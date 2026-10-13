import { api } from "../../core/http/client.js";
import { el, field, button, notice, toast, validateForm, openDialog } from "../../shared/ui/index.js";
import { creationConfig } from "./create-config.js";

/* Shared UI dialog/validation; each domain supplies only its backend-approved fields. */
export function openCreationDialog(page, user, onCreated) {
  const spec = creationConfig(page.id, user);
  if (!spec) return null;

  let saving = false;
  const controller = new AbortController();
  const feedback = el("div", { className: "form-feedback", "aria-live": "assertive" });
  const submit = button("Save record", { type: "submit", variant: "primary" });
  const cancel = button("Cancel");
  const form = el("form", { className: "stack-form" },
    ...spec.fields.map(([key, label, options]) => field(key, label, options)),
    feedback,
    el("div", { className: "form-actions" }, cancel, submit));
  const dialog = openDialog({
    title: spec.title,
    description: "Creates a local institute record only, not an official government registration.",
    content: form,
    onClose: () => controller.abort()
  });

  cancel.addEventListener("click", () => { if (!saving) dialog.close(); });
  dialog.addEventListener("cancel", event => {
    if (saving) event.preventDefault();
  });
  form.addEventListener("submit", async event => {
    event.preventDefault();
    if (saving || !dialog.open || !validateForm(form)) return;
    saving = true;
    submit.disabled = true;
    cancel.disabled = true;
    feedback.replaceChildren();
    try {
      const body = Object.fromEntries(new FormData(form));
      await api.post(spec.path, body, { signal: controller.signal });
      if (!dialog.open || controller.signal.aborted) return;
      dialog.close();
      toast("Record created successfully.");
      onCreated();
    } catch (error) {
      if (error?.name === "AbortError" || !dialog.open) return;
      feedback.replaceChildren(notice("Could not save",
        error?.message || "Please check the fields and try again.", "error"));
    } finally {
      saving = false;
      submit.disabled = false;
      cancel.disabled = false;
    }
  });
  return dialog;
}
