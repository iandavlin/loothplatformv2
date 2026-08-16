#!/usr/bin/env python3
"""
flag-register-gate.py — GATE 62 — a branch that ADDS a flag must register it
in docs/FLAGS.md.  (Number minted by keeper 2026-08-16; lanes never self-mint.)

WHY THIS EXISTS. docs/FLAGS.md states the rule in its own header: "any merge that
adds, flips, or retires a flag updates this file IN THE SAME COMMIT — keeper
refuses the merge otherwise." Nothing enforced it. On 2026-08-16 the same omission
was found FOUR times in one lane in one session:

  · platform/config/frontend-compose.php taught a mechanism that had been removed
  · FLAGS.md had no row for compose at all, while compose was ON on dev2
  · FLAGS.md had no row for LG_NOTIF_QUICKREPLY_ENABLED, on a branch awaiting merge
  · the lane's CURRENT handoff described a different lane entirely

CRAFT-STANDARD's law: a defect class discovered TWICE must be encoded as a gate
before it is fixed the second time. This is that gate for the mechanically
checkable half of the class. It cannot catch prose that is merely WRONG — only
prose that is MISSING — and that limit is stated rather than papered over.

WHAT IT CHECKS, against the branch's diff vs origin/main:
  1. flag symbols INTRODUCED by the diff (new tracked config files, new
     'enabled' => keys, new define('LG_*'), new getenv('LG_*')
  2. every introduced symbol must appear somewhere in docs/FLAGS.md ON THE BRANCH

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.

⚠️ CANNOT RUN IS 2, NOT 3. run-all.sh reads 0 green, 2 no-verdict and ANYTHING
ELSE as RED, so a gate that exits 3 where it merely could not run turns the whole
suite red for every lane. That trap was live in this repo twice this month.
"""
import re, subprocess, sys, os

BASE = os.environ.get("LG_FLAGREG_BASE", "origin/main")
REF  = os.environ.get("LG_FLAGREG_REF", "HEAD")
# Resolve the repo from THIS FILE, never from the caller's cwd: run-all.sh is
# invoked from wherever the lane happens to be standing, and a repo root one
# dirname short is a recorded way to turn a working gate into a no-verdict.
# tools/gates/<this file>  ->  ../..
REPO = os.environ.get("LG_FLAGREG_REPO") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))

def git(*a):
    r = subprocess.run(["git", "-C", REPO, *a], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"git {' '.join(a)}: {r.stderr.strip()[:200]}")
    return r.stdout

# A flag symbol is LG_-prefixed and SHOUTY, or a tracked config file's own name.
SYM = re.compile(r"\b(LG_[A-Z0-9_]{4,})\b")
CFG = re.compile(r"^platform/config/([a-z0-9-]+)\.php$")

def main() -> int:
    try:
        mb = git("merge-base", BASE, REF).strip()
        diff = git("diff", "-U0", mb, REF)
        files = [l for l in git("diff", "--name-only", mb, REF).splitlines() if l]
    except RuntimeError as e:
        print(f"CANNOT RUN: {e}")
        return 2

    if not files:
        print("CANNOT RUN: empty diff vs base — nothing to judge")
        return 2

    added = [l[1:] for l in diff.splitlines()
             if l.startswith("+") and not l.startswith("+++")]
    added_txt = "\n".join(added)

    # ── WHAT COUNTS AS A FLAG ────────────────────────────────────────────────
    # NOT "any new LG_ symbol". The first draft did that and RED-ed on
    # LG_FC_DRAFT_META (a post-meta key) and LG_FC_DRAFT_TTL_DAYS (an integer) —
    # constants, not switches. A gate that reds on non-defects blocks every lane,
    # which is the exact harm this gate exists to prevent, so the definition is
    # narrowed to things that can actually be SWITCHED:
    #
    #   · read from getenv() or $_SERVER      — an env/fastcgi_param flag
    #   · define()d to a BOOLEAN expression   — not to a string or a number
    #   · a brand-new tracked platform/config/*.php carrying an 'enabled' key
    #
    # Deliberately conservative: a missed flag is a defect this gate did not
    # catch, but a false RED is a defect this gate CAUSES.
    BOOLISH = ("===", "!==", "==", "true", "false", "!empty", "getenv", "$_SERVER",
               "filter_var", "(bool)")
    introduced = set()

    for line in added:
        for m in re.finditer(r"getenv\(\s*['\"](LG_[A-Z0-9_]{4,})['\"]", line):
            introduced.add(m.group(1))
        for m in re.finditer(r"\$_SERVER\s*\[\s*['\"](LG_[A-Z0-9_]{4,})['\"]", line):
            introduced.add(m.group(1))
        for m in re.finditer(r"define\(\s*['\"](LG_[A-Z0-9_]{4,})['\"]\s*,(.*)$", line):
            sym, val = m.group(1), m.group(2)
            if any(b in val for b in BOOLISH):
                introduced.add(sym)

    # A define() whose value sits on the NEXT line (the common wrapped form).
    for i, line in enumerate(added):
        m = re.search(r"define\(\s*['\"](LG_[A-Z0-9_]{4,})['\"]\s*,\s*$", line)
        if m and i + 1 < len(added) and any(b in added[i + 1] for b in BOOLISH):
            introduced.add(m.group(1))

    # Symbols already present on the base tree are not "introduced".
    if introduced:
        try:
            base_blob = git("grep", "-h", "-o", "-E", r"LG_[A-Z0-9_]{4,}", mb)
        except RuntimeError:
            base_blob = ""
        introduced -= set(SYM.findall(base_blob))

    # A brand-new tracked config file with an 'enabled' key is a flag by construction.
    base_files = set(git("ls-tree", "-r", "--name-only", mb).splitlines())
    for f in files:
        m = CFG.match(f)
        if m and f not in base_files:
            try:
                if "'enabled'" in git("show", f"{REF}:{f}"):
                    introduced.add(m.group(1))
            except RuntimeError:
                pass

    if not introduced:
        print("GREEN — this diff introduces no new flag symbol.")
        return 0

    try:
        register = git("show", f"{REF}:docs/FLAGS.md")
    except RuntimeError:
        print("CANNOT RUN: docs/FLAGS.md not present on this ref")
        return 2

    touched = "docs/FLAGS.md" in files
    missing = sorted(s for s in introduced if s not in register)

    print(f"flag symbols introduced by this diff ({len(introduced)}): "
          f"{', '.join(sorted(introduced))}")
    print(f"docs/FLAGS.md touched by this diff: {touched}")
    for s in sorted(introduced):
        print(f"  {'ok  ' if s in register else 'RED '} {s}"
              f"{'' if s in register else '  — NOT in docs/FLAGS.md'}")

    if missing:
        print(f"\nRED — {len(missing)} flag(s) introduced without a register entry: "
              f"{', '.join(missing)}")
        print("docs/FLAGS.md: 'any merge that adds, flips, or retires a flag updates "
              "this file IN THE SAME COMMIT — keeper refuses the merge otherwise.'")
        return 1

    print("\nGREEN — every introduced flag is registered.")
    return 0

if __name__ == "__main__":
    try:
        sys.exit(main())
    except Exception as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
