/* Local-only static server + same-origin API proxy. No dependencies, no CI hooks. */
import { createServer, request as httpRequest } from "node:http";
import { request as httpsRequest } from "node:https";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { dirname, join } from "node:path";

const home = dirname(fileURLToPath(import.meta.url));
const host = "127.0.0.1";
const port = Number(process.env.FRONTEND_PORT || 5173);
const backend = new URL(process.env.BACKEND_URL || "http://127.0.0.1:8080");
if (!["http:", "https:"].includes(backend.protocol) ||
    !Number.isInteger(port) || port < 1 || port > 65535) {
  throw new Error("Invalid BACKEND_URL or FRONTEND_PORT.");
}
const paths = new Map([
  ["/", ["index.html", "text/html; charset=utf-8"]],
  ["/index.html", ["index.html", "text/html; charset=utf-8"]],
  ["/styles.css", ["styles.css", "text/css; charset=utf-8"]]
]);
// Only source modules/styles with simple names are served. No path traversal,
// dotfiles, source maps, configuration files or directory listings.
function sourceAsset(pathname) {
  if (!/^\/src\/(?:[a-z0-9-]+\/)*[a-z0-9-]+\.(js|css)$/.test(pathname)) return null;
  return [pathname.slice(1), pathname.endsWith(".css")
    ? "text/css; charset=utf-8" : "text/javascript; charset=utf-8"];
}
const securityHeaders = {
  "X-Content-Type-Options": "nosniff",
  "X-Frame-Options": "DENY",
  "Referrer-Policy": "no-referrer",
  "Cache-Control": "no-store",
  "Content-Security-Policy": "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
};

function reply(res, code, body) {
  res.writeHead(code, { ...securityHeaders, "Content-Type": "text/plain; charset=utf-8" });
  res.end(body);
}

function proxy(req, res, path) {
  if (req.method !== "GET" && req.method !== "POST") {
    reply(res, 405, "Method not allowed");
    return;
  }
  const url = new URL(path, backend);
  const headers = {};
  for (const name of ["authorization", "content-type", "content-length", "accept", "user-agent"]) {
    if (typeof req.headers[name] === "string") headers[name] = req.headers[name];
  }
  const send = url.protocol === "https:" ? httpsRequest : httpRequest;
  const upstream = send(url, { method: req.method, headers, timeout: 30000 }, response => {
    if (res.destroyed) { response.resume(); return; }
    res.writeHead(response.statusCode || 502, {
      ...securityHeaders,
      "Content-Type": response.headers["content-type"] || "application/json; charset=utf-8"
    });
    response.pipe(res);
  });
  upstream.on("timeout", () => upstream.destroy(new Error("Backend request timeout")));
  upstream.on("error", () => {
    if (!res.headersSent) reply(res, 502, "ERP backend unavailable");
    else res.destroy();
  });
  req.on("aborted", () => upstream.destroy());
  req.pipe(upstream);
}

createServer(async (req, res) => {
  let url;
  try {
    url = new URL(req.url || "/", "http://127.0.0.1");
  } catch {
    reply(res, 400, "Bad request");
    return;
  }
  if (url.pathname.startsWith("/api/v1/")) {
    proxy(req, res, url.pathname + url.search);
    return;
  }
  if (req.method !== "GET" && req.method !== "HEAD") {
    reply(res, 405, "Method not allowed");
    return;
  }
  const asset = paths.get(url.pathname) || sourceAsset(url.pathname);
  if (!asset) {
    reply(res, 404, "Not found");
    return;
  }
  try {
    const bytes = await readFile(join(home, asset[0]));
    res.writeHead(200, { ...securityHeaders, "Content-Type": asset[1] });
    res.end(req.method === "HEAD" ? undefined : bytes);
  } catch {
    reply(res, 500, "Frontend file unavailable");
  }
}).listen(port, host, () => {
  process.stdout.write("ITI ERP frontend: http://" + host + ":" + port + "\n");
  process.stdout.write("Backend proxy: " + backend.origin + "\n");
});
