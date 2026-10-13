import { el } from "./dom.js";

/** Native select. No keyboard-inaccessible custom listbox. */
export function selectField(name, label, {
  options = [], required = true, multiple = false, description = ""
} = {}) {
  const id = "select-" + name;
  const control = el("select", {
    id, name, required, ...(multiple ? { multiple: true, size: String(Math.min(6, Math.max(2, options.length))) } : {}),
    ...(description ? { "aria-describedby": id + "-help" } : {})
  }, ...(multiple ? [] : [el("option", { value: "", disabled: required, selected: true }, "Choose " + label)]),
    ...options.map(([value, text]) => el("option", { value }, text)));
  return { node: el("label", { className: "field" },
    el("span", {}, label), control,
    description ? el("small", { id: id + "-help", className: "field-help" }, description) : null),
  control };
}

/** Native checkbox group; FormData.getAll(name) returns selected values. */
export function multiSelectField(name, label, options = [], { required = false } = {}) {
  const controls = options.map(([value, title]) => el("label", { className: "check-option" },
    el("input", { type: "checkbox", name, value }), el("span", {}, title)));
  const group = el("fieldset", { className: "check-group" },
    el("legend", {}, label), ...controls);
  const validate = () => {
    const checked = group.querySelectorAll("input:checked").length;
    const first = group.querySelector("input");
    if (first) first.setCustomValidity(required && checked === 0 ? "Select at least one option." : "");
  };
  group.addEventListener("change", validate);
  return { node: group, validate };
}
