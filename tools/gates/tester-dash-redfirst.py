#!/usr/bin/env python3
"""
RED-FIRST for GATE 90 (#190) — every assertion reddens for its OWN named reason.

    python3 tools/gates/tester-dash-redfirst.py

A gate that has never been seen RED is a gate nobody has tested. Each mutation
below breaks exactly one thing and must produce a FAIL on the assertion that
claims to watch it — not merely "the gate went red", which any typo achieves.

⚠️ SNAPSHOTS, NEVER `git checkout --`. Restoring from HEAD would wipe
uncommitted work under test and turn one harness bug into a run of false
verdicts (feedback-mutation-harness-must-snapshot-not-checkout). Every file is
copied to a per-run temp dir before the first mutation and restored from there.

⚠️ A MUTATION THAT DOES NOT APPLY IS AN ERROR, NOT A PASS. If the anchor text
has drifted, the harness stops and says so. Silently "mutating" nothing and
watching the gate stay green is how a harness reports a dead assertion as a
live one.

⚠️ NO-OP CONTROLS ARE PART OF THE EVIDENCE. If a comment reword reddens the
gate, the gate is measuring prose rather than behaviour — which this gate's own
F4/F5 did on their first run, matching their own warnings.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
REPO = os.path.dirname(REPO) if os.path.basename(REPO) == "tools" else REPO
REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

GATE = os.path.join(REPO, "tools", "gates", "tester-dash-gate.php")

STORE  = "lg-patreon-stripe-poller/src/TesterUnlock.php"
PANEL  = "lg-patreon-stripe-poller/src/TesterUnlockPanel.php"
READER = "lg-shared/tester-unlock.php"
ADMIN  = "lg-patreon-stripe-poller/src/Admin.php"

FILES = [STORE, PANEL, READER, ADMIN]

# (id, file, find, replace, assertion-id that MUST fail, what it simulates)
MUTATIONS = [
    # ---- the store -------------------------------------------------------
    ("M1", STORE,
     "if ( ! self::writeState( true, hash( 'sha256', $token ) ) ) {",
     "if ( ! self::writeState( true, '' ) ) {",
     "A8", "mint arms the box with an EMPTY hash — the link it hands out is dead"),

    ("M2", STORE,
     "'token_sha256' => $hash,",
     "'token_sha256' => $hash, 'token' => get_option( self::OPT_TOKEN, '' ),",
     "A15", "the raw token is written into the world-readable shared file"),

    ("M3", STORE,
     "if ( ! self::writeState( false, '' ) ) {",
     "if ( ! ( @unlink( self::statePath() ) || true ) ) {",
     "A18", "Clear DELETES the file instead of writing enabled:false — on a box "
            "with an armed .local.php that leaves it armed while the dash says off"),

    ("M4", STORE,
     "$token = bin2hex( random_bytes( self::TOKEN_BYTES ) );",
     "$token = get_option( self::OPT_TOKEN, '' ) ?: bin2hex( random_bytes( self::TOKEN_BYTES ) );",
     "A9a", "Rotate re-uses the existing token, so nothing actually rotates"),

    ("M5", STORE,
     "@chmod( $tmp, 0644 );",
     "@chmod( $tmp, 0600 );",
     "A16", "the store is 0600 — unreadable by the seven app users, so the "
            "unlock silently never works on any surface"),

    ("M6", STORE,
     "$agrees = ( $token !== '' && $stateHash !== '' && hash_equals( $stateHash, hash( 'sha256', $token ) ) );",
     "$agrees = ( $token !== '' );",
     "D6", "the panel believes its stored token matches whatever armed the box, "
           "so a foreign-armed box shows a dead link as live"),

    ("M7", STORE,
     "if ( $t === '' ) { return ''; }",
     "if ( $t === '' ) { return home_url( '/lgjoin/?lgtester=deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef' ); }",
     "A2", "url() invents a link when there is no token. (D2 correctly stays "
            "green: in the 'off' state the panel prints no link either way, so "
            "the watcher here is A2, not the panel.)"),

    ("M8", STORE,
     "return is_writable( $path ) || ( ! file_exists( $path ) && is_writable( dirname( $path ) ) );",
     "return true;",
     "D16", "an unwritable store is reported as writable, so nobody is told why "
            "Rotate will not work. (D18 correctly stays green: writeState still "
            "fails on the unwritable path, so mint refuses either way — which is "
            "the belt to D16's braces.)"),

    ("M12", READER,
     "    if ($forget) { $cache = null; }",
     "    if (false) { $cache = null; }",
     "D5c", "the cache is never dropped, so the dash cannot confirm its own "
            "write and reports a box it just armed as not armed"),

    # ---- the reader ------------------------------------------------------
    ("M9", READER,
     "    $apply(lg_tester_unlock_state());",
     "",
     "B1", "the operator store is not consulted at all"),

    ("M10", READER,
     "            $hash = preg_match('/^[a-f0-9]{64}$/', $h) === 1 ? $h : '';",
     "            $hash = $h;",
     "B4b", "a malformed hash leaves the box reading ARMED on something that can "
             "never match, so the panel tells Ian it is on when it is not"),

    ("M11", READER,
     "        if (array_key_exists('enabled', $cfg)) { $enabled = ($cfg['enabled'] === true); }",
     "        if (array_key_exists('enabled', $cfg) && $cfg['enabled'] === true) { $enabled = true; }",
     "B7b", "enabled:false can no longer turn anything OFF — it can only ever "
            "arm, so disabling a box that is armed by a .local.php stops working"),

    # ---- the panel -------------------------------------------------------
    ("M13", PANEL,
     "<?php if ( $u['mode'] === 'dash' ) : ?>",
     "<?php if ( $u['mode'] === 'dash' || $u['mode'] === 'foreign' ) : ?>",
     "D6", "the link panel renders for a foreign-armed box, printing a dead link"),

    ("M14", PANEL,
     "<?php if ( $breaks ) : ?>onclick=\"return confirm(<?php echo esc_attr( wp_json_encode( $confirm ) ); ?>);\"<?php endif; ?>",
     "",
     "D14", "Rotate no longer states the consequence before the click"),

    ("M15", PANEL,
     "<?php wp_nonce_field( 'lgms_tester_rotate' ); ?>",
     "",
     "D12", "the rotate form loses its nonce"),

    ("M16", PANEL,
     "<strong>The link is armed, but the header is <code>off</code>, so it currently does nothing.</strong>",
     "<strong>All good.</strong>",
     "D11", "the #170 pairing warning is gone, so an inert link reads as live"),

    ("M17", PANEL,
     "<strong>This box cannot store a tester link yet.</strong>",
     "<strong>Fine.</strong>",
     "D16", "an unwritable store is no longer reported to whoever is looking"),

    ("M23", PANEL,
     "There is no tester link on this box.",
     "A tester link is ready on this box.",
     "D1", "the 'no link' state claims there is one"),

    # ---- the handlers ----------------------------------------------------
    ("M18", ADMIN,
     "        check_admin_referer( 'lgms_tester_rotate' );",
     "",
     "E3a", "Rotate accepts a cross-site request"),

    ("M19", ADMIN,
     """        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_tester_clear' );""",
     "        check_admin_referer( 'lgms_tester_clear' );",
     "E2b", "Turn-it-off drops its capability check"),

    ("M20", ADMIN,
     "            $note . ( $had ? ' Every link sent before now has stopped working.' : '' )",
     "            $note . ' ' . $r['url']",
     "E5", "the raw token rides the redirect into the admin URL, browser "
           "history and every onward Referer"),

    ("M21", ADMIN,
     "        add_action( 'admin_post_lgms_tester_rotate', [ self::class, 'handleTesterRotate' ] );",
     "",
     "E4", "the rotate handler is never registered — a button that posts to nothing"),

    ("M22", ADMIN,
     "            admin_url( self::PARENT_FILE )\n        ) );\n        exit;\n    }\n\n    /** Step 1 of add",
     "            admin_url( 'options-general.php' )\n        ) );\n        exit;\n    }\n\n    /** Step 1 of add",
     "G8", "one redirect hardcodes the parent file behind the constant's back, "
           "so the move half-lands and that one link points at a page that no "
           "longer exists"),

    # ---- the menu move (#190, keeper's item 2) ---------------------------
    ("M24", ADMIN,
     "        add_menu_page(\n            'LG Member Sync',",
     "        add_options_page(\n            'LG Member Sync',",
     "G1", "the dash slides back under Settings — the one place Ian ruled against"),

    ("M25", ADMIN,
     "    private const PARENT_FILE = 'admin.php';",
     "    private const PARENT_FILE = 'options-general.php';",
     "G2", "every redirect lands on a Settings page that no longer exists"),

    ("M26", ADMIN,
     "        if ( $hook === 'toplevel_page_' . self::OPT_PAGE ) {",
     "        if ( $hook === 'settings_page_' . self::OPT_PAGE ) {",
     "G3", "the enqueue hook never fires again, so the Welcome Email tab's "
           "media uploader silently stops loading — no error anywhere"),

    ("M27", ADMIN,
     "        global $pagenow;\n        if ( $pagenow !== 'options-general.php' ) { return; }",
     "        return;\n        global $pagenow;\n        if ( $pagenow !== 'options-general.php' ) { return; }",
     "G6", "the old Settings URL is left dead, stranding every bookmark and "
           "every 'Settings -> LG Member Sync' in the docs"),

    ("M28", ADMIN,
     "        if ( $pagenow !== 'options-general.php' ) { return; }",
     "        if ( false ) { return; }",
     "G7", "the redirect fires on EVERY admin page, bouncing the whole of "
           "wp-admin into this dash"),

    # ---- the Affiliates fold (#190, keeper's item 3) ---------------------
    ("M29", ADMIN,
     "            // #190: absorbed from its own top-level menu. Same content, same\n            // handlers, one sidebar item.\n            'affiliates'    => 'Affiliates',\n",
     "",
     "G9c", "Affiliates is dropped instead of folded — the tab disappears and "
            "the payouts screen becomes unreachable from anywhere"),

    ("M30", ADMIN,
     "        if ( $pagenow !== 'admin.php' ) { return; }\n        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::AFF_PAGE ) { return; }",
     "        if ( true ) { return; }\n        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::AFF_PAGE ) { return; }",
     "G10", "the old Affiliates URL is left dead — seven inbound links, two of "
            "them member-facing, land on 'you are not allowed to access this page'"),

    ("M31", ADMIN,
     "        if ( ! isset( $_GET['page'] ) || $_GET['page'] !== self::AFF_PAGE ) { return; }",
     "        if ( false ) { return; }",
     "G11", "the affiliates redirect fires for every admin.php page"),

    ("M32", ADMIN,
     """        $extra = [];
        if ( isset( $_GET['lgms_edit_aff'] ) ) {
            $id = (int) $_GET['lgms_edit_aff'];
            if ( $id > 0 ) { $extra['lgms_edit_aff'] = $id; }
        }""",
     "        $extra = [];\n        if ( false ) {}",
     "G10b", "the redirect drops the row being edited, landing the operator on "
             "the list instead of the affiliate they clicked"),

    ("M33", ADMIN,
     "        self::renderAffiliatesTab();\n        self::renderPayoutsPanel();",
     "        self::renderAffiliatePage();",
     "G12", "the tab renders the standalone PAGE, nesting a second wrap and a "
            "second h1 inside render()'s own"),

    # ── §I — the Testers tab takes an ADDRESS (#193) ────────────────────────
    # Ian, 8/22: "I thought the whitelist would have them generating a wp-user
    # like a normal new member join." The DECIDER is red-firsted against gate 86
    # and the STORE against gate 34; these are aimed at the surface, because
    # "Ian cannot use this without the command line" was a charter constraint.

    ("M34", ADMIN,
     "            if ( $email !== \'\' ) {\n                self::cohortRedirect( [ \'lgms_cohort_confirm_email\' => rawurlencode( $email ) ] );\n            }",
     "            if ( false ) {\n                self::cohortRedirect( [ \'lgms_cohort_confirm_email\' => rawurlencode( $email ) ] );\n            }",
     "I6", "the lookup dead-ends on an address with no account again — Ian types "
           "a tester's email and is told to go and make them an account first, "
           "which is the whole of what #193 was opened to remove"),

    ("M35", ADMIN,
     "        add_action( \'admin_post_lgms_cohort_add_email\',    [ self::class, \'handleCohortAddEmail\' ] );",
     "        // add_action for add_email deliberately dropped",
     "I4", "the add-an-address handler is never registered — the confirm button "
           "posts into nothing and the address is silently not stored"),

    ("M36", ADMIN,
     "        check_admin_referer( \'lgms_cohort_remove_email_\' . $email );",
     "        check_admin_referer( \'lgms_cohort_remove_email\' );",
     "I5", "the remove nonce stops being bound to the address, so one valid page "
           "grants removal of any OTHER tester"),

    ("M37", ADMIN,
     "        if ( ( $existing = get_user_by( \'email\', $email ) ) instanceof \\WP_User ) {\n            if ( CohortAllowlist::add( (int) $existing->ID ) ) {",
     "        if ( false ) {\n            if ( CohortAllowlist::add( (int) $existing->ID ) ) {",
     "I7", "add-by-address stops re-checking for an account at write time, so a "
           "tester who signed up between the lookup and the click gets a second "
           "entry and the list can later disagree with itself"),

    ("M38", ADMIN,
     "            <?php foreach ( $emails as $addr ) :",
     "            <?php foreach ( [] as $addr ) :",
     "I8a", "the tab stops rendering the addresses — they are stored and invisible, "
            "which is #190's unreadable-store defect wearing a different hat"),

    ("M39", ADMIN,
     "<h3>Current cohort (<?php echo count( $ids ) + count( $emails ); ?>)</h3>",
     "<h3>Current cohort (<?php echo count( $ids ); ?>)</h3>",
     "I9", "the count goes back to the ids alone, so an addresses-only cohort "
           "reads as empty on the very tab that holds it"),

    ("M40", ADMIN,
     "            The test group only takes people who already have an account. This mints a",
     "            The test group only takes people who already have an account. This mints a",
     "I12", "PLACEHOLDER — replaced below"),
]

# M40 is written as a real reinstatement of the old, now-false sentence.
MUTATIONS[-1] = (
    "M40", ADMIN,
    "            <strong>Usually you do not need this any more.</strong> Since #193 the list below",
    "            The test group only takes people who already have an account. Since #193 the list below",
    "I12", "the invite panel reinstates its old claim that the list needs an "
           "existing account — a confidently-wrong sentence on the very tab that "
           "disproves it, which is how an operator concludes the feature is absent")

NOOPS = [
    ("N1", STORE,
     " * TesterUnlock — THE WRITE HALF of #180's anonymous tester link. Issue #190.",
     " * TesterUnlock — the write half of #180's anonymous tester link (issue #190).",
     "a comment reword in the store"),
    ("N2", PANEL,
     "     * THE TESTER LINK PANEL (#190) — the half of \"who can get in\" that had no",
     "     * The tester link panel (#190) — the half of \"who can get in\" that had no",
     "a comment reword in the panel"),
    # #193 — §I needs its own control, or a green run says nothing about whether
    # those assertions are keying on Admin.php's prose rather than its code.
    ("N3", ADMIN,
     "     * ADD AN ADDRESS (#193). Ian, 2026-08-22:",
     "     * Add an address (#193). Ian, 2026-08-22:",
     "a comment reword in the add-an-address handler"),
]


def run_gate():
    p = subprocess.run(["php", GATE], capture_output=True, text=True, cwd=REPO, timeout=900)
    return p.returncode, p.stdout + p.stderr


def failed_ids(out):
    """The assertion ids the gate reported as FAIL."""
    ids = set()
    for line in out.splitlines():
        m = re.match(r"\s*FAIL\s+([A-Z]\d+[a-z]?)\b", line)
        if m:
            ids.add(m.group(1))
    return ids


def main():
    snap = tempfile.mkdtemp(prefix="lg-g90-redfirst-")
    for f in FILES:
        dst = os.path.join(snap, f.replace("/", "__"))
        shutil.copy2(os.path.join(REPO, f), dst)

    def restore():
        for f in FILES:
            shutil.copy2(os.path.join(snap, f.replace("/", "__")), os.path.join(REPO, f))

    print("=" * 74)
    print("RED-FIRST — GATE 90")
    print("=" * 74)

    rc, out = run_gate()
    if rc != 0:
        print("\nBASELINE IS NOT GREEN — nothing below would mean anything.\n")
        print(out[-3000:])
        restore()
        shutil.rmtree(snap, ignore_errors=True)
        return 2
    base_n = re.search(r"GATE 90 GREEN — (\d+) assertions", out)
    print(f"\nbaseline GREEN ({base_n.group(1) if base_n else '?'} assertions)\n")

    good = bad = 0

    for mid, path, find, repl, want, why in MUTATIONS:
        full = os.path.join(REPO, path)
        src = open(full).read()
        if src.count(find) != 1:
            print(f"  ERROR {mid}: anchor appears {src.count(find)}x in {path} — cannot mutate. "
                  f"A mutation that does not apply is an ERROR, not a pass.")
            bad += 1
            restore()
            continue
        open(full, "w").write(src.replace(find, repl))

        rc, out = run_gate()
        fails = failed_ids(out)
        restore()

        if rc == 0:
            print(f"  MISS  {mid}  gate stayed GREEN — {want} does not actually watch this")
            print(f"        ({why})")
            bad += 1
        elif want not in fails:
            print(f"  WRONG {mid}  gate went red but {want} passed; red were: {sorted(fails)}")
            print(f"        ({why})")
            bad += 1
        else:
            print(f"  red   {mid}  {want} — {why}")
            good += 1

    print()
    for nid, path, find, repl, why in NOOPS:
        full = os.path.join(REPO, path)
        src = open(full).read()
        if src.count(find) != 1:
            print(f"  ERROR {nid}: anchor appears {src.count(find)}x in {path}")
            bad += 1
            restore()
            continue
        open(full, "w").write(src.replace(find, repl))
        rc, out = run_gate()
        restore()
        if rc == 0:
            print(f"  inert {nid}  {why} — gate stayed green, as it must")
            good += 1
        else:
            print(f"  NOISE {nid}  {why} — the gate REDDENED on a comment. It is measuring prose.")
            print("        " + " / ".join(sorted(failed_ids(out))))
            bad += 1

    shutil.rmtree(snap, ignore_errors=True)

    print()
    print("=" * 74)
    total = len(MUTATIONS) + len(NOOPS)
    print(f"RED-FIRST {good}/{total}" + ("" if bad == 0 else f"  — {bad} PROBLEM(S)"))
    print("=" * 74)
    return 0 if bad == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
