# Multi-Institute ITI ERP backend

Modular PHP 8.5.x API foundation using PDO and the existing SQL schema.
Internal admission/fee/exam transaction services and SQL record guards are documented in [FOUNDATION_UPGRADE.md](database/schema/FOUNDATION_UPGRADE.md). Selected guarded admission, exam and fee transitions are exposed through HTTP; service-level checks remain mandatory. Existing databases require the manual reviewed upgrade; rebuilding Docker alone does not apply it.
No framework or third-party runtime dependencies are required.

## Structure
### Layered modules

Each active module `Admissions`, `Staff`, `Students`, `Academic`, `Fees`, and `Auth` now has four working layers:

- `Http/`: controllers, middleware, request/response boundaries.
- `Application/`: authorized workflows and transactional orchestration.
- `Domain/`: existing validation, authorization context or monetary value rules.
- `Infrastructure/`: tenant-scoped database queries and cryptographic or login-throttle adapters.

Shared components remain in `app/Core/` and `app/Http/`. The `Academic` calendar registry keeps its original API route and existing source-backed SQL. No new regulatory requirements were introduced by reorganizing code. Forward-only SQL upgrades use `php bin/migrate.php --status` and explicit `--apply`; see `database/migrations/README.md`.


```text
app/Core/              Environment and lazy database connection
app/Http/              Router and consistent JSON responses
app/Modules/           Auth, institute, staff, students and guarded operational services
bootstrap/             Autoloading and application startup
config/                Database configuration
routes/api.php         Versioned API routes
public/                Only web-accessible directory
database/schema/       Existing schema
database/seeders/      Existing draft RBAC seed
tests/                 Backend checks and existing schema contracts
```

## Setup

For Docker, use the [project-root Docker instructions](../README.md#docker-local-development).

Run all setup and test commands from the `backend/` directory.

1. Install PHP 8.5.x with PDO MySQL. The current XAMPP PHP 8.3 runtime must
   be upgraded or a separate PHP 8.5 runtime used. The API rejects other branches.
2. Copy `.env.example` to `.env`, then set your database credentials.
3. Create an empty database and import the schema following
   [database instructions](database/schema/README.md). Review the draft RBAC
   policy before importing `database/seeders/rbac_seed.sql`.
4. Optionally run `composer install` for standard PSR-4 tooling. A built-in
   autoloader allows running the foundation without Composer installation.
5. Run `php -S 127.0.0.1:8000 -t public public/index.php` with PHP 8.5.
6. Open `http://127.0.0.1:8000/api/v1/health`.

For Apache/XAMPP, configure a virtual host whose DocumentRoot points to
`C:/xampp/htdocs/multi_institute_iti_erp/backend/public`, allow overrides in that
directory, and enable mod_rewrite. Serve only public; the root .htaccess
deliberately blocks direct project-root access. Use HTTPS in production.
Server error logs receive internal exceptions; API responses hide details.
The health endpoint checks application liveness only, not database readiness.

## Validation and next implementation

Run `php tests/backend.php` and `node --test tests/schema-contracts.test.mjs`.
The basic classes also parse on PHP 8.3 for local checks, but full API verification
requires PHP 8.5 and database integration requires the configured database.

Authentication, scoped staff/student draft creation, and selected audited transitions
are implemented. Remaining CRUD, instructor assignments, other role-specific
record access and populated-database migrations still require implementation.
Follow the module boundaries below when adding them.

## Source-backed formative learning-outcome marks

`POST /api/v1/formative/records/assigned` is only for authenticated,
NIMS-verified assigned **Trade instructors** with
`formative.record.assigned`. Required input:
`enrollment_id`, `batch_year_id`, `learning_outcome_id`,
`assessed_on`, decimal-string `max_marks`, `obtained_marks` and
`evidence_reference`. Checks active enrollment, assigned
instructor/ITSU, official LO on matching course year, the source-
linked published formative scheme and admissible numerical bounds.
Only the explicitly configured `points_ratio` method is supported;
a `template_weighted` scheme without a reviewed official template
is not silently reinterpreted as a points ratio.

`POST /api/v1/formative/records/lock` requires Principal role plus
`formative.template.manage` with `formative_assessment_id` and
`review_reference`. Creates a transactional institute audit entry
and sets the immutable SQL lock. Locked marks must be corrected via
a separate authorized, evidence-backed revision path, not overwritten.

## Scoped institute reporting and audit search

`GET /api/v1/reports/summary` requires `audit.view` and returns
current institute counts (staff, learners, planned/active batches,
active/pending admissions, locked attendance sessions and final
Seat Matrix versions). It never aggregates another institute.

`GET /api/v1/audit/events` requires `audit.view`, supports
`after_id`, `page_size` (up to 100), exact `action_code` and
`actor_user_id` filters, and returns only local event metadata,
not potentially sensitive `event_data_json`.

`GET /api/v1/platform/audit/events` is restricted to platform
`developer` with `platform.audit.view`; only platform-scoped
authentication audit metadata is returned, never password/tokens
or unfiltered event payloads. Read APIs are always cursor-paginated.

## CTS daily teaching calendar and Principal review

`POST /api/v1/academic/calendar-entries` requires `calendar.manage`,
`batch_year_id`, `session_date`, `component` and
`scheduled_minutes`. The component must be one of trade practical,
trade theory, employability or OJT project, fit within the published
Global Curriculum Year component-minute allocation, and be
scheduled on a day inside the local batch course year. Reviewed
days and duplicate calendar slots cannot be changed through this
draft-entry API.

`POST /api/v1/academic/calendars/review` requires Principal role
and `calendar.manage`, with `batch_year_id`,
`reviewed_through` and `review_evidence_reference`. The service
requires actual scheduled rows, advances the reviewed-through date
without presuming unchecked future days were reviewed, and writes
a transactional institute audit event. The existing
`/api/v1/attendance/sessions` action then uses this reviewed
entry as its authoritative duration and checks the NIMS mapping.

## Published-curriculum and CTS batch setup

`POST /api/v1/academic/curriculum-links` accepts
`global_curriculum_id` (requires `curriculum.manage`) and links a
previously published global CTS syllabus revision to the institute.

`POST /api/v1/academic/curriculum-year-links` accepts
`curriculum_id`, `global_curriculum_year_id` and maps the exact
published course year without duplicate local syllabus definitions.

`POST /api/v1/admission/rule-links` accepts `curriculum_id`,
`global_admission_rule_id` (requires `batch.manage`). Both the
published source admission rule and the linked Trade/Curriculum
identity must match; no local admission rule is invented.

`POST /api/v1/batches` accepts `seat_matrix_entry_id`,
`curriculum_id`, `admission_rule_id`, `batch_code`,
`starts_on`, `ends_on`. Batch must use a **final allowed**
source-linked Seat Matrix entry, published CTS calendar, approved
ITSU and matching published curriculum and admission rule.
The batch's capacity is copied exclusively from its matrix
entry; the caller **cannot** submit arbitrary capacity.
It begins in `planned` status.

`POST /api/v1/batches/years` accepts `batch_id`,
`curriculum_year_id`, `starts_on`, `ends_on`,
`attendance_basis` (minutes/working_days) and
`attendance_basis_source_id` referencing a published
institute-linked official document. Academic year stays in draft
calendar state until a separate explicit review.

## CTS admission intake and final seat matrix

`POST /api/v1/admission/intakes` requires `batch.manage`.
Supply `calendar_id`, `admission_year`, `intake_code`, and
`session_part` (primary or six_month_second). The CTS calendar
and referenced official source must already be published; year must
match the source calendar.

`POST /api/v1/seat-matrix/versions` requires `seatmatrix.draft`.
Supply `intake_id`, `regulatory_source_id`, `version_code`,
`portal_reference`, optional `supersedes_id` (only a current final
matrix for the **same** institute/intake). Creates draft only.

`POST /api/v1/seat-matrix/entries` requires `seatmatrix.draft`.
Supply `matrix_version_id`, `itsu_revision_id`, `seat_capacity`,
`admission_allowed` (JSON boolean), `portal_itsu_reference`.
Capacity cannot exceed sanctioned ITSU capacity; the calendar's
physical-to-portal shift mapping is derived from official registry,
never guessed. Admission-allowed rows require an active NIMS-verified
staff mapping for that exact ITSU.

`POST /api/v1/seat-matrix/versions/finalize` requires both
`seatmatrix.publish` and the Principal role. Supply
`matrix_version_id` and `review_reference`. The linked published
DGT document must identify a **final seat matrix**, have a SHA-256
digest, and the matrix must include entries. Finalization freezes
entries under SQL triggers. Revised final versions retain a pointer
to the superseded version. This is a local recording of reviewed
official evidence; it does not authenticate the government document.

## Source-backed institute Trade Unit / ITSU register

`POST /api/v1/institute/regulatory/sources` attaches a previously
registered and published global official document to the **current
institute**, with `global_document_id` and optional
`local_evidence_reference`. Requires `institute.affiliation.manage`.
This is an institute mapping, not an independent authority declaration.

`POST /api/v1/itsu/trade-units` creates a numbered institute Trade
Unit via `global_trade_id` and `unit_no`. It requires
`tradeunit.manage` and a currently effective active Global Trade
revision backed by a published source. There is no Institute Trade
master or locally invented trade code.

`POST /api/v1/itsu/shift-revisions` takes `trade_unit_id`,
`global_shift_id`, `regulatory_source_id`, `sanctioned_capacity`,
`approval_reference`, `valid_from`, `affiliation_status`,
`training_mode` (CTS/DST). Uses the Global Shift identity and
a reviewed DGT document linked to the caller's institute. Revisions
close prior effective rows and preserve history. The capacity and
approval reference are source-declared inputs, not inferred values.
Local source attachments do not constitute independent governmental
confirmation; check actual affiliation evidence before relying on
these records for admissions.

## Global DGT Trade master

Only the existing platform Developer with `platform.regulatory.manage`
can register or revise Global Trade identities. There is **no**
institute-specific Trade master API.

- `POST /api/v1/platform/trades` creates a globally unique `dgt_code`
  and first **draft** version using `source_document_id`,
  `version_code`, `official_name`, `trade_type`
  (`engineering`, `non_engineering`, `divyang`) and
  `effective_from`. The source must be a previously published
  registered **DGT document**.
- `POST /api/v1/platform/trades/revisions` adds a draft revision
  to an existing stable `trade_id`.
- `POST /api/v1/platform/trades/revisions/activate` takes
  `revision_id` and `review_reference`. It activates a draft
  revision, supersedes the previous active revision (without deletion),
  and retains the prior version's effective end date.
- `GET /api/v1/platform/trades` lists stable Trade IDs with their
  active revision, or a NULL revision if none is active.

Operator-provided source/review evidence must be independently inspected;
ERP activation means a locally reviewed master revision, not proof of
official issuance.

## Platform regulatory source document register

`POST /api/v1/platform/regulatory/documents/draft` accepts explicit
`authority` (`DGT`, `NCVET`, `STATE_UT`, `SSDEC`),
`document_type`, `title`, `reference_no`, `issued_on`,
`sha256`, a `source_url` (HTTPS) and/or `evidence_reference`,
optional `source_system` (`SIDH`, `NCVT_MIS`, `NIMS`),
`state_ut_code` and `supersedes_id`. The Bihar-bound STATE_UT/SSDEC
records require `state_ut_code=BR`. The digest is supplied by the
operator and is **not cryptographically recomputed against remote official
bytes by this API**. Draft registration is not regulatory verification.

`POST /api/v1/platform/regulatory/documents/publish` requires
`document_id` and explicit human `review_reference`. Only a
previously drafted record with source reference and SHA-256 may be
published; reviewed_by/reviewed_at and an authenticated platform audit
event are recorded. The status means **registered as reviewed within
this ERP**, not a claim that DGT/NCVET issued or accepted it.
`GET /api/v1/platform/regulatory/documents` provides a platform-only
cursor-paginated register. All three endpoints require
`platform.regulatory.manage` and an authenticated platform Developer.
Official verification must still inspect the genuine source evidence.

## Platform institute onboarding

Only an authenticated, active platform `developer` can create institute
tenants and provision their first Principal account.

- `GET /api/v1/platform/institutes`: `platform.institute.view`, paginated
  by `page_size` and `after_id`.
- `POST /api/v1/platform/institutes`: `platform.institute.create`; JSON
  `name`, `mis_code`, `address`, optional `district_name`,
  `management_type`. Records a local institute directory entry only:
  **not DGT affiliation, Seat Matrix approval or recognition**.
- `POST /api/v1/platform/institutes/principal`:
  `platform.user.provision_principal`; JSON `institute_id`, `employee_code`,
  `full_name`, `designation`, `joined_on`, `appointment_reference`,
  `username`, `temporary_password`. Creates a staff identity, principal
  assignment, linked user identity, Principal role and Argon2id credentials
  with mandatory first-login password change. Refuses a second current
  Principal/appointment. The appointment reference records *local
  declaration*, not verification by a government portal.

Both mutations use a single DB transaction and platform-auth audit event,
keep credentials out of responses/audits and cannot be used by institute
accounts. Existing Principal replacements/handover still need a separately
approved historical workflow.

## Authentication

| Method | Endpoint | Input / authorization |
| --- | --- | --- |
| POST | `/api/v1/auth/login` | JSON username, password, institute_id |
| GET | `/api/v1/auth/me` | Bearer token |
| POST | `/api/v1/auth/logout` | Bearer token, JSON `{}` |
| POST | `/api/v1/auth/change-password` | Bearer token, current_password and new_password |
| GET | `/api/v1/auth/sessions` | Bearer token; own live sessions only |
| POST | `/api/v1/auth/logout-all` | Bearer token and current_password |

Use `Content-Type: application/json` for POST requests. Institute login requires
a positive integer institute_id. For a platform Developer account use null.
The login returns access_token, token_type, expires_in and the current profile.
Send `Authorization: Bearer <access_token>` for protected routes. Keep the token
in application memory; use HTTPS. Sessions expire after 60 minutes and only a
SHA-256 digest is stored. Logout revokes the current session; password changes
revoke all sessions and outstanding reset tokens and require a new login.
Login, password-change and logout-all verification share the failed-attempt counter.
Five failures cause a 15-minute temporary lock, including existing sessions.
The attempt window resets after lock expiry. Null bytes in passwords are rejected.
Request metadata is sanitized; audit failures cannot undo failure counters.
Failure responses do not name an account's existence. Production needs perimeter/IP rate
limiting, especially against attempts across many usernames.

The existing five roles and identity links are used. Roles must be currently
valid; disabled accounts and expired/revoked sessions are rejected. Permissions
are loaded on every authenticated request, with per-user deny overrides winning.
Platform identities receive only platform permissions; institute identities
cannot receive platform permissions. Institute-scoped profile, staff/student registries and selected guarded
business transitions are exposed; other write workflows remain unimplemented.
Institute staff/student account provisioning, account disable, role revocation
and administrator-initiated temporary-password reset are now implemented.
Public forgot-password delivery and refresh are not implemented.
MFA/2FA is intentionally outside the current scope per the owner's decision.
Current login remains username/password with lockout, rate limiting and session
controls; no OTP or authenticator-app step is required.

### Authentication hardening

New passwords use Argon2id (19 MiB, two iterations, one thread), following
[OWASP password storage guidance](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html).
Legacy bcrypt hashes are verified normally and upgraded after successful login
using [PHP rehash checks](https://www.php.net/manual/en/function.password-needs-rehash.php).
The PHP runtime must support Argon2id; the Docker image has verified support.
Failed logins have a minimum 500 ms response duration, covering legacy hashes,
unknown/disabled/locked accounts and missing roles. Padding happens after database
locks are released. This removes the measured bcrypt-versus-Argon2id timing gap
under the tested load; unusually expensive imported hashes or server overload
can exceed the budget and require deployment-specific timing monitoring.

Sessions have a 60-minute absolute lifetime and 15-minute idle timeout, with a
maximum of five live sessions per account. A new login revokes the oldest excess
sessions. `GET /api/v1/auth/sessions` returns only the caller's live session
metadata, without token hashes. `POST /api/v1/auth/logout-all` requires the current
password and revokes every session including the current one. Wrong password
verification uses the same account lockout counter as login/password change.

Login is limited to 30 requests per source IP per minute before password checks.
The limiter uses atomic file locks shared by PHP workers on a single backend
instance and fails closed if its storage is unavailable. It trusts REMOTE_ADDR,
not client-supplied forwarding headers. For multiple backend replicas or a trusted
reverse proxy, configure an appropriate shared perimeter limiter and source-IP
handling; this instance-local limiter does not provide distributed protection.
Limiter state resets on container replacement. JSON responses disable caching,
referrer transmission and frame embedding. Tokens are accepted only in the
Authorization header, not URLs. Recovery delivery still requires separate
implementation. Public deployment
requires HTTPS; no zero-leakage or production-security certification is claimed.

### First developer account

After importing the schema, run `php bin/create-developer.php` and provide a JSON
object containing username and password through stdin using your local secure
workflow. Do not pass passwords as command arguments or commit them to files.
The command creates only the first platform account, refuses to run when one
already exists, and requires password change at first login. New passwords must
be 12–72 bytes. No account or default credentials are created automatically.
Permission grants still require your reviewed RBAC policy/seed. Institute users are provisioned via authenticated account-management routes,
with same-institute staff/student links and explicit RBAC; there is no public registration endpoint.

### Institute user and role lifecycle

| Method | Endpoint | Guard |
| --- | --- | --- |
| GET | `/api/v1/auth/users` | `auth.user.view` (institute only) |
| POST | `/api/v1/auth/users/staff` | `auth.user.manage_staff_accounts` for instructor, Principal plus `auth.user.grant_administrator` for administrator |
| POST | `/api/v1/auth/users/student` | `auth.user.manage_student_accounts` |
| POST | `/api/v1/auth/users/disable` | `auth.user.view` plus authority over target role |
| POST | `/api/v1/auth/users/revoke-role` | `auth.user.view` plus authority over target role |
| POST | `/api/v1/auth/users/reset-password` | `auth.password.reset_user` plus authority over target role |

Staff input: `staff_id`, `role` (`instructor` or `administrator`),
`username`, `temporary_password`. Student input: `student_id`,
`username`, `temporary_password`. Disable/revoke input: `user_id`.
Password reset: `user_id`, `temporary_password`. All identity numbers
must be positive JSON integers. Passwords must be 12–72 bytes, never returned
or audited in plaintext, and are stored with Argon2id. New accounts and
operator password resets force first-login password change. Reset, disable,
and revocation invalidate all existing sessions and outstanding reset tokens.

Only existing institute-linked, currently employed staff and existing student
identities can receive logins. Instructor roles require teaching staff;
Administrator grant/revocation is Principal-only and at most one **current**
Administrator assignment is permitted per institute. A disabled Administrator
retains their role history; revoke it explicitly before assigning a successor.
Principal and Developer accounts are **not** provisioned by these endpoints.
No SMTP, recovery tokens or NIMS approvals are fabricated. Every mutation
uses an institute-serialized transaction and audit entry. For secure
credential delivery, the institute must use an authorized channel outside
this API; do not pass initial passwords via query strings or audit logs.

### Authentication testing

`php tests/backend.php` checks routing, bootstrap and authorization helpers.
For database-backed tests set TEST_DB_DSN to a **disposable** MySQL/MariaDB server
DSN, plus TEST_DB_USERNAME and TEST_DB_PASSWORD if required, then run
`php tests/auth-integration.php`. It creates a random database, imports the full
schema and draft seed for tests, and drops only its own database afterwards.
Tests cover tenant isolation, token hashing, password rotation, deny overrides,
revocation, expiry, platform boundaries, lockout and audit events. Auth timestamps
use UTC; database connections set their session time zone to UTC. Existing auth
DATETIME values stored in another zone require a reviewed conversion before use.

The current manual suite also verifies developer bootstrap and repeat rejection,
legacy hash upgrade before password change, request ID/error redaction,
environment precedence and quote preservation, throttle expiry/storage failure
and concurrent workers, malformed/URL tokens, password-change-only sessions,
transaction commit/rollback/nesting, and readiness during a database outage.
These checks run against the isolated Docker test stack; unimplemented workflows
are listed under Future work below and are not counted as passing tests.

## ITSU and batch read APIs

`GET /api/v1/itsu` requires `tradeunit.view`. It joins institute ITSU
revisions to their global Trade and Shift masters; current official trade
names may be `NULL` if no active reviewed revision exists. Historical
affiliation/status records are retained in the output without turning them
into new admission approvals.

`GET /api/v1/batches` requires `batch.view`. It exposes the existing
institute-owned intake, calendar, seat matrix, capacity and status pointers
without changing their regulatory meaning. Both endpoints use cursor
pagination (`page_size`, `after_id`; maximum 100). Neither endpoint can
create or approve trade units, shifts, seat matrices or batches.

## Institute contact and staff lifecycle

`POST /api/v1/institute/contact` takes `contact_phone` and/or
`contact_email` and requires `institute.profile.update`. Only these
institute-local contact fields are writable: affiliated name, official
address, trade approvals and MIS identity remain source-governed.

`POST /api/v1/staff/profile` requires `staff.manage`, `staff_id`,
`full_name`, `designation` and applies to non-exited staff.
`POST /api/v1/staff/qualifications` requires `staff_id`,
`qualification_type`, `specialization`, `certificate_reference`,
and explicit nonnegative integer `experience_months`; newly recorded
school evidence has NULL approval/verification columns.
`POST /api/v1/staff/exit` accepts `staff_id`, `left_on`
(real non-future YYYY-MM-DD) and `reference`. It blocks unresolved
active instructor mappings and Principal/Acting Principal assignments,
requires Principal authority if exiting an Administrator, and disables
the departing staff member's login and live sessions without deleting
historical role links. Every mutation is institute-scoped and audited.

## Local student registry

`GET /api/v1/students` requires `student.view` and returns only institute-owned
student records with the same `page_size`/`after_id` cursor convention as
staff. `POST /api/v1/students` requires `student.manage` and accepts:

```json
{"local_student_code":"STU-001","full_name":"Learner","date_of_birth":"2005-01-01","gender":"female","address":"Local address","identity_mode":"non_aadhaar","identity_evidence_reference":"Local document reference"}
```

`GET /api/v1/students/me` requires `student.view.own` and reads only the
student identity linked to the authenticated account. Institute-wide access
is never implied by the own-record permission. Local drafts do not generate an
official PRN, certificate, allotment or user account; EWS remains unknown
(`NULL`) until verified. Admissions and qualification verification require
their separate official-source-backed workflows.

## Institute staff registry

`GET /api/v1/staff` requires `staff.view`; it returns only the caller's
institute staff with cursor pagination (`?page_size=50&after_id=123`,
page size 1–100; omit `after_id` for the first page).
`POST /api/v1/staff` requires `staff.manage` and accepts:

```json
{"employee_code":"EMP-001","full_name":"Staff Name","staff_category":"teaching","designation":"Instructor","joined_on":"2026-01-02"}
```

The same staff table holds teaching and non-teaching employees. Creating staff
does not auto-verify NIMS identity, confer an instructor/principal role, grant
permissions or create a login. Codes are unique within an institute and writes
are audited and serialized. Staff updates, verified mappings and account
provisioning remain separate workflows.

## Student qualification records

`POST /api/v1/students/qualifications` requires `student.manage` and
`student_id`, `level_code`, `board_name`, `passing_year` (integer),
`certificate_reference`, and `subject_results` as a JSON object containing
nonnegative numeric marks. A logged-in institute operator attests to a
referenced school document; `verified_by` and `verified_on` record this
local review (not government recognition). Duplicate certificate references
for one student are rejected. Records are tenant-scoped, transactional and
audited. Subsequent qualification-route validation still follows the
applicable published admission rule and Principal review.

## Unverified admission drafts

`POST /api/v1/enrollments/drafts` requires `enrollment.manage` and a
JSON body with `student_id`, `batch_id`, `qualification_id`,
`admission_intake_revision_id` (positive integer IDs),
`admission_number`, `admitted_on` (`YYYY-MM-DD`),
and `qualification_route_code`.

The server derives institute identity and batch intake/admission-rule IDs,
checks that the student, qualification, intake revision and batch belong
together in that same institute, rejects duplicate student/admission identities,
and creates only `pending_verification` with `admission_route=unverified`,
`qualification_check_passed=0`, and no official source/allotment claim.
It does **not** occupy a seat; activation remains blocked until an independent,
source-backed verification and review workflow populates genuine evidence.
Because the existing schema requires non-null `checked_by`/`checked_on`
even for drafts, `checked_by` is stored as `NOT_YET_VERIFIED`; that value
is not a claimed approval. Writes run in a tenant transaction and are audited.

## Principal qualification and admission-route review

`POST /api/v1/enrollments/review` requires `enrollment.manage` and a
logged-in Principal, not Administrator. JSON fields: `enrollment_id`,
`admission_route` (one of `iticat_counselling`, `iticat_spot`,
`institute_direct`, `transfer`), `admission_route_source_id`,
`admission_route_evidence_reference`, and optional
`quota_evidence_reference`. The service requires an institute-linked,
published and reviewed source, published applicable admission qualification
rule, valid documented school qualification, configured subject marks and
official age threshold. All reviews are audited. A reviewer cannot approve
their own locally created draft.

Review sets `qualification_check_passed=1` and the documented route, but
**retains pending_verification and does not allocate a seat**. The separate
existing admission activation service still checks published academic sources,
final Seat Matrix, affiliation, NIMS instructor mapping, official intake
window, quotas, eligibility and capacity. There is no external approval,
allotment generation or official portal submission.

## Admissions, Seat Matrix and calendar registries

| Method | Endpoint | Permission | Scope |
| --- | --- | --- | --- |
| GET | `/api/v1/enrollments` | `enrollment.view` | Institute-linked admission records, including verification/status fields |
| GET | `/api/v1/seat-matrix/entries` | `seatmatrix.view` | Institute Seat Matrix entries and their version/source/status |
| GET | `/api/v1/academic/calendars` | `calendar.view` | CTS training calendars linked through that institute's intakes |

All use keyset pagination (`page_size` 1–100, `after_id`).
Draft and final versions remain distinct; a read never certifies a source,
marks an applicant eligible or allocates a seat. No other institute's records
are returned. Enrollment review/approval and governed source imports remain
separate transactional workflows; only unverified local draft creation is exposed.

## Official results and certificates (read-only)

| Method | Endpoint | Permission |
| --- | --- | --- |
| GET | `/api/v1/exams/results` | `result.view` |
| GET | `/api/v1/exams/results/me` | `result.view.own` |
| GET | `/api/v1/certificates` | `certificate.view` |
| GET | `/api/v1/certificates/me` | `certificate.view.own` |

Results and certificate reads preserve published source references, revisions
and supersession pointers from previously imported authority records. They use
institute-scoped queries and, for `/me`, the account's linked student identity.
An empty result means no imported official record; it must not be presented as
a pass/fail decision or generated certificate. Both support keyset pagination
(`page_size` 1–100, `after_id`). Signed imports, evidence verification,
revocations and corrections require separately audited workflows.

## Attendance daily sessions and Principal lock

`POST /api/v1/attendance/sessions` requires `calendar.manage` and
`batch_year_id`, `session_date`, `component`, `instructor_id`.
An institute must already have a **published CTS calendar**, a reviewed
calendar through that day, a corresponding scheduled entry with its real
minutes, and a valid active NIMS-verified teaching-staff mapping for the
batch ITSU (or, for Employability, the subject batch-year assignment).
The service derives the actual minutes from the calendar; it does not
accept guessed client values or duplicate sessions.

`POST /api/v1/attendance/sessions/lock` requires `attendance.correct`
and a Principal account, with JSON `session_id`. It rejects future
sessions, an empty register or missing records for applicable enrolled
students. Once locked, existing database triggers reject modifying the
session/marks; any corrections must use separately authorized audited
workflows. Both actions are institute-serialized and audited.

## Attendance read APIs

| Method | Endpoint | Permission | Record boundary |
| --- | --- | --- | --- |
| GET | `/api/v1/attendance/records` | `attendance.view` | Caller institute only |
| GET | `/api/v1/attendance/records/assigned` | `attendance.view.assigned` | Sessions with `instructor_id` matching the logged-in staff |
| GET | `/api/v1/attendance/records/me` | `attendance.view.own` | Enrollments belonging to the linked student |

These read-only endpoints use joined institute/batch/session identities so
`.assigned` and `.own` permissions never expand into institute-wide reads.
Only recorded attendance is returned; absent rows do not imply absence.

**Attendance marking:** `POST /api/v1/attendance/records/assigned`
requires `attendance.mark.assigned` and JSON
`{"session_id":123,"enrollment_id":456,"attended_minutes":40,"status":"present"}`.
The server derives the institute and staff identity from the session token.
Only an existing, unlocked session assigned directly to the instructor may
receive a new row for an active learner enrolled in that same batch.
It rejects excess minutes, wrong-batch enrollment, missing/foreign sessions,
unassigned instructors and duplicate attendance instead of overwriting data.
Optional `leave_evidence_reference` is a string of up to 190 bytes.
Writes are serialized per institute and audited. This endpoint does not
create sessions, lock a daily register, certify a working day or approve
corrections; those remain separate source-backed workflows.
Pagination accepts `page_size` (1–100), and then the two integers
`after_session_id` and `after_enrollment_id` **together** from
`data.next_cursor`. No instructor can mark/edit/lock attendance through these
read endpoints. Source-backed data entry/correction APIs remain pending.

## Fee APIs

Existing `FeeService` methods are exposed through strict institute-bound adapters.
Amounts **must be JSON strings** (for example `"100.50"`, not the JSON
number `100.50`). Payment and refund creation require a non-empty
`idempotency_key`; retrying the same request and key returns the same ID,
while reusing a key for different data is rejected.

| Method | Endpoint | Permission | JSON fields |
| --- | --- | --- | --- |
| GET | `/api/v1/fees/payments` | `fee.view` | Pagination: `page_size`, `after_id` query |
| GET | `/api/v1/fees/payments/me` | `fee.view.own` | Own linked student payments only |
| POST | `/api/v1/fees/assessments/issue` | `fee.record` | `assessment_id` |
| POST | `/api/v1/fees/waivers/approve` | `fee.refund` | `waiver_id` |
| POST | `/api/v1/fees/payments` | `fee.record` | `student_id, amount, payment_mode, payment_no, receipt_no, idempotency_key` |
| POST | `/api/v1/fees/allocations` | `fee.record` | `payment_id, demand_id, amount` |
| POST | `/api/v1/fees/refunds` | `fee.refund` | `allocation_id, amount, reason, refund_mode, refund_no, idempotency_key` |
| POST | `/api/v1/fees/refunds/decide` | `fee.refund` | `refund_id, action` (`approved/rejected/cancelled/paid`), optional `reference_no` |
| POST | `/api/v1/fees/payments/reverse` | `fee.refund` | `payment_id, requested_by, reversal_no, reason` |

All financial writes use the existing serialized transaction/record guard and
enforce institute ownership, sum limits, audit trails and independent approval
where applicable. Students cannot write payments or see another student's
payments. This does not fabricate institute fee schedules or official orders;
financial setup, complete demand creation, reports and cash-closing APIs are
still pending. Do not expose any alternative direct SQL write endpoint.

## Guarded operational endpoints

Four additional POST endpoints invoke the existing transaction/evidence services.
They require a valid institute login, completed mandatory password change, and
the explicitly reviewed permissions in the table. All record IDs are positive
JSON integers, not string IDs. The server derives the institute from the bearer
session (never from the request body). All write services audit changes in the
same database transaction and enforce official-source applicability.

| Method | Endpoint | Required permission | JSON body |
| --- | --- | --- | --- |
| POST | `/api/v1/enrollments/activate` | `enrollment.manage` | `{"enrollment_id":123}` |
| POST | `/api/v1/enrollments/cancel` | `enrollment.manage` | `{"enrollment_id":123,"reason":"Documented correction"}` |
| POST | `/api/v1/enrollments/trainee-upload/prepare` | `enrollment.manage` | `{"enrollment_id":123}` |
| POST | `/api/v1/exams/eligibility/freeze` | `exam.eligibility.manage` | `{"enrollment_id":123,"batch_year_id":456,"cycle_id":789,"evidence_reference":"Official evidence reference"}` |

These are **not** public registration, admission creation, government upload,
certificate issuance or unrestricted record-editing endpoints. The upload action
only prepares locally validated JSON and does **not** submit it externally.
Eligibility freeze is intentionally one-way until a separately audited correction
workflow exists. The existing tests cover negative HTTP authorization and
validation plus service-level invariants; successful HTTP business-transition
integration still requires real reviewed fixtures and is not claimed.

## Module boundaries

The HTTP Kernel assigns X-Request-ID and handles validation/internal errors
centrally. Logs include the generated request identifier and exception class;
database errors and credentials are not returned to clients. AuthMiddleware
loads the live identity/permissions before protected handlers and blocks
business access until required password change is complete.

`GET /api/v1/institute/profile` is the first read-only business endpoint. Its
repository queries only the institute on the authenticated context; a client
institute_id parameter cannot change the scope. Access requires the reviewed
`institute.profile.view` grant. `GET /api/v1/ready` checks database connectivity
and returns 503 when unavailable; `/health` remains an independent liveness check.

Transaction::run() commits a successful database workflow or rolls it back on
failure. Nested transactions are rejected and callbacks are not retried
automatically. Keep external side effects out of database transaction callbacks.
Database migrations, admission/fee concurrency workflows and own/assigned
business-record filters remain module-specific implementation work.

Keep HTTP handling in Controllers and business transactions in Services. Add
Repositories and request validators when a module needs them, without empty
placeholder folders. Planned business modules include Institutes, Staff, Trades,
Curriculum, Admissions, Students, Attendance, Assessments, Examinations, Fees,
Assets and Audit.

Business routes must authenticate through AuthService, call
AuthContext::requirePermission() and requireInstitute(), then filter queries by
the context's instituteId and staffId/studentId. Permission codes alone do not
enforce `.own` and `.assigned` record access. Password-change-only sessions may
use me/logout/change-password; business permission checks reject them.
Use transactions for capacity, enrollment and financial workflows. Follow the
database README's deferred integrity requirements before exposing write APIs.

## Future work

The following features are not implemented or certified by the current tests:

- Populated-database migrations with history, checksums and rollback.
- Verified official-master data loading without invented regulatory defaults.
- Refresh token rotation and forgot-password delivery.
- Broader instructor-assigned and student-own access for formative/OJT/exams; attendance and own fee reads now have scoped endpoints.
- Admission creation/review, fee setup/report/cash-closing workflows, official submission, certificate imports, and complete approval/correction workflows. Guarded activation, cancellation, upload preparation and eligibility freeze HTTP endpoints exist, but are not end-to-end official workflows.
- Broader attendance/formative data-entry workflows and official reconciliation policies beyond the configured freeze service.
- Full simultaneous admissions/cancellations load tests; the new suite covers counters and institute lock serialization.
- Governed OJT, exam, official-result and certificate imports/revisions.
- Full recovery drills and reconciliation for a populated application database.

## Source-backed admission intake revisions

`POST /api/v1/admission/intake-revisions` requires `admission.intake.manage` and accepts
`intake_id`, institute-linked `regulatory_source_id`, `revision_code`, real
`opens_on` / `closes_on` dates, `approval_reference`, and optional
`supersedes_id`. The source must be a locally reviewed published official
reference with SHA-256. New records remain `draft`; no windows are guessed.

`POST /api/v1/admission/intake-revisions/publish` requires the Principal role
and `admission.intake.manage`. `intake_revision_id` and `review_reference`
are required. The current published revision of the same intake, if any, must
be specified as `supersedes_id` when the draft is created. Publication and
supersession are one audited institute transaction; old records are retained.
This is institute review of source evidence, not government issuance.

## Institute affiliation history from reviewed DGT orders

`POST /api/v1/institute/affiliation-history` requires Principal with
`institute.affiliation.manage`, `regulatory_source_id` of a published DGT
order linked to that institute, `change_type`, `approved_name`,
`approved_address`, `affiliation_status`, `affiliation_order_reference`,
and `effective_from`. Each later order closes the prior current period;
all historical records are kept with an institute audit event.
This does not issue or verify government approval.

## NIMS instructor identity and assignment review

All four endpoints are institute-scoped; they require the **Principal role**
plus the stated permission. They create no NIMS portal action or independent
DGT approval. The caller attests to externally reviewed source evidence.

- `POST /api/v1/staff/qualifications/review` (`staff.manage`):
  `qualification_id`, `regulatory_source_id`, `review_reference`;
  records one Principal review of staff qualification evidence.
- `POST /api/v1/instructors/iprn/review` (`instructor.mapping.manage`):
  `staff_id`, `regulatory_source_id`, `iprn`, `verification_reference`,
  `verified_on`; needs published institute-linked NIMS source evidence with
  SHA-256 and an active teaching employee. Existing verified identity is not
  silently replaced. A reviewed source record does not authenticate NIMS APIs.
- `POST /api/v1/instructors/mappings` (`instructor.mapping.manage`):
  `staff_id`, `qualification_id`, `qualification_source_id`,
  `mapping_type` (`trade` or `subject`), `valid_from`, `review_reference`,
  and either `itsu_revision_id` for a Trade or `subject_code` for a subject.
  Requires a reviewed qualification, matching active NIMS-reviewed instructor,
  and approved ITSU when applicable. One active staff/IPRN/ITSU assignment is
  enforced by the existing schema. The source and review are audited.
- `POST /api/v1/instructors/mappings/release` (`instructor.mapping.manage`):
  `mapping_id`, `valid_to`, `regulatory_source_id`, `review_reference`;
  preserves the historical assignment and its reviewed release evidence.

The current supported HTTP workflow records **local review of official
external evidence**, not a live verification against the NIMS portal.

## DGT CTS syllabus master loading

Platform Developer with `platform.regulatory.manage` may load the **explicit
values in a locally reviewed, published DGT syllabus source**:

- `POST /api/v1/platform/curricula`: `global_trade_id`,
  `source_document_id`, `version_code`, source-declared `duration_months`,
  decimal-string `nsqf_level`, `unit_strength`, `effective_from`, optional
  `effective_to` (creates draft only).
- `POST /api/v1/platform/curricula/years`: `global_curriculum_id`,
  `year_no`, and explicit integer `trade_practical_minutes`,
  `trade_theory_minutes`, `employability_minutes`, `ojt_project_minutes`,
  optional decimal-string `credits`.
- `POST /api/v1/platform/curricula/outcomes`: `global_curriculum_year_id`,
  `lo_code`, `outcome_text`, `assessment_criteria`, optional `nos_code`
  and decimal-string `formative_max_marks`.
- `POST /api/v1/platform/curricula/publish`: `global_curriculum_id`,
  `review_reference`. A complete 1- or 2-year schema duration with at least
  one official learning outcome in each applicable year must be recorded.

No official NSQF, trade strength, teaching duration, credits, or formative
marks are guessed. Platform publication means reviewed institute ERP evidence,
**not** independent cryptographic or regulatory authority verification.
Where a published Global Learning Outcome declares `formative_max_marks`, the instructor-supplied maximum must match it exactly; a request cannot override an official LO maximum. A null official maximum is not replaced with a guessed number.\n\nWhen a published global course year is linked locally, its complete official
learning outcomes are mapped to `learning_outcomes` in the same transaction;
manual local copy/paste of LO definitions is unnecessary.

## CTS training-calendar source register

Platform Developer with `platform.regulatory.manage` can create a source-backed
CTS Calendar revision and annexure rows. This does **not** certify external DGT
content, replace government uploads, or assign default official dates/shifts.

- `POST /api/v1/platform/cts-calendars`: `admission_year`, `session_code`,
  `revision_code`, `source_document_id` (reviewed published DGT order),
  `training_starts_on`, `training_ends_on`, optional `upload_profile`
  JSON object copied explicitly from the applicable reviewed annexure.
- `POST /api/v1/platform/cts-calendars/milestones`: `calendar_id`,
  `milestone_code`, `milestone_date`, `description`,
  `document_section_reference`.
- `POST /api/v1/platform/cts-calendars/portal-shifts`: `calendar_id`,
  `session_part` (`primary` or `six_month_second`),
  `physical_shift_no`, `portal_shift_no`, `document_section_reference`.
  The portal shift values are operator-supplied from the published source;
  no shift conversion is hard-coded.
- `POST /api/v1/platform/cts-calendars/publish`: `calendar_id` and
  `review_reference`, after milestones and portal shift mapping evidence exist.

Published calendar IDs are referenced by institute admission intake and batch
year workflows. Draft source annexures are never treated as government-approved.

## CTS OJT and group-project backend lifecycle

`GET /api/v1/ojt/projects` requires `ojt.view` and lists only the logged-in institute's projects; `GET /api/v1/ojt/projects/me` requires `ojt.view.own` plus an authenticated student link and lists only the learner's own projects. Both use `after_id`/`page_size` cursor pagination.

These institute-scoped routes use existing `ojt_projects`, `ojt_logbook`, and
`ojt_evaluations` records, not new tables. They record **local evidence**; no
SIDH submission, industry signature verification, or grade issuance is implied.

- `POST /api/v1/ojt/projects`: Principal with `calendar.manage` plans one
  project per enrolled trainee and course year. Supply `enrollment_id`,
  `batch_year_id`, `mode` (`ojt`/`group_project`), `title`,
  `coordinator_id`, `starts_on`, `ends_on`, `required_minutes`, and
  `safety_reference`. Industry OJT also requires `industry_name` and
  `industry_mentor`. Optional source-declared fields are
  `industry_registration_reference`, `workplace_address`, and
  `female_safety_reference`. Planned required minutes must equal the applicable
  published CTS syllabus year OJT/project minutes; no defaults are inserted.
- `POST /api/v1/ojt/projects/start`: Principal with `calendar.manage`
  supplies `project_id` and `review_reference`. A verified currently assigned
  Trade instructor must coordinate an active trainee/project.
- `POST /api/v1/ojt/logbook/me`: linked trainee with `ojt.log.own` supplies
  `project_id`, `entry_date`, `attended_minutes`, `activity`, and
  `mentor_confirmation` (optional `monitoring_safety_reference`). Only own
  active project's actual dated entries can be added; accumulated minutes
  cannot exceed the official source-backed requirement.
- `POST /api/v1/ojt/evaluations/assigned`: assigned Trade instructor with
  `ojt.evaluate.assigned` records published-source `evaluation_source_id`,
  source-declared `primary_evaluator_role`, names, decimal-string primary and
  external marks/maxima, `final_grade`, `evaluated_on`, and
  `signed_evidence_reference`. Optional `sidh_submission_reference` and
  `completion_certificate_reference` apply; an industry OJT requires
  completion-certificate evidence. Complete logbook minutes and a current
  institute-linked published official evaluation source are mandatory. One
  evaluation per project is recorded; re-evaluation requires reviewed
  correction rather than silently overwriting the result.

Do not treat the recorded `final_grade` as independently recalculated or
portal-verified; the backend validates ranges and traceable evidence but does
not reconstruct external grading rules without official source profiles.

## Source-gated exam setup and official imports

`POST /api/v1/exams/cycles` drafts a CTS exam cycle using a same-institute `rule_id`, published institute-linked regulatory schedule source, exact exam type, eligibility cutoff, starts-on date, and an explicit official `activity_windows` object. `POST /api/v1/exams/cycles/open` requires Principal role and `exam.eligibility.manage`, a review reference, and published source records at opening. This is *local institute exam planning*, not DGT authority issuance.

`POST /api/v1/exams/results/import` requires `result.import`. `POST /api/v1/certificates/import` requires `certificate.record`. **Both are disabled by default**; the operator must explicitly set `ALLOW_OFFICIAL_RECORD_IMPORT=1` after a verified authorized regulator export is available and checked against its published institute-linked source registration. Each import requires explicit `regulatory_source_id`, `source_system`, `source_reference`, `evidence_reference`, and complete source-backed record fields. Identity is constrained to the institute, enrollment, batch/year, and exam cycle; revisions must point to their direct predecessor; existing records are not overwritten. Certificate NTC imports require a recorded official pass result. The server-computed `payload_sha256` is an internal snapshot digest **not a cryptographic proof of government publication**. The source document's SHA-256 is only checked for presence and linked to the audit; external authenticity and each candidate's imported values must be independently verified. No official result or NTC is invented or issued by these APIs.

A generic published syllabus/calendar reference is not accepted for official imports: the linked source document type must explicitly identify a result for result imports, or a certificate/NTC/marksheet for certificate imports. A type-matched document still does not verify candidate-level values or confirm government authenticity.\n\nA fully certified production exam lifecycle remains gated on an authorized official data export, completeness checks for all eligible enrolments, review of exam statuses, and full manual integration/UAT testing. Do not enable these routes solely because fixtures pass.

`GET /api/v1/exams/cycles/status?cycle_id=...` returns only same-institute cycle metadata, eligibility snapshot counts and official-result revision counts for manual reconciliation. Counts are explicitly **not proof** that all required trainees, attempts or official actions are complete.

## Reviewed formative scheme setup

`POST /api/v1/formative/schemes/configure` requires the **Principal** plus
`formative.template.manage`. Pass `batch_year_id`, institute-linked
`regulatory_source_id`, `review_reference`, and explicit published-source
`formative_method` (`points_ratio` or `template_weighted`). A selected
method is frozen, not silently replaced. Points-ratio setup requires mapped
official learning outcomes. The source must have published status and SHA-256.

`POST /api/v1/formative/templates/items` requires the same authority and
a configured source-backed `template_weighted` batch year. Provide
`batch_year_id`, `item_code`, `description`, `max_marks` as a positive
decimal string (up to 3 fractional places), `weight_percent` as a positive
decimal string (up to 4 places), and `review_reference`. Template entries
and weights are **explicitly supplied** from the applicable source; no
regulatory amounts or default weights are invented. Combined template weights
may not exceed 100%; the existing exam eligibility logic requires the full
100% and complete locked scores. Once scores exist, template additions are
blocked rather than changing historical calculation silently.

These setup routes do not implement template score submission or correction
on their own; those remain separate release gates.

