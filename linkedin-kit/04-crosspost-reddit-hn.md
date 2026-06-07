# Cross-post versions (Reddit + Hacker News + dev.to)

> These communities punish marketing/hype HARD and reward honesty + detail. Lead with the technical caveat, never with the big number. No emojis on HN. Light emojis OK on Reddit.

---

## Hacker News — "Show HN" title options
Pick one. HN titles must be plain, no hype, no clickbait.

1. `Show HN: 50k RPS from a $12 VPS – but the PHP app only does 401 (here's why)`
2. `Squeezing 50k RPS from a 2-core Laravel box (the honest breakdown)`
3. `What "50,000 RPS on a cheap server" actually means (full Laravel stack)`

### HN post body (text)
> I run a small Laravel app on a $12/month, 2-core/2GB VPS. I wanted to see how far the stack could go without touching the hardware.
>
> The headline number is 50,839 RPS, but I want to be upfront: that's Nginx serving a cached response out of /dev/shm (RAM). PHP never executes for those requests. The actual PHP app does 401 RPS. The entire multiplier is the caching layer, not a faster runtime — and I think that distinction is the actually-useful part of the writeup.
>
> Stack: FrankenPHP (Octane), OPcache + preload, Nginx RAM micro-cache, Cloudflare free tier, Brotli, MariaDB tuned for 2GB, Redis split by concern.
>
> A couple of findings that surprised me:
> - JIT did almost nothing (0–3%) because Laravel is I/O-bound. OPcache preload was the real CPU win.
> - I evaluated ProxySQL and skipped it after measuring — actual DB connection counts under load were tiny.
> - 8 workers on 2 cores gave zero throughput gain over 4, just double the RAM.
>
> Full step-by-step with the wrk commands and before/after numbers: [LINK]
>
> Happy to answer questions / get torn apart on the methodology.

> ⚠️ HN tip: post Tue–Thu ~8–10am ET. Reply fast and non-defensively. If someone finds a flaw, thank them and update — that earns upvotes on HN more than defending does.

---

## Reddit — r/PHP and r/laravel

### Title
`How we got 50k RPS out of a $12 Laravel server — and why our PHP app only does 401 (full breakdown)`

### Body
> Wrote up every optimization we made on a 2-core/2GB VPS running Laravel 12. Trying to keep it honest, so the most important line first:
>
> **The 50,000 RPS is Nginx serving cache from RAM. Raw PHP is 401 RPS.** The win is architectural — making PHP run as rarely as possible — not a magic runtime.
>
> What's in it:
> - Swoole → FrankenPHP (OPcache preload was crashing on Swoole 6.x anon classes)
> - OPcache + preload (JIT was basically a no-op for our I/O-bound workload — curious if others see the same)
> - Nginx micro-cache in /dev/shm → 0.38ms hits
> - Cloudflare free tier cache rule
> - Brotli L9, HTML minify middleware
> - MariaDB tuning for 2GB RAM
> - Why we measured and then SKIPPED ProxySQL
>
> Full writeup + benchmark commands: [LINK]
>
> Genuinely interested in critique — especially if anyone's pushed FrankenPHP further on small boxes, or has data on JIT actually helping a real Laravel app.

> ⚠️ Reddit tip: r/PHP and r/laravel allow self-posts but hate pure link-drops. Put the real content in the post, link at the bottom. Engage in comments for the first few hours.

---

## dev.to
Just publish the FULL article (LINKEDIN_ARTICLE.md) as-is — dev.to rewards long technical content and it ranks on Google.

- Title: `How We Built a Laravel Stack That Serves 50,000 RPS on a $12/month Server`
- Tags: `php`, `laravel`, `performance`, `devops`
- Add a canonical URL if you also post it elsewhere, to avoid SEO duplicate-content splitting.
- Add a cover image (the carousel slide 1 works perfectly).
- dev.to link is the cleanest one to drop in your LinkedIn first-comment.

---

## Funnel summary (how the pieces connect)
```
HN / Reddit / dev.to   →  drive technical readers + SEO + backlinks
        ↓
LinkedIn hook post     →  your network + "CONFIG" comment leads
        ↓
First comment link     →  dev.to full article
        ↓
"Comment CONFIG" DMs    →  warm leads → Trentiums client conversations
```
Post the dev.to article FIRST (it's the link target), then LinkedIn, then HN/Reddit pointing at dev.to.
