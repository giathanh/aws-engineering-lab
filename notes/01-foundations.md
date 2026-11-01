# Bài 01 — Nền tảng AWS: Region, Availability Zone và AWS CLI

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 3–4 giờ, chia hai buổi. Buổi 1 gồm phần 1–4 (lý thuyết). Buổi 2 gồm phần 5–7 và [lab chỉ đọc](../labs/01-foundations/README.md).
> **Kế hoạch gốc:** [plans/01-aws-foundations.md](../plans/01-aws-foundations.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Giải thích quan hệ AWS account → Region → Availability Zone, và vì sao account không phải một địa điểm vật lý.
2. Phân biệt Region, AZ, data center và edge location.
3. Phân biệt tài nguyên global, regional và zonal.
4. Chọn Region dựa trên tiêu chí rõ ràng.
5. Phân biệt high availability (nhiều AZ) với disaster recovery (nhiều Region).
6. Cài AWS CLI v2, cấu hình named profile và đăng nhập bằng temporary credentials.
7. Biết chắc account, identity và Region trước khi chạy lệnh.
8. Dùng CLI đọc danh sách Region/AZ, lọc kết quả và xử lý lỗi cấu hình phổ biến.

---

## Phần 1 — Mô hình AWS (15 phút)

### Cloud nhìn từ góc độ developer

Có thể coi AWS là **một tập hợp lớn các API**. Tạo máy ảo, đọc danh sách Region hay xóa bucket đều là một HTTP request có ký (signed) gửi tới endpoint của một dịch vụ. Ba đặc điểm cần nhớ:

- **Truy cập qua API:** mọi thao tác đều đi qua API, có xác thực và phân quyền.
- **Cấp phát theo nhu cầu:** bạn tạo tài nguyên khi cần và xóa khi xong, không phải mua phần cứng.
- **Trả tiền theo mức dùng:** chi phí phụ thuộc vào loại tài nguyên, thời gian dùng, dung lượng và lưu lượng. Đây là lý do phải dọn dẹp sau mỗi lab.

### AWS account

**AWS account** là ranh giới về sở hữu, thanh toán và bảo mật. Tài nguyên thuộc về một account, hóa đơn tính theo account, và quyền truy cập được cấp trong phạm vi account. Account được định danh bằng một **account ID** gồm 12 chữ số.

Account **không phải một địa điểm**. Một account có thể chứa tài nguyên ở nhiều Region. Khi hỏi "tài nguyên X nằm ở đâu", cần trả lời hai ý: *account nào* và *Region nào* (với tài nguyên zonal thì thêm *AZ nào*).

### Console, CLI, SDK: cùng gọi một API

```text
 ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
 │ AWS Console  │   │   AWS CLI    │   │  AWS SDK     │
 │ (web UI)     │   │ (terminal)   │   │ (code)       │
 └──────┬───────┘   └──────┬───────┘   └──────┬───────┘
        │   HTTPS request có ký bằng credentials  │
        └──────────────────┼──────────────────────┘
                           ▼
            Endpoint dịch vụ theo Region
            vd: ec2.<region>.amazonaws.com
                           ▼
              Xác thực → Phân quyền (IAM)
                           ▼
              Tài nguyên trong account của bạn
```

- **Console** phù hợp để khám phá và xem trực quan. Region được chọn ở góc trên bên phải.
- **CLI** phù hợp để thao tác lặp lại, viết script và ghi lại chính xác những gì đã làm. Bài này tập trung vào CLI.
- **SDK** dùng khi ứng dụng của bạn gọi AWS (Python/boto3, JavaScript, Go, Java…).

Vì cả ba gọi cùng một API, một lỗi `AccessDenied` trên CLI cũng xảy ra trên Console nếu dùng cùng identity và cùng thao tác.

### Shared Responsibility Model

AWS và khách hàng chia nhau trách nhiệm bảo mật:

- **AWS chịu trách nhiệm "security *of* the cloud":** data center vật lý, phần cứng, mạng toàn cầu, lớp ảo hóa.
- **Khách hàng chịu trách nhiệm "security *in* the cloud":** ai được quyền làm gì (IAM), cấu hình tài nguyên, dữ liệu, mã hóa, vá hệ điều hành nếu tự quản lý máy ảo.

Ví dụ: AWS bảo đảm không ai lấy được ổ cứng trong data center. Nhưng nếu bạn commit access key lên GitHub công khai, đó là trách nhiệm của bạn. Ranh giới này thay đổi tùy dịch vụ: dịch vụ càng được quản lý sẵn (managed) thì phần của khách hàng càng ít.

> **Tự kiểm tra:** Hãy mô tả điều gì xảy ra khi bạn chạy `aws ec2 describe-regions`, theo luồng CLI → API → kết quả.

---

## Phần 2 — Hạ tầng toàn cầu (30 phút)

### Các khái niệm

| Khái niệm | Là gì | Ví dụ định danh |
|---|---|---|
| **Region** | Một khu vực địa lý, gồm nhiều AZ cách ly với nhau. Mỗi Region độc lập với các Region khác. | `ap-southeast-1`, `us-east-1` |
| **Availability Zone (AZ)** | Một hoặc nhiều data center trong Region, có nguồn điện, làm mát và mạng dự phòng riêng. Các AZ trong cùng Region nối với nhau bằng mạng độ trễ thấp. | Tên: `ap-southeast-1a` · ID: `apse1-az1` |
| **Data center** | Tòa nhà vật lý chứa máy chủ. AWS không cho bạn chọn data center. | Không có định danh công khai |
| **Edge location** | Điểm hiện diện (PoP) gần người dùng, phục vụ CloudFront (CDN), Route 53 (DNS)… Không phải nơi bạn chạy máy ảo thông thường. | Không chọn khi tạo VPC/EC2 |

Một AZ **có thể gồm nhiều data center**. AZ là một đơn vị logic về khả năng chịu lỗi, không phải một tòa nhà. AWS nhóm các data center chia sẻ rủi ro tương tự vào cùng một AZ, và tách các AZ đủ xa nhau để một sự cố cục bộ (mất điện, cháy, lũ) khó ảnh hưởng tới nhiều AZ cùng lúc.

> AWS còn có Local Zones, Wavelength Zones và Outposts. Đây là các mở rộng của Region, nằm ngoài phạm vi bài này. Bạn sẽ thấy tên của chúng khi lọc kết quả ở lab.

### Sơ đồ

```text
AWS account 111122223333 (ranh giới sở hữu / thanh toán / quyền)
│
├── Region ap-southeast-1 ─────────────────────────────────────┐
│   ├── AZ apse1-az1  [DC] [DC]                                │
│   ├── AZ apse1-az2  [DC]          ← mạng riêng độ trễ thấp → │
│   └── AZ apse1-az3  [DC] [DC]                                │
│                                                              │
├── Region us-east-1 (độc lập, có tập AZ riêng) ───────────────┘
│   └── ...
│
└── Tài nguyên global (vd: IAM) — không thuộc Region nào

Edge locations: rải khắp thế giới, phục vụ CDN/DNS, tách biệt với AZ.
```

*Số AZ trong sơ đồ chỉ để minh họa. Số Region và số AZ thay đổi theo thời gian, nên hãy tra cứu bằng CLI hoặc tài liệu khi cần.*

### Ranh giới lỗi (failure domain)

Hiểu đúng ranh giới lỗi giúp bạn thiết kế đúng:

| Sự cố | Ảnh hưởng | Cách giảm thiểu |
|---|---|---|
| Một máy chủ hỏng | Một instance | Chạy nhiều instance |
| Một AZ gặp sự cố | Mọi thứ trong AZ đó | Triển khai qua **nhiều AZ** (high availability) |
| Cả Region gặp sự cố (hiếm) | Mọi thứ trong Region | **Disaster recovery** sang Region khác |

**High availability (HA)** là khả năng hệ thống tiếp tục phục vụ khi một thành phần hoặc một AZ hỏng. Cách làm thường gặp là chạy nhiều bản sao ở nhiều AZ, có load balancer và cơ sở dữ liệu nhân bản.

**Disaster recovery (DR)** là khả năng khôi phục sau sự cố lớn, thường là sao lưu hoặc nhân bản sang Region khác. DR được đo bằng RPO (chấp nhận mất bao nhiêu dữ liệu) và RTO (chấp nhận gián đoạn bao lâu).

Hai hiểu lầm thường gặp:

- *"Region có nhiều AZ nên ứng dụng tự động HA."* **Sai.** Nếu bạn chỉ chạy một instance ở một AZ, sự cố của AZ đó sẽ làm ứng dụng ngừng hoạt động. Bạn phải chủ động triển khai và cấu hình qua nhiều AZ.
- *"Hệ thống nghiêm túc nào cũng phải multi-Region."* **Không nhất thiết.** Multi-Region làm tăng chi phí, độ phức tạp, và gây khó khăn cho tính nhất quán dữ liệu. Nhiều hệ thống chỉ cần multi-AZ cộng với sao lưu sang Region khác. Quyết định nên dựa trên RPO/RTO và yêu cầu kinh doanh.

> **Tự kiểm tra:** Hai instance chạy cùng một AZ có bảo vệ được ứng dụng khi toàn bộ AZ đó gặp sự cố không?

---

## Phần 3 — Phạm vi tài nguyên và chọn Region (25 phút)

### Global, regional, zonal

| Phạm vi | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| **Global** | Không gắn với Region. Thấy và dùng được từ mọi Region. | **IAM** (user, role, policy) |
| **Regional** | Thuộc một Region, có thể trải qua nhiều AZ trong Region đó. | **VPC** (mạng ảo) |
| **Zonal** | Thuộc đúng một AZ. | **Subnet** (một dải mạng con nằm trong một AZ) |

Lưu ý:

- Bảng trên chỉ minh họa phạm vi. VPC, subnet và IAM sẽ được học chi tiết ở các bài sau.
- **Không phải dịch vụ nào cũng có cùng kiểu phạm vi**, và **không phải dịch vụ nào cũng có ở mọi Region**. Hãy kiểm tra trang "AWS Services by Region" trước khi chọn Region.
- Hệ quả thực tế: nếu bạn tạo tài nguyên regional ở `ap-southeast-1` rồi tìm nó ở `us-east-1`, bạn sẽ không thấy gì. Đây là nguyên nhân phổ biến nhất của câu hỏi "tài nguyên của tôi đâu rồi?".

### Region code, AZ name và AZ ID

- **Region code** như `ap-southeast-1` là định danh dùng trong CLI/SDK. Console hiển thị thêm tên dễ đọc như "Asia Pacific (Singapore)".
- **AZ name** như `ap-southeast-1a` là tên AZ **theo góc nhìn của account bạn**.
- **AZ ID** như `apse1-az1` là định danh **cố định của AZ vật lý**, giống nhau ở mọi account.

AWS có thể ánh xạ AZ name sang AZ vật lý **khác nhau giữa các account**, để tài nguyên không dồn hết vào AZ "a". Vì vậy `ap-southeast-1a` của account A và `ap-southeast-1a` của account B **chưa chắc là cùng một AZ**. Khi phối hợp giữa nhiều account (ví dụ đặt hai hệ thống cùng AZ để giảm độ trễ), hãy so sánh bằng **AZ ID**.

### Chọn Region

Đừng mặc định chọn Region gần nhất. Hãy cân nhắc các tiêu chí sau:

| Tiêu chí | Câu hỏi cần trả lời |
|---|---|
| **Độ trễ** | Người dùng ở đâu? Đo độ trễ thực tế thay vì chỉ nhìn khoảng cách trên bản đồ. |
| **Dịch vụ & tính năng** | Region có đủ dịch vụ, loại instance và tính năng bạn cần không? |
| **Chi phí** | Giá cùng một dịch vụ khác nhau giữa các Region. Hãy dùng AWS Pricing Calculator. |
| **Dữ liệu & tuân thủ** | Có quy định về nơi lưu dữ liệu (data residency) hoặc yêu cầu pháp lý không? |
| **Region opt-in** | Một số Region mới phải được bật thủ công trước khi dùng. Bài này **không** bật Region. |
| **DR** | Nếu cần DR, Region dự phòng nào phù hợp và cách đủ xa? |

**Tình huống:** Một startup có người dùng chủ yếu ở Việt Nam. Cần kiểm tra:

1. Các Region trong khu vực Đông Nam Á/châu Á mà account đang dùng được (`describe-regions`), và Region nào cần opt-in.
2. Độ trễ đo thực tế từ mạng người dùng (Việt Nam) tới từng Region ứng viên.
3. Region ứng viên có đủ dịch vụ dự định dùng không.
4. Giá của các dịch vụ chính tại từng Region ứng viên.
5. Có ràng buộc pháp lý nào về lưu trữ dữ liệu không.

Không có đáp án cố định. Câu trả lời tốt nêu rõ tiêu chí và đánh đổi, ví dụ: "chọn X vì độ trễ thấp và có đủ dịch vụ, chấp nhận giá cao hơn Y khoảng Z%".

**Region dùng để học:** nên chọn **một Region cố định** đã được bật sẵn (`opt-in-not-required`), có đầy đủ dịch vụ phổ biến, rồi dùng thống nhất cho mọi lab. Như vậy bạn sẽ không bị "mất" tài nguyên vì quên Region nào. Trong các bài này, `ap-southeast-1` chỉ là **ví dụ minh họa**. Hãy thay bằng Region bạn chọn.

> **Tự kiểm tra:** Vì sao cùng AZ name ở hai account chưa chắc là cùng vị trí vật lý? Khi cần so sánh thì dùng định danh gì?

---

## Phần 4 — Chuẩn bị truy cập (20 phút)

### Nguyên tắc an toàn

- **Dùng account lab/sandbox**, không dùng account production để học.
- **Không dùng root user** cho công việc hằng ngày. Root có toàn quyền và không thể giới hạn bằng IAM policy. Hãy bật **MFA** cho root, cất thông tin đăng nhập root ở nơi an toàn và chỉ dùng cho những việc bắt buộc phải dùng root.
- **Ưu tiên temporary credentials**: credentials tạm thời, tự hết hạn. Tránh tạo long-lived access key (access key ID + secret không hết hạn), vì đây là nguồn rò rỉ phổ biến nhất.
- **Quyền tối thiểu:** lab này chỉ cần quyền đọc `ec2:DescribeRegions` và `ec2:DescribeAvailabilityZones`. **Không** cần, và không nên xin, `AdministratorAccess`.
- `sts:GetCallerIdentity` **không cần được cấp quyền**. Tài liệu AWS ghi rõ rằng thao tác này chạy được kể cả khi bị deny tường minh, vì nó chỉ trả lại thông tin về chính identity đang gọi.
- **Budget alert không phải trần chi tiêu.** AWS Budgets chỉ cảnh báo khi chi phí vượt ngưỡng, không tự chặn chi tiêu. Phần lớn lệnh đọc trong lab này không phát sinh phí, nhưng **đừng mặc định mọi thao tác trên Console/CLI đều miễn phí**.

### Các con đường xác thực

| Nhánh | Khi nào dùng | Bạn cần có |
|---|---|---|
| **A. IAM Identity Center (khuyến nghị)** | Tổ chức hoặc bạn đã thiết lập IAM Identity Center (trước đây là AWS SSO) | **Start URL** (dạng `https://<tên>.awsapps.com/start`), **SSO Region** (Region đặt Identity Center), **account ID**, **permission set / role** được gán |
| **B. Chưa có Identity Center** | Account cá nhân mới tạo, chưa ai thiết lập | Prerequisite **chưa đáp ứng**. Nhờ quản trị viên cấp quyền, hoặc tự thiết lập Identity Center theo tài liệu chính thức (bước tùy chọn, ngoài phạm vi bài) |
| **C. Sandbox cấp temporary credentials** | Nền tảng học tập/sandbox cấp sẵn bộ credentials tạm | Access key ID, secret access key, **session token** và thời hạn, theo hướng dẫn của sandbox |

Ghi chú cho nhánh B: từ AWS CLI **2.32.0** có lệnh `aws login`. Lệnh này dùng thông tin đăng nhập Console để sinh temporary credentials. Identity cần có managed policy `SignInLocalDevelopmentAccess`. Đây là lựa chọn hợp lệ nếu quản trị viên đã cấp quyền đó cho một IAM user hoặc role phục vụ lab. **Không dùng root để chạy `aws login`** cho việc học.

> **Tự kiểm tra:** Trước khi làm lab, bạn đang ở nhánh nào? Ai cấp thông tin đăng nhập cho bạn, và bạn đã có đủ Start URL, SSO Region, account, role chưa?

---

## Phần 5 — AWS CLI v2 và cấu hình (35 phút)

### Cài đặt

Chỉ cài từ nguồn chính thức: [Installing AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html). Trên macOS (bản CLI hiện hành yêu cầu macOS 11 trở lên):

```bash
# Cài bằng gói .pkg chính thức cho mọi user trên máy
curl "https://awscli.amazonaws.com/AWSCLIV2.pkg" -o "AWSCLIV2.pkg"
sudo installer -pkg AWSCLIV2.pkg -target /

# Kiểm tra
which aws        # thường là /usr/local/bin/aws
aws --version    # phải bắt đầu bằng aws-cli/2.
```

Tài liệu cài đặt hiện cũng giới thiệu script `install.sh` chính thức. Linux và Windows làm theo các mục tương ứng trong cùng trang. Homebrew tiện lợi nhưng không phải kênh phân phối do AWS duy trì.

### File cấu hình

CLI đọc hai file trong thư mục `~/.aws/`:

| File | Chứa gì | Ghi chú |
|---|---|---|
| `~/.aws/config` | Profile, Region, output, thông tin SSO | Không chứa secret nếu dùng SSO |
| `~/.aws/credentials` | Access key tĩnh (nếu có) | **Nhạy cảm**. Đừng in nội dung, đừng commit |

Khi dùng SSO, token và credentials tạm được cache trong `~/.aws/sso/cache` và `~/.aws/cli/cache`. Các file cache này cũng nhạy cảm như nhau.

Có thể đổi vị trí hai file bằng biến môi trường `AWS_CONFIG_FILE` và `AWS_SHARED_CREDENTIALS_FILE`.

### Named profile

**Profile** là một bộ cấu hình có tên (identity, Region, output). Thay vì dùng `[default]`, hãy dùng profile có tên rõ nghĩa như `aws-lab`, để không vô tình chạy lệnh vào nhầm account.

Ví dụ `~/.aws/config` cho nhánh A (giá trị đều là **placeholder**):

```ini
[profile aws-lab]
sso_session = lab-sso
sso_account_id = 111122223333
sso_role_name = LabReadOnly
region = ap-southeast-1
output = json

[sso-session lab-sso]
sso_start_url = https://my-org.awsapps.com/start
sso_region = us-east-1
sso_registration_scopes = sso:account:access
```

Hai Region ở đây **khác nhau về ý nghĩa**:

- `sso_region` là Region nơi **IAM Identity Center** được thiết lập. CLI chỉ dùng nó để đăng nhập.
- `region` là Region **mặc định cho các lệnh dịch vụ** (EC2, S3…).

Hai giá trị này khác nhau là **hoàn toàn bình thường**, không phải cấu hình sai.

### Region trên Console ≠ Region của CLI

Region chọn trên Console được lưu trong trình duyệt. Region của CLI lấy từ cấu hình CLI. **Hai bên không tự đồng bộ.** Bạn có thể đang xem `us-east-1` trên Console trong khi CLI chạy ở `ap-southeast-1`. Đây là lý do thường gặp khi "tạo bằng CLI rồi mà không thấy trên Console".

### Thứ tự ưu tiên (precedence)

Theo tài liệu CLI hiện hành, các nguồn cấu hình và credentials được ưu tiên theo thứ tự:

1. Tùy chọn dòng lệnh: `--region`, `--output`, `--profile`
2. Biến môi trường
3. Assume role
4. Assume role with web identity
5. IAM Identity Center
6. File credentials
7. Custom process
8. File config
9. Container credentials (ECS task role)
10. EC2 instance profile

Danh sách trên gộp nhiều loại cài đặt khác nhau. Để dễ suy luận, hãy tách thành hai câu hỏi.

**Câu hỏi 1: Region nào được dùng?** Theo thứ tự ưu tiên giảm dần:

| Ưu tiên | Nguồn |
|---|---|
| 1 | `--region` trên dòng lệnh |
| 2 | Biến môi trường `AWS_REGION` (ghi đè `AWS_DEFAULT_REGION`) |
| 3 | Biến môi trường `AWS_DEFAULT_REGION` |
| 4 | Cài đặt `region` trong profile đang dùng (file config) |

Output format (`--output`, `AWS_DEFAULT_OUTPUT`, `output` trong profile) cũng theo nguyên tắc: dòng lệnh > biến môi trường > file.

**Câu hỏi 2: Credentials nào được dùng?** CLI duyệt lần lượt các credential provider và dùng nguồn đầu tiên tìm thấy. Biến môi trường (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`) đứng **trước** credentials lấy từ profile, dù profile đó là SSO, file credentials hay file config.

Một bẫy thường gặp: trong shell còn sót `AWS_ACCESS_KEY_ID` từ phiên trước, nên lệnh chạy bằng identity khác với profile bạn nghĩ. Tài liệu **không mô tả trực tiếp** mọi tổ hợp (ví dụ cùng lúc có `AWS_PROFILE` hoặc `--profile` và access key trong biến môi trường). Vì vậy đừng suy đoán. Hãy:

```bash
env | grep '^AWS_' | sed 's/=.*/=<đã ẩn>/'   # xem biến AWS_* nào đang được đặt, không in giá trị
aws configure list --profile aws-lab           # xem giá trị hiệu lực và nguồn (Type/Location)
aws sts get-caller-identity --profile aws-lab  # xác nhận identity thật sự đang dùng
```

Nếu có biến credentials không mong muốn thì `unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN`.

### Thói quen "biết mình đang ở đâu"

Trước mọi lệnh có thể thay đổi tài nguyên (ở các bài sau), hãy trả lời ba câu hỏi:

1. **Account nào?** → `aws sts get-caller-identity` (trường `Account`)
2. **Identity nào?** → cũng lệnh đó (trường `Arn`: user hay role nào)
3. **Region nào?** → `aws configure list` hoặc chỉ định tường minh `--region`

### Output, help, query

- `--output json | yaml | text | table`: `json` dễ đọc bằng máy, `table` dễ đọc bằng mắt, `text` tiện kết hợp với `grep`/`awk`.
- `aws help`, `aws ec2 help`, `aws ec2 describe-regions help`: trợ giúp theo từng cấp, hoạt động offline.
- `--query` dùng cú pháp **JMESPath** để **lọc phía client**. CLI vẫn nhận toàn bộ response từ API rồi mới lọc trên máy bạn.
- `--filters` (với các API hỗ trợ, như EC2 `describe-*`) là **lọc phía server**. API chỉ trả về những gì khớp điều kiện.
- **Pager:** mặc định CLI v2 chuyển output dài qua `less`. Tắt cho một lệnh bằng `--no-cli-pager`, cho cả phiên bằng `export AWS_PAGER=""`, hoặc vĩnh viễn bằng `cli_pager=` trong profile.

---

## Phần 6 — Lab chỉ đọc (45 phút)

Làm theo **[labs/01-foundations/README.md](../labs/01-foundations/README.md)**. Lab chỉ đọc dữ liệu và quản lý phiên/cấu hình cục bộ, **không tạo tài nguyên dịch vụ**.

---

## Phần 7 — Troubleshooting và tổng kết (25 phút)

| Triệu chứng | Chẩn đoán | Cách xử lý |
|---|---|---|
| `aws: command not found` | CLI chưa cài, hoặc thư mục cài không có trong `PATH` | Cài theo trang chính thức. Kiểm tra `which aws` và `echo $PATH`. Mở terminal mới |
| `The config profile (aws-lab) could not be found` | Sai tên profile, file config ở chỗ khác, hoặc `AWS_PROFILE`/`AWS_CONFIG_FILE` trỏ sai | `aws configure list-profiles`. Kiểm tra `echo $AWS_PROFILE $AWS_CONFIG_FILE`. Trong file config, tên phải là `[profile aws-lab]` |
| `Unable to locate credentials` | Không provider nào có credentials: chưa login, chưa chọn profile, hoặc profile thiếu thông tin | Thêm `--profile aws-lab`. Với SSO thì `aws sso login --profile aws-lab` |
| Lỗi token SSO hết hạn / `Error when retrieving token from sso` | Phiên Identity Center đã hết hạn | `aws sso login --profile aws-lab` rồi chạy lại |
| `AccessDenied` / `UnauthorizedOperation` | Đã **xác thực** nhưng **không được phép** gọi API này | Đọc thông báo lỗi để xác định API bị từ chối (ví dụ `ec2:DescribeAvailabilityZones`). Xin đúng quyền đó. **Không** xử lý bằng cách xin quyền admin |
| `You must specify a region` | Không có Region ở cả dòng lệnh, biến môi trường và profile | Thêm `--region` hoặc đặt `region` trong profile |
| `Could not connect to the endpoint URL` | Sai mã Region (gõ nhầm), Region chưa enable, hoặc vấn đề mạng/proxy/VPN | So sánh với danh sách từ `describe-regions`. Kiểm tra `HTTPS_PROXY` và mạng |
| `AuthFailure` / token không hợp lệ ở một Region | Region chưa được opt-in cho account | Dùng Region `opt-in-not-required`. Lab này không bật Region |
| Kết quả từ sai account/identity | Biến môi trường credentials ghi đè, hoặc dùng nhầm profile | Chạy `env \| grep '^AWS_'` (ẩn giá trị), `aws configure list`, `aws sts get-caller-identity` |
| Output bị kẹt trong màn hình `less` | Client-side pager | Nhấn `q` để thoát. Dùng `--no-cli-pager` hoặc `AWS_PAGER=""` |

### Kết thúc phiên

- Nhánh A (SSO): `aws sso logout` xóa token và credentials đã cache.
- `aws login` (nếu dùng): `aws logout --profile <tên>`.
- Nhánh C (sandbox): `unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN` và đóng terminal. Làm theo quy trình kết thúc của sandbox.

### Cầu nối sang bài IAM

Lỗi `UnauthorizedOperation` cho thấy hai bước khác nhau:

- **Authentication (xác thực):** *bạn là ai?* `sts get-caller-identity` thành công nghĩa là AWS đã biết bạn là ai.
- **Authorization (phân quyền):** *bạn được làm gì?* Bước này do IAM policy quyết định, và là chủ đề của bài tiếp theo.

Xác thực thành công **không có nghĩa** là được phép làm mọi thứ.

---

## Câu hỏi tự kiểm tra

1. Một Region và một AZ khác nhau ở ranh giới lỗi nào?
2. Hai instance cùng một AZ có bảo vệ được trước sự cố toàn AZ không?
3. Vì sao đổi Region trên Console không làm CLI đổi Region?
4. Vì sao cùng AZ name ở hai account chưa chắc là cùng vị trí vật lý?
5. Với người dùng chủ yếu ở Việt Nam, cần kiểm tra gì trước khi chọn Region?
6. Khi SSO Region khác Region triển khai, có phải cấu hình sai không?
7. Làm thế nào biết lệnh sắp chạy đang dùng account, identity và Region nào?
8. Nếu không thấy tài nguyên mong đợi, cần kiểm tra account, Region, quyền và bộ lọc theo cách nào?

**Bài tập cuối:** viết một ghi chú ngắn gồm Region đã chọn và lý do, sơ đồ account/Region/AZ, lệnh kiểm tra identity, bảng AZ đã ẩn thông tin định danh, và một lỗi bạn đã tự chẩn đoán. Mẫu ghi chú có trong [lab](../labs/01-foundations/README.md#bước-9--kết-thúc-phiên-và-ghi-lại-kết-quả).

Đáp án gợi ý nằm ở cuối bài. Hãy tự trả lời trước khi xem.

---

## Tài liệu tham khảo chính thức

- [AWS Regions](https://docs.aws.amazon.com/global-infrastructure/latest/regions/aws-regions.html)
- [Regions and Availability Zones — Amazon EC2](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/using-regions-availability-zones.html)
- [Shared Responsibility Model](https://aws.amazon.com/compliance/shared-responsibility-model/)
- [Installing AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html)
- [Setting up the AWS CLI](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-quickstart.html)
- [Configuring IAM Identity Center for the CLI](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sso.html)
- [Login with console credentials (`aws login`)](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sign-in.html)
- [Configuration and credential precedence](https://docs.aws.amazon.com/cli/latest/userguide/cli-chap-configure.html)
- [Environment variables](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-envvars.html)
- [Configuration and credential files](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-files.html)
- [Pagination & client-side pager](https://docs.aws.amazon.com/cli/latest/userguide/cli-usage-pagination.html)
- CLI reference: [get-caller-identity](https://docs.aws.amazon.com/cli/latest/reference/sts/get-caller-identity.html) · [describe-regions](https://docs.aws.amazon.com/cli/latest/reference/ec2/describe-regions.html) · [describe-availability-zones](https://docs.aws.amazon.com/cli/latest/reference/ec2/describe-availability-zones.html)

## Trạng thái kiểm chứng

- **Đã đối chiếu với tài liệu AWS ngày 2026-10-31:** thứ tự precedence, quan hệ `AWS_REGION`/`AWS_DEFAULT_REGION`, luồng và file cấu hình `aws configure sso` (PKCE mặc định từ CLI 2.22.0), `aws login` (từ 2.32.0), ghi chú quyền của `get-caller-identity`, hành vi mặc định và filter của `describe-regions`/`describe-availability-zones`, cách tắt pager, lệnh cài đặt trên macOS.
- **Chưa kiểm thử thực tế:** chưa chạy lệnh nào trên AWS account thật. Mọi output trong bài và lab là **minh họa**, đã thay định danh bằng placeholder.
- **Suy luận, không phải trích dẫn tài liệu:** hành vi khi đồng thời có `--profile`/`AWS_PROFILE` và access key trong biến môi trường. Bài khuyên kiểm tra bằng `configure list` và `get-caller-identity` thay vì dựa vào suy luận này.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. **Region và AZ:** AZ là ranh giới lỗi *bên trong* một Region. Các AZ có điện, làm mát và mạng độc lập, nên sự cố cục bộ thường chỉ ảnh hưởng một AZ. Region là ranh giới lớn hơn và độc lập với các Region khác, nên sự cố hoặc thay đổi ở một Region không lan sang Region khác. Thiết kế multi-AZ chống được sự cố AZ. Muốn chống sự cố cả Region thì cần chiến lược DR qua Region khác.
2. **Không.** Cả hai instance chung một failure domain. Cần đặt ít nhất ở hai AZ khác nhau, và có cơ chế phân phối traffic/failover.
3. Region trên Console là trạng thái giao diện lưu trong trình duyệt. CLI lấy Region từ `--region`, biến môi trường hoặc profile. Hai bên không chia sẻ cấu hình.
4. AWS ánh xạ AZ name sang AZ vật lý độc lập cho từng account. Để so sánh giữa các account, hãy dùng **AZ ID** (ví dụ `apse1-az1`).
5. Độ trễ đo thực tế từ người dùng, dịch vụ/tính năng có sẵn, giá, yêu cầu pháp lý về dữ liệu, trạng thái opt-in, và Region dự phòng nếu cần DR. Câu trả lời cần nêu đánh đổi, không chỉ nêu tên Region.
6. **Không.** `sso_region` là nơi đặt IAM Identity Center và chỉ dùng để đăng nhập. `region` là nơi gửi lệnh dịch vụ. Hai giá trị khác nhau là bình thường.
7. `aws sts get-caller-identity --profile <p>` cho biết account và identity (ARN). `aws configure list --profile <p>` cho biết Region hiệu lực và nguồn của nó. Ngoài ra nên kiểm tra biến `AWS_*` trong shell, và với lệnh quan trọng thì truyền `--profile`/`--region` tường minh.
8. Theo thứ tự: (a) **account/identity**: `get-caller-identity`; (b) **Region**: tài nguyên regional/zonal chỉ hiện ở Region của nó, nên chạy lại với `--region` đúng; (c) **quyền**: có lỗi `AccessDenied` không, hay identity chỉ thấy một phần; (d) **bộ lọc**: bỏ `--filters`/`--query` để xem dữ liệu gốc, kiểm tra tên filter (phân biệt hoa thường). Trên Console thì kiểm tra Region đang chọn ở góc trên.

**Bài tập cuối (khung gợi ý):** Region đã chọn kèm 2–3 lý do theo bảng tiêu chí; sơ đồ dạng `account (ẩn) → <region> → <AZ ID 1>, <AZ ID 2>, …`; lệnh `aws sts get-caller-identity --profile aws-lab` với output đã ẩn; bảng ZoneName/ZoneId/State; một lỗi được mô tả theo cấu trúc *triệu chứng → giả thuyết → lệnh kiểm tra → nguyên nhân → cách sửa*.

</details>
