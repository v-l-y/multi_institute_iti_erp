import { staffCreateConfig } from "../staff/create-config.js";
import { studentCreateConfig } from "../students/create-config.js";

export function creationConfig(id, user) {
  if (id === "staff") return staffCreateConfig(user);
  if (id === "students") return studentCreateConfig(user);
  return null;
}
