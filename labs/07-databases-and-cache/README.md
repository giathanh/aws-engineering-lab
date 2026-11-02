# Lab 07 — Database và cache: DynamoDB theo access pattern, RDS PostgreSQL private và restore thật

> **Lý thuyết:** [notes/07-databases-and-cache.md](../../notes/07-databases-and-cache.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** Phần A (DynamoDB) khoảng 30 phút; Phần B (RDS) khoảng 90–120 phút, phần lớn là chờ tạo/restore DB; Phần C tùy chọn thêm 30–40 phút.
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output và số đo là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| **A.** Bảng DynamoDB on-demand theo access pattern đơn hàng, GSI theo khách hàng | Dùng `Scan` để tìm dữ liệu |
| **A.** Conditional write, optimistic locking với `version`, negative case GSI strongly consistent | |
| **B.** VPC lab riêng: 1 public subnet cho client, 2 private subnet cho DB | Dùng Default VPC (subnet của nó là public) |
| **B.** RDS PostgreSQL `db.t4g.micro` Single-AZ, `--no-publicly-accessible`, SG chaining | Mở 5432 theo CIDR hoặc ra Internet |
| **B.** Mật khẩu master do RDS quản lý trong Secrets Manager | Gõ hoặc commit mật khẩu |
| **B.** Seed 200.000 dòng, đo query trước/sau index | Multi-AZ, Provisioned IOPS, Aurora (chỉ phân tích trong lý thuyết) |
| **B.** Snapshot → "xóa nhầm" → restore sang instance mới → chứng minh dữ liệu về | Restore đè lên instance gốc (RDS không làm vậy) |
| **C (tùy chọn).** Cache-aside với ElastiCache Valkey: hit/miss, stale data, fallback khi cache lỗi | Coi cache là nguồn dữ liệu chuẩn |
| Cleanup theo dependency, kiểm tra inventory, nêu rõ cái gì còn giữ | |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `VPC_ID`, `PUB_SUB`, `PRI_SUB_A`, `PRI_SUB_B` | Mạng lab tạo ở Bước 3 | `vpc-0123456789abcdef0` |
| `SG_CLIENT`, `SG_DB`, `SG_CACHE` | Security group của client, DB, cache | `sg-0abc...` |
| `CLIENT_ID` | EC2 client (chỉ vào qua Session Manager) | `i-0abc...` |
| `DB_HOST`, `SECRET_ARN` | Endpoint RDS và ARN secret do RDS quản lý | `lab07-pg.xxxx.ap-southeast-1.rds.amazonaws.com` |

Tên cố định: `lab07-*` cho mạng/EC2/RDS, `OrdersLab07` cho bảng DynamoDB, `Lab07ClientRole`/`Lab07ClientProfile` cho IAM. Mọi tài nguyên hỗ trợ tag đều mang `Project=aws-lab-07`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 04](../04-networking/README.md) và [Lab 05](../05-compute-and-load-balancing/README.md) (VPC, SG chaining, Session Manager). Lab này tự tạo mạng riêng, không dùng tài nguyên của lab trước.
- [ ] Đã cài Session Manager plugin (`session-manager-plugin --version`), `jq`.
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **DynamoDB:** `dynamodb:CreateTable`, `DescribeTable`, `PutItem`, `UpdateItem`, `GetItem`, `Query`, `DeleteTable`, `TagResource` trên `arn:aws:dynamodb:*:*:table/OrdersLab07*`.
  - **EC2/VPC:** tạo/xóa VPC, subnet, IGW, route table, security group, `ec2:RunInstances`, `TerminateInstances`, `Describe*`, `CreateTags`.
  - **IAM:** `iam:CreateRole`, `PutRolePolicy`, `DeleteRolePolicy`, `AttachRolePolicy`, `DetachRolePolicy`, `DeleteRole`, `CreateInstanceProfile`, `AddRoleToInstanceProfile`, `RemoveRoleFromInstanceProfile`, `DeleteInstanceProfile`, **`iam:PassRole` trên `Lab07ClientRole`**.
  - **RDS:** `rds:CreateDBSubnetGroup`, `CreateDBInstance`, `DescribeDBInstances`, `DescribeDBEngineVersions`, `CreateDBSnapshot`, `DescribeDBSnapshots`, `RestoreDBInstanceFromDBSnapshot`, `DeleteDBInstance`, `DeleteDBSnapshot`, `DeleteDBSubnetGroup`, `AddTagsToResource`. Tính năng managed master password cần thêm `secretsmanager:CreateSecret`, `secretsmanager:TagResource` và `kms:DescribeKey` (RDS gọi thay bạn), và có thể cần `iam:CreateServiceLinkedRole` lần đầu dùng RDS.
  - **SSM:** `ssm:GetParameter`, `ssm:StartSession`, `ssm:DescribeInstanceInformation`.
  - **Phần C:** `elasticache:CreateCacheSubnetGroup`, `CreateReplicationGroup`, `DescribeReplicationGroups`, `DeleteReplicationGroup`, `DeleteCacheSubnetGroup`.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại [RDS pricing](https://aws.amazon.com/rds/postgresql/pricing/) cho Region của bạn)

| Tài nguyên | Số lượng | Đơn giá minh họa | Mỗi giờ |
|---|---|---|---|
| RDS `db.t4g.micro` PostgreSQL Single-AZ | 1 (2 trong lúc restore) | ~$0.025/giờ | ~$0.025–0.05 |
| RDS gp3 20 GB | 1–2 | ~$0.13/GB-tháng | ~$0.004–0.008 |
| EC2 `t4g.nano` client + EBS 8 GB | 1 | ~$0.0053/giờ | ~$0.006 |
| Public IPv4 của client | 1 | ~$0.005/giờ | ~$0.005 |
| Secrets Manager (secret do RDS tạo) | 1–2 | ~$0.40/secret-tháng, tính theo thời gian tồn tại | < $0.002 |
| DynamoDB on-demand | vài chục request, vài KB | ~$1.25/triệu write, ~$0.25/triệu read | < $0.001 |
| Manual snapshot 20 GB | 1 | ~$0.095/GB-tháng | < $0.003 |
| **Tổng phần A + B** | | | **~$0.05–0.08/giờ** |
| ElastiCache `cache.t4g.micro` (Phần C) | 1 | ~$0.02/giờ | ~$0.02 |

- Làm lab 3 giờ: khoảng **$0.20–0.30**.
- **Quên xóa 1 tháng: khoảng $30–50**, chủ yếu là RDS (và gấp đôi nếu quên xóa bản restore). Manual snapshot **không** tự xóa khi xóa DB và tính tiền theo tháng.
- Không dựa vào Free Tier: điều kiện Free Tier khác nhau giữa account cũ và mới.
- **Điều kiện dừng:** nếu sau 3 giờ chưa xong, chạy cleanup (Bước 11) rồi làm lại sau.

---

## Sơ đồ kiến trúc Lab 07

```text
Phần A (không cần VPC)                      ┌──────────────────────────────────┐
  Laptop ── AWS CLI ──────────────────────▶ │ DynamoDB OrdersLab07 (on-demand) │
                                            │ PK=order_id  SK=item_id          │
                                            │ GSI customer_id-index            │
                                            └──────────────────────────────────┘
Phần B/C
┌────────────────────── VPC lab07 10.7.0.0/16 ──────────────────────────────────┐
│ Public subnet 10.7.0.0/24 (AZ-a)        Private A 10.7.10.0/24 │ Private B .11 │
│ ┌─────────────────────────────┐         ┌──────────────────────┴────────────┐ │
│ │ EC2 lab07-client (t4g.nano) │──5432──▶│ RDS lab07-pg (Single-AZ, gp3 20GB)│ │
│ │ SG: lab07-client-sg         │         │ SG: lab07-db-sg (từ client-sg)    │ │
│ │ không inbound; SSM qua IGW  │         ├───────────────────────────────────┤ │
│ │ psql, valkey-cli            │──6379──▶│ RDS lab07-pg-restored (Bước 9)    │ │
│ └─────────────────────────────┘         │ ElastiCache lab07-cache (Phần C)  │ │
│        ▲ Session Manager                └───────────────────────────────────┘ │
└────────┼──────────────────────────────────────────────────────────────────────┘
      Laptop (không mở port 22)        Secrets Manager: mật khẩu master do RDS quản lý
```

Client ở public subnet có public IP để cài package và nói chuyện với SSM/Secrets Manager mà không cần NAT Gateway; SG của client **không có inbound rule**. Database nằm ở private subnet, route table không có đường ra Internet.

---

## Phần A — DynamoDB

### Bước 1 — Bảng theo access pattern

Access pattern của dịch vụ đơn hàng:

| # | Câu hỏi | Thao tác |
|---|---|---|
| AP1 | Lấy mọi dòng hàng của đơn `ORD-1001` | `Query` trên bảng, `order_id = :oid` |
| AP2 | Lấy mọi đơn của khách `CUST-99`, mới nhất trước | `Query` trên GSI, `customer_id = :cid`, sắp theo `created_at` |
| AP3 | Hủy đơn chỉ khi còn `PENDING` | `UpdateItem` với condition |

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"

aws dynamodb create-table \
  --table-name OrdersLab07 \
  --attribute-definitions \
    AttributeName=order_id,AttributeType=S \
    AttributeName=item_id,AttributeType=S \
    AttributeName=customer_id,AttributeType=S \
    AttributeName=created_at,AttributeType=S \
  --key-schema AttributeName=order_id,KeyType=HASH AttributeName=item_id,KeyType=RANGE \
  --billing-mode PAY_PER_REQUEST \
  --global-secondary-indexes '[{
    "IndexName": "customer_id-index",
    "KeySchema": [
      {"AttributeName": "customer_id", "KeyType": "HASH"},
      {"AttributeName": "created_at",  "KeyType": "RANGE"}
    ],
    "Projection": {"ProjectionType": "INCLUDE", "NonKeyAttributes": ["status", "price"]}
  }]' \
  --tags Key=Project,Value=aws-lab-07 >/dev/null

aws dynamodb wait table-exists --table-name OrdersLab07
aws dynamodb describe-table --table-name OrdersLab07 \
  --query 'Table.[TableStatus, GlobalSecondaryIndexes[0].IndexStatus]' --output text
```

*Kỳ vọng:* `ACTIVE  ACTIVE`. Projection `INCLUDE` chỉ chép những thuộc tính AP2 cần sang index, rẻ hơn `ALL`.

### Bước 2 — Ghi dữ liệu và query theo key

```bash
put() {
  aws dynamodb put-item --table-name OrdersLab07 --item "$1"
}
put '{"order_id":{"S":"ORD-1001"},"item_id":{"S":"ITEM#LAPTOP"},"customer_id":{"S":"CUST-99"},"created_at":{"S":"2026-10-30T09:00:00Z"},"price":{"N":"25000000"},"status":{"S":"PENDING"},"version":{"N":"1"}}'
put '{"order_id":{"S":"ORD-1001"},"item_id":{"S":"ITEM#MOUSE"},"customer_id":{"S":"CUST-99"},"created_at":{"S":"2026-10-30T09:00:00Z"},"price":{"N":"500000"},"status":{"S":"PENDING"},"version":{"N":"1"}}'
put '{"order_id":{"S":"ORD-1002"},"item_id":{"S":"ITEM#BOOK"},"customer_id":{"S":"CUST-99"},"created_at":{"S":"2026-10-31T08:00:00Z"},"price":{"N":"120000"},"status":{"S":"PAID"},"version":{"N":"1"}}'

# AP1: mọi dòng hàng của một đơn
aws dynamodb query --table-name OrdersLab07 \
  --key-condition-expression "order_id = :oid" \
  --expression-attribute-values '{":oid":{"S":"ORD-1001"}}' \
  --return-consumed-capacity TOTAL \
  --query '{items: Items[].item_id.S, rcu: ConsumedCapacity.CapacityUnits}'

# AP2: đơn của khách, mới nhất trước
aws dynamodb query --table-name OrdersLab07 --index-name customer_id-index \
  --key-condition-expression "customer_id = :cid" \
  --expression-attribute-values '{":cid":{"S":"CUST-99"}}' \
  --no-scan-index-forward \
  --query 'Items[].[order_id.S, created_at.S, status.S]' --output text
```

*Kỳ vọng (minh họa):* AP1 trả `["ITEM#LAPTOP", "ITEM#MOUSE"]` và `rcu` khoảng `0.5` (eventually consistent, dưới 4 KB). AP2 in `ORD-1002` trước, rồi hai dòng của `ORD-1001`.

### Bước 3 — Negative case: GSI không hỗ trợ strongly consistent read

```bash
aws dynamodb query --table-name OrdersLab07 --index-name customer_id-index \
  --key-condition-expression "customer_id = :cid" \
  --expression-attribute-values '{":cid":{"S":"CUST-99"}}' --consistent-read
```

*Kỳ vọng:* `ValidationException: Consistent reads are not supported on global secondary indexes`. Nếu nghiệp vụ cần đọc ngay sau ghi, đọc từ bảng chính bằng key.

### Bước 4 — Conditional write và cạnh tranh cập nhật

Hủy đơn chỉ khi còn `PENDING` (AP3), với `ORD-1002` đang `PAID`:

```bash
aws dynamodb update-item --table-name OrdersLab07 \
  --key '{"order_id":{"S":"ORD-1002"},"item_id":{"S":"ITEM#BOOK"}}' \
  --update-expression "SET #s = :cancel" \
  --condition-expression "#s = :pending" \
  --expression-attribute-names '{"#s":"status"}' \
  --expression-attribute-values '{":cancel":{"S":"CANCELLED"},":pending":{"S":"PENDING"}}' \
  --return-values-on-condition-check-failure ALL_OLD
```

*Kỳ vọng:* `ConditionalCheckFailedException`, kèm item hiện tại (`status = PAID`) nhờ `ALL_OLD`. Item không đổi.

Optimistic locking: hai worker cùng đọc `ORD-1001/ITEM#LAPTOP` ở `version = 1`, rồi cùng ghi.

```bash
bump() {  # $1 = trạng thái mới, $2 = version mà worker đã đọc
  aws dynamodb update-item --table-name OrdersLab07 \
    --key '{"order_id":{"S":"ORD-1001"},"item_id":{"S":"ITEM#LAPTOP"}}' \
    --update-expression "SET #s = :s, #v = #v + :one" \
    --condition-expression "#v = :seen" \
    --expression-attribute-names '{"#s":"status","#v":"version"}' \
    --expression-attribute-values "{\":s\":{\"S\":\"$1\"},\":one\":{\"N\":\"1\"},\":seen\":{\"N\":\"$2\"}}" \
    --return-values UPDATED_NEW --query 'Attributes.[status.S, version.N]' --output text
}
bump PAID 1        # worker A
bump CANCELLED 1   # worker B, vẫn tin version = 1
```

*Kỳ vọng:* worker A in `PAID 2`; worker B nhận `ConditionalCheckFailedException`. B phải đọc lại item (thấy `version = 2`, `PAID`) và quyết định lại theo nghiệp vụ, thay vì ghi đè mù.

---

## Phần B — RDS PostgreSQL private

### Bước 5 — Mạng lab và client

```bash
tag() { echo "ResourceType=$1,Tags=[{Key=Project,Value=aws-lab-07},{Key=Name,Value=$2}]"; }
read -r AZ_A AZ_B < <(aws ec2 describe-availability-zones --filters Name=state,Values=available \
  --query 'AvailabilityZones[0:2].ZoneName' --output text)

VPC_ID=$(aws ec2 create-vpc --cidr-block 10.7.0.0/16 --tag-specifications "$(tag vpc lab07-vpc)" \
  --query Vpc.VpcId --output text)
aws ec2 modify-vpc-attribute --vpc-id "${VPC_ID}" --enable-dns-hostnames

IGW_ID=$(aws ec2 create-internet-gateway --tag-specifications "$(tag internet-gateway lab07-igw)" \
  --query InternetGateway.InternetGatewayId --output text)
aws ec2 attach-internet-gateway --vpc-id "${VPC_ID}" --internet-gateway-id "${IGW_ID}"

PUB_SUB=$(aws ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block 10.7.0.0/24 --availability-zone "${AZ_A}" \
  --tag-specifications "$(tag subnet lab07-public-a)" --query Subnet.SubnetId --output text)
PRI_SUB_A=$(aws ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block 10.7.10.0/24 --availability-zone "${AZ_A}" \
  --tag-specifications "$(tag subnet lab07-private-a)" --query Subnet.SubnetId --output text)
PRI_SUB_B=$(aws ec2 create-subnet --vpc-id "${VPC_ID}" --cidr-block 10.7.11.0/24 --availability-zone "${AZ_B}" \
  --tag-specifications "$(tag subnet lab07-private-b)" --query Subnet.SubnetId --output text)

# Chỉ public subnet có route ra IGW; private subnet giữ main route table (chỉ có route local)
PUB_RT=$(aws ec2 create-route-table --vpc-id "${VPC_ID}" --tag-specifications "$(tag route-table lab07-public-rt)" \
  --query RouteTable.RouteTableId --output text)
aws ec2 create-route --route-table-id "${PUB_RT}" --destination-cidr-block 0.0.0.0/0 --gateway-id "${IGW_ID}" >/dev/null
aws ec2 associate-route-table --route-table-id "${PUB_RT}" --subnet-id "${PUB_SUB}" >/dev/null

sg() { aws ec2 create-security-group --vpc-id "${VPC_ID}" --group-name "$1" --description "$1" \
  --tag-specifications "$(tag security-group "$1")" --query GroupId --output text; }
SG_CLIENT=$(sg lab07-client-sg)
SG_DB=$(sg lab07-db-sg)
aws ec2 authorize-security-group-ingress --group-id "${SG_DB}" \
  --protocol tcp --port 5432 --source-group "${SG_CLIENT}" >/dev/null
echo "VPC=${VPC_ID} PUB=${PUB_SUB} PRI=${PRI_SUB_A},${PRI_SUB_B} SG_CLIENT=${SG_CLIENT} SG_DB=${SG_DB}"
```

IAM role cho client: SSM để vào máy, quyền đọc secret sẽ thêm ở Bước 6 khi đã biết ARN.

```bash
cat > trust-ec2.json << 'EOF'
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"ec2.amazonaws.com"},"Action":"sts:AssumeRole"}]}
EOF
aws iam create-role --role-name Lab07ClientRole --assume-role-policy-document file://trust-ec2.json \
  --tags Key=Project,Value=aws-lab-07 >/dev/null
aws iam attach-role-policy --role-name Lab07ClientRole \
  --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam create-instance-profile --instance-profile-name Lab07ClientProfile >/dev/null
aws iam add-role-to-instance-profile --instance-profile-name Lab07ClientProfile --role-name Lab07ClientRole
sleep 15   # IAM eventually consistent

AMI_ID=$(aws ssm get-parameter --name /aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64 \
  --query Parameter.Value --output text)
CLIENT_ID=$(aws ec2 run-instances --image-id "${AMI_ID}" --instance-type t4g.nano \
  --subnet-id "${PUB_SUB}" --security-group-ids "${SG_CLIENT}" --associate-public-ip-address \
  --iam-instance-profile Name=Lab07ClientProfile \
  --metadata-options HttpTokens=required,HttpEndpoint=enabled \
  --tag-specifications "$(tag instance lab07-client)" "$(tag volume lab07-client)" \
  --query 'Instances[0].InstanceId' --output text)
echo "CLIENT_ID=${CLIENT_ID}"
```

*Kỳ vọng:* sau 1–3 phút, `aws ssm describe-instance-information --query "InstanceInformationList[?InstanceId=='${CLIENT_ID}'].PingStatus" --output text` in `Online`.

### Bước 6 — Tạo RDS PostgreSQL private

```bash
aws rds create-db-subnet-group --db-subnet-group-name lab07-db-subnets \
  --db-subnet-group-description "Lab 07 private subnets" \
  --subnet-ids "${PRI_SUB_A}" "${PRI_SUB_B}" --tags Key=Project,Value=aws-lab-07 >/dev/null

# Lấy minor version mới nhất đang hỗ trợ của PostgreSQL 16, không ghi cứng (minor cũ bị retire theo thời gian)
PG_VERSION=$(aws rds describe-db-engine-versions --engine postgres \
  --query "DBEngineVersions[?starts_with(EngineVersion, '16.')].EngineVersion | [-1]" --output text)
echo "PG_VERSION=${PG_VERSION}"

aws rds create-db-instance \
  --db-instance-identifier lab07-pg \
  --engine postgres --engine-version "${PG_VERSION}" \
  --db-instance-class db.t4g.micro \
  --allocated-storage 20 --storage-type gp3 \
  --master-username dbadmin --manage-master-user-password \
  --db-subnet-group-name lab07-db-subnets \
  --vpc-security-group-ids "${SG_DB}" \
  --no-publicly-accessible --no-multi-az \
  --backup-retention-period 1 \
  --storage-encrypted \
  --tags Key=Project,Value=aws-lab-07 >/dev/null

aws rds wait db-instance-available --db-instance-identifier lab07-pg   # 5–15 phút
read -r DB_HOST SECRET_ARN PUBLIC < <(aws rds describe-db-instances --db-instance-identifier lab07-pg \
  --query 'DBInstances[0].[Endpoint.Address, MasterUserSecret.SecretArn, PubliclyAccessible]' --output text)
echo "DB_HOST=${DB_HOST} PUBLIC=${PUBLIC}"
```

*Kỳ vọng:* `PUBLIC=False`. `--manage-master-user-password` khiến RDS tự sinh mật khẩu và lưu vào Secrets Manager; bạn không bao giờ thấy mật khẩu trong lệnh hay lịch sử shell.

Cấp cho client quyền đọc **đúng một** secret này:

```bash
cat > read-secret.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":"secretsmanager:GetSecretValue","Resource":"${SECRET_ARN}"}]}
EOF
aws iam put-role-policy --role-name Lab07ClientRole --policy-name ReadLab07DbSecret \
  --policy-document file://read-secret.json

# Dòng này sẽ dán vào phiên Session Manager ở bước sau
echo "export AWS_REGION=${AWS_REGION} PGHOST=${DB_HOST} SECRET_ARN=${SECRET_ARN}"
```

### Bước 7 — Kết nối từ client, seed dữ liệu, đo query

Từ laptop: `aws ssm start-session --target "${CLIENT_ID}"`. Trong phiên, dán dòng `export` vừa in, rồi:

```bash
sudo dnf install -y -q postgresql16 jq
export PGUSER=dbadmin PGDATABASE=postgres PGSSLMODE=require
export PGPASSWORD=$(aws secretsmanager get-secret-value --secret-id "$SECRET_ARN" \
  --query SecretString --output text | jq -r .password)
psql -c "select version();"
```

*Kỳ vọng:* in `PostgreSQL 16.x on aarch64-unknown-linux-gnu...`. Nếu treo khoảng 30 giây rồi `timeout`, đó là lỗi mạng/SG, không phải mật khẩu (xem Troubleshooting).

Seed 200.000 đơn hàng giả và đo query theo khách hàng:

```bash
psql << 'SQL'
CREATE TABLE orders (
  id          bigserial PRIMARY KEY,
  customer_id int       NOT NULL,
  amount      numeric(12,0) NOT NULL,
  created_at  timestamptz NOT NULL DEFAULT now()
);
INSERT INTO orders (customer_id, amount, created_at)
SELECT (random()*5000)::int, (random()*2000000)::int, now() - random()*interval '365 days'
FROM generate_series(1, 200000);
ANALYZE orders;
\timing on
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*), sum(amount) FROM orders WHERE customer_id = 42;
CREATE INDEX orders_customer_idx ON orders (customer_id);
EXPLAIN (ANALYZE, BUFFERS) SELECT count(*), sum(amount) FROM orders WHERE customer_id = 42;
SELECT count(*) AS cust42_rows FROM orders WHERE customer_id = 42;
SQL
```

*Kỳ vọng (minh họa):* lần đầu plan là `Seq Scan on orders` (hoặc `Parallel Seq Scan`), `Execution Time` cỡ 15–40 ms; sau khi tạo index là `Bitmap Index Scan on orders_customer_idx`, cỡ 0,1–0,5 ms. Ghi lại `cust42_rows` (khoảng 40) để so sánh ở Bước 9. Đây là cùng bài học "Query vs Scan" của DynamoDB, nhưng ở thế giới SQL.

### Bước 8 — Snapshot, rồi "xóa nhầm"

Từ laptop:

```bash
aws rds create-db-snapshot --db-instance-identifier lab07-pg \
  --db-snapshot-identifier lab07-pg-before-change --tags Key=Project,Value=aws-lab-07 >/dev/null
aws rds wait db-snapshot-available --db-snapshot-identifier lab07-pg-before-change
aws rds describe-db-snapshots --db-snapshot-identifier lab07-pg-before-change \
  --query 'DBSnapshots[0].[Status,SnapshotCreateTime]' --output text
```

Sau khi snapshot `available`, trong phiên Session Manager mô phỏng một script bảo trì chạy sai:

```bash
psql -c "DELETE FROM orders WHERE customer_id = 42;" -c "SELECT count(*) FROM orders WHERE customer_id = 42;"
```

*Kỳ vọng:* `DELETE 4x` rồi `count = 0`. Dữ liệu khách 42 đã mất trên instance gốc.

### Bước 9 — Restore sang instance mới và chứng minh dữ liệu về

```bash
aws rds restore-db-instance-from-db-snapshot \
  --db-instance-identifier lab07-pg-restored \
  --db-snapshot-identifier lab07-pg-before-change \
  --db-instance-class db.t4g.micro \
  --db-subnet-group-name lab07-db-subnets \
  --vpc-security-group-ids "${SG_DB}" \
  --no-publicly-accessible --no-multi-az \
  --tags Key=Project,Value=aws-lab-07 >/dev/null
START=$(date +%s)
aws rds wait db-instance-available --db-instance-identifier lab07-pg-restored
echo "Restore mất $(( ($(date +%s) - START) / 60 )) phút"
RESTORED_HOST=$(aws rds describe-db-instances --db-instance-identifier lab07-pg-restored \
  --query 'DBInstances[0].Endpoint.Address' --output text)
echo "export RESTORED_HOST=${RESTORED_HOST}"
```

Trong phiên Session Manager (dán dòng `export`):

```bash
psql -h "$RESTORED_HOST" -c "SELECT count(*) AS restored FROM orders WHERE customer_id = 42;"
psql                     -c "SELECT count(*) AS original FROM orders WHERE customer_id = 42;"
```

*Kỳ vọng:* `restored` bằng `cust42_rows` ở Bước 7, `original = 0`. Ghi lại số phút restore: đó là **RTO đo được** cho DB cỡ này, chưa tính thời gian đổi endpoint của ứng dụng.

- Bản restore là instance **mới, endpoint mới**. Muốn ứng dụng dùng nó, phải đổi cấu hình kết nối (hoặc chép lại dữ liệu cần cứu sang instance gốc).
- Bản restore dùng cùng mật khẩu master như lúc chụp snapshot (mật khẩu nằm trong dữ liệu), nên lab dùng lại secret của instance gốc. Production nên bật lại managed password cho instance restore.
- Restore từ snapshot quay về **thời điểm chụp**. PITR (`restore-db-instance-to-point-in-time`) cho phép chọn giây cụ thể, sát trước lệnh `DELETE`, nhưng cũng tạo instance mới.

---

## Phần C (tùy chọn) — Cache-aside với ElastiCache

Bỏ qua nếu không đủ thời gian; mục tiêu chính của lab đã hoàn thành ở Phần A và B.

### Bước 10 — Tạo cache và thử hit/miss, stale data, fallback

Từ laptop:

```bash
SG_CACHE=$(sg lab07-cache-sg)
aws ec2 authorize-security-group-ingress --group-id "${SG_CACHE}" \
  --protocol tcp --port 6379 --source-group "${SG_CLIENT}" >/dev/null
aws elasticache create-cache-subnet-group --cache-subnet-group-name lab07-cache-subnets \
  --cache-subnet-group-description "Lab 07" --subnet-ids "${PRI_SUB_A}" "${PRI_SUB_B}" >/dev/null
aws elasticache create-replication-group --replication-group-id lab07-cache \
  --replication-group-description "Lab 07 cache-aside" \
  --engine valkey --cache-node-type cache.t4g.micro --num-cache-clusters 1 \
  --cache-subnet-group-name lab07-cache-subnets --security-group-ids "${SG_CACHE}" \
  --tags Key=Project,Value=aws-lab-07 >/dev/null
aws elasticache wait replication-group-available --replication-group-id lab07-cache   # 5–10 phút
CACHE_HOST=$(aws elasticache describe-replication-groups --replication-group-id lab07-cache \
  --query 'ReplicationGroups[0].NodeGroups[0].PrimaryEndpoint.Address' --output text)
echo "export CACHE_HOST=${CACHE_HOST}"
```

Trong phiên Session Manager:

```bash
sudo dnf install -y -q valkey || sudo dnf install -y -q redis6
CLI=$(command -v valkey-cli || command -v redis6-cli)

total() {  # cache-aside: đọc cache, miss thì đọc DB rồi ghi cache với TTL 60s
  local key="customer:$1:total" v
  v=$(timeout 1 "$CLI" -h "$CACHE_HOST" GET "$key" 2>/dev/null)
  if [ -n "$v" ]; then echo "HIT  $v"; return; fi
  v=$(psql -tAc "SELECT coalesce(sum(amount),0) FROM orders WHERE customer_id = $1")
  timeout 1 "$CLI" -h "$CACHE_HOST" SET "$key" "$v" EX 60 >/dev/null 2>&1 || echo "(cache lỗi, bỏ qua)"
  echo "MISS $v"
}
time total 7
time total 7
psql -c "UPDATE orders SET amount = amount + 1000000 WHERE customer_id = 7;"
total 7        # vẫn số cũ: stale tới khi TTL hết
```

*Kỳ vọng (minh họa):* lần 1 `MISS`, lần 2 `HIT` cùng giá trị và nhanh hơn; sau `UPDATE` vẫn `HIT` với **giá trị cũ**. Đó là stale data: cache-aside chỉ đúng "sau tối đa TTL". Muốn đúng ngay, sau khi ghi DB phải `DEL` key.

Giả lập cache lỗi bằng cách gỡ rule SG, **từ laptop**:

```bash
aws ec2 revoke-security-group-ingress --group-id "${SG_CACHE}" \
  --protocol tcp --port 6379 --source-group "${SG_CLIENT}" >/dev/null
```

Trong phiên: `time total 7` → in `(cache lỗi, bỏ qua)` rồi `MISS <giá trị mới>`, mất khoảng 1–2 giây do `timeout 1` hai lần. Ứng dụng chậm đi nhưng **không lỗi**, vì cache không phải nguồn dữ liệu chuẩn. Không có `timeout`, mỗi request sẽ treo tới timeout TCP mặc định của client.

---

## Bước 11 — Dọn dẹp theo dependency

```bash
# 1. Phần C (nếu đã làm)
aws elasticache delete-replication-group --replication-group-id lab07-cache 2>/dev/null &&
  aws elasticache wait replication-group-deleted --replication-group-id lab07-cache
aws elasticache delete-cache-subnet-group --cache-subnet-group-name lab07-cache-subnets 2>/dev/null

# 2. Hai RDS instance (secret do RDS quản lý bị xóa theo instance gốc)
for DB in lab07-pg-restored lab07-pg; do
  aws rds delete-db-instance --db-instance-identifier "${DB}" \
    --skip-final-snapshot --delete-automated-backups >/dev/null 2>&1
done
aws rds wait db-instance-deleted --db-instance-identifier lab07-pg-restored
aws rds wait db-instance-deleted --db-instance-identifier lab07-pg

# 3. Manual snapshot: KHÔNG tự xóa theo instance
aws rds delete-db-snapshot --db-snapshot-identifier lab07-pg-before-change >/dev/null
aws rds delete-db-subnet-group --db-subnet-group-name lab07-db-subnets

# 4. Client EC2
aws ec2 terminate-instances --instance-ids "${CLIENT_ID}" >/dev/null
aws ec2 wait instance-terminated --instance-ids "${CLIENT_ID}"

# 5. Security groups (xóa SG đang được tham chiếu trước), rồi mạng
for SG in "${SG_CACHE}" "${SG_DB}" "${SG_CLIENT}"; do
  [ -n "${SG}" ] && aws ec2 delete-security-group --group-id "${SG}"
done
aws ec2 delete-subnet --subnet-id "${PUB_SUB}"
aws ec2 delete-subnet --subnet-id "${PRI_SUB_A}"
aws ec2 delete-subnet --subnet-id "${PRI_SUB_B}"
aws ec2 delete-route-table --route-table-id "${PUB_RT}"
aws ec2 detach-internet-gateway --internet-gateway-id "${IGW_ID}" --vpc-id "${VPC_ID}"
aws ec2 delete-internet-gateway --internet-gateway-id "${IGW_ID}"
aws ec2 delete-vpc --vpc-id "${VPC_ID}"

# 6. IAM
aws iam remove-role-from-instance-profile --instance-profile-name Lab07ClientProfile --role-name Lab07ClientRole
aws iam delete-instance-profile --instance-profile-name Lab07ClientProfile
aws iam delete-role-policy --role-name Lab07ClientRole --policy-name ReadLab07DbSecret
aws iam detach-role-policy --role-name Lab07ClientRole --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore
aws iam delete-role --role-name Lab07ClientRole

# 7. DynamoDB
aws dynamodb delete-table --table-name OrdersLab07 >/dev/null
aws dynamodb wait table-not-exists --table-name OrdersLab07

# 8. File tạm
cd ~ && rm -rf "${LAB_DIR}"
```

Nếu xóa SG báo `DependencyViolation`, ENI của RDS/ElastiCache chưa được giải phóng: chờ 1–2 phút rồi chạy lại.

### Kiểm tra inventory sau cleanup

```bash
echo "== RDS";        aws rds describe-db-instances --query "DBInstances[?starts_with(DBInstanceIdentifier,'lab07')].DBInstanceIdentifier" --output text
echo "== Snapshot";   aws rds describe-db-snapshots --snapshot-type manual --query "DBSnapshots[?starts_with(DBSnapshotIdentifier,'lab07')].DBSnapshotIdentifier" --output text
echo "== Auto backup";aws rds describe-db-instance-automated-backups --query "DBInstanceAutomatedBackups[?starts_with(DBInstanceIdentifier,'lab07')].DBInstanceIdentifier" --output text
echo "== Secret";     aws secretsmanager list-secrets --filters Key=name,Values=rds! --query "SecretList[?Tags[?Key=='aws:rds:primaryDBInstanceArn' && contains(Value,'lab07')]].Name" --output text
echo "== Cache";      aws elasticache describe-replication-groups --query "ReplicationGroups[?ReplicationGroupId=='lab07-cache'].Status" --output text
echo "== EC2";        aws ec2 describe-instances --filters Name=tag:Project,Values=aws-lab-07 Name=instance-state-name,Values=pending,running,stopping,stopped --query 'Reservations[].Instances[].InstanceId' --output text
echo "== VPC";        aws ec2 describe-vpcs --filters Name=tag:Project,Values=aws-lab-07 --query 'Vpcs[].VpcId' --output text
echo "== DynamoDB";   aws dynamodb list-tables --query "TableNames[?starts_with(@,'OrdersLab07')]" --output text
```

*Kỳ vọng:* mọi mục rỗng. Secret do RDS quản lý bị xóa ngay khi xóa instance; nếu vẫn thấy, nó đang ở trạng thái chờ xóa và không còn dùng được. Billing có độ trễ, nên inventory qua API mới là bằng chứng cleanup.

**Retained resources:** không có. Nếu bạn muốn giữ snapshot để học tiếp, ghi lại tên và chi phí (~$0.095/GB-tháng × 20 GB ≈ $2/tháng).

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
q() { aws ec2 "$@" --output text; }
VPC_ID=$(q describe-vpcs --filters Name=tag:Project,Values=aws-lab-07 --query 'Vpcs[0].VpcId')
IGW_ID=$(q describe-internet-gateways --filters Name=attachment.vpc-id,Values="${VPC_ID}" --query 'InternetGateways[0].InternetGatewayId')
sub() { q describe-subnets --filters Name=vpc-id,Values="${VPC_ID}" Name=tag:Name,Values="$1" --query 'Subnets[0].SubnetId'; }
sgid() { q describe-security-groups --filters Name=vpc-id,Values="${VPC_ID}" Name=group-name,Values="$1" --query 'SecurityGroups[0].GroupId'; }
PUB_SUB=$(sub lab07-public-a); PRI_SUB_A=$(sub lab07-private-a); PRI_SUB_B=$(sub lab07-private-b)
PUB_RT=$(q describe-route-tables --filters Name=tag:Name,Values=lab07-public-rt --query 'RouteTables[0].RouteTableId')
SG_CLIENT=$(sgid lab07-client-sg); SG_DB=$(sgid lab07-db-sg); SG_CACHE=$(sgid lab07-cache-sg)
[ "${SG_CACHE}" = "None" ] && SG_CACHE=""
CLIENT_ID=$(q describe-instances --filters Name=tag:Project,Values=aws-lab-07 Name=instance-state-name,Values=pending,running,stopped --query 'Reservations[0].Instances[0].InstanceId')
echo "VPC=${VPC_ID} CLIENT=${CLIENT_ID} SG=${SG_CLIENT},${SG_DB},${SG_CACHE}"
```

Sau đó chạy lại Bước 11. Lệnh nào báo `NotFound`, `DBInstanceNotFound`, `NoSuchEntity` nghĩa là tài nguyên đó chưa tạo hoặc đã xóa: bỏ qua và chạy tiếp. Ưu tiên xóa **hai RDS instance và snapshot** trước nếu không đủ thời gian.

---

## Checklist hoàn thành

- [ ] Có bảng access pattern trước khi tạo bảng DynamoDB; mọi truy vấn dùng `Query`, không `Scan`.
- [ ] Thấy `ValidationException` khi đọc strongly consistent trên GSI.
- [ ] Thấy `ConditionalCheckFailedException` cho cả điều kiện nghiệp vụ và optimistic locking; giải thích được worker B phải làm gì.
- [ ] RDS `PubliclyAccessible=False`, nằm trong 2 private subnet; SG DB chỉ nhận 5432 từ `lab07-client-sg`.
- [ ] Kết nối từ client trong VPC bằng mật khẩu lấy từ Secrets Manager; không mật khẩu nào xuất hiện trong lệnh.
- [ ] Có số đo `EXPLAIN ANALYZE` trước và sau index.
- [ ] Restore snapshot sang instance mới, chứng minh dữ liệu đã xóa nằm trong bản restore; ghi lại thời gian restore.
- [ ] (Tùy chọn) Quan sát hit/miss, stale data và fallback khi cache lỗi.
- [ ] Cleanup xong; inventory rỗng (RDS, snapshot, automated backup, secret, cache, EC2, VPC, DynamoDB).

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| `psql` treo rồi `Connection timed out` | SG DB không cho 5432 từ SG client, hoặc client không ở cùng VPC | `aws ec2 describe-security-groups --group-ids ${SG_DB} --query 'SecurityGroups[0].IpPermissions'`; không sửa bằng cách mở `0.0.0.0/0` |
| `psql` báo `password authentication failed` | Mạng đã thông; sai user/mật khẩu hoặc `PGPASSWORD` rỗng | `echo ${#PGPASSWORD}` phải > 0; kiểm tra quyền `GetSecretValue` của role |
| `AccessDeniedException` khi đọc secret | Inline policy chưa gắn hoặc sai ARN | `aws iam get-role-policy --role-name Lab07ClientRole --policy-name ReadLab07DbSecret` |
| `create-db-instance` báo `InvalidParameterCombination` về version | Minor version không hỗ trợ instance class/Region | Chạy lại lệnh lấy `PG_VERSION`; thử major 17 |
| `DBSubnetGroupDoesNotCoverEnoughAZs` | Hai subnet cùng AZ | `describe-subnets --query 'Subnets[].AvailabilityZone'` |
| SSM `describe-instance-information` rỗng | Client không có public IP, route IGW, hoặc instance profile | Kiểm tra `PublicIpAddress` của client và route `0.0.0.0/0 → igw` của public RT |
| `dnf install postgresql16` không tìm thấy package | Tên package đổi theo phiên bản AL2023 | `dnf search postgresql` và cài bản client tương ứng |
| GSI query trả rỗng | Item thiếu `customer_id` hoặc `created_at` (index thưa), hoặc index chưa `ACTIVE` | `describe-table --query 'Table.GlobalSecondaryIndexes[].IndexStatus'`; kiểm tra item |
| Xóa SG báo `DependencyViolation` | ENI của RDS/ElastiCache chưa giải phóng | Chờ 1–2 phút; `describe-network-interfaces --filters Name=group-id,Values=<sg>` |
| Xóa VPC báo `DependencyViolation` | Còn subnet, ENI, SG hoặc IGW | `describe-network-interfaces --filters Name=vpc-id,Values=${VPC_ID}` |

---

## Tiếp theo

Bạn đã chọn kho dữ liệu theo access pattern, giữ database private và chứng minh backup bằng một lần restore thật. Bài tiếp theo: **[Bài 08 — Container: ECR, ECS, Fargate](../../notes/08-containers.md)**.

---

## Nhánh Laravel theo lộ trình gốc

Lab dịch vụ ở trên được nối với [dự án Laravel + Vue](../15-capstone-laravel/README.md). Đọc phần bổ sung trong bài lý thuyết 07 và bảng mốc của dự án để chọn đúng prerequisite. Đầu ra bắt buộc gồm code/fixture, happy path, negative case và inventory sau cleanup; không dùng output minh họa làm bằng chứng đã chạy.
