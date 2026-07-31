#!/usr/bin/env python3
"""
mock-shots.py — shoot the admin-edit-any decision mock, and ASSERT it, at both
viewports.

    python3 tools/admin-edit-any/mock-shots.py
    python3 tools/admin-edit-any/mock-shots.py --out ~/projects/footer-mockups/admin-edit-any

WHY THIS DOES MORE THAN SCREENSHOT.

The mock's whole argument is that an Edit control must APPEAR for an admin and
VANISH for an ordinary member, on every type and both viewports. A screenshot
cannot tell you that happened — it shows one state, and a mock whose toggle is
quietly broken photographs exactly as well as one that works. So this asserts
the counts in the DOM in both states before it writes any frame, and says so out
loud when they are wrong.

That is also a rehearsal for the real gate: the assertion that matters in the
build is the ABSENCE, and absence is what a green suite is worst at seeing.

CDP mechanics are reused from tools/guide-shots/capture.py rather than re-typed —
its Tab class already carries the three traps this box charges for (own tab, one
persistent socket so device emulation survives, leading-dot gate cookie).

WHY IT RENDERS file:// AND NOT THE URL. The shared chrome-dev.service on this box
is launched WITHOUT --host-resolver-rules, so Chrome resolves dev2.loothgroup.com
publicly and lands on Cloudflare's "Just a moment…" challenge — which screenshots
perfectly happily and would have shipped as a frame if the title check below
weren't here. capture.py's fix is a systemd drop-in, but installing it RESTARTS a
browser other lanes may be mid-run on, and this mock is a self-contained static
page with no external CSS, JS, fonts or images: over file:// it renders byte-for-
byte what the URL serves.

That equivalence is asserted, not assumed — assert_published_matches() diffs the
served bytes against the file being shot, so a stale publish can never masquerade
as a fresh frame.
"""

import argparse, hashlib, os, subprocess, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "guide-shots"))
import capture  # noqa: E402  (path juggling has to come first)

URL      = f"{capture.BASE}/footer-mockups/admin-edit-any/"
PUBLISHED = os.path.expanduser("~/projects/footer-mockups/admin-edit-any/index.html")

# The mock draws four cards in total across the two options; two of them carry
# the Edit affordance (Option A, desktop + phone). Option C's sheet is reached
# from that same control, so it is not counted here.
EXPECT_EDIT_AS_ADMIN  = 2
EXPECT_EDIT_AS_MEMBER = 0


def visible_edit_count(tab):
    """Count Edit controls a human could actually click — not merely present in
    the DOM. `display:none` via a CSS rule leaves the node in the document, so a
    querySelectorAll count would report the affordance still there and pass."""
    return tab.js("""(() => {
        return [...document.querySelectorAll('.edit')]
          .filter(e => e.offsetParent !== null).length;
    })()""")


def visible_absent_count(tab):
    """The member state should not just lose the button — it should say why."""
    return tab.js("""(() => {
        return [...document.querySelectorAll('.absent')]
          .filter(e => e.offsetParent !== null).length;
    })()""")


def assert_published_matches(local_path):
    """The frames must depict what Ian's URL actually serves. Fetch it and compare
    hashes — a lane that edits its mock and forgets to re-publish would otherwise
    ship screenshots of a page nobody else can see."""
    env = {}
    repo = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
    out = subprocess.run(["bash", os.path.join(repo, "tools/gates/gate-env.sh")],
                         capture_output=True, text=True)
    if out.returncode != 0:
        return "SKIPPED — gate-env.sh could not resolve a host/token"
    for line in out.stdout.splitlines():
        k, _, v = line.partition("=")
        env[k] = v
    cmd = ["curl", "-s", "-H", f"Cookie: loothdev_auth={env['LG_GATE_TOKEN']}"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd.append(URL)
    served = subprocess.run(cmd, capture_output=True).stdout
    with open(local_path, "rb") as f:
        disk = f.read()
    if hashlib.sha256(served).hexdigest() == hashlib.sha256(disk).hexdigest():
        return None
    return (f"the URL serves {len(served)}B but the file being shot is {len(disk)}B — "
            f"re-publish before trusting these frames")


def set_viewer(tab, who):
    ok = tab.js(f"""(() => {{
        const b = document.getElementById('v-{who}');
        if (!b) return false; b.click(); return true;
    }})()""")
    time.sleep(0.35)
    return bool(ok)


def frame_the_affordance(tab):
    """Put the Option A cards in the frame.

    The first pass of this script shot the top of the document and produced four
    handsome pictures of the INTRODUCTION — the control the whole mock is about
    was below the fold in every one. Viewport-only capture is right (full-page
    capture renders fixed chrome at the wrong offset), so the fix is to scroll,
    and then to prove the thing is actually on screen rather than trust that the
    scroll landed."""
    tab.js("""(() => {
        const row = document.querySelector('.cols');
        if (row) row.scrollIntoView({block: 'start'});
        window.scrollBy(0, -70);          // clear the sticky View-as bar
    })()""")
    time.sleep(0.45)
    # In frame = inside the viewport box, not merely "exists".
    return tab.js("""(() => {
        const r = document.querySelector('.card .row');
        if (!r) return false;
        const b = r.getBoundingClientRect();
        return b.top >= 0 && b.bottom <= window.innerHeight;
    })()""")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--out", default=os.path.expanduser("~/projects/footer-mockups/admin-edit-any"))
    args = ap.parse_args()
    os.makedirs(args.out, exist_ok=True)

    findings, written = [], []

    drift = assert_published_matches(PUBLISHED)
    if drift and not drift.startswith("SKIPPED"):
        findings.append(drift)
    print(f"publish check: {drift or 'served bytes == file bytes'}")

    tab = capture.Tab()
    try:
        for vp in ("desktop-narrow", "phone"):
            tab.viewport(vp)
            tab.goto(f"file://{PUBLISHED}", settle=1.0)

            title = tab.js("document.title") or ""
            if "Edit any post" not in title:
                # This check exists because Cloudflare's challenge page renders and
                # screenshots exactly like a real frame. Never remove it.
                findings.append(f"{vp}: wrong page — title was {title!r}")
                continue

            # ---- the assertion, both directions, before any frame is written ----
            if not set_viewer(tab, "admin"):
                findings.append(f"{vp}: the Admin toggle is not in the DOM")
                continue
            n_admin = visible_edit_count(tab)
            if n_admin != EXPECT_EDIT_AS_ADMIN:
                findings.append(f"{vp}: admin sees {n_admin} Edit controls, expected {EXPECT_EDIT_AS_ADMIN}")
            if not frame_the_affordance(tab):
                findings.append(f"{vp}/admin: the card action row is not in the frame — frame shows the wrong thing")
            path = os.path.join(args.out, f"shot-{vp}-admin.png")
            written.append((path, tab.shot(path)))

            if not set_viewer(tab, "member"):
                findings.append(f"{vp}: the Member toggle is not in the DOM")
                continue
            n_member = visible_edit_count(tab)
            n_reason = visible_absent_count(tab)
            if n_member != EXPECT_EDIT_AS_MEMBER:
                findings.append(
                    f"{vp}: MEMBER STILL SEES {n_member} Edit control(s) — "
                    f"the absence the mock is arguing for is not actually drawn")
            if n_reason == 0:
                findings.append(f"{vp}: member state shows no reason text where the button was")
            if not frame_the_affordance(tab):
                findings.append(f"{vp}/member: the card action row is not in the frame — frame shows the wrong thing")
            path = os.path.join(args.out, f"shot-{vp}-member.png")
            written.append((path, tab.shot(path)))

            print(f"{vp}: admin={n_admin} edit  member={n_member} edit / {n_reason} reason")
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
    print("OK — the affordance appears for an admin and is GONE for a member, at both viewports.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
