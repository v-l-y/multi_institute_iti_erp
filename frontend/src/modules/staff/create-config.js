/* Teaching/non-teaching staff share the existing backend Staff record. */
export function staffCreateConfig(user) {
  if (Array.isArray(user.permissions) && user.permissions.includes("staff.manage"))
    return {
      title: "Add staff record", path: "/api/v1/staff",
      fields: [
        ["employee_code", "Employee code", { maxLength: 50 }],
        ["full_name", "Full name", { maxLength: 190 }],
        ["staff_category", "Staff category", { options: [["teaching", "Teaching"], ["non_teaching", "Non-teaching"]] }],
        ["designation", "Designation", { maxLength: 120 }],
        ["joined_on", "Joined on", { type: "date" }]
      ]
    };
  return null;
}
