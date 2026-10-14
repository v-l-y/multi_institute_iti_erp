import {
  getAccessToken, setAccessToken, getRefreshToken, setRefreshToken,
  clearAccessToken, isSignedIn
} from "../auth/session.js";

export { setAccessToken, setRefreshToken, clearAccessToken, isSignedIn };

export class ApiError extends Error {
  constructor(message, code = "NETWORK_ERROR", status = 0) {
    super(message);
    this.name = "ApiError";
    this.code = code;
    this.status = status;
  }
}

let refreshFlight = null;
/** Only one refresh may rotate a token, even with concurrent API calls. */
async function refreshSession() {
  if (refreshFlight) return refreshFlight;
  const original = getRefreshToken();
  if (!original) throw new ApiError("Sign in again.", "UNAUTHORIZED", 401);
  const flight = (async () => {
    let response;
    try {
      response = await fetch("/api/v1/auth/refresh", {
        method: "POST", cache: "no-store", credentials: "omit", redirect: "error",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ refresh_token: original })
      });
    } catch {
      throw new ApiError("Could not refresh ERP session.", "NETWORK_ERROR");
    }
    let payload;
    try { payload = await response.json(); } catch {
      throw new ApiError("Invalid refresh response.", "INVALID_RESPONSE");
    }
    if (!response.ok || payload?.success !== true) {
      throw new ApiError("Session refresh denied.", "UNAUTHORIZED", response.status);
    }
    if (getRefreshToken() !== original) {
      throw new ApiError("Session changed during refresh.", "UNAUTHORIZED", 401);
    }
    setAccessToken(payload.data.access_token);
    setRefreshToken(payload.data.refresh_token);
    return payload.data;
  })();
  refreshFlight = flight;
  try { return await flight; }
  finally { if (refreshFlight === flight) refreshFlight = null; }
}

/* All requests are same-origin and API paths are explicit. */
async function request(path, { method = "GET", body, signal, retryRefresh = true } = {}) {
  if (typeof path !== "string" || !path.startsWith("/api/v1/") || path.startsWith("//")) {
    throw new ApiError("Invalid API path.", "INVALID_PATH");
  }
  const currentToken = getAccessToken();
  const headers = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (currentToken !== null) headers.Authorization = "Bearer " + currentToken;
  let response;
  try {
    response = await fetch(path, {
      method, headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      cache: "no-store", credentials: "omit", redirect: "error", signal
    });
  } catch (error) {
    if (error?.name === "AbortError") throw error;
    throw new ApiError("Could not reach ERP server. Check backend connection.");
  }
  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError("Server returned an invalid JSON response.", "INVALID_RESPONSE", response.status);
  }
  if (response.status === 401 && currentToken && retryRefresh &&
      getRefreshToken() && !["/api/v1/auth/login","/api/v1/auth/refresh",
        "/api/v1/auth/forgot-password","/api/v1/auth/reset-password"].includes(path)) {
    try {
      // Another request may already have completed rotation.
      if (currentToken === getAccessToken()) await refreshSession();
      return request(path, { method, body, signal, retryRefresh: false });
    } catch {
      if (currentToken === getAccessToken()) {
        clearAccessToken();
        window.dispatchEvent(new Event("erp:session-expired"));
      }
      throw new ApiError("Session expired. Sign in again.", "UNAUTHORIZED", 401);
    }
  }
  if (!response.ok || payload?.success !== true) {
    const code = typeof payload?.error?.code === "string" ? payload.error.code : "REQUEST_FAILED";
    const message = typeof payload?.error?.message === "string" ? payload.error.message : "Request failed.";
    if (response.status === 401 && currentToken && currentToken === getAccessToken()) {
      clearAccessToken();
      window.dispatchEvent(new Event("erp:session-expired"));
    }
    throw new ApiError(message, code, response.status);
  }
  return payload.data;
}


/** Authenticated binary GET for institute documents; bearer stays in the header. */
async function download(path, { signal, acceptedTypes = ["application/pdf", "image/png", "image/jpeg"],
  retryRefresh = true } = {}) {
  if (typeof path !== "string" || !path.startsWith("/api/v1/") || path.startsWith("//")) {
    throw new ApiError("Invalid download path.", "INVALID_PATH");
  }
  const token = getAccessToken();
  if (!token) throw new ApiError("Sign in before downloading documents.", "UNAUTHORIZED", 401);
  let response;
  try {
    response = await fetch(path, {
      headers: { Authorization: "Bearer " + token, Accept: "application/octet-stream" },
      cache: "no-store", credentials: "omit", redirect: "error", signal
    });
  } catch (error) {
    if (error?.name === "AbortError") throw error;
    throw new ApiError("Could not download this document.");
  }
  if (response.status === 401 && retryRefresh && getRefreshToken()) {
    try {
      if (token === getAccessToken()) await refreshSession();
      return download(path, { signal, acceptedTypes, retryRefresh: false });
    } catch {
      if (token === getAccessToken()) {
        clearAccessToken();
        window.dispatchEvent(new Event("erp:session-expired"));
      }
      throw new ApiError("Session expired. Sign in again.", "UNAUTHORIZED", 401);
    }
  }
  if (!response.ok) {
    let error;
    try { error = await response.json(); } catch {}
    if (response.status === 401 && token === getAccessToken()) {
      clearAccessToken();
      window.dispatchEvent(new Event("erp:session-expired"));
    }
    throw new ApiError(error?.error?.message || "Document download denied.",
      error?.error?.code || "DOWNLOAD_FAILED", response.status);
  }
  const mime = response.headers.get("content-type")?.split(";")[0].trim();
  if (!acceptedTypes.includes(mime)) {
    throw new ApiError("Unexpected document content type.", "INVALID_DOCUMENT");
  }
  const blob = await response.blob();
  if (blob.size > 5242880 && mime !== "text/csv") throw new ApiError("Document exceeds download limit.", "INVALID_DOCUMENT");
  if (mime === "text/csv" && blob.size > 10485760) throw new ApiError("Report exceeds download limit.", "INVALID_RESPONSE");
  return blob;
}

export const api = Object.freeze({
  get: (path, options) => request(path, options),
  download,
  post: (path, body, options = {}) => request(path, { ...options, method: "POST", body }),
  login: (username, password, instituteId, otpCode = "", recoveryCode = "") =>
    request("/api/v1/auth/login", {
      method: "POST",
      body: { username, password, institute_id: instituteId,
        ...(otpCode ? { otp_code: otpCode } : {}),
        ...(recoveryCode ? { recovery_code: recoveryCode } : {}) }
    }),
  me: () => request("/api/v1/auth/me"),
  refresh: refreshSession
});
