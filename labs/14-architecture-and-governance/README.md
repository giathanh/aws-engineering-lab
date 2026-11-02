# Lab 14 — Kiến trúc và quản trị: thiết kế multi-account, SCP có test matrix, đánh giá Control Tower, WAF count → block, review sáu trụ cột và ADR

> **Lý thuyết:** [notes/14-architecture-and-governance.md](../../notes/14-architecture-and-governance.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 4–5 giờ. Phần thiết kế (Bước 1–3, 9–10) mất 2,5–3 giờ. Nhánh WAF tùy chọn (Bước 4–8) mất 60–90 phút.
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**: lab chưa chạy trên account thật. Riêng JSON của SCP và rule WAF đã được kiểm tra cú pháp cục bộ bằng `jq`. Kết quả của IAM policy simulator và AWS WAF phải được bạn tự đo và ghi lại.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Thiết kế **trên giấy** cho multi-account: OU, account, owner, ai được vào và bằng đường nào, break-glass | Bật Organizations, tạo account, đổi root OU hay billing thật |
| Viết hai SCP mẫu, kiểm tra bằng IAM Access Analyzer `validate-policy` | **Attach** SCP vào organization thật |
| Chạy test matrix allowed/denied bằng IAM policy simulator, mô phỏng SCP bằng permissions boundary (có nói rõ giới hạn) | Coi kết quả simulator là bằng chứng SCP đã chạy trong production |
| Phân tích nguy cơ khóa quyền và lập kế hoạch rollout guardrail | Thử lockout trên account có người khác đang dùng |
| Đánh giá Control Tower **từ tài liệu**: prerequisite, chi phí, vòng đời account, drift | Dựng landing zone |
| Nhánh tùy chọn: WAF trên ALB fixture. Chạy count, đo false positive, block, tinh chỉnh, rồi xóa | Gắn WAF vào ALB, API hoặc CloudFront đang phục vụ người dùng |
| Chứng minh WAF **không** thay authentication | Coi WAF là lớp bảo mật duy nhất |
| Review sáu trụ cột Well-Architected cho hệ thống đơn hàng (lab 06–13), viết ba ADR có phương án thay thế | ADR không có bối cảnh, phương án bị loại và hệ quả |

Sản phẩm bàn giao (lưu trong `~/aws-lab-14`, không chứa secret hay ID thật):

- `design.md`
- `scp-*.json`
- `scp-test-matrix.md`
- `control-tower-eval.md`
- `waf-report.md` (nếu chạy nhánh WAF)
- `review.md`
- `adr/ADR-00{1,2,3}.md`

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập, cũng là Region duy nhất được SCP mẫu cho phép | `ap-southeast-1` |
| `111122223333` | Account ID **giả** dùng trong tài liệu thiết kế và simulator | không thay bằng ID thật trong file nộp bài |
| `MY_IP` | IP public của bạn (nhánh WAF) | `203.0.113.10` |
| `ALB_ARN`, `ALB_DNS` | ALB fixture | `arn:aws:elasticloadbalancing:...:loadbalancer/app/lab14-alb/...` |
| `ACL_ARN`, `ACL_ID` | Web ACL | `arn:aws:wafv2:...:regional/webacl/lab14-acl/...` |

Tên cố định là `lab14-*`. Mọi tài nguyên AWS đều gắn tag `Project=aws-lab-14`. Log group WAF **bắt buộc** có tiền tố `aws-waf-logs-`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 02](../02-identity-and-access/README.md) (IAM, policy evaluation), [Lab 05](../05-compute-and-load-balancing/README.md) (ALB) và [Lab 13](../13-reliability-and-performance/README.md) (RTO/RPO đo được, dùng cho ADR-003).
- [ ] Công cụ: AWS CLI v2, `jq`, `curl`, Python 3.
- [ ] Không dùng account production. **Không** cần quyền với AWS Organizations.

### Quyền cần thiết (không cần `AdministratorAccess`)

| Phần | Quyền |
|---|---|
| SCP (Bước 2) | `access-analyzer:ValidatePolicy`, `iam:SimulateCustomPolicy`. Cả hai chỉ đọc và không tạo tài nguyên. |
| Nhánh WAF: mạng | `ec2:CreateVpc`, `CreateSubnet`, `CreateInternetGateway`, `AttachInternetGateway`, `CreateRouteTable`, `CreateRoute`, `AssociateRouteTable`, `CreateSecurityGroup`, `AuthorizeSecurityGroupIngress`, `CreateTags`, `Describe*`, cùng các quyền `Delete*`/`Detach*` tương ứng |
| Nhánh WAF: ALB | `elasticloadbalancing:CreateLoadBalancer`, `CreateListener`, `AddTags`, `DescribeLoadBalancers`, `DeleteLoadBalancer`, **`SetWebAcl`** (bắt buộc khi associate). Cần `iam:CreateServiceLinkedRole` với điều kiện `iam:AWSServiceName = elasticloadbalancing.amazonaws.com` nếu đây là ALB đầu tiên trong account. |
| Nhánh WAF: WAF | `wafv2:CreateWebACL`, `GetWebACL`, `UpdateWebACL`, `DeleteWebACL`, `ListWebACLs`, `AssociateWebACL`, `DisassociateWebACL`, `GetWebACLForResource`, `GetSampledRequests`, `PutLoggingConfiguration`, `DeleteLoggingConfiguration` |
| Nhánh WAF: log | `logs:CreateLogGroup`, `PutRetentionPolicy`, `DeleteLogGroup`, `StartQuery`, `GetQueryResults`. Thêm `logs:CreateLogDelivery`, `DeleteLogDelivery`, `PutResourcePolicy`, `DescribeResourcePolicies`, `DescribeLogGroups`, vì WAF cần các quyền này để tự tạo resource policy cho log group. |

Lab này **không** cần `iam:PassRole` vì không gán role nào cho dịch vụ.

### Chi phí ước tính (giá minh họa, tra lại [AWS WAF pricing](https://aws.amazon.com/waf/pricing/) và [ELB pricing](https://aws.amazon.com/elasticloadbalancing/pricing/))

| Tài nguyên | Quy mô | Đơn giá minh họa | Chi phí cho ~2 giờ |
|---|---|---|---|
| Phần thiết kế, Access Analyzer `validate-policy`, IAM simulator | — | không tính phí | $0 |
| ALB | 1 ALB, gần như 0 LCU | ~$0,025/giờ | ~$0,05 |
| Public IPv4 của ALB | 2 địa chỉ (2 AZ) | ~$0,005/giờ mỗi IP | ~$0,02 |
| Web ACL | 1 | ~$5/tháng, tính theo giờ | ~$0,014 |
| Rule (mỗi managed rule group tính là 1 rule) | 2 | ~$1/rule/tháng, tính theo giờ | ~$0,006 |
| Request WAF | < 1.000 | ~$0,60/triệu | < $0,001 |
| CloudWatch Logs (log WAF, retention 1 ngày) | < 1 MB | ~$0,50–0,70/GB nạp | < $0,001 |
| **Tổng nhánh WAF** | | | **< $0,15** |

- **Quên cleanup một tháng:** ALB khoảng $18, hai IPv4 khoảng $7, web ACL $5, rule $2, tổng khoảng **$32/tháng**.
- Hai rule group trong lab (`AWSManagedRulesCommonRuleSet`, `AWSManagedRulesSQLiRuleSet`) thuộc nhóm baseline, không có phí đăng ký thêm. Một số rule group khác như Bot Control, ATP hay ACFP **có** phí đăng ký và phí theo request riêng, nên không dùng trong lab.
- Tổng WCU của web ACL phải nằm trong ngưỡng cơ bản (1.500 WCU). Vượt ngưỡng này thì phải trả thêm phí. Hai rule group ở đây cộng lại khoảng 900 WCU.
- **Điều kiện dừng:** chạy xong Bước 8 thì dọn dẹp ngay. Nếu phải nghỉ quá 1 giờ, chạy Bước 11 trước rồi làm lại sau.

---

## Sơ đồ kiến trúc Lab 14

```text
Phần A — thiết kế (giấy + API chỉ đọc)
  design.md ──▶ scp-*.json ──▶ validate-policy (Access Analyzer)
                           └──▶ simulate-custom-policy
                                  identity policy  = Allow *        (đóng vai admin trong member account)
                                  boundary policy  = FullAWSAccess + Deny của SCP   (mô phỏng SCP)
                                  context          = aws:RequestedRegion, aws:PrincipalArn
                                  ──▶ scp-test-matrix.md (allowed / explicitDeny)

Phần B — nhánh WAF tùy chọn (account học tập, Region AWS_REGION)
  laptop (MY_IP) ──curl──▶ ALB lab14-alb :80 ──▶ listener: fixed-response 200 "lab14-ok"  (không có target)
                            ▲
                            │ associate
                  Web ACL lab14-acl (REGIONAL, default Allow)
                    P0 AWSManagedRulesSQLiRuleSet    count ─▶ block
                    P1 AWSManagedRulesCommonRuleSet  count ─▶ block, override SizeRestrictions_BODY = count
                    │ sampled requests (tối đa 3 giờ gần nhất)
                    └ logging ──▶ CloudWatch Logs aws-waf-logs-lab14 (retention 1 ngày) ──▶ Logs Insights
```

ALB dùng listener **fixed-response**, nên không cần EC2 hay target group. WAF đánh giá request trước khi ALB áp dụng listener rule. Listener chỉ dùng HTTP, đủ cho lab nhưng production phải dùng HTTPS.

---

## Bước 0 — Biến và thư mục bàn giao

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
aws sts get-caller-identity --query '{Account:Account,Arn:Arn}' --output table
export LAB_DIR="${HOME}/aws-lab-14"
mkdir -p "${LAB_DIR}/adr" && cd "${LAB_DIR}"
```

*Kỳ vọng:* `Arn` là một role hoặc user của account **học tập**, không phải account production. Thư mục `~/aws-lab-14` được **giữ lại** sau lab vì chứa sản phẩm bàn giao. Chỉ xóa nó khi bạn đã nộp bài xong.

---

## Bước 1 — Thiết kế multi-account trên giấy

Tạo `design.md` theo khung dưới đây. Phần đã điền là ví dụ cho hệ thống đơn hàng. Hãy sửa theo bối cảnh của bạn và tự trả lời ba câu hỏi ở cuối.

````bash
cat > design.md <<'EOF'
# Thiết kế multi-account — hệ thống đơn hàng

## Cây OU
Root
├── Management account 111122223333   (chỉ billing, Organizations, Identity Center; KHÔNG chạy workload)
├── Security OU
│   ├── log-archive     (bucket log tập trung: CloudTrail org trail, Config; chỉ ghi thêm, ít người đọc)
│   └── security-tooling (delegated admin cho GuardDuty / Security Hub / IAM Access Analyzer)
├── Infrastructure OU
│   └── network-shared  (Transit Gateway hoặc VPC dùng chung, Route 53 Resolver, ECR dùng chung nếu cần)
├── Workloads OU
│   ├── NonProd OU
│   │   ├── orders-dev
│   │   └── orders-staging
│   └── Prod OU
│       └── orders-prod
├── Sandbox OU          (thử nghiệm cá nhân; budget thấp; không kết nối mạng nội bộ)
└── Suspended OU        (account chờ đóng; SCP deny gần như toàn bộ)

## Account, owner và đường truy cập
| Account | Owner | Ai vào, bằng permission set nào | Dữ liệu | SCP/OU áp dụng |
|---|---|---|---|---|
| management | Platform lead | 2 người, MFA, chỉ quản trị org/billing | billing | (SCP không áp dụng) |
| log-archive | Security | Security: ReadOnly; không ai được xóa | log | Security OU: bảo vệ log |
| security-tooling | Security | Security: SecurityAudit + vận hành công cụ | finding | Security OU |
| orders-dev | Team Orders | Dev: PowerUser (không IAM user) | dữ liệu giả | Region + bảo vệ audit |
| orders-prod | Team Orders | Dev: ReadOnly; deploy qua pipeline OIDC (Lab 12); ghi tay chỉ qua break-glass | dữ liệu thật | Region + bảo vệ audit + chặn xóa backup |

## Break-glass
- Role `BreakGlassEmergencyAdminRole` có trong mỗi account, được tạo bằng StackSets từ management account.
- Chỉ assume được từ 2 principal đã định danh, có MFA, và phải ghi lý do vào ticket.
- Mỗi lần assume tạo một event CloudTrail `AssumeRole` có tên role này, kích hoạt alarm gửi cho Security.
- SCP chặn người khác tạo, sửa hoặc xóa role này (xem Bước 2).
- Diễn tập mỗi quý: assume được không, alarm có kêu không.

## Tự trả lời
1. Vì sao orders-dev và orders-prod là hai account thay vì hai VPC trong một account? (quota, IAM, blast radius, chi phí)
2. Nếu Identity Center ngừng hoạt động, ai vào orders-prod bằng đường nào, và ai được báo?
3. Delegated admin của GuardDuty đặt ở account nào? Vì sao không đặt ở management account?
EOF
````

*Bằng chứng hiểu bài:* mỗi account có owner và đường truy cập cụ thể, và không account nào ghi "admin cho tất cả". Management account không chạy workload. Break-glass có cả cơ chế phát hiện (alarm), không chỉ cơ chế mở cửa.

---

## Bước 2 — SCP mẫu, validate và test matrix

### 2.1. SCP 1 — bảo vệ audit, organization và chính role break-glass

```bash
cat > scp-protect-audit.json <<'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "DenyAuditTampering",
      "Effect": "Deny",
      "Action": [
        "cloudtrail:DeleteTrail",
        "cloudtrail:StopLogging",
        "cloudtrail:UpdateTrail",
        "cloudtrail:PutEventSelectors",
        "config:DeleteConfigurationRecorder",
        "config:StopConfigurationRecorder",
        "config:DeleteDeliveryChannel"
      ],
      "Resource": "*",
      "Condition": {
        "ArnNotLike": {
          "aws:PrincipalArn": [
            "arn:aws:iam::*:role/BreakGlassEmergencyAdminRole",
            "arn:aws:iam::*:role/AWSControlTowerExecution"
          ]
        }
      }
    },
    {
      "Sid": "DenyLeavingOrganization",
      "Effect": "Deny",
      "Action": "organizations:LeaveOrganization",
      "Resource": "*"
    },
    {
      "Sid": "ProtectBreakGlassRole",
      "Effect": "Deny",
      "Action": [
        "iam:CreateRole",
        "iam:DeleteRole",
        "iam:UpdateAssumeRolePolicy",
        "iam:AttachRolePolicy",
        "iam:DetachRolePolicy",
        "iam:PutRolePolicy",
        "iam:DeleteRolePolicy"
      ],
      "Resource": "arn:aws:iam::*:role/BreakGlassEmergencyAdminRole",
      "Condition": {
        "ArnNotLike": {
          "aws:PrincipalArn": "arn:aws:iam::*:role/BreakGlassEmergencyAdminRole"
        }
      }
    }
  ]
}
EOF
```

Ghi chú về thiết kế:

- **Không** cần ngoại lệ cho `aws-service-role/*`, vì SCP vốn không ảnh hưởng service-linked role.
- `AWSControlTowerExecution` chỉ cần nếu dùng Control Tower. Thiếu ngoại lệ này thì Control Tower không quản lý được trail và Config.
- Statement thứ ba khép một lỗ hổng: nếu ai cũng tạo được role tên `BreakGlassEmergencyAdminRole`, ngoại lệ dựa trên tên sẽ trở thành cửa sau.

### 2.2. SCP 2 — chỉ cho phép một Region, có ngoại lệ dịch vụ global

```bash
cat > scp-region.json <<'EOF'
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "DenyOutsideApprovedRegion",
      "Effect": "Deny",
      "NotAction": [
        "iam:*",
        "organizations:*",
        "sts:*",
        "account:*",
        "route53:*",
        "route53domains:*",
        "cloudfront:*",
        "globalaccelerator:*",
        "waf:*",
        "wafv2:*",
        "shield:*",
        "budgets:*",
        "ce:*",
        "cur:*",
        "billing:*",
        "health:*",
        "support:*",
        "trustedadvisor:*",
        "sso:*",
        "s3:GetAccountPublicAccessBlock",
        "s3:ListAllMyBuckets"
      ],
      "Resource": "*",
      "Condition": {
        "StringNotEquals": {
          "aws:RequestedRegion": ["ap-southeast-1"]
        },
        "ArnNotLike": {
          "aws:PrincipalArn": [
            "arn:aws:iam::*:role/BreakGlassEmergencyAdminRole",
            "arn:aws:iam::*:role/AWSControlTowerExecution"
          ]
        }
      }
    }
  ]
}
EOF
```

Danh sách `NotAction` ở đây đã được **rút gọn để minh họa**. Trước khi dùng thật, so với ví dụ "Deny access to AWS based on the requested AWS Region" hiện hành trong tài liệu Organizations, hoặc dùng control Region deny của Control Tower. Lưu ý:

- **Không** đưa `kms:*` hay `config:*` vào `NotAction`. Chúng là dịch vụ theo Region, nên miễn trừ chúng nghĩa là cho phép tạo KMS key hoặc Config ở mọi Region.
- Dịch vụ global (IAM, CloudFront...) thường được ký request tới `us-east-1`. Đó là lý do chúng phải nằm trong `NotAction`. Một số team chọn cách khác là cho phép thêm `us-east-1`.
- Nhiều điều kiện trong một statement được kết hợp bằng **AND**. Lệnh chỉ bị deny khi Region không được phép **và** principal không phải break-glass.

### 2.3. Kiểm tra cú pháp và kích thước

```bash
for F in scp-protect-audit.json scp-region.json; do
  printf '%-24s minified=%s ký tự\n' "$F" "$(jq -c . "$F" | wc -c | tr -d ' ')"
done
```

*Kỳ vọng:* cả hai file parse được và mỗi file nhỏ hơn **5.120 ký tự**, là giới hạn kích thước một SCP. Khoảng trắng cũng được tính, nên khi attach thật hãy gửi bản minified.

```bash
for F in scp-protect-audit.json scp-region.json; do
  echo "== ${F}"
  aws accessanalyzer validate-policy --policy-type SERVICE_CONTROL_POLICY \
    --policy-document "file://${F}" \
    --query 'findings[].[findingType,issueCode,findingDetails]' --output text
done
```

*Kỳ vọng:* không có finding loại `ERROR` hoặc `SECURITY_WARNING`. Finding `SUGGESTION` hoặc `WARNING` cần được đọc và ghi lại lý do chấp nhận hoặc sửa.

*Negative case:* sửa tạm `"Effect": "Deny"` thành `"Effect": "Dney"` trong một bản sao rồi validate lại. Kết quả phải có `ERROR`, nghĩa là phải sửa trước khi attach.

### 2.4. Test matrix bằng IAM policy simulator

Simulator không biết gì về SCP. Ta **mô phỏng** SCP bằng permissions boundary vì cơ chế của hai thứ giống nhau ở điểm quan trọng: cả hai đặt trần quyền chứ không cấp quyền, và Deny thắng. Boundary gồm `FullAWSAccess` (SCP mặc định) cộng các statement Deny ở trên. Identity policy là `Allow *`, đóng vai admin trong member account.

```bash
jq -n '{Version:"2012-10-17",Statement:[{Effect:"Allow",Action:"*",Resource:"*"}]}' > id-admin.json
jq -s '{Version:"2012-10-17",
        Statement:([{Sid:"FullAWSAccess",Effect:"Allow",Action:"*",Resource:"*"}] + .[0].Statement + .[1].Statement)}' \
   scp-protect-audit.json scp-region.json > scp-as-boundary.json

DEV="arn:aws:iam::111122223333:role/OrdersDeveloper"
BG="arn:aws:iam::111122223333:role/BreakGlassEmergencyAdminRole"
ROOT="arn:aws:iam::111122223333:root"
BG_RES="arn:aws:iam::111122223333:role/BreakGlassEmergencyAdminRole"

sim() {  # sim <nhãn> <principal-arn> <action> <region> [resource-arn]
  local label=$1 principal=$2 action=$3 region=$4 res=${5:-*}
  local d
  d=$(aws iam simulate-custom-policy \
        --policy-input-list file://id-admin.json \
        --permissions-boundary-policy-input-list file://scp-as-boundary.json \
        --action-names "${action}" --resource-arns "${res}" \
        --context-entries \
          "ContextKeyName=aws:RequestedRegion,ContextKeyValues=${region},ContextKeyType=string" \
          "ContextKeyName=aws:PrincipalArn,ContextKeyValues=${principal},ContextKeyType=string" \
        --query 'EvaluationResults[0].EvalDecision' --output text)
  printf '| %s | %s | %s | %s | %s |\n' "${label}" "${principal##*/}" "${action}" "${region}" "${d}"
}

{
  echo '| # | Principal | Action | Region | Kết quả simulator |'
  echo '|---|---|---|---|---|'
  sim 1 "$DEV"  ec2:RunInstances              ap-southeast-1
  sim 2 "$DEV"  ec2:RunInstances              eu-central-1
  sim 3 "$DEV"  iam:CreateRole                us-east-1
  sim 4 "$DEV"  kms:CreateKey                 eu-central-1
  sim 5 "$DEV"  cloudtrail:StopLogging        ap-southeast-1
  sim 6 "$BG"   cloudtrail:StopLogging        ap-southeast-1
  sim 7 "$DEV"  organizations:LeaveOrganization us-east-1
  sim 8 "$DEV"  iam:UpdateAssumeRolePolicy    us-east-1 "$BG_RES"
  sim 9 "$ROOT" ec2:RunInstances              eu-central-1
} | tee scp-test-matrix.md
```

*Kỳ vọng (minh họa):*

| # | Principal | Action | Region | Kết quả | Vì sao |
|---|---|---|---|---|---|
| 1 | OrdersDeveloper | ec2:RunInstances | ap-southeast-1 | `allowed` | Region được phép |
| 2 | OrdersDeveloper | ec2:RunInstances | eu-central-1 | `explicitDeny` | SCP 2 |
| 3 | OrdersDeveloper | iam:CreateRole | us-east-1 | `allowed` | `iam:*` nằm trong `NotAction` |
| 4 | OrdersDeveloper | kms:CreateKey | eu-central-1 | `explicitDeny` | KMS là dịch vụ theo Region, không được miễn trừ |
| 5 | OrdersDeveloper | cloudtrail:StopLogging | ap-southeast-1 | `explicitDeny` | SCP 1 |
| 6 | BreakGlass… | cloudtrail:StopLogging | ap-southeast-1 | `allowed` | Ngoại lệ `aws:PrincipalArn` |
| 7 | OrdersDeveloper | organizations:LeaveOrganization | us-east-1 | `explicitDeny` | Không có ngoại lệ, kể cả break-glass |
| 8 | OrdersDeveloper | iam:UpdateAssumeRolePolicy (trên role break-glass) | us-east-1 | `explicitDeny` | Bảo vệ cửa sau |
| 9 | root của member account | ec2:RunInstances | eu-central-1 | `explicitDeny` | SCP **có** áp dụng cho root user của member account |

Nếu kết quả khác bảng trên, đọc `EvaluationResults[0].MatchedStatements` và `PermissionsBoundaryDecisionDetail` bằng cách bỏ `--query` trong `sim`. Lỗi hay gặp nhất là sai tên context key hoặc thiếu `--resource-arns` ở dòng 8.

Ghi thêm vào `scp-test-matrix.md` những trường hợp simulator **không** mô phỏng được:

| Trường hợp | Kết quả theo tài liệu | Ý nghĩa |
|---|---|---|
| Bất kỳ principal nào trong **management account** | SCP không áp dụng | Management account cần guardrail khác: ít người, MFA, không workload |
| Service-linked role (`aws-service-role/...`) | SCP không áp dụng | Không cần ngoại lệ SLR trong SCP |
| Principal từ **account khác** truy cập resource của bạn | SCP của bạn không áp dụng cho họ | Cần resource policy hoặc Resource Control Policy (RCP) |
| SCP gắn ở OU cha và OU con | Mọi tầng đều phải cho phép; Deny ở bất kỳ tầng nào đều thắng | Simulator chỉ mô phỏng một tầng |
| Gỡ `FullAWSAccess` khỏi một OU mà không thay bằng Allow khác | Implicit deny **mọi thứ** ở OU đó | Đây là kiểu lockout dễ gây ra nhất |

### 2.5. Phân tích nguy cơ khóa quyền và kế hoạch rollout

Thêm vào `scp-test-matrix.md`:

```markdown
## Nguy cơ lockout
| Thay đổi | Ai bị ảnh hưởng | Phát hiện bằng | Gỡ bằng |
|---|---|---|---|
| Deny sts:AssumeRole hoặc deny theo PrincipalArn sai | Toàn bộ người dùng Identity Center (role AWSReservedSSO_*) | CloudTrail errorCode AccessDenied, thông điệp "explicit deny in a service control policy" | Detach SCP từ management account |
| Gỡ FullAWSAccess | Mọi principal trong OU | Như trên, ngay lập tức | Attach lại FullAWSAccess từ management account |
| Deny audit thiếu ngoại lệ AWSControlTowerExecution | Control Tower (drift, enroll lỗi) | Trạng thái landing zone / account trong Control Tower | Thêm ngoại lệ, re-register OU |

## Rollout
1. Sandbox OU chỉ có 1 account test; chạy lại test matrix bằng lệnh thật (không phải simulator).
2. NonProd OU; theo dõi CloudTrail AccessDenied do SCP trong 1–2 tuần.
3. Prod OU, có change ticket và người trực.
4. Không bao giờ attach lần đầu ở Root.
```

---

## Bước 3 — Đánh giá Control Tower từ tài liệu

Điền `control-tower-eval.md` dựa trên [Control Tower User Guide](https://docs.aws.amazon.com/controltower/latest/userguide/). Cột "Ghi chú tài liệu" là gợi ý để bạn kiểm chứng. Hãy ghi link trang và ngày bạn đọc vào cột "Nguồn".

| Câu hỏi | Ghi chú tài liệu (kiểm chứng lại) | Áp dụng cho ta | Nguồn |
|---|---|---|---|
| Prerequisite | Thiết lập từ management account. Cần email riêng cho account Log Archive và Audit (dùng sẵn có hoặc để Control Tower tạo). Chọn **home Region**, không đổi được sau khi thiết lập. | | |
| Account sẵn có | Có thể enroll, nhưng phải xử lý trước các xung đột như Config recorder hoặc trail đã tồn tại và role `AWSControlTowerExecution` | | |
| Controls | Gồm preventive (SCP/RCP), detective (Config rule) và proactive (CloudFormation hook). Mức áp dụng gồm mandatory, strongly recommended và elective. | | |
| Vòng đời account | Tạo bằng Account Factory (Service Catalog) hoặc AFT (Terraform). Ngoài ra có thể unmanage hoặc đóng account. Account bị đóng vẫn nằm trong Suspended OU một thời gian. | | |
| Drift | Sửa SCP, OU hoặc role do Control Tower quản lý từ bên ngoài sẽ gây drift. Phải repair hoặc re-register. | | |
| Chi phí | Control Tower không thu phí riêng. Bạn trả cho dịch vụ nó bật: Config (theo configuration item và mỗi Region được quản lý), CloudTrail, S3, CloudWatch Logs, và KMS nếu dùng. | | |
| Không làm thay ta | Không bảo đảm compliance, không bảo vệ ứng dụng (SQLi, authZ), không thay review IAM của workload | | |
| Quyết định | Dùng Control Tower hay tự dựng bằng Organizations + IaC? Ghi điều kiện để xem lại quyết định. | | |

Ước tính chi phí governance theo **số account × số Region được quản lý**. Ví dụ, 6 account × 2 Region thì Config ghi ở 12 nơi. Việc giới hạn số governed Region là cách giảm chi phí lớn nhất, và phải quyết định từ đầu.

---

## Nhánh tùy chọn WAF (Bước 4–8)

Bỏ qua nhánh này nếu thiếu quyền hoặc budget. Thay vào đó, đọc output minh họa ở từng bước và vẫn viết ADR-002 ở Bước 10.

## Bước 4 — Fixture: VPC tối thiểu và ALB fixed-response

```bash
TAG='{Key=Project,Value=aws-lab-14}'
MY_IP=$(curl -s https://checkip.amazonaws.com) && echo "MY_IP=${MY_IP}"
AZ1=$(aws ec2 describe-availability-zones --query 'AvailabilityZones[?State==`available`]|[0].ZoneName' --output text)
AZ2=$(aws ec2 describe-availability-zones --query 'AvailabilityZones[?State==`available`]|[1].ZoneName' --output text)

VPC_ID=$(aws ec2 create-vpc --cidr-block 10.14.0.0/16 \
  --tag-specifications "ResourceType=vpc,Tags=[{Key=Name,Value=lab14-vpc},${TAG}]" \
  --query Vpc.VpcId --output text)
SUB1=$(aws ec2 create-subnet --vpc-id "$VPC_ID" --cidr-block 10.14.1.0/24 --availability-zone "$AZ1" \
  --tag-specifications "ResourceType=subnet,Tags=[{Key=Name,Value=lab14-public-a},${TAG}]" \
  --query Subnet.SubnetId --output text)
SUB2=$(aws ec2 create-subnet --vpc-id "$VPC_ID" --cidr-block 10.14.2.0/24 --availability-zone "$AZ2" \
  --tag-specifications "ResourceType=subnet,Tags=[{Key=Name,Value=lab14-public-b},${TAG}]" \
  --query Subnet.SubnetId --output text)
IGW_ID=$(aws ec2 create-internet-gateway \
  --tag-specifications "ResourceType=internet-gateway,Tags=[{Key=Name,Value=lab14-igw},${TAG}]" \
  --query InternetGateway.InternetGatewayId --output text)
aws ec2 attach-internet-gateway --vpc-id "$VPC_ID" --internet-gateway-id "$IGW_ID"
RT_ID=$(aws ec2 create-route-table --vpc-id "$VPC_ID" \
  --tag-specifications "ResourceType=route-table,Tags=[{Key=Name,Value=lab14-rt},${TAG}]" \
  --query RouteTable.RouteTableId --output text)
aws ec2 create-route --route-table-id "$RT_ID" --destination-cidr-block 0.0.0.0/0 --gateway-id "$IGW_ID" >/dev/null
aws ec2 associate-route-table --route-table-id "$RT_ID" --subnet-id "$SUB1" >/dev/null
aws ec2 associate-route-table --route-table-id "$RT_ID" --subnet-id "$SUB2" >/dev/null

SG_ID=$(aws ec2 create-security-group --group-name lab14-alb-sg --description "lab14 ALB, chi MY_IP" \
  --vpc-id "$VPC_ID" --tag-specifications "ResourceType=security-group,Tags=[${TAG}]" \
  --query GroupId --output text)
aws ec2 authorize-security-group-ingress --group-id "$SG_ID" \
  --ip-permissions "IpProtocol=tcp,FromPort=80,ToPort=80,IpRanges=[{CidrIp=${MY_IP}/32,Description=lab14}]" >/dev/null

read -r ALB_ARN ALB_DNS <<< "$(aws elbv2 create-load-balancer --name lab14-alb --type application \
  --scheme internet-facing --subnets "$SUB1" "$SUB2" --security-groups "$SG_ID" \
  --tags Key=Project,Value=aws-lab-14 \
  --query 'LoadBalancers[0].[LoadBalancerArn,DNSName]' --output text)"
aws elbv2 wait load-balancer-available --load-balancer-arns "$ALB_ARN"
aws elbv2 create-listener --load-balancer-arn "$ALB_ARN" --protocol HTTP --port 80 \
  --default-actions 'Type=fixed-response,FixedResponseConfig={StatusCode=200,ContentType=text/plain,MessageBody=lab14-ok}' >/dev/null
echo "ALB_DNS=${ALB_DNS}"
```

```bash
sleep 30   # DNS của ALB mới có thể cần thêm thời gian
curl -s "http://${ALB_DNS}/orders?id=123"; echo
```

*Kỳ vọng:* in ra `lab14-ok`. Nếu curl treo, kiểm tra lại SG (IP của bạn có thể đã đổi) và route `0.0.0.0/0` tới IGW.

---

## Bước 5 — Web ACL ở chế độ count, bật logging, associate

Rule được sinh bằng một hàm, nhờ vậy ba chế độ dùng ở Bước 5–7 chỉ khác nhau ở đúng chỗ cần khác:

- `count`: cả hai rule group chỉ đếm.
- `strict`: dùng action gốc của rule group, tức Block.
- `tuned`: như `strict` nhưng ép `SizeRestrictions_BODY` về Count.

```bash
waf_rules() {  # waf_rules count|strict|tuned
  jq -n --arg mode "$1" '
    def vis($m): {SampledRequestsEnabled:true, CloudWatchMetricsEnabled:true, MetricName:$m};
    def ov: if $mode == "count" then {Count:{}} else {None:{}} end;
    [
      { Name:"aws-sqli", Priority:0,
        Statement:{ManagedRuleGroupStatement:{VendorName:"AWS", Name:"AWSManagedRulesSQLiRuleSet"}},
        OverrideAction:ov, VisibilityConfig:vis("lab14-sqli") },
      { Name:"aws-common", Priority:1,
        Statement:{ManagedRuleGroupStatement:(
          {VendorName:"AWS", Name:"AWSManagedRulesCommonRuleSet"}
          + (if $mode == "tuned"
             then {RuleActionOverrides:[{Name:"SizeRestrictions_BODY", ActionToUse:{Count:{}}}]}
             else {} end))},
        OverrideAction:ov, VisibilityConfig:vis("lab14-common") }
    ]'
}
waf_rules count > rules-count.json && jq -c '.[] | {Name, OverrideAction}' rules-count.json

read -r ACL_ARN ACL_ID <<< "$(aws wafv2 create-web-acl --name lab14-acl --scope REGIONAL \
  --default-action Allow={} --rules file://rules-count.json \
  --visibility-config SampledRequestsEnabled=true,CloudWatchMetricsEnabled=true,MetricName=lab14-acl \
  --tags Key=Project,Value=aws-lab-14 \
  --query 'Summary.[ARN,Id]' --output text)"
echo "ACL_ID=${ACL_ID}"
```

Bật logging **trước** khi gửi traffic. Log là nguồn dữ liệu đầy đủ, còn sampled requests chỉ là mẫu.

```bash
ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text)
aws logs create-log-group --log-group-name aws-waf-logs-lab14 --tags Project=aws-lab-14
aws logs put-retention-policy --log-group-name aws-waf-logs-lab14 --retention-in-days 1
LG_ARN="arn:aws:logs:${AWS_REGION}:${ACCOUNT_ID}:log-group:aws-waf-logs-lab14"
aws wafv2 put-logging-configuration \
  --logging-configuration "ResourceArn=${ACL_ARN},LogDestinationConfigs=${LG_ARN}" >/dev/null

aws wafv2 associate-web-acl --web-acl-arn "$ACL_ARN" --resource-arn "$ALB_ARN"
aws wafv2 get-web-acl-for-resource --resource-arn "$ALB_ARN" --query 'WebACL.Name' --output text
```

*Kỳ vọng:* in ra `lab14-acl`.

*Negative case:* thử `put-logging-configuration` với log group tên `lab14-waf` (thiếu tiền tố `aws-waf-logs-`). Lệnh phải bị từ chối với `WAFInvalidParameterException`. Không cần tạo log group đó, lỗi tên xảy ra trước.

Nếu associate báo `WAFUnavailableEntityException`, ALB hoặc web ACL vừa tạo chưa sẵn sàng. Chờ 30–60 giây rồi thử lại.

---

## Bước 6 — Traffic giả lập ở chế độ count và đo false positive

Bộ request gồm ba request **hợp lệ** và hai request **tấn công**. Request "big-body" là một đơn hàng hợp lệ có ghi chú dài khoảng 10 KB. Đó là kiểu dữ liệu thật mà rule giới hạn kích thước hay bắt nhầm.

```bash
python3 -c 'import json; print(json.dumps({"order_id":"o-1","note":"giao giờ hành chính " * 500}))' > big.json
wc -c big.json

hit() {  # hit <nhãn> <curl args...>
  local label=$1; shift
  printf '%-10s %s\n' "${label}" "$(curl -s -o /dev/null -w '%{http_code}' "$@")"
}
traffic() {
  hit normal   "http://${ALB_DNS}/orders?id=123"
  hit bigbody  -X POST -H 'Content-Type: application/json' --data-binary @big.json "http://${ALB_DNS}/orders"
  hit admin    "http://${ALB_DNS}/admin/orders"
  hit sqli     "http://${ALB_DNS}/orders?id=1%20UNION%20SELECT%20password%20FROM%20users--"
  hit lfi      "http://${ALB_DNS}/download?file=..%2F..%2F..%2Fetc%2Fpasswd"
}
for i in 1 2 3; do traffic; done | sort | uniq -c
```

*Kỳ vọng ở chế độ count (minh họa):* mọi dòng đều là `200`, kể cả `sqli` và `lfi`, vì count không chặn.

```text
   3 admin      200
   3 bigbody    200
   3 lfi        200
   3 normal     200
   3 sqli       200
```

Chờ 2–3 phút rồi xem rule nào đã "đếm" request nào:

```bash
sampled() {  # sampled <rule-metric-name>
  local end=$(date +%s)
  aws wafv2 get-sampled-requests --web-acl-arn "$ACL_ARN" --rule-metric-name "$1" --scope REGIONAL \
    --time-window StartTime=$((end - 3600)),EndTime=${end} --max-items 100 --output json \
  | jq -r '.SampledRequests[] | [.Action, .Request.Method, .Request.URI, (.RuleNameWithinRuleGroup // "-")] | @tsv' \
  | sort | uniq -c
}
echo "== SQLi rule group";   sampled lab14-sqli
echo "== Common rule group"; sampled lab14-common
```

*Kỳ vọng (minh họa, tên rule cụ thể có thể khác theo phiên bản rule group):*

```text
== SQLi rule group
   3 COUNT  GET   /orders     SQLi_QueryArguments
== Common rule group
   3 COUNT  POST  /orders     SizeRestrictions_BODY
   3 COUNT  GET   /download   GenericLFI_QUERYARGUMENTS
```

Phân loại kết quả vào `waf-report.md`:

| Request | Hợp lệ? | Rule đếm | Phân loại |
|---|---|---|---|
| sqli | Không | SQLi_QueryArguments | True positive |
| lfi | Không | GenericLFI_QUERYARGUMENTS | True positive |
| bigbody | **Có** | SizeRestrictions_BODY | **False positive** |
| normal, admin | Có | — | True negative |

Đối chiếu bằng log, nguồn đầy đủ hơn sampled requests:

```bash
q() {  # q <logs insights query> ; khoảng 1 giờ gần nhất
  local id; id=$(aws logs start-query --log-group-name aws-waf-logs-lab14 \
    --start-time $(( $(date +%s) - 3600 )) --end-time $(date +%s) --query-string "$1" --query queryId --output text)
  while :; do
    local r; r=$(aws logs get-query-results --query-id "$id" --output json)
    case $(jq -r .status <<< "$r") in
      Complete) jq -r '.results[] | map(.value) | @tsv' <<< "$r"; return ;;
      Failed|Cancelled|Timeout) echo "query lỗi"; return 1 ;;
    esac
    sleep 2
  done
}
q 'fields httpRequest.httpMethod as method, httpRequest.uri as uri, action
   | parse @message /"name":"(?<label>awswaf:managed:aws:[^"]+)"/
   | stats count(*) as n by action, method, uri, label | sort uri'
```

*Kỳ vọng:* request `POST /orders` có label kết thúc bằng `SizeRestrictions_Body` hoặc tương tự, và `action` = `ALLOW` vì đang ở chế độ count. Log có thể đến chậm vài phút so với request.

---

## Bước 7 — Block "nguyên bản" để thấy false positive, rồi tinh chỉnh

```bash
waf_apply() {  # waf_apply count|strict|tuned  — luôn lấy LockToken mới
  waf_rules "$1" > "rules-$1.json"
  local lock; lock=$(aws wafv2 get-web-acl --name lab14-acl --scope REGIONAL --id "$ACL_ID" --query LockToken --output text)
  aws wafv2 update-web-acl --name lab14-acl --scope REGIONAL --id "$ACL_ID" --lock-token "$lock" \
    --default-action Allow={} --rules "file://rules-$1.json" \
    --visibility-config SampledRequestsEnabled=true,CloudWatchMetricsEnabled=true,MetricName=lab14-acl >/dev/null \
  && echo "đã áp dụng: $1"
}
wait_sqli_blocked() {  # thay đổi WAF cần thời gian lan truyền
  for i in $(seq 1 24); do
    [ "$(curl -s -o /dev/null -w '%{http_code}' "http://${ALB_DNS}/orders?id=1%20UNION%20SELECT%20password%20FROM%20users--")" = 403 ] && { echo "đã có hiệu lực"; return; }
    sleep 5
  done; echo "sau 2 phút vẫn chưa thấy 403"
}

waf_apply strict && wait_sqli_blocked
traffic
```

*Kỳ vọng ở `strict` (minh họa), đây là **negative case** của tuning:*

```text
normal     200
bigbody    403   ← khách hàng hợp lệ bị chặn: false positive thành sự cố
admin      200
sqli       403
lfi        403
```

Tinh chỉnh ở phạm vi **nhỏ nhất**: chỉ ép một rule con về Count, giữ nguyên phần còn lại của rule group.

```bash
waf_apply tuned && sleep 60
traffic
```

*Kỳ vọng ở `tuned`:* `bigbody` trở lại `200`, còn `sqli` và `lfi` vẫn `403`.

Ghi vào `waf-report.md` cái giá phải trả: rule `SizeRestrictions_BODY` giờ chỉ đếm. Ngoài ra, ALB chỉ chuyển cho WAF phần đầu của body (8 KB), nên nội dung nằm sau giới hạn đó **không** được rule khác soi. Trong production, cách hẹp hơn là một rule tùy chỉnh dựa trên **label** `SizeRestrictions_Body`, chỉ cho phép body lớn ở đúng path `POST /orders` và chặn ở mọi path khác. Hãy tự viết rule đó làm bài tập mở rộng.

---

## Bước 8 — WAF không phải authentication

```bash
curl -s -w '  [%{http_code}]\n' "http://${ALB_DNS}/admin/orders"
```

*Kỳ vọng:* `lab14-ok  [200]`. WAF cho request **không có danh tính** đi qua `/admin`, vì nó chỉ soi mẫu tấn công chứ không biết ai được phép làm gì. Authentication và authorization phải nằm ở ứng dụng hoặc ở lớp xác thực như Cognito, ALB authenticate action hay JWT authorizer (Lab 09). WAF chỉ giảm bớt traffic xấu đi tới lớp đó.

Bạn có thể chặn `/admin` bằng rule WAF theo path, nhưng đó là **network control**, không phải phân quyền. Nó cũng sẽ chặn luôn admin hợp lệ.

---

## Bước 9 — Review sáu trụ cột cho hệ thống đơn hàng

Tạo `review.md`. Mỗi dòng phải có **bằng chứng** (lab nào, số đo nào), không chỉ "đã làm". Bảng dưới là ví dụ, hãy bổ sung ít nhất một rủi ro của riêng bạn cho mỗi trụ cột.

| Trụ cột | Câu hỏi review | Bằng chứng hiện có | Rủi ro / khoảng trống | Cải tiến | Ưu tiên |
|---|---|---|---|---|---|
| Operational excellence | Deploy và rollback có tự động, có runbook không? | Lab 12 (change set, rollback), Lab 13 (runbook restore đã chạy lại) | Chưa có deploy staging → prod có gate | Pipeline OIDC qua environment `prod` có reviewer | Cao |
| Security | Quyền tối thiểu, secret, log kiểm toán, lớp edge? | Lab 10 (KMS, Secrets Manager, CloudTrail), Lab 12 (OIDC), Lab 14 (SCP, WAF) | Dev và prod chung account; chưa có SCP thật | ADR-001; rollout SCP theo Bước 2.5 | Cao |
| Reliability | RTO/RPO đo được? Retry có kiểm soát? | Lab 13: RTO và RPO của restore đã đo; retry budget | Chưa có bản sao khác Region | ADR-003 | Trung bình |
| Performance efficiency | Có SLO và baseline trước khi tối ưu? | Lab 13: p95 với sample size; Lab 07: index giảm thời gian query | Chưa đo dưới tải đọc từ cache | Đo lại khi bật cache Lab 07 phần C | Thấp |
| Cost optimization | Ai sở hữu chi phí, có budget và tag không? | Lab 03 (budget), tag `Project` ở mọi lab | NAT gateway (Lab 08) và ALB tính phí cả khi idle | Tắt nonprod ngoài giờ; xem VPC endpoint thay NAT | Trung bình |
| Sustainability | Có dùng tài nguyên vừa đủ, dọn tài nguyên thừa không? | arm64/Graviton (Lab 08, 09), retention log ngắn, lifecycle S3 (Lab 06) | Môi trường nonprod chạy 24/7 | Scale-to-zero nonprod; giữ retention theo yêu cầu, không mặc định "vĩnh viễn" | Thấp |

Kết thúc `review.md` bằng **lộ trình cải tiến** gồm 3–5 mục. Mỗi mục có owner, thước đo hoàn thành và ADR liên quan nếu có.

---

## Bước 10 — Ba ADR

Mỗi ADR phải có ít nhất một phương án bị loại kèm lý do, và hệ quả có cả mặt xấu. Không dùng con số không có nguồn như "giảm 99% rủi ro".

```bash
cat > adr/ADR-001-multi-account.md <<'EOF'
# ADR-001: Tách dev/staging/prod thành account riêng trong AWS Organizations

- Trạng thái: Proposed
- Ngày: 2026-10-31
- Người quyết định: <owner>

## Bối cảnh
Hệ thống đơn hàng (lab 06–13) đang chạy dev và prod trong cùng một account. Load test ở dev dùng chung
quota Lambda concurrency và DynamoDB của account với prod; quyền IAM của dev đủ để sửa tài nguyên prod
nếu policy viết sai; chi phí chỉ tách được bằng tag.

## Quyết định
Dùng Organizations với cây OU trong design.md. Truy cập người qua IAM Identity Center; deploy qua OIDC
(ADR của Lab 12). SCP bảo vệ audit và giới hạn Region, rollout theo scp-test-matrix.md.

## Phương án đã cân nhắc
1. Một account, tách bằng VPC + tag + IAM condition: rẻ và đơn giản, nhưng quota và IAM vẫn chung;
   một policy sai là đủ chạm prod. Loại.
2. Control Tower ngay từ đầu: có baseline sẵn, nhưng thêm chi phí Config theo account × Region và
   cần thời gian học drift/lifecycle. Hoãn; xem lại khi > 5 account (xem control-tower-eval.md).

## Hệ quả
+ Ranh giới IAM và quota rõ ràng; chi phí theo account.
+ SCP đặt được trần quyền cho cả admin của member account.
- Thêm việc: networking giữa account, pipeline đa account, quản lý Identity Center.
- Chia sẻ tài nguyên giữa account (ECR, KMS) cần resource policy, dễ sai.
- Tách account KHÔNG tự chặn rò rỉ: trust policy cross-account viết lỏng vẫn mở đường từ dev sang prod.
EOF

cat > adr/ADR-002-waf-rollout.md <<'EOF'
# ADR-002: WAF managed rules đi qua giai đoạn count có tiêu chí thoát, rồi mới block

- Trạng thái: Proposed
- Ngày: 2026-10-31

## Bối cảnh
API đơn hàng nhận traffic công khai qua ALB. Lab 14 cho thấy rule giới hạn kích thước body chặn một đơn
hàng hợp lệ (false positive), trong khi rule SQLi/LFI bắt đúng request tấn công giả lập.

## Quyết định
1. Rule group mới chạy ở count với logging bật.
2. Thoát count khi: đủ lưu lượng thực đại diện (ít nhất một chu kỳ nghiệp vụ, ví dụ một tuần có ngày cao điểm)
   và mọi rule con có match đã được phân loại true/false positive trong log.
3. False positive xử lý ở phạm vi nhỏ nhất: override đúng rule con, hoặc scope-down theo path/label;
   không tắt cả rule group.
4. Theo dõi BlockedRequests theo rule sau khi bật block; có lệnh rollback về count trong runbook.

## Phương án đã cân nhắc
1. Block ngay: bảo vệ sớm, nhưng lab đã chứng minh chặn nhầm khách hàng hợp lệ. Loại.
2. Không dùng WAF, chỉ dựa vào validate input ở ứng dụng: vẫn bắt buộc phải làm, nhưng thiếu lớp chặn
   sớm và log tập trung cho traffic xấu. Không thay thế, làm song song.

## Hệ quả
+ Giảm rủi ro chặn nhầm khi bật block.
- Trong giai đoạn count, request tấn công vẫn đi tới ứng dụng — ứng dụng phải tự an toàn.
- Chi phí cố định web ACL + rule + log; phải đặt retention log.
- WAF không thay authentication/authorization (Lab 14 Bước 8).
EOF

cat > adr/ADR-003-dr.md <<'EOF'
# ADR-003: DR cho dữ liệu đơn hàng — backup & restore sang Region thứ hai, chưa dùng pilot light

- Trạng thái: Proposed
- Ngày: 2026-10-31

## Bối cảnh
Yêu cầu nghiệp vụ (giả định): RPO ≤ 24 giờ, RTO ≤ 8 giờ khi mất cả một Region; mất một AZ phải tự chịu được.
Lab 13 đo được: restore DynamoDB trong cùng Region mất <số đo của bạn> phút; PITR cho RPO ~ vài phút trong Region.
Multi-AZ đã có sẵn (DynamoDB, RDS Multi-AZ ở Lab 07).

## Quyết định
Dùng AWS Backup copy job sang Region thứ hai cho DynamoDB và RDS, hằng ngày. Hạ tầng dựng lại bằng IaC
(Lab 12). Drill restore khác Region mỗi quý, ghi số đo vào runbook.

## Phương án đã cân nhắc
1. Pilot light (DynamoDB global table, Aurora Global Database, hạ tầng tối thiểu ở Region 2): RPO giây–phút,
   RTO thấp hơn, nhưng chi phí replica chạy liên tục và vận hành phức tạp hơn. Vượt yêu cầu hiện tại; xem lại
   nếu RPO yêu cầu < 1 giờ.
2. Chỉ PITR trong cùng Region: rẻ nhất, nhưng không chịu được mất Region và không chống được xóa nhầm
   ở cấp account. Loại.

## Hệ quả
+ Đáp ứng yêu cầu với chi phí lưu trữ backup + phí copy liên Region.
- RTO phụ thuộc thời gian restore + dựng hạ tầng: CHƯA đo khác Region — drill đầu tiên là điều kiện để Accepted.
- Dữ liệu ghi sau bản copy gần nhất sẽ mất (đúng với RPO 24 giờ).
- Cần KMS key ở Region 2 và quyền copy; backup vault nên ở account riêng để chống xóa cùng lúc.
EOF
ls adr/
```

---

## Bước 11 — Dọn dẹp nhánh WAF (theo dependency)

Thứ tự dọn: logging config → disassociate → web ACL → log group → ALB → SG (chờ ENI của ALB biến mất) → subnet → route table → IGW → VPC.

```bash
aws wafv2 delete-logging-configuration --resource-arn "$ACL_ARN" 2>/dev/null
aws wafv2 disassociate-web-acl --resource-arn "$ALB_ARN" 2>/dev/null
sleep 10
LOCK=$(aws wafv2 get-web-acl --name lab14-acl --scope REGIONAL --id "$ACL_ID" --query LockToken --output text)
aws wafv2 delete-web-acl --name lab14-acl --scope REGIONAL --id "$ACL_ID" --lock-token "$LOCK"
aws logs delete-log-group --log-group-name aws-waf-logs-lab14 2>/dev/null

aws elbv2 delete-load-balancer --load-balancer-arn "$ALB_ARN"
aws elbv2 wait load-balancers-deleted --load-balancer-arns "$ALB_ARN"
for i in $(seq 1 20); do
  aws ec2 delete-security-group --group-id "$SG_ID" 2>/dev/null && { echo "đã xóa SG"; break; }
  sleep 15   # ENI của ALB cần thêm thời gian để giải phóng
done
aws ec2 delete-subnet --subnet-id "$SUB1"
aws ec2 delete-subnet --subnet-id "$SUB2"
aws ec2 delete-route-table --route-table-id "$RT_ID"
aws ec2 detach-internet-gateway --internet-gateway-id "$IGW_ID" --vpc-id "$VPC_ID"
aws ec2 delete-internet-gateway --internet-gateway-id "$IGW_ID"
aws ec2 delete-vpc --vpc-id "$VPC_ID"
```

*Negative case khi dọn:* nếu chạy `delete-web-acl` trước `disassociate-web-acl`, lệnh bị từ chối với `WAFAssociatedItemException`. Đó là lý do phải theo đúng thứ tự trên.

### Kiểm tra inventory sau cleanup

```bash
echo "== Web ACL"; aws wafv2 list-web-acls --scope REGIONAL --query "WebACLs[?starts_with(Name,'lab14')].Name" --output text
echo "== ALB";     aws elbv2 describe-load-balancers --query "LoadBalancers[?starts_with(LoadBalancerName,'lab14')].LoadBalancerName" --output text
echo "== VPC";     aws ec2 describe-vpcs --filters Name=tag:Project,Values=aws-lab-14 --query 'Vpcs[].VpcId' --output text
echo "== ENI";     aws ec2 describe-network-interfaces --filters Name=group-name,Values=lab14-alb-sg --query 'NetworkInterfaces[].NetworkInterfaceId' --output text
echo "== Log";     aws logs describe-log-groups --log-group-name-prefix aws-waf-logs-lab14 --query 'logGroups[].logGroupName' --output text
```

*Kỳ vọng:* mọi dòng đều rỗng. Phần thiết kế (Bước 1–3, 9–10) không tạo tài nguyên AWS nào. Tài nguyên được giữ lại có chủ đích là thư mục `~/aws-lab-14` trên máy bạn, $0.

### Nếu lab dừng giữa chừng hoặc mất shell

Tìm lại ID theo tên và tag, rồi chạy lại Bước 11. Lệnh nào báo "không tồn tại" (`WAFNonexistentItemException`, `LoadBalancerNotFound`, `InvalidVpcID.NotFound`...) thì nghĩa là bước đó đã xong.

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1"
read -r ACL_ARN ACL_ID <<< "$(aws wafv2 list-web-acls --scope REGIONAL \
  --query "WebACLs[?Name=='lab14-acl'] | [0].[ARN,Id]" --output text)"
ALB_ARN=$(aws elbv2 describe-load-balancers --names lab14-alb --query 'LoadBalancers[0].LoadBalancerArn' --output text 2>/dev/null)
VPC_ID=$(aws ec2 describe-vpcs --filters Name=tag:Project,Values=aws-lab-14 --query 'Vpcs[0].VpcId' --output text)
SG_ID=$(aws ec2 describe-security-groups --filters Name=vpc-id,Values="$VPC_ID" Name=group-name,Values=lab14-alb-sg --query 'SecurityGroups[0].GroupId' --output text)
SUB1=$(aws ec2 describe-subnets --filters Name=tag:Name,Values=lab14-public-a --query 'Subnets[0].SubnetId' --output text)
SUB2=$(aws ec2 describe-subnets --filters Name=tag:Name,Values=lab14-public-b --query 'Subnets[0].SubnetId' --output text)
RT_ID=$(aws ec2 describe-route-tables --filters Name=tag:Name,Values=lab14-rt --query 'RouteTables[0].RouteTableId' --output text)
IGW_ID=$(aws ec2 describe-internet-gateways --filters Name=attachment.vpc-id,Values="$VPC_ID" --query 'InternetGateways[0].InternetGatewayId' --output text)
echo "ACL=${ACL_ID} ALB=${ALB_ARN##*/} VPC=${VPC_ID} SG=${SG_ID} SUB=${SUB1},${SUB2} RT=${RT_ID} IGW=${IGW_ID}"
```

Giá trị nào là `None` thì bỏ qua lệnh xóa tương ứng. Nếu ALB đã bị xóa nhưng web ACL vẫn còn, `disassociate-web-acl` sẽ báo lỗi. Khi đó xóa web ACL trực tiếp, vì association đã mất cùng với ALB. Nếu `IGW_ID` là `None` nhưng vẫn còn IGW tag `lab14-igw` ở trạng thái detached, tìm nó bằng `Name=tag:Name,Values=lab14-igw`.

---

## Checklist hoàn thành

- [ ] `design.md`: mỗi account có owner và đường truy cập. Management account không chạy workload. Break-glass có cả cơ chế phát hiện.
- [ ] Hai SCP qua được `validate-policy` mà không có `ERROR`, và mỗi bản minified nhỏ hơn 5.120 ký tự.
- [ ] Test matrix có đủ 9 dòng với kết quả simulator thật, cộng bảng các trường hợp simulator không mô phỏng được.
- [ ] Giải thích được: SCP không cấp quyền; không áp dụng cho management account và service-linked role; có áp dụng cho root user của member account.
- [ ] Có kế hoạch rollout không bắt đầu ở Root, và biết cách gỡ khi bị lockout.
- [ ] `control-tower-eval.md` có nguồn, ngày đọc và một quyết định kèm điều kiện xem lại.
- [ ] (Tùy chọn) WAF: có bảng phân loại true/false positive từ chế độ count, thấy `bigbody` bị 403 ở `strict` và 200 ở `tuned`, còn `sqli`/`lfi` 403.
- [ ] (Tùy chọn) Chứng minh được `/admin` vẫn 200 khi có WAF.
- [ ] `review.md` đủ sáu trụ cột, có bằng chứng và lộ trình cải tiến.
- [ ] Ba ADR, mỗi cái có phương án bị loại và hệ quả xấu.
- [ ] Inventory sau cleanup rỗng.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| Bị `AccessDenied` sau khi bật guardrail | SCP ở OU này **hoặc OU cha** deny; hoặc OU thiếu `FullAWSAccess`; hoặc điều kiện ngoại lệ sai ARN | CloudTrail `errorMessage` có cụm "explicit deny in a service control policy" (hoặc "no service control policy allows"). Liệt kê SCP từng tầng bằng `organizations list-policies-for-target`. Sửa đúng statement đó, **không** gỡ cả SCP nếu không cần. Lockout toàn bộ: dùng management account (SCP không áp dụng) hoặc break-glass đã thiết kế. |
| Simulator trả `allowed` dù kỳ vọng `explicitDeny` | Thiếu hoặc sai context key, sai `--resource-arns`, hoặc `NotAction` miễn trừ action đó | Bỏ `--query` để xem `MatchedStatements`. So action với danh sách `NotAction`. |
| `validate-policy` báo `ERROR` | Sai cú pháp, action hoặc condition key không tồn tại | Đọc `findingDetails` và `locations`. Sửa rồi validate lại. |
| WAF chặn nhầm khách hàng hợp lệ | Một rule con match dữ liệu nghiệp vụ, như body lớn hay ký tự giống SQL trong ghi chú | Từ log hoặc sampled request, xác định `terminatingRuleId` và `RuleNameWithinRuleGroup`, rồi kiểm tra priority và scope. Override **đúng rule con** về Count hoặc scope-down theo path/label. Không chuyển cả rule group về count, và không tắt WAF. |
| `get-sampled-requests` trả rỗng | Sai `--rule-metric-name` (phải khớp `MetricName` của rule), time window quá 3 giờ, hoặc chưa đủ thời gian | Kiểm tra `MetricName` trong `rules-*.json`. Chờ vài phút. Dùng log làm nguồn chính. |
| `update-web-acl` báo `WAFOptimisticLockException` | LockToken đã cũ vì có lần update khác xen giữa | `waf_apply` luôn lấy token mới. Chạy lại. |
| Đổi sang block nhưng request tấn công vẫn 200 | Thay đổi chưa lan truyền, hoặc web ACL chưa được associate | `get-web-acl-for-resource`, rồi chờ `wait_sqli_blocked`. |
| Chi phí governance tăng | Config ghi configuration item ở nhiều account × Region; trail thêm data event; log không có retention | Cost Explorer lọc theo service và account (dữ liệu có độ trễ). Đếm governed Region. Kiểm tra event selector của trail. Đặt retention và lifecycle cho log. Giảm phạm vi theo chủ đích, **không** tắt Config hay trail do Control Tower quản lý (sẽ gây drift). |

---

## Tiếp theo

Đây là bài cuối của lộ trình. Bài tập cuối khóa: chọn một hệ thống, có thể là hệ thống đơn hàng, rồi nộp các phần sau.

- Sơ đồ kiến trúc.
- Kết quả happy path và failure case **có bằng chứng**, lấy từ các lab bạn đã chạy.
- Ít nhất một ADR có phương án thay thế.
- Ước tính chi phí.
- Checklist cleanup.

Bài làm được đánh giá bằng bằng chứng (số đo, output, log), không phải bằng việc đã bấm đủ bước.

Quay lại [README lộ trình](../../plans/README.md) để xem tổng quan và chọn chủ đề cần ôn lại.

---

## Nhánh Laravel theo lộ trình gốc

Lab dịch vụ ở trên được nối với [dự án Laravel + Vue](../15-capstone-laravel/README.md). Đọc phần bổ sung trong bài lý thuyết 14 và bảng mốc của dự án để chọn đúng prerequisite. Đầu ra bắt buộc gồm code/fixture, happy path, negative case và inventory sau cleanup; không dùng output minh họa làm bằng chứng đã chạy.

Làm thêm [bài triage GuardDuty/Security Hub](findings.md), có fixture và đáp án, không yêu cầu bật dịch vụ để làm đường chính.
