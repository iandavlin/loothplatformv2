#!/usr/bin/env python3
"""
compose-licence-redfirst.py — break the licence feature on purpose, one way at a
time, and prove compose-licence-gate.py notices each one.

WHY. A gate that has only ever been run against a working build has never been
shown to be capable of failing. On this box that is not theoretical: assertions
have gone green against a page that was a styled 403, against a string that also
appeared in a CSS comment, and against a fixture whose keys made two different
walks indistinguishable. The cure is to make the defect and watch the gate.

⚠️ IT SNAPSHOTS AND RESTORES. It NEVER runs `git checkout --`. That is a recorded
box rule paid for the expensive way: checkout-from-HEAD wipes uncommitted work in
the tree under test and turned one harness bug into ten false "this assertion is
decoration" verdicts. Every touched file is copied aside first and copied back in
a finally, so an interrupted run leaves the tree exactly as it found it.

⚠️ A MUTATION THAT DID NOT APPLY FAILS LOUD. A find-and-replace whose target has
drifted silently tests nothing and reports the gate as sound. Every mutation
asserts its own text was found before the gate is run.

⚠️ TWO NO-OP CONTROLS. If a comment-only edit turns the gate red, the gate is
measuring something other than behaviour and none of the reds above mean what
they appear to.

RUN:  python3 tools/gates/compose-licence-redfirst.py          (all)
      python3 tools/gates/compose-licence-redfirst.py M5 N1    (named only)

Each case takes about 40s.
"""

import io, os, shutil, subprocess, sys, tempfile

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
MU   = os.path.join(REPO, "platform", "mu-plugins", "lg-frontend-compose.php")
LICD = os.path.join(REPO, "platform", "licences")
LIC  = os.path.join(REPO, "lg-layout-v2", "src", "Licenses.php")
GATE = os.path.join(REPO, "tools", "gates", "compose-licence-gate.py")

LEGACY = ("BY ND NC (Credit given to creator, No Derivatives, "
          "Adaptations shared with same terms)")


def sub(path, old, new, count=1):
    """One find-and-replace, as a mutation. Returns a callable."""
    def apply():
        src = io.open(path, encoding="utf-8").read()
        n = src.count(old)
        if n == 0:
            raise AssertionError(f"mutation text not found in {os.path.basename(path)}: "
                                 f"{old[:80]!r}")
        if count and n != count:
            raise AssertionError(f"mutation text appears {n}x in "
                                 f"{os.path.basename(path)}, expected {count}: {old[:60]!r}")
        io.open(path, "w", encoding="utf-8").write(src.replace(old, new))
    return apply


def swap_files(a, b):
    def apply():
        pa, pb = os.path.join(LICD, a), os.path.join(LICD, b)
        ta = io.open(pa, encoding="utf-8").read()
        tb = io.open(pb, encoding="utf-8").read()
        io.open(pa, "w", encoding="utf-8").write(tb)
        io.open(pb, "w", encoding="utf-8").write(ta)
    return apply


def truncate(name):
    def apply():
        p = os.path.join(LICD, name)
        t = io.open(p, encoding="utf-8").read()
        io.open(p, "w", encoding="utf-8").write(t[:400])
    return apply


# (id, what it breaks — in the voice of a plausible mistake, mutation, expect)
#   expect "RED"   the gate must fail
#   expect "GREEN" a control: the gate must still pass
CASES = [
    ("M1", "the original defect exactly — the fourth option back to its contradictory text",
     sub(MU, "'value'  => 'BY NC ND (Credit given to creator, Non-Commercial only, No Derivatives)',",
             f"'value'  => '{LEGACY}',"), "RED"),

    ("M2", "the forward map dropped — a legacy value no longer re-selects",
     sub(MU, "$field['value'] = lg_fc_licence_forward($field['value']);",
             "$field['value'] = $field['value'];"), "RED"),

    ("M3", "the nudge put back in the hint",
     sub(MU, "'How other people may use your print files and photos.', false, 'Creative Commons",
             "'The usual choice — leave it unless you know you want something else.', false, 'Creative Commons"),
     "RED"),

    ("M4", "the ⓘ filter never registered",
     sub(MU, "add_filter('acf/get_field_label', 'lg_fc_licence_label_info', 20, 3);",
             "/* mutation: not registered */"), "RED"),

    ("M5", "THE ONE THAT MATTERS — the modal always opens the FIRST licence, "
           "whatever is checked",
     sub(MU, "if (all[i].getAttribute('data-lic') === value) return all[i];",
             "if (all.length) return all[0];"), "RED"),

    # ⚠️ KNOWN GREEN, AND IT IS A FINDING ABOUT THE FEATURE, NOT ABOUT THE GATE.
    # Deleting our own btn.focus() leaves C10 green, because a native <dialog>
    # closed from showModal() restores focus to the invoker BY SPEC — the browser
    # already keeps the promise Ian was made. The explicit call is kept anyway:
    # it is the ONLY thing that keeps it in the no-showModal fallback path, where
    # nothing restores focus. So C10 asserts the PROMISE (focus comes home),
    # which is the right thing to assert, and this case records that our line is
    # not what satisfies it on this browser. Expecting RED here would be
    # expecting the gate to fail on a page that behaves correctly.
    ("K1", "KNOWN GREEN — our explicit btn.focus() removed; native <dialog> "
           "restores focus itself, so the promise still holds",
     sub(MU, "    btn.setAttribute('aria-expanded', 'false');\n    btn.focus();",
             "    btn.setAttribute('aria-expanded', 'false');"), "GREEN"),

    ("M7", "the ⓘ never reports itself collapsed again",
     sub(MU, "dlg.addEventListener('close', function () {\n    btn.setAttribute('aria-expanded', 'false');",
             "dlg.addEventListener('close', function () {\n    btn.setAttribute('aria-expanded', 'true');"),
     "RED"),

    ("M8", "the ⓘ loses type=button — inside the form, so it SUBMITS the post",
     sub(MU, "' <button type=\"button\" id=\"lgfc-lic-i\" class=\"lgfc-lic__i\"'",
             "' <button id=\"lgfc-lic-i\" class=\"lgfc-lic__i\"'"), "RED"),

    ("M9", "the dialog moved INSIDE the form",
     sub(MU, "  acf_form('lg-fc-' . $type);\n",
             "  echo lg_fc_licence_dialog();\n  acf_form('lg-fc-' . $type);\n"), "RED"),

    ("M10", "two legal texts wired to the wrong licences",
     swap_files("cc-by-sa-4.0.txt", "cc-by-nc-nd-4.0.txt"), "RED"),

    ("M11", "one legal text truncated to its first paragraph",
     truncate("cc-by-nc-sa-4.0.txt"), "RED"),

    ("M12", "one licence loses its template — three ship instead of four",
     sub(MU, "    foreach (lg_fc_licences() as $lic) {\n        $tpl .= sprintf(",
             "    foreach (array_slice(lg_fc_licences(), 1) as $lic) {\n        $tpl .= sprintf("),
     "RED"),

    ("M13", "the dialog stops painting its own background — it borrows the page's",
     sub(MU, "  background:var(--lg-card-bg,#fff);color:var(--lg-ink,#323532);\n"
             "  box-shadow:0 24px 60px -24px rgba(26,29,26,.45)}",
             "  box-shadow:0 24px 60px -24px rgba(26,29,26,.45)}"), "RED"),

    ("M14", "the dialog hardcodes a light surface, so dark never moves",
     sub(MU, "background:var(--lg-card-bg,#fff);color:var(--lg-ink,#323532);",
             "background:#fff;color:#323532;"), "RED"),

    ("M15", "the flag guard removed — the route runs with the feature switched off",
     sub(MU, "    if (!lg_fc_enabled()) {\n        return;\n    }\n\n    $path = trim",
             "    if (false) {\n        return;\n    }\n\n    $path = trim"), "RED"),

    # ⚠️ THE FIRST VERSION OF THIS ONE WAS A HARNESS BUG, not a finding: it built
    # invalid PHP, the page fatalled, and the gate reported CANNOT-RUN. A mutation
    # must be VALID CODE THAT IS WRONG — a build that does not parse tests nothing,
    # because every gate fails on it for the same uninteresting reason.
    ("M16", "the plain summary comes out empty, leaving only the legal wall",
     sub(MU, "         . $list('People may', $lic['can'] ?? [], 'can')\n"
             "         . $list('People may not', $lic['cannot'] ?? [], 'cannot')",
             "         . $list('People may', [], 'can')\n"
             "         . $list('People may not', [], 'cannot')"), "RED"),


    # ── §F, the cross-check between the two licence tables ───────────────────
    # M17 IS THE REGRESSION THIS LANE ACTUALLY CAUSED, reproduced exactly: the
    # form's wording was corrected and lg-layout-v2's exact-match recogniser was
    # not told. Nothing errors; the licence-block upgrade just walks past every
    # post saved afterwards. It was found by grepping the repo for the old
    # string, which is not a method — hence §F.
    ("M17", "lg-layout-v2 never told about the corrected wording — the exact "
            "recogniser stops matching every post saved from now on",
     sub(LIC, "        'BY NC ND (Credit given to creator, Non-Commercial only, No Derivatives)'                      => 'by-nc-nd',\n",
              ""), "RED"),

    ("M18", "the two tables disagree about what to CALL a licence",
     sub(LIC, "            'short'   => 'CC BY-NC-ND 4.0',",
              "            'short'   => 'CC BY-ND 4.0',"), "RED"),

    ("M19", "the LEGACY spelling dropped — live and any fresh cut of dev2 stop "
            "being recognised",
     sub(LIC, "        'BY ND NC (Credit given to creator, No Derivatives, Adaptations shared with same terms)'       => 'by-nc-nd',\n",
              ""), "RED"),

    # ── controls ─────────────────────────────────────────────────────────────
    ("N1", "CONTROL — a comment-only edit must NOT redden the gate",
     sub(MU, "/* ══════════════════════════ THE LICENCE LIST (#191) ═════════════════════════",
             "/* ══════════════════════════ THE LICENCE LIST (#191) ═════════════════════════\n *\n * (redfirst control: this comment line changes nothing)"),
     "GREEN"),

    ("N2", "CONTROL — trailing whitespace in the licence CSS must NOT redden it",
     sub(MU, ".lgfc-lic__b:focus{outline:none}",
             ".lgfc-lic__b:focus{outline:none}   "), "GREEN"),
]


def main() -> int:
    want = set(a.upper() for a in sys.argv[1:])
    cases = [c for c in CASES if not want or c[0] in want]
    if not cases:
        print("no such case; ids are: " + " ".join(c[0] for c in CASES))
        return 2

    snap = tempfile.mkdtemp(prefix="lg191-redfirst-")
    shutil.copy2(MU,  os.path.join(snap, os.path.basename(MU)))
    shutil.copy2(LIC, os.path.join(snap, os.path.basename(LIC)))
    shutil.copytree(LICD, os.path.join(snap, "licences"))

    def restore():
        shutil.copy2(os.path.join(snap, os.path.basename(MU)),  MU)
        shutil.copy2(os.path.join(snap, os.path.basename(LIC)), LIC)
        for f in os.listdir(os.path.join(snap, "licences")):
            shutil.copy2(os.path.join(snap, "licences", f), os.path.join(LICD, f))

    results = []
    try:
        # The baseline is not ceremony: if the tree is already red, every "RED"
        # below is free and means nothing.
        r = subprocess.run(["python3", GATE], capture_output=True, text=True)
        if r.returncode != 0:
            print("BASELINE IS NOT GREEN — every RED below would be free.\n" + r.stdout[-2000:])
            return 2
        print(f"baseline green: {r.stdout.strip().splitlines()[-1]}\n")

        for cid, what, mut, expect in cases:
            restore()
            try:
                mut()
            except AssertionError as e:
                results.append((cid, "HARNESS", what, str(e)))
                print(f"  {cid}  HARNESS BUG — {e}")
                continue
            r = subprocess.run(["python3", GATE], capture_output=True, text=True)
            got = "GREEN" if r.returncode == 0 else ("RED" if r.returncode == 1 else "CANNOT-RUN")
            hit = "ok" if got == expect else "MISS"
            first = ""
            if got == "RED":
                fl = [l.strip() for l in r.stdout.splitlines() if l.strip().startswith("FAIL")]
                first = f"  [{len(fl)} red: {fl[0][6:60] if fl else '?'}…]"
            results.append((cid, hit, what, got))
            print(f"  {cid}  {hit:4}  expected {expect:5} got {got:11}{first}\n"
                  f"        {what}")
    finally:
        restore()
        shutil.rmtree(snap, ignore_errors=True)
        print("\ntree restored from snapshot")

    bad = [r for r in results if r[1] != "ok"]
    print(f"\n{len(results) - len(bad)}/{len(results)} behaved as expected")
    for cid, hit, what, got in bad:
        print(f"  {hit}  {cid}  got {got} — {what}")
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
