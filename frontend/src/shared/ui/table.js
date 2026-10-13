import { el, formatValue } from "./dom.js";
import { status } from "./status.js";

export function dataTable(rows, columns) {
  const headers = el("tr", {}, ...columns.map(column => el("th", { scope: "col" }, column[1])));
  const body = el("tbody", {},
    ...rows.map(row => el("tr", {},
      ...columns.map(column => {
        const value = row?.[column[0]];
        const semanticStatus = /^(status|affiliation_status|version_status|source_status)$/.test(column[0]);
        return el("td", {}, semanticStatus ? status(value) : formatValue(value));
      })
    )));
  return el("div", { className: "table-scroll", role: "region", "aria-label": "Register records", tabindex: "0" },
    el("table", { className: "data-table" }, el("thead", {}, headers), body));
}
