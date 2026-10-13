import { el } from "./dom.js";
import { registerValidator } from "./validation.js";

/** Local selection only; server remains responsible for every file check. */
let uploadSequence = 0;
export function fileUpload(name, label, {
  accept = "", multiple = false, required = false,
  maxBytes = 5 * 1024 * 1024, onChange
} = {}) {
  if (!Number.isSafeInteger(maxBytes) || maxBytes < 1) throw new TypeError("Invalid file size limit");
  const id = "erp-upload-" + ++uploadSequence;
  const helpId = id + "-help";
  const control = el("input", {
    type: "file", name, id, required, "aria-describedby": helpId,
    ...(accept ? { accept } : {}), ...(multiple ? { multiple: true } : {})
  });
  const help = el("p", { id: helpId, className: "field-help", "aria-live": "polite" },
    "Files remain local until an authorized upload action is supplied.");
  const checkSize = () => {
    const files = [...control.files];
    const tooLarge = files.find(file => file.size > maxBytes);
    help.textContent = tooLarge ? "Selected file exceeds the size limit."
      : files.length ? files.map(file => file.name + " (" + Math.ceil(file.size / 1024) + " KB)").join(", ")
        : "No file selected.";
    return tooLarge ? "File exceeds " + Math.ceil(maxBytes / 1024 / 1024) + " MB limit." : "";
  };
  const validator = registerValidator(control, checkSize);
  control.addEventListener("change", () => {
    validator.validate();
    const valid = control.checkValidity();
    if (!valid) control.reportValidity();
    onChange?.(valid ? [...control.files] : [], valid);
  });
  return { node: el("div", { className: "field" },
    el("label", { for: control.id }, label), control, help),
    control, validate: () => { validator.validate(); return control.checkValidity(); },
    getFiles: () => [...control.files], dispose: () => validator.dispose() };
}
