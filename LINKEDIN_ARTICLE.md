# How We Squeezed 50,000+ RPS from a $12/month Server (Real Benchmarks, Every Step)

Most performance guides show you theory. This one shows you actual numbers from a real production server — a 2-core, 2GB RAM VPS running a Laravel 12 taxi booking app.

Here's every optimization we made, in order, with before/after results.

---

## The Server

- **Plan:** General purpose, dual-stack
- **CPU:** 2 vCPUs
- **RAM:** 2GB
- **Storage:** 60GB SSD
- **Transfer:** 1.5TB/month
- **OS:** Debian 12
- **App:** Laravel 12 (SwiftRide — taxi & tour booking)

Budget tier. No autoscaling. No load balancer. The kind of server most startups actually launch on.

---

## The Goal

Serve a dynamic Laravel app as fast as possible without changing the hardware. Handle real traffic spikes without crashing.

Here's what we achieved at the end:

| Layer | RPS | Avg Latency |
|---|---|---|
| FrankenPHP (PHP app, cold) | 401 | 24.9ms |
| Nginx RAM cache (HIT) | **50,839** | **0.38ms** |
| Cloudflare edge cache | 809 | 82ms |

That 127x multiplier between cold PHP and cached response — that's the story.

---

## Step 1: Swap Swoole for FrankenPHP

We started with Laravel Octane + Swoole 6.x. It works, but Swoole had a blocking issue: **OPcache preloading crashes with anonymous classes** in Swoole 6.x. We had to disable preload entirely.

FrankenPHP (v1.12.4) fixed that. It embeds PHP 8.5.7 with its own runtime, ships with the **mimalloc allocator** (lower memory fragmentation), and unlocks OPcache preload.

**Migration:** Drop-in swap. Change `--server=swoole` to `--server=frankenphp` in the Octane start command.

```ini
ExecStart=/usr/bin/php8.3 artisan octane:start \
  --server=frankenphp \
  --host=127.0.0.1 \
  --port=8000 \
  --workers=4
```

---

## Step 2: OPcache + JIT (Now Unblocked)

With FrankenPHP, we enabled what Swoole was blocking:

```ini
PHP_OPCACHE_JIT=tracing
PHP_OPCACHE_JIT_BUFFER_SIZE=64M
PHP_OPCACHE_MEMORY_CONSUMPTION=128
PHP_OPCACHE_MAX_ACCELERATED_FILES=15000
PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
PHP_OPCACHE_PRELOAD=/path/to/preload.php
```

`VALIDATE_TIMESTAMPS=0` is critical in production — stops OPcache from checking file modification times on every request. On a 2-core machine this saves a measurable amount of syscall overhead.

---

## Step 3: Nginx RAM Micro-Cache

This is the biggest single win.

Store the Nginx proxy cache in `/dev/shm` (RAM-backed tmpfs) instead of disk:

```nginx
proxy_cache_path /dev/shm/nginx_octane_cache
    levels=1:2
    keys_zone=OCTANE:64m
    max_size=512m
    inactive=60s
    use_temp_path=off;
```

For the home page (cacheable, public):

```nginx
location = / {
    proxy_cache OCTANE;
    proxy_cache_valid 200 30s;
    proxy_cache_use_stale error timeout updating;
    proxy_cache_background_update on;
    proxy_cache_lock on;
}
```

**Result:** Cache HITs never touch PHP at all. Nginx reads from RAM and responds in **0.38ms average**. At 50,000+ RPS, the PHP workers are completely idle for cached pages.

**Workers tested: 4 vs 8.** On a 2-core machine, 8 workers showed no RPS gain but doubled memory usage (217MB → 430MB). Stayed at 4.

---

## Step 4: Cloudflare Free Tier

Added Cloudflare with one Cache Rule:
- **Hostname:** your domain + **URI Path:** `/`
- **Edge TTL:** Ignore origin, use 30 seconds
- **Browser TTL:** Respect origin

This means the first visitor in 30 seconds hits our Nginx RAM cache. Every subsequent visitor in that window hits **Cloudflare's nearest PoP** — zero bytes leave their datacenter to reach us.

Result confirmed:
```
cf-cache-status: HIT
```

Also added all Cloudflare IP ranges to Nginx `set_real_ip_from` so real visitor IPs appear in logs, not Cloudflare proxy IPs.

---

## Step 5: Brotli Compression (Level 9)

Switched from gzip-only to Brotli + gzip fallback:

```nginx
brotli on;
brotli_comp_level 9;
brotli_static on;
```

**Payload comparison on our HTML:**
- Gzip level 4: ~8,034 bytes
- Brotli level 9: ~6,852 bytes
- **14.7% smaller** — meaningful at scale

Brotli level 9 has higher CPU cost at compression time, but `brotli_static on` pre-compresses static assets. For dynamic HTML, the tradeoff is worth it.

---

## Step 6: HTML Minification Middleware

Built a custom Laravel middleware that strips whitespace and HTML comments from responses:

```php
$html = preg_replace('/>\s+</s', '><', $html);
$html = preg_replace('/\s{2,}/s', ' ', $html);
```

Preserves `<pre>`, `<script>`, `<style>`, and `<textarea>` blocks. Strips `<!-- comments -->` (except IE conditionals).

**Applied to both `web` and `api` middleware groups** — every HTML response is minified before it hits the cache or Cloudflare.

---

## Step 7: MariaDB Tuning

```ini
innodb_buffer_pool_size = 512M
innodb_flush_method = O_DIRECT
innodb_flush_log_at_trx_commit = 2
```

On 2GB RAM, 512MB buffer pool is the right ceiling — leaves headroom for PHP workers (217MB), Redis (~50MB), and OS. `O_DIRECT` bypasses the OS page cache for InnoDB (avoids double caching). `flush=2` trades per-transaction fsync for 1-second batched flush — safe for a web app, big latency win.

---

## Step 8: Redis Multi-Database Separation

```env
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2
REDIS_QUEUE_DB=0
```

Separate DBs prevent sessions from evicting cache entries under memory pressure. Also makes `FLUSHDB` safe in dev without nuking queued jobs.

---

## Step 9: ProxySQL — When NOT to Add a Tool

We evaluated ProxySQL (connection pooling proxy for MySQL). On paper it sounds useful. In practice:

- Peak DB connections: **2** (PDO persistent connections)
- ProxySQL would add: 1–3ms latency per query for routing overhead

Decision: skip. Always measure before adding infrastructure.

---

## Step 10: Tailwind CDN → Vite Production Build

Caught late: `cdn.tailwindcss.com` was still loading in production (22KB runtime JS just to parse config). Replaced with a proper Vite build:

```bash
npm run build
# → public/build/assets/app.css  70KB (13.5KB gzip)
```

Tailwind v4 uses CSS-based config (`@theme` block in `app.css`) — no `tailwind.config.js` needed.

---

## What About Payment & Booking Under a Spike?

Caching wins the easy battle — listing and landing pages. But **payment and booking are writes**. They can't be cached. Every one of them must reach PHP and the database. So what happens when 5,000 people hit "Pay Now" or "Book this ride" in the same minute?

Here's how the write path holds up on the same 2-core box.

**1. The cache shields the writes.** Because product/listing pages serve from RAM and never touch PHP, all 4 workers stay free for the requests that *actually matter* — checkout and booking. You're not spending CPU rendering catalog pages during a rush; 100% of PHP capacity goes to transactions.

**2. Workers queue, they don't crash.** 4 FrankenPHP workers process bookings concurrently. Extra requests wait in Nginx's connection backlog (milliseconds), not error out. A booking takes ~25ms of PHP time, so 4 workers clear ~160 bookings/sec sustained — far above real booking rate even on a busy day.

**3. No double-booking — atomic DB locks.** The danger in a spike isn't speed, it's two people grabbing the *same* seat/slot. Solve at the database, not in PHP:

```php
DB::transaction(function () use ($rideId, $userId) {
    // Row lock — second request blocks until first commits
    $ride = Ride::where('id', $rideId)
        ->where('status', 'available')
        ->lockForUpdate()
        ->first();

    abort_if(!$ride, 409, 'Already booked');

    $ride->update(['status' => 'booked', 'user_id' => $userId]);
});
```

`lockForUpdate()` (SELECT ... FOR UPDATE) guarantees only one request wins a slot, even at 1,000 concurrent attempts. The rest get a clean "already booked" — never a corrupted state.

**4. Payment runs through a queue, not inline.** Calling Stripe/Razorpay inline ties up a worker for 1–3 seconds waiting on the gateway. Instead:
- Reserve the slot (fast DB lock above)
- Push payment-capture to a **Redis queue job**
- Return "processing" instantly; confirm via webhook

The worker frees in ~25ms instead of blocking 2s. One slow gateway can't exhaust all 4 workers.

```php
ProcessPayment::dispatch($booking)->onQueue('payments');
```

**5. Idempotency — safe retries.** A shopper double-clicks or Cloudflare retries a timeout. Use an idempotency key so the same booking/charge never executes twice:

```php
$lock = Cache::lock("booking:{$idempotencyKey}", 10);
abort_unless($lock->get(), 409, 'Duplicate request');
```

**6. Rate-limit the abuse, protect the real buyers.** Laravel throttle on the checkout route stops bots and accidental floods from eating worker capacity:

```php
Route::post('/book', BookController::class)
    ->middleware('throttle:10,1'); // 10 req/min per user
```

**The honest limit:** this box won't do 50,000 *bookings* per second — that's a DB-write ceiling, not a caching one. It comfortably handles a few hundred concurrent transactions/sec, which covers almost every real business. When genuine write volume outgrows one box, that's when you scale the DB (read replicas, bigger instance) — but you'll have the metrics to prove it first.

---

## The Full Stack

```
Browser → Cloudflare Edge (nearest PoP)
       → Nginx (RAM cache, Brotli, SSL termination)
       → Laravel Octane / FrankenPHP (4 workers, OPcache JIT)
       → Redis (cache, sessions, queues)
       → MariaDB (512MB buffer pool)
```

---

## Key Takeaways

**1. Caching layers multiply, not add.** Nginx RAM cache + Cloudflare means most requests never touch PHP. The PHP performance (401 RPS) barely matters for cacheable pages.

**2. Test before adding workers.** 8 workers on 2 cores = same throughput, double RAM. Always benchmark your specific hardware.

**3. Measure before adding tools.** ProxySQL, Redis Cluster, read replicas — none of these help when your bottleneck is elsewhere. Check connection counts, slow query logs, and CPU first.

**4. The free tier is enough.** Cloudflare free, 2GB VPS, open-source stack. Zero paid infrastructure beyond the server itself.

**5. OPcache preload matters.** The difference between preload-enabled and disabled is measurable on cold starts. Swoole blocking it was costing us on every worker restart.

---

**Final numbers on a $12/month server:**
- Cold PHP: 401 RPS
- Nginx RAM cache: 50,839 RPS
- Cloudflare edge: effectively unlimited for cached pages

If you're building on a budget and think you need to scale the box first — you probably don't. Optimize the stack.

---

*Built on: Laravel 12 · FrankenPHP · Nginx · MariaDB · Redis · Cloudflare Free · Debian 12*

*Stack is open source. Drop a comment if you want the Nginx config or the Octane service file.*
