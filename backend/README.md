# Multi-Institute ITI ERP backend

Modular PHP 8.5.x API foundation using PDO and the existing SQL schema.
Internal admission/fee/exam transaction services and SQL record guards are documented in [FOUNDATION_UPGRADE.md](database/schema/FOUNDATION_UPGRADE.md). Selected guarded admission, exam and fee transitions are exposed through HTTP; service-level checks remain mandatory. Existing databases require the manual reviewed upgrade; rebuilding Docker alone does not apply it.
No framework or third-party runtime dependencies are required.

## Structure

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
User provisioning for staff/students, refresh and forgot-password delivery
are not implemented in this authentication increment.
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
Permission grants still require your reviewed RBAC policy/seed. Institute users
must be provisioned with the appropriate same-institute staff/student link and
role assignment; there is no public registration endpoint.

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
