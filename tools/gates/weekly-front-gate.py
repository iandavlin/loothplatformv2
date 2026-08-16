#!/usr/bin/env python3
"""
weekly-front-gate — GATE 54/54 — the weekly email on the logged-out front page.

Backlog item 8 (Ian 2026-07-30: "surface the most-recent weekly email on the
FRONT PAGE for logged-out visitors"; ruled 2026-08-15 after the mock — "build it
and let me see it on dev2", Option A, member-only cards shown with their
padlock). KEEPER GATE NUMBER: 54, assigned by keeper 2026-08-15. The charter
said "next 52", which was already stale — profiles-alive holds 51,
notif-quickreply-v2 holds 52, dark-anon-sweep holds 53 — so this was re-asked
rather than minted. Do not renumber without asking.

STATIC BY CHOICE. It renders the partial in-process against payloads it
constructs, and reads the flag config off disk. No browser, no CDP, no network,
no database — so it cannot flake under load, cannot photograph injected app
chrome, and runs identically on a lane worktree and on main. The block's
CONTRAST is measured separately by tools/preview/weekly-front-shots.py, which
does need a browser; that is deliberately not folded in here, because a gate
that needs Chrome fails for reasons that have nothing to do with this feature.

WHAT IT ASSERTS, and why each one is here rather than being obvious:

 A. THE FLAG, IN ALL THREE STATES — absent / OFF / ON — read from the tracked
    config the way the code reads it. Asserting only the ON state produces a
    gate that reddens the moment the feature ships defaulted OFF, which is how
    "flag OFF must be a proven no-op" keeps getting downgraded to nothing.
    ABSENT is a state, not a typo: @include of a missing file returns false, and
    "the file is gone" must fail closed rather than fatal.

 B. OFF EMITS NOTHING. Not an empty container, not a hidden section — zero
    bytes. An OFF state that ships markup is one CSS rule away from being ON.

 C. A GATED ITEM RENDERS ITS PADLOCK. This is the assertion aimed at a defect
    that fails SILENTLY and that a looser check would miss. The `tier` taxonomy
    stores `looth-lite`/`looth-pro`; the CSS is `.rcard--gated-lite`. If the
    slug is passed through unmapped the class becomes `rcard--gated-looth-lite`,
    which matches no rule in archive.css — so a member-only card renders with NO
    padlock and reads as free content. Nothing throws. Asserting "a badge is
    present" would pass on the broken output, so this asserts the gate ELEMENT
    and the exact tier token.

 D. NO GATED PROSE IN THE BYTES. The leak rule, asserted on the rendered output
    rather than on intent. LG_WD_Front_Feed strips excerpts from gated items
    inside WordPress; this proves the renderer does not reintroduce one.

 E. AN ARCHIVED ITEM NEVER APPEARS. The email's resolver deliberately includes
    post_status=archived (right for a record of what was sent, wrong for a
    claim about what is there now). Measured on live: the August 10 issue
    carries post 72616, archived after the send.

 F. THE EVENTS SECTION IS NOT REPLAYED, and the issue's own labels are used
    verbatim rather than re-grouped.

 G. THE DATE IS THE ISSUE'S, NOT THE CLOCK. The email builder dates itself
    date_i18n('F j, Y') at render time — correct on send day, wrong for every
    later re-render, which is what this block does by definition.

Usage:  python3 tools/gates/weekly-front-gate.py
Exit:   0 green, 1 RED (real finding), 2 CANNOT RUN (missing php/files).
"""
import json, re
import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))
PARTIAL = os.path.join(REPO, "archive-poc", "web", "_render-weekly-issue.php")
CONFIG = os.path.join(REPO, "platform", "config", "weekly-front.php")
FEED = os.path.join(REPO, "lg-weekly-digest", "includes", "class-lg-wd-front-feed.php")
# The OTHER reader. Both pools must honour the box-local override or the dev2
# flip is half-on -- see section A2.
FRONT = os.path.join(REPO, "archive-poc", "web", "index.php")

FAILS = []
PASSES = []


def ok(msg):
    PASSES.append(msg)


def bad(msg):
    FAILS.append(msg)


def cannot(msg):
    print("CANNOT RUN  " + msg)
    sys.exit(2)


# ── the payload the renderer is exercised with ──────────────────────────────
# Shaped exactly like LG_WD_Front_Feed::build() output, and deliberately mixed:
# a public card, a lite card, a pro card, and a public forum topic. The gated
# ones carry NO excerpt, because the feed strips it before it leaves WordPress —
# so if the renderer ever prints one it can only have invented it.
PAYLOAD = {
    "issue_id": 72147,
    "sent_at": "2026-07-13 08:20:04",
    "sections": [
        {
            "key": "post-type-videos", "label": "Videos", "template": "card",
            "items": [
                {"id": 1, "title": "A public video", "url": "/v/pub", "thumb_url": "",
                 "date": "Jul 6, 2026", "kind": "video", "tier": "public",
                 "gated": False, "excerpt": "Open to everyone.", "author": "Giuliano Nicoletti"},
                {"id": 2, "title": "A lite video", "url": "/v/lite", "thumb_url": "",
                 "date": "Jul 12, 2026", "kind": "video", "tier": "lite",
                 "gated": True, "excerpt": "", "author": "Michael Bashkin"},
                {"id": 3, "title": "A pro video", "url": "/v/pro", "thumb_url": "",
                 "date": "Jul 4, 2026", "kind": "video", "tier": "pro",
                 "gated": True, "excerpt": "", "author": "Brett Bailey"},
            ],
        },
        {
            "key": "topic", "label": "From The Forum", "template": "forum",
            "items": [
                {"id": 4, "title": "A public discussion", "url": "/hub/?topic=a/b",
                 "thumb_url": "", "date": "Jul 10, 2026", "kind": "discussion",
                 "tier": "public", "gated": False,
                 "excerpt": "Anyone tried this on a GS Mini?", "author": "Jonathan Scott"},
            ],
        },
    ],
}


def render(payload):
    """Render the partial in isolation and return its HTML."""
    harness = """<?php
error_reporting(E_ALL);
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
define('LG_FALLBACK_IMG', 'https://example.invalid/fallback.webp');
$lg_wk = json_decode(file_get_contents($argv[1]), true);
include $argv[2];
"""
    with tempfile.TemporaryDirectory() as d:
        hp = os.path.join(d, "h.php")
        pp = os.path.join(d, "p.json")
        open(hp, "w").write(harness)
        open(pp, "w").write(json.dumps(payload))
        r = subprocess.run(["php", hp, pp, PARTIAL], capture_output=True, text=True, timeout=60)
    if r.returncode != 0:
        cannot("the partial did not render: " + (r.stderr or "")[:300])
    if "Warning" in r.stderr or "Notice" in r.stderr or "Fatal" in r.stderr:
        bad("[render] the partial emits PHP diagnostics: " + " ".join(r.stderr.split())[:200])
    return r.stdout


def flag_state(config_php):
    """Read a flag config exactly as the code does, via php."""
    code = ("$c = @include $argv[1]; "
            "echo (is_array($c) && !empty($c['enabled'])) ? 'ON' : 'OFF';")
    r = subprocess.run(["php", "-r", code, config_php], capture_output=True, text=True, timeout=30)
    return r.stdout.strip()


def pair_state(config_php, local_php):
    """Read tracked config THEN the box-local override, exactly as both readers
    do. array_key_exists, not !empty: an override saying enabled=false must be
    able to force OFF over a tracked true, which `!empty` could not express."""
    code = ("$c = @include $argv[1]; $on = is_array($c) && !empty($c['enabled']); "
            "$l = @include $argv[2]; "
            "if (is_array($l) && array_key_exists('enabled', $l)) "
            "{ $on = ($l['enabled'] === true); } echo $on ? 'ON' : 'OFF';")
    r = subprocess.run(["php", "-r", code, config_php, local_php],
                       capture_output=True, text=True, timeout=30)
    return r.stdout.strip()


def main():
    if not shutil.which("php"):
        cannot("php is not on PATH")
    for f in (PARTIAL, CONFIG, FEED):
        if not os.path.isfile(f):
            cannot("missing " + os.path.relpath(f, REPO))

    # ── A. the flag, all three states ───────────────────────────────────────
    shipped = flag_state(CONFIG)
    if shipped not in ("ON", "OFF"):
        bad("[A] the shipped config did not read as ON or OFF (%r)" % shipped)
    else:
        ok("[A] shipped flag state reads cleanly: %s" % shipped)

    with tempfile.TemporaryDirectory() as d:
        on_php = os.path.join(d, "on.php")
        off_php = os.path.join(d, "off.php")
        open(on_php, "w").write("<?php return array('enabled' => true);\n")
        open(off_php, "w").write("<?php return array('enabled' => false);\n")
        if flag_state(on_php) != "ON":
            bad("[A] a config saying enabled=true did not read as ON")
        else:
            ok("[A] enabled=true reads ON")
        if flag_state(off_php) != "OFF":
            bad("[A] a config saying enabled=false did not read as OFF")
        else:
            ok("[A] enabled=false reads OFF")
        missing = flag_state(os.path.join(d, "does-not-exist.php"))
        if missing != "OFF":
            bad("[A] an ABSENT config did not fail closed (read %r, wanted OFF)" % missing)
        else:
            ok("[A] an absent config fails closed to OFF")

    # The feed must read the flag rather than hardcode a state, and must read
    # BOTH override channels — a fastcgi_param lands in $_SERVER but not in
    # getenv(), so reading one serves the wrong path on a preview URL.
    feed_src = open(FEED, encoding="utf-8").read()
    if "platform/config/weekly-front.php" not in feed_src:
        bad("[A] the feed does not read the tracked config")
    elif "getenv(" not in feed_src or "_SERVER[" not in feed_src:
        bad("[A] the feed reads only one override channel; a fastcgi_param "
            "lands in $_SERVER but not getenv()")
    else:
        ok("[A] the feed reads the config and both override channels")

    # ── A2. THE BOX-LOCAL OVERRIDE, which is how dev2 is switched on ────────
    # The dev2 flip is platform/config/weekly-front.local.php, NOT an FPM env[].
    # env[] was wrong on this box: the pool files are SYMLINKS INTO THE SERVING
    # CHECKOUT, so a "dev2-only" flip modifies tracked files there and a later
    # `pull --ff-only` can refuse. These assertions exist because the override
    # is now the only thing standing between "off for members" and "on", and
    # LIVE IS PROTECTED BY THE FILE BEING ABSENT rather than by any code check.
    with tempfile.TemporaryDirectory() as d:
        tracked_off = os.path.join(d, "t-off.php")
        tracked_on  = os.path.join(d, "t-on.php")
        loc_on      = os.path.join(d, "l-on.php")
        loc_off     = os.path.join(d, "l-off.php")
        absent      = os.path.join(d, "l-absent.php")
        open(tracked_off, "w").write("<?php return array('enabled' => false);\n")
        open(tracked_on,  "w").write("<?php return array('enabled' => true);\n")
        open(loc_on,      "w").write("<?php return array('enabled' => true);\n")
        open(loc_off,     "w").write("<?php return array('enabled' => false);\n")

        cases = [
            (tracked_off, absent,  "OFF", "tracked false + NO override = OFF (this is LIVE)"),
            (tracked_off, loc_on,  "ON",  "tracked false + override true = ON (this is dev2)"),
            (tracked_off, loc_off, "OFF", "an override saying false keeps it OFF"),
            (tracked_on,  loc_off, "OFF", "an override saying false OVERRIDES a tracked true"),
            (tracked_on,  absent,  "ON",  "tracked true still governs when no override exists"),
        ]
        for cfg, loc, want, label in cases:
            got = pair_state(cfg, loc)
            if got != want:
                bad("[A2] %s -- read %s, wanted %s" % (label, got, want))
            else:
                ok("[A2] " + label)

    # BOTH READERS OR NEITHER. The front page (archive-poc pool) and the feed
    # (looth-dev pool) run as DIFFERENT USERS, so an override honoured by only
    # one of them is a HALF-ON state: an enabled front page fetching a 404, or a
    # live endpoint nobody reads. That is invisible to any single-reader test.
    front_src = open(FRONT, encoding="utf-8").read() if os.path.isfile(FRONT) else ""
    for label, src in (("the front page", front_src), ("the feed", feed_src)):
        if "weekly-front.local.php" not in src:
            bad("[A2] %s does not read the box-local override -- the dev2 flip "
                "would be HALF-ON, which looks like a broken feature" % label)
        else:
            ok("[A2] %s reads the box-local override" % label)
    # Precedence: the override must sit BEFORE the env loop, or a gate forcing a
    # state via LG_WEEKLY_FRONT would be silently overruled by a file on disk.
    # WHAT THE FIVE CASES ABOVE DO **NOT** PROVE, said plainly: they exercise
    # pair_state(), which is this gate's own model of the precedence, not the
    # shipped readers -- those live inside a 1000-line front page and a WP class
    # and cannot be honestly re-hosted (a lifted function body inherits none of
    # its file's imports). So the semantic is pinned to the SOURCE here instead.
    # array_key_exists is load-bearing and !empty cannot replace it: an override
    # saying enabled=false must be able to force OFF over a tracked true, and
    # !empty([...'enabled'=>false]) is indistinguishable from "key not present".
    for label, src in (("the front page", front_src), ("the feed", feed_src)):
        if "weekly-front.local.php" not in src:
            continue
        if "array_key_exists" not in src:
            bad("[A2] %s does not use array_key_exists for the override -- with "
                "!empty, an override saying false cannot force OFF" % label)
        else:
            ok("[A2] %s uses array_key_exists, so the override can force OFF" % label)

    # ⚠️ ANCHOR ON THE CODE, NOT ON "getenv". The first cut of this assertion
    # compared against src.index("getenv") and went RED on correct code: both
    # files carry a DOCBLOCK above the function explaining "reading only
    # getenv() serves the OFF path", and that prose sits earlier in the file
    # than either construct. Matching a string that also lives in a comment is
    # the recorded trap; the env CONSTANT only ever appears in real code.
    env_call = re.compile(r"getenv\(\s*'LG_WEEKLY_FRONT'")
    for label, src in (("the front page", front_src), ("the feed", feed_src)):
        m = env_call.search(src)
        if "weekly-front.local.php" in src and m:
            if src.index("weekly-front.local.php") > m.start():
                bad("[A2] %s reads the override AFTER the env channels -- a gate "
                    "forcing a state would lose to a file on disk" % label)
            else:
                ok("[A2] %s reads the override BEFORE the env channels" % label)

    # ── B. nothing to say => nothing emitted ───────────────────────────────
    for label, empty in (("no payload", {}), ("no sections", {"sections": []})):
        out = render(empty)
        if out.strip() != "":
            bad("[B] %s still emitted %d bytes of markup" % (label, len(out.strip())))
        else:
            ok("[B] %s emits nothing at all" % label)

    # ── the populated render, used by C-G ──────────────────────────────────
    html = render(PAYLOAD)
    if "wkiss" not in html:
        bad("[render] the block did not render at all")
        return verdict()

    # ── C. the padlock, and the exact tier token ───────────────────────────
    for tier in ("lite", "pro"):
        cls = "rcard--gated-" + tier
        if cls not in html:
            bad("[C] a %s item did not get %s — an unmapped taxonomy slug "
                "(looth-%s) produces a class that matches no CSS rule, so the "
                "card renders with NO padlock and reads as free content"
                % (tier, cls, tier))
        else:
            ok("[C] %s item carries %s" % (tier, cls))
    if "rcard__gate" not in html:
        bad("[C] no rcard__gate element rendered — gated cards have no padlock")
    else:
        ok("[C] the padlock element renders")
    if "looth-lite" in html or "looth-pro" in html:
        bad("[C] a raw taxonomy slug (looth-*) reached the markup unmapped")
    else:
        ok("[C] no raw looth-* slug in the markup")
    # the public card must NOT be gated
    if "A public video" in html:
        seg = html.split("A public video")[0].rsplit("<a class=", 1)[-1]
        if "rcard--gated" in seg:
            bad("[C] the PUBLIC card was rendered gated")
        else:
            ok("[C] the public card is not gated")

    # ── D. no gated prose in the bytes ─────────────────────────────────────
    # A DISCUSSION item, not a video, and the difference is the whole assertion.
    # The first version of this test used a video card and passed no matter what
    # the guard did — because .rcard renders no excerpt at all, so the secret
    # could never appear and the check was decoration. The red-first harness
    # caught it: removing the gated-guard entirely left the gate green.
    # The forum section is the ONLY part of an issue that carries prose
    # (measured: card items come back with empty excerpts, layout-v2 posts keep
    # their body in meta), so it is the only place this can be tested at all.
    leaked = render({
        "issue_id": 1, "sent_at": "2026-07-13 08:20:04",
        "sections": [{
            "key": "topic", "label": "From The Forum", "template": "forum",
            "items": [{"id": 9, "title": "Gated topic", "url": "/g", "thumb_url": "",
                       "date": "", "kind": "discussion", "tier": "lite", "gated": True,
                       # a payload that WRONGLY carries prose for a gated item:
                       # the renderer must not print it even when handed it.
                       "excerpt": "SECRET-MEMBER-PROSE", "author": "A"}],
        }],
    })
    if "SECRET-MEMBER-PROSE" in leaked:
        bad("[D] gated prose reached the rendered page — the renderer prints an "
            "excerpt for a gated item instead of dropping it")
    elif "dcard__excerpt" not in render(PAYLOAD):
        # Liveness: an absence assertion is worthless if the element it is
        # looking for never renders in any state. Prove the renderer DOES print
        # a discussion excerpt when the item is public, or [D] is measuring a
        # feature that does not exist.
        bad("[D] no discussion excerpt renders in ANY state, so the leak check "
            "above is vacuous")
    else:
        ok("[D] gated prose never reaches the markup, and public prose does")

    # ── C/E/F. the feed's own decisions, CALLED not grepped ────────────────
    # The first version of this gate asserted these by searching the source for
    # the words "archived" and "date-forward" — which the file's own docblock
    # satisfies, so deleting the filters left the gate green. Both were caught
    # by the red-first harness and are now exercised through reflection, with no
    # WordPress needed: map_tier, hidden_status and skip_section are pure.
    probe = r'''<?php
define('ABSPATH', '/tmp/'); define('HOUR_IN_SECONDS', 3600);
require $argv[1];
$r = new ReflectionClass('LG_WD_Front_Feed');
$out = [];
foreach (['map_tier','hidden_status','skip_section','map_kind'] as $m) {
    if (!$r->hasMethod($m)) { $out['missing'][] = $m; continue; }
    $x = $r->getMethod($m); $x->setAccessible(true);
    $out['have'][] = $m;
    if ($m === 'map_tier') {
        foreach (['','public','looth-lite','lite','looth-pro','pro','a-new-tier'] as $t) {
            $out['tier'][$t] = $x->invoke(null, $t);
        }
    }
    if ($m === 'hidden_status') {
        foreach (['publish','archived','trash','draft','closed','open'] as $t) {
            $out['status'][$t] = $x->invoke(null, $t);
        }
    }
    if ($m === 'skip_section') {
        foreach ([['card',false],['forum',false],['date-forward',false],
                  ['html-block',false],['header',false],['card',true]] as $c) {
            $out['skip'][$c[0].'|'.($c[1]?'hdr':'-')] = $x->invoke(null, $c[0], $c[1]);
        }
    }
}
echo json_encode($out);
'''
    with tempfile.TemporaryDirectory() as d:
        pf = os.path.join(d, "probe.php")
        open(pf, "w").write(probe)
        r = subprocess.run(["php", pf, FEED], capture_output=True, text=True, timeout=60)
    try:
        got = json.loads(r.stdout)
    except Exception:
        cannot("could not call the feed's predicates: " + (r.stderr or r.stdout)[:300])

    if got.get("missing"):
        bad("[C] the feed no longer exposes %s — the decisions this gate proves "
            "have been inlined again, and an inline comparison cannot be tested"
            % ", ".join(got["missing"]))
    else:
        ok("[C] the feed's decisions are callable predicates")

    want_tier = {"": "public", "public": "public", "looth-lite": "lite", "lite": "lite",
                 "looth-pro": "pro", "pro": "pro", "a-new-tier": "pro"}
    for slug, want in want_tier.items():
        g = (got.get("tier") or {}).get(slug)
        if g != want:
            bad("[C] map_tier(%r) returned %r, wanted %r — an unmapped taxonomy "
                "slug produces rcard--gated-looth-lite, which matches no CSS "
                "rule, so a member-only card renders with NO padlock"
                % (slug, g, want))
    if all((got.get("tier") or {}).get(k) == v for k, v in want_tier.items()):
        ok("[C] map_tier maps every tier slug, and an unknown tier fails CLOSED to pro")

    st = got.get("status") or {}
    if st.get("archived") is not True:
        bad("[E] hidden_status('archived') is not true — the resolver returns "
            "archived posts and live's latest issue contains one (72616)")
    elif st.get("publish") is not False:
        bad("[E] hidden_status('publish') is true — the filter hides everything")
    else:
        ok("[E] archived posts are filtered, published ones are not")

    sk = got.get("skip") or {}
    if sk.get("date-forward|-") is not True:
        bad("[F] skip_section does not skip the events (date-forward) section; "
            "an issue's events are what was upcoming AT SEND TIME")
    elif sk.get("card|-") is not False or sk.get("forum|-") is not False:
        bad("[F] skip_section is skipping card/forum sections — the block would "
            "render empty")
    elif sk.get("header|-") is not True or sk.get("card|hdr") is not True:
        bad("[F] skip_section does not skip group headers")
    else:
        ok("[F] events and headers are skipped; card and forum sections are kept")

    # the issue's own labels, verbatim
    for label in ("Videos", "From The Forum"):
        if label not in html:
            bad("[F] the issue's own section label %r was not rendered verbatim" % label)
        else:
            ok("[F] section label %r rendered verbatim" % label)

    # ── G. the date is the issue's, not the clock ──────────────────────────
    # sent_at is 2026-07-13, a Monday.
    if "Monday 13 July" not in html:
        bad("[G] the masthead does not carry the issue's own send date "
            "(expected 'Monday 13 July' from sent_at 2026-07-13)")
    else:
        ok("[G] the masthead carries the issue's own send date")
    import datetime
    today = datetime.date.today()
    if today.strftime("%-d %B") in html and today != datetime.date(2026, 7, 13):
        bad("[G] the masthead carries TODAY's date — the block is dating an old "
            "issue by the render clock, the defect measured in the email builder")
    else:
        ok("[G] the masthead does not use the render clock")

    # forum items must render as .dcard, not .rcard — they are not in
    # content_item and are genuinely a different thing
    if "dcard" not in html:
        bad("[F] the forum item did not render as a discussion card")
    else:
        ok("[F] the forum item renders as a discussion card")

    return verdict()


def verdict():
    for p in PASSES:
        print("  pass  " + p)
    if FAILS:
        print()
        for f in FAILS:
            print("  FAIL  " + f)
        print("\nGATE 54 RED — %d finding(s), %d passed" % (len(FAILS), len(PASSES)))
        return 1
    print("\nGATE 54 GREEN — %d assertions" % len(PASSES))
    return 0


if __name__ == "__main__":
    sys.exit(main())
