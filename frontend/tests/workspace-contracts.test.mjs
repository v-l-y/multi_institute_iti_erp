import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";
import { workspace } from "../src/modules/workspace/contracts.js";
import {
  clearAccessToken, getAccessToken, setAccessToken, isSignedIn
} from "../src/core/auth/session.js";
import { api, ApiError } from "../src/core/http/client.js";

const phpRoutes = readFileSync(new URL("../../backend/routes/api.php", import.meta.url), "utf8");
const registered = new Set(
  [...phpRoutes.matchAll(/\/api\/v1\/[a-z0-9/_-]+/g)].map(match => match[0])
);

// PHP registers the fee register GET routes from a single literal kind list.
const feeRegisterLoop = phpRoutes.match(
  /foreach\s*\(\s*\[([^\]]+)\]\s+as\s+\$kind\s*\)\s*\{\s*\$router->get\('\/api\/v1\/fees\/'\.\$kind/s
);
assert.ok(feeRegisterLoop, "Fee register route declaration could not be inspected.");
for (const match of feeRegisterLoop[1].matchAll(/'([a-z-]+)'/g)) {
  registered.add("/api/v1/fees/" + match[1]);
}

test("every workspace GET and POST endpoint has a PHP API route", () => {
  let checked = 0;
  for (const [name, spec] of Object.entries(workspace)) {
    assert.ok(spec.title && Array.isArray(spec.datasets) && Array.isArray(spec.actions), name);
    for (const entry of [...spec.datasets, ...spec.actions]) {
      assert.ok(entry.path.startsWith("/api/v1/"), name + ": invalid API path");
      assert.ok(registered.has(entry.path),
        name + ": missing PHP route for " + entry.path);
      assert.ok(typeof entry.permission === "string" && entry.permission.length > 0,
        name + ": action/register missing permission");
      checked++;
    }
    for (const dataset of spec.datasets) {
      assert.ok(typeof dataset.key === "string" && dataset.key.length > 0,
        name + ": missing API response collection key");
    }
  }
  assert.ok(checked >= 50, "Unexpectedly few workspace API contracts inspected.");
});

test("financial POST retries have explicit idempotency keys", () => {
  const fees = workspace.fees.actions;
  for (const path of [
    "/api/v1/fees/payments",
    "/api/v1/fees/refunds",
    "/api/v1/fees/cash/transfers"
  ]) {
    const action = fees.find(entry => entry.path === path);
    assert.ok(action, "Financial action missing: " + path);
    assert.equal(action.idempotency, true, "Financial retry protection missing: " + path);
  }
});

test("access tokens remain memory-only and require the exact token shape", () => {
  clearAccessToken();
  assert.equal(isSignedIn(), false);
  assert.equal(getAccessToken(), null);
  for (const invalid of ["", "bad", "a".repeat(63), "z".repeat(64)]) {
    assert.throws(() => setAccessToken(invalid), TypeError);
  }
  const value = "a".repeat(64);
  setAccessToken(value);
  assert.equal(getAccessToken(), value);
  assert.equal(isSignedIn(), true);
  clearAccessToken();
  assert.equal(getAccessToken(), null);
});

test("HTTP client rejects non-ERP and external endpoints before fetch", async () => {
  for (const path of ["https://example.com/api/v1/students", "//example.com/api/v1/students",
    "/other/path", "/api/v2/health"]) {
    await assert.rejects(() => api.get(path),
      error => error instanceof ApiError && error.code === "INVALID_PATH");
  }
});
