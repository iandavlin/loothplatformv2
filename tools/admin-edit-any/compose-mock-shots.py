#!/usr/bin/env python3
"""
compose-mock-shots.py — shoot the front-end-compose mock and assert it.

Same discipline as mock-shots.py (see its docstring for the two traps this box
charges for: Cloudflare's challenge page screenshots happily, and viewport-only
capture will happily frame the introduction instead of the thing).

What it asserts beyond "a page rendered":
  - the required-field count is what the scope claims (5 marked needed, not 12)
  - the optional fold starts CLOSED, which is the entire "easy" argument
  - and opens on click, so the extras are reachable rather than merely hidden
"""
import argparse, hashlib, os, subprocess, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "guide-shots"))
import capture  # noqa: E402

URL       = f"{capture.BASE}/footer-mockups/frontend-compose/"
PUBLISHED = os.path.expanduser("~/projects/footer-mockups/frontend-compose/index.html")


def published_matches():
    repo = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
    out = subprocess.run(["bash", os.path.join(repo, "tools/gates/gate-env.sh")],
                         capture_output=True, text=True)
    if out.returncode != 0:
        return "SKIPPED — gate-env.sh could not resolve"
    env = dict(l.partition("=")[::2] for l in out.stdout.splitlines())
    cmd = ["curl", "-s", "-H", f"Cookie: loothdev_auth={env['LG_GATE_TOKEN']}"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd.append(URL)
    served = subprocess.run(cmd, capture_output=True).stdout
    disk = open(PUBLISHED, "rb").read()
    if hashlib.sha256(served).hexdigest() == hashlib.sha256(disk).hexdigest():
        return None
    return f"URL serves {len(served)}B, file is {len(disk)}B — re-publish"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=os.path.expanduser("~/projects/footer-mockups/frontend-compose"))
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)

    findings, written = [], []
    drift = published_matches()
    if drift and not drift.startswith("SKIPPED"):
        findings.append(drift)
    print(f"publish check: {drift or 'served bytes == file bytes'}")

    tab = capture.Tab()
    try:
        for vp, sel in (("desktop-narrow", "#fold-d"), ("phone", "#fold-p")):
            tab.viewport(vp)
            tab.goto(f"file://{PUBLISHED}", settle=1.0)
            if "Post a Loothprint" not in (tab.js("document.title") or ""):
                findings.append(f"{vp}: wrong page")   # Cloudflare guard — never remove
                continue

            # The whole claim: the type defines 12 fields, but a member is asked for 5.
            # Count PER FORM — the page draws the desktop and phone forms side by side,
            # and a page-wide count silently returned 10 and read as a broken claim.
            req = tab.js("""(() => {
                const forms = [...document.querySelectorAll('.form')];
                return forms.map(f => f.querySelectorAll('.req').length);
            })()""")
            if not req or any(n != 5 for n in req):
                findings.append(f"{vp}: fields marked needed per form = {req}, scope says 5 each")

            closed = tab.js(f"!document.querySelector('{sel}').classList.contains('open')")
            if not closed:
                findings.append(f"{vp}: the optional fold starts OPEN — that defeats the whole shape")

            # Frame the form this viewport is ABOUT. The page draws both variants;
            # framing '.form' shot the DESKTOP one on the phone run, under a caption
            # reading "1280px" — a frame that is wrong in the most confusing way.
            want = ".form--phone" if vp == "phone" else ".form:not(.form--phone)"
            tab.js(f"""(() => {{ const f = document.querySelector('{want}');
                       if (f) f.scrollIntoView({{block: 'start'}}); window.scrollBy(0, -46); }})()""")
            time.sleep(0.4)
            if not tab.js(f"""(() => {{ const f = document.querySelector('{want}');
                    if (!f) return false; const b = f.getBoundingClientRect();
                    return b.top < window.innerHeight && b.bottom > 0; }})()"""):
                findings.append(f"{vp}: {want} is not in the frame")
            p = os.path.join(args.out, f"shot-{vp}-form.png")
            written.append((p, tab.shot(p)))

            # And prove the extras are reachable, not just hidden.
            tab.js(f"document.querySelector('[data-fold=\"{sel[1:]}\"]').click()")
            time.sleep(0.35)
            if tab.js(f"!document.querySelector('{sel}').classList.contains('open')"):
                findings.append(f"{vp}: the fold did not open on click — the extras are unreachable")
            tab.js(f"""(() => {{ const e = document.querySelector('{sel}');
                       if (e) e.scrollIntoView({{block: 'center'}}); }})()""")
            time.sleep(0.35)
            p = os.path.join(args.out, f"shot-{vp}-extras.png")
            written.append((p, tab.shot(p)))
            print(f"{vp}: needed-per-form={req}, fold starts closed and opens on click")
    finally:
        tab.close()

    print()
    for p, n in written:
        print(f"  wrote {os.path.basename(p)}  ({n // 1024}kb)")
    print()
    if findings:
        print(f"{len(findings)} FINDING(S):")
        for f in findings:
            print(f"  ✗ {f}")
        return 1
    print("OK — five things asked for, extras folded away by default and reachable on tap.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
