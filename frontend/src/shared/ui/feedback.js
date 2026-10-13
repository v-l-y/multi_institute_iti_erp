import { el } from "./dom.js";

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
