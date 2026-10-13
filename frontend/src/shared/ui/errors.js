import { el } from "./dom.js";
import { button } from "./buttons.js";
import { notice } from "./feedback.js";

/** Recoverable error panel, optional explicit retry (never auto-retries writes). */
export function errorPanel(message, { title = "Unable to load", onRetry } = {}) {
  const node = el("section", { className: "error-panel", role: "region", "aria-label": title },
    notice(title, message || "An unexpected error occurred.", "error"));
  if (typeof onRetry === "function") node.append(button("Try again", { onClick: onRetry }));
  return node;
}

/** Catch synchronous render failures without destroying the persistent app shell. */
export function renderSafely(container, render, { onRetry } = {}) {
  try {
    const result = render();
    if (result instanceof Node) container.replaceChildren(result);
    return true;
  } catch {
    container.replaceChildren(errorPanel("This section could not be displayed.",
      { title: "Display error", onRetry: onRetry || (() => renderSafely(container, render)) }));
    return false;
  }
}
