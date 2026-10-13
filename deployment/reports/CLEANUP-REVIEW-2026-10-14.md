# Cleanup and repository review — 14 October 2026

Scope: repository-wide file/reference inventory and focused review of authentication/RBAC, admission, student lifecycle, academic corrections, document storage, financial transactions, migration/deployment tools and verification scripts. This is not a claim of exhaustive line-by-line or legal/regulatory certification.

## Findings requiring follow-up

1. **P1 — Database-only backup omits private documents.** ops/backup-database.sh:22 streams MariaDB SQL, while uploaded files live in a separate private volume. Restoring that backup on another machine would restore document metadata without the corresponding bytes. Implement encrypted private-volume backup paired with the database snapshot and rehearse independent restoration before relying on disaster recovery. ops/restore-drill.sh currently checks database counts only.
2. **P2 — Fresh installs cannot use the numbered upgrade chain without a manual baseline.** backend/app/Core/MigrationRunner.php:124 executes unapplied 001–008, but a fresh canonical install already contains these tables/guards and has no ledger. --apply would fail on existing objects. Add an explicitly verified baseline workflow; do not silently mark a partially upgraded database as migrated. This documented limitation remains open.
3. **P2 — Standalone local SQL suite expected the old 97-table schema.** backend/tests/mysql-runtime.test.mjs:94 expected 97 even though fresh schema now contains 99. Corrected label and assertion to 99. The opt-in local MySQL/MariaDB CLI suite was syntax-checked, not executed; the supported Docker suite verifies current schema through independent schema contracts/integration installation.
4. **P2 — Current operating guides had outdated migration guidance.** deployment/PRODUCTION.md claimed no numbered migrations existed; current schema guides also retained old counts and unexecuted-foundation wording. Updated current guidance and linked dated execution evidence. Historical reports were preserved as historical evidence.
5. **P3 — Unique test images accumulated after verification.** ops/verify-backend.ps1 removed containers/network but retained each unique image tag. Added targeted removal of only that run's image after successful container cleanup; no global prune or force removal.

## Cleanup performed

- Removed one unused PDOException import in FeeCashService; no unused PHP classes or exact duplicate PHP source files were identified by the reference/hash inventory.
- Moved three historical verification reports into deployment/reports and added a report index; retained their contents and linked them from the root README.
- Removed the labeled iti-pending-debug disposable container/network and eight unused earlier verification/debug image tags. Active application, staging, demo and their persistent volumes were retained. Other Docker projects/shared images were not pruned.
- Removed the first new verification image after its test run; the second run exercises automatic own-image cleanup in the revised runner.
- Kept the frozen project goal, required ERP modules, version-specific upgrade exporters, tests, audit history, demo records and private local credentials/settings.

## Validation

Both cleanup executions passed all 1,228 static/unit/integration checks. The final run, iti-erp-verify-e9730b3d8a3a, exited 0 and automatically removed its containers, network and own disposable image. The second execution specifically verified the revised runner's automatic image cleanup.

Additional checks: node --check on the standalone SQL test, PowerShell script parsing, git diff --check and current documentation link resolution. No automatic CI or production deployment was triggered. No changes were committed or pushed during this cleanup.

Remaining release gates include fresh migration baseline, database/private-file recovery, frontend integration, actual source applicability review, real staff acceptance and production infrastructure validation. Passing backend checks do not certify those gates.
