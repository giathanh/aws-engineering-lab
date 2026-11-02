# Sổ kiểm chứng dự án Laravel

## Trạng thái biên soạn ngày 2026-11-01

| Phần | Kiểm chứng | Giới hạn |
|---|---|---|
| Laravel API/queue/outbox | PHPUnit local với SQLite, filesystem giả, queue sync và database worker | Chưa thử row lock/concurrency PostgreSQL/SQS thật |
| Cognito middleware | Token RSA giả hợp lệ, sai issuer/client/token_use/scope/hết hạn | Chưa đăng nhập hosted UI Cognito thật |
| Vue | npm install/lockfile và production build | Chưa E2E browser với AWS |
| Lambda ảnh | Unit tests resize/reject/duplicate/version/partial batch với client giả | Chưa chạy Lambda/S3 triggers |
| CloudFormation | cfn-lint trên foundation/runtime/ec2/images | Chưa create/update/delete stack |
| Docker images | Dockerfile/Compose đã biên soạn | Máy biên soạn chưa có Docker; chưa build/run image |
| CI/CD | Workflow/release script đã biên soạn và kiểm tra cú pháp | Chưa chạy GitHub Actions/OIDC/deploy/rollback |
| HA/load/DR/chi phí | Quy trình, workload có giới hạn và mẫu ghi bằng chứng | Chưa đo AWS RTO/RPO, p95/p99, cost thực |

Kết quả local: **9 test Laravel, 37 assertions đạt** (PHP 8.3.36/PHPUnit 11.5.57), **5 test xử lý ảnh đạt** (Pillow 11.3.0), **Vue build đạt** (Vite 6.4.5), **4 template cfn-lint đạt** (1.46.0), kiểm tra cú pháp PHP/Python và liên kết nội bộ đạt. Có test database queue worker và propagation trace context sau khi request kết thúc. Không đánh dấu lộ trình đã hoàn thành chỉ từ các artifact này.

## Mẫu ghi mỗi lần thực hiện

Sao chép mẫu ra thư mục cá nhân đã ignore; không commit log chứa định danh/secret.

```text
Mốc/test:
Ngày và timezone:
Commit/artifact digest:
Tool versions:
Account alias / Region:
Tài nguyên fixture và owner:
Prerequisite/quyền:
Lệnh hoặc bước thực hiện (che định danh):
Expected:
Actual:
Trạng thái: chưa chạy | đạt | thất bại
Evidence log/metric/trace đã che thông tin:
Giới hạn hoặc nguyên nhân thất bại:
Thời gian bắt đầu sự cố / phục hồi:
Watermark dữ liệu và phương pháp tính RTO/RPO:
Inventory sau cleanup / retained resources / chi phí còn lại:
```

## Ma trận nghiệm thu cần chạy trên AWS

| Nhóm | Positive | Negative | Bằng chứng bắt buộc | Trạng thái |
|---|---|---|---|---|
| EC2 | FPM/Nginx sau reboot | Dừng FPM, terminate instance | HTTP/status, log, target timeline | Chưa chạy |
| RDS/S3 | Migration, upload/download | Sai owner, URL hết hạn, file giả ảnh | Row/key/version, HTTP error | Chưa chạy |
| HTTPS/session | TLS hợp lệ, cookie qua target | Một target mất | hostname/visit count, error timeline | Chưa chạy |
| Queue/cache | Report/cache hit/refresh | Duplicate, worker crash, poison, cache down | DB state, queue/DLQ, object key | Chưa chạy |
| Scheduler | Tick theo lịch | Target lỗi/command lỗi | Scheduler DLQ khác ECS exit code | Chưa chạy |
| Quan sát/secret | Trace HTTP→job, dev allow | 500/slow SQL, other-env deny | Trace/log/alarm và principal | Chưa chạy |
| ECS/pipeline | Digest + business smoke | Bad port, healthy-but-broken API | Task revision, rollback, schema compatibility | Chưa chạy |
| IaC | Dựng môi trường mới | Drift/change set replacement | Template/parameter và smoke | Chưa chạy |
| Recovery | Restore + app verify | Watermark sau recovery point mất | Tập UUID, RTO/RPO | Chưa chạy |
| Lambda | Resize đúng | Duplicate/corrupt/partial batch | Kích thước, version, errors queue | Chưa chạy |
| Security services | Phân tích finding sample | Thiếu evidence/dedup | Phiếu triage, không coi sample là exploit | Offline fixture |
| Cleanup | Hết compute/network tính giờ | Dừng giữa chừng | Inventory retained/deleted | Chưa chạy |

## Không đưa vào evidence

AWS credentials/session token, Cognito tokens/password, presigned URL còn hiệu lực, `.env`, dump dữ liệu thật, output chưa che account/ARN/host. Dùng alias khi chia sẻ. Luôn giữ bản ghi `chưa chạy` cho bước bị bỏ qua.
