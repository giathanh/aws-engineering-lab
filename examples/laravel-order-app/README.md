# Laravel API + Vue — ứng dụng xuyên suốt

Ứng dụng này đi cùng [bài 15](../../notes/15-capstone-laravel.md) và [lab bảy mốc](../../labs/15-capstone-laravel/README.md). Dữ liệu chỉ là fixture học tập.

## Chạy local bằng PHP

Cần PHP 8.3 với PDO SQLite/PgSQL, mbstring, DOM, curl, pcntl; Composer 2; Node 22 và Python 3.10+ cho script triển khai. Chạy từ thư mục này:

```bash
composer install
cp .env.example .env
php artisan key:generate
mkdir -p storage/app/private
touch database/database.sqlite
php artisan migrate --seed
composer test
php artisan serve --host=127.0.0.1 --port=8080
```

Terminal thứ hai, cùng thư mục: `php artisan queue:work --timeout=60 --tries=3`. Terminal thứ ba:

```bash
cd web
npm ci
npm run dev
```

Mở địa chỉ Vite in ra. `alice`/`bob` là identity local, không có quyền đăng nhập AWS. Upload trả `409` đến khi cấu hình S3 ở mốc 2. Thử tạo đơn → xuất báo cáo → kiểm tra trạng thái → tải JSON. Backend giữ yêu cầu báo cáo trong outbox trước khi gửi queue. Nếu queue tạm lỗi, chạy `php artisan reports:dispatch` khi queue hoạt động lại.

## Chạy PostgreSQL và Redis bằng Docker Compose

Tạo `.env` và APP_KEY như trên, sau đó:

```bash
docker compose build
docker compose up -d db redis app nginx
docker compose exec app php artisan migrate --seed --force
docker compose up -d worker
BASE_URL=http://127.0.0.1:8080 LOCAL_SMOKE=1 python3 scripts/smoke.py
```

Mở `http://127.0.0.1:8080`. Nginx dùng network namespace của app để gọi FPM trên loopback; port được publish ở service app. Web và worker chia sẻ volume báo cáo trong Compose; trên AWS cả hai dùng S3.

Dừng: `docker compose down`. Chỉ khi muốn xóa **dữ liệu fixture local** mới dùng `docker compose down -v`.

## Hợp đồng API

| Method/path | Kết quả |
|---|---|
| `GET /up` | Liveness Laravel; không phụ thuộc DB |
| `GET /api/ready` | DB reachable, version; readiness ALB ECS |
| `GET /api/config` | Cấu hình public cho OIDC client; không có secret |
| `POST /api/orders` | `{product, quantity}` → `201`, UUID |
| `GET /api/orders`, `GET /api/orders/{id}` | Chỉ đơn thuộc identity hiện tại |
| `PATCH /api/orders/{id}` | `{quantity}`; invalidation cache |
| `POST /api/uploads` | `{content_type, bytes}` → URL PUT 2 phút; chỉ ở chế độ S3 |
| `POST /api/uploads/{id}/verify` | Kiểm tra owner, bytes và ảnh thực; ảnh gốc vẫn private |
| `POST /api/reports` | `{key}` idempotency theo owner; `202`, report ID |
| `GET /api/reports/{id}` và `.../download` | Trạng thái và JSON snapshot, kiểm tra owner |
| `GET /api/session` | Bộ đếm cookie dùng để học session qua nhiều target |
| `GET /api/fault/500`, `.../slow`, `/api/probe` | Chỉ hoạt động khi `LAB_FAULTS=true`; cần identity hợp lệ |

AWS sử dụng Cognito access token. `AUTH_MODE=local` bị chặn nếu `APP_ENV` không phải local/testing. Không đổi `APP_ENV=local` để chữa lỗi đăng nhập trên AWS. API kiểm tra quyền bằng DB kể cả khi cache hit. Cache invalidation thất bại có thể giữ dữ liệu cũ tối đa TTL 30 giây; ứng dụng này không dùng cache cho quyết định thanh toán hay phân quyền.

## Version và kiểm chứng

PHP 8.3, Laravel 12, Vue 3, Vite 6; phiên bản dependency chính xác nằm trong `composer.lock` và `web/package-lock.json`. Đây là bộ phiên bản của fixture, không phải tuyên bố bản mới nhất. Bản JWT 7 được chọn vì Composer từ chối bản 6 có advisory khi resolve.

Kết quả kiểm thử và giới hạn nằm ở [sổ kiểm chứng](../../labs/15-capstone-laravel/evidence/README.md). Không commit `.env`, token Cognito, URL presigned hoặc dữ liệu AWS thật.
