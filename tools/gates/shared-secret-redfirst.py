#!/usr/bin/env python3
"""
RED-FIRST for GATE 98 (#201) — every assertion reddens for its OWN named reason.

    python3 tools/gates/shared-secret-redfirst.py

A gate that has never been seen RED is a gate nobody has tested, and that goes
double for one whose subject is a SILENCE: `lgms_shared_secret` is absent on
live, #181's checkout guard is fail-open, and nothing anywhere said so.

Each mutation below breaks exactly one thing and must produce a FAIL on the
assertion that CLAIMS to watch it — not merely "the gate went red", which any
typo achieves.

⚠️ SNAPSHOTS, NEVER `git checkout --`. Restoring from HEAD would wipe
uncommitted work under test and turn one harness bug into a run of false
verdicts. Every file is copied to a per-run temp dir before the first mutation
and restored from there.

⚠️ A MUTATION THAT DOES NOT APPLY IS AN ERROR, NOT A PASS. If an anchor has
drifted the harness stops and says so. Silently mutating nothing and watching
the gate stay green is how a harness reports a dead assertion as a live one —
gate 79's caching-law leg had been inert since #180 for exactly that reason.

⚠️ THE MUTATIONS ARE WRONG, NOT BROKEN. A parse error or an arity mismatch kills
the run at exit 255 with no FAIL line and every remaining verdict is lost; #194
lost two mutations that way. Each replacement below is valid PHP that does the
wrong thing.

⚠️ N3 IS THE CONTROL THAT MATTERS. It puts the option name into a COMMENT in
Admin.php. §I2 asserts that file names the option nowhere — and this lane's own
change left a long comment there explaining the removal, so a grep-based
assertion would match the explanation and pass whether or not the field is gone.
N3 must stay GREEN, which is the proof §I2 reads tokens.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
GATE = os.path.join(REPO, "tools", "gates", "shared-secret-status-gate.php")

HEALTH = "lg-patreon-stripe-poller/src/Membership/Health.php"
PANEL  = "lg-patreon-stripe-poller/src/SharedSecretPanel.php"
HPANEL = "lg-patreon-stripe-poller/src/HealthPanel.php"
ADMIN  = "lg-patreon-stripe-poller/src/Admin.php"

FILES = [HEALTH, PANEL, HPANEL, ADMIN]

# ⚠️ M21 REPLACES THE WHOLE CATCH BLOCK, BRACES INCLUDED. Its first draft ended
# in a dangling `if ( false ) {`, which left the catch unterminated: PHP never
# parsed the file, the gate died with NO FAIL LINE, and the harness reported
# "went red but C3e passed". A mutation must be WRONG, never BROKEN.
CATCH_BODY = """        } catch ( Throwable $e ) {
            while ( ob_get_level() > $level ) {
                ob_end_clean();
            }
            /* The message is DISCARDED, not shortened and not logged to the
               screen. See ERROR_SENTENCE. */
            wp_send_json_error( [ 'message' => self::ERROR_SENTENCE ], 500 );
            return;
        }"""

CATCH_SHIPS_PARTIAL = """        } catch ( Throwable $e ) {
            $partial = '';
            while ( ob_get_level() > $level ) { $partial .= (string) ob_get_clean(); }
            wp_send_json_error( [ 'message' => self::ERROR_SENTENCE, 'html' => $partial ], 500 );
            return;
        }"""

# (id, file, find, replace, assertion-id that MUST fail, what it simulates)
MUTATIONS = [
    # ---- A. the four states of the half we cannot read --------------------
    ("M1", HEALTH,
     "'missing'    => 'cannot see it — there is no settings file at that path',",
     "'missing'    => 'cannot see it — the settings file could not be used',",
     "A1", "an absent settings file and an unusable one become the same sentence"),

    ("M2", HEALTH,
     "if ( ! is_file( $path ) ) {",
     "if ( false ) {",
     "A2", "a DIRECTORY at that path falls through and reads as a truncated file"),

    ("M3", HEALTH,
     "'unreadable' => 'cannot see it — the file is there and WordPress may not read it',",
     "'unreadable' => 'cannot see it — not available',",
     "A3", "a permissions problem stops naming itself, so nobody calls root"),

    ("M4", HEALTH,
     "'empty'      => 'cannot see it — the settings file parsed to nothing at all',",
     "'empty'      => 'cannot see it — NOT SET',",
     "A4", "an unparseable file is reported as a key nobody entered — opposite fixes"),

    ("M5", HEALTH,
     "            'verdict'   => 'cannot_compare',\n            'status'    => 'unknown',",
     "            'verdict'   => 'cannot_compare',\n            'status'    => 'ok',",
     "A5", "a question that could not be answered renders as a green tick"),

    ("M6", HEALTH,
     "default         => [ 'CANNOT COMPARE',",
     "default         => [ 'MATCH',",
     "A5b", "the headline reassures on the exact question that went unanswered"),

    ("M7", HEALTH,
     "        if ( $env['state'] !== 'ok' ) {\n            $out['line'] =",
     "        if ( $env['state'] !== 'ok' ) {\n            $out['wp'] = [ 'present' => false, 'len' => 0 ];\n            $out['line'] =",
     "A5c", "one half being unreadable throws away the half we CAN see"),

    # ---- B. the verdicts --------------------------------------------------
    ("M8", HEALTH,
     "'match'         => [ 'MATCH',",
     "'match'         => [ 'OK',",
     "B1", "the healthy verdict stops saying the two halves MATCH"),

    ("M9", HEALTH,
     "$agree          = hash_equals( (string) $appFact['sha'], hash( 'sha256', $wp ) );",
     "$agree          = ( $appFact['sha'] !== '' );",
     "B2", "any two present values are declared equal — a rotation that landed on one side passes"),

    ("M10", HEALTH,
     "'wp_missing'    => [ 'NOT SET in WordPress',",
     "'wp_missing'    => [ 'NOT SET',",
     "B3", "live's own shape stops naming WHICH half, sending the reader to the wrong file"),

    ("M11", HEALTH,
     "This is the state live is in, and it is why the checkout guard answers UNKNOWN instead of refusing anybody.",
     "This should be corrected.",
     "B3b", "the screen stops saying why an absent half matters"),

    ("M12", HEALTH,
     "'app_missing'   => [ 'NOT SET in the billing app',",
     "'app_missing'   => [ 'NOT SET',",
     "B4", "the billing app's absent half stops naming the billing app"),

    ("M13", HEALTH,
     "'both_missing'  => [ 'NOT SET anywhere',",
     "'both_missing'  => [ 'NOT SET',",
     "B5", "absent everywhere reads the same as absent on one side"),

    ("M14", HEALTH,
     "        $wpLine = $pair['wp']['present']\n            ? 'set — ' . $pair['wp']['len'] . ' characters'",
     "        $wpLine = $pair['wp']['present']\n            ? 'set'",
     "B6", "the WordPress row stops reporting its length — the one thing a secret may say about itself"),

    ("M15", HEALTH,
     "            $pair['app']['present']   => 'set — ' . $pair['app']['len'] . ' characters',",
     "            $pair['app']['present']   => 'set',",
     "B6b", "the billing app row stops reporting its length, and a truncated half stops being visible"),

    ("M15b", HEALTH,
     "        [ $headline, $summary ] = match ( $pair['verdict'] ) {",
     "        $pair['verdict'] = $pair['verdict'] === 'differ' ? 'match' : $pair['verdict'];\n        [ $headline, $summary ] = match ( $pair['verdict'] ) {",
     "B7", "a TRUNCATED half is waved through as a match"),

    ("M16", HEALTH,
     "self::line( 'Do they match?', $headline, $pair['status'] ),",
     "self::line( 'Status', $headline, $pair['status'] ),",
     "B8", "the verdict row is renamed and the itemised three-row shape is lost"),

    # ---- C. the value reaches nothing ------------------------------------
    ("M17", HEALTH,
     "'checked_at' => gmdate( 'H:i:s' ) . ' UTC',",
     "'checked_at' => gmdate( 'H:i:s' ) . ' UTC · ' . hash( 'sha256', (string) get_option( 'lgms_shared_secret', '' ) ),",
     "C1", "a sha256 of the secret rides along on the timestamp"),

    ("M18", PANEL,
     "<p class=\"lgms-ss-sum\"><?php echo esc_html( (string) $s['summary'] ); ?></p>",
     "<p class=\"lgms-ss-sum\"><?php echo esc_html( (string) $s['summary'] . ' ' . (string) get_option( 'lgms_shared_secret', '' ) ); ?></p>",
     "C1", "the renderer fetches the value itself and prints it"),

    ("M19", HEALTH,
     "'differ'        => [ 'DIFFER', 'The two halves hold DIFFERENT values, so every server-to-server call fails closed. A rotation that landed on one side only looks exactly like this.' ],",
     "'differ'        => [ 'DIFFER', 'The two halves hold DIFFERENT values: WordPress has ' . get_option( 'lgms_shared_secret', '' ) . '.' ],",
     "C4", "ONE broken state leaks while the healthy render stays clean — the leak that happens on the worst day"),

    ("M20", PANEL,
     "wp_send_json_error( [ 'message' => self::ERROR_SENTENCE ], 500 );",
     "wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );",
     "C3b", "the exception's own message is echoed, and it was holding the secret"),

    ("M21", PANEL,
     CATCH_BODY,
     CATCH_SHIPS_PARTIAL,
     "C3e", "a render that died half way through ships its fragment to the browser"),

    # ---- D. no input field ------------------------------------------------
    ("M22", PANEL,
     '<button type="button" class="button" data-lgms-ss="refresh">Refresh</button>',
     '<input type="submit" class="button" data-lgms-ss="refresh" value="Refresh">',
     "D1", "an <input> appears in a section whose whole shape is that it has none"),

    ("M23", PANEL,
     '<button type="button" class="button" data-lgms-ss="copy" data-target="lgms-ss-cmd-wp">Copy</button>',
     '<input type="password" name="lgms_shared_secret" value="" class="regular-text">',
     "D2", "the retired paste-in field grows back on the new screen"),

    # ---- E. one renderer --------------------------------------------------
    ("M24", PANEL,
     "            self::renderBody( self::refreshRead() );",
     "            $r = self::refreshRead(); echo '<div class=\"lgms-ss\"><table class=\"lgms-ss-tbl\"><tr><td>' . esc_html( (string) $r['headline'] ) . '</td></tr></table></div>';",
     "E2", "the refresh grows a SECOND renderer — a second place to leak and a second thing to gate"),

    # ---- F. both locks ----------------------------------------------------
    ("M25", PANEL,
     "        if ( ! current_user_can( 'manage_options' ) ) {",
     "        if ( false ) {",
     "F1", "any logged-in subscriber can read the fingerprint comparison"),

    ("M26", PANEL,
     "        check_ajax_referer( self::NONCE );",
     "        /* check_ajax_referer( self::NONCE ); */",
     "F2", "another origin can drive an admin's browser into pressing Refresh"),

    ("M27", PANEL,
     "            wp_send_json_error( [ 'message' => self::DENIED_SENTENCE ], 403 );\n            return;",
     "            ob_start(); self::renderBody( Health::sharedSecret() ); $leak = (string) ob_get_clean();\n            wp_send_json_error( [ 'message' => self::DENIED_SENTENCE, 'html' => $leak ], 403 );\n            return;",
     "F1b", "the refusal renders the panel anyway — a refusal that answers is not a refusal"),

    ("M28", PANEL,
     "        add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handleStatus' ] );",
     "        add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handleStatus' ] );\n        add_action( 'wp_ajax_nopriv_' . self::ACTION, [ self::class, 'handleStatus' ] );",
     "F5", "an anonymous door is opened onto the status check"),

    # ---- G. the refresh is a real re-read ---------------------------------
    ("M29", PANEL,
     "        wp_cache_delete( 'lgms_shared_secret', 'options' );\n",
     "",
     "G1", "Refresh answers out of the option cache — the button becomes a lie told convincingly"),

    ("M30", PANEL,
     "        wp_cache_delete( 'alloptions', 'options' );\n",
     "",
     "G2b", "the autoloaded blob still answers, so the single-key delete achieved nothing — and this option IS autoloaded"),

    ("M31", PANEL,
     "        Health::reset();\n",
     "",
     "G3", "Refresh re-reports the settings file as it was when the page loaded"),

    ("M32", HEALTH,
     "'checked_at' => gmdate( 'H:i:s' ) . ' UTC',",
     "'checked_at' => date( 'H:i:s' ) . ' UTC',",
     "G5", "the stamp follows the process timezone — #183's four-hour error, one screen over"),

    ("M33", PANEL,
     '<span class="lgms-ss-stamp">Checked at <?php echo esc_html( (string) $s[\'checked_at\'] ); ?></span>',
     '<span class="lgms-ss-stamp"><?php echo esc_html( (string) $s[\'checked_at\'] ); ?></span>',
     "G4b", "the stamp loses its label, so a refreshed section cannot be told from a stale one"),

    # ---- H. it says setting is a command-line act -------------------------
    ("M34", PANEL,
     "<strong>Setting this is a command-line act, on purpose — there is no field for it here or\n                on any other tab.</strong>",
     "<strong>This value is managed elsewhere.</strong>",
     "H1", "the screen stops saying how the thing it reports on is actually set"),

    ("M35", PANEL,
     "                . \" option update lgms_shared_secret '<the-new-value>'\";",
     "                . \" option update lgms_secret '<the-new-value>'\";",
     "H3", "the runbook line names an option that does not exist"),

    ("M36", PANEL,
     "$wpCmd   = 'wp' . ( $wpPath !== '' ? ' --path=' . $wpPath : '' )",
     "$wpCmd   = 'wp' . ( $wpPath !== '' ? '' : '' )",
     "H5", "the command drops --path and is wrong on a box with more than one site"),

    ("M37", PANEL,
     "option update lgms_shared_secret '<the-new-value>'",
     "option update lgms_shared_secret '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'",
     "H6", "a value-shaped example is printed, which is a secret-shaped string on a secrets screen"),

    # ---- I. the wiring ----------------------------------------------------
    # ---- the three defects the PICTURE found, not the gate --------------
    ("M37b", PANEL,
     """        $appCmd  = 'sudoedit ' . $envPath . "\\n"
                 . '# set LGMS_SHARED_SECRET= to the SAME value. The app re-reads this'""",
     """        $appCmd  = 'sudoedit ' . $envPath . ' '
                 . '# set LGMS_SHARED_SECRET= to the SAME value. The app re-reads this'""",
     "H4b", "the two halves of the app command rejoin into one line running off the edge"),

    ("M37c", PANEL,
     "                 . \" file on every request — nothing to restart.\";",
     "                 . \" file, then: sudo systemctl reload php8.3-fpm\";",
     "H4c", "the screen asks for a restart the billing app does not need"),

    ("M37d", PANEL,
     "            .lgms-ss .lgms-h-ok      { background:#dcfce7; color:#15803d; }",
     "            /* .lgms-ss .lgms-h-ok   { background:#dcfce7; color:#15803d; } */",
     "H8", "the chips lose their colours off the Health tab and every verdict reads as plain grey text"),

    ("M38", ADMIN,
     "        SharedSecretPanel::boot();",
     "        /* SharedSecretPanel::boot(); */",
     "I1", "the refresh door is never registered and the button 400s in silence"),

    ("M39", ADMIN,
     "            'lgms_stripe_secret_key'          => '',\n            /* ⚠️ `lgms_shared_secret` IS DELIBERATELY NOT HERE",
     "            'lgms_stripe_secret_key'          => '',\n            'lgms_shared_secret'              => '',\n            /* ⚠️ `lgms_shared_secret` IS DELIBERATELY NOT HERE",
     "I2b", "THE DANGEROUS ONE: the setting is registered with no field, so options.php blanks the secret on every Save"),

    ("M40", HPANEL,
     "        SharedSecretPanel::render( $shared );",
     "        /* SharedSecretPanel::render( $shared ); */",
     "I4", "the section is built and never placed on the tab"),

    ("M41", HPANEL,
     "$worst  = Health::worst( array_merge( $checks, [ $shared ] ) );",
     "$worst  = Health::worst( $checks );",
     "I5", "the tab headline says healthy while its first card says DIFFER"),

    ("M42", HEALTH,
     "            'shared_secret' => self::sharedSecret(),\n",
     "",
     "I6", "describe() stops carrying the reading, so the tab cannot render or rank it"),

    ("M43", HEALTH,
     "            [ 'Stripe webhook secret', 'lgms_stripe_webhook_secret', 'STRIPE_WEBHOOK_SECRET' ],",
     "            [ 'Stripe webhook secret', 'lgms_stripe_webhook_secret', 'STRIPE_WEBHOOK_SECRET' ],\n            [ 'Shared secret', 'lgms_shared_secret', 'LGMS_SHARED_SECRET' ],",
     "I8b", "the shared secret is reported twice on one screen — #199's two stacked panels"),

    # ---- J. the panel decides nothing -------------------------------------
    ("M44", PANEL,
     "        $s = $status ?? Health::sharedSecret();",
     "        $s = $status ?? Health::sharedSecret();\n        $agree = hash_equals( 'a', 'a' );",
     "J3", "a SECOND definition of \"do the halves agree\" appears in the renderer"),
]

# Comment-only edits. If ANY of these reddens the gate, the gate is grading
# prose rather than behaviour.
NOOPS = [
    ("N1", HEALTH,
     "     * THE SHARED SECRET, ON ITS OWN, WITH A TIMESTAMP. Issue #201.",
     "     * THE SHARED SECRET, ALONE, WITH A TIMESTAMP. Issue #201. (reworded control)",
     "rewording the reader's docblock"),

    ("N2", PANEL,
     " * ⚠️ (4) NO INPUT. NOT ONE, ANYWHERE IN THIS SECTION.",
     " * ⚠️ (4) NO INPUT FIELDS ANYWHERE IN THIS SECTION. (reworded control)",
     "rewording the panel's docblock"),

    # ⚠️ THE ONE THAT MATTERS. §I2 asserts Admin.php names the option in no
    # string — and this lane left a comment there that names it. A grep-based
    # assertion matches the explanation of the removal and passes either way.
    ("N3", ADMIN,
     "    public static function menu(): void",
     "    /* control: lgms_shared_secret is named here, in a COMMENT, and §I2 must not care */\n    public static function menu(): void",
     "naming the option in a fresh COMMENT — the proof §I2 reads tokens, not text"),
]


def run_gate():
    p = subprocess.run(["php", GATE], capture_output=True, text=True, cwd=REPO)
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
    snap = tempfile.mkdtemp(prefix="lg-rf98-")

    def restore():
        for rel in FILES:
            shutil.copy2(os.path.join(snap, rel.replace("/", "__")), os.path.join(REPO, rel))

    for rel in FILES:
        shutil.copy2(os.path.join(REPO, rel), os.path.join(snap, rel.replace("/", "__")))

    print(__doc__.strip())
    print()

    rc, out = run_gate()
    if rc != 0:
        print("\nBASELINE IS NOT GREEN — nothing below would mean anything.\n")
        print(out[-3000:])
        restore()
        shutil.rmtree(snap, ignore_errors=True)
        return 2
    n = re.search(r"(\d+) passed", out)
    print(f"baseline GREEN ({n.group(1) if n else '?'} assertions)\n")

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
