# Lab 13 — Độ tin cậy và hiệu năng: SLO, load test có trần, retry storm và drill restore đo RTO/RPO

> **Lý thuyết:** [notes/13-reliability-and-performance.md](../../notes/13-reliability-and-performance.md). Đọc phần 1–5 trước khi thực hiện lab.
> **Thời lượng:** khoảng 90–120 phút, trong đó restore DynamoDB có thể mất 10–30 phút mỗi lần.
> **Ngày đối chiếu tài liệu:** 2026-10-31. Output AWS là **minh họa**. Riêng bảng kết quả mô phỏng retry ở Bước 4 là **kết quả thật** khi chạy script cục bộ với seed 42. Phần AWS của lab chưa được chạy thử trên account thật.

---

## Lab này làm gì và không làm gì

| ✅ Có | ❌ Không |
|---|---|
| SLO cho thao tác "đọc đơn hàng"; baseline trước khi tối ưu | Tối ưu trước khi đo |
| Load test **có trần** rate, concurrency, duration và điều kiện dừng; chỉ nhắm bảng lab sở hữu | Load test endpoint không phải của bạn, hoặc không giới hạn |
| Báo cáo p50/p95/p99 kèm **sample size** và phương pháp | Suy percentile từ average; cộng percentile các tầng |
| Mô phỏng retry storm: fixed retry, full jitter, retry budget (cục bộ, miễn phí) | Gây sự cố trên dịch vụ dùng chung |
| Backup on-demand + PITR; "sự cố" xóa nhầm item; restore sang **bảng tách biệt**; đo thời gian và so dữ liệu | Restore đè lên bảng đang chạy |
| Runbook, chạy lại drill theo runbook | AWS FIS (chỉ phân tích, nhánh tùy chọn trong lý thuyết) |
| Cleanup bảng, bản restore, backup | Multi-Region |

---

## Quy ước placeholder

| Biến | Ý nghĩa | Ví dụ minh họa |
|---|---|---|
| `aws-lab` | Named profile CLI đã cấu hình ở Lab 01 | `aws-lab` |
| `AWS_REGION` | Region học tập | `ap-southeast-1` |
| `TABLE` | Bảng đơn hàng | `lab13-orders` |
| `BACKUP_ARN` | On-demand backup | `arn:aws:dynamodb:...:table/lab13-orders/backup/01730...` |
| `T_GOOD` | Thời điểm ngay trước sự cố (UTC, ISO 8601) | `2026-10-31T09:41:07Z` |

Tên cố định: `lab13-orders`, `lab13-restore-backup`, `lab13-restore-pitr`. Bảng gắn tag `Project=aws-lab-13`.

---

## Điều kiện đầu vào

- [ ] Đã hoàn thành [Lab 07](../07-databases-and-cache/README.md) (DynamoDB, restore RDS) và [Lab 10](../10-observability-and-application-security/README.md) (percentile, metric).
- [ ] Công cụ: AWS CLI v2, `jq`, Python 3 (virtualenv với `boto3`).
- [ ] Quyền cần thiết (không cần `AdministratorAccess`), giới hạn trên `table/lab13-*`:
  - `dynamodb:CreateTable`, `DescribeTable`, `TagResource`, `BatchWriteItem`, `PutItem`, `DeleteItem`, `GetItem`, `Scan`, `UpdateContinuousBackups`, `DescribeContinuousBackups`, `CreateBackup`, `DescribeBackup`, `ListBackups`, `DeleteBackup`, `RestoreTableFromBackup`, `RestoreTableToPointInTime`, `DeleteTable`.
  - Restore cần thêm quyền ghi vào bảng đích (`dynamodb:PutItem`, `UpdateItem`, `DeleteItem`, `BatchWriteItem`, `GetItem`, `Query`, `Scan` trên `table/lab13-restore-*`).
- [ ] Không sử dụng tài khoản production.

### Chi phí ước tính (giá minh họa, tra lại [DynamoDB pricing](https://aws.amazon.com/dynamodb/pricing/))

| Tài nguyên | Quy mô | Đơn giá minh họa | Chi phí |
|---|---|---|---|
| On-demand read/write | < 5.000 request | ~$0.25/triệu read, ~$1.25/triệu write | < $0.01 |
| Lưu trữ 3 bảng | < 1 MB | ~$0.25/GB-tháng | < $0.001 |
| PITR, on-demand backup | < 1 MB | ~$0.20/GB-tháng, ~$0.10/GB-tháng | < $0.001 |
| Restore (2 lần) | < 1 MB | ~$0.15/GB | < $0.001 |
| **Tổng** | | | **< $0.02** |

- Trần load test trong script: ≤ 50 request/giây, ≤ 16 luồng, ≤ 120 giây, tức tối đa 6.000 request mỗi lần chạy.
- On-demand backup **không** bị xóa khi xóa bảng; phải xóa riêng (Bước 9).

---

## Sơ đồ kiến trúc Lab 13

```text
Laptop
 ├─ load.py ──GetItem (≤ 50 rps, ≤ 16 luồng)──▶ DynamoDB lab13-orders (on-demand, PITR bật)
 │                                                   │ T0: create-backup ──▶ backup lab13-pre-drill
 ├─ retry_sim.py (cục bộ, không gọi AWS)             │ T0+: ghi thêm 50 đơn
 │                                                   │ T_GOOD ── "sự cố": xóa nhầm 30 đơn
 │                                                   ▼
 └─ drill ─────────▶ restore-table-from-backup ──▶ lab13-restore-backup  (dữ liệu tại T0)
                    restore-table-to-point-in-time(T_GOOD) ──▶ lab13-restore-pitr (dữ liệu tại T_GOOD)
                    so sánh tập order_id với danh sách kỳ vọng → RPO, RTO đo được
```

---

## Bước 0 — Biến, virtualenv và SLO

```bash
export AWS_PROFILE="aws-lab"
export AWS_REGION="ap-southeast-1"
export TABLE="lab13-orders"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
python3 -m venv .venv && . .venv/bin/activate && pip install --quiet boto3
```

SLO cho lab (viết ra **trước** khi đo):

| SLI | Cách đo | SLO |
|---|---|---|
| Tỉ lệ đọc đơn thành công | request trả về không lỗi / tổng request | ≥ 99,5% |
| Độ trễ đọc đơn | thời gian `GetItem` đo phía client (laptop), sau warm-up | 95% request ≤ 150 ms |

Ngưỡng 150 ms tính cả mạng từ laptop tới Region; nếu bạn ở xa Region, đặt lại sau khi có baseline, nhưng **ghi rõ** đã đổi và vì sao.

---

## Bước 1 — Bảng, PITR và dữ liệu giả

```bash
aws dynamodb create-table --table-name "${TABLE}" \
  --attribute-definitions AttributeName=order_id,AttributeType=S \
  --key-schema AttributeName=order_id,KeyType=HASH \
  --billing-mode PAY_PER_REQUEST --tags Key=Project,Value=aws-lab-13 >/dev/null
aws dynamodb wait table-exists --table-name "${TABLE}"
aws dynamodb update-continuous-backups --table-name "${TABLE}" \
  --point-in-time-recovery-specification PointInTimeRecoveryEnabled=true >/dev/null

cat > seed.py << 'EOF'
import sys, boto3
table = boto3.resource("dynamodb").Table("lab13-orders")
start, end = int(sys.argv[1]), int(sys.argv[2])
with table.batch_writer() as w:
    for i in range(start, end + 1):
        w.put_item(Item={"order_id": f"ORD-{i:04d}", "amount": i * 1000, "customer": f"KH-{i % 37:02d}"})
print(f"đã ghi ORD-{start:04d}..ORD-{end:04d}")
EOF
python seed.py 1 200

ids() {  # in danh sách order_id của một bảng, đã sắp xếp
  aws dynamodb scan --table-name "$1" --projection-expression order_id --output json |
    jq -r '.Items[].order_id.S' | sort
}
ids "${TABLE}" | wc -l
```

*Kỳ vọng:* `200`. PITR vừa bật chỉ khôi phục được từ thời điểm bật trở đi (`EarliestRestorableDateTime`).

---

## Bước 2 — Load test có trần

```bash
cat > load.py << 'EOF'
"""Load test đọc bảng lab13-orders: trần rate, concurrency, duration và điều kiện dừng."""
import argparse, math, random, threading, time
from concurrent.futures import ThreadPoolExecutor
import boto3
from botocore.config import Config

p = argparse.ArgumentParser()
p.add_argument("--rate", type=float, default=10, help="request/giây tối đa")
p.add_argument("--concurrency", type=int, default=4)
p.add_argument("--duration", type=int, default=30, help="giây")
p.add_argument("--slo-ms", type=float, default=150)
a = p.parse_args()
assert a.rate <= 50 and a.concurrency <= 16 and a.duration <= 120, "vượt trần của lab"

ddb = boto3.client("dynamodb", config=Config(max_pool_connections=a.concurrency,
                                             retries={"mode": "standard", "max_attempts": 3}))
for _ in range(5):  # warm-up: DNS, TLS, connection pool; không tính vào kết quả
    ddb.get_item(TableName="lab13-orders", Key={"order_id": {"S": "ORD-0001"}})

lat, errors, lock, stop = [], 0, threading.Lock(), threading.Event()

def one():
    global errors
    t0 = time.perf_counter()
    try:
        ddb.get_item(TableName="lab13-orders", Key={"order_id": {"S": f"ORD-{random.randint(1, 200):04d}"}})
        ok = True
    except Exception:
        ok = False
    ms = (time.perf_counter() - t0) * 1000
    with lock:
        if ok:
            lat.append(ms)
        else:
            errors += 1
        done = len(lat) + errors
        if done >= 50 and errors / done > 0.05:   # điều kiện dừng: lỗi > 5%
            stop.set()

def pct(xs, q):  # nearest-rank percentile
    return xs[max(0, math.ceil(q / 100 * len(xs)) - 1)]

start, sent = time.time(), 0
with ThreadPoolExecutor(a.concurrency) as pool:
    while time.time() - start < a.duration and not stop.is_set():
        pool.submit(one); sent += 1
        time.sleep(1 / a.rate)
elapsed = time.time() - start
lat.sort()
n = len(lat)
print(f"cấu hình: rate≤{a.rate}/s concurrency={a.concurrency} duration={a.duration}s  {'(DỪNG SỚM: lỗi > 5%)' if stop.is_set() else ''}")
print(f"mẫu: {n} thành công, {errors} lỗi, đạt {n / elapsed:.1f} req/s")
if n:
    print(f"avg {sum(lat)/n:.1f}  p50 {pct(lat,50):.1f}  p95 {pct(lat,95):.1f}  p99 {pct(lat,99):.1f}  max {lat[-1]:.1f} (ms)")
    print(f"SLO độ trễ: {sum(x <= a.slo_ms for x in lat) / n * 100:.2f}% request ≤ {a.slo_ms:.0f} ms (mục tiêu ≥ 95%)")
    print(f"SLO thành công: {n / (n + errors) * 100:.2f}% (mục tiêu ≥ 99,5%)")
    if n < 1000:
        print("lưu ý: < 1.000 mẫu, p99 chỉ dựa trên vài request chậm nhất, chưa đủ tin cậy")
EOF

python load.py --rate 10 --concurrency 1 --duration 30     # baseline
python load.py --rate 40 --concurrency 8 --duration 60     # tải cao hơn, vẫn trong trần
```

*Kỳ vọng (minh họa, phụ thuộc mạng của bạn):*

```text
cấu hình: rate≤10.0/s concurrency=1 duration=30s
mẫu: 296 thành công, 0 lỗi, đạt 9.9 req/s
avg 38.2  p50 34.9  p95 52.7  p99 118.4  max 241.0 (ms)
SLO độ trễ: 99.66% request ≤ 150 ms (mục tiêu ≥ 95%)
SLO thành công: 100.00% (mục tiêu ≥ 99,5%)
lưu ý: < 1.000 mẫu, p99 chỉ dựa trên vài request chậm nhất, chưa đủ tin cậy
```

Ghi lại cả hai lần chạy cùng **sample size**. Đọc kết quả:

- `avg` gần `p50`, nhưng `p99`/`max` cao hơn nhiều: average che mất đuôi chậm.
- Độ trễ đo ở client gồm mạng laptop → Region; metric `SuccessfulRequestLatency` của DynamoDB trong CloudWatch chỉ tính phía server, nên thường thấp hơn nhiều. Hai con số trả lời hai câu hỏi khác nhau.
- Nếu tăng tải mà p95 tăng mạnh nhưng DynamoDB không throttle, nghẽn có thể nằm ở **client** (CPU laptop, số kết nối pool, GIL của Python), không phải ở dịch vụ. Load generator cũng là một thành phần phải theo dõi.

---

## Bước 3 — Vì sao không cộng percentile

Không cần AWS. Giả sử một request gọi tuần tự hai dependency độc lập, mỗi cái có 98% lần gọi nhanh (10–30 ms) và 2% lần gọi chậm (100–300 ms):

```bash
python3 - << 'EOF'
import random
random.seed(1)
def dep():  # 98% nhanh (10–30 ms), 2% chậm (100–300 ms)
    return random.uniform(10, 30) if random.random() < 0.98 else random.uniform(100, 300)
a = sorted(dep() for _ in range(100000)); b = [dep() for _ in range(100000)]
e2e = sorted(x + y for x, y in zip(a, b))
p = lambda xs, q: xs[int(q * len(xs)) - 1]
print(f"p99 một dependency ≈ {p(a, .99):.0f} ms; cộng hai p99 = {2 * p(a, .99):.0f} ms; p99 end-to-end đo được = {p(e2e, .99):.0f} ms")
print(f"tỉ lệ request end-to-end chạm ít nhất một lần chậm: {sum(x > 60 for x in e2e) / len(e2e) * 100:.1f}%")
EOF
```

*Kết quả thật (Python 3, seed 1):*

```text
p99 một dependency ≈ 204 ms; cộng hai p99 = 409 ms; p99 end-to-end đo được = 273 ms
tỉ lệ request end-to-end chạm ít nhất một lần chậm: 4.1%
```

Cộng p99 của từng tầng **không** ra p99 end-to-end (có thể sai cả hai chiều tùy phân phối); với nhiều dependency, xác suất ít nhất một tầng chậm tăng lên (ở đây 4,1% thay vì 2%). Chỉ có cách đo percentile trên chính độ trễ end-to-end.

---

## Bước 4 — Mô phỏng retry storm: fixed, jitter, retry budget

Mô phỏng chạy cục bộ, không gọi AWS, có seed nên lặp lại được. Dependency chịu 60 attempt/giây, sự cố hoàn toàn từ giây 2 tới giây 5; tải nghiệp vụ đều 30 request/giây trong 10 giây; mỗi request tối đa 5 attempt.

```bash
cat > retry_sim.py << 'EOF'
"""Mô phỏng retry storm (chạy cục bộ, không gọi AWS, kết quả lặp lại được nhờ seed).

1 tick = 100 ms. Dependency xử lý tối đa CAPACITY attempt/tick và sự cố hoàn toàn từ giây 2 tới giây 5.
Tải nghiệp vụ đều 30 request/giây trong 10 giây (bằng nửa capacity). Mỗi request tối đa 5 attempt.
"""
import random

CAPACITY, OUTAGE, ARRIVAL_TICKS, PER_TICK, MAX_ATTEMPTS = 6, range(20, 50), 100, 3, 5


def run(strategy, seed=42):
    rnd = random.Random(seed)
    pending = {t: [(t * PER_TICK + i, 1) for i in range(PER_TICK)] for t in range(ARRIVAL_TICKS)}
    tokens, ok, failed, attempts, peak, overloaded_ticks, last = 10.0, 0, 0, 0, 0, 0, 0
    tick = 0
    while pending:
        arrivals = pending.pop(tick, [])
        rnd.shuffle(arrivals)
        attempts += len(arrivals)
        peak = max(peak, len(arrivals))
        if tick not in OUTAGE and len(arrivals) > CAPACITY:
            overloaded_ticks += 1
        served = 0
        for c, n in arrivals:
            if n == 1:
                tokens = min(tokens + 0.1, 10.0)       # mỗi request mới nạp 0,1 token: retry ≤ ~10% tải
            if tick not in OUTAGE and served < CAPACITY:
                served += 1; ok += 1; last = tick; continue
            if n >= MAX_ATTEMPTS:
                failed += 1; last = tick; continue
            if strategy == "jitter+budget":
                if tokens < 1:
                    failed += 1; last = tick; continue  # hết budget: fail nhanh, không dồn thêm tải
                tokens -= 1
            if strategy == "fixed":
                delay = 10                              # đúng 1 giây, không jitter
            else:
                delay = 1 + rnd.randrange(min(100, 5 * 2 ** n))   # full jitter, base 0,5 s, trần 10 s
            pending.setdefault(tick + delay, []).append((c, n + 1))
        tick += 1
    return attempts, peak, overloaded_ticks, ok, failed, last / 10


print(f"Tải 30 req/s × 10 s = {ARRIVAL_TICKS * PER_TICK} request; dependency 60 attempt/s, sự cố giây 2–5\n")
print(f"{'chiến lược':<15}{'tổng attempt':>13}{'đỉnh/100ms':>12}{'tick quá tải':>14}{'thành công':>12}{'thất bại':>10}{'xong lúc (s)':>14}")
for s in ("fixed", "jitter", "jitter+budget"):
    a, p, o, k, f, t = run(s)
    print(f"{s:<15}{a:>13}{p:>12}{o:>14}{k:>12}{f:>10}{t:>14.1f}")
EOF
python3 retry_sim.py
```

*Kết quả thật (Python 3, seed 42):*

```text
Tải 30 req/s × 10 s = 300 request; dependency 60 attempt/s, sự cố giây 2–5

chiến lược      tổng attempt  đỉnh/100ms  tick quá tải  thành công  thất bại  xong lúc (s)
fixed                    567          12            20         297         3           9.9
jitter                   554          14            14         297         3          13.2
jitter+budget            318           6             0         211        89           9.9
```

Đọc kết quả (cột "tick quá tải" đếm các 100 ms **sau khi** dependency đã hồi phục mà vẫn nhận quá 6 attempt):

- **fixed:** mọi retry dồn đúng 1 giây sau lần lỗi trước, nên khi dependency vừa hồi phục, nó nhận gấp đôi capacity trong 20 tick liên tiếp. Với hệ thống thật, đợt dồn này có thể làm dependency sập lại.
- **jitter:** cùng số attempt, ít tick quá tải hơn (14) nhưng **không** giới hạn được đỉnh và kéo dài thời gian hoàn tất. Jitter rải tải, không giảm tải.
- **jitter + budget:** tổng tải chỉ cao hơn tải gốc khoảng 6%, dependency **không bao giờ** quá tải sau khi hồi phục; đổi lại 89 request fail nhanh trong sự cố thay vì chờ retry. Đó là đánh đổi có chủ đích: bảo vệ dependency và trả lỗi sớm cho client (kèm backpressure như `429`/`Retry-After`) thay vì để mọi người cùng chậm.
- Thử thay `seed`, `CAPACITY`, thời gian sự cố để xem kết luận nào đứng vững. Retry nhiều tầng (client → API → service → DB, mỗi tầng 3 lần) nhân số attempt lên tới 3⁴ = 81 lần: chỉ retry ở **một** tầng.

---

## Bước 5 — Backup on-demand, ghi thêm, rồi "sự cố"

```bash
BACKUP_ARN=$(aws dynamodb create-backup --table-name "${TABLE}" --backup-name lab13-pre-drill \
  --query BackupDetails.BackupArn --output text)
until [ "$(aws dynamodb describe-backup --backup-arn "${BACKUP_ARN}" \
  --query BackupDescription.BackupDetails.BackupStatus --output text)" = AVAILABLE ]; do sleep 5; done
echo "backup AVAILABLE"

python seed.py 201 250                     # 50 đơn mới SAU khi backup
ids "${TABLE}" > expected.txt; wc -l < expected.txt
sleep 2
T_GOOD=$(date -u +%Y-%m-%dT%H:%M:%SZ)      # thời điểm "còn tốt", ngay trước sự cố
sleep 2

# Sự cố: script bảo trì xóa nhầm 30 đơn
for i in $(seq 101 130); do
  aws dynamodb delete-item --table-name "${TABLE}" --key "{\"order_id\":{\"S\":\"ORD-$(printf %04d "$i")\"}}"
done
T_INCIDENT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
echo "T_GOOD=${T_GOOD} T_INCIDENT=${T_INCIDENT}"; ids "${TABLE}" | wc -l
```

*Kỳ vọng:* `expected.txt` có 250 dòng; sau sự cố bảng chính còn 220.

---

## Bước 6 — Drill: restore sang bảng tách biệt và đo

Bắt đầu đồng hồ RTO tại thời điểm "phát hiện" sự cố. Chạy hai cách khôi phục song song để so sánh:

```bash
DRILL_START=$(date +%s)
aws dynamodb restore-table-from-backup --target-table-name lab13-restore-backup \
  --backup-arn "${BACKUP_ARN}" --query 'TableDescription.TableStatus' --output text

# PITR chỉ khôi phục tới LatestRestorableDateTime (thường trễ hiện tại khoảng 5 phút): chờ cho tới khi vượt T_GOOD
pitr_ready() {  # LatestRestorableDateTime (có múi giờ) >= T_GOOD (UTC)?
  python3 -c 'import sys, datetime as d; f = lambda s: d.datetime.fromisoformat(s.replace("Z", "+00:00")); sys.exit(0 if f(sys.argv[1]) >= f(sys.argv[2]) else 1)' \
    "$(aws dynamodb describe-continuous-backups --table-name "${TABLE}" \
       --query ContinuousBackupsDescription.PointInTimeRecoveryDescription.LatestRestorableDateTime --output text)" "${T_GOOD}"
}
until pitr_ready; do echo "$(date +%T) chờ PITR bắt kịp T_GOOD..."; sleep 30; done
aws dynamodb restore-table-to-point-in-time --source-table-name "${TABLE}" \
  --target-table-name lab13-restore-pitr --restore-date-time "${T_GOOD}" \
  --query 'TableDescription.TableStatus' --output text

for T in lab13-restore-backup lab13-restore-pitr; do
  aws dynamodb wait table-exists --table-name "${T}"
  echo "${T} ACTIVE sau $(( ($(date +%s) - DRILL_START) / 60 )) phút"
done
```

*Kỳ vọng:* cả hai in `CREATING`, rồi `ACTIVE` sau vài phút tới vài chục phút. Ghi lại số phút: đó là RTO **đo được** cho phần dữ liệu, chưa tính thời gian ra quyết định và chuyển ứng dụng sang bảng mới.

Nếu `pitr_ready` báo lỗi định dạng giờ (Python cũ), chỉ cần chờ 6 phút sau `T_GOOD` rồi chạy lệnh `restore-table-to-point-in-time`.

Kiểm tra độ đầy đủ bằng **tập ID**, không chỉ đếm:

```bash
for T in lab13-restore-backup lab13-restore-pitr; do
  ids "${T}" > "${T}.txt"
  echo "== ${T}: $(wc -l < "${T}.txt") đơn; thiếu so với trước sự cố: $(comm -23 expected.txt "${T}.txt" | wc -l)"
  comm -23 expected.txt "${T}.txt" | head -3
done
aws dynamodb describe-continuous-backups --table-name lab13-restore-pitr \
  --query 'ContinuousBackupsDescription.PointInTimeRecoveryDescription.PointInTimeRecoveryStatus' --output text
```

*Kỳ vọng (minh họa):*

```text
== lab13-restore-backup: 200 đơn; thiếu so với trước sự cố: 50
ORD-0201
ORD-0202
ORD-0203
== lab13-restore-pitr: 250 đơn; thiếu so với trước sự cố: 0
DISABLED
```

- **Backup:** RPO = khoảng thời gian từ lúc backup tới sự cố; mất toàn bộ 50 đơn ghi sau backup.
- **PITR tới `T_GOOD`:** không mất đơn nào ghi trước `T_GOOD`; RPO tính bằng giây.
- Bảng restore **không** mang theo PITR (`DISABLED`), TTL, auto scaling, tag, alarm, IAM policy, stream. Runbook phải có bước cấu hình lại. Restore xong cũng chưa xong sự cố: còn phải quyết định chuyển ứng dụng sang bảng mới hay chép 30 đơn bị xóa ngược về bảng chính.

---

## Bước 7 — Runbook và chạy lại drill

```bash
cat > runbook-lab13.md << EOF
# Runbook: khôi phục đơn hàng bị xóa nhầm (DynamoDB lab13-orders)

Đo được ngày $(date +%F): restore PITR ~<SỐ PHÚT> phút, backup ~<SỐ PHÚT> phút. RPO PITR: vài giây. RPO backup: tới lần backup gần nhất.

1. Xác định thời điểm sự cố (CloudTrail \`DeleteItem\`/\`BatchWriteItem\`, log ứng dụng); chọn T_GOOD trước đó vài giây.
2. Kiểm tra \`LatestRestorableDateTime\` ≥ T_GOOD.
3. \`restore-table-to-point-in-time\` sang bảng mới \`<tên>-restore-<ngày>\`. Không restore đè.
4. Chờ ACTIVE; so tập khóa với nguồn tham chiếu; lấy danh sách item bị mất.
5. Quyết định: chép item bị mất về bảng chính (ưu tiên, ít rủi ro) hoặc chuyển ứng dụng sang bảng mới.
6. Nếu chuyển bảng: bật lại PITR, TTL, auto scaling, tag, alarm, IAM, stream.
7. Ghi lại thời gian từng bước; xóa bảng restore khi xong.
EOF
cat runbook-lab13.md
```

Thay `<SỐ PHÚT>` bằng số đo được. Sau đó **chạy lại drill chỉ theo runbook**: xóa bảng `lab13-restore-pitr` (`aws dynamodb delete-table --table-name lab13-restore-pitr` rồi `wait table-not-exists`), làm lại bước 2–4 của runbook, đo lại thời gian. Nếu lần hai cần thêm bước không có trong runbook, sửa runbook. Chép 30 đơn bị xóa từ `lab13-restore-pitr` về bảng chính là bài tập mở rộng.

---

## Bước 8 — (Tham khảo) AWS FIS

Không chạy trong lab. Với hạ tầng của Lab 05/08, AWS Fault Injection Service có thể dừng instance/task hoặc chèn độ trễ mạng, nhưng **bắt buộc** có stop condition (CloudWatch alarm), target lọc theo tag lab, role FIS quyền hẹp và chỉ chạy trên account học tập. Xem lý thuyết Phần 4.

---

## Bước 9 — Dọn dẹp

```bash
for T in lab13-restore-backup lab13-restore-pitr "${TABLE}"; do
  aws dynamodb delete-table --table-name "${T}" >/dev/null 2>&1
done
for T in lab13-restore-backup lab13-restore-pitr "${TABLE}"; do
  aws dynamodb wait table-not-exists --table-name "${T}"
done
# On-demand backup KHÔNG tự xóa theo bảng
aws dynamodb delete-backup --backup-arn "${BACKUP_ARN}" >/dev/null
deactivate 2>/dev/null; cd ~ && rm -rf "${LAB_DIR}"
```

Giữ lại `runbook-lab13.md` và kết quả đo trước khi xóa `LAB_DIR` nếu cần nộp bài.

### Kiểm tra inventory sau cleanup

```bash
echo "== Bảng";   aws dynamodb list-tables --query "TableNames[?starts_with(@,'lab13')]" --output text
echo "== Backup"; aws dynamodb list-backups --query "BackupSummaries[?starts_with(TableName,'lab13')].[BackupName,BackupStatus]" --output text
```

*Kỳ vọng:* cả hai rỗng (backup đang xóa có thể hiện `DELETED` trong thời gian ngắn).

### Nếu lab dừng giữa chừng hoặc mất shell

```bash
export AWS_PROFILE="aws-lab" AWS_REGION="ap-southeast-1" TABLE="lab13-orders"
export LAB_DIR=$(mktemp -d) && cd "${LAB_DIR}"
BACKUP_ARN=$(aws dynamodb list-backups --query "BackupSummaries[?BackupName=='lab13-pre-drill'].BackupArn | [0]" --output text)
echo "BACKUP_ARN=${BACKUP_ARN}"
```

Sau đó chạy lại Bước 9 (bỏ dòng `deactivate`). Nếu `BACKUP_ARN` là `None`, bỏ qua `delete-backup`. Lệnh báo `ResourceNotFoundException`/`BackupNotFoundException` nghĩa là đã xóa. Bảng đang `CREATING` (restore dở) phải chờ `ACTIVE` mới xóa được.

---

## Checklist hoàn thành

- [ ] SLO viết ra trước khi đo; baseline có sample size và phương pháp đo.
- [ ] Load test chạy trong trần (rate/concurrency/duration) và có điều kiện dừng; giải thích được khác biệt giữa độ trễ client và metric phía server.
- [ ] Giải thích được vì sao không cộng percentile các tầng (Bước 3).
- [ ] Đọc được bảng mô phỏng: jitter rải tải nhưng không giảm tải; budget bảo vệ dependency, đổi bằng fail nhanh.
- [ ] Restore backup và PITR sang bảng tách biệt; đo thời gian từng cách; so tập ID và chỉ ra RPO khác nhau.
- [ ] Biết các cấu hình không đi theo bảng restore.
- [ ] Runbook có số đo thật; đã chạy lại drill theo runbook.
- [ ] Cleanup xong; không còn bảng hay backup `lab13`.

---

## Troubleshooting nhanh

| Triệu chứng | Nguyên nhân khả dĩ | Bằng chứng / Khắc phục |
|---|---|---|
| Latency p95 tăng khi tăng concurrency | Nghẽn ở load generator (CPU, pool), mạng, hoặc throttle phía DynamoDB | `top` trên laptop; metric `ThrottledRequests` và `SuccessfulRequestLatency` của bảng; so client vs server latency |
| Load test dừng sớm do lỗi > 5% | Throttle, credentials hết hạn, mạng | In exception trong `one()` khi debug; `aws sts get-caller-identity` |
| Retry storm ở hệ thống thật | Retry nhiều tầng, không jitter, không budget | Đếm attempt/request ở từng tầng; chỉ retry ở một tầng; thêm budget và circuit breaker |
| `restore-table-to-point-in-time` báo thời điểm không hợp lệ | `T_GOOD` trước `EarliestRestorableDateTime` hoặc sau `LatestRestorableDateTime` | `describe-continuous-backups`; chờ hoặc chọn thời điểm khác |
| `TableAlreadyExistsException` khi restore | Bảng đích đã tồn tại | Dùng tên mới hoặc xóa bảng restore cũ và chờ `table-not-exists` |
| Restore lâu hơn mong đợi | Thời gian restore không tỉ lệ đơn giản với kích thước; có chi phí cố định | Ghi số đo thật vào runbook; đó là lý do phải drill |
| Bảng restore thiếu dữ liệu | Chọn sai thời điểm (backup cũ, `T_GOOD` sau sự cố), hoặc so bằng count thay vì tập khóa | So tập ID với nguồn tham chiếu; kiểm tra lại mốc thời gian theo UTC |

---

## Tiếp theo

Bạn đã có SLO, số đo có phương pháp, hiểu đánh đổi của retry và một drill restore với RTO/RPO đo được. Bài cuối: **[Bài 14 — Kiến trúc và quản trị nâng cao: Organizations, SCP, Control Tower, WAF](../../notes/14-architecture-and-governance.md)**.

---

## Nhánh Laravel theo lộ trình gốc

Lab dịch vụ ở trên được nối với [dự án Laravel + Vue](../15-capstone-laravel/README.md). Đọc phần bổ sung trong bài lý thuyết 13 và bảng mốc của dự án để chọn đúng prerequisite. Đầu ra bắt buộc gồm code/fixture, happy path, negative case và inventory sau cleanup; không dùng output minh họa làm bằng chứng đã chạy.
