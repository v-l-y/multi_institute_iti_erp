import { el } from "./dom.js";

/**
 * Server-backed master lookup with native select + search. Caller provides the
 * authorized loader; shared UI never guesses endpoint, tenant or option values.
 */
let lookupSequence = 0;
export function masterLookup({
  name, label, loadOptions, required = true, value = "", placeholder = "Choose a record",
  minQueryLength = 0, onChange, disabled = false
} = {}) {
  if (typeof loadOptions !== "function" || !name || !label) {
    throw new TypeError("Lookup needs name, label and a permitted loader");
  }
  const id = "erp-lookup-" + ++lookupSequence;
  const query = el("input", {
    type: "search", "aria-label": "Search " + label,
    placeholder: "Search " + label, autocomplete: "off"
  });
  const select = el("select", { id, name, required, disabled });
  const feedback = el("p", { className: "field-help", role: "status" });
  const refresh = el("button", { type: "button", className: "button button-secondary",
    "aria-label": "Refresh " + label }, "↻");
  const node = el("div", { className: "erp-lookup" },
    el("label", { className: "field", for: id }, el("span", {}, label), select),
    el("div", { className: "erp-lookup-toolbar" }, query, refresh), feedback);
  let pending = null, epoch = 0, disposed = false;
  let selected = value;
  function render(items) {
    select.replaceChildren(el("option", { value: "" }, placeholder),
      ...items.map(item => {
        if (item === null || item === undefined || item.value == null) return null;
        return el("option", { value: String(item.value) }, String(item.label ?? item.value));
      }).filter(Boolean));
    const found = [...select.options].some(option => option.value === String(selected));
    select.value = found ? String(selected) : "";
    if (!found && selected !== "" && selected !== null) {
      feedback.textContent = "Previously selected record is not in these results.";
    } else {
      feedback.textContent = items.length + " available records";
    }
  }
  async function reload(search = query.value) {
    if (disposed) return;
    pending?.abort();
    const controller = new AbortController();
    const requestId = ++epoch; pending = controller;
    select.disabled = refresh.disabled = true;
    feedback.textContent = "Loading available records…";
    try {
      if (search.trim().length < minQueryLength) {
        select.replaceChildren(el("option", { value: "" }, placeholder));
        feedback.textContent = "Type at least " + minQueryLength + " characters.";
        return;
      }
      const items = await loadOptions(search.trim(), { signal: controller.signal });
      if (disposed || requestId !== epoch || controller.signal.aborted) return;
      if (!Array.isArray(items) || items.some(item => !item || item.value == null)) {
        throw new TypeError("Lookup loader must return [{value,label}, ...]");
      }
      render(items);
    } catch (error) {
      if (disposed || controller.signal.aborted || requestId !== epoch) return;
      select.replaceChildren(el("option", { value: "" }, placeholder));
      feedback.textContent = "Unable to load records. Try Refresh.";
    } finally {
      if (pending === controller) {
        pending = null;
        select.disabled = disabled;
        refresh.disabled = false;
      }
    }
  }
  select.addEventListener("change", () => {
    selected = select.value;
    onChange?.(select.value);
  });
  query.addEventListener("change", () => void reload());
  query.addEventListener("keydown", event => {
    if (event.key === "Enter") { event.preventDefault(); void reload(); }
  });
  refresh.addEventListener("click", () => void reload());
  render([]);
  return { node, select, query, load: reload,
    setValue(next) { selected = String(next ?? ""); select.value = selected; },
    dispose() { disposed = true; pending?.abort(); pending = null; }
  };
}
