import { api } from "../../core/http/client.js";
import { el, loadingState, formatValue, errorPanel } from "../../shared/ui/index.js";
import { pageHeader } from "../../shared/ui/headers.js";
import { createHydrationRegion } from "../../core/hydration/region.js";

/** Hydrate only the institute record slot; keep the page header mounted. */
export async function renderInstituteProfile(page, main, controller, isCurrent) {
  const content = el("section", { className: "surface profile-card" });
  const region = createHydrationRegion(content);
  main.replaceChildren(
    pageHeader("Institute identity",
      "Official source references and local institute profile are separate."),
    content
  );
  const request = region.begin({ placeholder: loadingState() });
  try {
    const data = await api.get(page.path, { signal: controller.signal });
    if (!isCurrent(controller) || !region.isCurrent(request)) return;
    const fields = [
      ["name", "Institute name"], ["mis_code", "MIS code"], ["district_name", "District"],
      ["management_type", "Management type"], ["contact_phone", "Contact phone"],
      ["contact_email", "Contact email"], ["id", "Institute ID"]
    ];
    region.commit(request, el("div", {},
      el("div", { className: "section-heading" },
        el("p", { className: "eyebrow" }, "REGISTERED INSTITUTE"),
        el("h2", {}, formatValue(data.name))),
      el("dl", { className: "detail-grid" },
        ...fields.map(([key, label]) => el("div", { className: "detail-item" },
          el("dt", {}, label), el("dd", {}, formatValue(data[key])))))));
  } catch (err) {
    if (err.name === "AbortError" || !isCurrent(controller)) return;
    region.fail(request, errorPanel(err.message || "Institute API unavailable.", {
      title: "Unable to load institute",
      onRetry: () => {
        if (isCurrent(controller)) void renderInstituteProfile(page, main, controller, isCurrent);
      }
    }));
  } finally {
    region.end(request);
  }
}
