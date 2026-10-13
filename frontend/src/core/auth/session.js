/* No localStorage, sessionStorage, cookies or URL-based credentials. */
let accessToken = null;

export function getAccessToken() {
  return accessToken;
}

export function setAccessToken(value) {
  if (typeof value !== "string" || !/^[a-f0-9]{64}$/i.test(value)) {
    throw new TypeError("Invalid session token returned by ERP backend.");
  }
  accessToken = value;
}

export function clearAccessToken() {
  accessToken = null;
}

export function isSignedIn() {
  return accessToken !== null;
}
