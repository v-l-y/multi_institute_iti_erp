import { el } from "./dom.js";

let controlSequence = 0;
function controlId(prefix) { return "erp-" + prefix + "-" + ++controlSequence; }

export function textareaField(name, label, {
  required = true, rows = 4, maxLength, description = "", value = ""
} = {}) {
  const id = controlId("notes");
  const control = el("textarea", {
    id, name, required, rows: String(rows),
    ...(maxLength ? { maxlength: maxLength } : {})
  });
  control.value = value == null ? "" : String(value);
  return { node: el("label", { className: "field" },
    el("span", {}, label), control,
    description ? el("small", { className: "field-help" }, description) : null), control };
}

export function numberField(name, label, {
  required = true, min, max, step = "1", value, description = ""
} = {}) {
  const id = controlId("number");
  const control = el("input", {
    type: "number", id, name, required, step,
    ...(min !== undefined ? { min } : {}),
    ...(max !== undefined ? { max } : {})
  });
  if (value !== undefined && value !== null) control.value = String(value);
  return { node: el("label", { className: "field" },
    el("span", {}, label), control,
    description ? el("small", { className: "field-help" }, description) : null), control };
}

export function amountField(name, label, options = {}) {
  const { min = "0", step = "0.01", currency = "₹", ...other } = options;
  const result = numberField(name, label, { min, step, ...other });
  result.node.classList.add("erp-amount-field");
  result.node.querySelector("span").append(" (" + currency + ")");
  return result;
}

export function toggleField(name, label, {
  checked = false, disabled = false, description = "", value = "1"
} = {}) {
  const control = el("input", { type: "checkbox", name, value, checked, disabled });
  return { node: el("label", { className: "erp-toggle-field" },
    control, el("span", {}, label),
    description ? el("small", { className: "field-help" }, description) : null),
    control };
}

export function radioFields(name, legend, options = [], {
  required = true, value = ""
} = {}) {
  const controls = options.map(([key, label]) => {
    const control = el("input", { type: "radio", name, value: key, required });
    control.checked = String(value) === String(key);
    return { node: el("label", { className: "check-option" }, control, el("span", {}, label)), control };
  });
  return { node: el("fieldset", { className: "check-group erp-radio-group" },
    el("legend", {}, legend), ...controls.map(item => item.node)),
    controls: controls.map(item => item.control) };
}

export function timeField(name, label, { required = true, min, max } = {}) {
  const id = controlId("time");
  const control = el("input", { type: "time", id, name, required,
    ...(min ? { min } : {}), ...(max ? { max } : {}) });
  return { node: el("label", { className: "field" }, el("span", {}, label), control), control };
}
