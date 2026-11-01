# Bài 13 — Độ tin cậy và Hiệu năng: SLI/SLO, Kiểm thử Tải, Thiết kế Phục hồi và Khôi phục Thảm họa (DR)

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 6–10 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Văn hóa SRE, Quản trị SLO/Error Budget, Phân tích Trễ & Nghẽn, và Các Mẫu Thiết kế Phục hồi). Buổi 2 gồm phần 4–7 (Phương pháp Kiểm thử Tải, Chiến lược Khôi phục Thảm họa RTO/RPO và [lab thực hành](../labs/13-reliability-and-performance/README.md)).
> **Kế hoạch gốc:** [plans/13-reliability-and-performance.md](../plans/13-reliability-and-performance.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Thiết lập khung đo lường độ tin cậy chuẩn **Site Reliability Engineering (SRE)**: phân biệt **SLI**, **SLO** và **SLA**, quản lý ngân sách lỗi (**Error Budget**) theo cửa sổ trượt (**Rolling Measurement Window**) và thiết lập cảnh báo tốc độ đốt ngân sách (**Burn Rate Alarms**).
2. Làm chủ các chỉ số hiệu năng phân tán: hiểu rõ tại sao số trung bình (**Average**) là một "cú lừa" nguy hiểm, phân tích độ trễ phân vị (**Latency Percentiles: p50, p90, p95, p99, p99.9**), đánh giá độ bão hòa tài nguyên (**Saturation**) và xác định điểm nghẽn cổ chai (**Bottleneck**).
3. Triển khai các mẫu thiết kế phục hồi (**Resilience Patterns**): thời hạn chót (**Deadlines & Timeouts**), ngân sách thử lại (**Retry Budget**), thuật toán **Exponential Backoff với Full Jitter** chống sụp đổ dây chuyền (**Retry Storm**), ngắt mạch (**Circuit Breaker**) và kiểm soát áp lực ngược (**Backpressure**).
4. Thiết kế quy trình kiểm thử tải khoa học (**Load Testing Methodology**): đo đạc Baseline, giai đoạn Ramp-up, Steady-state, Soak/Stress test, Spike test, và tuân thủ các quy tắc pháp lý kiểm thử tải của AWS.
5. Xây dựng chiến lược **Khôi phục Thảm họa (Disaster Recovery - DR)**: đo lường thực tế **RPO** và **RTO**, so sánh 4 mô hình DR (Backup & Restore, Pilot Light, Warm Standby, Multi-Region Active-Active) theo bài toán đánh đổi chi phí, và thực hiện bài diễn tập khôi phục (**DR Drill**).

---

## Phần 1 — Văn hóa SRE: SLI, SLO, SLA và Quản trị Ngân sách Lỗi (Error Budget) (50 phút)

Trong kỹ nghệ phần mềm hiện đại, việc theo đuổi mục tiêu "hệ thống không bao giờ có lỗi (100% Availability)" là bất khả thi và lãng phí nguồn lực kinh tế một cách vô ích. Thay vào đó, trường phái SRE (Site Reliability Engineering) chấp nhận một tỷ lệ lỗi có kiểm soát để duy trì tốc độ đổi mới sản phẩm.

```text
Phân cấp Khái niệm Đo lường Độ tin cậy:
┌────────────────────────────────────────────────────────┐
│ SLA (Service Level Agreement): Cam kết Pháp lý         │
│ "Nếu hệ thống sẵn sàng dưới 99.9%, hoàn tiền cho khách"│
│  ┌──────────────────────────────────────────────────┐  │
│  │ SLO (Service Level Objective): Mục tiêu Nội bộ   │  │
│  │ "Đội ngũ kỹ thuật phấn đấu đạt 99.95% sẵn sàng"  │  │
│  │  ┌────────────────────────────────────────────┐  │  │
│  │  │ SLI (Service Level Indicator): Chỉ số Đo   │  │  │
│  │  │ "Tỷ lệ requests thành công đo được là 99.98%│  │  │
│  │  └────────────────────────────────────────────┘  │  │
│  └──────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────┘
```

### 1. Phân biệt SLI, SLO và SLA
- **SLI (Chỉ số):** Số liệu thực tế đo được theo thời gian thực. Ví dụ: `Tỷ lệ response HTTP < 500ms đạt mã 200 OK / Tổng số requests nhận được`.
- **SLO (Mục tiêu):** Ngưỡng cam kết nội bộ giữa đội ngũ Kỹ thuật và Sản phẩm. Ví dụ: `99.9% requests phải thành công trong bất kỳ cửa sổ 30 ngày nào`.
- **SLA (Thỏa thuận):** Hợp đồng pháp lý ràng buộc với khách hàng kèm chế tài phạt tiền nếu vi phạm. Luôn luôn đặt **SLO nghiêm ngặt hơn SLA** (ví dụ: SLO 99.95%, SLA 99.9%) để nội bộ có thời gian khắc phục sự cố trước khi bị phạt hợp đồng.

### 2. Ngân sách Lỗi (Error Budget) và Cửa sổ Trượt (Rolling 30-Day Window)
- **Công thức:** `Error Budget = 100% - SLO`.
  - Nếu SLO là **99.9%**, bạn có một ngân sách lỗi là **0.1%**. Với 10,000,000 requests mỗi tháng, bạn được phép có tối đa 10,000 requests bị lỗi.
- **Quy tắc Vận hành:**
  - **Khi Error Budget còn dư dả:** Đội ngũ được tự do triển khai các tính năng mới, thử nghiệm kiến trúc mới với tốc độ phát hành nhanh.
  - **Khi Error Budget bị cạn kiệt (Burned Out):** Đóng băng phát hành tính năng mới (**Feature Freeze**); toàn bộ kỹ sư phải tập trung 100% thời gian vào việc sửa lỗi hạ tầng, tối ưu độ tin cậy và viết bài học kinh nghiệm (Post-mortem).

### 3. Cảnh báo Tốc độ Đốt Ngân sách (Burn Rate Alarms)
Đừng đợi đến khi Error Budget cạn sạch mới bật còi báo động! Hãy theo dõi tốc độ đốt (**Burn Rate**):
- Tốc độ đốt $1\times$: Bạn sẽ tiêu hết 100% ngân sách lỗi đúng sau 30 ngày (bình thường).
- Tốc độ đốt $14.4\times$: Bạn đã đốt hết 2% ngân sách chỉ trong 1 giờ! Đây là một sự cố nghiêm trọng đòi hỏi phải đánh thức kỹ sư trực chiến (On-call Pager) ngay lập tức.

---

## Phần 2 — Hiệu năng và Phân tích Trễ: "Cú lừa" của Số Trung bình (50 phút)

Khi kiểm tra hiệu năng hệ thống, hầu hết các dashboard hiển thị một dòng kẻ: `Average Latency = 45ms`. Kỹ sư vội kết luận hệ thống chạy siêu nhanh. Nhưng thực tế người dùng lại liên tục phàn nàn web bị giật và quay tròn!

```text
Sự Phân phối của Độ trễ (Long-Tail Latency Distribution):
Số lượng
Requests
  ▲
  │   █ (p50 = 25ms - 50% người dùng)
  │  ███
  │ █████ (Average = 45ms - "Cú lừa")
  │███████             █ (p95 = 450ms)
  │████████            ██       █ (p99 = 2,800ms)
  └───────────────────────────────────────────────► Độ trễ (ms)
```

### 1. Tại sao Số Trung bình (Average) là "Cú lừa"?
Số trung bình bị che giấu hoàn toàn bởi số đông các request nhỏ (như kiểm tra health check hoặc đọc cache). Một request duyệt trang nhanh 5ms sẽ triệt tiêu một request thanh toán giỏ hàng bị nghẽn tới 10 giây! Kết quả trung bình vẫn đẹp, nhưng khách hàng VIP sắp trả tiền thì đã bỏ đi vì web bị đơ.

### 2. Độ trễ Phân vị (Latency Percentiles): p50, p95, p99, p99.9
- **p50 (Median):** 50% khách hàng có độ trễ nhỏ hơn giá trị này (đại diện cho người dùng thông thường).
- **p95:** 95% requests nhanh hơn ngưỡng này; 5% chậm hơn.
- **p99 (Long-Tail):** Phản ánh trải nghiệm của 1% người dùng đen đủi nhất. Trong thương mại điện tử, 1% người dùng này thường chính là những khách hàng mua nhiều hàng nhất (giỏ hàng có 100 món, phải chạy hàng loạt truy vấn database phức tạp).
- **Quy tắc toán học quan trọng:** **Tuyệt đối không được cộng các percentile của từng service để suy ra percentile end-to-end!**
  $\text{p99}(A + B) \neq \text{p99}(A) + \text{p99}(B)$. Một request đơn lẻ có thể nhanh ở Service A nhưng lại gặp xui xẻo rơi vào phân vị chậm ở Service B. Muốn đo end-to-end, bạn bắt buộc phải đo trực tiếp tại điểm vào của khách hàng.

### 3. Độ Bão hòa (Saturation) và Nút thắt Cổ chai (Bottleneck)
- **Thông lượng (Throughput / RPS):** Số lượng request hệ thống xử lý trong 1 giây.
- **Độ bão hòa (Saturation):** Tỷ lệ tài nguyên đã bị chiếm dụng:
  - CPU / Memory Utilization.
  - **Database Connection Pool Exhaustion:** CPU máy chủ chỉ 15%, nhưng 100% kết nối kết nối tới RDS MySQL đã bị chiếm hết. Các thread ứng dụng phải xếp hàng chờ giải phóng connection, khiến độ trễ p99 vọt lên hàng nghìn mili-giây.

---

## Phần 3 — Các Mẫu Thiết kế Phục hồi (Resilience Patterns) (50 phút)

Khi một dịch vụ downstream bị chậm hoặc chập chờn, việc các client liên tục thử lại (retry) một cách mù quáng sẽ tạo ra thảm họa dây chuyền (**Cascading Failure**).

```text
Mối Nguy từ Thundering Herd & Sức mạnh của Jitter:
1. Thử lại Cố định (Fixed Retry - Sụp đổ dây chuyền):
   Tất cả Client đồng loạt bắn tải lại cùng một giây!
   Tải (RPS) ▲     ████            ████            ████ (Hạ tầng downstream
             │     ████            ████            ████  tiếp tục bị đè sập)
             └────────────────────────────────────────► Thời gian

2. Exponential Backoff kết hợp Full Jitter (Rải đều tải):
   Tải (RPS) ▲   █  █  █   █   █     █     █     █      (Tải được rải mịn,
             │  ████████████████████████████████████    downstream kịp hồi phục)
             └────────────────────────────────────────► Thời gian
```

### 1. Thuật toán Exponential Backoff và Full Jitter
- **Exponential Backoff:** Khi gặp lỗi, thay vì thử lại ngay, client chờ một khoảng thời gian tăng theo cấp số nhân: $T = \text{base} \times 2^{\text{attempt}}$ (ví dụ: 1s, 2s, 4s, 8s...).
- **Full Jitter:** Nếu 10,000 thiết bị di động cùng bị ngắt kết nối vào lúc 12:00:00, dù có backoff theo cấp số nhân, toàn bộ 10,000 máy vẫn sẽ đồng loạt bắn lại vào các mốc 12:00:01, 12:00:02, 12:00:04... tạo thành các đợt sóng thần triệt hạ máy chủ (**Thundering Herd / Retry Storm**).
  - Thuật toán **Full Jitter** rải đều độ trễ ngẫu nhiên:
    $$T_{\text{sleep}} = \text{random}(0, \min(\text{cap}, \text{base} \times 2^{\text{attempt}}))$$
  - Jitter phá sự đồng bộ: các retry không còn dồn vào cùng một thời điểm. Nhưng jitter **rải** tải chứ **không giảm** tổng số attempt; nếu dependency chỉ chịu được ít hơn tải gốc + retry, nó vẫn quá tải. Vì vậy jitter phải đi kèm giới hạn số lần retry, retry budget và backpressure (Lab 13 Bước 4 cho số liệu cụ thể).
  - Chỉ retry ở **một** tầng. Mỗi tầng retry 3 lần qua 4 tầng có thể nhân tải lên 3⁴ = 81 lần.

### 2. Ngân sách Thử lại (Retry Budget) và Deadlines
- **Retry Budget:** Giới hạn tổng số lượng request thử lại trong toàn hệ thống không được vượt quá **10–20% tổng lưu lượng**. Nếu downstream đang sập, việc cố gắng thử lại vô tận chỉ làm lãng phí năng nguyên CPU. Khi hết ngân sách thử lại, lập tức trả về lỗi cho tầng trên.
- **Deadlines (Context Propagation):** Nếu người dùng trình duyệt chỉ sẵn sàng đợi tối đa 2.5 giây, hãy truyền deadline này dọc theo các HTTP headers (`X-Deadline: 2500ms`). Nếu Service C nhận được request khi thời gian còn lại chỉ còn 50ms (không đủ để query database), Service C nên hủy luôn request ngay lập tức thay vì tốn công thực thi một kết quả mà người dùng chắc chắn sẽ đóng trang trước khi nhận.

- **Backpressure:** khi quá tải, trả lỗi sớm có tín hiệu (`429`/`503` kèm `Retry-After`, hoặc để message nằm trong queue) thay vì nhận thêm việc rồi xử lý chậm cho tất cả.

### 3. Mẫu Thiết kế Ngắt mạch (Circuit Breaker Pattern)

```text
Máy Trạng thái của Circuit Breaker:
                  ┌───────────────────────────────────┐
                  │          CLOSED (Bình thường)     │
                  │ - Mọi request đều được chuyển tiếp│
                  └─────────────────┬─────────────────┘
                                    │ Tỷ lệ lỗi vượt ngưỡng (ví dụ > 50%)
                                    ▼
┌─────────────────────────────────────────────────────┐
│                   OPEN (Ngắt mạch)                  │
│ - Chặn 100% request; trả về fallback error tức thì  │
│ - Bảo vệ downstream không bị sập hoàn toàn          │
└───────────────────────────┬─────────────────────────┘
                            │ Hết thời gian chờ (Sleep window: 30s)
                            ▼
                  ┌───────────────────────────────────┐
                  │         HALF-OPEN (Thử nghiệm)    │
                  │ - Cho phép 1 vài request đi qua...│
                  └─────────┬───────────────┬─────────┘
        Thành công trở lại  │               │ Lại gặp lỗi!
        (Đóng mạch)         ▼               ▼ (Mở mạch tiếp)
```

---

## Phần 4 — Phương pháp Kiểm thử Tải (Load Testing Methodology) (45 phút)

Kiểm thử tải không phải là việc tải một công cụ về và bắn tối đa luồng vào IP của máy chủ. Kiểm thử thiếu kiểm soát có thể khiến tài khoản AWS bị khóa vì vi phạm chính sách bảo mật hoặc phát sinh hóa đơn tiền điện toán khổng lồ.

### 1. Các Giai đoạn của một Bài Kiểm thử Tải Chuẩn mực

```text
Biểu đồ Tải của một Buổi Load Testing Khoa học:
Tải (RPS)
  ▲
  │                       ┌───────────────────┐ (Steady-State: Chạy ổn định
  │                      ╱│                   │  trong 30–60 phút)
  │                     ╱ │                   │
  │     ┌──────────────┘  │                   │
  │    ╱ (Ramp-up)        │                   │
  │   ╱                   │                   │
  ├──┘ (Baseline: Đo chuẩn│                   └───────────┐ (Recovery: Đo khả năng
  │     trong 5 phút)     │                               │  hồi phục sau khi xả tải)
  └───────────────────────┴───────────────────────────────┴────────────────────────► Thời gian
```

- **Giai đoạn 1 — Baseline:** Bắn tải nhẹ (ví dụ 50 RPS) để xác lập chỉ số chuẩn của hệ thống trong điều kiện lý tưởng.
- **Giai đoạn 2 — Ramp-up:** Tăng dần tốc độ tải theo bậc thang để hệ thống Auto Scaling và Database kịp làm nóng (Warm-up) và giãn nở tài nguyên.
- **Giai đoạn 3 — Steady-State (Soak Test):** Giữ nguyên mức tải mục tiêu trong 30–60 phút để phát hiện các lỗi rò rỉ bộ nhớ (**Memory Leak**) hoặc tắc nghẽn Connection Pool theo thời gian.
- **Giai đoạn 4 — Spike Test:** Đột ngột tăng gấp 5 lần tải trong 10 giây để kiểm tra xem hệ thống có tự bảo vệ bằng cơ chế Throttling hay sập hoàn toàn.
- **Giai đoạn 5 — Recovery:** Ngắt tải và theo dõi xem hệ thống mất bao lâu để đưa độ trễ và CPU trở lại mức bình thường.

### 2. Quy định của AWS về Kiểm thử Tải và Mô phỏng Sự cố (AWS FIS)
- **Chính sách AWS Customer Support Policy:** Bạn được phép kiểm thử tải lên các tài nguyên do chính bạn sở hữu (EC2, ALB, API Gateway). Tuy nhiên, **tuyệt đối không được bắn tải làm ảnh hưởng đến tài nguyên dùng chung** của người dùng khác hoặc hạ tầng Control Plane của AWS.
- **AWS Fault Injection Service (AWS FIS):** Dịch vụ tiêm lỗi có kiểm soát của AWS (Chaos Engineering). FIS cho phép bạn lập trình mô phỏng các sự cố nghiêm trọng như: Tắt đột ngột 1 Availability Zone, tăng độ trễ mạng nhân tạo hoặc làm nghẽn CPU máy chủ. Quan trọng nhất, FIS luôn có **Stop Conditions** — tự động dừng ngay bài test nếu CloudWatch Alarm báo động SLO bị đe dọa.

---

## Phần 5 — Chiến lược Phục hồi Thảm họa (Disaster Recovery): RPO và RTO (55 phút)

Khi cả một trung tâm dữ liệu gặp sự cố thiên tai, mất điện diện rộng hoặc tài khoản bị tấn công mã hóa dữ liệu (Ransomware), khả năng sống còn của doanh nghiệp phụ thuộc vào chiến lược Disaster Recovery (DR).

```text
Hai Thước đo Sống còn của Disaster Recovery:
                     Thời điểm Xảy ra Sự cố Thảm họa
                                    │
    ◄───────────────────────────────┼───────────────────────────────►
      Khứ hồi Quá khứ               │  Tương lai Phục hồi
                                    │
  [Bản Backup Gần Nhất]             │               [Hệ Thống Chạy Lại]
            │                       │                        │
            ◄─────── RPO ───────────►◄───────── RTO ─────────►
               (Mất bao nhiêu dữ liệu?) (Mất bao lâu để mở lại?)
```

- **RPO (Recovery Point Objective - Mục tiêu Điểm Phục hồi):** Lượng dữ liệu tối đa mà doanh nghiệp chấp nhận bị mất (tính theo đơn vị thời gian). Nếu backup chạy mỗi đêm lúc 00:00 và sự cố xảy ra lúc 14:00, bạn đã mất 14 giờ dữ liệu giao dịch!
- **RTO (Recovery Time Objective - Mục tiêu Thời gian Phục hồi):** Khoảng thời gian tối đa từ lúc sự cố xảy ra cho đến khi hệ thống hoạt động trở lại bình thường phục vụ khách hàng.

### Bảng Đánh đổi 4 Chiến lược Khôi phục Thảm họa

```text
4 Chiến lược Phục hồi Thảm họa theo Chi phí vs Tốc độ:
Chi phí ▲                                                   [Multi-Region
        │                                                   Active-Active]
        │                                                   (RTO ~ 0, RPO ~ 0)
        │                                    [Warm Standby]
        │                                    (RTO tính bằng phút)
        │                     [Pilot Light]
        │                     (RTO tính bằng chục phút)
        │     [Backup &
        │      Restore] (RTO tính bằng giờ)
        └─────────────────────────────────────────────────────────────► Tốc độ Khôi phục
```

| Chiến lược DR | Mô tả Kiến trúc | RPO | RTO | Đánh đổi Chi phí |
|---|---|---|---|---|
| **1. Backup & Restore** | Định kỳ sao lưu dữ liệu sang S3 ở Region khác. Khi có sự cố, dựng lại toàn bộ hạ tầng bằng IaC và nạp lại dữ liệu. | Tính bằng **giờ** | Tính bằng **giờ / ngày** | **Rẻ nhất** (Không tốn tiền chạy máy chủ dự phòng). Phù hợp cho hệ thống nội bộ, phi kinh doanh. |
| **2. Pilot Light** | Dữ liệu cốt lõi (Database) luôn được đồng bộ liên tục sang Region thứ hai. Máy chủ ứng dụng tắt hoàn toàn (chỉ lưu sẵn AMI hoặc Launch Template). Khi thảm họa, bật máy chủ ứng dụng lên. | Tính bằng **phút** | Tính bằng **chục phút** | **Trung bình thấp** (Chỉ tốn phí duy trì Database bản sao kích thước nhỏ). |
| **3. Warm Standby** | Một phiên bản thu nhỏ (Scale down) của toàn bộ hệ thống luôn chạy 24/7 ở Region thứ hai. Khi sự cố, kích hoạt Auto Scaling phình to lên 100% dung lượng. | Tính bằng **giây** (bằng độ trễ replication bất đồng bộ) | Tính bằng **phút** | **Trung bình cao** (Phải trả tiền cho toàn bộ cụm hạ tầng dự phòng tối thiểu). |
| **4. Multi-Region Active-Active** | Hệ thống chạy đồng thời 100% công suất trên cả 2 Region (sử dụng Route 53 Geolocation / Latency Routing và DynamoDB Global Tables). | **Gần 0**: vài giây với replication bất đồng bộ; bằng 0 chỉ khi dùng chế độ nhất quán mạnh đa Region (đổi bằng độ trễ ghi) | **Gần như tức thì** | **Cực kỳ đắt đỏ** (Gấp đôi đến gấp ba chi phí hạ tầng, yêu cầu xử lý bài toán xung đột dữ liệu phân tán). |

### Mỗi cơ chế chống một loại sự cố khác nhau

| Sự cố | Multi-AZ | Backup / PITR | Multi-Region |
|---|---|---|---|
| Một instance/AZ hỏng | ✅ tự failover | ❌ (quá chậm, không cần) | ✅ nhưng quá đắt cho mục đích này |
| Xóa nhầm dữ liệu, bug ghi sai, ransomware | ❌ lỗi được sao chép ngay sang standby | ✅ quay về thời điểm trước sự cố | ❌ lỗi cũng được replicate sang Region kia |
| Cả Region gặp sự cố | ❌ | ⚠️ chỉ khi backup được **copy sang Region khác** | ✅ |

Không cơ chế nào thay được cơ chế khác: hệ thống quan trọng thường cần Multi-AZ **và** backup/PITR, rồi mới cân nhắc multi-Region.

> [!IMPORTANT]
> **Điểm cốt tử:** **Không bao giờ tin vào RTO/RPO trên lý thuyết cấu hình!**
> Bạn chỉ thực sự biết RTO của mình là bao lâu khi bạn **thực hiện diễn tập thực tế (Disaster Recovery Drill)**: Xóa thử bảng dữ liệu trên môi trường kiểm thử, bấm đồng hồ đo thời gian restore từ backup và kiểm tra xem có bản ghi nào bị lỗi hay không.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 13)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. Viết SLO cho thao tác đọc đơn hàng trước khi đo.
2. Load test bảng DynamoDB của lab với trần rate/concurrency/duration và điều kiện dừng; báo cáo p50/p95/p99 kèm sample size.
3. Chứng minh bằng số liệu vì sao không cộng percentile các tầng.
4. Mô phỏng retry storm cục bộ: fixed retry, full jitter, jitter + retry budget; đọc đánh đổi giữa bảo vệ dependency và fail nhanh.
5. Backup on-demand và PITR; "sự cố" xóa nhầm item; restore sang bảng tách biệt bằng cả hai cách, đo thời gian, so tập ID để thấy RPO khác nhau.
6. Viết runbook có số đo thật và chạy lại drill theo runbook.
7. Dọn dẹp an toàn toàn bộ tài nguyên.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/13-reliability-and-performance/README.md](../labs/13-reliability-and-performance/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Độ trễ p99 tăng vọt nhưng mức sử dụng CPU máy chủ vẫn dưới 20%
- **Nguyên nhân 1:** Nghẽn kết nối cơ sở dữ liệu (**Connection Pool Exhaustion**). Số lượng request vượt quá kích thước pool của ứng dụng (ví dụ pool size = 20), khiến hàng trăm thread phải xếp hàng chờ giải phóng connection.
- **Nguyên nhân 2:** Nút thắt cổ chai nằm ở một dịch vụ bên thứ ba (Downstream API / Payment Gateway) không có thời hạn chờ (**Timeout**) thích hợp.
- **Cách khắc phục:** Dùng **AWS X-Ray Tracing** để bóc tách thời gian từng chặng; tăng kích thước connection pool hoặc triển khai **Amazon RDS Proxy** để tái sử dụng kết nối; đặt Client-side Timeout ngắn hơn cho tất cả các cuộc gọi ra ngoài.

### 2. Sự cố "Cơn bão thử lại" (Retry Storm) đánh sập máy chủ sau khi vừa khởi động lại
- **Nguyên nhân:** Khi máy chủ gặp sự cố và khởi động lại, hàng ngàn client cùng lúc phát hiện timeout và liên tục gửi lại request mà không có khoảng nghỉ trễ ngẫu nhiên (thiếu Jitter). Lượng tải tăng gấp 5 lần ngày thường đè bẹp máy chủ ngay khi vừa mở cổng mạng.
- **Cách khắc phục:** Cài đặt thuật toán Full Jitter trên toàn bộ SDK client; triển khai **Rate Limiting / Backpressure** tại tầng API Gateway; thiết lập Circuit Breaker để ngắt mạch tạm thời cho đến khi backend hoàn toàn ổn định.

### 3. Diễn tập khôi phục (Restore Backup) thất bại hoặc chạy quá chậm vượt quá RTO
- **Nguyên nhân 1:** IAM Role thực hiện khôi phục thiếu quyền truy cập vào khóa **AWS KMS CMK** dùng để mã hóa bản backup gốc.
- **Nguyên nhân 3:** Chọn sai mốc thời gian (giờ địa phương thay vì UTC, PITR chưa bắt kịp), hoặc kiểm tra bằng số lượng bản ghi thay vì so tập khóa với nguồn tham chiếu.
- **Nguyên nhân 2:** Bản backup chứa hàng terabyte dữ liệu, thời gian tải về và đánh lại chỉ mục (Index rebuilding) mất nhiều giờ mà không được dự tính trước trong kế hoạch RTO.
- **Cách khắc phục:** Chuẩn hóa Key Policy cho phép dịch vụ khôi phục truy cập KMS; định kỳ tổ chức diễn tập DR ít nhất một quý một lần để cập nhật số liệu RTO thực tế vào sổ tay vận hành (Runbook).

---

## Câu hỏi tự kiểm tra

1. Tại sao việc dựa vào chỉ số độ trễ trung bình (Average Latency) để đánh giá sức khỏe hệ thống có thể dẫn đến nhận định sai lầm nghiêm trọng? Phân tích ý nghĩa của độ trễ phân vị p99 trong bài toán thương mại điện tử.
2. Trình bày sự khác biệt giữa hai thuật toán Exponential Backoff thông thường và Exponential Backoff kết hợp Full Jitter. Thuật toán Full Jitter giải quyết triệt để vấn đề "Thundering Herd" như thế nào?
3. Phân biệt vai trò của RPO và RTO trong chiến lược khôi phục thảm họa (Disaster Recovery). Tại sao việc lưu trữ bản sao lưu (Backup) là chưa đủ để khẳng định doanh nghiệp đạt được mục tiêu RTO?
4. So sánh hai mô hình Pilot Light và Warm Standby về mặt kiến trúc, chi phí và thời gian khôi phục khi có sự cố Region xảy ra.
5. Mô tả nguyên lý hoạt động của ba trạng thái Closed, Open và Half-Open trong mẫu thiết kế Ngắt mạch (Circuit Breaker). Mẫu hình này bảo vệ hệ thống phân tán ra sao?

---

## Tài liệu tham khảo chính thức

- [AWS Well-Architected Framework: Reliability Pillar](https://docs.aws.amazon.com/wellarchitected/latest/reliability-pillar/)
- [AWS Well-Architected Framework: Performance Efficiency Pillar](https://docs.aws.amazon.com/wellarchitected/latest/performance-efficiency-pillar/)
- [AWS Prescriptive Guidance: Disaster Recovery Options in the Cloud](https://docs.aws.amazon.com/prescriptive-guidance/latest/disaster-recovery-options/)
- [Amazon Builders' Library: Timeouts, retries, and backoff with jitter](https://aws.amazon.com/builders-library/timeouts-retries-and-backoff-with-jitter/)
- [AWS Fault Injection Service (FIS) User Guide](https://docs.aws.amazon.com/fis/latest/userguide/)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** burn rate alert; full jitter theo Amazon Builders' Library; DynamoDB PITR (`LatestRestorableDateTime`, restore sang bảng mới, cấu hình không đi theo bảng restore); on-demand backup không tự xóa theo bảng; chiến lược DR theo AWS Prescriptive Guidance.
- **Đã chạy thật:** mô phỏng retry (Bước 4) và ví dụ percentile (Bước 3) của lab chạy cục bộ với seed cố định; số liệu trong lab là kết quả thật.
- **Chưa chạy thử trên AWS:** phần load test và restore DynamoDB chưa được thực thi trên account thật; output là minh họa.
- **Chi phí:** lab dưới $0.02 (DynamoDB on-demand, vài MB, hai lần restore).

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. **Ý nghĩa của p99 vs Average:**
   - Số trung bình bị pha loãng bởi hàng ngàn requests nhanh (nhẹ tải, cache hit), che giấu hoàn toàn các giao dịch bị nghẽn.
   - Trong thương mại điện tử, 1% người dùng ở phân vị p99 thường là những khách hàng mua sắm giỏ hàng lớn (nhiều mặt hàng, áp dụng nhiều mã giảm giá, kiểm tra tồn kho phức tạp). Nếu p99 lên tới 5-10 giây, những khách hàng mang lại doanh thu cao nhất cho doanh nghiệp sẽ bỏ sang đối thủ.
2. **Backoff thông thường vs Full Jitter:**
   - **Backoff thông thường:** Thời gian chờ tăng gấp đôi sau mỗi lần thử ($1s, 2s, 4s$). Nhưng nếu hàng ngàn client cùng gặp lỗi ở một thời điểm, tất cả đều thức dậy đồng loạt vào giây thứ 1, giây thứ 2, giây thứ 4... tạo thành các đợt bắn tải tập trung (Thundering Herd) dìm chết downstream vừa gượng dậy.
   - **Full Jitter:** Lấy giá trị ngẫu nhiên từ 0 đến khoảng backoff tối đa, nên các client không còn thức dậy cùng lúc và các đợt tải đồng bộ bị phá vỡ.
   - Nhưng jitter **không giải quyết triệt để**: tổng số attempt gần như không đổi, chỉ được rải ra. Nếu downstream không chịu nổi tải gốc cộng retry, nó vẫn quá tải. Cần thêm giới hạn số lần retry, retry budget, circuit breaker và backpressure.
3. **RPO, RTO và Sai lầm về Backup:**
   - **RPO:** Dữ liệu tối đa chấp nhận mất (Khứ hồi).
   - **RTO:** Thời gian tối đa chấp nhận ngừng hoạt động (Tương lai).
   - Chỉ có file backup **chưa chứng minh được RTO** vì: Bạn chưa biết mất bao lâu để tải file backup về, giải nén, nạp vào database, xây dựng lại indexes, và kiểm thử tính tương thích của ứng dụng. Nếu backup có dung lượng 5TB và mất 12 tiếng để restore, RTO thực tế của bạn là 12 tiếng dù hợp đồng chỉ cho phép 1 tiếng!
4. **Pilot Light vs Warm Standby:**
   - **Pilot Light:** Chỉ có cơ sở dữ liệu được đồng bộ liên tục; máy chủ ứng dụng hoàn toàn tắt. RTO khoảng 20-45 phút (cần thời gian khởi động EC2/ECS). Chi phí rất tiết kiệm.
   - **Warm Standby:** Toàn bộ hệ thống (cả Database và máy chủ ứng dụng) luôn chạy ở quy mô tối thiểu (ví dụ 10-20% công suất). RTO chỉ tính bằng vài phút (chỉ cần scale up số lượng máy chủ). Chi phí cao hơn do phải duy trì máy chủ chạy 24/7.
5. **Circuit Breaker:**
   - **Closed:** Hệ thống bình thường, mọi request đi qua. Đếm tỷ lệ lỗi.
   - **Open:** Khi lỗi vượt ngưỡng (ví dụ > 50%), mạch ngắt ngay lập tức. Mọi request mới bị từ chối tức thì (fail fast), không gọi tới downstream đang kiệt sức.
   - **Half-Open:** Sau một khoảng thời gian chờ (ví dụ 30s), cho một lượng nhỏ request đi qua thử nghiệm. Nếu thành công -> Đóng mạch trở lại (Closed). Nếu vẫn lỗi -> Tiếp tục mở mạch (Open).

</details>

