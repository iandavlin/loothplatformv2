#!/usr/bin/env python3
"""
header-join-gate — GATE 79 — where does a LOGGED-OUT visitor's "Join" go, and
can they actually get there?  (Number minted by keeper 2026-08-20; lanes never
self-mint.)

WHY THIS GATE EXISTS.

Ian, 2026-08-20, verbatim on issue #165:

    "can you Wire the header on Dev2 to have the stripe menuing that a logged
     out user would see?"

Until now the anon header's Join went straight to patreon.com — his own ruling
of 2026-06-12, and right for a Patreon-only world. With the Stripe rail built,
a logged-out visitor should land on our own two-tier /lgjoin/, where both rails
are on offer. Behind platform/config/header-join-stripe.php, default OFF.

⚠️ THE FIRST THING TO KNOW: THE OBVIOUS ASSERTION IS NOT THE ONE THAT BITES.

"With the flag ON, Join points at /lgjoin/" is easy, and it is nearly worthless
on its own, because it is true of a build in which /lgjoin/ hands an anonymous
visitor a page reading "This page isn't available yet". That is not a
hypothetical — it is the MEASURED state of dev2 on the day this was written.
membership-pages/web/router.php lists lgjoin as ['lgjoin.php','testgroup',
'public'] and the wp_option `lgms_stripe_pages_live` picks the column; while
that option is off the PRE-LAUNCH column applies and anon gets the stub. So a
gate that stopped at the href would go green on a Join button that is wired
perfectly and lands nowhere — the presence-is-not-reachability class, in the
one place it costs a sale. §E is that assertion, and it is why this file is not
a one-liner.

WHAT IT ASSERTS — five legs, and the three cheap ones cannot flake:

  §A SOURCE. One anon join anchor; its href comes from the reader, never a
     literal on the ON path; the reader consults getenv() AND $_SERVER; the
     .local.php override is honoured; the tab behaviour is derived from the
     href rather than from the flag. Run on COMMENT-STRIPPED tokens (via PHP's
     own tokenizer), so prose in a docblock can never satisfy an assertion —
     the red-first-that-stays-green class has cost this repo six findings.

     AND IT EXECUTES bottom-nav's tab rule rather than only reading it. That is
     not belt-and-braces: §D drives the real origin, the real origin serves
     MAIN, so §D's PWA-sheet legs read MAIN's bottom-nav.js and cannot exercise
     this branch at all. They pass today because main sets target
     unconditionally AND main's href is external, which makes "target iff
     external" accidentally true — a green that says nothing about the diff
     (trap-harness-and-serve-answer-from-main). So the branch's own guard line
     is lifted out and RUN in node against both destinations.

  §B RENDER, ALL THREE STATES. The partial is executed under php with a fixed
     anonymous ctx against a config that is absent / OFF / ON. Absent and OFF
     emit the patreon href WITH target="_blank"; ON emits /lgjoin/ WITHOUT it.
     Reads the tracked default and reports it, but asserts every state
     regardless, so flipping the default needs no edit here
     (feedback-gate-reads-the-flag-not-a-hardcoded-state).

  §C OFF IS BYTE-IDENTICAL TO MAIN, PROVEN NOT ARGUED. The same ctx is rendered
     through `git show origin/main:lg-shared/site-header.php` and through the
     branch, and the two outputs are compared BYTE FOR BYTE. "OFF is a no-op"
     going unasserted and then drifting is the recurring failure class here, so
     it is a fact this gate holds rather than a claim in a docblock. Asserted
     for the ABSENT config too: a deploy that lands the code before the config
     must also be a no-op. And asserted for an AUTHED ctx in every state — the
     flag must not be able to reach a signed-in member at all.

  §D REACHABLE, IN A REAL BROWSER, AT EVERY WIDTH, IN BOTH THEMES. Presence is
     not reachability: the control must be styled, sized, inside the viewport
     and the thing elementFromPoint returns at its own centre. Widths bracket
     the two breakpoints that have already produced a lockout here (821/820 for
     archive.css, 641/640 for site-header.css).

     THE PART THAT MAKES §D COVER A STATE THE ORIGIN CANNOT SERVE. dev2 serves
     main, so a lane cannot photograph its own ON state there
     (trap-harness-and-serve-answer-from-main). Rather than assume the ON state
     is reachable because the OFF state is, the leg SWAPS the href in the live
     document and re-measures: if getComputedStyle is unchanged across the swap
     and the control is still hit-testable, then presentation does not depend on
     the href and the measurement transfers. That is evidence, not an argument —
     and it would catch a `[href^="http"]` rule in some other stylesheet, which
     is exactly the kind of cross-file cascade that no grep of site-header.css
     could ever see.

     THE PWA SHEET IS THE PHONE'S JOIN. webroot/bottom-nav.js builds a second
     anon Join in the account sheet, reading the header's href so it cannot
     drift. Its target="_blank" was UNCONDITIONAL — harmless while Join could
     only be patreon.com, and the moment it can be /lgjoin/ it sends a member
     out of the installed PWA (manifest.json is display:standalone, so there is
     no browser chrome to come back through) to buy a membership in a browser
     tab. Asserted at <=640 in both states.

  §E THE COUPLING — the destination must ADMIT the visitor being sent to it.
     While the DEPLOYED flag is ON this is a hard assertion: anon GET /lgjoin/
     must not be the pre-launch stub. While it is OFF the leg REPORTS and does
     not assert, so it never reddens a lane that has nothing to do with it. A
     dead surface must not pass as a green one
     (trap-locked-out-browser-goes-vacuously-green), and a gate that reds on
     non-defects blocks every lane, which is the harm it exists to prevent.

ANON REALLY MEANS ANON. Every network leg goes through
tools/exercise-harness/real-origin-proxy.py with --cookies /dev/null, so the
dev gate cookie is injected server-side and NO WP session exists. The browser
legs run in a fresh incognito browser context per width, which is also what
keeps a theme stamp out of the shared chrome profile
(trap-mock-theme-stamp-poisons-shared-chrome) and stops a duplicate host-only
vs dotted session cookie executing the run as somebody else
(trap-shared-chrome-profile-duplicate-session-cookies).

WHAT IT CANNOT DO, said plainly rather than papered over: it cannot prove Ian
likes the join page. It asserts he can reach it and that it is not the stub.

Needs: php (§A–C, and nothing else); chrome-dev on 127.0.0.1:9222 +
python3-websocket (§D only); the dev gate token (§D, §E).

Usage:
    python3 tools/gates/header-join-gate.py               # everything
    python3 tools/gates/header-join-gate.py --no-browser  # §A–C, §E only

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.

⚠️ CANNOT RUN IS 2, NOT 3. run-all.sh reads 0 green, 2 no-verdict and ANYTHING
ELSE as RED, so a gate that exits 3 where it merely could not run turns the
whole suite red for every lane (trap-gate-exit-code-3-blocks-every-lane).
"""
import argparse
import difflib, json, os, re, shutil, socket, subprocess, sys, tempfile, time, urllib.request

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
GATE_ENV = f"{REPO}/tools/gates/gate-env.sh"
PROXY    = f"{REPO}/tools/exercise-harness/real-origin-proxy.py"
CDP      = "http://127.0.0.1:9222"
NO_VERDICT = 2

HEADER   = "lg-shared/site-header.php"
CONFIG   = "platform/config/header-join-stripe.php"
BOTTOM   = "webroot/bottom-nav.js"
FLAGS_MD = "docs/FLAGS.md"

PATREON = "https://www.patreon.com/c/theloothgroup/membership"
LGJOIN  = "/lgjoin/"

# 821/820 straddle archive.css's breakpoint; 641/640 straddle site-header.css's
# and the bottom bar's. A gate sampling only "desktop and phone" would have
# passed on the day the 641-820 sign-in lockout shipped (gate 12's scar). If a
# breakpoint moves, move these brackets with it.
WIDTHS = [1440, 900, 821, 820, 700, 641, 640, 390]
DARK_AT = [1440, 390]          # theme cannot change an href; it can change paint

# ── THE BASELINE RATCHET, and why this gate has one ─────────────────────────
#
# §D measures the DEPLOYED origin, and the deployed origin is main — a lane's
# own branch is deployed nowhere (trap-harness-and-serve-answer-from-main). So
# any GEOMETRY failure §D sees is by construction a statement about main, not
# about the branch under test. Asserting it outright would red this gate for
# every lane over a defect none of them caused, which is precisely the harm a
# gate is supposed to prevent.
#
# Dropping the width instead would hide a real defect. So: a named, measured,
# SELF-EXPIRING allowance. Widths listed here are reported and not scored; every
# OTHER width is a hard assertion, so the list cannot grow silently. And if a
# listed width starts PASSING, that is a FAIL telling you to delete the entry —
# an allowance that outlives its defect is how a gate quietly stops meaning
# anything.
KNOWN_MAIN_GAPS = {
    821: ("MEASURED ON MAIN 2026-08-20, /hub/ at 821px: the anon Join pill sits at "
          "x=845 w=59 in an 821px viewport — entirely past the right edge, with "
          "document.scrollWidth 905. The nav collapses into the hamburger at <=820, "
          "so 821 up to roughly 904 is a band where the full nav and the anon "
          "cluster cannot share one row and Join is pushed out of view. Connect "
          "Patreon (x=649..797) still fits; Join is last, so Join is what goes. "
          "The front page is milder: clipped by ~23px but its centre is still in "
          "the viewport. SAME CLASS as gate 12's 641-820 sign-in dead band, one "
          "band over, and PRE-EXISTING — this lane changed one href and one "
          "attribute and cannot move a layout. Reported to Ian on #165."),
}

PATH = "/hub/"   # keeps its query string — the front page's enterDiscover()
                 # replaceState wipes every param (trap-front-page-wipes-query-params)

passes = failures = 0
findings = []

def log(*a): print(" ".join(str(x) for x in a), flush=True)

def check(label, ok, detail=""):
    global passes, failures
    if ok: passes += 1
    else:
        failures += 1
        findings.append(f"{label}   {detail}".strip())
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + (f"   {detail}" if detail and not ok else ""))
    return ok

def report(label, detail=""):
    """Neither a pass nor a finding — a measured fact this gate refuses to
    score, with the reason it refuses stated in the same line."""
    log(f"  REPORT  {label}" + (f"   {detail}" if detail else ""))

def cannot_run(why):
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)

def git(*a):
    r = subprocess.run(["git", "-C", REPO, *a], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"git {' '.join(a)}: {r.stderr.strip()[:200]}")
    return r.stdout


# ─────────────────────────────────────────────────────────── comment stripping
# PHP's own tokenizer, not a regex. A regex that "removes comments" gets /* */
# inside a string literal wrong in both directions, and this gate's whole
# credibility rests on an assertion being unsatisfiable by prose.
STRIP = r'''
$src = file_get_contents($argv[1]);
$out = '';
foreach (token_get_all($src) as $t) {
    if (is_array($t)) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= "\n"; continue; }
        $out .= $t[1];
    } else { $out .= $t; }
}
echo $out;
'''

def php_code(path):
    """The file with every PHP comment removed, so prose cannot satisfy an
    assertion. Inline HTML survives — it is markup, not commentary."""
    r = subprocess.run(["php", "-r", STRIP, path], capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"could not tokenize {path}: {r.stderr.strip()[:200]}")
    return r.stdout

def js_code(path):
    """JS with // and /* */ comments removed. Deliberately crude but safe for
    the one function this gate reads: it only ever ASKS whether a token is
    present, so an over-eager strip can produce a false RED and never a false
    green, and a false RED here is loud and instantly diagnosable."""
    src = open(path, encoding="utf-8").read()
    src = re.sub(r"/\*.*?\*/", "\n", src, flags=re.S)
    src = re.sub(r"(?m)^\s*//.*$", "", src)
    return src


# ══════════════════════════════════════════════════════════════════════ §A
def leg_a():
    log("§A  SOURCE — the href is resolved, not written down (comment-stripped)")
    hdr_path = f"{REPO}/{HEADER}"
    if not os.path.isfile(hdr_path):
        cannot_run(f"{HEADER} not found under {REPO}")
    code = php_code(hdr_path)

    # ⚠️ ONE join anchor again. It was two from #170 (anon + the signed-in
    # tester's pill) until #196 ruled the signed-in one out — Ian, 2026-08-22:
    # "Why is there a superfluous join button for logged in users now." A
    # SECOND would now mean the ruled-out control is back; a THIRD would mean a
    # third place to forget. The signed-in door is the account-menu entry, which
    # carries no lg-chrome__join class and is asserted separately below.
    anchors = re.findall(r'<a class="lg-chrome__join"[^>]*>', code)
    check("the header emits exactly ONE join anchor, and it is the anon one (#196)",
          len(anchors) == 1, f"found {len(anchors)}: {anchors}")

    # BOTH hrefs must be PHP expressions, not literals. This is the assertion
    # that catches "someone hardcoded /lgjoin/ and deleted the flag" — a change
    # that would look like the feature working, on every page, for everybody,
    # with no way back. Asserted per-anchor rather than on the first, because
    # the copy nobody is looking at is the one that rots.
    for i, a in enumerate(anchors):
        check(f"join anchor {i+1}: href resolved at render time, not a literal URL",
              "<?= $h($join_href) ?>" in a or "$join_href" in a, a[:160])
        check(f"join anchor {i+1}: neither destination written into the anchor itself",
              PATREON not in a and "lgjoin" not in a.lower(), a[:160])

    # ── #170's TESTER PILL WAS RULED OUT ON 2026-08-22 BY #196 ───────────────
    #
    # Ian, decision box, on seeing it beside his own chip: "Why is there a
    # superfluous join button for logged in users now." The signed-in Join door
    # is the ACCOUNT MENU ENTRY and nothing else.
    #
    # ⚠️ INVERTED, NOT DELETED — the same discipline as gate 86 §I9, and for the
    # same reason. These two assertions used to say "the pill exists and is
    # confined to allowlist"; deleting them would leave the state they policed
    # unwatched, so they now say the pill CANNOT exist for a signed-in viewer.
    # The scar they were protecting against is unchanged and still live: before
    # #170, .lg-chrome__join rendered for ANON ONLY, so an href swap "for a test
    # user" changed a control no test user could see. That is now the CORRECT
    # state, and it is what these assert.
    check("no signed-in Join pill is drawn at all (#196 ruled it out)",
          "$join_pill_authed" not in code,
          "the signed-in door is the account menu entry")
    check("...and the pill markup is reached only from the ANON aside",
          len(re.findall(r'class="lg-chrome__join"', code)) == 1,
          f'{len(re.findall(chr(0x22) + "lg-chrome__join" + chr(0x22), code))} pill anchor(s) in the file')

    # LIVENESS BESIDE THE ABSENCE. "No pill" is trivially true of a file that
    # stopped drawing a header at all (feedback-absence-assertion-needs-liveness).
    check("liveness: the anon aside still draws its own Join pill",
          re.search(r'<a class="lg-chrome__join" href="<\?= \$h\(\$join_href\)', code) is not None)

    # AND THE DOOR IT WAS REPLACED BY MUST EXIST, or the ruling would have left
    # a signed-in tester with no Join anywhere — the very no-op #170 was built
    # to avoid, arrived at from the other side.
    check("the signed-in tester's Join lives in the account menu, with the hook "
          "the PWA sheet reads",
          'class="lg-chrome__menu-join"' in code)

    # THE COHORT IS READ ONCE, ELSEWHERE. A second definition of "a test user"
    # is how the two ends of a fence drift apart — the header must keep asking
    # the capability the poller already computes, never the option itself.
    check("the header defines NO second cohort list",
          "lgms_stripe_lifecycle_allowlist" not in code
          and "inCohort" not in code
          and "get_option" not in code,
          "it must read $caps['stripe_testgroup'], which rides whoami")
    check("the allowlist branch keys on the EXISTING stripe_testgroup capability",
          "$caps['stripe_testgroup']" in code and "$stripe_tester" in code)

    # The reader. Each clause has its own scar attached in the config docblock.
    check("the reader reads the tracked config",
          "header-join-stripe.php" in code)
    check("the reader honours the gitignored .local.php box override",
          "header-join-stripe.local.php" in code)
    check("the .local override wins only on an EXPLICIT boolean true",
          re.search(r"array_key_exists\(\s*'enabled'\s*,\s*\$cfg\s*\)", code) is not None
          and re.search(r"\$cfg\['enabled'\]\s*===\s*true", code) is not None,
          "the strict half of the shared resolver, applied to the .local file")

    # ── #170: three states, and the reader knows exactly three ───────────────
    check("the reader returns a STATE, not a boolean",
          "function lg_shared_header_join_stripe_state(): string" in code)
    check("and the three states are the only three",
          re.search(r"\$valid\s*=\s*array\(\s*'off'\s*,\s*'allowlist'\s*,\s*'on'\s*\)", code)
          is not None)
    check("an unrecognised state word falls to 'off', never to a guess",
          re.search(r"in_array\(\s*\$s\s*,\s*\$valid\s*,\s*true\s*\)\s*\?\s*\$s\s*:\s*'off'", code)
          is not None)

    # ⚠️ THE MIGRATION IS LOAD-BEARING, NOT LEFTOVERS. dev2's hand-placed
    # .local.php says `enabled => true` and lives in the SERVING CHECKOUT, which
    # no lane may edit. A tidy-up that dropped the legacy key would revert
    # dev2's header to patreon.com on the next `pull --ff-only`, with nobody
    # having flipped anything and nothing in any diff to explain it. §B proves
    # the behaviour; this proves the intent is written down where it is deleted.
    check("the legacy 'enabled' key is still read (dev2's .local.php depends on it)",
          re.search(r"array_key_exists\(\s*'enabled'\s*,\s*\$cfg\s*\)", code) is not None)
    check("the back-compat boolean shim is still exported",
          "function lg_shared_header_join_stripe_enabled(): bool" in code)
    # A fastcgi_param lands in $_SERVER but not reliably in the environment, so
    # a getenv()-only reader serves the OFF path on the very preview URL built
    # for Ian to click (trap-fastcgi-param-not-in-getenv).
    check("the preview override is read from getenv() AND $_SERVER",
          "getenv('LG_HEADER_JOIN_STRIPE')" in code
          and "$_SERVER['LG_HEADER_JOIN_STRIPE']" in code)

    # Tab behaviour derived from the DESTINATION, never from the flag. Written
    # the other way, a later change of destination silently keeps whichever tab
    # behaviour the flag happened to imply.
    check("the header derives target=_blank from the href, not from the flag",
          re.search(r"\$join_external\s*=.*preg_match.*https\?://", code) is not None
          and "$join_external ?" in code)

    # ── #170: THE TESTER PILL MUST INHERIT THE ANON PILL'S PRESENTATION ─────
    # Same class is NOT by itself evidence — a class can be styled only in a
    # context the new copy is not in, and then the control renders looking like
    # nothing, wearing a class with no rule that applies
    # (trap-class-name-assertion-passes-on-the-defect). So assert the property
    # that actually matters: every stylesheet that styles .lg-chrome__join does
    # it with an UNSCOPED selector, with no combinator tying it to the anon
    # cluster's siblings. §D already measures that one pill's real presentation
    # in a browser; this is what makes that measurement transfer to the other.
    scoped = []
    for root, _dirs, files in os.walk(REPO):
        if "/.git" in root: continue
        for fn in files:
            if not fn.endswith(".css"): continue
            fp = os.path.join(root, fn)
            try: css = open(fp, encoding="utf-8", errors="replace").read()
            except OSError: continue
            # ⚠️ COMMENTS FIRST — this scan reads SELECTORS, and prose is not one.
            # #169/#171 left the words "(lg-shared/site-header.css
            # .lg-chrome__join)" inside a /* */ block in lg-shortcodes.css, and
            # the descendant pattern below read that sentence as a scoped
            # selector: a standing RED on main about markup nobody had touched,
            # re-diagnosed twice. Same instinct gate 85 uses on PHP (#173).
            css = re.sub(r"/\*.*?\*/", "", css, flags=re.S)
            for m in re.finditer(r"([^{}\n,;]*\.lg-chrome__join[^{},]*)\s*[,{]", css):
                sel = m.group(1).strip()
                # a bare .lg-chrome__join, optionally with pseudo-classes, is fine;
                # a descendant/sibling combinator in front of it is not.
                if re.search(r"[+~>]\s*\.lg-chrome__join", sel) or re.match(
                        r".*\S\s+\.lg-chrome__join", sel):
                    scoped.append(f"{os.path.relpath(fp, REPO)}: {sel}")
    check("no stylesheet scopes .lg-chrome__join to the anon cluster",
          not scoped,
          "; ".join(scoped[:3]) or "the tester's copy inherits the same rules")

    # The PWA sheet is the phone's Join, and it must obey the same rule.
    js = js_code(f"{REPO}/{BOTTOM}")
    check("bottom-nav's anon sheet reads the header's Join href (no second flag)",
          "hdrHref('.lg-chrome__join'" in js)
    check("bottom-nav sets target=_blank only for an EXTERNAL join href",
          re.search(r"if\s*\(\s*/\^https\?:\\/\\//i\.test\(joinHref\)\s*\)", js) is not None,
          "an unconditional target='_blank' punts a member out of the installed PWA")
    check("bottom-nav no longer sets target unconditionally",
          re.search(r"joinRow\.href\s*=\s*joinHref;\s*joinRow\.target", js) is None)

    # ── #170: the tester's phone door ────────────────────────────────────────
    # At ≤640 on the hub the entire header aside is display:none!important and
    # the Nav tray carries no account entries, so without this row a signed-in
    # tester has NO path to /lgjoin/ at a phone width — the pill in the DOM the
    # whole time. Route-agnostic contract, same as gate 12's: "a tester can
    # reach Join", never "this pill is visible".
    # ⚠️ RE-SOURCED BY #196. It mirrored the header PILL; the pill is ruled out,
    # so mirroring it would have made this row vanish — and MEASURED on /hub/
    # AND the front page as a real signed-in tester, at 390 and 640 the account
    # chip is invisible and #lg-account-menu is display:none. Below 641 the menu
    # is in the DOM the whole time and is not a door at all, so losing both
    # leaves a signed-in tester no Join on a phone anywhere. It mirrors the MENU
    # ENTRY now, through .lg-chrome__menu-join — the same hook shape as
    # .lg-chrome__menu-signin. Contract unchanged and still route-agnostic:
    # "a tester can reach Join", never "this particular control is visible".
    check("bottom-nav's AUTHED sheet mirrors the account MENU entry",
          "querySelector('.lg-chrome__menu-join a')" in js and "menuJoinHref" in js)
    check("the tester row EXISTS only when the header drew that entry",
          re.search(r"if\s*\(\s*menuJoinHref\s*\)", js) is not None,
          "no flag of its own — it cannot drift from the control it mirrors")
    check("the tester row derives target=_blank from the href too",
          re.search(r"if\s*\(\s*/\^https\?:\\/\\//i\.test\(menuJoinHref\)\s*\)", js) is not None)
    check("bottom-nav still reads NO flag and NO cohort list of its own",
          "header-join-stripe" not in js.replace("header-join-stripe.php", "")
          and "stripe_testgroup" not in js
          and "lgms_stripe_lifecycle_allowlist" not in js)

    # ── EXECUTE the branch's rule, do not merely read it ────────────────────
    #
    # WHY THIS EXISTS AND WHY IT IS NOT REDUNDANT WITH §D. §D drives the real
    # origin, and the real origin serves MAIN — so §D's PWA-sheet assertions
    # read main's bottom-nav.js and cannot exercise this branch's change at all.
    # They pass today because main sets target unconditionally AND main's href
    # is external, which makes "target iff external" accidentally true. That is
    # a green that says nothing about the diff
    # (trap-harness-and-serve-answer-from-main), so the branch's own line is
    # pulled out and RUN here, against both destinations, in node.
    guard = None
    for line in open(f"{REPO}/{BOTTOM}", encoding="utf-8").read().splitlines():
        if "joinRow.target" in line and "test(joinHref)" in line:
            guard = line.strip()
            break
    if guard is None:
        check("bottom-nav's join-row tab rule can be located and executed", False,
              "no line matching the target guard — §A-exec cannot run")
    else:
        prog = ("var results = {};"
                "[%s].forEach(function (h) {"
                "  var joinHref = h, joinRow = {};"
                "  %s"
                "  results[h] = joinRow.target || null;"
                "});"
                "console.log(JSON.stringify(results));"
                % (json.dumps(PATREON) + ", " + json.dumps(LGJOIN), guard))
        r = subprocess.run(["node", "-e", prog], capture_output=True, text=True)
        if r.returncode != 0:
            check("bottom-nav's join-row tab rule executes", False,
                  f"node: {r.stderr.strip()[:200]}")
        else:
            got = json.loads(r.stdout)
            check("EXECUTED: bottom-nav opens a new tab for the patreon href",
                  got.get(PATREON) == "_blank", f"got target={got.get(PATREON)!r}")
            check("EXECUTED: bottom-nav does NOT open a new tab for /lgjoin/",
                  got.get(LGJOIN) is None,
                  f"got target={got.get(LGJOIN)!r} — an internal page in a new tab "
                  "leaves the installed PWA behind")

    # The flag register is a merge condition here (gate 62 enforces the general
    # rule; this names THIS flag so a rename cannot quietly orphan the row).
    flags = open(f"{REPO}/{FLAGS_MD}", encoding="utf-8").read() if os.path.isfile(f"{REPO}/{FLAGS_MD}") else ""
    check("docs/FLAGS.md carries a row for header-join-stripe",
          "header-join-stripe" in flags)
    check("that row names the lgms_stripe_pages_live coupling",
          "lgms_stripe_pages_live" in flags,
          "the register must say this flag is not sufficient on its own")
    log("")


# ══════════════════════════════════════════════════════════════════════ §B/§C
RENDER = r'''
$file = $argv[1]; $mode = $argv[2];
require $file;
/* FOUR VIEWERS, because #170's whole question is "which of them gets /lgjoin/".
   'authed' is the not-listed member and keeps EXACTLY the ctx #165 proved
   against, so its byte-identity legs still compare like for like. 'tester' adds
   the one capability the poller computes for the cohort; 'admin' is in that
   cohort by construction (manage_options || inCohort) and is how Ian clicks the
   real button on live without adding himself to a list. */
$caps = ['manage_options'=>false];
if ($mode === 'tester') { $caps['stripe_testgroup'] = true; }
if ($mode === 'admin')  { $caps['manage_options'] = true; $caps['stripe_testgroup'] = true; }
$ctx = $mode === 'anon'
  ? ['authenticated'=>false,'tier'=>'public']
  : ['authenticated'=>true,'tier'=>'pro','display_name'=>'probe','capabilities'=>$caps];
lg_shared_render_site_header($ctx);
'''

def build_tree(dest, source, cfg):
    """An isolated tree holding just what the partial needs, so a render cannot
    accidentally read the WORKTREE's config (or the serving checkout's) and
    report the wrong state as the right one.

    source: 'WORKTREE' or a git ref.  cfg: None | False | True | 'local-true'
    """
    os.makedirs(f"{dest}/lg-shared", exist_ok=True)
    os.makedirs(f"{dest}/platform/config", exist_ok=True)
    if source == "WORKTREE":
        for f in ("site-header.php", "impact-tag.php"):
            shutil.copyfile(f"{REPO}/lg-shared/{f}", f"{dest}/lg-shared/{f}")
    else:
        for f in ("site-header.php", "impact-tag.php"):
            open(f"{dest}/lg-shared/{f}", "w", encoding="utf-8").write(
                git("show", f"{source}:lg-shared/{f}"))
    p = f"{dest}/platform/config/header-join-stripe.php"
    lp = f"{dest}/platform/config/header-join-stripe.local.php"
    for f in (p, lp):
        if os.path.exists(f): os.remove(f)
    OFF, ALLOW, ON = ("<?php\nreturn array('state' => '%s');\n" % x
                      for x in ("off", "allowlist", "on"))
    LEG_ON  = "<?php\nreturn array('enabled' => true);\n"    # #165's spelling
    LEG_OFF = "<?php\nreturn array('enabled' => false);\n"
    if cfg is False:
        open(p, "w").write(OFF)
    elif cfg is True:
        open(p, "w").write(ON)
    elif cfg == "local-true":
        open(p, "w").write(OFF); open(lp, "w").write(ON)
    elif cfg == "allowlist":
        open(p, "w").write(ALLOW)
    elif cfg == "allowlist-local":
        open(p, "w").write(OFF); open(lp, "w").write(ALLOW)
    elif cfg == "legacy-on":
        open(p, "w").write(LEG_ON)
    elif cfg == "legacy-off":
        open(p, "w").write(LEG_OFF)
    elif cfg == "dev2":
        # ⚠️ dev2's ACTUAL on-box shape, byte for byte: tracked false, a
        # hand-placed .local.php saying `enabled => true`. It lives in the
        # SERVING CHECKOUT, which no lane may edit, so this is the one config
        # this gate cannot afford to get wrong.
        open(p, "w").write(LEG_OFF); open(lp, "w").write(LEG_ON)
    return f"{dest}/lg-shared/site-header.php"

def render(header_path, mode="anon", env=None):
    e = dict(os.environ)
    # A stray override in the operator's shell would silently decide every leg.
    e.pop("LG_HEADER_JOIN_STRIPE", None)
    if env: e.update(env)
    r = subprocess.run(["php", "-r", RENDER, header_path, mode],
                       capture_output=True, text=True, env=e)
    if r.returncode != 0:
        cannot_run(f"rendering {header_path} ({mode}) failed: {r.stderr.strip()[:300]}")
    return r.stdout

def join_anchor(html):
    m = re.search(r'<a class="lg-chrome__join"[^>]*>[^<]*</a>', html)
    return m.group(0) if m else ""

def menu_join(html):
    """The signed-in Join door since #196: the account-menu entry, which is the
    ONLY one a logged-in viewer gets. Matched by its hook class rather than by
    its href, because #196 also lets the href and the word change together for a
    member Patreon is already charging (Switch -> /switch-billing/), and an
    href-matched probe would silently find nothing in exactly that case."""
    m = re.search(r'<li role="none" class="lg-chrome__menu-join">\s*(<a [^>]*>[^<]*</a>)', html)
    return m.group(1) if m else ""


def leg_bc(tmp):
    log("§B  RENDER — every flag state, executed rather than read")

    # Report the tracked default; assert all states regardless of it.
    cfg = open(f"{REPO}/{CONFIG}", encoding="utf-8").read() if os.path.isfile(f"{REPO}/{CONFIG}") else ""
    if not cfg:
        cannot_run(f"{CONFIG} is missing — the flag has no tracked default")
    m = re.search(r"'state'\s*=>\s*'(off|allowlist|on)'", cfg)
    tracked_state = m.group(1) if m else (
        "on" if re.search(r"'enabled'\s*=>\s*true", cfg) else "off")
    report(f"tracked default: state = {tracked_state}",
           "(all three states asserted regardless — feedback-gate-reads-the-flag)")

    trees = {
        "absent":     build_tree(f"{tmp}/absent",     "WORKTREE", None),
        "off":        build_tree(f"{tmp}/off",        "WORKTREE", False),
        "allowlist":  build_tree(f"{tmp}/allow",      "WORKTREE", "allowlist"),
        "allow-local": build_tree(f"{tmp}/allow-loc", "WORKTREE", "allowlist-local"),
        "on":         build_tree(f"{tmp}/on",         "WORKTREE", True),
        "on-local":   build_tree(f"{tmp}/on-local",   "WORKTREE", "local-true"),
        "main":       build_tree(f"{tmp}/main",       "origin/main", None),
    }
    anon   = {k: render(v, "anon")   for k, v in trees.items()}
    authed = {k: render(v, "authed") for k, v in trees.items()}
    tester = {k: render(v, "tester") for k, v in trees.items()}
    admin  = {k: render(v, "admin")  for k, v in trees.items()}

    for state in ("absent", "off"):
        a = join_anchor(anon[state])
        check(f"{state}: Join goes to patreon.com", f'href="{PATREON}"' in a, a[:140])
        check(f"{state}: and opens in a new tab (it leaves the site)",
              'target="_blank"' in a and 'rel="noopener"' in a, a[:140])

    for state in ("on", "on-local"):
        a = join_anchor(anon[state])
        check(f"{state}: Join goes to {LGJOIN}", f'href="{LGJOIN}"' in a, a[:140])
        # THE ASSERTION THAT BITES on this half. "href is /lgjoin/" would pass
        # on a build that still carries target="_blank" — the state that throws
        # a member out of the installed PWA to buy a membership in a browser.
        check(f"{state}: and does NOT open a new tab (it is our own page)",
              "target=" not in a and "rel=" not in a, a[:140])

    # ── #170: THE THIRD STATE, executed ─────────────────────────────────────
    # The assertion that BITES here is not "a tester gets /lgjoin/". It is that
    # an ANONYMOUS visitor does not, in the same state, on the same box — that
    # is the whole reason live can sit in this state during a soft launch.
    for state in ("allowlist", "allow-local"):
        a_anon = join_anchor(anon[state])
        check(f"{state}: an ANONYMOUS visitor still goes to patreon.com",
              f'href="{PATREON}"' in a_anon, a_anon[:140])
        check(f"{state}: and still in a new tab — nothing about anon moved",
              'target="_blank"' in a_anon and 'rel="noopener"' in a_anon, a_anon[:140])
        check(f"{state}: a signed-in member NOT on the list gets no Join at all",
              join_anchor(authed[state]) == "" and menu_join(authed[state]) == "",
              join_anchor(authed[state])[:140])

    # ══ #196 RESTORES #165's RATCHET: THE FLAG REACHES NO SIGNED-IN VIEWER ══
    #
    # Ian, 2026-08-22, decision box, on the pill #170 added beside his own chip:
    # "Why is there a superfluous join button for logged in users now." The
    # signed-in Join door is the ACCOUNT MENU ENTRY and nothing else, so
    # $join_pill_authed is gone and no state of this flag draws a signed-in
    # control any more.
    #
    # ⚠️ REPLACED, NOT DELETED. What stood here was #170's VACUITY GUARD —
    # "allowlist ACTUALLY DIFFERS from off for a tester" — which existed because
    # implemented literally, 'allowlist' would have rendered byte-identically to
    # 'off' for every viewer and its gate would have been green having measured
    # nothing. That guard's premise was the signed-in pill. With the pill ruled
    # out the honest claim inverts: the flag must now move NOTHING for any
    # signed-in viewer in ANY state, which is #165's original ratchet that #170
    # narrowed. Asserting the OLD claim would demand a control Ian has ruled out;
    # deleting it would leave the ratchet unwatched. So it is inverted, with a
    # liveness partner, exactly as gate 86 §I9 was.
    for who, doc in (("tester", tester), ("admin", admin)):
        for state in ("off", "allowlist", "allow-local", "on", "absent"):
            check(f"'{state}' draws a signed-in {who} NO pill (#196)",
                  join_anchor(doc[state]) == "", join_anchor(doc[state])[:140])
        check(f"the flag moves not one byte for a signed-in {who}, in any state",
              len({doc[st] for st in ("off", "allowlist", "allow-local", "on", "absent")}) == 1,
              f"{sorted({len(doc[st]) for st in ('off','allowlist','allow-local','on','absent')})} bytes")

    # LIVENESS BESIDE THE ABSENCE, and it is the assertion that keeps the ruling
    # honest: "no pill" is trivially true of a build in which a tester has no
    # Join at all, which is the very no-op #170 was created to prevent, arrived
    # at from the other side. The door must EXIST — in the menu.
    for who, doc in (("tester", tester), ("admin", admin)):
        mj = menu_join(doc["allowlist"])
        check(f"...but a signed-in {who} DOES have a Join door, in the account menu",
              mj != "", "no account-menu Join entry rendered at all")
        check(f"...{who}'s menu Join points at {LGJOIN}",
              f'href="{LGJOIN}"' in mj, mj[:140])
        check(f"...and does NOT open a new tab (our page, inside the PWA)",
              "target=" not in mj and "rel=" not in mj, mj[:140])
        check(f"...carrying the hook the PWA account sheet reads at <=640, where "
              f"the menu itself is display:none",
              'class="lg-chrome__menu-join"' in doc["allowlist"])

    # ── #170: THE MIGRATION — dev2's exact on-box shape ─────────────────────
    # If this leg reddens, merging this branch reverts dev2's header to
    # patreon.com on the next `pull --ff-only`, with nobody having flipped
    # anything and nothing in any diff to explain it.
    dev2 = build_tree(f"{tmp}/dev2", "WORKTREE", "dev2")
    check("dev2's ACTUAL .local.php (`enabled => true`) still means 'on'",
          f'href="{LGJOIN}"' in join_anchor(render(dev2, "anon")),
          "tracked enabled=>false + hand-placed local enabled=>true")
    check("a TRACKED `enabled => true` still means 'on'",
          f'href="{LGJOIN}"' in join_anchor(
              render(build_tree(f"{tmp}/legacy-on", "WORKTREE", "legacy-on"), "anon")))
    check("a TRACKED `enabled => false` still means 'off'",
          f'href="{PATREON}"' in join_anchor(
              render(build_tree(f"{tmp}/legacy-off", "WORKTREE", "legacy-off"), "anon")))

    # A typo in a hand-placed file is the likeliest way a wrong state reaches a
    # box, and it must fall to today's behaviour rather than to the widest one.
    t = build_tree(f"{tmp}/badword", "WORKTREE", None)
    open(f"{os.path.dirname(os.path.dirname(t))}/platform/config/header-join-stripe.php",
         "w").write("<?php\nreturn array('state' => 'everyone');\n")
    check("an unrecognised state word falls CLOSED to patreon.com",
          f'href="{PATREON}"' in join_anchor(render(t, "anon")))

    # The preview override speaks the new vocabulary (lane previews and these
    # legs only — never a deploy mechanism).
    for word, expect in (("off", PATREON), ("allowlist", PATREON), ("on", LGJOIN)):
        r = join_anchor(render(trees["off"], "anon", env={"LG_HEADER_JOIN_STRIPE": word}))
        check(f"LG_HEADER_JOIN_STRIPE={word}: anon gets the right destination",
              f'href="{expect}"' in r, r[:140])
    # ⚠️ The signed-in half of this leg CHANGED WITH THE RULING, not with the
    # override. Under #170 an env-forced 'allowlist' handed a tester a PILL, and
    # that is what this asserted. Since #196 no state hands a signed-in viewer a
    # pill at all, so the honest assertion is that the override cannot conjure
    # one — paired with the door that DOES exist, or "no pill" would pass on a
    # tester who has no Join anywhere.
    t_html = render(trees["off"], "tester", env={"LG_HEADER_JOIN_STRIPE": "allowlist"})
    check("LG_HEADER_JOIN_STRIPE=allowlist cannot conjure a signed-in pill (#196)",
          join_anchor(t_html) == "", join_anchor(t_html)[:140])
    check("...and the tester's account-menu Join door is there regardless of the flag",
          f'href="{LGJOIN}"' in menu_join(t_html), menu_join(t_html)[:140])

    # The gitignored box override is the deploy mechanism for dev2, so it is
    # asserted to actually beat the tracked default rather than assumed to.
    check("a .local.php override beats the tracked default",
          join_anchor(anon["on-local"]) == join_anchor(anon["on"]))

    # The preview override, both doors. A fastcgi_param arrives in $_SERVER
    # only; $_SERVER is not settable from the CLI, so getenv() is what is
    # exercisable here and the $_SERVER half is asserted in §A's source leg.
    prev = render(trees["off"], "anon", env={"LG_HEADER_JOIN_STRIPE": "1"})
    check("LG_HEADER_JOIN_STRIPE=1 forces ON over a tracked OFF",
          f'href="{LGJOIN}"' in join_anchor(prev), join_anchor(prev)[:140])
    prev0 = render(trees["on"], "anon", env={"LG_HEADER_JOIN_STRIPE": "0"})
    check("LG_HEADER_JOIN_STRIPE=0 forces OFF over a tracked ON",
          f'href="{PATREON}"' in join_anchor(prev0), join_anchor(prev0)[:140])

    # ---- FAIL CLOSED -------------------------------------------------------
    # A reader that defaults ON when its config is wrong turns a deploy hiccup
    # into a member-facing launch. Each shape below is one a real box can
    # actually produce: a half-written file, a file returning the wrong type, a
    # file with the key missing, a file the pool user cannot read.
    shapes = {
        "an EMPTY file":               "",
        "a file returning a NON-ARRAY": "<?php\nreturn 'yes';\n",
        "a file with NO enabled key":  "<?php\nreturn array('note' => 'todo');\n",
        "a file returning NOTHING":    "<?php\n// placed but never filled in\n",
    }
    for label, body in shapes.items():
        t = build_tree(f"{tmp}/bad-{abs(hash(label))}", "WORKTREE", None)
        open(f"{os.path.dirname(os.path.dirname(t))}/platform/config/header-join-stripe.php",
             "w").write(body)
        check(f"tracked config is {label}: falls back to today's behaviour",
              f'href="{PATREON}"' in join_anchor(render(t, "anon")))

    # The same shapes in the .local.php must leave the TRACKED value standing
    # rather than deciding anything — that file is hand-placed on a box, so it
    # is the one most likely to be wrong.
    for label, body in shapes.items():
        t = build_tree(f"{tmp}/badlocal-{abs(hash(label))}", "WORKTREE", False)
        open(f"{os.path.dirname(os.path.dirname(t))}/platform/config/header-join-stripe.local.php",
             "w").write(body)
        check(f"local override is {label}: the tracked value stands",
              f'href="{PATREON}"' in join_anchor(render(t, "anon")))

    # An UNREADABLE file (wrong owner/mode after a hand-placed override) — the
    # os.path.lexists() scar in reverse: present is not readable.
    t = build_tree(f"{tmp}/unreadable", "WORKTREE", False)
    up = f"{tmp}/unreadable/platform/config/header-join-stripe.local.php"
    open(up, "w").write("<?php\nreturn array('enabled' => true);\n")
    os.chmod(up, 0o000)
    if os.access(up, os.R_OK):
        # running as root, or an ACL — the chmod proved nothing, so do not score it
        report("could not make a file unreadable to this user", "leg SKIPPED, not passed")
    else:
        check("an UNREADABLE local override leaves the tracked value standing",
              f'href="{PATREON}"' in join_anchor(render(t, "anon")))
    os.chmod(up, 0o644)

    # ⚠️ THE ONE SHAPE NOBODY CAN DEFEND AGAINST, stated rather than hidden.
    # `@` suppresses warnings, not PARSE errors: a config with a syntax error is
    # a hard fatal for any include, and would take down every page this partial
    # renders on. That is true of every flag config in this repo — back-pill,
    # frontend-compose, weekly-front — so it is the house pattern's property and
    # not this flag's defect, and inventing a bespoke guard here would make a
    # third pattern where two already disagree with nobody. The mitigation is
    # operational and belongs where the file gets placed:
    report("a config with a PHP SYNTAX ERROR is a hard fatal (@ hides warnings, not parse errors)",
           "run `php -l` on header-join-stripe.local.php before placing it on a box — "
           "this partial renders on EVERY page, so a typo there is a site-wide 500")
    log("")

    log("§C  OFF IS BYTE-IDENTICAL TO MAIN — compared, not argued")
    # 'allowlist' is in this list, and that is #170's central claim: the
    # logged-out page must stay cacheable carrying the patreon href while a
    # cohort is being tested behind it.
    for state in ("absent", "off", "allowlist", "allow-local"):
        same = anon[state] == anon["main"]
        check(f"anon, {state}: byte-identical to origin/main's header",
              same, f"{len(anon[state])} bytes vs {len(anon['main'])}")
    check("THE CACHING LAW: anon in 'allowlist' is byte-identical to anon in 'off'",
          anon["allowlist"] == anon["off"],
          f"{len(anon['allowlist'])} vs {len(anon['off'])} bytes")
    # A signed-in member NOT on the list is untouched in EVERY state, allowlist
    # included. That is what makes a cohort a cohort.
    #
    # ⚠️ THE AUTHED BASELINE IS THIS TREE'S OWN 'absent', NOT origin/main, AND
    # THAT IS THE ASSERTION BEING MADE CORRECTLY RATHER THAN LOOSELY. The claim
    # here is the FLAG'S BLAST RADIUS — "no state of this flag moves a byte for
    # this viewer" — and anchoring it to a historical file quietly turned it
    # into "the authed header may never change again". #173 clamped the account
    # chip to one line (+122 bytes in every state, uniformly) and reddened all
    # eleven of these at once, on a diff that touches nothing this gate is
    # about: the same shape as feedback-gate-reads-the-flag-not-a-hardcoded-state,
    # where a hardcoded expectation blocks every lane behind it. Comparing each
    # state to this tree's own no-config render still fails the instant the flag
    # leaks into an authed render, which is the thing worth failing on.
    #
    # THE ANON LEG ABOVE KEEPS ITS origin/main ANCHOR, deliberately: that one is
    # #170's cacheability claim, main is genuinely the right reference for it,
    # and it is the leg red-first mutation 7 fires at. #173 was proven
    # cmp-identical against it in all three states before this comment was
    # written.
    for state in ("absent", "off", "allowlist", "allow-local", "on"):
        same = authed[state] == authed["absent"]
        check(f"authed (not listed), {state}: byte-identical across flag states",
              same, f"{len(authed[state])} bytes vs {len(authed['absent'])}")
    # Liveness, because "identical to the baseline" is trivially true of two
    # dead renders, and the baseline is now this tree's own
    # (feedback-absence-assertion-needs-liveness).
    check("liveness: the authed baseline is a real signed-in header",
          len(authed["absent"]) > 5000
          and 'class="lg-chrome__account"' in authed["absent"],
          f"{len(authed['absent'])} bytes")
    check("authed (not listed): no join anchor exists in ANY state",
          all(join_anchor(authed[s]) == "" for s in trees))

    # ⚠️ #165's ratchet read "the flag must not reach a signed-in member AT ALL,
    # in any state, including ON". #170 narrowed it to off/on, because
    # 'allowlist' existed precisely to reach one. #196 RULED THAT CONTROL OUT
    # (Ian: "Why is there a superfluous join button for logged in users now"),
    # so the ratchet is WIDENED BACK to every state — including 'allowlist',
    # which no longer has a signed-in control to draw. §B holds the same claim
    # as a byte-set equality; this holds it per state, against this tree's own
    # no-config render.
    for who, doc in (("tester", tester), ("admin", admin)):
        for state in ("absent", "off", "allowlist", "allow-local", "on"):
            check(f"{who}, {state}: byte-identical across flag states",
                  doc[state] == doc["absent"],
                  f"{len(doc[state])} bytes vs {len(doc['absent'])}")

    # Liveness beside the absence: "identical to main" is trivially true of two
    # empty strings, and of a render that died before reaching the anchor
    # (feedback-absence-assertion-needs-liveness).
    check("liveness: the baseline render actually produced the header",
          len(anon["main"]) > 5000 and 'class="lg-chrome__join"' in anon["main"],
          f"{len(anon['main'])} bytes")
    # The same scar, one viewer over: every tester assertion above is an
    # equality or an absence, and both are trivially true of a render that died
    # before it reached the aside (feedback-absence-assertion-needs-liveness).
    # ⚠️ THE LIVENESS ANCHOR MOVED WITH THE RULING (#196). It used to require
    # the tester render to CARRY THE PILL; the pill is ruled out, so requiring it
    # would demand the control Ian removed. The account-menu Join is the door
    # now, so that is what "this render is real" means for a tester.
    check("liveness: the tester render is a real header, and DOES carry its "
          "account-menu Join door",
          len(tester["allowlist"]) > 5000
          and 'class="lg-chrome__account"' in tester["allowlist"]
          and 'class="lg-chrome__menu-join"' in tester["allowlist"],
          f"{len(tester['allowlist'])} bytes")

    # And the ON state must differ from main by EXACTLY the one anchor — a
    # change that also moved something else would pass every assertion above.
    ml = anon["main"].splitlines(); ol = anon["on"].splitlines()
    diffs = [i for i in range(max(len(ml), len(ol)))
             if (ml[i] if i < len(ml) else None) != (ol[i] if i < len(ol) else None)]
    check("ON differs from main by EXACTLY one line, and it is the join anchor",
          len(diffs) == 1 and "lg-chrome__join" in ol[diffs[0]],
          f"{len(diffs)} differing line(s)")
    log("")
    return tracked_state


# ══════════════════════════════════════════════════════════════════════ §D
def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p

def gate_token():
    try:
        out = subprocess.run(["bash", GATE_ENV], capture_output=True, text=True, timeout=30).stdout
    except Exception as e:
        cannot_run(f"gate-env.sh did not run ({e})")
    for line in out.splitlines():
        if line.startswith("LG_GATE_TOKEN="):
            t = line.split("=", 1)[1].strip()
            if t: return t
    cannot_run("gate-env.sh returned no LG_GATE_TOKEN")

def start_proxy(tok):
    """Anonymous real-origin proxy: --cookies /dev/null, so the viewer is
    genuinely logged out and the bytes are the REAL vhost's — real nginx, real
    sub_filter, real serving checkout."""
    port = free_port()
    proc = subprocess.Popen([sys.executable, PROXY, "--port", str(port),
                             "--cookies", "/dev/null", "--gate", tok],
                            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    base = f"http://127.0.0.1:{port}"
    for _ in range(40):
        time.sleep(0.25)
        try:
            if urllib.request.urlopen(base + "/", timeout=10).status == 200:
                return proc, base
        except Exception: pass
    proc.terminate()
    cannot_run(f"real-origin-proxy did not come up on {port}")


class Sock:
    """One persistent connection. Per-command sockets silently drop the device
    emulation (it is session-scoped), which makes a phone run false-PASS as a
    desktop (trap-chrome-dev-login-skill-stale-on-dev2)."""
    def __init__(self, url, timeout=60):
        import websocket
        self.ws = websocket.create_connection(url, timeout=timeout, suppress_origin=True)
        self.n = 0
    def send(self, method, params=None):
        self.n += 1; i = self.n
        self.ws.send(json.dumps({"id": i, "method": method, "params": params or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{method}: {r['error']}")
                return r.get("result", {})
    def ev(self, expr):
        r = self.send("Runtime.evaluate",
                      {"expression": expr, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")
    def close(self):
        try: self.ws.close()
        except Exception: pass


class Incognito:
    """A real private window: its own cookie jar and its own localStorage. Two
    scars ride on this — a theme stamp persisting into the shared chrome profile
    and turning every other lane's browser dark, and a second host-only vs
    dotted session cookie making the run execute as a different member."""
    def __init__(self, browser):
        self.b = browser
        self.ctx = browser.send("Target.createBrowserContext",
                                {"disposeOnDetach": False})["browserContextId"]
        self.tid = browser.send("Target.createTarget",
                                {"url": "about:blank",
                                 "browserContextId": self.ctx})["targetId"]
        self.page = Sock(f"ws://127.0.0.1:9222/devtools/page/{self.tid}")
    def close(self):
        self.page.close()
        for m, p in (("Target.closeTarget", {"targetId": self.tid}),
                     ("Target.disposeBrowserContext", {"browserContextId": self.ctx})):
            try: self.b.send(m, p)
            except Exception: pass


DESKTOP_UA = ("Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) "
              "Chrome/151.0.0.0 Safari/537.36")
PHONE_UA = ("Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 "
            "(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1")

def emulate(p, w, mobile):
    p.send("Emulation.setDeviceMetricsOverride",
           {"width": w, "height": 900, "deviceScaleFactor": 2 if mobile else 1, "mobile": mobile})
    p.send("Emulation.setTouchEmulationEnabled",
           {"enabled": mobile, "maxTouchPoints": 5 if mobile else 1})
    p.send("Emulation.setUserAgentOverride", {"userAgent": PHONE_UA if mobile else DESKTOP_UA})

def goto(p, url, secs=60):
    p.send("Page.navigate", {"url": url})
    for _ in range(secs * 4):
        time.sleep(0.25)
        try:
            if p.ev("document.readyState") == "complete": break
        except Exception: pass
    else:
        return False
    # bottom-nav.js injects the bar and the sheet AFTER load; measuring first
    # would report a phone with no controls at all.
    for _ in range(40):
        try:
            if p.ev("!!document.querySelector('.lg-chrome')"): break
        except Exception: pass
        time.sleep(0.25)
    time.sleep(1.2)
    return True


# "Can a person SEE and TOUCH Join, and where does it actually go?" Nothing here
# counts nodes: styled + sized + in-viewport + the element elementFromPoint
# returns at its own centre. That last clause separates hidden from COVERED — a
# blind click can land on the fixed tabbar (z-index 2147481000+) and a gate that
# never notices reports a tap that hit something else entirely.
PROBE = r"""
(() => {
  const desc = e => e ? e.tagName.toLowerCase() + (e.id ? '#'+e.id : '') +
    (e.className && typeof e.className === 'string'
      ? '.' + e.className.trim().split(/\s+/).join('.') : '') : null;
  const measure = a => {
    if (!a) return null;
    const cs = getComputedStyle(a), r = a.getBoundingClientRect();
    const styled = cs.display !== 'none' && cs.visibility !== 'hidden'
                   && parseFloat(cs.opacity) > 0.01;
    const sized  = r.width > 0 && r.height > 0;
    const cx = r.left + r.width/2, cy = r.top + r.height/2;
    const inView = cx >= 0 && cy >= 0 && cx <= innerWidth && cy <= innerHeight;
    let hit = null, top = null;
    if (styled && sized && inView) {
      const el = document.elementFromPoint(cx, cy);
      top = desc(el);
      hit = !!(el && (el === a || a.contains(el) || (el.closest && el.closest('a') === a)));
    }
    return {el: desc(a), href: a.getAttribute('href') || '',
            target: a.getAttribute('target') || '', rel: a.getAttribute('rel') || '',
            text: (a.textContent||'').trim().slice(0,20),
            styled, sized, inView, hit, top,
            x: Math.round(r.x), y: Math.round(r.y),
            w: Math.round(r.width), h: Math.round(r.height),
            paint: cs.backgroundColor + '|' + cs.color,
            // The style fingerprint used for the href-swap comparison below.
            fp: [cs.display, cs.visibility, cs.opacity, cs.backgroundColor, cs.color,
                 cs.padding, cs.fontSize, cs.fontWeight, cs.borderRadius,
                 cs.boxShadow, cs.width, cs.height].join('~')};
  };
  const join = document.querySelector('.lg-chrome__join');
  const anon = !!document.querySelector('.lg-chrome__signin, .lg-chrome__menu-signin a');
  const aside = document.querySelector('.lg-chrome__aside');
  return {
    // Is the header's anon cluster DISPLAYED at this width at all? On the hub at
    // <=640, bb-mirror/web/forums.css hides the whole aside with
    // display:none!important and the PWA sheet becomes the anon door. That is a
    // design decision, not a defect, so the contract has to be route-agnostic:
    // "an anon visitor can reach Join", never "this particular pill is visible".
    asideShown: !!(aside && getComputedStyle(aside).display !== 'none'),
    hOverflow: document.documentElement.scrollWidth > innerWidth,
    scrollW: document.documentElement.scrollWidth,
    // LIVENESS. An absence assertion is vacuous without proof the chrome built
    // and that we are actually looking at an ANONYMOUS page. A locked-out
    // browser serves a styled 403 that is identical at every width.
    alive: !!document.querySelector('.lg-chrome'),
    anonCluster: anon,
    accountBtn: !!document.querySelector('[data-lg-account-btn]'),
    title: (document.title||'').slice(0,60),
    join: measure(join)
  };
})()
"""

# Swap the href in the live document and re-measure. If the style fingerprint is
# unchanged and the control is still hit-testable, presentation does not depend
# on the href — so the state the origin cannot serve is reachable too. That is a
# measurement, and it would catch a [href^="http"] rule in ANY stylesheet, which
# no grep of site-header.css could see.
SWAP = r"""
(() => {
  const a = document.querySelector('.lg-chrome__join');
  if (!a) return null;
  const cs0 = getComputedStyle(a);
  const fp0 = [cs0.display, cs0.visibility, cs0.opacity, cs0.backgroundColor, cs0.color,
               cs0.padding, cs0.fontSize, cs0.fontWeight, cs0.borderRadius,
               cs0.boxShadow, cs0.width, cs0.height].join('~');
  const was = a.getAttribute('href');
  a.setAttribute('href', %OTHER%);
  // force layout/style resolution before re-reading
  void a.offsetWidth;
  const cs1 = getComputedStyle(a), r = a.getBoundingClientRect();
  const fp1 = [cs1.display, cs1.visibility, cs1.opacity, cs1.backgroundColor, cs1.color,
               cs1.padding, cs1.fontSize, cs1.fontWeight, cs1.borderRadius,
               cs1.boxShadow, cs1.width, cs1.height].join('~');
  const cx = r.left + r.width/2, cy = r.top + r.height/2;
  const el = document.elementFromPoint(cx, cy);
  const hit = !!(el && (el === a || a.contains(el) || (el.closest && el.closest('a') === a)));
  a.setAttribute('href', was);           // leave the page as we found it
  return {same: fp0 === fp1, hit, fp0, fp1};
})()
"""

SHEET = r"""
(() => {
  const desc = e => e ? e.tagName.toLowerCase() + (e.className && typeof e.className === 'string'
      ? '.' + e.className.trim().split(/\s+/).join('.') : '') : null;
  const sheet = document.querySelector('.lt-sheet');
  if (!sheet) return {open: false};
  const rows = [...sheet.querySelectorAll('a.lt-sheet__row')];
  const join = rows.find(a => /^join$/i.test((a.textContent||'').trim()));
  if (!join) return {open: true, rows: rows.map(r => (r.textContent||'').trim()), join: null};
  const cs = getComputedStyle(join), r = join.getBoundingClientRect();
  const cx = r.left + r.width/2, cy = r.top + r.height/2;
  const el = document.elementFromPoint(cx, cy);
  return {open: true, rows: rows.map(r => (r.textContent||'').trim()),
          join: {href: join.getAttribute('href') || '',
                 target: join.getAttribute('target') || '',
                 rel: join.getAttribute('rel') || '',
                 styled: cs.display !== 'none' && cs.visibility !== 'hidden',
                 sized: r.width > 0 && r.height > 0,
                 hit: !!(el && (el === join || join.contains(el))),
                 top: desc(el),
                 w: Math.round(r.width), h: Math.round(r.height)}};
})()
"""

ACCOUNT_TAB = ("'#looth-tabbar a[aria-label=\"You\"]', '#looth-tabbar button[aria-label=\"You\"]', "
               "'#looth-tabbar [aria-label=\"Account\"]'")

OPENER = r"""
(() => {
  const sels = [%SELS%];
  for (const s of sels) {
    const e = document.querySelector(s);
    if (!e) continue;
    const cs = getComputedStyle(e), r = e.getBoundingClientRect();
    if (cs.display === 'none' || cs.visibility === 'hidden' || !r.width || !r.height) continue;
    const cx = r.left + r.width/2, cy = r.top + r.height/2;
    if (cx < 0 || cy < 0 || cx > innerWidth || cy > innerHeight) continue;
    const top = document.elementFromPoint(cx, cy);
    // Hit-test the OPENER before dispatching at it: a blind click that lands on
    // the fixed tabbar still "succeeds" and the gate learns nothing.
    if (!(top && (top === e || e.contains(top)))) continue;
    return {sel: s, x: cx, y: cy};
  }
  return null;
})()
"""

def tap(p, x, y, mobile):
    if mobile:
        p.send("Input.dispatchTouchEvent", {"type": "touchStart", "touchPoints": [{"x": x, "y": y}]})
        time.sleep(0.08)
        p.send("Input.dispatchTouchEvent", {"type": "touchEnd", "touchPoints": []})
    else:
        for t in ("mousePressed", "mouseReleased"):
            p.send("Input.dispatchMouseEvent",
                   {"type": t, "x": x, "y": y, "button": "left", "clickCount": 1})
            time.sleep(0.06)
    time.sleep(1.0)


def served_flag_state(base):
    """What is the ORIGIN actually serving? Read from the served header itself.

    Returns 'on' | 'off' | 'preflag' | None. dev2 serves main, so during the
    window between this gate merging and the serving checkout's pull the origin
    has no flag support at all — that is 'preflag', and saying so keeps the gate
    honest instead of reporting a deploy lag as a finding.
    """
    try:
        html = urllib.request.urlopen(base + PATH, timeout=25).read().decode("utf-8", "replace")
    except Exception:
        return None
    m = re.search(r'<a class="lg-chrome__join"[^>]*>', html)
    if not m:
        return None
    a = m.group(0)
    if LGJOIN in a: return "on"
    if PATREON in a: return "off"
    return "preflag"


def leg_d(base, browser):
    log("§D  REACHABLE — a real browser, real widths, both themes, genuinely anon")
    served = served_flag_state(base)
    if served is None:
        report("the origin did not serve a recognisable header", "§D SKIPPED, not passed")
        log("")
        return
    report(f"the ORIGIN is serving the {served.upper()} state",
           "(dev2 serves main; a lane's own branch is deployed nowhere)")
    want = LGJOIN if served == "on" else PATREON
    other = json.dumps(PATREON if served == "on" else LGJOIN)

    for w in WIDTHS:
        mobile = w <= 640
        for theme in (["light", "dark"] if w in DARK_AT else ["light"]):
            label = f"{w}px {theme}"
            inc = Incognito(browser)
            try:
                p = inc.page
                emulate(p, w, mobile)
                if not goto(p, base + PATH):
                    inc.close(); cannot_run(f"{label}: {PATH} never reached readyState complete")
                if theme == "dark":
                    # Navigate FIRST — a localStorage write on about:blank is a
                    # no-op. Incognito keeps this out of the shared profile.
                    p.ev("localStorage.setItem('lg-set-theme','dark')")
                    if not goto(p, base + PATH):
                        inc.close(); cannot_run(f"{label}: reload after theming never completed")
                    got = p.ev("document.documentElement.getAttribute('data-lguser-theme')")
                    if got != "dark":
                        inc.close()
                        cannot_run(f"{label}: theme never resolved to dark (got {got!r}); "
                                   "a light measurement labelled dark is worse than none")

                r = p.ev(PROBE)
                if not r["alive"]:
                    inc.close()
                    cannot_run(f"{label}: no .lg-chrome in the document (title {r['title']!r}) — "
                               "a locked-out browser goes vacuously green")
                if not r["anonCluster"]:
                    inc.close()
                    cannot_run(f"{label}: no anon sign-in cluster — this run is NOT anonymous. "
                               "(Note: [data-lg-account-btn] also appears in the header's own "
                               "script text, so it is not an anon fingerprint.)")

                j = r["join"]
                # ---- what the FLAG controls. Asserted at every width, always,
                #      because these are the facts this lane actually decides.
                if j:
                    check(f"{label}: Join goes where the SERVED flag says",
                          j["href"] == want, f"want {want}, got {j['href']}")
                    ext = j["href"].startswith("http")
                    check(f"{label}: new tab iff it leaves the site",
                          (j["target"] == "_blank") == ext,
                          f"href={j['href']} target={j['target']!r}")
                else:
                    check(f"{label}: the header carries an anon Join anchor", False,
                          "no .lg-chrome__join in the DOM at all")

                header_route = bool(j and j["styled"] and j["sized"] and j["inView"] and j["hit"])
                if header_route:
                    log(f"        header pill {j['w']}x{j['h']} @{j['x']},{j['y']}  ->  {j['href']}")
                elif j:
                    why = ("the aside is display:none at this width (by design on the hub)"
                           if not r["asideShown"] else
                           "styled=false" if not j["styled"] else
                           "sized=false (0x0)" if not j["sized"] else
                           f"OFF-SCREEN (x={j['x']} w={j['w']} in a {w}px viewport, "
                           f"scrollWidth={r['scrollW']})" if not j["inView"] else
                           f"COVERED by {j['top']}")
                    log(f"        header pill not reachable: {why}")

                if j and r["asideShown"]:
                    if theme == "dark":
                        # gate 36 owns the contrast ratio; this owns "it exists as
                        # a visible control in this theme at all".
                        check(f"{label}: the Join pill is painted (not transparent)",
                              "rgba(0, 0, 0, 0)" not in j["paint"].split("|")[0],
                              f"background {j['paint'].split('|')[0]}")

                    # ---- the state the origin cannot serve ----
                    # Asserted as a DELTA, never as an absolute: at a width where
                    # main already hides or overflows this control, "is it
                    # hit-testable" answers about main and not about the flag.
                    # What this lane must prove is that flipping the flag changes
                    # NOTHING about reachability (trap-mock-theme-stamp: assert
                    # the delta you caused, never the absolute value).
                    sw = p.ev(SWAP.replace("%OTHER%", other))
                    check(f"{label}: presentation does NOT depend on the href",
                          bool(sw and sw["same"]),
                          "a stylesheet styles this control by its href — the state "
                          "the origin cannot serve may not look the same")
                    check(f"{label}: the OTHER href is exactly as reachable as this one",
                          bool(sw) and sw["hit"] == header_route,
                          f"reachable={header_route} with {j['href']}, "
                          f"{sw['hit'] if sw else '?'} with the other — flipping the "
                          "flag would change whether Join can be tapped")

                # ---- the PWA sheet: the phone's Join, and the only one at <=640
                #      on the hub, where forums.css hides the header aside ----
                sheet_route = None
                if mobile:
                    op = p.ev(OPENER.replace("%SELS%", ACCOUNT_TAB))
                    if not op:
                        report(f"{label}: no hit-testable account tab in the dash",
                               "PWA-sheet leg SKIPPED at this width, not passed")
                    else:
                        tap(p, op["x"], op["y"], mobile)
                        s = p.ev(SHEET)
                        if not s.get("open"):
                            report(f"{label}: the account sheet did not open", "leg SKIPPED")
                        elif not s.get("join"):
                            check(f"{label}: the PWA anon sheet offers Join", False,
                                  f"rows: {s.get('rows')}")
                        else:
                            sj = s["join"]
                            sheet_route = sj["styled"] and sj["sized"] and sj["hit"]
                            check(f"{label}: PWA sheet Join is visible and tappable",
                                  sheet_route,
                                  f"covered by {sj['top']}" if not sj["hit"] else "")
                            check(f"{label}: PWA sheet Join mirrors the header's href",
                                  sj["href"] == want, f"want {want}, got {sj['href']}")
                            # The scar this lane fixed: an unconditional
                            # target="_blank" throws a member out of the installed
                            # PWA to buy a membership in a browser tab.
                            sext = sj["href"].startswith("http")
                            check(f"{label}: PWA sheet Join opens a new tab iff it leaves the site",
                                  (sj["target"] == "_blank") == sext,
                                  f"href={sj['href']} target={sj['target']!r} — an internal "
                                  "page in a new tab leaves display:standalone behind")

                # ---- THE CONTRACT, route-agnostic: can this person JOIN? ----
                # Not "is this pill visible" — a redesign that moves Join into the
                # drawer or the tray must still pass, because what is protected is
                # the visitor's ability to join, not a particular div. Same shape
                # as gate 12, and for the same reason.
                reachable = header_route or bool(sheet_route)
                route = "header pill" if header_route else "PWA account sheet" if sheet_route else None
                if w in KNOWN_MAIN_GAPS:
                    # Baseline ratchet — see KNOWN_MAIN_GAPS.
                    if reachable:
                        check(f"{label}: KNOWN_MAIN_GAPS[{w}] is STALE — delete it", False,
                              f"Join is now reachable at {w}px via the {route}. The "
                              f"allowance has outlived its defect; remove the {w} entry "
                              "from KNOWN_MAIN_GAPS in this file so the width is asserted "
                              "again.")
                    else:
                        report(f"{label}: NO reachable Join — PRE-EXISTING ON MAIN, not scored",
                               KNOWN_MAIN_GAPS[w])
                else:
                    check(f"{label}: an anon visitor can reach Join by SOME route",
                          reachable,
                          "neither the header pill nor the PWA account sheet offers a "
                          "reachable Join at this width")
                    if reachable and theme == "light":
                        log(f"        route: {route}")
                if r["hOverflow"] and w not in KNOWN_MAIN_GAPS:
                    report(f"{label}: the page scrolls HORIZONTALLY "
                           f"(scrollWidth {r['scrollW']} > {w})",
                           "not scored here — craft gate territory — but it is how a "
                           "control ends up off the right edge")
            finally:
                inc.close()

    # ⚠️ WHAT THIS LEG DID NOT MEASURE, said out loud so a green §D is not read
    # as covering it. Every width above was browsed ANONYMOUSLY, and #170's
    # tester pill renders only for a signed-in member on the cohort list — a
    # session this leg has no way to hold (and the one shared chrome profile on
    # this box makes cookie-juggling its own class of false green:
    # trap-shared-chrome-profile-duplicate-session-cookies). What carries the
    # measurement across is §A's assertion that no stylesheet scopes
    # .lg-chrome__join to the anon cluster, so the tester's copy inherits the
    # presentation proven here rather than being assumed to.
    report("the TESTER pill's in-browser reachability is not measured by this anon leg",
           "click Join at 390px and at 900px signed in as a LISTED NON-ADMIN member; "
           "the ≤640 hub answer is the PWA You-sheet row, which §A asserts structurally")
    log("")


# ══════════════════════════════════════════════════════════════════════ §E
STUB = "This page isn't available yet"

def leg_e(base, served):
    log("§E  THE COUPLING — the destination must ADMIT the visitor sent to it")

    # ── #170: THE SECOND COUPLING, ONE COLUMN OVER ──────────────────────────
    # 'on' has to pair with `lgms_stripe_pages_live` (below, and all of #165).
    # 'allowlist' pairs with a DIFFERENT switch, and the two predicates are not
    # the same shape — which is the whole trap:
    #
    #   the PILL   $caps['stripe_testgroup'] = manage_options || inCohort($uid)
    #              ONE lock: the list.
    #   the DOOR   lg_membership_in_stripe_test_group() = the pages flag
    #              AND the list.  TWO locks.
    #
    # So with `lgms_stripe_testgroup_pages` off, a listed tester is handed a
    # pill and refused at the door — presence-is-not-reachability again, in the
    # one place it costs a sale. An admin would not notice: they pass both gates
    # by manage_options, which is exactly who checks these things.
    rp = f"{REPO}/membership-pages/web/router.php"
    cp = f"{REPO}/membership-pages/config.php"
    if not (os.path.isfile(rp) and os.path.isfile(cp)):
        report("membership-pages sources not found — the allowlist coupling is unchecked",
               "leg SKIPPED, not passed")
    else:
        router, mcfg = php_code(rp), php_code(cp)
        check("the router still sends pre-launch /lgjoin/ through the Test Group gate",
              re.search(r"'lgjoin'\s*=>\s*\[\s*'lgjoin\.php'\s*,\s*'testgroup'", router)
              is not None
              and "lg_membership_testgroup_gate_or_exit" in router)
        check("the DOOR needs the pages flag AND the list — two locks, not one",
              re.search(r"function lg_membership_in_stripe_test_group[^}]*"
                        r"lg_membership_stripe_testgroup_pages\(\)[^}]*in_array", mcfg,
                        re.S) is not None)
        check("both ends read the SAME cohort option — no second list",
              "lgms_stripe_lifecycle_allowlist" in mcfg)
        # Reported, never asserted, and NOT because it is unimportant: it is
        # unassertable HERE. This leg is anonymous, and 'allowlist' is by
        # construction invisible to an anonymous observer (see below), so there
        # is no served state for a §165-style "assert while ON" to key on. The
        # operational check is a signed-in click by a listed member, and saying
        # so plainly beats a green that measured nothing.
        report("'allowlist' ALSO needs wp_option lgms_stripe_testgroup_pages ON",
               "the pill has ONE lock (the list); the door has TWO (flag + list). "
               "An admin passes both regardless — so the person most likely to "
               "check is the one person who cannot see the failure. Verify by "
               "clicking Join signed in as a LISTED NON-ADMIN member.")

    # ⚠️ AN OUTSIDE OBSERVER CANNOT TELL 'off' FROM 'allowlist' HERE, and that
    # is not a gap in this gate — it IS the caching law, observed from outside
    # rather than argued: served_flag_state() reads the ANONYMOUS page, and the
    # anonymous page is byte-identical in those two states by construction. If
    # this ever started reporting 'allowlist', the logged-out render would be
    # leaking a per-viewer decision into a cacheable page.
    if served == "off":
        report("the served anon header says patreon.com — 'off' OR 'allowlist'",
               "indistinguishable to an anonymous observer BY DESIGN; §B and §C "
               "prove the two are byte-identical for anon")

    try:
        with urllib.request.urlopen(base + LGJOIN, timeout=25) as r:
            code, body = r.status, r.read().decode("utf-8", "replace")
    except Exception as e:
        report(f"anon GET {LGJOIN} did not answer ({e})", "leg SKIPPED, not passed")
        log("")
        return

    is_stub = STUB in body
    reachable = code == 200 and not is_stub
    what = ("the pre-launch stub" if is_stub else f"HTTP {code}" if code != 200 else "the join page")

    if served == "on":
        # HARD assertion: the flag is ON, so this IS the member's experience.
        check(f"anon {LGJOIN} is a real join page, not a refusal", reachable,
              f"anon gets {what}. lgms_stripe_pages_live must be ON in the same "
              f"window as this flag, or Join lands nowhere.")
        if reachable:
            check("and it actually offers something to buy",
                  ("$" in body or "lgjoin" in body.lower()) and len(body) > 8000,
                  f"{len(body)} bytes with no price and no join markup")
    else:
        # REPORT only. The flag is OFF, so nobody is being sent here yet, and a
        # gate that reds on a non-defect blocks every lane.
        report(f"anon {LGJOIN} currently serves {what} (HTTP {code}, {len(body)} bytes)",
               "NOT asserted while the header flag is OFF — it becomes a hard "
               "assertion the moment the flag goes ON")
        if not reachable:
            report("⚠️  FLIPPING THIS FLAG ALONE WOULD SEND ANON TO A REFUSAL",
                   "turn on wp_option lgms_stripe_pages_live in the same window "
                   "(WP admin: LG Member Sync)")
    log("")


# ══════════════════════════════════════════════════════════════════════ main
def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--no-browser", action="store_true", help="skip §D")
    ap.add_argument("--url", default="", help="existing anon origin; otherwise one is started")
    a = ap.parse_args()

    log("header-join-gate — GATE 79 — where a logged-out visitor's Join goes,")
    log("                              and whether they can actually get there.")
    log(f"  repo {REPO}")
    log("")

    leg_a()
    tmp = tempfile.mkdtemp(prefix="hjgate-")
    try:
        leg_bc(tmp)
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    # ---- network / browser legs ----
    proc = None
    base = a.url.rstrip("/") if a.url else None
    if base is None:
        tok = gate_token()
        proc, base = start_proxy(tok)
    try:
        served = served_flag_state(base)
        if served is None:
            report("the origin served no recognisable header",
                   "§D and §E SKIPPED, not passed")
        elif served == "preflag":
            report("the SERVING CHECKOUT predates this flag (no flag-shaped href)",
                   "§D still asserts reachability; §E reports only")
            served = "off"
        if served is not None:
            if a.no_browser:
                report("§D SKIPPED by --no-browser", "not passed")
            else:
                try:
                    import websocket  # noqa: F401
                except ImportError:
                    report("python3 websocket-client is not installed", "§D SKIPPED, not passed")
                else:
                    try:
                        bws = json.load(urllib.request.urlopen(CDP + "/json/version"))["webSocketDebuggerUrl"]
                    except Exception as e:
                        report(f"chrome-dev is not answering on {CDP} ({e})", "§D SKIPPED, not passed")
                    else:
                        browser = Sock(bws)
                        try:
                            leg_d(base, browser)
                        finally:
                            browser.close()
            leg_e(base, served)
    finally:
        if proc: proc.terminate()

    log(f"  {passes} passed, {failures} failed")
    if failures:
        log("")
        log("  FINDINGS:")
        for f in findings: log(f"    - {f}")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())

# ─────────────────────────────────────────────────────────────── RED-FIRST
# Mutations are applied to a SNAPSHOT COPY of the tree and never with
# `git checkout --`, which wipes uncommitted work under test and once turned one
# harness bug into ten false "the assertion is decoration" verdicts
# (feedback-mutation-harness-must-snapshot-not-checkout).
#
# tools/gates/header-join-redfirst.sh drives it. RUN 2026-08-20 (#170): baseline
# green at 104, twenty-one mutations each reddening its OWN named assertion, two
# no-ops reddening nothing. 23 of 23 as expected. (#165's run: 14 of 14 at 43.)
#
#    1  the ON href hardcoded into the anchor    -> "href resolved at render time"
#    2  reader forgets $_SERVER                  -> "read from getenv() AND $_SERVER"
#    3  .local.php override dropped              -> "honours the .local.php box override"
#    4  .local wins on any truthy, not === true  -> "wins only on an EXPLICIT boolean true"
#    5  our own page opened in a new tab         -> "on: does NOT open a new tab"
#    6  the OFF href changed by ONE character    -> "off: Join goes to patreon.com"
#    7  ONE blank line added to the OFF path     -> "byte-identical to origin/main"
#    8  bottom-nav target=_blank unconditional   -> "target=_blank only for an EXTERNAL href"
#    9  bottom-nav stops reading the header      -> "anon sheet reads the header"
#   10  config defaults ON when unreadable       -> "falls back to today's behaviour"
#   11  the FLAGS.md row deleted                 -> "FLAGS.md carries a row"
#   12  the FLAGS row drops the coupling         -> "row names lgms_stripe_pages_live"
#   13  tester pill escapes 'allowlist' into ON  -> "gives a signed-in tester no pill"
#   14  the pill ignores the cohort entirely     -> "member NOT on the list gets no Join"
#   15  anon leaks /lgjoin/ in allowlist         -> "THE CACHING LAW"
#   16  the tester pill never renders            -> "allowlist ACTUALLY DIFFERS from off"
#   17  the legacy `enabled` key tidied away     -> "dev2's ACTUAL .local.php"
#   18  an unknown state word falls OPEN         -> "unrecognised state word falls CLOSED"
#   19  the header grows its own cohort list     -> "defines NO second cohort list"
#   20  bottom-nav drops the tester row          -> "tester row EXISTS only when..."
#   21  the pill block re-indented (9 bytes)     -> "authed (not listed)"
#   A   rename the reader's closure              -> GREEN, 103 passed
#   B   reflow the config docblock               -> GREEN, 103 passed
#
# ⚠️ Mutation 16 is #170's mutation 7 — the one that proves the gate is worth
# having. It is the version of this issue that reads correct on every line: the
# three states resolve, the href logic is right, `allowlist` is wired to the
# cohort capability... and the control renders for nobody, because on main the
# Join pill was ANON-ONLY and a signed-in test user could never see it. It
# passes every other assertion in this file. Only "allowlist ACTUALLY DIFFERS
# from off for a tester" catches it.
#
# ⚠️ Mutation 21 is the defect this lane actually shipped into its own working
# tree and §C caught: an indented `<?php if ?>` tag emits its own leading spaces
# whether the branch is taken or not, so the tester block added 9 bytes to EVERY
# signed-in render in EVERY state including 'off'. Same family as mutation 7,
# found the same way, kept so it cannot return unseen.
#
# ⚠️ Mutation 7 is the one worth keeping. It is a pure-whitespace edit that
# changes NO behaviour whatsoever, and it is caught only by the byte-identity
# leg — which is exactly the defect lane 129 found in its own OFF path (a blank
# line after a php endif, 46 bytes, on a diff that removed zero lines). Without
# §C that mutation is invisible and "OFF is a no-op" is back to being a claim.
