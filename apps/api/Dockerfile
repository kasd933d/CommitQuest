FROM composer:2 AS composer

FROM php:8.3-cli-bookworm

# System deps + PHP extensions required by Laravel
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip libzip-dev libicu-dev libpng-dev libonig-dev libxml2-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql pdo_sqlite mbstring zip bcmath intl exif pcntl sockets opcache \
    && rm -rf /var/lib/apt/lists/*

# Daily quality-of-life tools for local development
RUN apt-get update && apt-get install -y --no-install-recommends \
        default-mysql-client sqlite3 \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

# Dependencies first so image layers cache across code changes
COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress

COPY . .
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi || true

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# ---------------------------------------------------------------------------
# Production target: no dev dependencies, optimized autoloader.
# Build with: docker build --target production .
# ---------------------------------------------------------------------------
FROM php:8.3-cli-bookworm AS production

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libonig-dev libxml2-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql mbstring zip bcmath intl exif pcntl opcache \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-progress --no-dev
COPY . .
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && php artisan package:discover --ansi

EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
