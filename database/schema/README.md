# Bihar Multi-Institute ITI ERP — Database Schema

[iti_erp_schema.sql](iti_erp_schema.sql) is the **new, empty-database schema** for managing Bihar CTS ITI institutes. This ERP records institute operations and references official authority records; it does **not** grant affiliation, approve a government Seat Matrix, issue official results or certificates, or change government admission rules.

## Scope and source of truth

Only model data needed for (1) Bihar institute administration or (2) the applicable official DGT/NCVET/Bihar State authority documents, CTS trade syllabus, affiliation, admission and training calendar.

- **Shared official masters:** `global_regulatory_documents`, `trades` with `trade_master_revisions`, `global_shifts`, `global_curriculum_*`, `global_admission_rule_versions`, `global_reservation_policies`, `global_exam_rule_versions`, `cts_training_calendars`, `cts_training_calendar_milestones`, and `cts_portal_shift_mappings`. Official rules, dates and amounts must be loaded from real applicable documents; do not invent default regulatory values.
- **Institute-managed references and records:** `institute`, `institute_affiliation_history`, `regulatory_sources` (institute-to-global document references, not duplicate documents), `trade_units`, `trade_unit_shifts`, local curriculum references, staff, instructors, ITSU, intakes, seat matrix versions/entries, batch years, students, enrollments, attendance, formative/OJT, examinations, fee ledgers, assets, portal evidence and permissions.
- **Revision history:** trade master, institute affiliation, admission window, Seat Matrix, curriculum and CTS calendar history are retained by dated versions. Final/official published versions have SQL write guards; corrections must use a new official version rather than in-place rewriting.
- **Multiple institutes:** operational foreign keys generally include `institute_id`. Shared masters do not become editable institute-specific rules.

## Latest schema-level integrity behavior

- Local syllabus references point to a global CTS curriculum, curriculum year, LO and resource requirement. Institute rows do not duplicate the official text, hours or tool specifications.
- Affiliated physical shifts are separate from official portal shift codes, stored by calendar and intake session in `cts_portal_shift_mappings`. No globally hardcoded Session-II portal shift arithmetic is used.
- ITSU, seat matrix, instructor identity, admission rule, batch, batch year, curriculum and student enrollment have tenant-aware and identity-aware foreign keys.
- Publishing/finalizing official documents, curriculum, calendars, shifts and policy versions requires a linked reviewed official source. Published government policy facts, syllabus details, calendar milestones and final Seat Matrix entries cannot be edited/deleted in place; transitions from published/final/active status back to draft are blocked.
- Creating or materially changing a batch requires a final admission-allowed Seat Matrix entry, capacity within both official matrix and applicable affiliation, an approved ITSU, the correct mode/curriculum, reviewed admission intake revision and an institute affiliation valid on the batch start date.
- `enrollments.admission_intake_revision_id` and `enrollments.intake_id` identify the exact official intake and its reviewed revision. Old superseded windows can support historical admissions only when their successor was published after the recorded admission date; unrelated updates to historic enrollments are not revalidated against new windows.
- `batches.occupied_seats` starts at zero. Row triggers reserve a seat atomically when a non-cancelled enrollment is inserted/reinstated/transferred in, and release it on cancellation or a qualifying change/delete. **Conservative accounting:** completed, transferred and dropout records remain counted; this does not by itself authorize a new government admission or a vacated-seat replacement.
- Instructor/staff, attendance, assessments, OJT, exam results, certificate and payment records retain tenant-scoped references and revision/transaction checks.

## Important operational boundaries

The seat counter is maintained by enrollment triggers **when writes follow the intended schema path**. Applications should not directly edit `batches.occupied_seats`; restrict such privileges and reconcile counter vs. actual enrollment rows during an eventual runtime review. Official cancellation/refill authorization is a separate source-governed decision, not inferred from the counter.

Freezing a regulatory row does not automatically re-certify every historical record after later government orders; workflows must select the applicable official source and preserve recorded evidence. Application roles/authorization, tenant access, auditability, source evidence accuracy, qualifying seat-matrix publication, attendance/marks reconciliations and reconciliation of portal values are outside what DDL alone can prove.

Some of the latest schema changes are **breaking for existing populated databases and callers**, including new non-null `enrollments.intake_id` and `enrollments.admission_intake_revision_id`. Existing data needs a reviewed, data-preserving migration and caller integration; do not silently fill official references or fabricate historical orders.

## Installation — fresh, empty database only

Use the MySQL CLI because this script contains `DELIMITER` directives and `CREATE TRIGGER` statements.

```sh
mysql -u USER -p -e "CREATE DATABASE multi_institute_iti_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u USER -p multi_institute_iti_erp < database/schema/iti_erp_schema.sql
```

**Do not run the create-only SQL directly on a populated database.** This schema has not been certified by a MySQL runtime import/negative-data test in this review. Structural inspection of text, foreign keys and trigger references is **not** runtime verification and must not be described as “0 gaps,” regulatory approval or production readiness.

The repository may have external generator or migration sources not present here; if so, align those upstream sources before regenerating the SQL.
