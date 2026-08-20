#!/usr/bin/env python3
"""lanes-page — render `lanes --json` to a static page (handoff-3, approved 8/18).

Static file, no service: a systemd timer (platform/systemd/lanes-page.timer)
runs this as ubuntu every 5 minutes. Writes index.html AND lanes.json side by
side (Ian's addition: structured data over the same gated 443, no terminal
needed). The page prints its own generation time so a dead timer shows as an
old timestamp — staleness is visible, never a quiet lie.

SHAPE (Ian, 8/20 — #155 + #159 + #160). The page is a CHECKLIST first and a
dashboard second:

  Your list      one bullet per thing that waits on IAN, in plain words, with
                 the single action named and its button attached. Derived from
                 issue labels and the lanes' own `.lane-state` files — never
                 hand-maintained. Absent when nothing waits on him.
  Seats          ONE card per seat: issue title, chip, verbatim reason, git
                 numbers, buttons. Ian asked what the Building strip was versus
                 the table below it; the answer is that they never should have
                 been two things (#160).

FOUR CHIPS, and only four (#159, Ian's ruling — a six-state taxonomy was
considered and rejected: fewer states that are always true beat rich states
that are sometimes wrong, which is #151's law). working · needs you ·
needs keeper · retired, each followed by its VERBATIM reason where one exists.
Every needs-you chip is mirrored as a todo bullet with its action named.

The derivation lives in lanes-status.sh, next to the tmux and filesystem truth
it reads; this file renders and does not guess. The one thing it adds is the
label layer the shell cannot see: an issue wearing `merged` or `built` is
waiting on Ian, so its seat is upgraded to needs-you.

Testability (this is why the CLI args exist): the renderer must be checkable
without a network, without tmux, and WITHOUT WRITING TO /var/www — gate 77
feeds it fixtures and reads the HTML back.

  lanes-page.py [--json-file F] [--issues-file F] [--out DIR]
"""
import argparse
import datetime
import html
import json
import pathlib
import re
import subprocess

REPO = "/home/ubuntu/keeper-repo"
OUT = pathlib.Path("/var/www/dev/lanes")
API = "https://api.github.com/repos/iandavlin/loothplatformv2"

# A possessive must never be lower-cased into "the ians-todo-list thing".
# Named rather than inlined so the red-first harness can disable exactly this
# guard and prove the assertion that covers it is not decoration.
APOSTROPHES = ("'", "\u2019")

# The four chips. Nothing else may render as a chip on this page.
CHIPS = {
    "working":      ("● working",    "#9db668"),
    "needs-you":    ("needs you",    "#e0b64f"),
    "needs-keeper": ("needs keeper", "#7fa8d9"),
    "retired":      ("retired",      "#9aa3ad"),
}


def run(cmd):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=90).stdout


def _token():
    try:
        for line in open("/etc/looth/env"):
            if line.startswith("LG_GITHUB_ISSUES_TOKEN="):
                return line.split("=", 1)[1].strip()
    except OSError:
        pass
    return ""


def _gh(url):
    import urllib.request
    req = urllib.request.Request(
        url if url.startswith("http") else API + url,
        headers={"Authorization": f"Bearer {_token()}",
                 "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(req, timeout=20) as r:
        return json.loads(r.read())


def nonce(verb, subject, token=None):
    """Per-day HMAC, derived from the GitHub token server-side (#139). Only the
    digest ever reaches the browser; the token never does."""
    import hmac as _hmac, hashlib as _hl
    tok = _token() if token is None else token
    day = f"{datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d}"
    return _hmac.new(tok.encode(), f"{verb}:{subject}:{day}".encode(),
                     _hl.sha256).hexdigest()


def copy_btn(label, payload):
    """A clipboard button (#133): payload rides in an attribute-escaped
    data-copy; the page's one script does the rest. No fetch, no token."""
    return (f'<button class="copybtn" '
            f'data-copy="{html.escape(payload, quote=True)}">{label}</button>')


def poke_btn(seat):
    """#156: one tap tells keeper this seat looks idle. Same nonce discipline
    as Approve; the endpoint's whole vocabulary is "say so on the board"."""
    return (f'<button class="actbtn pokebtn" data-seat="{html.escape(seat, quote=True)}" '
            f'data-nonce="{nonce("poke", seat)}">Poke keeper</button>')


def gh_link(issue, label=None):
    return (f'<a href="{html.escape(issue["html_url"])}" target="_blank" '
            f'rel="noopener" class="ghlink">{label or "on GitHub &#8599;"}</a>')


def labels_of(issue):
    return {l["name"] for l in issue.get("labels", [])}


def plainize(title, limit=62):
    """Ian's format law reaches every word the page shows him: plain English,
    no jargon. Issue titles carry ledger numbers, SHOUTING and dated
    attributions — none of which is the thing he has to do. Conservative on
    purpose: strip the known noise, leave the words alone."""
    t = (title or "").strip()
    t = re.sub(r'^\d+(\.\d+)?\s*[—–-]\s*', '', t)          # "44 — " ledger prefix
    t = re.sub(r'\s*\((?:Ian|keeper|measured|surfaced|from)\b[^)]*\)?\s*$', '', t, flags=re.I)
    t = re.sub(r'^\[[^\]]*\]\s*', '', t)                    # "[hidden] "
    # An issue title is usually "<headline> — <elaboration>"; the elaboration is
    # the part he does not need in order to know what to do.
    t = re.split(r'\s+[—–]\s+', t, maxsplit=1)[0]
    # "<category>: <headline>" — keep the headline. Only when the prefix really
    # reads as a category (short); a long prefix IS the headline and is kept
    # whole, because guessing wrong here costs more than a few extra words.
    if ": " in t:
        head, _, tail = t.partition(": ")
        # The tail must start with a capital. A lowercase tail means the colon
        # introduced a CLAUSE, not a section — "Dual holders: cancel Stripe
        # while paying Patreon" loses its subject if the prefix is dropped, and
        # the bullet then starts mid-sentence.
        if len(head) <= 14 and len(tail) >= 8 and tail[:1].isupper():
            t = tail
    letters = [c for c in t if c.isalpha()]
    if letters and sum(1 for c in letters if c.isupper()) / len(letters) > 0.7:
        t = t[:1].upper() + t[1:].lower()                   # a title that SHOUTS
    t = t.rstrip(" …·-—★").strip()
    if len(t) > limit:
        cut = t[:limit].rsplit(" ", 1)[0]
        t = (cut or t[:limit]) + "…"
    return t or (title or "").strip()


def casual(title):
    """#164, Ian's sketched format: "Agent 1 — #148, the multiple-tiers thing".

    The "the …-… thing" form is applied ONLY where it cannot come out wrong:
    a short title whose words are ordinary ones. A possessive ("Ian's todo
    list") or an acronym ("SEO/sitemap") keeps its own capitals, because
    "the ian's-todo-list thing" is worse than no flourish at all — this is a
    tone, not a schema, and a tone is not worth a mangled proper noun."""
    t = plainize(title, 40)
    w = t.split()
    if (2 <= len(w) <= 3 and len(t) <= 34
            and not any(q in t for q in APOSTROPHES)
            and w[0][:1].isupper() and w[0][1:].islower()
            and all(x.islower() for x in w[1:])):
        return "the " + "-".join([w[0].lower()] + w[1:]) + " thing"
    return t


def agent_line(l, iss):
    """What this worker is doing, in one clause. Ian asked for the live verb
    where there is one and plain words where there isn't — an agent sitting at
    a prompt is not "parked", it is waiting for a specific person to do a
    specific thing, and that is what the line says."""
    if l.get("state") == "working":
        sb = spinner_bits(l.get("spinner", ""))
        if sb:
            return f"{sb[0]} {sb[1]}"
        return "working"
    ls, reason = l.get("lane_state", "none"), (l.get("reason") or "")
    if ls == "DONE":
        return "waiting for the keeper to merge"
    if ls == "QUESTION":
        return f"waiting on an answer: {reason[:90]}" if reason else "waiting on an answer"
    if ls == "BLOCKED":
        return f"blocked: {reason[:90]}" if reason else "blocked"
    if l.get("state") == "needs-you":
        return f"waiting on you{' — ' + reason[:90] if reason else ''}"
    return "sitting idle at its desk"


def spinner_bits(raw):
    """#160: 'Roosting… (22m 32s · ↓ 23.8k tokens)' -> ('Roosting…', '22m').
    The seconds are dropped once a bigger unit exists — Ian wants proof of life
    at a glance, and a clock that ticks every second reads as noise. Across two
    draws: same verb + a growing clock is a live turn, a frozen clock is a hang."""
    # search, not match: the CLI prints a rotating glyph ahead of the verb
    # ("✽ Roosting… (…"). The shell extractor strips it today, but a renderer
    # that silently drops the whole chip the day it doesn't is not worth the
    # one character this costs.
    m = re.search(r'([A-Za-z]+…?)\s*\(([^·]+)·', raw or "")
    if not m:
        return None
    verb, elapsed = m.group(1), m.group(2).strip()
    parts = elapsed.split()
    if len(parts) > 1 and any(p.endswith(("h", "m")) for p in parts[:-1]):
        elapsed = " ".join(parts[:-1])
    return verb, elapsed


def git_words(l):
    """Git numbers in Ian's language, not git's. Loud only when something is
    actually wrong — a quiet line is the whole point of the quiet-when-healthy
    rule, and 'unique 0 / behind 0' says nothing to a human."""
    bits = []
    u, b = l.get("unique"), l.get("behind")
    if u == 0:
        bits.append("nothing of its own yet")
    elif isinstance(u, int):
        bits.append(f"{u} commit{'s' if u != 1 else ''} of its own")
    if b == 0:
        bits.append("in step with main")
    elif isinstance(b, int):
        bits.append(f"{b} behind main")
    out = " &middot; ".join(html.escape(x) for x in bits)
    up = l.get("unpushed")
    if l.get("no_remote") and isinstance(u, int) and u > 0:
        out += (' &middot; <b style="color:#e05f4f">on this disk only — '
                'never pushed</b>')
    elif isinstance(up, int) and up > 0:
        out += (f' &middot; <b style="color:#e05f4f">{up} commit'
                f'{"s" if up != 1 else ""} not pushed yet</b>')
    return out


def fetch_issue_state(fixture=None):
    """Needs-you (plan-ready without approved, with embedded plan text) and all
    open issues (to match seats by branch number, and to read the `merged` /
    `built` labels the todo list is built from). Failure is LOUD on the page,
    never a silently empty section.

    A fixture short-circuits every network call — that is what lets gate 77
    assert the render rules offline (and under load, where a live API call is
    the flakiest thing on the page)."""
    if fixture is not None:
        f = json.loads(pathlib.Path(fixture).read_text())
        return (f.get("needs", []), f.get("investigating", []),
                f.get("allopen", []), f.get("ok", True))
    try:
        pr = _gh("/issues?labels=plan-ready&state=open&per_page=50")
        needs = [i for i in pr if "approved" not in labels_of(i)]
        investigating = _gh("/issues?labels=investigating&state=open&per_page=50")
        for i in needs:
            i["_plan"] = _plan_text(i) or i.get("body") or "(no plan text found)"
        allopen = _gh("/issues?state=open&per_page=100")
        return needs, investigating, allopen, True
    except Exception:
        return [], [], [], False


def _plan_text(issue):
    """The lane's plan comment — the one carrying 'Files I expect to touch'."""
    if issue.get("_plan"):
        return issue["_plan"]
    try:
        if issue.get("comments"):
            cs = _gh(issue["comments_url"])
            return next((c["body"] for c in reversed(cs)
                         if "Files I expect to touch" in c.get("body", "")), None)
    except Exception:
        pass
    return None


def days_since(iso):
    d = datetime.datetime.fromisoformat(iso.replace("Z", "+00:00"))
    return max(0, (datetime.datetime.now(datetime.timezone.utc) - d).days)


def build_todo(seats, needs, allopen, parked_reason=None):
    """#155, Ian's ask verbatim: 'plain-words bullets of what waits on HIM —
    phone checks to run, flips to say GO on, questions owed a sentence'. One
    bullet, one action, derived from state and never hand-maintained.

    Four families, ordered by what they unblock: a lane stopped dead asking him
    a question, then a lane that cannot start without his GO, then a finished
    thing one flip from members, then a merged thing awaiting his eyes."""
    todo, seen = [], set()
    parked_reason = parked_reason or {}

    def because(num):
        """#159's ruling: the VERBATIM park reason where one exists. A lane that
        wrote 'merged as X, awaiting phone check' has already said the true
        thing better than any wording derived from a label could."""
        r = parked_reason.get(str(num))
        return (f' <span class="dim">&mdash; the lane said: &ldquo;'
                f'{html.escape(r[:160])}&rdquo;</span>') if r else ''

    # 1. a lane raised its hand and named him (its chip reads 'needs you', and
    #    #159's ruling says every one of those is mirrored here with its action)
    for l in seats:
        if l.get("state") != "needs-you" or not l.get("reason"):
            continue
        seat = l["branch"]
        todo.append({
            "icon": "💬",
            "text": (f'<b>Answer {html.escape(seat)}</b> — it asked: '
                     f'&ldquo;{html.escape(l["reason"][:200])}&rdquo;'),
            "buttons": copy_btn("Copy for keeper",
                                f'Re {seat}: answering its question — '),
        })

    # 2. a plan is ready and only his GO is missing
    for i in needs:
        seen.add(i["number"])
        d = days_since(i["updated_at"])
        waited = ("waiting since this morning" if d == 0
                  else f"waiting {d} day{'s' if d != 1 else ''}")
        plan = i.get("_plan") or "(no plan text found)"
        todo.append({
            "icon": "✅",
            "text": (f'<b>Say GO on {html.escape(plainize(i["title"]))}</b> — '
                     f'the plan is ready, {waited}.'),
            "detail": plan[:6000],
            "buttons": (
                f'<button class="actbtn apprbtn" data-issue="{i["number"]}" '
                f'data-nonce="{nonce("approve", i["number"])}">Approve ✓</button>'
                + gh_link(i) + copy_btn("Copy plan", plan[:6000])),
        })

    # 3 + 4. built = one flip from members seeing it; merged = awaiting his look
    for i in allopen:
        if i["number"] in seen:
            continue
        lab = labels_of(i)
        if "built" in lab:
            seen.add(i["number"])
            todo.append({
                "icon": "🎚",
                "text": (f'<b>Say GO to switch on '
                         f'{html.escape(plainize(i["title"]))}</b> — built and '
                         f'merged; one flag flip from members seeing it.'
                         + because(i["number"])),
                "buttons": gh_link(i),
            })
        elif "merged" in lab:
            seen.add(i["number"])
            todo.append({
                "icon": "📱",
                "text": (f'<b>Try {html.escape(plainize(i["title"]))}</b> — '
                         f'it&rsquo;s merged; your look is the last thing left.'
                         + because(i["number"])),
                "buttons": gh_link(i),
            })
    return todo


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--json-file", help="read lanes JSON from a file instead of running `lanes --json`")
    ap.add_argument("--issues-file", help="read GitHub state from a fixture instead of the API")
    ap.add_argument("--out", help="write index.html + lanes.json here (default /var/www/dev/lanes)")
    args = ap.parse_args()
    out_dir = pathlib.Path(args.out) if args.out else OUT

    if args.json_file:
        data = json.loads(pathlib.Path(args.json_file).read_text())
    else:
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
    seats = [l for l in rows if l["status"] != "done"]   # finished seats leave
    at_risk = [l for l in rows if l["status"] == "at-risk"]
    cap = data.get("capacity", {})
    unb = data.get("unbacked", {})
    collisions = data.get("collisions", [])
    parked = data.get("parked", [])

    needs, investigating, allopen, gh_ok = fetch_issue_state(args.issues_file)
    issue_by_num = {str(i["number"]): i for i in allopen}

    def issue_for(l):
        n = l["branch"].split("-")[0]
        return issue_by_num.get(n) if n.isdigit() else None

    # ⚠ #151, lie three: the seat map is built from EVERY seat, not from the
    # post-filter table. Building it from the filtered list is what made an
    # approved, running lane print as APPROVED, NOT STARTED — the seat existed
    # the whole time; it had merely been filtered out one line earlier.
    seat_nums = {l["branch"].split("-")[0]: l["branch"] for l in rows
                 if l["branch"].split("-")[0].isdigit()}
    # ⚠ AND a RIDER has a seat — it is being worked at somebody else's desk.
    # Without this, batching four issues onto one seat printed all four as
    # APPROVED, NOT STARTED while the lane was actively building them: the same
    # lie as #151, arriving through the rider mechanism instead of a fresh branch.
    for l in rows:
        for r in l.get("riders", []):
            seat_nums.setdefault(str(r), l["branch"])
    # ...and a PARKED branch is work that started and was set down. "Nothing
    # started" is a claim about history, and the branch is the history.
    for p_ in parked:
        n = p_["branch"].split("-")[0]
        if n.isdigit():
            seat_nums.setdefault(n, p_["branch"])

    # The label layer the shell cannot see: an issue wearing `merged` or `built`
    # is waiting on Ian, so its seat says so. Never over a live worker —
    # something IS happening there, and that outranks a stale label.
    for l in seats:
        iss = issue_for(l)
        if iss and l.get("state") != "working" and labels_of(iss) & {"merged", "built"}:
            l["state"] = "needs-you"
            if not l.get("reason"):
                l["reason"] = ("merged — waiting on your check"
                               if "merged" in labels_of(iss)
                               else "built — waiting on your GO to switch it on")

    now = datetime.datetime.now()
    h = []
    h.append("""<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>lanes</title><style>
body{background:#14161a;color:#e8e6df;font:15px/1.5 system-ui,sans-serif;margin:0;padding:16px;}
.wrap{max-width:760px;margin:0 auto;}
h1{font-size:18px;margin:0 0 14px;}
h2{font-size:13px;color:#9aa3ad;text-transform:uppercase;letter-spacing:.06em;margin:22px 0 8px;}
.block{border-radius:10px;padding:12px 14px;margin-bottom:14px;}
.gap{background:#3a2420;border:1px solid #7a4438;}
.risk{background:#4a1f18;border:2px solid #e05f4f;font-size:17px;font-weight:700;}
.chip{display:inline-block;border-radius:20px;padding:1px 10px;font-size:12px;font-weight:700;color:#14161a;}
.mm{color:#e0b64f;font-weight:700;}
.dim{color:#9aa3ad;font-size:13px;}
.foot{color:#9aa3ad;font-size:12px;margin-top:18px;}
.strip{border-top:1px solid #2a2f38;margin-top:16px;padding-top:10px;}
.strip div{font-size:13px;color:#9aa3ad;padding:2px 0;}
.strip b{color:#e8e6df;font-weight:600;}
.copybtn,.actbtn,.rfbtn{background:#2d323b;color:#9db668;border:1px solid #3a4049;border-radius:6px;
  padding:2px 10px;font-size:12px;font-weight:700;cursor:pointer;margin-left:8px;}
.actbtn{background:#2a3a20;}
.ghlink{color:#9db668;font-weight:700;font-size:12px;margin-left:10px;}
.todo{background:#1b1f26;border:1px solid #2a2f38;border-left:3px solid #e0b64f;
  border-radius:8px;padding:10px 12px;margin-bottom:8px;}
.todo .ic{font-size:16px;margin-right:6px;}
.todo .acts{margin-top:6px;}
.todo summary{cursor:pointer;color:#9aa3ad;font-size:12.5px;margin-top:6px;}
.todo .plan{white-space:pre-wrap;font-size:13px;margin:8px 0;color:#c8ccd2;}
.card{background:#1b1f26;border:1px solid #2a2f38;border-radius:8px;
  padding:10px 12px;margin-bottom:8px;}
.card .ttl{font-size:15px;font-weight:600;}
.card .meta{color:#9aa3ad;font-size:12.5px;margin-top:4px;}
.card .acts{margin-top:6px;}
.card summary{cursor:pointer;color:#9aa3ad;font-size:12.5px;margin-top:4px;}
.why{color:#e8e6df;font-size:13px;}
.agent{background:#1b1f26;border:1px solid #2a2f38;border-radius:8px;
  padding:8px 12px;margin-bottom:6px;font-size:14px;}
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

    # 3. YOUR LIST (#155) — the checklist, not a dashboard. Quiet when healthy:
    # absent entirely when nothing waits on him. A GitHub read that FAILED is
    # loud instead, because "nothing waits on you" and "I could not look" must
    # never render the same (the one inversion of the quiet rule).
    if not gh_ok:
        h.append('<div class="block gap">GitHub unreadable — what waits on you '
                 'is UNKNOWN right now, not necessarily nothing</div>')
    parked_reason = {p["branch"].split("-")[0]: p["reason"] for p in parked
                     if p["branch"].split("-")[0].isdigit() and p.get("reason")}
    todo = build_todo(seats, needs, allopen, parked_reason)
    if todo:
        h.append('<h2>Your list</h2>')
        for t in todo:
            h.append(f'<div class="todo"><span class="ic">{t["icon"]}</span>'
                     f'<span class="why">{t["text"]}</span>')
            if t.get("detail"):
                h.append(f'<details><summary>read the plan</summary>'
                         f'<div class="plan">{html.escape(t["detail"])}</div>'
                         f'</details>')
            h.append(f'<div class="acts">{t["buttons"]}</div></div>')

    # 3a. AGENTS (#164) — the WORKERS view. Ian: "I guess we need an agent
    # section and what they are working like - agent 1 - 146 edit button thing."
    # Deliberately NOT a second description of the desks (which is the thing
    # #160 fixed): no seats, no branches, no git numbers here. A row exists for
    # every agent that is ALIVE — a session at a prompt is still a worker, and
    # saying what it waits for is the whole point. Absent when nobody is home.
    live_agents = [l for l in seats if l.get("agent", "none") != "none"]
    if live_agents:
        h.append('<h2>Agents</h2>')
        for n, l in enumerate(live_agents, 1):
            iss = issue_for(l)
            what = (f'<a href="{html.escape(iss["html_url"])}" target="_blank" '
                    f'rel="noopener" style="color:#7fa8d9">#{iss["number"]}</a>, '
                    f'{html.escape(casual(iss["title"]))}' if iss
                    else f'{html.escape(l["branch"])}')
            doing = html.escape(agent_line(l, iss))
            colour = "#9db668" if l.get("state") == "working" else "#9aa3ad"
            h.append(f'<div class="agent"><b>Agent {n}</b> &mdash; {what} '
                     f'&mdash; <span style="color:{colour}">{doing}</span></div>')

    # 3b. in motion (no seat) — active investigations (#137): keeper working,
    # nothing needed from Ian yet. Explicitly labeled only; absent when none.
    if investigating:
        h.append('<div class="strip"><b>In motion (no seat)</b> <span '
                 'class="dim">— investigations keeper is actively working</span>')
        for i in investigating:
            hrs = max(0, int((datetime.datetime.now(datetime.timezone.utc)
                              - datetime.datetime.fromisoformat(
                                  i["created_at"].replace("Z", "+00:00")))
                             .total_seconds() // 3600))
            age = f"{hrs}h" if hrs < 48 else f"{hrs // 24}d"
            h.append(f'<div><a href="{html.escape(i["html_url"])}" '
                     f'target="_blank" rel="noopener" style="color:#c9a0dc">'
                     f'#{i["number"]} {html.escape(plainize(i["title"], 70))}</a> '
                     f'<span class="dim">· running {age}</span></div>')
        h.append('</div>')

    # 4. SEATS — one card each (#160). The Building strip and the seats table
    # were the same seats described twice; Ian asked what the difference was,
    # and the honest answer was "nothing", so they are one card now.
    desks = [l for l in seats if not issue_for(l)]        # no open issue -> old desk
    cards = [l for l in seats if issue_for(l)]
    if cards:
        h.append('<h2>Seats</h2>')
    for l in cards:
        iss = issue_for(l)
        state = l.get("state", "needs-keeper")
        label, color = CHIPS.get(state, CHIPS["needs-keeper"])
        if state == "working":
            sb = spinner_bits(l.get("spinner", ""))
            if sb:
                label = f"{label} &middot; {html.escape(sb[0])} {html.escape(sb[1])}"
        h.append('<div class="card">')
        h.append(f'<div class="ttl"><a href="{html.escape(iss["html_url"])}" '
                 f'target="_blank" rel="noopener" style="color:#7fa8d9">'
                 f'#{iss["number"]}</a> {html.escape(plainize(iss["title"], 70))}</div>')
        h.append(f'<div style="margin-top:6px"><span class="chip" '
                 f'style="background:{color}">{label}</span>')
        if l.get("reason"):
            h.append(f' <span class="why">{html.escape(l["reason"][:200])}</span>')
        h.append('</div>')
        meta = f'seat {html.escape(l["branch"])} &middot; {git_words(l)}'
        if l.get("mismatch"):
            meta += (f'<br><span class="mm">&ne; the folder is named '
                     f'{html.escape(l["folder"].removeprefix("worktrees/"))}, '
                     f'the branch is {html.escape(l["branch"])}</span>')
        if l.get("riders"):
            meta += ('<br>riders: ' + " ".join(f'#{r}' for r in l["riders"]))
        h.append(f'<div class="meta">{meta}</div>')
        plan = _plan_text(iss) if not args.issues_file else iss.get("_plan")
        if plan:
            h.append(f'<details><summary>plan</summary><div class="plan">'
                     f'{html.escape(plan[:5000])}</div></details>')
        where = "/home/ubuntu/{}  (branch {})".format(l["folder"], l["branch"])
        h.append(f'<div class="acts">{poke_btn(l["branch"])}'
                 f'{copy_btn("copy path+branch", where)}</div>')
        h.append('</div>')

    # 4b. old desks — a seat whose branch answers to no open issue. Ian asked
    # for these grouped rather than mixed in with the work (#160).
    if desks:
        h.append('<h2>Old desks</h2>')
        for l in desks:
            state = l.get("state", "needs-keeper")
            label, color = CHIPS.get(state, CHIPS["needs-keeper"])
            h.append(f'<div class="card"><div class="ttl">'
                     f'{html.escape(l["branch"])}</div>'
                     f'<div style="margin-top:6px"><span class="chip" '
                     f'style="background:{color}">{label}</span>'
                     + (f' <span class="why">{html.escape(l["reason"][:200])}</span>'
                        if l.get("reason") else '')
                     + f'</div><div class="meta">{git_words(l)}</div>'
                     f'<div class="acts">{poke_btn(l["branch"])}</div></div>')

    # 5. reconciliation — absent when clean. Approved-with-no-seat is the one
    # that matters: "I said go and nothing started" must never look identical
    # to work in progress. (#151: and it must never fire for a seat that EXISTS.)
    if gh_ok:
        for i in allopen:
            lab = labels_of(i)
            if ("approved" in lab and "merged" not in lab
                    and str(i["number"]) not in seat_nums):
                h.append(f'<div class="block risk">APPROVED, NOT STARTED — '
                         f'<a href="{html.escape(i["html_url"])}" '
                         f'target="_blank" rel="noopener" '
                         f'style="color:#e8e6df">#{i["number"]} '
                         f'{html.escape(plainize(i["title"], 70))}</a></div>')

    # 6. parked branches — no seat, no cost, drift accruing visibly. Same four
    # chips as a seat (#159): a parked branch whose issue is waiting on Ian says
    # needs you; everything else deliberately set down says retired.
    if parked:
        h.append('<h2>Parked — branch kept, seat freed</h2>')
        for p in parked:
            n = p["branch"].split("-")[0]
            iss = issue_by_num.get(n) if n.isdigit() else None
            state = ("needs-you" if iss and labels_of(iss) & {"merged", "built"}
                     else "retired")
            label, color = CHIPS[state]
            exp = (' <span style="color:#e05f4f;font-weight:700">PARKING '
                   'EXPIRED — re-cut on resume</span>' if p["expired"] else '')
            h.append(f'<div class="card"><div class="ttl">'
                     f'{html.escape(p["branch"])}</div>'
                     f'<div style="margin-top:6px"><span class="chip" '
                     f'style="background:{color}">{label}</span> '
                     f'<span class="why">{html.escape(p["reason"])}</span></div>'
                     f'<div class="meta">parked {p["days"]}d &middot; '
                     f'{p["behind"]} behind main{exp}</div></div>')

    # 7. cleanup footnote — names, not counts (a count isn't actionable)
    free_names = " · ".join(
        f'{l["folder"].removeprefix("worktrees/")}'
        + (f' ({l["branch"]})' if l["mismatch"] else '')
        for l in freeable) or "none"
    h.append(f'<div class="foot">cleanup: {len(merged)} merged branch(es) '
             f'deletable &middot; {len(backups)} backup branch(es) held for '
             f'review &middot; finished &amp; freeable: {html.escape(free_names)}</div>')

    # 8. shipped, last 7 days — self-clearing; where an unflipped flag shows up
    if shipped:
        h.append('<div class="strip"><b>Landed on main, last 7 days</b>')
        for line in shipped:
            d, _, s = line.partition("|")
            h.append(f'<div><b>{html.escape(d)}</b> {html.escape(s[:100])}</div>')
        h.append('</div>')

    h.append(f'<div class="foot">generated {now.strftime("%H:%M")} &middot; '
             f'redraws every 5 minutes — an old timestamp means the timer is '
             f'dead, not that all is well</div>')
    # the one script: clipboard (#133) + the two one-verb servants (#139, #156).
    # Copy buttons hide when the clipboard API is absent — quiet degrade, no
    # dialogs. Action buttons never hide: they do not need the clipboard.
    h.append("""<script>
(function(){
  function post(url,d,btn,busy,done){
    var was=btn.textContent;btn.disabled=true;btn.textContent=busy;
    var b=new URLSearchParams();for(var k in d)b.set(k,d[k]);
    fetch(url,{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()})
    .then(function(r){return r.json()}).then(function(j){
      if(j&&j.ok){btn.textContent=done;}
      else{btn.disabled=false;btn.textContent=was;alert(j&&j.error?j.error:'failed');}
    }).catch(function(){btn.disabled=false;btn.textContent=was;alert('failed');});
  }
  if(!navigator.clipboard){
    document.querySelectorAll('.copybtn').forEach(function(b){b.style.display='none';});}
  document.querySelectorAll('.copybtn').forEach(function(b){
    b.addEventListener('click',function(ev){ev.preventDefault();
      navigator.clipboard.writeText(b.dataset.copy).then(function(){
        var t=b.textContent;b.textContent='copied';setTimeout(function(){b.textContent=t},1500);
      },function(){});});});
  document.querySelectorAll('.apprbtn').forEach(function(b){
    b.addEventListener('click',function(ev){ev.preventDefault();
      if(!confirm('Approve #'+b.dataset.issue+'?'))return;
      post('/lanes-approve.php',{issue:b.dataset.issue,nonce:b.dataset.nonce},
           b,'approving…','approved ✓');});});
  document.querySelectorAll('.pokebtn').forEach(function(b){
    b.addEventListener('click',function(ev){ev.preventDefault();
      post('/lanes-poke.php',{seat:b.dataset.seat,nonce:b.dataset.nonce},
           b,'poking…','keeper told ✓');});});
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

    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "index.html").write_text("".join(h), encoding="utf-8")
    data["generated"] = now.isoformat(timespec="seconds")
    data["cleanup"] = {"merged_deletable": len(merged),
                       "backups_held": len(backups),
                       "seats_freeable": len(freeable)}
    data["todo"] = [re.sub("<[^>]+>", "", t["text"]) for t in todo]
    (out_dir / "lanes.json").write_text(json.dumps(data, indent=1), encoding="utf-8")


if __name__ == "__main__":
    main()
