import { el } from "./dom.js";
import { registerValidator } from "./validation.js";

let lookupSequence = 0;
/**
 * Authorized master lookup. Native select stays enabled while fetching so
 * FormData cannot silently omit it; validity blocks submission until settled.
 */
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
  const feedback = el("p", { id: id + "-feedback", className: "field-help", role: "status" });
  select.setAttribute("aria-describedby", feedback.id);
  const refresh = el("button", {
    type: "button", className: "button button-secondary",
    "aria-label": "Refresh " + label
  }, "↻");
  const node = el("div", { className: "erp-lookup" },
    el("label", { className: "field" }, el("span", {}, label), select),
    el("div", { className: "erp-lookup-toolbar" }, query, refresh), feedback);
  let pending = null, epoch = 0, disposed = false;
  let selected = String(value ?? "");
  let selectedLabel = "";
  let resolved = selected === "";
  let options = [];
  const validator = registerValidator(select, () => {
    if (pending) return "Wait until " + label + " finishes loading.";
    if (selected && !resolved) return "Select a verified " + label + " record.";
    return "";
  });

  function render(items) {
    options = items;
    const matching = items.find(item => String(item.value) === selected);
    if (matching) {
      selectedLabel = String(matching.label ?? matching.value);
      resolved = true;
    }
    const shown = [...items];
    if (selected && resolved && !matching) {
      shown.unshift({ value: selected, label: selectedLabel || "Selected record" });
    }
    select.replaceChildren(el("option", { value: "" }, placeholder),
      ...shown.map(item => el("option", { value: String(item.value) },
        String(item.label ?? item.value))));
    select.value = selected && resolved ? selected : "";
    if (selected && !resolved) {
      feedback.textContent = "Selected record could not be verified. Choose another record.";
    } else {
      feedback.textContent = items.length + " available records";
    }
    validator.validate();
  }

  async function reload(search = query.value) {
    if (disposed || disabled) return;
    pending?.abort();
    const controller = new AbortController();
    const requestId = ++epoch;
    pending = controller;
    select.setAttribute("aria-busy", "true");
    refresh.disabled = true;
    validator.validate();
    feedback.textContent = "Loading available records…";
    try {
      if (search.trim().length < minQueryLength) {
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
      feedback.textContent = "Unable to load records. Try Refresh.";
      // Do not remove the previously selected, authorized value on network errors.
    } finally {
      if (pending === controller) {
        pending = null;
        select.removeAttribute("aria-busy");
        refresh.disabled = false;
        validator.validate();
      }
    }
  }

  select.addEventListener("change", () => {
    selected = select.value;
    const matching = options.find(item => String(item.value) === selected);
    resolved = selected === "" || Boolean(matching) || (selected === String(value) && resolved);
    if (matching) selectedLabel = String(matching.label ?? matching.value);
    validator.validate();
    onChange?.(selected);
  });
  query.addEventListener("change", () => void reload());
  query.addEventListener("keydown", event => {
    if (event.key === "Enter") { event.preventDefault(); void reload(); }
  });
  refresh.addEventListener("click", () => void reload());
  render([]);
  return {
    node, select, query, load: reload,
    setValue(next) {
      selected = String(next ?? "");
      const matching = options.find(item => String(item.value) === selected);
      resolved = selected === "" || Boolean(matching);
      if (matching) selectedLabel = String(matching.label ?? matching.value);
      render(options);
    },
    dispose() {
      disposed = true;
      pending?.abort();
      pending = null;
      validator.dispose();
    }
  };
}
