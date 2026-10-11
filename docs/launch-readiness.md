# Launch readiness — 2026-10-10

This is the current status; dated README follow-ups describe earlier milestones. The application runs locally. It has not been deployed to production.

See the [security review](security-review.md) for the Avast investigation, dependency fixes, new application hardening and the remaining production security boundary. Local checks do not establish complete security or replace a deployed-environment penetration test.

The [input and injection review](input-security-review.md) records SQL/search binding checks, SSRF exposure review, compiled-template escaping tests and fixes for malformed authentication/location input, password limits and pagination.

## Implemented locally

- Phases 1–6: foundation, authentication, categories/locations, listing creation and photo processing, discovery/favorites, private messaging and moderation.
- Listing lifecycle: mark sold, scheduled expiry and renewal through moderation.
- Seller profiles, bounded profile-photo uploads and measured historical reply percentage.
- Phase 7 application work: guest SSR, canonical/meta tags, JSON-LD, sitemap, reference caching and private optimized WebP images. Asset CDN configuration is supported, but actual CDN delivery and production performance are unverified.
- First visual pass: light blue/slate/off-white presentation across home, search, item details, authentication, posting, account dashboard, my listings, settings, favorites, messages and seller profiles. Four labelled local demo listings use licensed real photographs; they are not offers for sale.

## Local user-journey and visual review

- [x] Complete the local seller journey: create with photos/category attributes, preview, submit, approve, publish, edit, mark sold and renew an expired fixture through review.
- [x] Complete a two-account buyer/seller journey: favorites, conversation/reply, block/unblock and report; inspect populated conversations on phone and desktop layouts.
- [x] Review profile-photo upload/replacement/removal, invalid-file errors, keyboard focus and light/dark appearance.
- [x] Review 18 main page types at seven widths from 320 through 1440 px, with additional authentication/error checks and local image/cache measurements.
- [x] Run the full isolated-database suite: 201 tests passed (1,679 assertions), with live SSR and Meilisearch. Frontend checks/builds, PHPStan and Pint also pass.
- [ ] Verify physical iOS/Android devices, additional browsers and slow mobile connections; measure production Web Vitals and representative data volume.
- [ ] Agree on final branding before replacing the current temporary logo.

The [design and performance review](design-and-performance-review.md) records the coverage, measured image sizes and limitations. Disposable QA data has been removed; four labelled demo listings remain. No new JavaScript console warnings/errors were reported on refreshed final marketplace pages.

## Phase 8: production infrastructure

- [x] Implement the selected native Ubuntu 24.04 deployment method, defaulting to `secondhand.webostics.com` with optional domain overrides. See [deployment instructions](deployment.md). The current Compose stack remains for development.
- [ ] Provision the actual VPS/region and run the prepared disposable Ubuntu VM scenarios on amd64/arm64, including a real reboot. Local syntax/mock tests do not validate package/service integration.
- [ ] Configure HTTPS, Cloudflare DNS/CDN and appropriate cache rules. Authenticated HTML and authorized image responses must not enter shared public caches.
- [ ] Configure PostgreSQL, Redis sessions/cache/queues and private VPS image storage (or optional private R2/S3); verify uploads, access checks and cleanup on the deployed stack. Include VPS photos in off-server backups and restore testing.
- [x] Add Horizon with confirmed administrator/2FA authorization tests, configurable SSR ports and native systemd supervision/scheduling templates.
- [ ] Verify supervised processes, Horizon jobs/dashboard and every-minute scheduling on the deployed Ubuntu stack.
- [ ] Configure a transactional email provider and test password reset delivery. Log mail is development-only.
- [x] Add the simplified deploy command, local CLI/configuration checks and a manually dispatched disposable-VM smoke workflow.
- [ ] Run both CI workflows remotely and complete real certificate issuance/renewal, domain changes, safe failure handling and repeat-deployment validation before production.
- [ ] Add actionable application/queue monitoring and error alerts, log retention and health checks.
- [ ] Configure database/object backups and prove a restore into a separate environment.
- [ ] Complete production security checks: debug disabled, secrets outside source control, secure cookies, trusted proxy/host configuration, storage permissions, dependency review and protected admin access.
- [ ] Profile representative production pages, database/search queries and image delivery; verify CDN behavior and capacity before launch.

These items require actual hosting, domain, storage and mail configuration; passing local tests cannot establish production readiness. Native Linux systemd/Nginx/Horizon integration and live cloud storage remain unverified on the current Windows preview setup. The deployment script provides local pre-migration backups; daily off-server backups, restores and real email delivery remain launch requirements.

## Explicitly deferred in the brief

Mobile OTP, email verification and social login are later additions. Do not imply phone verification or display invented trust badges. Existing registration, password reset and optional user two-factor authentication remain available; admin access requires completed two-factor sign-in.
