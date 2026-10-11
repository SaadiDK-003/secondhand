# Deploy on your Ubuntu VPS

Keep the complete uploaded or cloned project checkout in **`/var/www/html/secondhand`** and run `deploy.sh` there with sudo on **Ubuntu 24.04 LTS** (amd64 or arm64, with systemd). You can install Nginx, PHP, Node and Composer yourself first. The script reuses compatible installations and installs missing requirements. It does not pull code from Git or configure DNS.

## First deployment

Point your domain at the VPS and allow ports 80/443. Nginx serves only `/var/www/html/secondhand/public`. The initial domain is **secondhand.webostics.com**.

Keep settings in the project's root `.env`. If it already exists, edit it; otherwise copy the production example:

```bash
cd /var/www/html/secondhand
# Only when .env does not already exist:
sudo install -m 600 deploy/production.env.example .env
sudo nano .env
sudo chown root .env
sudo chmod 600 .env
sudo bash deploy.sh
```

Set `LISTING_IMAGES_DISK=listing_images` for private photos on this VPS at `storage/app/listing-images`, outside `public/`. No AWS credentials or paid storage account are required. The existing authorized image routes and WebP processing apply to VPS storage too. Do not create a public storage link for these images.

For your existing Gmail SMTP account, set `MAIL_MAILER=smtp`, `MAIL_HOST=smtp.gmail.com`, `MAIL_PORT=465`, `MAIL_SCHEME=smtps`, and your username, new app password and sender address. The older `MAIL_ENCRYPTION=ssl` setting is also accepted and converted to `MAIL_SCHEME=smtps`. `MAIL_FROM_NAME="${APP_NAME}"` is resolved to the application name without executing other substitutions. Passwords remain in your protected `.env`; never commit them. Deployment verifies SMTP authentication; test actual password-reset delivery before launch.

Deployment reads `.env` automatically on every run, preserves `APP_KEY`, and writes the final production configuration back to the same file with root ownership and read access for the app's group only (mode 640). It manages application URL/debug/security settings and its database/Redis/search ports and credentials, overriding local development values. Unknown settings produce an error instead of executing shell text. `--env-file FILE` remains an optional alternative for importing settings.

Cloud storage is optional: explicitly set `LISTING_IMAGES_DISK=s3` and configure the commented AWS fields in the example. For AWS S3, use the bucket region and omit `AWS_ENDPOINT`; for R2, use region `auto` and your HTTPS S3 endpoint. Keep the bucket private. Existing cloud deployments retain their disk. Switching the disk for new uploads does not move old images; retain their storage credentials until the files and database references have been migrated.

## Updates and domain changes

Upload or pull updated source into `/var/www/html/secondhand`, then run:

```bash
# Deploy and keep the saved domain, credentials and service ports.
sudo bash deploy.sh

# Deploy with a different domain, then remember it for later runs.
sudo bash deploy.sh --domain secondhand.om.pk

# Optional read-only checks. Edit root .env to change mail/storage settings.
sudo bash deploy.sh --check
```

`--email you@example.com` optionally supplies and remembers a Certbot contact address. Without one, certificate registration is non-interactive without an email. Use `--help` for the available options.

## What the script handles

- Checks PHP 8.4/FPM and its extensions, including GD JPEG/PNG/WebP support; Composer 2; Node 24/npm; PostgreSQL 16+; Redis; Meilisearch; Nginx; and Certbot. Missing PHP packages use the signed PHP PPA when necessary. Fresh PostgreSQL uses [Ubuntu's PostgreSQL packages](https://packages.ubuntu.com/noble/postgresql). Node/Composer fallback downloads and Meilisearch artifacts are verified before installation.
- Installs locked Composer/npm dependencies as an unprivileged account and builds the website and SSR before maintenance. It checks database, Redis, search, SMTP authentication and a private storage write/read/delete before publishing.
- Uses one application directory at `/var/www/html/secondhand`, persistent storage in its `storage` directory and protected saved credentials. Git metadata and local-only files are preserved when updating code in place. Temporary builds and deployment backups live under `/var/www/secondhand`; the live application stays in your chosen directory. It preserves `APP_KEY`, disables debug and uses HTTPS, secure cookies and Redis sessions/cache/queues.
- Backs up PostgreSQL before migrations, seeds only reference categories/locations/attributes on the first successful deployment, caches Laravel configuration and configures the search index. It does not seed demo users or listings.
- Creates only this app's Nginx site and PHP-FPM socket, with a 128 MB upload limit and sensitive-file denial. It validates configuration before reloads and configures HTTPS using Certbot's [webroot challenge](https://eff-certbot.readthedocs.io/en/stable/using.html#webroot). Valid certificates are reused; automatic renewal reloads Nginx.
- Supervises Horizon workers, SSR and the every-minute scheduler through systemd so they start after a reboot. Horizon access continues to require an active administrator with confirmed session 2FA.

Database, Redis, search and SSR instances bind to loopback and use available private ports. Existing apps such as Next.js on port 3000 are preserved. Nginx shares public 80/443 between domains; if another web server owns those ports, deployment exits with an explanation rather than stopping it. Existing compatible database/Redis binaries are reused with separate app data/configuration, so another app's credentials or service configuration are not changed.

Changing domains obtains the new certificate before maintenance, then updates Laravel's URL/host allowance and Nginx together. Our previous hostname is removed only after the new HTTPS check passes. Other sites and certificate files are retained. DNS is your responsibility; ACME validation still requires the domain and port 80 to be publicly reachable. Behind Cloudflare, use Full (strict) TLS and leave ACME challenges uncached; configure trusted visitor-IP handling separately.

## Errors and useful paths

Deployment uses a short maintenance window for migrations and replacing application files. Build, settings or certificate failures occur before that window and preserve an existing working site. A failure after migrations begin leaves maintenance enabled and workers stopped: fix the reported error and rerun **the same command**, including any domain/settings options. The script does not automatically reverse migrations or restore the database. There is no release history, `--rollback` or `--recover` command.

- `/var/log/secondhand-deploy.log`: root-only, redacted command details.
- `/etc/secondhand/state.json`: root-only saved domain, ports and credentials; keep a protected copy.
- `/var/www/html/secondhand/storage`: persistent application storage/logs.
- `/var/www/html/secondhand/storage/app/listing-images`: private listing and profile images; include this directory in off-server backups.
- `/var/www/secondhand/backups`: protected pre-migration PostgreSQL dumps; copy off the VPS and manage retention.
- `/var/lib/secondhand`: database, Redis and search data.

If a deployment is forcibly killed or the VPS loses power, inspect the log and rerun the same command. Temporary package-start suppression may need removal from `/usr/sbin/policy-rc.d` **only if its contents are exactly the script's `#!/bin/sh` followed by `exit 101`**; preserve any pre-existing policy. A saved `run.*/createcluster.conf` contains the original PostgreSQL cluster-creation configuration if installation was interrupted. Normal errors/signals clean these up automatically. An older release-based installation is rejected instead of overwritten; migrate it explicitly if one exists.

## Moving to another VPS and validation

Use a new Ubuntu VPS and preserve the existing `APP_KEY` and mail/storage settings in its root `.env`. Deploy once to initialize local services, then stop writes/workers, transfer `storage/app/listing-images` and a protected database dump, and restore the dump into the new application's database using its saved port. Restore private image ownership to `secondhand:secondhand`; keep it outside `public/`. If using cloud storage, retain the existing private bucket and credentials. Use `pg_restore --clean --if-exists --no-owner --no-privileges --role=secondhand` against **the new database only**, through the app's `/run/secondhand-postgres` socket as the postgres account. Rerun deployment, synchronize/import search with `scout:sync-index-settings` and `scout:import 'App\Models\Listing'`, verify the site, then change DNS. Data and secrets are not automatically copied between VPS providers. Pre-migration backups cover PostgreSQL only; daily off-server database and image backups, plus a tested restore, remain launch requirements.

Local verification passed: Bash syntax, ShellCheck, 30 mocked CLI/safety checks, 14 configuration tests, PHPStan and 24 deployment health/image tests (136 assertions). Coverage includes importing the root Laravel `.env`, Gmail SSL configuration, private VPS WebP uploads and rejecting public image storage. The manual deployment workflow provides disposable Ubuntu smoke scenarios for fresh/shared servers, dependency reuse, private-port conflicts, repeat deployment, certificates, domain changes and a Horizon WebP job. Certificate/storage/mail fixtures do not prove real provider behavior. Native Ubuntu execution, actual reboot, real ACME/cloud/SMTP, real email delivery and off-server backup restore testing remain pending before launch.
