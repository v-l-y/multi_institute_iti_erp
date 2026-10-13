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
