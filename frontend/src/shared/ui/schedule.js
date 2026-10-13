import { el, formatValue } from "./dom.js";
import { emptyState } from "./feedback.js";

/**
 * Timetable UI only. Caller provides actual day/slot identities and entries.
 * No inferred institute shift timing, holidays or training-hour rules.
 */
export function timetableGrid({ days = [], slots = [], entries = [],
  getCell = (item, day, slot) => item.day === day && item.slot === slot,
  cellLabel = item => item.label ?? item.subject ?? formatValue(item.id)
} = {}) {
  if (!days.length || !slots.length) return emptyState("No timetable",
    "No verified timetable days and slots have been supplied.");
  const head = el("tr", {}, el("th", { scope: "col" }, "Session"),
    ...days.map(day => el("th", { scope: "col" }, day)));
  const rows = slots.map(slot => el("tr", {},
    el("th", { scope: "row" }, slot),
    ...days.map(day => {
      const matches = entries.filter(item => getCell(item, day, slot));
      return el("td", { className: "erp-schedule-cell" },
        ...matches.map(item => el("div", { className: "erp-schedule-entry" }, cellLabel(item))));
    })));
  return el("div", { className: "table-scroll", role: "region",
    "aria-label": "Training timetable", tabindex: "0" },
    el("table", { className: "data-table erp-schedule-table" },
      el("caption", { className: "visually-hidden" }, "Supplied training timetable"),
      el("thead", {}, head), el("tbody", {}, ...rows)));
}

/** Calendar events are displayed by explicit ISO date. No generated milestones. */
export function monthCalendar({ year, month, entries = [],
  dateKey = "date", labelKey = "label" } = {}) {
  if (!Number.isInteger(year) || !Number.isInteger(month) || month < 1 || month > 12) {
    throw new TypeError("Calendar requires year and month (1-12)");
  }
  const startDay = new Date(Date.UTC(year, month - 1, 1)).getUTCDay();
  const days = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const datePrefix = String(year).padStart(4, "0") + "-" + String(month).padStart(2, "0") + "-";
  const dayNames = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
  const cells = Array.from({ length: Math.ceil((days + startDay) / 7) * 7 }, (_, i) => {
    const dateNo = i - startDay + 1;
    if (dateNo < 1 || dateNo > days) return el("td", { className: "calendar-outside" });
    const iso = datePrefix + String(dateNo).padStart(2, "0");
    const events = entries.filter(row => row[dateKey] === iso);
    return el("td", { className: "erp-calendar-day" },
      el("time", { datetime: iso }, String(dateNo)),
      ...events.map(item => el("p", { className: "erp-calendar-event" }, formatValue(item[labelKey]))));
  });
  const rows = [];
  for (let i = 0; i < cells.length; i += 7) rows.push(el("tr", {}, ...cells.slice(i, i + 7)));
  return el("div", { className: "table-scroll", role: "region",
    "aria-label": "Monthly calendar", tabindex: "0" },
    el("table", { className: "data-table erp-calendar" },
      el("caption", {}, "Calendar · " + year + "-" + String(month).padStart(2, "0")),
      el("thead", {}, el("tr", {}, ...dayNames.map(name => el("th", { scope: "col" }, name)))),
      el("tbody", {}, ...rows)));
}
