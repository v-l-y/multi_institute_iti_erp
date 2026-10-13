import { el, formatValue } from "./dom.js";

export function status(value) {
  const raw = formatValue(value);
  const normalized = raw.toLowerCase().replace(/[^a-z0-9_-]/g, "");
  const good = ["active", "published", "verified", "approved", "completed", "eligible"].includes(normalized);
  const caution = ["draft", "pending", "review", "provisional", "unverified"].includes(normalized);
  return el("span", { className: "status " + (good ? "status-good" : caution ? "status-warn" : "status-neutral") }, raw.replaceAll("_", " "));
}
