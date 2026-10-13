import { el, formatValue } from "./dom.js";
import { emptyState } from "./feedback.js";

/**
 * Read-only attendance register. Statuses/dates come from backend records.
 * Does not compute eligibility, attendance percentage or sanctioned hours.
 */
export function attendanceMatrix({
  people = [], dates = [], records = [],
  personId = "id", personLabel = "full_name",
  recordPersonId = "person_id", recordDate = "date", recordStatus = "status"
} = {}) {
  if (!people.length || !dates.length) return emptyState("No attendance data",
    "Choose a record set with actual trainees and dates.");
  const index = new Map(records.map(record =>
    [String(record[recordPersonId]) + "|" + record[recordDate], record]));
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
            const entry = index.get(String(person[personId]) + "|" + date);
            return el("td", {}, entry ? formatValue(entry[recordStatus]) : "—");
          })))));
}
