/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "students", label: "Students", group: "People", icon: "♙", scope: "institute",
    permission: "student.view", path: "/api/v1/students", key: "students",
    columns: [["local_student_code", "Student code"], ["full_name", "Name"], ["date_of_birth", "DOB"], ["gender", "Gender"], ["prn", "PRN"], ["prn_verified_on", "PRN verified"]] }
];
