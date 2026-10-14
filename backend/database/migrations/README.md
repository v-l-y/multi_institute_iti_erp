# Database baseline 000

All former migrations 001–020 are consolidated into the canonical fresh-install schema: `../schema/iti_erp_schema.sql` (106 application tables) and `../schema/integrity_guards.sql` (78 triggers). There are currently no forward SQL migrations. Historical migration files remain available in Git history; do not replay them on a fresh install.

Install both canonical files into an empty database, then review and import `../seeders/rbac_seed.sql`. Docker does this only for an empty database volume. No application database is installed, reset or migrated merely by changing repository files.

After installation, an operator may explicitly run `php bin/migrate.php --baseline-current`. This compares every table and trigger against a disposable canonical reference database and records one `000_canonical_schema` checksum entry in `iti_erp_schema_migrations`. It requires CREATE/DROP DATABASE permissions. Baseline registration preserves application records and refuses schema drift or previous migration history. Repeating it is safe. The checksum normalizes Windows/Unix line endings.

`--status` is read-only and lists future pending upgrades; “no pending migrations” does not certify that the schema matches baseline 000. `--apply` is a no-op when there are no forward files. Future migrations start at `001`, remain contiguous, and contain one validated SQL statement each. Applying future migrations requires a verified 000 ledger. Keep the baseline schema files frozen once installations are registered; subsequent changes belong in forward migrations and require a reviewed baseline/reference evolution.

Existing demo/staging databases with the former 001–020 ledger are intentionally not converted. Do not delete their history or volumes. They require a separate, reviewed transition if later reused. The obsolete `--baseline-through=008` option is removed. Back up and rehearse any populated-database upgrade; DDL auto-commits.
