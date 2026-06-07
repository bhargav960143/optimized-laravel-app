// Generates the article banner (1200x630 @2x) for LinkedIn article cover / link preview.
// Run:  node linkedin-kit/build-banner.mjs   →  linkedin-kit/images/banner.png

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

const BG = '#0B0E14';
const ACCENT = '#FF4F4F';
const ACCENT2 = '#36D399';
const TEXT = '#E6EAF2';
const MUTE = '#8B95A7';
const BRAND = 'Trentiums';

const html = `<!doctype html><html><head><meta charset="utf-8"><style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800;900&family=JetBrains+Mono:wght@600;700&display=swap');
*{margin:0;padding:0;box-sizing:border-box}
html,body{width:1200px;height:630px}
body{background:${BG};font-family:Inter,system-ui,sans-serif;color:${TEXT};overflow:hidden}
.card{width:1200px;height:630px;padding:72px 80px;display:flex;flex-direction:column;position:relative;
  background:
    radial-gradient(900px 420px at 100% -20%, rgba(255,79,79,.16), transparent 60%),
    radial-gradient(800px 460px at -10% 120%, rgba(54,211,153,.10), transparent 55%),
    ${BG};}
.kicker{font-size:22px;font-weight:800;letter-spacing:4px;color:${ACCENT};text-transform:uppercase}
.title{margin-top:28px;font-weight:900;font-size:74px;line-height:1.04;letter-spacing:-2.5px;max-width:1040px}
.title .hl{color:${ACCENT}}
.sub{margin-top:26px;font-size:30px;line-height:1.4;color:${MUTE};font-weight:500;max-width:900px}
.sub b{color:${TEXT};font-weight:700}
.stats{margin-top:auto;display:flex;gap:64px;align-items:flex-end}
.stat .n{font-family:'JetBrains Mono',monospace;font-weight:700;font-size:50px;line-height:1}
.stat .l{font-size:20px;color:${MUTE};font-weight:600;margin-top:8px;letter-spacing:.5px}
.spacer{flex:1}
.brand{font-size:24px;font-weight:800;color:${TEXT}}
.tag{font-size:20px;color:${MUTE};font-weight:600}
.bar{position:absolute;left:0;top:0;height:8px;width:100%;
  background:linear-gradient(90deg,${ACCENT},${ACCENT2})}
</style></head><body><div class="card">
  <div class="bar"></div>
  <div class="kicker">Laravel · Performance · Real Benchmarks</div>
  <div class="title">50,000 RPS from a <span class="hl">$12/month</span> server</div>
  <div class="sub">Every optimization, in order — and the honest catch: raw PHP does <b>401 RPS</b>. Caching does the rest.</div>
  <div class="stats">
    <div class="stat"><div class="n" style="color:${MUTE}">401</div><div class="l">PHP, uncached</div></div>
    <div class="stat"><div class="n" style="color:${ACCENT2}">50,839</div><div class="l">Nginx RAM cache</div></div>
    <div class="stat"><div class="n" style="color:${TEXT}">0.38ms</div><div class="l">cache-hit latency</div></div>
    <div class="spacer"></div>
    <div style="text-align:right"><div class="brand">${BRAND}</div><div class="tag">2-core · 2GB · Debian 12</div></div>
  </div>
</div></body></html>`;

const htmlPath = join(TMP, 'banner.html');
const pngPath = join(OUT, 'banner.png');
writeFileSync(htmlPath, html, 'utf8');
execFileSync(
  CHROME,
  [
    '--headless=new',
    '--disable-gpu',
    '--hide-scrollbars',
    '--force-device-scale-factor=2',
    '--window-size=1200,630',
    '--default-background-color=00000000',
    `--screenshot=${pngPath}`,
    `file:///${htmlPath.replace(/\\/g, '/')}`,
  ],
  { stdio: 'ignore' }
);
rmSync(TMP, { recursive: true, force: true });
console.log('✓ images/banner.png  (1200x630 @2x = 2400x1260)');
