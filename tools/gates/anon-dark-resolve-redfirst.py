#!/usr/bin/env python3
"""RED-FIRST for gate 36's resolve PRECONDITION (wait_dark_resolved).

A precondition that always returns True is worse than none: it would wave every
mid-boot page straight through while LOOKING like a guard. So this proves the
predicate in BOTH directions against the real browser and the real pages:

  1. a page that will NEVER be dark (prefers-color-scheme: light, no stored
     pick — the theme resolves to 'default') must return False
  2. the same page under the os-dark path must return True

If (1) passes the predicate is vacuous; if (2) fails it is uselessly strict.

Exit 0 both correct / 1 the predicate is broken / 2 cannot run.
"""
import importlib.util
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
spec = importlib.util.spec_from_file_location(
    "g", os.path.join(HERE, "anon-dark-contrast-gate.py"))
g = importlib.util.module_from_spec(spec)
sys.argv = ["redfirst"]
spec.loader.exec_module(g)

env = g.gate_env()
host, tok = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"]
url = host + "/wp-login.php"
fails = []


def probe(mode, deadline):
    s = g.Session()
    try:
        g.arm_anon(s, tok)
        s.call("Emulation.setDeviceMetricsOverride", **g.DESKTOP)
        s.call("Emulation.setEmulatedMedia", features=[
            {"name": "prefers-color-scheme", "value": "dark" if mode == "os-dark" else "light"}])
        s.goto(url, settle=0.8)
        s.js("try{localStorage.clear()}catch(e){}")
        s.goto(url, settle=1.6)
        got = g.wait_dark_resolved(s, deadline=deadline)
        theme = s.js("document.documentElement.getAttribute('data-lguser-theme')", quiet=True)
        return got, theme
    finally:
        s.finish()


print("=== gate 36 resolve-precondition red-first ===")

# 1. THE BREAK: a page that resolves LIGHT must NOT be reported as dark-resolved.
got, theme = probe("light", 4.0)
if got:
    fails.append(f"VACUOUS: wait_dark_resolved returned True on a LIGHT page (theme={theme}) "
                 f"— the precondition would wave every mid-boot page through")
else:
    print(f"  ok   returns False on a light page (theme={theme}) — the guard is real")

# 2. THE COUNTER-PROOF: it must still return True when the page IS dark.
got, theme = probe("os-dark", 8.0)
if not got:
    fails.append(f"TOO STRICT: wait_dark_resolved returned False on a genuinely dark page "
                 f"(theme={theme}) — this would discard real measurements")
else:
    print(f"  ok   returns True on a dark page (theme={theme}) — not uselessly strict")

if fails:
    print("\nRED-FIRST FAILED — the resolve precondition is not trustworthy")
    for f in fails:
        print("  FAIL  " + f)
    sys.exit(1)
print("resolve precondition proven in both directions")
