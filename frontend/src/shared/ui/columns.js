import { el } from "./dom.js";

/**
 * Column visibility is local UI state. Required columns always remain visible.
 * Caller supplies real metadata and must never infer data access from hiding.
 */
export function columnChooser(columns = [], { requiredKeys = [], onChange } = {}) {
  const allowed = new Set(columns.map(([key]) => key));
  const required = new Set(requiredKeys.filter(key => allowed.has(key)));
  const visible = new Set(allowed);
  const controls = columns.map(([key, label]) => {
    const control = el("input", { type: "checkbox", checked: true, disabled: required.has(key) });
    const row = el("label", { className: "check-option" }, control, el("span", {}, label));
    control.addEventListener("change", () => {
      if (!control.checked && visible.size <= 1) {
        control.checked = true;
        return;
      }
      if (control.checked) visible.add(key);
      else visible.delete(key);
      onChange?.(getColumns());
    });
    return row;
  });
  const details = el("details", { className: "erp-column-chooser" },
    el("summary", { className: "button button-secondary" }, "Columns"),
    el("div", { className: "erp-column-options" }, ...controls));
  function getColumns() { return columns.filter(([key]) => visible.has(key)); }
  return { node: details, getColumns };
}
