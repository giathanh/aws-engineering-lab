# Bài 10 — Quan sát và Bảo mật Ứng dụng: CloudWatch, CloudTrail, Secrets Manager, Parameter Store và KMS

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 6–8 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Ba trụ cột Quan sát, Amazon CloudWatch và Kiểm toán CloudTrail). Buổi 2 gồm phần 4–7 (Quản lý Bí mật, Mã hóa Phong bì với AWS KMS và [lab thực hành](../labs/10-observability-and-application-security/README.md)).
> **Kế hoạch gốc:** [plans/10-observability-and-application-security.md](../plans/10-observability-and-application-security.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Thiết lập ba trụ cột của Khả năng quan sát (**Observability: Logs, Metrics, Traces**), triển khai ghi nhật ký có cấu trúc (**Structured JSON Logging**), mã định danh tương quan (**Correlation ID**) và cơ chế che giấu dữ liệu nhạy cảm (**Redaction / PII masking**).
2. Làm chủ **Amazon CloudWatch**: thiết lập thời hạn lưu trữ (**Log Retention**), truy vấn logs phức tạp với **Logs Insights**, xây dựng **CloudWatch Alarms** và bảng điều khiển trực quan (**Dashboards**).
3. Phân biệt rõ vai trò của **AWS CloudTrail** trong việc kiểm toán quản trị (**Management Events**) và theo dõi dữ liệu (**Data Events**), trả lời chính xác câu hỏi "Ai đã làm gì, vào lúc nào và từ đâu?".
4. Lựa chọn chính xác giữa **AWS Secrets Manager** và **Systems Manager Parameter Store**, thiết kế cơ chế lấy thông tin xác thực an toàn tại thời điểm chạy (**Runtime Retrieval**), lưu đệm bộ nhớ và tự động xoay vòng mật khẩu (**Automatic Rotation**).
5. Nắm vững cơ chế mật mã học **AWS Key Management Service (KMS)**: phân biệt AWS Managed Key với Customer Managed Key (CMK), cơ chế mã hóa phong bì (**Envelope Encryption**), mối quan hệ giữa **KMS Key Policy và IAM Policy**, và ngữ cảnh mã hóa (**Encryption Context**).

---

## Phần 1 — Ba Trụ cột Quan sát: Logs, Metrics, Traces và Structured Logging (45 phút)

Trong môi trường phân tán (Microservices & Serverless), một request của người dùng có thể đi qua API Gateway, kích hoạt Lambda, truy vấn DynamoDB và ghi vào S3. Khi có lỗi xảy ra, việc mở từng server kiểm tra log là bất khả thi.

```text
Ba Trụ cột của Khả năng Quan sát (Observability):
┌─────────────────────────┬─────────────────────────┬─────────────────────────┐
│       LOGS (Nhật ký)    │      METRICS (Chỉ số)   │      TRACES (Dấu vết)   │
├─────────────────────────┼─────────────────────────┼─────────────────────────┤
│ "Chuyện gì đã xảy ra?"  │ "Hệ thống có ổn không?" │ "Nút thắt cổ chai ở đâu?"│
│                         │                         │                         │
│ - Chuỗi sự kiện chi tiết│ - Số liệu đo lường      │ - Theo vết đường đi của │
│ - Thông báo lỗi stack   │   theo chuỗi thời gian  │   1 request xuyên qua   │
│ - Dạng JSON có cấu trúc │ - Tỷ lệ CPU, RAM, RPS   │   hàng loạt dịch vụ     │
│ - Request ID & PII ẩn   │ - Tỷ lệ lỗi 5xx, Độ trễ │ - Thời gian chạy từng chặng│
└─────────────────────────┴─────────────────────────┴─────────────────────────┘
```

### Mã định danh Tương quan (Correlation ID)
Khi một request bắt đầu tại rìa hệ thống (API Gateway hoặc Load Balancer), một mã định danh duy nhất (ví dụ `X-Correlation-Id: c89b3f...` hoặc `X-Amzn-Trace-Id`) được sinh ra và truyền dọc theo tất cả các HTTP header của các dịch vụ nội bộ. Mọi dòng log được in ra ở bất kỳ microservice nào đều phải gắn kèm Correlation ID này để kỹ sư có thể lọc toàn bộ hành trình của một giao dịch đơn lẻ chỉ bằng một câu query.

### Traces: dùng công cụ còn được hỗ trợ
- Distributed tracing trên AWS hiển thị trong **AWS X-Ray** (CloudWatch → Traces). Lambda, API Gateway có thể bật tracing ở mức dịch vụ mà không cần sửa code.
- Để instrument code (từng lệnh gọi DB, HTTP, SDK), AWS khuyến nghị **OpenTelemetry** qua AWS Distro for OpenTelemetry (ADOT). Các X-Ray SDK/daemon cũ đã được AWS thông báo chuyển sang chế độ bảo trì; kiểm tra trạng thái trong tài liệu hiện hành trước khi chọn cho dự án mới.

### Nhật ký có Cấu trúc (Structured JSON Logging) và Che giấu Dữ liệu Nhạy cảm
- **Không ghi log dạng chuỗi thô (Unstructured Text):** `print("User logged in: " + user)` khiến các công cụ phân tích không thể tách trường dữ liệu.
- **Chuẩn Structured JSON Logging:**
  ```json
  {
    "timestamp": "2026-10-31T21:45:00Z",
    "level": "INFO",
    "service": "order-service",
    "correlation_id": "c89b3f12-4567-89ab-cdef-0123456789ab",
    "event": "ORDER_CREATED",
    "order_id": "ORD-9982",
    "customer_id": "CUST-104",
    "payment_method": "CREDIT_CARD",
    "card_masked": "************4321"
  }
  ```
- **Che giấu dữ liệu (Redaction):** Tuyệt đối không bao giờ ghi Access Keys, mật khẩu, mã OTP, số thẻ tín dụng hoặc thông tin định danh cá nhân (PII) vào log! Bất kỳ dữ liệu nhạy cảm nào cũng phải được băm hoặc che giấu (`masking`) trước khi đẩy lên hệ thống tập trung.

---

## Phần 2 — Amazon CloudWatch: Logs, Metrics, Alarms và Logs Insights (50 phút)

Amazon CloudWatch là trung tâm điều khiển giám sát toàn diện của AWS.

```text
Hệ sinh thái Giám sát Amazon CloudWatch:
┌─────────────────┐       ┌────────────────────────┐       ┌──────────────────────┐
│ AWS Resources   │ ────> │ CloudWatch Logs        │ ────> │ CloudWatch Alarms    │
│ (EC2, ECS,      │       │ - Log Groups           │       │ - Đạt ngưỡng lỗi?    │
│  Lambda, RDS)   │       │ - Retention: 7-30 ngày │       │ - Gửi cảnh báo SNS   │
└────────┬────────┘       │ - Logs Insights Query  │       └──────────────────────┘
         │                └────────────────────────┘                  │
         │                                                            ▼
         │                ┌────────────────────────┐       ┌──────────────────────┐
         └──────────────> │ CloudWatch Metrics     │ ────> │ CloudWatch Dashboard │
                          │ - CPU, Latency, Errors │       │ (Trực quan hóa đồ thị│
                          │ - Custom Metrics       │       │  cho đội vận hành)   │
                          └────────────────────────┘       └──────────────────────┘
```

### Log Group và Thời hạn Lưu trữ (Retention Period)
- Mặc định, CloudWatch Log Group lưu trữ log **vĩnh viễn (Never Expire)**! Nếu không cấu hình, theo năm tháng dung lượng log tích tụ sẽ đẩy chi phí lưu trữ lên rất cao.
- **Best Practice:** Luôn cấu hình **Retention Period** cho mọi Log Group (ví dụ: môi trường dev/staging giữ 7–14 ngày; môi trường production giữ 30–90 ngày rồi đẩy lưu trữ lạnh sang S3 Glacier nếu cần tuân thủ pháp lý).

### Truy vấn Log với CloudWatch Logs Insights
Cung cấp ngôn ngữ truy vấn mạnh mẽ để quét hàng Gigabytes log trong vài giây mà không cần cài đặt cụm Elasticsearch/OpenSearch tốn kém:
```sql
fields @timestamp, correlation_id, order_id, @message
| filter level = "ERROR" and @message like /Timeout/
| sort @timestamp desc
| limit 50
```

### Custom metric: Embedded Metric Format (EMF)
Thay vì gọi `PutMetricData` trong code (thêm độ trễ và quyền IAM), ứng dụng có thể in một dòng log JSON theo **Embedded Metric Format**; CloudWatch Logs tự tách thành metric. Đây là cách Lab 10 tạo metric `Latency` và `Errors`. Mỗi tổ hợp tên metric + dimension là một custom metric tính phí riêng, nên **không** đưa giá trị nhiều biến thể (order ID, user ID) vào dimension.

### CloudWatch Alarms: Ngưỡng và Xử lý Dữ liệu Thiếu (Missing Data)
CloudWatch Alarm giám sát một Metric cụ thể qua các chu kỳ thời gian (Evaluation Periods). Khi metric vượt ngưỡng (Threshold), Alarm sẽ chuyển sang trạng thái `ALARM` và kích hoạt hành động (gửi email qua SNS, kích hoạt Auto Scaling hoặc tự khởi động lại máy ảo EC2).
- **Trường `TreatMissingData`:** Cực kỳ quan trọng!
  - `missing` (mặc định): không tính điểm thiếu; nếu mọi điểm trong cửa sổ đều thiếu, alarm về `INSUFFICIENT_DATA`.
  - `ignore`: giữ nguyên trạng thái hiện tại.
  - `notBreaching`: Coi như bình thường (khuyến nghị cho các metric đếm lỗi — khi không có lỗi tức là hệ thống đang hoạt động tốt).
  - `breaching`: Coi như vượt ngưỡng (thích hợp cho các heartbeat ping kiểm tra sống còn — không có dữ liệu nghĩa là server đã chết).

---

## Phần 3 — AWS CloudTrail: Kiểm toán Quản trị và Truy vết Hành vi (45 phút)

Nếu như CloudWatch trả lời câu hỏi "Ứng dụng hoạt động ra sao?", thì **AWS CloudTrail** trả lời câu hỏi bảo mật cốt tử: **"Ai đã làm gì, vào lúc nào, từ địa chỉ IP nào và trên tài nguyên nào?"**.

```text
Kiểm toán Tập trung với AWS CloudTrail:
┌─────────────────┐
│ IAM User / Role │ ──Gọi AWS API (ví dụ: ec2:TerminateInstances)──> AWS CloudTrail
└─────────────────┘                                                         │
                                                                            ▼
                                                             Ghi nhận Event JSON:
                                                             - userIdentity: ARN của người gọi
                                                             - eventTime: 2026-10-31T21:50:12Z
                                                             - sourceIPAddress: 198.51.100.24
                                                             - eventName: TerminateInstances
                                                             - requestParameters: InstanceId
```

### Phân biệt Management Events vs Data Events

| Tiêu chí | Management Events (Sự kiện Quản trị) | Data Events (Sự kiện Dữ liệu) |
|---|---|---|
| **Phạm vi** | Các thao tác trên Control Plane (Tạo/Sửa/Xóa VPC, EC2, IAM Role, Security Group, S3 Bucket). | Các thao tác trên Data Plane (Đọc/Ghi từng object trong S3: `GetObject`, `PutObject`; gọi `lambda:InvokeFunction`). |
| **Chi phí** | **Không tính phí** khi xem 90 ngày gần nhất trong Event History; bản sao đầu tiên của management event ghi ra S3 qua trail không tính phí event (vẫn trả tiền lưu S3). | **Có tính phí** theo số event (khoảng $0.10/100,000 event, giá minh họa); bucket nhiều request có thể tốn nhanh. |
| **Mặc định** | Event History luôn có sẵn, **theo từng Region**, chỉ 90 ngày. Muốn giữ lâu hơn hoặc gom nhiều Region/account, phải tạo trail (hoặc CloudTrail Lake). | Mặc định **bị tắt**; bạn phải tạo trail/event data store, chọn lọc bucket/prefix/function cần kiểm toán. |

> [!IMPORTANT]
> **CloudWatch không thay CloudTrail.** CloudWatch cho biết hệ thống *chạy thế nào* (log ứng dụng, metric); CloudTrail cho biết *ai gọi API AWS nào*. Event History cũng không chứa mọi thứ: không có data event, không có log ứng dụng, chỉ 90 ngày và chỉ Region đang xem.

---

## Phần 4 — Quản lý Bí mật: Secrets Manager vs SSM Parameter Store (50 phút)

**Nguyên tắc số 1 của Bảo mật Ứng dụng:** Tuyệt đối không bao giờ lưu cứng thông tin nhạy cảm (Database Password, API Token của cổng thanh toán, Private Key) trong file code, Dockerfile hay cấu hình Git repo!

```text
Cây quyết định lựa chọn Quản lý Bí mật:
                                   Bạn cần lưu trữ thông tin gì?
                                                 │
                 ┌───────────────────────────────┴──────────────────────────────┐
                 ▼                                                              ▼
        Cấu hình ứng dụng thông thường?                              Bí mật nhạy cảm (Mật khẩu DB,
        - URL của microservice khác                                  Khóa API Stripe, Private Key)?
        - Cờ tính năng (Feature Flags)                                          │
        - Chuỗi kết nối phi nhạy cảm                                            │
                 │                                                              │
                 ▼                                                              ▼
   SSM Parameter Store (Standard)                                Cần tính năng Tự động Xoay vòng
   - Không tính phí lưu trữ (tier Standard)                     (Automatic Credential Rotation)?
   - Tối đa 10,000 parameters                                                   │
                                                        ┌───────────────────────┴───────────────────────┐
                                                        ▼                                               ▼
                                                 Có cần Rotation?                               Chỉ cần lưu an toàn
                                                 - RDS Database Passwords                       và giải mã runtime?
                                                 - Khóa dịch vụ tài chính                               │
                                                        │                                               ▼
                                                        ▼                                 SSM Parameter Store (SecureString)
                                               AWS Secrets Manager                        - Mã hóa bằng AWS KMS
                                               - Managed rotation hoặc Lambda rotation    - Rẻ hơn (Standard không phí lưu)
                                               - ~$0.40/secret/tháng (minh họa)           - Không tự động xoay vòng
```

**Parameter Store `SecureString` không phải Secrets Manager rẻ hơn.** Nó mã hóa bằng KMS, nhưng không có rotation tự động, không có nhãn version `AWSCURRENT`/`AWSPREVIOUS` cho chuyển tiếp an toàn khi rotation, không có replication đa Region. Secrets Manager còn có managed rotation cho RDS (ví dụ `--manage-master-user-password` ở Bài 07).

**Mã hóa chưa đủ để bảo vệ secret.** Secret mã hóa tốt vẫn lộ nếu: role app có `secretsmanager:*` trên `*`, app in secret ra log, secret nằm trong biến môi trường hiển thị ở console, hoặc ai đó có quyền decrypt key. Bảo vệ thật là **least privilege trên cả secret và key**, không log giá trị, và audit bằng CloudTrail.

### Lấy Bí mật tại Thời điểm Chạy (Runtime Retrieval) và Lưu đệm (Caching)
- **Sai lầm phổ biến:** Đọc secret một lần khi build Docker image rồi nhúng cứng vào image. Điều này làm mất đi khả năng xoay vòng mật khẩu mà không cần rebuild container.
- **Mô hình chuẩn:**
  1. Ứng dụng khởi động, gọi AWS Secrets Manager API để lấy thông tin xác thực mới nhất qua IAM Role.
  2. Lưu thông tin đó vào bộ nhớ RAM của ứng dụng kèm thời gian sống (**TTL Cache, ví dụ 1 giờ**).
  3. Khi Database Password được xoay vòng, sau 1 giờ cache hết hạn, ứng dụng sẽ tự động truy vấn lại secret mới mà không cần phải khởi động lại server.

---

## Phần 5 — AWS Key Management Service (KMS): Mã hóa Phong bì và Quyền Giải mã (50 phút)

AWS KMS là dịch vụ quản lý khóa mật mã học tập trung đạt tiêu chuẩn FIPS 140-3 Cryptographic Module.

```text
Quy trình Mã hóa Phong bì (Envelope Encryption):
┌────────────────────────────────────────────────────────────────────────┐
│ 1. Tạo Khóa Dữ liệu (GenerateDataKey):                                 │
│    Ứng dụng ──kms:GenerateDataKey──> AWS KMS (Customer Managed Key)    │
│    Ứng dụng <──Nhận 2 khóa─────────── AWS KMS                          │
│                ├─ Plaintext Data Key (Dùng để mã hóa dữ liệu)          │
│                └─ Encrypted Data Key (Đã được khóa gốc KMS khóa lại)   │
├────────────────────────────────────────────────────────────────────────┤
│ 2. Mã hóa Dữ liệu (Tại tầng ứng dụng):                                 │
│    Dữ liệu thô + Plaintext Data Key ──Thuật toán AES-256──> Dữ liệu mã hóa│
│    (XÓA NGAY LẬP TỨC Plaintext Data Key KHỎI BỘ NHỚ RAM!)              │
├────────────────────────────────────────────────────────────────────────┤
│ 3. Lưu trữ:                                                            │
│    Lưu Dữ liệu mã hóa kèm theo Encrypted Data Key xuống ổ đĩa/Database. │
└────────────────────────────────────────────────────────────────────────┘
```

### Tại sao phải dùng Mã hóa Phong bì (Envelope Encryption)?
- Khóa KMS gốc (**KMS Key / CMK**) **không bao giờ rời khỏi phần cứng HSM bảo mật của AWS**. Bạn không thể tải khóa gốc về máy.
- KMS API giới hạn kích thước dữ liệu mã hóa trực tiếp tối đa là **4 KB**.
- Để mã hóa các file lớn (như file hóa đơn 100 MB hoặc ổ đĩa EBS hàng Terabytes), KMS sử dụng Envelope Encryption: KMS sinh ra một **Data Key** ngẫu nhiên. Data Key này được dùng để mã hóa file cục bộ cực nhanh bằng thuật toán đối xứng AES-GCM-256. Sau khi mã hóa xong, Data Key bản rõ bị tiêu hủy khỏi RAM, chỉ còn Data Key đã bị mã hóa được lưu kèm với dữ liệu.

### Phân cấp Quyền: KMS Key Policy vs IAM Policy
Để một IAM Principal (User hoặc Role) có thể giải mã dữ liệu (`kms:Decrypt`), phải thỏa mãn nguyên tắc đồng thuận kép:
1. **IAM Policy:** IAM Role của ứng dụng phải được cấp quyền `kms:Decrypt` trên tài nguyên ARN của khóa.
2. **KMS Key Policy (bắt buộc):** Key policy là nguồn quyền chính của key. Nó phải **hoặc** cấp quyền trực tiếp cho principal, **hoặc** có statement cho principal `arn:aws:iam::<account>:root` để "tin IAM của account" (default key policy có statement này). Chỉ khi có statement tin IAM, IAM policy mới có tác dụng. Nếu key policy không cấp trực tiếp và cũng không tin IAM, thì dù IAM Policy có `AdministratorAccess`, lệnh giải mã vẫn bị **`AccessDenied`**.
3. **Grant** là cách thứ ba để cấp quyền dùng key, thường do dịch vụ AWS (EBS, RDS) tạo tự động.

### Ngữ cảnh Mã hóa (Encryption Context)
Encryption Context là một tập hợp các cặp key-value bổ sung (ví dụ: `{"order_id": "ORD-1001", "department": "finance"}`) được truyền vào khi mã hóa:
- Encryption Context không được lưu trữ bí mật mà được lưu dưới dạng dữ liệu xác thực bổ sung (**AAD - Additional Authenticated Data**). Nó xuất hiện **dạng rõ** trong CloudTrail, nên không đặt dữ liệu nhạy cảm vào đó.
- **Tính năng bảo vệ toàn vẹn:** Khi giải mã, client bắt buộc phải cung cấp chính xác từng cặp key-value của Encryption Context như lúc mã hóa. Nếu kẻ tấn công đánh cắp được bản mã của đơn hàng này và cố gắng gán vào đơn hàng khác, quá trình giải mã sẽ thất bại với `InvalidCiphertextException`.
- Key policy/IAM có thể dùng điều kiện `kms:EncryptionContext:<key>` để chỉ cho decrypt dữ liệu của một tenant/đơn vị.

### Xóa key và secret
- **Key KMS** không xóa ngay được: `ScheduleKeyDeletion` với khoảng chờ 7–30 ngày, có thể hủy trong thời gian đó. Sau khi xóa, mọi dữ liệu mã hóa bằng key (kể cả backup) không giải mã được nữa. Nếu không chắc, `DisableKey` trước để xem có gì hỏng.
- **Secret** mặc định có recovery window 7–30 ngày; trong thời gian đó không tạo được secret mới cùng tên. Chỉ dùng `--force-delete-without-recovery` cho fixture lab.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 10)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. Tạo **Customer Managed Key** riêng cho lab; giải thích statement "tin IAM của account" trong key policy.
2. Tạo secret giả (mật khẩu sinh ngẫu nhiên, không bao giờ in ra) mã hóa bằng key đó, và một parameter cấu hình thường.
3. Deploy một Lambda "order API" làm fixture: structured JSON log có correlation ID, metric latency/error qua EMF, cache secret có TTL, inject lỗi có kiểm soát.
4. Logs Insights (p95 latency, số lỗi, hành trình một correlation ID), alarm và dashboard; tùy chọn email SNS tới chính bạn.
5. Negative case: principal khác có quyền đọc secret nhưng thiếu `kms:Decrypt`; decrypt sai encryption context.
6. Đổi secret khi app đang cache và quan sát độ trễ cập nhật.
7. Truy vết bằng CloudTrail: ai đọc secret (kể cả lần bị từ chối), ai đổi parameter.
8. Cleanup: xóa secret, lên lịch xóa key, kiểm tra inventory.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/10-observability-and-application-security/README.md](../labs/10-observability-and-application-security/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Lỗi `AccessDeniedException` khi gọi `kms:Decrypt`
- **Nguyên nhân 1:** IAM Role chưa được cấp quyền `kms:Decrypt` trong Identity Policy.
- **Nguyên nhân 2:** Bản thân **KMS Key Policy** chưa khai báo ủy quyền cho tài khoản (`root`) hoặc chưa thêm ARN của Role vào danh sách Principals được phép sử dụng khóa.
- **Nguyên nhân 3:** Dữ liệu được mã hóa kèm **Encryption Context**, nhưng khi gọi giải mã lại không truyền hoặc truyền sai cặp key-value của Encryption Context.

### 2. CloudWatch Alarm liên tục ở trạng thái `INSUFFICIENT_DATA`
- **Nguyên nhân:** Metric chưa có đủ điểm dữ liệu trong số chu kỳ đánh giá (`EvaluationPeriods`), hoặc metric chỉ sinh ra khi có lỗi (Error metric) nên trong điều kiện bình thường không có điểm dữ liệu nào được gửi lên.
- **Cách khắc phục:** Cấu hình tham số `TreatMissingData` chuyển sang `notBreaching` để Alarm duy trì trạng thái `OK` khi không có lỗi.

### 3. Đã đổi mật khẩu trong Secrets Manager nhưng ứng dụng vẫn dùng mật khẩu cũ
- **Nguyên nhân:** Ứng dụng đang lưu đệm (cache) giá trị secret trong RAM quá lâu mà không có cơ chế vô hiệu hóa cache (Cache Invalidation) khi secret thay đổi.
- **Cách khắc phục:** Thiết lập TTL cache ngắn hơn (ví dụ 5–15 phút), hoặc cài đặt cơ chế bắt sự kiện EventBridge khi Secrets Manager xoay vòng thành công để gửi tín hiệu xóa cache nội bộ của ứng dụng.

---

## Câu hỏi tự kiểm tra

1. Phân tích sự khác biệt về mặt vai trò, dạng dữ liệu và mục đích sử dụng giữa ba trụ cột Khả năng quan sát: Logs, Metrics và Traces?
2. Tại sao việc đặt thời hạn lưu trữ (Retention Period) cho các CloudWatch Log Groups lại là một quy tắc quản trị chi phí bắt buộc trong doanh nghiệp?
3. So sánh AWS Secrets Manager và Systems Manager Parameter Store. Khi nào bạn bắt buộc phải chi trả thêm chi phí để sử dụng Secrets Manager?
4. Trình bày cơ chế hoạt động của Mã hóa Phong bì (Envelope Encryption) trong AWS KMS? Tại sao AWS lại áp dụng cơ chế này thay vì dùng trực tiếp khóa gốc để mã hóa dữ liệu lớn?
5. Nếu một kỹ sư có quyền `AdministratorAccess` đầy đủ trong IAM Policy nhưng trong KMS Key Policy không hề đề cập đến họ, kỹ sư này có thể giải mã được dữ liệu được bảo vệ bởi khóa KMS đó không? Tại sao?

---

## Tài liệu tham khảo chính thức

- [Amazon CloudWatch User Guide](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/)
- [AWS CloudTrail User Guide](https://docs.aws.amazon.com/awscloudtrail/latest/userguide/)
- [AWS Secrets Manager User Guide](https://docs.aws.amazon.com/secretsmanager/latest/userguide/)
- [AWS Systems Manager Parameter Store](https://docs.aws.amazon.com/systems-manager/latest/userguide/systems-manager-parameter-store.html)
- [AWS Key Management Service Concepts & Envelope Encryption](https://docs.aws.amazon.com/kms/latest/developerguide/concepts.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** envelope encryption, encryption context và `InvalidCiphertextException`; key policy và statement tin IAM của account; `kms:ViaService`; Event History 90 ngày theo Region, chỉ management event; scheduled key deletion 7–30 ngày; recovery window của secret; Embedded Metric Format; hướng dẫn chuyển sang OpenTelemetry cho tracing.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output là minh họa.
- **Chi phí:** buổi lab dưới $0.10 (key, secret, metric, alarm tính theo thời gian tồn tại). Key ở trạng thái chờ xóa thêm 7 ngày sau lab (xem bảng trong lab).

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Ba trụ cột:
   - **Logs:** Chuỗi sự kiện chi tiết dạng text/JSON kèm dấu thời gian. Trả lời "Điều gì cụ thể đã xảy ra tại thời điểm đó?".
   - **Metrics:** Số liệu định lượng được tổng hợp theo thời gian (CPU, Latency, Error count). Trả lời "Hệ thống đang hoạt động có khỏe mạnh không và xu hướng ra sao?".
   - **Traces:** Theo dõi hành trình của một request đi xuyên qua toàn bộ các ranh giới dịch vụ phân tán. Trả lời "Điểm nghẽn độ trễ nằm ở microservice nào?".
2. Mặc định CloudWatch Log Groups không bao giờ hết hạn (`Never Expire`). Theo thời gian, hàng triệu dòng log debug, log truy cập tích tụ hàng trăm Gigabytes dữ liệu, gây ra chi phí lưu trữ phát sinh liên tục hàng tháng. Đặt Retention (ví dụ 14 ngày) giúp tự động dọn dẹp log rác, duy trì ngân sách tối ưu.
3. So sánh:
   - **Parameter Store:** Phù hợp cho cấu hình hệ thống, biến môi trường và secret tĩnh với chi phí cực rẻ (hoặc miễn phí với Standard tier).
   - **Secrets Manager:** Bắt buộc phải dùng khi bạn cần tính năng **Tự động xoay vòng mật khẩu (Automatic Rotation)** cho Database (tự động đổi password trên cả RDS và Secrets Manager định kỳ mà không làm gián đoạn ứng dụng), hoặc cần chia sẻ secret an toàn xuyên nhiều tài khoản AWS (Cross-account secret sharing).
4. Envelope Encryption sinh ra một Data Key dùng một lần để mã hóa dữ liệu cục bộ, sau đó khóa gốc KMS chỉ mã hóa lại chính Data Key đó.
   - Lý do:
     - Khóa gốc KMS không bao giờ rời khỏi chip bảo mật của AWS và chỉ nhận mã hóa tối đa 4 KB dữ liệu.
     - Mã hóa trực tiếp dữ liệu lớn qua mạng sẽ làm nghẽn băng thông và gây độ trễ mạng khủng khiếp.
     - Envelope Encryption cho phép mã hóa dữ liệu hàng GB/TB ngay tại RAM máy chủ bằng thuật toán phần cứng AES siêu tốc.
5. **Tùy key policy.** Key policy là cơ chế kiểm soát chính của KMS.
   - Nếu key policy có statement cho `arn:aws:iam::<account>:root` (như default key policy), key "tin IAM": khi đó IAM policy `AdministratorAccess` **có** tác dụng và kỹ sư giải mã được, dù tên họ không xuất hiện trong key policy.
   - Nếu key policy **không** có statement đó và cũng không cấp quyền trực tiếp cho họ (hoặc có `Deny` khớp với họ), thì mọi lệnh `kms:Decrypt` bị `AccessDenied`, bất kể IAM cấp gì.
   - Bài học: "không đề cập trong key policy" chưa đủ để kết luận; phải đọc toàn bộ key policy, IAM policy và grant.

</details>

