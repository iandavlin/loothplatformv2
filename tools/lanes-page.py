#!/usr/bin/env python3
"""lanes-page — render `lanes --json` to a static page (handoff-3, approved 8/18).

Static file, no service: a systemd timer (platform/systemd/lanes-page.timer)
runs this as ubuntu every 5 minutes. Writes index.html AND lanes.json side by
side (Ian's addition: structured data over the same gated 443, no terminal
needed). The page prints its own generation time so a dead timer shows as an
old timestamp — staleness is visible, never a quiet lie.

Page order and rules (Ian's spec): deploy state, invisible when in agreement ·
AT RISK, absent when empty and impossible to miss when not · lanes, behind
ascending, mismatches flagged, finished lanes LEAVE (they show only as a
freeable-seat count) · cleanup footnote · shipped-last-7-days strip, which is
where a merged-but-never-flipped flag gets caught.
"""
import datetime
import html
import json
import pathlib
import subprocess

REPO = "/home/ubuntu/keeper-repo"
OUT = pathlib.Path("/var/www/dev/mockups/lanes")


def run(cmd):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=90).stdout


def main():
    data = json.loads(run(["/usr/local/bin/lanes", "--json"]))
    dep = data["deploy"]

    merged = [b.strip().lstrip("+* ") for b in run(
        ["git", "-C", REPO, "branch", "--merged", "main"]).splitlines()]
    merged = [b for b in merged if b and b != "main"]
    backups = [b for b in run(
        ["git", "-C", REPO, "for-each-ref", "--format=%(refname:short)",
         "refs/heads"]).splitlines() if b.endswith("-backup")]
    shipped = [l for l in run(
        ["git", "-C", REPO, "log", "--first-parent", "main",
         "--since=7 days ago", "--format=%ad|%s",
         "--date=format:%m/%d"]).splitlines() if l][:20]

    rows = [l for l in data["lanes"]
            if not l["scratch"] and l["status"] not in ("parent",)]
    freeable = [l for l in rows if l["status"] == "done"]
    table = [l for l in rows if l["status"] != "done"]   # finished lanes leave
    at_risk = [l for l in rows if l["status"] == "at-risk"]

    now = datetime.datetime.now()
    chip = {"live": ("live lane", "#9db668"),
            "stood-down": ("stood down", "#9aa3ad"),
            "re-cut": ("re-cut, don't rebase", "#e0b64f"),
            "at-risk": ("AT RISK", "#e05f4f"),
            "detached": ("detached", "#e0b64f")}

    h = []
    h.append("""<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>lanes</title><style>
body{background:#14161a;color:#e8e6df;font:15px/1.5 system-ui,sans-serif;margin:0;padding:16px;}
.wrap{max-width:760px;margin:0 auto;}
h1{font-size:18px;margin:0 0 14px;}
.block{border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.gap{background:#3a2420;border:1px solid #7a4438;}
.risk{background:#4a1f18;border:2px solid #e05f4f;font-size:17px;font-weight:700;}
table{width:100%;border-collapse:collapse;font-size:14px;}
th{text-align:left;color:#9aa3ad;font-size:11px;text-transform:uppercase;letter-spacing:.05em;padding:6px 8px;}
td{padding:8px;border-top:1px solid #2a2f38;vertical-align:top;}
td.num{text-align:right;font-variant-numeric:tabular-nums;}
.unique{font-weight:700;font-size:16px;}
.chip{display:inline-block;border-radius:20px;padding:1px 10px;font-size:12px;font-weight:700;color:#14161a;}
.mm{color:#e0b64f;font-weight:700;}
.dim{color:#9aa3ad;font-size:13px;}
.foot{color:#9aa3ad;font-size:12px;margin-top:18px;}
.strip{border-top:1px solid #2a2f38;margin-top:16px;padding-top:10px;}
.strip div{font-size:13px;color:#9aa3ad;padding:2px 0;}
.strip b{color:#e8e6df;font-weight:600;}
</style></head><body><div class="wrap"><h1>lanes</h1>""")

    # 1. deploy — invisible when everything agrees
    if not dep["in_sync"] or dep["live_state"] == "unknown":
        h.append('<div class="block gap"><b>DEPLOY GAP</b><br>')
        h.append(f'main {html.escape((dep["main"] or "")[:7])}<br>')
        d2 = dep["dev2"][:7] if dep["dev2"] else "MISSING"
        h.append(f'dev2 {html.escape(d2)}'
                 + (' &larr; differs' if dep["dev2"] != dep["main"] else '') + '<br>')
        if dep["live_state"] == "unknown":
            h.append('live UNKNOWN — read failed; not proof of health')
        elif dep["live_state"] == "ok":
            h.append(f'live {html.escape(dep["live"][:7])}'
                     + (' &larr; differs' if dep["live"] != dep["main"] else ''))
        h.append('</div>')

    # 2. at risk — absent when empty, unmissable when not
    for l in at_risk:
        h.append(f'<div class="block risk">AT RISK — {html.escape(l["branch"])} '
                 f'has {l["unique"]} commit(s) on one disk only '
                 f'({html.escape(l["folder"])})</div>')

    # 3. the lanes (finished lanes have left the table)
    h.append('<table><tr><th>seat</th><th style="text-align:right">unique</th>'
             '<th style="text-align:right">behind</th><th>status</th></tr>')
    for l in table:
        label, color = chip.get(l["status"], (l["status"], "#7fa8d9"))
        folder = html.escape(l["folder"].removeprefix("worktrees/"))
        branch = html.escape(l["branch"])
        seat = f'<b>{branch}</b>'
        if l["mismatch"]:
            seat += f'<br><span class="mm">&ne; folder: {folder}</span>'
        else:
            seat += f'<br><span class="dim">{folder}</span>'
        h.append(f'<tr><td>{seat}</td>'
                 f'<td class="num unique">{l["unique"]}</td>'
                 f'<td class="num dim">{l["behind"]}</td>'
                 f'<td><span class="chip" style="background:{color}">{label}</span></td></tr>')
    h.append('</table>')

    # 4. cleanup footnote
    h.append(f'<div class="foot">cleanup: {len(merged)} merged branch(es) '
             f'deletable &middot; {len(backups)} backup branch(es) held for '
             f'review &middot; {len(freeable)} seat(s) finished and freeable</div>')

    # 5. shipped, last 7 days — self-clearing; where an unflipped flag shows up
    if shipped:
        h.append('<div class="strip"><b>Landed on main, last 7 days</b>')
        for line in shipped:
            d, _, s = line.partition("|")
            h.append(f'<div><b>{html.escape(d)}</b> {html.escape(s[:100])}</div>')
        h.append('</div>')

    h.append(f'<div class="foot">generated {now.strftime("%H:%M")} &middot; '
             f'redraws every 5 minutes — an old timestamp means the timer is '
             f'dead, not that all is well</div>')
    h.append('</div></body></html>')

    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "index.html").write_text("".join(h), encoding="utf-8")
    data["generated"] = now.isoformat(timespec="seconds")
    data["cleanup"] = {"merged_deletable": len(merged),
                       "backups_held": len(backups),
                       "seats_freeable": len(freeable)}
    (OUT / "lanes.json").write_text(json.dumps(data, indent=1), encoding="utf-8")


if __name__ == "__main__":
    main()
