# Bài 07 — Cơ sở dữ liệu và Bộ đệm: RDS, Aurora, DynamoDB và ElastiCache

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 6–8 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Cơ sở dữ liệu quan hệ RDS, Aurora, High Availability & Disaster Recovery). Buổi 2 gồm phần 4–7 (NoSQL DynamoDB, In-Memory Caching ElastiCache và [lab thực hành](../labs/07-databases-and-cache/README.md)).
> **Kế hoạch gốc:** [plans/07-databases-and-cache.md](../plans/07-databases-and-cache.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Lựa chọn đúng công nghệ cơ sở dữ liệu dựa trên **Access Pattern**, yêu cầu tính nhất quán (**ACID vs BASE**), mô hình dữ liệu và đặc thù tải hệ thống.
2. Thiết kế và vận hành **Amazon RDS** trong mạng riêng tư (Private Subnet), cơ chế sao lưu tự động (**Automated Backup**) và khôi phục về điểm thời gian bất kỳ (**Point-In-Time Recovery - PITR**).
3. Phân biệt rõ bản chất kiến trúc giữa **Multi-AZ Deployment** (dự phòng chống thảm họa, đồng bộ) với **Read Replica** (mở rộng năng lực đọc, bất đồng bộ) và đột phá kiến trúc của **Amazon Aurora**.
4. Thiết kế dữ liệu NoSQL hiệu năng cao với **Amazon DynamoDB**: cấu trúc Partition Key / Sort Key, phân biệt thao tác **Query vs Scan**, thiết lập **Global Secondary Index (GSI)** và xử lý tương tranh bằng **Conditional Writes**.
5. Triển khai mô hình **Cache-Aside** với **Amazon ElastiCache**, kiểm soát hiện tượng nghẽn bộ đệm (**Cache Stampede / Thundering Herd**) và thiết kế khả năng chịu lỗi khi tầng Cache sập.

---

## Phần 1 — Lựa chọn Cơ sở dữ liệu theo Access Pattern và Tải (45 phút)

### Tư duy "Purpose-Built Database" trên Cloud
Trong kiến trúc nguyên khối (Monolith) truyền thống, doanh nghiệp thường cố gắng nhồi nhét mọi thứ vào một hệ quản trị RDBMS duy nhất (từ thông tin người dùng, đơn hàng, giỏ hàng tạm, đến dữ liệu phân tích và session).

Trên AWS, tư duy thiết kế hiện đại chuyển sang **Purpose-Built Databases** (chọn đúng công cụ cho từng bài toán cụ thể):

```text
Cây quyết định lựa chọn Database trên AWS:
                                  Dữ liệu của bạn là gì?
                                             │
             ┌───────────────────────────────┴──────────────────────────────┐
             ▼                                                              ▼
    Quan hệ (Relational)?                                         Phi quan hệ (NoSQL / Cache)?
    - Cần ACID transaction khắt khe                                         │
    - Cần JOIN nhiều bảng phức tạp                                          │
    - Schema có cấu trúc chặt chẽ                                           │
             │                                                              │
    ┌────────┴────────┐                                     ┌───────────────┴───────────────┐
    ▼                 ▼                                     ▼                               ▼
Amazon RDS     Amazon Aurora                         Key-Value / Document?          In-Memory Cache?
(Chi phí tối   (Tải cực lớn, tự                      - Độ trễ cực thấp (<10ms)      - Đệm dữ liệu thường đọc
 ưu, chuẩn     co giãn, failover                     - Khả năng scale vô hạn        - Lưu session, rate limit
 open-source)  nhanh <30s)                           - Không JOIN bảng              - Tải đọc hàng trăm nghìn RPS
                                                            │                               │
                                                            ▼                               ▼
                                                     Amazon DynamoDB                Amazon ElastiCache
                                                     (Serverless, NoSQL)            (Redis / Valkey / Memcached)
```

### Bảng so sánh các đặc tính cốt lõi

| Dịch vụ | Loại hình | Mô hình dữ liệu | Tính nhất quán | Khả năng mở rộng (Scaling) | Ca sử dụng điển hình |
|---|---|---|---|---|---|
| **Amazon RDS** | Managed RDBMS | Tables, Rows (Postgres, MySQL, MariaDB) | ACID mạnh (Strong Consistency) | Scale dọc (Vertical) là chủ yếu; Scale đọc qua Read Replica | Nghiệp vụ kế toán, ERP, lõi xử lý thanh toán tài chính |
| **Amazon Aurora** | Cloud-native RDBMS | Tương thích PostgreSQL & MySQL | ACID (một writer) | Storage tự tăng tới hàng trăm TiB (giới hạn tùy engine version); tối đa 15 Aurora Replicas | Ứng dụng doanh nghiệp lớn, SaaS đa người dùng, lưu lượng cao |
| **Amazon DynamoDB** | Managed NoSQL | Key-Value, Document (JSON) | Eventual Consistency (mặc định) hoặc Strongly Consistent | Scale ngang (Horizontal) tự động không giới hạn dung lượng/RPS | Giỏ hàng e-commerce, tracking đơn hàng, lịch sử thiết bị IoT |
| **Amazon ElastiCache** | In-memory Data Store | Key-Value, Hashes, Sets, Sorted Sets | In-memory Speed (Micro-giây) | Cluster Sharding / Replication | Cache truy vấn DB, quản lý session đăng nhập, Bảng xếp hạng (Leaderboard) |

---

## Phần 2 — Amazon RDS PostgreSQL: Mạng riêng tư, Connection Pool và PITR (50 phút)

### Kiến trúc Triển khai RDS An toàn trong VPC
Nguyên tắc bất di bất dịch trong bảo mật Enterprise: **Không bao giờ đưa cơ sở dữ liệu quan hệ ra Public Subnet!**

```text
Mô hình triển khai Amazon RDS trong mạng riêng tư:
┌────────────────────────────────────────────────────────────────────────┐
│ VPC (10.0.0.0/16)                                                      │
│                                                                        │
│  ┌──────────────────────────────┐    ┌──────────────────────────────┐  │
│  │ Private Subnet 1 (AZ-a)      │    │ Private Subnet 2 (AZ-b)      │  │
│  │ 10.0.11.0/24                 │    │ 10.0.12.0/24                 │  │
│  │                              │    │                              │  │
│  │ ┌──────────────────────────┐ │    │ ┌──────────────────────────┐ │  │
│  │ │ RDS Primary Instance     │ │    │ │ (Standby Instance        │ │  │
│  │ │ Port 5432                │ │    │ │  nếu bật Multi-AZ)       │ │  │
│  │ └──────────────────────────┘ │    │ └──────────────────────────┘ │  │
│  └──────────────▲───────────────┘    └──────────────────────────────┘  │
│                 │                                                      │
│                 │ Chỉ cho phép Inbound Port 5432 từ Security Group App │
│                 │                                                      │
│  ┌──────────────┴───────────────┐                                      │
│  │ App Servers (EC2 / ECS Tasks)│                                      │
│  │ Security Group: `sg-app`     │                                      │
│  └──────────────────────────────┘                                      │
└────────────────────────────────────────────────────────────────────────┘
```

1. **DB Subnet Group:** RDS bắt buộc bạn phải tạo một DB Subnet Group gồm tối thiểu **2 Subnet nằm ở 2 Availability Zones khác nhau** trong cùng một VPC (nhằm đảm bảo sẵn sàng chuyển đổi dự phòng khi cần).
2. **Security Group Chaining:** Security Group của RDS (`sg-rds`) chỉ mở cổng `5432` với Source là định danh Security Group của máy chủ ứng dụng (`sg-app`), loại bỏ hoàn toàn việc mở dải IP tĩnh.
3. **Connection Pooling và RDS Proxy:** Mỗi kết nối mới tới PostgreSQL tiêu tốn khoảng 2–10 MB RAM máy chủ. Khi hàng ngàn hàm Serverless Lambda hoặc container mở kết nối đồng thời, RDS sẽ nhanh chóng cạn kiệt bộ nhớ và báo lỗi `FATAL: remaining connection slots are reserved for non-replication superuser connections`.
   - **Giải pháp:** Sử dụng **Amazon RDS Proxy** đứng trước database để gom và chia sẻ kết nối (Connection Pooling), bảo vệ database khỏi tình trạng cạn kiệt connection.

### Cơ chế Sao lưu Tự động và Point-In-Time Recovery (PITR)
- **Automated Backup:** RDS tự động chụp snapshot toàn bộ instance hàng ngày trong khung giờ sao lưu (Backup Window) và tải transaction log (WAL với PostgreSQL) lên S3 khoảng mỗi 5 phút.
- **Thời gian lưu trữ (Backup Retention Period):** Cấu hình từ 1 đến 35 ngày.
- **Point-In-Time Recovery (PITR):** Cho phép bạn khôi phục database về **một giây cụ thể** trong khoảng retention, tới `LatestRestorableTime` (thường trễ hiện tại khoảng 5 phút). Ví dụ: khôi phục về `14:23:15` hôm qua, ngay trước khi một script bảo trì vô tình chạy `DROP TABLE orders;`.
- **Manual snapshot** không bị xóa khi xóa instance và tính phí cho tới khi bạn xóa; automated backup thì bị xóa theo instance (trừ khi chọn giữ lại).
- **Lưu ý then chốt:** Thao tác Restore từ PITR hoặc Snapshot sẽ **luôn luôn tạo ra một RDS DB Instance hoàn toàn mới** với Endpoint mới, chứ không bao giờ ghi đè trực tiếp lên instance cũ đang chạy.
- **Backup chưa phải bằng chứng khôi phục được.** Chỉ một lần restore thật, kiểm tra dữ liệu và đo thời gian mới cho bạn biết RTO/RPO thực tế. Lab 07 làm đúng việc này.
- **Mật khẩu master:** dùng `--manage-master-user-password` để RDS sinh và lưu mật khẩu trong Secrets Manager (có rotation), thay vì truyền mật khẩu qua tham số CLI.

---

## Phần 3 — Multi-AZ, Read Replica và Đột phá của Amazon Aurora (50 phút)

Rất nhiều kỹ sư mới học AWS nhầm lẫn giữa **Multi-AZ** và **Read Replica**. Đây là hai cơ chế phục vụ hai mục đích hoàn toàn khác biệt!

> [!IMPORTANT]
> "Multi-AZ" không chỉ có một kiểu. Luôn nói rõ topology:
> - **RDS Multi-AZ DB instance deployment:** 1 primary + 1 standby ở AZ khác, sao chép đồng bộ, **standby không đọc được**. Sơ đồ và bảng dưới đây mô tả kiểu này.
> - **RDS Multi-AZ DB cluster deployment** (MySQL/PostgreSQL): 1 writer + 2 **reader standby đọc được** ở 3 AZ, sao chép bán đồng bộ (commit khi ít nhất một reader xác nhận), failover thường nhanh hơn.
> - **Aurora:** storage luôn trải 3 AZ; "Multi-AZ" của Aurora nghĩa là có ít nhất một Aurora Replica ở AZ khác để làm đích failover, và replica đó đọc được.

```text
Phân biệt Multi-AZ Deployment vs Read Replica:

Multi-AZ (High Availability - Chống thảm họa):
┌─────────────────────────┐               ┌─────────────────────────┐
│ AZ-a (Primary DB)       │ ──Đồng bộ───> │ AZ-b (Standby DB)       │
│ Nhận Đọc & Ghi (R/W)    │  (Synchronous)│ CHỈ DỰ PHÒNG (Không đọc)│
└─────────────────────────┘               └─────────────────────────┘
        │
        └── Khi AZ-a sập ──> AWS tự động đổi DNS phân giải sang AZ-b (thường 1–2 phút)

Read Replica (Read Scalability - Mở rộng tải đọc):
┌─────────────────────────┐               ┌─────────────────────────┐
│ AZ-a (Primary DB)       │ ─Bất đồng bộ─>│ AZ-b (Read Replica)     │
│ Nhận Đọc & Ghi (R/W)    │ (Async lag)   │ CHỈ CHO PHÉP ĐỌC (RO)   │
└─────────────────────────┘               └─────────────────────────┘
```

### Bảng đối chiếu kỹ thuật

| Tiêu chí | RDS Multi-AZ Deployment | RDS Read Replica |
|---|---|---|
| **Mục đích chính** | Độ sẵn sàng cao (High Availability), chống chịu lỗi phần cứng/AZ | Mở rộng năng lực đọc (Read Scaling) cho ứng dụng báo cáo/dashboard |
| **Cơ chế sao chép** | **Đồng bộ (Synchronous Replication)** — Transaction chỉ commit khi đã ghi thành công ở cả 2 node | **Bất đồng bộ (Asynchronous Replication)** — Transaction commit ngay trên Primary, sau đó stream log sang Replica |
| **Khả năng phục vụ traffic** | Standby node **hoàn toàn thụ động (Passive)**, không thể kết nối đọc/ghi | Replica node mở cổng phục vụ các truy vấn đọc (`SELECT`) |
| **Endpoint** | Chỉ có 1 DNS Endpoint duy nhất. Khi failover, AWS đổi bản ghi DNS ngầm; ứng dụng phải reconnect và không cache DNS quá lâu | Mỗi Read Replica có DNS Endpoint riêng biệt cho ứng dụng đọc |
| **Độ trễ dữ liệu (Replication Lag)** | Không có lag với transaction đã commit, nên failover không mất dữ liệu đã commit | Có Replication Lag (từ vài mili-giây đến vài phút tùy tải ghi); đọc ngay sau ghi có thể thấy dữ liệu cũ |
| **Thời gian failover** | Thường 60–120 giây | Không tự failover; promote replica là thao tác thủ công, replica trở thành DB độc lập |
| **Có thay backup không?** | Không: lệnh xóa nhầm được sao chép sang standby | Không: lệnh xóa nhầm được sao chép sang replica |

### Đột phá Kiến trúc của Amazon Aurora
Trong kiến trúc RDS truyền thống, compute (máy ảo) và storage (ổ đĩa EBS) gắn liền với nhau. Khi muốn replicate, toàn bộ khối dữ liệu phải được truyền qua mạng.

**Amazon Aurora** tách rời hoàn toàn tầng tính toán (Compute) và tầng lưu trữ (Storage):
- **Tầng lưu trữ phân tán (Shared Distributed Storage):** Aurora tự động nhân bản mỗi khối dữ liệu thành **6 bản sao nằm rải rác trên 3 Availability Zones**.
- **Không cần nhân bản dữ liệu ở tầng compute:** Writer và tối đa 15 Aurora Replicas đọc chung một tầng lưu trữ (Shared Storage). Replica lag thường dưới 100 ms, và thêm replica mới chỉ mất vài phút vì không phải chép dữ liệu.
- **Failover nhanh:** Nếu writer sập, Aurora promote một replica thành writer mới, thường trong khoảng dưới 1 phút (nhanh hơn nếu dùng driver/RDS Proxy nhận biết failover). Không mất transaction đã commit vì dữ liệu nằm ở tầng storage chung. Nếu cluster **không có** replica, Aurora phải tạo instance mới, lâu hơn nhiều.
- **Đánh đổi:** Aurora có giá instance cao hơn RDS cùng cỡ và tính phí I/O (hoặc chọn cấu hình I/O-Optimized). Với tải nhỏ, RDS thường rẻ hơn.

---

## Phần 4 — Amazon DynamoDB: Partition Key, GSI và Conditional Writes (50 phút)

### Kiến trúc Phân tán của Amazon DynamoDB
DynamoDB là cơ sở dữ liệu NoSQL dạng Key-Value / Document được quản lý hoàn toàn (Fully Managed), mang lại độ trễ ổn định ở mức mili-giây ở bất kỳ quy mô dữ liệu nào (từ vài Megabytes đến hàng trăm Terabytes).

```text
Cấu trúc Partition trong DynamoDB:
Bảng: `Orders`
┌────────────────────────────────────────────────────────────────────────┐
│ Partition Key (Hash Key): `order_id`                                   │
│    │                                                                   │
│    ▼ (Hàm băm nội bộ của DynamoDB)                                     │
│  Hash(order_id) → dải giá trị băm của từng partition                   │
│    ├──> Partition 1 (SSD Storage Node A) -> "ORD-001", "ORD-004"       │
│    ├──> Partition 2 (SSD Storage Node B) -> "ORD-002", "ORD-005"       │
│    └──> Partition 3 (SSD Storage Node C) -> "ORD-003", "ORD-006"       │
└────────────────────────────────────────────────────────────────────────┘
```

- **Khóa chính (Primary Key):**
  - **Chỉ có Partition Key (PK - Simple Primary Key):** Mỗi item được xác định duy nhất bởi PK. DynamoDB dùng giá trị này qua hàm băm để quyết định dữ liệu được lưu trên Storage Partition vật lý nào.
  - **Composite Primary Key (Partition Key + Sort Key):** PK xác định partition, còn Sort Key (SK) quyết định thứ tự sắp xếp vật lý của các item bên trong partition đó. Cho phép thực hiện mô hình 1-Nhiều (ví dụ `PK = order_id`, `SK = item_id`).

### Query vs Scan: Quy tắc Sinh tử về Hiệu năng và Chi phí
Một trong những lỗi nghiêm trọng nhất của lập trình viên chuyển từ SQL sang DynamoDB là thói quen sử dụng lệnh `Scan`:

- **Thao tác `Query` (Hiệu quả, khuyến nghị 100%):**
  - Tìm kiếm các item dựa trên giá trị chính xác của Partition Key và điều kiện lọc trên Sort Key (ví dụ: `PK = 'USER#123' AND SK begins_with('ORDER#2026')`).
  - DynamoDB nhảy thẳng tới đúng partition vật lý và đọc chính xác các bản ghi cần tìm. Cực nhanh (<10ms) và chỉ tiêu tốn Capacity Unit của số byte thực tế đọc được.
- **Thao tác `Scan` (Cực kỳ tốn kém, chống chỉ định cho production):**
  - Quét tuần tự toàn bộ từng partition của cả bảng dữ liệu từ đầu đến cuối, sau đó mới lọc kết quả.
  - Chi phí và thời gian của `Scan` tăng **tuyến tính theo kích thước bảng**, không theo số kết quả: `FilterExpression` lọc **sau khi** đọc, nên bạn vẫn trả RCU cho mọi item bị quét. Bảng 10 triệu item làm một request `Scan` tốn hàng nghìn lần so với 1.000 item và chiếm capacity của request khác.

### Chỉ mục Phụ Toàn cục (Global Secondary Index - GSI)
Trong DynamoDB, bạn **chỉ có thể query trực tiếp theo Primary Key**. Nếu bạn muốn tìm đơn hàng theo `customer_id` hoặc theo `order_date` mà trường đó không phải là Primary Key, bạn phải tạo một **Global Secondary Index (GSI)**:
- GSI cho phép định nghĩa một bộ Partition Key và Sort Key hoàn toàn mới trên bảng dữ liệu sẵn có.
- Dữ liệu được DynamoDB tự động đồng bộ ngầm sang partition riêng của GSI theo cơ chế bất đồng bộ (Asynchronous).
- **Lưu ý then chốt:** Vì đồng bộ bất đồng bộ, truy vấn vào GSI **chỉ hỗ trợ tính nhất quán sau cùng (Eventual Consistency)**, không hỗ trợ Strongly Consistent Read!

### Khóa Lạc quan với Conditional Writes (Optimistic Locking)
Khi hai khách hàng cùng bấm nút đặt món hàng cuối cùng trong kho tại cùng một mili-giây, làm sao tránh tình trạng bán vượt số lượng (Overselling)?
DynamoDB hỗ trợ **Conditional Writes**:
```bash
aws dynamodb update-item \
  --table-name Products \
  --key '{"product_id": {"S": "LAPTOP-01"}}' \
  --update-expression "SET stock = stock - :dec" \
  --condition-expression "stock >= :dec" \
  --expression-attribute-values '{":dec": {"N": "1"}}'
```
Nếu `stock` nhỏ hơn 1, DynamoDB sẽ từ chối giao dịch với mã lỗi `ConditionalCheckFailedException` và không ghi đè dữ liệu, đảm bảo toàn vẹn nghiệp vụ mà không cần khóa bảng (Locking).

Đây là conditional write theo điều kiện nghiệp vụ. **Optimistic locking** tổng quát hơn: mỗi item có thuộc tính `version`; client đọc item, rồi ghi với `ConditionExpression: version = :seen` và tăng `version`. Nếu worker khác đã ghi trước, điều kiện sai và client phải đọc lại rồi quyết định lại.

### Capacity mode và TTL
- **On-demand:** trả theo request, không cần dự báo; hợp với tải khó đoán và lab. **Provisioned** (kèm auto scaling): đặt trước RCU/WCU, rẻ hơn khi tải ổn định. Cả hai đều có thể bị throttle khi một partition quá nóng.
- **TTL của DynamoDB** (thuộc tính epoch giây) chỉ đánh dấu item hết hạn; việc xóa diễn ra **nền, thường trong vài ngày**, không phải đúng giây. Item hết hạn nhưng chưa xóa vẫn xuất hiện khi đọc, nên code phải tự lọc `expires_at > now`. Không dùng TTL làm lịch chạy nghiệp vụ chính xác.

---

## Phần 5 — Amazon ElastiCache: Cache-Aside, TTL và Phòng chống Stampede (45 phút)

### Kiến trúc Bộ đệm In-Memory
Amazon ElastiCache cung cấp dịch vụ quản lý cụm bộ nhớ đệm cực nhanh (độ trễ dưới 1 mili-giây) tương thích với **Redis (hoặc Valkey)** và **Memcached**.

```text
Mô hình Cache-Aside (Lazy Loading):
┌────────┐                ┌──────────────────┐                ┌────────────┐
│ Client │ ──1. Đọc dữ liệu─>│ App Server       │ ──2. Đọc Cache──>│ ElastiCache│
│        │                │                  │<──Cache HIT (3a)─│ (Redis)    │
│        │                │                  │                  └────────────┘
│        │                │                  │ Cache MISS (3b)
│        │                │                  │        │
│        │                │                  │        ▼
│        │                │                  │ ┌───────────────┐
│        │                │                  │ │ Database      │
│        │                │                  │ │ (RDS/Postgres)│
│        │                │                  │ └──────┬────────┘
│        │                │                  │        │ 4. Trả dữ liệu
│        │                │                  │<───────┘
│        │                │                  │
│        │                │                  │ ──5. Ghi vào Cache (kèm TTL)──> ElastiCache
│        │<──6. Trả kết quả──────────────────│
└────────┘
```

1. **Mô hình Cache-Aside (Đọc lười - Lazy Loading):**
   - Ứng dụng luôn kiểm tra Cache trước. Nếu có dữ liệu (**Cache Hit**), trả về ngay lập tức.
   - Nếu không có (**Cache Miss**), ứng dụng đọc dữ liệu từ Database, trả kết quả cho client, đồng thời ghi bản ghi đó vào Cache kèm thời gian sống (**TTL - Time To Live**, ví dụ 300 giây).
2. **Hiện tượng Cache Stampede (Thundering Herd Problem):**
   - Xảy ra khi một key rất "hot" (ví dụ: thông tin khuyến mãi Flash Sale) vừa hết hạn TTL đúng lúc có 10,000 requests ập đến trong cùng một giây.
   - Toàn bộ 10,000 request đều gặp Cache Miss và cùng lúc lao thẳng xuống Database để truy vấn và ghi lại cache, khiến Database lập tức quá tải CPU 100% và sụp đổ dây chuyền.
   - **Giải pháp phòng ngừa:**
     - Thêm độ lệch ngẫu nhiên vào TTL (**Jittering TTL**, ví dụ: `TTL = 300s + random(0, 30s)`) để các key không hết hạn đồng thời.
     - Sử dụng Distributed Lock (Redis Mutex) để chỉ cho phép duy nhất 1 request chạy xuống DB cập nhật cache, các request khác chờ vài mili-giây để đọc lại từ cache.
3. **Chiến lược khi Cache Sập (Resilience against Cache Failure):**
   - Cache chỉ là lớp tối ưu hiệu năng, **không bao giờ được là nguồn lưu trữ chuẩn (Source of Truth)**.
   - Code ứng dụng phải có khối `try/catch` bọc quanh các thao tác gọi Redis. Nếu Redis timeout hoặc sập kết nối, ứng dụng phải tự động fallback đọc tạm từ Database thay vì văng lỗi 500 cho người dùng.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 07)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. Viết bảng access pattern, rồi tạo bảng **DynamoDB** (Composite Key `order_id` + `item_id`) và **GSI** theo `customer_id` + `created_at`.
2. Negative case: strongly consistent read trên GSI bị từ chối; **Conditional Write** và optimistic locking với hai worker cạnh tranh.
3. **RDS PostgreSQL** trong private subnet, mật khẩu do RDS quản lý trong Secrets Manager, kết nối từ client trong VPC qua Session Manager; seed dữ liệu và đo query trước/sau index.
4. Snapshot → "xóa nhầm" → restore sang instance mới → chứng minh dữ liệu về và đo thời gian restore.
5. Tùy chọn: cache-aside với ElastiCache, quan sát hit/miss, stale data và fallback khi cache lỗi.
6. Cleanup theo dependency và kiểm tra inventory (kể cả manual snapshot).

👉 Mở bài hướng dẫn chi tiết tại: **[labs/07-databases-and-cache/README.md](../labs/07-databases-and-cache/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Ứng dụng không thể kết nối tới RDS (Connection Timeout)
- **Triệu chứng:** `psql: error: connection to server at "...rds.amazonaws.com", port 5432 failed: Operation timed out`.
- **Nguyên nhân cốt lõi:** Lỗi định tuyến mạng hoặc Security Group, hoàn toàn chưa liên quan đến username/password.
- **Cách khắc phục:**
  - Kiểm tra xem máy chủ ứng dụng và RDS có nằm trong cùng VPC không.
  - Kiểm tra Inbound Rules của Security Group gắn trên RDS: Đã mở cổng 5432 cho Security Group của ứng dụng chưa.
  - Đảm bảo RDS Instance nằm trong Private Subnet có Route Table trỏ đúng dải mạng nội bộ.

### 2. Lỗi `FATAL: remaining connection slots are reserved` trên PostgreSQL
- **Nguyên nhân:** Số lượng kết nối vượt quá tham số `max_connections` của RDS (thường tỷ lệ thuận với dung lượng RAM của instance).
- **Cách khắc phục:** Triển khai **RDS Proxy** để chia sẻ pool kết nối giữa các application workers, hoặc cấu hình connection pooler ở tầng ứng dụng (PgBouncer, HikariCP) với giới hạn `maximumPoolSize` hợp lý.

### 3. DynamoDB trả về lỗi `ProvisionedThroughputExceededException` hoặc bị nghẽn (Throttling)
- **Nguyên nhân:** Bảng bị hiện tượng **Hot Partition** (quá nhiều request đọc/ghi dồn vào một giá trị Partition Key duy nhất, ví dụ chọn Partition Key là `status = "PENDING"` khiến mọi đơn hàng mới đều rơi vào 1 partition vật lý).
- **Cách khắc phục:** Thiết kế lại Partition Key có độ phân tán cao (High Cardinality) như UUID, hoặc thêm tiền tố ngẫu nhiên (Write Sharding, ví dụ `ORDER#2026-10-31#<random_1_to_10>`).

---

## Câu hỏi tự kiểm tra

1. Khi nào bạn nên chọn Amazon DynamoDB thay vì một hệ quản trị quan hệ truyền thống như Amazon RDS PostgreSQL?
2. Trình bày sự khác biệt cốt lõi giữa **RDS Multi-AZ Deployment** và **RDS Read Replica**? Tại sao không thể dùng Read Replica để thay thế cho cơ chế sao lưu (Backup)?
3. Thao tác `Scan` trong DynamoDB gây ra những tác hại nghiêm trọng nào đối với hiệu năng và ngân sách dự án? Khi nào là trường hợp ngoại lệ duy nhất được dùng `Scan`?
4. Trình bày hiện tượng **Cache Stampede (Thundering Herd)** và hai kỹ thuật kỹ thuật phần mềm phổ biến để triệt tiêu hiện tượng này?
5. Tại sao khi thực hiện khôi phục dữ liệu từ Snapshot hoặc Point-In-Time Recovery (PITR) trong RDS, AWS luôn tạo ra một DB instance hoàn toàn mới thay vì khôi phục đè lên instance cũ?

---

## Tài liệu tham khảo chính thức

- [Amazon RDS User Guide](https://docs.aws.amazon.com/AmazonRDS/latest/UserGuide/)
- [Amazon Aurora Architecture and Best Practices](https://docs.aws.amazon.com/AmazonRDS/latest/AuroraUserGuide/CHAP_AuroraOverview.html)
- [Amazon DynamoDB Developer Guide](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/)
- [Choosing between Query and Scan in DynamoDB](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/bp-query-scan.html)
- [Amazon ElastiCache for Redis User Guide](https://docs.aws.amazon.com/AmazonElastiCache/latest/red-ug/)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** ba topology Multi-AZ (DB instance, DB cluster, Aurora); kiến trúc storage của Aurora; restore luôn tạo instance mới; managed master password qua Secrets Manager; GSI chỉ eventually consistent; hành vi xóa nền của DynamoDB TTL; RDS Proxy.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output và số đo là minh họa.
- **Chi phí:** lab dùng DynamoDB on-demand, RDS `db.t4g.micro` Single-AZ và một EC2 `t4g.nano`, khoảng $0.05–0.08/giờ (xem bảng trong lab). Không dựa vào Free Tier vì điều kiện khác nhau giữa account cũ và mới. Manual snapshot phải xóa thủ công.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Bạn nên chọn **Amazon DynamoDB** khi:
   - Hệ thống đòi hỏi khả năng mở rộng quy mô lớn (Horizontal Scalability) với hàng chục ngàn đến hàng triệu RPS mà không muốn phải quản trị cụm máy chủ hay cấu hình sharding thủ công.
   - Mô hình truy cập dữ liệu (Access Pattern) đã được xác định rõ ràng ngay từ đầu (chủ yếu là tra cứu theo khóa chính Key-Value hoặc dải Sort Key).
   - Đòi hỏi độ trễ đọc/ghi cực thấp và ổn định ở mức mili-giây (Single-digit millisecond latency).
   - Ngược lại, nên chọn **RDS** khi dữ liệu có nhiều mối quan hệ phức tạp, cần thực hiện nhiều câu truy vấn `JOIN`, báo cáo phân tích động với nhiều tiêu chí chưa dự đoán trước, hoặc bắt buộc phải có ACID transaction trên nhiều bảng dữ liệu khác nhau.
2. Sự khác biệt:
   - **Multi-AZ (DB instance deployment):** Phục vụ tính sẵn sàng cao (High Availability). Sao chép đồng bộ (Synchronous), node Standby không nhận traffic đọc. Khi sự cố xảy ra, failover tự động, thường 60–120 giây, với cùng endpoint. (Multi-AZ DB cluster thì có 2 standby đọc được.)
   - **Read Replica:** Phục vụ mở rộng năng lực đọc (Read Scaling). Sao chép bất đồng bộ (Asynchronous), node Replica mở phục vụ các câu lệnh `SELECT`. Có endpoint riêng.
   - **Không thể thay thế Backup:** Vì Read Replica sao chép bất đồng bộ tức thời mọi thay đổi từ Primary. Nếu một kỹ sư chạy nhầm lệnh `DROP TABLE orders;` trên Primary, lệnh xóa đó sẽ lập tức được stream sang Read Replica và xóa sạch dữ liệu trên Replica chỉ sau vài mili-giây! Muốn cứu dữ liệu, bạn bắt buộc phải dùng bản Snapshot hoặc tính năng PITR để quay ngược thời gian.
3. Tác hại của `Scan`:
   - `Scan` đọc tuần tự toàn bộ bảng qua tất cả các phân vùng vật lý. Khi bảng có hàng triệu dòng, nó tiêu tốn toàn bộ Read Capacity Units, làm chậm mọi request của người dùng thật và khiến hóa đơn AWS tăng vọt.
   - Trường hợp ngoại lệ duy nhất: Khi bảng dữ liệu có quy mô rất nhỏ (vài chục bản ghi cấu hình hệ thống), hoặc trong các batch job chạy ngầm ban đêm để export dữ liệu phân tích ra Data Lake (S3).
4. **Cache Stampede** là hiện tượng một key rất phổ biến vừa hết hạn TTL thì hàng ngàn request cùng lúc nhận Cache Miss và đồng loạt lao xuống Database truy vấn, làm DB chết đứng vì quá tải.
   - Hai kỹ thuật phòng chống:
     - **Jittering TTL:** Cộng thêm một khoảng thời gian ngẫu nhiên nhỏ vào TTL của mỗi item để phân tán thời điểm hết hạn của các key.
     - **Mutex / Distributed Lock:** Khi gặp Cache Miss, luồng đầu tiên sẽ chiếm lock trong Redis (`SET key NX EX 5`) và đi truy vấn DB để ghi lại cache; các luồng khác thấy lock đang bận sẽ chờ ngắn (vài ms) rồi đọc lại từ cache. Lock phải có TTL để không kẹt khi luồng giữ lock chết.
5. AWS luôn tạo một DB instance hoàn toàn mới vì:
   - **An toàn tuyệt đối:** Tránh nguy cơ ghi đè làm mất vĩnh viễn dữ liệu hiện tại của database đang chạy nếu thao tác restore bị nhầm lẫn thời điểm.
   - **Kiểm chứng song song:** Cho phép kỹ sư kết nối vào database mới khôi phục để đối chiếu, chạy thử nghiệm kiểm tra tính toàn vẹn dữ liệu trước khi chính thức chuyển đổi Endpoint của ứng dụng sang instance mới.

</details>

