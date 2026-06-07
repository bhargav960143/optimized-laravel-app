# First Comment (post this yourself, immediately after publishing)

> The post body should NOT contain the link. Drop it here in the first comment so LinkedIn doesn't throttle the post's reach. Pin this comment.

---

Full step-by-step writeup here 👇
[LINK — your LinkedIn Article, dev.to post, or GitHub repo]

It covers all 10 steps with the real before/after numbers, the exact `wrk` benchmark commands, and the honest caveats (what the 50k number does and doesn't mean).

Want the actual config files? Comment **"CONFIG"** below and I'll DM you:
🔹 The Nginx RAM-cache + Brotli config
🔹 The Octane / FrankenPHP systemd service file
🔹 The HTML minify middleware

Happy to answer any setup questions in the thread.

---

## Reply template for when people comment "CONFIG"
> Sent! 📩 Check your DMs. If you get it working on your stack, I'd love to hear your before/after numbers — drop them here so others can compare.

## Reply template for "but 50k is just cache, that's cheating"
> 100% — and that's exactly the point I'm making. The post says it outright: raw PHP is 401 RPS. The skill isn't making PHP do 50k, it's architecting so PHP almost never runs for public traffic. Cache is the strategy, not a cheat. 🙂

## Reply template for "why not [Swoole / RoadRunner / k8s / serverless]?"
> Good question — we ran Swoole first (OPcache preload kept crashing on anonymous classes in 6.x). FrankenPHP fixed that specific issue for us. RoadRunner's a solid alternative; the caching layers in front matter way more than the runtime choice for this workload. k8s/serverless would be over-engineering for a single 2-core box doing 401 RPS of real PHP.
