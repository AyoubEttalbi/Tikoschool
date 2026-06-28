# Docker Deployment Plan - TikoSchool

## Files to Create

### 1. `.dockerignore`
```
# Git
.git/
.gitattributes
.gitignore

# Environment
.env
.env.*
!.env.example

# Dependencies
node_modules/
vendor/

# Build artifacts
public/build/
public/hot

# Storage (will be volume-mounted)
storage/framework/cache/data/*
storage/framework/sessions/*
storage/framework/views/*
storage/framework/testing/*
storage/logs/*
storage/debugbar/*
storage/app/public/*
!storage/app/public/.gitignore
storage/app/private/*
storage/fonts/*

# Docker config (copied explicitly in Dockerfile)
docker/

# IDE
.vscode/
.idea/
*.swp
*.swo

# OS
.DS_Store
Thumbs.db

# Temp
tmp/
*.bat
*.log

# Documentation
README.md
INVOICE_ANALYSIS.md
TikoSchool_Project_Analysis.md
TEACHER_MEMBERSHIP_PAYMENTS_README.md

# Dev scripts
example_invoice_form_integration.jsx
fix_invoice_154_specific.php
fix_missing_memberships_simple.php
payments_resync_fix.php
run-scheduler.bat
```

### 2. `Dockerfile`
```dockerfile
# Stage 1: Build frontend assets
FROM node:20-alpine AS build

WORKDIR /app

COPY package*.json ./
RUN npm ci

COPY . .
RUN npm run build

# Stage 2: PHP runtime
FROM php:8.2-fpm-alpine AS php

# System dependencies
RUN apk add --no-cache \
    supervisor \
    bash \
    curl \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    oniguruma-dev \
    $PHPIZE_DEPS

# PHP extensions
RUN docker-php-ext-install -j$(nproc) \
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
    && docker-php-ext-enable redis

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
```

### 3. `docker-compose.yml`
```yaml
services:
  nginx:
    build:
      context: .
      target: nginx
    ports:
      - "80:80"
      - "443:443"
    volumes:
      # Mount SSL certs from host (if using Let's Encrypt)
      - ./docker/nginx/certs:/etc/letsencrypt:ro
      - app_storage:/app/storage:ro
    depends_on:
      - php
    networks:
      - tikoschool
    restart: unless-stopped

  php:
    build:
      context: .
      target: php
    volumes:
      - app_storage:/app/storage
    environment:
      - APP_ENV=${APP_ENV:-production}
      - DB_HOST=mysql
      - DB_PORT=3306
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REVERB_HOST=0.0.0.0
      - REVERB_SERVER_HOST=0.0.0.0
    env_file:
      - .env
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_started
    networks:
      - tikoschool
    restart: unless-stopped

  mysql:
    image: mysql:8.0
    volumes:
      - mysql_data:/var/lib/mysql
    environment:
      MYSQL_DATABASE: ${DB_DATABASE:-tikoschool}
      MYSQL_USER: ${DB_USERNAME:-tikoschool}
      MYSQL_PASSWORD: ${DB_PASSWORD:-secret}
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD:-secret}
    ports:
      - "127.0.0.1:3306:3306"
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      timeout: 20s
      retries: 10
    networks:
      - tikoschool
    restart: unless-stopped

  redis:
    image: redis:7-alpine
    volumes:
      - redis_data:/data
    ports:
      - "127.0.0.1:6379:6379"
    networks:
      - tikoschool
    restart: unless-stopped

volumes:
  app_storage:
  mysql_data:
  redis_data:

networks:
  tikoschool:
    driver: bridge
```

### 4. `docker/nginx/default.conf`
```nginx
server {
    listen 80;
    server_name _;
    root /app/public;
    index index.php;

    location ~ /\. {
        deny all;
    }

    location ~ /\.ht {
        deny all;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|map)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass php:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
    }

    # Reverb WebSocket proxy
    location /app/ {
        proxy_pass http://php:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 86400;
    }
}
```

### 5. `docker/php/php.ini`
```ini
upload_max_filesize = 20M
post_max_size = 20M
max_execution_time = 300
max_input_time = 300
memory_limit = 256M
date.timezone = UTC
```

### 6. `docker/php/supervisord.conf`
```ini
[supervisord]
nodaemon=true
user=root
logfile=/var/log/supervisor/supervisord.log
pidfile=/var/run/supervisord.pid

[program:php-fpm]
command=php-fpm -F
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/php-fpm.log
stderr_logfile=/var/log/supervisor/php-fpm-error.log

[program:queue-worker]
command=php /app/artisan queue:work --tries=1 --timeout=90
process_name=%(program_name)s
numprocs=1
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/supervisor/queue-worker.log
stderr_logfile=/var/log/supervisor/queue-worker-error.log

[program:reverb]
command=php /app/artisan reverb:start
process_name=%(program_name)s
autostart=true
autorestart=true
user=www-data
stdout_logfile=/var/log/supervisor/reverb.log
stderr_logfile=/var/log/supervisor/reverb-error.log

[program:cron]
command=crond -f -l 2
autostart=true
autorestart=true
stdout_logfile=/var/log/supervisor/cron.log
stderr_logfile=/var/log/supervisor/cron-error.log
```

### 7. `docker/php/entrypoint.sh`
```bash
#!/bin/sh
set -e

mkdir -p /app/storage/fonts
mkdir -p /app/storage/framework/cache
mkdir -p /app/storage/framework/sessions
mkdir -p /app/storage/framework/views
mkdir -p /app/storage/logs

php /app/artisan storage:link --force || true

php /app/artisan migrate --force

if [ "${APP_ENV}" = "production" ] || [ "${APP_ENV}" = "prod" ]; then
    php /app/artisan config:cache
    php /app/artisan route:cache
    php /app/artisan view:cache
fi

chown -R www-data:www-data /app/storage /app/bootstrap/cache

echo "* * * * * www-data php /app/artisan schedule:run >> /dev/null 2>&1" > /etc/crontabs/www-data

exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
```

---

## Required .env Changes for Docker

Add/change these in your `.env`:
```
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=tikoschool
DB_USERNAME=tikoschool
DB_PASSWORD=<your-password>

REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

REVERB_HOST=0.0.0.0
REVERB_SERVER_HOST=0.0.0.0

VITE_REVERB_HOST=<your-domain-or-ip>
VITE_REVERB_PORT=80
VITE_REVERB_SCHEME=http

APP_ENV=production
APP_DEBUG=false
```

---

## Deployment Steps (on VPS)

```bash
# 1. Install Docker
curl -fsSL https://get.docker.com | sh
sudo usermod -aG docker $USER
# (log out and back in)

# 2. Clone repo
git clone https://github.com/AyoubEttalbi/Tikoschool.git
cd Tikoschool

# 3. Setup .env
cp .env.example .env
nano .env   # edit with your secrets

# 4. Build and start
docker compose up -d --build

# 5. Verify
docker compose ps
docker compose logs php   # check for errors
```

## SSL Setup (after Docker is running)

```bash
# Install certbot (on host, not in container)
sudo apt install certbot -y
sudo certbot certonly --standalone -d tiko.school -d www.tiko.school

# Create certs directory and copy/refresh
mkdir -p docker/nginx/certs
sudo cp -Lr /etc/letsencrypt docker/nginx/certs/
sudo chown -R $USER:$USER docker/nginx/certs

# Restart nginx with SSL (uncomment SSL lines in nginx config)
# Edit docker/nginx/default.conf to listen on 443 with certs

# Rebuild nginx
docker compose up -d --build nginx
```

## Useful Commands

```bash
# View logs
docker compose logs -f php        # Laravel + queue + reverb logs
docker compose logs -f nginx      # Nginx access logs

# Run artisan commands
docker compose exec php php artisan list

# Restart a specific service
docker compose restart php

# Rebuild everything
docker compose up -d --build

# Stop everything
docker compose down
```
