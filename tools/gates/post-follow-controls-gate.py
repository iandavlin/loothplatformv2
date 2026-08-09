#!/usr/bin/env python3
"""
post-follow-controls-gate — ruling 6's defaults, asserted per state.

Ian, 2026-08-08 (re-amended): the composer and reply box carry both follow controls,
🔔 Notifications TICKED by default and ✉ Emails PRESENT but UNTICKED. The two write to
different stores that fail independently:

    🔔  forums.topic_follow          (Postgres, ours)  → the notification bridge's leg 4
    ✉   wp_bb_notifications_subscriptions type='topic' → the follow roundup

    THE DEFAULT IS THE ASSERTION MOST LIKELY TO ROT, so it is the one pinned hardest:
    a post at the defaults must create the 🔔 row and must NOT create the ✉ row.

"✉ unticked" is not a cosmetic default. It is the difference between a member who chose
email and a member who was signed up by posting, and it silently inverts if anyone later
passes `subscribe` from the composer by reflex.

Four modes, one process each because the flag is a constant:

    absent      mu-plugin not loaded          — the pre-feature baseline
    off         flag OFF, request asks anyway — must equal `absent` EXACTLY
    on-default  🔔 ticked, ✉ unticked         — follow row YES, roundup row NO
    on-email    member also ticks ✉           — both rows YES

⚠️ WHAT THE FLAG DOES NOT GATE, stated here so nobody reads the OFF row as more than it
is: `subscribe` is BuddyBoss's own REST parameter and predates this feature. Our flag
gates the 🔔 write we add; it does not and must not suppress a native BB param. That is
why OFF is asserted against `absent` rather than against "nothing was written" — the
claim is "this feature changes nothing when off", which is the claim that matters.

This gate does NOT assert that a bell notification is delivered. The 🔔 row is a follow
record; delivery is the notification bridge's contract (leg 4, notify-bridge.php:232),
and a gate asserting someone else's behaviour would go red for their reasons.

Exit codes follow run-all.sh: 0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
"""

import os
import re
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
PROBE = os.path.join(REPO, "tools", "gates", "post-follow-controls-probe.php")
MU = os.path.join(REPO, "platform", "mu-plugins", "lg-post-follow-controls.php")
DOCROOT = os.environ.get("LG_PFC_DOCROOT", "/var/www/dev")
WPUSER = os.environ.get("LG_PFC_WPUSER", "looth-dev")

RED, GREEN, DEAD = [], [], []


BOOT = os.path.join(REPO, "tools", "gates", "flag-boot.php")

# Forced PRE-BOOT via `wp --require`: once deployed, WordPress has already loaded the
# mu-plugin and the probe can no longer define its constant.
FLAG_FOR_MODE = {"absent": "0", "off": "0", "on-default": "1", "on-email": "1"}


def wp(args, mode=None, topic=None, user=1):
    env = ["env"]
    pre = ""
    if mode:
        env += [f"LG_PFC_MODE={mode}", f"LG_PFC_TOPIC={topic}", f"LG_PFC_USER={user}",
                f"LG_BOOT_PFC={FLAG_FOR_MODE[mode]}"]
        pre = f"--require={BOOT} "
    cmd = ["sudo", "-n", "-u", WPUSER] + env + ["bash", "-lc", f"cd {DOCROOT} && wp {pre}{args} 2>&1"]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=180)
        return p.returncode, p.stdout
    except Exception as exc:  # noqa: BLE001
        return 1, f"EXEC_FAILED {exc}"


def parse(out):
    return {m.group(1): m.group(2)
            for m in (re.match(r"^([A-Z_]+)=(.*)$", l.strip()) for l in out.splitlines()) if m}


# ── the shipped default, READ not assumed ────────────────────────────────────
# The flag is COMPUTED from a tracked config shared with bb-mirror's UI, so the value
# lives in platform/config/post-follow.php and the mu-plugin only wires it up. Read
# both: the config for the state, the mu-plugin for the wiring. Asserting a literal
# in the define would have broken the moment the two runtimes were given one source
# of truth — which is exactly what happened, and this gate caught it.
CONFIG = os.path.join(REPO, "platform", "config", "post-follow.php")
try:
    with open(MU, "r", encoding="utf-8") as fh:
        src = fh.read()
    if "define( 'LG_POST_FOLLOW_CONTROLS'" not in src:
        RED.append("lg-post-follow-controls.php no longer defines LG_POST_FOLLOW_CONTROLS")
    elif "lg_pfc_config_enabled()" not in src:
        RED.append(
            "LG_POST_FOLLOW_CONTROLS no longer reads the tracked config — the UI flag "
            "and the write flag can now disagree, which renders a control that does nothing"
        )
    else:
        GREEN.append("the flag is wired to the tracked config (not a literal in the define)")
except OSError:
    DEAD.append(f"cannot read {MU} — the feature this gate guards is not in the tree")

try:
    with open(CONFIG, "r", encoding="utf-8") as fh:
        cfg = fh.read()
    m = re.search(r"'enabled'\s*=>\s*(true|false)", cfg)
    if m:
        GREEN.append(f"shipped default: post-follow.php enabled = {m.group(1)} (read, not assumed)")
    else:
        RED.append("platform/config/post-follow.php no longer states 'enabled' => true|false")
except OSError:
    DEAD.append(f"cannot read {CONFIG} — the shared flag this feature depends on is missing")



# ── the wp user must be able to READ our files, or the failure is unreadable ──
# `wp --require=` reports only "Required file 'x' doesn't exist" when the path is
# merely unreadable by the running user, which sends the next person hunting a
# missing file that is right there. A lane worktree under an unreadable parent hits
# this, so say the real thing instead.
def _readable_by_wpuser(path):
    p = subprocess.run(["sudo", "-n", "-u", WPUSER, "test", "-r", path],
                       capture_output=True, text=True)
    return p.returncode == 0


for _p, _what in ((BOOT, "flag-boot.php"), (PROBE, "the probe")):
    if not _readable_by_wpuser(_p):
        DEAD.append(
            f"{_what} at {_p} is not readable by {WPUSER}. WP-CLI will report it as "
            f"\"doesn't exist\", which it does. Make the path traversable+readable "
            f"for {WPUSER} (this bites when a gate is run from an unreadable directory)."
        )

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

check_copy_drift(MU, "lg-post-follow-controls.php")

topic = os.environ.get("LG_PFC_TOPIC")
if not topic and not DEAD:
    rc, out = wp('db query "SELECT ID FROM wp_posts WHERE post_type=\'topic\' AND '
                 'post_status=\'publish\' ORDER BY ID DESC LIMIT 1;" --skip-column-names')
    ids = re.findall(r"^\s*(\d+)\s*$", out, re.M)
    topic = ids[0] if ids else None
if not topic and not DEAD:
    DEAD.append("no published topic on this box — nothing to post into")

r = {}
if not DEAD:
    for mode in ("absent", "off", "on-default", "on-email"):
        rc, out = wp(f"eval-file {PROBE}", mode=mode, topic=topic)
        d = parse(out)
        r[mode] = d
        if d.get("ERROR"):
            DEAD.append(f"{mode}: probe reported ERROR={d['ERROR']}")
        elif d.get("DONE") != "1":
            DEAD.append(f"{mode}: probe did not complete (wp rc={rc})")
        elif d.get("LIVENESS") != "ok":
            DEAD.append(f"{mode}: the follow store was unreachable — every row count below is meaningless")
        elif d.get("POST_ERROR"):
            DEAD.append(f"{mode}: the post itself errored ({d['POST_ERROR']}) — no verdict")
        elif d.get("START_FOLLOW") != "0" or d.get("START_SUB") != "0":
            DEAD.append(f"{mode}: probe did not start from a clean state "
                        f"(follow={d.get('START_FOLLOW')} sub={d.get('START_SUB')})")
        elif d.get("RESTORED") != "yes":
            RED.append(f"{mode}: probe did not restore the box to its entry state")

if not DEAD and not RED:
    absent, off, dflt, mail = r["absent"], r["off"], r["on-default"], r["on-email"]

    # 1. THE NO-OP: off must be indistinguishable from the feature not existing.
    same = (off.get("AFTER_FOLLOW") == absent.get("AFTER_FOLLOW")
            and off.get("AFTER_SUB") == absent.get("AFTER_SUB"))
    if same:
        GREEN.append("flag OFF is indistinguishable from the mu-plugin being absent "
                     f"(follow={off.get('AFTER_FOLLOW')} sub={off.get('AFTER_SUB')} in both)")
    else:
        RED.append(
            "flag OFF is NOT a no-op: absent(follow=%s sub=%s) vs off(follow=%s sub=%s)"
            % (absent.get("AFTER_FOLLOW"), absent.get("AFTER_SUB"),
               off.get("AFTER_FOLLOW"), off.get("AFTER_SUB"))
        )
    if off.get("HOOKED") != "yes":
        RED.append("flag OFF did not even register the filter — the OFF path is untested, "
                   "so its no-op is unproven rather than proven")
    if absent.get("HOOKED") == "no":
        GREEN.append("the `absent` baseline genuinely ran with the feature detached (HOOKED=no)")
    else:
        DEAD.append(f"`absent` did NOT detach the feature (HOOKED={absent.get('HOOKED')}) — "
                    "it was not a pre-feature baseline, so the no-op comparison is unearned")
    if dflt.get("SOURCE"):
        GREEN.append(f"exercised the {dflt.get('SOURCE')} copy of the feature")

    # 2. RULING 6's DEFAULTS — the assertion this gate exists for.
    if dflt.get("AFTER_FOLLOW") == "1":
        GREEN.append("default post: the 🔔 topic_follow row IS created (ruling 6: bell ticked)")
    else:
        RED.append("default post did NOT create the 🔔 topic_follow row — the bell default "
                   "is the ONLY delivery path for 'your question was answered'")
    if dflt.get("AFTER_SUB") == "0":
        GREEN.append("default post: the ✉ type='topic' roundup row is NOT created (ruling 6: email unticked)")
    else:
        RED.append("default post CREATED the ✉ roundup row. Ruling 6 has email UNTICKED — "
                   "this signs members up for email by posting, which is the consent "
                   "failure the whole project exists to end.")

    # 3. TICKING ✉ must actually work, or "present but unticked" is a dead control.
    if mail.get("AFTER_SUB") == "1":
        GREEN.append("✉ ticked: the type='topic' row the follow roundup reads IS created")
    else:
        RED.append("✉ ticked did not create the type='topic' row — the control does nothing")
    if mail.get("AFTER_FOLLOW") == "1":
        GREEN.append("✉ ticked leaves the 🔔 default intact (both rows written)")
    else:
        RED.append("ticking ✉ suppressed the 🔔 row — the two controls are not independent")

for line in GREEN:
    print("  PASS  %s" % line)
for line in DEAD:
    print("  DEAD  %s" % line)
for line in RED:
    print("  FAIL  %s" % line)

if DEAD and not RED:
    print("post-follow-controls: NO VERDICT (%d probe(s) could not run or could not be trusted)" % len(DEAD))
    sys.exit(2)
if RED:
    print("post-follow-controls: RED (%d finding(s))" % len(RED))
    sys.exit(1)
print("post-follow-controls: GREEN (%d assertion(s))" % len(GREEN))
sys.exit(0)
