#!/usr/bin/env python3
"""
notif-type-filter-gate — backlog 11.6 (Ian 8/1): filter the bell by type, and
clear one type at once.

THE PROPERTY THIS EXISTS TO HOLD, in the spec's own words: "gate the bulk action
affects ONLY the selected type and ONLY that member." Both halves are asserted
against the REAL endpoint with a REAL second member present, because "only that
member" cannot be tested with one account.

WHAT IT ASSERTS, and why each is here:

  A. FLAG OFF IS INERT. ?type= is ignored (full list back, no `counts` key) and a
     typed DELETE clears NOTHING. That reads backwards for a feature gate and it
     is the point: it proves the flag really gates, and it keeps OFF a checked
     state rather than an unexamined one.

  B. ON NARROWS, and an UNKNOWN type is REFUSED rather than silently widened to
     the whole list. A member who believes they are looking at one type while
     shown everything is one tap from clearing everything.

  C. THE BULK CLEAR IS TYPE-SCOPED AND OWNER-SCOPED. After clearing one type:
     that type is gone, the member's OTHER type is untouched, and the BYSTANDER's
     row is untouched. The third is the one that matters most and the one a
     single-account test cannot see.

  D. TYPE + ALL TOGETHER TAKES THE TYPE BRANCH. A client sending both must not be
     serviced by clear-everything — that is the one destructive mistake this
     endpoint could make, so it is asserted rather than trusted to ordering.

  E. THE CODE'S TYPE LIST STILL EQUALS THE DATABASE CONSTRAINT. A type added to
     the schema and not to FILTER_TYPES would be invisible to the filter and so
     unclearable — the member would see rows they had no way to sweep.

  F. OFF IS INERT ON THE CLIENT TOO. Both surfaces open their renderer with a
     guard on `counts`, which the server sends only while the flag is on, so a
     cached client cannot grow chips on its own.

The probe member is PER-RUN and PID-keyed, and setup REPAIRS ON ENTRY as well as
tearing down on exit — a run killed half-way (a reboot did exactly that tonight)
must not leave probe members in the real member directory.

Exit codes follow run-all.sh: 0 green, 1 red, 2 no verdict.
"""

import json
import os
import re
import subprocess
import sys

HERE  = os.path.dirname(os.path.abspath(__file__))
REPO  = os.path.dirname(os.path.dirname(HERE))
PROBE = os.path.join(HERE, "notif-type-filter-probe.php")
PID   = os.getpid()

OK, RED, DEAD = [], [], []


def php(*args, timeout=60):
    try:
        p = subprocess.run(["sudo", "-n", "-u", "profile-app", "php", PROBE, REPO, *args],
                           capture_output=True, text=True, timeout=timeout)
    except Exception as e:                                    # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:220]
    return p.stdout.strip(), ""


def call(state, method, query, uuid):
    """Drive the real endpoint. Returns (parsed, err). Non-JSON is an error."""
    out, err = php(state, method, json.dumps(query), uuid)
    if out is None:
        return None, err
    try:
        return json.loads(out), ""
    except Exception as e:                                    # noqa: BLE001
        return None, f"unparseable ({e}): {out[:160]}"


def psql(sql):
    try:
        p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "profile_app",
                            "-A", "-t", "-c", sql], capture_output=True, text=True, timeout=30)
    except Exception as e:                                    # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    return (p.stdout.strip(), "") if p.returncode == 0 else (None, (p.stderr or p.stdout)[:200])


def count(uuid, typ=None):
    sql = f"SELECT count(*) FROM notifications WHERE user_uuid = '{uuid}'"
    if typ:
        sql += f" AND type = '{typ}'"
    out, err = psql(sql + ";")
    return int(out) if out is not None and out.isdigit() else -1


def main():
    print("=== GATE 57: notif-type-filter — backlog 11.6 (filter by type, clear a type) ===")
    if not os.path.isfile(PROBE):
        print(f"notif-type-filter-gate: NO VERDICT — probe missing at {PROBE}")
        return 2

    out, err = php("setup", str(PID))
    if out is None:
        print(f"notif-type-filter-gate: NO VERDICT — probe setup failed: {err}")
        return 2
    try:
        ids = json.loads(out)
        me, bystander = ids["me"], ids["bystander"]
    except Exception as e:                                    # noqa: BLE001
        print(f"notif-type-filter-gate: NO VERDICT — bad setup payload ({e}): {out[:120]}")
        return 2

    try:
        # ── A. flag OFF is inert ────────────────────────────────────────────
        d, e1 = call("off", "GET", {"type": "connection_accept"}, me)
        if d is None:
            DEAD.append(f"[A] GET(off) did not run: {e1}")
        else:
            n = len([i for i in d.get("items", []) if i.get("type") != "message"])
            if "counts" in d:
                RED.append("[A] flag OFF sent `counts` — the client would grow chips on its own")
            elif n != 5:
                RED.append(f"[A] flag OFF honoured ?type= — got {n} rows, expected the full 5")
            else:
                OK.append("[A] flag OFF ignores ?type= (full 5 rows) and sends no `counts`")

        before = count(me)
        d, _ = call("off", "DELETE", {"type": "connection_accept"}, me)
        after = count(me)
        if after != before:
            RED.append(f"[A] flag OFF CLEARED rows on a typed DELETE ({before} -> {after})")
        elif not (d and d.get("error") == "id_or_all_required"):
            RED.append(f"[A] flag OFF typed DELETE did not refuse cleanly: {d}")
        else:
            OK.append("[A] flag OFF refuses a typed DELETE and clears nothing")

        # ── B. ON narrows; unknown is refused ───────────────────────────────
        d, e2 = call("on", "GET", {"type": "reaction.on_post"}, me)
        if d is None:
            DEAD.append(f"[B] GET(on) did not run: {e2}")
        else:
            n = len(d.get("items", []))
            if n == 2 and d.get("counts"):
                OK.append(f"[B] flag ON narrows to the named type (2 rows) and sends counts "
                          f"({len(d['counts'])} types)")
            else:
                RED.append(f"[B] flag ON did not narrow: {n} rows, counts={bool(d.get('counts'))}")

        d, _ = call("on", "GET", {"type": "not_a_type"}, me)
        if d and d.get("error") == "unknown_type":
            OK.append("[B] an unknown type is REFUSED, never widened to the whole list")
        else:
            RED.append(f"[B] unknown type was not refused — returned {str(d)[:120]}")

        # ── C. the bulk clear is type- and owner-scoped ─────────────────────
        d, e3 = call("on", "DELETE", {"type": "connection_accept"}, me)
        if d is None:
            DEAD.append(f"[C] typed DELETE did not run: {e3}")
        else:
            gone   = count(me, "connection_accept")
            kept   = count(me, "reaction.on_post")
            others = count(bystander)
            if gone != 0:
                RED.append(f"[C] the named type survived the clear ({gone} rows left)")
            elif kept != 2:
                RED.append(f"[C] the member's OTHER type was hit ({kept}/2 left) — not type-scoped")
            elif others != 1:
                RED.append(f"[C] THE BYSTANDER LOST ROWS ({others}/1 left) — NOT OWNER-SCOPED")
            else:
                OK.append(f"[C] cleared {d.get('deleted')} of the named type; the member's other "
                          f"type and the BYSTANDER's row both untouched")

        # ── D. type + all together must take the TYPE branch ────────────────
        d, _ = call("on", "DELETE", {"type": "reaction.on_post", "all": "1"}, me)
        if count(bystander) != 1:
            RED.append("[D] type+all was serviced by CLEAR-ALL — the bystander lost rows")
        elif count(me) != 0:
            RED.append(f"[D] type+all left {count(me)} rows — unexpected shape")
        else:
            OK.append("[D] a request sending BOTH type and all takes the type branch")

        # ── D2. IAN'S STANDING PRINCIPLE (2026-08-16) ───────────────────────
        # "no notifs for things people have actually interacted with." So neither
        # the bell nor the weekly digest may resurface a handled item — and the
        # digest must exclude them by its OWN clause, not merely because the row
        # happened to be deleted. Asserted against Recap::outstanding()'s real SQL
        # rather than a description of it, because that clause is the only thing
        # standing between a tidied bell and a spam email.
        rec = open(os.path.join(REPO, "profile-app/src/Recap.php"), encoding="utf-8").read()
        m = re.search(r"private static function outstanding\(\): string.*?\n    \}", rec, re.S)
        clause = m.group(0) if m else ""
        if not clause:
            DEAD.append("[D2] could not read Recap::outstanding() — the digest's exclusion clause")
        else:
            # EACH DISJUNCT, not a bare substring. The first draft of this checked
            # whether "n.is_read = false" appeared ANYWHERE in the clause — and it
            # appears twice, so deleting the guard from the hub-rows arm left the
            # gate green on a genuinely broken digest. Third time today a
            # presence-check matched something other than the thing under test.
            flat = re.sub(r"\s+", " ", clause)
            checks = [
                (r"n\.type = 'connection_request' AND c\.status = 'pending'",
                 "an ANSWERED connection request is excluded by its EDGE, not by whether the "
                 "bell was opened"),
                (r"n\.type = 'connection_accept' +AND c\.status = 'accepted' AND n\.is_read = false",
                 "an accepted-request notice is excluded once read"),
                (r"n\.connection_id IS NULL AND n\.is_read = false",
                 "a READ hub notification (reply/mention/reaction) is excluded — this is the arm "
                 "that keeps a tidied bell from mailing the member anyway"),
                (r"dismissed_at IS NULL",
                 "a CLEARED notification is excluded from the digest"),
            ]
            missing = [why for pat, why in checks if not re.search(pat, flat)]
            if missing:
                RED.append("[D2] the digest's outstanding() clause no longer guarantees: "
                           + "; ".join(missing) + " — Ian's rule is that a handled item never "
                           "comes back, and this clause is what enforces it")
            else:
                OK.append("[D2] the digest excludes cleared, read, and edge-answered items by its "
                          "own clause — a tidied bell cannot produce a spam email")

        # ── E. code list vs database constraint ─────────────────────────────
        src = open(os.path.join(REPO, "profile-app/src/Notifications.php"), encoding="utf-8").read()
        m = re.search(r"const FILTER_TYPES = \[(.*?)\];", src, re.S)
        code = set(re.findall(r"'([^']+)'", m.group(1))) if m else set()
        con, cerr = psql("SELECT pg_get_constraintdef(oid) FROM pg_constraint "
                         "WHERE conname='notifications_type_check';")
        db = set(re.findall(r"'([^']+)'::text", con)) if con else set()
        if not code or not db:
            DEAD.append(f"[E] could not read both lists (code={len(code)}, db={len(db)}) {cerr}")
        elif code == db:
            OK.append(f"[E] FILTER_TYPES still equals the database constraint ({len(db)} types)")
        else:
            RED.append(f"[E] FILTER_TYPES has drifted from the constraint — "
                       f"only in code {sorted(code - db)}, only in db {sorted(db - code)}")

        # ── F. OFF is inert on the client too ───────────────────────────────
        for rel, fn in (("lg-shared/social-modals.js", "renderNotifFilter"),
                        ("webroot/bottom-nav.js", "sheetFilterHtml")):
            js = open(os.path.join(REPO, rel), encoding="utf-8").read()
            if re.search(rf"function {fn}\(counts[^)]*\)\s*{{\s*if \(!counts\) return '';", js):
                OK.append(f"[F] {rel} renders nothing without `counts` (OFF is inert client-side)")
            else:
                RED.append(f"[F] {rel}: {fn}() no longer guards on `counts` — a cached client "
                           f"could grow chips while the flag is off")
    finally:
        php("teardown", str(PID))
        left, _ = psql(f"SELECT count(*) FROM users WHERE slug LIKE 'ntf-probe-{PID}-%';")
        if left is not None and left != "0":
            RED.append(f"[!] teardown left {left} probe member(s) behind")

    for m_ in OK:
        print(f"  ok   {m_}")
    for m_ in RED:
        print(f"  RED  {m_}")
    for m_ in DEAD:
        print(f"  DEAD {m_}")

    if RED:
        print(f"notif-type-filter-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"notif-type-filter-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"notif-type-filter-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
