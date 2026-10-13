import { el, formatValue } from "./dom.js";
import { emptyState } from "./feedback.js";

/**
 * Read-only attendance register. Multiple rows per learner/date are displayed
 * as ambiguous, never silently promoted to an effective record.
 * The authorized backend must resolve corrections/revisions.
 */
export function attendanceMatrix({
  people = [], dates = [], records = [],
  personId = "id", personLabel = "full_name",
  recordPersonId = "person_id", recordDate = "date", recordStatus = "status"
} = {}) {
  if (!people.length || !dates.length) return emptyState("No attendance data",
    "Choose a record set with actual trainees and dates.");
  const index = new Map();
  for (const record of records) {
    const key = JSON.stringify([String(record[recordPersonId]), String(record[recordDate])]);
    const group = index.get(key) || [];
    group.push(record);
    index.set(key, group);
  }
  const header = el("tr", {}, el("th", { scope: "col" }, "Trainee / Staff"),
    ...dates.map(date => el("th", { scope: "col" }, date)));
  return el("div", { className: "table-scroll", role: "region",
    "aria-label": "Attendance register", tabindex: "0" },
    el("table", { className: "data-table erp-attendance" },
      el("caption", { className: "visually-hidden" }, "Supplied attendance data"),
      el("thead", {}, header),
      el("tbody", {},
        ...people.map(person => el("tr", {},
          el("th", { scope: "row" }, formatValue(person[personLabel])),
          ...dates.map(date => {
            const matches = index.get(JSON.stringify([String(person[personId]), String(date)])) || [];
            if (matches.length > 1) {
              return el("td", {}, el("span", { className: "status status-warn",
                title: matches.length + " attendance entries require source reconciliation"
              }, "Multiple records — review"));
            }
            return el("td", {}, matches.length ? formatValue(matches[0][recordStatus]) : "—");
          })))));
}
