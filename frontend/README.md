# ITI ERP frontend foundation (V1)

This is the first working **frontend foundation**, built against the current PHP backend contracts. It is a dependency-free browser application (ES modules, CSS, Node development server), not a completed ERP business UI.

## V1 boundary

Bihar **private ITIs only**, multiple independent institutes, CTS-oriented institute management. Its five foundations are **Institute, Regulatory Body, Trades, Syllabus, Academic Calendar**. DGT, NCVET, State authorities and government portals remain external authorities. Frontend labels do not imply that a local ERP record is an official affiliation, seat allotment, examination result or certificate.

## Start locally on Windows

1. Pull the latest main branch, without overwriting uncommitted local edits:

       git pull --ff-only origin main

2. Start the project's existing backend according to the root README. The default frontend proxy expects its HTTP API on http://127.0.0.1:8080. For the default Docker setup, browse http://127.0.0.1:8080/api/v1/health to confirm the API endpoint is reachable. Set up the first account and reviewed RBAC grants using the backend instructions.

3. In a **new PowerShell window**:

       cd C:\xampp\htdocs\multi_institute_iti_erp\frontend
       node --version
       npm run dev

   Node 20 or later is required.

4. Open **http://127.0.0.1:5173**. The Node server binds only to 127.0.0.1. No npm install is needed; there are no runtime dependencies. No tests, Docker builds or CI workflows are triggered by this command.

For an API on another port, set the proxy target **before** starting:

       $env:BACKEND_URL = "http://127.0.0.1:8000"
       $env:FRONTEND_PORT = "5173"
       npm run dev

Do not open index.html with the file:// protocol: browser modules and the same-origin backend proxy require an HTTP server.

## Sign in

- Institute users enter **username, password and their numeric institute ID** (assigned during backend account provisioning).
- Platform Developer accounts leave Institute ID blank. An institute role never becomes a platform role simply by changing UI input.
- The browser uses the backend POST /api/v1/auth/login, bearer token and GET /api/v1/auth/me contracts.
- Access tokens exist **only in JS memory** (not localStorage, sessionStorage, URL or cookies). A refresh requires signing in again.
- Initial logins requiring a password update use POST /api/v1/auth/change-password; the backend invalidates sessions and the frontend returns to login.
- API 401 clears the local session and hides the workspace. Logout starts POST /api/v1/auth/logout and immediately hides the local workspace; it then reports whether backend session revocation was confirmed.
- Deny by default: navigation and create actions are displayed only for permissions returned by /auth/me. **Backend authorization is always the final enforcement layer.** No role names are treated as blanket grants; developer users cannot browse institute-private data by default.

## Implemented surfaces

| Frontend | Backend contract | Required permission |
| --- | --- | --- |
| Workspace dashboard | auth/me profile, authorized navigation (no fake counts) | Authenticated |
| Institute profile | GET /api/v1/institute/profile | institute.profile.view |
| ITSU register | GET /api/v1/itsu | tradeunit.view |
| Batches | GET /api/v1/batches | batch.view |
| Training calendar / intakes | GET /api/v1/academic/calendars | calendar.view |
| Students register | GET /api/v1/students | student.view |
| Staff register | GET /api/v1/staff | staff.view |
| Enrollments | GET /api/v1/enrollments | enrollment.view |
| Seat matrix | GET /api/v1/seat-matrix/entries | seatmatrix.view |
| Platform institute register | GET /api/v1/platform/institutes | platform.institute.view |
| Global trades | GET /api/v1/platform/trades | platform.regulatory.manage |
| Regulatory sources | GET /api/v1/platform/regulatory/documents | platform.regulatory.manage |
| Create staff | POST /api/v1/staff | staff.manage plus register visibility |
| Create student identity | POST /api/v1/students | student.manage plus register visibility |

Read-only register pages use backend cursor pagination (page_size=25, next_after_id), previous/next navigation, **search within the currently loaded page only**, refresh, and loading/empty/error states. User-supplied data is inserted with DOM text nodes, never interpolated into HTML.

The creation forms use only fields expected by the current backend and do **not** issue official government registrations or admissions. No cross-institute selection or fake platform data is provided.

## Architecture

The frontend now uses a layered and module-owned structure. See the
[complete folder tree, source mapping and extension rules](docs/ARCHITECTURE.md).

The reusable component library is documented in [Shared UI API](src/shared/ui/README.md).
It provides native forms, data tables, loaded-page sorting/filtering, cursor pagination,
permission-aware actions, dialogs, confirmation, date and multi-select controls,
file selection, tabs, accordion, skeletons and recoverable errors. Actual register
pages and Staff/Student forms now consume those shared controls; unimplemented ERP
business screens are not treated as integrated merely because a control exists.

- `src/main.js` is a minimal entrypoint.
- `src/app/` owns route/session/view orchestration.
- `src/core/` contains in-memory authentication and the shared API client.
- `src/shared/` owns UI primitives and the responsive application shell.
- `src/modules/` owns institute, regulatory, trade, syllabus, calendar and
  linked institute-business modules.
- `src/styles/` holds tokens, components, shell, auth and responsive CSS.
- `styles.css` remains the stylesheet entrypoint.

The syllabus directory is reserved for a future **verified source-backed read
API**. It is not presented as a working UI route, because the backend contract
has not been confirmed.

### Extension rules

1. Add module routes in that module's `routes.js`; the route collection in
   `src/app/routes.js` is the only navigation index.
2. Use `src/core/http/client.js` for network requests; authorization is
   always enforced by PHP backend permissions and tenant scope.
3. Put domain-specific forms in domain modules, generic table/field/dialog
   foundations in `src/shared/` and `src/modules/registry/`.
4. Keep register toolbars stable across refreshes, abort obsolete reads, and dispose dialogs when routes or sessions change.
5. No mock official rules, statuses, registrations or government data.
6. The local Node server is not a production deployment.

## Not yet delivered

Complete Admission, Fees, Attendance, OJT, Assessments, Documents, Reports, Exams and institute setup/edit workflows; full CRUD and role-specific self-service; i18n; download UX; production static hosting; live browser UAT and production acceptance. These are later workflow implementations, not foundation completion claims.

**No automated tests or CI runs were executed as part of this frontend foundation.** Run checks only when explicitly requested.
