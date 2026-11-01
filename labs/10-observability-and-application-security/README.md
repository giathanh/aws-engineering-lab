# Lab 10 — Quan sát và bảo mật ứng dụng: structured log, metric, alarm, CloudTrail, Secrets Manager và KMS

> **Lý thuyết:** [notes/10-observability-and-application-security.md](../../notes/10-observability-and-application-security.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 90–120 phút (CloudTrail Event History có thể trễ 5–15 phút).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

Lab dùng một Lambda "order API" nhỏ làm fixture riêng (theo mô hình Lab 09), không phụ thuộc tài nguyên của bài trước.

| ✅ Có | ❌ Không |
|---|---|
| KMS customer managed key (CMK) riêng cho lab, alias, key policy | Đụng vào key dùng chung hoặc AWS managed key |
| Secret giả sinh ngẫu nhiên, mã hóa bằng CMK; parameter cấu hình thường | In giá trị secret ra terminal hoặc log |
| Role của app đọc **đúng** secret/parameter; principal khác bị từ chối | Cấp `secretsmanager:*` hoặc `kms:*` cho app |
| Structured JSON log, metric latency/error qua Embedded Metric Format (EMF), inject lỗi | |
| Logs Insights, alarm, dashboard; tùy chọn email SNS tới chính bạn | Gửi thông báo tới người khác |
| Encryption context: negative case decrypt sai context | |
| Cache secret và quan sát app dùng version cũ sau khi đổi secret | |
| CloudTrail management event: ai đọc secret, ai đổi parameter | Bật data event trên toàn account |
| Cleanup, giải thích recovery window của secret và scheduled deletion của key | |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `ACCOUNT_ID` | Account ID (lấy tự động) | `111122223333` |
| `KEY_ID`, `KEY_ARN` | CMK của lab | `1234abcd-12ab-34cd-56ef-1234567890ab` |
| `SECRET_ARN` | Secret giả | `arn:aws:secretsmanager:...:secret:lab10/order-api/db-AbCdEf` |
| `FN` | Tên function | `lab10-order-api` |
| `<YOUR_EMAIL>` | Email **của bạn** cho nhánh SNS tùy chọn | `ban@example.com` |

Tên cố định: `lab10-*` cho tài nguyên, `alias/lab10-order-key` cho key, `Lab10AppRole`/`Lab10OtherPrincipalRole` cho IAM. Tài nguyên hỗ trợ tag mang `Project=aws-lab-10`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 09](../09-serverless/README.md) (Lambda, execution role).
- [ ] Công cụ: AWS CLI v2, `zip`, `jq`.
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **KMS:** `kms:CreateKey`, `CreateAlias`, `DeleteAlias`, `TagResource`, `Encrypt`, `Decrypt`, `DescribeKey`, `ScheduleKeyDeletion`, `ListAliases`.
  - **Secrets Manager:** `secretsmanager:CreateSecret`, `GetRandomPassword`, `DescribeSecret`, `PutSecretValue`, `DeleteSecret`, `TagResource`.
  - **SSM:** `ssm:PutParameter`, `GetParameter`, `DeleteParameter`, `AddTagsToResource`.
  - **IAM:** `iam:CreateRole`, `PutRolePolicy`, `DeleteRolePolicy`, `AttachRolePolicy`, `DetachRolePolicy`, `DeleteRole`, `GetRole`, `TagRole`, **`iam:PassRole` trên `Lab10AppRole`**, và **`sts:AssumeRole` trên `Lab10OtherPrincipalRole`** (cho negative case).
  - **Lambda, CloudWatch Logs:** như [Lab 09](../09-serverless/README.md), thêm `logs:StartQuery`, `GetQueryResults`.
  - **CloudWatch:** `cloudwatch:PutMetricAlarm`, `DescribeAlarms`, `DeleteAlarms`, `PutDashboard`, `DeleteDashboards`, `GetMetricStatistics`.
  - **CloudTrail:** `cloudtrail:LookupEvents`. **X-Ray (tùy chọn):** `xray:GetTraceSummaries`. **SNS (tùy chọn):** `sns:CreateTopic`, `Subscribe`, `DeleteTopic`.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại [KMS](https://aws.amazon.com/kms/pricing/), [Secrets Manager](https://aws.amazon.com/secrets-manager/pricing/), [CloudWatch](https://aws.amazon.com/cloudwatch/pricing/) pricing)

| Tài nguyên | Quy mô | Đơn giá minh họa | Chi phí buổi lab |
|---|---|---|---|
| KMS CMK | 1 key | ~$1/key-tháng, tính theo giờ tồn tại | ~$0.01 |
| KMS request | < 100 | ~$0.03/10.000 request | < $0.001 |
| Secrets Manager | 1 secret | ~$0.40/secret-tháng, tính theo thời gian tồn tại | < $0.01 |
| Custom metric (EMF) | 2 metric | ~$0.30/metric-tháng, tính theo giờ | < $0.01 |
| Alarm | 1 | ~$0.10/alarm-tháng | < $0.01 |
| Dashboard | 1 | 3 dashboard đầu không tính phí; sau đó ~$3/dashboard-tháng | $0 hoặc ~$0.10 |
| Lambda, Logs, X-Ray, SNS email | vài chục request | rất nhỏ | < $0.01 |
| **Tổng** | | | **< $0.10** |

- Sau cleanup, **key vẫn tồn tại 7 ngày** ở trạng thái `PendingDeletion` (khoảng chờ tối thiểu). Tra trang giá KMS để biết key đang chờ xóa có bị tính phí không; trường hợp xấu nhất là ~$0.25 cho 7 ngày.
- **Điều kiện dừng:** nếu sau 2,5 giờ chưa xong, chạy cleanup (Bước 10).

---

## Sơ đồ kiến trúc Lab 10

```text
                    aws lambda invoke (happy path / fault)
                                   │
                                   ▼
┌────────────────────────── Lambda lab10-order-api ───────────────────────────┐
│ role Lab10AppRole:                                                          │
│   secretsmanager:GetSecretValue  → đúng 1 secret                            │
│   kms:Decrypt (qua Secrets Manager) → đúng 1 key                            │
│   ssm:GetParameter               → đúng 1 parameter                         │
│ cache secret 60 s │ log JSON + correlation_id │ EMF: Latency, Errors        │
└───────┬─────────────────────┬────────────────────────────┬──────────────────┘
        ▼                     ▼                            ▼
 Secrets Manager        SSM Parameter Store        CloudWatch Logs (retention 7 ngày)
 lab10/order-api/db     /lab10/order-api/           │  Logs Insights
 (mã hóa bằng CMK)      payment-url (String)        ▼
        │                                     CloudWatch Metrics Lab10/OrderApi
        ▼                                       ├─ Alarm Errors ≥ 3/phút → (SNS email, tùy chọn)
 KMS alias/lab10-order-key                      └─ Dashboard lab10-order-api
        ▲
        │ AssumeRole → GetSecretValue → "Access to KMS is not allowed"
 Lab10OtherPrincipalRole (negative case)

 CloudTrail Event History: GetSecretValue, Decrypt, PutParameter, AssumeRole (ai, khi nào, kết quả)
```

---

## Bước 0 — Biến và thư mục làm việc

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export FN="lab10-order-api"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
LAB_START=$(date -u +%Y-%m-%dT%H:%M:%SZ)
aws sts get-caller-identity --query Arn --output text
```

---

## Bước 1 — KMS customer managed key

```bash
cat > key-policy.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "EnableIAMPoliciesInThisAccount",
    "Effect": "Allow",
    "Principal": { "AWS": "arn:aws:iam::${ACCOUNT_ID}:root" },
    "Action": "kms:*",
    "Resource": "*"
  }]
}
EOF
read -r KEY_ID KEY_ARN < <(aws kms create-key --description "Lab 10 order service" \
  --policy file://key-policy.json --tags TagKey=Project,TagValue=aws-lab-10 \
  --query 'KeyMetadata.[KeyId,Arn]' --output text)
aws kms create-alias --alias-name alias/lab10-order-key --target-key-id "${KEY_ID}"
echo "KEY_ID=${KEY_ID}"
```

Statement `EnableIAMPoliciesInThisAccount` **không** cho mọi người trong account dùng key. Nó nói: "key này tin IAM của account". Từ đó, ai được IAM policy cấp `kms:Decrypt` trên key mới dùng được. Thiếu statement này (và không có statement nào khác cho bạn), key có thể bị khóa không ai quản lý được; AWS khi đó chỉ khôi phục qua AWS Support.

---

## Bước 2 — Secret giả và parameter

```bash
# Sinh mật khẩu ngẫu nhiên phía AWS, không bao giờ in ra màn hình
PW=$(aws secretsmanager get-random-password --password-length 24 --exclude-punctuation \
  --query RandomPassword --output text)
SECRET_ARN=$(aws secretsmanager create-secret --name lab10/order-api/db \
  --kms-key-id alias/lab10-order-key \
  --secret-string "$(jq -cn --arg p "${PW}" '{username:"order_app", password:$p}')" \
  --tags Key=Project,Value=aws-lab-10 --query ARN --output text)
unset PW

aws ssm put-parameter --name /lab10/order-api/payment-url --type String \
  --value "https://payment.internal.example/v1" --tags Key=Project,Value=aws-lab-10 >/dev/null

aws secretsmanager describe-secret --secret-id "${SECRET_ARN}" --query '[Name,KmsKeyId]' --output text
```

*Kỳ vọng:* `lab10/order-api/db  arn:aws:kms:...:key/<KEY_ID>` (hoặc alias). Không có lệnh nào in giá trị mật khẩu.

URL thanh toán không bí mật nên dùng `String`. Nếu là secret nhưng không cần rotation, `SecureString` của Parameter Store là lựa chọn rẻ hơn; nó **không** có rotation tự động, versioning theo stage hay replication đa Region như Secrets Manager.

---

## Bước 3 — Role của app (least privilege) và role "principal khác"

```bash
cat > trust-lambda.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"lambda.amazonaws.com"},
 "Action":"sts:AssumeRole","Condition":{"StringEquals":{"aws:SourceAccount":"${ACCOUNT_ID}"}}}]}
EOF
aws iam create-role --role-name Lab10AppRole --assume-role-policy-document file://trust-lambda.json \
  --tags Key=Project,Value=aws-lab-10 >/dev/null
aws iam attach-role-policy --role-name Lab10AppRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole
aws iam attach-role-policy --role-name Lab10AppRole \
  --policy-arn arn:aws:iam::aws:policy/AWSXRayDaemonWriteAccess

cat > app-policy.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [
    { "Sid": "ReadOneSecret", "Effect": "Allow", "Action": "secretsmanager:GetSecretValue",
      "Resource": "${SECRET_ARN}" },
    { "Sid": "DecryptViaSecretsManagerOnly", "Effect": "Allow", "Action": "kms:Decrypt",
      "Resource": "${KEY_ARN}",
      "Condition": { "StringEquals": { "kms:ViaService": "secretsmanager.${AWS_REGION}.amazonaws.com" } } },
    { "Sid": "ReadOneParameter", "Effect": "Allow", "Action": "ssm:GetParameter",
      "Resource": "arn:aws:ssm:${AWS_REGION}:${ACCOUNT_ID}:parameter/lab10/order-api/payment-url" }
  ]
}
EOF
aws iam put-role-policy --role-name Lab10AppRole --policy-name Lab10AppAccess --policy-document file://app-policy.json
```

`kms:ViaService` buộc quyền giải mã chỉ dùng được **thông qua** Secrets Manager: code trong app không thể tự gọi `kms:Decrypt` trên ciphertext khác bằng key này.

Role cho negative case: có quyền đọc secret nhưng **không** có `kms:Decrypt`. Trust policy tin account của bạn, nên chỉ principal nào được IAM cho phép `sts:AssumeRole` trên role này mới assume được.

```bash
cat > trust-account.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"AWS":"arn:aws:iam::${ACCOUNT_ID}:root"},"Action":"sts:AssumeRole"}]}
EOF
aws iam create-role --role-name Lab10OtherPrincipalRole --assume-role-policy-document file://trust-account.json \
  --max-session-duration 3600 --tags Key=Project,Value=aws-lab-10 >/dev/null
cat > other-policy.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Action":"secretsmanager:GetSecretValue","Resource":"${SECRET_ARN}"}]}
EOF
aws iam put-role-policy --role-name Lab10OtherPrincipalRole --policy-name ReadSecretNoKms --policy-document file://other-policy.json
```

---

## Bước 4 — Lambda có structured log, EMF metric và cache secret

```bash
cat > app.py << 'EOF'
import json, os, random, time, uuid
import boto3

sm = boto3.client("secretsmanager")
ssm = boto3.client("ssm")
SECRET_ID = os.environ["SECRET_ID"]
TTL = int(os.environ.get("SECRET_TTL_SECONDS", "60"))
PAYMENT_URL = ssm.get_parameter(Name=os.environ["PAYMENT_URL_PARAM"])["Parameter"]["Value"]
_cache = {"at": 0.0, "value": None, "version": None}


def get_secret():
    """Đọc secret khi khởi động và làm mới sau TTL; không bao giờ log giá trị."""
    if _cache["value"] is None or time.time() - _cache["at"] > TTL:
        r = sm.get_secret_value(SecretId=SECRET_ID)
        _cache.update(at=time.time(), value=json.loads(r["SecretString"]), version=r["VersionId"])
    return _cache["value"], _cache["version"]


def log(level, **fields):
    print(json.dumps({"level": level, "service": "order-api", **fields}, ensure_ascii=False))


def emit_metrics(latency_ms, error):
    # Embedded Metric Format: CloudWatch tự tách thành metric, không cần gọi PutMetricData
    print(json.dumps({
        "_aws": {"Timestamp": int(time.time() * 1000), "CloudWatchMetrics": [{
            "Namespace": "Lab10/OrderApi", "Dimensions": [["Service"]],
            "Metrics": [{"Name": "Latency", "Unit": "Milliseconds"}, {"Name": "Errors", "Unit": "Count"}]}]},
        "Service": "order-api", "Latency": latency_ms, "Errors": 1 if error else 0}))


def lambda_handler(event, context):
    start, error = time.time(), False
    cid = event.get("correlation_id") or str(uuid.uuid4())
    try:
        creds, version = get_secret()
        if event.get("fault") == "error":
            raise RuntimeError("PaymentGatewayTimeout (giả lập)")
        time.sleep(random.uniform(0.02, 0.15))       # giả lập gọi DB/payment
        log("INFO", event="ORDER_CONFIRMED", correlation_id=cid, order_id=event.get("order_id"),
            db_user=creds["username"], secret_version=version[:8], payment_host=PAYMENT_URL.split("/")[2])
        return {"order_id": event.get("order_id"), "status": "CONFIRMED", "correlation_id": cid}
    except Exception as e:
        error = True
        log("ERROR", event="ORDER_FAILED", correlation_id=cid, order_id=event.get("order_id"), error=str(e))
        raise
    finally:
        emit_metrics(round((time.time() - start) * 1000, 1), error)
EOF
zip -q app.zip app.py

aws logs create-log-group --log-group-name "/aws/lambda/${FN}" --tags Project=aws-lab-10
aws logs put-retention-policy --log-group-name "/aws/lambda/${FN}" --retention-in-days 7
sleep 10   # chờ IAM propagate

aws lambda create-function --function-name "${FN}" --runtime python3.12 --architectures arm64 \
  --role "arn:aws:iam::${ACCOUNT_ID}:role/Lab10AppRole" --handler app.lambda_handler \
  --zip-file fileb://app.zip --memory-size 256 --timeout 10 --tracing-config Mode=Active \
  --environment "Variables={SECRET_ID=${SECRET_ARN},PAYMENT_URL_PARAM=/lab10/order-api/payment-url,SECRET_TTL_SECONDS=60}" \
  --tags Project=aws-lab-10 >/dev/null
aws lambda wait function-active-v2 --function-name "${FN}"
```

`--tracing-config Mode=Active` bật X-Ray tracing ở mức function (không cần SDK trong code). Muốn trace chi tiết từng lệnh gọi AWS SDK, dùng OpenTelemetry (AWS Distro for OpenTelemetry), xem lý thuyết Phần 1.

---

## Bước 5 — Happy path, inject lỗi và đọc log

```bash
invoke() {  # $1 = payload JSON
  aws lambda invoke --function-name "${FN}" --cli-binary-format raw-in-base64-out \
    --payload "$1" out.json --query FunctionError --output text >/dev/null; cat out.json; echo
}
for i in $(seq 1 10); do invoke "{\"order_id\":\"ORD-$i\"}" >/dev/null; done
invoke '{"order_id":"ORD-100","correlation_id":"demo-cid-001"}'
for i in $(seq 1 4); do invoke "{\"order_id\":\"ORD-F$i\",\"fault\":\"error\"}" >/dev/null; done
```

*Kỳ vọng:* request `ORD-100` trả `{"order_id": "ORD-100", "status": "CONFIRMED", "correlation_id": "demo-cid-001"}`; 4 request lỗi trả `errorMessage: PaymentGatewayTimeout (giả lập)`.

Logs Insights: p95 latency, số lỗi, và hành trình của một correlation ID:

```bash
q() {
  local QID=$(aws logs start-query --log-group-name "/aws/lambda/${FN}" \
    --start-time $(( $(date +%s) - 1800 )) --end-time $(date +%s) --query-string "$1" --query queryId --output text)
  sleep 5; aws logs get-query-results --query-id "${QID}" --query 'results[][].[field,value]' --output text
}
q 'filter ispresent(Latency) | stats count(*) as calls, sum(Errors) as errors, pct(Latency, 95) as p95_ms'
q 'filter correlation_id = "demo-cid-001" | fields @timestamp, level, event, order_id'
q 'filter level = "ERROR" | stats count(*) by error'
```

*Kỳ vọng (minh họa):* `calls 15  errors 4  p95_ms 148.2`; một dòng `INFO ORDER_CONFIRMED ORD-100`; `PaymentGatewayTimeout (giả lập)  4`.

Kiểm tra secret **không** lọt vào log:

```bash
q 'filter @message like /password/ | count(*) as leaks'
```

*Kỳ vọng:* `leaks 0`. Log chỉ có `db_user` và 8 ký tự đầu của `secret_version`.

---

## Bước 6 — Alarm, dashboard và (tùy chọn) thông báo

```bash
# (Tùy chọn) SNS email tới chính bạn; bấm link xác nhận trong email
# TOPIC_ARN=$(aws sns create-topic --name lab10-alerts --tags Key=Project,Value=aws-lab-10 --query TopicArn --output text)
# aws sns subscribe --topic-arn "${TOPIC_ARN}" --protocol email --notification-endpoint "<YOUR_EMAIL>"
aws cloudwatch put-metric-alarm --alarm-name lab10-order-api-errors \
  --alarm-description "Lab 10: >= 3 lỗi trong 1 phút" \
  --namespace Lab10/OrderApi --metric-name Errors --dimensions Name=Service,Value=order-api \
  --statistic Sum --period 60 --evaluation-periods 1 --datapoints-to-alarm 1 \
  --threshold 3 --comparison-operator GreaterThanOrEqualToThreshold \
  --treat-missing-data notBreaching --tags Key=Project,Value=aws-lab-10
if [ -n "${TOPIC_ARN}" ]; then   # gắn action SNS nếu đã làm nhánh tùy chọn
  aws cloudwatch put-metric-alarm --alarm-name lab10-order-api-errors \
    --namespace Lab10/OrderApi --metric-name Errors --dimensions Name=Service,Value=order-api \
    --statistic Sum --period 60 --evaluation-periods 1 --datapoints-to-alarm 1 \
    --threshold 3 --comparison-operator GreaterThanOrEqualToThreshold --treat-missing-data notBreaching \
    --alarm-actions "${TOPIC_ARN}" --ok-actions "${TOPIC_ARN}"
fi

cat > dashboard.json << EOF
{"widgets":[
 {"type":"metric","x":0,"y":0,"width":12,"height":6,"properties":{"title":"Latency p95 (ms)","region":"${AWS_REGION}",
  "metrics":[["Lab10/OrderApi","Latency","Service","order-api",{"stat":"p95"}]],"period":60}},
 {"type":"metric","x":12,"y":0,"width":12,"height":6,"properties":{"title":"Errors / phút","region":"${AWS_REGION}",
  "metrics":[["Lab10/OrderApi","Errors","Service","order-api",{"stat":"Sum"}]],"period":60}}]}
EOF
aws cloudwatch put-dashboard --dashboard-name lab10-order-api --dashboard-body file://dashboard.json

# Bơm lỗi lần nữa để alarm có dữ liệu mới trong phút hiện tại
for i in $(seq 1 4); do invoke "{\"order_id\":\"ORD-G$i\",\"fault\":\"error\"}" >/dev/null; done
for i in $(seq 1 12); do
  S=$(aws cloudwatch describe-alarms --alarm-names lab10-order-api-errors --query 'MetricAlarms[0].StateValue' --output text)
  echo "$(date +%T) ${S}"; [ "${S}" = ALARM ] && break; sleep 20
done
```

*Kỳ vọng:* trạng thái đi từ `INSUFFICIENT_DATA` (alarm mới) sang `ALARM` trong 1–3 phút. Sau vài phút không có lỗi, alarm về `OK` nhờ `notBreaching`. Mở console CloudWatch → Dashboards → `lab10-order-api` để thấy hai biểu đồ.

Nếu alarm không đổi: kiểm tra metric đã tồn tại chưa (`aws cloudwatch list-metrics --namespace Lab10/OrderApi`), statistic là `Sum` (không phải `Average`, vì `Average` của 0/1 luôn ≤ 1), và dimension khớp chính xác.

---

## Bước 7 — Negative case KMS: principal khác và encryption context

### 1. Principal khác có quyền đọc secret nhưng không có `kms:Decrypt`

```bash
read -r AK SK ST < <(aws sts assume-role \
  --role-arn "arn:aws:iam::${ACCOUNT_ID}:role/Lab10OtherPrincipalRole" \
  --role-session-name lab10-negative --duration-seconds 900 \
  --query 'Credentials.[AccessKeyId,SecretAccessKey,SessionToken]' --output text)
# env -u AWS_PROFILE: khi profile được đặt tường minh, CLI có thể bỏ qua credentials trong biến môi trường
env -u AWS_PROFILE AWS_ACCESS_KEY_ID="${AK}" AWS_SECRET_ACCESS_KEY="${SK}" AWS_SESSION_TOKEN="${ST}" \
  aws secretsmanager get-secret-value --secret-id "${SECRET_ARN}" --query Name 2>&1 | tail -1
unset AK SK ST
```

*Kỳ vọng:* `An error occurred (AccessDeniedException) when calling the GetSecretValue operation: Access to KMS is not allowed`. Quyền Secrets Manager có, nhưng KMS từ chối giải mã: **mã hóa là một lớp kiểm soát truy cập thứ hai**, không phải thay thế cho IAM.

Nếu `assume-role` báo `AccessDenied`, profile của bạn không có `sts:AssumeRole` trên role này: đó là một lớp kiểm soát khác (người gọi), không phải lỗi KMS.

### 2. Encryption context sai

```bash
CT=$(aws kms encrypt --key-id alias/lab10-order-key --plaintext fileb://<(printf 'du-lieu-gia') \
  --encryption-context order_id=ORD-1001 --query CiphertextBlob --output text)
printf '%s' "${CT}" | base64 --decode > ct.bin

aws kms decrypt --ciphertext-blob fileb://ct.bin --encryption-context order_id=ORD-1001 \
  --query Plaintext --output text | base64 --decode; echo
aws kms decrypt --ciphertext-blob fileb://ct.bin --encryption-context order_id=ORD-9999 2>&1 | tail -1
```

*Kỳ vọng:* lệnh đầu in `du-lieu-gia`; lệnh sau thất bại với `InvalidCiphertextException`. Ciphertext của đơn `ORD-1001` không thể bị "gắn" sang đơn khác. Encryption context xuất hiện **dạng rõ** trong CloudTrail, nên không đặt dữ liệu nhạy cảm vào đó.

---

## Bước 8 — Đổi secret khi app đang cache

```bash
NEW_PW=$(aws secretsmanager get-random-password --password-length 24 --exclude-punctuation --query RandomPassword --output text)
NEW_VER=$(aws secretsmanager put-secret-value --secret-id "${SECRET_ARN}" \
  --secret-string "$(jq -cn --arg p "${NEW_PW}" '{username:"order_app", password:$p}')" --query VersionId --output text)
unset NEW_PW
echo "Version mới: ${NEW_VER:0:8}"

invoke '{"order_id":"ORD-C1"}' >/dev/null
sleep 65
invoke '{"order_id":"ORD-C2"}' >/dev/null
sleep 5
q 'filter order_id in ["ORD-C1","ORD-C2"] | fields order_id, secret_version'
```

*Kỳ vọng:* `ORD-C1` vẫn log version **cũ** (cache chưa hết 60 giây, nếu cùng execution environment còn warm), `ORD-C2` log version mới. Đây chính là triệu chứng "đổi secret mà app dùng giá trị cũ": thời gian lệch tối đa bằng TTL cache. Rotation thật của Secrets Manager giữ version cũ ở nhãn `AWSPREVIOUS` để app chưa làm mới vẫn kết nối được trong khoảng chuyển tiếp.

---

## Bước 9 — CloudTrail: ai đọc secret, ai đổi cấu hình

Event History có độ trễ (thường vài phút, có thể tới 15 phút). Nếu chưa thấy, chờ rồi chạy lại.

```bash
trail() {  # $1 = EventName
  aws cloudtrail lookup-events --lookup-attributes AttributeKey=EventName,AttributeValue="$1" \
    --start-time "${LAB_START}" --max-results 20 --output json |
  jq -r '.Events[].CloudTrailEvent | fromjson
         | [.eventTime, .eventName, (.userIdentity.arn // .userIdentity.invokedBy), (.errorCode // "OK")] | @tsv'
}
trail GetSecretValue
trail PutSecretValue
trail PutParameter
```

*Kỳ vọng (minh họa):*

```text
2026-10-31T14:05:12Z  GetSecretValue  arn:aws:sts::111122223333:assumed-role/Lab10AppRole/lab10-order-api           OK
2026-10-31T14:21:40Z  GetSecretValue  arn:aws:sts::111122223333:assumed-role/Lab10OtherPrincipalRole/lab10-negative  AccessDenied
2026-10-31T14:30:02Z  PutSecretValue  arn:aws:sts::111122223333:assumed-role/<role-của-bạn>/<session>               OK
2026-10-31T14:02:55Z  PutParameter    arn:aws:sts::111122223333:assumed-role/<role-của-bạn>/<session>               OK
```

- Event History chỉ có **management event** 90 ngày gần nhất, theo **Region**. Đọc object S3, gọi Lambda từng lần... là **data event**: không có ở đây, phải tạo trail/event data store và bật riêng (tính phí theo số event).
- Đây là cách trả lời "ai đã đổi cấu hình": lọc theo `EventName` hoặc `ResourceName`, đọc `userIdentity` và `sourceIPAddress`.

(Tùy chọn) Trace của function:

```bash
aws xray get-trace-summaries --start-time $(( $(date +%s) - 1800 )) --end-time $(date +%s) \
  --query 'TraceSummaries[0:3].[Id,Duration,HasError]' --output text
```

---

## Bước 10 — Dọn dẹp

```bash
# 1. Quan sát
aws cloudwatch delete-alarms --alarm-names lab10-order-api-errors
aws cloudwatch delete-dashboards --dashboard-names lab10-order-api
[ -n "${TOPIC_ARN}" ] && aws sns delete-topic --topic-arn "${TOPIC_ARN}"

# 2. Function rồi log group (custom metric tự biến mất sau 15 tháng không có dữ liệu mới, không tính phí khi không gửi)
aws lambda delete-function --function-name "${FN}"
aws logs delete-log-group --log-group-name "/aws/lambda/${FN}"

# 3. IAM
aws iam delete-role-policy --role-name Lab10AppRole --policy-name Lab10AppAccess
for P in service-role/AWSLambdaBasicExecutionRole AWSXRayDaemonWriteAccess; do
  aws iam detach-role-policy --role-name Lab10AppRole --policy-arn "arn:aws:iam::aws:policy/${P}"
done
aws iam delete-role --role-name Lab10AppRole
aws iam delete-role-policy --role-name Lab10OtherPrincipalRole --policy-name ReadSecretNoKms
aws iam delete-role --role-name Lab10OtherPrincipalRole

# 4. Parameter và secret (lab: xóa ngay, không recovery window)
aws ssm delete-parameter --name /lab10/order-api/payment-url
aws secretsmanager delete-secret --secret-id "${SECRET_ARN}" --force-delete-without-recovery >/dev/null

# 5. Key: chỉ lên lịch xóa, sau khi không còn gì phụ thuộc
aws kms delete-alias --alias-name alias/lab10-order-key
aws kms schedule-key-deletion --key-id "${KEY_ID}" --pending-window-in-days 7 \
  --query '[KeyState,DeletionDate]' --output text

# 6. File tạm
cd ~ && rm -rf "${LAB_DIR}"
```

- **Secret:** production nên dùng recovery window (7–30 ngày) để khôi phục nếu xóa nhầm. Trong thời gian đó **không thể** tạo secret mới cùng tên.
- **Key:** KMS không xóa key ngay; tối thiểu 7 ngày, tối đa 30 ngày. Trong thời gian chờ có thể `cancel-key-deletion`. Sau khi xóa, **mọi** dữ liệu mã hóa bằng key đó (kể cả backup, snapshot) không giải mã được nữa. Vì vậy chỉ xóa key bạn chắc chắn sở hữu và không còn dữ liệu nào phụ thuộc. Thay cho xóa, có thể `disable-key` để thử xem có gì hỏng.
- **Retained resource:** key ở trạng thái `PendingDeletion` trong 7 ngày.

### Kiểm tra inventory sau cleanup

```bash
echo "== Key";       aws kms describe-key --key-id "${KEY_ID}" --query KeyMetadata.KeyState --output text
echo "== Alias";     aws kms list-aliases --query "Aliases[?AliasName=='alias/lab10-order-key'].AliasName" --output text
echo "== Secret";    aws secretsmanager list-secrets --filters Key=name,Values=lab10/ --query 'SecretList[].Name' --output text
echo "== Parameter"; aws ssm get-parameter --name /lab10/order-api/payment-url 2>&1 | grep -o ParameterNotFound
echo "== Lambda";    aws lambda get-function --function-name lab10-order-api 2>&1 | grep -o ResourceNotFoundException
echo "== Alarm";     aws cloudwatch describe-alarms --alarm-name-prefix lab10 --query 'MetricAlarms[].AlarmName' --output text
echo "== Dashboard"; aws cloudwatch list-dashboards --dashboard-name-prefix lab10 --query 'DashboardEntries[].DashboardName' --output text
echo "== IAM";       aws iam list-roles --query "Roles[?starts_with(RoleName,'Lab10')].RoleName" --output text
```

*Kỳ vọng:* Key in `PendingDeletion`; parameter in `ParameterNotFound`; Lambda in `ResourceNotFoundException`; các mục còn lại rỗng.

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1" FN="lab10-order-api"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
KEY_ID=$(aws kms list-aliases --query "Aliases[?AliasName=='alias/lab10-order-key'].TargetKeyId | [0]" --output text)
SECRET_ARN=$(aws secretsmanager describe-secret --secret-id lab10/order-api/db --query ARN --output text 2>/dev/null)
TOPIC_ARN=$(aws sns list-topics --query "Topics[?ends_with(TopicArn,':lab10-alerts')].TopicArn | [0]" --output text)
[ "${TOPIC_ARN}" = "None" ] && TOPIC_ARN=""
echo "KEY_ID=${KEY_ID} SECRET_ARN=${SECRET_ARN}"
```

Sau đó chạy lại Bước 10. Nếu alias đã bị xóa mà key chưa schedule, tìm key theo tag: `aws kms list-keys` rồi `aws kms list-resource-tags --key-id <id>` để tìm `Project=aws-lab-10`. Lệnh báo `NotFound`/`NoSuchEntity`/`ResourceNotFoundException` nghĩa là đã xóa.

---

## Checklist hoàn thành

- [ ] Giải thích được statement `EnableIAMPoliciesInThisAccount` trong key policy làm gì và không làm gì.
- [ ] Secret được tạo từ mật khẩu ngẫu nhiên, không xuất hiện trong terminal, log hay file.
- [ ] Role app chỉ đọc đúng một secret, một parameter, decrypt qua Secrets Manager.
- [ ] Logs Insights trả về p95 latency, số lỗi và hành trình của `demo-cid-001`; query `password` trả 0.
- [ ] Alarm chuyển `ALARM` khi có ≥ 3 lỗi/phút; dashboard có hai biểu đồ.
- [ ] Principal khác nhận `Access to KMS is not allowed`; decrypt sai encryption context nhận `InvalidCiphertextException`.
- [ ] Quan sát app dùng version secret cũ trong thời gian TTL cache.
- [ ] CloudTrail chỉ ra ai đọc secret (kể cả lần bị từ chối) và ai đổi parameter.
- [ ] Cleanup xong; key ở `PendingDeletion`, inventory còn lại rỗng.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| Không thấy log của function | Role thiếu `AWSLambdaBasicExecutionRole`, sai Region, hoặc log group bị xóa trong lúc function chạy | `aws iam list-attached-role-policies --role-name Lab10AppRole`; kiểm tra `AWS_REGION` |
| Không thấy metric `Lab10/OrderApi` | Dòng EMF sai cấu trúc (phải là JSON một dòng có `_aws`) | `q 'filter ispresent(_aws) \| limit 3'`; metric mới có thể mất 1–2 phút |
| Alarm không đổi trạng thái | Sai statistic/period/dimension, hoặc lỗi rơi vào phút trước | `aws cloudwatch get-metric-statistics --namespace Lab10/OrderApi --metric-name Errors --dimensions Name=Service,Value=order-api --statistics Sum --period 60 --start-time ... --end-time ...` |
| `AccessDeniedException ... Access to KMS is not allowed` với role app | Thiếu `kms:Decrypt` hoặc sai `kms:ViaService` | So `Resource` với `KEY_ARN`; Region trong `ViaService` phải khớp |
| `AccessDeniedException` với `kms:Decrypt` dù IAM cho phép | Key policy không tin IAM của account (thiếu root statement) và không cấp trực tiếp | `aws kms get-key-policy --key-id ${KEY_ID} --policy-name default` |
| `InvalidCiphertextException` | Encryption context khi decrypt khác lúc encrypt, hoặc ciphertext bị hỏng (base64 sai) | So từng cặp key-value; kiểm tra bước `base64 --decode` |
| App vẫn dùng secret cũ | Cache trong app chưa hết TTL | Xem `secret_version` trong log; giảm TTL hoặc làm mới khi nhận lỗi xác thực |
| `create-secret` báo secret cùng tên đang chờ xóa | Lần chạy trước xóa có recovery window | `aws secretsmanager restore-secret` hoặc xóa lại với `--force-delete-without-recovery` |
| CloudTrail không có event | Event History trễ, sai Region, hoặc là data event | Chờ 5–15 phút; Event History theo Region; data event cần trail riêng |

---

## Tiếp theo

Bạn đã quan sát được hành vi của app bằng log, metric và alarm, và chứng minh secret được bảo vệ bởi cả IAM lẫn KMS. Bài tiếp theo: **[Bài 11 — Messaging và workflow: SQS, SNS, EventBridge, Step Functions](../../notes/11-messaging-and-workflows.md)**.
