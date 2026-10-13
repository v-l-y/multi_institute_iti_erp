import { el, notice } from "../../shared/ui/index.js";
import { pageHeader } from "../../shared/ui/headers.js";

export function renderDashboard(main, user, available) {
  main.replaceChildren(
    el("section", { className: "welcome-panel" },
      el("div", {}, el("p", { className: "eyebrow eyebrow-light" }, "YOUR WORKSPACE"),
        el("h2", {}, "Welcome, " + user.username),
        el("p", {}, user.institute_id === null
          ? "Manage platform institute references and regulatory records within your granted permissions."
          : "Work with your institute's actual records and source-backed academic registers.")),
      el("span", { className: "welcome-art", "aria-hidden": "true" }, "ITI")),
    pageHeader("Available modules", "Only modules allowed by your current backend permissions are listed."),
    available.length
      ? el("div", { className: "module-grid" }, ...available.map(page =>
        el("a", { href: "#/" + page.id, className: "module-card" },
          el("span", { className: "module-icon", "aria-hidden": "true" }, page.icon),
          el("strong", {}, page.label),
          el("span", { className: "module-meta" }, page.group),
          el("span", { className: "module-arrow", "aria-hidden": "true" }, "↗"))))
      : notice("No module permissions granted", "Your role has no register-view permissions yet. An authorized administrator must assign explicit permissions."),
    el("div", { className: "bottom-note" },
      el("strong", {}, "Connected to existing ERP"),
      el("p", {}, "Displayed records come from authenticated backend APIs; no demo rows, automatic official decisions, or cross-institute shortcuts.")));
}
