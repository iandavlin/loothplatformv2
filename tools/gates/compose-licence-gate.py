#!/usr/bin/env python3
"""
compose-licence-gate.py — the licence a member picks is described correctly, and
the ⓘ shows the terms of the one they actually picked.

⚠️ NO GATE NUMBER YET — keeper mints, lanes never. (#191; 90 was the highest in
run-all.sh when this was written, and two lanes have already collided by minting
their own.)

WHY IT EXISTS. One of the four Creative Commons options on the Loothprint compose
form described a licence that does not exist:

    BY ND NC (Credit given to creator, No Derivatives,
              Adaptations shared with same terms)

"No Derivatives" and "adaptations shared with same terms" contradict each other —
the second clause belongs to Share-Alike. Members were choosing legal terms off
that sentence, and three published loothprints stored it.

That is not a typo class. It is "a legal description drifts from the licence it
names", and the only reason it was ever found is that a human read it. A gate is
what makes the second occurrence cheap.

WHAT IT ASSERTS, and why each leg is here rather than the obvious cheaper version

  §Z  LIVENESS AND PROVENANCE, before anything.
      A locked-out browser on this box serves a styled 403 that is identical in
      both themes at every width — a whole visual suite has gone green against
      one. And "the serve only carries merged code" means a gate pointed at
      /compose/ measures MAIN. So this asserts the form arrived AND that the file
      under test is the branch's, by ReflectionFunction.

  §A  THE SERVED BYTES, no browser. Four choices, the contradictory string
      absent, the corrected one present, the nudge gone.
      ⚠️ A6 IS THE COUPLING THAT STOPS §C BEING VACUOUS: every radio value must
      have a template. Without it, "the modal follows the selection" could pass
      by opening the same panel every time — three of the four never checked.

  §B  THE SHIPPED FUNCTIONS, no browser. The forward map, and — the leg with the
      most value per line — that each licence's legal FILE is the licence it
      claims. A mis-wired filename is invisible on screen (it is nineteen
      thousand words of plausible legalese) and would hand a member the wrong
      contract. Asserted against the text's own first line, and against the
      checksums in platform/licences/README.md, so "verbatim" stays checkable.

  §C  THE BROWSER LEG, and the reason the gate exists: SELECT EACH OF THE FOUR IN
      TURN and prove the ⓘ opens THAT one. Then Escape closes it and focus is
      back on the ⓘ.

  §D  BOTH THEMES, asserted as a DELTA. Stamping data-lguser-theme alone
      photographs a light page wearing a dark attribute: dark on this platform is
      app-settings.js re-pointing the --lg-* tokens as inline style on <html>.
      The palette is READ OUT of app-settings.js, never copied here, and
      lg-set-theme is never written to localStorage — that key persists on the
      SHARED chrome profile and takes every other lane's browser dark.

  §F  THE TWO LICENCE TABLES AGREE. lg_fc_licences() decides what gets STORED;
      Licenses::ACF_CHOICES decides what the layout engine RECOGNISES. They
      cannot be merged (an mu-plugin must not depend on a plugin class), so the
      agreement is gated instead. This leg exists because #191 BROKE it: fixing
      the wording made the engine's exact-match recogniser stop matching every
      post saved afterwards, silently.

  §E  IT READS THE FLAG, it does not hardcode a state. With the flag off the
      route returns before anything is registered, so there is no ⓘ, no dialog
      and no licence CSS. That state is free to test: lg_fc_enabled() resolves
      its config relative to the mu-plugin FILE, and the `enabled => true`
      override is a gitignored .local.php that exists only in the serving
      checkout — so a mirror pointed at a worktree reads the tracked default.

MEASURED AS A REAL MEMBER. member_cookies() in loothprint-paywall-gate.py mints
qa-disposable, which is an ADMINISTRATOR + bbp_keymaster; a gate copying it is
measuring the admin path. This mints a PID-KEYED looth1 and deletes it in §Z-end,
because one fixed test account produces false REDs the moment two gates run at
once.

RUN:  python3 tools/gates/compose-licence-gate.py
      LG191_KEEP=1   leave the probe user and the preview in place for debugging
"""

import base64, io, json, os, re, subprocess, sys, time

try:
    import websocket  # websocket-client
except Exception as e:  # pragma: no cover
    print(f"CANNOT RUN: websocket-client unavailable: {e}")
    sys.exit(2)

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
CDP  = "http://127.0.0.1:9222"
TAG  = str(os.getpid())
LOGIN = "lg191probe-" + TAG
MIRROR = f"/home/ubuntu/.lg-preview/191-licence-modal-gate-{TAG}/mu"
MUFILE = os.path.join(REPO, "platform", "mu-plugins", "lg-frontend-compose.php")

LEGACY = ("BY ND NC (Credit given to creator, No Derivatives, "
          "Adaptations shared with same terms)")
FIXED  = ("BY NC ND (Credit given to creator, Non-Commercial only, "
          "No Derivatives)")
NUDGE  = "leave it unless you know you want something else"
DESC   = "How other people may use your print files and photos."

fails, checks = [], [0]


def ok(cond, label, detail=""):
    checks[0] += 1
    if not cond:
        fails.append(f"{label}" + (f"  — {detail}" if detail else ""))
    return bool(cond)


class CannotRun(Exception):
    pass


def sh(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, **kw)


def gate_env():
    r = sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")])
    if r.returncode != 0:
        raise CannotRun("gate-env.sh failed: " + r.stderr.strip()[:200])
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines())


# ── running the BRANCH's mu-plugin, under real WordPress ─────────────────────
#
# ⚠️ THE MIRROR IS ASSERTED, NOT ASSUMED. A gate that cannot say which file it
# measured has measured main — and main is what /compose/ serves, so the failure
# is silent and looks like success.
def build_mirror():
    r = sh(["bash", os.path.join(REPO, "tools", "preview", "mu-mirror.sh"), MUFILE, MIRROR])
    if r.returncode != 0:
        raise CannotRun("mu-mirror.sh failed: " + (r.stderr or r.stdout).strip()[:300])
    boot = f"/tmp/lg191-gate-boot-{TAG}.php"
    with io.open(boot, "w", encoding="utf-8") as fh:
        fh.write("<?php\ndefine('WPMU_PLUGIN_DIR', %r);\n" % MIRROR)
    os.chmod(boot, 0o644)
    return boot


# ⚠️ THE HTTP LEGS DO NOT GO THROUGH THE MIRROR ABOVE, and pretending otherwise
# would be exactly the failure this gate warns about. nginx names its own mirror
# in a fastcgi_param, because a fastcgi_param is the only thing a visitor cannot
# set — so the PID-keyed mirror serves the wp-cli legs and THIS one serves the
# browser. Both are read out of the conf rather than spelled here, and the
# swapped symlink is ASSERTED, so "which file did the browser measure" has an
# answer instead of an assumption.
def http_preview():
    conf = os.path.join(REPO, "platform", "nginx", "lane-preview-191-licence-modal.conf")
    src = io.open(conf, encoding="utf-8").read()
    m = re.search(r"^\s*fastcgi_param\s+LG_MU_MIRROR\s+(\S+);", src, re.M)
    u = re.search(r"^#\s+(https://\S+/preview/\S+)", src, re.M)
    if not m or not u:
        raise CannotRun(f"could not read the mirror path / preview URL out of {conf}")
    mirror, url = m.group(1), u.group(1)
    link = os.path.join(mirror, os.path.basename(MUFILE))
    # Rebuilt only when it is not already this branch — mu-mirror.sh starts with
    # rm -rf, and doing that unconditionally would yank the directory out from
    # under a concurrent run's in-flight request.
    if os.path.realpath(link) != MUFILE:
        r = sh(["bash", os.path.join(REPO, "tools", "preview", "mu-mirror.sh"), MUFILE, mirror])
        if r.returncode != 0:
            raise CannotRun("could not build the HTTP preview mirror: "
                            + (r.stderr or r.stdout).strip()[:300])
    return mirror, link, url


def wp(boot, php, flag_on=True):
    """Run PHP inside real WordPress with the branch's mu-plugin loaded.

    sudo strips the environment, so LG_FC_PREVIEW goes through `env` — a
    recorded trap that has silently exercised the OFF path inside a flag-ON run.
    """
    path = f"/tmp/lg191-gate-{TAG}.php"
    with io.open(path, "w", encoding="utf-8") as fh:
        fh.write("<?php\n" + php)
    os.chmod(path, 0o644)
    cmd = ["sudo", "-n", "-u", "looth-dev", "env"]
    if flag_on:
        cmd.append("LG_FC_PREVIEW=1")
    cmd += ["wp", "--path=/var/www/dev", f"--require={boot}", "eval-file", path]
    r = sh(cmd)
    out = "\n".join(l for l in r.stdout.splitlines()
                    if not l.startswith(("PHP Warning", "PHP Notice", "Warning:")))
    return out.strip(), r


# ── the probe member ─────────────────────────────────────────────────────────
def probe_make():
    php = f"""
$login = '{LOGIN}';
$uid = username_exists($login);
if (!$uid) {{
    $uid = wp_insert_user([
        'user_login' => $login,
        'user_pass'  => wp_generate_password(24),
        'user_email' => $login . '@example.invalid',
        'role'       => 'looth1',
    ]);
}}
if (is_wp_error($uid)) {{ echo 'ERR ' . $uid->get_error_message(); exit; }}
$uid = (int) $uid;
$exp = time() + 3600;
$tok = WP_Session_Tokens::get_instance($uid)->create($exp);
echo json_encode([
  'uid' => $uid, 'roles' => get_userdata($uid)->roles,
  'cookie' => LOGGED_IN_COOKIE,
  'value'  => wp_generate_auth_cookie($uid, $exp, 'logged_in', $tok),
]);
"""
    out, _ = wp(BOOT, php)
    try:
        return json.loads(out.splitlines()[-1])
    except Exception:
        raise CannotRun("could not mint the probe member: " + out[:300])


def probe_kill():
    out, _ = wp(BOOT, f"""
$uid = username_exists('{LOGIN}');
if ($uid) {{ require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) $uid); }}
echo username_exists('{LOGIN}') ? 'STILL THERE' : 'GONE';
""")
    return out.strip().endswith("GONE")


# ── CDP ──────────────────────────────────────────────────────────────────────
class Tab:
    def __init__(self):
        try:
            t = json.loads(subprocess.check_output(
                ["curl", "-s", "-X", "PUT", f"{CDP}/json/new?about:blank"]))
        except Exception as e:
            raise CannotRun(f"no CDP browser on {CDP}: {e}")
        self.id = t["id"]
        self.ws = websocket.create_connection(t["webSocketDebuggerUrl"],
                                              suppress_origin=True, timeout=60)
        self.n = 0

    def call(self, method, **params):
        self.n += 1
        self.ws.send(json.dumps({"id": self.n, "method": method, "params": params}))
        while True:
            m = json.loads(self.ws.recv())
            if m.get("id") == self.n:
                if "error" in m:
                    raise CannotRun(f"{method}: {m['error']}")
                return m.get("result", {})

    def js(self, expr):
        r = self.call("Runtime.evaluate", expression=expr, returnByValue=True,
                      awaitPromise=True)
        if "exceptionDetails" in r:
            raise CannotRun("JS threw: " + json.dumps(r["exceptionDetails"])[:300])
        return r.get("result", {}).get("value")

    def close(self):
        try:
            self.ws.close()
        finally:
            sh(["curl", "-s", f"{CDP}/json/close/{self.id}"])


def dark_vars():
    """The dark palette, READ OUT of app-settings.js.

    Copying the values here would let a retuned palette leave this gate asserting
    last month's dark, which is the failure that makes a theme leg decoration.
    """
    src = io.open(os.path.join(REPO, "webroot/app-settings.js"), encoding="utf-8").read()
    m = re.search(r"id:\s*'dark'.*?vars:\s*\{(.*?)\}\s*\}", src, re.S)
    if not m:
        raise CannotRun("could not read the dark palette out of webroot/app-settings.js")
    pairs = re.findall(r"'(--[a-z0-9-]+)'\s*:\s*'([^']+)'", m.group(1))
    if not pairs:
        raise CannotRun("the dark palette parsed to nothing")
    return pairs


# ═════════════════════════════════════════════════════════════════════ main ══
def main() -> int:
    global BOOT
    env  = gate_env()
    dom  = env["LG_GATE_DOMAIN"]
    tokn = env["LG_GATE_TOKEN"]
    BOOT = build_mirror()
    _httpmirror, httplink, url = http_preview()

    # ── §Z  provenance ───────────────────────────────────────────────────────
    out, _ = wp(BOOT, """
$r = new ReflectionFunction('lg_fc_licences');
echo $r->getFileName();
""")
    ok(out.strip() == MUFILE, "Z1 the wp-cli legs load THIS BRANCH's mu-plugin",
       f"loaded {out.strip() or '(nothing)'}")
    ok(os.path.realpath(httplink) == MUFILE,
       "Z1b …and so does the mirror nginx serves the BROWSER from",
       f"{httplink} -> {os.path.realpath(httplink)}")

    who = probe_make()
    ok("looth1" in (who.get("roles") or []) and "administrator" not in (who.get("roles") or []),
       "Z2 the probe is a REAL member, not an administrator", str(who.get("roles")))

    # ── §A  the served bytes ─────────────────────────────────────────────────
    # ⚠️ The dev-gate cookie needs a LEADING DOT on this box (recorded trap); the
    # WP session cookie is host-only.
    ck = f"{who['cookie']}={who['value']}; loothdev_auth={tokn}"
    r = sh(["curl", "-sS", "--resolve", f"{dom}:443:{env['LG_GATE_ADDR']}",
            "-H", f"Cookie: {ck}", "-w", "\n__HTTP__%{http_code}", url])
    html = r.stdout
    code = html.rpartition("__HTTP__")[2].strip()
    html = html.rpartition("\n__HTTP__")[0]

    ok(code == "200", "A0 LIVENESS — the preview answered 200", f"got {code}")
    ok('acf-form lgfc__form' in html and 'lgfc__card' in html,
       "A1 LIVENESS — the compose FORM arrived (not a styled 403)")

    m = re.search(r'<div class="acf-field[^"]*"\s+data-name="loothprint_creative_commons".*?</div>\s*</div>',
                  html, re.S)
    field = m.group(0) if m else ""
    ok(bool(field), "A2 the licence field is on the page")

    values = re.findall(r'<input type="radio"[^>]*value="([^"]*)"', field)
    ok(len(values) == 4, "A3 the licence offers exactly four choices",
       f"found {len(values)}: {values}")
    ok(LEGACY not in html, "A4 the CONTRADICTORY label is gone from the whole page")
    ok(FIXED in values, "A5 the corrected BY-NC-ND option is offered",
       f"values were {values}")
    ok(NUDGE not in html, "A6 the nudge sentence is gone")
    ok(DESC in field, "A7 the licence field describes itself instead")

    ok(field.count('id="lgfc-lic-i"') == 1 and 'class="acf-label"' in field,
       "A8 exactly one ⓘ, inside the licence field")
    ok('aria-haspopup="dialog"' in field and 'aria-controls="lgfc-lic"' in field,
       "A9 the ⓘ announces itself as opening a dialog")
    # ⚠️ LOAD-BEARING, not tidiness. The ⓘ is inside the field's <label>, which is
    # inside the compose <form> — a <button> with no type is a SUBMIT button, so
    # dropping this attribute turns "read the licence" into "publish the
    # half-finished loothprint". The dialog itself is outside the form (A11); the
    # button cannot be.
    ok(re.search(r'<button type="button"[^>]*id="lgfc-lic-i"', field) is not None,
       "A9b the ⓘ is type=button — inside the form, anything else SUBMITS it")

    ok(html.count('<dialog id="lgfc-lic"') == 1, "A10 exactly one licence dialog")
    fend = html.find("</form>")
    ok(fend > 0 and html.find('<dialog id="lgfc-lic"') > fend,
       "A11 the dialog is OUTSIDE the form — no control in it can submit a post")

    tpl_values = re.findall(r'class="lgfc-lic__tpl" data-lic="([^"]*)"', html)
    tpl_values = [v.replace("&amp;", "&").replace("&quot;", '"').replace("&#039;", "'")
                  for v in tpl_values]
    ok(len(tpl_values) == 4, "A12 four licence templates ship with the page",
       f"found {len(tpl_values)}")
    # ⚠️ THE COUPLING. Without this, §C could pass while three of the four
    # choices silently opened the same panel.
    missing = [v for v in values if v not in tpl_values]
    ok(not missing, "A13 EVERY offered choice has its own template",
       f"no template for: {missing}")

    # ⚠️ "OFFLINE" IS ABOUT REQUESTS, NOT ABOUT WORDS. The first version of this
    # leg looked for the string "creativecommons.org" and went RED on a correct
    # build: the legal code names that host in its own prose ("creativecommons.org
    # /policies"), and esc_html renders it as text that fetches nothing. The rule
    # Ian actually gave is "do not send a member to another site mid-form", so
    # what must be absent is anything that LOADS or NAVIGATES.
    # Bounded to the templates + the dialog, and no further: the page's own
    # footer chrome below it is full of <script> and <link>, and a region that
    # ran to the end of the document made this leg red on a correct build.
    t0 = html.find('<div class="lgfc-lic" hidden>')
    t1 = html.find("</dialog>", t0)
    tail = html[t0:t1 + 9] if t0 >= 0 and t1 > t0 else ""
    ok(len(tail) > 60000, "A13b the licence region was located and is the size of "
       "four legal codes", f"{len(tail)} bytes")
    offenders = (re.findall(r'<(?:img|iframe|script|link|source|object|embed)\b', tail)
                 + re.findall(r'\s(?:src|href|action|poster|formaction)="', tail))
    ok(not offenders,
       "A14 the licence text is genuinely OFFLINE — the modal loads nothing and "
       "links nowhere", f"found {sorted(set(offenders))}")
    ok("creativecommons.org" in tail,
       "A15 …and it really is the CC legal code, which names that host in its own "
       "PROSE — the pair is what tells 'held offline' from 'not there at all'")

    # ── §B  the shipped functions and the files ──────────────────────────────
    php = """
$r = ['forward_legacy' => lg_fc_licence_forward(%r),
      'forward_known'  => lg_fc_licence_forward('BY (Credit given to creator)'),
      'forward_junk'   => lg_fc_licence_forward('a licence nobody offered'),
      'forward_empty'  => lg_fc_licence_forward(''),
      'lics' => []];
foreach (lg_fc_licences() as $l) {
    $t = lg_fc_licence_legal($l);
    $r['lics'][] = ['short' => $l['short'], 'value' => $l['value'], 'file' => $l['legal'],
                    'bytes' => strlen($t),
                    'first' => trim(strtok($t, "\\n")),
                    'sha'   => hash('sha256', $t)];
}
echo json_encode($r);
""" % LEGACY
    out, _ = wp(BOOT, php)
    try:
        b = json.loads(out.splitlines()[-1])
    except Exception:
        raise CannotRun("§B probe produced no JSON: " + out[:300])

    ok(b["forward_legacy"] == FIXED,
       "B1 a stored LEGACY value forwards to the corrected option",
       f"got {b['forward_legacy']!r}")
    ok(b["forward_known"] == "BY (Credit given to creator)",
       "B2 an already-correct value is returned untouched")
    ok(b["forward_junk"] == "a licence nobody offered" and b["forward_empty"] == "",
       "B3 an unrecognised value is left EXACTLY as it is, never guessed at")

    # ⚠️ B1–B3 ARE TAUTOLOGICAL ON THEIR OWN, and the red-first proved it rather
    # than my noticing: they call lg_fc_licence_forward() themselves, so deleting
    # the line in lg_fc_relabel() that CALLS it left all three green. They show
    # the function works. They show nothing about whether the form ever reaches
    # it — which is the whole safety. This drives the real render filter with a
    # real legacy value, through the field ACF would actually hand it.
    php = """
$f = acf_get_field('field_6564e26df56ba');
$f['value'] = %s;
$out = lg_fc_relabel($f);
echo json_encode([
  'value'   => $out['value'],
  'checked' => isset($out['choices'][$out['value']]),
  'count'   => count($out['choices']),
]);
""" % json.dumps(LEGACY)
    out, _ = wp(BOOT, php)
    try:
        w = json.loads(out.splitlines()[-1])
    except Exception:
        raise CannotRun("the relabel probe produced no JSON: " + out[:300])
    ok(w["value"] == FIXED,
       "B3b the RENDER FILTER forwards a legacy value — the map is WIRED, not "
       "merely present", f"lg_fc_relabel left the value {w['value']!r}")
    ok(w["checked"],
       "B3c …so that radio renders CHECKED. Unchecked plus required means the "
       "member cannot save their own post at all")
    ok(w["count"] == 4,
       "B3d the filter replaces the stored choices with the four from code",
       f"got {w['count']}")

    readme = io.open(os.path.join(REPO, "platform/licences/README.md"), encoding="utf-8").read()
    for lic in b["lics"]:
        ok(lic["bytes"] > 5000, f"B4 {lic['short']}: the legal text loaded",
           f"{lic['bytes']} bytes from {lic['file']}")
        # ⚠️ THE MIS-WIRED-FILE LEG. Nineteen thousand words of plausible
        # legalese all look alike; only the title says which licence it is.
        want = lic["short"].replace("CC ", "").replace(" 4.0", "")
        letters = [x for x in want.split("-") if x != "BY"]
        head = lic["first"].lower()
        good = head.startswith("attribution") and all(
            {"NC": "noncommercial", "ND": "noderivative", "SA": "sharealike"}[x] in head
            for x in letters) and len(re.findall(r"noncommercial|noderivative|sharealike", head)) == len(letters)
        ok(good, f"B5 {lic['short']}: the file is that licence, by its own title",
           f"{lic['file']} opens {lic['first']!r}")
        ok(lic["sha"] in readme,
           f"B6 {lic['short']}: the text matches the checksum recorded in README.md",
           f"{lic['sha'][:16]}… is not in platform/licences/README.md")

    # ── §C + §D  the browser ─────────────────────────────────────────────────
    DARK = dark_vars()
    tab = Tab()
    try:
        tab.call("Network.enable")
        # ⚠️ CLEAR FIRST. The chrome profile is SHARED, and setCookies ADDS a
        # second host-only-vs-dotted cookie rather than replacing one — a run can
        # otherwise execute as a different member entirely.
        tab.call("Network.clearBrowserCookies")
        tab.call("Network.setCookies", cookies=[
            {"name": who["cookie"], "value": who["value"], "domain": dom, "path": "/"},
            {"name": "loothdev_auth", "value": tokn, "domain": "." + dom, "path": "/"},
        ])
        tab.call("Emulation.setDeviceMetricsOverride",
                 width=1280, height=900, deviceScaleFactor=1, mobile=False)
        tab.call("Page.navigate", url=url)
        time.sleep(3.0)

        live = tab.js("!!document.querySelector('.lgfc__card') && "
                      "!!document.getElementById('lgfc-lic-i')")
        if not ok(live, "C0 LIVENESS — the form and the ⓘ are in the real browser"):
            raise CannotRun("the browser never got the form; every leg below would be vacuous")

        ok(tab.js("(()=>{const b=document.getElementById('lgfc-lic-i');"
                  "b.focus();return document.activeElement===b})()"),
           "C1 the ⓘ is reachable from the keyboard")

        # THE LEG THIS GATE EXISTS FOR: walk all four.
        for lic in b["lics"]:
            v = lic["value"]
            res = tab.js("""(() => {
              const v = %s;
              const dlg = document.getElementById('lgfc-lic');
              const btn = document.getElementById('lgfc-lic-i');
              const inp = [...document.querySelectorAll(
                '.acf-field[data-name="loothprint_creative_commons"] input[type=radio]'
              )].find(i => i.value === v);
              if (!inp) return JSON.stringify({err:'no radio for this value'});
              inp.click();
              btn.click();
              const body = dlg.querySelector('.lgfc-lic__b');
              return JSON.stringify({
                open:    dlg.open === true,
                modal:   dlg.matches(':modal'),
                title:   (dlg.querySelector('.lgfc-lic__t')||{}).textContent || '',
                name:    (body.querySelector('.lgfc-lic__name')||{}).textContent || '',
                first:   (body.querySelector('.lgfc-lic__legal p')||{}).textContent || '',
                paras:   body.querySelectorAll('.lgfc-lic__legal p').length,
                can:     body.querySelectorAll('.lgfc-lic__col--can li').length,
                cannot:  body.querySelectorAll('.lgfc-lic__col--cannot li').length,
                expand:  btn.getAttribute('aria-expanded'),
                checked: (document.querySelector(
                  '.acf-field[data-name="loothprint_creative_commons"] input:checked')||{}).value
              });
            })()""" % json.dumps(v))
            d = json.loads(res)
            ok(d.get("open") and d.get("modal"),
               f"C2 [{lic['short']}] the ⓘ opens a real MODAL dialog", str(d)[:200])
            ok(d.get("checked") == v,
               f"C3 [{lic['short']}] that option is the one selected")
            ok(d.get("title") == lic["short"],
               f"C4 [{lic['short']}] the modal is TITLED for the selected licence",
               f"title read {d.get('title')!r}")
            ok(d.get("first", "").strip() == lic["first"].strip(),
               f"C5 [{lic['short']}] the LEGAL TEXT is that licence's",
               f"opened with {d.get('first','')[:60]!r}")
            ok(d.get("can", 0) >= 2 and d.get("cannot", 0) >= 2,
               f"C6 [{lic['short']}] the plain summary comes first",
               f"can={d.get('can')} cannot={d.get('cannot')}")
            ok(d.get("paras", 0) > 40,
               f"C7 [{lic['short']}] the COMPLETE legal text is underneath",
               f"{d.get('paras')} paragraphs")
            ok(d.get("expand") == "true",
               f"C8 [{lic['short']}] the ⓘ reports itself expanded")

            # Escape → closed, focus home. Dispatched as a real key event.
            tab.call("Input.dispatchKeyEvent", type="rawKeyDown", key="Escape",
                     code="Escape", windowsVirtualKeyCode=27, nativeVirtualKeyCode=27)
            tab.call("Input.dispatchKeyEvent", type="keyUp", key="Escape",
                     code="Escape", windowsVirtualKeyCode=27, nativeVirtualKeyCode=27)
            time.sleep(0.35)
            after = json.loads(tab.js("""(() => {
              const dlg = document.getElementById('lgfc-lic');
              const btn = document.getElementById('lgfc-lic-i');
              return JSON.stringify({open: dlg.open === true,
                                     focus: document.activeElement === btn,
                                     expand: btn.getAttribute('aria-expanded')});
            })()"""))
            ok(after["open"] is False, f"C9 [{lic['short']}] Escape closes it")
            ok(after["focus"], f"C10 [{lic['short']}] focus returns to the ⓘ")
            ok(after["expand"] == "false",
               f"C11 [{lic['short']}] the ⓘ reports itself collapsed again")

        # ── §D  both themes, as a DELTA ──────────────────────────────────────
        def dialog_colours():
            return json.loads(tab.js("""(() => {
              const dlg = document.getElementById('lgfc-lic');
              document.getElementById('lgfc-lic-i').click();
              const c = getComputedStyle(dlg);
              // The card token as THIS PAGE resolves it, read off a throwaway
              // probe inside .lgfc so the comparison is against a real computed
              // colour in the current theme, not against a string written here.
              const probe = document.createElement('div');
              probe.style.background = 'var(--lg-card-bg,#fff)';
              (document.querySelector('.lgfc') || document.body).appendChild(probe);
              const card = getComputedStyle(probe).backgroundColor;
              probe.remove();
              const out = {bg: c.backgroundColor, fg: c.color, card: card,
                           open: dlg.open === true};
              dlg.close();
              return JSON.stringify(out);
            })()"""))

        light = dialog_colours()
        sets = ";".join("r.style.setProperty(%s,%s)" % (json.dumps(k), json.dumps(v))
                        for k, v in DARK)
        # NOTHING IS WRITTEN TO localStorage. lg-set-theme persists on the shared
        # chrome profile and would take every other lane's browser dark.
        tab.js("(function(r){%s;r.setAttribute('data-lguser-theme','dark');"
               "r.setAttribute('data-lguser-dark','1')})(document.documentElement)" % sets)
        time.sleep(0.4)
        dark = dialog_colours()
        dels = ";".join("r.style.removeProperty(%s)" % json.dumps(k) for k, _ in DARK)
        tab.js("(function(r){%s;r.setAttribute('data-lguser-theme','default');"
               "r.setAttribute('data-lguser-dark','0')})(document.documentElement)" % dels)

        transparent = ("rgba(0, 0, 0, 0)", "transparent", "")
        ok(light["open"] and dark["open"], "D1 the dialog opened in both themes")
        # ⚠️ "NOT TRANSPARENT" IS VACUOUS ON A <dialog>, and the red-first caught
        # it: the UA stylesheet gives every dialog a Canvas background, so
        # deleting our own background rule left this green. It has to match the
        # CARD TOKEN — the same surface the rest of the form uses — or the dialog
        # is being painted by something that is not this stylesheet.
        ok(light["bg"] not in transparent and dark["bg"] not in transparent,
           "D2 the dialog paints a background in both themes",
           f"light={light['bg']} dark={dark['bg']}")
        ok(light["bg"] == light["card"] and dark["bg"] == dark["card"],
           "D2b …and it is OUR card token, not the browser's default dialog white",
           f"light dialog={light['bg']} vs card={light['card']} · "
           f"dark dialog={dark['bg']} vs card={dark['card']}")
        # THE DELTA. An absolute value proves nothing: a light page wearing a dark
        # attribute passes every absolute assertion ever written against it.
        ok(light["bg"] != dark["bg"] and light["fg"] != dark["fg"],
           "D3 dark actually CHANGES the dialog — surface and ink both move",
           f"light={light['bg']}/{light['fg']}  dark={dark['bg']}/{dark['fg']}")
    finally:
        tab.close()

    # ── §E  it READS the flag ────────────────────────────────────────────────
    #
    # ⚠️ SIGN THE PROBE MEMBER IN FIRST, and assert they WOULD be served. Without
    # that this leg was vacuous, and the red-first proved it: deleting the flag
    # guard from lg_fc_route() entirely left the old E2 GREEN, because wp-cli has
    # no current user and the route refuses an anon visitor for a completely
    # different reason. "Nothing was emitted" is true on a box with nobody logged
    # in. An absence assertion needs a viewer the feature would otherwise reach.
    out, _ = wp(BOOT, """
wp_set_current_user(%d);
echo lg_fc_enabled() ? 'ON' : 'OFF';
$_SERVER['REQUEST_URI'] = '/compose/';
$_GET['type'] = 'loothprint';
echo '|' . (lg_fc_may_compose('loothprint', get_current_user_id()) ? 'CAN' : 'CANNOT');
ob_start(); lg_fc_route(); $bytes = ob_get_clean();
echo '|' . strlen($bytes);
""" % who["uid"], flag_on=False)
    parts = out.strip().split("|")
    ok(parts[0] == "OFF",
       "E1 with no override the branch reads its TRACKED config, which is OFF",
       f"lg_fc_enabled() said {parts[0]!r}")
    ok(len(parts) > 1 and parts[1] == "CAN",
       "E2 LIVENESS FOR THE ABSENCE — this member is one the route would serve, "
       "so E3's silence is the FLAG's doing and not the visitor's",
       f"lg_fc_may_compose said {parts[1] if len(parts) > 1 else '?'}")
    ok(len(parts) > 2 and parts[2] == "0",
       "E3 flag OFF: the route emits ZERO bytes to that member — no ⓘ, no dialog, "
       "no licence CSS", f"emitted {parts[2] if len(parts) > 2 else '?'} bytes")

    out, _ = wp(BOOT, "echo lg_fc_enabled() ? 'ON' : 'OFF';", flag_on=True)
    ok(out.strip() == "ON",
       "E4 …and the SAME build reads ON when the flag is armed — so E1–E3 are a "
       "flag reading, not a build that cannot switch on", f"got {out.strip()!r}")


    # ── §F  THE TWO LICENCE TABLES AGREE ─────────────────────────────────────
    #
    # ⚠️ THERE ARE TWO, they cannot be merged, and drift between them is SILENT.
    #   · lg_fc_licences()            — what the form OFFERS, so what gets STORED
    #   · Licenses::ACF_CHOICES       — what the layout engine RECOGNISES
    # The compose form is an mu-plugin and must not depend on a regular plugin's
    # class being loaded, so the duplication is deliberate. The honest answer to
    # duplication you cannot remove is to gate the agreement.
    #
    # THIS LEG EXISTS BECAUSE #191 BROKE IT. Correcting the fourth choice's
    # wording made Licenses::from_exact_prose() stop matching every post saved
    # afterwards — that recogniser is exact ON PURPOSE (a loose match would
    # rewrite an author's prose), so upgrade_license_callouts() would simply walk
    # past those posts and the licence block would never appear. Nothing errors.
    # Measured on main before the fix: from_exact_prose of the corrected string
    # returned ''. Found by grepping the repo for the old string, not by the gate
    # — which is exactly why it is a gate now.
    lic_src = os.path.join(REPO, "lg-layout-v2", "src", "Licenses.php")
    payload = json.dumps({"values": [l["value"] for l in b["lics"]],
                          "legacy": [LEGACY]})
    r = sh(["php", os.path.join(REPO, "tools", "gates", "compose-licence-crosscheck.php"),
            lic_src], env={**os.environ, "LG191_IN": payload})
    try:
        x = json.loads(r.stdout.strip().splitlines()[-1])
    except Exception:
        raise CannotRun("the cross-check probe produced no JSON: "
                        + (r.stderr or r.stdout).strip()[:300])

    # ⚠️ LIVENESS FOR THIS LEG. Under WordPress the autoloader resolves this
    # class out of the SERVING CHECKOUT — main — which is the very state being
    # tested for, so a green here would mean nothing at all.
    ok(x["file"] == lic_src,
       "F1 the cross-check read THIS BRANCH's Licenses.php, not the serve's",
       f"loaded {x['file']}")

    for lic in b["lics"]:
        seen = x["offered"].get(lic["value"], {})
        ok(seen.get("exact") != "",
           f"F2 [{lic['short']}] the layout engine recognises the offered string "
           f"EXACTLY — the recogniser that upgrades a legacy licence callout",
           f"from_exact_prose returned {seen.get('exact')!r}")
        ok(seen.get("short") == lic["short"],
           f"F3 [{lic['short']}] both tables name the SAME licence",
           f"the engine calls it {seen.get('short')!r}")

    old = x["legacy"].get(LEGACY, {})
    ok(old.get("exact") != "" and old.get("short") == "CC BY-NC-ND 4.0",
       "F4 the LEGACY spelling is still recognised — live keeps it until Ian runs "
       "the migration, and a fresh cut of dev2 reintroduces it",
       f"exact={old.get('exact')!r} short={old.get('short')!r}")

    # ── §Z end  teardown, asserted ───────────────────────────────────────────
    ok(teardown(), "Z9 the probe member is deleted — a leaked fixture makes the "
                   "NEXT run blame the feature")
    return 0


def teardown() -> bool:
    """Delete the run's own fixtures. Returns whether the probe is really gone.

    ⚠️ CALLED FROM A finally AS WELL AS FROM §Z-END, because it was NOT and that
    leaked. Two `lg191probe-*` users were found on the box after red-first runs
    whose gate aborted with CannotRun: the teardown sat at the end of main() and
    an abort walked straight past it. A gate that leaves fixtures behind is the
    exact thing feedback-gate-probe-must-be-per-run warns about — the next run
    finds someone else's rows and blames the feature.
    """
    if os.environ.get("LG191_KEEP") == "1":
        print(f"LG191_KEEP=1 — leaving probe {LOGIN} and mirror {MIRROR}")
        return True
    gone = probe_kill()
    sh(["rm", "-rf", MIRROR])
    for f in (globals().get("BOOT"), f"/tmp/lg191-gate-{TAG}.php"):
        if f:
            try:
                os.unlink(f)
            except OSError:
                pass
    return gone


if __name__ == "__main__":
    try:
        main()
    except CannotRun as e:
        # ⚠️ PRINT WHAT WAS ALREADY MEASURED. The first version exited here with
        # one line, and threw away fifteen recorded failures — so a run whose
        # cookie name was wrong reported only "the browser never got the form",
        # and the fact that the CURL half had failed for the very same reason was
        # invisible. It made the two halves look like they disagreed, and a whole
        # paragraph was written about a disagreement that never happened.
        print(f"CANNOT RUN: {e}")
        # ⚠️ TEAR DOWN ON THE ABORT PATH TOO — see teardown()'s docstring. This
        # was `rm -rf MIRROR` alone, which left the probe USER behind: two of
        # them were found on the box after red-first runs whose gate aborted.
        try:
            teardown()
        except Exception as te:
            print(f"  (teardown also failed: {te})")
        if fails:
            # ⚠️ EXIT 1, NOT 2 — findings were recorded, so this is an OPEN
            # DEFECT and not a missing environment. run-all.sh reads 2 as "could
            # not run", which reports GATES INCOMPLETE and blocks every lane on
            # the box for what is actually one branch's bug. The recorded rule is
            # that an open defect exits 1. The red-first is what surfaced this:
            # deleting the ⓘ filter reddened four curl assertions and then
            # aborted the browser leg, and the whole run came back CANNOT RUN.
            print(f"  …and {len(fails)} of {checks[0]} checks had ALREADY failed "
                  f"before that — very likely the same cause:")
            for f in fails:
                print("  FAIL  " + f)
            print(f"compose-licence  FAIL  ({len(fails)} of {checks[0]}, run aborted)")
            sys.exit(1)
        sys.exit(2)
    if fails:
        print(f"compose-licence  FAIL  ({len(fails)} of {checks[0]})")
        for f in fails:
            print("  FAIL  " + f)
        sys.exit(1)
    print(f"compose-licence  OK  ({checks[0]} checks)")
