#!/usr/bin/env python3
"""WORK BOARD dark contrast — Ian's primary interface. UNREGISTERED, no number yet.

NOT IN run-all.sh ON PURPOSE, and it must not be added until this lane's fix is
MERGED AND DEPLOYED. It measures the SERVE, which runs main, so it reads RED
until then — and a red gate in run-all.sh blocks every lane on the box. Keeper
mints the number and registers it; the registration line is at the bottom.

WHAT IT ASSERTS: /wip-board.php has ZERO sub-AA findings in dark, in all four
states (app-dark x os-dark, desktop x mobile). Ian, 2026-08-16: "workboard is now
in darkmode and needs contrast love." Measured before the fix: 276 findings,
identical in every state — the board shipped a light-only :root and no dark rules
at all, so the boot script's forced dark ink landed on white panels (1.08-1.25:1)
while the board's own light-theme ink stranded on the dark body (1.91:1).

EVERY PRECONDITION THIS LANE LEARNED THE HARD WAY IS APPLIED, because each one
produced a wrong answer on this box within the last 24h:
  liveness   — the dev gate serves a styled 403 that is identical in light and
               dark at every width, so a visual pass goes green having measured
               nothing.
  resolved   — the theme attribute must be set, or the page is measured LIGHT.
  stylesheet — app-settings.js sets the attribute and SEPARATELY injects
               <style id="lg-dark-style">; between them the page has the dark
               attribute without the dark rules, which reads ~1.0-1.2:1 and is
               pure phantom.
  frozen     — resolution is not settlement; a CSS transition mid-flight
               photographs an interpolated colour no settled page ever shows.
A surface failing any precondition is NO VERDICT (exit 2), never red — "the page
never got dark enough to measure" is the absence of a measurement, not a defect.

Exit 0 green / 1 real findings / 2 cannot run.
"""
import importlib.util, json, os, sys, time

HERE = os.path.dirname(os.path.abspath(__file__))
_spec = importlib.util.spec_from_file_location("g", os.path.join(HERE, "anon-dark-contrast-gate.py"))
g = importlib.util.module_from_spec(_spec)
sys.argv = ["board-gate"]
_spec.loader.exec_module(g)

# SELF-CONTAINED ON PURPOSE: the stylesheet precondition lives in this lane's
# UNMERGED train-5 work, so importing it would couple this gate to a merge order
# and make it exit 2 on main today. Uses the shared one when present, falls back
# to a local copy otherwise — so it runs correctly before AND after that merge.
def wait_dark_styles(s, deadline=8.0):
    fn = getattr(g, "wait_dark_styles", None)
    if fn:
        return fn(s, deadline=deadline)
    start = time.monotonic()
    while time.monotonic() - start < deadline:
        try:
            if s.js("!!document.getElementById('lg-dark-style')", quiet=True) is True:
                return True
        except Exception:                                       # noqa: BLE001
            pass
        time.sleep(0.2)
    return False


PATH = "/wip-board.php"
FREEZE = ("(function(){var t=document.createElement('style');"
          "t.textContent='*,*::before,*::after{transition:none!important;animation:none!important}';"
          "document.head.appendChild(t);})()")


def main():
    env = g.gate_env()
    host, tok = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"]
    probe = open(g.PROBE).read()
    url = host + PATH
    red, cannot = [], []

    for mode in ("app-dark", "os-dark"):
        for dev, metrics in (("desktop", g.DESKTOP), ("mobile", g.PHONE)):
            label = f"board/{mode}/{dev}"
            s = g.Session()
            try:
                g.arm_anon(s, tok)
                s.call("Emulation.setDeviceMetricsOverride", **metrics)
                s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
                s.call("Emulation.setEmulatedMedia", features=[
                    {"name": "prefers-color-scheme", "value": "dark" if mode == "os-dark" else "light"}])
                s.goto(url, settle=1.0)
                s.js("try{localStorage.clear()}catch(e){}")
                if mode == "app-dark":
                    s.js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
                s.goto(url, settle=2.5)
                if mode == "app-dark":
                    s.goto(url, settle=2.5)

                # LIVENESS first — a gated 403 measures as nothing.
                if s.js("/403|Forbidden|Restricted/i.test((document.body&&document.body.innerText||'').slice(0,300))"):
                    cannot.append(f"{label}: dev-gate 403 — not signed in to the gate, measured nothing")
                    continue
                if not s.js("!!document.querySelector('.wrap, .app')"):
                    cannot.append(f"{label}: board markup absent — wrong page or it failed to render")
                    continue
                if not g.wait_dark_resolved(s):
                    cannot.append(f"{label}: theme never resolved dark — measured LIGHT, no verdict")
                    continue
                if not wait_dark_styles(s):
                    cannot.append(f"{label}: dark attribute set but #lg-dark-style never injected — "
                                  f"the page had the attribute without the rules; findings would be phantoms")
                    continue

                s.js(FREEZE)
                time.sleep(0.5)
                data = s.js(probe)
                findings = data.get("findings", [])
                if findings:
                    red.append(f"RED  {label}  {len(findings)} sub-AA finding(s)")
                    seen = set()
                    for f in findings:
                        k = (f.get("kind"), f.get("fg"), f.get("bg"))
                        if k in seen:
                            continue
                        seen.add(k)
                        red.append(f"     {label}  {f.get('kind')} {f.get('ratio')}:1 "
                                   f"(need {f.get('need')})  {f.get('fg')} on {f.get('bg')}  "
                                   f"[{str(f.get('sel',''))[-64:]}]")
                else:
                    print(f"  ok   {label}  0 finding(s), theme=dark, stylesheet present")
            except Exception as e:                              # noqa: BLE001
                cannot.append(f"{label}: {str(e)[:90]}")
            finally:
                s.finish()

    if red:
        print("\nBOARD DARK CONTRAST RED — Ian's primary interface has unreadable text:\n")
        for line in red:
            print(line)
        return 1
    if cannot:
        print("\nCANNOT RUN — no verdict on the surfaces below. Not a pass.")
        for line in cannot:
            print("   " + line)
        return 2
    print("\nGREEN — /wip-board.php clears AA in dark on all four states.")
    return 0


if __name__ == "__main__":
    sys.exit(main())

# REGISTRATION LINE for run-all.sh, once keeper mints a number and this lane's
# fix is deployed (it reads the serve, so it is RED until then):
#
#   echo "=== GATE NN: the work board clears AA in dark (Ian's primary interface) ==="
#   run "board-dark-contrast" python3 "$(dirname "$0")/board-dark-contrast-gate.py"
