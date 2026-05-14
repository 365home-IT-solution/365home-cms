# ─── Stage 0: PHP dependencies ───────────────────────────────────────────────
FROM composer:2 AS vendor

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --optimize-autoloader --no-interaction --ignore-platform-reqs

# ─── Stage 1: Build frontend assets ──────────────────────────────────────────
FROM node:20-alpine AS frontend

WORKDIR /app

# Install root npm dependencies
COPY package.json package-lock.json ./
RUN npm ci

# vendor needed for tailwind.config.js (imports filament preset)
COPY --from=vendor /app/vendor ./vendor

# Copy full source (modules read ../../.env relative to their directory)
COPY . .

# Provide a minimal .env so vite module builds don't fail
RUN cp .env.example .env

# Build root assets (public/build)
RUN npm run build

# Build every module that has its own vite.config.js
RUN find Modules -maxdepth 2 -name "vite.config.js" | sort | while read cfg; do \
      dir=$(dirname "$cfg"); \
      echo "▶ Building $dir" && \
      cd "/app/$dir" && \
      npm ci && \
      npm run build && \
      cd /app; \
    done

# ─── Stage 2: PHP production runtime ─────────────────────────────────────────
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=Asia/Ho_Chi_Minh
ENV LANG=C.UTF-8

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

# Install PHP 8.3 (Ondrej PPA) + Nginx + Supervisor
RUN apt-get update && apt-get install -y --no-install-recommends \
      gnupg curl ca-certificates zip unzip git supervisor nginx \
    && curl -sS 'https://keyserver.ubuntu.com/pks/lookup?op=get&search=0xb8dc7e53946656efbce4c1dd71daeaab4ad4cab6' \
       | gpg --dearmor | tee /etc/apt/keyrings/ppa_ondrej_php.gpg > /dev/null \
    && echo "deb [signed-by=/etc/apt/keyrings/ppa_ondrej_php.gpg] https://ppa.launchpadcontent.net/ondrej/php/ubuntu noble main" \
       > /etc/apt/sources.list.d/ppa_ondrej_php.list \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
       php8.3-fpm php8.3-cli \
       php8.3-mysql php8.3-bcmath php8.3-mbstring \
       php8.3-xml php8.3-xsl php8.3-zip php8.3-gd php8.3-curl \
       php8.3-intl php8.3-imagick php8.3-redis \
       php8.3-exif php8.3-soap php8.3-sqlite3 \
       php8.3-opcache php8.3-igbinary php8.3-ffi \
    && apt-get autoremove -y && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy application source (node_modules, vendor, .env excluded via .dockerignore)
COPY . .

# Copy built assets from frontend stage
COPY --from=frontend /app/public /var/www/html/public

# Copy vendor from composer stage (autoloader paths are relative, safe to copy)
COPY --from=vendor /app/vendor /var/www/html/vendor

# Nginx config
RUN rm -f /etc/nginx/sites-enabled/default
COPY docker/nginx.conf /etc/nginx/sites-enabled/laravel

# Supervisor config
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Entrypoint
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint"]
