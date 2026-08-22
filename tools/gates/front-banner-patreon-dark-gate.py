#!/usr/bin/env python3
"""
front-banner-patreon-dark-gate — GATE 80/80 — the logged-out front page drops
its SECONDARY join, and the join funnel is readable in dark.

KEEPER GATE NUMBER: 80, minted by keeper for the 169-front-polish train and
verified free against MAIN before use (main's highest is 79; the number appears
nowhere in run-all.sh or CRAFT-STANDARD). Two lanes have collided minting "9/9"
independently before — do not renumber without asking.

WHAT IT COVERS, and why one gate covers two issues: #169 removes a control from
the anon front page and #171 fixes contrast on the anon join funnel. Both are
answers to the same walk Ian took logged out on 2026-08-20, both are graded on
the same three surfaces, and splitting them would mean two gates arming the same
browser against the same pages.

  #169  Ian, verbatim: "The secondary join on the front page at the top banner
        can go away."  At 1440 logged out there were THREE join doors above the
        fold — the header pill, this strip, and the hero button.
  #171  Ian, verbatim: "dark mode is sucking on the patreon stuff."

⚠️ THE ISSUE NAMED THE WRONG CONTROL, AND THAT IS WORTH KNOWING BEFORE READING
THE ASSERTIONS. #171 names the header's Connect Patreon pill in dark. Measured,
that pill is 11.34:1 in dark with a ~9:1 outline — there is no dark defect on it
at all. There IS a real defect on it, in LIGHT: its outline was 2.72:1 against
the painted header, under WCAG 1.4.11's 3:1. So this gate deliberately grades
BOTH THEMES on every surface rather than the one the issue names — a gate shaped
around the charter's hypothesis would have gone green on the phantom and missed
the real thing one theme over.

── THE FOUR LEGS ────────────────────────────────────────────────────────────
§A  THE FLAG IS READ, NOT HARDCODED (feedback-gate-reads-the-flag-not-a-hardcoded
    -state). Reports the tracked default and exercises OFF and ON regardless, so
    flipping that default needs no edit here.
§B  THE RENDER, per flag state, through the REAL script. OFF emits the banner
    verbatim; ON does not; the MEMBER greeting survives both. Every absence is
    paired with a liveness assertion (feedback-absence-assertion-needs-liveness)
    — "no banner" is trivially true of a 500, a 404, or an empty string.
§C  BOTH COPIES of lg-shortcodes.css carry the dark block. There are two, they
    already differed, and one of them going dark-blind is the exact shape of the
    bug #171 fixes.
§D  THE BROWSER. AA text AND 3:1 component boundaries, anon, both dark paths,
    both widths, on /, /connect-your-patreon/ and /lgjoin.

── WHY §B RENDERS THE PAGE INSTEAD OF GREPPING IT ───────────────────────────
archive-poc/web/index.php's own comment says a gate "cannot require() it to
test" because it is a whole rendered page. That is true of require() and false
of the thing that matters: it renders fine under output buffering once it can
reach its database. So §B runs the REAL script, in its REAL directory (so every
__DIR__ require resolves as it does in production), and diffs bytes. A source
grep for `!$signup_banner_retired` would pass on a file where the flag is read
into a variable that nothing consumes.

⚠️ sudo STRIPS THE ENVIRONMENT, and this gate would be the natural victim: it
drives flag states through env vars across a sudo boundary, and a stripped env
means every state silently runs the OFF path — a gate that grades one state
four times and calls it four passes (feedback-absence-assertion-needs-liveness's
sibling failure). Two defences: `sudo -u … env VAR=…` passes explicitly, and the
ON assertion is "the banner is GONE", which fails loudly if the variable never
arrived. The states cannot collapse into each other quietly.

── WHY §D ADAPTS INSTEAD OF ASSUMING ────────────────────────────────────────
dev2's serve runs MAIN (trap-harness-and-serve-answer-from-main). Before this
branch merges, the CSS under test is NOT what the box serves, so a browser gate
pointed at dev2 would grade main and report a green that says nothing about the
branch. §D therefore FETCHES the served stylesheet and compares it to the file
in this worktree:

  identical  -> POST-MERGE MODE. Grades exactly what the box serves. No injection.
  differs    -> PRE-MERGE MODE. Injects THIS WORKTREE'S REAL FILE BYTES and says
                so, loudly, in the output.

Never a retyped copy of the rules: a retyped copy can pass while the committed
file is wrong, which is the whole failure this mode exists to avoid.

── ANIMATIONS ARE PINNED BEFORE MEASURING, AND HERE IS THE HONEST REASON ────
/lgjoin's recommended button carries `animation: lg-join-pulse 1.2s ease-out 3`
— 3.6 seconds of motion that outlasts any sane settle. While building this, one
pre-merge run of twelve disagreed with the other four at 4 findings, and the
shipped rules do not explain it: that keyframe moves box-shadow ONLY, which
cannot move a text-contrast reading. Rather than average the run away or pad a
threshold — the exact mistake gate 36's own re-baseline comment records someone
making for hours — motion is stopped before the probe walks anything, so a
sampled mid-transition value cannot exist in the first place. This is a DISCLOSED
stabilisation, not a silent cap: what is graded is the page at rest, which is
what a member reads.

Same probe as gate 36 and the dark sweep, on purpose (tools/gates/lib/contrast-
probe.js). A second copy of the colour math could go green on a surface the
sweep photographed as broken, and nobody could tell which number was lying.

Exit 0 = GREEN. Exit 1 = a real finding. Exit 2 = CANNOT RUN — never 3 and never
70: run-all.sh reads anything-non-zero-and-not-2 as RED, so a wrong code reports
a missing environment as a defect and blocks every lane
(trap-gate-exit-code-3-blocks-every-lane).
"""

import base64
import json
import os
import pathlib
import re
import subprocess
import sys
import urllib.request

HERE = pathlib.Path(__file__).resolve().parent
REPO = HERE.parent.parent
INDEX_PHP = REPO / "archive-poc/web/index.php"
FLAG_PHP = REPO / "platform/config/front-signup-banner-retire.php"
CSS_MEMBERSHIP = REPO / "membership-pages/web/lg-shortcodes.css"
CSS_POLLER = REPO / "lg-patreon-stripe-poller/assets/lg-shortcodes.css"
CSS_JOIN = REPO / "membership-pages/web/join.css"
CSS_HEADER = REPO / "lg-shared/site-header.css"

FAILS = []
NOTES = []


def fail(leg, msg):
    FAILS.append(f"{leg}  {msg}")


def note(msg):
    NOTES.append(msg)


def cannot_run(msg):
    print(f"CANNOT RUN  {msg}")
    sys.exit(2)


# ── the render harness ───────────────────────────────────────────────────────
# The DSN is READ FROM THE RUNNING POOL rather than hardcoded here. A second
# copy of a connection string in a gate is a thing that drifts silently and then
# grades a different database than the one the page uses.
def resolve_dsn():
    try:
        out = subprocess.run(
            ["sudo", "grep", "-rh", "LG_ARCHIVE_POC_DSN", "/etc/php/8.3/fpm/pool.d/"],
            capture_output=True, text=True, timeout=30).stdout
        if not out.strip():
            out = subprocess.run(
                ["bash", "-c", "sudo grep -rh LG_ARCHIVE_POC_DSN /etc/php/*/fpm/pool.d/*.conf"],
                capture_output=True, text=True, timeout=30).stdout
        m = re.search(r'env\[LG_ARCHIVE_POC_DSN\]\s*=\s*"?([^"\n]+)"?', out)
        return m.group(1).strip() if m else None
    except Exception:                                          # noqa: BLE001
        return None


PHP_RENDER = r'''
$_SERVER["REQUEST_URI"]="/"; $_SERVER["REQUEST_METHOD"]="GET";
$_SERVER["HTTP_HOST"]="dev2.loothgroup.com";
if (getenv("GATE80_MEMBER") === "1") { $_COOKIE["wordpress_logged_in_gate80"]="probe"; }
ob_start(); include getenv("GATE80_SCRIPT"); echo ob_get_clean();
'''


def render(dsn, script, flag=None, member=False):
    """Render index.php (or a snapshot sibling) as the pool user. Returns bytes."""
    env = ["GATE80_SCRIPT=" + str(script), "LG_ARCHIVE_POC_DSN=" + dsn]
    if member:
        env.append("GATE80_MEMBER=1")
    if flag is not None:
        env.append("LG_FRONT_SIGNUP_BANNER_RETIRE=" + flag)
    p = subprocess.run(["sudo", "-u", "archive-poc", "env"] + env + ["php", "-r", PHP_RENDER],
                       capture_output=True, cwd=str(REPO), timeout=180)
    return p.stdout, p.stderr.decode("utf-8", "replace")


BANNER_OPEN = b'<aside class="signup-banner" role="region" aria-label="Sign up">'
MEMBER_MARK = b'signup-banner--member'


def leg_a_and_b():
    dsn = resolve_dsn()
    if not dsn:
        cannot_run("could not read LG_ARCHIVE_POC_DSN from any FPM pool — refusing to "
                   "guess a connection string and grade the wrong database")

    # ── §A: the flag is READ. Report the tracked default; never assert it.
    try:
        tracked = subprocess.run(
            ["php", "-r", '$c=include $argv[1]; echo (is_array($c)&&!empty($c["enabled"]))?"ON":"OFF";',
             str(FLAG_PHP)], capture_output=True, text=True, timeout=60).stdout.strip()
    except Exception as e:                                     # noqa: BLE001
        cannot_run(f"could not evaluate the tracked flag: {e}")
    print(f"  §A  tracked default reads {tracked}  ({FLAG_PHP.relative_to(REPO)})")
    if tracked not in ("ON", "OFF"):
        fail("§A", f"the tracked config did not evaluate to a boolean state (got {tracked!r})")

    src = INDEX_PHP.read_text()
    # FAIL-CLOSED is a source assertion on purpose. Proving it behaviourally would
    # mean moving the tracked config aside on a live-serving box, and a gate that
    # is interrupted mid-run would leave the front page in whatever state it was
    # halfway through arranging. The env legs below are process-scoped and cannot
    # leak; this one cannot be, so it is graded by reading the initialiser.
    #
    # ⚠️ SCOPED TO THIS READER'S OWN BODY, and that is not fussiness — it is the
    # defect the red-first run found in the first version of this gate. index.php
    # holds TWO flag readers built from the same house pattern: line 177 is
    # lg_front_signup_banner_retired(), line 196 is lg_weekly_front_enabled(), and
    # both contain the identical `$on = is_array($cfg) && !empty($cfg['enabled'])`.
    # A file-wide search was therefore satisfied by the OTHER flag's reader — the
    # mutation that made THIS one fail OPEN left the assertion green
    # (feedback-red-first-that-stays-green, the same class as an assertion matching
    # a string that also lives in prose).
    m = re.search(r'function\s+lg_front_signup_banner_retired\s*\(\s*\)\s*:\s*bool\s*\{(.*?)\n\}',
                  src, re.S)
    if not m:
        fail("§A", "lg_front_signup_banner_retired() is gone from index.php — every source "
                   "assertion below would pass vacuously against the OTHER flag reader in this file")
    else:
        body = m.group(1)
        if not re.search(r'\$on\s*=\s*is_array\(\$cfg\)\s*&&\s*!empty\(\$cfg\[.enabled.\]\)', body):
            fail("§A", "the reader no longer initialises from is_array($cfg) && !empty($cfg['enabled']) — "
                       "an unreadable config must fail CLOSED to today's behaviour (banner shown)")
        if "front-signup-banner-retire.local.php" not in body:
            fail("§A", "the per-box .local.php override is gone — dev2 could not run this ON for Ian "
                       "without dirtying the tracked default")
        if "$_SERVER['LG_FRONT_SIGNUP_BANNER_RETIRE']" not in body.replace('"', "'"):
            fail("§A", "the reader no longer consults $_SERVER — a fastcgi_param lands there and NOT "
                       "reliably in getenv(), so a lane-preview URL would serve the OFF path "
                       "(trap-fastcgi-param-not-in-getenv)")

    # ── §B: the render, per state.
    off, err = render(dsn, INDEX_PHP, flag="0")
    if not off:
        cannot_run(f"the anon OFF render produced nothing — harness is dead, not the page: {err[:300]}")
    on, _ = render(dsn, INDEX_PHP, flag="1")
    default, _ = render(dsn, INDEX_PHP, flag=None)

    # LIVENESS FIRST. Every absence assertion below is worthless without it: "no
    # banner" is true of a 500, a redirect, and an empty buffer alike.
    for label, doc in (("OFF", off), ("ON", on), ("default", default)):
        if len(doc) < 20000 or b"lg-chrome" not in doc or b"</html>" not in doc:
            fail("§B", f"the {label} render is not a live page ({len(doc)} bytes, header "
                       f"{'present' if b'lg-chrome' in doc else 'MISSING'}) — every absence "
                       f"assertion here would pass vacuously")
            return

    if BANNER_OPEN not in off:
        fail("§B", "flag OFF did not emit the banner — OFF must be today's behaviour VERBATIM")
    if BANNER_OPEN in on:
        fail("§B", "flag ON still emits the banner — the retire never happened")

    # ON must differ from OFF by EXACTLY the banner and nothing else. A whole-page
    # byte claim would be false for a dynamic page, so the DELTA is what is graded:
    # anything else riding along on this flag fails here.
    only = off.replace(BANNER_OPEN, b"@@GATE80@@", 1)
    if b"@@GATE80@@" in only:
        start = off.index(BANNER_OPEN)
        end = off.index(b"</aside>", start) + len(b"</aside>")
        stripped = off[:start] + off[end:]
        a = b"".join(stripped.split())
        b = b"".join(on.split())
        if a != b:
            fail("§B", "ON differs from OFF by MORE than the banner block — the flag is carrying "
                       "an unrelated change with it")

    # THE COLLATERAL. The banner is the `if` half of an if/elseif whose `elseif
    # ($is_member)` renders "Welcome back". Gating the wrong half would delete a
    # signed-in member's greeting, and no anon assertion above could ever see it.
    m_off, _ = render(dsn, INDEX_PHP, flag="0", member=True)
    m_on, _ = render(dsn, INDEX_PHP, flag="1", member=True)
    if not m_off or MEMBER_MARK not in m_off:
        fail("§B", "the member greeting is missing with the flag OFF — the authed render is not live, "
                   "so the byte-identity check below would be comparing two broken pages")
        return
    if MEMBER_MARK not in m_on:
        fail("§B", "the flag removed the MEMBER greeting — it must gate the ANON banner only")
    if BANNER_OPEN in m_off or BANNER_OPEN in m_on:
        fail("§B", "a signed-in viewer is being shown the ANON join banner")
    if m_off != m_on:
        fail("§B", f"the authed render is NOT byte-identical across flag states "
                   f"({len(m_off)} vs {len(m_on)}) — this flag must not be able to reach a member")

    # OFF vs MAIN, byte for byte. The snapshot is a SIBLING in the same directory so
    # every __DIR__ require resolves identically; it is never `git checkout --`
    # (feedback-mutation-harness-must-snapshot-not-checkout), and the tracked file
    # is not touched at any point.
    snap = INDEX_PHP.parent / ".gate80-main-snapshot.php"
    try:
        main_src = subprocess.run(["git", "show", "origin/main:archive-poc/web/index.php"],
                                  capture_output=True, cwd=str(REPO), timeout=60)
        if main_src.returncode == 0 and main_src.stdout:
            snap.write_bytes(main_src.stdout)
            main_doc, _ = render(dsn, snap)
            if not main_doc:
                note("§B  could not render origin/main's index.php — the OFF-equals-main byte "
                     "comparison was SKIPPED (reported, not silently passed)")
            elif main_doc != default:
                # ⚠️ NARROWED BY #200, 2026-08-22, and the reason matters more than
                # the change. This compared the WHOLE PAGE against origin/main, so
                # it went RED the moment ANY other lane legitimately altered the
                # front page — a cross-branch whole-page equality assertion is
                # structurally a merge-blocker, not a flag check. #200 tripped it
                # by adding the empty-pool fallback band that Ian ruled must
                # exist, and the measured diff was that band and nothing else.
                #
                # THE PROPERTY THIS LEG ACTUALLY PROTECTS is that the
                # front-signup-banner-retire flag changes nothing outside its own
                # banner, so that is what is asserted: the banner region, byte for
                # byte. A difference elsewhere is reported by NAME and by size, so
                # it is visible and attributable rather than either silent or a
                # blanket red. Same restatement discipline #200 applied to gate 39
                # §C3 and §F3 — assert the property, not one spelling of it.
                def _banner(doc):
                    i = doc.find(BANNER_OPEN)
                    if i < 0:
                        return None
                    j = doc.find(b"</aside>", i)
                    return doc[i:j + 8] if j > 0 else doc[i:]
                b_main, b_mine = _banner(main_doc), _banner(default)
                if b_main != b_mine:
                    fail("§B", f"the DEFAULT render's SIGNUP BANNER differs from origin/main "
                               f"({len(b_mine or b'')} vs {len(b_main or b'')} bytes) — this flag's "
                               f"OFF state is not a no-op in the region it governs")
                else:
                    note(f"§B  the banner region is byte-identical to origin/main, but the page as "
                         f"a whole is not ({len(default)} vs {len(main_doc)} bytes, "
                         f"{len(default) - len(main_doc):+d}). That is another lane's front-page "
                         f"change, not this flag's — reported so it is attributable, never graded")
            else:
                print(f"  §B  OFF == origin/main, byte for byte ({len(main_doc)} bytes)")
        else:
            note("§B  origin/main:archive-poc/web/index.php was unreadable — byte comparison SKIPPED")
    finally:
        try:
            snap.unlink()
        except FileNotFoundError:
            pass
    print(f"  §B  anon OFF {len(off)}B (banner present) · ON {len(on)}B (banner gone) · "
          f"authed identical {len(m_off)}B")


# ── §C ───────────────────────────────────────────────────────────────────────
# The two copies are NOT compared byte for byte: they already differed by four
# lines before this change and syncing them wholesale would be a change nobody
# asked for. What is asserted is that neither is dark-blind, which is the defect.
DARK_SELECTORS = [".lg-join__tier", ".lg-join__buy", ".lg-join__tier-badge",
                  ".lg-pay-methods__label", ".lg-join__feature"]


def leg_c():
    for css in (CSS_MEMBERSHIP, CSS_POLLER):
        if not css.exists():
            fail("§C", f"{css.relative_to(REPO)} is missing")
            continue
        text = css.read_text()
        if "data-lguser-theme" not in text:
            fail("§C", f"{css.relative_to(REPO)} has NO dark rules at all — this is the exact "
                       f"state /lgjoin shipped in (0 occurrences, measured 2026-08-20)")
            continue
        for sel in DARK_SELECTORS:
            if not re.search(r"data-lguser-theme=.dark.\]\s*" + re.escape(sel) + r"\b", text):
                fail("§C", f"{css.relative_to(REPO)}: no dark rule for {sel}")
    jt = CSS_JOIN.read_text() if CSS_JOIN.exists() else ""
    # `\b` after the class name was NOT enough and the red-first run proved it:
    # deleting the BASE rule left `.lg-join__cta:hover`, which `\b` happily matched
    # (the boundary sits between "a" and ":"), so the gate stayed green on exactly
    # the deletion it exists to catch. The base rule is required explicitly.
    if not re.search(r"data-lguser-theme=.dark.\]\s*\.lg-join__cta\s*\{", jt):
        fail("§C", "join.css has no dark rule for .lg-join__cta — the primary button on the "
                   "Patreon page sits at a 1.05:1 boundary against its own dark card "
                   "(a :hover rule alone does not count: the button is not hovered at rest)")
    ht = CSS_HEADER.read_text() if CSS_HEADER.exists() else ""
    # ⚠️ SCOPED TO THE PILL'S OWN RULE. A bare `"#6b7c52" not in ht` was the worst
    # of the three vacuous assertions the red-first found: that hex is --lg-sage-d's
    # value and appears THIRTY-THREE times in this stylesheet, so the check could
    # never fail no matter what happened to the pill. Reverting the nudge now
    # reddens, because what is asserted is the value INSIDE .lg-chrome__connect's
    # box-shadow.
    # ANCHORED AT LINE START, because "scoped" is not the same as "scoped to the
    # right thing": an unanchored search matched the DARK restore at line 64 —
    # which legitimately has no box-shadow — long before reaching the base rule at
    # 243, and reddened a correct tree. A false RED on working code costs what a
    # miss costs.
    pill = re.search(r"^\.lg-chrome__connect\s*\{(.*?)\}", ht, re.S | re.M)
    if not pill:
        fail("§C", ".lg-chrome__connect's base rule is gone from site-header.css")
    elif not re.search(r"box-shadow:[^;]*#6b7c52", pill.group(1)):
        fail("§C", "the Connect Patreon pill's light-mode outline nudge (#6b7c52, 3.95:1) is gone — "
                   "it was 2.72:1 on --lg-sage, under WCAG 1.4.11's 3:1 for a component boundary")
    if not re.search(r"data-lguser-theme=.dark.\]\s*\.lg-chrome__connect\s*\{\s*box-shadow", ht):
        fail("§C", "the pill's DARK outline restore is gone — without it the light-mode nudge "
                   "follows the pill into dark, where it was never broken")
    print(f"  §C  both lg-shortcodes.css copies carry the dark block "
          f"({CSS_MEMBERSHIP.read_text().count('data-lguser-theme')} / "
          f"{CSS_POLLER.read_text().count('data-lguser-theme')} rules)")


# ── §D ───────────────────────────────────────────────────────────────────────
SURFACES = [("front", "/", [CSS_HEADER]),
            ("connect", "/connect-your-patreon/", [CSS_JOIN, CSS_HEADER]),
            ("lgjoin", "/lgjoin", [CSS_MEMBERSHIP, CSS_HEADER])]

# Gate 36's recorded, disclosed debt on the front page: ten Guitardle-leaderboard
# findings that belong to a different surface entirely. Carried here so this gate
# grades ITS OWN scope and does not re-report another lane's backlog as a finding
# — and scoped by SELECTOR, not by a count, so a NEW front-page defect still
# fails even though the ten are allowed.
OUT_OF_SCOPE_SEL = ("gdle-", "guitardle")

# ⚠️ MOTION IS KILLED AS *CSS*, INSIDE THE INJECTED BLOCK, SO IT LANDS BEFORE THE
# PROBE WALKS — and the ordering is the entire point. The first version of this
# gate stopped motion with a script AFTER measure() had already probed, and
# reported four Subscribe buttons at 1.25:1 on a branch whose fix demonstrably
# works. The cause is not the pulse animation (that moves box-shadow only, which
# cannot move a text reading) — it is `transition: background 0.15s` on
# .lg-join__buy. Adding a stylesheet STARTS a transition from #fff to the dark
# fill, and getComputedStyle during a transition returns the INTERPOLATED value,
# which early in the flight is still almost #ffffff. The probe was photographing
# the fade, not the page, and the reading it produced was indistinguishable from
# the real defect this gate exists to catch.
#
# This is also the honest explanation for the one pre-merge run in five that
# disagreed while #171 was being built. It was never load, and averaging it away
# would have left a gate that reds at random — which blocks every lane's train
# and gets a gate disbelieved, the worst outcome available.
STOP_MOTION_CSS = "*,*::before,*::after{animation:none!important;transition:none!important}"

BOUNDARY_JS = r"""
function g80eff(el){var n=el;while(n&&n.nodeType===1){var c=parseColor(getComputedStyle(n).backgroundColor);
  if(c&&c.a>0.999)return c;n=n.parentElement;}return parseColor('rgb(255,255,255)');}
var G80=[['.lg-join__buy','Subscribe button'],['.lg-join__cta','Patreon CTA'],
         ['.lg-chrome__connect','Connect Patreon pill']];
var g80out=[];
G80.forEach(function(p){
  document.querySelectorAll(p[0]).forEach(function(el){
    var r=el.getBoundingClientRect(); if(!r.width||!r.height) return;
    var cs=getComputedStyle(el), behind=g80eff(el.parentElement||document.body);
    var own=parseColor(cs.backgroundColor);
    var fill=(own&&own.a>0.01)?over(own,behind):behind;
    var edge=null,bw=parseFloat(cs.borderTopWidth)||0;
    if(bw>0) edge=parseColor(cs.borderTopColor);
    else if(cs.boxShadow&&cs.boxShadow!=='none'){var m=cs.boxShadow.match(/rgba?\([^)]+\)/);if(m)edge=parseColor(m[0]);}
    // A control reads as a control if EITHER its fill or its edge separates it
    // from the surface behind it by 3:1 (WCAG 1.4.11). Not both — a filled
    // button needs no border, and an outline button has no fill.
    var fr=ratio(fill,behind), er=edge?ratio(edge,behind):0;
    g80out.push({what:p[1],sel:p[0],fill:hex(fill),behind:hex(behind),
                 fillRatio:+fr.toFixed(2),edgeRatio:+er.toFixed(2),
                 ok:(fr>=3.0||er>=3.0)});
  });
});
return g80out;
"""


def leg_d():
    try:
        import websocket                                        # noqa: F401
    except ImportError:
        cannot_run("python3-websocket-client required for §D")
    sys.path.insert(0, str(HERE))
    import importlib.util
    spec = importlib.util.spec_from_file_location("g36", str(HERE / "anon-dark-contrast-gate.py"))
    g36 = importlib.util.module_from_spec(spec)
    sys.modules["g36"] = g36
    try:
        spec.loader.exec_module(g36)
    except SystemExit:
        cannot_run("gate 36's module refused to load (its own environment check failed)")

    env = g36.gate_env()
    host, tok = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"]
    probe = pathlib.Path(g36.PROBE).read_text()
    math = probe[probe.index("function parseColor"):probe.index("function desc(")]

    # PRE- vs POST-MERGE. Ask the box what it is serving instead of assuming.
    #
    # The stylesheet URLs are DISCOVERED from each page's own <link> tags rather
    # than written down here. Hardcoding them was tried and was wrong within the
    # hour — the membership app serves from /membership-pages/, not the
    # /lg-membership/ its constant name suggests, and a 404 read as "differs from
    # the branch", which would have put this gate in PRE-MERGE MODE forever and
    # quietly stopped it ever grading the real served bytes.
    #
    # ⚠️ FETCHED THROUGH THE BROWSER, NOT urllib. From this box, dev2.loothgroup
    # .com resolves to the PUBLIC address, where Cloudflare bot-challenges a bare
    # request into a 403 that reads exactly like "file missing" — every fetch here
    # failed that way on the first run. chrome-dev already carries
    # --host-resolver-rules and the gate cookie, so the browser is the channel
    # that actually reaches the box (trap-locked-out-browser-goes-vacuously-green
    # is the same hazard from the other end).
    s = g36.Session()
    try:
        s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
        g36.arm_anon(s, tok)
        s.goto(host + "/", settle=0.8)

        def fetch(url):
            return s.js("(async()=>{try{const r=await fetch(%s,{credentials:'include'});"
                        "return r.ok?await r.text():null}catch(e){return null}})()" % json.dumps(url),
                        quiet=True)

        modes = {}
        for key, path, cssfiles in SURFACES:
            page = fetch(host + path) or ""
            links = re.findall(r'<link[^>]+href="([^"]+\.css[^"]*)"', page)
            inject = []
            for f in cssfiles:
                hit = next((l for l in links if l.split("?")[0].endswith("/" + f.name)), None)
                served = fetch(host + hit) if hit and hit.startswith("/") else None
                if served is None:
                    note(f"§D  {key}: could not read a served copy of {f.name} — injecting this "
                         f"worktree's bytes and saying so, rather than grading main as if it were "
                         f"this branch")
                    inject.append(f)
                elif served.strip() != f.read_text().strip():
                    inject.append(f)
            modes[key] = inject
        premerge = sorted({f.name for v in modes.values() for f in v})
        if premerge:
            print(f"  §D  PRE-MERGE MODE — the box is not serving this branch's "
                  f"{', '.join(premerge)}; injecting this worktree's REAL FILE BYTES")
        else:
            print("  §D  POST-MERGE MODE — grading exactly what the box serves")

        for device, metrics in (("desktop", g36.DESKTOP), ("mobile", g36.PHONE)):
            for mode in ("app-dark", "os-dark"):
                for key, path, _cf in SURFACES:
                    label = f"{key}/{mode}/{device}"
                    # STOP_MOTION_CSS is ALWAYS appended, in both modes — a page
                    # can be mid-transition on its own after a cold navigation,
                    # not only after an injection.
                    css = "\n".join(p.read_text() for p in modes[key])
                    css = (css + "\n" + STOP_MOTION_CSS).strip()
                    data = None
                    # ONE retry on an unresolved theme, same shape and same reason
                    # as gate 36's: os-dark resolves CLIENT-SIDE with no boot
                    # script, so under contention a single surface can be
                    # photographed mid-boot while the rest of the run is fine.
                    for attempt in range(2):
                        try:
                            data = g36.measure(s, tok, host, probe, key, path, mode, device,
                                               metrics, extra_css=css)
                        except Exception as e:                  # noqa: BLE001
                            if attempt == 1:
                                fail("§D", f"{label}: probe failed twice — {str(e)[:120]}")
                                data = None
                            continue
                        if data.get("theme") == "dark":
                            break
                        data = None
                    if data is None:
                        if not any(label in f for f in FAILS):
                            fail("§D", f"{label}: the theme never resolved on either attempt, so "
                                       f"these numbers are worthless in BOTH directions — a 0 is "
                                       f"unearned and a >0 is a phantom")
                        continue
                    mine = [f for f in data.get("findings", [])
                            if not any(t in f["sel"] for t in OUT_OF_SCOPE_SEL)]
                    for f in mine:
                        fail("§D", f"{label}: {f['kind']} {f['ratio']}:1 "
                                   f"{f['fg']} on {f['bg']}  [{f['sel'][-60:]}]  {f.get('text','')[:30]!r}")
                    # Boundaries — the bar gate 36 structurally cannot see.
                    # Motion is already dead (it went in as CSS above), so this
                    # measures the settled page, not a fade.
                    bounds = s.js("(function(){" + math + BOUNDARY_JS + "})()", quiet=True) or []
                    for b in bounds:
                        if not b["ok"]:
                            fail("§D", f"{label}: {b['what']} has no visible boundary — fill "
                                       f"{b['fillRatio']}:1 and edge {b['edgeRatio']}:1 against "
                                       f"{b['behind']}, both under 3:1 (WCAG 1.4.11)")
                    seen = len(bounds)
                    if key in ("lgjoin", "connect") and seen == 0:
                        fail("§D", f"{label}: found NO controls to grade — an all-clear here would "
                                   f"be an all-clear about an empty page")
                    print(f"  §D  {label}  {len(mine)} finding(s) in scope, {seen} boundary check(s)")
    finally:
        try:
            s.finish()
        except Exception:                                       # noqa: BLE001
            pass


def main():
    print("=== GATE 80: front banner retired · join funnel readable in dark ===")
    if os.environ.get("GATE80_ONLY") != "D":
        leg_a_and_b()
        leg_c()
    if os.environ.get("GATE80_SKIP_D") != "1":
        leg_d()
    else:
        note("§D was skipped by GATE80_SKIP_D=1 — the browser leg did not run")

    for n in NOTES:
        print("  note " + n)
    if FAILS:
        print(f"\n{len(FAILS)} finding(s):\n")
        for f in FAILS:
            print("FAIL " + f)
        sys.exit(1)
    # The green line names ONLY the legs that actually ran. A summary that claims
    # §D's result on a run where §D was skipped is a gate lying about its own
    # coverage — the same shape as an absence assertion with no liveness beside it,
    # and it would let a GATE80_SKIP_D=1 run be quoted as "the funnel is readable".
    ran_abc = os.environ.get("GATE80_ONLY") != "D"
    ran_d = os.environ.get("GATE80_SKIP_D") != "1"
    parts = []
    if ran_abc:
        # Reworded by #200 with the §B narrowing above: the leg now proves the
        # BANNER REGION matches main, not the whole page, and a summary claiming
        # more than the assertion made is the stale-artifact failure this repo
        # keeps paying for.
        parts.append("the anon banner follows its flag (and its region is byte-identical to main), the "
                     "member greeting is untouched in both states, and both stylesheet copies "
                     "carry the dark block")
    if ran_d:
        parts.append("the join funnel clears AA text plus 3:1 boundaries in both dark paths at "
                     "both widths")
    print("\nGREEN — " + "; ".join(parts) + ".")
    if not (ran_abc and ran_d):
        print("      ⚠️ PARTIAL RUN: only " + ("§D" if not ran_abc else "§A–§C") +
              " executed. This is not a full pass and must not be quoted as one.")
    sys.exit(0)


if __name__ == "__main__":
    main()
