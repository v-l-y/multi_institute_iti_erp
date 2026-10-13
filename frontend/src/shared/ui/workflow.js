import { el } from "./dom.js";
import { button } from "./buttons.js";
import { validateForm } from "./validation.js";

/** Purely visual backend-supplied progress; not approval or process authority. */
export function progressSteps(steps = [], { current = 0, label = "Progress" } = {}) {
  if (!Array.isArray(steps)) throw new TypeError("Progress steps must be an array");
  return el("ol", { className: "erp-progress-steps", "aria-label": label },
    ...steps.map((step, i) => el("li", {
      className: "erp-progress-step" + (i === current ? " is-current" : ""),
      ...(i === current ? { "aria-current": "step" } : {})
    }, el("span", { className: "erp-step-number", "aria-hidden": "true" }, i + 1),
      el("span", {}, typeof step === "string" ? step : step.label))));
}

/** UI wizard; submit is supplied by domain owner, never an automatic official workflow. */
export function formWizard({
  steps = [], onSubmit, nextLabel = "Continue", submitLabel = "Submit"
} = {}) {
  if (!steps.length || typeof onSubmit !== "function") throw new TypeError("Wizard needs steps and submit");
  let current = 0, busy = false;
  const form = el("form", { className: "erp-form-wizard" });
  const progress = el("div");
  const stage = el("div", { className: "erp-wizard-stage" });
  const back = button("Back");
  const next = button(nextLabel, { variant: "primary" });
  const controls = el("div", { className: "form-actions" }, back, next);
  const error = el("p", { className: "muted", role: "status" });
  form.append(progress, stage, error, controls);
  function draw() {
    progress.replaceChildren(progressSteps(steps.map(step => step.label), { current }));
    stage.replaceChildren(steps[current].content);
    back.disabled = busy || current === 0;
    next.disabled = busy;
    next.textContent = current === steps.length - 1 ? submitLabel : nextLabel;
  }
  back.addEventListener("click", () => {
    if (busy || current === 0) return;
    current--; draw();
  });
  next.addEventListener("click", async () => {
    if (busy) return;
    const inputs = stage.querySelectorAll("input, select, textarea");
    if ([...inputs].some(control => !control.checkValidity())) {
      [...inputs].find(control => !control.checkValidity())?.reportValidity();
      return;
    }
    if (current < steps.length - 1) { current++; draw(); return; }
    busy = true;
    draw();
    try { await onSubmit(form); }
    catch (cause) { error.textContent = cause?.message || "Could not submit."; }
    finally { busy = false; draw(); }
  });
  draw();
  return { node: form, getStep: () => current };
}
