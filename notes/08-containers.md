# Bài 08 — Vận hành Ứng dụng Container: Amazon ECR, ECS và AWS Fargate

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 5–7 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Docker Registry ECR, Kiến trúc ECS & Phân biệt IAM Roles). Buổi 2 gồm phần 4–7 (Mạng awsvpc, Rolling Update, Graceful Shutdown và [lab thực hành](../labs/08-containers/README.md)).
> **Kế hoạch gốc:** [plans/08-containers.md](../plans/08-containers.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Quản lý kho lưu trữ ảnh container an toàn với **Amazon Elastic Container Registry (ECR)**: cơ chế mã hóa, gắn tag bất biến (**Image Tag Immutability**), định danh bằng **SHA-256 Digest** và quy tắc dọn dẹp **Lifecycle Policy**.
2. Nắm vững kiến trúc **Amazon Elastic Container Service (ECS)**: phân cấp Cluster, Task Definition, Task, Service và ưu thế của nền tảng tính toán không máy chủ **AWS Fargate** so với EC2 Launch Type.
3. Phân biệt chính xác hai loại IAM Role sống còn trong ECS: **Task Execution Role** (dành cho ECS Container Agent) và **Task Role** (dành riêng cho code ứng dụng gọi AWS SDK).
4. Thiết lập mô hình mạng chuẩn doanh nghiệp với chế độ mạng **`awsvpc`**: gán ENI riêng biệt, chạy trong Private Subnet và tích hợp với **Application Load Balancer (ALB)** qua Target Type `ip`.
5. Vận hành quy trình cập nhật không gián đoạn (**Zero-Downtime Rolling Update**), xử lý tắt ứng dụng an toàn (**Graceful Shutdown với SIGTERM**) và cấu hình **Deregistration Delay / Connection Draining**.

---

## Phần 1 — Amazon ECR: Kho chứa Ảnh Container, Tag Bất biến và Vòng đời (45 phút)

### Amazon Elastic Container Registry (ECR)
Amazon ECR là dịch vụ kho lưu trữ ảnh Docker/OCI container riêng tư (Private Container Registry) có độ sẵn sàng và độ bảo mật cao, tích hợp chặt chẽ với IAM và các dịch vụ tính toán của AWS.

```text
Quy trình Push/Pull ảnh Container với Amazon ECR:
┌─────────────────┐                                        ┌─────────────────────────┐
│ Developer Máy / │ ──1. docker login (get-login-password)>│ Amazon ECR Private Repo │
│ CI/CD Pipeline  │ ──2. docker push <repo>:<tag>─────────>│  - Tag Immutability: BẬT│
└─────────────────┘                                        │  - Scan on Push: BẬT    │
                                                           │  - Lifecycle Policy     │
                                                           └───────────┬─────────────┘
                                                                       │
                                                       3. Pull Image   │ (Qua Task Execution
                                                          bằng Digest  │  Role & VPC Endpoint)
                                                                       ▼
                                                           ┌─────────────────────────┐
                                                           │ ECS Fargate Task        │
                                                           │ (Chạy trong Private Sub)│
                                                           └─────────────────────────┘
```

### Rủi ro của Tag Mutable và Giải pháp Tag Immutability
Trong môi trường phát triển, lập trình viên thường có thói quen đẩy đè ảnh lên tag `:latest` hoặc `:v1.0`.
- **Rủi ro sản xuất (Production Hazard):** Nếu tag `:latest` bị ghi đè bởi một bản build lỗi, các máy chủ tự động co giãn (Auto Scaling) khi bật lên sau đó sẽ kéo về image lỗi, trong khi các máy cũ vẫn chạy image tốt. Kết quả là hệ thống rơi vào trạng thái phân mảnh không đồng nhất, không thể tái hiện lỗi và cực kỳ khó rollback.
- **Giải pháp:** Bật tính năng **Tag Immutability (Bất biến)** trên ECR Repository. Khi đã bật, bất kỳ lệnh `docker push` nào cố gắng ghi đè lên một tag đã tồn tại sẽ bị ECR từ chối ngay lập tức.
- **Quy chuẩn CI/CD chuẩn:** Định danh image bằng **Git Commit SHA** ngắn kết hợp số hiệu bản build (ví dụ: `my-order-api:build-102-commit-a8f9b2c`). Khi triển khai trên ECS, trỏ chính xác theo **Image Digest** (ví dụ: `my-order-api@sha256:7b5d...`) để đảm bảo 100% tính xác thực của artifact.

### ECR Lifecycle Policy (Quy tắc dọn dẹp)
Mỗi image Docker thường có dung lượng từ vài trăm MB đến cả GB. Nếu không dọn dẹp, hàng trăm bản build trong quy trình CI/CD sẽ tích tụ hàng Terabytes dữ liệu và tiêu tốn chi phí lưu trữ không nhỏ.
- **Lifecycle Policy** cho phép bạn tự động hóa việc xóa các image cũ:
  - *Quy tắc 1:* Giữ lại tối đa 30 image có tag bắt đầu bằng `release-`.
  - *Quy tắc 2:* Tự động xóa các image không có tag (`untagged`) sau 3 ngày.
- Lifecycle policy **không biết** image nào đang được service dùng. Đặt số lượng giữ lại đủ để rollback, hoặc pin image đang chạy bằng tag riêng được loại khỏi rule.

---

## Phần 2 — Kiến trúc Amazon ECS: Cluster, Task Definition, Task, Service và AWS Fargate (50 phút)

Amazon ECS là hệ thống điều phối container (Container Orchestration) mạnh mẽ, đơn giản và tối ưu của AWS.

```text
Hệ thống phân cấp trong Amazon ECS:
┌────────────────────────────────────────────────────────────────────────┐
│ Amazon ECS Cluster: `production-cluster`                               │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Amazon ECS Service: `order-service`                              │  │
│  │ (Đảm bảo số lượng mong muốn `desiredCount = 2`, gắn sau ALB)     │  │
│  │                                                                  │  │
│  │  ┌─────────────────────────────┐   ┌───────────────────────────┐ │  │
│  │  │ Task Instance 1 (Fargate)   │   │ Task Instance 2 (Fargate) │ │  │
│  │  │ ┌─────────────────────────┐ │   │ ┌───────────────────────┐ │ │  │
│  │  │ │ Container: order-api    │ │   │ │ Container: order-api  │ │ │  │
│  │  │ └─────────────────────────┘ │   │ └───────────────────────┘ │ │  │
│  │  │ ENI: 10.0.11.45 (awsvpc)  │ │   │ ENI: 10.0.12.89 (awsvpc)│ │ │  │
│  │  └─────────────────────────────┘   └───────────────────────────┘ │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### Các thành phần cốt lõi:
1. **Cluster:** Ranh giới logic gom nhóm các dịch vụ và tác vụ.
2. **Task Definition:** Bản thiết kế chi tiết (Blueprint) định dạng JSON mô tả một hoặc nhiều container phối hợp hoạt động:
   - Image URL cần chạy từ ECR.
   - Hạn mức CPU và Bộ nhớ (ví dụ: `0.5 vCPU` và `1024 MB RAM`).
   - Biến môi trường, Secrets tiêm vào từ SSM Parameter Store hoặc Secrets Manager.
   - Cổng mạng (Port Mappings), IAM Roles và cấu hình đẩy log (`awslogs`).
3. **Task:** Một phiên bản thực thi cụ thể đang chạy (Running instance) được tạo ra từ Task Definition. Tương tự như một container đơn lẻ hoặc một Pod trong Kubernetes.
4. **Service:** Bộ điều khiển đảm bảo duy trì liên tục một số lượng Task mong muốn (`desiredCount`). Nếu một Task bị chết do crash ứng dụng hoặc lỗi phần cứng, Service sẽ lập tức tự động sinh ra một Task mới để thay thế. Service cũng quản lý việc đăng ký/hủy đăng ký IP của Task vào Target Group của Load Balancer.

### AWS Fargate vs EC2 Launch Type
Khi chạy container trên ECS, bạn có hai lựa chọn hạ tầng tính toán:

| Tiêu chí | EC2 Launch Type | AWS Fargate (Serverless Compute) |
|---|---|---|
| **Mô hình quản trị** | Bạn tự quản lý máy ảo EC2: vá lỗi HĐH, cập nhật ECS Agent, giám sát dung lượng đĩa EBS. | **Hoàn toàn không có máy chủ (Serverless).** AWS quản trị toàn bộ hạ tầng bên dưới. |
| **Đơn vị thanh toán** | Trả tiền theo giờ cho máy ảo EC2 đang chạy (dù container có dùng hết CPU hay không). | Trả theo vCPU và RAM **được cấp phát** cho Task (không phải mức dùng thực tế), tính theo giây với mức tối thiểu 1 phút. ARM64 (Graviton) rẻ hơn x86_64. |
| **Bảo mật cách ly** | Nhiều container từ các dịch vụ khác nhau có thể chạy chung trên cùng một máy ảo EC2. | **Cách ly cấp độ Kernel (VM-isolated):** Mỗi Task chạy trên một máy ảo vi mô (microVM) độc lập hoàn toàn. |
| **Bảo trì vận hành** | Tốn công sức quản lý cụm máy chủ và dung lượng Auto Scaling của EC2. | Không quản lý host, nhưng **vẫn phải** thiết kế mạng (subnet, SG, đường ra ECR/logs), IAM role và chọn cỡ CPU/RAM. |
| **Kiến trúc CPU** | Theo instance type | Mặc định `X86_64`; muốn chạy image arm64 phải khai báo `runtimePlatform.cpuArchitecture = ARM64`. Sai kiến trúc → container thoát với `exec format error`. |

---

## Phần 3 — Phân biệt Hai IAM Role Sống còn trong ECS: Execution Role vs Task Role (45 phút)

Một trong những nhầm lẫn phổ biến nhất dẫn đến lỗi triển khai container trên AWS là gộp chung hoặc đảo lộn hai IAM Role này:

```text
Sự khác biệt giữa Task Execution Role và Task Role:
┌────────────────────────────────────────────────────────────────────────┐
│ Amazon ECS Fargate Task Environment                                    │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ 1. ECS Container Agent (Môi trường nền tảng của AWS)             │  │
│  │                                                                  │  │
│  │    Dùng: TASK EXECUTION ROLE                                     │  │
│  │    - Quyền: ecr:GetAuthorizationToken, ecr:BatchGetImage         │  │
│  │    - Quyền: logs:CreateLogStream, logs:PutLogEvents              │  │
│  │    - Quyền: ssm:GetParameters, secretsmanager:GetSecretValue     │  │
│  └──────────────────────────────────────────────────────────────────┘  │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ 2. Ứng dụng của bạn (Mã nguồn Node.js/Python chạy trong container)│  │
│  │                                                                  │  │
│  │    Dùng: TASK ROLE                                               │  │
│  │    - Quyền: s3:GetObject / s3:PutObject                          │  │
│  │    - Quyền: dynamodb:Query / dynamodb:PutItem                    │  │
│  │    - Quyền: sqs:SendMessage                                      │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

1. **Task Execution Role (Quyền của Nền tảng):**
   - Được sử dụng bởi hệ thống ECS Agent **trước khi container của bạn thực sự khởi chạy**.
   - Cần các quyền:
     - Đăng nhập và kéo ảnh từ kho lưu trữ riêng tư ECR (`ecr:GetDownloadUrlForLayer`, `ecr:BatchGetImage`).
     - Tạo log stream và gửi log ứng dụng lên CloudWatch Logs (`logs:PutLogEvents`).
     - Giải mã và kéo các biến môi trường nhạy cảm từ AWS Secrets Manager hoặc Systems Manager Parameter Store để tiêm vào container.
   - Thường sử dụng AWS managed policy có sẵn: `service-role/AmazonECSTaskExecutionRolePolicy`.
2. **Task Role (Quyền của Ứng dụng Nghiệp vụ):**
   - Được cung cấp trực tiếp vào bên trong container cho **chính mã nguồn ứng dụng của bạn**.
   - Khi code gọi AWS SDK (ví dụ `new S3Client({})` hoặc `boto3.client('dynamodb')`), SDK sẽ tự động truy vấn thông qua đường dẫn nội bộ `AWS_CONTAINER_CREDENTIALS_RELATIVE_URI` để lấy temporary credentials từ Task Role này.
   - **Tuyệt đối không bao giờ cấp quyền đọc/ghi S3 hay DynamoDB vào Task Execution Role!**
3. **`iam:PassRole`:** người (hoặc pipeline) đăng ký task definition/tạo service phải có `iam:PassRole` trên cả hai role. Trust policy của hai role nên có điều kiện `aws:SourceAccount` để chống confused deputy.

---

## Phần 4 — Mô hình mạng `awsvpc`, Subnet Riêng tư và ALB Target Type `ip` (45 phút)

### Chế độ mạng `awsvpc` (Bắt buộc trên Fargate)
Khác với chế độ mạng `bridge` truyền thống của Docker (nhiều container dùng chung 1 IP của host EC2 và map các port ngẫu nhiên), chế độ mạng **`awsvpc`** biến mỗi Task thành một công dân mạng hạng nhất (First-class citizen) trong VPC:
- Mỗi Task được cấp phát một giao diện mạng linh hoạt (**Elastic Network Interface - ENI**) riêng biệt.
- Mỗi Task có **một địa chỉ IP nội bộ Private IP riêng** nằm trực tiếp trong Subnet của bạn.
- Bạn có thể gắn riêng các quy tắc **Security Group** cho từng Task một cách chi tiết và độc lập.

```text
Mô hình kết nối Mạng giữa ALB và ECS Fargate Tasks:
┌────────────────────────────────────────────────────────────────────────┐
│ Amazon VPC (10.0.0.0/16)                                               │
│                                                                        │
│  ┌──────────────────────────────────────────────────────────────────┐  │
│  │ Public Subnets                                                   │  │
│  │  ┌────────────────────────────────────────────────────────────┐  │  │
│  │  │ Application Load Balancer (`sg-alb`, Port 80)              │  │  │
│  │  └──────────────────────────────┬─────────────────────────────┘  │  │
│  └─────────────────────────────────┼────────────────────────────────┘  │
│                                    │ Target Type: `ip`                 │
│                                    │ Chuyển tiếp tới IP: 10.0.11.45/8080│
│  ┌─────────────────────────────────┼────────────────────────────────┐  │
│  │ Private Subnets                 ▼                                │  │
│  │  ┌────────────────────────────────────────────────────────────┐  │  │
│  │  │ ECS Fargate Tasks (`sg-ecs-task`)                          │  │  │
│  │  │ - Task A: IP 10.0.11.45:8080                               │  │  │
│  │  │ - Task B: IP 10.0.12.89:8080                               │  │  │
│  │  └────────────────────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### Tại sao Target Group bắt buộc chọn Target Type `ip`?
- Khi dùng chế độ mạng `awsvpc` trên Fargate, container không gắn liền với máy ảo EC2 cố định nào.
- Do đó, Application Load Balancer không thể chuyển tiếp traffic theo kiểu `instance` ID truyền thống.
- Target Group phải được cấu hình với **Target Type là `ip`**. ECS Service sẽ tự động phát hiện IP private của từng Task mới sinh ra và đăng ký trực tiếp IP đó vào Target Group.

---

## Phần 5 — Chiến lược Triển khai, Kiểm tra Sức khỏe và Graceful Shutdown (50 phút)

### Rolling Update: `minimumHealthyPercent` và `maximumPercent`
ECS quản lý chiến lược cập nhật ứng dụng thông qua hai tham số phần trăm cốt lõi:
- **`minimumHealthyPercent` (ví dụ 100%):** Quy định số lượng Task tối thiểu luôn phải ở trạng thái chạy ổn định (healthy) trong suốt quá trình triển khai phiên bản mới.
- **`maximumPercent` (ví dụ 200%):** Quy định giới hạn số lượng Task tối đa được phép sinh ra tạm thời trong lúc deploy.

*Kịch bản với `desiredCount = 2`, `min = 100%`, `max = 200%`:*
1. Hiện tại: 2 Task cũ V1 đang chạy (100%).
2. Bắt đầu deploy: ECS sinh thêm 2 Task mới V2 (Tổng là 4 Task = 200%).
3. Chờ Task V2 vượt qua bài kiểm tra sức khỏe (**Health Check**).
4. Khi Task V2 đã `healthy`, ALB bắt đầu chuyển lưu lượng sang V2.
5. ECS tiến hành hủy bỏ (Drain và Terminate) 2 Task cũ V1.
6. Hệ thống quay về trạng thái cân bằng với 2 Task V2.

Rolling update **không tự động** có nghĩa là không mất request. Chỉ đạt được khi: health check phản ánh đúng app đã sẵn sàng, deregistration delay đủ cho request dài nhất, và app xử lý `SIGTERM` đúng (phần dưới). Thiếu một trong ba, người dùng vẫn gặp `502`/`503` trong lúc deploy.

### Deployment circuit breaker và rollback
Nếu revision mới không bao giờ healthy, ECS mặc định cứ thử khởi động task mới mãi. Bật `deploymentCircuitBreaker={enable=true,rollback=true}` để ECS đếm số task thất bại; vượt ngưỡng (tối thiểu 3 task) thì đánh dấu deployment `FAILED` và tự quay về revision ổn định gần nhất. Trong thời gian đó, `minimumHealthyPercent=100` giữ các task cũ tiếp tục phục vụ.

### Cơ chế Tắt Ứng dụng An toàn (Graceful Shutdown với SIGTERM)
Khi một task đứng sau load balancer bị dừng (do scale-in hoặc deploy bản mới), ECS làm **tuần tự**: hủy đăng ký target khỏi target group và chờ hết **deregistration delay** (ALB ngừng gửi request mới, request đang chạy được hoàn thành), **sau đó** mới gửi **`SIGTERM`** tới tiến trình chính (PID 1) trong container:
1. Container của bạn cần bắt tín hiệu `SIGTERM` trong mã nguồn (ví dụ `process.on('SIGTERM', ...)` trong Node.js). Dockerfile phải dùng **exec form** (`CMD ["node", "server.js"]`); shell form (`CMD node server.js`) làm `/bin/sh` thành PID 1 và tín hiệu không tới được app.
2. Khi nhận `SIGTERM`, server ngừng nhận request mới, hoàn tất xử lý nốt các request HTTP đang dở dang và giải phóng các kết nối database.
3. **`stopTimeout` (mặc định 30 giây, tối đa 120 giây trên Fargate):** Khoảng thời gian ECS chờ container tự tắt sau `SIGTERM`. Nếu tiến trình chưa thoát, ECS gửi **`SIGKILL`** (exit code `137`).
4. **Deregistration Delay (Connection Draining trên ALB):** mặc định 300 giây, làm deploy chậm. Đặt bằng thời gian request dài nhất cộng biên an toàn (ví dụ 15–30 giây cho API ngắn).

---

## Phần 6 — Giới thiệu bài thực hành (Lab 08)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. Tạo một kho lưu trữ **Amazon ECR** với cờ Tag Immutability và Lifecycle Policy.
2. Đóng gói mã nguồn ứng dụng Order API, gắn thẻ và đẩy ảnh lên ECR an toàn.
3. Cấu hình cặp IAM Role: **Task Execution Role** và **Task Role**.
4. Khởi tạo **ECS Cluster** và khai báo **Task Definition** cho môi trường Fargate kèm logging CloudWatch.
5. Triển khai **ECS Service** kết nối sau Application Load Balancer với Target Type `ip`.
6. Rolling deploy v1 → v2 trong khi chạy vòng `curl` liên tục để đo số request lỗi; xem log `SIGTERM`.
7. Thực nghiệm tình huống lỗi (**Negative Case**): bản v3 lắng nghe sai cổng, quan sát circuit breaker tự rollback và đọc `stoppedReason`.
8. Scale-in để thấy thứ tự draining → `SIGTERM` → thoát.
9. Dọn dẹp toàn bộ tài nguyên ECS, ALB, NAT, ECR và kiểm tra inventory.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/08-containers/README.md](../labs/08-containers/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Task lập tức bị dừng với lỗi `CannotPullContainerError`
- **Nguyên nhân 1:** ECS Task chạy trong Private Subnet nhưng Subnet đó không có đường ra Internet (qua NAT Gateway) hoặc thiếu các **VPC Endpoints** cho ECR (`ecr.api`, `ecr.dkr` và S3 Gateway Endpoint).
- **Nguyên nhân 2:** Task Execution Role thiếu quyền `AmazonECSTaskExecutionRolePolicy` để đăng nhập và kéo ảnh từ ECR.
- **Nguyên nhân 3:** Tên URI của image hoặc Tag bị sai chính tả trong Task Definition.

### 2. Task liên tục khởi động rồi tự tắt (CrashLoop / Essential container exited)
- **Cách chẩn đoán:** Xem mã thoát (`ExitCode`) và `StoppedReason` bằng lệnh:
  ```bash
  aws ecs describe-tasks --cluster <cluster> --tasks <task-id> \
    --query 'tasks[0].containers[0].[exitCode,reason]'
  ```
  - **ExitCode 137:** tiến trình nhận `SIGKILL`. Hai nguyên nhân thường gặp: hết bộ nhớ (`stoppedReason` chứa `OutOfMemoryError`; tăng `memory` hoặc giảm dùng bộ nhớ), hoặc không thoát kịp trong `stopTimeout` sau `SIGTERM` (sửa xử lý shutdown).
  - **ExitCode 1:** Mã nguồn ứng dụng văng ngoại lệ không được xử lý (Unhandled Exception) ngay khi khởi động. Hãy kiểm tra CloudWatch Logs của container.

### 3. Target trên ALB luôn ở trạng thái `unhealthy`
- **Nguyên nhân 1:** Port mapping trong Task Definition không khớp với port cấu hình trong Target Group (ví dụ container listen cổng 8080 nhưng Target Group kiểm tra cổng 80).
- **Nguyên nhân 2:** Đường dẫn Health Check Path (ví dụ `/health`) trả về mã khác success code của target group. Mặc định ALB chỉ chấp nhận `200`; redirect `301/302` cũng bị coi là unhealthy.
- **Nguyên nhân 3:** Security Group của ECS Task chưa mở Inbound cho Security Group của ALB.

---

## Câu hỏi tự kiểm tra

1. Tại sao quy chuẩn doanh nghiệp nghiêm cấm việc ghi đè lên tag `:latest` trong Amazon ECR và yêu cầu kích hoạt cờ `Tag Immutability`?
2. Trình bày sự khác biệt cốt lõi về mặt chức năng và phạm vi cấp quyền giữa **Task Execution Role** và **Task Role** trong Amazon ECS?
3. Khi triển khai container trên AWS Fargate với chế độ mạng `awsvpc`, tại sao Target Group trên Application Load Balancer bắt buộc phải cấu hình `TargetType = "ip"` thay vì `TargetType = "instance"`?
4. Trình bày quy trình diễn ra khi ECS dừng một container trong quá trình Rolling Update. Ý nghĩa của tín hiệu `SIGTERM` và tham số `Deregistration Delay` là gì?
5. Nếu một ECS Fargate Task đặt trong Private Subnet không có Public IP, nó cần những điều kiện hạ tầng mạng nào để có thể kéo được ảnh từ ECR và gửi log lên CloudWatch?

---

## Tài liệu tham khảo chính thức

- [Amazon ECS Developer Guide](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/)
- [AWS Fargate for Amazon ECS](https://docs.aws.amazon.com/AmazonECS/latest/userguide/what-is-fargate.html)
- [Amazon ECR User Guide](https://docs.aws.amazon.com/AmazonECR/latest/userguide/)
- [ECS Task execution IAM role vs Task IAM role](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/task-iam-roles.html)
- [Speeding up ECS deployment with ALB deregistration delay](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/load-balancer-target-groups.html#deregistration-delay)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** Fargate bắt buộc `awsvpc` và target type `ip`; `runtimePlatform` ARM64; trình tự draining → `SIGTERM` → `SIGKILL` và giới hạn `stopTimeout`; deployment circuit breaker; ECR tag immutability; `aws ecr get-login-password` (lệnh `get-login` cũ đã bị bỏ ở CLI v2); ALB success code mặc định `200`.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output và số đo là minh họa.
- **Chi phí:** task Fargate cỡ nhỏ nhất (0,25 vCPU, 0,5 GB) chỉ khoảng $0.01/task-giờ, nhưng NAT Gateway và ALB khiến cả lab khoảng $0.13–0.15/giờ (xem bảng trong lab). Phải xóa ngay sau buổi học.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Bật `Tag Immutability` để ngăn ngừa việc ghi đè lên các tag image đã có. Nếu dùng `:latest`, các lần scale-out máy ảo sau này có thể kéo về bản build mới hơn bản đang chạy, gây ra hiện tượng phân mảnh phiên bản (Version drift) giữa các container trong cùng một cụm. Định danh bất biến giúp đảm bảo tính toàn vẹn của mã nguồn, khả năng rollback tức thì và truy vết chính xác phiên bản mã nguồn tương ứng trong Git.
2. Sự khác biệt:
   - **Task Execution Role:** Trao quyền cho chính hạ tầng **ECS Container Agent** của AWS để thực hiện các tác vụ chuẩn bị trước khi container khởi chạy: kéo image từ ECR, đẩy log vào CloudWatch và lấy secret/parameter giải mã tiêm vào biến môi trường.
   - **Task Role:** Trao quyền trực tiếp cho **mã nguồn ứng dụng** chạy bên trong container để gọi các dịch vụ AWS khác (như ghi file lên S3, đọc ghi bảng DynamoDB) thông qua AWS SDK mà không cần nhúng Access Key vào container.
3. Vì ở chế độ mạng `awsvpc`, mỗi Task được cấp phát một giao diện mạng ảo (ENI) và địa chỉ IP riêng biệt trong subnet, hoàn toàn không phụ thuộc vào máy chủ vật lý hay instance EC2 nào bên dưới. Do đó, ALB chỉ có thể định tuyến lưu lượng trực tiếp tới địa chỉ IP cụ thể của từng task thông qua cấu hình `TargetType = "ip"`.
4. Quy trình:
   - ECS hủy đăng ký target; ALB đưa task vào trạng thái `draining`, ngừng gửi request mới và chờ tối đa `Deregistration Delay` để request đang xử lý hoàn tất.
   - **Sau khi** draining xong, ECS gửi `SIGTERM` tới container. Ứng dụng đóng listener, đóng kết nối idle/keep-alive và kết nối database, rồi tự thoát.
   - Nếu hết hạn `stopTimeout` mà container chưa dừng, ECS sẽ gửi `SIGKILL` để tiêu diệt tiến trình.
5. Cần một trong hai phương án mạng sau:
   - **Phương án 1 (NAT Gateway):** Route Table của Private Subnet có một default route (`0.0.0.0/0`) trỏ tới một NAT Gateway đặt ở Public Subnet để container đi ra ngoài Internet kéo ảnh và gửi log.
   - **Phương án 2 (VPC Endpoints - Hoàn toàn cô lập không cần Internet):** Cần tối thiểu 4 VPC Endpoints trong VPC:
     - Interface Endpoint cho `ecr.api` (gọi ECR Control Plane).
     - Interface Endpoint cho `ecr.dkr` (kéo các layer image).
     - Gateway Endpoint cho `s3` (vì các layer Docker thực chất được ECR lưu trữ trên S3).
     - Interface Endpoint cho `logs` (để gửi log lên CloudWatch Logs).

</details>

