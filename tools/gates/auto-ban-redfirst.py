#!/usr/bin/env python3
"""
auto-ban-redfirst.py — the proof that gate 84 can fail.

A green gate is worth exactly what its red-first is worth. This breaks the
shipped code one deliberate way at a time and requires the SPECIFIC assertion
that claims to cover that break to go red. An assertion that stays green while
the thing it names is broken is decoration, and this is how that gets found
before Ian does.

TWO RULES IT KEEPS, both learned here the hard way:

  · IT SNAPSHOTS, IT DOES NOT `git checkout --`. Restoring from HEAD would wipe
    uncommitted work in the same tree and turn one harness bug into a pile of
    false "the assertion is decoration" verdicts. Every file is copied byte-for-
    byte before it is touched and copied back afterwards, whatever happens.
  · A NO-OP MUTATION MUST FAIL LOUD. If a replacement does not change the file,
    or leaves PHP that will not parse, that is reported as a broken mutation
    rather than quietly scored as "the gate did not catch it".

It also runs the reverse: harmless edits that MUST leave the gate green, so a
gate that simply always fails cannot masquerade as a thorough one.

    python3 tools/gates/auto-ban-redfirst.py [-k SUBSTRING]
"""
import argparse
import atexit
import os
import re
import shutil
import signal
import subprocess
import sys
import tempfile

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
GATE = os.path.join(REPO, "tools", "gates", "auto-ban-gate.py")

MU     = "platform/mu-plugins/lg-auto-ban.php"
MON    = "platform/mu-plugins/lg-login-monitor.php"
REND   = "tools/infra/lg-auto-ban-render.py"
MAPS   = "platform/nginx/lg-auto-ban-maps.conf.template"
DOORS  = "platform/nginx/lg-auto-ban-doors.conf.template"
VHOST  = "platform/nginx/dev2.loothgroup.com.conf"
PAGE   = "lg-shared/errors/login-blocked.html"
INST   = "tools/infra/install-auto-ban.sh"

# (name, file, find, replace, the assertion PREFIX that must go red)
MUTATIONS = [
    # ── the flag ────────────────────────────────────────────────────────────
    ("flag is ignored — OFF records anyway", MU,
     "\tif ( ! lg_ab_enabled() ) {\n\t\treturn null;   // flag OFF: no file, no directory, no trace\n\t}",
     "\tif ( false ) {\n\t\treturn null;\n\t}", "A3"),
    ("config failure guesses ON instead of OFF", MU,
     "'enabled'     => ! empty( $defaults['enabled'] ),",
     "'enabled'     => true,", "A3"),
    ("the box-local override stops winning", MU,
     "\t$local = @include LG_AB_CONFIG_DIR . '/auto-ban.local.php';",
     "\t$local = null;", "A6"),

    # ── the trigger ─────────────────────────────────────────────────────────
    ("the threshold drops to one account", MON,
     "return (int) apply_filters( 'lg_login_monitor_stuffing_threshold', 5 );",
     "return (int) apply_filters( 'lg_login_monitor_stuffing_threshold', 1 );", "B2"),
    ("the signal fires on every failure, not just stuffing", MON,
     "\t\t\t\tdo_action( 'lg_login_stuffing_detected', array(",
     "\t\t\t}\n\t\t\t{\n\t\t\t\tdo_action( 'lg_login_stuffing_detected', array(", "B"),

    # ── the address: the security model ─────────────────────────────────────
    ("the client header is believed unconditionally", MU,
     "\tif ( ! lg_ab_is_cf( $peer ) ) {",
     "\tif ( false ) {", "C2"),
    ("a Cloudflare edge address becomes bannable", MU,
     "\tif ( lg_ab_is_cf( $ip ) ) {\n\t\treturn 'cloudflare';",
     "\tif ( false ) {\n\t\treturn 'cloudflare';", "C4"),
    ("private addresses become bannable", MU,
     "\tif ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {",
     "\tif ( false ) {", "C5"),
    # ⚠️ EXPECTS C3b, NOT C3. C3's fixture peer is itself a Cloudflare address, so
    # the structural refusal catches this mutation whatever the rule returns and
    # C3 stayed green over a deleted rule. C3b asks the function directly.
    ("a vouched connection with no client header bans the edge", MU,
     "\treturn $cf !== '' ? $cf : '';",
     "\treturn $cf !== '' ? $cf : $peer;", "C3b"),

    # ── the renderer: the privilege boundary ────────────────────────────────
    ("bans stop expiring", REND,
     "        if expires <= now:\n            drops[\"expired\"] += 1\n            continue",
     "        if False:\n            drops[\"expired\"] += 1\n            continue", "D1"),
    ("the allowlist stops being honoured", REND,
     "        if addr in allow or any(addr.version == n.version and addr in n for n in op_allow):",
     "        if False:", "D2"),
    ("the renderer stops refusing Cloudflare", REND,
     "    for net in cf_nets:\n        if addr.version == net.version and addr in net:\n            return \"cloudflare\"",
     "    for net in []:\n        if addr.version == net.version and addr in net:\n            return \"cloudflare\"", "D3"),
    ("the cap stops holding", REND,
     "    if len(kept) > max_entries:",
     "    if False:", "D4"),
    ("addresses are copied through instead of re-printed", REND,
     "    return [str(addr) for _, addr in kept], drops",
     "    return [str(b[\"ip\"]) for b in (state.get(\"bans\") or [])\n            if isinstance(b, dict) and any(str(b.get(\"ip\")) == str(a) for _, a in kept)], drops", "D5"),
    ("the status claims armed when nothing is installed", REND,
     "    armed = os.path.exists(doors_p) and os.path.exists(maps_p)",
     "    armed = True", "D6"),
    ("a rejected render is kept instead of rolled back", REND,
     '            if prev is not None:\n                write_if_changed(out_p, prev)',
     '            if False:\n                write_if_changed(out_p, prev)', "D8b"),
    ("the render time goes back into the file nginx compares", REND,
     '        f"# entries: {len(addresses)}",',
     '        f"# rendered: {time.strftime(\'%H:%M:%S\', time.gmtime(now))}",\n'
     '        f"# entries: {len(addresses)}",', "D7"),

    # ── the dash ────────────────────────────────────────────────────────────
    ("Remove stops removing", MU,
     "\t\t\tif ( is_array( $b ) && (string) ( $b['ip'] ?? '' ) === $ip ) {\n\t\t\t\t$removed = true;\n\t\t\t\tcontinue;\n\t\t\t}",
     "\t\t\tif ( false ) {\n\t\t\t\t$removed = true;\n\t\t\t\tcontinue;\n\t\t\t}", "E1"),
    ("the nonce stops being checked", MU,
     "\tcheck_admin_referer( 'lg_ab' );",
     "\t/* removed */", "E2"),
    ("the capability stops being checked", MU,
     "function lg_ab_handle( string $verb ): void {\n\tif ( ! current_user_can( LG_AB_CAP ) ) {\n\t\twp_die( 'Forbidden' );\n\t}",
     "function lg_ab_handle( string $verb ): void {\n\tif ( false ) {\n\t\twp_die( 'Forbidden' );\n\t}", "E4"),
    ("Never-ban forgets to unban", MU,
     "\t\tforeach ( $state['bans'] as $b ) {\n\t\t\tif ( is_array( $b ) && (string) ( $b['ip'] ?? '' ) === $ip ) {\n\t\t\t\tcontinue;\n\t\t\t}\n\t\t\t$kept[] = $b;\n\t\t}\n\t\t$state['bans'] = array_values( $kept );\n\n\t\tforeach ( $state['allowlist'] as $a ) {",
     "\t\tforeach ( $state['bans'] as $b ) {\n\t\t\t$kept[] = $b;\n\t\t}\n\t\t$state['bans'] = array_values( $kept );\n\n\t\tforeach ( $state['allowlist'] as $a ) {", "E3"),
    ("free text goes back into the redirect", MU,
     "\tlg_ab_redirect( lg_ab_remove_ban( $ip ) ? 'removed' : 'notlisted', $ip );",
     "\t\t\tlg_ab_redirect( rawurlencode( $ip . ' can sign in again.' ) );", "E1b"),
    ("the outcome sentence stops being composed", MU,
     "\t\tcase 'removed':    return $ip . ' can sign in again.';",
     "\t\tcase 'removed':    return '';", "E1c"),
    ("the dash claims to be blocking when it is not", MU,
     "\t} elseif ( empty( $status['armed'] ) ) {",
     "\t} elseif ( false ) {", "E5b"),
    ("the dash hides that recording is off", MU,
     "\tif ( ! $cfg['enabled'] ) {",
     "\tif ( false ) {", "E6"),

    # ── the config shape ────────────────────────────────────────────────────
    ("the vhost stops including the doors", VHOST,
     "    include /etc/nginx/snippets/lg-auto-ban-*.conf;",
     "    # include removed", "F1"),
    ("the vhost names a snippet variable in a directive", VHOST,
     "    location = /billing { return 301 /billing/; }",
     "    location = /billing { return 301 /billing/; }\n    add_header X-Ab $lg_ab_block always;", "F2"),
    ("the placeholder gets mentioned twice", MAPS,
     "# TEMPLATE. tools/infra/install-auto-ban.sh renders this to the box-local",
     "# TEMPLATE (@CF_RANGES@). tools/infra/install-auto-ban.sh renders this box-local", "F4"),
    ("logout stops being exempt", MAPS,
     '    "logout"                0;',
     '    "logout"                1;', "F5"),

    # ── nginx behaviour ─────────────────────────────────────────────────────
    ("nothing counts as the password door any more", MAPS,
     "map $arg_action $lg_ab_password_door {\n    default                 1;",
     "map $arg_action $lg_ab_password_door {\n    default                 0;", "G1"),
    ("the client is taken from the header on every connection", MAPS,
     "map $lg_ab_from_cf $lg_ab_client {\n    1       $http_cf_connecting_ip;\n    default $remote_addr;\n}",
     "map $lg_ab_from_cf $lg_ab_client {\n    1       $http_cf_connecting_ip;\n    default $http_cf_connecting_ip;\n}", "G8"),
    ("the login door stops asking the question", DOORS,
     "    if ($lg_ab_block) { return 462; }",
     "    # question removed", "G1"),
    ("a blocked login gets a blank refusal instead of the page", DOORS,
     "    error_page 462 =403 /lg-error/login-blocked.html;",
     "    # no error page", "G1b"),
    ("the API door answers with HTML its caller cannot read", DOORS,
     '    if ($lg_ab_listed) { return 403 \'{"code":"lg_login_blocked","blocked":true,"message":"Sign-in from your network is paused for a day after several failed password attempts. You can still sign in with Patreon, or email info@loothgroup.com and we will lift it."}\'; }\n\n    include fastcgi.conf;\n    fastcgi_param SCRIPT_FILENAME @DOCROOT@/index.php;\n    fastcgi_param SCRIPT_NAME /index.php;\n    fastcgi_pass unix:@FPM_SOCK@;\n    fastcgi_read_timeout 300;\n}\n\nlocation = /wp-json/lg-member-sync/v1/gift-auth {',
     '    error_page 462 =403 /lg-error/login-blocked.html;\n    if ($lg_ab_listed) { return 462; }\n\n    include fastcgi.conf;\n    fastcgi_param SCRIPT_FILENAME @DOCROOT@/index.php;\n    fastcgi_param SCRIPT_NAME /index.php;\n    fastcgi_pass unix:@FPM_SOCK@;\n    fastcgi_read_timeout 300;\n}\n\nlocation = /wp-json/lg-member-sync/v1/gift-auth {', "G6b"),
    # ── the flip kit ────────────────────────────────────────────────────────
    ("the installer stops checking the vhost include line", INST,
     'if [ -n "$VHOST" ] && ! grep -q \'include /etc/nginx/snippets/lg-auto-ban-\\*\\.conf;\' "$VHOST"; then',
     'if false; then', "H4"),
    ("the installer stops checking the polite page", INST,
     'if [ ! -r "$BANPAGE" ]; then',
     'if false; then', "H5"),
    ("a rejected config is left half-installed", INST,
     '    if [ -n "$PREV_DOORS" ]; then cp "$PREV_DOORS" "$DOORS"; else rm -f "$DOORS"; fi',
     '    if [ -n "$PREV_DOORS" ]; then cp "$PREV_DOORS" "$DOORS"; fi', "H6b"),
    ("--uninstall throws the ban store away too", INST,
     '    rm -f "$MAPS" "$DOORS" "$LIST"',
     '    rm -f "$MAPS" "$DOORS" "$LIST"; rm -rf "$STATE_DIR"', "H7c"),
    ("the FPM socket is guessed instead of read off the vhost", INST,
     '[ -n "$FPM_SOCK" ] || { echo "FATAL: could not find the WordPress FPM socket in $VHOST" >&2; exit 1; }',
     'FPM_SOCK=/run/php/php8.3-fpm-looth-dev.sock', "H2b"),
    ("the list path stops being substituted", MAPS,
     "    include @LIST_INCLUDE@;",
     "    include /etc/nginx/lg-auto-ban/list*.conf;", "H2c"),
    ("the polite page stops explaining itself", PAGE,
     "<h1>Sign-in is paused for your network</h1>",
     "<h1>Forbidden</h1>", "F6"),
]

# Edits that change nothing that matters. The gate MUST stay green through all of
# them, or it is failing for reasons unrelated to what it claims to measure.
NO_OPS = [
    ("a comment added to the mu-plugin", MU,
     "const LG_AB_CAP  = 'manage_options';",
     "// a harmless comment\nconst LG_AB_CAP  = 'manage_options';"),
    ("a comment added to the renderer", REND,
     "DEF_MAX     = 500",
     "DEF_MAX     = 500   # harmless trailing comment"),
    ("prose reworded in the doors template", DOORS,
     "# SCOPE: THE LOGIN DOOR, NEVER THE SITE.",
     "# SCOPE NOTE: the login door, never the site."),
    ("a blank line added to the maps template", MAPS,
     "map $lg_ab_from_cf $lg_ab_client {",
     "\nmap $lg_ab_from_cf $lg_ab_client {"),
]


# A killed harness must not leave a mutation in the tree. `finally` covers an
# exception; it does NOT cover SIGTERM, and the first run of this file was killed
# by a caller's timeout and left a live mutation behind in a tracked template.
_LIVE = {}


def _restore_all(*_a):
    for src, keep in list(_LIVE.items()):
        try:
            shutil.copy2(keep, src)
        except OSError:
            pass
    _LIVE.clear()


atexit.register(_restore_all)
for _sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
    signal.signal(_sig, lambda s, f: (_restore_all(), sys.exit(130)))


def run_gate():
    p = subprocess.run([sys.executable, GATE], capture_output=True, text=True, timeout=900)
    failed = [m.group(1).strip() for m in re.finditer(r"^   · (.+)$", p.stdout, re.M)]
    return p.returncode, failed, p.stdout


def php_ok(path):
    if not path.endswith(".php"):
        return True, ""
    p = subprocess.run(["php", "-l", path], capture_output=True, text=True, timeout=60)
    return p.returncode == 0, (p.stdout + p.stderr).strip()[:200]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("-k", help="only mutations whose name contains this")
    args = ap.parse_args()

    rc0, failed0, out0 = run_gate()
    if rc0 != 0:
        print("REFUSING TO START: gate 84 is not green on the untouched tree.")
        print("A red-first run only means something from a green baseline.")
        for f in failed0:
            print("   ·", f)
        return 2
    print("baseline: gate 84 GREEN\n")

    snap = tempfile.mkdtemp(prefix="lg-ab-redfirst-")
    problems = []
    caught = 0
    total = 0

    def with_mutation(rel, find, repl, body):
        """Snapshot -> mutate -> body() -> restore. Never git checkout."""
        src = os.path.join(REPO, rel)
        keep = os.path.join(snap, rel.replace("/", "__"))
        shutil.copy2(src, keep)
        _LIVE[src] = keep
        try:
            text = open(src, encoding="utf-8").read()
            n = text.count(find)
            if n != 1:
                return f"the mutation matched {n} times, not once — it proves nothing"
            open(src, "w", encoding="utf-8").write(text.replace(find, repl))
            if open(src, encoding="utf-8").read() == open(keep, encoding="utf-8").read():
                return "the mutation changed nothing — a no-op scored as a miss"
            ok, why = php_ok(src)
            if not ok:
                return f"the mutation produced PHP that will not parse: {why}"
            return body()
        finally:
            shutil.copy2(keep, src)
            _LIVE.pop(src, None)

    for name, rel, find, repl, expect in MUTATIONS:
        if args.k and args.k not in name:
            continue
        total += 1

        def body(name=name, expect=expect):
            rc, failed, _ = run_gate()
            if rc == 0:
                return "GATE STAYED GREEN — this assertion is decoration"
            if expect is None:
                return None
            if not any(f.startswith(expect) for f in failed):
                return (f"gate went red but not at {expect} — it caught "
                        + "; ".join(failed[:3]))
            return None

        problem = with_mutation(rel, find, repl, body)
        if problem:
            print(f"  MISS  {name}\n          {problem}")
            problems.append((name, problem))
        else:
            where = f" -> {expect}" if expect else " -> (red anywhere)"
            print(f"  ok    {name}{where}")
            caught += 1

    print()
    for name, rel, find, repl in NO_OPS:
        if args.k and args.k not in name:
            continue
        total += 1

        def body(name=name):
            rc, failed, _ = run_gate()
            if rc != 0:
                return "GATE WENT RED on a change that means nothing: " + "; ".join(failed[:3])
            return None

        problem = with_mutation(rel, find, repl, body)
        if problem:
            print(f"  MISS  no-op: {name}\n          {problem}")
            problems.append(("no-op: " + name, problem))
        else:
            print(f"  ok    no-op: {name} — gate stayed green")
            caught += 1

    shutil.rmtree(snap, ignore_errors=True)

    rc, failed, _ = run_gate()
    print()
    if rc != 0:
        print("⚠️  THE TREE DID NOT COME BACK CLEAN — gate 84 is red after the run:")
        for f in failed:
            print("   ·", f)
        return 2

    print(f"tree restored, gate 84 green again — {caught}/{total} mutations behaved")
    if problems:
        print(f"\nRED-FIRST INCOMPLETE — {len(problems)} mutation(s) went uncaught:")
        for n, w in problems:
            print(f"   · {n}: {w}")
        return 1
    print("RED-FIRST COMPLETE — every break is caught by the assertion that names it, "
          "and nothing harmless reddens it.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
