# Bài 04 — Mạng trong AWS: VPC, Subnet, Route Table, IGW, NAT và Security Group

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 5–6 giờ, chia hai buổi. Buổi 1 gồm phần 1–4 (lý thuyết & kiến trúc mạng). Buổi 2 gồm phần 5–7 và [lab thực hành](../labs/04-networking/README.md).
> **Kế hoạch gốc:** [plans/04-networking.md](../plans/04-networking.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Giải thích ký hiệu **CIDR**, các dải địa chỉ IP Private theo RFC 1918 và quy tắc **5 địa chỉ IP dự trữ** trong mỗi subnet của AWS.
2. Phân biệt phạm vi của **VPC (Regional)** và **Subnet (Zonal)**; giải thích vì sao một subnet không thể trải dài qua nhiều Availability Zone.
3. Hiểu cơ chế hoạt động của **Route Table**, **Local Route** và **Internet Gateway (IGW)**; định nghĩa chính xác về mặt kỹ thuật thế nào là **Public Subnet** và **Private Subnet**.
4. Phân tích giải pháp kết nối Internet một chiều (Outbound) cho Private Subnet bằng **NAT Gateway** và so sánh với **VPC Endpoint** (Gateway Endpoint vs Interface Endpoint/PrivateLink) về mặt bảo mật và chi phí.
5. So sánh chuyên sâu giữa **Security Group (Stateful)** và **Network ACL (Stateless)**, bao gồm cơ chế theo dõi trạng thái kết nối và dải cổng tạm thời (Ephemeral Ports).
6. Nắm vững đường đi của gói tin (Packet Flow / Request Path) qua các lớp bảo vệ và vai trò của **VPC Flow Logs** trong việc chẩn đoán sự cố mạng.

---

## Phần 1 — Không gian địa chỉ: CIDR, Private IP và Cấu trúc Subnet (45 phút)

### Ký hiệu CIDR (Classless Inter-Domain Routing)

Trong mạng IP, một khối địa chỉ được biểu diễn dưới dạng `IPv4/prefix_length`:
- Prefix length (số sau dấu gạch chéo) đại diện cho số bit cố định của phần mạng (Network ID).
- Số bit còn lại là dành cho các máy trạm (Host ID): `Số IP lý thuyết = 2^(32 - prefix)`.

| Ký hiệu CIDR | Mặt nạ mạng (Subnet Mask) | Số địa chỉ IP lý thuyết | Trường hợp sử dụng điển hình trong AWS |
|---|---|---|---|
| `/16` | `255.255.0.0` | 65,536 | Kích thước chuẩn cho một Virtual Private Cloud (VPC) |
| `/20` | `255.255.240.0`| 4,096 | Subnet lớn cho cụm Kubernetes (EKS) hoặc microservices |
| `/24` | `255.255.255.0`| 256 | Kích thước chuẩn phổ biến cho Subnet trong lab và ứng dụng vừa |
| `/28` | `255.255.255.240`| 16 | Subnet nhỏ nhất được phép tạo trong AWS |

### Các dải địa chỉ Private chuẩn (RFC 1918)
AWS VPC cho phép bạn sử dụng bất kỳ dải IP riêng tư nào sau đây mà không sợ bị trùng lặp với Internet công cộng:
- `10.0.0.0/8` (từ `10.0.0.0` đến `10.255.255.255`) — *Phổ biến nhất trong thiết kế doanh nghiệp*.
- `172.16.0.0/12` (từ `172.16.0.0` đến `172.31.255.255`) — *Default VPC của AWS mặc định dùng dải `172.31.0.0/16`*.
- `192.168.0.0/16` (từ `192.168.0.0` đến `192.168.255.255`) — *Thường dùng cho mạng gia đình hoặc văn phòng nhỏ*.

> [!WARNING]
> **Tránh trùng lặp CIDR (CIDR Overlap):**
> Nếu công ty bạn dự định kết nối VPC này với mạng nội bộ văn phòng (On-premises) qua VPN/Direct Connect, hoặc nối với VPC khác qua VPC Peering / Transit Gateway, **tuyệt đối không chọn dải CIDR trùng lặp**. Nếu cả hai bên đều dùng `10.0.0.0/16`, việc định tuyến gói tin sẽ bị xung đột và không thể giao tiếp được.

### 5 địa chỉ IP dự trữ của AWS trong mỗi Subnet

Nếu bạn tạo một subnet `/24` có 256 địa chỉ IP (ví dụ `10.0.1.0/24`), bạn **không có đủ 256 IP** để gán cho máy chủ. AWS **tự động dành riêng 5 địa chỉ IP** cho hạ tầng:

```text
Dải IP Subnet: 10.0.1.0/24 (Tổng 256 IPs)
├── 10.0.1.0   : Network address (địa chỉ mạng).
├── 10.0.1.1   : AWS VPC Router nội bộ (Default Gateway của subnet).
├── 10.0.1.2   : Dành riêng cho DNS (DNS server thực tế của VPC nằm ở base VPC + 2, ví dụ 10.0.0.2).
├── 10.0.1.3   : AWS dành riêng cho mục đích sử dụng trong tương lai.
├── 10.0.1.4 đến 10.0.1.254 : (251 IPs khả dụng cho ứng dụng của bạn)
└── 10.0.1.255 : Network broadcast address (AWS không hỗ trợ broadcast, nhưng vẫn giữ chỗ).
```

Số IP thực tế có thể sử dụng = `2^(32 - prefix) - 5`. Với subnet `/24`, bạn có chính xác **251 IP khả dụng**.

---

## Phần 2 — VPC, Subnet, Route Table và Internet Gateway (50 phút)

### Phạm vi địa lý: VPC là Regional, Subnet là Zonal

```text
AWS Region (vd: ap-southeast-1 - Singapore)
┌─────────────────────────────────────────────────────────────────────────────┐
│ Virtual Private Cloud (VPC): 10.0.0.0/16                                   │
│                                                                             │
│   Availability Zone 1 (ap-southeast-1a)  Availability Zone 2 (ap-southeast-1b)
│   ┌──────────────────────────────────┐   ┌──────────────────────────────────┐
│   │ Public Subnet A (10.0.1.0/24)    │   │ Public Subnet B (10.0.2.0/24)    │
│   └──────────────────────────────────┘   └──────────────────────────────────┘
│   ┌──────────────────────────────────┐   ┌──────────────────────────────────┐
│   │ Private Subnet A (10.0.10.0/24)  │   │ Private Subnet B (10.0.20.0/24)  │
│   └──────────────────────────────────┘   └──────────────────────────────────┘
└─────────────────────────────────────────────────────────────────────────────┘
```

- **VPC là tài nguyên Regional:** Trải rộng trên toàn bộ các Availability Zone trong một Region.
- **Subnet là tài nguyên Zonal:** Khi tạo subnet, bạn **bắt buộc phải gắn nó vào duy nhất một AZ**. Không có subnet nào có thể vắt ngang qua 2 AZ. Muốn đạt tính sẵn sàng cao (High Availability), bạn phải tạo ít nhất 2 subnet ở 2 AZ độc lập.

### Định nghĩa kỹ thuật: Public Subnet vs Private Subnet

Trong AWS, thuật ngữ "Public Subnet" và "Private Subnet" **không phải là thuộc tính cấu hình trực tiếp** trên subnet, mà được quyết định hoàn toàn bởi **Route Table (Bảng định tuyến)** gắn với nó:

1. **Local Route (Bắt buộc):** Khi tạo VPC, một Route Table luôn có một route mặc định cho dải CIDR của VPC trỏ tới `local` (ví dụ: `10.0.0.0/16 -> local`). Route này **không thể xóa**, đảm bảo mọi máy chủ trong cùng VPC luôn định tuyến được tới nhau qua private IP (còn có được phép kết nối hay không là do SG/NACL). Trường hợp nâng cao, target của local route có thể được đổi sang một network appliance để kiểm tra traffic, nằm ngoài phạm vi bài này.
2. **Internet Gateway (IGW):** Một thành phần VPC có tính sẵn sàng cao, phân tán và co giãn tự động của AWS, đóng vai trò là cửa ngõ chuyển đổi địa chỉ (1-to-1 NAT) giữa Private IP của instance và Public IP trên Internet.
3. **Định nghĩa chuẩn:**
   - **Public Subnet:** Là subnet có Route Table chứa một route trỏ ra Internet Gateway:
     `0.0.0.0/0 -> igw-xxxxxxxx`
   - **Private Subnet:** Là subnet mà Route Table **không có route trực tiếp tới Internet Gateway**. Gói tin từ subnet này không thể tự ý đi thẳng ra internet công cộng.

```text
[ Public Route Table ]
Destination       Target
10.0.0.0/16       local          (Giao tiếp nội bộ VPC)
0.0.0.0/0         igw-0123abcd   (Đi ra ngoài Internet qua IGW) ──► PUBLIC SUBNET

[ Private Route Table ]
Destination       Target
10.0.0.0/16       local          (Chỉ giao tiếp nội bộ VPC)     ──► PRIVATE SUBNET
```

> [!NOTE]
> Một EC2 instance nằm trong Public Subnet muốn giao tiếp được 2 chiều với Internet cần thỏa mãn **đủ 3 điều kiện**:
> 1. Subnet có route `0.0.0.0/0 -> IGW`.
> 2. Instance được gán một **Public IPv4** (hoặc Elastic IP).
> 3. Security Group và NACL cho phép lưu lượng truy cập tương ứng.

---

## Phần 3 — NAT Gateway và VPC Endpoints (45 phút)

Các máy chủ ứng dụng chứa cơ sở dữ liệu hoặc logic thanh toán (Order Service) **phải nằm trong Private Subnet** để bảo vệ khỏi sự tấn công trực diện từ Internet. Nhưng làm sao các máy chủ này có thể tải bản vá bảo mật (`apt update` / `dnf upgrade`) hoặc gọi webhook ra cổng thanh toán bên ngoài?

### NAT Gateway (Network Address Translation)

AWS NAT Gateway là dịch vụ quản lý giúp các máy ảo trong Private Subnet khởi tạo kết nối **một chiều đi ra ngoài Internet**, đồng thời ngăn chặn bất kỳ ai từ Internet khởi tạo kết nối đi vào.

```text
INTERNET
   ▲
   │ (Lưu lượng 2 chiều)
   ▼
[ Internet Gateway ]
   ▲
   │
[ Public Subnet ]
   │
   └──► [ NAT Gateway (Gắn Elastic IP công cộng) ]
           ▲
           │ (Chỉ cho phép outbound - một chiều)
[ Private Subnet ]
   │
   └──► [ EC2 Private (Chỉ có IP nội bộ 10.0.10.5) ]
```

**Đặc điểm kiến trúc của NAT Gateway:**
- Public NAT Gateway (loại phổ biến, zonal) được đặt trong **Public Subnet** và gắn một **Elastic IP**.
- Trong Private Route Table, bạn cấu hình: `0.0.0.0/0 -> nat-xxxxxxxx`.
- **Chi phí:** NAT Gateway tính phí theo **giờ tồn tại** + theo **GB dữ liệu xử lý**, cộng phí public IPv4 của EIP (xem Bài 03). Với giá minh họa, một NAT Gateway để quên cả tháng tốn khoảng $40–50 dù không có traffic. Tra giá thực tế theo Region trên trang [VPC pricing](https://aws.amazon.com/vpc/pricing/).
- **Đánh đổi:** NAT Gateway là zonal. Production thường đặt mỗi AZ một NAT để một AZ hỏng không làm mất outbound của AZ còn lại (chi phí nhân theo số AZ). Lab thì dùng một NAT duy nhất, chỉ bật khi cần và xóa ngay sau khi xong.

### VPC Endpoints: Đường cao tốc riêng tư tới dịch vụ AWS

Nếu máy chủ trong Private Subnet chỉ cần giao tiếp với các dịch vụ khác của AWS (như tải dữ liệu từ Amazon S3, đọc ghi bảng DynamoDB, hoặc ghi logs lên CloudWatch), bạn **không cần phải dùng NAT Gateway đi vòng qua Internet**! Thay vào đó, hãy dùng **VPC Endpoints**:

```text
┌────────────────────────────────────────────────────────┐
│ Virtual Private Cloud (VPC)                            │
│                                                        │
│ [ Private Subnet ]                                     │
│   EC2 Private ────────► VPC Endpoint (Gateway/Interface)│
│                             │                          │
└─────────────────────────────┼──────────────────────────┘
                              │ Đi qua mạng nội bộ của AWS
                              ▼
              [ S3 / DynamoDB / SSM / ECR / ... ]
```

Có hai loại VPC Endpoint:
1. **Gateway Endpoints:**
   - Hỗ trợ cho 2 dịch vụ: **Amazon S3** và **Amazon DynamoDB**.
   - **Hoàn toàn miễn phí** (không tính phí duy trì cổng hay phí truyền dữ liệu).
   - Cơ chế: Tự động chèn một route vào Route Table của subnet (ví dụ `pl-xxxx (S3 prefix) -> vpce-xxxx`).
2. **Interface Endpoints (AWS PrivateLink):**
   - Hỗ trợ hầu hết các dịch vụ AWS còn lại (ECR, Secrets Manager, CloudWatch, SQS, STS...).
   - Cơ chế: Tạo một card mạng ảo **Elastic Network Interface (ENI)** với IP private nằm trực tiếp bên trong subnet của bạn.
   - Có tính phí theo giờ **cho mỗi endpoint ở mỗi AZ** + phí theo GB dữ liệu xử lý. Một service cần nhiều endpoint (ví dụ SSM cần `ssm` và `ssmmessages`) thì chi phí nhân lên tương ứng.

### Ví dụ: Systems Manager (SSM) từ private subnet

SSM Session Manager (dùng ở Bài 05) cho phép quản trị instance mà không cần SSH. Nhưng **private subnet không mặc nhiên kết nối được SSM**. Instance cần đủ ba điều kiện:

1. SSM Agent đang chạy (có sẵn trên Amazon Linux 2023).
2. Instance profile có quyền tương đương managed policy `AmazonSSMManagedInstanceCore`.
3. **Đường mạng outbound HTTPS (443)** tới SSM endpoints, qua một trong hai cách:
   - NAT Gateway (instance đi ra internet tới endpoint public của SSM), hoặc
   - Interface endpoints `com.amazonaws.<region>.ssm` và `com.amazonaws.<region>.ssmmessages` (agent cũ có thể cần thêm `ec2messages`), với SG của endpoint cho phép 443 từ instance và VPC bật DNS hostnames/support.

Thiếu điều kiện 3, instance vẫn chạy bình thường nhưng hiện "offline" trong Session Manager.

---

## Phần 4 — Security Groups (Stateful) vs Network ACLs (Stateless) (45 phút)

Bảo mật mạng trong AWS được xây dựng theo mô hình nhiều lớp (Defense in Depth):

```text
Gói tin từ Internet
       │
       ▼
 [ Network ACL (NACL) ]    ◄─── Tường lửa cấp Subnet (Stateless)
       │
       ▼
[ Security Group (SG) ]    ◄─── Tường lửa cấp Card mạng / Instance (Stateful)
       │
       ▼
  [ EC2 Instance ]
```

### Bảng so sánh toàn diện:

| Tiêu chí | Security Group (SG) | Network ACL (NACL) |
|---|---|---|
| **Cấp độ áp dụng** | Gắn trên từng **Network Interface (ENI)** của instance/ALB. | Gắn trên ranh giới của toàn bộ **Subnet**. |
| **Loại trạng thái (State)** | **Stateful:** Tự động nhớ trạng thái kết nối. | **Stateless:** Hoàn toàn không nhớ trạng thái. |
| **Quy tắc (Rules)** | Chỉ hỗ trợ quy tắc **ALLOW**. SG mới: inbound trống (chặn tất cả), outbound mặc định **cho phép tất cả**. | Hỗ trợ cả **ALLOW** lẫn **DENY**. **Default NACL** của VPC cho phép tất cả hai chiều; **custom NACL** mới tạo chặn tất cả cho đến khi bạn thêm rule. |
| **Thứ tự đánh giá** | Đánh giá **đồng thời tất cả các rules** trước khi quyết định. | Đánh giá theo **thứ tự số thứ tự (Rule Number)** từ thấp đến cao (100, 200...). Rule nào khớp trước sẽ áp dụng ngay. |
| **Lưu lượng phản hồi (Return Traffic)** | **Tự động cho phép** gói tin phản hồi quay lại, bất kể cấu hình outbound/inbound thế nào. | **Phải mở tường minh cả 2 chiều:** Inbound Rule và Outbound Rule tương ứng. |

### Cơ chế Stateful của Security Group
Khi một client gửi request HTTP tới cổng 80 của EC2:
- Nếu Inbound Rule của Security Group cho phép cổng 80, gói tin được đi vào.
- Khi EC2 phản hồi lại cho client (gói tin response mang cổng nguồn 80 và cổng đích là cổng tạm của client), Security Group **tự động cho phép gói tin đi ra**, ngay cả khi Outbound Rules của SG bị xóa sạch!

### Cơ chế Stateless của NACL và Ephemeral Ports (Cổng tạm thời)
NACL không nhớ trạng thái kết nối. Khi một client kết nối tới cổng 80 của web server, client sẽ dùng một cổng tạm thời ngẫu nhiên (Ephemeral Port, dải cổng từ `1024` đến `65535` tùy hệ điều hành) để nhận dữ liệu phản hồi.
- Do đó, nếu bạn dùng NACL cho Subnet, bạn **bắt buộc phải cấu hình 2 chiều**:
  - Inbound: Cho phép Port 80 từ Internet (`0.0.0.0/0`).
  - Outbound: Cho phép Port `1024-65535` tới Internet (`0.0.0.0/0`) để trả về dữ liệu. Nếu quên mở outbound rule này, server sẽ nhận được request nhưng client sẽ bị treo và timeout!

### Kỹ thuật xâu chuỗi Security Group (Security Group Chaining)
Một trong những sức mạnh lớn nhất của Security Group là khả năng **tham chiếu một Security Group khác làm Source**, thay vì điền địa chỉ IP cố định:

```text
[ Client ngoài Internet ]
         │ (Port 80/443)
         ▼
[ ALB Security Group ] (sg-alb)
         │
         │ (Chỉ cho phép Port 8080 từ Source: sg-alb)
         ▼
[ App Security Group ] (sg-app)
         │
         │ (Chỉ cho phép Port 5432 từ Source: sg-app)
         ▼
[ Database Security Group ] (sg-db)
```

Với mô hình này, tầng Database chỉ chấp nhận kết nối từ tầng App, và tầng App chỉ chấp nhận kết nối từ Load Balancer. Không ai có thể chọc thẳng vào database từ bên ngoài!

---

## Phần 5 — DNS nội bộ, Request Flow và VPC Flow Logs (30 phút)

### DNS trong VPC
Mỗi VPC có hai thuộc tính boolean quan trọng:
- `enableDnsSupport`: Bật DNS server nội bộ của AWS (Route 53 Resolver), truy cập tại **base của VPC CIDR + 2** (ví dụ `10.0.0.2` với VPC `10.0.0.0/16`) hoặc `169.254.169.253`.
- `enableDnsHostnames`: Cấp public DNS hostname cho instance có public IP. Thuộc tính này (cùng `enableDnsSupport`) cũng là điều kiện để dùng **private DNS của interface endpoint**, ví dụ để `ssm.<region>.amazonaws.com` phân giải về IP private của endpoint.

### Đường đi của một request (request/response path)

Khi client ngoài internet gọi `http://<public-ip>` của một EC2 trong public subnet:

```text
Client ──► IGW (dịch public IP → private IP) ──► Route table subnet ──► NACL inbound (port 80)
       ──► SG inbound (port 80) ──► Ứng dụng
Ứng dụng trả lời ──► SG (stateful, tự cho phép) ──► NACL outbound (port ephemeral của client!)
       ──► Route 0.0.0.0/0 → IGW ──► Client
```

Mỗi mũi tên là một chỗ có thể làm request thất bại. Khi debug timeout, đi lần lượt từng chặng theo **cả hai chiều**, kể cả bước phân giải DNS trước khi gửi request.

### VPC Flow Logs
VPC Flow Logs ghi nhận metadata của traffic IP đi qua các ENI (bật ở cấp VPC, subnet hoặc từng ENI), gửi về CloudWatch Logs, S3 hoặc Firehose. Lưu ý:
- Flow Logs ghi theo **flow được gộp trong một khoảng thời gian** (1 hoặc 10 phút), không ghi từng gói tin và không chứa payload.
- Một số traffic **không được ghi**, ví dụ: truy vấn tới Amazon DNS server, DHCP, Instance Metadata (`169.254.169.254`).
- Flow Logs có chi phí (ingestion/lưu trữ log) và có độ trễ vài phút.

Mỗi dòng log chứa thông tin cốt lõi:
```text
<version> <account-id> <interface-id> <srcaddr> <dstaddr> <srcport> <dstport> <protocol> <packets> <bytes> <start> <end> <action> <log-status>
2 111122223333 eni-01234567 198.51.100.25 10.0.1.5 54321 80 6 10 840 1600000000 1600000060 ACCEPT OK
2 111122223333 eni-01234567 203.0.113.10  10.0.1.5 43210 22 6  4  160 1600000000 1600000060 REJECT OK
```
Nhìn vào trường `<action>`:
- `ACCEPT`: Gói tin được cho phép đi qua bởi cả Security Group và NACL.
- `REJECT`: Traffic bị chặn bởi Security Group hoặc NACL. Đây là bằng chứng quan trọng khi debug lỗi mạng không thông.
- Mẹo: nếu thấy inbound `ACCEPT` nhưng chiều trả về `REJECT`, thủ phạm thường là **NACL outbound** thiếu dải ephemeral port (SG là stateful nên không gây ra kiểu lỗi này).
- Nếu **không có dòng log nào**, traffic có thể chưa tới được ENI (sai route, sai DNS, chặn ở phía client) hoặc thuộc loại không được ghi.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 04)

Trong bài thực hành đi kèm:
- Bạn sẽ tự tay thiết kế và triển khai một **Custom Lab VPC** mang dải CIDR `10.0.0.0/16` trải dài trên **2 Availability Zones**.
- Tạo 2 Public Subnets và 2 Private Subnets.
- Khởi tạo và gắn một **Internet Gateway (IGW)** vào VPC.
- Tạo **Public Route Table** (chứa route trỏ ra IGW) và gán vào 2 public subnets; tạo **Private Route Table** (chỉ có local route) và gán vào 2 private subnets.
- Thiết lập mô hình **xâu chuỗi Security Group (SG Chaining)** giữa Web Tier và App Tier.
- Negative test miễn phí: tạo subnet sai kích thước/ngoài CIDR, xóa VPC khi còn dependency.
- Nhánh tùy chọn (chi phí vài cent): chạy một EC2 tạm trong public subnet để kiểm chứng route tới IGW, tính stateful của SG và tính stateless của NACL (ephemeral ports).
- Dọn dẹp sạch toàn bộ tài nguyên theo đúng thứ tự phụ thuộc. Bài 05 sẽ tự tạo lại mạng riêng, nên không cần giữ VPC này.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/04-networking/README.md](../labs/04-networking/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi mạng phổ biến (25 phút)

### 1. Kết nối bị timeout
- **Triệu chứng:** `curl` treo rồi báo timeout (khác với `Connection refused`, nghĩa là đã tới máy nhưng không có process lắng nghe cổng đó).
- **Đi theo request path, cả hai chiều:**
  1. **DNS:** tên miền có phân giải ra đúng IP không (`dig`/`nslookup`)?
  2. **Route:** subnet được gắn route table nào (`describe-route-tables --filters Name=association.subnet-id,Values=<subnet>`)? Có `0.0.0.0/0 → igw-...` không? Instance có public IP không?
  3. **Security Group:** inbound có mở cổng cho đúng nguồn không?
  4. **NACL:** inbound có cho phép cổng đích, và **outbound có cho phép dải ephemeral `1024-65535`** để trả về không?
  5. **Bằng chứng:** bật VPC Flow Logs trên ENI để xem `ACCEPT`/`REJECT` ở từng chiều.
- **Sửa có phạm vi nhỏ:** thêm đúng route/rule còn thiếu; không mở `0.0.0.0/0` tất cả cổng để "thử cho chạy".

### 2. Instance không hiện trong SSM Session Manager
- Kiểm tra lần lượt ba điều kiện ở Phần 3: agent, instance profile, đường outbound 443 tới SSM (NAT hoặc interface endpoints `ssm` + `ssmmessages`). Instance trong private subnet chỉ có local route là trường hợp hay gặp nhất.

### 3. CIDR overlap
- **Triệu chứng:** không tạo được VPC peering/Transit Gateway attachment, hoặc route bị xung đột, không đi tới mạng bên kia.
- **Phòng tránh:** lên kế hoạch dải IP cho mọi VPC và mạng on-premises **trước khi** tạo. Không thể đổi CIDR chính của VPC sau khi tạo; chỉ có thể thêm secondary CIDR hoặc xây lại.

### 4. Không thể xóa VPC (`DependencyViolation`)
- **Hiện tượng:** Chạy lệnh `delete-vpc` bị lỗi `The vpc 'vpc-xxxx' has dependencies and cannot be deleted`.
- **Nguyên nhân:** Trong VPC vẫn còn ENI (của EC2, ALB, NAT, VPC endpoint, Lambda...), Internet Gateway chưa detach, subnet, route table, NACL hoặc Security Group tùy biến chưa xóa.
- **Bằng chứng:** `aws ec2 describe-network-interfaces --filters Name=vpc-id,Values=<vpc-id>` cho biết ENI nào còn sót và thuộc dịch vụ gì (`InterfaceType`, `Description`).
- **Thứ tự dọn dẹp chuẩn:**
  1. Terminate EC2, xóa Load Balancer, xóa VPC endpoints; đợi ENI của chúng biến mất.
  2. Xóa NAT Gateway và đợi trạng thái `deleted`, sau đó release Elastic IP.
  3. Xóa Security Groups tùy biến (SG tham chiếu SG khác thì xóa trước).
  4. Xóa Subnets (association với route table/NACL tự mất theo).
  5. Xóa Custom Route Tables và Custom NACLs.
  6. Detach rồi xóa Internet Gateway.
  7. Xóa VPC (default SG, main route table, default NACL tự xóa theo).

---

## Câu hỏi tự kiểm tra

1. Một subnet có dải CIDR `10.0.2.0/23` có tổng cộng bao nhiêu địa chỉ IP lý thuyết, và bao nhiêu địa chỉ IP thực tế có thể cấp phát cho các máy ảo?
2. Điều gì phân biệt một Public Subnet và một Private Subnet trong AWS VPC?
3. Nếu Security Group Inbound Rule cho phép cổng 443 từ `0.0.0.0/0`, nhưng Security Group Outbound Rules bị xóa hết không còn dòng nào, client gửi request HTTPS tới server có nhận được kết quả không? Vì sao?
4. Tại sao một máy chủ nằm trong Private Subnet khi kết nối ra ngoài Internet thông qua NAT Gateway thì người bên ngoài Internet lại không thể chủ động kết nối vào máy chủ đó?
5. Sự khác biệt cơ bản về mặt chi phí và phạm vi dịch vụ giữa Gateway Endpoint và Interface Endpoint là gì?
6. Một instance có public IP nằm trong subnet chỉ có local route. Instance đó có truy cập được internet không? Public IP có đủ không?
7. Security Group cho phép inbound port 80 và request tới được server, nhưng client vẫn timeout. NACL inbound cho phép port 80. Giả thuyết hợp lý nhất là gì, và bạn kiểm chứng bằng cách nào?

---

## Tài liệu tham khảo chính thức

- [What is Amazon VPC?](https://docs.aws.amazon.com/vpc/latest/userguide/what-is-amazon-vpc.html)
- [VPCs and subnets](https://docs.aws.amazon.com/vpc/latest/userguide/configure-subnets.html)
- [Route tables](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Route_Tables.html)
- [Internet gateways](https://docs.aws.amazon.com/vpc/latest/userguide/VPC_Internet_Gateway.html)
- [NAT gateways](https://docs.aws.amazon.com/vpc/latest/userguide/vpc-nat-gateway.html)
- [Security groups](https://docs.aws.amazon.com/vpc/latest/userguide/vpc-security-groups.html)
- [Network ACLs](https://docs.aws.amazon.com/vpc/latest/userguide/vpc-network-acls.html)
- [VPC Endpoints (AWS PrivateLink)](https://docs.aws.amazon.com/vpc/latest/privatelink/concepts.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** 5 địa chỉ IP dự trữ và vị trí DNS server, SG stateful/NACL stateless và giá trị mặc định, Internet Gateway và route table, điều kiện kết nối SSM, giới hạn của Flow Logs, thứ tự xóa tài nguyên VPC.
- **Giá là minh họa:** tra lại trang giá chính thức cho Region của bạn.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output là minh họa.
- **Kiến trúc an toàn:** không chỉnh sửa Default VPC; tạo VPC riêng có tag `Project=aws-lab-04`.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. Dải `/23` có số bit dành cho host là `32 - 23 = 9` bits.
   - Số IP lý thuyết: `2^9 = 512` địa chỉ IP.
   - Số IP thực tế có thể cấp phát: `512 - 5 = 507` địa chỉ IP. 5 IP dự trữ tính trên **toàn subnet**, không phải trên từng dải /24 con: 4 IP đầu `10.0.2.0`–`10.0.2.3` và IP cuối `10.0.3.255`. Các địa chỉ `10.0.2.255` và `10.0.3.0` vẫn cấp phát được bình thường.
2. Public Subnet được liên kết với một Route Table có route mặc định `0.0.0.0/0` trỏ tới **Internet Gateway (IGW)**. Ngược lại, Private Subnet không có route trực tiếp trỏ tới IGW (nó chỉ có local route, hoặc route `0.0.0.0/0` trỏ tới NAT Gateway / VPC Endpoint).
3. **Có nhận được bình thường.** Do Security Group hoạt động theo cơ chế **Stateful (có nhớ trạng thái)**. Khi một gói tin inbound hợp lệ được chấp nhận đi vào, Security Group tự động theo dõi phiên kết nối và cho phép gói tin phản hồi (response) đi ngược ra ngoài mà không cần kiểm tra Outbound Rules.
4. Vì NAT Gateway thực hiện kỹ thuật **Source NAT (SNAT)**: Nó thay thế địa chỉ Private IP của máy chủ bằng địa chỉ Elastic IP công cộng của chính NAT Gateway khi gửi gói tin ra ngoài, và duy trì một bảng trạng thái kết nối (translation table) trong bộ nhớ. Khi có gói tin phản hồi từ bên ngoài về đúng cổng phiên đã mở, NAT Gateway sẽ chuyển tiếp lại cho máy ảo nội bộ. Người ngoài Internet không thể tự ý khởi tạo kết nối đi vào vì NAT Gateway không mở cổng lắng nghe và không có ánh xạ sẵn cho chiều Inbound từ Internet.
5. - **Gateway Endpoint:** Hoàn toàn **miễn phí**, chỉ hỗ trợ cho **S3** và **DynamoDB**, hoạt động bằng cách chèn route vào Route Table của Subnet.
   - **Interface Endpoint:** **Có tính phí** theo giờ cho mỗi endpoint ở mỗi AZ và theo GB dữ liệu xử lý, hỗ trợ hầu hết các dịch vụ AWS khác (và dịch vụ của bên thứ ba qua PrivateLink), hoạt động bằng cách tạo ENI có IP private trong subnet.
6. **Không.** Subnet không có route `0.0.0.0/0 → IGW` nên gói tin ra internet không có đường đi, dù instance có public IP. Public IP chỉ là một trong ba điều kiện (route tới IGW, public IP, SG/NACL cho phép).
7. Giả thuyết: **NACL outbound** của subnet không cho phép dải ephemeral port (`1024-65535`) tới client, nên response bị chặn ở chiều ra (SG không gây ra lỗi này vì nó stateful). Kiểm chứng: xem outbound rules của NACL (`describe-network-acls`), và Flow Logs sẽ cho thấy inbound `ACCEPT` nhưng outbound `REJECT`. Sửa bằng cách thêm đúng rule outbound cho dải ephemeral, không mở mọi thứ.

</details>

