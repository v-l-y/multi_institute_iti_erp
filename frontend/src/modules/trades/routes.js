/** Page descriptors only; backend permissions remain authoritative. */
export const routes = [
  { id: "itsu", label: "ITSU register", group: "Institute", icon: "▤", scope: "institute",
    permission: "tradeunit.view", path: "/api/v1/itsu", key: "itsu",
    columns: [["id", "ID"], ["official_name", "Trade"], ["dgt_code", "DGT code"], ["shift_name", "Shift"], ["unit_no", "Unit"], ["sanctioned_capacity", "Capacity"], ["affiliation_status", "Affiliation"], ["status", "Status"]] },
  { id: "platform-trades", label: "Global trades", group: "Platform", icon: "◉", scope: "platform",
    permission: "platform.regulatory.manage", path: "/api/v1/platform/trades", key: "trades",
    columns: [["dgt_code", "DGT code"], ["official_name", "Trade"], ["trade_type", "Type"], ["version_code", "Revision"], ["status", "Status"], ["effective_from", "Effective from"]] }
];
