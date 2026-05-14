#!/bin/sh
set -e

# Fix storage permissions on every start (handles host-mounted storage/)
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

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
