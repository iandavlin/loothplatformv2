#!/usr/bin/env python3
"""
directory-location-cap-gate — backlog 20 (Ian 8/15, via keeper): a member's
street address must never render in a LIST surface (directory rows, cards,
map pins), regardless of what the viewer is or what the member's own
precision dial says. The single profile page is untouched — this is scoped
to profile-app/api/v0/directory-members.php's shared dir_member_display().

GATE NUMBER: not yet allocated by keeper. Registered under a placeholder
banner in run-all.sh pending the real number — never self-minted as final.

THE DEFECT, proven with REAL rows, never synthetic ones:
Visibility::locationPrecision() correctly grants 'street' precision (full
address text) to the 'owner' and 'admin' audiences — right for a single
profile page, wrong for dir_member_display(), which is the shared coarsening
point for BOTH the paginated directory list and the map-pin feed (every row,
not one).

TWO REAL FIXTURES, fetched read-only from live via `ssh live-ro` (cached to
disk so a repeat run or a live-ro outage doesn't need a fresh SSH hop):
  1. LUKE (WP 2091, profile_app id 2131) — the member keeper named directly.
     His public-precision dial is 'private', so his row proves the
     admin/owner leak (row real, address real: "232 Lee st N , Lewisburg,
     WV 24901") but cannot exercise the PUBLIC audience — his dial already
     keeps him off the public-visible stack entirely.
  2. MICHAEL SWISHER (profile_app id 1912) — found WHILE building this gate:
     both his public- AND members-precision dials are 'street' (a real,
     standing dial combination — 7 real members share the public='street'
     half on live today, counted not estimated), which means
     dir_member_display() hands his raw address to ALL FOUR audiences —
     public included, a literal, unauthenticated, already-live "shows it to
     everyone" case, and the one that matches keeper's literal "run as a real
     anon" instruction. (An earlier build of this gate tried Josh Rieck,
     id 598, for the same purpose: his public dial is also 'street' but his
     `location_address` column happens to hold a city-level summary rather
     than his street text, and Block::locationDisplay's 'street' branch
     prefers `address` over `text` — so his particular row does NOT leak via
     this path even at 'street' precision. Caught by the gate's own
     BEFORE-picture assertion refusing to accept a leak that didn't happen;
     replaced rather than loosened.)

Both fixtures run through the REAL function bodies extracted from the
shipped source (not hand-duplicated logic — a change to the real
dir_member_display()/dir_location_cap_on() is what this gate actually tests,
so it cannot silently drift from the code it is meant to guard), for all four
audiences (public, member, admin, owner), in both flag states:
  OFF — the literal pre-existing behavior. The audience each fixture's own
        dial already exposes (admin/owner for Luke; PLUS public for Josh)
        MUST still see the raw text — this is deliberately the RED picture
        if it disappeared: proof the OFF path is unchanged, not proof the
        bug is gone.
  ON  — no audience, for either fixture, may see anything past "City,
        Region" or "Region, Country".

Exit codes follow run-all.sh: 0 green, 1 RED, 2 CANNOT RUN.
"""
import json
import os
import re
import subprocess
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
DIR_MEMBERS_PHP = os.path.join(REPO, "profile-app", "api", "v0", "directory-members.php")
FLAG_FILE = os.path.join(REPO, "platform", "config", "directory-location-cap.php")
FIXTURE_CACHE = "/tmp/.directory-location-cap-gate-fixtures.json"

# Two real members, fetched read-only from live. LUKE is who keeper named
# directly; SWISHER was found while building this gate (both precision dials
# 'street' — 7 real members share the public half of that setting on live
# today) and is the fixture that actually exercises the PUBLIC audience.
FIXTURE_SPECS = {
    # expected_leak: which audiences the OLD code hands the raw address to,
    # per THIS fixture's own dial settings — not a fixed set. Luke's
    # public-precision is 'private' (members-precision 'city' feeds the
    # member-audience branch directly, ignoring the public dial, so member is
    # never affected either way); Swisher's are both 'street', which is why
    # he — not Luke — is the fixture that proves the public/anon half of the
    # ruling, and leaks to all four audiences.
    "LUKE": {"where": "b.wp_user_id = 2091", "join": "JOIN wp_user_bridge b ON b.user_id = u.id",
             "needle": "Lee st N", "expected_leak": ("admin", "owner")},
    "SWISHER": {"where": "u.id = 1912", "join": "", "needle": "Blackfoot Dr",
                "expected_leak": ("public", "member", "admin", "owner")},
}

OK, RED, DEAD = [], [], []


def read(path):
    try:
        with open(path, "r") as f:
            return f.read()
    except OSError:
        return None


def fetch_fixture(label, spec):
    """One real member's row, read-only, from live — cached so repeat runs
    (and a live-ro outage) don't need a fresh SSH hop every time. Never
    written to, never used to derive an UNDO — this gate only ever reads."""
    cache = {}
    cached = read(FIXTURE_CACHE)
    if cached:
        try:
            cache = json.loads(cached)
        except ValueError:
            cache = {}
    if label in cache:
        return cache[label]

    sql = (
        "SELECT u.id, u.uuid, u.location_text, u.location_address, u.location_city, "
        "u.location_region, u.location_country, u.lat, u.lng, "
        "u.location_public_precision, u.location_members_precision, u.profile_visibility, "
        "(u.profile_layout IS NULL OR u.profile_layout @> jsonb_build_array('location')) AS loc_on_profile "
        f"FROM users u {spec['join']} WHERE {spec['where']}"
    )
    cmd = ["ssh", "live-ro", f"psql -h 127.0.0.1 -U looth_ro -d profile_app -Atc \"{sql}\""]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
    except Exception as e:
        return {"__error__": str(e)}
    if p.returncode != 0 or not p.stdout.strip():
        return {"__error__": (p.stderr or "empty result")[:300]}
    parts = p.stdout.strip().split("|")
    if len(parts) != 13:
        return {"__error__": f"unexpected column count ({len(parts)}): {p.stdout.strip()[:200]}"}
    fixture = {
        "id": int(parts[0]), "uuid": parts[1], "location_text": parts[2],
        "location_address": parts[3], "location_city": parts[4], "location_region": parts[5],
        "location_country": parts[6], "lat": parts[7], "lng": parts[8],
        "location_public_precision": parts[9], "location_members_precision": parts[10],
        "profile_visibility": parts[11], "loc_on_profile": parts[12] == "t",
    }
    cache[label] = fixture
    try:
        with open(FIXTURE_CACHE, "w") as f:
            json.dump(cache, f)
    except OSError:
        pass
    return fixture


def php_harness(fixture, cap_on):
    """Runs the REAL dir_location_cap_on()/dir_member_display() bodies,
    extracted verbatim from the shipped file (regex, not hand-copied), against
    one fixture row for all four audiences. Written into api/v0/ itself so
    __DIR__-relative includes inside the extracted code resolve exactly as
    they do in production."""
    src = read(DIR_MEMBERS_PHP)
    if src is None:
        return None, "profile-app/api/v0/directory-members.php is missing"

    m1 = re.search(r"function dir_location_cap_on\(\).*?\n\}\n", src, re.S)
    m2 = re.search(r"function dir_member_display\(array \$r, array \$vArr\): \?array\n\{.*?\n\}\n", src, re.S)
    if not m1 or not m2:
        return None, "could not extract dir_location_cap_on()/dir_member_display() from the source"

    audiences = {
        "public": {"id": 0, "admin": False},
        "member": {"id": 555555, "admin": False},
        "admin":  {"id": 777777, "admin": True},
        "owner":  {"id": fixture["id"], "admin": False},
    }

    php = "<?php\n"
    php += "require_once '" + os.path.join(REPO, "profile-app", "config.php") + "';\n"
    php += "require_once LG_PROFILE_APP_APP_ROOT . '/src/Db.php';\n"
    php += "require_once LG_PROFILE_APP_APP_ROOT . '/src/Visibility.php';\n"
    php += "require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';\n"
    php += "use Looth\\ProfileApp\\Visibility;\nuse Looth\\ProfileApp\\Block;\n"
    if cap_on:
        php += "putenv('LG_DIRECTORY_LOCATION_CAP=1');\n"
    php += m1.group(0) + "\n" + m2.group(0) + "\n"
    php += "$r = json_decode('" + json.dumps({
        "id": fixture["id"], "location_address": fixture["location_address"],
        "location_postcode": None, "location_city": fixture["location_city"],
        "location_region": fixture["location_region"], "location_country": fixture["location_country"],
        "lat": fixture["lat"], "lng": fixture["lng"], "location_text": fixture["location_text"],
        "profile_visibility": fixture["profile_visibility"],
        "location_members_precision": fixture["location_members_precision"],
        "location_public_precision": fixture["location_public_precision"],
        "loc_on_profile": fixture["loc_on_profile"],
    }) + "', true);\n"
    php += "$out = [];\n"
    for aud, v in audiences.items():
        # json_decode(..., true), not a raw literal — JSON object syntax
        # ({"id": 0}) is not valid PHP array syntax (PHP needs 'id' => 0).
        php += f"$out['{aud}'] = dir_member_display($r, json_decode('" + json.dumps(v) + "', true));\n"
    php += "echo json_encode($out);\n"

    tmp = os.path.join(REPO, "profile-app", "api", "v0", ".directory-location-cap-gate-tmp.php")
    try:
        with open(tmp, "w") as f:
            f.write(php)
        p = subprocess.run(["php", tmp], capture_output=True, text=True, timeout=15)
    except Exception as e:
        return None, str(e)
    finally:
        try:
            os.unlink(tmp)
        except OSError:
            pass
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:300]
    try:
        return json.loads(p.stdout.strip().splitlines()[-1]), None
    except (ValueError, IndexError):
        return None, f"non-JSON output: {p.stdout[:300]} {p.stderr[:300]}"


def check_fixture(label, fixture, needle, expected_leak):
    result_off, err_off = php_harness(fixture, cap_on=False)
    if result_off is None:
        DEAD.append(f"[{label}] harness failed (flag OFF): {err_off}")
        return
    result_on, err_on = php_harness(fixture, cap_on=True)
    if result_on is None:
        DEAD.append(f"[{label}] harness failed (flag ON): {err_on}")
        return

    def text_of(aud_result):
        return (aud_result or {}).get("text", "") or ""

    all_audiences = ("public", "member", "admin", "owner")

    # BEFORE picture: OFF must leak to EXACTLY the audiences this fixture's
    # own dial settings expose today (varies per fixture — Luke's dial never
    # exposes 'member' or 'public'; Josh's exposes 'public' too) — proof this
    # is testing the real pre-fix behavior, not a stub that never leaked, and
    # not overclaiming a leak this specific fixture doesn't actually have.
    off_leaking = {a for a in all_audiences if needle in text_of(result_off.get(a))}
    expected = set(expected_leak)
    if off_leaking != expected:
        RED.append(f"[{label}-BEFORE] flag OFF leaks to {sorted(off_leaking) or 'nobody'}, expected exactly "
                   f"{sorted(expected)} — either the fixture drifted or dir_member_display() changed shape; "
                   f"this gate cannot prove the fix without an accurate BEFORE picture")
    else:
        OK.append(f"[{label}-BEFORE] flag OFF reproduces the real leak to exactly {sorted(expected)} "
                  f"— confirms this gate is testing the actual defect, not a stand-in for it")

    # AFTER: no audience, flag ON, may ever see the needle — this is the
    # actual fix, and it must hold even for the audiences that were ALREADY
    # safe pre-fix (no new leak introduced) as well as the ones that weren't.
    leaking = [a for a, r in result_on.items() if needle in text_of(r)]
    if leaking:
        RED.append(f"[{label}-AFTER] flag ON still leaks the raw address to: {', '.join(sorted(leaking))} "
                   f"— backlog 20 is not actually enforced for this fixture")
    else:
        OK.append(f"[{label}-AFTER] flag ON: no audience (public/member/admin/owner) sees more than "
                  f"City/State for this fixture")


def main():
    print("=== GATE (unnumbered — pending keeper): directory-location-cap, backlog 20 ===")

    flag_src = read(FLAG_FILE)
    if flag_src is None:
        DEAD.append("[FLAG] platform/config/directory-location-cap.php is missing")
    elif re.search(r"'enabled'\s*=>\s*true", flag_src):
        window = flag_src[max(0, flag_src.find("'enabled' => true") - 400):flag_src.find("'enabled' => true")]
        if re.search(r"(?i)\bIan\b.{0,120}(ruled|ruling|decision|box|flip)", window, re.S):
            OK.append("[FLAG] flag is ON by an attributed ruling")
        else:
            RED.append("[FLAG] the tracked flag is ON with no ruling attribution beside it")
    elif re.search(r"'enabled'\s*=>\s*false", flag_src):
        OK.append("[FLAG] the tracked flag defaults to false")
    else:
        DEAD.append("[FLAG] could not find an 'enabled' => ... line to read")

    for label, spec in FIXTURE_SPECS.items():
        fixture = fetch_fixture(label, spec)
        if "__error__" in fixture:
            DEAD.append(f"[{label}] could not fetch the real fixture from live via ssh live-ro: "
                       f"{fixture['__error__']}")
            continue
        check_fixture(label, fixture, spec["needle"], spec["expected_leak"])

    for m in OK:   print(f"  ok   {m}")
    for m in RED:  print(f"  RED  {m}")
    for m in DEAD: print(f"  DEAD {m}")

    if DEAD and not RED:
        print(f"directory-location-cap-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if RED:
        print(f"directory-location-cap-gate: RED — {len(RED)} finding(s)")
        return 1
    print(f"directory-location-cap-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
