#!/usr/bin/env python3
"""
RED-FIRST for GATE 85 (#180) — every assertion proven to bite.

A gate is only worth its runtime if each assertion goes RED for its OWN reason.
This breaks the feature one way at a time and requires the NAMED assertion to
fail — not merely "the gate went red", which is satisfied by a mutation that
breaks something else entirely.

⚠️ MUTATIONS ARE APPLIED TO SNAPSHOTS AND RESTORED FROM THEM, never with
`git checkout --` (`feedback-mutation-harness-must-snapshot-not-checkout`):
checkout-from-HEAD would wipe uncommitted work under test and turn one harness
bug into a run of false verdicts. An EXIT handler restores every touched file
even if this is interrupted.

⚠️ AND A MUTATION THAT CHANGES NOTHING IS NOT EVIDENCE. Each mutation asserts
that it actually altered its target file before the gate is run, so a patch that
silently failed to apply can never be recorded as a passing red-first leg.

The no-ops at the end must leave the gate GREEN. A gate that reddens on a
comment edit is measuring the wrong thing.
"""

import atexit, os, re, shutil, signal, subprocess, sys, tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GATE = os.path.join(REPO, "tools", "gates", "tester-unlock-gate.py")

HEADER = "lg-shared/site-header.php"
UNLOCK = "lg-shared/tester-unlock.php"
CFG = "platform/config/tester-unlock.php"
ADMGATE = "membership-pages/web/_admin-gate.php"
MICRO = "platform/nginx/lg-microcache.conf"
LGJOIN = "membership-pages/web/lgjoin.php"

SNAPDIR = tempfile.mkdtemp(prefix=f"g85-redfirst-{os.getpid()}-")
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
    clean copy.

    ⚠️ It must NOT delete the snapshot directory: an earlier version did, so the
    second leg had nowhere to snapshot to, its restore failed, and the mutation
    from leg one stayed on disk while every later leg was measured against a
    tree nobody had verified. That is the harness bug that manufactures false
    verdicts (`feedback-mutation-harness-must-snapshot-not-checkout`) — it
    crashed loudly here, but it could as easily have gone quiet.
    """
    for rel, snap in list(snapped.items()):
        try:
            shutil.copyfile(snap, os.path.join(REPO, rel))
        except OSError as exc:                                  # noqa: BLE001
            print(f"  ⚠️ COULD NOT RESTORE {rel}: {exc}")
    snapped.clear()


def cleanup():
    restore_all()
    shutil.rmtree(SNAPDIR, ignore_errors=True)


# The docstring above promises the tree survives an interrupt, so make that
# true rather than hopeful: a `finally` does not run for SIGTERM, and this
# harness leaves MUTATED SOURCE on disk if it dies mid-leg. A run killed by a
# harness timeout once left the whitespace mutation in site-header.php.
atexit.register(cleanup)
for _sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
    signal.signal(_sig, lambda *_a: sys.exit(130))


def patch(rel, old, new, count=1):
    """Replace and PROVE the replacement happened."""
    snapshot(rel)
    p = os.path.join(REPO, rel)
    body = open(p, encoding="utf-8").read()
    if old not in body:
        raise AssertionError(f"mutation target not found in {rel}: {old[:70]!r}")
    mutated = body.replace(old, new, count)
    if mutated == body:
        raise AssertionError(f"mutation changed NOTHING in {rel}")
    open(p, "w", encoding="utf-8").write(mutated)


def run_gate():
    r = subprocess.run([sys.executable, GATE], capture_output=True, text=True,
                       timeout=900)
    return r.returncode, r.stdout + r.stderr


def leg(name, expect, apply_fn, want_red=True):
    """expect: a substring that must appear in a FAIL line (or None for a no-op)."""
    try:
        apply_fn()
    except AssertionError as exc:
        results.append((name, False, f"MUTATION DID NOT APPLY: {exc}"))
        return
    try:
        code, out = run_gate()
    finally:
        restore_all()

    fails = [l for l in out.splitlines() if l.strip().startswith("FAIL")]
    if not want_red:
        good = code == 0
        results.append((name, good,
                        "stayed GREEN" if good
                        else f"expected GREEN, got exit {code}: {fails[:2]}"))
        return
    if code == 0:
        results.append((name, False, "gate stayed GREEN — the assertion is decoration"))
        return
    hit = [l for l in fails if expect in l]
    results.append((name, bool(hit),
                    f"reddened {expect}" if hit
                    else f"went red, but NOT on {expect}: {[f[:90] for f in fails[:3]]}"))


# ─────────────────────────── the mutations ────────────────────────────────────
def m01():   # the unlock escapes the allowlist arm — 'off' stops meaning nobody
    patch(HEADER,
          "|| ($join_state === 'allowlist' && ($stripe_tester || $join_unlocked));",
          "|| ($join_state === 'allowlist' && $stripe_tester) || $join_unlocked;")


def m02():   # the unlock is dropped entirely — the feature does not exist
    patch(HEADER, "($stripe_tester || $join_unlocked)", "($stripe_tester)")


def m03():   # the cookie is not consulted; everyone anonymous gets /lgjoin/
    patch(HEADER, "($stripe_tester || $join_unlocked)", "(true)")


def m04():   # the unlock reaches the AUTHED pill it must never touch
    patch(HEADER,
          "$join_pill_authed = ($join_state === 'allowlist' && $stripe_tester);",
          "$join_pill_authed = ($join_state === 'allowlist' && ($stripe_tester || $join_unlocked));")


def m05():   # armed check dropped: a disabled config still honours a cookie
    # ⚠️ BOTH copies, and that is the finding this leg produced. The armed check
    # exists in lg_tester_unlock_marked() AND in lg_tester_unlock_token_matches()
    # — defence in depth — so removing either ALONE changes no behaviour at all
    # and the first version of this mutation was recorded as "the assertion is
    # decoration" when in truth the mutation was inert
    # (`feedback-mutation-harness-must-snapshot-not-checkout`: a mutation that
    # does nothing is not evidence).
    patch(UNLOCK,
          "    if (!lg_tester_unlock_armed()) { return false; }   // not armed",
          "    if (false) { return false; }   // not armed")
    patch(UNLOCK,
          "    if (!lg_tester_unlock_armed()) { return false; }\n    if ($token === '')",
          "    if (false) { return false; }\n    if ($token === '')")


def m06():   # timing-unsafe comparison
    patch(UNLOCK, "return hash_equals($cfg['hash'], hash('sha256', $token));",
          "return $cfg['hash'] === hash('sha256', $token);")


def m07():   # the mark becomes script-readable
    patch(UNLOCK, "'httponly' => true,", "'httponly' => false,")


def m08():   # a dotted cookie — the duplicate-cookie trap
    patch(UNLOCK, "        'secure'   => true,",
          "        'domain'   => '.dev2.loothgroup.com',\n        'secure'   => true,")


def m09():   # Strict drops the mark on the pasted link's own navigation
    patch(UNLOCK, "'samesite' => 'Lax',", "'samesite' => 'Strict',")


def m10():   # the token stays in the URL, the history and every Referer
    patch(UNLOCK,
          "    lg_tester_unlock_redirect(lg_tester_unlock_clean_target());\n}\n}",
          "    lg_tester_unlock_redirect((string) ($_SERVER['REQUEST_URI'] ?? '/'));\n}\n}")


def m11():   # fence 1 removed at the CLAIM — a token works anywhere
    patch(UNLOCK,
          "    if (!in_array(lg_tester_unlock_slug(), LG_TESTER_UNLOCK_SCOPE, true)) { return; }",
          "    if (false) { return; }")


def m12():   # a stranded visitor can no longer un-mark themselves
    patch(UNLOCK,
          "    if (in_array(strtolower($val), LG_TESTER_UNLOCK_CLEAR_WORDS, true)) {",
          "    if (lg_tester_unlock_armed() && in_array(strtolower($val), LG_TESTER_UNLOCK_CLEAR_WORDS, true)) {")


def m13():   # a Set-Cookie response becomes cacheable
    patch(UNLOCK, "    header('Cache-Control: no-store, private');", "    ")


def m14():   # ⚠️ THE IAN TRAP: the claim moves below the admin early-return
    patch(ADMGATE,
          "    if (function_exists('lg_tester_unlock_handle_claim')) { lg_tester_unlock_handle_claim(); }\n\n"
          "    if (($ctx['capabilities']['manage_options'] ?? false) === true) {\n"
          "        return; // admin — unchanged, and never gated behind the list\n    }",
          "    if (($ctx['capabilities']['manage_options'] ?? false) === true) {\n"
          "        return; // admin — unchanged, and never gated behind the list\n    }\n"
          "    if (function_exists('lg_tester_unlock_handle_claim')) { lg_tester_unlock_handle_claim(); }")


def m15():   # fence 1 removed at the DOOR — a mark opens manage-subscription
    patch(ADMGATE,
          "        && in_array(lg_tester_unlock_slug(), LG_TESTER_UNLOCK_SCOPE, true)) {",
          "        && true) {")


def m16():   # the admission is removed — the button works, the page refuses
    patch(ADMGATE,
          "    if (function_exists('lg_tester_unlock_marked')\n"
          "        && lg_tester_unlock_marked()",
          "    if (false\n        && lg_tester_unlock_marked()")


def m17():   # the admission jumps AHEAD of the invite check
    body_old = open(os.path.join(REPO, ADMGATE), encoding="utf-8").read()
    m = re.search(r"\n    /\*\*\n     \* A BROWSER HOLDING THE UNLOCK MARK.*?\n    \}\n",
                  body_old, re.S)
    if not m:
        raise AssertionError("could not locate the unlock admission block")
    block = m.group(0)
    snapshot(ADMGATE)
    moved = body_old.replace(block, "\n")
    anchor = "    if (function_exists('lg_membership_invite_admits')"
    moved = moved.replace(anchor, block.strip("\n") + "\n\n" + anchor, 1)
    if moved == body_old:
        raise AssertionError("reorder changed nothing")
    open(os.path.join(REPO, ADMGATE), "w", encoding="utf-8").write(moved)


def m18():   # a real hash lands in the TRACKED config
    patch(CFG, "'token_sha256' => '',",
          "'token_sha256' => '" + "a" * 64 + "',")


def m19():   # the microcache coupling is dropped — /hub/ serves a stale header
    patch(MICRO, "    ~lg_join_unlock 1;\n", "")


def m20():   # the auth failure is noticed and then IGNORED — the funnel
             # proceeds to payment on a refused sign-in
    patch(LGJOIN,
          "                            continueBt.textContent = origAuth;\n"
          "                            return;\n                        }",
          "                            continueBt.textContent = origAuth;\n"
          "                        }")


def m21():   # ONE STRAY BYTE IN THE RENDERED MARKUP — invisible to every
             # href assertion and to every behavioural leg, caught only by the
             # byte-identity comparison. This is the class of the 9-byte
             # whitespace leak #170 found in its own OFF path.
    patch(HEADER, ">Join</a>", ">Join </a>")


def m22():   # the header stops guarding the require — a deploy landing code
             # before config would fatal on every page of seven apps
    patch(HEADER,
          "if (is_readable(__DIR__ . '/tester-unlock.php')) { require_once __DIR__ . '/tester-unlock.php'; }",
          "require_once __DIR__ . '/tester-unlock.php';")


# ─────────────────────────────── no-ops ───────────────────────────────────────
def n01():   # a comment-only edit in the reader must not redden anything
    patch(UNLOCK, "declare(strict_types=1);",
          "/* red-first no-op: a comment, and nothing else. */\ndeclare(strict_types=1);")


def n02():   # a comment-only edit in the gate file
    patch(ADMGATE, "declare(strict_types=1);",
          "declare(strict_types=1);\n/* red-first no-op. */")


MUTATIONS = [
    ("M01 unlock escapes the allowlist arm ('off' stops meaning nobody)", "§A2b", m01),
    ("M02 unlock dropped entirely (the grant)", "§B3", m02),
    ("M03 cookie not consulted — everyone gets /lgjoin/", "§B1", m03),
    ("M04 unlock reaches the AUTHED pill", "§A3", m04),
    ("M05 armed check dropped — a disabled config still honours a cookie", "§B6", m05),
    ("M06 == instead of hash_equals", "§A7", m06),
    ("M07 cookie loses HttpOnly", "§A6", m07),
    ("M08 cookie becomes dotted (duplicate-cookie trap)", "§A6d", m08),
    ("M09 SameSite=Strict drops the mark on the pasted link", "§A6c", m09),
    ("M10 token left in the URL, history and Referer", "§C1b", m10),
    ("M11 scope fence removed at the CLAIM", "§C3", m11),
    ("M12 a stranded visitor cannot un-mark themselves", "§C7c", m12),
    ("M13 the Set-Cookie response becomes cacheable", "§C1e", m13),
    ("M14 ⚠️ the claim moves BELOW the admin early-return (Ian's own test)", "§A5", m14),
    ("M15 scope fence removed at the DOOR", "§D4", m15),
    ("M16 the admission is removed (button works, page refuses)", "§D1", m16),
    ("M17 the admission jumps ahead of the invite check", "§A4b", m17),
    ("M18 a real hash lands in the TRACKED config", "§A1", m18),
    ("M19 the microcache coupling is dropped", "§A8", m19),
    ("M20 the auth failure is noticed and then IGNORED", "§E1b", m20),
    ("M21 one stray byte in the rendered markup", "§B7", m21),
    ("M22 the require loses its is_readable guard", "§A9", m22),
]

def n03():   # PHP-CODE indentation emits nothing. Recorded as a no-op because
             # it was first written as a mutation and proven inert: unlike an
             # indented tag in a TEMPLATE region, indenting a statement changes
             # no rendered byte, so a gate reddening here would be measuring
             # formatting rather than behaviour.
    patch(HEADER, "    $join_unlocked = function_exists",
          "     $join_unlocked = function_exists")


NOOPS = [
    ("N01 comment-only edit in the reader", n01),
    ("N02 comment-only edit in the gate", n02),
    ("N03 PHP-code indentation (emits nothing)", n03),
]


def main():
    print("=" * 72)
    print("RED-FIRST — gate 85 (#180)")
    print("=" * 72)
    try:
        code, out = run_gate()
        if code != 0:
            print("BASELINE IS NOT GREEN — fix that before trusting anything below:")
            print("\n".join(l for l in out.splitlines() if "FAIL" in l)[:2000])
            sys.exit(2)
        print("baseline GREEN\n")

        for name, expect, fn in MUTATIONS:
            leg(name, expect, fn, want_red=True)
            n, good, why = results[-1]
            print(f"  {'✅' if good else '❌'}  {n}\n        {why}")

        for name, fn in NOOPS:
            leg(name, None, fn, want_red=False)
            n, good, why = results[-1]
            print(f"  {'✅' if good else '❌'}  {n}\n        {why}")
    finally:
        cleanup()

    passed = sum(1 for _, g, _ in results if g)
    total = len(results)
    print(f"\nRED-FIRST {passed}/{total}")
    sys.exit(0 if passed == total else 1)


if __name__ == "__main__":
    main()
