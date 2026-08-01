# Docker to Native Ubuntu Migration Plan

## Overview

Migrate TikoSchool from Docker containers to native Ubuntu 24.04 systemd services.
This saves ~682 MB RAM (Docker daemon overhead) and allows better resource tuning.

**Services to migrate:** nginx, PHP-FPM, MySQL 8.0, Redis 7, Laravel Reverb, Laravel Queue Worker, Cron

---

## Prerequisites

- VPS: Ubuntu 24.04, 1 vCPU, 3.8 GB RAM
- Current app path: `/var/www/Tikoschool`
- MySQL user: `tikoschool`, password: `***REDACTED***`
- Domain: `tiko.school` (SSL via Certbot)

---

## Step 1: Backup Everything

```bash
# Backup database
mysqldump -u root -p tikoschool > /tmp/tikoschool_full_backup.sql

# Backup Docker volumes
docker compose -f /var/www/Tikoschool/docker-compose.yml exec -T mysql mysqldump -u root -p'***REDACTED***' --all-databases > /tmp/mysql_all_backup.sql

# Backup app files
tar czf /tmp/tikoschool_app_backup.tar.gz -C /var/www Tikoschool --exclude='.git'

# Backup Docker nginx config (for reference)
cp /var/www/Tikoschool/docker/nginx/default.conf /tmp/nginx_default_conf.bak
```

---

## Step 2: Install Native Services

```bash
# Update system
apt update && apt upgrade -y

# Install MySQL 8.0
apt install -y mysql-server
systemctl enable mysql
systemctl start mysql

# Install PHP 8.2 + FPM + extensions
apt install -y php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-gd php8.2-zip php8.2-bcmath php8.2-redis \
  php8.2-redis php8.2-dom php8.2-sqlite3

# Install Redis
apt install -y redis-server
systemctl enable redis-server
systemctl start redis-server

# Install Nginx
apt install -y nginx
systemctl enable nginx
systemctl start nginx

# Install Composer
curl -sS https://getcomposer.org/installer | php
mv composer.phar /usr/local/bin/composer

# Install Node.js 20 (for asset builds)
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt install -y nodejs
```

---

## Step 3: MySQL Setup

```bash
# Login and set up
mysql -u root

# In MySQL shell:
CREATE DATABASE tikoschool CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tikoschool'@'localhost' IDENTIFIED BY '***REDACTED***';
GRANT ALL PRIVILEGES ON tikoschool.* TO 'tikoschool'@'localhost';
ALTER USER 'root'@'localhost' IDENTIFIED BY '***REDACTED***';
FLUSH PRIVILEGES;
EXIT;

# Import database
mysql -u root -p tikoschool < /tmp/tikoschool_full_backup.sql
```

Copy the optimized MySQL config:

```bash
cp /var/www/Tikoschool/docker/mysql/my.cnf /etc/mysql/conf.d/tikoschool.cnf
systemctl restart mysql
```

---

## Step 4: App Setup

```bash
# Create app directory
mkdir -p /var/www/Tikoschool
cd /var/www/Tikoschool

# Extract backup
tar xzf /tmp/tikoschool_app_backup.tar.gz --strip-components=1

# Install PHP dependencies
composer install --no-dev --optimize-autoloader

# Install Node dependencies and build assets
npm ci
npm run build

# Set permissions
chown -R www-data:www-data /var/www/Tikoschool/storage /var/www/Tikoschool/bootstrap/cache
chmod -R 775 /var/www/Tikoschool/storage /var/www/Tikoschool/bootstrap/cache

# Create storage symlink
php /var/www/Tikoschool/artisan storage:link

# Cache configs
php /var/www/Tikoschool/artisan config:cache
php /var/www/Tikoschool/artisan route:cache
php /var/www/Tikoschool/artisan view:cache
```

---

## Step 5: PHP-FPM Pool Config

Create `/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
[www]
user = www-data
group = www-data
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = static
pm.max_children = 5
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 5
pm.max_requests = 500

request_terminate_timeout = 300s
request_slowlog_timeout = 5s
slowlog = /var/log/php8.2-fpm-slow.log

php_admin_value[memory_limit] = 256M
php_admin_value[upload_max_filesize] = 20M
php_admin_value[post_max_size] = 20M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_time] = 300
php_admin_value[realpath_cache_size] = 4096K
php_admin_value[realpath_cache_ttl] = 600
php_admin_value[opcache.memory_consumption] = 128
php_admin_value[opcache.interned_strings_buffer] = 16
php_admin_value[opcache.max_accelerated_files] = 10000
php_admin_value[opcache.revalidate_freq] = 2
php_admin_value[opcache.enable_cli] = 0
php_admin_value[opcache.jit] = tracing
php_admin_value[opcache.jit_buffer_size] = 64M
```

```bash
# Also set in /etc/php/8.2/fpm/php.ini:
# opcache.enable = On (should be default)
# opcache.memory_consumption = 128
# opcache.interned_strings_buffer = 16
# opcache.max_accelerated_files = 10000
# opcache.jit = tracing
# opcache.jit_buffer_size = 64M

systemctl restart php8.2-fpm
```

---

## Step 6: Nginx Server Block

Create `/etc/nginx/sites-available/tikoschool`:

```nginx
set_real_ip_from 0.0.0.0/0;
real_ip_header X-Forwarded-For;
real_ip_recursive on;

server {
    listen 80;
    server_name tiko.school www.tiko.school;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name tiko.school www.tiko.school;
    root /var/www/Tikoschool/public;
    index index.php;

    client_max_body_size 20M;

    # SSL (Certbot managed)
    ssl_certificate /etc/letsencrypt/live/tiko.school/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/tiko.school/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    # Security
    location ~ /\. { deny all; }
    location ~ /\.ht { deny all; }

    # Static assets
    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    location ~* \.(jpg|jpeg|png|gif|ico|css|js|svg|woff|woff2|ttf|eot|map)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }

    # Laravel
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP-FPM via unix socket (faster than TCP)
    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
        fastcgi_param HTTP_X_FORWARDED_FOR $proxy_add_x_forwarded_for;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    }

    # Laravel Reverb WebSocket
    location /app/ {
        proxy_pass http://127.0.0.1:8080;
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

```bash
ln -s /etc/nginx/sites-available/tikoschool /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
```

---

## Step 7: Systemd Services

### Laravel Queue Worker: `/etc/systemd/system/tikoschool-queue.service`

```ini
[Unit]
Description=TikoSchool Laravel Queue Worker
After=network.target mysql.service php8.2-fpm.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/Tikoschool
ExecStart=/usr/bin/php artisan queue:work --tries=1 --timeout=90
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

### Laravel Reverb: `/etc/systemd/system/tikoschool-reverb.service`

```ini
[Unit]
Description=TikoSchool Laravel Reverb WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/Tikoschool
ExecStart=/usr/bin/php artisan reverb:start
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

### Enable all:

```bash
systemctl daemon-reload
systemctl enable tikoschool-queue tikoschool-reverb
systemctl start tikoschool-queue tikoschool-reverb
```

---

## Step 8: Cron

```bash
# Add www-data crontab
crontab -u www-data -e
```

Add:
```
* * * * * cd /var/www/Tikoschool && php artisan schedule:run >> /dev/null 2>&1
```

---

## Step 9: Certbot (keep as-is)

Certbot runs on the host and doesn't need Docker. The certificate renewal hook already exists:

```bash
# Test renewal
certbot renew --dry-run

# Update reload hook if needed
cat > /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh << 'EOF'
#!/bin/bash
systemctl reload nginx
EOF
chmod +x /etc/letsencrypt/renewal-hooks/post/reload-nginx.sh
```

---

## Step 10: Stop Docker

```bash
cd /var/www/Tikoschool
docker compose down
systemctl stop docker.socket docker.service
systemctl disable docker.socket docker.service

# Optionally remove Docker entirely
# apt purge -y docker-ce docker-ce-cli containerd.io docker-compose-plugin
# apt autoremove -y
```

---

## Step 11: Verify

```bash
# Check all services
systemctl status mysql nginx php8.2-fpm redis-server tikoschool-queue tikoschool-reverb

# Test app
curl -I https://tiko.school/login

# Check memory saved
free -h
```

---

## Expected Results After Migration

| Metric | Before (Docker) | After (Native) |
|---|---|---|
| RAM used | ~1,788 MB | ~1,100 MB |
| RAM available | ~2,126 MB | ~2,800 MB |
| PHP-FPM workers | 5 (dynamic) | 5 (static, pre-forked) |
| MySQL buffer pool | 128 MB | 1 GB |
| OPcache JIT | Off | On (tracing) |
| Docker daemon | ~682 MB | 0 MB |
| Response time | ~73ms | ~50-65ms |

---

## Rollback Plan

If anything goes wrong, restore Docker:
```bash
systemctl start docker.socket docker.service
cd /var/www/Tikoschool
docker compose up -d
```
