#!/usr/bin/env python3
"""
RED-FIRST for GATE 91 (#192) — every assertion reddens for its OWN named reason.

    python3 tools/gates/membership-health-redfirst.py

A gate that has never been seen RED is a gate nobody has tested — and that is
doubly true of a HEALTH panel, whose entire job is to be right when things are
broken. Keeper, 2026-08-21: "prove every answer against a DELIBERATELY BROKEN
state, not just a healthy one ... a panel that only ever goes green is worth
nothing."

Each mutation below breaks exactly one thing and must produce a FAIL on the
assertion that CLAIMS to watch it — not merely "the gate went red", which any
typo achieves.

⚠️ SNAPSHOTS, NEVER `git checkout --`. Restoring from HEAD would wipe
uncommitted work under test and turn one harness bug into a run of false
verdicts (feedback-mutation-harness-must-snapshot-not-checkout). Every file is
copied to a per-run temp dir before the first mutation and restored from there.

⚠️ A MUTATION THAT DOES NOT APPLY IS AN ERROR, NOT A PASS. If the anchor text
has drifted, the harness stops and says so. Silently "mutating" nothing and
watching the gate stay green is how a harness reports a dead assertion as a
live one.

⚠️ NO-OP CONTROLS ARE PART OF THE EVIDENCE. If rewording a comment reddens the
gate, the gate is measuring prose rather than behaviour — which gate 90's own
F4/F5 did on their first run, matching their own warnings. Every source check
in gate 91 runs through PHP's tokenizer for exactly that reason.
"""

import os
import re
import shutil
import subprocess
import sys
import tempfile

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
GATE = os.path.join(REPO, "tools", "gates", "membership-health-gate.php")

HEALTH = "lg-patreon-stripe-poller/src/Membership/Health.php"
PANEL  = "lg-patreon-stripe-poller/src/HealthPanel.php"
ADMIN  = "lg-patreon-stripe-poller/src/Admin.php"
WHCTL  = "lg-stripe-billing/src/Http/Controllers/WebhookController.php"
RECPT  = "lg-stripe-billing/src/Core/WebhookReceipts.php"

FILES = [HEALTH, PANEL, ADMIN, WHCTL, RECPT]

RECORD_VERIFIED = """        WebhookReceipts::recordVerified(
            $this->pdo,
            (string) $event->type,
            (string) ($event->id ?? '')
        );
"""

# (id, file, find, replace, assertion-id that MUST fail, what it simulates)
MUTATIONS = [
    # ---- A. the settings file's four states ------------------------------
    ("M1", HEALTH,
     "$out['state']  = 'missing';",
     "$out['state']  = 'empty';",
     "A1", "an absent settings file is reported as an empty one — the wrong fix entirely"),

    ("M2", HEALTH,
     "if ( ! is_file( $path ) ) {",
     "if ( false ) {",
     "A2", "a DIRECTORY at that path falls through and reads as a truncated file"),

    ("M3", HEALTH,
     "$out['reason'] = 'that path exists but is not a file';",
     "$out['reason'] = '';",
     "A2b", "the panel knows it is not a file and declines to say so"),

    ("M4", HEALTH,
     "                $worst = self::worseOf( $worst, 'unknown' );\n                continue;",
     "                continue;",
     "A5", "an unreadable settings file leaves the secrets question looking HEALTHY"),

    ("M5", HEALTH,
     "                'mode', 'Test or live mode?', 'unknown',",
     "                'mode', 'Test or live mode?', 'ok',",
     "A5b", "a mode nobody could read is reported as fine"),

    ("M6", HEALTH,
     "'AGREE — both set, ' . strlen( $wp ) . ' characters'",
     "'AGREE — ' . $appFact['sha']",
     "A6", "a sha256 of the secret is printed on the screen"),

    ("M7", HEALTH,
     "$lines[] = self::line( 'WordPress Stripe key', self::modeWords( $wpMode ), $wpMode === 'unknown' ? 'warn' : 'neutral' );",
     "$lines[] = self::line( 'WordPress Stripe key', (string) get_option( 'lgms_stripe_secret_key', '' ), 'neutral' );",
     "A6b", "the live Stripe secret key itself is handed to the renderer"),

    # ---- B. do the two halves agree --------------------------------------
    ("M8", HEALTH,
     "$agree = hash_equals( $appFact['sha'], hash( 'sha256', $wp ) );",
     "$agree = true;",
     "B2", "two DIFFERENT secrets are reported as agreeing — failure #2, undetected"),

    ("M9", HEALTH,
     "'billing app: set (' . $appFact['len'] . ' characters) · WordPress: NOT SET'",
     "'not configured'",
     "B3", "live's exact shape today is reported without naming WHICH half is missing"),

    ("M10", HEALTH,
     "'WordPress: set (' . strlen( $wp ) . ' characters) · billing app: NOT SET'",
     "'not configured'",
     "B4", "the mirror case is equally anonymous"),

    ("M11", HEALTH,
     """                $lines[]  = self::line( $label, 'NOT SET on either side', 'fail' );
                $issues[] = $label . ' is missing everywhere';
                $worst    = self::worseOf( $worst, 'fail' );
                continue;""",
     """                $lines[]  = self::line( $label, 'NOT SET on either side', 'ok' );
                continue;""",
     "B5", "a secret absent on BOTH sides is scored as healthy"),

    ("M12", HEALTH,
     "        foreach ( $pairs as [ $label, $opt, $key, $why ] ) {",
     "        foreach ( $pairs as [ $label, $opt, $key, $why ] ) {\n            if ( $label !== 'Shared secret' ) { continue; }",
     "B6", "only the first pair is judged, so a broken webhook secret is invisible"),

    ("M13", HEALTH,
     "                    ? 'AGREE — both set, ' . strlen( $wp ) . ' characters'",
     "                    ? 'AGREE'",
     "B7", "the length — the most a secret may say about itself — is dropped"),

    # ---- C. test or live --------------------------------------------------
    ("M14", HEALTH,
     "if ( $wpMode !== 'unknown' && $appKeyMode !== 'unknown' && $wpMode !== $appKeyMode ) {",
     "if ( false ) {",
     "C3", "one half in TEST and the other in LIVE passes silently"),

    ("M15", HEALTH,
     "if ( $declared !== '' && $appKeyMode !== 'unknown' && $declared !== $appKeyMode ) {",
     "if ( false ) {",
     "C4", "STRIPE_MODE disagreeing with the key it holds is not noticed"),

    ("M16", HEALTH,
     "        if ( $debugOn ) {\n            $status   = 'fail';",
     "        if ( false ) {\n            $status   = 'fail';",
     "C5", "APP_DEBUG left on in production is not reported"),

    ("M17", HEALTH,
     "        if ( $debugOn ) {\n            $status   = 'fail';\n            $issues[] = 'APP_DEBUG is on, which displays errors to visitors';",
     "        if ( $appEnvLabel === 'dev' ) {\n            $status   = 'fail';\n            $issues[] = 'APP_DEBUG is on, which displays errors to visitors';",
     "C6", "APP_ENV — a label that gates nothing — is scored as a defect"),

    # ---- D. does the catalogue resolve to tiers ---------------------------
    ("M18", HEALTH,
     "        if ( $unmapped > 0 ) {",
     "        if ( false ) {",
     "D3", "a product with no tier ref grants nothing and nobody is told"),

    ("M19", HEALTH,
     "        } elseif ( $memb === 0 ) {",
     "        } elseif ( false ) {",
     "D1", "an empty catalogue is reported as a healthy one"),

    ("M20", HEALTH,
     "$missingPrice = array_values( array_diff( $tiers, $configured ) );",
     "$missingPrice = [];",
     "D4", "a tier registered with no price is silently not offered"),

    ("M21", HEALTH,
     "            return self::check( 'catalogue', 'Does the catalogue resolve to tiers?', 'unknown',",
     "            return self::check( 'catalogue', 'Does the catalogue resolve to tiers?', 'ok',",
     "D5", "an unreachable database is reported as a healthy catalogue"),

    # ---- E. who may buy ---------------------------------------------------
    ("M22", HEALTH,
     "if ( $state === CheckoutAudience::ALLOWLIST && $n === 0 ) {",
     "if ( false ) {",
     "E2", "live's empty cohort — nobody at all can buy — is scored healthy"),

    ("M23", HEALTH,
     "if ( $state === CheckoutAudience::ALLOWLIST && $n > 0 && ! $testerPagesOn && ! $pagesLiveOn ) {",
     "if ( false ) {",
     "E3", "#165's shape returns: wired perfectly, lands nowhere, nothing says so"),

    ("M24", HEALTH,
     "if ( $state === CheckoutAudience::ON && ! $pagesLiveOn ) {",
     "if ( false ) {",
     "E4", "everybody may buy and the join page is still pre-launch"),

    ("M25", HEALTH,
     "$cohort = CohortAllowlist::ids();",
     "$cohort = (array) get_option( 'lgms_stripe_lifecycle_allowlist', [] );",
     "E7", "the panel grows a SECOND definition of the cohort, free to drift"),

    # ---- #193: a cohort of ADDRESSES is not an empty cohort ---------------
    ("M25b", HEALTH,
     "        $n      = CohortAllowlist::count();",
     "        $n      = count( $cohort );",
     "E2b", "the panel counts only the member ids again, so a cohort holding "
            "nothing but tester ADDRESSES is reported EMPTY — nobody at all can "
            "buy — on the very day it starts working"),

    ("M25c", HEALTH,
     "        if ( $addresses > 0 ) {\n            $s .= ' + ' . $addresses . ' address(es)';\n        }",
     "        if ( false ) {\n            $s .= ' + ' . $addresses . ' address(es)';\n        }",
     "E2d", "the panel stops distinguishing an ADDRESS still waiting on its "
            "tester from a MEMBER who has already joined"),

    # ---- F. can the two halves reach each other ---------------------------
    ("M26", HEALTH,
     "} elseif ( $syncHost !== '' && strcasecmp( $syncHost, $ourHost ) !== 0 ) {",
     "} elseif ( false ) {",
     "F2", "FAILURE #1 EXACTLY — the app points at another host and nothing says so"),

    ("M27", HEALTH,
     "$resolves = self::hostResolves( $syncHost );",
     "$resolves = true;",
     "F2b", "a host that does not exist is reported as a mere mismatch"),

    ("M28", HEALTH,
     "if ( $bbPrivate && ! $exempted ) {",
     "if ( false ) {",
     "F4", "BuddyBoss restricting REST with nothing exempted is not reported"),

    ("M29", HEALTH,
     "if ( str_contains( $body, 'bb_rest_authorization_required' ) ) {",
     "if ( false ) {",
     "F5", "FAILURE #3 — BuddyBoss answers instead of us and it is not named"),

    ("M30", HEALTH,
     "                'status' => 'unknown',\n                'words'  => 'could not reach the site over loopback (",
     "                'status' => 'ok',\n                'words'  => 'could not reach the site over loopback (",
     "F7", "a probe that could not run is reported as a healthy channel"),

    ("M31", HEALTH,
     "'resolve' => $host !== '' ? [ \"{$host}:{$port}:127.0.0.1\" ] : [],",
     "'resolve' => [],",
     "F9", "the loopback pin is dropped, so the probe leaves the box and Cloudflare 403s it"),

    ("M32", HEALTH,
     "            'timeout' => 3,",
     "            'timeout' => 30,",
     "F9c", "a dead channel hangs the admin page for half a minute"),

    ("M32b", HEALTH,
     "'headers' => [ 'Content-Type: application/json', 'X-LGMS-Token: ' . $secret ],",
     "'headers' => [ 'Content-Type: application/json' ],",
     "F9d", "the probe stops authenticating, so a 200 proves nothing about the channel"),

    ("M32c", HEALTH,
     "            CURLOPT_RESOLVE        => $opts['resolve'],",
     "            CURLOPT_URL            => $url,",
     "F9e", "the transport abandons the plugin's documented CURLOPT_RESOLVE convention"),

    # ---- G. are webhooks arriving -----------------------------------------
    ("M33", HEALTH,
     "            } elseif ( $soldSince > 0 ) {",
     "            } elseif ( false ) {",
     "G2", "a sale AFTER recording started with no receipt is only a shrug"),

    ("M33b", HEALTH,
     "            } elseif ( $soldEver > 0 ) {",
     "            } elseif ( false ) {",
     "G2b", "history is reported as if nothing had ever been sold — a different wrong answer"),

    ("M33c", HEALTH,
     "            if ( $soldSince === null ) {",
     "            if ( false ) {",
     "G2d", "with no recorder on the box the panel guesses instead of saying it cannot tell"),

    ("M33d", HEALTH,
     "$lines[] = self::line( 'Customers + subscriptions on this box', (string) $soldEver, 'neutral' );",
     "$lines[] = self::line( 'Customers + subscriptions on this box', '0', 'neutral' );",
     "G2c", "the sales count is hidden, so the reader cannot sanity-check the verdict"),

    ("M34", HEALTH,
     "$lines[] = self::line( 'Last verified webhook', 'Never', $status === 'fail' ? 'fail' : 'warn' );",
     "$lines[] = self::line( 'Last verified webhook', '', $status === 'fail' ? 'fail' : 'warn' );",
     "G7", "a BLANK CELL where 'Never' belongs — and a blank cell reads like health"),

    ("M35", HEALTH,
     "        return (int) round( $age / 86400 ) . ' days ago';",
     "        return $utc;",
     "G4", "'arrived long ago' becomes a raw timestamp nobody subtracts in their head"),

    ("M36", HEALTH,
     "$stale   = $age === null || $age > self::STALE_AFTER;",
     "$stale   = false;",
     "G4b", "a webhook from a month ago reads as current, and 'nothing since' vanishes"),

    ("M37", HEALTH,
     "        if ( $badN > 0 ) {",
     "        if ( false ) {",
     "G5", "Stripe is reaching us and we reject every event — reported as healthy"),

    ("M38", HEALTH,
     "                'Are webhooks arriving?',\n                'unknown',",
     "                'Are webhooks arriving?',\n                'ok',",
     "G6", "a database nobody could read is reported as webhooks arriving fine"),

    ("M39", HEALTH,
     "$summary = 'NEVER \u2014 but nothing has ever been sold on this box, so that is expected.';",
     "$summary = 'NEVER.';",
     "G1b", "'never' on a quiet box loses the sentence that stops somebody chasing it"),

    # ---- H. the wiring ----------------------------------------------------
    ("M40", ADMIN,
     "            'health'        => 'Health',",
     "            'healthz'       => 'Health',",
     "H1", "the tab is registered under a slug nothing dispatches"),

    ("M41", ADMIN,
     "                'health'        => HealthPanel::render(),",
     "                'health'        => self::renderSettingsTab(),",
     "H2", "the Health tab quietly renders the Settings tab"),

    ("M42", PANEL,
     '<h2 style="margin-top:0;">Health</h2>',
     '<h2 style="margin-top:0;">Health</h2><form method="post" action="">',
     "H4", "a form appears on a screen Ian ruled read-only"),

    ("M43", WHCTL,
     RECORD_VERIFIED,
     "",
     "H7", "nothing records a verified webhook — question one loses its data source"),

    ("M44", WHCTL,
     "WebhookReceipts::recordSignatureFailure($this->pdo, false);",
     "",
     "H8", "'no webhook secret at all' stops being recorded, the exact live shape"),

    ("M45", WHCTL,
     RECORD_VERIFIED + "\n        $obj = $event->data->object;",
     "        $obj = $event->data->object;\n\n" + RECORD_VERIFIED,
     "H9", "the receipt moves after dispatch, so a throwing handler erases the evidence"),

    ("M46", RECPT,
     "if ($t !== false && (time() - $t) < self::FAIL_THROTTLE_SECONDS) {",
     "if ($t !== false && (time() - $t) < 0) {",
     "H10", "an unauthenticated endpoint becomes a table anyone on the internet can fill"),

    ("M47", RECPT,
     "        } catch (Throwable $e) {",
     "        } catch (\\RuntimeException $e) {",
     "H11", "a receipt failure escapes and turns a delivered webhook into a 3-day retry"),

    ("M48", RECPT,
     "':details' => json_encode($details, JSON_UNESCAPED_SLASHES),",
     "':details' => json_encode($details + ['payload' => $payload ?? ''], JSON_UNESCAPED_SLASHES),",
     "H12", "customer emails and card metadata land in a diagnostics table"),

    ("M49", HEALTH,
     "$rank = [ 'ok' => 0, 'warn' => 1, 'unknown' => 2, 'fail' => 3 ];",
     "$rank = [ 'ok' => 0, 'unknown' => 1, 'warn' => 2, 'fail' => 3 ];",
     "H13", "the headline reassures about the one question that went unanswered"),

    ("M50", HEALTH,
     "$override = (string) ( $_SERVER['LG_HEALTH_APP_ENV'] ?? getenv( 'LG_HEALTH_APP_ENV' ) ?: '' );",
     "$override = (string) ( getenv( 'LG_HEALTH_APP_ENV' ) ?: '' );",
     "H16", "a fastcgi_param-delivered path lands in $_SERVER only and is never read"),

    ("M50b", HEALTH,
     "$path = (string) ( $_SERVER['LG_HEALTH_RECORDER'] ?? getenv( 'LG_HEALTH_RECORDER' ) ?: self::RECORDER_PATH );",
     "$path = (string) ( getenv( 'LG_HEALTH_RECORDER' ) ?: self::RECORDER_PATH );",
     "H16b", "the recorder override reads only one of the two places a value can arrive"),

    ("M51", PANEL,
     '<button type="button" class="button" id="lgms-health-copy">Copy path</button>',
     '<button type="button" class="button" id="lgms-health-copy">Go</button>',
     "H6b", "the read-only-with-a-copy-button ruling loses its copy button"),

    ("M51b", PANEL,
     '<input type="text" id="lgms-health-envpath" readonly',
     '<input type="text" id="lgms-health-envpath"',
     "H6", "the path field becomes editable on a screen ruled read-only"),

    # ---- I. the rendered words -------------------------------------------
    ("M52", PANEL,
     "            'unknown' => 'CANNOT SEE',",
     "            'unknown' => 'OK',",
     "I4", "a question nobody could answer renders as OK"),

    ("M53", PANEL,
     "            'fail'    => 'BROKEN',",
     "            'fail'    => 'fine',",
     "I3", "a broken state renders in reassuring words"),
]

# Comment-only edits. If ANY of these reddens the gate, the gate is grading
# prose rather than behaviour.
NOOPS = [
    ("N1", HEALTH,
     " * THE FIVE QUESTIONS NOBODY COULD ANSWER AT A GLANCE.",
     " * THE FIVE QUESTIONS NOBODY COULD ANSWER QUICKLY (reworded control).",
     "rewording the reader's opening docblock"),

    ("N2", PANEL,
     " * ⚠️ IT RENDERS ONLY.",
     " * ⚠️ THIS CLASS RENDERS ONLY (reworded control).",
     "rewording the panel's docblock"),

    ("N3", RECPT,
     " * A RECEIPT FOR EVERY WEBHOOK THAT LANDS.",
     " * A RECORD FOR EVERY WEBHOOK THAT LANDS (reworded control).",
     "rewording the recorder's docblock"),
]


def run_gate():
    p = subprocess.run([ "php", GATE ], capture_output=True, text=True, cwd=REPO)
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
    snap = tempfile.mkdtemp(prefix="lg-rf91-")

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
    base_n = re.search(r"GATE 91 GREEN — (\d+) assertions", out)
    print(f"baseline GREEN ({base_n.group(1) if base_n else '?'} assertions)\n")

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
