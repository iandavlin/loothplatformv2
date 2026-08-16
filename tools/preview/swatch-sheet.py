#!/usr/bin/env python3
"""Backlog 37 — the paired-token SWATCH SHEET. Ian approves colours from this.

EVERY RATIO PRINTED IS COMPUTED, NEVER TYPED. Same WCAG formula the contrast
probe uses (tools/gates/lib/contrast-probe.js). This lane spent a day proving
that an INTENDED value and a MEASURED one are different things — a swatch sheet
that printed intended ratios would scale that mistake into every future surface.

Pairs are DERIVED FROM MEASUREMENTS, not from taste: each one is a pairing this
lane actually measured on a real rendered page this week (the work board, the
login family, the hub/events/directory/shop surfaces).
"""
import io, os

def lin(c):
    c /= 255.0
    return c/12.92 if c <= 0.04045 else ((c+0.055)/1.055) ** 2.4

def lum(h):
    h = h.lstrip("#")
    if len(h) == 3: h = "".join(c*2 for c in h)
    r,g,b = (int(h[i:i+2],16) for i in (0,2,4))
    return 0.2126*lin(r) + 0.7152*lin(g) + 0.0722*lin(b)

def ratio(fg,bg):
    a,b = lum(fg), lum(bg)
    hi,lo = max(a,b), min(a,b)
    return round((hi+0.05)/(lo+0.05), 2)

# name, role, bar, (light fg, light bg), (dark fg, dark bg), where it was measured
PAIRS = [
 ("Body ink on page","text",4.5,("#1f1d1a","#f6f3ee"),("#e5e7e1","#15171a"),"work board"),
 ("Label on card","text",4.5,("#1f1d1a","#fffdf9"),("#e5e7e1","#1b1e21"),"work board / login cards"),
 ("Soft ink on card","text",4.5,("#4a463f","#fffdf9"),("#cdd0ca","#1b1e21"),"work board"),
 ("Muted on card","text",4.5,("#8a8478","#fffdf9"),("#a8ada6","#1b1e21"),"work board / login note"),
 ("Muted on page","text",4.5,("#8a8478","#f6f3ee"),("#a8ada6","#15171a"),"work board"),
 ("Muted on rail","text",4.5,("#8a8478","#fbf8f2"),("#a8ada6","#202426"),"work board rails"),
 ("Link on card","text",4.5,("#b9450b","#fffdf9"),("#9cb37d","#1b1e21"),"login cards"),
 ("Accent on card","text",4.5,("#b9450b","#fffdf9"),("#e8a07a","#1b1e21"),"work board desk strip"),
 ("Onboard subtext","text",4.5,("#666666","#ffffff"),("#a8ada6","#1b1e21"),"bpnoaccess (gate 53)"),
 ("Accordion label","text",4.5,("#2c3e50","#fbfcf8"),("#e5e7e1","#1b1e21"),"login accordions"),
 ("Chip: done","text",4.5,("#5a7a3a","#e8efe2"),("#b6c79a","#243024"),"work board chips"),
 ("Chip: look","text",4.5,("#b8860b","#f7ecd5"),("#e8c073","#2e2a1f"),"work board chips"),
 ("Chip: unowned","text",4.5,("#4a463f","#eee9df"),("#cdd0ca","#262b30"),"work board chips"),
 ("Chip: decide","text",4.5,("#ffffff","#8a3f1d"),("#ffffff","#8a3f1d"),"work board chips"),
 ("Chip: blocked","text",4.5,("#ffffff","#8a3208"),("#f0937a","#2e211c"),"work board"),
 ("Field border on page","non-text",3.0,("#e6e0d4","#f6f3ee"),("#767c76","#1e2124"),"hub / events / directory"),
 ("Card border on page","non-text",3.0,("#e6e0d4","#f6f3ee"),("#2c312d","#15171a"),"work board"),
 ("Search border on bar","non-text",3.0,("#e6e0d4","#fffdf9"),("#767c76","#15171a"),"hub-tsearch (fixed today)"),
]

rows=[]
fails=0
for name,role,bar,(lf,lb),(df,db),where in PAIRS:
    lr, dr = ratio(lf,lb), ratio(df,db)
    lok, dok = lr>=bar, dr>=bar
    if not lok: fails+=1
    if not dok: fails+=1
    def cell(fg,bg,r,ok):
        return (f'<td class="sw"><div class="chip" style="background:{bg};color:{fg}">Aa</div>'
                f'<div class="hex">{fg}<br><span>on {bg}</span></div>'
                f'<div class="r {"ok" if ok else "bad"}">{r}:1{"" if ok else " ✕"}</div></td>')
    rows.append(f'<tr><th>{name}<em>{where}</em></th><td class="bar">{bar}:1<br><span>{role}</span></td>'
                + cell(lf,lb,lr,lok) + cell(df,db,dr,dok) + '</tr>')

html = f"""<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paired-token swatch sheet</title><style>
body{{margin:0;background:#f6f3ee;color:#1f1d1a;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}}
.wrap{{max-width:1000px;margin:0 auto;padding:22px 18px 70px}}
h1{{font-size:1.3rem;margin:0 0 6px}}
p.lede{{color:#4a463f;margin:0 0 18px;max-width:70ch}}
table{{border-collapse:collapse;width:100%;background:#fffdf9;border:1px solid #e6e0d4;border-radius:10px;overflow:hidden}}
th,td{{padding:10px 12px;text-align:left;vertical-align:top;border-top:1px solid #efeae0}}
thead th{{background:#f0ece2;font-size:.8rem;text-transform:uppercase;letter-spacing:.04em;border-top:0}}
th{{font-weight:600}} th em{{display:block;font-style:normal;font-weight:400;font-size:.74rem;color:#8a8478;margin-top:2px}}
td.bar{{font-size:.8rem;color:#4a463f;white-space:nowrap}} td.bar span{{color:#8a8478;font-size:.72rem}}
.chip{{display:flex;align-items:center;justify-content:center;width:62px;height:40px;border-radius:7px;
 border:1px solid rgba(128,128,128,.35);font-weight:700}}
.hex{{font:11px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace;color:#4a463f;margin-top:5px}}
.hex span{{color:#8a8478}}
.r{{margin-top:4px;font-size:.82rem;font-weight:700}}
.r.ok{{color:#3f6b2a}} .r.bad{{color:#a3230a}}
.sum{{margin:16px 0 0;padding:11px 13px;border-radius:9px;font-size:.9rem}}
.sum.pass{{background:#e8efe2;color:#33521f;border:1px solid #b9cfa4}}
.sum.fail{{background:#fdf3f0;color:#8a3208;border:1px solid #eccfc4}}
.note{{margin-top:22px;font-size:.82rem;color:#4a463f;background:#fffaf3;border:1px solid #f2e2d3;
 border-radius:9px;padding:12px 14px}}
.note b{{color:#1f1d1a}}
</style></head><body><div class="wrap">
<h1>Paired-token swatch sheet</h1>
<p class="lede">Every semantic pair in <b>both themes, side by side</b>. Ian approves colours from this
picture; the shared tokens file is then built to match.</p>
<div class="sum {'pass' if fails==0 else 'fail'}">{'All ' + str(len(PAIRS)*2) + ' pairings clear their bar.' if fails==0 else str(fails) + ' pairing(s) below their bar — marked ✕.'}</div>
<table><thead><tr><th>Pair</th><th>Bar</th><th>Light</th><th>Dark</th></tr></thead>
<tbody>{''.join(rows)}</tbody></table>
<div class="note">
<b>Every ratio here is computed, never typed.</b> Same WCAG formula the contrast gate uses. Colours are
paired as they were actually <b>measured on rendered pages</b> this week — the work board, the login
family, and the hub / events / directory / shop surfaces — not chosen from a palette and hoped for.
<br><br>
<b>Why that distinction is the whole point.</b> Four separate times this week a pairing that looked
correct in the source measured wrong on the real page: ink that only fails for 250ms while a card
transitions, a fill that equals its own page, a rule silently outranked by a more specific sibling,
and a token flip that reached one rule and not its neighbour. A sheet printing <i>intended</i> ratios
would have shown green for all four.
<br><br>
<b>Bars.</b> 4.5:1 for text (WCAG 1.4.3); 3:1 for borders and other non-text boundaries (1.4.11).
Composited fades (<code>opacity</code>) are resolved to their effective colour before measuring —
a 35% ink is not the ink you specified.
</div>
</div></body></html>"""
out = os.path.join(os.path.dirname(os.path.abspath(__file__)), "swatch-sheet", "index.html")
os.makedirs(os.path.dirname(out), exist_ok=True)
io.open(out,"w",encoding="utf-8").write(html)
print("wrote %s (%d pairs, %d sub-bar cells)" % (out, len(PAIRS), fails))
