#!/usr/bin/env python3
"""
real-origin-proxy — put a browser on the REAL dev2 origin, without touching anything shared.

WHY THIS EXISTS
---------------
Two symptoms Ian reported from his own browser (toggles flashing then vanishing;
toggles absent on the desktop hub feed card) were NOT reproducible on the loopback
exercise harness. That is not surprising once stated plainly: the harness is not
the page Ian gets. It reproduces `<script src="/pwa.js">` only, while the real
vhost's server-level `sub_filter` also injects the theme-boot script (which sets
data-lguser-theme / data-lguser-dark / data-lg-hublayout pre-paint), the
`lg-feed-booting` opacity gate, and the analytics tags. A defect that depends on
any of those is invisible to the harness by construction.

The obvious fix — point Chrome at https://dev2.loothgroup.com — does not work:
that host is Cloudflare-proxied and bot-challenges a headless browser into a 403
(CLAUDE.md trap #2). And the usual escapes are all shared-state changes on a
fragile box: `--host-resolver-rules` needs the shared chrome-dev service
restarted, and /etc/hosts is global to every other lane.

So: proxy. This listens on plain HTTP on loopback and forwards each request to the
REAL nginx on 127.0.0.1:443 with `Host: dev2.loothgroup.com`, which is exactly
what `curl --resolve` does — the request never leaves the box, so Cloudflare is
never in the path. The browser sees ordinary same-origin http://127.0.0.1:<port>/,
so every absolute asset path (/hub/forums.css, /pwa.js, /bb-mirror-api/...)
resolves back through the proxy and is served by the REAL serving checkout.

WHAT IT IS AND IS NOT
---------------------
IS:  real nginx, real sub_filter, real serving checkout, real FPM pools, real DB.
NOT: a service worker (scope is the proxy origin, and https is not in play), and
     not Ian's browser profile. State those limits when reporting from it.

The dev gate cookie and the WP auth cookies are injected server-side here so the
browser never has to hold them for a foreign domain.

Usage:
    python3 real-origin-proxy.py [--port 8899] [--cookies /tmp/tf-gate/cookies.txt]
Then point CDP at http://127.0.0.1:8899/hub/?type=discussions
"""
import argparse, http.client, http.server, ssl, sys, threading

GATE_NAME = "loothdev_auth"
UPSTREAM_HOST = "dev2.loothgroup.com"
UPSTREAM_ADDR = "127.0.0.1"
UPSTREAM_PORT = 443

# Hop-by-hop headers must not be forwarded in either direction.
HOP = {"connection", "keep-alive", "proxy-authenticate", "proxy-authorization",
       "te", "trailers", "transfer-encoding", "upgrade"}


def build_cookie(gate_token, cookie_file):
    parts = []
    if gate_token:
        parts.append(f"{GATE_NAME}={gate_token}")
    if cookie_file:
        try:
            for line in open(cookie_file):
                line = line.strip()
                if line and "=" in line:
                    parts.append(line)
        except OSError as e:
            print(f"  (no cookie file: {e}) — running ANONYMOUS", file=sys.stderr)
    return "; ".join(parts)


def make_handler(cookie_header):
    ctx = ssl._create_unverified_context()   # loopback to our own nginx; the cert is for the public name

    class H(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass                              # keep the console for the probe's own output

        def _proxy(self, method):
            body = None
            if "content-length" in {k.lower() for k in self.headers}:
                body = self.rfile.read(int(self.headers["Content-Length"]))

            out = {}
            for k, v in self.headers.items():
                if k.lower() in HOP or k.lower() in ("host", "cookie", "accept-encoding",
                                                     "origin", "referer"):
                    continue
                out[k] = v
            out["Host"] = UPSTREAM_HOST
            # ⚠️ ORIGIN/REFERER MUST BE REWRITTEN, NOT FORWARDED, OR EVERY MUTATION
            # FALSE-FAILS. The browser is on http://127.0.0.1:<port>, so it sends that
            # as Origin. profile-app's CSRF guard (_bootstrap.php
            # profile_app_request_is_same_site) only accepts loothgroup.com hosts and
            # answers 403 {"error":"csrf_origin_rejected"} to everything else — on
            # EVERY POST/PUT/PATCH/DELETE under /profile-api/v0/me/*, for any request
            # carrying a looth_id cookie (which this proxy injects).
            #
            # Measured 2026-07-31 (react-fix lane): reacting to a message through this
            # proxy returned 403 while the IDENTICAL request via
            # `curl --resolve` returned 200. The front end was innocent — the picker
            # opened, the click landed, the POST carried the right body. Read without
            # this note, that 403 reads as "reacting in messages is broken", which is
            # exactly the live-regression claim the lane was sent to test. A harness
            # that fabricates the defect it is being used to investigate is worse than
            # no harness. Host is already rewritten two lines up for the same reason;
            # Origin/Referer are the same class and were simply missed.
            self_origin = f"https://{UPSTREAM_HOST}"
            if "origin" in {k.lower() for k in self.headers}:
                out["Origin"] = self_origin
            ref = self.headers.get("Referer")
            if ref:
                # keep the path, move it onto the upstream origin
                tail = ref.split("//", 1)[-1]
                out["Referer"] = self_origin + ("/" + tail.split("/", 1)[1] if "/" in tail else "/")
            # identity only: we forward the body verbatim, so letting nginx gzip it
            # would mean decompressing here for no reason. It also keeps sub_filter
            # active — sub_filter does not run on an already-compressed response.
            out["Accept-Encoding"] = "identity"
            if cookie_header:
                out["Cookie"] = cookie_header

            try:
                c = http.client.HTTPSConnection(UPSTREAM_ADDR, UPSTREAM_PORT,
                                                context=ctx, timeout=60)
                c.request(method, self.path, body=body, headers=out)
                r = c.getresponse()
                data = r.read()
            except Exception as e:
                self.send_response(502)
                self.send_header("Content-Type", "text/plain")
                self.send_header("Content-Length", "0")
                self.end_headers()
                print(f"  proxy 502 {self.path}: {e}", file=sys.stderr)
                return

            self.send_response(r.status)
            for k, v in r.getheaders():
                lk = k.lower()
                if lk in HOP or lk == "content-length":
                    continue
                # Rewrite redirects/cookies off the public host so the browser stays
                # on the proxy origin instead of being bounced to Cloudflare.
                if lk == "location":
                    v = v.replace(f"https://{UPSTREAM_HOST}", "").replace(f"http://{UPSTREAM_HOST}", "")
                if lk == "set-cookie":
                    v = v.replace(f"Domain=.{UPSTREAM_HOST};", "").replace(f"Domain={UPSTREAM_HOST};", "")
                    v = v.replace("Secure;", "").replace("; Secure", "")
                self.send_header(k, v)
            self.send_header("Content-Length", str(len(data)))
            self.end_headers()
            try:
                self.wfile.write(data)
            except BrokenPipeError:
                pass

        def do_GET(self):    self._proxy("GET")
        def do_POST(self):   self._proxy("POST")
        def do_HEAD(self):   self._proxy("HEAD")
        def do_OPTIONS(self):self._proxy("OPTIONS")

    return H


class Threaded(http.server.ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8899)
    ap.add_argument("--cookies", default="/tmp/tf-gate/cookies.txt")
    ap.add_argument("--gate", default="")
    a = ap.parse_args()
    ck = build_cookie(a.gate, a.cookies)
    print(f"real-origin-proxy → https://{UPSTREAM_HOST} (via {UPSTREAM_ADDR}:{UPSTREAM_PORT})")
    print(f"  listening http://127.0.0.1:{a.port}/   cookies: {'yes' if ck else 'NONE (anon)'}")
    Threaded(("127.0.0.1", a.port), make_handler(ck)).serve_forever()


if __name__ == "__main__":
    main()
