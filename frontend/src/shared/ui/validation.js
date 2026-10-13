import { el } from "./dom.js";

/**
 * One owner for shared control validity. Never clear arbitrary custom errors
 * registered by a backend-integrated form or third-party component.
 */
const registered = new WeakMap();
const ownedMessages = new WeakMap();

function setOwnedMessage(control, message) {
  if (typeof control.setCustomValidity !== "function") return;
  const previous = ownedMessages.get(control);
  // Another owner can supply an error; never silently erase it.
  if (control.validity.customError && control.validationMessage !== previous) return;
  control.setCustomValidity(message || "");
  if (message) ownedMessages.set(control, message);
  else ownedMessages.delete(control);
}

/** Register a reusable validator, returning a disposer for module teardown. */
export function registerValidator(control, validator) {
  if (!control || typeof validator !== "function") {
    throw new TypeError("Validator requires a form control and callback");
  }
  if (registered.has(control)) throw new Error("Control already has a registered validator");
  registered.set(control, validator);
  const refresh = () => {
    if (registered.get(control) !== validator) return;
    const message = validator(control.value, control);
    setOwnedMessage(control, message ? String(message) : "");
  };
  control.addEventListener("input", refresh);
  control.addEventListener("change", refresh);
  refresh();
  return {
    validate: refresh,
    dispose() {
      control.removeEventListener("input", refresh);
      control.removeEventListener("change", refresh);
      if (registered.get(control) === validator) {
        registered.delete(control);
        setOwnedMessage(control, "");
      }
    }
  };
}

/**
 * Validates registered checks, then optional local form rules.
 * Domain/backend rules must be passed by caller, never hard-coded here.
 */
export function validateForm(form, { matches = [], rules = [], validators = [] } = {}) {
  if (!(form instanceof HTMLFormElement)) throw new TypeError("Expected a form");
  const named = name => form.elements.namedItem(name);
  const pending = new Map();
  for (const control of [...form.elements]) {
    if (ownedMessages.has(control)) setOwnedMessage(control, "");
    const check = registered.get(control);
    if (check) {
      const message = check(control.value, control);
      if (message) pending.set(control, String(message));
    }
  }
  for (const validate of validators) {
    if (typeof validate === "function") validate();
  }
  for (const [name, other, message] of matches) {
    const control = named(name), counterpart = named(other);
    if (control && counterpart && typeof control.setCustomValidity === "function" &&
        control.value !== counterpart.value && !pending.has(control)) {
      pending.set(control, message || "Values must match.");
    }
  }
  for (const { name, check, message } of rules) {
    const control = named(name);
    if (control && typeof check === "function" &&
        typeof control.setCustomValidity === "function" &&
        !check(control.value, form) && !pending.has(control)) {
      pending.set(control, message || "Invalid value.");
    }
  }
  for (const [control, message] of pending) setOwnedMessage(control, message);
  const valid = form.checkValidity();
  if (!valid) {
    form.reportValidity();
    const invalid = form.querySelector(":invalid");
    invalid?.focus({ preventScroll: false });
  }
  return valid;
}

export function formErrorSummary(message) {
  return el("div", { className: "notice notice-error", role: "alert" },
    el("strong", {}, "Check the form"), el("p", {}, message));
}
