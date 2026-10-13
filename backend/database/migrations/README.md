# Database migrations

Migrations 001–008 upgrade the previous canonical 97-table schema to 99 application tables and 74 triggers, plus the migration ledger. They add private documents and immutable academic correction history. They are not a universal upgrade for older legacy databases.

Run `php bin/migrate.php --status` for read-only status; use `--apply` only after backup and rehearsal on a restored copy. The integration suite rehearses the populated previous schema upgrade and verifies repeat application is a no-op. Applied files must never be modified or renamed.

Fresh installation uses the canonical schema and integrity guards, which already contain these objects. Do not replay 001–008 on a fresh installation. Before future numbered upgrades, fresh installations currently require an operator-reviewed migration ledger baseline. Never baseline a partially upgraded database.

MariaDB DDL auto-commits. Failed DDL tracking requires manual reconciliation; the runner does not reset or roll back a populated database. Files must contain exactly one statement with contiguous numbered prefixes. Schema preflight proves object presence, not complete compatibility.
