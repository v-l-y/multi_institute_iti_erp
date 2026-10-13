/* Shared, DOM-safe UI primitives. API values are never inserted as HTML. */
export function el(tag, props = {}, ...children) {
  const node = document.createElement(tag);
  for (const [key, value] of Object.entries(props || {})) {
    if (value === undefined || value === null || value === false) continue;
    if (key === "className") node.className = value;
    else if (key === "textContent") node.textContent = String(value);
    else if (key === "value" || key === "checked" || key === "disabled") node[key] = value;
    else if (key.startsWith("on") && typeof value === "function") node.addEventListener(key.slice(2).toLowerCase(), value);
    else if (value === true) node.setAttribute(key, "");
    else node.setAttribute(key, String(value));
  }
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return node;
}

export function formatValue(value) {
  if (value === null || value === undefined || value === "") return "—";
  if (value === true || value === 1 && typeof value === "boolean") return "Yes";
  if (value === false) return "No";
  if (typeof value === "object") return JSON.stringify(value);
  return String(value);
}

export function status(value) {
  const raw = formatValue(value);
  const normalized = raw.toLowerCase().replace(/[^a-z0-9_-]/g, "");
  const good = ["active", "published", "verified", "approved", "completed", "eligible"].includes(normalized);
  const caution = ["draft", "pending", "review", "provisional", "unverified"].includes(normalized);
  return el("span", { className: "status " + (good ? "status-good" : caution ? "status-warn" : "status-neutral") }, raw.replaceAll("_", " "));
}

export function notice(title, description, type = "info") {
  return el("div", { className: "notice notice-" + type, role: type === "error" ? "alert" : "status" },
    el("strong", {}, title), el("p", {}, description));
}

export function emptyState(title = "No records found", description = "There are no records available for this page.") {
  return el("div", { className: "empty-state" }, el("span", { className: "empty-mark", "aria-hidden": "true" }, "◇"),
    el("h3", {}, title), el("p", {}, description));
}

export function loadingState() {
  return el("div", { className: "loading-state", role: "status" },
    el("span", { className: "spinner", "aria-hidden": "true" }), "Loading records…");
}

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

export function field(name, label, { type = "text", required = true, maxLength, placeholder = "", options } = {}) {
  const id = "field-" + name;
  let input;
  if (Array.isArray(options)) {
    input = el("select", { id, name, required }, ...options.map(
      option => el("option", { value: option[0] }, option[1])
    ));
  } else if (name === "address") {
    input = el("textarea", { id, name, required, maxlength: maxLength || 16000, rows: "3", placeholder });
  } else {
    input = el("input", { id, name, type, required, placeholder,
      ...(maxLength ? { maxlength: maxLength } : {}) });
  }
  return el("label", { className: "field" }, el("span", {}, label), input);
}

export function toast(message, type = "success") {
  const root = document.getElementById("toast-root");
  if (!root) return;
  const box = notice(type === "error" ? "Request failed" : "Updated", message, type);
  box.classList.add("toast");
  root.replaceChildren(box);
  window.setTimeout(() => { if (root.contains(box)) box.remove(); }, 5500);
}
