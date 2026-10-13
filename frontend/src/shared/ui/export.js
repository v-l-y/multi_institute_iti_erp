import { formatValue } from "./dom.js";

/**
 * Manual, current-page CSV export. No server-wide export or implicit permission.
 * Caller must explicitly supply a synchronous authorization predicate.
 */
export function csvText(rows, columns) {
  const quote = value => {
    const raw = formatValue(value);
    // Spreadsheet formula injection: force dangerous leading characters to text.
    const escaped = /^[\s]*[=+\-@\t\r]/.test(raw) ? "'" + raw : raw;
    return '"' + escaped.replaceAll('"', '""') + '"';
  };
  return "\uFEFF" + [
    columns.map(([, title]) => quote(title)).join(","),
    ...rows.map(row => columns.map(([key]) => quote(row?.[key])).join(","))
  ].join("\r\n");
}

export function downloadCurrentPageCsv(rows, columns, {
  fileName = "register-page.csv", authorize
} = {}) {
  if (typeof authorize !== "function" || authorize() !== true) {
    throw new Error("CSV export requires explicit authorization from the owning module.");
  }
  if (!Array.isArray(rows) || !Array.isArray(columns)) throw new TypeError("Invalid CSV data");
  const blob = new Blob([csvText(rows, columns)], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = String(fileName).replace(/[^a-z0-9_.-]/gi, "_").slice(0, 100);
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  // Revoke after navigation gets the URL, not before the browser can read it.
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

/** Printable view: only caller-authorized, explicitly selected current-page fields. */
export function printCurrentPage(rows, columns, {
  title = "Register", authorize
} = {}) {
  if (typeof authorize !== "function" || authorize() !== true) {
    throw new Error("Print requires explicit authorization from the owning module.");
  }
  const popup = window.open("", "_blank");
  if (!popup) throw new Error("Browser blocked print preview.");
  popup.opener = null;
  const safe = value => String(formatValue(value)).replace(/[&<>"']/g, char =>
    ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char]);
  // The popup receives only escaped current-page values, no tokens or credentials.
  const table = "<table><thead><tr>" + columns.map(([, label]) =>
    "<th>" + safe(label) + "</th>").join("") + "</tr></thead><tbody>" +
    rows.map(row => "<tr>" + columns.map(([key]) =>
      "<td>" + safe(row?.[key]) + "</td>").join("") + "</tr>").join("") +
    "</tbody></table>";
  popup.document.open();
  popup.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' +
    safe(title) + '</title><style>body{font:14px system-ui;padding:20px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #aaa;padding:8px;text-align:left}</style></head><body><h1>' +
    safe(title) + "</h1>" + table + "</body></html>");
  popup.document.close();
  popup.focus();
  popup.print();
}
