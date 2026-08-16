#!/usr/bin/env python3
"""RED-FIRST for wait_dark_styles — the stylesheet precondition.

Proves the predicate in BOTH directions against the real browser:
  1. a page with NO app-settings dark stylesheet must return False
  2. a settled dark page must return True

A precondition that always returns True is worse than none: it would wave every
attribute-set-but-rules-missing page straight through, which is exactly the state
that produced 26 phantom "invisible input" findings on 2026-08-16.

Exit 0 correct / 1 predicate broken / 2 cannot run.
"""
import importlib.util, os, sys
HERE=os.path.dirname(os.path.abspath(__file__))
spec=importlib.util.spec_from_file_location("g", os.path.join(HERE,"anon-dark-contrast-gate.py"))
g=importlib.util.module_from_spec(spec); sys.argv=["redfirst"]; spec.loader.exec_module(g)
env=g.gate_env(); host,tok=env["LG_GATE_HOST"],env["LG_GATE_TOKEN"]
fails=[]
s=g.Session()
try:
    g.arm_anon(s,tok)
    s.call("Emulation.setDeviceMetricsOverride",**g.DESKTOP)
    s.call("Emulation.setEmulatedMedia",features=[{"name":"prefers-color-scheme","value":"dark"}])
    s.goto(host+"/hub/",settle=1.0); s.js("try{localStorage.clear()}catch(e){}")
    s.goto(host+"/hub/",settle=2.0)
    if not g.wait_dark_resolved(s):
        print("CANNOT RUN  page never resolved dark; cannot test the stylesheet predicate")
        sys.exit(2)
    # 2. the counter-proof first, on the genuine settled page
    if not g.wait_dark_styles(s):
        fails.append("TOO STRICT: returned False on a settled dark page — this would "
                     "discard every real measurement")
    else:
        print("  ok   True on a settled dark page — not uselessly strict")
    # 1. THE BREAK: remove the stylesheet and require the predicate to notice
    s.js("(function(){var e=document.getElementById('lg-dark-style');if(e)e.remove();})()")
    if g.wait_dark_styles(s, deadline=2.0):
        fails.append("VACUOUS: returned True with #lg-dark-style REMOVED — the precondition "
                     "would wave through the attribute-without-rules window that produced "
                     "26 phantom findings")
    else:
        print("  ok   False once the dark stylesheet is removed — the guard is real")
finally:
    s.finish()
if fails:
    print("\nRED-FIRST FAILED")
    for f in fails: print("  FAIL  "+f)
    sys.exit(1)
print("stylesheet precondition proven in both directions")
