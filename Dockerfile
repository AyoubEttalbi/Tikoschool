# Stage 1: Build frontend assets
FROM node:20-alpine AS build

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# Stage 2: PHP runtime
FROM php:8.2-fpm-alpine AS php

# System dependencies + PHP extensions (merged to clean up build deps)
RUN apk add --no-cache \
    supervisor \
    bash \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    mbstring \
    xml \
    gd \
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
RUN composer install --no-dev --optimize-autoloader \
    && php artisan storage:link \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache /app/public/storage

# Docker config files
COPY docker/php/php.ini $PHP_INI_DIR/conf.d/99-app.ini
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
