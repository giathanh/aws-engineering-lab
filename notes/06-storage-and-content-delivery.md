# Bài 06 — Lưu trữ và phân phối nội dung: S3, CloudFront, Route 53 và ACM

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 5–7 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Lưu trữ hướng đối tượng Amazon S3, tối ưu chi phí & presigned URLs). Buổi 2 gồm phần 4–7 và [lab thực hành](../labs/06-storage-and-content-delivery/README.md).
> **Kế hoạch gốc:** [plans/06-storage-and-content-delivery.md](../plans/06-storage-and-content-delivery.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Phân biệt lưu trữ hướng đối tượng (**Amazon S3 Object Storage**) với lưu trữ khối (Block Storage - EBS) và hệ thống tệp chia sẻ (File Storage - EFS).
2. Kiểm soát an toàn truy cập S3 thông qua **Block Public Access (BPA)**, **Bucket Policy**, **IAM Policy** và cơ chế cấp quyền tạm thời an toàn bằng **Presigned URLs**.
3. Tối ưu chi phí lưu trữ thông qua **S3 Lifecycle Configuration**, các tầng lưu trữ (**Storage Classes**: Standard, Infrequent Access, Glacier, Intelligent-Tiering) và quản lý phiên bản (**Versioning & Delete Markers**).
4. Phân phối nội dung toàn cầu với độ trễ thấp thông qua **Amazon CloudFront CDN**, bảo vệ nguồn gốc dữ liệu riêng tư bằng **Origin Access Control (OAC)**.
5. Hiểu chiến lược quản lý bộ nhớ đệm (**Cache Key, TTL, Cache Policies**), phân biệt kỹ thuật **Cache Invalidation** tốn kém với **Cache Busting / Versioned Filenames**.
6. Nắm vững cơ chế phân giải tên miền **Amazon Route 53 (Alias Records)** và cấp phát chứng chỉ SSL/TLS miễn phí với **AWS Certificate Manager (ACM)**.

---

## Phần 1 — Amazon S3: Đối tượng, Khóa, Bucket Policy và Bảo mật mặc định (50 phút)

### Kiến trúc Lưu trữ Hướng đối tượng (Object Storage)
Khác với hệ điều hành truyền thống tổ chức file theo cây thư mục trên ổ đĩa, Amazon S3 lưu trữ dữ liệu dưới dạng **Object** trong một không gian phẳng (**Flat namespace**) gọi là **Bucket**.

```text
Mô hình lưu trữ trong S3:
┌────────────────────────────────────────────────────────┐
│ Amazon S3 Bucket: `order-service-artifacts`            │
│ (Tên Bucket duy nhất trên toàn cầu - Globally unique)  │
│                                                        │
│  Key: "invoices/2026/INV-1001.pdf"                     │
│  ┌──────────────────────────────────────────────────┐  │
│  │ Object Data (Payload file binary / text)         │  │
│  ├──────────────────────────────────────────────────┤  │
│  │ Metadata: Content-Type: application/pdf          │  │
│  │           x-amz-meta-order-id: ORD-9921          │  │
│  ├──────────────────────────────────────────────────┤  │
│  │ System Attributes: Size, ETag, VersionId, SHA256 │  │
│  └──────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────┘
```

- **Bucket**: Thùng chứa đối tượng ở cấp cao nhất. Tên bucket phải duy nhất trên toàn cầu (Globally unique trên toàn bộ tài khoản AWS khắp thế giới). Bucket thuộc về một Region cụ thể được chọn khi tạo.
- **Key (Khóa)**: Chuỗi định danh đầy đủ của object (ví dụ: `invoices/2026/INV-1001.pdf`). Dấu gạch chéo `/` chỉ là tiền tố ký tự (Prefix) giúp các công cụ UI/CLI hiển thị trực quan dưới dạng thư mục ảo, chứ bản chất bên dưới S3 không hề có thư mục.
- **Metadata**: Cặp key-value lưu thông tin thuộc tính của file (`Content-Type`, `Cache-Control`) và metadata do người dùng tự định nghĩa (`x-amz-meta-*`).
- **Độ bền dữ liệu (Durability):** S3 Standard cung cấp cam kết độ bền 99.999999999% (11 số 9) bằng cách tự động sao chép dữ liệu ngầm qua tối thiểu 3 Availability Zones trong một Region.

### Cơ chế bảo vệ Block Public Access (BPA)
Trước đây, nhiều sự cố rò rỉ dữ liệu doanh nghiệp xảy ra do kỹ sư cấu hình nhầm quyền công khai (Public Bucket). AWS đã bổ sung tính năng **Block Public Access** ở cả cấp tài khoản (Account-level) và cấp bucket (Bucket-level) với 4 chốt an toàn:

1. `BlockPublicAcls`: Chặn việc gắn ACL công khai vào bucket hoặc object mới.
2. `IgnorePublicAcls`: Bỏ qua toàn bộ quyền công khai nếu có sẵn trong ACL.
3. `BlockPublicPolicy`: Chặn cấp phép Bucket Policy nếu nó chứa quyền cho phép mọi người (`Principal: "*"`).
4. `RestrictPublicBuckets`: Hạn chế quyền truy cập vào bucket chỉ cho các AWS service principals và người dùng được xác thực trong account nếu bucket có public policy.

> [!IMPORTANT]
> **Quy tắc vàng:** Luôn giữ bật toàn bộ 4 cờ Block Public Access cho mọi bucket. Nếu bạn cần phân phối file ra Internet cho người dùng cuối, hãy đưa dữ liệu qua **CloudFront CDN** kết hợp **Origin Access Control (OAC)** hoặc tạo **Presigned URL**.

### Bucket Policy vs IAM Policy
Để kiểm soát ai được đọc/ghi vào S3, AWS kết hợp hai cơ chế:

| Tiêu chí | IAM Policy (Identity-based) | S3 Bucket Policy (Resource-based) |
|---|---|---|
| **Vị trí gắn** | Gắn vào User, Group, hoặc IAM Role. | Gắn trực tiếp vào bản thân S3 Bucket. |
| **Phạm vi** | Kiểm soát một identity có thể làm gì trên các tài nguyên nào. | Kiểm soát tất cả các identity/service nào được phép thao tác trên bucket này. |
| **Truy cập chéo tài khoản (Cross-account)** | Không đủ nếu không có Bucket Policy ở tài khoản đích cấp phép. | Có thể cấp quyền trực tiếp cho IAM Role/Account khác mà không cần tạo user mới. |
| **Quy mô** | Quản trị viên quản lý danh tính nhân sự/máy chủ. | Chủ sở hữu dữ liệu quản lý quy tắc truy cập dữ liệu tập trung. |

Ví dụ S3 Bucket Policy chỉ cho phép ứng dụng đọc dữ liệu:
```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowAppRoleRead",
      "Effect": "Allow",
      "Principal": {
        "AWS": "arn:aws:iam::123456789012:role/OrderServiceRole"
      },
      "Action": [
        "s3:GetObject"
      ],
      "Resource": "arn:aws:s3:::my-order-bucket/invoices/*"
    }
  ]
}
```

### S3 Versioning (Quản lý phiên bản)
Khi kích hoạt **Versioning** trên bucket:
- Mọi thao tác ghi đè (`PUT`) lên một key sẽ không xóa dữ liệu cũ mà tạo ra một phiên bản mới với `VersionId` riêng biệt.
- Thao tác xóa thông thường (`DELETE`) không làm mất dữ liệu vĩnh viễn, mà S3 chỉ đặt một **Delete Marker** (dấu xóa) lên đỉnh ngăn xếp phiên bản. Khi người dùng cố gắng đọc object, S3 thấy Delete Marker và trả về HTTP 404 Not Found.
- Để khôi phục: Bạn chỉ cần xóa Delete Marker đi, phiên bản liền kề trước đó sẽ lập tức xuất hiện trở lại.
- Để xóa vĩnh viễn: Phải chỉ định rõ `VersionId` cần xóa.

---

## Phần 2 — Presigned URLs, Content-Type, CORS và Kiểm tra Toàn vẹn Checksum (45 phút)

### Kiến trúc Tải file An toàn bằng Presigned URLs
Trong ứng dụng web truyền thống, người dùng thường upload file trực tiếp lên Application Server (EC2/Container), sau đó server mới upload tiếp lên S3. Kiến trúc này tạo ra nút thắt cổ chai nghiêm trọng (Server nghẽn băng thông, cạn RAM và chịu tải vô ích).

Giải pháp chuẩn kiến trúc Cloud là **Presigned URL**:

```text
Quy trình Tải file trực tiếp lên S3 bằng Presigned URL:
┌────────┐               ┌────────────────────┐               ┌───────────┐
│ Client │               │ Application Server │               │ Amazon S3 │
└───┬────┘               └─────────┬──────────┘               └─────┬─────┘
    │ 1. Xin link upload           │                                │
    │    (POST /api/upload-request)│                                │
    │─────────────────────────────>│                                │
    │                              │ 2. Dùng IAM Role sinh URL ký   │
    │                              │    (Thời hạn sống ví dụ: 5p)   │
    │                              │    getSignedUrl(PutObjectCmd)  │
    │ 3. Nhận Presigned PUT URL    │                                │
    │<─────────────────────────────│                                │
    │                                                               │
    │ 4. PUT file binary trực tiếp lên S3 với URL đã ký             │
    │──────────────────────────────────────────────────────────────>│
    │ 5. S3 xác thực chữ ký HMAC-SHA256 & lưu file thành công (200) │
    │<──────────────────────────────────────────────────────────────│
```

- **Presigned URL là một Bearer Capability:** Bất kỳ ai nắm trong tay URL này đều có quyền thực hiện thao tác (Upload hoặc Download) đối với object cụ thể trong thời gian quy định (`ExpiresIn`), mà không cần phải có tài khoản hay credentials của AWS.
- **Quyền hạn phụ thuộc vào người ký:** S3 kiểm tra quyền của người ký (ví dụ IAM Role của EC2) **tại thời điểm URL được sử dụng**, không phải lúc ký. Nếu role bị gỡ quyền, URL sẽ mất hiệu lực dù chưa tới `ExpiresIn`.
- **Không sống lâu hơn credentials:** URL ký bằng temporary credentials (role, IAM Identity Center) hết hiệu lực khi session hết hạn, dù `ExpiresIn` dài hơn. Giới hạn tối đa của SigV4 là 7 ngày.
- **CLI chỉ ký GET:** `aws s3 presign` chỉ sinh URL tải xuống. URL upload (`PutObject`) phải ký bằng SDK (boto3 `generate_presigned_url`, JavaScript v3 `getSignedUrl`). Nếu ký kèm `ContentType`, client bắt buộc gửi đúng header đó.

### Content-Type và Content-Disposition
Khi upload file lên S3, nếu không chỉ định header `Content-Type: application/pdf` hoặc `image/png`, S3 sẽ mặc định gán là `binary/octet-stream`. Hệ quả: Khi người dùng bấm vào link trên trình duyệt, file sẽ bị ép tải về máy tính thay vì hiển thị trực tiếp.

### Cross-Origin Resource Sharing (CORS)
Khi frontend (chạy trên domain `https://shop.example.com`) dùng Javascript `fetch()` để gửi HTTP PUT request tải file trực tiếp lên endpoint S3 `https://my-bucket.s3.amazonaws.com`, trình duyệt sẽ chặn request vì vi phạm chính sách Same-Origin Policy.
Bạn cần cấu hình **S3 CORS Configuration**:
```json
[
  {
    "AllowedHeaders": ["*"],
    "AllowedMethods": ["PUT", "POST", "GET"],
    "AllowedOrigins": ["https://shop.example.com"],
    "ExposeHeaders": ["ETag"],
    "MaxAgeSeconds": 3000
  }
]
```

> [!WARNING]
> CORS **không phải** là cơ chế bảo mật phía máy chủ và **không thay thế** cho cơ chế xác thực Authorization. CORS là quy định của trình duyệt web nhằm bảo vệ người dùng tránh việc script xấu từ website độc hại tự ý gọi API đến website khác.

### Tính toàn vẹn dữ liệu: Checksum SHA-256 vs MD5 (ETag)
Khi truyền tải file quan trọng (hóa đơn số, bản backup), dữ liệu có thể bị lỗi bit do đường truyền mạng. S3 hỗ trợ tính toán và xác minh tính toàn vẹn thông qua các thuật toán checksum **CRC64NVME**, **CRC32**, **CRC32C**, **SHA-1**, **SHA-256**. Client gửi kèm checksum trong header (ví dụ `x-amz-checksum-sha256`, giá trị base64), S3 tính lại khi nhận xong; nếu sai lệch, S3 từ chối request (`BadDigest`) và không lưu object. Các SDK/CLI đời mới tự gửi checksum CRC mặc định; bạn chỉ cần chỉ định thuật toán khi có yêu cầu riêng (ví dụ đối chiếu SHA-256 với hệ thống khác). ETag **không** phải checksum đáng tin: với multipart upload hoặc SSE-KMS, ETag không phải MD5 của nội dung.

---

## Phần 3 — Vòng đời lưu trữ (Lifecycle), Tầng lưu trữ (Storage Classes) và Multipart Upload (45 phút)

### Các Tầng Lưu trữ S3 (S3 Storage Classes)
Lưu trữ mọi dữ liệu ở S3 Standard trong nhiều năm sẽ khiến hóa đơn phình to lãng phí. AWS cung cấp nhiều tầng lưu trữ cho các nhu cầu khác nhau:

```text
Phổ lưu trữ S3 từ Thường xuyên đến Lưu trữ sâu:
┌─────────────────┬──────────────────┬─────────────────┬─────────────────┐
│ S3 Standard     │ S3 Standard-IA   │ Glacier Flex    │ Glacier Deep    │
├─────────────────┼──────────────────┼─────────────────┼─────────────────┤
│ Truy cập        │ Ít truy cập      │ Lưu trữ lạnh    │ Lưu trữ đóng băng│
│ thường xuyên    │ (>= 30 ngày)     │ (>= 90 ngày)    │ (>= 180 ngày)   │
│                 │ Phí dung lượng rẻ│ Phí lưu trữ     │ Rẻ nhất AWS     │
│                 │ nhưng có phí get │ cực rẻ          │                 │
│ ms retrieval    │ ms retrieval     │ Lấy lại: phút-h │ Lấy lại: 12-48h │
└─────────────────┴──────────────────┴─────────────────┴─────────────────┘
```

- **S3 Standard:** Dành cho dữ liệu active, truy cập thường xuyên hàng ngày. Phí lưu trữ cao nhất (~$0.023/GB/tháng tại us-east-1), không tốn phí truy xuất (retrieval fee).
- **S3 Standard-IA (Infrequent Access):** Dành cho dữ liệu ít khi đọc (ví dụ hóa đơn tháng trước), nhưng khi cần đọc thì phải trả về tức thì (mili-giây). Phí lưu trữ rẻ hơn ~45% so với Standard, nhưng tính thêm phí trên mỗi GB dữ liệu truy xuất ra. Tính tiền tối thiểu 30 ngày và tối thiểu 128 KB mỗi object, nên không hợp với nhiều file nhỏ.
- **S3 Glacier Flexible Retrieval:** Dành cho dữ liệu lưu trữ (archive). Thời gian lấy dữ liệu mất từ vài phút đến 3–5 giờ.
- **S3 Glacier Deep Archive:** Tầng lưu trữ rẻ nhất thế giới đám mây (~$0.00099/GB/tháng, tức ~$1 cho 1 TB dữ liệu mỗi tháng). Dành cho dữ liệu sao lưu tuân thủ quy định pháp luật (giữ 7–10 năm và hiếm khi chạm tới). Thời gian lấy dữ liệu mất từ 12 đến 48 giờ.
- **S3 Intelligent-Tiering:** Tự động giám sát mẫu truy cập và di chuyển dữ liệu giữa các tầng Frequent, Infrequent và Archive Instant Access mà không làm gián đoạn ứng dụng và không thu phí truy xuất. Đổi lại có phí giám sát theo số object (object < 128 KB không được giám sát và luôn ở tầng Frequent). Phù hợp cho dữ liệu lớn có mô hình truy cập khó dự đoán.
- Giá trong bài là giá minh họa tại `us-east-1`; tra lại [S3 pricing](https://aws.amazon.com/s3/pricing/) cho Region của bạn.

### S3 Lifecycle Rules (Quy tắc vòng đời)
Tự động hóa việc chuyển đổi tầng lưu trữ và dọn dẹp file cũ:
- **Transition actions:** Chuyển object sang Standard-IA sau 30 ngày, sang Glacier sau 90 ngày.
- **Expiration actions:** Xóa vĩnh viễn các object phiên bản cũ (Noncurrent Version Expiration) sau 180 ngày.
- **AbortIncompleteMultipartUpload:** Tự động hủy và xóa các mẩu chunk tải lên dở dang của Multipart Upload sau 7 ngày (giúp tiết kiệm lượng chi phí khổng lồ bị rò rỉ mà kỹ sư thường bỏ quên).

### S3 Multipart Upload
- Bắt buộc đối với object có kích thước lớn hơn 5 GB và được khuyến nghị mạnh mẽ cho bất kỳ file nào từ **100 MB trở lên**.
- File được chia thành nhiều phần nhỏ (tối thiểu 5 MB mỗi part), tải lên song song để tối đa hóa băng thông mạng.
- Nếu một part bị rớt mạng giữa chừng, chỉ cần upload lại đúng part đó thay vì phải truyền lại toàn bộ file từ đầu.

---

## Phần 4 — Amazon CloudFront: CDN, OAC, Cache Key và Invalidation (50 phút)

### Kiến trúc Mạng Phân phối Nội dung (Content Delivery Network - CDN)
Amazon CloudFront là mạng lưới gồm hàng trăm **Edge Locations** (Điểm hiện diện biên) phủ khắp các thành phố lớn trên thế giới.

```text
So sánh Truy cập Trực tiếp vs Qua CloudFront CDN:
Trực tiếp:
Client (Việt Nam) ────────────────── (Trễ 250ms) ──────────────────> S3 (us-east-1)

Qua CloudFront:
Client (Việt Nam) ── (Trễ 5ms) ──> CloudFront Edge (Hà Nội/TPHCM)
                                      │
                         Cache HIT?   ├── Có ──> Trả về ngay (5ms)
                                      └── Không ─> Đi qua cáp quang riêng của AWS
                                                  lấy từ S3 us-east-1 & lưu đệm lại
```

### Bảo vệ S3 Origin bằng Origin Access Control (OAC)
Trước đây AWS dùng OAI (Origin Access Identity), hiện nay chuẩn khuyến nghị mới nhất và an toàn nhất là **Origin Access Control (OAC)**:
- OAC hỗ trợ ký request theo chuẩn AWS Signature Version 4 (SigV4).
- Hỗ trợ các phương thức HTTP PUT/POST, hỗ trợ mã hóa SSE-KMS, áp dụng cho tất cả các Region mới.
- **Quy tắc bảo mật:** S3 Bucket đóng chặt hoàn toàn (Block Public Access bật 100%). S3 Bucket Policy chỉ cho phép **CloudFront Service Principal** đọc dữ liệu với điều kiện `aws:SourceArn` phải là ARN chính xác của CloudFront Distribution tương ứng.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowCloudFrontServicePrincipalReadOnly",
      "Effect": "Allow",
      "Principal": {
        "Service": "cloudfront.amazonaws.com"
      },
      "Action": "s3:GetObject",
      "Resource": "arn:aws:s3:::my-order-bucket/*",
      "Condition": {
        "StringEquals": {
          "AWS:SourceArn": "arn:aws:cloudfront::123456789012:distribution/EDFDVBD6EXAMPLE"
        }
      }
    }
  ]
}
```

### Cache Key, TTL và Cache Policy
CloudFront quyết định một request có phải là **Cache HIT** hay không dựa trên **Cache Key**:
- Mặc định, Cache Key chỉ bao gồm tên miền và đường dẫn URI (`example.com/images/logo.png`).
- Nếu bạn cấu hình đưa Query String, Header hoặc Cookie vào Cache Key, tính chất gom cụm cache sẽ giảm đi (Cache hit ratio giảm), làm tăng số lượng request phải chạy về origin.
- **TTL (Time-To-Live):** Quy định thời gian object được phép lưu trong cache của Edge Location trước khi CloudFront phải quay lại origin kiểm tra phiên bản mới. Có 3 giá trị: `Min TTL`, `Default TTL`, và `Max TTL` (được điều khiển qua header `Cache-Control: max-age=...` trả về từ origin).

### Chiến lược Cập nhật Nội dung: Cache Invalidation vs Cache Busting
Khi bạn cập nhật code frontend hoặc đổi ảnh banner trên S3, làm thế nào để người dùng nhìn thấy dữ liệu mới ngay lập tức mà không phải chờ hết hạn TTL?

| Tiêu chí | Tạo Cache Invalidation | Versioned Filename (Cache Busting) |
|---|---|---|
| **Cơ chế** | Gửi yêu cầu CloudFront xóa object khỏi hàng trăm Edge Location (`aws cloudfront create-invalidation --paths "/*"`). | Đổi tên file khi build: `app.a8f9b2.js` hoặc `banner-v2.png`. |
| **Tốc độ** | Mất từ 1 đến vài phút để lệnh truyền tới toàn bộ Edge Location trên toàn cầu. | Có hiệu lực tức thời 100% ngay khi người dùng tải trang HTML mới. |
| **Chi phí** | 1,000 path đầu tiên mỗi tháng không tính phí, sau đó khoảng **$0.005 trên mỗi path** (giá minh họa). Wildcard `/*` tính là **một** path nhưng xóa toàn bộ cache, làm origin chịu tải dồn. | Không tốn phí invalidation; chỉ tốn thêm chút dung lượng S3 cho file cũ (dọn bằng lifecycle). |
| **Phạm vi** | Chỉ xóa cache ở CloudFront, **không** xóa cache trình duyệt hay proxy trung gian. | Trình duyệt cũng thấy URL mới nên không bị cache cũ. |
| **Khuyến nghị kiến trúc** | Dùng cho file có tên cố định (`index.html`) hoặc sự cố khẩn cấp (xóa nội dung vi phạm). | **Thực hành phổ biến trong CI/CD.** Đặt TTL dài (1 năm, `immutable`) cho asset có hash; `index.html` để TTL ngắn hoặc invalidate khi deploy. |

---

## Phần 5 — Amazon Route 53 và AWS Certificate Manager (ACM) (45 phút)

### Amazon Route 53: Dịch vụ DNS Đám mây
Route 53 là dịch vụ Domain Name System (DNS) có độ khả dụng cực cao (SLA cam kết 100% uptime của AWS).
- **Public Hosted Zone:** Chứa các bản ghi DNS định tuyến lưu lượng từ Internet vào các tài nguyên của bạn.
- **Bản ghi thông thường (Standard DNS Records):**
  - `A`: Trỏ tên miền sang địa chỉ IPv4 (ví dụ `198.51.100.1`).
  - `AAAA`: Trỏ tên miền sang địa chỉ IPv6.
  - `CNAME`: Trỏ tên miền này sang một tên miền khác (ví dụ `www.example.com` trỏ tới `d111111abcdef8.cloudfront.net`).

### Điểm đặc biệt: Alias Record vs CNAME
Giao thức DNS tiêu chuẩn (RFC 1034) nghiêm cấm đặt bản ghi CNAME tại gốc tên miền (**Zone Apex**, hay Naked Domain, ví dụ `example.com` không có tiền tố `www`).
Nếu bạn muốn trỏ trực tiếp `example.com` về CloudFront Distribution hoặc Application Load Balancer (vốn không có địa chỉ IP cố định mà chỉ có DNS Name), CNAME sẽ vi phạm chuẩn DNS.

AWS giải quyết vấn đề này bằng **Route 53 Alias Record**:
- **Alias Record** là tính năng mở rộng độc quyền của Route 53.
- Cho phép trỏ trực tiếp Zone Apex (`example.com`) tới các tài nguyên AWS (CloudFront, ALB, S3 Website Bucket).
- Khi client truy vấn, Route 53 tự động phân giải tên miền AWS ngầm bên trong và trả về trực tiếp địa chỉ IP cho client, hoàn toàn tuân thủ RFC DNS.
- Route 53 **không tính phí truy vấn DNS** đối với các bản ghi Alias trỏ tới tài nguyên AWS!

### AWS Certificate Manager (ACM)
ACM cung cấp và tự động gia hạn chứng chỉ SSL/TLS miễn phí để kích hoạt giao thức HTTPS bảo mật.
- **Ràng buộc vùng quan trọng đối với CloudFront:**
  - Vì CloudFront là dịch vụ CDN toàn cầu, nếu muốn gắn Custom SSL Certificate vào CloudFront Distribution, chứng chỉ ACM bắt buộc phải được tạo/yêu cầu tại Region **`us-east-1` (US East - N. Virginia)**!
  - Nếu chứng chỉ được tạo tại `ap-southeast-1` (Singapore), CloudFront sẽ hoàn toàn không nhìn thấy để bạn lựa chọn.
- **Xác thực quyền sở hữu tên miền (DNS Validation):** ACM sẽ cung cấp một bản ghi CNAME đặc thù. Bạn chỉ cần thêm bản ghi này vào Hosted Zone của tên miền, ACM sẽ tự động xác minh và kích hoạt chứng chỉ, thường trong vài phút đến khoảng 30 phút. Giữ bản ghi này lại để ACM tự gia hạn.

### DNS TTL và CDN TTL là hai cơ chế khác nhau

| | DNS TTL (Route 53 record) | CDN TTL (CloudFront cache) |
|---|---|---|
| Quy định | Resolver nhớ bản ghi tên miền → IP/tên đích bao lâu | Edge giữ một object bao lâu trước khi hỏi lại origin |
| Khi đổi | Đổi bản ghi DNS (ví dụ chuyển sang distribution mới) | Đổi nội dung file trên origin |
| Cách "xóa" sớm | Không xóa được cache của resolver; hạ TTL **trước** khi đổi | Invalidation hoặc đổi tên file |
| Ghi chú | Alias record dùng TTL của tài nguyên đích, bạn không đặt TTL | Điều khiển bằng cache policy và `Cache-Control` của origin |

Invalidation không làm resolver quên bản ghi cũ, và đổi DNS không xóa cache của CloudFront.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 06)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai một hệ sinh thái phân phối nội dung hoàn chỉnh theo tiêu chuẩn doanh nghiệp:
1. Tạo một S3 Bucket hoàn toàn riêng tư (bật đủ 4 cờ Block Public Access và Versioning).
2. Ký **Presigned URL** PUT bằng boto3 và GET bằng CLI (5 phút), thử negative case sai header và URL hết hạn; kiểm tra checksum SHA-256, CORS preflight, lifecycle và multipart upload dở dang.
3. Cấu hình **CloudFront Distribution** với **Origin Access Control (OAC)** kết nối an toàn tới S3.
4. Kiểm chứng Negative Case: Truy cập trực tiếp link S3 bị trả về HTTP 403 Forbidden; truy cập qua CloudFront trả về HTTP 200 OK.
5. Kiểm nghiệm hành vi bộ đệm qua header phản hồi `X-Cache: Hit/Miss from cloudfront`.
6. Thực hiện dọn dẹp sạch sẽ tài nguyên (disable & delete CloudFront distribution, dọn sạch mọi version và delete marker trong S3).

👉 Mở bài hướng dẫn chi tiết tại: **[labs/06-storage-and-content-delivery/README.md](../labs/06-storage-and-content-delivery/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Truy cập qua CloudFront trả về lỗi HTTP 403 Forbidden
- **Nguyên nhân 1: Bucket Policy chưa cấp quyền cho OAC.** Kiểm tra xem policy trên S3 đã có `Service: cloudfront.amazonaws.com` và điều kiện `AWS:SourceArn` khớp chính xác với ARN của CloudFront Distribution chưa.
- **Nguyên nhân 2: File không tồn tại trong S3.** Khi S3 origin chặn quyền đọc hoặc file không tồn tại trong private bucket, S3 thường trả về mã 403 Forbidden (thay vì 404 Not Found) để tránh tiết lộ sự tồn tại của file cho kẻ tấn công bên ngoài.
- **Nguyên nhân 3: Nhầm lẫn giữa S3 REST Endpoint và S3 Website Endpoint.** OAC **chỉ hỗ trợ S3 REST API endpoint** (`<bucket-name>.s3.<region>.amazonaws.com`), không hỗ trợ S3 Static Website Hosting endpoint (`<bucket-name>.s3-website-<region>.amazonaws.com`).

### 2. Trình duyệt báo lỗi CORS khi tải file từ frontend
- **Hiện tượng:** `Access to XMLHttpRequest at '...' from origin 'https://...' has been blocked by CORS policy`.
- **Cách khắc phục:** Kiểm tra cấu hình S3 CORS:
  - Cột `AllowedOrigins` đã chứa chính xác domain frontend (kèm cả giao thức `https://` và cổng port nếu chạy local dev) chưa.
  - Cột `AllowedMethods` đã bao gồm method đang gọi (`PUT`, `GET`, `OPTIONS`) chưa.
  - Nếu frontend có gửi custom headers (ví dụ `x-amz-checksum-sha256`), cột `AllowedHeaders` phải chứa header đó hoặc cấu hình `*`.

### 3. Cập nhật file trên S3 nhưng người dùng vẫn thấy phiên bản cũ
- **Nguyên nhân:** File vẫn đang nằm trong bộ nhớ đệm của CloudFront hoặc browser cache do TTL chưa hết.
- **Cách kiểm tra:** Dùng lệnh `curl -I https://<distribution-domain>/<file-path>` và quan sát header `Age` cùng `X-Cache`.
- **Cách xử lý:** Tạo một cache invalidation cho file (`aws cloudfront create-invalidation --distribution-id ... --paths "/banner.png"`) hoặc áp dụng chuẩn **Versioned Filename** trong đường dẫn (`/banner-v2.png`).

---

## Câu hỏi tự kiểm tra

1. Tại sao tính năng S3 Block Public Access được coi là chốt an toàn quan trọng nhất trong việc ngăn ngừa rò rỉ dữ liệu đám mây?
2. So sánh cơ chế hoạt động, độ bảo mật và chi phí giữa **Presigned URL** và việc mở bucket ra công khai (Public Bucket) để chia sẻ tài liệu?
3. Tại sao khi sử dụng CloudFront CDN, các kỹ sư thường được khuyến nghị áp dụng kỹ thuật "Cache Busting / Versioned Filenames" trong quy trình CI/CD thay vì lạm dụng lệnh tạo Cache Invalidation?
4. Trình bày sự khác biệt giữa DNS CNAME và Route 53 Alias Record? Tại sao không thể dùng CNAME cho Apex domain (`example.com`)?
5. Vì sao khi tạo chứng chỉ SSL/TLS bằng AWS Certificate Manager (ACM) để gắn vào CloudFront, bạn bắt buộc phải yêu cầu chứng chỉ tại Region `us-east-1`?
6. Bucket bật đủ 4 cờ Block Public Access, vậy vì sao CloudFront vẫn đọc được để phục vụ người dùng Internet?
7. Bạn đã chạy `aws s3 rm s3://bucket --recursive` nhưng hóa đơn S3 tháng sau không giảm. Liệt kê ít nhất hai nơi dữ liệu vẫn còn nằm.

---

## Tài liệu tham khảo chính thức

- [Amazon S3 User Guide](https://docs.aws.amazon.com/AmazonS3/latest/userguide/)
- [Restricting access to Amazon S3 using Origin Access Control (OAC)](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/private-content-restricting-access-to-s3.html)
- [Amazon CloudFront Developer Guide](https://docs.aws.amazon.com/AmazonCloudFront/latest/DeveloperGuide/)
- [Choosing between Alias and Non-Alias records in Route 53](https://docs.aws.amazon.com/Route53/latest/DeveloperGuide/resource-record-types.html)
- [AWS Certificate Manager for CloudFront](https://docs.aws.amazon.com/acm/latest/userguide/acm-services.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** OAC là cơ chế được khuyến nghị (OAI là legacy, vẫn hoạt động); Block Public Access bật mặc định cho bucket mới (từ 4/2023); SSE-S3 mặc định cho object mới (từ 1/2023); `aws s3 presign` chỉ hỗ trợ GET; certificate ACM cho CloudFront phải ở `us-east-1`; OAC không hỗ trợ S3 website endpoint.
- **Chưa chạy thử trên AWS:** lệnh trong lab chưa được thực thi trên account thật; output là minh họa.
- **Chi phí:** lab chỉ dùng vài MB S3 và vài chục request CloudFront, ước tính dưới $0.05 kể cả khi không có Free Tier (xem bảng trong lab). Giá trong bài là minh họa, cần tra lại theo Region.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. S3 Block Public Access (BPA) hoạt động như một lớp màng lọc bảo vệ tối cao (Centralized Guardrail) độc lập với Bucket Policy và ACL. Ngay cả khi một lập trình viên hoặc script sơ ý gắn nhầm policy `Allow *` hoặc cấp quyền công khai qua ACL, 4 cờ BPA sẽ ngay lập tức chặn đứng hoặc vô hiệu hóa các quyền công khai này, đảm bảo dữ liệu không bao giờ bị lộ ra ngoài Internet do lỗi sơ suất của con người.
2. So sánh:
   - **Public Bucket:** Mọi đối tượng đều mở cho bất kỳ ai trên Internet đọc mà không có hạn chế nào. Dễ dẫn tới rò rỉ dữ liệu nhạy cảm, bị lạm dụng băng thông dẫn tới hóa đơn tiền mạng tăng vọt và không kiểm soát được danh tính người đọc.
   - **Presigned URL:** Bucket vẫn được bảo vệ 100% riêng tư. Quyền truy cập chỉ được cấp tạm thời cho một object cụ thể, có thời hạn sống ngắn tính bằng phút hoặc giây do backend ứng dụng quyết định. URL mang chữ ký mật mã HMAC-SHA256, không thể sửa đổi tham số và hết hạn tức thời, mang lại mức độ bảo mật vượt trội.
3. Vì:
   - **Tức thời và tin cậy:** Versioned Filename (ví dụ `main.8f2d9c.js`) tạo ra một đường dẫn hoàn toàn mới. Trình duyệt người dùng và toàn bộ Edge Location đều nhận diện đây là file mới 100%, không bao giờ gặp tình trạng người dùng xem phải file JS/CSS cũ hay lỗi giao diện do cache chưa kịp xóa.
   - **Không phụ thuộc cache ngoài tầm kiểm soát:** Invalidation chỉ xóa cache ở CloudFront; trình duyệt và proxy vẫn giữ bản cũ tới hết `max-age`. Tên file mới tránh được cả hai.
   - **Không làm origin chịu tải dồn:** Invalidate `/*` sau mỗi deploy biến toàn bộ request kế tiếp thành Miss. Versioned filename giữ nguyên cache cho asset không đổi.
   - **Chi phí:** chỉ đáng kể khi invalidate nhiều path cụ thể (quá 1.000 path/tháng, khoảng $0.005/path). Wildcard `/*` tính là một path, nên chi phí thường không phải lý do chính.
4. Sự khác biệt:
   - **CNAME:** Thuộc chuẩn RFC DNS thế giới. Chỉ có thể trỏ tên miền phụ (subdomain) sang một tên miền khác. RFC DNS nghiêm cấm tạo CNAME tại gốc tên miền (Zone Apex `example.com`) vì nó sẽ xung đột với các bản ghi SOA và NS bắt buộc phải có tại apex.
   - **Route 53 Alias Record:** Là tính năng riêng của Route 53. Cho phép trỏ an toàn Zone Apex `example.com` trực tiếp sang CloudFront Distribution hoặc ALB mà vẫn tuân thủ RFC DNS (vì Route 53 tự phân giải nội bộ ra địa chỉ A/AAAA khi trả về cho client). Đồng thời, truy vấn Alias tới tài nguyên AWS được miễn phí DNS query.
5. CloudFront là dịch vụ global, control plane đặt ở `us-east-1`, và tài liệu CloudFront quy định chỉ đọc certificate ACM ở Region này để phân phối ra các edge. Certificate ACM là tài nguyên theo Region, nên certificate tạo ở `ap-southeast-1` không hiện trong danh sách chọn của CloudFront. (Certificate cho ALB/API Gateway regional thì ngược lại: phải ở **cùng Region** với tài nguyên đó.)
6. Block Public Access chặn quyền **công khai** (ACL public, policy cho `Principal: "*"` không điều kiện). Policy OAC cấp `s3:GetObject` cho service principal `cloudfront.amazonaws.com` kèm `AWS:SourceArn` của một distribution cụ thể, nên S3 không coi là public. Người dùng Internet nói chuyện với CloudFront; CloudFront ký request SigV4 tới S3 bằng OAC. Truy cập thẳng S3 vẫn bị 403.
7. (a) Bucket có versioning: `rm` chỉ thêm delete marker, các version cũ vẫn tính tiền. (b) Multipart upload dở dang: không hiện trong `ls` nhưng các part đã upload vẫn tính tiền. Cách xử lý: xóa theo `VersionId`, abort multipart, và đặt lifecycle `NoncurrentVersionExpiration` + `AbortIncompleteMultipartUpload`. (Ngoài ra còn bản sao do replication sang bucket khác nếu có.)

</details>

