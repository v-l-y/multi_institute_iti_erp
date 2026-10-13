import { el, formatValue } from "./dom.js";

/**
 * Activity/audit timeline with explicit event data; never assigns a
 * "verified" or "approved" state itself.
 */
export function activityTimeline(events = [], {
  title = "Record history", emptyMessage = "No history returned by the server."
} = {}) {
  const node = el("section", { className: "erp-activity", "aria-label": title },
    el("h3", {}, title));
  if (!events.length) {
    node.append(el("p", { className: "muted" }, emptyMessage));
    return node;
  }
  node.append(el("ol", { className: "erp-timeline" },
    ...events.map(event => {
      const occurred = event.timestamp || event.occurred_at || "";
      const label = event.label ?? event.action ?? "Recorded event";
      const actor = event.actor ?? event.performed_by ?? "";
      const detail = event.description || event.reference || "";
      return el("li", { className: "erp-timeline-entry" },
        el("strong", {}, label),
        occurred ? el("time", {
          ...(String(occurred).match(/^\d{4}-\d{2}-\d{2}/)
            ? { datetime: String(occurred) } : {})
        }, String(occurred)) : null,
        actor ? el("span", {}, "By: " + formatValue(actor)) : null,
        detail ? el("p", {}, detail) : null);
    })));
  return node;
}
