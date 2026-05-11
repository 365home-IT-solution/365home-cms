# 365Home CMS

<p align="center">
<img width="50px" src="https://avatars.githubusercontent.com/u/192433871?s=200&v=4" alt="Golden Bee Logo">
<br>
<img src="https://img.shields.io/badge/version-1.0.0-blue.svg">
<img src="https://img.shields.io/badge/license-proprietary-red.svg">
<img src="https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg">
<img src="https://img.shields.io/badge/laravel-10.x-FF2D20.svg">
<img src="https://img.shields.io/badge/filament-3.x-FFC027.svg">
</p>

## Giới thiệu

**365Home CMS** là hệ thống quản lý nội dung toàn diện được xây dựng trên **Laravel 10** và **FilamentPHP 3**, phát triển bởi [Golden Bee IT Solutions Co., Ltd](https://goldenbeeltd.vn). Hệ thống hỗ trợ quản lý website bất động sản / nội dung với đầy đủ các module: bài viết, sản phẩm, thanh toán, người dùng, phân quyền, chủ đề và nhiều hơn nữa.

---

## Công nghệ sử dụng

| Thành phần | Công nghệ |
|---|---|
| Backend | Laravel 10, PHP >= 8.2 |
| Admin Panel | FilamentPHP 3, Livewire 3 |
| Frontend Build | Vite 5, Tailwind CSS 3 |
| Database | MySQL 5.7+ / MariaDB 10.3+ |
| Cache / Queue | Redis (Predis) |
| Realtime | Socket.IO Client, Pusher |
| Thanh toán | PayOS |
| Media | Spatie Media Library 11 |
| Phân quyền | Spatie Laravel Permission 6 |
| Export | Maatwebsite Excel 3 |
| 2FA | Google2FA, QR Code |
| Thông báo ZNS | Module Zns tích hợp sẵn |

---

## Các Module

| Module | Chức năng |
|---|---|
| `Dashboard` | Thống kê tổng quan, biểu đồ |
| `User` | Quản lý người dùng, profile, 2FA |
| `DataPermission` | Phân quyền dữ liệu theo vai trò |
| `Post` | Quản lý bài viết, blog |
| `Page` | Quản lý trang tĩnh |
| `Product` | Quản lý sản phẩm |
| `Category` | Danh mục dùng chung |
| `Tag` | Nhãn / tag dùng chung |
| `Menu` | Quản lý menu điều hướng |
| `Component` | Các component tái sử dụng |
| `Form` | Quản lý form liên hệ |
| `Comment` | Quản lý bình luận |
| `QA` | Hỏi & Đáp |
| `Book` | Quản lý danh mục sách / tài liệu |
| `Payment` | Tích hợp thanh toán PayOS |
| `Coupon` | Mã giảm giá, khuyến mãi |
| `Promotion` | Chương trình khuyến mãi |
| `Process` | Quy trình xử lý |
| `AccessCode` | Mã truy cập / mã mời |
| `Settingcompany` | Cài đặt thông tin công ty |
| `ThemeSetting` | Cài đặt giao diện |
| `ThemeStudio` | Chỉnh sửa giao diện trực quan |
| `BladeV1Theme` | Theme Blade phiên bản 1 |
| `Zns` | Gửi thông báo ZNS (Zalo) |

---

## Yêu cầu hệ thống

- PHP >= 8.2 (với các extension: BCMath, Ctype, JSON, Mbstring, OpenSSL, PDO, Tokenizer, XML, GD/Imagick)
- Composer >= 2.x
- Node.js >= 18.x, NPM >= 9.x
- MySQL 5.7+ hoặc MariaDB 10.3+
- Redis (tùy chọn, dùng cho cache/queue)

---

## Cài đặt local

### 1. Clone repository

```bash
git clone https://github.com/your-org/365home.git
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

Chỉnh sửa file `.env`:
```env
APP_NAME="365Home"
APP_URL=http://localhost
APP_LOCALE=vi

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=365home
DB_USERNAME=root
DB_PASSWORD=your_password
```

### 4. Tạo database và chạy migration

> **Lưu ý:** Trước khi migrate, vào [app/Models/User.php](app/Models/User.php) và comment tạm các trait sau:
> ```php
> // use HasRoles;
> // use TwoFactorAuthenticatable;
> // use HasPanelShield;
> ```

```bash
# Migrate toàn bộ (core + tất cả modules)
php artisan migrate
```

Sau khi migrate xong, mở lại các trait trong `User.php`.

### 5. Chạy seeder

```bash
php artisan db:seed
```

Seeder sẽ tự động tạo:
- Roles: `super_admin`, `admin`, `content_manager`, `editor`, `seo_manager`
- User admin mặc định (xem [UsersTableSeeder.php](database/seeders/UsersTableSeeder.php))
- Dữ liệu mẫu: công ty, chi nhánh, danh mục phòng, time slots, components, settings

> **Quan trọng:** Sau khi seed, vào **Admin > Payment > Cấu hình PayOS** để điền API keys thật của bạn từ [dashboard.payos.vn](https://dashboard.payos.vn).

### 6. Tạo symlink storage và build assets

```bash
php artisan storage:link
npm run build
```

### 7. Khởi động server

```bash
php artisan serve
```

Truy cập admin: `http://localhost/admin`

---

## Migrate theo từng Module

Tất cả migrations được tổ chức theo module. Bạn có thể migrate riêng từng module khi cần.

### Cấu trúc migration

```
database/migrations/          ← Core (users, roles, media, settings...)
Modules/
  AccessCode/Database/migrations/
  Category/Database/Migrations/
  Comment/Database/Migrations/
  Component/Database/Migrations/
  Coupon/Database/migrations/
  Form/Database/Migrations/
  Menu/Database/Migrations/
  Page/Database/Migrations/
  Payment/Database/migrations/
  Post/Database/Migrations/
  Process/Database/Migrations/
  Product/Database/migrations/
  Promotion/Database/migrations/
  QA/Database/migrations/
  Settingcompany/Database/Migrations/
  Tag/Database/Migrations/
  ThemeStudio/Database/migrations/
  User/Database/Migrations/
  Zns/Database/migrations/
```

### Migrate toàn bộ

```bash
php artisan migrate
```

### Migrate chỉ một module

```bash
# Cú pháp
php artisan module:migrate {TênModule}

# Ví dụ
php artisan module:migrate Payment
php artisan module:migrate Product
php artisan module:migrate AccessCode
php artisan module:migrate Category
```

### Rollback một module

```bash
php artisan module:migrate-rollback {TênModule}

# Ví dụ
php artisan module:migrate-rollback Payment
```

### Reset và migrate lại một module

```bash
php artisan module:migrate-reset {TênModule}
php artisan module:migrate {TênModule}
```

### Kiểm tra trạng thái migration

```bash
php artisan migrate:status
```

### Thứ tự migrate đúng (nếu migrate thủ công)

Cần migrate theo thứ tự phụ thuộc sau:

```
1. Core (database/migrations/)       ← users, roles, media
2. Category                          ← cms_categories (nhiều module phụ thuộc)
3. Tag
4. Product                           ← cms_products, cms_time_slots
5. Payment                           ← cms_orders (phụ thuộc Category, Product)
6. Coupon, Promotion, AccessCode     ← phụ thuộc Payment/Product
7. Post, Page, Form, Menu, Component
8. Comment, QA
9. Process, Settingcompany
10. ThemeStudio, User, Zns
```

---

## Deploy lên VPS

### Yêu cầu VPS

- Ubuntu 20.04+ / Debian 11+
- Nginx + PHP 8.2-FPM
- MySQL / MariaDB
- Git, Composer, Node.js

### Lần đầu deploy

```bash
# SSH vào VPS
ssh user@ip_vps

# Clone project
cd /var/www
git clone https://github.com/your-org/365home.git
cd 365home

# Cài dependencies
composer install --optimize-autoloader --no-dev
npm install && npm run build

# Cấu hình môi trường
cp .env.example .env
nano .env   # Điền DB, APP_URL, Redis, PayOS...
php artisan key:generate

# Database
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link

# Phân quyền
chown -R www-data:www-data /var/www/365home
chmod -R 775 storage bootstrap/cache

# Cache cho production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Cấu hình Nginx

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/365home/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

### Các lần deploy tiếp theo

```bash
cd /var/www/365home
git pull origin main
composer install --optimize-autoloader --no-dev
php artisan migrate --force
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Hoặc dùng script [deploy.sh](deploy.sh) nếu đã tạo sẵn.

---

## Biến môi trường quan trọng

| Biến | Mô tả |
|---|---|
| `APP_KEY` | Application key (tự generate) |
| `APP_ENV` | `local` hoặc `production` |
| `DB_*` | Thông tin kết nối database |
| `REDIS_HOST` | Host Redis (nếu dùng) |
| `MAIL_*` | Cấu hình gửi email |
| `PUSHER_*` / `VITE_PUSHER_*` | Realtime với Pusher |
| `FILESYSTEM_DISK` | `public` hoặc `s3` |

---

## Tác giả

**Golden Bee IT Solutions Co., Ltd**
- Website: [goldenbeeltd.vn](https://goldenbeeltd.vn)
- Email: support@goldenbeeltd.vn

---

*License: Proprietary — Không được phân phối lại khi chưa có sự cho phép.*
