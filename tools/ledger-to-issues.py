#!/usr/bin/env python3
"""ledger-to-issues — migrate docs/BACKLOG.md to GitHub Issues (handoff 5).

DRY RUN by default: parses the ledger and writes the full preview to
docs/LEDGER-ISSUE-MAP-DRYRUN.md, creating NOTHING. The preview leads with the
open/closed split and every status-ambiguous item, per Ian's ruling — the
costly failure is a live item arriving closed and vanishing from the queue,
so anything unclear defaults to OPEN and is flagged for his eyes.

Real run (--create) only after Ian rules on the preview: creates one issue
per item (ledger id leads the title, ledger:NN label, body = section verbatim
+ link to the frozen file), closed for shipped, then writes the permanent
docs/LEDGER-ISSUE-MAP.md.
"""
import json
import re
import subprocess
import sys
import urllib.request

REPO_DIR = "/home/ubuntu/keeper-repo"
BACKLOG = f"{REPO_DIR}/docs/BACKLOG.md"
API = "https://api.github.com/repos/iandavlin/loothplatformv2"

# Strong closed markers — anything less stays OPEN and gets flagged.
CLOSED_RX = re.compile(
    r"(✅|SHIPPED TO LIVE|CLOSED BY RULING|CLOSED \d|→ MERGED \+ LIVE|"
    r"MERGED \+ LIVE|LIVE @ [0-9a-f]{6,})")
# Open-leaning phrases: if these co-occur with a closed marker, it's AMBIGUOUS.
OPEN_RX = re.compile(
    r"(awaiting|flag (off|OFF)|OWED|UNOWNED|blocked|in flight|remaining|"
    r"needs?:|→ lane|fix BUILT|BUILT,)", re.I)
ID_RX = re.compile(r"(\d+(?:\.\d+)?)")


def token():
    for line in open("/etc/looth/env"):
        if line.startswith("LG_GITHUB_ISSUES_TOKEN="):
            return line.split("=", 1)[1].strip()
    sys.exit("no token in /etc/looth/env")


def parse():
    text = open(BACKLOG, encoding="utf-8").read()
    lines = text.splitlines()

    items = {}   # id -> dict(title, index_line, section, status, why)

    # 1. index lines: from PRIORITY INDEX to the first detail section
    in_index = False
    for ln in lines:
        if "PRIORITY INDEX" in ln:
            in_index = True
            continue
        if in_index and ln.startswith("## "):
            break
        if not in_index:
            continue
        m = re.match(r"^(\d+(?:\.\d+)?)[.\s]", ln)
        if not m:
            continue
        iid = m.group(1)
        items.setdefault(iid, {})["index_line"] = ln.strip()

    # 2. detail sections
    sec_heads = [(i, ln) for i, ln in enumerate(lines) if ln.startswith("## ")]
    for n, (i, head) in enumerate(sec_heads):
        if "PRIORITY INDEX" in head:
            continue
        end = sec_heads[n + 1][0] if n + 1 < len(sec_heads) else len(lines)
        body = "\n".join(lines[i:end]).strip()
        if "SHIPPED TO LIVE" in head:
            # graveyard: id-bearing lines are closed items; unnumbered bullets
            # (the pre-numbering era) can't carry ledger:NN — counted and put
            # to Ian in the preview rather than silently decided
            for gl in lines[i:end]:
                gm = re.match(r"^[-*\s]*(\d+(?:\.\d+)?)[.\s]", gl)
                if gm:
                    it = items.setdefault(gm.group(1), {})
                    it.setdefault("index_line", gl.strip())
                    it["graveyard"] = True
                elif gl.strip().startswith("- "):
                    items.setdefault("_grave_unnumbered", {"n": 0})["n"] = \
                        items.get("_grave_unnumbered", {"n": 0})["n"] + 1
            continue
        m = ID_RX.search(head)
        if not m:
            continue
        it = items.setdefault(m.group(1), {})
        it["section"] = body
        it["head"] = head

    # 3. status per item — default OPEN, strong markers close, conflicts flag
    for iid, it in items.items():
        basis = (it.get("head", "") + " " + it.get("index_line", ""))
        closed = bool(CLOSED_RX.search(basis)) or it.get("graveyard", False)
        openish = bool(OPEN_RX.search(basis))
        if closed and openish and not it.get("graveyard"):
            it["status"], it["why"] = "OPEN", (
                "AMBIGUOUS — closed marker AND open-leaning phrasing; "
                "defaulted OPEN for your eyes")
        elif closed:
            it["status"], it["why"] = "CLOSED", "strong closed marker"
        else:
            it["status"], it["why"] = "OPEN", ""
        title_src = it.get("index_line") or it.get("head", "")
        title = re.sub(r"^[#✅\s]*", "", title_src)
        title = re.sub(r"^\d+(\.\d+)?[.\s]+", "", title).strip()
        it["title"] = f"{iid} — {title[:90]}"
    return items


def preview(items):
    grave_n = items.pop("_grave_unnumbered", {"n": 0})["n"]
    open_i = {k: v for k, v in items.items() if v["status"] == "OPEN"}
    closed_i = {k: v for k, v in items.items() if v["status"] == "CLOSED"}
    amb = {k: v for k, v in items.items() if "AMBIGUOUS" in v.get("why", "")}
    out = ["# Ledger → Issues — DRY RUN preview (nothing created)", ""]
    out.append(f"**THE SPLIT: {len(items)} items — {len(open_i)} would arrive "
               f"OPEN, {len(closed_i)} CLOSED, and {len(amb)} are AMBIGUOUS "
               f"(defaulted OPEN, listed first for your ruling).**")
    out.append("")
    if amb:
        out.append("## Ambiguous — your eyes, per your ruling")
        out.append("")
        for iid in sorted(amb, key=lambda x: float(x)):
            out.append(f"- **{amb[iid]['title']}** — {amb[iid]['why']}")
        out.append("")
    out.append("## Full mapping (proposed)")
    out.append("")
    out.append("| ledger id | arrives | title |")
    out.append("|---|---|---|")
    for iid in sorted(items, key=lambda x: float(x)):
        it = items[iid]
        mark = it["status"] + (" ⚠" if "AMBIGUOUS" in it.get("why", "") else "")
        out.append(f"| {iid} | {mark} | {it['title'].replace('|', '·')} |")
    out.append("")
    out.append(f"**One question for you:** the shipped graveyard holds "
               f"{grave_n} UNNUMBERED entries (cleared pre-numbering, "
               f"2026-08-01) — they cannot carry a ledger:NN label. Proposed: "
               f"they stay in the frozen archive, no issues. Say otherwise "
               f"and they arrive as closed, unnumbered issues.")
    out.append("")
    out.append("Notes: gates stay in-repo; satellite audit files and DONE.md "
               "stay archive; issue numbers are assigned by GitHub and mapped "
               "in docs/LEDGER-ISSUE-MAP.md at the real run.")
    return "\n".join(out) + "\n"


def gh(method, path, data=None):
    req = urllib.request.Request(
        API + path, method=method,
        data=json.dumps(data).encode() if data else None,
        headers={"Authorization": f"Bearer {token()}",
                 "Accept": "application/vnd.github+json"})
    with urllib.request.urlopen(req, timeout=30) as r:
        return json.loads(r.read() or "{}")


def create(items):
    frozen_url = ("https://github.com/iandavlin/loothplatformv2/blob/main/"
                  "docs/BACKLOG.md")
    mapping = []
    for iid in sorted(items, key=lambda x: float(x)):
        it = items[iid]
        body = (it.get("section") or it.get("index_line", "")) + \
            f"\n\n---\n*Migrated from the frozen ledger ({frozen_url}), " \
            f"item {iid}.*"
        issue = gh("POST", "/issues", {
            "title": it["title"],
            "body": body[:60000],
            "labels": [f"ledger:{iid}"]})
        if it["status"] == "CLOSED":
            gh("PATCH", f"/issues/{issue['number']}",
               {"state": "closed", "state_reason": "completed"})
        mapping.append(f"| {iid} | #{issue['number']} | {it['status']} | "
                       f"{it['title'].replace('|', '·')} |")
        print(f"created #{issue['number']} <- ledger {iid} ({it['status']})")
    out = ["# Ledger → Issue map (permanent cross-reference)", "",
           "| ledger id | issue | arrived | title |", "|---|---|---|---|"]
    out += mapping
    open(f"{REPO_DIR}/docs/LEDGER-ISSUE-MAP.md", "w").write("\n".join(out) + "\n")
    print("map written: docs/LEDGER-ISSUE-MAP.md")


if __name__ == "__main__":
    items = parse()
    if "--create" in sys.argv:
        create(items)
    else:
        path = f"{REPO_DIR}/docs/LEDGER-ISSUE-MAP-DRYRUN.md"
        open(path, "w").write(preview(items))
        print(f"dry run written: {path} ({len(items)} items, nothing created)")
