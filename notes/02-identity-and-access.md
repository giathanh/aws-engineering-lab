# Bài 02 — Danh tính và quyền truy cập: IAM, IAM Identity Center và STS

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 4–5 giờ, chia hai buổi. Buổi 1 gồm phần 1–5 (lý thuyết & kiến trúc). Buổi 2 gồm phần 6–7 và [lab thực hành](../labs/02-identity-and-access/README.md).
> **Kế hoạch gốc:** [plans/02-identity-and-access.md](../plans/02-identity-and-access.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Phân biệt rõ **Authentication** (xác thực danh tính) và **Authorization** (phân quyền hành vi) trong AWS.
2. Giải thích sự khác nhau và mối liên kết giữa IAM User, Group, Role và Session.
3. Đọc, viết và phân tích tài liệu IAM Policy JSON: các phần tử `Effect`, `Action`, `Resource`, `Condition` và định dạng chuẩn của Amazon Resource Name (ARN).
4. Phân biệt **Identity-based policy**, **Resource-based policy** và **Trust policy**; hiểu sâu thứ tự đánh giá quyền với nguyên tắc **Explicit Deny** luôn thắng.
5. Giải thích cơ chế hoạt động của `sts:AssumeRole`, cấu trúc temporary credentials (`AccessKeyId`, `SecretAccessKey`, `SessionToken`, `Expiration`) và vai trò của IAM Identity Center (SSO) cùng MFA.
6. Hiểu các lớp kiểm soát nâng cao: Least privilege, Permission Boundary và Service Control Policy (SCP) ở cấp AWS Organizations.
7. Thiết kế và kiểm thử thành công chính sách phân quyền cho hệ thống đơn hàng mẫu (Order Service), bao gồm cả happy path và negative test có kiểm soát.

---

## Phần 1 — Authentication và Authorization: User, Group, Role, Session (35 phút)

### Authentication (AuthN) và Authorization (AuthZ)

Khi một request gửi tới API của AWS, hệ thống luôn thực hiện hai bước tuần tự:

```text
Request tới AWS API
       │
       ▼
 ┌──────────────┐      Không hợp lệ      ┌─────────────────────┐
 │ Authentication├──────────────────────►│ 403 Invalid Token / │
 │  "Bạn là ai?" │                       │ Missing Credentials │
 └──────┬───────┘                       └─────────────────────┘
        │ Hợp lệ (Principal đã xác thực)
        ▼
 ┌──────────────┐      Không có quyền    ┌─────────────────────┐
 │ Authorization ├──────────────────────►│ 403 AccessDenied    │
 │ "Làm được gì?"│                       │ (Unauthorized)      │
 └──────┬───────┘                       └─────────────────────┘
        │ Được phép (Explicit Allow & Không bị Deny)
        ▼
   Thực thi API (200 OK)
```

- **Authentication (AuthN):** Chứng minh danh tính của người gửi. Bạn cung cấp thông tin bí mật (mật khẩu, MFA code, hoặc chữ ký số HMAC tính từ Access Key / Secret Key). Nếu hợp lệ, AWS biết **Principal** (chủ thể) gửi yêu cầu là ai.
- **Authorization (AuthZ):** Quyết định chủ thể đó có được phép thực hiện hành động cụ thể trên tài nguyên cụ thể hay không. Việc đăng nhập thành công **chưa mang lại bất kỳ quyền hạn nào** nếu không có chính sách (Policy) cấp phép.

### IAM User, User Group và IAM Role

Trong AWS Identity and Access Management (IAM), có ba khái niệm danh tính cốt lõi cần phân biệt:

| Khái niệm | Định nghĩa | Credentials | Trường hợp sử dụng phù hợp |
|---|---|---|---|
| **IAM User** | Một danh tính cố định đại diện cho một người hoặc ứng dụng cụ thể trong account. | Cố định (long-lived): Mật khẩu console hoặc cặp Access Key ID + Secret Access Key. | Tài khoản break-glass khẩn cấp hoặc hệ thống cũ (legacy). AWS khuyến nghị hạn chế tối đa IAM User cá nhân. |
| **IAM User Group** | Tập hợp các IAM User để gán quyền chung, **không phải một principal** (không thể đăng nhập hay assume role). | Không có credentials. | Tiện ích quản lý quyền hàng loạt cho các IAM User. |
| **IAM Role** | Một danh tính có quyền hạn xác định nhưng **không gắn cố định** với một người. Role được "đảm nhận" (assumed) tạm thời bởi một principal hợp lệ. | Ngắn hạn (short-lived): Token tạm thời do AWS Security Token Service (STS) phát hành, tự hết hạn sau một khoảng thời gian (15 phút đến 12 giờ). | **Mô hình chuẩn của AWS:** Cấp quyền cho developer qua IAM Identity Center, gán quyền cho máy ảo EC2, container ECS, hoặc hàm Lambda. |

```text
       ┌──────────────┐
       │   Developer  │ (Con người: Đăng nhập qua Identity Center/SSO)
       └──────┬───────┘
              │ sts:AssumeRole
              ▼
       ┌──────────────┐
       │   IAM Role   │◄─── Gán Permission Policy (ví dụ: Read-Only Order Bucket)
       └──────┬───────┘
              │ Cấp Temporary Credentials (Key, Secret, Token)
              ▼
   ┌──────────────────────┐
   │ AWS S3 / DynamoDB    │ (Thao tác được bảo vệ)
   └──────────────────────┘
```

### Role Session

Khi một chủ thể thực hiện hành động assume role, AWS STS tạo ra một **Role Session**. Session này đi kèm:
- `AccessKeyId` (thường bắt đầu bằng `ASIA...` để chỉ định temporary credentials).
- `SecretAccessKey`.
- `SessionToken` (chuỗi mã hóa chứa thông tin ngữ cảnh và chữ ký của phiên).
- `Expiration` (mốc thời gian ISO8601 mà credentials sẽ bị vô hiệu hóa).

Mọi request ký bằng temporary credentials bắt buộc phải gửi kèm `SessionToken`. Nếu token hết hạn, request kế tiếp sẽ lập tức bị từ chối với mã lỗi `ExpiredToken`.

---

## Phần 2 — Policy: Effect, Action, Resource, Condition và ARN (45 phút)

Mọi quyền hạn trong AWS được biểu diễn dưới dạng tài liệu JSON gọi là **IAM Policy**.

### Cấu trúc JSON chuẩn của Policy

Một policy chứa một hoặc nhiều câu lệnh (`Statement`):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "AllowReadOrdersBucket",
      "Effect": "Allow",
      "Action": [
        "s3:GetObject",
        "s3:ListBucket"
      ],
      "Resource": [
        "arn:aws:s3:::company-orders-lab",
        "arn:aws:s3:::company-orders-lab/read-only/*"
      ],
      "Condition": {
        "Bool": {
          "aws:SecureTransport": "true"
        }
      }
    }
  ]
}
```

Ý nghĩa từng trường:
- **`Version`**: Luôn khai báo `"2012-10-17"`. Đây là phiên bản cú pháp policy hiện hành của AWS. Tuyệt đối không điền ngày tháng hiện tại.
- **`Sid`** *(Statement ID - tùy chọn)*: Chuỗi định danh mô tả mục đích của lệnh, hữu ích khi debug.
- **`Effect`**: Chỉ nhận một trong hai giá trị: `"Allow"` hoặc `"Deny"`.
- **`Action`**: Danh sách một hoặc nhiều API action theo dạng `<service>:<APIAction>`. Ví dụ: `s3:GetObject`, `ec2:DescribeInstances`, `dynamodb:Query`. Hỗ trợ wildcard `*` (ví dụ `s3:Get*`).
- **`Resource`**: Danh sách ARN của tài nguyên mà action tác động lên.
- **`Condition`** *(Tùy chọn)*: Điều kiện bổ sung để lệnh có hiệu lực (ví dụ: bắt buộc HTTPS qua `aws:SecureTransport`, giới hạn IP nguồn, hoặc kiểm tra tag).

### Cú pháp Amazon Resource Name (ARN)

ARN là chuỗi định danh duy nhất toàn cầu cho mọi tài nguyên trên AWS:

```text
arn:partition:service:region:account-id:resource-type/resource-id
arn:partition:service:region:account-id:resource-type:resource-id
```

Ví dụ cụ thể:
- S3 Bucket (global namespace, không có region và account-id):
  `arn:aws:s3:::my-order-bucket`
- S3 Object bên trong prefix:
  `arn:aws:s3:::my-order-bucket/orders/*`
- EC2 Instance ở Singapore (`ap-southeast-1`):
  `arn:aws:ec2:ap-southeast-1:123456789012:instance/i-0a1b2c3d4e5f67890`
- IAM Role (global service, không có region):
  `arn:aws:iam::123456789012:role/OrderServiceAppRole`

> [!WARNING]
> Lưu ý sự khác nhau giữa **Bucket ARN** và **Object ARN** trong S3:
> - Action `s3:ListBucket` tác động lên **Bucket**: `arn:aws:s3:::my-bucket`.
> - Action `s3:GetObject`, `s3:PutObject` tác động lên **Object**: `arn:aws:s3:::my-bucket/*`.
> Viết nhầm `s3:GetObject` trên Bucket ARN là lỗi phổ biến nhất khiến developer bị `AccessDenied`.

---

## Phần 3 — Phân loại Policy và Quy trình đánh giá quyền (40 phút)

### Identity-based Policy, Resource-based Policy và Trust Policy

```text
┌───────────────────────────────────────────────┐
│                   IAM Role                    │
│  ┌─────────────────────────────────────────┐  │
│  │ Trust Policy (AssumeRolePolicyDocument) │  │ "Ai được phép assume tôi?"
│  └─────────────────────────────────────────┘  │
│  ┌─────────────────────────────────────────┐  │
│  │ Identity-based Policy (Permissions)     │  │ "Session của tôi được làm gì?"
│  └─────────────────────────────────────────┘  │
└──────────────────────┬────────────────────────┘
                       │ Gọi API
                       ▼
         ┌───────────────────────────┐
         │     Amazon S3 Bucket      │
         │  ┌─────────────────────┐  │
         │  │ Resource-based      │  │ "Ai từ bên ngoài được chạm vào tôi?"
         │  │ Policy              │  │
         │  └─────────────────────┘  │
         └───────────────────────────┘
```

IAM User và Group chỉ có identity-based policy; **chỉ IAM Role mới có trust policy**.

1. **Identity-based Policy:** Gán trực tiếp cho IAM User, Group hoặc Role. Xác định danh tính đó có quyền làm gì với những tài nguyên nào.
2. **Resource-based Policy:** Gán trực tiếp lên tài nguyên (ví dụ: S3 Bucket Policy, SQS Queue Policy, KMS Key Policy). Xác định **Principal** nào được phép truy cập vào tài nguyên này. Khác với identity-based policy, resource-based policy có trường `Principal`.
3. **Trust Policy (AssumeRolePolicyDocument):** Một loại resource-based policy đặc biệt gắn trên **IAM Role**, quy định rõ **Principal nào (User, AWS Service như ec2.amazonaws.com, hoặc Account khác) được phép gọi `sts:AssumeRole` để nhận credentials của role này**.

### Quy trình đánh giá quyền (Evaluation Logic)

Khi có một request gửi đến, AWS đánh giá tất cả các chính sách áp dụng theo nguyên tắc:

1. **Mặc định là Deny (Default Deny):** Ban đầu, mọi request đều bị từ chối.
2. **Kiểm tra Explicit Deny:** Nếu **bất kỳ** policy nào có `Effect: "Deny"` khớp với context của request, request lập tức bị **TỪ CHỐI**. Không có điều gì có thể override một explicit Deny.
3. **Kiểm tra Explicit Allow:** Nếu không có Deny, AWS tìm xem có ít nhất một `Effect: "Allow"` hợp lệ hay không.
4. **Quyết định cuối cùng:** Nếu có ít nhất một Allow và không có Deny nào, request được chấp thuận. Nếu không tìm thấy Allow nào, request bị từ chối (Default Deny).

```text
                 [ Bắt đầu đánh giá ]
                          │
                          ▼
             Có Explicit Deny nào không?
                    /           \
                 (Có)           (Không)
                  ▼                ▼
             [ TỪ CHỐI ]    Có Explicit Allow nào không?
            (AccessDenied)         /          \
                                (Có)          (Không)
                                 ▼               ▼
                            [ CHẤP THUẬN ]   [ TỪ CHỐI ]
                              (200 OK)      (Default Deny)
```

Sơ đồ trên là mô hình rút gọn cho một account. Logic đầy đủ còn có các lớp **giới hạn** (SCP, RCP, permissions boundary, session policy): request phải được **mọi lớp áp dụng** cho phép, và các lớp này không tự cấp quyền (xem Phần 5). Với request **cross-account**, cần Allow ở cả hai phía: identity policy bên gọi **và** resource policy (hoặc trust policy) bên nhận.

> [!TIP]
> [IAM Policy Simulator](https://policysim.aws.amazon.com/) và `aws iam simulate-principal-policy` giúp dự đoán kết quả nhanh, nhưng không mô phỏng đủ mọi loại policy và condition. Bằng chứng cuối cùng vẫn là gửi request thật (happy path + negative test) như trong lab.

---

## Phần 4 — STS AssumeRole, Temporary Credentials và Identity Center (35 phút)

### AWS Security Token Service (STS)

STS là dịch vụ web cấp phát thông tin đăng nhập tạm thời, có phạm vi giới hạn cho người dùng IAM hoặc người dùng đã xác thực liên kết (Federated Users).

Các API thường gặp:
- `sts:GetCallerIdentity`: Trả về Account ID, User ID và ARN của danh tính hiện tại. Không yêu cầu cấp quyền đặc biệt.
- `sts:AssumeRole`: Nhận vào Role ARN và RoleSessionName, trả về temporary credentials của Role đó (nếu caller thỏa mãn Trust Policy của Role).

### Cơ chế hoạt động của `aws sts assume-role`

```bash
aws sts assume-role \
  --role-arn arn:aws:iam::111122223333:role/LabReadOnlyRole \
  --role-session-name test-session
```

Kết quả trả về:

```json
{
  "Credentials": {
    "AccessKeyId": "ASIAIOSFODNN7EXAMPLE",
    "SecretAccessKey": "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY",
    "SessionToken": "IQoJb3JpZ2luX2VjEPH//////////wEaCXVzLWVhc3QtMS...",
    "Expiration": "2026-10-31T15:30:00+00:00"
  },
  "AssumedRoleUser": {
    "AssumedRoleId": "AROA123456789EXAMPLE:test-session",
    "Arn": "arn:aws:sts::111122223333:assumed-role/LabReadOnlyRole/test-session"
  }
}
```

Sau khi nhận được bộ credentials trên, ứng dụng hoặc terminal gán chúng vào các biến môi trường:
- `AWS_ACCESS_KEY_ID=ASIA...`
- `AWS_SECRET_ACCESS_KEY=...`
- `AWS_SESSION_TOKEN=IQoJ...`

Mọi lệnh CLI sau đó sẽ hoạt động với tư cách là `LabReadOnlyRole` cho đến mốc `Expiration`.

### IAM Identity Center (AWS SSO) và MFA

Trong môi trường doanh nghiệp hiện đại:
- Không tạo từng IAM User với mật khẩu riêng lẻ trong từng account.
- Sử dụng **IAM Identity Center** kết nối với nhà cung cấp danh tính tập trung (Google Workspace, Okta, Microsoft Entra ID).
- Nhân viên kích hoạt **Multi-Factor Authentication (MFA)** bằng FIDO2/WebAuthn (khóa bảo mật YubiKey) hoặc ứng dụng Authenticator (TOTP).
- Mỗi **Permission Set** được Identity Center provision thành một IAM Role (tên dạng `AWSReservedSSO_<PermissionSet>_<hash>`) trong account mục tiêu. Khi developer chọn account/role, Identity Center cấp temporary credentials của role đó.
- Trên máy developer chỉ có SSO token có thời hạn (cache trong `~/.aws/sso/cache`) và temporary credentials; không có access key dài hạn.

---

## Phần 5 — Least Privilege, Permission Boundary và Service Control Policies (SCP) (30 phút)

Khi quản trị hệ thống phức tạp, AWS hỗ trợ nhiều lớp bảo vệ (Defense in Depth):

```text
   SCP (trần của account)          Permissions Boundary (trần của role/user)
        ┌───────────────┐            ┌───────────────┐
        │       ┌───────┼────────────┼───────┐       │
        │       │   ┌───┴────────────┴───┐   │       │
        │       │   │  QUYỀN HIỆU LỰC    │   │       │
        │       │   └───┬────────────┬───┘   │       │
        │       └───────┼────────────┼───────┘       │
        └───────────────┘            └───────────────┘
                    Identity-based Policy (thứ duy nhất CẤP quyền)

Quyền hiệu lực = Identity policy ∩ Boundary ∩ SCP   (và không có explicit Deny nào)
```

Ba lớp này **độc lập**, không lồng nhau: một action có thể nằm trong boundary nhưng bị SCP chặn, hoặc ngược lại. Action chỉ thực hiện được khi nằm trong **giao** của cả ba.

1. **Nguyên tắc quyền tối thiểu (Least Privilege):** Chỉ cấp đúng những API actions trên đúng tài nguyên cần thiết trong khoảng thời gian cần thiết. Tránh dùng wildcard `*` bừa bãi như `s3:*` trên `*`.
2. **Permissions Boundary:** Một cơ chế nâng cao sử dụng managed policy để đặt **mức trần quyền hạn (maximum permissions)** cho một IAM entity. Dù identity-based policy có khai báo `AdministratorAccess`, nếu Boundary không cho phép `s3:DeleteObject`, entity đó vẫn không thể xóa object. Rất hữu ích khi cho phép developer tự tạo IAM Role cho Lambda nhưng không cho phép họ leo thang đặc quyền (privilege escalation).
3. **Service Control Policies (SCP):** Áp dụng ở cấp độ AWS Organizations (tổ chức đa account). SCP thiết lập ranh giới quyền cho toàn bộ account thành viên. SCP được gắn vào Root, OU hoặc từng account trong Organization. SCP **không tự cấp quyền** cho ai, nhưng nếu SCP từ chối một hành động (ví dụ cấm dùng Region ngoài Singapore hoặc cấm xóa CloudTrail trail), thì ngay cả root user của account thành viên cũng không thể thực hiện. Lưu ý: SCP **không áp dụng cho management account**.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 02)

Trong bài thực hành đi kèm, bạn sẽ trực tiếp triển khai một kịch bản phân quyền thực tế:
- **Ngữ cảnh:** Hệ thống đơn hàng (Order Service).
- **Mục tiêu:** Cấu hình một IAM Role có Trust Policy cho phép session hiện tại assume, và đính kèm một IAM Policy chỉ cho phép đọc dữ liệu từ một prefix cố định (`orders/read-only/*`) trong một S3 Bucket fixture.
- **Thực nghiệm kiểm chứng:**
  - Happy path: Đọc dữ liệu từ đúng prefix được cấp phép.
  - Negative test 1: Đọc dữ liệu từ prefix khác (`orders/confidential/*`) -> Nhận `AccessDenied`.
  - Negative test 2: Cố gắng ghi object mới (`s3:PutObject`) -> Nhận `AccessDenied`.
  - Negative test 3: Gửi request với session token bị sửa -> Nhận `InvalidClientTokenId`. Tùy chọn: đợi session 15 phút hết hạn -> Nhận `ExpiredToken`.
- **Dọn dẹp:** Quy trình xóa an toàn role, policy và fixture bucket để giữ môi trường sạch sẽ.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/02-identity-and-access/README.md](../labs/02-identity-and-access/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi thường gặp (25 phút)

### 1. Lỗi `AccessDenied` khi gọi `sts:AssumeRole`
- **Triệu chứng:** `An error occurred (AccessDenied) when calling the AssumeRole operation`.
- **Nguyên nhân 1:** Danh tính hiện tại của bạn không có quyền thực hiện action `sts:AssumeRole` trên tài nguyên Role ARN mục tiêu trong identity policy của bạn.
- **Nguyên nhân 2:** Trust Policy của Role mục tiêu không liệt kê ARN hoặc Account của bạn trong trường `Principal`.
- **Cách khắc phục:** Kiểm tra cả hai chiều: (1) Identity hiện tại có quyền gọi `sts:AssumeRole` không? (2) Role mục tiêu có Trust Policy tin tưởng identity hiện tại không?

### 2. Lỗi `AccessDenied` khi thao tác S3 dù đã gắn quyền
- **Triệu chứng:** Role đã có `s3:GetObject` nhưng lệnh `aws s3 cp` vẫn báo `AccessDenied`.
- **Nguyên nhân 1:** Phân biệt sai giữa Bucket ARN (`arn:aws:s3:::my-bucket`) và Object ARN (`arn:aws:s3:::my-bucket/*`). Action `s3:GetObject` đòi hỏi Object ARN.
- **Nguyên nhân 2:** Trên bucket có **S3 Bucket Policy** chứa Explicit Deny (ví dụ từ chối nếu request không qua HTTPS hoặc từ chối IP ngoài văn phòng).
- **Nguyên nhân 3:** Object được mã hóa bằng AWS KMS (`aws:kms`) và identity chưa có quyền `kms:Decrypt` trên khóa tương ứng.

### 3. Lỗi `ExpiredToken`
- **Triệu chứng:** `The security token included in the request is expired`.
- **Nguyên nhân:** Phiên làm việc tạo bởi STS AssumeRole đã vượt quá `DurationSeconds` đã đăng ký (mặc định 1 giờ).
- **Cách khắc phục:** Chạy lại lệnh `aws sts assume-role` để lấy bộ 3 biến môi trường (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`) mới.

---

## Câu hỏi tự kiểm tra

1. Một lập trình viên đăng nhập thành công vào AWS Console bằng tài khoản công ty. Lập trình viên này có tự động xem được danh sách EC2 instance không? Vì sao?
2. Tại sao một IAM Role cần phải có cả **Trust Policy** lẫn **Permission Policy**?
3. Nếu một IAM Policy cấp `Allow` cho action `s3:GetObject` trên mọi bucket, nhưng S3 Bucket Policy của một bucket cụ thể chứa `Deny` cho tài khoản của bạn, bạn có tải được file từ bucket đó không? Vì sao?
4. Temporary credentials gồm những giá trị nào? Giá trị nào bắt buộc phải có mặt khi gửi request mà credentials cố định không cần?
5. Một Permissions Boundary được gán cho IAM User có thể cấp thêm quyền cho User đó khi chưa có identity-based policy không?
6. Làm sao để chứng minh một IAM policy tuân thủ nguyên tắc Least Privilege (không cấp thừa quyền)?

---

## Tài liệu tham khảo chính thức

- [IAM Identities (users, user groups, and roles)](https://docs.aws.amazon.com/IAM/latest/UserGuide/id.html)
- [Policies and permissions in IAM](https://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies.html)
- [How IAM evaluates policies (Evaluation Logic)](https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_policies_evaluation-logic.html)
- [AWS Security Token Service (STS) Documentation](https://docs.aws.amazon.com/STS/latest/UsingSTS/Welcome.html)
- [AWS IAM Identity Center User Guide](https://docs.aws.amazon.com/singlesignon/latest/userguide/what-is.html)
- [Permissions boundaries for IAM entities](https://docs.aws.amazon.com/IAM/latest/UserGuide/access_policies_boundaries.html)
- CLI reference: [aws sts assume-role](https://docs.aws.amazon.com/cli/latest/reference/sts/assume-role.html) · [aws iam create-role](https://docs.aws.amazon.com/cli/latest/reference/iam/create-role.html) · [aws iam put-role-policy](https://docs.aws.amazon.com/cli/latest/reference/iam/put-role-policy.html)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** policy evaluation logic, cú pháp `2012-10-17`, STS `assume-role` và temporary credentials, ARN format, permissions boundary và SCP. Mô tả evaluation logic trong bài là bản rút gọn; xem link chính thức cho đầy đủ.
- **Chưa chạy thử trên AWS:** các lệnh trong lab chưa được thực thi trên account thật; output là minh họa. Hãy coi output thực tế của bạn là bằng chứng.
- **An toàn:** lab không dùng root user, không tạo long-lived access key, dùng fixture riêng có tag và có cleanup.

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. **Không.** Việc đăng nhập thành công chỉ hoàn tất bước Authentication (chứng minh bạn là ai). Theo cơ chế Default Deny của AWS, nếu không có bất kỳ Identity-based Policy, Group Policy hoặc Permission Set nào cấp `Allow` cho action `ec2:DescribeInstances`, người đó sẽ thấy màn hình trống hoặc thông báo lỗi `UnauthorizedOperation`.
2. **Vì hai câu hỏi bảo mật độc lập:**
   - **Trust Policy** trả lời câu hỏi: *"Chủ thể nào (who) được phép bước vào vai trò này (assume role)?"*
   - **Permission Policy** trả lời câu hỏi: *"Khi đã bước vào vai trò này, danh tính này được phép làm gì (what actions) trên những tài nguyên nào (what resources)?"*
   Thiếu Trust Policy thì không ai assume được; thiếu Permission Policy thì assume vào nhưng không có quyền làm gì cả.
3. **Không tải được.** Theo quy tắc đánh giá quyền của AWS, một **Explicit Deny** (lệnh cấm rõ ràng) luôn luôn ghi đè và vô hiệu hóa mọi lệnh Allow. Do S3 Bucket Policy có Explicit Deny, request sẽ bị từ chối với mã lỗi `403 AccessDenied`.
4. Temporary credentials gồm 4 thành phần: `AccessKeyId`, `SecretAccessKey`, `SessionToken`, và `Expiration`. Trong đó, **`SessionToken`** bắt buộc phải gửi kèm mỗi request (header `X-Amz-Security-Token`; với CLI/SDK là biến `AWS_SESSION_TOKEN`). Thiếu hoặc sai token, AWS không xác thực được chữ ký và trả về `InvalidClientTokenId`; token hết hạn thì trả về `ExpiredToken`. `Expiration` chỉ là thông tin để client biết khi nào cần lấy credentials mới, không gửi kèm request.
5. **Không bao giờ.** Permissions Boundary chỉ đóng vai trò là "mức trần" (maximum permissions boundary) để giới hạn phạm vi quyền hạn lớn nhất có thể có. Boundary **không bao giờ tự cấp quyền (grant permissions)**. Muốn thực hiện được hành động, principal bắt buộc phải có identity-based policy cấp phép và hành động đó phải nằm trong phạm vi cho phép của Boundary.
6. **Bằng hai phương pháp:**
   - **Thiết kế phân rã (Granular Scope):** Giới hạn `Action` cụ thể thay vì wildcard `*` (ví dụ `s3:GetObject` thay vì `s3:*`), giới hạn `Resource` theo ARN chính xác (kèm prefix/path cụ thể thay vì `*`), và thêm `Condition` nếu cần (ví dụ bắt buộc `aws:SecureTransport: true`).
   - **Thực nghiệm kiểm chứng (Positive & Negative Testing):** Chạy happy path kiểm tra đúng quyền thì thành công; đồng thời chạy negative path kiểm tra sai prefix, sai action (ví dụ ghi thay vì đọc), hoặc dùng identity khác thì phải nhận ngay `AccessDenied`.

</details>

