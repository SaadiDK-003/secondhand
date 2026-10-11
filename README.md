# Second Hand

**Good Things Deserve a Second Home.**

A Pakistan-focused marketplace for pre-owned physical goods. This repository implements **Phases 1–6, the application portion of Phase 7 and native deployment automation for Phase 8** of `second-hand-codex-project-brief.md`. Actual production deployment and live performance verification remain outstanding.

## Included

- Laravel 13 on PHP 8.4, Vue 3, TypeScript, Inertia 3, Tailwind CSS 4 and the official starter kit’s accessible shadcn-style Vue components.
- PostgreSQL for application data and automated feature tests. No SQLite setup step.
- Redis defaults for encrypted sessions, cache, rate limiting and queued jobs. Redis jobs dispatch after database commits; workers retry three times.
- Registration with name, email, Pakistani mobile number and password; email-or-mobile login, logout, password reset, profile/password management and opt-in TOTP two-factor authentication.
- Mobile normalization to `+923XXXXXXXXX`, server-side validation, unique constraints, password hashing, login/registration rate limits and private mobile serialization.
- A responsive Second Hand landing page and authenticated account page.
- Docker development services, dependency lockfiles, PHP/frontend formatting, TypeScript/PHP static checks and authentication tests.

Phase 7 includes SSR, metadata, sitemap, schema, reference caching and image optimization. Live CDN delivery, production performance profiling and infrastructure validation remain open. See the current [launch checklist](docs/launch-readiness.md). Admin access requires a completed two-factor sign-in. Email verification, OTP and social login are deferred as specified in the brief. Mobile numbers are unverified; password resets use email. Mobile changes are deferred until a verification flow exists.

## Native Ubuntu deployment

The provider-independent [deploy.sh](deploy.sh) targets Ubuntu 24.04 LTS on amd64/arm64, using Nginx, PHP 8.4-FPM, Node 24 and systemd. It defaults to `secondhand.webostics.com`, remembers later domain changes and checks private-port conflicts. It deploys in place at `/var/www/html/secondhand` and handles missing dependencies, protected settings, database backups, HTTPS, Horizon, SSR and scheduling. Follow [docs/deployment.md](docs/deployment.md) for first-time private storage/SMTP settings and the required disposable-VM validation.

```bash
sudo bash deploy.sh --env-file /root/secondhand-production.env
sudo bash deploy.sh
sudo bash deploy.sh --domain secondhand.om.pk
sudo bash deploy.sh --check
```

The native installer has not yet run on an Ubuntu VM or a production VPS. Docker remains a development option below.

## Start with Docker (recommended)

Install Docker Desktop with Linux containers and Docker Compose v2. Run from this directory. The commands work in PowerShell; use `cp` instead of `Copy-Item` in a POSIX shell.

```powershell
Copy-Item .env.example .env
docker compose build
docker compose up -d postgres redis meilisearch
docker compose run --rm app composer install
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan migrate --seed
docker compose run --rm app php artisan scout:sync-index-settings
docker compose run --rm app php artisan scout:import "App\Models\Listing"
docker compose run --rm app npm ci
docker compose run --rm app npm run build
docker compose up -d app vite queue
```

Open http://localhost:8000. Vite uses localhost:5173. Published ports bind only to localhost. Docker uses named dependency volumes to keep Linux packages separate from Windows host packages.

PostgreSQL creates `second_hand` and `second_hand_test` on its first initialization. The example database password is for local development only. If using an existing volume without the test database:

```powershell
docker compose exec postgres createdb -U second_hand second_hand_test
```

Stop with `docker compose down`; persistent volumes are retained. Do not use `down -v` unless you intend to discard local data. After changing dependencies, rerun the relevant install command inside the app container. Database credentials in `.env` must match the initialized PostgreSQL volume.

## Native development

Requirements: PHP 8.4 with standard Laravel extensions plus `pdo_pgsql`, `mbstring`, `intl`, `zip`, `gd` (JPEG/PNG/WebP), `exif` and `redis`; Composer 2; Node 24; PostgreSQL 17; Redis 7.4; Meilisearch 1.54.3. Queue workers in Linux/Docker also use `pcntl`. The system PHP 8.2 bundled with XAMPP cannot run this application.

Horizon requires `pcntl` and `posix` on Linux. These extensions are unavailable in Windows PHP: Windows development may install with `composer install --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix` and use the ordinary development worker. Horizon itself must run on Linux. Production installation never ignores platform requirements.

Create PostgreSQL databases `second_hand` and `second_hand_test`, owned by the role configured in `.env`. Start PostgreSQL, Redis and Meilisearch before the application. Set `MEILISEARCH_HOST` and `MEILISEARCH_KEY` to match your local search service.

```powershell
Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan scout:sync-index-settings
php artisan scout:import "App\Models\Listing"
npm ci
npm run build
composer dev
```

`composer dev` starts the PHP development server, Redis worker and Vite together. Mail uses Laravel’s log driver locally; reset links appear in `storage/logs/laravel.log`. Never expose development logs publicly. `.tools/` contains ignored, project-local validation utilities on the original development machine; it is not a required application dependency or a replacement for the documented setup.

PHP needs a writable upload temporary directory. The original native preview uses `.tools/uploads` through its ignored PHP configuration; configure an appropriate `upload_tmp_dir` for your environment and restart PHP after changing it.

### Local demo accounts

The login page offers demo credentials only when `APP_ENV=local` and the matching accounts are available. Buyer: `demo-buyer@example.invalid`; seller: `demo-seller@example.invalid`. Both use `LocalDemo!2026`. The buttons fill the login form; authentication, permissions and rate limits still apply. These are ordinary marketplace accounts with no admin access.

For the original `second_hand_preview` database, run `php artisan db:seed --class=DemoAccountSeeder` to create the accounts or restore their demo passwords. `DemoListingSeeder` also calls this seeder. Both seeders refuse non-local environments and other databases, and neither runs with the default production seeds. Demo credentials are omitted from login responses outside the local environment. Demo accounts cannot sign in or reuse sessions outside `local`, even if the preview data is copied.

## Checks

With Docker services running and dependencies installed:

```powershell
docker compose exec app composer test
docker compose exec app npm run check
docker compose exec app npm run types:check
docker compose exec app npm run build
```

Native equivalents are `composer test`, `npm run check`, `npm run types:check` and `npm run build`. `composer test` runs Pint, PHPStan/Larastan and PHPUnit. Use `composer lint` and `npm run check:fix` to format changes.

`phpunit.xml` selects PostgreSQL database `second_hand_test`, with in-memory cache/session and synchronous queues to isolate application tests. It inherits database host/user/password from `.env` or the process environment. Never point tests at a database containing real data: feature tests reset its schema. Tests cover registration, login/logout, password resets, profile ownership, 2FA, mobile normalization/uniqueness/privacy and rate limiting. Redis integration and the complete Docker stack require a separate runtime smoke check; in-memory test stores do not prove Redis connectivity.

The GitHub Actions workflow provides PostgreSQL, Redis and Meilisearch, builds assets, then runs these checks. It does not deploy anything.

CI also runs Composer/npm dependency audits. Run `composer audit --locked` and `npm audit --audit-level=high` locally after dependency changes. The [security review](docs/security-review.md) records application hardening and the production requirements that remain open.

## Structure and conventions

This is a standard Laravel modular monolith. Controllers stay in `app/Http/Controllers`; request validation in `app/Http/Requests`; reusable auth operations in `app/Actions/Fortify`; mobile normalization in `app/Support`. Fortify registration keeps the framework’s `CreatesNewUsers` action and server-side validator convention. Settings endpoints use Form Requests and derive the current user from the authenticated session, never client-supplied ownership IDs.

Enums, listing policy and marketplace models now support the core domain. Listing actions, image jobs and the image processing service implement Phase 3. The `DTOs`, `Events`, `Listeners` and `Notifications` folders remain reserved for later functionality; no extra abstraction layer is introduced. Vue pages live in `resources/js/pages`, reusable components in `resources/js/components`. Wayfinder generates typed routes during the build; generated route files are ignored. All schema changes use migrations. Default seeds create no credentials; local preview accounts require the explicit demo seeder above.

## Dependency choices

The foundation comes from the [official Laravel Vue starter kit](https://github.com/laravel/vue-starter-kit). Fortify supplies Laravel’s authentication and 2FA flows; Inertia connects Laravel and Vue without a separate API; Wayfinder supplies typed routes. Tailwind and Reka UI/shadcn-style components supply styling and accessible controls; Lucide supplies icons. Vite Plus bundles the frontend and runs its formatter/linter; Vue TSC checks TypeScript. Pint formats PHP, Larastan/PHPStan checks types, and PHPUnit/Mockery/Faker support tests. Concurrently starts local development processes together.

Starter installation tooling and optional passkey UI have been removed. Fortify currently brings its passkeys PHP dependency transitively, but passkey routes/features are disabled. Phase 3 adds the standard Flysystem S3 adapter for private R2/S3 storage. Native GD handles image processing without another image library. Phase 4 adds Laravel Scout and the official Meilisearch PHP SDK for queued indexing and search. Payment and monitoring packages remain deferred. Horizon remains in Phase 8 as specified by the phase plan; Redis uses Laravel’s standard queue worker for now.

## Environment and production boundary

`.env.example` contains only development values and configuration for Meilisearch and R2/S3, plus a future Sentry placeholder. Never commit `.env`, keys, credentials, private files, or database backups. Keep the root brief as the scope reference.

The Dockerfile and `artisan serve` setup are **development only**. Production setup in Phase 8 must include Nginx/PHP-FPM, HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, trusted proxy configuration, real secret management, managed workers/Horizon, backups and the remaining security controls in the brief. Production images can use private VPS storage outside public/ or an optional private S3/R2 disk. Validate the selected storage on the deployed stack.

## Phase 1 validation record

Validated on 2026-10-05 with a project-local PHP 8.4 runtime and PostgreSQL 17:

- Production frontend build, TypeScript, frontend lint/format checks, Pint and PHPStan passed.
- Authentication and account feature tests passed against PostgreSQL.
- Landing and registration pages rendered in the browser; registration includes the mobile field.
- Docker is not installed on this machine. Container startup and real Redis cache/session/queue connectivity remain unverified locally. The browser smoke check used temporary file sessions and an in-memory cache; the committed application defaults remain Redis.

No production deployment, remote Git repository or marketplace features were created during Phase 1.

## Phase 2 — Marketplace core

Phase 2 established the data layer used by the Phase 3 posting screens. No new dependencies were needed in Phase 2.

- Categories and one level of subcategories, matching the brief's minimum nesting requirement. Model validation rejects self-parenting, deeper nesting and moving a parent with children under another category.
- Dynamic category attributes with text, number, boolean, select and multi-select types, required/filterable flags and selectable options. Definitions belong to the exact selected category; they are not implicitly inherited.
- Province/territory → city → area relationships. Initial seeds include 7 province/territory records, selected major cities, and the four Karachi areas from the brief. Expand this starter coverage before launch.
- Generic listings with owner, category, price/currency, negotiability, used-item condition, location, optional coordinates, lifecycle timestamps and all eight status values from the brief. Prices use decimal storage and PKR; category-specific fields never enter the listings table.
- Stable readable slugs, ownership policies, foreign keys, unique constraints and indexes for expected access patterns. Composite foreign keys reject inconsistent city/province, area/city and attribute/category combinations.
- \App\Actions\SyncListingAttributes replaces values in one transaction after checking required fields, types and allowed options. Callers pass an existing listing and values keyed by attribute slug. Select values use the option's stable value (for example `apple`), and multi-select uses a list of those values. False and zero are preserved. HTTP entry points authorize the listing and validate its base fields through Form Requests.

Example attribute payload for the seeded mobile-phones category:

```php
[
    'brand' => 'apple',
    'model' => 'iPhone 14',
    'storage' => '128-gb',
    'ram' => 6,
    'pta-approved' => false,
    'box-available' => true,
]
```

Draft is the default status. Owner/status/publication fields are excluded from mass assignment. Only published listings with a reached publication date and no elapsed expiry are public; owners may view their own private listings. The policy permits owner edits to draft/rejected/published listings and prevents owner deletion of blocked listings. Phase 3 adds submission to pending review. Moderation transitions, renewal and expiry jobs remain later work.

Seeders add 15 top-level categories and relevant subcategories, example attributes for phones, refrigerators, sofas, wedding clothes and laptops, and initial locations. They are safe to rerun: existing names and customizations are preserved, and no demo users or listings are created. Run `php artisan migrate --seed` (or the Docker equivalent) after pulling this phase. Account deletion cascades through its listings and values; referenced taxonomy/location records cannot be deleted while in use.

Phase 2 validation: all 60 tests passed against PostgreSQL (228 assertions), including attribute type/option validation, atomic failure, private/public visibility, ownership, hierarchy constraints and repeatable seeds. PHPStan passed. The Phase 1 Docker/Redis runtime validation limitation remains open.

## Phase 3 — Listing management

- Create, edit and delete your own ads at `/sell` and `/my-listings`. Choose a category/subcategory, fill its dynamic attributes, enter item details and select province/city/optional area. Ownership, allowed states and location relationships are checked server-side.
- Save a private draft, preview it, then submit for moderation. Submission requires 1–12 successfully processed photos. Submitted ads stay pending until the Phase 6 moderation tools exist. Editing a published ad returns it to draft and removes public access until reviewed again.
- Private owner previews use `/listings/{id}/preview`; public published ads use `/ad/{slug}`. The responsive detail page includes a photo gallery, price, condition, category details, location and seller name/joined date. Seller phone/email are not exposed. Favorites are available in Phase 4; messaging and reporting are implemented in Phases 5–6.
- JPEG, PNG and WebP uploads are checked using actual MIME, extension, size (10 MB each) and dimensions (8,000 pixels per side, 20 megapixels total). The server enforces a total of 1–12 retained/new photos.
- Queued jobs preserve orientation/aspect ratio, strip metadata and create WebP thumbnail, card, medium and large variants within 300×225, 600×450, 1000×750 and 1600×1200 bounds. Small images are not enlarged. Originals are private and removed after successful processing; public routes never serve them.
- Removing photos, deleting ads and deleting accounts enqueue private file cleanup. Processing and cleanup share a per-image queue lock. Failed images can be removed and uploaded again. Worker failures remain in Laravel's failed-jobs store for investigation/retry.

Run migrations and keep the queue worker running. The default Redis setup uses `php artisan queue:work --tries=3 --timeout=60` (or Docker's queue service). Without a worker, photos remain processing and submission is disabled. PHP must allow a request of up to 128 MB; the development Docker configuration includes this limit. Production Nginx/PHP upload limits must agree.

Local images live under `storage/app/listing-images`, outside the public directory. For R2/S3, set `LISTING_IMAGES_DISK=s3` and configure `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`, `AWS_BUCKET` and `AWS_ENDPOINT` (R2 uses its account endpoint and region `auto`). Keep the bucket private. Image responses pass through an authorized application route and use private/no-store caching; CDN delivery is a later production optimization. Production accepts the private listing_images VPS disk; public disks remain prohibited.

Phase 3 validation: 78 PostgreSQL tests passed (350 assertions), including listing ownership/state transitions, upload rejection, atomic saves, image processing and private/public access. PHPStan, Pint, TypeScript, frontend lint and the production build passed. Live R2/S3 access and Docker/Redis integration remain unverified on this machine. The native preview uses a separate PostgreSQL database, file sessions/cache and a database queue worker; committed defaults remain Redis.

Local preview follow-up (2026-10-06): the user restored the Avast-quarantined Laravel bootstrap and PHPStan configuration. Their hashes match the preserved copies. All 78 tests (350 assertions), Pint and PHPStan passed again. Browser checks verified sign-in, category attributes, location selection, uploading a photo, background processing, draft preview, price editing with retained photos and submission to pending review. Mobile and desktop layouts were checked; mobile price/seller information now follows the gallery. Frontend formatting/lint, TypeScript and the production build passed. The disposable test account and listing were removed after verification. Phase 3 is complete; Phase 4 is Discovery (Scout/Meilisearch, search, filters, sorting and favorites).

## Phase 4 — Discovery

- `/search` uses Scout and Meilisearch for keyword search with typo tolerance, category/subcategory, province/city/area, price, condition and dynamic category filters. Numeric/text attributes match exact values; multi-select matches any selected option.
- Sort by relevance, newest, price low to high or price high to low. Shareable filter URLs and pagination return 20 items per page, up to Meilisearch’s configured 1,000-result window. Nearest sorting remains deferred with coordinate/radius search, as described in the brief’s future location support.
- Responsive image cards, desktop sidebar filters and a mobile filter toggle. The homepage links categories and shows the latest eight public ads. Search outages show a retry message and link to recent ads.
- Authenticated users can save/remove ads and visit `/favorites`. Favorites are unique per user/listing; withdrawn ads are hidden. Private seller contact details never enter public cards or search documents.
- Index writes run through Scout’s queue after database commits. Publication/expiry filters and a database visibility check prevent stale search documents from exposing withdrawn/private ads. Result totals can temporarily lag while queued updates finish.

After installing dependencies and migrating, start Meilisearch, run `php artisan scout:sync-index-settings`, then `php artisan scout:import "App\Models\Listing"`, and keep the queue worker running. Rerun index settings after configuration changes; reimport after changing document fields or restoring data. The Docker service binds port 7700 to localhost and uses a development-only key; native installations must configure a matching server key. Keep the master key server-side. Production needs separate secrets and private service networking.

Most tests disable Scout. To include the three real-engine integration tests, start Meilisearch and set `MEILISEARCH_TESTS=1`, `MEILISEARCH_HOST` and `MEILISEARCH_KEY` before `php artisan test`. These tests create and delete isolated indexes with a unique `test_` prefix. They never flush the application index. CI enables them automatically.

Phase 4 validation (2026-10-06): 88 PostgreSQL tests passed (461 assertions), including real Meilisearch typo matching, filters, sorting, pagination, stale-index visibility protection, favorites ownership and outage behavior. The native preview uses PostgreSQL, a database queue and Meilisearch; Docker/Redis and cloud storage validation remain outstanding. Messaging and user blocking are Phase 5.

Browser validation: keyword search, ascending prices, combined sofa material/seats filters, saved ads and mobile filter expansion passed. No mobile horizontal overflow was observed. Pint, PHPStan, frontend formatting/lint, TypeScript and the production build passed. The disposable account and three ads were removed after verification; a screenshot of the temporary test results is retained locally under .tools/phase4-discovery.jpg.

## Phase 5 — Messaging

- Authenticated buyers can message a seller from a public ad using free text or a quick-message prompt. One conversation per buyer/ad is enforced by a unique database constraint. Starting requires a current public ad and excludes self-messaging.
- The inbox at /messages lists only the current user’s conversations, newest activity first, with 20 conversations per page. Chat shows 30 messages per page, latest page first, displayed chronologically within each page. Visible chats refresh every 2 seconds and the inbox every 3 seconds using Inertia polling; unsent drafts are preserved. Both pause in hidden tabs and refresh when returning.
- Buyers and sellers have incoming unread-message counts in the header and on each conversation. An authenticated, private `/messages/status` JSON check every 3 seconds updates the header and shows an in-app alert with an Open chat action while browsing elsewhere. It returns counts and sender display names, never message bodies or contact details. This works while the site is open; operating-system push notifications and WebSockets remain deferred.
- Opening a chat marks only the incoming messages displayed on that page as read. Sending your own message cannot clear the recipient's count, and older pages remain unread until opened. This internal tracking does not expose read receipts to the sender. Run `php artisan migrate` to add the indexed read-tracking column.
- Messages are plain text, limited to 2,000 characters and escaped by Vue. Sender and recipient IDs are derived server-side. Conversation policies prevent outsiders reading or posting; phone numbers and email addresses are excluded from chat props. Separate rate limits cover starting, sending, reading and blocking.
- Block/unblock from chat, or manage blocks at /blocked-users. Either participant’s block stops messaging in both directions across all ads. Only the blocker can remove their block. Shared user-row locks serialize block/send operations. Blocking preserves history and does not hide public listings.
- Withdrawal/deletion of an ad preserves its existing conversations so users can finish arrangements, but removes the ad link when it is no longer public. Only its original public title is retained; subsequent private edits are not exposed. Deleting either account cascades its conversations/messages/blocks.
- Two fixed buyer/seller foreign keys represent participants; there is no separate participant table or group-chat abstraction. No new packages, WebSockets, typing indicators, read receipts, attachments or phone-reveal flow were added. These remain outside this phase.

Run php artisan migrate after updating. No new service is needed. Phase 5 validation: 99 tests passed (621 assertions), including 11 messaging tests and the real Meilisearch suite.

## Design direction agreed with the owner

Continue the planned functional phases, then complete a deliberate visual polish before launch. The current UI is provisional. Target a distinctive marketplace that is clearer and easier to use than OLX: light-first presentation, strong typography, thoughtful spacing, polished navigation and product cards, and excellent mobile browsing/posting/contact flows. Use real product photography for clearly identified demo listings, never placeholder sofa graphics as finished merchandising. Review the homepage, discovery and listing details together for consistency; do not copy OLX branding or claim superiority without usability validation.

Phase 5 browser checks verified starting a chat from an ad, receiving a reply through polling, blocking/unblocking, sending again and a mobile layout without horizontal overflow. PHPStan, Pint, frontend lint/format, TypeScript and the production build passed. Disposable test accounts, their ad and conversation were removed after verification. Docker/Redis and cloud storage checks remain outstanding as previously documented.

Messaging follow-up (2026-10-09): verified incoming alerts while browsing, global and per-thread counts, clearing counts on opening a chat, automatic replies with an unsent draft preserved, and the authenticated header/alert at 320 px without page overflow. Four tagged test replies were removed afterward, preserving the existing demo conversation. All 158 tests passed across the main suite and five live SSR/search checks (1,183 assertions total); PHPStan, Pint, frontend checks, TypeScript and client/SSR builds passed.

## Phase 6 — Moderation

Filament 5.9 provides the admin panel at /admin, with Livewire 4 as its required dependency. It coexists with the Vue/Inertia marketplace; admin navigation performs a full-page handoff. See the [official installation documentation](https://filamentphp.com/docs/5.x/introduction/installation). Composer installs the panel assets through filament:upgrade. After updating dependencies, run php artisan migrate and restart long-running queue workers.

- Listing review: filter pending/published/rejected/blocked/draft ads, open an authorized private preview, approve, reject or block with a required reason. Approval requires a pending/blocked ad, an active seller and 1–12 ready photos. It sets publication time and a 30-day expiry. Rejection notes appear in the owner's preview; resubmission clears the previous note and reruns checks. Invalid/stale state transitions are rejected.
- Reports: authenticated users can report a public ad or a chat participant for fraud, prohibited content, misleading information, harassment or another concern. Reports are rate-limited and unique per reporter/target; repeat submissions do not overwrite evidence. Admins resolve or dismiss with a reason. Reports preserve target IDs if the target is deleted; reporter IDs become null on account deletion.
- Suspension: admins can suspend/restore ordinary accounts with a reason. Suspended accounts cannot sign in or continue authenticated requests. Their listings and images are hidden publicly using database checks, including stale search results, and messages to/from suspended accounts are refused. Restoration permits the account and still-current ads again. Admin accounts cannot be suspended through this panel.
- Keyword rules: admins add or deactivate literal, case-insensitive phrases. Submission records matched phrases, external URL warnings and potential same-seller duplicate titles. These are human-review flags, not automated bans or an advanced fraud classifier. Existing submissions are not automatically rescanned when rules change.
- Activity log: admin listing decisions, suspension/restoration, report resolutions, keyword changes and CLI privilege grants record the actor, target and reason/details. Reports and web moderation actions record request IPs for abuse investigation. No passwords, factors or full browsing history are recorded. Establish retention and trusted-proxy configuration before production.

### Admin setup

No administrator is seeded. Register an ordinary account, enable and confirm two-factor authentication under Settings → Security, then an operator with trusted server-shell access can run:

```powershell
php artisan admin:grant you@example.com
```

Sign out and sign back in, completing the authenticator/recovery-code challenge, then visit /admin. Enrollment alone, a remembered login or an old password-only session cannot enter the panel. Removing/changing the factor invalidates panel access. The access middleware also persists on Livewire requests, and mutation services independently authorize the admin session. Recovery uses the existing Fortify recovery codes; no second MFA store or alternate admin login is added. The CLI grant is audited and does not create credentials.

The panel intentionally exposes only the moderation functions in this phase. Read receipts, phone reveal, payments and advanced fraud scoring are still deferred. Before calling the whole MVP production-ready, also finish remaining lifecycle/profile items from the brief (sold/renewal actions, automatic expiry state transitions and fuller seller profiles), Phase 7 SEO/performance, the agreed visual polish and Phase 8 deployment/security work. Timestamp expiry already removes elapsed ads from public discovery.

Phase 6 validation (2026-10-06): 113 tests passed (692 assertions), including Fortify recovery-factor sign-in, admin access restrictions, Inertia/Filament handoff, a Livewire approval action, reporting, moderation state checks, suspension and keyword flags. PHPStan, Pint, frontend lint/format, TypeScript and the production build passed. Browser verification covered two-factor login, the review queue, rejection with a reason and its audit log. Temporary moderator/seller accounts and test records were removed. Docker/Redis and real cloud storage validation remain outstanding.

## Phase 7 — SEO and performance

Guest homepage, public ads and city/category browse pages now render through Inertia SSR. Authenticated and private pages remain client-rendered. Public ads include canonical URLs, descriptions, OpenGraph/Twitter metadata, Product/Offer JSON-LD and breadcrumbs. Canonicals use `APP_URL`; configure the final HTTPS domain before launch. HTML metadata remains available if SSR is temporarily unavailable.

Browse routes are `/browse/{city}`, `/browse/{city}/{category}` and `/category/{category}`. Empty browse pages, private screens and arbitrary search/filter combinations are noindex. Paginated browse pages have their own canonical URL. `/sitemap.xml` points to page and listing sitemaps, with listing chunks of 1,000; only current public listings from active sellers qualify. `/robots.txt` points to the sitemap. These controls do not replace authorization.

Build both frontend and SSR bundles, then run the SSR service alongside PHP and the queue worker:

```powershell
npm run build:ssr
php artisan inertia:start-ssr
```

Use `php artisan inertia:check-ssr` for health checks. After rebuilding, stop/restart the running SSR process so it loads the new bundle. Native builds bind SSR to loopback. Docker builds must run inside the app container (`docker compose run --rm app npm run build:ssr`) so `INERTIA_SSR_HOST=0.0.0.0` is embedded for private container networking; the `ssr` service has no published host port. Vite development uses its own SSR rendering endpoint. Production needs supervised SSR/queue processes, covered in Phase 8.

Common category/location filter data is cached for five minutes with invalidation on model writes. Redis remains the committed default; the native preview uses file cache. Existing queued WebP variants and lazy cards are retained, with priority loading for the listing hero. The [local performance baseline](docs/performance-baseline.md) records measurements and their limits.

Static asset CDN support uses Laravel/Vite's `ASSET_URL`: set it before building and at runtime, and serve the same hashed `public/build` assets from that origin. Cloudflare DNS/CDN activation and real R2/S3 delivery require production configuration in Phase 8 and have not been verified. Keep application HTML and authorized listing images out of shared CDN caches; the private image bucket/access checks remain in force.

Phase 7 validation: 122 tests passed (783 assertions), including live Node SSR, parsed rendered JSON-LD, sitemap visibility and cache invalidation, plus the real Meilisearch suite. Set `SSR_TESTS=1` with a built/running SSR service to include the SSR test; CI now builds and starts it. PHPStan, Pint, frontend lint/format, TypeScript and client/SSR builds passed. The Inertia build emits an upstream sourcemap warning. Browser checks confirmed category navigation updates canonicals and removes stale schema without console warnings/errors.

Phase 7 application work is implemented; live CDN and production performance validation remain open. Before launch, complete the remaining sold/renewal/expiry/profile functionality, agreed light-first visual polish with real product photography, and Phase 8 deployment/security work. No production deployment has been performed.

## Listing lifecycle follow-up — 2026-10-07

Owners can mark published/expired items as sold from their private listing preview. Sold ads immediately disappear from public pages, favorites and sitemaps; existing conversations remain available. Only eligible owners may perform the action, and sold ads cannot be renewed.

Expired ads can be renewed from the same preview, including when the timestamp has elapsed but the expiry worker has not run yet. Renewal preserves the ad ID, slug and photos, returns it to draft and opens its edit form. The seller must resubmit and pass moderation before publication. Blocked, pending, rejected and still-current ads cannot use renewal to bypass review.

`ExpireListings` runs through the queue every five minutes, using bounded batches and row locks, and removes expired documents through Scout model events. Timestamp checks still hide elapsed ads immediately if workers are delayed. Run both `php artisan schedule:work` and the queue worker for the native preview; Docker now includes a scheduler service. Production should run Laravel's scheduler every minute with its supervised queue workers. `LISTING_EXPIRY_DAYS` controls newly approved ads (default 30), without rewriting existing expiry dates.

PostgreSQL connections explicitly use UTC, matching Laravel. The native cluster previously defaulted to Asia/Karachi; existing timestamps written before this fix may represent an instant five hours earlier than intended. No historical records were silently shifted. Audit any retained development data before reuse; the preview currently has no published inventory. Restart long-running workers when applying the connection change.

Validation: 127 tests passed (826 assertions), including ownership/state restrictions, immediate withdrawal, renewal through moderation and exact expiry boundaries. PHPStan, Pint, frontend lint, TypeScript and client/SSR builds passed. Fuller seller profiles, visual polish and production deployment remain next; live Docker/Redis/cloud storage checks are still outstanding.

## Public seller profiles — 2026-10-08

Public ads link the seller's name to `/sellers/{id}`. Profiles show name, join month, actual email verification status, available-item count, seller-reported sold count, and cities derived only from current public ads. Items use the existing cards, favorites and 20-item pagination. Buyers open an ad to start a conversation or use the existing report flow from the profile. Phone numbers, email addresses, private ads, sold-ad details and private account fields are not included in seller props.

Suspended sellers return 404. Accounts with no current or sold inventory are visible only to their owner; sold-only and empty owner profiles are noindex. Guest profiles support SSR and canonical pagination. Updating the existing account name updates the profile; owners have a link to account settings.

This is the public profile foundation. Profile photo uploads and measured response information remain unfinished; phone verification is not implemented and no verified-phone badge is claimed. These gaps, the agreed visual polish, and production infrastructure remain on the pre-launch list.

Validation: 132 tests passed (912 assertions), including the live seller-profile SSR check, private-field exclusion, visibility rules, pagination and viewer-specific favorites. PHPStan, frontend formatting/lint, TypeScript and client/SSR builds passed. Live visual review of the new profile page remains part of the design pass; no production deployment was made.

Seller response information now reports the percentage of recent conversations with a seller reply. It considers up to 100 conversations started 1–90 days ago, requires at least five eligible conversations, and caches the aggregate for five minutes. Multiple messages in one conversation count once. The public page receives only sample size and percentage, not messages or participant details. It is a historical reply rate, not a response-time promise or trust score. Profile photo uploads remain unfinished.

The owner's palette options were reviewed. [Design recommendation](docs/design-direction.md): Option 2 blue/slate with Option 1's off-white canvas, white product surfaces and restrained amber highlights. The original palette file is unchanged. Applying the palette is part of the full visual pass together with photography, layout, typography and mobile usability; the recommendation is not presented as a completed redesign.

Reply-rate follow-up validation: 134 tests passed (917 assertions). PHPStan, Pint, frontend lint/format, TypeScript and client/SSR builds passed. Coverage includes sample thresholds, conversation rather than message counting, time-window exclusions and cache expiry.

## Profile photos — 2026-10-08

Settings → Profile now supports uploading, replacing and removing a public seller photo. JPEG, PNG and WebP are checked for actual image content, matching supported extension, a 2 MB limit and dimensions from 64×64 through 2000×2000. JPEG orientation is honored. The image is center-cropped and re-encoded as WebP at up to 256×256 without upscaling or retaining original metadata. Only the resulting avatar is stored; the uploaded original remains a temporary request file. This single bounded conversion runs during upload; large listing-image processing remains queued.

Photos use the existing private image disk configuration, supporting private VPS storage and optional private S3/R2 in production. Storage paths stay hidden from account props. A guarded route serves only the current photo, with private/no-store headers, matching public seller eligibility or owner access. Suspended users' photos are hidden. Replacement, removal and account deletion queue old-file cleanup; keep the queue worker running. The account row is locked during replacement/deletion to serialize concurrent changes.

Run `php artisan migrate` before using the new profile controls. The native preview migration has been applied. Profile-photo settings and seller avatars are functional additions; the full visual redesign remains pending, using the recorded blue/slate/off-white recommendation. Production deployment and live cloud-storage verification remain outstanding.

Validation: 139 tests passed (958 assertions), including avatar encoding, file rejection, ownership, photo visibility, replacement/removal and account cleanup. PHPStan, Pint, frontend lint/format, TypeScript and client/SSR builds passed. Browser interaction/visual review of the photo controls remains for the design pass.

## First visual design pass — 2026-10-08

The homepage and shared marketplace navigation now use the recommended blue/slate palette, off-white background, white surfaces and restrained amber accents. New visitors default to light appearance; an explicitly saved appearance preference is respected. The homepage includes real licensed category photography, category navigation, city links and the actual recent-inventory feed. Photograph sources are recorded in [photo credits](docs/photo-credits.md); editorial photos are not presented as seller inventory. No sample ads or fabricated marketplace activity were added.

The shared header includes working search and prominent selling access. Cards and shared primary actions use the updated palette. Search and listing-detail pages still need their fuller visual pass; this is not the completion of all design or Phase 8 deployment work.

Validation: 139 tests passed (958 assertions), frontend lint/format, TypeScript and client/SSR builds passed. Browser checks covered header search, mobile filter expansion, loaded photographs and homepage layouts at 390 and 1280 pixels; no browser console warnings/errors were reported on the refreshed homepage. The Inertia build retains its upstream sourcemap warning. The ignored native-preview environment now consistently selects the separate preview database on port 55432, file cache/session and database queues; production example defaults remain unchanged.

## Search and item-detail design follow-up — 2026-10-08

Search now has a clearer query field, white filter/result panels, a mobile filter toggle and a designed empty state. Item details use consistent photo dimensions, a photo counter, clearer price/category/location information, white seller/detail panels and practical meeting/payment guidance. Existing search, favorites, messaging and moderation permissions are retained.

Browser review uncovered a warm-cache bug: cached Eloquent collections could not be restored under the restricted file-cache serialization policy, causing category filters to disappear. MarketplaceOptions now caches plain arrays under a new versioned key, preserving the restrictive policy. A file-cache round-trip regression test covers this case.

Validation: frontend lint/format, TypeScript and client/SSR builds passed; 26 targeted discovery, listing and SEO tests passed (202 assertions), including live SSR and the new cache regression. Mobile filter expansion, category submission, warm-cache reload and desktop search layout were checked in the browser. Published inventory is currently empty, so a populated listing-detail browser review remains outstanding; no fake public inventory was added. Phase 8 production deployment remains outstanding.

## Local demo inventory and branding — 2026-10-08

The preview now contains four explicitly labelled demo ads: sofa, camera/headphones bundle, laptop and backpack. They use real licensed stock photography, accurate descriptions of what is visible, and illustrative prices/conditions. Titles, descriptions and the seller name identify them as demos; the products are not actual offers for sale. Sources are in [photo credits](docs/photo-credits.md).

Run `php artisan db:seed --class=DemoListingSeeder` to add or refresh this inventory. The seeder requires the local environment and the `second_hand_preview` database, is excluded from the default DatabaseSeeder, and reuses its own records and ready images on repeat runs. Photos pass through the existing private WebP variant processor. The buyer and seller demo accounts use reserved example email addresses, the local demo password documented above and no verification badge.

Authentication pages now show a temporary Second Hand wordmark with a circular second-chance/check symbol, also used in the marketplace header. Native select controls retain keyboard behavior with a custom chevron inset 14 pixels from the edge and 44 pixels of text clearance.

Validation: 22 authentication tests passed (82 assertions); PHPStan and Pint passed, as did frontend lint/format, TypeScript and client/SSR builds. Browser checks confirmed all four card photos load, the populated laptop page and demo seller profile work, registration fits a 390-pixel viewport, all three authentication pages display the logo, and search dropdown arrows have the intended spacing. No browser console warnings/errors were reported during these checks. The preview seeder was rerun successfully without creating duplicate ads. Phase 8 production infrastructure and deployment remain outstanding.

## Account-page visual follow-up — 2026-10-09

Posting, my listings, dashboard, account settings, favorites, conversations and seller profiles now share the marketplace layout and light surface styling. The header's Account menu provides settings/logout access. Posting groups item details, photos and location with clearer save/preview guidance; saved-items and inbox empty states provide useful browsing actions. Conversation timestamps explicitly use Asia/Karachi. Existing validation, ownership and messaging permissions are unchanged.

Validation: frontend lint/format, TypeScript and client/SSR builds passed. Targeted listing, messaging, discovery, profile and seller checks passed (46 tests, 485 assertions; one opt-in SSR check skipped). A further seller-profile/dashboard run with live SSR enabled passed (7 tests, 89 assertions, overlapping the earlier seller checks). Browser review covered account navigation/logout, category/location dependencies, settings, empty favorites/inbox, dashboard and mobile posting at 390 pixels without document overflow. No browser console warnings/errors were reported. The temporary browser-review account was removed after logout. The existing upstream Inertia sourcemap warning remains.

The [launch checklist](docs/launch-readiness.md) distinguishes the remaining full browser journeys, live Phase 7 CDN/performance validation and Phase 8 production work. This visual pass does not constitute production readiness.

## Complete local design and responsive review — 2026-10-09

The follow-up review improved phone/tablet navigation, homepage density, category readability, form text/touch targets, chat wrapping, photo selection, pagination, authentication accessibility and branded error pages. Listing cards/detail photos now choose responsive WebP sizes, and homepage editorial photos have smaller WebP alternatives. Upload conversion is verified for JPEG, PNG and WebP, including transparency and small/portrait sources.

The [review report](docs/design-and-performance-review.md) records 126 main-page layout checks across seven widths plus 20 authentication/error checks, completed seller/buyer/moderation/profile journeys, actual image sizes and test evidence. The full suite passes 145 tests (986 assertions), with live SSR and Meilisearch. Temporary QA accounts, uploads and related records were removed. Production infrastructure, physical-device/browser coverage and production performance verification remain on the launch checklist.

## Security review after antivirus alert — 2026-10-09

The [security review](docs/security-review.md) records inspection of the unchanged flagged messaging test, verification of the PHP archive against its official checksum and matching installed executable, dependency findings, hardening and remaining launch requirements. The source inspection did not find malicious behavior in the test; Avast must determine whether its detection is a false positive. No antivirus settings were changed and no files were submitted to external scanners.

Updated development tools to resolve five critical npm package advisory entries, added dependency audits to CI, applied security headers and private caching to account responses, restricted demo sign-ins and existing sessions to local environments, and hardened session defaults and appearance-cookie handling. Composer and npm now report zero known advisories; this does not guarantee the absence of unknown vulnerabilities.

Validation: all 165 tests passed (1,257 assertions), with live SSR and Meilisearch and no skips. PHPStan, Pint, frontend formatting/lint, TypeScript and client/SSR builds passed. A clean `npm ci` succeeded and its subsequent audit reported zero vulnerabilities. HTTP probes blocked sensitive source/configuration paths; the live login response included the new headers. Production deployment checks and independent security testing remain required before launch.

## Input and injection hardening — 2026-10-09

The [input security review](docs/input-security-review.md) records SQL/search binding checks, SSRF exposure review, compiled-template escaping tests and coverage across authentication, listing/search, uploads, messages/reports, accounts and administration. Fixed server errors from malformed location IDs and credential shapes, added guards before upstream authentication actions, rejected null bytes, bounded pagination/attribute maps, and enforced bcrypt's 72-byte password limit to prevent silent truncation. Browser controls now reflect text/numeric/password limits and reject unsupported or oversized profile photos before upload; backend validation remains authoritative.

Native Vite defaults to loopback. Docker retains its explicit internal bind override and loopback port mapping. The running SSR service was confirmed on `127.0.0.1:13714`; the preview server again runs at `http://127.0.0.1:8000/`. The preview database and conversations were not used for destructive tests. Previously quarantined messaging test source was not changed.

Validation: all 192 tests passed (1,632 assertions), with live SSR/Meilisearch and no skips, including 27 new input/injection cases. PHPStan, Pint, frontend formatting/lint, TypeScript and client/SSR builds passed. A real HTTP malformed-search request returned 422 without SQL/PDO details. The existing upstream Inertia sourcemap warning remains. No known injection path was found in the reviewed application code; this is not a guarantee of complete security. Production controls and independent penetration testing remain on the launch checklist.

## Native deployment automation — 2026-10-10

Added a Ubuntu deploy command, safe production-settings parsing, optional remembered domains, certificate handling and pre-migration backups. Deployment uses one application directory; release archives and rollback/recovery commands were removed at the user's request. Laravel Horizon 5.50 supplies Redis worker supervision and queue visibility required by the brief. Its dashboard/API require an active administrator with confirmed session 2FA, including in local environments. A `deploy:health --storage` command checks service connectivity and a temporary private-object roundtrip without sending email or printing credentials.

Validation: 201 application tests passed (1,679 assertions), with live SSR/search; 28 deployment CLI safety checks and 11 configuration tests passed for the simplified script. Bash syntax, ShellCheck, PHPStan/Pint and frontend checks/builds passed. A separate SSR build served its health endpoint on `127.0.0.1:20014`; Composer/npm audits reported no known advisories. These application results preceded the deployment simplification. The [deployment guide](docs/deployment.md) records the updated disposable Ubuntu smoke workflow and remaining native integration, real ACME/storage/mail, reboot, backup and production checks.
