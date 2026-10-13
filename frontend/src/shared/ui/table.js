import { el, formatValue } from "./dom.js";
import { status } from "./status.js";
import { button } from "./buttons.js";

/**
 * Accessible data table. Optional selection and actions are caller-owned.
 * No business mutation, role grant or HTML string interpolation occurs here.
 */
export function dataTable(rows, columns, {
  caption = "Register records", selection = null, rowActions = null
} = {}) {
  if (!Array.isArray(rows) || !Array.isArray(columns)) {
    throw new TypeError("Table requires rows and columns");
  }
  if (selection && (typeof selection.idKey !== "string" ||
      typeof selection.onChange !== "function")) {
    throw new TypeError("Row selection needs an ID key and a callback");
  }
  const selectedIds = new Set(selection?.selectedIds || []);
  const rowId = row => row?.[selection.idKey];
  const validIds = selection
    ? rows.map(rowId).filter(id => id !== null && id !== undefined) : [];
  const header = el("tr");
  const selectable = Boolean(selection);
  const rowCheckboxes = [];
  function syncSelection() {
    for (const [control, id] of rowCheckboxes) control.checked = selectedIds.has(id);
    if (selectAll) {
      selectAll.checked = validIds.length > 0 && validIds.every(id => selectedIds.has(id));
      selectAll.indeterminate = !selectAll.checked && validIds.some(id => selectedIds.has(id));
    }
  }
  const selectAll = selectable ? el("input", {
    type: "checkbox", "aria-label": "Select all rows on this page"
  }) : null;
  if (selectAll) {
    selectAll.checked = validIds.length > 0 && validIds.every(id => selectedIds.has(id));
    selectAll.indeterminate = !selectAll.checked && validIds.some(id => selectedIds.has(id));
    selectAll.disabled = validIds.length === 0;
    selectAll.addEventListener("change", () => {
      for (const id of validIds) {
        if (selectAll.checked) selectedIds.add(id);
        else selectedIds.delete(id);
      }
      syncSelection();
      selection.onChange(new Set(selectedIds));
    });
    header.append(el("th", { scope: "col", className: "selection-cell" }, selectAll));
  }
  header.append(...columns.map(([, title]) => el("th", { scope: "col" }, title)));
  if (typeof rowActions === "function") header.append(el("th", { scope: "col" }, "Actions"));

  const body = el("tbody");
  for (const row of rows) {
    const cells = [];
    if (selectable) {
      const id = rowId(row);
      const selectableRow = id !== null && id !== undefined;
      const checkbox = el("input", {
        type: "checkbox", "aria-label": "Select row " + String(id ?? ""),
        disabled: !selectableRow
      });
      checkbox.checked = selectableRow && selectedIds.has(id);
      checkbox.addEventListener("change", () => {
        if (checkbox.checked) selectedIds.add(id); else selectedIds.delete(id);
        syncSelection();
        selection.onChange(new Set(selectedIds));
      });
      rowCheckboxes.push([checkbox, id]);
      cells.push(el("td", { className: "selection-cell" }, checkbox));
    }
    cells.push(...columns.map(([key]) => {
      const value = row?.[key];
      const semanticStatus = /^(status|affiliation_status|version_status|source_status)$/.test(key);
      return el("td", {}, semanticStatus ? status(value) : formatValue(value));
    }));
    if (typeof rowActions === "function") {
      const actions = rowActions(row) || [];
      cells.push(el("td", {}, actions.length
        ? el("div", { className: "row-actions" },
            ...actions.filter(action => action.visible !== false).map(action => button(action.label, {
              variant: action.danger ? "danger" : "ghost",
              disabled: Boolean(action.disabled),
              onClick: action.onClick
            })))
        : "—"));
    }
    body.append(el("tr", {}, ...cells));
  }
  return el("div", {
    className: "table-scroll", role: "region",
    "aria-label": caption, tabindex: "0"
  }, el("table", { className: "data-table" },
    el("caption", { className: "visually-hidden" }, caption),
    el("thead", {}, header), body));
}
