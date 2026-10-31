# syntax=docker/dockerfile:1.7
# ---------------------------------------------------------------------
# PHP 8.4 FPM image used by the app, horizon and scheduler services.
#   dev    -> local Docker Compose (source mounted from the host)
#   assets -> builds the frontend (Wayfinder needs PHP, so Node is added here)
#   prod   -> staging and production: code, vendor and built assets baked in
# ---------------------------------------------------------------------
FROM php:8.4-fpm-alpine AS base

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        bash git unzip curl icu-libs libzip libpng libjpeg-turbo freetype \
        mysql-client fcgi \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath gd intl opcache pcntl pdo_mysql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

RUN addgroup -g "${GID}" app && adduser -D -u "${UID}" -G app app

WORKDIR /var/www/html

# --- Local development -------------------------------------------------
FROM base AS dev
USER app

# --- Frontend build -----------------------------------------------------
FROM base AS assets
RUN apk add --no-cache nodejs npm
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY . .
RUN composer dump-autoload --optimize \
    && cp .env.example .env \
    && php artisan key:generate --force \
    && DB_CONNECTION=sqlite npm run build

# --- Production ---------------------------------------------------------
FROM base AS prod
COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/php/fpm-prod.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chown=app:app . /var/www/html
COPY --from=assets --chown=app:app /var/www/html/public/build /var/www/html/public/build
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && rm -rf tests node_modules resources/js .env \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R app:app storage bootstrap/cache
USER app
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

# --- Web server (Nginx with this release's public assets) --------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
