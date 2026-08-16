#!/usr/bin/env python3
"""
GATE 71 (keeper, 2026-08-16) — sitemap-anon-open-gate — backlog 40, job 3.
The standing gate keeper promised Ian: every URL this site tells Google to
crawl must actually be OPEN to the same anonymous visitor Google is.

WHY THIS SHAPE, NOT A LOGIN-STRING CHECK. The trap keeper named on the board
tonight: testing "is this page open" by checking a page DOESN'T contain a
string like "Sign in" is backwards and fragile — nearly every page on this
site, gated or not, has a sign-in link somewhere in its shared header chrome
(logged-out visitors always see one). A page that is FULLY locked out still
usually contains the word "Sign in" (in the wall itself), and a page that is
genuinely open ALSO contains it (in the nav). The presence or absence of that
string says nothing reliable either way.

So this gate uses a CONTENT-PRESENCE discriminator instead, matching the
pattern tools/gates/hub-topic-landing-gate.py already proved for discussions:
fetch the URL as a REAL anonymous visitor (dev-gate cookie only — no WP
session, because Googlebot has none either), and check that the page's OWN
expected content — sourced independently from the database, never
hand-typed — actually appears. A locked-out page fails this not because it
happens to omit one magic word, but because the thing that should be there
(a topic's title, a profile's own name, a post's own title) simply is not.

SCOPE — sampled from the REAL sitemap.xml, not hand-picked. Reading the live
sitemap index and its four sections is what keeps this gate honest about
"what Google was actually told," rather than testing a parallel idea of it
that could quietly drift from what sitemap.php emits. Sampling (not every
URL) because sitemap-profiles.xml alone lists ~1,930 URLs and content/
discussions add thousands more — see LG_SM_SAMPLE.

FOUR SECTIONS, FOUR GROUND TRUTHS:
  static       — no DB row to check against; asserts 200 + the section's own
                 minimal identity marker (title tag content).
  content      — discovery.content_item.title must appear in the anon page.
  profiles     — profile_app.users.display_name must appear. NOTE: this only
                 proves the page is not WALLED OFF — a public-ceiling profile
                 can still legitimately show very little (About/at_a_glance
                 hidden under a members-only section floor is a REAL, SEPARATE,
                 measured finding: 1916 of 1930 sitemapped profiles hide BOTH
                 — but that is a content-richness/duplicate-content question
                 for job 1, not an access-control failure this gate exists to
                 catch, and conflating the two would make an honest "thin but
                 open" page look identical to a real lockout).
  discussions  — forums.topic.title must appear (a lighter, sitemap-coverage-
                 focused check than hub-topic-landing-gate.py's own deeper
                 OP/reply/layout assertions on this same URL class — that gate
                 stays the authority on layout; this one is the breadth check
                 across all four sections in one place).

PACING. Posture for tonight: Ian is actively working his own session on this
box. Probes are batched and paced (same 0.45s-gap-plus-403-retry shape as
hub-topic-landing-gate.py — the dev gate refuses correctly-cookied requests
in bursts, not from bad auth), and the sample size is deliberately small.

EXIT: 0 green · 1 RED (a sampled URL is not actually open) · 2 CANNOT RUN.
"""

import html as html_mod
import os
import re
import subprocess
import sys
import time
import urllib.parse
import xml.etree.ElementTree as ET

HERE = os.path.dirname(os.path.abspath(__file__))
SAMPLE = int(os.environ.get("LG_SM_SAMPLE", "6"))

OK, RED, DEAD = [], [], []

_LAST = [0.0]
_GAP = float(os.environ.get("LG_GATE_PACE", "0.45"))


def _pace():
    now = time.monotonic()
    wait = _GAP - (now - _LAST[0])
    if wait > 0:
        time.sleep(wait)
    _LAST[0] = time.monotonic()


def gate_env():
    out = subprocess.run(["bash", os.path.join(HERE, "gate-env.sh")],
                          capture_output=True, text=True, timeout=30)
    env = {}
    for line in out.stdout.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    if not env.get("LG_GATE_HOST") or not env.get("LG_GATE_TOKEN"):
        print("CANNOT RUN  gate-env.sh did not resolve a host/token:\n" + out.stdout + out.stderr)
        sys.exit(2)
    return env


def fetch(env, url, want_status=True):
    """Anonymous GET, dev-gate cookie ONLY — deliberately no WP session:
    Googlebot has none, so neither does this gate. Retries a 403 (this box's
    dev gate refuses correctly-cookied bursts, not bad auth — see
    hub-topic-landing-gate.py's identical note); does NOT follow redirects,
    because a redirect-to-login IS the failure this gate looks for."""
    if not url.startswith("http"):
        url = env["LG_GATE_HOST"] + url
    cmd = ["curl", "-s", "--max-time", "45"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd += ["-b", "loothdev_auth=" + env["LG_GATE_TOKEN"]]
    if want_status:
        cmd += ["-w", "\n__HTTP__%{http_code}__LOC__%{redirect_url}"]
    cmd.append(url)
    body, code, loc = "", 0, ""
    for attempt in range(3):
        try:
            _pace()
            r = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
        except Exception:  # noqa: BLE001
            return "", 0, ""
        body = r.stdout
        if want_status:
            m = re.search(r"\n__HTTP__(\d+)__LOC__(.*)$", body, re.S)
            if m:
                code = int(m.group(1))
                loc = m.group(2).strip()
                body = body[: m.start()]
        if code != 403:
            return body, code, loc
        if attempt < 2:
            time.sleep(1.5 * (attempt + 1))
    return body, code, loc


TAG_RE = re.compile(r"<[^>]+>")
WS_RE = re.compile(r"\s+")


def text_of(html):
    html = re.sub(r"(?is)<(script|style)\b.*?</\1>", " ", html)
    txt = TAG_RE.sub(" ", html)
    txt = html_mod.unescape(txt)
    return WS_RE.sub(" ", txt).strip()


# WordPress's own wptexturize() runs on render and silently swaps plain
# ASCII punctuation for typographic equivalents (' - ' -> ' – ' en-dash,
# straight quotes -> curly) — found live: a DB title with a plain hyphen
# ('Council Of Elders - Guitar Show Tips') did not substring-match its own
# correctly-rendered anon page (en-dash) and produced a false RED on the
# first real run. Ground truth comes from the DB pre-texturize, so this
# normalizes both sides the same way rather than trusting either verbatim.
DASH_RE = re.compile(r"[‐-―]")
QUOTE_RE = re.compile(r"[‘’‚‛]")
DQUOTE_RE = re.compile(r"[“”„‟]")


def norm(s):
    s = DASH_RE.sub("-", s or "")
    s = QUOTE_RE.sub("'", s)
    s = DQUOTE_RE.sub('"', s)
    return WS_RE.sub(" ", s).strip().lower()


def fetch_xml(env, path):
    body, code, _ = fetch(env, path)
    if code != 200 or not body.strip():
        return None, f"{path} returned {code}"
    try:
        root = ET.fromstring(body)
    except ET.ParseError as e:
        return None, f"{path} did not parse as XML: {e}"
    ns = {"sm": "http://www.sitemaps.org/schemas/sitemap/0.9"}
    locs = [el.text.strip() for el in root.findall(".//sm:loc", ns) if el.text]
    return locs, None


def psql(db, sql):
    try:
        p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", db,
                             "-A", "-t", "-F", "\x1f", "-c", sql],
                            capture_output=True, text=True, timeout=30)
    except Exception as e:  # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:200]
    return p.stdout, None


def sample(lst, n):
    if len(lst) <= n:
        return lst
    step = max(1, len(lst) // n)
    return lst[::step][:n]


def check_url(env, url, section, ground_truth, path_hint):
    """The one real assertion: anon-fetch the URL, confirm 200 + no redirect,
    then confirm ground_truth text appears in the page — content-presence,
    never a login-string check."""
    body, code, loc = fetch(env, url)
    if code != 200:
        RED.append(f"[{section}] {path_hint} — anon fetch returned {code}"
                   f"{' (redirect to ' + loc + ')' if loc else ''}, not 200 — not open")
        return
    if loc:
        RED.append(f"[{section}] {path_hint} — 200 but curl reports a redirect target "
                   f"({loc}) was followed; the FIRST hop was not the content itself")
        return
    if ground_truth is None:
        OK.append(f"[{section}] {path_hint} — 200, no redirect (no per-row ground truth for this class)")
        return
    txt = text_of(body)
    if norm(ground_truth) in norm(txt):
        OK.append(f"[{section}] {path_hint} — 200, own content present ({ground_truth[:60]!r})")
    else:
        RED.append(f"[{section}] {path_hint} — 200 but the page's OWN expected content "
                   f"({ground_truth[:60]!r}) is NOT in the anon-fetched body — locked out, "
                   f"not merely thin (a content-presence miss, not a login-string check)")


def main():
    env = gate_env()

    index_locs, err = fetch_xml(env, "/sitemap.xml")
    if err:
        print(f"CANNOT RUN  {err}")
        return 2
    sections = {}
    for loc in index_locs:
        m = re.search(r"sitemap-([a-z]+)\.xml$", loc)
        if m:
            sections[m.group(1)] = loc
    for want in ("static", "content", "profiles", "discussions"):
        if want not in sections:
            DEAD.append(f"sitemap index does not list a {want!r} section — cannot sample it")

    # ── static ──────────────────────────────────────────────────────────────
    if "static" in sections:
        locs, err = fetch_xml(env, sections["static"])
        if err:
            DEAD.append(f"[static] {err}")
        else:
            for u in sample(locs, SAMPLE):
                path = urllib.parse.urlparse(u).path
                check_url(env, u, "static", None, path)

    # ── content ─────────────────────────────────────────────────────────────
    if "content" in sections:
        locs, err = fetch_xml(env, sections["content"])
        if err:
            DEAD.append(f"[content] {err}")
        else:
            picked = sample(locs, SAMPLE)
            paths = [urllib.parse.urlparse(u).path for u in picked]
            out, perr = psql("looth", "SELECT url, title FROM discovery.content_item "
                                       "WHERE tier IN ('public','lite') AND cpt <> 'sponsor-product';")
            by_path = {}
            if not perr and out:
                for line in out.strip().splitlines():
                    parts = line.split("\x1f")
                    if len(parts) == 2:
                        u_full, title = parts
                        p = urllib.parse.urlparse(u_full).path
                        by_path[p] = title
            for u, p in zip(picked, paths):
                gt = by_path.get(p)
                if gt is None:
                    DEAD.append(f"[content] {p} — sampled from the sitemap but no matching "
                               f"content_item.title found for ground truth (path mismatch?)")
                    continue
                check_url(env, u, "content", gt, p)

    # ── profiles ────────────────────────────────────────────────────────────
    if "profiles" in sections:
        locs, err = fetch_xml(env, sections["profiles"])
        if err:
            DEAD.append(f"[profiles] {err}")
        else:
            picked = sample(locs, SAMPLE)
            out, perr = psql("profile_app", "SELECT slug, display_name FROM users "
                                             "WHERE profile_visibility='public' AND slug IS NOT NULL;")
            by_slug = {}
            if not perr and out:
                for line in out.strip().splitlines():
                    parts = line.split("\x1f")
                    if len(parts) == 2:
                        by_slug[parts[0]] = parts[1]
            for u in picked:
                path = urllib.parse.urlparse(u).path
                slug = urllib.parse.unquote(path.rstrip("/").split("/")[-1])
                gt = by_slug.get(slug)
                if gt is None:
                    DEAD.append(f"[profiles] {path} — sampled from the sitemap but no matching "
                               f"users.display_name found for ground truth (slug {slug!r})")
                    continue
                check_url(env, u, "profiles", gt, path)

    # ── discussions ─────────────────────────────────────────────────────────
    if "discussions" in sections:
        locs, err = fetch_xml(env, sections["discussions"])
        if err:
            DEAD.append(f"[discussions] {err}")
        else:
            picked = sample(locs, SAMPLE)
            out, perr = psql("looth", "SELECT f.slug, t.slug, t.title FROM forums.topic t "
                                       "JOIN forums.forum f ON f.id = t.forum_id "
                                       "WHERE f.visibility='public' AND f.tier_gate IN ('public','lite');")
            by_pair = {}
            if not perr and out:
                for line in out.strip().splitlines():
                    parts = line.split("\x1f")
                    if len(parts) == 3:
                        by_pair[(parts[0], parts[1])] = parts[2]
            for u in picked:
                path = urllib.parse.urlparse(u).path
                segs = [s for s in path.split("/") if s]
                if len(segs) < 3:
                    DEAD.append(f"[discussions] {path} — could not parse forum/topic slugs from the URL")
                    continue
                fslug, tslug = urllib.parse.unquote(segs[-2]), urllib.parse.unquote(segs[-1])
                gt = by_pair.get((fslug, tslug))
                if gt is None:
                    DEAD.append(f"[discussions] {path} — sampled from the sitemap but no matching "
                               f"forums.topic.title found for ground truth")
                    continue
                check_url(env, u, "discussions", gt, path)

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"sitemap-anon-open-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"sitemap-anon-open-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"sitemap-anon-open-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
