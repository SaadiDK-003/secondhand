# Design, images and responsive review — 2026-10-09

Local review of the built application using PostgreSQL, Meilisearch, Node SSR and the native PHP 8.4 preview. No production deployment was performed.

## Design and usability changes

- Kept the light blue/slate/off-white design across marketplace and account pages. The header now stays compact on small phones and arranges account/search controls coherently on tablets and desktop.
- Reduced the mobile homepage hero, shortened its introduction and increased category-label readability. Kept real licensed photography and explicit demo labels.
- Added consistent branded permission, missing-page, expired-session, rate-limit and service-error templates. Updated the favicon to the same blue second-chance/check symbol as the site logo.
- Improved mobile form text, touch targets, settings navigation, photo-selection limits, pagination wrapping and long conversation/title handling.
- Added a working keyboard skip link, visible theme-consistent focus outlines, keyboard-accessible password visibility controls, named two-factor/recovery fields and announced validation errors. Posting validation focuses the error summary while preserving entered values.
- Profile uploads clear the file chooser after success. Admin listing actions now show only decisions allowed by the current listing state; server authorization remains unchanged.
- Honored saved appearance preferences; verified switching dark/light and restored light. Reduced-motion preferences disable decorative transitions.

## Responsive coverage

The saved per-page matrix covers 18 main page types at each width below. No document overflow was found. Visible mobile form controls use at least 16 px text at phone widths; primary touch controls have a 44 px minimum target where applicable.

| Width   | Main page checks | Result |
| ------- | ---------------- | ------ |
| 320 px  | 18               | Passed |
| 375 px  | 18               | Passed |
| 390 px  | 18               | Passed |
| 430 px  | 18               | Passed |
| 768 px  | 18               | Passed |
| 1024 px | 18               | Passed |
| 1440 px | 18               | Passed |

Pages: home, search, category, city browse, public ad, public seller, dashboard, my listings, posting, own preview/edit, favorites, inbox, populated conversation, blocked users and all three settings pages. Login, registration, forgotten password, reset-password layout and 404 received 20 additional checks across 320/390/768/1440 px. Two-factor/recovery and 403 were also reviewed at 320 px.

Visual inspection supplemented the geometry checks: mobile category labels/expanded filters, populated conversations, tablet settings, desktop item hierarchy and error pages. Category navigation deliberately scrolls horizontally; photo strips and admin tables may scroll inside their own containers. The check does not prohibit these intended controls.

These are browser viewport checks with local demo/test data, not certification for every physical device, browser or possible content combination. Physical iOS/Android testing, browser coverage and slow-network Web Vitals remain pre-launch checks.

## Browser journeys completed

- Created a seller draft with category-specific brand/model fields, dependent province/city/area choices and real JPEG/PNG uploads. Verified both photos, selected the second photo and submitted for review.
- Signed a disposable admin in through the actual TOTP challenge and approved that ad with a review reason. Confirmed publication and state-appropriate admin actions.
- Used a separate buyer to save the ad, verify it in favorites, submit a clearly identified QA report and start a conversation. Replied as the seller; verified block/unblock removes/restores the composer.
- Uploaded a JPEG profile photo, replaced it with PNG, rejected a non-image file and removed the photo. Confirmed the account-security password confirmation flow without changing credentials.
- Marked the ad sold and confirmed its exclusion from the published scope. Prepared that same disposable record as an expired fixture, renewed it through the UI, edited the price, saved and resubmitted for review. The actual expiry job and state/ownership restrictions are covered by automated tests; the browser exercise did not wait 30 days.
- Submitted a valid posting form without photos and verified the server error receives keyboard focus. Verified the skip link works and has a visible outline.

All three QA accounts, their listing, conversations/messages, saved items, QA reports/audit entries, credentials and uploaded image files were removed. The cleanup check found zero remaining QA users/image files and zero pending jobs. Four labelled demo listings remain.

The real upload journey exposed an unwritable native PHP temporary directory. The ignored preview PHP configuration now uses a writable project-local `.tools/uploads` directory. Other environments must configure an appropriate writable PHP upload temporary directory; this is not a production storage setting.

## Image verification and bandwidth

Listing uploads are validated and processed in the queue into private WebP variants at quality 82: thumbnail, card, medium and large. PNG/JPEG inputs are not served unchanged. Aspect ratio and transparency are preserved, small images are not upscaled, JPEG orientation is honored by the processor, and source metadata is discarded during re-encoding. Originals are deleted only after every variant is stored successfully. Profile photos use a bounded square WebP conversion.

Added conversion coverage for JPEG, PNG and WebP, transparent PNG output, actual portrait widths in responsive sources and deduplication for small images. Existing ownership, format, dimensions and private-image route checks remain in place.

Real browser-upload example, 1200 × 800 source:

| Source | Original bytes | Card WebP | Large WebP |
| ------ | -------------- | --------- | ---------- |
| JPEG   | 106,550        | 13,152    | 33,478     |
| PNG    | 606,874        | 12,932    | 33,342     |

Cards and detail photos now expose width-correct `srcset` choices; detail thumbnails use the thumbnail variant. The browser selected the smaller variant at the 320 px viewport. Authorized image routes retain their private access/cache policy.

Homepage editorial photos also have 600 px WebP alternatives: the living-room photo is 36,262 bytes versus the 129,926-byte original; the tech photo is 40,738 versus 137,560 bytes. Responsive markup preserves larger originals for larger displays. Existing lazy loading, fixed image containers and high-priority hero loading remain.

Reference-data caching was measured at 6 queries cold and 0 warm. Local HTTP timing details are in [performance baseline](performance-baseline.md); they exclude asset downloads and rendering. Production PHP-FPM, Redis, R2/CDN delivery, slow-network LCP/INP/CLS and representative volume/load still require the deployed environment.

## Validation

- Full suite: 145 tests passed, 986 assertions, including live SSR and Meilisearch checks.
- A later SSR/authentication check passed 22 tests, 201 assertions after the authentication/homepage follow-up; these overlap the full suite.
- PHPStan, Pint, frontend formatting/lint, TypeScript and client/SSR builds passed. The existing upstream Inertia sourcemap warning remains.
- No JavaScript console warnings/errors on the refreshed final marketplace pages. Deliberate 403/404 navigation is expected to return those HTTP statuses.

See [launch readiness](launch-readiness.md) for the remaining production and physical-device checks. This review does not claim a Lighthouse score, production capacity or pixel-perfect behavior on every device.
