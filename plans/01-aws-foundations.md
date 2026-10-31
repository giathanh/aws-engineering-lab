# Plan: Nền tảng AWS — Region, Availability Zone, AWS CLI

## 1. Mục đích và đầu ra

Đây là bản kế hoạch để một mô hình khác biên soạn bài hướng dẫn tiếng Việt. Không triển khai lab, tạo tài nguyên AWS hay viết bài hướng dẫn hoàn chỉnh trong bước lập kế hoạch.

- Đối tượng: senior developer có kinh nghiệm phát triển phần mềm nhưng mới học AWS.
- Điều kiện kiến thức: biết terminal, HTTP/API và khái niệm client/server; không giả định đã biết IAM, VPC hay EC2.
- Thời lượng gợi ý: 3–4 giờ, chia thành hai buổi; không tính thời gian tạo tài khoản hoặc chờ cấp quyền.
- Đầu ra dự kiến của bước biên soạn tiếp theo: bài học `notes/01-foundations.md` và lab `labs/01-foundations/README.md`.
- Ngôn ngữ: tiếng Việt; giữ tên dịch vụ và thuật ngữ tiếng Anh, giải thích ở lần xuất hiện đầu tiên.

## 2. Mục tiêu học tập đo được

Sau bài học, người học có thể:

1. Giải thích quan hệ AWS account → Region → Availability Zone (AZ), đồng thời hiểu account không phải một địa điểm vật lý.
2. Phân biệt Region, AZ, data center và edge location; giải thích vì sao một AZ có thể gồm nhiều data center.
3. Phân biệt phạm vi global, regional và zonal của tài nguyên bằng ví dụ cụ thể.
4. Chọn Region học tập bằng tiêu chí rõ ràng, thay vì mặc định chọn Region gần nhất.
5. Phân biệt high availability trong nhiều AZ với disaster recovery qua nhiều Region.
6. Cài AWS CLI v2, cấu hình named profile và xác thực bằng temporary credentials.
7. Kiểm tra account/identity, cấu hình hiệu lực và Region trước khi chạy lệnh.
8. Dùng CLI đọc danh sách Region/AZ, lọc kết quả và xử lý các lỗi cấu hình phổ biến.

## 3. Phạm vi và giới hạn

### Bao gồm

- Cloud cơ bản: dịch vụ qua API, tài nguyên được cấp phát theo nhu cầu, chi phí theo mức sử dụng.
- Shared responsibility ở mức định hướng; một ví dụ trách nhiệm AWS và khách hàng.
- Account, Region, AZ, edge location, endpoint và phạm vi tài nguyên.
- Console, CLI, SDK: cùng tương tác với AWS API nhưng khác cách sử dụng.
- AWS CLI v2: cài đặt, authentication, profile, Region, output, help, query, lỗi thường gặp.
- Nhận thức chi phí và vệ sinh credentials đủ để bắt đầu học.

### Để dành cho bài sau

- Viết IAM policy chi tiết; quản trị Organizations và thiết kế multi-account.
- VPC, EC2, S3, RDS, load balancer và triển khai ứng dụng.
- Terraform/CDK/CloudFormation, CI/CD và thiết kế disaster recovery hoàn chỉnh.
- Không yêu cầu tạo EC2, S3 bucket, VPC hoặc bật Region mới để hoàn thành bài.

## 4. Dàn ý bài hướng dẫn cần biên soạn

| Phần | Nội dung cần phát triển | Cách kiểm tra hiểu bài | Thời lượng |
|---|---|---|---|
| 1. Mô hình AWS | Account, API, Console/CLI/SDK, shared responsibility | Người học diễn giải luồng CLI → API → tài nguyên | 15 phút |
| 2. Hạ tầng toàn cầu | Region, AZ, data center, edge location; ranh giới lỗi | Sơ đồ một Region có nhiều AZ; phân biệt edge với AZ | 30 phút |
| 3. Phạm vi và chọn Region | Global/regional/zonal; Region code, AZ name và AZ ID; latency, dịch vụ, chi phí, dữ liệu và DR | Chọn Region cho tình huống giả định và nêu đánh đổi | 25 phút |
| 4. Chuẩn bị truy cập | Tài khoản lab, MFA, quyền đọc tối thiểu, temporary credentials, prerequisite của SSO | Xác định ai cấp thông tin đăng nhập và cần dữ liệu gì | 20 phút |
| 5. CLI v2 và cấu hình | Cài đặt, named profile, authentication, config/credentials, Region và precedence | Xác định profile/identity/Region đang có hiệu lực | 35 phút |
| 6. Lab chỉ đọc | Kiểm tra identity; liệt kê Region/AZ; output và query; đổi Region theo lệnh | Thu thập bằng chứng đã che thông tin định danh | 45 phút |
| 7. Troubleshooting và tổng kết | Lỗi credentials, session, quyền, Region, endpoint; kết thúc phiên | Chẩn đoán tình huống và hoàn thành checklist | 25 phút |

## 5. Yêu cầu chính xác về nội dung

- Không ghi cứng số lượng Region/AZ hoặc giá dịch vụ vì có thể thay đổi.
- Phân biệt Region đang được chọn trên Console với Region CLI sử dụng; hai cấu hình không tự đồng bộ.
- Phân biệt Region của IAM Identity Center (`sso_region`) và Region mặc định cho lệnh dịch vụ.
- Nêu AZ name có thể ánh xạ khác giữa các account; AZ ID dùng để xác định cùng một AZ giữa các account.
- Dùng IAM làm ví dụ global, VPC làm ví dụ regional và subnet làm ví dụ zonal; chỉ giới thiệu để minh họa phạm vi.
- Không mô tả mọi dịch vụ có cùng phạm vi hoặc có mặt ở mọi Region.
- Nhiều AZ không tự làm ứng dụng high availability; ứng dụng phải được triển khai và cấu hình phù hợp.
- Multi-Region không phải điều kiện bắt buộc cho mọi hệ thống.
- Bảng precedence phải kiểm chứng theo tài liệu CLI hiện hành; tách cấu hình Region/output khỏi cơ chế chọn credentials, tránh khẳng định một thứ tự đơn giản áp dụng cho mọi provider.
- Cảnh báo ngân sách không phải trần chi tiêu. Không khẳng định toàn bộ hoạt động trong AWS Console hoặc CLI đều miễn phí.

## 6. Kế hoạch lab chỉ đọc

### Điều kiện đầu vào

- Máy có internet và terminal; hướng dẫn chính cho macOS/zsh, liên kết cài đặt chính thức cho Linux/Windows.
- AWS CLI v2 được cài từ nguồn chính thức và kiểm tra phiên bản.
- Có account lab hoặc sandbox được phép sử dụng; không mặc định dùng account production.
- Có đường xác thực hợp lệ và quyền đọc `ec2:DescribeRegions`, `ec2:DescribeAvailabilityZones`.
- Giải thích `sts:GetCallerIdentity` không cần được cấp Allow riêng theo tài liệu API; không vì lab này mà yêu cầu AdministratorAccess.

### Nhánh xác thực cần mô hình biên soạn xử lý

1. **Nhánh chính:** IAM Identity Center đã được thiết lập, có start/issuer URL, SSO Region, account và permission set. Hướng dẫn cấu hình profile, login, kiểm tra và logout.
2. **Chưa có Identity Center:** đánh dấu prerequisite chưa đáp ứng; dẫn tài liệu thiết lập hoặc yêu cầu quản trị viên cấp truy cập. Việc thiết lập Identity Center là bước tùy chọn riêng, không âm thầm giả định đã có.
3. **Sandbox cung cấp temporary credentials:** mô tả cách dùng theo cơ chế sandbox, có session token và thời hạn; không ghi credentials vào repository.

Không dùng root access key hoặc yêu cầu tạo long-lived access key làm đường mặc định.

### Các bước lab cần viết sau

| Bước | Thao tác dự kiến | Bằng chứng và tiêu chí đạt |
|---|---|---|
| 1 | Kiểm tra CLI v2 và cú pháp help | Nhận diện được phiên bản; biết tìm trợ giúp cho một command |
| 2 | Tạo/chọn profile `aws-lab`, đăng nhập | Profile tồn tại; không lưu thông tin nhạy cảm trong repo |
| 3 | Chạy `sts get-caller-identity` | Xác định đúng account và role; che account ID/ARN trong ghi chép công khai |
| 4 | Kiểm tra cấu hình qua `configure list` và danh sách profile | Nêu được nguồn cấu hình; không in nội dung file credentials |
| 5 | Chạy `ec2 describe-regions` | Giải thích phạm vi danh sách trả về và Region opt-in; không bật Region |
| 6 | Chạy `ec2 describe-availability-zones` cho Region đã chọn | Đọc được ZoneName, ZoneId, State; lọc availability-zone để không lẫn loại zone khác |
| 7 | Chạy cùng truy vấn với Region khác đã được enable | Giải thích vì sao kết quả AZ khác; dùng `--region`, không thay cấu hình toàn cục |
| 8 | Thực hành `--output json/table`, `--query`, `--profile`, `--region` | Có ví dụ query đơn giản và giải thích đây là lọc phía client |
| 9 | Kết thúc phiên và ghi lại kết quả | Logout phù hợp với cơ chế xác thực; ghi nhận không có tài nguyên dịch vụ được tạo |

Mỗi bước trong bài hướng dẫn sau phải có mục đích, lệnh, placeholder rõ nghĩa, output minh họa đã ẩn định danh và cách xác minh. Output không được giả vờ là kết quả thực thi thật.

## 7. Troubleshooting cần có

| Triệu chứng | Hướng chẩn đoán mong đợi |
|---|---|
| `aws: command not found` | Cài đặt, PATH và mở lại terminal |
| Không tìm thấy profile | Tên profile, vị trí file cấu hình, biến môi trường liên quan |
| `Unable to locate credentials` | Chưa cấu hình hoặc chưa chọn đúng cơ chế xác thực |
| Phiên SSO/token hết hạn | Đăng nhập lại; kiểm tra profile và thời hạn phiên |
| `AccessDenied` / `UnauthorizedOperation` | Xác định API bị từ chối và quyền cần có; không giải quyết bằng cấp admin |
| Thiếu/sai Region hoặc lỗi endpoint | Kiểm tra mã Region, cấu hình hiệu lực, mạng/proxy và Region đã enable |
| Trả về sai account/identity | Kiểm tra profile và credentials từ môi trường có thể ảnh hưởng |
| Output bị giữ trong pager | Giải thích pager và tùy chọn tắt cho lệnh minh họa |

## 8. Câu hỏi và bài tập đánh giá

- Một Region và một AZ khác nhau ở ranh giới lỗi nào?
- Hai instance cùng một AZ có bảo vệ được trước sự cố toàn AZ không?
- Vì sao đổi Region trên Console không làm CLI đổi Region?
- Vì sao cùng AZ name ở hai account chưa chắc là cùng vị trí vật lý?
- Với người dùng chủ yếu ở Việt Nam, cần kiểm tra gì trước khi chọn Region? Không yêu cầu một đáp án Region cố định.
- Khi SSO Region khác Region triển khai, có phải cấu hình sai không?
- Làm thế nào biết lệnh sắp chạy đang dùng account, identity và Region nào?
- Nếu không thấy tài nguyên mong đợi, cần kiểm tra account, Region, quyền và bộ lọc theo cách nào?

Bài tập cuối: viết một ghi chú ngắn gồm Region đã chọn và lý do, sơ đồ account/Region/AZ, lệnh kiểm tra identity, bảng AZ đã ẩn định danh cần thiết và một lỗi đã tự chẩn đoán. Mô hình biên soạn cần cung cấp đáp án gợi ý tách khỏi câu hỏi.

## 9. Tiêu chí nghiệm thu bài hướng dẫn sau này

- [ ] Đạt đủ tám mục tiêu học tập, có sơ đồ và ví dụ thực tế.
- [ ] Không giả định người học đã cấu hình SSO hoặc có quyền admin.
- [ ] Lệnh chính đã đối chiếu CLI v2; các lệnh lab chỉ đọc dữ liệu và quản lý phiên/cấu hình cục bộ.
- [ ] Không tạo tài nguyên tính phí, bật Region hay thay đổi hạ tầng để hoàn thành lab.
- [ ] Có output minh họa, tiêu chí thành công, troubleshooting và phần tự kiểm tra.
- [ ] Không có credentials, session token, file cấu hình thật hoặc thông tin tài khoản thật trong repo.
- [ ] Có liên kết tài liệu chính thức và ngày kiểm chứng ở bài hướng dẫn.
- [ ] Nếu không chạy được lab thực tế, ghi rõ phần nào chỉ được đối chiếu tài liệu; không tuyên bố đã kiểm thử.
- [ ] Kết thúc bằng cầu nối sang bài IAM: phân biệt đã xác thực thành công với được phép thực hiện hành động.

## 10. Tài liệu nguồn cho mô hình biên soạn

Ưu tiên tài liệu AWS chính thức; kiểm tra lại tại thời điểm viết bài, đặc biệt authentication, cài đặt CLI và precedence.

- [AWS Regions](https://docs.aws.amazon.com/global-infrastructure/latest/regions/aws-regions.html)
- [Regions and Availability Zones — Amazon EC2](https://docs.aws.amazon.com/AWSEC2/latest/UserGuide/using-regions-availability-zones.html)
- [Shared Responsibility Model](https://aws.amazon.com/compliance/shared-responsibility-model/)
- [Cài đặt AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html)
- [Thiết lập AWS CLI](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-quickstart.html)
- [Cấu hình IAM Identity Center cho CLI](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-sso.html)
- [Authentication và cấu hình CLI](https://docs.aws.amazon.com/cli/latest/userguide/cli-chap-configure.html)
- [Configuration và credential files](https://docs.aws.amazon.com/cli/latest/userguide/cli-configure-files.html)
- [CLI: get-caller-identity](https://docs.aws.amazon.com/cli/latest/reference/sts/get-caller-identity.html)
- [CLI: describe-regions](https://docs.aws.amazon.com/cli/latest/reference/ec2/describe-regions.html)
- [CLI: describe-availability-zones](https://docs.aws.amazon.com/cli/latest/reference/ec2/describe-availability-zones.html)

## 11. Chỉ dẫn bàn giao cho mô hình tiếp theo

> Dựa trên plan này, hãy biên soạn bài hướng dẫn tiếng Việt và lab chỉ đọc cho chủ đề Nền tảng AWS. Giữ đúng phạm vi, kiểm chứng tài liệu AWS hiện hành và làm rõ prerequisite xác thực. Viết cho developer có kinh nghiệm phần mềm nhưng mới học AWS. Tạo hai đầu ra theo mục 1, liên kết qua lại và cung cấp đáp án tự kiểm tra. Không tự thực hiện thao tác trên AWS account. Không mở rộng sang triển khai EC2/VPC/S3 hoặc hướng dẫn IAM chuyên sâu. Phân biệt rõ ví dụ minh họa, nội dung đã đối chiếu tài liệu và lệnh đã thực sự kiểm thử.
