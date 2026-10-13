# Database Migrations

This directory is for reviewed, numbered **forward-only** upgrades to populated ERP databases. Fresh installation uses `../schema/iti_erp_schema.sql` and `../schema/integrity_guards.sql` instead. No migration SQL files have been added by the architecture refactor, so applying now makes no schema changes.

Run from `backend/`:

```sh
php bin/migrate.php --status
php bin/migrate.php --apply
```

The default `--status` is read-only. `--apply` explicitly acquires a database advisory lock, checks that the canonical `institute` table exists, checks applied checksums, and records a successful migration in `iti_erp_schema_migrations`. File names must be `001_description.sql`, `002_description.sql` etc., unique three-digit prefixes, with **one SQL statement per file**; procedure delimiters/batches are rejected. Do not rename or modify an applied file.

MySQL/MariaDB DDL can auto-commit: if applying a statement succeeds but tracking fails, or a partial upgrade fails, stop and reconcile manually from a verified backup. The runner does not reset, seed, recreate, roll back or automatically retry a live database. Test new upgrades against an isolated copy first.
