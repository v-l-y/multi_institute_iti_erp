/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "calendar", label: "Training calendar", group: "Academics", icon: "▧", scope: "institute",
    permission: "calendar.view", path: "/api/v1/academic/calendars", key: "intakes",
    columns: [["admission_year", "Year"], ["intake_code", "Intake"], ["session_part", "Session"], ["session_code", "Calendar"], ["training_starts_on", "Starts"], ["training_ends_on", "Ends"], ["source_reference", "Source"], ["source_status", "Source status"]] },
  { id: "batches", label: "Batches", group: "Academics", icon: "▣", scope: "institute",
    permission: "batch.view", path: "/api/v1/batches", key: "batches",
    columns: [["batch_code", "Batch"], ["admission_year", "Admission year"], ["trade_unit_shift_id", "ITSU ID"], ["starts_on", "Starts"], ["ends_on", "Ends"], ["capacity", "Capacity"], ["occupied_seats", "Occupied"], ["status", "Status"]] }
];
