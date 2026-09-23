-- ADWOL PIA — production database users (Phase 9 / brief §7).
--
-- Two users, least-privilege:
--   pia_migrate  — DDL + DML on every table. Used ONLY when running
--                  `composer migrate` / `composer seed` at deploy time.
--                  Its credentials never go in the live app's .env.
--   pia_app      — the credentials the running application actually uses
--                  (DB_USERNAME / DB_PASSWORD in .env). No DDL at all — it
--                  cannot CREATE/ALTER/DROP a table, even if the app were
--                  compromised. On `audit_log` specifically it can only
--                  SELECT and INSERT — never UPDATE or DELETE — enforcing
--                  §7's "audit_log is append-only ... no UPDATE/DELETE
--                  grants on that table for the app's DB user" at the
--                  database layer, not just in App\Audit\AuditLog (Phase 5).
--
-- Replace <db_name>, <migrate_password>, and <app_password> before running.
-- On shared/cPanel hosting that only allows one MySQL user per database,
-- skip the pia_migrate/pia_app split and grant pia_app everything instead —
-- weaker, but audit_log's UPDATE/DELETE restriction below is still worth
-- keeping even in that case if the panel allows column/table-level grants.
--
-- Run as a MySQL user with GRANT privileges (root, or whatever the host
-- gives you), from the database host, e.g.:
--   mysql -u root -p < db/grants.sql

-- --- Deploy-time migration user -------------------------------------------
CREATE USER IF NOT EXISTS 'pia_migrate'@'localhost' IDENTIFIED BY '<migrate_password>';
GRANT ALL PRIVILEGES ON <db_name>.* TO 'pia_migrate'@'localhost';

-- --- Runtime application user ----------------------------------------------
CREATE USER IF NOT EXISTS 'pia_app'@'localhost' IDENTIFIED BY '<app_password>';

-- Full DML on every table except audit_log (listed explicitly — safer than a
-- blanket grant + a REVOKE, which would briefly leave the wider grant live).
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.users                        TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.refresh_tokens               TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.rate_limits                  TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.clients                     TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.consignments                TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.consignment_trade_details   TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.inspection_requests         TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.inspections                 TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.inspection_findings         TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.inspection_attachments      TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.inspection_shipment_details TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.sync_log                    TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.documents                   TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.document_sequences          TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.company_settings            TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.operational_settings        TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.record_attachments          TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.password_resets             TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.user_recovery_codes         TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.compliance_tracking         TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.statutory_returns           TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.cbn_invoices                TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.invoice_sequences           TO 'pia_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON <db_name>.invoice_settings            TO 'pia_app'@'localhost';
GRANT SELECT                         ON <db_name>.phinx_migrations            TO 'pia_app'@'localhost';

-- audit_log: append-only. No UPDATE, no DELETE — matches §7 exactly.
GRANT SELECT, INSERT ON <db_name>.audit_log TO 'pia_app'@'localhost';

FLUSH PRIVILEGES;

-- Verify afterwards with, e.g.:
--   SHOW GRANTS FOR 'pia_app'@'localhost';
-- and confirm UPDATE/DELETE are absent for audit_log, and every *_PRIV for
-- DDL (CREATE, ALTER, DROP, INDEX, REFERENCES) is absent entirely.
