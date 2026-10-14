import { api, ApiError } from "../../core/http/client.js";
import {
  el, button, dataTable, emptyState, skeleton, errorPanel,
  cursorPagination, tableFilters, filterRows, permissionButton,
  columnChooser, openRecordDetails
} from "../../shared/ui/index.js";
import { pageHeader } from "../../shared/ui/headers.js";
import { creationConfig } from "./create-config.js";
import { openCreationDialog } from "./create-dialog.js";
import { createHydrationRegion } from "../../core/hydration/region.js";

/** Shared UI is the single owner of table controls. Backend owns tenant and permissions. */
export function createRegistryView(page, main, user, { initialState = null } = {}) {
  const restored = initialState || {};
  const history = Array.isArray(restored.cursors) && restored.cursors.length < 101 &&
    restored.cursors[0] === 0 &&
    restored.cursors.every(n => Number.isSafeInteger(n) && n >= 0);
  let cursors = history ? [...restored.cursors] : [0];
  let cursorIndex = Number.isSafeInteger(restored.cursorIndex) &&
    restored.cursorIndex >= 0 && restored.cursorIndex < cursors.length
    ? restored.cursorIndex : 0;
  let inFlight = null;
  let disposed = false;
  let activeDialog = null;
  let rows = [];
  let nextCursor = null;

  const count = el("span", { className: "muted", "aria-live": "polite" });
  const dataArea = el("div", { "aria-busy": "false" });
  const region = createHydrationRegion(dataArea);
  const pageHint = el("span", { className: "page-hint" }, "25 per page · search and sort this page");
  const filters = tableFilters({
    columns: page.columns, initialState: restored.filters,
    onChange: () => renderRows()
  });
  const columns = columnChooser(page.columns, {
    initialKeys: restored.visibleColumns, onChange: () => renderRows()
  });
  let detailsDialog = null;
  const pager = cursorPagination({
    onPrevious: () => {
      if (cursorIndex === 0 || inFlight || disposed) return;
      cursorIndex--;
      void load();
    },
    onNext: () => {
      if (inFlight || disposed || !Number.isSafeInteger(nextCursor) ||
          nextCursor <= cursors[cursorIndex]) return;
      cursors = cursors.slice(0, cursorIndex + 1);
      cursors.push(nextCursor);
      cursorIndex++;
      void load();
    }
  });
  const refresh = button("↻ Refresh", { onClick: () => void load() });
  const actions = el("div", { className: "heading-actions" }, refresh);

  const createPermission = page.id === "staff" ? "staff.manage"
    : page.id === "students" ? "student.manage" : null;
  if (createPermission && creationConfig(page.id, user)) {
    const add = permissionButton(user, createPermission, "+ Add record", {
      variant: "primary",
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
    });
    if (add) actions.prepend(add);
  }

  const footer = el("div", { className: "register-bottom" }, count, pager.node);
  main.replaceChildren(
    pageHeader(page.label, "Records returned by the authenticated ERP API.", actions),
    el("section", { className: "surface register-surface", "aria-label": page.label + " register" },
      el("div", { className: "register-tools" }, filters.node, columns.node, pageHint),
      dataArea, footer)
  );

  function updatePaging(busy = false) {
    refresh.disabled = busy;
    pager.update({ page: cursorIndex + 1, hasNext: nextCursor !== null, busy });
  }

  function renderRows() {
    if (disposed) return;
    const filtered = filterRows(rows, {
      ...filters.read(),
      searchKeys: columns.getColumns().map(([key]) => key)
    });
    count.textContent = filtered.length + " of " + rows.length + " records on this page";
    region.update(filtered.length
      ? dataTable(filtered, columns.getColumns(), {
          caption: page.label + " (current page)",
          rowActions: record => [{
            label: "View",
            ariaLabel: "View " + page.label + " record " + String(record.id ?? record.code ?? "details"),
            onClick: () => {
              if (disposed) return;
              if (detailsDialog?.open) detailsDialog.close();
              const dialog = openRecordDetails(record, page.columns, {
                title: page.label + " record",
                description: "Fields returned by the authorized register API."
              });
              detailsDialog = dialog;
              dialog.addEventListener("close", () => {
                if (detailsDialog === dialog) detailsDialog = null;
              }, { once: true });
            }
          }]
        })
      : emptyState(rows.length ? "No matching records" : "No records on this page",
        rows.length ? "Filters apply only to the loaded page." :
          "No data has been registered for this view."));
  }

  updatePaging();

  async function load() {
    if (disposed) return;
    const controller = region.begin({
      placeholder: skeleton({ rows: 5, columns: page.columns.length })
    });
    if (!controller) return;
    inFlight = controller;
    nextCursor = null;
    updatePaging(true);


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
      region.fail(controller, errorPanel(
        error.message || "Please try again.", { title: "Unable to load records", onRetry: () => void load() }));
    } finally {
      if (inFlight === controller) {
        inFlight = null;
        region.end(controller);
        updatePaging();
      }
    }
  }

  return {
    mount() { return load(); },
    snapshot() {
      return {
        filters: filters.read(),
        visibleColumns: columns.getState(),
        cursors: cursors.slice(0, 100),
        cursorIndex
      };
    },
    dispose() {
      disposed = true;
      region.dispose();
      inFlight?.abort();
      inFlight = null;
      if (activeDialog?.open) activeDialog.close();
      activeDialog = null;
      if (detailsDialog?.open) detailsDialog.close();
      detailsDialog = null;
    }
  };
}
