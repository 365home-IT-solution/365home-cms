# 365Home CMS

Hướng dẫn setup source chạy ở local.

## Yêu cầu

- PHP >= 8.2 (BCMath, Ctype, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML, GD/Imagick)
- Composer >= 2.x
- Node.js >= 18.x, NPM >= 9.x
- MySQL 5.7+ / MariaDB 10.3+ (đã tạo sẵn 1 database rỗng)
- Redis (tùy chọn — mặc định local dùng cache/queue file/sync, không bắt buộc)

## Cài đặt

### 1. Clone repository

```bash
git clone <repo-url>
cd 365home
```

### 2. Cài đặt dependencies

```bash
composer install
npm install
```

### 3. Cấu hình môi trường

```bash
cp .env.example .env
php artisan key:generate
```

Chỉnh sửa file `.env`, tối thiểu cần điền đúng:
```env
APP_URL=http://localhost
APP_LOCALE=vi

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=365home
DB_USERNAME=root
DB_PASSWORD=
```

### 4. Migrate database

> **Lưu ý:** Với database rỗng hoàn toàn, trước khi migrate lần đầu, vào [app/Models/User.php](app/Models/User.php) và comment tạm các trait sau (do bảng roles/permissions chưa tồn tại):
> ```php
> // use HasRoles;
> // use TwoFactorAuthenticatable;
> // use HasPanelShield;
> ```

```bash
php artisan migrate
```

Sau khi migrate xong, mở lại các trait vừa comment trong `User.php`.

### 5. Seed dữ liệu mẫu

```bash
php artisan db:seed
```

Lệnh này tự tạo roles, quyền (Filament Shield), user admin mặc định (xem [UsersTableSeeder.php](database/seeders/UsersTableSeeder.php)) và dữ liệu mẫu (công ty, chi nhánh, danh mục phòng, time slots...).

### 6. Symlink storage và build assets

```bash
php artisan storage:link
npm run build
```

### 7. Chạy server

```bash
php artisan serve
```

Truy cập admin: `http://localhost/admin`

> Nếu dùng Laravel Herd, bỏ qua bước `php artisan serve` — Herd tự phục vụ site theo tên thư mục.

### 8. Cấu hình thanh toán (nếu cần test luồng đặt phòng)

Vào **Admin > Payment > Cấu hình PayOS**, điền API keys thật từ [dashboard.payos.vn](https://dashboard.payos.vn).

---

*License: Proprietary — Không được phân phối lại khi chưa có sự cho phép.*
