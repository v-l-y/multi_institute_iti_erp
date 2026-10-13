# Database Migrations

This directory is for reviewed, numbered **forward-only** upgrades to populated ERP databases. Fresh installation uses `../schema/iti_erp_schema.sql` and `../schema/integrity_guards.sql` instead. No reviewed numbered SQL migrations are installed yet. `--apply` therefore fails closed rather than reporting a misleading successful no-op.

Run from `backend/`:

```sh
php bin/migrate.php --status
php bin/migrate.php --apply
```

The default `--status` is read-only. `--apply` explicitly acquires a database advisory lock, checks that the canonical `institute` table exists, checks applied checksums, and records a successful migration in `iti_erp_schema_migrations`. File names must be `001_description.sql`, `002_description.sql` etc., unique three-digit prefixes, with **one SQL statement per file**; procedure delimiters/batches are rejected. Do not rename or modify an applied file.

MySQL/MariaDB DDL can auto-commit: if applying a statement succeeds but tracking fails, or a partial upgrade fails, stop and reconcile manually from a verified backup. The runner does not reset, seed, recreate, roll back or automatically retry a live database. Test new upgrades against an isolated copy first.

Before every populated-database upgrade, run the read-only `php bin/schema-preflight.php` against an isolated restored copy and reconcile missing tables and integrity triggers. Its object-presence checks do not prove complete schema compatibility. There are still **no numbered SQL migrations** in this folder: existing one-time upgrade exporters require individual review, cannot be blindly replayed, and do not replace a complete migration history.

**Safety behavior:** `--apply` now fails closed when no reviewed SQL migration files exist; `--status` remains read-only. Migration filenames must form a contiguous `001_...`, `002_...` sequence with no skipped prefix. These checks protect against an accidental green no-op; they do not themselves upgrade populated legacy databases. Exporter SQL and existing data still require reviewed migration authoring and an isolated restore rehearsal.
