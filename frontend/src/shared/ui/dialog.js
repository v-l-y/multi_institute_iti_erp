import { el } from "./dom.js";
import { button } from "./buttons.js";

/** Native modal, labelled and focus-managed by HTMLDialogElement. */
let nextDialogId = 0;
export function openDialog({
  title, description = "", content, actions = [], dismissible = true,
  onClose
} = {}) {
  if (!title) throw new TypeError("Dialog needs a title");
  const id = "erp-modal-" + ++nextDialogId;
  const dialog = el("dialog", {
    className: "form-dialog", "aria-labelledby": id + "-title",
    ...(description ? { "aria-describedby": id + "-description" } : {})
  });
  const heading = el("div", { className: "dialog-heading" },
    el("h2", { id: id + "-title" }, title),
    description ? el("p", { className: "muted", id: id + "-description" }, description) : null);
  const footer = el("div", { className: "form-actions" },
    ...actions.map(action => button(action.label, {
      type: "button", variant: action.variant || "secondary",
      disabled: action.disabled,
      onClick: () => action.onClick?.(dialog)
    })));
  dialog.append(heading, content || el("div"), footer);
  dialog.addEventListener("cancel", event => {
    if (!dismissible) event.preventDefault();
  });
  dialog.addEventListener("close", () => {
    dialog.remove();
    onClose?.(dialog.returnValue);
  }, { once: true });
  document.body.append(dialog);
  dialog.showModal();
  return dialog;
}

export function confirmDialog({
  title = "Confirm action",
  message, confirmText = "Confirm", danger = false, onConfirm
} = {}) {
  if (typeof onConfirm !== "function") throw new TypeError("Confirmation needs a callback");
  let working = false;
  const dialog = openDialog({
    title, description: message || "Continue?",
    actions: [
      { label: "Cancel", onClick: node => { if (!working) node.close("cancel"); } },
      { label: confirmText, variant: danger ? "danger" : "primary",
        onClick: async node => {
          if (working) return;
          working = true;
          const controls = [...node.querySelectorAll("button")];
          controls.forEach(control => { control.disabled = true; });
          try {
            await onConfirm();
            if (node.open) node.close("confirmed");
          } catch (error) {
            if (node.open) {
              node.querySelector(".dialog-error")?.remove();
              node.append(el("div", { className: "notice notice-error dialog-error", role: "alert" },
                error?.message || "Action could not be completed."));
            }
          } finally {
            working = false;
            controls.forEach(control => { control.disabled = false; });
          }
        } }
    ],
    onClose: () => {}
  });
  dialog.addEventListener("cancel", event => { if (working) event.preventDefault(); });
  return dialog;
}
