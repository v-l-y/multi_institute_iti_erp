# Canonical final schema

Use [iti_erp_schema.sql](iti_erp_schema.sql), generated from the six immutable Bihar migration sources. It includes the core schema, institute access provisioner, empty safe seed, exam/refund integrity, sourced methods, exact Bihar scope and checksum migration ledger. One database layout; no separate access SQL import is needed.

Target: fresh MySQL 9.7 LTS, tested build 9.7.2. Select a NEW EMPTY database in the MySQL client before importing. No hardcoded database USE directive is included. This is not a populated-data migration. Alternatively use the checksum installer described in [../mysql_bihar/README.md](../mysql_bihar/README.md); both routes are verified to converge structurally.

`../mysql_bihar/build_final_schema.py` regenerates the file. Do not edit generated SQL or applied migrations. Project Docker verification includes a stale-file check and compares columns, indexes, foreign keys, triggers, views and routines between both install routes.

Historical files under `historical/` are comparison/reference inputs only. Do not use old PHP installers, RBAC or demo seeds with this model. No API/frontend compatibility, actual institute approval, official policy completeness or production readiness is certified.
