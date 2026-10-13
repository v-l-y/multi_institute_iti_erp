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

/**
 * Print caller-authorized rows through textContent-only DOM nodes and an
 * external CSS stylesheet. No inline CSS, HTML strings or credential copying.
 */
export function printCurrentPage(rows, columns, {
  title = "Register", authorize
} = {}) {
  if (typeof authorize !== "function" || authorize() !== true) {
    throw new Error("Print requires explicit authorization from the owning module.");
  }
  if (!Array.isArray(rows) || !Array.isArray(columns)) {
    throw new TypeError("Print requires rows and columns.");
  }
  const popup = window.open("", "_blank");
  if (!popup) throw new Error("Browser blocked print preview.");
  popup.opener = null;
  const doc = popup.document;
  doc.open();
  doc.write("<!doctype html><html><head><meta charset=\"utf-8\"></head><body></body></html>");
  doc.close();
  doc.title = String(title);
  const stylesheet = doc.createElement("link");
  stylesheet.rel = "stylesheet";
  stylesheet.href = new URL("/src/styles/print.css", window.location.origin).href;
  doc.head.append(stylesheet);
  const heading = doc.createElement("h1");
  heading.textContent = String(title);
  const table = doc.createElement("table");
  const head = doc.createElement("thead");
  const headRow = doc.createElement("tr");
  columns.forEach(([, label]) => {
    const th = doc.createElement("th");
    th.scope = "col";
    th.textContent = String(label);
    headRow.append(th);
  });
  head.append(headRow);
  const body = doc.createElement("tbody");
  rows.forEach(row => {
    const tr = doc.createElement("tr");
    columns.forEach(([key]) => {
      const td = doc.createElement("td");
      td.textContent = formatValue(row?.[key]);
      tr.append(td);
    });
    body.append(tr);
  });
  table.append(head, body);
  doc.body.append(heading, table);
  let printed = false;
  const requestPrint = () => {
    if (!printed && !popup.closed) {
      printed = true;
      popup.focus();
      popup.print();
    }
  };
  stylesheet.addEventListener("load", requestPrint, { once: true });
  stylesheet.addEventListener("error", requestPrint, { once: true });
  window.setTimeout(requestPrint, 2000);
}
