import { el } from "./dom.js";

/** Native date input. Business calendar rules belong to the PHP backend. */
export function dateField(name, label, { required = true, min, max } = {}) {
  const control = el("input", {
    type: "date", name, required, id: "date-" + name,
    ...(min ? { min } : {}), ...(max ? { max } : {})
  });
  return { node: el("label", { className: "field" }, el("span", {}, label), control), control };
}

export function dateRange(name, { startLabel = "From date", endLabel = "To date", required = false } = {}) {
  const start = dateField(name + "_from", startLabel, { required });
  const end = dateField(name + "_to", endLabel, { required });
  const validate = () => {
    end.control.min = start.control.value || "";
    end.control.setCustomValidity(
      start.control.value && end.control.value && end.control.value < start.control.value
        ? "End date must not be before start date." : "");
  };
  start.control.addEventListener("change", validate);
  end.control.addEventListener("change", validate);
  return { node: el("div", { className: "date-range" }, start.node, end.node),
    start: start.control, end: end.control, validate };
}
