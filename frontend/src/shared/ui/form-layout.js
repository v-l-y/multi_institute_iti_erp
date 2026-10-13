import { el } from "./dom.js";
import { button } from "./buttons.js";

/** Form layout with CSP-safe class names, never dynamic inline styles. */
const columnsClass = columns => " erp-form-grid-cols-" + (columns === 1 ? 1 : columns === 3 ? 3 : 2);

export function formSection(title, fields = [], { description = "", columns = 2, compact = false } = {}) {
  if (!title || !Array.isArray(fields)) throw new TypeError("Section requires title and controls");
  const section = el("fieldset", { className: "erp-form-section" + (compact ? " is-compact" : "") },
    el("legend", {}, title));
  if (description) section.append(el("p", { className: "muted" }, description));
  section.append(el("div", { className: "erp-form-grid" + columnsClass(columns) }, ...fields));
  return section;
}

export function formGrid(controls = [], { columns = 2 } = {}) {
  return el("div", { className: "erp-form-grid" + columnsClass(columns) }, ...controls);
}

export function formActions({ onCancel, submitLabel = "Save", busy = false, cancelLabel = "Cancel" } = {}) {
  const save = button(submitLabel, { type: "submit", variant: "primary", busy });
  const cancel = button(cancelLabel, { disabled: busy, onClick: onCancel });
  return {
    node: el("div", { className: "form-actions" }, cancel, save),
    save, cancel,
    setBusy(state) {
      save.disabled = cancel.disabled = Boolean(state);
      save.setAttribute("aria-busy", String(Boolean(state)));
    }
  };
}

/**
 * Reads native fields and explicitly attached repeatable grids.
 * Unnamed grid controls are serialized from grid.read(), not implicitly lost.
 */
export function readForm(form, { multi = [], grids = {} } = {}) {
  if (!(form instanceof HTMLFormElement)) throw new TypeError("Expected a form");
  const data = new FormData(form);
  const result = Object.fromEntries(data.entries());
  for (const key of multi) result[key] = data.getAll(key);
  for (const [key, grid] of Object.entries(grids)) {
    if (!grid || typeof grid.read !== "function" || grid.namePrefix !== key) {
      throw new TypeError("Editable grid namePrefix must match: " + key);
    }
    for (const fieldName of Object.keys(result)) {
      if (fieldName.startsWith(key + "[")) delete result[fieldName];
    }
    result[key] = grid.read();
  }
  return result;
}
