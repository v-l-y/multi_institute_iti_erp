import { api } from "../../core/http/client.js";
import { el, field, notice, toast } from "../../shared/ui/index.js";
import { creationConfig } from "./create-config.js";

/* Forms use source-backed backend contracts; shared dialogs never infer permissions. */
export function openCreationDialog(page, user, onCreated) {
  const spec = creationConfig(page.id, user);
  if (!spec) return null;

  let saving = false;
  const controller = new AbortController();
  const dialog = el("dialog", {
    className: "form-dialog",
    "aria-labelledby": "dialog-title",
    "aria-describedby": "dialog-description"
  });
  const feedback = el("div", { className: "form-feedback", "aria-live": "assertive" });
  const submit = el("button", {
    type: "submit", className: "button button-primary"
  }, "Save record");
  const cancel = el("button", {
    type: "button", className: "button button-ghost",
    onClick: () => { if (!saving) dialog.close(); }
  }, "Cancel");
  const form = el("form", { className: "stack-form" },
    ...spec.fields.map(([key, label, options]) => field(key, label, options)),
    feedback,
    el("div", { className: "form-actions" }, cancel, submit));

  dialog.addEventListener("cancel", event => {
    if (saving) event.preventDefault();
  });
  dialog.addEventListener("close", () => {
    controller.abort();
    dialog.remove();
  }, { once: true });

  form.addEventListener("submit", async event => {
    event.preventDefault();
    if (saving || !dialog.open || !form.reportValidity()) return;
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

  dialog.append(el("div", { className: "dialog-heading" },
    el("p", { className: "eyebrow" }, "INSTITUTE REGISTER"),
    el("h2", { id: "dialog-title" }, spec.title),
    el("p", { className: "muted", id: "dialog-description" },
      "Creates a local institute record only, not an official government registration.")),
    form);
  document.body.append(dialog);
  dialog.showModal();
  return dialog;
}
