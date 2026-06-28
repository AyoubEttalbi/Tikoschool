# TikoSchool VPS Deployment Guide

## Overview

This guide deploys TikoSchool (Laravel 12 + Inertia.js React + MySQL + Redis + Reverb) using Docker on a VPS.

**Architecture:** 4 Docker containers managed by docker-compose
- `nginx` — Web server + Reverb WebSocket proxy
- `php` — PHP-FPM + Queue Worker + Reverb + Cron (via Supervisor)
- `mysql` — Database
- `redis` — Cache store

---

## Prerequisites

### On Your Local Machine
- Git
- Access to the GitHub repository
- This guide file (`project-setup-guide-OP.md`)

### On the VPS
- Ubuntu 22.04+ or Debian 12+
- Root or sudo access
- Domain `tiko.school` pointing to VPS IP `31.97.196.136`
- Ports 80 and 443 open

---

## Step 1: Install Docker on VPS

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install dependencies
sudo apt install -y curl git

# Install Docker
curl -fsSL https://get.docker.com | sudo sh

# Add current user to docker group (log out & back in after)
sudo usermod -aG docker $USER

# Verify
docker --version
docker compose version
```

After running `usermod`, log out and back in for group changes to take effect.

---

## Step 2: Clone Repository

```bash
mkdir -p /var/www
cd /var/www

# Clone the repo (FORK IT FIRST if needed)
git clone https://github.com/AyoubEttalbi/Tikoschool.git
cd Tikoschool
git checkout local
```

---

## Step 3: Configure Environment

```bash
cp .env.example .env
nano .env
```

### Required .env Changes for Docker

Set these values in `.env`:

#### Database
```
DB_CONNECTION=mysql
DB_HOST=mysql                    # Docker service name (NOT 127.0.0.1)
DB_PORT=3306
DB_DATABASE=tikoschool
DB_USERNAME=tikoschool
DB_PASSWORD=<choose-a-strong-password>
```

#### Redis
```
REDIS_HOST=redis                 # Docker service name
REDIS_PORT=6379
REDIS_PASSWORD=null
CACHE_STORE=redis
SESSION_DRIVER=database
```

#### Reverb (WebSockets)
```
BROADCAST_CONNECTION=reverb
BROADCAST_DRIVER=reverb

REVERB_APP_ID=<from-old-env-or-generate-new>
REVERB_APP_KEY=<from-old-env-or-generate-new>
REVERB_APP_SECRET=<from-old-env-or-generate-new>
REVERB_HOST=tiko.school          # Your domain, NOT 0.0.0.0
REVERB_PORT=80                   # Through nginx proxy (port 80)
REVERB_SCHEME=http               # Change to https after SSL setup
REVERB_SERVER_HOST=0.0.0.0       # Bind to all interfaces inside container
REVERB_SERVER_PORT=8080

VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=tiko.school
VITE_REVERB_PORT=80
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

#### App
```
APP_NAME=Tikoschool
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tiko.school      # Change to https after SSL
APP_KEY=<generate-or-reuse-key>
```

#### Queue
```
QUEUE_CONNECTION=database
```

#### Mail (optional — default logs to file)
```
MAIL_MAILER=log
```

#### Cloudinary (required for image uploads)
```
CLOUDINARY_CLOUD_NAME=<your-cloud-name>
CLOUDINARY_API_KEY=<your-api-key>
CLOUDINARY_API_SECRET=<your-api-secret>
```

#### WhatsApp / Twilio (optional)
```
WASENDERAPI_API_KEY=<your-key>
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
```

#### APP_KEY Generation (if no existing key)
```bash
# Generate a new APP_KEY (run inside the php container later, OR use sail/artisan locally)
docker compose run --rm php php /app/artisan key:generate
```

> **Important:** The APP_KEY must never change after the first deployment. It is used to encrypt all data (sessions, cookies, encrypted values). If you lose it, all encrypted data becomes unrecoverable.

---

## Step 4: Create SSL Certificate Directory

```bash
mkdir -p docker/nginx/certs
```

This directory is gitignored. SSL certificates will be mounted here later.

---

## Step 5: Build and Start Containers

```bash
# Build images (first time: 5-10 minutes)
docker compose build

# Or build and start in one command:
docker compose up -d --build
```

### What Happens During Build

| Stage | Base Image | Action |
|---|---|---|
| `build` | `node:20-alpine` | `npm ci && npm run build` (compiles React assets) |
| `php` | `php:8.2-fpm-alpine` | Installs PHP extensions (pdo_mysql, redis, pcntl, etc.), Composer install, copies built assets |
| `nginx` | `nginx:alpine` | Copies nginx config + built public/ assets |

### What Happens on Container Start (entrypoint.sh)

1. Creates storage subdirectories (fonts, logs, cache, etc.)
2. Runs `php artisan storage:link` (symlink public/storage)
3. Runs `php artisan migrate --force`
4. If `APP_ENV=production`: caches config, routes, views
5. Sets www-data permissions on storage/ and bootstrap/cache/
6. Creates crontab for scheduler (`* * * * * schedule:run`)
7. Starts Supervisor (manages php-fpm, queue:work, reverb:start, crond)

---

## Step 6: Verify Running Containers

```bash
# Check all containers are running
docker compose ps

# Expected output:
# NAME                 IMAGE               STATUS
# tikoschool-nginx     nginx:alpine        Up (healthy)
# tikoschool-php       tikoschool-php      Up
# tikoschool-mysql     mysql:8.0           Up (healthy)
# tikoschool-redis     redis:7-alpine      Up

# Check Laravel logs
docker compose logs php --tail=50

# Test HTTP access
curl -I http://localhost

# Expected: HTTP/1.1 302 Found (redirects to /login)

# Check queue worker is running
docker compose exec php ps aux | grep queue

# Check Reverb is running
docker compose exec php ps aux | grep reverb
```

---

## Step 7: Configure SSL with Let's Encrypt

After the app is running on HTTP, set up HTTPS:

```bash
# Stop Docker containers temporarily (ports 80/443 need to be free)
docker compose down

# Install certbot on the host
sudo apt install certbot -y

# Get SSL certificate
sudo certbot certonly --standalone -d tiko.school -d www.tiko.school

# Copy certificates to the mounted certs directory
sudo cp -Lr /etc/letsencrypt docker/nginx/certs/
sudo chown -R $USER:$USER docker/nginx/certs

# Restart containers
docker compose up -d
```

### Update .env for HTTPS

```bash
# Edit .env and change:
APP_URL=https://tiko.school
REVERB_SCHEME=https

# Also update VITE_REVERB_SCHEME:
VITE_REVERB_SCHEME=https
VITE_REVERB_PORT=443
```

### Update Nginx Config for SSL

After SSL certs are in place, update `docker/nginx/default.conf` to add the HTTPS server block:

```nginx
# HTTP → HTTPS redirect
server {
    listen 80;
    server_name tiko.school www.tiko.school;
    return 301 https://$host$request_uri;
}

# HTTPS server
server {
    listen 443 ssl http2;
    server_name tiko.school www.tiko.school;

    ssl_certificate /etc/letsencrypt/live/tiko.school/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tiko.school/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    root /app/public;
    index index.php;

    location ~ /\. { deny all; }
    location ~ /\.ht { deny all; }
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt { access_log off; log_not_found off; }

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

Then rebuild and restart nginx:
```bash
docker compose up -d --build nginx
```

### Auto-renew SSL

Certbot creates a systemd timer by default. Verify:
```bash
sudo systemctl status certbot-renewal.timer
sudo certbot renew --dry-run
```

---

## Step 8: Useful Docker Commands

```bash
# View logs
docker compose logs -f php        # Follow Laravel + queue + reverb logs
docker compose logs -f nginx      # Nginx access/error logs
docker compose logs -f mysql      # MySQL logs

# Run artisan commands
docker compose exec php php artisan list
docker compose exec php php artisan cache:clear
docker compose exec php php artisan queue:status

# Create database tables (if auto-migrate was skipped)
docker compose exec php php artisan migrate --force

# Create storage symlink manually
docker compose exec php php artisan storage:link

# Restart a single service
docker compose restart php

# Rebuild and restart everything
docker compose up -d --build

# Rebuild only a specific service
docker compose up -d --build nginx

# Stop everything
docker compose down

# Stop and remove volumes (WARNING: deletes database data)
docker compose down -v

# Access the PHP container shell
docker compose exec php bash
```

---

## Container Details

### nginx
- **Image:** Custom (`nginx:alpine` with built assets)
- **Ports:** `80:80`, `443:443`
- **Depends on:** php
- **Volumes:**
  - `./docker/nginx/certs:/etc/letsencrypt:ro` (SSL certs, gitignored)
  - `app_storage:/app/storage:ro` (read-only access to uploaded files)
- **Restart policy:** unless-stopped

### php (Supervisor-managed processes)
- **Image:** Custom (`php:8.2-fpm-alpine`)
- **Internal ports:** 9000 (PHP-FPM), 8080 (Reverb WebSocket)
- **Depends on:** mysql (healthy), redis (started)
- **Volumes:**
  - `app_storage:/app/storage` (persistent storage for uploads, logs, etc.)
- **Environment overrides:**
  - `APP_ENV=production` (from compose or .env)
  - `DB_HOST=mysql`, `DB_PORT=3306`
  - `REDIS_HOST=redis`, `REDIS_PORT=6379`
  - `REVERB_SERVER_HOST=0.0.0.0` (bind to all interfaces)
- **Restart policy:** unless-stopped

#### Processes inside php container (Supervisor):

| Process | Command | Purpose |
|---|---|---|
| php-fpm | `php-fpm -F` | Serves HTTP requests proxied by nginx |
| queue-worker | `php artisan queue:work --tries=1 --timeout=90` | Processes queued jobs (WhatsApp, etc.) |
| reverb | `php artisan reverb:start` | WebSocket server for real-time features |
| cron | `crond -f -l 2` | Runs `php artisan schedule:run` every minute |

### mysql
- **Image:** `mysql:8.0`
- **Port:** `127.0.0.1:3306:3306` (not exposed externally)
- **Volume:** `mysql_data:/var/lib/mysql` (persistent database storage)
- **Environment:** Reads DB_DATABASE, DB_USERNAME, DB_PASSWORD from .env
- **Health check:** `mysqladmin ping`
- **Restart policy:** unless-stopped

### redis
- **Image:** `redis:7-alpine`
- **Port:** `127.0.0.1:6379:6379` (not exposed externally)
- **Volume:** `redis_data:/data` (persistent cache data)
- **Restart policy:** unless-stopped

---

## Volumes

All volumes are named Docker volumes (persist data across restarts):

| Volume | Mount Point | Purpose |
|---|---|---|
| `app_storage` | `/app/storage` | Uploaded files, logs, compiled views |
| `mysql_data` | `/var/lib/mysql` | Database files |
| `redis_data` | `/data` | Redis dump/append-only file |

Volumes are NOT deleted on `docker compose down`. To delete them (WARNING: data loss):
```bash
docker compose down -v
```

---

## Required .env Variables Summary

This is the complete list of variables that must be set in `.env`:

```
# App
APP_NAME=Tikoschool
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tiko.school
APP_KEY=base64:...

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=tikoschool
DB_USERNAME=tikoschool
DB_PASSWORD=<your-password>

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
CACHE_STORE=redis

# Session
SESSION_DRIVER=database

# Reverb
BROADCAST_CONNECTION=reverb
BROADCAST_DRIVER=reverb
REVERB_APP_ID=<your-app-id>
REVERB_APP_KEY=<your-app-key>
REVERB_APP_SECRET=<your-app-secret>
REVERB_HOST=tiko.school
REVERB_PORT=80
REVERB_SCHEME=http
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

# Vite
VITE_APP_NAME="${APP_NAME}"
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=tiko.school
VITE_REVERB_PORT=80
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# Queue
QUEUE_CONNECTION=database

# Cloudinary (required for image uploads)
CLOUDINARY_CLOUD_NAME=<your-cloud-name>
CLOUDINARY_API_KEY=<your-api-key>
CLOUDINARY_API_SECRET=<your-api-secret>

# Mail (optional)
MAIL_MAILER=log
MAIL_FROM_ADDRESS=noreply@tiko.school
MAIL_FROM_NAME="${APP_NAME}"

# WhatsApp (optional)
WASENDERAPI_API_KEY=<your-key>
TWILIO_ACCOUNT_SID=
TWILIO_AUTH_TOKEN=
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886
```

---

## Troubleshooting

### Container won't start
```bash
# Check logs for errors
docker compose logs php
docker compose logs nginx
```

### MySQL connection refused
```
# Check mysql is running
docker compose ps mysql
docker compose logs mysql

# Wait for health check to pass
docker compose exec mysql mysqladmin ping -h localhost
```

### Permission errors (storage/)
```bash
# Fix permissions inside container
docker compose exec php chown -R www-data:www-data /app/storage /app/bootstrap/cache
```

### Migration errors
```bash
# Run migrations manually
docker compose exec php php artisan migrate --force

# Rollback if needed
docker compose exec php php artisan migrate:rollback --step=1
```

### Nginx 502 Bad Gateway
```
# PHP-FPM might not be running. Check supervisor status:
docker compose exec php supervisorctl status

# Should show:
# php-fpm         RUNNING
# queue-worker    RUNNING
# reverb          RUNNING
# cron            RUNNING
```

### Reverb WebSocket not connecting
```
# Check Reverb is running
docker compose exec php supervisorctl status reverb

# Check Reverb logs
docker compose exec php cat /var/log/supervisor/reverb.log

# Verify nginx proxy is working
curl -I http://localhost/app/
# Expected: 426 Upgrade Required (means Reverb is responding)
```
