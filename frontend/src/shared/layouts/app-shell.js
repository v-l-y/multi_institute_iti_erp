import { el } from "../ui/dom.js";

/** Presentation-only layout. PHP remains authoritative for every permission. */
export function createAppShell(root, user, onLogout, pages, canAccess, onSecurity) {
  const mobile = window.matchMedia("(max-width: 780px)");
  const permitted = pages.filter(page => canAccess(page, user));
  let currentId = "";
  let open = false;

  const nav = el("nav", { className: "side-nav", "aria-label": "Main navigation" });
  const sidebar = el("aside", { className: "sidebar", id: "sidebar" },
    el("div", { className: "sidebar-head" },
      el("a", { href: "#/dashboard", className: "brand" },
        el("span", { className: "brand-logo", "aria-hidden": "true" }, "I"),
        el("span", {}, "ITI ERP")),
      el("span", { className: "brand-caption" }, "INSTITUTE MANAGEMENT")),
    nav,
    el("div", { className: "sidebar-foot" },
      el("span", { className: "tiny-dot", "aria-hidden": "true" }, "●"),
      "Bihar · Private ITIs"));

  const heading = el("h1", {}, "Overview");
  const subtitle = el("p", { className: "muted" }, "Institute management workspace");
  const main = el("main", { className: "page-content", id: "main-content", tabindex: "-1" });
  const crumb = el("span", { className: "breadcrumb" }, "Overview");
  const menuButton = el("button", {
    className: "icon-button mobile-menu", type: "button",
    "aria-label": "Open navigation",
    "aria-expanded": "false", "aria-controls": "sidebar"
  }, "☰");
  const backdrop = el("button", {
    className: "sidebar-backdrop", type: "button",
    "aria-label": "Close navigation", tabindex: "-1"
  });
  const scope = user.institute_id === null ? "Platform account" : "Institute #" + user.institute_id;
  const initials = user.username.slice(0, 2).toUpperCase();
  const topbar = el("header", { className: "topbar" },
    el("div", { className: "topbar-left" }, menuButton,
      el("div", { className: "crumb-wrap" },
        el("span", { className: "crumb-parent" }, "WORKSPACE /"), crumb)),
    el("div", { className: "topbar-right" },
      el("span", { className: "account-scope" }, scope),
      el("button", {type:"button",className:"button button-ghost",
        onClick:onSecurity},"Account security"),
      el("span", {
        className: "avatar", title: user.username,
        "aria-label": "Signed in as " + user.username
      }, initials),
      el("button", {
        type: "button", className: "button button-ghost signout",
        onClick: onLogout
      }, "Sign out")));
  const workspace = el("div", { className: "workspace" },
    topbar,
    el("div", { className: "page-head" },
      el("div", {},
        el("p", { className: "eyebrow" }, "MANAGEMENT PORTAL"),
        heading, subtitle)),
    main,
    el("footer", { className: "app-footer" },
      "Institute records are not official government approvals or certifications."));
  const shell = el("div", { className: "app-shell" },
    sidebar, workspace, backdrop,
    el("div", { id: "toast-root", className: "toast-root", "aria-live": "polite" }));

  function syncResponsive() {
    if (!mobile.matches) open = false;
    shell.classList.toggle("sidebar-open", open);
    sidebar.inert = mobile.matches && !open;
    workspace.inert = mobile.matches && open;
    menuButton.setAttribute("aria-expanded", String(open));
    menuButton.setAttribute("aria-label", open ? "Close navigation" : "Open navigation");
    backdrop.tabIndex = open ? 0 : -1;
  }

  function closeNav(restoreFocus = false) {
    if (!open) return;
    open = false;
    syncResponsive();
    if (restoreFocus) menuButton.focus();
  }

  menuButton.addEventListener("click", () => {
    open = !open;
    syncResponsive();
    if (open) (nav.querySelector("a") || backdrop).focus();
  });
  backdrop.addEventListener("click", () => closeNav(true));
  nav.addEventListener("click", event => {
    if (event.target.closest("a")) {
      closeNav();
      if (location.hash === event.target.closest("a").hash) main.focus({ preventScroll: true });
    }
  });

  function onKeydown(event) {
    if (!open) return;
    if (event.key === "Escape") {
      event.preventDefault();
      closeNav(true);
    }
    if (event.key !== "Tab") return;
    const focusables = [...sidebar.querySelectorAll("a:not([tabindex='-1'])"), backdrop];
    const first = focusables[0], last = focusables.at(-1);
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  // Hydrate navigation once: preserve sidebar nodes, scroll and keyboard focus.
  const links = new Map();
  function renderNavigation() {
    if (!links.size) {
      const groups = [...new Set(permitted.map(page => page.group))];
      nav.replaceChildren(...groups.map(group => el("div", { className: "nav-group" },
        el("p", { className: "nav-group-title" }, group),
        ...permitted.filter(page => page.group === group).map(page => {
          const link = el("a", {
            href: "#/" + page.id, className: "nav-link"
          }, el("span", { className: "nav-icon", "aria-hidden": "true" }, page.icon),
            el("span", {}, page.label));
          links.set(page.id, link);
          return link;
        }))));
    }
    for (const [id, link] of links) {
      const active = id === currentId;
      link.classList.toggle("is-active", active);
      if (active) link.setAttribute("aria-current", "page");
      else link.removeAttribute("aria-current");
    }
  }

  document.addEventListener("keydown", onKeydown);
  mobile.addEventListener("change", syncResponsive);
  root.replaceChildren(shell);
  renderNavigation();
  syncResponsive();

  return {
    main,
    setPage(page) {
      currentId = page.id;
      heading.textContent = page.label;
      subtitle.textContent = user.institute_id === null
        ? "Platform workspace" : "Institute #" + user.institute_id;
      crumb.textContent = page.label;
      renderNavigation();
      closeNav();
    },
    showRestricted() {
      currentId = "";
      heading.textContent = "Restricted page";
      subtitle.textContent = "This route is not available to your account.";
      crumb.textContent = "Restricted";
      renderNavigation();
      closeNav();
    },
    focusContent() {
      main.focus({ preventScroll: true });
    },
    dispose() {
      document.removeEventListener("keydown", onKeydown);
      mobile.removeEventListener("change", syncResponsive);
      open = false;
      workspace.inert = false;
    }
  };
}
