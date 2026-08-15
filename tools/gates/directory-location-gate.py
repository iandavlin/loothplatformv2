#!/usr/bin/env python3
"""
directory-location-gate — backlog 20 (Ian 8/12), member safety.

THE DEFECT, measured rather than described. The member directory printed
whatever precision each member's own dial allowed, so a member who set
"street" had their full street address and postcode rendered into a directory
ROW, a MAP PIN POPUP and the click-through STUB, to every anonymous visitor.
On live on 2026-08-15 that was 7 of the 37 anon-visible rows; 14 members
publish street precision to logged-in members. Ian's picture for it was member
"Luke" (WP 2091), who typed his home address into the field.

THE RULE: a LIST surface prints "City, Region" and nothing else, whatever the
row stores. A list is an index strangers scan, not the detail view the member
curated — so the member's OWN PROFILE PAGE is deliberately untouched and still
shows exactly what they chose.

WHAT IT ASSERTS, and why each one is here:

  A. THE RULE ITSELF, deterministically. Block::listLocationText() is driven
     over synthetic places, so this half can never go vacuous — it holds even
     on a box where every member has since fixed their own dial. The assertion
     that matters most is the NEGATIVE one: a row with no structured city or
     region must render the EMPTY STRING and must never fall back to its
     literal text. Block::coarseText() does make that fallback (its text-only
     rows are snapshot-migrated place names) and copying it here would have
     re-opened the whole defect for exactly the rows most likely to hold a
     hand-typed home address. An empty location line is a cosmetic loss; a
     printed home address is not.

  B. THE REAL ENDPOINT, on every list surface and BOTH audiences. The list
     pages, the map-pin feed and the single-slug click-through stub all run
     through the shipped file via directory-location-probe.php, anonymously
     and again with a minted member bearer. Under ON, no surface and no
     audience may emit a street-shaped string. This is the half that would
     have caught the original defect, and it is run against the branch the
     gate ships in, not against whatever /srv/profile-app points at.

  C. OFF IS INERT, AND PROVEN BY THE DEFECT ITSELF. Under OFF the street rows
     must still print their address. That reads backwards for a safety gate,
     and it is the point: it is the red-first picture, it proves the flag
     actually gates something, and per house doctrine it is what makes the
     OFF state a checked state rather than an unexamined one. A flag whose OFF
     path is never asserted is how a "no-op" quietly stops being one.
     Paired with a LIVENESS assertion, because "no addresses found" is also
     what a dead endpoint, an empty database and a 403 all look like — an
     absence assertion with nothing proving the machinery ran is vacuous.

  D. THE PIN DOES NOT MOVE, AND NOTHING ELSE DOES. Between OFF and ON, every
     row's lat/lng/zoom/kind and every non-location field must be byte-equal.
     The clamp is allowed to change one string. Pin precision is its own dial
     (users.location_pin_precision plus the anon coarsening in
     Profile::renderLocation), and silently overriding a control the member
     set somewhere else would be a second defect wearing the first one's fix.

  E. THE FLAG IS REAL: the tracked config file exists, still defaults to
     false, and the clamp in the endpoint is reachable ONLY behind a
     Flags::bool() read of it. Asserted per-state off the shipped source, so
     flipping the default needs no edit here.

Exit codes follow run-all.sh: 0 green, 1 red (an open defect), 2 no verdict.
DB and PHP run over passwordless sudo peer-auth (profile-app role), the same
way featured-member-gate.py does.
"""

import json
import os
import re
import subprocess
import sys

HERE     = os.path.dirname(os.path.abspath(__file__))
REPO     = os.path.dirname(os.path.dirname(HERE))   # <repo>/tools/gates -> <repo>
APP_ROOT = os.path.join(REPO, "profile-app")
PROBE    = os.path.join(HERE, "directory-location-probe.php")
CFG      = os.path.join(APP_ROOT, "config", "directory-location.php")
ENDPOINT = os.path.join(APP_ROOT, "api", "v0", "directory-members.php")
BLOCK    = os.path.join(APP_ROOT, "src", "Block.php")

OK, RED, DEAD = [], [], []

# A street-shaped string: leading house number ("380 Adams St"), or the " · "
# postcode tail the street branch appends. Both are things a City/Region label
# can never contain.
STREET_RE   = re.compile(r"^\s*\d+\s+\S")
POSTCODE_RE = re.compile(r"·\s*\S")


def php(state, query, token=None, timeout=90):
    """Run the real endpoint under a flag state. Returns (rc, parsed_json_or_None, err).

    The viewer bearer goes over STDIN, never a temp file — it is a real member's
    signed credential and /tmp is world-readable."""
    cmd = ["sudo", "-n", "-u", "profile-app", "php", PROBE, APP_ROOT, state, json.dumps(query)]
    if token:
        cmd.append("-")
    try:
        p = subprocess.run(cmd, input=(token or ""), capture_output=True, text=True, timeout=timeout)
    except Exception as e:                                    # noqa: BLE001
        return 1, None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return p.returncode, None, (p.stderr or p.stdout)[:300]
    try:
        return 0, json.loads(p.stdout), ""
    except Exception as e:                                    # noqa: BLE001
        return 1, None, f"unparseable output ({e}): {p.stdout[:200]}"


def psql(sql):
    """Passwordless sudo peer-auth read, same as featured-member-gate.py."""
    try:
        p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "profile_app",
                            "-A", "-t", "-c", sql],
                           capture_output=True, text=True, timeout=30)
    except Exception as e:                                    # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:200]
    return p.stdout.strip(), ""


def street_rows(payload):
    """[(slug, text)] for rows whose location text is street-shaped."""
    out = []
    for it in payload.get("items", []):
        t = (it.get("location") or {}).get("text") or ""
        if STREET_RE.match(t) or POSTCODE_RE.search(t):
            out.append((it.get("slug"), t))
    return out


def loc_pin(it):
    l = it.get("location") or {}
    return (l.get("lat"), l.get("lng"), l.get("zoom"), l.get("kind"))


# ── A. the rule, deterministically ───────────────────────────────────────────
A_CASES = [
    # (place, level, expected listLocationText, why)
    ({"city": "Bedford Hills", "region": "New York", "country": "USA", "lat": 41.2,
      "text": "380 Adams St, Bedford Hills, NY 10507, USA"},
     "city", "Bedford Hills, New York", "street row collapses to its structured label"),
    ({"city": "Goteborg", "region": "", "country": "Sweden", "lat": 57.7, "text": "Stegerholmsvagen 32"},
     "city", "Goteborg", "city with no region is still a valid label"),
    ({"city": "", "region": "West Virginia", "country": "USA", "lat": 37.8,
      "text": "232 Lee st N , Lewisburg, WV 24901"},
     "city", "West Virginia", "region alone is a valid label"),
    ({"city": "", "region": "", "country": "", "lat": 37.8, "text": "232 Lee st N , Lewisburg, WV 24901"},
     "city", "", "PINNED row with no structured labels renders NOTHING, never the verbatim text"),
    # The two live rows the precision downgrade alone cannot reach: no pin, so
    # Block::coarseText() would fall straight through to the literal address.
    ({"city": "", "region": "", "country": "", "lat": None,
      "text": "900 South 5th Street ste 103, Milwaukee, WI, USA"},
     "city", "", "TEXT-ONLY street address renders nothing — the hole a downgrade misses"),
    ({"city": "", "region": "", "country": "", "lat": None, "text": "2310 Ballan Road ANAKIE Vic 3213"},
     "city", "", "the second real text-only address on live renders nothing"),
    ({"city": "Lewisburg", "region": "West Virginia", "country": "USA", "lat": 37.8, "text": "x"},
     "state", "West Virginia, USA", "state level uses Region, Country — never City"),
]


def section_a_rule():
    script = [
        "<?php require %s . '/config.php';" % json.dumps(APP_ROOT),
        "use Looth\\ProfileApp\\Block;",
        "$cases = json_decode(file_get_contents('php://stdin'), true);",
        "$out = [];",
        "foreach ($cases as $c) { $out[] = Block::listLocationText($c[0], $c[1]); }",
        "echo json_encode($out);",
    ]
    path = f"/tmp/lg-dirloc-gate-{os.getpid()}-a.php"
    try:
        with open(path, "w") as fh:
            fh.write("\n".join(script))
        os.chmod(path, 0o644)
        p = subprocess.run(["sudo", "-n", "-u", "profile-app", "php", path],
                           input=json.dumps([[c[0], c[1]] for c in A_CASES]),
                           capture_output=True, text=True, timeout=60)
        if p.returncode != 0:
            DEAD.append(f"[A] could not run Block::listLocationText: {(p.stderr or p.stdout)[:200]}")
            return
        got = json.loads(p.stdout)
    except Exception as e:                                    # noqa: BLE001
        DEAD.append(f"[A] could not run Block::listLocationText: {type(e).__name__}: {e}")
        return
    finally:
        try:
            os.unlink(path)
        except OSError:
            pass

    for (place, level, want, why), have in zip(A_CASES, got):
        if have == want:
            OK.append(f"[A] {why} -> {have!r}")
        else:
            RED.append(f"[A] {why}: expected {want!r}, got {have!r}")


# ── B/C/D. the real endpoint, every surface, both audiences ──────────────────
SURFACES = [
    ("list p1", {"view": "list", "page": "1"}),
    ("list p2", {"view": "list", "page": "2"}),
    ("map",     {"view": "map"}),
]


def section_bcd():
    def mint(mode, label):
        try:
            p = subprocess.run(["sudo", "-n", "-u", "profile-app", "php", PROBE, APP_ROOT, mode, "{}"],
                               capture_output=True, text=True, timeout=60)
            if p.returncode == 0 and p.stdout.strip():
                return p.stdout.strip()
            DEAD.append(f"[B] could not mint a {label} bearer — that audience went untested: "
                        f"{(p.stderr or '').strip()[:120]}")
        except Exception as e:                                 # noqa: BLE001
            DEAD.append(f"[B] could not mint a {label} bearer — that audience went untested: {e}")
        return None

    # All three reachable audiences. 'owner' is covered inside the member and
    # admin runs: a viewer always appears in their own directory list, and
    # precisionForAudience() hands 'owner' the same 'street' that admin gets.
    member_tok = mint("mint", "member")
    admin_tok  = mint("mint-admin", "admin")
    audiences = [("anon", None)]
    if member_tok:
        audiences.append(("member", member_tok))
    if admin_tok:
        audiences.append(("admin", admin_tok))
    live_rows = 0
    off_street_total = 0

    for aud, tok in audiences:
        for label, query in SURFACES:
            rc_off, off, err_off = php("off", query, tok)
            rc_on,  on,  err_on  = php("on",  query, tok)
            if rc_off != 0 or rc_on != 0:
                DEAD.append(f"[B] {aud}/{label}: endpoint did not run (off={err_off[:80]} on={err_on[:80]})")
                continue

            live_rows += len(off.get("items", []))

            # B — ON must never emit a street-shaped string, on any surface.
            bad = street_rows(on)
            if bad:
                RED.append(f"[B] {aud}/{label}: flag ON still printed {len(bad)} street-shaped "
                           f"location(s): " + "; ".join(f"{s}={t!r}" for s, t in bad[:3]))
            else:
                OK.append(f"[B] {aud}/{label}: flag ON emitted no street-shaped location "
                          f"({len(on.get('items', []))} rows)")

            # C — OFF is inert: whatever the street rows were, they are still there.
            off_bad = street_rows(off)
            off_street_total += len(off_bad)
            if off_bad:
                OK.append(f"[C] {aud}/{label}: flag OFF still prints {len(off_bad)} address(es) "
                          f"— OFF is inert and the flag really gates this")
                # and ON must have coarsened exactly those rows, not blanked them
                on_by_slug = {i.get("slug"): (i.get("location") or {}).get("text") or ""
                              for i in on.get("items", [])}
                blanked = [s for s, _ in off_bad if not on_by_slug.get(s)]
                if blanked:
                    RED.append(f"[C] {aud}/{label}: flag ON BLANKED the location for {blanked} "
                               f"instead of coarsening it to a City, Region label")
                else:
                    OK.append(f"[C] {aud}/{label}: each coarsened row kept a real label "
                              f"({', '.join(on_by_slug[s] for s, _ in off_bad[:3])})")

            # D — the pin follows the text. A street row must come back COARSE
            # (a zoom-15 exact marker on a house is the address, just drawn
            # instead of spelled); every other row's pin must be untouched.
            o_by = {i.get("slug"): i for i in off.get("items", [])}
            n_by = {i.get("slug"): i for i in on.get("items", [])}
            exact_off = {s for s in o_by if (o_by[s].get("location") or {}).get("kind") == "exact"}

            def shown(it):
                l = it.get("location") or {}
                return bool(l) and not l.get("hidden")

            # The clamp may only SUBTRACT. A row OFF hid must stay hidden, and a
            # row OFF showed must stay present — only its precision may drop.
            appeared = [s for s in n_by if shown(n_by[s]) and s in o_by and not shown(o_by[s])]
            vanished = [s for s in o_by if shown(o_by[s]) and s in n_by and not shown(n_by[s])]
            if appeared:
                RED.append(f"[D] {aud}/{label}: flag ON gave a location to {appeared[:5]}, which "
                           f"OFF hid entirely — the clamp added something that was not there")
            elif vanished:
                RED.append(f"[D] {aud}/{label}: flag ON hid {vanished[:5]}, which OFF showed — "
                           f"the clamp is meant to coarsen a row, not remove it")
            else:
                OK.append(f"[D] {aud}/{label}: the same rows are shown under OFF and ON "
                          f"(the clamp only subtracts precision, never presence)")

            still_exact = [s for s in exact_off
                           if s in n_by and (n_by[s].get("location") or {}).get("kind") == "exact"]
            if still_exact:
                RED.append(f"[D] {aud}/{label}: flag ON left an EXACT pin on {still_exact[:5]} — "
                           f"the marker still sits on the street the text no longer prints")
            elif exact_off:
                OK.append(f"[D] {aud}/{label}: all {len(exact_off)} exact pin(s) coarsened under ON")

            drifted_pin = [s for s in o_by
                           if s in n_by and s not in exact_off and loc_pin(o_by[s]) != loc_pin(n_by[s])]
            if drifted_pin:
                RED.append(f"[D] {aud}/{label}: the pin moved for already-coarse row(s) "
                           f"{drifted_pin[:5]} — the clamp may only ever subtract precision")
            else:
                OK.append(f"[D] {aud}/{label}: no already-coarse pin moved between OFF and ON")

            def strip(i):
                return {k: v for k, v in i.items() if k != "location"}

            drifted = [s for s in o_by if s in n_by and strip(o_by[s]) != strip(n_by[s])]
            if drifted:
                RED.append(f"[D] {aud}/{label}: non-location fields changed for {drifted[:5]}")
            else:
                OK.append(f"[D] {aud}/{label}: no non-location field changed between OFF and ON")

    # The liveness assertion. Everything above is an ABSENCE claim, and a dead
    # endpoint, an empty table or a 403 would satisfy all of them.
    if live_rows == 0:
        DEAD.append("[B] the endpoint returned NO rows on any surface — every 'no address "
                    "found' above is vacuous, so this gate reached no verdict")
        return
    OK.append(f"[B] liveness: the endpoint really answered ({live_rows} rows across surfaces)")

    # C, asserted rather than merely observed. Without this, a clamp that stopped
    # reading the flag and started running unconditionally would simply make the
    # OFF observations above go quiet, and the gate would stay green while the
    # OFF state silently stopped being a no-op — the exact failure doctrine calls
    # out. So: ask the DATABASE whether a defect picture is available on this box,
    # and if one is, OFF is REQUIRED to still print it.
    n, err = psql("SELECT count(*) FROM users WHERE location_public_precision = 'street' "
                  "AND coalesce(location_text,'') <> '';")
    if n is None:
        DEAD.append(f"[C] could not ask the database whether a defect picture exists here: {err}")
    elif int(n or 0) == 0:
        OK.append("[C] this box has no street-precision row, so there is no red-first picture "
                  "to re-photograph; section A still proves the rule deterministically")
    elif off_street_total == 0:
        RED.append(f"[C] the database holds {n} street-precision row(s), but flag OFF printed "
                   f"NO address on any surface. Either the clamp is running with the flag OFF "
                   f"(the OFF state is no longer a no-op) or these rows stopped reaching the "
                   f"directory — both make every ON assertion above vacuous.")
    else:
        OK.append(f"[C] red-first confirmed against the database: {n} street-precision row(s) "
                  f"exist and flag OFF really printed {off_street_total} address(es)")


# ── E. the flag is real and the clamp is behind it ───────────────────────────
def section_e_flag():
    if not os.path.isfile(CFG):
        RED.append(f"[E] the tracked flag file is MISSING: {CFG}")
        return
    cfg = open(CFG, encoding="utf-8").read()
    if re.search(r"'coarsen_list_location'\s*=>\s*false", cfg):
        OK.append("[E] the tracked flag still defaults to false")
    elif re.search(r"'coarsen_list_location'\s*=>\s*true", cfg):
        OK.append("[E] the tracked flag is switched ON (a state, not a finding)")
    else:
        RED.append("[E] config/directory-location.php declares no readable "
                   "'coarsen_list_location' boolean")

    src = open(ENDPOINT, encoding="utf-8").read()
    if "Flags::bool('directory-location', 'coarsen_list_location')" not in src:
        RED.append("[E] the directory endpoint no longer reads the flag — the clamp is either "
                   "gone or is running UNGATED")
    else:
        OK.append("[E] the clamp in the directory endpoint is reachable only behind the flag")

    if "listLocationText" not in open(BLOCK, encoding="utf-8").read():
        RED.append("[E] Block::listLocationText is gone — the list-safe label rule has no home")
    else:
        OK.append("[E] Block::listLocationText is present as the single list-safe label rule")


def main():
    print("=== directory-location-gate: backlog 20 (list surfaces print City/Region only) ===")
    if not os.path.isfile(PROBE):
        print(f"directory-location-gate: NO VERDICT — probe missing at {PROBE}")
        return 2
    section_a_rule()
    section_bcd()
    section_e_flag()

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"directory-location-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"directory-location-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"directory-location-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
