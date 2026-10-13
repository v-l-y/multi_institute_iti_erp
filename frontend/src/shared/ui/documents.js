import { el, formatValue } from "./dom.js";
import { emptyState } from "./feedback.js";
import { button } from "./buttons.js";

/**
 * Metadata-only document register. Opens only caller-supplied authorized
 * document-view actions, never guesses URLs or exposes raw storage paths.
 */
export function documentList(documents = [], {
  title = "Documents", onView, onDownload, nameKey = "name",
  typeKey = "type", referenceKey = "reference", dateKey = "created_at"
} = {}) {
  const section = el("section", { className: "erp-document-list", "aria-label": title },
    el("h3", {}, title));
  if (!documents.length) {
    section.append(emptyState("No documents", "No document metadata has been provided."));
    return section;
  }
  section.append(el("ul", { className: "erp-document-items" },
    ...documents.map(doc => {
      const controls = el("div", { className: "erp-document-actions" });
      if (typeof onView === "function") controls.append(button("View", { onClick: () => onView(doc) }));
      if (typeof onDownload === "function") controls.append(button("Download", {
        onClick: () => onDownload(doc)
      }));
      return el("li", { className: "erp-document-item" },
        el("div", {}, el("strong", {}, formatValue(doc[nameKey])),
          el("p", { className: "muted" },
            [doc[typeKey], doc[referenceKey], doc[dateKey]]
              .filter(value => value != null && value !== "").map(formatValue).join(" · "))),
        controls);
    })));
  return section;
}
