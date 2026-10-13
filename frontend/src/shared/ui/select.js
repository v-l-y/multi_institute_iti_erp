import { el } from "./dom.js";
import { registerValidator } from "./validation.js";

let selectSequence = 0;
export function selectField(name, label, {
  options = [], required = true, multiple = false, description = ""
} = {}) {
  const id = "erp-select-" + ++selectSequence;
  const control = el("select", {
    id, name, required,
    ...(multiple ? { multiple: true, size: String(Math.min(6, Math.max(2, options.length))) } : {}),
    ...(description ? { "aria-describedby": id + "-help" } : {})
  }, ...(multiple ? [] : [el("option", { value: "" }, "Choose " + label)]),
    ...options.map(([value, text]) => el("option", { value }, text)));
  return { node: el("label", { className: "field" },
    el("span", {}, label), control,
    description ? el("small", { id: id + "-help", className: "field-help" }, description) : null),
  control };
}

/** Checkbox group, FormData.getAll(name) returns selected values. */
export function multiSelectField(name, label, options = [], { required = false } = {}) {
  if (required && options.length === 0) throw new TypeError("Required multiselect needs options");
  const controls = options.map(([value, title]) => el("label", { className: "check-option" },
    el("input", { type: "checkbox", name, value }), el("span", {}, title)));
  const group = el("fieldset", { className: "check-group" },
    el("legend", {}, label), ...controls);
  const first = group.querySelector("input");
  const validation = first ? registerValidator(first,
    () => required && !group.querySelector("input:checked") ? "Select at least one option." : "") : null;
  group.addEventListener("change", () => validation?.validate());
  return { node: group, validate: () => validation?.validate(), dispose: () => validation?.dispose() };
}
