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

# The route must be registered INSIDE the enabled-check, so OFF means "not a
# route", not "a route that renders nothing".
m = re.search(r"add_action\('init',\s*function\s*\(\)\s*\{(.{0,400})", src_mu, re.S)
if m and re.search(r"if\s*\(!lg_profile_setup_enabled\(\)\)\s*return;", m.group(1)):
    ok("route registration returns early when the flag is OFF")
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

if "lg_profile_setup_enabled()" in src_pw and "lg_profile_setup_path()" in src_pw:
    ok("Patreon rail (lgpo-set-password.php) hands off to the step")
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
for d in SCAN_DIRS:
    base = os.path.join(ROOT, d)
    if not os.path.isdir(base):
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
            m2 = NUDGE.search(text)
            if m2:
                hits.append(f"{os.path.relpath(fp, ROOT)}: {m2.group(0)[:60]!r}")

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


# ── verdict ────────────────────────────────────────────────────────────────────
print(f"\n{checks} checks run.")
if fails:
    print("\nGATE 51 RED:")
    for f in fails:
        print("  ✗ " + f)
    sys.exit(1)
print("GATE 51 GREEN — flag OFF is a no-op, both rails wired, all four of Ian's "
      "sharpenings held, and no nudge surface exists.")
