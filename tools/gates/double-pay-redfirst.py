#!/usr/bin/env python3
"""RED-FIRST for GATE 75 sections 9 and 10 (#193 rider + Ian 2026-08-22)

Section 9 is the rider: the double-pay guard was ON and BLIND, because
/patreon-standing answered 401 bb_rest_authorization_required. R5 is the one
that matters — it reopens THE MIKELLE CASE, a listed tester who actively pays
Patreon, and it must redden 9c*.

Section 10 is Ian's ruling that the linked Patreon address appears on any
double-pay or switch surface, with keeper's rail that it reaches the SIGNED-IN
member only. I2 is the mutation that leaks it across the server-to-server
boundary; if I2 ever goes green, the rail is unguarded.

    python3 tools/gates/double-pay-redfirst.py

Measured 2026-08-22: 17/17 caught, 1/1 no-op inert, baseline 131 assertions.

Three of these were BLIND on the first run and the gate was fixed, not the
mutation: I6 (a "renders it" check that looked for the function NAME, satisfied
by a call pinned to false), I7 (a selector regex whose [^{}]* swallowed a
"-DISABLED" suffix, so a prefix match read as a hit), and I11 (no assertion at
all that the standalone app SELECTs the address, so its sentence had nothing to
say while every copy check passed).

⚠️  SNAPSHOTS AND RESTORES BY BYTES, never `git checkout --`: a harness that
restores from HEAD wipes uncommitted work under test, and this repo has already
paid for that once. Everything is copied to a temp dir first and copied back in
a finally block.
"""
import pathlib, shutil, subprocess, tempfile, re, sys

ROOT  = pathlib.Path(__file__).resolve().parents[2]
GATE  = ROOT / 'tools/gates/double-pay-block-gate.php'
PS    = ROOT / 'lg-patreon-stripe-poller/src/Membership/PatreonStanding.php'
PSR   = ROOT / 'lg-patreon-stripe-poller/src/Wp/PatreonStandingRestController.php'
PLUG  = ROOT / 'lg-patreon-stripe-poller/src/Plugin.php'
WPD   = ROOT / 'lg-patreon-stripe-poller/src/Wp/CheckoutRestController.php'
CFG   = ROOT / 'membership-pages/config.php'
MS    = ROOT / 'membership-pages/web/manage-subscription.php'
MSC   = ROOT / 'membership-pages/web/manage-subscription.css'
JOIN  = ROOT / 'membership-pages/web/lgjoin.php'
GUARD = ROOT / 'lg-stripe-billing/src/Core/DoublePayGuard.php'
FILES = [PS, PSR, PLUG, WPD, CFG, MS, MSC, JOIN, GUARD]

MUTS = {
 # ── §9, the rider ──
 "R1": ("the /patreon-standing exemption is removed — the guard goes back to armed and blind", PSR,
        ("        if ( ! in_array( self::FULL_ROUTE, $endpoints, true ) ) {\n            $endpoints[] = self::FULL_ROUTE;\n        }",
         "        if ( false ) {\n            $endpoints[] = self::FULL_ROUTE;\n        }")),
 "R2": ("the exemption is widened to the whole namespace", PSR,
        ("    public const FULL_ROUTE = '/' . self::NAMESPACE . self::ROUTE;",
         "    public const FULL_ROUTE = '/' . self::NAMESPACE;")),
 "R3": ("Plugin.php stops registering it — an unwired filter is a comment", PLUG,
        ("            [ Wp\\PatreonStandingRestController::class, 'exemptFromBuddyBossRestriction' ]",
         "            [ Wp\\PatreonStandingRestController::class, 'exemptFromNothing' ]")),
 "R4": ("the route's own secret check accepts anybody (condition 1)", PSR,
        ("        return $given !== '' && hash_equals( $expected, $given );",
         "        return true;")),
 "R5": ("the guard stops refusing an ACTIVE patron — THE MIKELLE CASE REOPENS", GUARD,
        ("        if ($standing === null || empty($standing['active'])) {\n            return null;\n        }",
         "        if ($standing === null || empty($standing['active']) || true) {\n            return null;\n        }")),
 # ── §10, Ian's ruling ──
 "I1": ("the linked address is NOT shown — Ian's ruling silently unimplemented", PS,
        ("        return 'Your Patreon membership is linked to the email address ' . $email",
         "        return '' . ( $email === $email ? '' : $email )\n             . 'Your Patreon membership is linked to the email address '")),
 "I2": ("*** THE ADDRESS LEAKS ACROSS THE SERVER-TO-SERVER BOUNDARY *** (keeper's rail)", PSR,
        ("            'manage_url' => $s['active'] ? PatreonStanding::manageUrl() : null,",
         "            'manage_url' => $s['active'] ? PatreonStanding::manageUrl() : null,\n            'patreon_email' => $s['patreon_email'] ?? null,")),
 "I3": ("the ANONYMOUS refusal copy starts carrying the address", PS,
        ("        return 'Your membership is already paid through Patreon, so buying here would charge you twice.'",
         "        return 'Your membership is already paid through Patreon (' . ( $standing['patreon_email'] ?? '' ) . '), so buying here would charge you twice.'")),
 "I4": ("an ABSENT address invents one instead of staying silent", PS,
        ("        if ( $email === '' ) {\n            return '';\n        }\n\n        return 'Your Patreon membership is linked",
         "        if ( $email === '' ) {\n            $email = 'your Patreon email';\n        }\n\n        return 'Your Patreon membership is linked")),
 "I5": ("the address stops being normalized, so a stored capital breaks the match", PS,
        ("            ? strtolower( trim( $standing['patreon_email'] ) )\n            : '';",
         "            ? (string) $standing['patreon_email']\n            : '';")),
 "I6": ("/manage-subscription/ stops rendering it", MS,
        ("$linked_email_sentence = $is_dual_payer", "$linked_email_sentence = false && $is_dual_payer")),
 "I7": ("its element loses the LIGHT rule, dark-only (mutation 20's lesson)", MSC,
        (".lg-manage-sub__dual-linked {\n    margin: .75rem 0 0;", ".lg-manage-sub__dual-linked-DISABLED {\n    margin: .75rem 0 0;")),
 "I8": ("/lgjoin/ stops rendering it on the switch surface", JOIN,
        ("            $lgLinkedSentence = lg_membership_linked_email_sentence( $patreonStanding['patreon_email'] ?? null );",
         "            $lgLinkedSentence = '';")),
 "I9": ("the two apps' wording FORKS — one member, two answers", CFG,
        ("    return 'Your Patreon membership is linked to the email address ' . $email",
         "    return 'Your Patreon account uses the email address ' . $email")),
 "I10": ("the WP door's user id comes from the BODY — an authenticated member could ask about a stranger", WPD,
        ("        $uid  = (int) get_current_user_id();", "        $uid  = (int) ( $req->get_param( 'uid' ) ?: get_current_user_id() );")),
 "I11": ("the mirrored reader stops selecting the address, so the member sees nothing", CFG,
        ("'SELECT patron_status, currently_entitled_amount_cents, tier_label, email",
         "'SELECT patron_status, currently_entitled_amount_cents, tier_label")),
 "I12": ("the poller's row stops selecting it, so every signed-in surface goes quiet", PS,
        ("                        tier_label, synced_at, email", "                        tier_label, synced_at")),
}
NOOPS = {
 "N1": ("a comment reword in the sentence's docblock", PS,
        ("     * THE LINKED PATREON ADDRESS, AND WHY IT IS ON THE SCREEN.",
         "     * The linked Patreon address, and why it is on the screen.")),
}

def run():
    p = subprocess.run(['php', str(GATE)], capture_output=True, text=True, timeout=900, cwd=str(ROOT))
    return p.returncode, p.stdout

snap = pathlib.Path(tempfile.mkdtemp(prefix='lg-75-'))
for f in FILES:
    shutil.copy2(f, snap / f.name)
def restore():
    for f in FILES:
        shutil.copy2(snap / f.name, f)
try:
    code, out = run()
    if code != 0:
        print("CANNOT RUN: gate not green at baseline"); print(out[-1500:]); sys.exit(3)
    print("baseline GREEN:", out.strip().splitlines()[-4])
    caught = 0
    for mid, (desc, path, (old, new)) in {**MUTS, **NOOPS}.items():
        s = path.read_text()
        if old not in s:
            print(f"  {mid}  ANCHOR LOST in {path.name}"); restore(); continue
        path.write_text(s.replace(old, new, 1))
        if path.suffix == '.php':
            lint = subprocess.run(['php','-l',str(path)], capture_output=True, text=True)
            if lint.returncode != 0:
                print(f"  {mid}  INVALID MUTATION — {lint.stdout.strip()[:90]}"); restore(); continue
        code, out = run()
        restore()
        reds = re.findall(r'FAIL\s+(\S+)', out)
        expect_red = mid in MUTS
        if expect_red and code != 0:
            caught += 1
            print(f"  RED   {mid}  [{','.join(reds[:4])}] {desc}")
        elif expect_red:
            print(f"  !! GREEN {mid}  {desc}   <-- BLIND SPOT")
        elif code == 0:
            print(f"  ok    {mid}  no-op stayed green — {desc}")
        else:
            print(f"  !! RED {mid}  A NO-OP TURNED IT RED — keying on prose")
    print(f"\n{caught}/{len(MUTS)} caught")
finally:
    restore()
    shutil.rmtree(snap, ignore_errors=True)
