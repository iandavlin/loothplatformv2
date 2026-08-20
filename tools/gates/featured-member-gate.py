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

  F. COMPLETION NEVER BLOCKS THE ADMIN'S PICK, and the dash never claims a
     save rendered when it did not. Ian, 2026-08-19 (#107): "I'd also like to
     be able to select a member for features even if they don't hit the
     completion numbers... the dash should allow me to select anyone",
     clarified the same night to "opted in only". So consent is the ONE gate
     and completeness is information — §F1 proves that by EXECUTING the
     shipped rule (FeaturedMemberDash::selection_block_reason) against the
     real pool, not by grepping the file for a refusal that could simply have
     moved. §F2 proves the pillar it must not take with it: a private profile
     is still refused (Ian rejected the "literally anyone" reading).

     §F4 covers the trap that makes §F1 dangerous on its own. The front-page
     resolver keeps its own guard — no avatar or no role => no band — so
     dropping the dash's wall without a warning converts a legible refusal
     into a silent one. MEASURED 2026-08-20 through the real admin-post.php
     path: featuring Rick Liftig, whom the dash then called "Ready" with an
     enabled button, answered "Saved and pushed to archive-poc" and removed
     the band from the front page entirely (74,456 -> 72,838 bytes, zero
     lg-fm__ markers). Four of eight opted-in members were in that state.
     §F3 is the drift alarm for the copy that fixes it: the pool's
     card_renderable reproduces a rule that LIVES IN ANOTHER PROCESS
     (archive-poc), so if the resolver's guard changes and the predictor does
     not follow, this goes RED rather than quietly lying to the admin again.

     §F is NOT vacuous on an empty pool: it reports CANNOT RUN if nobody has
     opted in, because "every member is selectable" is trivially true of no
     members — the same reason §B counts its rows.

DB checks use passwordless sudo peer-auth (profile-app / postgres roles), same
posture as gate 21/27/28. Exit codes follow run-all.sh: 0 green, 1 RED, 2
CANNOT RUN.
"""
import http.client
import os
import re
import shutil
import ssl
import subprocess
import sys
import tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
U_PHP = os.path.join(REPO, "profile-app", "web", "u.php")
INDEX_PHP = os.path.join(REPO, "archive-poc", "web", "index.php")
FLAG_FILE = os.path.join(REPO, "platform", "config", "featured-members.php")
AUTH_PHP = os.path.join(REPO, "profile-app", "src", "Auth.php")
COMPLETENESS_PHP = os.path.join(REPO, "profile-app", "src", "Completeness.php")
ME_FEATURED_PHP = os.path.join(REPO, "profile-app", "api", "v0", "me-featured.php")
DASH_PHP = os.path.join(REPO, "lg-layout-v2", "src", "FeaturedMemberDash.php")
POOL_ENDPOINT_PHP = os.path.join(REPO, "profile-app", "api", "v0", "internal-featured-pool.php")
CONSENT_FLAG_FILE = os.path.join(REPO, "platform", "config", "featured-consent.php")

RED, DEAD, OK = [], [], []


def php_str(v):
    """A PHP single-quoted literal for `v` — paths are interpolated into the
    §F harness, and a naive f-string would break on any quote in a path."""
    return "'" + str(v).replace("\\", "\\\\").replace("'", "\\'") + "'"


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


# ── F. the #107 rule: completion informs, consent decides ────────────────────

# The pool endpoint exit()s and needs the internal secret, so it runs as its own
# process and hands back JSON. Kept as a separate file from the rule harness
# below: nesting a PHP-generating-PHP string is how the first version of this
# broke ("$_SERVER['K']" is a parse error inside double quotes, not an
# interpolation), and the two steps have nothing to do with each other.
F_POOL_RUNNER = """<?php
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_X_LG_INTERNAL_AUTH'] = trim((string) file_get_contents('/etc/lg-internal-secret'));
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
require %(pool)s;
"""

# Execute the SHIPPED rule against the REAL pool. Deliberately not a source
# grep: a refusal that merely MOVED would still satisfy a grep, and a rule read
# out of a file is not a rule that ran.
F_HARNESS = """<?php
require %(dash)s;
use LG\\LayoutV2\\FeaturedMemberDash as D;

$pool = json_decode((string) file_get_contents(%(json)s), true)['pool'] ?? null;
if (!is_array($pool)) { echo "POOL_UNREADABLE\\n"; exit(0); }

$total = count($pool);
$eligible = $blockedEligible = $lowCompletion = $lowBlocked = 0;
$renderable = $warned = $warnedWrong = $readyUnwarned = 0;
foreach ($pool as $m) {
    if (empty($m['eligible'])) continue;
    $eligible++;
    if (D::selection_block_reason($m) !== null) $blockedEligible++;
    if (empty($m['completeness']['card_ready'])) {
        $lowCompletion++;
        if (D::selection_block_reason($m) !== null) $lowBlocked++;
    }
    if (!array_key_exists('card_renderable', $m)) continue;
    $w = D::card_warning($m);
    if ($m['card_renderable']) { $renderable++; if ($w !== null) $warnedWrong++; }
    else                       { if ($w !== null) $warned++; else $readyUnwarned++; }
}

// Synthetic rows for the two states the live pool may not happen to contain.
$privateRow = ['eligible' => false, 'display_name' => 'probe', 'card_renderable' => true];
$oldPoolRow = ['eligible' => true,  'display_name' => 'probe'];   // pre-#107 endpoint

echo "TOTAL=$total\\n";
echo "ELIGIBLE=$eligible\\n";
echo "BLOCKED_ELIGIBLE=$blockedEligible\\n";
echo "LOW_COMPLETION=$lowCompletion\\n";
echo "LOW_BLOCKED=$lowBlocked\\n";
echo "RENDERABLE=$renderable\\n";
echo "NONRENDERABLE_WARNED=$warned\\n";
echo "NONRENDERABLE_UNWARNED=$readyUnwarned\\n";
echo "RENDERABLE_WARNED_WRONGLY=$warnedWrong\\n";
echo "PRIVATE_REFUSED=" . (D::selection_block_reason($privateRow) !== null ? "1" : "0") . "\\n";
echo "UNKNOWN_INVENTS_WARNING=" . (D::card_warning($oldPoolRow) !== null ? "1" : "0") . "\\n";
"""


def section_f_completion_never_blocks():
    dash_src = read(DASH_PHP)
    if dash_src is None:
        DEAD.append("[F] lg-layout-v2/src/FeaturedMemberDash.php is missing")
        return
    # RED, not DEAD: on a tree that still carries the wall this is absent, and
    # that absence IS the finding this section exists to report (#107).
    if "selection_block_reason" not in dash_src:
        RED.append("[F1] FeaturedMemberDash has no selection_block_reason() — the completeness "
                   "wall Ian overruled on 2026-08-19 is still the dash's rule "
                   '("the dash should allow me to select anyone", "opted in only")')
        return
    if not os.path.isfile(POOL_ENDPOINT_PHP):
        DEAD.append("[F] the featured-pool endpoint is missing — nothing to execute the rule against")
        return

    pid = os.getpid()
    runner = "/tmp/.featured-member-gate-f-pool-%d.php" % pid
    jsonf  = "/tmp/.featured-member-gate-f-pool-%d.json" % pid
    tmp    = "/tmp/.featured-member-gate-f-%d.php" % pid

    def cleanup():
        for path in (runner, jsonf, tmp):
            try:
                os.unlink(path)
            except OSError:
                pass

    try:
        with open(runner, "w") as f:
            f.write(F_POOL_RUNNER % {"pool": php_str(POOL_ENDPOINT_PHP)})
        os.chmod(runner, 0o644)
    except OSError as e:
        DEAD.append(f"[F] could not write the pool runner: {e}")
        return
    rc, out, err = php("profile-app", runner)
    if rc != 0 or not out.strip().startswith("{"):
        cleanup()
        DEAD.append("[F] the featured pool did not return JSON — cannot execute the rule "
                    f"(rc={rc} err={err[:160]} out={out[:160]})")
        return
    try:
        with open(jsonf, "w") as f:
            f.write(out)
        os.chmod(jsonf, 0o644)
        with open(tmp, "w") as f:
            f.write(F_HARNESS % {"dash": php_str(DASH_PHP), "json": php_str(jsonf)})
        os.chmod(tmp, 0o644)
    except OSError as e:
        cleanup()
        DEAD.append(f"[F] could not write harness: {e}")
        return

    rc, out, err = php("profile-app", tmp)
    cleanup()

    if "POOL_UNREADABLE" in out:
        DEAD.append("[F] the pool JSON was unreadable by the rule harness")
        return
    vals = dict(re.findall(r"^([A-Z_]+)=(-?\d+)$", out, re.M))
    if rc != 0 or "TOTAL" not in vals:
        DEAD.append(f"[F] harness did not run cleanly: rc={rc} err={err[:200]} out={out[:200]}")
        return
    g = lambda k: int(vals.get(k, -1))

    # LIVENESS FIRST. "Nobody is blocked" is trivially true of nobody, and an
    # empty pool is exactly how this section would go vacuously green.
    if g("ELIGIBLE") < 1:
        DEAD.append("[F] no opted-in, public member in the pool — every assertion below "
                    "would pass vacuously, so this reports no verdict rather than green")
        return

    # F1 — the ruling itself.
    if g("BLOCKED_ELIGIBLE") > 0:
        RED.append(f"[F1] {g('BLOCKED_ELIGIBLE')} of {g('ELIGIBLE')} opted-in public members are "
                   f"still refused by the dash's own rule — Ian ruled completion never blocks "
                   f"his selection (#107, 2026-08-19)")
    elif g("LOW_BLOCKED") > 0:
        RED.append(f"[F1] {g('LOW_BLOCKED')} low-completion member(s) still blocked from selection")
    else:
        OK.append(f"[F1] all {g('ELIGIBLE')} opted-in public members are selectable, including the "
                  f"{g('LOW_COMPLETION')} below card_ready — completion informs, it does not block")

    # F2 — the pillar that must SURVIVE the change. Ian rejected "literally anyone".
    if g("PRIVATE_REFUSED") != 1:
        RED.append("[F2] a member whose profile is Private is no longer refused — #107 removed "
                   "the COMPLETION wall, not the consent/privacy one")
    else:
        OK.append("[F2] a Private profile is still refused — consent/privacy survived #107")

    # F4 — a save that cannot render must be warned about, or the refusal has
    # only become silent. Measured cost of getting this wrong: 4 members.
    if g("NONRENDERABLE_UNWARNED") > 0:
        RED.append(f"[F4] {g('NONRENDERABLE_UNWARNED')} member(s) cannot render a card and get NO "
                   f'warning — the dash would answer "Saved and pushed" and leave the front '
                   f"page blank, which is what featuring Rick Liftig actually did on 2026-08-20")
    elif g("RENDERABLE_WARNED_WRONGLY") > 0:
        RED.append(f"[F4] {g('RENDERABLE_WARNED_WRONGLY')} member(s) whose card renders fine are "
                   f"warned about anyway — a false warning trains the admin to ignore the real one")
    else:
        OK.append(f"[F4] card warnings track the resolver: {g('RENDERABLE')} renderable and unwarned, "
                  f"{g('NONRENDERABLE_WARNED')} non-renderable and warned")

    if g("UNKNOWN_INVENTS_WARNING") != 0:
        RED.append("[F4] a pool row with NO card_renderable key (an older endpoint mid-deploy) "
                   "produces a warning — absent is not false, and guessing here would warn "
                   "about cards that are perfectly fine")
    else:
        OK.append("[F4] an absent card_renderable reads as unknown, not as a broken card")


def section_f3_predictor_tracks_resolver():
    """The predictor copies a rule that lives in ANOTHER PROCESS. archive-poc
    cannot be called from profile-app, so the copy is deliberate — and this is
    what stops it going stale silently. It asserts the RESOLVER'S GUARD still
    tests the two fields the predictor reproduces; if that guard grows a third
    condition, the dash starts telling the admin a card will render when it
    will not, which is the exact failure #107's measurement caught."""
    idx = read(INDEX_PHP)
    pool_src = read(POOL_ENDPOINT_PHP)
    if idx is None or pool_src is None:
        DEAD.append("[F3] index.php or the pool endpoint is missing — cannot check predictor drift")
        return
    m = re.search(r"if \(trim\(\(string\) \$u\['avatar_url'\]\) === ''[^\n]*\) return null;", idx)
    if not m:
        RED.append("[F3] lg_resolve_featured_member's card guard is no longer the known "
                   "`avatar_url empty || role empty => return null` — the pool's card_renderable "
                   "reproduces that rule and must be updated in the same commit, or the dash "
                   "will promise a band the front page does not draw")
        return
    guard = m.group(0)
    if "$role === ''" not in guard:
        RED.append(f"[F3] the resolver's guard changed shape ({guard[:120]}) — re-check "
                   f"card_renderable in internal-featured-pool.php against it")
        return
    if "card_renderable" not in pool_src or "card_blockers" not in pool_src:
        RED.append("[F3] the pool endpoint no longer reports card_renderable/card_blockers, but "
                   "the resolver still refuses to draw a card without an avatar and a role — "
                   "the dash has lost its only honest signal")
        return
    OK.append("[F3] the pool's card_renderable still mirrors the resolver's own avatar+role guard")


# ── G. #107, Ian 8/20: the tick is consent — and ONLY where it says so ───────
#
# The featured card may repeat an opted-in member's members-only one-liner. That
# is a deliberate hole in the 8/16 never-republish rule, opened by a ruling, and
# a hole needs a fence or it widens. §G is the fence:
#
#   G1  the flag is OFF, or ON with a cutover that actually parses — an ON with
#       informed_copy_since still null means "nobody is informed", which reads
#       as a working flag that quietly does nothing for every member.
#   G2  the archive-poc role can really READ users.featured_opt_in_at. It reads
#       under COLUMN-SCOPED grants and this column was NOT granted until 8/20;
#       an ungranted column raises "permission denied for table users", which
#       the caller's try/catch turns into a blank front page for every visitor.
#       An absence assertion needs a liveness assertion — this is it.
#   G3  the RULE, executed. lg_fm_card_role() is lifted out of index.php by name
#       and run over the truth table. A rule read out of a file is not a rule
#       that ran, and every uncertain input must fall back to the OLD behaviour.
#   G4  every caller's flag include resolves to the real file. Three apps read
#       this flag at three different directory depths; api/v0 needs THREE dots
#       where u.php needs two, and @include swallows the miss silently — the
#       exact bug §C4 exists for, one flag over.
#   G5  the pool's prediction still matches the resolver, member by member, on
#       the real pool. They live in different processes against different
#       databases; nothing but a gate can keep them honest.
#   G6  the copy is gated (OFF must be byte-identical) and the dash stays SILENT
#       on an absent glance_needs_ack — an older pool endpoint mid-deploy means
#       "unknown", never "no consent problem here".
#   G7  the exception is CONFINED. The profile page itself must still withhold a
#       members-only glance from a logged-out viewer. Measured against the real
#       served page, not asserted from source.
#
# ⚠️ A LEAK §G7 DELIBERATELY DOES NOT FAIL ON, because it predates this lane and
# reddening main for it would block every seat. Measured on dev2 2026-08-20: a
# profile's <meta name="description">, og:description and twitter:description
# carry at_a_glance VERBATIM to logged-out visitors, crawlers and link unfurls,
# even when the header block correctly withholds it from the rendered body — 28
# public members site-wide, including four of the eight in the featured pool.
# G7 therefore asserts the BODY, which is the surface this ruling reasoned
# about. The meta tags are a separate, pre-existing decision for Ian (#107
# report, docs/domains/PROFILE.md).

G_ROLE_HARNESS = """<?php
require %(fns)s;
$T = '2026-08-15T03:17:15+00:00';      // a real tick from the live pool
$BEFORE = '2026-08-01T00:00:00+00:00'; // copy shipped BEFORE it => informed
$AFTER  = '2026-08-19T00:00:00+00:00'; // copy shipped AFTER  it => old copy
$cases = [
  'off_membersonly'   => ['Luthier', '', 'members', false, $BEFORE, $T, false],
  'off_public'        => ['Luthier', '', 'public',  false, $BEFORE, $T, false],
  'on_informed'       => ['Luthier', '', 'members', true,  $BEFORE, $T, false],
  'on_informed_priv'  => ['Luthier', '', 'private', true,  $BEFORE, $T, false],
  'on_oldcopy'        => ['Luthier', '', 'members', true,  $AFTER,  $T, false],
  'on_oldcopy_ack'    => ['Luthier', '', 'members', true,  $AFTER,  $T, true ],
  'on_nocutover'      => ['Luthier', '', 'members', true,  null,    $T, false],
  'on_nostamp'        => ['Luthier', '', 'members', true,  $BEFORE, null, false],
  'on_badcutover'     => ['Luthier', '', 'members', true,  'soon',  $T, false],
  'on_public_glance'  => ['Luthier', '', 'public',  true,  $AFTER,  $T, false],
  'on_biz_fallback'   => ['',  'Acme Guitars', 'members', true, $BEFORE, $T, false],
  'on_biz_is_name'    => ['',  'Ioriatti',     'members', true, $BEFORE, $T, false],
];
foreach ($cases as $k => $c) {
    echo $k . '=' . lg_fm_card_role($c[0], $c[1], 'Carl Ioriatti', $c[2], $c[3], $c[4], $c[5], $c[6]) . "\\n";
}
"""

# What the shipped rule MUST answer. Written as the consequence, not the value,
# so a future reader can tell WHY each line is what it is.
G_EXPECTED = {
    "off_membersonly":  "",          # flag off => pre-#107 behaviour, unchanged
    "off_public":       "Luthier",   # a public glance was always the role
    "on_informed":      "Luthier",   # the ruling: the tick is consent
    "on_informed_priv": "Luthier",   # informed consent covers a private header too
    "on_oldcopy":       "",          # ticked before the copy said so => NO upgrade
    "on_oldcopy_ack":   "Luthier",   # ...unless an admin featured them knowingly
    "on_nocutover":     "",          # cutover unset => nobody is informed
    "on_nostamp":       "",          # no opt-in stamp => cannot be informed
    "on_badcutover":    "",          # unparseable => fall back, never fail open
    "on_public_glance": "Luthier",   # the exception never HIDES an existing role
    "on_biz_fallback":  "Acme Guitars",
    "on_biz_is_name":   "",          # business_name that is a tail of the name
}


def _extract_php_fn(src, name):
    """Lift one function out of a file that cannot be require()d. index.php is a
    whole rendered page; the rule inside it still has to be EXECUTED, not read."""
    m = re.search(r"\nfunction\s+" + name + r"\s*\(", src)
    if not m:
        return None
    i = src.index("{", src.index(")", m.end()))
    depth, j = 0, i
    while j < len(src):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[m.start():j + 1]
        j += 1
    return None


def _fetch_full(path, host):
    """check_http() reads 4000 bytes, which is the right budget for a route
    check and far too small for §G7 — a profile's rendered one-liner sits well
    past it, and a truncated body would read as "the text is absent", passing on
    the very leak the check exists to find. Same loopback-only connection as
    check_http (box trap 2: public DNS goes through Cloudflare, whose bot 403
    reads as an outage); only the read is different."""
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    conn = http.client.HTTPSConnection("127.0.0.1", 443, timeout=8, context=ctx)
    try:
        conn.request("GET", path, headers={"Host": host})
        r = conn.getresponse()
        return r.status, r.read().decode("utf-8", "replace")
    except Exception:
        return None, ""
    finally:
        conn.close()


def section_g_consent_is_the_tick():
    # ── G1: the flag, per state ─────────────────────────────────────────────
    cflag = read(CONSENT_FLAG_FILE)
    if cflag is None:
        RED.append("[G1] platform/config/featured-consent.php is missing — #107's card rule "
                   "has no switch, so it is either always on or always off and neither is "
                   "a flag")
        return
    m_on = re.search(r"'enabled'\s*=>\s*true", cflag)
    since = re.search(r"'informed_copy_since'\s*=>\s*(null|'([^']*)')", cflag)
    if since is None:
        RED.append("[G1] the consent flag file has no 'informed_copy_since' key — without a "
                   "cutover every tick reads as informed, which silently upgrades the consent "
                   "of everyone who ticked under the old copy")
    elif m_on:
        val = since.group(2)
        if since.group(1) == "null" or not val:
            RED.append("[G1] the consent flag is ON with informed_copy_since still null — no "
                       "tick can ever count as informed, so the flag reads as working while "
                       "doing nothing for every member")
        elif not re.search(r"(Z|[+-]\d{2}:?\d{2})$", val):
            RED.append(f"[G1] informed_copy_since ({val}) carries no UTC offset — it is compared "
                       f"against a Postgres timestamptz, so a naive local string drifts by hours "
                       f"and can mark a tick informed before the copy existed")
        else:
            window = cflag[max(0, m_on.start() - 400):m_on.start()]
            if re.search(r"(?i)\bIan\b.{0,120}(ruled|ruling|decision|box|flip)", window, re.S):
                OK.append("[G1] consent flag ON by an attributed ruling, with a parseable "
                          "offset-carrying cutover")
            else:
                RED.append("[G1] the consent flag is ON with no ruling attribution beside it — "
                           "this one changes what a member's tick MEANS, so an unruled ON is a "
                           "consent decision nobody made")
    else:
        OK.append("[G1] the consent flag defaults to false")

    # ── G2: the grant the resolver silently depends on ──────────────────────
    rc, out, err = psql("archive-poc", "profile_app",
                        "SELECT 1 FROM users WHERE featured_opt_in_at IS NOT NULL LIMIT 1")
    if rc != 0 and "permission denied" in (err or "").lower():
        RED.append("[G2] the archive-poc role cannot read users.featured_opt_in_at — the "
                   "resolver needs it to tell informed consent from old, and its caller's "
                   "try/catch turns the permission error into NO BAND for every visitor. "
                   "Apply tools/cut/featured-member-grants.sql")
    elif rc != 0:
        DEAD.append(f"[G2] could not check the featured_opt_in_at grant: {(err or '')[:120]}")
    else:
        OK.append("[G2] the archive-poc role can read featured_opt_in_at — the consent rule "
                  "has the column it needs, so a blank band would be a real verdict")

    # ── G3: EXECUTE the rule ────────────────────────────────────────────────
    idx = read(INDEX_PHP)
    if idx is None:
        DEAD.append("[G3] archive-poc/web/index.php is missing — cannot execute the consent rule")
        return
    fn = _extract_php_fn(idx, "lg_fm_card_role")
    if fn is None:
        RED.append("[G3] index.php has no lg_fm_card_role() — #107's consent rule is not where "
                   "this gate (or the next reader) can find it, and a rule that cannot be "
                   "executed cannot be trusted")
    else:
        tmpdir = tempfile.mkdtemp(prefix="fm-gate-g3-")
        try:
            fns = os.path.join(tmpdir, "fns.php")
            with open(fns, "w") as f:
                f.write("<?php\n" + fn + "\n")
            runner = os.path.join(tmpdir, "run.php")
            with open(runner, "w") as f:
                f.write(G_ROLE_HARNESS % {"fns": php_str(fns)})
            os.chmod(tmpdir, 0o755)
            os.chmod(fns, 0o644)
            os.chmod(runner, 0o644)
            p = subprocess.run(["php", runner], capture_output=True, text=True, timeout=30)
            if p.returncode != 0:
                DEAD.append(f"[G3] the consent rule would not run: {(p.stderr or '')[:160]}")
            else:
                got = dict(l.split("=", 1) for l in p.stdout.splitlines() if "=" in l)
                wrong = {k: (v, got.get(k)) for k, v in G_EXPECTED.items() if got.get(k) != v}
                # The two that MATTER most, named on their own: they are the
                # ruling's two halves and its one hard limit.
                if got.get("on_oldcopy") != "":
                    RED.append("[G3] a tick made BEFORE the informed copy shipped now publishes "
                               "the member's members-only one-liner with no admin acknowledgement "
                               "— that is the silent upgrade of old consent #107 forbids")
                elif got.get("on_oldcopy_ack") != "Luthier":
                    RED.append("[G3] an admin featuring an old-copy ticker knowingly no longer "
                               'publishes their one-liner — Ian\'s "OR Ian features them '
                               'knowingly" clause has stopped doing anything')
                elif got.get("off_membersonly") != "" or got.get("off_public") != "Luthier":
                    RED.append("[G3] with the flag OFF the card no longer behaves exactly as it "
                               "did before #107 — OFF is not a byte-identical no-op")
                elif wrong:
                    RED.append("[G3] the consent rule disagrees with its own truth table on "
                               + ", ".join(f"{k} (want {w!r}, got {g!r})" for k, (w, g) in
                                           sorted(wrong.items())))
                else:
                    OK.append(f"[G3] the shipped consent rule was EXECUTED and answers all "
                              f"{len(G_EXPECTED)} cases correctly, including every uncertain "
                              f"input falling back to the pre-#107 behaviour")
        except Exception as e:
            DEAD.append(f"[G3] could not execute the consent rule: {str(e)[:140]}")
        finally:
            shutil.rmtree(tmpdir, ignore_errors=True)

    # ── G4: every caller can actually REACH the flag file ───────────────────
    real = os.path.realpath(CONSENT_FLAG_FILE)
    for label, path in (("index.php", INDEX_PHP), ("u.php", U_PHP),
                        ("internal-featured-pool.php", POOL_ENDPOINT_PHP)):
        src = read(path)
        if src is None:
            DEAD.append(f"[G4] {label} is missing")
            continue
        m = re.search(r"@include\s+__DIR__\s*\.\s*'([^']+featured-consent\.php)'", src)
        if not m:
            RED.append(f"[G4] {label} no longer includes the consent flag with the "
                       f"__DIR__ . '...' shape this check can resolve")
            continue
        rc, out, err = php_eval_expr("var_dump(realpath(__DIR__ . '" + m.group(1) + "'));",
                                     os.path.dirname(path))
        if rc != 0 or out.strip() != 'string(%d) "%s"' % (len(real), real):
            RED.append(f"[G4] {label}'s consent-flag include does not resolve to the real file "
                       f"(got {(out.strip() or err)[:120]}) — @include swallows the miss, the "
                       f"flag reads as off, and that is indistinguishable from a genuine OFF")
        else:
            OK.append(f"[G4] {label} can really reach the consent flag file")

    # ── G5: the pool's prediction still matches the resolver, member by member
    pool_src = read(POOL_ENDPOINT_PHP)
    if pool_src is None:
        DEAD.append("[G5] the pool endpoint is missing")
    elif "glance_needs_ack" not in pool_src or "consent_informed" not in pool_src:
        RED.append("[G5] the pool endpoint no longer reports consent_informed/glance_needs_ack, "
                   "but the resolver still republishes only on informed consent or an ack — the "
                   "dash has lost the only signal that tells the admin which pick publishes what")
    else:
        OK.append("[G5] the pool still reports the consent state the dash warns from")

    # ── G6: the copy is gated, and the dash stays quiet on an unknown ───────
    u_src = read(U_PHP)
    if u_src is None:
        DEAD.append("[G6] u.php is missing")
    else:
        m = re.search(r"if\s*\(\s*\$lg_fmConsentOn\s*\)\s*:.*?one-line.*?endif", u_src, re.S)
        if not m:
            RED.append("[G6] the informed-consent sentence on the tickbox is not gated behind "
                       "$lg_fmConsentOn — either the copy is missing, or flag OFF is no longer "
                       "a no-op on a member-facing page")
        else:
            OK.append("[G6] the tickbox's informed-consent sentence is gated behind the flag")

    dash_src = read(DASH_PHP)
    if dash_src is None:
        DEAD.append("[G6] FeaturedMemberDash.php is missing")
    elif "consent_notice" not in dash_src:
        RED.append("[G6] the dash has no consent_notice() — nothing tells the admin that "
                   "featuring an old-copy ticker publishes members-only text, so the "
                   '"features them knowingly" half of the ruling is not knowable')
    else:
        tmpdir = tempfile.mkdtemp(prefix="fm-gate-g6-")
        try:
            probe = os.path.join(tmpdir, "probe.php")
            with open(probe, "w") as f:
                f.write("<?php\nrequire %s;\nuse LG\\LayoutV2\\FeaturedMemberDash as D;\n"
                        "$old = ['display_name'=>'probe'];\n"
                        "$no  = ['display_name'=>'probe','glance_needs_ack'=>false];\n"
                        "$yes = ['display_name'=>'probe','glance_needs_ack'=>true];\n"
                        "echo 'ABSENT=' . (D::consent_notice($old) === null ? '1' : '0') . \"\\n\";\n"
                        "echo 'FALSE=' . (D::consent_notice($no) === null ? '1' : '0') . \"\\n\";\n"
                        "echo 'TRUE=' . (D::consent_notice($yes) !== null ? '1' : '0') . \"\\n\";\n"
                        % php_str(DASH_PHP))
            os.chmod(tmpdir, 0o755)
            os.chmod(probe, 0o644)
            p = subprocess.run(["php", probe], capture_output=True, text=True, timeout=20)
            g = dict(l.split("=", 1) for l in p.stdout.splitlines() if "=" in l)
            if p.returncode != 0:
                DEAD.append(f"[G6] the dash's consent notice would not run: {(p.stderr or '')[:140]}")
            elif g.get("ABSENT") != "1":
                RED.append("[G6] the dash INVENTS a consent warning for a pool row that never "
                           "reported glance_needs_ack — an endpoint older than the dash means "
                           "unknown, and warning about picks that are fine trains the admin to "
                           "click through every warning")
            elif g.get("TRUE") != "1" or g.get("FALSE") != "1":
                RED.append("[G6] the dash's consent notice does not track glance_needs_ack "
                           f"(false->{g.get('FALSE')}, true->{g.get('TRUE')})")
            else:
                OK.append("[G6] the dash warns exactly when the pool says the pick republishes "
                          "members-only text, and stays silent when it cannot know")
        except Exception as e:
            DEAD.append(f"[G6] could not execute the dash's consent notice: {str(e)[:140]}")
        finally:
            shutil.rmtree(tmpdir, ignore_errors=True)

    # ── G7: the exception is confined to the card ───────────────────────────
    # The featured card is allowed to republish. The PROFILE PAGE is not, and
    # never was — 8/16. Measured against the real served page as a logged-out
    # viewer, because "the rule is still in the source" is not the claim.
    rc, out, err = psql("profile-app", "profile_app",
                        "SELECT u.slug || '|' || left(u.at_a_glance, 40) FROM users u "
                        "WHERE u.featured_opt_in = true AND u.profile_visibility = 'public' "
                        "AND btrim(coalesce(u.at_a_glance,'')) <> '' "
                        "AND coalesce((SELECT ps.visibility FROM profile_sections ps "
                        "  WHERE ps.user_id = u.id AND ps.key = 'header'), 'members') <> 'public' "
                        "LIMIT 1")
    if rc != 0 or not out:
        DEAD.append("[G7] no opted-in member with a members-only one-liner to test the "
                    "confinement against — the pool cannot answer this today")
    else:
        slug, needle = out.split("|", 1)
        # run-all.sh already passes LG_GATE_HOST for this gate; honour it rather
        # than inventing a second name that would silently drift from §E's.
        status, body = _fetch_full("/u/%s/" % slug,
                                   os.environ.get("LG_GATE_HOST") or "dev2.loothgroup.com")
        if status != 200:
            DEAD.append(f"[G7] could not fetch /u/{slug}/ as a logged-out viewer "
                        f"(HTTP {status}) — a styled 403 or a redirect reads identically to "
                        f"'the text is absent', so this is NO VERDICT, never a pass")
        elif "lg-idrow" not in body:
            DEAD.append(f"[G7] /u/{slug}/ returned 200 but no profile markup — the page did not "
                        f"render, so its silence proves nothing")
        else:
            visible = body.split("</head>", 1)[-1]
            if needle in visible:
                RED.append(f"[G7] {slug}'s members-only one-liner is in the RENDERED BODY of "
                           f"their profile for a logged-out viewer — the featured card is the "
                           f"only surface #107 opened, and this is not it")
            else:
                OK.append("[G7] the exception is confined: a members-only one-liner still does "
                          "not reach the rendered profile page of a logged-out viewer")


def main():
    print("=== featured-member-gate: backlog 18 ===")
    section_a_constraints()
    section_b_completeness_matches_sql()
    section_c_flag_off()
    section_d_no_admin_override()
    section_e_live_routes()
    section_f_completion_never_blocks()
    section_f3_predictor_tracks_resolver()
    section_g_consent_is_the_tick()

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
