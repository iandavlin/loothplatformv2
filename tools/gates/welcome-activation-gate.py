#!/usr/bin/env python3
"""
THE WELCOME ONE-SHOT REACHES BOTH RAILS, FIRES ONCE, AND NEVER RETRO-FIRES.

KEEPER GATE NUMBER: 63 — ASSIGNED by keeper 2026-08-16, and VERIFIED FREE before
being written here rather than taken on trust: no hit on origin/main, none in any
worktree on the box, and unregistered in main's run-all.sh. Keeper's own collision
note names 59 as guitardle's and 62 as compose's; a third lane was told 57 in the
same window as featured-members and both wrote it down, so "keeper said so" is not
proof a number is free. Lanes do not mint numbers — they still check.

STATE: GREEN on this branch (the fix is present, flag OFF), RED on main. It was
written red-first, before the fix existed, against the property the product was
violating; the section lettering below is the POST-FIX one, so do not expect the
red-first commit's letters to match.

WHAT IT ASSERTS, and why this shape rather than the obvious one.

The obvious gate would assert "the welcome fires for a new member". That is an
implementation detail and it would have to be rewritten when the mechanism moves.
The property Ian actually rules on is SYMMETRY: two members who paid the same
money must get the same product regardless of which rail they joined through
("Everything needs to fire for both for the foreseeable future… we are dual
wielding patreon and stripe for a while"). So §C asserts that the two
account-creation SHAPES end in the same welcome outcome, and says nothing about
which outcome. That assertion is true after the fix, was true before the Patreon
path started pre-applying the role, and is RED exactly while the defect exists.

MEASURED CAUSE (live, 2026-08-15). Arbiter::sync derived $oldTier from the
member's CURRENT WordPress roles and stamped the one-shot only on a transition
into a paid tier. That is a question about ROLE ORDERING, and the two production
creation paths sit on opposite sides of it:

  lg-patreon-onboard.php:1615   creates WITH the paid role  → old === winning → never fires
  UserLifecycle.php:231         creates with NO role        → null → looth3   → fires

THE SECTIONS, in the order they run:

  A  LIVENESS, twice over. Everything below compares probe results, so if the
     arbiter never ran they all come back empty, "agree", and the headline would
     pass having measured nothing. It also asserts SCHEMA liveness — that the
     probe actually RETURNS every field the assertions read. The first cut omitted
     'marker', so "OFF writes no marker" passed vacuously on the exact property it
     existed to protect. An absence assertion is worth nothing until you prove the
     question was asked.
  B  THE SHIPPED STATE, read from the config rather than hardcoded, so this gate
     stays correct the day the default flips. OFF is a CONTRACT, not "nothing to
     check": today's behaviour exactly, and not one byte of new state.
  C  THE HEADLINE — armed, both rails welcome alike.
  D  RETRO-FIRE, the dangerous half. Asserted BEHAVIOURALLY against a member
     shaped like the 1,109, and asserted WITHOUT running any backfill: a backfill
     is something a person has to remember to run, which makes it a plan, not a
     guard. The fence must stand alone.
  E  The Stripe leg stays untouched (stripe-membership's gate 34d).

RETRO-FIRE IS THE DANGEROUS HALF, restated because it governs the design: 1,109
currently-paying members carry no welcome marker of any kind, so a fix keyed on
"paid and never welcomed" mails eleven hundred people on the first sweep,
unrecallably.

DEV2 NOTE: pre_wp_mail is filtered on this box, so wp_mail returns false and
WelcomeMailer never stamps _lg_welcome_email_sent_at. Every mail-side assertion
here reads the STAMP INTENT, never "an email arrived" — dev2 cannot prove a send.

EXIT CODES follow run-all.sh: 0 green, 1 RED (real finding), 2 CANNOT RUN.
A guard reading the CODE UNDER TEST fails RED; only a guard reading the
ENVIRONMENT (no wp-cli, no WordPress) may fail CANNOT RUN — a cannot() exits 2
immediately and would otherwise overwrite a RED already recorded by an earlier
section.
"""
import json
import os
import re
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
ARBITER = os.path.join(ROOT, "lg-patreon-stripe-poller", "src", "Arbiter.php")
CFG = os.path.join(ROOT, "platform", "config", "welcome-activation.php")
ONBOARD = os.path.join(ROOT, "lg-patreon-stripe-poller", "lg-patreon-onboard.php")
LIFECYCLE = os.path.join(ROOT, "lg-patreon-stripe-poller", "src", "UserLifecycle.php")
STRIPE_LC = os.path.join(ROOT, "lg-patreon-stripe-poller", "src", "StripeLifecycle.php")
WP_PATH = "/var/www/dev"

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


def wp_eval_file(path, env=None):
    e = dict(os.environ)
    if env:
        e.update(env)
    r = subprocess.run(
        ["sudo", "-n", "-E", "wp", "--allow-root", f"--path={WP_PATH}", "eval-file", path],
        capture_output=True, text=True, env=e,
    )
    if r.returncode != 0:
        cannot(f"wp eval-file failed: {(r.stderr or r.stdout).strip()[:300]}")
    return r.stdout


# The probe runs both creation shapes through the real arbiter and reports what
# each one ended up with. Probe accounts are keyed to the PID — a fixed test
# account produces false results the moment anything else writes concurrently —
# and are removed in a shutdown handler whatever happens.
PROBE = r"""<?php
require_once '__PROBE_REQUIRE__';
$pid = getmypid(); $made = [];
register_shutdown_function(function () use (&$made) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($made as $id) { wp_delete_user($id); }
});
// SWEEP STRAY PROBE MEMBERS LEFT BY KILLED RUNS. The handler above covers a
// normal exit and even a fatal; it never runs on a SIGKILL or a harness timeout,
// and this gate had already left three members behind exactly that way. They are
// not merely untidy — another lane measured them as noise in a byte-inert diff,
// because a member count that moves between two captures reads as a real change.
//
// Keyed on the OWNING PID BEING GONE, deliberately never on age: the
// existing-member probe is registered in 2025 on purpose, so an age rule would
// delete a CONCURRENT run's live probe and make this gate the very thing it is
// cleaning up after.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach (get_users(['search'=>'lgwgate*','search_columns'=>['user_login'],'fields'=>['ID','user_login']]) as $u) {
    if (preg_match('/^lgwgate(\d+)z[0-9a-f]{6}$/', $u->user_login, $mm) && !is_dir('/proc/' . $mm[1])) {
        wp_delete_user((int) $u->ID);
    }
}
function lgw_probe(string $key, bool $roleAtCreation, array &$made, int $pid, string $registered = ''): array {
    $login = "lgwgate{$pid}z" . substr(md5($key), 0, 6);
    $args = ['user_login'=>$login,'user_email'=>$login.'@example.invalid',
             'user_pass'=>wp_generate_password(24,true,true),'display_name'=>$login];
    if ($registered !== '') $args['user_registered'] = $registered;
    if ($roleAtCreation) $args['role'] = 'looth3';
    $id = wp_insert_user($args);
    if (is_wp_error($id)) return ['error'=>$id->get_error_message()];
    $made[] = (int)$id;
    if (!$roleAtCreation) (new WP_User($id))->set_role('subscriber');
    \LGMS\RoleSourceWriter::report((int)$id, 'patreon', 'looth3');
    $cls = '\\LGMS\\__PROBE_CLASS__'; $res = $cls::sync((int)$id);
    return [
        'old'     => $res['old_tier'] ?? null,
        'winning' => $res['winning_tier'] ?? null,
        'stamp'   => (string) get_user_meta((int)$id, '_lg_pending_welcome', true),
        'marker'  => (string) get_user_meta((int)$id, '_lg_membership_activated_at', true),
        'mail'    => (string) get_user_meta((int)$id, '_lg_welcome_email_sent_at', true),
    ];
}
$out = [
  'patreon_shape' => lgw_probe('patreon', true,  $made, $pid),
  'stripe_shape'  => lgw_probe('stripe',  false, $made, $pid),
  // An EXISTING member: holds a paid tier, registered long before any cutover.
  // 1,109 real members look exactly like this and none of them may be mailed.
  'existing'      => lgw_probe('existing', true,  $made, $pid, '2025-03-01 00:00:00'),
  'mail_intercepted' => (bool) has_filter('pre_wp_mail'),
];
echo "LGWJSON:" . json_encode($out) . "\n";
"""

# DRIVE THE BRANCH, NOT THE SERVE. wp eval-file loads WordPress, and the poller
# reaches WordPress through an mu-plugin symlink into loothplatformv2-clean — so
# \LGMS\Arbiter is ALWAYS main's copy, whatever this branch says. A gate that
# calls it is grading the deployed tree and would report a branch's fix as absent
# (and, worse, report a branch's REGRESSION as fine). Measured: the first run of
# this harness reported flag ON changing nothing at all, because it never loaded
# the edited file.
#
# So the probe copies THIS TREE's Arbiter under a different class name, into this
# tree's own src/ — the directory matters, because the config is resolved with
# __DIR__ and a copy in /tmp would read the serve's config or none at all.
# SWEEP STALE PROBES FIRST. The finally-block below deletes the probe copy on a
# normal exit — but a timeout or a kill never runs finally, and this gate lives
# inside a plugin's src/ where a stray PHP class would sit in the working tree
# waiting to be committed and shipped. Measured: one run killed by a harness
# timeout left ArbiterProbe<pid>.php behind exactly this way. Cleaning at START
# is the half that survives being killed.
for stale in os.listdir(os.path.join(ROOT, "lg-patreon-stripe-poller", "src")):
    if re.match(r"^ArbiterProbe\d+\.php$", stale):
        try:
            os.unlink(os.path.join(ROOT, "lg-patreon-stripe-poller", "src", stale))
            print(f"  ..  swept a stale probe left by an earlier killed run: {stale}")
        except OSError:
            pass

probe_class = f"ArbiterProbe{os.getpid()}"
probe_php = os.path.join(ROOT, "lg-patreon-stripe-poller", "src", f"{probe_class}.php")
src_arbiter = open(ARBITER, encoding="utf-8", errors="ignore").read()
if not re.search(r"^final class Arbiter$", src_arbiter, re.M):
    cannot("could not find 'final class Arbiter' to clone — the probe would grade the wrong file")
with open(probe_php, "w") as fh:
    fh.write(re.sub(r"^final class Arbiter$", f"final class {probe_class}", src_arbiter, count=1, flags=re.M))

probe_path = os.path.join("/tmp", f"lgw-probe-{os.getpid()}.php")
with open(probe_path, "w") as fh:
    fh.write(PROBE.replace("__PROBE_REQUIRE__", probe_php).replace("__PROBE_CLASS__", probe_class))


def run_probe():
    m = re.search(r"LGWJSON:(\{.*\})", wp_eval_file(probe_path))
    if not m:
        cannot("probe produced no result line; WordPress or the poller may not be loadable here")
    return json.loads(m.group(1))


def read_flag():
    """Read the shipped state from the branch's own config — never hardcode it.
    A gate that assumes OFF goes red the day the default flips, and blocks
    every lane for a change that was intended."""
    if not os.path.isfile(CFG):
        return False
    txt = open(CFG, encoding="utf-8", errors="ignore").read()
    return bool(re.search(r"'enabled'\s*=>\s*true", txt))


try:
    shipped_on = read_flag()
    data = run_probe()

    # The ON contract is exercised by arming the flag in a SNAPSHOTTED copy of the
    # tracked config and restoring the exact bytes afterwards. Snapshot, never
    # `git checkout --`: a checkout would wipe uncommitted work under test.
    cfg_before = open(CFG, "rb").read() if os.path.isfile(CFG) else None
    data_on = None
    if cfg_before is not None:
        try:
            armed = re.sub(rb"'enabled'\s*=>\s*false", b"'enabled' => true", cfg_before, count=1)
            if armed == cfg_before and not shipped_on:
                cannot("could not arm the flag in a copy of the config — the ON half would be untested")
            with open(CFG, "wb") as fh:
                fh.write(armed)
            data_on = run_probe()
        finally:
            with open(CFG, "wb") as fh:
                fh.write(cfg_before)
            if open(CFG, "rb").read() != cfg_before:
                cannot("FAILED TO RESTORE the tracked config after arming it — refusing to continue")
finally:
    for f in (probe_path, probe_php):
        try:
            os.unlink(f)
        except OSError:
            pass

pat, stp = data.get("patreon_shape", {}), data.get("stripe_shape", {})

# ── A. LIVENESS ────────────────────────────────────────────────────────────────
# Everything below compares two probe results. If the arbiter never actually ran,
# both come back empty, they "agree", and §B would pass having measured nothing.
print("A. liveness — the arbiter really ran for both shapes")
if pat.get("error") or stp.get("error"):
    cannot(f"probe accounts could not be created: {pat.get('error') or stp.get('error')}")
if pat.get("winning") == "looth3" and stp.get("winning") == "looth3":
    ok("both probes reached the arbiter and resolved the same winning tier (looth3)")
else:
    cannot(f"the arbiter did not resolve a tier for both shapes: "
           f"patreon={pat.get('winning')!r} stripe={stp.get('winning')!r} — §B would be vacuous")

# SCHEMA LIVENESS. Every assertion below reads a named field and treats "" as an
# absence. A field the probe never RETURNS therefore reads as absence forever —
# and that is not hypothetical: the first cut of this gate omitted 'marker' from
# the probe, so "OFF writes no marker" passed while the marker was being written,
# and the armed run reported the self-backfill missing when it was working. An
# absence assertion is worth nothing until you prove the question was asked.
for shape in ("patreon_shape", "stripe_shape", "existing"):
    missing = [k for k in ("old", "winning", "stamp", "marker", "mail")
               if k not in data.get(shape, {})]
    if missing:
        cannot(f"probe result '{shape}' is missing field(s) {missing} — "
               "every absence assertion reading them would pass vacuously")
ok("probe returns every field the assertions read (no field can read as a false absence)")

if data.get("mail_intercepted"):
    ok("mail is intercepted on this box — mail assertions read STAMP INTENT, not delivery")
else:
    ok("mail is not intercepted here")

# ── B. THE SHIPPED STATE — read the flag, never assume it ─────────────────────
print("\nB. the shipped state behaves as the flag says it should")
pat_w = pat.get("stamp", "") != ""
stp_w = stp.get("stamp", "") != ""
pat_m = pat.get("marker", "") != ""
stp_m = stp.get("marker", "") != ""

if not shipped_on:
    # OFF is not "nothing to check". It is a CONTRACT: today's behaviour exactly,
    # and not one byte of new state. The marker write sits inside the enabled
    # check precisely so that OFF stays byte-identical rather than "the old
    # behaviour plus a harmless write" — and a harmless write is still a write.
    if not pat_m and not stp_m:
        ok("OFF — no activation marker is written for anybody (a true no-op)")
    else:
        bad("OFF still writes the activation marker — the off state is not byte-identical",
            f"patreon_marker={pat_m} stripe_marker={stp_m}")
    if not pat_w and stp_w:
        ok("OFF — today's behaviour preserved exactly (transition-only welcome)")
    else:
        bad("OFF changed the existing welcome behaviour",
            f"role-at-creation welcomed={pat_w} (expected False), role-after welcomed={stp_w} (expected True)")
else:
    ok("shipped state is ON — the ON contract below is the shipped contract")

# ── C. THE HEADLINE: WITH THE FLAG ARMED, BOTH RAILS WELCOME ALIKE ────────────
# Asserted as SYMMETRY, not as "the welcome fires". Symmetry is the thing Ian
# rules on ("Everything needs to fire for both"), it survives the mechanism
# changing, and it is the property the product is violating today.
print("\nC. armed — the two joining shapes end in the SAME welcome outcome")
if data_on is None:
    cannot("the armed probe did not run; the headline would be untested")
pat_on = data_on.get("patreon_shape", {})
stp_on = data_on.get("stripe_shape", {})
if pat_on.get("winning") != "looth3" or stp_on.get("winning") != "looth3":
    cannot("the armed probe did not resolve a tier for both shapes — C would be vacuous")
pw = pat_on.get("stamp", "") != ""
sw = stp_on.get("stamp", "") != ""
if pw and sw:
    ok("armed — BOTH rails welcome: role-applied-at-creation and role-granted-after agree")
elif pw == sw:
    bad("armed — the rails agree but NEITHER is welcomed; the fix does nothing",
        f"patreon={pw} stripe={sw}")
else:
    bad("armed — a member's welcome still depends on WHICH RAIL they joined through",
        f"role-applied-at-creation welcomed={pw} (old={pat_on.get('old')!r}), "
        f"role-granted-after welcomed={sw} (old={stp_on.get('old')!r})")

# The marker is named _lg_membership_activated_at, so it has to MEAN that for the
# members it is true about. A genuine first activation carries no provenance
# prefix; only a swept or backfilled row does.
pm_on = pat_on.get("marker", "")
if pm_on and not pm_on.startswith(("pre-cutover:", "backfill:")):
    ok("armed — a genuine first activation records a clean activation date")
else:
    bad("armed — a genuine first activation did not record a clean activation date",
        f"marker={pm_on!r}")

# ── D. RETRO-FIRE — the guard that must not depend on a backfill ─────────────
# The dangerous half. 1,109 currently-paying members carry no welcome marker, so
# a fix keyed on "paid and never welcomed" mails eleven hundred people on the
# first sweep, unrecallably. Asserted BEHAVIOURALLY against a member shaped like
# those 1,109 — a source grep for a date fence proves only that somebody wrote
# one, not that it holds.
#
# And asserted WITHOUT running any backfill, deliberately: a backfill is something
# a person has to remember to run, which makes it a plan, not a guard. The fence
# must stand alone.
print("\nD. armed — an EXISTING member is marked, and NOT mailed")
ex = data_on.get("existing", {})
if ex.get("winning") != "looth3":
    cannot("the existing-member probe did not resolve a tier — D would be vacuous")
ex_w = ex.get("stamp", "") != ""
ex_m = ex.get("marker", "") != ""
if not ex_w and ex_m:
    ok("armed — a pre-cutover member is recorded as activated but receives NO welcome")
elif ex_w:
    bad("RETRO-FIRE: a member who joined before the cutover would be welcomed",
        "1,109 paying members look like this one (live, 2026-08-15) — that is a mass mail")
else:
    bad("armed — the existing member was not marked, so the flag never self-backfills",
        f"welcomed={ex_w} marker={ex_m}")

# ...and it must not lie about WHEN for the members it is not true about. A bare
# timestamp here reads as "activated today" for all 1,225 of them, and nothing
# downstream could then tell a swept member from one who genuinely joined this
# morning — the distinction the whole cutover rests on.
ex_marker = ex.get("marker", "")
if ex_marker.startswith("pre-cutover:"):
    ok("armed — the swept marker is labelled pre-cutover, not a false activation date")
else:
    bad("the sweep stamps a bare activation date on a member who activated long ago",
        f"marker={ex_marker!r} — _lg_membership_activated_at would be wrong for 1,225 members")

# The OFF state must not mark them either.
ex_off = data.get("existing", {})
if (ex_off.get("marker", "") != "") and not shipped_on:
    bad("OFF writes an activation marker for existing members — not a byte-identical no-op")
else:
    ok("OFF — an existing member is left completely untouched")

# ── E. THE STRIPE LEG STAYS UNTOUCHED (stripe-membership's gate 34d) ──────────
print("\nE. nothing is wired into the Stripe leg")
slc = open(STRIPE_LC, encoding="utf-8", errors="ignore").read()
slc_code = re.sub(r"/\*.*?\*/", "", slc, flags=re.S)
slc_code = re.sub(r"^\s*(//|\*).*$", "", slc_code, flags=re.M)
leaked = [n for n in ("_lg_pending_welcome", "WelcomeMailer", "sendIfNeeded") if n in slc_code]
if not leaked:
    ok("StripeLifecycle neither stamps the welcome nor mails — gate 34d's rule holds")
else:
    bad("the Stripe leg would mail or stamp member data — gate 34d forbids it", ", ".join(leaked))

# ── verdict ───────────────────────────────────────────────────────────────────
print(f"\n{checks} checks run.")
if fails:
    print("\nWELCOME-ACTIVATION GATE RED:")
    for f in fails:
        print("  ✗ " + f)
    sys.exit(1)
print("WELCOME-ACTIVATION GATE GREEN — both rails welcome alike, once, and never retroactively.")
