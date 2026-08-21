#!/usr/bin/env python3
"""
GATE 85 — THE ANONYMOUS TESTER'S UNLOCK LINK.  Issue #180.

Ian, 2026-08-21, verbatim:

    "dev2 join goes to stripe rather than patreon with a fresh incognito. I need
     it to go to patreon unless the user has some kind of token url or something
     to unlock the whitelisted pages."

⚠️ THE ASSERTION THAT BITES IS THE REFUSAL, NOT THE GRANT.  "A browser holding
the token sees /lgjoin/" is satisfied by a build that shows /lgjoin/ to
EVERYBODY — which is precisely the state Ian was complaining about when he
opened this issue.  So the grant is one assertion here and the refusals are
seven: no cookie, a WRONG cookie, a cookie with the flag disabled, with an empty
hash, with a malformed hash, with a non-array config, and with no config at all.
A build that leaks goes red on all seven.

⚠️ AND THE REFUSAL HAS A SECOND HALF THAT IS EASY TO MISS.  'off' must keep
meaning NOBODY.  An unlock that quietly worked in 'off' would look perfect in
every test written about 'allowlist' and would silently overrule #170's ruling on
the state LIVE ships in.  §B4 is that assertion.

── WHAT §E MEASURES, AND WHAT IT DELIBERATELY DOES NOT CLAIM ──────────────────
Ian's stated safety net is "no one can sign up on the join page unless they are
white listed."  Measured on both boxes while this was designed: THERE IS NO
WHITELIST IN THAT PATH.  Nothing in the poller's REST controller or the Slim
billing app consults the cohort list.  What actually refuses an anonymous
visitor in the BROWSER today is that lgjoin's own JS requires
POST /wp-json/lg-member-sync/v1/auth to return ok before it calls checkout, and
that route answers an anonymous caller 401 — BuddyBoss's global
`bb-enable-private-rest-apis`, a setting re-armed by every DB reload.

So §E asserts THE REFUSAL AS IT ACTUALLY IS — the ordering in the page's own JS,
and the live 401 — and REPORTS the mechanism rather than asserting a whitelist
that does not exist.  That wording is the point: if this leg ever goes green
because someone flipped a BuddyBoss setting rather than because the funnel is
safe, the report line says so and the assertion names what it measured.  The
API-level gap is issue #181 (Ian ruled FIX BEFORE GO-LIVE, 8/21) and is out
of scope here — this gate must not be read as evidence that #181 is closed.

── WHY THIS GATE CANNOT GO VACUOUSLY GREEN ────────────────────────────────────
§A–§D need no browser, no DB, no WordPress, no FPM and no network: the header is
EXECUTED under php in an isolated tree, the claim runs against php's own built-in
server on a per-run port, and the door runs against hermetic stubs.  A locked-out
browser or a loaded box cannot turn any of it green.  Only §E touches the box,
and it CANNOT RUN rather than passing when it cannot reach it.

Every absence assertion is paired with a liveness assertion
(`feedback-absence-assertion-needs-liveness`): "the header does not say /lgjoin/"
is equally true of a header that failed to render, so every refusal leg also
proves it rendered a real Join anchor.
"""

import hashlib, http.client, os, re, shutil, socket, subprocess, sys, tempfile, time

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

fails, reports, checks = [], [], [0]


def ok(cond, msg, detail=""):
    checks[0] += 1
    if not cond:
        fails.append(msg + (f"  [{detail}]" if detail else ""))
    return bool(cond)


def report(msg):
    reports.append(msg)


def log(msg):
    print(msg, flush=True)


def cannot_run(why):
    log(f"\nGATE 85 CANNOT RUN — {why}")
    # Exit 2 is this suite's "could not run", NOT a finding: run-all.sh reads
    # 0 green / 2 CANNOT RUN / anything else RED, and a gate exiting 3 or 70
    # reports a missing environment as a defect and blocks every lane.
    sys.exit(2)


def git(*args):
    r = subprocess.run(["git", "-C", REPO] + list(args), capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"git {' '.join(args)} failed: {r.stderr.strip()[:200]}")
    return r.stdout


def php_tokens(path):
    """Source with COMMENTS AND STRINGS OF PROSE STRIPPED, via PHP's own tokenizer.

    A regex over raw source passes on a docblock that merely DESCRIBES the rule —
    and this feature's files are mostly docblock, so a source assertion read
    naively would be satisfied by its own explanation
    (`feedback-red-first-that-stays-green`).
    """
    code = (
        '$s=token_get_all(file_get_contents($argv[1]));$o="";'
        'foreach($s as $t){if(is_array($t)){'
        'if(in_array($t[0],[T_COMMENT,T_DOC_COMMENT],true)){$o.=" ";continue;}$o.=$t[1];}'
        'else $o.=$t;}echo $o;'
    )
    r = subprocess.run(["php", "-r", code, path], capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"tokenizing {path} failed: {r.stderr.strip()[:200]}")
    return r.stdout


TOKEN = "gate85unlocktoken"          # a TEST token, never a real one
TOKHASH = hashlib.sha256(TOKEN.encode()).hexdigest()

HEADER_REL = "lg-shared/site-header.php"
UNLOCK_REL = "lg-shared/tester-unlock.php"
CFG_REL = "platform/config/tester-unlock.php"
GATE_REL = "membership-pages/web/_admin-gate.php"
ROUTER_REL = "membership-pages/web/router.php"
MICRO_REL = "platform/nginx/lg-microcache.conf"

PATREON = "patreon.com"
OURJOIN = 'href="/lgjoin/"'

RENDER = r'''
$file=$argv[1]; $state=$argv[2]; $mode=$argv[3]; $cookie=$argv[4];
if ($cookie !== '') { $_COOKIE['lg_join_unlock'] = $cookie; }
$_SERVER['LG_HEADER_JOIN_STRIPE'] = $state;
require $file;
$caps = ['manage_options'=>false];
if ($mode === 'tester') { $caps['stripe_testgroup'] = true; }
if ($mode === 'admin')  { $caps['manage_options']=true; $caps['stripe_testgroup']=true; }
$ctx = $mode === 'anon'
  ? ['authenticated'=>false,'tier'=>'public']
  : ['authenticated'=>true,'tier'=>'pro','display_name'=>'probe','capabilities'=>$caps];
lg_shared_render_site_header($ctx);
'''

# The config shapes a wrong or half-placed override can take. EVERY ONE must be
# a dead unlock, because a config that fails open is the only way this feature
# can hurt anybody.
CFG_SHAPES = {
    "armed":       '<?php return array("enabled"=>true,"token_sha256"=>"%s");' % TOKHASH,
    "disabled":    '<?php return array("enabled"=>false,"token_sha256"=>"%s");' % TOKHASH,
    "empty-hash":  '<?php return array("enabled"=>true,"token_sha256"=>"");',
    "bad-hash":    '<?php return array("enabled"=>true,"token_sha256"=>"nothexatall");',
    "non-array":   '<?php return "not an array";',
    "no-return":   '<?php $x = 1;',
    "absent":      None,
}


def build_tree(dest, source, shape, local=None):
    """An isolated tree holding only what the partial needs, so a render can
    never read the WORKTREE's config or the SERVING CHECKOUT's and report the
    wrong state as the right one (`trap-harness-and-serve-answer-from-main`)."""
    os.makedirs(f"{dest}/lg-shared", exist_ok=True)
    os.makedirs(f"{dest}/platform/config", exist_ok=True)
    for f in ("site-header.php", "impact-tag.php"):
        if source == "WORKTREE":
            shutil.copyfile(f"{REPO}/lg-shared/{f}", f"{dest}/lg-shared/{f}")
        else:
            open(f"{dest}/lg-shared/{f}", "w", encoding="utf-8").write(
                git("show", f"{source}:lg-shared/{f}"))
    # The unlock reader is the WORKTREE's in both cases: origin/main does not
    # have one, and site-header.php is is_readable-guarded, so main's copy
    # simply never sees it. That asymmetry is deliberate — it is what makes the
    # byte-identity comparison a comparison of BEHAVIOUR rather than of trees.
    if source == "WORKTREE":
        shutil.copyfile(f"{REPO}/{UNLOCK_REL}", f"{dest}/lg-shared/tester-unlock.php")

    p = f"{dest}/platform/config/tester-unlock.php"
    lp = f"{dest}/platform/config/tester-unlock.local.php"
    for f in (p, lp):
        if os.path.exists(f):
            os.remove(f)
    if shape is not None:
        open(p, "w").write(shape + "\n")
    if local is not None:
        open(lp, "w").write(local + "\n")
    return f"{dest}/lg-shared/site-header.php"


def render(header_path, state, mode="anon", cookie=""):
    e = dict(os.environ)
    # A stray override in the operator's shell would silently decide every leg.
    for k in ("LG_HEADER_JOIN_STRIPE", "LG_TESTER_UNLOCK", "LG_TESTER_UNLOCK_SHA256"):
        e.pop(k, None)
    r = subprocess.run(["php", "-r", RENDER, header_path, state, mode, cookie],
                       capture_output=True, text=True, env=e)
    if r.returncode != 0:
        cannot_run(f"rendering ({state}/{mode}) failed: {r.stderr.strip()[:300]}")
    return r.stdout


def join_anchor(html):
    m = re.search(r'<a class="lg-chrome__join"[^>]*>Join</a>', html)
    return m.group(0) if m else ""


def alive(html, where, mode="anon"):
    """LIVENESS. 'The header does not point at /lgjoin/' is equally true of a
    header that rendered nothing at all, so no refusal below is trusted without
    this (`feedback-absence-assertion-needs-liveness`).

    ⚠️ MODE-AWARE, and the first version of this gate was WRONG to demand a Join
    anchor from every viewer. #170 MEASURED that `.lg-chrome__join` sits in the
    ANON branch of the aside: a signed-in viewer gets no Join pill at any width
    unless the state is 'allowlist' AND they are in the cohort. Demanding one
    from an authed render fails on correct output and would have been "fixed" by
    weakening the check that matters. A signed-in header proves itself live by
    carrying its own account furniture instead.
    """
    if mode == "anon":
        return ok(len(html) > 5000 and join_anchor(html) != "",
                  f"§LIVENESS {where}: the anon render produced no real Join "
                  f"anchor — every refusal measured here would be vacuous",
                  f"{len(html)}B, anchor={join_anchor(html)[:60]!r}")
    return ok(len(html) > 5000 and "probe" in html,
              f"§LIVENESS {where}: the signed-in render produced no account "
              f"furniture — every comparison here would be vacuous",
              f"{len(html)}B")


# ───────────────────────────── §A  SOURCE ─────────────────────────────────────
def leg_a():
    log("§A  SOURCE — the structural facts, on comment-stripped tokens")

    hdr = php_tokens(f"{REPO}/{HEADER_REL}")
    unlock = php_tokens(f"{REPO}/{UNLOCK_REL}")
    gate = php_tokens(f"{REPO}/{GATE_REL}")
    router = php_tokens(f"{REPO}/{ROUTER_REL}")

    # A1 — THE TOKEN IS NEVER IN THE REPO. The tracked config must ship an empty
    # hash: a committed one is a working link for anyone who can read the repo.
    cfg = php_tokens(f"{REPO}/{CFG_REL}")
    m = re.search(r"'token_sha256'\s*=>\s*'([^']*)'", cfg)
    ok(m is not None and m.group(1) == "",
       "§A1 the TRACKED config must ship an EMPTY token_sha256 — a committed "
       "hash plus a leaked token is a working unlock for every repo reader",
       f"found {m.group(1)[:16] if m else 'no token_sha256 key'!r}")

    # A1b — and no tracked file may carry a 64-hex sha256 next to the unlock's
    # own name. This is what a well-meaning "just for now" commit looks like.
    tracked = git("ls-files").split()
    leaked = []
    for f in tracked:
        if not f.endswith((".php", ".conf", ".sh", ".py", ".md")):
            continue
        try:
            body = open(os.path.join(REPO, f), encoding="utf-8", errors="replace").read()
        except OSError:
            continue
        if "token_sha256" in body and re.search(r"[a-f0-9]{64}", body):
            leaked.append(f)
    ok(leaked == [],
       "§A1b a tracked file pairs token_sha256 with a 64-hex value — the unlock "
       "token must live only in the gitignored .local.php",
       ", ".join(leaked[:4]))

    # A1c — the override really is gitignored, or step 3 of the arming
    # instructions quietly commits the secret.
    r = subprocess.run(["git", "-C", REPO, "check-ignore", "-q",
                        "platform/config/tester-unlock.local.php"])
    ok(r.returncode == 0,
       "§A1c platform/config/tester-unlock.local.php is NOT gitignored — arming "
       "a box would stage the token hash")

    # A2 — THE UNLOCK IS INSIDE THE allowlist ARM. If it were its own top-level
    # term, 'off' would stop meaning nobody and #170's ruling would be silently
    # overruled on the state live ships in.
    m = re.search(r"\$join_stripe\s*=\s*(.+?);", hdr, re.S)
    expr = m.group(1) if m else ""
    ok("join_unlocked" in expr and "allowlist" in expr,
       "§A2 the unlock must be a term INSIDE the allowlist arm of $join_stripe",
       expr[:160])
    # The unlock token must not appear anywhere in that expression outside the
    # allowlist parenthesis — measured by removing the allowlist group and
    # looking again.
    stripped = re.sub(r"\(\s*\$stripe_tester[^)]*\)", "", expr)
    ok("join_unlocked" not in stripped,
       "§A2b $join_unlocked appears OUTSIDE the allowlist group — 'off' would "
       "stop meaning nobody",
       stripped[:160])

    # A3 — the header must not move the AUTHED pill. #170's byte-identity proofs
    # of the signed-in header all depend on this staying true.
    m = re.search(r"\$join_pill_authed\s*=\s*(.+?);", hdr, re.S)
    ok(m is not None and "join_unlocked" not in m.group(1),
       "§A3 the unlock must not reach $join_pill_authed — the authed header is "
       "byte-proven by #170 and this feature is for anonymous browsers",
       (m.group(1)[:120] if m else "not found"))

    # A4 — THE ADMISSION IS IN THE ONE GATE BOTH DOORS DELEGATE TO, and it is
    # LAST, so it only ever widens.
    ok("lg_tester_unlock_marked" in gate,
       "§A4 _admin-gate.php must ask lg_tester_unlock_marked() — an admission "
       "placed anywhere else is the two-doors-disagree defect #180 avoided")
    i_inv = gate.find("lg_membership_invite_admits")
    i_unl = gate.find("lg_tester_unlock_marked", gate.find("testgroup_gate_or_exit"))
    ok(i_inv != -1 and i_unl != -1 and i_unl > i_inv,
       "§A4b the unlock admission must come AFTER the invite check, so it only "
       "ever widens and never decides for someone who had another way in",
       f"invite@{i_inv} unlock@{i_unl}")

    # A5 — ⚠️ THE ONE THAT PROTECTS IAN'S OWN TEST. The claim must run BEFORE the
    # administrator early-return, or an admin clicking the link marks nobody,
    # sees the join page he can always see, and hands out a dead URL.
    body = gate[gate.find("function lg_membership_testgroup_gate_or_exit"):]
    i_claim = body.find("lg_tester_unlock_handle_claim")
    i_admin = body.find("manage_options")
    ok(i_claim != -1 and i_admin != -1 and i_claim < i_admin,
       "§A5 the claim must be handled BEFORE the manage_options early-return — "
       "otherwise an ADMIN clicking the link marks nobody and never finds out",
       f"claim@{i_claim} admin@{i_admin}")
    ok("lg_tester_unlock_handle_claim" in router,
       "§A5b router.php must handle the claim too — that is the routed path, "
       "which is how /lgjoin/ is actually served")

    # A6 — the cookie's own properties. HttpOnly so no script on any of the seven
    # apps can read it; Secure; Lax (Strict would drop the mark on the very
    # cross-site navigation the pasted link IS); host-only.
    m = re.search(r"setcookie\s*\(.*?\)\s*;", unlock, re.S)
    sc = m.group(0) if m else ""
    ok("'httponly'=>true" in sc.replace(" ", ""),
       "§A6 the unlock cookie must be HttpOnly", sc[:200])
    ok("'secure'=>true" in sc.replace(" ", ""),
       "§A6b the unlock cookie must be Secure", sc[:200])
    ok("'samesite'=>'Lax'" in sc.replace(" ", ""),
       "§A6c the unlock cookie must be SameSite=Lax — Strict would drop the mark "
       "on the pasted link's own cross-site navigation", sc[:200])
    ok("'domain'" not in sc,
       "§A6d the unlock cookie must be HOST-ONLY — a dotted cookie becomes a "
       "second copy beside any host-only one "
       "(`trap-shared-chrome-profile-duplicate-session-cookies`)", sc[:200])

    # A7 — hash_equals, not ==. This compares a secret-derived value against
    # attacker-supplied input on a public endpoint.
    ok("hash_equals" in unlock,
       "§A7 the token comparison must use hash_equals")

    # A9 — THE REQUIRE MUST STAY GUARDED. site-header.php renders on every page
    # of seven apps, so an unguarded require_once of a sibling that a deploy has
    # not landed yet is a site-wide fatal rather than one quiet feature. The
    # guard is also what lets gate 79 keep rendering origin/main's copy of this
    # partial from a temp tree where the sibling does not exist.
    m = re.search(r"(\S[^\n]*)require_once\s+__DIR__\s*\.\s*'/tester-unlock\.php'", hdr)
    ok(m is not None and "is_readable" in m.group(1),
       "§A9 the tester-unlock require must be is_readable-guarded — an "
       "unguarded require is a site-wide 500 if code lands before config",
       (m.group(0)[:120] if m else "require not found"))

    # A8 — THE MICROCACHE COUPLING. A marked browser is anonymous, so /hub/ would
    # hand it a 60s-cached page still pointing at patreon.com.
    micro = open(f"{REPO}/{MICRO_REL}", encoding="utf-8").read()
    ok(re.search(r"^\s*~lg_join_unlock\s+1;", micro, re.M) is not None,
       "§A8 lg-microcache.conf must bypass the anon microcache for the unlock "
       "cookie, or the feature silently does nothing on /hub/")


# ───────────────────────────── §B  RENDER ─────────────────────────────────────
def leg_b(tmp):
    log("§B  RENDER — the header EXECUTED, every state x viewer x config shape")

    armed = build_tree(f"{tmp}/armed", "WORKTREE", CFG_SHAPES["armed"])

    # B1–B3, the whole feature and its two refusals, in the one state that has
    # an opinion.
    h = render(armed, "allowlist", "anon", "")
    alive(h, "allowlist/anon/no-cookie")
    ok(PATREON in join_anchor(h),
       "§B1 REFUSAL: an anonymous browser with NO cookie must still get "
       "patreon.com — this is what Ian reported broken", join_anchor(h)[:120])

    h = render(armed, "allowlist", "anon", "not-the-token")
    alive(h, "allowlist/anon/wrong-cookie")
    ok(PATREON in join_anchor(h),
       "§B2 REFUSAL: a WRONG cookie must be exactly as inert as no cookie",
       join_anchor(h)[:120])

    h = render(armed, "allowlist", "anon", TOKEN)
    alive(h, "allowlist/anon/good-cookie")
    a = join_anchor(h)
    ok(OURJOIN in a,
       "§B3 GRANT: a marked anonymous browser must get /lgjoin/", a[:120])
    ok("target=" not in a,
       "§B3b an INTERNAL destination must not open a new tab — that ejects a "
       "member from the installed PWA (display:standalone) to buy a membership "
       "in a browser tab", a[:120])

    # B4 — ⚠️ 'off' MUST KEEP MEANING NOBODY. An unlock that worked here would
    # pass every test written about 'allowlist' and silently overrule #170 on
    # the state LIVE ships in.
    h = render(armed, "off", "anon", TOKEN)
    alive(h, "off/anon/good-cookie")
    ok(PATREON in join_anchor(h),
       "§B4 REFUSAL: in state 'off' the cookie must not be consulted at all — "
       "'off' means NOBODY (#170's ruling, and live's tracked default)",
       join_anchor(h)[:120])

    # B5 — 'on' is unchanged: everybody already gets /lgjoin/.
    h = render(armed, "on", "anon", "")
    alive(h, "on/anon/no-cookie")
    ok(OURJOIN in join_anchor(h),
       "§B5 state 'on' must be unchanged by this feature", join_anchor(h)[:120])

    # B6 — EVERY WRONG CONFIG SHAPE IS A DEAD UNLOCK, holding a valid cookie.
    for name, shape in CFG_SHAPES.items():
        if name == "armed":
            continue
        t = build_tree(f"{tmp}/shape-{name}", "WORKTREE", shape)
        h = render(t, "allowlist", "anon", TOKEN)
        alive(h, f"shape={name}")
        ok(PATREON in join_anchor(h),
           f"§B6 REFUSAL: config shape '{name}' must fail CLOSED even with a "
           f"valid cookie present", join_anchor(h)[:120])

    # B6b — a box-local override must be able to ARM as well as read, since that
    # is the actual deploy mechanism.
    t = build_tree(f"{tmp}/local-arm", "WORKTREE", CFG_SHAPES["disabled"],
                   local=CFG_SHAPES["armed"])
    h = render(t, "allowlist", "anon", TOKEN)
    alive(h, "local-arm")
    ok(OURJOIN in join_anchor(h),
       "§B6b the gitignored .local.php must be able to ARM the unlock — it is "
       "the deploy mechanism, since an env[] flip would dirty the serving "
       "checkout", join_anchor(h)[:120])


# ─────────────────────── §B7  BYTE IDENTITY vs origin/main ────────────────────
def leg_b7(tmp):
    log("§B7 BYTE IDENTITY — OFF is proven with cmp, not argued")

    main_tree = build_tree(f"{tmp}/main", "origin/main", None)

    # ⚠️ keeper's condition: the OFF state is proven on THIS TREE, which already
    # carries the nginx microcache change. An OFF state proven only against the
    # pre-change tree would be proving something nobody is going to deploy.
    micro = open(f"{REPO}/{MICRO_REL}", encoding="utf-8").read()
    ok("~lg_join_unlock" in micro,
       "§B7-pre the byte-identity below must run on a tree that ALREADY carries "
       "the microcache change — otherwise OFF is proven for a tree that will "
       "never ship")

    for shape_name in ("absent", "disabled"):
        branch = build_tree(f"{tmp}/id-{shape_name}", "WORKTREE",
                            CFG_SHAPES[shape_name])
        for state in ("off", "allowlist", "on"):
            for mode in ("anon", "authed", "tester"):
                # A valid cookie is presented every time. If OFF ever leaks, it
                # leaks here.
                a = render(branch, state, mode, TOKEN)
                b = render(main_tree, state, mode, TOKEN)
                alive(a, f"identity {shape_name}/{state}/{mode}", mode)
                ok(a == b,
                   f"§B7 OFF must be BYTE-IDENTICAL to origin/main "
                   f"(config={shape_name}, state={state}, viewer={mode})",
                   f"{len(a)}B vs {len(b)}B")

    # B8 — ARMED, with a valid cookie: EXACTLY ONE cell may differ. This is the
    # blast radius asserted as a number rather than described in a docblock.
    branch = build_tree(f"{tmp}/id-armed", "WORKTREE", CFG_SHAPES["armed"])
    differing = []
    for state in ("off", "allowlist", "on"):
        for mode in ("anon", "authed", "tester"):
            a = render(branch, state, mode, TOKEN)
            b = render(main_tree, state, mode, TOKEN)
            alive(a, f"armed {state}/{mode}", mode)
            if a != b:
                differing.append(f"{state}/{mode}")
    ok(differing == ["allowlist/anon"],
       "§B8 ARMED with a valid cookie, EXACTLY ONE viewer x state may differ "
       "from origin/main, and it must be allowlist/anon",
       f"differing: {differing}")


# ───────────────────────────── §C  THE CLAIM ──────────────────────────────────
CLAIM_ROUTER = r'''<?php
require getenv('LG_UNLOCK_FILE');
lg_tester_unlock_handle_claim();
http_response_code(200);
header('Content-Type: text/plain');
echo "PAGE-RENDERED";
'''


def free_port():
    s = socket.socket()
    s.bind(("127.0.0.1", 0))
    p = s.getsockname()[1]
    s.close()
    return p


def http_get(port, path, cookie=None):
    c = http.client.HTTPConnection("127.0.0.1", port, timeout=10)
    hdrs = {"Cookie": cookie} if cookie else {}
    c.request("GET", path, headers=hdrs)
    r = c.getresponse()
    body = r.read().decode("utf-8", "replace")
    out = (r.status, r.getheader("Location"), r.getheader("Set-Cookie"),
           r.getheader("Cache-Control"), body)
    c.close()
    return out


def leg_c(tmp):
    log("§C  THE CLAIM — a real HTTP request, php's own server, per-run port")

    root = f"{tmp}/claim"
    os.makedirs(root, exist_ok=True)
    open(f"{root}/router.php", "w").write(CLAIM_ROUTER)

    def serve(shape):
        cfgdir = f"{tmp}/claimcfg-{shape}"
        os.makedirs(f"{cfgdir}/platform/config", exist_ok=True)
        os.makedirs(f"{cfgdir}/lg-shared", exist_ok=True)
        shutil.copyfile(f"{REPO}/{UNLOCK_REL}", f"{cfgdir}/lg-shared/tester-unlock.php")
        if CFG_SHAPES[shape] is not None:
            open(f"{cfgdir}/platform/config/tester-unlock.php", "w").write(
                CFG_SHAPES[shape] + "\n")
        port = free_port()
        e = dict(os.environ)
        for k in ("LG_TESTER_UNLOCK", "LG_TESTER_UNLOCK_SHA256"):
            e.pop(k, None)
        e["LG_UNLOCK_FILE"] = f"{cfgdir}/lg-shared/tester-unlock.php"
        p = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", root, f"{root}/router.php"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=e)
        for _ in range(80):
            try:
                socket.create_connection(("127.0.0.1", port), 0.2).close()
                return p, port
            except OSError:
                time.sleep(0.05)
        p.kill()
        cannot_run("php's built-in server did not come up for the claim leg")

    proc, port = serve("armed")
    try:
        # C1 — THE CLAIM ITSELF.
        st, loc, sc, cc, _ = http_get(port, f"/lgjoin/?lgtester={TOKEN}")
        ok(st == 302, "§C1 a valid claim must answer 302", f"got {st}")
        ok(loc == "/lgjoin/",
           "§C1b the claim must land on the CLEAN path — the token must leave "
           "the address bar, the history and every onward Referer", f"got {loc!r}")
        ok(sc is not None and f"lg_join_unlock={TOKEN}" in sc,
           "§C1c the claim must set the unlock cookie", f"got {sc!r}")
        # PHP emits these attribute names lowercase ("secure"), and RFC 6265
        # says they are case-insensitive — so compare that way rather than
        # asserting one runtime's spelling.
        low = (sc or "").lower()
        ok(sc is not None and "httponly" in low and "secure" in low
           and "samesite=lax" in low and "domain=" not in low,
           "§C1d the cookie must be HttpOnly + Secure + SameSite=Lax + host-only",
           f"got {sc!r}")
        ok(cc is not None and "no-store" in cc,
           "§C1e a Set-Cookie response must never be cacheable", f"got {cc!r}")

        # C2 — REFUSAL: a wrong token sets nothing and says nothing. A prober
        # cannot tell a wrong token from a right one that is out of scope.
        st, loc, sc, _, _ = http_get(port, "/lgjoin/?lgtester=wrong-token")
        ok(st == 302 and sc is None,
           "§C2 REFUSAL: a WRONG token must set no cookie and still strip the "
           "parameter", f"status={st} set-cookie={sc!r}")

        # C3 — REFUSAL: fence 1, scope. A perfectly good token is inert outside
        # the join flow, so it can never be a pre-launch bypass.
        st, loc, sc, _, _ = http_get(port, f"/manage-subscription/?lgtester={TOKEN}")
        ok(sc is None,
           "§C3 REFUSAL: a VALID token presented outside the join flow must set "
           "no cookie — manage-subscription is not an invitee's business",
           f"set-cookie={sc!r}")

        # C4 — the no-param case is a true no-op: the page renders.
        st, loc, sc, _, body = http_get(port, "/lgjoin/")
        ok(st == 200 and sc is None and "PAGE-RENDERED" in body,
           "§C4 with no parameter the handler must be a complete no-op",
           f"status={st} set-cookie={sc!r}")

        # C5 — CLEARING works, needs no token, and deletes rather than re-sets.
        st, loc, sc, _, _ = http_get(port, "/lgjoin/?lgtester=off")
        ok(st == 302 and sc is not None and "lg_join_unlock=" in sc
           and TOKEN not in sc,
           "§C5 the clear URL must delete the mark and must not need a token",
           f"status={st} set-cookie={sc!r}")

        # C6 — other query parameters survive the claim. A link carrying a utm
        # or a ref must not be silently truncated.
        st, loc, sc, _, _ = http_get(port, f"/lgjoin/?ref=abc&lgtester={TOKEN}")
        ok(loc is not None and "ref=abc" in loc and "lgtester" not in loc,
           "§C6 the claim must strip ONLY its own parameter", f"got {loc!r}")
    finally:
        proc.kill()

    # C7 — REFUSAL: with the flag off, a perfect token marks nobody. This is the
    # OFF state of the claim half, which §B only proves for the read half.
    proc, port = serve("disabled")
    try:
        st, loc, sc, _, body = http_get(port, f"/lgjoin/?lgtester={TOKEN}")
        ok(sc is None,
           "§C7 REFUSAL: with the flag disabled a VALID token must set no cookie",
           f"status={st} set-cookie={sc!r}")
        # C7b — LIVENESS: prove the harness is live in the disabled config too,
        # or C7 is the assertion of a server that is simply not running.
        st, _, _, _, body = http_get(port, "/lgjoin/")
        ok(st == 200 and "PAGE-RENDERED" in body,
           "§C7b LIVENESS: the disabled-config server must still serve pages, or "
           "§C7 measured nothing", f"status={st}")
        # C7c — clearing must work even when disarmed, so a visitor stranded by a
        # rotation can always un-mark themselves.
        st, _, sc, _, _ = http_get(port, "/lgjoin/?lgtester=off")
        ok(sc is not None and "lg_join_unlock=" in sc,
           "§C7c a visitor must be able to clear their own mark even after the "
           "unlock has been disarmed or rotated", f"set-cookie={sc!r}")
    finally:
        proc.kill()


# ───────────────────────────── §D  THE DOOR ───────────────────────────────────
DOOR = r'''<?php
/* Hermetic: no DB, no WordPress, no whoami. The gate's own dependencies are
   stubbed so this measures the ADMISSION RULE and nothing around it. */
require getenv('LG_UNLOCK_FILE');
function lg_membership_h($s){ return htmlspecialchars((string)$s); }
function lg_membership_wp_option($k, $d = null){ return $d; }
function lg_membership_stripe_pages_live(){ return false; }
function lg_membership_in_stripe_test_group($id){ return $id === 4242; }
$_SERVER['LG_MS_SLUG'] = getenv('LG_SLUG') ?: 'lgjoin';
if (getenv('LG_COOKIE')) { $_COOKIE['lg_join_unlock'] = getenv('LG_COOKIE'); }
require getenv('LG_GATE_FILE');
$mode = getenv('LG_MODE');
$caps = ['manage_options' => $mode === 'admin'];
$ctx = [
  'authenticated' => in_array($mode, ['admin','listed','member'], true),
  'wp_user_id'    => $mode === 'listed' ? 4242 : 7,
  'capabilities'  => $caps,
];
ob_start();
lg_membership_testgroup_gate_or_exit($ctx);
$out = ob_get_clean();
/* Reaching this line at all IS the admission: the stub calls exit(). */
echo "ADMITTED";
'''


def leg_d(tmp):
    log("§D  THE DOOR — the one gate both doors delegate to, hermetic stubs")

    cfgdir = f"{tmp}/door"
    os.makedirs(f"{cfgdir}/platform/config", exist_ok=True)
    os.makedirs(f"{cfgdir}/lg-shared", exist_ok=True)
    shutil.copyfile(f"{REPO}/{UNLOCK_REL}", f"{cfgdir}/lg-shared/tester-unlock.php")
    open(f"{cfgdir}/door.php", "w").write(DOOR)

    def run(shape, mode, cookie, slug="lgjoin"):
        cfg = f"{cfgdir}/platform/config/tester-unlock.php"
        if os.path.exists(cfg):
            os.remove(cfg)
        if CFG_SHAPES[shape] is not None:
            open(cfg, "w").write(CFG_SHAPES[shape] + "\n")
        e = dict(os.environ)
        for k in ("LG_TESTER_UNLOCK", "LG_TESTER_UNLOCK_SHA256"):
            e.pop(k, None)
        e.update({
            "LG_UNLOCK_FILE": f"{cfgdir}/lg-shared/tester-unlock.php",
            "LG_GATE_FILE": f"{REPO}/{GATE_REL}",
            "LG_MODE": mode, "LG_COOKIE": cookie, "LG_SLUG": slug,
        })
        r = subprocess.run(["php", f"{cfgdir}/door.php"], capture_output=True,
                           text=True, env=e)
        return "ADMITTED" in r.stdout, r.stdout

    # D0 — LIVENESS FIRST. If the harness admitted everybody, every refusal
    # below would be vacuous; if it admitted nobody, every grant would be.
    adm, _ = run("armed", "admin", "")
    ok(adm, "§D0 LIVENESS: an administrator must still be admitted — otherwise "
            "this harness measures nothing")
    adm, out = run("armed", "listed", "")
    ok(adm, "§D0b LIVENESS: a signed-in COHORT member must still be admitted — "
            "#170's soft launch must survive this change")
    adm, out = run("armed", "member", "")
    ok(not adm,
       "§D0c LIVENESS: an unlisted signed-in member must still be REFUSED, or "
       "the harness admits everybody", out[:80])

    # D1 — THE GRANT.
    adm, out = run("armed", "anon", TOKEN)
    ok(adm, "§D1 GRANT: a marked anonymous browser must be admitted to the join "
            "flow WITHOUT any second wp_option — that coupling is what bit #165 "
            "and #170", out[:120])

    # D2 — REFUSAL: unmarked anon still meets the stub.
    adm, out = run("armed", "anon", "")
    ok(not adm,
       "§D2 REFUSAL: an unmarked anonymous visitor must still get the stub",
       out[:120])

    # D3 — REFUSAL: wrong cookie.
    adm, out = run("armed", "anon", "not-the-token")
    ok(not adm, "§D3 REFUSAL: a wrong cookie must not admit", out[:120])

    # D4 — REFUSAL: fence 1 at the DOOR, not just at the claim. A cookie that
    # already exists must not open manage-subscription.
    adm, out = run("armed", "anon", TOKEN, slug="manage-subscription")
    ok(not adm,
       "§D4 REFUSAL: a marked browser must NOT be admitted outside the join "
       "flow — a token opening manage-subscription is a pre-launch bypass "
       "wearing an unlock's costume", out[:120])

    # D5 — REFUSAL: every disarmed shape closes the door again.
    for shape in ("disabled", "empty-hash", "bad-hash", "absent"):
        adm, out = run(shape, "anon", TOKEN)
        ok(not adm,
           f"§D5 REFUSAL: config shape '{shape}' must close the door even with a "
           f"valid cookie", out[:120])


# ─────────────────── §E  THE SAFETY NET, MEASURED AS IT IS ────────────────────
def leg_e():
    log("§E  THE SAFETY NET — measured as it actually is, not as it is assumed")

    # E1 — SOURCE: lgjoin's own JS refuses to call checkout until the auth call
    # has returned ok. THIS is the browser-path refusal a marked anonymous
    # browser meets, so if the ordering is ever removed, this leg goes red and
    # the posture has changed even if nothing else has.
    src = open(f"{REPO}/membership-pages/web/lgjoin.php", encoding="utf-8").read()
    i_auth = src.find("if (!authData.ok)")
    i_ck = src.find("ENDPOINTS.checkout")
    ok(i_auth != -1 and i_ck != -1 and i_auth < i_ck,
       "§E1 lgjoin must still check the auth answer BEFORE calling checkout — "
       "that ordering IS the browser-path refusal a marked anonymous browser "
       "meets", f"auth-check@{i_auth} checkout@{i_ck}")

    # ⚠️ ORDERING ALONE IS NOT THE PROPERTY, and asserting only that was this
    # leg's first and weaker form. "The auth check appears earlier in the file"
    # stays true of code that checks the answer, ignores it, and calls checkout
    # anyway — which is exactly the defect that would open the funnel. What
    # makes the refusal real is that the failure branch RETURNS.
    # ⚠️ THE SEGMENT MUST BE THE FAILURE BLOCK, NOT "everything up to checkout".
    # Measured: the surrounding try/catch has its OWN bare `return;`, so a
    # window ending at the checkout call stays satisfied after the failure
    # branch's return is deleted — the assertion passed on the very defect
    # (`trap-class-name-assertion-passes-on-the-defect`). The block ends where
    # the success path begins, so bound it there.
    i_end = src.find("window.__lgJoinAuthed", i_auth) if i_auth != -1 else -1
    between = src[i_auth:i_end] if (i_auth != -1 and i_end != -1) else ""
    ok(re.search(r"^\s*return;\s*$", between, re.M) is not None,
       "§E1b the auth-failure branch must RETURN before checkout is reached — "
       "without it the funnel proceeds to payment on a refused sign-in",
       f"{len(between)} chars between, no bare return")

    # E2 — BEHAVIOUR: the account-creation route must still refuse an anonymous
    # caller on this box. Worded as what it measures: the route REFUSES. It does
    # NOT assert a whitelist, because there is none in that path.
    host = os.environ.get("LG_GATE_HOST", "dev2.loothgroup.com")
    try:
        c = http.client.HTTPSConnection("127.0.0.1", 443, timeout=12,
                                        context=__import__("ssl")._create_unverified_context())
        c.request("POST", "/wp-json/lg-member-sync/v1/auth",
                  body='{"email":"gate85-probe@example.invalid","password":"x"}',
                  headers={"Host": host, "Content-Type": "application/json"})
        r = c.getresponse()
        body = r.read().decode("utf-8", "replace")[:300]
        status = r.status
        c.close()
    except Exception as exc:                                  # noqa: BLE001
        report(f"§E2 COULD NOT REACH the box to measure the signup refusal "
               f"({type(exc).__name__}) — NOT counted as a pass")
        return

    ok(status >= 400,
       "§E2 the anonymous account-creation route must still REFUSE an "
       "anonymous caller — this, not a whitelist, is what dead-ends the funnel "
       "for a marked browser today", f"status={status} body={body[:120]}")

    # E3 — REPORT the mechanism, so a green §E2 can never be mistaken for a
    # whitelist and a change of mechanism is visible in the log rather than
    # silently inherited.
    why = ("bb_rest_authorization_required" in body
           and "BuddyBoss global private-REST toggle "
               "(wp_option bb-enable-private-rest-apis)"
           or f"some other refusal: {body[:100]}")
    report(f"§E3 the funnel's refusal is currently: {why}. It is NOT a "
           f"whitelist — nothing in the signup or checkout path consults the "
           f"cohort list. The API-level gap is issue #181, ruled FIX BEFORE "
           f"GO-LIVE by Ian on 8/21; this gate does not assert it is closed.")
    report("§E4 EXPECTED, NOT A DEFECT: a tester holding the unlock link will "
           "reach the tier picker and then dead-end at \"Sign-in failed\" when "
           "they enter an email. That IS the refusal above, working.")


# ───────────────────────────── §F  COUPLING ───────────────────────────────────
def leg_f():
    """COUPLING — reported, never asserted.

    ⚠️ IT MUST READ THE SERVING CHECKOUT, NOT THIS WORKTREE. The first version
    required the WORKTREE's site-header.php, whose __DIR__ resolves to the
    worktree's platform/config — so it read the TRACKED default and reported
    "this box is 'off'" on a box keeper had deliberately set to 'allowlist'.
    A report that names the wrong subject is worse than no report: it is the
    "verify the thing, not the thing next to it" trap, and here it would have
    told a reader the unlock was inert on a box where it is live.
    """
    log("§F  COUPLING — reported, never asserted")
    served = "/srv/lg-shared/site-header.php"
    if not os.path.exists(served):
        report("§F no serving checkout at /srv/lg-shared — cannot report this "
               "box's header-join-stripe state. NOT a finding.")
        return
    env = {k: v for k, v in os.environ.items() if k != "LG_HEADER_JOIN_STRIPE"}
    r = subprocess.run(
        ["php", "-r", "require $argv[1]; echo lg_shared_header_join_stripe_state();",
         served],
        capture_output=True, text=True, env=env)
    state = r.stdout.strip() or "unknown"
    if state == "off":
        report("§F THE SERVING CHECKOUT's header-join-stripe is 'off', so the "
               "unlock is INERT on this box however it is configured. That is "
               "correct behaviour ('off' means nobody), not a defect — but "
               "arming the unlock alone will appear to do nothing. Both boxes "
               "are meant to sit in 'allowlist' during the soft launch.")
    else:
        report(f"§F THE SERVING CHECKOUT's header-join-stripe is '{state}' — the "
               f"unlock is live on this box once armed.")


def main():
    log("=" * 72)
    log("GATE 85 — the anonymous tester's unlock link (#180)")
    log("=" * 72)

    if shutil.which("php") is None:
        cannot_run("php is not on PATH")

    with tempfile.TemporaryDirectory(prefix=f"gate85-{os.getpid()}-") as tmp:
        leg_a()
        leg_b(tmp)
        leg_b7(tmp)
        leg_c(tmp)
        leg_d(tmp)
    leg_e()
    leg_f()

    log("")
    for r in reports:
        log(f"  REPORT  {r}")
    log("")
    if fails:
        log(f"GATE 85 RED — {len(fails)} of {checks[0]} assertions failed")
        for f in fails:
            log(f"  FAIL  {f}")
        sys.exit(1)
    log(f"GATE 85 GREEN — {checks[0]} assertions")
    sys.exit(0)


if __name__ == "__main__":
    main()
