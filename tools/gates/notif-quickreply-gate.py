#!/usr/bin/env python3
"""notif-quickreply gate — tap a notification, get a reply modal (Ian 7/30, layout A).

WHY THIS GATE EXISTS IN THE SHAPE IT DOES
-----------------------------------------
Three defects reached Ian on 2026-07-30 through gates that asserted only what should be
PRESENT. A gate that checks "my quote renders" cannot see a composer that kept the last
reply's text, a reaction row that grew a composer it should never have, or a flag whose
OFF state quietly does something. So this gate is built around two ideas:

  1. THE ABSENCES ARE ASSERTED, not just the presences. With the flag off the quote
     branch must be UNREACHABLE — not merely uncalled — and the page must carry no
     attribute at all.
  2. THE ABSENCE CHECKS ARE PROVED NON-VACUOUS. `--expect-off` run against a flag-ON
     build MUST fail. That counter-run is the only thing separating "the OFF state is
     correct" from "I asserted nothing and it passed".

Run it both ways; run-all.sh runs the OFF mode, which is the state main ships in:

    python3 tools/gates/notif-quickreply-gate.py --expect-off      # the shipped state
    python3 tools/gates/notif-quickreply-gate.py --expect-on       # the feature works
    python3 tools/gates/notif-quickreply-gate.py --expect-off --force-flag-on
                                                 # ^ counter-proof: MUST fail

THREE STATES, not two (run-all.sh's rule): a check that cannot run reports SKIP and is
counted separately. A gate that silently degrades to "nothing to check" is the failure
mode this whole file is written against.
"""

import argparse
import os
import re
import subprocess
import sys
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
TOPIC_PHP = REPO / "bb-mirror" / "api" / "v0" / "topic.php"

PASS, FAIL, SKIP = [], [], []


def ok(name, detail=""):
    PASS.append(name)
    print(f"  PASS  {name}" + (f" — {detail}" if detail else ""))


def bad(name, detail=""):
    FAIL.append(name)
    print(f"  FAIL  {name}" + (f" — {detail}" if detail else ""))


def skip(name, why):
    SKIP.append(name)
    print(f"  SKIP  {name} — {why}")


def check(name, cond, detail=""):
    ok(name, detail) if cond else bad(name, detail)


# ── fixtures: resolve a REAL reply out of the mirror ─────────────────────────────
# Pinned ids rot. Every id below is discovered at run time, and if the mirror cannot
# be reached the behavioural half SKIPs loudly rather than passing on an empty set.
def psql(db, sql):
    try:
        r = subprocess.run(
            ["sudo", "-n", "-u", "postgres", "psql", "-d", db, "-tA", "-F", "|", "-c", sql],
            capture_output=True, text=True, timeout=25,
        )
        if r.returncode != 0:
            return None
        return [ln for ln in r.stdout.strip().splitlines() if ln.strip()]
    except Exception:
        return None


def fixtures():
    """(forum_slug, topic_slug, reply_id, other_topic_reply_id, private_forum/topic)."""
    rows = psql("looth", """
        SELECT f.slug, t.slug, r.id, r.topic_id
          FROM forums.reply r
          JOIN forums.topic t ON t.id = r.topic_id
          JOIN forums.forum f ON f.id = t.forum_id
         WHERE r.status = 'publish' AND f.visibility = 'public'
           AND length(coalesce(r.content_html, '')) > 40
         ORDER BY r.id DESC LIMIT 40
    """)
    if not rows:
        return None
    parsed = [ln.split("|") for ln in rows]
    base = parsed[0]
    # a reply belonging to a DIFFERENT topic — the cross-topic probe
    foreign = next((p for p in parsed if p[3] != base[3]), None)
    priv = psql("looth", """
        SELECT f.slug, t.slug FROM forums.topic t
          JOIN forums.forum f ON f.id = t.forum_id
         WHERE f.visibility <> 'public' LIMIT 1
    """)
    return {
        "forum": base[0], "topic": base[1], "reply": base[2],
        "foreign_reply": foreign[2] if foreign else None,
        "private": priv[0].split("|") if priv else None,
    }


def run_topic_php(query, flag_on, script=None):
    """Execute topic.php as the bb-mirror role (peer auth). None if it cannot run."""
    env = dict(os.environ)
    env["REQUEST_METHOD"] = "GET"
    env["REQUEST_URI"] = "/bb-mirror-api/v0/topic?" + query
    env.pop("LG_BB_MIRROR_NOTIF_QUICKREPLY", None)
    if flag_on:
        env["LG_BB_MIRROR_NOTIF_QUICKREPLY"] = "1"
    try:
        r = subprocess.run(
            ["sudo", "-n", "-u", "bb-mirror", "env",
             *(f"{k}={v}" for k, v in env.items() if k.startswith(("REQUEST_", "LG_"))),
             "php", str(script or TOPIC_PHP)],
            capture_output=True, text=True, timeout=40, cwd=str(TOPIC_PHP.parent),
        )
        return r.stdout
    except Exception:
        return None


def src(rel):
    p = REPO / rel
    return p.read_text(encoding="utf-8", errors="replace") if p.exists() else ""


# ── A. WIRING — true in both flag states; these are what fail against main ───────
def wiring_checks():
    print("\nA. WIRING (flag-independent — these fail against a build without the feature)")

    hub = src("webroot/hub-polish.js")
    # The scrub, not the slot. A slot that is never cleared is the prefill bug with a
    # new name: the composer sheet is built once and reused, so a quote left standing
    # reappears above an unrelated reply opened later from a feed card.
    has_slot = "lgc-quote" in hub
    scrub = re.search(r"quoteEl\s*=\s*sh\.querySelector\('#lgc-quote'\)\s*;\s*\n\s*if\s*\(quoteEl\)\s*\{\s*quoteEl\.innerHTML\s*=\s*''", hub)
    check("composer exposes a quote slot", has_slot)
    check("quote slot is CLEARED on every composer open (prefill-bug guard)",
          bool(scrub),
          "unconditional clear must precede the conditional fill")
    # The clear must come BEFORE the fill, or every open wipes what it just set.
    if has_slot and scrub:
        clear_at = hub.index("quoteEl.innerHTML = ''")
        fill_at = hub.find("o.quoteHtml")
        check("clear precedes fill (order, not just presence)", -1 < clear_at < fill_at)

    # ── THE FILE MUST EXIST BEFORE ANYTHING IS ASSERTED ABOUT ITS CONTENTS ──────
    # Caught by running this gate against origin/main: "no bodyText" and "no write
    # path" both PASSED there, because the file is absent, src() returns "", and the
    # empty string trivially contains neither. Two green checks asserting nothing —
    # the precise failure class this gate was written against, found in the gate
    # itself. Every content assertion below is now gated on the file being real.
    nqr = src("webroot/notif-reply.js")
    nqr_exists = len(nqr) > 500
    check("notif-reply.js exists (guards the checks below from passing vacuously)",
          nqr_exists, f"{len(nqr)} bytes")

    if nqr_exists:
        # No bodyText is ever passed into the composer from the notification path —
        # that is what keeps a fresh reply blank-or-own-draft (Ian: "Keep drafts per
        # topic"). The comment naming the rule is stripped before testing, so the
        # comment cannot be what satisfies the check.
        check("notification path passes NO bodyText into the composer",
              "bodyText" not in re.sub(r"//.*", "", nqr),
              "seeding the editor with the quoted reply is the bug edit-post-parity fixed")

        # No second write path — the charter's hard line.
        writes = re.findall(r"method\s*:\s*['\"](POST|PUT|DELETE)['\"]", nqr)
        check("notif-reply.js contains NO write path", not writes,
              f"found {writes}" if writes else "read-only, posts through the existing composer")
    else:
        bad("notification path passes NO bodyText into the composer", "file absent")
        bad("notif-reply.js contains NO write path", "file absent")

    # Reply-shaped types ONLY, mirrored in both surfaces, reactions excluded.
    want = {"forum.reply_to_topic", "forum.reply_to_reply", "forum.mention", "forum.followed_topic"}
    banned = {"reaction.on_post", "message", "connection_request", "connection_accept"}
    for rel, marker in (("webroot/bottom-nav.js", "LT_QUICKREPLY_TYPES"),
                        ("lg-shared/social-modals.js", "NQR_TYPES")):
        body = src(rel)
        m = re.search(marker + r"\s*=\s*\{(.*?)\}", body, re.S)
        if not m:
            bad(f"{rel}: quick-reply type list present")
            continue
        got = set(re.findall(r"'([a-z_.]+)'\s*:", m.group(1)))
        check(f"{rel}: exactly the 4 reply types", got == want, f"got {sorted(got)}")
        check(f"{rel}: reactions/DMs/connections EXCLUDED", not (got & banned),
              "a reaction has nothing to reply to")

    # COALESCED ROWS must say so. The backend merges a second replier into one row and
    # re-points the link at the NEWEST reply, so the modal quotes the most recent of
    # several. Showing one and silently hiding three is a quiet untruth, and it is not
    # rare: 3 of 17 reply rows on live are coalesced, one from four people.
    check("coalesced rows are declared, not silently truncated",
          nqr_exists and "lgc-quote__multi" in nqr and "most recent of" in nqr)
    for rel in ("webroot/bottom-nav.js", "lg-shared/social-modals.js"):
        body = src(rel)
        check(f"{rel}: actor_count reaches the modal",
              "data-notif-actors" in body and "actors:" in body,
              "rendered on the row AND threaded through the handler")

    # The row must carry the type, or the surfaces cannot tell a reply from a reaction
    # (both deep-link the same ?topic= URL).
    for rel in ("webroot/bottom-nav.js", "lg-shared/social-modals.js"):
        check(f"{rel}: rows render data-notif-type", "data-notif-type" in src(rel))

    # Fail-open contract: false means "navigate as before", never "swallow the tap".
    for rel in ("webroot/bottom-nav.js", "lg-shared/social-modals.js"):
        body = src(rel)
        check(f"{rel}: preventDefault ONLY when the modal took the intent",
              re.search(r"window\.lgOpenNotifReply\(\{[^}]*\}\)\)\s*\{\s*\n\s*e\.preventDefault\(\)", body) is not None,
              "preventDefault must be inside the success branch")

    # Dark: both signals, or a panel renders white on a dark page.
    check("notif-reply.js dark matches BOTH theme signals",
          nqr_exists and 'data-lguser-theme="dark"' in nqr and 'data-lguser-dark="1"' in nqr)
    # Sage tints do not re-point for dark on /hub — dark values must be explicit pins.
    # `bool(dark_block)` is load-bearing: without it an absent file yields an empty
    # block that contains no token and would pass.
    dark_block = "\n".join(l for l in nqr.splitlines() if l.strip().startswith("dk("))
    check("dark values are explicit pins, not sage tokens",
          bool(dark_block) and "--lg-sage-tint" not in dark_block,
          "tokens in a dark rule are the defect a previous lane shipped")


# ── B. BEHAVIOUR — depends on the flag; this is the absence/presence half ────────
def behaviour_checks(expect_on, force_flag_on):
    state = "ON" if force_flag_on else "OFF"
    print(f"\nB. BEHAVIOUR (server flag {state}; expecting the feature "
          f"{'ON' if expect_on else 'OFF'})")

    fx = fixtures()
    if not fx:
        skip("server behaviour", "mirror unreachable (needs sudo -u postgres) — NOT counted as pass")
        return
    q = f"forum={fx['forum']}&topic={fx['topic']}&reply_context={fx['reply']}"
    out = run_topic_php(q, flag_on=force_flag_on)
    if out is None:
        skip("server behaviour", "cannot execute topic.php as bb-mirror — NOT counted as pass")
        return

    quoted = "lg-nqr-quote" in out
    if expect_on:
        check("ON: the source reply is quoted", quoted)
        check("ON: quote carries the ids the composer needs",
              'data-topic-id="' in out and 'data-forum-id="' in out)
        # CROSS-TOPIC: pairing a public topic's slugs with another topic's reply id
        # must NOT return that reply. This is the probe that would catch someone
        # reading a hidden forum's reply through a public topic's gate.
        if fx["foreign_reply"]:
            fout = run_topic_php(
                f"forum={fx['forum']}&topic={fx['topic']}&reply_context={fx['foreign_reply']}",
                flag_on=force_flag_on) or ""
            check("ON: a reply from ANOTHER topic is refused (falls back to the OP)",
                  'data-reply-id="0"' in fout,
                  "r.topic_id scoping is what stops the gate being walked around")
        else:
            skip("cross-topic refusal", "no second topic with replies on this box")
    else:
        # THE ABSENCE. Not "no client calls it" — the branch must not fire at all.
        check("OFF: reply_context is INERT (no quote markup at any level)", not quoted,
              "the parameter was SENT and must have done nothing")
        check("OFF: the ordinary OP fragment is returned instead",
              "lg-fpd-op" in out or "lg-dmodal__note" in out)

    # The visibility gate holds in BOTH states — a private forum never quotes.
    if fx["private"]:
        pout = run_topic_php(
            f"forum={fx['private'][0]}&topic={fx['private'][1]}&reply_context=0",
            flag_on=force_flag_on) or ""
        check("private/hidden forum 404s and never quotes",
              "lg-nqr-quote" not in pout and "not found" in pout.lower())
    else:
        skip("private-forum gate", "no non-public forum on this box")

    # OFF must be a BYTE-IDENTICAL no-op vs main, even with the parameter present.
    if not force_flag_on:
        try:
            baseline = subprocess.run(
                ["git", "-C", str(REPO), "show", "origin/main:bb-mirror/api/v0/topic.php"],
                capture_output=True, text=True, timeout=25)
            if baseline.returncode == 0:
                tmp = TOPIC_PHP.parent / "_nqr_gate_baseline.php"
                tmp.write_text(baseline.stdout, encoding="utf-8")
                try:
                    base_out = run_topic_php(q, flag_on=False, script=tmp)
                    check("OFF: output is BYTE-IDENTICAL to origin/main, parameter and all",
                          base_out is not None and base_out == out,
                          f"{len(out)} bytes")
                finally:
                    tmp.unlink(missing_ok=True)
            else:
                skip("byte-identical vs main", "origin/main not fetched")
        except Exception as e:
            skip("byte-identical vs main", str(e))

    # The page-level absence: with the flag off, no attribute is emitted AT ALL —
    # not data-lg-notifreply="0". So a flag-off page differs from main by zero bytes.
    chrome = src("bb-mirror/web/_chrome.php")
    emits_conditionally = "lg_notif_quickreply_enabled()) ? ' data-lg-notifreply=\"1\"' : ''" in chrome
    check("_chrome.php emits the attribute ONLY when enabled (and nothing when off)",
          emits_conditionally)
    pwa = src("webroot/pwa.js")
    check("pwa.js requests notif-reply.js ONLY behind that attribute",
          re.search(r"data-lg-notifreply'\)\s*===\s*'1'\s*\)\s*\{\s*\n\s*inject\('looth-notif-reply-js'", pwa) is not None,
          "flag OFF must ship zero bytes, not dead code")


def main():
    ap = argparse.ArgumentParser()
    g = ap.add_mutually_exclusive_group()
    g.add_argument("--expect-on", action="store_true")
    g.add_argument("--expect-off", action="store_true")
    ap.add_argument("--force-flag-on", action="store_true",
                    help="run the server with the flag ON regardless of what is expected "
                         "— used to prove the OFF assertions are not vacuous")
    a = ap.parse_args()
    expect_on = a.expect_on
    force = a.force_flag_on or a.expect_on

    print("=" * 78)
    print("notif-quickreply gate — tap a notification → reply modal (Ian 7/30, layout A)")
    print("=" * 78)

    wiring_checks()
    behaviour_checks(expect_on, force)

    print("\n" + "-" * 78)
    print(f"  {len(PASS)} pass / {len(FAIL)} fail / {len(SKIP)} skip")
    if SKIP:
        print("  SKIPPED (not passes): " + "; ".join(SKIP))
    if FAIL:
        print("  FAILED: " + "; ".join(FAIL))
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
