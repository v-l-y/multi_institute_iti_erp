import { el, formatValue } from "./dom.js";
import { status } from "./status.js";

/**
 * Native accessible table. Only explicitly supplied columns are rendered.
 * No HTML interpolation; cell values are text nodes.
 */
export function dataTable(rows, columns, { caption = "Register records" } = {}) {
  if (!Array.isArray(rows) || !Array.isArray(columns)) {
    throw new TypeError("Table requires rows and columns");
  }
  const header = el("tr", {},
    ...columns.map(([key, title]) => el("th", { scope: "col" }, title)));
  const body = el("tbody", {},
    ...rows.map(row => el("tr", {},
      ...columns.map(([key]) => {
        const value = row?.[key];
        const semanticStatus = /^(status|affiliation_status|version_status|source_status)$/.test(key);
        return el("td", {}, semanticStatus ? status(value) : formatValue(value));
      }))));
  return el("div", {
    className: "table-scroll", role: "region",
    "aria-label": caption, tabindex: "0"
  }, el("table", { className: "data-table" },
    el("caption", { className: "visually-hidden" }, caption),
    el("thead", {}, header), body));
}
