# Lab 05 — Máy chủ và cân bằng tải: Auto Scaling Group trong private subnet sau Application Load Balancer

> **Lý thuyết:** [notes/05-compute-and-load-balancing.md](../../notes/05-compute-and-load-balancing.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 90–120 phút (cộng 20–30 phút nếu làm phần scaling tùy chọn).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output và thời gian đo là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Tự tạo mạng lab riêng (2 public + 2 private subnet, 1 NAT Gateway) | Dùng Default VPC hoặc tài nguyên của lab khác |
| Tạo IAM Role + Instance Profile cho SSM | Mở port 22, tạo SSH key pair |
| Launch Template: AL2023, `t4g.nano`, IMDSv2, app chạy bằng systemd | Gán public IP cho instance ứng dụng |
| ALB internet-facing trên 2 AZ, ASG trong 2 private subnet, health check ELB | Dùng instance lớn hoặc `max` capacity lớn hơn 3 |
| Negative test `503`; giả lập sự cố và **đo** thời gian tự phục hồi | Để NAT/ALB chạy qua đêm |
| Tùy chọn: target tracking scaling với tải CPU có giới hạn thời gian | Load test từ bên ngoài với lưu lượng lớn |
| Cleanup theo dependency + kiểm tra EBS, EIP, public IPv4 còn sót | |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `REGION` | Region học tập | `ap-southeast-1` |
| `AZ_A`, `AZ_B` | Hai AZ đầu tiên khả dụng (lấy tự động) | `ap-southeast-1a`, `ap-southeast-1b` |
| `VPC_ID`, `PUB_SUB_*`, `PRI_SUB_*`, `NAT_ID`, ... | ID tài nguyên lab tạo, lưu trong biến shell | `vpc-0123456789abcdef0` |
| `TG_ARN`, `ALB_ARN`, `ALB_DNS` | Target group, ALB và DNS name | `lab05-alb-123.ap-southeast-1.elb.amazonaws.com` |

Tên cố định: `lab05-*` cho tài nguyên mạng/ELB/ASG, `LabEC2SSMRole`/`LabEC2SSMProfile` cho IAM, `LabOrderApiTemplate` cho launch template. Mọi tài nguyên hỗ trợ tag đều mang `Project=aws-lab-05`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 01](../01-foundations/README.md) đến [Lab 04](../04-networking/README.md). Lab này **không** dùng lại VPC của Lab 04 (đã xóa ở cuối Lab 04) mà tự tạo mạng theo cùng mô hình.
- [ ] Đã cài [Session Manager plugin](https://docs.aws.amazon.com/systems-manager/latest/userguide/session-manager-working-with-install-plugin.html) cho AWS CLI (`session-manager-plugin --version`). Nếu không cài được, dùng Session Manager trên console.
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **IAM:** `iam:CreateRole`, `iam:AttachRolePolicy`, `iam:DetachRolePolicy`, `iam:DeleteRole`, `iam:CreateInstanceProfile`, `iam:AddRoleToInstanceProfile`, `iam:RemoveRoleFromInstanceProfile`, `iam:DeleteInstanceProfile`, `iam:GetInstanceProfile`, `iam:TagRole`, **`iam:PassRole` trên `LabEC2SSMRole`** (bắt buộc để gắn instance profile vào launch template/ASG), và `iam:CreateServiceLinkedRole` nếu đây là lần đầu account dùng Auto Scaling/ELB.
  - **EC2/VPC:** các quyền mạng như Lab 04, thêm `ec2:AllocateAddress`, `ec2:ReleaseAddress`, `ec2:CreateNatGateway`, `ec2:DeleteNatGateway`, `ec2:CreateLaunchTemplate`, `ec2:DeleteLaunchTemplate`, `ec2:RunInstances` (ASG kiểm tra quyền này của người tạo ASG), `ec2:Describe*`.
  - **ELB:** `elasticloadbalancing:CreateLoadBalancer`, `CreateTargetGroup`, `CreateListener`, `ModifyTargetGroupAttributes`, `AddTags`, `DeleteLoadBalancer`, `DeleteTargetGroup`, `Describe*`.
  - **Auto Scaling:** `autoscaling:CreateAutoScalingGroup`, `UpdateAutoScalingGroup`, `DeleteAutoScalingGroup`, `PutScalingPolicy`, `Describe*`.
  - **SSM:** `ssm:GetParameter` (AMI ID), `ssm:StartSession`, `ssm:TerminateSession`, `ssm:DescribeInstanceInformation`.
- [ ] Quota: account mới có thể bị giới hạn vCPU On-Demand thấp. Lab dùng tối đa 3 × `t4g.nano` (6 vCPU).
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại cho Region của bạn)

| Tài nguyên | Số lượng | Đơn giá minh họa | Mỗi giờ |
|---|---|---|---|
| NAT Gateway (giờ) | 1 | ~$0.059/giờ | ~$0.059 |
| Public IPv4 (EIP của NAT + 2 IP của ALB) | 3 | ~$0.005/giờ/IP | ~$0.015 |
| ALB (giờ + ~1 LCU) | 1 | ~$0.0252 + ~$0.008 | ~$0.033 |
| EC2 `t4g.nano` | 2 (tối đa 3) | ~$0.0053/giờ | ~$0.011 |
| EBS gp3 8 GB | 2 | ~$0.096/GB-tháng | < $0.001 |
| NAT data processing | vài chục MB | ~$0.059/GB | < $0.01 |
| **Tổng** | | | **~$0.12/giờ** |

- Làm lab 2 giờ: khoảng **$0.25**.
- **Quên xóa 1 tháng: khoảng $85–90**, trong đó NAT + ALB chiếm hơn 80%.
- **Điều kiện dừng:** nếu sau 3 giờ chưa xong, chạy cleanup (Bước 10) rồi làm lại sau. Tạo lại toàn bộ chỉ mất khoảng 15 phút.

---

## Sơ đồ kiến trúc Lab 05

```text
                      Internet / máy của bạn (curl)
                                 │ HTTP :80
                                 ▼
┌──────────────────────────── VPC lab05-vpc 10.50.0.0/16 ─────────────────────────────┐
│                         [ Internet Gateway lab05-igw ]                              │
│                                       │                                             │
│  Public RT: 0.0.0.0/0 → IGW           │                                             │
│  ┌───────── Public Subnet A ──────────┴──┐   ┌───────── Public Subnet B ─────────┐  │
│  │ 10.50.1.0/24 (<AZ_A>)                 │   │ 10.50.2.0/24 (<AZ_B>)             │  │
│  │  [ALB node]   [NAT Gateway + EIP]     │   │  [ALB node]                       │  │
│  └───────────────────┬─────────▲─────────┘   └──────────────┬────────────────────┘  │
│        lab05-alb-sg: │ :80     │ outbound 443 (SSM)         │                       │
│        từ 0.0.0.0/0  │         │                            │                       │
│                      ▼ :8080   │                            ▼ :8080                 │
│  Private RT: 0.0.0.0/0 → NAT   │                                                    │
│  ┌───────── Private Subnet A ──┴─────────┐   ┌───────── Private Subnet B ────────┐  │
│  │ 10.50.10.0/24 (<AZ_A>)                │   │ 10.50.20.0/24 (<AZ_B>)            │  │
│  │  EC2 #1 (không public IP)             │   │  EC2 #2 (không public IP)         │  │
│  └───────────────────────────────────────┘   └───────────────────────────────────┘  │
│        lab05-app-sg: :8080 chỉ từ lab05-alb-sg; không có inbound nào khác            │
│                                                                                     │
│   Target Group lab05-tg (HTTP :8080, health check GET /health mỗi 10 giây)           │
│   Auto Scaling Group lab05-asg: min 1 / desired 2 / max 3, HealthCheckType = ELB     │
└─────────────────────────────────────────────────────────────────────────────────────┘
```

**Vì sao cần NAT Gateway?** Instance trong private subnet cần outbound HTTPS tới SSM endpoints. Private subnet chỉ có local route thì SSM agent không kết nối được (xem Bài 04, Phần 3). Lab dùng **một** NAT (zonal, đặt ở AZ A) để tiết kiệm. Nếu AZ A gặp sự cố, instance ở AZ B cũng mất outbound; production sẽ đặt mỗi AZ một NAT.

**Phương án thay thế:** interface endpoints `ssm` + `ssmmessages` ở 2 AZ thay cho NAT. Instance khi đó hoàn toàn không ra được internet (an toàn hơn), nhưng chi phí theo giờ của 4 endpoint (2 service × 2 AZ) tương đương hoặc cao hơn 1 NAT. Ứng dụng của lab không cần cài package từ internet nên phương án nào cũng chạy được.

---

## Bước 0 — Tạo mạng lab

Các lệnh dưới đây lặp lại mô hình Lab 04, thêm NAT Gateway. Đọc lại Lab 04 nếu cần giải thích từng lệnh.

```bash
export REGION="ap-southeast-1"   # đổi thành Region của bạn
export TAG="{Key=Project,Value=aws-lab-05}"
export LAB_DIR=$(mktemp -d)
ec2() { aws ec2 "$@" --region "${REGION}" --profile aws-lab; }

read -r AZ_A AZ_B < <(ec2 describe-availability-zones \
  --filters Name=zone-type,Values=availability-zone Name=state,Values=available \
  --query 'AvailabilityZones[0:2].ZoneName' --output text)
echo "AZ_A=${AZ_A} AZ_B=${AZ_B}"

# VPC + DNS
VPC_ID=$(ec2 create-vpc --cidr-block 10.50.0.0/16 \
  --tag-specifications "ResourceType=vpc,Tags=[{Key=Name,Value=lab05-vpc},${TAG}]" \
  --query 'Vpc.VpcId' --output text)
ec2 modify-vpc-attribute --vpc-id "${VPC_ID}" --enable-dns-support '{"Value": true}'
ec2 modify-vpc-attribute --vpc-id "${VPC_ID}" --enable-dns-hostnames '{"Value": true}'

# Internet Gateway
IGW_ID=$(ec2 create-internet-gateway \
  --tag-specifications "ResourceType=internet-gateway,Tags=[{Key=Name,Value=lab05-igw},${TAG}]" \
  --query 'InternetGateway.InternetGatewayId' --output text)
ec2 attach-internet-gateway --vpc-id "${VPC_ID}" --internet-gateway-id "${IGW_ID}"

# Subnets
mksub() { ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block "$1" --availability-zone "$2" \
  --tag-specifications "ResourceType=subnet,Tags=[{Key=Name,Value=$3},${TAG}]" \
  --query 'Subnet.SubnetId' --output text; }
PUB_SUB_A=$(mksub 10.50.1.0/24  "${AZ_A}" lab05-public-a)
PUB_SUB_B=$(mksub 10.50.2.0/24  "${AZ_B}" lab05-public-b)
PRI_SUB_A=$(mksub 10.50.10.0/24 "${AZ_A}" lab05-private-a)
PRI_SUB_B=$(mksub 10.50.20.0/24 "${AZ_B}" lab05-private-b)

# Public route table
PUB_RT_ID=$(ec2 create-route-table --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=lab05-public-rt},${TAG}]" \
  --query 'RouteTable.RouteTableId' --output text)
ec2 create-route --route-table-id "${PUB_RT_ID}" --destination-cidr-block 0.0.0.0/0 --gateway-id "${IGW_ID}" > /dev/null
ec2 associate-route-table --subnet-id "${PUB_SUB_A}" --route-table-id "${PUB_RT_ID}" > /dev/null
ec2 associate-route-table --subnet-id "${PUB_SUB_B}" --route-table-id "${PUB_RT_ID}" > /dev/null

echo "VPC=${VPC_ID} PUB=${PUB_SUB_A},${PUB_SUB_B} PRI=${PRI_SUB_A},${PRI_SUB_B}"
```

Public subnet **không** bật `map-public-ip-on-launch`: chỉ ALB và NAT nằm ở đây, và chúng tự quản lý IP của mình.

Tạo NAT Gateway (bắt đầu tính phí từ đây) và private route table:

```bash
EIP_ALLOC_ID=$(ec2 allocate-address --domain vpc \
  --tag-specifications "ResourceType=elastic-ip,Tags=[{Key=Name,Value=lab05-nat-eip},${TAG}]" \
  --query AllocationId --output text)

NAT_ID=$(ec2 create-nat-gateway --subnet-id "${PUB_SUB_A}" --allocation-id "${EIP_ALLOC_ID}" \
  --tag-specifications "ResourceType=natgateway,Tags=[{Key=Name,Value=lab05-nat},${TAG}]" \
  --query 'NatGateway.NatGatewayId' --output text)
echo "Đang chờ NAT ${NAT_ID} chuyển sang available (1–3 phút)..."
ec2 wait nat-gateway-available --nat-gateway-ids "${NAT_ID}"

PRI_RT_ID=$(ec2 create-route-table --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=lab05-private-rt},${TAG}]" \
  --query 'RouteTable.RouteTableId' --output text)
ec2 create-route --route-table-id "${PRI_RT_ID}" --destination-cidr-block 0.0.0.0/0 --nat-gateway-id "${NAT_ID}" > /dev/null
ec2 associate-route-table --subnet-id "${PRI_SUB_A}" --route-table-id "${PRI_RT_ID}" > /dev/null
ec2 associate-route-table --subnet-id "${PRI_SUB_B}" --route-table-id "${PRI_RT_ID}" > /dev/null
```

Xác minh route của private RT:

```bash
ec2 describe-route-tables --route-table-ids "${PRI_RT_ID}" \
  --query 'RouteTables[0].Routes[].[DestinationCidrBlock,GatewayId||NatGatewayId,State]' --output text
```

Kết quả mong đợi:
```text
10.50.0.0/16   local          active
0.0.0.0/0      nat-0abc...    active
```

---

## Bước 1 — Tạo IAM Role và Instance Profile cho EC2

```bash
cat <<'EOF' > "${LAB_DIR}/ec2-trust-policy.json"
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": { "Service": "ec2.amazonaws.com" },
      "Action": "sts:AssumeRole"
    }
  ]
}
EOF

aws iam create-role --role-name LabEC2SSMRole \
  --assume-role-policy-document "file://${LAB_DIR}/ec2-trust-policy.json" \
  --tags Key=Project,Value=aws-lab-05 --profile aws-lab > /dev/null

aws iam attach-role-policy --role-name LabEC2SSMRole \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore --profile aws-lab

aws iam create-instance-profile --instance-profile-name LabEC2SSMProfile --profile aws-lab > /dev/null
aws iam add-role-to-instance-profile --instance-profile-name LabEC2SSMProfile \
  --role-name LabEC2SSMRole --profile aws-lab

# IAM là eventually consistent: chờ profile tồn tại và thêm một chút để EC2 "thấy" nó
aws iam wait instance-profile-exists --instance-profile-name LabEC2SSMProfile --profile aws-lab
sleep 15
```

Trust policy cho phép **dịch vụ EC2** assume role; instance profile là "vỏ bọc" để gắn role vào instance. App trên instance lấy temporary credentials của role qua IMDSv2 (xem lý thuyết Phần 1). Nếu bỏ qua `sleep`, lần launch đầu tiên có thể lỗi `Invalid IamInstanceProfile`.

---

## Bước 2 — Chuẩn bị User Data cho Order API

User data tạo một HTTP server Python nhỏ trên cổng `8080` và đăng ký nó thành **systemd service** `order-api`:
- `GET /health` → `{"status": "healthy"}` cho ALB health check.
- `GET /order` → phiên bản, instance ID và AZ (lấy qua IMDSv2), để thấy request đi tới máy nào.
- Mỗi request được ghi log vào journald (`journalctl -u order-api`).

Script **không** chạy `dnf update`: AL2023 có sẵn `python3`, và việc cập nhật package lúc boot trên `t4g.nano` có thể lâu hơn health check grace period, làm ASG giết instance trước khi app kịp lên (xem câu hỏi 7 trong lý thuyết).

```bash
cat <<'USERDATA' > "${LAB_DIR}/user-data.sh"
#!/bin/bash
set -euo pipefail
mkdir -p /opt/order-api

cat > /opt/order-api/server.py <<'PYEOF'
import json
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

VERSION = "v1"
IMDS = "http://169.254.169.254/latest"


def metadata(path):
    try:
        token_req = urllib.request.Request(
            f"{IMDS}/api/token", method="PUT",
            headers={"X-aws-ec2-metadata-token-ttl-seconds": "300"})
        token = urllib.request.urlopen(token_req, timeout=2).read().decode()
        req = urllib.request.Request(
            f"{IMDS}/meta-data/{path}",
            headers={"X-aws-ec2-metadata-token": token})
        return urllib.request.urlopen(req, timeout=2).read().decode()
    except Exception:
        return "unknown"


INSTANCE_ID = metadata("instance-id")
AZ = metadata("placement/availability-zone")


class Handler(BaseHTTPRequestHandler):
    def _json(self, code, body):
        data = (json.dumps(body) + "\n").encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/health":
            self._json(200, {"status": "healthy"})
        elif self.path == "/order":
            self._json(200, {"service": "order-api", "version": VERSION,
                             "instance_id": INSTANCE_ID, "az": AZ})
        else:
            self._json(404, {"error": "not found"})


ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
PYEOF

cat > /etc/systemd/system/order-api.service <<'EOF'
[Unit]
Description=Order API (lab 05)
After=network-online.target
Wants=network-online.target

[Service]
ExecStart=/usr/bin/python3 /opt/order-api/server.py
Restart=on-failure
DynamicUser=yes

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now order-api
USERDATA

USER_DATA_BASE64=$(base64 < "${LAB_DIR}/user-data.sh" | tr -d '\n')
```

Ghi chú thiết kế:
- Bind `0.0.0.0` (không phải `127.0.0.1`) để ALB kết nối được từ ngoài instance.
- `DynamicUser=yes`: service chạy bằng user tạm không có đặc quyền, không phải root.
- `Restart=on-failure`: systemd tự khởi động lại nếu process crash, nhưng **không** khởi động lại khi bạn chủ động `systemctl stop` (dùng ở Bước 8).
- `enable` giúp service tự chạy lại sau reboot, điều mà `python3 server.py &` không làm được.
- `Version` trong launch template (Bước 3) là phiên bản cấu hình hạ tầng; `VERSION` trong code là phiên bản ứng dụng. Khi deploy bản mới, bạn tạo launch template version mới với user data mới.

---

## Bước 3 — Security Groups và Launch Template

### Security Groups

```bash
SG_ALB_ID=$(ec2 create-security-group --group-name lab05-alb-sg \
  --description "ALB: HTTP 80 tu internet" --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=lab05-alb-sg},${TAG}]" \
  --query GroupId --output text)
ec2 authorize-security-group-ingress --group-id "${SG_ALB_ID}" \
  --protocol tcp --port 80 --cidr 0.0.0.0/0 > /dev/null

SG_APP_ID=$(ec2 create-security-group --group-name lab05-app-sg \
  --description "App: 8080 chi tu ALB" --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=lab05-app-sg},${TAG}]" \
  --query GroupId --output text)
ec2 authorize-security-group-ingress --group-id "${SG_APP_ID}" \
  --protocol tcp --port 8080 --source-group "${SG_ALB_ID}" > /dev/null
```

`lab05-app-sg` không có rule inbound nào khác: không port 22, không 0.0.0.0/0. Outbound mặc định cho phép tất cả, đủ để agent ra NAT tới SSM.

### Launch Template

```bash
AMI_ID=$(aws ssm get-parameter \
  --name /aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64 \
  --region "${REGION}" --profile aws-lab --query 'Parameter.Value' --output text)
echo "AMI_ID=${AMI_ID}"

cat <<EOF > "${LAB_DIR}/launch-template.json"
{
  "ImageId": "${AMI_ID}",
  "InstanceType": "t4g.nano",
  "IamInstanceProfile": { "Name": "LabEC2SSMProfile" },
  "SecurityGroupIds": ["${SG_APP_ID}"],
  "UserData": "${USER_DATA_BASE64}",
  "MetadataOptions": { "HttpTokens": "required", "HttpEndpoint": "enabled" },
  "TagSpecifications": [
    { "ResourceType": "instance",
      "Tags": [{ "Key": "Name", "Value": "lab05-order-api" }, { "Key": "Project", "Value": "aws-lab-05" }] },
    { "ResourceType": "volume",
      "Tags": [{ "Key": "Name", "Value": "lab05-order-api" }, { "Key": "Project", "Value": "aws-lab-05" }] }
  ]
}
EOF

ec2 create-launch-template --launch-template-name LabOrderApiTemplate \
  --version-description "v1-order-api" \
  --launch-template-data "file://${LAB_DIR}/launch-template.json" \
  --tag-specifications "ResourceType=launch-template,Tags=[${TAG}]" \
  --query 'LaunchTemplate.[LaunchTemplateId,LatestVersionNumber]' --output text
```

Kết quả mong đợi: `lt-0abc...  1`.

- `t4g.nano` là Graviton (arm64), nên AMI cũng phải là bản `arm64`. Lệch kiến trúc sẽ làm launch thất bại.
- `HttpTokens: required` buộc dùng IMDSv2.
- Launch template không chứa subnet. ASG sẽ chọn subnet từ `--vpc-zone-identifier`, và vì private subnet không tự gán public IP nên instance không có public IP.
- Root volume của AMI có `DeleteOnTermination=true`, nên tự xóa khi instance terminate.

---

## Bước 4 — Target Group, ALB và negative test 503

### Target Group

```bash
TG_ARN=$(aws elbv2 create-target-group --name lab05-tg \
  --protocol HTTP --port 8080 --vpc-id "${VPC_ID}" --target-type instance \
  --health-check-protocol HTTP --health-check-path /health \
  --health-check-interval-seconds 10 --health-check-timeout-seconds 5 \
  --healthy-threshold-count 2 --unhealthy-threshold-count 2 \
  --tags "Key=Project,Value=aws-lab-05" \
  --region "${REGION}" --profile aws-lab \
  --query 'TargetGroups[0].TargetGroupArn' --output text)

# Rút deregistration delay từ 300 xuống 30 giây cho lab (production nên đo theo thời gian request dài nhất)
aws elbv2 modify-target-group-attributes --target-group-arn "${TG_ARN}" \
  --attributes Key=deregistration_delay.timeout_seconds,Value=30 \
  --region "${REGION}" --profile aws-lab > /dev/null
```

Với interval 10 giây và threshold 2: target cần khoảng 20 giây để chuyển `healthy` hoặc `unhealthy`.

### Application Load Balancer

```bash
ALB_ARN=$(aws elbv2 create-load-balancer --name lab05-alb \
  --type application --scheme internet-facing --ip-address-type ipv4 \
  --subnets "${PUB_SUB_A}" "${PUB_SUB_B}" --security-groups "${SG_ALB_ID}" \
  --tags "Key=Project,Value=aws-lab-05" \
  --region "${REGION}" --profile aws-lab \
  --query 'LoadBalancers[0].LoadBalancerArn' --output text)

echo "Đang chờ ALB active (2–4 phút)..."
aws elbv2 wait load-balancer-available --load-balancer-arns "${ALB_ARN}" --region "${REGION}" --profile aws-lab

aws elbv2 create-listener --load-balancer-arn "${ALB_ARN}" \
  --protocol HTTP --port 80 \
  --default-actions Type=forward,TargetGroupArn="${TG_ARN}" \
  --region "${REGION}" --profile aws-lab > /dev/null

ALB_DNS=$(aws elbv2 describe-load-balancers --load-balancer-arns "${ALB_ARN}" \
  --region "${REGION}" --profile aws-lab --query 'LoadBalancers[0].DNSName' --output text)
echo "ALB_DNS=${ALB_DNS}"
```

### Negative test: ALB chưa có target

```bash
sleep 30   # chờ DNS của ALB phân giải được
curl -s -o /dev/null -w "%{http_code}\n" "http://${ALB_DNS}/order"
```

Kết quả mong đợi: `503`. Target group chưa có target nào được đăng ký. Đây là ý nghĩa thực sự của 503 trên ALB (xem bảng mã lỗi trong lý thuyết). Nếu `curl` báo không phân giải được tên, chờ thêm 1–2 phút rồi thử lại.

---

## Bước 5 — Tạo Auto Scaling Group

```bash
aws autoscaling create-auto-scaling-group \
  --auto-scaling-group-name lab05-asg \
  --launch-template "LaunchTemplateName=LabOrderApiTemplate,Version=1" \
  --min-size 1 --desired-capacity 2 --max-size 3 \
  --vpc-zone-identifier "${PRI_SUB_A},${PRI_SUB_B}" \
  --target-group-arns "${TG_ARN}" \
  --health-check-type ELB --health-check-grace-period 90 \
  --tags "Key=Project,Value=aws-lab-05,PropagateAtLaunch=false" \
  --region "${REGION}" --profile aws-lab
```

- `--vpc-zone-identifier` là **2 private subnet**; ASG tự chia đều instance giữa 2 AZ.
- `--health-check-grace-period 90`: thời gian boot + chạy user data + app start với script này thường dưới 60 giây. Bạn sẽ đo con số thực ở Bước 8.

Theo dõi target health cho đến khi cả 2 target `healthy` (thường 2–3 phút):

```bash
for i in $(seq 1 24); do
  STATES=$(aws elbv2 describe-target-health --target-group-arn "${TG_ARN}" \
    --region "${REGION}" --profile aws-lab \
    --query 'TargetHealthDescriptions[].[Target.Id,TargetHealth.State]' --output text | tr '\n' ' ')
  echo "$(date +%T) ${STATES}"
  [ "$(echo "${STATES}" | grep -o healthy | wc -l)" -ge 2 ] && ! echo "${STATES}" | grep -q unhealthy && break
  sleep 10
done
```

Output minh họa:
```text
10:02:11
10:02:21 i-0aaa... initial i-0bbb... initial
10:03:31 i-0aaa... healthy i-0bbb... initial
10:03:41 i-0aaa... healthy i-0bbb... healthy
```

Xác nhận instance **không có public IP** và nằm ở 2 AZ:

```bash
ec2 describe-instances \
  --filters Name=tag:Name,Values=lab05-order-api Name=instance-state-name,Values=running \
  --query 'Reservations[].Instances[].[InstanceId,Placement.AvailabilityZone,PrivateIpAddress,PublicIpAddress]' \
  --output table
```

Kết quả mong đợi: cột cuối là `None`, hai AZ khác nhau.

---

## Bước 6 — Kiểm chứng phân phối request qua ALB

```bash
for i in $(seq 1 8); do curl -s "http://${ALB_DNS}/order"; done
```

Output minh họa:
```json
{"service": "order-api", "version": "v1", "instance_id": "i-0aaa...", "az": "ap-southeast-1a"}
{"service": "order-api", "version": "v1", "instance_id": "i-0bbb...", "az": "ap-southeast-1b"}
{"service": "order-api", "version": "v1", "instance_id": "i-0bbb...", "az": "ap-southeast-1b"}
{"service": "order-api", "version": "v1", "instance_id": "i-0aaa...", "az": "ap-southeast-1a"}
...
```

✅ **Xác nhận:** cả hai instance ở hai AZ đều nhận request. Thứ tự **không nhất thiết luân phiên đều**: DNS của ALB trả về nhiều IP (mỗi AZ một node), mỗi node lại cân bằng tải riêng, nên chỉ cần thấy cả hai instance ID xuất hiện.

Thử truy cập thẳng instance, bỏ qua ALB (negative test): instance không có public IP nên không có địa chỉ nào để gọi từ internet. `lab05-app-sg` chỉ nhận 8080 từ `lab05-alb-sg`, nên ngay cả máy khác trong VPC (không mang SG của ALB) cũng không gọi được.

---

## Bước 7 — Vào instance bằng Session Manager

```bash
INSTANCE_ID=$(aws autoscaling describe-auto-scaling-groups --auto-scaling-group-names lab05-asg \
  --region "${REGION}" --profile aws-lab \
  --query 'AutoScalingGroups[0].Instances[0].InstanceId' --output text)
echo "INSTANCE_ID=${INSTANCE_ID}"

# Kiểm tra instance đã "Online" với SSM (có thể mất 1–3 phút sau khi boot)
aws ssm describe-instance-information --region "${REGION}" --profile aws-lab \
  --filters "Key=InstanceIds,Values=${INSTANCE_ID}" \
  --query 'InstanceInformationList[].[InstanceId,PingStatus]' --output text
```

Kết quả mong đợi: `i-0aaa...  Online`. Nếu rỗng, xem Troubleshooting.

```bash
aws ssm start-session --target "${INSTANCE_ID}" --region "${REGION}" --profile aws-lab
```

*(Hoặc trên console: EC2 → Instances → chọn instance → **Connect** → tab **Session Manager** → **Connect**.)*

Trong session, kiểm tra:

```bash
sudo tail -n 5 /var/log/cloud-init-output.log   # user data chạy xong, không lỗi
systemctl status order-api --no-pager           # active (running)
curl -s localhost:8080/health                   # {"status": "healthy"}
sudo journalctl -u order-api -n 5 --no-pager    # log request từ ALB health check (10.50.1.x / 10.50.2.x)
```

Giữ session mở cho Bước 8.

---

## Bước 8 — Giả lập sự cố và đo thời gian tự phục hồi

**Trên máy của bạn**, mở terminal thứ hai. Terminal mới chưa có biến, nên chạy `echo "export REGION=${REGION} TG_ARN=${TG_ARN}"` ở terminal đầu, rồi dán kết quả vào terminal thứ hai. Sau đó bắt đầu theo dõi target health:

```bash
for i in $(seq 1 30); do
  echo "$(date +%T) $(aws elbv2 describe-target-health --target-group-arn "${TG_ARN}" \
    --region "${REGION}" --profile aws-lab \
    --query 'TargetHealthDescriptions[].[Target.Id,TargetHealth.State]' --output text | tr '\n' ' ')"
  sleep 10
done
```

**Trong session SSM** (Bước 7), dừng ứng dụng:

```bash
date +%T
sudo systemctl stop order-api
exit
```

Quan sát terminal theo dõi. Diễn biến minh họa:

```text
10:10:00 i-0aaa... healthy   i-0bbb... healthy       ← dừng service lúc ~10:10:02
10:10:20 i-0aaa... unhealthy i-0bbb... healthy       ← ~20 giây = interval × threshold
10:10:40 i-0aaa... draining  i-0bbb... healthy       ← ASG terminate, ALB drain 30 giây
10:11:30 i-0ccc... initial   i-0bbb... healthy       ← instance mới đăng ký
10:12:30 i-0ccc... healthy   i-0bbb... healthy       ← phục hồi hoàn toàn
```

Trong lúc đó, `curl` qua ALB vẫn trả về `200` từ instance còn lại. Đó là ý nghĩa của việc chạy ≥ 2 instance ở 2 AZ.

Xem lịch sử hành động của ASG:

```bash
aws autoscaling describe-scaling-activities --auto-scaling-group-name lab05-asg --max-items 3 \
  --region "${REGION}" --profile aws-lab \
  --query 'Activities[].[StartTime,StatusCode,Description]' --output text
```

Kết quả mong đợi: một dòng "Terminating EC2 instance ... (failed ELB health check)" và một dòng "Launching a new EC2 instance ...".

**Ghi lại số đo của bạn:**

| Mốc | Thời điểm | Khoảng cách |
|---|---|---|
| Dừng service | | 0 |
| Target `unhealthy` | | (kỳ vọng ~20–30 giây) |
| ASG bắt đầu terminate | | |
| Instance mới `healthy` | | (kỳ vọng ~2–3 phút) |

**Câu hỏi:** Nếu ASG dùng health check `EC2` (mặc định) thay vì `ELB`, điều gì xảy ra với instance bị dừng service? *(Đáp án: ALB vẫn ngừng gửi traffic tới nó, nhưng ASG thấy máy vẫn "running" nên không thay. Nhóm chạy thiếu một máy mãi mãi.)*

---

## Bước 9 (tùy chọn) — Target tracking scaling

Gắn policy giữ CPU trung bình của nhóm quanh 40%:

```bash
aws autoscaling put-scaling-policy --auto-scaling-group-name lab05-asg \
  --policy-name lab05-cpu40 --policy-type TargetTrackingScaling \
  --target-tracking-configuration '{"PredefinedMetricSpecification":{"PredefinedMetricType":"ASGAverageCPUUtilization"},"TargetValue":40.0}' \
  --region "${REGION}" --profile aws-lab --query 'Alarms[].AlarmName' --output text
```

Kết quả mong đợi: tên 2 CloudWatch alarm (`TargetTracking-lab05-asg-AlarmHigh-...` và `...AlarmLow-...`) do AWS tự tạo.

Tạo tải CPU **có giới hạn 10 phút** trên một instance (mở session SSM như Bước 7):

```bash
timeout 600 sh -c 'yes > /dev/null & yes > /dev/null & wait' &
exit
```

`t4g.nano` có 2 vCPU, nên 2 process `yes` đẩy instance đó lên ~100%, làm trung bình nhóm vượt 40%. Theo dõi:

```bash
aws autoscaling describe-auto-scaling-groups --auto-scaling-group-names lab05-asg \
  --region "${REGION}" --profile aws-lab \
  --query 'AutoScalingGroups[0].[DesiredCapacity,length(Instances)]' --output text
```

- Kỳ vọng: sau khoảng 5–15 phút, `DesiredCapacity` tăng lên `3` và **dừng ở 3** vì `max-size 3`. Với basic monitoring (metric EC2 mỗi 5 phút), alarm phản ứng chậm. Đây là hành vi đúng, không phải lỗi.
- Sau khi tải hết (10 phút), nhóm sẽ scale-in về 2 nhưng chậm hơn scale-out (có chủ đích, để tránh dao động).
- Nếu không thấy scale: kiểm tra alarm trong CloudWatch có chuyển sang `ALARM` không, instance mới có đang trong warmup không, và nhóm đã chạm `max` chưa.

Policy và alarm sẽ tự xóa khi xóa ASG ở Bước 10.

---

## Bước 10 — Dọn dẹp (Teardown)

Thứ tự dọn: ASG → (chờ instance terminate) → ALB → Target Group → Launch Template → NAT → EIP → Security Groups → mạng → IAM.

```bash
# 1. Xóa ASG (force: terminate luôn instance) và chờ instance terminate xong
aws autoscaling delete-auto-scaling-group --auto-scaling-group-name lab05-asg --force-delete \
  --region "${REGION}" --profile aws-lab
for i in $(seq 1 30); do
  LEFT=$(ec2 describe-instances --filters Name=tag:Project,Values=aws-lab-05 \
    Name=instance-state-name,Values=pending,running,shutting-down,stopping,stopped \
    --query 'Reservations[].Instances[].InstanceId' --output text)
  [ -z "${LEFT}" ] && { echo "Tất cả instance đã terminated"; break; }
  echo "$(date +%T) còn: ${LEFT}"; sleep 10
done

# 2. Xóa ALB (listener xóa theo), chờ xóa xong, rồi xóa Target Group
aws elbv2 delete-load-balancer --load-balancer-arn "${ALB_ARN}" --region "${REGION}" --profile aws-lab
aws elbv2 wait load-balancers-deleted --load-balancer-arns "${ALB_ARN}" --region "${REGION}" --profile aws-lab
aws elbv2 delete-target-group --target-group-arn "${TG_ARN}" --region "${REGION}" --profile aws-lab

# 3. Launch Template
ec2 delete-launch-template --launch-template-name LabOrderApiTemplate > /dev/null

# 4. NAT Gateway (chờ deleted), rồi release EIP
ec2 delete-nat-gateway --nat-gateway-id "${NAT_ID}" > /dev/null
ec2 wait nat-gateway-deleted --nat-gateway-ids "${NAT_ID}"
ec2 release-address --allocation-id "${EIP_ALLOC_ID}"

# 5. Security Groups: ENI của ALB có thể còn tồn tại vài phút sau khi ALB bị xóa, nên thử lại
for sg in "${SG_APP_ID}" "${SG_ALB_ID}"; do
  for i in $(seq 1 20); do
    ec2 delete-security-group --group-id "${sg}" 2>/dev/null && { echo "deleted ${sg}"; break; }
    echo "chờ ENI giải phóng ${sg}..."; sleep 15
  done
done

# 6. Mạng
for sub in "${PUB_SUB_A}" "${PUB_SUB_B}" "${PRI_SUB_A}" "${PRI_SUB_B}"; do ec2 delete-subnet --subnet-id "${sub}"; done
ec2 delete-route-table --route-table-id "${PUB_RT_ID}"
ec2 delete-route-table --route-table-id "${PRI_RT_ID}"
ec2 detach-internet-gateway --internet-gateway-id "${IGW_ID}" --vpc-id "${VPC_ID}"
ec2 delete-internet-gateway --internet-gateway-id "${IGW_ID}"
ec2 delete-vpc --vpc-id "${VPC_ID}"

# 7. IAM (gỡ role khỏi profile, xóa profile, gỡ policy, xóa role)
aws iam remove-role-from-instance-profile --instance-profile-name LabEC2SSMProfile --role-name LabEC2SSMRole --profile aws-lab
aws iam delete-instance-profile --instance-profile-name LabEC2SSMProfile --profile aws-lab
aws iam detach-role-policy --role-name LabEC2SSMRole \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore --profile aws-lab
aws iam delete-role --role-name LabEC2SSMRole --profile aws-lab

# 8. File tạm
rm -rf "${LAB_DIR}"
```

### Kiểm tra inventory sau cleanup

```bash
echo "== Instance còn sống";   ec2 describe-instances --filters Name=tag:Project,Values=aws-lab-05 Name=instance-state-name,Values=pending,running,stopping,stopped --query 'Reservations[].Instances[].InstanceId' --output text
echo "== EBS còn sót";         ec2 describe-volumes --filters Name=tag:Project,Values=aws-lab-05 --query 'Volumes[].[VolumeId,State]' --output text
echo "== EIP";                 ec2 describe-addresses --filters Name=tag:Project,Values=aws-lab-05 --query 'Addresses[].AllocationId' --output text
echo "== NAT chưa deleted";    ec2 describe-nat-gateways --filter Name=tag:Project,Values=aws-lab-05 --query 'NatGateways[?State!=`deleted`].NatGatewayId' --output text
echo "== VPC";                 ec2 describe-vpcs --filters Name=tag:Project,Values=aws-lab-05 --query 'Vpcs[].VpcId' --output text
echo "== ALB";                 aws elbv2 describe-load-balancers --names lab05-alb --region "${REGION}" --profile aws-lab 2>&1 | grep -o LoadBalancerNotFound
echo "== Snapshot";            ec2 describe-snapshots --owner-ids self --filters Name=tag:Project,Values=aws-lab-05 --query 'Snapshots[].SnapshotId' --output text
```

Kết quả mong đợi: mọi mục rỗng, riêng ALB in `LoadBalancerNotFound`. Lab không tạo snapshot hay log group, nhưng vẫn kiểm tra cho chắc. Lưu ý dữ liệu billing có độ trễ, nên inventory qua API mới là bằng chứng cleanup, không phải Cost Explorer.

### Nếu lab dừng giữa chừng hoặc mất shell

Khôi phục biến theo tên/tag, rồi chạy lại các lệnh cleanup ở trên. Lệnh nào báo `NotFound`/`NoSuchEntity` nghĩa là tài nguyên đó chưa tạo hoặc đã xóa: bỏ qua và chạy tiếp.

```bash
export REGION="ap-southeast-1"
export LAB_DIR=$(mktemp -d)
ec2() { aws ec2 "$@" --region "${REGION}" --profile aws-lab; }
q() { ec2 "$@" --output text; }
sub() { q describe-subnets --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values="$1" --query 'Subnets[0].SubnetId'; }
rt()  { q describe-route-tables --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values="$1" --query 'RouteTables[0].RouteTableId'; }
sg()  { q describe-security-groups --filters Name=vpc-id,Values="${VPC_ID}" Name=group-name,Values="$1" --query 'SecurityGroups[0].GroupId'; }

VPC_ID=$(q describe-vpcs --filters Name=tag:Project,Values=aws-lab-05 --query 'Vpcs[0].VpcId')
IGW_ID=$(q describe-internet-gateways --filters Name=attachment.vpc-id,Values="${VPC_ID}" --query 'InternetGateways[0].InternetGatewayId')
PUB_SUB_A=$(sub lab05-public-a);  PUB_SUB_B=$(sub lab05-public-b)
PRI_SUB_A=$(sub lab05-private-a); PRI_SUB_B=$(sub lab05-private-b)
PUB_RT_ID=$(rt lab05-public-rt);  PRI_RT_ID=$(rt lab05-private-rt)
SG_ALB_ID=$(sg lab05-alb-sg);     SG_APP_ID=$(sg lab05-app-sg)
NAT_ID=$(q describe-nat-gateways --filter Name=tag:Project,Values=aws-lab-05 --query 'NatGateways[?State!=`deleted`]|[0].NatGatewayId')
EIP_ALLOC_ID=$(q describe-addresses --filters Name=tag:Project,Values=aws-lab-05 --query 'Addresses[0].AllocationId')
ALB_ARN=$(aws elbv2 describe-load-balancers --names lab05-alb --region "${REGION}" --profile aws-lab --query 'LoadBalancers[0].LoadBalancerArn' --output text 2>/dev/null)
TG_ARN=$(aws elbv2 describe-target-groups --names lab05-tg --region "${REGION}" --profile aws-lab --query 'TargetGroups[0].TargetGroupArn' --output text 2>/dev/null)

echo "VPC=${VPC_ID} NAT=${NAT_ID} EIP=${EIP_ALLOC_ID}"
echo "ALB=${ALB_ARN}"
echo "TG=${TG_ARN}"
```

Ưu tiên xóa **NAT Gateway và ALB** trước nếu không đủ thời gian dọn hết, vì đó là hai khoản tốn tiền nhất. VPC, subnet, SG, IAM role không tính phí.

---

## Checklist hoàn thành

- [ ] Có bảng ước tính chi phí và điều kiện dừng trước khi bắt đầu.
- [ ] Private route table trỏ `0.0.0.0/0 → nat-...`; giải thích được vì sao SSM cần đường này.
- [ ] IAM Role + Instance Profile với `AmazonSSMManagedInstanceCore`; biết vì sao cần `iam:PassRole`.
- [ ] Launch template: AL2023 arm64, `t4g.nano`, IMDSv2 required, app là systemd service.
- [ ] Gọi ALB khi chưa có target nhận `503`.
- [ ] Hai instance `healthy` ở hai AZ, **không có public IP**.
- [ ] `curl /order` qua ALB thấy cả hai instance ID.
- [ ] Vào được instance bằng Session Manager mà không mở port 22.
- [ ] Đo được thời gian `unhealthy` và thời gian ASG thay instance; giải thích được vai trò của `HealthCheckType=ELB`.
- [ ] (Tùy chọn) Quan sát scale-out dừng ở `max-size`.
- [ ] Cleanup xong; inventory theo tag `Project=aws-lab-05` rỗng (instance, EBS, EIP, NAT, VPC, ALB).

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| `create-auto-scaling-group` báo lỗi về `iam:PassRole` hoặc `Invalid IamInstanceProfile` | Thiếu quyền PassRole, hoặc IAM chưa propagate | Xin `iam:PassRole` giới hạn trên `LabEC2SSMRole`; chờ 15–30 giây rồi tạo lại |
| ASG không tạo được instance | Quota vCPU, AMI sai kiến trúc, instance type không có ở AZ | `aws autoscaling describe-scaling-activities --auto-scaling-group-name lab05-asg` xem `StatusMessage` |
| Target mãi `unhealthy` với `Target.Timeout` | SG app không cho 8080 từ SG ALB, hoặc app không chạy | Kiểm tra rule của `lab05-app-sg`; vào SSM chạy `curl localhost:8080/health`, `systemctl status order-api` |
| Target `unhealthy` với `Target.ResponseCodeMismatch` | `/health` trả mã khác 200 | Kiểm tra code, `journalctl -u order-api` |
| Instance bị thay liên tục, không bao giờ `healthy` | App khởi động lâu hơn grace period, hoặc user data lỗi | `aws ec2 get-console-output --instance-id <id> --latest`; tạm tăng grace period: `aws autoscaling update-auto-scaling-group --auto-scaling-group-name lab05-asg --health-check-grace-period 300` |
| ALB trả `503` sau khi đã tạo ASG | Chưa có target nào được đăng ký (ASG chưa launch xong hoặc launch lỗi) | `describe-target-health` rỗng → xem scaling activities |
| ALB trả `502`/`504` | Target có đăng ký nhưng app không trả lời (502: từ chối kết nối; 504: SG chặn hoặc treo) | Xem lý thuyết Phần 7; `describe-target-health` để xem `Reason` |
| `describe-instance-information` rỗng, SSM không vào được | Thiếu đường ra NAT, thiếu role, agent chưa đăng ký xong | Kiểm tra private RT có route tới NAT và NAT `available`; instance có profile `LabEC2SSMProfile`; chờ 1–3 phút sau boot |
| `SessionManagerPlugin is not found` | Chưa cài plugin trên máy cục bộ | Cài plugin hoặc dùng console |
| Xóa SG báo `DependencyViolation` | ENI của ALB/instance chưa giải phóng | Vòng lặp retry ở bước cleanup; nếu vẫn lỗi, `ec2 describe-network-interfaces --filters Name=group-id,Values=<sg>` |
| Xóa VPC báo `DependencyViolation` | NAT chưa `deleted`, hoặc còn ENI/subnet | `ec2 describe-network-interfaces --filters Name=vpc-id,Values=${VPC_ID}` |

## Tiếp theo

Bạn đã triển khai một dịch vụ có cân bằng tải, tự phục hồi và quản trị không cần SSH. Bài tiếp theo (Bài 06) chuyển sang lưu trữ đối tượng và phân phối nội dung: **Amazon S3, CloudFront, Route 53 và AWS Certificate Manager (ACM)**.

---

## Nhánh Laravel theo lộ trình gốc

Lab dịch vụ ở trên được nối với [dự án Laravel + Vue](../15-capstone-laravel/README.md). Đọc phần bổ sung trong bài lý thuyết 05 và bảng mốc của dự án để chọn đúng prerequisite. Đầu ra bắt buộc gồm code/fixture, happy path, negative case và inventory sau cleanup; không dùng output minh họa làm bằng chứng đã chạy.
