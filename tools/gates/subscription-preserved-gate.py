#!/usr/bin/env python3
"""
subscription-preserved-gate — participation must never silently unsubscribe you.

WHAT IT GUARDS. BuddyBoss reads the ABSENCE of a subscription field as "the member
unticked the box" and removes their subscription (bbp_update_reply, replies/functions
.php:996-1008, hooked to bbp_new_reply AND bbp_edit_reply at priority 10; the identical
block for topics at class-bp-rest-topics-endpoint.php:1622). Our composer replaced
BuddyBoss's form in June 2026 and sends no such field, so posting a reply — or editing
a discussion — destroyed the author's subscription. Silently, on a save that succeeded.
Same class as edit-post-parity (b99570b).

    THE NEGATIVE CONTROL IS THE POINT OF THIS FILE.

"Still subscribed after posting" is trivially true on a box where the reply never
posted, where forums are disabled, or where the member was never subscribed to begin
with. So the gate does not merely assert the fixed behaviour: it runs the SAME probe
with the repair NOT LOADED and requires that to REPRODUCE THE DATA LOSS. If today's
code does not fail, the probe is not exercising the defect and this gate has no verdict
to give — it reports DEAD rather than a green it has not earned.

Three modes, each a separate WP process because the flag is a constant and constants
cannot be redefined:

    nofix    repair absent      → MUST unsubscribe, on BOTH routes   (negative control)
    fix-on   repair, flag ON    → MUST preserve,    on BOTH routes
    fix-off  repair, flag OFF   → MUST match nofix exactly           (the no-op claim)

⚠️ IT READS THE SHIPPED DEFAULT RATHER THAN ASSUMING IT. The constant in the mu-plugin
is reported, not hardcoded here, so flipping it needs no gate edit — only the per-state
assertions above, which hold whichever way it is set.

Exit codes follow run-all.sh: 0 green, 1 RED (real finding), 2 CANNOT RUN (no verdict).
"""

import os
import re
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROBE = os.path.join(REPO, "tools", "gates", "subscription-preserved-probe.php")
MU = os.path.join(REPO, "platform", "mu-plugins", "lg-preserve-forum-subscription.php")
DOCROOT = os.environ.get("LG_PFS_DOCROOT", "/var/www/dev")
WPUSER = os.environ.get("LG_PFS_WPUSER", "looth-dev")

RED, GREEN, DEAD = [], [], []


BOOT = os.path.join(REPO, "tools", "gates", "flag-boot.php")

# Flag state is forced PRE-BOOT via `wp --require`, because once the mu-plugin is
# deployed WordPress has already loaded it and the probe cannot define its constant.
FLAG_FOR_MODE = {"nofix": "1", "fix-on": "1", "fix-off": "0"}


def wp(sql_or_args, mode=None, topic=None, user=1):
    env = ["env"]
    pre = ""
    if mode:
        env += [f"LG_PFS_MODE={mode}", f"LG_PFS_TOPIC={topic}", f"LG_PFS_USER={user}",
                f"LG_BOOT_PFS={FLAG_FOR_MODE[mode]}"]
        pre = f"--require={BOOT} "
    cmd = ["sudo", "-n", "-u", WPUSER] + env + [
        "bash", "-lc", f"cd {DOCROOT} && wp {pre}{sql_or_args} 2>&1"
    ]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
        return p.returncode, p.stdout
    except Exception as exc:  # noqa: BLE001
        return 1, f"EXEC_FAILED {exc}"


def parse(out):
    d = {}
    for line in out.splitlines():
        m = re.match(r"^([A-Z_]+)=(.*)$", line.strip())
        if m:
            d[m.group(1)] = m.group(2)
    return d


# ── the shipped default, READ not assumed ────────────────────────────────────
shipped = None
try:
    with open(MU, "r", encoding="utf-8") as fh:
        src = fh.read()
    m = re.search(r"define\(\s*'LG_PRESERVE_FORUM_SUBSCRIPTION'\s*,\s*(true|false)\s*\)", src)
    shipped = m.group(1) if m else None
except OSError:
    DEAD.append(f"cannot read {MU} — the repair this gate guards is not in the tree")

if shipped is None and not DEAD:
    RED.append(
        "lg-preserve-forum-subscription.php no longer defines "
        "LG_PRESERVE_FORUM_SUBSCRIPTION — the repair cannot be switched on or off, and "
        "this gate cannot say which state is shipped."
    )
elif shipped:
    GREEN.append(f"shipped default: LG_PRESERVE_FORUM_SUBSCRIPTION = {shipped} (read, not assumed)")

# ── find a usable topic on this box ──────────────────────────────────────────

# ── ⚠️ WHICH COPY DID WE ACTUALLY TEST? ───────────────────────────────────────
# Once the mu-plugin is deployed, WordPress loads it from the SERVING CHECKOUT and the
# probe exercises that file — not the one in this branch. A branch whose changes are
# unmerged would then go green on somebody else's code. That is the standing "a lane
# verifying on dev2 is usually testing MAIN" trap, so it is encoded rather than
# remembered: if the two copies differ, this gate has no verdict about THIS branch.
SERVE = os.environ.get("LG_SERVE_ROOT", "/home/ubuntu/loothplatformv2-clean")


def _md5(path):
    try:
        import hashlib
        with open(path, "rb") as fh:
            return hashlib.md5(fh.read()).hexdigest()
    except OSError:
        return None


def check_copy_drift(mu_path, label):
    served = os.path.join(SERVE, "platform", "mu-plugins", os.path.basename(mu_path))
    a, b = _md5(mu_path), _md5(served)
    if b is None:
        GREEN.append(f"{label}: not deployed on this box — the branch copy is under test")
    elif a == b:
        GREEN.append(f"{label}: branch and serving copies are identical (md5 {a[:8]})")
    else:
        DEAD.append(
            f"{label}: the SERVING copy differs from this branch (branch {a[:8]} vs "
            f"serving {b[:8]}). WordPress loads the serving copy, so this run says "
            "nothing about the branch's changes. Merge+pull, or run on a box without "
            "the symlink, before trusting a verdict."
        )

check_copy_drift(MU, "lg-preserve-forum-subscription.php")

topic = os.environ.get("LG_PFS_TOPIC")
if not topic:
    rc, out = wp(
        'db query "SELECT ID FROM wp_posts WHERE post_type=\'topic\' AND post_status=\'publish\' '
        'ORDER BY ID DESC LIMIT 1;" --skip-column-names'
    )
    ids = re.findall(r"^\s*(\d+)\s*$", out, re.M)
    topic = ids[0] if ids else None

if not topic:
    DEAD.append("no published topic on this box — nothing to exercise the defect against")

results = {}
if not DEAD:
    for mode in ("nofix", "fix-on", "fix-off"):
        rc, out = wp(f"eval-file {PROBE}", mode=mode, topic=topic)
        d = parse(out)
        results[mode] = d
        if d.get("ERROR"):
            DEAD.append(f"{mode}: probe reported ERROR={d['ERROR']}")
        elif d.get("DONE") != "1":
            DEAD.append(f"{mode}: probe did not complete (wp rc={rc})")
        elif d.get("LIVENESS") != "ok":
            DEAD.append(f"{mode}: liveness probe failed — forums/subscriptions unavailable")
        elif d.get("REPLY_ERROR") or d.get("TOPIC_ERROR"):
            # An errored REST request proves nothing either way, and once read as a pass.
            DEAD.append(
                f"{mode}: a REST leg errored "
                f"({d.get('REPLY_ERROR') or ''}{d.get('TOPIC_ERROR') or ''}) — no verdict"
            )
        elif d.get("RESTORED") != "yes":
            RED.append(f"{mode}: probe did not restore the box to its entry state")

if not DEAD and not RED:
    nofix, on, off = results["nofix"], results["fix-on"], results["fix-off"]

    # 1. NEGATIVE CONTROL — today's code must FAIL, on both routes.
    for leg, key in (("reply", "REPLY_AFTER"), ("topic edit", "TOPIC_AFTER")):
        if nofix.get(key) == "NOT-subscribed":
            GREEN.append(f"negative control: without the repair, {leg} DOES unsubscribe (defect reproduced)")
        else:
            DEAD.append(
                f"negative control did not reproduce the {leg} data loss ({key}={nofix.get(key)}). "
                "Either BuddyBoss changed, or this probe is not exercising the defect — "
                "so a green below would be unearned. NO VERDICT."
            )

if not DEAD and not RED:
    # 2. THE REPAIR — flag ON preserves, on both routes.
    for leg, key in (("reply", "REPLY_AFTER"), ("topic edit", "TOPIC_AFTER")):
        if on.get(key) == "subscribed":
            GREEN.append(f"flag ON: a subscribed member's {leg} PRESERVES the subscription")
        else:
            RED.append(
                f"flag ON: {leg} still unsubscribes the member ({key}={on.get(key)}). "
                "The repair does not cover this route — participation still destroys data."
            )

    # 3. THE NO-OP CLAIM — flag OFF must be indistinguishable from the repair's absence.
    for leg, key in (("reply", "REPLY_AFTER"), ("topic edit", "TOPIC_AFTER")):
        if off.get(key) == nofix.get(key):
            GREEN.append(f"flag OFF: {leg} behaves exactly as with the repair absent ({off.get(key)})")
        else:
            RED.append(
                f"flag OFF is NOT a no-op for {leg}: absent={nofix.get(key)} vs off={off.get(key)}"
            )

    if on.get("HOOKED") != "yes" or off.get("HOOKED") != "yes":
        RED.append("the repair did not register its filter when loaded — it cannot be doing anything")

    # The negative control simulates absence by UNHOOKING (a loaded mu-plugin cannot be
    # unloaded). Prove the unhook actually happened, or "the defect reproduced" is a
    # claim about a run that still had the repair attached.
    if nofix.get("HOOKED") == "no":
        GREEN.append("negative control genuinely ran with the repair detached (HOOKED=no)")
    else:
        DEAD.append(
            f"negative control did NOT detach the repair (HOOKED={nofix.get('HOOKED')}) — "
            "it was not testing the unrepaired path, so nothing below it is earned"
        )

    # Which copy is under test matters: a green against the branch file says nothing
    # about the box, and a green against the deployed file says nothing about the branch.
    src = on.get("SOURCE")
    if src:
        GREEN.append(f"exercised the {src} copy of the repair")

for line in GREEN:
    print("  PASS  %s" % line)
for line in DEAD:
    print("  DEAD  %s" % line)
for line in RED:
    print("  FAIL  %s" % line)

if DEAD and not RED:
    print("subscription-preserved: NO VERDICT (%d probe(s) could not run or could not be trusted)" % len(DEAD))
    sys.exit(2)
if RED:
    print("subscription-preserved: RED (%d finding(s))" % len(RED))
    sys.exit(1)
print("subscription-preserved: GREEN (%d assertion(s))" % len(GREEN))
sys.exit(0)
