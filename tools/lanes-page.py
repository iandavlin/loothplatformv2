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

#172 — EVERY ITEM IS AN ACTION WITH A DOOR, AND THE PAGE IS A SNAPSHOT. Ian on
8/20, looking at the shipped list: "the list for me isn't super useful. Can we
get links and copy and paste so I can talk to you about them?… This is hard for
me to parse or get started with." And minutes later: "I'd also like to collapse
the sections into accordions so I can get a snapshot easier." Measured before
building: 12 bullets, 11 of them the word "Try" followed by a raw issue title,
one control on each (on GitHub), no door and no way to reply.

So a bullet now leads with a plain-words ACTION, carries a Do-it link to the
dev2 URL where that action happens, prints the one-word replies he can send
back, and hands the whole thing to the clipboard ready to paste at keeper. The
door and the words come from TEST-URL / ACTION records — see the block above
build_todo, which is the one place that convention is defined. And every
section is a details/summary whose closed line is name + live count, so the
default view of this page is now the snapshot he asked for.

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
import os
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


DECISIONS = pathlib.Path(os.path.expanduser("~/.lg-decisions"))


def pending_decisions(store=None):
    """#202 — how many decision boxes are waiting on Ian right now.

    Returns (count, ok). `ok` is False when the store could not be read AT ALL
    or when a file in it would not parse — and that distinction is the whole
    reason this returns a flag instead of just a number.

    ⚠ THE COUNT IS THE ONLY THING THIS PAGE BAKES. The questions themselves and
    their nonces are fetched live by the browser from lanes-decisions.php, so a
    box opened at 14:03 shows what keeper is asking at 14:03 and not what it was
    asking at the last five-minute redraw — and a question Ian already answered
    in chat is gone from it. Baking them would make the page a snapshot of a
    conversation, which is the one thing a decision box must never be.

    ⚠ AN UNREADABLE STORE IS NOT AN EMPTY ONE. This page's oldest law, and the
    reason `ok` exists: "nothing waits on you" and "I could not look" must never
    render alike. A missing store means the deploy step was never run, which is
    exactly the state the poke button sat in for two days while telling Ian it
    had told keeper.

    The CLAIM FILE OUTRANKS THE JSON, as it does in both endpoints: the claim is
    written first and the rewrite can be lost, so a question with a claim has
    been answered even if its own body has not caught up.
    """
    store = pathlib.Path(store) if store else DECISIONS
    try:
        names = sorted(store.glob("*.json"))
    except OSError:
        return 0, False
    if not store.is_dir():
        return 0, False
    n = 0
    for f in names:
        i = f.name[:-5]
        if not re.match(r"^[0-9a-z][0-9a-z-]{2,39}$", i) or ".." in i:
            return n, False
        try:
            q = json.loads(f.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return n, False
        if q.get("answered"):
            continue
        if (store / (i + ".claim")).exists():
            continue
        n += 1
    return n, True


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


def plural(n, word, many=None):
    return f"{n} {word if n == 1 else (many or word + 's')}"


def acc(h, sid, title, count_words, open_=False):
    """#172, Ian's second ask verbatim: "I'd also like to collapse the sections
    into accordions so I can get a snapshot easier." details/summary, no
    framework — the same pattern the plan text already uses on this page.

    ⚠ THE LOUD LAYER IS NEVER INSIDE ONE. A collapsed AT RISK is a hidden AT
    RISK, and this page's oldest rule is that silence may only ever mean
    healthy. The deploy gap, AT RISK, UNBACKED, COLLISION, the
    GitHub-unreadable banner and APPROVED-NOT-STARTED therefore render outside
    every accordion, and gate 77 asserts that structurally rather than by eye.

    Default collapsed, except Your list — his list is the reason the page
    exists, so it opens. The remembered state overrides the server-rendered
    default in the browser rather than replacing it, so a section he keeps open
    does not flash shut on every redraw.

    The <h2> stays INSIDE the summary: it is still the section's heading, a
    summary may legally carry heading content, and every assertion that already
    reads this page by its headings keeps working."""
    h.append(f'<details class="acc" data-acc="{html.escape(sid, quote=True)}"'
             f'{" open" if open_ else ""}><summary><h2>{title}</h2>'
             f'<span class="acccount">{html.escape(count_words)}</span>'
             f'</summary><div class="accbody">')


def acc_end(h):
    h.append('</div></details>')


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


# ─────────────────────────────────────────────────────────────────────────────
# TEST-URL / ACTION records — the door and the words on every bullet (#172)
# ─────────────────────────────────────────────────────────────────────────────
# ⚠ THE CONVENTION IS DEFINED HERE AND NOWHERE ELSE. It did not exist before
# #172: a grep of every branch and of every commit body on main returned zero
# hits. Ian, 8/20, looking at the shipped list: "the list for me isn't super
# useful. Can we get links and copy and paste so I can talk to you about them?…
# This is hard for me to parse or get started with."
#
# A RECORD is one line, written by a lane or by keeper in the ordinary course of
# work. Two keys, both optional:
#
#     TEST-URL: /lgjoin/                the dev2 door where the thing happens
#     TEST-URL #148: /lgjoin/           explicit — for a batched merge or a rider
#     ACTION #148: Look at the join page — three tiers and their prices
#
# READ FROM, first hit wins, newest first inside each source:
#   1. the issue's own COMMENTS, then its BODY — live, so a correction reaches
#      him on the next 5-minute redraw with nothing to merge;
#   2. COMMIT BODIES ON MAIN — deliberately NOT --first-parent, so a LANE can
#      write the record in its own commit at build time and not only keeper at
#      merge time. Attributed by, in order: an explicit #n; the first #n in the
#      commit SUBJECT ("merge #170: …"); a leading number ("170: close — …").
#      The SUBJECT is never scanned for records, only for that number: a subject
#      is one line of prose and "172: add TEST-URL: parsing" is not a record.
#   3. the PARK REASON — the lane's own last words, already on this page.
#
# DERIVED, NEVER HAND-MAINTAINED: there is no map of issue numbers in this file
# and there must never be one. The four already-live doors were seeded as
# records in a commit body, which is the same door every future record uses.
#
# NO RECORD IS NOT A GITHUB LINK (Ian's spec, point 2). The card says "no test
# link yet"; substituting the issue link would answer a question he did not ask
# with the very thing he said was not useful. And a source that FAILED TO READ
# says so — "there isn't one" and "I could not look" must never render alike,
# which is this page's oldest law.
#
# PROSE CANNOT COUNTERFEIT A RECORD, and that is the validator's job rather than
# the regex's (feedback-red-first-that-stays-green: an assertion matching a
# string that also lives in prose is the commonest way this goes wrong). A line
# reading "TEST-URL: the convention" yields the value "the", which is not a
# reachable path, so it is dropped as if it had never been written.

RECORD_RE = re.compile(
    r'^[\s>*\-]*(TEST-URL|ACTION)\s*(?:#(\d+))?\s*:\s*(\S.*?)\s*$',
    re.M | re.I)

# ⚠ THE PARK REASON IS ONE LINE, so the line-start rule above would make that
# source unusable: a lane writes "merged as c879589, dev2 flag ON pending Ian's
# join-page look" and has nowhere to put a record. This variant lets the record
# ride at the END of such a line — and it is used for the park reason ONLY.
#
# The two rules differ because their SOURCES differ, not for convenience. A
# commit body and an issue comment are long prose that discusses this convention
# as often as it uses it, so there a record must own its whole line. A park
# reason is one short deliberate sentence a lane writes about its own work; the
# same strictness there would buy nothing and cost the source entirely.
RECORD_TAIL_RE = re.compile(
    r'(?:^|[\s;,(\[])(TEST-URL|ACTION)\s*(?:#(\d+))?\s*:\s*(\S.*?)\s*$',
    re.M | re.I)

# The href allow-list. A record arrives from an issue comment or a commit body
# and lands in an anchor, so it is UNTRUSTED INPUT: same-site paths and our own
# hosts only. javascript:, data:, protocol-relative //evil and any third-party
# host are dropped exactly as a malformed line is — the card then honestly says
# there is no door rather than offering a poisoned one.
SAFE_HOSTS = ("dev2.loothgroup.com", "loothgroup.com", "www.loothgroup.com")


def safe_url(u):
    u = (u or "").strip()
    if len(u) > 300 or any(c in u for c in ' "\'<>\\'):
        return None
    if u.startswith("//"):
        return None
    if u.startswith("/"):
        return u
    m = re.match(r'^https://([A-Za-z0-9.\-]+)(/.*)?$', u)
    if m and m.group(1).lower() in SAFE_HOSTS:
        return u
    return None


def _record_lines(text, default_issue=None, tail=False):
    """Every record in one blob of text, as (issue, key, value) triples.

    `tail=True` is the park-reason form — see RECORD_TAIL_RE."""
    out = []
    for m in (RECORD_TAIL_RE if tail else RECORD_RE).finditer(text or ""):
        key = m.group(1).upper()
        num = m.group(2) or (str(default_issue) if default_issue else None)
        if not num:
            continue                       # unattributable — silently useless
        val = m.group(3).strip()
        if key == "TEST-URL":
            # ⚠ THE WHOLE REMAINDER IS THE VALUE, never its first token, and
            # safe_url() rejects anything containing a space. So a record is a
            # STRUCTURED LINE and nothing else: "TEST-URL: /lgjoin/" is one,
            # "TEST-URL: /lgjoin/ — try it signed out" is not. This commit's own
            # parent is the proof it is needed — its message quotes an example
            # record inline, and a first-token reading would have handed #172 a
            # door pointing at the join page. Prose that mentions the convention
            # must stay inert (feedback-red-first-that-stays-green).
            # Trailing SENTENCE punctuation only. ')' and '>' were in this
            # set and mangled a legitimate value — a record reading
            # "TEST-URL: javascript:alert(1)" arrived as "javascript:alert(1",
            # which is still a poisoned href and merely one the assertion
            # against it could no longer recognise. Anything '>' could have
            # caught, safe_url rejects outright.
            val = safe_url(val.rstrip('.,;'))
        else:
            val = re.sub(r"\s+", " ", val)[:120].rstrip(" .")
            if len(val) < 6:
                val = None                 # too short to be an action sentence
        if val:
            out.append((num, key, val))
    return out


def _absorb(records, triples):
    """First writer wins. Call order IS the precedence order."""
    for num, key, val in triples:
        records.setdefault(num, {}).setdefault(key, val)


def _subject_issue(subject):
    """Which issue a commit is about, from its subject alone."""
    m = re.search(r'#(\d+)', subject or "")
    if m:
        return m.group(1)
    m = re.match(r'\s*(\d+)\s*[:.]', subject or "")
    return m.group(1) if m else None


def commit_records(repo, days=45, ref="main"):
    """Records in commit bodies on main. NOT --first-parent — see the note above.
    A repo that cannot be read yields nothing, which the card reports honestly.

    `ref` exists for ONE reason: a lane's seed lives on its own branch until the
    merge, and a worktree's `main` is keeper's main, not the lane's. Without a
    way to name the branch, a lane could only verify its own records AFTER they
    were already in front of Ian. Default stays `main` — the serving renderer
    never passes this."""
    out = []
    try:
        txt = run(["git", "-C", str(repo), "log", ref,
                   f"--since={days} days ago", "--format=%x1e%s%x00%b"])
    except Exception:
        return out
    for chunk in txt.split("\x1e"):
        if not chunk.strip():
            continue
        subj, _, body = chunk.partition("\x00")
        out.extend(_record_lines(body, _subject_issue(subj)))
    return out


def park_records(parked):
    out = []
    for p in parked or []:
        n = p.get("branch", "").split("-")[0]
        if n.isdigit():
            out.extend(_record_lines(p.get("reason") or "", n, tail=True))
    return out


def gather_records(issues, parked, repo, live=True, ref="main"):
    """(records, ok). ok is False when a source READ FAILED, which is the one
    thing a missing door must never be confused with."""
    records, ok = {}, True
    for i in issues:
        texts = []
        if live and i.get("comments"):
            try:
                for c in reversed(_gh(i["comments_url"])):
                    texts.append(c.get("body") or "")
            except Exception:
                ok = False
        else:
            texts.extend(reversed(i.get("_comments") or []))
        texts.append(i.get("body") or "")
        for t in texts:
            _absorb(records, _record_lines(t, i["number"]))
    _absorb(records, commit_records(repo, ref=ref))
    _absorb(records, park_records(parked))
    return records, ok


def gh_fine(issue):
    """#172, spec point 5: `on GitHub` is DEMOTED to fine print. It was the only
    control on every bullet, and it is the one place he said he did not want to
    have to go — the door and the copy button are the controls now."""
    return (f'<a href="{html.escape(issue["html_url"])}" target="_blank" '
            f'rel="noopener" class="ghfine">#{issue["number"]} on GitHub '
            f'&#8599;</a>')


def door_html(rec, records_ok, quiet_when_absent=False):
    """The Do-it link (#172, spec point 2), or the honest absence of one.

    `quiet_when_absent` is for the question family: the answer to "what should
    the second tier cost?" is a sentence, and telling him to go ask keeper for a
    test link — when keeper is the very party he is answering — is noise on the
    one card that is already a conversation."""
    u = rec.get("TEST-URL")
    if u:
        return (f'<a class="dobtn" href="{html.escape(u, quote=True)}" '
                f'target="_blank" rel="noopener">Do it &#8599;</a>'
                f'<span class="doorpath">{html.escape(u)}</span>')
    if not records_ok:
        # The one inversion of quiet-when-healthy, applied to the door: a read
        # that failed must never render as an answer. Loud even on a question
        # card, because "I could not look" is never noise.
        return ('<span class="nodoor">test link unknown &mdash; a GitHub read '
                'failed, so this is not &ldquo;there isn&rsquo;t one&rdquo;</span>')
    if quiet_when_absent:
        return ''
    return ('<span class="nodoor">no test link yet &mdash; ask keeper for '
            'one</span>')


# The suggested one-word replies (#172, spec point 4 — "the chat-list voice Ian
# liked"). Derived from the FAMILY and the issue number, never from the words of
# the issue: a reply built from a number is always true, and a reply guessed
# from a title is only usually true.
def replies_for(family, n):
    if family == "flip":
        return [f"GO on {n}", f"hold {n}"]
    if family == "look":
        return [f"{n} good", f"{n} not right"]
    return [f"GO on {n}", "not yet"]


def says_html(reps):
    return ('<div class="says">say: ' + ' &middot; '.join(
        f'&ldquo;{html.escape(r)}&rdquo;' for r in reps) + '</div>')


def action_for(family, issue, rec):
    """The plain-words ACTION every card leads with (#172, spec point 1).

    An `ACTION:` record wins. Failing that the FAMILY supplies a real verb and
    the plainised title becomes its object, so an un-recorded card still leads
    with something to do rather than with a title. What it must never be is the
    bare title, which is what Ian was looking at when he said the list was hard
    to get started with."""
    if rec.get("ACTION"):
        return rec["ACTION"]
    what = plainize(issue["title"])
    # ⚠ THE INSTRUCTION IS COMPLETE BEFORE THE DASH, and the title is a LABEL
    # after it. The first cut wrote "Look at " + the title, which reads
    # "Look at Checkout is Patreon-blind: a live Patreon member can…" — the verb
    # swallowing a sentence-shaped title, which is Ian's original complaint
    # wearing a verb. Seen by looking at the rendered page, not by reading the
    # code. This form cannot come out ungrammatical whatever the title is,
    # because the title is never the object of the verb.
    if family == "flip":
        return f"Say GO to switch it on — {what}"
    if family == "look":
        return f"Take a look — {what}"
    return f"Say GO on the plan — {what}"


def copy_payload(n, action, reps):
    """#172, spec point 3, verbatim: 'Re #<n> <plain name> — ' plus the card's
    suggested replies, ready to paste into keeper chat with the wrong one
    deleted."""
    tail = " / ".join(reps)
    return f"Re #{n} {action} — " + (f"[{tail}]" if tail else "")


def build_todo(seats, needs, allopen, parked_reason=None,
               records=None, records_ok=True):
    """#155, Ian's ask verbatim: 'plain-words bullets of what waits on HIM —
    phone checks to run, flips to say GO on, questions owed a sentence'. One
    bullet, one action, derived from state and never hand-maintained.

    Four families, ordered by what they unblock: a lane stopped dead asking him
    a question, then a lane that cannot start without his GO, then a finished
    thing one flip from members, then a merged thing awaiting his eyes.

    #172 gave every one of them an ACTION to lead with, a DOOR to walk through
    and a REPLY to send back. Returns (todo, quiet) — `quiet` is the fifth
    family, the things that landed with nothing in them for him.
    """
    todo, quiet, seen = [], [], set()
    parked_reason = parked_reason or {}
    records = records or {}
    by_num = {i["number"]: i for i in allopen}

    def rec_for(n):
        return records.get(str(n), {})

    def because(num):
        """#159's ruling: the VERBATIM park reason where one exists. A lane that
        wrote 'merged as X, awaiting phone check' has already said the true
        thing better than any wording derived from a label could."""
        r = parked_reason.get(str(num))
        # Its own line: after a full stop, " — the lane said:" read as a
        # fragment glued to the sentence before it.
        return (f'<br><span class="dim">the lane said: &ldquo;'
                f'{html.escape(r[:160])}&rdquo;</span>') if r else ''

    def card(i, family, icon, meta):
        n = i["number"]
        rec = rec_for(n)
        action = action_for(family, i, rec)
        reps = replies_for(family, n)
        return {"icon": icon, "family": family, "issue": n, "action": action,
                "text": f'<b>{html.escape(action)}</b>',
                "meta": meta + because(n),
                "door": door_html(rec, records_ok),
                "says": says_html(reps),
                "url": rec.get("TEST-URL"),
                "buttons": copy_btn("Copy for keeper",
                                    copy_payload(n, action, reps)),
                "gh": gh_fine(i)}

    # 1. a lane raised its hand and named him (its chip reads 'needs you', and
    #    #159's ruling says every one of those is mirrored here with its action)
    for l in seats:
        if (l.get("state") != "needs-you" or not l.get("reason")
                or l.get("state_from_label")):
            continue                       # see the note at the upgrade site
        seat = l["branch"]
        num = seat.split("-")[0]
        iss = by_num.get(int(num)) if num.isdigit() else None
        # A question's answer is a sentence, not a word, so this family gets no
        # suggested replies — the copy button hands him the opening instead.
        name = plainize(iss["title"]) if iss else seat
        todo.append({
            "icon": "💬",
            "family": "question",
            "issue": iss["number"] if iss else None,
            "action": f"Answer the {name} lane",
            "text": (f'<b>Answer {html.escape(seat)}</b> — it asked: '
                     f'&ldquo;{html.escape(l["reason"][:200])}&rdquo;'),
            "door": door_html(rec_for(num) if num.isdigit() else {}, records_ok,
                              quiet_when_absent=True),
            "buttons": copy_btn(
                "Copy for keeper",
                (f"Re #{num} {name} — answering: " if iss
                 else f"Re {seat}: answering its question — ")),
            "gh": gh_fine(iss) if iss else "",
        })

    # 2. a plan is ready and only his GO is missing
    for i in needs:
        seen.add(i["number"])
        d = days_since(i["updated_at"])
        waited = ("waiting since this morning" if d == 0
                  else f"waiting {d} day{'s' if d != 1 else ''}")
        plan = i.get("_plan") or "(no plan text found)"
        c = card(i, "plan", "✅", f'the plan is ready, {waited}.')
        c["detail"] = plan[:6000]
        c["buttons"] = (
            f'<button class="actbtn apprbtn" data-issue="{i["number"]}" '
            f'data-nonce="{nonce("approve", i["number"])}">Approve ✓</button>'
            + c["buttons"] + copy_btn("Copy plan", plan[:6000]))
        todo.append(c)

    # 3 + 4. built = one flip from members seeing it; merged = awaiting his look
    #
    # 5 (#172, spec point 5). NO-IAN-ACTION CARDS ARE EXCLUDED. The only rule
    # derivable without guessing at what a thing is: merged + `infra` + NOT
    # `built` is keeper's own tooling — there is no member-facing surface to look
    # at and no flag to say GO to. Ian's ruling 8/20 when I put the two options
    # to him: it drops to ONE QUIET LINE rather than vanishing, because silently
    # dropping something from his list is the one failure this page must not
    # have. If the rule is ever wrong, a wrong quiet line is recoverable and a
    # wrong disappearance is not.
    for i in allopen:
        if i["number"] in seen:
            continue
        lab = labels_of(i)
        if "built" in lab:
            seen.add(i["number"])
            todo.append(card(i, "flip", "🎚",
                             "built and merged; one flag flip from members "
                             "seeing it."))
        elif "merged" in lab:
            seen.add(i["number"])
            if "infra" in lab:
                quiet.append(i)
                continue
            todo.append(card(i, "look", "📱",
                             "merged; your look is the last thing left."))
    return todo, quiet



def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--json-file", help="read lanes JSON from a file instead of running `lanes --json`")
    ap.add_argument("--issues-file", help="read GitHub state from a fixture instead of the API")
    ap.add_argument("--out", help="write index.html + lanes.json here (default /var/www/dev/lanes)")
    # #172: the TEST-URL records are read from commit bodies on main, so the
    # repo has to be nameable — that is what lets a lane verify its own seed
    # BEFORE the merge that puts it on keeper's main, and what lets gate 77
    # exercise the git source in a throwaway repo instead of the real box.
    ap.add_argument("--repo", help="read git history here (default %s)" % REPO)
    ap.add_argument("--history-ref", default="main",
                    help="the ref whose commit bodies carry TEST-URL records "
                         "(default main; a lane names its own branch to check "
                         "its seed before the merge)")
    ap.add_argument("--decisions-dir",
                    help="read the pending-question store here (default "
                         "~/.lg-decisions); gate 77 points this at a fixture")
    args = ap.parse_args()
    out_dir = pathlib.Path(args.out) if args.out else OUT
    repo = args.repo or REPO

    if args.json_file:
        data = json.loads(pathlib.Path(args.json_file).read_text())
    else:
        data = json.loads(run(["/usr/local/bin/lanes", "--json"]))
    dep = data["deploy"]

    merged = [b.strip().lstrip("+* ") for b in run(
        ["git", "-C", repo, "branch", "--merged", "main"]).splitlines()]
    merged = [b for b in merged if b and b != "main"]
    backups = [b for b in run(
        ["git", "-C", repo, "for-each-ref", "--format=%(refname:short)",
         "refs/heads"]).splitlines() if b.endswith("-backup")]
    shipped = [l for l in run(
        ["git", "-C", repo, "log", "--first-parent", "main",
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
            # ⚠ #172: MARK IT AS OURS. This upgrade is derived from a label, so
            # the words below are the RENDERER'S, not the lane's — and the todo
            # list's first family exists for a lane that raised its hand and
            # named him. Untagged, this seat printed "Answer 138-phase-b — it
            # asked: 'merged — waiting on your check'", which attributes a
            # sentence nobody said to a lane that never asked, AND swallowed the
            # issue before the merged/built families could class it (#138 is the
            # one item that should have dropped to the quiet line and did not).
            # The SEAT CARD still shows this state and this reason — that is
            # #159's design and unchanged. Only the checklist looks past it.
            l["state_from_label"] = True
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
/* #172 — the accordions. The summary IS the snapshot line, so it has to read
   as one row: heading, then its live count, and a marker that says which way
   it opens. */
details.acc{border-top:1px solid #2a2f38;margin:0 0 2px;}
details.acc>summary{cursor:pointer;list-style:none;padding:10px 2px;
  display:flex;align-items:baseline;gap:10px;}
details.acc>summary::-webkit-details-marker{display:none;}
/* ⚠ A LITERAL GLYPH, NOT A CSS ESCAPE. "\\25B8" here is read by PYTHON first,
   where \\25 is an OCTAL escape, so the browser received a control character
   followed by the text "B8" and every closed section wore a mojibake bullet.
   Caught by looking at the rendered page; no markup assertion would have. */
details.acc>summary::before{content:"▸";color:#9aa3ad;font-size:11px;
  transition:transform .12s;}
details.acc[open]>summary::before{transform:rotate(90deg);}
details.acc>summary h2{display:inline;margin:0;}
details.acc>summary:hover h2{color:#e8e6df;}
.acccount{color:#9aa3ad;font-size:12.5px;margin-left:auto;}
.accbody{padding:2px 0 12px;}
/* #172 — the card's own furniture: a door, a starter and demoted fine print. */
.dobtn{display:inline-block;background:#2a3a20;color:#9db668;
  border:1px solid #3a4049;border-radius:6px;padding:2px 10px;font-size:12px;
  font-weight:700;text-decoration:none;}
.doorpath{color:#9aa3ad;font-size:12px;margin-left:8px;}
.nodoor{color:#9aa3ad;font-size:12.5px;font-style:italic;}
.says{color:#9aa3ad;font-size:12.5px;margin-top:6px;}
.ghfine{color:#6f7681;font-size:11.5px;text-decoration:none;}
.ghfine:hover{color:#9aa3ad;}
.fine{margin-top:6px;}
.quietlanded{color:#9aa3ad;font-size:12.5px;padding:6px 2px 0;}
.todo .meta{color:#9aa3ad;font-size:12.5px;margin-top:3px;}
.shipline{font-size:13px;color:#9aa3ad;padding:2px 0;}
.shipline b{color:#e8e6df;font-weight:600;}
/* #202 — the decision box. The BUTTON lives at accordion depth zero, because a
   collapsed decision is a hidden decision and this page's rule is that the loud
   layer is never inside an accordion. It sits below AT RISK and above Your list:
   a decision is a request, not a failure, and must not shout over one. */
.decide{background:#2a2418;border:1px solid #6b5628;border-left:3px solid #e0b64f;
  border-radius:8px;padding:12px 14px;margin-bottom:14px;}
.decide b{font-size:16px;}
.decidebtn{background:#e0b64f;color:#14161a;border:none;border-radius:6px;
  padding:7px 16px;font-size:14px;font-weight:700;cursor:pointer;margin-top:8px;}
.decidebtn:hover{background:#efc763;}
dialog#lg-decide-dlg{background:#1b1f26;color:#e8e6df;border:1px solid #3a4049;
  border-radius:10px;padding:0;max-width:600px;width:calc(100% - 24px);}
dialog#lg-decide-dlg::backdrop{background:rgba(0,0,0,.62);}
.dlghead{display:flex;align-items:baseline;gap:10px;padding:14px 16px 8px;
  border-bottom:1px solid #2a2f38;}
.dlghead h2{margin:0;}
.dlgbody{padding:12px 16px 16px;max-height:70vh;overflow-y:auto;}
.dlgclose{margin-left:auto;background:#2d323b;color:#9aa3ad;border:1px solid #3a4049;
  border-radius:6px;padding:2px 10px;font-size:12px;font-weight:700;cursor:pointer;}
.qcard{border:1px solid #2a2f38;border-radius:8px;padding:10px 12px;margin-bottom:10px;}
.qcard .qq{font-size:15px;font-weight:600;}
.qcard .qd{color:#9aa3ad;font-size:12.5px;margin-top:4px;}
.optbtn{display:block;width:100%;text-align:left;background:#232833;color:#e8e6df;
  border:1px solid #3a4049;border-radius:6px;padding:8px 10px;margin-top:8px;
  font-size:14px;cursor:pointer;font-family:inherit;}
.optbtn:hover:not(:disabled){border-color:#9db668;background:#283040;}
.optbtn:disabled{opacity:.5;cursor:default;}
.optbtn .ol{font-weight:700;}
.optbtn .od{display:block;color:#9aa3ad;font-size:12.5px;font-weight:400;margin-top:2px;}
.optbtn .orec{color:#9db668;font-size:11.5px;font-weight:700;margin-left:6px;}
.qdone{color:#9db668;font-size:13px;font-weight:700;margin-top:8px;}
.dlgnote{color:#9aa3ad;font-size:13px;}
.dlgloud{color:#e05f4f;font-weight:700;font-size:13.5px;}
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
    # 3a. THE DECISION BOX (#202). Ian, 8/22: "I want a button that opens up the
    # decision box that we use here and have it communicate with you."
    #
    # Depth zero — outside every accordion — for the same reason AT RISK is:
    # a collapsed decision is a hidden decision. Below the risk blocks, because
    # a decision is a request and not a failure.
    #
    # ⚠ The three states are three DIFFERENT renders, and the third is the whole
    # point: nothing pending is SILENCE (quiet when healthy), and a store that
    # could not be read is LOUD. They must never look alike — the one inversion
    # of the quiet rule that this page exists to protect.
    dcount, dok = pending_decisions(args.decisions_dir)
    if not dok:
        h.append('<div class="block gap"><b>DECISIONS UNKNOWN</b><br>'
                 'I could not read the question list — that is not the same as '
                 'there being none. Nothing is waiting to be concluded from '
                 'this.</div>')
    elif dcount:
        h.append(f'<div class="decide"><b>{plural(dcount, "decision")} waiting '
                 f'for you</b><div class="dim" style="margin-top:4px">'
                 f'the same boxes keeper asks in chat — one tap answers one, '
                 f'and keeper hears it within the minute</div>'
                 f'<button class="decidebtn" id="lg-decide-open">'
                 f'Open the decision box</button></div>')
    # The dialog is an EMPTY shell. No question text and no nonce is ever baked
    # into this page: the browser fetches both from lanes-decisions.php when the
    # button is tapped. That is what lets a page cached from yesterday still
    # work, and what makes a question answered in chat thirty seconds ago
    # already absent from the box.
    if dok and dcount:
        h.append('<dialog id="lg-decide-dlg"><div class="dlghead">'
                 '<h2>Decisions</h2>'
                 '<button class="dlgclose" id="lg-decide-close">close</button>'
                 '</div><div class="dlgbody" id="lg-decide-body">'
                 '<div class="dlgnote">loading…</div></div></dialog>')

    parked_reason = {p["branch"].split("-")[0]: p["reason"] for p in parked
                     if p["branch"].split("-")[0].isdigit() and p.get("reason")}
    # #172: the doors and the action words. Only the issues that can reach the
    # list are read, so this is a bounded handful of API calls, not a sweep.
    todo_candidates = [i for i in allopen
                       if labels_of(i) & {"merged", "built", "plan-ready"}]
    records, records_ok = gather_records(
        todo_candidates, parked, repo, live=not args.issues_file,
        ref=args.history_ref)
    # A GitHub read that already failed cannot be trusted to say "no door"
    # either — the two failures are the same failure.
    records_ok = records_ok and gh_ok
    todo, quiet = build_todo(seats, needs, allopen, parked_reason,
                             records, records_ok)
    if todo:
        acc(h, "your-list", "Your list", plural(len(todo), "item"), open_=True)
        for t in todo:
            h.append(f'<div class="todo"><span class="ic">{t["icon"]}</span>'
                     f'<span class="why">{t["text"]}</span>')
            if t.get("meta"):
                h.append(f'<div class="meta">{t["meta"]}</div>')
            if t.get("detail"):
                h.append(f'<details><summary>read the plan</summary>'
                         f'<div class="plan">{html.escape(t["detail"])}</div>'
                         f'</details>')
            h.append(f'<div class="acts">{t.get("door", "")}{t["buttons"]}</div>')
            if t.get("says"):
                h.append(t["says"])
            if t.get("gh"):
                h.append(f'<div class="fine">{t["gh"]}</div>')
            h.append('</div>')
        # The fifth family: merged keeper tooling with nothing in it for him.
        # One line, never a bullet, never silence (Ian's ruling 8/20).
        if quiet:
            names = " &middot; ".join(
                f'<a href="{html.escape(i["html_url"])}" target="_blank" '
                f'rel="noopener" class="ghfine">#{i["number"]} '
                f'{html.escape(plainize(i["title"], 44))}</a>' for i in quiet)
            h.append(f'<div class="quietlanded">landed, nothing for you to do: '
                     f'{names}</div>')
        acc_end(h)

    # 3a. AGENTS (#164) — the WORKERS view. Ian: "I guess we need an agent
    # section and what they are working like - agent 1 - 146 edit button thing."
    # Deliberately NOT a second description of the desks (which is the thing
    # #160 fixed): no seats, no branches, no git numbers here. A row exists for
    # every agent that is ALIVE — a session at a prompt is still a worker, and
    # saying what it waits for is the whole point. Absent when nobody is home.
    live_agents = [l for l in seats if l.get("agent", "none") != "none"]
    if live_agents:
        working_now = sum(1 for l in live_agents if l.get("state") == "working")
        acc(h, "agents", "Agents",
            f'{plural(len(live_agents), "agent")} · {working_now} working')
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
        acc_end(h)

    # 3b. in motion (no seat) — active investigations (#137): keeper working,
    # nothing needed from Ian yet. Explicitly labeled only; absent when none.
    if investigating:
        acc(h, "in-motion", "In motion (no seat)",
            plural(len(investigating), "investigation"))
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
        acc_end(h)

    # 4. SEATS — one card each (#160). The Building strip and the seats table
    # were the same seats described twice; Ian asked what the difference was,
    # and the honest answer was "nothing", so they are one card now.
    desks = [l for l in seats if not issue_for(l)]        # no open issue -> old desk
    cards = [l for l in seats if issue_for(l)]
    if cards:
        acc(h, "seats", "Seats", plural(len(cards), "seat"))
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
    if cards:
        acc_end(h)

    # 4b. old desks — a seat whose branch answers to no open issue. Ian asked
    # for these grouped rather than mixed in with the work (#160).
    if desks:
        acc(h, "old-desks", "Old desks", plural(len(desks), "desk"))
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
        acc_end(h)

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
        acc(h, "parked", "Parked — branch kept, seat freed",
            plural(len(parked), "branch", "branches"))
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
        acc_end(h)

    # 7. cleanup footnote — names, not counts (a count isn't actionable)
    free_names = " · ".join(
        f'{l["folder"].removeprefix("worktrees/")}'
        + (f' ({l["branch"]})' if l["mismatch"] else '')
        for l in freeable) or "none"
    acc(h, "cleanup", "Cleanup",
        f'{plural(len(merged), "merged branch", "merged branches")} deletable')
    h.append(f'<div class="foot">{len(merged)} merged branch(es) '
             f'deletable &middot; {len(backups)} backup branch(es) held for '
             f'review &middot; finished &amp; freeable: {html.escape(free_names)}</div>')
    acc_end(h)

    # 8. shipped, last 7 days — self-clearing; where an unflipped flag shows up
    if shipped:
        acc(h, "landed", "Landed on main, last 7 days",
            f'{plural(len(shipped), "merge")} in 7 days')
        for line in shipped:
            d, _, sj = line.partition("|")
            h.append(f'<div class="shipline"><b>{html.escape(d)}</b> '
                     f'{html.escape(sj[:100])}</div>')
        acc_end(h)

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
  /* #172 — open state per section. The server already rendered the default
     (collapsed, except Your list), so this only ever OVERRIDES it; a section he
     keeps open therefore does not flash shut on each 5-minute redraw. A browser
     with localStorage refused keeps the defaults and says nothing. */
  document.querySelectorAll('details.acc').forEach(function(d){
    var k='lg-lanes-acc:'+d.getAttribute('data-acc');
    try{var v=localStorage.getItem(k);
        if(v==='1')d.open=true;else if(v==='0')d.open=false;}catch(e){}
    d.addEventListener('toggle',function(){
      try{localStorage.setItem(k,d.open?'1':'0');}catch(e){}});});
  /* #202 — the decision box. Ian: "a button that opens up the decision box
     that we use here and have it communicate with you."

     Everything is fetched on OPEN, never baked: fresh questions, fresh nonces,
     and anything he already answered in chat is simply not in the response.
     One tap answers one question; the other cards stay open, because he may
     have three waiting and answering one is not answering them all. */
  var dop=document.getElementById('lg-decide-open'),
      ddl=document.getElementById('lg-decide-dlg'),
      dbd=document.getElementById('lg-decide-body');
  function esc(s){var d=document.createElement('div');d.textContent=s==null?'':s;
    return d.innerHTML;}
  function answer(btn,card,id,key){
    card.querySelectorAll('.optbtn').forEach(function(o){o.disabled=true;});
    var was=btn.innerHTML;btn.innerHTML='sending…';
    var b=new URLSearchParams();b.set('id',id);b.set('key',key);
    b.set('nonce',btn.dataset.nonce);
    fetch('/lanes-decide.php',{method:'POST',credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},body:b.toString()})
    .then(function(r){return r.json()}).then(function(j){
      if(j&&j.ok){btn.innerHTML=was;
        card.innerHTML='<div class="qq">'+card.dataset.q+'</div>'+
          '<div class="qdone">answered: '+esc(btn.dataset.label)+
          ' &check; — keeper has been told</div>';}
      else{card.querySelectorAll('.optbtn').forEach(function(o){o.disabled=false;});
        btn.innerHTML=was;
        /* A refusal is SHOWN, never swallowed. "Already answered" is the
           honest outcome of a race with the chat channel and he needs to see
           it, or the page has told him something it does not know. */
        var e=document.createElement('div');e.className='dlgloud';
        e.textContent=(j&&j.error)?j.error:'that did not send';
        card.appendChild(e);}
    }).catch(function(){
      card.querySelectorAll('.optbtn').forEach(function(o){o.disabled=false;});
      btn.innerHTML=was;
      var e=document.createElement('div');e.className='dlgloud';
      e.textContent='that did not send — check you are still signed in';
      card.appendChild(e);});
  }
  function draw(j){
    if(!j||!j.ok){
      /* The law again, in the browser this time: a failed read says so. */
      dbd.innerHTML='<div class="dlgloud">I could not read the question list'+
        ((j&&j.error)?' — '+esc(j.error):'')+'. That is not the same as there '+
        'being none.</div>';return;}
    if(!j.questions||!j.questions.length){
      dbd.innerHTML='<div class="dlgnote">Nothing waiting on you right now — '+
        'anything that was here has been answered.</div>';
      if(j.unreadable){dbd.innerHTML+='<div class="dlgloud">…but '+j.unreadable+
        ' question(s) in the store could not be read, so this may not be all of '+
        'them.</div>';}
      return;}
    dbd.innerHTML='';
    j.questions.forEach(function(q){
      var c=document.createElement('div');c.className='qcard';
      c.dataset.q=esc(q.question);
      var html='<div class="qq">'+esc(q.question)+'</div>';
      if(q.detail)html+='<div class="qd">'+esc(q.detail)+'</div>';
      if(q.issue)html+='<div class="qd">about #'+q.issue+'</div>';
      c.innerHTML=html;
      (q.options||[]).forEach(function(o){
        var b=document.createElement('button');b.className='optbtn';
        b.dataset.nonce=o.nonce;b.dataset.label=o.label;
        b.innerHTML='<span class="ol">'+esc(o.label)+'</span>'+
          (o.recommended?'<span class="orec">recommended</span>':'')+
          (o.description?'<span class="od">'+esc(o.description)+'</span>':'');
        b.addEventListener('click',function(ev){ev.preventDefault();
          answer(b,c,q.id,o.key);});
        c.appendChild(b);});
      dbd.appendChild(c);});
    if(j.unreadable){var w=document.createElement('div');w.className='dlgloud';
      w.textContent=j.unreadable+' question(s) in the store could not be read, '+
        'so this may not be all of them.';dbd.appendChild(w);}
  }
  if(dop&&ddl){
    dop.addEventListener('click',function(){
      dbd.innerHTML='<div class="dlgnote">loading…</div>';
      if(ddl.showModal)ddl.showModal();else ddl.setAttribute('open','');
      fetch('/lanes-decisions.php',{credentials:'same-origin'})
        .then(function(r){return r.json()}).then(draw)
        .catch(function(){draw(null);});});
    var dcl=document.getElementById('lg-decide-close');
    if(dcl)dcl.addEventListener('click',function(){
      if(ddl.close)ddl.close();else ddl.removeAttribute('open');});
  }
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
    # The JSON is for machines, so it gets the words and not the markup —
    # entities unescaped too, or a consumer reads "it&rsquo;s merged".
    data["todo"] = [html.unescape(re.sub("<[^>]+>", "", t["text"])) for t in todo]
    # #172: the structured form, so a consumer gets the action and the door
    # without parsing prose. The flat list above is kept as it was — a shape
    # change is a broken consumer, and nothing on this box asked for one.
    # #202: the count and whether it could be read, so a machine consumer sees
    # the same three states the page does. Deliberately NOT the questions
    # themselves and never a nonce — lanes.json is a static file on disk and a
    # nonce in it would be a nonce with a five-minute life and no reader.
    data["decisions"] = {"pending": dcount, "readable": dok}
    data["todo_cards"] = [
        {"issue": t.get("issue"), "family": t.get("family"),
         "action": t.get("action"), "test_url": t.get("url")} for t in todo]
    (out_dir / "lanes.json").write_text(json.dumps(data, indent=1), encoding="utf-8")


if __name__ == "__main__":
    main()
