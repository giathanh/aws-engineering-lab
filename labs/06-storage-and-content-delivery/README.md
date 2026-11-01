# Lab 06 — Lưu trữ và phân phối nội dung: S3 private, Presigned URL, CloudFront với Origin Access Control (OAC)

> **Lý thuyết:** [notes/06-storage-and-content-delivery.md](../../notes/06-storage-and-content-delivery.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 90–120 phút, phần lớn là chờ CloudFront deploy (tạo và disable mỗi lần 5–15 phút).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Bucket private: 4 cờ Block Public Access, Versioning, tag `Project=aws-lab-06` | Mở bucket public dưới bất kỳ hình thức nào |
| Presigned **PUT** bằng SDK (boto3), presigned **GET** bằng CLI, thời hạn ngắn | Đưa URL presigned thật vào repo hoặc chat |
| Checksum SHA-256 và negative case checksum sai | |
| CORS cho một origin cụ thể, kiểm tra preflight bằng `curl` | Dùng `AllowedOrigins: ["*"]` |
| Lifecycle: hủy multipart dở dang, hết hạn version cũ; quan sát multipart "vô hình" | |
| CloudFront + OAC tới **S3 REST endpoint**; S3 direct `403`, CDN `200` | Dùng S3 website endpoint |
| Quan sát `x-cache`, `age`; so sánh invalidation với versioned filename | |
| Tùy chọn: domain riêng qua Route 53 + ACM (chỉ khi bạn **đã sở hữu** domain) | Mua domain |
| Cleanup theo dependency, kiểm tra inventory | |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region của bucket | `ap-southeast-1` |
| `ACCOUNT_ID` | Account ID 12 chữ số (lấy tự động) | `111122223333` |
| `BUCKET_NAME` | Tên bucket duy nhất toàn cầu | `lab06-order-assets-111122223333` |
| `OAC_ID`, `DIST_ID`, `CF_DOMAIN` | OAC, distribution và domain CloudFront | `E1234567890ABC`, `d111111abcdef8.cloudfront.net` |
| `LAB_DIR` | Thư mục tạm chứa file lab | `/tmp/tmp.XXXX` |

Lab đặt `AWS_PROFILE` và `AWS_REGION` bằng biến môi trường để cả AWS CLI và boto3 cùng dùng một profile/Region. Distribution được nhận diện bằng `Comment=aws-lab-06`, OAC bằng tên `lab06-oac`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 01](../01-foundations/README.md) đến [Lab 04](../04-networking/README.md). Lab này không dùng tài nguyên của lab trước.
- [ ] Công cụ: AWS CLI v2, `curl`, `jq`, `openssl`, Python 3 (dùng để tạo virtualenv cài `boto3`).
- [ ] Quyền cần thiết (không cần `AdministratorAccess`; có thể giới hạn S3 theo `arn:aws:s3:::lab06-*`):
  - **S3 control plane:** `s3:CreateBucket`, `s3:PutBucketTagging`, `s3:PutBucketPublicAccessBlock`, `s3:GetBucketPublicAccessBlock`, `s3:PutBucketVersioning`, `s3:GetBucketVersioning`, `s3:PutBucketPolicy`, `s3:GetBucketPolicy`, `s3:PutBucketCORS`, `s3:PutLifecycleConfiguration`, `s3:DeleteBucket`.
  - **S3 data plane:** `s3:PutObject`, `s3:GetObject`, `s3:ListBucket`, `s3:ListBucketVersions`, `s3:DeleteObject`, `s3:DeleteObjectVersion`, `s3:ListBucketMultipartUploads`, `s3:AbortMultipartUpload`. Presigned URL chỉ hoạt động khi **người ký** có quyền tương ứng (`s3:PutObject` cho URL PUT, `s3:GetObject` cho URL GET).
  - **CloudFront:** `cloudfront:CreateOriginAccessControl`, `GetOriginAccessControl`, `ListOriginAccessControls`, `DeleteOriginAccessControl`, `CreateDistribution`, `GetDistribution`, `GetDistributionConfig`, `UpdateDistribution`, `DeleteDistribution`, `ListDistributions`, `CreateInvalidation`, `GetInvalidation`.
  - **Nhánh tùy chọn:** `acm:RequestCertificate`, `acm:DescribeCertificate`, `acm:DeleteCertificate`, `route53:ChangeResourceRecordSets`, `route53:ListHostedZones`.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại [S3 pricing](https://aws.amazon.com/s3/pricing/) và [CloudFront pricing](https://aws.amazon.com/cloudfront/pricing/))

| Tài nguyên | Quy mô trong lab | Đơn giá minh họa | Chi phí |
|---|---|---|---|
| S3 Standard storage | < 10 MB (kể cả part multipart 5 MB) | ~$0.025/GB-tháng | < $0.001 |
| S3 request PUT/GET/LIST | vài trăm | ~$0.005/1.000 PUT, ~$0.0004/1.000 GET | < $0.01 |
| CloudFront data transfer + request | vài chục request, vài KB | ~$0.085–0.12/GB tùy khu vực | < $0.001 |
| CloudFront invalidation | 1 path | 1.000 path đầu mỗi tháng không tính phí | $0 |
| Route 53 hosted zone (tùy chọn, nếu bạn tạo zone mới) | 1 | ~$0.50/zone-tháng | ~$0.50 |
| ACM public certificate (tùy chọn) | 1 | không tính phí khi dùng với CloudFront | $0 |
| **Tổng lab chính** | | | **< $0.05** |

- Không có tài nguyên tính phí theo giờ, nên quên xóa một tháng vẫn chỉ vài cent. Rủi ro thật là **bucket còn sót** và **distribution còn chạy** bị truy cập từ bên ngoài, không phải tiền giờ.
- **Điều kiện dừng:** nếu sau 2,5 giờ chưa xong, chạy cleanup (Bước 10) rồi làm lại sau.

---

## Sơ đồ kiến trúc Lab 06

```text
                    ┌─────────────────────────────────────────┐
                    │ Client (curl / trình duyệt)              │
                    └────┬──────────────────────────────┬─────┘
       1. GET qua CDN    │                              │ 2. PUT/GET bằng presigned URL
                         ▼                              │    (chữ ký SigV4 của người ký)
             ┌───────────────────────┐                  │
             │ CloudFront            │                  │
             │ Comment=aws-lab-06    │                  │
             └───────────┬───────────┘                  │
                         │ request ký SigV4 qua OAC     │
                         ▼                              ▼
             ┌───────────────────────────────────────────────┐
             │ S3 bucket lab06-order-assets-<ACCOUNT_ID>     │
             │  - Block Public Access: 4 cờ = true           │
             │  - Versioning: Enabled                        │
             │  - Bucket policy: chỉ distribution này đọc    │
             │  - CORS: chỉ https://shop.example.com         │
             │  - Lifecycle: abort MPU 1 ngày, noncurrent 30 │
             └───────────────────────────────────────────────┘
                         ▲
                         │ 3. GET thẳng S3 không chữ ký → 403 AccessDenied
             ┌───────────┴───────────┐
             │ Client ẩn danh        │
             └───────────────────────┘
```

---

## Bước 0 — Chuẩn bị biến và thư mục làm việc

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export BUCKET_NAME="lab06-order-assets-${ACCOUNT_ID}"
export LAB_DIR=$(mktemp -d)
cd "${LAB_DIR}"

aws sts get-caller-identity --query Arn --output text
echo "Bucket: ${BUCKET_NAME} | Region: ${AWS_REGION} | Thư mục: ${LAB_DIR}"

# Virtualenv riêng cho boto3, không cài vào Python hệ thống
python3 -m venv .venv && . .venv/bin/activate && pip install --quiet boto3
python3 -c "import boto3; print('boto3', boto3.__version__)"
```

*Kỳ vọng:* ARN là role/user của profile lab (không phải root), boto3 in ra phiên bản.

---

## Bước 1 — Tạo bucket private với Block Public Access và Versioning

```bash
# us-east-1 không nhận LocationConstraint; mọi Region khác bắt buộc có
if [ "${AWS_REGION}" = "us-east-1" ]; then
  aws s3api create-bucket --bucket "${BUCKET_NAME}"
else
  aws s3api create-bucket --bucket "${BUCKET_NAME}" \
    --create-bucket-configuration LocationConstraint="${AWS_REGION}"
fi

aws s3api put-bucket-tagging --bucket "${BUCKET_NAME}" \
  --tagging 'TagSet=[{Key=Project,Value=aws-lab-06}]'

# Bucket mới đã bật sẵn 4 cờ BPA từ 2023; đặt lại tường minh để không phụ thuộc mặc định
aws s3api put-public-access-block --bucket "${BUCKET_NAME}" \
  --public-access-block-configuration \
  "BlockPublicAcls=true,IgnorePublicAcls=true,BlockPublicPolicy=true,RestrictPublicBuckets=true"

aws s3api put-bucket-versioning --bucket "${BUCKET_NAME}" \
  --versioning-configuration Status=Enabled
```

Kiểm tra:

```bash
aws s3api get-bucket-versioning --bucket "${BUCKET_NAME}"
aws s3api get-public-access-block --bucket "${BUCKET_NAME}"
```

*Kỳ vọng (minh họa):*

```json
{ "Status": "Enabled" }
{
  "PublicAccessBlockConfiguration": {
    "BlockPublicAcls": true, "IgnorePublicAcls": true,
    "BlockPublicPolicy": true, "RestrictPublicBuckets": true
  }
}
```

Nếu `create-bucket` báo `BucketAlreadyExists`, tên đã bị account khác dùng: thêm hậu tố ngẫu nhiên vào `BUCKET_NAME`. `BucketAlreadyOwnedByYou` nghĩa là bạn đã tạo từ lần chạy trước, có thể đi tiếp.

---

## Bước 2 — Presigned URL: upload, download và negative case

`aws s3 presign` của CLI **chỉ sinh URL cho GET**. Để upload trực tiếp, backend phải ký URL cho `PutObject` bằng SDK. Script dưới đây đóng vai backend.

### 1. Script ký URL PUT (đóng vai backend)

```bash
cat > presign_put.py << 'EOF'
import os, sys
import boto3
from botocore.config import Config

# Regional endpoint + SigV4: tránh redirect 307 với bucket mới tạo ở Region khác us-east-1
s3 = boto3.client("s3", region_name=os.environ["AWS_REGION"],
                  config=Config(signature_version="s3v4"))
key, content_type = sys.argv[1], sys.argv[2]
print(s3.generate_presigned_url(
    "put_object",
    Params={"Bucket": os.environ["BUCKET_NAME"], "Key": key, "ContentType": content_type},
    ExpiresIn=300,  # 5 phút
))
EOF
```

`ContentType` nằm trong phần được ký, nên client **bắt buộc** gửi đúng header `Content-Type` đó. Đây là cách backend giới hạn loại file client được upload.

### 2. Upload bằng presigned PUT

```bash
echo "Đơn hàng #10023 - Khách hàng: Khách Mẫu - Tổng tiền: 1.500.000 VND" > invoice-10023.txt
UPLOAD_URL=$(python3 presign_put.py invoices/invoice-10023.txt text/plain)

curl -sS -o /dev/null -w "HTTP %{http_code}\n" -X PUT \
  -H "Content-Type: text/plain" --upload-file invoice-10023.txt "${UPLOAD_URL}"
aws s3 ls "s3://${BUCKET_NAME}/invoices/"
```

*Kỳ vọng:* `HTTP 200`, sau đó `aws s3 ls` hiện `invoice-10023.txt`. Không in `UPLOAD_URL` ra log chung: ai có URL đều upload được trong 5 phút.

### 3. Negative case: header khác lúc ký

```bash
UPLOAD_URL=$(python3 presign_put.py invoices/invoice-10023.txt text/plain)
curl -sS -X PUT -H "Content-Type: application/pdf" \
  --upload-file invoice-10023.txt "${UPLOAD_URL}" | grep -o '<Code>[^<]*</Code>'
```

*Kỳ vọng:* `<Code>SignatureDoesNotMatch</Code>` (HTTP 403). Object không bị ghi đè.

### 4. Presigned GET và negative case hết hạn

```bash
GET_URL=$(aws s3 presign "s3://${BUCKET_NAME}/invoices/invoice-10023.txt" --expires-in 300)
curl -sS "${GET_URL}"

SHORT_URL=$(aws s3 presign "s3://${BUCKET_NAME}/invoices/invoice-10023.txt" --expires-in 5)
sleep 10
curl -sS "${SHORT_URL}" | grep -o '<Message>[^<]*</Message>'
```

*Kỳ vọng:* lệnh đầu in nội dung hóa đơn. Lệnh sau trả `<Message>Request has expired</Message>` (HTTP 403).

> [!NOTE]
> Profile lab dùng temporary credentials (IAM Identity Center/role). URL presigned **không sống lâu hơn session** đã ký nó: nếu session hết hạn sau 1 giờ thì URL `--expires-in 604800` cũng chết sau 1 giờ.

### 5. Versioning: ghi đè và quan sát phiên bản

```bash
echo "Đơn hàng #10023 - ĐÃ THANH TOÁN" > invoice-10023.txt
UPLOAD_URL=$(python3 presign_put.py invoices/invoice-10023.txt text/plain)
curl -sS -o /dev/null -w "HTTP %{http_code}\n" -X PUT \
  -H "Content-Type: text/plain" --upload-file invoice-10023.txt "${UPLOAD_URL}"

aws s3api list-object-versions --bucket "${BUCKET_NAME}" \
  --prefix invoices/invoice-10023.txt \
  --query 'Versions[].[VersionId,IsLatest,Size]' --output table
```

*Kỳ vọng:* 2 dòng; bản mới `IsLatest=True`, bản cũ `False` vẫn còn và vẫn bị tính tiền lưu trữ.

### 6. Checksum SHA-256 và negative case

```bash
aws s3api put-object --bucket "${BUCKET_NAME}" --key invoices/checksum-demo.txt \
  --body invoice-10023.txt --checksum-algorithm SHA256 --content-type text/plain >/dev/null

echo "Local : $(openssl dgst -sha256 -binary invoice-10023.txt | base64)"
echo "S3    : $(aws s3api head-object --bucket "${BUCKET_NAME}" --key invoices/checksum-demo.txt \
  --checksum-mode ENABLED --query ChecksumSHA256 --output text)"

# Negative: gửi checksum không khớp nội dung (SHA-256 của chuỗi rỗng)
aws s3api put-object --bucket "${BUCKET_NAME}" --key invoices/bad-checksum.txt \
  --body invoice-10023.txt \
  --checksum-sha256 "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hi1vI="
```

*Kỳ vọng:* hai giá trị `Local` và `S3` giống nhau. Lệnh cuối thất bại với lỗi `BadDigest` (hoặc thông điệp tương đương "did not match the calculated checksum"); `aws s3 ls s3://${BUCKET_NAME}/invoices/` không có `bad-checksum.txt`.

---

## Bước 3 — CORS: cho phép đúng một origin

```bash
cat > cors.json << 'EOF'
{
  "CORSRules": [{
    "AllowedOrigins": ["https://shop.example.com"],
    "AllowedMethods": ["PUT", "GET"],
    "AllowedHeaders": ["content-type", "x-amz-checksum-sha256"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 300
  }]
}
EOF
aws s3api put-bucket-cors --bucket "${BUCKET_NAME}" --cors-configuration file://cors.json

S3_URL="https://${BUCKET_NAME}.s3.${AWS_REGION}.amazonaws.com/invoices/new.txt"
preflight() {
  curl -sS -o /dev/null -D - -X OPTIONS "${S3_URL}" \
    -H "Origin: $1" -H "Access-Control-Request-Method: PUT" \
    -H "Access-Control-Request-Headers: content-type" \
    | grep -iE '^HTTP|^access-control-allow-origin'
}
preflight https://shop.example.com
preflight https://evil.example.net
```

*Kỳ vọng (minh họa):*

```text
HTTP/1.1 200 OK
Access-Control-Allow-Origin: https://shop.example.com
HTTP/1.1 403 Forbidden
```

Preflight thành công **không** cấp quyền ghi: request PUT thật vẫn cần chữ ký hợp lệ. CORS chỉ cho trình duyệt biết có được phép đọc response hay không.

---

## Bước 4 — Lifecycle và multipart upload "vô hình"

### 1. Tạo một multipart upload dở dang

```bash
head -c 5242880 /dev/urandom > part1.bin   # 5 MiB, kích thước part tối thiểu
UPLOAD_ID=$(aws s3api create-multipart-upload --bucket "${BUCKET_NAME}" \
  --key backups/big.bin --query UploadId --output text)
aws s3api upload-part --bucket "${BUCKET_NAME}" --key backups/big.bin \
  --part-number 1 --upload-id "${UPLOAD_ID}" --body part1.bin >/dev/null

aws s3 ls "s3://${BUCKET_NAME}/backups/" || echo "(aws s3 ls không thấy gì)"
aws s3api list-multipart-uploads --bucket "${BUCKET_NAME}" \
  --query 'Uploads[].[Key,Initiated]' --output table
```

*Kỳ vọng:* `aws s3 ls` không thấy `big.bin`, nhưng `list-multipart-uploads` có một dòng. 5 MiB này **vẫn bị tính tiền** cho tới khi bị abort. Đây là lý do "xóa hết object" chưa chắc đã hết storage.

### 2. Lifecycle rule

```bash
cat > lifecycle.json << 'EOF'
{
  "Rules": [{
    "ID": "lab06-hygiene",
    "Status": "Enabled",
    "Filter": {},
    "AbortIncompleteMultipartUpload": { "DaysAfterInitiation": 1 },
    "NoncurrentVersionExpiration": { "NoncurrentDays": 30 }
  }]
}
EOF
aws s3api put-bucket-lifecycle-configuration --bucket "${BUCKET_NAME}" \
  --lifecycle-configuration file://lifecycle.json
aws s3api get-bucket-lifecycle-configuration --bucket "${BUCKET_NAME}" --query 'Rules[].ID'
```

Lifecycle chạy bất đồng bộ (thường vài lần mỗi ngày), nên upload dở dang ở trên **không** biến mất ngay. Cleanup ở Bước 10 abort thủ công. Rule này không có transition sang Standard-IA/Glacier vì object nhỏ hơn 128 KB và sống dưới 30 ngày sẽ đắt hơn khi chuyển tầng.

---

## Bước 5 — Tạo OAC và CloudFront Distribution

### 1. Nội dung tĩnh

```bash
mkdir -p web
cat > web/index.html << 'EOF'
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Cổng đơn hàng</title></head>
<body><h1>Cổng đơn hàng V1</h1><script src="/app.js"></script></body></html>
EOF
echo 'console.log("app v1");' > web/app.js

aws s3 cp web/index.html "s3://${BUCKET_NAME}/index.html" --content-type "text/html; charset=utf-8"
aws s3 cp web/app.js "s3://${BUCKET_NAME}/app.js" --content-type "application/javascript"
```

### 2. Origin Access Control

```bash
OAC_ID=$(aws cloudfront create-origin-access-control \
  --origin-access-control-config \
  "Name=lab06-oac,Description=aws-lab-06,SigningProtocol=sigv4,SigningBehavior=always,OriginAccessControlOriginType=s3" \
  --query 'OriginAccessControl.Id' --output text)
echo "OAC_ID=${OAC_ID}"
```

### 3. Distribution trỏ tới S3 REST endpoint

```bash
cat > cf-config.json << EOF
{
  "CallerReference": "lab06-$(date +%s)",
  "Comment": "aws-lab-06",
  "DefaultRootObject": "index.html",
  "Enabled": true,
  "PriceClass": "PriceClass_100",
  "Origins": {
    "Quantity": 1,
    "Items": [{
      "Id": "S3Origin",
      "DomainName": "${BUCKET_NAME}.s3.${AWS_REGION}.amazonaws.com",
      "OriginAccessControlId": "${OAC_ID}",
      "S3OriginConfig": { "OriginAccessIdentity": "" }
    }]
  },
  "DefaultCacheBehavior": {
    "TargetOriginId": "S3Origin",
    "ViewerProtocolPolicy": "redirect-to-https",
    "AllowedMethods": {
      "Quantity": 2, "Items": ["GET", "HEAD"],
      "CachedMethods": { "Quantity": 2, "Items": ["GET", "HEAD"] }
    },
    "CachePolicyId": "658327ea-f89d-4fab-a63d-7e88639e58f6",
    "Compress": true
  }
}
EOF

read -r DIST_ID CF_DOMAIN < <(aws cloudfront create-distribution \
  --distribution-config file://cf-config.json \
  --query 'Distribution.[Id,DomainName]' --output text)
echo "DIST_ID=${DIST_ID} CF_DOMAIN=${CF_DOMAIN}"
```

- `658327ea-…` là managed cache policy **CachingOptimized**: cache key chỉ gồm host + path, không query string/header/cookie; TTL mặc định 1 ngày nếu origin không gửi `Cache-Control`.
- `OriginAccessIdentity: ""` là bắt buộc về cú pháp khi dùng OAC; để rỗng nghĩa là không dùng OAI (cơ chế cũ).
- `PriceClass_100` giới hạn edge ở Bắc Mỹ/châu Âu cho rẻ hơn; từ Việt Nam latency sẽ cao hơn, chấp nhận được với lab.

---

## Bước 6 — Bucket policy cho đúng distribution này

```bash
cat > bucket-policy.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "AllowCloudFrontOACReadOnly",
    "Effect": "Allow",
    "Principal": { "Service": "cloudfront.amazonaws.com" },
    "Action": "s3:GetObject",
    "Resource": "arn:aws:s3:::${BUCKET_NAME}/*",
    "Condition": {
      "StringEquals": {
        "AWS:SourceArn": "arn:aws:cloudfront::${ACCOUNT_ID}:distribution/${DIST_ID}"
      }
    }
  }]
}
EOF
aws s3api put-bucket-policy --bucket "${BUCKET_NAME}" --policy file://bucket-policy.json
```

Policy này không bị `BlockPublicPolicy` chặn vì principal là một service và bị ràng buộc bằng `AWS:SourceArn` của một distribution cụ thể, nên S3 không coi là public. Policy không cấp `s3:ListBucket`, nên CloudFront nhận `403` (không phải `404`) với key không tồn tại.

Chờ distribution deploy:

```bash
aws cloudfront wait distribution-deployed --id "${DIST_ID}"
aws cloudfront get-distribution --id "${DIST_ID}" --query 'Distribution.Status' --output text
```

*Kỳ vọng:* `Deployed` (thường 5–15 phút).

---

## Bước 7 — S3 direct bị chặn, CloudFront đọc được, quan sát cache

### 1. Negative case: truy cập thẳng S3

```bash
curl -sS -o /dev/null -w "S3 direct: HTTP %{http_code}\n" \
  "https://${BUCKET_NAME}.s3.${AWS_REGION}.amazonaws.com/index.html"
```

*Kỳ vọng:* `S3 direct: HTTP 403`, body XML `<Code>AccessDenied</Code>`.

### 2. Positive case qua CloudFront

```bash
hdr() { curl -sS -o /dev/null -D - "https://${CF_DOMAIN}$1" | grep -iE '^HTTP|^x-cache|^age|^content-type'; }
hdr /index.html
hdr /index.html
```

*Kỳ vọng (minh họa):*

```text
HTTP/2 200
content-type: text/html; charset=utf-8
x-cache: Miss from cloudfront
HTTP/2 200
content-type: text/html; charset=utf-8
x-cache: Hit from cloudfront
age: 3
```

Lần hai thường là `Hit` vì cùng edge đã có bản sao; nếu vẫn `Miss`, request có thể rơi vào server khác trong POP, chạy thêm 1–2 lần. `age` là số giây object đã nằm trong cache.

### 3. Negative case: key không tồn tại

```bash
curl -sS -o /dev/null -w "HTTP %{http_code}\n" "https://${CF_DOMAIN}/khong-ton-tai.html"
```

*Kỳ vọng:* `HTTP 403` (không phải 404) vì policy không cho `s3:ListBucket`. Đây là lý do 403 từ CloudFront chưa chắc là lỗi OAC (xem Troubleshooting).

---

## Bước 8 — Invalidation và versioned filename

### 1. Nội dung cũ do cache

```bash
sed -i.bak 's/V1/V2/' web/index.html
aws s3 cp web/index.html "s3://${BUCKET_NAME}/index.html" --content-type "text/html; charset=utf-8"
curl -sS "https://${CF_DOMAIN}/index.html" | grep -o '<h1>[^<]*</h1>'
```

*Kỳ vọng:* vẫn `<h1>Cổng đơn hàng V1</h1>`: S3 đã có V2 nhưng edge còn giữ V1 tới hết TTL (1 ngày với CachingOptimized).

### 2. Invalidation

```bash
INV_ID=$(aws cloudfront create-invalidation --distribution-id "${DIST_ID}" \
  --paths "/index.html" --query 'Invalidation.Id' --output text)
aws cloudfront wait invalidation-completed --distribution-id "${DIST_ID}" --id "${INV_ID}"
curl -sS "https://${CF_DOMAIN}/index.html" | grep -o '<h1>[^<]*</h1>'
hdr /index.html
```

*Kỳ vọng:* `<h1>Cổng đơn hàng V2</h1>`, request đầu sau invalidation là `Miss from cloudfront`. Invalidation **không** xóa cache trình duyệt của người dùng.

### 3. Versioned filename

```bash
echo 'console.log("app v2");' > web/app.3f9a2c.js
aws s3 cp web/app.3f9a2c.js "s3://${BUCKET_NAME}/app.3f9a2c.js" \
  --content-type "application/javascript" --cache-control "public, max-age=31536000, immutable"
hdr /app.3f9a2c.js
```

*Kỳ vọng:* `200`, `Miss` ngay lần đầu, không cần invalidation. Trong thực tế, chỉ `index.html` (TTL ngắn) cần đổi để trỏ tới file mới; asset có hash được cache 1 năm.

---

## Bước 9 (nhánh tùy chọn) — Domain riêng qua Route 53 và ACM

Chỉ làm nếu bạn **đã sở hữu** một domain với public hosted zone trên Route 53. Bỏ qua bước này không ảnh hưởng mục tiêu chính.

1. Yêu cầu certificate **ở `us-east-1`** (CloudFront chỉ dùng certificate ACM ở Region này):
   ```bash
   CERT_ARN=$(aws acm request-certificate --region us-east-1 \
     --domain-name "assets.<YOUR_DOMAIN>" --validation-method DNS \
     --query CertificateArn --output text)
   aws acm describe-certificate --region us-east-1 --certificate-arn "${CERT_ARN}" \
     --query 'Certificate.DomainValidationOptions[0].ResourceRecord'
   ```
2. Tạo bản ghi CNAME validation ở trên vào hosted zone (console: nút **Create records in Route 53** trong ACM). Chờ `Status` = `ISSUED`.
3. Sửa distribution: thêm `Aliases` = `assets.<YOUR_DOMAIN>`, `ViewerCertificate` dùng `ACMCertificateArn=${CERT_ARN}`, `SSLSupportMethod=sni-only`, `MinimumProtocolVersion=TLSv1.2_2021`.
4. Tạo bản ghi **A Alias** (và **AAAA Alias** nếu bật IPv6) `assets.<YOUR_DOMAIN>` → `${CF_DOMAIN}`, hosted zone ID của CloudFront là `Z2FDTNDATAQYW2`.
5. Kiểm tra: `dig +short assets.<YOUR_DOMAIN>` trả về IP của CloudFront; `curl -I https://assets.<YOUR_DOMAIN>/` trả `200`.

DNS TTL (bao lâu resolver nhớ bản ghi) và CDN TTL (bao lâu edge giữ object) là hai cơ chế độc lập: invalidation không làm resolver quên bản ghi cũ, và đổi bản ghi DNS không xóa cache của CloudFront.

Cleanup nhánh này: gỡ `Aliases`/certificate khỏi distribution (hoặc xóa distribution ở Bước 10), xóa bản ghi Alias, xóa bản ghi validation, rồi `aws acm delete-certificate --region us-east-1 --certificate-arn "${CERT_ARN}"`. Không xóa hosted zone nếu nó đang phục vụ domain của bạn.

---

## Bước 10 — Dọn dẹp theo dependency

Thứ tự: disable distribution → chờ deployed → xóa distribution → xóa OAC → abort multipart → xóa mọi version/delete marker → xóa bucket.

```bash
# 1. Disable distribution (bắt buộc trước khi xóa)
ETAG=$(aws cloudfront get-distribution-config --id "${DIST_ID}" --query ETag --output text)
aws cloudfront get-distribution-config --id "${DIST_ID}" --query DistributionConfig --output json \
  | jq '.Enabled = false' > cf-disable.json
aws cloudfront update-distribution --id "${DIST_ID}" --if-match "${ETAG}" \
  --distribution-config file://cf-disable.json >/dev/null
aws cloudfront wait distribution-deployed --id "${DIST_ID}"   # 5–15 phút

# 2. Xóa distribution với ETag mới
ETAG=$(aws cloudfront get-distribution-config --id "${DIST_ID}" --query ETag --output text)
aws cloudfront delete-distribution --id "${DIST_ID}" --if-match "${ETAG}"

# 3. Xóa OAC (chỉ được khi không còn distribution nào dùng)
OAC_ETAG=$(aws cloudfront get-origin-access-control --id "${OAC_ID}" --query ETag --output text)
aws cloudfront delete-origin-access-control --id "${OAC_ID}" --if-match "${OAC_ETAG}"

# 4. Abort multipart upload dở dang
aws s3api list-multipart-uploads --bucket "${BUCKET_NAME}" \
  --query 'Uploads[].[Key,UploadId]' --output text |
while read -r KEY UID_; do
  [ -n "${UID_}" ] && aws s3api abort-multipart-upload --bucket "${BUCKET_NAME}" --key "${KEY}" --upload-id "${UID_}"
done

# 5. Xóa mọi version và delete marker (mỗi lô tối đa 1.000), rồi xóa bucket
for KIND in Versions DeleteMarkers; do
  while :; do
    BATCH=$(aws s3api list-object-versions --bucket "${BUCKET_NAME}" --max-items 1000 --output json \
      --query "{Objects: ${KIND}[].{Key:Key,VersionId:VersionId}, Quiet: \`true\`}")
    [ "$(echo "${BATCH}" | jq '.Objects | length')" -gt 0 ] || break
    aws s3api delete-objects --bucket "${BUCKET_NAME}" --delete "${BATCH}" >/dev/null
  done
done
aws s3api delete-bucket --bucket "${BUCKET_NAME}"

# 6. File tạm
deactivate 2>/dev/null; cd ~ && rm -rf "${LAB_DIR}"
```

`aws s3 rm --recursive` trên bucket có versioning chỉ tạo thêm delete marker, không xóa version cũ; vì vậy phải xóa theo `VersionId` như trên.

### Kiểm tra inventory sau cleanup

```bash
echo "== Bucket";       aws s3api head-bucket --bucket "${BUCKET_NAME}" 2>&1 | grep -oE '404|Not Found' || echo "VẪN CÒN"
echo "== Distribution"; aws cloudfront list-distributions --query "DistributionList.Items[?Comment=='aws-lab-06'].Id" --output text
echo "== OAC";          aws cloudfront list-origin-access-controls --query "OriginAccessControlList.Items[?Name=='lab06-oac'].Id" --output text
echo "== Certificate";  aws acm list-certificates --region us-east-1 --query "CertificateSummaryList[?starts_with(DomainName,'assets.')].CertificateArn" --output text
```

*Kỳ vọng:* bucket in `404`/`Not Found`; ba mục còn lại rỗng. Billing có độ trễ, nên inventory qua API mới là bằng chứng cleanup.

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export BUCKET_NAME="lab06-order-assets-${ACCOUNT_ID}"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
DIST_ID=$(aws cloudfront list-distributions --query "DistributionList.Items[?Comment=='aws-lab-06'].Id | [0]" --output text)
OAC_ID=$(aws cloudfront list-origin-access-controls --query "OriginAccessControlList.Items[?Name=='lab06-oac'].Id | [0]" --output text)
echo "DIST_ID=${DIST_ID} OAC_ID=${OAC_ID}"
```

Sau đó chạy lại Bước 10. Nếu `DIST_ID` là `None`, bỏ qua mục 1–2; nếu distribution đã `Enabled=false` thì bỏ qua `update-distribution`. Lệnh nào báo `NoSuchDistribution`, `NoSuchOriginAccessControl`, `NoSuchBucket` nghĩa là tài nguyên đã được xóa.

---

## Checklist hoàn thành

- [ ] Bucket có tag `Project=aws-lab-06`, 4 cờ BPA `true`, versioning `Enabled`.
- [ ] Upload thành công bằng presigned PUT ký bởi SDK; giải thích được vì sao `aws s3 presign` không dùng được cho PUT.
- [ ] Thấy `SignatureDoesNotMatch` khi đổi `Content-Type`, `Request has expired` khi URL hết hạn.
- [ ] Checksum SHA-256 local và trên S3 khớp; checksum sai bị từ chối.
- [ ] Preflight từ origin được phép trả `Access-Control-Allow-Origin`; origin lạ bị `403`.
- [ ] Thấy multipart upload dở dang không hiện trong `aws s3 ls` nhưng có trong `list-multipart-uploads`.
- [ ] S3 direct `403`, CloudFront `200`; quan sát được `Miss` → `Hit` và header `age`.
- [ ] Thấy nội dung cũ trước invalidation và nội dung mới sau invalidation; versioned filename có hiệu lực ngay.
- [ ] Cleanup xong; inventory rỗng (bucket, distribution, OAC, certificate nếu làm nhánh tùy chọn).

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| CloudFront trả `403` cho mọi file | Bucket policy sai `AWS:SourceArn`, distribution chưa gắn OAC, hoặc origin là website endpoint | `aws s3api get-bucket-policy`; `get-distribution-config --query 'DistributionConfig.Origins.Items[0].[DomainName,OriginAccessControlId]'`; domain phải là `<bucket>.s3.<region>.amazonaws.com` |
| CloudFront `403` chỉ với một file | Key không tồn tại hoặc sai chữ hoa/thường | `aws s3api head-object --bucket ... --key ...`; nhớ không có `s3:ListBucket` thì key thiếu cũng ra 403 |
| Object mã hóa SSE-KMS bị `403` qua CloudFront | Key policy KMS chưa cho `cloudfront.amazonaws.com` `kms:Decrypt` | Lab dùng SSE-S3 mặc định nên không gặp; với SSE-KMS thêm statement vào key policy kèm `AWS:SourceArn` |
| Presigned PUT trả `SignatureDoesNotMatch` | `Content-Type` gửi lên khác lúc ký, hoặc URL bị sửa/escape sai | Gửi đúng header đã ký; đặt URL trong dấu nháy kép |
| Presigned trả `AccessDenied` dù chưa hết hạn | Người ký không có `s3:PutObject`/`GetObject`, hoặc session đã hết hạn | `aws sts get-caller-identity`; ký lại sau khi đăng nhập lại |
| Presigned URL bị redirect `307` | Bucket mới, URL dùng global endpoint | Ký với `region_name` đúng như script (regional endpoint) |
| Trình duyệt báo lỗi CORS | Origin/method/header không có trong rule | Chạy hàm `preflight` với đúng `Origin` và header; so với `get-bucket-cors` |
| Vẫn thấy nội dung cũ | Còn trong TTL của edge hoặc trình duyệt | Xem `x-cache`, `age`; invalidation đúng path (phân biệt `/index.html` và `/`); kiểm tra `Cache-Control` của object |
| `delete-distribution` báo `DistributionNotDisabled` | Chưa disable hoặc chưa deploy xong | `get-distribution --query 'Distribution.[Status,DistributionConfig.Enabled]'` phải là `Deployed False` |
| `delete-bucket` báo `BucketNotEmpty` | Còn version, delete marker | Chạy lại mục 5 của Bước 10; `list-object-versions --max-items 5` để xem còn gì |

---

## Tiếp theo

Bạn đã phục vụ nội dung từ bucket private qua CDN và cho client upload trực tiếp mà không mở quyền. Bài tiếp theo: **[Bài 07 — Database và cache: RDS, Aurora, DynamoDB, ElastiCache](../../notes/07-databases-and-cache.md)**.
