#!/usr/bin/env python3
"""
GATE 51 — profile-setup (backlog 19, "new members arrive alive").

Ian saw a directory of identical grey faces (8/12) and ruled on the mocks (8/15):
OPTION A APPROVED, with four sharpenings quoted verbatim below. This gate exists
to keep those four true, and to keep the flag's OFF state a real no-op.

WHAT IT ASSERTS, and why each one is here:

  A. THE FLAG IS READ PER-STATE, and OFF is the default. Exercised by actually
     RUNNING the reader in three states — config file ABSENT, present-and-OFF,
     and overridden ON — not by reading the array and trusting it. The absent
     case is a real temp-dir copy with no config sibling, because "someone
     deletes the config" and "the config says false" are different states that a
     source-read conflates. Per the lane memory, a gate must read the flag rather
     than hardcode a state, so flipping the default later needs no gate edit.

  B. FLAG OFF IS A TRUE NO-OP ON BOTH RAILS. The step's route is registered
     INSIDE the enabled-check, so with the flag off /profile-setup/ is not a
     route at all; and each rail's hand-off target must equal its pre-feature
     value (Patreon: home_url('/') on success and the member's own profile on
     skip; Stripe: the single original "Head to the community" CTA).

  C. BOTH RAILS ARE WIRED — sharpening 1, "Both patreon onboarding like after
     Password gen and for the stripe." A build that quietly serves one rail is
     the exact failure Ian's standing dual-wield ruling forbids, and it would be
     invisible to anyone testing on Patreon only.

  D. THE STEP SAYS IT IS OPTIONAL AND SKIP IS FIRST-CLASS — sharpening 2, "clear
     that this is setting up the profile and is optional". Asserted as: the word
     optional is present, and the skip control carries the same button class as
     the save control rather than being a bare text link.

  E. NO NUDGE SURFACE EXISTS, ANYWHERE — sharpening 3, "No nudging on that
     matter." This is the one assertion that is an ABSENCE, which is precisely
     the kind that rots back in silently, so it is paired with a LIVENESS
     self-test: the detector is first run against a synthetic string it MUST
     flag. An absence assertion whose detector is broken passes on an empty
     repo, which proves nothing.

  F. SKIPPERS GET INSTRUCTIONS — sharpening 4, they get "instructions for how to
     find their profile later". Asserted on the ?skipped=1 branch.

  G. THE TWO EXCLUSIONS HOLD. ?change=1 is an existing member changing a
     password, and kind=gift is somebody buying codes for other people; neither
     is a new member and neither may be sent to a profile-setup screen.

EXIT CODES follow run-all.sh: 0 green, 1 RED (real finding), 2 CANNOT RUN.
"""
import html
import os
import re
import shutil
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
CFG = os.path.join(ROOT, "platform", "config", "profile-setup.php")
MU = os.path.join(ROOT, "platform", "mu-plugins", "lg-profile-setup.php")
PW = os.path.join(ROOT, "platform", "mu-plugins", "lgpo-set-password.php")
WELCOME = os.path.join(ROOT, "membership-pages", "web", "welcome.php")

fails = []
checks = 0


def ok(label):
    global checks
    checks += 1
    print(f"  ok   {label}")


def bad(label, detail=""):
    global checks
    checks += 1
    fails.append(label + (f" — {detail}" if detail else ""))
    print(f"  FAIL {label}" + (f" — {detail}" if detail else ""))


def cannot(msg):
    print(f"\nCANNOT RUN: {msg}")
    sys.exit(2)


for p in (CFG, MU, PW, WELCOME):
    if not os.path.isfile(p):
        cannot(f"missing {os.path.relpath(p, ROOT)} — nothing to gate")

src_cfg = open(CFG).read()
src_mu = open(MU).read()
src_pw = open(PW).read()
src_wel = open(WELCOME).read()


def php_eval(code, env=None, cwd=None):
    """Run a PHP snippet, returning stripped stdout. Stubs the WP surface the
    mu-plugin touches at include time so the reader can be exercised alone."""
    e = dict(os.environ)
    e.pop("LG_PROFILE_SETUP", None)   # never inherit the harness's own override
    if env:
        e.update(env)
    r = subprocess.run(["php", "-r", code], capture_output=True, text=True, env=e, cwd=cwd)
    if r.returncode != 0:
        cannot(f"php failed: {r.stderr.strip()[:300]}")
    return r.stdout.strip()


STUB = (
    "define('ABSPATH',1);"
    "function add_action(){} function add_filter(){}"
)


# ── A. the flag, exercised in three real states ────────────────────────────────
print("A. flag reads per-state, defaults OFF")

state_off = php_eval(
    STUB + f"require '{MU}';"
    "echo lg_profile_setup_enabled() ? 'ON' : 'OFF';"
)
if state_off == "OFF":
    ok("config present and shipped OFF")
else:
    bad("shipped config must default OFF", f"reader says {state_off!r}")

state_on = php_eval(
    STUB + f"require '{MU}';"
    "echo lg_profile_setup_enabled() ? 'ON' : 'OFF';",
    env={"LG_PROFILE_SETUP": "1"},
)
if state_on == "ON":
    ok("env override turns it ON (the flag is genuinely read, not hardcoded)")
else:
    bad("LG_PROFILE_SETUP=1 must turn the step ON", f"reader says {state_on!r}")

# ABSENT: a copy with no config sibling. "Config deleted" and "config says false"
# are different states; a source-read cannot tell them apart.
tmp = tempfile.mkdtemp(prefix="gate51-")
try:
    os.makedirs(os.path.join(tmp, "mu-plugins"))
    shutil.copy(MU, os.path.join(tmp, "mu-plugins", "lg-profile-setup.php"))
    state_absent = php_eval(
        STUB + f"require '{os.path.join(tmp, 'mu-plugins', 'lg-profile-setup.php')}';"
        "echo lg_profile_setup_enabled() ? 'ON' : 'OFF';"
    )
    if state_absent == "OFF":
        ok("config file ABSENT still reads OFF (fails safe)")
    else:
        bad("missing config must fail safe to OFF", f"reader says {state_absent!r}")
finally:
    shutil.rmtree(tmp, ignore_errors=True)


# ── B. OFF is a true no-op ─────────────────────────────────────────────────────
print("\nB. flag OFF is a real no-op on both rails")

# The route must be registered INSIDE the liveness check, so the shipped state
# means "not a route", not "a route that renders nothing".
#
# The guard is lg_profile_setup_live() — enabled OR a non-empty testers list —
# rather than lg_profile_setup_enabled(), because Ian's live-testing list has to
# reach a named member while the master switch is still off. The absence property
# is unchanged and section I proves it by RUNNING the reader: off with an empty
# list answers live=false, so nothing is registered and /profile-setup/ 404s
# exactly as it did before this feature existed.
m = re.search(r"add_action\('init',\s*function\s*\(\)\s*\{(.{0,600})", src_mu, re.S)
if m and re.search(r"if\s*\(!lg_profile_setup_live\(\)\)\s*return;", m.group(1)):
    ok("route registration returns early unless the step is live (off+no testers ⇒ absent)")
else:
    bad("the /profile-setup/ route must early-return on flag OFF",
        "an always-registered route is not a no-op")

if re.search(r"\$onboardDone\s*=\s*\$psOn\s*\?\s*home_url\(\$psPath\)\s*:\s*home_url\('/'\)", src_pw):
    ok("Patreon rail OFF ⇒ success still redirects to home_url('/') (Ian 6/16)")
else:
    bad("Patreon OFF path must be the original home_url('/') redirect")

if re.search(r"\$onboardSkip\s*=\s*\$psOn\s*\?\s*\$psPath\s*:\s*\$cont", src_pw):
    ok("Patreon rail OFF ⇒ skip still goes to the member's own profile")
else:
    bad("Patreon OFF skip must be the original $cont target")

# Stripe: the OFF branch must be the single original CTA, unchanged.
if re.search(r"else:\s*\?>\s*<a class=\"lg-success__cta is-primary\" href=\"/\">Head to the community</a>",
             src_wel, re.S):
    ok("Stripe rail OFF ⇒ the original single 'Head to the community' CTA")
else:
    bad("Stripe OFF path must render exactly the pre-feature CTA")


# ── C. both rails wired (sharpening 1) ─────────────────────────────────────────
print("\nC. sharpening 1 — BOTH rails hand off to the same step")

# Per-MEMBER, not per-box: the rail must ask the same question the step asks, or a
# tester would reach /profile-setup/ while their own end-of-join page never offers
# it — and a non-tester's page would change when it must stay byte-identical.
if "lg_profile_setup_visible_to(" in src_pw and "lg_profile_setup_path()" in src_pw:
    ok("Patreon rail (lgpo-set-password.php) hands off, per member")
else:
    bad("Patreon rail is not wired to the step")

# Behaviour, not presence: $profileSetupOn must be DERIVED FROM the config's
# enabled key. Checking only that the identifier appears still passes when it is
# hardcoded false, i.e. a Patreon-only build — which the red-first run caught.
if re.search(r"\$profileSetupOn\s*=\s*!empty\(\$psCfg\['enabled'\]\)", src_wel):
    ok("Stripe rail (welcome.php) hands off, driven by the flag")
else:
    bad("Stripe rail must derive $profileSetupOn from the config's enabled key",
        "a hardcoded value is a Patreon-only build")

# Both must resolve the SAME path, from the same config, or the rails drift.
if "platform/config/profile-setup.php" in src_wel.replace("\\", "/") or \
   re.search(r"config/profile-setup\.php", src_wel):
    ok("Stripe rail reads the SAME tracked config file (one flag, two runtimes)")
else:
    bad("Stripe rail must read platform/config/profile-setup.php, not its own flag")


# ── D. optional + skip is first-class (sharpening 2) ───────────────────────────
print("\nD. sharpening 2 — says it is optional, and Skip is first-class")

# Scope to the RENDERED markup. This file's own docblock uses the word
# "optional", so a whole-file search stayed green after the member-visible
# wording was deleted — caught by the red-first run.
_doc = src_mu.find("<!doctype html")
rendered_mu = src_mu[_doc:] if _doc != -1 else ""
if not rendered_mu:
    cannot("could not locate the step's rendered markup; the wording checks would be vacuous")
if re.search(r"[Oo]ptional", rendered_mu):
    ok("the step tells the MEMBER it is optional (in the rendered markup)")
else:
    bad("the step must say plainly, on screen, that it is optional")

# Skip must carry button weight, not be a bare text link.
#
# ⚠️ Detector note, learned the hard way on this gate's first run: an
# `id="ps-skip"[^>]*` window is WRONG twice over — it stops at the `>` inside an
# embedded `<?php … ?>`, and it misses attributes written BEFORE the id (the
# class is first in this markup). It reported a correctly-built control as RED.
# So: isolate the whole element and test that.
def element_with_id(html, el_id):
    """Return the full opening tag containing id="<el_id>", PHP tags and all."""
    i = html.find(f'id="{el_id}"')
    if i == -1:
        return None
    start = html.rfind("<", 0, i)
    if start == -1:
        return None
    # Walk forward to the tag's real end, skipping over any `<?php … ?>` blocks
    # so their `?>` cannot be mistaken for the tag's own `>`.
    j = i
    while j < len(html):
        if html.startswith("<?", j):
            close = html.find("?>", j)
            if close == -1:
                return None
            j = close + 2
            continue
        if html[j] == ">":
            return html[start:j + 1]
        j += 1
    return None


skip_el = element_with_id(src_mu, "ps-skip")
save_el = element_with_id(src_mu, "ps-save")
if skip_el and save_el and "btn" in skip_el and "btn" in save_el:
    ok("Skip carries the same button class as Save (first-class, not a grey link)")
else:
    bad("Skip must be a button-weight control beside Save",
        f"skip={skip_el!r}")


# ── E. NO NUDGE SURFACE ANYWHERE (sharpening 3) ────────────────────────────────
print("\nE. sharpening 3 — 'No nudging on that matter': the absence, gated")

# The detector: markup that would constitute a profile-completeness nudge on a
# member-facing surface. Deliberately broad — this is an absence assertion, so a
# narrow detector is worse than none.
NUDGE = re.compile(
    r"(profile[-_ ]?(is )?(nearly )?empty"
    r"|finish (your |setting up )?(your )?profile"
    r"|complete your profile"
    r"|profile[-_ ]?nudge"
    r"|nudge[-_ ]?profile"
    r"|your profile is \d+%)",
    re.I,
)

# LIVENESS FIRST. An absence assertion with a broken detector passes on an empty
# repo and proves nothing — so prove the detector bites before trusting a miss.
probe = 'if (x) { echo "<div class=\'nudge\'>Finish your profile</div>"; }'
if NUDGE.search(probe):
    ok("LIVENESS — the nudge detector fires on a synthetic nudge")
else:
    cannot("nudge detector did not match its own probe; the absence check would be vacuous")

SCAN_DIRS = ["platform", "webroot", "archive-poc/web", "profile-app/web", "membership-pages/web"]
SKIP_SUFFIX = (".md", ".png", ".jpg", ".webp", ".map")
hits = []
scanned = 0
missing = []
for d in SCAN_DIRS:
    base = os.path.join(ROOT, d)
    if not os.path.isdir(base):
        missing.append(d)
        continue
    for dirpath, _dirnames, filenames in os.walk(base):
        for fn in filenames:
            if fn.endswith(SKIP_SUFFIX):
                continue
            fp = os.path.join(dirpath, fn)
            # The step itself legitimately talks about the profile being empty;
            # it is the destination, not a nudge planted on another surface.
            if os.path.abspath(fp) == os.path.abspath(MU):
                continue
            try:
                text = open(fp, encoding="utf-8", errors="ignore").read()
            except OSError:
                continue
            scanned += 1
            m2 = NUDGE.search(text)
            if m2:
                hits.append(f"{os.path.relpath(fp, ROOT)}: {m2.group(0)[:60]!r}")

# COVERAGE, BEFORE THE ABSENCE. The liveness probe above proves the DETECTOR
# works; it says nothing about whether the walk reached any files. Rename or move
# one of these trees and os.path.isdir goes false, the loop skips it in SILENCE,
# and the assertion below still prints "5 member-facing trees" because it counts
# the constant rather than the work. That is the same vacuous-pass this section
# exists to prevent, one level up — an absence is only worth as much as the
# ground it actually covered.
if missing:
    cannot("nudge scan tree(s) missing, so the absence check covers less than it "
           "claims: " + ", ".join(missing))
if scanned == 0:
    cannot("the nudge scan read 0 files; the absence assertion would be vacuous")
ok(f"COVERAGE — the scan really read {scanned} files across all {len(SCAN_DIRS)} trees")

if not hits:
    ok(f"no nudge surface anywhere in {len(SCAN_DIRS)} member-facing trees")
else:
    bad("a profile-completeness NUDGE exists — Ian ruled none, ever",
        "; ".join(hits[:4]))


# ── F. skippers get instructions (sharpening 4) ────────────────────────────────
print("\nF. sharpening 4 — skipping ends with instructions, not a dead end")

if "skipped" in src_mu and re.search(r"[Ww]here to find it later", src_mu):
    ok("?skipped=1 renders the where-to-find-your-profile instructions")
else:
    bad("skipping must land on instructions for finding the profile later")


# ── G. the two exclusions ──────────────────────────────────────────────────────
print("\nG. exclusions — a password change and a gift purchase are not new members")

if re.search(r"!isset\(\$_GET\['change'\]\)", src_pw):
    ok("?change=1 (existing member changing a password) never gets the step")
else:
    bad("?change=1 must be excluded from the profile-setup hand-off")

# Must be the guard around the PROFILE-SETUP block specifically. welcome.php
# carries an unrelated `$kind !== 'gift'` for the manage-subscription hint, and
# the loose regex matched that one — staying green with the real guard removed.
if re.search(r"if\s*\(\$kind\s*!==\s*'gift'\)\s*\{\s*\n\s*\$psFile", src_wel):
    ok("kind=gift (buying codes for others) never gets the step")
else:
    bad("the profile-setup block must itself be guarded by $kind !== 'gift'",
        "a gift buyer is not a new member")


# ── I. THE TESTERS ALLOWLIST — live limited testing, and NOBODY ELSE ──────────
# Ian, 2026-08-15: he wants to walk this on LIVE with a couple of named members
# before flipping it on for everyone. Three states, and the assertion that earns
# its keep is the NEGATIVE one: a member who is not on the list must get the
# byte-identical OFF experience, not a polite refusal page.
#
# Exercised by RUNNING the reader against real temp configs, not by reading the
# source — "off with an empty list" and "off with a list" are different states
# that a source read conflates, and they are the two we ship between.
print("\nI. the testers allowlist — live limited testing, and nobody else")

def _tester_probe(cfg_php, calls):
    d = tempfile.mkdtemp(prefix="gate51-t-")
    try:
        os.makedirs(os.path.join(d, "mu-plugins"))
        os.makedirs(os.path.join(d, "config"))
        shutil.copy(MU, os.path.join(d, "mu-plugins", "lg-profile-setup.php"))
        with open(os.path.join(d, "config", "profile-setup.php"), "w") as fh:
            fh.write("<?php return " + cfg_php + ";")
        return php_eval(
            STUB + f"require '{os.path.join(d, 'mu-plugins', 'lg-profile-setup.php')}';" + calls
        )
    finally:
        shutil.rmtree(d, ignore_errors=True)

CALLS = ("printf('%s|%s|%s|%s',"
         "lg_profile_setup_live()?'Y':'n',"
         "lg_profile_setup_visible_to(7)?'Y':'n',"
         "lg_profile_setup_visible_to(8)?'Y':'n',"
         "lg_profile_setup_visible_to(0)?'Y':'n');")

shipped = _tester_probe("array('enabled'=>false,'testers'=>array())", CALLS)
if shipped == "n|n|n|n":
    ok("SHIPPED (off, empty list) — the route is never registered: a total absence")
else:
    bad("off with an empty testers list must be a total absence", f"reader says {shipped!r}")

listed = _tester_probe("array('enabled'=>false,'testers'=>array(7))", CALLS)
if listed == "Y|Y|n|n":
    ok("off + list — the step exists for member 7, and for NOBODY else")
elif listed.startswith("Y|Y|Y"):
    bad("a non-tester can reach the step — the allowlist is not the discriminator", listed)
else:
    bad("off + testers=[7] must serve exactly member 7", f"reader says {listed!r}")

everyone = _tester_probe("array('enabled'=>true,'testers'=>array())", CALLS)
if everyone == "Y|Y|Y|Y":
    ok("enabled=true — everyone, list no longer matters")
else:
    bad("enabled=true must serve every logged-in member", f"reader says {everyone!r}")

# An anon is never a tester. IDs come from the WordPress login; user 0 is "nobody",
# and a list that admits 0 would admit every logged-out visitor.
anon = _tester_probe("array('enabled'=>false,'testers'=>array(0))", CALLS)
if anon.endswith("|n"):
    ok("a testers list containing 0 does NOT admit logged-out visitors")
else:
    bad("testers=[0] must not admit anonymous visitors", f"reader says {anon!r}")

junk = _tester_probe("array('enabled'=>false,'testers'=>array('x',-3))", CALLS)
if junk == "n|n|n|n":
    ok("a malformed list fails safe to absence, not to everyone")
else:
    bad("a malformed testers list must fail safe", f"reader says {junk!r}")

# Identity is the WordPress login and NOTHING else. A token, a cookie of our own
# or a query parameter would make the list decorative the moment one is guessed —
# and the dev2 gate token does not exist on live at all.
mu_txt = open(MU, encoding="utf-8", errors="ignore").read()
smuggled = [n for n in ("$_GET", "$_COOKIE", "loothdev_auth", "lg_dev_gate")
            if n in mu_txt.split("function lg_profile_setup_visible_to")[-1].split("add_action")[0]]
if not smuggled:
    ok("tester identity comes from the WP login only — no token, cookie or query param")
else:
    bad("the tester check reads something other than the WP login", ", ".join(smuggled))

# BOTH RAILS ask the same per-member question — Ian's dual-rail ruling applies to
# the allowlist too, or a Patreon tester and a Stripe tester get different products.
pw_txt = open(PW, encoding="utf-8", errors="ignore").read()
if "lg_profile_setup_visible_to" in pw_txt:
    ok("Patreon rail asks the per-member question, not the global flag")
else:
    bad("Patreon rail still keys off the global flag — a tester would not see the hand-off")

wel_txt = open(WELCOME, encoding="utf-8", errors="ignore").read()
if "testers" in wel_txt and "wp_user_id" in wel_txt:
    ok("Stripe rail resolves testers from wp_user_id (the WP login cookie)")
else:
    bad("Stripe rail does not honour the testers list — the two rails would diverge")


# ── J. IAN'S THREE ADDITIONS (2026-08-15, after seeing the built screens) ──────
#  1. "throw in some privacy stuff to get them thinking about that"
#  2. "ask them if they want to go to the full profile interface. Especially if
#     we are doing a location"
#  3. "get their user name and gen their slug at this point too"
# His prior ruling is unchanged and still gated above: optional, skip first-class,
# safe defaults on skip, no nudging, both rails identical.
print("\nJ. Ian's three additions of 8/15")

mu = open(MU, encoding="utf-8", errors="ignore").read()
body = mu.split("<body>", 1)[-1]

def code_only(t):
    """Strip comments before asserting on code.

    This gate's history is four assertions that passed or failed on PROSE: two
    matched text in the gate's own docblock, one matched an unrelated line, and
    the me-slug check below first went RED against a COMMENT in the mu-plugin
    that exists purely to explain why the step must never call me-slug.php. An
    assertion that reads documentation is testing the documentation.
    """
    t = re.sub(r"/\*.*?\*/", "", t, flags=re.S)
    t = re.sub(r"^\s*//.*$", "", t, flags=re.M)
    t = re.sub(r"^\s*\*.*$", "", t, flags=re.M)
    return t

mu_code = code_only(mu)

# 3 — the NAME is collected; the HANDLE is DERIVED. Ian's own numbered ruling of
# 7/25 makes handles display-only, and me-slug.php is GET-only, so a slug writer
# here would be a reversal smuggled in as a feature.
if 'id="ps-name"' in body:
    ok("addition 3 — the step collects the member's name")
else:
    bad("no name field — Ian asked for the user name at this point")

if "me-name.php" in mu:
    ok("addition 3 — the handle derives via me-name.php (Provision dedupes it)")
else:
    bad("the name is not sent to me-name.php, so no handle is generated")

# The dedupe lives in Provision::maybeSyncSlugFromName and handles the collisions
# Ian warned about (11 of 436 names). Re-deriving a slug HERE would drift from it.
if re.search(r"me-slug\.php", mu_code):
    bad("the step touches me-slug.php — handles are display-only (Ian, numbered, 7/25)")
else:
    ok("addition 3 — no slug writer: the handle is never edited here, only derived")

for reinvent in ("toLowerCase().replace", "slugify", "slugFit"):
    if reinvent in mu_code:
        bad("the step re-derives a slug client-side", f"found {reinvent!r} — dedupe would drift")
        break
else:
    ok("addition 3 — the slug is not re-invented here; the server's answer is shown")

# 1 — privacy. The dials must be PRE-FILLED from the member's current values and
# sent ONLY when moved, or Save silently rewrites a setting they never looked at —
# which is the opposite of the awareness Ian asked for, and would break his
# "skipping keeps safe defaults" ruling by making Save itself unsafe.
if 'id="ps-privacy"' in body and 'id="ps-vis"' in body:
    ok("addition 1 — the privacy question is on the step")
else:
    bad("no privacy surface — Ian asked to get them thinking about it")

# \b matters: "locvisWas = j." CONTAINS "visWas = j.", so the unanchored version
# matched the location line while the profile line was broken, and the mutation
# that deletes the profile pre-fill sailed through green. Found by red-first, not
# by reading it.
pre = (re.search(r"\bvisWas\s*=\s*j\.", mu) and re.search(r"\blocvisWas\s*=\s*j\.", mu))
if pre:
    ok("addition 1 — both dials are pre-filled from the member's CURRENT values")
else:
    bad("the dials are not pre-filled, so Save would impose a default they never chose")

if re.search(r"if\s*\(\s*visChanged\s*\)", mu) and "locvisChanged" in mu:
    ok("addition 1 — a dial is sent ONLY when the member actually moved it")
else:
    bad("privacy is written unconditionally — Save would rewrite untouched settings")

# 2 — the full-profile door, offered as a QUESTION and strongest with a location.
if "Open the full profile editor" in mu:
    ok("addition 2 — the full profile editor is offered after saving")
else:
    bad("no full-profile door — Ian asked us to ask them")

if re.search(r"var\s+extra\s*=\s*city", mu) or re.search(r"city\s*\n?\s*\?", mu):
    ok("addition 2 — the offer is strongest when they set a location, as ruled")
else:
    bad("the full-profile offer does not respond to a location being set")

# AND THE PRIOR RULING STILL HOLDS over the new fields: skipping writes NOTHING.
skip_branch = mu.split("if ($skipped):", 1)[-1].split("else:", 1)[0] if "if ($skipped):" in mu else ""
if skip_branch and "fetch(" not in skip_branch:
    ok("unchanged ruling — the skip screen still writes nothing at all")
else:
    bad("the skip path can now write — 'skipping keeps safe defaults' would be false")


# ── H. THE PUBLISHED SNAPSHOT AGREES WITH THE SOURCE IT PICTURES ───────────────
# Ian rules from pictures. The two pages under footer-mockups/profiles-alive/built/
# are a RENDER of this feature, published dev-gated so he can look at the built
# thing before it reaches the serve. A render is a DERIVED artifact, and derived
# artifacts go stale in silence: on 2026-08-15 the published skip screen still read
# "Choose My profile" for hours after the source was corrected to "My Profile",
# because the snapshot had been built from a pre-fix capture. Every static check
# stayed green the whole time — the SOURCE was right. Only the picture was wrong,
# and the picture is the thing Ian actually looks at.
print("\nH. the published snapshot shows what the code actually says")

SNAP_DIR = os.path.join(ROOT, "footer-mockups", "profiles-alive", "built")
SNAPS = [os.path.join(SNAP_DIR, "step.html"), os.path.join(SNAP_DIR, "skipped.html")]
# saved.html pictures Ian's addition 2 — the post-save door. It is built by the
# page's JAVASCRIPT, so it cannot be produced by the static builder and cannot be
# checked by the server-markup comparison below; it is captured from the running
# code with fetch stubbed (tools/capture-profile-setup-done.py). It gets the
# INERTNESS check like the other two, plus its own phrase check against the JS.
SAVED = os.path.join(SNAP_DIR, "saved.html")

missing_snap = [os.path.relpath(f, ROOT) for f in SNAPS if not os.path.isfile(f)]
if missing_snap:
    bad("the published snapshot Ian is linked to is missing", ", ".join(missing_snap))
else:
    def own_content(path):
        """The page's OWN markup, with the shared chrome cut away.

        Scoping matters more than it looks. The first version searched the whole
        file and went GREEN against the real staleness it was written to catch:
        the phrase "My Profile" also appears in the site header's account menu, so
        the assertion matched the CHROME of the other page while the instruction
        text under test still said "My profile". Passing for the wrong reason —
        the same decoration bug that already bit three assertions in this gate.
        """
        t = open(path, encoding="utf-8", errors="ignore").read()
        i = t.find('<div class="wrap">')
        if i == -1:
            return ""
        j = t.find("lg-chrome-foot", i)
        return t[i:j if j != -1 else len(t)]

    snap_regions = {f: own_content(f) for f in SNAPS}
    empty = [os.path.relpath(f, ROOT) for f, r in snap_regions.items() if not r.strip()]
    if empty:
        cannot("could not isolate the page's own content in: " + ", ".join(empty)
               + " — the agreement check would compare against nothing")
    snap_text = "".join(snap_regions.values())

    # Compare against the SERVER-RENDERED markup only. Everything after <script>
    # is built in the browser after the member saves — the "Saved." panel with the
    # new profile address and the full-editor door — and a static, script-stripped
    # snapshot can never contain it. Including it made this check demand wording
    # the snapshot is structurally incapable of holding: a permanent false RED,
    # which is as useless as a permanent green and noisier.
    mu_src = open(MU, encoding="utf-8", errors="ignore").read().split("<script>")[0]

    # Take the member-visible literals straight out of the source rather than
    # listing them here — a hardcoded list is itself a derived artifact and would
    # drift the same way the snapshot did.
    phrases = set()
    # Labels and the privacy heading are included deliberately: Ian's 8/15
    # additions are mostly LABELS ("Your name", "Your profile", "Where you are"),
    # and a version that only read <strong>/<h2> would have left every one of the
    # new questions free to go stale in the picture without anything noticing.
    for pat in (r"<strong>([^<>]{4,60})</strong>",
                r"<h2>([^<>{}]{4,60})</h2>",
                r"<h3>([^<>{}]{4,60})</h3>",
                r'<label[^>]*>([^<>{}$?]{4,60})</label>',
                r'<div class="privacy__h">([^<>{}]{4,60})</div>'):
        for m in re.findall(pat, mu_src):
            t = m.strip()
            if "<?" in t or "?>" in t or "$" in t:
                continue          # PHP-interpolated: renders to something else
            phrases.add(t)

    # COVERAGE, the lesson from section E: an agreement check that compares nothing
    # passes on any snapshot at all. Prove the extraction actually found phrases.
    # RED, not CANNOT RUN. This guard exists so the comparison cannot be vacuous —
    # but it reads the SOURCE, and the source losing its member-visible wording is a
    # DEFECT, which section F above already reports. Raising CANNOT RUN here would
    # overwrite that exit code (2 beats 1) and re-report a real finding as a missing
    # environment. Measured: the mutation that deletes the skipper instructions made
    # this gate exit 2 instead of 1, and the red-first harness caught it.
    if len(phrases) < 4:
        bad(f"only {len(phrases)} member-visible phrases found in the source — either the "
            "wording was deleted, or this gate's extraction broke; either way the "
            "snapshot cannot be checked against anything")
    ok(f"COVERAGE — {len(phrases)} member-visible phrases extracted from the source, "
       f"checked against {len(snap_text)} bytes of page-own markup (chrome excluded)")

    # Compare like with like. The source writes &mdash; and wraps lines; the
    # published page may hold either form. An earlier cut decoded entities on the
    # phrase side ONLY, which reported a correctly-published line as stale — a
    # false RED caused entirely by the gate's own normalisation.
    def norm(t):
        return " ".join(html.unescape(t).split())

    snap_norm = norm(snap_text)
    stale = sorted(t for t in phrases if norm(t) not in snap_norm)
    if stale:
        bad("the published snapshot is STALE — it shows Ian wording the code no longer has",
            "; ".join(repr(t) for t in stale[:4]))
    else:
        ok(f"all {len(phrases)} phrases in the source appear in the published snapshot")

    # And it must stay inert. It is a picture, not a working form: the captured page
    # carried the live JS that POSTs to the real profile-api write endpoints.
    # The post-save picture, checked against the JS that actually builds it.
    if not os.path.isfile(SAVED):
        bad("the post-save screen Ian is linked to is missing",
            os.path.relpath(SAVED, ROOT))
    else:
        saved_txt = open(SAVED, encoding="utf-8", errors="ignore").read()
        js_src = open(MU, encoding="utf-8", errors="ignore").read().split("<script>", 1)[-1]
        js_phrases = set()
        for m in re.findall(r"<h3>([^<>{}'\"]{4,60})</h3>", js_src):
            js_phrases.add(m.strip())
        for lit in ("Open the full profile editor", "Take me to the community"):
            if lit in js_src:
                js_phrases.add(lit)
        if len(js_phrases) < 2:
            bad(f"only {len(js_phrases)} phrases found in the post-save JS — "
                "the picture cannot be checked against anything")
        else:
            gone = sorted(t for t in js_phrases if norm(t) not in norm(saved_txt))
            if gone:
                bad("the post-save picture is STALE against the code that builds it",
                    "; ".join(repr(t) for t in gone[:3]))
            else:
                ok(f"the post-save picture matches all {len(js_phrases)} phrases in its JS")

    live = []
    for f in SNAPS + [SAVED]:
        t = open(f, encoding="utf-8", errors="ignore").read()
        if re.search(r"<script", t, re.I):
            live.append(os.path.relpath(f, ROOT) + ": a <script> survived")
        if "profile-api" in t:
            live.append(os.path.relpath(f, ROOT) + ": a real write endpoint survived")
    if live:
        bad("the published snapshot is not inert — it can act, not just illustrate",
            "; ".join(live))
    else:
        ok("the snapshot is inert: no script, no write endpoint on either page")



# ── K. DARK: EVERY LABEL CLEARS AA AGAINST WHAT IT ACTUALLY RENDERS ON ─────────
# Ian, 2026-08-16, from a screenshot: the section headers were invisible in dark.
# Measured at the time: 'Your name' 1.11:1, the privacy title 1.10:1, against a
# 4.5 bar. The cause was NOT in this file's CSS being wrong on its own terms — an
# injected html[data-lguser-theme="dark"] body{color:#e5e7e1!important} supplied
# the ink to every element that named no colour, while .card and .privacy pinned
# LIGHT fills. So a source-reading assertion would have been GREEN on the defect,
# and that is why this section drives a real browser.
#
# THE BAR IS RENDERED CONTRAST, NOT THE PRESENCE OF A DARK BLOCK. A gate that
# asserts "a dark rule exists" passes the moment somebody writes one, whether or
# not it wins the cascade — which is precisely the failure this is guarding.
#
# TWO DIRECTIONS, because the defect had two: elements that INHERIT the theme ink
# fail on a light surface, and elements that NAME their own dark ink fail on the
# dark page. .lede was the second kind at 1.88:1 and was not in the report.
#
# INJECTED APP CHROME IS EXCLUDED. The docroot injects /pwa.js into the snapshot,
# which mounts the hub menu sheet and the notifications modal; their headings sit
# in .lg-* containers and are NOT this page's surface. A visual gate that
# photographs them reports another component's defects as this one's, and this
# repo has already had a gate whose counts moved on 10 of 24 surfaces for exactly
# that reason.
AA_TEXT = 4.5

DARK_PROBE = r"""
(function () {
  function parse(s){ if(!s) return null;
    var m=/rgba?\(([^)]+)\)/.exec(s); if(!m) return null;
    var p=m[1].split(',').map(function(x){return parseFloat(x.trim())});
    if(p.length<3||p.some(isNaN)) return null;
    return {r:p[0],g:p[1],b:p[2],a:p.length>3?p[3]:1}; }
  function lum(c){ var ch=[c.r,c.g,c.b].map(function(v){ v=v/255;
    return v<=0.03928? v/12.92 : Math.pow((v+0.055)/1.055,2.4); });
    return 0.2126*ch[0]+0.7152*ch[1]+0.0722*ch[2]; }
  function ratio(f,b){ var A=lum(f),B=lum(b); var hi=Math.max(A,B),lo=Math.min(A,B);
    return (hi+0.05)/(lo+0.05); }
  function over(src,dst){ var a=src.a; if(a>=1) return {r:src.r,g:src.g,b:src.b,a:1};
    if(a===0) return dst;
    return {r:src.r*a+dst.r*(1-a), g:src.g*a+dst.g*(1-a), b:src.b*a+dst.b*(1-a), a:1}; }
  function hex(c){ function h(v){var s=Math.round(Math.max(0,Math.min(255,v))).toString(16);
    return s.length<2?'0'+s:s;} return '#'+h(c.r)+h(c.g)+h(c.b); }
  // Composite ancestors UNTIL opaque. Starting at the element itself compares a
  // panel to itself; stopping at the first non-transparent value ignores alpha.
  function effBg(el){
    var acc={r:0,g:0,b:0,a:0}, n=el, chrome=false;
    while(n){
      if(n.className && /\blg-/.test(String(n.className))) chrome=true;
      var c=parse(getComputedStyle(n).backgroundColor);
      if(c && c.a>0){
        acc = (acc.a===0) ? {r:c.r,g:c.g,b:c.b,a:c.a} : over(acc,c);
        if(acc.a>=1) return {c:acc, chrome:chrome};
      }
      n=n.parentElement;
    }
    var pg=parse(getComputedStyle(document.body).backgroundColor)||{r:255,g:255,b:255,a:1};
    return {c: acc.a>0? over(acc,pg) : pg, chrome:chrome};
  }
  var out=[];
  ['h2','.lede','label','.privacy__h','.hint','.found h3'].forEach(function(sel){
    Array.prototype.slice.call(document.querySelectorAll(sel)).forEach(function(el){
      var cs=getComputedStyle(el);
      if(cs.display==='none'||cs.visibility==='hidden') return;
      var txt=(el.innerText||'').trim(); if(!txt) return;
      var fg=parse(cs.color); if(!fg) return;
      var bg=effBg(el);
      if(bg.chrome) return;                 // injected app chrome, not this page
      var fgc = fg.a<1 ? over(fg,bg.c) : fg;
      out.push({sel:sel,text:txt.slice(0,40),fg:hex(fgc),bg:hex(bg.c),
                ratio:Math.round(ratio(fgc,bg.c)*100)/100});
    });
  });
  return JSON.stringify({
    theme: document.documentElement.getAttribute('data-lguser-theme')||'(none)',
    cardBg: (document.querySelector('.card')? getComputedStyle(document.querySelector('.card')).backgroundColor : ''),
    text: (document.body.innerText||'').slice(0,8000),
    is403: /403|Forbidden/i.test(document.body.innerText.slice(0,200)),
    rows: out
  });
})()
"""


def _dark_unavailable(why):
    """Environment, not the code under test. But NEVER discard a RED already
    recorded above: cannot() exits 2 immediately, and run-all.sh reads 2 as
    'could not run', so calling it here with fails pending would convert a real
    finding into a shrug. Only a clean run may report CANNOT RUN."""
    if fails:
        print(f"  ..  dark section SKIPPED ({why}) — earlier findings stand, verdict below")
        return
    cannot(f"dark contrast section: {why}")


def _run_dark():
    import json as _json
    import time as _time
    import urllib.request as _url
    try:
        import websocket
    except ImportError:
        return _dark_unavailable("python websocket-client is not installed")
    try:
        _url.urlopen("http://127.0.0.1:9222/json/version", timeout=5).read()
    except Exception:
        return _dark_unavailable("no headless Chrome on 127.0.0.1:9222")

    tokf = os.path.join(ROOT, "tools", "gates", "gate-env.sh")
    tok = ""
    try:
        r = subprocess.run(["bash", tokf], capture_output=True, text=True, timeout=30)
        for line in r.stdout.splitlines():
            if line.startswith("LG_GATE_TOKEN="):
                tok = line.split("=", 1)[1]
    except Exception:
        pass
    if not tok:
        return _dark_unavailable("no dev-gate token from gate-env.sh")

    BASE = "https://dev2.loothgroup.com/footer-mockups/profiles-alive/built/"
    # ALL THREE published screens, each with a marker only IT can contain.
    #
    # §K measured only step.html to begin with, and that was a real blind spot:
    # saved.html is captured by a DIFFERENT script from the running JS, so it did
    # not pick up the dark fix when the other two were rebuilt, and no assertion
    # anywhere would have noticed. §H compares PHRASES, which are unchanged by a
    # styling regression. A per-screen marker rather than one shared selector,
    # because the three are genuinely different documents — the skip screen has no
    # form at all.
    SCREENS = (
        ("step",    "step.html",    "Set up your profile"),
        ("skipped", "skipped.html", "No problem"),
        ("saved",   "saved.html",   "Open the full profile editor"),
    )
    DESK = {"width": 1440, "height": 900, "mobile": False, "deviceScaleFactor": 1}
    PHONE = {"width": 390, "height": 844, "mobile": True, "deviceScaleFactor": 2}

    t = _json.load(_url.urlopen(_url.Request("http://127.0.0.1:9222/json/new?about:blank",
                                             method="PUT"), timeout=15))
    ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                     timeout=20, suppress_origin=True)
    box = {"i": 0}

    def call(m, **p):
        box["i"] += 1
        ws.send(_json.dumps({"id": box["i"], "method": m, "params": p}))
        while True:
            msg = _json.loads(ws.recv())
            if msg.get("id") == box["i"]:
                if "error" in msg:
                    raise RuntimeError(f"{m}: {msg['error']}")
                return msg.get("result", {})

    def js(e):
        r = call("Runtime.evaluate", expression=e, returnByValue=True, awaitPromise=True)
        return r.get("result", {}).get("value")

    def goto(u, settle=1.6):
        call("Page.navigate", url=u)
        start = _time.monotonic()
        while _time.monotonic() - start < 25:
            _time.sleep(0.15)
            try:
                if js("document.readyState") == "complete":
                    break
            except Exception:
                continue
        _time.sleep(settle)

    measured = 0
    try:
        call("Page.enable"); call("Runtime.enable"); call("Network.enable")
        for device, metrics in (("desktop", DESK), ("mobile", PHONE)):
            for mode in ("light", "app-dark", "os-dark"):
                call("Emulation.setDeviceMetricsOverride", **metrics)
                call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
                call("Emulation.setEmulatedMedia", features=[
                    {"name": "prefers-color-scheme",
                     "value": "dark" if mode == "os-dark" else "light"}])
                for screen, fname, marker in SCREENS:
                    url = BASE + fname
                    tag = f"{screen}/{device}/{mode}"
                    # Clear FIRST: setCookie ADDS rather than replaces, and a
                    # leftover session from another lane would measure a
                    # different page.
                    call("Network.clearBrowserCookies")
                    call("Network.setCookie", name="loothdev_auth", value=tok,
                         domain=".dev2.loothgroup.com", path="/", secure=True)
                    goto(url, settle=0.8)
                    if mode == "app-dark":
                        js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
                    else:
                        js("try{localStorage.clear()}catch(e){}")
                    goto(url, settle=1.4)
                    if mode == "app-dark":
                        goto(url, settle=1.8)
                    data = _json.loads(js(DARK_PROBE))

                    # LIVENESS. A dev-gate 403 is a styled page that measures
                    # beautifully and tells you nothing; so does a dark run that
                    # never actually went dark, and so does the WRONG screen.
                    if data.get("is403") or marker not in (data.get("text") or ""):
                        bad(f"dark/{tag}: the screen did not render "
                            f"(gate 403, missing snapshot, or no {marker!r})",
                            "every ratio below would have been measured on the wrong page")
                        continue
                    if mode != "light" and data.get("theme") != "dark":
                        bad(f"dark/{tag}: dark never resolved "
                            f"(data-lguser-theme={data.get('theme')!r})",
                            "the assertions would have passed by measuring the LIGHT page")
                        continue
                    if not data.get("rows"):
                        bad(f"dark/{tag}: probe returned no rows",
                            "an all-pass with nothing measured is not a pass")
                        continue

                    worst = min(r["ratio"] for r in data["rows"])
                    failing = [r for r in data["rows"] if r["ratio"] < AA_TEXT]
                    measured += len(data["rows"])
                    if failing:
                        worst_rows = sorted(failing, key=lambda r: r["ratio"])[:3]
                        bad(f"dark/{tag}: {len(failing)} of {len(data['rows'])} "
                            f"labels fail AA on the rendered background",
                            "; ".join(f"{r['text']!r} {r['ratio']}:1 ({r['fg']} on {r['bg']})"
                                      for r in worst_rows))
                    else:
                        ok(f"{tag}: all {len(data['rows'])} labels clear AA "
                           f"(worst {worst}:1)")
    finally:
        # NEVER leave the shared chrome profile stamped dark: app-settings.js
        # persists the pick and this profile is shared with every other lane —
        # a stamped theme once turned every lane's browser dark for a whole run.
        try:
            goto(BASE + "step.html", settle=0.4)
            js("try{localStorage.clear()}catch(e){}")
        except Exception:
            pass
        for fn in (lambda: ws.close(),
                   lambda: _url.urlopen("http://127.0.0.1:9222/json/close/" + t["id"],
                                        timeout=10).read()):
            try:
                fn()
            except Exception:
                pass

    if measured == 0:
        bad("dark section measured nothing at all",
            "six combinations ran and not one produced a row — treat as unmeasured, not as clean")


print("\nK. dark — every label clears AA against its ACTUAL rendered background")
_run_dark()

# ── verdict ────────────────────────────────────────────────────────────────────
print(f"\n{checks} checks run.")
if fails:
    print("\nGATE 51 RED:")
    for f in fails:
        print("  ✗ " + f)
    sys.exit(1)
print("GATE 51 GREEN — flag OFF is a no-op, both rails wired, all four of Ian's "
      "sharpenings held, no nudge surface exists, and every label clears AA in "
      "both themes at both widths.")
