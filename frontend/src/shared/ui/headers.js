import { el } from "./dom.js";

export function pageHeader(title, description, action = null) {
  const head = el("div", { className: "content-heading" },
    el("div", {}, el("h2", {}, title), el("p", { className: "muted" }, description)));
  if (action) head.append(action);
  return head;
}
