#!/usr/bin/env python3
"""Measure the hidden-attribute candidates the static sweep flagged (backlog 3.6).

`hidden` is only `display:none` from the UA stylesheet, so any author `display:` on
the same element defeats it silently — the JS toggle keeps flipping an attribute with
no visual effect. Two instances were confirmed and fixed (.post__menu,
.reply-form__replying-to). tools/gates/hidden-attr-honoured-sweep.py flags six more
by reading CSS + markup; none had been measured, and the sweep is deliberately NOT in
run-all.sh until they are.

WHY MEASURING MATTERS HERE, rather than trusting the scan: one flagged class
(.ntm-form) sets display, has no guard, and does NOT misrender — a guarded ancestor
hides it. Adding a guard on faith is also dangerous in the other direction: a guard on
something whose JS never clears `hidden` makes it permanently unreachable, which is
worse than the bug.

Three outcomes per class per surface, and they are not the same thing:
  BUG      present, carries [hidden], and is PAINTED  -> confirmed, fix + prove reveal
  masked   present, carries [hidden], not painted     -> latent; an ancestor is hiding it
  absent   not on this surface                        -> unproven, say so, do not guess
"""
import json, sys, time, os, subprocess, urllib.request, websocket

CDP = "http://127.0.0.1:9222"
BASE = "https://dev2.loothgroup.com"
COOKIES = "/tmp/mobile-bugs-exercise/cookies.txt"

CANDIDATES = ["feed-card__full-body", "feed-card__replies-full", "forum-header__edit-img",
              "hub-suggest", "ntm-form", "reply-stub__img"]
FIXED = ["post__menu", "reply-form__replying-to"]      # regression guard

SURFACES = [("/hub/", "the hub feed"),
            ("/hub/touring-tech/test-3/", "a topic page"),
            ("/hub/?type=discussions", "the hub, discussions filter")]


def gate(): return open("/tmp/mobile-bugs-exercise/gate.txt").read().strip()


class P:
    def __init__(s, w):
        s.ws = websocket.create_connection(w, timeout=60, suppress_origin=True); s.n = 0
    def send(s, m, p=None):
        s.n += 1; i = s.n
        s.ws.send(json.dumps({"id": i, "method": m, "params": p or {}}))
        while True:
            r = json.loads(s.ws.recv())
            if r.get("id") == i:
                if "error" in r: raise RuntimeError(f"{m}: {r['error']}")
                return r.get("result", {})
    def ev(s, e):
        r = s.send("Runtime.evaluate", {"expression": e, "returnByValue": True, "awaitPromise": True})
        if r.get("exceptionDetails"): return None
        return r["result"].get("value")


PROBE = """(function(){
  var es=[].slice.call(document.querySelectorAll('.%s'));
  var h=es.filter(function(e){return e.hasAttribute('hidden');});
  var painted=h.filter(function(e){var r=e.getBoundingClientRect();return r.width>0&&r.height>0;});
  return {present:es.length, with_hidden:h.length, painted:painted.length,
          disp: painted.length?getComputedStyle(painted[0]).display:
                (h.length?getComputedStyle(h[0]).display:'-')};
})()"""


def main():
    t = json.load(urllib.request.urlopen(
        urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")))
    p = P(t["webSocketDebuggerUrl"])
    verdict = {c: {} for c in CANDIDATES + FIXED}
    try:
        p.send("Network.enable"); p.send("Page.enable")
        p.send("Network.setCacheDisabled", {"cacheDisabled": True})
        p.send("Network.clearBrowserCookies")
        cks = [{"name": "loothdev_auth", "value": gate(), "domain": ".dev2.loothgroup.com",
                "path": "/", "secure": True}]
        cks += [{"name": k.strip(), "value": v.strip(), "url": BASE, "path": "/"}
                for k, v in (l.split("=", 1) for l in open(COOKIES) if "=" in l)]
        p.send("Network.setCookies", {"cookies": cks})
        p.send("Emulation.setDeviceMetricsOverride",
               {"width": 390, "height": 844, "deviceScaleFactor": 1, "mobile": True})
        p.send("Emulation.setTouchEmulationEnabled", {"enabled": True, "maxTouchPoints": 5})

        for path, label in SURFACES:
            p.send("Page.navigate", {"url": BASE + path})
            for _ in range(160):
                time.sleep(0.25)
                if p.ev("document.readyState") == "complete": break
            time.sleep(5.5)
            # liveness: an "absent" reading is worthless on a page that did not load
            live = p.ev("[document.title, innerWidth, !!document.querySelector('.lg-chrome__avatar')]")
            print(f"\n=== {label}  ({path}) ===")
            print(f"  liveness: {live}")
            if not live or not live[2]:
                print("  !! not a signed-in member here — readings below would be vacuous, SKIPPED")
                continue
            for c in CANDIDATES + FIXED:
                r = p.ev(PROBE % c) or {}
                if not r.get("present"):
                    state = "absent"
                elif r.get("painted"):
                    state = "BUG"
                elif r.get("with_hidden"):
                    state = "masked"
                else:
                    state = "no-hidden-attr"
                verdict[c][label] = state
                mark = "  <-- PAINTED WHILE [hidden]" if state == "BUG" else ""
                print(f"    .{c:26} {state:14} present={r.get('present')} "
                      f"hidden={r.get('with_hidden')} painted={r.get('painted')} {r.get('disp')}{mark}")

        print("\n=== SUMMARY ===")
        bugs = [c for c in CANDIDATES if "BUG" in verdict[c].values()]
        masked = [c for c in CANDIDATES if "BUG" not in verdict[c].values()
                  and "masked" in verdict[c].values()]
        unseen = [c for c in CANDIDATES if set(verdict[c].values()) <= {"absent", "no-hidden-attr"}
                  or not verdict[c]]
        print(f"  CONFIRMED BUGS  : {bugs or '(none)'}")
        print(f"  masked (latent) : {masked or '(none)'}")
        print(f"  never rendered  : {unseen or '(none)'}  <- NOT SEEN THIS RUN, not proven absent:")
        print(f"                    the hub feed's cards change between loads, so a class can move")
        print(f"                    between 'masked' and 'never rendered' run to run. Do not guard")
        print(f"                    anything on the strength of one quiet run.")
        # ⚠️ THE REGRESSION LINE IS ONLY MEANINGFUL AGAINST A TREE THAT HAS THE FIX.
        # dev2 serves MAIN. Run this before the 3.6 fix merges and both fixed classes
        # come back "BUG" — which is the UNFIXED state, not a regression. The first
        # run of this tool reported exactly that and it read as alarming. So: ask the
        # served CSS which tree we are auditing, and say so.
        guarded = p.ev(r"""(function(){
          var out={};
          [].slice.call(document.styleSheets).forEach(function(ss){
            var rules; try { rules = ss.cssRules; } catch(e) { return; }
            [].slice.call(rules||[]).forEach(function(r){
              var t=r.selectorText||''; 
              if (/\.post__menu\[hidden\]/.test(t)) out.post_menu=true;
              if (/\.reply-form__replying-to\[hidden\]/.test(t)) out.replying=true;
            });
          });
          return out;})()""") or {}
        has_fix = bool(guarded.get("post_menu")) and bool(guarded.get("replying"))
        reg = [c for c in FIXED if "BUG" in verdict[c].values()]
        if not has_fix:
            print(f"  regression check: N/A — the served CSS does NOT carry the 3.6 guards, "
                  f"so this tree predates the fix. {reg or '[]'} showing as BUG here is the "
                  f"UNFIXED state, not a regression.")
        else:
            print(f"  regression check: {'REGRESSED ' + str(reg) if reg else 'both fixes hold on the served tree'}")
    finally:
        try: p.ws.close()
        except Exception: pass
        try: urllib.request.urlopen(CDP + f"/json/close/{t['id']}").read()
        except Exception: pass


if __name__ == "__main__":
    sys.exit(main())
