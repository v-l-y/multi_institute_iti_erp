import { api, ApiError, clearAccessToken, isSignedIn, setAccessToken, setRefreshToken } from "../core/http/client.js";
import { pages, canAccess, currentPage } from "./routes.js";
import { el, notice, renderSafely } from "../shared/ui/index.js";
import { createAppShell } from "../shared/layouts/app-shell.js";
import { renderLogin, renderPasswordChange, renderPasswordRecovery } from "../modules/auth/views.js";
import { openSecurityCenter } from "../modules/auth/security-center.js";
import { renderDashboard } from "../modules/dashboard/view.js";
import { renderInstituteProfile } from "../modules/institutes/profile.js";
import { createRegistryView } from "../modules/registry/register-view.js";
import { createWorkspaceView } from "../modules/workspace/view.js";
import { sessionUiState } from "../core/hydration/session-state.js";

const root = document.getElementById("app");
if (!root) throw new Error("ERP application root not found.");

let user = null;
let shell = null;
let activeView = null;
let authEpoch = 0;
let securityDialog = null;
let currentRouteId = null;

function disposeView() {
  if (currentRouteId && typeof activeView?.snapshot === "function") {
    sessionUiState.remember(currentRouteId, activeView.snapshot());
  }
  activeView?.dispose();
  activeView = null;
  currentRouteId = null;
}

function resetToLogin(message = "") {
  authEpoch++;
  disposeView();
  if(securityDialog?.open)securityDialog.close();
  securityDialog=null;
  sessionUiState.clear();
  shell?.dispose();
  clearAccessToken();
  user = null;
  shell = null;
  showLogin(message);
}

function showLogin(message = "") {
  renderLogin(root, {message,onLogin:login,onForgot:showRecovery});
}
function showRecovery() {
  renderPasswordRecovery(root, {
    onRequest: values => api.post("/api/v1/auth/forgot-password",values),
    onReset: values => api.post("/api/v1/auth/reset-password",values),
    onBack: () => showLogin()
  });
}
function showSecurity() {
  if(securityDialog?.open)securityDialog.close();
  securityDialog=openSecurityCenter();
  securityDialog.addEventListener("close",()=>{
    securityDialog=null;
  },{once:true});
}

async function login(username, password, instituteId, otpCode = "", recoveryCode = "") {
  const attempt = ++authEpoch;
  try {
    const session = await api.login(username, password, instituteId, otpCode, recoveryCode);
    if (attempt !== authEpoch) return;
    setAccessToken(session.access_token);
    setRefreshToken(session.refresh_token);
    const profile = await api.me();
    if (attempt !== authEpoch) return;
    if (!profile || !Array.isArray(profile.permissions) || !Array.isArray(profile.roles) ||
        typeof profile.username !== "string" ||
        !(profile.institute_id === null || (Number.isSafeInteger(profile.institute_id) && profile.institute_id > 0))) {
      throw new ApiError("Unexpected account profile returned by ERP backend.", "INVALID_PROFILE");
    }
    user = profile;
    if (profile.must_change_password) {
      renderPasswordChange(root, { onChangePassword: changePassword, onLogout: logout });
    } else {
      shell = createAppShell(root, user, logout, pages, canAccess, showSecurity);
      renderRoute();
    }
  } catch (error) {
    if (attempt === authEpoch) {
      user = null;
      clearAccessToken();
    }
    throw error;
  }
}

async function changePassword(values) {
  const attempt = authEpoch;
  await api.post("/api/v1/auth/change-password", values);
  if (attempt === authEpoch)
    resetToLogin("Password updated. Sign in again with your new password.");
}

async function logout() {
  // Start the authenticated revocation request before clearing in-memory token.
  const revocation = isSignedIn() ? api.post("/api/v1/auth/logout", {}) : Promise.resolve();
  resetToLogin("Signed out locally. Confirming server session revocation…");
  const attempt = authEpoch;
  try {
    await revocation;
    if (attempt === authEpoch) resetToLogin("You have signed out.");
  } catch {
    if (attempt === authEpoch) resetToLogin(
      "Local session cleared, but server logout could not be confirmed. Contact an administrator if needed.");
  }
}

function renderRoute() {
  if (!user || !shell) return;
  const page = currentPage();
  // A hash query edit must not rebuild the same route and lose input focus.
  if (page && activeView && currentRouteId === page.id) return;
  disposeView();
  if (!page || !canAccess(page, user)) {
    shell.showRestricted();
    shell.main.replaceChildren(
      notice("Access not available",
        "This page does not exist or your account has not been granted the required permission.",
        "error"),
      el("a", { href: "#/dashboard", className: "button button-secondary" }, "Back to overview")
    );
    shell.focusContent();
    return;
  }

  shell.setPage(page);
  currentRouteId = page.id;
  shell.focusContent();
  if (page.id === "dashboard") {
    const displayed = renderSafely(shell.main,
      () => renderDashboard(shell.main, user,
        pages.filter(route => route.id !== "dashboard" && canAccess(route, user))),
      { onRetry: renderRoute });
    shell.main.dataset.hydration = displayed ? "ready" : "error";
    shell.main.setAttribute("aria-busy", "false");
    return;
  }

  if (page.type === "profile") {
    const controller = new AbortController();
    activeView = { controller, dispose: () => controller.abort() };
    void renderInstituteProfile(page, shell.main, controller,
      current => activeView?.controller === current && !current.signal.aborted);
    return;
  }

  const initialState = sessionUiState.restore(page.id);
  activeView = page.type === "workspace"
    ? createWorkspaceView(page, shell.main, user, { initialState })
    : createRegistryView(page, shell.main, user, { initialState });
  void activeView.mount();
}

window.addEventListener("erp:session-expired",
  () => resetToLogin("Your session expired. Sign in again."));
window.addEventListener("hashchange", () => { if (user && shell) renderRoute(); });

resetToLogin();
