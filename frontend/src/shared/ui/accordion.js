import { el } from "./dom.js";

/** Native details/summary, accessible without scripting a custom control. */
export function accordion(items = [], { expanded = [] } = {}) {
  return el("div", { className: "accordion" },
    ...items.map((item, i) => el("details", {
      className: "accordion-item", ...(expanded.includes(i) ? { open: true } : {})
    }, el("summary", {}, item.label), el("div", { className: "accordion-content" }, item.content))));
}
