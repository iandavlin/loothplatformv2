#!/usr/bin/env python3
"""
dev-live-split-gate.py — dev material must not ride to live, and must never be
reachable over HTTP (docs/DEV-LIVE-SPLIT-PLAN.md, dev-live-split lane 2026-08-01).

THREE assertions, because the failure modes are genuinely different:

  A. STRUCTURE — no dev/test/fixture directory inside a tree that live symlinks
     into a DOCROOT. The 7/31 nginx rules block the wp-content ones, but a guard
     is not a structure: the rules are anchored to ^/wp-content/(plugins|
     mu-plugins)/, and /v2/ reaches the SAME files through a /srv alias one
     directory over. A is what makes the class impossible rather than blocked.

  B. REACHABILITY — no dev-only path answers over HTTP. This is the assertion
     that actually caught something: /v2/mockup/*.html and /v2/tests/fixtures/
     *.json serve 200 to an ANONYMOUS request on live today, because
     `location ^~ /v2/ { alias /srv/lg-layout-v2/; }` carries no auth directive
     despite a comment claiming "(static, gated)".

  C. MANIFEST COMPLETENESS — every top-level path is classified. A new top-level
     directory that nobody classified must FAIL LOUD, not silently default. Once
     live runs a sparse-checkout, an unclassified path silently does not deploy,
     which is worse than noise.

Exit codes follow run-all.sh's three states (see that file's header):
    0 = green   1 = RED (real findings)   2 = CANNOT RUN (no verdict)
Note 2, not 3/70 — an exit run-all.sh does not understand reports a missing
environment as a finding and blocks every lane.

B needs an origin to talk to. With no reachable origin it returns 2 (CANNOT RUN),
never 0 — a dead origin answering nothing must not read as "nothing is exposed".
Every run asserts a CONTROL url first to prove the prober is alive.

Run standalone:
    python3 tools/gates/dev-live-split-gate.py
    LG_SPLIT_ORIGIN=127.0.0.1 LG_SPLIT_HOST=loothgroup.com python3 tools/gates/...
"""

from __future__ import annotations

import os
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]

# --- classification ---------------------------------------------------------
# LIVE_SERVING is DERIVED, not guessed: it is the set of top-level roots reached
# by resolving all 122 symlinks on live back into the serving checkout.
# DOCROOT_SYMLINKED is the subset symlinked as a WHOLE DIRECTORY into a docroot
# (wp-content/plugins, wp-content/mu-plugins) — everything under those is web
# addressable, so this is the only set assertion A can meaningfully police.
DOCROOT_SYMLINKED = {
    "lg-layout-v2",              # -> wp-content/plugins/ AND /srv (aliased at / by /v2/)
    "lg-legacy-import",          # -> wp-content/plugins/
    "lg-snippets",               # -> wp-content/plugins/
    "lg-weekly-digest",          # -> wp-content/plugins/
    "lg-patreon-stripe-poller",  # -> wp-content/mu-plugins/
}

# Symlinked wholesale into /srv, but nginx aliases only a web/ or api/ subdir,
# so sibling bin/ and tests/ are structurally unreachable. lg-layout-v2 is the
# documented exception (the /v2/ block aliases its ROOT) and is listed above.
SRV_SYMLINKED = {
    "archive-poc", "bb-mirror", "events", "lg-shared",
    "membership-pages", "profile-app",
}

# Only named files exist on the live side; adding a file here does not expose it.
FILE_SYMLINKED = {"platform", "webroot", "bug-report"}

# Not symlinked, but live code all the same — installed on live as REAL dirs
# (copy-deployed). A symlink-only heuristic would wrongly exile these.
LIVE_INSTALLED = {
    "lg-apps", "lg-anonymous-authors", "lg-recent-posts-widget",
    "event-reminder-and-cleaner", "lg-push",
}

# In the deploy and read/run ON live, though never served over HTTP.
# cutover/ is run by Ian on live; docs/ holds docs/runbooks/ which is read during
# a deploy — so docs/ is NOT dev-only wholesale.
LIVE_OPERATOR = {"cutover", "docs"}

DEV_ONLY = {
    "footer-mockups", "evidence", "evidence-c", "tools", "run-reactions",
    "guitardle", "lg-shell", "deploy", "fast-follow",
    # Own repo on live (github.com/iandavlin/lg-stripe-billing), deployed
    # independently; the monorepo copy serves live through no path at all.
    "lg-stripe-billing",
}

# Directory names that mean "dev material" when found inside a served tree.
DEV_DIR_NAMES = {
    "dev", "test", "tests", "fixture", "fixtures", "spec", "__tests__",
    "previs", "sandbox", "scratch", "mockup", "mockups", "e2e",
}

# Dev-only paths that MUST NOT answer over HTTP. (url, why)
FORBIDDEN_URLS = [
    ("/v2/mockup/render-pipeline.html", "lg-layout-v2 mockup via the /v2/ alias"),
    ("/v2/mockup/editor-pipeline.html", "lg-layout-v2 mockup via the /v2/ alias"),
    ("/v2/tests/fixtures/simple-article.json", "lg-layout-v2 test fixture via /v2/"),
    ("/v2/tests/fixtures/edge-cases.json", "lg-layout-v2 test fixture via /v2/"),
    ("/footer-mockups/", "footer mockups symlinked into the docroot"),
    ("/wp-content/plugins/lg-weekly-digest/dev/run-suite.sh", "digest dev suite"),
    ("/wp-content/plugins/lg-layout-v2/tests/", "layout test dir under wp-content"),
]

# Proves the prober is alive. Without this, every "not reachable" is vacuous —
# a dead origin returns nothing for everything and that reads as green.
CONTROL_URL = "/robots.txt"


def sh(cmd: list[str]) -> tuple[int, str]:
    p = subprocess.run(cmd, capture_output=True, text=True)
    return p.returncode, (p.stdout or "") + (p.stderr or "")


# --- A. structure -----------------------------------------------------------
def check_structure() -> list[str]:
    findings = []
    for root in sorted(DOCROOT_SYMLINKED):
        base = REPO / root
        if not base.is_dir():
            continue
        for d in sorted(base.rglob("*")):
            if not d.is_dir() or d.is_symlink():
                continue
            if d.name.lower() not in DEV_DIR_NAMES:
                continue
            # vendor/ is third-party and unmovable; the nginx rules cover it.
            if "vendor" in d.relative_to(REPO).parts:
                continue
            n = sum(1 for f in d.rglob("*") if f.is_file())
            findings.append(
                f"{d.relative_to(REPO)}  ({n} files) sits INSIDE a tree live "
                f"symlinks into a docroot"
            )
    return findings


# --- B. reachability --------------------------------------------------------
def check_reachability() -> tuple[list[str], str | None]:
    """Returns (findings, cannot_run_reason)."""
    origin = os.environ.get("LG_SPLIT_ORIGIN", "127.0.0.1")
    host = os.environ.get("LG_SPLIT_HOST", "dev2.loothgroup.com")

    def fetch(path: str) -> tuple[int, int] | None:
        rc, out = sh([
            "curl", "-sS", "-k", "-o", "/dev/null",
            "-w", "%{http_code} %{size_download}",
            "--max-time", "15",
            "--resolve", f"{host}:443:{origin}",
            f"https://{host}{path}",
        ])
        if rc != 0:
            return None
        try:
            code, size = out.strip().split()[:2]
            return int(code), int(size)
        except ValueError:
            return None

    control = fetch(CONTROL_URL)
    if control is None or control[0] != 200:
        got = "no response" if control is None else f"HTTP {control[0]}"
        return [], (
            f"control {CONTROL_URL} returned {got} against {host}@{origin} — the "
            f"prober is not talking to a live origin, so every 'not reachable' "
            f"result below would be vacuous"
        )

    findings = []
    for path, why in FORBIDDEN_URLS:
        r = fetch(path)
        if r is None:
            continue
        code, size = r
        # 200 with a body = served. Cloudflare's "Just a moment..." interstitial
        # is a BOT CHALLENGE, not access control, so it is never counted as safe
        # here — this gate runs against the origin precisely to avoid that lie.
        if code == 200 and size > 0:
            findings.append(f"HTTP {code} ({size}B)  {path}  — {why}")
    return findings, None


# --- C. manifest completeness ----------------------------------------------
def check_manifest() -> list[str]:
    known = (DOCROOT_SYMLINKED | SRV_SYMLINKED | FILE_SYMLINKED
             | LIVE_INSTALLED | LIVE_OPERATOR | DEV_ONLY)
    rc, out = sh(["git", "-C", str(REPO), "ls-files"])
    if rc != 0:
        return ["could not list tracked files"]
    tops = {p.split("/")[0] for p in out.splitlines() if "/" in p}
    return [
        f"top-level path '{t}' is not classified — add it to the manifest in "
        f"{Path(__file__).name} (silently not deploying is worse than noise)"
        for t in sorted(tops - known)
    ]


def main() -> int:
    red = False
    dead_reason = None

    print("=== A. no dev/test dir inside a docroot-symlinked tree ===")
    a = check_structure()
    if a:
        red = True
        for f in a:
            print(f"  RED  {f}")
        print(f"  -> {len(a)} dev directories are web-addressable by structure.")
        print("     A guard is holding them, not the layout. See "
              "docs/DEV-LIVE-SPLIT-PLAN.md §4 L1b.")
    else:
        print("  ok — no dev/test dirs inside docroot-symlinked trees")

    print("\n=== B. no dev-only path answers over HTTP ===")
    b, dead_reason = check_reachability()
    if dead_reason:
        print(f"  CANNOT RUN — {dead_reason}")
    elif b:
        red = True
        for f in b:
            print(f"  RED  {f}")
        print("  -> dev material is being SERVED. See "
              "docs/DEV-LIVE-SPLIT-PLAN.md §0 F1/F2.")
    else:
        print("  ok — no forbidden path served")

    print("\n=== C. every top-level path is classified ===")
    c = check_manifest()
    if c:
        red = True
        for f in c:
            print(f"  RED  {f}")
    else:
        print("  ok — manifest covers every tracked top-level path")

    if red:
        print("\nRESULT: RED")
        return 1
    if dead_reason:
        print("\nRESULT: CANNOT RUN (no verdict for B)")
        return 2
    print("\nRESULT: green")
    return 0


if __name__ == "__main__":
    sys.exit(main())
