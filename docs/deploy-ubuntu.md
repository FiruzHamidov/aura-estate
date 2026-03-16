# Ubuntu Deployment Guide

This project is a Laravel 12 backend with Vite-built frontend assets. It uses:

- PHP 8.2+
- Composer
- Node.js and npm
- MySQL or PostgreSQL in production
- Queue workers
- `ffmpeg` for reel preview/thumbnail generation
- PHP GD for image processing

## 1. Install system packages

Ubuntu 22.04 / 24.04 example:

```bash
sudo apt update
sudo apt install -y \
  nginx \
  git \
  curl \
  unzip \
  supervisor \
  ffmpeg \
  mysql-client \
  php8.2-cli \
  php8.2-fpm \
  php8.2-mysql \
  php8.2-sqlite3 \
  php8.2-mbstring \
  php8.2-xml \
  php8.2-curl \
  php8.2-zip \
  php8.2-bcmath \
  php8.2-intl \
  php8.2-gd
```

Install Composer:

```bash
cd /tmp
curl -sS https://getcomposer.org/installer -o composer-setup.php
php composer-setup.php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

Install Node.js 22:

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v
npm -v
```

Check `ffmpeg`:

```bash
ffmpeg -version
which ffmpeg
```

## 2. Prepare application directory

```bash
sudo mkdir -p /var/www/aura-estate
sudo chown -R $USER:$USER /var/www/aura-estate
cd /var/www/aura-estate
git clone <YOUR_REPOSITORY_URL> .
```

## 3. Install backend and frontend dependencies

```bash
cd /var/www/aura-estate
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 4. Configure environment

Create the environment file:

```bash
cd /var/www/aura-estate
cp .env.example .env
php artisan key:generate
```

Minimum production variables to review in `.env`:

```dotenv
APP_NAME="Aura Estate"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.com

LOG_CHANNEL=stack
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=aura_estate
DB_USERNAME=aura_estate
DB_PASSWORD=change_me

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database

FILESYSTEM_DISK=public
REELS_FILESYSTEM_DISK=public
REELS_FFMPEG_BINARY=/usr/bin/ffmpeg

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=587
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@your-domain.com"
MAIL_FROM_NAME="${APP_NAME}"

OPENAI_API_KEY=
OPENAI_MODEL=gpt-5-mini
OPENAI_BASE=https://api.openai.com
RELAY_SHARED_KEY=

AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false
AWS_ENDPOINT=
AWS_URL=
```

Notes:

- Use `FILESYSTEM_DISK=public` and `REELS_FILESYSTEM_DISK=public` for local disk storage.
- If reels are stored in S3, set `REELS_FILESYSTEM_DISK=s3` and fill AWS variables.
- `REELS_FFMPEG_BINARY=/usr/bin/ffmpeg` is recommended explicitly in production.

## 5. Database setup

Create the database and user in MySQL:

```bash
mysql -u root -p
```

Then run:

```sql
CREATE DATABASE aura_estate CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aura_estate'@'127.0.0.1' IDENTIFIED BY 'change_me';
GRANT ALL PRIVILEGES ON aura_estate.* TO 'aura_estate'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

Run migrations:

```bash
cd /var/www/aura-estate
php artisan migrate --force
```

## 6. Storage and permissions

```bash
cd /var/www/aura-estate
php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

If your deploy user must also write during deploy:

```bash
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
```

## 7. Optimize Laravel

```bash
cd /var/www/aura-estate
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## 8. Configure Nginx

Create `/etc/nginx/sites-available/aura-estate`:

```nginx
server {
    listen 80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/aura-estate/public;

    index index.php index.html;

    client_max_body_size 120M;

    access_log /var/log/nginx/aura-estate.access.log;
    error_log /var/log/nginx/aura-estate.error.log;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site:

```bash
sudo ln -s /etc/nginx/sites-available/aura-estate /etc/nginx/sites-enabled/aura-estate
sudo nginx -t
sudo systemctl reload nginx
```

## 9. Configure queue worker with Supervisor

Create `/etc/supervisor/conf.d/aura-estate-worker.conf`:

```ini
[program:aura-estate-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/aura-estate/artisan queue:work database --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/aura-estate/storage/logs/worker.log
stopwaitsecs=3600
directory=/var/www/aura-estate
```

Enable and start Supervisor:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start aura-estate-worker:*
sudo supervisorctl status
```

## 10. Optional cron for scheduler

If you use scheduled commands later, add:

```bash
crontab -e
```

Then:

```cron
* * * * * cd /var/www/aura-estate && php artisan schedule:run >> /dev/null 2>&1
```

## 11. HTTPS with Let's Encrypt

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

## 12. Deploy update flow

For every new release:

```bash
cd /var/www/aura-estate
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
sudo systemctl reload php8.2-fpm
sudo systemctl reload nginx
```

## 13. Reel preview troubleshooting

If `playback.preview_image_url` or `playback.thumbnail_public_url` is empty:

Check that `ffmpeg` exists:

```bash
which ffmpeg
ffmpeg -version
```

Check queue worker status:

```bash
sudo supervisorctl status
```

Check Laravel logs:

```bash
tail -f /var/www/aura-estate/storage/logs/laravel.log
```

Requeue preview generation for one reel:

```bash
cd /var/www/aura-estate
HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker --execute='App\Jobs\ProcessReelVideo::dispatch(7);'
```

Requeue all reels without preview or thumbnail:

```bash
cd /var/www/aura-estate
HOME=/tmp XDG_CONFIG_HOME=/tmp php artisan tinker --execute='App\Models\Reel::query()->whereNull("deleted_at")->where("status", "!=", App\Models\Reel::STATUS_ARCHIVED)->where(function ($q) { $q->whereNull("preview_image")->orWhereNull("thumbnail_url"); })->chunkById(100, function ($reels) { foreach ($reels as $reel) { App\Jobs\ProcessReelVideo::dispatch($reel->id); } });'
```
