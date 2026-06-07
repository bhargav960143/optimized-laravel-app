# LinkedIn Carousel Script (10 slides)

> Build in Canva/Figma → export as PDF → upload as "document" to LinkedIn. Carousels get the highest organic reach on LinkedIn right now.
> Design: dark background, one big number per slide, monospace font for code. Keep ≤15 words of body text per slide. Brand color accent on the numbers.

---

## Slide 1 — COVER (the hook)
**Big text:**
> $12/month server.
> 50,000 requests/sec.

**Small text bottom:**
> Here's every step. (And the number everyone fakes.)
> swipe →

---

## Slide 2 — THE HONEST TWIST
**Big:** 401 RPS
**Body:**
> That's what the PHP app actually does.
> The 50,000 is Nginx serving cache from RAM — PHP never runs.
> The whole trick: make PHP run as rarely as possible.

---

## Slide 3 — THE BOX
**Big:** 2 cores · 2GB RAM
**Body:**
> Debian 12 · Laravel 12 · no autoscaling · no load balancer.
> The kind of server most startups actually launch on.

---

## Slide 4 — STEP 1: FrankenPHP
**Big:** Swoole → FrankenPHP
**Body:**
> Swoole crashed OPcache preload. FrankenPHP unlocked it.
> Drop-in swap: --server=swoole → --server=frankenphp

---

## Slide 5 — STEP 2: OPcache + Preload
**Big:** Preload > JIT
**Body:**
> For Laravel (I/O-bound), JIT moves 0–3%.
> OPcache preload is the real win. VALIDATE_TIMESTAMPS=0 in prod.

---

## Slide 6 — STEP 3: Nginx RAM cache (the big one)
**Big:** 0.38ms
**Body:**
> Cache stored in /dev/shm (RAM, not disk).
> Cache hits never touch PHP. This is the 50,000 RPS layer.

---

## Slide 7 — STEP 4: Cloudflare free
**Big:** 0 requests to origin
**Body:**
> One cache rule. On a hit, the request dies at Cloudflare's edge.
> Your server never even hears about it.

---

## Slide 8 — STEPS 5–8: The polish
**Body (4 quick rows):**
> 🔹 Brotli L9 → HTML 14.7% smaller than gzip
> 🔹 Custom HTML minify middleware
> 🔹 MariaDB tuned for 2GB (512MB pool, O_DIRECT)
> 🔹 Redis split: cache / session / queue

---

## Slide 9 — THE LESSON
**Big:** Measure first.
**Body:**
> We almost added ProxySQL. Measured actual DB connections under load — barely any. Skipped it.
> Most "scaling" problems are caching problems in disguise.

---

## Slide 10 — CTA
**Big:** Want the configs?
**Body:**
> Full writeup + Nginx config + Octane service file.
> 💬 Comment "CONFIG" and I'll send it.
> ♻️ Repost if this saved you a server upgrade.

**Footer:** [Your name] · Trentiums · #Laravel #DevOps

---

## Caption to post WITH the carousel
> Most "I scaled to 50k RPS" posts hide one number: how slow the app actually is without caching.
>
> Ours does 401 RPS in raw PHP. Caching does the other 99%. That's not a flaw — that's the strategy.
>
> 10 slides, every step, on a $12/month box 👇
>
> Comment "CONFIG" for the Nginx + Octane files.
>
> #Laravel #DevOps #WebPerformance
