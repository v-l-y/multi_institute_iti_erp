/* No localStorage, sessionStorage, cookies or URL-based credentials. */
let accessToken = null;
let refreshToken = null;

export function getAccessToken() {
  return accessToken;
}

export function setAccessToken(value) {
  if (typeof value !== "string" || !/^[a-f0-9]{64}$/i.test(value)) {
    throw new TypeError("Invalid session token returned by ERP backend.");
  }
  accessToken = value;
}

export function getRefreshToken() {
  return refreshToken;
}

export function setRefreshToken(value) {
  if (typeof value !== "string" || !/^[a-f0-9]{64}$/i.test(value)) {
    throw new TypeError("Invalid refresh token.");
  }
  refreshToken = value;
}

export function clearAccessToken() {
  accessToken = null;
  refreshToken = null;
}

export function isSignedIn() {
  return accessToken !== null;
}
