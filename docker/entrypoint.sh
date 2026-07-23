#!/bin/sh
set -e

# Service khác dùng CHUNG image này nhưng override "command:" trong compose.yaml (VD service
# "reverb" chạy "php artisan reverb:start ...") — chạy THẲNG lệnh đó, bỏ qua toàn bộ luồng
# migrate/seed/cache/supervisord bên dưới (chỉ dành cho service "app" chính, không truyền
# command nên $# = 0, rơi xuống dưới y như cũ).
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Ensure required storage directories exist (handles fresh host mounts)
mkdir -p /var/www/html/storage/framework/views \
         /var/www/html/storage/framework/cache \
         /var/www/html/storage/framework/sessions \
         /var/www/html/storage/logs \
         /var/www/html/storage/app/public

# Fix storage permissions on every start (handles host-mounted storage/)
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/resources
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache /var/www/html/resources

# Create public/storage symlink so uploaded files are accessible via URL
php artisan storage:link --force

# Discover modules/packages
php artisan package:discover --ansi

# Run migrations and cache
php artisan migrate --force --no-interaction

# Seed idempotent data (insertOrIgnore — safe to run on every deploy)
php artisan db:seed --class=RoomAdditionalServiceSeeder --force --no-interaction
php artisan db:seed --class=AuditLogPermissionSeeder --force --no-interaction

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:cache-components

exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
