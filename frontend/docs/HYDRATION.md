# Frontend hydration — all current routes

## Meaning in this project

The frontend is **vanilla HTML, CSS and ES Modules** and the backend serves
JSON APIs, not server-rendered pages. Therefore React/Next/Vue-style **SSR
hydration is not applicable**. Here *hydration* means progressively applying
authenticated API data to a stable DOM shell, restoring safe per-route UI
preferences after client navigation, and preventing stale asynchronous work
from modifying the wrong view.

The application still requires sign-in after a full browser reload. It does
**not** cache institute or student record payloads, credentials, documents, fee
details, password drafts or API responses in localStorage/sessionStorage.

## Layers

| File | Responsibility |
| --- | --- |
| \`src/core/hydration/session-state.js\` | Bounded in-memory state for the **current authenticated session**; serializes simple UI preferences only; cleared on logout/session expiry |
| \`src/core/hydration/region.js\` | Stable asynchronous DOM region with \`idle/loading/refreshing/ready/error/disposed\` state; abort controller; prevents stale responses |
| \`src/app/controller.js\` | Route lifecycle: snapshot previous view, dispose/abort, restore allowed route snapshot, never re-create same active route for a hash-query change |
| \`src/shared/layouts/app-shell.js\` | Creates app shell once per authentication session, hydrates/updates active nav link classes without rebuilding all navigation nodes |
| \`src/shared/ui/filters.js\` | \`tableFilters({initialState})\` and \`hydrate(state)\`: restore search/sort and approved filter values without synthetic input events |
| \`src/shared/ui/columns.js\` | \`columnChooser({initialKeys})\` and \`hydrate(keys)\`: retain column visibility only for columns defined by the owning module |
| \`src/modules/registry/register-view.js\` | Existing institute and platform registers: retain toolbar, controls, current cursor and filters; hydrate only API result slot |
| \`src/modules/workspace/view.js\` | Fees/Attendance/OJT/Formative/Syllabus/Documents: retain each **authorized dataset**'s filters, columns and opaque cursor history separately; fetch fresh records on return |
| \`src/modules/institutes/profile.js\` | Keep profile heading mounted; populate/loading/error only inside the institute identity content region |
| \`src/modules/dashboard/view.js\` | Synchronous permission-aware content, marked ready by controller (no fake API loading) |
| \`src/modules/auth/views.js\` | Login/password-change render normally and are marked ready; **never snapshot passwords or form values** |

## Lifecycle contract

1. Anonymous app boots from \`index.html\` boot screen; \`renderLogin()\`
   mounts a native accessible form. Login tokens stay in JavaScript memory.
2. Successful profile validation creates one authorized app shell.
   \`renderRoute()\` assigns the page and hydrates a stable content region.
3. Initial read shows loading UI in an empty region. A refresh keeps the
   previous loaded table mounted while its replacement GET is pending;
   no fake data is inserted. The region exposes \`aria-busy\`.
4. The response is validated against the module's row key. Only the current
   request can update the region. If the read fails the region switches to
   an explicit error and retry view.
5. Leaving a route saves **presentation-only state** (dataset identity,
   visible column keys, search, local sort/filter, pagination cursor, and
   requested student ID when applicable). The old controller aborts reads
   and closes dialogs; no API records or form drafts are cached.
6. Returning to that route builds fresh controls seeded with the saved
   preference, then fetches **fresh authorized server data**.
7. Changing a workspace's dataset resets the stale result region and
   hydrates from its own saved state. A different institute/account never
   inherits that view state.
8. Logout, session expiration and password-change sign-out clear all
   presentation state before another login.

## Coverage of current frontend routes

| Route category | Current pages | Hydration behavior |
| --- | --- | --- |
| Authentication | Login, mandatory password change | Native forms stable during request; never persisted |
| Overview | Dashboard | Synchronous, profile-authorized render with ready/error status |
| Institute identity | Institute profile | Asynchronous stable profile slot |
| Generic institute registers | ITSU, Batches, Academic Calendar, Students, Staff, Enrollments, Seat Matrix | Saved local filters, cursor, columns; async abort and result-only refresh |
| Platform registers | Platform Institutes, Global Trades, Regulatory Sources | Same generic-register lifecycle, strictly scoped by profile |
| Fees | Payments, statements, head/structure/assessment/demand/refund/waiver registers | Authorized per-dataset state; latest API totals remain server-owned |
| Academics | Attendance sessions and records, OJT projects, Formative assessments, correction history | Per-dataset state; composite cursors preserved as strings, no guessed score/attendance eligibility |
| Syllabus | CTS course versions, course years and learning outcomes; platform DGT syllabus | Only source-backed GET results hydrated |
| Documents | Institute/own student document metadata | Data list only; files always fetched again with authenticated API, never stored by hydration |

Interactive modal forms, uploads, private files and financial write requests are
**not route-hydrated after navigation**. This deliberate exception protects
against replaying mutations, retaining personal documents and cross-account
exposure. They keep their DOM and controls while mounted and abort when closed
or the user signs out.

## Acceptance / exclusions

Hydration does **not** imply SSR or backend production acceptance. No external
framework, extra frontend dependency or independent government logic is added.
Behavior must be checked in a browser with real permissions/records and
keyboard assistive technology. These changes are source-level implementation
only; no automated tests, CI, Docker or browser verification was executed
without an explicit request.
