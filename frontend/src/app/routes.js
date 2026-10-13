import { routes as dashboard } from "../modules/dashboard/routes.js";
import { routes as institutes } from "../modules/institutes/routes.js";
import { routes as trades } from "../modules/trades/routes.js";
import { routes as calendar } from "../modules/calendar/routes.js";
import { routes as students } from "../modules/students/routes.js";
import { routes as staff } from "../modules/staff/routes.js";
import { routes as admissions } from "../modules/admissions/routes.js";
import { routes as regulatory } from "../modules/regulatory/routes.js";

const all = [...dashboard, ...institutes, ...trades, ...calendar, ...students, ...staff, ...admissions, ...regulatory];
const order = [
  "dashboard", "institute", "itsu", "batches", "calendar",
  "students", "staff", "enrollments", "seat-matrix",
  "platform-institutes", "platform-trades", "platform-sources"
];
// Fail safely if module owners introduce a duplicate, unsupported route or
// a page without an explicit backend permission. These are startup assertions,
// not automatic CI/test execution.
const routeIds = new Set();
for (const page of all) {
  if (routeIds.has(page.id)) throw new Error("Duplicate frontend route: " + page.id);
  routeIds.add(page.id);
  if (typeof page.id !== "string" || typeof page.group !== "string" ||
      typeof page.label !== "string") throw new Error("Invalid frontend route descriptor.");
  if (page.id !== "dashboard") {
    if (!["institute", "platform"].includes(page.scope) ||
        typeof page.permission !== "string" || page.permission.length === 0 ||
        typeof page.path !== "string" || !page.path.startsWith("/api/v1/")) {
      throw new Error("Permission or API contract missing for frontend route: " + page.id);
    }
    if (page.type !== "profile" && (
      typeof page.key !== "string" || !Array.isArray(page.columns)
    )) throw new Error("Register columns missing for route: " + page.id);
  }
}
if (all.length !== order.length) throw new Error("Frontend navigation registration mismatch.");

export const pages = Object.freeze(order.map(id => {
  const page = all.find(item => item.id === id);
  if (!page) throw new Error("Missing frontend route: " + id);
  return Object.freeze(page);
}));

/* UI visibility is not access control: backend checks permissions and tenant. */
export function canAccess(page, user) {
  if (!user || !page) return false;
  if (page.scope === "platform" && user.institute_id !== null) return false;
  if (page.scope === "institute" && (!Number.isInteger(user.institute_id) || user.institute_id < 1)) return false;
  return !page.permission || (Array.isArray(user.permissions) && user.permissions.includes(page.permission));
}

export function currentPage() {
  let id;
  try {
    id = decodeURIComponent(location.hash.slice(2).split("?")[0] || "dashboard");
  } catch {
    return null;
  }
  return pages.find(page => page.id === id) ?? null;
}
