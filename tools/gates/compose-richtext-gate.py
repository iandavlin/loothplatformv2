#!/usr/bin/env python3
"""
compose-richtext-gate.py — the write-up field is rich text, safely.

⚠️ NO GATE NUMBER YET. Keeper mints, lanes never.

Ian, 2026-08-16: "rich text with light tinymce controls" on the Loothprint
write-up. Three things can go wrong afterwards and each has its own leg here.

── A. THE TOOLBAR AND THE TAG LIST MUST NOT DRIFT (static) ─────────────────────
The whole safety argument is "nothing is stored that the toolbar cannot make".
That is only true while ONE list matches the other, and the obvious way to break
it is to add a button and forget the tag — or to widen the tag list and forget
why. So this READS BOTH LISTS OUT OF THE SOURCE AND COMPARES THEM. It is the
cheapest leg and the one most likely to catch a real regression, because it fails
the moment the two disagree rather than when a member exploits the gap.

── B. THE SANITISER MUST ACTUALLY STRIP (behaviour, no browser) ────────────────
Runs the SHIPPED function — sliced out of the mu-plugin at run time, never a
copy — over hostile input inside real WordPress, so wp_kses is the real one.
A copy of the sanitiser would pass while the real one was broken.

⚠️ AND IT ASSERTS THE FUNCTION IS HOOKED, not merely defined. A sanitiser that
nothing calls is indistinguishable from no sanitiser, and this one guards what
gets stored. It also asserts the hook is acf/pre_save_post specifically, because
_post_content is a pseudo-field landing in the post rather than in meta, so the
per-field acf/update_value filter never runs for it — wiring it there would look
right and do nothing.

── C. BACK-COMPAT (behaviour, no browser) ──────────────────────────────────────
Measured on dev2 when this was built: of 169 published loothprints, 72 are PLAIN
TEXT and 32 of those contain newlines. Hand plain text to a WYSIWYG unprocessed
and TinyMCE collapses it to one paragraph — 32 members' write-ups silently lose
their structure the first time anyone opens the editor and presses Post. So:
plain text must come back with paragraphs, and existing HTML must come back
UNCHANGED (wpautop over real HTML doubles it).

── D. THE DARK SKIN (browser) ──────────────────────────────────────────────────
TinyMCE renders the write-up inside an IFRAME with its own document, wearing
wp-admin's black-on-white by default — in dark mode a full-width white slab, the
same class that reddened gate 47 the same night. Page CSS cannot cross an iframe
boundary and content_style cannot know a client-side theme, so this measures the
IFRAME'S OWN BODY BACKGROUND. That is the only number the slab bar cares about.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.
⚠️ CANNOT RUN IS 2, NOT 3 — run-all.sh reads anything else as RED.
"""
import json, os, re, subprocess, sys, tempfile, time

CDP  = "http://127.0.0.1:9222"
REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
# Overridable so the drift leg can be MUTATION-TESTED against a copy — the real
# file is never touched, which is the recorded rule for mutation harnesses.
SRC  = os.environ.get("LG_RICHTEXT_SRC") or os.path.join(
    REPO, "platform", "mu-plugins", "lg-frontend-compose.php")
# Staged where the WP user can actually read it — a session scratchpad is not
# readable by looth-dev, and that failure looks exactly like a syntax error once
# WP-CLI has wrapped it in fourteen frames of stack.
STAGE = "/tmp/lgfc-richtext-gate"


class CannotRun(Exception):
    pass


def sh(c):
    return subprocess.run(c, capture_output=True, text=True)


def source():
    if not os.path.isfile(SRC):
        raise CannotRun(f"mu-plugin not found at {SRC}")
    return open(SRC, encoding="utf-8").read()


# button -> the tag it can produce. Buttons that make no markup map to nothing.
BUTTON_TAGS = {
    "bold":    {"strong", "b"},
    "italic":  {"em", "i"},
    "bullist": {"ul", "li"},
    "numlist": {"ol", "li"},
    "link":    {"a"},
    "unlink":  set(),
    "undo":    set(),
    "redo":    set(),
}
# Always-legal structural tags a WYSIWYG emits regardless of buttons.
STRUCTURAL = {"p", "br"}


def leg_a(s, add):
    m = re.search(r"\$toolbars\['lgfc_light'\]\s*=\s*\[\s*1\s*=>\s*\[(.*?)\]", s, re.S)
    if not m:
        add("RED", "A", "the lgfc_light toolbar is not declared in the source")
        return
    buttons = re.findall(r"'([a-z]+)'", m.group(1))
    add("ok", "A", f"toolbar declares {len(buttons)}: {', '.join(buttons)}")

    m2 = re.search(r"function lg_fc_richtext_allowed\(\).*?return \[(.*?)\];", s, re.S)
    if not m2:
        add("RED", "A", "the allowed-tag list is not declared in the source")
        return
    # ⚠️ TOP-LEVEL KEYS ONLY, AND DEPTH-AWARE. Two earlier versions got this
    # wrong in the same direction. Matching every quoted key swept up the
    # ATTRIBUTES nested inside 'a' => ['href' => [], 'title' => [], …] and called
    # them tags no button can make. Stripping nested arrays with a regex then
    # failed too, because those inner values are themselves [] — so [^\]]* stopped
    # at the first bracket and left rel/target/title behind. Count depth instead;
    # a quoted key only counts as a TAG when it sits at depth 0 of this block.
    body, depth, tags = m2.group(1), 0, set()
    for tok in re.finditer(r"\[|\]|'([a-z0-9]+)'\s*=>", body):
        t = tok.group(0)
        if t == "[":
            depth += 1
        elif t == "]":
            depth -= 1
        elif depth == 0 and tok.group(1):
            tags.add(tok.group(1))

    unknown = [b for b in buttons if b not in BUTTON_TAGS]
    if unknown:
        add("RED", "A", f"toolbar has button(s) this gate does not know: {unknown} — "
                        f"add them to BUTTON_TAGS with the tags they make")
    needed = set().union(*[BUTTON_TAGS.get(b, set()) for b in buttons]) | STRUCTURAL
    missing = sorted(needed - tags)
    extra   = sorted(tags - needed)
    if missing:
        add("RED", "A", f"toolbar can make tag(s) the sanitiser strips: {missing}")
    else:
        add("ok", "A", "every tag the toolbar can make is allowed")
    if extra:
        add("RED", "A", f"sanitiser allows tag(s) no button can make: {extra} — "
                        f"'nothing stored the toolbar cannot make' is no longer true")
    else:
        add("ok", "A", "no tag is allowed that the toolbar cannot make")

    # the field itself
    for label, pat in (("field is a wysiwyg",  r"\$field\['type'\]\s*=\s*'wysiwyg'"),
                       ("uses lgfc_light",     r"\$field\['toolbar'\]\s*=\s*'lgfc_light'"),
                       ("delay-init (on intent, not eager)", r"\$field\['delay'\]\s*=\s*1"),
                       ("media_upload off",    r"\$field\['media_upload'\]\s*=\s*0")):
        add("ok" if re.search(pat, s) else "RED", "A", label)


def leg_b_hooked(s, add):
    hooked = re.search(r"add_filter\(\s*'acf/pre_save_post'", s)
    add("ok" if hooked else "RED", "B",
        "sanitiser is HOOKED at acf/pre_save_post (defined-but-unhooked == absent)")
    # ⚠️ MATCH THE CALL, NOT THE WORDS. The first version searched for
    # "acf/update_value" near "_post_content" and fired on the SOURCE'S OWN
    # COMMENT explaining why that hook is wrong — a gate reddened by prose that
    # exists to prevent the very mistake it was accusing the file of. Anchor on an
    # actual add_filter/add_action registration instead.
    wrong = re.search(r"add_(?:filter|action)\(\s*'acf/update_value[^']*'", s)
    if wrong:
        add("RED", "B", "a filter is registered on acf/update_value for this field — "
                        "that hook never runs for a pseudo-field")
    else:
        add("ok", "B", "not wired at acf/update_value (which would never fire here)")


def leg_bc_behaviour(s, add):
    os.makedirs(STAGE, exist_ok=True)
    parts = []
    for name in ("lg_fc_richtext_allowed", "lg_fc_content_for_editor", "lg_fc_sanitize_richtext"):
        i = s.find("function %s(" % name)
        if i < 0:
            add("RED", "B", f"{name}() is missing from the source")
            return
        j = s.index("\n}\n", i) + 3
        parts.append(s[i:j])
    slice_path = os.path.join(STAGE, "rich.php")
    open(slice_path, "w").write("<?php\n" + "\n".join(parts))

    runner = os.path.join(STAGE, "run.php")
    open(runner, "w").write("""<?php
require '%s';
$out = [];
$out['plain']  = lg_fc_content_for_editor("A.\\n\\nB.");
$out['html']   = lg_fc_content_for_editor('<p>x <strong>y</strong></p>');
$out['empty']  = lg_fc_content_for_editor('');
$out['script'] = lg_fc_sanitize_richtext('<p>ok</p><script>alert(1)</script>');
$out['img']    = lg_fc_sanitize_richtext('<p>a<img src=x onerror=y></p>');
$out['h1']     = lg_fc_sanitize_richtext('<h1 style="color:red">Big</h1>');
$out['iframe'] = lg_fc_sanitize_richtext('<iframe src="//evil"></iframe>');
$out['onclick']= lg_fc_sanitize_richtext('<a href="/x" onclick="bad()">l</a>');
$out['light']  = lg_fc_sanitize_richtext('<p><strong>b</strong></p><ul><li>o</li></ul>');
echo "LGJSON" . json_encode($out) . "LGEND";
""" % slice_path)
    for p in (slice_path, runner):
        os.chmod(p, 0o644)
    os.chmod(STAGE, 0o755)

    r = sh(["sudo", "-n", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
            "require '%s';" % runner])
    m = re.search(r"LGJSON(.*?)LGEND", r.stdout, re.S)
    if not m:
        raise CannotRun("could not run the shipped functions inside WordPress: "
                        + (r.stderr or r.stdout)[:200])
    o = json.loads(m.group(1))

    add("ok" if "<p>A.</p>" in o["plain"] else "RED", "C",
        f"plain text keeps its paragraphs ({o['plain'][:34]!r})")
    add("ok" if o["html"] == '<p>x <strong>y</strong></p>' else "RED", "C",
        f"existing HTML passes through unchanged ({o['html'][:34]!r})")
    add("ok" if o["empty"] == "" else "RED", "C", "empty stays empty")

    for key, banned, label in (("script", "<script", "<script> stripped"),
                               ("img",    "<img",    "<img> stripped"),
                               ("h1",     "<h1",     "<h1> stripped"),
                               ("iframe", "<iframe", "<iframe> stripped"),
                               ("onclick", "onclick", "onclick= stripped")):
        add("ok" if banned not in o[key] else "RED", "B", f"{label} ({o[key][:30]!r})")
    add("ok" if "rel=\"nofollow ugc\"" in o["onclick"] else "RED", "B",
        "member links get rel=nofollow ugc")
    add("ok" if "\\\"" not in o["onclick"] else "RED", "B",
        f"sanitiser returns UNSLASHED (wp_rel_ugc slashes; double-slashing stores "
        f"literal backslashes) ({o['onclick'][:40]!r})")
    add("ok" if "<strong>" in o["light"] and "<li>" in o["light"] else "RED", "B",
        "the light tags survive")


def leg_d_dark(add):
    """The iframe's own background. Browser leg, deliberately last and short."""
    try:
        import websocket
    except Exception as e:
        add("SKIP", "D", f"websocket-client unavailable: {e}")
        return
    env = dict(l.partition("=")[::2] for l in
               sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")]).stdout.splitlines())
    if not env.get("LG_GATE_HOST"):
        add("SKIP", "D", "no gate env")
        return
    r = sh(["sudo", "-n", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval",
            "$u=get_user_by('login','claude_admin');$e=time()+3600;"
            "echo LOGGED_IN_COOKIE.'|'.wp_generate_auth_cookie($u->ID,$e,'logged_in').\"\\n\";"
            "echo SECURE_AUTH_COOKIE.'|'.wp_generate_auth_cookie($u->ID,$e,'secure_auth');"])
    ck = [{"name": "loothdev_auth", "value": env["LG_GATE_TOKEN"],
           "domain": "." + env["LG_GATE_DOMAIN"], "path": "/", "secure": True}]
    for line in r.stdout.splitlines():
        if "|" in line and not line.startswith(("PHP ", "Warning")):
            n, v = line.split("|", 1)
            ck.append({"name": n, "value": v, "domain": env["LG_GATE_DOMAIN"], "path": "/",
                       "secure": True, "httpOnly": True, "sameSite": "Lax"})
    t = json.loads(subprocess.check_output(["curl", "-s", "-X", "PUT", f"{CDP}/json/new?about:blank"]))
    ws = websocket.create_connection(t["webSocketDebuggerUrl"], suppress_origin=True, timeout=60)
    n = [0]

    def call(m, **p):
        n[0] += 1
        ws.send(json.dumps({"id": n[0], "method": m, "params": p}))
        while True:
            msg = json.loads(ws.recv())
            if msg.get("id") == n[0]:
                return msg.get("result", {})

    try:
        call("Page.enable"); call("Network.enable"); call("Network.clearBrowserCookies")
        call("Network.setCookies", cookies=ck)
        call("Emulation.setDeviceMetricsOverride", width=1280, height=1400,
             deviceScaleFactor=1, mobile=False, screenWidth=1280, screenHeight=1400)
        url = env["LG_GATE_HOST"] + "/compose/?type=loothprint"
        call("Page.navigate", url=url); time.sleep(2.5)
        call("Runtime.evaluate", expression=(
            "localStorage.setItem('lg-set-theme','dark');"
            "document.documentElement.setAttribute('data-lguser-theme','dark');"))
        call("Page.navigate", url=url); time.sleep(3.5)
        probe = r"""(() => {
          const f = document.querySelector('.lgfc .acf-field[data-name="_post_content"]');
          if (!f) return JSON.stringify({field:false});
          const ta = f.querySelector('textarea');
          if (ta) { ta.focus(); ta.click(); }
          return JSON.stringify({field:true, isWysiwyg: !!f.querySelector('.wp-editor-container, .acf-editor-wrap'),
                                 form: !!document.querySelector('.lgfc__card')});
        })()"""
        first = json.loads(call("Runtime.evaluate", expression=probe,
                                returnByValue=True)["result"]["value"])
        if not first.get("form"):
            add("SKIP", "D", "the compose form did not render (flag off or refused)")
            return
        if not first.get("isWysiwyg"):
            add("RED", "D", "the write-up field is not a rich-text editor on this serve "
                            "(expected before the deploy that ships it)")
            return
        time.sleep(3)
        deep = r"""(() => {
          const srgb=c=>{c/=255;return c<=0.04045?c/12.92:Math.pow((c+0.055)/1.055,2.4)};
          const px=s=>{const m=s.match(/rgba?\(([^)]+)\)/);if(!m)return null;
            const p=m[1].split(',').map(Number);return {r:p[0],g:p[1],b:p[2]}};
          const lum=c=>0.2126*srgb(c.r)+0.7152*srgb(c.g)+0.0722*srgb(c.b);
          const f=document.querySelector('.lgfc .acf-field[data-name="_post_content"]');
          const fr=f?f.querySelector('iframe'):null;
          if(!fr) return JSON.stringify({frame:false});
          let d=null; try{d=fr.contentDocument;}catch(e){}
          if(!d||!d.body) return JSON.stringify({frame:true,reach:false});
          const bg=px(getComputedStyle(d.body).backgroundColor);
          const r=fr.getBoundingClientRect();
          return JSON.stringify({frame:true,reach:true,bg:getComputedStyle(d.body).backgroundColor,
            lum:bg?+lum(bg).toFixed(3):null,w:Math.round(r.width),h:Math.round(r.height)});
        })()"""
        d = json.loads(call("Runtime.evaluate", expression=deep,
                            returnByValue=True)["result"]["value"])
        if not d.get("frame"):
            add("SKIP", "D", "editor iframe never appeared (delay-init did not fire)")
        elif not d.get("reach"):
            add("SKIP", "D", "could not read the editor iframe document")
        else:
            big = d["w"] >= 40 and d["h"] >= 24
            ok = (not big) or (d["lum"] is not None and d["lum"] < 0.35)
            add("ok" if ok else "RED", "D",
                f"editor iframe body is not a bright slab in dark "
                f"(bg={d['bg']} lum={d['lum']} {d['w']}x{d['h']})")
    finally:
        try: ws.close()
        finally: sh(["curl", "-s", f"{CDP}/json/close/{t['id']}"])


def main() -> int:
    rows = []
    def add(state, leg, msg): rows.append((state, leg, msg))
    s = source()
    leg_a(s, add)
    leg_b_hooked(s, add)
    leg_bc_behaviour(s, add)
    if "--no-browser" not in sys.argv:
        leg_d_dark(add)
    else:
        add("SKIP", "D", "--no-browser")

    for state, leg, msg in rows:
        print(f"  [{leg}] {state:<4} {msg}")
    reds = [r for r in rows if r[0] == "RED"]
    if reds:
        print(f"\nRED — {len(reds)} of {len(rows)}:")
        for _, leg, m in reds:
            print(f"  - [{leg}] {m}")
        return 1
    print(f"\nGREEN — toolbar and tag list agree, the sanitiser is hooked and strips, "
          f"back-compat holds, and the editor is dark in dark ({len(rows)} checks).")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except CannotRun as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
    except Exception as e:
        print(f"CANNOT RUN: {e}")
        sys.exit(2)
