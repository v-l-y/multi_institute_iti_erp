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

## Authentication — five application roles

The SQL schema now uses **one shared authentication model**, not five separate login systems, with **11 existing `auth_*` tables** (no extra authentication tables).

| Role code | Scope | Identity requirement | Intended data boundary |
| --- | --- | --- | --- |
| `developer` | Platform | Global account (`auth_users.institute_id IS NULL`) | ERP technical administration; not a regulatory-body identity |
| `principal` | Institute | Same-institute `auth_staff_links` record | Institute-level management |
| `administrator` | Institute | Institute account; staff link optional | Institute administration according to assigned permissions |
| `instructor` | Institute | Same-institute `auth_staff_links` record | Only assigned instructional records |
| `student` | Institute | Same-institute `auth_student_links` record | Only the student's own records |

- The seeded canonical role codes are `developer`, `principal`, `administrator`, `instructor`, `student`. The old `administration` code is no longer used in new schemas.
- `auth_roles.scope` and the matching role-assignment columns structurally separate platform from institute roles. Platform accounts do not need a synthetic institute. `auth_users.tenant_key` and `auth_user_roles.tenant_key` are stored/generated values used for composite foreign keys; this also prevents assigning a role in the wrong institute.
- `auth_staff_links` and `auth_student_links` precede role assignments so required Principal/Instructor/Student role links can be enforced by foreign keys and `CHECK` constraints.
- Sessions, password resets and audit entries can be platform-scoped or institute-scoped; token hashes are unique across institutes. Expiry, revocation, password verification, MFA, rate limits, login lockouts and logout must be handled by the authentication service.
- Only **role identifiers** are seeded. No account credentials, users or arbitrary permissions are seeded. Without an authorized application policy and permission grants, access must be **denied by default**.
- Checks that require inspecting another staff/business row—e.g. Principal role implies staff designation Principal, Instructor access only to assigned batches, Student access only to own records, who can grant Administrator or Developer roles, and detailed principal/administrator permission boundaries—need middleware/application authorization. This SQL intentionally has **no triggers or DATA-INTEGRITY GUARDS**.
- `auth_user_permissions` remains institute-scoped, so a platform Developer cannot be silently treated as a tenant user through a personal grant. `auth_user_roles.granted_by` records the issuing account; it does not alone prove that account had grant authority.
- Login by username for institute-scoped accounts must also select/resolve the institute, because username uniqueness is per scope. This repo's database schema is **not** an implemented authentication HTTP service.

**Existing-data note:** Changing `auth_users.institute_id` to nullable, generated tenant FKs, moving `auth_user_roles` after subject-link tables and renaming the role code require a controlled migration for populated databases. Do not execute this create-only SQL on existing data.

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
