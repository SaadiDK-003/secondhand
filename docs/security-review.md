# Local security review — 2026-10-09

This is a source, dependency and local application review following an Avast Behavior Shield `IDP.Generic` alert for `tests/Feature/Marketplace/MessagingTest.php`, with `.tools/php/php.exe` listed as the process. It is not an antivirus verdict, a forensic investigation of the computer, an independent penetration test, or a guarantee of complete security. No production deployment exists yet.

## Antivirus finding

The inspected test contains ordinary PHPUnit/Laravel fixtures, HTTP assertions, authorization/rate-limit checks, unread-count checks and a literal XSS payload used as message text. No shell execution, dynamic evaluation, encoded executable payload or remote downloader was found in this file. The literal script string is test data; the chat template renders message bodies with Vue text interpolation. A heuristic false positive is plausible, but the exact trigger and final classification must be confirmed by Avast. The test was not renamed, obfuscated, deleted or weakened to avoid detection.

SHA-256 of the inspected, unchanged test:

```text
649885FE63F229F48F8808C19CB3372A37E8C05EE9097B1486CD620A0287D93C
```

The saved PHP archive matches the [official PHP Windows release manifest](https://downloads.php.net/~windows/releases/releases.json) for `php-8.4.26-nts-Win32-vs17-x64.zip`:

```text
Archive: da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7
php.exe: 9c9c7cdb2ec112cf39498dd1a77b56eacac482487182066920f1bcd209091898
```

The installed executable matches the executable in that checksum-verified archive. This establishes those files' integrity against the release artifact; it does not certify every runtime extension or the computer as malware-free.

Keep antivirus protection enabled. If the alert continues, use Avast's [official file-review process](https://support.avast.com/en-ph/article/submit-file-url), with only the specific flagged source file and alert details after reviewing what will be uploaded. Do not upload `.env`, private keys, database exports, storage or application logs. No file has been submitted to Avast or another scanning service by this review. No antivirus exclusions or settings were changed.

## Dependency findings and fixes

The initial Composer audit found no advisories or abandoned dependencies. The npm audit found five critical package entries in development/build tooling, tracing to three advisories:

- [shell-quote command injection](https://github.com/advisories/GHSA-pqg4-j6r4-53mv), reached through concurrently.
- [tinypool worker-options prototype pollution](https://github.com/advisories/GHSA-5gmw-xhrv-c9v3).
- [tinypool run-options prototype pollution](https://github.com/advisories/GHSA-85c8-ppgw-ccpr), reached through the Vite Plus formatting toolchain.

Updated concurrently to 10.0.6 (`shell-quote` 1.12.0) and Vite Plus to 0.3.3 (`oxfmt` 0.68.0, `tinypool` 2.1.2). Vite now uses the matching official Vite Plus core alias and npm override required by that toolchain. Dependency lockfiles record the updates. These advisory findings do not establish a connection to the antivirus alert or prove exploitation occurred.

After updates, Composer and npm audits reported zero known advisories. The npm check used Node's system CA support because HTTPS inspection required the trusted Windows certificate store; TLS verification remained enabled. CI now runs `composer audit --locked` and `npm audit --audit-level=high`. Audits are a snapshot of published advisories, not a malware scan or proof that unknown vulnerabilities do not exist.

## Application hardening

- Added `nosniff`, same-origin framing protection, a referrer policy and a CSP restricting base URLs, embedded objects and framing. These follow the intent of the [OWASP header guidance](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html). This is a limited CSP, not a strict script allowlist; nonce/script enforcement needs separate Livewire, SSR and CDN verification before production.
- Applied private/no-store caching to authenticated responses and authentication/admin/account endpoints, including rendered errors. Public marketplace caching remains available.
- Known local demo accounts cannot authenticate or retain an authenticated session outside `APP_ENV=local`, even if someone copies the preview database. Credentials remain omitted from non-local login responses; seeders remain restricted to the local preview database.
- Session encryption now defaults on, and secure cookies default on for production when no explicit environment override is provided. `.env.example` still contains explicit HTTP development settings; production must supply secure values.
- Appearance cookies accept only `light`, `dark` or `system` before reaching the HTML template.

Reviewed existing protections: hashed passwords, normal Fortify login/throttling, CSRF protection, participant/owner authorization, server-derived sender IDs, private photo storage, actual image MIME/dimension/size checks, re-encoding to WebP, hidden contact/password/factor fields and admin access requiring a completed two-factor session. Existing feature tests exercise these controls; this review does not claim exhaustive coverage of every threat.

Actual HTTP probes against the local server blocked `.env`, Composer metadata, Git metadata, application logs and test source (403/404). The web document root must remain `public/` in production.

## Verification and launch boundary

Focused tests cover security headers on public/private/error responses, no-store authentication pages, real CSRF rejection/acceptance, restricted appearance values, and demo login/session rejection outside local. All 165 tests passed (1,257 assertions), with live SSR and Meilisearch enabled and no skipped tests. PHPStan, Pint, frontend formatting/lint, TypeScript and client/SSR builds passed. A clean `npm ci` succeeded, and the subsequent npm audit again reported zero vulnerabilities. The flagged test's SHA-256 remained unchanged. The live login response included the new headers and private/no-store caching.

Before public launch, complete the existing [launch checklist](launch-readiness.md): HTTPS and HSTS at the trusted edge, debug off, appropriate trusted hosts/proxies, production secrets and credentials, secure cookies, private cloud storage, Redis/worker/scheduler integration, least-privilege infrastructure, protected monitoring/logs, backups with a tested restore and deployment/rollback verification. Remove demo accounts and development tooling from production artifacts/data. Validate a strict CSP against the deployed application, repeat security tests against that environment, and arrange an independent application penetration test before handling real customer data. Maintain dependency updates and monitoring after launch.

The local preview intentionally uses HTTP, local debug settings and demo credentials. It must remain bound to loopback and must not be exposed publicly.
