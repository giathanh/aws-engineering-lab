# Lab 01 — Nền tảng AWS: CLI, identity, Region và AZ (chỉ đọc)

> **Lý thuyết:** [notes/01-foundations.md](../../notes/01-foundations.md). Nên đọc phần 1–5 trước khi làm lab.
> **Thời lượng:** khoảng 45 phút (chưa tính thời gian chờ cấp quyền).
> **Ngày đối chiếu tài liệu:** 2026-10-31. **Chưa kiểm thử trên account thật.** Mọi output bên dưới là **minh họa**, định danh đã được thay bằng placeholder.

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| Đọc thông tin identity, Region, AZ | Tạo EC2, S3, VPC hay bất kỳ tài nguyên dịch vụ nào |
| Tạo/sửa **profile cục bộ** trong `~/.aws/config` | Bật (opt-in) Region mới |
| Đăng nhập/đăng xuất phiên | Dùng root access key hoặc tạo long-lived access key |
| | Xin `AdministratorAccess` |

## Quy ước placeholder

| Placeholder | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Tên profile dùng trong lab | (giữ nguyên) |
| `<REGION>` | Region học tập bạn đã chọn | `ap-southeast-1` |
| `<REGION_2>` | Một Region khác **đã enable** sẵn | `us-west-2` |
| `<ACCOUNT_ID>` | Account ID 12 số | `111122223333` |
| `<START_URL>` | Start URL của IAM Identity Center | `https://my-org.awsapps.com/start` |
| `<SSO_REGION>` | Region đặt IAM Identity Center | `us-east-1` |
| `<ROLE_NAME>` | Permission set/role được gán | `LabReadOnly` |

**Khi chia sẻ kết quả** (blog, repo, chat), luôn che account ID, ARN, user ID và start URL. Ví dụ: `arn:aws:sts::<ACCOUNT_ID>:assumed-role/<ROLE_NAME>/<user>`.

## Điều kiện đầu vào

- [ ] Máy có internet và terminal. Hướng dẫn viết cho macOS/zsh. Với Linux/Windows, xem [trang cài đặt chính thức](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html).
- [ ] AWS CLI v2 đã được cài từ nguồn chính thức (xem [lý thuyết phần 5](../../notes/01-foundations.md#phần-5--aws-cli-v2-và-cấu-hình-35-phút)).
- [ ] Có **account lab/sandbox** được phép dùng (không dùng production).
- [ ] Có một con đường xác thực hợp lệ: nhánh A, B hoặc C ở bước 2.
- [ ] Identity có quyền đọc `ec2:DescribeRegions` và `ec2:DescribeAvailabilityZones`. Lệnh `sts:GetCallerIdentity` không cần cấp quyền riêng.

Quyền tối thiểu cần xin quản trị viên, để tham khảo (cách viết policy được học ở bài IAM):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": ["ec2:DescribeRegions", "ec2:DescribeAvailabilityZones"],
      "Resource": "*"
    }
  ]
}
```

---

## Bước 1 — Kiểm tra CLI v2 và cách tra help

**Mục đích:** chắc chắn đang dùng CLI v2 và biết cách tự tra cứu.

```bash
aws --version
aws help                      # nhấn q để thoát
aws ec2 describe-regions help # help của một command cụ thể
```

**Output minh họa:**

```text
aws-cli/2.x.y Python/3.x.y Darwin/xx.x.x exe/...
```

**Xác minh:**
- Chuỗi phiên bản bắt đầu bằng `aws-cli/2.`. Nếu là `aws-cli/1.`, bạn đang dùng CLI v1; hãy gỡ bỏ hoặc sửa `PATH`.
- Nếu muốn dùng `aws login` (nhánh B), phiên bản cần ≥ 2.32.0.
- Bạn tìm được mục `OPTIONS` và `EXAMPLES` trong trang help.

---

## Bước 2 — Tạo profile `aws-lab` và đăng nhập

**Mục đích:** có một profile riêng cho lab, xác thực bằng temporary credentials.

Trước tiên, đảm bảo shell không còn biến credentials cũ:

```bash
env | grep '^AWS_' | sed 's/=.*/=<đã ẩn>/'
# Nếu thấy AWS_ACCESS_KEY_ID / AWS_SECRET_ACCESS_KEY / AWS_SESSION_TOKEN không thuộc lab này:
unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN
```

### Nhánh A — IAM Identity Center (khuyến nghị)

Yêu cầu: `<START_URL>`, `<SSO_REGION>`, account và role đã được gán cho bạn.

```bash
aws configure sso
```

Trả lời các câu hỏi theo thứ tự (CLI hiển thị lần lượt):

```text
SSO session name (Recommended): lab-sso
SSO start URL [None]: <START_URL>
SSO region [None]: <SSO_REGION>
SSO registration scopes [None]: sso:account:access
  → Trình duyệt mở ra; đăng nhập và bấm cho phép.
  → Chọn account (bỏ qua nếu chỉ có một), rồi chọn role (bỏ qua nếu chỉ có một).
Default client Region [None]: <REGION>
CLI default output format (json if not specified) [None]: json
Profile name [...]: aws-lab
```

Kết quả trong `~/.aws/config` (minh họa):

```ini
[profile aws-lab]
sso_session = lab-sso
sso_account_id = <ACCOUNT_ID>
sso_role_name = <ROLE_NAME>
region = <REGION>
output = json

[sso-session lab-sso]
sso_start_url = <START_URL>
sso_region = <SSO_REGION>
sso_registration_scopes = sso:account:access
```

Những lần sau, khi phiên hết hạn:

```bash
aws sso login --profile aws-lab
```

Ghi chú:
- Từ CLI 2.22.0, luồng mặc định là PKCE và cần trình duyệt trên cùng máy. Nếu máy không có trình duyệt (ví dụ SSH vào server), thêm `--use-device-code` cho cả `aws configure sso` và `aws sso login`.
- `<SSO_REGION>` khác `<REGION>` là bình thường (xem [lý thuyết](../../notes/01-foundations.md#named-profile)).

### Nhánh B — Chưa có IAM Identity Center

**Prerequisite chưa đáp ứng.** Chọn một trong các cách sau:

1. Nhờ quản trị viên cấp truy cập qua Identity Center, rồi quay lại nhánh A.
2. Tự thiết lập Identity Center theo [tài liệu chính thức](https://docs.aws.amazon.com/singlesignon/latest/userguide/get-set-up-for-idc.html). Đây là bước tùy chọn, ngoài phạm vi lab này.
3. Nếu quản trị viên đã gán `SignInLocalDevelopmentAccess` cho một IAM user/role dùng cho lab (không phải root), dùng `aws login`:

   ```bash
   aws login --profile aws-lab     # mở trình duyệt; thêm --remote nếu máy không có trình duyệt
   aws configure set region <REGION> --profile aws-lab
   ```

   Credentials tự làm mới trong suốt thời hạn phiên (tối đa 12 giờ, tùy cấu hình của identity). Sau đó cần chạy `aws login` lại.

### Nhánh C — Sandbox cấp temporary credentials

Làm theo hướng dẫn của sandbox. Cách thường gặp là export vào **phiên shell hiện tại**:

```bash
export AWS_ACCESS_KEY_ID=<từ sandbox>
export AWS_SECRET_ACCESS_KEY=<từ sandbox>
export AWS_SESSION_TOKEN=<từ sandbox>     # bắt buộc với credentials tạm
export AWS_REGION=<REGION>
```

- **Không** dán các giá trị này vào file trong repo, vào lịch sử lệnh dùng chung hay vào ảnh chụp màn hình.
- Credentials có thời hạn. Khi hết hạn, lấy bộ mới từ sandbox.
- Ở nhánh này, biến môi trường đã quyết định identity, nên bỏ `--profile aws-lab` khỏi các lệnh bên dưới.

**Xác minh bước 2:**

```bash
aws configure list-profiles   # nhánh A/B: có aws-lab
git status                    # repo không có file .aws, credentials hay token mới
```

---

## Bước 3 — Kiểm tra identity

**Mục đích:** biết chính xác *account nào* và *identity nào* đang được dùng.

```bash
aws sts get-caller-identity --profile aws-lab
```

**Output minh họa (đã ẩn):**

```json
{
    "UserId": "AROAEXAMPLEID:<user>",
    "Account": "<ACCOUNT_ID>",
    "Arn": "arn:aws:sts::<ACCOUNT_ID>:assumed-role/AWSReservedSSO_<ROLE_NAME>_<suffix>/<user>"
}
```

**Xác minh:**
- `Account` đúng là account lab (so với thông tin được cấp), **không phải** production.
- `Arn` cho biết bạn đang dùng role (`assumed-role/...`) hay IAM user (`user/...`). Với nhánh A, tên role thường có tiền tố `AWSReservedSSO_`.
- Lệnh này thành công chỉ chứng minh **xác thực** thành công, chưa chứng minh bạn có quyền làm gì.

---

## Bước 4 — Kiểm tra cấu hình hiệu lực

**Mục đích:** biết Region và credentials đến từ **nguồn nào**, mà không in nội dung file nhạy cảm.

```bash
aws configure list --profile aws-lab
aws configure get region --profile aws-lab
aws configure list-profiles
```

**Output minh họa của `configure list`:**

```text
      Name                    Value             Type    Location
      ----                    -----             ----    --------
   profile                  aws-lab           manual    --profile
access_key     ****************WXYZ              sso
secret_key     ****************ABCD              sso
    region           <REGION>          config-file    ~/.aws/config
```

**Xác minh:**
- Đọc cột `Type`/`Location` để biết nguồn. Ví dụ `env` nghĩa là giá trị đến từ biến môi trường, `config-file` nghĩa là từ `~/.aws/config`.
- Thử ghi đè Region bằng biến môi trường rồi quan sát:

  ```bash
  AWS_REGION=<REGION_2> aws configure list --profile aws-lab   # region giờ có Type = env
  ```

- **Không** chạy `cat ~/.aws/credentials`, và không chia sẻ nội dung thư mục `~/.aws/sso/cache` hay `~/.aws/cli/cache`.

---

## Bước 5 — Liệt kê Region

**Mục đích:** xem các Region mà account dùng được, và hiểu khái niệm opt-in.

```bash
aws ec2 describe-regions --profile aws-lab --output table \
  --query 'Regions[].{Region:RegionName,OptIn:OptInStatus}'
```

**Output minh họa (rút gọn):**

```text
-------------------------------------------------
|                DescribeRegions                |
+------------------------+----------------------+
|          OptIn         |       Region         |
+------------------------+----------------------+
|  opt-in-not-required   |  ap-southeast-1      |
|  opt-in-not-required   |  us-east-1           |
|  opted-in              |  <region-đã-bật>     |
|  ...                   |  ...                 |
+------------------------+----------------------+
```

So sánh với danh sách đầy đủ (chỉ **đọc**, không bật Region):

```bash
aws ec2 describe-regions --all-regions --profile aws-lab \
  --filters Name=opt-in-status,Values=not-opted-in \
  --query 'Regions[].RegionName' --output text
```

**Xác minh và giải thích:**
- Không có `--all-regions`, API chỉ trả về các Region **đã enable** cho account. Có `--all-regions`, API trả thêm các Region bị tắt.
- `OptInStatus` có ba giá trị: `opt-in-not-required` (bật sẵn), `opted-in` (đã bật thủ công), `not-opted-in` (chưa bật).
- Lệnh `describe-regions` vẫn gửi tới endpoint của một Region (Region của profile), nhưng kết quả là danh sách Region ở cấp account.
- Số lượng Region bạn thấy có thể khác với người khác hoặc khác với tài liệu. Đó là điều bình thường.

---

## Bước 6 — Liệt kê AZ trong Region đã chọn

**Mục đích:** đọc ZoneName, ZoneId, State, và chỉ lấy Availability Zone thật sự.

```bash
aws ec2 describe-availability-zones --profile aws-lab --region <REGION> \
  --filters Name=zone-type,Values=availability-zone \
  --query 'AvailabilityZones[].{Name:ZoneName,Id:ZoneId,State:State}' \
  --output table
```

**Output minh họa:**

```text
--------------------------------------------------
|            DescribeAvailabilityZones           |
+-------------+---------------------+------------+
|     Id      |        Name         |   State    |
+-------------+---------------------+------------+
|  apse1-az1  |  ap-southeast-1a    |  available |
|  apse1-az2  |  ap-southeast-1b    |  available |
|  apse1-az3  |  ap-southeast-1c    |  available |
+-------------+---------------------+------------+
```

*(Ánh xạ Name ↔ Id ở trên chỉ để minh họa. Account của bạn có thể khác.)*

**Xác minh:**
- Bạn đọc được cả ba cột. Hãy ghi lại cặp **Name ↔ Id** của account mình.
- Filter `zone-type=availability-zone` loại bỏ Local Zones và Wavelength Zones (nếu account đã opt-in), vì chúng cũng được API này trả về. Thử bỏ filter và so sánh kết quả.
- `--filters` lọc **phía server** (tên filter phân biệt hoa thường). `--query` chỉ chọn trường để hiển thị, thực hiện **phía client**.

---

## Bước 7 — Cùng truy vấn với Region khác

**Mục đích:** thấy rằng mỗi Region có tập AZ riêng, và đổi Region cho **một lệnh** mà không sửa cấu hình chung.

```bash
aws ec2 describe-availability-zones --profile aws-lab --region <REGION_2> \
  --filters Name=zone-type,Values=availability-zone \
  --query 'AvailabilityZones[].[ZoneName,ZoneId]' --output text
```

Chọn `<REGION_2>` trong danh sách ở bước 5 có trạng thái `opt-in-not-required` hoặc `opted-in`.

**Xác minh:**
- Tên AZ có tiền tố là Region mới, ZoneId có tiền tố khác, số AZ có thể khác.
- `aws configure get region --profile aws-lab` vẫn trả về `<REGION>`, tức là `--region` chỉ ảnh hưởng một lệnh.
- **Giải thích:** AZ là thành phần của Region, nên mỗi Region có AZ riêng. Hãy liên hệ với việc "không thấy tài nguyên" khi nhìn nhầm Region.

---

## Bước 8 — Thực hành output và query

**Mục đích:** quen với các dạng output và JMESPath cơ bản.

```bash
# Cùng dữ liệu, ba định dạng
aws ec2 describe-availability-zones --profile aws-lab --output json  --no-cli-pager
aws ec2 describe-availability-zones --profile aws-lab --output table --no-cli-pager
aws ec2 describe-availability-zones --profile aws-lab --output text  --no-cli-pager

# Chỉ lấy danh sách ZoneId
aws ec2 describe-availability-zones --profile aws-lab \
  --query 'AvailabilityZones[].ZoneId' --output text

# Lọc phía client bằng JMESPath: chỉ AZ đang available
aws ec2 describe-availability-zones --profile aws-lab \
  --query "AvailabilityZones[?State=='available'].ZoneName"

# Đếm số Region đã enable
aws ec2 describe-regions --profile aws-lab --query 'length(Regions)'
```

**Xác minh:**
- Bạn giải thích được khi nào dùng `json` (script, `jq`), `table` (đọc bằng mắt) và `text` (kết hợp với `grep`/`awk`).
- Bạn phân biệt được: `--query "AvailabilityZones[?State=='available']"` lọc **sau khi** đã nhận response, còn `--filters Name=state,Values=available` lọc **trên server**.
- Chú ý cách quote: dùng nháy đơn bên ngoài cho JMESPath có backtick hoặc khoảng trắng; dùng nháy kép bên ngoài khi bên trong có chuỗi `'available'`.

---

## Bước 9 — Kết thúc phiên và ghi lại kết quả

**Mục đích:** đóng phiên đúng cách và ghi bằng chứng đã ẩn thông tin định danh.

Đăng xuất theo nhánh bạn dùng:

```bash
# Nhánh A
aws sso logout

# Nhánh B (aws login)
aws logout --profile aws-lab

# Nhánh C
unset AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN AWS_REGION
```

Kiểm tra phiên đã đóng (nhánh A/B):

```bash
aws sts get-caller-identity --profile aws-lab   # mong đợi: lỗi token/credentials
```

### Mẫu ghi chú kết quả

Tạo file ghi chú **ngoài repo**, hoặc trong repo nếu đã ẩn hết thông tin định danh:

```markdown
## Lab 01 — Kết quả (ngày ...)

**Region học tập:** <REGION> — lý do: (độ trễ / dịch vụ / chi phí / không cần opt-in ...)

**Sơ đồ:**
account <ACCOUNT_ID> → <REGION> → <AZ ID 1>, <AZ ID 2>, <AZ ID 3>

**Kiểm tra identity:** `aws sts get-caller-identity --profile aws-lab`
→ Arn: arn:aws:sts::<ACCOUNT_ID>:assumed-role/<ROLE_NAME>/<user>

**Bảng AZ:**
| ZoneName | ZoneId | State |
|---|---|---|
| ... | ... | ... |

**Một lỗi đã tự chẩn đoán:**
- Triệu chứng:
- Giả thuyết:
- Lệnh kiểm tra:
- Nguyên nhân:
- Cách sửa:

**Tài nguyên dịch vụ đã tạo:** không có (chỉ đọc + profile cục bộ).
```

---

## Checklist hoàn thành

- [ ] `aws --version` hiển thị CLI v2
- [ ] Profile `aws-lab` hoạt động (hoặc nhánh C đã cấu hình qua biến môi trường)
- [ ] `get-caller-identity` trả về đúng account lab và role mong đợi
- [ ] Giải thích được nguồn của Region và credentials qua `configure list`
- [ ] Liệt kê được Region và giải thích `OptInStatus`
- [ ] Có bảng ZoneName/ZoneId/State cho `<REGION>` và so sánh được với `<REGION_2>`
- [ ] Phân biệt được `--filters` (server) với `--query` (client)
- [ ] Đã logout và ghi chú kết quả đã ẩn thông tin định danh
- [ ] Không có credentials, token hay file cấu hình thật trong repo (`git status` sạch)
- [ ] Không tạo tài nguyên dịch vụ và không bật Region nào

## Troubleshooting nhanh

| Triệu chứng | Làm gì |
|---|---|
| `aws: command not found` | `which aws`, kiểm tra `PATH`, mở terminal mới, cài lại từ nguồn chính thức |
| `The config profile (aws-lab) could not be found` | `aws configure list-profiles`; kiểm tra `AWS_PROFILE`, `AWS_CONFIG_FILE`; tên section phải là `[profile aws-lab]` |
| `Unable to locate credentials` | Thiếu `--profile`, hoặc chưa login: `aws sso login --profile aws-lab` |
| Token SSO hết hạn | `aws sso login --profile aws-lab` |
| `UnauthorizedOperation` / `AccessDenied` | Ghi lại tên API trong thông báo lỗi và xin đúng quyền đó (xem phần điều kiện đầu vào), không xin admin |
| `You must specify a region` | Thêm `--region <REGION>` hoặc `aws configure set region <REGION> --profile aws-lab` |
| `Could not connect to the endpoint URL` | Kiểm tra lỗi chính tả mã Region, Region đã enable chưa, mạng/proxy/VPN |
| Sai account/identity | `env \| grep '^AWS_' \| sed 's/=.*/=<đã ẩn>/'`, `unset` biến thừa, chạy lại bước 3–4 |
| Kẹt trong màn hình `less` | Nhấn `q`; thêm `--no-cli-pager` hoặc `export AWS_PAGER=""` |

Chi tiết chẩn đoán: [lý thuyết phần 7](../../notes/01-foundations.md#phần-7--troubleshooting-và-tổng-kết-25-phút).

## Tiếp theo

Bước 3 cho thấy bạn đã **xác thực** được. Nhưng nếu bước 6 báo `UnauthorizedOperation`, nghĩa là bạn chưa được **phân quyền**. Bài IAM tiếp theo sẽ giải thích policy quyết định ai được làm gì.
