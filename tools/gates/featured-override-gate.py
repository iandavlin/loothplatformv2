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
     states, and for both fallback shapes Ian is choosing between. This is the
     one Ian actually reported.
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


    # B3 — THE OTHER HALF OF THE OVERRIDE: the guard must STILL refuse an
    # unpinnable consented pick. Ian overruled the criteria for members HE
    # places, not for the self-serve pool, and gate 39 §F3 keeps the dash's
    # prediction tied to this same guard — if it stops applying, the dash starts
    # promising bands the front page never draws.
    #
    # ⚠️ ADDED AFTER RED-FIRST MISSED IT. Deleting the guard outright left every
    # section green, because §B2's fixture is not opted in and is refused a row
    # by the WHERE clause before the guard is ever reached. A control that cannot
    # reach the code it controls is not a control.
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
        elif band(html3)["name"] == fx3[1]:
            RED.append(f"[B3] {fx3[1]!r} is a CONSENTED pick whose card resolves no role, and the "
                       f"front page drew them anyway — the card-ready guard has stopped applying "
                       f"to the self-serve pool, which Ian did not overrule, and the dash's "
                       f"card_renderable prediction (gate 39 §F3) is now lying to the admin")
        else:
            OK.append("[B3] the card-ready guard still refuses an unrenderable CONSENTED pick — "
                      "the override reaches pinned picks only")


# ── C. THE CONSENT FENCE, ALSO AS A PAIR ─────────────────────────────────────
def section_c_pin_never_republishes():
    """Consent-A (#107): "the tick is consent". A pinned member has not ticked,
    so their members-only one-liner may never appear on the public card — under
    either state of the consent flag, and even with consent_ack set in the
    config, because an admin cannot acknowledge a consent that was never given.

    PAIRED with the consented case under the same flag. A fence that refuses
    everybody would pass the first half while proving nothing."""
    pin_fx = FX.get("glance_pin")
    con_fx = FX.get("glance_consented")

    if not pin_fx:
        DEAD.append("[C1] no never-asked member on this box has a members-only one-liner — the "
                    "fixture is absent, so this is not run rather than passing vacuously")
    else:
        uuid, name, glance = pin_fx[0], pin_fx[1], pin_fx[2]
        for flag in ("0", "1"):
            # consent_ack deliberately TRUE: if the pin honoured it, that
            # would be a consent nobody ever gave.
            html, err = render(fm(uuid, True, ack=True),
                               {"LG_FEATURED_MEMBERS": "1", "LG_FEATURED_CONSENT": flag})
            if not liveness(html):
                DEAD.append(f"[C1] the front page did not render (consent={flag}): {err or 'no page'}")
                continue
            if glance and glance in html:
                RED.append(f"[C1] with the consent flag {flag}, PINNING {name!r} republished their "
                           f"members-only one-liner on the public front page — they never ticked "
                           f"the box, so nothing consented to that")
            else:
                OK.append(f"[C1] consent flag {flag}: a pinned member's members-only one-liner is "
                          f"withheld, even with consent_ack set")

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
            OK.append(f"[C2] liveness: the SAME rule still republishes for a CONSENTED member "
                      f"under the consent flag — so C1's refusal is the pin, not a dead path")
        else:
            RED.append(f"[C2] a consented member's one-liner is NOT republished with the consent "
                       f"flag on — either #107's rule has regressed, or C1 above is passing "
                       f"because nothing republishes at all and proves nothing")


# ── D. THE THREE FLAG READERS AGREE ──────────────────────────────────────────
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

    # F3 — the Private refusal, EXECUTED. Keeper's ruling of 2026-08-22: a pinned
    # pick does not bypass a member's own profile_visibility. Run the real
    # endpoint against the box and check a private member comes back listed and
    # marked ineligible — listed, because a name that silently is not there is a
    # question rather than an answer.
    # ⚠️ BY UUID, NOT BY NAME. This box has TWO members displaying "test", one
    # public and one private, and matching on the name picked the public one —
    # so the gate reported the private-profile refusal as broken when it was
    # working. A fixture that can match the wrong row is not a fixture.
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
                    "$_GET['q'] = getenv('Q');\nrequire %r;\n" % POOL_PHP)
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
                               f"list entirely — a name that silently is not there reads as a bug, "
                               f"which is why the ruling asks for a legible refusal instead")
                elif match[0].get("eligible"):
                    RED.append(f"[F3] {pname!r} has a non-public profile but is marked eligible to "
                               f"pin — that is the member's own switch and it outranks pinning")
                else:
                    OK.append(f"[F3] a non-public member is listed and marked ineligible, so the "
                              f"dash can say why rather than omitting them")
                # And the search must be usable at all.
                if payload.get("pool") is None:
                    RED.append("[F3] the pool payload lost its `pool` key while gaining candidates")

    # F4 — the words. Both kinds must be NAMED on the page; "featured" alone now
    # covers a member who agreed and one who was never asked.
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
                    section_c_pin_never_republishes, section_d_readers_agree,
                    section_e_tracked_default, section_f_dash_is_honest):
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
