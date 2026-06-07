# LinkedIn Hook Post (ready to paste)

> Paste this as a normal text post. Put the article/repo link in the FIRST COMMENT, not in the post body (body links cut reach ~50%).

---

Our $12/month server handles 50,000 requests per second.

Same box most people look at and say "just upgrade it."

Here's the part nobody admits in those flashy benchmark posts 👇

The PHP app itself? It does 401 requests/sec. Not 50,000.

The 50,000 number is Nginx serving a cached copy straight from RAM — PHP never even runs for those requests.

So the "127x speedup" didn't come from making PHP faster. It came from making sure PHP runs as rarely as possible.

That distinction is the whole game. And almost every "I scaled to X RPS" post hides it.

Here's the actual stack, on a 2-core / 2GB Debian box running Laravel 12:

🔹 Swapped Swoole → FrankenPHP (unlocked OPcache preload Swoole was crashing on)
🔹 OPcache + preload (the real Laravel win — JIT barely moves the needle on I/O-bound apps)
🔹 Nginx micro-cache in /dev/shm (RAM-backed) → 0.38ms cache hits
🔹 Cloudflare free tier → origin gets ZERO requests on a cache hit
🔹 Brotli level 9 → 14.7% smaller HTML than gzip
🔹 Custom HTML minify middleware
🔹 MariaDB tuned for 2GB RAM (512MB buffer pool, O_DIRECT)
🔹 Redis split across cache / session / queue DBs

And the lesson that saved the most money:

We evaluated ProxySQL. Sounded great on paper. Then we measured actual DB connections under load — barely any. So we skipped it.

Measure before you add infrastructure. Almost every "scaling" problem is a caching problem wearing a disguise.

Full step-by-step writeup (with the real before/after numbers + the benchmark commands) in the comments 👇

If you're on a budget and think you need a bigger box first — you probably don't. You need a better stack.

#Laravel #DevOps #WebPerformance

---

## Variant B (cost-founder angle — test this against the one above)

I almost paid for a bigger server. Then I found out my $12/month box could do 50,000 requests/sec.

It just wasn't configured to.

No autoscaling. No load balancer. No paid infrastructure beyond the server itself. A 2-core, 2GB VPS running a Laravel app.

Here's every change we made — and the one number most benchmark posts quietly hide (our PHP app only does 401 RPS; caching does the other 99%).

[... same bullet list + CTA ...]

---

## Posting notes
- Best window: Tue–Thu, 8–10am your audience timezone.
- Reply to EVERY comment in the first 2 hours — early engagement decides reach.
- Don't edit the post for the first hour (edits can suppress distribution on some accounts).
- 3 hashtags max. More looks spammy and doesn't help.
