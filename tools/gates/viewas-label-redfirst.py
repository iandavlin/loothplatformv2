#!/usr/bin/env python3
"""RED-FIRST for gate 97 (#195) — every assertion must be reddenable, and every
mutation must be PROVEN to have applied.

⚠️ THE FAILURE THIS FILE EXISTS TO PREVENT is a mutation that changes nothing
and records RED-OK anyway. Gate 79's mutation 15 — its caching law, the most
important leg in that file — had been SILENTLY INERT since #180 moved its
target, dutifully reporting RED-OK for a no-op (#196's lane found it). So each
mutation here asserts the file's bytes actually changed before the gate is run,
and a mutation whose search text is not found is a HARNESS FAILURE, never a pass.

⚠️ SNAPSHOT, NEVER `git checkout --`. Restoring from HEAD wipes uncommitted work
under test and once turned one harness bug into ten false verdicts
(feedback-mutation-harness-must-snapshot-not-checkout). Every file is read into
memory first and written back in a finally.

⚠️ TWO NO-OP CONTROLS ARE INCLUDED AND MUST STAY GREEN. A gate that reddens on
everything is not measuring the thing it names. One of them puts the OLD label
in a PHP comment inside the very file under test — that is the
assert-a-string-that-also-lives-in-prose trap (feedback-red-first-that-stays-
green, six cases in this repo), and it proves §A parses the seg element rather
than grepping the file.

Runs against the LANE PREVIEW so §B/§C see this branch. With no preview up the
rendered sections go NO VERDICT and only §A/§A2 are exercised — the run says so
rather than quietly halving itself.

    tools/preview/lane-preview.sh up 195-edit-label
    python3 tools/gates/viewas-label-redfirst.py
"""
import os
import re
import subprocess
import sys
import time

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GATE = os.path.join(REPO, "tools", "gates", "viewas-label-gate.py")
BASE = os.environ.get("LG_VIEWAS_BASE",
                      "https://dev2.loothgroup.com/preview/195-edit-label")

U = "profile-app/web/u.php"
P = "profile-app/web/p.php"
R = "profile-app/web/_render.php"

# mutation: (id, description, [(file, find, replace), ...], must_redden_substring)
# must_redden_substring is matched against the gate's FAIL lines — a mutation
# that reddens some OTHER assertion is not proof that this one is alive.
MUTATIONS = [
    ("m01", "revert the /u/ label to 'Me' — the change Ian asked for, undone",
     [(U, "?>>Edit</a>", "?>>Me</a>")],
     "u.php: the 'me' position reads 'Me'"),

    ("m02", "revert the /p/ label to 'Me' — one position wearing two names",
     [(P, "?>>Edit</a>", "?>>Me</a>")],
     "p.php: the 'me' position reads 'Me'"),

    ("m03", "revert the unreachable SSR editor's label — source-only leg",
     [(R, '?>">Edit</button>', '?>">Me</button>')],
     "_render.php: the 'me' position reads 'Me'"),

    # THE ONE THIS GATE EXISTS FOR. Someone "tidies" the value to match the new
    # word; every consumer keys on 'me' and edit mode dies with it.
    ("m04", "rewrite the /u/ VALUE to match the new label: viewLink('me') -> viewLink('edit')",
     [(U, "$viewLink('me')", "$viewLink('edit')")],
     "the me position is missing"),

    ("m05", "rewrite the /p/ VALUE the same way",
     [(P, "$viewLink('me')", "$viewLink('edit')")],
     "the me position is missing"),

    ("m06", "move the SSR editor's data-role off 'me'",
     [(R, 'data-role="me"', 'data-role="edit"')],
     "the me position is missing"),

    # Breaks the HIGHLIGHT without touching the link — caught by §B's
    # aria-current leg, not by the href leg. Distinct failure, distinct assertion.
    ("m07", "break only the current-position test: $role==='me' -> $role==='edit' on /u/",
     [(U, "<?= $role==='me'?'aria-current=\"true\"':'' ?>>Edit</a>",
          "<?= $role==='edit'?'aria-current=\"true\"':'' ?>>Edit</a>")],
     "marks [] as current"),

    ("m08", "rename a position that #195 did NOT touch (Public -> Everyone) on /u/",
     [(U, "?>>Public</a>", "?>>Everyone</a>")],
     "position reads 'Everyone'"),

    ("m09", "rename Member on /p/ — the other untouched position",
     [(P, "?>>Member</a>", "?>>Audience</a>")],
     "position reads 'Audience'"),

    # A fourth copy of the switcher keeping the old word is the drift this
    # feature will actually suffer, and §A2 is the only leg that sees it.
    ("m10", "a NEW shipped template renders the old label (drift into a fourth surface)",
     [("profile-app/web/_render_public.php", "<?php", "<?php /* <a>Me</a> */")],
     None),  # asserted specially — see run()

    # ⚠️ THIS MUTATION WAS WRONG FIRST, and the gate was right to argue. It used
    # to add `hidden` to the seg's opening tag and expect GREEN. Two things were
    # broken about that: the gate reddened for the WRONG REASON (its selector
    # demanded the tag verbatim, so one extra attribute read as "the switcher is
    # gone"), and a hidden switcher is a real defect the gate has no business
    # calling healthy. Now it deletes the seg outright, which is unambiguous.
    # Visibility remains the screenshots' job — asserting CSS is not this gate's.
    ("m11", "delete the /u/ switcher outright",
     [(U, '<span class="lg-viewas__seg">', '<span class="lg-viewas__seg-DELETED">')],
     "carries no View-as switcher"),

    ("m12", "make ?view=member render edit chrome too, so §C's ON leg proves nothing",
     [(U, "$editing = ($isOwner && $role === 'me') || $adminEditing;",
          "$editing = $isOwner || $adminEditing;")],
     "?view=member renders edit chrome too"),

    ("m13", "take edit mode off ?view=me — the member-visible cost of moving the value",
     [(U, "$editing = ($isOwner && $role === 'me') || $adminEditing;",
          "$editing = $adminEditing;")],
     "?view=me no longer renders edit chrome"),

    # ── NO-OP CONTROLS — these MUST stay green ──────────────────────────────
    ("c01", "NO-OP: the old label in a PHP COMMENT inside the file under test",
     [(U, "<span class=\"lg-viewas__lbl\">View as</span>",
          "<span class=\"lg-viewas__lbl\">View as</span><?php /* was: Me */ ?>")],
     "GREEN"),

    ("c02", "NO-OP: whitespace only",
     [(P, '<span class="lg-viewas__seg">', '<span class="lg-viewas__seg" >')],
     "GREEN"),
]


def run_gate():
    env = dict(os.environ, LG_VIEWAS_BASE=BASE)
    p = subprocess.run([sys.executable, GATE], capture_output=True, text=True, env=env, timeout=300)
    fails = [l.strip() for l in p.stdout.splitlines() if l.strip().startswith("FAIL")]
    deads = [l.strip() for l in p.stdout.splitlines() if l.strip().startswith("----")]
    return p.returncode, fails, deads, p.stdout


def main():
    print(f"=== gate 97 red-first — base {BASE} ===")

    rc0, fails0, deads0, out0 = run_gate()
    if rc0 != 0:
        print("BASELINE IS NOT GREEN — red-first cannot mean anything until it is.")
        for f in fails0:
            print("   ", f)
        for d in deads0:
            print("   ", d)
        return 3
    rendered_live = not any("unreachable" in d or "no live surface" in d for d in deads0)
    print(f"baseline GREEN (exit 0). rendered sections live: {rendered_live}")
    if not rendered_live:
        print("  ⚠️ §B/§C could not reach a surface — only §A/§A2 are being exercised below.")

    results = []
    for mid, desc, edits, want in MUTATIONS:
        snaps = {}
        applied = True
        try:
            for rel, find, repl in edits:
                path = os.path.join(REPO, rel)
                src = open(path, encoding="utf-8").read()
                snaps[path] = src
                if find not in src:
                    print(f"{mid} HARNESS FAILURE: {rel} does not contain {find!r} — "
                          f"the mutation's target moved. This is NOT a pass.")
                    applied = False
                    break
                new = src.replace(find, repl, 1)
                # ⚠️ PROVE IT APPLIED. A no-op mutation that records RED-OK is the
                # exact defect this file exists to prevent (gate 79 mutation 15).
                if new == src:
                    print(f"{mid} HARNESS FAILURE: replacement changed no bytes in {rel}")
                    applied = False
                    break
                open(path, "w", encoding="utf-8").write(new)
            if not applied:
                results.append((mid, "HARNESS", desc))
                continue

            # PHP opcache revalidates on mtime; give it a beat so the preview
            # really serves the mutated file rather than the cached one.
            time.sleep(2.5)
            rc, fails, deads, out = run_gate()

            if want == "GREEN":
                if rc == 0:
                    print(f"{mid} GREEN-OK  (control stayed green)   {desc}")
                    results.append((mid, "OK", desc))
                else:
                    print(f"{mid} CONTROL REDDENED — the gate is over-sensitive: {desc}")
                    for f in fails:
                        print("      ", f)
                    results.append((mid, "BAD", desc))
                continue

            if mid == "m10":
                hit = any("still renders the label 'Me'" in f for f in fails)
            else:
                hit = any(want in f for f in fails)

            if rc == 1 and hit:
                print(f"{mid} RED-OK    ({len(fails)} finding(s), named leg fired)   {desc}")
                results.append((mid, "OK", desc))
            elif rc == 1:
                print(f"{mid} RED-WRONG-LEG — gate went red, but not on {want!r}: {desc}")
                for f in fails:
                    print("      ", f)
                results.append((mid, "BAD", desc))
            else:
                print(f"{mid} STAYED GREEN — the assertion is decoration: {desc}")
                results.append((mid, "BAD", desc))
        finally:
            for path, src in snaps.items():
                open(path, "w", encoding="utf-8").write(src)
            time.sleep(0.6)

    # Restoring must really restore: re-run and demand the baseline back.
    time.sleep(2.5)
    rc9, fails9, _, _ = run_gate()
    print(f"\nrestored tree re-run: exit {rc9} ({'clean' if rc9 == 0 else 'NOT CLEAN'})")
    if rc9 != 0:
        for f in fails9:
            print("   ", f)

    ok = sum(1 for _, s, _ in results if s == "OK")
    bad = [(m, d) for m, s, d in results if s != "OK"]
    print(f"\nred-first: {ok}/{len(results)} mutations behaved")
    for m, d in bad:
        print(f"  NOT OK  {m}  {d}")
    return 0 if (not bad and rc9 == 0) else 1


if __name__ == "__main__":
    sys.exit(main())
