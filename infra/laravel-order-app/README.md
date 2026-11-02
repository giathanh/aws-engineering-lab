# CloudFormation cho ứng dụng Laravel

Các template dành cho sandbox, chưa deploy trong lần biên soạn. [Lab chính](../../labs/15-capstone-laravel/README.md) giải thích thứ tự và expected result.

| File | Sở hữu | Vòng đời |
|---|---|---|
| `foundation.yaml` | VPC/NAT/SG, RDS, Redis, S3/CloudFront, SQS, Cognito, ECR, IAM, logs/dashboard | Tồn tại qua các lần thay compute; tính phí ngay |
| `ec2.yaml` | ALB/TG, launch template/ASG, bootstrap native | Mốc EC2; không sở hữu DB |
| `runtime.yaml` | ALB, ECS API/worker/task definitions, Scheduler, alarm | Mốc ECS; bắt đầu DesiredCount=0 |
| `images.yaml` | Bucket ảnh riêng, Lambda resize, failure queue, alarm | Mini-lab 09; không ghi đè notification của bucket capstone |

Template dùng YAML đầy đủ, không cần CDK/bootstrap bucket. Domain/certificate ACM cùng Region, quyền deploy và OIDC provider GitHub có trước là prerequisite ngoài stack. Không chạy đồng thời Terraform/CDK quản lý cùng tài nguyên.

## Quyền cần xin cho người học

Người bootstrap cần quyền CloudFormation create/describe/update/delete/change set trên prefix stack riêng; thao tác tạo/xóa các loại resource đã liệt kê trong template: EC2 networking/ASG/ELB, RDS, ElastiCache, S3/CloudFront, SQS, Cognito, ECR/ECS, Logs/CloudWatch, Secrets Manager, Scheduler và IAM role/profile/policy. Cần `iam:PassRole` chỉ với role lab cho EC2/ECS/Scheduler/CloudFormation, và quyền tạo service-linked role tương ứng nếu account chưa có. Quota/permission boundary/SCP của sandbox vẫn có thể ngăn deploy; hỏi quản trị viên cấp theo template, không yêu cầu admin chung.

Template là danh sách quyền của **resource runtime** cụ thể: task role chỉ S3 prefixes, SQS queue và X-Ray; execution role đọc đúng secret ARN và ECR/log; instance role thêm SSM/artifact bootstrap. Chính sách runtime không tự cấp người dùng quyền tạo stack.

DB sử dụng managed master secret trong fixture. Điều này đơn giản hóa bootstrap nhưng quyền SQL quá rộng cho production. Khi tách runtime SQL user, tạo role DML và secret riêng qua migration quản trị, đổi DB_USERNAME/DBSecret; không lưu password vào output stack. TLS DB ở mức `require` mã hóa nhưng chưa verify hostname bằng CA; production cần CA bundle và `verify-full` theo driver.

## Build image và lấy digest

Từ repo root, dùng output foundation đã lưu ở lab. Máy build cần Docker; Apple Silicon phải build `linux/amd64` vì runtime template chọn X86_64.

```bash
export APP_REPO=$(out AppRepo) NGINX_REPO=$(out NginxRepo) TELEMETRY_REPO=$(out TelemetryRepo)
export IMAGE_TAG="$(git rev-parse --short HEAD)-$(date -u +%Y%m%d%H%M%S)"
aws ecr get-login-password | docker login --username AWS --password-stdin "${APP_REPO%%/*}"
docker build --platform linux/amd64 --target app -t "$APP_REPO:$IMAGE_TAG" examples/laravel-order-app
docker build --platform linux/amd64 --target nginx -t "$NGINX_REPO:$IMAGE_TAG" examples/laravel-order-app
docker build --platform linux/amd64 -f examples/laravel-order-app/docker/telemetry.Dockerfile -t "$TELEMETRY_REPO:$IMAGE_TAG" examples/laravel-order-app
docker push "$APP_REPO:$IMAGE_TAG"
docker push "$NGINX_REPO:$IMAGE_TAG"
docker push "$TELEMETRY_REPO:$IMAGE_TAG"
export APP_IMAGE="$APP_REPO@$(aws ecr describe-images --repository-name "${APP_REPO#*/}" --image-ids imageTag="$IMAGE_TAG" --query 'imageDetails[0].imageDigest' --output text)"
export NGINX_IMAGE="$NGINX_REPO@$(aws ecr describe-images --repository-name "${NGINX_REPO#*/}" --image-ids imageTag="$IMAGE_TAG" --query 'imageDetails[0].imageDigest' --output text)"
export TELEMETRY_IMAGE="$TELEMETRY_REPO@$(aws ecr describe-images --repository-name "${TELEMETRY_REPO#*/}" --image-ids imageTag="$IMAGE_TAG" --query 'imageDetails[0].imageDigest' --output text)"
```

Giữ tag cũ cho rollback; lifecycle chỉ dọn untagged sau 7 ngày. Base images dùng version tag, dependency app dùng lockfiles; tag image bên ngoài có thể thay đổi. Với release cần tái lập byte-for-byte, resolve/pin base digest theo kiến trúc trước build và ghi vào evidence. Collector chọn 0.120.0 làm fixture; chưa xác minh image pull ở máy biên soạn không có Docker.

## GitHub OIDC và quyền pipeline

Workflow chỉ release **runtime đã tồn tại**, không tạo foundation. Provider `token.actions.githubusercontent.com` là tài nguyên cấp account, dùng provider đã được quản trị viên tạo; không tự xóa sau lab. Trust dùng `aud=sts.amazonaws.com` và `sub=repo:OWNER/REPO:environment:dev`. Environment dev phải chỉ cho branch main; bật reviewer nếu chính sách nhóm cần. Workflow_dispatch tự kiểm tra main nhưng không thay environment protection. [GitHub OIDC với AWS](https://docs.github.com/en/actions/how-tos/secure-your-work/security-harden-deployments/oidc-in-aws).

Sinh các policy từ resource đã có (chỉ đọc, chưa tạo IAM):

```bash
python3 infra/laravel-order-app/scripts/cicd-policy.py "$FOUNDATION_STACK" "$RUNTIME_STACK" OWNER/REPO "$LAB_STATE/iam"
```

Review bốn file rồi tạo hai role riêng trong account lab:

```bash
aws iam create-role --role-name "$RUNTIME_STACK-cfn-execution" --assume-role-policy-document "file://$LAB_STATE/iam/cfn-trust.json"
aws iam put-role-policy --role-name "$RUNTIME_STACK-cfn-execution" --policy-name RuntimeUpdate --policy-document "file://$LAB_STATE/iam/cfn-policy.json"
aws iam create-role --role-name "$RUNTIME_STACK-deploy" --assume-role-policy-document "file://$LAB_STATE/iam/deploy-trust.json"
aws iam put-role-policy --role-name "$RUNTIME_STACK-deploy" --policy-name LabRelease --policy-document "file://$LAB_STATE/iam/deploy-policy.json"
```

CloudFormation execution policy hỗ trợ update và cleanup các resource runtime hiện tại. Khi thêm resource hoặc thay topology, người bootstrap review/change set rồi cập nhật quyền; đừng cấp wildcard admin để pipeline tự mở rộng phạm vi. `RegisterTaskDefinition` cần resource `*`; PassRole vẫn giới hạn đúng task/execution role. ARN role này phụ thuộc tên stack, không dùng chung cho prod.

GitHub environment variables: `AWS_REGION`, `FOUNDATION_STACK`, `RUNTIME_STACK`, `DEPLOY_ROLE_ARN`, `CFN_EXECUTION_ROLE`. Environment secret: `LAB_SMOKE_ACCESS_TOKEN` của user test, có hạn ngắn. Lấy token mới ngay trước một phiên release, xóa secret sau phiên. Production CI nên dùng test identity/token acquisition được quản lý; fixture không tự động tạo flow password hoặc lưu refresh token lâu dài.

Workflow actions được cố định ở major tag (checkout/setup-node v4, setup-php v2, configure credentials v4); trước khi dùng ngoài sandbox, review và pin commit SHA được tin cậy. Workflow có timeout ở API waiters/smoke, concurrency theo environment; không cancel deployment đang chạy. Khi smoke fail, `release.py` restore tham số CloudFormation trước đó, tránh drift từ update-service thủ công. Schema migration không bị đảo ngược.

## Drift, môi trường và rollback

Dùng prefix khác cho dev/staging/prod mô phỏng; không chia sẻ DB/cache/bucket/secret. `parameters.py` lấy output foundation của đúng môi trường và tạo file JSON **không chứa secret value**. Kiểm tra file trước create-stack. CloudFormation dynamic references và ECS secret ARN được resolve trong AWS; không giải mã vào file CI.

Trước update hạ tầng lớn: tạo change set, xem replacement DB/bucket/ALB, execution role và retention. Sau thay tay để drill: `detect-stack-drift`, `describe-stack-resource-drifts`, sửa bằng code rồi update; restore không âm thầm import tài nguyên ngoài stack.

## Cleanup retained resources

Trước delete foundation:

```bash
aws cloudformation list-stack-resources --stack-name "$FOUNDATION_STACK" > "$LAB_STATE/foundation-inventory.json"
```

Sau khi runtime/EC2/foundation đã xóa, review bucket/repository còn giữ:

```bash
python3 infra/laravel-order-app/scripts/cleanup-retained.py "$LAB_STATE/foundation-inventory.json"
# Chỉ sau khi xác nhận mọi tên đều là fixture của phiên lab:
python3 infra/laravel-order-app/scripts/cleanup-retained.py "$LAB_STATE/foundation-inventory.json" --apply
```

Script chỉ xử lý S3/ECR trong inventory. RDS snapshot, DB restore, IAM CI role, DNS record ngoài stack vẫn cần kiểm kê riêng. Dọn CI role bằng delete-role-policy rồi delete-role cho **hai role vừa tạo**, giữ OIDC provider có sẵn. Người bootstrap gọi delete-stack runtime; deploy role của GitHub không có cloudformation:DeleteStack. Execution role có quyền cleanup resource runtime hiện tại và phải được giữ đến khi stack xóa xong.

## Kiểm chứng tĩnh

```bash
cfn-lint infra/laravel-order-app/*.yaml
python3 -m compileall -q infra/laravel-order-app/scripts
```

Lint kiểm tra schema/intrinsic, không chứng minh IAM đầy đủ, service availability, image tồn tại, quota, lifecycle restore hoặc deploy thành công. Không tự chạy create-stack chỉ để xác minh từ yêu cầu biên soạn này.
