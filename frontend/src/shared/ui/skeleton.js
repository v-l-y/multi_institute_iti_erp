import { el } from "./dom.js";

export function skeleton({ rows = 4, columns = 1, label = "Loading records" } = {}) {
  const count = Math.max(1, Math.min(20, Math.floor(rows)));
  const cols = Math.max(1, Math.min(8, Math.floor(columns)));
  return el("div", { className: "skeleton", role: "status", "aria-label": label, "aria-busy": "true" },
    el("span", { className: "visually-hidden" }, label),
    ...Array.from({ length: count }, () => el("div", { className: "skeleton-row", "aria-hidden": "true" },
      ...Array.from({ length: cols }, () => el("span", { className: "skeleton-cell" })))));
}
