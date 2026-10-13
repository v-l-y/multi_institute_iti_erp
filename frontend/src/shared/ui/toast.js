import { notice } from "./feedback.js";

export function toast(message, type = "success") {
  const root = document.getElementById("toast-root");
  if (!root) return;
  const box = notice(type === "error" ? "Request failed" : "Updated", message, type);
  box.classList.add("toast");
  root.replaceChildren(box);
  window.setTimeout(() => { if (root.contains(box)) box.remove(); }, 5500);
}
