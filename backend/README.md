# Multi-Institute ITI ERP backend

Modular PHP 8.5.x API foundation using PDO and the existing SQL schema.
No framework or third-party runtime dependencies are required.

## Structure

```text
app/Core/              Environment and lazy database connection
app/Http/              Router and consistent JSON responses
app/Modules/           Health, Auth and read-only Institute profile
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

Authentication is implemented; business CRUD, record-level authorization,
migrations and business integrity workflows remain to be implemented.
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
cannot receive platform permissions. Only the scoped institute profile is
exposed as a business endpoint; write workflows remain unimplemented.
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
- Instructor assigned-record and student own-record access on business endpoints.
- Admission lifecycle, eligibility evidence, approvals and cancellations.
- Attendance, formative marks and exam-eligibility reconciliation.
- Concurrent enrollment/cancellation workflows with seat and quota locks.
- Governed OJT, exam, official-result and certificate imports/revisions.
- Full recovery drills and reconciliation for a populated application database.
