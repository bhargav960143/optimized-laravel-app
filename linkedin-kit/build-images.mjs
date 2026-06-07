// Generates LinkedIn carousel PNGs (1080x1080 @2x) from slide data.
// Renders each slide as standalone HTML, screenshots via headless Chrome. No npm deps.
//
// Run:  node linkedin-kit/build-images.mjs
// Out:  linkedin-kit/images/slide-01.png ... slide-10.png  + deck.html (preview)

import { execFileSync } from 'node:child_process';
import { mkdirSync, writeFileSync, rmSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const OUT = join(__dirname, 'images');
const TMP = join(OUT, '.tmp');
mkdirSync(OUT, { recursive: true });
mkdirSync(TMP, { recursive: true });

const CHROME =
  process.env.CHROME_PATH ||
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

// --- Brand ---
const BG = '#0B0E14';        // near-black
const CARD = '#11161F';
const ACCENT = '#FF4F4F';    // red accent for the big numbers
const ACCENT2 = '#36D399';   // green for "good"
const TEXT = '#E6EAF2';
const MUTE = '#8B95A7';
const BRAND = 'Trentiums';

// --- Slides ---
// kicker = small top label, big = hero line(s), body = supporting lines (array)
const slides = [
  {
    kicker: 'PERFORMANCE / LARAVEL',
    big: '$12/mo server.\n50,000 req/sec.',
    body: ["Here's every step.", 'And the one number everyone fakes.'],
    foot: 'swipe →',
    hero: true,
  },
  {
    kicker: 'THE HONEST TWIST',
    bigNum: '401 RPS',
    body: [
      'That’s what the PHP app actually does.',
      'The 50,000 is Nginx serving cache from RAM —',
      'PHP never runs for those requests.',
    ],
  },
  {
    kicker: 'THE BOX',
    bigNum: '2 cores · 2GB',
    body: [
      'Debian 12 · Laravel 12',
      'No autoscaling. No load balancer.',
      'The server most startups launch on.',
    ],
  },
  {
    kicker: 'STEP 1',
    big: 'Swoole →\nFrankenPHP',
    body: [
      'Swoole crashed OPcache preload.',
      'FrankenPHP unlocked it.',
      'Drop-in swap of one flag.',
    ],
  },
  {
    kicker: 'STEP 2',
    big: 'Preload > JIT',
    body: [
      'Laravel is I/O-bound — JIT moves 0–3%.',
      'OPcache preload is the real win.',
      'VALIDATE_TIMESTAMPS=0 in prod.',
    ],
  },
  {
    kicker: 'STEP 3 — THE BIG ONE',
    bigNum: '0.38 ms',
    body: [
      'Nginx micro-cache in /dev/shm (RAM).',
      'Cache hits never touch PHP.',
      'This is the 50,000 RPS layer.',
    ],
    accent: ACCENT2,
  },
  {
    kicker: 'STEP 4',
    big: '0 requests\nto origin',
    body: [
      'Cloudflare free tier, one cache rule.',
      'On a hit, the request dies at the edge.',
      'Your server never hears about it.',
    ],
  },
  {
    kicker: 'STEPS 5–8 — THE POLISH',
    big: 'The polish',
    list: [
      'Brotli L9 → HTML 14.7% smaller than gzip',
      'Custom HTML minify middleware',
      'MariaDB tuned for 2GB (512MB pool, O_DIRECT)',
      'Redis split: cache / session / queue',
    ],
  },
  {
    kicker: 'THE LESSON',
    big: 'Measure first.',
    body: [
      'We almost added ProxySQL.',
      'Measured DB connections under load — tiny.',
      'Most scaling problems are caching problems.',
    ],
  },
  {
    kicker: 'YOUR MOVE',
    big: 'Want the\nconfigs?',
    body: [
      'Full writeup + Nginx config + Octane service file.',
      '💬  Comment “CONFIG” and I’ll send it.',
      '♻️  Repost if this saved you a server upgrade.',
    ],
    cta: true,
  },
];

const esc = (s) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
const nl = (s) => esc(s).replace(/\n/g, '<br>');

function slideHtml(s, i) {
  const accent = s.accent || ACCENT;
  const hero = s.bigNum
    ? `<div class="num" style="color:${accent}">${nl(s.bigNum)}</div>`
    : s.big
    ? `<div class="big">${nl(s.big)}</div>`
    : '';
  const body = (s.body || [])
    .map((l) => `<p class="body">${nl(l)}</p>`)
    .join('');
  const list = s.list
    ? `<ul class="list">${s.list
        .map((l) => `<li>${esc(l)}</li>`)
        .join('')}</ul>`
    : '';
  const rightFoot = s.foot
    ? `<span class="swipe">${esc(s.foot)}</span>`
    : `<span class="idx">${String(i + 1).padStart(2, '0')} / ${String(
        slides.length
      ).padStart(2, '0')}</span>`;
  return `<!doctype html><html><head><meta charset="utf-8"><style>
  @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&family=JetBrains+Mono:wght@600&display=swap');
  *{margin:0;padding:0;box-sizing:border-box}
  html,body{width:1080px;height:1080px}
  body{background:${BG};font-family:Inter,system-ui,sans-serif;color:${TEXT};
    display:flex;align-items:center;justify-content:center;overflow:hidden}
  .card{width:1080px;height:1080px;padding:90px 96px;display:flex;flex-direction:column;
    position:relative;background:
      radial-gradient(1200px 500px at 80% -10%, rgba(255,79,79,.10), transparent 60%),
      radial-gradient(900px 500px at -10% 110%, rgba(54,211,153,.08), transparent 55%),
      ${BG};}
  .kicker{font-size:26px;font-weight:800;letter-spacing:3px;color:${accent};
    text-transform:uppercase;margin-bottom:auto}
  .center{margin:auto 0;display:flex;flex-direction:column;gap:34px}
  .num{font-family:'JetBrains Mono',monospace;font-weight:600;font-size:150px;line-height:1.02;letter-spacing:-2px}
  .big{font-weight:900;font-size:118px;line-height:1.04;letter-spacing:-3px}
  ${s.hero ? `.big{font-size:104px}` : ''}
  .body{font-size:38px;line-height:1.45;color:${MUTE};font-weight:400;max-width:880px}
  .body:first-of-type{color:${TEXT};font-weight:600}
  .list{list-style:none;display:flex;flex-direction:column;gap:26px;margin-top:10px}
  .list li{font-size:40px;font-weight:600;color:${TEXT};padding-left:54px;position:relative;line-height:1.3}
  .list li:before{content:'▹';position:absolute;left:0;color:${accent};font-weight:800}
  .swipe{font-size:30px;font-weight:800;color:${accent};letter-spacing:1px}
  .footer{margin-top:auto;display:flex;justify-content:space-between;align-items:center;
    font-size:26px;color:${MUTE};font-weight:600;border-top:1px solid rgba(255,255,255,.08);padding-top:30px}
  .brand{color:${TEXT}}
  .idx{font-family:'JetBrains Mono',monospace}
  ${s.cta ? `.card{background:radial-gradient(1100px 700px at 50% 40%, rgba(255,79,79,.16), transparent 60%),${BG}}` : ''}
  </style></head><body><div class="card">
    <div class="kicker">${esc(s.kicker)}</div>
    <div class="center">${hero}${body}${list}</div>
    <div class="footer"><span class="brand">${BRAND}</span>${rightFoot}</div>
  </div></body></html>`;
}

// Render each slide → PNG via headless Chrome
let made = 0;
for (let i = 0; i < slides.length; i++) {
  const n = String(i + 1).padStart(2, '0');
  const htmlPath = join(TMP, `slide-${n}.html`);
  const pngPath = join(OUT, `slide-${n}.png`);
  writeFileSync(htmlPath, slideHtml(slides[i], i), 'utf8');
  execFileSync(
    CHROME,
    [
      '--headless=new',
      '--disable-gpu',
      '--hide-scrollbars',
      '--force-device-scale-factor=2',
      '--window-size=1080,1080',
      '--default-background-color=00000000',
      `--screenshot=${pngPath}`,
      `file:///${htmlPath.replace(/\\/g, '/')}`,
    ],
    { stdio: 'ignore' }
  );
  made++;
  console.log(`✓ slide-${n}.png`);
}

// Preview deck (all slides in one scrollable page)
const deck = `<!doctype html><meta charset="utf-8"><title>Carousel preview</title>
<body style="margin:0;background:#05070b;display:flex;flex-wrap:wrap;gap:24px;padding:24px;justify-content:center">
${slides
  .map(
    (_, i) =>
      `<img src="images/slide-${String(i + 1).padStart(
        2,
        '0'
      )}.png" style="width:480px;height:480px;border-radius:14px;box-shadow:0 8px 40px rgba(0,0,0,.6)">`
  )
  .join('\n')}
</body>`;
writeFileSync(join(__dirname, 'deck.html'), deck, 'utf8');

rmSync(TMP, { recursive: true, force: true });
console.log(`\nDone. ${made} PNGs in linkedin-kit/images/`);
console.log('Preview: open linkedin-kit/deck.html');
