import { el } from "./dom.js";
import { registerValidator } from "./validation.js";

let dateSequence = 0;
/** Native dates only. Academic scheduling rules belong to the backend. */
export function dateField(name, label, { required = true, min, max } = {}) {
  const id = "erp-date-" + ++dateSequence;
  const control = el("input", {
    type: "date", name, required, id,
    ...(min ? { min } : {}), ...(max ? { max } : {})
  });
  return { node: el("label", { className: "field" }, el("span", {}, label), control), control };
}

export function dateRange(name, { startLabel = "From date", endLabel = "To date", required = false } = {}) {
  const start = dateField(name + "_from", startLabel, { required });
  const end = dateField(name + "_to", endLabel, { required });
  const validator = registerValidator(end.control, () =>
    start.control.value && end.control.value && end.control.value < start.control.value
      ? "End date must not be before start date." : "");
  const refresh = () => {
    end.control.min = start.control.value || "";
    validator.validate();
  };
  start.control.addEventListener("input", refresh);
  start.control.addEventListener("change", refresh);
  refresh();
  return { node: el("div", { className: "date-range" }, start.node, end.node),
    start: start.control, end: end.control,
    validate: () => { refresh(); return start.control.checkValidity() && end.control.checkValidity(); },
    dispose() {
      start.control.removeEventListener("input", refresh);
      start.control.removeEventListener("change", refresh);
      validator.dispose();
    } };
}
