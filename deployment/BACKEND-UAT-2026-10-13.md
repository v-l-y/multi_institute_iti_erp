# Backend dummy-data UAT rehearsal — 13 October 2026

Status: automated backend rehearsal PASS; institute staff acceptance PENDING.
Code baseline: 811c8ff. Execution: ops/verify-backend.ps1, Docker project iti-erp-verify-09d7c18c0dba. Run completed successfully with exit code 0. Disposable containers and their isolated database were removed after execution; no persistent UAT login environment was created.

## Data and environment

Fictional institute A/B, students, teaching/non-teaching staff, five authentication roles, sample trade/ITSU, calendar, batch, enrollment, attendance, assessments and fee transactions were exercised through existing fixtures and workflow tests. These are deliberately synthetic records, not official affiliation, government rules or real personal data. This run used PHP 8.5 and MariaDB 10.11 in a separate test stack without application volumes, host database ports or local secrets. Existing staging was not upgraded by this run.

## Fresh execution evidence

| Suite | Checks | Result |
|---|---:|---|
| Schema contracts | 30 | PASS |
| Backend contracts | 365 | PASS |
| Proxy security | 8 | PASS |
| Runtime grants | 4 | PASS |
| Authentication integration | 75 | PASS |
| HTTP workflows, five roles and tenant boundaries | 524 | PASS |
| Fee SQL integration | 11 | PASS |
| Finance lifecycle | 36 | PASS |
| Exam cycle | 7 | PASS |
| Fictional local result import | 15 | PASS |
| Foundation integrity and lock serialization | 104 | PASS |
| Pending institute workflows | 33 | PASS |
| Upload/decimal validation | 16 | PASS |
| Total | 1228 | PASS |

## Exercised workflow coverage

- Authentication, session revocation, denied permissions and institute/own-record boundaries.
- Institute/academic configuration HTTP flows and admission, seat accounting and cancellation integrity.
- Fee demand, payment allocation, independent waiver approval, refund, reversal, immutable receipt identity and cash closing.
- Attendance recording/locking, formative assessment, immutable correction history and preserved frozen eligibility. Future eligibility uses approved corrected evidence.
- Private document upload, own download, institute boundaries, prohibited filenames/types, tamper detection and archive retention.
- Local recovery generic responses, account cooldown, single-use token reset and session revocation.
- Student profile correction, Principal-authorized transfer and retained historical intake consumption.
- Internal CSV and printable HTML receipt HTTP access.
- Populated previous-canonical-schema migration rehearsal, student preservation and migration replay protection.

No unexpected assertion failure occurred. Deliberate malformed-request and unavailable-audit scenarios emitted expected diagnostic logs; these were not unhandled test failures. The aggregate includes static/unit contracts as well as integration checks and is not 1228 distinct user journeys.

## Remaining acceptance work

| Scenario | Acceptance evidence still needed |
|---|---|
| Persistent local UAT environment | Upgrade staging safely and create clearly marked demo accounts/data for staff use |
| Complete frozen V1 coverage | Map all 12 modules to requirements; this suite does not certify every frozen requirement |
| Representative institute operations | Staff-entered admission-to-completion journeys, corrections and report reconciliation |
| Recovery boundaries | Dedicated expired/inactive-token scenarios and operator outbox handling |
| Academic correction variants | Dedicated template-mark correction and role-specific read scenarios |
| Enrollment variants | Dedicated successful dropout/completion and date-boundary acceptance |
| Data safety | Independent database plus private-volume backup/restore rehearsal |
| Realistic scale | Representative student/batch sizes and concurrent-user/load acceptance |
| Regulatory alignment | Actual institute-specific authoritative source applicability review |
| Release | Deployment-specific HTTPS, monitoring, approved RBAC policy and authorized staff sign-off |

Automated rehearsal establishes backend evidence for covered cases. It does not establish live staff UAT, regulatory certification, frontend completion or production release approval.
