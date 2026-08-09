#!/usr/bin/env python3
"""
endpoint-swap-proxy — the REAL dev2 page, with ONE endpoint swapped to a branch.

WHY THIS EXISTS (shorty-react lane, 2026-07-30)
-----------------------------------------------
`real-origin-proxy.py` puts a browser on the real dev2 origin, but everything it
serves comes from the SERVING CHECKOUT, which is main. That is perfect for
reproducing a defect (red) and useless for proving a fix (green): the branch's
code is never executed. Flipping the serving checkout to a branch is forbidden
(CLAUDE.md, the one rule that outranks everything).

So: forward everything to the real nginx as usual, EXCEPT a small set of paths,
which go to a loopback `php -S` running the branch as the correct pool user.
The page, its CSS/JS overlays, the sub_filter injection, WP auth and the DB are
all the real thing; only the file under test is the branch's.

    red  = proxy with no --route          -> serving checkout (== main)
    green= proxy with --route <the file>  -> branch, one file swapped

TWO THINGS THIS HAS TO GET RIGHT
--------------------------------
1. ORIGIN. archive-poc pins LG_ARCHIVE_POC_HOST from /etc/looth/env
   (LG_PUBLIC_HOST=dev2.loothgroup.com), and card-react.php:130-134 rejects any
   POST whose Origin host differs -> 403 bad_origin. A browser parked on
   http://127.0.0.1:<port> would therefore 403 on EVERY react, red and green
   alike, masking the very status code under test. `--rewrite-origin` restores
   the production relationship (Origin host == public host) that Ian's browser
   has natively. The guard still runs; it is fed its real-world input, not
   bypassed. Say so when reporting from this harness.

2. COOKIES. Injected server-side (same as real-origin-proxy) so the browser
   never has to hold cookies for a foreign domain, and so the swapped-in
   php -S sees the same WP auth as nginx does.

Usage:
    python3 endpoint-swap-proxy.py --port 8899 \
        --cookies /tmp/<lane>-exercise/cookies.txt \
        --gate <dev-gate-token> \
        --rewrite-origin \
        --route /archive-api/v0/card-react=127.0.0.1:8793

  --route may be repeated. Match is a path PREFIX (query string ignored), and
  the path is passed through unchanged except for --route-strip.
"""
import argparse, http.client, http.server, ssl, sys

GATE_NAME = "loothdev_auth"
UPSTREAM_HOST = "dev2.loothgroup.com"
UPSTREAM_ADDR = "127.0.0.1"
UPSTREAM_PORT = 443

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
            print(f"  cookie file: {e}", file=sys.stderr)
    return "; ".join(parts)


def head_injection():
    """nginx's server-level sub_filter, read FROM nginx — not copied.

    ⚠️ THIS IS WHY A SWAPPED HTML PAGE USED TO COME BACK CRIPPLED, and it is silent.
    dev2's server block rewrites '</head>' in every text/html response to add the
    manifest, the theme boot script and — the one that matters — <script src="/pwa.js">.
    pwa.js is what loads EVERY client layer: bottom-nav, profile-sheet, messenger-sheet,
    mobile-hub, push. A swapped route never touches nginx, so its HTML arrives with
    none of that, the page renders perfectly, and every mobile behaviour is simply
    absent. That reads as "the branch broke the sheet" when the branch is fine.

    Measured while proving backlog 4.4: the harness-served /u/ had window.openMessenger
    undefined, so tapping Message could not open a DM no matter what the code did.

    The string is read out of the running conf so it cannot drift from what nginx
    actually does. If it cannot be read we say so loudly rather than serving a page
    that is quietly missing its whole client layer.
    """
    import re as _re, subprocess
    conf = "/etc/nginx/sites-enabled/dev2.loothgroup.com.conf"
    out = subprocess.run(["sudo", "grep", "-h", "-m1", "sub_filter '</head>'", conf],
                         capture_output=True, text=True).stdout
    m = _re.search(r"sub_filter\s+'</head>'\s+'(.*)';\s*$", out.strip())
    if not m:
        print("  !! could not read nginx's sub_filter from " + conf +
              "\n     Swapped HTML will have NO /pwa.js and therefore NO client layers."
              "\n     Do not trust any mobile-behaviour result from this run.",
              file=sys.stderr)
        return None
    return m.group(1).encode()


HEAD_INJECT = None      # set in main()


def inject_head(data, ctype):
    """Apply nginx's injection to a swapped HTML body. sub_filter_once is on, so once."""
    if HEAD_INJECT is None or "text/html" not in (ctype or ""):
        return data
    if b"</head>" not in data or b"/pwa.js" in data:
        return data
    return data.replace(b"</head>", HEAD_INJECT, 1)


def make_handler(cookie_header, routes, rewrite_origin, strip):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE

    class H(http.server.BaseHTTPRequestHandler):
        protocol_version = "HTTP/1.1"

        def log_message(self, *a):
            pass

        def _pick_route(self):
            path = self.path.split("?", 1)[0]
            for prefix, dest in routes:
                if path == prefix or path.startswith(prefix.rstrip("/") + "/") \
                   or path == prefix + ".php":
                    return prefix, dest
            return None, None

        def _proxy(self, method):
            body = None
            if "content-length" in {k.lower() for k in self.headers}:
                body = self.rfile.read(int(self.headers["Content-Length"]))

            prefix, dest = self._pick_route()

            out = {}
            for k, v in self.headers.items():
                if k.lower() in HOP or k.lower() in ("host", "cookie", "accept-encoding"):
                    continue
                out[k] = v
            out["Host"] = UPSTREAM_HOST
            out["Accept-Encoding"] = "identity"
            if cookie_header:
                out["Cookie"] = cookie_header

            # See docstring note 1 — restore the production Origin/Referer host.
            if rewrite_origin:
                if "Origin" in out:
                    out["Origin"] = f"https://{UPSTREAM_HOST}"
                for k in list(out):
                    if k.lower() == "referer":
                        out[k] = f"https://{UPSTREAM_HOST}/"

            path = self.path
            try:
                if dest:
                    if strip:
                        # php -S is docroot'd at the endpoint dir, so hand it the
                        # bare filename the rewrite would have produced.
                        tail = path[len(prefix):].split("?", 1)
                        q = ("?" + tail[1]) if len(tail) > 1 else ""
                        leaf = prefix.rstrip("/").rsplit("/", 1)[-1]
                        path = f"/{leaf}.php{q}"
                    host, _, port = dest.partition(":")
                    c = http.client.HTTPConnection(host, int(port), timeout=60)
                else:
                    c = http.client.HTTPSConnection(UPSTREAM_ADDR, UPSTREAM_PORT,
                                                    context=ctx, timeout=60)
                c.request(method, path, body=body, headers=out)
                r = c.getresponse()
                data = r.read()
            except Exception as e:
                self.send_response(502)
                self.send_header("Content-Type", "text/plain")
                self.send_header("Content-Length", "0")
                self.end_headers()
                print(f"  proxy 502 {self.path}: {e}", file=sys.stderr)
                return

            if dest:
                print(f"  [SWAPPED->{dest}] {method} {self.path} -> {r.status}", file=sys.stderr)
                data = inject_head(data, r.getheader("Content-Type", ""))

            self.send_response(r.status)
            for k, v in r.getheaders():
                lk = k.lower()
                if lk in HOP or lk == "content-length":
                    continue
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

        def do_GET(self):     self._proxy("GET")
        def do_POST(self):    self._proxy("POST")
        def do_HEAD(self):    self._proxy("HEAD")
        def do_OPTIONS(self): self._proxy("OPTIONS")

    return H


class Threaded(http.server.ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--port", type=int, default=8899)
    ap.add_argument("--cookies", default="")
    ap.add_argument("--gate", default="")
    ap.add_argument("--rewrite-origin", action="store_true")
    ap.add_argument("--route", action="append", default=[],
                    help="/path/prefix=127.0.0.1:PORT (repeatable)")
    # store_true WITH default=True cannot be turned off, so every swapped route was
    # rewritten to "<prefix>.php" whether or not that was the right shape. Fine for a
    # single endpoint whose file really is <leaf>.php; wrong for anything else:
    #   /profile-sheet.js  ->  /profile-sheet.js.php   404, reads as "the branch is broken"
    #   /u/<slug>          ->  /u.php                  slug DISCARDED, wrong profile or 404
    # A branch server that routes on the real path needs the real path.
    ap.add_argument("--route-strip", action="store_true", default=True,
                    help="rewrite a swapped path to /<leaf>.php (php -S docroot'd at the endpoint dir)")
    ap.add_argument("--no-route-strip", action="store_false", dest="route_strip",
                    help="pass the swapped path through UNCHANGED — needed for static assets "
                         "and for any branch server doing its own routing")
    ap.add_argument("--no-head-inject", action="store_false", dest="head_inject",
                    help="do NOT re-apply nginx's </head> sub_filter to swapped HTML "
                         "(you almost never want this — see head_injection())")
    a = ap.parse_args()

    global HEAD_INJECT
    if a.head_inject:
        HEAD_INJECT = head_injection()
        if HEAD_INJECT:
            print(f"  head-inject: ON ({len(HEAD_INJECT)} bytes of nginx sub_filter, "
                  f"incl. /pwa.js) — swapped HTML keeps its client layers", file=sys.stderr)

    routes = []
    for r in a.route:
        prefix, _, dest = r.partition("=")
        if not prefix or not dest:
            sys.exit(f"bad --route {r!r} (want /prefix=host:port)")
        routes.append((prefix, dest))

    ck = build_cookie(a.gate, a.cookies)
    print(f"endpoint-swap-proxy :{a.port} → https://{UPSTREAM_HOST} "
          f"(via {UPSTREAM_ADDR}:{UPSTREAM_PORT})")
    print(f"  rewrite-origin: {a.rewrite_origin}")
    for prefix, dest in routes:
        print(f"  SWAP {prefix} → {dest}")
    if not routes:
        print("  (no routes — serving checkout only: this is the RED configuration)")
    Threaded(("127.0.0.1", a.port), make_handler(ck, routes, a.rewrite_origin, a.route_strip)).serve_forever()


if __name__ == "__main__":
    main()
