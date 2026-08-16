#!/usr/bin/env python3
"""
RED-FIRST PROOF for gate 51 §K (dark contrast) — through the WHOLE chain.

Removes the step's dark block from the SOURCE, re-renders the page, rebuilds the
published snapshot, and runs gate 51. §K must go RED and must name the labels Ian
reported. Then it restores and rebuilds, and gate 51 must return to GREEN.

WHY THE WHOLE CHAIN AND NOT JUST THE SNAPSHOT. §K measures the published
snapshot, so mutating that file alone would prove §K reads pixels — which is the
easy half. The property that matters is that a regression IN THE SOURCE reaches
the measurement, and the snapshot is rebuilt by a separate script from a separate
render. If that pipeline breaks, §K goes on measuring a stale picture and stays
green through a live defect. That is not hypothetical: gate 51 §H exists because
a snapshot was once built from a pre-fix capture.

⚠️ NOT REGISTERED IN run-all.sh AND CARRIES NO NUMBER. It mutates a tracked source
file and rebuilds published artifacts, so it must never run inside a shared suite.
It restores from a BYTE SNAPSHOT taken up front — never `git checkout --`, which
would wipe uncommitted work under test — and refuses to report a result it could
not restore from.

EXIT: 0 the proof held, 1 it did not.
"""
import os
import re
import subprocess
import sys
import tempfile

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
STEP = os.path.join(ROOT, "platform", "mu-plugins", "lg-profile-setup.php")
GATE = os.path.join(ROOT, "tools", "gates", "profile-setup-gate.py")
BUILT = os.path.join(ROOT, "footer-mockups", "profiles-alive", "built")
SNAPS = [os.path.join(BUILT, "step.html"), os.path.join(BUILT, "skipped.html")]
RENDER = os.path.join(ROOT, "tools", "render-profile-setup.php")
BUILDER = os.path.join(ROOT, "tools", "build-profile-setup-snapshots.py")
UID = "1881"
WP = "/var/www/dev"

TOUCHED = [STEP] + SNAPS


def sh(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=900, **kw)


def rebuild():
    """Re-render the real template and rebuild the published snapshots."""
    tmp = tempfile.mkdtemp(prefix="ps-redfirst-")
    r = sh(["sudo", "-n", "wp", "--allow-root", f"--path={WP}", "eval-file", RENDER, UID, tmp])
    if "rendered.html" not in os.listdir(tmp) or "LIVENESS FAIL" in (r.stderr or ""):
        return f"render failed: {(r.stderr or r.stdout)[-300:]}"
    b = sh([sys.executable, BUILDER, tmp, BUILT])
    if b.returncode != 0:
        return f"builder failed: {(b.stderr or b.stdout)[-300:]}"
    return None


def run_gate():
    r = sh([sys.executable, GATE], cwd=ROOT)
    return r.returncode, (r.stdout or "") + (r.stderr or "")


def section_k(out):
    """Only §K's lines — so a RED somewhere else cannot be misread as this proof."""
    m = re.search(r"\nK\. dark(.*?)(?:\n#|\n\d+ checks run\.)", out, re.S)
    return m.group(1) if m else ""


snap = {p: open(p, "rb").read() for p in TOUCHED if os.path.isfile(p)}
failures = []


def restore():
    for p, b in snap.items():
        with open(p, "wb") as fh:
            fh.write(b)
    for p, b in snap.items():
        if open(p, "rb").read() != b:
            print(f"\nFATAL: could not restore {p}")
            sys.exit(1)


print("RED-FIRST PROOF — gate 51 §K (dark contrast)")
print("=" * 78)

try:
    # ── 1. BASELINE: the gate is green on the tree as it stands ────────────────
    print("\n1. baseline — gate 51 must be GREEN before anything is broken")
    code, out = run_gate()
    if code != 0:
        print(f"  FAIL gate 51 is not green to begin with (exit {code}); "
              "a red-first proof from a red baseline proves nothing")
        print(section_k(out)[:800])
        sys.exit(1)
    base_k = section_k(out)
    if "clear AA" not in base_k:
        print("  FAIL §K did not run in the baseline — it may have skipped on environment")
        print(base_k[:600])
        sys.exit(1)
    print(f"  ok   gate 51 GREEN, §K measured {base_k.count('ok ')} combinations")

    # ── 2. THE DEFECT, REINTRODUCED AT SOURCE ─────────────────────────────────
    # Remove the whole dark block. This is exactly the state Ian screenshotted:
    # the card keeps a light fill while injected CSS supplies a light ink.
    print("\n2. remove the dark block from the SOURCE, re-render, rebuild, re-run")
    src = open(STEP, encoding="utf-8").read()
    block = re.search(r"\n  /\* ── DARK ─.*?\n(?=</style>)", src, re.S)
    if not block:
        print("  FAIL could not find the dark block to remove — anchor drifted")
        sys.exit(1)
    open(STEP, "w", encoding="utf-8").write(src.replace(block.group(0), "\n", 1))
    lint = sh(["php", "-l", STEP])
    if lint.returncode != 0:
        print(f"  FAIL removing the block produced invalid php: {lint.stdout[:200]}")
        sys.exit(1)

    why = rebuild()
    if why:
        print(f"  FAIL could not rebuild with the defect in place — {why}")
        sys.exit(1)

    code, out = run_gate()
    k = section_k(out)
    if code == 0:
        failures.append("gate 51 stayed GREEN with the dark block removed — §K is decoration")
        print("  FAIL gate 51 stayed GREEN with the defect reintroduced")
    else:
        print(f"  ok   gate 51 went RED (exit {code})")

    # It must fail for the RIGHT reason, naming the labels Ian reported.
    named = [t for t in ("Your name", "A photo of you", "Where are you", "who sees this")
             if t in k]
    if "fail AA" not in k:
        failures.append("§K did not report an AA failure — the red came from somewhere else")
        print("  FAIL §K did not name an AA failure")
        print(k[:700])
    elif not named:
        failures.append("§K reported an AA failure but named none of Ian's labels")
        print("  FAIL §K went red without naming any reported label")
        print(k[:700])
    else:
        print(f"  ok   §K named the reported labels: {', '.join(named)}")

    # And it must fail in BOTH dark modes at BOTH widths — a gate that only
    # catches one of the four would have missed half of the defect.
    combos = [c for c in ("desktop/app-dark", "desktop/os-dark",
                          "mobile/app-dark", "mobile/os-dark") if c in k]
    if len(combos) < 4:
        failures.append(f"§K flagged only {len(combos)} of 4 dark combinations: {combos}")
        print(f"  FAIL only {len(combos)}/4 dark combinations went red")
    else:
        print("  ok   all 4 dark combinations (both modes x both widths) went red")

    # AND IT MUST CATCH THE WHOLE CLASS, not one unlucky label. The defect hits
    # every element that inherits the theme ink onto a light surface plus .lede
    # in the other direction; a section reporting one failure would be measuring
    # a coincidence.
    counts = [int(n) for n in re.findall(r"(\d+) of \d+ labels fail AA", k)]
    if counts and min(counts) >= 7:
        print(f"  ok   each dark combination flagged {min(counts)}-{max(counts)} labels, "
              "not a lone outlier")
    else:
        failures.append(f"§K flagged too few labels to be the real defect: {counts}")
        print(f"  FAIL dark combinations flagged only {counts} labels")

    # LIGHT MUST STAY GREEN. The defect is dark-only; a section that reddens the
    # light page too is measuring something other than the theme.
    #
    # CHECKED LINE BY LINE, and the first cut of this check was WRONG in a way
    # worth keeping: `re.search("desktop/light.*?fail AA", k, re.S)` matched,
    # because with DOTALL the `.` crosses newlines and happily reached the
    # app-dark line printed BELOW the light one. It reported the gate as not
    # theme-specific when the gate was perfectly correct — a harness bug wearing
    # the costume of a finding, which is exactly what a red-first is supposed to
    # be immune to. A per-line check cannot span rows.
    light_bad = [ln.strip() for ln in k.splitlines()
                 if "/light" in ln and "fail AA" in ln]
    if light_bad:
        failures.append("§K also failed the LIGHT page — it is not measuring the theme")
        print("  FAIL light went red too; the assertion is not theme-specific")
        for ln in light_bad[:2]:
            print("       " + ln[:150])
    else:
        print("  ok   light stayed clean — the finding is specific to dark")

finally:
    restore()
    why = rebuild()
    if why:
        print(f"\nFATAL: restored the source but could NOT rebuild the snapshots — {why}")
        print("The published snapshots may be showing the defect. Rebuild by hand.")
        sys.exit(1)

# ── 3. AND IT GOES GREEN AGAIN ────────────────────────────────────────────────
# A gate that can never go green is exactly as useless as one that can never go
# red, and this is the half a red-first usually skips.
print("\n3. restore — gate 51 must return to GREEN")
code, out = run_gate()
if code != 0:
    failures.append(f"gate 51 did not return to green after restore (exit {code})")
    print(f"  FAIL gate 51 is still red after restore (exit {code})")
    print(section_k(out)[:700])
else:
    print(f"  ok   gate 51 GREEN again, §K measured {section_k(out).count('ok ')} combinations")

print("\n" + "=" * 78)
if failures:
    print("RED-FIRST PROOF FAILED:")
    for f in failures:
        print("  ✗ " + f)
    sys.exit(1)
print("RED-FIRST PROOF HELD — §K goes red on the real defect, through the whole "
      "source→render→snapshot chain, names Ian's labels, fires in all four dark "
      "combinations, leaves light alone, and returns to green.")
