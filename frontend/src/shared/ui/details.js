import { el, formatValue } from "./dom.js";
import { openDialog } from "./dialog.js";

/** Definition-list for exactly the fields supplied by the authorized caller. */
export function recordDetails(record, fields, { title = "Record details" } = {}) {
  if (!record || !Array.isArray(fields)) throw new TypeError("Details require data and fields");
  return el("section", { className: "erp-record-details", "aria-label": title },
    el("dl", { className: "detail-grid" },
      ...fields.map(entry => {
        const key = Array.isArray(entry) ? entry[0] : entry.key;
        const label = Array.isArray(entry) ? entry[1] : entry.label;
        const displayed = entry.formatter ? entry.formatter(record[key], record) : formatValue(record[key]);
        return el("div", { className: "detail-item" },
          el("dt", {}, label),
          el("dd", {}, displayed instanceof Node ? displayed : String(displayed)));
      })));
}

export function openRecordDetails(record, fields, { title = "Record details", description = "" } = {}) {
  return openDialog({ title, description, content: recordDetails(record, fields, { title }),
    actions: [{ label: "Close", onClick: dialog => dialog.close() }] });
}
