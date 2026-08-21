#!/usr/bin/env python3
"""GATE 87 — the header's account chip is ONE LINE, and the full name survives it.

#173. Ian 8/20, signed in as "Massimiliano Monterosso Maxmonte Guitars":
"Verbose names in the profile icon in the header? Maybe do a ....." — and again
8/21 as "Ian Davlin The Looth Group": "Something changed in the header. We are
stacking words that used to be inline."

⚠️ THIS IS THE SECOND DISCOVERY OF THE CLASS, WHICH IS WHY IT IS A GATE.
site-header.css's own ≤820 rule carries the comment "the display name is the
first thing to drop (it's what tips a busy admin aside into a two-line wrap)" —
that was discovery #1, answered by hiding the name on tablets. This is the same
defect at desktop widths, so CRAFT-STANDARD's law says it gets encoded before it
is fixed a second time.

WHAT THE DEFECT ACTUALLY WAS, because the obvious assertion measures the wrong
thing: the header BAR never changed height. `.lg-chrome__inner` is `height:60px`
FIXED. What grew was the account button inside it — 40px → 49 → 62 → 88 — and it
spilled OUT of the bar. So "the header is still 60px tall" is true of the broken
state and of the fixed one, and a gate written around it would be green on the
screenshot Ian sent.

WHAT IT MEASURES INSTEAD
  §A  source — the rule exists in BOTH stylesheets, comments stripped first
  §B  render — the full name reaches title= and the menu row, escaped; and the
      ANON render is cmp-identical to origin/main in every flag state
  §C  browser — one line, one 40px button, a VISIBLE ellipsis, both themes,
      both sides of both breakpoints
  §D  the overflow baseline — a 71-character name adds no horizontal scroll
      over a 3-character one. This is the leg that keeps the max-width's
      constant honest: a seventh nav item or a wider wordmark reddens a gate
      instead of silently invalidating it.

CANNOT GO VACUOUSLY GREEN. No WordPress, no DB, no login, no network beyond the
loopback CDP port. The header is EXECUTED by php in hermetic temp trees, and
every browser cell asserts liveness (logo + nav + account button present) before
anything else, because a blank page satisfies "nothing wrapped" perfectly
(feedback-absence-assertion-needs-liveness). If Chrome cannot be reached the
gate exits 2 — CANNOT RUN — never 0 (trap-gate-exit-code-3-blocks-every-lane).

IT DOES NOT TOUCH THE SHARED CHROME PROFILE. It opens its own target, sets the
document with Page.setDocumentContent, and closes it. No dev2 navigation, no
cookies, no localStorage, and the theme is an attribute on its OWN document —
never a stamp on a shared key (trap-mock-theme-stamp-poisons-shared-chrome).
"""
import json, os, re, shutil, subprocess, sys, tempfile, urllib.request

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CDP  = os.environ.get("LG_CDP", "http://127.0.0.1:9222")
HEADER_PHP = f"{REPO}/lg-shared/site-header.php"
HEADER_CSS = f"{REPO}/lg-shared/site-header.css"
ARCHIVE_CSS = f"{REPO}/archive-poc/web/archive.css"

_fail, _pass, _reports = [], [], []

def log(m=""): print(m)
def check(name, ok, detail=""):
    (_pass if ok else _fail).append(name)
    log(f"  {'PASS' if ok else 'FAIL'}  {name}" + (f"   {detail}" if detail and not ok else ""))
def report(name, detail=""):
    _reports.append(name); log(f"  REPORT  {name}" + (f"   {detail}" if detail else ""))
def cannot_run(why):
    log(f"\n  CANNOT RUN: {why}")
    log("  (exit 2 — a missing environment is not a finding)")
    sys.exit(2)

# ── THE FIXTURE NAMES ARE REAL, and that matters ────────────────────────────
# Measured off wp_users on dev2, 1,933 rows, 2026-08-21: the long tail of this
# platform is business-suffixed display names, not long personal ones. The two
# Ian actually reported are in the set. A name longer than these needs no new
# fixture — the clamp caps at its own max-width, so every name wider than the
# cap is the same case, and §D's baseline is what proves that case costs the
# row nothing.
NAME_SHORT = "Ian"                                    # the 3-char baseline
NAME_IAN   = "Ian Davlin The Looth Group"             # 8/21, 200.3px rendered
NAME_MAX   = "Massimiliano Monterosso Maxmonte Guitars"   # 8/20, the original
NAME_WORST = 'Dave Staudte (rhymms with "Howdy") NB Guitar Repair (New Braunfels, TX)'
NAMES = [("short", NAME_SHORT), ("ian", NAME_IAN), ("max", NAME_MAX), ("worst", NAME_WORST)]

# Both sides of both breakpoints (1000 and 820), plus the widths the defect was
# reported at. bracket-both-sides: trap-presence-is-not-reachability.
WIDTHS = [1600, 1440, 1280, 1200, 1154, 1100, 1024, 1001, 1000, 950, 900, 821, 820, 640, 390]
SHOWN_AT = lambda w: w > 1000      # what the CSS is supposed to do
THEMES = ["light", "dark"]


# ══════════════════════════════════════════════════════════════════ helpers
def strip_css_comments(css):
    """Gate 79 spent two re-diagnoses reading PROSE as a selector. Not again."""
    return re.sub(r"/\*.*?\*/", "", css, flags=re.S)

def rule_body(css, selector):
    """The declaration block for an exact top-level selector, comments gone."""
    css = strip_css_comments(css)
    m = re.search(re.escape(selector) + r"\s*\{([^}]*)\}", css)
    return m.group(1) if m else None

HARNESS = r'''<?php
$ctx = [
  "authenticated" => true,
  "display_name"  => $argv[2],
  "capabilities"  => ["manage_options" => false, "stripe_testgroup" => ($argv[3] === "1")],
  "logout_url" => "/logout", "profile_url" => "/p", "logo_url" => "",
  "active_nav" => "hub", "tier" => "looth3",
  "msg_unread" => 3, "notif_unread" => 5, "avatar_url" => null,
];
if ($argv[4] === "anon") { $ctx["authenticated"] = false; $ctx["display_name"] = "";
                           $ctx["capabilities"] = []; $ctx["tier"] = null;
                           $ctx["msg_unread"] = null; $ctx["notif_unread"] = null; }
ob_start(); require $argv[1]; lg_shared_render_site_header($ctx); echo ob_get_clean();
'''

def render(header_php, name, tester=True, who="authed", state="allowlist"):
    env = dict(os.environ, LG_HEADER_JOIN_STRIPE=state)
    return subprocess.check_output(
        ["php", _harness_path, header_php, name, "1" if tester else "0", who],
        env=env, stderr=subprocess.DEVNULL).decode("utf-8", "replace")


# ══════════════════════════════════════════════════════════════════════ §A
def leg_a():
    log("§A  THE RULE EXISTS, IN BOTH COPIES OF THE CHROME BLOCK")
    for label, path in (("site-header.css", HEADER_CSS), ("archive.css", ARCHIVE_CSS)):
        try: css = open(path, encoding="utf-8").read()
        except OSError: check(f"{label}: readable", False, path); continue
        body = rule_body(css, ".lg-chrome__account-name")
        if body is None:
            check(f"{label}: .lg-chrome__account-name has a rule at all", False)
            continue
        one = " ".join(body.split())
        check(f"{label}: the name never wraps", "white-space: nowrap" in one, one)
        check(f"{label}: the overflow is hidden", "overflow: hidden" in one, one)
        check(f"{label}: the truncation is an ELLIPSIS, not a clip",
              "text-overflow: ellipsis" in one, one)
        # ⚠️ a max-width that is a FIXED number cannot be right at 1440 and at
        # 1024 at once — one generous enough for the wide case turns the wrap
        # into a horizontal overflow at the narrow one. §D is what proves the
        # value; this only asserts it responds to the viewport at all.
        check(f"{label}: the max-width RESPONDS to the viewport",
              re.search(r"max-width:\s*[^;]*100vw", one) is not None, one)
        # The phone rule that predates #173 is not collateral damage.
        check(f"{label}: the ≤820 hide is still there",
              re.search(r"@media[^{]*max-width:\s*820px[^{]*\{(?:[^{}]|\{[^{}]*\})*"
                        r"\.lg-chrome__account-name\s*\{[^}]*display:\s*none",
                        strip_css_comments(css)) is not None)
    log("")


# ══════════════════════════════════════════════════════════════════════ §B
def leg_b(tmp):
    log("§B  THE FULL NAME SURVIVES THE CLAMP — and anon does not move")
    php = open(HEADER_PHP, encoding="utf-8").read()

    html = render(HEADER_PHP, NAME_WORST)
    # Liveness first: every assertion below is a substring test, and a substring
    # test on an empty render is a different kind of false.
    check("liveness: the authed render is a real header",
          len(html) > 5000 and 'class="lg-chrome__account"' in html,
          f"{len(html)} bytes")

    esc = NAME_WORST.replace("&", "&amp;").replace('"', "&quot;")
    check("the chip carries the FULL name in title=",
          f'class="lg-chrome__account-name" title="{esc}"' in html)
    check("the opened menu carries the FULL name, character for character",
          f'class="lg-chrome__account-menu-name">{esc}</li>' in html)
    # A label, not a door: arrow-key menu navigation must not stop on it.
    check("the menu's name row is presentational, not a menuitem",
          re.search(r'<li role="presentation" class="lg-chrome__account-menu-name">',
                    html) is not None)
    # The escaping is the assertion, not a detail: this name contains a quote,
    # and an unescaped one would break OUT of the title attribute.
    check("the name is escaped in both places — a quote cannot break the attribute",
          '"Howdy"' not in html.split("lg-chrome__account-name")[1][:400])

    # ⚠️ THE MENU'S CSS MUST NOT LIVE IN site-header.php's INLINE CRITICAL BLOCK.
    # That block is emitted on EVERY render including the anonymous one, so a
    # rule added there grows the anon response by its own length. The first
    # draft of #173 did exactly that (+745 bytes) and it was the byte-identity
    # leg below that caught it, not review.
    inline = php.split("<style>")[1].split("</style>")[0] if "<style>" in php else ""
    check("the menu's name rule is NOT in the anon-visible inline block",
          "lg-chrome__account-menu-name" not in inline)

    anon = render(HEADER_PHP, "", tester=False, who="anon")
    check("anon has no account-name element at all",
          "lg-chrome__account-name" not in anon and "lg-chrome__account-menu-name" not in anon)
    check("liveness: the anon render is a real header",
          len(anon) > 5000 and 'class="lg-chrome__signin"' in anon, f"{len(anon)} bytes")

    # ── ANON BYTE-IDENTITY vs origin/main, in every flag state ──────────────
    # Rendered from TWO hermetic copies with normalised mtimes, because the
    # partial embeds @filemtime(social-modals.js) in a cache-buster: comparing a
    # repo render to a temp-tree render would differ by that number and read as
    # a leak. Neither tree is the worktree, and the serving checkout is never
    # touched.
    try:
        main_php = subprocess.check_output(
            ["git", "-C", REPO, "show", "origin/main:lg-shared/site-header.php"],
            stderr=subprocess.DEVNULL)
    except Exception:
        report("origin/main is unreachable — the anon byte-identity leg is SKIPPED, not passed",
               "fetch origin, then re-run")
        log(""); return
    trees = {}
    for which in ("branch", "main"):
        root = f"{tmp}/{which}"
        os.makedirs(f"{root}/platform", exist_ok=True)
        shutil.copytree(f"{REPO}/lg-shared", f"{root}/lg-shared")
        shutil.copytree(f"{REPO}/platform/config", f"{root}/platform/config")
        if which == "main":
            open(f"{root}/lg-shared/site-header.php", "wb").write(main_php)
        for dirpath, _d, files in os.walk(root):
            for fn in files: os.utime(os.path.join(dirpath, fn), (1600000000, 1600000000))
        trees[which] = f"{root}/lg-shared/site-header.php"
    for state in ("off", "allowlist", "on"):
        a = render(trees["branch"], "", tester=False, who="anon", state=state)
        b = render(trees["main"],   "", tester=False, who="anon", state=state)
        check(f"anon, {state}: byte-identical to origin/main",
              a == b, f"{len(a)} bytes vs {len(b)}")
    log("")


# ══════════════════════════════════════════════════════════════ browser bits
PROBE = r"""(() => {
  const q = s => document.querySelector(s), r = e => e && e.getBoundingClientRect();
  const chrome=q('.lg-chrome'), inner=q('.lg-chrome__inner'), nav=q('.lg-chrome__nav');
  const logo=q('.lg-chrome__logo'), btn=q('.lg-chrome__account');
  const el=q('.lg-chrome__account-name'), join=q('.lg-chrome__join');
  const cs = el && getComputedStyle(el);
  const lh = cs ? (parseFloat(cs.lineHeight) || parseFloat(cs.fontSize)) : 0;
  const rect = r(el);
  return {
    live: !!(chrome && inner && logo && btn),
    innerW: window.innerWidth,
    docScrollW: document.documentElement.scrollWidth,
    btnH: btn ? +r(btn).height.toFixed(1) : null,
    joinVisible: join ? getComputedStyle(join).display !== 'none' : false,
    display: cs ? cs.display : null,
    lines: (rect && lh && cs.display !== 'none') ? Math.round(rect.height / lh) : 0,
    width: rect ? +rect.width.toFixed(1) : 0,
    ellipsised: el ? el.scrollWidth > el.clientWidth : false,
    textOverflow: cs ? cs.textOverflow : null,
    title: el ? (el.getAttribute('title') || '') : null,
    navDisplay: nav ? getComputedStyle(nav).display : null,
  };
})()"""

class Browser:
    def __init__(self):
        try:
            import websocket  # noqa
        except ImportError:
            cannot_run("python websocket-client is not installed")
        self.ws = None
        try:
            req = urllib.request.Request(f"{CDP}/json/new?about:blank", method="PUT")
            self.tab = json.loads(urllib.request.urlopen(req, timeout=10).read())
        except Exception as e:
            cannot_run(f"no CDP at {CDP} ({e}) — the headless chrome is not up")
        import websocket
        try:
            # suppress_origin: Chrome 151 rejects a WS handshake carrying an Origin.
            self.ws = websocket.create_connection(self.tab["webSocketDebuggerUrl"],
                                                  timeout=25, suppress_origin=True)
        except Exception as e:
            cannot_run(f"CDP websocket refused ({e})")
        self.n = 0
        self.send("Page.enable"); self.send("Runtime.enable")
        self.frame = self.send("Page.getFrameTree")["frameTree"]["frame"]["id"]

    def send(self, method, params=None):
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": method, "params": params or {}}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self.n:
                if "error" in m: raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})

    def measure(self, html, width):
        self.send("Emulation.setDeviceMetricsOverride",
                  {"width": width, "height": 900, "deviceScaleFactor": 1, "mobile": False})
        self.send("Page.setDocumentContent", {"frameId": self.frame, "html": html})
        return self.send("Runtime.evaluate",
                         {"expression": PROBE, "returnByValue": True})["result"]["value"]

    def close(self):
        try:
            if self.ws: self.ws.close()
            urllib.request.urlopen(f"{CDP}/json/close/{self.tab['id']}", timeout=5).read()
        except Exception: pass

# ⚠️ THE VIEWPORT META IS LOAD-BEARING. Without it Chrome lays a page out in a
# 980px fallback viewport under emulation, every media query answers for 980
# instead of the width under test, and the ≤820 rules never fire — which reads
# as "the phone rule is gone" on a stylesheet that is perfectly fine. Measured,
# not assumed: matchMedia was False at a 640px override until this was added.
PAGE = """<!doctype html><html%(theme)s><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>%(css)s</style><style>body{margin:0}</style></head>
<body>%(header)s<div style="height:600px"></div></body></html>"""


# ══════════════════════════════════════════════════════════════════ §C + §D
def leg_cd(br):
    log("§C  ONE LINE, EVERY NAME, EVERY WIDTH, BOTH THEMES")
    css = open(HEADER_CSS, encoding="utf-8").read()
    pages = {}
    for key, name in NAMES:
        for tester in (True, False):
            html = render(HEADER_PHP, name, tester=tester)
            for theme in THEMES:
                pages[(key, tester, theme)] = PAGE % {
                    "css": css, "header": html,
                    "theme": ' data-lguser-theme="dark"' if theme == "dark" else ""}

    cells, dead, multiline, tall, no_ellipsis, no_title = 0, [], [], [], [], []
    wrong_visibility, base = [], {}
    for theme in THEMES:
        for tester in (True, False):
            for key, name in NAMES:
                for w in WIDTHS:
                    v = br.measure(pages[(key, tester, theme)], w)
                    cells += 1
                    tag = f"{key}/{'join' if tester else 'nojoin'}/{theme}/{w}"
                    if not v["live"]: dead.append(tag); continue
                    if key == "short": base[(tester, theme, w)] = v["docScrollW"]
                    shown = v["display"] != "none"
                    if shown != SHOWN_AT(w): wrong_visibility.append(f"{tag}: display={v['display']}")
                    if shown:
                        if v["lines"] > 1: multiline.append(f"{tag}: {v['lines']} lines")
                        if v["btnH"] > 40: tall.append(f"{tag}: button {v['btnH']}px")
                        if v["title"] != name: no_title.append(tag)
                        # A name WIDER than the cap must show an ellipsis; a name
                        # that fits must not. Both directions, because "always
                        # truncated" and "never truncated" are each a defect.
                        if key == "worst" and not v["ellipsised"]:
                            no_ellipsis.append(f"{tag}: clamped to {v['width']}px, no ellipsis")
                        if key == "short" and v["ellipsised"]:
                            no_ellipsis.append(f"{tag}: a 3-char name should not truncate")
                        # ⚠️ scrollWidth > clientWidth is TRUE OF A BARE CLIP TOO
                        # — overflow:hidden alone satisfies it — so deleting
                        # text-overflow would leave the assertion above green on
                        # a chip that cuts a name off mid-letter with no "…".
                        # The computed value is what says it is an ellipsis.
                        if v["textOverflow"] != "ellipsis":
                            no_ellipsis.append(f"{tag}: text-overflow is {v['textOverflow']}")

    check("liveness: every cell rendered a real header", not dead,
          f"{len(dead)} dead: {dead[:3]}")
    if dead: log("  (every assertion below is vacuous on a dead render — stopping here)"); return
    check(f"the name is ONE LINE in all {cells} cells", not multiline,
          f"{len(multiline)}: {multiline[:4]}")
    check("the account button never exceeds its 40px single-line height", not tall,
          f"{len(tall)}: {tall[:4]}")
    check("the truncation is a VISIBLE ellipsis, and only when needed", not no_ellipsis,
          f"{len(no_ellipsis)}: {no_ellipsis[:4]}")
    check("every rendered chip carries its own full name in title=", not no_title,
          f"{len(no_title)}: {no_title[:4]}")
    check("the name is shown above 1000 and hidden at 1000 and below —"
          " both sides of the breakpoint", not wrong_visibility,
          f"{len(wrong_visibility)}: {wrong_visibility[:4]}")

    # ⚠️ THE CASE THAT OPENED THE ISSUE, asserted by itself so it can never be
    # lost inside an aggregate: Ian's own name, with the tester Join pill, is
    # the exact combination that made him report this.
    ian_full = []
    for theme in THEMES:
        for w in (1154, 1200, 1280, 1440, 1600):
            v = br.measure(pages[("ian", True, theme)], w)
            if not (v["live"] and v["lines"] == 1 and v["btnH"] == 40 and not v["ellipsised"]):
                ian_full.append(f"{theme}/{w}: lines={v['lines']} btn={v['btnH']} "
                                f"ellipsised={v['ellipsised']}")
    check("Ian's own name, WITH the Join pill, renders in FULL on one line at 1154+",
          not ian_full, "; ".join(ian_full[:4]))
    log("")

    log("§D  THE CLAMP COSTS THE ROW NOTHING — the constant, kept honest")
    # ⚠️ THIS IS THE LEG THAT MAKES THE max-width MORE THAN A NUMBER SOMEONE
    # LIKED. The value is derived from the widths of the logo, the nav's
    # min-content and the aside — none of which this gate can pin down forever.
    # So instead of asserting the number, assert its CONSEQUENCE: a 71-character
    # name must cost the row no more horizontal scroll than a 3-character one.
    # Add a seventh nav item and this goes red, which is the correct place for
    # that to surface.
    worse = []
    for theme in THEMES:
        for tester in (True, False):
            for key, _n in NAMES:
                if key == "short": continue
                for w in WIDTHS:
                    v = br.measure(pages[(key, tester, theme)], w)
                    b = base.get((tester, theme, w))
                    if b is not None and v["docScrollW"] > b:
                        worse.append(f"{key}/{'join' if tester else 'nojoin'}/{theme}/{w}: "
                                     f"{v['docScrollW']} vs {b}")
    check("a 71-character name adds NO horizontal scroll over a 3-character one",
          not worse, f"{len(worse)}: {worse[:4]}")
    # Said out loud rather than asserted, because it is true of main too and no
    # name cap can change it: the tester Join pill alone overflows this band.
    v821 = br.measure(pages[("short", True, "light")], 821)
    if v821["docScrollW"] > 821:
        report("PRE-EXISTING (#170, not #173): with the Join pill the row already "
               f"overflows at 821px with a THREE-character name "
               f"(scrollWidth {v821['docScrollW']})",
               "the pill's width, not the name's — filed separately; no name cap can fix it")
    log("")


# ══════════════════════════════════════════════════════════════════════ main
def main():
    global _harness_path
    if shutil.which("php") is None: cannot_run("php is not on PATH")
    tmp = tempfile.mkdtemp(prefix=f"lg-gate87-{os.getpid()}-")
    _harness_path = f"{tmp}/render.php"
    open(_harness_path, "w").write(HARNESS)
    br = None
    try:
        log("=== GATE 87: the header's account chip is ONE LINE, and the name survives it ===\n")
        leg_a()
        leg_b(tmp)
        br = Browser()
        leg_cd(br)
    finally:
        if br: br.close()
        shutil.rmtree(tmp, ignore_errors=True)
    log(f"  {len(_pass)} passed, {len(_fail)} failed")
    if _fail:
        log("\n  FINDINGS:")
        for f in _fail: log(f"    - {f}")
    sys.exit(1 if _fail else 0)

main()
