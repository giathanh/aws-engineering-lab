# Lab 02 — Danh tính và quyền truy cập: STS AssumeRole và Least Privilege với S3

> **Lý thuyết:** [notes/02-identity-and-access.md](../../notes/02-identity-and-access.md). Đọc phần 1–5 trước khi bắt đầu.
> **Thời lượng:** khoảng 60–75 phút (cộng 15 phút nếu chờ session hết hạn ở bước tùy chọn).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Tạo IAM Role với Trust Policy cho phép caller assume | Tạo IAM User với long-lived Access Key |
| Tạo IAM Policy cấp quyền chỉ đọc một prefix S3 xác định | Dùng `AdministratorAccess` hoặc policy wildcard `*` trên `*` |
| Sử dụng `aws sts assume-role` để lấy temporary credentials | Sử dụng root user |
| Kiểm chứng happy path (đọc thành công prefix hợp lệ) | Đặt dữ liệu nhạy cảm hoặc bí mật thật lên S3 |
| Negative test có kiểm soát: đọc sai prefix, ghi file, token sai | Để lại tài nguyên rác sau khi kết thúc lab |
| Dọn dẹp sạch toàn bộ role, policy và S3 fixture | Thay đổi tài nguyên production hoặc tài nguyên chung của tổ chức |

---

## Quy ước placeholder

| Placeholder / biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Tên named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `REGION` | Region bạn đã chọn học tập | `ap-southeast-1` |
| `ACCOUNT_ID` | 12 số account ID (lấy tự động ở Bước 1) | `111122223333` |
| `CALLER_ARN` | ARN của identity đang đăng nhập (lấy tự động ở Bước 1) | `arn:aws:sts::111122223333:assumed-role/AWSReservedSSO_LabAdmin_abc123/dev` |
| `BUCKET_NAME` | Tên bucket fixture, duy nhất toàn cầu (tạo ở Bước 2) | `lab02-orders-111122223333-1761900000` |
| `OrderReaderRole` | Tên IAM Role được tạo trong lab | `OrderReaderRole` |
| `OrderReadOnlyPolicy` | Tên inline policy được tạo trong lab | `OrderReadOnlyPolicy` |

Mọi tài nguyên đều gắn tag `Project=aws-lab-02` để tìm lại và dọn dẹp.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 01](../01-foundations/README.md), AWS CLI v2 hoạt động ổn định.
- [ ] Profile `aws-lab` gọi thành công `aws sts get-caller-identity`, và ARN **không** phải root (`arn:aws:iam::<ACCOUNT_ID>:root`).
- [ ] Identity hiện tại có quyền:
  - IAM trên role của lab: `iam:CreateRole`, `iam:TagRole`, `iam:GetRole`, `iam:PutRolePolicy`, `iam:DeleteRolePolicy`, `iam:DeleteRole`.
  - S3 trên bucket của lab: `s3:CreateBucket`, `s3:PutBucketTagging`, `s3:ListBucket`, `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:DeleteBucket`.
- [ ] Không sử dụng production account.

### Chi phí ước tính

IAM và STS không tính phí. S3 fixture chỉ có 2 object vài chục byte và vài chục request trong lab, chi phí gần như bằng 0. Nếu quên cleanup, bucket vẫn tốn không đáng kể, nhưng vẫn phải xóa vì đây là tài nguyên mồ côi.

---

## Sơ đồ kiến trúc thực hành

```text
1. Identity hiện tại (Caller)
   │
   ├─► Tạo S3 Bucket: <BUCKET_NAME>
   │   ├── orders/read-only/order-101.json  (Dữ liệu đọc được)
   │   └── orders/confidential/secret.json  (Dữ liệu giả, cấm đọc)
   │
   ├─► Tạo IAM Role: OrderReaderRole
   │   ├── Trust Policy: Cho phép <CALLER_ARN> gọi sts:AssumeRole
   │   └── Permission Policy: s3:ListBucket trên bucket + s3:GetObject trên orders/read-only/*
   │
   └─► Chạy `aws sts assume-role`
         │
         ▼
2. Nhận Temporary Credentials (ASIA...)
   │
   ├── [Happy Path] Đọc orders/read-only/order-101.json ──► thành công
   ├── [Negative 1] Đọc orders/confidential/secret.json  ──► 403 Forbidden / AccessDenied
   ├── [Negative 2] Ghi file mới (s3:PutObject)          ──► AccessDenied
   └── [Negative 3] Gửi request với session token bị sửa ──► InvalidClientTokenId
```

---

## Bước 1 — Lấy thông tin Principal hiện tại

Trước khi tạo Trust Policy, cần biết chính xác danh tính của mình. Lưu vào biến để các bước sau tự dùng:

```bash
export REGION="ap-southeast-1"   # đổi thành Region của bạn
export ACCOUNT_ID=$(aws sts get-caller-identity --profile aws-lab --query Account --output text)
export CALLER_ARN=$(aws sts get-caller-identity --profile aws-lab --query Arn --output text)
echo "ACCOUNT_ID=${ACCOUNT_ID}"
echo "CALLER_ARN=${CALLER_ARN}"
```

Output minh họa:

```text
ACCOUNT_ID=111122223333
CALLER_ARN=arn:aws:sts::111122223333:assumed-role/AWSReservedSSO_LabAdmin_abc123/dev
```

> [!NOTE]
> Nếu đăng nhập bằng IAM Identity Center, `CALLER_ARN` là một **role session ARN** (`arn:aws:sts::...:assumed-role/<role>/<session>`). Trust policy chấp nhận loại principal này. Session name của Identity Center là username của bạn nên vẫn khớp sau khi `aws sso login` lại.
>
> Nếu ARN kết thúc bằng `:root`, **dừng lại**: lab không dùng root user. Quay lại Lab 01 để đăng nhập bằng identity khác.

---

## Bước 2 — Chuẩn bị S3 Fixture

Tạo một bucket riêng để thử nghiệm. Tên bucket S3 là duy nhất toàn cầu, nên ghép account ID và timestamp:

```bash
export BUCKET_NAME="lab02-orders-${ACCOUNT_ID}-$(date +%s)"
echo "BUCKET_NAME=${BUCKET_NAME}"   # ghi lại giá trị này, cần cho cleanup nếu mất shell

aws s3 mb "s3://${BUCKET_NAME}" --region "${REGION}" --profile aws-lab
aws s3api put-bucket-tagging --bucket "${BUCKET_NAME}" \
  --tagging 'TagSet=[{Key=Project,Value=aws-lab-02}]' --profile aws-lab
```

Tạo thư mục làm việc riêng và hai file dữ liệu mẫu (dữ liệu giả, không phải bí mật thật):

```bash
export LAB_DIR=$(mktemp -d)
echo '{"order_id": 101, "item": "Keyboard", "status": "shipped"}' > "${LAB_DIR}/order-101.json"
echo '{"order_id": 999, "note": "FAKE confidential fixture"}' > "${LAB_DIR}/secret.json"

aws s3 cp "${LAB_DIR}/order-101.json" "s3://${BUCKET_NAME}/orders/read-only/order-101.json" --profile aws-lab
aws s3 cp "${LAB_DIR}/secret.json" "s3://${BUCKET_NAME}/orders/confidential/secret.json" --profile aws-lab
```

Kiểm tra danh sách objects:

```bash
aws s3 ls "s3://${BUCKET_NAME}/orders/" --recursive --profile aws-lab
```

Kết quả mong đợi: hai dòng `orders/confidential/secret.json` và `orders/read-only/order-101.json`.

---

## Bước 3 — Tạo IAM Role và Trust Policy

Viết Trust Policy cho phép `CALLER_ARN` assume role này. Heredoc không đặt trong nháy nên shell tự thay `${CALLER_ARN}`:

```bash
cat <<EOF > "${LAB_DIR}/trust-policy.json"
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowLabCallerToAssume",
      "Effect": "Allow",
      "Principal": {
        "AWS": "${CALLER_ARN}"
      },
      "Action": "sts:AssumeRole"
    }
  ]
}
EOF
cat "${LAB_DIR}/trust-policy.json"   # xác nhận ARN đã được điền đúng
```

Tạo IAM Role:

```bash
aws iam create-role \
  --role-name OrderReaderRole \
  --assume-role-policy-document "file://${LAB_DIR}/trust-policy.json" \
  --description "Role thuc hanh Least Privilege cho Order Service" \
  --tags Key=Project,Value=aws-lab-02 \
  --profile aws-lab
```

> [!NOTE]
> Vì trust policy nêu **đích danh** principal trong cùng account, caller không cần thêm quyền `sts:AssumeRole` trong identity policy của mình. Nếu trust policy chỉ ghi `arn:aws:iam::<ACCOUNT_ID>:root` (tin tưởng cả account), caller **bắt buộc** phải có thêm `sts:AssumeRole` trong identity policy.

---

## Bước 4 — Đính kèm Permission Policy giới hạn Least Privilege

Tạo chính sách chỉ cho phép list bucket và đọc object trong prefix `orders/read-only/`:

```bash
cat <<EOF > "${LAB_DIR}/order-read-policy.json"
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowListSpecificBucket",
      "Effect": "Allow",
      "Action": "s3:ListBucket",
      "Resource": "arn:aws:s3:::${BUCKET_NAME}"
    },
    {
      "Sid": "AllowReadReadOnlyPrefixOnly",
      "Effect": "Allow",
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::${BUCKET_NAME}/orders/read-only/*"
    }
  ]
}
EOF
```

Lưu ý `s3:ListBucket` dùng **bucket ARN**, còn `s3:GetObject` dùng **object ARN** (xem lý thuyết Phần 2).

Đính kèm policy dưới dạng inline policy vào role:

```bash
aws iam put-role-policy \
  --role-name OrderReaderRole \
  --policy-name OrderReadOnlyPolicy \
  --policy-document "file://${LAB_DIR}/order-read-policy.json" \
  --profile aws-lab
```

---

## Bước 5 — Assume Role và nạp Temporary Credentials

IAM là eventually consistent: role vừa tạo có thể cần vài giây mới assume được. Chờ một chút rồi gọi `sts:AssumeRole` với thời lượng phiên 900 giây (15 phút, mức tối thiểu):

```bash
sleep 15

read -r AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN < <(
  aws sts assume-role \
    --role-arn "arn:aws:iam::${ACCOUNT_ID}:role/OrderReaderRole" \
    --role-session-name "LabTestSession" \
    --duration-seconds 900 \
    --profile aws-lab \
    --query 'Credentials.[AccessKeyId,SecretAccessKey,SessionToken]' \
    --output text
)
export AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN
export AWS_REGION="${REGION}"
echo "${AWS_ACCESS_KEY_ID}" | cut -c1-4   # mong đợi: ASIA
```

Nếu lệnh báo `AccessDenied` ngay sau khi tạo role, chờ thêm 15–30 giây rồi chạy lại.

Xác minh identity của phiên hiện tại (không dùng `--profile` để biến môi trường có hiệu lực):

```bash
aws sts get-caller-identity
```

Kết quả mong đợi:

```json
{
    "UserId": "AROA...:LabTestSession",
    "Account": "111122223333",
    "Arn": "arn:aws:sts::111122223333:assumed-role/OrderReaderRole/LabTestSession"
}
```

> **Phân tích:** ARN đã chuyển sang `assumed-role/OrderReaderRole/LabTestSession`. Mọi lệnh **không** có `--profile` trong shell này giờ chạy với quyền của role mới. Lệnh có `--profile aws-lab` vẫn dùng identity gốc, vì cờ `--profile` được ưu tiên hơn biến môi trường.

---

## Bước 6 — Kiểm chứng Happy Path

Tải file `order-101.json` trong prefix `orders/read-only/`:

```bash
aws s3 cp "s3://${BUCKET_NAME}/orders/read-only/order-101.json" "${LAB_DIR}/downloaded-order.json"
cat "${LAB_DIR}/downloaded-order.json"
```

Kết quả mong đợi:
```text
download: s3://lab02-orders-.../orders/read-only/order-101.json to .../downloaded-order.json
{"order_id": 101, "item": "Keyboard", "status": "shipped"}
```
✅ **Thành công:** `s3:GetObject` trên prefix hợp lệ hoạt động.

---

## Bước 7 — Kiểm chứng Negative Tests có kiểm soát

### Test 1: Đọc object ở prefix bí mật (`orders/confidential/`)

```bash
aws s3 cp "s3://${BUCKET_NAME}/orders/confidential/secret.json" "${LAB_DIR}/downloaded-secret.json"
```

Kết quả mong đợi:
```text
fatal error: An error occurred (403) when calling the HeadObject operation: Forbidden
```
`aws s3 cp` gọi `HeadObject` trước khi tải, nên lỗi thường hiện ở bước này. Vì role có `s3:ListBucket`, S3 trả về 403 (thay vì 404) khi bị từ chối.

✅ **Xác nhận:** Default Deny chặn hành vi đọc ngoài phạm vi cấp phép.

### Test 2: Thử ghi dữ liệu mới (`s3:PutObject`)

```bash
echo "unauthorized write" > "${LAB_DIR}/hack.txt"
aws s3 cp "${LAB_DIR}/hack.txt" "s3://${BUCKET_NAME}/orders/read-only/hack.txt"
```

Kết quả mong đợi:
```text
upload failed: .../hack.txt to s3://.../orders/read-only/hack.txt An error occurred (AccessDenied) when calling the PutObject operation: ...
```
✅ **Xác nhận:** Dù đúng prefix `orders/read-only/`, role không có `s3:PutObject` nên bị từ chối.

### Test 3: Session token bị sửa

Chạy trong subshell để không làm hỏng credentials đang dùng:

```bash
( export AWS_SESSION_TOKEN="${AWS_SESSION_TOKEN}tampered"; aws sts get-caller-identity )
```

Kết quả mong đợi:
```text
An error occurred (InvalidClientTokenId) when calling the GetCallerIdentity operation: The security token included in the request is invalid.
```
✅ **Xác nhận:** Thiếu hoặc sai `SessionToken` thì AWS không xác thực được request (lỗi AuthN, khác với `AccessDenied` là lỗi AuthZ).

### Test 4 (tùy chọn): Session hết hạn

Nếu có thời gian, đợi hơn 15 phút kể từ Bước 5 rồi chạy:

```bash
aws sts get-caller-identity
```

Kết quả mong đợi: `An error occurred (ExpiredToken) ... The security token included in the request is expired`. Cách khắc phục là chạy lại Bước 5 để lấy credentials mới.

---

## Bước 8 — Hủy phiên và quay về identity gốc

Xóa các biến môi trường của role:

```bash
unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_REGION
aws sts get-caller-identity --profile aws-lab --query Arn --output text
```

Kết quả mong đợi: ARN trùng với `CALLER_ARN` ở Bước 1.

---

## Bước 9 — Dọn dẹp tài nguyên (Cleanup)

Đảm bảo đã chạy Bước 8 (không còn credentials của `OrderReaderRole`, vì role đó không có quyền xóa). Dọn theo thứ tự phụ thuộc:

```bash
# 1. Xóa toàn bộ object và bucket S3 fixture
aws s3 rm "s3://${BUCKET_NAME}" --recursive --profile aws-lab
aws s3 rb "s3://${BUCKET_NAME}" --profile aws-lab

# 2. Xóa inline policy của role (phải xóa trước khi xóa role)
aws iam delete-role-policy \
  --role-name OrderReaderRole \
  --policy-name OrderReadOnlyPolicy \
  --profile aws-lab

# 3. Xóa IAM role
aws iam delete-role --role-name OrderReaderRole --profile aws-lab

# 4. Xóa thư mục tạm cục bộ
rm -rf "${LAB_DIR}"
```

Xác minh inventory đã sạch:

```bash
aws iam get-role --role-name OrderReaderRole --profile aws-lab
# Mong đợi: An error occurred (NoSuchEntity) ...

aws s3api head-bucket --bucket "${BUCKET_NAME}" --profile aws-lab
# Mong đợi: An error occurred (404) ... Not Found
```

### Nếu lab dừng giữa chừng hoặc mất shell

Biến môi trường sẽ mất khi đóng terminal. Tìm lại tài nguyên của lab:

```bash
# Tìm bucket fixture theo tiền tố tên
aws s3 ls --profile aws-lab | grep lab02-orders-
export BUCKET_NAME=<tên bucket tìm được>

# Kiểm tra role còn không
aws iam get-role --role-name OrderReaderRole --profile aws-lab --query 'Role.Tags'
```

Chỉ xóa bucket có tiền tố `lab02-orders-` và tag `Project=aws-lab-02` (`aws s3api get-bucket-tagging --bucket ...`). Sau đó chạy các lệnh cleanup ở trên; lệnh nào báo `NoSuchEntity`/`NoSuchBucket` nghĩa là tài nguyên đó chưa từng được tạo hoặc đã xóa, bỏ qua và chạy tiếp.

---

## Checklist hoàn thành

- [ ] Lấy được Caller ARN bằng `sts:GetCallerIdentity`.
- [ ] Phân biệt được Trust Policy (`AssumeRolePolicyDocument`) và Permission Policy (`OrderReadOnlyPolicy`).
- [ ] Assume role thành công và có temporary credentials bắt đầu bằng `ASIA...`.
- [ ] Đọc thành công file trong prefix `orders/read-only/`.
- [ ] Ghi nhận `403`/`AccessDenied` khi đọc ngoài prefix hoặc ghi file mới.
- [ ] Ghi nhận `InvalidClientTokenId` khi token bị sửa, và phân biệt được lỗi AuthN với lỗi AuthZ.
- [ ] Hiểu cơ chế `unset` biến môi trường để quay về caller ban đầu.
- [ ] Xóa sạch role, policy và S3 bucket fixture, xác minh bằng `get-role` và `head-bucket`.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Khắc phục |
|---|---|---|
| `AccessDenied` khi chạy `assume-role` ngay sau khi tạo role | IAM chưa propagate | Chờ 15–30 giây rồi chạy lại |
| `AccessDenied` khi chạy `assume-role` sau nhiều lần thử | ARN trong trust policy không khớp caller | `cat "${LAB_DIR}/trust-policy.json"` và so với `aws sts get-caller-identity --profile aws-lab`; cập nhật bằng `aws iam update-assume-role-policy` |
| `MalformedPolicyDocument` khi tạo role | `CALLER_ARN` rỗng (biến chưa set) | `echo "${CALLER_ARN}"`, chạy lại Bước 1 |
| Vẫn đọc được file bí mật ở Bước 7 | Resource bị viết rộng thành `arn:aws:s3:::${BUCKET_NAME}/*` | Sửa resource về `.../orders/read-only/*` và chạy lại `put-role-policy` |
| `BucketAlreadyExists` | Tên bucket trùng toàn cầu | Tạo lại `BUCKET_NAME` (timestamp mới) |
| Lệnh cleanup báo `AccessDenied` | Shell vẫn đang dùng credentials của `OrderReaderRole` | Chạy `env \| grep AWS_`, rồi `unset` như Bước 8 |

## Tiếp theo

Bạn đã nắm cơ chế cấp quyền qua IAM role và nguyên tắc Least Privilege. Bài tiếp theo học cách **kiểm soát và dự báo chi phí (AWS Budgets, Cost Explorer)** để không bị bất ngờ bởi hóa đơn cloud.
