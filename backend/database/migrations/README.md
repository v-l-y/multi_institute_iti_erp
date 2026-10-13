# Database migrations

The previous canonical 97-table schema upgrades via 001–008 to the document/correction baseline (99 tables, 74 triggers). Migrations 009–014 add instructional training (101 application tables, 78 triggers). The separate iti_erp_schema_migrations ledger adds one installed table. These are reviewed additive upgrades, not a universal legacy database conversion.

Read-only status: php bin/migrate.php --status. Explicit upgrades: --apply. Back up and rehearse first; DDL auto-commits, so a partial failure requires manual reconciliation. Never change applied files/checksums.

Fresh current canonical installs already contain all migration objects. Use php bin/migrate.php --baseline-current before --apply. The baseline compares every application table's SHOW CREATE TABLE and trigger body against a disposable canonical install with matching database defaults, rejecting schema drift or missing guards. It records checksums only after validation. It requires operator permissions to create/drop its randomly named disposable reference database; it never resets application tables or data.

For the previously installed canonical 99-table document baseline without a ledger, explicitly use --baseline-through=008, then --apply to install 009–014. Earlier-prefix reconstruction is supported only when later migrations are additive CREATE TABLE/TRIGGER operations, and occurs only in the disposable reference database. Unsupported reverse operations fail closed. Do not baseline a partially applied or unreviewed legacy upgrade.

Files use contiguous three-digit prefixes and one SQL statement. A narrowly validated single conditional immutable-status CREATE TRIGGER body is supported; trailing SQL batches remain rejected. Runtime application credentials have no DDL privileges and cannot perform maintenance.
