# Bài 00 — Mở đầu: Lộ trình AWS Engineering Lab

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 45–60 phút đọc và chuẩn bị máy. Bài này không có lab và không tạo tài nguyên AWS.
> **Mục lục kế hoạch:** [plans/README.md](../plans/README.md)
> **Bài tiếp theo:** [Bài 01 — Nền tảng AWS](01-foundations.md)

## Bài này giúp bạn làm gì

Sau bài mở đầu, bạn sẽ:

1. Biết toàn bộ lộ trình gồm những chủ đề nào, học theo thứ tự nào và vì sao.
2. Hiểu mỗi chủ đề được tổ chức thế nào: bài lý thuyết, lab, kế hoạch gốc.
3. Biết những quy tắc an toàn áp dụng cho mọi lab, nhất là về credentials và chi phí.
4. Chuẩn bị xong máy và account trước khi vào [Bài 01](01-foundations.md).

Mục tiêu của cả lộ trình không phải là nhớ hết dịch vụ AWS. Học xong, bạn cần làm được bốn việc: **giải thích** được vì sao chọn dịch vụ này thay vì dịch vụ khác, **triển khai** theo cách tái lập được, **chứng minh** hệ thống chạy đúng và hỏng đúng cách khi có lỗi, và **biết** mình đang trả tiền cho cái gì.

---

## Vì sao học theo lộ trình này

Nhiều người học AWS bằng cách mở Console, bấm tạo EC2, rồi cố hiểu vì sao nó không truy cập được. Cách đó nhanh lúc đầu nhưng để lại lỗ hổng: không biết mình đang ở account nào, Region nào, dùng quyền gì, và tốn bao nhiêu tiền.

Lộ trình này đi theo hướng ngược lại:

- **Nền móng trước:** account, Region, CLI, quyền và chi phí được học trước khi tạo bất kỳ máy chủ nào.
- **CLI thay vì bấm chuột:** lệnh CLI ghi lại chính xác bạn đã làm gì, lặp lại được, và dọn dẹp được. Console vẫn được dùng để quan sát.
- **Mỗi lab có lỗi có kiểm soát:** bạn không chỉ thấy hệ thống chạy, mà còn cố tình làm nó hỏng để hiểu cơ chế.
- **Mỗi lab kết thúc bằng cleanup và kiểm kê:** không để lại tài nguyên mồ côi.

---

## Bản đồ lộ trình

14 chủ đề được chia thành năm chặng. Mỗi chặng dựa trên chặng trước.

```text
Chặng 1 — Nền móng            01 Nền tảng ─► 02 IAM ─► 03 Chi phí
                                                │
Chặng 2 — Hạ tầng cốt lõi     04 Mạng ─► 05 EC2/ALB ─► 06 S3/CloudFront ─► 07 Database/Cache
                                                │
Chặng 3 — Mô hình chạy app    08 Container (ECS/Fargate)      09 Serverless (Lambda)
                                                │
Chặng 4 — Vận hành            10 Quan sát & bảo mật ─► 11 Messaging ─► 12 IaC & CI/CD ─► 13 Độ tin cậy
                                                │
Chặng 5 — Quản trị            14 Kiến trúc & quản trị nhiều account
```

| # | Chủ đề | Bạn trả lời được câu hỏi | Thời lượng | Chi phí lab |
|---|---|---|---|---|
| 01 | [Nền tảng AWS](01-foundations.md) · [Lab](../labs/01-foundations/README.md) | Lệnh này chạy ở account nào, Region nào, bằng identity nào? | 3–4 giờ | Không tạo tài nguyên |
| 02 | [IAM, Identity Center, STS](02-identity-and-access.md) · [Lab](../labs/02-identity-and-access/README.md) | Ai được làm gì, trên tài nguyên nào, trong bao lâu? | 4–5 giờ | Gần như bằng 0 |
| 03 | [Budgets, Cost Explorer](03-cost-management.md) · [Lab](../labs/03-cost-management/README.md) | Tiền đi đâu, và làm sao biết sớm khi chi vượt dự kiến? | 2–3 giờ | Gần như bằng 0 |
| 04 | [VPC và mạng](04-networking.md) · [Lab](../labs/04-networking/README.md) | Gói tin đi từ Internet tới app theo đường nào, bị chặn ở đâu? | 5–6 giờ | Không tính phí (lab chính) |
| 05 | [EC2, ALB, Auto Scaling](05-compute-and-load-balancing.md) · [Lab](../labs/05-compute-and-load-balancing/README.md) | Làm sao chạy nhiều máy, tự thay máy hỏng? | 5–7 giờ | **Tính theo giờ** (NAT, ALB, EC2) |
| 06 | [S3, CloudFront, Route 53, ACM](06-storage-and-content-delivery.md) · [Lab](../labs/06-storage-and-content-delivery/README.md) | Lưu file ở đâu, phát nội dung an toàn ra sao? | 5–7 giờ | Thấp |
| 07 | [RDS, Aurora, DynamoDB, ElastiCache](07-databases-and-cache.md) · [Lab](../labs/07-databases-and-cache/README.md) | Chọn database theo access pattern, backup và restore thế nào? | 6–8 giờ | **Tính theo giờ** (RDS) |
| 08 | [ECR, ECS, Fargate](08-containers.md) · [Lab](../labs/08-containers/README.md) | Deploy, rollback và tắt container đúng cách ra sao? | 5–7 giờ | **Tính theo giờ** (NAT, ALB, Fargate) |
| 09 | [Lambda, API Gateway](09-serverless.md) · [Lab](../labs/09-serverless/README.md) | Khi nào serverless rẻ hơn, khi nào đắt hơn, giới hạn ở đâu? | 5–7 giờ | Theo request, gần như bằng 0 |
| 10 | [CloudWatch, CloudTrail, Secrets, KMS](10-observability-and-application-security.md) · [Lab](../labs/10-observability-and-application-security/README.md) | Hệ thống đang khỏe không, ai đã làm gì, bí mật cất ở đâu? | 6–8 giờ | Thấp |
| 11 | [SQS, SNS, EventBridge, Step Functions](11-messaging-and-workflows.md) · [Lab](../labs/11-messaging-and-workflows/README.md) | Xử lý bất đồng bộ, message lỗi và retry ra sao? | 6–8 giờ | Thấp |
| 12 | [CloudFormation, Terraform, GitHub OIDC](12-iac-and-cicd.md) · [Lab](../labs/12-iac-and-cicd/README.md) | Làm sao hạ tầng tái lập được và deploy không cần access key? | 6–8 giờ | Thấp |
| 13 | [SLO, load test, backup, DR](13-reliability-and-performance.md) · [Lab](../labs/13-reliability-and-performance/README.md) | Hệ thống tin cậy đến mức nào, phục hồi mất bao lâu? | 6–10 giờ | Thấp, có trần |
| 14 | [Organizations, SCP, Control Tower, WAF](14-architecture-and-governance.md) · [Lab](../labs/14-architecture-and-governance/README.md) | Quản trị nhiều account và ghi lại quyết định kiến trúc ra sao? | 5–7 giờ | Phần lớn là thiết kế; nhánh WAF tính theo giờ |

Tổng cộng khoảng **70–100 giờ**. Cột "Chi phí lab" chỉ mang tính định hướng; mỗi lab có bảng ước tính riêng với giá minh họa, và bạn phải tra lại giá cho Region của mình.

### Thứ tự học và các nhánh

- **01 → 02 → 03 là bắt buộc và theo đúng thứ tự.** Đừng bỏ qua 03: phải đặt cảnh báo ngân sách trước khi tạo tài nguyên tính tiền theo giờ ở bài 05.
- **04 → 05 là bắt buộc.** Từ 05 trở đi các lab dùng VPC có public/private subnet.
- **06 và 07** có thể học theo thứ tự bất kỳ sau 05.
- **08 và 09** là hai cách chạy ứng dụng song song. Nên học cả hai; nếu thiếu thời gian, chọn một rồi quay lại sau.
- **10** dùng một API từ 05, 08 hoặc 09, nên chỉ cần đã học ít nhất một trong ba bài đó.
- **11–13** dùng kiến thức của các bài trước, nhưng mỗi lab tự dựng fixture riêng.
- **14** nên học cuối vì nó tổng hợp mọi thứ ở góc độ tổ chức.

Logging, IAM, kiểm soát chi phí và IaC không đợi tới bài riêng mới dùng. Chúng xuất hiện dần từ các lab đầu, rồi được học sâu ở bài tương ứng.

### Ví dụ xuyên suốt

Khi phù hợp, các bài dùng chung một ví dụ: **hệ thống đơn hàng nhỏ**. Nó lớn dần theo lộ trình:

1. Bài 04–05: API đơn hàng chạy trên EC2 trong private subnet, sau ALB.
2. Bài 06–07: ảnh sản phẩm trên S3/CloudFront, đơn hàng lưu trong DynamoDB hoặc RDS.
3. Bài 08–09: cùng API đó chạy bằng container trên Fargate, hoặc bằng Lambda.
4. Bài 10–11: có log, metric, alarm; xử lý đơn bất đồng bộ qua hàng đợi và workflow.
5. Bài 12–14: toàn bộ được viết bằng IaC, deploy qua CI/CD, đo SLO và đặt trong mô hình nhiều account.

Ví dụ chỉ giúp liên kết các bài lại với nhau. Mỗi lab vẫn có thể làm độc lập; nếu dùng lại tài nguyên từ lab trước, lab sẽ nói rõ.

---

## Cách mỗi chủ đề được tổ chức

Mỗi chủ đề có ba file:

| File | Vai trò | Khi nào đọc |
|---|---|---|
| `notes/NN-*.md` | Bài lý thuyết: khái niệm, sơ đồ, đánh đổi, câu hỏi tự kiểm tra | Buổi 1 |
| `labs/NN-*/README.md` | Lab từng bước: lệnh, output minh họa, lỗi có kiểm soát, troubleshooting, cleanup | Buổi 2 |
| `plans/NN-*.md` | Kế hoạch gốc dùng để biên soạn hai file trên | Chỉ khi muốn biết phạm vi và tiêu chí của bài |

Mỗi file `.md` có bản `.html` cùng tên để đọc trên trình duyệt, với mục lục và nút copy lệnh. Bản HTML được sinh tự động:

```bash
npm install
npm run build:html
```

Chỉ sửa file Markdown, không sửa trực tiếp file HTML.

### Nhịp học gợi ý cho mỗi chủ đề

1. **Buổi 1 — lý thuyết:** đọc phần 1–4 hoặc 1–5 của bài. Trả lời câu hỏi "Tự kiểm tra" trước khi xem đáp án.
2. **Buổi 2 — lab:** đọc lại phần còn lại, làm lab, ghi bằng chứng vào sổ tay của bạn.
3. **Kết thúc:** chạy cleanup, kiểm kê tài nguyên còn lại, ghi lại một lỗi bạn đã tự chẩn đoán.

Với nhịp hai buổi mỗi tuần, cả lộ trình mất khoảng 7–10 tuần.

### Cách đọc một lab

Các lab dùng chung những quy ước sau:

- **Placeholder** như `<REGION>`, `<ACCOUNT_ID>`, `<ROLE_NAME>` được giải thích trong bảng ở đầu lab. Thay bằng giá trị của bạn.
- **Profile CLI** luôn là `aws-lab`, được tạo ở Lab 01.
- **Tag:** từ Lab 04, tài nguyên do lab tạo được gắn tag `Project=aws-lab-NN` (NN là số bài). Tag giúp tìm và dọn tài nguyên, và tách tài nguyên của lab này khỏi lab khác.
- **Output trong bài là minh họa**, không phải kết quả chạy thật. Kết quả của bạn sẽ khác ở ID, thời gian và số đo. Mỗi bài có mục "Trạng thái kiểm chứng" ghi rõ phần nào đã đối chiếu tài liệu, phần nào đã chạy thử.
- **"Lab này làm gì và không làm gì"** ở đầu mỗi lab cho biết phạm vi. Đọc kỹ phần này trước khi chạy lệnh.

---

## Quy tắc an toàn cho mọi lab

Những quy tắc này áp dụng từ Bài 01 tới Bài 14. Chúng được giải thích kỹ ở các bài 01–03; ở đây chỉ liệt kê để bạn biết trước.

### Account

- Dùng **account lab hoặc sandbox riêng**. Không bao giờ làm lab trên account production hoặc account có dữ liệu thật.
- **Không dùng root user** cho công việc hằng ngày. Root chỉ dùng cho vài thao tác quản trị account, và phải bật MFA.
- Không cần `AdministratorAccess` để học. Mỗi lab liệt kê quyền tối thiểu cần có.

### Credentials

- Ưu tiên **temporary credentials** qua IAM Identity Center (SSO) hoặc cơ chế của sandbox. Không tạo long-lived access key làm cách mặc định.
- **Không commit** credentials, session token, file `~/.aws/config` hay `~/.aws/credentials` vào repository.
- Khi chia sẻ kết quả (blog, chat, issue), **che account ID, ARN, start URL và mọi định danh**.

### Chi phí

- AWS tính tiền theo mức dùng. Một số tài nguyên **tính theo giờ dù không có ai dùng**: NAT Gateway, load balancer, RDS, EC2, Fargate task đang chạy.
- **Đặt cảnh báo ngân sách ở Bài 03**, trước khi làm Lab 05. Cảnh báo ngân sách chỉ báo cho bạn biết, nó **không chặn chi tiêu**.
- Không giả định Free Tier sẽ bao hết chi phí. Điều kiện Free Tier thay đổi theo thời gian và theo account.
- **Làm lab có tài nguyên tính theo giờ trong một lần ngồi.** Nếu phải dừng giữa chừng, chạy cleanup trước rồi làm lại sau.
- Sau mỗi lab, chạy phần **cleanup** và **kiểm kê** ở cuối lab. Kiểm tra trong đúng Region bạn đã dùng.

### Trước mỗi lệnh thay đổi hạ tầng

Hãy tự hỏi ba câu, và chạy lệnh để xác nhận thay vì đoán:

```bash
aws sts get-caller-identity --profile aws-lab   # Đúng account và role chưa?
aws configure get region --profile aws-lab      # Đúng Region chưa?
```

Câu hỏi thứ ba: tài nguyên này có tính tiền theo giờ không, và bạn sẽ xóa nó lúc nào?

---

## Chuẩn bị trước khi vào Bài 01

### Kiến thức nền

Lộ trình giả định bạn đã:

- Dùng terminal hằng ngày (bash/zsh), biết biến môi trường và pipe.
- Hiểu HTTP, REST API, JSON và mô hình client/server.
- Biết Git cơ bản và từng chạy một ứng dụng web.
- Biết sơ qua về Docker (cần cho Bài 08) và mạng cơ bản như IP, port, DNS (Bài 04 sẽ giải thích phần riêng của AWS).

Không cần biết trước bất kỳ dịch vụ AWS nào.

### Công cụ trên máy

Hướng dẫn trong các lab viết cho macOS/zsh. Linux dùng được gần như nguyên vẹn; Windows nên dùng WSL.

| Công cụ | Dùng từ bài | Ghi chú |
|---|---|---|
| Terminal (zsh/bash) | 01 | |
| [AWS CLI v2](https://docs.aws.amazon.com/cli/latest/userguide/getting-started-install.html) | 01 | Cài và cấu hình chi tiết ở Bài 01 |
| `git` | 01 | |
| `jq` | 01 | Đọc và lọc JSON; macOS: `brew install jq` |
| `curl` | 04 | Thường có sẵn |
| Python 3 | 03 | Script phụ trợ nhỏ trong nhiều lab |
| Node.js | 05 | Ứng dụng mẫu trong lab 05, 07, 08; cũng dùng để build HTML |
| Docker | 08 | Build và push image |
| `psql` | 07 | PostgreSQL client |
| Terraform | 12 | Chỉ cho phần so sánh với CloudFormation |
| Tài khoản GitHub | 12 | Chỉ cho nhánh chạy GitHub Actions thật |

Không cần cài hết ngay. Cài AWS CLI, `git` và `jq` trước, phần còn lại cài khi tới bài cần.

Kiểm tra nhanh:

```bash
git --version
jq --version
python3 --version
aws --version   # Nếu chưa có, Bài 01 hướng dẫn cài
```

### Account AWS

Chọn một trong ba trường hợp:

1. **Công ty hoặc nhóm đã có IAM Identity Center:** xin quản trị viên một account lab và một permission set. Ghi lại start URL, SSO Region, account ID và tên permission set. Đây là trường hợp lý tưởng.
2. **Có sandbox do nền tảng học tập cấp:** dùng temporary credentials theo hướng dẫn của sandbox. Lưu ý sandbox có thể hạn chế một số dịch vụ hoặc tự xóa tài nguyên sau một thời gian.
3. **Tự tạo account cá nhân:** bật MFA cho root ngay, sau đó thiết lập truy cập không dùng root. Bài 01–02 hướng dẫn phần này. Bạn tự chịu chi phí, nên Bài 03 càng quan trọng.

Bài 01 sẽ hướng dẫn cấu hình CLI cho cả ba trường hợp.

### Sổ tay học tập

Tạo một thư mục hoặc file ghi chép riêng, **ngoài repository này** hoặc trong một thư mục đã được `.gitignore`. Với mỗi lab, ghi lại:

- Region đã dùng và lý do chọn.
- Lệnh quan trọng và output đã che định danh.
- Một lỗi bạn gặp, cách chẩn đoán và cách sửa.
- Kết quả kiểm kê sau cleanup.

Sổ tay này sẽ trở thành runbook cá nhân của bạn.

---

## Checklist sẵn sàng

Trước khi chuyển sang Bài 01, hãy chắc chắn:

- [ ] Tôi biết lộ trình gồm năm chặng và thứ tự bắt buộc của 01 → 02 → 03 → 04 → 05.
- [ ] Tôi biết mỗi chủ đề gồm bài lý thuyết và lab, và output trong bài chỉ là minh họa.
- [ ] Tôi có, hoặc biết cách xin, một account lab/sandbox không phải production.
- [ ] Tôi sẽ không dùng root user và không commit credentials.
- [ ] Tôi hiểu một số tài nguyên tính tiền theo giờ, và sẽ đặt cảnh báo ngân sách ở Bài 03 trước khi làm Lab 05.
- [ ] Máy tôi đã có terminal, `git` và `jq`.
- [ ] Tôi đã chuẩn bị sổ tay ghi chép nằm ngoài repository.

## Tự kiểm tra

1. Vì sao Bài 03 (chi phí) phải học trước Bài 05?
2. Cảnh báo ngân sách có ngăn AWS tính tiền khi bạn quên xóa NAT Gateway không?
3. Output trong lab khác output trên máy bạn. Điều đó có nghĩa là bạn làm sai không?
4. Bạn cần chạy lệnh gì trước một lệnh tạo tài nguyên để biết chắc mình đang ở đúng account và Region?
5. Bạn chỉ có thời gian cho một trong hai bài 08 và 09. Bài 10 có bị ảnh hưởng không?

<details>
<summary>Đáp án gợi ý</summary>

1. Từ Bài 05 bắt đầu có tài nguyên tính tiền theo giờ (NAT Gateway, ALB, EC2). Cần có cảnh báo ngân sách và hiểu cách đọc chi phí trước khi tạo chúng.
2. Không. Budgets chỉ gửi cảnh báo khi chi phí thực tế hoặc dự báo vượt ngưỡng; nó không phải trần chi tiêu. Chỉ cleanup mới dừng chi phí.
3. Không nhất thiết. ID, thời gian, số đo và thứ tự kết quả thường khác. Hãy so cấu trúc output và tiêu chí đạt của từng bước, không so từng ký tự.
4. `aws sts get-caller-identity --profile aws-lab` để xem account và identity, và kiểm tra Region bằng `aws configure get region --profile aws-lab` hoặc truyền `--region` rõ ràng. Bài 01 giải thích thêm về thứ tự ưu tiên cấu hình.
5. Không. Bài 10 chỉ cần một API từ bài 05, 08 hoặc 09.

</details>

---

## Tiếp theo

Bài 01 bắt đầu từ câu hỏi nền tảng nhất: khi bạn gõ một lệnh AWS, lệnh đó đi đâu, chạy trong account nào, ở Region nào, và bằng identity nào? Bạn sẽ cài AWS CLI v2, tạo profile `aws-lab` và làm lab chỉ đọc đầu tiên mà không tạo tài nguyên tính phí nào.

👉 [Bài 01 — Nền tảng AWS: Region, Availability Zone và AWS CLI](01-foundations.md)
