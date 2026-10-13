import { el } from "./dom.js";

/** Local-only filtering/sorting for the currently loaded page, not whole DB. */
const collator = new Intl.Collator(undefined, { numeric: true, sensitivity: "base" });
export function filterRows(rows, { search = "", column = "", direction = "asc", filters = {}, searchKeys = [] } = {}) {
  const q = String(search).trim().toLocaleLowerCase();
  let result = rows.filter(row => {
    // Only explicitly searchable fields, never every field of a full API row.
    if (q && !searchKeys.some(key => {
      const value = row?.[key];
      return value !== null && value !== undefined &&
        String(value).toLocaleLowerCase().includes(q);
    })) return false;
    return Object.entries(filters).every(([key, value]) =>
      value === "" || String(row?.[key] ?? "") === String(value));
  });
  if (column) {
    const sign = direction === "desc" ? -1 : 1;
    result = [...result].sort((a, b) => {
      const left = a?.[column], right = b?.[column];
      if (left === null || left === undefined) return right == null ? 0 : 1;
      if (right === null || right === undefined) return -1;
      return sign * collator.compare(String(left), String(right));
    });
  }
  return result;
}

/** Explicitly labelled search, sort and filters; no fabricated filter options. */
export function tableFilters({ columns = [], filters = [], onChange, label = "Search this page" } = {}) {
  const search = el("input", {
    type: "search", className: "search-input", "aria-label": label,
    placeholder: "Search this page…"
  });
  const sortOptions = [["", "Original order"], ...columns.map(([key, title]) => [key, title])];
  const sort = el("select", { className: "table-filter-select", "aria-label": "Sort column" },
    ...sortOptions.map(([key, title]) => el("option", { value: key }, title)));
  const direction = el("select", { className: "table-filter-select", "aria-label": "Sort direction" },
    el("option", { value: "asc" }, "Ascending"),
    el("option", { value: "desc" }, "Descending"));
  const extra = filters.map(({ key, title, values = [] }) => {
    const control = el("select", { className: "table-filter-select", "aria-label": title },
      el("option", { value: "" }, "All " + title),
      ...values.map(([value, caption]) => el("option", { value }, caption)));
    return { key, control };
  });
  const read = () => ({
    search: search.value,
    column: sort.value,
    direction: direction.value,
    filters: Object.fromEntries(extra.map(({ key, control }) => [key, control.value]))
  });
  const notify = () => onChange?.(read());
  for (const control of [search, sort, direction, ...extra.map(x => x.control)]) {
    control.addEventListener(control === search ? "input" : "change", notify);
  }
  const node = el("div", { className: "table-filters", role: "group", "aria-label": "Current-page controls" },
    search, sort, direction, ...extra.map(x => x.control));
  return { node, search, read, reset() {
    search.value = ""; sort.value = ""; direction.value = "asc";
    extra.forEach(({ control }) => { control.value = ""; });
    notify();
  } };
}
