#!/usr/bin/env python3
"""RED-FIRST for gate 36's NO-VERDICT semantics (keeper, 2026-08-15).

A surface whose theme never resolves is the ABSENCE of a measurement, not a
defect in the page. run-all.sh reads 0 green / 2 CANNOT RUN / anything else RED,
so exiting 1 for it blocks every other lane's train for what is really a box-load
problem — the same false-red disease one layer up. Two surfaces did exactly that
to a train before this was fixed.

This proves the semantics instead of trusting them: it forces resolution to fail
on ONE surface and requires the gate to exit 2, not 1 and not 0.

Exit 0 semantics correct / 1 semantics wrong / 2 cannot run.
"""
import importlib.util
import io
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location("g", os.path.join(HERE, "anon-dark-contrast-gate.py"))
g = importlib.util.module_from_spec(spec)
sys.argv = ["redfirst"]
spec.loader.exec_module(g)

# ONE surface, one mode, one device — enough to prove the verdict, cheap to run.
g.GATED_SURFACES = [("signin", "/wp-login.php")]

# THE BREAK: resolution can never succeed.
g.wait_dark_resolved = lambda s, deadline=8.0: False
# keep the forced retries short so this stays a fast check
_orig_measure = g.measure
def _fast_measure(*a, **kw):
    kw["patience"] = 0.15
    return _orig_measure(*a, **kw)
g.measure = _fast_measure

print("=== gate 36 no-verdict red-first: forcing 'dark never resolved' ===")
try:
    g.main()
    rc = 0
except SystemExit as e:
    rc = e.code if isinstance(e.code, int) else 1

if rc == 2:
    print("\n  ok   exited 2 (CANNOT RUN) — no verdict, as house doctrine requires")
    sys.exit(0)
if rc == 1:
    print("\n  FAIL exited 1 (RED) — an unmeasurable surface is reported as a defect; "
          "this blocks every lane's train for a box-load problem")
    sys.exit(1)
print(f"\n  FAIL exited {rc} — an unresolved surface must never be GREEN either")
sys.exit(1)
