import { api } from "../../core/http/client.js";
import { el, notice, loadingState, formatValue } from "../../shared/ui/index.js";
import { pageHeader } from "../../shared/ui/headers.js";

export async function renderInstituteProfile(page, main, controller, isCurrent) {
  main.replaceChildren(pageHeader("Institute identity", "Official source references and local institute profile are separate."),
    loadingState());
  try {
    const data = await api.get(page.path, { signal: controller.signal });
    if (!isCurrent(controller)) return;
    const fields = [
      ["name", "Institute name"], ["mis_code", "MIS code"], ["district_name", "District"],
      ["management_type", "Management type"], ["contact_phone", "Contact phone"],
      ["contact_email", "Contact email"], ["id", "Institute ID"]
    ];
    main.replaceChildren(pageHeader("Institute identity", "Details provided by the authenticated institute API."),
      el("section", { className: "surface profile-card" },
        el("div", { className: "section-heading" }, el("p", { className: "eyebrow" }, "REGISTERED INSTITUTE"),
          el("h2", {}, formatValue(data.name))),
        el("dl", { className: "detail-grid" },
          ...fields.map(([key, label]) => el("div", { className: "detail-item" },
            el("dt", {}, label), el("dd", {}, formatValue(data[key]))))));
  } catch (err) {
    if (err.name === "AbortError" || !isCurrent(controller)) return;
    main.replaceChildren(pageHeader("Institute identity", "The profile could not be loaded."),
      notice("Unable to load institute", err.message, "error"));
  }
}
