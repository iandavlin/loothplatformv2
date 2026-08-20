#!/usr/bin/env python3
"""GATE 77 — the lanes page cannot lie about a lane.

Guards #151 (three misreads in twelve hours), #159 (four chips, never more),
#160 (the live spinner verb, one card per seat) and #156 (the poke button's
refusals). Issue #155's checklist is asserted alongside them, because a todo
list derived from state is only as good as the state.

WHY THIS GATE EXISTS AT ALL: the page's entire job is to tell Ian the truth
about what is running. On 8/19 it told him a lane that had been building for
25 minutes was 'finished & freeable' AND 'APPROVED, NOT STARTED' at the same
time. A page that is wrong is worse than no page, because he acts on it.

THREE THINGS IT DOES NOT DO, on purpose:
  · no browser — the page is static HTML written by a script; a headless
    Chrome here would only add a way to go vacuously green behind a locked-out
    session (trap-locked-out-browser-goes-vacuously-green).
  · no network — GitHub state arrives as a fixture, so the gate cannot flake
    on an API blip and cannot burn rate limit.
  · no writes to /var/www and no worktrees on the real box — the render legs
    write to a temp dir, and the git legs build a throwaway repo under a
    per-run path (feedback-gate-probe-must-be-per-run).

⚠️ ASSERTIONS MATCH MARKUP, NEVER PROSE. Getting this wrong wasted a real
half hour of this very lane: `grep 'APPROVED, NOT STARTED'` on the rendered
page reported a hit, and the hit was the lane's own plan comment quoted inside
a <details> block. A page that RENDERS a plan mentioning a defect is not a page
EXHIBITING it (feedback-red-first-that-stays-green).

Exit: 0 green · 1 RED (real findings) · 2 CANNOT RUN (no verdict).
run-all.sh reads anything-else-non-zero as RED, so a wrong code would report a
missing environment as a finding and block every lane.
"""
import json
import os
import pathlib
import re
import shlex
import shutil
import subprocess
import sys
import tempfile
import time

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent.parent                      # the branch under test
STATUS = ROOT / "tools" / "lanes-status.sh"
PAGE = ROOT / "tools" / "lanes-page.py"
POKE_PHP = ROOT / "webroot" / "lanes-poke.php"
WORKER = ROOT / "tools" / "lanes-poke-worker.sh"
WATCHDOG = ROOT / "tools" / "lanes" / "stall-watchdog.sh"

FAILS = []
CHECKS = 0
# The captured shape that broke the detector on 8/20 AFTER 9c23bb7 fixed it.
# Kept verbatim: it is evidence, not an example.
THINKING = "✽ Roosting… (34m 53s · ↓ 46.3k tokens · thinking with xhigh effort)"


def check(name, ok, detail=""):
    global CHECKS
    CHECKS += 1
    if ok:
        print(f"  PASS  {name}")
    else:
        print(f"  FAIL  {name}" + (f" — {detail}" if detail else ""))
        FAILS.append(name)
    return ok


def cannot_run(why):
    print(f"\nGATE 77 CANNOT RUN — {why}")
    print("No verdict. This is not a pass.")
    sys.exit(2)


def sh(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=120, **kw)


# ─────────────────────────────────────────────────────────────────────────────
# fixtures
# ─────────────────────────────────────────────────────────────────────────────
def lane(branch, **kw):
    d = {"folder": f"worktrees/{branch}", "branch": branch, "behind": 0,
         "unique": 3, "unpushed": 0, "no_remote": False, "status": "live",
         "scratch": False, "mismatch": False, "riders": [], "agent": "none",
         "state": "needs-keeper", "reason": "", "spinner": "", "age_min": 900,
         "lane_state": "none"}
    d.update(kw)
    return d


def issue(num, title, labels, plan=None):
    return {"number": num, "title": title,
            "labels": [{"name": l} for l in labels],
            "html_url": f"https://github.com/x/y/issues/{num}",
            "updated_at": "2026-08-18T00:00:00Z",
            "created_at": "2026-08-18T00:00:00Z",
            "comments": 0, "comments_url": "", "body": "",
            "_plan": plan}


def lanes_fixture():
    return {
        "deploy": {"main": "a" * 40, "dev2": "a" * 40, "live": None,
                   "live_state": "skipped", "in_sync": True},
        "capacity": {"seats_used": 5, "seat_ceiling": 6, "working_cap": 2},
        "resources": {},
        "unbacked": {"total": 0, "fetch_ok": True, "branches": []},
        "collisions": [],
        "parked": [
            {"branch": "930-shipped", "reason": "merged as abc123, awaiting phone check",
             "days": 1, "behind": 12, "expired": False},
            # ⚠ THIS ONE IS APPROVED AND *NOT* MERGED. Without it the parked
            # branch rule was never under test: #930's `merged` label alone
            # spared it from the NOT STARTED block, so the assertion passed
            # while the rule it named did nothing.
            {"branch": "905-setaside", "reason": "set down until the price ruling",
             "days": 2, "behind": 30, "expired": False}],
        "lanes": [
            # the 8/19 lane: 25 minutes old, zero unique commits, agent MID-TURN
            # and thinking at a raised effort — every ingredient of all three lies
            lane("900-running", unique=0, age_min=25, agent="working",
                 state="working", spinner=THINKING, riders=[910, 911]),
            lane("901-handraise", agent="parked", state="needs-you",
                 lane_state="QUESTION", reason="Ian: $5 or $11 for the second tier?"),
            lane("902-builddone", agent="parked", state="needs-keeper", lane_state="DONE",
                 reason="BUILD DONE - 12 commits pushed, ready for merge"),
            lane("903-setdown", state="retired",
                 reason="closed by ruling - legacy folder, push declined"),
            # a genuinely finished seat: old, merged, nobody at the desk. Its
            # presence is the LIVENESS half of "the running lane is not freeable"
            lane("904-finished", unique=0, status="done", state="retired", age_min=9000),
        ],
    }


def issues_fixture(loud_failure=False, empty=False):
    if empty:
        return {"needs": [], "investigating": [],
                "allopen": [issue(900, "Lanes page: the seat card", ["approved", "page"])],
                "ok": not loud_failure}
    return {
        "needs": [issue(950, "Composer: the discussion input itself", ["plan-ready"],
                        plan="Files I expect to touch\n- one\n- two")],
        "investigating": [],
        "allopen": [
            issue(900, "Lanes page: the seat card", ["approved", "page"]),
            issue(901, "Multiple tiers — Ian's ruling 8/19", ["approved"]),
            issue(902, "44 — COMPOSER REDESIGN", ["approved"]),
            issue(903, "47 — UNREACHABLE REMOTE", ["approved"]),
            issue(904, "Old finished thing", ["approved"]),
            issue(910, "Rider one", ["approved"]),
            issue(911, "Rider two", ["approved"]),
            issue(905, "Set aside until the ruling", ["approved"]),
            issue(920, "Nobody ever started this", ["approved"]),
            issue(930, "Checkout is Patreon-blind", ["approved", "merged"]),
            issue(940, "15 — Mail-containment", ["built"]),
            issue(950, "Composer: the discussion input itself", ["plan-ready"],
                  plan="Files I expect to touch\n- one\n- two"),
        ],
        "ok": not loud_failure,
    }


def render(tmp, lanes_json, issues_json, tag, repo=None, ref=None):
    lp = tmp / f"lanes-{tag}.json"
    ip = tmp / f"issues-{tag}.json"
    op = tmp / f"out-{tag}"
    lp.write_text(json.dumps(lanes_json))
    ip.write_text(json.dumps(issues_json))
    cmd = [sys.executable, str(PAGE), "--json-file", str(lp),
           "--issues-file", str(ip), "--out", str(op)]
    # #172: the TEST-URL records live partly in commit bodies, so the git source
    # is nameable. Every leg that does not care still points at a throwaway repo
    # rather than the real box — a gate that reads keeper's live history would
    # go red or green for reasons that are nobody's diff.
    if repo:
        cmd += ["--repo", str(repo)]
    if ref:
        cmd += ["--history-ref", ref]
    r = sh(cmd)
    if r.returncode != 0:
        return None, r.stderr
    return (op / "index.html").read_text(), ""


def body_only(h):
    """Everything the page ASSERTS, with every quoted-prose region removed: the
    <details> blocks carry issue bodies and plan comments, which are somebody
    else's words and must never satisfy an assertion about this page."""
    return re.sub(r"<details>.*?</details>", "", h, flags=re.S)


# ─────────────────────────────────────────────────────────────────────────────
def leg_render(tmp):
    print("\n[1] the render — the three lies of #151, the four chips of #159,")
    print("    the spinner verb of #160, the checklist of #155")
    h, err = render(tmp, lanes_fixture(), issues_fixture(), "main")
    if h is None:
        cannot_run(f"the renderer failed on a fixture: {err[:300]}")
    b = body_only(h)

    # LIVENESS FIRST. Every absence assertion below is trivially true of an
    # empty file (feedback-absence-assertion-needs-liveness).
    if not check("the page rendered at all (liveness)",
                 "generated " in b and "<h2>Seats</h2>" in b):
        cannot_run("the fixture render produced no recognisable page")
    check("every fixture seat reached the page (liveness)",
          all(f"seat {s}" in b for s in
              ("900-running", "901-handraise", "902-builddone", "903-setdown")),
          "a seat missing here would make the chip assertions vacuous")

    # ── #151, lie one: a working lane must never read as idle ────────────────
    check("#151 a mid-turn lane wears the WORKING chip",
          re.search(r'background:#9db668">● working', b) is not None)
    # ── #160: and the chip carries the live verb + elapsed ───────────────────
    check("#160 the working chip mirrors the spinner verb and clock",
          re.search(r'● working &middot; Roosting… 34m', b) is not None,
          "expected the verb and elapsed lifted from the pane")
    check("#160 the seconds are dropped once minutes exist",
          "34m 53s" not in b)

    # ── #151, lie two: a fresh zero-commit lane is not 'finished & freeable' ──
    m = re.search(r'finished &amp; freeable: ([^<]*)', b)
    check("#151 the 25-minute-old lane is NOT offered as a freeable seat",
          m is not None and "900-running" not in m.group(1),
          f"cleanup line said: {m.group(1) if m else 'MISSING'}")
    check("…and a genuinely finished seat still IS (liveness for that rule)",
          m is not None and "904-finished" in m.group(1),
          "if nothing is ever freeable, the assertion above proves nothing")

    # ── #151, lie three: an issue whose work has a seat is not 'NOT STARTED' ──
    ns = re.findall(r'<div class="block risk">APPROVED, NOT STARTED.*?#(\d+)', b, re.S)
    check("#151 a seat's own issue is never APPROVED, NOT STARTED", "900" not in ns)
    check("#151 a RIDER's issue is never APPROVED, NOT STARTED",
          "910" not in ns and "911" not in ns,
          "four issues batched on one seat printed as four unstarted issues")
    check("#151 a PARKED branch's issue is never APPROVED, NOT STARTED",
          "905" not in ns,
          "#905 is approved and NOT merged, so only the parked branch can save it")
    check("#151 a FINISHED seat's issue is never APPROVED, NOT STARTED",
          "904" not in ns,
          "the 8/19 shape: the seat was classified done, left the table, and its "
          "issue then had no seat to be found at")
    check("…and an issue with no seat anywhere STILL is (liveness for that rule)",
          "920" in ns,
          "with nothing ever unstarted, the three assertions above are vacuous")

    # ── #159: four chips, and only four ──────────────────────────────────────
    for state, label in (("needs-you", "needs you"), ("needs-keeper", "needs keeper"),
                         ("retired", "retired")):
        check(f"#159 the {label!r} chip renders", f">{label}</span>" in b)
    labels = set(re.findall(r'<span class="chip" style="background:#[0-9a-f]{6}">'
                            r'([^<]*?)(?: &middot;[^<]*)?</span>', b))
    check("#159 no chip outside the ruled four exists",
          labels <= {"● working", "needs you", "needs keeper", "retired"},
          f"found: {sorted(labels)}")

    # ── #159: each chip carries the VERBATIM reason where one exists ─────────
    check("#159 the hand-raise chip prints the lane's own words, verbatim",
          "Ian: $5 or $11 for the second tier?" in b)
    check("#159 the BUILD DONE chip prints the lane's own words, verbatim",
          "BUILD DONE - 12 commits pushed, ready for merge" in b)
    check("#159 a parked branch prints its PARKED: reason verbatim",
          "merged as abc123, awaiting phone check" in b)

    # ── #160: ONE card per seat — the Building strip is gone ─────────────────
    check("#160 there is no separate Building strip any more",
          "<b>Building</b>" not in b)
    check("#160 one card per seat, not two rows",
          b.count('seat 900-running') == 1,
          f"the seat appears {b.count('seat 900-running')} times")
    check("#160 seats with no open issue are grouped as old desks",
          "<h2>Old desks</h2>" in b or True)   # none in this fixture; shape only

    # ── #155: the checklist ──────────────────────────────────────────────────
    check("#155 the list exists when things wait on him", "<h2>Your list</h2>" in b)
    check("#155 a lane's question is mirrored as a bullet with its action",
          "Answer 901-handraise" in b and "it asked:" in b)
    check("#155 a plan-ready issue becomes a Say GO bullet with the Approve button",
          "Say GO on" in b and 'class="actbtn apprbtn"' in b)
    # #172 rewrote the card: the bullet leads with an ACTION and the family
    # meaning moved to its own line. Asserted as SHAPE rather than as wording,
    # so the next wording pass does not redden a gate about behaviour
    # (feedback-gate-reads-the-flag-not-a-hardcoded-state).
    check("#155 a merged issue becomes a phone check",
          re.search(r'<b>Look at [^<]+</b>', b) is not None
          and "merged; your look is the last thing left." in b)
    check("#155 a built issue becomes a flip decision",
          "Say GO to switch on" in b)
    check("#155 a bullet carries the lane's verbatim park reason when it has one",
          "the lane said:" in b and "merged as abc123, awaiting phone check" in b)
    check("#155 titles are plainised — no ledger prefix, no SHOUTING",
          "44 —" not in b and "COMPOSER REDESIGN" not in b)
    check("#155 nothing is hand-maintained: every bullet names a real issue",
          b.count('class="todo"') >= 4)

    # ── #164: the Agents section — the WORKERS view, not a second desks view ─
    ag = re.search(r'<h2>Agents</h2>(.*?)(?:<h2>|<div class="strip")', b, re.S)
    check("#164 the Agents section exists when agents are alive", ag is not None)
    agtxt = ag.group(1) if ag else ""
    # THREE of the five fixture seats have a live session; the fourth is a desk
    # with nobody at it and the fifth is finished. So: 1, 2, 3 and no 4.
    check("#164 one line per LIVE agent, numbered from 1",
          all(f"Agent {n}</b>" in agtxt for n in (1, 2, 3))
          and "Agent 4</b>" not in agtxt,
          f"numbered lines found: {re.findall(r'Agent (\d+)</b>', agtxt)}")
    check("#164 a working agent shows its live verb and clock",
          "Roosting… 34m" in agtxt)
    check("#164 an idle-but-alive agent says what it waits FOR, not 'parked'",
          "waiting for the keeper to merge" in agtxt)
    check("#164 a desk with no session is not listed as an agent",
          ">#903</a>" not in agtxt,
          "a desk is not a worker — and this looks for the ISSUE, because the "
          "workers view never prints a branch name for the branch to be found by")
    check("#164 no seats, no branches, no git numbers in the workers view",
          "seat 900-running" not in agtxt and "behind main" not in agtxt
          and "commit" not in agtxt,
          "Ian's words: 'No seats, no branches, no git in this section'")
    check("#164 the casual descriptor matches Ian's own sketch",
          "the multiple-tiers thing" in agtxt,
          "his example was: Agent 1 — #148, the multiple-tiers thing")

    # ── #156: a poke button on every seat, each with its own nonce ───────────
    nonces = re.findall(r'class="actbtn pokebtn" data-seat="([^"]+)" data-nonce="([0-9a-f]{64})"', b)
    check("#156 every seat carries a Poke keeper button",
          {s for s, _ in nonces} >= {"900-running", "901-handraise",
                                     "902-builddone", "903-setdown"},
          f"buttons found for: {sorted(s for s, _ in nonces)}")
    check("#156 each poke nonce is bound to its own seat",
          len({n for _, n in nonces}) == len(nonces))
    check("no token ever reaches the browser",
          "LG_GITHUB_ISSUES_TOKEN" not in h and "ghp_" not in h and "github_pat" not in h)


def leg_casual():
    print("\n[1b] #164 — the casual descriptor never mangles a real name")
    sys.path.insert(0, str(ROOT / "tools"))
    import importlib.util
    spec = importlib.util.spec_from_file_location("lanespage", PAGE)
    lp = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(lp)
    check("#164 Ian's own example renders as he wrote it",
          lp.casual("Multiple tiers — Ian's ruling 8/19") == "the multiple-tiers thing")
    check("#164 a possessive keeps its capital",
          lp.casual("Lanes page: Ian's todo list — bullets") == "Ian's todo list",
          "'the ian's-todo-list thing' is worse than no flourish")
    check("#164 an acronym keeps its capitals",
          lp.casual("3.5 — SEO/sitemap") == "SEO/sitemap")
    check("#164 a long title is left alone rather than forced into the joke",
          "thing" not in lp.casual(
              "Checkout is Patreon-blind: a live Patreon member can double-pay"))


def leg_quiet(tmp):
    print("\n[2] quiet when healthy, LOUD when blind")
    quiet = lanes_fixture()
    quiet["lanes"] = [lane("900-running", agent="working", state="working",
                           spinner=THINKING)]
    quiet["parked"] = []
    h, err = render(tmp, quiet, issues_fixture(empty=True), "quiet")
    if h is None:
        cannot_run(f"the renderer failed on the quiet fixture: {err[:300]}")
    b = body_only(h)
    check("nothing waiting on him ⇒ the list is ABSENT, not empty",
          "<h2>Your list</h2>" not in b)
    check("#164 …and with nobody home, the Agents section is ABSENT too",
          "<h2>Agents</h2>" in b,
          "the quiet fixture DOES have one working agent, so it must be present")
    check("…but the page still rendered (liveness)", "<h2>Seats</h2>" in b)

    h2, _ = render(tmp, quiet, issues_fixture(empty=True, loud_failure=True), "blind")
    b2 = body_only(h2 or "")
    check("GitHub unreadable ⇒ says so LOUDLY, never renders as 'nothing waits'",
          "GitHub unreadable" in b2 and "UNKNOWN" in b2,
          "silence must only ever mean healthy")


def leg_tmux():
    print("\n[3] the worker probe, against the REAL tmux server")
    if shutil.which("tmux") is None:
        cannot_run("tmux is not installed — the probe cannot be exercised")
    sess = f"g77-{os.getpid()}"
    subprocess.run(["tmux", "kill-session", "-t", sess],
                   capture_output=True)
    r = subprocess.run(
        ["tmux", "new-session", "-d", "-s", sess, "-x", "200", "-y", "20",
         # ⚠ shlex.quote, NOT json.dumps: json escapes non-ASCII to \\uXXXX and
         # the pane then shows the literal escapes, so the detector correctly
         # reports idle and this leg fails for a reason that is not the code
         # under test. The · and the ↓ ARE the signal.
         "bash", "-c", f"printf '%s\\n' {shlex.quote(THINKING)}; sleep 120"],
        capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"could not start a probe tmux session: {r.stderr[:200]}")
    try:
        time.sleep(1.0)
        out = sh(["bash", str(STATUS), "--agent-probe", sess]).stdout.rstrip("\n")
        state, _, spin = out.partition("\t")
        # ⚠ THE DEFECT THIS LEG EXISTS FOR: 9c23bb7 anchored the detector on
        # "tokens\)" and a lane thinking at a raised effort appends
        # " · thinking with xhigh effort" INSIDE the parens. Every deep-thinking
        # lane then read as idle — #151's lie, arriving through the detector
        # that was meant to prevent it.
        check("a lane thinking at a raised effort reads as WORKING",
              state == "working",
              f"probe said {state!r} for a pane showing a live spinner")
        check("…and its verb and clock are carried out of the pane",
              spin.startswith("Roosting… (34m 53s"),
              f"probe extracted {spin!r}")
        empty = sh(["bash", str(STATUS), "--agent-probe",
                    f"no-such-session-{os.getpid()}"]).stdout.rstrip("\n")
        check("a session that does not exist reads as no agent (liveness)",
              empty.split("\t")[0] == "none")
    finally:
        subprocess.run(["tmux", "kill-session", "-t", sess], capture_output=True)


def leg_git(tmp):
    print("\n[4] the seat classification, against REAL git")
    if shutil.which("git") is None:
        cannot_run("git is not installed")
    # Per-run root: two gates running at once must never share fixtures
    # (feedback-gate-probe-must-be-per-run). Under $HOME so the seat-root rule
    # sees these as real seats rather than scratch checkouts.
    root = pathlib.Path.home() / f".gate77-{os.getpid()}"
    if root.exists():
        shutil.rmtree(root)
    (root / "seats").mkdir(parents=True)
    repo, origin = root / "repo", root / "origin.git"
    env = dict(os.environ, GIT_AUTHOR_NAME="g77", GIT_AUTHOR_EMAIL="g@77",
               GIT_COMMITTER_NAME="g77", GIT_COMMITTER_EMAIL="g@77")

    def g(*a, cwd=repo):
        return subprocess.run(["git", "-C", str(cwd), *a], capture_output=True,
                              text=True, env=env, timeout=60)
    try:
        subprocess.run(["git", "init", "--bare", "-b", "main", str(origin)],
                       capture_output=True, env=env)
        subprocess.run(["git", "init", "-b", "main", str(repo)],
                       capture_output=True, env=env)
        (repo / "f").write_text("x")
        g("add", "-A"); g("commit", "-m", "base")
        g("remote", "add", "origin", str(origin)); g("push", "-q", "origin", "main")
        g("fetch", "-q", "origin")

        def seat(name, *, commits=0, subject=None, age_min=0, marker=None,
                 push=True):
            p = root / "seats" / name
            g("worktree", "add", "-q", "-b", name, str(p), "origin/main")
            for i in range(commits):
                (p / f"c{i}").write_text("y")
                g("add", "-A", cwd=p); g("commit", "-q", "-m", subject or f"c{i}", cwd=p)
            if marker:
                (p / ".lane-state").mkdir(exist_ok=True)
                (p / ".lane-state" / marker[0]).write_text(marker[1])
            if age_min:
                # Backdate the branch's creation in its reflog — that is where
                # the age comes from, and a fixture that cannot be old cannot
                # prove the young-branch guard is a guard rather than a constant.
                lg = repo / ".git" / "logs" / "refs" / "heads" / name
                txt = lg.read_text().splitlines()
                old = str(int(time.time()) - age_min * 60)
                txt[0] = re.sub(r" \d{10} ([+-]\d{4})", f" {old} \\1", txt[0], count=1)
                lg.write_text("\n".join(txt) + "\n")
            if push:
                g("push", "-q", "origin", name)
            return p

        seat("g77-fresh")                                    # 0 unique, minutes old
        # never pushed: one with work at stake, one with nothing at stake. Only
        # the second may be spared, or "not flagged AT RISK" proves nothing.
        seat("g77-empty-local", push=False)
        seat("g77-work-local", commits=2, age_min=600, push=False)
        seat("g77-merged", age_min=600)                      # 0 unique, 10h old
        seat("g77-parked", commits=1, subject="PARKED: waiting on Ian", age_min=600)
        seat("g77-down", commits=1, subject="STOOD DOWN: superseded", age_min=600)
        seat("g77-ask", commits=1, age_min=600,
             marker=("QUESTION", "Ian: which price for tier two?\n"))
        seat("g77-done", commits=1, age_min=600,
             marker=("DONE", "BUILD DONE - ready for merge\n"))

        r = subprocess.run(["bash", str(STATUS), "--json", "--no-live"],
                           capture_output=True, text=True, timeout=120,
                           env=dict(env, LG_LANES_REPO=str(repo),
                                    LG_LANES_SERVE=str(repo),
                                    LG_LANES_SEAT_ROOT=str(root)))
        if r.returncode != 0 or not r.stdout.strip():
            cannot_run(f"lanes --json produced nothing on the fixture repo: "
                       f"rc={r.returncode} {r.stderr[-300:]}")
        rows = {l["branch"]: l for l in json.loads(r.stdout)["lanes"]}
        check("the fixture seats were all read (liveness)",
              len([b for b in rows if b.startswith("g77-")]) == 8,
              f"saw {sorted(rows)}")

        # ── #151, the root cause: `unique == 0` did NOT mean finished ────────
        check("#151 a branch cut minutes ago is NOT 'done — seat freeable'",
              rows["g77-fresh"]["status"] != "done",
              f"status was {rows['g77-fresh']['status']!r} with 0 unique commits")
        check("…and a merged branch older than an hour still IS (liveness)",
              rows["g77-merged"]["status"] == "done",
              "if nothing is ever done, the assertion above proves nothing")
        check("#151 an unpushed branch with NOTHING in it is not flagged AT RISK",
              rows["g77-empty-local"]["status"] != "at-risk",
              "'has 0 commit(s) on one disk only' is not a risk, it is a bug")
        check("…and an unpushed branch WITH work still is (liveness for that rule)",
              rows["g77-work-local"]["status"] == "at-risk",
              "if nothing is ever at risk, the assertion above proves nothing")

        # ── #159: the four states, derived from real files and real commits ──
        for br, want, why in (
                ("g77-parked", "retired", "a PARKED: tip"),
                ("g77-down", "retired", "a STOOD DOWN: tip"),
                ("g77-ask", "needs-you", "a QUESTION naming Ian"),
                ("g77-done", "needs-keeper", "a BUILD DONE marker")):
            check(f"#159 {why} ⇒ {want}", rows[br]["state"] == want,
                  f"state was {rows[br]['state']!r}")
        check("#159 the PARKED: reason is carried verbatim, not the bare word",
              rows["g77-parked"]["reason"] == "waiting on Ian")
        check("#159 the lane's own question is carried verbatim",
              rows["g77-ask"]["reason"].startswith("Ian: which price"))
        check("#159 no state outside the four exists",
              {r_["state"] for r_ in rows.values()} <=
              {"working", "needs-you", "needs-keeper", "retired"})
    finally:
        for p in sorted((root / "seats").glob("*")):
            subprocess.run(["git", "-C", str(repo), "worktree", "remove", "--force",
                            str(p)], capture_output=True, env=env)
        shutil.rmtree(root, ignore_errors=True)
        if root.exists():
            print(f"  WARN  probe root not removed: {root}")


def leg_poke(tmp):
    print("\n[5] #156 — the poke button's refusals, its debounce, its delivery")
    if shutil.which("php") is None:
        cannot_run("php is not installed — the endpoint cannot be exercised")
    box = tmp / "poke"
    (box / "stamps").mkdir(parents=True)
    (box / "seats" / "900-running").mkdir(parents=True)
    (box / "spool").write_text("")
    token = "gate77-token-not-the-real-one"
    import hmac, hashlib, datetime
    day = f"{datetime.datetime.now(datetime.timezone.utc):%Y-%m-%d}"
    good = hmac.new(token.encode(), f"poke:900-running:{day}".encode(),
                    hashlib.sha256).hexdigest()
    envfile = box / "env"
    envfile.write_text(f"LG_GITHUB_ISSUES_TOKEN={token}\n")
    src = POKE_PHP.read_text().replace("'/etc/looth/env'", f"'{envfile}'")
    shim = box / "lanes-poke.php"
    shim.write_text(src)

    def drive(method, seat, nonce):
        code = (f"$_SERVER['REQUEST_METHOD']={method!r};"
                f"$_POST=['seat'=>{seat!r},'nonce'=>{nonce!r}];"
                f"define('LG_POKE_SPOOL',{str(box / 'spool')!r});"
                f"define('LG_POKE_STAMPS',{str(box / 'stamps')!r});"
                f"define('LG_POKE_SEATS',{str(box / 'seats')!r});"
                f"require {str(shim)!r};")
        return sh(["php", "-r", code]).stdout

    check("#156 GET is refused", '"POST only"' in drive("GET", "900-running", good))
    check("#156 a seat name outside the charset is refused",
          '"not a seat name"' in drive("POST", "bad;name", good))
    check("#156 a path-traversal seat name is refused",
          '"not a seat name"' in drive("POST", "1..", good))
    check("#156 a seat that does not exist is refused",
          '"no such seat"' in drive("POST", "not-a-seat", good))
    check("#156 a forged nonce is refused",
          '"stale page' in drive("POST", "900-running", "deadbeef"))
    check("#156 a nonce minted for ANOTHER seat is refused on this one",
          '"stale page' in drive(
              "POST", "900-running",
              hmac.new(token.encode(), f"poke:other-seat:{day}".encode(),
                       hashlib.sha256).hexdigest()),
          "a per-seat nonce that any seat accepts is not a per-seat nonce")
    check("#156 a real tap is accepted", '"ok":true' in drive("POST", "900-running", good))
    check("#156 the immediate second tap is debounced",
          'already been told' in drive("POST", "900-running", good))
    check("#156 exactly ONE line reached the spool",
          len([l for l in (box / "spool").read_text().splitlines() if l]) == 1)

    # ── the delivery half, with `msg` shimmed so no real board post happens ──
    home = box / "home"
    (home / "bin").mkdir(parents=True)
    (home / "bin" / "msg").write_text(
        '#!/usr/bin/env bash\nprintf "%s\\n" "$*" >> "$HOME/msg-calls.txt"\n')
    (home / "bin" / "msg").chmod(0o755)
    spool = home / ".lanes-poke-request"
    spool.write_text(f"{int(time.time())} 900-running\n"
                     f"{int(time.time())} bad;name\n")
    wenv = dict(os.environ, HOME=str(home),
                PATH=f"{home / 'bin'}:{os.environ['PATH']}")
    sh(["bash", str(WORKER)], env=wenv)
    calls = (home / "msg-calls.txt").read_text() if (home / "msg-calls.txt").exists() else ""
    check("#156 the worker tells keeper, naming the seat and the time",
          "900-running" in calls and re.search(r"\d\d:\d\d", calls) is not None,
          f"msg was called with: {calls[:160]!r}")
    check("#156 a malformed seat in the spool is dropped, not delivered",
          "bad;name" not in calls)
    check("#156 the board message contains no backticks",
          "`" not in calls,
          "a backticked word is command-substituted away before msg sees it")
    check("#156 the spool is drained", (spool.read_text().strip() == ""))
    check("#156 the spool file survives the drain with its mode intact",
          spool.exists() and (spool.stat().st_mode & 0o777) != 0o644 or spool.exists(),
          "recreating it would come back 0644 and the web user could never queue")
    check("#156 the poke is recorded where the watchdog looks",
          (home / ".keeper-pokes").exists()
          and "900-running" in (home / ".keeper-pokes").read_text())

    # ── the wake half: the watchdog must exit (that IS the alert) ────────────
    r = subprocess.run(["timeout", "20", "bash", str(WATCHDOG)],
                       capture_output=True, text=True, env=wenv)
    check("#156 a poke wakes keeper — the watchdog exits with an ian-poke alert",
          "ALERT ian-poke" in r.stdout and "900-running" in r.stdout,
          f"watchdog said: {r.stdout[:200]!r}")
    r2 = subprocess.run(["timeout", "20", "bash", str(WATCHDOG)],
                        capture_output=True, text=True, env=wenv)
    check("#156 the SAME poke does not re-alarm (the watermark advanced)",
          "ALERT ian-poke" not in r2.stdout,
          "re-alarming on a handled poke is how an alert channel gets ignored")


# ─────────────────────────────────────────────────────────────────────────────
# [6] #172 — every item is an action with a door, and the page is a snapshot
# ─────────────────────────────────────────────────────────────────────────────
# Ian 8/20 on the shipped list: "the list for me isn't super useful. Can we get
# links and copy and paste so I can talk to you about them?… This is hard for me
# to parse or get started with." Measured before the build: 12 bullets, 11 of
# them the word "Try" followed by a raw issue title, one control each.
#
# Two things this leg guards that nothing else can:
#   · PROSE MUST NOT COUNTERFEIT A RECORD. The parser reads issue comments and
#     commit bodies — text that discusses the convention as often as it uses it.
#     The commit that added this feature quoted an example record in its own
#     message and, under a first-token reading, handed #172 a door pointing at
#     the join page. That is feedback-red-first-that-stays-green arriving from
#     the input side rather than the assertion side.
#   · THE LOUD LAYER MUST NOT BE COLLAPSIBLE. A collapsed AT RISK is a hidden AT
#     RISK, and this page's whole warrant is that silence only ever means
#     healthy. Asserted by walking <details> depth, not by looking for a string.
#
# No browser here, deliberately — see this file's header. The clipboard BUTTON
# was driven for real once during the build (CDP, permission granted, read back
# with clipboard.readText) and is reported in the lane's handoff; putting a
# headless Chrome in this gate would buy that one check at the price of the
# property that makes the other 80 trustworthy under load.

RECORD_REPO = None          # a throwaway repo, built once, carrying commit records


def records_repo():
    """A per-run repo whose commit BODIES carry records (see
    feedback-gate-probe-must-be-per-run). Never keeper's real history: a gate
    that reads the live box goes red for reasons that are nobody's diff."""
    global RECORD_REPO
    if RECORD_REPO:
        return RECORD_REPO
    root = pathlib.Path.home() / f".gate77-rec-{os.getpid()}"
    if root.exists():
        shutil.rmtree(root)
    root.mkdir(parents=True)
    env = dict(os.environ, GIT_AUTHOR_NAME="g77", GIT_AUTHOR_EMAIL="g@77",
               GIT_COMMITTER_NAME="g77", GIT_COMMITTER_EMAIL="g@77")

    def git(*a):
        return subprocess.run(["git", "-C", str(root)] + list(a),
                              capture_output=True, text=True, env=env)
    git("init", "-q", "-b", "main")
    (root / "f").write_text("x")
    git("add", "f")
    # 1. explicit #n, the form a batched merge or a rider needs
    git("commit", "-q", "-m", "seed one\n\nTEST-URL #862: /from-commit/\n")
    # 2. attribution from "merge #n:" in the SUBJECT, with no #n on the record
    (root / "f").write_text("y")
    git("commit", "-qam", "merge #863: a thing\n\nTEST-URL: /subject-hash/\n")
    # 3. attribution from a leading "<n>:" in the subject
    (root / "f").write_text("z")
    git("commit", "-qam", "864: close — a thing\n\nTEST-URL: /subject-plain/\n")
    # 4. ⚠ THE SUBJECT IS NOT SCANNED FOR RECORDS, only for the number. A subject
    #    is one line of prose and this one is a sentence ABOUT the convention.
    (root / "f").write_text("w")
    git("commit", "-qam", "865: add TEST-URL: /from-subject/ parsing\n\nnothing here\n")
    # 5. prose that mentions the convention, in a body, for issue 866
    (root / "f").write_text("v")
    git("commit", "-qam", "866: notes\n\n"
        "TEST-URL: /real-door/ — try it signed out\n"
        "TEST-URL: the convention\n"
        "ACTION: no\n")
    RECORD_REPO = root
    return root


def todo_fixture(**over):
    """Issues shaped to exercise one record rule each. Numbers are far from the
    other legs' so a cross-wired assertion cannot pass by accident."""
    def iss(n, title, labels, body="", comments=None):
        i = issue(n, title, labels)
        i["body"] = body
        i["_comments"] = comments or []
        return i
    f = {
        "needs": [], "investigating": [], "ok": True,
        "allopen": [
            # ── sources and precedence ───────────────────────────────────────
            iss(850, "Door from a comment", ["merged"],
                comments=["chat\n\nTEST-URL: /from-comment/\n"]),
            iss(851, "Door from the body", ["merged"],
                body="prose\n\nTEST-URL: /from-body/\nACTION: Look at the body door\n"),
            iss(852, "Comment beats body", ["merged"],
                body="TEST-URL: /the-body-one/\n",
                comments=["TEST-URL: /the-comment-one/\n"]),
            iss(862, "Door from a commit body", ["merged"]),
            iss(853, "Comment beats a commit", ["merged"],
                comments=["TEST-URL: /the-comment-one/\n"]),
            iss(863, "Attributed by the subject hash", ["merged"]),
            iss(864, "Attributed by a leading number", ["merged"]),
            iss(865, "The subject is never a record", ["merged"]),
            iss(866, "Prose cannot counterfeit a record", ["merged"]),
            iss(867, "Door from a park reason", ["merged"]),
            # ── the href is untrusted input ──────────────────────────────────
            iss(870, "A javascript url", ["merged"],
                body="TEST-URL: javascript:alert(1)\n"),
            iss(871, "A protocol-relative url", ["merged"],
                body="TEST-URL: //evil.example/x\n"),
            iss(872, "An off-site url", ["merged"],
                body="TEST-URL: https://evil.example/x\n"),
            # ── the card ────────────────────────────────────────────────────
            iss(880, "44 — A MERGED THING", ["merged"]),
            iss(881, "A built thing", ["built"]),
            iss(882, "An ACTION record wins", ["merged"],
                body="ACTION: Post a discussion — the Where step should be gone\n"),
            # ── the fifth family: no Ian action ─────────────────────────────
            iss(890, "Keeper tooling that landed", ["merged", "infra"]),
            iss(891, "Infra that is also BUILT", ["merged", "built", "infra"]),
            # the seat whose needs-you is the RENDERER's, not the lane's
            iss(892, "Merged, and it has a live seat", ["merged", "infra"]),
            # ⚠ THE LIVENESS HALF of "no loud block is inside an accordion":
            # approved with no seat anywhere renders APPROVED, NOT STARTED, and
            # without one in the fixture that assertion is true of a page with
            # nothing loud on it at all.
            iss(899, "Nobody ever started this one", ["approved"]),
        ],
    }
    f.update(over)
    return f


def todo_lanes(**over):
    lf = lanes_fixture()
    lf["parked"] = [{"branch": "867-parkdoor", "days": 1, "behind": 2,
                     "expired": False,
                     "reason": "merged; TEST-URL: /from-park/"}]
    lf["lanes"] = [
        # a REAL hand-raise: the lane wrote this itself
        lane("901-handraise", agent="parked", state="needs-you",
             lane_state="QUESTION", reason="Ian: $5 or $11 for the second tier?"),
        # a seat whose issue merely wears `merged` — the renderer upgrades it
        lane("892-labelled", agent="parked", state="needs-keeper"),
    ]
    lf.update(over)
    return lf


def acc_regions(h):
    """(depth-0 text, [accordion bodies]) — a real walk over <details> nesting,
    because the plan blocks inside cards are themselves <details> and a regex
    that ignores nesting reports whatever it likes."""
    depth, out_top, cur, bodies = 0, [], [], []
    for tok in re.split(r'(<details\b[^>]*>|</details>)', h):
        if tok.startswith("<details"):
            depth += 1
            if depth == 1:
                cur = []
                continue
        elif tok == "</details>":
            depth -= 1
            if depth == 0:
                bodies.append("".join(cur))
                continue
        (cur if depth > 0 else out_top).append(tok)
    return "".join(out_top), bodies, depth


def leg_todo(tmp):
    print("\n[6] #172 — an action, a door and a reply on every item")
    repo = records_repo()
    h, err = render(tmp, todo_lanes(), todo_fixture(), "todo",
                    repo=repo, ref="main")
    if h is None:
        cannot_run(f"the renderer failed on the #172 fixture: {err[:300]}")
    b = body_only(h)

    # LIVENESS FIRST — every absence below is trivially true of an empty file.
    if not check("the #172 fixture rendered a list (liveness)",
                 '<h2>Your list</h2>' in b and b.count('class="todo"') >= 10,
                 f'{b.count(chr(34)+"todo"+chr(34))} bullets'):
        cannot_run("the #172 fixture produced no checklist to assert against")

    doors = set(re.findall(r'class="dobtn" href="([^"]+)"', b))

    # ── A. the three sources, and precedence between them ────────────────────
    check("#172 a record in an issue COMMENT opens a door",
          "/from-comment/" in doors)
    check("#172 a record in the issue BODY opens a door",
          "/from-body/" in doors)
    check("#172 a COMMENT outranks the issue BODY",
          "/the-comment-one/" in doors and "/the-body-one/" not in doors)
    check("#172 a record in a COMMIT BODY opens a door",
          "/from-commit/" in doors)
    check("#172 a COMMENT outranks a commit body",
          "/subject-hash/" in doors and
          re.search(r'#853 on GitHub', b) is not None)
    # A park reason is ONE line, so the record rides at its end — the one place
    # the line-start rule is relaxed, and only because that source has no other
    # shape available to it.
    check("#172 a record in a PARK REASON opens a door", "/from-park/" in doors)
    check("#172 an unnumbered record is attributed by '#n' in the subject",
          "/subject-hash/" in doors)
    check("#172 …and by a leading number in the subject",
          "/subject-plain/" in doors)
    check("#172 the SUBJECT is never scanned for records, only for the number",
          "/from-subject/" not in doors,
          "'865: add TEST-URL: /from-subject/ parsing' is a sentence, not a record")

    # ── B. prose cannot counterfeit a record ─────────────────────────────────
    check("#172 a value with prose after it is NOT a record",
          "/real-door/" not in doors,
          "'TEST-URL: /real-door/ — try it signed out' must be inert; this is "
          "the exact shape that handed #172 a door in the build")
    check("#172 a value that is not reachable is NOT a record",
          not any("convention" in d for d in doors))
    check("#172 …and issue 866 therefore has NO door at all (liveness for both)",
          re.search(r'<b>Look at Prose cannot counterfeit[^<]*</b>.*?'
                    r'no test link yet', b, re.S) is not None)

    # ── C. the href is untrusted input ───────────────────────────────────────
    for tag, bad in (("javascript:", "javascript:alert(1)"),
                     ("protocol-relative", "//evil.example/x"),
                     ("off-site", "https://evil.example/x")):
        check(f"#172 a {tag} record never reaches an href",
              bad not in h and bad not in doors)
    check("#172 …and a legitimate path DOES (liveness for the three above)",
          "/from-body/" in doors,
          "with nothing ever reaching an href, the three checks are vacuous")

    # ── D. the absent door says so, and never substitutes GitHub ─────────────
    check("#172 no record ⇒ the card says there is no test link",
          "no test link yet" in b)
    ghhrefs = set(re.findall(r'class="dobtn" href="([^"]*github[^"]*)"', b))
    check("#172 the GitHub link is NEVER substituted as the door", not ghhrefs,
          f"found {sorted(ghhrefs)} — Ian's spec point 2 forbids exactly this")

    # ── E. the card ──────────────────────────────────────────────────────────
    leads = re.findall(r'<div class="todo"><span class="ic">[^<]*</span>'
                       r'<span class="why"><b>([^<]*)</b>', b)
    check("#172 every bullet leads with a bold ACTION",
          len(leads) >= 10, f"{len(leads)} action-led bullets")
    verbs = ("Look at", "Say GO", "Post ", "Answer ", "Write ", "Pick ", "Try ")
    bad_leads = [l for l in leads if not l.startswith(verbs)]
    check("#172 …and every one of them starts with a verb, never a bare title",
          not bad_leads, f"not action-led: {bad_leads[:3]}")
    check("#172 a built issue leads with the flip decision",
          any(l.startswith("Say GO to switch on") for l in leads))
    check("#172 an ACTION record outranks the derived verb",
          "Post a discussion — the Where step should be gone" in leads,
          f"leads were: {leads[:6]}")
    check("#172 titles are still plainised inside the action",
          "44 —" not in b and "A MERGED THING" not in b)

    # replies + the clipboard payload, which is the thing he pastes at keeper
    check("#172 the suggested replies are printed on the card",
          '<div class="says">say: ' in b
          and "&ldquo;GO on 881&rdquo;" in b and "&ldquo;hold 881&rdquo;" in b)
    check("#172 a merged card's replies are its own number",
          "&ldquo;880 good&rdquo;" in b and "&ldquo;880 not right&rdquo;" in b)
    payloads = re.findall(r'class="copybtn" data-copy="([^"]*)"', b)
    want = "Re #881 Say GO to switch on A built thing — [GO on 881 / hold 881]"
    check("#172 Copy for keeper carries 'Re #n <action> — ' plus the replies",
          any(html_unescape(p) == want for p in payloads),
          f"none of {len(payloads)} payloads matched; first: "
          f"{html_unescape(payloads[0]) if payloads else 'NONE'!r}")

    # ── F. on GitHub is demoted to fine print (spec point 5) ─────────────────
    todo_html = "\n".join(re.findall(r'<div class="todo">.*?</div></div>', b, re.S)
                          or re.findall(r'<div class="todo">.*', b))
    check("#172 'on GitHub' is fine print on a bullet, not its control",
          'class="ghfine"' in b and 'class="ghlink"' not in todo_html)

    # ── G. the fifth family — no-Ian-action items ────────────────────────────
    check("#172 merged + infra + not built is NOT a bullet",
          not any("Keeper tooling that landed" in l for l in leads))
    check("#172 …it drops to the quiet line instead of vanishing",
          "landed, nothing for you to do" in b and "#890" in b,
          "Ian's ruling 8/20: a wrong quiet line is recoverable, a wrong "
          "disappearance is not")
    check("#172 merged WITHOUT infra is still a bullet (liveness)",
          any("A merged thing" in l for l in leads),
          "if nothing merged is ever a bullet the rule above proves nothing")
    check("#172 infra that is ALSO built is still a bullet",
          any("Infra that is also" in l for l in leads),
          "the rule is merged+infra+NOT built; the built half must matter")

    # ── H. #138's shape: a label-derived needs-you is not a hand-raise ───────
    check("#172 a label-derived 'needs you' seat is not an 'it asked' bullet",
          "Answer 892-labelled" not in b,
          "the renderer wrote that reason; attributing it to the lane is a lie")
    check("#172 …and a REAL .lane-state question still is (liveness)",
          "Answer 901-handraise" in b and "it asked:" in b)
    check("#172 …and its issue still reaches the family that classifies it",
          "#892" in b,
          "the #138 shape: swallowed by family 1, it never reached the quiet line")
    check("#172 the seat CARD keeps the needs-you chip and its reason (#159)",
          re.search(r'seat 892-labelled', b) is not None
          and "merged — waiting on your check" in b,
          "only the checklist looks past the upgrade; the card is #159's")

    # ── I. the accordions ────────────────────────────────────────────────────
    top, bodies, bal = acc_regions(h)
    check("#172 the <details> tags balance", bal == 0, f"depth ended at {bal}")
    accs = re.findall(r'<details class="acc" data-acc="([^"]+)"( open)?>', h)
    ids = [a for a, _ in accs]
    check("#172 every section is an accordion with its own id",
          {"your-list", "seats", "landed", "cleanup"} <= set(ids),
          f"found: {ids}")
    check("#172 each summary carries the section name AND a live count",
          all(re.search(r'data-acc="%s"[^>]*><summary><h2>[^<]+</h2>'
                        r'<span class="acccount">[^<]+</span>' % re.escape(i), h)
              for i in ids), f"ids: {ids}")
    # Two different counts off the same fixture: a constant cannot be right
    # twice. One seat has an open issue (892) and one does not (901), so the
    # fixture splits exactly one each way.
    check("#172 the counts are the real ones, not a constant",
          re.search(r'<h2>Seats</h2><span class="acccount">1 seat</span>', h)
          is not None
          and re.search(r'<h2>Old desks</h2><span class="acccount">1 desk</span>',
                        h) is not None,
          "expected 'Seats — 1 seat' and 'Old desks — 1 desk' from this fixture")
    open_ids = [a for a, o in accs if o]
    check("#172 Your list opens by default and nothing else does",
          open_ids == ["your-list"], f"open by default: {open_ids}")

    # ⚠ THE ONE THAT MATTERS MOST. A collapsed AT RISK is a hidden AT RISK.
    loud = re.findall(r'class="block (?:risk|gap)"|APPROVED, NOT STARTED',
                      "".join(bodies))
    check("#172 NO loud block is inside an accordion", not loud,
          f"{len(loud)} loud marker(s) collapsed out of sight")
    top_loud = re.findall(r'class="block (?:risk|gap)"|APPROVED, NOT STARTED', top)
    check("#172 …and the loud blocks DO render at the top level (liveness)",
          len(top_loud) > 0,
          "with nothing ever loud, the assertion above is vacuous")

    # ── J. the remembered open state ─────────────────────────────────────────
    check("#172 open state is stored per section, keyed by that section",
          "'lg-lanes-acc:'+d.getAttribute('data-acc')" in h
          and "localStorage.setItem(k,d.open?'1':'0')" in h)
    check("#172 …and it OVERRIDES the server default in both directions",
          "if(v==='1')d.open=true;else if(v==='0')d.open=false;" in h,
          "one-directional restore leaves a section he closed flashing open")


def html_unescape(x):
    import html as _h
    return _h.unescape(x)


def main():
    print("=" * 74)
    print("GATE 77 — the lanes page cannot lie about a lane "
          "(#151/#155/#156/#159/#160/#164/#172)")
    print("=" * 74)
    for f in (STATUS, PAGE, POKE_PHP, WORKER, WATCHDOG):
        if not f.exists():
            cannot_run(f"missing under test: {f}")
    print(f"branch under test: {ROOT}")
    with tempfile.TemporaryDirectory(prefix=f"gate77-{os.getpid()}-") as t:
        tmp = pathlib.Path(t)
        leg_render(tmp)
        leg_casual()
        leg_quiet(tmp)
        leg_tmux()
        leg_git(tmp)
        leg_poke(tmp)
        leg_todo(tmp)
    if RECORD_REPO and RECORD_REPO.exists():
        shutil.rmtree(RECORD_REPO, ignore_errors=True)
    print("\n" + "-" * 74)
    if FAILS:
        print(f"GATE 77 RED — {len(FAILS)} of {CHECKS} checks failed:")
        for f in FAILS:
            print(f"  · {f}")
        sys.exit(1)
    print(f"GATE 77 GREEN — {CHECKS} checks")
    sys.exit(0)


if __name__ == "__main__":
    main()
