import { api, ApiError, clearAccessToken, isSignedIn, setAccessToken } from "../core/http/client.js";
import { pages, canAccess, currentPage } from "./routes.js";
import { el, notice } from "../shared/ui/index.js";
import { createAppShell } from "../shared/layouts/app-shell.js";
import { renderLogin, renderPasswordChange } from "../modules/auth/views.js";
import { renderDashboard } from "../modules/dashboard/view.js";
import { renderInstituteProfile } from "../modules/institutes/profile.js";
import { createRegistryView } from "../modules/registry/register-view.js";

const root = document.getElementById("app");
if (!root) throw new Error("ERP application root not found.");

let user = null;
let shell = null;
let activeView = null;

function disposeView() {
  activeView?.dispose();
  activeView = null;
}

function resetToLogin(message = "") {
  disposeView();
  clearAccessToken();
  user = null;
  shell = null;
  renderLogin(root, { message, onLogin: login });
}

async function login(username, password, instituteId) {
  try {
    const session = await api.login(username, password, instituteId);
    setAccessToken(session.access_token);
    const profile = await api.me();
    if (!profile || !Array.isArray(profile.permissions) || !Array.isArray(profile.roles) ||
        typeof profile.username !== "string" ||
        !(profile.institute_id === null || (Number.isSafeInteger(profile.institute_id) && profile.institute_id > 0))) {
      throw new ApiError("Unexpected account profile returned by ERP backend.", "INVALID_PROFILE");
    }
    user = profile;
    if (profile.must_change_password) {
      renderPasswordChange(root, { onChangePassword: changePassword, onLogout: logout });
    } else {
      shell = createAppShell(root, user, logout, pages, canAccess);
      renderRoute();
    }
  } catch (error) {
    user = null;
    clearAccessToken();
    throw error;
  }
}

async function changePassword(values) {
  await api.post("/api/v1/auth/change-password", values);
  resetToLogin("Password updated. Sign in again with your new password.");
}

async function logout() {
  let serverRevocationFailed = false;
  if (isSignedIn()) {
    try {
      await api.post("/api/v1/auth/logout", {});
    } catch {
      serverRevocationFailed = true;
    }
  }
  resetToLogin(serverRevocationFailed
    ? "Local session cleared, but server logout could not be confirmed. Contact an administrator if needed."
    : "You have signed out.");
}

function renderRoute() {
  if (!user || !shell) return;
  disposeView();
  const page = currentPage();
  if (!page || !canAccess(page, user)) {
    shell.showRestricted();
    shell.main.replaceChildren(
      notice("Access not available",
        "This page does not exist or your account has not been granted the required permission.",
        "error"),
      el("a", { href: "#/dashboard", className: "button button-secondary" }, "Back to overview")
    );
    return;
  }

  shell.setPage(page);
  if (page.id === "dashboard") {
    renderDashboard(shell.main, user, pages.filter(route => route.id !== "dashboard" && canAccess(route, user)));
    return;
  }

  if (page.type === "profile") {
    const controller = new AbortController();
    activeView = { controller, dispose: () => controller.abort() };
    void renderInstituteProfile(page, shell.main, controller,
      current => activeView?.controller === current && !current.signal.aborted);
    return;
  }

  activeView = createRegistryView(page, shell.main, user);
  void activeView.mount();
}

window.addEventListener("erp:session-expired",
  () => resetToLogin("Your session expired. Sign in again."));
window.addEventListener("hashchange", () => { if (user && shell) renderRoute(); });

resetToLogin();
