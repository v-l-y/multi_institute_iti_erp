# Frontend Architecture — V1 Foundation

## Scope and source of truth

- **Target:** Bihar state, private ITI institutes, multi-institute CTS management only.
- **Five foundations:** ITI Institute, Regulatory Body, Trades, Syllabus, Academic Calendar.
- The application may *record, reference, and display* official decisions. It cannot create DGT affiliations, ITICAT allotments, official exam results, certificates or government-portal updates.
- Backend PHP API and authorized source documents determine valid data and business behavior. UI labels, mock constants, client-side calculations and navigation do **not** create regulatory rules.
- No independent institute Trade or institute Shift master is introduced by frontend code. Current ITSU API uses the backend's actual contract.

## Dependency-free application layout

    frontend/
      index.html
      styles.css                     CSS import entrypoint
      package.json                   Node >=20, dev/start only
      dev-server.mjs                 loopback static + same-origin API proxy
      README.md
      docs/
        ARCHITECTURE.md
      src/
        main.js                      minimal entrypoint
        app/
          controller.js              session/route/view lifecycle composition
          routes.js                  combines module routes; denies unknown routes
        core/
          auth/
            session.js               in-memory bearer token (no browser storage)
          http/
            client.js                JSON envelopes, API failures, 401 handling
        shared/
          layouts/
            app-shell.js             responsive shell, navigation, tenant banner
          ui/
            index.js                 component barrel
            dom.js                   safe DOM node creation and formatting
            headers.js               register page heading
            status.js                semantic status labels
            fields.js                reusable labeled fields
            feedback.js              empty/loading/error states
            table.js                 reusable tabular view
            toast.js                 transient notices
            buttons.js               native actions and action menus
            permissions.js           frontend permission visibility
            pagination.js            cursor-aware pager
            filters.js               loaded-page search/filter/sort
            validation.js            generic native form validation
            select.js                select/multiselect fields
            dates.js                 native date/date-range controls
            file-upload.js           file selection and size feedback
            dialog.js                generic modal and confirmation
            tabs.js                  keyboard navigable tablist
            accordion.js             native disclosure controls
            skeleton.js              loading placeholders
            errors.js                error panels and recoverable boundaries
            README.md                shared UI catalog and API contracts
        modules/
          auth/
            views.js                 sign in and forced password change
          dashboard/
            routes.js
            view.js
          institutes/
            routes.js
            profile.js
          regulatory/
            routes.js
          trades/
            routes.js
          syllabus/
            README.md                no GET contract; UI must not fake syllabus
          calendar/
            routes.js
          admissions/
            routes.js
          students/
            routes.js
            create-config.js
          staff/
            routes.js
            create-config.js
          registry/
            register-view.js         cursor paging, scoped view lifecycle
            create-config.js         delegates to module-owned forms
            create-dialog.js         generic dialog, form submission
        styles/
          tokens.css
          primitives.css
          auth.css
          shell.css
          components.css
          shared-ui.css              reusable UI library styling
          erp-patterns.css           ERP form/academic/register composition
          responsive.css

No React, Vue, Angular, Vite, Tailwind, Bootstrap, TypeScript or third-party runtime is installed or required by this foundation. The chosen architecture can be migrated deliberately later, without implying a framework selection now.

## Runtime boundaries

1. **Entrypoint:** src/main.js imports app/controller.js. index.html loads only that module.
2. **Login:** auth/views.js gathers credentials. The app controller calls POST /api/v1/auth/login and GET /api/v1/auth/me. Token is stored only in core/auth/session.js memory.
3. **Access:** app/routes.js evaluates the permission list from /auth/me for UI visibility. It does not infer a grant from role name. Server-side PHP authorization and institute tenancy remain mandatory.
4. **Shell:** shared/layouts/app-shell.js handles sidebar, topbar, current route, mobile navigation, logout and persistent structure. Mobile closed navigation is inert; opening it moves focus into the menu, Escape closes it, and disposal removes listeners.
5. **View:** each route descriptor contains the exact backend endpoint and columns; views call core/http/client.js. Dashboard shows *available modules*, not fake numeric KPIs.
6. **Register:** modules/registry/register-view.js owns cursor, search, request abort and active dialog; it is disposed when the route or session changes. Refreshing records preserves the register toolbar and search focus. Table search covers **only the currently loaded page**.
7. **Mutation:** module-owned staff and student field configs feed the shared create dialog; POST requests go to existing guarded PHP endpoints. The dialog guards duplicate submits, blocks dismissal during a save, and closes/aborts stale UI requests when disposed. Aborting a browser request does not guarantee that an already-sent backend mutation was rolled back.
8. **Styling:** styles.css imports eight stylesheets in cascade order, including shared-ui.css. All UI uses shared tokens and reusable components.
9. **Local server:** dev-server.mjs serves an explicit root entry and restricted /src/*.js and /src/*.css file paths. It proxies /api/v1/* to the configured backend. Local Host and write-Origin checks reduce DNS rebinding/cross-origin write risk; proxy request bodies are size-bounded. It is loopback-only and not a production server.

## Foundation mapping

| Foundation | Current frontend owner | Source or constraint |
| --- | --- | --- |
| Institute | modules/institutes | Backend institute profile; institute identity comes from authenticated PHP context |
| Regulatory body | modules/regulatory | Backend regulatory document register; govt authority remains external |
| Trades | modules/trades | Global Trades (platform) and ITSU register (institute); no duplicate local trade/shift masters |
| Syllabus | modules/syllabus | **Structure reserved; read API/source-backed display not yet implemented** |
| Academic calendar | modules/calendar | Existing training calendar/intake and batches read APIs; dates sourced from backend |

Admission, Staff, Students and reusable Registry are application workflows subordinate to those foundations. Their existence does not create a sixth official source.

## Development rules

- Add a route only with an existing backend GET/POST endpoint and its exact permission. Use a source-backed owner module, not an invented UI-only rules engine.
- Keep module-specific field names and forms in their owner modules. Keep generic table, field, dialog, feedback and navigation code under shared/.
- API client is the only browser-to-API transport. Use response envelope {success, data, error}. Do not bypass permissions with query institute_id.
- Any source-derived status must be presented as the backend value, never silently promoted to verified/published by UI.
- Do not put credentials in localStorage, sessionStorage, URL, HTML or committed configuration.
- Client-side hiding is convenience only; every API mutation must still check backend authorization.
- Use no fake institute, seat capacity, teacher, attendance, marks, source reference, certificate or official approval data.
- Do not automatically run CI, tests, Docker builds or production deployments. Verification requires an explicit user request.

## Hardening review and manual verification checklist

Static inspection identified and addressed these issues in the frontend foundation:

- Register pages previously replaced headers and search controls on refresh, losing keyboard focus.
- Mobile sidebar previously allowed focus to reach navigation hidden outside the viewport.
- Logout previously kept the sensitive workspace visible while waiting for the network response.
- Dialogs previously allowed repeated Enter submits and stale callbacks after navigation.
- Local server previously lacked explicit Host/Origin restrictions.
- Root live region previously announced the entire application on updates; now only focused feedback regions announce changes.
- Route registration now rejects duplicate and missing permission/endpoint descriptors at runtime.

**Checks to perform manually when authorized** (not executed):

1. Log in as a platform developer and as an institute account with different explicit permissions. Confirm menu isolation and backend 403 behavior for disallowed API requests.
2. On a 320px-wide viewport, open mobile navigation, use Tab and Shift+Tab, press Escape, and resize to desktop. Check focus, inert state and backdrop behavior.
3. Navigate between registers, refresh, search, follow Next/Previous. Confirm search applies to the loaded page only, focus is preserved, and stale responses never replace a new route.
4. Open staff/student create dialog. Check native required-field validation, loading state, prevented duplicate saves, keyboard Escape while saving, and close when leaving the route.
5. Log out while a register is loading. Confirm records disappear immediately and no stale view is restored. Disconnect the API and verify visible error feedback.
6. Review color contrast with the browser accessibility tools and confirm screen reader landmark/heading order.

No CI, browser automation, Docker or local backend tests have been run for this hardening pass.

## ERP shared presentation patterns

The shared library has been extended for the institute-management registers and
future academic/business views. Core controls live under `src/shared/ui/`:
`form-layout.js`, `lookup.js`, `details.js`, `stat-cards.js`,
`activity.js`, `schedule.js`, `attendance.js`, `documents.js`,
`workflow.js`, `advanced-fields.js`, `columns.js`, `export.js`,
`drawer.js`, `editable-grid.js`, `audit-diff.js` and `navigation.js`.

Their styling is in `src/styles/erp-patterns.css`; combined with
`shared-ui.css` it loads after base styles and before responsive styles.
All component APIs and permission limits are documented in
[Shared UI catalog](../src/shared/ui/README.md).

The generic register now includes an explicit-column **View** action and
local column visibility chooser. It does not invent a record-details API or
expose hidden fields. Domain modules must supply verified read/write routes
before enabling other shared patterns; library availability alone is **not**
proof that the corresponding ERP workflow is implemented.


## Hydration architecture (all current routes)

This project has no SSR, so "hydration" is client-side DOM/data hydration rather
than attaching React/Vue handlers to server-rendered markup. The authenticated
app shell/nav persists between routes. Async pages keep a stable heading and
toolbars, using \`src/core/hydration/region.js\` to reconcile each API result
into its dedicated content region. Prior requests are aborted, obsolete results
cannot commit, and refresh never rebuilds the app shell.

\`src/core/hydration/session-state.js\` stores bounded route-presentation
preferences in JavaScript memory only. Both generic registers and all newer
fees/academic/syllabus/document workspaces snapshot and restore approved
search/sort, visible columns and pagination position, then always re-fetch
authorized API rows. Authentication forms and modal/write drafts are never
persisted. Session expiry and logout clear this state.

See [full hydration contract and current-route coverage](HYDRATION.md).

## Shared UI completion

The dependency-free shared component catalog, module exports and styling are implemented. Refer to [src/shared/ui/README.md](../src/shared/ui/README.md) for exact APIs and limits. Existing registers and Staff/Student creation dialogs use these shared primitives. Additional controls (tabs, date ranges, file selection, confirmations, accordion) are library-ready, but **not** tied to unfinished module endpoints.

Static verification and full browser/UAT validation are separate from implementation. No automated test or CI command was run for this phase.

## Current status / non-goals

This is the structure and shared foundation, **not** a finished ERP frontend or audited production deployment. Future work includes real syllabus GET and revision evidence support, complete fees/admission/attendance/OJT/assessment/document workflows, all-role UX, validated mutation forms, browser accessibility validation, responsive UAT, secure production hosting and integration acceptance.

No automated tests were run for this restructuring. Source files and route contracts were reviewed; execution against the user's local Docker or XAMPP backend remains unverified.
