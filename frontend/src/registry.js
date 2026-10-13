/* Visibility reflects permissions from /auth/me; backend remains the authority. */
export const pages = [
  { id: "dashboard", label: "Overview", group: "Workspace", icon: "▦" },
  { id: "institute", label: "Institute profile", group: "Institute", icon: "◈", scope: "institute",
    permission: "institute.profile.view", path: "/api/v1/institute/profile", type: "profile" },
  { id: "itsu", label: "ITSU register", group: "Institute", icon: "▤", scope: "institute",
    permission: "tradeunit.view", path: "/api/v1/itsu", key: "itsu",
    columns: [["id", "ID"], ["official_name", "Trade"], ["dgt_code", "DGT code"], ["shift_name", "Shift"], ["unit_no", "Unit"], ["sanctioned_capacity", "Capacity"], ["affiliation_status", "Affiliation"], ["status", "Status"]] },
  { id: "batches", label: "Batches", group: "Academics", icon: "▣", scope: "institute",
    permission: "batch.view", path: "/api/v1/batches", key: "batches",
    columns: [["batch_code", "Batch"], ["admission_year", "Admission year"], ["trade_unit_shift_id", "ITSU ID"], ["starts_on", "Starts"], ["ends_on", "Ends"], ["capacity", "Capacity"], ["occupied_seats", "Occupied"], ["status", "Status"]] },
  { id: "calendar", label: "Training calendar", group: "Academics", icon: "▧", scope: "institute",
    permission: "calendar.view", path: "/api/v1/academic/calendars", key: "intakes",
    columns: [["admission_year", "Year"], ["intake_code", "Intake"], ["session_part", "Session"], ["session_code", "Calendar"], ["training_starts_on", "Starts"], ["training_ends_on", "Ends"], ["source_reference", "Source"], ["source_status", "Source status"]] },
  { id: "students", label: "Students", group: "People", icon: "♙", scope: "institute",
    permission: "student.view", path: "/api/v1/students", key: "students",
    columns: [["local_student_code", "Student code"], ["full_name", "Name"], ["date_of_birth", "DOB"], ["gender", "Gender"], ["prn", "PRN"], ["prn_verified_on", "PRN verified"]] },
  { id: "staff", label: "Staff", group: "People", icon: "♧", scope: "institute",
    permission: "staff.view", path: "/api/v1/staff", key: "staff",
    columns: [["employee_code", "Employee code"], ["full_name", "Name"], ["staff_category", "Category"], ["designation", "Designation"], ["joined_on", "Joined"], ["left_on", "Left"]] },
  { id: "enrollments", label: "Enrollments", group: "Admissions", icon: "≡", scope: "institute",
    permission: "enrollment.view", path: "/api/v1/enrollments", key: "enrollments",
    columns: [["admission_number", "Admission no."], ["student_id", "Student ID"], ["batch_id", "Batch ID"], ["admitted_on", "Admitted"], ["admission_route", "Route"], ["status", "Status"]] },
  { id: "seat-matrix", label: "Seat matrix", group: "Admissions", icon: "▥", scope: "institute",
    permission: "seatmatrix.view", path: "/api/v1/seat-matrix/entries", key: "entries",
    columns: [["version_code", "Matrix version"], ["admission_year", "Year"], ["trade_unit_shift_id", "ITSU ID"], ["seat_capacity", "Seats"], ["admission_allowed", "Admission allowed"], ["version_status", "Source status"]] },
  { id: "platform-institutes", label: "Institutes", group: "Platform", icon: "▤", scope: "platform",
    permission: "platform.institute.view", path: "/api/v1/platform/institutes", key: "institutes",
    columns: [["name", "Institute"], ["mis_code", "MIS code"], ["district_name", "District"], ["management_type", "Management"], ["state_ut_code", "State"]] },
  { id: "platform-trades", label: "Global trades", group: "Platform", icon: "◉", scope: "platform",
    permission: "platform.regulatory.manage", path: "/api/v1/platform/trades", key: "trades",
    columns: [["dgt_code", "DGT code"], ["official_name", "Trade"], ["trade_type", "Type"], ["version_code", "Revision"], ["status", "Status"], ["effective_from", "Effective from"]] },
  { id: "platform-sources", label: "Regulatory sources", group: "Platform", icon: "☷", scope: "platform",
    permission: "platform.regulatory.manage", path: "/api/v1/platform/regulatory/documents", key: "documents",
    columns: [["authority", "Authority"], ["title", "Document"], ["reference_no", "Reference"], ["issued_on", "Issued"], ["status", "Status"], ["source_system", "System"]] }
];

export function canAccess(page, user) {
  if (!user) return false;
  if (page.scope === "platform" && user.institute_id !== null) return false;
  if (page.scope === "institute" && user.institute_id === null) return false;
  return !page.permission || Array.isArray(user.permissions) && user.permissions.includes(page.permission);
}

export function currentPage() {
  const id = decodeURIComponent(location.hash.slice(2).split("?")[0] || "dashboard");
  return pages.find(p => p.id === id) || null;
}
