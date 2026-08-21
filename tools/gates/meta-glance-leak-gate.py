#!/usr/bin/env python3
"""
meta-glance-leak-gate — gate 83, issue #166 (Ian 8/20: "Fix meta leak.")

THE DEFECT THIS EXISTS TO PREVENT COMING BACK. A profile's <meta
name="description">, og:description and twitter:description carried the
member's one-line "what you do" (users.at_a_glance) VERBATIM to logged-out
visitors, crawlers and link unfurls, while the rendered body correctly withheld
it behind the members gate. Measured 2026-08-20: 42 members on LIVE, 28 on
dev2. Of the 42, ZERO had chosen members-only — every one was the untouched
platform default that 1,917 of 1,933 members have never opened. The head and
the body simply disagreed about what that default meant.

The same defect lived on /p/ with practice tagline AND about under
PRACTICE_HEADER_DEFAULT = 'members' (3 practices, both boxes). A defect class
found TWICE is gated before the second fix — CRAFT-STANDARD. Hence one gate,
four surfaces.

WHAT IT ASSERTS, and why each is here rather than being obvious:

  A. THE LEAK IS SEALED. A public member whose header is NOT public, fetched
     anonymously, must not have their one-liner in the <head>. Measured against
     the real served page, because "the rule is in the source" is not the claim.

  B. THE PUBLIC-HEADER MEMBER STILL HAS THEIRS. This is the half that is easy
     to leave out and it is the half that makes §A mean anything: deleting all
     three tags outright would satisfy §A perfectly. 14 live members have a
     genuinely public one-liner and it is good SEO — the fix must be a ceiling,
     not a deletion. Both sides of the bracket, always.

  C. THE DESCRIPTION IS STILL A DESCRIPTION. §A is an ABSENCE assertion, and an
     absence assertion is vacuous without a liveness assertion — it passes just
     as happily on a page with no meta tags at all, on a styled 403, and on a
     box with no PHP. So the generic fallback must be PRESENT and must carry
     the member's display name.

  D. THE PRACTICE PAGE, same three assertions, its own ceiling.

  E. THE #107 FENCE, FROM THE OTHER SIDE. #107 lets the FEATURED CARD republish
     a members-only one-liner on the strength of a tickbox that says so. That
     consent covers the card and nothing else — a tick is not permission to put
     the line in Google. So an OPTED-IN member with a members-only header must
     STILL be sealed here, with the consent flag ON. Gate 39 §G7 asserts the
     body of this same page; this asserts the head, which is the surface §G7
     deliberately does not fail on. Together they close it.

  F. THE RULE IS THE RENDERER'S OWN, NOT A COPY. u.php and p.php must reach
     their decision through Block::headerCeiling() / practiceHeaderCeiling().
     A local re-implementation of "is the header public" is precisely how the
     head and body drifted apart in the first place, so a second copy is a
     finding even while it happens to agree.

⚠️ IT AUDITS WHATEVER LG_GATE_HOST SERVES, WHICH BY DEFAULT IS MAIN. /u/ and
/p/ are symlinked out of ~/loothplatformv2-clean. On a lane branch, run
`tools/preview/lane-preview.sh up <lane>` and set LG_MGL_PREFIX=/preview/<lane>
or this gate will cheerfully audit main and tell you about main.

EXIT CODES follow run-all.sh's contract exactly: 0 green, 1 an open defect,
2 CANNOT RUN. Never 3, never 70 — run-all reads anything-but-0-or-2 as RED, so
a missing environment reported as 3 blocks every lane on the box.
"""
import html
import http.client
import os
import re
import ssl
import subprocess
import sys

RED, DEAD, OK = [], [], []

# THREE dirnames, not two — this file is tools/gates/x.py, so two lands on
# tools/ and every §F read fails. It does not fail loudly either: read() returns
# None, §F reports DEAD, and a gate whose source checks quietly never run reads
# as "2 checks could not run" forever. Same class as the pool endpoint's "THREE
# dots, not two" include bug, one language over.
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
HOST = os.environ.get("LG_GATE_HOST") or "dev2.loothgroup.com"
# Empty by default: audit the real routes. A lane sets this to
# /preview/<lane> to audit ITS OWN branch through lane-preview.sh.
PREFIX = os.environ.get("LG_MGL_PREFIX", "").rstrip("/")
DB = os.environ.get("LG_PROFILE_DB", "profile_app")


def read(path):
    try:
        with open(path, "r", encoding="utf-8") as f:
            return f.read()
    except OSError:
        return None


def psql(sql):
    """Passwordless peer auth as postgres — the same door the other gates use."""
    cmd = ["sudo", "-n", "-u", "postgres", "psql", "-d", DB, "-A", "-t", "-F", "|", "-c", sql]
    try:
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=25)
        return p.returncode, p.stdout.strip(), p.stderr.strip()
    except Exception as e:
        return -1, "", str(e)


def fetch(path):
    """Loopback, cookieless, full body.

    Loopback for box trap 2 (the public name goes through Cloudflare, whose bot
    403 reads as an outage) and because the dev gate authorises loopback — so
    the request is past the gate while carrying NO WP session. That combination
    is exactly a crawler's view, which is the viewer this gate is about.

    The whole body is read, never a 4000-byte prefix: a truncated page reads as
    "the text is absent" and would pass on the very leak being looked for.
    """
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    conn = http.client.HTTPSConnection("127.0.0.1", 443, timeout=12, context=ctx)
    try:
        conn.request("GET", path, headers={"Host": HOST})
        r = conn.getresponse()
        return r.status, r.read().decode("utf-8", "replace")
    except Exception:
        return None, ""
    finally:
        conn.close()


def split_head(page):
    """Head and body, split on the literal `</head>` STRING.

    Never on lines. `sed 's|<head>.*</head>||'` and any line-based equivalent
    strip NOTHING from a multi-line head, so the "body" still holds all three
    meta tags and the leak reads as if it were in the rendered page. That bit
    this lane's own first measurement.
    """
    head, sep, body = page.partition("</head>")
    return head, (body if sep else "")


def count(hay, needle):
    """Exact substring count of the HTML-ESCAPED needle.

    Escaped because a one-liner is arbitrary member text: a practice tagline
    here contains '&', which reaches the page as '&amp;', and a raw-needle
    search scored 0 against a page that was plainly leaking. Counting
    occurrences, never lines — `grep -c` cannot tell one tag from three.
    """
    return hay.count(html.escape(needle, quote=True))


def descriptions(head):
    return re.findall(
        r'<meta (?:name|property)="(?:description|og:description|twitter:description)"'
        r' content="([^"]*)"', head)


def probe(tag, path, needle, name, live_marker, expect_present):
    """One surface, fully bracketed.

    expect_present=False -> the needle must be gone from head AND body (§A/§D).
    expect_present=True  -> it must still be in the head (§B).
    Either way the page must have really rendered and must still carry a
    description naming the member; otherwise this is NO VERDICT, never a pass.
    """
    status, page = fetch(path)
    if status != 200:
        DEAD.append(f"[{tag}] {path} returned HTTP {status} — a styled 403, a redirect or a "
                    f"dead socket reads identically to 'the text is absent', so this is NO "
                    f"VERDICT rather than a pass")
        return
    head, body = split_head(page)
    if live_marker not in body:
        DEAD.append(f"[{tag}] {path} returned 200 but the body has no '{live_marker}' — the "
                    f"page did not render, so its silence proves nothing")
        return

    descs = descriptions(head)
    if len(descs) < 2:
        RED.append(f"[{tag}] {path} emits {len(descs)} description tag(s) — the fix is a "
                   f"CEILING on what they may say, not permission to delete them; a page "
                   f"with no description would pass an absence check for the wrong reason")
        return

    in_head, in_body = count(head, needle), count(body, needle)

    if expect_present:
        if in_head == 0:
            RED.append(f"[{tag}] {name}'s header is PUBLIC and their one-liner is gone from "
                       f"the head of {path} — the ceiling has been applied to a member who "
                       f"never had one. This is the SEO half of the bracket: withholding "
                       f"everyone's line seals the leak and silently degrades the members "
                       f"whose line is legitimately public")
        else:
            OK.append(f"[{tag}] a public-header member's one-liner still reaches the head "
                      f"({in_head} tags) — the fix is a ceiling, not a deletion")
        return

    if in_head > 0:
        RED.append(f"[{tag}] {name}'s one-liner is in the CRAWLER-VISIBLE HEAD of {path} "
                   f"({in_head} tag(s)) while their header withholds it from the rendered "
                   f"body ({in_body} in body) — this is #166, the leak reopened")
        return

    # Absence established. Now prove it is absence-with-a-page, not silence.
    if not any(name.split()[0] in d for d in descs):
        RED.append(f"[{tag}] the one-liner is gone from {path}, but no description names "
                   f"{name} either — the generic fallback is not rendering, so this page "
                   f"traded a leak for an empty description")
        return
    OK.append(f"[{tag}] withheld one-liner is absent from the head, and the generic "
              f"description still names the member: \"{descs[0][:60]}…\"")


# ── A/B/C: the profile page ─────────────────────────────────────────────────
def section_a_profile():
    rc, out, err = psql(
        "SELECT u.slug || '|' || u.display_name || '|' || btrim(u.at_a_glance) "
        "FROM users u WHERE u.profile_visibility='public' "
        "AND btrim(coalesce(u.at_a_glance,'')) <> '' "
        "AND coalesce((SELECT ps.visibility FROM profile_sections ps "
        "  WHERE ps.user_id=u.id AND ps.key='header'),'members') <> 'public' "
        "AND EXISTS (SELECT 1 FROM wp_user_bridge b WHERE b.user_id=u.id) "
        "ORDER BY u.slug LIMIT 1")
    if rc != 0 or not out:
        DEAD.append("[A] no public member with a members-only header and a written one-liner "
                    "on this box — the leak cannot be tested here")
        return
    slug, name, glance = out.split("|", 2)
    # lg-gate, NOT lg-idrow: for a members-only member seen anonymously the body
    # IS the join gate, and lg-idrow appears TWELVE times in this page's head as
    # CSS rules and zero times in the body — so lg-idrow would prove the page
    # rendered by finding a stylesheet.
    probe("A", f"{PREFIX}/u/{slug}/", glance, name, "lg-gate", expect_present=False)


def section_b_public_header_keeps_it():
    rc, out, err = psql(
        "SELECT u.slug || '|' || u.display_name || '|' || btrim(u.at_a_glance) "
        "FROM users u WHERE u.profile_visibility='public' "
        "AND btrim(coalesce(u.at_a_glance,'')) <> '' "
        "AND coalesce((SELECT ps.visibility FROM profile_sections ps "
        "  WHERE ps.user_id=u.id AND ps.key='header'),'members') = 'public' "
        "AND EXISTS (SELECT 1 FROM wp_user_bridge b WHERE b.user_id=u.id) "
        "ORDER BY u.slug LIMIT 1")
    if rc != 0 or not out:
        DEAD.append("[B] no member with a PUBLIC header and a one-liner on this box — the "
                    "no-op half of the bracket cannot be tested, so §A alone would also "
                    "pass on a fix that deleted the tags outright")
        return
    slug, name, glance = out.split("|", 2)
    # Here the profile really renders, so the liveness marker is real body markup.
    probe("B", f"{PREFIX}/u/{slug}/", glance, name, "lg-idrow", expect_present=True)


# ── D: the practice page, same class ────────────────────────────────────────
def section_d_practice():
    rc, out, err = psql(
        "SELECT p.slug || '|' || p.name || '|' || "
        "  btrim(coalesce(nullif(btrim(p.tagline),''), p.about)) "
        "FROM practices p WHERE p.archived_at IS NULL "
        "AND (btrim(coalesce(p.tagline,'')) <> '' OR btrim(coalesce(p.about,'')) <> '') "
        "AND coalesce((SELECT ps.visibility FROM profile_sections ps "
        "  WHERE ps.key = 'practice-header:' || p.id),'members') <> 'public' "
        "ORDER BY p.slug LIMIT 1")
    if rc != 0 or not out:
        DEAD.append("[D] no practice with a members-only header and text on this box")
        return
    slug, name, text = out.split("|", 2)
    probe("D", f"{PREFIX}/p/{slug}/", text, name, "lg-gate", expect_present=False)


# ── E: the #107 fence, from the head side ───────────────────────────────────
def section_e_consent_does_not_reach_the_head():
    rc, out, err = psql(
        "SELECT u.slug || '|' || u.display_name || '|' || btrim(u.at_a_glance) "
        "FROM users u WHERE u.featured_opt_in = true "
        "AND u.profile_visibility='public' "
        "AND btrim(coalesce(u.at_a_glance,'')) <> '' "
        "AND coalesce((SELECT ps.visibility FROM profile_sections ps "
        "  WHERE ps.user_id=u.id AND ps.key='header'),'members') <> 'public' "
        "ORDER BY u.slug LIMIT 1")
    if rc != 0 or not out:
        DEAD.append("[E] no opted-in member with a members-only one-liner — the #107 fence "
                    "cannot be tested from the head side today")
        return
    slug, name, glance = out.split("|", 2)
    status, page = fetch(f"{PREFIX}/u/{slug}/")
    if status != 200:
        DEAD.append(f"[E] /u/{slug}/ returned HTTP {status}")
        return
    head, body = split_head(page)
    if "lg-gate" not in body:
        DEAD.append(f"[E] /u/{slug}/ did not render the gate; its silence proves nothing")
        return
    if count(head, glance) > 0:
        RED.append(f"[E] {name} is OPTED IN to featuring and their members-only one-liner is "
                   f"in the head of their profile. #107's consent covers the FEATURED CARD "
                   f"and nothing else — a tick is not permission to put the line in Google. "
                   f"The card exception has leaked into the profile's meta tags")
    else:
        OK.append("[E] the #107 card exception does not reach the profile head: an opted-in "
                  "member's members-only one-liner is still withheld from crawlers")


# ── F: the rule is the renderer's own ───────────────────────────────────────
def strip_php_comments(path):
    """Source with every comment replaced by a space, via PHP's OWN tokenizer.

    Not a regex. Two reasons, and the second one already caused a false RED in
    this gate's first run:

      1. u.php contains `'https://' . $seoHost` — a naive `//.*$` strip eats the
         rest of that line and can turn working code into a missing call.
      2. THE VOCABULARY CHECK BELOW MUST READ CODE, NOT PROSE. The fix's own
         comment block spells out, at length, that this is NOT the #107 consent
         exception — so it necessarily contains the words featured_opt_in and
         consent_ack. Matching the raw file reported the WARNING AGAINST the
         defect as the defect. That is the same class as an assertion matching a
         string that also lives in a CSS rule or a JS comment; the cure is to
         assert something only real code can satisfy.
    """
    code = (
        'echo implode("", array_map(function($t){'
        ' return is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT]) ? " " : $t[1]) : $t;'
        ' }, token_get_all(file_get_contents($argv[1]))));'
    )
    try:
        p = subprocess.run(["php", "-r", code, "--", path],
                           capture_output=True, text=True, timeout=25)
        return p.stdout if p.returncode == 0 else None
    except Exception:
        return None


def section_f_rule_is_not_a_copy():
    for path, fn in (
        ("profile-app/web/u.php", "headerCeiling"),
        ("profile-app/web/p.php", "practiceHeaderCeiling"),
    ):
        full = os.path.join(REPO, path)
        src = strip_php_comments(full)
        if src is None:
            DEAD.append(f"[F] could not tokenize {path} — no verdict on how its head decides")
            continue
        # The SEO block only, anchored on CODE (the heading is a comment and has
        # just been stripped): $seoHost's assignment through the doctype.
        m = re.search(r"\$seoHost\s*=.*?<!doctype html>", src, re.S | re.I)
        if not m:
            DEAD.append(f"[F] could not locate the SEO block in {path} after stripping "
                        f"comments — the anchors moved; fix the gate, do not assume a pass")
            continue
        seo = m.group(0)
        if f"Block::{fn}(" not in seo:
            RED.append(f"[F] {path}'s SEO block does not call Block::{fn}() — if it now "
                       f"decides 'is the header public' some other way, that is a SECOND "
                       f"copy of the renderer's rule, and two copies drifting apart is "
                       f"exactly how #166 happened")
        elif re.search(r"(featured_opt_in|consent_ack|LG_FEATURED_CONSENT)", seo):
            RED.append(f"[F] {path}'s SEO block has the #107 consent vocabulary in its CODE "
                       f"(not merely its comments) — the card's exception must not become "
                       f"the head's rule (see §E)")
        else:
            OK.append(f"[F] {path}'s head reaches its decision through Block::{fn}(), the "
                      f"renderer's own rule, with no consent vocabulary in the code")


def main():
    print("=== meta-glance-leak-gate: #166 — private one-liners vs the crawler-visible head ===")
    print(f"    host={HOST}  prefix={PREFIX or '(none — auditing the REAL routes, i.e. main)'}")
    section_a_profile()
    section_b_public_header_keeps_it()
    section_d_practice()
    section_e_consent_does_not_reach_the_head()
    section_f_rule_is_not_a_copy()

    for m in OK:   print(f"  ok   {m}")
    for m in RED:  print(f"  RED  {m}")
    for m in DEAD: print(f"  DEAD {m}")

    if DEAD and not RED:
        print(f"meta-glance-leak-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if RED:
        print(f"meta-glance-leak-gate: RED — {len(RED)} finding(s)")
        return 1
    print(f"meta-glance-leak-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
