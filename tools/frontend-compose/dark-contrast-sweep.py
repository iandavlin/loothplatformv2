#!/usr/bin/env python3
"""
dark-contrast-sweep.py — WCAG contrast for the Loothprint modal, MEASURED off
the rendered page in dark mode.

Ian, 2026-08-15: "compose works well. Needs some dark mode love."

WHY NOT tools/mock-contrast-check.py: that one checks hardcoded hex pairs from a
mock, which is right for a design still on paper. This modal's colours are not
knowable that way — the form is FETCHED FROM WORDPRESS and injected into the
hub, so its text and field colours come from ACF/WP stylesheets meeting the
hub's dark tokens. Nobody can write that pair list down in advance; it has to be
read off the live DOM. The luminance/ratio math is imported from that tool
rather than re-implemented, so there is one definition of "contrast" here.

WHAT IT MEASURES: every text-bearing element inside #lpm-overlay — labels, help
text, field values, chips, buttons, the toggle — resolving each one's EFFECTIVE
background by walking ancestors until something opaque is found, then compositing
any rgba layers over it. A field whose own background is transparent is the case
that catches people out: the pair that matters is the text against whatever is
actually behind it, not against the panel someone assumed.

Thresholds: WCAG AA — 4.5:1 normal text, 3.0:1 for >=24px or >=18.66px bold.

Usage: python3 tools/frontend-compose/dark-contrast-sweep.py [--width 1280]
Exit:  0 clean, 1 failures found, 3 CANNOT RUN.
"""
import argparse
import importlib.util
import json
import os
import sys
import time

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
sys.path.insert(0, HERE)
from shots import Tab, gate_env, wp_cookies  # noqa: E402

# one definition of the ratio math, borrowed from the featured team's tool
# That tool runs its own report at module level (no __main__ guard), so importing
# it prints 20 unrelated PASS lines into this one's output. Borrow the maths,
# swallow the noise.
import contextlib, io
_spec = importlib.util.spec_from_file_location(
    "mcc", os.path.join(REPO, "tools", "mock-contrast-check.py"))
_mcc = importlib.util.module_from_spec(_spec)
with contextlib.redirect_stdout(io.StringIO()):
    try:
        _spec.loader.exec_module(_mcc)
    except SystemExit:
        pass
ratio = _mcc.ratio

# Read every visible text element in the modal with its computed colour and the
# first opaque background above it. Done in ONE evaluate: a per-element round
# trip over CDP on this box is minutes, not seconds.
COLLECT = r"""(() => {
  const root = document.getElementById('lpm-overlay');
  if (!root) return {error: 'no #lpm-overlay'};
  const parse = s => {
    const m = (s||'').match(/rgba?\(([^)]+)\)/);
    if (!m) return null;
    const p = m[1].split(',').map(x => parseFloat(x.trim()));
    return {r:p[0], g:p[1], b:p[2], a:p.length > 3 ? p[3] : 1};
  };
  /* Effective background: walk up until something is not fully transparent,
     compositing each translucent layer as we go. Assuming the nearest panel is
     the background is how a transparent field gets scored against the wrong
     colour and passes. */
  const bgOf = el => {
    const stack = [];
    let n = el;
    while (n && n !== document.documentElement) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c && c.a > 0) { stack.push(c); if (c.a === 1) break; }
      n = n.parentElement;
    }
    const base = parse(getComputedStyle(document.documentElement).backgroundColor)
              || {r:255,g:255,b:255,a:1};
    let out = stack.length && stack[stack.length-1].a === 1 ? stack.pop() : base;
    for (let i = stack.length - 1; i >= 0; i--) {
      const f = stack[i];
      out = {r: f.r*f.a + out.r*(1-f.a),
             g: f.g*f.a + out.g*(1-f.a),
             b: f.b*f.a + out.b*(1-f.a), a: 1};
    }
    return out;
  };
  const hex = c => '#' + [c.r,c.g,c.b].map(v => Math.round(v).toString(16).padStart(2,'0')).join('');
  /* PRE-FILTER IN THE PAGE. Returning every text element reset the CDP socket
     under load — the payload is the problem, not the measurement. JS keeps only
     what is anywhere near the line (under 6:1, well above the 4.5 threshold, so
     nothing borderline is dropped) and Python recomputes the authoritative
     ratio for the survivors with the shared math. A generous pre-filter cannot
     hide a failure; a tight one could. */
  const lum = c => {
    const f = v => { v/=255; return v <= 0.04045 ? v/12.92 : Math.pow((v+0.055)/1.055, 2.4); };
    return 0.2126*f(c.r) + 0.7152*f(c.g) + 0.0722*f(c.b);
  };
  const rat = (a,b) => { const L1=Math.max(lum(a),lum(b)), L2=Math.min(lum(a),lum(b));
                         return (L1+0.05)/(L2+0.05); };
  const out = []; let seen = 0;
  root.querySelectorAll('*').forEach(el => {
    const cs = getComputedStyle(el);
    if (cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) return;
    const r = el.getBoundingClientRect();
    if (r.width < 2 || r.height < 2) return;
    /* own text only — a wrapper would otherwise be scored for its children */
    const txt = [...el.childNodes].filter(n => n.nodeType === 3)
                  .map(n => n.textContent.trim()).join(' ').trim();
    if (txt.length < 2) return;
    const fg = parse(cs.color); if (!fg) return;
    const bg = bgOf(el);
    /* text alpha composites over its own background too */
    const eff = fg.a >= 1 ? fg : {r: fg.r*fg.a + bg.r*(1-fg.a),
                                  g: fg.g*fg.a + bg.g*(1-fg.a),
                                  b: fg.b*fg.a + bg.b*(1-fg.a)};
    const px = parseFloat(cs.fontSize) || 16;
    const bold = (parseInt(cs.fontWeight,10) || 400) >= 700;
    seen++;
    if (rat(eff, bg) >= 6) return;          /* comfortably clear — not our problem */
    out.push({sel: el.tagName.toLowerCase() + (el.className && typeof el.className === 'string'
                    ? '.' + el.className.trim().split(/\s+/).slice(0,2).join('.') : ''),
              text: txt.slice(0, 46), fg: hex(eff), bg: hex(bg), px: px,
              large: px >= 24 || (bold && px >= 18.66)});
  });
  return {items: out, seen: seen};
})()"""


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--width", type=int, default=1280)
    args = ap.parse_args()
    mobile = args.width < 641

    env = gate_env(); domain = env["LG_GATE_DOMAIN"]
    tab = Tab()
    try:
        tab.call("Page.enable"); tab.call("Network.enable")
        tab.call("Network.setCacheDisabled", cacheDisabled=True)
        tab.call("Network.clearBrowserCookies")
        cookies = [{"name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
                    "domain": "." + domain, "path": "/", "secure": True}]
        for n, v in wp_cookies("claude_admin"):
            cookies.append({"name": n, "value": v, "domain": domain, "path": "/",
                            "secure": True, "httpOnly": True, "sameSite": "Lax"})
        tab.call("Network.setCookies", cookies=cookies)
        tab.call("Emulation.setDeviceMetricsOverride", width=args.width, height=1400,
                 deviceScaleFactor=1, mobile=mobile,
                 screenWidth=args.width, screenHeight=1400)

        url = env["LG_GATE_HOST"] + "/preview/frontend-compose/hub/?compose=1"
        # trap: a localStorage write on about:blank is a silent no-op, so
        # navigate FIRST, set the theme, then navigate again.
        tab.call("Page.navigate", url=url); time.sleep(3)
        tab.call("Runtime.evaluate", expression=(
            "localStorage.setItem('lg-set-theme','dark');"
            "localStorage.setItem('lg-set-boot', JSON.stringify({theme:'dark',dark:true}));"
            "document.documentElement.setAttribute('data-lguser-theme','dark');"))
        tab.call("Page.navigate", url=url)

        def ev(js):
            r = tab.call("Runtime.evaluate", expression=js, returnByValue=True)
            return r["result"].get("value") if "exceptionDetails" not in r else None

        for _ in range(80):
            time.sleep(0.25)
            if ev("document.readyState==='complete' && !!document.getElementById('lpm-overlay')"):
                break
        theme = ev("document.documentElement.getAttribute('data-lguser-theme')")
        if theme != "dark":
            print(f"CANNOT RUN: theme is {theme!r}, not dark — measuring the wrong thing")
            return 3

        ev("(() => {const b=[...document.querySelectorAll('#ntm-typetoggle .ntm-typetoggle__opt')]"
           ".find(x=>x.getAttribute('data-ntm-type')==='loothprint'); b&&b.click(); return 1;})()")
        for _ in range(80):
            time.sleep(0.5)
            if ev("!!document.querySelector('#lpm-body .acf-field')"):
                break
        time.sleep(4)

        res = ev(COLLECT)
        if not res or res.get("error"):
            print(f"CANNOT RUN: {(res or {}).get('error', 'collector returned nothing')}")
            return 3
        items, seen = res["items"], res.get("seen", 0)
        if not seen:
            print("CANNOT RUN: no text found in the modal — a pass here would be vacuous")
            return 3

        fails = []
        for it in items:
            need = 3.0 if it["large"] else 4.5
            r = ratio(it["fg"], it["bg"])
            if r < need:
                fails.append((round(r, 2), need, it))
        print(f"dark-contrast-sweep @ {args.width}px — {seen} text elements measured, "
              f"{len(items)} within reach of the line")
        for r, need, it in sorted(fails, key=lambda f: f[0])[:25]:
            print(f"  ✗ {r}:1 (needs {need}) {it['fg']} on {it['bg']}  "
                  f"{it['px']:.0f}px  {it['sel']}  “{it['text']}”")
        if fails:
            print(f"\nRED — {len(fails)} of {seen} below WCAG AA in dark mode.")
            return 1
        print("\nGREEN — every measured element meets WCAG AA in dark mode.")
        return 0
    finally:
        tab.close()


if __name__ == "__main__":
    sys.exit(main())
