#!/usr/bin/env python3
"""GATE 82 — approval may start work by itself, but only PRE-STAGED work.

Guards #138 phase B. Ian's ruling 8/20 (verbatim): "If a lane goes Idle waiting
for me to make a decision, I want the next work in line to start up while I'm
screwing around." The watcher that does it can spawn paid sessions on a 2-core
box with nobody watching, so every one of its refusals is load-bearing and every
one is asserted here.

WHY THIS GATE EXISTS AT ALL. The failure mode is not "a lane fails to start" —
that is visible within five minutes. It is the reverse: a watcher that spins
when it should not. Nine parked branches on this box wear open+approved issues
whose charters are still on disk; a watcher missing its one-spin-ever backstop
re-spins the lot, on two cores, at once. Nothing in the fleet would stop it.

THREE THINGS IT DOES NOT DO, on purpose (gate 77's rules, same reasons):
  · no network — issues arrive as a fixture, so it cannot flake on an API blip
    and cannot burn rate limit.
  · no real seat and no claude process — the spin command is a recorder. A gate
    that spawned a lane to prove a lane spawns would cost more than the defect.
  · no writing to the real box — state dir, prompts dir, worktree root, manifest,
    bell and the keeper-quiet paths are all redirected into a per-run temp dir.
    ⚠ THE QUIET PATH ESPECIALLY: a gate that touched the real /tmp/keeper-quiet
    would set a box-wide hold on the whole fleet and then delete it, and the
    window between is a fleet that silently stops starting work.

⚠️ ABSENCE ASSERTIONS ARE PAIRED WITH LIVENESS, EVERY TIME. "It did not spin" is
true of a broken script, an empty fixture, and a box with no watcher at all. So
each refusal leg runs a control in the SAME harness where the same issue DOES
spin, one condition apart. Leg 5 is the same law applied to the script's own
production defaults: it proved a real bug during the build — tmux's
'#{session_name}' inside a ${VAR:-default} closes the expansion on its own brace,
so the session list would have been empty forever, silently disabling the
"already running" guard while the fleet-down hold jammed on.

Exit: 0 green · 1 RED (real findings) · 2 CANNOT RUN (no verdict).
⚠️ CANNOT RUN IS 2, NOT 3 — run-all.sh reads anything-else-non-zero as RED, so a
wrong code reports a missing environment as a finding and blocks every lane.
"""
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import time

HERE = pathlib.Path(__file__).resolve().parent
ROOT = HERE.parent.parent                       # the branch under test
WATCHER = ROOT / "tools" / "approved-watcher.sh"

FAILS = []
CHECKS = 0


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
    print(f"\nGATE 82 CANNOT RUN — {why}")
    print("No verdict. This is not a pass.")
    sys.exit(2)


# ─────────────────────────────────────────────────────────────────────────────
# the world: one temp box per run
# ─────────────────────────────────────────────────────────────────────────────
class World:
    """A throwaway box. Nothing here touches the real one."""

    def __init__(self, tmp, name):
        self.d = pathlib.Path(tmp) / name
        for sub in ("state", "prompts", "wt", "bin", "repo"):
            (self.d / sub).mkdir(parents=True, exist_ok=True)
        self.board = self.d / "board.log"
        self.spins = self.d / "spins.log"
        for rec, var in ((self.d / "bin" / "rec-msg", "board.log"),
                         (self.d / "bin" / "rec-spin", "spins.log")):
            rec.write_text("#!/usr/bin/env bash\nprintf '%s\\n' \"$*\" >> "
                           f"'{self.d}/{var}'\n")
            rec.chmod(0o755)
        # a git repo the branch-existence backstop can be asked about
        self.git(["init", "-q", "-b", "main"], cwd=self.d / "repo")
        self.git(["commit", "-q", "--allow-empty", "-m", "init"], cwd=self.d / "repo")
        self.issues = []
        self.lanes = {"capacity": {"seats_used": 2, "seat_ceiling": 6,
                                   "working_cap": 3},
                      "lanes": [{"agent": "working"}, {"agent": "parked"}]}

    def git(self, args, cwd=None):
        return subprocess.run(["git", "-c", "user.email=g82@x", "-c", "user.name=g82"]
                              + args, cwd=str(cwd or self.d / "repo"),
                              capture_output=True, text=True, timeout=60)

    def issue(self, n, title="staged"):
        self.issues.append({"number": n, "title": title})
        return self

    def charter(self, name):
        (self.d / "prompts" / f"{name}.md").write_text("charter body\n")
        return self

    def worktree(self, lane, branch=None):
        wt = self.d / "wt" / lane
        wt.mkdir(parents=True, exist_ok=True)
        self.git(["init", "-q", "-b", branch or lane], cwd=wt)
        self.git(["commit", "-q", "--allow-empty", "-m", "init"], cwd=wt)
        return self

    def branch_only(self, lane):
        """a branch with no worktree — the parked/merged lane shape"""
        self.git(["branch", lane])
        return self

    def working(self, n, cap=3, seats=2, ceiling=6):
        self.lanes = {"capacity": {"seats_used": seats, "seat_ceiling": ceiling,
                                   "working_cap": cap},
                      "lanes": [{"agent": "working"}] * n + [{"agent": "parked"}]}
        return self

    def state(self, fname, body):
        (self.d / "state" / fname).write_text(body)
        return self

    def run(self, sessions="true", load="0.5", extra_env=None, default_sessions=False):
        (self.d / "issues.json").write_text(json.dumps(self.issues))
        (self.d / "lanes.json").write_text(json.dumps(self.lanes))
        for f in (self.board, self.spins):
            f.unlink(missing_ok=True)
        env = dict(os.environ)
        env.update({
            "LG_AW_STATE_DIR": str(self.d / "state"),
            "LG_AW_PROMPTS": str(self.d / "prompts"),
            "LG_AW_WORKTREES": str(self.d / "wt"),
            "LG_AW_REPO": str(self.d / "repo"),
            "LG_AW_LANES_CMD": f"cat {self.d}/lanes.json",
            "LG_AW_SPIN_CMD": str(self.d / "bin" / "rec-spin"),
            "LG_AW_MSG_CMD": str(self.d / "bin" / "rec-msg"),
            # ⚠ the real one is /tmp/keeper-quiet — see the module docstring
            "LG_AW_QUIET_FILES": str(self.d / "quiet"),
            "LG_AW_MANIFEST": str(self.d / "manifest"),
            "LG_AW_BELL": str(self.d / "bell"),
            "LG_AW_ISSUES_JSON": str(self.d / "issues.json"),
            "LG_AW_LOAD": load,
        })
        if not default_sessions:
            env["LG_AW_SESSIONS_CMD"] = sessions
        else:
            env.pop("LG_AW_SESSIONS_CMD", None)
        env.update(extra_env or {})
        r = subprocess.run(["bash", str(WATCHER)], env=env, capture_output=True,
                           text=True, timeout=120)
        board = self.board.read_text() if self.board.exists() else ""
        spins = self.spins.read_text() if self.spins.exists() else ""
        return r.returncode, board, spins


def staged(w, n=900, lane="900-staged"):
    """the shape phase B exists for: approved + charter + cut worktree"""
    return w.issue(n).charter(lane).worktree(lane)


# ─────────────────────────────────────────────────────────────────────────────
def leg_charter(tmp):
    print("\n[1] CHARTER REQUIRED — approval alone never spins (7/27)")
    # ⚠ THE FIXTURE CARRIES A CUT WORKTREE ON PURPOSE. Without it this world has
    # nothing that could spin even with the charter rule deleted, and the
    # assertion below would pass for the wrong reason (red-first proved exactly
    # that). "Seat cut, charter never written" is also the 7/27 shape itself.
    w = World(tmp, "charterless").issue(901, "approved, no charter")
    w.worktree("901-nocharter")
    w.state(".autospin-mode", "live\n")
    rc, board, spins = w.run()
    check("a charterless approved issue does NOT spin",
          spins.strip() == "", f"it spun: {spins!r}")
    check("…and it still RINGS — phase A is not collateral damage",
          "#901 is APPROVED" in board, f"board was {board!r}")
    check("…and the bell file is touched", (w.d / "bell").exists())

    # LIVENESS: the same harness, one file added, must spin. Without this the
    # assertion above is satisfied by a script that does nothing at all.
    w2 = staged(World(tmp, "charterful")).state(".autospin-mode", "live\n")
    rc, board, spins = w2.run()
    check("…while an issue WITH a charter spins in the same harness (liveness)",
          "900-staged" in spins, f"spins were {spins!r}")

    w3 = staged(World(tmp, "twocharters")).charter("900-other")
    w3.state(".autospin-mode", "live\n")
    rc, board, spins = w3.run()
    check("two charters for one issue ⇒ refuse, never guess",
          spins.strip() == "" and "two charters" in board, f"{board!r} / {spins!r}")
    check("…and the refusal NAMES both files, so keeper can act on it",
          "900-other.md" in board and "900-staged.md" in board, board)


def leg_cap(tmp):
    print("\n[2] the WORKING cap — counted with lanes-status's own detector")
    w = staged(World(tmp, "atcap")).working(3, cap=3).state(".autospin-mode", "live\n")
    rc, board, spins = w.run()
    check("at the cap, nothing spins", spins.strip() == "", f"it spun: {spins!r}")
    check("…and it says so, with the numbers",
          "3/3" in board and "cap" in board.lower(), board)

    w2 = staged(World(tmp, "undercap")).working(2, cap=3).state(".autospin-mode", "live\n")
    rc, board, spins = w2.run()
    check("one under the cap, the same fixture spins (liveness)",
          "900-staged" in spins, f"spins were {spins!r}")

    # A cap that cannot be read is not a free pass. Silence here would mean
    # "healthy" and it is not (silence-only-means-healthy, inverted).
    w3 = staged(World(tmp, "capunreadable")).state(".autospin-mode", "live\n")
    rc, board, spins = w3.run(extra_env={"LG_AW_LANES_CMD": "echo not-json"})
    check("an UNREADABLE capacity refuses — it is not a cap of zero",
          spins.strip() == "" and "capacity" in board, f"{board!r} / {spins!r}")


def leg_once(tmp):
    print("\n[3] ONE SPIN PER ISSUE EVER")
    w = staged(World(tmp, "twice")).state(".autospin-mode", "live\n")
    rc, board, spins = w.run()
    check("the first tick spins", "900-staged" in spins, spins)
    log = (w.d / "state" / ".autospin-log").read_text()
    check("…and the issue is recorded", "900" in log, f"log={log!r}")
    rc, board, spins = w.run()
    check("the second tick does NOT spin the same issue",
          spins.strip() == "", f"it re-spun: {spins!r}")

    # THE BACKSTOP, load-bearing on day one: nine parked branches on this box
    # wear open+approved issues whose charters are still on disk. A watcher with
    # only the log would re-spin all of them the moment it went live.
    w2 = World(tmp, "parkedbranch").issue(900).charter("900-staged")
    w2.branch_only("900-staged").state(".autospin-mode", "live\n")
    rc, board, spins = w2.run()
    check("a branch that exists with NO worktree is a prior lane ⇒ refuse",
          spins.strip() == "" and "prior lane" in board, f"{board!r} / {spins!r}")
    check("…even though nothing about it is in the one-spin log (liveness)",
          not (w2.d / "state" / ".autospin-log").read_text().strip(),
          "the log was non-empty, so the refusal may have come from the log")

    w3 = World(tmp, "livesession").issue(900).charter("900-staged").worktree("900-staged")
    w3.state(".autospin-mode", "live\n")
    rc, board, spins = w3.run(sessions="echo 900-staged")
    check("a seat with a live session is already running ⇒ no second spin",
          spins.strip() == "", f"it spun: {spins!r}")
    rc, board, spins = w3.run(sessions="echo some-other-lane")
    check("…and with a DIFFERENT session live it spins (liveness)",
          "900-staged" in spins, spins)


def leg_mode(tmp):
    print("\n[4] DRY-RUN is the default, and the flip is a FILE")
    w = staged(World(tmp, "dryrun"))
    rc, board, spins = w.run()
    check("with no mode file at all, nothing spins",
          spins.strip() == "", f"it spun: {spins!r}")
    check("…but it POSTS what it would have done",
          "WOULD SPIN" in board and "900-staged" in board, board)
    check("…naming the flip command, so keeper needs no code edit",
          "echo live" in board, board)
    rc, board, spins = w.run()
    check("a dry run announces ONCE, not every five minutes",
          "WOULD SPIN" not in board, f"re-announced: {board!r}")
    # If a dry run consumed the one-spin-ever record, the flip would arm a
    # watcher with nothing left to fire at — the whole feature, dead on arrival.
    check("…and it consumes NOTHING of the one-spin-ever record",
          not (w.d / "state" / ".autospin-log").read_text().strip(),
          "the dry run wrote the spun-log")
    w.state(".autospin-mode", "live\n")
    rc, board, spins = w.run()
    check("after the flip, the SAME issue spins for real",
          "900-staged" in spins, f"spins were {spins!r}")

    w2 = staged(World(tmp, "modejunk")).state(".autospin-mode", "yes please\n")
    rc, board, spins = w2.run()
    check("a mode file that does not say 'live' stays dry — no fuzzy arming",
          spins.strip() == "", f"it spun on {'yes please'!r}")


def leg_holds(tmp):
    print("\n[5] the holds — keeper-quiet, fleet-down, load")
    for label, setup, needle in (
        ("keeper-quiet", lambda w: (w.d / "quiet").write_text(""), "keeper-quiet"),
        ("fleet-down (reboot signature)",
         lambda w: (w.d / "manifest").write_text("oldlane\n"), "fleet is DOWN"),
    ):
        for mode in ("live\n", None):
            w = staged(World(tmp, f"hold-{label[:5]}-{'live' if mode else 'dry'}"))
            if mode:
                w.state(".autospin-mode", mode)
            setup(w)
            rc, board, spins = w.run()
            check(f"{label} blocks the spin ({'live' if mode else 'dry-run'} mode)",
                  spins.strip() == "" and needle in board,
                  f"{board!r} / {spins!r}")

    w = staged(World(tmp, "loadhold")).state(".autospin-mode", "live\n")
    rc, board, spins = w.run(load="9.9")
    check("load over the ceiling blocks the spin",
          spins.strip() == "" and "load" in board, f"{board!r} / {spins!r}")
    rc, board, spins = w.run(load="0.4")
    check("…and under it, the same fixture spins (liveness)",
          "900-staged" in spins, spins)

    # Edge-triggered: a hold that reposts every five minutes trains keeper to
    # stop reading the board, which costs more than the hold it announces.
    w2 = staged(World(tmp, "holdrepeat")).state(".autospin-mode", "live\n")
    (w2.d / "quiet").write_text("")
    w2.run()
    rc, board, spins = w2.run()
    check("a standing hold is announced once, not on every tick",
          "HELD" not in board, f"re-announced: {board!r}")
    (w2.d / "quiet").unlink()
    rc, board, spins = w2.run()
    check("…and its CLEARING is announced too",
          "hold cleared" in board, board)


def leg_worktree(tmp):
    print("\n[6] the seat itself — folder==branch, and the upstream rule (75a0fb6)")
    w = World(tmp, "mismatch").issue(900).charter("900-staged")
    w.worktree("900-staged", branch="something-else").state(".autospin-mode", "live\n")
    rc, board, spins = w.run()
    check("a worktree on the wrong branch ⇒ refuse, never repair",
          spins.strip() == "" and "folder and branch must match" in board,
          f"{board!r} / {spins!r}")
    br = subprocess.run(["git", "-C", str(w.d / "wt" / "900-staged"), "rev-parse",
                         "--abbrev-ref", "HEAD"], capture_output=True, text=True)
    check("…and it did NOT check the worktree out from under anybody",
          br.stdout.strip() == "something-else",
          f"the branch became {br.stdout.strip()!r}")

    w2 = staged(World(tmp, "noseats")).working(1, cap=3, seats=6, ceiling=6)
    shutil.rmtree(w2.d / "wt" / "900-staged")
    w2.state(".autospin-mode", "live\n")
    rc, board, spins = w2.run()
    check("a seat that must be CUT is refused at the seat ceiling",
          spins.strip() == "" and "6/6" in board, f"{board!r} / {spins!r}")

    # The real cut, with real git against a real (local, offline) bare origin.
    w3 = World(tmp, "cut").issue(902, "no worktree yet").charter("902-fresh")
    w3.state(".autospin-mode", "live\n").working(1, cap=3, seats=2, ceiling=6)
    bare = w3.d / "origin.git"
    subprocess.run(["git", "init", "-q", "--bare", str(bare)],
                   check=True, capture_output=True)
    shutil.rmtree(w3.d / "repo")
    subprocess.run(["git", "clone", "-q", str(bare), str(w3.d / "repo")],
                   check=True, capture_output=True)
    w3.git(["commit", "-q", "--allow-empty", "-m", "init"])
    w3.git(["branch", "-M", "main"])
    w3.git(["push", "-q", "-u", "origin", "main"])
    rc, board, spins = w3.run()
    wt = w3.d / "wt" / "902-fresh"
    check("a missing worktree is cut, and the lane spins",
          wt.is_dir() and "902-fresh" in spins, f"{board!r} / {spins!r}")
    if wt.is_dir():
        head = subprocess.run(["git", "-C", str(wt), "rev-parse", "--abbrev-ref",
                               "HEAD"], capture_output=True, text=True).stdout.strip()
        up = subprocess.run(["git", "-C", str(wt), "rev-parse", "--abbrev-ref",
                             "--symbolic-full-name", "@{u}"],
                            capture_output=True, text=True).stdout.strip()
        check("…the cut worktree is on its own branch (folder==branch)",
              head == "902-fresh", f"HEAD is {head!r}")
        # A worktree cut from origin/main TRACKS MAIN. A bare push from the lane
        # would then target main and bypass the plan-mode wall entirely — caught
        # live on lanes 165 and 170 before it could.
        check("…and its upstream IS origin/<lane>, never origin/main",
              up == "origin/902-fresh", f"upstream is {up!r}")


def leg_defaults(tmp):
    print("\n[7] the PRODUCTION defaults are live — not just the overrides")
    if shutil.which("tmux") is None:
        cannot_run("tmux is not installed — the default session probe cannot be exercised")
    lane = f"9902-g82-{os.getpid()}"
    subprocess.run(["tmux", "kill-session", "-t", lane], capture_output=True)
    w = World(tmp, "defaults").issue(9902).charter(lane).worktree(lane)
    w.state(".autospin-mode", "live\n")
    r = subprocess.run(["tmux", "new-session", "-d", "-s", lane, "-x", "80", "-y", "10",
                        "bash", "-c", "sleep 90"], capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"could not start a probe tmux session: {r.stderr[:200]}")
    try:
        time.sleep(0.5)
        # ⚠ THE BUG THIS LEG EXISTS FOR. Written as
        #   SESSIONS_CMD="${LG_AW_SESSIONS_CMD:-tmux list-sessions -F #{session_name}}"
        # the } of tmux's format string closes the parameter expansion, and the
        # stray } is appended as its own word. tmux then errors, the list is
        # EMPTY FOREVER, "already running" can never fire and fleet-down jams on.
        # Every override-driven leg above passes with that bug present.
        rc, board, spins = w.run(default_sessions=True)
        check("the DEFAULT session command sees a real tmux session",
              spins.strip() == "",
              f"it spun a lane that is already running: {spins!r} / board {board!r}")
        check("…and did not mistake a live fleet for a DOWN one",
              "fleet is DOWN" not in board, board)
    finally:
        subprocess.run(["tmux", "kill-session", "-t", lane], capture_output=True)
    rc, board, spins = w.run(default_sessions=True)
    check("…and once that session is gone, the same lane spins (liveness)",
          lane in spins, f"spins were {spins!r}")


def main():
    print("GATE 82 — approval auto-spins PRE-STAGED work, and nothing else\n"
          f"watcher under test: {WATCHER}")
    if not WATCHER.exists():
        cannot_run(f"no watcher at {WATCHER}")
    if shutil.which("git") is None:
        cannot_run("git is not installed")
    with tempfile.TemporaryDirectory(prefix=f"gate82-{os.getpid()}-") as tmp:
        leg_charter(tmp)
        leg_cap(tmp)
        leg_once(tmp)
        leg_mode(tmp)
        leg_holds(tmp)
        leg_worktree(tmp)
        leg_defaults(tmp)
    print(f"\n{CHECKS} checks, {len(FAILS)} failed")
    if FAILS:
        print("\nGATE 82 RED:")
        for f in FAILS:
            print(f"  · {f}")
        sys.exit(1)
    print("GATE 82 GREEN")
    sys.exit(0)


if __name__ == "__main__":
    main()
