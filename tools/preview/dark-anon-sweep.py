#!/usr/bin/env python3
"""dark-anon-sweep.py — measure DARK-MODE contrast on every surface a LOGGED-OUT
visitor meets, screenshot each one, and rank them by how bad they are.

Backlog 21. Ian, 2026-08-14: "the dark mode needs some love for the login stuff"
and "we have a ton of instructions and fields not ready for primetime in dark for
logged out". So: form fields and instruction/help text are the named mess, and
the deliverable is PICTURES plus a ranking, not a verdict.

THE TWO DARK PATHS, AND WHY BOTH ARE TESTED SEPARATELY
------------------------------------------------------
Dark here is a RESOLVED APP THEME (html[data-lguser-theme="dark"]), not a media
query. An anonymous visitor can arrive in dark by two routes that run completely
different code:

  A. APP-DARK   — they picked Dark in the gear. localStorage carries lg-set-theme
     and lg-set-boot, and nginx's server-wide sub_filter boot script paints
     `body{background:#15171a!important}` PRE-PAINT, before any page CSS loads.

  B. OS-DARK    — they picked nothing and their phone/OS is dark. The boot script
     reads ONLY localStorage, so it does nothing at all; dark arrives later (or
     never) from app-settings.js's matchMedia check.

These are not two ways of spelling the same state. B has no boot script, so any
surface that gets its dark exclusively from that inline <style> renders LIGHT
under B — and any surface without app-settings.js loaded on it renders light
under B while A force-darkens its <body> anyway. A sweep that tested only one
path would report a clean bill of health for half the visitors. Every row below
is therefore measured twice, and the theme that actually RESOLVED is recorded
rather than assumed.

Usage:  python3 tools/preview/dark-anon-sweep.py <out-dir>
"""

import base64
import json
import os
import pathlib
import subprocess
import sys
import time
import urllib.request

import websocket

CDP = "http://127.0.0.1:9222"
HOST = "https://dev2.loothgroup.com"
HERE = os.path.dirname(os.path.abspath(__file__))
PROBE = os.path.join(HERE, "..", "gates", "lib", "contrast-probe.js")

DESKTOP = {"width": 1440, "height": 900, "mobile": False, "deviceScaleFactor": 1}
PHONE = {"width": 390, "height": 844, "mobile": True, "deviceScaleFactor": 2}

# Every surface an anonymous visitor can actually reach. Verified 200 (or the
# real redirect target) before being listed — a 403 gate page or a 302 measures
# beautifully and tells you nothing about the page you meant.
SURFACES = [
    ("front",        "/",                             "Front page (anon)"),
    ("hub",          "/hub/",                         "Hub feed (anon)"),
    ("hub-door",     "/hub/acoustic/",                "Category door /hub/acoustic/"),
    ("signin",       "/wp-login.php",                 "SIGN IN"),
    ("lostpassword", "/wp-login.php?action=lostpassword", "Password reset"),
    ("bpnoaccess",   "/wp-login.php?redirect_to=https%3A%2F%2Fdev2.loothgroup.com%2Fregister%2F&bp-auth=1&action=bpnoaccess",
                                                      "Sign-up attempt bounce"),
    ("join",         "/join",                         "Join page"),
    ("lgjoin",       "/lgjoin",                       "Join (lgjoin)"),
    ("events",       "/events/",                      "Events"),
    ("sponsors",     "/sponsors/",                    "Sponsors"),
    ("directory",    "/directory/members/",           "Member directory"),
    ("shop",         "/shop/",                        "Shop"),
]


class Session:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req, timeout=15))
        self.target_id = t["id"]
        # 15s, not 90s. This box is shared and load spikes are real (measured
        # 6+ load average from other lanes' concurrent Chrome sessions,
        # 2026-08-14) — but a call that is genuinely stuck rather than just
        # slow must fail fast, because goto()'s poll loop below RETRIES on
        # exception, and 90s x many silent retries is how a sweep run sits
        # for minutes producing nothing with no error on screen.
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                              timeout=15, suppress_origin=True)
        self._id = 0

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.target_id, timeout=10).read()):
            try:
                fn()
            except Exception:                                  # noqa: BLE001
                pass

    def call(self, method, **params):
        self._id += 1
        self.ws.send(json.dumps({"id": self._id, "method": method, "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self._id:
                if "error" in m:
                    raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})

    def js(self, expr, quiet=False):
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=True, awaitPromise=True)
        if r.get("exceptionDetails"):
            if quiet:
                return None
            raise RuntimeError("JS threw: " + str(r["exceptionDetails"].get("text"))[:160])
        return r.get("result", {}).get("value")

    def goto(self, url, settle=2.2, deadline=25.0):
        self.call("Page.navigate", url=url)
        # WALL-CLOCK deadline, not an iteration count. The original shape (a
        # bounded `for` loop that retries forever on exception) looks bounded
        # but is not: each retry's js() call can itself block up to the socket
        # timeout, so N retries x a stuck socket is N x timeout, not N x 0.15s.
        # That is exactly how a run went silent for 5+ minutes on one page
        # (2026-08-14). A monotonic deadline bounds the TOTAL time regardless
        # of how slow any single call turns out to be.
        start = time.monotonic()
        while time.monotonic() - start < deadline:
            time.sleep(0.15)
            try:
                if self.js("document.readyState", quiet=True) == "complete":
                    break
            except Exception:                                  # noqa: BLE001
                continue                                        # mid-navigation context loss — keep polling
        time.sleep(settle)

    def shot(self, path):
        r = self.call("Page.captureScreenshot", format="png", captureBeyondViewport=False)
        pathlib.Path(path).write_bytes(base64.b64decode(r["data"]))
        return os.path.getsize(path)


def gate_token():
    out = subprocess.run(["bash", os.path.join(HERE, "..", "gates", "gate-env.sh")],
                         capture_output=True, text=True, timeout=30)
    for line in out.stdout.splitlines():
        if line.startswith("LG_GATE_TOKEN="):
            return line.split("=", 1)[1]
    raise SystemExit("CANNOT RUN  no gate token from gate-env.sh")


def arm_anon(s, tok):
    """Anonymous, gated, from scratch. Clear FIRST: Network.setCookie ADDS rather
    than replaces, and a leftover WP session from another lane's run would make
    this whole sweep measure a MEMBER's page while claiming to be anon."""
    s.call("Network.clearBrowserCookies")
    s.call("Network.setCookie", name="loothdev_auth", value=tok,
           domain=".dev2.loothgroup.com", path="/", secure=True)   # gate cookie is DOTTED


def run_surface(s, probe_js, key, path, label, mode, device, outdir):
    url = HOST + path

    # --- put the browser in the requested dark state -----------------------
    # prefers-color-scheme is emulated for BOTH modes so the only difference
    # under test is the localStorage pick, not the OS.
    s.call("Emulation.setEmulatedMedia", features=[
        {"name": "prefers-color-scheme", "value": "dark" if mode == "os-dark" else "light"}])

    # localStorage only exists per-origin and a write on about:blank is a no-op,
    # so land on the origin first, write, then reload into the themed state.
    s.goto(url, settle=0.8)
    if mode == "app-dark":
        # The real journey: the visitor picks Dark in the gear. Let app-settings.js
        # derive lg-set-boot itself rather than hand-forging it — a hand-forged
        # boot blob would test this script's idea of dark, not the product's.
        s.js("try{localStorage.setItem('lg-set-theme','dark')}catch(e){}")
    else:
        s.js("try{localStorage.clear()}catch(e){}")
    s.goto(url, settle=1.6)
    if mode == "app-dark":
        s.goto(url, settle=2.0)   # second pass: lg-set-boot now exists pre-paint

    # --- did dark ACTUALLY resolve? ---------------------------------------
    resolved = s.js("document.documentElement.getAttribute('data-lguser-theme')||'(none)'")
    title = s.js("document.title||''")
    is403 = s.js("!!document.body && /403|Forbidden/.test(document.body.innerText.slice(0,200))")

    data = s.js(probe_js)
    data["surface"] = key
    data["label"] = label
    data["mode"] = mode
    data["device"] = device
    data["path"] = path
    data["resolvedTheme"] = resolved
    data["title"] = title
    data["gate403"] = bool(is403)

    shot = f"{key}__{mode}__{device}.png"
    size = s.shot(os.path.join(outdir, shot))
    data["shot"] = shot
    data["shotBytes"] = size
    return data


def main():
    # LINE-BUFFERED even when piped (`| tail`, a background-task capture file):
    # the previous run sat silent for 5+ minutes with progress prints sitting
    # in Python's block-buffer, indistinguishable from "not doing anything" —
    # a stall and a buffered success looked identical from outside. Every
    # print below is also flush=True as a second belt on the same problem.
    try:
        sys.stdout.reconfigure(line_buffering=True)
    except Exception:                                          # noqa: BLE001
        pass

    outdir = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "./sweep")
    (outdir / "shots").mkdir(parents=True, exist_ok=True)
    probe_js = pathlib.Path(PROBE).read_text()
    tok = gate_token()

    def fresh_session(metrics, mode):
        """A NEW tab + websocket, fully re-armed. Used both for the first
        setup and to RECOVER from a dead connection — see the reconnect note
        below for why this exists as a standalone step rather than inline."""
        ns = Session()
        ns.call("Page.enable"); ns.call("Runtime.enable"); ns.call("Network.enable")
        ns.call("Emulation.setDeviceMetricsOverride", **metrics)
        ns.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
        ns.call("Emulation.setEmulatedMedia", features=[
            {"name": "prefers-color-scheme", "value": "dark" if mode == "os-dark" else "light"}])
        arm_anon(ns, tok)
        return ns

    s = fresh_session(DESKTOP, "app-dark")
    rows = []
    try:
        for device, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
            # Re-arm viewport for THIS device before its first surface runs —
            # not after, and not only inside the reconnect path. A prior
            # version of this loop set metrics once at session-open and then
            # only re-asserted them at the wrong end of the loop, so mobile's
            # very first block ran a full pass at the desktop viewport before
            # anyone noticed (caught in review before this ever shipped data).
            s.call("Emulation.setDeviceMetricsOverride", **metrics)
            s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
            for mode in ("app-dark", "os-dark"):
                for key, path, label in SURFACES:
                    print(f"  {device:7s} {mode:8s} {key:13s} starting...", end="\r", flush=True)
                    arm_anon(s, tok)
                    try:
                        r = run_surface(s, probe_js, key, path, label, mode, device,
                                        str(outdir / "shots"))
                    except Exception as e:                     # noqa: BLE001
                        # RECONNECT, DON'T JUST SKIP. A single dead websocket
                        # (measured live, 2026-08-14: one "Connection timed
                        # out" under box contention) otherwise fails every
                        # remaining call on the SAME session for the rest of
                        # the run — 40+ surfaces reduced to 40+ identical
                        # instant errors and zero data, silently, because each
                        # one still prints and looks like independent
                        # per-surface failures rather than one broken pipe.
                        # One retry on a fresh tab; if THAT also fails, this
                        # surface really is the problem, not the connection,
                        # so move on rather than loop.
                        print(f"  ERROR {key}/{mode}/{device}: {str(e)[:100]} — reconnecting" + " " * 10, flush=True)
                        try:
                            s.finish()
                        except Exception:                       # noqa: BLE001
                            pass
                        try:
                            s = fresh_session(metrics, mode)
                            r = run_surface(s, probe_js, key, path, label, mode, device,
                                            str(outdir / "shots"))
                        except Exception as e2:                 # noqa: BLE001
                            print(f"  ERROR {key}/{mode}/{device}: retry also failed: {str(e2)[:100]}" + " " * 10, flush=True)
                            continue
                    rows.append(r)
                    n = len(r["findings"])
                    worst = r["findings"][0]["ratio"] if n else None
                    flag = "" if r["resolvedTheme"] == "dark" else f"  [theme={r['resolvedTheme']}]"
                    trunc = f"  [scanned {r['scannedElements']}/{r['totalElements']}, {r['truncReason']}]" if r.get("truncated") else ""
                    print(f"  {device:7s} {mode:8s} {key:13s} "
                          f"{n:3d} fail  worst {worst if worst is not None else '-':>5}  "
                          f"bg {r['bodyBg']}{flag}{trunc}" + " " * 10, flush=True)
    finally:
        s.finish()

    (outdir / "sweep.json").write_text(json.dumps(rows, indent=1))
    print(f"\n  {len(rows)} runs -> {outdir/'sweep.json'}", flush=True)


if __name__ == "__main__":
    main()
