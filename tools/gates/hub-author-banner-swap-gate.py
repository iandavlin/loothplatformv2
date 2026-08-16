#!/usr/bin/env python3
"""
GATE 64 — hub-author-banner-swap-gate — backlog 38, P0. Number from keeper,
2026-08-16. Reads THIS BRANCH'S TREE via the featured-members lane preview
(not the live serve), so it registers normally in run-all.sh rather than
waiting for a deploy.

The Hub's Advanced Search modal
picks an author and stays open (Ian 2026-06-11: "apply without closing"), so
forums.js's fmodalApply() fetches the filtered page and swaps three things
back into the live DOM in place: the feed cards, the modal body, the chip
bar. The green author banner (.hub-author-hdr) was never on that list — it
sits OUTSIDE all three in the server's own DOM order (sponsor rail, banner,
#hub-feed-results) — so picking an author from the dropdown while the modal
stays open updated the cards and the chip correctly and silently left the
banner exactly as it was: absent if none was showing, and it would go stale
if a different author's banner was already up.

Ian reported this as "works for Patrick, missing for Rick" and keeper's first
read suspected name fragility (the banner's own author-id lookup vs. the SQL
filter using different matchers on an awkward display name). PROVEN WRONG
here, on purpose: this gate drives BOTH names through the identical in-place
pick path and the defect reproduces IDENTICALLY for both — so assertion B
below is deliberately parametrized over two very different display names
(one plain ASCII, one 47 chars with a real U+2026 ellipsis) rather than just
the one Ian happened to report broken. A gate that only proves the reported
name is fixed would still miss the actual bug class.

WHAT THIS DRIVES: real clicks through the REAL suggest dropdown (type into
the field, wait for the fetch, mousedown the result) — not a hand-built
?author= URL. A hand-built URL exercises server-side rendering only and
cannot see this bug at all; it is 100% a client-side DOM-swap omission,
invisible to anything that does not execute forums.js in a real page.

FIXTURES: author_id 27 (Rick Liftig...) and 396 (Patrick Niedermeyer) are
pre-existing dev2 data (8 and 2 forum topics respectively, confirmed via
forums.topic) — nothing minted, nothing to clean up.

FLAG: platform/config/hub-author-banner-swap.php, OFF by default. Both states
are tested against the IDENTICAL branch commit via two lane-preview nginx
locations (tools/preview/lane-preview.sh, platform/nginx/lane-preview-
featured-members.conf) — .../hub/ pins the flag ON via fastcgi_param (the
one and only place it can be turned on; never by a query string), .../hub-off/
serves the same worktree with no override, so "OFF" is not a different
commit's behaviour, it is what the SAME code does when the switch is not
thrown — a real byte-inertness comparison, not one against main.

  A. FLAG OFF is inert: window.LG_HUB_AUTHOR_BANNER_SWAP is undefined, and an
     author-filtered response never emits the #hub-author-headers wrapper
     (the individual .hub-author-hdr divs still render, unwrapped — flag-off
     changes NOTHING about what a plain page load already showed).
  B. FLAG ON, the real bug, for BOTH fixture authors: open the Advanced
     Search modal, type into the real field, wait for the real suggest
     fetch, mousedown the real result. Banner must appear, named correctly,
     modal stays open, chip bar reflects the filter, feed cards all belong
     to that author.
  C. FLAG ON, the reverse direction: from B's state, click the modal's own
     chip removal link (still in-place, not a full nav) and confirm the
     banner disappears too — the "stale banner" half of the bug, worse than
     "missing" because nothing visually signals it is wrong.
  D. THE FIX IS STRUCTURAL, not incidental: forums.js's fmodalApply()
     actually contains the guarded #hub-author-headers swap, matched as a
     real code construct (a getElementById call), not a bare word that could
     be sitting in a comment — this gate's own siblings this session have
     each self-no-verdicted once on exactly that mistake.

Needs:  chrome-dev on 127.0.0.1:9222, tools/gates/gate-env.sh resolving a
        token, the featured-members lane preview UP (tools/preview/
        lane-preview.sh up featured-members).
Exit 0 = GREEN. Exit 1 = RED. Exit 2 = CANNOT RUN (missing chrome/token/
preview — never silently exit 0 on a broken harness).
"""

import json
import os
import re
import subprocess
import sys
import time
import urllib.request

try:
    import websocket
except ImportError:
    print("CANNOT RUN  python3-websocket-client required")
    sys.exit(2)

CDP = "http://127.0.0.1:9222"
HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(os.path.dirname(HERE))  # tools/gates/<file>.py -> tools -> repo (2 dirnames)

PREVIEW_ON = "/preview/featured-members/hub/"
PREVIEW_OFF = "/preview/featured-members/hub-off/"

# One plain-ASCII name, one 47-char name with a real U+2026 ellipsis — the
# whole point of testing two is that the defect does not care which.
# key -> (typed prefix, full stored name)
FIXTURE_AUTHORS = {
    "rick": ("Rick Liftig", "Rick Liftig luthier wannabe… slowly gettinthere"),
    "patrick": ("Patrick Niedermeyer", "Patrick Niedermeyer"),
}

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


# The Hub author-suggest endpoint masks anon visitors near to zero results —
# a real, already-known, already-flagged, UNRELATED defect (backlog 27,
# platform/config/author-search-mask.php: "anon 0, member 1, admin 1" on the
# same query). Confirmed the hard way: this gate returned NO VERDICT on its
# first run because the dev-gate cookie alone reads as anon to
# lg_bb_mirror_whoami(), and the suggest dropdown legitimately never
# populated — not a bug in the fix under test, a different, pre-existing one.
# So a real WP session is required to test the banner-swap fix at all, same
# as the reported bug itself only exists for a SIGNED-IN member (Ian). Minted
# rather than harvested — a gate that depends on a human's live browser
# session goes red on a Monday for no reason — following the exact pattern
# tools/gates/following-section-gate.py already uses successfully. uid 1 is
# that gate's own default for "a real member," reused here for the same
# reason: read-only (no writes ever touch this account), already proven safe.
DEFAULT_UID = 1


def mint_wp_cookies(uid):
    r = subprocess.run(
        ["sudo", "-n", "-u", "looth-dev", "wp", "eval",
         f'$e=time()+3600; echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie({uid},$e,"logged_in")."\\n";'
         f'echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie({uid},$e,"secure_auth")."\\n";',
         "--skip-themes", "--path=/var/www/dev"],
        capture_output=True, text=True, timeout=30)
    pairs = [l for l in r.stdout.splitlines() if l.startswith("wordpress")]
    if len(pairs) < 2:
        print("CANNOT RUN  could not mint a WP session (needs sudo -u looth-dev wp): "
              + (r.stderr or r.stdout)[:200])
        sys.exit(2)
    return pairs


class Session:
    def __init__(self):
        req = urllib.request.Request(CDP + "/json/new?about:blank", method="PUT")
        t = json.load(urllib.request.urlopen(req, timeout=15))
        self.target_id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"], max_size=None,
                                               timeout=15, suppress_origin=True)
        self._id = 0

    def finish(self):
        for fn in (lambda: self.ws.close(),
                   lambda: urllib.request.urlopen(CDP + "/json/close/" + self.target_id, timeout=10).read()):
            try:
                fn()
            except Exception:  # noqa: BLE001
                pass

    def call(self, method, **params):
        self._id += 1
        self.ws.send(json.dumps({"id": self._id, "method": method, "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self._id:
                if "error" in m:
                    raise RuntimeError(f"{method}: {m['error']}")
                return m.get("result", {})

    def js(self, expr, quiet=False):
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=True, awaitPromise=True)
        if r.get("exceptionDetails"):
            if quiet:
                return None
            raise RuntimeError("JS threw: " + str(r["exceptionDetails"].get("text"))[:200])
        return r.get("result", {}).get("value")

    def goto(self, url, settle=1.0, deadline=20.0):
        self.call("Page.navigate", url=url)
        start = time.monotonic()
        while time.monotonic() - start < deadline:
            time.sleep(0.15)
            try:
                if self.js("document.readyState", quiet=True) == "complete":
                    break
            except Exception:  # noqa: BLE001
                continue
        time.sleep(settle)


def arm(s, tok, domain, wp_cookies):
    # Clear first, then set — a stale cookie from a prior gate's run on the
    # shared profile is a documented trap (duplicate host-only-vs-dotted
    # cookies serving a DIFFERENT identity than the one under test).
    s.call("Network.clearBrowserCookies")
    s.call("Network.setCookie", name="loothdev_auth", value=tok, domain=domain, path="/", secure=True)
    # HOST-ONLY, no leading dot — the dev gate cookie is dotted and the WP one
    # is not; setting the WP cookie on the dotted domain would leave two
    # cookies of the same name on the profile and the run executes as
    # whichever the browser happens to send (trap-shared-chrome-profile-
    # duplicate-session-cookies). domain here is dotted (arm's own caller
    # passes "." + LG_GATE_DOMAIN for the gate cookie); strip the leading dot
    # for the WP ones.
    host_only = domain[1:] if domain.startswith(".") else domain
    for pair in wp_cookies:
        name, _, value = pair.partition("=")
        s.call("Network.setCookie", name=name, value=value, domain=host_only, path="/", secure=True)


def pick_author(s, typed):
    """Open the Advanced Search modal, type into the REAL field, wait for the
    REAL suggest fetch, mousedown the REAL result. Returns the picked name or
    None if no suggestion appeared."""
    return s.js("""(async () => {
        const modal = document.getElementById('hub-fmodal');
        if (!modal) return {error: 'no #hub-fmodal on this page'};
        modal.hidden = false;
        const inp = modal.querySelector('[data-hub-author]');
        if (!inp) return {error: 'no [data-hub-author] field'};
        inp.focus();
        inp.value = %s;
        inp.dispatchEvent(new Event('input', {bubbles: true}));
        // 40 x 200ms = 8s: the shared box runs several lanes' own CDP
        // sessions concurrently, and this suggest fetch competes with all of
        // them — 20 x 150ms (3s) was tight enough to occasionally read as
        // "never returned a result" under real cross-lane load rather than
        // any defect (confirmed: the identical fetch, run in isolation
        // moments later, always answered correctly).
        for (let i = 0; i < 40; i++) {
            await new Promise(r => setTimeout(r, 200));
            const box = modal.querySelector('[data-hub-suggest="author"]');
            const item = box && box.querySelector('[data-pick]');
            if (item) {
                const name = item.getAttribute('data-pick');
                item.dispatchEvent(new MouseEvent('mousedown', {bubbles: true}));
                await new Promise(r => setTimeout(r, 1200));
                return {picked: name};
            }
        }
        return {error: 'suggest dropdown never returned a result for ' + %s};
    })()""" % (json.dumps(typed), json.dumps(typed)))


def clear_author_in_modal(s):
    """Click the modal's OWN chip-removal link for the author facet (still
    inside .hub-fmodal__body, so it goes through the same in-place apply the
    original pick did — NOT the banner's own Clear-author link, which sits
    outside the modal and would just be a normal full navigation)."""
    return s.js("""(async () => {
        const modal = document.getElementById('hub-fmodal');
        const mbody = modal.querySelector('.hub-fmodal__body');
        const links = Array.from(mbody.querySelectorAll('a[href]'));
        const clear = links.find(a => /[?&]author=/.test(a.getAttribute('href') || '') === false
                                    && a.textContent.trim() === '\\u00d7');
        if (!clear) return {error: 'no chip removal link found in the modal body'};
        clear.click();
        await new Promise(r => setTimeout(r, 1500));
        return {ok: true};
    })()""")


def read_state(s):
    return s.js("""({
        bannerPresent: !!document.querySelector('.hub-author-hdr'),
        bannerName: (document.querySelector('.hub-author-hdr__name') || {}).textContent || null,
        wrapperPresent: !!document.getElementById('hub-author-headers'),
        chipbarText: (document.querySelector('.hub-chipbar') || {}).textContent || '',
        cardAuthors: Array.from(document.querySelectorAll('#hub-feed-results .fc-author__name, #hub-feed-results .feed-card__op-author'))
            .map(a => a.textContent.trim()).filter((v, i, a) => a.indexOf(v) === i),
        modalOpen: !(document.getElementById('hub-fmodal') || {}).hidden,
        flag: window.LG_HUB_AUTHOR_BANNER_SWAP === true
    })""")


def norm(t):
    return re.sub(r"\s+", " ", (t or "")).strip()


def assertion_a_off_is_inert(host, tok, domain, wp_cookies):
    s = Session()
    try:
        arm(s, tok, domain, wp_cookies)
        s.goto(host + PREVIEW_OFF)
        st = read_state(s)
        if st is None:
            DEAD.append("[A] could not read page state on the OFF preview")
            return
        if st.get("flag") is True:
            RED.append("[A] window.LG_HUB_AUTHOR_BANNER_SWAP is TRUE on the OFF preview — "
                        "the flag is not actually off")
            return
        OK.append("[A1] flag OFF: window.LG_HUB_AUTHOR_BANNER_SWAP is not true")

        # Author-filtered, flag OFF: individual banner divs still render (the
        # flag never touched the base behaviour), but the wrapper must not.
        name = FIXTURE_AUTHORS["rick"][1]
        import urllib.parse
        s.goto(host + PREVIEW_OFF + "?author=" + urllib.parse.quote(name))
        st2 = read_state(s)
        if st2 is None:
            DEAD.append("[A] could not read page state on the OFF preview, author-filtered")
            return
        if not st2["bannerPresent"]:
            DEAD.append("[A] OFF preview with ?author= never rendered ANY banner — "
                        "cannot tell inert-flag from dead-fixture; fixture may be stale")
            return
        if st2["wrapperPresent"]:
            RED.append("[A2] flag OFF still emits #hub-author-headers — not byte-inert")
        else:
            OK.append("[A2] flag OFF: banner divs still render (unwrapped), no wrapper added — "
                      "byte-inert with this feature absent")
    except Exception as e:  # noqa: BLE001
        DEAD.append(f"[A] {type(e).__name__}: {e}")
    finally:
        s.finish()


def assertion_bc_on_fixes_both_directions(host, tok, domain, wp_cookies, key, typed_prefix, full_name):
    s = Session()
    try:
        arm(s, tok, domain, wp_cookies)
        s.goto(host + PREVIEW_ON)
        pre = read_state(s)
        if pre and pre.get("flag") is not True:
            DEAD.append(f"[B:{key}] window.LG_HUB_AUTHOR_BANNER_SWAP is not true on the ON "
                        f"preview — cannot test the fix at all")
            return

        picked = pick_author(s, typed_prefix)
        if not picked or picked.get("error"):
            DEAD.append(f"[B:{key}] {picked.get('error') if picked else 'pick_author returned nothing'}")
            return

        st = read_state(s)
        if st is None:
            DEAD.append(f"[B:{key}] could not read page state after picking")
            return

        if not st["modalOpen"]:
            DEAD.append(f"[B:{key}] modal closed after the pick — not the in-place path "
                        f"this bug lives in; result is not meaningful")
            return

        if norm(full_name) not in norm(st["chipbarText"]):
            DEAD.append(f"[B:{key}] chip bar never shows {full_name!r} after the pick — "
                        f"the filter itself did not apply; cannot test the banner in isolation")
            return

        if not st["bannerPresent"] or not st["wrapperPresent"]:
            RED.append(f"[B:{key}] picked {full_name!r} via the real suggest dropdown with the "
                       f"modal open (the in-place apply path) — filter applied (chip bar confirms "
                       f"it), feed cards present, but NO banner rendered. This is the reported bug.")
        elif norm(st["bannerName"] or "") != norm(full_name):
            RED.append(f"[B:{key}] banner rendered but named {st['bannerName']!r}, not {full_name!r}")
        else:
            # "Private member" is a KNOWN, orthogonal data artifact — an
            # author_id=0 row whose author_name string still literally
            # matches the filter (found investigating this exact gate,
            # 2026-08-16: data-author-id="0" in the real markup, no name
            # collision in forums.person; a legacy/orphaned link, not a
            # second person sharing the name). The name-based filter
            # correctly includes it by string match; the byline just can't
            # resolve author_id 0 to a profile. Unrelated to the banner-swap
            # mechanism under test here — tolerated, not chased, so this
            # gate does not go DEAD on a pre-existing data-quality gap that
            # is the comma/id-based-filtering family's problem, not this one's.
            bad_authors = [a for a in st["cardAuthors"]
                           if norm(a) != norm(full_name) and norm(a) != "private member"]
            if bad_authors:
                DEAD.append(f"[B:{key}] banner correct but feed cards include other authors "
                           f"{bad_authors!r} — filter itself is not clean; not this bug, but "
                           f"invalidates a strict read of the result")
            else:
                OK.append(f"[B:{key}] in-place pick of {full_name!r}: banner appeared correctly "
                          f"named, modal stayed open, chip bar + {len(st['cardAuthors'])} feed "
                          f"card(s) all agree (tolerating known 'Private member' author_id=0 rows)")

        # C — the reverse direction, only meaningful if B actually got a banner up.
        if st and st["bannerPresent"]:
            res = clear_author_in_modal(s)
            if not res or res.get("error"):
                DEAD.append(f"[C:{key}] {res.get('error') if res else 'clear_author_in_modal returned nothing'}")
                return
            st3 = read_state(s)
            if st3 is None:
                DEAD.append(f"[C:{key}] could not read page state after clearing")
                return
            if st3["bannerPresent"]:
                RED.append(f"[C:{key}] cleared the author filter in place (modal chip x) — chip "
                           f"bar and feed updated but the banner is STILL SHOWING {st3['bannerName']!r}. "
                           f"Stale, not absent — a member would see the wrong person's banner.")
            else:
                OK.append(f"[C:{key}] in-place clear removed the banner along with the filter")
    except Exception as e:  # noqa: BLE001
        DEAD.append(f"[B/C:{key}] {type(e).__name__}: {e}")
    finally:
        s.finish()


def assertion_d_structural():
    path = os.path.join(REPO, "bb-mirror", "web", "forums.js")
    try:
        src = open(path, encoding="utf-8").read()
    except OSError as e:
        DEAD.append(f"[D] could not read forums.js: {e}")
        return
    fn_match = re.search(r"function fmodalApply\s*\([^)]*\)\s*\{", src)
    if not fn_match:
        DEAD.append("[D] fmodalApply() not found in forums.js — cannot check it")
        return
    # Grab from fmodalApply's opening brace to the next top-level "function "
    # (or EOF) as a cheap body bound — good enough to prove the swap lives
    # INSIDE this function, not merely somewhere in the file.
    tail = src[fn_match.end():]
    nxt = re.search(r"\n  function \w+\(", tail)
    body = tail[: nxt.start() if nxt else len(tail)]
    has_guard = re.search(r"window\.LG_HUB_AUTHOR_BANNER_SWAP", body)
    has_swap = re.search(r"getElementById\(\s*['\"]hub-author-headers['\"]\s*\)", body)
    if has_guard and has_swap:
        OK.append("[D] fmodalApply() contains a flag-guarded #hub-author-headers swap (structural, "
                  "matched as a real getElementById call, not a bare word in a comment)")
    else:
        RED.append(f"[D] fmodalApply() is missing the fix: guard present={bool(has_guard)}, "
                   f"swap present={bool(has_swap)}")


def main():
    env = gate_env()
    host, tok = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"]
    domain = "." + env["LG_GATE_DOMAIN"]
    wp_cookies = mint_wp_cookies(DEFAULT_UID)

    assertion_d_structural()
    assertion_a_off_is_inert(host, tok, domain, wp_cookies)
    for key, (typed, full) in FIXTURE_AUTHORS.items():
        assertion_bc_on_fixes_both_directions(host, tok, domain, wp_cookies, key, typed, full)

    for m in OK:
        print(f"  ok   {m}")
    for m in RED:
        print(f"  RED  {m}")
    for m in DEAD:
        print(f"  DEAD {m}")

    if RED:
        print(f"hub-author-banner-swap-gate: RED — {len(RED)} finding(s)")
        return 1
    if DEAD:
        print(f"hub-author-banner-swap-gate: NO VERDICT ({len(DEAD)} check(s) could not run)")
        return 2
    print(f"hub-author-banner-swap-gate: GREEN — {len(OK)} assertions")
    return 0


if __name__ == "__main__":
    sys.exit(main())
