/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "platform-sources", label: "Regulatory sources", group: "Platform", icon: "☷", scope: "platform",
    permission: "platform.regulatory.manage", path: "/api/v1/platform/regulatory/documents", key: "documents",
    columns: [["authority", "Authority"], ["title", "Document"], ["reference_no", "Reference"], ["issued_on", "Issued"], ["status", "Status"], ["source_system", "System"]] }
];
