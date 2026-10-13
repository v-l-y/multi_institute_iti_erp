# Bihar Multi-Institute ITI ERP — Database Schema

[iti_erp_schema.sql](iti_erp_schema.sql) is the **new, empty-database schema** for managing Bihar CTS ITI institutes. This ERP records institute operations and references official authority records; it does **not** grant affiliation, approve a government Seat Matrix, issue official results or certificates, or change government admission rules.

## Scope and source of truth

Only model data needed for (1) Bihar institute administration or (2) the applicable official DGT/NCVET/Bihar State authority documents, CTS trade syllabus, affiliation, admission and training calendar.

- **Shared official masters:** `global_regulatory_documents`, `trades` with `trade_master_revisions`, `global_shifts`, `global_curriculum_*`, `global_admission_rule_versions`, `global_reservation_policies`, `global_exam_rule_versions`, `cts_training_calendars`, `cts_training_calendar_milestones`, and `cts_portal_shift_mappings`. Official rules, dates and amounts must be loaded from real applicable documents; do not invent default regulatory values.
- **Single Staff Master:** `staff` includes teaching and non-teaching employees, with a `staff_category`, free-text `designation` and a separate `principal_assignment` (`none`, `principal`, `acting_principal`). No separate staff/administration masters are needed. A staff designation is not an ERP login role; many employees need no login. `active_principal_guard` enforces at most one current Principal or Acting Principal assignment per institute (it does not establish who is authorized to appoint them).
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

## Reviewed schema design choices (October 2026)

- **NIMS/IPRN identity:** `instructor_identities.iprn` is the one stored instructor registration number. `staff.identity_id` references it and a verified staff record must have that identity plus a verification reference/date. `instructor_mappings` has a composite staff/identity/institute foreign key so non-null instructor identities cannot silently differ from the staff record. No duplicate `staff.iprn` column remains.
- **Exam rule provenance:** `global_exam_rule_versions` requires explicit values for PRN, CBT fee, practical fee, OJT certificate requirement and the principal relaxation cap. The schema does not invent universal TRUE/zero defaults; load each value from the applicable official order.
- **Registration fees:** An institute trainee/student record can exist before an enrollment. `fee_transactions.student_id` is mandatory; `enrollment_id` is optional **only for registration** records. When provided, the enrollment must belong to the same student and institute; registration refunds/reversals refer to the same student. This is not a separate applicant master, fee master or automated payment workflow.
- **Institute directory:** `district_name`, `management_type`, `contact_phone` and `contact_email` are optional institute-management fields; no invented administrative classification or district code list was imposed.
- **Issuing authority vs source portal:** `global_regulatory_documents.authority` identifies the issuing authority (DGT, NCVET, Bihar State/SSDEC when applicable). `source_system` records NIMS/SIDH/NCVT-MIS when a record was obtained through an official portal, not as a separate rule-making authority.
- **Instructor mapping scope remains source-dependent:** The existing uniqueness of active NIMS instructor and staff mappings is unchanged until the applicable current NIMS rule is checked for each scenario. Active mappings now require a matching Staff IPRN identity; this does not independently establish assignment approval.
- **Formative assessment representations:** `formative_assessments` stores learning-outcome assessments; `formative_template_items`/`formative_template_scores` support a source-backed weighted template only where applicable. `batch_years.formative_method` and `formative_scheme_source_id` identify the selected method and supporting source. Use neither mode with invented default weights; cross-mode reconciliation is deferred along with DATA-INTEGRITY GUARDS.

## Enforced schema relationships — P1 review follow-up

These changes use only existing tables, composite foreign keys and `CHECK` constraints; **no DATA-INTEGRITY GUARDS, triggers, or independent regulatory rules were added**.

- **Instructor NIMS identity:** `instructor_mappings.status='active'` requires non-NULL `identity_id`. The existing composite foreign key `(staff_id,identity_id,institute_id)` ensures it is the exact instructor identity recorded on that staff member. Historical released/revoked mappings can still have no identity.
- **Seat Matrix portal-shift mapping:** `seat_matrix_entries` records `calendar_id`, `session_part` and `physical_shift_no`. Composite keys verify that (a) the calendar/session are from the referenced `admission_intakes`, (b) the physical shift is from the referenced `trade_unit_shifts`, and (c) the resulting `portal_shift_no` is an actual `cts_portal_shift_mappings` row for that same calendar/session/shift. Consumers must supply these actual matching values; they are not defaulted or derived from guessed arithmetic.
- **Reservation quota type:** `enrollments.vertical_quota_type` and `horizontal_quota_type` have fixed technical defaults and matching `CHECK` constraints. Each nullable quota ID uses the corresponding batch and type in a composite FK to `batch_quotas`. The constant type columns are technical FK discriminators, not extra admission policy or seat-category authority.
- **Payment balances:** `fee_payment_balances.payment_transaction_type` is fixed to `payment` by `CHECK`; the composite FK to `fee_transactions(id,transaction_type,institute_id)` prevents a balance from referencing a `refund` or `reversal`. Refunded amounts and balance reconciliation still require a later transaction-safe workflow.

**Compatibility:** These are updates to the fresh-install schema. Newly required `seat_matrix_entries` identity fields and extra foreign keys need a verified data-preserving migration for already populated databases.

## Authentication — five application roles

The SQL schema now uses **one shared authentication model**, not five separate login systems, with **11 existing `auth_*` tables** (no extra authentication tables).

| Role code | Scope | Identity requirement | Intended data boundary |
| --- | --- | --- | --- |
| `developer` | Platform | Global account (`auth_users.institute_id IS NULL`) | ERP technical administration; not a regulatory-body identity |
| `principal` | Institute | Same-institute `auth_staff_links` record | Institute-level management |
| `administrator` | Institute | Same-institute `auth_staff_links` record | Institute administration according to assigned permissions |
| `instructor` | Institute | Same-institute `auth_staff_links` record | Only assigned instructional records |
| `student` | Institute | Same-institute `auth_student_links` record | Only the student's own records |

- The seeded canonical role codes are `developer`, `principal`, `administrator`, `instructor`, `student`. The old `administration` code is no longer used in new schemas. The Staff Master `principal_assignment` is independent of the login role.
- `auth_roles.scope` and the matching role-assignment columns structurally separate platform from institute roles. Platform accounts do not need a synthetic institute. `auth_users.tenant_key` and `auth_user_roles.tenant_key` are stored/generated values used for composite foreign keys; this also prevents assigning a role in the wrong institute.
- `auth_staff_links` and `auth_student_links` precede role assignments so required Principal/Administrator/Instructor staff links and Student identity links can be enforced by foreign keys and `CHECK` constraints. Administrator is always linked to institute staff; `staff.designation` records the employment designation separately from `auth_roles.code`.
- Sessions, password resets and audit entries can be platform-scoped or institute-scoped; token hashes are unique across institutes. Expiry, revocation, password verification, MFA, rate limits, login lockouts and logout must be handled by the authentication service.
- Only **role identifiers** are seeded. No account credentials, users or arbitrary permissions are seeded. Without an authorized application policy and permission grants, access must be **denied by default**.
- Checks that require inspecting another staff/business row—e.g. Principal/Administrator roles match the correct staff designation, Instructor access only to assigned batches, Student access only to own records, who can grant Administrator or Developer roles, and detailed principal/administrator permission boundaries—need middleware/application authorization. This SQL intentionally has **no triggers or DATA-INTEGRITY GUARDS**.
- `auth_user_permissions` remains institute-scoped, so a platform Developer cannot be silently treated as a tenant user through a personal grant. `auth_user_roles.granted_by` records the issuing account; it does not alone prove that account had grant authority.
- Login by username for institute-scoped accounts must also select/resolve the institute, because username uniqueness is per scope. This repo's database schema is **not** an implemented authentication HTTP service.

**Existing-data note:** Changing `auth_users.institute_id` to nullable, generated tenant FKs, moving `auth_user_roles` after subject-link tables and renaming the role code require a controlled migration for populated databases. Do not execute this create-only SQL on existing data.

**Principal staff assignment and migration note:** `staff.active_principal_guard` now uses the explicit `principal_assignment`, not matching arbitrary designation text. `principal` and `acting_principal` are assignment identities, not extra login roles. Existing data requires reviewed mapping of previous `staff.role` and `designation` to `staff_category`, `designation` and `principal_assignment`; never guess Principal appointment history from the title alone.

## Important operational boundaries

**No DATA-INTEGRITY GUARDS triggers are present.** Source approval checks, published-row immutability, date-overlap prevention, Seat Matrix publication checks, admission-window verification and seat-counter reconciliation are all deferred. Applications must not assume these validations are enforced by this DDL. Database `FOREIGN KEY`, `UNIQUE` and `CHECK` constraints remain in place.

Workflows must select the applicable official source and preserve historic evidence. Application roles/authorization, tenant access, auditability, source evidence accuracy, Seat Matrix currentness, occupancy and attendance/marks reconciliations are **not certified by this schema alone**.

Some schema changes **break existing populated databases and callers**, including new non-null `enrollments.intake_id` and `enrollments.admission_intake_revision_id`, canonical instructor identity changes, nullable pre-enrollment fees and structured Principal assignments. Existing data needs a reviewed, data-preserving migration and caller integration; do not silently fill official references or fabricate historical orders.

## Tests and outstanding runtime gates

Database test files provide two different kinds of evidence. Application and HTTP
test instructions are in the [backend README](../../README.md#authentication-testing).

| Test file | Cases | What it does | What it does not prove |
| --- | ---: | --- | --- |
| [schema-contracts.test.mjs](../../tests/schema-contracts.test.mjs) | 22 | Pure static SQL checks | MySQL imports, login, application behavior |
| [mysql-runtime.test.mjs](../../tests/mysql-runtime.test.mjs) | 14 | Opt-in real MySQL/MariaDB import, local-only fixtures, FK/CHECK negative tests, cross-institute and role identities, attendance evidence, conditional seat race, mysqldump and restore | Official admission/eligibility policy correctness or live APIs |

Run static tests (no database or external packages needed):

~~~powershell
node --test tests/schema-contracts.test.mjs
~~~

Run SQL tests against local MySQL/MariaDB using a disposable database. The runner
creates a random `iti_erp_gate_...` database, imports the schema and fictional
fixtures, then drops only its own databases. Existing databases are not reset.
The dedicated test account needs CREATE/DROP database permission.

~~~powershell
$env:ITI_ERP_RUN_MYSQL = "1"
$env:ITI_ERP_TEST_HOST = "127.0.0.1"
$env:ITI_ERP_TEST_PORT = "3306"
$env:ITI_ERP_TEST_USER = "root"
$env:ITI_ERP_MYSQL_BIN = "C:\xampp\mysql\bin\mysql.exe"
$env:ITI_ERP_MYSQLDUMP_BIN = "C:\xampp\mysql\bin\mysqldump.exe"
# Supply ITI_ERP_TEST_PASSWORD through a private environment variable if required.
node --test tests/mysql-runtime.test.mjs
~~~

If the tools are on PATH, omit the binary-path settings. The runner rejects remote
hosts; use a local service or Docker port mapped to `127.0.0.1`. It requires
`ITI_ERP_RUN_MYSQL=1` and reports SKIP without that opt-in. Fixture-backed checks
and backup drills do not certify a live ERP release.

The backend implements and tests authentication HTTP routes and a scoped institute
profile. Populated-schema migrations, official-data loading and admission/exam
workflows remain [future work](../../README.md#future-work), rather than executable
tests. Fixture-backed success does not certify production or regulatory workflows.

## Installation — fresh, empty database only

The SQL currently contains table definitions and structural constraints only; the `DATA-INTEGRITY GUARDS`, `DELIMITER` directives and all `CREATE TRIGGER` statements have been removed for later implementation.

```sh
mysql -u USER -p -e "CREATE DATABASE multi_institute_iti_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u USER -p multi_institute_iti_erp < database/schema/iti_erp_schema.sql
```

**Do not run the create-only SQL directly on a populated database.** This schema has not been certified by a MySQL runtime import/negative-data test in this review. Structural inspection of text, foreign keys and trigger references is **not** runtime verification and must not be described as “0 gaps,” regulatory approval or production readiness.

The repository may have external generator or migration sources not present here; if so, align those upstream sources before regenerating the SQL.

## API implementation gate after whole-schema re-review

The auth role identity CHECK syntax and whitespace handling in static tests have been corrected. Students now have nullable email and PWD category fields; enrollments have a nullable trainee type. These are reviewed payload inputs, not invented defaults or mandatory values for every draft local record. Resolve allowed values, required fields and category mappings from the applicable official upload specification before submission. An absent attendance record must have zero attended minutes.

The schema remains a fresh-database baseline, not a production-ready API or an in-place migration. The following requirements remain unimplemented and must be verified in service transactions or future integrity guards before enabling the corresponding write endpoints:

- Instructor mappings: require the staff's canonical identity wherever the applicable NIMS mapping rule requires IPRN; a NULL identity bypasses the composite identity FK. Do not assume one mapping policy for every subject/scenario.
- Attendance: enforce attended minutes no greater than the session duration, applicable calendar dates, enrollment status, staff assignment, lock/correction authorization and denominator reconciliation.
- Formative assessment: enforce each score no greater than its template maximum, complete applicable template weights and source-backed method selection. Changing a template must not silently invalidate existing scores.
- Admissions: validate effective affiliation, final Seat Matrix, applicable curriculum/rule/window, age and qualifications, portal shift, quota type/category and evidence. Lock batch and quota rows while changing enrollment status and counters in one transaction; test simultaneous admissions and cancellations.
- Fees: refunds/reversals must target a payment for the same student, applicable enrollment, fee head and exam cycle; reconcile balances atomically and prohibit over-refunding.
- Published/frozen records: protect sources, Seat Matrix, eligibility, assessments, results and certificates with explicit correction/revision workflows and audit evidence.
- DST: validate applicable MoU, affiliated base-unit limits and expiry behavior against the official affiliation order. Do not treat the third-shift DST CHECK as full DST compliance.
- OJT/project: validate source-backed start eligibility, completed hours, evaluator roles, weighting and computed grade. Official result imports must validate the permitted scope of Promoted with Back and retain the original portal status/payload evidence.
- Authorization: define approved role/permission policy, institute boundaries, student self-access, instructor assignments and platform grant authority; database foreign keys do not authorize requests.
- Release verification: select a supported database engine/version, import the complete SQL into a disposable empty database, and run negative-data, tenant-isolation and concurrency tests. Static tests alone do not establish database compatibility or regulatory compliance.

Official references for applicability review:

- [DGT CTS-2026 Calendar and upload annexures](https://dgt.gov.in/en/node/4394)
- [DGT Affiliation Norms 2025](https://dgt.gov.in/en/node/3975)
- [DGT examination, OJT and certificate orders](https://dgt.gov.in/en/exam-corner)
- [DGT CTS trade-specific syllabi](https://dgt.gov.in/en/cts-details)
- [Bihar ITICAT notices](https://bceceboard.bihar.gov.in/ITICATIndex.php): establish applicability to the institute and admission route; do not apply every counselling rule to private direct admissions by assumption.

Existing databases require a reviewed migration for added fields and CHECK changes. New nullable payload fields must be backfilled from verified evidence before any submission that requires them.
