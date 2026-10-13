/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "institute", label: "Institute profile", group: "Institute", icon: "◈", scope: "institute",
    permission: "institute.profile.view", path: "/api/v1/institute/profile", type: "profile" },
  { id: "platform-institutes", label: "Institutes", group: "Platform", icon: "▤", scope: "platform",
    permission: "platform.institute.view", path: "/api/v1/platform/institutes", key: "institutes",
    columns: [["name", "Institute"], ["mis_code", "MIS code"], ["district_name", "District"], ["management_type", "Management"], ["state_ut_code", "State"]] }
];
