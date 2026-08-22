#!/usr/bin/env python3
"""
RED-FIRST for GATE 93 (#196) — every assertion proven to bite.

A gate nobody has watched go red is decoration. This breaks the feature one way
at a time and requires the NAMED assertion to fail — not merely "the gate went
red", which any broken edit achieves.

⚠️ MUTATIONS ARE APPLIED TO SNAPSHOTS AND RESTORED FROM THEM, never with
`git checkout --` (feedback-mutation-harness-must-snapshot-not-checkout):
checkout-from-HEAD wipes uncommitted work under test and once turned one harness
bug into ten false "the assertion is decoration" verdicts. An atexit handler
restores every touched file even if this is interrupted or killed.

⚠️ AND A MUTATION THAT CHANGES NOTHING IS NOT EVIDENCE. patch() proves its
target existed and that the file actually changed. This is not theoretical: the
sibling shell harness had FIVE legs silently no-oping after #196 removed
$join_pill_authed, and a SIXTH — its most important, the caching law — had been
inert since #180 with nothing saying so.

The no-ops at the end must leave the gate GREEN. A gate that reddens on a
comment edit is measuring the wrong thing.
"""

import atexit, os, shutil, signal, subprocess, sys, tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GATE = os.path.join(REPO, "tools", "gates", "switch-menu-gate.py")

HEADER = "lg-shared/site-header.php"
POLLER = "lg-patreon-stripe-poller/src/Wp/InternalRestController.php"
WHOAMI = "profile-app/src/Whoami.php"
BOTTOM = "webroot/bottom-nav.js"
ROUTER = "membership-pages/web/router.php"
NGINX  = "platform/nginx/strangler-membership.conf"

SNAPDIR = tempfile.mkdtemp(prefix=f"g93-redfirst-{os.getpid()}-")
snapped = {}
results = []


def snapshot(rel):
    if rel in snapped:
        return
    os.makedirs(SNAPDIR, exist_ok=True)
    dst = os.path.join(SNAPDIR, rel.replace("/", "__"))
    shutil.copyfile(os.path.join(REPO, rel), dst)
    snapped[rel] = dst


def restore_all():
    """Restore every touched file and FORGET them, so the next leg snapshots a
    clean copy. It must NOT delete SNAPDIR — an earlier harness elsewhere did,
    so the second leg had nowhere to snapshot to and the first leg's mutation
    stayed on disk while every later leg was measured against a tree nobody had
    verified."""
    for rel, snap in list(snapped.items()):
        try:
            shutil.copyfile(snap, os.path.join(REPO, rel))
        except OSError as exc:                                  # noqa: BLE001
            print(f"  ⚠️ COULD NOT RESTORE {rel}: {exc}")
    snapped.clear()


def cleanup():
    restore_all()
    shutil.rmtree(SNAPDIR, ignore_errors=True)


# The docstring promises the tree survives an interrupt, so make that true
# rather than hopeful: `finally` does not run for SIGTERM, and a run killed by a
# harness timeout would otherwise leave MUTATED SOURCE on disk.
atexit.register(cleanup)
for _sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
    signal.signal(_sig, lambda *_a: sys.exit(130))


def patch(rel, old, new, count=1):
    """Replace, and PROVE the replacement happened."""
    snapshot(rel)
    p = os.path.join(REPO, rel)
    body = open(p, encoding="utf-8").read()
    if old not in body:
        raise AssertionError(f"mutation target not found in {rel}: {old[:70]!r}")
    mutated = body.replace(old, new, count)
    if mutated == body:
        raise AssertionError(f"mutation changed NOTHING in {rel}")
    open(p, "w", encoding="utf-8").write(mutated)


def drop_file(rel):
    snapshot(rel)
    os.rename(os.path.join(REPO, rel), os.path.join(REPO, rel + ".redfirst-moved"))


def restore_moved():
    for rel in list(snapped):
        moved = os.path.join(REPO, rel + ".redfirst-moved")
        if os.path.exists(moved):
            os.rename(moved, os.path.join(REPO, rel))


def run_gate():
    r = subprocess.run([sys.executable, GATE], capture_output=True, text=True, timeout=900)
    return r.returncode, r.stdout + r.stderr


def leg(name, expect, apply_fn, want_red=True):
    """expect: a substring that must appear in a FAIL line (None for a no-op)."""
    try:
        apply_fn()
    except AssertionError as exc:
        results.append((name, False, f"MUTATION DID NOT APPLY: {exc}"))
        return
    try:
        code, out = run_gate()
    finally:
        restore_moved()
        restore_all()

    fails = [l for l in out.splitlines() if l.strip().startswith("FAIL")]
    if not want_red:
        good = code == 0
        results.append((name, good, "stayed GREEN" if good
                        else f"expected GREEN, got exit {code}: {fails[:2]}"))
        return
    if code == 0:
        results.append((name, False, "gate stayed GREEN — the assertion is decoration"))
        return
    hit = [l for l in fails if expect in l]
    results.append((name, bool(hit), f"reddened {expect}" if hit
                    else f"went red, but NOT on {expect}: {[f[:95] for f in fails[:3]]}"))


# ─────────────────────────── the mutations ────────────────────────────────────
def m01():   # the capability is never read — a Patreon payer is offered Join again
    patch(HEADER, "$patreon_paying = ($caps['patreon_paying'] ?? false) === true;",
                  "$patreon_paying = false;")

def m02():   # the swap ignores Patreon — EVERY tester is sent to the switch page
    patch(HEADER, "$patreon_paying = ($caps['patreon_paying'] ?? false) === true;",
                  "$patreon_paying = true;")

def m03():   # read loosely: 'no', 0.0 and a stray array all become "paying"
    patch(HEADER, "$patreon_paying = ($caps['patreon_paying'] ?? false) === true;",
                  "$patreon_paying = (bool) ($caps['patreon_paying'] ?? false);")

def m04():   # the label is hardcoded — the control says Join and goes to Switch
    patch(HEADER, "$tester_join_label = $patreon_paying ? 'Switch' : 'Join';",
                  "$tester_join_label = 'Join';")

def m05():   # the href is hardcoded — the word changes and the door does not
    patch(HEADER, "$tester_join_href  = $patreon_paying ? '/switch-billing/' : '/lgjoin/';",
                  "$tester_join_href  = '/lgjoin/';")

def m06():   # the menu item stops using the derivation (two sources, one drifts)
    patch(HEADER,
          '<a role="menuitem" href="<?= $h($tester_join_href) ?>"><?= $h($tester_join_label) ?></a>',
          '<a role="menuitem" href="/lgjoin/">Join</a>')

def m07():   # the PWA sheet's hook is removed — no signed-in phone door below 641
    patch(HEADER, '<li role="none" class="lg-chrome__menu-join">',
                  '<li role="none">')

def m08():   # THE DEFECT THIS LANE ACTUALLY SHIPPED IN ITS FIRST DRAFT.
    # An indented <?php ?> island emits its own leading whitespace as inline HTML
    # whether or not the branch is taken: 12 bytes added to EVERY tester render,
    # invisible on screen and to every href assertion. Only a byte comparison
    # against origin/main sees it, and gate 79 cannot — it compares signed-in
    # viewers across FLAG STATES only, where the leak is uniform.
    patch(HEADER, '            <li role="none" class="lg-chrome__menu-join">',
                  '            <?php /* the switch door */ ?>\n'
                  '            <li role="none" class="lg-chrome__menu-join">')

def m09():   # the swap escapes the cohort — every signed-in member gets a door
    patch(HEADER, "$stripe_tester = ($caps['stripe_testgroup'] ?? false) === true;",
                  "$stripe_tester = true;")

def m10():   # the header grows a lookup of its own — seven apps, no database
    patch(HEADER, "$patreon_paying = ($caps['patreon_paying'] ?? false) === true;",
                  "$patreon_paying = (bool) get_option('lgms_double_pay_block');")

def m11():   # the poller stops computing it at all
    # ⚠️ BOTH assignments, not just the first. The initial version renamed only
    # the `= false` line and left `$caps['patreon_paying'] = ...forUser...`
    # inside the try, so "the poller computes the capability" still passed and
    # the leg reddened a NEIGHBOUR instead. A mutation that half-applies is the
    # same class of lie as one that does not apply at all.
    patch(POLLER, "$caps['patreon_paying'] = false;", "$caps['x_disabled'] = false;")
    patch(POLLER, "            $caps['patreon_paying'] =\n",
                  "            $caps['x_disabled'] =\n")

def m12():   # a SECOND definition of "already paying" — the field both rails overwrite
    patch(POLLER,
          "\\LGMS\\Membership\\PatreonStanding::forUser( $wpUserId )['active'] === true;",
          "get_user_meta( $wpUserId, 'payment_source', true ) === 'patreon';")

def m13():   # an unreadable database becomes "paying" instead of "unknown"
    patch(POLLER, "$caps['patreon_paying'] = false;\n        try {",
                  "$caps['patreon_paying'] = true;\n        try {")

def m14():   # THE 2026-08-16 BUG, EXACTLY: the named pass-through drops it
    patch(WHOAMI, "'stripe_testgroup', 'patreon_paying'", "'stripe_testgroup'")

def m15():   # the PWA sheet hardcodes the word again
    patch(BOTTOM, "var menuJoinLabel = ((menuJoinEl.textContent || '').trim()) || 'Join';",
                  "var menuJoinLabel = 'Join';")

def m16():   # the sheet row ejects a member from the installed PWA
    patch(BOTTOM,
          "if (/^https?:\\/\\//i.test(menuJoinHref)) { joinRow2.target = '_blank'; joinRow2.rel = 'noopener'; }",
          "joinRow2.target = '_blank'; joinRow2.rel = 'noopener';")

def m17():   # the router forgets the slug — Switch lands on a 404
    patch(ROUTER, "'switch-billing'                 => ['switch-billing.php',",
                  "'switch-billing-x'               => ['switch-billing.php',")

def m18():   # the page is opened to anyone pre-launch, unlike the menu that links to it
    patch(ROUTER, "['switch-billing.php',                 'testgroup', 'member'],",
                  "['switch-billing.php',                 'public', 'member'],")

def m19():   # nginx never hears about the slug — the whole coupling, unwired
    patch(NGINX, "|join|switch-billing)", "|join)")

def m20():   # the page file itself goes missing under a registry that still names it
    drop_file("membership-pages/web/switch-billing.php")

def m21():   # the ANON header is touched — the one render that must never move
    patch(HEADER, '<a class="lg-chrome__connect" href="/connect-your-patreon/">Connect Patreon</a>',
                  '<a class="lg-chrome__connect" href="/connect-your-patreon/">Connect Patreon </a>')


# ───────────────────────────── the no-ops ─────────────────────────────────────
def n01():   # prose only: reflow a docblock line in the header
    patch(HEADER, " * A PATREON PAYER IS OFFERED \"SWITCH\", NEVER \"JOIN\" (#196).",
                  " * A PATREON PAYER IS OFFERED \"SWITCH\" AND NEVER \"JOIN\" (issue 196).")

def n02():   # rename a local in the poller — behaviour identical
    patch(POLLER, "        } catch ( \\Throwable $e ) {\n            error_log( 'LGMS InternalRestController patreon_paying: ' . $e->getMessage() );",
                  "        } catch ( \\Throwable $err ) {\n            error_log( 'LGMS InternalRestController patreon_paying: ' . $err->getMessage() );")


LEGS = [
    ("M01 capability never read — the payer is offered Join again", "says Switch", m01),
    ("M02 swap ignores Patreon — every tester sent to the switch page", "still says Join", m02),
    ("M03 capability read loosely, not === true", "read strictly", m03),
    ("M04 the label is hardcoded (says Join, goes to Switch)", "derived ONCE", m04),
    ("M05 the href is hardcoded (word changes, door does not)", "derived ONCE", m05),
    ("M06 the menu item stops using the derivation", "not a literal", m06),
    ("M07 the PWA sheet's hook removed — no phone door below 641", "hook", m07),
    ("M08 an indented island inside the menu (12 stray bytes)", "NOTHING else moved", m08),
    ("M09 the swap escapes the cohort", "NEITHER door appears", m09),
    ("M10 the header grows a database lookup of its own", "no database to ask", m10),
    ("M11 the poller stops computing the capability", "the poller computes", m11),
    ("M12 a SECOND definition of already-paying (payment_source)", "payment_source", m12),
    ("M13 an unreadable database reads as PAYING", "reads as NOT paying", m13),
    ("M14 the named pass-through drops it (the 8/16 bug, exactly)", "pass-through", m14),
    ("M15 the PWA sheet hardcodes the word again", "follows the menu to Switch", m15),
    ("M16 the sheet row ejects a member from the installed PWA", "eject a member from the installed PWA", m16),
    ("M17 the router forgets the slug — Switch lands on a 404", "router registers", m17),
    ("M18 the page is opened to anyone pre-launch", "testgroup", m18),
    ("M19 nginx never hears about the slug", "slug regex lists", m19),
    ("M20 the page file is missing under a registry that names it", "page file", m20),
    ("M21 the ANON header is touched", "byte-identical", m21),
]
NOOPS = [
    ("N01 reflow a docblock line (prose only)", n01),
    ("N02 rename a caught-exception local in the poller", n02),
]


def main():
    print("=" * 78)
    print("RED-FIRST — GATE 93 (#196)")
    print(f"snapshots: {SNAPDIR}")
    print("=" * 78)

    code, _ = run_gate()
    if code != 0:
        print("  ⚠️ THE GATE IS NOT GREEN ON THE UNMUTATED TREE — nothing below "
              "would mean anything. Fix that first.")
        return 2

    for name, expect, fn in LEGS:
        print(f"  … {name}", flush=True)
        leg(name, expect, fn)
        print(f"    {'RED-OK  ' if results[-1][1] else 'MISSED  '} {results[-1][2]}")
    for name, fn in NOOPS:
        print(f"  … {name}", flush=True)
        leg(name, None, fn, want_red=False)
        print(f"    {'NOOP-OK ' if results[-1][1] else 'BROKE   '} {results[-1][2]}")

    good = sum(1 for _, ok, _ in results if ok)
    print("-" * 78)
    for name, ok, detail in results:
        print(f"  {'OK  ' if ok else 'BAD '} {name}\n       {detail}")
    print(f"\n  {good}/{len(results)}")
    if good != len(results):
        print("  ############ RED-FIRST INCOMPLETE")
        return 1
    print("  ############ RED-FIRST COMPLETE — every assertion proven able to "
          "fail for its own stated reason, and no-ops proven inert.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
