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
MU_DIR = "/var/www/dev/wp-content/mu-plugins"

# Scratch paths for --plugin (branch) mode. Rebuilt every run; never read back.
HARNESS_DIR = "/tmp/lg-fd-gate-mu"
HARNESS_BOOT = "/tmp/lg-fd-gate-boot.php"

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
PARSER = "lg_fd_parse_allowlist"       # (string $raw) => decision, PURE
ALLOWED = "lg_fd_allowed"              # (int $uid, string $email) => bool
DELIVER = "lg_fd_deliver"              # THE ONLY wp_mail() call site
ALLOW_ALL_TOKEN = "all-members"        # the one token that reaches the membership
CONFIG_REL = "platform/config/follow-digest.php"

RED, GREEN, DEAD = [], [], []
PLUGIN = ""
EXPECT_ON = False

# ── THE ALLOWLIST GRAMMAR, STATED HERE RATHER THAN IN THE CODE UNDER TEST ─────
# Expectations live in the gate on purpose: a table generated by the implementation
# would agree with the implementation no matter what it did. Every row is a claim
# about who could be mailed, and the shape of the table is the whole argument —
# ONE input widens the set, and it is a word nobody types by accident.
#
# The rule applied to every leniency: leniency is permitted only where it is
# INCAPABLE of widening the recipient set. A tolerated trailing comma cannot add a
# recipient; a tolerated garbage token could mask intent, so it voids the whole list.
GRAMMAR_CASES = [
    # (raw, expected mode, expected uids)
    ("",                       "none", []),      # the fail-closed default
    ("   ",                    "none", []),
    ("0",                      "none", []),      # not a positive id
    ("00",                     "none", []),
    ("-1",                     "none", []),
    ("+1",                     "none", []),
    ("1.0",                    "none", []),
    ("1e2",                    "none", []),      # is_numeric() would have accepted this
    ("0x1",                    "none", []),      # ...and this
    ("1abc",                   "none", []),      # intval() would have made this 1
    ("abc",                    "none", []),
    ("*",                      "none", []),      # a wildcard SYMBOL is not a wildcard
    ("all",                    "none", []),
    ("ALL",                    "none", []),
    ("all members",            "none", []),      # near-misses of the real token
    ("all-member",             "none", []),
    ("ian.davlin@gmail.com",   "none", []),      # a bare address is not a user id
    ("1:notanemail",           "none", []),      # a pin that is not an address VOIDS
    ("1:",                     "none", []),
    ("1,abc",                  "none", []),      # ONE bad token voids the WHOLE list
    ("1,0",                    "none", []),
    ("1,-2",                   "none", []),
    ("1",                      "list", [1]),
    (" 1 ",                    "list", [1]),
    ("1,2",                    "list", [1, 2]),
    ("1,",                     "list", [1]),     # trailing comma cannot ADD anyone
    ("1,,2",                   "list", [1, 2]),
    ("1:ian.davlin@gmail.com", "list", [1]),     # the pinned form — prefer it
    ("1:a@b.com,2",            "list", [1, 2]),
    ("all-members",            "all",  []),      # the ONLY widening input
    ("ALL-MEMBERS",            "all",  []),
    (" All-Members ",          "all",  []),
]

# ── WHO MAY BE MAILED, given an allowlist. The rows that matter are the BLOCKS. ──
# (allowlist, uid, address, expected)
DECISION_CASES = [
    # Pinned to Ian: the intended live test posture.
    ("1:ian.davlin@gmail.com", 1, "ian.davlin@gmail.com", True),
    ("1:ian.davlin@gmail.com", 1, "IAN.Davlin@Gmail.COM", True),   # addresses are case-insensitive
    # ⚠️ THE ROW THE PIN EXISTS FOR: user 1's address was changed. A uid-only
    # allowlist still says yes and mails whoever holds the account now.
    ("1:ian.davlin@gmail.com", 1, "someone@else.com",     False),
    ("1:ian.davlin@gmail.com", 2, "member@example.com",   False),  # a real member, blocked
    # Unpinned: the uid is trusted, which is exactly the weaker guarantee.
    ("1",                      1, "someone@else.com",     True),
    ("1",                      2, "member@example.com",   False),
    ("1,7",                    7, "member@example.com",   True),
    ("1,7",                    8, "member@example.com",   False),
    # ⚠️ FAIL-CLOSED: a malformed allowlist admits NOBODY — never the real list.
    # These go through the FULL path (sourcing + grammar + decision), so they prove
    # fail-closed end to end, not just in the parser.
    ("garbage",                1, "ian.davlin@gmail.com", False),
    ("*",                      1, "ian.davlin@gmail.com", False),
    ("1;2",                    1, "ian.davlin@gmail.com", False),
    ("1 2",                    1, "ian.davlin@gmail.com", False),
    # ⚠️ NO ("", …) ROW HERE, DELIBERATELY. An empty ENVIRONMENT value is not an empty
    # allowlist — it means "no override", and sourcing correctly falls through to the
    # tracked config. A row asserting False would be asserting that the tracked config
    # is ignored, which is the opposite of the design. Empty-means-NOBODY is a property
    # of the GRAMMAR and is proven there (GRAMMAR_CASES row 1); the LAYERING is proven
    # separately by the raw-sourcing assertion below.
    # Nonsense subjects are rejected even under all-members — the mode must not
    # answer before the arguments are validated. This pair caught a real defect.
    ("all-members",            0,  "",                    False),
    ("all-members",            -1, "ian.davlin@gmail.com", False),
    ("all-members",            1,  "not-an-email",        False),
    ("all-members",            2,  "member@example.com",  True),
]


def red(name, detail):
    RED.append((name, detail))


def ok(name, detail=""):
    GREEN.append((name, detail))


def dead(name, detail):
    DEAD.append((name, detail))


def build_branch_harness(plugin):
    """Make a WordPress that loads THIS BRANCH's mu-plugin, without touching the serve.

    ⚠️ THE OBVIOUS IMPLEMENTATION STOPPED WORKING THE DAY THE FEATURE MERGED, and
    silently enough to be worth naming. This used to `require_once` the branch file
    into the probe. That worked only while the serve had no lg-follow-digest.php of
    its own; once the mu-plugin was symlinked into the docroot (2026-07-31), the
    serve loads main's copy first and requiring a second file with the same functions
    is a FATAL redeclare — the gate dies rather than reporting on the branch.

    So instead of adding a file to a WordPress that already has one, we point
    WordPress at a different mu-plugin directory: a mirror of the real one with this
    branch's file swapped in. WPMU_PLUGIN_DIR is only defined by wp_initial_constants()
    `if ( ! defined(...) )`, so a `wp --require=` bootstrap that defines it first wins.

    The result is a REAL WordPress — real DB, real BuddyBoss, real is_email(), real
    containment — running the branch's sender. Nothing in /var/www/dev is modified,
    which matters on a box where other lanes are working.

    @return (boot_file, None) or (None, reason-it-is-DEAD)
    """
    # Enumerating the real mu-plugin dir needs root: it is 0750 root:loothdevs.
    try:
        listing = subprocess.run(["sudo", "find", MU_DIR, "-maxdepth", "1", "-mindepth", "1"],
                                 capture_output=True, text=True, timeout=60)
    except Exception as e:                                    # noqa: BLE001
        return None, "could not enumerate %s: %s" % (MU_DIR, e)
    if listing.returncode != 0:
        return None, "could not enumerate %s: %s" % (MU_DIR, (listing.stderr or "").strip()[:200])

    entries = [x for x in (listing.stdout or "").splitlines() if x.strip()]
    if not entries:
        return None, "%s is empty — a mirror of it would be a WordPress with no "\
                     "mu-plugins, and every liveness probe below would fail for the "\
                     "wrong reason" % MU_DIR

    shutil.rmtree(HARNESS_DIR, ignore_errors=True)
    os.makedirs(HARNESS_DIR, 0o755, exist_ok=True)
    swapped = False
    for src in entries:
        base = os.path.basename(src)
        # Resolve through the docroot symlink to the real file in the repo.
        real = subprocess.run(["sudo", "readlink", "-f", src],
                              capture_output=True, text=True, timeout=30).stdout.strip()
        if not real:
            continue
        if base == os.path.basename(plugin):
            real, swapped = plugin, True
        try:
            os.symlink(real, os.path.join(HARNESS_DIR, base))
        except OSError:
            pass
    if not swapped:
        # The branch file would be loaded IN ADDITION to the serve's copy rather than
        # INSTEAD of it. Say so instead of producing a confident answer about a
        # WordPress running two senders.
        return None, ("%s is not among the mu-plugins in %s, so the mirror would load it "
                      "alongside the serve's copy rather than in place of it"
                      % (os.path.basename(plugin), MU_DIR))
    os.chmod(HARNESS_DIR, 0o755)

    with open(HARNESS_BOOT, "w") as fh:
        fh.write('<?php define("WPMU_PLUGIN_DIR", %r);\n' % HARNESS_DIR)
    os.chmod(HARNESS_BOOT, 0o644)
    return HARNESS_BOOT, None


BOOT = None            # set by build_branch_harness() in --plugin mode
ALLOWLIST_ENV = ""     # forwarded into the probe; see wp_eval()


def wp_eval(php):
    """Run PHP in the WP context.

    ⚠️ `wp eval-file` runs the file in FUNCTION scope, so a top-level $var is NOT a
    global and a helper reading `global $x` silently queries nothing and returns 0
    rows rather than raising — green-looking code with red assertions. Everything
    here is therefore self-contained and communicates only through the printed JSON.
    """
    cmd = ["wp"]
    if BOOT:
        cmd.append("--require=" + BOOT)
    cmd += ["eval", php, "--path=" + WP_PATH, "--skip-themes"]
    if shutil.which("sudo"):
        # ⚠️ sudo STRIPS THE ENVIRONMENT, so `LG_FOLLOW_DIGEST=1 python3 thisgate.py`
        # would run the probe with the flag still OFF and report the OFF behaviour as
        # though it were the ON behaviour — a confident wrong answer about who gets
        # mail. Forwarded explicitly through `env`. This was caught by --expect-on,
        # which is exactly why that guard exists: the run went DEAD instead of green.
        #
        # LG_FOLLOW_DIGEST_ALLOWLIST is forwarded for the same reason and it is the
        # more dangerous of the two: dropped, the probe would read the TRACKED config
        # instead of the value under test, and the decision table would report on a
        # different allowlist than the one it names.
        cmd = ["sudo", "-u", WP_USER, "env",
               "LG_FOLLOW_DIGEST=" + (os.environ.get("LG_FOLLOW_DIGEST") or ""),
               "LG_FOLLOW_DIGEST_ALLOWLIST=" + ALLOWLIST_ENV] + cmd
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

// ── WHICH FILE IS ACTUALLY UNDER TEST ─────────────────────────────────────────
// In branch mode the harness has already pointed WPMU_PLUGIN_DIR at a mirror with the
// branch's file swapped in, so the sender is loaded like any other mu-plugin — no
// require, no redeclare. We do not TRUST that: we ask the loaded function where it
// came from. A gate that reports on main while claiming to report on a branch is the
// exact failure this mode exists to avoid, and Reflection settles it.
$out['loaded'] = null;
$lane = '%PLUGIN%';
if (function_exists('lg_fd_cadence')) {
    $r = new ReflectionFunction('lg_fd_cadence');
    $out['loaded'] = $r->getFileName();
}
if ($lane !== '' && $out['loaded'] !== null && realpath($lane) !== realpath($out['loaded'])) {
    echo json_encode(array('load_error' =>
        "asked for $lane but WordPress loaded {$out['loaded']}")); return;
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

// ── THE THREE SURFACES MUST AGREE ────────────────────────────────────────────
// Ian added the control to the manage-account page as well as the follow modal, so
// ONE setting now has two UI surfaces and one store. If they can disagree, that is
// the "UI lies" class. Assert the single gate helper AND that the account-page
// transport does not exist while the control is hidden — a control that is merely
// not drawn, but whose endpoint answers, is one stray render from being live.
$out['ui_helper']  = function_exists('lg_fd_cadence_ui_enabled');
$out['ui_enabled'] = function_exists('lg_fd_cadence_ui_enabled') ? (bool) lg_fd_cadence_ui_enabled() : null;
$out['ajax_state'] = (bool) has_action('wp_ajax_lg_fd_cadence_state');
$out['ajax_set']   = (bool) has_action('wp_ajax_lg_fd_cadence_set');

// ── THE SCHEDULED SENDER ──────────────────────────────────────────────────────
$out['cron_scheduled'] = (bool) wp_next_scheduled('%CRON%');
// Is the FLUSH actually wired to the hook? In --plugin mode `init` has long since
// fired before the file was required, so the scheduler cannot have run and
// "is it scheduled" says nothing. This says the real thing: when init DOES run with
// the flag on, there is a callback for it to arm. The gate must never arm it itself
// — that would write a real event into wp_options.cron on the serve, which is the
// mirror-dispatch trap and the very thing this file exists to forbid.
$out['tick_attached']    = (bool) has_action('%CRON%');
$out['scheduler_exists'] = function_exists('lg_fd_sync_schedule');
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

// ── WHERE THE LINKS POINT — not merely that there ARE links ──────────────────
// This is the assertion whose absence let a wrong destination reach Ian's inbox. The
// gates proved the email SENDS; nothing proved where it POINTED, and get_permalink()
// was quietly returning the mirrored legacy forum page he had already rejected. A link
// in a sent email cannot be edited afterwards, so the destination is gated, not the
// presence of an href.
$out['link_check'] = null;
if (function_exists('lg_fd_render') && function_exists('lg_fd_topic_urls')) {
    global $wpdb;
    $bb  = $wpdb->prefix . 'bb_notifications_subscriptions';
    $tids = array_map('intval', (array) $wpdb->get_col(
        "SELECT DISTINCT item_id FROM {$bb} WHERE type='topic' AND status=1 LIMIT 6"));
    if ($tids) {
        $items = array();
        foreach ($tids as $i => $t) {
            $items[] = array('ID' => 900000 + $i, 'topic_id' => $t,
                             'post_author' => 1, 'post_date_gmt' => '2026-01-01 00:00:00');
        }
        $u = get_userdata(1);
        if ($u) {
            $html = lg_fd_render($u, array('items' => $items, 'capped' => false), 'daily');
            preg_match_all('~href="([^"]+)"~', $html, $m);
            $hub = $bad = $other = 0; $badlist = array();
            foreach ($m[1] as $href) {
                $h = html_entity_decode($href, ENT_QUOTES);
                if (strpos($h, '/hub/?topic=') !== false)            { $hub++; }
                elseif (strpos($h, '/manage-subscription') !== false) { $other++; }
                // The rejected destinations, named explicitly rather than inferred.
                elseif (preg_match('~/(forums|groups|topic)/~', $h))  { $bad++; $badlist[] = $h; }
                else                                                  { $other++; }
            }
            $out['link_check'] = array('hub' => $hub, 'bad' => $bad,
                                       'other' => $other, 'badlist' => array_slice($badlist, 0, 3));
        }
    }
}

// ── THE RECIPIENT SET, if the sender exists ───────────────────────────────────
// The ONE thing dev2 genuinely proves about an unrecallable channel is the negative.
if (function_exists('%RESOLVER%')) {
    foreach (array('daily', 'weekly', 'instant') as $c) {
        $r = call_user_func('%RESOLVER%', $c);
        $out['recipients'][$c] = is_array($r) ? count($r) : -1;
        // ⚠️ And WHO, not just how many. A count cannot tell you the allowlist held;
        // it can only tell you the number was small. These ids are checked against
        // the allowlist by the gate, one by one.
        $out['recipient_ids'][$c] = is_array($r) ? array_map('intval', $r) : array();
    }
}

// ── THE ALLOWLIST ─────────────────────────────────────────────────────────────
$out['parser_exists']  = function_exists('%PARSER%');
$out['allowed_exists'] = function_exists('%ALLOWED%');
$out['deliver_exists'] = function_exists('%DELIVER%');

// The GRAMMAR, exhaustively. Pure function, so this is a complete statement of what
// the allowlist accepts — no environment, no config, no ordering effects.
if (function_exists('%PARSER%')) {
    $out['grammar'] = array();
    foreach (json_decode('%GRAMMAR%', true) as $raw) {
        $d = call_user_func('%PARSER%', $raw);
        $out['grammar'][] = array('raw'  => $raw, 'mode' => (string) ($d['mode'] ?? '?'),
                                  'uids' => array_map('intval', (array) ($d['uids'] ?? array())));
    }
}

// WHO MAY BE MAILED. lg_fd_allowed() reads the allowlist from the environment/config,
// so each row is exercised by setting $_SERVER — the same source lg_fd_allowlist_raw()
// consults, not a back door around it.
if (function_exists('%ALLOWED%')) {
    $out['decisions'] = array();
    $keep = $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] ?? null;
    foreach (json_decode('%DECISIONS%', true) as $row) {
        // putenv('') would leave getenv() returning '' and fall through to the tracked
        // config; setting BOTH sources to the row's value is what makes the row honest.
        putenv('LG_FOLLOW_DIGEST_ALLOWLIST=' . $row[0]);
        $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] = $row[0];
        $out['decisions'][] = (bool) call_user_func('%ALLOWED%', (int) $row[1], (string) $row[2]);
    }
    if ($keep === null) { unset($_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST']); }
    else { $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] = $keep; putenv('LG_FOLLOW_DIGEST_ALLOWLIST=' . $keep); }
}

// ── THE TRACKED CONFIG, AS THE SENDER ITSELF RESOLVES IT ──────────────────────
// Not "is there a file at the path the gate expects" — what does the PLUGIN read?
// Those differ the moment someone moves the file, and only the second one governs
// who gets mail.
if (function_exists('lg_fd_config')) {
    $cfg = lg_fd_config();
    $out['cfg_enabled']   = (bool) ($cfg['enabled'] ?? false);
    $out['cfg_allowlist'] = (string) ($cfg['allowlist'] ?? '');
}
if (function_exists('lg_fd_allowlist')) {
    $a = lg_fd_allowlist();
    $out['live_allowlist'] = array('mode' => (string) ($a['mode'] ?? '?'),
                                   'uids' => array_map('intval', (array) ($a['uids'] ?? array())),
                                   'reason' => (string) ($a['reason'] ?? ''));
}

/* ── THE LAYERING: an empty ENV is "no override", not an empty allowlist ──────
 * With no environment value set, the effective allowlist must be exactly what the
 * tracked config says — because the cron context, which is the only thing that
 * actually sends, has no environment at all. If sourcing preferred an empty env over
 * the file, the real sender would resolve to NOBODY on every box and the feature
 * would be a silent nothing. Measured here by clearing both env sources. */
if (function_exists('lg_fd_allowlist_raw') && function_exists('lg_fd_config')) {
    $keep = $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] ?? null;
    putenv('LG_FOLLOW_DIGEST_ALLOWLIST');
    unset($_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST']);
    $out['raw_no_env']  = (string) lg_fd_allowlist_raw();
    $out['raw_matches'] = ($out['raw_no_env'] === (string) (lg_fd_config()['allowlist'] ?? ''));
    if ($keep !== null) { $_SERVER['LG_FOLLOW_DIGEST_ALLOWLIST'] = $keep; putenv('LG_FOLLOW_DIGEST_ALLOWLIST=' . $keep); }
}

/* ── LIVENESS FOR THE CRON PATH, which is where this feature actually sends ────
 * The flag being readable HERE says nothing about whether it is readable in the
 * process that sends: lg-wp-cron.service has no `Environment=`, so getenv() is empty
 * there. This asks the question that matters — can the sender resolve its config
 * WITHOUT any environment at all? — by reading the tracked file directly, the same
 * way lg_fd_config() does, rather than trusting the ambient env this probe inherited. */
$out['cron_readable'] = null;
if (function_exists('lg_fd_config')) {
    $r = new ReflectionFunction('lg_fd_config');
    $p = dirname(dirname($r->getFileName())) . '/config/follow-digest.php';
    $out['cron_cfg_path'] = $p;
    $out['cron_readable'] = is_readable($p);
}
echo json_encode($out);
"""


def build_probe():
    # json.dumps twice would double-escape; the payloads go in as single-quoted PHP
    # string literals, so the only character that can break out is a single quote —
    # and none of the table inputs contain one. Asserted rather than assumed.
    grammar = json.dumps([c[0] for c in GRAMMAR_CASES])
    decisions = json.dumps([[c[0], c[1], c[2]] for c in DECISION_CASES])
    for payload in (grammar, decisions):
        assert "'" not in payload, "a table input contains a single quote; it would "\
                                   "break out of the PHP literal and the probe would "\
                                   "test something other than what the table says"
    return (PROBE
            .replace("%FLAG%", FLAG)
            .replace("%CRON%", CRON_HOOK)
            .replace("%FILTER%", FILTER_HOOK)
            .replace("%CADENCE%", CADENCE_META)
            .replace("%WATERMARK%", WATERMARK_META)
            .replace("%RESOLVER%", RESOLVER)
            .replace("%COLLECTOR%", COLLECTOR)
            .replace("%PARSER%", PARSER)
            .replace("%ALLOWED%", ALLOWED)
            .replace("%DELIVER%", DELIVER)
            .replace("%GRAMMAR%", grammar)
            .replace("%DECISIONS%", decisions)
            .replace("%PLUGIN%", PLUGIN or ""))


def check_choke_point(path):
    """THE STRUCTURAL ASSERTION: exactly one wp_mail() call site, behind the allowlist.

    Every other assertion in this file is about what the code DOES with the inputs it
    was given. This one is about what the code IS, and it is the only one that
    constrains code nobody has written yet. The allowlist is worth nothing if a future
    "send me a preview" button, a retry path or a one-shot can call wp_mail() directly;
    the gate cannot test a path that does not exist, so it asserts the path cannot be
    added quietly.

    ⚠️ ASKED WITH PHP'S OWN LEXER, NOT A GREP, and the first version got this wrong in
    the way that matters. The sender DISCUSSES wp_mail() at length — in comments, and
    in an error_log string that says "no message was handed to wp_mail()". A textual
    search counts that prose and reports two call sites where there is one: a gate
    crying wolf about the exact invariant it exists to protect, which is how a gate
    stops being believed. (`grep -c` would have been wrong twice over — it counts
    LINES, not occurrences.)

    token_get_all() settles it exactly: a real call is a T_STRING token, while the
    same characters inside quotes are T_CONSTANT_ENCAPSED_STRING and inside a comment
    are T_COMMENT. No heuristics, no false positives, nothing to tune.

    @return (list of (line, text), None) or (None, reason-it-is-DEAD)
    """
    if not shutil.which("php"):
        return None, "php not on PATH — cannot tokenise the sender, and a textual "\
                     "search would count the prose in its own comments"
    php = ('$t = token_get_all(file_get_contents($argv[1])); $o = array();'
           'foreach ($t as $x) { if (is_array($x) && $x[0] === T_STRING && $x[1] === "wp_mail")'
           ' { $o[] = $x[2]; } } echo json_encode($o);')
    try:
        p = subprocess.run(["php", "-r", php, "--", path],
                           capture_output=True, text=True, timeout=60)
    except Exception as e:                                    # noqa: BLE001
        return None, "could not tokenise %s: %s" % (path, e)
    if p.returncode != 0:
        return None, "php failed on %s: %s" % (path, (p.stderr or "").strip()[:200])
    try:
        nums = json.loads((p.stdout or "").strip())
    except Exception as e:                                    # noqa: BLE001
        return None, "unparseable tokeniser output (%s): %r" % (e, (p.stdout or "")[:200])

    try:
        with open(path) as fh:
            lines = fh.read().splitlines()
    except OSError as e:
        return None, "could not read %s: %s" % (path, e)
    return [(n, lines[n - 1].strip() if n <= len(lines) else "") for n in nums], None


def main():
    global WP_PATH, PLUGIN, EXPECT_ON, BOOT, ALLOWLIST_ENV
    ap = argparse.ArgumentParser()
    ap.add_argument("--wp-path", default=WP_PATH)
    ap.add_argument("--plugin", default="",
                    help="run WordPress against THIS branch's plugin file (prove a branch pre-merge)")
    ap.add_argument("--expect-on", action="store_true",
                    help="assert the flag resolves ON, so the ON path is exercised deliberately")
    args = ap.parse_args()
    WP_PATH = args.wp_path
    EXPECT_ON = args.expect_on
    ALLOWLIST_ENV = os.environ.get("LG_FOLLOW_DIGEST_ALLOWLIST") or ""
    if args.plugin:
        PLUGIN = os.path.abspath(args.plugin)
        if not os.path.isfile(PLUGIN):
            dead("--plugin", "no such file: %s" % PLUGIN)
            return verdict()
        BOOT, why = build_branch_harness(PLUGIN)
        if BOOT is None:
            dead("--plugin harness", why)
            return verdict()
        print("    (branch mode: WPMU_PLUGIN_DIR -> %s, with %s swapped in.\n"
              "     The serve is NOT modified.)\n" % (HARNESS_DIR, os.path.basename(PLUGIN)))

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
    elif PLUGIN:
        # Branch mode: `init` fired before the file was loaded, so the scheduler
        # cannot have run. Asserting "is it scheduled" here would be measuring the
        # probe, not the code — and the gate must NOT arm it to find out.
        if d.get("tick_attached") and d.get("scheduler_exists"):
            ok("flag ON ⇒ flush is wired to %s and the scheduler exists" % CRON_HOOK,
               "branch mode: init already fired, so arming is not observable here — "
               "and the gate must never arm it itself")
        else:
            red("flag ON but the flush is not wired to %s" % CRON_HOOK,
                "tick_attached=%s scheduler_exists=%s. The control would be visible and "
                "the member picked Daily, but nothing would ever flush their queue — the "
                "silent-nothing lie §15.4 forbids."
                % (d.get("tick_attached"), d.get("scheduler_exists")))
    else:
        if d.get("cron_scheduled"):
            ok("flag ON ⇒ %s is scheduled" % CRON_HOOK)
        else:
            red("flag ON but %s is NOT scheduled" % CRON_HOOK,
                "The control is visible and the member picked Daily, but nothing will "
                "ever flush their queue. That is the silent-nothing lie §15.4 forbids.")
    if stray:
        red("stray lg_fd_* cron hooks", "unexpected scheduled hooks: %s" % ", ".join(stray))

    # ── THE THREE SURFACES ─────────────────────────────────────────────────────
    if not d.get("ui_helper"):
        red("lg_fd_cadence_ui_enabled() does not exist",
            "Three surfaces (account page, follow modal, sender) must agree about "
            "whether the control is shown. Without ONE helper they each check their "
            "own condition and will eventually disagree.")
    else:
        ui = d.get("ui_enabled")
        if ui != flag_on:
            red("the UI gate and the sender flag DISAGREE",
                "lg_fd_cadence_ui_enabled()=%s but %s=%s. The control must never be "
                "visible while the thing that honours it is off — that is exactly the "
                "member picking Daily and receiving instant mail." % (ui, FLAG, flag_on))
        else:
            ok("UI gate agrees with the sender flag", "both %s" % ("ON" if flag_on else "OFF"))

        leaked = [n for n, k in (("state", "ajax_state"), ("set", "ajax_set")) if d.get(k)]
        if not flag_on and leaked:
            red("flag OFF but the account-page transport is REGISTERED",
                "wp_ajax_lg_fd_cadence_%s exists. The hidden state must be enforced "
                "server-side, not merely by not drawing the control — otherwise one "
                "stray render makes it live." % "/".join(leaked))
        elif not flag_on:
            ok("flag OFF ⇒ account-page ajax transport not registered",
               "non-vacuous: the helper exists and resolves false")
        elif len(leaked) == 2:
            ok("flag ON ⇒ both account-page ajax actions registered")
        else:
            red("flag ON but the account-page transport is incomplete",
                "registered: %s. The account page would read a cadence it cannot write, "
                "or write one it cannot read back." % (leaked or "none"))

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

    # ── WHERE THE LINKS POINT ──────────────────────────────────────────────────
    lc = d.get("link_check")
    if lc is None:
        if d.get("resolver_exists"):
            red("link destinations were not checked",
                "lg_fd_render/lg_fd_topic_urls unavailable, or no topic to render against. "
                "A digest's links cannot be edited once sent, so an unchecked destination "
                "is the defect that already reached Ian once.")
    elif lc.get("bad"):
        red("%d link(s) point at the MIRRORED LEGACY FORUM" % lc["bad"],
            "Ian rejected that destination — \"it has no bearing on actual ui\". A member "
            "clicking out of the email must land in the HUB with the discussion open. "
            "Offenders: %s" % ", ".join(lc.get("badlist") or []))
    elif not lc.get("hub"):
        red("the rendered digest emitted NO hub links at all",
            "Every discussion row should carry a /hub/?topic= deep link, or none if the "
            "hub genuinely cannot serve it. Zero hub links means the deep-link path is "
            "dead and every row is unclickable.")
    else:
        ok("every link points at the HUB", "%d hub deep link(s), 0 legacy-forum links"
           % lc["hub"])

    if not d.get("collector_exists"):
        red("%s() does not exist" % COLLECTOR,
            "Cannot assert the two content invariants that matter: a member's own reply "
            "never appears in their own digest, and a fresh cadence never backfills.")

    # ── THE RECIPIENT ALLOWLIST ────────────────────────────────────────────────
    # Ian's controlled end-to-end test: the real cron, the real batching, his real
    # followed threads, with the recipient set hard-locked to him alone. The only
    # question worth gating is "could this send to someone who is not on the list?",
    # and it is answered here structurally rather than by counting a small number.
    missing = [n for n, k in ((PARSER, "parser_exists"), (ALLOWED, "allowed_exists"),
                              (DELIVER, "deliver_exists")) if not d.get(k)]
    if missing:
        red("the allowlist does not exist: %s" % ", ".join(m + "()" for m in missing),
            "Without it the sender's recipient set is whoever holds a cadence — the "
            "whole membership, on an unrecallable channel. THIS IS THE EXPECTED RED "
            "against a build without the allowlist, and it is the red-first proof that "
            "the assertions below are load-bearing rather than decorative.")
    else:
        ok("allowlist machinery present",
           "%s (pure grammar), %s (decision), %s (send layer)" % (PARSER, ALLOWED, DELIVER))

        # ── THE GRAMMAR, exhaustively ──────────────────────────────────────────
        got = d.get("grammar") or []
        if len(got) != len(GRAMMAR_CASES):
            dead("grammar table",
                 "probe returned %d rows for %d cases — cannot say what the parser "
                 "accepts, so the allowlist's shape is unproven"
                 % (len(got), len(GRAMMAR_CASES)))
        else:
            bad = []
            for (raw, want_mode, want_uids), g in zip(GRAMMAR_CASES, got):
                if g.get("mode") != want_mode or g.get("uids") != want_uids:
                    bad.append("%r -> %s%s (expected %s%s)"
                               % (raw, g.get("mode"), g.get("uids") or "",
                                  want_mode, want_uids or ""))
            # The rows whose failure would WIDEN the set are the ones that matter;
            # named separately so a widening bug can never read as one of a list.
            widened = [b for b in bad if "-> all" in b or "-> list" in b]
            if widened:
                red("the allowlist grammar ADMITS MORE than it should",
                    "%d row(s) resolve to recipients where the table says nobody. This "
                    "is the fail-open direction and it is the one that mails strangers: "
                    "%s" % (len(widened), "; ".join(widened[:4])))
            if bad and not widened:
                red("the allowlist grammar does not match its table",
                    "%d row(s) differ, all in the fail-CLOSED direction (they admit "
                    "fewer than expected, so nothing unsafe — but the table and the code "
                    "disagree and one of them is wrong): %s" % (len(bad), "; ".join(bad[:4])))
            if not bad:
                closed = sum(1 for c in GRAMMAR_CASES if c[1] == "none")
                ok("allowlist grammar matches its table on all %d inputs" % len(GRAMMAR_CASES),
                   "%d degenerate input(s) — empty, malformed, '*', a bare address, a "
                   "negative id — ALL resolve to NOBODY; exactly one input (%r) widens"
                   % (closed, ALLOW_ALL_TOKEN))

        # ── WHO MAY BE MAILED ──────────────────────────────────────────────────
        dec = d.get("decisions") or []
        if len(dec) != len(DECISION_CASES):
            dead("decision table",
                 "probe returned %d verdicts for %d cases" % (len(dec), len(DECISION_CASES)))
        else:
            wrong = [(c, g) for c, g in zip(DECISION_CASES, dec) if bool(g) != c[3]]
            leaks = [c for c, g in wrong if g]        # allowed when it should have blocked
            if leaks:
                red("the allowlist ALLOWED %d subject(s) it must block" % len(leaks),
                    "Each of these is a message reaching an address the allowlist does "
                    "not name: %s" % "; ".join("allowlist=%r uid=%s <%s>" % (c[0], c[1], c[2])
                                               for c in leaks[:4]))
            refused = [c for c, g in wrong if not g]  # blocked when it should have allowed
            if refused:
                red("the allowlist BLOCKED %d subject(s) it should admit" % len(refused),
                    "Safe direction — nothing is mailed to a stranger — but the test send "
                    "would deliver nothing and read as a broken batcher: %s"
                    % "; ".join("allowlist=%r uid=%s" % (c[0], c[1]) for c in refused[:4]))
            if not wrong:
                blocks = sum(1 for c in DECISION_CASES if not c[3])
                ok("every one of %d recipient decisions is correct" % len(DECISION_CASES),
                   "%d block(s) asserted, including a changed address on an allowlisted "
                   "uid and a malformed allowlist admitting NOBODY" % blocks)

        # ── THE STRUCTURAL ONE: the send layer cannot be routed around ─────────
        # THE FILE WORDPRESS ACTUALLY LOADED, not the repo copy sitting next to this
        # gate. In serve mode those are different files — the docroot symlinks main —
        # and checking the branch's source while probing main's behaviour is precisely
        # the "verifying on dev2 is usually testing main" trap wearing a gate's clothes.
        src = PLUGIN or d.get("loaded") or os.path.join(os.path.dirname(os.path.dirname(
            os.path.dirname(os.path.abspath(__file__)))), "platform/mu-plugins/lg-follow-digest.php")
        calls, why = check_choke_point(src)
        if calls is None:
            dead("choke point", why)
        elif len(calls) != 1:
            red("%d wp_mail() call site(s) in the sender — expected exactly 1" % len(calls),
                "The allowlist is enforced at %s(). A second call site is a way to mail "
                "somebody without passing it, and the gate cannot test a path that does "
                "not exist yet — which is why the SHAPE is asserted, not just the "
                "behaviour. Sites: %s" % (DELIVER, ", ".join("line %d" % c[0] for c in calls)))
        else:
            line = calls[0][0]
            with open(src) as fh:
                body = fh.read().splitlines()
            # Walk back to the enclosing function, and check the allowlist is consulted
            # between it and the send.
            start = 0
            for i in range(line - 1, 0, -1):
                if body[i - 1].startswith("function "):
                    start = i
                    break
            fn = body[start - 1].split("(")[0].replace("function ", "").strip() if start else "?"
            guarded = any(ALLOWED + "(" in body[i - 1] for i in range(start, line))
            if fn != DELIVER:
                red("the only wp_mail() is in %s(), not %s()" % (fn, DELIVER),
                    "The send layer is where the allowlist is enforced; a send from "
                    "anywhere else has not passed it.")
            elif not guarded:
                red("wp_mail() is not guarded by %s() inside %s()" % (ALLOWED, DELIVER),
                    "The call site exists but nothing checks the recipient before it. "
                    "This is the whole wall, and it is not there.")
            else:
                ok("exactly ONE wp_mail() in the sender, inside %s(), behind %s()"
                   % (DELIVER, ALLOWED),
                   "line %d — a future code path cannot mail anyone without passing the "
                   "allowlist, or this goes red" % line)

        # ── THE TRACKED CONFIG, as the SENDER resolves it ──────────────────────
        if d.get("cron_readable") is False:
            red("the sender cannot read its tracked config",
                "lg_fd_config() resolves %s and it is not readable. The allowlist would "
                "fall back to its default — which is NOBODY, so nothing unsafe happens, "
                "but the test send would deliver nothing and look like a broken batcher."
                % d.get("cron_cfg_path"))
        elif d.get("cron_readable"):
            ok("the sender resolves its tracked config without any environment",
               "%s — the cron context has no env at all, so this file (not an FPM "
               "env var) is what reaches the sender" % d.get("cron_cfg_path"))

        if d.get("raw_matches") is False:
            red("an empty environment does not fall through to the tracked config",
                "With no env set, lg_fd_allowlist_raw() returned %r but the tracked "
                "config says %r. The cron context has NO environment at all, so the "
                "real sender would resolve to that first value on every box — and if it "
                "is empty, the feature is a silent nothing that mails nobody, forever."
                % (d.get("raw_no_env"), d.get("cfg_allowlist")))
        elif d.get("raw_matches"):
            ok("with no environment, the allowlist is exactly the tracked config",
               "%r — which is what the cron context will read, since it has no env"
               % d.get("raw_no_env"))

        live = d.get("live_allowlist") or {}
        if live.get("mode") == "all":
            red("the tracked allowlist is %r — THE WHOLE MEMBERSHIP" % ALLOW_ALL_TOKEN,
                "Test mode requires the full member list to be UNREACHABLE. This is the "
                "one input that reaches it. If general release is genuinely intended, "
                "this gate line changes in the SAME commit — that is the point of it "
                "being one visible, reviewable diff rather than a silent widening.")
        elif live.get("mode") == "list":
            ok("the tracked allowlist admits a bounded set",
               "uids %s — %s" % (live.get("uids"), live.get("reason")))
        else:
            ok("the tracked allowlist admits NOBODY", "%s" % live.get("reason"))

        # ── AND AGAINST THE REAL DATA: every due member is allowlisted ─────────
        # The assertion that ties the table to this box's actual rows. A count of
        # recipients cannot prove the allowlist held; these are the ids themselves.
        allow_uids = live.get("uids") or []
        if live.get("mode") == "all":
            pass                       # already red above
        else:
            strangers = []
            for cad, ids in (d.get("recipient_ids") or {}).items():
                for uid in ids:
                    if uid not in allow_uids:
                        strangers.append("%s:uid %d" % (cad, uid))
            if strangers:
                red("%d due recipient(s) are NOT on the allowlist" % len(strangers),
                    "The resolver would hand these to the sender: %s. Either the "
                    "allowlist is not applied in %s() or it resolved differently there "
                    "than here." % (", ".join(strangers[:6]), RESOLVER))
            elif d.get("resolver_exists"):
                tot = sum(len(v) for v in (d.get("recipient_ids") or {}).values())
                ok("every due recipient on this box is on the allowlist",
                   "%d due across all cadences, all within uids %s" % (tot, allow_uids))

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
