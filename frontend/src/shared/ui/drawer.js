import { el } from "./dom.js";

/**
 * Right-side detail panel using native dialog focus and Escape semantics.
 * Not a global drawer singleton: each caller owns/disposes its instance.
 */
let nextDrawer = 0;
export function openDrawer({ title, content, description = "", onClose } = {}) {
  if (!title) throw new TypeError("Drawer title is required");
  const id = "erp-drawer-" + ++nextDrawer;
  const dialog = el("dialog", {
    className: "erp-drawer", "aria-labelledby": id,
    ...(description ? { "aria-describedby": id + "-description" } : {})
  }, el("header", { className: "erp-drawer-header" },
    el("div", {}, el("h2", { id }, title),
      description ? el("p", { id: id + "-description", className: "muted" }, description) : null),
    el("button", { type: "button", className: "button button-ghost",
      "aria-label": "Close details", onClick: () => dialog.close() }, "✕")),
  el("div", { className: "erp-drawer-body" }, content || ""));
  dialog.addEventListener("close", () => {
    dialog.remove();
    onClose?.();
  }, { once: true });
  document.body.append(dialog);
  dialog.showModal();
  return dialog;
}
