# Deployment runbook

Phase 9 (security pass + deployment). This assumes cPanel-style shared
hosting per brief D5/§8 — adjust paths for a different panel or a VPS.

## 0. Before you start — confirm with your host (Q8, still open)

- **Composer access** — either SSH to run `composer install` yourself, or a
  cPanel "Setup PHP App" / Softaculous flow that runs it for you. If neither
  is available, you'll need to `composer install` locally and upload the
  `vendor/` directory (large — mPDF alone pulls in font data).
- **Cron job access** — cPanel's "Cron Jobs" page, or ask your host. Needed
  for nightly compliance evaluation (§8); see step 6.
- **PHP version** — 8.1+ (developed against 8.3). Set it in cPanel's
  "MultiPHP Manager" / "Select PHP Version" for this domain.
- **PHP extensions** — `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `json`,
  `gd` (mPDF wants it for image handling; the app boots without it but PDF
  generation is more reliable with it present), `intl`, `zip`. Check under
  "Select PHP Version" → Extensions.
- **MySQL 8 or MariaDB 10.6+**, and whether you can create a *second* MySQL
  user for the database (needed for the two-user privilege split in
  `db/grants.sql` — see step 4; if the panel only allows one user per
  database, that step has a documented fallback).
- **Storage quota** for photo attachments + generated PDFs/CSVs — ask what
  the plan allows and whether it can grow.
- **TLS/HTTPS** — confirm a certificate is issued and HTTPS actually works
  end-to-end for this domain before touching HSTS (step 8) — enabling it too
  early can lock a browser out of the site.

## 1. Folder layout

Only `public/` is web-exposed. On cPanel that means `public/`'s *contents*
go into `public_html/` (or a subdomain's document root), and everything else
— `src/`, `config/`, `db/`, `storage/`, `templates/`, `vendor/`, `.env` —
lives **one level up**, outside the web root:

```
/home/youruser/
  adwol-pia/                 <- app root (src/, config/, vendor/, .env, …)
    public/                  <- NOT the web root itself
  public_html/                <- web root; contents of adwol-pia/public/ go here
```

If your panel forces the app into `public_html/` directly with no way to
place code above it, put everything under `public_html/`, deny access to
everything except `public/`'s contents via `.htaccess` `RewriteRule`s (not
provided here — verify with your host, this is the weaker fallback), or ask
about a symlinked document root pointing at `adwol-pia/public`.

`public/index.php` expects `require dirname(__DIR__) . '/vendor/autoload.php'`
— i.e. `vendor/` one level above `public/`. Keep that relationship wherever
the app root actually ends up.

## 2. Environment

```bash
cp .env.example .env
php -r "for(\$i=0;\$i<3;\$i++) echo bin2hex(random_bytes(32)), PHP_EOL;"
# paste the three 64-hex values into APP_KEY, JWT_SECRET, HMAC_DOCUMENT_KEY
```

Then edit `.env`:
- `APP_ENV=production`, `APP_DEBUG=false` (debug is force-disabled in
  production regardless — `config/settings.php` — but set it explicitly).
- `APP_URL` to the real domain.
- `DB_*` — the **`pia_app`** user from step 4, not `pia_migrate` and not root.
- `PIA_COMPANY_NAME` / `PIA_COMPANY_ADDRESS` — the fresh-install default only;
  an admin can change it later at `/console/settings` without a redeploy.
- Everything else has a sane default (see `.env.example`'s comments) — only
  change `NOTICE_WINDOW_HOURS`, `COMPLIANCE_GRACE_HOURS`,
  `COMPLIANCE_STRIKE_THRESHOLD` once the client confirms their real policy
  (Q9 — currently reasonable, reversible defaults).

`.env` must never be inside `public/` and must never be committed — confirm
`public/.htaccess`'s dotfile deny rule is active (`<FilesMatch "^\.">`).

## 3. Install dependencies

```bash
composer install --no-dev --optimize-autoloader
```

`--no-dev` skips PHPUnit and friends — the production install doesn't need
them. `--optimize-autoloader` is a meaningful speed win with no downside.

## 4. Database

Create the database and the two MySQL users, then apply `db/grants.sql`
(fill in `<db_name>`, `<migrate_password>`, `<app_password>` first — see the
file's own header for the two-user rationale and the single-user fallback):

```bash
mysql -u root -p your_db_name < db/grants.sql   # or via phpMyAdmin
```

Migrate using the **`pia_migrate`** credentials (temporarily point `.env` at
them, or pass overrides — do not leave `pia_migrate`'s password in the
runtime `.env`):

```bash
DB_USERNAME=pia_migrate DB_PASSWORD='<migrate_password>' composer migrate
```

Then switch `.env`'s `DB_USERNAME`/`DB_PASSWORD` to **`pia_app`** for the
live application and confirm the site still works (step 9). Do **not** run
`composer seed` in production — `db/seeds/DevSeeder.php` creates a
known-password dev account; create the first real `super_admin` by hand:

```sql
INSERT INTO users (uuid, full_name, email, password_hash, role, status, created_at, updated_at)
VALUES (UUID(), 'Full Name', 'you@company.com',
        -- generate with: php -r "echo password_hash('a-real-password', PASSWORD_ARGON2ID, ['memory_cost'=>65536,'time_cost'=>4,'threads'=>1]);"
        '$argon2id$...', 'super_admin', 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP());
```

## 5. Storage directories

`storage/attachments/`, `storage/documents/`, `storage/returns/`,
`storage/invoices/`, `storage/record-attachments/`, `storage/backups/`,
`storage/cache/mpdf/`, `storage/logs/` must exist and be writable by the
PHP process (they're created on first use if missing, but pre-creating them
lets you set permissions deliberately — `755` directories is usually enough
on shared hosting; avoid `777`). None of these are inside `public/`, so
they're not web-accessible regardless — that's the actual security boundary,
not the file mode.

## 6. Cron

Compliance evaluation (brief §8) — add via cPanel's Cron Jobs page:

```
0 2 * * *  cd /home/youruser/adwol-pia && php bin/evaluate-compliance.php >> storage/logs/compliance.log 2>&1
```

(Adjust the path and use the server's real PHP CLI binary — cPanel often
needs the versioned path, e.g. `/usr/local/bin/php81`.)

**Statutory returns are not on a cron yet** — each agency's real filing
cadence isn't confirmed (Q6/Q9). Once known, a second cron entry calling
`POST /api/statutory-returns/generate` (or a small `bin/` script following
the same pattern as `evaluate-compliance.php`) is a small, low-risk addition.

## 7. Refresh token cleanup (optional but recommended)

`RefreshTokenService::purgeExpired()` exists but isn't wired to a cron yet —
expired/used refresh token rows just accumulate in `refresh_tokens`. Fine at
small scale; add a monthly cron once it's worth doing:

```bash
php -r "require 'vendor/autoload.php'; \$c = App\Http\ContainerFactory::create(); \$c->get(App\Auth\RefreshTokenService::class)->purgeExpired(new DateTimeImmutable('-30 days'));"
```

## 8. Enabling HTTPS enforcement

Once HTTPS is confirmed working for the real domain (step 0), uncomment the
HSTS line in `public/.htaccess` (it's there, commented, with instructions).
Start with a short `max-age` if you want to be cautious, and only add
`preload` once you're confident — removing HSTS after browsers have cached
it is slow (they honor the cached `max-age` regardless of later header
changes).

## 9. Post-deploy smoke test

- `GET /health` → 200, `checks.database.status: "ok"`.
- `GET /console/login` → loads, has a `Set-Cookie` with `Secure` (only over
  HTTPS — confirm `APP_ENV=production` so `SessionMiddleware` sets it).
- Log in as the `super_admin` created in step 4; dashboard loads.
- Create a client → consignment → inspection request → schedule (needs an
  inspector account) → sync via the API → finalize → confirm a CCI PDF
  downloads and its letterhead matches what's set at `/console/settings`.
- `POST /api/auth/login` with a bad password 10 times quickly → the 11th is
  429 (rate limiting live).
- Confirm `storage/`, `.env`, `vendor/`, `src/`, `config/` are **not**
  reachable over HTTP (e.g. `GET /../.env`, `GET /../src/` → 404, not 200).
- `bin/evaluate-compliance.php` runs cleanly by hand once before trusting
  the cron entry.

## 10. Backups

The app can make its own backups — pure PHP, no `mysqldump`/shell access needed
(shared hosting friendly):

```bash
0 1 * * *  cd /home/youruser/adwol-pia && php bin/backup.php --keep=14 >> storage/logs/backup.log 2>&1
```

Each run writes `storage/backups/pia-backup-YYYYMMDD-HHMMSS.zip` (with a
`.sha256` sidecar) containing:

- `database.sql` — every table (structure + rows; `rate_limits` structure only),
  timestamps pinned to UTC so a restore on a server in any time zone is exact;
- `files/attachments/`, `files/documents/`, `files/returns/`, `files/invoices/`,
  `files/record-attachments/` — the generated and uploaded files (the database
  only stores their path and hash, so it can't recreate them);
- `manifest.json` — versions and per-table row counts.

Super admins can also create, download and delete backups at **Console →
Backups**. `--keep=N` prunes all but the newest N.

**The archive contains every password hash and all client data, and it sits on
the same server it protects.** A backup you can only reach from the server that
just died is not a backup: copy them off-box (rsync/scp to another machine, or
your host's backup feature) on a schedule, and treat the copies as sensitive as
the live database. If PHP has no `zip` extension the archive is a gzipped SQL
dump only — copy `storage/` yourself in that case (the CLI tells you).

> **Keep `APP_KEY` safe and unchanged — and back up `.env` separately.** Two-factor
> seeds are stored encrypted under a key derived from `APP_KEY`, and recovery codes
> are hashed with it. The backup archive deliberately does **not** contain `.env`
> (that would put the key next to the data it protects). So: restoring onto a new
> server needs the *same* `APP_KEY`; and if you ever rotate `APP_KEY`, every
> enrolled 2FA seed and recovery code stops working (it fails closed) — an admin
> must then reset each person's two-factor from their user page and they re-enrol.

### Restoring a backup

Deliberately manual — overwriting a live database should never be one click.

1. Put the site in maintenance mode / stop cron jobs.
2. Verify the archive: `sha256sum pia-backup-….zip` must equal the `.sha256` file.
3. Unzip it. Load the database into the (empty or to-be-replaced) database using
   an account with DDL rights — **not** the runtime `pia_app` user:
   `mysql -u pia_migrate -p <db_name> < database.sql`
   (the script drops and recreates each table, so it replaces what is there).
4. Copy the `files/<dir>/` folders back into `storage/<dir>/`.
5. Run `composer migrate` — if the backup is older than the deployed code, this
   brings the schema forward.
6. Sign in and spot-check a recent inspection, a generated CCI download, and the
   audit log.

Practise this once on a scratch database before you ever need it — it was
verified during development by restoring into a fresh database and comparing
every table's contents to the source.

## 10b. Email (optional)

Email is off until `MAIL_HOST` is set in `.env` (see `.env.example`: host, port,
`MAIL_ENCRYPTION` = `tls` for port 587 / `ssl` for 465, username, password, from
address). Nothing breaks without it — it only switches off two features:

- **"Forgot your password?"** on the console login page (self-service reset by
  emailed link: single-use, 60-minute, only the token's hash is stored; the link
  is built from `APP_URL`, so **set `APP_URL` to the real public https address**).
- **The daily "needs attention" digest** — `php bin/send-digest.php`, e.g.
  `30 7 * * 1-5  cd /home/youruser/adwol-pia && php bin/send-digest.php >> storage/logs/digest.log 2>&1`.
  Nobody is emailed on a quiet day; individual users can opt out on their user page.

After configuring, open **Console → Settings → Email** and press *Send me a test
email*. SMTP credentials live only in `.env` — they are secrets, so they are
deliberately not editable from the browser. Use a dedicated sending mailbox or
your host's SMTP relay; a personal mailbox password in `.env` is a bad idea.

## 11. Updating the deployed code later

```bash
composer install --no-dev --optimize-autoloader   # if composer.lock changed
DB_USERNAME=pia_migrate DB_PASSWORD='<migrate_password>' composer migrate
```

Migrations are additive by design (see `DEV_NOTES.md`'s deviation log) — no
migration in this codebase has ever dropped a brief §4 column, so rolling
forward is always safe. Rolling back (`composer rollback`) is a dev-only
convenience; treat it as destructive in production and don't run it there.
