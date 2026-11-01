# Lab 12 — IaC và CI/CD: CloudFormation với change set, execution role, rollback có kiểm soát và GitHub Actions OIDC

> **Lý thuyết:** [notes/12-iac-and-cicd.md](../../notes/12-iac-and-cicd.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 90–120 phút (thêm 30 phút nếu chạy workflow GitHub Actions thật).
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin định danh dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

Công cụ chính là **CloudFormation** (viết tay YAML để thấy rõ từng tài nguyên). CDK sinh ra CloudFormation; Terraform là lựa chọn thay thế được so sánh trong lý thuyết. Không triển khai cùng tài nguyên bằng hai công cụ.

| ✅ Có | ❌ Không |
|---|---|
| Template: SNS topic → SQS queue (fan-out) + DLQ + alarm, tham số hóa theo môi trường | Access key dài hạn cho CI |
| Lint (`cfn-lint`, tùy chọn) và `validate-template`; change set, đọc diff trước khi deploy | Coi "validate/plan pass" là bằng chứng deploy đúng |
| **CloudFormation execution role** giới hạn quyền tài nguyên; người deploy chỉ cần quyền CloudFormation + `PassRole` | Deploy bằng quyền admin của người chạy |
| Kiểm chứng chức năng sau deploy (publish SNS → message vào SQS) | |
| Update lỗi có chủ đích → `UPDATE_ROLLBACK_COMPLETE`, tài nguyên cũ giữ nguyên; đọc event nguyên nhân đầu tiên | Sửa tay tài nguyên do stack quản lý |
| Drift detection sau khi sửa tay có chủ đích | |
| OIDC provider + role cho GitHub Actions, trust giới hạn repo **và** environment | Trust `repo:*` hoặc thiếu `aud` |
| Workflow GitHub Actions mẫu (nhánh có repo riêng) | Commit account ID, ARN thật vào repo public |
| Cleanup stack, role, provider (nếu lab tạo) | |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `ACCOUNT_ID` | Account ID (lấy tự động) | `111122223333` |
| `STACK` | Tên stack | `lab12-dev-orders` |
| `<GH_OWNER>/<GH_REPO>` | Repository GitHub **của bạn** (nhánh tùy chọn) | `ban/aws-lab12` |

Tên cố định: `lab12-*` cho tài nguyên, `lab12-cfn-exec` (execution role), `lab12-github-deployer` (role cho GitHub Actions). Stack tự gắn tag `Project=aws-lab-12` lên mọi tài nguyên hỗ trợ tag.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 02](../02-identity-and-access/README.md) (IAM role, trust policy) và [Lab 11](../11-messaging-and-workflows/README.md) (SNS/SQS).
- [ ] Công cụ: AWS CLI v2, `jq`. Tùy chọn: `pip install cfn-lint` trong virtualenv; tài khoản GitHub và repo riêng (private khuyến nghị) cho nhánh workflow.
- [ ] Quyền cần thiết (không cần `AdministratorAccess`):
  - **IAM:** `iam:CreateRole`, `PutRolePolicy`, `DeleteRolePolicy`, `DeleteRole`, `GetRole`, `TagRole`, **`iam:PassRole` trên `lab12-cfn-exec`**, `iam:ListOpenIDConnectProviders`, `CreateOpenIDConnectProvider`, `DeleteOpenIDConnectProvider`, `TagOpenIDConnectProvider`.
  - **CloudFormation:** `cloudformation:ValidateTemplate`, `CreateChangeSet`, `DescribeChangeSet`, `ExecuteChangeSet`, `DeleteChangeSet`, `DescribeStacks`, `DescribeStackEvents`, `DetectStackDrift`, `DescribeStackDriftDetectionStatus`, `DescribeStackResourceDrifts`, `DeleteStack` trên `stack/lab12-*`.
  - **Dữ liệu kiểm chứng:** `sns:Publish`, `sqs:ReceiveMessage`, `sqs:GetQueueAttributes`, `sqs:SetQueueAttributes` (cho bước drift), `ssm:PutParameter`, `ssm:DeleteParameter`.
  - Quyền tạo SNS/SQS/CloudWatch **không** cần ở người chạy: execution role của CloudFormation làm việc đó.
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính

| Tài nguyên | Quy mô | Đơn giá minh họa | Chi phí |
|---|---|---|---|
| CloudFormation (resource type AWS::*) | vài stack operation | không tính phí | $0 |
| SNS, SQS | < 50 request | ~$0.50/triệu publish, ~$0.40/triệu request | < $0.001 |
| CloudWatch alarm | 1 | ~$0.10/alarm-tháng, tính theo thời gian tồn tại | < $0.01 |
| GitHub Actions (nhánh tùy chọn) | 1–3 run | theo gói GitHub của bạn | thường $0 với phút miễn phí |
| **Tổng** | | | **< $0.01** |

---

## Sơ đồ kiến trúc Lab 12

```text
Người học (profile aws-lab)        GitHub Actions (nhánh tùy chọn)
   │ quyền: CloudFormation +          │ OIDC token (iss=token.actions.githubusercontent.com,
   │ iam:PassRole lab12-cfn-exec      │  aud=sts.amazonaws.com, sub=repo:<owner>/<repo>:environment:dev)
   │                                  ▼
   │                         STS AssumeRoleWithWebIdentity → role lab12-github-deployer
   │                                  │ quyền: CloudFormation trên stack/lab12-* + PassRole lab12-cfn-exec
   ▼                                  ▼
 CloudFormation  ──assume──▶  lab12-cfn-exec (trust cloudformation.amazonaws.com)
   │                            quyền: SNS/SQS/CloudWatch chỉ trên tên lab12-*
   ▼
 Stack lab12-dev-orders
   SNS lab12-dev-order-events ──subscription──▶ SQS lab12-dev-orders ──redrive──▶ SQS lab12-dev-orders-dlq
                                                     └─ Alarm lab12-dev-dlq-not-empty (DLQ > 0)
```

---

## Bước 0 — Biến và thư mục làm việc

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export STACK="lab12-dev-orders"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
```

---

## Bước 1 — Template CloudFormation

```bash
cat > orders.yaml << 'EOF'
AWSTemplateFormatVersion: '2010-09-09'
Description: 'Lab 12 - fan-out don hang: SNS -> SQS (+DLQ) va alarm'

Parameters:
  EnvironmentName:
    Type: String
    AllowedValues: [dev, staging, prod]
    Default: dev
  RetentionSeconds:
    Type: Number
    Description: Thoi gian giu message (giay). SQS cho phep 60 - 1209600.
    MinValue: 60
    MaxValue: 1209600
    Default: 345600          # 4 ngay
  AlarmThreshold:
    Type: Number
    Default: 0

Resources:
  OrderEventsTopic:
    Type: AWS::SNS::Topic
    Properties:
      TopicName: !Sub 'lab12-${EnvironmentName}-order-events'

  OrdersDLQ:
    Type: AWS::SQS::Queue
    Properties:
      QueueName: !Sub 'lab12-${EnvironmentName}-orders-dlq'
      MessageRetentionPeriod: 1209600

  OrdersQueue:
    Type: AWS::SQS::Queue
    Properties:
      QueueName: !Sub 'lab12-${EnvironmentName}-orders'
      MessageRetentionPeriod: !Ref RetentionSeconds
      VisibilityTimeout: 30
      RedrivePolicy:
        deadLetterTargetArn: !GetAtt OrdersDLQ.Arn
        maxReceiveCount: 3

  OrdersQueuePolicy:
    Type: AWS::SQS::QueuePolicy
    Properties:
      Queues: [!Ref OrdersQueue]
      PolicyDocument:
        Version: '2012-10-17'
        Statement:
          - Effect: Allow
            Principal: { Service: sns.amazonaws.com }
            Action: sqs:SendMessage
            Resource: !GetAtt OrdersQueue.Arn
            Condition:
              ArnEquals: { 'aws:SourceArn': !Ref OrderEventsTopic }

  OrdersSubscription:
    Type: AWS::SNS::Subscription
    Properties:
      TopicArn: !Ref OrderEventsTopic
      Protocol: sqs
      Endpoint: !GetAtt OrdersQueue.Arn
      RawMessageDelivery: true

  DlqNotEmptyAlarm:
    Type: AWS::CloudWatch::Alarm
    Properties:
      AlarmName: !Sub 'lab12-${EnvironmentName}-dlq-not-empty'
      Namespace: AWS/SQS
      MetricName: ApproximateNumberOfMessagesVisible
      Dimensions:
        - Name: QueueName
          Value: !GetAtt OrdersDLQ.QueueName
      Statistic: Maximum
      Period: 300
      EvaluationPeriods: 1
      Threshold: !Ref AlarmThreshold
      ComparisonOperator: GreaterThanThreshold
      TreatMissingData: notBreaching

Outputs:
  TopicArn:
    Value: !Ref OrderEventsTopic
  QueueUrl:
    Value: !Ref OrdersQueue
EOF
```

- `!Ref` trên `AWS::SQS::Queue` trả **URL**, `!GetAtt ... .Arn` trả ARN. Nhầm hai giá trị này là lỗi phổ biến trong queue policy.
- CloudFormation tự suy ra thứ tự tạo từ các tham chiếu (`!Ref`, `!GetAtt`): DLQ trước queue chính, topic và queue trước subscription.
- `RawMessageDelivery: true` để message trong SQS là đúng body đã publish, không bọc trong envelope JSON của SNS.

---

## Bước 2 — Lint và validate

```bash
# Tùy chọn, bắt được nhiều lỗi hơn validate-template (giá trị property, tham chiếu sai):
# python3 -m venv .venv && . .venv/bin/activate && pip install -q cfn-lint && cfn-lint orders.yaml
aws cloudformation validate-template --template-body file://orders.yaml --query 'Parameters[].ParameterKey'
```

*Kỳ vọng:* `["EnvironmentName", "RetentionSeconds", "AlarmThreshold"]`. `validate-template` chỉ kiểm tra cú pháp/cấu trúc; nó **không** biết `MessageRetentionPeriod: 4` là sai hay tên queue đã tồn tại. Chỉ deploy thật và kiểm chứng chức năng mới là bằng chứng.

---

## Bước 3 — Execution role cho CloudFormation

```bash
cat > cfn-trust.json << EOF
{"Version":"2012-10-17","Statement":[{"Effect":"Allow","Principal":{"Service":"cloudformation.amazonaws.com"},
 "Action":"sts:AssumeRole","Condition":{"StringEquals":{"aws:SourceAccount":"${ACCOUNT_ID}"}}}]}
EOF
cat > cfn-exec.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": ["sns:*"],
      "Resource": "arn:aws:sns:${AWS_REGION}:${ACCOUNT_ID}:lab12-*" },
    { "Effect": "Allow", "Action": ["sqs:*"],
      "Resource": "arn:aws:sqs:${AWS_REGION}:${ACCOUNT_ID}:lab12-*" },
    { "Effect": "Allow", "Action": ["cloudwatch:PutMetricAlarm", "cloudwatch:DeleteAlarms", "cloudwatch:DescribeAlarms",
        "cloudwatch:TagResource", "cloudwatch:UntagResource", "cloudwatch:ListTagsForResource"],
      "Resource": "arn:aws:cloudwatch:${AWS_REGION}:${ACCOUNT_ID}:alarm:lab12-*" },
    { "Effect": "Allow", "Action": ["ssm:PutParameter", "ssm:DeleteParameter", "ssm:GetParameters", "ssm:AddTagsToResource"],
      "Resource": "arn:aws:ssm:${AWS_REGION}:${ACCOUNT_ID}:parameter/lab12/*" }
  ]
}
EOF
aws iam create-role --role-name lab12-cfn-exec --assume-role-policy-document file://cfn-trust.json \
  --tags Key=Project,Value=aws-lab-12 >/dev/null
aws iam put-role-policy --role-name lab12-cfn-exec --policy-name Lab12StackResources --policy-document file://cfn-exec.json
CFN_EXEC_ARN="arn:aws:iam::${ACCOUNT_ID}:role/lab12-cfn-exec"
sleep 10
```

Khi tạo stack với `--role-arn`, **CloudFormation dùng role này** cho mọi thao tác tài nguyên. Người (hoặc pipeline) deploy chỉ cần quyền CloudFormation và `iam:PassRole` trên role này, nên không thể dùng stack để tạo tài nguyên ngoài phạm vi `lab12-*`. `sns:*`/`sqs:*` giới hạn theo tên là đủ cho lab; production nên thu hẹp action.

---

## Bước 4 — Change set, deploy và kiểm chứng chức năng

```bash
changeset() {  # $1 = tên change set, $2 = CREATE|UPDATE, $3 = file template, các tham số còn lại = parameters
  local NAME=$1 TYPE=$2 FILE=$3; shift 3
  aws cloudformation create-change-set --stack-name "${STACK}" --change-set-name "${NAME}" \
    --change-set-type "${TYPE}" --template-body "file://${FILE}" --role-arn "${CFN_EXEC_ARN}" \
    --tags Key=Project,Value=aws-lab-12 --parameters "$@" >/dev/null
  aws cloudformation wait change-set-create-complete --stack-name "${STACK}" --change-set-name "${NAME}"
  aws cloudformation describe-change-set --stack-name "${STACK}" --change-set-name "${NAME}" \
    --query 'Changes[].ResourceChange.[Action,LogicalResourceId,ResourceType,Replacement]' --output table
}
changeset initial CREATE orders.yaml ParameterKey=EnvironmentName,ParameterValue=dev
```

*Kỳ vọng:* bảng 6 dòng `Add` (topic, 2 queue, queue policy, subscription, alarm). **Đọc diff trước khi execute**: đây là bước review thực sự, tương đương `terraform plan`.

```bash
aws cloudformation execute-change-set --stack-name "${STACK}" --change-set-name initial
aws cloudformation wait stack-create-complete --stack-name "${STACK}"
read -r TOPIC_ARN QUEUE_URL < <(aws cloudformation describe-stacks --stack-name "${STACK}" \
  --query "Stacks[0].[Outputs[?OutputKey=='TopicArn'].OutputValue | [0], Outputs[?OutputKey=='QueueUrl'].OutputValue | [0]]" --output text)

# Kiểm chứng chức năng: stack "CREATE_COMPLETE" chưa chứng minh SNS → SQS chạy được
aws sns publish --topic-arn "${TOPIC_ARN}" --message '{"order_id":"ORD-1201","amount":150000}' >/dev/null
aws sqs receive-message --queue-url "${QUEUE_URL}" --wait-time-seconds 10 --query 'Messages[0].Body' --output text
```

*Kỳ vọng:* in `{"order_id":"ORD-1201","amount":150000}`. Nếu rỗng: queue policy sai `aws:SourceArn` hoặc subscription chưa tạo, dù stack báo thành công.

---

## Bước 5 — Negative case: update lỗi và rollback có kiểm soát

Tạo xung đột thật mà validate không bắt được: một parameter SSM **ngoài stack** đã chiếm tên mà bản update muốn tạo. Cùng bản update đổi ngưỡng alarm (một thay đổi hợp lệ) để thấy nó cũng bị hoàn tác.

```bash
aws ssm put-parameter --name /lab12/dev/feature-flag --type String --value "tao-tay" >/dev/null

cat > ff.yaml << 'EOF'
  FeatureFlag:
    Type: AWS::SSM::Parameter
    Properties:
      Name: !Sub '/lab12/${EnvironmentName}/feature-flag'
      Type: String
      Value: 'on'

EOF
# Chèn khối FeatureFlag vào cuối phần Resources (ngay trước dòng "Outputs:")
awk 'FNR==NR { ff = ff $0 "\n"; next } /^Outputs:/ { printf "%s", ff } { print }' ff.yaml orders.yaml > orders-v2.yaml
aws cloudformation validate-template --template-body file://orders-v2.yaml >/dev/null && echo "validate: OK"

changeset add-flag UPDATE orders-v2.yaml ParameterKey=EnvironmentName,UsePreviousValue=true ParameterKey=AlarmThreshold,ParameterValue=5
aws cloudformation execute-change-set --stack-name "${STACK}" --change-set-name add-flag
aws cloudformation wait stack-update-complete --stack-name "${STACK}" 2>/dev/null || true
aws cloudformation describe-stacks --stack-name "${STACK}" --query 'Stacks[0].StackStatus' --output text
```

*Kỳ vọng:* `validate: OK`, change set có `Add FeatureFlag` và `Modify DlqNotEmptyAlarm`; sau khi execute, trạng thái cuối là `UPDATE_ROLLBACK_COMPLETE`.

Tìm **event lỗi đầu tiên** (event mới nhất nằm đầu danh sách, nên đảo ngược):

```bash
aws cloudformation describe-stack-events --stack-name "${STACK}" --output json |
  jq -r '.StackEvents | reverse | map(select(.ResourceStatus | test("FAILED"))) | .[0]
         | "\(.Timestamp)  \(.LogicalResourceId)  \(.ResourceStatus)\n  \(.ResourceStatusReason)"'
aws cloudwatch describe-alarms --alarm-names lab12-dev-dlq-not-empty --query 'MetricAlarms[0].Threshold'
aws sns publish --topic-arn "${TOPIC_ARN}" --message '{"order_id":"ORD-1202"}' >/dev/null
aws sqs receive-message --queue-url "${QUEUE_URL}" --wait-time-seconds 10 --query 'Messages[0].Body' --output text
```

*Kỳ vọng (minh họa):* event đầu tiên là `FeatureFlag  CREATE_FAILED` với lý do dạng `/lab12/dev/feature-flag already exists`; các event `UPDATE_FAILED`/`ROLLBACK` sau đó chỉ là **hậu quả**. Ngưỡng alarm vẫn là `0.0` (thay đổi hợp lệ cũng bị hoàn tác) và SNS → SQS vẫn chạy.

Rollback của CloudFormation chỉ đưa **tài nguyên hạ tầng** về cấu hình cũ. Nếu bản deploy đã chạy migration xóa cột, đổi dữ liệu, gửi message ra ngoài... thì rollback stack **không** đảo ngược những việc đó (xem lý thuyết Phần 5).

---

## Bước 6 — Drift: ai đó sửa tay ngoài stack

```bash
aws sqs set-queue-attributes --queue-url "${QUEUE_URL}" --attributes VisibilityTimeout=300
DID=$(aws cloudformation detect-stack-drift --stack-name "${STACK}" --query StackDriftDetectionId --output text)
until [ "$(aws cloudformation describe-stack-drift-detection-status --stack-drift-detection-id "${DID}" \
  --query DetectionStatus --output text)" != DETECTION_IN_PROGRESS ]; do sleep 3; done
aws cloudformation describe-stack-resource-drifts --stack-name "${STACK}" \
  --stack-resource-drift-status-filters MODIFIED \
  --query 'StackResourceDrifts[].[LogicalResourceId, PropertyDifferences[0].PropertyPath, PropertyDifferences[0].ExpectedValue, PropertyDifferences[0].ActualValue]' --output text
```

*Kỳ vọng:* `OrdersQueue  /VisibilityTimeout  30  300`. Drift detection chỉ **báo**, không sửa. Cách sửa đúng là đưa giá trị mong muốn vào template rồi deploy (hoặc đặt lại bằng tay cho khớp template), không phải để drift tồn tại. Đặt lại để không ảnh hưởng bước sau:

```bash
aws sqs set-queue-attributes --queue-url "${QUEUE_URL}" --attributes VisibilityTimeout=30
```

---

## Bước 7 — OIDC cho GitHub Actions

### 1. OIDC provider (dùng chung cả account)

```bash
PROVIDER_ARN=$(aws iam list-open-id-connect-providers \
  --query "OpenIDConnectProviderList[?ends_with(Arn,'/token.actions.githubusercontent.com')].Arn | [0]" --output text)
if [ "${PROVIDER_ARN}" = "None" ]; then
  # Không cần thumbprint: IAM xác minh certificate của GitHub bằng thư viện CA tin cậy
  PROVIDER_ARN=$(aws iam create-open-id-connect-provider --url https://token.actions.githubusercontent.com \
    --client-id-list sts.amazonaws.com --tags Key=Project,Value=aws-lab-12 \
    --query OpenIDConnectProviderArn --output text)
  echo "lab tạo provider" > provider-created-by-lab
fi
echo "PROVIDER_ARN=${PROVIDER_ARN}"
```

Provider là tài nguyên **dùng chung cả account**: nếu đã có sẵn (do người khác tạo), lab dùng lại và **không** xóa ở cleanup. Nếu CLI cũ báo thiếu `--thumbprint-list`, cập nhật AWS CLI thay vì tự tính thumbprint.

### 2. Role cho workflow: trust giới hạn repo + environment, quyền giới hạn stack

```bash
GH_OWNER="<GH_OWNER>"; GH_REPO="<GH_REPO>"   # repo của bạn; giữ nguyên placeholder nếu không làm nhánh GitHub
cat > gh-trust.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": { "Federated": "${PROVIDER_ARN}" },
    "Action": "sts:AssumeRoleWithWebIdentity",
    "Condition": {
      "StringEquals": {
        "token.actions.githubusercontent.com:aud": "sts.amazonaws.com",
        "token.actions.githubusercontent.com:sub": "repo:${GH_OWNER}/${GH_REPO}:environment:dev"
      }
    }
  }]
}
EOF
cat > gh-deployer.json << EOF
{
  "Version": "2012-10-17",
  "Statement": [
    { "Effect": "Allow", "Action": ["cloudformation:CreateChangeSet", "cloudformation:DescribeChangeSet",
        "cloudformation:ExecuteChangeSet", "cloudformation:DeleteChangeSet", "cloudformation:DescribeStacks",
        "cloudformation:DescribeStackEvents", "cloudformation:GetTemplateSummary"],
      "Resource": "arn:aws:cloudformation:${AWS_REGION}:${ACCOUNT_ID}:stack/lab12-*/*" },
    { "Effect": "Allow", "Action": "cloudformation:ValidateTemplate", "Resource": "*" },
    { "Effect": "Allow", "Action": "iam:PassRole", "Resource": "${CFN_EXEC_ARN}",
      "Condition": { "StringEquals": { "iam:PassedToService": "cloudformation.amazonaws.com" } } }
  ]
}
EOF
aws iam create-role --role-name lab12-github-deployer --assume-role-policy-document file://gh-trust.json \
  --max-session-duration 3600 --tags Key=Project,Value=aws-lab-12 >/dev/null
aws iam put-role-policy --role-name lab12-github-deployer --policy-name DeployLab12Stacks --policy-document file://gh-deployer.json
echo "GH_ROLE_ARN=arn:aws:iam::${ACCOUNT_ID}:role/lab12-github-deployer"
```

- `sub` dạng `repo:<owner>/<repo>:environment:dev` chỉ khớp job khai báo `environment: dev`. Kết hợp với **environment protection rule** trên GitHub (chỉ branch `main`, cần reviewer), bạn giới hạn được cả nhánh lẫn người duyệt. Nếu không dùng environment, `sub` là `repo:<owner>/<repo>:ref:refs/heads/main`.
- Dùng `StringEquals` với giá trị cụ thể. `StringLike` với `repo:<owner>/*` cho **mọi** repo của owner assume role.
- Role này **không** có quyền SNS/SQS: nó chỉ ra lệnh cho CloudFormation, còn CloudFormation dùng `lab12-cfn-exec`. Pipeline bị chiếm cũng không thể tạo EC2 hay đọc S3.

### 3. Negative case không cần GitHub: token không do GitHub ký

```bash
aws sts assume-role-with-web-identity --role-arn "arn:aws:iam::${ACCOUNT_ID}:role/lab12-github-deployer" \
  --role-session-name forged --web-identity-token "eyJhbGciOiJSUzI1NiJ9.eyJpc3MiOiJodHRwczovL2V2aWwuZXhhbXBsZSJ9.c2ln" 2>&1 | tail -1
```

*Kỳ vọng:* lỗi `InvalidIdentityToken` (thông điệp cụ thể tùy lý do, ví dụ issuer không khớp provider nào hoặc chữ ký không hợp lệ). Biết ARN của role là chưa đủ: STS kiểm tra chữ ký của issuer, rồi mới tới `aud`/`sub` trong trust policy.

### 4. (Nhánh tùy chọn) Workflow GitHub Actions thật

Chỉ làm với repo **của bạn**. Tạo environment `dev` trong *Settings → Environments* (tùy chọn: deployment branch `main`, required reviewer). Lưu `GH_ROLE_ARN` và `CFN_EXEC_ARN` vào **environment variables** của `dev` (`AWS_ROLE_ARN`, `CFN_EXEC_ROLE_ARN`), không hardcode trong file. Copy `orders.yaml` vào `infra/orders.yaml` của repo.

```yaml
# .github/workflows/deploy-dev.yml
name: deploy-dev
on:
  workflow_dispatch:
permissions:
  contents: read
  id-token: write          # bắt buộc để xin OIDC token
jobs:
  deploy:
    runs-on: ubuntu-latest
    environment: dev       # quyết định claim sub = repo:<owner>/<repo>:environment:dev
    steps:
      # Pin action theo commit SHA đầy đủ của bản release bạn đã review (lấy ở trang Releases)
      - uses: actions/checkout@<COMMIT_SHA>                       # vX.Y.Z
      - uses: aws-actions/configure-aws-credentials@<COMMIT_SHA>  # vX.Y.Z
        with:
          role-to-assume: ${{ vars.AWS_ROLE_ARN }}
          aws-region: ap-southeast-1
      - name: Validate
        run: aws cloudformation validate-template --template-body file://infra/orders.yaml
      - name: Change set
        run: |
          aws cloudformation create-change-set --stack-name lab12-dev-orders \
            --change-set-name "gh-${GITHUB_RUN_ID}" --change-set-type UPDATE \
            --template-body file://infra/orders.yaml --role-arn "${{ vars.CFN_EXEC_ROLE_ARN }}" \
            --parameters ParameterKey=EnvironmentName,UsePreviousValue=true
          aws cloudformation wait change-set-create-complete --stack-name lab12-dev-orders --change-set-name "gh-${GITHUB_RUN_ID}"
          aws cloudformation describe-change-set --stack-name lab12-dev-orders --change-set-name "gh-${GITHUB_RUN_ID}" \
            --query 'Changes[].ResourceChange.[Action,LogicalResourceId]' --output table
```

*Kỳ vọng:* bước `configure-aws-credentials` thành công; bước cuối in change set (rỗng nếu template không đổi; khi đó `wait` báo change set `FAILED` với lý do "didn't contain changes", là bình thường). Workflow mẫu dừng ở change set để review; thêm bước `execute-change-set` sau khi đã quen.

Negative case thật: tạm đổi `environment: dev` thành `environment: staging` (hoặc bỏ dòng `environment`), chạy lại.
*Kỳ vọng:* `Could not assume role with OIDC: Not authorized to perform sts:AssumeRoleWithWebIdentity`, vì `sub` không còn khớp trust policy.

---

## Bước 8 — Dọn dẹp

```bash
# 1. Stack (CloudFormation dùng execution role để xóa, nên xóa stack TRƯỚC role)
aws cloudformation delete-stack --stack-name "${STACK}" --role-arn "${CFN_EXEC_ARN}"
aws cloudformation wait stack-delete-complete --stack-name "${STACK}"

# 2. Parameter tạo tay ở Bước 5 (không thuộc stack)
aws ssm delete-parameter --name /lab12/dev/feature-flag

# 3. Role
aws iam delete-role-policy --role-name lab12-github-deployer --policy-name DeployLab12Stacks
aws iam delete-role --role-name lab12-github-deployer
aws iam delete-role-policy --role-name lab12-cfn-exec --policy-name Lab12StackResources
aws iam delete-role --role-name lab12-cfn-exec

# 4. OIDC provider: CHỈ xóa nếu lab tạo ra
[ -f provider-created-by-lab ] && aws iam delete-open-id-connect-provider --open-id-connect-provider-arn "${PROVIDER_ARN}"

# 5. File tạm (và workflow/environment trên GitHub nếu đã làm nhánh tùy chọn)
cd ~ && rm -rf "${LAB_DIR}"
```

Nếu xóa role `lab12-cfn-exec` trước stack, `delete-stack` sẽ fail vì CloudFormation không assume được role đã gắn với stack; khi đó tạo lại role cùng tên/quyền rồi xóa stack.

### Kiểm tra inventory sau cleanup

```bash
echo "== Stack";     aws cloudformation describe-stacks --stack-name lab12-dev-orders 2>&1 | grep -o 'does not exist'
echo "== SQS/SNS";   aws sqs list-queues --queue-name-prefix lab12 --query QueueUrls --output text; aws sns list-topics --query "Topics[?contains(TopicArn,':lab12-')].TopicArn" --output text
echo "== Alarm";     aws cloudwatch describe-alarms --alarm-name-prefix lab12 --query 'MetricAlarms[].AlarmName' --output text
echo "== Parameter"; aws ssm get-parameters-by-path --path /lab12 --recursive --query 'Parameters[].Name' --output text
echo "== IAM";       aws iam list-roles --query "Roles[?starts_with(RoleName,'lab12-')].RoleName" --output text
echo "== OIDC";      aws iam list-open-id-connect-providers --query 'OpenIDConnectProviderList[].Arn' --output text
```

*Kỳ vọng:* stack in `does not exist`; các mục còn lại rỗng, trừ OIDC provider nếu nó có từ trước lab (retained có chủ đích, không tính phí).

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1" STACK="lab12-dev-orders"
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
CFN_EXEC_ARN="arn:aws:iam::${ACCOUNT_ID}:role/lab12-cfn-exec"
PROVIDER_ARN=$(aws iam list-open-id-connect-providers \
  --query "OpenIDConnectProviderList[?ends_with(Arn,'/token.actions.githubusercontent.com')].Arn | [0]" --output text)
aws iam list-open-id-connect-provider-tags --open-id-connect-provider-arn "${PROVIDER_ARN}" \
  --query "Tags[?Value=='aws-lab-12']" --output text | grep -q aws-lab-12 && touch provider-created-by-lab
```

Sau đó chạy lại Bước 8. Provider chỉ bị xóa nếu mang tag `Project=aws-lab-12` (do lab tạo). Lệnh báo `does not exist`, `NoSuchEntity`, `ParameterNotFound` nghĩa là đã xóa.

---

## Checklist hoàn thành

- [ ] Template có tham số, DLQ, alarm; giải thích được `!Ref` và `!GetAtt` của SQS khác nhau thế nào.
- [ ] Đọc change set trước khi execute; publish SNS và nhận được message ở SQS sau deploy.
- [ ] Stack được deploy qua execution role; người deploy/pipeline không có quyền SNS/SQS trực tiếp.
- [ ] Update lỗi → `UPDATE_ROLLBACK_COMPLETE`; tìm được event lỗi **đầu tiên**; thay đổi hợp lệ trong cùng update cũng bị hoàn tác; hệ thống vẫn chạy.
- [ ] Drift detection chỉ ra `VisibilityTimeout` bị sửa tay.
- [ ] Trust policy OIDC có cả `aud` và `sub` cụ thể; token giả bị `InvalidIdentityToken`.
- [ ] (Tùy chọn) Workflow assume role thành công với `environment: dev`, thất bại khi đổi environment.
- [ ] Cleanup xong; chỉ OIDC provider có từ trước (nếu có) được giữ lại.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| `Not authorized to perform sts:AssumeRoleWithWebIdentity` | `sub` không khớp (sai owner/repo, thiếu `environment`, chạy từ branch/PR khác), sai `aud`, thiếu `id-token: write` | So `sub` trong trust policy với claim thật; GitHub hiển thị claim trong log bước configure-aws-credentials khi bật debug |
| `No OpenIDConnect provider found` | Provider chưa tạo hoặc sai URL/issuer | `aws iam list-open-id-connect-providers`; URL phải là `https://token.actions.githubusercontent.com` |
| Change set `FAILED` với "didn't contain changes" | Template và tham số không đổi | Không phải lỗi; xóa change set đó |
| Stack `ROLLBACK_COMPLETE` sau lần tạo đầu | Lần **tạo** thất bại; stack này không update được | Đọc event lỗi đầu tiên, `delete-stack`, sửa template rồi tạo lại |
| `UPDATE_ROLLBACK_FAILED` | Rollback cũng lỗi (tài nguyên bị sửa/xóa tay) | Sửa nguyên nhân rồi `continue-update-rollback` (có thể `--resources-to-skip`); không xóa tay thêm |
| `CREATE_FAILED ... not authorized` khi deploy | Execution role thiếu quyền cho resource type đó | Đọc action bị từ chối trong `ResourceStatusReason`; thêm đúng action vào `lab12-cfn-exec` |
| `iam:PassRole` bị từ chối | Người deploy/pipeline thiếu PassRole trên execution role | Thêm `iam:PassRole` giới hạn `Resource` và `iam:PassedToService` |
| `delete-stack` treo/`DELETE_FAILED` | Tài nguyên còn phụ thuộc bên ngoài, `DeletionPolicy: Retain`, hoặc role đã bị xóa | Xem event `DELETE_FAILED`; kiểm tra retain policy; tạo lại execution role nếu cần |

---

## Tiếp theo

Bạn đã deploy hạ tầng có review, có quyền tách bạch và có rollback được chứng minh, và cho CI dùng credentials tạm thời. Bài tiếp theo: **[Bài 13 — Độ tin cậy và hiệu năng: SLO, load test, backup, DR](../../notes/13-reliability-and-performance.md)**.
