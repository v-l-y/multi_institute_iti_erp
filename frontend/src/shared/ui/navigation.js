import { el } from "./dom.js";

/** Breadcrumbs from supplied accessible paths; never infers tenant/scope. */
export function breadcrumbs(items = [], { label = "Breadcrumb" } = {}) {
  return el("nav", { className: "erp-breadcrumbs", "aria-label": label },
    el("ol", {},
      ...items.map((item, i) => el("li", {},
        item.href && i !== items.length - 1
          ? el("a", { href: item.href }, item.label)
          : el("span", { "aria-current": i === items.length - 1 ? "page" : "false" }, item.label)))));
}

/** Optional state/filter chips; caller determines selected states and semantics. */
export function filterChips(items = [], { onRemove } = {}) {
  return el("div", { className: "erp-filter-chips", role: "group", "aria-label": "Active filters" },
    ...items.map(({ key, label }) => el("span", { className: "erp-filter-chip" },
      el("span", {}, label),
      typeof onRemove === "function"
        ? el("button", { type: "button", "aria-label": "Remove " + label,
          onClick: () => onRemove(key) }, "×")
        : null)));
}

export function bulkActions(selectedIds = [], actions = [], { label = "Selected rows" } = {}) {
  const count = selectedIds.length;
  return el("div", { className: "erp-bulk-actions", role: "region", "aria-label": label },
    el("strong", {}, count + " selected"),
    ...actions.filter(action => action.visible !== false)
      .map(action => el("button", {
        type: "button", className: "button " + (action.danger ? "button-danger" : "button-secondary"),
        disabled: count === 0 || Boolean(action.disabled),
        onClick: () => action.onClick?.([...selectedIds])
      }, action.label)));
}
