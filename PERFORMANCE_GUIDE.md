# Laravel Performance Guide — Existing Packages + Infrastructure

A practical, step-by-step playbook to push a Laravel app toward Go/Bun-class
throughput using **only mature, off-the-shelf packages and infrastructure** — no
custom engine to build and maintain.

Goal: maximize real-world RPS (DB + auth + dynamic work) and minimize p95 latency.

Principle: **measure → change one thing → measure again.** Never optimize blind.

---

## Table of Contents

1. [Phase 0 — Baseline & Benchmark Harness](#phase-0--baseline--benchmark-harness)
2. [Phase 1 — Kill Bootstrap Cost (Octane)](#phase-1--kill-bootstrap-cost-octane)
3. [Phase 2 — PHP Runtime Tuning (OPcache, JIT, jemalloc)](#phase-2--php-runtime-tuning-opcache-jit-jemalloc)
4. [Phase 3 — Redis for All State](#phase-3--redis-for-all-state)
5. [Phase 4 — Application Layer Packages](#phase-4--application-layer-packages)
6. [Phase 5 — Nginx RAM Micro-Cache](#phase-5--nginx-ram-micro-cache)
7. [Phase 6 — Database: MySQL Tuning, DDL, ProxySQL](#phase-6--database-mysql-tuning-ddl-proxysql)
8. [Phase 7 — Horizontal Scale & Read Replicas](#phase-7--horizontal-scale--read-replicas)
9. [Phase 8 — Continuous Monitoring](#phase-8--continuous-monitoring)
10. [Package Reference Table](#package-reference-table)
11. [Master Execution Checklist](#master-execution-checklist)

---

## Phase 0 — Baseline & Benchmark Harness

**Why:** You cannot claim a speedup you didn't measure. Establish numbers before any change.

### Tools

| Tool | Purpose | Install |
|---|---|---|
| `wrk` | HTTP throughput/latency load test | `apt install wrk` |
| `k6` | Scriptable load test (auth flows) | [k6.io](https://k6.io) |
| `bombardier` | Simple Go-based HTTP bench | `go install github.com/codesenberg/bombardier@latest` |
| `laravel/pulse` | In-app performance dashboard | see Phase 8 |
| `clockwork` / `debugbar` | Per-request profiling (dev only) | see Phase 4 |

### Steps

1. Pick 3 representative endpoints:
   - **Read-heavy** (e.g. `GET /api/products`)
   - **Authenticated dynamic** (e.g. `GET /api/user/dashboard`)
   - **Write** (e.g. `POST /api/orders`)

2. Run a fixed benchmark and **save the output** for each phase:
   ```bash
   wrk -t4 -c100 -d30s --latency http://localhost/api/products
   ```

3. Record in a table per endpoint: RPS, p50, p95, p99, errors.

4. Re-run the **same** command after every phase. Compare. Keep a log file:
   ```
   benchmarks/
     phase-0-baseline.txt
     phase-1-octane.txt
     ...
   ```

> **Rule:** if a change does not move the number, revert it. Complexity without
> measured gain is debt.

---

## Phase 1 — Kill Bootstrap Cost (Octane)

**Biggest single win.** PHP-FPM re-bootstraps the entire framework every request
(20–40ms). Octane boots workers once and keeps them in memory.

**Package:** `laravel/octane` (official).

### Choose a server

| Server | Pros | Cons | Best for |
|---|---|---|---|
| **FrankenPHP** | Modern, HTTP/2/3, built-in worker mode, easy Docker | Newer ecosystem | New deploys, simplest setup |
| **Swoole** | Mature, Swoole Tables, coroutines, `concurrently()` | PECL extension, learning curve | Max features, in-memory tables |
| **RoadRunner** | Go-based, no PECL ext, stable | Fewer Swoole superpowers | Teams avoiding PECL |

Recommendation: **Swoole** if you want Swoole Tables + concurrent I/O (used in
later phases); **FrankenPHP** if you want the simplest modern path.

### Steps (Swoole)

1. Install Swoole extension:
   ```bash
   pecl install swoole
   # enable in php.ini: extension=swoole
   ```

2. Install Octane:
   ```bash
   composer require laravel/octane
   php artisan octane:install --server=swoole
   ```

3. Tune `config/octane.php`:
   ```php
   'swoole' => [
       'options' => [
           'worker_num'         => swoole_cpu_num() * 2,
           'task_worker_num'    => swoole_cpu_num(),
           'max_requests'       => 500,            // recycle worker after N requests (controls memory/GC)
           'package_max_length' => 10 * 1024 * 1024,
       ],
   ],
   ```

4. Start:
   ```bash
   php artisan octane:start --server=swoole --workers=auto --max-requests=500
   ```

5. Production: run under a supervisor (systemd or Supervisor):
   ```ini
   ; /etc/supervisor/conf.d/octane.conf
   [program:octane]
   command=php /var/www/artisan octane:start --server=swoole --host=127.0.0.1 --port=8000
   autostart=true
   autorestart=true
   user=www-data
   numprocs=1
   redirect_stderr=true
   stdout_logfile=/var/log/octane.log
   ```

### Critical: avoid state leaks

Octane workers are long-lived. State that "just worked" in FPM can now leak
between requests.

- **Never** store request-specific data in static properties or singletons.
- Reset/clear any package that caches per-request state. Octane flushes the
  default container bindings, but third-party singletons may need
  `Octane::flush` listeners.
- Audit with: read the
  [Octane concurrency & state docs](https://laravel.com/docs/octane#dependency-injection-and-octane).
- Test under load, not just a single request.

### Deploy step

```bash
php artisan octane:reload   # graceful worker reload after deploy (zero-downtime)
```

**Expected:** ~900 RPS → 6,000–10,000 RPS.

---

## Phase 2 — PHP Runtime Tuning (OPcache, JIT, jemalloc)

**Free wins, no code changes.** Applies on top of Octane.

### OPcache + JIT — `php.ini`

```ini
; ---- OPcache ----
opcache.enable=1
opcache.enable_cli=1                     ; needed so Octane workers use OPcache
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=20000      ; match: find /var/www -name "*.php" | wc -l
opcache.validate_timestamps=0            ; PROD ONLY — skip stat() per request (reload OPcache on deploy!)
opcache.revalidate_freq=0
opcache.save_comments=1                  ; required by Laravel/Doctrine annotations
opcache.huge_code_pages=1

; ---- Preload (boots opcode of vendor at startup) ----
opcache.preload=/var/www/preload.php
opcache.preload_user=www-data

; ---- JIT ----
opcache.jit=tracing
opcache.jit_buffer_size=100M

; ---- Regex / memory / errors ----
pcre.jit=1
memory_limit=256M
zend.assertions=-1
display_errors=Off
log_errors=On
```

> JIT realistically buys **5–10%** for I/O-bound Laravel apps — it is not the
> headline win. OPcache + preload matters far more.

### Important deploy note for `validate_timestamps=0`

With timestamps off, PHP will **not** notice changed files. After every deploy
you MUST reset OPcache:

```bash
php artisan octane:reload      # if using Octane (reloads workers + opcode)
# or restart php-fpm / the service
```

### Preload script

Generate or write `/var/www/preload.php` that requires the Composer autoloader
(and optionally warm-loads hot classes). Laravel can generate one via packages
like `darkghosthunter/preloader`, or write a minimal one:

```php
<?php
require __DIR__.'/vendor/autoload.php';
// Optionally opcache_compile_file() hot framework classes here.
```

### jemalloc (Linux) — 10–30% memory + alloc speed under concurrency

```bash
apt install libjemalloc2
```
Add to the Octane systemd/Supervisor service environment:
```ini
Environment="LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libjemalloc.so.2"
```

### Verify

```bash
php -i | grep -i opcache.jit          # confirm JIT on
php artisan about                     # confirm env=production, caches on
```
Re-run Phase 0 benchmark.

**Expected:** +10–20% on top of Octane.

---

## Phase 3 — Redis for All State

**Why:** Sessions/cache/queue on file or DB driver serialize and hit disk/DB.
Redis is in-memory and shared across all workers and servers.

**Package:** `predis/predis` or the `phpredis` extension (phpredis is faster —
prefer it in production).

### Steps

1. Install phpredis:
   ```bash
   pecl install redis     # enable extension=redis in php.ini
   ```

2. `.env`:
   ```env
   CACHE_STORE=redis
   SESSION_DRIVER=redis
   QUEUE_CONNECTION=redis
   REDIS_CLIENT=phpredis
   REDIS_HOST=127.0.0.1
   REDIS_PORT=6379
   ```

3. Separate logical concerns into Redis databases or prefixes to avoid
   `cache:clear` wiping sessions:
   ```php
   // config/database.php — redis
   'cache'    => [ 'database' => '1' ],
   'sessions' => [ 'database' => '2' ],
   ```

4. Queues — install **Laravel Horizon** for Redis queue management + dashboard:
   ```bash
   composer require laravel/horizon
   php artisan horizon:install
   ```
   Run `php artisan horizon` under Supervisor. Dashboard at `/horizon`.

5. Move slow synchronous work off the request path into queued jobs
   (emails, notifications, exports, webhooks, image processing).

### Verify

- `redis-cli monitor` while hitting the app — confirm cache/session traffic.
- Re-benchmark; queued endpoints should drop p95 sharply.

---

## Phase 4 — Application Layer Packages

Now optimize the Laravel code path itself with proven packages.

### 4.1 Artisan caches — run on EVERY deploy

```bash
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
php artisan octane:reload
```

> After `config:cache`, `env()` returns null outside config files. Only call
> `env()` inside `config/*.php`. Use `config()` everywhere else.

### 4.2 Detect N+1 queries — `beyondcode/laravel-query-detector`

```bash
composer require beyondcode/laravel-query-detector --dev
```
Run the app in dev; it raises alerts on N+1. Fix with eager loading:
```php
User::with(['orders.items', 'profile'])->paginate(20);
```
Optionally enforce strictness app-wide:
```php
// AppServiceProvider::boot()
Model::preventLazyLoading(! app()->isProduction());
```

### 4.3 Full audit — `enlightn/enlightn`

```bash
composer require enlightn/enlightn --dev
php artisan enlightn        # runs performance + security + reliability checks
```
Reviews: missing indexes, N+1, unbounded queries, config, caching gaps. Acts as
an automated reviewer for this entire guide's app layer. Fix what it flags.

### 4.4 HTTP response caching — `spatie/laravel-responsecache`

Full-page/response cache for read-heavy, user-agnostic endpoints.
```bash
composer require spatie/laravel-responsecache
php artisan vendor:publish --provider="Spatie\ResponseCache\ResponseCacheServiceProvider"
```
- Add the middleware to the routes/groups you want cached.
- Exclude auth/cart/checkout via the package's cache profile.
- Bust on writes with `php artisan responsecache:clear` or tagged clears in
  model observers.

For finer control, cache at the query/fragment level:
```php
return Cache::remember("products:list:{$page}", 60, fn () =>
    Product::with('category')->paginate(20)
);
```
Invalidate in a model observer on save/delete.

### 4.5 Static analysis & insights (CI gates)

```bash
composer require nunomaduro/larastan --dev      # type-level bugs
composer require nunomaduro/phpinsights --dev   # code quality + perf hints
```
Wire into CI so regressions fail the build.

### 4.6 Hot service singletons

Bind frequently-resolved services as singletons to skip container reflection:
```php
// AppServiceProvider::register()
$this->app->singleton(MyHotService::class);
$this->app->singleton(MyRepository::class);
```

### 4.7 Swoole superpowers (if on Swoole)

Concurrent I/O:
```php
[$users, $orders] = Octane::concurrently([
    fn () => User::find($id),
    fn () => Order::where('user_id', $id)->get(),
]);
```

Swoole Tables — shared in-memory across all workers (~2M ops/s) for rate limits,
feature flags, counters:
```php
// config/octane.php
'tables' => [
    'rate_limits:1000' => [
        'count'    => 'int',
        'reset_at' => 'int',
    ],
],
```
```php
Octane::table('rate_limits')->set($ip, ['count' => $n, 'reset_at' => time() + 60]);
```

Octane cache (Swoole-backed, faster than Redis for node-local data) and ticks
(periodic in-worker jobs, no queue overhead) — see
[Octane docs](https://laravel.com/docs/octane).

**Expected (Phases 3+4):** 15,000–40,000 RPS on cached/read paths.

---

## Phase 5 — Nginx RAM Micro-Cache

**The single biggest infrastructure win.** When nginx serves from RAM, PHP never
wakes up at all. Even Octane caps near ~10k RPS; nginx from `/dev/shm` does
100k–500k+ RPS at sub-millisecond latency.

> Use this in front of either PHP-FPM or Octane. With Octane, proxy to its
> port via `proxy_pass` and use `proxy_cache` directives (FastCGI directives are
> for FPM). The example below shows FPM; swap to `proxy_*` for Octane.

### Steps

1. Confirm `/dev/shm` is tmpfs (RAM):
   ```bash
   df -h /dev/shm
   ```

2. nginx config (FPM variant):
   ```nginx
   fastcgi_cache_path /dev/shm/nginx_cache
       levels=1:2 keys_zone=LARAVEL:100m max_size=1g inactive=60s use_temp_path=off;
   fastcgi_cache_key "$scheme$request_method$host$request_uri";

   map $request_method $skip_method { default 0; POST 1; PUT 1; PATCH 1; DELETE 1; }
   map $http_cookie    $skip_cookie { default 0; "~*laravel_session" 1; }
   map $request_uri    $skip_uri    { default 0; "~*/api/user" 1; "~*/admin" 1; "~*/checkout" 1; }

   server {
       listen 80;
       set $skip_cache 0;
       if ($skip_method) { set $skip_cache 1; }
       if ($skip_cookie) { set $skip_cache 1; }
       if ($skip_uri)    { set $skip_cache 1; }

       location ~ \.php$ {
           fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
           include fastcgi_params;

           fastcgi_cache LARAVEL;
           fastcgi_cache_valid 200 10s;       # micro-cache: 10s
           fastcgi_cache_valid 404 1m;
           fastcgi_cache_use_stale error timeout updating http_500 http_503;
           fastcgi_cache_background_update on;
           fastcgi_cache_lock on;             # dog-pile protection
           fastcgi_cache_bypass $skip_cache;
           fastcgi_no_cache $skip_cache;
           add_header X-Cache $upstream_cache_status;
       }
   }
   ```

3. Reload and verify cache hits:
   ```bash
   nginx -t && systemctl reload nginx
   curl -I http://localhost/api/products    # look for: X-Cache: HIT
   ```

### Cache hit-rate strategy

Target **>90% hit rate**. Below 80%, average latency collapses back to app speed.

| TTL | Use case | Hit-rate potential |
|---|---|---|
| 1–5s | High-traffic API lists | 95%+ |
| 10s | Search / category pages | 90%+ |
| 60s | User-agnostic config endpoints | 99% |
| bypass | Auth, cart, checkout | 0% (correct) |

**Expected:** 200,000–500,000 RPS at ~0.3–0.5ms on cached routes.

---

## Phase 6 — Database: MySQL Tuning, DDL, ProxySQL

The DB is the real ceiling for uncacheable dynamic work.

### 6.1 MySQL `my.cnf`

```ini
[mysqld]
innodb_buffer_pool_size = 12G            ; 70-80% RAM dedicated, 50% shared
innodb_buffer_pool_instances = 8
innodb_log_file_size = 1G
innodb_flush_log_at_trx_commit = 2       ; 1=safest, 2=fast, 0=risky
innodb_flush_method = O_DIRECT
innodb_io_capacity = 2000                ; match SSD IOPS
innodb_io_capacity_max = 4000
max_connections = 200                    ; (workers * servers) * 1.5 + 20
thread_cache_size = 50
query_cache_type = 0                     ; disable — global mutex hurts concurrency
query_cache_size = 0
tmp_table_size = 128M
max_heap_table_size = 128M
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.1
log_queries_not_using_indexes = 1
```

### 6.2 Connection reuse in Laravel — `config/database.php`

```php
'mysql' => [
    'driver'  => 'mysql',
    'options' => [
        PDO::ATTR_PERSISTENT       => true,   // reuse connection across requests in same worker
        PDO::ATTR_EMULATE_PREPARES => false,  // real prepared statements
    ],
],
```

### 6.3 DDL audit — smaller types, right indexes

```sql
-- Column types: smaller = smaller index = faster
status    TINYINT          -- not VARCHAR(255)
user_id   INT UNSIGNED     -- not BIGINT if < 4.2B rows
is_active TINYINT(1)       -- not "true"/"false" strings

-- Composite index: equality columns first, range last
CREATE INDEX idx_orders_user_status_date ON orders(user_id, status, created_at);

-- Covering index: index holds every column the query selects (index-only scan)
CREATE INDEX idx_products_covering ON products(category_id, id, name, price);
```

Anti-patterns to fix:
```sql
WHERE DATE(created_at) = '2024-01-01'        -- function kills index
WHERE created_at >= '2024-01-01' AND created_at < '2024-01-02'   -- good

WHERE name LIKE '%smith%'                    -- leading wildcard = full scan
-- use FULLTEXT instead
ALTER TABLE users ADD FULLTEXT(name);
WHERE MATCH(name) AGAINST('smith' IN BOOLEAN MODE)
```

Find offenders from the slow log, then `EXPLAIN` each query and add the index it
wants.

### 6.4 ProxySQL — connection pooling + read/write split

```
Laravel Octane workers → ProxySQL :6033 → MySQL primary + replicas
```
ProxySQL keeps persistent connections to MySQL so workers skip the TCP+auth
handshake. Install, then point Laravel at it:
```bash
apt install proxysql
```
```env
DB_HOST=127.0.0.1
DB_PORT=6033
```

### Verify

- `EXPLAIN` shows index usage (no `ALL` full scans on hot queries).
- Slow log shrinks.
- Re-benchmark the write + authenticated endpoints.

---

## Phase 7 — Horizontal Scale & Read Replicas

When a single node saturates.

### Read replica split — `config/database.php`

```php
'mysql' => [
    'read'   => ['host' => [env('DB_READ_HOST', '127.0.0.1')]],
    'write'  => ['host' => [env('DB_WRITE_HOST', '127.0.0.1')]],
    'sticky' => true,   // read-your-own-writes after a write in same request
],
```

### Horizontal app scale

Octane workers are stateless **if** state is flushed correctly (see Phase 1).
Load-balance multiple app nodes behind nginx/HAProxy. Shared state (sessions,
cache, queue) already lives in Redis (Phase 3), so nodes are interchangeable.

### Nuclear option — Go/Bun sidecar

For a tiny set of truly uncacheable, CPU-bound hot endpoints where PHP physically
cannot match: co-deploy a single-purpose Go or Bun microservice for that one
endpoint and route to it. Use only when profiling proves PHP is the wall.

---

## Phase 8 — Continuous Monitoring

Keep the gains; catch regressions.

| Package | Role | Install |
|---|---|---|
| **laravel/pulse** (official) | Live dashboard: slow queries, slow requests, queues, cache | `composer require laravel/pulse` |
| **laravel/nightwatch** (official) | Hosted APM / production observability | per docs |
| **laravel/telescope** | Deep request/query inspector (staging/dev) | `composer require laravel/telescope --dev` |
| **clockwork** / **barryvdh/laravel-debugbar** | Per-request profiling (dev only) | `--dev` |

### Steps

1. Install Pulse:
   ```bash
   composer require laravel/pulse
   php artisan pulse:install
   php artisan migrate
   ```
   Dashboard at `/pulse`. Watch: slowest queries, slowest routes, cache hit rate.

2. Set alerts on p95 latency and error rate.

3. Re-run the Phase 0 benchmark suite on a schedule (CI/cron) and diff against
   the saved baseline files. Treat a regression like a failing test.

> Keep Telescope/Debugbar **out of production** — they add overhead and store
> sensitive data.

---

## Package Reference Table

| Need | Package | Official | Scope |
|---|---|---|---|
| Kill bootstrap cost | `laravel/octane` | ✅ | runtime |
| Queue management | `laravel/horizon` | ✅ | runtime |
| Live perf dashboard | `laravel/pulse` | ✅ | monitoring |
| Hosted APM | `laravel/nightwatch` | ✅ | monitoring |
| Request inspector | `laravel/telescope` | ✅ | dev |
| Response/page cache | `spatie/laravel-responsecache` | — | app |
| N+1 detector | `beyondcode/laravel-query-detector` | — | dev |
| Full perf/security audit | `enlightn/enlightn` | — | audit |
| Static analysis | `nunomaduro/larastan` | — | CI |
| Code insights | `nunomaduro/phpinsights` | — | CI |
| OPcache preloader | `darkghosthunter/preloader` | — | build |
| Redis client (fast) | `phpredis` (PECL ext) | — | runtime |

Infra (not composer): **Swoole/FrankenPHP/RoadRunner**, **nginx**, **Redis**,
**MySQL/ProxySQL**, **jemalloc**, **k6/wrk** (benchmarking).

---

## Master Execution Checklist

Do in order. Benchmark after each. Revert anything that doesn't move the number.

- [ ] 0. Baseline benchmark harness (wrk/k6) + save numbers
- [ ] 1. Octane + Swoole/FrankenPHP — biggest single win, fix state leaks
- [ ] 2. OPcache + JIT + preload + jemalloc (`php.ini`)
- [ ] 3. Redis everywhere (cache, session, queue) + Horizon + queue slow work
- [ ] 4. Artisan caches on every deploy (`config/route/event/view` + autoloader)
- [ ] 5. `enlightn` audit — fix everything it flags
- [ ] 6. N+1 sweep with query-detector + eager loading
- [ ] 7. `spatie/laravel-responsecache` on read-heavy routes
- [ ] 8. Nginx RAM micro-cache (`/dev/shm`) — target >90% hit rate
- [ ] 9. MySQL `my.cnf` tuning (buffer pool, flush, disable query cache)
- [ ] 10. DDL audit — column types + composite/covering indexes from slow log
- [ ] 11. ProxySQL connection pooling
- [ ] 12. Persistent PDO + hot-service singletons
- [ ] 13. Swoole Tables for rate limits / feature flags (if on Swoole)
- [ ] 14. Read replica + horizontal scale when single node saturates
- [ ] 15. Pulse + nightwatch monitoring + scheduled regression benchmarks

---

## Expected Outcomes

| After | RPS (real-world) | vs Go/Bun |
|---|---|---|
| PHP-FPM baseline | ~900 | ~100x behind |
| + Octane | ~8,000 | ~12x behind |
| + Redis + app layer | ~15,000–40,000 | ~3–5x behind |
| + Nginx RAM cache (cached routes) | ~200,000–500,000 | matched / ahead |
| + DB tuning + scale (uncached) | DB-bound, multi-node | competitive |

**Honest assessment:** for uncached, CPU-bound dynamic work, PHP will not match Go
byte-for-byte. For real-world web apps (read-heavy, cacheable, CRUD), this stack —
Octane + Redis + nginx RAM cache + tuned MySQL — delivers the same effective
throughput as Go/Bun from the client's perspective. The goal is achievable with
existing packages and infrastructure alone; no custom engine required.
