# Plan: Messaging và workflow — SQS, SNS, EventBridge, Step Functions

## 1. Mục đích và đầu ra

Đây là kế hoạch cho mô hình khác biên soạn bài hướng dẫn, không phải bài học hoàn chỉnh. Bước lập plan không tạo tài nguyên, chạy lab hay deploy AWS.

- Đối tượng: senior developer mới học AWS; giải thích thuật ngữ ở lần xuất hiện đầu tiên.
- Ngôn ngữ: tiếng Việt; giữ tên dịch vụ/API tiếng Anh.
- Yêu cầu đầu vào: chủ đề 01–03, 09–10; hiểu transaction trong lộ trình; kiểm tra điều kiện thực tế trước khi bắt đầu.
- Thời lượng gợi ý: 6–8 giờ, có thể chia buổi; không tính thời gian cấp quyền và chờ provisioning.
- Đầu ra khi biên soạn: `notes/11-messaging-and-workflows.md` và `labs/11-messaging-and-workflows/README.md`, liên kết qua lại.
- Dùng hệ thống đơn hàng nhỏ làm ví dụ khi phù hợp; mỗi lab phải có fixture độc lập hoặc chỉ rõ tài nguyên kế thừa từ bài trước.

## 2. Mục tiêu học tập đo được

1. Giải thích và áp dụng: queue, pub/sub, event routing và workflow: chọn theo yêu cầu.
2. Giải thích và áp dụng: sQS Standard/FIFO, visibility timeout, long polling, DLQ và redrive.
3. Giải thích và áp dụng: sNS subscription, filter và fan-out; EventBridge bus/rule/target.
4. Giải thích và áp dụng: step Functions Standard/Express, state, Retry/Catch và timeout.
5. Giải thích và áp dụng: idempotency, ordering, outbox và compensation ở mức thiết kế.
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
| 1 | Queue, pub/sub, event routing và workflow: chọn theo yêu cầu | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 2 | SQS Standard/FIFO, visibility timeout, long polling, DLQ và redrive | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 3 | SNS subscription, filter và fan-out; EventBridge bus/rule/target | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 4 | Step Functions Standard/Express, state, Retry/Catch và timeout | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 5 | Idempotency, ordering, outbox và compensation ở mức thiết kế | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 6 | Lab, lỗi có kiểm soát và cleanup | Có expected result, negative case và inventory sau cleanup |

Trước lab, cung cấp sơ đồ, bảng tài nguyên, quyền cần thiết và ước tính chi phí. Sau lab, liên hệ kết quả với hành vi production và chỉ rõ giới hạn của mô hình đơn giản.

## 5. Yêu cầu chính xác và điểm dễ hiểu sai

- Không hứa exactly-once end-to-end chỉ vì dùng FIFO; side effect vẫn cần idempotency
- SQS visibility timeout không phải khóa transaction; timeout phải phù hợp consumer
- Step Functions Standard và Express có execution semantics khác nhau; xác minh retry theo loại integration
- Event schema/versioning và event size phải được kiểm tra theo tài liệu hiện hành

## 6. Kế hoạch thực hành

### Chuẩn bị

- Kiểm tra account/role bằng STS, profile và Region; dùng temporary credentials.
- Liệt kê quyền control plane, data plane và `iam:PassRole` nếu cần, giới hạn theo tài nguyên; không thay bằng yêu cầu admin chung.
- Kiểm tra quota, phiên bản công cụ, dịch vụ khả dụng trong Region và tài nguyên prerequisite.
- Đặt tên/tag riêng cho fixture; ghi ownership để không xóa tài nguyên bài khác.
- Với phần có tính phí: ước tính theo Region và thời gian chạy, ghi nguồn giá/ngày kiểm chứng, trần quy mô và điều kiện dừng. Không hứa Free Tier.

### Trình tự cần triển khai trong bài hướng dẫn sau

1. Lab chính: API đơn hàng → SQS → worker; dùng idempotency key và dữ liệu giả.
2. Gửi message trùng, làm worker lỗi sau side effect và trước ack; kiểm chứng không tạo nghiệp vụ trùng.
3. Đưa poison message vào DLQ, sửa nguyên nhân rồi redrive có kiểm soát.
4. Mini-lab tách biệt: SNS fan-out hoặc EventBridge filter; workflow Step Functions có Retry/Catch; không buộc ghép mọi dịch vụ.
5. Tắt producer/trigger trước, kiểm tra in-flight và dọn queue/DLQ/topic/rule/state machine/logs.

Mỗi bước cần mục đích, thao tác/lệnh, placeholder, expected output, cách xác minh và cách khắc phục khi khác kỳ vọng. Negative test phải có kết quả mong đợi cụ thể; chỉ gây lỗi trên fixture. Không đưa secrets, token hoặc output định danh thật vào repository.

### Kết thúc và chi phí còn lại

- Cung cấp lệnh/quy trình cleanup theo dependency, có nhánh xử lý khi lab dừng giữa chừng.
- Chỉ xóa tài nguyên có ownership rõ; liệt kê retained resources cùng lý do và chi phí.
- Kiểm tra inventory bằng API/service console thích hợp. Không dùng billing tức thời làm bằng chứng duy nhất vì dữ liệu có độ trễ.
- Với lab thiết kế: bàn giao sơ đồ, policy/ADR và test matrix; không giả vờ đã kiểm chứng trên AWS.

## 7. Troubleshooting bắt buộc

- Message xuất hiện lại: kiểm tra delete/ack và visibility
- DLQ tăng: kiểm tra poison message, quyền và maxReceiveCount
- Workflow lặp side effect: kiểm tra Retry và idempotency
- Event không tới target: kiểm tra pattern, permission và target failure

Mỗi lỗi cần triệu chứng, giả thuyết, bằng chứng cần thu thập và cách sửa có phạm vi nhỏ; tránh chữa lỗi bằng mở quyền hoặc mở mạng rộng.

## 8. Câu hỏi và bài tập đánh giá

- Khi nào dùng SNS, SQS hoặc EventBridge?
- Nếu worker chết sau ghi DB nhưng trước ack thì sao?
- Khi nào cần transactional outbox?

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

- [Tài liệu AWSSimpleQueueService](https://docs.aws.amazon.com/AWSSimpleQueueService/latest/SQSDeveloperGuide/)
- [Tài liệu sns](https://docs.aws.amazon.com/sns/latest/dg/)
- [Tài liệu eventbridge](https://docs.aws.amazon.com/eventbridge/latest/userguide/)
- [Tài liệu step-functions](https://docs.aws.amazon.com/step-functions/latest/dg/)
- [AWS Decision Guides](https://docs.aws.amazon.com/decision-guides/)

## 11. Chỉ dẫn bàn giao cho mô hình tiếp theo

> Dựa trên plan này, tạo bài học và lab tiếng Việt cho chủ đề “Messaging và workflow — SQS, SNS, EventBridge, Step Functions” tại hai đường dẫn mục 1. Kiểm tra nội dung repository và hướng dẫn AGENTS.md trước khi sửa; giữ nguyên thay đổi của người khác. Tra cứu tài liệu hiện hành, cụ thể hóa quyền và lệnh, cung cấp expected output, đáp án, troubleshooting và cleanup. Chỉ biên soạn artifact; không tự chạy thao tác thay đổi AWS account, deploy hoặc gửi thông báo. Không mở rộng nhánh tùy chọn thành prerequisite bắt buộc. Ghi rõ nội dung đã đối chiếu tài liệu và phần thực sự đã kiểm thử.

## 12. Phần bổ sung theo lộ trình Laravel

Đọc [plan hoàn thiện lộ trình](15-roadmap-gap-completion.md) trước khi biên soạn tiếp. Phạm vi bổ sung cho chủ đề này: Mốc 4: Laravel Queue/SQS, retry/DLQ/redrive, idempotency bền vững, EventBridge Scheduler và kiểm chứng lịch không tạo side effect trùng.

Giữ lab dịch vụ hiện tại và liên kết tới nhánh dự án; áp dụng hợp đồng ứng dụng, kiểm chứng và cleanup trong plan bổ sung. Không đánh dấu hoàn thành yêu cầu thực hành chỉ bằng ví dụ Python/Node.js hoặc output minh họa.
