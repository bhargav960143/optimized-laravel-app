# Laravel Performance Upgrade Plan
Goal: Match Go / Bun throughput

---

## Performance Reality Check

| Stack | Synthetic RPS | Real-world (DB + auth) |
|---|---|---|
| Bun + Elysia | ~2,000,000 | ~50,000–200,000 |
| Go Fiber/FastHTTP | ~200,000–2M | ~30,000–100,000 |
| Laravel + PHP-FPM (baseline) | ~900–2,000 | ~500–1,500 |
| Laravel + Octane + Swoole | ~6,000–10,000 | ~2,000–8,000 |
| Laravel + Octane + full cache | ~50,000–200,000 | — |
| Swoole Table (in-memory) | ~2,000,000 ops/s | hot-data only |

Gap without optimization: 30–100x behind Go/Bun
Gap with full optimization: 5–10x behind for uncached dynamic work, effectively zero for cached reads

---

## Root Causes

1. **Bootstrap Cost** — PHP-FPM bootstraps entire framework every request. Go/Bun stay in memory. Costs 20–40ms per request.
2. **Synchronous I/O** — PHP blocks on DB/Redis/HTTP calls. Go has goroutines. Bun has event loop.
3. **No Native Connection Reuse** — PHP-FPM creates new DB/Redis connections per request.
4. **Interpreter Overhead** — PHP is interpreted. JIT helps marginally for I/O-bound apps. Go is compiled.
5. **Framework Weight** — Laravel bootstraps 200+ service providers, event listeners, middleware, ORM — all per request in FPM mode.

---

## Tier 1 — Eliminate Bootstrap Cost
**Expected gain: ~900 RPS → 6,000–10,000 RPS**

### Install Laravel Octane + Swoole
```bash
composer require laravel/octane
php artisan octane:install --server=swoole
```

Workers boot once, stay in memory, serve thousands of requests. Framework bootstrap happens once per worker, not per request.

### Tune `config/octane.php`
```php
'swoole' => [
    'options' => [
        'worker_num'      => swoole_cpu_num() * 2,
        'max_requests'    => 500,
        'task_worker_num' => swoole_cpu_num(),
        'package_max_length' => 10 * 1024 * 1024,
    ],
],
```

### OPcache + JIT in `php.ini`
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
opcache.preload=/var/www/vendor/autoload.php
opcache.preload_user=www-data
opcache.jit=tracing
opcache.jit_buffer_size=100M
```

---

## Tier 2 — Fix I/O & Connection Reuse
**Expected gain: 2–3x on top of Tier 1**

### Swoole Concurrent I/O
```php
// Sequential: 40ms + 30ms = 70ms
// Parallel:   max(40ms, 30ms) = 40ms
[$users, $orders] = Octane::concurrently([
    fn() => User::find($id),
    fn() => Order::where('user_id', $id)->get(),
]);
```

### Redis for All State
```env
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=redis
```

---

## Tier 3 — Laravel Application Layer
**Expected gain: 1.5–2x on top of Tier 2**

### Artisan Caches — run on every deploy
```bash
php artisan route:cache
php artisan config:cache
php artisan event:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
```

### Disable Unused Service Providers
Remove unused providers from `config/app.php` (Broadcasting, Notifications, etc.)

### Raw Queries on Hot Paths
```php
// Slow — Eloquent hydrates model objects
User::where('id', $id)->first();

// Fast — no hydration overhead
DB::selectOne('SELECT id, name FROM users WHERE id = ?', [$id]);
```

### Aggressive Eager Loading — eliminate N+1
```php
User::with(['orders.items', 'profile'])->paginate(20);
```

### HTTP Response Caching on Read-Heavy Endpoints
```php
return Cache::remember("user:{$id}", 300, fn() =>
    response()->json(User::find($id))
);
```

---

## Tier 4 — Swoole Superpowers
**Expected gain: approaching Go/Bun territory for hot data**

### Swoole Tables — shared in-memory across ALL workers, ~2M ops/s
```php
// config/octane.php
'tables' => [
    'rate_limits' => [
        'rows' => 1000,
        'columns' => [
            ['name' => 'count',    'type' => Table::TYPE_INT, 'size' => 4],
            ['name' => 'reset_at', 'type' => Table::TYPE_INT, 'size' => 4],
        ],
    ],
],

// Usage — no Redis round-trip
Octane::table('rate_limits')->set($ip, ['count' => $n, 'reset_at' => time() + 60]);
```

Use for: rate limiting, feature flags, config hot-reload, counters, hot-user sessions.

### Octane Cache (Swoole-backed, faster than Redis for local data)
```php
Octane::cache()->put('key', $value, ttl: 60);
Octane::cache()->get('key');
```

### Octane Ticks — background work inside worker, no queue overhead
```php
Octane::tick('refresh-config', fn() => Cache::forget('app_config'))
      ->seconds(60);
```

---

## Tier 5 — Infrastructure
**Final gap closure + horizontal scale**

### Nginx Upstream Cache
```nginx
fastcgi_cache_valid 200 10s;
add_header X-Cache $upstream_cache_status;
```

### DB Read Replica
```php
// config/database.php
'read'   => ['host' => env('DB_READ_HOST')],
'write'  => ['host' => env('DB_WRITE_HOST')],
'sticky' => true,
```

### Horizontal Scaling
Octane workers are stateless (if state is flushed correctly). Load balance freely behind nginx/HAProxy.

### Nuclear Option — Go/Bun Sidecar
For truly uncacheable hot paths where PHP physically cannot match: co-deploy a Go or Bun microservice for that specific endpoint only.

---

## Expected Outcomes

| After | RPS | vs Go/Bun |
|---|---|---|
| Now (PHP-FPM baseline) | ~900 | 100x behind |
| + Tier 1 (Octane + Swoole) | ~8,000 | 12x behind |
| + Tier 2 (I/O + connections) | ~15,000–25,000 | 5x behind |
| + Tier 3 (app layer) | ~25,000–40,000 | 3x behind |
| + Tier 4 (Swoole tables + cache) | ~50,000–100,000+ | matched / ahead |
| + Tier 5 (infra + scale) | horizontal | win |

---

## Execution Order

- [ ] 1. Octane + Swoole — biggest single win, everything builds on it
- [ ] 2. OPcache config — free, no code changes
- [ ] 3. Redis everywhere — sessions, cache, queues
- [ ] 4. Artisan caches — 15 min work, permanent gain
- [ ] 5. Response caching on top 10 read-heavy routes
- [ ] 6. Swoole Tables for rate limiting + feature flags
- [ ] 7. Raw queries on profiled slow paths only — don't prematurely optimize
- [ ] 8. Horizontal scale when single node saturated

---

## Honest Assessment

For non-cached dynamic computation, PHP will not match Go byte-for-byte.
For real-world web apps (read-heavy, cacheable data, CRUD), properly configured Laravel Octane + Swoole Tables + Redis can serve the same effective throughput as Go/Bun from the client's perspective.
The goal is achievable.

---

## Infrastructure — Nginx RAM Cache (Deep Dive)

### Why this matters
Nginx serving from RAM = PHP never wakes up. Even Octane + Swoole can only do ~10k RPS. Nginx from `/dev/shm` (RAM disk) does **100k–500k+ RPS at 0.5ms**. This is the single biggest infrastructure win.

### Full nginx config
```nginx
# Define RAM-backed cache zone — store in /dev/shm, not disk
fastcgi_cache_path /dev/shm/nginx_cache
    levels=1:2
    keys_zone=LARAVEL:100m        # 100MB key index in RAM
    max_size=1g                   # 1GB max cache body
    inactive=60s                  # purge entries unused for 60s
    use_temp_path=off;            # write directly to cache, skip temp dir

fastcgi_cache_key "$scheme$request_method$host$request_uri";

server {
    listen 80;

    # Cache timeouts
    fastcgi_cache LARAVEL;
    fastcgi_cache_valid 200 10s;          # 200 responses: cache 10s (micro-cache)
    fastcgi_cache_valid 404 1m;
    fastcgi_cache_use_stale error timeout updating
                       http_500 http_503; # serve stale on backend failure
    fastcgi_cache_background_update on;   # refresh cache in background, no user waits
    fastcgi_cache_lock on;                # only 1 worker rebuilds a cache entry at a time (dog-pile protection)

    # Skip cache for mutations and authenticated sessions
    fastcgi_cache_bypass $skip_cache;
    fastcgi_no_cache $skip_cache;

    add_header X-Cache $upstream_cache_status; # HIT / MISS / BYPASS for debugging

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # or Swoole socket
        include fastcgi_params;
    }
}

# Logic to skip cache
map $request_method $skip_cache_method {
    default 0;
    POST    1;
    PUT     1;
    PATCH   1;
    DELETE  1;
}

map $http_cookie $skip_cache_cookie {
    default              0;
    "~*laravel_session"  1;   # skip cache for logged-in users
}

map $request_uri $skip_cache_uri {
    default 0;
    "~*/api/user*"   1;        # skip cache for user-specific API endpoints
    "~*/admin*"      1;
    "~*/checkout*"   1;
}

# Combine all skip conditions
geo $skip_cache {
    default 0;
}
# In server block:
# set $skip_cache $skip_cache_method;
# if ($skip_cache_cookie) { set $skip_cache 1; }
# if ($skip_cache_uri)    { set $skip_cache 1; }
```

### Cache hit rate strategy
Target: **>90% hit rate**. Below 80%, average latency collapses to Octane speed.

| Cache TTL | Use case | Hit rate potential |
|---|---|---|
| 1–5s | High-traffic API lists (products, articles) | 95%+ |
| 10s | Search results, category pages | 90%+ |
| 60s | User-agnostic config/settings endpoints | 99% |
| 0 (bypass) | Auth, cart, checkout, user-specific | 0% (correct) |

### Mount `/dev/shm` for RAM-only cache
```bash
# /dev/shm is already RAM on Linux — just point nginx cache_path there
# Verify it's tmpfs:
df -h /dev/shm

# Optional: dedicated mount with size limit
# /etc/fstab:
tmpfs /dev/shm tmpfs defaults,size=2G 0 0
```

### Expected numbers
| Config | RPS | Latency |
|---|---|---|
| PHP-FPM, no cache | ~900 | ~150ms |
| Octane + Swoole, no cache | ~8,000 | ~15ms |
| Nginx disk cache | ~50,000 | ~2ms |
| Nginx RAM (`/dev/shm`) cache | ~200,000–500,000 | ~0.3–0.5ms |

---

## PHP Bottlenecks After Octane — Why the Gap Remains

Octane eliminates bootstrap cost. But these 6 problems remain in PHP's foundation.

### 1. Zend VM Interpreter Layer
Every PHP operation runs through the Zend virtual machine, even with OPcache. OPcache removes parsing (`.php` → opcodes). JIT compiles some hot opcodes to native machine code. But JIT coverage is partial — PHP is I/O-bound, not CPU-bound, so most of the Zend VM overhead in web apps is in I/O dispatch, not computation. JIT buys **5–10%** for Laravel, not 50%.

Rust/Go: machine code directly, no interpreter, no VM dispatch.

### 2. Dynamic Typing — Runtime Type Checks Everywhere
```php
// PHP: $a + $b
// Internally: check zval type of $a, check zval type of $b,
//             possibly convert, then add. ~10 CPU instructions.

// Rust: a + b
// One MOV + ADD instruction.
```
PHP must check types at runtime for every single operation. Laravel's service container, Eloquent, and request pipeline execute thousands of these per request.

### 3. zval Memory Overhead — Everything Is 16 Bytes Minimum
```
PHP integer:   16 bytes (zval struct: type + value + gc_info + u2)
PHP string:    16 bytes (zval) + 24 bytes (zend_string header) + length
PHP array[0]:  hash lookup — not pointer arithmetic

Rust u64:      8 bytes, stack-allocated
Rust String:   24 bytes total (ptr + len + cap), zero GC overhead
Rust vec[0]:   pointer + offset = 1 CPU instruction
```

A PHP array of 1,000 users: ~400–800KB.
A Rust Vec of 1,000 structs: ~40–80KB.
Laravel JSON-encodes 1,000 Eloquent models: allocates + GCs that full memory every request.

### 4. PHP Garbage Collector — Cycle GC Pauses
PHP uses reference counting + a cycle collector. Cycle collection runs periodically and causes stop-the-world pauses. Worse under Octane — workers are long-lived, so GC accumulates more cycles between collections.

Rust: no GC (ownership model). Go: concurrent GC with very short pauses. PHP: periodic stop-the-world.

**Mitigation:**
```php
// Octane config — recycle workers before GC pressure builds
'max_requests' => 250,  // lower = more frequent recycling = less GC pause

// In hot loops, explicitly unset large allocations
unset($largeCollection);
gc_collect_cycles(); // force early if you know you just freed a lot
```

### 5. HashTable Arrays — No Contiguous Memory
PHP arrays are always ordered HashMaps. `$arr[0]` is not `*(ptr + 0)`. It's a hash lookup:
```
hash(0) → bucket → linked list traversal → zval
```
Every array operation has this overhead. Laravel pipelines, middleware stacks, collections, query builders — all use PHP arrays internally.

### 6. Laravel-Specific: Container Resolution Cost
Even with Octane (singletons cached), non-singleton bindings resolve on every request:
```php
// Every request resolves these fresh:
app(Request::class)           // PSR-7 request wrapping
app(ResponseFactory::class)   // response building
app(Gate::class)              // auth gate
app(Translator::class)        // if using i18n
```
Container resolution = reflection + closure execution + type hint checking.

**Mitigation:** Bind hot services as singletons explicitly:
```php
// AppServiceProvider::register()
$this->app->singleton(MyHotService::class);
$this->app->singleton(MyRepository::class);
```

---

## PHP.ini — Full Production Settings

```ini
; ---- OPcache ----
opcache.enable=1
opcache.enable_cli=0
opcache.memory_consumption=256          ; MB — increase for large apps
opcache.interned_strings_buffer=32      ; MB — for string deduplication
opcache.max_accelerated_files=20000     ; match: find /var/www -name "*.php" | wc -l
opcache.validate_timestamps=0           ; PROD ONLY — don't stat() files each request
opcache.revalidate_freq=0
opcache.save_comments=1                 ; required by Laravel/Doctrine annotations
opcache.preload=/var/www/vendor/autoload.php
opcache.preload_user=www-data
opcache.huge_code_pages=1               ; use 2MB pages for opcode = fewer TLB misses

; ---- JIT ----
opcache.jit=tracing                     ; tracing JIT — best for web workloads
opcache.jit_buffer_size=100M            ; don't go above 256M — diminishing returns

; ---- Regex ----
pcre.jit=1                              ; JIT-compile regex patterns

; ---- Memory ----
memory_limit=256M                       ; per Octane worker
; With Swoole, each worker is persistent — tune based on: workers * memory_limit < available RAM

; ---- Execution ----
max_execution_time=30                   ; set to 0 for Octane/Swoole workers (managed by Octane)
zend.assertions=-1                      ; PROD: disable assert() overhead completely

; ---- Error handling (prod) ----
display_errors=Off
log_errors=On
error_reporting=E_ALL & ~E_DEPRECATED & ~E_STRICT

; ---- Serialization ----
serialize_precision=-1                  ; use minimum digits for float JSON encoding
json.exceptions=0

; ---- Session (use Redis instead, but if PHP sessions) ----
session.gc_probability=0                ; let Redis TTL handle expiry, not PHP GC
```

### Replace malloc with jemalloc (Linux — significant gain for PHP)
```bash
# Install jemalloc
apt install libjemalloc2

# Start PHP-FPM or Swoole with jemalloc
LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libjemalloc.so.2 php-fpm

# For Octane/Swoole in systemd unit:
[Service]
Environment="LD_PRELOAD=/usr/lib/x86_64-linux-gnu/libjemalloc.so.2"
ExecStart=/usr/bin/php artisan octane:start --server=swoole
```
Gain: **10–30% reduction in memory usage + faster allocation** under concurrent workers.

---

## Database — DDL & MySQL/Postgres Settings

### MySQL Production `my.cnf`
```ini
[mysqld]
# Buffer pool — most important setting
# Rule: 70-80% of RAM on dedicated DB server, 50% on shared
innodb_buffer_pool_size = 12G           ; example for 16GB RAM server
innodb_buffer_pool_instances = 8        ; 1 per GB of buffer pool, max 8

# Write performance
innodb_log_file_size = 1G               ; larger = less flushing, faster writes
innodb_flush_log_at_trx_commit = 2      ; 1=safest, 2=fast(flush per sec), 0=fastest/risky
innodb_flush_method = O_DIRECT          ; bypass OS cache (buffer pool IS the cache)

# I/O tuning for SSD
innodb_io_capacity = 2000               ; IOPS your SSD can handle
innodb_io_capacity_max = 4000
innodb_read_io_threads = 8
innodb_write_io_threads = 8

# Connections — tune for Octane workers
# Formula: max_connections = (workers_per_app_server * app_servers) * 1.5 + 20
max_connections = 200
thread_cache_size = 50                  ; reuse threads instead of spawning

# Disable query cache — harmful under concurrent load (global mutex)
query_cache_type = 0
query_cache_size = 0

# Temp tables
tmp_table_size = 128M
max_heap_table_size = 128M              ; for GROUP BY / ORDER BY in memory

# Slow query log — find bottlenecks
slow_query_log = 1
slow_query_log_file = /var/log/mysql/slow.log
long_query_time = 0.1                   ; 100ms threshold
log_queries_not_using_indexes = 1
```

### DDL — Schema-Level Performance

**Column types — smaller = faster index + less memory:**
```sql
-- BAD: wastes index space, slower comparisons
status VARCHAR(255)
user_id BIGINT          -- 8 bytes when INT (4 bytes) is enough for <2B rows
is_active VARCHAR(5)    -- storing "true"/"false" strings

-- GOOD
status TINYINT          -- 0/1/2/3 for enum-like status
user_id INT UNSIGNED    -- 4 bytes, handles up to 4.2B rows
is_active TINYINT(1)    -- proper boolean
```

**Indexes — the single biggest query performance lever:**
```sql
-- Composite index — column order matters
-- Rule: equality conditions first, range condition last
-- Query: WHERE user_id = ? AND status = ? AND created_at > ?
CREATE INDEX idx_orders_user_status_date
    ON orders(user_id, status, created_at);

-- Covering index — index contains ALL columns the query needs
-- Query never touches table data (index-only scan)
-- Query: SELECT id, name, price FROM products WHERE category_id = ?
CREATE INDEX idx_products_covering
    ON products(category_id, id, name, price);

-- Partial index — index only active rows (smaller, faster)
CREATE INDEX idx_active_users
    ON users(email)
    WHERE deleted_at IS NULL;     -- Postgres only; MySQL: use generated column
```

**Avoid these DDL mistakes:**
```sql
-- BAD: NULLable indexed column — index stores NULL entries, wastes space
CREATE INDEX idx_email ON users(email);  -- if email is NULLable

-- BAD: Function on indexed column — index not used
WHERE DATE(created_at) = '2024-01-01'   -- full table scan
-- GOOD: range on raw column — uses index
WHERE created_at >= '2024-01-01' AND created_at < '2024-01-02'

-- BAD: LIKE with leading wildcard — full table scan
WHERE name LIKE '%smith%'
-- GOOD: use full-text index
ALTER TABLE users ADD FULLTEXT(name);
WHERE MATCH(name) AGAINST('smith' IN BOOLEAN MODE)
```

### Connection Pooling — ProxySQL (eliminates connection overhead)
```
App Servers (Laravel Octane × N workers)
         ↓
     ProxySQL :6033          ← connection pool, query routing, read/write split
         ↓             ↓
   MySQL Primary    MySQL Replica(s)
```

ProxySQL keeps persistent connections to MySQL. Laravel workers connect to ProxySQL (cheap), ProxySQL reuses its pool (expensive connections already open). Eliminates TCP handshake + auth per request.

```bash
# Install ProxySQL
apt install proxysql

# Laravel .env — point to ProxySQL, not MySQL directly
DB_HOST=127.0.0.1
DB_PORT=6033
```

### Laravel DB config for Octane
```php
// config/database.php
'mysql' => [
    'driver'    => 'mysql',
    'options'   => [
        PDO::ATTR_PERSISTENT         => true,  // reuse connection across requests in same worker
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements = safer + faster
        PDO::MYSQL_ATTR_COMPRESS     => false, // disable if DB is local (no network)
    ],
    'read'  => ['host' => [env('DB_READ_HOST', '127.0.0.1')]],
    'write' => ['host' => [env('DB_WRITE_HOST', '127.0.0.1')]],
    'sticky' => true,  // route to write after write (avoid read-your-own-writes bug)
],
```

---

## Updated Execution Order

- [ ] 1. Octane + Swoole (Tier 1)
- [ ] 2. OPcache + JIT + jemalloc php.ini tuning
- [ ] 3. Redis everywhere — sessions, cache, queues
- [ ] 4. Nginx RAM cache (`/dev/shm`) with micro-cache strategy
- [ ] 5. MySQL `my.cnf` — buffer pool, flush settings, disable query cache
- [ ] 6. DDL audit — fix column types, add composite + covering indexes
- [ ] 7. Artisan caches on every deploy
- [ ] 8. ProxySQL connection pooling
- [ ] 9. Response caching on top 10 read-heavy routes
- [ ] 10. Swoole Tables for rate limiting + feature flags
- [ ] 11. Bind hot services as singletons in AppServiceProvider
- [ ] 12. Raw queries on profiled slow paths
- [ ] 13. Horizontal scale + read replica when single node saturated
