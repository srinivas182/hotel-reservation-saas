# syntax=docker/dockerfile:1.7
# ---------------------------------------------------------------------
# PHP 8.4 FPM image used by the app, horizon and scheduler services.
# The "dev" target is used by Docker Compose; "prod" is used on staging
# and production (built in S0.3).
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

# --- Production (hardened further in S0.3) -----------------------------
FROM base AS prod
COPY docker/php/opcache-prod.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY --chown=app:app . /var/www/html
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && rm -rf /var/www/html/tests
USER app
