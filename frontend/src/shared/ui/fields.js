import { el } from "./dom.js";

let fieldIndex = 0;

/**
 * Generic accessible field factory. No field names imply regulatory rules.
 * Defaults preserve the current institute forms, including address textarea.
 */
export function field(name, label, {
  type = "text", required = true, maxLength, minLength, placeholder = "",
  options, autocomplete, description, value, min, max, step,
  multiline = name === "address", rows = 3, disabled = false, readonly = false
} = {}) {
  const id = "erp-field-" + ++fieldIndex;
  const hintId = id + "-hint";
  let input;
  const common = { id, name, required, disabled,
    ...(description ? { "aria-describedby": hintId } : {})
  };
  if (Array.isArray(options)) {
    input = el("select", common,
      ...options.map(([key, caption]) => el("option", { value: key }, caption)));
  } else if (multiline) {
    input = el("textarea", {
      ...common, readonly, rows: String(rows), placeholder,
      ...(maxLength ? { maxlength: maxLength } : {}),
      ...(minLength ? { minlength: minLength } : {}),
      ...(autocomplete ? { autocomplete } : {})
    });
  } else {
    const passwordAutocomplete = name === "current_password" ? "current-password"
      : ["new_password", "confirm_password"].includes(name) ? "new-password" : undefined;
    input = el("input", {
      ...common, type, placeholder, readonly,
      ...(maxLength ? { maxlength: maxLength } : {}),
      ...(minLength ? { minlength: minLength } : {}),
      ...(min !== undefined ? { min } : {}),
      ...(max !== undefined ? { max } : {}),
      ...(step !== undefined ? { step } : {}),
      ...(autocomplete || passwordAutocomplete
        ? { autocomplete: autocomplete || passwordAutocomplete } : {})
    });
  }
  if (value !== undefined && value !== null) input.value = String(value);
  return el("label", { className: "field" },
    el("span", {}, label,
      required ? el("span", { className: "required-mark", "aria-hidden": "true" }, " *") : null),
    input,
    description ? el("small", { id: hintId, className: "field-help" }, description) : null);
}
