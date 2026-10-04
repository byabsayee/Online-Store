FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
# No committed composer.lock (kept out of git so PHPMailer/mPDF patch releases
# are picked up automatically) — resolves fresh on every image build.
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --ignore-platform-req=ext-gd

FROM php:8.3-fpm-bookworm

# --- System deps: nginx (baked in) + supervisor (runs both processes) + PHP build deps ---
# mbstring is required by mPDF (invoice PDFs); gd lets mPDF embed product
# photos on invoices.
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor libzip-dev unzip pkg-config libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mysqli opcache gd \
    && apt-get clean && rm -rf /var/lib/apt/lists/* \
    && rm -f /etc/nginx/sites-enabled/default

# --- PHP-FPM: listen on a unix socket instead of the default TCP 9000 ---
RUN mkdir -p /run/php \
    && sed -ri 's/^;?listen\s*=.*/listen = \/run\/php\/php8.3-fpm.sock/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -ri 's/^;?listen\.owner\s*=.*/listen.owner = www-data/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -ri 's/^;?listen\.group\s*=.*/listen.group = www-data/' /usr/local/etc/php-fpm.d/www.conf \
    && sed -ri 's/^;?listen\.mode\s*=.*/listen.mode = 0660/' /usr/local/etc/php-fpm.d/www.conf

COPY docker/nginx/default.conf /etc/nginx/sites-enabled/default.conf
COPY docker/nginx/fastcgi-php.conf /etc/nginx/fastcgi-php.conf
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/opcache-dev.ini
COPY docker/php/php-fpm-logging.conf /usr/local/etc/php-fpm.d/zz-logging.conf

# --- App code ---
# Baked in so the image is self-contained (this is what gets published to
# GHCR and run in production via docker-compose.yml, unmodified). For local
# development, docker-compose.override.yml bind-mounts ./src over
# /var/www/html at runtime, shadowing this copy — that's what lets you edit
# code and just refresh the browser instead of rebuilding.
COPY src/ /var/www/html/
COPY --from=vendor /app/vendor/ /var/www/html/vendor/

# SQL migrations live outside the web root; the app applies any that are new
# on the first request after a deploy (src/includes/migrate.php).
COPY sql/migrations/ /var/www/migrations/
# Fresh-install schema: imported automatically on the first request when the database is empty.
COPY sql/schema.sql /var/www/schema.sql

# Uploaded product images must be writable by the web server
RUN mkdir -p /var/www/html/uploads/products /var/www/html/uploads/branding \
    && chown -R www-data:www-data /var/www/html/uploads /var/www/html \
    && chown -R www-data:www-data /run/php

EXPOSE 80

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]