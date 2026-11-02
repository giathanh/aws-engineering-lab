# Bài 14 — Kiến trúc và quản trị nâng cao: AWS Organizations, SCP, Control Tower, AWS WAF và Well-Architected Framework

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 5–7 giờ, chia hai buổi. Buổi 1 gồm phần 1–3: multi-account, Organizations và SCP, Control Tower. Buổi 2 gồm phần 4–7: WAF, Well-Architected và ADR, cùng [lab thực hành](../labs/14-architecture-and-governance/README.md).
> **Kế hoạch gốc:** [plans/14-architecture-and-governance.md](../plans/14-architecture-and-governance.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Thiết kế **chiến lược multi-account**. Bạn sẽ biết khi nào tách account tốt hơn chỉ tách VPC (ranh giới IAM, quota, blast radius, chi phí), và biết cách tổ chức **Organizational Unit (OU)** theo môi trường và chức năng.
2. Dùng **AWS Organizations** và **Service Control Policy (SCP)**. Bạn sẽ hiểu SCP là trần quyền chứ không cấp quyền, SCP thừa kế qua OU, và các ngoại lệ của SCP (management account, service-linked role). Bạn cũng sẽ biết **delegated administration** là gì và cách thiết kế **break-glass** để tránh khóa quyền.
3. Đánh giá **AWS Control Tower**: landing zone, ba loại control (preventive, detective, proactive), vòng đời account, drift, chi phí, và những gì Control Tower **không** làm thay bạn.
4. Vận hành **AWS WAF**. Bạn sẽ phân biệt web ACL, rule, rule group và label, và biết quy trình tuning từ **Count** sang **Block** có tiêu chí rõ ràng. Bạn cũng sẽ đọc được sampled request và log, và hiểu vì sao WAF không thay authentication.
5. Review một kiến trúc theo **sáu trụ cột Well-Architected**, lập lộ trình cải tiến và viết **Architecture Decision Record (ADR)** có phương án thay thế.

---

## Phần 1 — Multi-account: vì sao một account thường không đủ (45 phút)

Khi mới bắt đầu, nhiều nhóm gom dev, staging và prod vào một account AWS, ngăn cách bằng VPC hoặc IAM group. Cách này chạy được cho tới khi một policy viết sai, một bài load test hay một lỗi cấu hình ở dev chạm vào prod.

```text
Ví dụ cây OU cho một tổ chức nhỏ:
                          ┌────────────────────────┐
                          │ Management account     │  (chỉ billing, Organizations, Identity Center;
                          │ (nằm ở Root)           │   KHÔNG chạy workload)
                          └───────────┬────────────┘
         ┌────────────────────────────┼────────────────────────────┐
         ▼                            ▼                            ▼
┌──────────────────┐         ┌──────────────────┐         ┌──────────────────┐
│ Security OU      │         │ Infrastructure OU│         │ Workloads OU     │
│ - Log Archive    │         │ - Network shared │         │ ├ NonProd OU     │
│ - Security       │         │   (TGW, DNS)     │         │ │  dev, staging  │
│   Tooling        │         │                  │         │ └ Prod OU        │
│   (delegated     │         │                  │         │    prod          │
│    admin)        │         │                  │         │                  │
└──────────────────┘         └──────────────────┘         └──────────────────┘
        + Sandbox OU (thử nghiệm, budget thấp)   + Suspended OU (account chờ đóng)
```

### Account là ranh giới gì?

| Ranh giới | Trong một account (tách bằng VPC/tag) | Tách account |
|---|---|---|
| IAM | Một policy `Resource: "*"` viết sai có thể chạm mọi môi trường | Principal của dev mặc định không có quyền gì ở prod. Quyền chéo phải được cấp rõ ràng qua trust policy hoặc resource policy. |
| Quota | Dev và prod dùng chung quota theo account và Region. Ví dụ Lambda concurrency mặc định 1.000, hay throttle mặc định của API Gateway. Phần lớn là soft quota tăng được, nhưng vẫn **dùng chung**. | Mỗi account có quota riêng |
| Blast radius | Credential lộ ở dev là đủ để thử với prod | Credential lộ chỉ trong account đó, **trừ khi** có trust cross-account viết lỏng |
| Chi phí | Chỉ tách được bằng tag, mà tag dễ thiếu | Consolidated billing vẫn là một hóa đơn, nhưng có chi tiết theo account |
| Guardrail | Chỉ có IAM | Có thêm SCP/RCP theo OU |

Tách VPC vẫn đủ khi: dự án nhỏ, một team, không có dữ liệu nhạy cảm, và bạn chấp nhận rủi ro dùng chung IAM và quota. Cái giá của multi-account là thêm networking giữa account, pipeline đa account, quản lý đăng nhập và chia sẻ tài nguyên qua resource policy.

---

## Phần 2 — AWS Organizations và Service Control Policy (55 phút)

**AWS Organizations** gom nhiều account vào một cây OU, có consolidated billing và các loại policy quản trị. Loại quan trọng nhất cho bảo mật là **SCP**.

```text
Quyền hiệu lực của một principal trong member account:
┌────────────────────────────────────────────────────────┐
│ SCP (mọi tầng từ Root → OU → account đều phải cho phép)│
│  ┌──────────────────────────────────────────────────┐  │
│  │ Identity policy (+ permissions boundary nếu có)  │  │
│  │  ┌────────────────────────────────────────────┐  │  │
│  │  │ QUYỀN HIỆU LỰC = giao của các lớp          │  │  │
│  │  │ (và không có Deny tường minh ở lớp nào)    │  │  │
│  │  └────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────┘
```

> [!IMPORTANT]
> **SCP không cấp quyền.** SCP chỉ đặt trần quyền (guardrail).
> - Request thành công khi identity policy (hoặc resource policy) cho phép, **và** SCP ở mọi tầng có Allow cho action đó, **và** không có Deny tường minh ở lớp nào.
> - `Allow` trong SCP không tự cho ai làm gì. Mặc định mọi OU có `FullAWSAccess` (Allow `*`). Đó là lý do chiến lược phổ biến là **deny list**: giữ `FullAWSAccess` rồi thêm các Deny.
> - Gỡ `FullAWSAccess` khỏi một OU mà không thay bằng Allow khác thì **mọi thứ** ở OU đó bị implicit deny. Đây là cách lockout dễ gây ra nhất.

### 1. Thừa kế và phạm vi áp dụng

- SCP gắn ở Root hoặc ở một OU áp dụng cho mọi OU con và mọi account bên dưới. Deny ở **bất kỳ** tầng nào cũng thắng, và tầng dưới không mở lại được.
- Với chiến lược **allow list** (gỡ `FullAWSAccess`, chỉ Allow vài dịch vụ), action phải được Allow ở **mọi tầng** từ Root tới account.
- SCP **có** áp dụng cho root user của member account. Đây là điểm khác với IAM policy thông thường.
- Mỗi SCP tối đa 5.120 ký tự, và số SCP gắn trên mỗi target có giới hạn. Kiểm tra quota hiện hành trước khi thiết kế nhiều SCP nhỏ.

### 2. Ngoại lệ theo tài liệu

| Trường hợp | SCP có tác dụng không? | Hệ quả thiết kế |
|---|---|---|
| User/role trong **management account** | Không | Management account là đường gỡ lockout, nhưng cũng là account nguy hiểm nhất. Cần ít người, MFA, không workload. |
| **Service-linked role** | Không | Không cần (và không nên) viết ngoại lệ SLR trong SCP |
| Principal từ **account khác** (ngoài org) truy cập resource của bạn | SCP của bạn không giới hạn họ | Dùng resource policy, hoặc **Resource Control Policy (RCP)** để đặt trần quyền trên resource |

### 3. Delegated administration

Nhiều dịch vụ bảo mật (GuardDuty, Security Hub, IAM Access Analyzer, Config, Firewall Manager...) cho phép **ủy quyền quản trị** cho một member account, thường là Security Tooling. Lợi ích là đội security làm việc hằng ngày mà không cần đăng nhập management account. Mục tiêu là **thu nhỏ** số người và số việc cần đến management account.

### 4. Break-glass

Break-glass là đường vào khẩn cấp khi đường bình thường hỏng, chẳng hạn khi IAM Identity Center hoặc IdP ngừng hoạt động, hoặc khi một SCP cấu hình sai.

- Một role, ví dụ `BreakGlassEmergencyAdminRole`, có trong mỗi account. Role này chỉ assume được từ vài principal định danh có MFA.
- Ngoại lệ trong SCP dựa theo ARN:
  ```json
  "Condition": {
    "ArnNotLike": {
      "aws:PrincipalArn": "arn:aws:iam::*:role/BreakGlassEmergencyAdminRole"
    }
  }
  ```
- **Bảo vệ chính role đó.** Nếu ai cũng tạo được role trùng tên, ngoại lệ trở thành cửa sau. SCP phải Deny `iam:CreateRole`, `UpdateAssumeRolePolicy`, `Attach/PutRolePolicy`... trên ARN của role này với mọi principal khác (xem lab Bước 2.1).
- **Phát hiện:** mỗi lần assume role break-glass phải gửi cảnh báo. Diễn tập định kỳ để biết đường này còn dùng được.

---

## Phần 3 — AWS Control Tower: landing zone, controls và vòng đời account (45 phút)

**Control Tower** dựng và duy trì một **landing zone**, tức một môi trường multi-account có baseline sẵn. Landing zone gồm Organizations với OU Security, account Log Archive và Audit, CloudTrail cấp organization, AWS Config, và tùy chọn IAM Identity Center.

```text
Control Tower (chạy từ management account, có "home Region")
 ├─ Organizations + OU Security (Log Archive, Audit)
 ├─ CloudTrail org trail ──▶ Log Archive
 ├─ AWS Config ở các "governed Region"
 ├─ Controls: preventive / detective / proactive
 └─ Account Factory (Service Catalog) hoặc AFT (Terraform)
      tạo account → đặt vào OU → áp baseline + controls của OU
```

### Ba loại control

| Loại | Cơ chế | Khi nào tác dụng | Ví dụ |
|---|---|---|---|
| Preventive | SCP; một số control dùng RCP hoặc declarative policy | Lúc gọi API: bị chặn | Không cho xóa log archive, không cho chạy ngoài Region |
| Detective | AWS Config rule | Sau khi tài nguyên đã tồn tại: đánh dấu `NON_COMPLIANT` | Security group mở port 22 ra `0.0.0.0/0` |
| Proactive | CloudFormation hook | Lúc CloudFormation provision tài nguyên: từ chối nếu vi phạm | Bucket S3 tạo bằng CloudFormation mà không bật mã hóa |

Proactive control chỉ thấy tài nguyên đi qua **CloudFormation**. Tài nguyên tạo bằng CLI/SDK hay Terraform gọi API trực tiếp thì không đi qua hook. Nếu muốn kiểm tra từ CI/CD, bạn phải tự chạy công cụ như `cfn-guard` hoặc `checkov` trong pipeline. Đó là việc riêng của bạn, không phải proactive control.

### Prerequisite, vòng đời và chi phí

- **Home Region** chọn lúc thiết lập và không đổi được sau đó. **Governed Region** là các Region Control Tower áp baseline; có thể chọn tập nhỏ.
- **Account sẵn có** có thể enroll, nhưng phải xử lý trước các xung đột như Config recorder hoặc trail đã tồn tại.
- **Drift:** sửa SCP, OU hay role do Control Tower quản lý từ bên ngoài sẽ làm landing zone hoặc account bị drift, và phải repair hoặc re-register.
- **Chi phí:** Control Tower không thu phí riêng, nhưng bạn trả cho dịch vụ nó bật. Lớn nhất thường là Config, tính theo configuration item và nhân với số account × governed Region. Ngoài ra còn S3, CloudWatch Logs, và KMS nếu dùng.

> [!WARNING]
> **Control Tower không bảo đảm compliance hay an toàn ứng dụng.** Nó cung cấp baseline quản trị: log tập trung, guardrail, account chuẩn hóa. Các việc sau vẫn là trách nhiệm của team theo shared responsibility model:
> - Đáp ứng một chuẩn cụ thể (PCI, ISO...).
> - Phân quyền trong ứng dụng, chống SQL injection, vá thư viện.
> - Review IAM của workload.
>
> "Mọi control đều xanh" chỉ có nghĩa là các control **đang bật** không phát hiện vi phạm.

---

## Phần 4 — Bảo vệ tầng ứng dụng với AWS WAF (50 phút)

Security group và network ACL làm việc ở tầng 3/4 (IP, port). Request chứa SQL injection gửi tới port 443 hợp lệ thì vẫn qua được chúng. **AWS WAF** soi request HTTP(S) ở tầng 7: URI, query string, header, body, IP.

WAF gắn được vào các resource như ALB, API Gateway **REST API**, CloudFront, AppSync, Cognito user pool, App Runner và Verified Access. Danh sách đầy đủ xem trong tài liệu. API Gateway **HTTP API** (Lab 09) **không** gắn web ACL trực tiếp được. Nếu cần WAF, đặt CloudFront phía trước.

```text
Request ─▶ Web ACL (đánh giá rule theo Priority, số nhỏ trước)
             P0  Rule group AWSManagedRulesSQLiRuleSet
             P1  Rule group AWSManagedRulesCommonRuleSet (CRS)
             P2  Rate-based rule (giới hạn theo IP hoặc khóa tùy chọn, trong cửa sổ đánh giá)
             ...
           Rule match với action Block/Allow ─▶ dừng ngay (terminating)
           Rule match với action Count       ─▶ ghi nhận + gắn label, đánh giá tiếp
           Không rule nào kết thúc           ─▶ Default action của web ACL (thường Allow)
             │
     Block ─▶ 403 (mặc định; có thể tùy chỉnh response)     Allow ─▶ ALB / API / CloudFront
```

### Khái niệm cần nắm

- **Web ACL:** tập rule có thứ tự, kèm default action. Mỗi web ACL có **WCU** (capacity unit). Vượt ngưỡng cơ bản thì phải trả thêm phí.
- **Rule group:** nhóm rule. **AWS Managed Rules** được AWS cập nhật; ví dụ CRS cho các mẫu tấn công phổ biến kiểu OWASP, và SQLi rule set. Một số rule group như Bot Control hay ATP có phí riêng.
- **Override:** đặt cả rule group về Count bằng `OverrideAction: Count`, hoặc chỉ một rule con bằng `RuleActionOverrides`.
- **Label:** rule match sẽ gắn label như `awswaf:managed:aws:core-rule-set:...`. Rule phía sau có thể dựa vào label để quyết định. Đây là cách tuning hẹp nhất.
- **Giới hạn soi body:** với ALB, WAF chỉ nhận phần đầu của body (8 KB). CRS có rule chặn body lớn hơn giới hạn đó (`SizeRestrictions_BODY`), và đây là nguồn false positive rất phổ biến với API upload hoặc ghi chú dài.
- **Quan sát:**
  - Metric CloudWatch theo rule (`AllowedRequests`, `BlockedRequests`, `CountedRequests`).
  - **Sampled requests:** chỉ là **mẫu**, trong khoảng tối đa 3 giờ gần nhất.
  - **Logging** đầy đủ tới CloudWatch Logs, S3 hoặc Firehose. Tên log group hoặc bucket bắt buộc có tiền tố `aws-waf-logs-`.

### Count trước, Block sau, với tiêu chí thoát

Bật mọi rule ở Block ngay ngày đầu thì khách hàng hợp lệ có thể bị chặn chỉ vì ghi chú dài, hoặc vì địa chỉ chứa chuỗi giống SQL như `1=1`.

1. **Count + logging:** rule chỉ ghi nhận và gắn label, request vẫn đi qua.
2. **Phân loại:** với mỗi rule con có match, dùng log để xác định đó là tấn công thật (true positive) hay dữ liệu nghiệp vụ (false positive). Theo dõi đủ một chu kỳ nghiệp vụ, ví dụ một tuần có ngày cao điểm, không chỉ vài giờ.
3. **Sửa ở phạm vi nhỏ nhất:** override đúng rule con về Count; thêm **scope-down statement** để rule group không áp cho một path cụ thể; hoặc viết rule dựa trên label (chỉ cho body lớn ở `POST /orders`).
4. **Block** khi mọi match còn lại là true positive. Theo dõi `BlockedRequests` theo rule, và giữ sẵn lệnh rollback về Count trong runbook.

> [!WARNING]
> **WAF không thay authentication, authorization hay việc sửa lỗ hổng ứng dụng.** WAF không biết ai được làm gì. Request không có token tới `/admin` vẫn đi qua nếu không khớp mẫu tấn công (lab Bước 8). WAF cũng có thể bị vượt bằng biến thể payload. Ứng dụng vẫn phải dùng parameterized query, validate input và kiểm tra quyền.

---

## Phần 5 — Review sáu trụ cột Well-Architected và ADR (50 phút)

Well-Architected Framework là bộ câu hỏi giúp tìm rủi ro một cách có hệ thống. Nó không phải checklist để "đạt điểm". Một bản review tốt cần ba thứ:

- **Bằng chứng**, chẳng hạn số đo, cấu hình hay runbook đã chạy.
- **Rủi ro** được xếp hạng.
- **Lộ trình cải tiến** có owner.

| Trụ cột | Câu hỏi then chốt | Bằng chứng ví dụ trong lộ trình |
|---|---|---|
| Operational excellence | Thay đổi có được deploy/rollback an toàn và lặp lại được không? Sự cố có runbook không? | Change set, rollback (Lab 12); runbook đã chạy lại (Lab 13) |
| Security | Quyền tối thiểu? Secret và mã hóa? Ai làm gì có được ghi lại không? | KMS, Secrets Manager, CloudTrail (Lab 10); OIDC (Lab 12); SCP, WAF (Lab 14) |
| Reliability | Chịu được mất AZ hay Region không? RTO/RPO **đo được** chưa? Retry có kiểm soát không? | Multi-AZ (Lab 07); restore drill, retry budget (Lab 13) |
| Performance efficiency | Có SLO và baseline trước khi tối ưu không? Chọn tài nguyên dựa trên số đo? | p95 kèm sample size (Lab 13); index (Lab 07) |
| Cost optimization | Ai sở hữu chi phí? Có budget, tag, và dọn tài nguyên idle không? | Budget (Lab 03); tag `Project`; cleanup có inventory |
| Sustainability | Có dùng vừa đủ tài nguyên, giảm tài nguyên idle và dữ liệu giữ không cần thiết không? | arm64/Graviton (Lab 08, 09); lifecycle S3 (Lab 06); retention log ngắn |

Các trụ cột thường **đánh đổi** lẫn nhau. Multi-Region tăng reliability nhưng tăng cost; WAF tăng security nhưng có thể làm hỏng trải nghiệm nếu tuning ẩu. Một kiến trúc an toàn hơn nhưng đắt hơn được chấp nhận khi rủi ro nó giảm có giá trị lớn hơn chi phí tăng thêm. Đánh giá giá trị đó theo xác suất × thiệt hại, yêu cầu pháp lý và cam kết với khách hàng. Quyết định đó phải được ghi lại trong ADR, kèm điều kiện để xem lại.

### Architecture Decision Record (ADR)

ADR ghi lại **vì sao** một quyết định được đưa ra, để người đến sau không phải đoán và không phải tranh luận lại từ đầu.

- **Tiêu đề:** quyết định ngắn gọn, ví dụ *ADR-004: Dùng DynamoDB thay Aurora cho dịch vụ giỏ hàng*.
- **Trạng thái:** Proposed → Accepted → Superseded (ghi rõ ADR nào thay thế).
- **Bối cảnh:** vấn đề, ràng buộc, số đo hiện có.
- **Quyết định:** cụ thể, kiểm chứng được.
- **Phương án đã cân nhắc:** cái bị loại và **lý do**.
- **Hệ quả:** cả tích cực lẫn đánh đổi, cộng điều kiện để xem lại.

Tránh con số không có nguồn như "giảm 99% rủi ro" hay "tiết kiệm 70%". Nếu chưa đo, ghi "chưa đo" và đặt việc đo làm điều kiện để chuyển sang Accepted.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 14)

Lab mặc định là **thiết kế** và chỉ gọi các API đọc, miễn phí. Lab không bật Organizations và không attach SCP. Bạn sẽ:

1. Thiết kế cây OU, account, owner, đường truy cập và break-glass trên giấy.
2. Viết hai SCP mẫu (bảo vệ audit và break-glass; giới hạn Region), rồi kiểm tra bằng IAM Access Analyzer `validate-policy`.
3. Chạy **test matrix** allowed/denied bằng IAM policy simulator, trong đó SCP được mô phỏng bằng permissions boundary. Ghi rõ những trường hợp simulator không mô phỏng được. Sau đó phân tích nguy cơ lockout và lập kế hoạch rollout.
4. Đánh giá Control Tower từ tài liệu.
5. *(Tùy chọn, có phí nhỏ)* Gắn WAF vào một ALB fixture, gửi traffic hợp lệ và tấn công giả lập ở chế độ Count, rồi phân loại false positive. Tiếp theo, bật Block để thấy false positive biến thành sự cố, sau đó tinh chỉnh một rule con. Cuối cùng chứng minh WAF không thay authentication.
6. Review sáu trụ cột và viết ba ADR, rồi dọn dẹp và kiểm tra inventory.

👉 Mở lab tại: **[labs/14-architecture-and-governance/README.md](../labs/14-architecture-and-governance/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Bị `AccessDenied` sau khi bật guardrail

- **Triệu chứng:** một lệnh vốn chạy được nay bị từ chối, dù identity policy vẫn cho phép.
- **Giả thuyết:**
  - Một SCP ở account, ở OU, hoặc ở **OU cha** có Deny.
  - OU thiếu `FullAWSAccess`.
  - Điều kiện ngoại lệ sai ARN hoặc sai condition operator.
- **Bằng chứng cần thu thập:**
  - Event CloudTrail tương ứng: `errorMessage` thường nêu rõ "explicit deny in a service control policy" hoặc "no service control policy allows".
  - Liệt kê SCP gắn ở từng tầng (`organizations list-policies-for-target` cho account và từng OU cha).
- **Sửa phạm vi nhỏ:**
  - Sửa đúng statement gây lỗi, rồi chạy lại test matrix.
  - Nếu lockout diện rộng, dùng management account (SCP không áp dụng ở đó) hoặc role break-glass đã thiết kế để detach hoặc sửa SCP.
  - **Không** gỡ cả bộ guardrail nếu không cần.

### 2. WAF chặn nhầm khách hàng hợp lệ

- **Triệu chứng:** khách hàng nhận 403 từ ALB hoặc CloudFront; ứng dụng không có log request đó.
- **Bằng chứng cần thu thập:**
  - Trong log WAF hoặc sampled request, tìm `terminatingRuleId`, rule con (`RuleNameWithinRuleGroup`) và label.
  - Xem phần nào của request đã match: body, query hay header.
  - Kiểm tra **priority** (có rule nào chặn trước rule bạn nghĩ không) và **scope** (web ACL có gắn nhầm vào resource khác không).
- **Sửa phạm vi nhỏ:**
  - Override đúng rule con về Count bằng `RuleActionOverrides`.
  - Hoặc thêm scope-down statement cho path cụ thể.
  - Hoặc viết rule dựa trên label (ví dụ chỉ cho body lớn ở `POST /orders`).
  - **Không** chuyển cả rule group về Count vĩnh viễn, và không tắt WAF.

### 3. Chi phí governance tăng sau khi bật landing zone

- **Nguyên nhân thường gặp:**
  - AWS Config ghi configuration item ở **mỗi account × mỗi governed Region**. Tài nguyên thay đổi nhiều (ví dụ autoscaling, ENI của Lambda trong VPC) sinh nhiều item.
  - Có người thêm **data event** vào trail. Control Tower không tự bật data event; org trail của nó ghi management event.
  - Log không có retention hoặc lifecycle.
- **Bằng chứng cần thu thập:**
  - Cost Explorer lọc theo service và linked account. Dữ liệu billing có độ trễ, nên đừng dùng nó làm bằng chứng duy nhất.
  - Đếm số governed Region.
  - Xem event selector của trail và kích thước, retention của log group/bucket.
- **Sửa phạm vi nhỏ:**
  - Bỏ các Region không dùng khỏi danh sách governed Region.
  - Đặt lifecycle cho bucket log, ví dụ chuyển sang lớp lưu trữ rẻ hơn sau một thời gian theo yêu cầu lưu giữ.
  - Gỡ data event không cần thiết.
  - **Không** tắt tay Config hoặc trail do Control Tower quản lý, vì sẽ gây drift.

---

## Câu hỏi tự kiểm tra

1. Khi nào tách account tốt hơn chỉ tách VPC? Nêu ít nhất bốn ranh giới mà account cung cấp và VPC thì không, cùng một trường hợp mà tách VPC là đủ.
2. IAM policy cho `Action: "*", Resource: "*"`, còn SCP trên OU có `Deny ec2:*`. Người dùng có tạo được EC2 không? Nếu thay Deny bằng một SCP chỉ có `Allow s3:*` và **gỡ** `FullAWSAccess` thì sao?
3. SCP có áp dụng cho management account, cho service-linked role, cho root user của member account không? Mỗi câu trả lời dẫn tới quyết định thiết kế gì?
4. Trình bày quy trình tuning WAF từ Count sang Block. Nêu tiêu chí để thoát giai đoạn Count, và cách sửa một false positive mà không tắt cả rule group.
5. ADR gồm những phần nào? Vì sao phần "phương án đã cân nhắc" quan trọng?
6. SCP `Allow` có cấp quyền gọi API không?
7. Làm sao triển khai một guardrail mới mà hạn chế nguy cơ lockout?
8. Một kiến trúc an toàn hơn nhưng đắt hơn có được chấp nhận không? Bạn dùng tiêu chí gì và ghi quyết định ở đâu?
9. Web ACL đã chặn được SQLi trên `/orders`. Vì sao endpoint `/admin` vẫn có thể bị truy cập trái phép?

---

## Tài liệu tham khảo chính thức

- [AWS Organizations User Guide](https://docs.aws.amazon.com/organizations/latest/userguide/), mục SCP, ví dụ SCP, delegated administrator
- [AWS Control Tower User Guide](https://docs.aws.amazon.com/controltower/latest/userguide/), mục controls, drift, governed Region
- [AWS WAF Developer Guide](https://docs.aws.amazon.com/waf/latest/developerguide/), mục managed rule groups, labels, logging, sampled requests, giới hạn soi body
- [IAM Access Analyzer policy validation](https://docs.aws.amazon.com/IAM/latest/UserGuide/access-analyzer-policy-validation.html)
- [AWS Well-Architected Framework](https://docs.aws.amazon.com/wellarchitected/latest/framework/)
- [Organizing Your AWS Environment Using Multiple Accounts](https://docs.aws.amazon.com/whitepapers/latest/organizing-your-aws-environment/organizing-your-aws-environment.html)
- [AWS Decision Guides](https://docs.aws.amazon.com/decision-guides/)

## Trạng thái kiểm chứng

- **Đã đối chiếu với tài liệu AWS ngày 2026-10-31:**
  - SCP: không cấp quyền, thừa kế qua OU, `FullAWSAccess`, giới hạn 5.120 ký tự, áp dụng cho root user của member account.
  - Các ngoại lệ: management account, service-linked role, principal ngoài org; RCP.
  - Ba loại control của Control Tower, home và governed Region, drift.
  - Managed rule groups của WAF, label, logging (tiền tố `aws-waf-logs-`), sampled requests, giới hạn soi body với ALB.
  - Sáu trụ cột Well-Architected.
- **Đã kiểm tra cục bộ:** cú pháp JSON của hai SCP và của bộ rule WAF sinh bằng `jq` trong lab.
- **Chưa chạy trên AWS:** lab chưa được chạy trên account thật. Output của simulator và WAF trong lab là minh họa; tên rule con và label có thể khác theo phiên bản rule group.
- **Chi phí:** phần thiết kế miễn phí. Nhánh WAF có phí (ALB, IPv4 public, web ACL, rule), ước tính dưới $0,15 nếu dọn ngay. Quên dọn một tháng tốn khoảng $32. Không có cam kết Free Tier. Giá là minh họa, tra lại trang pricing theo Region của bạn.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. **Account và VPC:**
   - Account cho ranh giới **IAM**: principal ở dev không có quyền ở prod nếu không được cấp rõ.
   - Account có **quota** riêng.
   - Account thu hẹp **blast radius** khi lộ credential.
   - Account có **chi phí** theo account và **SCP** theo OU.
   - Tách VPC là đủ khi dự án nhỏ, một team, không dữ liệu nhạy cảm và chấp nhận dùng chung IAM/quota.
   - Lưu ý: tách account không tự chặn mọi đường. Trust policy cross-account viết lỏng vẫn nối dev với prod.
2. **SCP và IAM:**
   - Trường hợp đầu: **không** tạo được, vì Deny tường minh trong SCP thắng mọi Allow.
   - Trường hợp sau: SCP chỉ có `Allow s3:*` và đã gỡ `FullAWSAccess`, nên EC2 bị **implicit deny**: không tầng SCP nào cho phép `ec2:*`. Người dùng vẫn chỉ làm được với S3 trong phạm vi IAM cho phép.
3. **Phạm vi áp dụng:**
   - Management account: SCP **không** áp dụng. Vì vậy cần ít người, MFA và không workload; đây cũng là đường gỡ lockout.
   - Service-linked role: SCP **không** áp dụng, nên không cần viết ngoại lệ.
   - Root user của member account: SCP **có** áp dụng, nên SCP là cách đặt trần cho cả root của member account.
4. **Tuning WAF:**
   - Quy trình: Count + logging → phân loại từng rule con có match → sửa hẹp → Block → theo dõi `BlockedRequests`, có sẵn rollback.
   - Tiêu chí thoát Count: đủ lưu lượng đại diện (ít nhất một chu kỳ nghiệp vụ), và mọi match còn lại là tấn công thật.
   - Sửa false positive: `RuleActionOverrides` cho đúng rule con, scope-down statement, hoặc rule dựa trên label.
   - Rủi ro nếu Block ngay: chặn khách hàng hợp lệ, gây gián đoạn kinh doanh.
5. **ADR:**
   - Các phần: tiêu đề, trạng thái, bối cảnh, quyết định, phương án đã cân nhắc, hệ quả.
   - Phần phương án cho người đến sau biết lựa chọn khác **đã được xem xét** và vì sao bị loại. Nhờ vậy họ không mở lại tranh luận cũ, và biết khi nào bối cảnh đổi thì nên xem lại.
6. **SCP Allow:** **không** cấp quyền. Nó chỉ xác định trần quyền. Quyền phải đến từ identity policy hoặc resource policy.
7. **Hạn chế lockout:**
   - Validate bằng `validate-policy` và chạy test matrix (simulator, rồi lệnh thật ở sandbox).
   - Rollout từ Sandbox → NonProd → Prod; **không** attach lần đầu ở Root.
   - Có ngoại lệ break-glass được bảo vệ và có cảnh báo.
   - Không gỡ `FullAWSAccess` khi dùng chiến lược deny list.
   - Theo dõi CloudTrail `AccessDenied` do SCP sau mỗi bước, và biết sẵn cách detach từ management account.
8. **An toàn hơn nhưng đắt hơn:**
   - Được chấp nhận khi rủi ro giảm đi (xác suất × thiệt hại, yêu cầu pháp lý, cam kết khách hàng) có giá trị lớn hơn chi phí tăng thêm, và không có phương án rẻ hơn đạt cùng mức giảm rủi ro.
   - Ghi trong ADR kèm phương án bị loại và điều kiện xem lại, ví dụ khi lưu lượng hoặc yêu cầu RPO thay đổi.
9. **WAF và `/admin`:**
   - WAF soi **mẫu tấn công**, không xác thực danh tính và không phân quyền.
   - Request hợp lệ về cú pháp nhưng không có quyền vẫn đi qua.
   - `/admin` phải được bảo vệ bằng authentication và authorization ở ứng dụng hoặc ở lớp xác thực (Cognito, ALB authenticate, JWT authorizer).

</details>

---

## Bổ sung — GuardDuty, Security Hub và ADR của dự án

GuardDuty tạo finding phát hiện dấu hiệu đe dọa từ các nguồn dữ liệu được bật; Security Hub CSPM tổng hợp findings/kiểm tra posture theo cấu hình. Chúng không thay IAM, network control hoặc authorization. Phạm vi account/Region, delegated admin và nguồn dữ liệu phải rõ; bật một dịch vụ ở một Region không chứng minh toàn tổ chức đã được bao phủ.

[Mini-lab finding](../labs/14-architecture-and-governance/findings.md) dùng dữ liệu giả để triage, không cần bật dịch vụ trả phí. Người học liên hệ resource ARN với owner, thời gian hoạt động, nguồn log và trạng thái kiểm chứng; severity của provider là đầu vào ưu tiên, không tự chứng minh exploit.

GuardDuty có chức năng sample findings để kiểm tra routing/filter; sample không chứng minh detector đã phát hiện một cuộc tấn công thật. Security Hub có RecordState/Workflow/Compliance khác mục đích; đóng workflow không tự sửa cấu hình resource. [GuardDuty sample](https://docs.aws.amazon.com/guardduty/latest/ug/sample_findings.html), [ASFF attributes](https://docs.aws.amazon.com/securityhub/latest/userguide/asff-top-level-attributes.html), đối chiếu 2026-11-01.

ADR dự án phải ghi: quyết định, phương án khác, bối cảnh tải/budget, evidence, failure mode còn lại và điều kiện xem xét lại. Đánh giá đủ sáu trụ cột Well-Architected; ví dụ một NAT giảm chi phí nhưng tạo điểm lỗi cho outbound, RDS Single-AZ tiết kiệm nhưng không đáp ứng AZ failover.

Bài tập: finding được chuyển RESOLVED có đủ đóng incident không? Đáp án: không; cần bằng chứng containment/remediation, kiểm tra lại tài nguyên và theo dõi tái diễn. Sample finding chỉ nghiệm thu luồng triage/routing.
