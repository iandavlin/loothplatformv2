#!/usr/bin/env python3
"""
THE WELCOME ONE-SHOT REACHES BOTH RAILS, FIRES ONCE, AND NEVER RETRO-FIRES.

⚠️ NOT REGISTERED IN run-all.sh AND DELIBERATELY UNNUMBERED. This gate is RED
against the tree it was written on, because it asserts the property the product
is currently violating. A red gate in run-all.sh blocks every lane on the box, so
it stays out of that file until the fix lands and keeper mints a number. Lanes do
not mint gate numbers.

WHAT IT ASSERTS, and why this shape rather than the obvious one.

The obvious gate would assert "the welcome fires for a new member". That is an
implementation detail and it would have to be rewritten when the mechanism moves.
The property Ian actually rules on is SYMMETRY: two members who paid the same
money must get the same product regardless of which rail they joined through
("Everything needs to fire for both for the foreseeable future… we are dual
wielding patreon and stripe for a while"). So §B asserts that the two
account-creation SHAPES end in the same welcome outcome, and says nothing about
which outcome. That assertion is true after the fix, was true before the Patreon
path started pre-applying the role, and is RED exactly while the defect exists.

MEASURED CAUSE (live, 2026-08-15). Arbiter::sync derives $oldTier from the
member's CURRENT WordPress roles and stamps the one-shot only on a transition into
a paid tier. The two production creation paths sit on opposite sides of that test:

  lg-patreon-onboard.php:1615   creates WITH the paid role  → old === winning → never fires
  UserLifecycle.php:231         creates with NO role        → null → looth3   → fires

RETRO-FIRE IS THE DANGEROUS HALF. 1,109 currently-paying members carry no welcome
marker of any kind, so a fix keyed on "paid and never welcomed" mails eleven
hundred people on the first sweep, unrecallably. §D asserts the date fence
independently of any backfill, because a guard that depends on a backfill having
been run is not a guard.

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
$pid = getmypid(); $made = [];
register_shutdown_function(function () use (&$made) {
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ($made as $id) { wp_delete_user($id); }
});
function lgw_probe(string $key, bool $roleAtCreation, array &$made, int $pid): array {
    $login = "lgwgate{$pid}" . substr(md5($key), 0, 6);
    $args = ['user_login'=>$login,'user_email'=>$login.'@example.invalid',
             'user_pass'=>wp_generate_password(24,true,true),'display_name'=>$login];
    if ($roleAtCreation) $args['role'] = 'looth3';
    $id = wp_insert_user($args);
    if (is_wp_error($id)) return ['error'=>$id->get_error_message()];
    $made[] = (int)$id;
    if (!$roleAtCreation) (new WP_User($id))->set_role('subscriber');
    \LGMS\RoleSourceWriter::report((int)$id, 'patreon', 'looth3');
    $res = \LGMS\Arbiter::sync((int)$id);
    return [
        'old'     => $res['old_tier'] ?? null,
        'winning' => $res['winning_tier'] ?? null,
        'stamp'   => (string) get_user_meta((int)$id, '_lg_pending_welcome', true),
        'mail'    => (string) get_user_meta((int)$id, '_lg_welcome_email_sent_at', true),
    ];
}
$out = [
  'patreon_shape' => lgw_probe('patreon', true,  $made, $pid),
  'stripe_shape'  => lgw_probe('stripe',  false, $made, $pid),
  'mail_intercepted' => (bool) has_filter('pre_wp_mail'),
];
echo "LGWJSON:" . json_encode($out) . "\n";
"""

probe_path = os.path.join("/tmp", f"lgw-probe-{os.getpid()}.php")
with open(probe_path, "w") as fh:
    fh.write(PROBE)
try:
    raw = wp_eval_file(probe_path)
finally:
    try:
        os.unlink(probe_path)
    except OSError:
        pass

m = re.search(r"LGWJSON:(\{.*\})", raw)
if not m:
    cannot("probe produced no result line; WordPress or the poller may not be loadable here")
data = json.loads(m.group(1))
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

if data.get("mail_intercepted"):
    ok("mail is intercepted on this box — mail assertions read STAMP INTENT, not delivery")
else:
    ok("mail is not intercepted here")

# ── B. THE HEADLINE: RAIL SYMMETRY ────────────────────────────────────────────
print("\nB. dual rail — the two joining shapes end in the SAME welcome outcome")
pat_welcomed = pat.get("stamp", "") != ""
stp_welcomed = stp.get("stamp", "") != ""
if pat_welcomed == stp_welcomed:
    ok(f"both shapes agree (welcomed={pat_welcomed}) — no rail-dependent difference")
else:
    bad("a member's welcome depends on WHICH RAIL they joined through",
        f"role-applied-at-creation welcomed={pat_welcomed} (old={pat.get('old')!r}), "
        f"role-granted-after welcomed={stp_welcomed} (old={stp.get('old')!r}) — "
        "same tier, same arbiter call, opposite outcome")

# ── C. THE CAUSE IS NAMED, so the fix cannot drift back ───────────────────────
print("\nC. the transition test is not fed by a pre-applied role")
onboard = open(ONBOARD, encoding="utf-8", errors="ignore").read()
creates_with_role = re.search(r"wp_insert_user\(\s*array\((?:[^;]{0,400}?)'role'\s*=>", onboard, re.S)
arb = open(ARBITER, encoding="utf-8", errors="ignore").read()
keys_on_roles = re.search(r"\$oldTier\s*=\s*self::currentTier\(\s*\(array\)\s*\$user->roles", arb)
if creates_with_role and keys_on_roles:
    bad("the welcome is decided by role ORDERING, not by activation",
        "lg-patreon-onboard.php creates the account with the paid role while Arbiter::sync "
        "reads $oldTier from the CURRENT roles, so that rail can never show a transition")
else:
    ok("activation is no longer decided by whether the role was applied before the arbiter ran")

# ── D. RETRO-FIRE — the guard that must not depend on a backfill ──────────────
print("\nD. no retro-fire for members who joined before the cutover")
fence = re.search(r"user_registered|registered_after|LG_WELCOME_CUTOVER|activation_cutover", arb)
if fence:
    ok("the arbiter carries a registration-date fence independent of any backfill")
else:
    bad("no date fence in the arbiter — a missed backfill row could mail a pre-cutover member",
        "1,109 paying members carry no welcome marker of any kind (live, 2026-08-15)")

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
