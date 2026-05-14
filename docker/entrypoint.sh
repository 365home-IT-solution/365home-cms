#!/bin/sh
set -e

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
php artisan config:cache
php artisan route:cache
php artisan view:cache

exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
