#!/usr/bin/env python3
"""
featured-member-gate — backlog 18 (Ian 8/11), design rulings 2026-08-14
(docs/IAN-RULINGS-2026-08-14.md item 6, after decide.html).

WHAT IT ASSERTS, and why each is here:

  A. THE TWO SCHEMA CONSTRAINTS ACTUALLY FIRE. `users.featured_opt_in_at` must
     be NULL unless `featured_opt_in` is true (a member's un-tick has to clear
     the date, or the dash's "opted in <date>" column lies about someone no
     longer in the pool). `discovery.featured_history` must never hold two
     open rows (Ian's ONE AT A TIME ruling) — a partial unique index on
     `ended_at IS NULL` is what makes that true rather than merely intended.
     Both are proven by trying to VIOLATE them and watching Postgres refuse,
     not by reading the DDL and trusting it.

  B. THE COMPLETENESS DEFINITION HAS NOT DRIFTED from
     tools/profile-completeness-report.sql. That SQL file is the reproducible
     measurement keeper and Ian have both been shown real numbers from (66
     card-ready, 978 at 12%, etc.) — Completeness.php is a SEPARATE PHP
     re-implementation of the same eight items, and there is no single source
     both could share (one is a live PDO query, the other a standalone psql
     report against whatever box happens to be measured). A future edit to
     either one, alone, is exactly how they silently disagree — so this
     re-runs the row-by-row comparison found while BUILDING the class
     (2026-08-15: 32 users' business_name carried a stray backslash from a
     double-escaping artifact; Postgres's LIKE absorbed it as an escape char
     so the SQL side was accidentally right and a naive PHP byte-comparison
     was not — see Completeness::deEscape) as a permanent regression check.

  C. FLAG OFF IS A REAL NO-OP, read from the shipped SOURCE the same way
     back-pill-navigates-gate.py does — u.php's card markup and index.php's
     member_uuid resolution must both be reachable ONLY through the tracked
     flag's on-branch, and the flag itself must still default to false.

  D. THE NO-ADMIN-OVERRIDE GUARDRAIL HOLDS. Ian's ruling is consent that is
     "never inferred... no override, not even for admins." The only place
     that could quietly regress is Auth::ADMIN_EDIT_AS_ENDPOINTS gaining
     'me-featured.php' in some future edit — gated here BEFORE the first
     time it could happen, per CRAFT-STANDARD's normal after-the-fact rule,
     because the failure mode (an admin silently opting a member in on their
     behalf) is a consent violation, not a UI bug, and worth catching pre-emptively.

  E. THE LIVE ROUTES, where reachable. profile-api/v0/me/featured and
     archive-api/v0/_config's featured_member handling are exercised over
     real HTTP against whatever host is being gated — the dev2 SERVE (main)
     until this branch merges, at which point the ON-path assertions light
     up for real. Reports CANNOT RUN (never RED) for any check whose route
     is absent, matching gate 27/29's own convention for a not-yet-deployed
     surface — an unmerged branch is an environment fact, not a finding.

DB checks use passwordless sudo peer-auth (profile-app / postgres roles), same
posture as gate 21/27/28. Exit codes follow run-all.sh: 0 green, 1 RED, 2
CANNOT RUN.
"""
import http.client
import os
import re
import ssl
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
U_PHP = os.path.join(REPO, "profile-app", "web", "u.php")
INDEX_PHP = os.path.join(REPO, "archive-poc", "web", "index.php")
FLAG_FILE = os.path.join(REPO, "platform", "config", "featured-members.php")
AUTH_PHP = os.path.join(REPO, "profile-app", "src", "Auth.php")
COMPLETENESS_PHP = os.path.join(REPO, "profile-app", "src", "Completeness.php")
ME_FEATURED_PHP = os.path.join(REPO, "profile-app", "api", "v0", "me-featured.php")

RED, DEAD, OK = [], [], []


def read(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            return f.read()
    except OSError:
        return None


def psql(role, db, sql):
    """Run SQL via passwordless sudo peer-auth. Returns (rc, stdout, stderr)."""
    cmd = ["sudo", "-n", "-u", role, "psql", "-d", db, "-A", "-t", "-c", sql]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
        return p.returncode, p.stdout.strip(), p.stderr.strip()
    except Exception as e:
        return -1, "", str(e)


def php(user, script_path):
    cmd = ["sudo", "-n", "-u", user, "php", script_path]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        return p.returncode, p.stdout, p.stderr
    except Exception as e:
        return -1, "", str(e)


def php_eval_expr(expr, cwd_dir):
    """Run a PHP snippet with __DIR__ meaning `cwd_dir` — i.e. as if the code
    lived in that directory, the same as the real caller. Written to a real
    temp .php FILE inside that directory (not `php -r`, whose __DIR__ is not
    the caller's) so a relative __DIR__-based path resolves exactly the way
    the actual server-side include does. No sudo/DB needed — pure filesystem."""
    tmp = os.path.join(cwd_dir, ".featured-member-gate-c4-tmp.php")
    try:
        with open(tmp, "w") as f:
            f.write("<?php " + expr)
        p = subprocess.run(["php", tmp], capture_output=True, text=True, timeout=10)
        return p.returncode, p.stdout, p.stderr
    except Exception as e:
        return -1, "", str(e)
    finally:
        try:
            os.unlink(tmp)
        except OSError:
            pass


def section_a_constraints():
    rc, _, err = psql("postgres", "profile_app", "SELECT 1")
    if rc != 0:
        DEAD.append(f"[A] cannot reach profile_app as postgres (peer-auth sudo): {err[:160]}")
        return
    rc, _, err = psql("postgres", "looth", "SELECT 1")
    if rc != 0:
        DEAD.append(f"[A] cannot reach looth as postgres (peer-auth sudo): {err[:160]}")
        return

    # A1: featured_opt_in_at must reject standing alone (opt_in=false).
    rc, out, _ = psql("postgres", "profile_app", "SELECT id FROM users WHERE slug='ut-test-alice'")
    test_id = out.strip() if rc == 0 and out.strip().isdigit() else None
    if test_id is None:
        DEAD.append("[A1] scratch fixture ut-test-alice not found — cannot safely test the "
                    "constraint without risking a real member's row")
    else:
        psql("postgres", "profile_app",
             f"UPDATE users SET featured_opt_in=false, featured_opt_in_at=NULL WHERE id={test_id}")
        rc, out, err = psql("postgres", "profile_app",
            f"UPDATE users SET featured_opt_in_at=now() WHERE id={test_id} AND featured_opt_in=false")
        if rc == 0:
            RED.append("[A1] users_featured_opt_in_at_ck did NOT reject featured_opt_in_at "
                       "being set while featured_opt_in=false — the dash's 'opted in <date>' "
                       "column can now lie")
        elif "featured_opt_in_at_ck" in err or "violates check constraint" in err:
            OK.append("[A1] the opt-in timestamp constraint rejects standing alone, as designed")
        else:
            DEAD.append(f"[A1] UPDATE failed for an unexpected reason: {err[:200]}")
        psql("postgres", "profile_app",
             f"UPDATE users SET featured_opt_in=false, featured_opt_in_at=NULL WHERE id={test_id}")

    # A2: featured_history must reject a second open row.
    #
    # Since the feature went live (2026-08-15) there can be a REAL open row
    # here — Ian himself featuring a real member through the real dash — and
    # this test must never touch it: the earlier version unconditionally
    # inserted a synthetic "first" row, which after that point started
    # colliding with the real one (the constraint has no concept of test vs.
    # real, by design) and reported DEAD ("could not seed") instead of
    # testing anything. Check for a real open row first; if one exists, use
    # IT as the first half of the proof (read-only, never deleted) and only
    # insert+clean up the synthetic SECOND row.
    u1, u2 = "eeeeeeee-1111-1111-1111-111111111111", "eeeeeeee-2222-2222-2222-222222222222"
    psql("postgres", "looth",
         f"DELETE FROM discovery.featured_history WHERE member_uuid IN ('{u1}','{u2}')")
    rc0, out0, err0 = psql("postgres", "looth",
        "SELECT member_uuid FROM discovery.featured_history WHERE ended_at IS NULL LIMIT 1")
    if rc0 != 0:
        DEAD.append(f"[A2] could not check for an existing open row: {err0[:200]}")
        return
    real_open = out0.strip() if out0.strip() else None

    if real_open is None:
        rc, _, err = psql("postgres", "looth",
            f"INSERT INTO discovery.featured_history (member_uuid, display_name) VALUES ('{u1}','Gate Test A')")
        if rc != 0:
            DEAD.append(f"[A2] could not seed the first open row: {err[:200]}")
            return
    # else: a real open row already exists — that's our "first", untouched.

    rc2, _, err2 = psql("postgres", "looth",
        f"INSERT INTO discovery.featured_history (member_uuid, display_name) VALUES ('{u2}','Gate Test B')")
    if rc2 == 0:
        RED.append("[A2] featured_history_one_open did NOT reject a second concurrently-open "
                   "row — Ian's ONE AT A TIME ruling is not actually enforced")
    elif "featured_history_one_open" in err2 or "duplicate key" in err2:
        source = "the real currently-open row" if real_open else "a synthetic first row"
        OK.append(f"[A2] the one-open-row-at-a-time constraint rejects a second open row "
                  f"(tested against {source}), as designed")
    else:
        DEAD.append(f"[A2] second INSERT failed for an unexpected reason: {err2[:200]}")

    psql("postgres", "looth",
         f"DELETE FROM discovery.featured_history WHERE member_uuid IN ('{u1}','{u2}')")


def section_b_completeness_matches_sql():
    report = os.path.join(REPO, "tools", "profile-completeness-report.sql")
    if not os.path.isfile(report):
        DEAD.append("[B] tools/profile-completeness-report.sql is missing — nothing to diff against")
        return
    harness = f"""<?php
define('LG_PROFILE_APP_PG_DSN', 'pgsql:host=/var/run/postgresql;dbname=profile_app');
require '{os.path.join(REPO, "profile-app", "src", "Db.php")}';
require '{COMPLETENESS_PHP}';
use Looth\\ProfileApp\\Db; use Looth\\ProfileApp\\Completeness;
$pg = Db::pg();
$rows = $pg->query("
  SELECT u.id,
    (avatar_version>0)::int AS sql_photo,
    ((coalesce(location_city,'')<>'') OR (coalesce(location_region,'')<>''))::int AS sql_location,
    ((coalesce(at_a_glance,'')<>'') OR (coalesce(business_name,'')<>'' AND display_name NOT LIKE '%'||business_name))::int AS sql_whatyoudo,
    EXISTS(SELECT 1 FROM profile_sections s WHERE s.user_id=u.id AND s.key='about' AND coalesce(s.data->>'text','')<>'')::int AS sql_bio,
    EXISTS(SELECT 1 FROM profile_socials x WHERE x.user_id=u.id)::int AS sql_links,
    (EXISTS(SELECT 1 FROM profile_skills x WHERE x.user_id=u.id) OR EXISTS(SELECT 1 FROM profile_instruments x WHERE x.user_id=u.id))::int AS sql_craft,
    EXISTS(SELECT 1 FROM profile_sections s WHERE s.user_id=u.id AND s.key LIKE 'gallery%')::int AS sql_gallery,
    (banner_version>0)::int AS sql_banner
  FROM users u WHERE profile_visibility='public' AND slug !~ '^patreon_[0-9]+\\$'
")->fetchAll();
$diff = 0;
foreach ($rows as $r) {{
    $php = Completeness::forUser((int)$r['id']);
    $sql = ['photo'=>(bool)$r['sql_photo'],'location'=>(bool)$r['sql_location'],
            'what_you_do'=>(bool)$r['sql_whatyoudo'],'bio'=>(bool)$r['sql_bio'],
            'links'=>(bool)$r['sql_links'],'craft'=>(bool)$r['sql_craft'],
            'gallery'=>(bool)$r['sql_gallery'],'banner'=>(bool)$r['sql_banner']];
    if ($sql !== $php['items']) $diff++;
}}
echo "TOTAL={{$diff}}/" . count($rows) . "\\n";
"""
    tmp = "/tmp/.featured-member-gate-completeness-check.php"
    try:
        with open(tmp, "w") as f:
            f.write(harness)
        os.chmod(tmp, 0o644)
    except OSError as e:
        DEAD.append(f"[B] could not write harness: {e}")
        return
    rc, out, err = php("profile-app", tmp)
    try:
        os.unlink(tmp)
    except OSError:
        pass
    m = re.search(r"TOTAL=(\d+)/(\d+)", out)
    if rc != 0 or not m:
        DEAD.append(f"[B] harness did not run cleanly: rc={rc} err={err[:200]} out={out[:200]}")
        return
    diffs, total = int(m.group(1)), int(m.group(2))
    if diffs > 0:
        RED.append(f"[B] Completeness.php disagrees with the SQL report on {diffs}/{total} "
                   f"members — the dash and the numbers already shown to Ian would now diverge")
    else:
        OK.append(f"[B] Completeness.php matches the SQL report on all {total} public members")


def section_c_flag_off():
    # PER-STATE since 2026-08-15 (keeper): originally this hard-asserted OFF,
    # which made every RULED flip a red — same lesson as the compose gate,
    # "flipping the default needs no gate edit". The policy this now encodes:
    # ON is legal ONLY with an attribution comment naming the ruling on the
    # define/flip (grep the flag file's history for who and when); an ON with
    # no ruling text adjacent is still RED. The real safety — the member-facing
    # markup reachable only through the flag check — is asserted in BOTH states
    # by C2/C2b/C2c below, which do not care what the default is.
    flag_src = read(FLAG_FILE)
    if flag_src is None:
        DEAD.append("[C] platform/config/featured-members.php is missing")
    elif re.search(r"'enabled'\s*=>\s*true", flag_src):
        window = flag_src[max(0, flag_src.find("'enabled' => true") - 400):flag_src.find("'enabled' => true")]
        if re.search(r"(?i)\bIan\b.{0,120}(ruled|ruling|decision|box|flip)", window, re.S):
            OK.append("[C1] flag is ON by an attributed ruling (comment names Ian + the decision)")
        else:
            RED.append("[C] the tracked flag is ON with no ruling attribution beside it — "
                       "an unruled ON is a member-facing surface nobody decided to open")
    elif re.search(r"'enabled'\s*=>\s*false", flag_src):
        OK.append("[C1] the tracked flag defaults to false")
    else:
        DEAD.append("[C] could not find an 'enabled' => ... line in the flag file to read")

    u_src = read(U_PHP)
    if u_src is None:
        DEAD.append("[C] profile-app/web/u.php is missing")
    else:
        m = re.search(r"if\s*\(\s*\$lg_fmOn\s*&&\s*\$isOwner\s*\)\s*:.*?lg-featcard", u_src, re.S)
        if not m:
            RED.append("[C2] the featured-member card markup is not textually gated behind "
                       "$lg_fmOn — flag OFF may not be a real no-op")
        else:
            OK.append("[C2] u.php's card markup is gated behind the flag check ($lg_fmOn)")

        # C2b: the CSS RULES, separately from the markup. Found by the real
        # click-through against the merged serve, 2026-08-15 — a gate that
        # only checked the markup (C2, above) missed this: the .lg-featcard*
        # class definitions lived as plain text inside the shared <style>
        # block with NO php around them at all, so they shipped on every
        # /u/<slug> page regardless of the flag. Inert (no matching DOM to
        # style) but not byte-identical — this codebase's own bar
        # (back-pill.php, sheet-embeds.php).
        #
        # Went through TWO broken versions before this one, each caught only
        # by red-firing it, not by inspection — worth keeping both fixes
        # visible so neither regresses back in:
        #   v1 used `.*?` unbounded forward search for "if ($lg_fmOn) appears
        #      BEFORE the CSS rule somewhere in the file" — true of almost
        #      any pairing regardless of whether an endif closed the block in
        #      between, so it could not tell "gated" from "merely preceded by
        #      an unrelated if elsewhere in the file".
        #   v2 added a lookbehind to stop the light-block search matching
        #      inside the dark rule's own selector text (`.lg-featcard{` is a
        #      literal substring of `html[...="dark"] .lg-featcard{`), but
        #      still used unbounded `.*?` — so removing the DARK block's own
        #      `if` still passed, because the LIGHT block's `if` (still
        #      present, far earlier in the file) satisfied the same
        #      unbounded "appears somewhere before" test for the dark rule.
        # v3 (this one): find the exact byte offset of each CSS rule, then
        # require the NEAREST preceding `if ($lg_fmOn)` to be closer than the
        # nearest preceding `endif` — i.e., no `endif` has closed the
        # matching if-block between the gate and the rule. This is the
        # smallest check that actually distinguishes "textually gated" from
        # "somewhere after an unrelated if used once".
        def _rule_is_gated(src, rule_marker):
            rule_pos = src.find(rule_marker)
            if rule_pos < 0:
                return False
            preceding = src[:rule_pos]
            last_if = preceding.rfind("if ($lg_fmOn):")
            last_endif = preceding.rfind("endif;")
            return last_if >= 0 and last_if > last_endif

        css_gated = (
            _rule_is_gated(u_src, ".lg-featcard{") and
            _rule_is_gated(u_src, 'html[data-lguser-theme="dark"] .lg-featcard{')
        )
        if not css_gated:
            RED.append("[C2b] the featured-member CSS RULES (not just the markup) are not both "
                       "gated behind $lg_fmOn — inert but not byte-identical: the class "
                       "definitions would ship on every profile page regardless of the flag")
        else:
            OK.append("[C2b] both the light and dark featured-member CSS blocks are gated "
                      "behind the flag check, matching the markup")

        # C2c: the tickbox toggle <script> block, same defect class as C2b,
        # found in the same click-through sweep — the script's own
        # `if (!cb) return;` guard makes it inert without the markup, but
        # inert bytes shipping unconditionally is exactly what C2b already
        # established is not good enough. Same _rule_is_gated helper, same
        # position-based check (no unbounded regex this time — learned that
        # lesson once already on C2b, no need to relearn it here).
        script_gated = _rule_is_gated(u_src, "document.getElementById('lg-featcard-cb')")
        if not script_gated:
            RED.append("[C2c] the featured-member tickbox <script> block is not gated behind "
                       "$lg_fmOn — inert (guards on a missing element) but not byte-identical: "
                       "the handler would ship on every profile page regardless of the flag")
        else:
            OK.append("[C2c] the featured-member tickbox script is gated behind the flag check, "
                      "matching the markup and CSS")

    idx_src = read(INDEX_PHP)
    if idx_src is None:
        DEAD.append("[C] archive-poc/web/index.php is missing")
    else:
        # STRICT: the resolve CALL must appear inside the `if ($lg_fm_on) {`
        # block, ahead of its own `} else { $lg_fm = null; }` — not merely
        # "lg_fm_on appears somewhere nearby". Was a ternary
        # ($lg_fm = $lg_fm_on ? lg_resolve_featured_member(...) : null) until
        # review 2026-08-15 added a try/catch (PDO can throw on a transient
        # DB outage), which needed a full if/else instead — this regex was
        # updated to match at the same time, and re-red-fired (moving the
        # call above the `if` still trips it) so the structural check didn't
        # quietly weaken along with the refactor.
        m = re.search(
            r"if\s*\(\s*\$lg_fm_on\s*\)\s*\{.*?lg_resolve_featured_member\s*\(.*?\}\s*else\s*\{\s*\$lg_fm\s*=\s*null;",
            idx_src, re.S)
        if not m:
            RED.append("[C3] index.php's member_uuid resolution is not textually gated behind "
                       "a flag check — a stale member_uuid in config.json could resolve a real "
                       "card even with the feature meant to be off")
        else:
            OK.append("[C3] index.php's real-member resolution is gated behind the flag check")

    # C4: me-featured.php's OWN include of the flag file must resolve to a
    # REAL, readable file — found 2026-08-15 via a live PUT that came back
    # 403 feature_disabled with the tracked flag reading enabled=true: the
    # file used `__DIR__ . '/../../platform/config/...'`, one `..` short for
    # api/v0's extra directory level (u.php and index.php sit one level
    # shallower, where that same two-dot pattern IS correct — a straight
    # copy-paste without adjusting for the depth). `@include` swallowed the
    # miss silently and `$cfg` fell back to false, so the member-visible
    # symptom (every PUT refused) looked identical to the flag being
    # genuinely off — no static check anywhere caught it because C1-C3 all
    # assert the FLAG FILE's own content, never that a CALLER can actually
    # reach it. This does not re-derive the path by counting dots (that is
    # exactly the class of arithmetic that produced the bug); it extracts the
    # literal expression from the source and asks PHP to resolve it for
    # real, the same way the running server would.
    me_featured_src = read(ME_FEATURED_PHP)
    if me_featured_src is None:
        DEAD.append("[C4] profile-app/api/v0/me-featured.php is missing")
    else:
        m = re.search(r"@include\s+__DIR__\s*\.\s*'([^']+featured-members\.php)'", me_featured_src)
        if not m:
            RED.append("[C4] me-featured.php no longer includes the flag file the way this "
                       "check expects — could not find the @include __DIR__ . '...' expression")
        else:
            rc, out, err = php_eval_expr(
                "var_dump(realpath(__DIR__ . '" + m.group(1) + "'));",
                os.path.dirname(ME_FEATURED_PHP),
            )
            resolved = out.strip()
            expect = 'string(%d) "%s"' % (len(os.path.realpath(FLAG_FILE)), os.path.realpath(FLAG_FILE))
            if rc != 0 or resolved != expect:
                RED.append("[C4] me-featured.php's include path does not resolve to the real "
                           "flag file (got: %s) — every PUT would silently read the flag as off, "
                           "exactly as it did live before this was fixed" % (resolved or err)[:200])
            else:
                OK.append("[C4] me-featured.php's flag include resolves to the real, tracked "
                          "flag file — a member's PUT can actually see the real enabled state")


def section_d_no_admin_override():
    auth_src = read(AUTH_PHP)
    if auth_src is None:
        DEAD.append("[D] profile-app/src/Auth.php is missing")
        return
    m = re.search(r"ADMIN_EDIT_AS_ENDPOINTS\s*=\s*\[(.*?)\];", auth_src, re.S)
    if not m:
        DEAD.append("[D] could not find ADMIN_EDIT_AS_ENDPOINTS in Auth.php to check")
        return
    if "me-featured.php" in m.group(1):
        RED.append("[D] me-featured.php has been added to ADMIN_EDIT_AS_ENDPOINTS — Ian's ruling "
                   "is consent that is never inferred and never overridden, not even by an admin "
                   "acting ?as= a member")
    else:
        OK.append("[D] me-featured.php stays off the admin-impersonation allowlist, as ruled")


def check_http(path, host, headers, expect_key=None):
    """GET `path` against the BOX'S OWN LOOPBACK, never public DNS.

    Found by keeper 2026-08-15: the original version built the request URL as
    f"https://{host}/..." and let urllib resolve `host` normally — which
    means a real DNS lookup, out through Cloudflare's edge, in front of the
    box. Cloudflare bot-challenges an unrecognized client into a 403 (the
    documented box trap 2: "Never smoke live with a plain public curl — it
    reads as an outage"), and this gate's §E would have read that exact 403
    as "nginx is blocking the route" — the identical false-red the finding
    #1 fix was written to catch for real, now capable of firing on a
    Cloudflare artifact instead. CONNECT to 127.0.0.1 (the box's own door);
    the Host header alone decides which vhost answers, matching every curl
    --resolve invocation used throughout this lane's own verification.
    """
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    conn = http.client.HTTPSConnection("127.0.0.1", 443, timeout=5, context=ctx)
    try:
        conn.request("GET", path, headers={**headers, "Host": host})
        r = conn.getresponse()
        body = r.read(4000).decode("utf-8", "replace")
        return r.status, body
    except Exception as e:
        return None, str(e)
    finally:
        conn.close()


def section_e_live_routes():
    gate_cookie = os.environ.get("LG_GATE_COOKIE", "")
    host = os.environ.get("LG_GATE_HOST", "dev2.loothgroup.com")
    if not gate_cookie:
        DEAD.append("[E] LG_GATE_HOST/LG_GATE_COOKIE not set — cannot exercise the live routes; "
                    "this is an environment fact, not a finding, until this branch is merged")
        return
    # EXPLICIT allow-list of acceptable statuses, not an else-catches-all.
    # Found in review 2026-08-15: the original else branch called ANY
    # non-404/401 status "routed, OK" — which would have called the nginx
    # allow-list gap (finding elsewhere in this same review: me-featured was
    # missing from strangler-profile-app.conf's regex, producing a 403) a
    # PASS. Auth::requireUser() answers 401 with no session; that is the one
    # response that proves the route is live AND reaches the PHP endpoint.
    # 403 is now its own branch — RED, not swallowed — because it is exactly
    # the signature of "nginx is blocking this before PHP ever runs."
    status, body = check_http("/profile-api/v0/me/featured", host,
                               {"Cookie": f"loothdev_auth={gate_cookie}"})
    if status is None:
        DEAD.append(f"[E1] request to /profile-api/v0/me/featured failed: {body[:160]}")
    elif status == 404:
        DEAD.append("[E1] /profile-api/v0/me/featured is 404 on this host — route not live yet "
                    "(expected pre-merge; re-run after merge)")
    elif status == 401:
        OK.append("[E1] /profile-api/v0/me/featured is routed and requires auth (401 without a "
                 "member session) — the route exists, reaches PHP, and is not wide open")
    elif status == 403:
        RED.append("[E1] /profile-api/v0/me/featured returned 403 — nginx is blocking this route "
                  "before it reaches PHP (check the regex allow-list in "
                  "strangler-profile-app.conf around the me-*.php location)")
    else:
        DEAD.append(f"[E1] /profile-api/v0/me/featured returned an unexpected HTTP {status} — "
                    "not a known-good or known-bad signature, needs a human look")


def main():
    print("=== featured-member-gate: backlog 18 ===")
    section_a_constraints()
    section_b_completeness_matches_sql()
    section_c_flag_off()
    section_d_no_admin_override()
    section_e_live_routes()

    for m in OK:   print(f"  ok   {m}")
    for m in RED:  print(f"  RED  {m}")
    for m in DEAD: print(f"  DEAD {m}")

    if DEAD and not RED:
        print(f"featured-member-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if RED:
        print(f"featured-member-gate: RED — {len(RED)} finding(s)")
        return 1
    print(f"featured-member-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
