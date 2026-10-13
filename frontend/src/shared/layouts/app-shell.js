import { el } from "../ui/dom.js";

/* The shell is presentation only; it cannot authorize backend requests. */
export function createAppShell(root, user, onLogout, pages, canAccess) {
  let shell = null;
  let sideNav = null;
  let main = null;
  let heading = null;
  let subtitle = null;
  let currentId = "";
  const permittedPages = () => pages.filter(page => canAccess(page, user));

function mountShell() {
  const nav = el("nav", { className: "side-nav", "aria-label": "Main navigation" });
  sideNav = nav;
  const sidebar = el("aside", { className: "sidebar", id: "sidebar" },
    el("div", { className: "sidebar-head" },
      el("a", { href: "#/dashboard", className: "brand" },
        el("span", { className: "brand-logo", "aria-hidden": "true" }, "I"),
        el("span", {}, "ITI ERP")),
      el("span", { className: "brand-caption" }, "INSTITUTE MANAGEMENT")),
    nav,
    el("div", { className: "sidebar-foot" },
      el("span", { className: "tiny-dot" }, "●"),
      "Bihar · Private ITIs"));

  heading = el("h1", {}, "Overview");
  subtitle = el("p", { className: "muted" }, "Institute management workspace");
  main = el("main", { className: "page-content", id: "main-content", tabindex: "-1" });
  const crumb = el("span", { className: "breadcrumb" }, "Overview");
  const menuButton = el("button", {
    className: "icon-button mobile-menu", type: "button", "aria-label": "Open navigation",
    "aria-expanded": "false", "aria-controls": "sidebar"
  }, "☰");
  const backdrop = el("button", { className: "sidebar-backdrop", type: "button", "aria-label": "Close navigation" });
  const closeNav = () => {
    shell.classList.remove("sidebar-open");
    menuButton.setAttribute("aria-expanded", "false");
  };
  menuButton.addEventListener("click", () => {
    const open = shell.classList.toggle("sidebar-open");
    menuButton.setAttribute("aria-expanded", String(open));
  });
  backdrop.addEventListener("click", closeNav);
  nav.addEventListener("click", event => { if (event.target.closest("a")) closeNav(); });

  const scope = user.institute_id === null ? "Platform account" : "Institute #" + user.institute_id;
  const initials = user.username.slice(0, 2).toUpperCase();
  const topbar = el("header", { className: "topbar" },
    el("div", { className: "topbar-left" }, menuButton,
      el("div", { className: "crumb-wrap" }, el("span", { className: "crumb-parent" }, "WORKSPACE /"), crumb)),
    el("div", { className: "topbar-right" },
      el("span", { className: "account-scope" }, scope),
      el("span", { className: "avatar", title: user.username, "aria-label": "Signed in as " + user.username }, initials),
      el("button", { type: "button", className: "button button-ghost signout", onClick: onLogout }, "Sign out")));
  shell = el("div", { className: "app-shell" }, sidebar,
    el("div", { className: "workspace" }, topbar,
      el("div", { className: "page-head" },
        el("div", {}, el("p", { className: "eyebrow" }, "MANAGEMENT PORTAL"), heading, subtitle)),
      main,
      el("footer", { className: "app-footer" }, "Institute records are not official government approvals or certifications.")),
    backdrop, el("div", { id: "toast-root", className: "toast-root", "aria-live": "polite" }));
  root.replaceChildren(shell);
  function updateBreadcrumb(value) { crumb.textContent = value; }
  shell.updateBreadcrumb = updateBreadcrumb;
  renderNavigation();
}

function renderNavigation() {
  const permitted = permittedPages();
  const groups = [...new Set(permitted.map(page => page.group))];
  sideNav.replaceChildren(...groups.map(group => el("div", { className: "nav-group" },
    el("p", { className: "nav-group-title" }, group),
    ...permitted.filter(page => page.group === group).map(page => el("a", {
      href: "#/" + page.id, className: "nav-link" + (page.id === currentId ? " is-active" : ""),
      ...(page.id === currentId ? { "aria-current": "page" } : {})
    }, el("span", { className: "nav-icon", "aria-hidden": "true" }, page.icon),
      el("span", {}, page.label))))));
}

  mountShell();
  return {
    get main() { return main; },
    setPage(page) {
      currentId = page.id;
      heading.textContent = page.label;
      subtitle.textContent = user.institute_id === null
        ? "Platform workspace" : "Institute #" + user.institute_id;
      shell.updateBreadcrumb(page.label);
      renderNavigation();
    },
    showRestricted() {
      currentId = "";
      heading.textContent = "Restricted page";
      subtitle.textContent = "This route is not available to your account.";
      shell.updateBreadcrumb("Restricted");
      renderNavigation();
    }
  };
}
