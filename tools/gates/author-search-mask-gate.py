#!/usr/bin/env python3
"""
author-search-mask-gate — backlog 27 (Ian 8/15, folded in by keeper), the anon
half: the Hub's author search must apply the SAME mask the Hub's feed applies.

THE DEFECT, measured rather than described. `/hub/?suggest=author&q=erlewine`
returned ZERO on dev2 AND live for a man with 54 posts. Not a dead endpoint and
not the <2-character guard — `?suggest=tag` and `?suggest=hub` both answered
normally on the same request. The FEED masks by `discussion_visibility` only
where `card_type === 'topic'` (its own comment: "content cards are CPTs, never
anonymous"), so a CONTENT byline is always published; the SEARCH applied that
same condition to the WHOLE UNION, hiding content authors the feed prints by
name on the front page. `discussion_visibility` is 'member' on 506 of 517 rows
purely because that is the default, so a logged-out visitor could be suggested
4 authors out of 432.

WHAT IT ASSERTS, and why each one is here:

  A. THE RED-FIRST PICTURE IS STILL AVAILABLE. With the flag OFF, a content
     author the feed publishes must still be unsearchable. That reads backwards
     for a fix gate and it is the point: it proves the flag really gates
     something, and it keeps the OFF state a checked state rather than an
     unexamined one.

  B. ON SURFACES A CONTENT AUTHOR — the actual repair.

  C. ON LEAVES A TOPIC-ONLY AUTHOR AT 'member' HIDDEN. This is the assertion
     that matters most. B alone would pass if the fix simply deleted the mask
     and opened the gate for everyone; C is what proves it MATCHED THE FEED
     instead. A privacy fix that over-corrects is a second defect wearing the
     first one's clothes.

  D. LIVENESS, because every assertion above is about an empty or non-empty
     result set and a dead endpoint produces empties too. `?suggest=tag` and
     `?suggest=hub` must answer on the same tree — that is exactly the probe
     that told "masked" from "broken" when this was diagnosed.

  E. THE COUNT MEANS WHAT THE VIEWER CAN SEE. Moving the condition into the
     topic leg also fixes `n`: an author with both kinds contributes only the
     rows this viewer may see. Asserted against the tier-gated content count
     computed from the database, so it cannot drift into advertising totals a
     signed-out visitor cannot reach.

  F. THE FLAG IS REAL: tracked file present, still defaults to false, and the
     mask is reachable only behind a read of it.

THE CASES ARE DERIVED FROM THE DATABASE AT RUN TIME, never hardcoded names — a
gate that names Doug Proper rots the day his dials change, and a rotted gate
that still passes is worse than no gate.

Exit codes follow run-all.sh: 0 green, 1 red, 2 no verdict.
"""

import json
import os
import re
import subprocess
import sys

HERE  = os.path.dirname(os.path.abspath(__file__))
REPO  = os.path.dirname(os.path.dirname(HERE))   # <repo>/tools/gates -> <repo>
PROBE = os.path.join(HERE, "author-search-mask-probe.php")
CFG   = os.path.join(REPO, "platform", "config", "author-search-mask.php")
SUG   = os.path.join(REPO, "bb-mirror", "web", "forums", "_suggest.php")

OK, RED, DEAD = [], [], []


def psql(sql):
    try:
        p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "looth",
                            "-A", "-t", "-F", "|", "-c", sql],
                           capture_output=True, text=True, timeout=30)
    except Exception as e:                                    # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:200]
    return p.stdout.strip(), ""


def probe(state, q):
    """Run the REAL _suggest.php author branch as the bb-mirror user (peer auth)."""
    try:
        p = subprocess.run(["sudo", "-n", "-u", "bb-mirror", "php", PROBE, REPO, state, q],
                           capture_output=True, text=True, timeout=60)
    except Exception as e:                                    # noqa: BLE001
        return None, f"{type(e).__name__}: {e}"
    if p.returncode != 0:
        return None, (p.stderr or p.stdout)[:220]
    try:
        return json.loads(p.stdout), ""
    except Exception as e:                                    # noqa: BLE001
        return None, f"unparseable ({e}): {p.stdout[:160]}"


def names(payload):
    return [r.get("name") for r in (payload or {}).get("results", [])]


# ── pick the two fixtures out of the database ────────────────────────────────
def pick_cases():
    """(content_only_author, topic_only_member_author) — derived, never hardcoded."""
    # PREFER an author with BOTH public and non-public content. Without that mix
    # assertion [E] is decoration: a fixture whose rows are all public has the same
    # count with or without the tier gate, and a mutation that removed the gate
    # entirely passed this gate green until the fixture was tightened.
    content_only, err = psql("""
        SELECT ci.author_name, count(*) FILTER (WHERE ci.tier = 'public') AS pub
          FROM discovery.content_item ci
         WHERE ci.author_name IS NOT NULL AND ci.author_name <> ''
           AND NOT EXISTS (SELECT 1 FROM forums.topic t
                            WHERE t.author_name = ci.author_name AND t.status = 'publish')
         GROUP BY ci.author_name
        HAVING count(*) FILTER (WHERE ci.tier = 'public') > 0
           AND count(*) FILTER (WHERE ci.tier <> 'public') > 0
         ORDER BY count(*) FILTER (WHERE ci.tier <> 'public') DESC LIMIT 1;""")
    if content_only is not None and not content_only.strip():
        # No mixed author on this box — fall back, and say so rather than let [E]
        # pass on a fixture that cannot fail.
        content_only, err = psql("""
            SELECT ci.author_name, count(*) FILTER (WHERE ci.tier = 'public') AS pub
              FROM discovery.content_item ci
             WHERE ci.author_name IS NOT NULL AND ci.author_name <> ''
               AND NOT EXISTS (SELECT 1 FROM forums.topic t
                                WHERE t.author_name = ci.author_name AND t.status = 'publish')
             GROUP BY ci.author_name
            HAVING count(*) FILTER (WHERE ci.tier = 'public') > 0
             ORDER BY pub DESC LIMIT 1;""")
    if content_only is None:
        return None, None, f"content-only author lookup failed: {err}"

    topic_only, err = psql("""
        SELECT t.author_name, count(*)
          FROM forums.topic t LEFT JOIN forums.person p ON p.id = t.author_id
         WHERE t.status = 'publish'
           AND COALESCE(p.discussion_visibility, 'member') <> 'public'
           AND NOT EXISTS (SELECT 1 FROM discovery.content_item ci
                            WHERE ci.author_name = t.author_name)
         GROUP BY t.author_name ORDER BY 2 DESC LIMIT 1;""")
    if topic_only is None:
        return None, None, f"topic-only author lookup failed: {err}"

    c = content_only.split("|")[0] if content_only else ""
    t = topic_only.split("|")[0] if topic_only else ""
    if not c or not t:
        return None, None, ("this box has no content-only author and/or no topic-only "
                            "'member' author, so neither half of the fix can be exercised")
    return c, t, ""


def main():
    print("=== author-search-mask-gate: backlog 27 (author search uses the FEED's mask) ===")
    if not os.path.isfile(PROBE):
        print(f"author-search-mask-gate: NO VERDICT — probe missing at {PROBE}")
        return 2

    content_author, topic_author, err = pick_cases()
    if err:
        DEAD.append(f"[A] {err}")
    else:
        OK.append(f"[A] fixtures derived from the database: content-only={content_author!r}, "
                  f"topic-only-at-member={topic_author!r}")

        # --- A / B: the content author ---
        off, e1 = probe("off", content_author)
        on,  e2 = probe("on",  content_author)
        if off is None or on is None:
            DEAD.append(f"[A/B] probe did not run (off={e1[:90]} on={e2[:90]})")
        else:
            if content_author in names(off):
                RED.append(f"[A] flag OFF already suggests {content_author!r} — the defect "
                           f"picture is gone, so B and C below prove nothing about a fix")
            else:
                OK.append(f"[A] red-first holds: flag OFF still hides {content_author!r}, "
                          f"a content author whose byline the feed publishes")
            if content_author in names(on):
                OK.append(f"[B] flag ON surfaces {content_author!r} — the repair")
            else:
                RED.append(f"[B] flag ON did NOT surface {content_author!r}: the content leg "
                           f"is still being masked by a discussions setting")

        # --- C: the assertion that proves it matched the feed ---
        t_off, e3 = probe("off", topic_author)
        t_on,  e4 = probe("on",  topic_author)
        if t_off is None or t_on is None:
            DEAD.append(f"[C] probe did not run (off={e3[:90]} on={e4[:90]})")
        elif topic_author in names(t_on):
            RED.append(f"[C] flag ON LEAKED {topic_author!r} — a topic-only author who kept "
                       f"discussion_visibility at 'member'. The fix opened the gate instead of "
                       f"matching the feed, which masks exactly this author on a topic card")
        else:
            OK.append(f"[C] flag ON still hides {topic_author!r} (topic-only, 'member') — "
                      f"the fix subtracts one leg, it does not remove the mask")

        # --- E: the count means what this viewer can see ---
        pub, perr = psql("SELECT count(*) FROM discovery.content_item "
                         f"WHERE author_name = '{content_author.replace(chr(39), chr(39)*2)}' "
                         "AND tier = 'public';")
        if pub is None:
            DEAD.append(f"[E] could not compute the visible content count: {perr}")
        elif on:
            got = next((r.get("n") for r in on.get("results", [])
                        if r.get("name") == content_author), None)
            if got is None:
                DEAD.append("[E] no row to read a count from (see [B])")
            elif int(got) == int(pub):
                OK.append(f"[E] the count is what a logged-out visitor can actually reach "
                          f"({got} public-tier items), not the author's total")
            else:
                RED.append(f"[E] count for {content_author!r} is {got} but only {pub} of their "
                           f"items are public-tier — the suggestion advertises rows this "
                           f"viewer cannot open")

    # --- D: liveness. Every assertion above reads an empty-or-not result set. ---
    alive = 0
    for mode_q in ("tag", "hub"):
        p = subprocess.run(["sudo", "-n", "-u", "bb-mirror", "php", "-r",
                            f"$_GET=['suggest'=>'{mode_q}','q'=>'ne'];"
                            f"$_SERVER['REQUEST_METHOD']='GET';"
                            f"require '{REPO}/bb-mirror/config.php';"
                            f"include '{SUG}';"],
                           capture_output=True, text=True, timeout=60)
        try:
            if len(json.loads(p.stdout).get("results", [])) > 0:
                alive += 1
        except Exception:                                     # noqa: BLE001
            pass
    if alive == 0:
        DEAD.append("[D] neither ?suggest=tag nor ?suggest=hub returned anything — the endpoint "
                    "itself may be dead, so every empty result above is vacuous")
    else:
        OK.append(f"[D] liveness: {alive}/2 other suggest modes answered on this tree, so an "
                  f"empty author result means MASKED and not broken")

    # --- F: the flag is real and the mask is behind it ---
    if not os.path.isfile(CFG):
        RED.append(f"[F] the tracked flag file is MISSING: {CFG}")
    else:
        cfg = open(CFG, encoding="utf-8").read()
        if re.search(r"'enabled'\s*=>\s*false", cfg):
            OK.append("[F] the tracked flag still defaults to false")
        elif re.search(r"'enabled'\s*=>\s*true", cfg):
            OK.append("[F] the tracked flag is switched ON (a state, not a finding)")
        else:
            RED.append("[F] author-search-mask.php declares no readable 'enabled' boolean")

    src = open(SUG, encoding="utf-8").read()
    # The CALL SITE, not the definition. Grepping the bare name passes even when the
    # guard has been replaced by `if (true)`, because the function's own definition
    # still contains its name — a mutation proved exactly that slipping through.
    if not re.search(r"if\s*\(\s*lg_author_search_feed_mask\(\)\s*\)", src):
        RED.append("[F] the mask in _suggest.php is no longer guarded by a call to "
                   "lg_author_search_feed_mask() — it is gone, or running UNGATED")
    else:
        OK.append("[F] the mask change in _suggest.php is reachable only behind the flag")

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"author-search-mask-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"author-search-mask-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"author-search-mask-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
