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
- API 401 clears the local session and hides the workspace. Logout requests POST /api/v1/auth/logout before clearing local state; if the backend is unreachable the frontend warns that server revocation was not confirmed.
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

    frontend/
      index.html          Browser entrypoint
      styles.css          Shared responsive tokens, components and states
      package.json        Only local dev/start scripts; no dependencies
      dev-server.mjs      Loopback static server and same-origin API proxy
      src/
        api.js            JSON contracts, in-memory bearer auth, errors
        registry.js       Page/permission/endpoint definitions
        ui.js             Accessible, DOM-safe UI primitives
        main.js           Login, password change, navigation, registers, forms

### Extension rules

1. Only add a page when a real backend route and permission exist. Add its definition in src/registry.js and reuse common src/ui.js components.
2. Every institute data request derives tenant scope **on the backend** from the authenticated account. Never add frontend-controlled institute selection to institute APIs.
3. Preserve current-stable layout; don't overwrite the whole screen during register refresh.
4. Keep the local proxy loopback-only. Production requires a reviewed HTTPS/static-host and API reverse-proxy setup; dev-server.mjs is **not production deployment**.
5. Do not add pretend syllabus/calendar/government rules, seed fake records, expose government portal credentials, or claim official export certification.

## Not yet delivered

Complete Admission, Fees, Attendance, OJT, Assessments, Documents, Reports, Exams and institute setup/edit workflows; full CRUD and role-specific self-service; i18n; download UX; production static hosting; live browser UAT and production acceptance. These are later workflow implementations, not foundation completion claims.

**No automated tests or CI runs were executed as part of this frontend foundation.** Run checks only when explicitly requested.
