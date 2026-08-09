#!/usr/bin/env python3
"""hub-topic-landing-gate.py — a sitemapped discussion URL must land on THE HUB
WITH ITS MODAL OPEN, and must carry the discussion's text in the SERVER HTML.

WHY THIS GATE EXISTS (Ian, 2026-08-09):
    "we need google to go to the modals on the hub page."
    "I don't want people landing on the pages we are mirroring for hub. They
     look aweful."
    (shown the standalone page) "does this look like the fucking hub with a
     fucking modal open?"

e9ddc28 put 1,352 discussions into the sitemap. Every one of those URLs is the
canonical permalink /hub/<forum>/<topic>/, and that route rendered
forums/_single-topic.php — the legacy standalone layout. The sitemap turned a
fallback page into the front door, so Google now sends every visitor to the one
layout Ian wants gone.

TWO HALVES, AND THEY FAIL INDEPENDENTLY — which is the whole point of gating
both. The obvious fix (route the pretty URL at the feed and let JS open the
modal) passes the LAYOUT half and silently destroys the SEO half, because
forums.js §4f's cold path fetches the body AFTER load: a crawler reading the
served HTML would find an empty modal, and the sitemap work would be undone
without one visible symptom.

    A. CONTENT  — the OP body and the reply text are in `curl` output, with no
                  JS and no cookies, and the title is in <title>.
    B. LAYOUT   — that same URL renders the hub feed grid with a discussion
                  modal already open on it.

MEASURED ON MAIN 2026-08-09 (the red-first): A passes, B fails. _single-topic.php
does server-render the OP and all replies (10 .post__body blocks on
/hub/general/crazy-electrical-issue/), so this gate must not be read as "the old
page had no content" — it had content in the wrong clothes. Half A is here to
stop the REPLACEMENT from regressing what the legacy page already did right.

GROUND TRUTH IS SELF-SOURCED, NOT HARDCODED. The expected body/reply text comes
from the fragment endpoints the modal itself uses
(/bb-mirror-api/v0/topic and /hub/?replies=<id>), so the gate needs no DB role,
survives content edits, and cannot drift into asserting a string nobody serves.

FLAG-STATE AWARE (docs/CRAFT-STANDARD.md, and the lane rule that a defect fix
behind an OFF-default flag must not redden every other lane on merge). The gate
READS which state is being served instead of hardcoding one:
    ON   → both halves must pass.
    OFF  → half A must still pass on the legacy page (no content regression),
           and half B is reported as OFF, not as a finding.
Flipping the default therefore needs no edit here.

EXIT: 0 green · 1 RED (real findings) · 2 CANNOT RUN (no verdict).
"""

import html as html_mod
import os
import re
import subprocess
import sys
import time
import urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
SAMPLE = int(os.environ.get("LG_TL_SAMPLE", "3"))

# LG_TL_REQUIRE_ON=1 turns "the legacy layout is being served" from a reported
# state into a FINDING. Two jobs, both load-bearing:
#   1. It is this gate's red-first lever. Half B is an assertion about a layout
#      that does not exist yet; without a way to demand it, half B is decoration
#      that has never once been observed to fail. Run with it on main:
#        LG_TL_REQUIRE_ON=1 python3 tools/gates/hub-topic-landing-gate.py
#      → RED on every sampled URL, which is the measurement this gate was
#      written to make.
#   2. It is the switch to throw when the flag's default flips ON. At that point
#      the legacy layout reaching a visitor IS a regression, and the gate should
#      say so without anyone editing an assertion.
#
# THAT SWITCH IS NOW THROWN (2026-08-09). Ian approved the running thing, the
# default flipped ON, and forums/_single-topic.php has been deleted — so the
# legacy layout reaching a visitor is a regression, and this gate demands the hub
# layout by default.
#
# The OFF-RECOGNISING ARM IS DELIBERATELY KEPT, and LG_TL_REQUIRE_ON=0 still
# disarms it. Nothing can serve that layout today, which is exactly why the check
# is worth keeping: it is what would NAME a bad deploy that somehow resurrected
# it, instead of leaving a confusing generic failure. An assertion whose subject
# is gone costs one string compare; deleting it costs the diagnosis.
REQUIRE_ON = os.environ.get("LG_TL_REQUIRE_ON", "1") == "1"

# LG_TL_PREFIX — gate a LANE PREVIEW instead of the live mount. The sitemap
# always advertises /hub/<forum>/<topic>/ (that is the promise made to Google);
# a preview serves the identical routes under its own prefix, so the URL set is
# taken from the sitemap and then re-based. Rebasing rather than hand-listing
# slugs is what keeps the preview run and the /hub/ run the SAME assertions over
# the SAME discussions — otherwise a green preview would prove nothing about the
# URLs Google actually holds.
#   LG_TL_PREFIX=/preview/hub-seo-landing/hub LG_TL_REQUIRE_ON=1 python3 …
PREFIX = os.environ.get("LG_TL_PREFIX", "/hub").rstrip("/")


def gate_env():
    """Resolve host/token/edge-pin through the ONE resolver (gate-env.sh)."""
    try:
        out = subprocess.run(
            ["bash", os.path.join(HERE, "gate-env.sh")],
            capture_output=True, text=True, timeout=30,
        )
    except Exception as e:                                    # noqa: BLE001
        return None, f"could not execute gate-env.sh: {e}"
    if out.returncode != 0:
        return None, f"gate-env.sh failed: {out.stderr.strip()}"
    env = {}
    for line in out.stdout.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    if not env.get("LG_GATE_HOST") or not env.get("LG_GATE_TOKEN"):
        return None, "gate-env.sh produced no host/token"
    return env, None


def fetch(env, path, want_status=False):
    """Anonymous GET through the edge-bypass pin, carrying only the dev gate
    cookie. Deliberately NOT a WP session: Google is anonymous, so the gate is."""
    url = path if path.startswith("http") else env["LG_GATE_HOST"] + path
    cmd = ["curl", "-s", "--max-time", "45"]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd += ["-b", "loothdev_auth=" + env["LG_GATE_TOKEN"]]
    if want_status:
        cmd += ["-w", "\n__HTTP__%{http_code}"]
    cmd.append(url)
    try:
        r = subprocess.run(cmd, capture_output=True, text=True, timeout=60)
    except Exception:                                         # noqa: BLE001
        return "", 0
    body = r.stdout
    code = 0
    if want_status:
        m = re.search(r"\n__HTTP__(\d+)$", body)
        if m:
            code = int(m.group(1))
            body = body[: m.start()]
    return body, code


TAG_RE = re.compile(r"<[^>]+>")
WS_RE = re.compile(r"\s+")


def text_of(html):
    """Tag-stripped, entity-folded, whitespace-normalised text.

    Both sides of every comparison go through this, so the gate compares what a
    READER sees rather than markup that may legitimately differ between the
    server-rendered modal and the fragment endpoint.
    """
    html = re.sub(r"(?is)<(script|style)\b.*?</\1>", " ", html)
    txt = TAG_RE.sub(" ", html)
    # html.unescape, not a hand-rolled replace() chain: the first cut of this gate
    # folded six named entities by hand and then reported a REAL page as broken
    # because a reply contained &#9786;. A partial unescape makes the gate's own
    # blind spot look like a finding.
    txt = html_mod.unescape(txt)
    return WS_RE.sub(" ", txt).strip()


def probe_window(txt, words=8):
    """A distinctive contiguous word-window to search for in the landing page.

    Taken from the MIDDLE of the text, not the head: the first words of a body
    are often echoed in the card excerpt, which would let a page pass half A
    while carrying only the excerpt — the exact failure this gate exists to
    catch. Returns '' when the text is too short to be distinctive.
    """
    w = txt.split()
    if len(w) < words + 4:
        return ""
    start = max(0, len(w) // 2 - words // 2)
    return " ".join(w[start:start + words])


def main():
    env, err = gate_env()
    if err:
        print(f"CANNOT RUN  {err}")
        return 2

    # ── Liveness FIRST. Every assertion below is a presence check, and a
    #    presence check on a dead box is vacuously red — or worse, a layout
    #    check on an empty hub is vacuously green. Prove the hub is serving a
    #    real feed before judging anything about a topic URL.
    # RETRY THE LIVENESS PROBE. The dev gate on this box intermittently answers
    # 403 to a correctly-cookied request, in WINDOWS of a few seconds during
    # which every client is refused — not just this one.
    #
    # Chased properly before writing this off, because "shell curl 200, gate
    # 403, back to back" looked damning for the gate: the token is byte-identical
    # to the shell's, the argv is identical (reconstructed and run side by side),
    # a cookie-less request 403s exactly as it should, and interleaving fetch()
    # with a hand-built reconstruction in ONE process gives 200/200 four rounds
    # running. The apparent shell-vs-gate split was two different windows, not
    # two different clients. It is NOT the limit_req zone either — that returns
    # 429, not 403.
    #
    # So: an environment-level flap in $loothdev_is_authorized, not this lane's
    # code and not this script's. Worth keeper knowing; not worth a gate that
    # cannot run.
    #
    # A single blip should not cost a whole run, but it must not be swallowed
    # either: the retry is bounded, and a run that needed one SAYS SO — so a
    # transient stays visible and a persistent fault still reports CANNOT RUN.
    hub, code, tries = "", 0, 0
    for tries in range(1, 6):
        hub, code = fetch(env, PREFIX + "/", want_status=True)
        if code == 200 and "feed-card" in hub:
            break
        if tries < 5:
            time.sleep(3 * tries)          # 3+6+9+12 = up to 30s of window
    if code != 200 or "feed-card" not in hub:
        print(f"CANNOT RUN  {PREFIX}/ did not serve a feed after {tries} "
              f"attempt(s) (HTTP {code}, {len(hub)}b, feed-card present: "
              f"{'feed-card' in hub})")
        return 2
    if tries > 1:
        print(f"liveness    NOTE: needed {tries} attempts — the dev gate blipped "
              f"(transient, self-clearing; see the comment above)")
    hub_cards = len(re.findall(r"feed-card feed-card--", hub))
    print(f"liveness    {PREFIX}/ HTTP 200, {hub_cards} feed cards, {len(hub)}b")

    # ── The URL set under test is the SITEMAP's own, not a hand-picked slug.
    #    This is what e9ddc28 promised Google, so it is what must work.
    smap, code = fetch(env, "/sitemap-discussions.xml", want_status=True)
    if code != 200:
        print(f"CANNOT RUN  /sitemap-discussions.xml HTTP {code}")
        return 2
    locs = re.findall(r"<loc>([^<]+/hub/[^<]+)</loc>", smap)
    if not locs:
        print("CANNOT RUN  sitemap-discussions.xml listed no /hub/ topic URLs")
        return 2
    print(f"sitemap     {len(locs)} discussion URLs listed")

    # Spread the sample across the file instead of taking the first N — the head
    # of the sitemap is the most-recently-active topics, which are the least
    # representative of the 1,352.
    step = max(1, len(locs) // SAMPLE)
    sample = [locs[i * step] for i in range(SAMPLE) if i * step < len(locs)]

    findings = []
    states = []

    for loc in sample:
        path = urllib.parse.urlparse(loc).path
        parts = [p for p in path.split("/") if p]
        if len(parts) < 3:
            continue
        forum, topic = parts[-2], parts[-1]
        # Re-base onto the mount under test. The sitemap's own /hub/ path is what
        # a live run uses unchanged; a preview run gates the same discussions
        # through the branch.
        path = "%s/%s/%s/" % (PREFIX, forum, topic)
        page, code = fetch(env, path, want_status=True)

        if code != 200:
            findings.append(f"{path} — HTTP {code} (sitemapped URL must 200)")
            continue

        # ── Ground truth from the fragment API the modal itself reads ──────────
        frag, fcode = fetch(
            env,
            "/bb-mirror-api/v0/topic?forum=%s&topic=%s"
            % (urllib.parse.quote(forum), urllib.parse.quote(topic)),
            want_status=True,
        )
        if fcode != 200 or "lg-fpd-op" not in frag:
            print(f"  {path}\n    SKIP  fragment API gave HTTP {fcode} "
                  f"— no ground truth, not judged")
            continue

        m = re.search(r'data-title="([^"]*)"', frag)
        title = text_of(m.group(1)) if m else ""
        m = re.search(r'data-topic-id="(\d+)"', frag)
        tid = m.group(1) if m else ""
        m = re.search(r'(?s)<div class="lg-dmodal__body">(.*?)</div>\s*<div class="lg-dmodal__opacts">', frag)
        body_txt = text_of(m.group(1)) if m else ""

        page_txt = text_of(page)

        # ── HALF B: which layout is being served? Read it, do not assume. ──────
        has_grid = "feed-card--topic" in page or "feed-card--content" in page
        modal_open = bool(re.search(r'id="lg-dmodal"(?![^>]*\bhidden\b)', page))
        legacy = "topic-header__title" in page and not has_grid
        state = "ON" if (has_grid and modal_open) else ("OFF" if legacy else "?")
        states.append(state)

        # ── HALF A: content in the server HTML, no JS, no cookies ─────────────
        problems = []
        # casefold BOTH sides. The first cut lowercased only the needle and then
        # reported every page as titleless — a red that was real-looking, loud,
        # and entirely the gate's own.
        m_title = re.search(r"(?is)<title>(.*?)</title>", page)
        page_title = text_of(m_title.group(1)) if m_title else ""
        if title and title.casefold() not in page_title.casefold():
            problems.append(
                f"<title> does not carry the discussion title {title!r} "
                f"(served: {page_title!r})")

        probe = probe_window(body_txt)
        if not probe:
            # A genuinely short OP (a link-only post) has no distinctive window;
            # fall back to the whole body rather than skipping the assertion.
            probe = body_txt
        if probe and probe not in page_txt:
            problems.append(
                "OP body text is NOT in the served HTML "
                f"(looked for {probe[:60]!r})")

        # ── VISIBILITY MASKS SURVIVE THE MOVE (keeper, 2026-08-09) ──────────
        # Differential, not hardcoded: the fragment API and the landing page are
        # two different renderers over one masking contract (is_anon →
        # "Anonymous", member-only author → "Private member", logged-out contact
        # scrub). Ask BOTH for the same discussion as the same anonymous viewer
        # and the author block must agree. If the landing leaks a real name the
        # fragment masked, these differ — which is audit H6 exactly, and H6 was
        # this bug on this permalink.
        # No DB role, no fixture list, and it covers every sampled discussion
        # rather than the one or two somebody remembered to write down.
        # BOUNDED to the OP's own meta block. An unbounded `.*?` reaches past it
        # into the REPLY authors — the landing page carries the whole thread, so
        # the first cut of the /u/ check matched a replier's profile link and
        # reported a correctly-masked page as leaking. Scope first, assert second.
        def meta_of(doc):
            mm = re.search(r'(?s)<div class="lg-dmodal__meta">(.*?)'
                           r'<div class="lg-dmodal__body">', doc)
            return mm.group(1) if mm else None

        def author_of(doc):
            blk = meta_of(doc)
            if not blk:
                return None
            mm = re.search(r'(?s)class="fc-author__name"[^>]*>(.*?)</span>', blk)
            return text_of(mm.group(1)) if mm else None
        f_author, p_author = author_of(frag), author_of(page)
        if f_author and p_author and f_author != p_author:
            problems.append(
                f"author block DISAGREES with the fragment API — mask lost in "
                f"the move (fragment says {f_author!r}, page says {p_author!r})")
        # A masked author must not keep a clickable identity either: the mask
        # nulls author_id/slug, so a /u/<slug> link on the page that the fragment
        # does not carry is the same leak wearing a different hat.
        f_meta, p_meta = meta_of(frag), meta_of(page)
        if f_meta is not None and p_meta is not None:
            if 'href="/u/' in p_meta and 'href="/u/' not in f_meta:
                problems.append(
                    f"OP author {p_author!r} renders a /u/ profile link the "
                    f"fragment API masks away")

        # ── A SELF-REFERENCING CANONICAL (Ian, 2026-08-09) ──────────────────
        # Ian caught this by eye on the flipped serve, and it is exactly the gap
        # the two original halves could not see: the content IS served, so half A
        # passes, and the layout IS the hub, so half B passes — while nothing on
        # the page tells Google WHICH url owns that content.
        #
        # It matters because forums.js §4f rewrites the address bar to
        # /hub/?topic=<forum>/<topic> once the modal is up, and live robots.txt
        # carries `Disallow: /hub/?` (verified on live, not assumed). So the
        # shareable form of every discussion is a URL Google is FORBIDDEN to
        # fetch. Without a canonical there is nothing anchoring the permalink,
        # and the sitemap's promise is left arguing with the address bar.
        #
        # Asserted ABSOLUTE and SELF-REFERENCING — the canonical must name this
        # exact page on the host that served it, which is what makes it a
        # consolidation signal rather than decoration.
        want_canon = env["LG_GATE_HOST"].rstrip("/") + path
        m_can = re.search(r'<link[^>]+rel=["\']canonical["\'][^>]*>', page, re.I)
        if not m_can:
            problems.append("NO <link rel=canonical> — nothing anchors Google to "
                            "the permalink, and the ?topic= address-bar form is "
                            "robots-blocked on live")
        else:
            m_href = re.search(r'href=["\']([^"\']+)["\']', m_can.group(0), re.I)
            got = (m_href.group(1) if m_href else "").strip()
            if got.rstrip("/") != want_canon.rstrip("/"):
                problems.append(
                    f"canonical is not self-referencing: {got!r} != {want_canon!r}")

        m_og = re.search(r'<meta[^>]+property=["\']og:url["\'][^>]*>', page, re.I)
        if not m_og:
            problems.append("no og:url — the share/social form of the URL is "
                            "unanchored too")
        else:
            m_c = re.search(r'content=["\']([^"\']+)["\']', m_og.group(0), re.I)
            gotog = (m_c.group(1) if m_c else "").strip()
            if gotog.rstrip("/") != want_canon.rstrip("/"):
                problems.append(f"og:url disagrees with the canonical: {gotog!r}")

        reps, rcode = fetch(env, f"{PREFIX}/?replies={tid}", want_status=True)
        # Compare the reply BODY only. The first cut compared whole stubs and
        # tripped over their CHROME — the fragment renders a relative time ("1h")
        # where a page may render an absolute date, and the author line differs
        # too. That is a renderer difference, not a missing reply, and asserting
        # across it manufactures findings on healthy pages.
        rep_bodies = re.findall(
            r'(?s)<div class="reply-stub__excerpt"[^>]*>(.*?)</div>', reps
        ) if rcode == 200 else []
        rep_stubs = len(re.findall(r'class="reply-stub__body"', reps)) if rcode == 200 else 0
        if rep_bodies:
            rtxt = text_of(rep_bodies[0])
            rprobe = probe_window(rtxt, words=6) or rtxt
            if rprobe and rprobe not in page_txt:
                problems.append(
                    "reply text is NOT in the served HTML "
                    f"(looked for {rprobe[:60]!r})")

        # ── Verdict for this URL ──────────────────────────────────────────────
        print(f"  {path}")
        print(f"    state={state}  grid={has_grid}  modal_open={modal_open}  "
              f"replies={rep_stubs}  {len(page)}b")
        for p in problems:
            findings.append(f"{path} — {p}")
            print(f"    RED   {p}")
        if state == "OFF":
            if REQUIRE_ON:
                findings.append(
                    f"{path} — serves the LEGACY standalone layout, not the hub "
                    f"with the modal open (grid={has_grid}, "
                    f"modal_open={modal_open}). This is the page Ian rejected.")
                print("    RED   legacy standalone layout — not hub+modal")
            else:
                print("    OFF   legacy standalone layout is being served "
                      "(flag off) — half B not asserted")
        elif state == "?":
            findings.append(
                f"{path} — served neither the hub+modal layout nor the legacy "
                f"page (grid={has_grid}, modal_open={modal_open})")
            print("    RED   unrecognised layout")
        else:
            print("    OK    hub grid + open modal")

    # ── A HIDDEN FORUM'S TOPIC MUST 404 THROUGH EVERY PATH ─────────────────
    # The landing route resolves topics itself now, so "public forums only" had
    # to be re-implemented on a second code path — and a visibility gate that
    # exists on one path and not the other is worth more than a comment.
    # 14 topics live in hidden forums and 3 in private ones; none may render.
    #
    # THE FIXTURE GUARDS ITSELF. If this topic were ever made public it would
    # appear in the sitemap, and a 404 assertion against a public topic is a
    # green that measures nothing. So the sitemap is checked first and the
    # sub-check reports CANNOT RUN rather than passing vacuously.
    hidden = os.environ.get("LG_TL_HIDDEN", "the-jannies-3/website-changes-to-be-made")
    hf, _, ht = hidden.partition("/")
    if any(f"/{hf}/{ht}/" in loc for loc in locs):
        print(f"\n  hidden-forum check  CANNOT RUN — fixture {hidden} is now IN "
              f"the sitemap, so it is not hidden any more. Set LG_TL_HIDDEN.")
    else:
        for label, path in (
            ("landing route", f"{PREFIX}/{hf}/{ht}/"),
            ("fragment API", "/bb-mirror-api/v0/topic?forum=%s&topic=%s"
                             % (urllib.parse.quote(hf), urllib.parse.quote(ht))),
        ):
            hbody, hcode = fetch(env, path, want_status=True)
            leaked = "lg-dmodal__body" in hbody or "lg-fpd-op" in hbody
            if hcode == 200 and leaked:
                findings.append(
                    f"{path} — HIDDEN forum topic RENDERED via the {label} "
                    f"(HTTP 200 with discussion markup)")
                print(f"  hidden via {label}: RED  HTTP {hcode}, discussion rendered")
            else:
                print(f"  hidden via {label}: ok   HTTP {hcode}, no discussion markup")

    if not states:
        print("CANNOT RUN  no sampled URL yielded ground truth")
        return 2

    print()
    if findings:
        print(f"RED  {len(findings)} finding(s):")
        for f in findings:
            print(f"  - {f}")
        return 1

    if all(s == "OFF" for s in states):
        print("GREEN (flag OFF)  legacy layout still serves the discussion text "
              "intact; hub+modal landing not yet armed.")
    else:
        print("GREEN  sitemapped discussion URLs land on the hub with the modal "
              "open, and carry the discussion text in the server HTML.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
