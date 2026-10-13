import { el, field, notice } from "../../shared/ui/index.js";

/* Auth screens are view-only; all authentication actions go through app controller. */
export function renderLogin(root, { message = "", onLogin }) {
  const error = el("div", { className: "form-feedback", "aria-live": "assertive" });
  if (message) error.append(notice("Sign-in notice", message));
  const username = el("input", { id: "username", name: "username", autocomplete: "username", required: true, maxlength: 120, placeholder: "Your username" });
  const password = el("input", { id: "password", name: "password", type: "password", autocomplete: "current-password", required: true, placeholder: "Your password" });
  const institute = el("input", { id: "institute_id", name: "institute_id", type: "number", min: 1, step: 1, inputmode: "numeric", placeholder: "Leave blank for platform account" });
  const button = el("button", { className: "button button-primary", type: "submit" }, "Sign in");
  const form = el("form", { className: "login-form" },
    el("label", { className: "field" }, el("span", {}, "Username"), username),
    el("label", { className: "field" }, el("span", {}, "Password"), password),
    el("label", { className: "field" }, el("span", {}, "Institute ID"), institute),
    el("p", { className: "field-help" }, "Enter the institute ID assigned to your account. Platform developers leave this field blank."),
    error, button);
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const raw = institute.value.trim();
    const instituteId = raw === "" ? null : Number(raw);
    if (raw !== "" && (!Number.isSafeInteger(instituteId) || instituteId <= 0)) {
      error.replaceChildren(notice("Invalid institute ID", "Enter a positive integer.", "error"));
      return;
    }
    button.disabled = true;
    button.textContent = "Signing in…";
    error.replaceChildren();
    try {
      await onLogin(username.value.trim(), password.value, instituteId);
      password.value = "";
    } catch (err) {
      error.replaceChildren(notice("Sign in failed", err.message || "Unable to sign in.", "error"));
      password.value = "";
      password.focus();
    } finally {
      button.disabled = false;
      button.textContent = "Sign in";
    }
  });

  root.replaceChildren(el("div", { className: "auth-layout" },
    el("div", { className: "auth-story" },
      el("div", { className: "brand brand-large" }, el("span", { className: "brand-logo", "aria-hidden": "true" }, "I"), el("span", {}, "ITI ERP")),
      el("span", { className: "scope-pill" }, "BIHAR · PRIVATE ITIs"),
      el("h1", {}, "One workspace. Every institute record in its place."),
      el("p", {}, "Institute administration, CTS trades, training calendars and academic registers—connected to your existing ERP API."),
      el("div", { className: "story-footer" }, "Institute management only · Regulatory decisions stay with official authorities")),
    el("main", { className: "auth-main", id: "main-content" },
      el("div", { className: "auth-card" },
        el("span", { className: "small-overline" }, "SECURE ACCOUNT ACCESS"),
        el("h2", {}, "Welcome back"),
        el("p", { className: "muted" }, "Sign in with your institute or platform account."),
        form,
        el("p", { className: "security-note" }, "Sessions are kept in browser memory, not persistent storage. Refreshing the page requires a new sign-in.")))));
  username.focus();
}

export function renderPasswordChange(root, { onChangePassword, onLogout }) {
  const feedback = el("div", { "aria-live": "assertive" });
  const old = field("current_password", "Current password", { type: "password", maxLength: 72 });
  const next = field("new_password", "New password (12–72 characters)", { type: "password", maxLength: 72 });
  const confirm = field("confirm_password", "Confirm new password", { type: "password", maxLength: 72 });
  const submit = el("button", { className: "button button-primary", type: "submit" }, "Update password");
  const form = el("form", { className: "stack-form" }, old, next, confirm, feedback, submit);
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(form));
    if (values.new_password !== values.confirm_password || values.new_password.length < 12 || values.new_password.length > 72) {
      feedback.replaceChildren(notice("Check password", "New passwords must match and contain 12–72 characters.", "error"));
      return;
    }
    submit.disabled = true;
    try {
      await onChangePassword({
        current_password: values.current_password,
        new_password: values.new_password
      });
    } catch (err) {
      feedback.replaceChildren(notice("Could not update password", err.message, "error"));
      submit.disabled = false;
    }
  });
  root.replaceChildren(el("main", { className: "single-screen", id: "main-content" },
    el("div", { className: "auth-card" },
      el("p", { className: "eyebrow" }, "Account security"),
      el("h1", {}, "Change your password"),
      el("p", { className: "muted" }, "Your institute requires a password change before you can access registers."),
      form, el("button", { className: "button button-ghost", type: "button", onClick: onLogout }, "Sign out"))));
}
