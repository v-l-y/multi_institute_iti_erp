import { el } from "./dom.js";

/**
 * Column visibility is local UI state. Required columns always remain visible.
 * Caller supplies real metadata and must never infer data access from hiding.
 */
export function columnChooser(columns = [], { requiredKeys = [], onChange, initialKeys = null } = {}) {
  const allowed = new Set(columns.map(([key]) => key));
  const required = new Set(requiredKeys.filter(key => allowed.has(key)));
  const initial = Array.isArray(initialKeys)
    ? initialKeys.filter(key => allowed.has(key)) : [...allowed];
  const visible = new Set([...required, ...initial]);
  if (!visible.size && allowed.size) visible.add(allowed.values().next().value);
  const controls = columns.map(([key, label]) => {
    const control = el("input", { type: "checkbox", checked: visible.has(key), disabled: required.has(key) });
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
  return {
    node: details, getColumns,
    getState: () => [...visible],
    hydrate(keys, notify = false) {
      if (!Array.isArray(keys)) return;
      const selected = keys.filter(key => allowed.has(key));
      if (!selected.length && allowed.size && !required.size) return;
      visible.clear();
      for (const key of [...required, ...selected]) visible.add(key);
      controls.forEach((label, index) => {
        label.querySelector("input").checked = visible.has(columns[index][0]);
      });
      if (notify) onChange?.(getColumns());
    }
  };
}
