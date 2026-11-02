# Plan bổ sung: Hoàn thiện lộ trình Laravel + Vue trên AWS

## 1. Mục đích và phạm vi bàn giao

Plan này chuyển những khoảng thiếu so với [lộ trình gốc](../lo-trinh-hoc-aws.md) thành công việc cụ thể cho model biên soạn tiếp theo. Đây là kế hoạch bổ sung cho bài 01–14 và dự án xuyên suốt, không phải một bài học số 15 bắt người học hoàn thành sau tất cả các bài khác.

- Chỉ dẫn trong file này bổ sung các plan hiện có; khi có khác biệt, dùng quyết định thống nhất được ghi ở đây cho phần bổ sung.
- Đối tượng: senior developer PHP/Laravel, mới học AWS; ngôn ngữ tiếng Việt.
- Chọn **Laravel API + Vue** làm đường thực hành chính. Flutter là nhánh chuyển đổi client tùy chọn; không yêu cầu người học xây cả hai.
- Giữ các lab Python/Node.js hiện tại làm bài tập cơ chế dịch vụ. Bổ sung nhánh Laravel và chỉ rõ phần nào phải làm để đạt lộ trình gốc.
- Model tiếp theo biên soạn bài học, mã mẫu, IaC và workflow trong repo. Không tự tạo tài nguyên AWS, chạy workflow deploy, bật dịch vụ trả phí, mua domain, thay đổi organization hoặc gửi thông báo.
- Việc chạy kiểm chứng AWS là giai đoạn riêng do người học thực hiện hoặc theo một yêu cầu có phạm vi được cho phép rõ ràng. Chưa chạy thì phải ghi chưa chạy.

## 2. Cấu trúc đầu ra cần tạo ở giai đoạn biên soạn

| Đường dẫn dự kiến | Nội dung |
|---|---|
| `notes/15-capstone-laravel.md` | Hướng dẫn liên kết bảy mốc của dự án, sơ đồ tiến hóa và quyết định kiến trúc |
| `labs/15-capstone-laravel/README.md` | Điểm vào dự án; prerequisite, lệnh tái lập, kiểm chứng, cleanup theo từng mốc |
| `examples/laravel-order-app/` | Laravel API, Vue client, fixture giả, test, Dockerfile và hướng dẫn chạy local |
| `infra/laravel-order-app/` | CloudFormation cho hạ tầng dự án, tham số môi trường, quyền và cleanup |
| `.github/workflows/laravel-order-app.yml` | CI, build, push ECR, deploy ECS, smoke test và rollback qua OIDC |
| `labs/15-capstone-laravel/evidence/README.md` | Mẫu thu thập bằng chứng; không chứa output AWS thật mặc định |
| `notes/05` đến `notes/14` và `labs/05` đến `labs/14` tương ứng | Bổ sung theo ma trận mục 3, liên kết đến đúng mốc dự án |

Các đường dẫn rút gọn trong hàng cuối chỉ nhóm các thư mục/file hiện hữu, không phải tên file cần tạo. Giữ bài 15 dự án tách biệt với số 15 của plan này. Model biên soạn phải cập nhật mục lục mở đầu và cơ chế build HTML để người học tìm được cả nhánh cơ bản lẫn dự án.

Không tạo các đầu ra trên trong lượt chỉ viết plan này.

## 3. Ma trận yêu cầu còn thiếu và nơi bổ sung

| Yêu cầu trong lộ trình | Bổ sung bắt buộc | Nơi biên soạn chính | Tiêu chí đầu ra |
|---|---|---|---|
| Laravel trên EC2; Nginx, PHP-FPM | Deploy Laravel thật; bootstrap, health check, log, instance role, SSM | Bài/lab 05; mốc 1 | API trả dữ liệu fixture, reboot lên lại; chẩn đoán được Nginx → FPM → app |
| RDS, cache; ưu tiên dữ liệu quan hệ | Laravel migration/seed qua RDS private; cache-aside, session dùng chung | Bài/lab 07; mốc 2, 4 | Dữ liệu tồn tại sau thay instance; chứng minh hit/miss, invalidation và cache lỗi |
| Presigned URL cho Vue/Flutter | Laravel ký URL; Vue upload trực tiếp; xác thực, ownership, CORS | Bài/lab 06; mốc 2 | Upload/download hợp lệ, URL hết hạn hoặc sai quyền bị từ chối |
| HTTPS, ALB, ASG; state ngoài máy | ACM, domain có sẵn, hai instance, session/upload/scheduler | Bài/lab 05–07, 11; mốc 3–4 | Không mất trạng thái khi chuyển target hoặc thay instance; tác vụ lịch không nhân đôi |
| Laravel Queue với SQS | Worker Laravel riêng; retry, DLQ, idempotency có dữ liệu bền vững | Bài/lab 11; mốc 4 | Job trùng không tạo side effect trùng; poison job vào DLQ và phục hồi được |
| Quan sát ứng dụng và secret dev/prod | Dashboard, alarm, tracing OpenTelemetry, phân quyền môi trường | Bài/lab 10; mốc 5 | Trace có liên kết request/job; dev đọc dev được, đọc prod bị từ chối |
| Laravel API và worker trên ECS/Fargate | Container riêng, scheduler, migration task, rollout và graceful shutdown | Bài/lab 08; mốc 6 | API và worker cùng dùng dữ liệu mốc trước; drain/rollback kiểm chứng được |
| Pipeline đầy đủ qua OIDC | Test → build → ECR → ECS → smoke test → rollback | Bài/lab 12; mốc 6 | Digest triển khai khớp build; lỗi smoke test có đường phục hồi rõ |
| IaC tái tạo toàn môi trường | VPC, compute, dữ liệu, queue, logs, IAM và secret references | Bài/lab 12; mốc 7 | Tạo môi trường mới bằng code; không cần sửa tay từng dịch vụ |
| S3 → Lambda xử lý ảnh → S3 | Resize ảnh thật, prefix riêng, idempotency, theo dõi lỗi | Bài/lab 09 | Ảnh kết quả đúng kích thước; file lỗi không mất dấu, không tạo vòng lặp |
| EFS so với S3/EBS | Giải thích filesystem dùng chung và bài tập quyết định | Bài 06 | Chọn đúng storage cho upload, shared POSIX, block device; không chỉ nêu tên EFS |
| EventBridge Scheduler | Lịch chạy job; timezone, quyền target, retry, DLQ và chống trùng | Bài/lab 11 | Một lịch kích hoạt job, xử lý target lỗi và xóa lịch sau lab |
| Cognito | User pool, Vue login, kiểm tra JWT và phân quyền API | Bài/lab 10; liên kết bài 09 | Token sai/hết hạn bị từ chối; hai user không đọc được đơn của nhau |
| GuardDuty, Security Hub | Quy trình đọc, đối chiếu và xử lý finding mẫu | Bài/lab 14 | Truy vết finding đến tài nguyên, đề xuất xử lý, phân biệt phát hiện với ngăn chặn |
| HA, DR, hiệu năng và Well-Architected | Drill trên ứng dụng dự án; ADR và đánh giá sáu trụ cột | Bài/lab 13–14; mốc 7 | Đo RTO/RPO, bottleneck và chi phí; nêu failure mode còn lại |

## 4. Hợp đồng ứng dụng và tài nguyên dùng xuyên suốt

Model biên soạn phải chốt hợp đồng trước khi viết lệnh để các bài không dùng các ứng dụng khác nhau chỉ cùng tên “orders”.

- API tối thiểu: health/readiness, tạo/xem đơn hàng, yêu cầu upload ảnh, tạo job xuất báo cáo và xem trạng thái job.
- Vue tối thiểu: tạo/xem đơn, upload trực tiếp S3 và xem trạng thái báo cáo. Bắt đầu bằng identity giả cho local; nêu rõ thời điểm chuyển sang xác thực thật. Endpoint có quyền trên dữ liệu không được để công khai ngoài nhánh demo giới hạn.
- Dữ liệu: một hệ quản trị quan hệ làm đường chính, ưu tiên PostgreSQL cho khớp lab 07. DynamoDB giữ vai trò bài tập access pattern hoặc ví dụ riêng; không thay thế yêu cầu Laravel + RDS.
- Một nguồn cấu hình cho DB, cache/session, S3, SQS, log và secret reference; không commit `.env`, secret, token, Terraform state hoặc output định danh thật.
- Session: nếu API stateless thì giải thích vì sao; thêm tình huống kiểm tra session dùng chung để đáp ứng mục tiêu nhiều instance. Phân biệt session của ứng dụng với token xác thực.
- Idempotency: xác định business key, ràng buộc unique, trạng thái xử lý và transaction boundary. Chỉ ra crash sau side effect khác crash trước side effect; không hứa exactly-once cho email ngoài hệ thống.
- Schema migration: một bước chạy có kiểm soát, không chạy đồng thời trong mọi container; dùng thay đổi tương thích ngược và ghi giới hạn rollback dữ liệu.
- Version công cụ, PHP/Laravel, Node/Vue, SDK, image và action được chọn/ghi lại khi biên soạn sau khi kiểm tra tài liệu chính thức. Không dùng plan này làm bằng chứng version hiện hành.
- Mỗi mốc có tên/tag tài nguyên, inventory đầu vào/đầu ra, owner, artifact version và cách tiếp tục sau cleanup.
- Ưu tiên checkpoint bằng code/fixture và IaC thay vì yêu cầu giữ NAT, ALB, RDS chạy nhiều tuần. Trước mốc 7 phải có hướng dẫn dựng lại prerequisite; mốc 7 thay thế dần thao tác thủ công bằng IaC.
- Không đưa tài nguyên tạo thủ công và tài nguyên IaC vào cùng ownership ngầm: chọn tạo môi trường mới và chuyển dữ liệu, hoặc mô tả import/migration có kiểm soát.

## 5. Bảy mốc dự án và nghiệm thu

### Mốc 1 — Laravel API trên EC2

- Bổ sung kiến thức Nginx → PHP-FPM → Laravel, permission thư mục, config cache, process/service và log.
- Hướng dẫn cài ứng dụng bằng artifact/version rõ ràng; tránh đưa secret vào user data hoặc log bootstrap.
- Kiểm tra API, reboot và lỗi FPM dừng; ghi dấu hiệu phân biệt lỗi upstream với lỗi ứng dụng.
- Chỉ rõ storage/database tạm ở mốc này và dữ liệu nào sẽ mất khi thay instance. Chưa tuyên bố HA.

### Mốc 2 — RDS và S3, client Vue

- Migration/seed dữ liệu giả vào RDS private; kết nối từ ứng dụng, không mở DB ra Internet để tiện làm lab.
- Chuyển upload local sang S3 private. Backend xác thực người yêu cầu, kiểm tra ownership, key, loại/kích thước file; mô tả giới hạn của presigned PUT và bước kiểm tra object sau upload.
- Cung cấp Vue client, CORS đúng origin/header, download có kiểm soát và CloudFront cho loại nội dung phù hợp. Phân biệt file công khai với file riêng tư.
- Kiểm tra migration, dữ liệu sau restart, URL hết hạn, sai chữ ký/header, user khác và origin không hợp lệ. Giải thích CORS không thay thế authorization.

### Mốc 3 — HTTPS, ALB và Auto Scaling

- Chạy hai instance dùng cùng RDS/S3; cấu hình proxy/HTTPS của Laravel và health check phù hợp.
- Nhánh HTTPS dùng domain đã có quyền quản lý; hướng dẫn xác thực ACM và DNS. Nhánh không có domain vẫn học HTTP lab riêng nhưng không được đánh dấu hoàn thành yêu cầu HTTPS.
- Kiểm tra chuyển target, terminate một instance, tỷ lệ lỗi và thời gian phục hồi; giải thích giới hạn NAT một AZ và DB Single-AZ nếu dùng cấu hình tiết kiệm.
- Chỉ ra nơi lưu session và cách ngăn scheduler chạy trùng. Thực hiện kiểm tra đầy đủ khi có cache/queue ở mốc 4; ghi rõ dependency này.

### Mốc 4 — Cache, Laravel Queue và lịch chạy

- Cache-aside và session ngoài instance, invalidation sau cập nhật, TTL, cache unavailable; nêu lựa chọn fail-open/fail-closed theo chức năng.
- Laravel worker riêng xử lý báo cáo có thể kiểm chứng bằng artifact S3, dùng dữ liệu giả. Email là nhánh tùy chọn dùng test sink, không gửi thật.
- Phân biệt retry/backoff trong Laravel, SQS visibility/redrive và Laravel failed jobs; giải thích quan hệ timeout, thời gian job và nhận lại message.
- Negative case: message trùng, worker bị dừng giữa job, job lỗi hết số lần thử, redrive sau sửa. Kiểm tra side effect và trạng thái DB, không chỉ đếm log.
- EventBridge Scheduler làm đường chính cho lịch gửi job; Laravel scheduler là phương án so sánh có khóa dùng chung. Không bật cả hai cho cùng nghiệp vụ.
- Kiểm tra session qua hai target và tác vụ lịch không tạo báo cáo trùng; pause/delete schedule trước cleanup queue/target.

### Mốc 5 — Log, metric, tracing và secret

- Structured log có correlation ID, metric latency/error/throughput/backlog, dashboard và alarm có ngưỡng/điều kiện thử cụ thể.
- OpenTelemetry: trace HTTP → DB hoặc AWS SDK, truyền context qua SQS đến worker; chỉ rõ package/collector/exporter và đường mạng sau khi tra cứu hỗ trợ thực tế.
- Không coi chỉ bật active tracing ở Lambda là đã instrument Laravel. Tránh ghi token, presigned URL hoặc nội dung secret vào trace/log.
- Tách dev và prod bằng tài nguyên giả riêng hoặc account sandbox được cấp; không dùng production thật. Test dev đọc secret dev thành công, đọc secret prod thất bại; đối chiếu CloudTrail phù hợp.
- Gây HTTP 500, chậm query có giới hạn và dừng instance; yêu cầu phát hiện, chẩn đoán, khôi phục và runbook cho từng trường hợp.

### Mốc 6 — ECS/Fargate và CI/CD

- Container Nginx/PHP-FPM cho API theo thiết kế được giải thích; worker và scheduler là service/task riêng nếu sử dụng. Không để worker chạy kèm web process thiếu quản lý vòng đời.
- Task role/execution role tách biệt; secret runtime, log, readiness, stop timeout và graceful shutdown cho job dài.
- Pipeline: kiểm tra Laravel/Vue → test → build image → push ECR → deploy digest → migration task được kiểm soát → chờ ổn định → smoke test nghiệp vụ → kết luận hoặc rollback.
- OIDC giới hạn repo và branch/environment; PR không tin cậy chỉ chạy kiểm tra, không nhận quyền deploy. Hai lần deploy cùng môi trường phải có kiểm soát concurrency.
- Negative case: image/port lỗi và image healthy nhưng nghiệp vụ sai. Phân biệt ECS circuit breaker với rollback do smoke test của pipeline.
- Rollback dùng task definition/digest trước đó; kiểm tra API và worker sau rollback; không tự đảo migration phá hủy dữ liệu.

### Mốc 7 — IaC, recovery drill và đánh giá kiến trúc

- Chọn **CloudFormation YAML** làm đường chính để nối lab 12 hiện tại; CDK và Terraform là nhánh so sánh. Khi biên soạn sửa mục tiêu/dàn ý plan 12 đang mặc định CDK để thống nhất lựa chọn.
- Bao phủ VPC/subnet/route/egress, ALB/ACM reference, ECS/ECR, RDS, cache, S3/CloudFront, SQS/DLQ/Scheduler, IAM, secret references, log/metric/alarm; ghi rõ thành phần tùy chọn và prerequisite được cấp bên ngoài.
- Môi trường dev/staging/prod có cấu hình, quyền và dữ liệu tách biệt; dùng tên prod cho môi trường mô phỏng, không triển khai production thật trong lab.
- Từ checkout mới, tạo một môi trường sandbox mới, seed, deploy và chạy luồng tạo đơn → upload → job → kết quả. Không cần thao tác tạo riêng từng dịch vụ; bootstrap và xác thực domain phải được liệt kê rõ.
- Load test trên API dự án có baseline, ramp, steady-state, recovery, trần rate/concurrency/duration và điều kiện dừng. Đo percentile từ dữ liệu thực, chỉ ra bottleneck.
- Thử timeout, retry có budget, circuit breaker và backpressure trên dependency của fixture. Có đối chứng và giới hạn; không thay bằng mô phỏng offline mà vẫn ghi đạt drill ứng dụng.
- Backup/restore RDS sang môi trường tách biệt; đo RTO/RPO theo mốc thời gian, đối chiếu khóa/dữ liệu tham chiếu và kiểm tra ứng dụng trỏ sang bản restore.
- Viết ADR cho compute, DB, storage, queue, HA/DR; đánh giá sáu trụ cột Well-Architected, tính chi phí và liệt kê điểm lỗi đơn còn lại.

## 6. Các phần chuyên đề bổ sung

### 6.1 EFS và quản lý kết nối — bài 06–07

- Bổ sung bảng S3/EBS/EFS: API/filesystem, chia sẻ giữa instance, latency, topology, quyền, backup và chi phí.
- Có tình huống cần POSIX dùng chung và tình huống upload nên dùng S3. Mount EFS là mini-lab tùy chọn có SG, mount target, encryption, cleanup; không biến EFS thành prerequisite Laravel.
- Với RDS Proxy/connection pool: giải thích pool budget theo replica/worker, theo dõi kết nối và giới hạn. Có bài tập tính budget; triển khai proxy là tùy chọn, nêu workload nào cần và chi phí.

### 6.2 Lambda xử lý ảnh — bài 09

- Input `uploads/`, output `processed/`; cung cấp code resize ảnh, dependency/package tương thích runtime và kiến trúc đã chọn.
- Execution role giới hạn input/output, giới hạn kích thước/loại ảnh, bộ nhớ, timeout và concurrency. Không tin Content-Type từ client là đủ xác nhận ảnh hợp lệ.
- Xử lý duplicate event và thứ tự phiên bản object; output key/idempotency không bỏ qua bản upload mới cùng key.
- Kiểm tra ảnh hợp lệ bằng kích thước/nội dung metadata, ảnh hỏng, object thiếu và event trùng; có log/metric/alarm và nơi giữ lỗi sau retry.
- Phân biệt cơ chế lỗi của S3 async invoke với SQS partial batch response. Bổ sung ví dụ Lambda + SQS xử lý lỗi từng phần và liên kết EventBridge; không dùng một trigger làm bằng chứng đã học mọi integration.
- Cleanup notification trước function/bucket; xử lý object versions, artifact package, log và destination lỗi.

### 6.3 Cognito — bài 10, liên kết bài 09

- Giải thích user pool và identity pool, chọn flow web/mobile phù hợp; Vue là public client, không nhúng client secret.
- Cung cấp login/logout, refresh và kiểm tra issuer/audience hoặc client ID theo loại token, expiry, scope/token use theo thư viện chính thức được chọn.
- Laravel API kiểm tra JWT và authorization theo ownership; nếu dùng API Gateway authorizer, giải thích phần trách nhiệm còn ở app.
- Negative case: token hết hạn/sai issuer, thiếu scope và truy cập đơn người khác. Có đường học bằng fixture token giả ghi rõ chưa xác minh với Cognito; đường AWS thật tách riêng.
- Không bắt mua domain hoặc gửi SMS/email trả phí để hoàn thành đường tối thiểu; nêu điều kiện user test, messaging và cleanup khi biên soạn.

### 6.4 GuardDuty và Security Hub — bài 14

- Bổ sung vai trò, nguồn dữ liệu, phạm vi account/Region, finding lifecycle, phân loại ưu tiên và delegated administrator.
- Đường chính dùng finding mẫu đã che định danh, bảng tài nguyên giả và bài tập triage: bằng chứng, tác động, hành động và kiểm chứng sau xử lý. Có đáp án gợi ý.
- Nhánh live tùy chọn: kiểm tra trạng thái dịch vụ trước, ước tính chi phí, dùng chức năng finding mẫu được hỗ trợ; không tạo hành vi tấn công thật.
- Ghi tài nguyên/cấu hình có sẵn và chỉ hoàn tác thứ lab đã tạo. Không tắt detector hoặc cấu hình tổ chức dùng chung để cleanup.
- Không đánh dấu bài tập phân tích mẫu là đã bật dịch vụ hoặc đã kiểm chứng phát hiện thực tế.

## 7. Trình tự model tiếp theo triển khai tài liệu

1. Đọc lộ trình gốc, plan hiện tại, notes/labs liên quan và quy tắc repository; giữ thay đổi có sẵn của người dùng.
2. Chốt hợp đồng ứng dụng, phiên bản và sơ đồ; tạo fixture local, API contract và kiểm thử nghiệp vụ trước.
3. Biên soạn mốc 1–4 và cập nhật bài 05–07, 11. Thứ tự dự án khác thứ tự đánh số bài; cung cấp bảng “đọc phần nào trước mốc nào”.
4. Biên soạn mốc 5–6 và cập nhật bài 08, 10, 12; phần Lambda xử lý ảnh là nhánh sau storage, không chặn đường ECS.
5. Biên soạn mốc 7, cập nhật bài 13–14; bổ sung EFS, Scheduler, Cognito, GuardDuty/Security Hub theo mục 6.
6. Cập nhật bài mở đầu, mục lục, liên kết và thời lượng. Tách thời lượng lab dịch vụ với thời lượng dự án; không giữ tổng 70–100 giờ nếu chưa tính phần thêm.
7. Chạy kiểm tra local phù hợp, kiểm tra liên kết và build HTML khi biên soạn xong. Ghi danh sách phần AWS chưa chạy; bàn giao checklist cho người thực hiện drill.

## 8. Hợp đồng chất lượng cho từng phần bổ sung

- Mỗi bước có mục đích, prerequisite, lệnh/thao tác đầy đủ, nơi chạy, biến/placeholder, expected result, cách xác minh và cách xử lý khác kỳ vọng.
- Ghi quyền control/data plane, `PassRole` nếu cần, tài nguyên áp dụng và ownership; không chữa lỗi bằng admin hoặc mở mạng rộng.
- Ghi nguồn giá chính thức, Region/ngày tra cứu, thời lượng, trần quy mô và điều kiện dừng. Phân biệt đường thiết kế, mô phỏng local và đường AWS có tính phí.
- Cleanup có thứ tự dependency, đường dừng giữa chừng/mất shell, inventory sau cleanup và retained resources còn tính tiền. Không chỉ dựa vào billing tức thời.
- Mỗi chủ đề có câu hỏi áp dụng và đáp án tách khỏi đề. Mỗi mục tiêu triển khai có happy path và negative case đo được.
- Nội dung thay đổi theo thời gian phải tra tài liệu AWS, Laravel, Vue, OpenTelemetry và GitHub chính thức khi viết; ghi URL cụ thể, ngày và version. Không sao chép ngày đối chiếu cũ sang phần chưa tra.

## 9. Kế hoạch kiểm chứng và bằng chứng

Phân biệt hai mức nghiệm thu: **đã biên soạn và kiểm tra local** với **đã chạy AWS**. Hoàn thành mức đầu không được tự đánh dấu mức sau.

| Nhóm | Bằng chứng yêu cầu ở giai đoạn tương ứng |
|---|---|
| Local | Cài từ lockfile, migration/seed, test nghiệp vụ/authorization/idempotency, build Vue và image; ghi công cụ và kết quả |
| IaC/pipeline | Validate/lint, review quyền và workflow; ghi giới hạn kiểm tra tĩnh, không coi validate pass là deploy pass |
| Bảy mốc AWS | Ngày, commit, công cụ, Region, account alias đã che, bước thực hiện, expected/actual, pass/fail và liên kết bằng chứng |
| Failure/recovery | Timeline sự cố, alarm/trace/log liên quan, số đo và dữ liệu đối chiếu; không dùng số minh họa để kết luận RTO/RPO |
| Cleanup | Inventory trước/sau theo service và môi trường, tài nguyên giữ lại, lý do, ownership và chi phí còn lại |

Mẫu bằng chứng chỉ chứa placeholder. Không commit credentials, token, presigned URL, dữ liệu người dùng hoặc log định danh chưa xử lý. Mọi test không thực hiện phải ghi `chưa chạy` cùng điều kiện cần để chạy.

## 10. Checklist nghiệm thu phần bổ sung

- [ ] Từng hàng ma trận mục 3 có bài học/bài tập tương ứng và tiêu chí đạt; không chỉ thêm tên dịch vụ.
- [ ] Có Laravel API + Vue chạy local và một hợp đồng dữ liệu dùng xuyên suốt bảy mốc.
- [ ] Có Nginx/PHP-FPM, RDS, S3, HTTPS/ALB/ASG, cache/session, SQS worker và lịch chạy chống trùng.
- [ ] Có tracing ứng dụng, xử lý ba tình huống lỗi và test quyền secret dev/prod.
- [ ] API và worker triển khai trên ECS; pipeline đủ test/build/push/deploy/verify/rollback, không chứa access key dài hạn.
- [ ] IaC có thể dựng môi trường mới; prerequisite ngoài IaC và thành phần tùy chọn được liệt kê rõ.
- [ ] Có xử lý ảnh thật bằng Lambda, bài lỗi từng phần với SQS và phân biệt semantics các trigger.
- [ ] EFS, Scheduler, Cognito, GuardDuty/Security Hub có giải thích, bài tập và giới hạn xác minh rõ.
- [ ] Có recovery/load/fault drill trên ứng dụng; kiến trúc và chi phí được đánh giá theo bằng chứng.
- [ ] Mỗi mốc có cách dựng lại, cleanup và kiểm kê; không buộc giữ hạ tầng trả phí qua nhiều buổi.
- [ ] Liên kết, mục lục, thời lượng và HTML được cập nhật ở giai đoạn biên soạn.
- [ ] Tách biệt trạng thái hoàn thành tài liệu/local với trạng thái thực thi AWS; không có bằng chứng giả.

## 11. Chỉ dẫn bàn giao

> Hãy thực hiện plan bổ sung này cùng các plan 01–14, bắt đầu bằng mục 4 và thứ tự mục 7. Giữ các lab dịch vụ hiện có, bổ sung đường Laravel + Vue và dự án bảy mốc. Đọc file thực tế trước khi sửa; dùng các đường dẫn mục 2 cho artifact mới và cập nhật mục lục/liên kết liên quan. Tra tài liệu chính thức ở thời điểm biên soạn, cụ thể hóa lệnh, quyền, dependency, expected output, negative case, đáp án và cleanup. Chỉ biên soạn artifact và chạy kiểm tra local phù hợp; không tự deploy hoặc thay đổi AWS/GitHub từ yêu cầu viết tài liệu. Bàn giao trạng thái từng hàng ma trận, kiểm tra đã chạy và danh sách AWS chưa kiểm chứng. Không đánh dấu đạt toàn bộ lộ trình chỉ vì tài liệu đã được tạo.
