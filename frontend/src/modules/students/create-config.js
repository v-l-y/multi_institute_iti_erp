/* Local student identity only; never an official registration. */
export function studentCreateConfig(user) {
  if (Array.isArray(user.permissions) && user.permissions.includes("student.manage"))
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
