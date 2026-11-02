# Bài 12 — Hạ tầng dưới dạng Mã nguồn (IaC) và CI/CD: CloudFormation, AWS CDK, Terraform và GitHub Actions OIDC

> **Đối tượng:** developer có kinh nghiệm nhưng mới học AWS.
> **Thời lượng:** khoảng 6–8 giờ, chia hai buổi. Buổi 1 gồm phần 1–3 (Bản chất IaC, So sánh CloudFormation/CDK vs Terraform, Quản trị Cấu hình & Secrets). Buổi 2 gồm phần 4–7 (Tự động hóa CI/CD không mật khẩu với GitHub Actions OIDC, Quản lý Rủi ro Rollback và [lab thực hành](../labs/12-iac-and-cicd/README.md)).
> **Kế hoạch gốc:** [plans/12-iac-and-cicd.md](../plans/12-iac-and-cicd.md)
> **Ngày đối chiếu tài liệu:** 2026-10-31. Xem [Trạng thái kiểm chứng](#trạng-thái-kiểm-chứng) ở cuối bài.

## Mục tiêu

Sau bài này, bạn có thể:

1. Nắm vững tư duy **Hạ tầng dưới dạng Mã nguồn (Infrastructure as Code - IaC)**: trạng thái mong muốn (**Desired State**), đồ thị phụ thuộc (**Dependency Graph**), cơ chế xem trước thay đổi (**Plan / Change Set**) và phát hiện độ lệch cấu hình (**Drift Detection**).
2. Lựa chọn chuẩn xác giữa hệ sinh thái của AWS (**AWS CloudFormation, AWS CDK**) và công cụ trung lập (**HashiCorp Terraform**); làm chủ vòng đời và cách bảo vệ tệp trạng thái (**State File Management** và **State Locking**).
3. Thiết kế kiến trúc module hóa: chia tách Stack theo môi trường (Dev/Staging/Prod), tham số hóa (**Parameters & Outputs**), và tham chiếu bí mật an toàn (**Dynamic References**) từ AWS Secrets Manager và Systems Manager Parameter Store.
4. Xây dựng đường ống **CI/CD hiện đại không dùng Access Key tĩnh (Zero-Secret Pipeline)** bằng **OpenID Connect (OIDC)** giữa GitHub Actions và AWS STS; làm chủ các thuộc tính xác thực **Subject (`sub`)** và **Audience (`aud`)**.
5. Kiểm soát quy trình **Rollback** hạ tầng, giải thích rõ tại sao rollback hạ tầng không thể tự động đảo ngược cấu trúc dữ liệu (**Schema / Data Migration**), và xử lý an toàn các xung đột tương tranh khi hủy tài nguyên (**Destroy**).

---

## Phần 1 — Khái niệm Cốt lõi của IaC: Desired State, Dependency Graph và Drift (45 phút)

Phương pháp tạo tài nguyên thủ công qua giao diện Console ("ClickOps") tiềm ẩn rủi ro rất lớn: không thể tái lập môi trường chính xác (Environment Parity), thiếu lịch sử kiểm toán phiên bản (Version Control), và dễ dẫn đến thảm họa do thao tác nhầm của con người.

**Infrastructure as Code (IaC)** biến toàn bộ tài nguyên đám mây thành các tệp mã nguồn được quản lý bằng Git:

```text
Quy trình Vận hành của Khung Khái niệm IaC:
┌─────────────────────┐       ┌──────────────────────┐       ┌─────────────────────┐
│  Mã nguồn IaC       │ ────> │ Plan / Change Set    │ ────> │ Thực thi Đồng bộ    │
│  (Mô tả trạng thái  │       │ (So sánh Trạng thái  │       │ (Apply / Execute)   │
│   mong muốn: Desired)│       │  Hiện tại vs Mong đợi│       │ Đạt Actual State    │
└─────────────────────┘       └──────────────────────┘       └─────────────────────┘
                                                                        │
                                                                        ▼
                                                             ┌─────────────────────┐
                                                             │ Phát hiện Lệch      │
                                                             │ (Drift Detection)   │
                                                             │ Nếu ai đó sửa tay!  │
                                                             └─────────────────────┘
```

### 1. Trạng thái Mong muốn (Desired State) vs Mệnh lệnh (Imperative)
- **Mô hình Mệnh lệnh (Imperative):** Bạn viết một kịch bản bash/Python chỉ rõ từng bước: `1. Tạo VPC`, `2. Tạo Subnet`, `3. Đợi subnet sẵn sàng...`. Nếu bước 2 bị lỗi giữa chừng và bạn chạy lại, script sẽ cố tạo lại VPC thứ hai hoặc ném lỗi trùng lặp.
- **Mô hình Khai báo (Declarative / Desired State):** Bạn chỉ mô tả mục tiêu cuối cùng: *"Tôi muốn có 1 VPC với dải IP 10.0.0.0/16 và 2 Subnets"*. Động cơ IaC (CloudFormation hoặc Terraform) sẽ tự động kiểm tra xem tài nguyên nào đã có, tài nguyên nào thiếu hoặc cần cập nhật để đưa hạ tầng thực tế khớp hoàn toàn với bản khai báo.

### 2. Đồ thị Phụ thuộc Tài nguyên (Resource Dependency Graph)
Động cơ IaC tự động phân tích quan hệ giữa các tài nguyên để xây dựng **Directed Acyclic Graph (DAG)**:
- Nếu Subnet cần `VpcId`, VPC sẽ được tạo trước.
- Các tài nguyên độc lập (ví dụ hai Subnet ở hai Availability Zones khác nhau) sẽ được tạo **song song** để tối ưu thời gian triển khai.
- Bạn có thể can thiệp thứ tự bằng thuộc tính phụ thuộc tường minh (`DependsOn` trong CloudFormation hoặc `depends_on` trong Terraform).

### 3. Change Set và Độ lệch Hạ tầng (Configuration Drift)
- **Change Set / Plan:** Trước khi tác động lên hạ tầng thật, bạn luôn phải tạo một bản xem trước thay đổi. Change Set sẽ chỉ rõ chính xác: Tài nguyên nào sẽ được tạo mới (`Add`), tài nguyên nào sẽ cập nhật tại chỗ (`Modify`), và đặc biệt là tài nguyên nào sẽ bị **xóa và tạo lại (Destroy & Recreate)** — rủi ro gây mất dữ liệu với Database!
- **Drift Detection:** Khi một kỹ sư đăng nhập Console để sửa tạm một Security Group mà không cập nhật vào Git, hạ tầng thực tế đã bị lệch (**Drift**). Tính năng Drift Detection của CloudFormation và lệnh `terraform plan` cho phép quét và hiển thị chi tiết các điểm sai lệch này để đội ngũ đưa cấu hình trở về trạng thái chuẩn hóa.

---

## Phần 2 — So sánh Công cụ: AWS CloudFormation, AWS CDK và HashiCorp Terraform (50 phút)

```text
So sánh 3 Công cụ IaC Phổ biến nhất trên AWS:
┌─────────────────────────┬────────────────────────────┬────────────────────────────┐
│ Đặc tính                │ AWS CloudFormation / CDK   │ HashiCorp Terraform        │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Hệ sinh thái            │ Native AWS                 │ Đa nền tảng (Multi-Cloud)  │
│                         │ (Hỗ trợ 100% tính năng mới)│ AWS, Azure, GCP, Datadog   │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Ngôn ngữ định nghĩa     │ JSON/YAML (CloudFormation) │ HCL (HashiCorp             │
│                         │ TypeScript/Python/Go (CDK) │ Configuration Language)    │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Quản lý Trạng thái      │ Quản lý tự động bởi AWS    │ Tệp `terraform.tfstate`    │
│ (State Management)      │ (Người dùng không cần lưu  │ Bắt buộc lưu trên S3       │
│                         │ hay khóa state)            │ kết hợp DynamoDB Locking   │
├─────────────────────────┼────────────────────────────┼────────────────────────────┤
│ Cơ chế Rollback         │ Tự động rollback khi deploy│ Không tự rollback; giữ     │
│                         │ thất bại (Atomic Stack)    │ trạng thái tại điểm lỗi    │
└─────────────────────────┴────────────────────────────┴────────────────────────────┘
```

### 1. AWS CloudFormation và AWS CDK (Cloud Development Kit)
- **CloudFormation:** Là công cụ gốc của AWS. Mọi tài nguyên được gom thành các **Stacks**. Toàn bộ lịch sử triển khai, trạng thái và rollback được AWS quản lý ngầm an toàn tuyệt đối.
- **AWS CDK:** Cho phép kỹ sư sử dụng các ngôn ngữ lập trình thực thụ (TypeScript, Python, Java, C#, Go) để định nghĩa hạ tầng:
  - **L1 Constructs:** Ánh xạ 1:1 trực tiếp với các tài nguyên thô của CloudFormation (`CfnBucket`).
  - **L2 Constructs:** Các lớp trừu tượng thông minh có sẵn cấu hình bảo mật mặc định tối ưu của AWS (ví dụ: `s3.Bucket` tự động bật mã hóa và chặn public access).
  - **L3 Constructs (Patterns):** Gom nhóm kiến trúc hoàn chỉnh chỉ trong vài dòng code (ví dụ: `ApplicationLoadBalancedFargateService`).
  - Lệnh `cdk synth` biên dịch code bậc cao thành các mẫu JSON/YAML CloudFormation thuần túy để AWS triển khai.
  - **Bootstrap:** `cdk bootstrap` tạo stack `CDKToolkit` gồm bucket/ECR repo chứa asset và các role (deploy, file-publishing, lookup, **CloudFormation execution role**). Mặc định execution role được gắn `AdministratorAccess`; nên truyền `--cloudformation-execution-policies` với policy hẹp hơn và review các role này như mọi role khác. Khi dọn môi trường, bucket asset của bootstrap thường bị giữ lại và tính phí lưu trữ.

### 2. HashiCorp Terraform: State File và Rủi ro Lộ Bí mật
Terraform là công cụ của HashiCorp (không phải dịch vụ AWS): nó gọi AWS API qua provider, chạy trên máy bạn hoặc CI, và dùng tệp `terraform.tfstate` để lưu bản đồ ánh xạ giữa mã nguồn HCL và tài nguyên thực tế trên AWS:

> [!CAUTION]
> **Rủi ro rò rỉ bí mật trong Terraform State:**
> Tệp `terraform.tfstate` lưu toàn bộ giá trị plaintext của các tham số đầu vào, bao gồm cả mật khẩu Database hoặc Access Keys!
> - **Tuyệt đối KHÔNG BAO GIỜ commit `terraform.tfstate` vào Git repository!**
> - Luôn cấu hình **Remote Backend** trên S3 có bật mã hóa KMS phía máy chủ và chặn truy cập công khai.
> - Bật **state locking** để hai người/pipeline không cùng `apply`: backend S3 từ Terraform 1.10 hỗ trợ khóa bằng chính S3 (`use_lockfile = true`); cách cũ dùng bảng DynamoDB đã bị đánh dấu deprecated. Kiểm tra tài liệu phiên bản Terraform bạn dùng.
> - Bật **versioning** cho bucket state để khôi phục khi state hỏng.

---

## Phần 3 — Cấu trúc Module, Tham số hóa và Tham chiếu Bí mật An toàn (50 phút)

Một kiến trúc IaC chuẩn doanh nghiệp phải tách biệt ranh giới trách nhiệm và tuyệt đối không bao giờ ghi cứng (hardcode) dữ liệu nhạy cảm.

```text
Kiến trúc Phân tầng Stacks & Tham chiếu Bí mật Động:
┌────────────────────────────────────────────────────────┐
│ Mạng & Hạ tầng Nền tảng (Networking Stack)             │
│ - VPC, Subnets, Route Tables                           │
│ - Outputs: VpcId, PrivateSubnetIds                     │
└───────────────────────────┬────────────────────────────┘
                            │ Cross-Stack Reference
                            ▼
┌────────────────────────────────────────────────────────┐
│ Ứng dụng & Dịch vụ (Application Stack)                 │
│ - ECS Service / Lambda / RDS Database                  │
│                                                        │
│  Tham chiếu Bí mật Động (Dynamic Reference)            │
│  ┌──────────────────────────────────────────────────┐  │
│  │ "{{resolve:secretsmanager:prod/db:SecretString}}"│  │
│  └────────────────────────┬─────────────────────────┘  │
└───────────────────────────┼────────────────────────────┘
                            │ Lấy mật khẩu an toàn lúc chạy
                            ▼
┌────────────────────────────────────────────────────────┐
│ AWS Secrets Manager (prod/db)                          │
│ (Không bao giờ xuất hiện mật khẩu thô trong Git/YAML)  │
└────────────────────────────────────────────────────────┘
```

### 1. Phân tầng Stack theo Chu kỳ Thay đổi
Đừng bao giờ gom toàn bộ hệ thống vào một Stack duy nhất:
- **Tầng Nền tảng (Networking / VPC):** Rất ít khi thay đổi (vài tháng một lần). Nên nằm ở một Stack riêng biệt.
- **Tầng Dữ liệu (Database / Storage):** Thay đổi theo chu kỳ bảo trì, cần cấu hình bảo vệ xóa (`DeletionPolicy: Retain`).
- **Tầng Ứng dụng (App / Compute):** Thay đổi liên tục theo mỗi bản phát hành (nhiều lần mỗi ngày). Stack này import các thông tin đầu ra (**Outputs / Export**) từ tầng Mạng.

### 2. Tham chiếu Động (Dynamic References) trong CloudFormation
Thay vì truyền mật khẩu qua biến Parameters (vốn có nguy cơ bị in ra trong lịch sử CloudFormation Events hoặc Console), hãy sử dụng cơ chế Dynamic References:

```yaml
Resources:
  MyDatabase:
    Type: AWS::RDS::DBInstance
    Properties:
      DBInstanceClass: db.t4g.micro
      AllocatedStorage: 20
      MasterUsername: admin
      # Tham chiếu trực tiếp tới Secrets Manager tại thời điểm tạo:
      MasterUserPassword: '{{resolve:secretsmanager:app/db/credentials:SecretString:password}}'
```
CloudFormation resolve giá trị lúc deploy và không hiển thị nó trong template, event hay console. Với RDS, cách tốt hơn nữa là `ManageMasterUserPassword: true` để RDS tự sinh và rotate mật khẩu trong Secrets Manager (Bài 07). Tránh `NoEcho` parameter cho secret: giá trị vẫn đi qua tay người deploy và lịch sử CLI.

---

## Phần 4 — Tự động hóa CI/CD không Mật khẩu: GitHub Actions và AWS OIDC (55 phút)

Phương pháp truyền thống để pipeline CI/CD deploy lên AWS là tạo một IAM User với Access Key / Secret Key dài hạn, sau đó dán vào GitHub Secrets. 

> [!WARNING]
> **Mối nguy từ Static Long-Lived Credentials:**
> Nếu GitHub Secrets bị rò rỉ hoặc nhân viên rời bỏ dự án có quyền xem secret, kẻ tấn công có thể nắm giữ chìa khóa truy cập vĩnh viễn vào tài khoản AWS của bạn.

### Chuẩn Mực Hiện Đại: OpenID Connect (OIDC) Federation

Với OIDC, GitHub Actions không cần lưu bất kỳ Access Key nào. Thay vào đó, nó xác thực thông qua trao đổi mã thông báo JWT ngắn hạn với AWS Security Token Service (STS):

```text
Luồng Xác thực Không Mật khẩu (GitHub Actions OIDC Federation):
1. Kích hoạt Workflow
┌──────────────────┐
│ GitHub Actions   │
│ Runner           │
└────────┬─────────┘
         │ 2. Yêu cầu OIDC Token
         ▼
┌──────────────────┐
│ GitHub OIDC      │ ────> Ký Token JWT (Chứa repo, branch, actor)
│ Provider         │
└────────┬─────────┘
         │ 3. Gửi JWT Token + Gọi AssumeRoleWithWebIdentity
         ▼
┌──────────────────┐
│ AWS STS          │ ────> Xác thực chữ ký OIDC từ GitHub
│ (Token Service)  │       Kiểm tra Trust Policy (Đúng repo? Đúng main branch?)
└────────┬─────────┘
         │ 4. Cấp Temporary AWS Credentials (Hạn 15 phút - 1 giờ)
         ▼
┌──────────────────┐
│ GitHub Runner    │ ────> 5. Thực hiện deploy CloudFormation / Terraform
└──────────────────┘
```

### Trust Policy Giới hạn Nghiêm ngặt (Least Privilege Trust Claims)
Để đảm bảo một repository khác của ai đó trên GitHub không thể chiếm đoạt Role của bạn, Trust Policy **bắt buộc phải kiểm tra hai điều kiện cốt lõi**:
1. **`aud` (Audience):** Phải là `sts.amazonaws.com`.
2. **`sub` (Subject):** Phải trỏ chính xác vào tổ chức, repository và ngữ cảnh được phép. Dạng claim phụ thuộc workflow: job có `environment: prod` → `repo:<org>/<repo>:environment:prod`; không có environment, chạy trên branch → `repo:<org>/<repo>:ref:refs/heads/main`; pull request → `repo:<org>/<repo>:pull_request`. Dùng `environment` kèm protection rule (branch được phép, reviewer) là cách giới hạn chặt nhất.
3. Dùng `StringEquals` cho giá trị cụ thể. `StringLike` với `repo:<org>/*` hoặc thiếu điều kiện `sub` cho phép **mọi** repo (thậm chí mọi repo GitHub, nếu chỉ kiểm tra `aud`) assume role.
4. **Tách quyền:** role của pipeline chỉ cần quyền CloudFormation trên stack của nó và `iam:PassRole` cho một **CloudFormation execution role**; quyền tạo tài nguyên nằm ở execution role. Pipeline bị chiếm cũng không làm được gì ngoài phạm vi stack.
5. IAM không còn cần thumbprint để tin provider của GitHub (dùng thư viện CA tin cậy); `--thumbprint-list` có thể bỏ với CLI hiện hành. Pin action (`aws-actions/configure-aws-credentials`) theo commit SHA đã review.

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Principal": {
        "Federated": "arn:aws:iam::<YOUR_ACCOUNT_ID>:oidc-provider/token.actions.githubusercontent.com"
      },
      "Action": "sts:AssumeRoleWithWebIdentity",
      "Condition": {
        "StringEquals": {
          "token.actions.githubusercontent.com:aud": "sts.amazonaws.com",
          "token.actions.githubusercontent.com:sub": "repo:my-company/order-service:environment:prod"
        }
      }
    }
  ]
}
```

---

## Phần 5 — Quản lý Rủi ro: Rollback Hạ tầng vs Rollback Dữ liệu (45 phút)

Một trong những sai lầm nguy hiểm nhất của kỹ sư là ngộ nhận rằng: *"Khi bản cập nhật bị lỗi, CloudFormation sẽ tự động rollback nên mọi thứ sẽ trở về nguyên vẹn như trước."*

```text
Sự Khác biệt Cốt lõi giữa Rollback Hạ tầng và Dữ liệu:
┌─────────────────────────────────┬─────────────────────────────────┐
│ Rollback Hạ tầng (Infrastructure│ Rollback Dữ liệu (Data / Schema │
│ Rollback - Tự động bởi AWS)     │ Migration - Thủ công bởi Dev)   │
├─────────────────────────────────┼─────────────────────────────────┤
│ - Hủy các tài nguyên EC2/ECS mới│ - Bảng Database đã bị thêm hoặc │
│ - Đưa cấu hình Load Balancer    │   sửa đổi cột (Columns)         │
│   trở lại target group cũ       │ - Dữ liệu đơn hàng mới đã ghi   │
│ - Khôi phục Route 53 DNS        │   theo định dạng mới            │
│                                 │                                 │
│ => Hạ tầng khôi phục an toàn    │ => CloudFormation KHÔNG THỂ     │
│    trong vài phút               │    tự đảo ngược dữ liệu đã ghi! │
└─────────────────────────────────┴─────────────────────────────────┘
```

### 1. Tại sao Rollback Hạ tầng không thể Rollback Schema Database?
CloudFormation chỉ quản lý tài nguyên ở tầng AWS Control Plane (tạo máy chủ RDS, gán IP, mở Security Group). Nó **hoàn toàn không có quyền can thiệp hay hiểu được logic dữ liệu bên trong database**.
- Nếu pipeline của bạn chạy script `ALTER TABLE orders ADD COLUMN vat_rate INT` trước khi deploy app, và sau đó bước deploy app thất bại kích hoạt rollback hạ tầng:
  - Máy chủ ứng dụng được đưa về phiên bản cũ.
  - Nhưng cơ sở dữ liệu vẫn mang cấu trúc mới! Nếu ứng dụng cũ không tương thích với bảng đã sửa, toàn bộ hệ thống sẽ sập vĩnh viễn.

### 2. Quy tắc Expand & Contract (Two-Phase Database Migrations)
Để ứng dụng an toàn tuyệt đối trước mọi kịch bản rollback:
- **Phase 1 (Expand):** Luôn chỉ thêm mới cột hoặc bảng, cho phép giá trị `NULL` hoặc có `DEFAULT`. Cả ứng dụng cũ và ứng dụng mới đều có thể hoạt động song song.
- **Phase 2 (Contract):** Sau khi phiên bản mới đã chạy ổn định trên production vài ngày, chạy một script riêng biệt để dọn dẹp các cột cũ không còn dùng nữa.

### 3. Chính sách Bảo vệ Dữ liệu: `DeletionPolicy` và `UpdateReplacePolicy`
Mặc định, nếu bạn xóa một tài nguyên khỏi CloudFormation template hoặc cập nhật một thuộc tính bắt buộc phải tạo mới (replacement), tài nguyên cũ sẽ bị xóa (`Delete`). Ngoại lệ đáng nhớ: một số resource RDS (ví dụ `AWS::RDS::DBCluster`, `AWS::RDS::DBInstance` không thuộc cluster) mặc định `Snapshot`.
- Đặt `DeletionPolicy: Retain` (hoặc `Snapshot`) **và** `UpdateReplacePolicy` tương ứng cho RDS, DynamoDB Table, S3 Bucket mang dữ liệu thật. `DeletionPolicy` chỉ bảo vệ khi xóa; replacement do đổi thuộc tính được điều khiển bởi `UpdateReplacePolicy`.
- Change set có cột `Replacement: True` là tín hiệu phải dừng lại đọc kỹ.
- Tài nguyên `Retain` vẫn tồn tại (và tính phí) sau khi xóa stack: ghi vào danh sách retained resources.

---

## Phần 6 — Giới thiệu bài thực hành (Lab 12)

Trong bài thực hành đính kèm, bạn sẽ trực tiếp triển khai:
1. Template CloudFormation: SNS → SQS (+DLQ) và alarm, có `Parameters`/`Outputs`; lint/validate, đọc change set trước khi deploy.
2. Deploy qua **CloudFormation execution role** giới hạn quyền; kiểm chứng chức năng (publish SNS → nhận ở SQS), vì `CREATE_COMPLETE` chưa phải bằng chứng.
3. Negative case: update có xung đột thật → `UPDATE_ROLLBACK_COMPLETE`; tìm event lỗi đầu tiên; thấy thay đổi hợp lệ cùng đợt cũng bị hoàn tác.
4. Drift detection sau khi sửa tay một thuộc tính.
5. OIDC provider và role cho GitHub Actions: trust kiểm tra `aud` và `sub` theo environment, quyền chỉ đủ để chạy change set; token giả bị từ chối; tùy chọn chạy workflow thật và negative case đổi environment.
6. Cleanup đúng thứ tự (stack trước execution role), chỉ xóa OIDC provider nếu lab tạo.

👉 Mở bài hướng dẫn chi tiết tại: **[labs/12-iac-and-cicd/README.md](../labs/12-iac-and-cicd/README.md)**.

---

## Phần 7 — Troubleshooting và các lỗi phổ biến (25 phút)

### 1. Lỗi `Not authorized to perform sts:AssumeRoleWithWebIdentity` trong CI/CD
- **Nguyên nhân 1:** Chuỗi `token.actions.githubusercontent.com:sub` trong Trust Policy không khớp chính xác với ngữ cảnh thực thi của GitHub (lưu ý: phân biệt chữ hoa/thường, kiểm tra lại cú pháp `repo:<org>/<repo>:ref:refs/heads/<branch>`).
- **Nguyên nhân 2:** Trường `token.actions.githubusercontent.com:aud` bị thiếu hoặc khai báo khác với `sts.amazonaws.com`.
- **Nguyên nhân 3:** Workflow thiếu `permissions: id-token: write`, nên không xin được token.
- **Cách khắc phục:** Xác định claim `sub` thực tế (phụ thuộc `environment`, branch, sự kiện `pull_request`) rồi sửa trust policy cho khớp **giá trị cụ thể**. Không nới trust bằng `StringLike` với `*` để "debug": trong thời gian đó role mở cho repo khác.

### 2. CloudFormation Stack rơi vào trạng thái `ROLLBACK_COMPLETE` hoặc `UPDATE_ROLLBACK_FAILED`
- **Nguyên nhân:** Một tài nguyên trong template gặp lỗi khi khởi tạo (ví dụ: tên S3 bucket đã bị trùng trên toàn cầu, hoặc Security Group tham chiếu tới VPC không tồn tại). CloudFormation tự động hủy các tài nguyên đã tạo để đưa stack về trạng thái ban đầu.
- **Cách khắc phục:** Không tìm lỗi ở các event dọn dẹp. `describe-stack-events` trả event **mới nhất trước**, nên đảo ngược danh sách và lấy event `*_FAILED` **sớm nhất** của lần deploy đó, rồi đọc `ResourceStatusReason`. Stack `ROLLBACK_COMPLETE` (tạo lần đầu thất bại) không update được: xóa và tạo lại. `UPDATE_ROLLBACK_FAILED`: sửa nguyên nhân rồi `continue-update-rollback`.

### 3. Terraform State bị khóa chết (`Error acquiring the state lock`)
- **Nguyên nhân:** Một tiến trình CI/CD trước đó bị crash hoặc bị hủy giữa chừng, để lại lock (file `.tflock` trên S3 hoặc bản ghi `LockID` trong DynamoDB với cấu hình cũ), **hoặc** thật sự có một run khác đang chạy.
- **Cách khắc phục:** Tuyệt đối không dùng lệnh `terraform force-unlock` ngay lập tức! Đầu tiên phải kiểm tra xem có đồng nghiệp hoặc pipeline nào khác đang thực sự chạy hay không. Chỉ khi đã chắc chắn không có tiến trình nào đang ghi state, mới sử dụng `terraform force-unlock <LOCK_ID>` để giải phóng khóa.

---

## Câu hỏi tự kiểm tra

1. Phân biệt sự khác biệt cơ bản giữa phương pháp định nghĩa hạ tầng Mệnh lệnh (Imperative) và Khai báo (Declarative). Tại sao các công cụ IaC hiện đại đều ưu tiên mô hình Khai báo?
2. Tại sao việc lưu trữ tệp `terraform.tfstate` trên Git repository là một lỗ hổng bảo mật nghiêm trọng? Mô tả cách thức bảo vệ state file chuẩn của Terraform trên AWS.
3. Giải thích cơ chế xác thực của GitHub Actions OIDC với AWS IAM. Lợi ích vượt trội của OIDC so với việc lưu trữ IAM User Access Key trong GitHub Secrets là gì?
4. Nếu một kỹ sư thay đổi thủ công một Security Group trên AWS Console, hiện tượng gì sẽ xảy ra với mã nguồn IaC? Công cụ IaC giúp phát hiện và xử lý tình huống này như thế nào?
5. Một pipeline CI/CD thực hiện rollback tự động hạ tầng CloudFormation khi deploy thất bại. Tại sao các kỹ sư phần mềm vẫn phải thiết kế kỹ lưỡng chiến lược di chuyển dữ liệu (Database Schema Migration) theo mô hình Two-Phase (Expand & Contract)?

---

## Tài liệu tham khảo chính thức

- [AWS CloudFormation User Guide](https://docs.aws.amazon.com/AWSCloudFormation/latest/UserGuide/)
- [AWS Cloud Development Kit (AWS CDK) v2 Developer Guide](https://docs.aws.amazon.com/cdk/v2/guide/)
- [HashiCorp Terraform AWS Provider Documentation](https://registry.terraform.io/providers/hashicorp/aws/latest/docs)
- [GitHub Actions: Configuring OpenID Connect in Amazon Web Services](https://docs.github.com/en/actions/deployment/security-hardening-your-deployments/configuring-openid-connect-in-amazon-web-services)
- [AWS Security Blog: Use IAM roles to connect GitHub Actions to actions in AWS](https://aws.amazon.com/blogs/security/use-iam-roles-to-connect-github-actions-to-actions-in-aws/)

## Trạng thái kiểm chứng

- **Đối chiếu tài liệu (2026-10-31):** dạng claim `sub` của GitHub OIDC (environment/ref/pull_request); IAM không cần thumbprint cho GitHub; CloudFormation execution role (`--role-arn`), change set, drift detection, `DeletionPolicy`/`UpdateReplacePolicy`; CDK bootstrap; Terraform S3 native locking (`use_lockfile`).
- **Chưa chạy thử trên AWS/GitHub:** lệnh và workflow trong lab chưa được thực thi; output là minh họa.
- **Chi phí:** dưới $0.01 (CloudFormation không tính phí cho resource type AWS, SNS/SQS vài request, một alarm).

---

## Đáp án gợi ý

<details>
<summary>Mở để xem đáp án</summary>

1. **Imperative vs Declarative:**
   - **Mệnh lệnh (Imperative):** Chỉ định chuỗi hành động cụ thể cần làm theo thứ tự. Rất khó xử lý khi gặp lỗi giữa chừng hoặc khi cần tái lập môi trường.
   - **Khai báo (Declarative):** Chỉ định kết quả mong muốn cuối cùng. Động cơ IaC tự động tính toán khoảng cách giữa hiện trạng và mục tiêu để thực thi các bước bù trừ tối ưu, có tính lũy kế (Idempotent) cao.
2. **Bảo mật Terraform State:**
   - `terraform.tfstate` chứa toàn bộ thuộc tính của tài nguyên ở dạng bản rõ (plaintext), bao gồm cả mật khẩu RDS, secret keys, certificate private keys. Đưa lên Git sẽ làm lộ toàn bộ bí mật cho bất kỳ ai có quyền đọc repo.
   - Bảo vệ chuẩn: Lưu state trên **S3 Bucket riêng tư** (bật mã hóa SSE-KMS, bật Versioning để phục hồi khi hỏng state, bật Block Public Access), giới hạn IAM chỉ pipeline/người vận hành đọc được, và bật state locking (S3 native `use_lockfile` với Terraform ≥ 1.10, hoặc DynamoDB với cấu hình cũ).
3. **Cơ chế GitHub Actions OIDC:**
   - Thay vì cấp chìa khóa tĩnh tồn tại mãi mãi, GitHub sinh ra một chữ ký số JWT ngắn hạn chứa danh tính của repository và branch. AWS STS xác thực chữ ký này và cấp quyền tạm thời (Temporary Credentials) chỉ có giá trị trong tối đa vài chục phút.
   - Lợi ích: Triệt tiêu nguy cơ rò rỉ Access Key dài hạn; không cần quản trị vòng đời xoay vòng mật khẩu (No Key Rotation); kiểm soát quyền chặt chẽ tới từng nhánh (Branch-level authorization).
4. **Hiện tượng Configuration Drift:**
   - Khi ai đó sửa tay trên Console, hạ tầng thực tế đã bị lệch khỏi mã nguồn Git (**Drift**).
   - Động cơ IaC hỗ trợ tính năng **Drift Detection**: Nó so sánh Actual State trên AWS với State lưu trong template để hiển thị chi tiết thuộc tính bị sai lệch. Kỹ sư có thể quyết định cập nhật ngược lại mã nguồn Git hoặc chạy Apply để ghi đè hạ tầng trở về cấu hình chuẩn mực.
5. **Expand & Contract trong Database Migration:**
   - CloudFormation chỉ quản lý tài nguyên ảo ở tầng ngoài, hoàn toàn không thể và không được phép rollback dữ liệu bên trong database.
   - Nếu bản deploy làm thay đổi schema database và sau đó hạ tầng bị rollback, code cũ sẽ sập vì không đọc được schema mới. Mô hình Expand & Contract đảm bảo mọi thay đổi database luôn mang tính tương thích ngược (Backward-compatible) với ít nhất một phiên bản ứng dụng liền trước.

</details>


---

## Bổ sung — Pipeline ứng dụng và IaC toàn môi trường

[Hạ tầng dự án](../infra/laravel-order-app/README.md) chọn CloudFormation YAML: foundation giữ mạng/dữ liệu/identity, compute stack giữ EC2 hoặc ECS. CDK/Terraform là phương án so sánh; không có hai state managers cùng sở hữu resource.

[Workflow](../.github/workflows/laravel-order-app.yml) chạy test/build cho PR; deploy là workflow_dispatch từ main vào environment dev. OIDC trust giới hạn repo+environment, branch restriction được cấu hình ở GitHub environment. Deployment job dùng token AWS tạm thời, CI test job không có id-token write.

Release script đăng ký task migration ứng viên, chờ exit 0, update CloudFormation với digest, chờ services stable rồi smoke API/report/idempotency. Smoke fail thì đưa tham số stack về bản trước; schema đã migrate vẫn còn. Rollback hạ tầng và rollback dữ liệu là hai công việc khác nhau.

Tái tạo staging dùng foundation mới và cùng image digest, không copy secret dev. Certificate/domain/OIDC provider là prerequisite cấp ngoài được liệt kê, không giấu trong thao tác thủ công. Sau update tay để thử lỗi, kiểm tra drift rồi đồng bộ template.

Bài tập: service stable nhưng API export report không chạy, pipeline có đạt không? Đáp án: không; cần business smoke, vì health endpoint không kiểm chứng queue/worker/storage end-to-end.
