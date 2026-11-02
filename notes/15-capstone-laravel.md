# Bài 15 — Dự án Laravel API + Vue xuyên suốt

> Đọc song song với bài 05–14. [Lab bảy mốc](../labs/15-capstone-laravel/README.md) · [Mã ứng dụng](../examples/laravel-order-app/README.md) · [Hạ tầng](../infra/laravel-order-app/README.md).
> Biên soạn/đối chiếu nguồn: 2026-11-01. Kiểm chứng local được ghi riêng; chưa triển khai AWS.

## Mục tiêu và đường học

Một đơn hàng đi qua cùng API, database, bucket và hàng đợi ở mọi mốc. Khi chuyển EC2 sang ECS, chỉ cách vận hành process thay đổi; hợp đồng API và dữ liệu không đổi. Hoàn thành bài nghĩa là bạn giải thích được lựa chọn, triển khai được, tìm được lỗi và khôi phục được bằng bằng chứng.

| Mốc | Đọc trước | Đầu ra | Thời lượng thêm dự kiến |
|---|---|---|---|
| 1. EC2 | 01–05 | Laravel/Nginx/PHP-FPM, reboot, log | 3–5 giờ |
| 2. RDS/S3 | 06–07 | Vue tạo đơn, upload; dữ liệu ngoài máy | 4–6 giờ |
| 3. HTTPS/ALB/ASG | 05–07 | Hai instance, mất một instance vẫn phục vụ | 3–4 giờ |
| 4. Cache/queue | 07, 11 | Session chung, báo cáo idempotent, lịch chạy | 4–6 giờ |
| 5. Quan sát/secret | 10 | Trace, alarm, test dev/prod | 4–6 giờ |
| 6. ECS/pipeline | 08, 12 | API/worker riêng, CI/CD và rollback | 5–8 giờ |
| 7. IaC/DR | 12–14 | Môi trường mới, restore, load test, ADR | 5–8 giờ |

Tổng phần dự án khoảng 28–43 giờ, chưa tính chờ quyền, domain và thời gian xử lý sự cố. Mini-lab Cognito, xử lý ảnh và triage finding thêm khoảng 4–8 giờ nếu chưa làm trong các bài tương ứng. Không cần giữ tài nguyên tính phí giữa các buổi: checkpoint là code, tham số và bằng chứng đã che thông tin.

## Kiến trúc cuối

```text
Vue ── Cognito authorization code + PKCE
 │ access token
 ▼
HTTPS ALB ── private app: Nginx → PHP-FPM/Laravel
                 │           │             │
                 │           │             └── S3 uploads/ (quarantine)
                 │           └── Redis cache/session
                 └── RDS orders + reports + outbox
                                      │ dispatch Laravel payload
                                      ▼
                                  SQS → worker → S3 reports/
                                   │ lỗi quá số lần nhận
                                   └── DLQ
Scheduler → ECS RunTask → artisan reports:tick → outbox → SQS
OpenTelemetry API/worker → collector → X-Ray; structured log → CloudWatch
S3 ảnh (mini-lab riêng) → Lambda → processed/ + queue lưu lỗi
```

`foundation.yaml` chứa mạng/dữ liệu/identity/repository, `ec2.yaml` chứa máy chủ native, `runtime.yaml` chứa container. Tách vòng đời giúp thay compute mà không mất database. Môi trường mới có stack foundation mới; không dùng secret/DB của môi trường khác.

## HTTP, process và state

Nginx phục vụ Vue và chuyển `/api` sang FastCGI. PHP-FPM quản lý PHP workers; mỗi worker có thể mở kết nối DB. Liveness `/up` không cần DB; readiness `/api/ready` kiểm tra DB. Nếu mọi target cùng mất DB, readiness cùng fail: health check không tự phục hồi database.

Mỗi task/instance có thể bị thay. Upload và báo cáo nằm ở S3, đơn hàng nằm ở RDS. API xác thực bằng JWT, nên không cần session để xác định người dùng; `/api/session` là bài tập cookie độc lập để thấy vì sao session local làm bộ đếm thay đổi giữa target. Redis session chứa state dùng chung nhưng không biến cookie thành access token.

`APP_KEY` phải giống giữa instance để giải mã cookie. Rotate key mà không có kế hoạch tương thích có thể làm mất session. Không tạo key mới ở mỗi lần boot. Secret được lấy lúc EC2 bootstrap hoặc ECS inject lúc khởi động; rotate secret phải restart/redeploy process để nhận giá trị mới.

## Transaction, queue và tính idempotent

Yêu cầu xuất báo cáo tạo `reports` và `outbox` trong cùng transaction. Sau commit, dispatcher gửi job; nếu gửi queue lỗi, outbox còn để thử lại. Nếu gửi thành công rồi chết trước khi xóa outbox, sẽ có job trùng.

Worker khóa row report, lấy snapshot đã lưu và ghi vào key `reports/{uuid}.json`. Crash sau S3 PUT nhưng trước commit DB được phục hồi bằng cách ghi lại cùng bytes vào cùng key. Với bucket versioning, vẫn có thể phát sinh thêm object version và chi phí; tiêu chí là một kết quả nghiệp vụ, không phải đúng một lần gọi S3. Không áp dụng lập luận này cho gửi email hay thu tiền nếu dịch vụ ngoài không hỗ trợ idempotency key.

Trong đường SQS, worker dùng `--tries=0`; redrive policy của SQS đưa poison job sang DLQ sau số lần nhận cấu hình. Nếu đặt Laravel tries hữu hạn, Laravel có thể đánh dấu failed và xóa message trước khi SQS đưa vào DLQ. Local database queue dùng `--tries=3`. Timeout job 60 giây phải ngắn hơn visibility 180 giây và thời gian chờ graceful stop 90 giây. Đây là thông số fixture, cần đo lại khi workload thay đổi. [Laravel queues](https://laravel.com/docs/12.x/queues).

Scheduler chạy Artisan qua ECS thay vì viết tay serialized Laravel job. Khóa lịch dựa trên `scheduled-time`, giữ nguyên qua retry; UUID của lần thực thi không thích hợp để chống trùng cùng một lịch. Scheduler DLQ ghi lỗi gọi target; RunTask được chấp nhận không đảm bảo command bên trong thành công. Cần xem exit code/log task và tuổi outbox để phát hiện lỗi ứng dụng. [Scheduler universal targets](https://docs.aws.amazon.com/scheduler/latest/UserGuide/managing-targets-universal.html).

## Upload và quyền dữ liệu

Backend xác thực owner, tự chọn key ngẫu nhiên và ký PUT ngắn hạn. CORS giúp browser tuân thủ origin nhưng không ngăn một HTTP client có URL hợp lệ. Giới hạn bytes trong request cấp URL chỉ là khai báo; presigned PUT không tự thực thi giới hạn kích thước do ứng dụng đặt.

`verify` kiểm tra bytes, loại ảnh và kích thước pixel thực. URL vẫn dùng lại được cho tới khi hết hạn; vì vậy không công khai object gốc sau một lần verify. Pipeline ảnh dùng version ID và output riêng theo version. CloudFront trong foundation chỉ đọc `public/*`; `uploads/`, `processed/`, `reports/` mặc định không public. Muốn xuất bản ảnh, cần bước phê duyệt/copy phiên bản đã xử lý sang `public/`; fixture không tự công khai dữ liệu.

## Xác thực và phân quyền

Cognito user pool quản lý người dùng và phát token; identity pool cấp AWS credentials cho client theo cấu hình, không cần cho luồng presigned upload này. Vue dùng authorization code + PKCE, không giữ client secret. Access token phải được kiểm chữ ký, issuer, expiry, `token_use`, client ID và scope; sau đó API vẫn phải kiểm tra owner của từng đơn/báo cáo. ID token phục vụ thông tin đăng nhập, không được dùng thay access token của API. [Kiểm chứng JWT Cognito](https://docs.aws.amazon.com/cognito/latest/developerguide/amazon-cognito-user-pools-using-tokens-verifying-a-jwt.html).

Middleware cache JWKS 5 phút. Một key mới chưa có trong cache có thể khiến request bị từ chối tới khi cache hết hạn; fixture chọn fail-closed. Verify offline không bảo đảm phát hiện ngay token đã revoke; cần TTL phù hợp và thiết kế bổ sung nếu nghiệp vụ đòi thu hồi tức thì. Token local trong tests chỉ chứng minh logic kiểm tra, không chứng minh đã tích hợp Cognito thật.

## Tracing, metric và xử lý sự cố

Middleware tạo span HTTP; `Telemetry::run` tạo span DB/export/storage. Trace context được đưa vào outbox và serialized job để nối worker với request tạo báo cáo. Manual instrumentation chỉ bao phủ các điểm đã bọc; không được kết luận mọi query đã có span. SDK xuất OTLP HTTP tới collector cùng task; collector xuất sang X-Ray bằng task role. [OpenTelemetry PHP instrumentation](https://opentelemetry.io/docs/languages/php/instrumentation/).

Log JSON gồm request ID, status, latency và version; không ghi Authorization, token, URL ký sẵn hoặc payload đơn hàng. Khi gặp 500, đi từ alarm đến log theo request ID rồi trace liên quan. Khi latency tăng, kiểm tra SQL span, số PHP worker, DB connection và cache miss trước khi tăng instance.

## HA, hiệu năng và phục hồi

Hai app ở hai AZ giải quyết mất một compute instance. Cấu hình tiết kiệm vẫn dùng một NAT, một RDS Single-AZ và một cache node: mất AZ có thể gây downtime. Snapshot không phải failover; Multi-AZ không thay backup khi ứng dụng xóa dữ liệu hợp lệ. ADR phải ghi cả ba điểm này.

`/api/probe` là dependency drill trên PostgreSQL: timeout SQL, retry tối đa hai lần với jitter, khóa giới hạn concurrency và circuit mở 15 giây sau ba request thất bại. Khi hết thời gian mở, khóa chỉ cho một probe vào, đóng sau thành công hoặc mở lại sau thất bại. Đây là fixture nhỏ để quan sát, chưa phải thư viện circuit breaker cho production. HTTP 429/503 với Retry-After là tín hiệu backpressure; client không retry vô hạn.

RTO đo từ lúc công bố sự cố đến lúc ứng dụng dùng DB khôi phục vượt smoke test. RPO đo từ dữ liệu đã xác nhận trước sự cố đến điểm mới nhất có trong bản khôi phục. Ghi riêng thời gian provision DB, đổi cấu hình và kiểm tra nghiệp vụ. Không suy RTO chỉ từ thời gian API restore trả về.

## Bài tập quyết định và đáp án

1. Nếu worker chết sau PUT S3 nhưng trước commit DB, tiêu chí nào chứng minh không xuất báo cáo trùng?
2. Tăng API từ 2 lên 8 task, mỗi task 8 PHP worker: cần dự trù bao nhiêu kết nối, ngoài worker/migration?
3. ASG thay một máy thành công có đủ chứng minh chịu mất AZ không?
4. Pipeline smoke test fail sau migration: rollback image có sửa lại schema không?

<details><summary>Đáp án gợi ý</summary>

1. Cùng report ID, cùng key và cùng nội dung, một trạng thái hoàn tất trong DB. S3 có thể có nhiều version của cùng key.
2. Trần từ PHP-FPM có thể lên 64 kết nối, cộng queue workers, migration, quản trị và khoảng dự phòng; cần đo mức thực và giới hạn DB. Proxy không loại bỏ mọi pinning/transaction.
3. Không; NAT/DB/cache và đường mạng vẫn có failure mode riêng. Thử đúng sự cố và kiểm tra toàn luồng.
4. Không. Dùng expand/contract và compatibility window; nếu migration phá dữ liệu phải áp dụng recovery plan, không chạy migrate:rollback mù quáng.

</details>

## Giới hạn kiểm chứng

Artifact hướng dẫn triển khai đã được biên soạn. Test local, build Vue và lint CloudFormation không chứng minh quota, IAM propagation, AMI availability, DNS/TLS, ECS rollout hoặc RTO/RPO trên account thật. Theo dõi từng dòng ở [sổ kiểm chứng](../labs/15-capstone-laravel/evidence/README.md).
