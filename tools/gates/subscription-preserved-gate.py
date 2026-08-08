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


def wp(sql_or_args, mode=None, topic=None, user=1):
    env = ["env"]
    if mode:
        env += [f"LG_PFS_MODE={mode}", f"LG_PFS_TOPIC={topic}", f"LG_PFS_USER={user}"]
    cmd = ["sudo", "-n", "-u", WPUSER] + env + [
        "bash", "-lc", f"cd {DOCROOT} && wp {sql_or_args} 2>&1"
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
