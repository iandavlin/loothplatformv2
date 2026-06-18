#!/usr/bin/env python3
"""
gate-anon-leak.py — archive-poc tier-leak gate (docs/CRAFT-STANDARD.md).

Proves a viewer BELOW a content item's tier never receives its body prose or
its embedded video id. Born from the 2026-06-13 fresh-eyes audit: the card
`excerpt` was baked from the first 220 chars of the FULL body (raw youtube
embed URL and all) and emitted to anon via /archive-api/v0/{search,item}, the
SSR cards, and the page JSON-LD description.

Runs as ubuntu on dev: mints the cookie-gate token from nginx, reads the TRUE
excerpt / yt_id of real gated rows straight from Postgres, then asserts the
ANON HTTP responses (gate cookie ONLY — no WP/looth_id cookies) contain none
of it. Three surfaces, one per audit finding:

  /archive-api/v0/item?id=<gated>   excerpt + body_preview MUST be null
  /archive-api/v0/search            every gated row's excerpt MUST be null
  /archive-poc/  (front-page HTML)  no gated row's excerpt prose or yt_id

Exit 0 = GREEN. Non-zero = a leak (one line each). Wire into
tools/gates/run-all.sh. Run standalone: python3 bin/gate-anon-leak.py
"""

import json, re, ssl, subprocess, sys, urllib.request

HOST = "https://dev.loothgroup.com"
PG   = 'host=/var/run/postgresql dbname=looth'
SAMPLE = 60          # how many gated rows to pull for the HTML/search sweeps
EXCERPT_MIN = 30     # only assert on excerpt slices this long (avoid word collisions)
_SSL = ssl.create_default_context(); _SSL.check_hostname = False; _SSL.verify_mode = ssl.CERT_NONE

# Excerpt prose carries newlines (e.g. "Looth Group Live\n\nFrench Polishing…");
# JSON escapes them and HTML collapses them, so compare on whitespace-normalized
# text both sides — a leak still matches, a spurious newline diff never fails.
def norm(s):
    return re.sub(r"\s+", " ", s or "").strip()


# A distinctive prose needle from an excerpt. Video bodies often LEAD with a raw
# youtube embed URL (https://www.youtube.com/embed/<id>…); that prefix collides
# with the one legit public embed on the page, so strip URLs first and slice the
# real prose that remains. Embedded video ids are covered by the yt_id check, so
# URL-only excerpts correctly yield no prose needle. Returns None if too short.
def prose_needle(ex):
    p = norm(re.sub(r"https?://\S+", " ", ex or ""))
    return p[:EXCERPT_MIN] if len(p) >= EXCERPT_MIN else None


def sh(cmd):
    return subprocess.run(cmd, shell=True, capture_output=True, text=True).stdout


def gate_token():
    for line in open("/etc/nginx/sites-available/dev.loothgroup.com.conf"):
        if "$loothdev_token" in line and '"' in line:
            return line.split('"')[1]
    sys.exit("cannot read dev gate token")


def pg(sql):
    """Run SQL as the archive-poc PG role; return list of field-lists. Records are
    NUL-separated (-0) so embedded newlines in excerpts don't split a row. SQL must
    schema-qualify (discovery.*) — no SET, so no command-tag noise in the output."""
    out = sh(f"sudo -u archive-poc psql \"{PG}\" -X -A -t -0 -F'\t' -c "
             + "\"" + sql.replace('"', '\\"') + "\"")
    return [rec.split("\t") for rec in out.split("\x00") if rec != ""]


def get(path, token):
    req = urllib.request.Request(HOST + path, headers={"Cookie": f"loothdev_auth={token}"})
    with urllib.request.urlopen(req, context=_SSL, timeout=20) as r:
        return r.status, r.read().decode("utf-8", "replace")


def main():
    token = gate_token()
    fails = []

    # ---- fixtures: real gated rows, true values straight from PG ----------
    gated = pg(
        "SELECT id, kind, tier, COALESCE(yt_id,''), COALESCE(excerpt,'') "
        "FROM discovery.content_item WHERE tier <> 'public' "
        f"ORDER BY id LIMIT {SAMPLE};")
    if not gated:
        print("  SKIP   no gated (tier<>public) content in the index — gate cannot prove anything")
        print("==================== ANON-LEAK GATE GREEN (skipped) ====================")
        return

    # an item with real prose, and a video with a real yt_id — the sharpest probes
    prose = next((g for g in gated if len(g[4]) >= EXCERPT_MIN), None)
    video = next((g for g in gated if g[3]), None)

    # ---- surface 1: /archive-api/v0/item -------------------------------
    for fx, why in [(prose, "prose"), (video, "video")]:
        if not fx:
            continue
        cid, kind, tier, yt, ex = fx
        st, body = get(f"/archive-api/v0/item?id={cid}", token)
        d = json.loads(body)
        if d.get("excerpt") not in (None, ""):
            fails.append(f"ITEM-EXCERPT    id={cid} tier={tier} returned excerpt to anon: {d.get('excerpt')!r}")
        if d.get("body_preview") not in (None, ""):
            fails.append(f"ITEM-BODYPREV   id={cid} tier={tier} returned body_preview to anon")
        needle = prose_needle(ex)
        if needle and needle in norm(body):
            fails.append(f"ITEM-PROSE      id={cid} tier={tier} body prose present in /item response")
        if yt and yt in body:
            fails.append(f"ITEM-YTID       id={cid} tier={tier} yt_id {yt} present in /item response")

    # ---- surface 2: /archive-api/v0/search -----------------------------
    st, body = get("/archive-api/v0/search?tier=lite,pro&limit=50", token)
    d = json.loads(body)
    for it in d.get("items", []):
        if it.get("tier") != "public" and it.get("excerpt") not in (None, ""):
            fails.append(f"SEARCH-EXCERPT  id={it.get('id')} tier={it.get('tier')} excerpt leaked in /search")

    # ---- surface 3: front-page HTML ------------------------------------
    st, html = get("/archive-poc/", token)
    if st != 200:
        fails.append(f"HTML-STATUS     front page returned {st} to anon")
    nhtml = norm(html)
    for cid, kind, tier, yt, ex in gated:
        if yt and re.search(r'[^A-Za-z0-9_-]' + re.escape(yt) + r'[^A-Za-z0-9_-]', html):
            fails.append(f"HTML-YTID       id={cid} tier={tier} gated yt_id {yt} present in anon front-page HTML")
        needle = prose_needle(ex)
        if needle and needle in nhtml:
            fails.append(f"HTML-PROSE      id={cid} tier={tier} gated excerpt prose present in anon front-page HTML")

    print(f"  probed {len(gated)} gated rows  (prose fixture={prose[0] if prose else '-'}, "
          f"video fixture={video[0] if video else '-'})")
    if fails:
        print(f"==================== ANON-LEAK GATE RED ({len(fails)}) ====================")
        for f in fails:
            print("  " + f)
        sys.exit(1)
    print("==================== ANON-LEAK GATE GREEN ====================")


if __name__ == "__main__":
    main()
