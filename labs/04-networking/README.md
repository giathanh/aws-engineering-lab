# Lab 04 — Mạng trong AWS: Thiết kế VPC đa AZ, Phân vùng Subnet và Xâu chuỗi Security Group

> **Lý thuyết:** [notes/04-networking.md](../../notes/04-networking.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 60–90 phút (cộng 30–40 phút nếu làm nhánh tùy chọn).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Thiết kế và triển khai một Custom VPC riêng (`10.0.0.0/16`) | Chỉnh sửa Default VPC của account |
| Chia 4 subnet trên 2 AZ (2 public, 2 private) | Tạo NAT Gateway (chỉ phân tích trên sơ đồ; Lab 05 mới tạo) |
| Tạo Internet Gateway và Route Tables | Mở cổng cho database hoặc mở mọi cổng ra `0.0.0.0/0` |
| Thiết lập Security Group Chaining | SSH vào instance hoặc tạo key pair |
| Negative test miễn phí: subnet sai CIDR, xóa VPC khi còn dependency | Để lại tài nguyên mồ côi |
| Nhánh tùy chọn: EC2 tạm để kiểm chứng route, SG stateful, NACL stateless | |

---

## Quy ước placeholder

| Placeholder / biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Tên named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `REGION` | Region học tập của bạn | `ap-southeast-1` |
| `AZ_A`, `AZ_B` | Hai AZ đầu tiên khả dụng (lấy tự động) | `ap-southeast-1a`, `ap-southeast-1b` |
| `VPC_ID`, `IGW_ID`, ... | ID tài nguyên do lab tạo, lưu trong biến shell | `vpc-0123456789abcdef0` |

Mọi tài nguyên gắn tag `Project=aws-lab-04`. Nếu mất shell, xem mục [Khôi phục biến khi mất shell](#khôi-phục-biến-khi-mất-shell).

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 01](../01-foundations/README.md) đến [Lab 03](../03-cost-management/README.md).
- [ ] Quyền EC2/VPC (giới hạn trong VPC lab nếu có thể):
  - Lab chính: `ec2:CreateVpc`, `ec2:ModifyVpcAttribute`, `ec2:DeleteVpc`, `ec2:CreateSubnet`, `ec2:ModifySubnetAttribute`, `ec2:DeleteSubnet`, `ec2:CreateInternetGateway`, `ec2:AttachInternetGateway`, `ec2:DetachInternetGateway`, `ec2:DeleteInternetGateway`, `ec2:CreateRouteTable`, `ec2:CreateRoute`, `ec2:DeleteRoute`, `ec2:AssociateRouteTable`, `ec2:DeleteRouteTable`, `ec2:CreateSecurityGroup`, `ec2:AuthorizeSecurityGroupIngress`, `ec2:DeleteSecurityGroup`, `ec2:CreateTags`, `ec2:Describe*`.
  - Nhánh tùy chọn: thêm `ec2:RunInstances`, `ec2:TerminateInstances`, `ec2:RevokeSecurityGroupEgress`, `ec2:AuthorizeSecurityGroupEgress`, `ec2:CreateNetworkAcl`, `ec2:CreateNetworkAclEntry`, `ec2:ReplaceNetworkAclAssociation`, `ec2:DeleteNetworkAcl`, `ssm:GetParameter` (lấy AMI ID).
- [ ] Có `curl` trên máy cục bộ (cho nhánh tùy chọn).
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính

| Phần | Chi phí |
|---|---|
| VPC, subnet, route table, IGW, Security Group, NACL | Không tính phí |
| Nhánh tùy chọn: 1x `t4g.nano` + 1 public IPv4 + EBS 8 GB, khoảng 30–60 phút | Dưới $0.02 (giá minh họa) |

Điều kiện dừng: nếu nhánh tùy chọn kéo dài quá 2 giờ, terminate instance trước rồi mới debug tiếp.

---

## Sơ đồ kiến trúc mạng Lab 04

```text
Region: <REGION>
┌──────────────────────────────────────────────────────────────────────────────┐
│ Lab VPC: 10.0.0.0/16                         [ Internet Gateway: lab-igw ]   │
│                                                         │                    │
│  Public Route Table (lab-public-rt): 10.0.0.0/16 → local, 0.0.0.0/0 → igw    │
│        ├──────────────────────────────┐                                      │
│   Zone A: <AZ_A>                 Zone B: <AZ_B>                              │
│   ┌──────────────────────────┐   ┌──────────────────────────┐                │
│   │ Public Subnet A          │   │ Public Subnet B          │                │
│   │ 10.0.1.0/24              │   │ 10.0.2.0/24              │                │
│   └──────────────────────────┘   └──────────────────────────┘                │
│                                                                              │
│  Private Route Table (lab-private-rt): chỉ 10.0.0.0/16 → local               │
│        ├──────────────────────────────┐                                      │
│   ┌──────────────────────────┐   ┌──────────────────────────┐                │
│   │ Private Subnet A         │   │ Private Subnet B         │                │
│   │ 10.0.10.0/24             │   │ 10.0.20.0/24             │                │
│   └──────────────────────────┘   └──────────────────────────┘                │
│                                                                              │
│  Security Groups (cấp VPC, gắn vào ENI chứ không gắn vào subnet):            │
│   lab-web-sg: inbound TCP 80 từ 0.0.0.0/0                                    │
│   lab-app-sg: inbound TCP 8080 chỉ từ source = lab-web-sg                    │
└──────────────────────────────────────────────────────────────────────────────┘
```

**Đường outbound của private subnet (chỉ phân tích, không tạo trong lab này):**
- Qua NAT Gateway: private RT `0.0.0.0/0 → nat-...` (NAT nằm ở public subnet) → IGW → internet. Tính phí theo giờ + GB + public IPv4.
- Qua VPC endpoint: S3/DynamoDB dùng gateway endpoint (miễn phí, thêm route prefix list); dịch vụ khác như SSM dùng interface endpoint (tính phí theo giờ/AZ). Traffic không ra internet.

---

## Bước 1 — Khởi tạo VPC

```bash
export REGION="ap-southeast-1"   # đổi thành Region của bạn
export TAG="{Key=Project,Value=aws-lab-04}"

# Lấy 2 AZ đầu tiên đang khả dụng (bỏ qua Local Zones / Wavelength)
read -r AZ_A AZ_B < <(aws ec2 describe-availability-zones \
  --region "${REGION}" --profile aws-lab \
  --filters Name=zone-type,Values=availability-zone Name=state,Values=available \
  --query 'AvailabilityZones[0:2].ZoneName' --output text)
export AZ_A AZ_B
echo "AZ_A=${AZ_A} AZ_B=${AZ_B}"

VPC_ID=$(aws ec2 create-vpc \
  --cidr-block 10.0.0.0/16 \
  --tag-specifications "ResourceType=vpc,Tags=[{Key=Name,Value=lab-vpc},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'Vpc.VpcId' --output text)
echo "VPC_ID=${VPC_ID}"
```

Bật DNS support và DNS hostnames (mỗi lệnh chỉ đổi được một thuộc tính):

```bash
aws ec2 modify-vpc-attribute --vpc-id "${VPC_ID}" --enable-dns-support '{"Value": true}' --region "${REGION}" --profile aws-lab
aws ec2 modify-vpc-attribute --vpc-id "${VPC_ID}" --enable-dns-hostnames '{"Value": true}' --region "${REGION}" --profile aws-lab
```

> [!NOTE]
> Khi tạo VPC, AWS tự tạo kèm **main route table**, **default NACL** (cho phép tất cả) và **default security group**. Lab không dùng chúng nhưng chúng sẽ tự xóa cùng VPC.

---

## Bước 2 — Tạo và gắn Internet Gateway

```bash
IGW_ID=$(aws ec2 create-internet-gateway \
  --tag-specifications "ResourceType=internet-gateway,Tags=[{Key=Name,Value=lab-igw},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'InternetGateway.InternetGatewayId' --output text)
echo "IGW_ID=${IGW_ID}"

aws ec2 attach-internet-gateway \
  --vpc-id "${VPC_ID}" --internet-gateway-id "${IGW_ID}" \
  --region "${REGION}" --profile aws-lab
```

---

## Bước 3 — Tạo 4 Subnets trên 2 AZ

```bash
create_subnet() {   # $1=cidr $2=az $3=name
  aws ec2 create-subnet \
    --vpc-id "${VPC_ID}" --cidr-block "$1" --availability-zone "$2" \
    --tag-specifications "ResourceType=subnet,Tags=[{Key=Name,Value=$3},${TAG}]" \
    --region "${REGION}" --profile aws-lab \
    --query 'Subnet.SubnetId' --output text
}

PUB_SUB_A=$(create_subnet 10.0.1.0/24  "${AZ_A}" lab-public-subnet-a)
PUB_SUB_B=$(create_subnet 10.0.2.0/24  "${AZ_B}" lab-public-subnet-b)
PRI_SUB_A=$(create_subnet 10.0.10.0/24 "${AZ_A}" lab-private-subnet-a)
PRI_SUB_B=$(create_subnet 10.0.20.0/24 "${AZ_B}" lab-private-subnet-b)

echo "Public:  ${PUB_SUB_A} (${AZ_A}), ${PUB_SUB_B} (${AZ_B})"
echo "Private: ${PRI_SUB_A} (${AZ_A}), ${PRI_SUB_B} (${AZ_B})"
```

Cho public subnet tự gán public IPv4 khi launch instance. Lưu ý mỗi public IPv4 đều tính phí (xem Bài 03):

```bash
aws ec2 modify-subnet-attribute --subnet-id "${PUB_SUB_A}" --map-public-ip-on-launch --region "${REGION}" --profile aws-lab
aws ec2 modify-subnet-attribute --subnet-id "${PUB_SUB_B}" --map-public-ip-on-launch --region "${REGION}" --profile aws-lab
```

Xác minh mỗi subnet có 251 IP khả dụng:

```bash
aws ec2 describe-subnets --filters Name=vpc-id,Values="${VPC_ID}" \
  --region "${REGION}" --profile aws-lab \
  --query 'Subnets[].[Tags[?Key==`Name`]|[0].Value,CidrBlock,AvailabilityZone,AvailableIpAddressCount]' \
  --output table
```

Kết quả mong đợi: 4 dòng, cột cuối đều là `251`.

---

## Bước 4 — Negative test: CIDR không hợp lệ (miễn phí)

**Mục đích:** quan sát các quy tắc CIDR của AWS qua lỗi thật. Các lệnh dưới đây **phải thất bại**.

```bash
# 1. Subnet nhỏ hơn /28
aws ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block 10.0.100.0/29 \
  --availability-zone "${AZ_A}" --region "${REGION}" --profile aws-lab

# 2. Subnet nằm ngoài dải CIDR của VPC
aws ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block 10.1.0.0/24 \
  --availability-zone "${AZ_A}" --region "${REGION}" --profile aws-lab

# 3. Subnet chồng lấn subnet đã có
aws ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block 10.0.1.0/25 \
  --availability-zone "${AZ_A}" --region "${REGION}" --profile aws-lab
```

Kết quả mong đợi (nội dung thông báo có thể khác đôi chút):
```text
1. An error occurred (InvalidSubnet.Range) ... The CIDR '10.0.100.0/29' is invalid.
2. An error occurred (InvalidSubnet.Range) ... The CIDR '10.1.0.0/24' is invalid.
3. An error occurred (InvalidSubnet.Conflict) ... The CIDR '10.0.1.0/25' conflicts with another subnet
```

Nếu lệnh nào **thành công** (ngoài dự kiến), ghi lại subnet ID và xóa ngay bằng `aws ec2 delete-subnet`.

---

## Bước 5 — Cấu hình Public Route Table

```bash
PUB_RT_ID=$(aws ec2 create-route-table \
  --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=lab-public-rt},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'RouteTable.RouteTableId' --output text)

aws ec2 create-route \
  --route-table-id "${PUB_RT_ID}" \
  --destination-cidr-block 0.0.0.0/0 \
  --gateway-id "${IGW_ID}" \
  --region "${REGION}" --profile aws-lab

aws ec2 associate-route-table --subnet-id "${PUB_SUB_A}" --route-table-id "${PUB_RT_ID}" --region "${REGION}" --profile aws-lab
aws ec2 associate-route-table --subnet-id "${PUB_SUB_B}" --route-table-id "${PUB_RT_ID}" --region "${REGION}" --profile aws-lab
```

`create-route` trả về `{"Return": true}`. Mỗi `associate-route-table` trả về một `AssociationId`.

---

## Bước 6 — Cấu hình Private Route Table

```bash
PRI_RT_ID=$(aws ec2 create-route-table \
  --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=lab-private-rt},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'RouteTable.RouteTableId' --output text)

aws ec2 associate-route-table --subnet-id "${PRI_SUB_A}" --route-table-id "${PRI_RT_ID}" --region "${REGION}" --profile aws-lab
aws ec2 associate-route-table --subnet-id "${PRI_SUB_B}" --route-table-id "${PRI_RT_ID}" --region "${REGION}" --profile aws-lab
```

Private RT chỉ có local route. Nếu bỏ qua bước này, private subnet sẽ dùng main route table (cũng chỉ có local route). Tạo RT riêng giúp ý đồ thiết kế rõ ràng và sau này dễ thêm route tới NAT.

---

## Bước 7 — Thiết lập Security Group Chaining

### 1. Web Security Group (`lab-web-sg`)

Dành cho tầng tiếp nhận (Load Balancer hoặc web server): inbound cổng 80 từ mọi nơi.

```bash
SG_WEB_ID=$(aws ec2 create-security-group \
  --group-name "lab-web-sg" \
  --description "Security group cho Web Tier / ALB" \
  --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=lab-web-sg},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'GroupId' --output text)

aws ec2 authorize-security-group-ingress \
  --group-id "${SG_WEB_ID}" --protocol tcp --port 80 --cidr 0.0.0.0/0 \
  --region "${REGION}" --profile aws-lab
```

### 2. App Security Group (`lab-app-sg`)

Dành cho tầng Order Service: **chỉ** nhận cổng 8080 nếu nguồn là ENI mang `lab-web-sg`.

```bash
SG_APP_ID=$(aws ec2 create-security-group \
  --group-name "lab-app-sg" \
  --description "Security group cho App Tier noi bo" \
  --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=security-group,Tags=[{Key=Name,Value=lab-app-sg},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'GroupId' --output text)

aws ec2 authorize-security-group-ingress \
  --group-id "${SG_APP_ID}" --protocol tcp --port 8080 --source-group "${SG_WEB_ID}" \
  --region "${REGION}" --profile aws-lab
```

---

## Bước 8 — Kiểm chứng cấu hình

### Route tables

```bash
for rt in "${PUB_RT_ID}" "${PRI_RT_ID}"; do
  echo "== ${rt}"
  aws ec2 describe-route-tables --route-table-ids "${rt}" \
    --region "${REGION}" --profile aws-lab \
    --query 'RouteTables[0].Routes[].[DestinationCidrBlock,GatewayId,State]' --output text
done
```

Kết quả mong đợi:
```text
== rtb-public...
10.0.0.0/16   local          active
0.0.0.0/0     igw-0abc...    active
== rtb-private...
10.0.0.0/16   local          active
```

### Security group chaining

```bash
aws ec2 describe-security-groups --group-ids "${SG_APP_ID}" \
  --region "${REGION}" --profile aws-lab \
  --query 'SecurityGroups[0].IpPermissions[].{Port:FromPort,SourceSG:UserIdGroupPairs[0].GroupId,Cidr:IpRanges}' \
  --output json
```

Kết quả mong đợi: `Port: 8080`, `SourceSG` bằng `${SG_WEB_ID}`, `Cidr: []`. Rule không chứa dải IP nào, nên client nào không mang `lab-web-sg` (kể cả client trong cùng VPC) đều không vào được 8080. Đây mới là **kiểm tra cấu hình**; nhánh tùy chọn bên dưới mới kiểm chứng bằng traffic thật.

### Negative test: xóa VPC khi còn dependency

```bash
aws ec2 delete-vpc --vpc-id "${VPC_ID}" --region "${REGION}" --profile aws-lab
```

Kết quả mong đợi:
```text
An error occurred (DependencyViolation) when calling the DeleteVpc operation: The vpc 'vpc-...' has dependencies and cannot be deleted.
```
✅ AWS không cho xóa VPC khi còn subnet, IGW, SG... Đây là lý do cleanup phải đi theo thứ tự.

---

## Bước 9 (tùy chọn, tính phí vài cent) — Kiểm chứng bằng traffic thật

**Mục đích:** chạy một web server tạm trong public subnet A, rồi lần lượt thay đổi route, SG và NACL để thấy tác động thật lên kết nối.

### 9.1. Launch instance tạm

```bash
AMI_ID=$(aws ssm get-parameter \
  --name /aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64 \
  --region "${REGION}" --profile aws-lab --query 'Parameter.Value' --output text)

export LAB_DIR=$(mktemp -d)
cat <<'EOF' > "${LAB_DIR}/web.sh"
#!/bin/bash
mkdir -p /srv/web && echo "hello from lab-04" > /srv/web/index.html
cd /srv/web && nohup python3 -m http.server 80 > /var/log/lab-web.log 2>&1 &
EOF

TEST_INSTANCE_ID=$(aws ec2 run-instances \
  --image-id "${AMI_ID}" --instance-type t4g.nano --count 1 \
  --subnet-id "${PUB_SUB_A}" --security-group-ids "${SG_WEB_ID}" \
  --user-data "file://${LAB_DIR}/web.sh" \
  --metadata-options HttpTokens=required \
  --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=lab04-test-web},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'Instances[0].InstanceId' --output text)

aws ec2 wait instance-running --instance-ids "${TEST_INSTANCE_ID}" --region "${REGION}" --profile aws-lab
TEST_IP=$(aws ec2 describe-instances --instance-ids "${TEST_INSTANCE_ID}" \
  --region "${REGION}" --profile aws-lab \
  --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)
echo "TEST_IP=${TEST_IP}"

sleep 60   # chờ user data khởi động web server
curl -m 5 "http://${TEST_IP}/"
```

Kết quả mong đợi: `hello from lab-04`. Nếu `curl` báo `Connection refused`, chờ thêm 30 giây rồi thử lại.

### 9.2. Route quyết định public/private

Xóa route tới IGW khỏi public RT. Subnet A lúc này trở thành "private" dù instance vẫn có public IP:

```bash
aws ec2 delete-route --route-table-id "${PUB_RT_ID}" --destination-cidr-block 0.0.0.0/0 \
  --region "${REGION}" --profile aws-lab
curl -m 5 "http://${TEST_IP}/"; echo "exit=$?"
```

Kết quả mong đợi: `curl: (28) Connection timed out ...`, `exit=28`.

Khôi phục route:

```bash
aws ec2 create-route --route-table-id "${PUB_RT_ID}" --destination-cidr-block 0.0.0.0/0 \
  --gateway-id "${IGW_ID}" --region "${REGION}" --profile aws-lab
curl -m 5 "http://${TEST_IP}/"
```

Kết quả mong đợi: `hello from lab-04` trở lại.

### 9.3. Security Group là stateful

Xóa **toàn bộ** outbound rule của `lab-web-sg`:

```bash
aws ec2 revoke-security-group-egress --group-id "${SG_WEB_ID}" \
  --ip-permissions '[{"IpProtocol":"-1","IpRanges":[{"CidrIp":"0.0.0.0/0"}]}]' \
  --region "${REGION}" --profile aws-lab
curl -m 5 "http://${TEST_IP}/"
```

Kết quả mong đợi: **vẫn** nhận được `hello from lab-04`. SG tự cho phép response của kết nối inbound đã được chấp nhận, không cần outbound rule.

Khôi phục outbound mặc định:

```bash
aws ec2 authorize-security-group-egress --group-id "${SG_WEB_ID}" \
  --ip-permissions '[{"IpProtocol":"-1","IpRanges":[{"CidrIp":"0.0.0.0/0"}]}]' \
  --region "${REGION}" --profile aws-lab
```

### 9.4. NACL là stateless

Tạo custom NACL chỉ có inbound port 80, **không có outbound rule nào**:

```bash
NACL_ID=$(aws ec2 create-network-acl --vpc-id "${VPC_ID}" \
  --tag-specifications "ResourceType=network-acl,Tags=[{Key=Name,Value=lab-test-nacl},${TAG}]" \
  --region "${REGION}" --profile aws-lab \
  --query 'NetworkAcl.NetworkAclId' --output text)

aws ec2 create-network-acl-entry --network-acl-id "${NACL_ID}" \
  --ingress --rule-number 100 --protocol tcp --port-range From=80,To=80 \
  --cidr-block 0.0.0.0/0 --rule-action allow \
  --region "${REGION}" --profile aws-lab

# Lấy association hiện tại của subnet A (đang trỏ tới default NACL)
ASSOC_ID=$(aws ec2 describe-network-acls \
  --filters Name=association.subnet-id,Values="${PUB_SUB_A}" \
  --region "${REGION}" --profile aws-lab \
  --query "NetworkAcls[0].Associations[?SubnetId=='${PUB_SUB_A}'].NetworkAclAssociationId" --output text)
DEFAULT_NACL_ID=$(aws ec2 describe-network-acls \
  --filters Name=vpc-id,Values="${VPC_ID}" Name=default,Values=true \
  --region "${REGION}" --profile aws-lab \
  --query 'NetworkAcls[0].NetworkAclId' --output text)

# Gắn subnet A vào custom NACL
ASSOC_ID=$(aws ec2 replace-network-acl-association \
  --association-id "${ASSOC_ID}" --network-acl-id "${NACL_ID}" \
  --region "${REGION}" --profile aws-lab --query NewAssociationId --output text)

curl -m 5 "http://${TEST_IP}/"; echo "exit=$?"
```

Kết quả mong đợi: timeout, `exit=28`. Request vào được (inbound 80 cho phép), nhưng response gửi tới **ephemeral port** của máy bạn bị NACL outbound chặn.

Thêm outbound cho dải ephemeral:

```bash
aws ec2 create-network-acl-entry --network-acl-id "${NACL_ID}" \
  --egress --rule-number 100 --protocol tcp --port-range From=1024,To=65535 \
  --cidr-block 0.0.0.0/0 --rule-action allow \
  --region "${REGION}" --profile aws-lab
curl -m 5 "http://${TEST_IP}/"
```

Kết quả mong đợi: `hello from lab-04`.

Trả subnet A về default NACL:

```bash
aws ec2 replace-network-acl-association \
  --association-id "${ASSOC_ID}" --network-acl-id "${DEFAULT_NACL_ID}" \
  --region "${REGION}" --profile aws-lab
```

### 9.5. Kết thúc nhánh tùy chọn

```bash
aws ec2 terminate-instances --instance-ids "${TEST_INSTANCE_ID}" --region "${REGION}" --profile aws-lab
aws ec2 wait instance-terminated --instance-ids "${TEST_INSTANCE_ID}" --region "${REGION}" --profile aws-lab
aws ec2 delete-network-acl --network-acl-id "${NACL_ID}" --region "${REGION}" --profile aws-lab
rm -rf "${LAB_DIR}"
```

Root volume có `DeleteOnTermination=true` nên tự xóa theo instance; public IPv4 tự auto-assign cũng được giải phóng.

---

## Bước 10 — Dọn dẹp (Teardown)

Nếu đã làm Bước 9, đảm bảo 9.5 đã chạy xong (instance `terminated`, NACL đã xóa). Sau đó xóa theo thứ tự phụ thuộc:

```bash
# 1. Security Groups (lab-app-sg tham chiếu lab-web-sg nên xóa trước)
aws ec2 delete-security-group --group-id "${SG_APP_ID}" --region "${REGION}" --profile aws-lab
aws ec2 delete-security-group --group-id "${SG_WEB_ID}" --region "${REGION}" --profile aws-lab

# 2. Subnets (association với route table/NACL tự mất theo)
for sub in "${PUB_SUB_A}" "${PUB_SUB_B}" "${PRI_SUB_A}" "${PRI_SUB_B}"; do
  aws ec2 delete-subnet --subnet-id "${sub}" --region "${REGION}" --profile aws-lab
done

# 3. Custom Route Tables
aws ec2 delete-route-table --route-table-id "${PUB_RT_ID}" --region "${REGION}" --profile aws-lab
aws ec2 delete-route-table --route-table-id "${PRI_RT_ID}" --region "${REGION}" --profile aws-lab

# 4. Detach và xóa Internet Gateway
aws ec2 detach-internet-gateway --internet-gateway-id "${IGW_ID}" --vpc-id "${VPC_ID}" --region "${REGION}" --profile aws-lab
aws ec2 delete-internet-gateway --internet-gateway-id "${IGW_ID}" --region "${REGION}" --profile aws-lab

# 5. VPC (main route table, default NACL, default SG tự xóa theo)
aws ec2 delete-vpc --vpc-id "${VPC_ID}" --region "${REGION}" --profile aws-lab
```

Xác minh inventory:

```bash
aws ec2 describe-vpcs --filters Name=tag:Project,Values=aws-lab-04 \
  --region "${REGION}" --profile aws-lab --query 'Vpcs[].VpcId' --output text
# Mong đợi: rỗng

aws ec2 describe-instances --filters Name=tag:Project,Values=aws-lab-04 \
  Name=instance-state-name,Values=pending,running,stopping,stopped \
  --region "${REGION}" --profile aws-lab --query 'Reservations[].Instances[].InstanceId' --output text
# Mong đợi: rỗng
```

> Bài 05 tự tạo mạng riêng, nên không cần giữ lại VPC này.

### Khôi phục biến khi mất shell

Nếu lab dừng giữa chừng và biến đã mất, tìm lại tài nguyên theo tag rồi chạy các lệnh cleanup ở trên (bỏ qua lệnh nào báo `NotFound`):

```bash
export REGION="ap-southeast-1"
ec2q() { aws ec2 "$@" --region "${REGION}" --profile aws-lab --output text; }
byname() { ec2q describe-subnets --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values="$1" --query 'Subnets[0].SubnetId'; }

VPC_ID=$(ec2q describe-vpcs --filters Name=tag:Project,Values=aws-lab-04 --query 'Vpcs[0].VpcId')
IGW_ID=$(ec2q describe-internet-gateways --filters Name=attachment.vpc-id,Values="${VPC_ID}" --query 'InternetGateways[0].InternetGatewayId')
PUB_SUB_A=$(byname lab-public-subnet-a);  PUB_SUB_B=$(byname lab-public-subnet-b)
PRI_SUB_A=$(byname lab-private-subnet-a); PRI_SUB_B=$(byname lab-private-subnet-b)
PUB_RT_ID=$(ec2q describe-route-tables --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values=lab-public-rt --query 'RouteTables[0].RouteTableId')
PRI_RT_ID=$(ec2q describe-route-tables --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values=lab-private-rt --query 'RouteTables[0].RouteTableId')
SG_WEB_ID=$(ec2q describe-security-groups --filters Name=vpc-id,Values="${VPC_ID}" Name=group-name,Values=lab-web-sg --query 'SecurityGroups[0].GroupId')
SG_APP_ID=$(ec2q describe-security-groups --filters Name=vpc-id,Values="${VPC_ID}" Name=group-name,Values=lab-app-sg --query 'SecurityGroups[0].GroupId')
TEST_INSTANCE_ID=$(ec2q describe-instances --filters Name=tag:Name,Values=lab04-test-web Name=instance-state-name,Values=pending,running,stopping,stopped --query 'Reservations[0].Instances[0].InstanceId')
NACL_ID=$(ec2q describe-network-acls --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values=lab-test-nacl --query 'NetworkAcls[0].NetworkAclId')

echo "VPC=${VPC_ID} IGW=${IGW_ID} INSTANCE=${TEST_INSTANCE_ID} NACL=${NACL_ID}"
```

Giá trị `None` nghĩa là tài nguyên đó không tồn tại. Nếu `TEST_INSTANCE_ID` khác `None`, terminate và chờ trước. Nếu `NACL_ID` khác `None`, xóa NACL **sau khi** đã xóa subnet (subnet đang gắn NACL thì không xóa NACL được).

---

## Checklist hoàn thành

- [ ] Tạo VPC `10.0.0.0/16`, bật `enableDnsSupport` và `enableDnsHostnames`.
- [ ] Tạo và gắn Internet Gateway.
- [ ] 4 subnet trên 2 AZ, mỗi subnet có `AvailableIpAddressCount = 251`.
- [ ] Gặp `InvalidSubnet.Range` / `InvalidSubnet.Conflict` ở negative test CIDR.
- [ ] Public RT có `0.0.0.0/0 → igw`; private RT chỉ có `local`.
- [ ] `lab-app-sg` nhận 8080 chỉ từ `lab-web-sg`.
- [ ] Gặp `DependencyViolation` khi xóa VPC sớm và giải thích được vì sao.
- [ ] (Tùy chọn) Quan sát timeout khi xóa route IGW; SG vẫn trả response khi không có outbound; NACL chặn response khi thiếu ephemeral port.
- [ ] Xóa sạch tài nguyên, inventory theo tag `Project=aws-lab-04` rỗng.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Khắc phục |
|---|---|---|
| `InvalidVpcID.NotFound` | Biến `VPC_ID` rỗng hoặc sai Region | `echo "${VPC_ID} ${REGION}"`; khôi phục biến theo mục trên |
| `AZ_A`/`AZ_B` rỗng | Region chưa enable hoặc thiếu quyền `ec2:DescribeAvailabilityZones` | Kiểm tra `REGION`; chạy lại lệnh lấy AZ |
| `curl` timeout ngay ở 9.1 | Web server chưa chạy, route/SG sai | Kiểm tra route public RT, rule port 80 của `lab-web-sg`; chờ thêm rồi thử lại |
| `curl` báo `Connection refused` | Đã tới instance nhưng web server chưa lắng nghe | Chờ user data chạy xong (30–90 giây) |
| `DependencyViolation` khi xóa SG | SG còn được tham chiếu hoặc còn gắn với ENI (instance chưa terminate xong) | Xóa `lab-app-sg` trước; `aws ec2 wait instance-terminated` trước khi xóa `lab-web-sg` |
| `DependencyViolation` khi xóa VPC | Còn subnet, IGW, SG hoặc ENI | `aws ec2 describe-network-interfaces --filters Name=vpc-id,Values=${VPC_ID}` để tìm phần còn sót |
| Không xóa được NACL | Subnet vẫn đang gắn NACL | Trả subnet về default NACL (9.4) hoặc xóa subnet trước |

## Tiếp theo

Bạn đã nắm nền móng mạng của AWS. Bài tiếp theo đưa máy chủ và dịch vụ lên chạy: **EC2, EBS, Application Load Balancer, Auto Scaling Group và quản trị an toàn bằng Systems Manager (SSM)**.
