import { el } from "./dom.js";

/** Labelled native controls, shared across source-owned forms. */
export function field(name, label, {
  type = "text", required = true, maxLength, placeholder = "",
  options, autocomplete, description
} = {}) {
  const id = "field-" + name;
  const hintId = id + "-hint";
  let input;
  const common = { id, name, required };
  if (description) common["aria-describedby"] = hintId;
  if (Array.isArray(options)) {
    input = el("select", common,
      ...options.map(([value, caption]) => el("option", { value }, caption)));
  } else if (name === "address") {
    input = el("textarea", {
      ...common, maxlength: maxLength || 16000,
      rows: "3", placeholder, autocomplete: autocomplete || "street-address"
    });
  } else {
    const passwordAutocomplete = name === "current_password" ? "current-password"
      : (name === "new_password" || name === "confirm_password") ? "new-password" : undefined;
    input = el("input", {
      ...common, type, placeholder,
      ...(maxLength ? { maxlength: maxLength } : {}),
      ...(autocomplete || passwordAutocomplete
        ? { autocomplete: autocomplete || passwordAutocomplete } : {})
    });
  }
  const caption = el("span", {}, label,
    required ? el("span", { className: "required-mark", "aria-hidden": "true" }, " *") : null);
  return el("label", { className: "field" },
    caption, input,
    description ? el("small", { id: hintId, className: "field-help" }, description) : null);
}
