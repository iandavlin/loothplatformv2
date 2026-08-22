#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""#202 — render the todo-page PROPOSAL as static mockups Ian can click.

This is a DESIGN artefact, not a build. It writes self-contained HTML into
footer-mockups/202-todo-proposal/ and touches nothing the lanes page uses.

⚠ THREE TRAPS THIS FILE IS SHAPED AROUND, each already paid for on this box:

 1. THE DOCROOT INJECTS A THEME BOOT SCRIPT INTO EVERY text/html RESPONSE.
    dev2's server-level `sub_filter` appends a script that reads `lg-set-boot`
    from localStorage, sets every `--lg-*` token as INLINE STYLE on <html>, and
    when the viewer's app theme is dark also appends
    `<style id="lg-boot-crit">body{background:…!important;color:…!important}`.
    A mock that paints `body` or reuses `--lg-*` therefore INVERTS for some
    viewers and not others. Defences here, belt and braces:
      · the mock owns a `--m-*` token namespace and never reads a `--lg-*` one;
      · nothing is painted on `body` — a full-bleed `.page` wrapper carries the
        background, so an `!important` body rule has nothing to show through.
 2. A MOCK'S THEME STAMP POISONS THE SHARED CHROME PROFILE. `app-settings.js`
    has no iframe guard and PERSISTS what it is handed, so a mock that writes
    `lg-set-theme` takes every other lane's browser dark. This mock reads
    `?theme=` and stamps its OWN attribute. It NEVER touches localStorage.
 3. `/pwa.js` IS INJECTED TOO, and lands app chrome on the mock — a fixed
    tabbar (z-index 2147481000+) that covered 55px of an earlier lane's mock,
    and an install banner that once landed on the very control being shown.
    Neutralised in the mock's own stylesheet, plus bottom padding as a backstop.

Usage:  python3 tools/proposals/202-todo/build.py [outdir]
"""
import html
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from data import ITEMS, QUIET, BANDS, FACTS, GENERATED  # noqa: E402

E = lambda s: html.escape(str(s), quote=True)  # noqa: E731


# --------------------------------------------------------------------------
# tokens.  Light is the BARE default; dark is a redefinition of the same names.
# The current /lanes/ page has no light theme at all — `body{background:#14161a}`
# is hardcoded, `prefers-color-scheme` appears zero times — so a viewer in
# daylight gets the dark page regardless. Dark here reuses that page's exact
# palette so the proposal reads as continuous with what he has today.
# --------------------------------------------------------------------------
CSS = """
*,*::before,*::after{box-sizing:border-box}
body{margin:0}

.page{
  --m-bg:#f5f3ed; --m-card:#fff; --m-ink:#191c20; --m-soft:#6a7079;
  --m-line:#e3e0d7; --m-line2:#d5d1c5; --m-accent:#5f7d33; --m-accent-ink:#fff;
  --m-chip:#eeece4; --m-shadow:0 1px 2px rgba(0,0,0,.06),0 4px 14px rgba(0,0,0,.05);
  --m-hero:#fffdf7;
  background:var(--m-bg); color:var(--m-ink);
  font:15px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
  min-height:100vh; padding:20px 16px 80px;
  -webkit-font-smoothing:antialiased;
}
.page[data-t="dark"]{
  --m-bg:#14161a; --m-card:#1b1f26; --m-ink:#e8e6df; --m-soft:#9aa3ad;
  --m-line:#2a2f38; --m-line2:#343b45; --m-accent:#9db668; --m-accent-ink:#14161a;
  --m-chip:#252b33; --m-shadow:none; --m-hero:#1e232b;
}
.wrap{max-width:820px;margin:0 auto}

/* ---- trap 3: the injected app chrome is not part of this mockup ---- */
#looth-tabbar,nav#looth-tabbar,.lg-install-banner,#lg-install-banner,
.pwa-install,#looth-bottom-nav{display:none!important}

/* ---------------- masthead ---------------- */
.mast{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin-bottom:4px}
.mast h1{font-size:21px;font-weight:700;margin:0;letter-spacing:-.01em}
.mast .when{color:var(--m-soft);font-size:12.5px;margin-left:auto}
.tt{color:var(--m-soft);font-size:12.5px;text-decoration:none;border:1px solid var(--m-line2);
    border-radius:20px;padding:2px 11px;white-space:nowrap}
.tt:hover{color:var(--m-ink)}

/* ---------------- the loud layer — never inside a fold ---------------- */
.loud{border-radius:10px;padding:10px 13px;margin:14px 0;font-size:14px;
      background:#fdf0e8;border:1px solid #e4b49a;color:#8a3c1c}
.page[data-t="dark"] .loud{background:#3a2420;border-color:#7a4438;color:#f0c3ac}
.calm{color:var(--m-soft);font-size:13px;margin:14px 0}

/* ---------------- section headers ---------------- */
.bandh{display:flex;align-items:center;gap:9px;margin:26px 0 10px;font-size:12.5px;
       text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--m-soft)}
.bandh .dot{width:9px;height:9px;border-radius:50%;flex:none}
.bandh .ct{margin-left:auto;font-weight:600;letter-spacing:0;text-transform:none;font-size:12.5px}

/* ---------------- the hero card ---------------- */
.hero{background:var(--m-hero);border:1px solid var(--m-line2);border-left:4px solid #b4402f;
      border-radius:12px;padding:16px 17px;box-shadow:var(--m-shadow);margin-bottom:6px}
.hero .kicker{display:flex;align-items:center;gap:8px;font-size:11.5px;font-weight:700;
      letter-spacing:.07em;text-transform:uppercase;color:#b4402f;margin-bottom:9px}
.page[data-t="dark"] .hero .kicker{color:#e0715c}
.hero .act{font-size:20px;line-height:1.3;font-weight:650;letter-spacing:-.01em;margin:0 0 10px}
.hero .rank{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;
      border-radius:50%;background:#b4402f;color:#fff;font-size:11.5px;font-weight:700;flex:none}

/* ---------------- a compact row ---------------- */
.row{background:var(--m-card);border:1px solid var(--m-line);border-radius:10px;
     padding:11px 13px;margin-bottom:7px;box-shadow:var(--m-shadow)}
.row.b1{border-left:3px solid #b4402f}
.row.b2{border-left:3px solid #b8860b}
.row.b3{border-left:3px solid #5f7d33}
.row .top{display:flex;gap:10px;align-items:baseline}
.row .act{font-weight:600;font-size:15px;flex:1;min-width:0;line-height:1.35}
.rk{color:var(--m-soft);font-size:12.5px;font-weight:700;font-variant-numeric:tabular-nums;flex:none}
.wait{color:var(--m-soft);font-size:12.5px;white-space:nowrap;flex:none;font-variant-numeric:tabular-nums}

.behind{color:var(--m-soft);font-size:13px;margin-top:5px}
.behind b{color:var(--m-ink);font-weight:600}
.lane{color:var(--m-soft);font-size:12.5px;margin-top:4px;font-style:italic}

/* ---------------- controls ---------------- */
.acts{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-top:10px}
.door{background:var(--m-accent);color:var(--m-accent-ink);border:0;border-radius:7px;
      padding:6px 13px;font-size:13.5px;font-weight:700;text-decoration:none;
      display:inline-block;white-space:nowrap}
.hero .door{padding:9px 17px;font-size:15px}
.nodoor{color:var(--m-soft);font-size:12.5px;font-style:italic}
.say{display:inline-flex;gap:6px;align-items:center;flex-wrap:wrap}
.say .lbl{color:var(--m-soft);font-size:12.5px}
.chip{background:var(--m-chip);border:1px solid var(--m-line2);border-radius:20px;
      padding:3px 11px;font-size:12.5px;font-weight:600;color:var(--m-ink);white-space:nowrap;cursor:pointer}
.ghost{background:transparent;border:1px solid var(--m-line2);border-radius:7px;
       padding:5px 11px;font-size:12.5px;color:var(--m-soft);cursor:pointer;white-space:nowrap}
.confirm{background:transparent;border:1px solid var(--m-accent);color:var(--m-accent);
      border-radius:7px;padding:5px 12px;font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.hero .confirm{padding:8px 15px;font-size:13.5px}
.fine{color:var(--m-soft);font-size:11.5px;margin-left:auto;white-space:nowrap}
.fine a{color:var(--m-soft)}
.hr{border-top:1px solid var(--m-line);margin:10px 0 0;padding-top:9px}
.newbadge{background:#5f7d33;color:#fff;border-radius:4px;font-size:10px;font-weight:700;
      padding:1px 5px;letter-spacing:.04em;vertical-align:2px}
.page[data-t="dark"] .newbadge{background:#9db668;color:#14161a}

/* the #178 confirm button, shown mid-flight */
.row.done{opacity:.62}
.row.done .act{text-decoration:line-through;text-decoration-thickness:1px}
.doneline{color:var(--m-soft);font-size:12.5px;margin-top:5px}

/* ---------------- the fold ---------------- */
.quiet{color:var(--m-soft);font-size:13px;margin:16px 0 0;padding:9px 12px;
       border:1px dashed var(--m-line2);border-radius:8px}
details.shop{margin-top:22px;border-top:1px solid var(--m-line);padding-top:6px}
details.shop>summary{cursor:pointer;list-style:none;color:var(--m-soft);font-size:13px;padding:8px 2px}
details.shop>summary::-webkit-details-marker{display:none}
details.shop>summary::before{content:"\\25B8 ";display:inline-block;transition:transform .12s}
details.shop[open]>summary::before{transform:rotate(90deg)}
.shopbody{color:var(--m-soft);font-size:13px;padding:2px 2px 10px;line-height:1.7}

@media (max-width:560px){
  .page{padding:16px 12px 80px}
  .mast h1{font-size:19px}
  .mast .when{margin-left:0;width:100%}
  .hero .act{font-size:18px}
  .row{padding:10px 11px;margin-bottom:6px}
  .row .top{flex-wrap:wrap;gap:7px}
  .wait{width:100%;order:3;margin-top:-2px}
  /* 21 items is a long scroll on a phone. The SECONDARY controls go — the
     copy button and the issue fine print, both of which have a home on the
     desktop view — and nothing that carries a decision does. */
  .row .opt2,.row .fine{display:none}
  .acts{gap:6px;margin-top:8px}
}
"""

# The theme switch. Reads ?theme= and stamps the MOCK'S OWN attribute.
# It never reads or writes localStorage — see trap 2 in the docstring.
THEME_JS = """
(function(){try{
  var t=new URLSearchParams(location.search).get('theme');
  if(t!=='dark'&&t!=='light'){t=matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light';}
  document.querySelector('.page').setAttribute('data-t',t);
  var o=document.getElementById('tt'); if(o){
    var u=new URL(location.href); u.searchParams.set('theme',t==='dark'?'light':'dark');
    o.href=u.pathname+u.search; o.textContent=(t==='dark'?'\\u2600 light':'\\u263e dark');
  }
}catch(e){}})();
"""


def shell(title, body, extra_css=""):
    return (
        '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        '<meta name="viewport" content="width=device-width,initial-scale=1">'
        f'<title>{E(title)}</title>'
        f'<style>{CSS}{extra_css}</style></head><body>'
        f'<div class="page" data-t="light"><div class="wrap">{body}</div></div>'
        f'<script>{THEME_JS}</script></body></html>'
    )


def wait_words(h):
    """Plain English, never a number he has to convert. Ian's format law."""
    if h < 1:
        return "waiting since just now"
    if h < 2:
        return "waiting 1 hour"
    if h < 24:
        return f"waiting {int(round(h))} hours"
    d = h / 24.0
    if d < 1.5:
        return "waiting 1 day"
    return f"waiting {d:.1f} days".replace(".0 ", " ")


def replies(it):
    n = it["n"]
    return [f"{n} good", f"{n} not right"] if it["band"] == 3 else [f"GO on {n}", f"hold {n}"]


def door_html(it, big=False):
    if it["door"]:
        label = "Open it" if not big else "Open it"
        return (f'<a class="door" href="{E(it["door"])}" target="_blank" rel="noopener">'
                f'{label} &#8599;</a>')
    return '<span class="nodoor">no test link yet &mdash; ask keeper for one</span>'


def says_html(it):
    ch = "".join(f'<button class="chip">&ldquo;{E(r)}&rdquo;</button>' for r in replies(it))
    return f'<span class="say"><span class="lbl">say</span>{ch}</span>'


def mast(sub):
    """`sub` is this file's own markup, so it is NOT escaped.

    It was, in the first cut, and the masthead read
    `generated 2026-08-22 17:00 &middot; redraws every 5 minutes` — the entity
    printed as literal text. Nothing asserted against it; only looking at the
    picture found it, which is the third time on this page that has been true.
    """
    return (
        '<div class="mast"><h1>What needs you</h1>'
        f'<a class="tt" id="tt" href="?theme=dark">&#9790; dark</a>'
        f'<span class="when">{sub}</span></div>'
    )


def loud_layer():
    """Quiet when healthy, loud when broken — and PLAIN ENGLISH.

    Today's page renders this as `main 19b43dd  dev2 19b43dd  live 2163c08` —
    three raw SHAs, against Ian's own standing format law (no hashes, no branch
    names, no jargon) which PAGE.md records for every word the page shows him.
    """
    return (f'<div class="loud"><b>Live is behind.</b> {FACTS["live_behind_commits"]} '
            'changes have landed on dev2 that live has not taken yet.</div>')


# --------------------------------------------------------------------------
def render_option_a():
    ranked = sorted(ITEMS, key=lambda i: (i["band"], not i.get("blocker"), -i["waiting_h"]))
    hero, rest = ranked[0], ranked[1:]
    b = [mast(f"generated {GENERATED} &middot; redraws every 5 minutes"), loud_layer()]

    # ---- the hero -------------------------------------------------------
    band_name, _ = BANDS[hero["band"]]
    b.append(f'<div class="bandh"><span class="dot" style="background:#b4402f"></span>Next up</div>')
    b.append('<div class="hero">')
    b.append(f'<div class="kicker"><span class="rank">1</span>{E(band_name)}'
             f'<span style="margin-left:auto;font-weight:600;letter-spacing:0;text-transform:none;'
             f'color:var(--m-soft)">{E(wait_words(hero["waiting_h"]))}</span></div>')
    b.append(f'<p class="act">{E(hero["action"])}</p>')
    if hero["behind"]:
        b.append(f'<div class="behind"><b>Behind it:</b> {E(hero["behind"])}</div>')
    if hero["lane_says"]:
        b.append(f'<div class="lane">the lane said: &ldquo;{E(hero["lane_says"])}&rdquo;</div>')
    b.append(f'<div class="acts">{door_html(hero, big=True)}{says_html(hero)}'
             f'<button class="ghost">Copy for keeper</button></div>')
    b.append(f'<div class="acts hr"><button class="confirm">&#10003; Landed as expected</button>'
             f'<span class="fine"><a href="#">#{hero["n"]} on GitHub &#8599;</a></span></div>')
    b.append('</div>')

    # ---- the ranked rest, banded ----------------------------------------
    rank = 2
    for band in (1, 2, 3):
        rows = [i for i in rest if i["band"] == band]
        if not rows:
            continue
        name, col = BANDS[band]
        b.append(f'<div class="bandh"><span class="dot" style="background:{col}"></span>{E(name)}'
                 f'<span class="ct">{len(rows)}</span></div>')
        for idx, it in enumerate(rows):
            # One row is shown mid-flight in the CONFIRMED state, so Ian can
            # judge #178's button by what it DOES, not by its label alone.
            done = (band == 3 and it["n"] == 148)
            cls = f'row b{band}' + (' done' if done else '')
            b.append(f'<div class="{cls}">')
            new = ('<span class="newbadge">NEW</span> ' if not it["visible"] else '')
            b.append(f'<div class="top"><span class="rk">{rank}</span>'
                     f'<span class="act">{new}{E(it["action"])}</span>'
                     f'<span class="wait">{E(wait_words(it["waiting_h"]))}</span></div>')
            if done:
                b.append('<div class="doneline">&#10003; you said this landed &mdash; '
                         'keeper is closing it out</div>')
            else:
                # EVERY row gets exactly one line of context. `behind` where the
                # item blocks something; failing that the lane's own verbatim
                # words, which PAGE.md #159 says are better than any wording
                # derived from a label. A look-family card blocks nothing but
                # Ian's eyes, and inventing a consequence for it would be
                # fabrication — so it borrows the lane's sentence instead.
                if it["behind"]:
                    b.append(f'<div class="behind"><b>Behind it:</b> {E(it["behind"])}</div>')
                elif it["lane_says"]:
                    b.append(f'<div class="lane">the lane said: &ldquo;'
                             f'{E(it["lane_says"])}&rdquo;</div>')
                b.append(f'<div class="acts">{door_html(it)}{says_html(it)}'
                         f'<button class="ghost opt2">Copy</button>'
                         f'<button class="confirm">&#10003; Landed</button>'
                         f'<span class="fine"><a href="#">#{it["n"]} &#8599;</a></span></div>')
            b.append('</div>')
            rank += 1

    q = ", ".join(f'#{x["n"]} {x["title"]}' for x in QUIET)
    b.append(f'<div class="quiet">landed, nothing for you to do: {E(q)}</div>')
    b.append(
        '<details class="shop"><summary>The workshop &mdash; 2 agents working &middot; '
        '3 seats &middot; 31 parked branches &middot; 20 merges this week</summary>'
        '<div class="shopbody">Agents, seats, old desks, parked branches, cleanup and the '
        '7-day shipped strip all live in here. Nothing in this drawer ever waits on you &mdash; '
        'if something starts to, it leaves the drawer and joins the list above.</div></details>')
    return shell("Option A — What needs you", "".join(b))


# --------------------------------------------------------------------------
def render_option_b():
    ranked = sorted(ITEMS, key=lambda i: (i["band"], not i.get("blocker"), -i["waiting_h"]))
    it, nxt = ranked[0], ranked[1]
    name, col = BANDS[it["band"]]
    extra = """
.counter{display:flex;align-items:center;gap:10px;color:var(--m-soft);font-size:13px;margin:18px 0 12px}
.pips{display:flex;gap:4px;flex-wrap:wrap;flex:1}
.pip{width:7px;height:7px;border-radius:50%;background:var(--m-line2)}
.pip.on{background:var(--m-accent)}
.one{background:var(--m-card);border:1px solid var(--m-line2);border-radius:14px;
     padding:26px 22px;box-shadow:var(--m-shadow);border-top:5px solid #b4402f}
.one .act{font-size:25px;line-height:1.25;font-weight:650;letter-spacing:-.015em;margin:14px 0 14px}
.one .kick{font-size:11.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:#b4402f}
.page[data-t="dark"] .one .kick{color:#e0715c}
.one .door{display:block;text-align:center;padding:13px;font-size:16px;margin:18px 0 14px}
.bigchips{display:flex;gap:9px}
.bigchips .chip{flex:1;text-align:center;padding:11px;font-size:14.5px;border-radius:9px}
.foot2{display:flex;gap:9px;margin-top:16px;border-top:1px solid var(--m-line);padding-top:14px}
.foot2 .confirm{flex:1;text-align:center;padding:11px}
.foot2 .ghost{padding:11px 16px}
.nextup{color:var(--m-soft);font-size:13px;margin-top:16px;text-align:center}
/* One card on a 1440 desktop left ~900px of dead page below it, which
   photographs as unfinished rather than as deliberate. Centring it makes the
   emptiness read as the point of this option instead of as a missing section. */
@media (min-width:561px){
  .page{display:flex;flex-direction:column;justify-content:center;padding-top:0;padding-bottom:0}
  .wrap{width:100%;padding:28px 0}
}
@media (max-width:560px){.one{padding:20px 16px}.one .act{font-size:21px}
  .bigchips{flex-direction:column}}
"""
    b = [mast(f"generated {GENERATED}"), loud_layer()]
    pips = "".join(f'<span class="pip{" on" if k == 0 else ""}"></span>' for k in range(len(ranked)))
    b.append(f'<div class="counter"><b style="color:var(--m-ink)">1 of {len(ranked)}</b>'
             f'<span class="pips">{pips}</span></div>')
    b.append('<div class="one">')
    b.append(f'<div class="kick">{E(name)} &middot; {E(wait_words(it["waiting_h"]))}</div>')
    b.append(f'<p class="act">{E(it["action"])}</p>')
    if it["behind"]:
        b.append(f'<div class="behind"><b>Behind it:</b> {E(it["behind"])}</div>')
    if it["lane_says"]:
        b.append(f'<div class="lane">the lane said: &ldquo;{E(it["lane_says"])}&rdquo;</div>')
    b.append(door_html(it, big=True) if it["door"] else
             f'<div style="margin:18px 0 14px">{door_html(it)}</div>')
    b.append('<div class="bigchips">' + "".join(
        f'<button class="chip">&ldquo;{E(r)}&rdquo;</button>' for r in replies(it)) + '</div>')
    b.append('<div class="foot2"><button class="confirm">&#10003; Landed as expected</button>'
             '<button class="ghost">Skip &rarr;</button></div>')
    b.append('</div>')
    b.append(f'<div class="nextup">next: {E(nxt["action"])}</div>')
    return shell("Option B — One at a time", "".join(b), extra)


# --------------------------------------------------------------------------
def render_index():
    f = FACTS
    css = """
.lede{font-size:16.5px;line-height:1.6;margin:14px 0 20px}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(178px,1fr));gap:12px;margin:18px 0}
.stat{background:var(--m-card);border:1px solid var(--m-line);border-radius:10px;padding:13px 14px}
.stat .big{font-size:27px;font-weight:700;letter-spacing:-.02em;line-height:1.1}
.stat .cap{color:var(--m-soft);font-size:12.5px;margin-top:4px}
.opt{background:var(--m-card);border:1px solid var(--m-line);border-radius:12px;
     padding:16px 17px;margin:12px 0}
.opt h3{margin:0 0 6px;font-size:17px}
.opt p{margin:0 0 12px;color:var(--m-soft);font-size:14px;line-height:1.55}
.opt .links{display:flex;gap:8px;flex-wrap:wrap}
.rec{background:var(--m-accent);color:var(--m-accent-ink);border-radius:5px;font-size:10.5px;
     font-weight:700;padding:2px 7px;letter-spacing:.05em;vertical-align:2px;margin-left:7px}
h2.sec{font-size:13px;text-transform:uppercase;letter-spacing:.07em;color:var(--m-soft);
       margin:30px 0 10px;font-weight:700}
ul.pts{margin:0;padding-left:19px}
ul.pts li{margin:7px 0;line-height:1.55}
.shots{display:grid;grid-template-columns:repeat(auto-fit,minmax(158px,1fr));gap:12px;margin-top:10px}
.shots a{display:block;text-decoration:none;color:var(--m-soft);font-size:12px}
/* ⚠ THE THUMBNAILS MUST BE CROPPED, NOT SCALED. The phone shots are full-page
   captures ~4,900px tall; at a 158px column that is a 1,900px-tall cell, which
   drags every grid row down with it and leaves the gallery full of holes. A
   fixed height plus object-fit:cover from the TOP gives eight uniform tiles
   that each show the masthead — and the full picture is one click away. */
.shots img{width:100%;height:190px;object-fit:cover;object-position:top center;
  border:1px solid var(--m-line2);border-radius:7px;display:block;margin-bottom:5px}
"""
    b = ['<div class="mast"><h1>The todo page, rebuilt &mdash; a proposal</h1>'
         '<a class="tt" id="tt" href="?theme=dark">&#9790; dark</a>'
         '<span class="when">issue #202 &middot; pictures only, nothing built</span></div>']
    b.append('<p class="lede">You said <i>&ldquo;to do still isn&rsquo;t quite useful yet&rdquo;</i>. '
             'I measured the page before drawing anything, and the problem turned out not to be '
             'the way the cards look. <b>The list is missing most of what actually waits on you.</b></p>')

    b.append('<h2 class="sec">What I measured, on the live page</h2>')
    b.append('<div class="grid">')
    for big, cap in [
        (f'{f["visible_today"]}', 'items on your list right now'),
        (f'{f["invisible"]}', 'more are waiting on you and <b>do not appear at all</b>'),
        (f'{f["visible_without_doors"]} of {f["visible_today"]}', 'items you CAN see have no button to press'),
        (f'{f["invisible_with_doors"]} of {f["invisible"]}', 'items you CANNOT see already have one'),
    ]:
        b.append(f'<div class="stat"><div class="big">{big}</div><div class="cap">{cap}</div></div>')
    b.append('</div>')

    b.append('<p>Every item on your list today is from 19&ndash;20 August. Everything finished since '
             'then &mdash; the licence fix, the new uploader, the lighter photo pages, the Products '
             'tab, the loothprint gating, the billing cutover &mdash; is invisible to it, even though '
             'six of those already have a working link written and ready to use.</p>')
    b.append('<p><b>Why.</b> The list is built from a label that someone has to remember to put on '
             'an issue by hand. The lanes themselves write the truth &mdash; &ldquo;awaiting Ian&rsquo;s '
             'look at the picture page&rdquo; &mdash; and the page already reads those words, but only '
             'to quote them. It never uses them to decide what goes on the list. So when the label '
             'ritual slipped, the list quietly froze, and a frozen list looks exactly like a short one.</p>')

    b.append('<h2 class="sec">The two shapes &mdash; pick one</h2>')
    b.append('<div class="opt"><h3>Option A &mdash; one thing up top, the rest ranked below'
             '<span class="rec">RECOMMENDED</span></h3>'
             '<p>The single most-blocking thing gets a big card. Everything else is one compact line '
             'each, in three plain-English groups: <i>nothing else moves until you say</i>, '
             '<i>one word and members see it</i>, <i>just needs your eyes</i>. Longest-waiting first '
             'inside each group. Agents, seats, parked branches and history all fold into one drawer '
             'at the bottom.</p>'
             '<div class="links">'
             '<a class="door" href="option-a.html?theme=light">Open it &#8599;</a>'
             '<a class="ghost" href="option-a.html?theme=dark" style="text-decoration:none">Dark</a>'
             '</div></div>')
    b.append('<div class="opt"><h3>Option B &mdash; one at a time</h3>'
             '<p>The page shows exactly one thing, full size, with a counter: <i>1 of 21</i>. '
             'Answer it or skip it and the next appears. There is nothing to parse &mdash; but you '
             'lose the cold-open snapshot, which is the one thing this page can do that chat cannot. '
             'That is why I recommend A.</p>'
             '<div class="links">'
             '<a class="door" href="option-b.html?theme=light">Open it &#8599;</a>'
             '<a class="ghost" href="option-b.html?theme=dark" style="text-decoration:none">Dark</a>'
             '</div></div>')

    b.append('<h2 class="sec">What is new in both</h2>')
    b.append('<ul class="pts">'
             '<li><b>Ranked by what is stuck behind it</b>, then by how long it has waited &mdash; '
             'not by issue number, which is what orders the list today. Five things have been '
             'waiting 3.7 days at the <i>bottom</i> of your current list.</li>'
             '<li><b>Every item says what is waiting behind it</b> in one line.</li>'
             '<li><b>The list comes from what the lanes actually said</b>, so it cannot freeze '
             'when someone forgets a label.</li>'
             '<li><b>Your confirm button (#178) is drawn in place</b> &mdash; the &ldquo;Landed as '
             'expected&rdquo; control, and what an item looks like just after you tap it. '
             'Its plan is already written and parked.</li>'
             '<li><b>A light theme.</b> The page is dark-only today, whatever your phone is set to.</li>'
             '<li><b>Plain English at the top.</b> The deploy line currently reads '
             '<code>main 19b43dd dev2 19b43dd live 2163c08</code>.</li>'
             '</ul>')

    b.append('<h2 class="sec">Pictures</h2>')
    b.append('<div class="shots">')
    for fn, cap in SHOTS:
        b.append(f'<a href="{fn}" target="_blank"><img src="{fn}" alt="{E(cap)}" loading="lazy">{E(cap)}</a>')
    b.append('</div>')

    b.append('<h2 class="sec">Nothing has been built</h2>'
             '<p style="color:var(--m-soft);font-size:14px">These are static pictures. The buttons '
             'do not do anything yet. Say which shape you want and a build seat takes it from there.</p>')
    return shell("Todo page proposal — #202", "".join(b), css)


SHOTS = [
    ("a-desktop-light.png", "Option A — desktop, light"),
    ("a-desktop-dark.png", "Option A — desktop, dark"),
    ("a-phone-light.png", "Option A — phone, light"),
    ("a-phone-dark.png", "Option A — phone, dark"),
    ("b-desktop-light.png", "Option B — desktop, light"),
    ("b-desktop-dark.png", "Option B — desktop, dark"),
    ("b-phone-light.png", "Option B — phone, light"),
    ("b-phone-dark.png", "Option B — phone, dark"),
]


def main():
    out = sys.argv[1] if len(sys.argv) > 1 else os.path.join(
        os.path.dirname(os.path.abspath(__file__)), "..", "..", "..",
        "footer-mockups", "202-todo-proposal")
    out = os.path.abspath(out)
    os.makedirs(out, exist_ok=True)
    for name, fn in (("option-a.html", render_option_a),
                     ("option-b.html", render_option_b),
                     ("index.html", render_index)):
        p = os.path.join(out, name)
        with open(p, "w", encoding="utf-8") as fh:
            fh.write(fn())
        print(f"wrote {p}  ({os.path.getsize(p):,} bytes)")


if __name__ == "__main__":
    main()
