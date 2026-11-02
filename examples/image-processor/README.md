# Mini-lab — S3, Lambda resize và lỗi từng phần SQS

Liên kết [bài 09](../../notes/09-serverless.md). Thời lượng 60–90 phút; chỉ dữ liệu giả. Code đã có test local, deploy AWS chưa chạy. Region/profile theo lab 01, quyền tạo ECR/CloudFormation/Lambda/S3/SQS/IAM role và PassRole cho Lambda role thuộc stack này. Docker build linux/amd64, CLI v2, Python 3.10+.

## Local: output có thể kiểm chứng

```bash
python3 -m venv /tmp/image-lab-venv
. /tmp/image-lab-venv/bin/activate
pip install -r examples/image-processor/requirements.txt
python -m unittest discover -s examples/image-processor
```

Expected: resize 1024×800 → 512×400 JPEG, reject file hỏng/quá giới hạn, cùng key+version ra cùng output, version mới ra key khác; partial batch chỉ báo ID lỗi. Tests dùng client giả nên không chứng minh IAM hoặc S3 trigger.

## Build và deploy

Chạy từ repo root. Tên stack ngắn, chữ thường; bucket được đặt tên từ stack/account/Region để tránh dependency vòng permission→bucket→function.

```bash
export IMAGE_STACK=orders-image-lab
export IMAGE_REPO_NAME=orders-image-lab
export IMAGE_STATE=$(mktemp -d)
aws ecr create-repository --repository-name "$IMAGE_REPO_NAME" --image-tag-mutability IMMUTABLE > "$IMAGE_STATE/repo.json"
export IMAGE_REPO=$(jq -r .repository.repositoryUri "$IMAGE_STATE/repo.json")
aws ecr get-login-password | docker login --username AWS --password-stdin "${IMAGE_REPO%%/*}"
docker build --platform linux/amd64 --provenance=false -t "$IMAGE_REPO:v1" examples/image-processor
docker push "$IMAGE_REPO:v1"
export IMAGE_URI="$IMAGE_REPO@$(aws ecr describe-images --repository-name "$IMAGE_REPO_NAME" --image-ids imageTag=v1 --query 'imageDetails[0].imageDigest' --output text)"
aws cloudformation create-stack --stack-name "$IMAGE_STACK" \
  --template-body file://infra/laravel-order-app/images.yaml --capabilities CAPABILITY_IAM \
  --parameters ParameterKey=ImageUri,ParameterValue="$IMAGE_URI"
aws cloudformation wait stack-create-complete --stack-name "$IMAGE_STACK"
aws cloudformation describe-stacks --stack-name "$IMAGE_STACK" > "$IMAGE_STATE/stack.json"
imgout() { jq -r --arg k "$1" '.Stacks[0].Outputs[]|select(.OutputKey==$k)|.OutputValue' "$IMAGE_STATE/stack.json"; }
export IMAGE_BUCKET=$(imgout Bucket)
```

ECR repository và bucket là tài nguyên của lab riêng. Nếu repository đã tồn tại, kiểm tra owner trước khi tái sử dụng, không xóa/push đè tag của người khác. Template Lambda có 512 MB, timeout 30s, concurrency 2 cho từng function, queue retention 14 ngày. Trần buổi 90 phút, 20 ảnh dưới 5 MB; dừng nếu thấy invocation liên tục hoặc output ghi vào uploads/.

Chi phí theo request/duration/S3/ECR/log; dự trù buổi $1 với khối lượng nhỏ làm **ngưỡng tự dừng**, không bảo đảm hóa đơn. Ghi giá Region từ [Lambda](https://aws.amazon.com/lambda/pricing/), [S3](https://aws.amazon.com/s3/pricing/) và [SQS](https://aws.amazon.com/sqs/pricing/) trước chạy; không dùng Free Tier làm giả định.

## S3 happy path và failure destination

```bash
python - <<'PY'
from PIL import Image
Image.new('RGB',(1024,800),'blue').save('/tmp/order-image.png')
PY
aws s3api put-object --bucket "$IMAGE_BUCKET" --key uploads/photo.png --body /tmp/order-image.png --content-type image/png > "$IMAGE_STATE/upload.json"
aws s3api list-objects-v2 --bucket "$IMAGE_BUCKET" --prefix processed/
```

Chờ log cho invocation; tải một object processed/ về local rồi đọc kích thước bằng Pillow: phải 512×400 JPEG. Đổi ảnh rồi upload cùng key: VersionId đổi và tạo output khác. Gửi lại event chứa VersionId cũ: không ghi đè output phiên bản mới. Duplicate invoke có thể tạo thêm version cùng output key; không tuyên bố đúng một PUT.

Upload text vào `uploads/bad.png`: handler phải fail, Lambda retry có trần, sau đó queue `Errors` nhận invocation record. Xem CloudWatch Errors/alarm và DLQ body; không chỉ xem HTTP return của PUT. `processed/` không có notification nên không kích hoạt lại resize. Nếu thiếu log: kiểm tra notification, Lambda resource permission source bucket/account, execution role và image architecture.

## SQS partial batch

Template tạo BatchFunction dùng `handler.sqs_handler`, BatchQueue và BatchDLQ. Event source mapping đã bật ReportBatchItemFailures. Mỗi message body chứa **S3 event JSON**; không trộn với Laravel serialized job.

```bash
export BATCH_QUEUE=$(imgout BatchQueue)
python - <<'PY' > "$IMAGE_STATE/batch.json"
import json,os,boto3
s3=boto3.client('s3');bucket=os.environ['IMAGE_BUCKET']
version=s3.head_object(Bucket=bucket,Key='uploads/photo.png')['VersionId']
event={'Records':[{'s3':{'bucket':{'name':bucket},'object':{'key':'uploads/photo.png','versionId':version}}}]}
print(json.dumps({'QueueUrl':os.environ['BATCH_QUEUE'],'Entries':[{'Id':'good','MessageBody':json.dumps(event)},{'Id':'bad','MessageBody':'not-json'}]}))
PY
aws sqs send-message-batch --cli-input-json "file://$IMAGE_STATE/batch.json"
```

Expected: message good hoàn thành, message bad được retry và sang BatchDLQ sau 3 lần nhận; không cần good đi theo bad. Lambda poller không bảo đảm cả hai nằm cùng batch mọi lần; local test kiểm chứng return contract, live test kiểm chứng số message/invocation và DLQ thực tế. Không dùng demo này cho FIFO mà không đổi xử lý ordering.

## Cleanup

Lưu inventory, disable bucket notification trước khi xóa function, rồi delete stack. Nếu đã dừng giữa chừng, lấy bucket/function/queue từ describe-stacks và làm cùng thứ tự.

```bash
aws cloudformation list-stack-resources --stack-name "$IMAGE_STACK" > "$IMAGE_STATE/inventory.json"
aws s3api put-bucket-notification-configuration --bucket "$IMAGE_BUCKET" --notification-configuration '{}'
aws cloudformation delete-stack --stack-name "$IMAGE_STACK"
aws cloudformation wait stack-delete-complete --stack-name "$IMAGE_STACK"
python3 infra/laravel-order-app/scripts/cleanup-retained.py "$IMAGE_STATE/inventory.json"
# Sau khi review đúng bucket fixture:
python3 infra/laravel-order-app/scripts/cleanup-retained.py "$IMAGE_STATE/inventory.json" --apply
aws ecr delete-repository --repository-name "$IMAGE_REPO_NAME" --force
```

Kiểm tra không còn Lambda functions/mapping/queues/log groups của stack, bucket versions và ECR repo. Không xóa bucket capstone vì mini-lab này dùng bucket riêng.

## Đánh giá

Nộp kích thước ảnh gốc/kết quả, hai version cùng input key, duplicate event, ảnh hỏng→failure destination, partial batch→BatchDLQ và cleanup inventory. Phân biệt quyền ghi object, quyền S3 invoke Lambda và quyền Lambda đọc object version. Không có ảnh thực/PII trong fixture.
