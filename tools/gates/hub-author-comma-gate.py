#!/usr/bin/env python3
"""
GATE NUMBER PENDING (keeper) — hub-author-comma-gate. Number requested
2026-08-16, apply on delivery. Reads THIS BRANCH'S TREE via the
featured-members lane preview.

A display name containing a literal comma broke the Hub's author filter
entirely. hub_url() joined multiple selected authors with implode(',', ...)
and the parser split back on the same character, so any ONE name with its
own comma sliced into fragments matching neither the real author.

Found while diagnosing Ian's banner report (backlog 38) — a DIFFERENT,
worse defect in the same code path, not the one he reported. Reproduced on
dev2 with real fixture data and confirmed live: "John Lehmann, Old Naples
Guitars" -> 2 bogus banner headers, 0 matching cards. 6 authors on live
carry a comma this way, including some the backlog-27 archive icon would
otherwise link to.

Fix, flagged OFF by default (platform/config/hub-author-comma-fix.php):
_hub-filters.php's hub_author_delim() returns ',' (today's exact, broken-
for-commas behaviour) when off, \x1F (a character no display name plausibly
contains) when on. hub-filters.js's addAuthor() reads the same decision from
window.LG_HUB_AUTHOR_COMMA_FIX.

  A. FLAG OFF reproduces the exact original defect (2 bogus banners, 0
     cards) for a real comma-bearing dev2 author — proves OFF changed
     nothing, this ships without silently fixing anything Ian has not
     looked at.
  B. FLAG ON fixes it: exactly ONE correctly-named banner, and the card
     count matches a DB ground-truth count for that author — not merely
     "some cards," the RIGHT number.
  C. THE REAL CLIENT PATH: driven via CDP through the actual suggest
     dropdown (not a hand-built URL) for the same comma-bearing author,
     flag on — the picked name round-trips through addAuthor()'s join and
     the server's parse intact, comma included.
  D. STRUCTURAL — hub_authors_join()/hub_authors_parse() are the ONLY join/
     split for the author facet; no leftover raw implode(',', $filters
     ['authors']) anywhere that would silently reintroduce the bug for one
     of the (currently four) call sites while the others were fixed.

Needs:  chrome-dev on 127.0.0.1:9222, tools/gates/gate-env.sh, the
        featured-members lane preview UP, sudo -u postgres psql, a minted
        WP session (author-suggest masks anon near to zero — gate 48's own
        finding, and gate 64 already paid this discovery cost once).
Exit 0 = GREEN. Exit 1 = RED. Exit 2 = CANNOT RUN.
"""

import json
import os
import re
import subprocess
import sys
import time
import urllib.parse
import urllib.request

try:
    import websocket
except ImportError:
    print("CANNOT RUN  python3-websocket-client required")
    sys.exit(2)

CDP = "http://127.0.0.1:9222"
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))  # tools/gates/<file>.py -> tools -> repo (2 dirnames)

PREVIEW_ON = "/preview/featured-members/hub/"
PREVIEW_OFF = "/preview/featured-members/hub-off/"
DEFAULT_UID = 1  # same fixture + reasoning as gate 64 (following-section-gate.py's own default)

OK, RED, DEAD = [], [], []


def gate_env():
    out = subprocess.run(["bash", os.path.join(HERE, "gate-env.sh")],
                          capture_output=True, text=True, timeout=30)
    env = {}
    for line in out.stdout.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    if "LG_GATE_TOKEN" not in env or "LG_GATE_HOST" not in env:
        print("CANNOT RUN  gate-env.sh did not resolve a host/token:\n" + out.stdout + out.stderr)
        sys.exit(2)
    return env


def mint_wp_cookies(uid):
    r = subprocess.run(
        ["sudo", "-n", "-u", "looth-dev", "wp", "eval",
         f'$e=time()+3600; echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie({uid},$e,"logged_in")."\\n";'
         f'echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie({uid},$e,"secure_auth")."\\n";',
         "--skip-themes", "--path=/var/www/dev"],
        capture_output=True, text=True, timeout=30)
    pairs = [l for l in r.stdout.splitlines() if l.startswith("wordpress")]
    if len(pairs) < 2:
        print("CANNOT RUN  could not mint a WP session (needs sudo -u looth-dev wp): "
              + (r.stderr or r.stdout)[:200])
        sys.exit(2)
    return pairs


def psql(db, sql):
    try:
        p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", db,
                             "-A", "-t", "-F", "\x1f", "-c", sql],
                            capture_output=True, text=True, timeout=30)
    except Exception as e:  # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:200]
    return p.stdout, None


def fetch(url, resolve, token):
    cmd = ["curl", "-s", "-w", "\n%{http_code}"] + resolve.split() + \
          ["-b", f"loothdev_auth={token}", url]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
    body, _, code = p.stdout.rpartition("\n")
    return body, code.strip()


class Session:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req, timeout=15))
        self.target_id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                               timeout=15, suppress_origin=True)
        self._id = 0

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.target_id, timeout=10).read()):
            try:
                fn()
            except Exception:  # noqa: BLE001
                pass

    def call(self, method, **params):
        self._id += 1
        self.ws.send(json.dumps({"id": self._id, "method": method, "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self._id:
                if "error" in m:
                    raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})

    def js(self, expr, quiet=False):
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=True, awaitPromise=True)
        if r.get("exceptionDetails"):
            if quiet:
                return None
            raise RuntimeError("JS threw: " + str(r["exceptionDetails"].get("text"))[:200])
        return r.get("result", {}).get("value")

    def goto(self, url, settle=1.0, deadline=20.0):
        self.call("Page.navigate", url=url)
        start = time.monotonic()
        while time.monotonic() - start < deadline:
            time.sleep(0.15)
            try:
                if self.js("document.readyState", quiet=True) == "complete":
                    break
            except Exception:  # noqa: BLE001
                continue
        time.sleep(settle)


def arm(s, tok, domain, wp_cookies):
    s.call("Network.clearBrowserCookies")
    s.call("Network.setCookie", name="loothdev_auth", value=tok, domain=domain, path="/", secure=True)
    host_only = domain[1:] if domain.startswith(".") else domain
    for pair in wp_cookies:
        name, _, value = pair.partition("=")
        s.call("Network.setCookie", name=name, value=value, domain=host_only, path="/", secure=True)


def find_comma_author():
    out, err = psql("looth",
        "SELECT author_id, author_name, count(*) n FROM forums.topic "
        "WHERE author_name LIKE '%,%' GROUP BY author_id, author_name ORDER BY n DESC LIMIT 1;")
    if err or not out or not out.strip():
        return None, None, None, err
    author_id, name, n = out.strip().split("\x1f")
    return author_id, name, int(n), None


def real_card_count(author_name):
    out, err = psql("looth",
        "SELECT count(*) FROM forums.topic WHERE status='publish' AND LOWER(author_name) = LOWER(" +
        psql_quote(author_name) + ");")
    if err:
        return None, err
    return int(out.strip() or "0"), None


def psql_quote(s):
    return "'" + s.replace("'", "''") + "'"


def main():
    env = gate_env()
    host, token, resolve = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"], env.get("LG_GATE_RESOLVE", "")
    domain = "." + env["LG_GATE_DOMAIN"]

    author_id, comma_name, mirror_n, err = find_comma_author()
    if err or comma_name is None:
        print("CANNOT RUN  no comma-bearing author fixture found on this box: " + str(err))
        sys.exit(2)
    real_n, err = real_card_count(comma_name)
    if err or real_n is None or real_n <= 0:
        print(f"CANNOT RUN  could not establish a real ground-truth count for {comma_name!r}: {err}")
        sys.exit(2)

    enc = urllib.parse.quote(comma_name)

    # ── A. FLAG OFF reproduces the exact original defect ──────────────────
    off_body, off_code = fetch(f"{host}{PREVIEW_OFF}?author={enc}", resolve, token)
    if off_code != "200":
        DEAD.append(f"[A] OFF preview did not load ({off_code})")
    else:
        banners = re.findall(r'<h2 class="hub-author-hdr__name">([^<]*)', off_body)
        cards = len(set(re.findall(r'data-topic-id="(\d+)"', off_body)))
        if len(banners) == 2 and cards == 0:
            OK.append(f"[A] flag OFF reproduces the exact original defect for {comma_name!r}: "
                      f"2 bogus banners {banners!r}, 0 cards — unchanged, as a default-off flag "
                      f"must be")
        elif len(banners) <= 1 and cards > 0:
            RED.append(f"[A] flag OFF is not byte-inert — it is ALREADY showing the fix "
                      f"(banners={banners!r}, cards={cards}) even though the flag was never set")
        else:
            DEAD.append(f"[A] flag OFF produced neither the known-broken shape nor the fixed "
                       f"shape: banners={banners!r}, cards={cards} — inconclusive, not a real "
                       f"reproduction of anything")

    # ── B. FLAG ON fixes it, with the RIGHT count ──────────────────────────
    on_body, on_code = fetch(f"{host}{PREVIEW_ON}?author={enc}", resolve, token)
    if on_code != "200":
        DEAD.append(f"[B] ON preview did not load ({on_code})")
    else:
        banners = re.findall(r'<h2 class="hub-author-hdr__name">([^<]*)', on_body)
        cards = len(set(re.findall(r'data-topic-id="(\d+)"', on_body)))
        if len(banners) == 1 and banners[0].strip() == comma_name and cards == real_n:
            OK.append(f"[B] flag ON: exactly one banner, correctly named {comma_name!r}, and "
                      f"{cards} cards matching the DB ground truth ({real_n})")
        else:
            RED.append(f"[B] flag ON did not fix {comma_name!r}: banners={banners!r} "
                      f"(want 1, want name), cards={cards} (want {real_n})")

    # ── D. structural — one join/parse pair, no leftover raw implode ───────
    filter_rail = os.path.join(REPO, "bb-mirror", "web", "forums", "_filter-rail.php")
    hub_filters = os.path.join(REPO, "bb-mirror", "web", "forums", "_hub-filters.php")
    hub_filters_js = os.path.join(REPO, "bb-mirror", "web", "hub-filters.js")
    try:
        rail_src = open(filter_rail, encoding="utf-8").read()
        filt_src = open(hub_filters, encoding="utf-8").read()
        js_src = open(hub_filters_js, encoding="utf-8").read()
    except OSError as e:
        DEAD.append(f"[D] could not read one of the fix's files: {e}")
    else:
        join_calls = len(re.findall(r"hub_authors_join\s*\(", rail_src))
        raw_author_implode = re.findall(r"implode\(',',\s*\$(?:filters|f)\['authors'\]\)", rail_src)
        has_parse_def = re.search(r"function\s+hub_authors_parse\s*\(", filt_src)
        js_reads_flag = re.search(r"window\.LG_HUB_AUTHOR_COMMA_FIX", js_src)
        if join_calls >= 3 and not raw_author_implode and has_parse_def and js_reads_flag:
            OK.append(f"[D] hub_authors_join() is used at all {join_calls} author-facet join "
                      f"sites in _filter-rail.php, no raw comma-implode left over, and the "
                      f"client reads the same flag")
        else:
            RED.append(f"[D] predicate/delimiter sharing is incomplete: join_calls={join_calls} "
                      f"(want >=3), leftover_raw_implode={raw_author_implode}, "
                      f"parse_def_present={bool(has_parse_def)}, js_reads_flag={bool(js_reads_flag)}")

    # ── C. the real client path via CDP ──────────────────────────────────
    wp_cookies = mint_wp_cookies(DEFAULT_UID)
    s = Session()
    try:
        arm(s, token, domain, wp_cookies)
        s.goto(host + PREVIEW_ON)
        pre = s.js("window.LG_HUB_AUTHOR_COMMA_FIX === true")
        if pre is not True:
            DEAD.append("[C] window.LG_HUB_AUTHOR_COMMA_FIX is not true on the ON preview — "
                       "cannot test the real client path at all")
        else:
            typed = comma_name.split(",")[0].strip()  # what a visitor would actually type
            result = s.js("""(async () => {
                const modal = document.getElementById('hub-fmodal');
                if (!modal) return {error: 'no #hub-fmodal'};
                modal.hidden = false;
                const inp = modal.querySelector('[data-hub-author]');
                inp.focus();
                inp.value = %s;
                inp.dispatchEvent(new Event('input', {bubbles: true}));
                for (let i = 0; i < 20; i++) {
                    await new Promise(r => setTimeout(r, 150));
                    const box = modal.querySelector('[data-hub-suggest="author"]');
                    const item = box && box.querySelector('[data-pick]');
                    if (item) {
                        const name = item.getAttribute('data-pick');
                        item.dispatchEvent(new MouseEvent('mousedown', {bubbles: true}));
                        await new Promise(r => setTimeout(r, 1200));
                        return {picked: name, url: location.href,
                                bannerCount: document.querySelectorAll('.hub-author-hdr').length,
                                bannerName: (document.querySelector('.hub-author-hdr__name')||{}).textContent || null};
                    }
                }
                return {error: 'suggest never returned a result for ' + %s};
            })()""" % (json.dumps(typed), json.dumps(typed)))
            if not result or result.get("error"):
                DEAD.append(f"[C] {result.get('error') if result else 'no result'}")
            elif result.get("bannerCount") == 1 and (result.get("bannerName") or "").strip() == comma_name:
                OK.append(f"[C] real dropdown pick of {result.get('picked')!r} round-tripped "
                          f"intact through the client join + server parse: 1 banner, correctly "
                          f"named {comma_name!r}")
            else:
                RED.append(f"[C] real dropdown pick produced bannerCount="
                          f"{result.get('bannerCount')}, bannerName={result.get('bannerName')!r} "
                          f"— comma did not survive the round-trip via the actual client path")
    except Exception as e:  # noqa: BLE001
        DEAD.append(f"[C] {type(e).__name__}: {e}")
    finally:
        s.finish()

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"hub-author-comma-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"hub-author-comma-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"hub-author-comma-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
