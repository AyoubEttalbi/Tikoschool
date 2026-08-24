# Stage 1: Build frontend assets
FROM node:20-alpine AS build

ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT
ARG VITE_REVERB_SCHEME

ENV VITE_REVERB_APP_KEY=$VITE_REVERB_APP_KEY \
    VITE_REVERB_HOST=$VITE_REVERB_HOST \
    VITE_REVERB_PORT=$VITE_REVERB_PORT \
    VITE_REVERB_SCHEME=$VITE_REVERB_SCHEME

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# Stage 2: PHP runtime
FROM php:8.2-fpm-alpine AS php

# System dependencies + PHP extensions (merged to clean up build deps)
#
# GD codecs matter: with only libpng-dev, docker-php-ext-install gd compiles a PNG-only
# build (no JPEG, no WebP) while local Windows PHP has both enabled - so profile-image
# processing worked locally and 500'd in production. libjpeg-turbo-dev + libwebp-dev +
# freetype-dev plus the matching --with-* flags give JPEG/WebP/text parity with local.
# exif matters too: intervention/image guards orient() behind function_exists('exif_read_data'),
# so without the extension phone photos silently upload unrotated (Windows PHP has exif).
# avif matters for the same parity reason: ProfileImageService::convertAvifToWebp() calls
# imagecreatefromavif() for AVIF downloads misnamed as .png — without libavif the service
# rejects them with a clear message instead of a 500, but with it they just work.
RUN apk add --no-cache \
    supervisor \
    bash \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libavif-dev \
    freetype-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype --with-avif \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    xml \
    gd \
    exif \
    zip \
    bcmath \
    ctype \
    fileinfo \
    pcntl \
    posix \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copy application
COPY . .

# Copy built frontend assets
COPY --from=build /app/public/build /app/public/build

# Install PHP dependencies
# --prefer-dist only: --prefer-source needs git, which this image does not ship, so the
# first attempt always failed and re-downloaded every package from dist anyway — twice.
# Packagist dist archives are served from codeload.github.com, which rate-limits
# unauthenticated builds (HTTP 429 after ~60 requests); the aliyun composer mirror
# re-hosts the same metadata and dist archives without that cap.
RUN composer config repo.packagist composer https://mirrors.aliyun.com/composer/ \
    && composer install --no-dev --optimize-autoloader --prefer-dist \
    && php artisan storage:link \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/public/storage

# Docker config files
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/99-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-custom.conf
COPY docker/php/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && mkdir -p /var/log/supervisor

EXPOSE 9000 8080

CMD ["/entrypoint.sh"]

# Stage 3: Nginx
FROM nginx:alpine AS nginx

COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/public /app/public

EXPOSE 80 443
