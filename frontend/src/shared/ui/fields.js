import { el } from "./dom.js";

export function field(name, label, { type = "text", required = true, maxLength, placeholder = "", options } = {}) {
  const id = "field-" + name;
  let input;
  if (Array.isArray(options)) {
    input = el("select", { id, name, required }, ...options.map(
      option => el("option", { value: option[0] }, option[1])
    ));
  } else if (name === "address") {
    input = el("textarea", { id, name, required, maxlength: maxLength || 16000, rows: "3", placeholder });
  } else {
    input = el("input", { id, name, type, required, placeholder,
      ...(maxLength ? { maxlength: maxLength } : {}) });
  }
  return el("label", { className: "field" }, el("span", {}, label), input);
}
