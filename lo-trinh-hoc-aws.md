# Lộ trình học AWS cho Senior Developer

Định hướng dành cho lập trình viên PHP/Laravel, Vue và Flutter: tự triển khai, vận hành và thiết kế hệ thống cho ứng dụng thực tế.

Đây là thứ tự học đề xuất, không phải tiêu chuẩn bắt buộc cho mọi senior developer. Đánh dấu `[x]` khi hoàn thành từng nội dung.

## 1. Nền tảng cloud và quản lý tài khoản

- [ ] **Region, Availability Zone (AZ):** vị trí triển khai, độ trễ và tính sẵn sàng.
- [ ] **Shared Responsibility Model:** trách nhiệm của AWS và trách nhiệm của người sử dụng.
- [ ] **IAM:** user, group, role, policy; nguyên tắc cấp quyền tối thiểu.
- [ ] **MFA và thông tin xác thực:** bảo vệ tài khoản; phân biệt access key và thông tin xác thực tạm thời.
- [ ] **AWS CLI:** thao tác AWS bằng dòng lệnh, cấu hình profile.
- [ ] **AWS Budgets, Cost Explorer:** đặt cảnh báo ngân sách và theo dõi chi phí.

**Thực hành:** thiết lập tài khoản có MFA, cấu hình CLI và cảnh báo ngân sách trước khi tạo hạ tầng.

## 2. Networking

- [ ] **Amazon VPC:** mạng riêng cho hệ thống.
- [ ] **CIDR, subnet:** chia dải IP; public subnet và private subnet.
- [ ] **Route table, Internet Gateway, NAT Gateway:** đường đi của lưu lượng mạng.
- [ ] **Security Group và Network ACL:** kiểm soát kết nối; hiểu khác biệt giữa hai cơ chế.
- [ ] **Amazon Route 53:** DNS và định tuyến tên miền.
- [ ] **VPC Endpoint:** truy cập các dịch vụ AWS được hỗ trợ mà không cần đi qua Internet Gateway hoặc NAT.

**Thực hành:** thiết kế mạng có tầng ứng dụng và database; giải thích được vì sao ứng dụng kết nối được database nhưng Internet không truy cập trực tiếp được database.

## 3. Máy chủ và triển khai ứng dụng

- [ ] **Amazon EC2:** máy chủ ảo, AMI, instance type, user data.
- [ ] **Amazon EBS:** ổ đĩa cho EC2, snapshot.
- [ ] **IAM Role cho EC2:** ứng dụng truy cập dịch vụ AWS bằng quyền của role.
- [ ] **AWS Systems Manager Session Manager:** truy cập và quản lý máy chủ.
- [ ] **Application Load Balancer (ALB):** phân phối HTTP/HTTPS, health check.
- [ ] **EC2 Auto Scaling:** tăng giảm số máy chủ, thay thế instance lỗi.
- [ ] **AWS Certificate Manager (ACM):** quản lý chứng chỉ TLS.

**Thực hành:** triển khai Laravel với Nginx và PHP-FPM; sau đó chạy hai instance sau ALB. Tìm hiểu những gì phải thay đổi khi session, file upload và lịch chạy tác vụ không còn nằm trên một máy duy nhất.

## 4. Lưu trữ file và phân phối nội dung

- [ ] **Amazon S3:** bucket, object, quyền truy cập và Block Public Access.
- [ ] **Presigned URL:** cấp quyền upload/download tạm thời.
- [ ] **Versioning và Lifecycle:** lưu phiên bản, chuyển lớp lưu trữ hoặc xóa theo quy tắc.
- [ ] **Amazon CloudFront:** CDN, cache, invalidation.
- [ ] **Amazon EFS:** hệ thống file dùng chung; phân biệt với S3 và EBS.

**Thực hành:** Laravel cấp presigned URL để Vue hoặc Flutter upload trực tiếp lên S3; phân phối nội dung phù hợp qua CloudFront.

## 5. Database và cache

- [ ] **Amazon RDS:** triển khai MySQL/PostgreSQL, backup và khôi phục.
- [ ] **Multi-AZ và read replica:** phân biệt mục tiêu tăng tính sẵn sàng với mở rộng khả năng đọc.
- [ ] **Amazon Aurora:** tìm hiểu khi nào cân nhắc thay cho RDS MySQL/PostgreSQL thông thường.
- [ ] **Amazon ElastiCache:** cache, session và các vấn đề invalidation.
- [ ] **Amazon DynamoDB:** partition key, sort key, index, thiết kế theo access pattern.
- [ ] **Quản lý kết nối:** connection pool, giới hạn kết nối và RDS Proxy.

**Thực hành:** chuyển database của Laravel sang RDS, bổ sung cache và thử khôi phục dữ liệu từ backup.

**Ưu tiên:** học RDS trước DynamoDB với nền tảng ứng dụng quan hệ hiện tại. Khi mở rộng sang mô hình dữ liệu khác, tham khảo hướng dẫn lựa chọn database của AWS.

## 6. Giám sát và xử lý sự cố

- [ ] **Amazon CloudWatch:** logs, metrics, dashboard và alarm.
- [ ] **AWS CloudTrail:** lịch sử hoạt động API trong tài khoản.
- [ ] **Distributed tracing:** theo dõi request qua nhiều thành phần; tìm hiểu OpenTelemetry.
- [ ] **Chỉ số ứng dụng:** latency, error rate, throughput, queue backlog.
- [ ] **Quy trình xử lý sự cố:** phát hiện, khoanh vùng, khôi phục và phân tích nguyên nhân.

**Thực hành:** tạo lỗi HTTP 500, dừng một instance và làm chậm truy vấn; kiểm tra hệ thống có phát hiện được và bạn có tìm ra nguyên nhân hay không.

## 7. Queue và kiến trúc bất đồng bộ

- [ ] **Amazon SQS:** queue, visibility timeout, retry, dead-letter queue.
- [ ] **Amazon SNS:** publish/subscribe, phân phối thông báo.
- [ ] **Amazon EventBridge:** định tuyến sự kiện và tích hợp các thành phần.
- [ ] **EventBridge Scheduler:** lên lịch tác vụ.
- [ ] **Idempotency:** xử lý an toàn khi cùng một tác vụ được nhận nhiều lần.
- [ ] **AWS Step Functions:** điều phối workflow nhiều bước.

**Thực hành:** dùng Laravel Queue với SQS để xử lý gửi email hoặc xuất báo cáo; bổ sung retry, dead-letter queue và chống xử lý trùng.

## 8. Container và CI/CD

- [ ] **Docker:** đóng gói ứng dụng, cấu hình runtime, health check.
- [ ] **Amazon ECR:** lưu container image.
- [ ] **Amazon ECS:** task definition, service, networking và scaling.
- [ ] **AWS Fargate:** chạy container mà không quản lý các máy chủ EC2 bên dưới.
- [ ] **CI/CD:** test → build image → push ECR → deploy → kiểm tra → rollback.
- [ ] **OIDC cho pipeline:** cấp quyền tạm thời từ hệ thống CI/CD vào AWS.

**Thực hành:** đóng gói Laravel và queue worker thành các container riêng, triển khai lên ECS/Fargate.

**Ưu tiên:** học ECS/Fargate trước; dành Amazon EKS cho khi có nhu cầu Kubernetes.

## 9. Infrastructure as Code (IaC)

- [ ] **AWS CloudFormation hoặc AWS CDK:** mô tả và triển khai hạ tầng bằng code.
- [ ] **Terraform:** tìm hiểu nếu dự án hoặc công ty sử dụng.
- [ ] **Tách môi trường:** dev, staging, production.
- [ ] **Quản lý thay đổi:** review, xem trước thay đổi, phát hiện drift.
- [ ] **Quản lý cấu hình và secret:** tránh đưa bí mật vào repository hoặc output triển khai.

**Thực hành:** tái tạo hạ tầng đã học bằng IaC; triển khai được một môi trường mới mà không phải cấu hình thủ công từng dịch vụ.

## 10. Bảo mật ứng dụng và phân quyền nâng cao

- [ ] **AWS Secrets Manager, Systems Manager Parameter Store:** lưu secret và cấu hình.
- [ ] **AWS KMS:** mã hóa và kiểm soát quyền sử dụng khóa.
- [ ] **IAM nâng cao:** resource policy, condition, cross-account role.
- [ ] **AWS WAF:** quy tắc bảo vệ tầng HTTP.
- [ ] **Amazon Cognito:** xác thực người dùng cho web/mobile khi phù hợp.
- [ ] **Amazon GuardDuty, AWS Security Hub:** phát hiện và tổng hợp vấn đề bảo mật.

**Thực hành:** phân quyền để ứng dụng dev không đọc được secret production; kiểm tra cả trường hợp được phép lẫn bị từ chối.

## 11. Serverless

- [ ] **AWS Lambda:** execution model, timeout, concurrency, cold start.
- [ ] **Amazon API Gateway:** cung cấp API, xác thực và throttling.
- [ ] **Lambda kết hợp S3, SQS, EventBridge:** xử lý theo sự kiện.
- [ ] **Retry và lỗi từng phần:** tránh mất tác vụ hoặc tạo tác dụng phụ trùng lặp.
- [ ] **Lựa chọn kiến trúc:** so sánh Lambda với container và EC2 theo workload.

**Thực hành:** tạo luồng S3 upload → Lambda xử lý ảnh → lưu kết quả; bổ sung theo dõi lỗi.

## 12. Thiết kế và vận hành hệ thống ở mức senior

- [ ] **High availability:** loại bỏ điểm lỗi đơn, triển khai nhiều AZ.
- [ ] **Disaster recovery:** xác định RTO/RPO; thử khôi phục thực tế.
- [ ] **Khả năng chịu lỗi:** timeout, retry với backoff, circuit breaker, backpressure.
- [ ] **Hiệu năng:** load test, xác định bottleneck, lựa chọn cách scale.
- [ ] **Chi phí:** compute, database, NAT Gateway, lưu lượng dữ liệu và lưu log.
- [ ] **AWS Organizations, SCP:** quản trị nhiều tài khoản và đặt giới hạn quyền.
- [ ] **AWS Well-Architected Framework:** đánh giá kiến trúc theo vận hành, bảo mật, độ tin cậy, hiệu năng, chi phí và tính bền vững.

**Thực hành:** viết tài liệu kiến trúc cho ứng dụng đã xây, giải thích lựa chọn dịch vụ, cách khôi phục khi lỗi và đánh đổi về chi phí.

## Dự án xuyên suốt

Dùng một ứng dụng **Laravel API + Vue hoặc Flutter**, phát triển lần lượt:

- [ ] **Bước 1:** chạy trên EC2.
- [ ] **Bước 2:** chuyển database sang RDS, file sang S3.
- [ ] **Bước 3:** thêm HTTPS, ALB và Auto Scaling.
- [ ] **Bước 4:** thêm cache, SQS và queue worker.
- [ ] **Bước 5:** bổ sung log, dashboard, alarm và quản lý secret.
- [ ] **Bước 6:** chuyển sang ECS/Fargate, tự động hóa CI/CD.
- [ ] **Bước 7:** viết IaC, thử phục hồi backup và đánh giá kiến trúc.

**Mục tiêu sau cùng:** giải thích được vì sao chọn dịch vụ, triển khai được, phát hiện được lỗi, khôi phục được và kiểm soát được chi phí.

## Tài liệu AWS chính thức

- [AWS Decision Guides](https://docs.aws.amazon.com/decision-guides/)
- [Choosing an AWS compute service](https://docs.aws.amazon.com/decision-guides/latest/decision-guides/choosing-aws-compute-service.html)
- [Choosing an AWS database service](https://docs.aws.amazon.com/decision-guides/latest/decision-guides/databases-on-aws-how-to-choose.html)
- [AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/)
