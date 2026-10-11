# Local performance baseline — 2026-10-07

Native Windows preview: PHP 8.4 development server, PostgreSQL 17, file cache/sessions, Node 24 SSR and built production frontend assets. The preview has seeded taxonomy and no published ads. This is a development baseline, not a production capacity test or mobile Web Vitals result.

## Measurements

Each HTTP route was warmed once, then measured five times with PowerShell `Invoke-WebRequest` and `Stopwatch`. These timings include receiving the HTML, but exclude downloading assets, rendering and user interaction.

| Page      | Five requests (ms)      | Median (ms) | HTML bytes |
| --------- | ----------------------- | ----------- | ---------- |
| Home      | 608, 658, 611, 633, 654 | 633         | 12,070     |
| Karachi   | 652, 660, 631, 680, 628 | 652         | 6,853      |
| Furniture | 750, 672, 648, 677, 626 | 672         | 6,918      |

All responses contained server-rendered content. Development-server overhead is substantial; measure again behind production PHP-FPM before setting latency targets.

Discovery reference data required **6 database queries cold and 0 warm**, measured with Laravel's query log around `MarketplaceOptions::discovery()`. The cache lasts five minutes and model writes invalidate it. Production uses the configured Redis cache; the native preview uses files. Do not infer Redis performance from these numbers.

## Decisions and next measurements

- Cache taxonomy/location options, not personalized HTML or listing visibility. Public queries continue to check publication, expiry and seller suspension immediately.
- Keep listing cards paginated and related records eager-loaded. Image processing remains queued, generating four WebP sizes; cards lazy-load and the detail hero has high fetch priority.
- Vite splits page code and emits hashed assets. Set `ASSET_URL` at build and runtime for a static asset CDN. Keep HTML, authenticated requests and authorized listing-image routes out of shared edge caches until withdrawal/privacy behavior is explicitly validated.
- Before launch, repeat with representative listing/photo volume, Redis, private cloud storage, PHP-FPM and the actual CDN. Measure mobile LCP/INP/CLS, search latency, image bandwidth, queue delay and concurrent requests. No production load or Lighthouse score is claimed here.

## Design follow-up — 2026-10-09

Repeated the same warm-once/five-request method after the design review, with four labelled demo listings. These are local development-server HTML timings; they exclude asset downloads and rendering and are not directly comparable to the earlier empty inventory.

| Page        | Median (ms) | HTML bytes | Server-rendered content                           |
| ----------- | ----------- | ---------- | ------------------------------------------------- |
| Home        | 688.0       | 44,425     | Yes                                               |
| Search      | 742.9       | 31,309     | No; the noindex search page renders on the client |
| Demo laptop | 684.0       | 20,743     | Yes                                               |

The homepage's initial JS dependency graph totals approximately 133,349 bytes compressed with gzip level 9; CSS totals 18,361 bytes under the same calculation. This is a build-file estimate, not observed network transfer: enable compression and verify real delivery on production hosting. It excludes HTML and photographs.

Reference-data query counts remain 6 cold/0 warm. A real PNG listing upload of 606,874 bytes produced a 12,932-byte card WebP and 33,342-byte large WebP; the original was removed. Cards/detail now offer responsive width-correct sources, and gallery thumbnails use the smaller thumbnail variant. Homepage editorial photos have 600 px alternatives of 36,262 and 40,738 bytes, preserving larger sources for larger displays.

See the [review report](design-and-performance-review.md) for browser journeys and responsive coverage. Physical-device, slow-network and production volume/CDN measurements remain required before launch; no production Web Vitals or capacity claim is made.
