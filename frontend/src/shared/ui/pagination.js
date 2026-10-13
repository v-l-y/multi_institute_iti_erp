import { el } from "./dom.js";
import { button } from "./buttons.js";

/** Cursor pagination: never invents a row total or a page count. */
export function cursorPagination({ onPrevious, onNext, page = 1 } = {}) {
  if (typeof onPrevious !== "function" || typeof onNext !== "function") {
    throw new TypeError("Cursor pagination requires callbacks");
  }
  const previous = button("← Previous", { onClick: onPrevious });
  const next = button("Next →", { onClick: onNext });
  const label = el("span", { className: "page-hint", "aria-live": "polite" });
  const node = el("nav", { className: "pager", "aria-label": "Register pages" },
    previous, label, next);
  const update = ({ page: nextPage = page, hasNext = false, busy = false } = {}) => {
    previous.disabled = busy || nextPage <= 1;
    next.disabled = busy || !hasNext;
    label.textContent = "Page " + nextPage;
    node.setAttribute("aria-busy", String(busy));
  };
  update({ page });
  return { node, previous, next, update };
}
