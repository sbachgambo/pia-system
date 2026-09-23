# PIA Automation System — Build Brief

## 1. Purpose and scope

An internal system to automate Preshipment Inspection Agency (PIA) workflows:
consignment intake, inspection scheduling, field data capture (with offline
capability), CCI/CRF/IDR document generation, compliance tracking, and
statutory return generation for CBN, NEPC, NBS, the Ministry of Finance, and
Customs.

This brief assumes the system is for internal company use, not a multi-tenant
product. Revisit the schema's `clients` table design if that changes later —
it's already structured to support it without a rewrite, but access control
would need adding.

## 2. Decision register

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Inspectors can amend a synced record until office review finalizes it | Balances field correction ability with a hard cutoff before a document is legally issued |
| D2 | Document generation (CCI/CRF/IDR) is server-side only, after sync | Keeps the PWA simple; avoids draft/final mismatch; centralizes document integrity |
| D3 | Regulator output is generated PDF/CSV reports for manual submission | No confirmed API/portal integration exists yet; avoids building against an undefined contract |
| D4 | Offline auth: online login required at least every 7 days; local PIN unlocks the cached session in between | Standard mobile-offline pattern; bounds the risk window of a lost/stolen device |
| D5 | Stack: PHP 8.1+ / MySQL, deployed to existing shared hosting (cPanel-style) | Matches existing company hosting and prior in-house builds |
| D6 | PDF generation: pure-PHP library (Dompdf or mPDF), no server binary/exec() | Shared hosting typically has no shell access — rules out wkhtmltopdf-style tools |
| D7 | Document integrity: SHA-256 content hash + HMAC signature, not a PKI digital certificate | No certificate authority relationship exists yet; this is upgradeable later without a schema change (see §7) |

## 3. Architecture

**Three tiers**, matching the diagram reviewed earlier:

1. **Inspector device** — installable PWA, service worker for offline asset
   caching, IndexedDB for the local record queue
2. **Shared hosting server** — PHP REST API (business logic, auth, workflow
   rules) + MySQL (system of record)
3. **Consumers of the server** — office/admin console (browser-based) and
   the statutory returns module (scheduled report generation)

**Sync model:** every record created offline carries a client-generated UUID
(v4) as its permanent identifier. The server never assigns identity — it only
accepts or rejects a UUID it already has. This makes sync idempotent: a
retried upload after a dropped connection can't create a duplicate.

## 4. Data model

All tables use `InnoDB`, `utf8mb4`, and a `created_at` / `updated_at`
(`ON UPDATE CURRENT_TIMESTAMP`) pair unless noted. All timestamps stored in
UTC; convert at the presentation layer.

### users
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| uuid | CHAR(36) UNIQUE | |
| full_name | VARCHAR(150) | |
| email | VARCHAR(190) UNIQUE | |
| phone | VARCHAR(20) | |
| password_hash | VARCHAR(255) | Argon2id |
| pin_hash | VARCHAR(255) NULL | Argon2id, offline PIN (D4) |
| role | ENUM('inspector','office_reviewer','admin','super_admin') | |
| zone | VARCHAR(100) NULL | |
| status | ENUM('active','suspended') DEFAULT 'active' | |
| last_login_at | DATETIME NULL | drives the 7-day re-auth rule |

### clients
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| uuid | CHAR(36) UNIQUE | |
| name | VARCHAR(200) | |
| type | ENUM('exporter','importer') | |
| rc_number | VARCHAR(50) NULL | CAC registration |
| address | TEXT | |
| contact_name | VARCHAR(150) | |
| contact_phone | VARCHAR(20) | |
| contact_email | VARCHAR(190) | |

### consignments
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| uuid | CHAR(36) UNIQUE | |
| client_id | BIGINT UNSIGNED FK → clients.id | |
| direction | ENUM('export','import') | |
| product_category | VARCHAR(150) | |
| product_description | TEXT | |
| hs_code | VARCHAR(20) NULL | |
| quantity | DECIMAL(14,2) | |
| unit_of_measure | VARCHAR(20) | |
| declared_value | DECIMAL(16,2) | |
| currency | CHAR(3) | ISO 4217 |
| origin_country | VARCHAR(100) | |
| destination_country | VARCHAR(100) | |
| zone | VARCHAR(100) | |
| form_nxp_number | VARCHAR(50) NULL | exports only |

### inspection_requests
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| uuid | CHAR(36) UNIQUE | |
| consignment_id | BIGINT UNSIGNED FK → consignments.id | |
| requested_by | BIGINT UNSIGNED FK → users.id | |
| requested_at | DATETIME | |
| notice_deadline | DATETIME | computed: requested_at + policy window |
| status | ENUM('pending','scheduled','completed','cancelled') | |

### inspections
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | internal only, never exposed to the client |
| uuid | CHAR(36) UNIQUE | **client-generated**, the sync idempotency key (§3) |
| inspection_request_id | BIGINT UNSIGNED FK | |
| inspector_id | BIGINT UNSIGNED FK → users.id | |
| scheduled_at | DATETIME | |
| location_type | ENUM('factory','warehouse','port','other') | |
| location_detail | VARCHAR(255) | |
| status | ENUM('scheduled','in_progress','synced','amended','finalized','rejected') | D1's state machine |
| started_at | DATETIME NULL | |
| synced_at | DATETIME NULL | |
| finalized_at | DATETIME NULL | |
| finalized_by | BIGINT UNSIGNED FK → users.id NULL | office_reviewer/admin only |
| device_id | VARCHAR(100) NULL | for sync_log correlation |

### inspection_findings
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| inspection_id | BIGINT UNSIGNED FK | |
| checklist_item | VARCHAR(255) | |
| expected_value | VARCHAR(255) NULL | |
| observed_value | VARCHAR(255) NULL | |
| result | ENUM('pass','fail','flag') | |
| notes | TEXT NULL | |

### inspection_attachments
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| inspection_id | BIGINT UNSIGNED FK | |
| file_path | VARCHAR(255) | |
| file_type | ENUM('photo','document','other') | |
| captured_at | DATETIME | client-side timestamp |
| uploaded_at | DATETIME NULL | |
| checksum_sha256 | CHAR(64) | integrity check on upload |

### documents
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| uuid | CHAR(36) UNIQUE | |
| inspection_id | BIGINT UNSIGNED FK | |
| type | ENUM('CCI','CRF','IDR') | |
| document_number | VARCHAR(50) UNIQUE | sequential, human-facing |
| file_path | VARCHAR(255) | |
| content_hash | CHAR(64) | SHA-256 (D7) |
| hmac_signature | VARCHAR(255) | (D7) |
| issued_at | DATETIME | |
| issued_by | BIGINT UNSIGNED FK → users.id | |
| status | ENUM('draft','issued','void') | |

### compliance_tracking
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| inspector_id | BIGINT UNSIGNED FK → users.id | |
| period_month | DATE | first-of-month marker |
| missed_windows_count | INT DEFAULT 0 | |
| consecutive_miss_count | INT DEFAULT 0 | the 3-strikes trigger |
| alert_triggered | BOOLEAN DEFAULT FALSE | |
| last_evaluated_at | DATETIME | |

### statutory_returns
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| agency | ENUM('CBN','NEPC','NBS','MOF','CUSTOMS') | |
| period_start | DATE | |
| period_end | DATE | |
| file_path | VARCHAR(255) | |
| format | ENUM('pdf','csv') | (D3) |
| generated_at | DATETIME | |
| generated_by | BIGINT UNSIGNED FK → users.id | |
| submitted_at | DATETIME NULL | manually confirmed |
| submitted_by | BIGINT UNSIGNED FK → users.id NULL | |
| status | ENUM('generated','submitted') | |

### sync_log
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| record_uuid | CHAR(36) | |
| record_type | VARCHAR(50) | 'inspection', 'attachment', etc. |
| device_id | VARCHAR(100) | |
| synced_by | BIGINT UNSIGNED FK → users.id | |
| sync_status | ENUM('success','conflict','error') | |
| conflict_resolution | VARCHAR(255) NULL | |
| synced_at | DATETIME | |

### audit_log
| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK AI | |
| actor_id | BIGINT UNSIGNED FK → users.id NULL | null = system event |
| action | VARCHAR(100) | e.g. 'inspection.amend' |
| entity_type | VARCHAR(50) | |
| entity_id | BIGINT UNSIGNED | |
| before_state | JSON NULL | |
| after_state | JSON NULL | |
| ip_address | VARCHAR(45) NULL | |
| created_at | DATETIME | append-only, no updated_at |

**Indexing:** every FK column indexed; composite index on
`inspections(status, scheduled_at)` for dashboard queries; composite index on
`compliance_tracking(inspector_id, period_month)`; `documents.document_number`
unique index for lookup by physical document.

## 5. API contract (PHP REST layer)

| Method | Endpoint | Purpose |
|---|---|---|
| POST | /api/auth/login | Email/password login, issues JWT + refresh token |
| POST | /api/auth/refresh | Refresh session (used by the 7-day rule in D4) |
| GET | /api/inspections/assigned | Pull an inspector's upcoming/active inspections for offline caching |
| POST | /api/inspections/sync | Batch upload queued records; idempotent on `uuid` |
| POST | /api/inspections/{uuid}/amend | Office_reviewer/admin correction, writes audit_log |
| POST | /api/inspections/{uuid}/finalize | Locks the record, triggers document generation (D2) |
| GET | /api/documents/{uuid} | Fetch a generated document |
| POST | /api/statutory-returns/generate | Admin-triggered or cron-triggered report generation |

All endpoints behind JWT auth except `/auth/login`. Rate-limit `/auth/*` to
mitigate credential stuffing.

## 6. PWA / offline design

**IndexedDB stores (client-side):**
- `inspections_queue` — keyPath `uuid`, holds unsynced/pending records
- `attachments_queue` — keyPath `id`, blobs for photos pending upload
- `reference_cache` — checklists and assigned consignments, refreshed on
  every online session
- `auth_cache` — session token, expiry, and `pin_hash` for local offline
  verification (never the plaintext PIN)

**Service worker:** caches the app shell (HTML/CSS/JS) and reference data on
install; queues sync requests via Background Sync API where supported, with
a manual "sync now" button as the fallback for browsers without it (notably
iOS Safari).

## 7. Security (OWASP ASVS 4.0 Level 2 baseline)

- Argon2id for `password_hash` and `pin_hash`
- HTTPS-only, HSTS enabled
- PDO with prepared statements throughout — no raw query concatenation
- CSRF tokens on the admin console's state-changing forms
- Rate limiting on `/api/auth/*`
- `documents.hmac_signature` gives tamper-evidence now; if a real digital
  certificate relationship becomes available later, the `documents` table
  needs no schema change — just swap the signing method
- `audit_log` is append-only at the application layer (no UPDATE/DELETE
  grants on that table for the app's DB user)

## 8. Deployment

- PHP 8.1+, MySQL 8 / MariaDB 10.6+, Composer-managed dependencies
- PDF generation: Dompdf or mPDF (pure PHP, no shell/exec() dependency)
- Cron: nightly compliance_tracking evaluation; scheduled statutory_returns
  generation per agency's reporting cadence
- Confirm with the hosting plan before build starts: Composer/SSH access,
  cron job availability, PHP extensions (PDO MySQL, GD or Imagick, mbstring,
  intl), and storage quota for photo attachments

## 9. Open items for the next phase

- Confirm hosting plan supports the items listed in §8
- Confirm exact report layout/columns each regulator expects for the D3 CSV/PDF outputs
- Confirm the inspection checklist content (product-category-specific fields) — this drives `inspection_findings` validation rules
