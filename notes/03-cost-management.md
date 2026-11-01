# Bài 03 — Quản lý chi phí: AWS Budgets và Cost Explorer

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 2–3 giờ. Buổi 1 gồm phần 1–5 (lý thuyết chi phí & phân bổ). Buổi 2 gồm phần 6–7 và [lab thực hành](../labs/03-cost-management/README.md).
> **Kế hoạch gốc:** [plans/03-cost-management.md](../plans/03-cost-management.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Phân tích các chiều chi phí cốt lõi của ứng dụng cloud: Compute, Storage, Request/API và Data Transfer.
2. Hiểu cấu trúc thanh toán: quan hệ giữa Management Account (Payer) và Member Account trong AWS Organizations.
3. Thiết lập chiến lược gắn thẻ (Tagging) và kích hoạt **Cost Allocation Tags** để theo dõi chi phí theo môi trường, dự án và chủ sở hữu.
4. Sử dụng thành thạo **AWS Cost Explorer** để lọc (filter), nhóm (group by), xem chi phí thực tế (actual) và dự báo xu hướng (forecast).
5. Thiết lập **AWS Budgets** với các ngưỡng cảnh báo sớm (Actual & Forecasted thresholds) qua Email/SNS; phân biệt thông báo cảnh báo với Budgets Actions.
6. Hình thành tư duy "ước tính trước khi tạo tài nguyên" (Pre-lab estimation) và kỹ năng rà soát tài nguyên ngầm (hidden idle costs) để dọn dẹp triệt để.

---

## Phần 1 — Các chiều chi phí trên AWS (30 phút)

Khi chuyển từ on-premises lên cloud, mô hình tài chính chuyển từ **CapEx** (chi phí đầu tư mua sắm thiết bị ban đầu) sang **OpEx** (chi phí vận hành tính theo mức sử dụng thực tế). Bất kỳ dịch vụ nào trên AWS cũng được cấu thành từ 4 chiều chi phí chính:

```text
               ┌─────────────────────────────────────────┐
               │         4 CHIỀU CHI PHÍ CỐT LÕI         │
               └────────────────────┬────────────────────┘
                                    │
    ┌───────────────────┬───────────┴───────────┬───────────────────┐
    ▼                   ▼                       ▼                   ▼
┌───────────────┐ ┌───────────────┐     ┌───────────────┐   ┌───────────────┐
│    Compute    │ │    Storage    │     │    Request    │   │ Data Transfer │
│  (vCPU, RAM,  │ │  (GB/tháng,   │     │  (API calls,  │   │   (Egress,    │
│  giờ hoạt động│ │ IOPS, Backup) │     │  HTTP status) │   │   Inter-AZ)   │
└───────────────┘ └───────────────┘     └───────────────┘   └───────────────┘
```

### 1. Compute (Máy chủ tính toán)
- **Đơn vị tính:** Tính theo giây hoặc theo giờ máy chạy (Instance hours / vCPU hours).
- **Yếu tố ảnh hưởng:** Kích thước instance (số vCPU, dung lượng RAM), họ kiến trúc (x86 vs AWS Graviton arm64 thường rẻ hơn tới 20%), và mô hình mua sắm (On-Demand linh hoạt, Spot Instances giảm tới 90% nhưng có thể bị thu hồi, Savings Plans / Reserved Instances cam kết 1–3 năm).
- *Công thức:* chi phí/tháng ≈ giá On-Demand theo giờ × 730 giờ. Giá khác nhau theo Region và thay đổi theo thời gian: luôn tra [trang giá EC2](https://aws.amazon.com/ec2/pricing/on-demand/) hoặc [Pricing Calculator](https://calculator.aws/) cho đúng Region của bạn trước khi ước tính.

> [!NOTE]
> Các con số giá trong bài là **giá minh họa** để luyện cách tính, không phải báo giá. Cùng một instance type có thể đắt hơn 20–30% ở Singapore so với `us-east-1`.

### 2. Storage (Lưu trữ khối lượng và tệp)
- **Đơn vị tính:** GB-tháng (GB-month), IOPS dự phòng (Provisioned IOPS), và throughput băng thông.
- **Điểm cần lưu ý:**
  - Ổ đĩa **EBS Volume** gắn vào EC2 được tính tiền **ngay cả khi EC2 bị STOPPED**. Miễn là volume còn tồn tại, AWS vẫn phải giữ block dung lượng vật lý cho bạn.
  - Snapshot của EBS hay backup của RDS lưu trên S3 cũng tính phí theo dung lượng lưu trữ gia tăng tích lũy.

### 3. Request / API Calls (Yêu cầu xử lý)
- **Đơn vị tính:** Số lượt gọi API (per 1,000 hoặc 1,000,000 requests).
- **Ví dụ:** S3 tính phí cho nhóm `PUT`, `COPY`, `POST`, `LIST` đắt hơn nhóm `GET`. Ngay cả API quản lý cũng có thể tính phí: mỗi request tới Cost Explorer API (`aws ce ...`) tốn khoảng $0.01. DynamoDB tính phí theo Read/Write Capacity Unit (RCU/WCU) hoặc On-Demand Request Units.

### 4. Data Transfer (Lưu lượng mạng) — "Kẻ vô hình" nguy hiểm nhất
Lưu lượng mạng thường là nguồn gốc của các hóa đơn bất ngờ nếu lập trình viên không hiểu rõ kiến trúc:

| Loại lưu lượng | Quy tắc tính phí điển hình (giá minh họa) |
|---|---|
| **Data Inbound** (từ Internet vào AWS) | Thường **miễn phí**. |
| **Data Outbound** (Internet egress, từ AWS ra internet) | **Có phí** theo GB, bậc thang giảm dần; cỡ ~$0.09–0.12/GB tùy Region. |
| **Inter-AZ** (giữa 2 AZ trong cùng Region) | **Có phí cả 2 chiều** (cỡ ~$0.01/GB mỗi chiều). |
| **Intra-AZ** (trong cùng 1 AZ qua private IP) | **Miễn phí**. |
| **NAT Gateway** | Phí theo giờ tồn tại + phí theo GB dữ liệu xử lý (cỡ ~$0.05–0.06 mỗi loại), **cộng thêm** phí transfer thông thường. |
| **Public IPv4** | Từ 02/2024, **mọi** public IPv4 (EIP, auto-assigned public IP, IP của ALB/NAT) tính phí theo giờ (cỡ ~$0.005/giờ/IP, tức ~$3.6/tháng), **dù đang gắn hay không**. |

---

## Phần 2 — Phạm vi thanh toán, Tags và Cost Allocation Tags (35 phút)

### Cấu trúc AWS Organizations và Phân quyền Billing

Trong môi trường chuyên nghiệp, tài khoản AWS thường nằm trong một **AWS Organization**:
- **Management Account (Payer Account):** Tài khoản gốc, nhận hóa đơn tổng thể (Consolidated Billing) của toàn bộ doanh nghiệp.
- **Member Accounts (Linked Accounts):** Tài khoản độc lập dùng cho môi trường (Dev, Staging, Prod) hoặc team dự án.
- **Phân quyền truy cập Billing:** IAM user/role muốn xem Billing và Cost Explorer cần **cả hai điều kiện**:
  1. Root user **của chính account đó** đã bật **"IAM user and role access to Billing information"** (trong Account settings). Mỗi account bật riêng.
  2. Identity có quyền tương ứng: `ce:*` cho Cost Explorer, `budgets:*` cho Budgets, các action `billing:`/`account:` cho trang Billing.
- **Phạm vi dữ liệu:** Management account thấy chi phí của toàn Organization. Member account mặc định chỉ thấy chi phí của chính mình, và management account có thể tắt quyền xem Cost Explorer của member account.

### Chiến lược Tagging và Cost Allocation Tags

Một hệ thống có hàng ngàn tài nguyên (EC2, S3, RDS, Lambda). Nếu không gắn nhãn, cuối tháng bạn chỉ nhận được một con số chung: *"Tổng tiền EC2 là \$5,000"*, mà không thể biết microservice nào hay team nào tiêu tốn nhiều nhất.

```text
Tài nguyên AWS (EC2 / S3 / DynamoDB)
    │
    ├── Tag: Environment = "staging"
    ├── Tag: Service     = "order-api"
    └── Tag: Owner       = "team-backend"
```

AWS chia thẻ chi phí làm hai loại:
1. **AWS-Generated Cost Allocation Tags:** Bắt đầu bằng tiền tố `aws:`. Ví dụ: `aws:createdBy` (cho biết IAM user/role nào đã tạo ra tài nguyên).
2. **User-Defined Cost Allocation Tags:** Do lập trình viên tự quy định (ví dụ: `Environment`, `Project`, `CostCenter`).

> [!IMPORTANT]
> **Quy tắc vàng về kích hoạt Thẻ chi phí:**
> - Gắn tag vào tài nguyên **chưa đủ** để xem trên báo cáo chi phí. Bạn bắt buộc phải vào **Billing Console → Cost Allocation Tags** và nhấn **Activate** cho các key tag mong muốn.
> - Chỉ **management account** (hoặc account độc lập) mới kích hoạt được cost allocation tag.
> - **Độ trễ:** tag key mới thường mất đến 24 giờ mới xuất hiện trong danh sách để kích hoạt, và thêm đến 24 giờ sau khi kích hoạt mới dùng được trong Cost Explorer.
> - **Hồi tố có điều kiện:** mặc định, chi phí trước thời điểm kích hoạt **không** được gắn nhãn. Bạn có thể yêu cầu **backfill** tối đa 12 tháng trước trong trang Cost Allocation Tags, nhưng backfill chỉ gắn được chi phí của tài nguyên **đã mang tag đó vào thời điểm phát sinh chi phí**. Gắn tag muộn cho tài nguyên thì không backfill được phần quá khứ.

---

## Phần 3 — AWS Cost Explorer: Phân tích và Dự báo (35 phút)

**AWS Cost Explorer** là công cụ trực quan hóa chi phí và mức sử dụng với giao diện đồ họa mạnh mẽ.

```text
┌────────────────────────────────────────────────────────────────────────┐
│ AWS Cost Explorer                                                      │
│ Range: Last 3 Months | Granularity: Monthly | Group By: Service        │
├────────────────────────────────────────────────────────────────────────┤
│  ($)                                                                   │
│ 100 │                 ┌───┐                                            │
│  80 │                 │   │                                            │
│  60 │         ┌───┐   │   │     ┌ ─ ─ ┐ ◄─── Forecast (Dự báo cuối kỳ) │
│  40 │ ┌───┐   │   │   │   │     │     │                            │
│  20 │ │   │   │   │   │   │     │     │                            │
│   0 └─┴───┴───┴───┴───┴───┴─────┴─ ─ ─┴──                              │
│       Tháng 8 Tháng 9 Tháng 10  Tháng 11                               │
└────────────────────────────────────────────────────────────────────────┘
```

### Các tính năng phân tích chủ chốt:
1. **Độ phân giải (Granularity):**
   - **Monthly:** Quan sát xu hướng chi tiêu dài hạn qua các tháng.
   - **Daily:** Phát hiện các đột biến chi phí bất thường theo ngày (ví dụ: một batch job chạy lỗi loop vô tận vào thứ Ba).
   - **Hourly:** Yêu cầu bật tính năng Hourly and Resource Level Data (có tính phí bổ sung).
2. **Group By (Nhóm theo):**
   - Nhóm theo `Service`: Biết dịch vụ nào tiêu tốn nhiều tiền nhất (EC2 vs RDS vs S3).
   - Nhóm theo `Tag` (sau khi kích hoạt): Phân bổ chi phí theo `Environment` (Dev vs Prod).
   - Nhóm theo `Usage Type`: Đi sâu vào chi tiết kỹ thuật (ví dụ `APSE1-BoxUsage:t4g.small` vs `APSE1-DataTransfer-Out-Bytes`).
3. **Actual vs Forecast (Thực tế và Dự báo):**
   - **Actual Cost:** Số tiền đã ghi nhận tính tới thời điểm hiện tại.
   - **Forecast:** AWS dùng mô hình machine learning để ước tính tổng chi phí đến cuối kỳ dựa trên lịch sử chi tiêu. Account mới hoặc ít dữ liệu có thể chưa có forecast hoặc forecast kém tin cậy.
4. **Lần đầu sử dụng:** Account mới phải mở Cost Explorer trên console một lần để bật tính năng; dữ liệu có thể mất đến 24 giờ mới sẵn sàng cho cả console lẫn API.

---

## Phần 4 — AWS Budgets: Cảnh báo và Hành động tự động (30 phút)

Nếu Cost Explorer dùng để **nhìn lại quá khứ**, thì **AWS Budgets** là lá chắn bảo vệ để **chủ động phòng ngừa trong hiện tại**.

### Các loại Budget:
- **Cost Budget:** Theo dõi số tiền chi tiêu (USD).
- **Usage Budget:** Theo dõi số lượng đơn vị sử dụng (ví dụ: cảnh báo khi số giờ chạy EC2 vượt 500 giờ).
- **Savings Plans / RI Budgets:** Theo dõi tỷ lệ phủ (coverage) và mức sử dụng cam kết.

### Cơ chế Ngưỡng (Thresholds): Actual vs Forecasted

Khi tạo một Cost Budget trị giá **\$100/tháng**, bạn có thể cấu hình hai loại cảnh báo:

```text
Mốc ngân sách: $100

1. Ngưỡng Forecasted (80% = $80):
   Giữa tháng, nếu dự báo cuối tháng vượt $80 ──► Gửi email cảnh báo
   (Bạn còn thời gian tắt bớt tài nguyên trước khi vượt thật).

2. Ngưỡng Actual (100% = $100):
   Khi chi phí đã ghi nhận chạm $100 ──► Gửi email.
```

> [!IMPORTANT]
> - **Budget không phải hard cap.** Vượt budget thì AWS chỉ gửi thông báo; tài nguyên vẫn chạy và vẫn tính tiền. Muốn tự động can thiệp phải cấu hình Budgets Actions (xem bên dưới).
> - **Không theo thời gian thực.** Budgets cập nhật dữ liệu vài lần mỗi ngày (dựa trên dữ liệu billing vốn đã trễ), nên cảnh báo có thể đến sau nhiều giờ.
> - **Forecast cần lịch sử.** AWS cần khoảng **5 tuần** dữ liệu sử dụng mới tạo được forecast cho budget. Account học tập mới tạo sẽ chưa nhận được cảnh báo `FORECASTED`; hãy luôn kèm một ngưỡng `ACTUAL`.

> [!CAUTION]
> **Phân biệt Budget Alerts với Budgets Actions:**
> - **Budget Alert:** Chỉ gửi email hoặc thông báo qua SNS / Slack. **Hoàn toàn an toàn**, không can thiệp vào tài nguyên đang chạy.
> - **Budgets Action:** Dùng một IAM execution role để tự động (hoặc sau khi được duyệt) thực hiện một trong ba loại hành động: gắn IAM policy hạn chế quyền, gắn SCP (trong Organization), hoặc **stop** EC2/RDS instance được chỉ định. Budgets Actions **không terminate/xóa** tài nguyên. **Hết sức cẩn trọng:** cấu hình nhầm trên production có thể stop máy chủ quan trọng hoặc khóa quyền của cả đội vận hành.

---

## Phần 5 — Kỹ năng ước tính trước lab và truy quét tài nguyên ngầm (25 phút)

Một senior engineer chuyên nghiệp không bao giờ click tạo tài nguyên trên AWS mà không biết nó sẽ tiêu tốn bao nhiêu tiền.

### Bảng tính ước tính trước lab (Pre-lab Estimation) mẫu

Giả sử bạn chuẩn bị làm lab triển khai dịch vụ Order API:

| Tài nguyên | Quy mô | Đơn giá minh họa | 3 giờ chạy lab | Quên 1 tháng (730 giờ) |
|---|---|---|---|---|
| 1x EC2 `t4g.nano` | 2 vCPU, 0.5 GB RAM | ~$0.0053 / giờ | ~$0.02 | ~$3.9 |
| 1x EBS gp3 | 20 GB root disk | ~$0.096 / GB-tháng | ~$0.01 | ~$1.9 |
| 1x Application Load Balancer | 1 ALB, tải rất nhỏ (~1 LCU) | ~$0.0252 / giờ + ~$0.008 / LCU-giờ | ~$0.10 | ~$24.2 |
| Public IPv4 | 2 IP của ALB + 1 IP của EC2 | ~$0.005 / giờ / IP | ~$0.05 | ~$11.0 |
| S3 Storage & Requests | 100 objects (< 10 MB) | — | < $0.01 | < $0.01 |
| **Tổng ước tính** | | | **~$0.18** | **~$41** |

*Kết luận:* Lab chỉ tốn vài nghìn đồng nếu bạn **dọn dẹp ngay sau 3 giờ**. Nếu **quên xóa** trong 1 tháng, chi phí lên khoảng $40. Để ý rằng public IPv4 là khoản dễ bị bỏ sót nhất trong bảng.

Trước mỗi lab có tính phí, hãy tự lập bảng này với **giá tra cứu cho Region của bạn**, ghi ngày tra cứu, và đặt điều kiện dừng (ví dụ: "xóa toàn bộ nếu lab kéo dài quá 4 giờ").

### Rà soát tài nguyên ngầm (Hidden Idle Costs)

Sau khi làm lab, rất nhiều developer chỉ vào giao diện EC2 và nhấn "Terminate Instance", nhưng hóa đơn cuối tháng vẫn phát sinh tiền vì những tài nguyên ngầm sau:
1. **Unattached EBS Volumes:** Ổ cứng phụ được tách ra khỏi EC2 nhưng chưa bị xóa.
2. **Public IPv4 / Elastic IP:** Mọi public IPv4 đều tính phí theo giờ, kể cả đang gắn. EIP đã allocate mà không gắn vào đâu là lãng phí thuần túy; instance đã stop nhưng còn giữ EIP cũng vẫn bị tính phí IP.
3. **NAT Gateway:** Vẫn đang tồn tại và tính phí theo từng giờ dù không có byte dữ liệu nào đi qua.
4. **Old Snapshots & Custom AMIs:** Các bản sao lưu EBS tích lũy qua từng ngày.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 03)

Trong bài thực hành đi kèm:
- Bạn sẽ học cách tra cứu thông tin chi phí qua CLI và Console.
- Lập bảng ước tính chi phí trước cho một kiến trúc microservice đơn giản.
- Khởi tạo một **AWS Cost Budget** trị giá \$10 với ba ngưỡng cảnh báo gửi về email của chính bạn: 50% Actual, 100% Actual và 80% Forecasted.
- Thử một negative test có kiểm soát (tạo budget trùng tên) và quan sát lỗi.
- Sử dụng AWS CLI để kiểm tra trạng thái ngân sách và kích hoạt quy trình dọn dẹp an toàn.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/03-cost-management/README.md](../labs/03-cost-management/README.md)**.

---

## Phần 7 — Troubleshooting và các tình huống thực tế (20 phút)

### 1. Không truy cập được trang Billing hoặc Cost Explorer
- **Hiện tượng:** `You need permissions. You don't have permission to access billing data`.
- **Nguyên nhân:** (1) identity thiếu quyền `ce:*`/`budgets:*`; (2) root user của **chính account này** chưa bật "IAM user and role access to Billing information"; (3) account là member và management account đã tắt quyền xem Cost Explorer của member.
- **Bằng chứng cần thu thập:** thông báo lỗi chính xác, ARN của identity (`aws sts get-caller-identity`), account là standalone hay member.
- **Khắc phục:** nhờ người quản trị cấp đúng quyền billing (không xin `AdministratorAccess`), hoặc nhờ root user bật cờ truy cập billing. Nếu không có quyền, làm lab theo nhánh dữ liệu minh họa.

### 2. Dữ liệu trên Cost Explorer không khớp với thời gian thực
- **Hiện tượng:** Vừa xóa máy ảo lúc 10h sáng nhưng 11h sáng Cost Explorer vẫn chưa hiển thị chi phí của ngày hôm nay.
- **Nguyên nhân:** Dữ liệu billing chỉ được cập nhật ít nhất mỗi 24 giờ; chi phí của hôm nay thường chưa đầy đủ.
- **Khắc phục:** Không dùng Cost Explorer làm công cụ giám sát tức thời (real-time metrics). Muốn xem tức thời, hãy kiểm tra danh sách tài nguyên đang active qua EC2/VPC console.

### 3. Đã xóa hết EC2 nhưng tiền vẫn tiếp tục tăng
- **Nguyên nhân:** Rà soát ngay: public IPv4/Elastic IP, EBS volume ở trạng thái `available`, NAT Gateway, Load Balancer, snapshot/AMI, CloudWatch Logs, và backup RDS.
- **Bằng chứng cần thu thập:** trong Cost Explorer, group by `Usage Type` để biết chính xác khoản nào đang tăng (ví dụ `PublicIPv4:InUseAddress`, `NatGateway-Hours`), rồi tìm tài nguyên tương ứng bằng API `describe-*` ở **mọi Region** đã dùng.

---

## Câu hỏi tự kiểm tra

1. Tại sao một instance EC2 ở trạng thái `STOPPED` vẫn tiếp tục phát sinh chi phí hàng ngày?
2. Lưu lượng mạng truyền giữa hai EC2 instance ở hai Availability Zone khác nhau trong cùng một Region có miễn phí không? Vì sao?
3. Nếu bạn kích hoạt Cost Allocation Tag `Environment` vào ngày 15 của tháng, bạn có thể xem biểu đồ chi phí của tag này từ ngày 1 đến ngày 14 không? Vì sao?
4. Sự khác biệt cốt lõi giữa ngưỡng cảnh báo **Actual** và ngưỡng cảnh báo **Forecasted** trong AWS Budgets là gì?
5. Một kiến trúc sư đề xuất thiết lập Budgets Action để tự động stop các instance trong môi trường Production khi chi phí vượt 120% ngân sách. Đánh giá của bạn về đề xuất này?
6. Budget \$10/tháng của bạn bị vượt vào ngày 20. Điều gì tự động xảy ra, và điều gì **không** xảy ra?

---

## Tài liệu tham khảo chính thức

- [AWS Cost Management User Guide](https://docs.aws.amazon.com/cost-management/latest/userguide/what-is-costmanagement.html)
- [Public IPv4 address pricing (VPC pricing)](https://aws.amazon.com/vpc/pricing/)
- [Analyzing your costs with AWS Cost Explorer](https://docs.aws.amazon.com/cost-management/latest/userguide/ce-what-is.html)
- [Managing your costs with AWS Budgets](https://docs.aws.amazon.com/cost-management/latest/userguide/budgets-managing-costs.html)
- [Using Cost Allocation Tags](https://docs.aws.amazon.com/cost-management/latest/userguide/cost-alloc-tags.html)
- [Amazon EC2 On-Demand Pricing](https://aws.amazon.com/ec2/pricing/on-demand/)
- [AWS Pricing Calculator](https://calculator.aws/)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** phí public IPv4 (áp dụng từ 02/2024), cost allocation tag activation và backfill, yêu cầu lịch sử cho budget forecast, các loại Budgets Actions, cú pháp `aws budgets` và `aws ce`.
- **Giá là minh họa:** mọi đơn giá trong bài chỉ để luyện cách tính. Tra lại trang giá chính thức cho Region của bạn trước khi dùng.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output là minh họa.
- **An toàn tài chính:** chỉ dùng notification-only budget, không bật Budgets Actions.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Instance stopped không tính phí compute, nhưng các tài nguyên gắn kèm vẫn tồn tại và vẫn tính phí: **EBS volume** (theo GB-tháng, cho đến khi volume bị xóa) và **Elastic IP** nếu instance đang giữ EIP.
2. **Không miễn phí.** Traffic inter-AZ tính phí ở cả hai chiều (ra khỏi AZ nguồn và vào AZ đích). Đây là lý do kiến trúc multi-AZ có "chi phí ẩn" khi các service gọi nhau chéo AZ nhiều.
3. **Mặc định là không**, vì chi phí trước thời điểm kích hoạt không được gắn nhãn. Tuy nhiên bạn có thể yêu cầu **backfill** (tối đa 12 tháng) trong trang Cost Allocation Tags. Backfill chỉ có tác dụng nếu tài nguyên **đã được gắn tag `Environment` từ ngày 1–14**; nếu tag chỉ được gắn lên tài nguyên vào ngày 15 thì phần trước đó vẫn không có nhãn.
4. - **Actual Threshold:** Cảnh báo kích hoạt khi số tiền *đã thực sự tiêu* chạm tới ngưỡng (nhìn về những gì đã xảy ra).
   - **Forecasted Threshold:** Cảnh báo kích hoạt khi thuật toán dự báo thấy rằng *với tốc độ tiêu hiện tại, đến cuối chu kỳ thanh toán bạn chắc chắn sẽ vượt ngưỡng* (nhìn về tương lai để bạn kịp thời can thiệp trước khi tiền bị trừ).
5. **Rủi ro cao, không phù hợp cho Production.** Tự động stop máy chủ production khi vượt budget gây downtime cho người dùng, và chi phí vượt có thể là do tải thật tăng (tin tốt). Với production, nên dùng cảnh báo cho người chịu trách nhiệm điều tra; nếu cần action thì chọn loại ít phá hủy (ví dụ gắn policy chặn tạo tài nguyên mới) và bật chế độ **yêu cầu phê duyệt** thay vì tự động. Ngoài ra, Budgets Actions chỉ stop được instance, không terminate.
6. **Tự động xảy ra:** AWS gửi thông báo tới subscriber của ngưỡng `ACTUAL` 100% (có thể trễ vài giờ). **Không xảy ra:** tài nguyên không bị dừng, không có giới hạn chi tiêu nào được áp dụng, hóa đơn vẫn tăng. Budget không phải hard cap trừ khi bạn chủ động cấu hình Budgets Actions.

</details>

