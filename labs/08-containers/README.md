# Lab 08 — Container: ECR, ECS Fargate sau ALB, rolling deploy, rollback và graceful shutdown

> **Lý thuyết:** [notes/08-containers.md](../../notes/08-containers.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 120–150 phút (deploy và rollback mỗi lần mất 5–10 phút).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output và số đo là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Mạng lab riêng: 2 public subnet (ALB, NAT), 2 private subnet (task), 1 NAT Gateway | Dùng Default VPC hoặc gán public IP cho task |
| ECR: tag immutable, scan on push, lifecycle; negative case push đè tag | Dùng tag `latest` |
| Image `linux/arm64`, chạy user không phải root; task definition **pin theo digest** | Bake secret hoặc access key vào image |
| Execution role và task role tách biệt | Gắn quyền ứng dụng vào execution role |
| ECS service Fargate, `desiredCount=2`, circuit breaker + rollback | EKS, EC2 launch type |
| Rolling deploy v1 → v2 trong khi đo lỗi request liên tục | Load test lưu lượng lớn |
| Negative case: bản v3 sai cổng → health check fail → tự rollback | |
| Scale-in để quan sát draining → `SIGTERM` → thoát sạch | |
| Cleanup theo dependency, kiểm tra inventory | Để NAT/ALB chạy qua đêm |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `ACCOUNT_ID` | Account ID (lấy tự động) | `111122223333` |
| `VPC_ID`, `PUB_SUB_*`, `PRI_SUB_*`, `NAT_ID` | Mạng lab | `vpc-0123456789abcdef0` |
| `ECR_URI` | URI repository | `111122223333.dkr.ecr.ap-southeast-1.amazonaws.com/lab08-order-api` |
| `DIGEST_V1`, `DIGEST_V2` | Digest image | `sha256:7b5d...` |
| `TD_V1`, `TD_V2`, `TD_V3` | ARN task definition theo revision | `arn:aws:ecs:...:task-definition/lab08-order-api:1` |
| `TG_ARN`, `ALB_ARN`, `ALB_DNS` | Target group, ALB | `lab08-alb-123.ap-southeast-1.elb.amazonaws.com` |

Tên cố định: `lab08-*` cho mọi tài nguyên, `Lab08TaskExecutionRole`/`Lab08TaskRole` cho IAM. Mọi tài nguyên hỗ trợ tag đều mang `Project=aws-lab-08`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 05](../05-compute-and-load-balancing/README.md) (ALB, target group, NAT). Lab này tự tạo mạng riêng theo cùng mô hình.
- [ ] Docker Desktop/Engine đang chạy (`docker version`), hỗ trợ `--platform linux/arm64` (Apple Silicon build native; máy Intel cần buildx/QEMU, Docker Desktop có sẵn). Cài `jq`, `curl`.
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **ECR:** `ecr:CreateRepository`, `PutLifecyclePolicy`, `DescribeRepositories`, `DescribeImages`, `DescribeImageScanFindings`, `GetAuthorizationToken`, `InitiateLayerUpload`, `UploadLayerPart`, `CompleteLayerUpload`, `BatchCheckLayerAvailability`, `PutImage`, `DeleteRepository`, `TagResource`.
  - **ECS:** `ecs:CreateCluster`, `RegisterTaskDefinition`, `DeregisterTaskDefinition`, `DeleteTaskDefinitions`, `ListTaskDefinitions`, `CreateService`, `UpdateService`, `DeleteService`, `DescribeServices`, `ListTasks`, `DescribeTasks`, `DeleteCluster`, `TagResource`.
  - **IAM:** `iam:CreateRole`, `AttachRolePolicy`, `DetachRolePolicy`, `DeleteRole`, `GetRole`, `TagRole`, **`iam:PassRole` trên `Lab08TaskExecutionRole` và `Lab08TaskRole`** (bắt buộc khi đăng ký task definition/tạo service), `iam:CreateServiceLinkedRole` nếu account chưa từng dùng ECS/ELB.
  - **EC2/VPC, ELB:** như [Lab 05](../05-compute-and-load-balancing/README.md) (mạng, NAT, EIP, SG, ALB, target group).
  - **CloudWatch Logs:** `logs:CreateLogGroup`, `PutRetentionPolicy`, `FilterLogEvents`, `DeleteLogGroup`.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại [Fargate pricing](https://aws.amazon.com/fargate/pricing/) cho Region của bạn)

| Tài nguyên | Số lượng | Đơn giá minh họa | Mỗi giờ |
|---|---|---|---|
| NAT Gateway (giờ) | 1 | ~$0.059/giờ | ~$0.059 |
| Public IPv4 (EIP của NAT + 2 IP của ALB) | 3 | ~$0.005/giờ/IP | ~$0.015 |
| ALB (giờ + ~1 LCU) | 1 | ~$0.0252 + ~$0.008 | ~$0.033 |
| Fargate ARM 0,25 vCPU / 0,5 GB | 2 (tối đa 4 lúc deploy) | ~$0.01/task-giờ | ~$0.02–0.04 |
| NAT data processing (pull image ~50 MB × vài lần) | < 1 GB | ~$0.059/GB | < $0.05 tổng |
| ECR storage + CloudWatch Logs | < 200 MB | ~$0.10/GB-tháng | < $0.01 |
| **Tổng** | | | **~$0.13–0.15/giờ** |

- Làm lab 2,5 giờ: khoảng **$0.35–0.40**.
- **Quên xóa 1 tháng: khoảng $95–110**, trong đó NAT + ALB chiếm phần lớn.
- **Điều kiện dừng:** nếu sau 3 giờ chưa xong, chạy cleanup (Bước 11) rồi làm lại sau.

---

## Sơ đồ kiến trúc Lab 08

```text
Laptop ── docker push (linux/arm64) ──▶ ECR lab08-order-api (IMMUTABLE, scan on push)
                                              ▲ pull theo digest (qua NAT)
┌──────────────────── VPC lab08 10.80.0.0/16 ─┼──────────────────────────────────┐
│ Public A 10.80.1.0/24      Public B .2.0/24 │                                  │
│ ┌──────────────────────────────────────┐    │   ┌─────────┐                    │
│ │ ALB lab08-alb :80 (lab08-alb-sg)     │    │   │ NAT GW  │◀── 0.0.0.0/0 ──┐   │
│ └──────────────┬───────────────────────┘    │   └─────────┘                │   │
│                │ target type ip, :8080      │                              │   │
│ Private A 10.80.10.0/24     Private B 10.80.20.0/24                        │   │
│ ┌──────────────▼───────────┐  ┌──────────────────────────┐                 │   │
│ │ Task (awsvpc ENI)        │  │ Task (awsvpc ENI)        │─────────────────┘   │
│ │ lab08-task-sg: 8080 từ   │  │ không public IP          │                     │
│ │ lab08-alb-sg             │  │                          │──▶ CloudWatch Logs  │
│ └──────────────────────────┘  └──────────────────────────┘                     │
└────────────────────────────────────────────────────────────────────────────────┘
ECS service lab08-order-api: desired 2, min 100%, max 200%, circuit breaker + rollback
Execution role: pull ECR + ghi log │ Task role: rỗng (app không gọi AWS)
```

Task trong private subnet ra ECR, S3 (layer) và CloudWatch Logs qua NAT. Phương án không cần NAT là VPC endpoint cho `ecr.api`, `ecr.dkr`, `logs` (interface) và `s3` (gateway); với 2 AZ, 3 interface endpoint tốn khoảng $0.08/giờ, đắt hơn một NAT trong lab nhỏ này (xem lý thuyết Phần 7).

---

## Bước 0 — Biến và mạng lab

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
TAG="{Key=Project,Value=aws-lab-08}"
ec2() { aws ec2 "$@"; }

read -r AZ_A AZ_B < <(ec2 describe-availability-zones \
  --filters Name=zone-type,Values=availability-zone Name=state,Values=available \
  --query 'AvailabilityZones[0:2].ZoneName' --output text)

VPC_ID=$(ec2 create-vpc --cidr-block 10.80.0.0/16 \
  --tag-specifications "ResourceType=vpc,Tags=[{Key=Name,Value=lab08-vpc},${TAG}]" --query Vpc.VpcId --output text)
ec2 modify-vpc-attribute --vpc-id "${VPC_ID}" --enable-dns-hostnames '{"Value": true}'
IGW_ID=$(ec2 create-internet-gateway \
  --tag-specifications "ResourceType=internet-gateway,Tags=[{Key=Name,Value=lab08-igw},${TAG}]" \
  --query InternetGateway.InternetGatewayId --output text)
ec2 attach-internet-gateway --vpc-id "${VPC_ID}" --internet-gateway-id "${IGW_ID}"

mksub() { ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block "$1" --availability-zone "$2" \
  --tag-specifications "ResourceType=subnet,Tags=[{Key=Name,Value=$3},${TAG}]" --query Subnet.SubnetId --output text; }
PUB_SUB_A=$(mksub 10.80.1.0/24  "${AZ_A}" lab08-public-a)
PUB_SUB_B=$(mksub 10.80.2.0/24  "${AZ_B}" lab08-public-b)
PRI_SUB_A=$(mksub 10.80.10.0/24 "${AZ_A}" lab08-private-a)
PRI_SUB_B=$(mksub 10.80.20.0/24 "${AZ_B}" lab08-private-b)

mkrt() { ec2 create-route-table --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=$1},${TAG}]" --query RouteTable.RouteTableId --output text; }
PUB_RT_ID=$(mkrt lab08-public-rt)
ec2 create-route --route-table-id "${PUB_RT_ID}" --destination-cidr-block 0.0.0.0/0 --gateway-id "${IGW_ID}" >/dev/null
ec2 associate-route-table --subnet-id "${PUB_SUB_A}" --route-table-id "${PUB_RT_ID}" >/dev/null
ec2 associate-route-table --subnet-id "${PUB_SUB_B}" --route-table-id "${PUB_RT_ID}" >/dev/null

# NAT Gateway: bắt đầu tính phí từ đây
EIP_ALLOC_ID=$(ec2 allocate-address --domain vpc \
  --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=lab08-nat-eip},${TAG}]" --query AllocationId --output text)
NAT_ID=$(ec2 create-nat-gateway --subnet-id "${PUB_SUB_A}" --allocation-id "${EIP_ALLOC_ID}" \
  --tag-specifications "ResourceType=natgateway,Tags=[{Key=Name,Value=lab08-nat},${TAG}]" --query NatGateway.NatGatewayId --output text)
ec2 wait nat-gateway-available --nat-gateway-ids "${NAT_ID}"
PRI_RT_ID=$(mkrt lab08-private-rt)
ec2 create-route --route-table-id "${PRI_RT_ID}" --destination-cidr-block 0.0.0.0/0 --nat-gateway-id "${NAT_ID}" >/dev/null
ec2 associate-route-table --subnet-id "${PRI_SUB_A}" --route-table-id "${PRI_RT_ID}" >/dev/null
ec2 associate-route-table --subnet-id "${PRI_SUB_B}" --route-table-id "${PRI_RT_ID}" >/dev/null

mksg() { ec2 create-security-group --vpc-id "${VPC_ID}" --group-name "$1" --description "$1" \
  --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=$1},${TAG}]" --query GroupId --output text; }
SG_ALB_ID=$(mksg lab08-alb-sg)
SG_TASK_ID=$(mksg lab08-task-sg)
ec2 authorize-security-group-ingress --group-id "${SG_ALB_ID}" --protocol tcp --port 80 --cidr 0.0.0.0/0 >/dev/null
ec2 authorize-security-group-ingress --group-id "${SG_TASK_ID}" --protocol tcp --port 8080 --source-group "${SG_ALB_ID}" >/dev/null
echo "VPC=${VPC_ID} NAT=${NAT_ID} SG_ALB=${SG_ALB_ID} SG_TASK=${SG_TASK_ID}"
```

*Kỳ vọng:* in đủ ID, NAT `available`. Giải thích từng lệnh mạng xem [Lab 04](../04-networking/README.md) và [Lab 05](../05-compute-and-load-balancing/README.md).

---

## Bước 1 — ECR repository

```bash
export ECR_URI=$(aws ecr create-repository --repository-name lab08-order-api \
  --image-tag-mutability IMMUTABLE \
  --image-scanning-configuration scanOnPush=true \
  --tags Key=Project,Value=aws-lab-08 \
  --query repository.repositoryUri --output text)
echo "ECR_URI=${ECR_URI}"

cat > ecr-lifecycle.json << 'EOF'
{
  "rules": [
    { "rulePriority": 1, "description": "Xóa image không tag sau 1 ngày",
      "selection": { "tagStatus": "untagged", "countType": "sinceImagePushed", "countUnit": "days", "countNumber": 1 },
      "action": { "type": "expire" } },
    { "rulePriority": 2, "description": "Giữ 10 image mới nhất",
      "selection": { "tagStatus": "any", "countType": "imageCountMoreThan", "countNumber": 10 },
      "action": { "type": "expire" } }
  ]
}
EOF
aws ecr put-lifecycle-policy --repository-name lab08-order-api \
  --lifecycle-policy-text file://ecr-lifecycle.json >/dev/null
```

Production thường giữ lại image đang chạy và vài bản trước để rollback; lifecycle "giữ 10 bản" không biết image nào đang được service dùng, nên đặt số đủ lớn.

---

## Bước 2 — Build, push và pin theo digest

### 1. Ứng dụng có xử lý `SIGTERM`

```bash
mkdir -p app && cd app
cat > server.js << 'EOF'
const http = require('http');
const os = require('os');

const PORT = Number(process.env.PORT || 8080);
const VERSION = process.env.APP_VERSION || 'dev';
const log = (msg, extra = {}) => console.log(JSON.stringify({ msg, version: VERSION, task: os.hostname(), ...extra }));

const server = http.createServer((req, res) => {
  if (req.url === '/health') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end('{"status":"UP"}');
  }
  if (req.url === '/api/orders') {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    return res.end(JSON.stringify({ version: VERSION, task: os.hostname() }));
  }
  res.writeHead(404);
  res.end('Not Found');
});

process.on('SIGTERM', () => {
  log('SIGTERM nhận được, ngừng nhận kết nối mới');
  server.close(() => { log('đã đóng hết kết nối, thoát 0'); process.exit(0); });
  server.closeIdleConnections();           // keep-alive từ ALB sẽ giữ server.close() mãi nếu không đóng
  setTimeout(() => { log('quá 20s, thoát 1'); process.exit(1); }, 20000).unref();
});

server.listen(PORT, '0.0.0.0', () => log('đang lắng nghe', { port: PORT }));
EOF

cat > Dockerfile << 'EOF'
FROM node:22-alpine
ARG APP_VERSION=dev
ENV APP_VERSION=${APP_VERSION} NODE_ENV=production
WORKDIR /app
COPY server.js .
USER node
EXPOSE 8080
# Exec form: node là PID 1 và nhận SIGTERM trực tiếp. Shell form ("CMD node server.js") sẽ để /bin/sh làm PID 1 và nuốt tín hiệu.
CMD ["node", "server.js"]
EOF
```

### 2. Build `linux/arm64` và push hai phiên bản

```bash
aws ecr get-login-password | docker login --username AWS --password-stdin "${ECR_URI%%/*}"

build_push() {  # $1 = tag, $2 = APP_VERSION
  docker build --platform linux/arm64 --provenance=false \
    --build-arg APP_VERSION="$2" -t "${ECR_URI}:$1" . &&
  docker push "${ECR_URI}:$1" >/dev/null &&
  aws ecr describe-images --repository-name lab08-order-api --image-ids imageTag="$1" \
    --query 'imageDetails[0].imageDigest' --output text
}
DIGEST_V1=$(build_push v1 1.0.0)
DIGEST_V2=$(build_push v2 2.0.0)
cd ..
echo "V1=${DIGEST_V1}"; echo "V2=${DIGEST_V2}"
```

*Kỳ vọng:* hai digest `sha256:...` khác nhau. Task definition sẽ dùng `${ECR_URI}@${DIGEST}` thay cho tag: dù ai đó xóa và push lại tag, task vẫn chạy đúng byte đã kiểm tra.

### 3. Negative case: push đè tag immutable

```bash
cd app && docker build --platform linux/arm64 --provenance=false --build-arg APP_VERSION=hack \
  -t "${ECR_URI}:v1" . -q && docker push "${ECR_URI}:v1"; cd ..
```

*Kỳ vọng:* push thất bại với thông điệp dạng `tag invalid: The image tag 'v1' already exists in the 'lab08-order-api' repository and cannot be overwritten because the repository is immutable.`

### 4. Kết quả scan

```bash
aws ecr wait image-scan-complete --repository-name lab08-order-api --image-id imageTag=v2
aws ecr describe-image-scan-findings --repository-name lab08-order-api --image-id imageTag=v2 \
  --query 'imageScanFindings.findingSeverityCounts'
```

*Kỳ vọng:* một object đếm theo mức độ (có thể rỗng `{}` hoặc vài `LOW`/`MEDIUM` từ base image). Basic scanning chỉ quét package hệ điều hành; nếu account đã bật Amazon Inspector enhanced scanning, lệnh này trả kết quả khác và có thể tính phí Inspector.

---

## Bước 3 — Execution role và task role

```bash
cat > ecs-trust.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Service": "ecs-tasks.amazonaws.com" },
    "Action": "sts:AssumeRole",
    "Condition": { "StringEquals": { "aws:SourceAccount": "${ACCOUNT_ID}" } }
  }]
}
EOF

aws iam create-role --role-name Lab08TaskExecutionRole --assume-role-policy-document file://ecs-trust.json \
  --tags Key=Project,Value=aws-lab-08 >/dev/null
aws iam attach-role-policy --role-name Lab08TaskExecutionRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy

aws iam create-role --role-name Lab08TaskRole --assume-role-policy-document file://ecs-trust.json \
  --tags Key=Project,Value=aws-lab-08 >/dev/null

EXEC_ROLE_ARN=$(aws iam get-role --role-name Lab08TaskExecutionRole --query Role.Arn --output text)
TASK_ROLE_ARN=$(aws iam get-role --role-name Lab08TaskRole --query Role.Arn --output text)
```

- **Execution role** được ECS agent dùng **trước và quanh** container: pull image từ ECR, ghi log vào CloudWatch, lấy secret để inject. Managed policy trên chỉ có quyền ECR pull và `logs:CreateLogStream`/`PutLogEvents`.
- **Task role** để trống vì app không gọi AWS. Khi app cần S3/DynamoDB, thêm quyền **vào task role**, không vào execution role.

---

## Bước 4 — Cluster, log group, target group, ALB

```bash
aws ecs create-cluster --cluster-name lab08-cluster --tags key=Project,value=aws-lab-08 >/dev/null
aws logs create-log-group --log-group-name /ecs/lab08-order-api --tags Project=aws-lab-08
aws logs put-retention-policy --log-group-name /ecs/lab08-order-api --retention-in-days 1

TG_ARN=$(aws elbv2 create-target-group --name lab08-tg \
  --protocol HTTP --port 8080 --vpc-id "${VPC_ID}" --target-type ip \
  --health-check-path /health --health-check-interval-seconds 10 \
  --health-check-timeout-seconds 5 --healthy-threshold-count 2 --unhealthy-threshold-count 2 \
  --tags Key=Project,Value=aws-lab-08 \
  --query 'TargetGroups[0].TargetGroupArn' --output text)
aws elbv2 modify-target-group-attributes --target-group-arn "${TG_ARN}" \
  --attributes Key=deregistration_delay.timeout_seconds,Value=30 >/dev/null

ALB_ARN=$(aws elbv2 create-load-balancer --name lab08-alb --type application --scheme internet-facing \
  --subnets "${PUB_SUB_A}" "${PUB_SUB_B}" --security-groups "${SG_ALB_ID}" \
  --tags Key=Project,Value=aws-lab-08 --query 'LoadBalancers[0].LoadBalancerArn' --output text)
aws elbv2 wait load-balancer-available --load-balancer-arns "${ALB_ARN}"
aws elbv2 create-listener --load-balancer-arn "${ALB_ARN}" --protocol HTTP --port 80 \
  --default-actions Type=forward,TargetGroupArn="${TG_ARN}" >/dev/null
ALB_DNS=$(aws elbv2 describe-load-balancers --load-balancer-arns "${ALB_ARN}" \
  --query 'LoadBalancers[0].DNSName' --output text)
echo "ALB_DNS=${ALB_DNS}"
```

Target type phải là `ip`: task Fargate có ENI riêng và không gắn với EC2 instance nào. Deregistration delay 30 giây là đủ cho API trả lời trong vài trăm ms; production nên đặt theo request dài nhất.

---

## Bước 5 — Task definition (pin digest) và service

```bash
taskdef() {  # $1 = digest, $2 = cổng app thực sự lắng nghe (để tạo bản lỗi ở Bước 8)
  cat > td.json << EOF
{
  "family": "lab08-order-api",
  "networkMode": "awsvpc",
  "requiresCompatibilities": ["FARGATE"],
  "runtimePlatform": { "cpuArchitecture": "ARM64", "operatingSystemFamily": "LINUX" },
  "cpu": "256",
  "memory": "512",
  "executionRoleArn": "${EXEC_ROLE_ARN}",
  "taskRoleArn": "${TASK_ROLE_ARN}",
  "containerDefinitions": [{
    "name": "order-api",
    "image": "${ECR_URI}@$1",
    "essential": true,
    "portMappings": [{ "containerPort": 8080, "protocol": "tcp" }],
    "environment": [{ "name": "PORT", "value": "$2" }],
    "stopTimeout": 30,
    "readonlyRootFilesystem": true,
    "logConfiguration": {
      "logDriver": "awslogs",
      "options": {
        "awslogs-group": "/ecs/lab08-order-api",
        "awslogs-region": "${AWS_REGION}",
        "awslogs-stream-prefix": "order-api"
      }
    }
  }]
}
EOF
  aws ecs register-task-definition --cli-input-json file://td.json \
    --tags key=Project,value=aws-lab-08 --query taskDefinition.taskDefinitionArn --output text
}
TD_V1=$(taskdef "${DIGEST_V1}" 8080)
echo "TD_V1=${TD_V1}"

aws ecs create-service --cluster lab08-cluster --service-name lab08-order-api \
  --task-definition "${TD_V1}" --desired-count 2 --launch-type FARGATE \
  --network-configuration "awsvpcConfiguration={subnets=[${PRI_SUB_A},${PRI_SUB_B}],securityGroups=[${SG_TASK_ID}],assignPublicIp=DISABLED}" \
  --load-balancers "targetGroupArn=${TG_ARN},containerName=order-api,containerPort=8080" \
  --health-check-grace-period-seconds 30 \
  --deployment-configuration "deploymentCircuitBreaker={enable=true,rollback=true},maximumPercent=200,minimumHealthyPercent=100" \
  --tags key=Project,value=aws-lab-08 --propagate-tags SERVICE >/dev/null

aws ecs wait services-stable --cluster lab08-cluster --services lab08-order-api   # 2–5 phút
```

- `runtimePlatform` phải khớp kiến trúc image; image arm64 chạy trên Fargate x86_64 (mặc định) sẽ dừng với `exec format error`.
- `deploymentCircuitBreaker` với `rollback=true`: nếu deployment mới không đạt trạng thái ổn định, ECS đánh dấu thất bại và tự quay về revision cũ. Không bật thì ECS cứ thử khởi động task lỗi mãi.
- `stopTimeout: 30` là khoảng ECS chờ giữa `SIGTERM` và `SIGKILL`.

---

## Bước 6 — Kiểm chứng phân phối lưu lượng

```bash
aws elbv2 describe-target-health --target-group-arn "${TG_ARN}" \
  --query 'TargetHealthDescriptions[].[Target.Id,Target.AvailabilityZone,TargetHealth.State]' --output table
for i in $(seq 1 6); do curl -s "http://${ALB_DNS}/api/orders"; echo; done
```

*Kỳ vọng (minh họa):* hai IP private `10.80.10.x` và `10.80.20.x` ở trạng thái `healthy`; response có `"version":"1.0.0"` và hai giá trị `task` khác nhau. Nếu `curl` không phân giải được tên, chờ 1–2 phút cho DNS của ALB.

---

## Bước 7 — Rolling deploy v1 → v2 và đo lỗi request

Chạy một vòng `curl` liên tục ở nền để làm bằng chứng, rồi deploy:

```bash
probe() {  # $1 = số giây; in mã lỗi khác 200 và tổng kết
  local ok=0 bad=0 end=$(( $(date +%s) + $1 )) c
  while [ "$(date +%s)" -lt "${end}" ]; do
    c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "http://${ALB_DNS}/api/orders")
    if [ "$c" = 200 ]; then ok=$((ok+1)); else bad=$((bad+1)); echo "$(date +%T) HTTP $c"; fi
    sleep 0.2
  done
  echo "TỔNG: OK=${ok} LỖI=${bad}"
}
probe 480 > probe-v2.log 2>&1 &
PROBE_PID=$!

TD_V2=$(taskdef "${DIGEST_V2}" 8080)
aws ecs update-service --cluster lab08-cluster --service lab08-order-api --task-definition "${TD_V2}" >/dev/null
aws ecs wait services-stable --cluster lab08-cluster --services lab08-order-api
wait "${PROBE_PID}"; tail -5 probe-v2.log
curl -s "http://${ALB_DNS}/api/orders"; echo
```

*Kỳ vọng (minh họa):* `TỔNG: OK=1900 LỖI=0` (số OK tùy mạng), response cuối là `"version":"2.0.0"`. Trong lúc deploy có lúc 4 task chạy song song (max 200%).

Xem thứ tự sự kiện và log `SIGTERM` của task v1:

```bash
aws ecs describe-services --cluster lab08-cluster --services lab08-order-api \
  --query 'services[0].events[0:8].[createdAt,message]' --output text
aws logs filter-log-events --log-group-name /ecs/lab08-order-api --filter-pattern '"1.0.0"' \
  --query 'events[].message' --output text | grep -E 'SIGTERM|thoát'
```

*Kỳ vọng:* với mỗi task v1, events có `has begun draining connections on 1 tasks` và `deregistered 1 targets` trước `has stopped 1 running tasks`; log v1 có `SIGTERM nhận được...` rồi `đã đóng hết kết nối, thoát 0`. ECS chờ target draining xong (30 giây) **rồi mới** gửi `SIGTERM`.

Nếu `LỖI` > 0: ghi lại thời điểm và mã (`502` thường do task bị kill khi còn kết nối; `503` do target group trống). So sánh với thứ tự event để tìm nguyên nhân, đây chính là lý do "rolling deploy" chưa đủ nếu thiếu draining và xử lý `SIGTERM`.

---

## Bước 8 — Negative case: bản v3 sai cổng và tự rollback

Bản v3 dùng image v2 nhưng app lắng nghe `9999`, trong khi target group health check vào `8080`:

```bash
probe 900 > probe-v3.log 2>&1 &
PROBE_PID=$!
TD_V3=$(taskdef "${DIGEST_V2}" 9999)
aws ecs update-service --cluster lab08-cluster --service lab08-order-api --task-definition "${TD_V3}" >/dev/null

# Theo dõi deployment tới khi rollback xong (thường 5–15 phút)
for i in $(seq 1 60); do
  aws ecs describe-services --cluster lab08-cluster --services lab08-order-api \
    --query 'services[0].deployments[].[status,rolloutState,taskDefinition,failedTasks]' --output text
  echo "---"
  read -r P_TD P_STATE < <(aws ecs describe-services --cluster lab08-cluster --services lab08-order-api \
    --query "services[0].deployments[?status=='PRIMARY'] | [0].[taskDefinition,rolloutState]" --output text)
  if [ "${P_TD}" = "${TD_V2}" ] && [ "${P_STATE}" = COMPLETED ]; then echo "Đã rollback về ${TD_V2##*/}"; break; fi
  sleep 20
done
kill "${PROBE_PID}" 2>/dev/null; tail -3 probe-v3.log
```

*Kỳ vọng (minh họa):*

```text
PRIMARY  IN_PROGRESS  .../lab08-order-api:3  1
ACTIVE   COMPLETED    .../lab08-order-api:2  0
---
...
PRIMARY  IN_PROGRESS  .../lab08-order-api:2  0
ACTIVE   FAILED       .../lab08-order-api:3  3
---
Đã rollback về lab08-order-api:2
```

Bằng chứng nguyên nhân:

```bash
aws ecs describe-services --cluster lab08-cluster --services lab08-order-api \
  --query 'services[0].events[0:6].message' --output text | grep -iE 'circuit|roll|fail|health'
aws ecs describe-tasks --cluster lab08-cluster \
  --tasks $(aws ecs list-tasks --cluster lab08-cluster --desired-status STOPPED --query 'taskArns[0:3]' --output text) \
  --query 'tasks[].[taskDefinitionArn,stoppedReason,containers[0].exitCode]' --output text
```

*Kỳ vọng:* event chứa `deployment failed: tasks failed to start` và `rolling back to deployment ...`; `stoppedReason` của task v3 là `Task failed ELB health checks in (target-group ...)`. `probe-v3.log` không có (hoặc rất ít) lỗi vì task v2 vẫn phục vụ suốt thời gian đó nhờ `minimumHealthyPercent=100`.

Circuit breaker cần ít nhất 3 task thất bại (với `desiredCount` nhỏ) trước khi kết luận, nên rollback không tức thì.

---

## Bước 9 — Scale-in và graceful shutdown

```bash
aws ecs update-service --cluster lab08-cluster --service lab08-order-api --desired-count 1 >/dev/null
aws ecs wait services-stable --cluster lab08-cluster --services lab08-order-api
aws ecs describe-services --cluster lab08-cluster --services lab08-order-api \
  --query 'services[0].events[0:3].[createdAt,message]' --output text
aws logs filter-log-events --log-group-name /ecs/lab08-order-api --start-time $(( ($(date +%s) - 300) * 1000 )) \
  --query 'events[].message' --output text | grep -E 'SIGTERM|thoát'
```

*Kỳ vọng:* event `draining` khoảng 30 giây trước `stopped 1 running tasks`; log của task bị dừng có `SIGTERM` và `thoát 0` cách nhau dưới 1 giây (không còn kết nối dở). Nếu thấy `quá 20s, thoát 1` hoặc exit code `137`, app không đóng kịp và bị `SIGKILL`.

---

## Bước 10 — (Tham khảo) Lỗi `CannotPullContainerError`

Không cần chạy. Nếu private route table mất route tới NAT (hoặc thiếu VPC endpoint), task mới dừng với `stoppedReason` dạng `CannotPullContainerError: ... dial tcp ...:443: i/o timeout`. Nếu execution role thiếu quyền ECR, thông điệp sẽ là `... not authorized to perform: ecr:...`. Phân biệt hai loại bằng chữ trong `stoppedReason`, rồi sửa đúng chỗ, không mở rộng quyền.

---

## Bước 11 — Dọn dẹp theo dependency

Thứ tự: service → task definition → cluster → ALB → target group → NAT → EIP → SG → mạng → ECR → log group → IAM.

```bash
# 1. Service (desired 0, xóa, chờ INACTIVE) rồi task definition và cluster
aws ecs update-service --cluster lab08-cluster --service lab08-order-api --desired-count 0 >/dev/null
aws ecs delete-service --cluster lab08-cluster --service lab08-order-api --force >/dev/null
aws ecs wait services-inactive --cluster lab08-cluster --services lab08-order-api
for TD in $(aws ecs list-task-definitions --family-prefix lab08-order-api --query 'taskDefinitionArns[]' --output text); do
  aws ecs deregister-task-definition --task-definition "${TD}" >/dev/null
done
aws ecs delete-task-definitions --task-definitions \
  $(aws ecs list-task-definitions --family-prefix lab08-order-api --status INACTIVE --query 'taskDefinitionArns[]' --output text) >/dev/null
aws ecs delete-cluster --cluster lab08-cluster >/dev/null

# 2. ALB, rồi target group
aws elbv2 delete-load-balancer --load-balancer-arn "${ALB_ARN}"
aws elbv2 wait load-balancers-deleted --load-balancer-arns "${ALB_ARN}"
aws elbv2 delete-target-group --target-group-arn "${TG_ARN}"

# 3. NAT (chờ deleted), rồi EIP
ec2 delete-nat-gateway --nat-gateway-id "${NAT_ID}" >/dev/null
ec2 wait nat-gateway-deleted --nat-gateway-ids "${NAT_ID}"
ec2 release-address --allocation-id "${EIP_ALLOC_ID}"

# 4. Security group: ENI của ALB/task có thể còn vài phút, nên thử lại
for sg in "${SG_TASK_ID}" "${SG_ALB_ID}"; do
  for i in $(seq 1 20); do
    ec2 delete-security-group --group-id "${sg}" 2>/dev/null && { echo "deleted ${sg}"; break; }
    echo "chờ ENI giải phóng ${sg}..."; sleep 15
  done
done

# 5. Mạng
for sub in "${PUB_SUB_A}" "${PUB_SUB_B}" "${PRI_SUB_A}" "${PRI_SUB_B}"; do ec2 delete-subnet --subnet-id "${sub}"; done
ec2 delete-route-table --route-table-id "${PUB_RT_ID}"
ec2 delete-route-table --route-table-id "${PRI_RT_ID}"
ec2 detach-internet-gateway --internet-gateway-id "${IGW_ID}" --vpc-id "${VPC_ID}"
ec2 delete-internet-gateway --internet-gateway-id "${IGW_ID}"
ec2 delete-vpc --vpc-id "${VPC_ID}"

# 6. ECR (--force xóa cả image), log group
aws ecr delete-repository --repository-name lab08-order-api --force >/dev/null
aws logs delete-log-group --log-group-name /ecs/lab08-order-api

# 7. IAM
aws iam detach-role-policy --role-name Lab08TaskExecutionRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy
aws iam delete-role --role-name Lab08TaskExecutionRole
aws iam delete-role --role-name Lab08TaskRole

# 8. Image local và file tạm
docker rmi -f $(docker image ls "${ECR_URI}" -q | sort -u) 2>/dev/null
cd ~ && rm -rf "${LAB_DIR}"
```

### Kiểm tra inventory sau cleanup

```bash
echo "== ECS cluster"; aws ecs describe-clusters --clusters lab08-cluster --query "clusters[?status=='ACTIVE'].clusterName" --output text
echo "== Task def";    aws ecs list-task-definitions --family-prefix lab08-order-api --query 'taskDefinitionArns' --output text
echo "== ALB";         aws elbv2 describe-load-balancers --names lab08-alb 2>&1 | grep -o LoadBalancerNotFound
echo "== NAT";         aws ec2 describe-nat-gateways --filter Name=tag:Project,Values=aws-lab-08 --query 'NatGateways[?State!=`deleted`].NatGatewayId' --output text
echo "== EIP";         aws ec2 describe-addresses --filters Name=tag:Project,Values=aws-lab-08 --query 'Addresses[].AllocationId' --output text
echo "== VPC";         aws ec2 describe-vpcs --filters Name=tag:Project,Values=aws-lab-08 --query 'Vpcs[].VpcId' --output text
echo "== ECR";         aws ecr describe-repositories --repository-names lab08-order-api 2>&1 | grep -o RepositoryNotFoundException
echo "== Log group";   aws logs describe-log-groups --log-group-name-prefix /ecs/lab08 --query 'logGroups[].logGroupName' --output text
echo "== IAM";         aws iam list-roles --query "Roles[?starts_with(RoleName,'Lab08')].RoleName" --output text
```

*Kỳ vọng:* mọi mục rỗng, riêng ALB in `LoadBalancerNotFound` và ECR in `RepositoryNotFoundException`. Billing có độ trễ, nên inventory qua API mới là bằng chứng cleanup.

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
ec2() { aws ec2 "$@"; }
q() { ec2 "$@" --output text; }
sub() { q describe-subnets --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values="$1" --query 'Subnets[0].SubnetId'; }
rt()  { q describe-route-tables --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values="$1" --query 'RouteTables[0].RouteTableId'; }
sg()  { q describe-security-groups --filters Name=vpc-id,Values="${VPC_ID}" Name=group-name,Values="$1" --query 'SecurityGroups[0].GroupId'; }

VPC_ID=$(q describe-vpcs --filters Name=tag:Project,Values=aws-lab-08 --query 'Vpcs[0].VpcId')
IGW_ID=$(q describe-internet-gateways --filters Name=attachment.vpc-id,Values="${VPC_ID}" --query 'InternetGateways[0].InternetGatewayId')
PUB_SUB_A=$(sub lab08-public-a);  PUB_SUB_B=$(sub lab08-public-b)
PRI_SUB_A=$(sub lab08-private-a); PRI_SUB_B=$(sub lab08-private-b)
PUB_RT_ID=$(rt lab08-public-rt);  PRI_RT_ID=$(rt lab08-private-rt)
SG_ALB_ID=$(sg lab08-alb-sg);     SG_TASK_ID=$(sg lab08-task-sg)
NAT_ID=$(q describe-nat-gateways --filter Name=tag:Project,Values=aws-lab-08 --query 'NatGateways[?State!=`deleted`]|[0].NatGatewayId')
EIP_ALLOC_ID=$(q describe-addresses --filters Name=tag:Project,Values=aws-lab-08 --query 'Addresses[0].AllocationId')
ALB_ARN=$(aws elbv2 describe-load-balancers --names lab08-alb --query 'LoadBalancers[0].LoadBalancerArn' --output text 2>/dev/null)
TG_ARN=$(aws elbv2 describe-target-groups --names lab08-tg --query 'TargetGroups[0].TargetGroupArn' --output text 2>/dev/null)
ECR_URI="${ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/lab08-order-api"
echo "VPC=${VPC_ID} NAT=${NAT_ID} ALB=${ALB_ARN}"
```

Sau đó chạy lại Bước 11. Lệnh nào báo `NotFound`, `ServiceNotFoundException`, `ClusterNotFoundException`, `NoSuchEntity` nghĩa là tài nguyên đó chưa tạo hoặc đã xóa: bỏ qua và chạy tiếp. Ưu tiên xóa **NAT Gateway và ALB** trước nếu không đủ thời gian.

---

## Checklist hoàn thành

- [ ] Có bảng chi phí và điều kiện dừng trước khi bắt đầu.
- [ ] ECR immutable: push đè tag `v1` bị từ chối; đọc được kết quả scan.
- [ ] Task definition tham chiếu image bằng **digest**, `runtimePlatform` ARM64.
- [ ] Giải thích được vì sao task role để trống còn execution role có quyền ECR/logs.
- [ ] Hai task `healthy` ở hai private subnet, không có public IP; ALB trả về hai `task` khác nhau.
- [ ] Rolling deploy v1 → v2 với số liệu `OK/LỖI` từ `probe`; thấy log `SIGTERM` → `thoát 0` của task v1.
- [ ] Bản v3 sai cổng bị circuit breaker đánh dấu `FAILED` và tự rollback; có `stoppedReason` làm bằng chứng.
- [ ] Scale-in: thấy draining trước `SIGTERM`.
- [ ] Cleanup xong; inventory rỗng (ECS, ALB, NAT, EIP, VPC, ECR, log group, IAM).

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| Task dừng với `CannotPullContainerError ... i/o timeout` | Private subnet không có đường tới ECR/S3 | `describe-route-tables` của private RT phải có `0.0.0.0/0 → nat-...`, NAT `available`; hoặc thêm VPC endpoint |
| `CannotPullContainerError ... not authorized` | Execution role thiếu quyền ECR hoặc sai role trong task definition | `aws ecs describe-task-definition --task-definition ${TD_V1} --query taskDefinition.executionRoleArn` |
| Task dừng, log có `exec format error` | Kiến trúc image khác `runtimePlatform` | `docker image inspect ${ECR_URI}:v1 --format '{{.Architecture}}'` phải là `arm64` |
| Task dừng `OutOfMemoryError`, exit code `137` | App vượt `memory` của task | Tăng `memory` hoặc giảm dùng bộ nhớ; exit `137` cũng xuất hiện khi bị `SIGKILL` sau `stopTimeout` |
| Exit code `1` ngay khi start | App lỗi khi khởi động | `aws logs tail /ecs/lab08-order-api --since 10m` |
| Target `unhealthy` với `Target.Timeout` | SG task không nhận 8080 từ SG ALB, hoặc app lắng nghe cổng khác | Kiểm tra rule `lab08-task-sg`; biến `PORT` trong task definition |
| Target `unhealthy` với `Target.ResponseCodeMismatch` | `/health` trả mã khác 200 (ALB mặc định chỉ chấp nhận `200`) | Sửa app hoặc `--matcher HttpCode=200-299` |
| `create-service` báo lỗi `iam:PassRole` | Người chạy lab thiếu PassRole trên hai role | Xin `iam:PassRole` giới hạn trên `Lab08TaskExecutionRole`, `Lab08TaskRole` |
| `update-service` báo `container port ... not found` | Task definition mới đổi `containerPort` khác với cấu hình load balancer của service | Giữ `containerPort` cố định; đổi cổng cần cập nhật cả `--load-balancers` |
| Deployment kẹt `IN_PROGRESS` rất lâu | Circuit breaker chưa đủ số task thất bại, hoặc không bật | Xem `failedTasks`; chờ; kiểm tra `deploymentConfiguration` |
| `delete-cluster` báo `ClusterContainsServicesException` | Service chưa `INACTIVE` | `aws ecs wait services-inactive` rồi xóa lại |

---

## Tiếp theo

Bạn đã đóng gói, deploy theo digest và chứng minh được rolling deploy, rollback và graceful shutdown bằng số liệu. Bài tiếp theo: **[Bài 09 — Serverless: Lambda, API Gateway](../../notes/09-serverless.md)**.

---

## Nhánh Laravel theo lộ trình gốc

Lab dịch vụ ở trên được nối với [dự án Laravel + Vue](../15-capstone-laravel/README.md). Đọc phần bổ sung trong bài lý thuyết 08 và bảng mốc của dự án để chọn đúng prerequisite. Đầu ra bắt buộc gồm code/fixture, happy path, negative case và inventory sau cleanup; không dùng output minh họa làm bằng chứng đã chạy.
