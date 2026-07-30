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
    ap.add_argument("--route-strip", action="store_true", default=True)
    a = ap.parse_args()

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
