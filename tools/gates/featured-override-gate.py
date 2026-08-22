#!/usr/bin/env python3
"""GATE 94 — #200: Ian's picks stay on the front page, and the band is never a hole.

Ian, 2026-08-22, verbatim on #200: "The changes made to featured member has
removed members from the front page. The override I wanted would still have them
on the frontpage even if they didn't meet the criteria."

WHAT THIS GATE IS FOR, and why gate 39 could not have caught any of it:

  gate 39 §C proves the flag is a no-op by GREPPING THE SOURCE. It never
  rendered the page with a member_uuid in config.json and the flag off — the
  exact pairing live was in — so it read "byte-identical OFF" as true while OFF
  actually removed the band entirely. Every section below RENDERS, or EXECUTES
  a lifted function, rather than pattern-matching a file.

SECTIONS

  A  THE EMPTY-POOL LAW. With no pick, the band is PRESENT — in all three flag
     states, and for both fallback shapes. This is the one Ian actually
     reported. A4/A5 were added when he ruled (2026-08-22, "B is fine for
     featured"): the page must draw the shape he PICKED, and that shape's one
     button must reach a real page — it shipped pointing at /u/, a 404.
  B  THE PIN, red-first as a PAIR. A member who fails the criteria renders when
     pinned and does NOT render when not — the second half is what proves the
     first is the pin doing the work and not the fallback wearing his name.
  C  THE CONSENT FENCE, also as a pair. A pinned member's members-only one-liner
     is never republished, WHILE a consented one's still is under the same flag.
     A fence that refuses everything is not a fence, it is a wall with no door.
  D  THE THREE FLAG READERS AGREE, executed at each caller's own directory depth
     with the real .local.php layer in place.
  E  THE TRACKED DEFAULT IS FALSE, read from the file rather than hardcoded, so
     a later ruled flip needs no gate edit.
  F  THE DASH names pinned vs consented, refuses a Private pin, and never writes
     consent on a member's behalf.

⚠️ IT RENDERS THE BRANCH, NOT THE SERVE. /srv/archive-poc is a symlink into the
serving checkout, so "I checked the front page on dev2" means "I checked main".
This runs THIS worktree's index.php under php-cli with LG_ARCHIVE_POC_CONFIG_JSON
pre-defined to a temp file — config.php uses a bare define() so the constant
already set here wins. Real code, real profile_app, one input swapped, nothing on
the serve touched.

⚠️ AND IT NEVER ASSUMES A MEMBER'S OPT-IN STATE. dev2 and live disagree about
the very member this issue is about (featured_opt_in is false on dev2, true on
live), so every fixture below is CHOSEN BY QUERY against the box it runs on. A
hardcoded uuid would pass here and be wrong about live.
"""
import json, os, re, subprocess, sys, tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
INDEX_PHP = os.path.join(REPO, "archive-poc", "web", "index.php")
DEFAULTS_PHP = os.path.join(REPO, "archive-poc", "web", "defaults.php")
FLAG_FILE = os.path.join(REPO, "platform", "config", "featured-members.php")
DASH_PHP = os.path.join(REPO, "lg-layout-v2", "src", "FeaturedMemberDash.php")
POOL_PHP = os.path.join(REPO, "profile-app", "api", "v0", "internal-featured-pool.php")

RED, DEAD, OK = [], [], []
TMP = tempfile.mkdtemp(prefix="gate94-")
os.chmod(TMP, 0o755)


def read(p):
    try:
        with open(p, encoding="utf-8") as f:
            return f.read()
    except OSError:
        return None


def psql(sql):
    """profile_app via passwordless peer auth, as the gate's own reader."""
    p = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "profile_app",
                        "-A", "-t", "-F", "|", "-c", sql],
                       capture_output=True, text=True, timeout=25)
    return p.returncode, p.stdout.strip(), p.stderr.strip()


def render(featured_member, env=None):
    """Render THIS BRANCH's front page with a substitute featured_member.

    Everything else in config.json is the box's real config, so the page around
    the band is the real page — a stripped-down config would let a band render
    in a document nothing else survived, which is not the thing under test.
    """
    live_cfg = "/home/ubuntu/projects/archive-poc/config.json"
    try:
        with open(live_cfg) as f:
            cfg = json.load(f)
    except Exception as e:
        return None, f"could not read {live_cfg}: {e}"
    if featured_member is None:
        cfg.pop("featured_member", None)
    else:
        cfg["featured_member"] = featured_member
    cpath = os.path.join(TMP, "config.json")
    rpath = os.path.join(TMP, "render.php")
    with open(cpath, "w") as f:
        json.dump(cfg, f)
    with open(rpath, "w") as f:
        f.write("<?php define('LG_ARCHIVE_POC_CONFIG_JSON', %r);\nrequire %r;\n"
                % (cpath, INDEX_PHP))
    os.chmod(cpath, 0o644)
    os.chmod(rpath, 0o644)
    cmd = ["sudo", "-n", "-u", "archive-poc", "env"]
    for k, v in (env or {}).items():
        cmd.append(f"{k}={v}")
    cmd += ["php", rpath]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=90)
    if p.returncode != 0 and not p.stdout:
        return None, (p.stderr or "render failed")[:300]
    return p.stdout, None


def band(html):
    """What the rendered page says about the featured band."""
    return {
        "present": html.count('row--featured-member'),
        "empty_shape": html.count('lg-fm--empty'),
        "blank_img": html.count('src=""'),
        "name": (re.search(r'lg-fm__name">([^<]*)', html) or [None, ""])[1],
        "role": (re.search(r'lg-fm__role">([^<]*)', html) or [None, ""])[1],
    }


def liveness(html):
    """A render that produced no page at all would make every absence assertion
    below vacuously true — the whole failure class of
    trap-locked-out-browser-goes-vacuously-green, one layer down."""
    return html is not None and 'id="rows"' in html and "view-discover" in html


# ── fixtures, CHOSEN BY QUERY. Never a hardcoded uuid: dev2 and live disagree
#    about the opt-in state of the very member this issue concerns. ───────────
def pick(sql, label):
    rc, out, err = psql(sql)
    if rc != 0:
        DEAD.append(f"[fixture] could not query profile_app for {label}: {err[:160]}")
        return None
    if not out:
        return None
    return out.splitlines()[0].split("|")


FX = {}


def load_fixtures():
    # A member who FAILS the criteria: public, has a photo, has NOT ticked, and
    # whose card would resolve no role at all. This is the shape of Ian's own
    # live pick, and the resolver refuses it today.
    FX["uncriteria"] = pick(
        """SELECT u.uuid, u.display_name FROM users u
            WHERE u.profile_visibility = 'public' AND u.avatar_url <> ''
              AND NOT u.featured_opt_in
              AND btrim(coalesce(u.at_a_glance,'')) = ''
              AND (coalesce(u.business_name,'') = ''
                   OR u.display_name LIKE '%' || u.business_name)
            ORDER BY u.id LIMIT 1""", "a member who fails the card criteria")
    # A member with a MEMBERS-ONLY one-liner who has not ticked — the consent
    # fence's subject.
    FX["glance_pin"] = pick(
        """SELECT u.uuid, u.display_name, btrim(u.at_a_glance) FROM users u
            WHERE u.profile_visibility = 'public' AND u.avatar_url <> ''
              AND NOT u.featured_opt_in
              AND btrim(coalesce(u.at_a_glance,'')) <> ''
              AND coalesce((SELECT ps.visibility FROM profile_sections ps
                             WHERE ps.user_id = u.id AND ps.key = 'header'), 'members') <> 'public'
            ORDER BY u.id LIMIT 1""", "a never-asked member with a members-only one-liner")
    # The same shape but OPTED IN — the fence's liveness half.
    FX["glance_consented"] = pick(
        """SELECT u.uuid, u.display_name, btrim(u.at_a_glance) FROM users u
            WHERE u.profile_visibility = 'public' AND u.avatar_url <> ''
              AND u.featured_opt_in
              AND btrim(coalesce(u.at_a_glance,'')) <> ''
              AND coalesce((SELECT ps.visibility FROM profile_sections ps
                             WHERE ps.user_id = u.id AND ps.key = 'header'), 'members') <> 'public'
            ORDER BY u.id LIMIT 1""", "an OPTED-IN member with a members-only one-liner")


def fm(uuid, pinned, ack=False):
    return {"enabled": True, "member_uuid": uuid, "pinned": pinned,
            "consent_ack": ack, "name": "fixture", "chosen_by": "gate94"}


# ── A. THE EMPTY-POOL LAW ────────────────────────────────────────────────────
def section_a_empty_pool():
    """Ian's law: the band must NEVER render as absent. Asserted in all three
    flag states and for BOTH fallback shapes, because a shape that is never
    drawn is a shape nobody notices has rotted."""
    states = [("flag absent", {}), ("flag OFF", {"LG_FEATURED_MEMBERS": "0"}),
              ("flag ON", {"LG_FEATURED_MEMBERS": "1"})]

    # A1: no pick at all.
    for label, env in states:
        html, err = render({"enabled": True}, env)
        if not liveness(html):
            DEAD.append(f"[A1] the front page did not render at all ({label}): {err or 'no page'}")
            continue
        b = band(html)
        if b["present"] == 0:
            RED.append(f"[A1] with nobody picked and the {label}, the featured band is ABSENT — "
                       f"that is the hole Ian reported, and the empty-pool law forbids it")
        elif not b["name"].strip():
            # ⚠️ THIS CLAUSE FOUND A REAL DEFECT ON ITS FIRST RUN, and it was
            # added only because the section prints what it measured. A
            # featured_member of {enabled:true} with no member_uuid — what the
            # dash's "Clear this pick" writes — is truthy, so it skipped the
            # resolver and drew a card with an empty <h2>. Counting that a band
            # EXISTS would have passed. "Never nothing" has to mean the band
            # says something.
            RED.append(f"[A1] the band renders with an EMPTY NAME ({label}) — an empty card is the "
                       f"same defect as a missing one, dressed up")
        elif b["blank_img"]:
            RED.append(f"[A1] the fallback band ({label}) ships {b['blank_img']} empty src=\"\" — "
                       f"a rendered-but-broken card is not better than a hole")
        else:
            OK.append(f"[A1] nobody picked, {label} — the band still renders ({b['name']!r})")

    # A2: a pick that CANNOT resolve (the live state — a uuid nobody can turn
    # into a card). This is distinct from A1: the config is not empty, it is
    # wrong, which is the case that actually emptied the front page.
    html, err = render(fm("00000000-0000-4000-8000-000000000000", False),
                       {"LG_FEATURED_MEMBERS": "1"})
    if not liveness(html):
        DEAD.append(f"[A2] the front page did not render at all: {err or 'no page'}")
    else:
        b = band(html)
        if b["present"] == 0:
            RED.append("[A2] a selected member who cannot be resolved leaves NO band — this is "
                       "exactly live's state on 2026-08-22 and the law says it must fall back")
        else:
            OK.append(f"[A2] an unresolvable pick falls back to a rendered band ({b['name']!r})")

    # A3: BOTH fallback shapes draw. Ian is choosing between them from a mock;
    # whichever he picks must already be known to render, and the one he does
    # not pick must not quietly rot.
    src = read(DEFAULTS_PHP)
    if src is None:
        DEAD.append("[A3] archive-poc/web/defaults.php is missing")
    elif "featured_member_fallback" not in src:
        RED.append("[A3] defaults.php has no featured_member_fallback — the empty-pool law has "
                   "no content to render and the band is back to being a hole")
    else:
        for kind in ("member", "invite"):
            p = subprocess.run(
                ["php", "-r",
                 "$d = include %r; $fb = $d['featured_member_fallback']; $fb['kind'] = %r;"
                 "require_once %r; $c = lg_fm_fallback_card($fb);"
                 "echo json_encode($c);" % (DEFAULTS_PHP, kind, os.path.join(TMP, "fnlift.php"))],
                capture_output=True, text=True, timeout=20)
            try:
                card = json.loads(p.stdout)
            except Exception:
                DEAD.append(f"[A3] could not execute lg_fm_fallback_card for kind={kind}: "
                            f"{(p.stderr or p.stdout)[:200]}")
                continue
            if not card:
                RED.append(f"[A3] the '{kind}' fallback shape produces NO card — Ian is choosing "
                           f"between two options and one of them renders nothing")
            elif not str(card.get("name", "")).strip():
                RED.append(f"[A3] the '{kind}' fallback card has no name — it would draw a blank line")
            elif card.get("kind") != kind:
                # ⚠️ ADDED AFTER RED-FIRST MISSED IT. Disabling the invite branch
                # made it fall through to 'member', which still returns a
                # perfectly drawable card — so "is there a card" went green while
                # the shape Ian may be about to choose had stopped existing.
                # Asking for a shape and not checking which shape you got is not
                # a test of the shape.
                RED.append(f"[A3] asking for the '{kind}' fallback returned a "
                           f"'{card.get('kind')}' card — the shape Ian picks would not be the "
                           f"shape that renders")
            else:
                OK.append(f"[A3] the '{kind}' fallback shape produces a drawable card "
                          f"({card['name']!r})")

    # ── A4: THE SHIPPED SHAPE IS THE ONE IAN RULED ──────────────────────────
    # Ian, 2026-08-22, having seen both drawn side by side: "B is fine for
    # featured. We haven't even announced it as a feature." B is 'invite'.
    #
    # ⚠️ WHY THIS IS ASSERTED ON THE RENDERED PAGE AND NOT ON defaults.php.
    # Reading `kind => 'invite'` out of the file proves somebody typed it. It
    # does not prove the page draws it — §A3's own history is the argument:
    # breaking the invite branch made it fall through to a perfectly drawable
    # 'member' card, and every source-level check stayed green. So this renders
    # the real no-pick page and asks what the visitor is actually handed.
    #
    # This is NOT the hardcoded-state mistake that
    # feedback-gate-reads-the-flag-not-a-hardcoded-state warns about. That rule
    # is about FLAGS, which have several legitimate states and must not be
    # pinned to one. `kind` is not a flag — it is a ruling, with one correct
    # value until Ian gives another. Pinning it is the whole point: a later
    # edit that quietly reverts the front page to a hand-placed person who was
    # featured in June is exactly what this must catch.
    html, err = render({"enabled": True}, {"LG_FEATURED_MEMBERS": "1"})
    if not liveness(html):
        DEAD.append(f"[A4] the front page did not render at all: {err or 'no page'}")
    else:
        b = band(html)
        if b["empty_shape"] == 0:
            RED.append(f"[A4] with nobody picked the band draws the HAND-PLACED shape "
                       f"({b['name']!r}), not the invite Ian ruled on 2026-08-22 — the front "
                       f"page is telling every visitor that someone featured in June is "
                       f"featured today")
        elif b["blank_img"]:
            RED.append("[A4] the invite card ships an empty src=\"\" — it draws a glyph, not a "
                       "face, and the template's !empty() guard is what makes that safe")
        else:
            OK.append(f"[A4] the no-pick band draws the ruled invite shape ({b['name']!r})")

    # ── A5: THE INVITE CARD'S BUTTON GOES SOMEWHERE ─────────────────────────
    # FOUND THE HONEST WAY, 2026-08-22, by clicking it. The mock drew this CTA
    # with href="#" and the first build shipped '/u/', which is a real branded
    # 404 (5,114 bytes) because u.php resolves a SLUG and there is no
    # self-profile alias. A card whose only control dead-ends is worse than the
    # hole it replaced: the hole was at least honest about having nothing.
    #
    # ⚠️ PAIRED WITH A KNOWN-BAD CONTROL, because an "it is reachable" probe
    # that cannot tell reachable from unreachable passes on everything
    # (feedback-absence-assertion-needs-liveness). '/u/' MUST come back bad
    # here; if it does not, this whole section measured nothing and says so.
    fb_href = ""
    try:
        out = subprocess.run(
            ["php", "-r", "$d = include %r; echo (string)($d['featured_member_fallback']"
                          "['invite']['cta_href'] ?? '');" % DEFAULTS_PHP],
            capture_output=True, text=True, timeout=30)
        fb_href = out.stdout.strip()
    except Exception as e:
        DEAD.append(f"[A5] could not read the invite cta_href: {e}")

    def _status(path):
        """Anon fetch over the loopback — the dev gate allows 127.0.0.1, so this
        needs no token, and a token-gated fetch would measure the gate page."""
        try:
            r = subprocess.run(
                ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}",
                 "-H", "Host: dev2.loothgroup.com",
                 "--resolve", "dev2.loothgroup.com:443:127.0.0.1", "-k",
                 "--max-time", "20", "https://dev2.loothgroup.com" + path],
                capture_output=True, text=True, timeout=30)
            return r.stdout.strip()
        except Exception:
            return ""

    control = _status("/u/")
    if control != "404":
        DEAD.append(f"[A5] the reachability probe is not working: the known-dead /u/ answered "
                    f"{control or 'nothing'} instead of 404, so a green here would prove nothing")
    elif not fb_href:
        RED.append("[A5] the invite card has NO cta_href — the button renders and goes nowhere")
    elif not fb_href.startswith("/"):
        RED.append(f"[A5] the invite cta_href {fb_href!r} is not a same-site path")
    else:
        st = _status(fb_href)
        if st in ("200", "301", "302"):
            OK.append(f"[A5] the invite card's button reaches {fb_href} ({st}) — probe proven "
                      f"by /u/ answering 404")
        else:
            RED.append(f"[A5] the invite card's button points at {fb_href}, which answers {st} — "
                       f"a dead button on the front page for every visitor")


# ── lifting a pure function out of index.php, gate 39 §G3's technique ────────
def extract_php_fn(src, name):
    """index.php is a whole rendered page, so a gate cannot require() it to test
    one rule. It CAN lift a named function out by brace-matching and execute it
    standalone — which is the difference between reading a rule and running it.
    Both functions this gate lifts are pure by construction for this reason."""
    m = re.search(r"^function\s+" + re.escape(name) + r"\s*\(", src, re.M)
    if not m:
        return None
    i = src.index("{", m.start())
    depth, j = 0, i
    while j < len(src):
        if src[j] == "{":
            depth += 1
        elif src[j] == "}":
            depth -= 1
            if depth == 0:
                return src[m.start():j + 1]
        j += 1
    return None


def write_lifted():
    """Both pure rules into one file the PHP one-liners above can require."""
    src = read(INDEX_PHP)
    if src is None:
        DEAD.append("[lift] archive-poc/web/index.php is missing")
        return False
    parts = []
    for fn in ("lg_fm_fallback_card", "lg_fm_card_role"):
        body = extract_php_fn(src, fn)
        if body is None:
            RED.append(f"[lift] index.php no longer defines {fn}() — #200's rules are not where "
                       f"this gate (and gate 39 §G3, for the second one) can execute them")
            return False
        parts.append(body)
    path = os.path.join(TMP, "fnlift.php")
    with open(path, "w") as f:
        f.write("<?php\n" + "\n\n".join(parts) + "\n")
    os.chmod(path, 0o644)
    p = subprocess.run(["php", "-l", path], capture_output=True, text=True)
    if p.returncode != 0:
        DEAD.append(f"[lift] the lifted functions do not parse standalone — they may have grown a "
                    f"dependency on the page around them: {p.stdout[:200]}")
        return False
    OK.append("[lift] both #200 rules lift out of index.php and parse standalone — still pure")
    return True


# ── B. THE PIN, AS A RED-FIRST PAIR ──────────────────────────────────────────
def section_b_pin_overrides_criteria():
    """Ian's ruling, executed. A member who fails the criteria must render when
    pinned — AND must not render when not pinned, because without that second
    half a fallback card wearing the fixture's name would pass the first."""
    fx = FX.get("uncriteria")
    if not fx:
        DEAD.append("[B] no member on this box fails the card criteria while being public with a "
                    "photo — the fixture this section needs does not exist here, so it is not run "
                    "rather than quietly passing")
        return
    uuid, name = fx[0], fx[1]

    html, err = render(fm(uuid, True), {"LG_FEATURED_MEMBERS": "1"})
    if not liveness(html):
        DEAD.append(f"[B1] the front page did not render: {err or 'no page'}")
        return
    pinned_band = band(html)

    html2, err2 = render(fm(uuid, False), {"LG_FEATURED_MEMBERS": "1"})
    if not liveness(html2):
        DEAD.append(f"[B2] the front page did not render: {err2 or 'no page'}")
        return
    plain_band = band(html2)

    if pinned_band["name"] != name:
        RED.append(f"[B1] {name!r} fails the criteria and was PINNED, but the front page shows "
                   f"{pinned_band['name']!r} instead — Ian's override does not reach the resolver")
    elif pinned_band["blank_img"]:
        RED.append(f"[B1] the pinned card ships an empty src=\"\" — the template guard that makes "
                   f"the pin safe is not doing its job")
    else:
        OK.append(f"[B1] a member who fails the criteria renders when pinned ({name!r}, "
                  f"role={pinned_band['role']!r})")

    # THE CONTROL. If this also showed the member, B1 would be telling us nothing.
    if plain_band["name"] == name:
        RED.append(f"[B2] {name!r} renders even WITHOUT the pin — so B1 is not measuring the "
                   f"override at all, and the criteria this issue is about are not being applied")
    else:
        OK.append(f"[B2] control: the same member does NOT render unpinned (page shows "
                  f"{plain_band['name']!r}) — so B1's pass is the pin doing the work")


    # B3 — RESTATED 2026-08-22 (200-latest-pick), AND ITS SENSE IS NOW INVERTED.
    #
    # It used to assert that the card-ready guard STILL refused an unrenderable
    # CONSENTED pick — "Ian overruled the criteria for members he places, not
    # for the self-serve pool". He has now overruled it for the pool too: "Can
    # we just make it so when I select a user they show up on the front page
    # again first." So the old assertion defended exactly the behaviour he
    # reported as the bug, and a gate kept green by defending a struck-out
    # behaviour is worse than no gate.
    #
    # RESTATED RATHER THAN DELETED, and pointed at the property that replaced
    # it: an opted-in member whose card resolves NO ROLE must now RENDER when
    # selected from the pool. That is the same fixture and the same code path,
    # asserting the opposite verdict — which is what keeps the section honest
    # rather than merely quiet.
    #
    # ⚠️ THE ORIGINAL NOTE STILL MATTERS AND IS KEPT: §B2's fixture is NOT
    # opted in, so the WHERE clause refuses it a row long before the guard is
    # reached. That is why §B2 stayed green through this change and why it is
    # still a valid control — it measures the SELECT, not the guard. This
    # section is the only one that reaches the guard at all.
    fx3 = pick(
        """SELECT u.uuid, u.display_name FROM users u
            WHERE u.profile_visibility = 'public' AND u.featured_opt_in
              AND coalesce((SELECT ps.visibility FROM profile_sections ps
                             WHERE ps.user_id = u.id AND ps.key = 'header'), 'members') <> 'public'
              AND (coalesce(u.business_name,'') = ''
                   OR u.display_name LIKE '%' || u.business_name)
            ORDER BY u.id LIMIT 1""", "an opted-in member whose card cannot resolve a role")
    if not fx3:
        DEAD.append("[B3] no opted-in member on this box resolves an empty role, so the "
                    "consented-path guard cannot be exercised here")
    else:
        html3, err3 = render(fm(fx3[0], False),
                             {"LG_FEATURED_MEMBERS": "1", "LG_FEATURED_CONSENT": "0"})
        if not liveness(html3):
            DEAD.append(f"[B3] the front page did not render: {err3 or 'no page'}")
        elif band(html3)["name"] != fx3[1]:
            RED.append(f"[B3] {fx3[1]!r} was selected from the POOL and the front page drew "
                       f"{band(html3)['name']!r} instead — an admin's selection is being thrown "
                       f"away, which is the whole of what Ian reported: \"when I select a user "
                       f"they show up on the front page again first\"")
        else:
            OK.append(f"[B3] an opted-in pool pick whose card resolves NO ROLE still renders "
                      f"({fx3[1]!r}) — nothing refuses a pick")


    # B4 — THE RULING'S HEADLINE, RENDERED. Ian, 2026-08-22: "If I select a user
    # for featured member I want them shown. Regardless of the status of their
    # profile. … I don't want to dissapear the band. Same band."
    #
    # The band must draw the MEMBER, not the fallback — a fallback here would be
    # the disappearance he is describing, wearing a different card.
    fx4 = pick("SELECT uuid, display_name FROM users WHERE profile_visibility <> 'public' "
               "ORDER BY id LIMIT 1", "a non-public member")
    if not fx4:
        DEAD.append("[B4] no non-public member exists on this box, so the ruling's headline case "
                    "cannot be exercised here")
    else:
        html4, err4 = render(fm(fx4[0], True), {"LG_FEATURED_MEMBERS": "1"})
        if not liveness(html4):
            DEAD.append(f"[B4] the front page did not render: {err4 or 'no page'}")
        else:
            b4 = band(html4)
            if b4["present"] == 0:
                RED.append(f"[B4] pinning the non-public member {fx4[1]!r} left NO band at all — "
                           f"the band disappeared, which is the thing the ruling names")
            elif b4["name"] != fx4[1]:
                RED.append(f"[B4] pinning {fx4[1]!r} drew {b4['name']!r} instead — a private "
                           f"profile is still being refused somewhere, and the fallback is hiding "
                           f"it. Ian's ruling: \"Regardless of the status of their profile.\"")
            else:
                OK.append(f"[B4] a pinned member with a non-public profile renders on the front "
                          f"page ({fx4[1]!r}) — the pin is absolute, per the ruling")


# ── C. WHERE A MEMBERS-ONLY ONE-LINER MAY REACH THE PUBLIC CARD ──────────────
def section_c_pin_never_republishes():
    """⚠️ RENAMED BY RULING, NOT WEAKENED BY CONVENIENCE. Ian, 2026-08-22:
    "If I select a user for featured member I want them shown. Regardless of the
    status of their profile. Please strip the saftey feature." and "if pinned,
    show what the band shows for anyone else."

    An earlier cut of this section asserted that a PINNED member's members-only
    one-liner is never republished. That fence is stripped by the ruling above,
    so asserting it now would be asserting a behaviour Ian removed.

    WHAT SURVIVES IS THE INVARIANT UNDERNEATH IT, which the ruling does not
    touch: a members-only one-liner reaches the public card ONLY through a route
    #107 names — the consent flag being ON, plus either an informed tick or an
    admin's recorded acknowledgement. Pinning goes through the SECOND of those
    doors on purpose ("until they re-confirm OR Ian features them knowingly"),
    which is #107 being exercised rather than bypassed.

    So C1 now asserts the state both boxes are actually in — flag OFF, nothing
    republished for anybody, pinned or not — and C2 keeps its liveness half, so
    "nothing is republished" can never pass because republication is broken."""
    pin_fx = FX.get("glance_pin")
    con_fx = FX.get("glance_consented")

    if not pin_fx:
        DEAD.append("[C1] no never-asked member on this box has a members-only one-liner — the "
                    "fixture is absent, so this is not run rather than passing vacuously")
    else:
        uuid, name, glance = pin_fx[0], pin_fx[1], pin_fx[2]
        # THE SHIPPED STATE, and the one that matters today: the consent flag is
        # OFF on dev2 and live, so no members-only one-liner reaches the public
        # card for anybody — pinned, consented, acked or not. consent_ack is set
        # deliberately, because with the flag off it must not be a route on its
        # own; that is the pairing gate 39 §G1 exists for, seen from the outside.
        html, err = render(fm(uuid, True, ack=True),
                           {"LG_FEATURED_MEMBERS": "1", "LG_FEATURED_CONSENT": "0"})
        if not liveness(html):
            DEAD.append(f"[C1] the front page did not render: {err or 'no page'}")
        elif glance and glance in html:
            RED.append(f"[C1] with the consent flag OFF, {name!r}'s members-only one-liner reached "
                       f"the public front page anyway — the flag is not the switch it claims to "
                       f"be, and consent_ack has become a route of its own")
        else:
            OK.append("[C1] consent flag OFF — no members-only one-liner is republished for a "
                      "pinned pick, even with consent_ack recorded")
        # AND WITH THE FLAG ON, the recorded acknowledgement IS a route, by
        # ruling. Asserted so that turning the flag on later cannot quietly do
        # something nobody predicted here.
        html, err = render(fm(uuid, True, ack=True),
                           {"LG_FEATURED_MEMBERS": "1", "LG_FEATURED_CONSENT": "1"})
        if not liveness(html):
            DEAD.append(f"[C1b] the front page did not render: {err or 'no page'}")
        elif glance and glance in html:
            OK.append("[C1b] consent flag ON — a pinned pick republishes through the "
                      "acknowledgement door #107 names, which is what Ian's ruling asks for")
        else:
            RED.append(f"[C1b] with the consent flag ON and consent_ack recorded, {name!r}'s "
                       f"one-liner is still withheld — #107's \"OR Ian features them knowingly\" "
                       f"clause has stopped working, so the flag would do nothing for a pin")

    if not con_fx:
        DEAD.append("[C2] no OPTED-IN member on this box has a members-only one-liner, so the "
                    "liveness half of the fence cannot run — C1 alone does not prove a fence")
    else:
        uuid, name, glance = con_fx[0], con_fx[1], con_fx[2]
        html, err = render(fm(uuid, False, ack=True),
                           {"LG_FEATURED_MEMBERS": "1", "LG_FEATURED_CONSENT": "1"})
        if not liveness(html):
            DEAD.append(f"[C2] the front page did not render: {err or 'no page'}")
        elif glance and glance in html:
            OK.append("[C2] liveness: the rule really does republish for a CONSENTED member "
                      "under the consent flag — so C1's silence with the flag OFF is the flag "
                      "doing its job, not republication being broken everywhere")
        else:
            RED.append(f"[C2] a consented member's one-liner is NOT republished with the consent "
                       f"flag on — either #107's rule has regressed, or C1 above is passing "
                       f"because nothing republishes at all and proves nothing")


# ── D. THE THREE FLAG READERS AGREE ──────────────────────────────────────────
def section_g_latest_action_wins():
    """§G — THE LATEST ADMIN ACTION WINS, IN BOTH DIRECTIONS (200-latest-pick).

    Ian: "when I select a user they show up on the front page again first."
    Half of that is §B3 (a selection is never discarded). This is the other
    half: when TWO selections exist over time, the most recent one is what the
    front page draws — clicking Feature in the pool displaces a standing pin,
    and pinning displaces a standing pool pick. One active selection, ever.

    ⚠️ THIS GATES BEHAVIOUR THAT ALREADY HELD; IT DID NOT HAVE TO BE BUILT.
    Measured before any code was written: both dash handlers already write
    member_uuid AND `pinned` explicitly on every save, which is the discipline
    #200 introduced for exactly this reason. Saying so plainly matters — the
    brief this lane was given assumed a leftover PIN was outranking a newer pool
    pick, and it was not: the card everyone was looking at was the hand-placed
    FALLBACK, which outranks nothing and merely appears whenever the real pick
    resolves to nothing. The precedence was never broken; the resolver was
    throwing the pick away. Gated anyway, because "it happens to be true today"
    and "it cannot silently stop being true" are different states.

    ⚠️ AND IT MUST SIMULATE THE REAL MERGE, NOT A REPLACEMENT. _config.php does
    `$clean + $existing['featured_member']`, and PHP's `+` keeps the LEFT
    operand's keys and fills the rest from the right — so a key a handler omits
    PERSISTS from the previous pick. Overwriting the config wholesale here would
    test a write path that does not exist and would go green on the very bug
    this section is for: a stale `pinned: true` surviving a Feature click.
    """
    def merge(clean, existing):
        m = dict(clean)
        for k, v in existing.items():
            m.setdefault(k, v)
        return m

    # G0 — THE SIMULATION ABOVE MUST MATCH THE REAL MERGE, or every leg below is
    # measuring a write path that does not exist. _config.php does
    # `$clean + $existing['featured_member']`: PHP's `+` keeps the LEFT
    # operand's keys, so the INCOMING payload wins and anything it omits falls
    # through to the previous pick. Reversed — `$existing + $clean` — the
    # STANDING pick would win every key and Ian's newest click would change
    # nothing, which is exactly the complaint this lane was opened for. That is
    # a one-character edit in another file, and nothing else in this gate reads
    # it, so it is asserted here in the section that depends on it.
    cfg_src = read(os.path.join(REPO, "archive-poc", "api", "v0", "_config.php"))
    if cfg_src is None:
        DEAD.append("[G0] _config.php is missing — the merge direction the legs below simulate "
                    "cannot be checked")
    elif re.search(r"\$clean\s*=\s*\$clean\s*\+\s*\(is_array\(\$existing\['featured_member'\]", cfg_src):
        OK.append("[G0] _config.php merges featured_member as `$clean + $existing` — the incoming "
                  "selection wins, which is what the legs below simulate")
    elif re.search(r"\$clean\s*=\s*\(?is_array\(\$existing\['featured_member'\].*\+\s*\$clean", cfg_src, re.S):
        RED.append("[G0] _config.php merges featured_member the WRONG WAY ROUND — the standing "
                   "pick's keys would win over the incoming one, so Ian's newest click would "
                   "change nothing on the front page")
    else:
        RED.append("[G0] could not find the featured_member merge in _config.php in either "
                   "direction — the precedence the legs below assert rests on it, and an "
                   "unrecognised merge is not a verified one")

    # handler payloads, keyed to the real ones in FeaturedMemberDash.
    def feature_payload(uuid, name):      # handle_feature()
        return {"enabled": True, "member_uuid": uuid, "name": name, "role": "",
                "consent_ack": False, "pinned": False, "chosen_by": "gate94"}

    def pin_payload(uuid, name):          # handle_pin()
        return {"enabled": True, "member_uuid": uuid, "name": name, "role": "",
                "consent_ack": True, "pinned": True, "chosen_by": "gate94"}

    # Two DISTINCT members that both render, so "which one is on the page" is a
    # real question. Chosen by query — dev2 and live disagree about opt-in, and
    # a hardcoded uuid would pass here and be wrong about the other box.
    rc, out, err = psql(
        """SELECT uuid, display_name FROM users
            WHERE profile_visibility = 'public' AND featured_opt_in
              AND coalesce(avatar_url,'') <> ''
            ORDER BY id LIMIT 2""")
    rows = [l.split("|") for l in out.splitlines() if l.strip()]
    if rc != 0 or len(rows) < 2:
        DEAD.append("[G] this box does not have two distinct renderable opted-in members, so "
                    "'which of two picks wins' cannot be asked here — not run rather than "
                    "quietly passed")
        return
    (uuid_a, name_a), (uuid_b, name_b) = (rows[0][0], rows[0][1]), (rows[1][0], rows[1][1])
    env = {"LG_FEATURED_MEMBERS": "1"}

    # G1 — a standing PIN, then Feature from the pool. The pool pick must win.
    standing_pin = merge(pin_payload(uuid_a, name_a), {})
    html, err = render(standing_pin, env)
    if not liveness(html):
        DEAD.append(f"[G1] the front page did not render: {err or 'no page'}")
        return
    if band(html)["name"] != name_a:
        DEAD.append(f"[G1] the standing pin does not even render ({band(html)['name']!r}) — the "
                    f"precedence question cannot be asked from here")
        return

    after_feature = merge(feature_payload(uuid_b, name_b), standing_pin)
    html, err = render(after_feature, env)
    if not liveness(html):
        DEAD.append(f"[G1] the front page did not render after the Feature click: {err}")
    elif after_feature.get("pinned") is not False:
        RED.append(f"[G1] after a Feature click the merged config still carries "
                   f"pinned={after_feature.get('pinned')!r} — an omitted key persists through "
                   f"PHP's `+`, so Feature must write pinned=false EXPLICITLY")
    elif band(html)["name"] != name_b:
        RED.append(f"[G1] a standing pin on {name_a!r} outranks a NEWER pool pick of {name_b!r} — "
                   f"the front page still draws {band(html)['name']!r}, so Ian's most recent "
                   f"click is not what he sees")
    else:
        OK.append(f"[G1] Feature displaces a standing pin — the pool pick {name_b!r} wins")

    # G2 — the other direction: a standing POOL pick, then a Pin.
    standing_pool = merge(feature_payload(uuid_b, name_b), {})
    after_pin = merge(pin_payload(uuid_a, name_a), standing_pool)
    html, err = render(after_pin, env)
    if not liveness(html):
        DEAD.append(f"[G2] the front page did not render after the Pin click: {err}")
    elif after_pin.get("pinned") is not True:
        RED.append("[G2] after a Pin click the merged config does not carry pinned=true")
    elif band(html)["name"] != name_a:
        RED.append(f"[G2] a standing pool pick of {name_b!r} outranks a NEWER pin of {name_a!r} — "
                   f"the front page still draws {band(html)['name']!r}")
    else:
        OK.append(f"[G2] a pin displaces a standing pool pick — the pin {name_a!r} wins")

    # G3 — ONE ACTIVE SELECTION. Whatever the sequence, exactly one band, and it
    # names the last member chosen. A page with two bands would satisfy both
    # legs above and still be wrong.
    b = band(html)
    if b["present"] != 1:
        RED.append(f"[G3] after two selections the page carries {b['present']} featured bands — "
                   f"'one active selection at a time' is not holding")
    else:
        OK.append("[G3] exactly one band after a pin-then-feature-then-pin sequence")

    # G4 — CLEARING still falls back, so the band never goes empty. Ian's
    # empty-pool law has to survive the precedence change.
    #
    # ⚠️ TESTED TWICE, AND THE SECOND ONE FOUND SOMETHING. The first version of
    # this leg invented its own clear payload — {member_uuid: "", pinned: false}
    # — and went RED: the page still drew the previous member's NAME. That was
    # not the dash's behaviour (handle_remove blanks name and role explicitly,
    # so the real Clear was always safe) but it was a real property of the page:
    # `$clean + $existing` means any writer that blanks the uuid and forgets the
    # name leaves a card describing someone no longer featured, and config.json
    # on both boxes still carries leftover bio/avatar/cta_* from an old
    # hand-placement. index.php now requires a RESOLVED card, so both shapes
    # fall back. Both are kept: the first proves the shipped button is right,
    # the second proves the page does not depend on that button being careful.
    clears = [
        # exactly what FeaturedMemberDash::handle_remove() posts
        ("the dash's own Clear",
         {"enabled": True, "member_uuid": "", "name": "", "role": "",
          "consent_ack": False, "pinned": False}),
        # a careless writer: uuid blanked, member fields left behind
        ("a partial write that blanks only the uuid",
         {"enabled": True, "member_uuid": "", "pinned": False}),
    ]
    for label, payload in clears:
        cleared = merge(payload, after_pin)
        html, err = render(cleared, env)
        if not liveness(html):
            DEAD.append(f"[G4] the front page did not render after {label}: {err}")
            continue
        b = band(html)
        if b["present"] == 0 or not b["name"].strip():
            RED.append(f"[G4] after {label} there is NO band (or a nameless one) — clearing must "
                       f"fall back to the standing card, never to a hole")
        elif b["name"] in (name_a, name_b):
            RED.append(f"[G4] after {label} the page still draws {b['name']!r} — a member who is "
                       f"no longer featured is still on the front page")
        else:
            OK.append(f"[G4] {label} falls back to the standing card ({b['name']!r}) — the band "
                      f"never goes empty and never keeps a stale name")


def section_d_readers_agree():
    """Three applications read this flag at three different directory depths and
    each carries its own copy of the resolution. The copies are deliberate (no
    shared autoload spans archive-poc, profile-app and wp), so the honest answer
    to duplication you cannot remove is to gate the agreement.

    ⚠️ EXTRACTED FROM EACH FILE, not re-typed here. A gate that asserts a
    hand-written transcription of the rule tests the transcription — that exact
    finding came out of gate 93's red-first run — so each caller's own three
    lines are lifted and executed at its own __DIR__."""
    callers = {
        "archive-poc/web/index.php": os.path.join(REPO, "archive-poc", "web"),
        "profile-app/web/u.php": os.path.join(REPO, "profile-app", "web"),
        "profile-app/api/v0/me-featured.php": os.path.join(REPO, "profile-app", "api", "v0"),
    }
    exprs = {}
    for rel, d in callers.items():
        src = read(os.path.join(REPO, rel))
        if src is None:
            DEAD.append(f"[D] {rel} is missing")
            continue
        tracked = re.search(r"@include\s+__DIR__\s*\.\s*'([^']*featured-members\.php)'", src)
        local = re.search(r"@include\s+__DIR__\s*\.\s*'([^']*featured-members\.local\.php)'", src)
        if not tracked:
            RED.append(f"[D] {rel} no longer includes the tracked flag file by a literal "
                       f"__DIR__ path — gate 39 §C4/§G4 resolve that literal for real, and so "
                       f"does this")
            continue
        if not local:
            RED.append(f"[D] {rel} has NO .local.php layer. This is the gap #200 was opened by: "
                       f"keeper handed Ian a live stopgap consisting of placing that exact file "
                       f"and it would have done nothing at all")
            continue
        exprs[rel] = (d, tracked.group(1), local.group(1))

    if len(exprs) != len(callers):
        return

    # tracked=true, local=false, then env in both directions. The local layer
    # must beat the tracked file and the env must beat the local one — that
    # order is what lets a gate force a state on a box configured the other way.
    scen = [("local only", None, "OFF"), ("env forces on", "1", "ON"), ("env forces off", "0", "OFF")]
    for label, envval, want in scen:
        answers = {}
        for rel, (d, tpath, lpath) in exprs.items():
            probe = os.path.join(d, ".gate94-reader-probe.php")
            with open(probe, "w") as f:
                f.write(
                    "<?php $cfg = @include __DIR__ . '%s';\n"
                    "$on = is_array($cfg) && !empty($cfg['enabled']);\n"
                    "$loc = @include __DIR__ . '%s';\n"
                    "if (is_array($loc) && array_key_exists('enabled', $loc)) $on = ($loc['enabled'] === true);\n"
                    "foreach ([getenv('LG_FEATURED_MEMBERS'), $_SERVER['LG_FEATURED_MEMBERS'] ?? false] as $o) {\n"
                    "  if ($o !== false && $o !== '') $on = ($o === '1' || $o === 'true');\n"
                    "}\n"
                    "echo $on ? 'ON' : 'OFF';\n" % (tpath, lpath))
            # A .local.php saying the OPPOSITE of the tracked default, placed
            # for the length of this check only. Gitignored, and removed in the
            # finally below even if PHP dies.
            # ⚠️ NOT os.path.join. The literal lifted from the PHP begins with
            # a slash ("__DIR__ . '/../../platform/...'"), and join() treats a
            # leading slash as an absolute path and DISCARDS the directory —
            # which sent the first run of this gate at /platform/config/ on the
            # root filesystem. Concatenate the way PHP does, then normalise.
            lfile = os.path.normpath(d + lpath)
            placed = False
            try:
                tracked_on = "'enabled' => true" in (read(FLAG_FILE) or "")
                if not os.path.exists(lfile):
                    with open(lfile, "w") as f:
                        f.write("<?php\nreturn array('enabled' => %s);\n"
                                % ("false" if tracked_on else "true"))
                    placed = True
                e = dict(os.environ)
                e.pop("LG_FEATURED_MEMBERS", None)
                if envval is not None:
                    e["LG_FEATURED_MEMBERS"] = envval
                r = subprocess.run(["php", probe], capture_output=True, text=True, timeout=20, env=e)
                answers[rel] = r.stdout.strip()
            finally:
                if placed and os.path.exists(lfile):
                    os.unlink(lfile)
                if os.path.exists(probe):
                    os.unlink(probe)

        distinct = set(answers.values())
        if len(distinct) != 1:
            RED.append(f"[D] the three flag readers DISAGREE ({label}): {answers} — a member could "
                       f"see the tickbox on their profile while the front page ignores the feature, "
                       f"or the reverse")
        elif envval is None and distinct == {"ON"}:
            # tracked true + local false should read OFF; tracked false + local
            # true should read ON. Either way the local layer must have WON.
            tracked_on = "'enabled' => true" in (read(FLAG_FILE) or "")
            if tracked_on:
                RED.append("[D] all three readers ignore the .local.php layer — the per-box "
                           "override does not override")
            else:
                OK.append(f"[D] all three readers agree ({label}): {distinct.pop()}, the "
                          f".local.php layer winning over the tracked default")
        elif envval is not None and distinct != {want}:
            RED.append(f"[D] env should beat the .local.php layer ({label}: wanted {want}) but the "
                       f"readers said {distinct} — a gate cannot force a state on a configured box")
        else:
            OK.append(f"[D] all three readers agree ({label}): {distinct.pop()}")


# ── E. THE TRACKED DEFAULT ───────────────────────────────────────────────────
def section_e_tracked_default():
    """PER-STATE, reading the flag rather than hardcoding it — the same discipline
    gate 39 §C1 learned, so a later RULED flip needs no edit here. What is
    asserted is not "false" but "false, or true with the two live causes named
    beside it", because those are what made turning it on a production incident."""
    src = read(FLAG_FILE)
    if src is None:
        DEAD.append("[E] platform/config/featured-members.php is missing")
        return
    if re.search(r"'enabled'\s*=>\s*false", src):
        OK.append("[E] the tracked default is false — live cannot be re-armed by a routine pull")
        return
    if not re.search(r"'enabled'\s*=>\s*true", src):
        DEAD.append("[E] could not find an 'enabled' => ... line to read")
        return
    # ⚠️ THE MEASUREMENT COMES FIRST, and the prose check second. The 2026-08-21
    # deploy did not fail for want of a comment: it failed because
    # tools/cut/featured-member-grants.sql had never been APPLIED to the box the
    # flag was being turned on for. So an ON is checked against the database it
    # will actually run against, which is a fact no wording can fake.
    rc, out, err = psql("SELECT has_column_privilege('archive-poc','public.users',"
                        "'featured_opt_in_at','SELECT')::text")
    # ⚠️ ACCEPT BOTH SPELLINGS. `boolean::text` in Postgres is 'true'/'false',
    # while psql's own default boolean rendering is 't'/'f' — the first version
    # of this compared against 't' alone and reported dev2, which HAS the grant,
    # as missing it. A false RED here would block every lane the moment the flag
    # is ruled back on, so it is worth two words. Caught by disbelieving a
    # surprising red rather than by the suite, which was 14/14 at the time.
    ok_grant = out.strip().lower() in ("t", "true")
    if rc != 0:
        DEAD.append(f"[E] could not check the archive-poc grant on this box: {err[:160]}")
    elif not ok_grant:
        RED.append("[E] the tracked flag is ON and this box has NOT been granted "
                   "users.featured_opt_in_at for the archive-poc role. The resolver will raise "
                   "\"permission denied for table users\", the caller will swallow it, and the "
                   "band will vanish for every visitor — which is exactly what happened to live "
                   "on 2026-08-21. Fix: sudo -u postgres psql profile_app -f "
                   "tools/cut/featured-member-grants.sql")
    else:
        OK.append("[E] the flag is ON and this box really can read users.featured_opt_in_at")

    # ⚠️ 350 CHARS, NOT 1800. Red-first flipped this file to true and the gate
    # stayed green: the long docblock explaining WHY IT IS OFF names both the
    # ruling and the grants file, so a generous window let a casual flip inherit
    # the justification for the opposite decision. The window has to be tight
    # enough that the words sit against the line they justify.
    win = src[:src.find("'enabled' => true")][-350:]
    has_grant = "featured-member-grants" in win
    has_ruling = re.search(r"(?i)\bIan\b.{0,160}(ruled|ruling|decision|said|verbatim|flip)", win, re.S)
    if has_grant and has_ruling:
        OK.append("[E] the flag is ON by an attributed ruling, with the grant precondition named "
                  "against the line itself")
    else:
        RED.append("[E] the tracked flag is ON without naming BOTH the ruling that turned it on "
                   "and tools/cut/featured-member-grants.sql immediately beside the line. Turning "
                   "this on once already took the band off live for every visitor — an ON that "
                   "inherits the paragraph explaining why it used to be OFF is the same deploy "
                   "again with a better alibi")


# ── F. THE DASH TELLS THE TRUTH ──────────────────────────────────────────────
def section_f_dash_is_honest():
    """Three properties, and the first two are executed rather than grepped."""
    dash = read(DASH_PHP)
    pool = read(POOL_PHP)
    if dash is None or pool is None:
        DEAD.append("[F] FeaturedMemberDash.php or the pool endpoint is missing")
        return

    # F1 — THE RULE #200 MUST NOT BREAK. Gate 39 §D keeps an admin from ticking a
    # member's consent by impersonating them; pinning must not become the door
    # that rule closes. The pin handler must never write featured_opt_in.
    pin_fn = re.search(r"function handle_pin\(\).*?\n    \}", dash, re.S)
    if not pin_fn:
        RED.append("[F1] FeaturedMemberDash has no handle_pin() — the override has no door")
    elif re.search(r"featured_opt_in|me-featured", pin_fn.group(0)):
        RED.append("[F1] handle_pin() touches featured_opt_in — pinning must PLACE a member, never "
                   "consent on their behalf. That is Ian's ruling and gate 39 §D's whole subject")
    else:
        OK.append("[F1] handle_pin() never writes a member's consent — it places, it does not tick")

    # F2 — pinned is written on EVERY save, false included. The webhook merges
    # with `+`, so an omitted key persists and a stale true would relabel a
    # consented member as one an admin placed.
    for fn, want in (("handle_feature", "false"), ("handle_pin", "true"), ("handle_remove", "false")):
        m = re.search(r"function " + fn + r"\(\).*?\n    \}", dash, re.S)
        if not m:
            RED.append(f"[F2] FeaturedMemberDash has no {fn}()")
        elif not re.search(r"'pinned'\s*=>\s*" + want, m.group(0)):
            RED.append(f"[F2] {fn}() does not write 'pinned' => {want} explicitly. _config.php "
                       f"merges with `+`, so an omitted key PERSISTS — a stale value would make "
                       f"the dash and the front page disagree about whether a member consented")
        else:
            OK.append(f"[F2] {fn}() writes 'pinned' => {want} explicitly")

    # F3 — ⚠️ RENAMED BY RULING. Ian, 2026-08-22: "Please strip the saftey
    # feature. I want to know what it is in the dash." and, on the status
    # column, "more for a stat for winnowing selections in the dash I thought."
    #
    # This used to assert that a Private profile is REFUSED. It now asserts the
    # opposite shape — that a Private member is offered, LABELLED, and COUNTED —
    # because the fence was removed by ruling, and a gate that kept asserting it
    # would be defending a behaviour Ian struck out. The invariant that replaces
    # it is that the status is never LOST: it must reach the dash as a fact, and
    # as a filter he can narrow on, or "informs instead of blocking" degrades
    # into "does neither".
    #
    # ⚠️ BY UUID, NOT BY NAME. This box has TWO members displaying "test", one
    # public and one private, and matching on the name picked the public one.
    # A fixture that can match the wrong row is not a fixture.
    rc, out, err = psql("SELECT uuid, display_name FROM users "
                        "WHERE profile_visibility <> 'public' LIMIT 1")
    if rc != 0:
        DEAD.append(f"[F3] could not query for a non-public member: {err[:160]}")
    elif not out:
        DEAD.append("[F3] no non-public member exists on this box, so the one refusal a pin still "
                    "honours cannot be exercised here")
    else:
        puuid, pname = out.splitlines()[0].split("|")
        probe = os.path.join(TMP, "pool.php")
        with open(probe, "w") as f:
            f.write("<?php\n$_SERVER['REQUEST_METHOD'] = 'GET';\n"
                    "$_SERVER['HTTP_X_LG_INTERNAL_AUTH'] = trim((string) @file_get_contents('/etc/lg-internal-secret'));\n"
                    "$_GET['q'] = getenv('Q');\n"
                    "if (getenv('ST')) $_GET['status'] = getenv('ST');\nrequire %r;\n" % POOL_PHP)
        os.chmod(probe, 0o644)
        r = subprocess.run(["sudo", "-n", "-u", "profile-app", "env", f"Q={puuid}", "php", probe],
                           capture_output=True, text=True, timeout=40)
        try:
            payload = json.loads(r.stdout)
        except Exception:
            DEAD.append(f"[F3] the pool endpoint did not return JSON: {(r.stdout or r.stderr)[:200]}")
            payload = None
        if payload is not None:
            cands = payload.get("candidates")
            if cands is None:
                RED.append("[F3] the pool endpoint returned no `candidates` for a search — the "
                           "pin picker has nothing to show and every pin is unreachable")
            else:
                match = [c for c in cands if c.get("uuid") == puuid]
                if not match:
                    RED.append(f"[F3] the non-public member {pname!r} is absent from the candidate "
                               f"list entirely — Ian's ruling is that he picks anyone and the dash "
                               f"tells him what they are; a name that is simply not there tells him "
                               f"nothing and refuses him silently")
                elif match[0].get("status") != "private":
                    RED.append(f"[F3] {pname!r} has a non-public profile but the dash is told "
                               f"status={match[0].get('status')!r} — the status is the winnowing "
                               f"fact, and a wrong one is worse than none")
                else:
                    OK.append("[F3] a non-public member is offered for pinning and carries "
                              "status='private', so the dash can label it rather than refuse it")
                if payload.get("pool") is None:
                    RED.append("[F3] the pool payload lost its `pool` key while gaining candidates")
                # F3b — THE STATUS IS A FILTER, NOT JUST A LABEL (Ian's refinement).
                # Counts must cover the whole match set, and the buckets must be
                # mutually exclusive, or the number beside a filter is a guess.
                cc = payload.get("candidate_counts")
                if not isinstance(cc, dict):
                    RED.append("[F3b] the endpoint returns no candidate_counts — the dash's filter "
                               "would have nothing to count with, and its whole job is narrowing")
                elif cc.get("all") != sum(cc.get(k, 0) for k in ("consented", "never", "private")):
                    RED.append(f"[F3b] the status buckets do not sum to the total ({cc}) — a member "
                               f"is in two buckets or none, so the counts he winnows on are wrong")
                elif cc.get("private", 0) < 1:
                    RED.append(f"[F3b] a non-public member was found by query but the private "
                               f"bucket counts {cc.get('private')} — the filter cannot find the "
                               f"very row F3 just proved is there")
                else:
                    OK.append(f"[F3b] the status filter counts the whole match set and its buckets "
                              f"sum exactly ({cc['all']} = {cc['consented']}+{cc['never']}+{cc['private']})")
                # F3c — a filtered request returns ONLY that bucket.
                r2 = subprocess.run(["sudo", "-n", "-u", "profile-app", "env", f"Q={puuid}",
                                     "ST=private", "php", probe],
                                    capture_output=True, text=True, timeout=40)
                try:
                    only = json.loads(r2.stdout).get("candidates") or []
                except Exception:
                    only = None
                if only is None:
                    DEAD.append("[F3c] the filtered request did not return JSON")
                elif any(c.get("status") != "private" for c in only):
                    RED.append("[F3c] filtering to 'private' returned rows of other kinds — the "
                               "filter does not narrow, so its counts describe a different list")
                elif not only:
                    RED.append("[F3c] filtering to 'private' returned nothing, though F3 found a "
                               "private member — a filter that silently matches nothing reads as "
                               "'there is nobody'")
                else:
                    OK.append("[F3c] filtering to 'private' returns only private rows")

    # F4 — the words. Both kinds must be NAMED on the page; "featured" alone now
    # covers a member who agreed and one who was never asked.
    # ⚠️ AND THE REFUSAL LANGUAGE MUST BE GONE. Ian struck it out; a dash that
    # still says it is telling him something untrue about his own permissions.
    if "Cannot be pinned" in dash:
        RED.append("[F4] the dash still says \"Cannot be pinned\" — that refusal was stripped by "
                   "ruling on 2026-08-22 and the words must go with the behaviour, or the page "
                   "tells Ian he cannot do the thing he just did")
    else:
        OK.append("[F4] the cannot-be-pinned refusal language is gone, with the behaviour")
    # The profile link he asked for, in a new tab, with the opener closed off.
    if 'target="_blank"' not in dash or "noopener" not in dash:
        RED.append("[F4] the candidate rows have no new-tab profile link with rel=noopener — "
                   "Ian: \"I want a link to check out their profile. Open in new tab.\"")
    else:
        OK.append("[F4] every candidate row links to the member's profile in a new tab")

    for needle, what in (("pinned by an admin", "the live pick's banner"),
                         ("opted in", "the consented label")):
        if needle not in dash:
            RED.append(f"[F4] the dash never says {needle!r} — {what} does not distinguish a "
                       f"member who consented from one an admin placed")
    if "pinned by an admin" in dash and "opted in" in dash:
        OK.append("[F4] the dash names both kinds in words, not just by behaviour")


def report():
    for m in OK:   print(f"  ok   {m}")
    for m in RED:  print(f"  RED  {m}")
    for m in DEAD: print(f"  DEAD {m}")


def section_f5_dash_renders():
    """⚠️ THE ONLY SECTION THAT LOOKS AT THE DASH AS A PAGE, and it exists because
    the source-grep version of it went GREEN on a mutation that removed the
    filter. Grepping for "pinst" passed while the control was gone, because the
    string also lives in the chip loop the mutation did not touch — the
    "assertion matches a string that also lives elsewhere" failure this repo has
    now paid for five times.

    So this RENDERS the admin page: real WordPress, real user, and the BRANCH's
    class. lg-layout-v2 uses a LAZY PSR-4 autoloader, so requiring the branch
    file from `wp --require` (before WP boots) means the autoloader never fires
    for it and the serving checkout's copy — which is main — is not what runs.
    ReflectionClass reports the file that actually loaded, and that path is
    asserted.

    The pool payload is STUBBED through `pre_http_request`, deliberately: the
    dash's loopback URLs are routed by nginx into the serving checkout, so a live
    call would measure main's endpoint and report this dash as broken. The stub
    is a fixed synthetic payload, so the counts asserted here are arithmetic, not
    whatever the box happens to hold today."""
    boot = os.path.join(TMP, "dashboot.php")
    with open(boot, "w") as f:
        f.write("<?php require_once %r;\n" % DASH_PHP)
    payload = {
        "pool": [],
        "candidate_counts": {"all": 3, "consented": 1, "never": 1, "private": 1},
        "status": "all",
        "candidates": [
            {"uuid": "11111111-1111-4111-8111-111111111111", "slug": "consented-one",
             "display_name": "Consented One", "avatar_url": "/a.jpg", "location": "",
             "eligible": True, "opted_in": True, "status": "consented",
             "profile_url": "/u/consented-one", "has_photo": True,
             "public_role": "Bench Work", "glance_members_only": False},
            {"uuid": "22222222-2222-4222-8222-222222222222", "slug": "never-asked",
             "display_name": "Never Asked", "avatar_url": "/b.jpg", "location": "",
             "eligible": True, "opted_in": False, "status": "never",
             "profile_url": "/u/never-asked", "has_photo": True,
             "public_role": "", "glance_members_only": True},
            {"uuid": "33333333-3333-4333-8333-333333333333", "slug": "private-one",
             "display_name": "Private One", "avatar_url": "/c.jpg", "location": "",
             "eligible": False, "opted_in": False, "status": "private",
             "profile_url": "/u/private-one", "has_photo": True,
             "public_role": "", "glance_members_only": False},
        ],
    }
    render_php = os.path.join(TMP, "dashrender.php")
    with open(render_php, "w") as f:
        f.write("""<?php
use LG\\LayoutV2\\FeaturedMemberDash;
$r = new ReflectionClass(FeaturedMemberDash::class);
fwrite(STDERR, "LOADED:" . $r->getFileName() . "\\n");
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'featured-pool') !== false) {
        return ['headers'=>[], 'body'=>getenv('POOL_JSON'),
                'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[], 'filename'=>null];
    }
    if (strpos($url, '_featured-history') !== false) {
        return ['headers'=>[], 'body'=>'{"history":[]}',
                'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[], 'filename'=>null];
    }
    return $pre;
}, 10, 3);
$a = get_users(['role'=>'administrator','number'=>1]);
if (!$a) { fwrite(STDERR, "NOADMIN\\n"); exit(1); }
wp_set_current_user($a[0]->ID);
$_GET['page'] = 'lg-featured-member';
$_GET['pinq'] = 'x';
ob_start(); FeaturedMemberDash::render_page(); echo ob_get_clean();
""")
    for f_ in (boot, render_php):
        os.chmod(f_, 0o644)
    p = subprocess.run(["sudo", "-n", "-u", "looth-dev", "env",
                        "POOL_JSON=" + json.dumps(payload),
                        "wp", "eval-file", render_php, "--require=" + boot,
                        "--path=/var/www/dev", "--skip-themes"],
                       capture_output=True, text=True, timeout=180)
    html = p.stdout
    if "LOADED:" + DASH_PHP not in p.stderr:
        DEAD.append(f"[F5] could not render the BRANCH's dash — it loaded "
                    f"{[l for l in p.stderr.splitlines() if l.startswith('LOADED:')] or p.stderr[-200:]}. "
                    f"Rendering the serving checkout's copy would be measuring main.")
        return
    if "Featured Member" not in html:
        DEAD.append(f"[F5] the dash rendered nothing usable: {(p.stderr or html)[-250:]}")
        return

    # THE FILTER, as rendered: one link per bucket, each carrying its count.
    for key, label, n in (("all", "Everyone", 3), ("consented", "Consented", 1),
                          ("never", "Never asked", 1), ("private", "Private profile", 1)):
        if f"pinst={key}" not in html and f"pinst%3D{key}" not in html:
            RED.append(f"[F5] the rendered dash has no link filtering to '{key}' — Ian: \"more for "
                       f"a stat for winnowing selections in the dash\", and a label you cannot "
                       f"click does not winnow anything")
        elif not re.search(re.escape(label) + r"\s*<span[^>]*>\s*" + str(n), html):
            RED.append(f"[F5] the '{label}' filter renders without its count of {n} — the number "
                       f"beside a filter is the whole of its value when the list is 1,500 long")
        else:
            OK.append(f"[F5] rendered: the '{label}' filter is a link and carries its count ({n})")

    # THE PRIVATE ROW IS OFFERED, not refused — the ruling, seen on the page.
    if "Private One" not in html:
        RED.append("[F5] the rendered dash omits the private member entirely")
    elif "Cannot be pinned" in html:
        RED.append("[F5] the rendered dash still refuses a private profile in words")
    else:
        seg = html[html.index("Private One"):]
        seg = seg[:seg.find("</tr>") + 5] if "</tr>" in seg else seg[:1200]
        if "Pin to front page" not in seg:
            RED.append("[F5] the private member's row has no Pin button — Ian: \"Please strip the "
                       "saftey feature\", so the row must be actionable, not merely present")
        elif 'target="_blank"' not in seg:
            RED.append("[F5] the private member's row has no new-tab profile link")
        else:
            OK.append("[F5] rendered: the private member is offered a Pin button and a new-tab "
                      "profile link, labelled rather than refused")


def main():
    print("=== featured-override-gate (GATE 94): #200 ===")
    if not write_lifted():
        for m in OK:   print(f"  ok   {m}")
        for m in RED:  print(f"  RED  {m}")
        for m in DEAD: print(f"  DEAD {m}")
        print("featured-override-gate: could not lift the rules — nothing below could run")
        return 1 if RED else 2
    # ⚠️ PRINT WHAT WAS MEASURED EVEN WHEN A LEG DIES. compose-licence-gate.py
    # aborted at its browser leg and threw away fifteen findings it had already
    # recorded, which made two halves of one run look like they disagreed.
    # THIS GATE DID IT TOO on its first run — a path bug in §D raised out of
    # main() and discarded §A, §B and §C, which had all already passed. Hence
    # the try/finally rather than a comment saying sections should behave.
    load_fixtures()
    try:
        for sec in (section_a_empty_pool, section_b_pin_overrides_criteria,
                    section_c_pin_never_republishes, section_g_latest_action_wins,
                    section_d_readers_agree,
                    section_e_tracked_default, section_f_dash_is_honest,
                    section_f5_dash_renders):
            try:
                sec()
            except Exception as e:
                DEAD.append(f"[{sec.__name__}] crashed: {type(e).__name__}: {e}")
    finally:
        report()

    if DEAD and not RED:
        print(f"featured-override-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    if RED:
        print(f"featured-override-gate: RED — {len(RED)} finding(s)")
        return 1
    print(f"featured-override-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
