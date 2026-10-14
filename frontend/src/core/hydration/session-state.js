/**
 * In-memory, authenticated-session-only presentation state.
 * Deliberately excludes API rows, documents, files, credentials and drafts.
 * Never use browser persistence for institute/learner data.
 */
const MAX_ROUTES = 24;
let states = new Map();

function safeCopy(value) {
  if (!value || typeof value !== "object") return null;
  try {
    const serialized = JSON.stringify(value);
    if (serialized.length > 8192) return null;
    return JSON.parse(serialized);
  } catch {
    return null;
  }
}

export const sessionUiState = Object.freeze({
  restore(route) {
    return safeCopy(states.get(route));
  },
  remember(route, snapshot) {
    if (!/^[a-z0-9-]{1,80}$/i.test(route)) return;
    const copy = safeCopy(snapshot);
    if (!copy) return;
    states.delete(route);
    states.set(route, copy);
    if (states.size > MAX_ROUTES) states.delete(states.keys().next().value);
  },
  forget(route) { states.delete(route); },
  clear() { states.clear(); }
});
