/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "staff", label: "Staff", group: "People", icon: "♧", scope: "institute",
    permission: "staff.view", path: "/api/v1/staff", key: "staff",
    columns: [["employee_code", "Employee code"], ["full_name", "Name"], ["staff_category", "Category"], ["designation", "Designation"], ["joined_on", "Joined"], ["left_on", "Left"]] }
];
