# Mục lục kế hoạch học AWS

Mỗi file là plan để mô hình khác viết bài hướng dẫn, không phải bài học đã hoàn thành. Học theo thứ tự dưới đây; prerequisite và nhánh tùy chọn được ghi riêng trong từng plan.

- [01. Nền tảng AWS](01-aws-foundations.md)
- [02. Danh tính và quyền truy cập — IAM, IAM Identity Center, STS](02-identity-and-access.md)
- [03. Chi phí — AWS Budgets, Cost Explorer](03-cost-management.md)
- [04. Mạng — VPC, Subnet, Route Table, IGW, NAT, Security Group](04-networking.md)
- [05. Máy chủ và cân bằng tải — EC2, EBS, ALB, Auto Scaling, Systems Manager](05-compute-and-load-balancing.md)
- [06. Lưu trữ và phân phối nội dung — S3, CloudFront, Route 53, ACM](06-storage-and-content-delivery.md)
- [07. Database và cache — RDS, Aurora, DynamoDB, ElastiCache](07-databases-and-cache.md)
- [08. Container — ECR, ECS, Fargate](08-containers.md)
- [09. Serverless — Lambda, API Gateway](09-serverless.md)
- [10. Quan sát và bảo mật ứng dụng — CloudWatch, CloudTrail, Secrets Manager, Parameter Store, KMS](10-observability-and-application-security.md)
- [11. Messaging và workflow — SQS, SNS, EventBridge, Step Functions](11-messaging-and-workflows.md)
- [12. Infrastructure as Code và CI/CD — CloudFormation/CDK, Terraform, GitHub Actions OIDC](12-iac-and-cicd.md)
- [13. Độ tin cậy và hiệu năng — SLO, load test, backup, DR](13-reliability-and-performance.md)
- [14. Kiến trúc và quản trị nâng cao — Organizations, SCP, Control Tower, WAF](14-architecture-and-governance.md)

Logging, IAM, kiểm soát chi phí và IaC nên áp dụng dần từ các lab đầu, rồi học sâu ở chủ đề tương ứng. Chủ đề 09 chỉ dùng logging cơ bản nếu chưa học 10; chủ đề 10 chọn API từ 05, 08 hoặc 09 nên không tạo dependency vòng.

Mục tiêu của toàn lộ trình: giải thích được lựa chọn dịch vụ, triển khai có thể tái lập, kiểm chứng tình huống lỗi và vận hành với chi phí được hiểu rõ.
