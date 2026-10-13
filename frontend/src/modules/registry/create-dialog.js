import { api } from "../../core/http/client.js";
import { el, field, notice, toast } from "../../shared/ui/index.js";
import { creationConfig } from "./create-config.js";

export function openCreationDialog(page, user, onCreated) {
  const spec = creationConfig(page.id, user);
  if (!spec) return;
  const dialog = el("dialog", { className: "form-dialog", "aria-labelledby": "dialog-title" });
  const feedback = el("div", { "aria-live": "assertive" });
  const submit = el("button", { type: "submit", className: "button button-primary" }, "Save record");
  const cancel = el("button", { type: "button", className: "button button-ghost",
    onClick: () => dialog.close() }, "Cancel");
  const form = el("form", { className: "stack-form" },
    ...spec.fields.map(([key, label, options]) => field(key, label, options)),
    feedback,
    el("div", { className: "form-actions" }, cancel, submit));
  form.addEventListener("submit", async event => {
    event.preventDefault();
    submit.disabled = true;
    feedback.replaceChildren();
    try {
      const body = Object.fromEntries(new FormData(form));
      await api.post(spec.path, body);
      dialog.close();
      toast("Record created successfully.");
      onCreated();
    } catch (err) {
      feedback.replaceChildren(notice("Could not save", err.message, "error"));
    } finally {
      submit.disabled = false;
    }
  });
  dialog.append(el("div", { className: "dialog-heading" },
    el("p", { className: "eyebrow" }, "INSTITUTE REGISTER"),
    el("h2", { id: "dialog-title" }, spec.title),
    el("p", { className: "muted" }, "Save a local record only. This does not create official government registration.")),
    form);
  document.body.append(dialog);
  dialog.addEventListener("close", () => dialog.remove(), { once: true });
  dialog.showModal();
  return dialog;
}
