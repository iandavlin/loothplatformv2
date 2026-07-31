#!/usr/bin/env python3
"""
messages-header-footprint — a many-name group header must not eat the panel.

Ian approved the mock 2026-07-31 ("Approve sizing"). Two things shipped with it and both
are asserted here, because the second one is where this change could do real harm:

  1. THE NAMES CLAMP to one line with a "+N more" chip that expands them in place.
  2. "Group · N people · everyone here sees your reply" SURVIVES IN BOTH STATES.
     That line is the entire point of the header. It used to be a CHILD of
     .lg-msg__peer-names, so the obvious implementation — clamp that element — hides it,
     and a group thread then reads as a private 1:1 while replies reach people the header
     never named. The first draft of the mock did exactly that and was thrown out. So the
     note is now a SIBLING of the clamped element, and this gate fails if it ever stops
     being visible. Truncating the list is only acceptable BECAUSE the count is stated.

MEASURED ON A REAL 12-PARTICIPANT FIXTURE, not extrapolated. dev2's largest organic thread
has 4 people, which is not enough to show the defect: keeper asked for the saving to be
measured, so the fixture below is created in the DB and the gate refuses to run without it
rather than quietly grading a 4-name thread and reporting a smaller number as if it were
the answer.

Fixture (idempotent, dev2 only) — see the lane's SQL, thread uuid:
    aaaaaaaa-0000-4000-8000-00000000f1f0

Run:
    python3 tools/gates/messages-header-footprint-gate.py --url http://127.0.0.1:8899
Exit: 0 green, 1 RED, 2 CANNOT RUN.
"""
import argparse, json, sys, time, urllib.request

CDP = "http://127.0.0.1:9222"
FIXTURE = "aaaaaaaa-0000-4000-8000-00000000f1f0"
MIN_PEERS = 10
NO_VERDICT = 2
passes = failures = 0


def log(*a): print(" ".join(str(x) for x in a), flush=True)


def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok


def check_cmp(label, got, want, fn, desc):
    global passes, failures
    ok = fn(got, want)
    if ok: passes += 1
    else:  failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want {desc} {want!r}"))
    return ok


_open = {"page": None, "tid": None}


def cannot_run(why):
    try:
        if _open["page"]: _open["page"].close()
        if _open["tid"]:  close_page(_open["tid"])
    except Exception: pass
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)


try:
    import websocket
except ImportError:
    cannot_run("python3 websocket-client is not installed")


class Page:
    def __init__(self, ws):
        self.ws = websocket.create_connection(ws, timeout=60, suppress_origin=True); self.n = 0

    def send(self, m, p=None):
        self.n += 1; i = self.n
        self.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(self.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})

    def ev(self, e):
        r = self.send("Runtime.evaluate",
                      {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"):
            raise RuntimeError("JS: " + str(r["exceptionDetails"].get("text")))
        return r["result"].get("value")

    def close(self):
        try: self.ws.close()
        except Exception: pass


def new_page():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    return t["id"], Page(t["webSocketDebuggerUrl"])


def close_page(tid):
    try: urllib.request.urlopen(CDP + f"/json/close/{tid}").read()
    except Exception: pass


VIS = """(sel => { const e = document.querySelector(sel); if (!e) return false;
  const s = getComputedStyle(e), r = e.getBoundingClientRect();
  return s.display!=='none' && s.visibility!=='hidden' && r.width>0 && r.height>0; })"""


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--url", default="http://127.0.0.1:8899")
    ap.add_argument("--thread", default=FIXTURE)
    ap.add_argument("--width", type=int, default=1440)
    a = ap.parse_args()
    base = a.url.rstrip("/")
    try:
        urllib.request.urlopen(CDP + "/json/version", timeout=5).read()
    except Exception as e:
        cannot_run(f"no CDP engine: {e}")

    tid, p = new_page()
    _open["tid"], _open["page"] = tid, p
    try:
        p.send("Page.enable"); p.send("Runtime.enable"); p.send("Network.enable")
        p.send("Network.setCacheDisabled", {"cacheDisabled": True})
        p.send("Network.clearBrowserCache")
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": a.width, "height": 950, "deviceScaleFactor": 1, "mobile": False})
        p.send("Page.navigate", {"url": base + "/hub/"})
        for _ in range(240):
            time.sleep(0.25)
            try:
                if p.ev("document.readyState") == "complete": break
            except Exception: pass
        time.sleep(5)

        if not p.ev("!!document.querySelector('[data-lg-msg-link]')"):
            cannot_run("no messages entry point (not signed in?)")
        p.ev("document.querySelector('[data-lg-msg-link]').click()"); time.sleep(3)

        opened = p.ev(f"""(() => {{
          const r = [...document.querySelectorAll('[data-thread-uuid]')]
            .find(x => x.getAttribute('data-thread-uuid') === {json.dumps(a.thread)});
          if (!r) return false;
          r.click(); return true;
        }})()""")
        if not opened:
            cannot_run(f"the {MIN_PEERS}+-participant fixture thread {a.thread} is not in "
                       f"this member's list — create it (see the header of this file). "
                       f"Grading a 4-name thread instead would report a smaller saving as "
                       f"if it were the answer.")
        time.sleep(3.5)

        peers = p.ev("document.querySelectorAll('.lg-msg__peer-name').length")
        if not peers or peers < MIN_PEERS:
            cannot_run(f"the opened thread renders only {peers} names; this gate exists to "
                       f"measure the MANY-name case and needs >= {MIN_PEERS}")
        log(f"  (fixture: {peers} participant names at {a.width}px)")

        st = p.ev("""(() => {
          const h = document.querySelector('.lg-msg__peer');
          const list = document.querySelector('.lg-msg__peer-namelist');
          const note = document.querySelector('.lg-msg__peer-note');
          const more = document.querySelector('.lg-msg__peer-more');
          const vis = e => { if(!e) return false; const s=getComputedStyle(e), r=e.getBoundingClientRect();
                             return s.display!=='none' && s.visibility!=='hidden' && r.width>0 && r.height>0; };
          return {
            headerPx: h ? Math.round(h.getBoundingClientRect().height) : null,
            hasList: !!list,
            noteVisible: vis(note),
            noteText: note ? (note.textContent||'').trim().slice(0,60) : null,
            moreVisible: vis(more),
            moreText: more ? (more.textContent||'').trim() : null,
            // the note must be a SIBLING of the clamped list, never a child of it
            noteInsideList: !!(list && note && list.contains(note)),
          };
        })()""")
        log(f"  collapsed header = {st['headerPx']}px   chip={st['moreText']!r}")

        check("the participant list is a clampable element of its own", st["hasList"], True)
        # THE ONE THAT MATTERS MOST. Clamping the old structure hides this line.
        # Tie this to the list EXISTING. Without that, a build with no clamp at all
        # passes it vacuously — list is null, contains() is false, "sibling" looks true.
        check("the note is a SIBLING of the clamped list, not a child",
              bool(st["hasList"] and not st["noteInsideList"]), True)
        check("'everyone here sees your reply' is VISIBLE while collapsed",
              bool(st["noteVisible"] and "everyone here sees your reply" in (st["noteText"] or "")),
              True)
        check("a '+N more' chip is offered", st["moreVisible"], True)
        check_cmp("the collapsed header is small", st["headerPx"], 110,
                  lambda g, w: isinstance(g, int) and g <= w, "<=")

        # Expand: the names must actually come back, and the note must still be there.
        # A gate that tracebacks on the RED build reports an ERROR where it should report a
        # FINDING — and an error is easy to mistake for an environment problem.
        if not st["moreVisible"]:
            log("  (no '+N more' chip on this build — the expand assertions below cannot "
                "run; counted as failures, not as an error)")
            for lbl in ("clicking the chip expands the list",
                        "expanding reveals every participant",
                        "the note is STILL visible when expanded",
                        "expanding actually grows the header (the clamp was doing work)"):
                check(lbl, "no chip to click", "the chip")
            log(f"\n  {passes} passed, {failures} failed")
            sys.exit(1 if failures else 0)
        p.ev("document.querySelector('.lg-msg__peer-more').click()"); time.sleep(0.8)
        ex = p.ev("""(() => {
          const h = document.querySelector('.lg-msg__peer');
          const note = document.querySelector('.lg-msg__peer-note');
          const wrap = document.querySelector('.lg-msg__peer-names');
          const list = document.querySelector('.lg-msg__peer-namelist');
          const vis = e => { if(!e) return false; const s=getComputedStyle(e), r=e.getBoundingClientRect();
                             return s.display!=='none' && s.visibility!=='hidden' && r.width>0 && r.height>0; };
          const lr = list.getBoundingClientRect();
          const lh = parseFloat(getComputedStyle(list).lineHeight) || 20;
          let shown = 0;
          document.querySelectorAll('.lg-msg__peer-name').forEach(n => {
            const r = n.getBoundingClientRect();
            if (r.bottom <= lr.bottom + 1 && r.right <= lr.right + 1) shown++;
          });
          return { headerPx: Math.round(h.getBoundingClientRect().height),
                   expanded: wrap.classList.contains('is-expanded'),
                   namesShown: shown, noteVisible: vis(note),
                   lines: Math.round(lr.height / lh) };
        })()""")
        log(f"  expanded header = {ex['headerPx']}px, {ex['namesShown']}/{peers} names on "
            f"{ex['lines']} line(s)")
        check("clicking the chip expands the list", ex["expanded"], True)
        check("expanding reveals every participant", ex["namesShown"], peers)
        check("the note is STILL visible when expanded", ex["noteVisible"], True)
        check_cmp("expanding actually grows the header (the clamp was doing work)",
                  ex["headerPx"], st["headerPx"], lambda g, w: g > w, ">")
    finally:
        p.close(); close_page(tid)
    log(f"\n  {passes} passed, {failures} failed")
    sys.exit(1 if failures else 0)


main()
