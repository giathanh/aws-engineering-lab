# Lab 15 — Laravel + Vue, từ EC2 đến ECS và recovery drill

> [Lý thuyết](../../notes/15-capstone-laravel.md) · [Ứng dụng](../../examples/laravel-order-app/README.md) · [CloudFormation](../../infra/laravel-order-app/README.md) · [Bằng chứng](evidence/README.md).
> Biên soạn 2026-11-01. Các thao tác AWS bên dưới là hướng dẫn để người học thực hiện; chưa được chạy trên account thật trong lần biên soạn này.

## Chuẩn bị và ownership

Dùng Bash cho các khối lệnh, chạy từ **repo root** trừ khi ghi rõ trên EC2. Cần AWS CLI v2, jq, Python 3.10+ với boto3, PHP 8.3/Composer, Node 22 và Docker nếu build container. Hoàn thành lab 01–04 và đọc prerequisite theo từng mốc ở bài 15.

Mỗi lần học có ba stack riêng: foundation, EC2 và ECS. Foundation tạo tài nguyên tính tiền ngay, kể cả chưa chạy ứng dụng. Dùng đúng Region, không tái sử dụng stack production. Domain và ACM certificate đã xác thực trong cùng Region là prerequisite của đường HTTPS; account không có domain làm local/lab dịch vụ trước, ghi HTTPS `chưa chạy`.

```bash
export AWS_PROFILE=aws-lab
export AWS_REGION=ap-southeast-1 AWS_DEFAULT_REGION=ap-southeast-1
export FOUNDATION_STACK=orders-dev-foundation
export EC2_STACK=orders-dev-ec2 RUNTIME_STACK=orders-dev-runtime
export APP_ORIGIN=https://orders.example.com   # thay bằng domain bạn quản lý
export CERTIFICATE_ARN='<ARN ACM cùng Region>'
export COGNITO_PREFIX='<prefix-duy-nhat-cua-ban>'
export LAB_STATE=$(mktemp -d)
chmod 700 "$LAB_STATE"
aws sts get-caller-identity
```

Thay toàn bộ placeholder trước khi chạy. `LAB_STATE` lưu tham số/inventory local, không commit. Quyền người tạo stack và role runtime được giải thích trong README hạ tầng. Không dùng `AdministratorAccess` làm cách chữa lỗi permission; đọc event lỗi để xác định action/resource thiếu.

## Chi phí và điều kiện dừng

Tính một buổi 3 giờ, tối đa 2 EC2 **hoặc** 2 API + 2 worker Fargate, thêm một task migration/tick; rollout có thể tạm gấp đôi số service task. Foundation dùng một NAT, một ALB cho mỗi stack compute đang giữ, một RDS 20 GB và một cache node. Không chạy cả EC2 và ECS qua đêm.

Bảng sau là **giả định để làm phép tính**, không phải báo giá Singapore đã xác nhận. Thay đơn giá bằng bảng Region tại các nguồn trước khi deploy, ghi ngày vào evidence:

| Thành phần | Giả định đơn giá cho bài tính | Số lượng | Ước tính 3 giờ |
|---|---|---|---|
| NAT | $0.06/giờ | 1 | $0.18 + xử lý dữ liệu |
| ALB | $0.04/giờ gồm LCU giả định | 1 | $0.12 |
| Public IPv4 | $0.005/IP-giờ | tối thiểu 3 | $0.045 |
| EC2 | $0.025/instance-giờ | 2 | $0.15 |
| RDS | $0.03/giờ | 1 | $0.09 + storage/backup |
| Cache | $0.025/giờ | 1 | $0.075 |
| Fargate thay EC2 | $0.035/task-giờ với 0.5 vCPU/1 GB giả định | 4 | $0.42 + task tạm |

Theo giả định này, phần giờ khoảng $0.66 với EC2 hoặc $0.93 với ECS trong 3 giờ; còn S3/ECR/log/trace/secret/Cognito/request/egress/backup và thuế. Đặt ngân sách buổi **$5** là điều kiện tự dừng, không phải AWS spending cap. Dừng và cleanup sau 3 giờ hoặc khi thấy tài nguyên vượt inventory dự kiến. Không suy chi phí thật từ bảng minh họa.

Nguồn được mở đối chiếu 2026-11-01: [EC2](https://aws.amazon.com/ec2/pricing/on-demand/), [VPC/NAT/IPv4](https://aws.amazon.com/vpc/pricing/), [ALB](https://aws.amazon.com/elasticloadbalancing/pricing/), [RDS PostgreSQL](https://aws.amazon.com/rds/postgresql/pricing/), [Fargate](https://aws.amazon.com/fargate/pricing/), [ElastiCache](https://aws.amazon.com/elasticache/pricing/). Các bảng giá tương tác cần chọn Region thực tế trước khi tính.

## Checkpoint local

Làm theo README ứng dụng; cần tạo đơn, report hoàn tất và test owner isolation đạt trước khi tạo AWS. SQLite/local disk giúp học luồng nghiệp vụ; PostgreSQL/Redis Compose dùng cho concurrency/session. Không dùng kết quả SQLite để tuyên bố đã kiểm chứng row lock PostgreSQL.

```bash
cd examples/laravel-order-app
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
composer test
cd web
npm ci
npm run build
cd ../../..
```

Nếu `.env` đã có, giữ cấu hình và key của bạn, không chạy lại lệnh copy. Bản local có worker riêng; xem README để chạy API/Vue/worker.

## Mốc 1 — Laravel native trên EC2

Để tái lập giữa các buổi, lab dùng template đã có để tạo mạng/dữ liệu, nhưng bạn vẫn phải đọc resource và giải thích route/SG trước khi chạy. Bài 05 độc lập tiếp tục là đường học EC2 bằng CLI thủ công.

```bash
aws cloudformation create-stack --stack-name "$FOUNDATION_STACK" \
  --template-body file://infra/laravel-order-app/foundation.yaml \
  --capabilities CAPABILITY_IAM \
  --parameters ParameterKey=Environment,ParameterValue=dev \
    ParameterKey=AppOrigin,ParameterValue="$APP_ORIGIN" \
    ParameterKey=CognitoPrefix,ParameterValue="$COGNITO_PREFIX"
aws cloudformation wait stack-create-complete --stack-name "$FOUNDATION_STACK"
aws cloudformation describe-stacks --stack-name "$FOUNDATION_STACK" > "$LAB_STATE/foundation.json"
out() { jq -r --arg key "$1" '.Stacks[0].Outputs[]|select(.OutputKey==$key)|.OutputValue' "$LAB_STATE/foundation.json"; }
```

*Kỳ vọng:* `CREATE_COMPLETE`; DB/cache private, app SG chỉ nhận 8080 từ ALB SG. Nếu thất bại: `describe-stack-events`, tìm event lỗi đầu tiên; quota, instance class và service-linked role thường là prerequisite cần sửa. Không tiếp tục bằng output rỗng.

Đóng gói release với vendor theo lockfile và Vue build:

```bash
python3 examples/laravel-order-app/scripts/package.py "$LAB_STATE/app.tgz"
export ARTIFACT_KEY="releases/$(git rev-parse --short HEAD)-$(date -u +%Y%m%d%H%M%S).tgz"
aws s3 cp "$LAB_STATE/app.tgz" "s3://$(out Artifacts)/$ARTIFACT_KEY"
export AMI_ID=$(aws ssm get-parameter \
  --name /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id \
  --query Parameter.Value --output text)
python3 infra/laravel-order-app/scripts/parameters.py "$FOUNDATION_STACK" --mode ec2 > "$LAB_STATE/ec2-parameters.json"
aws cloudformation create-stack --stack-name "$EC2_STACK" \
  --template-body file://infra/laravel-order-app/ec2.yaml \
  --parameters "file://$LAB_STATE/ec2-parameters.json"
aws cloudformation wait stack-create-complete --stack-name "$EC2_STACK"
```

AMI parameter phải tồn tại ở Region đã chọn; xác nhận `describe-images` architecture `x86_64`, owner Canonical và image available. Nếu không, lấy AMI Ubuntu 24.04 chính thức cùng Region rồi đặt `AMI_ID`; không thay bằng AMI lạ để bỏ qua bước xác minh.

Lấy ASG rồi instance ID theo tag Project của stack; mở Session Manager:

```bash
export INSTANCE_ID=$(aws ec2 describe-instances --filters "Name=tag:Project,Values=$EC2_STACK" Name=instance-state-name,Values=running --query 'Reservations[0].Instances[0].InstanceId' --output text)
aws ssm start-session --target "$INSTANCE_ID"
```

**Trên EC2**:

```bash
sudo cloud-init status --wait
cd /var/www/app
sudo -u www-data php artisan migrate --seed --force
curl -i http://localhost:8080/up
curl -i http://localhost:8080/api/ready
sudo systemctl status php8.3-fpm nginx --no-pager
```

*Kỳ vọng:* `/up` và `/api/ready` đều 200 sau migration. `.env` là root:www-data 0640, secret được lấy qua instance role. Tắt FPM bằng `sudo systemctl stop php8.3-fpm`: Nginx trả 502; xem `/var/log/nginx/error.log`, khôi phục `sudo systemctl start php8.3-fpm`. Reboot qua SSM/EC2 rồi kiểm tra `/up` lại. Không restart worker trước khi schema sẵn sàng.

## Mốc 2 — RDS, S3 và Vue với Cognito

Foundation đã tạo RDS, bucket private và Cognito. App dùng PostgreSQL ngay ở đường AWS; bước chuyển từ local SQLite là chạy migration/seed vào DB mới, không copy file SQLite lên RDS. Fixture DB user `orders` là master do RDS quản lý secret để giảm bootstrap; trước production phải tách migration role với runtime SQL role giới hạn DML.

1. Lấy ALB DNS bằng `describe-stacks --stack-name "$EC2_STACK"`; trỏ domain `APP_ORIGIN` đến ALB bằng Alias Route 53 hoặc CNAME của subdomain. ACM cert phải khớp hostname. Kiểm tra `curl -I "$APP_ORIGIN/up"` trả 200 và HTTP redirect sang HTTPS.
2. Trong Cognito user pool `$(out Pool)`, tạo hai user test với **Suppress invitation**, không gửi SMS/email. Đặt password riêng trong môi trường cá nhân; dùng hosted UI đổi temporary password khi đăng nhập. Không ghi password vào repo.
3. Mở Vue bằng đúng `APP_ORIGIN`, đăng nhập user thứ nhất. Tạo đơn, logout, đăng nhập user thứ hai: danh sách không chứa đơn của user thứ nhất. API đọc trực tiếp UUID của người khác phải trả 404.
4. Upload PNG/JPEG nhỏ: Vue gọi endpoint cấp URL, PUT trực tiếp S3, rồi verify. Với local disk sẽ trả 409; ở AWS phải thành công. `uploads/` vẫn private: anonymous GET trực tiếp S3 và CloudFront phải bị từ chối.
5. Trong DevTools Network, giữ một URL đã hết 2 phút rồi thử PUT lại: 403; ký mới nếu cần. Không đưa URL này vào evidence. Cố gửi text với `Content-Type: image/png`: PUT có thể thành công nhưng verify phải trả 422.

S3 CORS origin phải đúng cả scheme/host/port. Sai CORS gây lỗi browser, không nhất thiết có nghĩa IAM đã chặn `curl`. `AccessDenied` trong backend cần xem instance role/resource prefix; không cấp `s3:*` toàn account.

## Mốc 3 — Hai instance, HTTPS và ASG

Đổi `DesiredCount` trong file ec2 parameters từ 1 thành 2, update stack với cùng template:

```bash
jq 'map(if .ParameterKey=="DesiredCount" then .ParameterValue="2" else . end)' "$LAB_STATE/ec2-parameters.json" > "$LAB_STATE/ec2-two.json"
aws cloudformation update-stack --stack-name "$EC2_STACK" --use-previous-template --parameters "file://$LAB_STATE/ec2-two.json"
aws cloudformation wait stack-update-complete --stack-name "$EC2_STACK"
```

Target group cần có hai target healthy. Cả hai instance lấy cùng APP_KEY/DB/cache/bucket. Không chạy seed trên mọi máy. Lưu access token của user test vào biến `ACCESS_TOKEN` bằng nhập kín trong shell; không dán vào tài liệu. Chỉ dùng token còn hạn, đúng issuer/client/scope của stack này.

```bash
read -rs -p 'Access token của user test: ' ACCESS_TOKEN; echo
export ACCESS_TOKEN BASE_URL="$APP_ORIGIN"
for n in 1 2 3 4; do curl -fsS -c "$LAB_STATE/cookies" -b "$LAB_STATE/cookies" -H "Authorization: Bearer $ACCESS_TOKEN" "$BASE_URL/api/session"; echo; done
```

*Kỳ vọng:* visits tăng dù hostname target thay đổi. Nếu hostname chưa đổi, chạy thêm vài request; không bật stickiness chỉ để che lỗi session local. Cookie dùng HTTPS và cùng APP_KEY.

Chọn **một instance có tag đúng stack**, terminate bằng `aws ec2 terminate-instances --instance-ids "$INSTANCE_ID"`; theo dõi target health và request lỗi có timestamp. ASG phải thay máy mới; dữ liệu đơn/upload còn nguyên. Ghi thời gian mất target/khôi phục, không điền số giả. Kiểm tra giới hạn một NAT/Single-AZ DB trước khi kết luận HA.

## Mốc 4 — Cache, worker, DLQ và lịch

Trên mỗi EC2 dùng làm worker: `sudo systemctl enable --now order-worker`. Worker có thể chạy ở máy riêng về sau; API không chạy job nặng trong request.

```bash
BASE_URL="$APP_ORIGIN" python3 examples/laravel-order-app/scripts/smoke.py
```

*Kỳ vọng:* tạo/đọc đơn, gửi report với cùng key hai lần, cùng report ID, tải kết quả có đơn vừa tạo. Dừng worker rồi gửi report: status pending; khởi động lại thì complete. Kill process worker giữa tác vụ và gửi lại cùng key: chỉ một report trong DB, object key không đổi. Có thể phát sinh thêm S3 version.

Cache: gọi GET cùng đơn hai lần, PATCH quantity rồi GET lại phải thấy giá trị mới khi invalidation thành công. Thử lỗi cache trên fixture bằng tạm cấu hình `REDIS_HOST=127.0.0.1` ở EC2 rồi restart FPM; khôi phục ngay sau thử. Order detail có fallback DB, nhưng session và JWT JWKS cache có thể fail-closed; không kết luận mọi endpoint phải vẫn 200.

Poison job: trong môi trường riêng, tạm bỏ quyền PutObject `reports/*` của **instance role lab**, gửi một report và chờ đủ số lần nhận. SQS queue visibility 180 giây, redrive 5 lần; có thể cần nhiều phút. Xem DLQ visible tăng, report chưa complete. Khôi phục quyền trước khi redrive; dùng `start-message-move-task` từ DLQ sang source queue với tốc độ 1 msg/s và kiểm tra report hoàn tất. Không chỉnh role dùng chung. Nếu dùng Laravel finite tries, kiểm tra `failed_jobs` thay vì kỳ vọng DLQ.

Scheduler được bật ở mốc 6 bằng ECS RunTask. Trước đó có thể thử một lần trên EC2: `sudo -u www-data php artisan reports:tick 2026-11-01T03:00:00Z`, chạy lại cùng slot; số report không tăng lần hai. Đây là kiểm chứng command/idempotency, chưa phải bằng chứng Scheduler AWS đã chạy.

## Mốc 5 — Quan sát và secret

EC2: xem Nginx/FPM/systemd logs bằng SSM. Bước collector tự động ở ECS mốc 6; trước đó tracing AWS ghi `chưa chạy`. Đọc cấu hình `docker/otel.yaml` và helper Telemetry để biết span nào được tạo.

Secret dev/prod: trên EC2, instance role đọc AppKey được nhưng không đọc ProdCanary. Không in secret ra màn hình:

```bash
# Trên EC2; đặt ARN từ output foundation, không phải giá trị secret
python3 - <<'PY'
import boto3, os
sm=boto3.client('secretsmanager',region_name=os.environ['AWS_REGION'])
for variable in ['DEV_SECRET_ARN','OTHER_SECRET_ARN']:
    try: sm.get_secret_value(SecretId=os.environ[variable]); print(variable, 'ALLOWED')
    except sm.exceptions.ClientError as e: print(variable,e.response['Error']['Code'])
PY
```

Export AWS_REGION/DEV_SECRET_ARN/OTHER_SECRET_ARN ở shell EC2 trước khi chạy. Kỳ vọng AppKey ALLOWED, ProdCanary AccessDenied. Với ECS, execution role đọc secret để inject; task role cố đọc trực tiếp cả hai đều bị từ chối theo template. Muốn kiểm chứng allow/deny ở execution role dùng IAM simulation có đúng context hoặc một task test riêng, không nhầm quyền người deploy với quyền app.

Tạo lỗi HTTP 500 và slow SQL: bật `LAB_FAULTS=true` **chỉ cho fixture** (EC2 `.env` rồi restart; ECS thêm env task definition qua template và update). Dùng token gọi `/api/fault/500`, `/api/fault/slow`. Tìm log theo X-Request-ID, metric latency/error, trace HTTP→DB, rồi khôi phục `LAB_FAULTS=false`. Alarm không gắn SNS mặc định, xem state trong CloudWatch; không giả định có email thông báo.

## Mốc 6 — ECS, Scheduler và pipeline

Build/push ba image theo [README hạ tầng](../../infra/laravel-order-app/README.md), đặt `APP_IMAGE`, `NGINX_IMAGE`, `TELEMETRY_IMAGE` bằng digest. Sau đó:

```bash
python3 infra/laravel-order-app/scripts/parameters.py "$FOUNDATION_STACK" > "$LAB_STATE/runtime.json"
aws cloudformation create-stack --stack-name "$RUNTIME_STACK" \
  --template-body file://infra/laravel-order-app/runtime.yaml --capabilities CAPABILITY_IAM \
  --parameters "file://$LAB_STATE/runtime.json"
aws cloudformation wait stack-create-complete --stack-name "$RUNTIME_STACK"
```

Ban đầu DesiredCount=0. Trỏ DNS sang ALB runtime trước smoke test; EC2 có thể giữ tạm để chuyển đổi nhưng không chạy Scheduler trùng. `release.py` chạy migration bằng task một lần trước khi cập nhật service lên 2 API/2 worker:

```bash
python3 infra/laravel-order-app/scripts/release.py
```

*Kỳ vọng:* migration exit 0, service stable, smoke test PASS, image digest đúng build. Nếu app khởi động nhưng smoke fail, script cập nhật lại các tham số runtime trước đó. Lần deploy đầu trước đó có desired=0: rollback về 0 là đúng, không phải ứng dụng cũ đang phục vụ. Migration đã chạy vẫn còn; phải tương thích ngược.

Sau khi ổn định, lấy runtime parameters hiện tại rồi đổi ScheduleState=ENABLED (giữ các image/digest và DesiredCount hiện tại), update stack. Mỗi 5 phút Scheduler chạy một task `reports:tick`; xem task exit code và reports. Cố tình dùng một task revision không có quyền target trong fixture riêng để thấy Scheduler delivery error/DLQ; với command bên trong task lỗi, xem ECS stopped task/log thay vì kỳ vọng Scheduler DLQ.

Kiểm tra tracing: tạo report từ Vue, mở X-Ray và tìm span HTTP/report.export/storage.put chung trace. Nếu chỉ thấy HTTP, kiểm tra carrier trong outbox và OTEL env worker; nếu không có trace, kiểm tra collector log, port 4318, IAM xray và đường outbound.

CI/CD: workflow chỉ deploy khi chạy thủ công, checkbox deploy=true, branch main và GitHub environment dev. Cấu hình environment theo README hạ tầng. PR chỉ test/build. Token smoke là user test có hạn ngắn, nhập vào environment secret trước phiên deploy và xóa sau phiên; không dùng account thật hoặc AWS access key.

Negative case bắt buộc: (1) image sai port/FPM không lên, ECS circuit breaker xử lý; (2) image healthy nhưng API report sai, business smoke kích hoạt rollback. Ghi cả task definition trước/sau và kết quả sau khôi phục. Graceful worker stop phải đủ hoàn thành job hoặc để message được nhận lại an toàn.

## Mốc 7 — Tái tạo, load test và restore

**Tái tạo:** dùng tên stack foundation mới (ví dụ orders-staging-foundation), Cognito prefix/domain riêng, cùng template và image digest. Chạy lại bootstrap/migration/smoke. Không import âm thầm tài nguyên tạo tay từ bài 05–14. Domain/certificate là đầu vào cấp ngoài; mọi tài nguyên ứng dụng khác nằm trong template.

**Load test:** chỉ đặt BASE_URL đến endpoint lab mình sở hữu:

```bash
python3 examples/laravel-order-app/scripts/load.py > "$LAB_STATE/load.jsonl"
```

Script có baseline/ramp/steady/recovery, tối đa 4 concurrent, timeout 3 giây và dừng khi hơn 20% lỗi trong 20 mẫu gần nhất. Lưu count/p95/p99/error; script không tính percentile từ average. Xem saturation PHP-FPM, DB connections và queue backlog cùng thời điểm. Không tăng max task trước khi tìm bottleneck.

**Dependency drill:** bật LAB_FAULTS cho fixture PostgreSQL. Gọi `/api/probe?mode=slow&resilient=0` rồi `...&resilient=1` vài lần, so latency/attempts/503. Sau ba failure, circuit mở 15 giây; gọi mode=ok sau cooldown để thấy phục hồi. Tạo nhiều request đồng thời để thấy 429 từ bulkhead. Load script sẽ dừng sớm khi lỗi vượt ngưỡng; đây là điều kiện dừng đúng, không bỏ giới hạn để kéo dài drill.

**Recovery drill:** snapshot nguồn, tạo thêm đơn làm watermark sau snapshot, rồi restore vào DB khác. Không xóa nguồn để mô phỏng sự cố.

```bash
export SOURCE_DB=$(out Database)
export SNAPSHOT_ID="orders-dr-$(date -u +%Y%m%d%H%M%S)"
aws rds create-db-snapshot --db-instance-identifier "$SOURCE_DB" --db-snapshot-identifier "$SNAPSHOT_ID"
aws rds wait db-snapshot-available --db-snapshot-identifier "$SNAPSHOT_ID"
# Tạo một đơn mới qua Vue và ghi timestamp/UUID làm watermark; sau đó bắt đầu đồng hồ sự cố.
export RESTORE_DB="orders-restore-$(date -u +%Y%m%d%H%M%S)"
date -u +%FT%TZ > "$LAB_STATE/recovery-start.txt"
aws rds restore-db-instance-from-db-snapshot --db-instance-identifier "$RESTORE_DB" \
  --db-snapshot-identifier "$SNAPSHOT_ID" --db-instance-class db.t4g.micro \
  --db-subnet-group-name "$(out DBSubnets)" --vpc-security-group-ids "$(out DBSG)" \
  --no-publicly-accessible --no-multi-az
aws rds wait db-instance-available --db-instance-identifier "$RESTORE_DB"
```

Tạo runtime **recovery** riêng DesiredCount=0 với DBHost bản restore và DBSecret tương ứng, cùng mạng/role được mở đúng secret ARN; không đổi runtime chính. Restore có thể tạo managed secret mới; kiểm tra `MasterUserSecret.SecretArn`, cập nhật execution role/EC2 role cho secret của bản restore. Nếu không có managed secret, cấu hình quản lý password cho DB restore theo lab 07 trước khi chạy task. Dùng app test/migration task trên runtime recovery để query tập UUID và snapshot JSON trước khi chạy smoke tạo dữ liệu mới.

Ghi dữ liệu trước snapshot có đủ; watermark sau snapshot có thể mất đúng theo recovery point. Chạy smoke qua domain recovery (Cognito callback phải thêm origin recovery hoặc dùng token API cùng user pool), ghi thời gian kết thúc. RTO bao gồm thời gian đổi cấu hình và kiểm tra này; RPO dùng watermark chứ không chỉ số lượng rows.

**ADR cuối:** ít nhất một lựa chọn thay thế cho EC2/ECS/Lambda, RDS/Aurora/DynamoDB, S3/EFS, cache, queue, HA/DR. Đánh giá vận hành, bảo mật, độ tin cậy, hiệu năng, chi phí, tính bền vững; dẫn evidence đo được và việc chưa thử. Không gọi cấu hình tiết kiệm Single-AZ là production HA.

## Cleanup và dừng giữa chừng

Lưu inventory trước khi xóa. Nếu mất shell, khôi phục bằng `describe-stacks` theo ba tên stack; không tìm mọi tài nguyên account rồi xóa hàng loạt.

1. Disable ScheduleState qua update runtime hoặc xóa runtime stack; chờ task tick đang chạy kết thúc. Không xóa queue khi scheduler còn gửi.
2. Xóa runtime recovery nếu có, rồi runtime chính và EC2 stack. Các thao tác dưới đây chỉ áp dụng stack do phiên lab tạo:

```bash
aws cloudformation list-stack-resources --stack-name "$RUNTIME_STACK" > "$LAB_STATE/runtime-inventory.json"
aws cloudformation delete-stack --stack-name "$RUNTIME_STACK"
aws cloudformation wait stack-delete-complete --stack-name "$RUNTIME_STACK"
aws cloudformation delete-stack --stack-name "$EC2_STACK"
aws cloudformation wait stack-delete-complete --stack-name "$EC2_STACK"
```

3. Xóa DB restore sau khi đã thu evidence: `aws rds delete-db-instance --db-instance-identifier "$RESTORE_DB" --skip-final-snapshot --delete-automated-backups`; chờ db-instance-deleted. Chỉ bỏ snapshot cuối cho bản restore dùng dữ liệu giả.
4. Lưu foundation inventory và outputs rồi delete-stack foundation, chờ hoàn tất. Template giữ **bucket, repository** và tạo **RDS final snapshot**; chúng vẫn có thể tính phí.
5. Với bucket thuộc đúng lab, kiểm kê versions/delete markers rồi xóa bằng script `cleanup-retained.py` trong README hạ tầng. `aws s3 rm --recursive` không xóa hết version. Dọn ECR image/repository và snapshot lab sau khi xác nhận không cần cho buổi sau.
6. Kiểm tra EC2/ASG, ALB, NAT, EIP, RDS, cache, ECS service/task, Scheduler, queues, log groups, CloudFront và IAM roles theo inventory. `DELETE_FAILED` phải đọc event để xử lý dependency; không coi command trả về là đã dọn xong.
7. Domain record, ACM certificate và OIDC provider có trước lab được giữ. Xóa token smoke trên GitHub, logout Cognito; secret bị scheduled deletion được ghi lại thời hạn. Billing có độ trễ, không dùng làm bằng chứng duy nhất.

## Troubleshooting và nghiệm thu

| Triệu chứng | Thu thập | Cách sửa có phạm vi nhỏ |
|---|---|---|
| EC2 không xuất hiện SSM | instance profile, route NAT, cloud-init, SSM agent | Sửa profile/egress/agent; không mở SSH ra Internet |
| ALB 502 | nginx error, FPM socket/port, process | Khớp socket native hoặc loopback container; restart service lỗi |
| ALB 503 | target health/reason, bootstrap log | Chờ đủ boot, sửa health path; không bỏ health check |
| API 401 | token_use/issuer/client/scope/expiry, JWKS fetch | Đăng nhập lại đúng pool; không chuyển AUTH_MODE local trên AWS |
| Report pending | outbox, queue, worker log, S3 permission | Dispatch lại outbox; sửa quyền prefix, redrive có kiểm soát |
| ECS cannot pull image | image digest, execution role, NAT, ECR | Sửa đường outbound/quyền đúng repo, không gán public IP mặc định |
| Trace rời rạc | carrier, SDK/collector log | Kiểm tra propagation và exporter, không ghi token vào trace |
| Cleanup kẹt | stack events, ENI dependency | Chờ service xóa ENI, dọn theo owner; giữ inventory lỗi |

Nộp API smoke, owner negative test, duplicate job/worker crash, session qua target, S3 lỗi, tracing, secret deny, rollout/rollback, restore watermark/RTO/RPO, load test, ADR và inventory sau cleanup. Đánh dấu từng mục `đã chạy / chưa chạy / thất bại`; output minh họa không được dùng làm evidence.
