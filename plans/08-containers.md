# Plan: Container — ECR, ECS, Fargate

## 1. Mục đích và đầu ra

Đây là kế hoạch cho mô hình khác biên soạn bài hướng dẫn, không phải bài học hoàn chỉnh. Bước lập plan không tạo tài nguyên, chạy lab hay deploy AWS.

- Đối tượng: senior developer mới học AWS; giải thích thuật ngữ ở lần xuất hiện đầu tiên.
- Ngôn ngữ: tiếng Việt; giữ tên dịch vụ/API tiếng Anh.
- Yêu cầu đầu vào: chủ đề 01–05, 07 phần RDS; Docker trong lộ trình; kiểm tra điều kiện thực tế trước khi bắt đầu.
- Thời lượng gợi ý: 5–7 giờ, có thể chia buổi; không tính thời gian cấp quyền và chờ provisioning.
- Đầu ra khi biên soạn: `notes/08-containers.md` và `labs/08-containers/README.md`, liên kết qua lại.
- Dùng hệ thống đơn hàng nhỏ làm ví dụ khi phù hợp; mỗi lab phải có fixture độc lập hoặc chỉ rõ tài nguyên kế thừa từ bài trước.

## 2. Mục tiêu học tập đo được

1. Giải thích và áp dụng: eCR repository, image tag/digest và lifecycle.
2. Giải thích và áp dụng: eCS cluster, task definition, task, service và Fargate.
3. Giải thích và áp dụng: task role và execution role; secret và logs.
4. Giải thích và áp dụng: awsvpc, subnet, SG, ALB target type ip.
5. Giải thích và áp dụng: deployment, health checks, autoscaling và graceful shutdown.
6. Chứng minh hành vi đúng và một tình huống lỗi bằng bằng chứng; giải thích đánh đổi chi phí, bảo mật và vận hành.

## 3. Phạm vi và giới hạn

Các nội dung chính nằm trong dàn ý bên dưới. Chỉ giới thiệu dịch vụ ngoài phạm vi khi cần so sánh hoặc làm fixture; không mở rộng thành hướng dẫn đầy đủ cho mọi dịch vụ liên quan.

- Không dùng production hoặc dữ liệu thật; không mặc định người học có AdministratorAccess.
- Không tự tạo account, mua domain, bật organization hoặc thay đổi tài nguyên dùng chung.
- Nhánh tùy chọn phải tách rõ khỏi lab chính để người học vẫn hoàn thành mục tiêu khi thiếu budget hoặc quyền.
- IaC có thể dùng từ đầu, nhưng phải giải thích tài nguyên được tạo; không che kiến thức bằng một template không được diễn giải.

## 4. Dàn ý bài hướng dẫn cần biên soạn

| Phần | Nội dung | Bằng chứng hiểu bài |
|---|---|---|
| 1 | ECR repository, image tag/digest và lifecycle | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 2 | ECS cluster, task definition, task, service và Fargate | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 3 | Task role và execution role; secret và logs | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 4 | awsvpc, subnet, SG, ALB target type ip | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 5 | Deployment, health checks, autoscaling và graceful shutdown | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 6 | Lab, lỗi có kiểm soát và cleanup | Có expected result, negative case và inventory sau cleanup |

Trước lab, cung cấp sơ đồ, bảng tài nguyên, quyền cần thiết và ước tính chi phí. Sau lab, liên hệ kết quả với hành vi production và chỉ rõ giới hạn của mô hình đơn giản.

## 5. Yêu cầu chính xác và điểm dễ hiểu sai

- Execution role cho agent pull image/log/secret injection; task role cho ứng dụng gọi AWS
- Fargate bỏ quản lý host nhưng vẫn cần network, IAM và resource sizing
- Rolling deployment không đồng nghĩa không mất request; cần draining và shutdown phù hợp
- Không bắt buộc học EKS để hoàn thành bài

## 6. Kế hoạch thực hành

### Chuẩn bị

- Kiểm tra account/role bằng STS, profile và Region; dùng temporary credentials.
- Liệt kê quyền control plane, data plane và `iam:PassRole` nếu cần, giới hạn theo tài nguyên; không thay bằng yêu cầu admin chung.
- Kiểm tra quota, phiên bản công cụ, dịch vụ khả dụng trong Region và tài nguyên prerequisite.
- Đặt tên/tag riêng cho fixture; ghi ownership để không xóa tài nguyên bài khác.
- Với phần có tính phí: ước tính theo Region và thời gian chạy, ghi nguồn giá/ngày kiểm chứng, trần quy mô và điều kiện dừng. Không hứa Free Tier.

### Trình tự cần triển khai trong bài hướng dẫn sau

1. Build API mẫu, pin image theo digest và push ECR; không bake secret vào image.
2. Tạo ECS service Fargate sau ALB, task ở private subnet với outbound/endpoint được giải thích.
3. Deploy version mới, kiểm tra health và rollback khi version mới không healthy.
4. Gửi SIGTERM/scale-in trong fixture để kiểm tra graceful shutdown; giới hạn desired/max tasks.
5. Xóa service/task, ALB, image/repo lab, log group và mạng chỉ do lab sở hữu.

Mỗi bước cần mục đích, thao tác/lệnh, placeholder, expected output, cách xác minh và cách khắc phục khi khác kỳ vọng. Negative test phải có kết quả mong đợi cụ thể; chỉ gây lỗi trên fixture. Không đưa secrets, token hoặc output định danh thật vào repository.

### Kết thúc và chi phí còn lại

- Cung cấp lệnh/quy trình cleanup theo dependency, có nhánh xử lý khi lab dừng giữa chừng.
- Chỉ xóa tài nguyên có ownership rõ; liệt kê retained resources cùng lý do và chi phí.
- Kiểm tra inventory bằng API/service console thích hợp. Không dùng billing tức thời làm bằng chứng duy nhất vì dữ liệu có độ trễ.
- Với lab thiết kế: bàn giao sơ đồ, policy/ADR và test matrix; không giả vờ đã kiểm chứng trên AWS.

## 7. Troubleshooting bắt buộc

- CannotPullContainerError: kiểm tra image, role và đường tới ECR/S3
- Task stopped: xem stopped reason, exit code, memory và logs
- ALB unhealthy: kiểm tra port, SG và health path

Mỗi lỗi cần triệu chứng, giả thuyết, bằng chứng cần thu thập và cách sửa có phạm vi nhỏ; tránh chữa lỗi bằng mở quyền hoặc mở mạng rộng.

## 8. Câu hỏi và bài tập đánh giá

- Vì sao app đọc S3 cần task role?
- Tag mutable gây rủi ro rollback thế nào?
- Private task pull image bằng đường nào?

Bài tập cuối: nộp sơ đồ, kết quả happy path và failure case, một quyết định kiến trúc có lựa chọn thay thế, ước tính chi phí và checklist cleanup. Cung cấp đáp án gợi ý tách khỏi đề, đánh giá bằng bằng chứng thay vì chỉ xác nhận đã bấm đủ bước.

## 9. Tiêu chí nghiệm thu bài hướng dẫn

- [ ] Các mục tiêu đều có phần giải thích và hoạt động đánh giá tương ứng.
- [ ] Prerequisite, quyền, Region, công cụ và fixture rõ ràng; không có dependency vòng giữa các bài.
- [ ] Lab chính và nhánh tùy chọn được phân biệt; có đường học qua dữ liệu/sơ đồ minh họa nếu không chạy AWS.
- [ ] Có sơ đồ, lệnh hoặc thao tác tái lập, expected result, negative case và troubleshooting.
- [ ] Có phân tích chi phí và cleanup cả trường hợp thành công lẫn dừng giữa chừng.
- [ ] Nội dung thay đổi theo thời gian được đối chiếu tài liệu chính thức; ghi ngày và phiên bản.
- [ ] Không có secret, credentials hoặc dữ liệu người dùng thật trong artifact.
- [ ] Phân biệt output minh họa với bằng chứng thực thi; ghi rõ phần chưa kiểm thử.

## 10. Tài liệu nguồn

Các trang dưới đây là điểm bắt đầu tra cứu, không thay cho kiểm chứng từng API/giới hạn khi viết bài. Ưu tiên tài liệu chính thức của AWS và nhà cung cấp công cụ. Không ghi cứng giá, quota, runtime hay thông số có thể đổi.

- [Tài liệu AmazonECS](https://docs.aws.amazon.com/AmazonECS/latest/developerguide/)
- [Tài liệu AmazonECR](https://docs.aws.amazon.com/AmazonECR/latest/userguide/)
- [AWS Decision Guides](https://docs.aws.amazon.com/decision-guides/)

## 11. Chỉ dẫn bàn giao cho mô hình tiếp theo

> Dựa trên plan này, tạo bài học và lab tiếng Việt cho chủ đề “Container — ECR, ECS, Fargate” tại hai đường dẫn mục 1. Kiểm tra nội dung repository và hướng dẫn AGENTS.md trước khi sửa; giữ nguyên thay đổi của người khác. Tra cứu tài liệu hiện hành, cụ thể hóa quyền và lệnh, cung cấp expected output, đáp án, troubleshooting và cleanup. Chỉ biên soạn artifact; không tự chạy thao tác thay đổi AWS account, deploy hoặc gửi thông báo. Không mở rộng nhánh tùy chọn thành prerequisite bắt buộc. Ghi rõ nội dung đã đối chiếu tài liệu và phần thực sự đã kiểm thử.

## 12. Phần bổ sung theo lộ trình Laravel

Đọc [plan hoàn thiện lộ trình](15-roadmap-gap-completion.md) trước khi biên soạn tiếp. Phạm vi bổ sung cho chủ đề này: Mốc 6: container Laravel API và worker riêng, migration task, graceful shutdown và kiểm chứng nghiệp vụ sau deploy/rollback.

Giữ lab dịch vụ hiện tại và liên kết tới nhánh dự án; áp dụng hợp đồng ứng dụng, kiểm chứng và cleanup trong plan bổ sung. Không đánh dấu hoàn thành yêu cầu thực hành chỉ bằng ví dụ Python/Node.js hoặc output minh họa.
