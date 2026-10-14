/**
 * Progressive DOM hydration for client-rendered ERP screens.
 * Keeps the page shell/toolbar mounted and replaces only result content.
 * This is not SSR hydration: there is no server-rendered HTML to reconcile.
 */
export function createHydrationRegion(container) {
  if (!(container instanceof Element)) throw new TypeError("Hydration region must be an Element");
  let pending = null;
  let disposed = false;
  let hasData = false;

  function setState(state, busy) {
    container.dataset.hydration = state;
    container.setAttribute("aria-busy", String(busy));
  }
  setState("idle", false);

  function begin({ placeholder } = {}) {
    if (disposed) return null;
    pending?.abort();
    const task = new AbortController();
    pending = task;
    // Hydrate an empty slot once; later refreshes preserve previous rows
    // until the replacement API response is validated.
    setState(hasData ? "refreshing" : "loading", true);
    if (!hasData && placeholder instanceof Node) container.replaceChildren(placeholder);
    return task;
  }

  function isCurrent(task) {
    return !disposed && pending === task && !task.signal.aborted;
  }

  function commit(task, node) {
    if (!isCurrent(task)) return false;
    if (!(node instanceof Node)) throw new TypeError("Hydration result must be a DOM Node");
    container.replaceChildren(node);
    hasData = true;
    setState("ready", false);
    return true;
  }

  function fail(task, fallback) {
    if (!isCurrent(task)) return false;
    if (fallback instanceof Node) container.replaceChildren(fallback);
    hasData = false;
    setState("error", false);
    return true;
  }

  function end(task) {
    if (!isCurrent(task)) return false;
    pending = null;
    setState(hasData ? "ready" : "idle", false);
    return true;
  }

  function update(node) {
    if (disposed || !(node instanceof Node)) return false;
    container.replaceChildren(node);
    hasData = true;
    if (!pending) setState("ready", false);
    return true;
  }

  function reset() {
    if (disposed) return;
    pending?.abort();
    pending = null;
    hasData = false;
    container.replaceChildren();
    setState("idle", false);
  }

  return {
    container, begin, isCurrent, commit, fail, end, update, reset,
    get busy() { return pending !== null; },
    get hydrated() { return hasData; },
    dispose() {
      disposed = true;
      pending?.abort();
      pending = null;
      setState("disposed", false);
    }
  };
}
