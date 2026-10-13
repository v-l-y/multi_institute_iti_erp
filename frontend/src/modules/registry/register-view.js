import { api, ApiError } from "../../core/http/client.js";
import { el, dataTable, emptyState, loadingState, notice } from "../../shared/ui/index.js";
import { pageHeader } from "../../shared/ui/headers.js";
import { creationConfig } from "./create-config.js";
import { openCreationDialog } from "./create-dialog.js";

/**
 * One view instance owns its cursor history, search, in-flight reads and modal.
 * Refreshing a register never replaces the app shell, toolbar or focused controls.
 */
export function createRegistryView(page, main, user) {
  let cursors = [0];
  let cursorIndex = 0;
  let inFlight = null;
  let disposed = false;
  let activeDialog = null;
  let rows = [];

  const search = el("input", {
    type: "search", className: "search-input",
    "aria-label": "Search current page",
    placeholder: "Search this page…"
  });
  const count = el("span", { className: "muted", "aria-live": "polite" });
  const dataArea = el("div", { "aria-busy": "false" });
  const prev = el("button", {
    type: "button", className: "button button-secondary",
    onClick: () => {
      if (cursorIndex > 0 && !inFlight) {
        cursorIndex--;
        void load();
      }
    }
  }, "← Previous");
  const next = el("button", {
    type: "button", className: "button button-secondary",
    onClick: () => {
      if (inFlight || !Number.isSafeInteger(nextCursor) || nextCursor <= cursors[cursorIndex]) return;
      cursors = cursors.slice(0, cursorIndex + 1);
      cursors.push(nextCursor);
      cursorIndex++;
      void load();
    }
  }, "Next →");
  const refresh = el("button", {
    type: "button", className: "button button-secondary",
    onClick: () => void load()
  }, "↻ Refresh");
  const actions = el("div", { className: "heading-actions" }, refresh);
  const pageInfo = el("span", { className: "page-hint" });
  let nextCursor = null;

  if (creationConfig(page.id, user)) {
    actions.prepend(el("button", {
      type: "button", className: "button button-primary",
      onClick: () => {
        if (disposed) return;
        if (activeDialog?.open) activeDialog.close();
        const dialog = openCreationDialog(page, user, () => {
          if (!disposed) void load();
        });
        activeDialog = dialog;
        dialog?.addEventListener("close", () => {
          if (activeDialog === dialog) activeDialog = null;
        }, { once: true });
      }
    }, "+ Add record"));
  }

  const toolbar = el("div", { className: "register-tools" }, search, pageInfo);
  const footer = el("div", { className: "register-bottom" }, count,
    el("div", { className: "pager" }, prev, next));
  main.replaceChildren(
    pageHeader(page.label, "Records returned by the authenticated ERP API.", actions),
    el("section", {
      className: "surface register-surface",
      "aria-label": page.label + " register"
    }, toolbar, dataArea, footer)
  );

  function updatePaging(busy = false) {
    refresh.disabled = busy;
    prev.disabled = busy || cursorIndex === 0;
    next.disabled = busy || nextCursor === null;
    pageInfo.textContent = "Page " + (cursorIndex + 1) + " · 25 per page";
  }

  function renderRows() {
    const term = search.value.trim().toLocaleLowerCase();
    const filtered = term
      ? rows.filter(row => Object.values(row).some(value =>
        value !== null && value !== undefined &&
        String(value).toLocaleLowerCase().includes(term)))
      : rows;
    count.textContent = filtered.length + " of " + rows.length + " records on this page";
    dataArea.replaceChildren(filtered.length
      ? dataTable(filtered, page.columns)
      : emptyState(rows.length ? "No matching records" : "No records on this page",
        rows.length ? "Search applies only to the loaded page." :
          "No data has been registered for this view."));
  }
  search.addEventListener("input", renderRows);
  updatePaging();

  async function load() {
    if (disposed) return;
    inFlight?.abort();
    const controller = new AbortController();
    inFlight = controller;
    nextCursor = null;
    updatePaging(true);
    dataArea.setAttribute("aria-busy", "true");
    dataArea.replaceChildren(loadingState());

    const after = cursors[cursorIndex];
    const path = page.path + "?page_size=25" +
      (after ? "&after_id=" + encodeURIComponent(after) : "");
    try {
      const payload = await api.get(path, { signal: controller.signal });
      if (disposed || inFlight !== controller) return;
      if (!payload || !Array.isArray(payload[page.key])) {
        throw new ApiError("Unexpected register response format.", "INVALID_RESPONSE");
      }
      rows = payload[page.key];
      const candidate = Number(payload.next_after_id);
      nextCursor = payload.next_after_id !== null &&
        payload.next_after_id !== undefined &&
        Number.isSafeInteger(candidate) && candidate > after
        ? candidate : null;
      renderRows();
    } catch (error) {
      if (error.name === "AbortError" || disposed || inFlight !== controller) return;
      rows = [];
      count.textContent = "Records unavailable";
      dataArea.replaceChildren(notice("Unable to load records",
        error.message || "Please try again.", "error"),
        el("button", {
          type: "button", className: "button button-secondary",
          onClick: () => void load()
        }, "Try again"));
    } finally {
      if (inFlight === controller) {
        inFlight = null;
        dataArea.setAttribute("aria-busy", "false");
        updatePaging(false);
      }
    }
  }

  return {
    mount() { return load(); },
    dispose() {
      disposed = true;
      inFlight?.abort();
      inFlight = null;
      if (activeDialog?.open) activeDialog.close();
      activeDialog = null;
    }
  };
}
