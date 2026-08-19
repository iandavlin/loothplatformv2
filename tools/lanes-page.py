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
OUT = pathlib.Path("/var/www/dev/lanes")
API = "https://api.github.com/repos/iandavlin/loothplatformv2"


def run(cmd):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=90).stdout


def _token():
    for line in open("/etc/looth/env"):
        if line.startswith("LG_GITHUB_ISSUES_TOKEN="):
            return line.split("=", 1)[1].strip()
    return ""


def _gh(url):
    import urllib.request
    req = urllib.request.Request(
        url if url.startswith("http") else API + url,
        headers={"Authorization": f"Bearer {_token()}",
                 "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.loads(r.read())


def copy_btn(label, payload):
    """A clipboard button (#133): payload rides in an attribute-escaped
    data-copy; the page's one script does the rest. No fetch, no token."""
    return (f'<button class="copybtn" '
            f'data-copy="{html.escape(payload, quote=True)}">{label}</button>')


def fetch_issue_state():
    """Needs-you (plan-ready without approved, with embedded plan text) and
    all open issues (to match building seats by branch number). Failure is
    LOUD on the page, never a silently empty section."""
    try:
        pr = _gh("/issues?labels=plan-ready&state=open&per_page=50")
        needs = [i for i in pr
                 if not any(l["name"] == "approved" for l in i["labels"])]
        investigating = _gh("/issues?labels=investigating&state=open&per_page=50")
        for i in needs:
            plan = None
            if i.get("comments"):
                cs = _gh(i["comments_url"])
                plan = next((c["body"] for c in reversed(cs)
                             if "Files I expect to touch" in c.get("body", "")),
                            None)
            i["_plan"] = plan or i.get("body") or "(no plan text found)"
        allopen = _gh("/issues?state=open&per_page=100")
        return needs, investigating, allopen, True
    except Exception:
        return [], [], [], False


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
    cap = data.get("capacity", {})
    unb = data.get("unbacked", {})
    collisions = data.get("collisions", [])
    parked = data.get("parked", [])

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
.copybtn,.rfbtn{background:#2d323b;color:#9db668;border:1px solid #3a4049;border-radius:6px;
  padding:2px 10px;font-size:12px;font-weight:700;cursor:pointer;margin-left:8px;}
</style></head><body><div class="wrap"><h1>lanes</h1>""")

    # 0. capacity — one glance, no counting rows (Ian's item 1)
    if cap:
        h.append(f'<div class="dim" style="margin-bottom:12px">seats '
                 f'<b style="color:#e8e6df">{cap["seats_used"]}/{cap["seat_ceiling"]}</b>'
                 f' &middot; working cap {cap["working_cap"]}'
                 f' <span class="dim">(1 while you&rsquo;re actively on dev2)</span></div>')

    # 0b. resource strip (#143) — sampled each render, free (live rides the
    # deploy ssh). Loud only past the box's own known danger lines.
    res = data.get("resources", {})
    if res.get("dev2"):
        d2 = res["dev2"]
        lv = res.get("live")
        swap = int(d2.get("swap_m") or 0)
        warn = (float(d2.get("load") or 0) > 2
                or int(str(d2.get("disk", "0%")).rstrip("%") or 0) > 90
                or swap > 1024
                or (lv and float(lv.get("load") or 0) > 2))
        line = (f'dev2 load {d2.get("load")} · mem {d2.get("mem_used_m")}/'
                f'{d2.get("mem_total_m")}M · disk {d2.get("disk")}')
        if swap:
            line += f' · swap {swap}M'
        if lv:
            line += f' — live load {lv.get("load")} · disk {lv.get("disk")}'
        style = 'color:#e05f4f;font-weight:700;' if warn else ''
        h.append(f'<div class="dim" style="margin:-6px 0 12px;{style}">'
                 f'{html.escape(line)}'
                 f'<button class="rfbtn" id="lg-refresh">refresh</button></div>')

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
    if unb.get("total", 0) > 0:
        names = " &middot; ".join(
            f'{html.escape(b["branch"])} ({b["count"]})' for b in unb.get("branches", []))
        h.append(f'<div class="block risk">UNBACKED — {unb["total"]} commit(s) '
                 f'exist only on this box: {names}</div>')
    if not unb.get("fetch_ok", True):
        h.append('<div class="block gap">fetch failed — the unbacked count may '
                 'be stale, not necessarily zero</div>')

    # 2b. collisions — same file in more than one lane; absent when empty
    for c in collisions:
        lanes_named = ", ".join(html.escape(b) for b in c["branches"])
        h.append(f'<div class="block risk">COLLISION — '
                 f'<code>{html.escape(c["file"])}</code> is changed by: '
                 f'{lanes_named}</div>')

    # 2c. NEEDS YOU — plan-ready issues awaiting Ian's ruling. The missing
    # section this whole build was plumbing toward. Absent when empty; the
    # plan text is embedded at render time (<details>, no JS, no fetch); the
    # one action is a link — no token ever reaches the browser.
    needs, investigating, allopen, gh_ok = fetch_issue_state()
    if not gh_ok:
        h.append('<div class="block gap">GitHub unreadable — the "needs you" '
                 'state is UNKNOWN right now, not necessarily empty</div>')
    import datetime as _dt
    import hmac as _hmac
    import hashlib as _hl
    for i in needs:
        upd = _dt.datetime.fromisoformat(i["updated_at"].replace("Z", "+00:00"))
        days = max(0, (_dt.datetime.now(_dt.timezone.utc) - upd).days)
        pointer = f'Re issue #{i["number"]} — your plan-ready plan. Question: '
        # #139: per-day HMAC nonce for the one-verb approve endpoint. Derived
        # from the token server-side; only the digest ships to the browser.
        nonce = _hmac.new(_token().encode(),
                          f'approve:{i["number"]}:{_dt.datetime.now(_dt.timezone.utc):%Y-%m-%d}'.encode(),
                          _hl.sha256).hexdigest()
        h.append(
            f'<details class="block" style="background:#3a3320;border:2px '
            f'solid #e0b64f"><summary style="font-weight:700;font-size:16px;'
            f'cursor:pointer">NEEDS YOU — {html.escape(i["title"])} '
            f'<span class="dim">· waiting {days}d</span></summary>'
            f'<div style="white-space:pre-wrap;font-size:13.5px;margin:10px 0">'
            f'{html.escape(i["_plan"][:6000])}</div>'
            f'<button class="copybtn apprbtn" style="background:#2a3a20;'
            f'color:#9db668;margin-left:0" data-issue="{i["number"]}" '
            f'data-nonce="{nonce}">Approve ✓</button>'
            f'<a href="{html.escape(i["html_url"])}" target="_blank" '
            f'rel="noopener" style="color:#9db668;font-weight:700;'
            f'margin-left:10px">on GitHub &#8599;</a>'
            f'{copy_btn("Copy for keeper", pointer)}'
            f'{copy_btn("Copy plan", i["_plan"][:6000])}'
            f'</details>')

    # 2c2. in motion (no seat) — active investigations (#137): keeper working,
    # nothing needed from Ian yet. Explicitly labeled only; absent when none.
    if investigating:
        h.append('<div class="strip"><b>In motion (no seat)</b> <span '
                 'class="dim">— investigations keeper is actively working</span>')
        for i in investigating:
            upd = _dt.datetime.fromisoformat(i["created_at"].replace("Z", "+00:00"))
            hrs = max(0, int((_dt.datetime.now(_dt.timezone.utc) - upd)
                             .total_seconds() // 3600))
            age = f"{hrs}h" if hrs < 48 else f"{hrs // 24}d"
            h.append(f'<div><a href="{html.escape(i["html_url"])}" '
                     f'target="_blank" rel="noopener" style="color:#c9a0dc">'
                     f'#{i["number"]} {html.escape(i["title"][:70])}</a> '
                     f'<span class="dim">· running {age}</span></div>')
        h.append('</div>')

    # 2d. building — open issues matched to seats by branch leading number
    seat_nums = {l["branch"].split("-")[0]: l["branch"] for l in table
                 if l["branch"].split("-")[0].isdigit()}
    issue_by_num = {str(i["number"]): i for i in allopen}
    building = [i for i in allopen if str(i["number"]) in seat_nums]
    if building:
        h.append('<div class="strip"><b>Building</b>')
        for i in building:
            h.append(f'<div><b>#{i["number"]}</b> {html.escape(i["title"])} '
                     f'<span class="dim">seat: '
                     f'{html.escape(seat_nums[str(i["number"])])}</span></div>')
        h.append('</div>')

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
        # seat ↔ issue: derived from the branch's leading number, nothing to
        # keep true by hand; title inline, plan readable in place
        num = l["branch"].split("-")[0]
        iss = issue_by_num.get(num) if num.isdigit() else None
        if iss:
            seat += (f'<br><a href="{html.escape(iss["html_url"])}" '
                     f'target="_blank" rel="noopener" '
                     f'class="dim" style="color:#7fa8d9">#{iss["number"]} '
                     f'{html.escape(iss["title"][:60])}</a>')
            plan = None
            try:
                if iss.get("comments"):
                    cs = _gh(iss["comments_url"])
                    plan = next((c["body"] for c in reversed(cs)
                                 if "Files I expect to touch" in c.get("body", "")), None)
            except Exception:
                pass
            if plan:
                seat += (f'<details><summary class="dim" style="cursor:pointer">'
                         f'plan</summary><div style="white-space:pre-wrap;'
                         f'font-size:12.5px">{html.escape(plan[:5000])}</div>'
                         f'</details>')
        if l.get("riders"):
            seat += ('<br><span class="dim">riders: '
                     + " ".join(f'#{r}' for r in l["riders"]) + '</span>')
        seat_line = (f'/home/ubuntu/{l["folder"]}  (branch {l["branch"]})')
        seat += "<br>" + copy_btn("copy path+branch", seat_line)
        h.append(f'<tr><td>{seat}</td>'
                 f'<td class="num unique">{l["unique"]}</td>'
                 f'<td class="num dim">{l["behind"]}</td>'
                 f'<td><span class="chip" style="background:{color}">{label}</span></td></tr>')
    h.append('</table>')

    # 3c. reconciliation — absent when clean. Approved-with-no-seat is the one
    # that matters: "I said go and nothing started" must never look identical
    # to work in progress.
    if gh_ok:
        approved_orphans = [
            i for i in allopen
            if any(l["name"] == "approved" for l in i["labels"])
            and str(i["number"]) not in seat_nums]
        for i in approved_orphans:
            h.append(f'<div class="block risk">APPROVED, NOT STARTED — '
                     f'<a href="{html.escape(i["html_url"])}" '
                     f'target="_blank" rel="noopener" '
                     f'style="color:#e8e6df">#{i["number"]} '
                     f'{html.escape(i["title"][:70])}</a></div>')
    unnumbered_seats = [l["branch"] for l in table
                        if not l["branch"].split("-")[0].isdigit()]
    if unnumbered_seats:
        h.append(f'<div class="dim" style="margin-top:8px">seats without an '
                 f'issue (the old world, counting down): '
                 f'{html.escape(" · ".join(unnumbered_seats))}</div>')

    # 3b. parked zone — no seat, no cost, drift accruing visibly
    if parked:
        h.append('<div class="strip"><b>Parked</b> <span class="dim">(branch '
                 'kept, seat freed — deliberately marked)</span>')
        for p in parked:
            exp = (' <span style="color:#e05f4f;font-weight:700">PARKING '
                   'EXPIRED — re-cut on resume</span>' if p["expired"] else '')
            h.append(f'<div><b>{html.escape(p["branch"])}</b> — '
                     f'{html.escape(p["reason"])} &middot; parked {p["days"]}d '
                     f'&middot; behind {p["behind"]}{exp}</div>')
        h.append('</div>')

    # 4. cleanup footnote — names, not counts (a count isn't actionable)
    free_names = " · ".join(
        f'{l["folder"].removeprefix("worktrees/")}'
        + (f' ({l["branch"]})' if l["mismatch"] else '')
        for l in freeable) or "none"
    h.append(f'<div class="foot">cleanup: {len(merged)} merged branch(es) '
             f'deletable &middot; {len(backups)} backup branch(es) held for '
             f'review &middot; finished &amp; freeable: {html.escape(free_names)}</div>')

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
    # the one script (#133): clipboard only; hides every button when the API
    # is absent — quiet degrade, no dialogs
    h.append("""<script>
(function(){
  var btns=document.querySelectorAll('.copybtn');
  if(!navigator.clipboard){btns.forEach(function(b){
    if(!b.classList.contains('apprbtn'))b.style.display='none';});}
  btns.forEach(function(b){
    if(b.classList.contains('apprbtn')){
      b.addEventListener('click',function(ev){
        ev.preventDefault();
        if(!confirm('Approve #'+b.dataset.issue+'?'))return;
        b.disabled=true;b.textContent='approving…';
        var d=new URLSearchParams();d.set('issue',b.dataset.issue);d.set('nonce',b.dataset.nonce);
        fetch('/lanes-approve.php',{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},body:d.toString()})
        .then(function(r){return r.json()}).then(function(j){
          if(j&&j.ok){b.textContent='approved ✓';}
          else{b.disabled=false;b.textContent='Approve ✓';alert(j&&j.error?j.error:'failed');}
        }).catch(function(){b.disabled=false;b.textContent='Approve ✓';alert('failed');});
      });
      return;
    }
    b.addEventListener('click',function(ev){
      ev.preventDefault();
      navigator.clipboard.writeText(this.dataset.copy).then(function(){
        var t=b.textContent;b.textContent='copied';setTimeout(function(){b.textContent=t},1500);
      },function(){});
    });
  });
  var rf=document.getElementById('lg-refresh');
  if(rf){rf.addEventListener('click',function(){
    rf.disabled=true;rf.textContent='refreshing…';
    fetch('/lanes-refresh.php',{method:'POST'}).then(function(r){return r.json()})
    .then(function(j){if(j&&j.ok){setTimeout(function(){location.reload()},4000);}
      else{rf.disabled=false;rf.textContent='refresh';}})
    .catch(function(){rf.disabled=false;rf.textContent='refresh';});
  });}
})();
</script>""")
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
