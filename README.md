# ADWOL PIA Automation System

Internal Preshipment Inspection Agency (PIA) automation: NXP record intake,
inspection scheduling, offline field capture (PWA), CCI/NNCI certificate
generation, compliance tracking, statutory returns, monthly CBN invoicing and a
document archive.

> **Terminology:** the business calls each transaction an **NXP** (after the NXP
> form number that identifies it). The screens and console URLs say "NXP"; the
> database table, PHP classes and JSON API still use the original internal name
> `consignment` (`/api/office/consignments`, `consignment_uuid`).

**Authoritative spec:** [`PIA_BUILD_BRIEF.md`](PIA_BUILD_BRIEF.md).
**Running decision/assumption log:** [`DEV_NOTES.md`](DEV_NOTES.md).
**Deploying to real hosting:** [`DEPLOYMENT.md`](DEPLOYMENT.md).

## Stack

| Concern | Choice |
|---|---|
| Language / runtime | PHP 8.1+ (developed on 8.3) |
| HTTP | Slim 4 + PHP-DI |
| Database | MySQL 8 / MariaDB 10.6+ (InnoDB, utf8mb4) |
| Migrations | Phinx |
| Logging | Monolog (PSR-3) |
| Tests | PHPUnit 10 |
| PDF | mPDF (`mpdf/mpdf`) |

## Layout

```
public/           Front controller (the only web-exposed directory) + .htaccess
  app/            Inspector PWA — static app shell, service worker, ES modules
src/              Application code (PSR-4: App\)
  Http/           Slim app factory, container, middleware, controllers, handlers
  Support/        Env loader, PDO factory
  Logging/        Monolog factory
  Database/       Shared migration helpers
config/           settings.php — the single place env vars are read
db/migrations/    Phinx migrations (core schema)
db/seeds/         Phinx seeders (dev data only)
storage/          logs/, attachments/, cache/ (git-ignored contents)
tests/            PHPUnit suite + bootstrap
```

## Local setup

Prerequisites: PHP 8.1+ with `pdo_mysql`, `mbstring`, `intl`, `openssl`,
`fileinfo`; Composer; a running MySQL server.

```bash
composer install
cp .env.example .env
# generate three 64-hex secrets and paste into APP_KEY / JWT_SECRET / HMAC_DOCUMENT_KEY:
php -r "for(\$i=0;\$i<3;\$i++) echo bin2hex(random_bytes(32)), PHP_EOL;"

# create the databases (adjust credentials to match .env)
mysql -uroot -e "CREATE DATABASE adwol_pia_dev  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -uroot -e "CREATE DATABASE adwol_pia_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

composer migrate          # phinx migrate -e development
composer seed             # dev super_admin + sample client
composer serve            # http://127.0.0.1:8080
```

Dev login (from the seeder): `admin@adwol.test` / `ChangeMe!123`.

## Common commands

| Command | Does |
|---|---|
| `composer migrate` | Apply migrations to the dev DB |
| `composer rollback` | Roll the dev DB back to zero |
| `composer seed` | Insert dev seed data (idempotent) |
| `composer test` | Run PHPUnit (auto-creates + migrates `adwol_pia_test`) |
| `composer serve` | PHP built-in server on :8080 |

## Endpoints so far

| Method | Path | Auth | Purpose |
|---|---|---|---|
| GET | `/health` | none | Liveness/readiness (200 / 503) |
| POST | `/api/auth/login` | none (rate-limited) | Email + password → token bundle |
| POST | `/api/auth/refresh` | refresh token (rate-limited) | Rotate tokens (D4 7-day rule) |
| GET | `/api/me` | Bearer access token | Current user's profile + `offline` block |
| POST/DELETE | `/api/me/pin` | Bearer access token | Set / clear the offline unlock PIN (D4) |
| GET/POST | `/api/office/clients` | Bearer + office role | List / create clients |
| GET/PATCH | `/api/office/clients/{uuid}` | Bearer + office role | Read / update a client |
| GET/POST | `/api/office/consignments` | Bearer + office role | List / create consignments |
| GET/PATCH | `/api/office/consignments/{uuid}` | Bearer + office role | Read / update a consignment |
| GET/POST | `/api/office/inspection-requests` | Bearer + office role | List / create inspection requests |
| GET/PATCH | `/api/office/inspection-requests/{uuid}` | Bearer + office role | Read / reschedule a request |
| POST | `/api/office/inspection-requests/{uuid}/transition` | Bearer + office role | Change request status |
| POST | `/api/office/inspection-requests/{uuid}/schedule` | Bearer + office role | Assign an inspector → create the inspection |
| GET | `/api/inspections/assigned` | Bearer + inspector | The inspector's offline working set |
| POST | `/api/inspections/sync` | Bearer + inspector | Batch upload captured inspections (idempotent) |
| POST | `/api/inspections/attachments/{clientUuid}` | Bearer + inspector | Upload a declared photo/document |
| GET | `/api/attachments/{id}` | Bearer (assigned inspector or office) | Download an attachment |
| GET | `/api/office/inspections` | Bearer + office role | Review queue (synced/amended/…) |
| GET | `/api/inspections/{uuid}` | Bearer + office role | Inspection detail + findings + audit trail |
| POST | `/api/inspections/{uuid}/amend` | Bearer + office role | Correct findings / location → `amended` |
| POST | `/api/inspections/{uuid}/finalize` | Bearer + office role | Lock the inspection → `finalized` (D1); auto-generates the CCI (D2) |
| POST | `/api/inspections/{uuid}/reject` | Bearer + office role | Send back to the inspector with a reason |
| POST | `/api/inspections/{uuid}/documents` | Bearer + office role | (Re)generate a document — body `{"type":"CCI"\|"NNCI"}` |
| GET | `/api/inspections/{uuid}/documents` | Bearer + office role | List documents generated for an inspection |
| GET | `/api/documents/{uuid}` | Bearer + office role | Download a generated document (PDF) |
| GET | `/api/compliance` | Bearer + admin role | List the 3-strikes tracking (filters: `alert_only`, `inspector_uuid`, `period_month`) |
| POST | `/api/compliance/evaluate` | Bearer + admin role | (Re)run the compliance evaluator — body `{"month":"YYYY-MM"}`, optional |
| POST | `/api/statutory-returns/generate` | Bearer + admin role | Generate a regulator return — body `{agency, period_start, period_end}` |
| GET | `/api/statutory-returns` | Bearer + admin role | List generated returns |
| POST | `/api/statutory-returns/{uuid}/submit` | Bearer + admin role | Mark a return as manually submitted |
| GET | `/api/statutory-returns/{uuid}/download` | Bearer + admin role | Download the return (CSV) |

Console-only (no JSON API — server-rendered pages under `/console`, admin/super_admin): user management (`/console/users`), a global audit log (`/console/audit-log`), and operational settings (`/console/settings`).

"Office role" = `office_reviewer`, `admin`, or `super_admin` (inspectors get 403).
Token bundle: `access_token` (JWT, 15 min), `refresh_token` (opaque, rotates on
every use, 7-day hard cap from last online login), a `user` summary, and an
`offline` block (`pin_set`, `pin_hash`, `reauth_deadline`, `reauth_days`) the
PWA caches for offline PIN unlock (D4 / §6).
List endpoints take `?page=` / `?per_page=` and resource-specific filters.

## Documents (CCI / NNCI)

Finalizing an inspection (office console or API) automatically generates a
**CCI** (Clean Certificate of Inspection) — or, for a case where the NESS fee
wasn't paid, an office user can generate an **NNCI** instead. Layout and
numbering (`{year}-{5-digit sequence}`) are taken directly from the client's
own real sample documents (see `DEV_NOTES.md`, Phase 6). Every document is
SHA-256 hashed and HMAC-signed (D7) and stored outside the web root; download
it from the inspection's review page in the console, or
`GET /api/documents/{uuid}`. The certificate's header carries the **client's**
name and address with the CCI and NXP numbers (not the agency's logo); box 50,
the NESS fee payable, is filled automatically at **0.5% of FOB** when the office
hasn't recorded a figure (rates live in `App\Documents\Fees`).
Letterhead (company name / address / representative title) is editable at
**`/console/settings`** (admin/super_admin only) — no redeploy needed; `.env`
(`PIA_COMPANY_NAME` etc.) only supplies the fresh-install default.

## Compliance tracking & statutory returns

**Compliance** (`/console/compliance`, admin/super_admin only): tracks
inspectors against a 3-strikes rule — 3 consecutive months with a "missed"
inspection (scheduled but not synced within a grace period, default 24h)
raises an alert. Runs nightly via cron:

```bash
php bin/evaluate-compliance.php               # current month
php bin/evaluate-compliance.php --month=2026-08   # backfill a specific month
```

or on demand from the console / `POST /api/compliance/evaluate`.

**Statutory returns** (`/console/statutory-returns`, admin/super_admin
only): generates a CSV register of documents issued in a date range for
CBN, NEPC, NBS, MOF, or Customs. CBN and NBS match the client's real report
columns; the other three use a generic placeholder register pending a real
sample. Mark a return "submitted" once it's actually been filed.

**CBN invoices** (`/console/invoices`, admin/super_admin only): the monthly
service-fee invoice from the agency to the Central Bank of Nigeria — CCIs issued
in the month grouped by region, fee 0.35% of FOB in naira, laid out like the
client's sample. The page shows exactly what the invoice will say and anything
stopping it (a CCI without an exchange rate, missing bank details); issued
invoices are frozen, signed, numbered `{prefix}/{year}/{NNN}`, and then marked
paid or voided. Fill in the bank account under *Invoice details* first.

**Archive** (`/console/archive`, every office role): every generated file in one
searchable register — certificates for everyone, plus CBN invoices and statutory
returns for admins — filed automatically as it's created, including voided ones.
Filter by CCI/NXP/invoice number, client, type and date; download a selection as
one ZIP (with an index).

## Inspector PWA (`/app`)

Installable offline-first web app for field inspectors, served as static files
from `public/app/` (no build step). Service worker precaches the app shell;
IndexedDB holds the offline queue (§6). Flow: log in online once → pull
assigned inspections → work offline (findings + photos) → "Sync now" (or
auto-sync on reconnect) pushes to `/api/inspections/sync` + uploads photos.
Between online logins the app unlocks with a local PIN, verified against the
cached Argon2id `pin_hash` via `argon2-browser` (WASM). Set a PIN from the
app's Settings screen.

## Office console (browser)

Server-rendered pages at **`/console`** (cookie session + CSRF, office roles
only): login, dashboard, CRUD for clients / NXP records / inspection requests,
assigning an inspector to a request (creates the inspection the app downloads),
and the **inspection review** queue (`/console/inspections`) — amend, finalise,
reject, with the audit trail. A left sidebar (collapsible on mobile) is the
main navigation; light/dark mode is a manual toggle in the top bar. Dev login
from the seeder: `admin@adwol.test` / `ChangeMe!123`.

Admin/super_admin also get **user management** (`/console/users` — create,
edit, reset password, suspend/reactivate; the 4 roles from the brief plus the
read-only Board of Directors role, not a generic permission system), a **global audit log** (`/console/audit-log`),
and **operational settings** (`/console/settings` — company letterhead plus
the compliance grace period, editable without a redeploy).

**`/`** is a landing page pointing at the two separate apps (office console and
the inspector PWA below) — there is no single shared login. (A client portal
existed briefly and was withdrawn at the client's request.)

**NXP numbers** are required and unique on exports (never allowed on imports);
older exports without one are flagged "needs NXP no." until someone completes
them. Old `/console/consignments…` links redirect to `/console/nxp…`.

**Income** (`/console/income`, plus a card on the dashboard): the PIA service fee
of **0.35% of FOB** on every issued CCI, converted to naira at each CCI's own
exchange rate — the same rate and certificates the CBN invoice bills. Switch
period on the dashboard without a reload (this month / last month / quarter /
year / custom), click a month's bar for its report; breakdowns by month, region
and client; invoiced vs paid vs outstanding; CSV download. CCIs without an
exchange rate are listed and left out of the total, never guessed.

**Board of Directors** (`board` role): signs in to the console and sees
everything an admin can view — dashboard with income, income report, CBN
invoices, returns, compliance, audit log, archive (all kinds) and every record —
but changes nothing: `BoardReadOnlyMiddleware` refuses every non-GET request (and
the "new record" forms) for a board user, except their own account and sign-out.

**Bulk import** (`/console/import`, admin) for every record the office types in:
clients, NXP records, inspection requests, trade / banking details, shipment /
NESS details (the quickest way to load exchange rates) and users. Template per
type, preview first, all-or-nothing, same rules as the forms. The two "details"
types update existing records and only change filled-in cells.

**Also in the console:** global search (by NXP number, CCI number, client…), CSV
export on the lists, a calendar view, a "needs attention"
bell, documents attached to clients and NXP records, a
security overview, optional email (self-service password reset + a daily digest —
off until `MAIL_HOST` is set), **two-factor authentication** (authenticator app +
recovery codes; an admin setting can make it mandatory for admins and super
admins) under **My account**, and (super admin) backups — see `DEV_NOTES.md`.

Command-line jobs (cron): `bin/evaluate-compliance.php`, `bin/backup.php`,
`bin/send-digest.php` — see `DEPLOYMENT.md`.

## Status

**All 9 build phases complete.**
- **1** — scaffolding + core schema.
- **2** — authentication (login, JWT + rotating refresh tokens, Argon2id, the
  D4 7-day rule, `/api/auth/*` rate limiting).
- **3a/3b** — office data/API layer + browser console for clients,
  NXP records (then called consignments), inspection requests.
- **4** — offline sync: office scheduling → `inspections` row; the inspector's
  `/assigned` pull; idempotent `/sync` batch; attachment upload/download.
- **5** — amend / finalise / reject workflow (D1) with a before/after
  `audit_log` on every change; office review queue + console screens.
- **6** — CCI/NNCI document generation: mPDF rendering matched field-by-field
  to the client's real sample, `{year}-00001`-style numbering, SHA-256 +
  HMAC signing (D7), auto-fires on finalize; editable company letterhead at
  `/console/settings`.
- **7** — compliance 3-strikes tracking (nightly cron, on-demand evaluate) +
  statutory return generation (CBN/NBS matched to the real sample layouts;
  NEPC/MOF/Customs generic pending a sample) with a submit/download console.
- **8** — offline inspector PWA (`public/app/`): service worker + IndexedDB
  queue, pull/push sync, camera capture, Argon2id offline PIN unlock (D4);
  server side adds `POST/DELETE /api/me/pin` and the `offline` auth block.
- **9** — security pass (a same-origin Content-Security-Policy; rate limiting
  extended to `/console/login`, which had none before; admin-only console
  pages now gated at the route level, not per-action) + deployment prep
  (`DEPLOYMENT.md` runbook, `db/grants.sql` — a least-privilege two-MySQL-
  user split that enforces `audit_log`'s append-only rule at the database
  layer, not just in code).

**Nothing left to build against the brief.** What remains is operational:
confirm the real hosting plan (Q8) and follow `DEPLOYMENT.md`.
See `DEV_NOTES.md` for the full decision/assumption log, including a
post-launch enhancement pass (branding/sidebar redesign, a landing page,
user management, a global audit log, editable operational settings) done
after this phase list was signed off.
