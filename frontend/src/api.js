/* API contract: JSON {success,data,error}; bearer token stays in memory only. */
let token = null;

export class ApiError extends Error {
  constructor(message, code = "NETWORK_ERROR", status = 0) {
    super(message);
    this.name = "ApiError";
    this.code = code;
    this.status = status;
  }
}

export function isSignedIn() {
  return token !== null;
}

export function setAccessToken(value) {
  if (typeof value !== "string" || !/^[a-f0-9]{64}$/i.test(value)) {
    throw new ApiError("Invalid session returned by server.", "INVALID_SESSION");
  }
  token = value;
}

export function clearAccessToken() {
  token = null;
}

async function request(path, { method = "GET", body, signal } = {}) {
  if (typeof path !== "string" || !path.startsWith("/api/v1/") || path.startsWith("//")) {
    throw new ApiError("Invalid API path.", "INVALID_PATH");
  }
  const currentToken = token;
  const headers = { Accept: "application/json" };
  if (body !== undefined) headers["Content-Type"] = "application/json";
  if (currentToken !== null) headers.Authorization = "Bearer " + currentToken;
  let response;
  try {
    response = await fetch(path, {
      method, headers, body: body === undefined ? undefined : JSON.stringify(body),
      cache: "no-store", credentials: "omit", redirect: "error", signal
    });
  } catch (error) {
    if (error?.name === "AbortError") throw error;
    throw new ApiError("Could not reach ERP server. Check backend connection.", "NETWORK_ERROR");
  }

  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError("Server returned an invalid JSON response.", "INVALID_RESPONSE", response.status);
  }
  if (!response.ok || payload?.success !== true) {
    const code = typeof payload?.error?.code === "string" ? payload.error.code : "REQUEST_FAILED";
    const message = typeof payload?.error?.message === "string" ? payload.error.message : "Request failed.";
    if (response.status === 401 && currentToken && currentToken === token) {
      token = null;
      window.dispatchEvent(new Event("erp:session-expired"));
    }
    throw new ApiError(message, code, response.status);
  }
  return payload.data;
}

export const api = {
  get: (path, options) => request(path, options),
  post: (path, body, options = {}) => request(path, { ...options, method: "POST", body }),
  login: (username, password, instituteId) =>
    request("/api/v1/auth/login", { method: "POST", body: {
      username, password, institute_id: instituteId
    } }),
  me: () => request("/api/v1/auth/me")
};
