#!/usr/bin/env python3
"""cadence-control-gate.py — the account-level email frequency control, BOTH WAYS.

    python3 tools/gates/cadence-control-gate.py
    python3 tools/gates/cadence-control-gate.py --prove     # red-first, both directions
    python3 tools/gates/cadence-control-gate.py --cdp http://127.0.0.1:9334

account-following lane, 2026-08-01.

── WHY THIS GATE EXISTS ──────────────────────────────────────────────────────
CLAUDE.md names the failure class outright: "Gates assert what should be PRESENT;
they cannot see what should be ABSENT." Every one of the six defects that reached
Ian's phone through a green suite was in that blind spot, and the cadence control
is the current occupant of it — it is SUPPOSED to be invisible, and a thing that is
supposed to be invisible is exactly what nobody writes an assertion for.

So this asserts both directions, and it does NOT do it by reading the repo. It
fetches the two surfaces that really exist on this box and diffs their behaviour:

    OFF   https://dev2.loothgroup.com/manage-subscription/
    ON    https://dev2.loothgroup.com/preview/account-following/manage-subscription/
          (LG_FOLLOWING_CADENCE=1, set by the lane-preview fastcgi_param)

── AND THE TWO SURFACES ARE EACH OTHER'S RED-FIRST CONTROL ───────────────────
`--prove` re-runs the ON assertions against the OFF surface and the OFF assertions
against the ON surface, and DEMANDS that every one of them fails. That is a real
red-first without editing a file or building a deliberately-broken tree: if an
assertion passes against the surface it was written to reject, it is measuring
nothing. feedback-red-first-that-stays-green — twice in one weekend — is what this
guards against.

── THE ONE THAT IS NOT OBVIOUS: `hidden` DOES NOT HIDE ───────────────────────
The container ships with the `hidden` attribute and is revealed by the server, not
by the page. But the UA sheet's [hidden]{display:none} LOSES to
.lg-manage-sub__fol-freq{display:flex} — same specificity, later origin. So without
an explicit rule in the page's own stylesheet, `hidden` hides NOTHING and the
control paints for precisely the members lg_fd_cadence_state refuses to serve.
That is the whole safety property, defeated by a CSS cascade, and it is invisible
to any assertion that only checks the attribute. Phase 4 fetches the SERVED
stylesheet and requires the rule.

── WHAT THIS GATE DOES NOT CLAIM ─────────────────────────────────────────────
It does not prove the control is usable, because on this box it correctly is not:
lg_fd_cadence_state lives in the WP pool behind LG_FOLLOW_DIGEST_ENABLED (false in
the tracked config) plus a per-member allowlist, so it 404s and the JS removes the
node. That is asserted as its own phase — flag ON but member NOT SERVED must still
be ABSENT, which is the strictest form of the absent assertion available here.
The value the sender resolves is proven separately and for real, by
platform/bin/cadence-seam-proof.php.
"""

import argparse
import json
import re
import ssl
import subprocess
import time
import sys
import urllib.request

DEV2 = "https://dev2.loothgroup.com"
OFF_PATH = "/manage-subscription/"
ON_PATH = "/preview/account-following/manage-subscription/"

FAIL = []
OKS = []


def ok(msg, detail=""):
    OKS.append(msg)
    print("  ok   %s%s" % (msg, "  — " + detail if detail else ""))


def bad(msg, detail=""):
    FAIL.append(msg)
    print("  FAIL %s%s" % (msg, "  — " + detail if detail else ""))


def log(msg):
    print(msg)


def cannot_run(why):
    """No verdict. EXIT 2 — run-all.sh's third state, and getting this wrong is not
    cosmetic: its run() helper treats 0 as green, 2 as NO VERDICT and *anything
    else* as RED. This exited 3 in the first draft, which would have counted a
    missing lane preview as a real finding and blocked every other lane's push for
    a reason that has nothing to do with their code. One unflagged lane blocking
    everyone else's deploy is the failure CLAUDE.md is loudest about."""
    print("\n############ cadence-control gate CANNOT RUN ############")
    print("  " + why)
    print("  Not reported as GREEN: an unrun gate that prints green is worse than")
    print("  no gate, because it retires the question. Not reported as RED either:")
    print("  a red with no findings is indistinguishable from real ones.")
    sys.exit(2)


def mint_cookies(uid):
    """A real WP session for the acting member plus the dev gate cookie.

    Minted, not harvested — a gate that needs a human's live browser session goes
    red on a Monday for no reason.
    """
    r = subprocess.run(
        ["sudo", "-n", "-u", "looth-dev", "wp", "eval",
         '$e=time()+3600; echo LOGGED_IN_COOKIE."=".wp_generate_auth_cookie(%d,$e,"logged_in")."\\n";'
         'echo SECURE_AUTH_COOKIE."=".wp_generate_auth_cookie(%d,$e,"secure_auth")."\\n";' % (uid, uid),
         "--skip-themes", "--path=/var/www/dev"],
        capture_output=True, text=True)
    pairs = [l for l in r.stdout.splitlines() if l.startswith("wordpress")]
    if len(pairs) < 2:
        cannot_run("could not mint a WP session cookie (needs sudo -n -u looth-dev wp)")
    g = subprocess.run(
        ["sudo", "-n", "grep", "-oP", r'loothdev_token\s+"\K[^"]+',
         "/etc/nginx/snippets/loothdev-tokens.conf"], capture_output=True, text=True)
    tok = (g.stdout.strip().splitlines() or [""])[0]
    if tok:
        pairs.append("loothdev_auth=" + tok)
    return pairs


def fetch(url, cookies):
    ctx = ssl.create_default_context()
    ctx.check_hostname = False
    ctx.verify_mode = ssl.CERT_NONE
    try:
        req = urllib.request.Request(url, headers={"Cookie": "; ".join(cookies)})
        with urllib.request.urlopen(req, timeout=25, context=ctx) as r:
            return r.read().decode("utf-8", "replace")
    except Exception as e:
        print("    (fetch failed: %s — %s)" % (url, e))
        return None


# ── THE ASSERTION SETS, as data ──────────────────────────────────────────────
# Written as (name, predicate) so --prove can run the SAME predicates against the
# opposite surface and require them to fail. Anything hand-inlined below could not
# be inverted, which is how a red-first quietly stops covering half the file.

def strip_js_comments(src):
    """Drop /* … */ and // … so a write-path assertion reads CODE, not prose.

    ⚠️ THE REASON IS A CAUGHT FAILURE, not hygiene. "no lg_disc_email_cadence in
    the served JS" went RED against a correct file, because the file's own comment
    explains WHICH usermeta key it deliberately never writes. A grep cannot tell an
    explanation from an instruction, and a gate that goes red on good code is a gate
    that gets switched off. Lex it, do not grep it.

    String-aware enough for this file: it keeps quoted text so a URL containing //
    survives. It is not a full JS lexer and does not need to be — it is used ONLY
    to decide whether an identifier appears in executable code.
    """
    out, i, n = [], 0, len(src)
    quote = None
    while i < n:
        c = src[i]
        nxt = src[i + 1] if i + 1 < n else ""
        if quote:
            out.append(c)
            if c == "\\":
                if i + 1 < n:
                    out.append(nxt)
                    i += 1
            elif c == quote:
                quote = None
            i += 1
        elif c in "\"'`":
            quote = c
            out.append(c)
            i += 1
        elif c == "/" and nxt == "*":
            j = src.find("*/", i + 2)
            i = n if j < 0 else j + 2
        elif c == "/" and nxt == "/":
            j = src.find("\n", i)
            i = n if j < 0 else j
        else:
            out.append(c)
            i += 1
    return "".join(out)


def freq_tag(html):
    """The opening tag of the cadence container, or '' if it is not there.

    ⚠️ EVERY CONTAINER-SHAPED ASSERTION GOES THROUGH THIS, and that is a fix, not
    a tidy-up. `"hidden" in html` passed against the flag-OFF surface — which has
    no cadence control at all — because SOME other element on a 89KB page carries
    a hidden attribute. The predicate was true for a reason that had nothing to do
    with what it claimed to measure, and only --prove caught it. Scope to the tag.
    """
    m = re.search(r'<[^<>]*id="lg-fol-freq"[^<>]*>', html)
    return m.group(0) if m else ""


def on_assertions(html):
    """What MUST be true of a surface where LG_FOLLOWING_CADENCE is ON."""
    tag = freq_tag(html)
    return [
        ("the frequency control is rendered", "lg-fol-freq" in html),
        ("all three cadences are offered", html.count("data-cadence=") == 3),
        ("…and they are instant/daily/weekly",
         all(('data-cadence="%s"' % v) in html for v in ("instant", "daily", "weekly"))),
        ("it announces as a radiogroup", 'role="radiogroup"' in html),
        # Ian's ruling: this is ONE ACCOUNT-LEVEL setting, not per-thread. The copy
        # is the only thing that says so, so the copy is gated.
        ("it says ACCOUNT-WIDE in as many words",
         "Applies to every discussion you follow" in html),
        ("it ships HIDDEN, for the server to reveal",
         bool(tag) and re.search(r"\shidden(\s|>|=)", tag) is not None),
        ("there is a live region for the save outcome", 'id="lg-fol-freq-note"' in html),
    ]


def off_assertions(html):
    """What MUST be true of a surface where LG_FOLLOWING_CADENCE is OFF.

    This is the half that has been missing all weekend across this codebase.
    """
    return [
        ("NO frequency control", "lg-fol-freq" not in html),
        ("NO cadence options", "data-cadence" not in html),
        ("NO save-outcome region", "lg-fol-freq-note" not in html),
        ("NO account-wide copy leaks either",
         "Applies to every discussion you follow" not in html),
    ]


def run_set(title, assertions):
    log(title)
    for name, passed in assertions:
        ok(name) if passed else bad(name)


def browser_phase(args, cookies):
    """What the HTML alone cannot show: what the member's browser ENDS UP WITH.

    ⚠️ THIS IS THE STRONGEST ABSENCE ASSERTION AVAILABLE, and it needs no stub,
    because dev2's real state IS the interesting state: LG_FOLLOW_DIGEST_ENABLED is
    false in the tracked config, so lg_fd_cadence_state genuinely 404s and the JS
    must genuinely remove the node. Flag ON in the markup, control GONE in the DOM.
    That is "present in the HTML but absent to the member", which no fetch can see.

    ⚠️ AND IT NEEDS A BROWSER THAT RESOLVES dev2 INTERNALLY. chrome-dev.service now
    carries --host-resolver-rules=MAP dev2.loothgroup.com 172.31.78.94, so it reaches
    nginx directly. WITHOUT that, Chrome goes out through Cloudflare, gets a challenge
    page, finds no cadence control on it, and every absence assertion here PASSES
    having audited nothing. So the challenge page is detected and refused.
    """
    log("\n  [7] BROWSER — what the member's DOM actually ends up holding")
    try:
        import websocket
    except ImportError:
        bad("websocket-client not installed — browser phase could not run")
        return

    on_url = args.origin + ON_PATH
    try:
        req = urllib.request.Request(args.cdp + "/json/new?about:blank", method="PUT")
        tgt = json.loads(urllib.request.urlopen(req, timeout=10).read())
        ws = websocket.create_connection(tgt["webSocketDebuggerUrl"], timeout=30,
                                         suppress_origin=True)
    except Exception as e:
        bad("could not open a CDP target", str(e)[:140])
        return

    n = [0]

    def send(method, params=None):
        n[0] += 1
        ws.send(json.dumps({"id": n[0], "method": method, "params": params or {}}))
        while True:
            m = json.loads(ws.recv())
            if m.get("id") == n[0]:
                if "error" in m:
                    raise RuntimeError("%s: %s" % (method, m["error"]))
                return m.get("result", {})

    def ev(expr, awaitp=False):
        r = send("Runtime.evaluate", {"expression": expr, "returnByValue": True,
                                      "awaitPromise": awaitp})
        return r.get("result", {}).get("value")

    try:
        send("Page.enable")
        send("Runtime.enable")
        send("Network.enable")
        send("Security.setIgnoreCertificateErrors", {"ignore": True})
        # ⚠️ CLEAR FIRST, AND THE TWO COOKIE KINDS TAKE DIFFERENT DOMAINS. This cost
        # a FALSE GREEN, caught only by the liveness control below.
        #
        # chrome-dev runs on a SHARED profile that already holds WordPress session
        # cookies from whoever ran last. Those are HOST-ONLY (domain
        # "dev2.loothgroup.com"). Setting ours with a leading dot does not replace
        # them — it creates a SECOND cookie of the same name, both get sent, and the
        # server picks one. The run then executes as a different member: here, one
        # with no followed discussions, so the whole block (control AND "Stop all")
        # never rendered, and "the control is absent" came back TRUE for a reason
        # that had nothing to do with the flag or the endpoint.
        #
        # So: wipe the jar, then WP session cookies HOST-ONLY (no dot) exactly as
        # WordPress sets them, and the dev gate cookie WITH the leading dot, which is
        # the recorded trap in the other direction.
        send("Network.clearBrowserCookies")
        jar = []
        for c in cookies:
            k, v = c.split("=", 1)
            jar.append({"name": k, "value": v, "path": "/",
                        "domain": ".dev2.loothgroup.com" if k == "loothdev_auth"
                                  else "dev2.loothgroup.com"})
        send("Network.setCookies", {"cookies": jar})
        send("Emulation.setDeviceMetricsOverride",
             {"width": 1280, "height": 900, "deviceScaleFactor": 1, "mobile": False})
        send("Page.navigate", {"url": on_url})

        state = None
        for _ in range(70):
            time.sleep(0.4)
            state = ev("""(() => {
              if (!document.getElementById('lg-following')) return 'no-section';
              const b = document.getElementById('lg-fol-freq');
              if (!b) return 'removed';
              const cs = getComputedStyle(b);
              if (b.hidden || cs.display === 'none') return 'hidden';
              return 'VISIBLE';
            })()""")
            if state in ("removed", "VISIBLE"):
                break

        # LIVENESS FIRST. "No cadence control" is also true of a Cloudflare
        # challenge page, a 403, and a blank tab.
        title = ev("document.title || ''") or ""
        body_len = ev("(document.body && document.body.innerHTML.length) || 0") or 0
        if "just a moment" in title.lower() or "attention required" in title.lower():
            bad("the browser landed on a Cloudflare challenge page — every absence "
                "assertion here would pass having audited nothing",
                "title=%r; chrome-dev needs --host-resolver-rules" % title[:60])
            return
        if state == "no-section":
            bad("the following section never rendered — not a verdict about the "
                "cadence control", "title=%r len=%d" % (title[:50], body_len))
            return
        ok("the real page rendered in a real browser",
           "title=%r, %d bytes of body" % (title[:44], body_len))

        # ⚠️ THE BLOCK THE CONTROL LIVES IN MUST HAVE RENDERED AT ALL. The cadence
        # control sits beside "Stop all" inside a section that only renders for a
        # member who follows something. Run as a member with zero follows and the
        # control is absent for a reason that has nothing to do with the flag or the
        # endpoint — which is precisely the false green this phase produced once.
        rows = ev("document.querySelectorAll('.lg-manage-sub__fol-row').length") or 0
        stopall = ev("!!document.getElementById('lg-fol-stopall')")
        if not stopall:
            bad("this member follows nothing, so the block holding the control never "
                "rendered — 'the control is absent' would be true for the wrong "
                "reason", "%d rows; pick a --uid that follows discussions" % rows)
            return
        ok("the member has follows, so the block really rendered",
           "%d row(s), Stop all present — the control's absence below is about the "
           "control" % rows)

        # The assertion. The markup IS present (phase 2 proved it); the endpoint
        # refuses this member; therefore the member's DOM must not hold it.
        served = ev("""(async () => {
          const d = new URLSearchParams(); d.set('action','lg_fd_cadence_state');
          const r = await fetch('/wp-admin/admin-ajax.php', {method:'POST',
            credentials:'same-origin',
            headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:d.toString()});
          return r.status;
        })()""", awaitp=True)
        log("           lg_fd_cadence_state answered HTTP %s" % served)

        if state == "removed":
            ok("flag ON + endpoint refuses ⇒ the control is REMOVED FROM THE DOM",
               "present in the HTML, absent to the member — which no fetch can see")
        elif state == "hidden":
            ok("flag ON + endpoint refuses ⇒ the control is not displayed",
               "still in the DOM but computed display:none")
        elif state == "VISIBLE":
            bad("THE CONTROL IS VISIBLE TO A MEMBER THE SENDER WILL NOT SERVE — "
                "they can set a cadence, have their instant mail suppressed, and "
                "receive nothing at all")
        else:
            bad("could not determine the control's runtime state", repr(state))

        # ── THE CONTROL FOR THAT ABSENCE ─────────────────────────────────────
        # "The control was removed" is equally true of a page whose JavaScript never
        # ran, 404'd, or threw on line one. Under that reading the phase above would
        # report a serene green over a completely dead surface — the vacuous pass
        # that feedback-absence-assertion-needs-liveness describes.
        #
        # So: reload with ONLY the endpoint's answer stubbed to ok, and require the
        # control to APPEAR. If it appears, the JS is alive and the removal above was
        # genuinely caused by the refusal. If it does not, the earlier "removed"
        # proved nothing, and this goes red instead of green.
        #
        # The stub replaces one fetch response and nothing else — same markup, same
        # CSS, same script.
        STUB = """
        (() => { const real = window.fetch;
          window.fetch = function (input, init) {
            const u = (typeof input === 'string') ? input : (input && input.url) || '';
            const b = (init && init.body) ? String(init.body) : '';
            if (u.indexOf('admin-ajax.php') >= 0 && b.indexOf('lg_fd_cadence_state') >= 0) {
              return Promise.resolve(new Response(JSON.stringify({ok:true, cadence:'daily',
                options:['instant','daily','weekly'], nonce:'gate-stub'}),
                {status:200, headers:{'Content-Type':'application/json'}}));
            }
            return real.apply(this, arguments); }; })();
        """
        send("Page.addScriptToEvaluateOnNewDocument", {"source": STUB})
        send("Page.navigate", {"url": on_url})
        live = None
        for _ in range(70):
            time.sleep(0.4)
            live = ev("""(() => {
              const b = document.getElementById('lg-fol-freq');
              if (!b) return 'removed';
              if (b.hidden || getComputedStyle(b).display === 'none') return 'hidden';
              const opts = [...b.querySelectorAll('[data-cadence]')];
              const on = opts.filter(o => o.getAttribute('aria-checked') === 'true')
                             .map(o => o.getAttribute('data-cadence'));
              return 'VISIBLE:' + opts.length + ':' + on.join(',');
            })()""")
            if live and live.startswith("VISIBLE"):
                break
        if live and live.startswith("VISIBLE"):
            parts = live.split(":")
            ok("CONTROL — with the endpoint stubbed to ok, the control APPEARS",
               "%s options, %s selected — so the removal above was the REFUSAL, "
               "not dead javascript" % (parts[1], parts[2] or "none"))
            if parts[1] == "3":
                ok("…built from the endpoint's own options list", "3 pills")
            else:
                bad("the stub offered 3 options and the page built %s" % parts[1])
            if parts[2] == "daily":
                ok("…and painted from the STORED value the endpoint echoed", "daily")
            else:
                bad("painted %r, but the endpoint said 'daily'" % parts[2])
            # Reachability, not presence — twice Ian has reported a control that
            # was in the DOM the whole time and could not be pressed.
            hit = ev("""(() => {
              const b = document.querySelector('#lg-fol-freq [data-cadence="weekly"]');
              if (!b) return 'no-button';
              b.scrollIntoView({block:'center'});
              const r = b.getBoundingClientRect();
              const el = document.elementFromPoint(r.left + r.width/2, r.top + r.height/2);
              if (!el) return 'nothing-at-point';
              if (r.width < 24 || r.height < 24) return 'too-small:' + r.width + 'x' + r.height;
              return (b === el || b.contains(el)) ? 'REACHABLE'
                     : 'BLOCKED by ' + (el.id || el.className || el.tagName);
            })()""")
            ok("…and it is REACHABLE, hit-tested", str(hit)) if hit == "REACHABLE" \
                else bad("the control is present but NOT reachable", str(hit))
        else:
            bad("with the endpoint stubbed to ok the control STILL did not appear — "
                "so the 'removed' verdict above was vacuous: this page's javascript "
                "is not running at all", repr(live))

        # And the cascade, measured rather than inferred from the stylesheet text.
        probe = ev("""(() => {
          const d = document.createElement('div');
          d.className = 'lg-manage-sub__fol-freq';
          d.hidden = true;
          document.body.appendChild(d);
          const v = getComputedStyle(d).display;
          d.remove();
          return v;
        })()""")
        if probe == "none":
            ok("a hidden container computes to display:none", "measured in the browser")
        else:
            bad("`hidden` does NOT hide — computed display is %r, so the attribute is "
                "overridden by .lg-manage-sub__fol-freq{display:flex}" % probe)
    except Exception as e:
        bad("browser phase failed", str(e)[:160])
    finally:
        try:
            ws.close()
        except Exception:
            pass


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--origin", default=DEV2)
    ap.add_argument("--uid", type=int, default=1)
    ap.add_argument("--prove", action="store_true",
                    help="red-first: run each assertion set against the surface it "
                         "was written to REJECT, and require every one to fail")
    ap.add_argument("--cdp", default="",
                    help="CDP endpoint; adds the computed-style and node-removal phases")
    args = ap.parse_args()

    print("\n############ cadence-control gate ############")
    cookies = mint_cookies(args.uid)

    off_url = args.origin + OFF_PATH
    on_url = args.origin + ON_PATH
    off = fetch(off_url, cookies)
    on = fetch(on_url, cookies)

    # ── [0] LIVENESS ─────────────────────────────────────────────────────────
    # An absence assertion is vacuous without a liveness assertion: "no control
    # here" is equally true of a 403, a Cloudflare challenge and an empty file.
    log("\n  [0] both surfaces are real (else every absence below is vacuous)")
    if off is None or on is None:
        cannot_run("one of the two surfaces did not answer — is the lane preview up? "
                   "`sudo tools/preview/lane-preview.sh up account-following`")
    for label, html, url in (("flag-OFF", off, off_url), ("flag-ON", on, on_url)):
        if "lg-following" not in html:
            cannot_run("%s surface (%s) has no following section at all — a gate "
                       "asserting the control's absence there would pass on a blank "
                       "page. Is the member signed in and following anything?"
                       % (label, url))
        ok("%s surface answers and carries the following section" % label,
           "%d bytes" % len(html))
    if off == on:
        cannot_run("both URLs returned byte-identical HTML — the preview is not "
                   "serving the branch, so ON and OFF are the same surface and "
                   "nothing below can distinguish them")
    ok("the two surfaces genuinely differ", "%d vs %d bytes" % (len(off), len(on)))

    # ── [1] FLAG OFF ⇒ ABSENT ────────────────────────────────────────────────
    run_set("\n  [1] LG_FOLLOWING_CADENCE OFF ⇒ the control is ABSENT\n"
            "      (the assertion class every one of the six misses lived in)",
            off_assertions(off))
    ok("…and the following section itself is untouched", "lg-following still present")

    # ── [2] FLAG ON ⇒ PRESENT ────────────────────────────────────────────────
    run_set("\n  [2] LG_FOLLOWING_CADENCE ON ⇒ the control is PRESENT and correct",
            on_assertions(on))

    # ── [3] ON, BUT NOT SERVED, IS STILL ABSENT ──────────────────────────────
    # The strictest absence available here, and the one that matters most: the page
    # flag is ON, the markup IS in the HTML, and the member must STILL not get a
    # usable control because lg_fd_cadence_state refuses them. Everything after this
    # depends on the reveal being the server's decision, never the page's.
    log("\n  [3] flag ON but the sender does not serve this member ⇒ STILL hidden")
    tag = freq_tag(on)
    if not tag:
        bad("could not locate the container to check its hidden state")
    else:
        if re.search(r"\shidden(\s|>|=)", tag):
            ok("the container ships with the hidden attribute", tag[:90] + "…")
        else:
            bad("the container is NOT hidden in the served HTML", tag[:120])
        if 'data-state="pending"' in tag:
            ok("…and is marked pending until the endpoint answers")
        else:
            bad("no pending state — cannot tell 'not yet asked' from 'confirmed'")

    # ── [4] `hidden` ACTUALLY HIDES — the cascade trap ───────────────────────
    log("\n  [4] the hidden state SURVIVES THE CASCADE (asserted on the SERVED css)")
    cm = re.search(r'href="([^"]*manage-subscription\.css[^"]*)"', on)
    if not cm:
        bad("could not find the stylesheet link on the flag-ON surface")
    else:
        href = cm.group(1)
        css_url = href if href.startswith("http") else args.origin + href
        css = fetch(css_url, cookies)
        if css is None:
            bad("could not fetch the served stylesheet", css_url)
        else:
            ok("served stylesheet fetched", "%d bytes" % len(css))
            has_flex = re.search(r"\.lg-manage-sub__fol-freq\s*\{[^}]*display:\s*flex", css)
            has_hide = re.search(
                r"\.lg-manage-sub__fol-freq\[hidden\]\s*\{[^}]*display:\s*none", css)
            if has_flex and not has_hide:
                bad("display:flex WITHOUT a [hidden] override — `hidden` hides NOTHING "
                    "and the control paints for members the endpoint refuses")
            elif has_hide:
                ok("[hidden] override present — the attribute actually hides")
            else:
                ok("no display:flex on the container, so the UA [hidden] rule stands")

    # ── [5] ONE WRITE PATH, asserted on the SERVED javascript ────────────────
    # Not on the repo file: what matters is what the member's browser runs.
    log("\n  [5] ONE STORE, ONE WRITE PATH (asserted on the SERVED javascript)")
    jm = re.search(r'src="([^"]*manage-following\.js[^"]*)"', on)
    if not jm:
        bad("could not find manage-following.js on the flag-ON surface")
    else:
        jhref = jm.group(1)
        js_url = jhref if jhref.startswith("http") else args.origin + jhref
        js = fetch(js_url, cookies)
        if js is None:
            bad("could not fetch the served javascript", js_url)
        else:
            ok("served javascript fetched", "%d bytes" % len(js))
            code = strip_js_comments(js)
            ok("comments stripped before the write-path check",
               "%d bytes of code, %d of prose" % (len(code), len(js) - len(code)))
            checks = [
                ("reads through the sanctioned transport", "lg_fd_cadence_state" in code),
                ("writes through the sanctioned transport", "lg_fd_cadence_set" in code),
                # follow.php:212 writes the usermeta key raw and skips the flood
                # guard, which is a mail black hole (cadence-seam-proof.php arm B).
                # This page must never reach it.
                ("never POSTs a cadence to follow.php",
                 not re.search(r"cadence[^\n]{0,80}bb-mirror-api", code)
                 and not re.search(r"bb-mirror-api[^\n]{0,120}cadence", code)),
                ("no CODE names the usermeta key", "lg_disc_email_cadence" not in code),
                ("repaints from the STORED value the server echoes", "j.cadence" in code),
            ]
            for name, passed in checks:
                ok(name) if passed else bad(name)

    # ── [6] RED-FIRST: each set run against the surface it must reject ───────
    if args.prove:
        log("\n  [6] RED-FIRST — every assertion run against the surface it must REJECT")
        log("      (a predicate that passes here is measuring nothing)")
        inverted = 0
        for label, assertions in (("ON assertions vs the OFF surface", on_assertions(off)),
                                  ("OFF assertions vs the ON surface", off_assertions(on))):
            log("    %s" % label)
            for name, passed in assertions:
                if passed:
                    bad("STAYED GREEN when it should have failed: %s" % name)
                else:
                    inverted += 1
                    print("      red  %s" % name)
        ok("every assertion can fail", "%d predicate(s) inverted correctly" % inverted)

    # ── [7] optional: the browser phases ─────────────────────────────────────
    if args.cdp:
        browser_phase(args, cookies)

    print("\n" + "-" * 74)
    if FAIL:
        print("############ cadence-control gate RED — %d failure(s) ############"
              % len(FAIL))
        for f in FAIL:
            print("  · %s" % f)
        return 1
    print("############ cadence-control gate GREEN — %d assertion(s) ############"
          % len(OKS))
    print("  BOTH directions asserted: present when the flag is on, ABSENT when it")
    print("  is off, and still hidden when the flag is on but the sender would not")
    print("  serve this member.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
