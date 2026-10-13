import { button } from "./buttons.js";

/** UI visibility only. Backend must repeat permission and tenant checks. */
export function hasPermission(user, permission, scope = "institute") {
  if (!user || !Array.isArray(user.permissions) || typeof permission !== "string" || !permission) {
    return false;
  }
  const scoped = scope === "platform"
    ? user.institute_id === null
    : scope === "institute"
      ? Number.isSafeInteger(user.institute_id) && user.institute_id > 0
      : false;
  return scoped && user.permissions.includes(permission);
}

export function permissionButton(user, permission, label, options = {}) {
  const { scope = "institute", ...rest } = options;
  return hasPermission(user, permission, scope) ? button(label, rest) : null;
}
