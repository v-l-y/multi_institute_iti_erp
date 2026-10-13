import { el } from "./dom.js";
import { button } from "./buttons.js";

/**
 * Repeatable rows have stable native form names: entries[0][code], ...
 * Owning module decides backend contract and always may use grid.read().
 */
export function editableGrid(columns = [], initialRows = [], {
  title = "Entries", allowAdd = true, allowRemove = true,
  addLabel = "Add row", namePrefix = "entries", onChange
} = {}) {
  if (!Array.isArray(columns) || !columns.length || !Array.isArray(initialRows) ||
      !/^[A-Za-z][A-Za-z0-9_]*$/.test(namePrefix) ||
      columns.some(col => !col || !/^[A-Za-z][A-Za-z0-9_]*$/.test(col.key || "")) ||
      new Set(columns.map(col => col.key)).size !== columns.length) {
    throw new TypeError("Editable grid needs unique field keys and a valid namePrefix");
  }
  const tbody = el("tbody");
  const count = el("span", { className: "muted", "aria-live": "polite" });
  const container = el("section", { className: "erp-edit-grid", "aria-label": title });
  const table = el("table", { className: "data-table" },
    el("caption", {}, title),
    el("thead", {}, el("tr", {},
      ...columns.map(({ label }) => el("th", { scope: "col" }, label)),
      allowRemove ? el("th", { scope: "col" }, "Action") : null)), tbody);
  const add = button(addLabel, { onClick: () => appendRow() });
  add.hidden = !allowAdd;

  const read = () => [...tbody.rows].map(row => Object.fromEntries(
    columns.map(({ key }, i) => {
      const control = row.cells[i].querySelector("input, select, textarea");
      return [key, control.type === "checkbox" ? control.checked : control.value];
    })));

  function updateNames() {
    [...tbody.rows].forEach((row, index) => columns.forEach(({ key }, column) => {
      const control = row.cells[column].querySelector("input, select, textarea");
      control.name = namePrefix + "[" + index + "][" + key + "]";
    }));
    count.textContent = tbody.rows.length + " rows";
  }
  function emit() { updateNames(); onChange?.(read()); }

  function appendRow(values = {}, notify = true) {
    const row = el("tr");
    for (const column of columns) {
      const { key, label, type = "text", required = false, options, min, max, step } = column;
      let control;
      if (Array.isArray(options)) {
        control = el("select", { "aria-label": label, required },
          ...options.map(([value, text]) => el("option", { value }, text)));
        if (values[key] !== undefined) control.value = String(values[key]);
      } else {
        control = el("input", { type, "aria-label": label, required,
          ...(min !== undefined ? { min } : {}),
          ...(max !== undefined ? { max } : {}),
          ...(step !== undefined ? { step } : {})
        });
        if (type === "checkbox") control.checked = Boolean(values[key]);
        else control.value = values[key] == null ? "" : String(values[key]);
      }
      control.addEventListener("input", emit);
      control.addEventListener("change", emit);
      row.append(el("td", {}, control));
    }
    if (allowRemove) row.append(el("td", {}, button("Remove row", {
      variant: "ghost", ariaLabel: "Remove row",
      onClick: () => { row.remove(); emit(); }
    })));
    tbody.append(row);
    updateNames();
    if (notify) onChange?.(read());
  }
  container.append(el("div", { className: "table-scroll", role: "region",
    "aria-label": title, tabindex: "0" }, table),
    el("div", { className: "erp-edit-grid-actions" }, count, add));
  for (const row of initialRows) appendRow(row, false);
  updateNames();

  return {
    node: container, read, appendRow, clear() { tbody.replaceChildren(); emit(); },
    namePrefix,
    validate() {
      const first = tbody.querySelector(":invalid");
      if (first) { first.reportValidity(); first.focus(); return false; }
      return true;
    }
  };
}
