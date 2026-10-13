import { api } from "../../core/http/client.js";
import { el, button, field, notice, toast, openDialog, validateForm } from "../../shared/ui/index.js";

/**
 * Declarative UI for existing PHP POST contracts only.
 * Field 'kind' determines JSON type; never infers regulatory business logic.
 */
export function permittedAction(user, spec) {
  return spec && (!spec.permission || user?.permissions?.includes(spec.permission)) &&
    (!spec.role || user?.roles?.includes(spec.role)) &&
    (!spec.identity || user?.[spec.identity] != null);
}

export function openActionDialog(spec, { onDone, initial = {}, user } = {}) {
  if (!permittedAction(user, spec)) return null;
  const controller = new AbortController();
  let busy = false;
  const feedback = el("div", { className: "form-feedback", "aria-live": "assertive" });
  const fields = spec.fields.map(def => {
    const opts = {
      required: def.required !== false,
      type: def.type || (def.kind === "id" || def.kind === "integer" ? "number" : "text"),
      maxLength: def.maxLength,
      min: def.kind === "id" ? "1" : def.min,
      max: def.max,
      step: def.kind === "integer" || def.kind === "id" ? "1" : def.step,
      options: def.options,
      multiline: def.multiline || false,
      value: initial[def.name] ?? def.value,
      description: def.description
    };
    if (def.kind === "boolean") {
      opts.options = [["true", "Yes"], ["false", "No"]];
      opts.type = "text";
    }
    return field(def.name, def.label, opts);
  });
  const submit = button(spec.submitLabel || "Submit", { type: "submit", variant: "primary" });
  const cancel = button("Cancel");
  const form = el("form", { className: "stack-form" }, ...fields, feedback,
    el("div", { className: "form-actions" }, cancel, submit));
  const dialog = openDialog({
    title: spec.label,
    description: spec.description || "This action updates the authenticated institute ERP only.",
    content: form,
    onClose: () => controller.abort()
  });
  cancel.addEventListener("click", () => { if (!busy) dialog.close(); });
  dialog.addEventListener("cancel", event => { if (busy) event.preventDefault(); });
  form.addEventListener("submit", async event => {
    event.preventDefault();
    if (busy || !dialog.open || !validateForm(form)) return;
    let payload = Object.fromEntries(new FormData(form));
    try {
      for (const def of spec.fields) {
        const value = payload[def.name];
        if (value === undefined) continue;
        if (value === "" && def.required === false) {
          delete payload[def.name];
        } else if (def.kind === "id" || def.kind === "integer") {
          if (!/^\d+$/.test(String(value)) || !Number.isSafeInteger(Number(value)) ||
              (def.kind === "id" && Number(value) < 1)) {
            throw new Error(def.label + " must be a valid " +
              (def.kind === "id" ? "positive" : "whole") + " number.");
          }
          payload[def.name] = Number(value);
        } else if (def.kind === "boolean") {
          payload[def.name] = value === "true";
        }
      }
      if (spec.idempotency) {
        if (!globalThis.crypto?.randomUUID) throw new Error("Secure browser context required.");
        payload.idempotency_key = crypto.randomUUID();
      }
    } catch (error) {
      feedback.replaceChildren(notice("Check entered values", error.message, "error"));
      return;
    }
    busy = true;
    submit.disabled = cancel.disabled = true;
    feedback.replaceChildren();
    try {
      const result = await api.post(spec.path, payload, { signal: controller.signal });
      if (!dialog.open || controller.signal.aborted) return;
      dialog.close();
      toast(spec.success || "Record submitted.");
      onDone?.(result);
    } catch (error) {
      if (error?.name === "AbortError" || !dialog.open) return;
      feedback.replaceChildren(notice("Action was not completed",
        error?.message || "Please check the source records and try again.", "error"));
    } finally {
      busy = false;
      submit.disabled = cancel.disabled = false;
    }
  });
  return dialog;
}
