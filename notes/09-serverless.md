# Bài 09 — Kiến trúc Không Máy chủ (Serverless): AWS Lambda và Amazon API Gateway

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 5–7 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Môi trường thực thi Lambda, 3 mô hình Invocation & Quản lý Concurrency). Buổi 2 gồm phần 4–7 (Amazon API Gateway, Version/Alias, Kinh tế học Serverless và [lab thực hành](../labs/09-serverless/README.md)).
> **Kế hoạch gốc:** [plans/09-serverless.md](../plans/09-serverless.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Hiểu sâu vòng đời môi trường thực thi (**Lambda Execution Environment**), hàm xử lý (**Handler**), mối quan hệ tỷ lệ thuận giữa **Memory & vCPU**, hiện tượng **Cold Start** và kỹ thuật tối ưu hóa khởi động.
2. Phân biệt rõ 3 mô hình kích hoạt hàm Lambda: **Đồng bộ (Synchronous)**, **Bất đồng bộ (Asynchronous)** và **Ánh xạ nguồn sự kiện (Event Source Mapping)**; cơ chế xử lý lỗi, retry và **Dead Letter Queue (DLQ)**.
3. Làm chủ bài toán tương tranh (**Concurrency**): phân biệt **Reserved Concurrency** (bảo vệ cơ sở dữ liệu hạ nguồn) với **Provisioned Concurrency** (triệt tiêu Cold Start), xử lý hiện tượng nghẽn (**Throttling - HTTP 429**) và thiết kế tính lũy kế (**Idempotency**).
4. Lựa chọn chính xác giữa **API Gateway HTTP API** (hiện đại, tối ưu chi phí, nhẹ) và **REST API** (đầy đủ tính năng doanh nghiệp); cơ chế tích hợp **Lambda Proxy Integration (Payload Format Version 2.0)**.
5. Đóng gói mã nguồn (**Zip archive vs Container Image**), quản lý phiên bản (**Version & Alias**) và phân tích mô hình kinh tế Serverless (so sánh chi phí giữa Lambda với EC2/Fargate luôn chạy).

---

## Phần 1 — Vòng đời AWS Lambda: Execution Environment, Memory, Timeout và Cold Start (45 phút)

### Kiến trúc Không Máy chủ (Serverless Computing)
AWS Lambda cho phép bạn chạy mã nguồn mà không cần cấp phát hay quản lý máy chủ. Bạn chỉ cần tải code lên, Lambda sẽ tự động lo liệu toàn bộ việc co giãn, vá lỗi hệ điều hành và tính sẵn sàng cao.

```text
Vòng đời một Lambda Execution Environment (MicroVM Firecracker):
┌────────────────────────────────────────────────────────────────────────┐
│ 1. INIT Phase (Cold Start - Chạy 1 lần khi tạo container vi mô)        │
│    - Tải mã nguồn hàm từ S3                                            │
│    - Khởi động Runtime (Node.js, Python, Java...)                      │
│    - Chạy mã ngoài Handler: Khởi tạo AWS SDK clients, DB connections   │
├────────────────────────────────────────────────────────────────────────┤
│ 2. INVOKE Phase (Chạy mỗi khi có request)                              │
│    - Nhận sự kiện (event, context)                                     │
│    - Thực thi hàm `handler(event, context)`                            │
│    - Trả kết quả về cho caller                                         │
├────────────────────────────────────────────────────────────────────────┤
│ 3. SHUTDOWN Phase (Khi môi trường không có request sau một thời gian)  │
│    - Dọn dẹp runtime, đóng kết nối và tiêu diệt MicroVM                │
└────────────────────────────────────────────────────────────────────────┘
```

### Handler Function và Tối ưu hóa Code
Hàm xử lý (**Handler**) là điểm vào (Entry point) được AWS Lambda gọi khi thực thi code.
```python
import os
import boto3

# MÃ NGOÀI HANDLER (Chạy trong INIT Phase - Chỉ chạy 1 lần duy nhất trong Cold Start):
# Khởi tạo SDK client ở đây để tái sử dụng qua hàng ngàn lần INVOKE sau đó!
dynamodb = boto3.resource('dynamodb')
table = dynamodb.Table(os.environ['ORDERS_TABLE'])

def lambda_handler(event, context):
    # MÃ TRONG HANDLER (Chạy mỗi lần có request đến - Warm Execution):
    order_id = event.get('order_id')
    print(f"Đang xử lý đơn hàng: {order_id}, Request ID: {context.aws_request_id}")
    return {"status": "SUCCESS", "order_id": order_id}
```

> [!TIP]
> **Quy tắc vàng hiệu năng:** Luôn khởi tạo kết nối Database, HTTP client và AWS SDK bên ngoài hàm Handler. Nếu bạn viết `boto3.resource('dynamodb')` bên trong `lambda_handler`, hàm của bạn sẽ phải khởi tạo lại kết nối trên từng request, gây lãng phí CPU và tăng độ trễ nghiêm trọng.

### Tương quan giữa Memory và CPU
Trong Lambda, bạn **chỉ có thể chọn dung lượng Memory** (từ 128 MB đến 10,240 MB). Bạn **không thể chọn số lượng vCPU độc lập**:
- AWS tự động phân bổ năng lực tính toán CPU tỷ lệ thuận với lượng RAM được cấu hình.
- Tại mốc **1,769 MB RAM**, hàm Lambda được cấp phát chính xác **1 vCPU đầy đủ**.
- Trên 1,769 MB, hàm có nhiều hơn 1 vCPU (tối đa 6 vCPU ở 10,240 MB), nhưng code phải chạy đa luồng/đa tiến trình mới tận dụng được phần vượt 1 vCPU. Với các tác vụ tốn CPU (mã hóa, nén ảnh), tăng RAM có thể làm hàm chạy nhanh hơn nhiều, khiến tổng chi phí `Thời gian thực thi x RAM` có khi **rẻ hơn** cấu hình RAM thấp. Đo bằng số liệu thật (ví dụ công cụ AWS Lambda Power Tuning) thay vì đoán.

### Timeout
Thời gian chạy tối đa của một hàm Lambda là **15 phút (900 giây)**.
- Với API sau API Gateway, client chỉ chờ tới **timeout của integration**: HTTP API tối đa **30 giây**; REST API mặc định **29 giây** (regional/private REST API có thể xin tăng quota). Đặt timeout của function **nhỏ hơn** timeout integration, và chuyển việc dài sang xử lý bất đồng bộ (SQS, Step Functions).

### Hiện tượng Khởi động Lạnh (Cold Start)
- **Cold Start:** Xảy ra khi một request đến mà không có sẵn môi trường thực thi nào đang rảnh (ví dụ: request đầu tiên sau khi deploy, hoặc khi lưu lượng tăng đột biến đòi hỏi scale-out thêm container mới). Lambda phải chạy qua cả pha INIT và INVOKE, khiến request đầu tiên có thể mất thêm từ vài trăm mili-giây đến vài giây.
- **Warm Start:** Các request tiếp theo được chuyển thẳng tới container đã khởi tạo sẵn và chỉ tốn thời gian chạy pha INVOKE (vài mili-giây).
- **Yếu tố ảnh hưởng Cold Start:**
  - Ngôn ngữ: Python, Node.js, Go thường có Cold Start vài trăm mili-giây. Java, .NET thường lâu hơn (1–3s) do khởi động JVM/CLR; Lambda SnapStart giảm đáng kể cho Java (và một số runtime khác).
  - Gắn VPC: từ 2019 Lambda dùng Hyperplane ENI nên cold start do VPC không còn đáng kể như trước, nhưng việc tạo ENI lần đầu khi cấu hình vẫn mất thời gian.
  - Kích thước package: Dung lượng code và thư viện càng gọn nhẹ thì kéo về càng nhanh.

---

## Phần 2 — Ba Mô hình Kích hoạt: Synchronous, Asynchronous và Event Source Mapping (50 phút)

Hiểu rõ 3 mô hình kích hoạt là điều kiện tiên quyết để thiết kế kiến trúc Serverless có khả năng phục hồi lỗi cao:

```text
3 Mô hình Kích hoạt Lambda:
1. Đồng bộ (Synchronous):
   Client (Web) ──Request──> API Gateway ──Sync Invoke──> AWS Lambda ──HTTP 200──> Trả kết quả ngay

2. Bất đồng bộ (Asynchronous):
   S3 / SNS / EventBridge ──Async Event──> AWS Lambda Internal Queue ──> AWS Lambda
                                                     │
                                                     └── Lỗi 2 lần? ──> Dead Letter Queue (SQS)

3. Ánh xạ nguồn sự kiện (Event Source Mapping - Polling):
   DynamoDB Streams / SQS / Kinesis <──Poll theo lô── Lambda ESM Component ──> Gọi Lambda
```

### 1. Đồng bộ (Synchronous Invocation)
- **Nguồn kích hoạt:** Amazon API Gateway, Application Load Balancer, AWS CLI (`--invocation-type RequestResponse`), hoặc gọi trực tiếp từ code microservice khác.
- **Hành vi:** Client gửi request và **treo kết nối chờ đợi** cho đến khi hàm Lambda chạy xong và trả về kết quả (hoặc timeout).
- **Xử lý lỗi:** Nếu hàm văng lỗi hoặc timeout, Lambda **không tự động retry**. Trách nhiệm xử lý lỗi và retry thuộc về phía Client gọi hàm (lưu ý: AWS SDK của client có thể tự retry một số lỗi, nên handler vẫn cần idempotent).

### 2. Bất đồng bộ (Asynchronous Invocation)
- **Nguồn kích hoạt:** Amazon S3 (khi có file mới upload), Amazon SNS, EventBridge, AWS CLI (`--invocation-type Event`).
- **Hành vi:** Dịch vụ gọi gửi event vào hàng đợi nội bộ do AWS quản lý và nhận ngay phản hồi `HTTP 202 Accepted`. Client không chờ code chạy.
- **Xử lý lỗi tự động:** Nếu hàm chạy thất bại, Lambda **tự động thử lại 2 lần** (Tổng cộng 3 lần chạy). Giữa các lần thử có cơ chế giãn cách thời gian (Exponential Backoff).
- **Dead Letter Queue (DLQ) / Lambda Destinations:** Nếu sau 3 lần vẫn thất bại (hoặc event quá tuổi tối đa, mặc định 6 giờ), event sẽ bị hủy bỏ trừ khi bạn cấu hình DLQ (SQS/SNS) hoặc **on-failure destination** (SQS, SNS, Lambda, EventBridge; destination chứa thêm thông tin lỗi). Có thể giảm số lần retry (0–2) và tuổi tối đa của event.

### 3. Ánh xạ Nguồn Sự kiện (Event Source Mapping - ESM)
- **Nguồn kích hoạt:** Amazon SQS, Amazon Kinesis Data Streams, Amazon DynamoDB Streams.
- **Hành vi:** Dịch vụ nguồn không chủ động gọi Lambda. Thay vào đó, một bộ điều phối nội bộ của AWS (**ESM Poller**) liên tục thăm dò (poll) dữ liệu từ hàng đợi/luồng, gom thành từng lô (Batch), rồi chuyển lô đó vào hàm Lambda xử lý.
- **Xử lý lỗi (khác nhau theo nguồn):**
  - **SQS:** nếu hàm thất bại, **cả batch** quay lại hàng đợi sau `Visibility Timeout` (trừ khi bật `ReportBatchItemFailures` và trả về danh sách message lỗi). Sau `maxReceiveCount` lần, message sang DLQ **của queue** (cấu hình trên SQS, không phải trên Lambda).
  - **Kinesis / DynamoDB Streams:** Lambda retry batch lỗi cho tới khi record hết hạn trong stream, và **chặn** các record sau trong cùng shard. Cấu hình `MaximumRetryAttempts`, `BisectBatchOnFunctionError` và on-failure destination để không kẹt shard.

> [!IMPORTANT]
> Không có "một cơ chế retry của Lambda". Retry phụ thuộc **cách gọi**: synchronous do caller quyết định; asynchronous do Lambda retry 2 lần; event source mapping theo quy tắc của từng nguồn.

---

## Phần 3 — Quản lý Tương tranh, Throttling và Tính Lũy kế Idempotency (50 phút)

### Hạn ngạch Tương tranh (Concurrency Quota)
- Concurrency là số lượng môi trường thực thi của hàm Lambda đang đồng thời xử lý request tại một thời điểm cụ thể.
- Công thức: `Concurrency = (Số request mỗi giây - RPS) x (Thời gian xử lý trung bình mỗi request tính bằng giây)`.
- *Ví dụ:* 1,000 RPS với thời gian xử lý là 100ms (0.1s) => Cần `1,000 x 0.1 = 100 concurrent executions`.
- Mặc định, mỗi tài khoản AWS có một hạn mức tương tranh dùng chung là **1,000** trên mỗi Region (account mới có thể thấp hơn; kiểm tra bằng `aws lambda get-account-settings`). Lambda luôn giữ lại một phần unreserved tối thiểu (100 với quota 1,000), nên không thể reserve hết.

```text
Phân biệt Reserved Concurrency vs Provisioned Concurrency:
┌────────────────────────────────────────────────────────────────────────┐
│ Toàn bộ Tài khoản AWS Region (Hạn ngạch 1,000 Concurrency)             │
│                                                                        │
│  ┌─────────────────────────┐  ┌─────────────────────────────────────┐  │
│  │ Unreserved Concurrency  │  │ Reserved Concurrency: 100           │  │
│  │ (900 Concurrency)       │  │ (Dành riêng cho Order Processing)   │  │
│  │ Dùng chung cho toàn bộ  │  │                                     │  │
│  │ các Lambda khác         │  │  ┌───────────────────────────────┐  │  │
│  │                         │  │  │ Provisioned Concurrency: 20   │  │  │
│  │                         │  │  │ (20 microVM luôn bật sẵn RAM) │  │  │
│  │                         │  │  └───────────────────────────────┘  │  │
│  └─────────────────────────┘  └─────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────────────┘
```

### Reserved Concurrency (Tương tranh Dành riêng)
- **Bảo đảm tài nguyên:** Đảm bảo hàm luôn có sẵn một số lượng tương tranh nhất định, không bị các hàm khác trong tài khoản chiếm dụng hết.
- **Trần cứng bảo vệ hạ nguồn:** Reserved concurrency đồng thời là **giới hạn tối đa**. Giả sử hàm Lambda kết nối tới database RDS PostgreSQL chỉ chịu được tối đa 50 kết nối. Nếu không đặt Reserved Concurrency, khi có đợt sale lớn, Lambda có thể scale lên 500 environment đồng thời và đánh sập database. Đặt `Reserved Concurrency = 50` giới hạn cứng số environment (mỗi environment giữ một kết nối nếu khởi tạo ngoài handler). Đặt `0` để tạm dừng hoàn toàn một function.
- Reserved concurrency **không tính phí** thêm.

### Provisioned Concurrency (Tương tranh Cấp phát sẵn)
- Khởi tạo sẵn một số lượng môi trường thực thi đã chạy xong pha INIT (đã nạp sẵn code và kết nối SDK).
- **Loại bỏ Cold Start trong phạm vi đã cấp:** request nằm trong số environment đã cấp sẵn không bị cold start; vượt số đó thì Lambda scale như bình thường và vẫn có cold start. Gắn với **version hoặc alias**, không gắn với `$LATEST`.
- **Tính phí** theo thời gian bật, kể cả khi không có request. Chỉ dùng khi đã đo được cold start ảnh hưởng tới SLO.

### Hiện tượng Nghẽn (Throttling)
- Khi số lượng request vượt quá hạn ngạch Concurrency, Lambda sẽ từ chối xử lý:
  - Gọi đồng bộ: Lambda trả lỗi `TooManyRequestsException` (HTTP 429) cho caller; API Gateway phía trước sẽ chuyển thành lỗi cho client.
  - Gọi bất đồng bộ: Lambda tự động giữ event trong hàng đợi nội bộ tối đa 6 giờ và thử lại khi có tài nguyên rảnh.

### Thiết kế Tính Lũy kế (Idempotency)
Vì mạng Internet có thể bị chập chờn và các cơ chế Retry tự động có thể gửi cùng một request nhiều lần, mã nguồn xử lý thanh toán/đơn hàng bắt buộc phải đảm bảo tính **Idempotent** (Thực hiện thao tác 1 lần hay 10 lần thì kết quả nghiệp vụ vẫn chỉ trừ tiền và tạo đơn đúng 1 lần):
- Sử dụng **Idempotency Key** (thường là UUID sinh ra từ client khi bấm nút đặt hàng).
- Lưu trạng thái Idempotency Key vào bảng DynamoDB kèm thời gian sống (TTL). Nếu phát hiện key đã được xử lý thành công trước đó, hàm trả về ngay kết quả cũ mà không thực hiện trừ tiền lần thứ hai.

---

## Phần 4 — Amazon API Gateway: HTTP API vs REST API và Proxy Integration (50 phút)

Amazon API Gateway là dịch vụ quản lý cổng API mạnh mẽ, đóng vai trò "cửa ngõ" đón nhận toàn bộ lưu lượng HTTP từ Internet và định tuyến vào Lambda hoặc các dịch vụ AWS khác.

```text
Luồng tích hợp qua API Gateway HTTP API (Proxy Integration v2.0):
Client ──HTTP POST /orders──> API Gateway HTTP API ──Event JSON (v2.0)──> AWS Lambda
                                                                               │
Client <──HTTP 201 Created──── API Gateway <──Trả về JSON {statusCode, body}──┘
```

### Lựa chọn HTTP API vs REST API

| Tính năng | API Gateway HTTP API (v2) | API Gateway REST API (v1) |
|---|---|---|
| **Đặc điểm kiến trúc** | Ra đời sau, tối ưu cho Serverless hiện đại, siêu nhẹ, độ trễ thấp | Thế hệ đầu tiên, đầy đủ tính năng doanh nghiệp phức tạp |
| **Chi phí** | **Rẻ hơn ~70%** ($1.00 / triệu request) | $3.50 / triệu request |
| **Xác thực (Authentication)** | JWT authorizer (OIDC/OAuth 2.0, ví dụ Cognito) tích hợp sẵn, Lambda authorizer, IAM SigV4 | Cognito User Pools authorizer, Lambda authorizer, IAM SigV4, API key (không phải auth) |
| **Tích hợp WAF** | Không hỗ trợ trực tiếp (cần đưa qua CloudFront) | Hỗ trợ gắn trực tiếp AWS WAF |
| **Tính năng mở rộng** | CORS cấu hình sẵn; throttling theo stage/route | API Keys, Usage Plans (giới hạn theo từng client), cache, request validation, private endpoint |
| **Lỗi khi Lambda ném exception/timeout** | `500 Internal Server Error` | `502 Bad Gateway` |
| **Khuyến nghị sử dụng** | **Ưu tiên số 1** cho các dịch vụ Web/Mobile API hiện đại | Dùng khi bắt buộc cần phân phối API Key cho đối tác thứ ba hoặc tích hợp WAF trực tiếp |

### Lambda Proxy Integration và Chuẩn Payload Format v2.0
Khi sử dụng **Proxy Integration**, API Gateway sẽ đóng gói toàn bộ HTTP request (Path, Query parameters, Headers, Body) thành một JSON payload và chuyển nguyên vẹn vào tham số `event` của Lambda.

Ngược lại, hàm Lambda **bắt buộc phải trả về một Dictionary/Object** đúng cấu trúc quy chuẩn để API Gateway có thể biên dịch ngược lại thành HTTP response gửi cho trình duyệt:

```json
{
  "statusCode": 200,
  "headers": {
    "Content-Type": "application/json",
    "Access-Control-Allow-Origin": "*"
  },
  "body": "{\"message\": \"Đơn hàng đã được tạo thành công!\"}"
}
```

> [!WARNING]
> Trường `body` phải là **chuỗi** (JSON đã stringify). Hành vi khi vi phạm khác nhau theo format:
> - **REST API / payload format 1.0:** response không đúng cấu trúc → `502 Bad Gateway` (`Malformed Lambda proxy response`).
> - **HTTP API, payload format 2.0:** nếu function trả JSON hợp lệ **không có** `statusCode`, API Gateway **đoán**: status `200`, `content-type: application/json`, body là toàn bộ object. Vì vậy một response "quên" `statusCode` vẫn trả `200`, lỗi bị che đi. Luôn trả đủ `statusCode`, `headers`, `body` để không phụ thuộc phỏng đoán.

---

## Phần 5 — Đóng gói, Phiên bản, Phân chia Tải và Kinh tế học Serverless (45 phút)

### Định dạng Đóng gói (Packaging Formats)
1. **Zip Archive (Tối đa 50 MB nén, 250 MB giải nén):** Định dạng truyền thống, tải lên nhanh nhất, tối ưu cho phần lớn các thư viện nhẹ.
2. **Container Image (Tối đa 10 GB):** Cho phép bạn đóng gói code và toàn bộ các dependencies nặng (như thư viện Machine Learning PyTorch, TensorFlow, FFmpeg) vào Dockerfile và đẩy lên ECR.

### Quản lý Phiên bản (Version) và Định danh (Alias)
- Khi phát triển, code của bạn nằm ở phiên bản `$LATEST` (có thể thay đổi).
- Khi release lên production, bạn thực hiện xuất bản một phiên bản bất biến (**Version**, ví dụ: `Version 1`, `Version 2`).
- **Alias (Bí danh):** Là một con trỏ trỏ tới một Version cụ thể (ví dụ alias `PROD` trỏ tới Version 1).
- **Canary Deployment (Phân tách tải có trọng số):** Cho phép alias `PROD` trỏ tới Version 1 (90% lưu lượng) và Version 2 mới deploy (10% lưu lượng) để kiểm thử thực tế an toàn trước khi chuyển đổi 100%.

### So sánh Kinh tế học: Serverless vs Máy chủ Luôn chạy (EC2/Fargate)
- **EC2 / Fargate:** Bạn phải trả tiền 24/7 cho năng lực tính toán đã cấp phát, bất kể lúc nửa đêm có người truy cập hay không. Phù hợp cho tải công việc đều đặn, chạy liên tục và dự đoán trước được dung lượng.
- **AWS Lambda:** **Thanh toán theo request và GB-giây thực chạy.** Khi không có request, phí compute bằng $0 (vẫn có phí log, storage và Provisioned Concurrency nếu bật). Free Tier có thể giảm thêm nhưng điều kiện khác nhau theo loại account, nên đừng dùng nó làm căn cứ thiết kế. Phù hợp cho lưu lượng biến thiên, tác vụ theo sự kiện, batch nhỏ.
- **Điểm hòa vốn:** với tải cao và đều 24/7, chi phí Lambda (+ API Gateway theo request) có thể vượt Fargate/EC2 chạy liên tục. Tính bằng số request/tháng và thời gian chạy trung bình trước khi chọn.

### Lambda gắn VPC
- Mặc định Lambda chạy ngoài VPC của bạn và ra Internet được. Khi gắn vào subnet (để tới RDS/ElastiCache private), function **mất đường Internet** trừ khi subnet có route tới NAT Gateway; gán public IP không có tác dụng.
- Gọi dịch vụ AWS từ Lambda trong VPC cần NAT hoặc VPC endpoint. Chỉ gắn VPC khi thật sự cần tài nguyên private.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 09)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. Viết hàm Lambda Python (arm64) theo payload format 2.0, chỉ log các trường cần thiết.
2. Execution role chỉ có quyền ghi log; log group tạo trước với retention.
3. **HTTP API** với route `POST /orders`, `GET /orders/{orderId}`, resource-based policy giới hạn theo API.
4. Giới hạn chi phí bằng throttling ở stage và reserved concurrency.
5. Kiểm thử 200/201/400/404 và phân biệt 404 của API Gateway với 404 của function.
6. Negative case: exception và timeout (HTTP API trả `500`), response thiếu `statusCode` (format 2.0 tự suy ra `200`), throttling `429`; đọc nguyên nhân trong log.
7. Tùy chọn: S3 trigger với prefix tách biệt.
8. Cleanup và kiểm tra inventory.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/09-serverless/README.md](../labs/09-serverless/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. API Gateway trả về 5xx (HTTP API: `500`, REST API: `502`)
- **Nguyên nhân cốt lõi:** function ném exception, timeout, hoặc (với REST API/format 1.0) định dạng phản hồi không đúng chuẩn proxy. Nếu log của function **không có dòng nào** cho request đó, nghi API Gateway không được phép invoke (thiếu resource-based policy).
- **Cách khắc phục:**
  - Kiểm tra xem output của hàm có chứa đủ 2 trường bắt buộc: `statusCode` (số nguyên) và `body` (chuỗi string) không.
  - Kiểm tra xem hàm Lambda có bị crash hoặc văng ngoại lệ trước khi kịp return response hay không bằng cách tra cứu log trong CloudWatch.

### 2. Hàm Lambda bị kết thúc bởi lỗi Task Timed Out
- **Triệu chứng:** `Task timed out after 3.00 seconds`.
- **Nguyên nhân:** Thời gian timeout mặc định khi tạo hàm thường là 3 giây. Nếu hàm phải gọi ra mạng bên ngoài, gọi database hoặc query bên thứ ba, 3 giây có thể không đủ.
- **Cách khắc phục:** Tăng thông số timeout của hàm lên 10–15 giây trong cấu hình hàm (`aws lambda update-function-configuration --timeout 15`).

### 3. Vòng lặp kích hoạt đệ quy vô tận (Recursive Loop Hazard)
- **Kịch bản sự cố:** Hàm Lambda được kích hoạt bởi sự kiện S3 `ObjectCreated`, sau đó trong code của hàm lại ghi một file log mới vào chính bucket đó với cùng prefix.
- **Hệ quả:** Mỗi lần hàm chạy lại sinh ra 1 file mới -> S3 lại kích hoạt thêm 1 lần chạy mới -> Hàm chạy hàng triệu lần trong vài phút làm cháy hạn ngạch Concurrency và gây tốn kém chi phí khổng lồ.
- **Cách phòng chống:** Luôn luôn ghi file đầu ra sang một Bucket khác hoặc sử dụng một Prefix hoàn toàn tách biệt (ví dụ: trigger chỉ lắng nghe prefix `uploads/`, còn output ghi vào `processed/`). Lambda có **recursive loop detection** cho một số nguồn (SQS, SNS, S3) và dừng vòng lặp sau khoảng 16 lần gọi liên tiếp, nhưng đó là lưới an toàn, không thay cho thiết kế; đặt thêm reserved concurrency làm trần chi phí.

---

## Câu hỏi tự kiểm tra

1. Trình bày vòng đời của một Lambda Execution Environment. Tại sao việc khởi tạo các kết nối Database hoặc AWS SDK client bên ngoài hàm Handler lại giúp tối ưu hóa hiệu năng vượt bậc?
2. So sánh hành vi xử lý lỗi và cơ chế thử lại (Retry) giữa hai mô hình: Kích hoạt đồng bộ (Synchronous) và Kích hoạt bất đồng bộ (Asynchronous)?
3. Phân biệt mục đích sử dụng giữa **Reserved Concurrency** và **Provisioned Concurrency**? Trong trường hợp nào bạn bắt buộc phải cấu hình Reserved Concurrency để bảo vệ hệ thống?
4. Trình bày ít nhất 3 lý do vì sao Amazon API Gateway HTTP API được ưa chuộng hơn REST API trong các kiến trúc Serverless hiện đại?
5. Khi một hàm Lambda hoạt động phía sau API Gateway với chế độ Lambda Proxy Integration, tại sao trường `body` trong response trả về bắt buộc phải là một chuỗi string thay vì một JSON object trực tiếp? Điều gì xảy ra nếu vi phạm?

---

## Tài liệu tham khảo chính thức

- [AWS Lambda Developer Guide](https://docs.aws.amazon.com/lambda/latest/dg/welcome.html)
- [Lambda Execution Environment lifecycle](https://docs.aws.amazon.com/lambda/latest/dg/runtimes-context.html)
- [Managing Concurrency for a Lambda function](https://docs.aws.amazon.com/lambda/latest/dg/configuration-concurrency.html)
- [Working with HTTP APIs in Amazon API Gateway](https://docs.aws.amazon.com/apigateway/latest/developerguide/http-api.html)
- [AWS Lambda Operating System and architecture specifications](https://docs.aws.amazon.com/lambda/latest/dg/lambda-runtimes.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** payload format 2.0 và quy tắc suy ra response khi thiếu `statusCode`; timeout integration (HTTP API 30 giây, REST API 29 giây mặc định); retry theo từng kiểu invoke; reserved/provisioned concurrency; recursive loop detection; arm64 có đơn giá GB-giây thấp hơn x86_64 khoảng 20%.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output (đặc biệt mã lỗi 500/503 của HTTP API) là minh họa, cần đối chiếu khi chạy.
- **Chi phí:** lab dưới $0.01 ngay cả khi không có Free Tier (vài trăm request). Rủi ro chính là API public bị lạm dụng, nên lab bật throttling và reserved concurrency.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Vòng đời gồm 3 pha: INIT (nạp code, khởi tạo runtime, chạy mã bên ngoài handler) -> INVOKE (thực thi handler xử lý request) -> SHUTDOWN (dọn dẹp tài nguyên).
   Khởi tạo Database/SDK bên ngoài handler giúp tận dụng pha INIT chỉ chạy một lần duy nhất trong quá trình Cold Start. Các request tiếp theo (Warm Start) sẽ tái sử dụng lại các kết nối đã mở sẵn mà không mất công đàm phán SSL hay thiết lập kết nối TCP mới, giúp giảm độ trễ từ hàng trăm mili-giây xuống chỉ còn vài mili-giây.
2. So sánh:
   - **Đồng bộ (Synchronous):** Caller chờ kết quả trực tiếp. Nếu hàm lỗi, Lambda không retry; việc xử lý lỗi hoàn toàn do Caller tự chịu trách nhiệm.
   - **Bất đồng bộ (Asynchronous):** Caller nhận `202 Accepted` ngay và không chờ. Event được đưa vào hàng đợi nội bộ. Nếu hàm lỗi, Lambda sẽ **tự động thử lại 2 lần** với cơ chế giãn cách thời gian. Nếu sau 3 lần vẫn lỗi, event được chuyển vào Dead Letter Queue (SQS/SNS) nếu có cấu hình.
3. Phân biệt:
   - **Reserved Concurrency:** Giới hạn và dành riêng một mức tương tranh cố định cho hàm. Dùng làm "cầu dao an toàn" để bảo vệ cơ sở dữ liệu hạ nguồn (như RDS) không bị quá tải kết nối khi lưu lượng tăng đột biến.
   - **Provisioned Concurrency:** Giữ ấm sẵn một số lượng container vi mô đã nạp sẵn code và runtime trong RAM để phục vụ request tức thời, triệt tiêu 100% độ trễ Cold Start cho các hệ thống thanh toán hoặc ứng dụng tài chính nhạy cảm về độ trễ.
4. Ba lý do:
   - **Chi phí rẻ hơn tới 70%:** Chỉ $1.00 cho mỗi triệu request (so với $3.50 của REST API).
   - **Độ trễ thấp hơn:** Kiến trúc tinh gọn loại bỏ các lớp chuyển đổi phức tạp, giúp giảm thời gian phản hồi cho ứng dụng di động/web.
   - **Xác thực JWT đơn giản:** Hỗ trợ trực tiếp JSON Web Token (JWT) và các nhà cung cấp OIDC (như Cognito, Auth0) mà không cần phải viết thêm một hàm Custom Lambda Authorizer tốn kém.
   - Ngược lại, chọn REST API khi cần usage plan/API key cho đối tác, cache, request validation, private endpoint hoặc gắn WAF trực tiếp.
5. HTTP body là chuỗi byte, nên API Gateway cần `body` đã được tuần tự hóa (string, hoặc base64 kèm `isBase64Encoded`). Hậu quả khi vi phạm phụ thuộc loại API: với REST API/format 1.0, response sai cấu trúc bị coi là lỗi và client nhận `502 Bad Gateway`. Với HTTP API format 2.0, nếu function trả JSON không có `statusCode`, API Gateway tự suy ra `200` và dùng cả object làm body, nên lỗi có thể bị che đi; vì vậy luôn trả đủ `statusCode`, `headers`, `body` dạng string.

</details>


---

## Bổ sung — Xử lý ảnh thật và lỗi từng phần SQS

[Mini-lab xử lý ảnh](../examples/image-processor/README.md) cung cấp Lambda code, Dockerfile, CloudFormation và tests. Ảnh versioned trong uploads/ được resize tối đa 512×512, giữ tỉ lệ; output processed/ theo hash key+version. Duplicate event ghi cùng kết quả; event cũ không ghi đè kết quả version mới. Không xử lý lại processed/ để tránh vòng lặp.

S3 async invocation: exception làm cả invocation lỗi; cấu hình retry/failure destination theo Lambda. SQS event source mapping: handler trả `batchItemFailures` và phải bật `ReportBatchItemFailures`; message thành công không cần nhận lại cùng batch. Code mẫu dùng standard queue; FIFO cần dừng sau lỗi đầu và trả cả message chưa xử lý để giữ ordering. [AWS partial batch responses](https://docs.aws.amazon.com/lambda/latest/dg/services-sqs-errorhandling.html), đối chiếu 2026-11-01.

EventBridge dùng event envelope riêng (`source`, `detail-type`, `detail`); không đưa envelope đó trực tiếp vào handler đòi S3 `Records`. Khi chọn EventBridge làm nguồn, phải có adapter và negative test schema. Scheduler trong dự án gọi Artisan qua ECS, không cùng cơ chế S3 async retry.

Bài tập: message A xử lý xong, B lỗi, C xử lý xong trên standard queue; trả ID nào? Đáp án: chỉ B. Nếu handler ném exception ra ngoài, cả batch có thể bị retry; idempotency vẫn cần ngay cả khi dùng partial response.
