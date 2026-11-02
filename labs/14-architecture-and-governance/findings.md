# Mini-lab — Triage GuardDuty và Security Hub bằng finding mẫu

> 45–60 phút, đường chính offline, không tạo tài nguyên. [Bài 14](../../notes/14-architecture-and-governance.md). Đối chiếu nguồn 2026-11-01; dữ liệu dưới đây tự tạo, không phải output scan thật.

## Fixture và nhiệm vụ

Account alias `sandbox-dev`, Region `ap-southeast-1`, resource alias `lab-api-01`, owner `team-lab`, cửa sổ thử 09:00–09:15. Bảng sau là dữ liệu rút gọn phục vụ bài tập, không phải JSON hợp lệ để import qua API:

| Finding | Nguồn | Mức ưu tiên do provider | Dữ kiện giả |
|---|---|---|---|
| F-01 | GuardDuty sample | High | EC2 lab-api-01 có kết nối outbound bất thường lúc 09:05; SAMPLE=true |
| F-02 | Security Hub CSPM | Medium | SG của lab-api-01 có ingress 22 từ 0.0.0.0/0; Compliance=FAILED |
| F-03 | Security Hub bản tổng hợp | High | Cùng resource và thời gian F-01; provider finding ID trùng; Workflow=NEW |

Nhiệm vụ: viết một phiếu triage cho mỗi vấn đề thực sự khác nhau, gồm owner, resource/Region/account alias, thời điểm, nguồn, bằng chứng cần thêm, tác động, hành động nhỏ nhất, tiêu chí xác minh và trạng thái. Không suy F-01 thành máy bị chiếm chỉ từ mức High. Không tạo issue/gửi thông báo trong bài offline.

## Các bước và kết quả mong đợi

1. Nhận diện sample: F-01/F-03 chỉ dùng để kiểm tra cách đọc/routing; ghi “chưa xác minh sự cố thật”. Đối chiếu provider ID để tránh tạo hai incident cho cùng finding.
2. Với F-02, kiểm tra SG rule, attachment vào ENI, route/public IP, và cách truy cập quản trị được yêu cầu. SG mở 22 không tự chứng minh Internet có route tới máy, nhưng rule vẫn rộng hơn nhu cầu SSM.
3. Đề xuất bỏ rule 22 khỏi SG lab sau khi xác minh SSM hoạt động. Không xóa toàn SG đang dùng bởi app/DB, không thay bằng một rule khác rộng tương đương.
4. Kiểm chứng sau sửa: inventory SG không còn rule, Session Manager vẫn truy cập được, API smoke vẫn pass; chờ control đánh giá lại. Workflow RESOLVED là trạng thái công việc, không phải bằng chứng rule đã sửa.
5. Với F-01, liệt kê nguồn cần đọc nếu đây là finding thật: instance/ENI mapping, flow/DNS logs khả dụng, CloudTrail thay đổi role/network và process/app logs cùng thời gian. Chỉ chọn nguồn thực sự được bật/thu thập; không hứa CloudTrail chứa mọi network packet.

<details><summary>Đáp án gợi ý</summary>
Có hai nhóm: sample threat F-01/F-03 và posture F-02. F-01/F-03 không chứng minh exploit; chỉ nghiệm thu dedup/routing. F-02 cần xác minh cấu hình và exposure, sau đó thu hẹp rule nếu không cần SSH. Owner cần biết hành động, scope và rollback. Đóng ticket sau evidence, không chỉ đổi severity/workflow.
</details>

## Nhánh AWS tùy chọn: sample findings

Chỉ làm trên detector sandbox đã được cấp quyền. Không tự bật GuardDuty/Security Hub của tổ chức vì đó là thay đổi phạm vi/cost riêng. Cần `guardduty:ListDetectors`, `GetDetector`, `CreateSampleFindings`, `ListFindings`, `GetFindings` trên detector được giao; read findings Security Hub nếu dịch vụ đã bật.

```bash
aws guardduty list-detectors --profile aws-lab --region ap-southeast-1
# Điền detector ID đã xác minh thuộc sandbox:
export DETECTOR_ID='<sandbox-detector-id>'
aws guardduty get-detector --detector-id "$DETECTOR_ID"
aws guardduty create-sample-findings --detector-id "$DETECTOR_ID"
aws guardduty list-findings --detector-id "$DETECTOR_ID" --max-results 20
```

Kỳ vọng sample findings có dấu hiệu SAMPLE trong nội dung; đọc get-findings cho ID cụ thể. Giới hạn 1 lần tạo sample, 30 phút quan sát. Nếu đã có EventBridge routing tự động gửi thông báo, không chạy nhánh live trước khi hiểu đích nhận và được phép thử; đường offline vẫn đầy đủ cho bài phân tích.

Chi phí: offline bằng 0. Live dùng dịch vụ đã bật vẫn chịu cơ chế tính phí của account; xem [GuardDuty pricing](https://aws.amazon.com/guardduty/pricing/) và [Security Hub pricing](https://aws.amazon.com/security-hub/pricing/) theo Region, không coi trial là bảo đảm miễn phí. Ghi cấu hình trước/sau; không tắt detector/organization integration để cleanup sample. Nếu tạo filter/rule riêng, xóa đúng filter/rule đó; sample finding có thể archive sau review theo quyền được cấp.

## Nghiệm thu

Nộp phiếu triage, cách dedup, hai giả thuyết có thể bác bỏ, kiểm chứng sửa SG, phần còn thiếu evidence và phạm vi cleanup. Offline chỉ đánh dấu đã phân tích mẫu; không đánh dấu đã phát hiện hoặc xử lý tấn công trên AWS.

Nguồn: [GuardDuty samples](https://docs.aws.amazon.com/guardduty/latest/ug/sample_findings.html), [ASFF attributes](https://docs.aws.amazon.com/securityhub/latest/userguide/asff-top-level-attributes.html).
