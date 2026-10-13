/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "enrollments", label: "Enrollments", group: "Admissions", icon: "≡", scope: "institute",
    permission: "enrollment.view", path: "/api/v1/enrollments", key: "enrollments",
    columns: [["admission_number", "Admission no."], ["student_id", "Student ID"], ["batch_id", "Batch ID"], ["admitted_on", "Admitted"], ["admission_route", "Route"], ["status", "Status"]] },
  { id: "seat-matrix", label: "Seat matrix", group: "Admissions", icon: "▥", scope: "institute",
    permission: "seatmatrix.view", path: "/api/v1/seat-matrix/entries", key: "entries",
    columns: [["version_code", "Matrix version"], ["admission_year", "Year"], ["trade_unit_shift_id", "ITSU ID"], ["seat_capacity", "Seats"], ["admission_allowed", "Admission allowed"], ["version_status", "Source status"]] }
];
