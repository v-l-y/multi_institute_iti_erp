import { el, formatValue } from "./dom.js";

/** Display only caller-computed, source-verified metrics; never manufacture counts. */
export function statCards(metrics = [], { title = "Summary" } = {}) {
  if (!Array.isArray(metrics)) throw new TypeError("Metrics must be an array");
  return el("section", { className: "erp-stat-grid", "aria-label": title },
    ...metrics.map(({ label, value, note, unit }) => el("article", { className: "erp-stat-card" },
      el("p", { className: "erp-stat-label" }, label),
      el("strong", { className: "erp-stat-value" }, formatValue(value), unit ? " " + unit : ""),
      note ? el("p", { className: "muted" }, note) : null)));
}
