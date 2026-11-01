# Bài 05 — Máy chủ và cân bằng tải: EC2, EBS, ALB, Auto Scaling và Systems Manager

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 5–7 giờ, chia hai buổi. Buổi 1 gồm phần 1–4 (lý thuyết & kiến trúc điện toán phân tán). Buổi 2 gồm phần 5–7 và [lab thực hành](../labs/05-compute-and-load-balancing/README.md).
> **Kế hoạch gốc:** [plans/05-compute-and-load-balancing.md](../plans/05-compute-and-load-balancing.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Phân loại các họ máy chủ **Amazon EC2**, kiến trúc vi xử lý (x86 vs AWS Graviton arm64), quy trình khởi động qua **User Data** và vai trò của **Instance Metadata Service v2 (IMDSv2)**.
2. Hiểu vòng đời của **Amazon Elastic Block Store (EBS)**, cờ `DeleteOnTermination`, cơ chế sao lưu **EBS Snapshot** và ràng buộc gắn ổ đĩa theo Availability Zone (Zonal constraint).
3. Nắm vững cơ chế hoạt động của **Application Load Balancer (ALB)**: Listener, Routing Rules, Target Group và thuật toán kiểm tra sức khỏe (**Health Check**).
4. Thiết kế cụm máy chủ tự động co giãn bằng **Launch Template** và **EC2 Auto Scaling Group (ASG)** qua nhiều AZ với các tham số `min`, `max`, `desired` và chính sách **Target Tracking Scaling Policy**.
5. Thay thế hoàn toàn giao thức SSH truyền thống (Port 22) bằng **AWS Systems Manager (SSM) Session Manager**, giúp quản trị máy chủ riêng tư (Private Subnet) an toàn mà không cần mở cổng mạng hoặc dùng Bastion Host.
6. Thiết kế ứng dụng phi trạng thái (**Stateless Architecture**) và cơ chế xử lý dừng dịch vụ an toàn (**Graceful Shutdown**).

---

## Phần 1 — Amazon EC2: Instance, AMI, User Data và Instance Profile (45 phút)

### Amazon Elastic Compute Cloud (EC2)
EC2 cung cấp năng lực tính toán máy ảo có thể thay đổi quy mô linh hoạt trong đám mây.

```text
Cấu tạo một Instance EC2:
┌────────────────────────────────────────────────────────┐
│ Amazon EC2 Instance                                    │
│                                                        │
│  ┌───────────────────┐        ┌─────────────────────┐  │
│  │   AMI (HĐH Linux) │        │ User Data Bootstrap │  │
│  └───────────────────┘        └─────────────────────┘  │
│  ┌───────────────────┐        ┌─────────────────────┐  │
│  │ vCPU / RAM (Họ T) │        │ IAM Instance Profile│  │
│  └───────────────────┘        └─────────────────────┘  │
│            ▲                                           │
└────────────┼───────────────────────────────────────────┘
             ▼ (Gắn qua mạng; trên instance Nitro hiện ra như ổ NVMe)
┌────────────────────────┐
│ EBS Volume (Root Disk) │
└────────────────────────┘
```

### Các họ máy chủ EC2 (Instance Families)
Quy ước đặt tên của EC2 theo dạng `<Family><Generation><Capabilities>.<Size>`. Ví dụ: `t4g.small`:
- `t`: Họ máy chủ burstable (phù hợp tải biến thiên, lab, dev/test).
- `4`: Thế hệ thứ 4.
- `g`: Trang bị chip AWS Graviton (kiến trúc ARM64, hiệu năng/giá tốt hơn x86 tới 20–40%).
- `small`: Kích cỡ phần cứng (vCPU và RAM).

Các họ chính:
- **T (General Purpose / Burstable):** `t4g`, `t3` — Cân bằng giữa CPU, bộ nhớ và chi phí thấp.
- **M (General Purpose):** `m6i`, `m7g` — Tỷ lệ vCPU : RAM là 1 : 4, dùng cho web server, app server tiêu chuẩn.
- **C (Compute Optimized):** `c6i`, `c7g` — Tỷ lệ 1 : 2, tối ưu cho xử lý tính toán cao, batch processing, video encoding.
- **R (Memory Optimized):** `r6i`, `r7g` — Tỷ lệ 1 : 8, tối ưu cho in-memory cache (Redis), cơ sở dữ liệu (PostgreSQL/MySQL).

### Amazon Machine Image (AMI)
AMI là khuôn mẫu đóng gói sẵn hệ điều hành, cấu hình kernel và phần mềm cài đặt sẵn. Khuyến nghị chuẩn hiện nay của AWS là **Amazon Linux 2023 (AL2023)** — tối ưu hóa thời gian khởi động, đi kèm kernel Linux mới và trình quản lý gói `dnf`.

### User Data Script
User Data là đoạn script (thường là Bash shell script) được chuyển vào máy ảo khi khởi tạo:
- **Cơ chế chạy:** Script này được tiện ích `cloud-init` thực thi với quyền `root` **duy nhất một lần trong lần khởi động đầu tiên (first boot)** của instance.
- **Lưu ý:** Khi bạn Reboot hay Stop/Start máy ảo, User Data **mặc định không chạy lại** (có thể cấu hình cloud-init để chạy mỗi lần boot, nhưng đó không phải mặc định; hành vi cụ thể phụ thuộc AMI). Vì vậy, process khởi chạy bằng `&` trong user data sẽ **mất sau reboot**; muốn ứng dụng tự chạy lại, hãy đăng ký nó thành **systemd service**.
- **Bảo mật:** Không đưa secrets vào User Data, vì bất kỳ ai có quyền `ec2:DescribeInstanceAttribute` và mọi process trên instance (qua IMDS) đều đọc được nội dung này dưới dạng plain text.
- **Debug:** output của script nằm ở `/var/log/cloud-init-output.log`.

### IMDSv2 (Instance Metadata Service Version 2)
Mỗi EC2 instance có thể truy vấn thông tin về chính nó (IP, AZ, IAM role credentials) qua địa chỉ IP link-local đặc biệt `http://169.254.169.254/latest/meta-data/`.
- **IMDSv1:** Yêu cầu một request GET đơn giản, dễ bị lộ credentials nếu ứng dụng dính lỗ hổng SSRF (Server-Side Request Forgery).
- **IMDSv2 (Chuẩn bảo mật bắt buộc):** Yêu cầu phương thức dựa trên session (Session-oriented). Client phải gửi request `PUT` với header `X-aws-ec2-metadata-token-ttl-seconds` để lấy token bí mật, sau đó mới dùng token đó trong header `X-aws-ec2-metadata-token` để đọc metadata.

### IAM Instance Profile
Để cấp quyền cho ứng dụng chạy trên EC2 gọi AWS API (ví dụ ghi file lên S3 hoặc kết nối Systems Manager), **tuyệt đối không bao giờ copy Access Key vào máy ảo**.
Thay vào đó, bạn tạo một **IAM Role**, bọc nó vào một **Instance Profile** và gắn vào EC2:
- AWS tự động xoay vòng (rotate) temporary credentials định kỳ và cung cấp cho máy ảo qua IMDSv2.
- Ứng dụng dùng AWS SDK sẽ tự động phát hiện và sử dụng credentials này mà bạn không cần cấu hình thêm dòng code nào.

---

## Phần 2 — Amazon EBS: Vòng đời, Snapshot và Giới hạn vùng (40 phút)

**Amazon Elastic Block Store (EBS)** là dịch vụ lưu trữ khối cấp độ mạng (network-attached block storage) hiệu năng cao, đóng vai trò như chiếc ổ cứng gắn ngoài kết nối vào máy ảo EC2.

```text
Availability Zone A
┌──────────────────────────────────────────────┐
│  EC2 Instance A                              │
│       ▲                                      │
│       │ Gắn qua mạng nội bộ AZ (NVMe)        │
│       ▼                                      │
│  EBS Volume (gp3)                            │
└───────┬──────────────────────────────────────┘
        │
        │ ec2:CreateSnapshot (Bản sao lưu tăng dần)
        ▼
   ┌────────────────────────────────────────┐
   │ Amazon S3 Storage (Bảo vệ đa vùng AZ) │
   └────────────────────────────────────────┘
```

### Các loại Volume EBS phổ biến:
1. **General Purpose SSD (gp3):** Lựa chọn mặc định tốt nhất cho hầu hết khối lượng công việc. Cho phép tùy chỉnh độc lập giữa dung lượng (GB), IOPS cơ sở (mặc định 3,000 IOPS miễn phí) và Throughput (mặc định 125 MB/s) mà không cần tăng dung lượng đĩa.
2. **Provisioned IOPS SSD (io2 / io2 Block Express):** Dành cho các hệ thống cơ sở dữ liệu quan trọng đòi hỏi độ trễ dưới mili-giây và độ bền bỉ 99.999%.
3. **Throughput Optimized HDD (st1):** Ổ đĩa từ tính giá rẻ cho Big Data, log processing và data warehousing.

### Hai nguyên tắc vàng về kiến trúc EBS:

1. **Ràng buộc Zonal (Zonal Constraint):**
   Một EBS Volume được tạo trong một Availability Zone cụ thể (ví dụ `ap-southeast-1a`) thì **chỉ có thể gắn (attach) vào instance EC2 nằm trong cùng AZ đó**. Bạn không thể gắn trực tiếp một EBS volume ở AZ-1a vào một máy ảo ở AZ-1b. Muốn chuyển dữ liệu sang AZ khác, bạn phải tạo Snapshot rồi khôi phục thành Volume mới ở AZ đích.

2. **Cờ `DeleteOnTermination`:**
   Mỗi volume khi gắn vào EC2 có một thuộc tính boolean `DeleteOnTermination`.
   - Với **Root Volume** (chứa HĐH): Mặc định là `true` (khi terminate instance, ổ đĩa bị xóa tự động).
   - Với **Data Volume** (ổ gắn thêm): Mặc định là `false` (khi terminate instance, ổ đĩa vẫn được giữ lại ở trạng thái `available` và tiếp tục tính tiền). Cần lưu ý điều này để tránh tài nguyên ngầm.

### EBS Snapshots
EBS Snapshot là bản chụp ảnh sao lưu tại một thời điểm (Point-in-time backup) của volume, được lưu trữ an toàn trên Amazon S3:
- **Cơ chế sao lưu gia tăng (Incremental Backup):** Lần snapshot đầu tiên copy 100% block dữ liệu đã dùng. Các lần snapshot tiếp theo chỉ lưu lại các block dữ liệu có thay đổi kể từ lần chụp trước, giúp tiết kiệm tối đa chi phí lưu trữ.

---

## Phần 3 — Application Load Balancer (ALB) và Health Check (45 phút)

Khi lưu lượng truy cập tăng cao hoặc cần đảm bảo tính sẵn sàng cao (High Availability), chúng ta không bao giờ để client kết nối trực tiếp vào một máy chủ đơn lẻ. Thay vào đó, ta đặt một **Application Load Balancer (ALB)** làm cửa ngõ phân phối.

```text
Client Request (HTTP/HTTPS)
            │
            ▼
┌───────────────────────────────────────────────┐
│ Application Load Balancer (Tầng 7)            │
│  - Trải trên 2 Public Subnets (AZ-A & AZ-B)   │
│  - DNS: my-alb-123.<region>.elb.amazonaws.com │
├───────────────────────────────────────────────┤
│ Listener: Cổng 80 HTTP                        │
│  - Routing Rule: Nếu path="/api/*" ──► TG-App │
└───────────────────────┬───────────────────────┘
                        │ Phân phối tải (Round Robin / Least Requests)
                        ▼
┌───────────────────────────────────────────────┐
│ Target Group: tg-order-service                │
│  - Health Check: GET /health (Mỗi 15s)        │
├───────────────────────┬───────────────────────┤
│   AZ-A (Private)      │   AZ-B (Private)      │
│ ┌───────────────────┐ │ ┌───────────────────┐ │
│ │ EC2 Instance #1   │ │ │ EC2 Instance #2   │ │
│ │ Trạng thái: Healthy│ │ │ Trạng thái: Healthy│ │
│ └───────────────────┘ │ └───────────────────┘ │
└───────────────────────┴───────────────────────┘
```

### Các thành phần của ALB:
1. **Load Balancer:** Đặt node trên các Public Subnet ở nhiều AZ (mỗi node có public IPv4, tính phí) và cung cấp một `DNS Name` cố định. **IP của ALB có thể thay đổi**, nên client luôn dùng DNS name, không hard-code IP.
2. **Listener:** Lắng nghe kết nối trên một cổng cụ thể (ví dụ HTTP cổng 80 hoặc HTTPS cổng 443).
3. **Routing Rules:** Quy tắc định tuyến dựa trên nội dung tầng 7 (HTTP header, path, query parameter hoặc HTTP method). Ví dụ: `GET /orders/*` chuyển tiếp về Target Group Order, còn `GET /users/*` chuyển về Target Group User.
4. **Target Group:** Tập hợp các đối tượng đích nhận traffic (EC2 instances, container ECS, hoặc Lambda).

### Cơ chế Health Check (Kiểm tra sức khỏe)
ALB định kỳ gửi request HTTP (ví dụ `GET /health`) tới từng target trong Target Group:
- **Healthy Threshold (Ngưỡng sống):** Số lần phản hồi HTTP 200 liên tiếp để đánh dấu instance là khỏe mạnh (ví dụ: 2 lần).
- **Unhealthy Threshold (Ngưỡng chết):** Số lần health check thất bại liên tiếp (timeout, từ chối kết nối, hoặc mã HTTP ngoài `Matcher`, mặc định `200`) để đánh dấu target hỏng (ví dụ: 2 lần).
- **Thời gian phát hiện lỗi** ≈ `interval × unhealthy_threshold`. Ví dụ interval 10 giây, threshold 2 thì mất khoảng 20 giây.
- Khi một target `unhealthy`, ALB **ngừng gửi request mới** tới target đó và chia cho các target healthy còn lại.
- **Fail open:** nếu **tất cả** target đều unhealthy, ALB không trả lỗi ngay mà gửi request tới **mọi** target (vì có thể chính health check bị cấu hình sai). Request sau đó thất bại theo cách của target: không kết nối được thì `502`/`504`.

### Bảng mã lỗi thường gặp của ALB

| Mã | Ý nghĩa thường gặp |
|---|---|
| `502 Bad Gateway` | ALB kết nối được target nhưng nhận response không hợp lệ, hoặc target đóng/từ chối kết nối (app chết, app chỉ bind `127.0.0.1`) |
| `503 Service Unavailable` | Target group **không có target nào được đăng ký** (ví dụ ASG desired = 0), hoặc listener rule không có target |
| `504 Gateway Timeout` | Target không phản hồi kịp: SG chặn port, app treo, quá idle timeout |

### Graceful shutdown và deregistration delay

Khi một target bị gỡ khỏi target group (scale-in, ASG thay instance, deploy), ALB chuyển nó sang trạng thái `draining`:
- **Deregistration delay** (mặc định 300 giây, chỉnh qua `deregistration_delay.timeout_seconds`): ALB ngừng gửi request mới nhưng **cho request đang xử lý chạy xong** trong khoảng thời gian này.
- Ứng dụng cũng phải hợp tác: khi nhận **SIGTERM** (systemd/ECS gửi khi dừng), app ngừng nhận request mới, xử lý xong request đang chạy, đóng kết nối DB rồi mới thoát.
- Với ASG, **lifecycle hook** (`autoscaling:EC2_INSTANCE_TERMINATING`) giữ instance ở trạng thái chờ để chạy việc dọn dẹp (flush log, hoàn tất job) trước khi bị terminate.

---

## Phần 4 — Launch Template và Auto Scaling Group (ASG) (45 phút)

Tự phục hồi (Self-healing) và Tự co giãn (Auto-scaling) là hai phẩm chất tối quan trọng của kiến trúc đám mây.

### Launch Template (Bản thiết kế máy chủ)
Launch Template chứa đầy đủ thông số kỹ thuật cần thiết để khởi chạy một EC2 instance mới:
- ID của AMI (ví dụ Amazon Linux 2023).
- Loại instance (ví dụ `t4g.nano` hoặc `t4g.micro`).
- Security Groups.
- IAM Instance Profile.
- User Data bootstrap script.
- Cấu hình ổ đĩa EBS.
- Hỗ trợ đánh số phiên bản (Version 1, Version 2...) giúp dễ dàng rollback khi deploy phiên bản phần mềm mới.

### Auto Scaling Group (ASG)
ASG quản lý một tập hợp các máy ảo EC2 được tạo ra từ Launch Template, đảm bảo số lượng máy chủ luôn duy trì theo đúng nhu cầu:

```text
Cấu hình Capacity của ASG:
[ Min: 1 ] ◄─────── [ Desired: 2 ] ───────► [ Max: 4 ]
 Số tối thiểu        Số lượng mong muốn       Số tối đa được phép mở
```

- **Phân bổ đa vùng (Multi-AZ):** Khi bạn cấu hình ASG nằm trên 2 Private Subnets ở 2 AZ khác nhau, ASG sẽ tự động cân bằng số lượng máy ảo đều giữa các AZ để tối đa hóa khả năng chịu lỗi.
- **Tích hợp Health Check giữa ALB và ASG:**
  - Mặc định, ASG chỉ kiểm tra sức khỏe ở mức phần cứng (**EC2 status check**: máy còn chạy không).
  - Khi bật **`HealthCheckType: "ELB"`**, nếu ALB đánh dấu một instance `unhealthy` (app treo dù HĐH vẫn chạy), ASG sẽ terminate instance đó và launch instance mới thay thế.
  - **Health check grace period** cho instance mới thời gian khởi động (boot + user data + app start) trước khi ASG tính kết quả health check của ELB. Đặt quá ngắn thì ASG giết instance trước khi app kịp lên và lặp lại mãi.
  - ASG chỉ thay **máy**. Dữ liệu và session lưu trên đĩa local của máy bị thay sẽ **mất**; ASG không sửa được điều đó. Đây là lý do ứng dụng cần stateless (Phần 5).

### Chính sách co giãn (Scaling Policies)
- **Target Tracking Scaling Policy (Khuyến nghị):** Bạn chỉ cần đặt mục tiêu (Target Value), AWS sẽ tự động tăng/giảm số máy để duy trì chỉ số đó. Ví dụ: *"Luôn giữ mức sử dụng CPU trung bình của cụm ở mức 60%"*.
- **Instance warmup** (dùng với target tracking/step scaling): khoảng thời gian instance mới **chưa được tính** vào metric trung bình của nhóm, để CPU cao lúc khởi động không kích hoạt scale-out thêm.
- **Cooldown** (chủ yếu cho simple scaling): sau một lần scale, ASG chờ hết thời gian này mới xét lần scale tiếp theo.
- **Không scale như mong đợi?** Kiểm tra: metric có thật sự vượt target không (CloudWatch), instance còn trong warmup không, đã chạm `max` chưa, và quota vCPU của account.

---

## Phần 5 — Quản trị máy chủ an toàn với AWS Systems Manager (SSM) (30 phút)

Trong kiến trúc truyền thống, để SSH vào máy ảo, lập trình viên thường:
- Mở cổng `22` trên Security Group.
- Gán Public IP cho máy ảo hoặc dựng một máy nhảy (Bastion Host / Jump Box).
- Quản lý và chia sẻ file SSH Key Pair (`.pem`) — tiềm ẩn nguy cơ lộ key nghiêm trọng.

### Giải pháp hiện đại: AWS Systems Manager Session Manager

```text
Lập trình viên (Terminal / AWS CLI)
       │
       │ Không cần Port 22! Không cần Public IP! Không cần SSH Key!
       ▼
[ AWS Systems Manager API Endpoint ]
       ▲
       │ Kết nối bảo mật Outbound qua cổng 443 HTTPS (Websocket)
       │ (Sử dụng IAM Role: AmazonSSMManagedInstanceCore)
       │
[ EC2 Instance trong Private Subnet ]
  - Chạy sẵn SSM Agent (tích hợp sẵn trong Amazon Linux 2023)
```

**Ưu điểm vượt trội của SSM Session Manager:**
1. **Zero Open Ports:** Không cần mở bất kỳ cổng Inbound nào trên Security Group (kể cả cổng 22). Security Group Inbound có thể để trống hoàn toàn!
2. **Private-Only:** Máy ảo nằm trong Private Subnet, không cần public IP, nhưng **bắt buộc** có đường outbound HTTPS tới SSM (qua NAT Gateway hoặc VPC Interface Endpoints, xem Bài 04). Private subnet chỉ có local route thì SSM **không** hoạt động.
3. **Quản trị bằng IAM:** Phân quyền ai được mở session vào máy nào bằng IAM Policy (`ssm:StartSession` giới hạn theo tag/instance) thay vì phát SSH key.
4. **Audit Logs:** Có thể bật ghi log output của session lên Amazon S3 hoặc CloudWatch Logs, và CloudTrail ghi lại ai đã mở session nào, khi nào.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 05)

Trong bài thực hành đi kèm, bạn ghép các mảnh kiến trúc từ Lab 02–04 để triển khai một dịch vụ đơn hàng nhỏ:
- **Kiến trúc:**
  - Tự tạo mạng riêng cho lab (cùng mô hình Lab 04) và thêm **một NAT Gateway** để instance trong private subnet kết nối được SSM.
  - Tạo IAM Role + Instance Profile cho EC2 với `AmazonSSMManagedInstanceCore`.
  - Tạo Launch Template (Amazon Linux 2023, `t4g.nano`, IMDSv2) với User Data đăng ký Order API viết bằng Python thành **systemd service**.
  - Triển khai **Application Load Balancer** công khai trên 2 Public Subnet.
  - Triển khai **Auto Scaling Group** với 2 instance **không có public IP** trong 2 Private Subnet.
- **Thực nghiệm kiểm chứng:**
  - Negative test: gọi ALB khi chưa có target → `503`.
  - Phân phối request qua DNS của ALB (`GET /order` trả về instance ID và AZ).
  - Vào instance trong private subnet bằng Session Manager, không mở port 22.
  - Giả lập sự cố: dừng service trên 1 instance, **đo** thời gian ALB đánh dấu `unhealthy` và thời gian ASG thay instance mới.
  - Tùy chọn: target tracking policy và tạo tải CPU có giới hạn để quan sát scale-out.
  - Dọn dẹp theo dependency và kiểm tra EBS, public IPv4/EIP, NAT còn sót.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/05-compute-and-load-balancing/README.md](../labs/05-compute-and-load-balancing/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. ALB trả về 502, 503 hoặc 504
- **Bằng chứng đầu tiên:** `aws elbv2 describe-target-health` cho biết số target đã đăng ký, trạng thái và `Reason` (ví dụ `Target.Timeout`, `Target.FailedHealthChecks`, `Target.ResponseCodeMismatch`).
- **502 Bad Gateway:** target từ chối hoặc đóng kết nối, hoặc trả response hỏng. Thường do app chưa chạy, app crash, hoặc app chỉ bind `127.0.0.1` thay vì `0.0.0.0`. Kiểm tra từ chính instance: `curl -v http://localhost:8080/health` và `ss -ltnp`.
- **503 Service Unavailable:** target group **không có target nào được đăng ký**. Kiểm tra ASG có đang `desired = 0` không, ASG có gắn đúng target group không, instance có launch thất bại không (`describe-scaling-activities`).
- **504 Gateway Timeout:** ALB không nhận được phản hồi. Thường do **Security Group của instance không cho phép port app từ SG của ALB**, hoặc app treo.
- **Sửa có phạm vi nhỏ:** mở đúng port app từ SG của ALB, không mở `0.0.0.0/0` vào instance.

### 2. User Data script không chạy hoặc cài đặt thất bại
- **Cách chẩn đoán:** Remote vào máy qua SSM Session Manager và kiểm tra file log của hệ điều hành:
  ```bash
  cat /var/log/cloud-init-output.log
  ```
  File này lưu toàn bộ stdout và stderr của script User Data trong lần boot đầu tiên. Nếu dùng systemd service, xem thêm `systemctl status <service>` và `journalctl -u <service>`.
- **Nếu không vào được instance** (SSM offline và instance bị ASG thay liên tục): xem console output qua `aws ec2 get-console-output --instance-id <id> --latest`, và tạm tăng health check grace period để instance sống đủ lâu cho bạn debug.

### 3. Instance không hiện trong SSM Session Manager (SSM Offline)
- Cần thỏa mãn đủ 3 điều kiện:
  1. Instance đã được gắn IAM Role có AWS managed policy `AmazonSSMManagedInstanceCore`.
  2. SSM Agent đang chạy (đã bật sẵn trên AL2023).
  3. Instance trong Private Subnet phải có đường outbound HTTPS tới SSM endpoints (`ssm.<region>.amazonaws.com`, `ssmmessages.<region>.amazonaws.com`; agent phiên bản cũ cần thêm `ec2messages.<region>.amazonaws.com`) qua NAT Gateway hoặc VPC Interface Endpoints.
  - Instance mới thường mất 1–3 phút sau khi boot mới hiện "Online" trong Fleet Manager.

---

## Câu hỏi tự kiểm tra

1. Khi một instance EC2 bị terminate, dữ liệu trên ổ đĩa Root EBS và ổ đĩa Data EBS gắn kèm sẽ ra sao? Điều gì quyết định hành vi này?
2. Tại sao chúng ta nên ưu tiên sử dụng `Target Tracking Scaling Policy` thay vì tự viết các quy tắc Step Scaling thủ công phức tạp?
3. Nếu Target Group cấu hình Health check là `GET /health`, cổng 8080, nhưng Security Group của EC2 chỉ mở Inbound cho cổng 80, điều gì sẽ xảy ra với trạng thái của target trên ALB?
4. Trình bày ít nhất 3 lý do vì sao doanh nghiệp nên chuyển từ quản trị SSH (Port 22) sang AWS Systems Manager Session Manager?
5. Thế nào là một ứng dụng "phi trạng thái" (Stateless Application) và vì sao tính chất này là điều kiện tiên quyết để Auto Scaling hoạt động trơn tru?
6. ALB giải quyết vấn đề gì, ASG giải quyết vấn đề gì? Nếu chỉ có ALB mà không có ASG (2 instance cố định), điều gì xảy ra khi một instance chết?
7. Vì sao ASG đặt health check grace period 30 giây cho một app cần 90 giây để khởi động có thể khiến nhóm không bao giờ ổn định?

---

## Tài liệu tham khảo chính thức

- [Amazon EC2 User Guide](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/concepts.html)
- [Amazon EBS volumes](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/ebs-volume-types.html)
- [What is an Application Load Balancer?](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/introduction.html)
- [What is Amazon EC2 Auto Scaling?](https://docs.aws.amazon.com/autoscaling/ec2/userguide/what-is-amazon-ec2-auto-scaling.html)
- [AWS Systems Manager Session Manager](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager.html)
- [Instance metadata and user data](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/ec2-instance-metadata.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** IMDSv2, `AmazonSSMManagedInstanceCore` và yêu cầu mạng của SSM, phối hợp health check ALB–ASG và grace period, fail-open của ALB, ý nghĩa các mã 502/503/504, deregistration delay, cloud-init log.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output và thời gian đo là minh họa.
- **Chi phí:** lab dùng `t4g.nano`, EBS gp3 nhỏ, một NAT Gateway và một ALB. NAT và ALB là hai khoản lớn nhất, nên phải xóa ngay sau buổi học (xem ước tính trong lab).

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Hành vi này được quyết định bởi cờ **`DeleteOnTermination`** của từng volume:
   - Với **Root Volume**: Cờ này mặc định là `true`, nên khi instance bị terminate, toàn bộ ổ đĩa Root sẽ bị xóa vĩnh viễn cùng instance.
   - Với **Data Volume**: Cờ này mặc định là `false`, nên khi instance bị terminate, ổ đĩa Data vẫn được giữ nguyên vẹn ở trạng thái `available` trên AWS (và tiếp tục phát sinh phí lưu trữ cho đến khi bị xóa thủ công).
2. Vì **Target Tracking Scaling Policy** tự động hóa hoàn toàn phép toán tính toán tải. Thay vì người quản trị phải đau đầu thiết lập hàng loạt ngưỡng cứng phức tạp (như: nếu CPU > 70% thì thêm 1 máy, nếu CPU > 85% thì thêm 2 máy...), bạn chỉ cần định nghĩa một mục tiêu duy nhất (ví dụ: giữ CPU trung bình ở 60%). AWS sẽ tự động phân tích độ dốc của lưu lượng và quyết định số lượng máy ảo cần thêm hoặc bớt một cách tối ưu và mượt mà nhất.
3. Health check tới cổng 8080 bị Security Group chặn nên không nhận được phản hồi. Sau `unhealthy_threshold` lần, target chuyển sang **`unhealthy`** với reason `Target.Timeout`. Khi **tất cả** target unhealthy, ALB **fail open** và vẫn gửi request tới chúng, nhưng request cũng bị SG chặn, nên client thường nhận **`504 Gateway Timeout`** (không phải 503, vì target vẫn đang được đăng ký). Nếu ASG dùng health check ELB, ASG còn liên tục thay instance mà không giải quyết được gì, vì lỗi nằm ở SG chứ không ở máy.
4. Ba lý do vượt trội của SSM Session Manager:
   - **Bảo mật tối đa (Zero Open Ports):** Không cần mở bất kỳ cổng Inbound nào trên Security Group (không cần mở port 22), triệt tiêu hoàn toàn nguy cơ bị quét cổng (port scanning) và tấn công brute-force SSH từ Internet.
   - **Không cần quản lý SSH Key:** Loại bỏ gánh nặng tạo, phân phối, lưu trữ và thu hồi file `.pem`. Mọi quyền truy cập được quản lý tập trung và tức thời bằng IAM Policy.
   - **Ghi log và kiểm toán (Auditability):** CloudTrail ghi lại ai mở session vào instance nào, khi nào; output của session có thể được ghi lên Amazon S3 hoặc CloudWatch Logs, giúp đáp ứng yêu cầu kiểm toán.
5. **Ứng dụng phi trạng thái (Stateless Application)** là ứng dụng mà mọi request gửi đến đều chứa đầy đủ thông tin để xử lý độc lập, server không lưu trữ bất kỳ trạng thái phiên (session data), tệp tải lên (uploads) hay dữ liệu tạm của người dùng trên đĩa cứng cục bộ của chính máy đó (các dữ liệu này được chuyển sang dịch vụ lưu trữ ngoài như Redis, DynamoDB hoặc S3).
   Tính chất này là điều kiện tiên quyết cho Auto Scaling vì: Khi có nhu cầu co giãn, ASG có thể tùy ý khởi tạo một máy mới hoặc tiêu diệt bất kỳ máy cũ nào bất cứ lúc nào mà không làm mất phiên đăng nhập của người dùng hay làm hỏng dữ liệu giao dịch.
6. **ALB** giải quyết việc **phân phối request** và **ngừng gửi traffic** tới target hỏng (có một điểm vào ổn định). **ASG** giải quyết việc **duy trì đúng số lượng máy**: thay máy hỏng, thêm/bớt máy theo tải. Chỉ có ALB với 2 instance cố định: khi 1 máy chết, ALB dồn hết traffic sang máy còn lại (dịch vụ vẫn chạy nhưng mất dự phòng), và không ai tạo lại máy thứ hai cho đến khi bạn tự làm.
7. Instance mới boot, app chưa lên nên health check của ALB thất bại. Hết 30 giây grace period, ASG thấy ELB báo unhealthy và terminate instance, rồi launch instance mới, và vòng lặp lặp lại. Grace period phải dài hơn thời gian từ lúc launch đến lúc app sẵn sàng (đo thực tế rồi cộng thêm biên an toàn).

</details>

