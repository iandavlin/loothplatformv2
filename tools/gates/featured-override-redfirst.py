#!/usr/bin/env python3
"""RED-FIRST for GATE 94 (#200) — break each thing the gate claims to guard, and
prove the gate notices, then put it back.

Why this file exists at all: a gate that has only ever been green is a gate
nobody has tested. This repo has caught four assertions in one day that matched
a string also living in prose, a CSS rule or a comment
(feedback-red-first-that-stays-green), and one of them declared a WORKING page
broken. So every leg below names the section it expects to redden and fails if
that section stays green.

⚠️ SNAPSHOT AND RESTORE BY COPY, NEVER `git checkout --`. Restoring from HEAD
wipes uncommitted work in the same file and once turned one harness bug into ten
false "the assertion is decoration" verdicts
(feedback-mutation-harness-must-snapshot-not-checkout). Every file is read into
memory first and written back in a finally, whatever happens in between.

⚠️ AND TWO NO-OP CONTROLS. A mutation that changes nothing must leave the gate
GREEN; if a no-op reddens it, the gate is reacting to the edit rather than to the
defect, and every other leg's red is worthless.
"""
import os, subprocess, sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
GATE = os.path.join(REPO, "tools", "gates", "featured-override-gate.py")

IDX  = os.path.join(REPO, "archive-poc", "web", "index.php")
FLAG = os.path.join(REPO, "platform", "config", "featured-members.php")
DASH = os.path.join(REPO, "lg-layout-v2", "src", "FeaturedMemberDash.php")
POOL = os.path.join(REPO, "profile-app", "api", "v0", "internal-featured-pool.php")
UPHP = os.path.join(REPO, "profile-app", "web", "u.php")


def run_gate():
    p = subprocess.run([sys.executable, GATE], capture_output=True, text=True, timeout=1200)
    reds = [l.strip() for l in p.stdout.splitlines() if l.strip().startswith("RED")]
    dead = [l.strip() for l in p.stdout.splitlines() if l.strip().startswith("DEAD")]
    return p.returncode, reds, dead, p.stdout


# (label, file, find, replace, expected section tag, expect_red)
MUTATIONS = [
    ("the empty-pool law is removed", IDX,
     "if (!$lg_fm_drawable && (defined('LG_FEATURED_MEMBER')",
     "if (false && !$lg_fm_drawable && (defined('LG_FEATURED_MEMBER')", "[A", True),

    ("the fallback card comes back nameless", IDX,
     "    $name = trim((string) ($fb['name'] ?? ''));\n    if ($name === '') return null;",
     "    $name = '';", "[A", True),

    ("the invite shape stops producing a card", IDX,
     "    if (($fb['kind'] ?? 'member') === 'invite') {",
     "    if (false) {", "[A3", True),

    ("a pinned pick no longer bypasses the tick", IDX,
     "        . ($pinned ? '' : ' AND featured_opt_in = true')",
     "        . ' AND featured_opt_in = true'", "[B1", True),

    # Retargeted after the first run: this originally expected §B2, whose fixture
    # is not opted in and is refused a row by the WHERE clause long before the
    # guard runs — so deleting the guard changed nothing it could see. §B3 exists
    # because of this leg.
    ("the card-ready guard stops applying to anyone", IDX,
     "    if (!$pinned && (trim((string) $u['avatar_url']) === '' || $role === '')) return null;",
     "    if (false) return null;", "[B3", True),

    ("a pinned pick starts honouring consent again", IDX,
     "    if ($pinned) {\n        $consentOn = false;\n        $consentAck = false;\n    }",
     "    if (false) {\n        $consentOn = false;\n        $consentAck = false;\n    }", "[C1", True),

    ("one reader loses its .local.php layer", UPHP,
     "$lg_fmLoc = @include __DIR__ . '/../../platform/config/featured-members.local.php';",
     "$lg_fmLoc = false;", "[D", True),

    ("the tracked default is flipped on with no reason given", FLAG,
     "\t'enabled' => false,", "\t'enabled' => true,", "[E", True),

    ("pinning starts writing the member's consent", DASH,
     "            'pinned'      => true,",
     "            'pinned'      => true, 'featured_opt_in' => true,", "[F1", True),

    ("an ordinary Feature stops writing pinned=false", DASH,
     "            'pinned'     => false,", "            // pinned omitted", "[F2", True),

    ("a Private profile becomes pinnable", POOL,
     "            'eligible'     => $r['profile_visibility'] === 'public',",
     "            'eligible'     => true,", "[F3", True),

    ("the dash stops naming a pinned pick", DASH,
     "'pinned by an admin'", "'featured'", "[F4", True),

    # ── NO-OP CONTROLS ──────────────────────────────────────────────────────
    ("NO-OP: a comment is reworded", IDX,
     "// ── THE EMPTY-POOL LAW (#200, Ian 2026-08-22) ─",
     "// ── the empty-pool law, Ian 2026-08-22 ─", None, False),

    ("NO-OP: whitespace inside the fallback function", IDX,
     "function lg_fm_fallback_card(array $fb): ?array {",
     "function lg_fm_fallback_card(array $fb): ?array  {", None, False),
]


def main():
    print("=== featured-override RED-FIRST (gate 94) ===")
    originals = {}
    for _, path, *_ in MUTATIONS:
        if path not in originals:
            with open(path, encoding="utf-8") as f:
                originals[path] = f.read()

    passed = failed = 0
    try:
        for label, path, find, repl, tag, expect_red in MUTATIONS:
            src = originals[path]
            if find not in src:
                print(f"  BROKEN LEG  {label}: the text it mutates is not in {os.path.basename(path)} "
                      f"— the leg is stale, which is a finding about this harness")
                failed += 1
                continue
            with open(path, "w", encoding="utf-8") as f:
                f.write(src.replace(find, repl, 1))
            try:
                rc, reds, dead, out = run_gate()
            finally:
                with open(path, "w", encoding="utf-8") as f:
                    f.write(src)

            if expect_red:
                hit = [r for r in reds if tag in r]
                if hit:
                    print(f"  caught      {label}\n              -> {hit[0][:150]}")
                    passed += 1
                elif reds:
                    print(f"  WRONG RED   {label}: gate reddened, but not at {tag} — {reds[0][:130]}")
                    failed += 1
                else:
                    print(f"  MISSED      {label}: gate stayed green (rc={rc}, {len(dead)} dead). "
                          f"That assertion is decoration.")
                    failed += 1
            else:
                if rc == 0:
                    print(f"  control ok  {label}")
                    passed += 1
                else:
                    print(f"  FALSE RED   {label}: a no-op reddened the gate — it is reacting to "
                          f"the EDIT, not the defect: {(reds or dead or ['?'])[0][:130]}")
                    failed += 1
    finally:
        for path, src in originals.items():
            with open(path, "w", encoding="utf-8") as f:
                f.write(src)
        print("  (all files restored from the in-memory snapshot)")

    print(f"\nred-first: {passed}/{passed + failed}")
    return 0 if failed == 0 else 1


if __name__ == "__main__":
    sys.exit(main())
