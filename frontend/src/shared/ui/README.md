# Shared UI Library — API and integration guide

All shared controls are implemented using native HTML elements, CSS and dependency-free JavaScript modules. Import them from **src/shared/ui/index.js**. Do not create competing per-module versions.

**Scope:** reusable presentation and local interaction for Bihar private ITI institute ERP. No official source rules, government decisions, permit/status calculations, API endpoint assumptions, or tenant authorizations are implemented here. PHP backend APIs, authenticated tenants and source documents remain authoritative.

## Component API

| Component | Exports | Behavior |
| --- | --- | --- |
| DOM / formatting | el, formatValue | Constructs DOM nodes with text nodes, avoiding interpolation of API text into HTML |
| Actions | button, actionMenu | Native buttons, busy/disabled state, details-based accessible action list |
| Permission-aware action | hasPermission, permissionButton | Hides actions without explicit permission and matching institute/platform scope; **not server authorization** |
| Table | dataTable | Explicit columns, caption, header semantics, scrollable region, status cell formatting; optional row selection and caller-owned actions |
| Page search/filter/sort | tableFilters, filterRows | Local current-page search, explicit-value filters and numeric-aware string sorting, no fake DB-wide results |
| Cursor pager | cursorPagination | Previous/next and page position, no invented total count |
| Form basics | field, selectField, multiSelectField | Labels, required attributes, hints, native select and checkbox groups |
| Validation | validateForm, formErrorSummary | Native constraints and optional cross-field rules (not regulatory business rules) |
| Dates | dateField, dateRange | Native date entry with end >= start constraint only |
| Files | fileUpload | Native file choice, per-file size guard and names; **does not upload files** |
| Modal | openDialog, confirmDialog | Focus-managed native dialog, optional async confirmation and error feedback |
| Tabs | tabs | Role-based tablist with arrow/home/end keys and panel state |
| Accordion | accordion | Native details/summary sections |
| Loading | loadingState, skeleton | Spinner and reduced-motion skeleton |
| Feedback | notice, emptyState, toast, errorPanel | Polite/error live messages, empty content, temporary toast, explicit retry |
| Error boundary | renderSafely, renderAsyncSafely | Synchronous/async recoverable UI with route abort awareness |
| Headers | pageHeader | Consistent section heading, description, action slot |
| Status | status | Renders source-provided text; colors are presentation only, never an approval authority |

## API usage (no mock records)

    import {
      dataTable, tableFilters, filterRows, cursorPagination, skeleton,
      permissionButton, validateForm, openDialog
    } from "../../shared/ui/index.js";

An institute register calls an **actual** backend API and provides its **actual** result array to \`dataTable(rows, page.columns, { caption: page.label })\`. For a module-specific form, only supply field definitions already accepted by the matching backend POST endpoint. No frontend component decides sanctioned seats, trade affiliation, attendance eligibility, curriculum weights, approval, certification or fee calculations.

### Forms

\`validateForm(form, { matches: [["confirm", "new_password", "Passwords differ"]], rules: [...] })\` resets previous custom validity, verifies native constraints, reports errors and focuses the first invalid element. Optional \`rules\` use functions supplied by the owning module. **Do not invent government rules in shared validation.**

\`multiSelectField(name, label, options, { required: true })\` uses native checkbox controls; \`FormData(form).getAll(name)\` returns selected values. \`selectField\` uses native select; \`dateRange\` uses native date inputs, not a third-party calendar. The caller is responsible for providing backend-compatible field names and handling submission.

### File controls

\`fileUpload(name, label, { accept, multiple, maxBytes, onChange })\` **selects** local files only. It never POSTs, reads file content, or guesses an upload URL. Client \`accept\` and byte limits are user experience helpers; a future authorized backend upload must recheck size, type, content, storage permissions and institute isolation. Never send personal documents to a placeholder endpoint.

### Confirmations

\`confirmDialog({ title, message, confirmText, danger, onConfirm })\` invokes \`onConfirm\` at most once during its active submission, displays failure instead of closing, and uses the native dialog keyboard behavior. Only the owning module supplies an authorized destructive action. No generic shared component deletes or edits ERP data by itself.

### Tables and permissions

\`tableFilters({ columns, filters, onChange })\` exposes \`read()\`; pass this into \`filterRows(rows, read())\`. Search/sort only covers the **currently returned page**, not the full institute database. \`cursorPagination({ onPrevious, onNext })\` never infers an unseen page or row count.

\`permissionButton(user, "staff.manage", "Add staff", { scope: "institute", onClick })\` returns \`null\` without permission. This **cannot replace** PHP-side RBAC, signed-in session checks or tenant scoping. Never pass a frontend-selected institute ID to bypass backend tenancy.

### Navigation / accessibility / fallback

\`tabs\` implements Left/Right/Home/End and tab focus. \`accordion\` uses native HTML details. Buttons, fields, selectors, checkboxes and modals use native keyboard semantics. \`skeleton\` respects reduced motion.

\`renderAsyncSafely(container, producer, { signal, loading, onRetry })\` supports abort-aware asynchronous rendering and an explicit recovery action. Do not automatically retry POST/PUT/DELETE operations, as retry may duplicate a completed mutation. For a synchronous renderer, use \`renderSafely(container, render, { onRetry })\`.

## CSS

Styles load through \`frontend/styles.css\`, with \`src/styles/shared-ui.css\` imported before responsive CSS. This covers action menus, table controls, pager, forms, modal states, tabs, accordion, skeleton and error panels. Shared spacing/design tokens live in \`src/styles/tokens.css\` and focus styling in \`primitives.css\`.

## Adopted in live screens

- Generic institute/platform registers now use **tableFilters + filterRows + cursorPagination + dataTable + skeleton + errorPanel + permissionButton**.
- Staff/student record creation uses **openDialog + button + validateForm + field + notice + toast**.
- Auth and dashboard retain their existing shared fields/feedback/UI primitives.

File inputs, date range, checkbox group, tabs, accordion, confirmations and asynchronous view boundary are implemented and exported as **library controls**. They are **not claimed to be integrated with unfinished backend modules**, because no verified endpoint/form workflow has been supplied. This distinction prevents fake functionality.

## Completion criteria and review status

The reusable component implementation and barrel exports above are committed. This does **not** certify production readiness, WCAG AA conformance, or finished business workflows. Local browser execution, keyboard/assistive technology review, security validation, UAT and backend integration remain necessary before approval.

**No CI, automated tests, Docker checks, or local runtime execution were performed**, in accordance with the user's explicit instruction to run them only on request.


## ERP composition patterns (additional components)

These patterns deliberately operate on caller-provided values. They do not create any Bihar ITI policy, admission decision, calendar milestone or government status.

| Pattern | Exports | What it provides |
| --- | --- | --- |
| Form layout | formSection, formGrid, formActions, readForm | Accessible section legends, responsive multi-column layouts, save/cancel state, native FormData including multiselect |
| Staff/trade/batch master selectors | masterLookup | Async server-backed native select with user search, refresh, abort and stale-request protection; caller must provide permitted loader |
| Record view | recordDetails, openRecordDetails, openDrawer | Explicit-field detail grid, native modal or side panel, focus/close behavior |
| Metrics | statCards | Real backend metric values only, no fake KPI calculations |
| Audit/approval history presentation | activityTimeline, auditDiff | Supplied event timeline and before/after field differences, never an approval engine |
| Academic display | timetableGrid, monthCalendar | Explicit weekday/slot and ISO-date data; no inferred shift, holiday or session rules |
| Attendance display | attendanceMatrix | Read-only matrix over provided people/dates/statuses, no computed eligibility |
| Document display | documentList | Supplied metadata and authorized caller-supplied actions; no guessed storage link |
| Workflow navigation | progressSteps, formWizard | Visual steps and stable multi-step form state; owning module supplies real submit logic |
| Specialized fields | textareaField, numberField, amountField, toggleField, radioFields, timeField | Native text, amount, flag, radio and time fields; no automatic financial rules |
| Register columns | columnChooser | User-visible column selection; hides presentation only, not a security filter |
| Download/print | csvText, downloadCurrentPageCsv, printCurrentPage | Explicit selected-column, loaded-page output requiring a caller-provided authorization predicate; CSV formula injection mitigation |
| Repeated entries | editableGrid | Add/remove/edit rows for module-owned forms, with native validity; never auto-calculates/saves |
| Navigation | breadcrumbs, filterChips, bulkActions | Accessible supplied routes, active filter display and callback-controlled selected-row actions |

### Operational boundaries

**Backend verified:** Shared controls accept the actual backend-supplied values and exact field definitions. \`masterLookup\` has no built-in API URL; its \`loadOptions(query, {signal})\` callback must return \`[{value, label}]\` only from an authorized institute/platform endpoint. Always call \`lookup.dispose()\` when leaving the module. Search result labels and IDs are not persisted in browser storage.

**Limited to the current loaded page:** Table search/sort, column selection, record view, CSV and print all operate on the returned rows only. CSV/print are *not automatically available in the ERP screen*. Any module exposing them must check an explicitly granted export permission and any privacy rules server-side as well. A locally downloaded CSV is not certified for SIDH/NIMS/ITICAT government submission.

**Read-only by default:** Timetable, monthly academic calendar, attendance matrix, history, audit diff and document list show records; they cannot certify attendance, mark exam eligibility, publish an academic calendar, issue results or edit source documents.

**No invented workflow:** Wizard step count and visible step labels come from the owning module. \`progressSteps\` never means official approval. A client-side confirm dialog alone is not an authorization/independent approval control.

### Currently integrated screens

- Existing authenticated registers: shared search/sort/pagination/skeleton/errors, column chooser, row **View** dialog, table and permission-aware Add action.
- Staff and Students: shared labels, buttons, forms and modal validation.
- Other components are exported and CSS-ready, but not wired to unfinished Fees, Attendance, Admission, Assessments, Document storage, OJT or Exams workflows. That integration requires their verified PHP endpoints, source fields and user permissions.

### Verification still required

No CI/automatic test, local Node startup, real Docker/backend, browser UAT, WCAG audit, export authorization review or accessibility certification has been executed for this extension. Code review and repository file verification alone do not prove those behaviors work in a browser. Do not mark the full ERP as production-ready on this basis.
