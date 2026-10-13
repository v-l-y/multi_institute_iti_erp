import { el } from "./dom.js";

/** Consistent native button, never a link without a destination. */
export function button(label, {
  variant = "secondary", type = "button", disabled = false,
  busy = false, onClick, ariaLabel, title
} = {}) {
  if (!["primary", "secondary", "ghost", "danger"].includes(variant)) {
    throw new TypeError("Unknown button variant");
  }
  const node = el("button", {
    className: "button button-" + variant,
    type, disabled: disabled || busy,
    ...(ariaLabel ? { "aria-label": ariaLabel } : {}),
    ...(title ? { title } : {}),
    ...(onClick ? { onClick } : {})
  }, label);
  if (busy) node.setAttribute("aria-busy", "true");
  return node;
}

/** Action menu uses native details; permissions must be checked separately. */
export function actionMenu(label, actions = []) {
  const details = el("details", { className: "action-menu" });
  const summary = el("summary", { className: "button button-secondary" }, label);
  const choices = el("div", { className: "action-menu-options" },
    ...actions.filter(action => action && action.visible !== false).map(action =>
      button(action.label, {
        variant: action.danger ? "danger" : "ghost",
        disabled: Boolean(action.disabled),
        onClick: () => {
          details.open = false;
          action.onClick?.();
        }
      })));
  details.append(summary, choices);
  details.addEventListener("keydown", event => {
    if (event.key === "Escape" && details.open) {
      event.preventDefault();
      details.open = false;
      summary.focus();
    }
  });
  details.addEventListener("focusout", event => {
    if (details.open && !details.contains(event.relatedTarget)) details.open = false;
  });
  return details;
}
