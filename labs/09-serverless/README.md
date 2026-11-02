# Lab 09 — Serverless: Lambda sau API Gateway HTTP API, lỗi có kiểm soát và giới hạn concurrency

> **Lý thuyết:** [notes/09-serverless.md](../../notes/09-serverless.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 60–90 phút (thêm 20 phút nếu làm nhánh S3 tùy chọn).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Lambda Python 3.12 arm64, log group tạo trước với retention 1 ngày | Ghi toàn bộ `event` (header, token) vào log |
| HTTP API, payload format 2.0, route `POST /orders`, `GET /orders/{orderId}` | REST API, custom domain |
| Kiểm thử 200/201/400/404, invalid JSON | Dữ liệu thật hoặc nghiệp vụ nhạy cảm |
| Negative case: exception, timeout, response format 2.0 được "đoán" | |
| Throttling ở stage và reserved concurrency để giới hạn chi phí | Load test |
| Tùy chọn: S3 trigger với prefix tách biệt để tránh vòng lặp | Ghi output vào cùng prefix trigger |
| Cleanup và kiểm tra inventory | |

> [!WARNING]
> API trong lab **không có authentication**: ai biết URL đều gọi được. Lab chỉ chứa dữ liệu giả, được giới hạn bằng throttling và reserved concurrency, và phải xóa ngay sau buổi học. API thật cần JWT authorizer, IAM auth hoặc Lambda authorizer.

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `ACCOUNT_ID` | Account ID (lấy tự động) | `111122223333` |
| `API_ID`, `API_ENDPOINT` | HTTP API và URL stage `$default` | `a1b2c3d4e5`, `https://a1b2c3d4e5.execute-api.ap-southeast-1.amazonaws.com` |
| `FN` | Tên function | `lab09-order-api` |

Tên cố định: `lab09-*` cho function/API/bucket, `Lab09LambdaRole` cho IAM. Tài nguyên hỗ trợ tag mang `Project=aws-lab-09`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 02](../02-identity-and-access/README.md) (IAM role, resource-based policy).
- [ ] Công cụ: AWS CLI v2, `zip`, `curl`, `jq`.
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **IAM:** `iam:CreateRole`, `AttachRolePolicy`, `DetachRolePolicy`, `DeleteRole`, `GetRole`, `TagRole`, **`iam:PassRole` trên `Lab09LambdaRole`** (bắt buộc khi tạo function).
  - **Lambda:** `lambda:CreateFunction`, `GetFunction`, `GetFunctionConfiguration`, `UpdateFunctionCode`, `UpdateFunctionConfiguration`, `AddPermission`, `RemovePermission`, `PutFunctionConcurrency`, `DeleteFunctionConcurrency`, `GetAccountSettings`, `DeleteFunction`, `TagResource`.
  - **API Gateway:** `apigateway:POST`, `PATCH`, `GET`, `DELETE` trên `arn:aws:apigateway:<region>::/apis*`.
  - **CloudWatch Logs:** `logs:CreateLogGroup`, `PutRetentionPolicy`, `FilterLogEvents`, `DeleteLogGroup`.
  - **Nhánh S3:** `s3:CreateBucket`, `PutBucketNotification`, `PutObject`, `ListBucket`, `DeleteObject`, `DeleteBucket`.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại [Lambda pricing](https://aws.amazon.com/lambda/pricing/) và [API Gateway pricing](https://aws.amazon.com/api-gateway/pricing/))

| Tài nguyên | Quy mô trong lab | Đơn giá minh họa | Chi phí |
|---|---|---|---|
| Lambda request | < 200 | ~$0.20/triệu request | < $0.001 |
| Lambda compute arm64 128 MB | < 200 × tối đa 3 giây | ~$0.0000133/GB-giây | < $0.001 |
| HTTP API request | < 200 | ~$1.00/triệu request | < $0.001 |
| CloudWatch Logs | vài KB | ~$0.50–0.70/GB ingest | < $0.001 |
| **Tổng** | | | **< $0.01** |

- Không có phí theo giờ. Rủi ro chi phí thật là API public bị gọi hàng loạt: throttling (Bước 5) giới hạn tối đa khoảng 2 request/giây.
- Không dựa vào Free Tier; chi phí trên đã tính như không có Free Tier.

---

## Sơ đồ kiến trúc Lab 09

```text
curl ──HTTPS──▶ API Gateway HTTP API lab09-order-api ($default, auto-deploy, throttle 2 rps / burst 5)
                  │ route POST /orders, GET /orders/{orderId}
                  │ integration AWS_PROXY, payload format 2.0, timeout 30 s
                  ▼ (resource-based policy: chỉ API này được invoke)
               Lambda lab09-order-api (python3.12, arm64, 128 MB, timeout 3 s, reserved concurrency 5)
                  │ role Lab09LambdaRole: chỉ ghi log
                  ▼
               CloudWatch Logs /aws/lambda/lab09-order-api (retention 1 ngày)

Tùy chọn: S3 lab09-uploads-<ACCOUNT_ID> ──ObjectCreated, prefix uploads/──▶ Lambda (cùng function)
```

---

## Bước 0 — Biến và thư mục làm việc

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export FN="lab09-order-api"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
aws lambda get-account-settings --query 'AccountLimit.[ConcurrentExecutions,UnreservedConcurrentExecutions]' --output text
```

*Kỳ vọng:* hai số, ví dụ `1000  1000`. Account mới có thể có quota thấp hơn (ví dụ `10`); khi đó bỏ qua phần reserved concurrency ở Bước 5 vì Lambda luôn giữ lại một phần unreserved tối thiểu.

---

## Bước 1 — Mã nguồn function

```bash
cat > lambda_function.py << 'EOF'
import json
import os
import time

# Code ngoài handler chạy một lần trong INIT, tái sử dụng qua các lần invoke warm
ORDERS = {
    "ORD-1001": {"order_id": "ORD-1001", "customer": "Khách Mẫu A", "total": 1500000, "status": "COMPLETED"},
    "ORD-1002": {"order_id": "ORD-1002", "customer": "Khách Mẫu B", "total": 450000, "status": "PENDING"},
}
COLD = True


def lambda_handler(event, context):
    global COLD
    if "Records" in event:                      # nhánh S3 tùy chọn
        return handle_s3(event)

    http = event.get("requestContext", {}).get("http", {})
    route = event.get("routeKey", "")
    # Chỉ log trường cần thiết: không log header (có thể chứa Authorization) hay body
    print(json.dumps({"route": route, "requestId": context.aws_request_id, "cold": COLD,
                      "sourceIp": http.get("sourceIp")}))
    COLD = False

    fault = os.environ.get("FAULT", "")
    if fault == "exception":
        raise RuntimeError("lỗi giả lập: bug chưa xử lý")
    if fault == "timeout":
        time.sleep(5)                            # dài hơn timeout 3 giây của function
    if fault == "raw":
        return {"note": "không có statusCode, API Gateway tự suy ra response"}

    if route == "POST /orders":
        try:
            body = json.loads(event.get("body") or "{}")
        except json.JSONDecodeError:
            return response(400, {"error": "Body không phải JSON hợp lệ"})
        if not isinstance(body.get("customer"), str) or not isinstance(body.get("total"), int) or body["total"] <= 0:
            return response(400, {"error": "Cần 'customer' (chuỗi) và 'total' (số nguyên dương)"})
        order_id = f"ORD-{context.aws_request_id[:8]}"
        order = {"order_id": order_id, "customer": body["customer"], "total": body["total"], "status": "CREATED"}
        ORDERS[order_id] = order                 # chỉ sống trong execution environment này
        return response(201, order)

    if route == "GET /orders/{orderId}":
        order_id = (event.get("pathParameters") or {}).get("orderId")
        if order_id in ORDERS:
            return response(200, ORDERS[order_id])
        return response(404, {"error": f"Không tìm thấy đơn '{order_id}'"})

    return response(404, {"error": f"Route '{route}' không tồn tại"})


def handle_s3(event):
    for r in event["Records"]:
        key = r["s3"]["object"]["key"]
        print(json.dumps({"s3_key": key, "size": r["s3"]["object"].get("size")}))
    return {"processed": len(event["Records"])}


def response(status, data):
    return {
        "statusCode": status,
        "headers": {"Content-Type": "application/json; charset=utf-8"},
        "body": json.dumps(data, ensure_ascii=False),
    }
EOF
zip -q function.zip lambda_function.py
```

- `ORDERS` là "database giả" trong bộ nhớ: đơn tạo bằng `POST` chỉ tồn tại trong **một** execution environment và mất khi environment bị thu hồi. Đây là cách minh họa vì sao Lambda phải stateless; bài 07 đã có DynamoDB cho dữ liệu thật.
- Biến `FAULT` cho phép tạo lỗi có kiểm soát bằng cấu hình, không cần deploy code hỏng.

---

## Bước 2 — Execution role và log group

```bash
cat > trust.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"lambda.amazonaws.com"},
 "Action":"sts:AssumeRole","Condition":{"StringEquals":{"aws:SourceAccount":"${ACCOUNT_ID}"}}}]}
EOF
aws iam create-role --role-name Lab09LambdaRole --assume-role-policy-document file://trust.json \
  --tags Key=Project,Value=aws-lab-09 >/dev/null
aws iam attach-role-policy --role-name Lab09LambdaRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole
ROLE_ARN=$(aws iam get-role --role-name Lab09LambdaRole --query Role.Arn --output text)

# Tạo log group trước để đặt retention; nếu để Lambda tự tạo, log giữ vĩnh viễn
aws logs create-log-group --log-group-name "/aws/lambda/${FN}" --tags Project=aws-lab-09
aws logs put-retention-policy --log-group-name "/aws/lambda/${FN}" --retention-in-days 1
sleep 10   # IAM eventually consistent: role mới có thể chưa assume được ngay
```

`AWSLambdaBasicExecutionRole` chỉ cho `logs:CreateLogGroup`, `CreateLogStream`, `PutLogEvents`. Function không gọi dịch vụ AWS nào khác nên không cần thêm quyền.

---

## Bước 3 — Tạo function

```bash
FN_ARN=$(aws lambda create-function --function-name "${FN}" \
  --runtime python3.12 --architectures arm64 \
  --role "${ROLE_ARN}" --handler lambda_function.lambda_handler \
  --zip-file fileb://function.zip --memory-size 128 --timeout 3 \
  --tags Project=aws-lab-09 \
  --query FunctionArn --output text)
aws lambda wait function-active-v2 --function-name "${FN}"

aws lambda invoke --function-name "${FN}" --cli-binary-format raw-in-base64-out \
  --payload '{"routeKey":"GET /orders/{orderId}","pathParameters":{"orderId":"ORD-1001"},"requestContext":{"http":{}}}' \
  out.json --query StatusCode && cat out.json; echo
```

*Kỳ vọng:* `200` và `{"statusCode": 200, ... "body": "{\"order_id\": \"ORD-1001\", ...}"}`. Gọi trực tiếp bằng CLI là **synchronous invoke**: nếu function lỗi, không ai retry thay bạn.

Nếu `create-function` báo `The role defined for the function cannot be assumed by Lambda`, IAM chưa propagate: chờ 10–20 giây rồi chạy lại.

---

## Bước 4 — HTTP API, route, stage và quyền invoke

```bash
API_ID=$(aws apigatewayv2 create-api --name "${FN}" --protocol-type HTTP \
  --tags Project=aws-lab-09 --query ApiId --output text)

INTEGRATION_ID=$(aws apigatewayv2 create-integration --api-id "${API_ID}" \
  --integration-type AWS_PROXY --integration-uri "${FN_ARN}" \
  --payload-format-version 2.0 --timeout-in-millis 30000 \
  --query IntegrationId --output text)

for RK in "POST /orders" "GET /orders/{orderId}"; do
  aws apigatewayv2 create-route --api-id "${API_ID}" --route-key "${RK}" \
    --target "integrations/${INTEGRATION_ID}" >/dev/null
done

# Lambda resource-based policy: chỉ API này (mọi stage/method/route) được invoke
aws lambda add-permission --function-name "${FN}" --statement-id AllowHttpApiInvoke \
  --action lambda:InvokeFunction --principal apigateway.amazonaws.com \
  --source-arn "arn:aws:execute-api:${AWS_REGION}:${ACCOUNT_ID}:${API_ID}/*" >/dev/null

API_ENDPOINT=$(aws apigatewayv2 get-api --api-id "${API_ID}" --query ApiEndpoint --output text)
echo "API_ENDPOINT=${API_ENDPOINT}"
```

`AuthorizationType` của route mặc định là `NONE` (public). Đây là lý do có cảnh báo ở đầu lab.

---

## Bước 5 — Giới hạn throttling và concurrency

```bash
aws apigatewayv2 create-stage --api-id "${API_ID}" --stage-name '$default' --auto-deploy \
  --default-route-settings ThrottlingBurstLimit=5,ThrottlingRateLimit=2 >/dev/null

# Bỏ qua lệnh này nếu Bước 0 cho thấy quota account thấp (ví dụ 10)
aws lambda put-function-concurrency --function-name "${FN}" --reserved-concurrent-executions 5
```

- Throttling ở stage chặn request **trước khi** tới Lambda (`429 Too Many Requests`), nên không tốn tiền Lambda.
- Reserved concurrency 5 vừa **giữ chỗ** vừa là **trần cứng**: tối đa 5 environment chạy đồng thời. Request vượt trần bị Lambda throttle.

---

## Bước 6 — Happy path và invalid input

```bash
call() { curl -s -o resp.json -w "HTTP %{http_code}  " "$@"; cat resp.json; echo; }

call "${API_ENDPOINT}/orders/ORD-1001"
call -X POST "${API_ENDPOINT}/orders" -H 'Content-Type: application/json' -d '{"customer":"Khách Mẫu C","total":2800000}'
call -X POST "${API_ENDPOINT}/orders" -H 'Content-Type: application/json' -d '{"customer":"Thiếu total"}'
call -X POST "${API_ENDPOINT}/orders" -H 'Content-Type: application/json' -d '{"customer": "JSON hỏng",'
call "${API_ENDPOINT}/orders/ORD-9999"
call -X DELETE "${API_ENDPOINT}/orders/ORD-1001"
```

*Kỳ vọng (minh họa):*

```text
HTTP 200  {"order_id": "ORD-1001", "customer": "Khách Mẫu A", "total": 1500000, "status": "COMPLETED"}
HTTP 201  {"order_id": "ORD-3f2a9c1e", "customer": "Khách Mẫu C", "total": 2800000, "status": "CREATED"}
HTTP 400  {"error": "Cần 'customer' (chuỗi) và 'total' (số nguyên dương)"}
HTTP 400  {"error": "Body không phải JSON hợp lệ"}
HTTP 404  {"error": "Không tìm thấy đơn 'ORD-9999'"}
HTTP 404  {"message":"Not Found"}
```

Dòng cuối do **API Gateway** trả (không có route `DELETE`), Lambda không hề được gọi: so sánh dạng body với các 404 do function trả.

Kiểm tra log không chứa header hay body:

```bash
aws logs filter-log-events --log-group-name "/aws/lambda/${FN}" --filter-pattern '"route"' \
  --query 'events[-3:].message' --output text
```

*Kỳ vọng:* mỗi dòng chỉ có `route`, `requestId`, `cold`, `sourceIp`. Dòng đầu tiên có `"cold": true`, các dòng sau `false` (cùng environment được tái sử dụng).

---

## Bước 7 — Negative case: exception, timeout, response format

Dùng biến môi trường `FAULT` để bật từng lỗi, rồi gọi lại:

```bash
fault() {
  aws lambda update-function-configuration --function-name "${FN}" \
    --environment "Variables={FAULT=$1}" >/dev/null
  aws lambda wait function-updated-v2 --function-name "${FN}"
  call "${API_ENDPOINT}/orders/ORD-1001"
}
fault exception
fault timeout
fault raw
fault none
```

*Kỳ vọng (minh họa):*

```text
HTTP 500  {"message":"Internal Server Error"}
HTTP 500  {"message":"Internal Server Error"}
HTTP 200  {"note": "không có statusCode, API Gateway tự suy ra response"}
HTTP 200  {"order_id": "ORD-1001", ...}
```

- **exception / timeout:** HTTP API trả `500` chung chung cho client; nguyên nhân thật chỉ có trong log. (REST API với Lambda proxy trả `502` cho cùng loại lỗi, nên đừng học thuộc "lỗi Lambda = 502".)
- **raw:** với payload format **2.0**, nếu function trả JSON hợp lệ mà **không có** `statusCode`, API Gateway coi cả object là body và trả `200`. Lỗi thiết kế response vì vậy có thể **không** lộ ra thành mã lỗi; với format 1.0 hoặc REST API, response sai định dạng bị coi là lỗi.

Đọc nguyên nhân:

```bash
aws logs filter-log-events --log-group-name "/aws/lambda/${FN}" \
  --filter-pattern '?"Task timed out" ?RuntimeError ?ERROR' \
  --query 'events[].message' --output text | head -20
```

*Kỳ vọng:* thấy `RuntimeError: lỗi giả lập: bug chưa xử lý` kèm traceback, và `Task timed out after 3.00 seconds`. Timeout của function (3 giây) nhỏ hơn timeout integration (30 giây), nên Lambda dừng trước và API Gateway nhận lỗi function.

### Throttling

```bash
for i in $(seq 1 20); do curl -s -o /dev/null -w '%{http_code} ' "${API_ENDPOINT}/orders/ORD-1001" & done; wait; echo
```

*Kỳ vọng:* trộn `200` và `429`. Số `429` phụ thuộc thời điểm, nhưng với burst 5 và rate 2/giây, 20 request đồng thời phải có `429`.

---

## Bước 8 (tùy chọn) — S3 trigger với prefix tách biệt

Đây là **asynchronous invoke**: S3 gửi event vào hàng đợi nội bộ của Lambda; nếu function lỗi, Lambda tự retry tối đa 2 lần.

```bash
BUCKET="lab09-uploads-${ACCOUNT_ID}"
if [ "${AWS_REGION}" = "us-east-1" ]; then aws s3api create-bucket --bucket "${BUCKET}" >/dev/null
else aws s3api create-bucket --bucket "${BUCKET}" --create-bucket-configuration LocationConstraint="${AWS_REGION}" >/dev/null; fi

aws lambda add-permission --function-name "${FN}" --statement-id AllowS3Invoke \
  --action lambda:InvokeFunction --principal s3.amazonaws.com \
  --source-arn "arn:aws:s3:::${BUCKET}" --source-account "${ACCOUNT_ID}" >/dev/null

cat > notif.json << EOF
{"LambdaFunctionConfigurations":[{"LambdaFunctionArn":"${FN_ARN}","Events":["s3:ObjectCreated:*"],
  "Filter":{"Key":{"FilterRules":[{"Name":"prefix","Value":"uploads/"}]}}}]}
EOF
aws s3api put-bucket-notification-configuration --bucket "${BUCKET}" --notification-configuration file://notif.json

echo "đơn hàng giả" > a.txt
aws s3 cp a.txt "s3://${BUCKET}/uploads/a.txt"
aws s3 cp a.txt "s3://${BUCKET}/processed/a.txt"
sleep 15
aws logs filter-log-events --log-group-name "/aws/lambda/${FN}" --filter-pattern '"s3_key"' \
  --query 'events[].message' --output text
```

*Kỳ vọng:* chỉ có một dòng `{"s3_key": "uploads/a.txt", ...}`; file trong `processed/` không kích hoạt function. Nếu function ghi output vào `uploads/`, mỗi lần chạy sẽ tạo event mới và thành vòng lặp; Lambda có cơ chế phát hiện vòng lặp đệ quy cho một số nguồn (SQS, SNS, S3) nhưng đó là lưới an toàn, không phải thiết kế.

---

## Bước 9 — Dọn dẹp

```bash
# 1. Nhánh S3 (nếu đã làm)
BUCKET="lab09-uploads-${ACCOUNT_ID}"
aws s3 rm "s3://${BUCKET}" --recursive 2>/dev/null && aws s3api delete-bucket --bucket "${BUCKET}"

# 2. API (xóa cả route, integration, stage)
aws apigatewayv2 delete-api --api-id "${API_ID}"

# 3. Function (xóa luôn permission và reserved concurrency), log group
aws lambda delete-function --function-name "${FN}"
aws logs delete-log-group --log-group-name "/aws/lambda/${FN}"

# 4. IAM
aws iam detach-role-policy --role-name Lab09LambdaRole \
  --policy-arn arn:aws:iam::aws:policy/service-role/AWSLambdaBasicExecutionRole
aws iam delete-role --role-name Lab09LambdaRole

# 5. File tạm
cd ~ && rm -rf "${LAB_DIR}"
```

Xóa log group **sau** function: nếu xóa trước mà function còn được gọi, Lambda sẽ tự tạo lại log group không có retention.

### Kiểm tra inventory sau cleanup

```bash
echo "== Lambda";    aws lambda get-function --function-name lab09-order-api 2>&1 | grep -o ResourceNotFoundException
echo "== API";       aws apigatewayv2 get-apis --query "Items[?Name=='lab09-order-api'].ApiId" --output text
echo "== Log group"; aws logs describe-log-groups --log-group-name-prefix /aws/lambda/lab09 --query 'logGroups[].logGroupName' --output text
echo "== Bucket";    aws s3api head-bucket --bucket "lab09-uploads-${ACCOUNT_ID}" 2>&1 | grep -oE '404|Not Found'
echo "== IAM";       aws iam get-role --role-name Lab09LambdaRole 2>&1 | grep -o NoSuchEntity
```

*Kỳ vọng:* Lambda in `ResourceNotFoundException`, bucket in `404`/`Not Found`, IAM in `NoSuchEntity`, API và log group rỗng.

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1" FN="lab09-order-api"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
API_ID=$(aws apigatewayv2 get-apis --query "Items[?Name=='lab09-order-api'].ApiId | [0]" --output text)
echo "API_ID=${API_ID}"
```

Sau đó chạy lại Bước 9. Lệnh nào báo `NotFoundException`, `ResourceNotFoundException`, `NoSuchEntity`, `NoSuchBucket` nghĩa là tài nguyên đó đã được xóa. Nếu `API_ID` là `None`, bỏ qua lệnh xóa API.

---

## Checklist hoàn thành

- [ ] Log group có retention 1 ngày, được tạo trước function.
- [ ] Function arm64 chạy được qua `aws lambda invoke` và qua HTTP API.
- [ ] Phân biệt được 404 do API Gateway trả (không có route) và 404 do function trả.
- [ ] Invalid input (thiếu trường, JSON hỏng) trả `400`, không làm function lỗi.
- [ ] Thấy `500` khi function ném exception và khi timeout; đọc được nguyên nhân trong log.
- [ ] Giải thích được vì sao response thiếu `statusCode` vẫn trả `200` với payload format 2.0.
- [ ] Thấy `429` khi vượt throttling; biết reserved concurrency là trần cứng.
- [ ] Log không chứa header hay body.
- [ ] (Tùy chọn) S3 trigger chỉ chạy với prefix `uploads/`.
- [ ] Cleanup xong; inventory rỗng.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| `{"message":"Not Found"}` | Route không khớp method/path, hoặc stage chưa tạo | `aws apigatewayv2 get-routes --api-id ${API_ID} --query 'Items[].RouteKey'`; kiểm tra stage `$default` |
| `500 Internal Server Error`, log Lambda không có dòng nào | API Gateway không được phép invoke function | `aws lambda get-policy --function-name ${FN}`; `source-arn` phải chứa đúng `API_ID` |
| `500 Internal Server Error`, log có traceback | Function ném exception | Đọc traceback; xử lý input không hợp lệ thành `400` |
| `500`, log có `Task timed out after 3.00 seconds` | Function chạy lâu hơn timeout của nó | Tìm phần chậm; tăng `--timeout` có chủ đích; timeout function phải nhỏ hơn timeout integration (30 giây với HTTP API) |
| `503 Service Unavailable` | Integration chạy quá timeout của API Gateway | Giảm thời gian xử lý hoặc chuyển sang xử lý bất đồng bộ |
| `429 Too Many Requests` | Vượt throttling của stage | Có chủ đích trong lab; production cần client retry với backoff |
| Lỗi 5xx tăng khi tải cao, log không có lỗi code | Lambda bị throttle do chạm reserved concurrency | Metric `Throttles` của function trong CloudWatch; tăng trần có chủ đích hoặc giảm throttling ở stage cho khớp |
| `create-function` báo role cannot be assumed | IAM chưa propagate, hoặc trust policy sai principal | Chờ 10–20 giây; trust policy phải là `lambda.amazonaws.com` |
| `put-function-concurrency` báo vượt unreserved tối thiểu | Quota concurrency của account thấp | Bỏ qua bước này, dựa vào throttling của stage |
| S3 không kích hoạt function | Thiếu `lambda:AddPermission` cho `s3.amazonaws.com`, sai prefix | `aws s3api get-bucket-notification-configuration --bucket ...`; `aws lambda get-policy` |

---

## Tiếp theo

Bạn đã chạy một API serverless có giới hạn chi phí và đọc được nguyên nhân lỗi từ log. Bài tiếp theo: **[Bài 10 — Quan sát và bảo mật ứng dụng: CloudWatch, CloudTrail, Secrets Manager, Parameter Store, KMS](../../notes/10-observability-and-application-security.md)**.

---

## Nhánh Laravel theo lộ trình gốc

Lab dịch vụ ở trên được nối với [dự án Laravel + Vue](../15-capstone-laravel/README.md). Đọc phần bổ sung trong bài lý thuyết 09 và bảng mốc của dự án để chọn đúng prerequisite. Đầu ra bắt buộc gồm code/fixture, happy path, negative case và inventory sau cleanup; không dùng output minh họa làm bằng chứng đã chạy.

Làm thêm [mini-lab ảnh và SQS partial batch](../../examples/image-processor/README.md): có code resize thật, template, expected result và cleanup.
