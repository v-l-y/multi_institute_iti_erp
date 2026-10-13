import { el } from "./dom.js";

/**
 * File selection/validation only; no upload endpoint is assumed.
 * Server must independently validate size, file type, content and permissions.
 */
export function fileUpload(name, label, {
  accept = "", multiple = false, required = false,
  maxBytes = 5 * 1024 * 1024, onChange
} = {}) {
  if (!Number.isSafeInteger(maxBytes) || maxBytes < 1) throw new TypeError("Invalid file size limit");
  const control = el("input", {
    type: "file", name, id: "upload-" + name, required,
    ...(accept ? { accept } : {}),
    ...(multiple ? { multiple: true } : {})
  });
  const help = el("p", { className: "field-help", "aria-live": "polite" },
    "Files remain local until an authorized upload action is supplied.");
  const validate = () => {
    const files = [...control.files];
    const tooLarge = files.find(file => file.size > maxBytes);
    control.setCustomValidity(tooLarge
      ? "File exceeds " + Math.ceil(maxBytes / 1024 / 1024) + " MB limit." : "");
    const valid = !tooLarge;
    help.textContent = valid
      ? (files.length ? files.map(file => file.name + " (" + Math.ceil(file.size / 1024) + " KB)").join(", ")
        : "No file selected.")
      : "Selected file exceeds the maximum size.";
    return valid;
  };
  control.addEventListener("change", () => {
    const valid = validate();
    if (!valid) control.reportValidity();
    onChange?.(valid ? [...control.files] : [], valid);
  });
  return { node: el("div", { className: "field" },
    el("label", { for: control.id }, label), control, help),
    control, validate, getFiles: () => [...control.files] };
}
