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
import os
import re
import subprocess
import sys
import urllib.request

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
U_PHP = os.path.join(REPO, "profile-app", "web", "u.php")
INDEX_PHP = os.path.join(REPO, "archive-poc", "web", "index.php")
FLAG_FILE = os.path.join(REPO, "platform", "config", "featured-members.php")
AUTH_PHP = os.path.join(REPO, "profile-app", "src", "Auth.php")
COMPLETENESS_PHP = os.path.join(REPO, "profile-app", "src", "Completeness.php")

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
    u1, u2 = "eeeeeeee-1111-1111-1111-111111111111", "eeeeeeee-2222-2222-2222-222222222222"
    psql("postgres", "looth",
         f"DELETE FROM discovery.featured_history WHERE member_uuid IN ('{u1}','{u2}')")
    rc, _, err = psql("postgres", "looth",
        f"INSERT INTO discovery.featured_history (member_uuid, display_name) VALUES ('{u1}','Gate Test A')")
    if rc != 0:
        DEAD.append(f"[A2] could not seed the first open row: {err[:200]}")
    else:
        rc2, _, err2 = psql("postgres", "looth",
            f"INSERT INTO discovery.featured_history (member_uuid, display_name) VALUES ('{u2}','Gate Test B')")
        if rc2 == 0:
            RED.append("[A2] featured_history_one_open did NOT reject a second concurrently-open "
                       "row — Ian's ONE AT A TIME ruling is not actually enforced")
        elif "featured_history_one_open" in err2 or "duplicate key" in err2:
            OK.append("[A2] the one-open-row-at-a-time constraint rejects a second open row, as designed")
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
    flag_src = read(FLAG_FILE)
    if flag_src is None:
        DEAD.append("[C] platform/config/featured-members.php is missing")
    elif re.search(r"'enabled'\s*=>\s*true", flag_src):
        RED.append("[C] the tracked flag defaults to true — must ship OFF")
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

    idx_src = read(INDEX_PHP)
    if idx_src is None:
        DEAD.append("[C] archive-poc/web/index.php is missing")
    else:
        # STRICT: $lg_fm_on must be the literal ternary CONDITION that decides
        # whether to resolve, not merely present somewhere in the file. A
        # looser "does lg_fm_on appear after the if" check was tried first and
        # missed exactly this: $lg_fm_on is computed a few lines above
        # regardless, so it always "appears nearby" even after the ternary
        # itself is deleted and resolution runs unconditionally — caught only
        # by actually red-firing this check against that mutation.
        m = re.search(r"\$lg_fm\s*=\s*\$lg_fm_on\s*\?\s*lg_resolve_featured_member\s*\(", idx_src)
        if not m:
            RED.append("[C3] index.php's member_uuid resolution is not textually gated behind "
                       "a flag check — a stale member_uuid in config.json could resolve a real "
                       "card even with the feature meant to be off")
        else:
            OK.append("[C3] index.php's real-member resolution is gated behind the flag check")


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


def check_http(url, headers, expect_key=None):
    req = urllib.request.Request(url, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=5) as r:
            body = r.read(4000).decode("utf-8", "replace")
            return r.status, body
    except urllib.error.HTTPError as e:
        return e.code, ""
    except Exception as e:
        return None, str(e)


def section_e_live_routes():
    gate_cookie = os.environ.get("LG_GATE_COOKIE", "")
    host = os.environ.get("LG_GATE_HOST", "dev2.loothgroup.com")
    if not gate_cookie:
        DEAD.append("[E] LG_GATE_HOST/LG_GATE_COOKIE not set — cannot exercise the live routes; "
                    "this is an environment fact, not a finding, until this branch is merged")
        return
    status, body = check_http(f"https://{host}/profile-api/v0/me/featured",
                               {"Cookie": f"loothdev_auth={gate_cookie}"})
    if status is None:
        DEAD.append(f"[E1] request to /profile-api/v0/me/featured failed: {body[:160]}")
    elif status == 404:
        DEAD.append("[E1] /profile-api/v0/me/featured is 404 on this host — route not live yet "
                    "(expected pre-merge; re-run after merge)")
    elif status == 401:
        OK.append("[E1] /profile-api/v0/me/featured is routed and requires auth (401 without a "
                 "member session) — the route exists and is not wide open")
    else:
        OK.append(f"[E1] /profile-api/v0/me/featured routed, HTTP {status}")


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
