export function creationConfig(id, user) {
  if (id === "staff" && user.permissions.includes("staff.manage"))
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
  if (id === "students" && user.permissions.includes("student.manage"))
    return {
      title: "Register student identity", path: "/api/v1/students",
      fields: [
        ["local_student_code", "Local student code", { maxLength: 60 }],
        ["full_name", "Full name", { maxLength: 190 }],
        ["date_of_birth", "Date of birth", { type: "date" }],
        ["gender", "Gender (as recorded)", { maxLength: 30 }],
        ["address", "Address", { maxLength: 16000 }],
        ["identity_mode", "Identity evidence mode", { options: [["non_aadhaar", "Non-Aadhaar"], ["aadhaar", "Aadhaar"]] }],
        ["identity_evidence_reference", "Identity evidence reference", { maxLength: 190 }]
      ]
    };
  return null;
}
