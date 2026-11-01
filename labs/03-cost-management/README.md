# Lab 03 — Quản lý chi phí: Thiết lập AWS Budgets và Rà soát tài nguyên ngầm

> **Lý thuyết:** [notes/03-cost-management.md](../../notes/03-cost-management.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 45–60 phút.
> **Ngày đối chiếu tài liệu:** 2026-10-31. Mọi output là **minh họa**, thông tin cá nhân dùng placeholder. Lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Lập bảng dự toán chi phí trước khi triển khai hệ thống | Hứa hẹn Free Tier hoặc một mức giá cố định |
| Truy vấn chi phí bằng Cost Explorer API (hoặc dùng dữ liệu minh họa) | Bật Budgets Actions (stop instance, gắn policy/SCP) |
| Tạo Cost Budget $10/tháng với ngưỡng Actual và Forecasted | Gửi email thông báo tới người khác ngoài chính bạn |
| Negative test: tạo budget trùng tên | Xóa tài nguyên không rõ chủ sở hữu khi rà soát |
| Rà soát tài nguyên nhàn rỗi (EBS không gắn, EIP không gắn, public IPv4) | Chờ 24h để xem billing thật như điều kiện bắt buộc |
| Dọn dẹp budget vừa tạo sau khi kiểm chứng | |

---

## Quy ước placeholder

| Placeholder / biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Tên named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `ACCOUNT_ID` | 12 số account ID (lấy tự động) | `111122223333` |
| `MY_EMAIL` | Email **của chính bạn** để nhận thông báo budget | `developer@example.com` |
| `REGION` | Region học tập của bạn | `ap-southeast-1` |
| `LabMonthlyCostBudget` | Tên budget được tạo trong lab | `LabMonthlyCostBudget` |

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 01](../01-foundations/README.md) và [Lab 02](../02-identity-and-access/README.md).
- [ ] Quyền cần thiết:
  - Budgets: `budgets:ModifyBudget` (tạo/xóa budget và notification), `budgets:ViewBudget`.
  - Cost Explorer (Bước 2, tùy chọn): `ce:GetCostAndUsage`.
  - Rà soát (Bước 6): `ec2:DescribeVolumes`, `ec2:DescribeAddresses`, `ec2:DescribeNetworkInterfaces`.
- [ ] Root user của account đã bật **"IAM user and role access to Billing information"**. Nếu account là member trong Organization, management account cũng phải cho phép member xem Cost Explorer.
- [ ] Nếu không có quyền billing: vẫn làm được Bước 1, Bước 2 (nhánh B) và Bước 6; đọc Bước 3–5 như bài thiết kế.

### Chi phí ước tính

- Budget chỉ có notification (không có action) **không tính phí**.
- Mỗi request tới Cost Explorer API tính khoảng **$0.01**. Lab gọi 1–3 lần.
- Các lệnh `describe-*` của EC2 không tính phí.

---

## Bước 1 — Lập bảng dự toán kiến trúc Order API

**Mục đích:** luyện thói quen ước tính *trước khi* tạo tài nguyên.

**Giả định kiến trúc** (chạy liên tục 730 giờ/tháng tại Region của bạn):
- 2x EC2 `t4g.micro` có public IPv4 (mỗi máy 1 IP).
- Mỗi instance gắn 1x EBS gp3 20 GB.
- 1x Application Load Balancer internet-facing trên 2 AZ (2 public IPv4), khoảng 3 triệu request/tháng (~1 LCU).
- Internet egress 50 GB/tháng.

**Việc cần làm:** mở [AWS Pricing Calculator](https://calculator.aws/) hoặc trang giá của từng dịch vụ, tra đơn giá **cho Region của bạn**, ghi ngày tra cứu, rồi điền bảng. **Không trừ Free Tier**, vì Free Tier phụ thuộc loại account và thời điểm.

| Thành phần | Đơn giá (bạn tra) | Công thức | Thành tiền |
|---|---|---|---|
| EC2 `t4g.micro` ×2 | `<giá/giờ>` | 2 × 730 × giá/giờ | |
| EBS gp3 40 GB | `<giá/GB-tháng>` | 40 × giá | |
| ALB (giờ) | `<giá/giờ>` | 730 × giá | |
| ALB (LCU) | `<giá/LCU-giờ>` | ~1 LCU × 730 × giá | |
| Public IPv4 ×4 | `<giá/IP-giờ>` | 4 × 730 × giá | |
| Data transfer out 50 GB | `<giá/GB>` | 50 × giá | |
| **Tổng** | | | |

<details>
<summary>Đáp án minh họa (giá tham khảo, có thể đã thay đổi)</summary>

| Thành phần | Đơn giá minh họa | Thành tiền |
|---|---|---|
| EC2 `t4g.micro` ×2 | ~$0.0106/giờ | ~$15.48 |
| EBS gp3 40 GB | ~$0.096/GB-tháng | ~$3.84 |
| ALB (giờ) | ~$0.0252/giờ | ~$18.40 |
| ALB (LCU) | ~$0.008/LCU-giờ | ~$5.84 |
| Public IPv4 ×4 | ~$0.005/IP-giờ | ~$14.60 |
| Data transfer out 50 GB | ~$0.12/GB | ~$6.00 |
| **Tổng** | | **~$64** |

**Nhận xét:**
- ALB (~$24) cộng 2 IP của nó (~$7) chiếm gần một nửa tổng chi phí. Với lab ngắn, chỉ bật ALB khi kiểm thử và xóa ngay sau buổi học.
- Public IPv4 (~$15) là khoản dễ bị quên nhất. Đặt instance trong private subnet giúp bỏ được IP của EC2, nhưng khi đó cần đường ra internet (NAT Gateway) hoặc VPC endpoint, và hai thứ này cũng tính phí (xem Bài 04).
- Compute (EC2) thực ra không phải khoản lớn nhất trong kiến trúc nhỏ này.

</details>

---

## Bước 2 — Khám phá dữ liệu chi phí với Cost Explorer

> [!NOTE]
> Account mới phải mở **Billing and Cost Management → Cost Explorer** trên console một lần để bật tính năng. Dữ liệu có thể mất đến 24 giờ mới sẵn sàng. Mỗi lệnh dưới đây tốn khoảng $0.01.

Truy vấn chi phí 7 ngày gần nhất, nhóm theo dịch vụ (`End` là ngày **không** tính vào kết quả):

```bash
# macOS
START=$(date -v-7d +%Y-%m-%d)
# Linux: START=$(date -d "7 days ago" +%Y-%m-%d)
END=$(date +%Y-%m-%d)

aws ce get-cost-and-usage \
  --time-period Start=${START},End=${END} \
  --granularity DAILY \
  --metrics "UnblendedCost" \
  --group-by Type=DIMENSION,Key=SERVICE \
  --profile aws-lab \
  --output json
```

### Nhánh A: Account có dữ liệu chi phí

Lệnh trả về mảng `ResultsByTime`, mỗi phần tử là một ngày, bên trong có `Groups` theo từng dịch vụ.

### Nhánh B: Account mới, chưa bật Cost Explorer, hoặc thiếu quyền

Bạn có thể gặp:
- `AccessDeniedException`: thiếu quyền `ce:GetCostAndUsage` hoặc account chưa bật truy cập billing cho IAM.
- `DataUnavailableException` hoặc thông báo chưa bật Cost Explorer: mở Cost Explorer trên console rồi chờ đến 24 giờ.
- Kết quả có `Groups` rỗng: account chưa phát sinh chi phí.

Trong trường hợp này, dùng **dữ liệu minh họa** dưới đây để đọc cấu trúc response (đã rút gọn còn 1 ngày):

```json
{
  "GroupDefinitions": [
    { "Type": "DIMENSION", "Key": "SERVICE" }
  ],
  "ResultsByTime": [
    {
      "TimePeriod": { "Start": "2026-10-30", "End": "2026-10-31" },
      "Total": {},
      "Groups": [
        {
          "Keys": ["Amazon Elastic Compute Cloud - Compute"],
          "Metrics": { "UnblendedCost": { "Amount": "0.2074", "Unit": "USD" } }
        },
        {
          "Keys": ["Amazon Virtual Private Cloud"],
          "Metrics": { "UnblendedCost": { "Amount": "0.1200", "Unit": "USD" } }
        }
      ],
      "Estimated": true
    }
  ],
  "DimensionValueAttributes": []
}
```

**Câu hỏi đọc dữ liệu:** dòng "Amazon Virtual Private Cloud" ở trên nhiều khả năng là khoản gì, dù bạn không tạo NAT Gateway? (Gợi ý: xem bảng Data Transfer trong lý thuyết.) `"Estimated": true` nghĩa là gì?

<details>
<summary>Đáp án</summary>

- Phí **public IPv4** được tính dưới dịch vụ "Amazon Virtual Private Cloud" (usage type dạng `PublicIPv4:InUseAddress`). $0.12/ngày ≈ 1 public IP chạy 24 giờ.
- `Estimated: true` nghĩa là số liệu của khoảng thời gian đó chưa chốt và còn có thể thay đổi khi AWS xử lý xong dữ liệu billing.

</details>

---

## Bước 3 — Tạo định nghĩa Budget (JSON)

Budget dùng cho việc học:
- Giới hạn: **$10.00 USD / tháng**, loại `COST`, chu kỳ `MONTHLY`.
- Ba ngưỡng thông báo:
  - `ACTUAL` > 50% ($5): cảnh báo sớm, hoạt động ngay cả với account mới.
  - `ACTUAL` > 100% ($10).
  - `FORECASTED` > 80% ($8): chỉ hoạt động khi account có khoảng 5 tuần lịch sử chi phí.

```bash
export MY_EMAIL="<email-cua-ban>"   # chỉ dùng email của chính bạn
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text --profile aws-lab)
export LAB_DIR=$(mktemp -d)

cat <<'EOF' > "${LAB_DIR}/budget.json"
{
  "BudgetName": "LabMonthlyCostBudget",
  "BudgetLimit": { "Amount": "10.0", "Unit": "USD" },
  "TimeUnit": "MONTHLY",
  "BudgetType": "COST"
}
EOF

cat <<EOF > "${LAB_DIR}/notifications.json"
[
  {
    "Notification": {
      "NotificationType": "ACTUAL",
      "ComparisonOperator": "GREATER_THAN",
      "Threshold": 50.0,
      "ThresholdType": "PERCENTAGE"
    },
    "Subscribers": [{ "SubscriptionType": "EMAIL", "Address": "${MY_EMAIL}" }]
  },
  {
    "Notification": {
      "NotificationType": "ACTUAL",
      "ComparisonOperator": "GREATER_THAN",
      "Threshold": 100.0,
      "ThresholdType": "PERCENTAGE"
    },
    "Subscribers": [{ "SubscriptionType": "EMAIL", "Address": "${MY_EMAIL}" }]
  },
  {
    "Notification": {
      "NotificationType": "FORECASTED",
      "ComparisonOperator": "GREATER_THAN",
      "Threshold": 80.0,
      "ThresholdType": "PERCENTAGE"
    },
    "Subscribers": [{ "SubscriptionType": "EMAIL", "Address": "${MY_EMAIL}" }]
  }
]
EOF

grep -c "${MY_EMAIL}" "${LAB_DIR}/notifications.json"   # mong đợi: 3
```

`budget.json` bỏ qua `CostTypes` để dùng giá trị mặc định (unblended, có thuế, có subscription, không trừ credit/refund). Heredoc của `budget.json` đặt trong nháy (`'EOF'`) vì không cần thay biến; `notifications.json` thì không, để shell điền `${MY_EMAIL}`.

---

## Bước 4 — Tạo Budget bằng CLI

```bash
aws budgets create-budget \
  --account-id "${ACCOUNT_ID}" \
  --budget "file://${LAB_DIR}/budget.json" \
  --notifications-with-subscribers "file://${LAB_DIR}/notifications.json" \
  --profile aws-lab
echo "exit code: $?"
```

Kết quả mong đợi: không in gì và `exit code: 0`.

---

## Bước 5 — Xác minh Budget và negative test

### Xem budget vừa tạo

```bash
aws budgets describe-budget \
  --account-id "${ACCOUNT_ID}" \
  --budget-name "LabMonthlyCostBudget" \
  --profile aws-lab \
  --query 'Budget.{Name:BudgetName,Limit:BudgetLimit,Spend:CalculatedSpend}' \
  --output json
```

Output minh họa (account mới):

```json
{
    "Name": "LabMonthlyCostBudget",
    "Limit": { "Amount": "10.0", "Unit": "USD" },
    "Spend": {
        "ActualSpend": { "Amount": "0.0", "Unit": "USD" }
    }
}
```

`ForecastedSpend` có thể không xuất hiện, hoặc xuất hiện sau vài giờ. Account chưa đủ lịch sử thì sẽ không có forecast.

### Xem các ngưỡng thông báo

```bash
aws budgets describe-notifications-for-budget \
  --account-id "${ACCOUNT_ID}" \
  --budget-name "LabMonthlyCostBudget" \
  --profile aws-lab \
  --query 'Notifications[].[NotificationType,Threshold]' \
  --output table
```

Kết quả mong đợi: 3 dòng `ACTUAL 50.0`, `ACTUAL 100.0`, `FORECASTED 80.0`.

### Negative test: tạo budget trùng tên

```bash
aws budgets create-budget \
  --account-id "${ACCOUNT_ID}" \
  --budget "file://${LAB_DIR}/budget.json" \
  --profile aws-lab
```

Kết quả mong đợi:
```text
An error occurred (DuplicateRecordException) when calling the CreateBudget operation: ...
```
✅ Tên budget là duy nhất trong account. Đây cũng là lỗi bạn sẽ gặp nếu chạy lại lab khi chưa cleanup.

> [!NOTE]
> AWS **không** gửi email xác nhận khi tạo budget. Email chỉ đến khi chi phí vượt ngưỡng, và có thể trễ vài giờ vì Budgets không cập nhật theo thời gian thực.

---

## Bước 6 — Rà soát tài nguyên ngầm (Cost Auditing)

```bash
export REGION="ap-southeast-1"   # lặp lại bước này cho mọi Region bạn đã dùng
```

### 1. EBS volume không gắn vào instance nào

```bash
aws ec2 describe-volumes \
  --region "${REGION}" \
  --filters Name=status,Values=available \
  --query "Volumes[*].{VolumeId:VolumeId,Size:Size,Created:CreateTime,Tags:Tags}" \
  --profile aws-lab \
  --output table
```

### 2. Elastic IP chưa được gắn

```bash
aws ec2 describe-addresses \
  --region "${REGION}" \
  --query "Addresses[?AssociationId==null].{AllocationId:AllocationId,PublicIp:PublicIp,Tags:Tags}" \
  --profile aws-lab \
  --output table
```

Lọc theo `AssociationId` chứ không theo `InstanceId`, vì EIP gắn vào NAT Gateway hoặc ENI không có `InstanceId` nhưng vẫn đang được sử dụng.

### 3. Toàn bộ public IPv4 đang tính phí

```bash
aws ec2 describe-network-interfaces \
  --region "${REGION}" \
  --query "NetworkInterfaces[?Association.PublicIp!=null].{ENI:NetworkInterfaceId,PublicIp:Association.PublicIp,Type:InterfaceType,Desc:Description}" \
  --profile aws-lab \
  --output table
```

Danh sách này gồm IP của EC2, ALB, NAT Gateway... Mỗi dòng là khoảng $3.6/tháng.

> [!WARNING]
> **Không xóa ngay** những gì tìm được. Trước khi `delete-volume` hay `release-address`, kiểm tra tag/tên để chắc chắn tài nguyên do bạn tạo cho lab. Volume có thể chứa dữ liệu cần giữ (tạo snapshot trước nếu không chắc). Trên account dùng chung, hỏi chủ sở hữu.

Với tài nguyên chắc chắn của bạn và không cần nữa:

```bash
aws ec2 delete-volume --volume-id <vol-id> --region "${REGION}" --profile aws-lab
aws ec2 release-address --allocation-id <alloc-id> --region "${REGION}" --profile aws-lab
```

---

## Bước 7 (tùy chọn) — So sánh ước tính với thực tế

Sau khi làm Lab 05 (có ALB và EC2), chờ ít nhất 24 giờ rồi chạy lại Bước 2 với `--group-by Type=DIMENSION,Key=USAGE_TYPE` cho ngày làm lab. So sánh với bảng ước tính của Lab 05: khoản nào lệch nhiều nhất và vì sao (thời gian chạy thực tế, public IPv4, data transfer...)?

Nếu bạn giữ budget này qua tháng, đây cũng là lúc kiểm tra xem email `ACTUAL 50%` có đến không.

---

## Bước 8 — Dọn dẹp (Cleanup)

Nếu muốn giữ budget làm "lưới an toàn" cho các lab sau, **có thể bỏ qua bước xóa**: budget notification-only không tính phí. Ghi nhận đây là tài nguyên giữ lại có chủ đích.

Nếu không giữ:

```bash
aws budgets delete-budget \
  --account-id "${ACCOUNT_ID}" \
  --budget-name "LabMonthlyCostBudget" \
  --profile aws-lab

rm -rf "${LAB_DIR}"
```

Xóa budget cũng xóa luôn các notification và subscriber của nó. Xác minh:

```bash
aws budgets describe-budget \
  --account-id "${ACCOUNT_ID}" \
  --budget-name "LabMonthlyCostBudget" \
  --profile aws-lab
# Mong đợi: An error occurred (NotFoundException) ...
```

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export ACCOUNT_ID=$(aws sts get-caller-identity --query Account --output text --profile aws-lab)
aws budgets describe-budgets --account-id "${ACCOUNT_ID}" --profile aws-lab \
  --query 'Budgets[].BudgetName' --output text
```

Nếu thấy `LabMonthlyCostBudget`, chạy lệnh `delete-budget` ở trên.

---

## Checklist hoàn thành

- [ ] Lập bảng dự toán với giá tra cứu cho Region của mình, không trừ Free Tier, có public IPv4.
- [ ] Hiểu 4 chiều chi phí: Compute, Storage, Request và Data Transfer.
- [ ] Đọc được cấu trúc `ResultsByTime` → `Groups` → `Metrics` của `aws ce get-cost-and-usage`.
- [ ] Tạo được budget với 2 ngưỡng `ACTUAL` và 1 ngưỡng `FORECASTED`; giải thích được vì sao `FORECASTED` có thể chưa hoạt động.
- [ ] Gặp `DuplicateRecordException` ở negative test.
- [ ] Rà soát được EBS `available`, EIP chưa gắn và toàn bộ public IPv4 trong Region.
- [ ] Xóa budget (hoặc ghi rõ lý do giữ lại).

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Khắc phục |
|---|---|---|
| `AccessDeniedException` khi gọi Budgets/CE API | Thiếu quyền `budgets:*`/`ce:*`, hoặc root của account chưa bật IAM access to billing | Kiểm tra ARN bằng `get-caller-identity`; nhờ quản trị cấp đúng quyền billing hoặc nhờ root user bật cờ. Không xin `AdministratorAccess` |
| `create-budget` báo lỗi validation | JSON sai cú pháp, hoặc `Amount` không phải chuỗi | Chạy `python3 -m json.tool "${LAB_DIR}/budget.json"`; `Amount` phải là chuỗi `"10.0"` |
| Email chứa `${MY_EMAIL}` nguyên văn hoặc rỗng | Biến chưa export trước khi tạo file | `echo "${MY_EMAIL}"`, export rồi tạo lại `notifications.json` |
| Không nhận email cảnh báo | Chưa vượt ngưỡng, dữ liệu billing trễ, account chưa đủ lịch sử để forecast, hoặc email vào Spam | Kiểm tra `CalculatedSpend`; dựa vào ngưỡng `ACTUAL`, không dựa vào `FORECASTED` với account mới |
| `DuplicateRecordException` | Budget cùng tên đã tồn tại (chạy lại lab) | Xóa budget cũ hoặc dùng tên khác |
| Cost Explorer trả về rỗng | Account mới, chưa bật Cost Explorer, hoặc chưa có chi phí | Dùng nhánh B; chờ đến 24 giờ sau khi mở Cost Explorer lần đầu |

## Tiếp theo

Bạn đã có công cụ theo dõi ngân sách. Bài tiếp theo bắt đầu thiết kế hạ tầng mạng của mọi hệ thống AWS: **VPC, Subnets, Route Tables, Internet Gateway và Security Groups**.
