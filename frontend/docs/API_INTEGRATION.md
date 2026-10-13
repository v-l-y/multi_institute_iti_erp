# ERP module API integration — source contracts

Implementation targets: Bihar private ITI institute management only. All writes call
existing validated PHP controllers; the frontend never grants affiliation, generates
official results, predicts academic eligibility or substitutes for government portals.

## Frontend workspaces

\`frontend/src/modules/workspace/routes.js\` declares 7 dedicated pages.
\`contracts.js\` owns exact GET endpoint, response-array key, column set, cursor
type and known POST form fields. \`view.js\` provides a single reusable scoped
register, current-page filtering, record details, permission-based actions,
cursor pagination, server failure states and cleanup on route changes.
\`action-dialog.js\` converts explicitly typed IDs/minutes to JSON integers;
amounts/marks remain decimal strings. Forms are disabled during POST and
financial idempotency keys remain stable across ambiguous retries.

| Screen | Backend read APIs | Backend write APIs linked |
| --- | --- | --- |
| Fees | \`/fees/payments\`, \`/fees/payments/me\`, \`/fees/{heads,structures,structure-items,assessments,demands,refunds,waivers,reversals}\`, \`/fees/statements\`, \`/fees/statements/me\`, \`/fees/receipts\`, \`/fees/receipts/me\`, \`/fees/statements/export\`, \`/fees/statements/export/me\` | Head/structure/item/activate, assessments/issue/demand, payment/allocations, waivers, refunds/decide, reversal request/approval, cash transfer/close/review |
| Attendance | \`/attendance/sessions\`, \`/attendance/records\`, \`/attendance/records/assigned\`, \`/attendance/records/me\`, \`/academic/corrections?type=attendance\` | Session create/lock, assigned marking, reviewed correction |
| OJT | \`/ojt/projects\`, \`/ojt/projects/me\` | Principal plan/start, own trainee logbook, assigned instructor evaluation |
| Formative | \`/formative/records\`, \`/formative/records/assigned\`, \`/formative/records/me\` with points/template type; \`/academic/corrections?type=formative\` | Assigned score (LO and template), principal scheme/template review, locks and reviewed corrections |
| CTS syllabus | \`/syllabus/curricula\`, \`/syllabus/years\`, \`/syllabus/outcomes\` (new source-backed GET controllers) | Existing \`/academic/curriculum-links\` and \`/academic/curriculum-year-links\` |
| Documents | \`/documents\`, \`/documents/me\`, \`/documents/download\`, \`/documents/download/me\` | \`/documents/upload\`, \`/documents/archive\` |

All URLs above are relative to \`/api/v1\`. User-visible dataset selectors
only show permissions actually granted to the profile, while backend endpoints
enforce current token, tenant, role, student/staff mappings and transitions.

## Backend GET additions

\`backend/app/Modules/Regulatory/CurriculumReadController.php\` exposes only
**published** \`global_curriculum_versions\`, \`global_curriculum_years\`
and \`global_learning_outcomes\` whose linked source is a published DGT regulatory
document. Supports \`page_size\`, \`after_id\`, optional course version and year
filters; bounded pages and ID cursor. Institute users require \`curriculum.view\`,
platform users require \`platform.regulatory.manage\` plus Developer role.

\`AttendanceSessionController::index\` returns institute-scoped sessions for
\`attendance.view\`, and filters to mapped instructor sessions when a user
has only \`attendance.view.assigned\`. Includes real batch/year/date/component,
scheduled minutes, assigned instructor and lock timestamp.

## Cursor differences

- Most GET arrays return \`next_after_id\` (start with none and pass \`after_id\`
  only when provided).
- Attendance returns \`next_cursor\` containing both \`after_session_id\` and
  \`after_enrollment_id\`.
- Formative points returns \`next_cursor.after_id\`; formative template rows
  return \`after_enrollment_id\`, \`after_batch_year_id\` and \`after_item_code\`.
- Fees statements require a positive \`student_id\` except own-student versions.
  The page shows **backend-computed** totals; the browser performs no ledger math.
- Search and column toggling affect only the loaded page. No invented global total.

## Secure document transport

The PHP upload contract is **JSON base64**, NOT multipart: authenticated
\`POST /api/v1/documents/upload\` with \`{student_id,filename,content_base64}\`.
The UI enforces 5 MiB per file and restricts selection to PDF/PNG/JPEG, but
the server independently checks magic MIME, size, institute/student identity
and private storage integrity. Reading the file uses \`FileReader\`, and aborts
when the dialog closes. Do not log file contents or session tokens.

Binary downloads use \`api.download(path)\` with the bearer header, not a token
in a query string and not a public file-storage URL. Blob type/size is checked
and a short-lived object URL is revoked. Fee statement CSV uses the same bearer
request with a narrow allowed MIME list; only the returned page is exported.

## No fabricated authority

All selected fee modes/amounts, formative scheme weights, official OJT hours,
calendar components, evaluator grades and source references must be supported
by the PHP services and their stored regulatory/institute evidence. Form
options that correspond to strict backend enum validation are copied from
the current controllers; none implies a universal DGT/Bihar policy. Approval,
review and audit operations remain subject to actual backend authorization.

## Verification status

**Implemented in the repository; not yet end-to-end certified.**
Code changes and backend contract reads are not a substitute for PHP/database
execution. No CI, Docker, automated testing, browser testing, security audit or
live UAT was run in this integration pass (per explicit user instruction).
Real source documents, storage root configuration, permissions and valid
institute records remain prerequisites for successful operations. UI workflows
for other ERP features not listed here are outside this integration scope.
