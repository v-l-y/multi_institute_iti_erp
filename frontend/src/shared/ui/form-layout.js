import { el } from "./dom.js";
import { button } from "./buttons.js";

/** ERP form sections/layout only; no inferred regulatory field or workflow rules. */
export function formSection(title, fields = [], { description = "", columns = 2, compact = false } = {}) {
  if (!title || !Array.isArray(fields)) throw new TypeError("Section requires title and controls");
  const section = el("fieldset", { className: "erp-form-section" + (compact ? " is-compact" : "") },
    el("legend", {}, title));
  if (description) section.append(el("p", { className: "muted" }, description));
  section.append(el("div", {
    className: "erp-form-grid",
    style: "--form-cols:" + (columns === 1 ? "1" : columns === 3 ? "3" : "2")
  }, ...fields));
  return section;
}

export function formGrid(controls = [], { columns = 2 } = {}) {
  return el("div", {
    className: "erp-form-grid",
    style: "--form-cols:" + (columns === 1 ? "1" : columns === 3 ? "3" : "2")
  }, ...controls);
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

export function readForm(form, { multi = [] } = {}) {
  if (!(form instanceof HTMLFormElement)) throw new TypeError("Expected a form");
  const data = new FormData(form);
  const result = Object.fromEntries(data.entries());
  for (const key of multi) result[key] = data.getAll(key);
  return result;
}
