import { el } from "./dom.js";
import { button } from "./buttons.js";

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

/**
 * Multi-step form UI. Stages stay mounted so data survives navigation.
 * Caller controls submission, validation rules and backend permissions.
 */
export function formWizard({
  steps = [], onSubmit, nextLabel = "Continue", submitLabel = "Submit"
} = {}) {
  if (!steps.length || typeof onSubmit !== "function") throw new TypeError("Wizard needs steps and submit");
  let current = 0, busy = false, closed = false;
  const form = el("form", { className: "erp-form-wizard", novalidate: true });
  const progress = el("div");
  const stages = steps.map((step, i) => el("section", {
    className: "erp-wizard-stage", "aria-label": step.label || "Step " + (i + 1)
  }, step.content));
  const back = button("Back");
  const next = button(nextLabel, { variant: "primary" });
  const controls = el("div", { className: "form-actions" }, back, next);
  const error = el("p", { className: "muted", role: "status" });
  form.append(progress, ...stages, error, controls);
  function draw() {
    if (closed) return;
    progress.replaceChildren(progressSteps(steps.map(step => step.label), { current }));
    stages.forEach((node, i) => { node.hidden = i !== current; });
    back.disabled = busy || current === 0;
    next.disabled = busy;
    next.textContent = current === steps.length - 1 ? submitLabel : nextLabel;
  }
  back.addEventListener("click", () => {
    if (busy || current === 0) return;
    current--;
    draw();
  });
  async function advance() {
    if (busy || closed) return;
    const fields = [...stages[current].querySelectorAll("input, select, textarea")];
    const invalid = fields.find(control => !control.checkValidity());
    if (invalid) { invalid.reportValidity(); invalid.focus(); return; }
    if (current < steps.length - 1) { current++; draw(); return; }
    busy = true;
    error.textContent = "";
    draw();
    try { await onSubmit(form); }
    catch (cause) { if (!closed) error.textContent = cause?.message || "Could not submit."; }
    finally { busy = false; draw(); }
  }
  next.addEventListener("click", () => void advance());
  form.addEventListener("submit", event => { event.preventDefault(); void advance(); });
  draw();
  return {
    node: form,
    getStep: () => current,
    dispose() { closed = true; }
  };
}
