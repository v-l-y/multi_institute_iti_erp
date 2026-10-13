import { el, formatValue } from "./dom.js";

/** Compare exactly provided snapshots. Pure read-only display, not an audit source. */
export function auditDiff(before = {}, after = {}, fields = [], {
  title = "Recorded changes", showUnchanged = false
} = {}) {
  const changed = fields.filter(([key]) =>
    showUnchanged || JSON.stringify(before?.[key] ?? null) !== JSON.stringify(after?.[key] ?? null));
  const node = el("section", { className: "erp-audit-diff", "aria-label": title },
    el("h3", {}, title));
  if (!changed.length) {
    node.append(el("p", { className: "muted" }, "No differences in the supplied fields."));
    return node;
  }
  node.append(el("div", { className: "table-scroll", role: "region",
    "aria-label": title, tabindex: "0" },
    el("table", { className: "data-table" },
      el("caption", { className: "visually-hidden" }, title),
      el("thead", {}, el("tr", {},
        ...["Field", "Before", "After"].map(label => el("th", { scope: "col" }, label)))),
      el("tbody", {}, ...changed.map(([key, label]) => el("tr", {},
        el("th", { scope: "row" }, label),
        el("td", {}, formatValue(before?.[key])),
        el("td", {}, formatValue(after?.[key])))))));
  return node;
}
