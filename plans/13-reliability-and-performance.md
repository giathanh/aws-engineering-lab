# Plan: Độ tin cậy và hiệu năng — SLO, load test, backup, DR

## 1. Mục đích và đầu ra

Đây là kế hoạch cho mô hình khác biên soạn bài hướng dẫn, không phải bài học hoàn chỉnh. Bước lập plan không tạo tài nguyên, chạy lab hay deploy AWS.

- Đối tượng: senior developer mới học AWS; giải thích thuật ngữ ở lần xuất hiện đầu tiên.
- Ngôn ngữ: tiếng Việt; giữ tên dịch vụ/API tiếng Anh.
- Yêu cầu đầu vào: chủ đề 01–12; có ứng dụng lab hoàn chỉnh trong lộ trình; kiểm tra điều kiện thực tế trước khi bắt đầu.
- Thời lượng gợi ý: 6–10 giờ, có thể chia buổi; không tính thời gian cấp quyền và chờ provisioning.
- Đầu ra khi biên soạn: `notes/13-reliability-and-performance.md` và `labs/13-reliability-and-performance/README.md`, liên kết qua lại.
- Dùng hệ thống đơn hàng nhỏ làm ví dụ khi phù hợp; mỗi lab phải có fixture độc lập hoặc chỉ rõ tài nguyên kế thừa từ bài trước.

## 2. Mục tiêu học tập đo được

1. Giải thích và áp dụng: sLI/SLO, error budget và measurement window.
2. Giải thích và áp dụng: latency percentiles, throughput, saturation và bottleneck.
3. Giải thích và áp dụng: deadline, timeout, retry budget, backoff/jitter, circuit breaker và backpressure.
4. Giải thích và áp dụng: load test: baseline, ramp, steady-state, recovery và giới hạn.
5. Giải thích và áp dụng: rTO/RPO, backup/restore, failover và lựa chọn DR theo chi phí.
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
| 1 | SLI/SLO, error budget và measurement window | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 2 | Latency percentiles, throughput, saturation và bottleneck | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 3 | Deadline, timeout, retry budget, backoff/jitter, circuit breaker và backpressure | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 4 | Load test: baseline, ramp, steady-state, recovery và giới hạn | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 5 | RTO/RPO, backup/restore, failover và lựa chọn DR theo chi phí | Có ví dụ, sơ đồ hoặc bảng quyết định; có một câu hỏi áp dụng gắn với phần này |
| 6 | Lab, lỗi có kiểm soát và cleanup | Có expected result, negative case và inventory sau cleanup |

Trước lab, cung cấp sơ đồ, bảng tài nguyên, quyền cần thiết và ước tính chi phí. Sau lab, liên hệ kết quả với hành vi production và chỉ rõ giới hạn của mô hình đơn giản.

## 5. Yêu cầu chính xác và điểm dễ hiểu sai

- Không suy RTO/RPO thực tế chỉ từ cấu hình; cần đo drill
- p95/p99 không suy từ average; ghi sample size và phương pháp đo
- Không cộng percentile của từng dependency để suy percentile end-to-end
- Multi-AZ, backup và multi-Region giải quyết các failure mode khác nhau
- Chỉ đề xuất AWS FIS như nhánh tùy chọn có stop condition và scope rõ, không bắt buộc chạy

## 6. Kế hoạch thực hành

### Chuẩn bị

- Kiểm tra account/role bằng STS, profile và Region; dùng temporary credentials.
- Liệt kê quyền control plane, data plane và `iam:PassRole` nếu cần, giới hạn theo tài nguyên; không thay bằng yêu cầu admin chung.
- Kiểm tra quota, phiên bản công cụ, dịch vụ khả dụng trong Region và tài nguyên prerequisite.
- Đặt tên/tag riêng cho fixture; ghi ownership để không xóa tài nguyên bài khác.
- Với phần có tính phí: ước tính theo Region và thời gian chạy, ghi nguồn giá/ngày kiểm chứng, trần quy mô và điều kiện dừng. Không hứa Free Tier.

### Trình tự cần triển khai trong bài hướng dẫn sau

1. Định nghĩa SLO và workload cho API; đo baseline trước mọi tối ưu.
2. Chạy load test có trần rate, concurrency, duration và điều kiện dừng; chỉ target endpoint lab sở hữu.
3. Inject dependency timeout/instance failure; so sánh có/không retry budget và backpressure.
4. Restore backup vào môi trường tách biệt; đo thời gian và kiểm tra độ đầy đủ dữ liệu.
5. Viết runbook, chạy lại một recovery drill, ghi kết quả và dọn bản restore/load generator.

Mỗi bước cần mục đích, thao tác/lệnh, placeholder, expected output, cách xác minh và cách khắc phục khi khác kỳ vọng. Negative test phải có kết quả mong đợi cụ thể; chỉ gây lỗi trên fixture. Không đưa secrets, token hoặc output định danh thật vào repository.

### Kết thúc và chi phí còn lại

- Cung cấp lệnh/quy trình cleanup theo dependency, có nhánh xử lý khi lab dừng giữa chừng.
- Chỉ xóa tài nguyên có ownership rõ; liệt kê retained resources cùng lý do và chi phí.
- Kiểm tra inventory bằng API/service console thích hợp. Không dùng billing tức thời làm bằng chứng duy nhất vì dữ liệu có độ trễ.
- Với lab thiết kế: bàn giao sơ đồ, policy/ADR và test matrix; không giả vờ đã kiểm chứng trên AWS.

## 7. Troubleshooting bắt buộc

- Latency tăng: đối chiếu saturation, DB pool, cache miss và downstream
- Retry storm: kiểm tra retry nhiều tầng và thiếu jitter
- Restore chậm/mất dữ liệu: xem recovery point, log và quy trình xác minh

Mỗi lỗi cần triệu chứng, giả thuyết, bằng chứng cần thu thập và cách sửa có phạm vi nhỏ; tránh chữa lỗi bằng mở quyền hoặc mở mạng rộng.

## 8. Câu hỏi và bài tập đánh giá

- SLO nào phản ánh trải nghiệm người dùng?
- Retry làm hệ thống tệ hơn khi nào?
- Backup tồn tại đã đủ chứng minh RPO chưa?
- Đánh đổi chi phí nào chấp nhận để giảm RTO?

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

- [Tài liệu wellarchitected](https://docs.aws.amazon.com/wellarchitected/latest/reliability-pillar/)
- [Tài liệu wellarchitected](https://docs.aws.amazon.com/wellarchitected/latest/performance-efficiency-pillar/)
- [Tài liệu prescriptive-guidance](https://docs.aws.amazon.com/prescriptive-guidance/latest/disaster-recovery-options/)
- [Tài liệu fis](https://docs.aws.amazon.com/fis/latest/userguide/)
- [AWS Decision Guides](https://docs.aws.amazon.com/decision-guides/)

## 11. Chỉ dẫn bàn giao cho mô hình tiếp theo

> Dựa trên plan này, tạo bài học và lab tiếng Việt cho chủ đề “Độ tin cậy và hiệu năng — SLO, load test, backup, DR” tại hai đường dẫn mục 1. Kiểm tra nội dung repository và hướng dẫn AGENTS.md trước khi sửa; giữ nguyên thay đổi của người khác. Tra cứu tài liệu hiện hành, cụ thể hóa quyền và lệnh, cung cấp expected output, đáp án, troubleshooting và cleanup. Chỉ biên soạn artifact; không tự chạy thao tác thay đổi AWS account, deploy hoặc gửi thông báo. Không mở rộng nhánh tùy chọn thành prerequisite bắt buộc. Ghi rõ nội dung đã đối chiếu tài liệu và phần thực sự đã kiểm thử.

## 12. Phần bổ sung theo lộ trình Laravel

Đọc [plan hoàn thiện lộ trình](15-roadmap-gap-completion.md) trước khi biên soạn tiếp. Phạm vi bổ sung cho chủ đề này: Mốc 7: load test, timeout/retry/circuit breaker/backpressure và RDS recovery drill trên ứng dụng Laravel thật; tách số đo thực khỏi mô phỏng.

Giữ lab dịch vụ hiện tại và liên kết tới nhánh dự án; áp dụng hợp đồng ứng dụng, kiểm chứng và cleanup trong plan bổ sung. Không đánh dấu hoàn thành yêu cầu thực hành chỉ bằng ví dụ Python/Node.js hoặc output minh họa.
