import { el } from "./dom.js";

/** Generic HTML validation and optional cross-field rules, never regulatory rules. */
export function validateForm(form, { matches = [], rules = [] } = {}) {
  if (!(form instanceof HTMLFormElement)) throw new TypeError("Expected a form");
  const named = name => form.elements.namedItem(name);
  for (const control of [...form.elements]) {
    if (typeof control.setCustomValidity === "function") control.setCustomValidity("");
  }
  for (const [name, other, message] of matches) {
    const control = named(name), counterpart = named(other);
    if (control && counterpart && control.value !== counterpart.value) {
      control.setCustomValidity(message || "Values must match.");
    }
  }
  for (const { name, check, message } of rules) {
    const control = named(name);
    if (control && typeof check === "function" && !check(control.value, form)) {
      control.setCustomValidity(message || "Invalid value.");
    }
  }
  const valid = form.checkValidity();
  if (!valid) {
    form.reportValidity();
    const invalid = form.querySelector(":invalid");
    invalid?.focus({ preventScroll: false });
  }
  return valid;
}

/** A live error summary, containing no user-supplied HTML. */
export function formErrorSummary(message) {
  return el("div", { className: "notice notice-error", role: "alert" },
    el("strong", {}, "Check the form"), el("p", {}, message));
}
