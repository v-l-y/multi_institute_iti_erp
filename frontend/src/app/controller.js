import { api, ApiError, clearAccessToken, isSignedIn, setAccessToken } from "../core/http/client.js";
import { pages, canAccess, currentPage } from "./routes.js";
import { el, field, dataTable, emptyState, loadingState, notice, toast, formatValue } from "../shared/ui/index.js";

const root = document.getElementById("app");
let user = null;
let viewRequest = null;
let shell = null;
let sideNav = null;
let main = null;
let heading = null;
let subtitle = null;
let currentId = "";
let cursors = [null];
let cursorIndex = 0;

function cancelRequest() {
  if (viewRequest) viewRequest.abort();
  viewRequest = null;
}

function hideSensitiveWorkspace(message = "") {
  cancelRequest();
  user = null;
  clearAccessToken();
  shell = null;
  main = null;
  heading = null;
  currentId = "";
  cursors = [null];
  cursorIndex = 0;
  showLogin(message);
}

window.addEventListener("erp:session-expired", () =>
  hideSensitiveWorkspace("Your session expired. Sign in again."));
window.addEventListener("hashchange", () => { if (user) renderRoute(); });

async function logout() {
  let failed = false;
  if (isSignedIn()) {
    try {
      await api.post("/api/v1/auth/logout", {});
    } catch {
      failed = true;
    }
  }
  hideSensitiveWorkspace(failed
    ? "Local session cleared, but server logout could not be confirmed. Contact your administrator if needed."
    : "You have signed out.");
}

function sectionTitle(eyebrow, title, description) {
  return el("div", { className: "section-heading" },
    el("p", { className: "eyebrow" }, eyebrow),
    el("h1", {}, title),
    el("p", { className: "muted" }, description));
}

function showLogin(message = "") {
  const error = el("div", { className: "form-feedback", "aria-live": "assertive" });
  if (message) error.append(notice("Sign-in notice", message));
  const username = el("input", { id: "username", name: "username", autocomplete: "username", required: true, maxlength: 120, placeholder: "Your username" });
  const password = el("input", { id: "password", name: "password", type: "password", autocomplete: "current-password", required: true, placeholder: "Your password" });
  const institute = el("input", { id: "institute_id", name: "institute_id", type: "number", min: 1, step: 1, inputmode: "numeric", placeholder: "Leave blank for platform account" });
  const button = el("button", { className: "button button-primary", type: "submit" }, "Sign in");
  const form = el("form", { className: "login-form" },
    el("label", { className: "field" }, el("span", {}, "Username"), username),
    el("label", { className: "field" }, el("span", {}, "Password"), password),
    el("label", { className: "field" }, el("span", {}, "Institute ID"), institute),
    el("p", { className: "field-help" }, "Enter the institute ID assigned to your account. Platform developers leave this field blank."),
    error, button);
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const raw = institute.value.trim();
    const instituteId = raw === "" ? null : Number(raw);
    if (raw !== "" && (!Number.isSafeInteger(instituteId) || instituteId <= 0)) {
      error.replaceChildren(notice("Invalid institute ID", "Enter a positive integer.", "error"));
      return;
    }
    button.disabled = true;
    button.textContent = "Signing in…";
    error.replaceChildren();
    try {
      const result = await api.login(username.value.trim(), password.value, instituteId);
      setAccessToken(result.access_token);
      const profile = await api.me();
      if (!profile || !Array.isArray(profile.permissions) || !Array.isArray(profile.roles) ||
          typeof profile.username !== "string" ||
          !(profile.institute_id === null || Number.isInteger(profile.institute_id))) {
        throw new ApiError("Unexpected account profile returned by backend.", "INVALID_PROFILE");
      }
      user = profile;
      password.value = "";
      if (profile.must_change_password) showPasswordChange();
      else { mountShell(); renderRoute(); }
    } catch (err) {
      clearAccessToken();
      user = null;
      error.replaceChildren(notice("Sign in failed", err.message || "Unable to sign in.", "error"));
      password.value = "";
      password.focus();
    } finally {
      button.disabled = false;
      button.textContent = "Sign in";
    }
  });

  root.replaceChildren(el("div", { className: "auth-layout" },
    el("div", { className: "auth-story" },
      el("div", { className: "brand brand-large" }, el("span", { className: "brand-logo", "aria-hidden": "true" }, "I"), el("span", {}, "ITI ERP")),
      el("span", { className: "scope-pill" }, "BIHAR · PRIVATE ITIs"),
      el("h1", {}, "One workspace. Every institute record in its place."),
      el("p", {}, "Institute administration, CTS trades, training calendars and academic registers—connected to your existing ERP API."),
      el("div", { className: "story-footer" }, "Institute management only · Regulatory decisions stay with official authorities")),
    el("main", { className: "auth-main", id: "main-content" },
      el("div", { className: "auth-card" },
        el("span", { className: "small-overline" }, "SECURE ACCOUNT ACCESS"),
        el("h2", {}, "Welcome back"),
        el("p", { className: "muted" }, "Sign in with your institute or platform account."),
        form,
        el("p", { className: "security-note" }, "Sessions are kept in browser memory, not persistent storage. Refreshing the page requires a new sign-in.")))));
  username.focus();
}

function showPasswordChange() {
  cancelRequest();
  const feedback = el("div", { "aria-live": "assertive" });
  const old = field("current_password", "Current password", { type: "password", maxLength: 72 });
  const next = field("new_password", "New password (12–72 characters)", { type: "password", maxLength: 72 });
  const confirm = field("confirm_password", "Confirm new password", { type: "password", maxLength: 72 });
  const submit = el("button", { className: "button button-primary", type: "submit" }, "Update password");
  const form = el("form", { className: "stack-form" }, old, next, confirm, feedback, submit);
  form.addEventListener("submit", async event => {
    event.preventDefault();
    const values = Object.fromEntries(new FormData(form));
    if (values.new_password !== values.confirm_password || values.new_password.length < 12 || values.new_password.length > 72) {
      feedback.replaceChildren(notice("Check password", "New passwords must match and contain 12–72 characters.", "error"));
      return;
    }
    submit.disabled = true;
    try {
      await api.post("/api/v1/auth/change-password", {
        current_password: values.current_password,
        new_password: values.new_password
      });
      hideSensitiveWorkspace("Password updated. Sign in again with your new password.");
    } catch (err) {
      feedback.replaceChildren(notice("Could not update password", err.message, "error"));
      submit.disabled = false;
    }
  });
  root.replaceChildren(el("main", { className: "single-screen", id: "main-content" },
    el("div", { className: "auth-card" },
      el("p", { className: "eyebrow" }, "Account security"),
      el("h1", {}, "Change your password"),
      el("p", { className: "muted" }, "Your institute requires a password change before you can access registers."),
      form, el("button", { className: "button button-ghost", type: "button", onClick: logout }, "Sign out"))));
}

function permittedPages() {
  return pages.filter(page => canAccess(page, user));
}

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
      el("button", { type: "button", className: "button button-ghost signout", onClick: logout }, "Sign out")));
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

function pageHeader(title, description, action = null) {
  const head = el("div", { className: "content-heading" },
    el("div", {}, el("h2", {}, title), el("p", { className: "muted" }, description)));
  if (action) head.append(action);
  return head;
}

function showOverview() {
  const available = permittedPages().filter(page => page.id !== "dashboard");
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

function ensureRequest() {
  cancelRequest();
  viewRequest = new AbortController();
  return viewRequest;
}

async function showInstituteProfile(page) {
  const controller = ensureRequest();
  main.replaceChildren(pageHeader("Institute identity", "Official source references and local institute profile are separate."),
    loadingState());
  try {
    const data = await api.get(page.path, { signal: controller.signal });
    if (viewRequest !== controller) return;
    const fields = [
      ["name", "Institute name"], ["mis_code", "MIS code"], ["district_name", "District"],
      ["management_type", "Management type"], ["contact_phone", "Contact phone"],
      ["contact_email", "Contact email"], ["id", "Institute ID"]
    ];
    main.replaceChildren(pageHeader("Institute identity", "Details provided by the authenticated institute API."),
      el("section", { className: "surface profile-card" },
        el("div", { className: "section-heading" }, el("p", { className: "eyebrow" }, "REGISTERED INSTITUTE"),
          el("h2", {}, formatValue(data.name))),
        el("dl", { className: "detail-grid" },
          ...fields.map(([key, label]) => el("div", { className: "detail-item" },
            el("dt", {}, label), el("dd", {}, formatValue(data[key]))))));
  } catch (err) {
    if (err.name === "AbortError" || viewRequest !== controller) return;
    main.replaceChildren(pageHeader("Institute identity", "The profile could not be loaded."),
      notice("Unable to load institute", err.message, "error"));
  }
}

function searchRows(rows, term) {
  const q = term.trim().toLocaleLowerCase();
  if (!q) return rows;
  return rows.filter(row => Object.values(row).some(value =>
    value !== null && value !== undefined && String(value).toLocaleLowerCase().includes(q)));
}

function nextPage(cursor) {
  if (!Number.isSafeInteger(Number(cursor)) || Number(cursor) <= 0) return;
  cursors = cursors.slice(0, cursorIndex + 1);
  cursors.push(Number(cursor));
  cursorIndex++;
}

function creationConfig(id) {
  if (id === "staff" && user.permissions.includes("staff.manage"))
    return {
      title: "Add staff record", path: "/api/v1/staff",
      fields: [
        ["employee_code", "Employee code", { maxLength: 50 }],
        ["full_name", "Full name", { maxLength: 190 }],
        ["staff_category", "Staff category", { options: [["teaching", "Teaching"], ["non_teaching", "Non-teaching"]] }],
        ["designation", "Designation", { maxLength: 120 }],
        ["joined_on", "Joined on", { type: "date" }]
      ]
    };
  if (id === "students" && user.permissions.includes("student.manage"))
    return {
      title: "Register student identity", path: "/api/v1/students",
      fields: [
        ["local_student_code", "Local student code", { maxLength: 60 }],
        ["full_name", "Full name", { maxLength: 190 }],
        ["date_of_birth", "Date of birth", { type: "date" }],
        ["gender", "Gender (as recorded)", { maxLength: 30 }],
        ["address", "Address", { maxLength: 16000 }],
        ["identity_mode", "Identity evidence mode", { options: [["non_aadhaar", "Non-Aadhaar"], ["aadhaar", "Aadhaar"]] }],
        ["identity_evidence_reference", "Identity evidence reference", { maxLength: 190 }]
      ]
    };
  return null;
}

function openCreationDialog(page) {
  const spec = creationConfig(page.id);
  if (!spec) return;
  const dialog = el("dialog", { className: "form-dialog", "aria-labelledby": "dialog-title" });
  const feedback = el("div", { "aria-live": "assertive" });
  const submit = el("button", { type: "submit", className: "button button-primary" }, "Save record");
  const cancel = el("button", { type: "button", className: "button button-ghost",
    onClick: () => dialog.close() }, "Cancel");
  const form = el("form", { className: "stack-form" },
    ...spec.fields.map(([key, label, options]) => field(key, label, options)),
    feedback,
    el("div", { className: "form-actions" }, cancel, submit));
  form.addEventListener("submit", async event => {
    event.preventDefault();
    submit.disabled = true;
    feedback.replaceChildren();
    try {
      const body = Object.fromEntries(new FormData(form));
      await api.post(spec.path, body);
      dialog.close();
      toast("Record created successfully.");
      loadRegister(page);
    } catch (err) {
      feedback.replaceChildren(notice("Could not save", err.message, "error"));
    } finally {
      submit.disabled = false;
    }
  });
  dialog.append(el("div", { className: "dialog-heading" },
    el("p", { className: "eyebrow" }, "INSTITUTE REGISTER"),
    el("h2", { id: "dialog-title" }, spec.title),
    el("p", { className: "muted" }, "Save a local record only. This does not create official government registration.")),
    form);
  document.body.append(dialog);
  dialog.addEventListener("close", () => dialog.remove(), { once: true });
  dialog.showModal();
}

async function loadRegister(page) {
  const controller = ensureRequest();
  const refresh = el("button", { type: "button", className: "button button-secondary",
    onClick: () => loadRegister(page) }, "↻ Refresh");
  const tools = el("div", { className: "heading-actions" }, refresh);
  if (creationConfig(page.id)) {
    tools.prepend(el("button", { type: "button", className: "button button-primary",
      onClick: () => openCreationDialog(page) }, "+ Add record"));
  }
  const holder = el("div", { className: "surface register-surface" }, loadingState());
  main.replaceChildren(
    pageHeader(page.label, "Source-backed records from the authenticated ERP API.", tools),
    holder
  );
  const after = cursors[cursorIndex];
  const path = page.path + "?page_size=25" + (after ? "&after_id=" + encodeURIComponent(after) : "");
  try {
    const payload = await api.get(path, { signal: controller.signal });
    if (viewRequest !== controller) return;
    if (!payload || !Array.isArray(payload[page.key])) {
      throw new ApiError("The server returned an unexpected register format.", "INVALID_RESPONSE");
    }
    const rows = payload[page.key];
    const search = el("input", { type: "search", className: "search-input",
      "aria-label": "Search current page", placeholder: "Search this page…" });
    const dataArea = el("div");
    const count = el("span", { className: "muted" });
    const renderFiltered = () => {
      const filtered = searchRows(rows, search.value);
      count.textContent = filtered.length + " of " + rows.length + " records on this page";
      dataArea.replaceChildren(filtered.length
        ? dataTable(filtered, page.columns)
        : emptyState(rows.length ? "No matching records" : "No records on this page",
          rows.length ? "Try a different search. Search covers only loaded page records." :
            "No data has been registered for this view."));
    };
    search.addEventListener("input", renderFiltered);
    const previous = el("button", { type: "button", className: "button button-secondary",
      disabled: cursorIndex === 0,
      onClick: () => { if (cursorIndex > 0) { cursorIndex--; loadRegister(page); } } }, "← Previous");
    const next = el("button", { type: "button", className: "button button-secondary",
      disabled: payload.next_after_id === null || payload.next_after_id === undefined,
      onClick: () => { nextPage(payload.next_after_id); loadRegister(page); } }, "Next →");
    const toolsBar = el("div", { className: "register-tools" }, search,
      el("span", { className: "page-hint" }, "Page " + (cursorIndex + 1) + " · 25 per page"));
    const pagination = el("div", { className: "register-bottom" }, count,
      el("div", { className: "pager" }, previous, next));
    holder.replaceChildren(toolsBar, dataArea, pagination);
    renderFiltered();
  } catch (err) {
    if (err.name === "AbortError" || viewRequest !== controller) return;
    holder.replaceChildren(notice("Unable to load records", err.message, "error"),
      el("button", { className: "button button-secondary", type: "button",
        onClick: () => loadRegister(page) }, "Try again"));
  }
}

function renderRoute() {
  if (!user || !shell) return;
  const page = currentPage();
  const permitted = page && canAccess(page, user);
  cancelRequest();
  if (!page || !permitted) {
    heading.textContent = "Restricted page";
    subtitle.textContent = "This route is not available to your account.";
    shell.updateBreadcrumb("Restricted");
    main.replaceChildren(notice("Access not available",
      "This page does not exist or your current account does not have explicit permission.", "error"),
      el("a", { href: "#/dashboard", className: "button button-secondary" }, "Back to overview"));
    return;
  }
  if (page.id !== currentId) {
    cursors = [null];
    cursorIndex = 0;
  }
  currentId = page.id;
  heading.textContent = page.label;
  subtitle.textContent = user.institute_id === null ? "Platform workspace" : "Institute #" + user.institute_id;
  shell.updateBreadcrumb(page.label);
  renderNavigation();
  if (page.id === "dashboard") {
    showOverview();
  } else if (page.type === "profile") {
    showInstituteProfile(page);
  } else {
    loadRegister(page);
  }
}

showLogin();
