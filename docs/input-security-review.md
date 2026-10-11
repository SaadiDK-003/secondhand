# Input and injection review — 2026-10-09

This review covers the current marketplace's public, account and moderation inputs. “SSR attacks” was interpreted to include server-side request forgery (SSRF), server-side rendering/template injection and cross-site scripting (XSS). This is an application review with regression tests, not a guarantee that every possible attack is impossible or a substitute for a penetration test of the deployed system.

## Findings fixed

- A malformed province value could reach a dependent city existence check and cause a PostgreSQL type error. IDs now stop validation at the first type failure, and dependent existence checks receive only integer values. These queries used parameter bindings; the finding was an error-handling gap, not execution of injected SQL.
- Login throttling converted identifiers to strings before validation, so array-shaped input could cause a server error. It now checks the type first, bounds the identifier and hashes it in the throttle key. Equivalent mobile formats still share a rate limit. An application LoginRequest enforces bounded credentials and valid remember-checkbox input.
- Some Fortify registration/reset/confirmation actions normalize or use input before complete validation. A small middleware validates those fields' types and sizes first, and limits two-factor/recovery codes. Existing actions still enforce full registration, password policy, broker tokens and authentication checks.
- Null bytes are rejected in stored free text and passwords, preventing PostgreSQL text errors and invalid hashing inputs. Quotes, angle brackets and SQL-looking text remain legitimate text; they are bound or escaped in the relevant output context rather than globally stripped.
- Password inputs enforce bcrypt's 72-byte limit, including multibyte characters, to prevent silently ignored suffixes. This follows [PHP's documented bcrypt limit](https://www.php.net/manual/en/function.password-hash.php). Frontend controls cap entry length; backend byte checks remain authoritative. Any older development account created with a longer password should reset it. No existing accounts or hashes were altered. Revisit this policy if the hashing algorithm changes.
- Listing attribute maps and paginated account/inbox pages have bounded sizes. Existing public/search pagination limits remain. Native Vite now defaults to loopback; Docker explicitly overrides its internal address and publishes its development port only on loopback.

## Input coverage

| Area                                 | Backend enforcement                                                                                                                                              | Frontend handling                                                                              |
| ------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------- |
| Authentication                       | Scalar types, lengths/byte limits, email/mobile formats, password policy, reset tokens, rate limits and normal Fortify authentication                            | Input types, autocomplete, length limits, password rules and validation errors                 |
| Listing details/location             | Seller authorization, typed IDs, active leaf category, matching province/city/area, price bounds, condition allowlist, text lengths and writable-field allowlist | Required fields, text/numeric limits, dependent dropdowns and error summary                    |
| Attributes/search                    | Category-specific keys/types, bounded text/numbers/maps/lists, approved options and sorts                                                                        | Appropriate controls, numeric bounds and separate posting/search text limits                   |
| Photos                               | Uploaded files only; actual content/MIME, extension, size/dimensions checked; private storage and WebP conversion; no URL-import feature                         | Supported formats, size checks, count and errors; actual content/dimensions verified by server |
| Messages/reports                     | Participant authorization, server-selected sender, text/reason limits, blocking, rate limits and CSRF                                                            | Plain text rendering, message length, reason choices and errors                                |
| Profiles/favorites/blocks/ad actions | Authenticated owner/participant, server-selected targets/permissions, bound database operations and bounded pagination                                           | Escaped text, server-generated internal links and permission-aware controls                    |
| Administration                       | Confirmed admin two-factor session, server-side actions, bounded reasons, approved decisions and escaped table text                                              | Filament validation, confirmations and escaped content                                         |

Browser validation improves feedback; it does not authorize actions. Crafted requests are validated on the backend. Tests deliberately bypass the forms.

## SQL and search injection

Reviewed database calls use Eloquent/query-builder bindings. The single application `whereRaw` expression uses `lower(title) = ?` with a separate binding. No request value is concatenated into executable SQL. Tests check literal SQL-looking text, rejected malformed IDs, protected privileged fields, duplicate-title matching and admin table searches. See [OWASP's parameterized-query guidance](https://cheatsheetseries.owasp.org/cheatsheets/SQL_Injection_Prevention_Cheat_Sheet.html).

Meilisearch uses a different query language. Filter names come from code or server-side attribute IDs, numbers are cast, strings/lists are JSON-encoded and sorts come from fixed choices. The live suite includes an attempted filter injection and excludes withdrawn/private records even when the index is stale.

## SSRF and rendered output

No current input accepts a URL for the server to download an arbitrary image/resource. Upload URLs are rejected as non-files; message URLs remain text. Reviewed destinations for SSR, search, storage, mail and compromised-password checking come from server configuration or library-controlled endpoints, not a user's URL. A focused message/profile test records zero Laravel HTTP-client requests; that assertion does not cover every third-party networking stack. Infrastructure outbound restrictions remain deployment work.

Keep SSR on loopback in the native preview and on a private network without a published port in Docker. Public pages use SSR only for guests; account/chat pages normally render on the client. No user input is compiled as a Vue template. Tests force the actual compiled listing, seller and chat templates through the local SSR renderer, parse the HTML, and check that scripts/event handlers cannot be introduced and template-looking expressions remain literal text. Admin tables are checked separately. SEO JSON uses context-safe encoding against script-tag breakout. See [Vue's security guidance](https://vuejs.org/guide/best-practices/security).

The sole `v-html` usage receives Fortify's generated two-factor QR SVG, not marketplace text. Future rich text, URL imports, link previews, webhooks or remote SVGs need their own review/tests before release. Do not reuse QR rendering for user content.

## Verification and limits

The isolated database is `second_hand_test`; preview inventory/conversations are preserved. All 192 tests passed (1,632 assertions), with live Meilisearch and SSR enabled and no skipped tests. This includes 27 new input/injection cases; the focused input/auth/account run passed 75 tests (652 assertions). PHPStan, Pint, frontend lint/format, TypeScript and client/SSR builds passed. The existing upstream Inertia sourcemap warning remains.

An actual HTTP request with an array-shaped search ID returned 422 with validation errors and no SQL/PDO details. Login returned 200 with nosniff and private/no-store headers. The running SSR listener was confirmed at `127.0.0.1:13714`. The previously flagged MessagingTest.php hash remained unchanged. This extends the [earlier security report](security-review.md).

Production HTTPS, secure cookies, debug-off settings, database least privilege, private service ports, outbound restrictions, WAF/request-size limits, protected logs, backups and independent security testing remain in the [launch checklist](launch-readiness.md). The limited CSP is not a strict script allowlist. Recheck the deployed environment and future changes.
