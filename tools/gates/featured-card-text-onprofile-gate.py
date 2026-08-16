#!/usr/bin/env python3
"""
featured-card-text-onprofile-gate — the featured card may only say things the
member's own profile says.

WHY IT EXISTS. Ian ticked the featured box on dev2 and reported the card's bio as
text that was "nowhere on my profile" — and it named a place (SoCal) his profile
contradicts (Ridgefield Park NJ). The trace found the card was in fact ALREADY
sourced from the About section and the text WAS on his profile, 46px tall and
visible, just below the fold. So the alarm was a false one — but answering it
took an evening of tracing, and THIS assertion answers it in one run.

That is the point of the gate: not that a defect exists today, but that the
question "is the card inventing text?" should be cheap to answer forever. If the
card is ever re-sourced from a column the profile does not render — the exact
thing keeper feared — this goes red the same day instead of surfacing as a
member's confusion weeks later.

WHAT IT ASSERTS, for every member who has opted in (not only whoever is featured
right now, so a future pick cannot surprise):

  A. THE CARD'S ROLE LINE appears in that member's rendered public profile.
     Sourced from users.at_a_glance, falling back to business_name.
  B. THE CARD'S BIO appears in that member's rendered public profile.
     Sourced from profile_sections key='about', visibility='public'.

Both are compared against the RENDERED HTML of /u/<slug>, entity-decoded and
whitespace-normalised, because the profile escapes and re-wraps what it prints —
comparing raw bytes would fail on an em-dash and prove nothing.

LIVENESS IS ASSERTED, not assumed: a dev-gate 403 is a perfectly well-formed page
in which NOTHING is a substring of anything, so every check would "pass" by
finding no text to look for. A profile that does not render the member's own
display name is treated as unusable, not as evidence.

Exit codes follow run-all.sh: 0 green, 1 red, 2 no verdict.
"""

import html
import json
import re
import subprocess
import sys
import urllib.request

HOST = "https://dev2.loothgroup.com"
OK, RED, DEAD = [], [], []


def psql(sql):
    try:
        p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "profile_app",
                            "-A", "-t", "-F", "\x1f", "-c", sql],
                           capture_output=True, text=True, timeout=30)
    except Exception as e:                                    # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:200]
    return p.stdout.strip("\n"), ""


def gate_token():
    try:
        out = subprocess.run(["bash", "tools/gates/gate-env.sh"],
                             capture_output=True, text=True, timeout=20).stdout
    except Exception:                                         # noqa: BLE001
        return None
    for line in out.splitlines():
        if line.startswith("LG_GATE_TOKEN="):
            return line.split("=", 1)[1]
    return None


def fetch_profile(slug, token):
    """The rendered public profile, through the box-local dev gate."""
    cmd = ["curl", "-sk", "-m", "20", "--resolve", "dev2.loothgroup.com:443:127.0.0.1"]
    if token:
        cmd += ["-H", f"Cookie: loothdev_auth={token}"]
    cmd += [f"{HOST}/u/{slug}"]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
        return p.stdout if p.returncode == 0 else None
    except Exception:                                         # noqa: BLE001
        return None


def norm(s):
    """Entity-decode, strip tags, collapse whitespace — the profile escapes and
    re-wraps what it prints, so a raw byte compare would fail on an em-dash."""
    s = html.unescape(s or "")
    s = re.sub(r"<[^>]+>", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def main():
    print("=== featured-card-text-onprofile-gate: the card may only say what the profile says ===")

    token = gate_token()
    if not token:
        print("featured-card-text-onprofile-gate: NO VERDICT — no dev-gate token")
        return 2

    # JSON, not columns. An About section contains NEWLINES — Ian's is nine
    # paragraphs — so a line-oriented psql read splits ONE member across a dozen
    # "rows" and then reports each paragraph as a profile that would not fetch.
    # It did exactly that on the first run. Any field that can hold prose has to
    # come back in a container that survives newlines.
    rows, err = psql("""
        SELECT coalesce(json_agg(t)::text, '[]') FROM (
          SELECT u.slug AS slug, u.display_name AS name,
                 -- The card uses the glance ONLY when the header block is public
                 -- (no header row => Block::HEADER_DEFAULT 'members' => anon never
                 -- sees it), and About ONLY when it is public AND in the resolved
                 -- layout. The gate resolves them the SAME way the card does, so it
                 -- asserts what ships rather than a parallel idea of it.
                 CASE WHEN coalesce((SELECT h.visibility FROM profile_sections h
                                      WHERE h.user_id = u.id AND h.key = 'header'), 'members') = 'public'
                      THEN coalesce(nullif(trim(u.at_a_glance), ''), '') ELSE '' END AS glance,
                 coalesce(nullif(trim(u.business_name), ''), '') AS biz,
                 coalesce((SELECT trim(ps.data->>'text') FROM profile_sections ps
                            WHERE ps.user_id = u.id AND ps.key = 'about'
                              AND ps.visibility = 'public'
                              AND u.profile_layout @> '["about"]'::jsonb), '') AS about
            FROM users u
           WHERE u.featured_opt_in = true
             AND u.profile_visibility = 'public'
             AND u.slug IS NOT NULL
           ORDER BY u.id) t;""")
    if rows is None:
        print(f"featured-card-text-onprofile-gate: NO VERDICT — {err}")
        return 2
    try:
        members = json.loads(rows)
    except Exception as e:                                    # noqa: BLE001
        print(f"featured-card-text-onprofile-gate: NO VERDICT — unparseable member list ({e})")
        return 2
    if not members:
        print("featured-card-text-onprofile-gate: NO VERDICT — no opted-in public member to check")
        return 2

    checked = 0
    for m_ in members:
        slug, name = m_.get("slug") or "", m_.get("name") or ""
        glance, biz, about = m_.get("glance") or "", m_.get("biz") or "", m_.get("about") or ""
        page = fetch_profile(slug, token)
        if not page:
            DEAD.append(f"[{slug}] profile did not fetch")
            continue
        flat = norm(page)

        # LIVENESS. A gated 403 renders fine and contains none of these strings, so
        # every check below would pass by finding nothing to look for.
        if norm(name) and norm(name) not in flat:
            DEAD.append(f"[{slug}] the profile does not render the member's own display name — "
                        f"almost certainly a dev-gate 403 or an error page, so nothing here is evidence")
            continue
        checked += 1

        role = glance or (biz if biz and not name.endswith(biz) else "")
        if role:
            if norm(role) in flat:
                OK.append(f"[{slug}] card ROLE text appears on the profile ({norm(role)[:40]!r})")
            else:
                RED.append(f"[{slug}] the card would print a ROLE the profile never shows: "
                           f"{norm(role)[:70]!r} — sourced from at_a_glance/business_name")
        if about:
            probe = norm(about)[:80]
            if probe and probe in flat:
                OK.append(f"[{slug}] card BIO text appears on the profile ({probe[:40]!r}…)")
            else:
                RED.append(f"[{slug}] the card would print a BIO the profile never shows: "
                           f"{probe[:70]!r} — sourced from profile_sections about/public")

    if checked == 0:
        DEAD.append("no profile rendered usably — every assertion above would be vacuous")

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"featured-card-text-onprofile-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"featured-card-text-onprofile-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"featured-card-text-onprofile-gate: GREEN — {len(OK)} assertions across {checked} member(s)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
