# Bài 11 — Hàng đợi, Phân phối Sự kiện và Luồng Công việc: Amazon SQS, SNS, EventBridge và AWS Step Functions

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 6–8 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Queue, Pub/Sub, SQS chi tiết, SNS Fan-out và EventBridge). Buổi 2 gồm phần 4–7 (AWS Step Functions, Các mẫu thiết kế hệ thống phân tán và [lab thực hành](../labs/11-messaging-and-workflows/README.md)).
> **Kế hoạch gốc:** [plans/11-messaging-and-workflows.md](../plans/11-messaging-and-workflows.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Phân biệt rõ vai trò và lựa chọn chuẩn xác giữa 4 dịch vụ cốt lõi: **Amazon SQS** (Message Queue), **Amazon SNS** (Pub/Sub Topic), **Amazon EventBridge** (Event Bus định tuyến sự kiện) và **AWS Step Functions** (Orchestration luồng công việc).
2. Làm chủ **Amazon SQS**: phân biệt Standard vs FIFO Queue, cơ chế **Visibility Timeout**, kỹ thuật **Long Polling** giảm chi phí, quản lý thông điệp độc hại với **Dead-Letter Queue (DLQ)** và quy trình **Redrive to Source**.
3. Xây dựng mô hình mở rộng **SNS Fan-out** kết hợp lọc thông điệp (**Subscription Filter Policies**); làm chủ kiến trúc hướng sự kiện (**Event-driven Architecture**) với **Amazon EventBridge**: Bus, Rules, Event Patterns, Targets và cơ chế Archive & Replay.
4. Thiết kế quy trình nghiệp vụ phức tạp với **AWS Step Functions**: phân biệt **Standard Workflows** và **Express Workflows**, làm chủ các trạng thái (Task, Choice, Parallel, Map, Pass, Fail), cấu hình xử lý lỗi với `Retry` và `Catch`, quản lý thời gian chờ với `TimeoutSeconds` và `HeartbeatSeconds`.
5. Nắm vững và áp dụng các mẫu thiết kế phân tán nâng cao: **Idempotency** (Tính lũy kế), đảm bảo thứ tự (**Message Ordering** với FIFO), **Transactional Outbox Pattern**, và mô hình bồi hoàn phân tán (**Saga Pattern / Compensation**).

---

## Phần 1 — Lựa chọn Dịch vụ: Queue, Pub/Sub, Event Routing hay Workflow Orchestration? (45 phút)

Trong kiến trúc nguyên khối (Monolith), các module giao tiếp thông qua lời gọi hàm trong bộ nhớ (In-memory function calls). Khi phân rã thành vi dịch vụ (Microservices), việc gọi trực tiếp qua HTTP/REST đồng bộ (**Synchronous**) biến hệ thống thành một chuỗi phụ thuộc giòn gãy (**Brittle Tight Coupling**): nếu dịch vụ thanh toán chậm hoặc sập, API tạo đơn hàng sẽ lập tức chết theo.

Để giải quyết vấn đề này, AWS cung cấp 4 công cụ bất đồng bộ (**Asynchronous**) với mục đích chuyên biệt:

```text
So sánh 4 dịch vụ Messaging và Workflow của AWS:
┌──────────────────┬─────────────────┬─────────────────┬──────────────────────┐
│ Amazon SQS       │ Amazon SNS      │ Amazon          │ AWS Step Functions   │
│                  │                 │ EventBridge     │                      │
├──────────────────┼─────────────────┼─────────────────┼──────────────────────┤
│ Message Queue    │ Pub/Sub Topic   │ Event Bus       │ Workflow             │
│ (Hàng đợi)       │ (Phát tán)      │ (Định tuyến)    │ (Điều phối luồng)    │
├──────────────────┼─────────────────┼─────────────────┼──────────────────────┤
│ Mô hình: Pull    │ Mô hình: Push   │ Mô hình: Push   │ Mô hình: State       │
│ Consumer chủ động│ Topic đẩy tức   │ Event Bus đẩy   │ Machine điều khiển   │
│ kéo message về   │ thì tới mọi     │ tới các targets │ các bước theo sơ đồ  │
│ xử lý            │ Subscribers     │ khớp Rule       │ trạng thái           │
├──────────────────┼─────────────────┼─────────────────┼──────────────────────┤
│ 1-to-1           │ 1-to-Many       │ Many-to-Many    │ Quy trình đa bước    │
│ Chỉ 1 consumer   │ Một thông báo   │ Nhận sự kiện từ │ Điều phối chuỗi tác  │
│ xử lý thành công │ nhân bản tới    │ AWS, SaaS, App; │ vụ có trạng thái     │
│ mỗi message      │ nhiều queues    │ lọc theo JSON   │ (Stateful) & Saga    │
├──────────────────┼─────────────────┼─────────────────┼──────────────────────┤
│ Lưu trữ message  │ Không lưu trữ   │ Tùy chọn        │ Lưu lịch sử thực thi │
│ tối đa 14 ngày   │ (Nếu không có   │ Archive &       │ (Standard: 90 ngày;  │
│ chờ tiêu thụ     │ subscriber =>   │ Replay          │ Express: đẩy log     │
│                  │ mất dữ liệu)    │                 │ CloudWatch)          │
└──────────────────┴─────────────────┴─────────────────┴──────────────────────┘
```

### Bảng quyết định kiến trúc: Chọn dịch vụ nào?

| Yêu cầu nghiệp vụ | Dịch vụ đề xuất | Lý do kiến trúc |
|---|---|---|
| Cần đệm tải (buffering), điều hòa tốc độ (rate leveling) để bảo vệ database phía sau | **Amazon SQS** | SQS lưu trữ thông điệp an toàn, worker tiêu thụ theo năng lực xử lý (pull model), không làm sập downstream. |
| Một sự kiện xảy ra (ví dụ: `OrderCreated`) cần thông báo đồng thời cho 3 hệ thống: Kho hàng, Email, và Phân tích dữ liệu | **Amazon SNS + SQS (Fan-out)** | SNS nhân bản message tức thì tới 3 queue SQS riêng biệt; mỗi nhóm nghiệp vụ xử lý độc lập không ảnh hưởng nhau. |
| Định tuyến sự kiện phức tạp dựa vào nội dung JSON payload, tích hợp dịch vụ bên ngoài (SaaS như Stripe, Datadog, GitHub) hoặc sự kiện AWS (S3, EC2) | **Amazon EventBridge** | Bộ lọc schema JSON mạnh mẽ (content-based filtering), hỗ trợ Event Bus đa tài khoản và kết nối SaaS tự nhiên. |
| Quy trình nghiệp vụ gồm nhiều bước có điều kiện rẽ nhánh, thử lại (retry), chờ phê duyệt của con người hoặc cần rollback bồi hoàn (Saga) | **AWS Step Functions** | Trực quan hóa luồng nghiệp vụ, tự động quản lý trạng thái, timeout và xử lý ngoại lệ mà không phải viết mã spaghetti. |

---

## Phần 2 — Amazon SQS: Cơ chế Hoạt động, Visibility Timeout, FIFO và DLQ (55 phút)

Amazon Simple Queue Service (SQS) là một trong những dịch vụ điện toán đám mây đầu tiên của AWS (ra mắt năm 2004), cung cấp hàng đợi thông điệp có độ bền bỉ và khả năng mở rộng gần như vô hạn.

### 1. Chu kỳ sống của một Message trong SQS

```text
Vòng đời Thông điệp trong Amazon SQS:
  Producer               SQS Queue                         Consumer
     │                       │                                │
     │── 1. SendMessage ────>│                                │
     │                       │ (Message ở trạng thái Visible) │
     │                       │                                │
     │                       │<── 2. ReceiveMessage ──────────│
     │                       │ [Bắt đầu Visibility Timeout]   │
     │                       │ (Message chuyển sang In-Flight)│── Xử lý nghiệp vụ...
     │                       │                                │
     │                       │<── 3. DeleteMessage ───────────│── Xử lý thành công!
     │                       │ (Message bị xóa vĩnh viễn)     │
```

- **Bước 1 — SendMessage:** Producer gửi thông điệp vào queue. Thông điệp được nhân bản trên nhiều máy chủ của AWS Availability Zones để đảm bảo độ bền. Kích thước tối đa của một message SQS là **1 MiB** (tăng từ 256 KB trong năm 2025; SNS và EventBridge có giới hạn riêng, thường 256 KB). Payload lớn hơn: lưu trên S3 và gửi tham chiếu (SQS Extended Client Library làm việc này). Luôn đối chiếu giới hạn hiện hành trước khi thiết kế schema.
- **Bước 2 — ReceiveMessage & Visibility Timeout:** Khi một Consumer kéo message về, message **không bị xóa ngay**. Thay vào đó, SQS khởi động đồng hồ đếm ngược gọi là **Visibility Timeout** (mặc định 30 giây, tối đa 12 giờ). Trong thời gian này, message chuyển sang trạng thái ẩn (**In-Flight**) đối với tất cả các consumer khác để tránh việc hai worker cùng xử lý một tác vụ.
- **Bước 3 — DeleteMessage:** Sau khi consumer hoàn thành tác vụ (ví dụ ghi vào DB), nó **bắt buộc phải gửi lệnh `DeleteMessage`** kèm theo mã `ReceiptHandle`. Nếu worker bị sập (crash) hoặc quá thời hạn Visibility Timeout mà chưa xóa, message sẽ tự động xuất hiện trở lại trong queue (**Visible**) để worker khác kéo về thử lại.

> [!WARNING]
> **Điểm dễ hiểu sai:** Visibility Timeout **không phải là khóa phân tán (Distributed Lock)**. Nếu worker mất 45 giây để xử lý nhưng Visibility Timeout chỉ đặt là 30 giây, SQS sẽ giải phóng message cho một worker thứ hai nhận. Kết quả là hai worker sẽ xử lý trùng lặp cùng một nghiệp vụ!
> **Giải pháp:** Nếu tác vụ kéo dài hơn dự kiến, worker phải định kỳ gọi API `ChangeMessageVisibility` để gia hạn thêm thời gian trước khi hết hạn.

### 2. Kỹ thuật Long Polling vs Short Polling
- **Short Polling (`WaitTimeSeconds = 0`):** SQS chỉ quét một tập con các máy chủ lưu trữ và trả về ngay kết quả, ngay cả khi queue đang rỗng. Điều này dẫn đến hàng triệu request rỗng (Empty Receive), làm phát sinh chi phí API calls không cần thiết.
- **Long Polling (`WaitTimeSeconds = 1–20s`):** SQS giữ kết nối mở tối đa 20 giây và chỉ phản hồi ngay khi có thông điệp xuất hiện. Long Polling giảm mạnh số request rỗng (mỗi request rỗng vẫn tính phí) và giảm độ trễ nhận thông điệp.

### 3. SQS Standard vs SQS FIFO

```text
Bảng so sánh SQS Standard và SQS FIFO:
┌─────────────────────────┬────────────────────────────┬────────────────────────────┐
│ Đặc tính                │ SQS Standard               │ SQS FIFO (.fifo)           │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Thứ tự (Ordering)       │ Cố gắng tối đa (Best-effort│ Đảm bảo thứ tự chính xác   │
│                         │ có thể sai thứ tự gửi)     │ Tuyệt đối (First-In-First) │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Số lần gửi (Delivery)   │ Ít nhất một lần (At-least- │ Chống trùng khi gửi trong  │
│                         │ once delivery - có trùng)  │ cửa sổ 5 phút (dedup ID)   │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Thông lượng             │ Gần như không giới hạn     │ 300 msg/s (Mặc định)       │
│ (Throughput)            │ (Hàng trăm nghìn msg/s)    │ Lên tới 3,000–70,000 msg/s │
│                         │                            │ với High-throughput mode   │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Định danh bắt buộc      │ Không                      │ MessageGroupId &           │
│                         │                            │ MessageDeduplicationId     │
└─────────────────────────┴────────────────────────────┴────────────────────────────┘
```

Trong SQS FIFO:
- **`MessageGroupId`:** Đảm bảo thứ tự nghiêm ngặt cho từng nhóm dữ liệu riêng biệt. Ví dụ, đặt `MessageGroupId = customer_id` sẽ đảm bảo các đơn hàng của cùng một khách hàng được xử lý tuần tự, trong khi các khách hàng khác nhau vẫn có thể được xử lý song song bởi nhiều worker.
- **`MessageDeduplicationId`:** Ngăn chặn việc gửi trùng thông điệp trong khoảng thời gian chống trùng lặp 5 phút (5-minute deduplication interval).
- **FIFO không cho exactly-once end-to-end:** nếu worker ghi DB xong rồi chết trước khi delete, message vẫn được giao lại sau visibility timeout. Side effect vẫn phải idempotent.

### 4. Dead-Letter Queue (DLQ) và Redrive to Source
Khi một thông điệp có cấu trúc dữ liệu bị lỗi (Poison Pill Message) khiến worker liên tục ném ngoại lệ (crash), message đó sẽ hết hạn Visibility Timeout, xuất hiện lại, và khiến worker crash liên tục theo vòng lặp vô tận.

- **Cấu hình Redrive Policy:** Thiết lập `maxReceiveCount` (ví dụ: 3 lần). Khi message đã được nhận đủ 3 lần mà không bị xóa, ở **lần nhận tiếp theo** SQS chuyển nó sang **Dead-Letter Queue (DLQ)** thay vì trả cho consumer.
- **Retention của DLQ:** với Standard queue, thời điểm enqueue gốc được giữ khi chuyển sang DLQ, nên đặt retention của DLQ **dài hơn** queue nguồn. DLQ của FIFO queue phải là FIFO.
- **Giám sát & Xử lý:** Đội vận hành nhận cảnh báo CloudWatch Alarm khi số lượng message trong DLQ (`ApproximateNumberOfMessagesVisible`) > 0. Kỹ sư phân tích nguyên nhân lỗi, sửa bug mã nguồn của worker, sau đó sử dụng tính năng **SQS DLQ Redrive** để đẩy toàn bộ message tồn đọng từ DLQ quay trở lại hàng đợi chính (Source Queue) để xử lý hoàn tất mà không bị mất dữ liệu.

---

## Phần 3 — Amazon SNS và Amazon EventBridge: Fan-out Pattern và Định tuyến Sự kiện (50 phút)

Khi kiến trúc phát triển từ mô hình một-một sang mô hình một-nhiều, việc kết hợp SNS và EventBridge là chìa khóa để phân tách độc lập các miền nghiệp vụ (Domain Boundaries).

### 1. Mô hình SNS Fan-out kết hợp SQS

```text
Kiến trúc SNS Fan-out Pattern:
                          ┌────────────────────────┐
                          │   Order Service API    │
                          └───────────┬────────────┘
                                      │ 1. Publish sự kiện "OrderCreated"
                                      ▼
                          ┌────────────────────────┐
                          │   Amazon SNS Topic     │
                          │   (order-events-topic) │
                          └─────┬────────────┬─────┘
                                │            │
           ┌────────────────────┘            └────────────────────┐
           ▼                                                      ▼
┌──────────────────────┐                               ┌──────────────────────┐
│ SQS Queue Kho Hàng   │ [Filter: status=CREATED]      │ SQS Queue Thông Báo  │
│ (inventory-queue)    │                               │ (notification-queue) │
└──────────┬───────────┘                               └──────────┬───────────┘
           ▼                                                      ▼
┌──────────────────────┐                               ┌──────────────────────┐
│ Inventory Worker     │                               │ Email / SMS Lambda   │
└──────────────────────┘                               └──────────────────────┘
```

- **Lợi ích:** Service Order không cần biết ai đang quan tâm đến sự kiện `OrderCreated`. Khi tương lai có thêm service Tích điểm thưởng (Loyalty Service), kiến trúc sư chỉ việc tạo một SQS Queue mới và đăng ký (`Subscribe`) vào SNS Topic mà không cần sửa đổi bất kỳ dòng code nào của Order Service.
- **Subscription Filter Policy:** SNS cho phép khai báo bộ lọc JSON trên các thuộc tính của thông điệp (`MessageAttributes`). Ví dụ: Queue thông báo VIP chỉ nhận các message có `customer_type: ["VIP"]`, giúp giảm tải xử lý cho các worker không liên quan.

### 2. Amazon EventBridge: Trái tim của Kiến trúc Hướng Sự kiện (EDA)
Trong khi SNS tập trung vào phát tán khối lượng lớn với độ trễ tính bằng mili-giây, **Amazon EventBridge** là một Event Bus thông minh:

```text
Cấu trúc Hoạt động của Amazon EventBridge:
┌────────────────────────┐
│ Nguồn Sự kiện (Sources)│
│ - AWS Services (S3,EC2)│
│ - Custom Apps (Orders) │ ────> ┌───────────────────────────┐ ────> ┌──────────────────────────┐
│ - SaaS (Stripe, Datadog│       │ Amazon EventBridge Bus    │       │ Targets (Đích đến)       │
└────────────────────────┘       │ - Event Rules             │       │ - AWS Lambda Functions   │
                                 │ - JSON Pattern Filtering  │       │ - SQS Queues / SNS       │
                                 │ - Schema Registry         │       │ - Step Functions         │
                                 └───────────────────────────┘       │ - API Destinations (HTTP)│
                                                                     └──────────────────────────┘
```

- **Event Pattern Matching:** Bộ định tuyến của EventBridge so sánh trực tiếp payload JSON của sự kiện:
  ```json
  {
    "source": ["ecommerce.order"],
    "detail-type": ["OrderStateChange"],
    "detail": {
      "status": ["PAID"],
      "total_amount": [{ "numeric": [">=", 1000] }]
    }
  }
  ```
  Quy tắc trên chỉ kích hoạt target khi đơn hàng ở trạng thái `PAID` và có giá trị từ 1000 USD trở lên.
- **Archive & Replay:** EventBridge cho phép lưu trữ vĩnh viễn hoặc theo thời hạn toàn bộ sự kiện đã đi qua Bus. Khi hệ thống gặp sự cố mất dữ liệu, kỹ sư có thể kích hoạt tính năng **Replay** để phát lại toàn bộ sự kiện trong khoảng thời gian mong muốn nhằm khôi phục trạng thái hệ thống.

---

## Phần 4 — AWS Step Functions: Điều phối Luồng Nghiệp vụ và Quản lý Lỗi (50 phút)

Khi một quy trình gồm nhiều bước đòi hỏi sự phối hợp chặt chẽ giữa các vi dịch vụ (ví dụ: Thanh toán → Trừ kho → Xuất hóa đơn → Giao vận), việc xâu chuỗi thông qua message queues đơn thuần sẽ dẫn đến hiện tượng phân tán logic nghiệp vụ (**Choreography Blind Spot**), khiến việc theo dõi trạng thái đơn hàng trở nên cực kỳ khó khăn.

**AWS Step Functions** giải quyết bài toán này bằng mô hình **Orchestration**: Một máy trạng thái trực quan (**State Machine**) điều phối toàn bộ các bước thực thi.

```text
Sơ đồ Máy Trạng thái Step Functions Xử lý Đơn hàng:
              ┌───────────────────────────┐
              │   Start: Nhận Đơn Hàng    │
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
              │  Task: Kiểm Tra Tồn Kho   │
              └─────────────┬─────────────┘
                            ▼
              ┌───────────────────────────┐
       ┌───── │    Choice: Còn Hàng?      │ ─────┐
(Hết)  │      └───────────────────────────┘      │ (Còn)
       ▼                                         ▼
┌──────────────┐                       ┌───────────────────┐
│ Fail State:  │                       │ Task: Thu Tiền Thẻ│ ──[Lỗi Thẻ]──┐
│ Hủy Đơn Hàng │                       └─────────┬─────────┘              │
└──────────────┘                                 ▼                        │
                                       ┌───────────────────┐              ▼
                                       │ Task: Đóng Gói Vận│     ┌───────────────────┐
                                       │ Chuyển            │     │ Task Compensation:│
                                       └─────────┬─────────┘     │ Hoàn Lại Kho Hàng │
                                                 ▼               └─────────┬─────────┘
                                       ┌───────────────────┐               ▼
                                       │  Success State    │     ┌───────────────────┐
                                       └───────────────────┘     │    Fail State     │
                                                                 └───────────────────┘
```

### 1. Standard Workflows vs Express Workflows

| Tiêu chí | Standard Workflows | Express Workflows |
|---|---|---|
| **Thời lượng thực thi** | Tối đa **1 năm** | Tối đa **5 phút** |
| **Mô hình thực thi** | Workflow chạy đúng một lần (**exactly-once**); từng task vẫn có thể bị gọi lại nếu bạn cấu hình `Retry` | Async: **at-least-once**; Sync: **at-most-once** |
| **Tần suất kích hoạt** | Thấp hơn (quota StartExecution theo Region, cỡ hàng nghìn/giây) | Rất cao (cỡ 100,000 executions/giây) |
| **Lưu lịch sử trạng thái** | Lưu chi tiết từng bước trên Console tới **90 ngày** | Chỉ có nếu bật logging ra CloudWatch Logs |
| **Cách tính phí** | Tính theo số lần chuyển trạng thái (**State Transitions**) | Tính theo số lần thực thi và thời gian chạy (GB-giây) |
| **Trường hợp sử dụng** | Quy trình dài, phê duyệt thủ công của con người, xử lý đơn hàng giá trị cao, tài chính | Xử lý dữ liệu IoT tốc độ cao, biến đổi luồng Clickstream, API đồng bộ tốc độ cao |

### 2. Các State Types phổ biến trong Amazon States Language (ASL)
- **Task:** Thực thi công việc (gọi hàm Lambda, gửi SQS message, kích hoạt ECS Task hoặc gọi HTTP API thứ ba).
- **Choice:** Rẽ nhánh logic dựa trên giá trị của input JSON.
- **Wait:** Tạm dừng quy trình trong một khoảng thời gian cố định hoặc đến một mốc thời gian cụ thể.
- **Parallel:** Chạy song song nhiều nhánh logic độc lập cùng lúc.
- **Map:** Lặp qua một mảng dữ liệu và thực thi cùng một chuỗi tác vụ trên từng phần tử (hỗ trợ Distributed Map xử lý hàng triệu phần tử).
- **Pass / Fail / Succeed:** Chuyển đổi dữ liệu, đánh dấu quy trình thất bại hoặc thành công.

### 3. Xử lý Lỗi Chuyên sâu: `Retry` và `Catch`
Step Functions cho phép khai báo chính sách xử lý lỗi trực tiếp trong định nghĩa JSON mà không cần viết mã try/catch phức tạp trong ứng dụng:

```json
"ThuTienThe": {
  "Type": "Task",
  "Resource": "arn:aws:states:::lambda:invoke",
  "Parameters": {
    "FunctionName": "ProcessPaymentFunction"
  },
  "Retry": [
    {
      "ErrorEquals": ["PaymentGatewayNetworkTimeoutException"],
      "IntervalSeconds": 2,
      "MaxAttempts": 3,
      "BackoffRate": 2.0
    }
  ],
  "Catch": [
    {
      "ErrorEquals": ["InsufficientFundsException"],
      "ResultPath": "$.error",
      "Next": "HoanLaiKhoHang"
    },
    {
      "ErrorEquals": ["States.ALL"],
      "ResultPath": "$.error",
      "Next": "HoanLaiKhoHang"
    }
  ],
  "Next": "DongGoiVanChuyen"
}
```

- **Quy tắc ASL:** `States.ALL` phải đứng **một mình** trong `ErrorEquals` và nằm ở retrier/catcher **cuối cùng**. Không có `ResultPath` trong catcher, input gốc bị thay bằng thông tin lỗi.
- **Retry khác nhau theo loại integration:** với Lambda (`lambda:invoke`) nên retry các lỗi dịch vụ tạm thời như `Lambda.ServiceException`, `Lambda.TooManyRequestsException`; với pattern `.sync`/`.waitForTaskToken`, retry chạy lại cả job. Mỗi lần retry là một lần gọi lại side effect.
- **`TimeoutSeconds` vs `HeartbeatSeconds`:**
  - `TimeoutSeconds`: Thời gian tối đa cho phép một Task chạy từ khi bắt đầu đến khi kết thúc.
  - `HeartbeatSeconds`: Nếu worker thực thi một tác vụ dài không gửi tín hiệu "nhịp tim" (heartbeat) về Step Functions trong khoảng thời gian này, Step Functions sẽ coi worker đã bị treo hoặc chết và kích hoạt nhánh Catch ngay lập tức, thay vì phải đợi hết toàn bộ `TimeoutSeconds`.

---

## Phần 5 — Các Mẫu Thiết kế Hệ thống Phân tán Nâng cao (45 phút)

### 1. Tính Lũy kế (Idempotency) trong Xử lý Message
Trong các hệ thống phân tán, mạng chập chờn hoặc timeout có thể khiến producer gửi lại message hoặc consumer nhận trùng message (At-least-once delivery). 

> [!IMPORTANT]
> **Quy tắc vàng:** Không bao giờ phụ thuộc vào SQS FIFO để đạt được tính chính xác tuyệt đối. Consumer **bắt buộc phải được thiết kế Idempotent** (thực hiện xử lý cùng một thông điệp nhiều lần nhưng kết quả trạng thái cuối cùng không thay đổi).

- **Cơ chế Idempotency Key:**
  Mỗi giao dịch sinh ra một `idempotency_key` (ví dụ: mã băm từ `order_id + customer_id`). Khi consumer nhận message:
  1. Kiểm tra trong DynamoDB xem `idempotency_key` đã tồn tại chưa bằng điều kiện `attribute_not_exists(idempotency_key)`.
  2. Nếu đã tồn tại: Bỏ qua việc trừ tiền thẻ, trả về ngay kết quả đã xử lý trước đó.
  3. Nếu chưa tồn tại: Thực thi nghiệp vụ và ghi nhận key vào bảng lưu trữ.

### 2. Transactional Outbox Pattern
Một lỗi phổ biến là: Ứng dụng ghi đơn hàng vào Database thành công, nhưng ngay trước khi kịp gọi `sns.publish()`, máy chủ bị mất điện đột ngột. Kết quả là đơn hàng được tạo trong DB nhưng không có bất kỳ thông báo nào được phát ra ngoài!

```text
Transactional Outbox Pattern:
┌────────────────────────────────────────────────────────┐
│ Máy chủ Đơn Hàng (Order Service)                       │
│                                                        │
│  Giao dịch Database Nguyên tử (Single ACID Transaction)│
│  ┌───────────────────────┐   ┌───────────────────────┐ │
│  │ Bảng ORDERS           │   │ Bảng OUTBOX           │ │
│  │ (Ghi bản ghi đơn hàng)│ + │ (Ghi sự kiện cần phát)│ │
│  └───────────────────────┘   └───────────┬───────────┘ │
└──────────────────────────────────────────┼─────────────┘
                                           │
                                           │ CDC (Change Data Capture)
                                           │ hoặc Outbox Poller
                                           ▼
                               ┌───────────────────────┐
                               │ Amazon EventBridge    │
                               │ hoặc Amazon SQS       │
                               └───────────────────────┘
```

Bằng cách ghi sự kiện vào bảng `OUTBOX` trong cùng transaction với bản ghi nghiệp vụ, hệ thống đảm bảo **có đơn thì có sự kiện** (và ngược lại). Một quy trình nền (DynamoDB Streams + Lambda, Debezium CDC hoặc poller) đọc Outbox và publish ra message bus. Relay này là **at-least-once** (có thể publish lại khi sập giữa chừng), nên consumer vẫn phải idempotent.

**Khi nào cần outbox?** Khi một thao tác phải vừa đổi dữ liệu vừa phát sự kiện, và việc mất sự kiện gây sai nghiệp vụ (ví dụ kho không biết đơn đã thanh toán). Nếu sự kiện chỉ để thống kê, chấp nhận mất thỉnh thoảng thì không cần độ phức tạp này.

### 3. Mô hình Bồi hoàn Phân tán (Saga Compensation Pattern)
Trong kiến trúc vi dịch vụ, việc sử dụng Two-Phase Commit (2PC) phân tán gây khóa dữ liệu (blocking locks) và làm suy giảm nghiêm trọng thông lượng của hệ thống. Thay vào đó, chúng ta áp dụng **Saga Pattern**:
- Mỗi bước nghiệp vụ là một giao dịch cục bộ độc lập.
- Nếu một bước ở giữa gặp thất bại không thể phục hồi (ví dụ: Trừ kho thành công nhưng Trừ tiền thẻ thất bại do tài khoản không đủ số dư), hệ thống sẽ không thực hiện "Rollback Database" như trong RDBMS nguyên khối.
- Thay vào đó, Step Functions sẽ kích hoạt một chuỗi các **Hành động Bồi hoàn (Compensating Transactions)** chạy ngược lại: Gọi API hoàn lại kho số lượng hàng vừa giữ để đưa hệ thống về trạng thái nhất quán cuối cùng (**Eventual Consistency**).

---

## Phần 6 — Giới thiệu bài thực hành (Lab 11)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. SQS Standard queue + DLQ (`maxReceiveCount = 3`), bảng DynamoDB làm side effect.
2. Worker Python idempotent: gửi message trùng, và cho worker chết **sau** ghi DB **trước** delete; chứng minh không tạo đơn trùng.
3. Poison message (schema cũ) vào DLQ ở lần nhận thứ 4; đọc DLQ để tìm nguyên nhân, sửa worker rồi redrive có giới hạn tốc độ.
4. Mini-lab EventBridge: custom bus, rule lọc theo nội dung, `test-event-pattern`, target SQS có queue policy.
5. Mini-lab Step Functions Standard: `Retry` chỉ cho lỗi tạm thời, `Catch` → bồi hoàn, task gửi SQS khi thành công.
6. Cleanup: disable rule trước, kiểm tra in-flight, xóa queue/bus/state machine/bảng/role.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/11-messaging-and-workflows/README.md](../labs/11-messaging-and-workflows/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Message liên tục xuất hiện lại trong SQS Queue gây xử lý trùng lặp
- **Nguyên nhân 1:** Thời gian xử lý nghiệp vụ của Consumer dài hơn giá trị `VisibilityTimeout` của queue. Trước khi worker kịp gửi lệnh `DeleteMessage`, SQS đã coi worker bị chết và giải phóng message cho worker khác nhận.
- **Nguyên nhân 2:** Worker xử lý nghiệp vụ thành công nhưng gặp lỗi ngoại lệ mạng khi gọi API `DeleteMessage`, khiến SQS không nhận được lệnh xóa.
- **Cách khắc phục:** Tăng `VisibilityTimeout` lớn hơn thời gian xử lý tối đa của worker (ví dụ gấp 3-5 lần p99 latency), hoặc gọi `ChangeMessageVisibility` để gia hạn động. Thiết kế logic nghiệp vụ của worker luôn có tính Idempotent.

### 2. Số lượng Message trong Dead-Letter Queue (DLQ) tăng vọt
- **Nguyên nhân:** Một lỗi phát hành code mới (bug logic hoặc sai schema JSON) khiến tất cả các worker đều ném ngoại lệ khi phân tích payload. Sau khi thử lại đủ số lần `maxReceiveCount`, tất cả thông điệp đều bị tống vào DLQ.
- **Cách khắc phục:** Thiết lập CloudWatch Alarm theo dõi metric `ApproximateNumberOfMessagesVisible` của DLQ. Khi có sự cố, giữ nguyên message trong DLQ (SQS lưu trữ tối đa 14 ngày), rollback phiên bản code của worker, kiểm thử kỹ lưỡng, sau đó dùng tính năng **StartMessageMoveTask (DLQ Redrive)** để đẩy message về queue chính.

### 3. Step Functions liên tục lặp lại các tác vụ có tác dụng phụ (Side Effects)
- **Nguyên nhân:** Cấu hình khối `Retry` của một Task bắt các lỗi chung (`States.ALL` hoặc `States.TaskFailed`), khiến Step Functions tự động gọi lại hàm thực thi ngay cả khi tác vụ đã thực hiện xong một nửa công việc (ví dụ đã gọi API ngân hàng trừ tiền nhưng bị timeout khi nhận phản hồi).
- **Cách khắc phục:** Chỉ cấu hình `Retry` cho các mã lỗi tạm thời (Transient Errors) như network timeout hoặc HTTP 429/503. Với các lỗi nghiệp vụ (như `InsufficientFunds`), phải định tuyến sang khối `Catch` để chuyển sang bước bồi hoàn. Luôn truyền `idempotency_key` vào các tác vụ có tác dụng phụ.

### 4. EventBridge Rule không định tuyến sự kiện tới Target
- **Nguyên nhân 1:** Khối `EventPattern` trong Rule khai báo không khớp chính xác với payload JSON của sự kiện (lưu ý: EventBridge phân biệt chữ hoa/thường - Case sensitive).
- **Nguyên nhân 2:** Target (ví dụ SQS Queue hoặc CloudWatch Log Group) thiếu **Resource-based Policy** cho phép dịch vụ `events.amazonaws.com` quyền gửi thông điệp hoặc ghi log.
- **Cách khắc phục:** Dùng công cụ **Test Event Pattern** trong EventBridge Console để kiểm tra tính hợp lệ của JSON pattern trước khi triển khai; kiểm tra metric `FailedInvocations` trên CloudWatch để xác định lỗi phân quyền.

---

## Câu hỏi tự kiểm tra

1. Phân tích sự khác biệt về cơ chế truyền tải (Push vs Pull) giữa Amazon SNS và Amazon SQS. Tại sao các kiến trúc sư thường kết hợp cả hai dịch vụ này trong mẫu hình Fan-out?
2. Giả sử một hệ thống thanh toán cần xử lý 50,000 giao dịch mỗi giây. Bạn có thể sử dụng hàng đợi SQS FIFO mặc định không? Nếu không, bạn cần điều chỉnh kiến trúc như thế nào?
3. Tại sao việc đặt `maxReceiveCount` cho Dead-Letter Queue là yếu tố sống còn để ngăn chặn hiện tượng nghẽn hàng đợi bởi thông điệp độc hại (Poison Pill)?
4. So sánh hai loại luồng công việc Standard Workflows và Express Workflows trong AWS Step Functions về mặt độ bền, tần suất kích hoạt, thời gian chạy và cơ chế tính phí?
5. Trình bày cách thức hoạt động của Transactional Outbox Pattern. Mẫu hình này giải quyết triệt để vấn đề mất tính nhất quán giữa cơ sở dữ liệu và hệ thống thông điệp như thế nào?
6. Worker ghi đơn vào DB thành công rồi chết trước khi gọi `DeleteMessage`. Chuyện gì xảy ra với message, và thiết kế nào giúp không tạo đơn trùng?

---

## Tài liệu tham khảo chính thức

- [Amazon SQS Developer Guide](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/)
- [Amazon SNS Developer Guide](https://docs.aws.amazon.com/sns/latest/dg/)
- [Amazon EventBridge User Guide](https://docs.aws.amazon.com/eventbridge/latest/userguide/)
- [AWS Step Functions Developer Guide](https://docs.aws.amazon.com/step-functions/latest/dg/)
- [AWS Prescriptive Guidance: Transactional Outbox Pattern](https://docs.aws.amazon.com/prescriptive-guidance/latest/cloud-design-patterns/transactional-outbox.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** thời điểm chuyển DLQ và retention của DLQ; `StartMessageMoveTask`; kích thước message SQS 1 MiB; ngữ nghĩa thực thi Standard/Express; quy tắc `States.ALL` trong ASL; EventBridge pattern phân biệt hoa thường và queue policy cho target.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output là minh họa.
- **Chi phí:** lab dưới $0.01 (vài trăm request SQS, vài event, 3 execution Standard), không có phí theo giờ.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. **Push vs Pull và Fan-out:**
   - **SNS (Push):** Ngay khi publisher đẩy message, SNS sẽ chủ động bắn ngay tới tất cả các subscriber. Nếu subscriber là máy chủ HTTP và đang bị quá tải, việc bị push dồn dập sẽ làm sập máy chủ.
   - **SQS (Pull):** Consumer chủ động thăm dò và kéo message về khi có năng lực xử lý rảnh rỗi.
   - **Mẫu Fan-out:** Kết hợp SNS (để nhân bản sự kiện tới nhiều hệ sinh thái độc lập) và SQS (để làm bộ đệm điều hòa tải trước từng worker). Nhờ đó vừa đạt được tính mở rộng một-nhiều vừa bảo vệ an toàn cho các dịch vụ phía sau.
2. **Hạn ngạch SQS FIFO:**
   - Mặc định, SQS FIFO chỉ hỗ trợ tối đa 300 giao dịch/giây (hoặc 3,000 msg/s nếu gom nhóm batch 10). Mức này **hoàn toàn không đáp ứng** được yêu cầu 50,000 giao dịch/giây!
   - Giải pháp:
     - Bật tính năng **High-Throughput FIFO Queue** kết hợp chia nhỏ các giá trị `MessageGroupId` khác nhau (đáp ứng từ 3,000 đến 70,000 msg/s).
     - Hoặc nếu nghiệp vụ có thể thiết kế tính lũy kế (Idempotency) mạnh mẽ, chuyển sang dùng **SQS Standard Queue** (thông lượng gần như vô hạn) kết hợp kiểm tra trùng lặp tại tầng ứng dụng/DynamoDB.
3. **Ý nghĩa của `maxReceiveCount`:**
   - Khi một message bị lỗi định dạng (Poison Pill), worker đọc vào sẽ bị crash. SQS sẽ nhả message ra sau Visibility Timeout, worker khác lại đọc và tiếp tục crash. Vòng lặp này tiêu tốn 100% CPU của worker fleet và làm tắc nghẽn toàn bộ hàng đợi.
   - Đặt `maxReceiveCount` (ví dụ 3 lần) đảm bảo sau 3 lần thử thất bại, message độc hại sẽ bị cách ly ngay lập tức vào Dead-Letter Queue (DLQ), giải phóng hàng đợi để các thông điệp hợp lệ tiếp theo được xử lý thông suốt.
4. **So sánh Standard vs Express Workflows:**
   - **Standard Workflows:** Chạy tối đa 1 năm, workflow exactly-once (task vẫn có thể bị gọi lại do `Retry`), lưu lịch sử trực quan 90 ngày, tính phí theo từng lần chuyển trạng thái. Phù hợp cho quy trình dài, phê duyệt của con người, tài chính.
   - **Express Workflows:** Chạy tối đa 5 phút, async at-least-once / sync at-most-once, thông lượng rất cao, lịch sử chỉ có khi bật CloudWatch Logs, tính phí theo số execution và thời gian chạy. Phù hợp cho xử lý sự kiện khối lượng lớn, backend API ngắn; mọi bước phải idempotent.
5. **Transactional Outbox Pattern:**
   - Thay vì ghi vào DB rồi gọi network API gửi message riêng lẻ (rủi ro mất đồng bộ khi sập nguồn ở giữa), ứng dụng mở một transaction cơ sở dữ liệu cục bộ duy nhất: vừa ghi dữ liệu vào bảng nghiệp vụ (`ORDERS`), vừa chèn một bản ghi sự kiện vào bảng `OUTBOX`.
   - Một tiến trình chuyên trách (CDC reader như DynamoDB Streams hoặc Poller) quét bảng Outbox và phát tán sự kiện vào Message Bus. Ngay cả khi máy chủ sập nguồn ở bất kỳ thời điểm nào, giao dịch DB hoặc hoàn thành cả hai hoặc rollback cả hai, nên không có đơn nào thiếu sự kiện. Relay có thể publish lặp lại, nên consumer vẫn phải idempotent.

6. Message không bị xóa nên quay lại queue sau visibility timeout và được giao lại (at-least-once). Nếu side effect là `PutItem` có `attribute_not_exists(idempotency_key)` (key do producer sinh một lần cho mỗi yêu cầu), lần xử lý thứ hai gặp `ConditionalCheckFailedException`, worker coi là đã xử lý và delete message. Lab 11 Bước 3 chứng minh điều này.

</details>

