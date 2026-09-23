# DEV_NOTES.md

Running log of decisions, deviations from `PIA_BUILD_BRIEF.md`, and assumptions.
Newest entries at the top of each section.

---

## Status

- **2026-09-19** — **Post-launch enhancement pass complete**: brand/UI
  redesign (sidebar console, logo, light/dark mode) + a landing page +
  user management + a global audit log + editable operational settings.
  See "Post-launch enhancements" near the end of this file for the full
  writeup — kept separate from the numbered phases below since it's work
  requested after the 9-phase brief was already signed off.
- **2026-09-17** — **Phase 9 (security pass + deployment prep) complete.**
  (Phase 8, the offline PWA, was already done — see the entry below; the
  client asked for "8 and 9" together and 8 needed nothing further.) A full
  code audit against brief §7 (OWASP ASVS 4.0 L2) found the codebase already
  solid on injection (every query is prepared; the few interpolated
  fragments are `LIMIT`/`OFFSET` ints from `Pagination` or column names from
  fixed internal whitelists, never request input) and XSS (every template
  value reaches the page through `Renderer::e()` or an equivalent
  `htmlspecialchars()` helper — verified by grep, not just spot-checked).
  Found and fixed two real gaps:
  - **The browser console's login had no rate limiting** — only
    `/api/auth/*` did, per §7's literal wording, even though the console is
    where admin/super_admin accounts sign in. `AuthRateLimitMiddleware` (the
    same class, same buckets-per-IP-per-path) now also guards
    `POST /console/login`. Verified against a real running instance: 10
    wrong-password attempts render the form again, the 11th returns 429.
  - **Admin-only console pages (Settings/Compliance/Returns) were gated
    inside each controller action**, not at the route level — correct today,
    but one missed call away from a hole the next time an admin-only action
    is added. Replaced with `App\Http\Middleware\ConsoleRoleMiddleware`
    (the console equivalent of the API's `RoleMiddleware`), mounted once on
    a route group — every admin-only action now inherits the gate
    automatically. Confirmed both roles still behave identically
    (admin: 200; office_reviewer: redirected + flashed, nav links hidden)
    on the real running app.
  Also: a same-origin **Content-Security-Policy** (`public/.htaccess`) — the
  app loads no external CDN/font/script anywhere (everything, including
  argon2-browser and mPDF's assets, is vendored/self-hosted), so
  `default-src 'self'` costs nothing. Getting there meant removing the two
  remaining inline event handlers in the whole codebase (a `confirm()` on
  the finalize button, and a `Retry` button in the PWA's offline fallback
  page) — a strict `script-src` blocks inline handlers same as inline
  `<script>`. **HSTS is written but commented out** — enabling it before
  HTTPS is confirmed live can lock browsers out; it's a one-line uncomment
  once Q8 is answered. Deployment: `DEPLOYMENT.md` (full runbook) and
  `db/grants.sql` (a two-MySQL-user privilege split — a migration-only user
  with DDL, and the actual runtime app user with no DDL at all and, on
  `audit_log` specifically, no UPDATE/DELETE — enforcing §7's append-only
  requirement at the database layer, not just in `App\Audit\AuditLog`).
  **195 tests still green** (no new automated tests this phase — the two
  fixes are route-level/config-level, verified against the real running app
  the same way the console has been throughout, since native-session HTTP
  testing under PHPUnit remains the documented Phase 3b limitation).
  **Known gap, flagged, not built:** there is still no way to create or
  manage `users` rows except direct SQL — no self-service password reset,
  no admin "add a user" console page. Not requested by the brief's decision
  register or data model, but worth knowing before relying on shared hosting
  with awkward DB access for onboarding real inspectors.
- **2026-09-17** — **Phase 7 (compliance tracking + statutory returns)
  complete.** Two new brief §4 tables: `compliance_tracking` (the 3-strikes
  inspector tracker) and `statutory_returns` (generated CBN/NEPC/NBS/MOF/
  CUSTOMS filings). Q9's "missed window" is resolved (A34, reversible): an
  inspection counts as missed once `scheduled_at + COMPLIANCE_GRACE_HOURS`
  (default 24h) has passed while it's still `scheduled`/`in_progress`; 3
  consecutive months with a miss sets `alert_triggered`. `App\Compliance\
  ComplianceEvaluator` does the counting; `bin/evaluate-compliance.php` is the
  nightly cron entrypoint (brief §8), idempotent, also runnable on demand
  from `/console/compliance` or `POST /api/compliance/evaluate`.
  `App\Returns\StatutoryReturnService` generates CSV registers (D3) — CBN and
  NBS builders match the client's real sample-docs layouts column-for-column;
  NEPC/MOF/CUSTOMS use a generic placeholder register (no sample exists for
  any of the three — Q6 still partially open) rather than invented columns.
  Both areas are gated to admin/super_admin only (`/console/compliance`,
  `/console/statutory-returns`) — a step up in sensitivity from the
  office_reviewer-accessible parts of the console. **195 tests green**
  (168 + 27 new). Full backend + console flow smoke-tested end to end on the
  dev server (evaluate → alert-eligible data → generate a return → download →
  mark submitted; role gate confirmed both ways). **All 7 build phases (plus
  the two Phase 6 follow-ups) are now complete — remaining work is Phase 9
  (security/deployment) plus whatever real regulator layouts / hosting
  details the client still wants to confirm.**
- **2026-09-17** — **Phase 6 follow-up: editable company branding.** Client
  confirmed the real company name — **ADWOL Investments and Services** — now
  the fresh-install default (`PIA_COMPANY_NAME`), replacing the sample's
  "Gojopal Nigeria Limited" placeholder. Per the client's request, the
  letterhead is no longer only an env value: a new `company_settings` table
  (single row) + `/console/settings` (admin/super_admin only — gated in the
  controller, and the nav link itself is hidden from other roles) let an
  admin change the company name/address/representative title at runtime, no
  redeploy. `DocumentService` now reads the letterhead from
  `App\Settings\CompanySettingsRepository::get()` at generation time (falls
  back to the env default until someone saves a change) instead of a
  container-injected static array. **168 tests green** (164 + 4 new in
  `CompanySettingsRepositoryTest`; `DocumentServiceTest` updated to match,
  not added to). Verified manually end-to-end on the dev server: super_admin sees and can
  save Settings; office_reviewer gets redirected and never sees the nav link;
  a freshly generated CCI immediately reflects a saved change. **Proceeding
  to Phase 7.**
- **2026-09-16** — **Phase 6 (document generation) complete.** The client
  dropped real sample documents at `sample-docs/` (a filled CCI, a monthly
  PIA report with CBN/exporter appendices, CBN/NBS/annual-report Excel
  templates, a CBN service-fee invoice) — see the Phase 6 section below for
  what each one settled. Highlights: **Q10 (document numbering) resolved from
  evidence** — `{year}-{5-digit sequence}`, one sequence shared across all
  types, confirmed against the client's own CCI numbers. A second real
  document type was discovered — **NNCI** (issued instead of a CCI when the
  NESS fee is unpaid) — not the CRF/IDR the brief guessed at; the schema now
  supports CCI/NNCI/CRF/IDR but only CCI/NNCI have a renderer. Two new detail
  tables (`consignment_trade_details`, `inspection_shipment_details`) hold the
  CCI's trade-finance and shipping fields the original 4 tables had no room
  for. `mpdf/mpdf` renders the PDF (D6); SHA-256 + HMAC signs it (D7).
  Finalizing an inspection now auto-generates the CCI (D2) — both from the API
  and the console — as a best-effort step that can't undo the finalize itself
  and can be retried. Letterhead defaults to "Gojopal Nigeria Limited" (their
  own sample's letterhead) — **confirm or replace**, see A29. **160 tests
  green.** Phase 7 (compliance + statutory returns) is next — the sample
  reports likely answer most of Q6 too; see the Phase 6 section.
- **2026-09-09** — **Phase 8 (offline PWA) complete.** Phases 6 and 7 are
  deliberately deferred — they need real assets/inputs from the client
  (sample CCI/CRF/IDR + numbering scheme for 6; regulator report layouts for 7),
  and Phase 8 needs almost nothing, so it was brought forward. Two parts:
  - **8a (server, tested):** offline unlock PIN. `users.pin_hash` was already
    in the schema; added `PinService` (Argon2id, re-checks the account password
    before setting a PIN), `OfflineSession` (builds the `offline` block), and
    `POST` / `DELETE /api/me/pin`. Every auth response (`login`, `refresh`,
    `/api/me`) now carries an `offline` block:
    `{ pin_set, pin_hash, reauth_deadline, reauth_days }` — the data the PWA's
    `auth_cache` is built from (brief §6). `reauth_deadline = last_login_at +
    OFFLINE_REAUTH_MAX_DAYS` (D4). New env `PIN_MIN_LENGTH` / `PIN_MAX_LENGTH`.
    **20 targeted tests green** (PinService + OfflineAuthEndpoint + updated
    AuthService).
  - **8b (client, static — `public/app/`):** an installable, offline-first PWA
    for field inspectors. No build step, vanilla ES modules. Service worker
    precaches the app shell (scope `/app/`; it never touches `/api/*`).
    IndexedDB holds the four stores from §6 (`inspections_queue`,
    `attachments_queue`, `reference_cache`, `auth_cache`). Sync engine: pull
    `/assigned` → merge without trampling local edits; push queued inspections
    to `/sync` (idempotent) then upload photo blobs; "Sync now" + auto-sync on
    reconnect + Background Sync (Chrome/Android). Offline unlock verifies the
    PIN against the cached Argon2id `pin_hash` using vendored `argon2-browser`
    (WASM, A7), degrading to "must log in online" if the WASM can't load.
    Views: login, PIN unlock, assigned list, capture form (findings + camera
    photos), settings (set/clear PIN, sync, logout). Verified by a Node
    functional test of the whole offline loop against a stubbed server; the
    in-browser WASM path is a manual device check (see Phase 8 section).
  **Next: Phase 6 (documents) or Phase 7 (compliance/returns) once the client
  sends the assets.**
- **2026-09-09** — **Phase 5 (amend / finalise + audit_log) complete.** New
  table `audit_log` (append-only — `App\Audit\AuditLog` is the only writer and
  only INSERTs). `ReviewService` + `ReviewController`: office review queue
  (`GET /api/office/inspections`), per-inspection detail with findings +
  attachments + audit trail (`GET /api/inspections/{uuid}`), and the D1
  actions `POST /api/inspections/{uuid}/{amend,finalize,reject}` — each writes
  a before/after snapshot to `audit_log`. `amend` replaces the findings set +
  the two location fields → `amended`; `finalize` locks the row (`finalized`,
  stamps `finalized_by`/`_at`, Phase 6 doc-gen hook); `reject` needs a reason,
  bounces to `rejected`, and a later inspector re-sync brings it back to
  `synced` (A21). Console: `/console/inspections` review queue + detail page
  (amend form, finalise/reject, audit trail). **~130 tests green.** Q13/Q14
  decided as below. **Next: Phase 6 (document generation).**
- **2026-09-09** — **Phase 4 (offline sync) complete.** New tables
  `inspection_findings`, `inspection_attachments`, `sync_log`. Q12 resolved as
  A18 (office schedules → creates the `inspections` row). New surface:
  `POST /api/office/inspection-requests/{uuid}/schedule` (assign inspector +
  time), `GET /api/inspections/assigned` (offline working set + consignment
  context + free-form checklist template), `POST /api/inspections/sync`
  (batch, **idempotent on inspection uuid**, replaces findings, upserts
  attachment stubs, writes `sync_log`, rejects locked/`finalized` records),
  `POST /api/inspections/attachments/{clientUuid}` (blob upload — checksum +
  type + size checked), `GET /api/attachments/{id}` (assigned inspector or
  office). `App\Inspections\*` services/repos + `AttachmentStorage`
  (files outside web root). **116 tests green.** Findings validation stays
  free-form pending Q7. **Next: Phase 5 (amend / finalise + audit_log).**
- **2026-09-08** — **Phase 3b (office browser console) complete.**
  Server-rendered pages under `/console`: cookie session + CSRF (Q4),
  office-role login gate, dashboard, and full CRUD forms for clients,
  consignments and inspection requests (incl. IR status transitions /
  reschedule), rendering on top of the Phase 3a services with
  validation-error re-render + POST-redirect-GET flashes. New: `Renderer`
  (tiny PHP-template engine), `SessionMiddleware`, `CsrfMiddleware`,
  `ConsoleAuth`, `ConsoleAuthMiddleware`, `templates/**`,
  `public/assets/console.css`. Verified end-to-end via dev server; 18 isolated
  tests green. **Phase 3 done — next: Phase 4 (offline sync). Needs Q7 (a draft
  checklist).**
- **2026-09-08** — **Phase 3a (office API) complete.** Tested data + API layer
  for clients, consignments and inspection requests: repositories (prepared
  statements, JOINs for uuid exposure, pagination + filters), services
  (validation, `form_nxp_number` export-only rule, `notice_deadline` = A14,
  request status state machine A16), controllers under `/api/office/*` behind
  JWT + `RoleMiddleware` (office roles only). New: `RoleMiddleware`, typed
  `Input` reader, `Pagination`, `CurrentUser`, `NotFoundException`. No new
  tables. Config `NOTICE_WINDOW_HOURS`. **Next: Phase 3b — the server-rendered
  browser console on top of these services (session + CSRF wiring).**
- **2026-09-08** — **Phase 2 (auth) complete.** JWT library = `lcobucci/jwt`
  (Q11). Built: Argon2id `PasswordHasher` (password + PIN, transparent rehash);
  HS256 `AccessTokenService` (15 min, iss/exp/sig strictly checked, no DB hit);
  `RefreshTokenService` — opaque token, SHA-256 at rest, rotation, reuse ⇒
  whole family revoked, absolute 7-day cap from last online login (D4);
  DB-backed `RateLimiter` (fixed window) on `/api/auth/*`; `JwtAuthMiddleware`;
  `POST /api/auth/login`, `POST /api/auth/refresh`, `GET /api/me`; console
  `Csrf` + `SessionStore` primitives (mounted in Phase 3). Migrations:
  `refresh_tokens`, `rate_limits`. Next: Phase 3 (office CRUD) — needs Q9.
- **2026-09-08** — **Phase 1 complete.** Scaffolding, config loader, PDO layer,
  Slim HTTP skeleton with JSON error envelope + request-id middleware, health
  endpoint, Phinx wired, migrations for the 5 core tables, dev seeder, PHPUnit
  harness (15 tests green). Client said "proceed with recommendations" — Q1–Q5
  decided as proposed (see below). Awaiting: Q8 (hosting) before Phase 9; Q9
  before Phase 3 deadline logic. Next: Phase 2 (auth).
- **2026-09-08** — Brief read in full. Phase plan + Phase 1 breakdown proposed.

---

## Decisions locked (client: "proceed with recommendations that work best")

- **Q1 → Slim 4 + PHP-DI.** Micro-framework, PSR-7/15, no build step needed for
  shared hosting. Container definitions kept explicit for infrastructure.
- **Q2 → Phinx** (`robmorgan/phinx ^0.16`). Standalone, reads DB creds from the
  same `.env` the app uses (see `phinx.php`).
- **Q3 → mPDF** for Phase 6 (better with table/form-heavy statutory layouts than
  Dompdf). Not yet installed.
- **Q4 → split auth.** Cookie session + CSRF for the browser console; JWT
  (Authorization header) for the PWA/API. Wired in Phase 2.
- **Q5 → HS256 JWT**, secret in `JWT_SECRET`. Access TTL 900s (15 min), refresh
  TTL 604800s (7 days, aligns with D4). Refresh-token rotation + reuse
  detection. Argon2id params: 64 MB / 4 / 1 (env-tunable, conservative for
  shared hosting).

---

## Open questions raised with the client (blocking or shaping Phase 1)

| # | Question | Why it matters | Status |
|---|----------|----------------|--------|
| Q1 | HTTP layer (Slim 4 vs vanilla) | project skeleton | **DECIDED: Slim 4 + PHP-DI** |
| Q2 | Migration tooling | Phase 1 deliverable | **DECIDED: Phinx** |
| Q3 | PDF library: Dompdf or mPDF? (D6 allows either) | Phase 6 | **DECIDED: mPDF** (install in Phase 6) |
| Q4 | Console vs API auth model | Phase 2/3 shape | **DECIDED: cookie+CSRF console, JWT API** |
| Q5 | JWT strategy / TTLs | Phase 2 | **DECIDED: HS256, 15 min / 7 day, rotating refresh** |
| Q6 | §9: exact regulator report layouts/columns for CBN, NEPC, NBS, MOF, Customs (D3) | Blocked Phase 7's build | **PARTIALLY DECIDED (Phase 7).** CBN and NBS builders ship matched column-for-column to the real samples (A36). NEPC/MOF/Customs still have no sample — `GenericReturnBuilder` covers them with a core register until you send one (same swap-in pattern as CBN/NBS: a new `*ReturnBuilder` class). |
| Q7 | §9: inspection checklist content, per product category | Drives `inspection_findings` validation | **PARTIALLY UNBLOCKED** — Phase 4 sync accepts free-form findings (item + pass/fail/flag + notes); `/assigned` returns an empty `checklist` template (`version: freeform-1`). Real per-category checklists + required-item validation land when you send drafts. |
| Q12 | Who creates the `inspections` row, and what `/api/inspections/sync` does with it. §3 says the uuid is "client-generated" and the server "only accepts or rejects a UUID it already has"; §4 puts no inspector/schedule fields on `inspection_request`. | Phase 4 sync semantics | **DECIDED (A18): office creates it.** The office "schedule" step assigns an inspector + time and creates the `inspections` row (a v4 uuid, not a DB sequence). The field device pulls it via `/assigned`, works offline, and syncs *updates* — the sync never inserts an inspection. Reversible if you want device-created inspections. |
| Q13 | Who can reject an inspection, and is `rejected` terminal? | Phase 5 | **DECIDED (default, reversible): office roles reject with a required reason; `rejected` is NOT terminal.** It goes back to the inspector, who re-syncs the correction (→ `synced`) for another review (A21). Say if you'd rather it be terminal or require a supervisor. |
| Q14 | Is "amend" field-level or a whole-record replace? | Phase 5 | **DECIDED (default, reversible): field-level.** The reviewer may change `location_type` / `location_detail` and replaces the findings set; attachments (evidence) are not amendable. Every amend writes a before/after `audit_log` snapshot. |
| Q8 | §8: hosting plan confirmation — Composer/SSH access, cron availability, PHP extensions (PDO MySQL, GD/Imagick, mbstring, intl), storage quota for photo attachments, HTTPS/TLS live, ability to create a 2nd MySQL user | Confirms deployment assumptions | **STILL OPEN** — `DEPLOYMENT.md` + `db/grants.sql` are ready and written to be actioned as soon as this is answered; nothing else is blocked on it. |
| Q9 | Definition of a "missed window" for `compliance_tracking`; the policy window that computes `notice_deadline` | Phase 3 (deadline computation) and Phase 7 (3-strikes) | **DECIDED (default, reversible).** `notice_deadline` uses A14 (`requested_at` + 72h, configurable). "Missed window" uses A34 (`scheduled_at` + `COMPLIANCE_GRACE_HOURS`, default 24h, still un-synced) — both are one env change away from the real policy once you confirm it. |
| Q10 | `document_number` format/scheme per type (CCI/CRF/IDR) — prefix, sequence reset cadence (yearly?), width | Phase 6 | **DECIDED, from evidence (sample-docs/2021-00002.pdf + the Oct 2021 report appendix):** `{year}-{5-digit sequence}` (e.g. "2021-00002"), one sequence shared across the whole year and across document types — no monthly reset, no per-type split. Implemented in `App\Documents\DocumentNumberAllocator`; digit width is configurable if ever needed. |
| Q11 | JWT library (php-jwt blocked by advisory PKSA-y2cr-5h3j-g3ys) | Phase 2 | **DECIDED: `lcobucci/jwt ^5.3`** — maintained, secure defaults, no advisories |

## Assumptions (used where the brief is silent; correct me if wrong)

- **A1** — Local dev on the existing Laragon stack (PHP 8.3 binary present on this
  machine); target runtime PHP 8.1+ per D5, so code stays 8.1-compatible.
- **A2** — Tests with PHPUnit. Each phase ships with its own test suite.
- **A3** — App code lives outside the web root; only a thin `public/` (front
  controller + PWA assets) is exposed. On cPanel this maps to `public_html/`
  with the rest one level up. Confirmed at Phase 9 against real hosting layout.
- **A4** — A `refresh_tokens` table (not in §4) is needed for D4 / refresh-token
  rotation. Deviation logged under "Deviations". Added in Phase 2.
- **A5** — Rate limiting (§7) is DB-backed (no Redis on shared hosting): a small
  `rate_limits` table keyed by IP + route bucket. Added in Phase 2.
- **A6** — `document_number` sequences use a dedicated counter table with
  `SELECT ... FOR UPDATE` (or atomic `INSERT`) to stay safe under concurrency.
- **A7** — Offline PIN verification in the PWA (D4) needs an Argon2id
  implementation in the browser — `argon2-browser` (WASM). If that footprint is
  unacceptable we revisit (e.g. PBKDF2 for the PIN only). Decided in Phase 8.
- **A8** — `inspections.created_at` = server insert time; the field capture
  moment is `started_at` (client clock). Sync trusts `started_at`/`captured_at`
  as reported but records server receipt time separately.
- **A9** — Attachments are stored on the filesystem outside the web root and
  served through an authenticated PHP endpoint (`GET /api/attachments/{id}` —
  not in §5, minor addition). Logged under "Deviations".
- **A10** — `zone` is a free-text string as specified in §4 (no `zones` lookup
  table) until there's a reason to normalize it.
- **A11** — `created_at` / `updated_at` are MySQL `TIMESTAMP` columns
  (`DEFAULT CURRENT_TIMESTAMP` / `ON UPDATE CURRENT_TIMESTAMP`), via Phinx's
  `addTimestamps()`. §4 asks for that auto-update behaviour without naming a
  type; `TIMESTAMP` is the idiom that provides it. Every app DB connection
  runs `SET time_zone = '+00:00'`, so stored/returned values are UTC —
  matching §4. Domain datetime columns (`scheduled_at`, `requested_at`,
  `notice_deadline`, `started_at`, `synced_at`, `finalized_at`, …) stay
  `DATETIME` exactly as specified. Note: `TIMESTAMP` has the 2038 limit; a
  later migration can widen if the system is expected to run past then.
- **A12** — All core foreign keys are `ON DELETE RESTRICT` / `ON UPDATE NO
  ACTION` (no cascades). Referenced-row deletion must be handled explicitly by
  the app. Revisit when a soft-delete strategy is chosen.
- **A13** — Local dev DB is Laragon's bundled MySQL 8.4.3, started manually
  (`mysqld --datadir=C:\laragon\data\mysql-8.4`). The Windows `MySQL80`
  service needs elevation not available in this environment. Dev-environment
  detail only; no bearing on the deployed system.
- **A14** (Q9, pending client confirmation) — `notice_deadline = requested_at +
  NOTICE_WINDOW_HOURS` (default **72 calendar hours**). Calendar hours, not
  working days, because working-day math needs a public-holiday calendar we
  don't have. One env change when the real policy is known
  (`config.workflow.notice_window_hours`).
- **A15** — Office-created records (`clients`, `consignments`,
  `inspection_requests`) get a **server-generated** UUIDv4. Only `inspections`
  use a client-generated uuid (brief §3).
- **A16** — Office-side `inspection_requests.status` lifecycle:
  `pending → scheduled → completed`, plus `cancelled` from `pending` or
  `scheduled`; `completed`/`cancelled` are terminal. This is the office
  request lifecycle and is separate from `inspections.status` (D1's field
  workflow).
- **A17** — `DECIMAL` values (`quantity`, `declared_value`) are returned in
  JSON as **strings** and written via `number_format($v, 2)`, so money and
  quantity precision is never routed through a float.
- **A18** (Q12) — **The office creates the `inspections` row.** A new "schedule"
  action (`POST /api/office/inspection-requests/{uuid}/schedule`) assigns an
  inspector + `scheduled_at` + location and inserts the inspection with a
  v4 uuid. `GET /api/inspections/assigned` returns those rows;
  `POST /api/inspections/sync` only ever *updates* a row it already has
  (idempotent on uuid, §3) — it never inserts an inspection. The uuid is
  "client-generated" in the sense of app-generated-not-a-DB-sequence; the
  office console is also a client. Reversible to a device-creates model.
- **A19** — On sync, the **finding set is replaced wholesale** for the
  inspection (delete + reinsert in one transaction) — last-write-wins within
  the D1 amend window. Attachments are upserted by `client_uuid` (never
  duplicated). A `finalized`/`rejected` inspection is locked: a late field
  push is rejected as a `conflict`, logged, and not applied.
- **A20** — Attachment binaries: allowed types **jpeg / png / webp / heic /
  pdf** (MIME sniffed from bytes, not trusted from the request); size cap
  `ATTACHMENT_MAX_BYTES` (10 MB default); SHA-256 declared by the client and
  verified against the bytes on upload (§4). Stored under
  `storage/attachments/<client-uuid>.<ext>` outside the web root; served only
  via `GET /api/attachments/{id}` to the assigned inspector or an office role.
- **A21** — `rejected` is a **syncable** state: after the office rejects an
  inspection the device can re-sync the correction, which moves it to
  `synced` for another review round. `finalized` remains fully locked.
- **A22** — `audit_log` wiring in Phase 5 covers the D1 workflow
  (`inspection.amend` / `.finalize` / `.reject`). Broader coverage (office
  CRUD on clients/consignments, scheduling) can be layered on the same
  `App\Audit\AuditLog` service later — not done yet.
- **A23** (Phase 8) — Offline PIN policy: **4–8 digits** (env-tunable
  `PIN_MIN_LENGTH`/`PIN_MAX_LENGTH`), rejecting one repeated digit and simple
  ascending/descending runs (incl. wrap, e.g. `9012`). Setting or replacing a
  PIN requires re-entering the account password — a stolen access token alone
  can't plant a PIN. Hashed with the same Argon2id params as the password
  (§7). Reversible / tunable in config.
- **A24** (Phase 8, reaffirms §6) — the server returns the Argon2id `pin_hash`
  to the client in the `offline` block so the PWA can verify the PIN with no
  network. This is exactly what §6's `auth_cache` ("`pin_hash` for local
  offline verification (never the plaintext PIN)") calls for. It only travels
  over an authenticated Bearer channel to the account holder; Argon2id makes an
  offline guessing attack on a stolen device expensive. See DEV-11.
- **A25** (Phase 8) — the service worker's scope is `/app/` and it **never
  caches `/api/*`** (those requests are outside its scope anyway). All offline
  data lives in IndexedDB, managed by the app; the HTTP cache only holds the
  static app shell. This avoids stale-auth / stale-data classes of bug.
- **A26** (Phase 8) — the PWA uses **hash-based routing** (`#/inspection/<uuid>`)
  so a deep link is always served by the one precached `index.html` with no
  server rewrite rule for `/app/*`.
- **A27** (Phase 8) — app icons (`public/app/icons/*.svg`) are **placeholders**
  — a clean clipboard-check mark in the brand colour, shipped as SVG (`sizes:
  "any"`, `any` + `maskable`). A proper PNG raster set (192 / 512 / 180
  maskable) is a small asset still owed by the client; the manifest just needs
  the `src`s swapped. iOS in particular wants a PNG `apple-touch-icon`.
- **A28** (Phase 8) — `argon2-browser` v1.18.0 (MIT) is vendored at
  `public/app/vendor/argon2/` (`argon2-bundled.min.js` + `argon2.wasm`). The
  offline-PIN feature **degrades gracefully**: if the WASM can't load
  (blocked, old browser), the app reports offline unlock unavailable and
  requires an online login instead of breaking. Confirms A7's primary choice;
  PBKDF2 fallback not needed unless a target device fails the manual check.
- **A29** — **RESOLVED 2026-09-17.** The letterhead printed on every
  generated document started as env-only, defaulted to "Gojopal Nigeria
  Limited" from the sample CCI pending confirmation. The client confirmed
  the real name — **ADWOL Investments and Services** — now the fresh-install
  default, and asked for it to be editable without a redeploy: it now lives
  in `company_settings` (single row), editable at `/console/settings`
  (admin/super_admin only), with the env value only as the pre-first-save
  fallback. No logo image file was supplied — the PDF header is text-only for
  now (letterhead artwork is the outstanding "asset" item for this phase,
  alongside the PWA's app icon —
  A27).
- **A30** (Phase 6) — `documents.type` is widened to
  `ENUM('CCI','NNCI','CRF','IDR')`. The brief's schema (§4) named CCI/CRF/IDR,
  but no CRF or IDR sample exists anywhere in `sample-docs/`; instead the
  client's own October 2021 report (§5.3, "CCI & NNCI Issuance") evidences a
  real second document type — **NNCI** ("Non-Negotiable Certificate of
  Inspection"), issued instead of a CCI when the exporter hasn't paid the
  NESS fee. `App\Documents\DocumentService` renders CCI and NNCI (same
  template, different title) and rejects CRF/IDR with a clear
  `unsupported_document_type` 422 rather than inventing a layout for either.
- **A31** (Phase 6) — two new 1:1 detail tables hold CCI fields the original
  §4 tables have no room for (DEV-12): `consignment_trade_details` (importer,
  both parties' banks, invoice, basis of sale, payment method, freight/
  insurance — known at intake, entered on the consignment form) and
  `inspection_shipment_details` (carrier/vessel, containers, weights, the
  NESS fee paper trail — only known after the physical inspection, entered by
  the office during review). Every column is nullable: a CCI can be issued
  with several fields blank, exactly as the real sample is (freight/insurance/
  exchange-rate are blank on it).
- **A32** (Phase 6) — finalizing an inspection **auto-generates the CCI**
  (D2), from both the API (`ReviewController::finalize`) and the console
  (`ReviewConsoleController::finalize`). Generation is a best-effort step
  *after* the finalize state change has already committed: a PDF/storage
  fault surfaces as `document: null` + `document_generation_error` (API) or a
  flash message (console), never as a failed/rolled-back finalize. Either
  surface exposes a manual (re)generate action
  (`POST /api/inspections/{uuid}/documents`, or the console's "Generate CCI /
  NNCI" buttons) for retry, or to issue an NNCI instead. Reissuing burns a
  fresh document number each time (A33) — there's no void-and-reissue flow
  yet (a Phase 9 follow-up if the client needs it).
- **A33** (Phase 6) — each `generateForInspection()` call allocates a brand
  new, independently-numbered document — including retries after a failure.
  A number that was allocated but never actually issued (e.g. PDF render
  threw right after allocation) is simply skipped, the way a spoiled paper
  form would be. Matches real-world numbered-certificate practice; no gap-
  filling logic was added.
- **A34** (Phase 7, Q9, pending client confirmation) — "missed window":
  an inspection counts as missed once `scheduled_at +
  COMPLIANCE_GRACE_HOURS` (default 24h) has passed while it's still
  `scheduled`/`in_progress` — the inspector never got it to `synced` (or
  further). Completed-but-late doesn't count as missed; an inspection later
  `rejected` by the office for quality reasons also doesn't count here
  (attendance, not review outcome, is what this table tracks — a `rejected`
  row already passed through `synced` once). `consecutive_miss_count`
  carries a streak across months, incrementing only when the immediately
  preceding month was also evaluated and also had a miss; a clean month
  resets it to 0. `alert_triggered` flips on at
  `COMPLIANCE_STRIKE_THRESHOLD` (default 3, both env-tunable). One env
  change if the real policy differs.
- **A35** (Phase 7, D3) — every statutory return is generated as **CSV**,
  never PDF, even for `format` values the schema allows either way for.
  Rationale: the real CBN/NBS reports in `sample-docs/` are Excel workbooks
  with pivot tables and charts alongside the raw register — reliably
  reproducing the pivots/charts isn't realistic without a real spreadsheet-
  writer library and a firm spec for which pivots matter; the raw register
  (which IS what gets submitted, per the report text: "X Hard Copies + 1
  Electronic Copy") is what these builders produce, and CSV opens cleanly in
  Excel. `format` stays an enum with `pdf` still legal in the schema in case
  a future agency return is better served as one.
- **A36** (Phase 7, Q6, partially open) — CBN and NBS have a real builder
  matched column-for-column to the client's own samples (some columns are
  best-available proxies where the system doesn't distinguish two real-world
  concepts it treats as one — e.g. "NXP Issuing Bank" vs "Designated Bank"
  both read from the single `exporter_bank_name` field — documented in the
  builder's own doc-block). NEPC/MOF/CUSTOMS have no sample anywhere and use
  `GenericReturnBuilder` — every CCI field, no invented agency-specific
  columns — until a real layout is confirmed.
- **A37** (Phase 9) — the console login's new rate limit shares
  `AUTH_RATE_LIMIT_MAX`/`AUTH_RATE_LIMIT_WINDOW` with the API (same
  `RateLimiter`, a separate bucket keyed by path so `/console/login` and
  `/api/auth/login` don't share a counter). One env change if the console
  should have a different threshold than the API.
- **A38** (Phase 9) — the DB-grants two-user split (`db/grants.sql`) assumes
  the hosting plan allows creating more than one MySQL user per database.
  Common on cPanel but not universal on the cheapest shared-hosting tiers —
  the script documents the single-user fallback inline. Confirming this is
  part of Q8.

## Deviations from the brief

- **DEV-1** — Adding `refresh_tokens` table (see A4). Rationale: D4's 7-day rule
  and safe refresh-token rotation need server-side token state; §4 has no such
  table.
- **DEV-2** — Adding `rate_limits` table (see A5). Rationale: §7 mandates rate
  limiting on `/api/auth/*`; shared hosting has no Redis/memcached.
- **DEV-3** — Adding `GET /api/attachments/{id}` (see A9). Rationale: §5 covers
  document fetch but not inspection attachments, which still need
  authenticated retrieval.
- **DEV-4** — Adding a `document_sequences` counter table (see A6). Rationale:
  `documents.document_number` is UNIQUE and human-facing; needs collision-safe
  allocation. **Built in Phase 6** as `App\Documents\DocumentNumberAllocator`
  — `year` is the table's own primary key (no separate surrogate id: an
  earlier attempt that added one raced against the
  `LAST_INSERT_ID(...)`-based atomic-increment idiom and allocated the wrong
  number on the first document of a year — caught by a manual end-to-end
  test before shipping, fixed in migration `20260916100006`).
- **DEV-5** — `firebase/php-jwt` NOT added in Phase 1 (was in the proposed
  composer.json). Composer's `audit`/`block-insecure` refused it over advisory
  PKSA-y2cr-5h3j-g3ys (v6.10.0–6.11.1). **Resolved in Phase 2:** chose
  `lcobucci/jwt ^5.3` instead (Q11) — actively maintained, secure by default
  (rejects `alg:none`, strict time checks), no advisories. A tiny in-house
  `App\Support\SystemClock` (PSR-20) is used for the JWT time constraints so
  `lcobucci/clock` isn't pulled in for one class.
- **DEV-6** — Added `GET /api/me` (not in the §5 table). Small helper so the
  PWA/console can confirm a token is still valid and read the current profile
  without decoding the JWT client-side. Behind `JwtAuthMiddleware`.
- **DEV-7** — Added `X-Device-Id` (optional request header) and `X-Request-Id`
  (already in Phase 1). `X-Device-Id` is recorded on refresh-token rows for
  `sync_log` correlation later; ignored if absent or malformed.
- **DEV-8** — Added an `/api/office/*` endpoint group (clients, consignments,
  inspection-requests: list/create/show + `PATCH` update, plus
  `POST …/inspection-requests/{uuid}/transition`). Not in the §5 table — that
  table is the PWA/sync contract; the office intake still needs a server API,
  and the browser console (Phase 3b) renders on top of it. Whole group is
  behind `JwtAuthMiddleware` + `RoleMiddleware(['office_reviewer','admin',
  'super_admin'])` — inspectors get 403. All identifiers in payloads and
  responses are uuids; internal `id` never leaves the service layer.
- **DEV-9** — Phase 4 additions on top of §4/§5:
  - Extra columns on `inspection_attachments`: `client_uuid` (unique — the
    blob's own idempotency key; §4 gives findings/attachments no uuid but §3's
    whole model is uuid-keyed), `original_name`, `mime_type`, `byte_size`
    (serving metadata), and `file_path` made **nullable** (the stub row is
    created at inspection-sync time; the binary arrives later).
  - Extra column on `sync_log`: `detail` (a short error/skip reason for the
    client, alongside `conflict_resolution`).
  - New endpoints: `GET /api/inspections/assigned`, `POST /api/inspections/sync`
    (both in §5), plus `POST /api/inspections/attachments/{clientUuid}` (upload)
    and `POST /api/office/inspection-requests/{uuid}/schedule` (A18) which are
    not in the §5 table but are needed for the sync model.
- **DEV-10** — Phase 5: `POST /api/inspections/{uuid}/reject` and
  `GET /api/office/inspections` (review queue) added — not in the §5 table, but
  the `rejected` status in §4 implies a reject action and the office needs a
  list view. `GET /api/inspections/{uuid}` (office detail) also added. `amend`
  and `finalize` are exactly the §5 paths. All office-role gated.
- **DEV-11** — Phase 8: `POST /api/me/pin` and `DELETE /api/me/pin` added (not
  in the §5 table) to set/clear the offline unlock PIN (D4), and every auth
  response (`login`, `refresh`, `/api/me`) gained an additive `offline` block
  (`pin_set`, `pin_hash`, `reauth_deadline`, `reauth_days`) — the exact data
  §6's `auth_cache` needs. No §4 column or existing §5 endpoint changed; the
  `user` block in the login bundle is untouched.
- **DEV-12** — Phase 6: two new tables not in §4 —
  `consignment_trade_details` and `inspection_shipment_details` — hold the
  CCI's trade-finance / shipping / NESS fields (see A31). `documents.type`
  widened to include `NNCI` (see A30). Console forms: an optional "Trade &
  banking details" section on the consignment edit page, and an optional
  "Shipment & NESS details" section on the inspection review page.
- **DEV-13** — Phase 6: `POST /api/inspections/{uuid}/documents` (generate)
  and `GET /api/inspections/{uuid}/documents` (list) added — not in the §5
  table, which only specifies the finalize-triggers-generation flow (D2) and
  the fetch route. `GET /api/documents/{uuid}` is exactly the §5 path. All
  three are office-role gated, same sensitivity as the rest of the office
  surface (documents carry trade-finance data).
- **DEV-14** — Phase 6 follow-up: a `company_settings` table (not in §4) —
  the client asked for the document letterhead to be editable at runtime
  instead of only via env (A29). `/console/settings` (admin/super_admin
  only) is new, not in the brief's console scope, but is the natural home
  for this and future settings.
- **DEV-15** — Phase 7: `POST /api/compliance/evaluate`, `GET
  /api/compliance`, `GET /api/statutory-returns`, and `POST
  /api/statutory-returns/{uuid}/submit` added — not in the §5 table, which
  only names `POST /api/statutory-returns/generate` (present, exactly as
  specified). A list endpoint and a way to record §4's `submitted_at`/
  `submitted_by` ("manually confirmed") are both needed for the feature to
  be usable; evaluate is the on-demand counterpart to §8's nightly cron.
  `/console/compliance` and `/console/statutory-returns` are the matching
  browser pages. All six — API and console — are gated to admin/super_admin
  only, a step up from the office_reviewer-accessible parts of the console
  (inspector performance data and regulator filings are more sensitive than
  routine office work).
- **DEV-16** — Phase 9: `AuthRateLimitMiddleware` (§7's "rate limiting on
  `/api/auth/*`") now also guards `POST /console/login` (A37) — the brief's
  wording names only the API path, but the console login is equally
  sensitive and had no protection at all before this. Additive: the API
  routes' behaviour is unchanged.

_(All deviations are additive — no §4 column or §5 endpoint is being changed or
removed.)_

---

## Phase 1 — what was built & how it was verified

**Built:** `composer.json` (platform pinned to PHP 8.1 so deps stay
8.1-safe); `.env.example` / `.env.testing.example` + `Env` loader that fails
fast on missing required keys; `config/settings.php` as the single env
read-point; `Database` PDO factory (ERRMODE_EXCEPTION, real prepares, UTC
session, MySQL-only guard); `LoggerFactory` (Monolog → `storage/logs/app.log`,
per-request uid); Slim app via `AppFactory` + PHP-DI `ContainerFactory`;
`RequestIdMiddleware` (in/out `X-Request-Id`); `JsonErrorHandler` (uniform
`{error:{code,message,request_id}}` envelope, debug block only when
`APP_DEBUG`); `HealthController` (`GET /health`); `phinx.php`; migrations
`20260908120001`–`…005` for users / clients / consignments /
inspection_requests / inspections; `DevSeeder`; PHPUnit harness with a
bootstrap that auto-creates + migrates `adwol_pia_test`.

**Verification results (2026-09-08):**
- `composer install` — clean (67 packages).
- `phinx migrate -e development` — all 5 tables created; `SHOW CREATE TABLE`
  spot-checked: InnoDB, utf8mb4, `inspections.status` 6-value enum,
  `inspections_status_scheduled_idx (status, scheduled_at)`, unique `uuid` per
  table, `users.email` unique, all declared FKs present.
- `phinx rollback -t 0` — reverses to only `phinx_migrations`, re-migrates
  clean.
- `phinx seed:run` — creates `admin@adwol.test` (Argon2id) + sample client;
  idempotent on re-run.
- `php -S … -t public` — `GET /` 200; `GET /health` 200 with
  `checks.database.status=ok`; `GET /nope` 404 `not_found` w/ request_id;
  `POST /health` 405 `method_not_allowed` w/ request_id; no 5xx in `app.log`.
- `phpunit` — 15 tests / 76 assertions green (DB layer strictness, schema
  shape, health + error envelope, request-id passthrough).

**Known follow-ups for later phases:** `.htaccess` HSTS deferred to Phase 9
(needs real TLS host); container compilation off (fine at this size);
`document_sequences` / `refresh_tokens` / `rate_limits` tables come with the
phases that use them.

---

## Phase 2 — what was built & how it was verified

**Built:**
- `App\Support\ApiException` — base for "expected" errors that render as a
  clean JSON envelope (status + machine `code` + optional `details`/headers)
  instead of a 500. `JsonErrorHandler` now renders these directly.
- `App\Support\SystemClock` — tiny PSR-20 UTC clock for the JWT time checks.
- `PasswordHasher` (Argon2id, config-driven cost, `needsRehash`, empty-hash
  is a safe `false` with comparable timing).
- `AccessTokenService` (lcobucci/jwt, HS256): `issue()` sets iss/iat/nbf/exp/
  jti/sub + role + zone; `verify()` asserts signature, issuer and `StrictValidAt`
  (all of iat/nbf/exp present, no leeway) and throws `InvalidTokenException`.
  No DB hit — 15-min lifetime is the revocation window.
- `RefreshTokenService`: opaque base64url token, only SHA-256 stored; rotation
  on every use; presenting an already-used token revokes the whole `family`;
  a family's `expires_at` is hard-capped at `login_at + OFFLINE_REAUTH_MAX_DAYS`
  so refresh can't extend a session past the D4 7-day limit → `ReauthRequiredException`.
  Concurrent-rotation safe via a conditional `UPDATE ... WHERE used_at IS NULL`.
- `RateLimiter`: fixed-window counter in `rate_limits`, race-safe
  `INSERT ... ON DUPLICATE KEY UPDATE`; `enforce()` throws 429 + `Retry-After`.
- `UserRepository` (prepared statements only): find by email/id/uuid, update
  `last_login_at`, update `password_hash`.
- `AuthService`: login (dummy-verify on unknown email for timing parity,
  transparent rehash, `last_login_at` stamp, suspended-account refusal) and
  refresh (rotate → reload user → re-issue access; suspended ⇒ revoke all).
- HTTP: `JwtAuthMiddleware` (`Authorization: Bearer`, attaches
  `AuthenticatedUser`), `AuthRateLimitMiddleware` (on the `/api/auth` group),
  `LoginController`, `RefreshController`, `MeController`, `Input` (typed JSON
  body reader → 422), `ClientContext` (REMOTE_ADDR only; XFF not trusted).
- Console primitives for Phase 3: `SessionStore` (array-or-$_SESSION bag),
  `Csrf` (synchroniser token, `hash_equals`). Not mounted yet — the console
  route group is Phase 3.
- Migrations `20260908130001` `refresh_tokens`, `20260908130002` `rate_limits`.
- Config: `JWT_ISSUER` added to env files + `settings.security.jwt_issuer`.

**Note on a MySQL constraint hit during the build:** with real prepared
statements (`ATTR_EMULATE_PREPARES = false`, per §7) MySQL does **not** allow a
named placeholder to appear twice in one statement. Row-audit columns
(`created_at`/`updated_at`) therefore use `UTC_TIMESTAMP()` in SQL rather than
a repeated `:now` bind. Also: each auth exception is its own file (PSR-4
requires one class per file) — an earlier single `AuthExceptions.php` failed to
autoload.

**Verification results (2026-09-08):**
- `phpunit` — **46 tests / 146 assertions green** (15 Phase 1 + 31 Phase 2:
  PasswordHasher, AccessTokenService round-trip + tamper/issuer/expiry/secret
  rejection, RefreshTokenService rotation + reuse-revokes-family + 7-day cap +
  raw-token-never-stored, RateLimiter window behaviour, AuthService rules,
  full HTTP auth flow incl. rate-limit 429).
- `composer validate` clean; `composer audit` — no advisories.
- Manual smoke (dev server, dev DB): `login` → full bundle (`super_admin`);
  `GET /api/me` with the access token → profile incl. freshly stamped
  `last_login_at`; `login` bad password → 401 `invalid_credentials`;
  `/api/me` no token → 401 `invalid_token`; `refresh` → new token, differs
  from old; replay old refresh → 401 `invalid_token` "reuse detected; session
  revoked"; 11th `/api/auth/login` in the window → 429.

**Known follow-ups:** email is lower-cased at the login boundary — the future
sign-up/user-admin path (Phase 3+) must also store emails lower-cased.
`SessionMiddleware` + `CsrfMiddleware` wiring lands with the console in Phase 3.
Access-token revocation within its 15-min window is intentionally not
supported (documented in `AccessTokenService`).

---

## Phase 3a — what was built & how it was verified

**Built (all under `App\Office` + `App\Http\Controllers\Office`):**
- `RoleMiddleware([...roles])` — runs inside `JwtAuthMiddleware`; 403
  `forbidden` if the token's role isn't in the allow-list.
- `Input` extended: `requiredEnum` / `optionalEnum`, `requiredNumber`
  (min/max), `requiredEmail` / `optionalEmail` (lower-cased),
  `requiredCurrency` (3 letters, upper-cased), `optionalDateTime` (ISO-8601 →
  UTC), plus `fromArray()` for unit tests. All failures are 422
  `validation_failed` with a `field` detail.
- `Pagination` — 1-based `page`, `per_page` clamped to [1,100] (default 25),
  `envelope(rows, total)` → `{data, pagination:{page,per_page,total,total_pages}}`.
- `Query::params()` — reads `getQueryParams()`, falling back to parsing the
  URI query string (so hand-built requests / non-standard entry points still
  filter correctly).
- `CurrentUser` — resolves the JWT uuid to the internal user `id` for FKs
  (e.g. `inspection_requests.requested_by`).
- Repositories (`ClientRepository`, `ConsignmentRepository`,
  `InspectionRequestRepository`): prepared statements only; reads JOIN so
  callers get `client_uuid` / `consignment_uuid` / `requested_by_uuid` and
  never the internal `id`; `paginate()` with COUNT + filters (`type`,
  `direction`, `zone`, `client_uuid`, `status`, `overdue`, free-text `q`).
- Services: validation + rules + `Representation` mapping (drops `id`, ISO
  timestamps, DECIMAL as string per A17). Rules: `form_nxp_number` export-only
  (A/§4), `notice_deadline = requested_at + NOTICE_WINDOW_HOURS` (A14),
  request status state machine (A16), reschedule blocked once
  completed/cancelled, same-status transition is an idempotent no-op.
- Controllers + routes: `GET/POST /api/office/clients`,
  `GET/PATCH /api/office/clients/{uuid}`; same for `/consignments`;
  `/inspection-requests` adds `POST …/{uuid}/transition`. Whole group behind
  `JwtAuthMiddleware` + `RoleMiddleware(['office_reviewer','admin','super_admin'])`.
- Config: `NOTICE_WINDOW_HOURS` (env, default 72) →
  `settings.workflow.notice_window_hours`. **No new tables.**

**Verification results (2026-09-08):**
- `phpunit --filter Office` — **OK (25 tests, 69 assertions)**, zero warnings.
  Covers: representation hides `id`; enum/email/type validation → 422 with
  `field`; unknown `client_uuid`/`consignment_uuid` in a body → 422 (not 404);
  unknown `{uuid}` in the URL → 404; NXP export-only rule on create *and* on a
  direction-changing update; currency upper-case + DECIMAL precision as
  string; `notice_deadline` = requested_at + window (72h and 48h cases);
  default `requested_at` = now; full state machine incl. illegal transition
  and idempotent no-op; reschedule recompute + terminal-state block; `overdue`
  filter; pagination + `type` filter. HTTP: no token → 401; inspector role →
  403; office_reviewer runs client → consignment → inspection-request →
  transition → list; validation-error shape.
- One warning found and fixed during the run: `ConsignmentService` set
  `hs_code` only when provided, so the repo's create bind hit an undefined key
  — now always set on create (like `form_nxp_number`).

**Deferred to Phase 3b:** the server-rendered browser console (session +
`SessionMiddleware` + `CsrfMiddleware` on top of `Csrf`/`SessionStore`,
list/detail/form pages) over these same services. `DELETE` endpoints not added
(no soft-delete strategy yet — A12).

---

## Phase 3b — what was built & how it was verified

**Built:**
- `SessionMiddleware` — native PHP session for `/console/*` only; cookie
  `pia_console`, HttpOnly + SameSite=Lax + Secure(prod only); session bag
  exposed as the `session` request attribute (`SessionStore`); closes the
  session before the response is emitted.
- `CsrfMiddleware` — synchroniser-token check on unsafe methods (`_csrf`
  field or `X-CSRF-Token` header) via the Phase-2 `Csrf` helper; safe methods
  pass; failure → 403 `csrf_failed`.
- `ConsoleAuth` — password auth for the console (no tokens): find by email,
  timing-safe dummy verify on miss, Argon2id verify, suspended check,
  **office-roles-only** (`office_reviewer`/`admin`/`super_admin` — inspectors
  refused with 403), transparent rehash, `last_login_at` stamp.
- `ConsoleAuthMiddleware` — gate for the authed pages: no/stale/suspended/
  non-office session user → clear + 302 to `/console/login` (remembering the
  intended path); otherwise attaches the user row as `console_user`.
- `Renderer` — tiny PHP-template engine (no third-party engine, no build
  step): `render(tpl, data)` wraps `templates/layout.php`; `$this->e()`
  escapes, `$this->partial()` includes; path-traversal stripped.
- `AbstractConsoleController` — shared `render()` (injects CSRF token, current
  user, one-shot flash messages), `redirect()`, `flash()`.
- Controllers: `ConsoleAuthController` (login form / login / logout, session
  id + CSRF rotated on login, bounce-to-intended), `DashboardController`
  (counts), `ClientConsoleController`, `ConsignmentConsoleController`,
  `InspectionRequestConsoleController` (index / new / create / edit / update,
  plus IR `show` / `transition` / `reschedule`). Validation errors
  (`ApiException` from the Phase 3a services) are caught and the form is
  re-rendered with per-field messages and the user's input preserved;
  success uses POST-redirect-GET with a flash.
- Templates under `templates/console/**` + `public/assets/console.css`
  (theme-aware, responsive-ish, ~1 screen of CSS).
- Routes: `/console/login`, `/console/logout`, and behind the auth gate
  `/console` (dashboard) + `/console/{clients,consignments,inspection-requests}`
  CRUD. Group middleware order: `SessionMiddleware` (outer) → `CsrfMiddleware`
  → routing → `ConsoleAuthMiddleware` (authed sub-group only).
- Container: `Renderer` (template dir + `app_name`), `SessionMiddleware`
  (secure-cookie flag from env).

**Verified (2026-09-08, dev server + curl, real dev DB):**
- `GET /console/login` → 200 HTML, `Set-Cookie: pia_console=…; HttpOnly; SameSite=Lax`.
- `GET /console` with no session → 302 `/console/login`.
- `POST /console/login` with no / bad CSRF → 403 `csrf_failed`.
- Bad password → form re-rendered (422) with "Email or password is incorrect".
- Valid login (seed `admin@adwol.test`) → 302 `/console`; dashboard shows counts.
- Create client via form (with CSRF) → 302 to its edit page; appears in the list.
- Missing required field → 422, form re-rendered with `field-error` "is required".
- Create consignment (client dropdown) → created; create inspection request →
  created; detail page shows `pending` + valid next-status buttons; POST
  transition → 302 + flash, badge now `scheduled`.
- Isolated tests: **18 green** (`Csrf` token/rotate/validate, `ConsoleAuth`
  good/bad/suspended/inspector-role, `ConsoleAuthMiddleware` redirect vs pass +
  attaches user, `Renderer` layout/escape/partial/traversal).

**Known follow-ups:**
- Console errors that reach the *global* handler (CSRF 403, any 500) render as
  JSON, not an HTML page. Fine functionally; a content-negotiated HTML error
  page is a Phase 9 polish item.
- The client/consignment `<select>` dropdowns are capped at 200 rows — becomes
  a typeahead if a deployment outgrows that.
- Full HTTP console flow is covered by the manual script above, not PHPUnit
  (native `session_start()` is awkward under PHPUnit); a subprocess-based e2e
  test could be added in Phase 9.
- Session storage is the PHP default (files). Fine for one shared host; note
  for any future multi-node setup.

---

## Phase 4 — what was built & how it was verified

**Schema (migrations `20260909140001`–`…03`):**
- `inspection_findings` — child of `inspections` (CASCADE); item / expected /
  observed / `result` enum(pass,fail,flag) / notes. No uuid — the sync unit is
  the whole inspection.
- `inspection_attachments` — child of `inspections` (CASCADE); `client_uuid`
  UNIQUE (blob idempotency key), `file_path` nullable until upload,
  `file_type` enum, mime / size / original_name, `captured_at`,
  `uploaded_at`, `checksum_sha256`.
- `sync_log` — `record_uuid`, `record_type` ('inspection' | 'attachment'),
  `synced_by` FK, `device_id`, `sync_status` enum(success,conflict,error),
  `conflict_resolution`, `detail`, `synced_at`.

**Code (`src/Inspections/`):**
- `InspectionRepository` — `createScheduled` (office), `listAssignedTo`,
  `findByUuidForInspector`, `applySync` (status / started_at / synced_at /
  device via COALESCE so a null in the payload doesn't wipe an existing value).
- `FindingRepository::replaceForInspection` (delete + reinsert),
  `AttachmentRepository` (upsert stub by `client_uuid`, `markUploaded`,
  lookups), `SyncLogRepository::record` (append-only).
- `AttachmentStorage` — pure file I/O: size cap, client-declared SHA-256
  verified against bytes, MIME **sniffed from bytes** (jpeg/png/webp/heic/pdf),
  stored as `<client-uuid>.<ext>` under `storage/attachments/`, path-traversal
  guarded on read.
- `SchedulingService` (A18) — request must be pending/scheduled; inspector must
  exist, be role `inspector`, be active; creates the inspection (v4 uuid,
  status `scheduled`) and advances the request `pending → scheduled`.
- `AssignmentService` — the `/assigned` payload: each inspection + its
  findings/attachments + consignment context + a `checklist` template
  (`version: freeform-1`, empty `items` — Q7).
- `SyncService` — batch (≤50). Per record: validate shape; find by uuid *for
  this inspector* (else `conflict:not_assigned`); reject if status not in
  scheduled/in_progress/synced/amended (`conflict:locked` — D1); else one
  transaction: `applySync` + `replaceForInspection` + attachment `upsertStub`;
  `sync_log` row; result carries `attachments_pending` (client_uuids with no
  blob yet). Re-sending a batch converges to the same state.
- `AttachmentService` — upload (stub must exist + belong to the inspector +
  inspection not locked; stores bytes; `markUploaded`; `sync_log` type
  'attachment'); download (assigned inspector OR office role; 404 hides
  existence from others; 409 if the blob isn't uploaded yet).
- Controllers `AssignedController`, `SyncController`, `AttachmentController`
  (upload = raw body + `X-Checksum-Sha256` / `X-Original-Name` headers;
  download streams bytes with `Content-Disposition` + `nosniff`),
  `Office\SchedulingController`.
- Routes: `/api/inspections/{assigned,sync,attachments/{clientUuid}}` behind
  JWT + `RoleMiddleware(['inspector'])`; `GET /api/attachments/{id}` behind JWT
  (any role, service does the fine check); `.../schedule` added to the office
  group.
- Container: `AttachmentStorage` (dir + max bytes from settings).

**Verified (2026-09-09):**
- `phpunit` — **116 tests / ~330 assertions green** (Phase 1–3 = 90, Phase 4 =
  26): `AttachmentStorage` (types/size/checksum/traversal/overwrite),
  `SchedulingService` (create + request advance, role/status guards),
  `SyncService` (accept + store, **idempotent re-send**, not-assigned,
  wrong-inspector, locked-when-finalized, invalid-finding-no-partial-write,
  batch cap), `AttachmentService` (upload/download authz matrix, 409s), and a
  full HTTP round trip (office schedule → `/assigned` → `/sync` → upload →
  download as inspector and as office → role gates → no-token 401).
- `phinx migrate` clean on dev + test; app boots with all routes registered.

**Known follow-ups:**
- Findings are free-form until Q7 — no per-category required-item checks yet.
- Console UI for scheduling (assigning an inspector) not built — the API is
  there; a console form is a small add.
- Attachment upload is raw-body + header (simple for an internal API); switch
  to multipart if the PWA needs progress/multiple files per request.
- `sync` returns a flat 200 with per-record results (not 207). Client reads
  `results[].status`.

---

## Phase 5 — what was built & how it was verified

**Schema:** `audit_log` (migration `20260909150001`) — `actor_id` FK NULL,
`action`, `entity_type`/`entity_id`, `before_state`/`after_state` JSON,
`ip_address`, `created_at` (no `updated_at`). Indexed on entity, action, time.

**Code:**
- `App\Audit\AuditLog` — the ONLY class that touches `audit_log`, and it only
  `INSERT`s. `record(actor, action, entityType, entityId, before?, after?, ip?)`
  and `forEntity(type, id)` (newest first, joins the actor's name). A test
  asserts the class has no method other than `__construct` / `record` /
  `forEntity` (§7 app-layer append-only).
- `InspectionRepository` gained `reviewQueue`, `applyAmend` (status→amended,
  COALESCE on the two location fields), `applyFinalize` (status→finalized,
  stamps `finalized_by`/`_at`), `applyReject` (status→rejected). `rejected`
  added to `listAssignedTo` and to `SyncService::SYNCABLE` (A21).
- `ReviewService` — `queue` / `show` (inspection + findings + attachments +
  `audit_trail`) / `amend` / `finalize` / `reject`. Guard: only `synced` or
  `amended` may be amended/finalised/rejected (else 422 `not_reviewable`).
  `amend` runs in one transaction (row + findings), then writes the audit
  snapshot; empty amend → 422 `nothing_to_amend`; `reject` requires `reason`.
- `ReviewController` (`/api/office/inspections` queue; `/api/inspections/{uuid}`
  + `/amend` `/finalize` `/reject`) — JWT + office roles. `{uuid}` route regex
  keeps it from shadowing the inspector group's static `/assigned` `/sync`.
- Console: `ReviewConsoleController` + `templates/console/inspections/{index,show}.php`
  — review queue, detail with an editable findings table (blank rows ignored,
  submit replaces the set), finalise (with confirm) / reject (reason) buttons,
  and the audit trail. Nav: "Requests" (was "Inspections") + new "Review".

**Verified (2026-09-09):**
- `phpunit --filter ReviewServiceTest|InspectionReviewEndpointTest` — green:
  amend replaces findings + writes correct before/after + ip; `nothing_to_amend`;
  can't amend a `scheduled` row; finalize locks + stamps + no re-finalize;
  reject needs a reason, then re-sync returns it to `synced`; queue lists only
  reviewable rows; unknown uuid 404; `AuditLog` has no mutation methods.
- HTTP: office queue → detail (with `audit_trail`) → amend (200, `amended`) →
  finalize (200, `finalized`) → a late inspector sync is `locked`; reject flow;
  role gates (inspector 403 on review, office 403 on `/sync`, no-token 401).
- Full suite re-run after Phase 5 — see the Status line count.
- App boots with all routes; `phinx migrate` clean on dev + test.

**Known follow-ups:**
- Console review page not yet manually smoke-tested end to end (MySQL was
  down at write time); covered by the HTTP test at the API level.
- Broader `audit_log` coverage (office CRUD, scheduling) — A22.
- `finalize` has a Phase-6 hook comment where document generation will fire.

---

## Phase 8 — what was built & how it was verified

### 8a — server (offline-auth contract)

**No new tables** — `users.pin_hash` (nullable, Argon2id) was already in §4.

**Code:**
- `App\Auth\PinService` — `set(userRow, currentPassword, pin)` verifies the
  account password, checks the PIN against A23's policy, writes the Argon2id
  hash; `clear(userId)` nulls it. Argon2id params come from the shared
  `PasswordHasher`.
- `App\Auth\OfflineSession` — `describe(userRow, lastLoginAt?)` →
  `{ pin_set, pin_hash, reauth_deadline, reauth_days }`. `reauth_deadline` is
  `last_login_at + OFFLINE_REAUTH_MAX_DAYS` (or the freshly-stamped login time
  on the login path). Injected into `AuthService` and `MeController`.
- `UserRepository::updatePinHash(id, ?hash)`.
- `App\Http\Controllers\Auth\PinController` — `POST /api/me/pin` (body
  `current_password`, `pin`), `DELETE /api/me/pin`. Both return the refreshed
  `offline` block. Behind `JwtAuthMiddleware`.
- `AuthService::bundle()` and `MeController` now include the `offline` block.
- Config: `security.pin.{min,max}_length` from `PIN_MIN_LENGTH` /
  `PIN_MAX_LENGTH` (default 4 / 8). `.env.example` updated.

**Verified (2026-09-09):**
- `phpunit tests/Auth/PinServiceTest tests/Auth/AuthServiceTest
  tests/Http/OfflineAuthEndpointTest` — **20 tests / 65 assertions green**:
  PIN set stores a `password_verify`-able `$argon2id$` hash; wrong account
  password → `InvalidCredentialsException`; weak PINs (short, long, non-digit,
  all-same, ascending/descending/wrapping run) → 422 `weak_pin` and nothing
  written; `clear` nulls it. HTTP: login/refresh/`/api/me` all carry `offline`
  with a ~7-day `reauth_deadline`; `POST /api/me/pin` wrong password → 401,
  weak → 422, good → 200 with a verifiable `pin_hash`; `DELETE` → `pin_set`
  false; unauthenticated → 401.
- `tests/Http/AuthEndpointTest` + `HealthEndpointTest` re-run green (bundle
  shape change is additive).
- Full suite re-run after Phase 8 — see the Status line count.

### 8b — client (`public/app/`, static, no build step)

**Files:** `index.html` (app shell) · `offline.html` · `manifest.webmanifest`
· `sw.js` · `css/app.css` · `js/` (`config`, `util`, `idb`, `store`, `api`,
`argon`, `auth`, `sync`, `ui`, `app` + `views/{login,unlock,list,inspection,
settings}`) · `vendor/argon2/` (argon2-browser 1.18.0, MIT) · `icons/*.svg`
(placeholder — A27).

**Design:**
- **Service worker** (scope `/app/`): precache the app shell on install, drop
  old caches on activate, cache-first for shell assets, network-with-shell-
  fallback for navigations. Never sees `/api/*` (A25). Background Sync
  (`pia-sync` tag) wakes window clients to run their own sync; iOS falls back
  to "Sync now" + sync-on-reconnect.
- **IndexedDB** (`js/idb.js` + `js/store.js`): the four §6 stores.
  `inspections_queue` records carry a local `_state`
  (`clean`/`dirty`/`queued`/`error`). `mergeAssigned()` refreshes clean rows
  from the server but never overwrites a row with unsynced local work.
- **Sync engine** (`js/sync.js`): `syncNow()` = push queued inspections to
  `/api/inspections/sync` (idempotent on uuid), then upload each pending photo
  blob to `/api/inspections/attachments/{clientUuid}` (SHA-256 computed at
  capture, declared in the sync stub and re-sent as the upload header), then
  pull `/api/inspections/assigned` into `reference_cache` + the queue. Throws
  `OfflineError` when offline.
- **Auth / lock** (`js/auth.js` + `js/argon.js`): a cached session can exist
  while the app is locked. A cold start unlocks via online login or a local
  PIN check — `argon2.verify({pass, encoded: pin_hash})` against the cached
  Argon2id hash. If argon2/WASM is unavailable the app requires an online
  login (A28). Online login is refused for non-`inspector` roles.
- **Views:** login · numeric PIN unlock (shows a 7-day-overdue banner) ·
  assigned list (status chips, sync bar with online dot + last-sync time,
  "Sync now") · capture form (read-only consignment context, editable findings
  table, camera photo capture with pending/uploaded thumbnails, "Save draft" /
  "Mark ready to sync"; read-only when `finalized`, correction banner when
  `rejected`) · settings (set/replace/remove PIN, sync now, log out with an
  unsynced-work warning).
- `.htaccess`: `AddType application/wasm .wasm`, `application/manifest+json`,
  and `Cache-Control: no-store` + `Service-Worker-Allowed: /app/` on `sw.js`.

**Verified (2026-09-09):**
- `node --check` on every module + service worker — clean.
- Import-graph check (all modules imported with stubbed browser globals) —
  every cross-module named import resolves.
- **Functional test** (Node + `fake-indexeddb` + a stateful stub server):
  online login caches the session and unlocks → first `syncNow()` pulls one
  assigned inspection in as `clean` → local edit (findings + a photo blob,
  `_state = queued`) → second `syncNow()` sends the correct payload (uuid,
  findings, attachment checksum), uploads exactly one blob, record returns to
  `clean`/`synced`, no attachments left pending → a third `syncNow()` pushes
  nothing (idempotent) → `syncNow()` throws `OfflineError` when
  `navigator.onLine` is false.
- Argon2id correctness: PHP `password_hash(PASSWORD_ARGON2ID)` produces a
  standard `$argon2id$v=19$…` string that argon2-browser's `verify()` is built
  to parse (same lib KeeWeb ships).

**Known follow-ups / manual checks still owed:**
- **In-browser WASM path** (load `argon2-bundled.min.js` as a classic script,
  fetch `argon2.wasm` from its own directory, run a real `verify()`) can only
  be checked on a real device/browser — do this on the actual target phones,
  especially **iOS Safari**. The app degrades safely if it fails.
- **Real PNG icon set** (A27) and a proper app name/short_name if different.
- No automated browser E2E (no headless browser here); the Node functional
  test covers the data/sync logic, not the DOM.
- Attachment upload is one-blob-per-request raw body (matches the Phase 4
  API); fine for photos, revisit if very large PDFs appear.
- Logout does not proactively revoke the refresh token server-side (no
  endpoint yet); it clears local state and lets the token expire. A
  `POST /api/auth/logout` is a Phase 9 hardening item.
- A `/console` link to `/app/` (or a QR) for onboarding inspectors — cosmetic,
  not added.

---

## Phase 6 — what was built & how it was verified

### What `sample-docs/` actually contained, and what each file settled

| File | What it is | What it answered |
|---|---|---|
| `2021-00002.pdf` | A real, filled-in CCI | The exact 52-field CCI layout (§4's document, field-by-field); the numbering scheme (Q10); confirms NESS fee (0.5% FOB) is a CCI field, distinct from Gojopal's own service fee. |
| `Gojopal…Preshipment October Report 2021.pdf` | The client's monthly submission to FMF/CBN, with 3 appendices (Comprehensive / Compliant / Non-Compliant, split South/North) | Evidences **NNCI** as the real second document type (§5.3) — not CRF/IDR; the exact register columns Phase 7's statutory returns will need; confirms the numbering scheme across a whole month (2021-00262…00302, no monthly reset). |
| `CBN Report - June Analsysis.xlsx` | The CBN monthly submission | The "Compliant Report" register columns (identical shape to the PDF appendix) + bank/exporter/sector/destination pivot summaries — most of Q6 for CBN. |
| `GOJOPAL…MONTHLY STATISTICAL DATA REPORT JUNE 2021.xlsx` / `NBS MAY REPORT.xlsx` | The NBS-style monthly submission | A wider register (adds NEPC number, service fee, repatriation date, receipt numbers, direction-of-trade / top-exporters / top-banks pivots) — most of Q6 for NBS. |
| `ANNUAL REPORT FORMAT.xlsx` | A blank annual template | The FMF/NEPC annual submission shape: monthly columns × destination/exit-point/product/exporter/bank, several "top 10" sheets — most of Q6 for the annual return. |
| `Gojopal PIA Invoice June 2023 Final.docx` | Gojopal's own monthly invoice to CBN | Not a PIA-issued document (it's Gojopal billing CBN 0.35% of FOB for inspection services) — noted for Phase 7 as a possible extra report type, not built now. |

None of these are CRF or IDR — see A30.

### Schema (migrations `20260916100001`–`…006`)

- `consignment_trade_details`, `inspection_shipment_details` — the CCI's
  extra fields (A31). 1:1, nullable, upserted as a whole row.
- `document_sequences` — rebuilt once mid-phase (`…100006`) to drop a
  redundant surrogate `id` that was racing the atomic-counter SQL idiom (see
  DEV-4's updated note) — `year` is now the primary key directly.
- `documents` — brief §4 exactly, `type` widened to include `NNCI` (A30).

### Code

- `App\Documents\DocumentNumberAllocator` — `{year}-{00001..}`, one
  `INSERT … ON DUPLICATE KEY UPDATE next_number = LAST_INSERT_ID(next_number
  + 1)` per allocation; verified race-free against real MySQL (sequential,
  no gaps, independent per year).
- `App\Documents\DocumentSigner` — SHA-256 + HMAC-SHA256 (D7), keyed by
  `security.hmac_document_key` (already a required env var since Phase 1).
- `App\Documents\{TradeDetailsRepository,ShipmentDetailsRepository}` —
  prepared-statement upsert/read for the two new detail tables.
- `App\Documents\CciDataAssembler` — gathers inspection + consignment +
  client + trade + shipment into one structure matching the real form's
  fields one-to-one; computes `unit_price` (declared_value ÷ quantity) and
  the field-40 amount-in-words.
- `App\Documents\NumberToWords` — spells out currency amounts the way the
  sample does ("Ten Thousand Seven hundred and Eight dollars only") —
  verified against the real sample's own value, exact match.
- `App\Documents\PdfRenderer` — thin `mpdf/mpdf` wrapper (D6), reusable for
  Phase 7's report PDFs; `Mpdf`'s scratch dir is `storage/cache/mpdf/`.
- `App\Documents\DocumentStorage` — files under `storage/documents/`,
  outside the web root, named by the document's own uuid (mirrors
  `AttachmentStorage`, DEV-3's pattern).
- `App\Documents\DocumentService` — orchestrates: guard (finalized only),
  guard (CCI/NNCI only), assemble, allocate, render HTML
  (`templates/documents/cci.php` via `Renderer::renderRaw`), render PDF,
  sign, store, insert the `documents` row.
- `ReviewController::finalize` / `ReviewConsoleController::finalize` — both
  now call `DocumentService` right after a successful finalize, non-fatally
  (A32).
- `Http\Controllers\Documents\DocumentController` (API) — generate / list /
  download (DEV-13). Console: `ReviewConsoleController` gained
  `saveShipmentDetails` / `generateDocument` / `downloadDocument`;
  `ConsignmentConsoleController` gained `saveTradeDetails`.
- Config: `settings.pia` (`name`, `address`, `representative_title`) — env
  `PIA_COMPANY_NAME` / `PIA_COMPANY_ADDRESS` / `PIA_REPRESENTATIVE_TITLE`
  (A29).

### Verified (2026-09-16)

- **160 tests green** (140 from Phases 1–5/8 + 20 new): `DocumentNumberAllocatorTest`
  (sequential/no-gaps/independent-years/configurable-width against real
  MySQL), `DocumentSignerTest` (round-trip, tamper detection, wrong-key
  detection), `TradeDetailsRepositoryTest` / `ShipmentDetailsRepositoryTest`
  (upsert semantics), `DocumentServiceTest` (rejects non-finalized, rejects
  CRF/IDR, produces a valid `%PDF`-signed document with a verifiable
  signature, CCI/NNCI share one sequence, list/download, 404s),
  `DocumentEndpointTest` (finalize auto-generates a CCI over HTTP, explicit
  generate/list/download, role gates, validation).
- **Manual end-to-end, twice**: once via `App\Documents\DocumentService`
  directly against fixture data built from the real CCI sample's own values
  (caught and fixed the `document_sequences` numbering bug — first allocation
  of a year came back as "2026-00004" instead of "2026-00001" — and a
  template variable-name typo, both before either reached a test); once
  through the **actual browser console** end to end on the dev server:
  create client → create consignment → save trade details → office schedules
  → inspector syncs (API) → office finalises in the console → CCI
  auto-generates → appears in the Documents section → downloads as a valid
  PDF. The rendered CCI was read back and eyeballed field-by-field against
  the real sample — matches, including the amount-in-words line.
- `phinx migrate` clean on dev; app boots with all routes registered.

### Known follow-ups

- **Letterhead confirmation + logo file** (A29) — text-only for now.
- **CRF/IDR** — no sample exists; `DocumentService` rejects them cleanly
  rather than guessing at a layout.
- **No void/reissue flow** — a botched finalize's CCI can only be
  regenerated as a brand-new, separately-numbered document (A33). Fine for
  now; a Phase 9 polish item if the client wants a `status: void` UI.
- Broader `audit_log` coverage doesn't yet include document generation
  (A22 already notes office CRUD/scheduling are also outstanding).
- The console's "Generate NNCI" button doesn't (yet) try to infer
  compliance from the NESS fields — the office picks CCI or NNCI
  explicitly, which is safer than guessing.

---

## Phase 7 — what was built & how it was verified

### Schema (migrations `20260917130001`–`…002`)

- `compliance_tracking` — brief §4 exactly. Composite unique
  `(inspector_id, period_month)` doubles as the idempotency key for
  `ComplianceRepository::upsert()`.
- `statutory_returns` — brief §4 plus one addition: `row_count` (not in §4)
  — how many register rows a given return covers, shown in the console list
  so an empty/near-empty return is obvious before download.

### Code

- `App\Compliance\ComplianceEvaluator` — the missed-window count (A34) +
  streak/alert logic described above. `evaluateMonth()` only touches
  inspectors with something scheduled that month (no misleading all-zero
  rows for inspectors with nothing on the books).
- `App\Compliance\{ComplianceRepository,ComplianceService,Representation}` —
  standard repository/service/representation split, matching every other
  module in this codebase.
- `bin/evaluate-compliance.php` — the brief §8 nightly cron entrypoint.
  `--month=YYYY-MM` backfills a specific month; default is the current one
  (safe to re-run nightly — upserts, never duplicates). Prints a one-line
  summary + any new alerts to stdout for the cron log.
- `App\Returns\ReturnRowAssembler` — the one join every return builder reads
  from: `documents` (issued only) → `inspections` → `consignments` →
  `clients`, left-joined to the Phase 6 trade/shipment detail tables.
- `App\Returns\Builders\{CbnReturnBuilder,NbsReturnBuilder,
  GenericReturnBuilder}` (+ `AbstractReturnBuilder`, `CsvWriter`) — one class
  per agency layout; `StatutoryReturnService` picks the builder by agency
  and falls back to generic for NEPC/MOF/CUSTOMS (A36).
- `App\Returns\{StatutoryReturnRepository,StatutoryReturnStorage,
  StatutoryReturnService,Representation}` — generate (validates agency +
  period, builds the CSV, stores it under `storage/returns/`, inserts the
  row), list, submit (guards against double-submit), download.
- API: `App\Http\Controllers\Compliance\ComplianceController`,
  `App\Http\Controllers\Returns\StatutoryReturnController` — both behind
  `RoleMiddleware(['admin','super_admin'])` (DEV-15).
- Console: `ComplianceConsoleController` (`/console/compliance` — list +
  filter by alert + "Evaluate now") and `StatutoryReturnConsoleController`
  (`/console/statutory-returns` — generate form + list + download + mark
  submitted). `AbstractConsoleController::denyUnlessAdmin()` is a new shared
  helper (also now used by `SettingsConsoleController`, replacing its
  Phase-6-follow-up-local copy) — redirects non-admins to the dashboard with
  a flash, and the nav links themselves are hidden from non-admins too.

### Verified (2026-09-17)

- **Unit** (`ComplianceEvaluatorTest`, 7 tests): a past-grace still-scheduled
  inspection counts as missed; one within grace doesn't; a synced inspection
  is never missed even when late; 3 consecutive missed months build a streak
  to 3 and trip the alert; a clean month resets the streak to 0;
  `evaluateMonth` only touches inspectors with something scheduled that
  month; re-running is idempotent (no duplicate rows). All timestamps are
  computed relative to the real wall clock at test-run time (never a fixed
  "-48 hours"-style offset) specifically to avoid month-boundary and
  future-timestamp flakiness on the 1st/2nd of a calendar month or very
  early in the day — worth knowing if these tests ever need touching.
- **Unit** (`Cbn/Nbs/GenericReturnBuilderTest`, 6 tests, no DB): headers
  match the real sample column order exactly; a row built from the real
  sample's own values reproduces its own figures (kg→MT conversion, unit
  price, NESS/service-fee computation with the office-recorded figure
  winning over the 0.5%/0.35% fallback).
- **Service** (`StatutoryReturnServiceTest`, 9 tests): period filtering
  (including an inclusive end date), CSV content round-trips through
  download, NBS vs generic builders produce genuinely different columns,
  unknown agency / inverted period / double-submit are all rejected, a
  `void`ed document is excluded from the register.
- **HTTP** (`ComplianceEndpointTest` + `StatutoryReturnEndpointTest`, 5
  tests): evaluate → list → alert-only filter round trip over the real API;
  generate → list → download → submit → re-submit-rejected round trip;
  admin-only role gates confirmed both ways (office_reviewer 403, no token
  401); validation errors for a bad agency / missing dates.
- **Manual, on the dev server, real browser-console flow**: logged in as
  super_admin, hit `/console/compliance` and `/console/statutory-returns`
  (clean render, no PHP warnings), ran "Evaluate now", generated a CBN
  return, downloaded it (real CSV with the right headers), marked it
  submitted (status badge updates). Logged in as office_reviewer: both pages
  redirect to the dashboard with "Only admins can access this page.", and
  neither nav link renders at all.
- Full suite re-run after Phase 7 — see the Status line count.
- `phinx migrate` clean on dev; app boots with all routes registered.

### Known follow-ups

- **NEPC/MOF/CUSTOMS layouts** — still no sample; `GenericReturnBuilder`
  covers them for now (A36).
- **Cron isn't installed anywhere** — `bin/evaluate-compliance.php` is
  ready for a hosting cron entry (§8) but nothing schedules it yet; that's
  a Phase 9 deployment-checklist item, alongside confirming each agency's
  actual filing cadence (so a statutory-returns cron job could be added the
  same way, once cadences are known).
- **No automated submission channel** — `submitted_at`/`submitted_by` are
  set by a human clicking "Mark submitted" after actually filing the return
  elsewhere, exactly as §4 specifies ("manually confirmed"); D3's rationale
  (no confirmed regulator API/portal) still holds.
- Two NBS columns (`Int Ref No.`, `Repatriation Date`, `Receipt No. 2`) are
  on the real sample but aren't captured anywhere in this system yet — left
  blank rather than guessed.

## Post-launch enhancements — what was built & how it was verified

Work requested after the 9 build phases were already complete and signed
off; kept out of the phase numbering above since it isn't tracked against a
`PIA_BUILD_BRIEF.md` §-item, but logged here for the same reason as
everything else in this file.

### Branding + console redesign (2026-09-18/19)

- Real brand colors sampled directly from the client's logo (`adwol-logo.png`)
  via pixel analysis rather than guessed: primary red `#EC3237`, charcoal
  `#363436`. Generated a full icon/favicon/lockup asset set into
  `public/assets/brand/` (a PHP+GD script, not committed — one-off asset
  generation) and wired it into the console header, login page, PDF
  letterhead, and the PWA manifest/icons/app-bar (replacing Phase 8's
  placeholder SVGs).
- Console navigation rebuilt as a persistent left sidebar (dark charcoal
  surface, brand-gradient active state, collapses to an off-canvas drawer
  under 1024px) in place of the original top nav bar, using the
  `ui-ux-pro-max` skill's "Data-Dense Dashboard" style profile — matched
  against the actual product type (an internal compliance dashboard), not
  the generic marketing-landing pattern the tool suggests by default for
  vague queries.
- Manual light/dark toggle (`public/assets/theme-init.js` sets
  `data-theme` before first paint to avoid a flash; `console.js` flips +
  persists it to `localStorage`). CSS custom-property tokens for both
  themes; falls back to `prefers-color-scheme` if JS/storage is unavailable.
- Typography deliberately kept on the system-font stack rather than the
  skill's Google Fonts suggestion — the Phase 9 CSP is `font-src 'self'`
  with no external hosts, and relaxing that for a font felt like the wrong
  trade for an internal tool.
- Verified: full PHPUnit suite green after each pass; every touched console
  route re-checked with real `curl` + cookie-jar login round trips (not just
  "it renders") after the sidebar restructure, including that CSP-safe JS
  behavior (`script-src 'self'`, no inline handlers) was preserved.

### Landing page + admin features (2026-09-19)

Prompted by the user actually trying to find the inspector login and having
to ask for it — `/` was a bare JSON stub with no link to either app.

- **Landing page** (`GET /`, `App\Http\Controllers\LandingController`,
  `templates/landing.php`) — two cards (Office Console / Field Inspector
  PWA), no auth or DB dependency. Replaces the old JSON stub; `/health`
  remains the real machine-readable liveness endpoint.
- **User management** (`/console/users`, admin/super_admin only) — the
  README's long-standing known gap ("no user-management screen yet,
  accounts created directly in the database"). `App\Users\{UserAdminService,
  UserAdminRepository,UserRepresentation}` + `UserConsoleController`: create,
  edit (name/email/phone/role/zone), reset password (admin-set, ≥10 chars,
  shared out of band — no email sending in this app), suspend/reactivate.
  Deliberately kept to the existing 4-role enum
  (`inspector`/`office_reviewer`/`admin`/`super_admin`) rather than building
  a generic permission-matrix system — a real architecture/scope decision,
  confirmed with the client rather than assumed (a granular per-permission
  system would be a much larger surface to secure and test for roles the
  brief doesn't actually call for). A user can't suspend their own account
  (checked both in the controller and hidden in the UI); suspended accounts
  were already rejected at login by the existing `AuthService` check, so the
  toggle needed no auth-path changes.
- **Global audit log** (`/console/audit-log`, admin/super_admin only) — the
  per-inspection audit trail already existed (Phase 5); there was no
  system-wide "who changed what, anywhere" view. Added `paginate()` +
  `distinctActions()` to the existing append-only `App\Audit\AuditLog`
  writer rather than a new class, since it already owns every read/write
  against that table.
- **Operational settings** (`/console/settings`, new "Operational defaults"
  section) — `compliance_grace_hours` (previously env-only, a redeploy to
  change) is now editable from the console, same single-row-table +
  env-fallback pattern as `CompanySettingsRepository` (Phase 6 follow-up).
  Works because this app is process-per-request (`public/index.php` calls
  `AppFactory::create()` fresh on every request, confirmed by reading the
  front controller before relying on it) — no cache to bust, a saved value
  is live on the very next request, including the next `bin/
  evaluate-compliance.php` cron run.
- Verified: `composer test` full green; every new route exercised end-to-end
  with real `curl` + cookie-jar sessions against the running dev server —
  create a user → confirm the row in MySQL, toggle-status → confirm
  `status` flips and login is actually rejected while suspended, reset
  password → confirm the new password verifies against the stored hash
  with `password_verify()`, operational settings save → confirm the new
  value round-trips through `OperationalSettingsRepository`. Test data
  cleaned up afterward (`DELETE`d the scratch user, reset the grace-hours
  row) so it doesn't linger in the dev seed.

### Review pass, quick wins and medium features (2026-09-19)

A `reviewing-code` / `architecting-systems` pass over the whole system, then
the feature list the client picked from ("quick wins first, medium next,
bigger initiatives after, 2FA last").

**Found and fixed in review**
- `db/grants.sql` was missing the new `operational_settings` table — on a
  least-privilege production DB user the Settings "Operational defaults" save
  would have failed while working fine in dev (dev connects unrestricted).
  Also now lists `record_attachments`. **Every new table needs a grants line.**
- Password reset bypassed the `Input` trimming/validation the create form
  gets; both now share it.
- **Resetting a password or suspending a user did not end their existing
  sessions** (console cookie sessions can't be enumerated server-side).
  Added `users.sessions_valid_after`: each console session records its start
  time (`login_at`) and `ConsoleAuthMiddleware` rejects any session older than
  the stamp. Reset, suspend and a new "Sign out everywhere" button all stamp
  it and also revoke the user's API/PWA refresh tokens. API access tokens
  still live out their 15-minute TTL (existing, documented JWT trade-off).
- User management had **no audit trail and no tests**. Every user action
  (create/update/reset/suspend/activate/sign-out) is now written to
  `audit_log` (profile fields only, never hashes); a user can't change their
  own role (self-lockout guard); `UserAdminServiceTest` added.
- Icon SVG paths were duplicated between two templates → `App\Http\View\Icons`.
- Two existing tests encoded the old behaviour (`/` returning JSON; the exact
  public method list of `AuditLog`) and were updated deliberately — the
  audit test still pins the list so a mutation method can't slip in unseen.

**Quick wins**
- **Global search** (`/console/search`, top-bar box): clients, consignments,
  inspections, issued documents (by CCI number), plus users for admins. LIKE
  wildcards in the query are escaped (a search for `50%` matches literally).
- **CSV export** on the clients / consignments / inspections lists (and users,
  admin-only), honouring the active filters, capped at 20,000 rows, via
  `CsvWriter::writeSafe` which neutralises spreadsheet formula cells (leading
  `= + - @`, tab) — trade-off: phone numbers beginning `+` get a leading `'`.
  Statutory returns keep the plain writer (their numbers legitimately start
  with `-`). **Each export is written to the audit log.**
- **Security page** (`/console/security`, admin-only): rate-limiter activity,
  throttled windows, live inspector sessions, account hygiene — pure reads
  over tables the app already keeps.
- **Dashboard charts**: inspections/month and requests-by-status as
  server-rendered SVG/CSS (no JS chart library, so the strict CSP is
  untouched), a compliance-alert note, and an admin-only recent-activity feed.
- The request and review lists now show client / product / inspector names
  instead of truncated UUIDs (additive `client_name`, `product_category`,
  `inspector_name` fields on the API representations too).

**Medium**
- **Calendar** (`/console/calendar`): month grid of scheduled inspections and
  unassigned-request notice deadlines, inspector filter, per-day agenda; the
  grid collapses to the agenda on phones. Times shown in UTC.
- **"Needs attention" bell**: deliberately *derived* live from existing data
  (inspections awaiting review, requests past / within 24h of their notice
  deadline, compliance alerts for admins) rather than a stored notification
  table — no read/unread state to drift, no job to run; resolving the work is
  what clears the item.
- **Documents on clients and consignments** (`record_attachments`): PDF/JPEG/
  PNG/WebP, reusing the hardened `AttachmentStorage` (content-sniffed type,
  size cap, traversal-safe) in `storage/record-attachments/`. The download
  filename's extension always comes from the sniffed type, so `invoice.exe`
  that is really a PDF is served as `invoice.pdf`. Served only through an
  authenticated route (`attachment` disposition, `nosniff`); delete is limited
  to the uploader or an admin; upload/delete are audited; the post-delete
  redirect only accepts console edit paths (no open redirect).
- Sessions: see "Found and fixed" above.

### Bigger initiatives (2026-09-20)

**Backups** (`src/Backup/`, `bin/backup.php`, super_admin-only `/console/backups`).
Pure-PHP logical dump (no `mysqldump` — shared-hosting constraint, same spirit
as D6) + generated/uploaded files + manifest in one zip with a `.sha256`
sidecar; `--keep=N` retention; create/download/delete audit-logged; restore is
deliberately a documented manual procedure, not a button (DEPLOYMENT.md §10).
**Verified by an actual restore** into a scratch database with a per-table
content hash comparison against the source — which caught a real bug: TIMESTAMP
columns were dumped as UTC but the restoring session read them in the server's
local zone (China Standard Time here), shifting every timestamp by +8h with row
counts still matching. The dumper now pins `time_zone='+00:00'` for both the read
and the restore script. Lesson: count checks don't prove a backup; content
hashes after a restore do. Download uses `?name=` rather than a `.zip` path
segment because some servers (PHP's built-in one) treat that as a static-file
request and 404 before the app sees it. Backup names are matched against a strict
regex; nothing user-supplied ever becomes a path.

**Email** (`src/Mail/`, PHPMailer). `MailerInterface` with an SMTP transport when
`MAIL_HOST` is set and a logging no-op otherwise, so features degrade instead of
failing. Built on it: **self-service password reset** (`PasswordResetService`;
hashed single-use 60-minute tokens, generic response so it can't be used to
enumerate accounts, link built from `APP_URL` never the Host header,
per-user request throttle plus the IP rate limiter, `no-referrer`/`no-store` on
the reset page, completing a reset ends every live session and voids other
links) and the **daily digest** (`bin/send-digest.php`; same live list as the
bell; nobody is emailed on a quiet day; per-user opt-out). Settings gets an
email status panel and a "send me a test email" button; SMTP credentials stay in
`.env` on purpose. Verified over a real SMTP conversation (a throwaway local sink),
not just a test double — that run also caught that my `composer require` had been
cut short by its timeout, leaving PHPMailer un-installed: unit tests with a mailer
double could never have found it. Text-only emails (no HTML to inject into).

**Client portal** — ⚠ **withdrawn on 2026-09-21 at the client's request** (see
"Client change requests" below); kept here as a record of what existed. A third
app alongside the console and the inspector PWA: read-only pages (overview,
consignments + detail, inspections with a client-friendly progress label, and
issued certificates for download) for a client's own staff. Design decisions:
- **Separate accounts** (`client_users`, one client each, no role) rather than a
  new role in `users` — a mistake in role handling can never promote an external
  person into the console or the inspector API.
- **Separate session**: own cookie name (`pia_portal`) and path (`/portal`), own
  CSRF namespace; verified that a portal cookie gets no console access and vice
  versa. Same hardened handling otherwise (HttpOnly, SameSite=Lax, session id
  regenerated at login, login rate-limited per IP+path, timing-parity on unknown
  emails, one indistinguishable "email or password is incorrect").
- **Tenant isolation is structural**: `PortalData` is the only place the portal
  reads business tables; every method takes the client id from the *session-bound
  user row* (never a URL/form value) and every query — including the
  document → inspection → request → consignment chains — is constrained by it. A
  uuid from another client matches nothing and gets the same redirect as a
  nonexistent one. `PortalDataTest` builds two clients with full chains and tries
  every read from the other side; the live server was tested the same way with two
  real accounts (other client: 0 bytes leaked).
- **Only `issued` documents** are visible (not draft/void), and **every download
  re-verifies the HMAC** and refuses (and logs) a file that fails — tested by
  corrupting a stored PDF, confirming a refusal, then restoring it.
- Internal review states (synced/amended/rejected) are folded into "Under review";
  clients don't see auditor-facing workflow detail.
- **Admin-only provisioning** from the client's edit page (create / suspend /
  reset password / sign out everywhere), all audit-logged against the client.
  Suspension, reset and sign-out stamp `sessions_valid_after`, so they take effect
  on the user's next click. Portal downloads are audited as system events carrying
  the portal account in the payload (`audit_log.actor_id` references staff users).
- Deliberately *not* built yet: client-submitted inspection requests (needs a
  decision on who `requested_by` is), and self-service password reset for portal
  users (admin-set passwords for now; the email plumbing exists to add it).

**Two-factor authentication (TOTP)** — built last, as requested. `App\Security\{Totp,
SecretBox,TwoFactorService,TwoFactorPolicy,QrCode}`; challenge at `/console/two-factor`,
self-service at `/console/account` ("My account", also has change-password, which
didn't exist before), admin controls on the user page and in Settings → Security.
- **Standard TOTP** (RFC 6238, SHA-1, 30 s, 6 digits) — works with Google/Microsoft
  Authenticator, Authy, 1Password. Implemented in ~80 lines rather than adding a
  dependency, and pinned to the RFC's *published test vectors* so "standard" is
  proven, not asserted. QR codes are rendered server-side to inline SVG (no image
  host, no JS, no CSP change).
- **Password ≠ signed in**: a correct password on a 2FA account only parks the
  identity in the session (5-minute TTL, 5 wrong tries, plus the per-IP limiter);
  `/console/two-factor` is the only route that can turn that into a session, via the
  same `ConsoleLogin::complete()` the password-only path uses.
- **Replay-proof**: the matched 30-second step is recorded with an atomic
  compare-and-set, so a code can't be used twice (verified live: same code → 422).
- **Seeds encrypted at rest** (AES-256-GCM, key derived from `APP_KEY`); unreadable
  seed → fails closed. Recovery codes: 10 random, single-use, stored only as keyed
  hashes, shown exactly once (rendered straight from the POST response, `no-store`,
  never via session/flash). ⚠ Because of the `APP_KEY` binding, **a backup restored
  onto a new server needs the same `APP_KEY`, and rotating `APP_KEY` invalidates all
  enrolled 2FA** — documented in DEPLOYMENT.md.
- **Policy is a setting, not a code change**: Settings → Security "Require two-factor
  for admins and super admins". When on, an unenrolled admin is confined to My
  account until they enrol (enforced in `ConsoleAuthMiddleware`, tested). An admin
  can't switch it on until they've enrolled themselves (would just lock them into
  the enrolment page), and can't turn off their own 2FA while it's required.
  Off by default so nobody is locked out on upgrade; available voluntarily to every
  console role.
- **Re-authentication for anything that weakens a credential**: disabling 2FA, new
  recovery codes and changing the password all re-ask for the current password. A
  password change signs out every *other* device but keeps this session.
- **Lost phone**: an admin resets the person's 2FA from their user page (switches it
  off and signs them out everywhere so whoever has the phone loses access too);
  not available on your own account. Every 2FA event (enable, disable, regenerate,
  recovery-code use, admin reset, policy on/off) is audit-logged.
- Not built: 2FA for the inspector PWA (its offline PIN is a separate mechanism).
- **Status (2026-09-21)**: built and working, but switched off in the dev database
  at the client's request while they review the system — the policy is off and no
  account is enrolled, so every sign-in is password-only. Nothing needs undoing to
  turn it back on.

**Testing note**: the full suite takes 25+ minutes on this machine; run
`vendor/bin/phpunit` directly (composer's 2000 s process-timeout aborts it). Test
counts are recorded at the end of the latest section below.

### Known follow-ups from this pass

- ~~No self-service "forgot password"~~ — built (email reset for console users,
  when SMTP is configured).
- ~~No CSV export for Users~~ — built. The Audit log screen still has no export.

---

## Client change requests (2026-09-21)

The client asked for seven changes after reviewing the system. What was done:

**1. Client portal removed.** Every portal file, route, template, stylesheet
block and test is gone; `/portal/*` now 404s and the landing page lists two apps.
Schema: the unreleased portal-self-service migration (portal 2FA, portal password
resets, client-submitted requests) was rolled back and deleted — it had only ever
run on dev/test — and `20260921120000_drop_client_portal` drops `client_users`
(its `down()` recreates the table empty; accounts aren't recoverable). Grants
updated. `TwoFactorService` went back to console-users-only.

**2. "Consignments" renamed "NXP" — labels and URLs.** Client decision: every
visible label (nav, titles, buttons, table headers, CSV headers and filenames,
search, audit-log labels, inspector PWA) says NXP; console URLs moved from
`/console/consignments…` to `/console/nxp…` with a 301 redirect from the old paths
(query string kept). **Deliberately unchanged**: database tables/columns, PHP class
names and the JSON API (`/api/office/consignments`, `consignment_uuid`) — renaming
those was judged high-risk for no user-visible gain. Caught during the live check:
the attachment upload's safe-redirect whitelist still only allowed
`/console/consignments/…`, so uploads from an NXP page would have bounced to the
dashboard — fixed.

**3. The NXP number is the key identifier.** Client decision: *required and unique
on exports*; still refused on imports (brief §4).
- `ConsignmentService` enforces both with field-level messages; migration
  `20260921130000_unique_nxp_number` folds blanks to NULL, trims, and adds a
  unique index as the race-proof backstop (the collation makes it
  case-insensitive; NULLs don't collide, so imports and legacy blanks are fine).
- Legacy exports saved before the rule keep working everywhere; they're flagged
  ("needs NXP no.", a "Missing NXP number" filter, a notice on the record) and must
  be given a number the next time they're edited.
- The NXP number now leads the NXP list, request list, review queue, search results,
  request/inspection detail pages, the request form's dropdown, CSV exports and the
  inspector PWA; search matches it everywhere.

**4. CCI number prominence.** Each NXP record has a "Requests, inspections &
certificates" panel listing every CCI/NNCI number issued under it (with links);
the review queue, inspection page, invoice preview, archive, search and the
inspections CSV all show the CCI number.

**5. CCI header shows the client, not the agency.** The header now carries the
client's name and address, a prominent "CCI No." and the NXP number; the ADWOL logo
and name were removed from it. The agency still signs at the foot
("For: {company}") — an inspection certificate needs its issuer; flagged to the
client in case they want that removed too.

**6. NESS fee.** The client first wrote 0.05%; asked, they confirmed the existing
**0.5% of FOB** is right. The rate (and the 0.35% service fee) now live in one place,
`App\Documents\Fees`, used by the CCI, the CBN return and the invoice. Box 50 on the
CCI is always filled: the office's recorded figure if there is one, otherwise 0.5%
of FOB (in naira when the exchange rate is known), and the review form shows that
computed figure next to the input.

**7. CBN invoicing** (`src/Invoicing/`, `/console/invoices`, admin-only). The
monthly PIA → CBN service-fee invoice, modelled on sample-docs "PIA Invoice June
2023": CCIs issued in the month grouped by region (zone) and currency; FOB, weighted
exchange rate, FOB ₦, fee at 0.35%; amount in words; bank details; signatory.
- **Blockers are shown, never guessed around**: a CCI without an exchange rate,
  missing bank details, no CCIs, or an existing live invoice for the month.
- NNCIs and void certificates are not billed. One live invoice per month; a
  replacement needs the first voided (with a reason). Issued → paid (date +
  reference, validated) or void; a paid invoice is final.
- Figures are frozen into the row (`lines` JSON) and the PDF is HMAC-signed like a
  CCI; downloads re-verify. Issuing is serialised per month with a MySQL named
  lock, and number allocation + insert share a transaction so a failure never
  leaves a gap.
- Numbers: `{prefix}/{year}/{NNN}` from their own `invoice_sequences` table.
  ⚠ Bug caught live: that table first had a surrogate `id`, which makes the
  `LAST_INSERT_ID(expr)` idiom return the id instead of the counter on a year's
  first insert — exactly the trap `document_sequences` fell into in Phase 6. Rebuilt
  keyed by `year` (migration amended before any deployment); tested.
- Checked against the sample: every line figure reproduces exactly, and the amount
  in words matches word for word. The sample's printed total is 1 kobo below the
  sum of its own lines (it took 0.35% of the combined naira); our total is the sum
  of the printed lines so an invoice always adds up.
- Invoice letter details (bank account, prefix, addressee, description) are edited
  on the Invoices page and audit-logged.

**8. Archive** (`src/Archive/`, `/console/archive`). One register over every
generated file — CCIs/NNCIs for all office roles, plus CBN invoices and statutory
returns for admins. Automatic by construction (it reads the records each generator
already writes, so nothing can be forgotten) and keeps voided documents. Filters by
number, NXP no., client, type and date; bulk ZIP (up to 500 files) filed as
`{kind}/{year}/{month}/…` with an `index.csv`. Signed files are re-verified before
they go in; a tampered or missing file is left out and named in the index. A
reviewer asking for `kind=invoice` or downloading the ZIP only ever gets
certificates. Bulk downloads are audit-logged.

**9. Bulk import** (`/console/import`, admin-only) — clients and NXP records from a
CSV: template download, row-by-row preview, then confirm. The preview *is* the real
import run inside a rolled-back transaction, so it can't disagree with the commit;
the commit is all-or-nothing. Goes through the same services as the forms, so every
rule (including the NXP rules) applies. Old sheets with a `form_nxp_number` header
still import.

**Verified live** (dev server, real MySQL, curl + PDF inspection; dev data restored
from a snapshot afterwards): NXP rules through the real form (missing, duplicate
in a different case, NXP on an import, legacy notice, missing filter); 301s from old
URLs; invoice blocked → fixed through the UI → issued → double-issue refused →
future payment date refused → paid → void of a paid invoice refused; invoice and
CCI PDFs read back and checked visually; archive filters, ZIP contents, reviewer
visibility, and a tampered invoice refused both in the ZIP and on direct download.

**Tests**: 305 tests / 1,011 assertions, all passing (2026-09-21). New: invoice
arithmetic pinned to the sample invoice, invoice issuing/blocking/numbering
(including a failure *after* number allocation), payments and voids, tamper
refusal; archive union, role filtering, filters, ZIP layout and tamper handling;
the CCI header and NESS box; the NXP rules (required, unique case-insensitively,
DB-level backstop, legacy records); import of NXP records. The full run found one
real gap — a non-admin asking the archive for `kind=invoice` got certificates back
instead of nothing (no invoice was ever exposed; the controller already reset the
filter) — fixed in the repository. A full run now takes ~90 minutes on this
machine (the invoice tests render real PDFs).

## Income report, Board of Directors role, bulk import for every record (2026-09-22)

Client requests: an interactive income report on the dashboard at the initial
0.35% (confirmed over 0.3%), a CSV upload for every record that is typed in, and
Board of Directors access.

**Income** — `src/Income/IncomeRepository.php` + `IncomeReport.php`,
`IncomeConsoleController`, `templates/console/income/{index,_card}.php`.
- Income per CCI = FOB × that CCI's exchange rate × `Fees::SERVICE_FEE_RATE`
  (0.35%), to the kobo. Only `type = CCI AND status = issued` count — the same
  certificates the CBN invoice bills. NNCIs and voided certificates earn nothing.
- A CCI with no exchange rate is listed under "not counted" with a link to add
  the rate (or bulk-load via Import → Shipment / NESS details); never guessed.
- Invoiced / paid / outstanding come from live (non-void) `cbn_invoices` whose
  month falls in the period. Invoices bill per region line, so they can differ
  from the per-CCI sum by a few kobo of rounding; the page says so.
- Dashboard card (admin + board): period chips swap the card in place by
  fetching `/console/income/card` (same partial; works as plain links without
  JS); a custom date range; a 12-month bar chart where each bar links to that
  month's report. Periods are calendar periods in UTC, like the invoices.
- `/console/income/export` — CSV, one line per CCI plus a total; audit-logged.

**Board of Directors** — role `board` (migration `20260922100000_add_board_role`).
- Signs in to the console; sees everything an admin can *view*: dashboard with
  income and activity, income report, CBN invoices (list/download), returns
  (list/download), compliance, audit log, archive (all kinds), every record.
- Read-only by one gate: `BoardReadOnlyMiddleware` on the whole signed-in
  console refuses every non-GET request for a board user (and the `/new` forms),
  except `/console/account/*` and logout — so a write action added later is
  covered automatically. Refusal = flash + 303 back to the (same-site) referring
  page.
- Admin-area GET pages moved into an "oversight" route group allowed for
  admin/super_admin/board; every write stays in the admin-only group as well.
- UI: "Oversight" menu group, a read-only banner, create buttons hidden and
  write forms disabled by `console.js` (presentation only — the server refuses).
- Not given: users, settings, security, import, backups, the inspector API.

**Bulk import for every typed-in record** — `ImportService` now handles
`clients`, `nxp`, `inspection_requests`, `trade_details`, `shipment_details`,
`users`. Same design as before (preview = real run rolled back; commit
all-or-nothing; same services as the forms).
- Rows find their NXP record by `nxp_number`, or by `record_ref` (the record's
  uuid, now shown on the NXP page as "Record reference" and in the NXP export)
  for imports, which have no NXP number. Shipment rows can also use `cci_number`
  (the inspection that certificate was issued on); otherwise the record's latest
  non-rejected inspection.
- The two details types update existing rows and change only filled-in cells
  (blank = keep). Cells are validated first: lengths, FOB/CFR/CIF, dates as
  2026-09-21 or 21/09/2026, amounts with thousands separators; exchange rate > 0.
- Inspection requests: one per record per file; a record with an open
  (pending/scheduled) request is refused.
- Users: same rules as the user form; a blank password gets a random one (the
  person uses "Forgot password", or an admin resets it). "Board of Directors" is
  accepted for `board`.
- Not importable, on purpose: inspection findings (captured on the inspector's
  device) and certificates/invoices (generated by the system).

**Found while writing the SOP, fixed:**
- **No console screen assigned an inspector.** Scheduling (SchedulingService,
  decision A18) existed only as `POST /api/office/inspection-requests/{uuid}/schedule`
  (a Phase 4 follow-up never done), so the office could not complete the
  process in the console. The request page now lists its inspections and has an
  "Assign inspector" form (active inspectors, date/time, location); it hides once
  a live inspection exists and reappears after a rejection. Also importable in
  bulk ("Inspector assignments": NXP number + inspector email).
- **Regenerating a certificate double-billed.** Every generated CCI/NNCI stayed
  `issued`, so a corrected CCI (or a CCI followed by an NNCI) was counted once
  per version by the CBN invoice and the income report — the dev data had one
  shipment with CCI, CCI, NNCI all issued. `DocumentRepository::insert` now voids
  the inspection's current certificate in the same transaction as the insert:
  one live certificate per inspection. Migration
  `20260922110000_one_live_certificate_per_inspection` voids older duplicates
  (keeps the newest). The review page marks certificates current / replaced and
  asks before replacing. An invoice already issued keeps its frozen figures.

## UI/UX overhaul on the design-system tokens (2026-09-22)

Client request: "UI is not perfect — make proper enhancements to all UI/UX", using
the design-system skill (`../.claude/skills/design-system`). Reviewed from real
screenshots (headless Chrome over CDP, desktop 1440 + phone 390, light + dark).

**Token architecture** — `public/assets/console.css` and `public/app/css/app.css`
were rewritten on the skill's three layers:
- *Primitives*: brand red scale around the logo's #EC3237, charcoal neutrals
  around #363436 / #4B4B4D / #979797, status greens/ambers/blues, a 4px spacing
  scale, type scale, radii, durations. Raw values live only here (checked: no
  hex colour outside the primitive block in either file).
- *Semantic*: surface / fg / border / primary / success / warning / info /
  danger… The only layer the themes override (OS preference, and the manual
  toggle via `data-theme`, in both directions).
- *Component*: button, input, card, table, badge tokens.
Components follow the skill's specs: 40px buttons and inputs (48px in the
field app), primary / outline / ghost / danger variants, hover / active /
focus-ring / disabled states, 12×16 table cells with right-aligned numbers,
badges with a dot so status never relies on colour alone, alerts with icons.

**Fixes found in review**
- Phone width: dashboard stats and period chips overflowed; now 2-up stats and
  no horizontal page scroll (verified scrollWidth = viewport at 390px).
- Title shown twice (top bar + page) → the top bar is now a breadcrumb.
- Raw ISO timestamps everywhere → `Renderer::dt()` / `d()` ("16 Sep 2026,
  01:21 UTC"); UUIDs shown for inspector / requester → names (reference ids
  moved to the end of the detail list).
- Input values rendered bold (inherited from the label) → regular weight; base
  input styles use `:where()` so component rules always win (the top-bar
  search icon overlapped its placeholder before).
- Tiny unreadable logo chip → brand mark + "ADWOL / PIA Office Console"
  (or "Board oversight"); skip-to-content link; one primary action per page
  (filters are secondary, destructive actions use the danger variant).
- Whole-page fade-in on every navigation removed (only small, purposeful
  motion remains; reduced-motion respected).
- Nested boxes (form card inside section card) flattened; income page figures
  in an even 3×2 grid; oversized chart capped; equal-width breakdown tables.
- **Review queue bug**: the queue showed "—" for NXP no., client, product, CCI
  no. and inspector on every row (and emitted PHP warnings) — `ReviewService`'s
  summary never carried those fields after the NXP rename. Fixed.
- **Field app**: could stay on "Loading…" when DOMContentLoaded had fired before
  the module ran; it now starts immediately when the DOM is ready. Cards show
  the NXP number. Shell cache bumped to `v3` so devices pick up the new CSS.
  (Intermittent stalls seen while testing were PHP's single-threaded dev server
  held by leftover headless-Chrome connections — dev-server only.)
