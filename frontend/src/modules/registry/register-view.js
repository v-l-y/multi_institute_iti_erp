import { api, ApiError } from "../../core/http/client.js";
import { el, dataTable, emptyState, loadingState, notice } from "../../shared/ui/index.js";
import { pageHeader } from "../../shared/ui/headers.js";
import { creationConfig } from "./create-config.js";
import { openCreationDialog } from "./create-dialog.js";

/* Cursor and abort state belong to one mounted register, never to all routes. */
export function createRegistryView(page, main, user) {
  let cursors = [null];
  let cursorIndex = 0;
  let viewRequest = null;
  let disposed = false;

function searchRows(rows, term) {
  const q = term.trim().toLocaleLowerCase();
  if (!q) return rows;
  return rows.filter(row => Object.values(row).some(value =>
    value !== null && value !== undefined && String(value).toLocaleLowerCase().includes(q)));
}

function nextPage(cursor) {
  if (!Number.isSafeInteger(Number(cursor)) || Number(cursor) <= 0) return;
  cursors = cursors.slice(0, cursorIndex + 1);
  cursors.push(Number(cursor));
  cursorIndex++;
}

async function loadRegister(page) {
  viewRequest?.abort();
  const controller = new AbortController();
  viewRequest = controller;
  const refresh = el("button", { type: "button", className: "button button-secondary",
    onClick: () => loadRegister(page) }, "↻ Refresh");
  const tools = el("div", { className: "heading-actions" }, refresh);
  if (creationConfig(page.id, user)) {
    tools.prepend(el("button", { type: "button", className: "button button-primary",
      onClick: () => openCreationDialog(page, user, () => loadRegister(page)) }, "+ Add record"));
  }
  const holder = el("div", { className: "surface register-surface" }, loadingState());
  main.replaceChildren(
    pageHeader(page.label, "Source-backed records from the authenticated ERP API.", tools),
    holder
  );
  const after = cursors[cursorIndex];
  const path = page.path + "?page_size=25" + (after ? "&after_id=" + encodeURIComponent(after) : "");
  try {
    const payload = await api.get(path, { signal: controller.signal });
    if ((disposed || viewRequest !== controller)) return;
    if (!payload || !Array.isArray(payload[page.key])) {
      throw new ApiError("The server returned an unexpected register format.", "INVALID_RESPONSE");
    }
    const rows = payload[page.key];
    const search = el("input", { type: "search", className: "search-input",
      "aria-label": "Search current page", placeholder: "Search this page…" });
    const dataArea = el("div");
    const count = el("span", { className: "muted" });
    const renderFiltered = () => {
      const filtered = searchRows(rows, search.value);
      count.textContent = filtered.length + " of " + rows.length + " records on this page";
      dataArea.replaceChildren(filtered.length
        ? dataTable(filtered, page.columns)
        : emptyState(rows.length ? "No matching records" : "No records on this page",
          rows.length ? "Try a different search. Search covers only loaded page records." :
            "No data has been registered for this view."));
    };
    search.addEventListener("input", renderFiltered);
    const previous = el("button", { type: "button", className: "button button-secondary",
      disabled: cursorIndex === 0,
      onClick: () => { if (cursorIndex > 0) { cursorIndex--; loadRegister(page); } } }, "← Previous");
    const next = el("button", { type: "button", className: "button button-secondary",
      disabled: payload.next_after_id === null || payload.next_after_id === undefined,
      onClick: () => { nextPage(payload.next_after_id); loadRegister(page); } }, "Next →");
    const toolsBar = el("div", { className: "register-tools" }, search,
      el("span", { className: "page-hint" }, "Page " + (cursorIndex + 1) + " · 25 per page"));
    const pagination = el("div", { className: "register-bottom" }, count,
      el("div", { className: "pager" }, previous, next));
    holder.replaceChildren(toolsBar, dataArea, pagination);
    renderFiltered();
  } catch (err) {
    if (err.name === "AbortError" || (disposed || viewRequest !== controller)) return;
    holder.replaceChildren(notice("Unable to load records", err.message, "error"),
      el("button", { className: "button button-secondary", type: "button",
        onClick: () => loadRegister(page) }, "Try again"));
  }
}

  return {
    mount() { if (!disposed) return loadRegister(page); },
    dispose() {
      disposed = true;
      viewRequest?.abort();
      viewRequest = null;
    }
  };
}
