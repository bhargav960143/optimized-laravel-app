# Server & Performance Setup Guide
**Domain:** testingphp.trentiums.com | **IP:** 65.2.41.189
**OS:** Debian 12 | **PHP:** 8.2 | **Server:** Nginx + Octane (Swoole) + MariaDB + Redis

This guide documents every step to reproduce this server from scratch, or onboard a new developer.

---

## Architecture Overview

```
Internet → Nginx (443/SSL) → RAM Micro-Cache (/dev/shm) ─→ Laravel Octane (port 8000, Swoole)
                                                         └→ Cache HIT: served from RAM, PHP never wakes
Static files (CSS/JS/images) → Nginx serves directly
Queues → Laravel Horizon → Redis
Sessions/Cache → Redis (separate DB indexes)
Database → MariaDB (tuned for 2GB RAM)
```

---

## Table of Contents

1. [Initial Server Setup](#1-initial-server-setup)
2. [Directory Structure — Multi-site Hosting](#2-directory-structure--multi-site-hosting)
3. [SSL Certificate](#3-ssl-certificate)
4. [Phase 0 — Benchmark Harness](#4-phase-0--benchmark-harness)
5. [Phase 1 — Octane + Swoole](#5-phase-1--octane--swoole)
6. [Phase 2 — PHP Runtime Tuning (OPcache + JIT)](#6-phase-2--php-runtime-tuning-opcache--jit)
7. [Phase 3 — Redis for All State](#7-phase-3--redis-for-all-state)
8. [Phase 4 — Application Layer](#8-phase-4--application-layer)
9. [Phase 5 — Nginx RAM Micro-Cache](#9-phase-5--nginx-ram-micro-cache)
10. [Phase 6 — MariaDB Tuning](#10-phase-6--mariadb-tuning)
11. [Phase 8 — Monitoring (Pulse + Horizon)](#11-phase-8--monitoring-pulse--horizon)
12. [Deploy Runbook (After Every Git Push)](#12-deploy-runbook-after-every-git-push)
13. [Adding a New Website](#13-adding-a-new-website)
14. [Troubleshooting](#14-troubleshooting)

---

## 1. Initial Server Setup

### Install core packages

```bash
sudo apt-get update
sudo apt-get install -y git nginx redis-server mariadb-server certbot \
  python3-certbot-nginx wrk curl unzip software-properties-common

# Add Sury PHP repo (needed for php8.2-swoole)
curl -sSLo /tmp/sury.gpg https://packages.sury.org/php/apt.gpg
sudo cp /tmp/sury.gpg /usr/share/keyrings/deb.sury.org-php.gpg
echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
  | sudo tee /etc/apt/sources.list.d/php.list
sudo apt-get update

sudo apt-get install -y php8.2 php8.2-fpm php8.2-cli php8.2-mysql php8.2-redis \
  php8.2-bcmath php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip \
  php8.2-sqlite3 php8.2-swoole libjemalloc2
```

### Install Composer

```bash
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
composer --version  # should be 2.x
```

### Generate SSH key for GitHub deploys

```bash
ssh-keygen -t ed25519 -C "your@email.com" -f ~/.ssh/id_ed25519 -N ""
cat ~/.ssh/id_ed25519.pub
# → Add this key at: https://github.com/settings/keys
ssh -T git@github.com  # verify
```

---

## 2. Directory Structure — Multi-site Hosting

Each site lives under `/var/www/<domain>/`:

```
/var/www/
├── testingphp.trentiums.com/      ← one directory per site
│   ├── public/                    ← Laravel/app code (git clone goes here)
│   │   ├── public/                ← Laravel's web root (Apache/Nginx document root)
│   │   ├── app/
│   │   ├── storage/               ← must be writable by www-data
│   │   ├── bootstrap/cache/       ← must be writable by www-data
│   │   ├── .env                   ← never commit this
│   │   └── ...
│   └── logs/                      ← access.log, error.log, octane.log
└── anothersite.com/               ← next site follows same pattern
    ├── public/
    └── logs/
```

### Create site directory

```bash
DOMAIN="testingphp.trentiums.com"
sudo mkdir -p /var/www/$DOMAIN/{public,logs}
sudo chown -R www-data:www-data /var/www/$DOMAIN
```

### Clone the repo

```bash
# Give admin user write access for git operations
sudo chown admin:www-data /var/www/$DOMAIN/public
git clone git@github.com:bhargav960143/optimized-laravel-app.git /var/www/$DOMAIN/public

# Fix permissions for Laravel
sudo chown -R www-data:www-data /var/www/$DOMAIN/public
sudo chmod -R 775 /var/www/$DOMAIN/public/storage
sudo chmod -R 775 /var/www/$DOMAIN/public/bootstrap/cache
```

---

## 3. SSL Certificate

```bash
DOMAIN="testingphp.trentiums.com"
EMAIL="bhargav@trentiums.com"

# One command — gets cert + configures Nginx + auto-renewal
sudo certbot --nginx -d $DOMAIN --non-interactive --agree-tos \
  --email $EMAIL --redirect --no-eff-email

# Verify auto-renewal
sudo systemctl status certbot.timer
# Renews automatically twice daily
```

**Cert paths:**
- Certificate: `/etc/letsencrypt/live/testingphp.trentiums.com/fullchain.pem`
- Key: `/etc/letsencrypt/live/testingphp.trentiums.com/privkey.pem`

---

## 4. Phase 0 — Benchmark Harness

```bash
# Install wrk
sudo apt-get install -y wrk

# Create benchmark directory
mkdir -p /var/www/testingphp.trentiums.com/public/benchmarks

# Run benchmark and save (run after EVERY phase)
wrk -t2 -c50 -d30s --latency https://testingphp.trentiums.com/api/your-endpoint \
  > /var/www/testingphp.trentiums.com/public/benchmarks/phase-N-label.txt

# Current baseline (Phase 1 — Octane, 2-core server, session route):
# RPS: 213 | p50: 3.94ms | p95: ~503ms | p99: 1.5s
```

> **Rule:** only keep a change if it improves the numbers. Revert otherwise.

---

## 5. Phase 1 — Octane + Swoole

**Expected gain:** ~10x over PHP-FPM (900 → 8,000 RPS).

### Files changed

| File | What changed |
|------|-------------|
| `config/octane.php` | Set server=swoole, worker_num=4, task_worker_num=2 |
| `/etc/systemd/system/octane.service` | Runs Octane as www-data, auto-restarts |

### config/octane.php — key settings

```php
'server' => 'swoole',

'swoole' => [
    'options' => [
        'worker_num'         => 4,                // CPU cores × 2
        'task_worker_num'    => 2,                // CPU cores
        'package_max_length' => 10 * 1024 * 1024,
        // NOTE: max_requests NOT set here — Swoole 6.x removed it from server options
        //       pass via CLI: --max-requests=500 (once Octane supports it in your version)
    ],
],
```

### Systemd service

**File:** `/etc/systemd/system/octane.service`

```ini
[Unit]
Description=Laravel Octane (Swoole)
After=network.target redis.service mariadb.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/var/www/testingphp.trentiums.com/public
# NOTE: Do NOT add LD_PRELOAD=jemalloc here — Swoole 6.x crashes when mixed with jemalloc
ExecStart=/usr/bin/php artisan octane:start --server=swoole --host=127.0.0.1 --port=8000 --workers=4
ExecReload=/usr/bin/php artisan octane:reload
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

### Commands

```bash
sudo systemctl enable --now octane
sudo systemctl status octane
sudo systemctl reload octane   # graceful reload (zero-downtime deploy)
sudo journalctl -u octane -f   # live logs
```

### State leak warning (critical for Octane)

Octane workers are **long-lived** — state from one request bleeds into the next if stored in static properties or singletons.

- Never write request-specific data to `static $var` or global singletons
- Never store `request()` in a service registered as a singleton
- Test under load, not just a single request
- See: https://laravel.com/docs/octane#dependency-injection-and-octane

---

## 6. Phase 2 — PHP Runtime Tuning (OPcache + JIT)

**Expected gain:** +10–20% on top of Octane.

### File: `/etc/php/8.2/mods-available/zz-performance.ini`

Symlinked to `/etc/php/8.2/cli/conf.d/99-zz-performance.ini` and `fpm/conf.d/`.

```ini
; OPcache
opcache.enable=1
opcache.enable_cli=1                  ; required for Octane workers
opcache.memory_consumption=128        ; MB — increase to 256 on >4GB RAM
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=15000   ; run: find /var/www -name "*.php" | wc -l
opcache.validate_timestamps=0         ; PROD ONLY — skip file-stat() per request
opcache.revalidate_freq=0
opcache.save_comments=1               ; required by Laravel annotations
opcache.huge_code_pages=1

; Preload — DISABLED: Swoole 6.x crashes with anonymous class preloading
; opcache.preload=/var/www/testingphp.trentiums.com/public/preload.php
; opcache.preload_user=www-data
; Re-enable if upgrading to Swoole 7+ or switching to FrankenPHP

; JIT
opcache.jit=tracing
opcache.jit_buffer_size=64M

; Runtime
pcre.jit=1
memory_limit=256M
zend.assertions=-1
display_errors=Off
log_errors=On
```

> **IMPORTANT:** `validate_timestamps=0` means PHP won't notice changed files.
> After every deploy you MUST run: `sudo systemctl reload octane`

### jemalloc

```bash
sudo apt-get install -y libjemalloc2
# Path: /usr/lib/x86_64-linux-gnu/libjemalloc.so.2
# NOTE: Do NOT use LD_PRELOAD with Swoole (causes heap corruption)
# Safe to use with PHP-FPM or non-Swoole processes only
```

---

## 7. Phase 3 — Redis for All State

**Expected gain:** eliminates DB reads for sessions/cache, drops p95 sharply for queue-heavy routes.

### Redis tuning: `/etc/redis/redis-performance.conf`

```
maxmemory 256mb
maxmemory-policy allkeys-lru
save ""              ; disable disk persistence (pure cache)
appendonly no
tcp-keepalive 60
hz 20
```

```bash
echo "include /etc/redis/redis-performance.conf" >> /etc/redis/redis.conf
sudo systemctl restart redis-server
```

### `.env` settings

```env
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=sessions     ← uses Redis DB 2 (isolated from cache)
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis            ← use the PECL extension, not predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

### `config/database.php` — separate Redis databases

```php
'cache'    => [ 'database' => '1' ],   // DB 1 = cache  (safe to flush)
'sessions' => [ 'database' => '2' ],   // DB 2 = sessions (never flush!)
```

Why: `php artisan cache:clear` flushes DB 1, leaving live sessions in DB 2 untouched.

### Laravel Horizon (queue manager)

**File:** `/etc/systemd/system/horizon.service`

```ini
[Service]
User=www-data
WorkingDirectory=/var/www/testingphp.trentiums.com/public
ExecStart=/usr/bin/php artisan horizon
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
```

```bash
sudo systemctl enable --now horizon
# Dashboard at: https://testingphp.trentiums.com/horizon
```

---

## 8. Phase 4 — Application Layer

### Artisan caches — run on EVERY deploy

```bash
cd /var/www/testingphp.trentiums.com/public
sudo -u www-data php artisan config:cache   # merges all config/*.php into one file
sudo -u www-data php artisan route:cache    # compiles routes to static file
sudo -u www-data php artisan view:cache     # pre-compiles Blade templates
sudo -u www-data php artisan event:cache    # caches event listeners
sudo -u www-data composer install --optimize-autoloader --no-dev
sudo systemctl reload octane               # graceful zero-downtime worker reload
```

> After `config:cache`, `env()` returns null outside config files.
> Only call `env()` inside `config/*.php`. Use `config('key')` everywhere else.

### PDO persistent connections — `config/database.php`

```php
'mysql' => [
    'options' => [
        PDO::ATTR_PERSISTENT       => true,   // reuse TCP connection per Octane worker
        PDO::ATTR_EMULATE_PREPARES => false,  // real prepared statements
    ],
],
```

### N+1 detection (dev only)

```bash
composer require --dev beyondcode/laravel-query-detector
```

Fix N+1 queries with eager loading:
```php
User::with(['orders.items', 'profile'])->paginate(20);
```

Enforce globally in `AppServiceProvider::boot()`:
```php
Model::preventLazyLoading(! app()->isProduction());
```

### HTTP response cache — `spatie/laravel-responsecache`

```bash
composer require spatie/laravel-responsecache
php artisan vendor:publish --provider="Spatie\ResponseCache\ResponseCacheServiceProvider"
```

Apply middleware to cacheable route groups:
```php
Route::middleware(['cacheResponse:600'])->group(function () {
    Route::get('/api/products', [ProductController::class, 'index']);
});
```

Bust on model changes:
```php
ResponseCache::clear();  // in model observer
```

### Swoole Tables (rate limits, feature flags)

```php
// config/octane.php
'tables' => [
    'rate_limits:1000' => [
        'count'    => 'int',
        'reset_at' => 'int',
    ],
],

// Usage:
Octane::table('rate_limits')->set($ip, ['count' => $n, 'reset_at' => time() + 60]);
```

---

## 9. Phase 5 — Nginx RAM Micro-Cache

**Biggest infrastructure win:** Nginx serves from `/dev/shm` (RAM). PHP never wakes up for cached routes.
**Target:** >90% cache hit rate on public endpoints.

### File: `/etc/nginx/sites-available/testingphp.trentiums.com`

Key sections:

```nginx
# Cache stored in RAM (/dev/shm is tmpfs)
proxy_cache_path /dev/shm/nginx_octane_cache
    levels=1:2
    keys_zone=OCTANE:64m
    max_size=512m
    inactive=60s
    use_temp_path=off;

# Skip rules — never cache these
map $request_method $skip_method { default 0; POST 1; PUT 1; PATCH 1; DELETE 1; }
map $http_cookie    $skip_cookie  { default 0; "~*laravel_session" 1; }
map $request_uri    $skip_uri     { default 0; "~*/admin" 1; "~*/horizon" 1; "~*/checkout" 1; }
```

```nginx
location / {
    proxy_pass http://127.0.0.1:8000;   # → Octane

    proxy_cache OCTANE;
    proxy_cache_valid 200 10s;           # micro-TTL: serve stale for 10s
    proxy_cache_use_stale error timeout updating;
    proxy_cache_background_update on;
    proxy_cache_lock on;                 # prevents dog-pile on cache miss
    proxy_cache_bypass $skip_cache;
    proxy_no_cache $skip_cache;
    add_header X-Cache $upstream_cache_status;
}
```

### Cache hit strategy

| Route type | TTL | Expected hit rate |
|------------|-----|-------------------|
| Public API lists | 10s | 95%+ |
| Search / category | 30s | 90%+ |
| Authenticated | bypass | 0% (correct) |
| Admin/horizon/pulse | bypass | 0% (correct) |

### Why sessions bypass the cache

Laravel sets a `laravel-session` cookie on every request (for CSRF + sessions).
The `skip_cookie` map detects this and bypasses the cache.
To get cache hits: strip session middleware from public read endpoints.

```bash
# Verify cache is working (look for X-Cache: HIT)
curl -sI https://testingphp.trentiums.com/api/public-endpoint | grep X-Cache
```

---

## 10. Phase 6 — MariaDB Tuning

**Tuned for 2GB RAM server.** Scale `innodb_buffer_pool_size` to 70% of RAM on larger servers.

### File: `/etc/mysql/mariadb.conf.d/99-performance.cnf`

```ini
[mysqld]
innodb_buffer_pool_size = 512M       ; 25% of 2GB RAM
innodb_buffer_pool_instances = 2
innodb_log_file_size = 128M
innodb_flush_log_at_trx_commit = 2   ; 1=safest, 2=fast (risk: last 1s of txn on crash)
innodb_flush_method = O_DIRECT
innodb_io_capacity = 1000
innodb_io_capacity_max = 2000

max_connections = 100                ; (octane_workers * servers) * 1.5 + 20
thread_cache_size = 20

query_cache_type = 0                 ; DISABLED — global mutex kills concurrency
query_cache_size = 0

tmp_table_size = 64M
max_heap_table_size = 64M

slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.1
log_queries_not_using_indexes = 1
```

### Scale for larger servers

| RAM | innodb_buffer_pool_size | innodb_buffer_pool_instances |
|-----|------------------------|------------------------------|
| 2GB | 512M | 2 |
| 8GB | 6G | 4 |
| 16GB | 12G | 8 |
| 32GB | 24G | 8 |

### Index best practices (DDL)

```sql
-- Composite index: equality columns first, range last
CREATE INDEX idx_orders_user_status_date ON orders(user_id, status, created_at);

-- Covering index: every column in the SELECT is in the index → index-only scan
CREATE INDEX idx_products_covering ON products(category_id, id, name, price);

-- Anti-pattern: function on column kills index
WHERE DATE(created_at) = '2024-01-01'    -- BAD
WHERE created_at >= '2024-01-01' AND created_at < '2024-01-02'  -- GOOD

-- Use FULLTEXT for contains-search
ALTER TABLE users ADD FULLTEXT(name);
WHERE MATCH(name) AGAINST('smith' IN BOOLEAN MODE);
```

### Find slow queries

```bash
sudo tail -f /var/log/mysql/slow.log
# Then: EXPLAIN SELECT ... to see index usage
# Fix: add the index it asks for
```

---

## 11. Phase 8 — Monitoring (Pulse + Horizon)

### Laravel Pulse

```bash
composer require laravel/pulse
php artisan vendor:publish --tag=pulse-config --tag=pulse-dashboard --tag=pulse-migrations
php artisan migrate
```

Dashboard: `https://testingphp.trentiums.com/pulse`
Tracks: slow queries, slow routes, cache hit rate, queue lengths, exceptions.

> Pulse runs as a background worker via `php artisan pulse:work` — add to Horizon or a separate systemd service.

### Laravel Horizon

Dashboard: `https://testingphp.trentiums.com/horizon`
Tracks: queue throughput, failed jobs, worker status.

**Secure both dashboards** in `app/Providers/AppServiceProvider.php`:
```php
use Laravel\Pulse\Facades\Pulse;
use Laravel\Horizon\Horizon;

Pulse::auth(fn ($request) => $request->user()?->isAdmin());
Horizon::auth(fn ($request) => $request->user()?->isAdmin());
```

---

## 12. Deploy Runbook (After Every Git Push)

```bash
cd /var/www/testingphp.trentiums.com/public

# 1. Pull latest code
sudo -u www-data git pull origin main

# 2. Install dependencies
sudo -u www-data composer install --optimize-autoloader --no-dev

# 3. Run migrations (--force bypasses production prompt)
sudo -u www-data php artisan migrate --force

# 4. Rebuild all caches (order matters)
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan event:cache

# 5. Graceful Octane reload (zero-downtime — workers finish current requests first)
sudo systemctl reload octane

# 6. Restart Horizon (picks up new job class changes)
sudo -u www-data php artisan horizon:terminate
sudo systemctl restart horizon

# 7. Optional: Run benchmark to check for regression
wrk -t2 -c50 -d15s --latency https://testingphp.trentiums.com/ \
  > benchmarks/deploy-$(date +%Y%m%d-%H%M).txt
```

---

## 13. Adding a New Website

```bash
NEW_DOMAIN="newsite.com"
EMAIL="bhargav@trentiums.com"
REPO="git@github.com:org/repo.git"

# 1. Create directories
sudo mkdir -p /var/www/$NEW_DOMAIN/{public,logs}
sudo chown admin:www-data /var/www/$NEW_DOMAIN/public

# 2. Clone repo
git clone $REPO /var/www/$NEW_DOMAIN/public
sudo chown -R www-data:www-data /var/www/$NEW_DOMAIN/public
sudo chmod -R 775 /var/www/$NEW_DOMAIN/public/storage
sudo chmod -R 775 /var/www/$NEW_DOMAIN/public/bootstrap/cache

# 3. Create MySQL DB
sudo mysql -e "
  CREATE DATABASE ${NEW_DOMAIN//./_}_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER '${NEW_DOMAIN//./_}_user'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
  GRANT ALL PRIVILEGES ON ${NEW_DOMAIN//./_}_db.* TO '${NEW_DOMAIN//./_}_user'@'localhost';
  FLUSH PRIVILEGES;
"

# 4. Create Nginx config
sudo cp /etc/nginx/sites-available/testingphp.trentiums.com \
        /etc/nginx/sites-available/$NEW_DOMAIN
# Edit: change ServerName, document root path, log paths
sudo nano /etc/nginx/sites-available/$NEW_DOMAIN

# 5. Enable site
sudo ln -s /etc/nginx/sites-available/$NEW_DOMAIN /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# 6. Get SSL cert
sudo certbot --nginx -d $NEW_DOMAIN --non-interactive --agree-tos \
  --email $EMAIL --redirect --no-eff-email

# 7. Laravel setup
cd /var/www/$NEW_DOMAIN/public
sudo -u www-data composer install --optimize-autoloader --no-dev
sudo -u www-data cp .env.example .env
sudo -u www-data nano .env   # fill in APP_URL, DB_*, APP_ENV=production, APP_DEBUG=false
sudo -u www-data php artisan key:generate
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

# 8. Create separate Octane service for this site (different port)
# Copy /etc/systemd/system/octane.service → /etc/systemd/system/octane-newsite.service
# Change port to 8001, WorkingDirectory, and logfile paths
```

---

## 14. Troubleshooting

### Octane won't start

```bash
sudo journalctl -u octane -n 50 --no-pager
# Common causes:
# - Port 8000 already in use: sudo ss -tlnp | grep 8000
# - Permission error: sudo chown -R www-data:www-data /var/www/.../public
# - Config syntax error: sudo -u www-data php artisan config:clear
```

### Site shows 502 Bad Gateway

```bash
# Octane not running
sudo systemctl status octane
sudo systemctl restart octane

# Nginx can't reach Octane
curl http://127.0.0.1:8000/  # test directly
```

### Cache:clear deletes sessions

```bash
# Make sure session.php uses the 'sessions' Redis connection (DB 2)
grep SESSION_CONNECTION .env    # should be: SESSION_CONNECTION=sessions
# And config/database.php redis.sessions.database = '2'
# Then: php artisan cache:clear only flushes DB 1
```

### OPcache serving stale code after deploy

```bash
# validate_timestamps=0 means PHP ignores file changes
# Always run after deploy:
sudo systemctl reload octane   # reloads workers + clears opcode cache
```

### Swoole + jemalloc crashes (free(): invalid pointer)

```bash
# Do NOT use LD_PRELOAD=jemalloc with Swoole 6.x
# They use incompatible memory allocators
# Remove Environment="LD_PRELOAD=..." from /etc/systemd/system/octane.service
```

### Check all service health at once

```bash
for svc in nginx octane horizon redis mariadb; do
  echo "$svc: $(systemctl is-active $svc)"
done
```

---

## Installed Packages Summary

### Composer (production)
| Package | Version | Purpose |
|---------|---------|---------|
| `laravel/octane` | ^2.17 | Keeps workers in memory (Swoole) |
| `laravel/horizon` | ^5.47 | Redis queue manager + dashboard |
| `laravel/pulse` | ^1.7 | Live performance dashboard |
| `spatie/laravel-responsecache` | ^7.7 | Full response caching |

### Composer (dev only)
| Package | Version | Purpose |
|---------|---------|---------|
| `beyondcode/laravel-query-detector` | ^2.3 | N+1 query detection |
| `nunomaduro/larastan` | ^3.10 | Static analysis (PHPStan for Laravel) |
| `nunomaduro/phpinsights` | ^2.13 | Code quality + perf hints |

### System packages
| Package | Purpose |
|---------|---------|
| `nginx` | Web server + SSL termination + RAM cache |
| `php8.2-swoole` | Swoole extension for Octane |
| `redis-server` | Cache + sessions + queues |
| `mariadb-server` | Primary database |
| `certbot + python3-certbot-nginx` | Let's Encrypt SSL |
| `libjemalloc2` | Fast allocator (for non-Swoole processes) |
| `wrk` | HTTP benchmarking |

---

*Last updated: 2026-06-06 by automated setup script*
