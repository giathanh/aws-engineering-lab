# Lab 11 — Messaging và workflow: SQS worker idempotent, DLQ/redrive, EventBridge filter, Step Functions Retry/Catch

> **Lý thuyết:** [notes/11-messaging-and-workflows.md](../../notes/11-messaging-and-workflows.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 90–120 phút (phần poison message có nhiều lần chờ visibility timeout).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| **Lab chính:** producer (CLI) → SQS → worker Python; side effect ghi DynamoDB với idempotency key | Hứa "exactly-once" chỉ vì dùng FIFO |
| Message trùng; worker chết **sau** side effect, **trước** delete; chứng minh không tạo đơn trùng | |
| Poison message → DLQ; sửa worker → redrive có kiểm soát | Redrive trước khi sửa nguyên nhân |
| **Mini-lab 1:** EventBridge custom bus, rule lọc theo nội dung, target SQS có queue policy | SNS fan-out (chỉ phân tích trong lý thuyết) |
| **Mini-lab 2:** Step Functions Standard: `Retry` cho lỗi tạm thời, `Catch` → bồi hoàn, task gửi SQS | Gọi cổng thanh toán thật, Lambda |
| Cleanup: tắt producer/rule trước, kiểm tra in-flight, rồi xóa | Email/HTTP endpoint bên ngoài |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `ACCOUNT_ID` | Account ID (lấy tự động) | `111122223333` |
| `QUEUE_URL`, `DLQ_URL` | Queue chính và DLQ | `https://sqs.ap-southeast-1.amazonaws.com/111122223333/lab11-orders` |
| `HV_URL`, `SHIP_URL` | Queue nhận event giá trị cao, queue giao hàng | `.../lab11-high-value`, `.../lab11-shipping` |
| `SFN_ARN` | State machine | `arn:aws:states:...:stateMachine:lab11-order-saga` |

Tên cố định: `lab11-*`. Tài nguyên hỗ trợ tag mang `Project=aws-lab-11`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 07](../07-databases-and-cache/README.md) (DynamoDB conditional write) và [Lab 09](../09-serverless/README.md).
- [ ] Công cụ: AWS CLI v2, `jq`, Python 3 (tạo virtualenv cài `boto3`).
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **SQS:** `sqs:CreateQueue`, `GetQueueUrl`, `GetQueueAttributes`, `SetQueueAttributes`, `SendMessage`, `ReceiveMessage`, `DeleteMessage`, `StartMessageMoveTask`, `ListMessageMoveTasks`, `DeleteQueue`, `TagQueue` trên `arn:aws:sqs:*:*:lab11-*`.
  - **DynamoDB:** `dynamodb:CreateTable`, `DescribeTable`, `PutItem`, `Scan` (chỉ để đếm trong lab), `DeleteTable`, `TagResource` trên `table/lab11-*`.
  - **EventBridge:** `events:CreateEventBus`, `PutRule`, `PutTargets`, `TestEventPattern`, `PutEvents`, `DisableRule`, `RemoveTargets`, `DeleteRule`, `DeleteEventBus`, `TagResource`.
  - **Step Functions:** `states:CreateStateMachine`, `StartExecution`, `DescribeExecution`, `GetExecutionHistory`, `DeleteStateMachine`, `TagResource`.
  - **IAM:** `iam:CreateRole`, `PutRolePolicy`, `DeleteRolePolicy`, `DeleteRole`, `TagRole`, **`iam:PassRole` trên `lab11-sfn-role`**.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại trang giá [SQS](https://aws.amazon.com/sqs/pricing/), [EventBridge](https://aws.amazon.com/eventbridge/pricing/), [Step Functions](https://aws.amazon.com/step-functions/pricing/))

| Tài nguyên | Quy mô trong lab | Đơn giá minh họa | Chi phí |
|---|---|---|---|
| SQS request (kể cả receive rỗng) | < 500 | ~$0.40/triệu request | < $0.001 |
| DynamoDB on-demand | < 50 request | ~$1.25/triệu write | < $0.001 |
| EventBridge custom event | < 10 | ~$1.00/triệu event | < $0.001 |
| Step Functions Standard | 3 execution × ~10 transition | ~$0.025/1.000 transition | < $0.001 |
| **Tổng** | | | **< $0.01** |

- Không có phí theo giờ. Queue, bus, state machine để không cũng gần như không tốn tiền, nhưng vẫn phải xóa để không để lại fixture và quyền thừa.

---

## Sơ đồ kiến trúc Lab 11

```text
Lab chính
 producer (aws sqs send-message, kèm IdempotencyKey)
      │
      ▼
 SQS lab11-orders (visibility 20 s, long poll 10 s) ──maxReceiveCount 3──▶ SQS lab11-orders-dlq (14 ngày)
      │  receive / delete                                                      │
      ▼                                                                        │ start-message-move-task
 worker.py ──PutItem attribute_not_exists(idempotency_key)──▶ DynamoDB         ▼
 (normal | crash-after-write | v2)                            lab11-orders    quay về lab11-orders

Mini-lab 1
 aws events put-events ──▶ bus lab11-bus ──rule: OrderPlaced & amount ≥ 1.000.000──▶ SQS lab11-high-value
                                                                       (queue policy: chỉ rule này gửi)
Mini-lab 2
 start-execution ──▶ Step Functions lab11-order-saga (Standard)
   ReserveInventory → ChargePayment (Retry PaymentGatewayTimeout ×2; Catch → ReleaseInventory → Fail)
                    → RequestShipping (sqs:sendMessage → lab11-shipping) → Succeed
```

---

## Bước 0 — Biến, virtualenv và worker

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
python3 -m venv .venv && . .venv/bin/activate && pip install --quiet boto3

cat > worker.py << 'EOF'
"""Worker SQS một vòng: nhận tối đa 10 message, xử lý idempotent, delete khi xong."""
import json, os, sys, time
import boto3

sqs, ddb = boto3.client("sqs"), boto3.client("dynamodb")
QUEUE_URL, TABLE = os.environ["QUEUE_URL"], "lab11-orders"
MODE = sys.argv[1] if len(sys.argv) > 1 else "normal"   # normal | crash-after-write | v2


def parse(body):
    data = json.loads(body)
    order_id = data.get("order_id")
    if order_id is None and MODE == "v2":
        order_id = data.get("orderId")          # bản sửa: chấp nhận schema cũ của producer legacy
    if not order_id:
        raise ValueError("thiếu order_id")
    return order_id, data


resp = sqs.receive_message(QueueUrl=QUEUE_URL, MaxNumberOfMessages=10, WaitTimeSeconds=2,
                           MessageAttributeNames=["All"],
                           MessageSystemAttributeNames=["ApproximateReceiveCount"])
messages = resp.get("Messages", [])
if not messages:
    print("(không có message)")
for m in messages:
    rc = m["Attributes"]["ApproximateReceiveCount"]
    try:
        order_id, data = parse(m["Body"])
        key = m.get("MessageAttributes", {}).get("IdempotencyKey", {}).get("StringValue") or f"order:{order_id}"
        try:
            # Side effect và chống trùng là MỘT lệnh ghi có điều kiện
            ddb.put_item(TableName=TABLE, ConditionExpression="attribute_not_exists(idempotency_key)", Item={
                "idempotency_key": {"S": key}, "order_id": {"S": order_id},
                "amount": {"N": str(data.get("amount", 0))},
                "processed_at": {"S": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())}})
            print(f"CREATED   {order_id} key={key} receive#{rc}")
        except ddb.exceptions.ConditionalCheckFailedException:
            print(f"DUPLICATE {order_id} key={key} receive#{rc} -> không tạo lại đơn")
        if MODE == "crash-after-write":
            print("CRASH     sau khi ghi DB, trước khi delete")
            sys.exit(1)
        sqs.delete_message(QueueUrl=QUEUE_URL, ReceiptHandle=m["ReceiptHandle"])
    except (ValueError, json.JSONDecodeError) as e:
        print(f"FAILED    receive#{rc} lỗi='{e}' -> không delete, message sẽ quay lại")
EOF
```

---

## Bước 1 — Bảng đơn hàng, DLQ và queue chính

```bash
aws dynamodb create-table --table-name lab11-orders \
  --attribute-definitions AttributeName=idempotency_key,AttributeType=S \
  --key-schema AttributeName=idempotency_key,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST --tags Key=Project,Value=aws-lab-11 >/dev/null

DLQ_URL=$(aws sqs create-queue --queue-name lab11-orders-dlq \
  --attributes MessageRetentionPeriod=1209600 --tags Project=aws-lab-11 --query QueueUrl --output text)
DLQ_ARN=$(aws sqs get-queue-attributes --queue-url "${DLQ_URL}" --attribute-names QueueArn \
  --query Attributes.QueueArn --output text)

# jq dựng attributes: RedrivePolicy phải là CHUỖI JSON lồng trong JSON
QUEUE_URL=$(aws sqs create-queue --queue-name lab11-orders --tags Project=aws-lab-11 \
  --attributes "$(jq -cn --arg dlq "${DLQ_ARN}" '{
     VisibilityTimeout: "20", ReceiveMessageWaitTimeSeconds: "10", MessageRetentionPeriod: "86400",
     RedrivePolicy: ({deadLetterTargetArn: $dlq, maxReceiveCount: "3"} | tojson)}')" \
  --query QueueUrl --output text)
QUEUE_ARN=$(aws sqs get-queue-attributes --queue-url "${QUEUE_URL}" --attribute-names QueueArn \
  --query Attributes.QueueArn --output text)
export QUEUE_URL
aws dynamodb wait table-exists --table-name lab11-orders

aws sqs get-queue-attributes --queue-url "${QUEUE_URL}" \
  --attribute-names VisibilityTimeout RedrivePolicy --query Attributes
```

*Kỳ vọng:* `VisibilityTimeout: "20"` và `RedrivePolicy` chứa ARN của `lab11-orders-dlq`, `maxReceiveCount: 3`.

- DLQ giữ 14 ngày, lâu hơn queue chính (1 ngày): với Standard queue, khi message chuyển sang DLQ, **thời điểm enqueue gốc được giữ nguyên**, nên DLQ retention ngắn hơn sẽ làm message hết hạn ngay khi vừa chuyển sang.
- Visibility 20 giây là cho lab chạy nhanh. Production đặt theo thời gian xử lý tối đa của worker (thường vài lần p99) và gia hạn bằng `ChangeMessageVisibility` khi việc kéo dài.

---

## Bước 2 — Happy path và message trùng

```bash
send() {  # $1 = body JSON, $2 = idempotency key do producer sinh một lần cho mỗi yêu cầu
  aws sqs send-message --queue-url "${QUEUE_URL}" --message-body "$1" \
    --message-attributes "{\"IdempotencyKey\":{\"DataType\":\"String\",\"StringValue\":\"$2\"}}" \
    --query MessageId --output text
}
count() { aws dynamodb scan --table-name lab11-orders --select COUNT --query Count --output text; }

# Producer retry: cùng một yêu cầu đặt hàng gửi 2 lần (ví dụ do timeout khi nhận phản hồi)
send '{"order_id":"ORD-1001","amount":250000}' req-7f3a-1001
send '{"order_id":"ORD-1001","amount":250000}' req-7f3a-1001
python worker.py
python worker.py
echo "Số đơn trong bảng: $(count)"
```

*Kỳ vọng (minh họa):* hai `MessageId` **khác nhau** (SQS coi là hai message); worker in một dòng `CREATED ORD-1001 key=req-7f3a-1001` và một dòng `DUPLICATE ORD-1001 ...`; `Số đơn trong bảng: 1`. Chống trùng nằm ở **side effect**, không nằm ở queue.

---

## Bước 3 — Worker chết sau side effect, trước delete

```bash
send '{"order_id":"ORD-1002","amount":480000}' req-7f3a-1002
python worker.py crash-after-write; echo "exit=$?"
aws sqs get-queue-attributes --queue-url "${QUEUE_URL}" \
  --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible --query Attributes
echo "Chờ visibility timeout 20 giây..."; sleep 22
python worker.py
echo "Số đơn trong bảng: $(count)"
```

*Kỳ vọng (minh họa):*

```text
CREATED   ORD-1002 key=req-7f3a-1002 receive#1
CRASH     sau khi ghi DB, trước khi delete
exit=1
{ "ApproximateNumberOfMessages": "0", "ApproximateNumberOfMessagesNotVisible": "1" }
DUPLICATE ORD-1002 key=req-7f3a-1002 receive#2 -> không tạo lại đơn
Số đơn trong bảng: 2
```

Message **quay lại** vì chưa bị delete (at-least-once), nhưng lần xử lý thứ hai thấy key đã có nên không tạo đơn trùng, rồi delete. Nếu side effect là gọi API bên ngoài (trừ tiền thẻ), bạn cần truyền idempotency key sang API đó; một bảng "đã xử lý" riêng cũng phải ghi **nguyên tử** cùng side effect, nếu không sẽ có khe hở giữa hai lệnh ghi.

---

## Bước 4 — Negative case: poison message vào DLQ

Producer legacy gửi schema cũ (`orderId` thay vì `order_id`). Worker hiện tại không hiểu, ném lỗi và không delete:

```bash
send '{"orderId":"ORD-1003","amount":990000}' req-7f3a-1003
for i in 1 2 3 4; do
  echo "--- lần $i ($(date +%T))"
  python worker.py
  [ "$i" -lt 4 ] && sleep 21
done
for Q in "${QUEUE_URL}" "${DLQ_URL}"; do
  aws sqs get-queue-attributes --queue-url "${Q}" \
    --attribute-names ApproximateNumberOfMessages --query Attributes.ApproximateNumberOfMessages --output text
done
```

*Kỳ vọng (minh họa):*

```text
--- lần 1  FAILED receive#1 lỗi='thiếu order_id' -> không delete, message sẽ quay lại
--- lần 2  FAILED receive#2 ...
--- lần 3  FAILED receive#3 ...
--- lần 4  (không có message)
0
1
```

SQS chỉ chuyển message sang DLQ khi có **lần nhận tiếp theo** sau khi đã nhận đủ `maxReceiveCount` lần; lần gọi thứ 4 kích hoạt việc chuyển và không trả message nào. Nếu dừng ở lần 3, queue chính vẫn còn 1 message.

Xem message trong DLQ để tìm nguyên nhân (không xóa):

```bash
aws sqs receive-message --queue-url "${DLQ_URL}" --visibility-timeout 0 \
  --message-system-attribute-names ApproximateReceiveCount SentTimestamp \
  --query 'Messages[0].[Body, Attributes.ApproximateReceiveCount]' --output text
```

*Kỳ vọng:* `{"orderId":"ORD-1003","amount":990000}`: nguyên nhân là **schema**, không phải lỗi tạm thời, nên retry thêm cũng vô ích.

---

## Bước 5 — Sửa worker rồi redrive có kiểm soát

"Bản sửa" là chế độ `v2` của worker (chấp nhận `orderId`). Chỉ redrive **sau khi** bản sửa sẵn sàng:

```bash
TASK=$(aws sqs start-message-move-task --source-arn "${DLQ_ARN}" --destination-arn "${QUEUE_ARN}" \
  --max-number-of-messages-per-second 5 --query TaskHandle --output text)
for i in $(seq 1 12); do
  S=$(aws sqs list-message-move-tasks --source-arn "${DLQ_ARN}" --max-results 1 \
    --query 'Results[0].[Status,ApproximateNumberOfMessagesMoved]' --output text)
  echo "$(date +%T) ${S}"; case "${S}" in COMPLETED*|FAILED*) break;; esac; sleep 5
done
python worker.py v2
echo "Số đơn trong bảng: $(count)"
```

*Kỳ vọng (minh họa):* `COMPLETED 1`; worker in `CREATED ORD-1003 key=req-7f3a-1003 receive#1`; `Số đơn trong bảng: 3`. `--max-number-of-messages-per-second` giới hạn tốc độ để redrive hàng nghìn message không đè sập worker/DB vừa được sửa.

---

## Bước 6 — Mini-lab 1: EventBridge lọc theo nội dung

```bash
aws events create-event-bus --name lab11-bus --tags Key=Project,Value=aws-lab-11 >/dev/null

cat > pattern.json << 'EOF'
{
  "source": ["lab11.orders"],
  "detail-type": ["OrderPlaced"],
  "detail": { "amount": [{ "numeric": [">=", 1000000] }] }
}
EOF

# Kiểm tra pattern trước khi deploy, không tốn event
ev() {  # $1 = detail-type, $2 = amount
  jq -cn --arg dt "$1" --argjson a "$2" --arg acct "${ACCOUNT_ID}" --arg r "${AWS_REGION}" \
    '{id:"1", account:$acct, source:"lab11.orders", time:"2026-10-31T00:00:00Z", region:$r,
      resources:[], "detail-type":$dt, detail:{order_id:"ORD-X", amount:$a}}'
}
aws events test-event-pattern --event-pattern file://pattern.json --event "$(ev OrderPlaced 2500000)" --query Result
aws events test-event-pattern --event-pattern file://pattern.json --event "$(ev OrderPlaced 300000)"  --query Result
aws events test-event-pattern --event-pattern file://pattern.json --event "$(ev orderPlaced 2500000)" --query Result
```

*Kỳ vọng:* `true`, `false`, `false`. Dòng cuối sai vì pattern **phân biệt hoa thường** (`orderPlaced` ≠ `OrderPlaced`).

Tạo rule, queue đích và queue policy chỉ cho **đúng rule này** gửi:

```bash
HV_URL=$(aws sqs create-queue --queue-name lab11-high-value --tags Project=aws-lab-11 --query QueueUrl --output text)
HV_ARN=$(aws sqs get-queue-attributes --queue-url "${HV_URL}" --attribute-names QueueArn --query Attributes.QueueArn --output text)
RULE_ARN=$(aws events put-rule --name lab11-high-value --event-bus-name lab11-bus \
  --event-pattern file://pattern.json --query RuleArn --output text)

cat > hv-policy.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"events.amazonaws.com"},
 "Action":"sqs:SendMessage","Resource":"${HV_ARN}","Condition":{"ArnEquals":{"aws:SourceArn":"${RULE_ARN}"}}}]}
EOF
aws sqs set-queue-attributes --queue-url "${HV_URL}" \
  --attributes "$(jq -cn --rawfile p hv-policy.json '{Policy: $p}')"
aws events put-targets --event-bus-name lab11-bus --rule lab11-high-value \
  --targets "Id=hv-queue,Arn=${HV_ARN}" --query FailedEntryCount

entry() { jq -cn --arg a "$1" '{EventBusName:"lab11-bus", Source:"lab11.orders", DetailType:"OrderPlaced",
  Detail: ({order_id:("ORD-"+$a), amount:($a|tonumber)} | tojson)}'; }
aws events put-events --entries "[$(entry 2500000),$(entry 300000)]" --query FailedEntryCount
sleep 5
aws sqs receive-message --queue-url "${HV_URL}" --max-number-of-messages 10 --wait-time-seconds 5 \
  --query 'Messages[].Body' --output text | jq -c '{detail_type: .["detail-type"], detail}'
```

*Kỳ vọng:* `put-targets` và `put-events` đều trả `0` (không lỗi); queue chỉ nhận **một** event với `amount: 2500000`. Event `300000` khớp bus nhưng không khớp rule nên bị bỏ qua (không lỗi, không DLQ).

Nếu queue rỗng dù pattern đúng: target bị từ chối do thiếu queue policy. Bằng chứng là metric `FailedInvocations` của rule (namespace `AWS/Events`). Với target quan trọng, gắn DLQ cho target (`DeadLetterConfig`) để không mất event khi gửi thất bại.

---

## Bước 7 — Mini-lab 2: Step Functions Standard với Retry/Catch

```bash
SHIP_URL=$(aws sqs create-queue --queue-name lab11-shipping --tags Project=aws-lab-11 --query QueueUrl --output text)
SHIP_ARN=$(aws sqs get-queue-attributes --queue-url "${SHIP_URL}" --attribute-names QueueArn --query Attributes.QueueArn --output text)

cat > sfn-trust.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"states.amazonaws.com"},
 "Action":"sts:AssumeRole","Condition":{"StringEquals":{"aws:SourceAccount":"${ACCOUNT_ID}"}}}]}
EOF
aws iam create-role --role-name lab11-sfn-role --assume-role-policy-document file://sfn-trust.json \
  --tags Key=Project,Value=aws-lab-11 >/dev/null
aws iam put-role-policy --role-name lab11-sfn-role --policy-name SendToShipping --policy-document \
  "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":\"sqs:SendMessage\",\"Resource\":\"${SHIP_ARN}\"}]}"

cat > saga.json << 'EOF'
{
  "Comment": "Lab 11 order saga: Retry cho lỗi tạm thời, Catch cho lỗi nghiệp vụ",
  "StartAt": "ReserveInventory",
  "States": {
    "ReserveInventory": {
      "Type": "Pass", "Result": { "reservation_id": "RES-8921" }, "ResultPath": "$.inventory",
      "Next": "ChargePayment"
    },
    "ChargePayment": {
      "Type": "Parallel",
      "Comment": "Giả lập cổng thanh toán; Parallel cho phép Retry/Catch mà không cần Lambda",
      "Branches": [{
        "StartAt": "CallGateway",
        "States": {
          "CallGateway": {
            "Type": "Choice",
            "Choices": [
              { "Variable": "$.payment_mode", "StringEquals": "DECLINED", "Next": "Declined" },
              { "Variable": "$.payment_mode", "StringEquals": "TIMEOUT",  "Next": "GatewayTimeout" }
            ],
            "Default": "Charged"
          },
          "Declined":       { "Type": "Fail", "Error": "PaymentDeclined",       "Cause": "Thẻ bị từ chối (giả lập)" },
          "GatewayTimeout": { "Type": "Fail", "Error": "PaymentGatewayTimeout", "Cause": "Cổng thanh toán không phản hồi (giả lập)" },
          "Charged":        { "Type": "Pass", "Result": { "transaction_id": "TXN-7731" }, "End": true }
        }
      }],
      "ResultPath": "$.payment",
      "Retry": [
        { "ErrorEquals": ["PaymentGatewayTimeout"], "IntervalSeconds": 1, "MaxAttempts": 2, "BackoffRate": 2.0 }
      ],
      "Catch": [
        { "ErrorEquals": ["PaymentDeclined", "PaymentGatewayTimeout"], "ResultPath": "$.error", "Next": "ReleaseInventory" },
        { "ErrorEquals": ["States.ALL"], "ResultPath": "$.error", "Next": "ReleaseInventory" }
      ],
      "Next": "RequestShipping"
    },
    "RequestShipping": {
      "Type": "Task",
      "Resource": "arn:aws:states:::sqs:sendMessage",
      "Parameters": { "QueueUrl": "__SHIP_URL__", "MessageBody.$": "States.JsonToString($)" },
      "ResultPath": null,
      "Next": "OrderCompleted"
    },
    "OrderCompleted": { "Type": "Succeed" },
    "ReleaseInventory": {
      "Type": "Pass", "Result": { "released": true }, "ResultPath": "$.compensation",
      "Next": "OrderFailed"
    },
    "OrderFailed": { "Type": "Fail", "Error": "OrderFailed", "Cause": "Thanh toán thất bại, đã hoàn trả tồn kho" }
  }
}
EOF
sed -i.bak "s#__SHIP_URL__#${SHIP_URL}#" saga.json
sleep 10   # IAM propagate

SFN_ARN=$(aws stepfunctions create-state-machine --name lab11-order-saga --type STANDARD \
  --definition file://saga.json --role-arn "arn:aws:iam::${ACCOUNT_ID}:role/lab11-sfn-role" \
  --tags key=Project,value=aws-lab-11 --query stateMachineArn --output text)
```

- `States.ALL` phải đứng **một mình** và ở catcher **cuối cùng**; viết chung với lỗi khác trong một `ErrorEquals` là ASL không hợp lệ.
- `Retry` chỉ cho `PaymentGatewayTimeout` (lỗi tạm thời). `PaymentDeclined` là lỗi nghiệp vụ: retry chỉ tạo thêm lần trừ tiền thất bại, nên đi thẳng sang `Catch`.

Chạy ba kịch bản:

```bash
run() {  # $1 = tên, $2 = payment_mode
  local ARN=$(aws stepfunctions start-execution --state-machine-arn "${SFN_ARN}" --name "$1-$(date +%s)" \
    --input "{\"order_id\":\"ORD-500\",\"payment_mode\":\"$2\"}" --query executionArn --output text)
  sleep 8
  aws stepfunctions describe-execution --execution-arn "${ARN}" --query '[status,error,cause]' --output text
  echo "  số lần gọi cổng thanh toán: $(aws stepfunctions get-execution-history --execution-arn "${ARN}" \
    --query "length(events[?type=='ChoiceStateEntered'])")"
}
run ok OK
run declined DECLINED
run timeout TIMEOUT
aws sqs receive-message --queue-url "${SHIP_URL}" --wait-time-seconds 5 --query 'Messages[].Body' --output text
```

*Kỳ vọng (minh họa):*

```text
SUCCEEDED  None  None
  số lần gọi cổng thanh toán: 1
FAILED  OrderFailed  Thanh toán thất bại, đã hoàn trả tồn kho
  số lần gọi cổng thanh toán: 1
FAILED  OrderFailed  Thanh toán thất bại, đã hoàn trả tồn kho
  số lần gọi cổng thanh toán: 3
{"order_id":"ORD-500","payment_mode":"OK","inventory":{...},"payment":[{"transaction_id":"TXN-7731"}]}
```

`timeout` được thử 3 lần (1 + 2 retry) rồi mới bồi hoàn; `declined` chỉ 1 lần. Chỉ kịch bản `ok` gửi message sang `lab11-shipping`. Mở console Step Functions → execution `timeout-...` để xem đồ thị và từng lần retry.

Nếu state `ChargePayment` gọi một API trừ tiền thật, mỗi lần retry là một lần gọi lại: API đó phải nhận idempotency key (ví dụ `order_id`), nếu không retry có thể trừ tiền hai lần.

---

## Bước 8 — Dọn dẹp

Thứ tự: tắt nguồn phát (rule) → kiểm tra message đang xử lý → xóa đích và workflow → xóa queue/bảng → IAM.

```bash
# 1. Tắt producer: disable rule, không còn event mới vào queue
aws events disable-rule --name lab11-high-value --event-bus-name lab11-bus

# 2. Message còn trong queue/đang in-flight (lab: chấp nhận bỏ; production: chờ worker xử lý xong)
for Q in "${QUEUE_URL}" "${DLQ_URL}" "${HV_URL}" "${SHIP_URL}"; do
  echo "${Q##*/}: $(aws sqs get-queue-attributes --queue-url "${Q}" \
    --attribute-names ApproximateNumberOfMessages ApproximateNumberOfMessagesNotVisible \
    --query 'Attributes.[ApproximateNumberOfMessages,ApproximateNumberOfMessagesNotVisible]' --output text)"
done

# 3. EventBridge
aws events remove-targets --event-bus-name lab11-bus --rule lab11-high-value --ids hv-queue >/dev/null
aws events delete-rule --event-bus-name lab11-bus --name lab11-high-value
aws events delete-event-bus --name lab11-bus

# 4. Step Functions (execution history bị xóa theo), IAM role
aws stepfunctions delete-state-machine --state-machine-arn "${SFN_ARN}"
aws iam delete-role-policy --role-name lab11-sfn-role --policy-name SendToShipping
aws iam delete-role --role-name lab11-sfn-role

# 5. Queue và bảng
for Q in "${QUEUE_URL}" "${DLQ_URL}" "${HV_URL}" "${SHIP_URL}"; do aws sqs delete-queue --queue-url "${Q}"; done
aws dynamodb delete-table --table-name lab11-orders >/dev/null

# 6. File tạm
deactivate 2>/dev/null; cd ~ && rm -rf "${LAB_DIR}"
```

Xóa queue chính trước DLQ cũng được, nhưng nếu xóa DLQ trước mà queue chính còn worker chạy, message hết lượt retry sẽ không có chỗ đi. SQS cần tới 60 giây để thực sự xóa queue; tạo lại queue cùng tên ngay lập tức có thể báo lỗi.

### Kiểm tra inventory sau cleanup

```bash
echo "== SQS";      aws sqs list-queues --queue-name-prefix lab11 --query QueueUrls --output text
echo "== DynamoDB"; aws dynamodb list-tables --query "TableNames[?starts_with(@,'lab11')]" --output text
echo "== Bus";      aws events list-event-buses --name-prefix lab11 --query 'EventBuses[].Name' --output text
echo "== SFN";      aws stepfunctions list-state-machines --query "stateMachines[?name=='lab11-order-saga'].name" --output text
echo "== IAM";      aws iam get-role --role-name lab11-sfn-role 2>&1 | grep -o NoSuchEntity
```

*Kỳ vọng:* mọi mục rỗng (queue có thể còn hiện thêm tối đa 60 giây), IAM in `NoSuchEntity`.

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
url() { aws sqs get-queue-url --queue-name "$1" --query QueueUrl --output text 2>/dev/null; }
QUEUE_URL=$(url lab11-orders); DLQ_URL=$(url lab11-orders-dlq); HV_URL=$(url lab11-high-value); SHIP_URL=$(url lab11-shipping)
SFN_ARN="arn:aws:states:${AWS_REGION}:${ACCOUNT_ID}:stateMachine:lab11-order-saga"
echo "Q=${QUEUE_URL} DLQ=${DLQ_URL} HV=${HV_URL} SHIP=${SHIP_URL}"
```

Sau đó chạy lại Bước 8 (bỏ dòng `deactivate`). Lệnh báo `NonExistentQueue`, `ResourceNotFoundException`, `StateMachineDoesNotExist`, `NoSuchEntity` nghĩa là tài nguyên đã xóa; biến URL rỗng thì bỏ qua queue đó.

---

## Checklist hoàn thành

- [ ] Hai message trùng idempotency key chỉ tạo **một** đơn.
- [ ] Worker chết sau ghi DB, trước delete: message quay lại, lần hai báo `DUPLICATE`, tổng đơn không tăng.
- [ ] Poison message: `receive#1..3` rồi chuyển DLQ ở lần nhận thứ 4; giải thích được vì sao không phải ngay sau lần 3.
- [ ] Tìm được nguyên nhân (schema) từ message trong DLQ trước khi redrive; redrive có giới hạn tốc độ, worker v2 xử lý thành công.
- [ ] `test-event-pattern` trả `true/false/false` như kỳ vọng; queue chỉ nhận event giá trị cao.
- [ ] Step Functions: `ok` → `SUCCEEDED` và có message giao hàng; `declined` → bồi hoàn, 1 lần gọi cổng thanh toán; `timeout` → 3 lần rồi bồi hoàn.
- [ ] Cleanup: rule được disable trước; inventory rỗng.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| Message xuất hiện lại sau khi worker xử lý xong | Worker không gọi `DeleteMessage`, receipt handle sai/cũ, hoặc xử lý lâu hơn visibility timeout | Xem `ApproximateReceiveCount` tăng; log worker có dòng delete không; tăng visibility hoặc gia hạn |
| Đơn bị tạo hai lần | Side effect không có điều kiện chống trùng, hoặc idempotency key khác nhau giữa hai lần gửi | Kiểm tra `ConditionExpression`; producer phải sinh key **một lần** cho mỗi yêu cầu nghiệp vụ |
| Message không sang DLQ | Chưa có lần nhận thứ `maxReceiveCount + 1`, hoặc `RedrivePolicy` sai ARN | `get-queue-attributes --attribute-names RedrivePolicy`; gọi receive thêm một lần |
| DLQ tăng đột biến | Bản deploy worker lỗi, schema đổi, hoặc worker thiếu quyền (ví dụ DynamoDB) | Đọc vài message trong DLQ và log worker; sửa nguyên nhân **trước** khi redrive |
| `start-message-move-task` lỗi | Queue nguồn không phải DLQ của queue nào, hoặc thiếu quyền | Kiểm tra `RedrivePolicy` của queue chính trỏ tới DLQ; quyền `sqs:StartMessageMoveTask` |
| Event không tới target | Pattern không khớp (hoa thường, kiểu số/chuỗi), sai bus, thiếu queue policy | `test-event-pattern`; `put-events` gửi đúng `EventBusName`; metric `FailedInvocations`; queue policy với `aws:SourceArn` của rule |
| `create-state-machine` báo `InvalidDefinition` | ASL sai, ví dụ `States.ALL` chung với lỗi khác | Đọc thông điệp lỗi (có đường dẫn tới state); tách `States.ALL` thành catcher cuối |
| Execution lỗi `States.TaskFailed` ở `RequestShipping` với AccessDenied | Role thiếu `sqs:SendMessage` trên queue giao hàng | `aws iam get-role-policy --role-name lab11-sfn-role --policy-name SendToShipping` |
| Workflow lặp side effect | `Retry` bắt cả lỗi nghiệp vụ hoặc `States.ALL` | Chỉ retry lỗi tạm thời; truyền idempotency key vào task có side effect |

---

## Tiếp theo

Bạn đã chứng minh được hệ thống không tạo đơn trùng dù message bị gửi lại, cô lập và cứu được poison message, và tách lỗi tạm thời khỏi lỗi nghiệp vụ trong workflow. Bài tiếp theo: **[Bài 12 — Infrastructure as Code và CI/CD: CloudFormation/CDK, Terraform, GitHub Actions OIDC](../../notes/12-iac-and-cicd.md)**.
