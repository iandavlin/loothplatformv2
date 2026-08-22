#!/usr/bin/env python3
"""GATE 97 — the View-as switcher says Edit, and the VALUE it carries still says me.

#195. Ian, 2026-08-22, verbatim: "in the profile, I'd like the view as controls
in the privacy area to read edit instead of me."

⚠️ THE WHOLE POINT OF THIS GATE IS THAT THE LABEL AND THE VALUE MOVED APART.
Renaming a segmented control is the kind of change that looks safe and is only
safe as long as nobody "tidies up" the value to match the new word. Every
consumer on this platform keys on the VALUE `me` and nothing anywhere reads the
visible text:

    ?view=me                     the URL each position links to (u.php, p.php)
    $role === 'me'               the edit-mode predicate (u.php:127, p.php:50)
    data-role="me"               the SSR editor's button (_render.php)
    BOOT.role || 'me'            edit.js:13
    .lg-shell--owner + .lg-pmp   privacy-sheet.js's surface gate (not a label)

So the assertion here is deliberately a PAIR, per position: the text is the new
label AND the href/value still carries the old one. Asserting only the text
would go green on the change that actually breaks members — someone rewriting
?view=me to ?view=edit and taking edit mode down with it.

⚠️ AND IT ASSERTS THE RENDERED PAGE, NOT ONLY THE SOURCE. A source grep passes
on a string that lives in a comment or in prose (feedback-red-first-that-stays-
green, six cases in this repo). §A parses only INSIDE the seg element and
requires the anchor to carry the viewLink() call; §B and §C then render the real
page through real nginx and the real FPM pool and read the DOM back.

⚠️ /u/ AND /p/ ARE SYMLINKED OUT OF THE SERVING CHECKOUT, so a bare run measures
MAIN, not your branch (the #166 trap, docs/domains/PROFILE.md). A lane runs:

    tools/preview/lane-preview.sh up 195-edit-label
    LG_VIEWAS_BASE=https://dev2.loothgroup.com/preview/195-edit-label \\
      python3 tools/gates/viewas-label-gate.py

§A always reads THIS worktree, so the source half is true of your branch even
with no preview up. §B/§C say which base they measured, every run, so a green
can never quietly be a green about main.

THE THIRD SWITCHER IS UNREACHABLE AND IS SOURCE-ONLY ON PURPOSE.
profile-app/web/_render.php's topbar ("viewing as") carries the same three
positions, but edit.php:44 302s any member with a slug to /u/<slug>, and the
only slug-less rows on dev2 AND live (ids 1, 1702, 1703) are all unclaimed, so
they hit the claim interstitial before that editor ever renders. Zero members,
both boxes, measured 2026-08-22. Keeper's ruling (#195): change the string
anyway because dead surfaces revive, but build no render proof for a page nobody
can reach. §A scores it; §B and §C do not touch it.

FIXTURES ARE CHOSEN BY QUERY, never hardcoded — dev2 and live do not hold the
same members, and a slug from one 404s on the other.

Exit: 0 green · 1 finding · 2 could-not-run (run-all.sh reads 2 as NO VERDICT;
an environment it cannot reach must never read as a defect —
trap-gate-exit-code-3-blocks-every-lane).
"""
import http.cookiejar
import json
import os
import re
import ssl
import subprocess
import sys
import urllib.error
import urllib.parse
import urllib.request

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
BASE = os.environ.get("LG_VIEWAS_BASE", "https://dev2.loothgroup.com").rstrip("/")

# Does BASE serve THIS worktree, or the shared serving checkout? A lane preview
# points fastcgi straight at the branch's own files, so what it renders is this
# tree and a disagreement with §A is a real finding. The bare serve renders
# whatever ~/loothplatformv2-clean has pulled, which lags main after every merge
# — see the deploy-gap branch in §B for why that distinction is load-bearing.
SERVES_THIS_TREE = "/preview/" in BASE

OK, RED, DEAD, NOTE = [], [], [], []


def ok(sec, msg):
    OK.append(f"[{sec}] {msg}")


def red(sec, msg):
    RED.append(f"[{sec}] {msg}")


def dead(sec, msg):
    DEAD.append(f"[{sec}] {msg}")


def note(msg):
    NOTE.append(msg)


# ── the three switchers, and what each position must carry ──────────────────
# text  = the visible label AFTER #195
# value = a fragment that must still be present on that position's own element,
#         i.e. the thing every consumer keys on. If this moves, edit mode dies.
POSITIONS = [
    ("public", "Public", "public"),
    ("member", "Member", "member"),
    ("me",     "Edit",   "me"),      # <- the position #195 renamed
]

# ⚠️ THE SELECTORS TOLERATE EXTRA ATTRIBUTES ON PURPOSE. The first version
# demanded the opening tag verbatim, so adding one space inside it — a no-op
# control mutation in the red-first — made the gate announce that the View-as
# switcher had vanished. A gate that reddens on whitespace is not measuring the
# thing it names, and its findings stop being believed.
SEG_SPAN = r'<span class="lg-viewas__seg"[^>]*>(.*?)</span>'

# ⚠️ EVERY SHAPE, NOT ANY SHAPE. These were an any() first, and the red-first
# caught it immediately: rewriting viewLink('me') to viewLink('edit') left
# $role==='me' on the same anchor, so §A said the value was intact while the
# link had already moved. Two mutations (m04/m05) were saved only by §B, which
# needs a live surface — meaning the half of the gate that is supposed to be
# honest with no server was blind to the exact failure it exists for. A third
# (m06, data-role) got through entirely.
SOURCES = [
    # file, the element wrapping the seg, the shapes EVERY position must carry, why
    ("profile-app/web/u.php", SEG_SPAN,
     ["viewLink('{v}')", "$role==='{v}'"],
     "reachable — /u/<slug>?view=me, the privacy panel Ian named"),
    ("profile-app/web/p.php", SEG_SPAN,
     ["viewLink('{v}')", "$role==='{v}'"],
     "reachable — /p/<slug>?view=me, the practice page"),
    ("profile-app/web/_render.php", r'<div class="seg" id="role"[^>]*>(.*?)</div>',
     ['data-role="{v}"', "$role==='{v}'"],
     "UNREACHABLE — /profile/edit; source-only, see the module docstring"),
]


# ⚠️ A PHP TAG CONTAINS '>' AND NAIVE ATTRIBUTE PARSING SPLITS INSIDE IT. The
# first version of this gate matched `<a(.*?)>` and stopped at the '>' of `?>`,
# so every position's "text" came back as a slab of PHP and all six legs went
# RED against the correct tree. Mask the PHP tags to placeholders that hold no
# angle bracket, parse the HTML, then put them back for the value check.
def _mask_php(src):
    parts = []

    def sub(m):
        parts.append(m.group(0))
        return f"\x01{len(parts) - 1}\x01"

    return re.sub(r"<\?(?:php|=)?.*?\?>", sub, src, flags=re.S), parts


def _unmask(s, parts):
    return re.sub(r"\x01(\d+)\x01", lambda m: parts[int(m.group(1))], s)


def section_a_source():
    """Per position, in every switcher: the LABEL is the new word and the VALUE
    is untouched — parsed inside the seg element, never grepped file-wide."""
    sec = "A source"
    for rel, wrap, shape_tpl, why in SOURCES:
        path = os.path.join(REPO, rel)
        try:
            src = open(path, encoding="utf-8").read()
        except OSError as e:
            dead(sec, f"{rel}: unreadable ({e})")
            continue

        masked, parts = _mask_php(src)
        m = re.search(wrap, masked, re.S)
        if not m:
            # Not a finding about the label — the gate has lost its target and
            # must say so rather than pass. A stale file:line points at working
            # code (trap-stale-file-line-citation-points-at-working-code).
            dead(sec, f"{rel}: no seg element matched {wrap!r} — gate target moved, not a defect")
            continue

        els = [(_unmask(a, parts), _unmask(t, parts).strip())
               for a, t in re.findall(r"<(?:a|button)\b([^>]*)>(.*?)</(?:a|button)>",
                                      m.group(1), re.S)]
        if len(els) != 3:
            dead(sec, f"{rel}: seg holds {len(els)} positions, expected 3 — gate target moved")
            continue

        # ⚠️ MATCH BY VALUE, NEVER BY DOM ORDER. _render.php lists the positions
        # me/member/public — the REVERSE of u.php and p.php — so zipping against
        # a fixed order scored every one of its labels against the wrong
        # position and reported three findings about a correct file.
        for key, want_text, want_value in POSITIONS:
            shapes = [t.format(v=want_value) for t in shape_tpl]
            # Locate the position by ANY of its shapes, then demand ALL of them.
            # Locating on any is what lets the gate still find — and name — a
            # position whose value has been half-rewritten.
            hits = [(a, t) for a, t in els if any(s in a for s in shapes)]
            if len(hits) != 1:
                red(sec, f"{rel}: {len(hits)} of 3 positions carry the value {want_value!r} in "
                         f"any form, expected exactly 1. Every consumer keys on this string "
                         f"(?view=, $role===, data-role, BOOT.role); moving it takes edit mode "
                         f"down. #195 changed the LABEL only.")
                continue
            attrs, text = hits[0]
            missing = [s for s in shapes if s not in attrs]
            if missing:
                red(sec, f"{rel}: the {key} position is missing {missing} — it carries the value "
                         f"{want_value!r} in some places and not others, which is how half a "
                         f"rename ships. Present: {attrs.strip()!r}. #195 changed the LABEL only.")
            else:
                ok(sec, f"{rel}: the {key} position carries the value {want_value!r} in all "
                        f"{len(shapes)} places it is written")
            if text == want_text:
                ok(sec, f"{rel}: the {want_value!r} position reads {want_text!r}  ({why})")
            else:
                red(sec, f"{rel}: the {want_value!r} position reads {text!r}, expected "
                         f"{want_text!r}. #195 renamed the 'me' position to 'Edit'; the others "
                         f"did not move.")


def section_a2_no_stragglers():
    """A FOURTH copy of the switcher must not quietly keep the old word. This is
    the assertion that catches the next person adding a surface, not this one."""
    sec = "A2 drift"
    p = subprocess.run(["grep", "-rn", "--include=*.php", ">Me<",
                        os.path.join(REPO, "profile-app")],
                       capture_output=True, text=True)
    hits = [l for l in p.stdout.splitlines() if l.strip()]
    if hits:
        red(sec, "a shipped profile-app template still renders the label 'Me':\n      "
                 + "\n      ".join(hits)
                 + "\n      #195 renamed every reachable copy; a new one must move with them.")
    else:
        ok(sec, "no shipped profile-app template renders the old label 'Me'")
    # LIVENESS for that absence: the grep must be capable of finding the label
    # at all. "Nothing says Me" is trivially true of a checkout with no
    # templates in it (feedback-absence-assertion-needs-liveness).
    q = subprocess.run(["grep", "-rn", "--include=*.php", ">Edit<",
                        os.path.join(REPO, "profile-app")],
                       capture_output=True, text=True)
    n = len([l for l in q.stdout.splitlines() if l.strip()])
    if n >= 3:
        ok(sec, f"liveness: the same grep finds the NEW label in {n} templates, so it can see labels")
    else:
        dead(sec, f"liveness: the grep found only {n} copies of the new label — expected 3 "
                  f"(u.php, p.php, _render.php). Absence above proves nothing.")


# ── the rendered half ────────────────────────────────────────────────────────
def _mint_session():
    """A real WP session for a real profile owner, chosen BY QUERY."""
    rc, out, _ = _psql(
        "SELECT b.wp_user_id, u.slug FROM users u "
        "JOIN profiles p ON p.user_id = u.id "
        "JOIN wp_user_bridge b ON b.user_id = u.id "
        "WHERE COALESCE(TRIM(u.slug),'') <> '' AND u.archived_at IS NULL "
        "ORDER BY u.id LIMIT 1")
    if rc != 0 or not out.strip():
        return None, "no claimed, slugged, bridged member found to sign in as"
    wp_uid, u_slug = out.strip().split("|")[0], out.strip().split("|")[1]

    rc, out, _ = _psql(
        "SELECT pr.slug, u.slug FROM practices pr "
        "JOIN practice_members pm ON pm.practice_id = pr.id AND pm.role = 'owner' "
        "JOIN users u ON u.id = pm.user_id "
        "JOIN wp_user_bridge b ON b.user_id = u.id "
        "WHERE COALESCE(TRIM(pr.slug),'') <> '' ORDER BY pr.id LIMIT 1")
    p_slug = p_owner = None
    if rc == 0 and out.strip():
        p_slug, p_owner = out.strip().split("|")[0], out.strip().split("|")[1]

    mint = os.path.join(REPO, "tools", "preview", "mint-wp-session.php")
    if not os.path.exists(mint):
        return None, f"session minter missing at {mint}"
    return (wp_uid, u_slug, p_slug, p_owner, mint), None


def _psql(sql):
    p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "profile_app",
                        "-tAF|", "-c", sql], capture_output=True, text=True)
    return p.returncode, p.stdout, p.stderr


def _session_for(wp_uid, mint):
    p = subprocess.run(["sudo", "-n", "-u", "looth-dev", "env", f"LG_SHOT_UID={wp_uid}",
                        "wp", "eval-file", mint, "--skip-themes", "--path=/var/www/dev"],
                       capture_output=True, text=True)
    line = [l for l in p.stdout.splitlines() if l.strip().startswith("{")]
    if not line:
        return None
    return json.loads(line[0])


def _gate_token():
    p = subprocess.run(["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
                        "/etc/nginx/snippets/loothdev-tokens.conf"],
                       capture_output=True, text=True)
    toks = p.stdout.strip().splitlines()
    return toks[0] if toks else None


# ⚠️ /u/ NEEDS A COOKIE JAR, NOT A COOKIE HEADER. A member holding a WP session
# but no looth_id yet is 302'd through /looth-auth/issue to mint one and bounced
# straight back (u.php's invisible hop). A fetch that sends a fixed Cookie header
# and keeps nothing never acquires the minted cookie, so it bounces forever and
# urllib reports "redirect error that would lead to an infinite loop" — which
# reads like an outage and is really the gate refusing to hold its own session.
def _opener(cookies):
    host = urllib.parse.urlsplit(BASE).hostname or "dev2.loothgroup.com"
    jar = http.cookiejar.CookieJar()
    for name, value in cookies.items():
        jar.set_cookie(http.cookiejar.Cookie(
            0, name, value, None, False,
            host, False, False,
            "/", True, True, None, False, None, None, {}))
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    return urllib.request.build_opener(
        urllib.request.HTTPCookieProcessor(jar),
        urllib.request.HTTPSHandler(context=ctx))


def _fetch(url, cookies, opener=None):
    op = opener or _opener(cookies)
    req = urllib.request.Request(url, headers={"User-Agent": "lg-gate-97/1.0"})
    with op.open(req, timeout=25) as r:
        return r.status, r.read().decode("utf-8", "replace")


# ⚠️ EVERY CLASS NAME IN THIS FEATURE ALSO EXISTS AS A CSS RULE IN THE SAME
# RESPONSE, and the first version of this gate was wrecked by it twice:
#   · "lg-shell--owner" in html was the LIVENESS check. It appears 5 times as a
#     stylesheet rule on /u/ and once as markup, so it was true of a page whose
#     body is plain class="lg-shell" — a signed-out or preview render would have
#     sailed through it having measured nothing.
#   · the same string made §C report that ?view=member renders edit chrome, a
#     confident finding about a page that does no such thing.
# This is feedback-red-first-that-stays-green exactly: an assertion matching a
# string that also lives in a CSS rule. Strip style/script first, then assert.
def _markup(html):
    html = re.sub(r"<style\b.*?</style>", "", html, flags=re.S | re.I)
    return re.sub(r"<script\b.*?</script>", "", html, flags=re.S | re.I)


def _is_owner_shell(html):
    """The OWNER's editable shell as an ELEMENT — its class attribute really
    carries lg-shell--owner — not as a substring of a stylesheet."""
    for attrs in re.findall(r'<div class="([^"]*)"', _markup(html)):
        if "lg-shell--owner" in attrs.split():
            return True
    return False


def _seg_from_html(html):
    m = re.search(SEG_SPAN, _markup(html), re.S)
    if not m:
        return None
    return re.findall(r'<a\b([^>]*)>(.*?)</a>', m.group(1), re.S)


def section_b_rendered(ctx):
    """The real page, through real nginx and the real FPM pool: the third
    position reads Edit, its href still says view=me, and it is the current one."""
    sec = "B rendered"
    wp_uid, u_slug, p_slug, p_owner, mint = ctx

    sess = _session_for(wp_uid, mint)
    tok = _gate_token()
    if not sess or not tok:
        dead(sec, "could not mint a session / read the dev-gate token — not a finding")
        return
    cookies = {"loothdev_auth": tok, sess["cookie"]: sess["value"]}

    targets = [("/u/", f"{BASE}/u/{u_slug}?view=me", "the privacy panel (#195's own surface)",
                u_slug, wp_uid)]
    if p_slug:
        # /p/ renders the switcher only for its OWNER, so sign in as them.
        targets.append(("/p/", f"{BASE}/p/{p_slug}?view=me", "the practice page",
                        p_owner, None))

    print(f"  [{sec}] measuring BASE={BASE}  owner={u_slug}  practice={p_slug or '(none on this box)'}")

    for surface, url, why, who, uid in targets:
        if surface == "/p/" and p_owner and p_owner != u_slug:
            # The slug comes from our own users.slug and the /u/ route only
            # matches [\w-], but the query is built by interpolation, so refuse
            # anything outside that shape rather than rely on the column.
            if not re.fullmatch(r"[\w\-]+", p_owner or ""):
                dead(sec, f"{surface}: practice owner slug {p_owner!r} is not [\\w-] — refusing "
                          f"to interpolate it into SQL")
                continue
            rc, out, _ = _psql("SELECT b.wp_user_id FROM users u "
                               "JOIN wp_user_bridge b ON b.user_id = u.id "
                               f"WHERE u.slug = '{p_owner}' LIMIT 1")
            s2 = _session_for(out.strip(), mint) if (rc == 0 and out.strip()) else None
            if not s2:
                dead(sec, f"{surface}: could not sign in as the practice owner {p_owner!r}")
                continue
            c, warm = {"loothdev_auth": tok, s2["cookie"]: s2["value"]}, p_owner
        else:
            c, warm = cookies, u_slug

        # ⚠️ WARM THE SESSION ON /u/ FIRST. A WP session alone is not enough: the
        # member needs a minted looth_id, and only /u/ performs the invisible hop
        # through /looth-auth/issue that mints it. /p/ does NOT — asked cold it
        # renders the NON-OWNER page (52KB vs 104KB), which carries the seg's CSS
        # but none of its markup. The first version read that as "the View-as
        # switcher is gone" and reported a confident finding about a healthy page.
        op = _opener(c)
        try:
            _fetch(f"{BASE}/u/{warm}?view=me", c, op)
            status, html = _fetch(url, c, op)
        except (urllib.error.URLError, OSError) as e:
            dead(sec, f"{surface}: {url} unreachable ({e}) — not a finding")
            continue
        if status != 200:
            dead(sec, f"{surface}: {url} returned {status} — not a finding")
            continue

        # LIVENESS FIRST. A locked-out browser / gated response is identical in
        # shape and photographs as a clean pass having measured nothing
        # (trap-locked-out-browser-goes-vacuously-green). The owner's editable
        # shell — as an ELEMENT, see _is_owner_shell — is what proves this is
        # really the owner's own page.
        if not _is_owner_shell(html):
            dead(sec, f"{surface}: no element carries class lg-shell--owner ({len(html)} bytes) "
                      f"— signed out, gated, or the wrong member. Nothing below would mean "
                      f"anything, so this is NO VERDICT rather than a finding.")
            continue
        ok(sec, f"{surface}: liveness — the owner's editable shell rendered  ({why})")

        els = _seg_from_html(html)
        if els is None:
            red(sec, f"{surface}: the response carries no .lg-viewas__seg at all — the View-as "
                     f"switcher is gone from a page that rendered the owner shell.")
            continue
        if len(els) != 3:
            red(sec, f"{surface}: seg rendered {len(els)} positions, expected 3")
            continue

        for (key, want_text, want_value), (attrs, text) in zip(POSITIONS, els):
            text = re.sub(r"<[^>]+>", "", text).strip()
            href = (re.search(r'href="([^"]*)"', attrs) or [None, ""])[1]
            if text == want_text:
                ok(sec, f"{surface}: {key} position RENDERS {want_text!r}")
            elif not SERVES_THIS_TREE:
                # ⚠️ A DEPLOY GAP IS NOT A DEFECT, AND SCORING IT AS ONE BLOCKS
                # EVERY LANE. The shared serve renders whatever ~/loothplatformv2-
                # clean has PULLED, which lags main by design after any merge. If
                # this gate went RED because dev2 had not pulled yet, every lane
                # running run-all.sh would be stopped by a label. So on the shared
                # serve a text disagreement is NO VERDICT and says which side is
                # behind; on a lane preview — which serves this worktree directly
                # — the same disagreement is a real finding. The href, aria-current
                # and behaviour legs below score on BOTH, because they read the
                # same before and after #195 and so cannot be a deploy gap.
                dead(sec, f"{surface}: the serve renders {text!r} where this tree's source says "
                          f"{want_text!r}. That is a DEPLOY GAP (dev2 behind this branch), not a "
                          f"finding — §A already scored the source. Bring up a lane preview and "
                          f"set LG_VIEWAS_BASE to score the rendered label for real.")
            else:
                red(sec, f"{surface}: {key} position renders {text!r}, expected {want_text!r}")
            if f"view={want_value}" in href:
                ok(sec, f"{surface}: {key} position still links to ?view={want_value}")
            else:
                red(sec, f"{surface}: {key} position links to {href!r} — expected ?view={want_value}. "
                         f"The LABEL moved in #195; the value must not.")

        cur = [re.sub(r"<[^>]+>", "", t).strip()
               for a, t in els if 'aria-current="true"' in a]
        if cur == ["Edit"]:
            ok(sec, f"{surface}: ?view=me marks exactly the Edit position as current")
        elif cur == ["Me"] and not SERVES_THIS_TREE:
            dead(sec, f"{surface}: the serve marks 'Me' as current — the same DEPLOY GAP as "
                      f"above, not a second finding.")
        else:
            red(sec, f"{surface}: ?view=me marks {cur!r} as current, expected ['Edit'] — the URL "
                     f"and the highlighted position disagree.")


def section_c_value_still_drives_edit_mode(ctx):
    """The behavioural half: ?view=me is still what turns edit mode ON, and
    ?view=member is still what turns it OFF. This is what a label change must
    not touch, and it is bracketed on BOTH sides so a build where edit chrome
    never renders cannot pass the ON leg by accident."""
    sec = "C behaviour"
    wp_uid, u_slug, p_slug, p_owner, mint = ctx
    sess = _session_for(wp_uid, mint)
    tok = _gate_token()
    if not sess or not tok:
        dead(sec, "could not mint a session — not a finding")
        return
    c = {"loothdev_auth": tok, sess["cookie"]: sess["value"]}
    op = _opener(c)

    try:
        _, on = _fetch(f"{BASE}/u/{u_slug}?view=me", c, op)
        _, off = _fetch(f"{BASE}/u/{u_slug}?view=member", c, op)
    except (urllib.error.URLError, OSError) as e:
        dead(sec, f"unreachable ({e}) — not a finding")
        return

    if _is_owner_shell(on):
        ok(sec, "?view=me still renders the owner's edit chrome (.lg-shell--owner)")
    else:
        red(sec, "?view=me no longer renders edit chrome. #195 renamed the LABEL; if the value "
                 "moved with it, this is where members lose their editor.")
    if not _is_owner_shell(off):
        ok(sec, "?view=member still renders WITHOUT edit chrome — the two views really differ")
    else:
        red(sec, "?view=member renders edit chrome too, so the ON leg above proves nothing about "
                 "?view=me — every view would pass it.")
    # Both responses must still carry the switcher AS MARKUP: it is gated on
    # $isOwner, a weaker condition than $editing, and that is what lets a member
    # come back from a preview. Checked through _seg_from_html so the seg's own
    # stylesheet rule cannot answer for it.
    for name, html in (("?view=me", on), ("?view=member", off)):
        if _seg_from_html(html):
            ok(sec, f"{name} still carries the View-as switcher as markup (the way back)")
        else:
            red(sec, f"{name} carries no View-as switcher — a member who previews cannot return.")


def report():
    for line in OK:
        print(f"  PASS {line}")
    for line in DEAD:
        print(f"  ---- {line}")
    for line in RED:
        print(f"  FAIL {line}")
    for line in NOTE:
        print(f"  note {line}")


def main():
    print(f"=== gate 97: View-as label (#195) — base {BASE} ===")
    note("the /profile/edit switcher is source-only: UNREACHABLE on dev2 AND live "
         "(edit.php 302s any slugged member; ids 1/1702/1703 are unclaimed). "
         "Keeper's ruling — change the string, build no render proof.")
    if BASE == "https://dev2.loothgroup.com":
        note("BASE is the bare serve, which serves MAIN. /u/ and /p/ are symlinked out of "
             "the serving checkout, so §B/§C measured main. A lane must run "
             "tools/preview/lane-preview.sh up <lane> and set LG_VIEWAS_BASE.")

    for sec in (section_a_source, section_a2_no_stragglers):
        try:
            sec()
        except Exception as e:
            DEAD.append(f"[{sec.__name__}] crashed: {type(e).__name__}: {e}")

    ctx, why = None, None
    try:
        ctx, why = _mint_session()
    except Exception as e:
        why = f"{type(e).__name__}: {e}"
    if ctx is None:
        dead("B/C", f"no live surface to measure ({why}) — source half still scored above")
    else:
        for sec in (section_b_rendered, section_c_value_still_drives_edit_mode):
            try:
                sec(ctx)
            except Exception as e:
                DEAD.append(f"[{sec.__name__}] crashed: {type(e).__name__}: {e}")

    report()
    if RED:
        print(f"viewas-label-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD and not OK:
        print(f"viewas-label-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if DEAD:
        # ⚠️ SAY WHICH HALF WENT UNSCORED. A gate that prints "GREEN" while four
        # of its legs quietly could not run is the shape that lets a deploy gap
        # pass for a verified serve. Exit stays 0 — a stale checkout must never
        # block every lane (trap-gate-exit-code-3-blocks-every-lane) — but the
        # summary names it, so nobody reads this as "the serve was checked".
        gap = [d for d in DEAD if "DEPLOY GAP" in d]
        if gap:
            print(f"viewas-label-gate: GREEN ON SOURCE — the RENDERED half was NOT scored: the "
                  f"serve at {BASE} is behind this tree. §A verified the templates; §B's label "
                  f"legs did not run. Pull the serving checkout, or point LG_VIEWAS_BASE at a "
                  f"lane preview, then re-run before calling this surface verified.")
        print(f"viewas-label-gate: GREEN — {len(OK)} assertions ({len(DEAD)} could not run)")
        return 0
    print(f"viewas-label-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
