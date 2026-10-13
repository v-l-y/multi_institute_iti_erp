/* Stable public UI API. Module consumers import from this entrypoint only. */
export { el, formatValue } from "./dom.js";
export { status } from "./status.js";
export { notice, emptyState, loadingState } from "./feedback.js";
export { dataTable } from "./table.js";
export { field } from "./fields.js";
export { toast } from "./toast.js";
export { pageHeader } from "./headers.js";
export { button, actionMenu } from "./buttons.js";
export { hasPermission, permissionButton } from "./permissions.js";
export { cursorPagination } from "./pagination.js";
export { filterRows, tableFilters } from "./filters.js";
export { validateForm, registerValidator, formErrorSummary } from "./validation.js";
export { selectField, multiSelectField } from "./select.js";
export { dateField, dateRange } from "./dates.js";
export { fileUpload } from "./file-upload.js";
export { openDialog, confirmDialog } from "./dialog.js";
export { tabs } from "./tabs.js";
export { accordion } from "./accordion.js";
export { skeleton } from "./skeleton.js";
export { errorPanel, renderSafely, renderAsyncSafely } from "./errors.js";

export { formSection, formGrid, formActions, readForm } from "./form-layout.js";
export { masterLookup } from "./lookup.js";
export { recordDetails, openRecordDetails } from "./details.js";
export { statCards } from "./stat-cards.js";
export { activityTimeline } from "./activity.js";
export { timetableGrid, monthCalendar } from "./schedule.js";
export { attendanceMatrix } from "./attendance.js";
export { documentList } from "./documents.js";
export { progressSteps, formWizard } from "./workflow.js";
export { textareaField, numberField, amountField, toggleField, radioFields, timeField } from "./advanced-fields.js";
export { columnChooser } from "./columns.js";
export { csvText, downloadCurrentPageCsv, printCurrentPage } from "./export.js";
export { openDrawer } from "./drawer.js";

export { editableGrid } from "./editable-grid.js";
export { auditDiff } from "./audit-diff.js";
export { breadcrumbs, filterChips, bulkActions } from "./navigation.js";
