import { el } from "./dom.js";

let tabSetId = 0;
/** Accessible tabs: Left/Right/Home/End controls, one active panel. */
export function tabs(items = [], { initial = 0, label = "Sections" } = {}) {
  if (!items.length) throw new TypeError("Tabs need items");
  const id = "erp-tabs-" + ++tabSetId;
  const selected = Math.max(0, Math.min(initial, items.length - 1));
  const buttons = items.map((item, i) => el("button", {
    type: "button", role: "tab",
    id: id + "-tab-" + i, "aria-controls": id + "-panel-" + i,
    "aria-selected": String(i === selected),
    tabindex: i === selected ? "0" : "-1"
  }, item.label));
  const panels = items.map((item, i) => el("section", {
    role: "tabpanel", id: id + "-panel-" + i,
    "aria-labelledby": id + "-tab-" + i,
    tabindex: "0", ...(i === selected ? {} : { hidden: true })
  }, item.content));
  let current = selected;
  function activate(index, focus = false) {
    current = (index + items.length) % items.length;
    buttons.forEach((node, i) => {
      node.setAttribute("aria-selected", String(i === current));
      node.tabIndex = i === current ? 0 : -1;
      panels[i].hidden = i !== current;
    });
    if (focus) buttons[current].focus();
  }
  buttons.forEach((node, index) => {
    node.addEventListener("click", () => activate(index));
    node.addEventListener("keydown", event => {
      const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: items.length - 1 }[event.key];
      if (next !== undefined) {
        event.preventDefault();
        activate(next, true);
      }
    });
  });
  return { node: el("div", { className: "tabs" },
    el("div", { role: "tablist", "aria-label": label, className: "tab-list" }, ...buttons),
    ...panels), activate };
}
