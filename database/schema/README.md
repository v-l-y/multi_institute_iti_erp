# Bihar Multi-Institute ITI ERP — Database Schema

[iti_erp_schema.sql](iti_erp_schema.sql) is the **new, empty-database schema** for managing Bihar CTS ITI institutes. This ERP records institute operations and references official authority records; it does **not** grant affiliation, approve a government Seat Matrix, issue official results or certificates, or change government admission rules.

## Scope and source of truth

Only model data needed for (1) Bihar institute administration or (2) the applicable official DGT/NCVET/Bihar State authority documents, CTS trade syllabus, affiliation, admission and training calendar.

- **Shared official masters:** `global_regulatory_documents`, `trades` with `trade_master_revisions`, `global_shifts`, `global_curriculum_*`, `global_admission_rule_versions`, `global_reservation_policies`, `global_exam_rule_versions`, `cts_training_calendars`, `cts_training_calendar_milestones`, and `cts_portal_shift_mappings`. Official rules, dates and amounts must be loaded from real applicable documents; do not invent default regulatory values.
- **Institute-managed references and records:** `institute`, `institute_affiliation_history`, `regulatory_sources` (institute-to-global document references, not duplicate documents), `trade_units`, `trade_unit_shifts`, local curriculum references, staff, instructors, ITSU, intakes, seat matrix versions/entries, batch years, students, enrollments, attendance, formative/OJT, examinations, fee ledgers, assets, portal evidence and permissions.
- **Revision history:** trade master, institute affiliation, admission window, Seat Matrix, curriculum and CTS calendar history have versioned tables. **Published-record immutability and regulatory status validations are deferred** until the DATA-INTEGRITY GUARDS are implemented.
- **Multiple institutes:** operational foreign keys generally include `institute_id`. Shared masters do not become editable institute-specific rules.

## Current schema relationships (DATA-INTEGRITY GUARDS deferred)

- Local syllabus references point to a global CTS curriculum, curriculum year, LO and resource requirement. Institute rows do not duplicate the official text, hours or tool specifications.
- Affiliated physical shifts are separate from official portal shift codes, stored by calendar and intake session in `cts_portal_shift_mappings`. No globally hardcoded Session-II portal shift arithmetic is used.
- ITSU, seat matrix, instructor identity, admission rule, batch, batch year, curriculum and student enrollment have tenant-aware and identity-aware foreign keys.
- Foreign keys retain official source-document relationships, but the SQL currently **does not prevent** draft/unreviewed source publication, published-row rewriting, status rollback or final Seat Matrix entry edits. These require future integrity guards.
- A batch references its institute, ITSU, intake, Seat Matrix entry, rule and calendar with relational foreign keys. Eligibility, approval status, applicable affiliation and source verification are **not yet enforced by triggers**.
- `enrollments.admission_intake_revision_id` and `enrollments.intake_id` link to the recorded intake revision. Admission-window effective-date and revision-status validation are **deferred**.
- `batches.occupied_seats` is a stored counter with a `CHECK` constraint against capacity. **It is not automatically synchronized with enrollments** until the deferred integrity logic is added.
- Instructor/staff, attendance, assessments, OJT, exams, results, certificates and payment records retain tenant-scoped foreign keys and defined `CHECK`/`UNIQUE` constraints. Cross-record workflow validation must be added later.

## Important operational boundaries

**No DATA-INTEGRITY GUARDS triggers are present.** Source approval checks, published-row immutability, date-overlap prevention, Seat Matrix publication checks, admission-window verification and seat-counter reconciliation are all deferred. Applications must not assume these validations are enforced by this DDL. Database `FOREIGN KEY`, `UNIQUE` and `CHECK` constraints remain in place.

Workflows must select the applicable official source and preserve historic evidence. Application roles/authorization, tenant access, auditability, source evidence accuracy, Seat Matrix currentness, occupancy and attendance/marks reconciliations are **not certified by this schema alone**.

Some of the latest schema changes are **breaking for existing populated databases and callers**, including new non-null `enrollments.intake_id` and `enrollments.admission_intake_revision_id`. Existing data needs a reviewed, data-preserving migration and caller integration; do not silently fill official references or fabricate historical orders.

## Installation — fresh, empty database only

The SQL currently contains table definitions and structural constraints only; the `DATA-INTEGRITY GUARDS`, `DELIMITER` directives and all `CREATE TRIGGER` statements have been removed for later implementation.

```sh
mysql -u USER -p -e "CREATE DATABASE multi_institute_iti_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u USER -p multi_institute_iti_erp < database/schema/iti_erp_schema.sql
```

**Do not run the create-only SQL directly on a populated database.** This schema has not been certified by a MySQL runtime import/negative-data test in this review. Structural inspection of text, foreign keys and trigger references is **not** runtime verification and must not be described as “0 gaps,” regulatory approval or production readiness.

The repository may have external generator or migration sources not present here; if so, align those upstream sources before regenerating the SQL.
