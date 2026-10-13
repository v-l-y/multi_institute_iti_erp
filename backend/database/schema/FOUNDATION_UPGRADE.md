# Bihar institute foundation hardening

This change adds source-aware internal services and database protection for institute management. It does not create government authority accounts or submit official data. Selected institute-scoped admission, exam and fee HTTP adapters were subsequently added; they call the existing guarded services. No additional permission grants are seeded.

## What changed

- Admission activation checks a reviewed qualification route, source-backed admission route evidence, reviewed affiliation/curriculum/window, final Seat Matrix, verified instructor, capacity and quotas. It serializes institute operations and reconciles counters from enrollment rows. Pending drafts reserve no seat; active/completed/transferred/dropout admissions consume this intake's seats until an explicit cancellation/correction. Cancellation of completed/transfer/dropout records is refused rather than silently re-opening seats.
- DST agreements have industry identity, evidence, validity and same-trade/same-institute unit coverage. Activation rejects expired agreements and excess third-shift units relative to the larger first/second-shift base. Portal expiry handling still belongs to the official authority; this ERP blocks unsupported local activation.
- EWS can be unknown (`NULL`). Historical false values remain untouched: verify them from evidence rather than bulk converting them. Existing admissions retain `unverified` route until verified evidence is supplied.
- Exam freezing computes attendance and formative scores from complete locked records reconciled to a reviewed calendar. It selects the published rule, checks PRN/fee requirements and relaxation authority/cap, and snapshots thresholds. Contradictory eligible percentages are also blocked by row CHECKs. Exam eligibility records are immutable; corrective exam versions/import workflows remain explicit future work.
- Fee writes use an institute-row mutex in one transaction: exact decimal cents, immutable receipts, retry fingerprints, payment/demand allocation limits, cumulative pending/approved/paid refund reservations, independent approval, refund/reversal conflict protection and waiver limits. Unallocated credit is not restored by a cash refund. Reversal requires an approver distinct from the original payment recorder. A separate multi-step reversal request UI is not implemented.
- `institute_activity_log` records the authenticated same-institute actor in the business transaction. Audit failure rolls back the business mutation. Original external signatory text is preserved in historic evidence fields.
- `integrity_guards.sql` protects published rule/document content, final Seat Matrix entries, issued assessment items, activated structure items, receipts/allocations/reversals/cash snapshots, results/certificates and locked academic records. It also rejects attendance over session duration and scores over template maxima. Selected lifecycle transitions such as published to superseded remain possible without rewriting content. A frozen eligibility cannot be edited in place.
- Trainee payload validation uses an explicit reviewed calendar upload profile, preserves unknown EWS/disability values and validates required fields, allowed values, dates, portal shifts, age and export-batch duplicates. It is a local preparation tool, not a government upload client. Official portal duplicate checks against already uploaded state records must still be performed by the authorized state workflow.

## Source-backed configuration

`global_admission_rule_versions.qualification_routes_json` uses a reviewed route-code map with `level_codes` and, where required, `minimum_subject_marks`. Qualification subject results map those source-backed subjects to numeric results. No route, school qualification or threshold is seeded here.

`cts_training_calendars.upload_profile_json` must be reviewed against the applicable published calendar/annexure. Supply `genders`, `categories`, `qualifications`, `trainee_types`, `pwd_categories`, `portal_shifts`, `maximum_unit`, `session_code`, `admission_opens_on`, `admission_closes_on`, `age_cutoff_date`, `minimum_age_years`, and `strict_age_boundary`; also supply local-to-portal `gender_map`, `category_map`, `qualification_map`, `trainee_type_map`. Do not resolve conflicting checklist/sample values or later extensions by guessing. Unconfigured profiles fail closed.

Fee heads default to requiring enrollment; only verified pre-enrollment registration charges should set `requires_enrollment=0`. A fee structure is institute configuration, not a government fee order.

## Boundaries

Supported engine: Docker MariaDB 10.11. The base DDL has 97 tables; load both `iti_erp_schema.sql` and `integrity_guards.sql` for a fresh database. Docker empty-volume initialization loads both; PHP integration tests use `SchemaInstaller`.

The SQL record guards do not independently enforce all financial sums, source applicability or admission counters. All application business writes must use `AdmissionService`, `FeeService` and `EligibilityService`, rather than direct SQL. Internal services require an authenticated `AuthContext`, matching institute and permission. They do not accept a request-created context. Direct database administration can bypass service rules and must remain controlled. The mutex is deliberately coarse; concurrent operations in one institute serialize. Lock timeout/deadlock errors roll back and must be retried with the same retry identity by a future API.

This foundation does not certify official source content, implement every approval/correction UI, validate all Bihar private admission policies, or replace official result/certificate issuance. Services and guards are newly added and have not been executed by the agent. Prior passing test output predates this change.

## Local tests first

From the project root:

```powershell
node --test backend/tests/schema-contracts.test.mjs
docker compose -f compose.test.yaml up --build --abort-on-container-exit --exit-code-from tests
docker compose -f compose.test.yaml down
```

The expanded Docker suite includes source/admission rejection, successful activation and cancellation counters, frozen-record negative cases, valid exam freezing, fee allocation/refund/retry limits, audit identity, a second-connection lock timeout/rollback, and upload validation. These are disposable fictional fixtures, not official approvals. Review test output before upgrading the main database.

## Existing 94-table database upgrade

Do not reset the volume or import the full fresh schema. Back up first. Existing nonempty exam eligibility requires reviewed historical threshold backfill; the exporter refuses it rather than inventing values. It also refuses pre-existing foundation tables or triggers, to avoid blindly repeating partial DDL.

Example backup (contains private institute data; the `backups` directory is ignored by Git):

```powershell
New-Item -ItemType Directory -Force backups | Out-Null
docker compose exec -T database sh -c 'mariadb-dump --single-transaction --routines --triggers --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE" > /tmp/foundation-backup.sql'
if ($LASTEXITCODE -ne 0) { throw 'Backup failed' }
docker compose cp database:/tmp/foundation-backup.sql ./backups/foundation-backup.sql
if ($LASTEXITCODE -ne 0) { throw 'Backup copy failed' }
```

Rebuild the backend and display the upgrade SQL for review:

```powershell
docker compose up -d --build --wait
docker compose exec -T backend php bin/export-foundation-upgrade.php
```

Apply the reviewed upgrade once, preserving the exporter's exit status:

```powershell
$foundationSql = docker compose exec -T backend php bin/export-foundation-upgrade.php
if ($LASTEXITCODE -ne 0) { throw 'Upgrade preflight refused; inspect the message' }
$foundationSql | docker compose exec -T database sh -c 'exec mariadb --user="$MARIADB_USER" --password="$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
if ($LASTEXITCODE -ne 0) { throw 'Upgrade partially failed; inspect before any retry' }
docker compose exec -T backend php bin/verify-foundation.php
docker compose exec -T backend php bin/verify-fee-schema.php
```

MariaDB DDL auto-commits. A failure requires inspection/restoration or completion of reviewed remaining statements, not a blind retry. No existing roles, permissions, students, payments or official records are deleted or guessed by this upgrade. The read-only verifier checks object presence; integration tests establish behavior in the disposable database.

## Official applicability references

- [DGT Affiliation Norms 2025](https://dgt.gov.in/en/node/3975), especially DST chapter 4.
- [DGT CTS-2026 calendar and upload annexures](https://dgt.gov.in/en/node/4394); apply the appropriate revision/extension.
- [DGT Exam Corner](https://dgt.gov.in/en/exam-corner) for the applicable exam cycle and orders.
- [Bihar BCECEB ITICAT notices](https://bceceboard.bihar.gov.in/ITICATIndex.php); apply counselling rules only to the applicable institute/admission route.
