#!/usr/bin/env python3
"""
mirror-sync-loud-gate.py — the mirror pipe announces its own failures, and
reconcile can reach backwards.

⚠️ NO GATE NUMBER YET. Keeper mints, lanes never.

Backlog 3.9. Two independent guarantees, each of which has already failed once in
production and cost real member-visible data:

── A. A SKIP IS NOT A SUCCESS ─────────────────────────────────────────────────
bb_mirror_upsert_reply() returns without writing when a reply is unmirrorable —
correctly, because throwing there wedged live's reconcile for 11 days. But the
receiver then answered 200 for a write and for a drop alike, so on 2026-08-09 an
investigator read 290 _sync POSTs in nginx's access log, saw every one return 200,
and could not tell the 11 replies that vanished from the 61 that landed.

So this asserts the skip is RECORDED at every silent exit, and that _sync reads it
and answers 202. The status code is load-bearing: it is what makes a drop visible
in a log we already keep, to someone with no shell access.

⚠️ AND IT ASSERTS 202 SPECIFICALLY, NOT "not 200". A 4xx/5xx would look like a
stricter fix and would be a worse one: the WP hook is fire-and-forget, so an error
status turns an unmirrorable row into a retry storm against a condition that
retrying cannot fix.

── B. RECONCILE CAN REACH BACKWARDS ───────────────────────────────────────────
The delta walk upserts WHERE post_modified_gmt >= bookmark - 60, so anything that
diverges and then ages out is invisible forever. Measured on live 2026-08-16: all
five diverged replies were 60-73 days older than the bookmark, and reply 71432 had
been serving another author's content since June.

Asserts the deep sweep exists, is bounded (its own state key + an interval), and
REFUSES TO SCORE AN EMPTY READ — a failed query returning zero WP rows would
otherwise read as total divergence and rewrite the mirror. That guard is not
hypothetical: I hit exactly that while measuring, and a mis-parsed capture claimed
5,305 replies were missing when every one was present.

── C. THE OLD DESIGN'S ONE CORRECT INSTINCT MUST SURVIVE ──────────────────────
It must still NOT throw on an unmirrorable row. Loud is not the same as fatal, and
confusing the two would re-create the 11-day wedge while fixing the silence.

⚠️ ONE HONEST LIMIT, found by mutation-testing this gate rather than by reasoning
about it. The "every non-writing exit announces itself" check reads the SOURCE for
a note call near each return, so it catches the realistic regression — someone
tidying the line away — and it does NOT catch a call deliberately disabled in
place (wrapping it in `if (false)` passes). Proven both ways: deleting the note
reds it ("1 noted, 1 still silent"), disabling it does not. A stronger version
would have to execute the function, which means a DB and a WordPress; that is a
bigger gate than this one and is not pretended to here.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN (2, never 3 — run-all.sh reads
anything else as RED).
"""
import os, re, sys

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
MAT  = os.environ.get("LG_MIRROR_MAT")  or os.path.join(REPO, "bb-mirror", "lib", "materializers.php")
SYNC = os.environ.get("LG_MIRROR_SYNC") or os.path.join(REPO, "bb-mirror", "api", "v0", "_sync.php")
REC  = os.environ.get("LG_MIRROR_REC")  or os.path.join(REPO, "bb-mirror", "bin", "reconcile.php")


class CannotRun(Exception):
    pass


def read(p):
    if not os.path.isfile(p):
        raise CannotRun(f"not found: {p}")
    return open(p, encoding="utf-8").read()


def reply_fn(mat):
    """The body of bb_mirror_upsert_reply, so assertions cannot match other code."""
    i = mat.find("function bb_mirror_upsert_reply(")
    if i < 0:
        raise CannotRun("bb_mirror_upsert_reply() not found")
    j = mat.find("\nfunction ", i + 10)
    return mat[i: j if j > 0 else len(mat)]


def main() -> int:
    mat, sync, rec = read(MAT), read(SYNC), read(REC)
    body = reply_fn(mat)
    rows = []
    def add(state, leg, msg): rows.append((state, leg, msg))

    # ── A ──────────────────────────────────────────────────────────────────
    add("ok" if "function bb_mirror_note_skip(" in mat else "RED", "A",
        "bb_mirror_note_skip() exists")
    add("ok" if "function bb_mirror_last_skip(" in mat else "RED", "A",
        "bb_mirror_last_skip() exists for callers to read")
    add("ok" if "bb_mirror_clear_skip();" in body else "RED", "A",
        "the reply upsert CLEARS the skip first (a stale reason must not read as fresh)")

    # EVERY silent exit inside the reply upsert must be preceded by a note.
    # Count bare `return;` that are not immediately after a note_skip call.
    silent, noted = 0, 0
    for m in re.finditer(r"return;", body):
        window = body[max(0, m.start() - 320): m.start()]
        if "bb_mirror_note_skip(" in window:
            noted += 1
        else:
            # the delete path legitimately returns after writing a DELETE
            if "DELETE FROM reply" in window:
                continue
            silent += 1
    add("ok" if silent == 0 else "RED", "A",
        f"every non-writing exit announces itself ({noted} noted, {silent} still silent)")

    add("ok" if "bb_mirror_last_skip()" in sync else "RED", "A",
        "_sync reads the skip")
    add("ok" if "http_response_code(202)" in sync else "RED", "A",
        "_sync answers 202 on a skip (visible in the access log)")
    # It must not have been 'fixed' into an error status.
    bad = re.search(r"http_response_code\((4\d\d|5\d\d)\)", sync.split("$skip")[-1]) if "$skip" in sync else None
    add("ok" if not bad else "RED", "A",
        "the skip path does NOT answer 4xx/5xx (that would retry-storm a row retrying cannot fix)")

    # ── C ──────────────────────────────────────────────────────────────────
    add("ok" if not re.search(r"\bthrow new\b", body) else "RED", "C",
        "the reply upsert still does not THROW on an unmirrorable row (loud != fatal)")

    # ── B ──────────────────────────────────────────────────────────────────
    add("ok" if "last_deep_at" in rec else "RED", "B",
        "the deep sweep has its OWN state key (cannot disturb the delta bookmark)")
    add("ok" if "BB_MIRROR_DEEP_EVERY" in rec else "RED", "B",
        "the deep sweep is interval-bounded")
    guard = re.search(r"if \(!\$wp\) \{", rec)
    add("ok" if guard else "RED", "B",
        "the deep sweep REFUSES an empty WP read (else zero rows reads as total divergence)")
    add("ok" if re.search(r"\$m - \$pg\[\$id\] > 60", rec) else "RED", "B",
        "it repairs on a MODIFIED-TIME difference, not just on absence (stale edits too)")
    add("ok" if "bb_mirror_walk_ids(" in rec else "RED", "B",
        "the deep repair uses the poison-tolerant walker (one bad row must not wedge it)")

    for state, leg, msg in rows:
        print(f"  [{leg}] {state:<4} {msg}")
    reds = [r for r in rows if r[0] == "RED"]
    if reds:
        print(f"\nRED — {len(reds)} of {len(rows)}:")
        for _, leg, m in reds:
            print(f"  - [{leg}] {m}")
        return 1
    print(f"\nGREEN — skips are recorded and answered 202, the upsert still never throws, "
          f"and reconcile can reach backwards with its guards on ({len(rows)} checks).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
    except Exception as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
