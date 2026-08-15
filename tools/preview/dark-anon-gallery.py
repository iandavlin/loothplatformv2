#!/usr/bin/env python3
"""dark-anon-gallery.py — turn sweep.json into a BEFORE gallery Ian can judge.

Ian decides from pictures, so this leads with the screenshot and puts the
numbers underneath in plain English. Ranked worst-first: the point of the page
is "here is what a logged-out visitor in dark actually sees, worst first",
not "here is a table of ratios".

DEFENDED AGAINST THE DOCROOT, deliberately and at some cost to elegance. Pages
under /var/www/dev get nginx's server-wide sub_filter, which injects the boot
script, /pwa.js and the app chrome into ANYTHING it serves as text/html —
including this gallery. That has already wrecked a previous lane's mocks: the
boot script rewrites the --lg-* tokens as INLINE STYLE on <html>, so a mock
built on real tokens INVERTS, and the injected tabbar covers the bottom ~55px
of the very thing being shown. So this page:
  · uses LITERAL hex everywhere and never reads a --lg-* token;
  · pins background AND color on its own <body> so an inherited token can't
    reach it;
  · leaves 140px of bottom padding so the injected tabbar covers nothing.
Do not "tidy" those into variables.

Usage: python3 tools/preview/dark-anon-gallery.py <sweep-dir>
"""

import html
import json
import pathlib
import sys

WORST_FIRST = True


def band(r):
    """Plain-English severity. AA wants 4.5:1 for normal text."""
    if r is None:
        return ("none", "#3f8f5f", "no contrast failures")
    if r < 2.0:
        return ("unreadable", "#c0392b", "effectively invisible")
    if r < 3.0:
        return ("very poor", "#d35400", "unreadable for most people")
    if r < 4.5:
        return ("below AA", "#b7950b", "hard to read; fails the standard")
    return ("ok", "#3f8f5f", "passes")


def main():
    d = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "./sweep")
    rows = json.loads((d / "sweep.json").read_text())

    for r in rows:
        f = r.get("findings") or []
        r["_worst"] = f[0]["ratio"] if f else None
        r["_n"] = len(f)
        # rank: unreadable-and-many first
        r["_rank"] = (r["_worst"] if r["_worst"] is not None else 99, -r["_n"])

    ranked = sorted(rows, key=lambda r: r["_rank"]) if WORST_FIRST else rows

    tot = sum(r["_n"] for r in rows)
    surfaces_bad = len({r["surface"] for r in rows if r["_n"]})
    theme_misses = [r for r in rows if r["resolvedTheme"] != "dark"]

    out = []
    A = out.append
    A('<!doctype html><html><head><meta charset="utf-8">')
    A('<meta name="viewport" content="width=device-width,initial-scale=1">')
    A('<title>Dark mode, logged out — what it looks like now</title>')
    A('<style>')
    A('html{background:#14161a!important;color-scheme:dark}')
    A('body{background:#14161a!important;color:#e8eae6!important;margin:0;'
      'padding:28px 20px 140px;font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
      '-webkit-font-smoothing:antialiased}')
    A('.wrap{max-width:1180px;margin:0 auto}')
    A('h1{font-size:26px;margin:0 0 6px;color:#fff!important;letter-spacing:-.02em}')
    A('.sub{color:#a8ada6!important;margin:0 0 22px;font-size:15px;max-width:70ch}')
    A('.tot{display:flex;gap:26px;flex-wrap:wrap;background:#1b1e23;border:1px solid #2b3038;'
      'border-radius:12px;padding:16px 20px;margin:0 0 26px}')
    A('.tot div{font-size:13px;color:#a8ada6}')
    A('.tot b{display:block;font-size:24px;color:#fff;font-weight:650;letter-spacing:-.02em}')
    A('.card{background:#1b1e23;border:1px solid #2b3038;border-radius:14px;'
      'padding:18px;margin:0 0 20px;overflow:hidden}')
    A('.hd{display:flex;align-items:baseline;gap:12px;flex-wrap:wrap;margin:0 0 4px}')
    A('.hd h2{font-size:18px;margin:0;color:#fff!important;font-weight:650}')
    A('.tag{font-size:11px;letter-spacing:.06em;text-transform:uppercase;'
      'padding:3px 8px;border-radius:999px;background:#262a31;color:#aeb4ac}')
    A('.pill{font-size:12px;font-weight:700;padding:3px 9px;border-radius:999px;color:#fff}')
    A('.path{font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;color:#8d938c;margin:0 0 12px}')
    A('.grid{display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:20px;align-items:start}')
    A('@media(max-width:800px){.grid{grid-template-columns:1fr}}')
    A('.shot{width:100%;border:1px solid #333;border-radius:8px;display:block;background:#000}')
    A('table{border-collapse:collapse;width:100%;font-size:13px}')
    A('th,td{text-align:left;padding:6px 9px;border-bottom:1px solid #262a31;vertical-align:top}')
    A('th{color:#8d938c;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.05em}')
    A('td.r{font:600 13px ui-monospace,Menlo,monospace;white-space:nowrap}')
    A('code{font:12px ui-monospace,Menlo,monospace;color:#9fb98a;word-break:break-all}')
    A('.sample{color:#d7dad4}')
    A('.warn{background:#3a2a1a;border:1px solid #6b4a22;color:#f0d5a8;'
      'border-radius:10px;padding:14px 18px;margin:0 0 22px;font-size:14px}')
    A('.ok{color:#7fd19a}')
    A('</style></head><body><div class="wrap">')

    A('<h1>Dark mode, logged out — what it looks like now</h1>')
    A('<p class="sub">Every page a signed-out visitor can reach, loaded in dark mode and '
      'measured for readability. Worst first. Nothing here is fixed yet — this is the '
      '&ldquo;before&rdquo; so you can pick what gets fixed.</p>')

    A('<div class="tot">')
    A(f'<div><b>{tot}</b>unreadable items found</div>')
    A(f'<div><b>{surfaces_bad}</b>pages affected</div>')
    A(f'<div><b>{len(rows)}</b>page loads measured</div>')
    A('</div>')

    A('<div class="warn"><b>How dark mode is reached matters.</b> A visitor gets dark '
      'either by choosing it in the settings gear (<b>app-dark</b>) or by their phone/computer '
      'being set to dark while they chose nothing (<b>os-dark</b>). Those run different code, '
      'so each page below is shown both ways. Where they differ, that difference is itself a bug.</div>')

    if theme_misses:
        names = sorted({f"{r['label']} ({r['mode']}/{r['device']})" for r in theme_misses})
        A('<div class="warn"><b>Dark did not switch on at all here:</b><br>' +
          html.escape(", ".join(names)) +
          '<br><br>These pages stayed light. For a visitor whose phone is in dark mode, '
          'that is a bright white page in a dark app.</div>')

    for r in ranked:
        w = r["_worst"]
        label, colour, plain = band(w)
        A('<div class="card">')
        A('<div class="hd">')
        A(f'<h2>{html.escape(r["label"])}</h2>')
        A(f'<span class="tag">{html.escape(r["mode"])}</span>')
        A(f'<span class="tag">{html.escape(r["device"])}</span>')
        if w is not None:
            A(f'<span class="pill" style="background:{colour}">{w}:1 &middot; {label}</span>')
        else:
            A('<span class="pill" style="background:#3f8f5f">clean</span>')
        A('</div>')
        A(f'<p class="path">{html.escape(r["path"])} &nbsp;·&nbsp; '
          f'page background {html.escape(r["bodyBg"])} &nbsp;·&nbsp; '
          f'theme resolved: {html.escape(r["resolvedTheme"])}</p>')
        A('<div class="grid">')
        A(f'<img class="shot" src="shots/{html.escape(r["shot"])}" alt="">')
        A('<div>')
        if r["_n"]:
            A(f'<p style="margin:0 0 8px;color:#c9cec7">'
              f'<b style="color:#fff">{r["_n"]}</b> item(s) below the readability standard — '
              f'worst is <b style="color:{colour}">{plain}</b>. Showing the worst 8:</p>')
            A('<table><tr><th>Contrast</th><th>What it is</th><th>Text</th></tr>')
            for f in r["findings"][:8]:
                _, c2, _ = band(f["ratio"])
                kind = {"text": "text", "field-text": "what you type",
                        "placeholder": "hint inside field",
                        "field-border": "edge of the box",
                        "field-borderless": "the box itself",
                        "icon-control": "icon on its button"}.get(f["kind"], f["kind"])
                A(f'<tr><td class="r" style="color:{c2}">{f["ratio"]}:1'
                  f'<br><span style="color:#7b817a;font-weight:400">needs {f["need"]}</span></td>'
                  f'<td>{html.escape(kind)}<br><code>{html.escape(f["sel"][-70:])}</code></td>'
                  f'<td class="sample">{html.escape(f["sample"])}<br>'
                  f'<code>{f["fg"]} on {f["bg"]}</code></td></tr>')
            A('</table>')
        else:
            A('<p class="ok" style="margin:0">No readability failures measured on this page.</p>')
        if r.get("unmeasurable"):
            A(f'<p style="color:#8d938c;font-size:12px;margin:10px 0 0">'
              f'{len(r["unmeasurable"])} item(s) sit on an image or gradient, so contrast '
              f'cannot be measured automatically — these need an eye, not a number.</p>')
        A('</div></div></div>')

    A('</div></body></html>')
    (d / "index.html").write_text("\n".join(out))
    print(f"  gallery -> {d/'index.html'}  ({tot} findings across {surfaces_bad} surfaces)")


if __name__ == "__main__":
    main()
