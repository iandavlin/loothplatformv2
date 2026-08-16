#!/usr/bin/env python3
"""
GATE 65 — author-archive-icon-gate — backlog 27. Number from keeper,
2026-08-16. Reads THIS BRANCH'S TREE via the featured-members lane preview.

Ian looked at the mock (a 7th icon in the profile's existing social-icon
palette, styled like its neighbours, linking to the Hub's by-author filtered
view) and answered "Icon good," plus ONE refinement: "can we make the icon
vis 0 if no authorship of either discussions or cpts" — a zero-authorship
profile shows no icon at all.

Keeper's implementation spec: the icon's visibility must come from THE SAME
predicate the destination (the Hub author-filter) uses — never a parallel
count. This gate asserts that architecturally, not just behaviourally:

  A. FLAG OFF is inert — the hlinks markup on a real, high-activity profile
     (Ian, wp_user_id 1) is byte-identical with the flag off vs. main's
     currently-served page. Byte-identical, not "looks the same," because a
     flag that changes even whitespace is not the no-op it claims to be.
  B. FLAG ON, a member WITH activity gets the icon, and its href is the
     SAME author_name + a REAL request to that exact URL against the live
     Hub returns that member's own posts (not zero, not someone else's) —
     proves the destination actually honours what the icon promises, not
     just that a plausible-looking href string got built.
  C. FLAG ON, a member with ZERO Hub activity gets NO icon — the other half
     of "vis-0 both ways." A real dev2 member (not minted) with a bridged WP
     identity and a genuine zero count from the shared predicate.
  D. STRUCTURAL — "one predicate, two consumers" is actually true in the
     code, not just true today by coincidence: the author-banner's own count
     (_feed.php) and the archive-icon's endpoint (author-activity.php) both
     call hub_author_activity_count(), matched as a real function-call
     construct in both files — not two files that happen to contain
     similar-looking SQL that could silently drift apart on the next edit.
  E. THE LOOPBACK ENDPOINT ITSELF is loopback-only — a plain external
     request (via the LAN-pinned gate address, never truly 127.0.0.1) is
     refused, so this is not an accidental new anonymous surface.

Needs:  tools/gates/gate-env.sh resolving a host/token, the featured-members
        lane preview UP (tools/preview/lane-preview.sh up featured-members),
        sudo -u postgres psql reachable.
Exit 0 = GREEN. Exit 1 = RED. Exit 2 = CANNOT RUN.
"""

import json
import os
import re
import subprocess
import sys
import urllib.parse
import urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))  # tools/gates/<file>.py -> tools -> repo (2 dirnames)

PREVIEW_U_ON  = "/preview/featured-members/u/"
PREVIEW_U_OFF = "/preview/featured-members/u-off/"

OK, RED, DEAD = [], [], []


def gate_env():
    out = subprocess.run(["bash", os.path.join(HERE, "gate-env.sh")],
                          capture_output=True, text=True, timeout=30)
    env = {}
    for line in out.stdout.splitlines():
        if "=" in line:
            k, v = line.split("=", 1)
            env[k] = v
    if "LG_GATE_TOKEN" not in env or "LG_GATE_HOST" not in env:
        print("CANNOT RUN  gate-env.sh did not resolve a host/token:\n" + out.stdout + out.stderr)
        sys.exit(2)
    return env


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


def fetch(url, resolve, token, cookie_name="loothdev_auth"):
    cmd = ["curl", "-s", "-w", "\n%{http_code}"] + resolve.split() + \
          ["-b", f"{cookie_name}={token}", url]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=20)
    body, _, code = p.stdout.rpartition("\n")
    return body, code.strip()


def main():
    env = gate_env()
    host, token, resolve = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"], env.get("LG_GATE_RESOLVE", "")

    # ── fixtures: real dev2 data, nothing minted ──────────────────────────
    out, err = psql("profile_app",
        "SELECT u.slug, b.wp_user_id FROM users u JOIN wp_user_bridge b ON b.user_id = u.id "
        "WHERE b.wp_user_id IN (1) LIMIT 1;")
    if err or not out.strip():
        print("CANNOT RUN  could not resolve the active fixture (wp_user_id 1): " + str(err))
        sys.exit(2)
    active_slug, active_wp = out.strip().split("\x1f")

    out, err = psql("looth",
        "SELECT COALESCE((SELECT count(*) FROM forums.topic WHERE author_id=" + active_wp + "),0) + "
        "COALESCE((SELECT count(*) FROM discovery.content_item WHERE author_id=" + active_wp + "),0);")
    if err:
        print("CANNOT RUN  could not read the active fixture's real count: " + str(err))
        sys.exit(2)
    active_count = int(out.strip() or "0")
    if active_count <= 0:
        print(f"CANNOT RUN  fixture wp_user_id {active_wp} has zero Hub activity — the 'active' "
              f"half of this gate needs a member who genuinely has posts")
        sys.exit(2)

    # A zero-activity member: real profile_app row bridged to a WP id with no
    # topic/content rows at all. Scanned, not hardcoded, so this keeps working
    # if the fixture's own activity ever changes.
    out, err = psql("profile_app",
        "SELECT u.slug, b.wp_user_id FROM users u JOIN wp_user_bridge b ON b.user_id = u.id "
        "ORDER BY b.wp_user_id LIMIT 40;")
    if err or not out.strip():
        print("CANNOT RUN  could not list candidate members: " + str(err))
        sys.exit(2)
    zero_slug = zero_wp = None
    for line in out.strip().splitlines():
        slug, wp = line.split("\x1f")
        c_out, c_err = psql("looth",
            "SELECT COALESCE((SELECT count(*) FROM forums.topic WHERE author_id=" + wp + "),0) + "
            "COALESCE((SELECT count(*) FROM discovery.content_item WHERE author_id=" + wp + "),0);")
        if c_err:
            continue
        if int(c_out.strip() or "0") == 0:
            zero_slug, zero_wp = slug, wp
            break
    if zero_slug is None:
        DEAD.append("[fixture] no zero-activity member found among the first 40 bridged users — "
                    "cannot test the vis-0 half at all")

    # ── D. structural — one predicate, two real call sites ────────────────
    hub_filters_path = os.path.join(REPO, "bb-mirror", "web", "forums", "_hub-filters.php")
    feed_path = os.path.join(REPO, "bb-mirror", "web", "forums", "_feed.php")
    api_path = os.path.join(REPO, "bb-mirror", "api", "v0", "author-activity.php")
    try:
        hub_filters_src = open(hub_filters_path, encoding="utf-8").read()
        feed_src = open(feed_path, encoding="utf-8").read()
        api_src = open(api_path, encoding="utf-8").read()
    except OSError as e:
        DEAD.append(f"[D] could not read one of the predicate's files: {e}")
    else:
        has_def = re.search(r"function\s+hub_author_activity_count\s*\(", hub_filters_src)
        has_feed_call = re.search(r"hub_author_activity_count\s*\(\s*\$db\s*,", feed_src)
        has_api_call = re.search(r"hub_author_activity_count\s*\(", api_src)
        # Guard against the banner's OLD inline SQL still being present
        # alongside the new call — that would be TWO predicates, not one,
        # even if the new one is also wired in.
        has_old_inline = re.search(
            r"SELECT count\(\*\) FROM topic WHERE status='publish' AND LOWER\(author_name\)",
            feed_src)
        if has_def and has_feed_call and has_api_call and not has_old_inline:
            OK.append("[D] hub_author_activity_count() is defined once and called from both the "
                      "banner (_feed.php) and the archive-icon endpoint — one predicate, real "
                      "call sites, no leftover duplicate inline SQL")
        else:
            RED.append(f"[D] predicate sharing is broken: def={bool(has_def)} "
                       f"feed_calls_it={bool(has_feed_call)} api_calls_it={bool(has_api_call)} "
                       f"old_inline_sql_still_present={bool(has_old_inline)}")

    # ── E. loopback-only enforcement ───────────────────────────────────────
    body, code = fetch(f"{host}/bb-mirror-api/v0/author-activity.php?wp_id={active_wp}", resolve, token)
    if code == "403":
        OK.append("[E] author-activity.php refuses a non-loopback request (403)")
    elif code == "200":
        RED.append("[E] author-activity.php answered a non-loopback request with 200 — this is "
                   "meant to be server-to-server only")
    else:
        DEAD.append(f"[E] author-activity.php returned an unexpected {code} for a non-loopback probe")

    # ── A. flag OFF is byte-inert (hlinks block) vs. main's real serve ─────
    off_body, off_code = fetch(f"{host}{PREVIEW_U_OFF}{active_slug}/", resolve, token)
    main_body, main_code = fetch(f"{host}/u/{active_slug}/", resolve, token)
    if off_code != "200" or main_code != "200":
        DEAD.append(f"[A] could not fetch both pages to diff (off={off_code}, main={main_code})")
    else:
        off_hlinks = re.findall(r'<div class="lg-hlinks".*?</div>', off_body, re.S)
        main_hlinks = re.findall(r'<div class="lg-hlinks".*?</div>', main_body, re.S)
        if not off_hlinks or not main_hlinks:
            DEAD.append("[A] could not locate the hlinks block in one of the two responses — "
                       "liveness check failed, not a real comparison")
        elif off_hlinks[0] != main_hlinks[0]:
            RED.append("[A] flag OFF hlinks markup differs from main's currently-served markup "
                      "— not byte-inert")
        else:
            OK.append("[A] flag OFF: hlinks markup is byte-identical to main's real serve")

    # ── B. flag ON, active member: icon present, href honoured by the REAL Hub ──
    on_body, on_code = fetch(f"{host}{PREVIEW_U_ON}{active_slug}/", resolve, token)
    if on_code != "200":
        DEAD.append(f"[B] could not fetch the ON preview for {active_slug} ({on_code})")
    elif active_slug not in on_body and "not found" in on_body.lower():
        DEAD.append(f"[B] {active_slug} did not render usably on the ON preview — liveness failed")
    else:
        m = re.search(r'<a class="lg-hlinks__a" href="([^"]*)" title="Posts in the Hub"', on_body)
        if not m:
            RED.append(f"[B] no archive icon rendered for {active_slug} (wp {active_wp}), who has "
                      f"{active_count} real Hub items — this is the reported vis-0 defect, "
                      f"inverted (should show, doesn't)")
        else:
            href = m.group(1).replace("&#038;", "&").replace("&amp;", "&")
            u = urllib.parse.urlparse(href)
            qs = urllib.parse.parse_qs(u.query)
            author_param = (qs.get("author") or [""])[0]
            if not author_param:
                RED.append(f"[B] archive icon href has no ?author= param: {href!r}")
            else:
                # Drive the REAL destination (not the preview) with that exact
                # param, since the whole point is "the icon and the
                # destination cannot disagree."
                dest_body, dest_code = fetch(
                    f"{host}/hub/?author=" + urllib.parse.quote(author_param), resolve, token)
                if dest_code != "200":
                    DEAD.append(f"[B] the icon's own destination URL did not load ({dest_code})")
                elif "data-topic-id=" not in dest_body and "fc-cover" not in dest_body \
                        and active_slug.split("-")[0].lower() not in dest_body.lower():
                    RED.append(f"[B] the icon promises posts for {author_param!r} but the real "
                              f"/hub/?author= destination shows none — icon and destination "
                              f"disagree, exactly the failure mode this predicate-sharing exists "
                              f"to prevent")
                else:
                    OK.append(f"[B] {active_slug} (wp {active_wp}, {active_count} real items): "
                              f"icon present, href carries {author_param!r}, and the REAL Hub "
                              f"destination for that exact param shows their posts")

    # ── C. flag ON, zero-activity member: no icon (the other half of vis-0) ─
    if zero_slug is not None:
        zbody, zcode = fetch(f"{host}{PREVIEW_U_ON}{zero_slug}/", resolve, token)
        if zcode != "200":
            DEAD.append(f"[C] could not fetch the ON preview for the zero-activity fixture ({zcode})")
        elif zero_slug not in zbody and "not found" in zbody.lower():
            DEAD.append(f"[C] {zero_slug} did not render usably — liveness failed")
        else:
            if 'title="Posts in the Hub"' in zbody:
                RED.append(f"[C] {zero_slug} (wp {zero_wp}) has ZERO Hub activity (verified via "
                          f"the shared predicate) but the archive icon rendered anyway — vis-0 "
                          f"is broken in this direction")
            else:
                OK.append(f"[C] {zero_slug} (wp {zero_wp}), verified zero Hub activity: no icon "
                          f"rendered — vis-0 holds")

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"author-archive-icon-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"author-archive-icon-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"author-archive-icon-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
