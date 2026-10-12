# Bihar Multi-Institute ITI ERP — Schema

[iti_erp_schema.sql](iti_erp_schema.sql) defines the institute data model and the integrity triggers. Its purpose is **management of multiple Bihar CTS ITIs**; it does not issue or replace official DGT/State/NCVT-MIS/SIDH/NIMS approvals, results, certificates, or references.

## What is actually checked in the current schema

- An enrollment's verified qualification belongs to that student and institute.
- Attendance session, batch year, and enrollment belong to the **same** batch.
- Practical marks, eligibility and practical session share the exam cycle and batch year.
- Official results, certificates and their previous revisions share the enrollment.
- ITSU instructor mapping, Seat Matrix entry and batch use the appropriate unit/shift and intake.
- Seat Matrix version supersession stays within the same intake.
- An ITSU unit/shift may have separate dated affiliation versions but only one open-ended version; role reassignments and daily-attendance revisions retain history with only one current record.
- Calendar rows record an official source ID and its revision identifier.
- INSERT/UPDATE triggers require reviewed, non-draft, non-withdrawn regulatory source records for published/approved governance rows. A batch requires a final, admission-allowed Seat Matrix entry with sufficient seats at creation or relevant change.

## Installation — new, empty database only

Run with a **MySQL CLI client**. The SQL file includes `DELIMITER` and `CREATE TRIGGER`, so it is not valid as a single PDO query.

```sh
mysql -u USER -p -e "CREATE DATABASE multi_institute_iti_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u USER -p multi_institute_iti_erp < database/schema/iti_erp_schema.sql
node --test tests/schema-contracts.test.mjs
```

The included Node test is a **static contract check**, not proof that MySQL executed every table and trigger. A database import, negative insert testing and repeatable migration test must pass in MySQL before acceptance.

**Do not run this create-only schema over existing populated data.** The new non-null identity columns require a reviewed, data-preserving migration for live tables. Do not delete old institute data or manufacture official source evidence.

## Residual work / not automatically solved by SQL

- The official curriculum and regulatory source tables are currently institute-scoped. Consolidating the same DGT document/syllabus into a platform-global governed version master requires a separate migration plus downstream API changes; do not silently repoint history.
- Closing a previous affiliation or role period and starting a newer one needs **transactional overlap checks** for finite date ranges. The unique open-ended guards alone do not enforce every possible overlap.
- Published official source supersession, withdrawal, time applicability, official-seat-matrix currency, and all regulator-specific eligibility calculations require backend authorization/workflow checks. The triggers validate status at the relevant write; they do not recertify history after official changes.
- Marks, attendance, OJT, payments and exam eligibility require reconciliation against actual operational records, and multitenant RBAC/tenant scoping require runtime tests.
- Training calendar revision identifiers are recorded; full immutable schedule edit history is not implemented by this file.
- There is no verified MySQL 9.7 test run in this review. Do not claim zero gaps, official approval, or production readiness.

Earlier README references to a `database/mysql_bihar` generator/installer could not be matched to files in the current repository and have been removed from these instructions. If this generated artifact has an external source-of-truth pipeline, update that pipeline too before regenerating it.
