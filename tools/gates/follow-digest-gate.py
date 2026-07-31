#!/usr/bin/env python3
"""
follow-digest-gate — the OFF state sends NOTHING, and that is asserted, not claimed.

WHY THIS GATE EXISTS, and why it is written BEFORE the sender it guards.

Email is unrecallable. Every other gate on this platform guards something that
renders wrong and can be fixed with a pull; this one guards something that lands in
a member's inbox and cannot be taken back. CRAFT-STANDARD's law is that a defect
class found twice becomes a gate before it is fixed the second time — this gate is
written before the FIRST time, because the second time is not survivable.

The standing failure class, in Ian's own tally: six misses, all in the same blind
spot. Gates assert what should be PRESENT; they are blind to what should be ABSENT.
A flag defaulted OFF whose OFF-ness nobody asserts is exactly that blind spot, and
"the flag is off so it can't send" is a claim, not a measurement.

    ⚠️ AND THE OBVIOUS VERSION OF THIS GATE IS WORTHLESS.

"Flag OFF => no cron event scheduled" is TRUE right now, on a box where the sender
does not exist, and it would be true on a box with no WordPress at all. An absence
assertion passes vacuously whenever the machinery that would produce the presence is
itself missing — which is precisely the state this gate is born into. thread-follow
shipped that mistake once already and caught it (SPEC §18.3, "the vacuous pass I
nearly shipped"); shipping it here would produce a green suite that proves nothing
about an unrecallable channel.

    SO EVERY ABSENCE ASSERTION HERE IS PAIRED WITH A LIVENESS ASSERTION.

Before asserting "X is absent", the gate proves the mechanism that WOULD produce X
exists and is reachable. "No cron event is scheduled" only counts once we have shown
the cron system answers, the hook name is the one the sender really uses, and the
flag constant is genuinely defined and genuinely false. Absence without liveness is
not evidence, and this file refuses to report it as such.

RED-FIRST, DELIBERATELY. Run today, against a build with no sender, this gate is
RED — every liveness probe fails because nothing exists yet. That is the correct
starting state and the whole point of writing it now: it must fail against today's
build, then go green as the sender lands, and no intermediate state can be mistaken
for safety.

WHAT IT WILL NOT DO: assert that mail was sent. dev2's containment mu-plugin
(lg-dev-mail-containment.php) swallows wp_mail into mailpit and RETURNS TRUE, so a
"successful send" on this box is a convincing false positive and is not evidence.
Every assertion below is about the RECIPIENT SET and the STORE — prove who would be
mailed, never that mailing happened. Real delivery is Ian's, deliberate and one-shot.

⚠️ BY DEFAULT THIS GATE MEASURES THE SERVE, WHICH RUNS `main` — NOT YOUR BRANCH.
mu-plugins are symlinked individually into the docroot, so a lane's unmerged plugin
file is invisible here and the gate reports RED however finished the branch is. That
is the documented "a lane verifying on dev2 is usually testing main" trap, and left
unaddressed it means this gate could only ever go green AFTER merge — exactly the
ordering that keeps putting production-shaped defects in front of Ian.

    --plugin platform/mu-plugins/lg-follow-digest.php

loads a specific file into the probe first, so a branch can be proven BEFORE it
merges. The file must exist and must actually define the flag, or the run is DEAD
rather than red — loading nothing and reporting "not defined" would be the same
vacuous answer this gate exists to refuse.

    --expect-on   assert the flag resolves ON (use with LG_FOLLOW_DIGEST=1 in the
                  environment) so the ON path is exercised deliberately rather than
                  only ever being observed in its OFF state.

Run:   python3 tools/gates/follow-digest-gate.py [--wp-path /var/www/dev]
       python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php
Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
       The 0/1/2 split is run-all.sh's convention. Environmental failure must never
       be reported as red — craft gate 2 sat "red" for weeks while it was in fact
       dead, and a gate nobody trusts is worse than no gate.
"""

import argparse
import json
import os
import shutil
import subprocess
import sys

WP_PATH = "/var/www/dev"
WP_USER = "looth-dev"

# The names the sender is contracted to use. If a build renames one of these, this
# gate must be updated in the SAME commit — a gate pointed at a stale name reports a
# confident green about a hook that no longer exists. SPEC §19.3 lost a gate's target
# exactly this way, and §20.5 counted four more gate bugs that each gave a confident
# wrong answer.
FLAG = "LG_FOLLOW_DIGEST_ENABLED"
CRON_HOOK = "lg_fd_send"
FILTER_HOOK = "bb_send_forums_subscribed_reply_email_notifications"
CADENCE_META = "lg_disc_email_cadence"
WATERMARK_META = "lg_disc_digest_watermark"
RESOLVER = "lg_fd_due_recipients"      # (string $cadence) => array of wp user ids
COLLECTOR = "lg_fd_items_for"          # (int $uid) => array of reply rows since watermark

RED, GREEN, DEAD = [], [], []
PLUGIN = ""
EXPECT_ON = False


def red(name, detail):
    RED.append((name, detail))


def ok(name, detail=""):
    GREEN.append((name, detail))


def dead(name, detail):
    DEAD.append((name, detail))


def wp_eval(php):
    """Run PHP in the WP context.

    ⚠️ `wp eval-file` runs the file in FUNCTION scope, so a top-level $var is NOT a
    global and a helper reading `global $x` silently queries nothing and returns 0
    rows rather than raising — green-looking code with red assertions. Everything
    here is therefore self-contained and communicates only through the printed JSON.
    """
    cmd = ["wp", "eval", php, "--path=" + WP_PATH, "--skip-themes"]
    if shutil.which("sudo"):
        # ⚠️ sudo STRIPS THE ENVIRONMENT, so `LG_FOLLOW_DIGEST=1 python3 thisgate.py`
        # would run the probe with the flag still OFF and report the OFF behaviour as
        # though it were the ON behaviour — a confident wrong answer about who gets
        # mail. Forwarded explicitly through `env`. This was caught by --expect-on,
        # which is exactly why that guard exists: the run went DEAD instead of green.
        cmd = ["sudo", "-u", WP_USER, "env",
               "LG_FOLLOW_DIGEST=" + (os.environ.get("LG_FOLLOW_DIGEST") or "")] + cmd
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=120)
    except Exception as e:                                    # noqa: BLE001
        return None, "could not execute wp: %s" % e
    if p.returncode != 0:
        return None, (p.stderr or p.stdout).strip()[:400]
    out = (p.stdout or "").strip()
    start = out.find("{")
    if start < 0:
        return None, "no JSON in wp output: %r" % out[:300]
    try:
        return json.loads(out[start:]), None
    except Exception as e:                                    # noqa: BLE001
        return None, "unparseable wp output (%s): %r" % (e, out[:300])


PROBE = r"""
$out = array();

// Optional: load a branch's plugin file so an UNMERGED branch can be proven. The
// serve runs main, so without this the gate can only ever go green after merge.
$out['loaded'] = null;
$lane = '%PLUGIN%';
if ($lane !== '') {
    if (!is_readable($lane)) { echo json_encode(array('load_error' => "unreadable: $lane")); return; }
    require_once $lane;
    $out['loaded'] = $lane;
}

// ── LIVENESS: is this even a WordPress that could send anything? ───────────────
// Without this, every absence assertion below is vacuous.
$out['wp_alive']       = function_exists('wp_next_scheduled') && function_exists('add_filter');
$out['bb_reply_mailer'] = class_exists('BP_Forums_Notification');
$out['subs_table']     = false;
global $wpdb;
if (isset($wpdb)) {
    $t = $wpdb->prefix . 'bb_notifications_subscriptions';
    $out['subs_table'] = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $t)) === $t);
    $out['subs_live']  = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$t} WHERE type='topic' AND status=1");
}

// ── THE FLAG ──────────────────────────────────────────────────────────────────
$out['flag_defined'] = defined('%FLAG%');
$out['flag_value']   = defined('%FLAG%') ? (bool) constant('%FLAG%') : null;

// ── THE SENDER'S OWN MACHINERY ────────────────────────────────────────────────
$out['resolver_exists']  = function_exists('%RESOLVER%');
$out['collector_exists'] = function_exists('%COLLECTOR%');

// ── THE SUPPRESSION FILTER ────────────────────────────────────────────────────
// Registered at all? And — the assertion that matters — is it the IDENTITY function
// while the flag is off? "It returns its input" is the entire no-op claim, and it is
// cheap to test and impossible to argue with.
$out['filter_registered'] = (bool) has_filter('%FILTER%');
$probe = array('recipient_user_id' => 1, 'type' => 'notification_forums_following_reply');
$out['filter_true_in']  = (bool) apply_filters('%FILTER%', true,  $probe);
$out['filter_false_in'] = (bool) apply_filters('%FILTER%', false, $probe);

// ── THE SCHEDULED SENDER ──────────────────────────────────────────────────────
$out['cron_scheduled'] = (bool) wp_next_scheduled('%CRON%');
$out['cron_any']       = false;
if (function_exists('_get_cron_array')) {
    $c = _get_cron_array();
    if (is_array($c)) {
        // Liveness: SOMETHING is scheduled on this box, so "our hook is absent" is a
        // real observation rather than "cron is empty/broken".
        $out['cron_any'] = count($c) > 0;
        foreach ($c as $ts => $hooks) {
            foreach ((array) $hooks as $h => $_) {
                if (strpos((string) $h, 'lg_fd') === 0) { $out['cron_stray'][] = $h; }
            }
        }
    }
}

// ── THE STORE ─────────────────────────────────────────────────────────────────
// Anyone actually carrying a cadence? While the flag is OFF this must be nobody, or
// a member is holding a preference the system cannot honour.
if (isset($wpdb)) {
    $out['cadence_rows']   = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", '%CADENCE%'));
    $out['watermark_rows'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", '%WATERMARK%'));
    $out['cadence_bad'] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s
           AND meta_value NOT IN ('instant','daily','weekly')", '%CADENCE%'));
}

// ── THE RECIPIENT SET, if the sender exists ───────────────────────────────────
// The ONE thing dev2 genuinely proves about an unrecallable channel is the negative.
if (function_exists('%RESOLVER%')) {
    foreach (array('daily', 'weekly', 'instant') as $c) {
        $r = call_user_func('%RESOLVER%', $c);
        $out['recipients'][$c] = is_array($r) ? count($r) : -1;
    }
}
echo json_encode($out);
"""


def build_probe():
    return (PROBE
            .replace("%FLAG%", FLAG)
            .replace("%CRON%", CRON_HOOK)
            .replace("%FILTER%", FILTER_HOOK)
            .replace("%CADENCE%", CADENCE_META)
            .replace("%WATERMARK%", WATERMARK_META)
            .replace("%RESOLVER%", RESOLVER)
            .replace("%COLLECTOR%", COLLECTOR)
            .replace("%PLUGIN%", PLUGIN or ""))


def main():
    global WP_PATH, PLUGIN, EXPECT_ON
    ap = argparse.ArgumentParser()
    ap.add_argument("--wp-path", default=WP_PATH)
    ap.add_argument("--plugin", default="",
                    help="load this plugin file into the probe (prove a branch pre-merge)")
    ap.add_argument("--expect-on", action="store_true",
                    help="assert the flag resolves ON, so the ON path is exercised deliberately")
    args = ap.parse_args()
    WP_PATH = args.wp_path
    EXPECT_ON = args.expect_on
    if args.plugin:
        PLUGIN = os.path.abspath(args.plugin)
        if not os.path.isfile(PLUGIN):
            dead("--plugin", "no such file: %s" % PLUGIN)
            return verdict()
        print("    (probing WITH %s — branch mode, not the serve)\n" % PLUGIN)

    print("=== follow-digest gate — the OFF state must send NOTHING ===\n")

    d, err = wp_eval(build_probe())
    if d is None:
        dead("wp probe", err)
        return verdict()
    if d.get("load_error"):
        dead("--plugin load", d["load_error"])
        return verdict()

    # ── LIVENESS FIRST. Everything below is meaningless without it. ─────────────
    if not d.get("wp_alive"):
        dead("liveness: WordPress", "wp_next_scheduled/add_filter absent — not a WP context")
        return verdict()
    ok("liveness: WordPress answers")

    if not d.get("bb_reply_mailer"):
        dead("liveness: BB reply mailer",
             "BP_Forums_Notification absent — the class carrying the suppression filter "
             "is not loaded, so 'the filter is a no-op' cannot be observed here")
    else:
        ok("liveness: BP_Forums_Notification present (the mailer we suppress)")

    if not d.get("subs_table"):
        dead("liveness: subscription store",
             "wp_bb_notifications_subscriptions missing — the ✉ population cannot be read, "
             "so a zero recipient count would be vacuous")
    else:
        ok("liveness: ✉ subscription store readable",
           "%s live topic rows" % d.get("subs_live", "?"))

    if not d.get("cron_any"):
        dead("liveness: cron",
             "_get_cron_array is empty — 'our hook is not scheduled' would be true of a "
             "box with no cron at all, which proves nothing")
    else:
        ok("liveness: cron array is populated (absence of our hook is observable)")

    # ── THE FLAG ───────────────────────────────────────────────────────────────
    flag_defined = bool(d.get("flag_defined"))
    flag_on = bool(d.get("flag_value"))
    if not flag_defined and PLUGIN:
        dead(FLAG + " not defined even though the plugin was loaded",
             "The file at --plugin does not define the flag, so every assertion below "
             "would be about nothing. Loading a file and then reporting 'absent' is the "
             "vacuous answer this gate refuses to give.")
        return verdict()
    if flag_defined and EXPECT_ON and not flag_on:
        dead("--expect-on but the flag resolved OFF",
             "Set LG_FOLLOW_DIGEST=1 in the environment. Exercising the ON path against "
             "an OFF flag would report the OFF behaviour as if it were the ON behaviour.")
        return verdict()
    if not flag_defined:
        red(FLAG + " is not defined",
            "The sender does not exist yet. THIS IS THE EXPECTED RED-FIRST STATE — the "
            "gate is written before the feature on purpose. It goes green when the "
            "flag, the filter and the resolver land together.")
    else:
        ok("flag %s defined" % FLAG, "value=%s" % ("ON" if flag_on else "OFF"))

    # ── THE SUPPRESSION FILTER: identity function while OFF ────────────────────
    if not d.get("filter_registered"):
        red("suppression filter not registered on " + FILTER_HOOK,
            "Nothing is suppressing instant mail, so a member on Daily would receive "
            "instant mail AND a digest. Expected red until the sender lands.")
    elif not flag_on:
        # The whole no-op claim, tested rather than asserted.
        if d.get("filter_true_in") is True and d.get("filter_false_in") is False:
            ok("flag OFF ⇒ suppression filter is the IDENTITY function",
               "true→true, false→false: proven byte-identical no-op, not claimed")
        else:
            red("flag OFF but the filter CHANGES its input",
                "true→%s, false→%s. The OFF state is not a no-op — it is altering who "
                "receives mail while the feature is supposed to be dark."
                % (d.get("filter_true_in"), d.get("filter_false_in")))
    else:
        ok("flag ON — suppression filter active (per-member behaviour asserted below)")

    # ── THE SCHEDULED SENDER ───────────────────────────────────────────────────
    stray = d.get("cron_stray") or []
    if not flag_on and d.get("cron_scheduled"):
        red("flag OFF but %s IS SCHEDULED" % CRON_HOOK,
            "A sender armed ahead of its code. This is the mirror-dispatch trap: it "
            "reddens `systemctl --failed` forever and kills the alert channel — and "
            "here it would also send real mail from a feature nobody has switched on.")
    elif not flag_on:
        ok("flag OFF ⇒ no %s event scheduled" % CRON_HOOK,
           "non-vacuous: cron array is populated (liveness above)")
    else:
        if d.get("cron_scheduled"):
            ok("flag ON ⇒ %s is scheduled" % CRON_HOOK)
        else:
            red("flag ON but %s is NOT scheduled" % CRON_HOOK,
                "The control is visible and the member picked Daily, but nothing will "
                "ever flush their queue. That is the silent-nothing lie §15.4 forbids.")
    if stray:
        red("stray lg_fd_* cron hooks", "unexpected scheduled hooks: %s" % ", ".join(stray))

    # ── THE STORE ──────────────────────────────────────────────────────────────
    cad = d.get("cadence_rows")
    if cad is not None:
        if not flag_on and cad:
            red("flag OFF but %d member(s) carry %s" % (cad, CADENCE_META),
                "Members are holding a preference the system cannot honour — the "
                "stored-cadence-with-no-sender state this whole lane exists to prevent.")
        elif not flag_on:
            ok("flag OFF ⇒ no member carries a cadence", "usermeta rows = 0")
        else:
            ok("cadence rows present", "%d member(s)" % cad)
    if d.get("cadence_bad"):
        red("%d cadence row(s) outside the allow-list" % d["cadence_bad"],
            "Only instant|daily|weekly are deliverable. Anything else is a value the "
            "sender will not recognise and will silently skip — a member set a "
            "preference and gets nothing.")

    # Watermark must never trail cadence: a member with a cadence and no watermark is
    # the FLOOD. §4.3 of the design note — the single most dangerous line in it.
    wm = d.get("watermark_rows")
    if cad is not None and wm is not None and cad > wm:
        red("%d member(s) have a cadence but NO watermark" % (cad - wm),
            "THE FLOOD. With no watermark the first digest reads from the beginning of "
            "time — the entire reply history of every thread they follow, unrecallable. "
            "One live account holds 335 subscriptions. Watermark must be set to NOW at "
            "the moment cadence is first written; a digest is never a backfill.")
    elif cad:
        ok("every member with a cadence has a watermark", "%s cadence / %s watermark" % (cad, wm))

    # ── THE RECIPIENT SET ──────────────────────────────────────────────────────
    if not d.get("resolver_exists"):
        red("%s() does not exist" % RESOLVER,
            "The recipient set cannot be proven, and proving the recipient set before "
            "the content is the rule for an unrecallable channel. Expected red until "
            "the sender lands.")
    else:
        rec = d.get("recipients") or {}
        if not flag_on:
            tot = sum(v for v in rec.values() if isinstance(v, int) and v > 0)
            if tot:
                red("flag OFF but the resolver returns %d recipient(s)" % tot,
                    "detail: %s. The OFF state must resolve NOBODY — this is the one "
                    "negative dev2 can genuinely prove, and it is failing." % rec)
            else:
                ok("flag OFF ⇒ resolver returns ZERO recipients for every cadence",
                   "the negative dev2 CAN prove: %s" % rec)
        else:
            ok("resolver answers", "%s" % rec)
        if rec.get("instant", 0) > 0:
            red("cadence=instant resolved %d digest recipient(s)" % rec["instant"],
                "Instant members are served by the native per-reply path and must NEVER "
                "appear in a digest — they would get the same reply twice.")

    if not d.get("collector_exists"):
        red("%s() does not exist" % COLLECTOR,
            "Cannot assert the two content invariants that matter: a member's own reply "
            "never appears in their own digest, and a fresh cadence never backfills.")

    return verdict()


def verdict():
    for n, det in GREEN:
        print("  ok   %s%s" % (n, ("  — " + det) if det else ""))
    for n, det in DEAD:
        print("  ??   %s\n         %s" % (n, det))
    for n, det in RED:
        print("  RED  %s\n         %s" % (n, det))

    print()
    if RED:
        print("############ follow-digest gate RED — %d finding(s) ############" % len(RED))
        print("If the sender has not been built yet, this red is EXPECTED and correct:")
        print("the gate is written before the feature so it cannot be mistaken for safety.")
        return 1
    if DEAD:
        print("############ follow-digest gate INCOMPLETE — %d probe(s) dead ############" % len(DEAD))
        print("Nothing red, but liveness could not be established, so absence assertions")
        print("would be VACUOUS. This is NOT a pass. Fix the environment and re-run.")
        return 2
    print("############ follow-digest gate GREEN ############")
    return 0


if __name__ == "__main__":
    sys.exit(main())
