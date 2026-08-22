#!/usr/bin/env python3
"""
switch-menu-gate — GATE 93 — a member Patreon is already charging is offered
SWITCH, never JOIN, and the place Switch goes actually exists.
(Number minted from MAIN by this lane per feedback-gate-number-from-main-not-branch;
lanes 193 and 194 are in flight and will mint 93 too — keeper keeps all three.)

WHY THIS GATE EXISTS.  Ian, 2026-08-22, verbatim on issue #196:

    "Can you check and see if a user that has a patreon would have a menu for
     join in the profile chip? If so we need to change that to switch and give
     people a page with instructions for Patreon deactivation and reactivation
     through stripe."

Measured on main before a line was written: user 1953 is a listed soft-launch
tester with an ACTIVE PAID Patreon pledge (looth2, next charge 2026-09-02), and
the account menu offered her Join. #150's guard would refuse her at checkout —
presence-is-not-reachability on a money door — and on dev2 that guard is not
even armed (`lgms_double_pay_block` absent), so the menu was the only thing
between a paying patron and a second charge.

⚠️ THE FIRST THING TO KNOW: THE OBVIOUS ASSERTION IS THE VACUOUS ONE.

"a Patreon payer sees Switch" is easy and it is worth very little on its own,
because it is equally true of a build that shows Switch to EVERYBODY — which
would send every non-Patreon tester to a page of instructions for cancelling a
pledge they do not have, and would delete the join door for the cohort the
soft launch exists for. The assertions that bite are the NEGATIVE ones, and
they are §B's spine: a non-Patreon tester still sees Join and NOT the switch
page; a Patreon payer OUTSIDE the cohort sees neither; a ctx carrying no
capabilities at all sees neither. #148 paid for this lesson in the same domain
("a PRO purchase grants looth3" passed on the defect because the constant
already was looth3).

WHAT IT ASSERTS — five legs.

  §A SOURCE, on COMMENT-STRIPPED tokens via PHP's own tokenizer, so prose in a
     docblock can never satisfy an assertion (the red-first-that-stays-green
     class has cost this repo six findings, one of which blamed a working page).
     The header keys on the capability; label and href are derived ONCE and
     shared by both renderings rather than written down twice; the header
     performs no lookup of its own — no option name, no user id, no query —
     because it renders on seven apps under seven unix users with no database;
     the poller computes the capability from PatreonStanding, the single
     definition of "already paying" that #150's three doors ask; and
     profile-app's NAMED pass-through forwards it.

     ⚠️ THAT LAST ONE IS NOT PARANOIA. profile-app names the capabilities it
     forwards, so one nobody remembered to name is dropped — and a dropped
     capability is INDISTINGUISHABLE from one that is false. It happened on
     2026-08-16, to stripe_testgroup, to this very menu, and the person it
     happened to was user 1953.

  §B RENDER, EXECUTED, five viewers x three capability states. Not "the file
     contains the string" — the partial is run under php against an isolated
     tree so no worktree or serving-checkout config can decide the answer.

  §C BYTE-IDENTITY AGAINST origin/main, proven with a comparison rather than
     argued in a comment. Anon in every flag state; a non-tester with the
     capability true, false and absent; a non-Patreon tester; an admin; and a
     no-caps ctx. #196 ships NO FLAG because every byte of it lives inside the
     $stripe_tester narrowing that was already there — and that claim is only
     worth anything if something checks it. It also catches the leak this
     lane's own first draft shipped: an indented `<?php ?>` island inside the
     menu emits its own leading whitespace as inline HTML whether or not the
     branch is taken, and added 12 bytes to EVERY tester render.

  §D THE PWA COPY, RUN RATHER THAN READ. webroot/bottom-nav.js draws this
     control a third time in the account sheet, and at <=640 on the hub it is
     the ONLY one a phone gets — bb-mirror/web/forums.css hides the entire
     header aside there. It mirrored the HREF and hardcoded the WORD "Join", so
     it would have read Join while pointing at the switch page. Its rule is
     lifted out and EXECUTED in node against both destinations, because the
     real origin serves main and cannot exercise this branch at all
     (trap-harness-and-serve-answer-from-main).

  §E THE COUPLING — where Switch goes must exist. THREE files have to name the
     same slug: the header's href, the router's registry, and the nginx
     location regex, which ENUMERATES every membership slug and has no
     catch-all. That is asserted statically and unconditionally. The RUNNING
     nginx snippet is a root-owned COPY, not a symlink, so the repo change does
     NOT arrive by `git pull`; while the box has not been updated this leg
     REPORTS the gap and names the command, and the moment it has, it asserts
     the URL is answered by membership-pages rather than falling through to
     WordPress. "Wired perfectly and lands nowhere" is the named failure class
     on this exact surface (#165 §E).

Liveness sits beside every absence: "no Join anywhere" is trivially true of a
render that died before it reached the menu (feedback-absence-assertion-needs-
liveness).

RED-FIRST: tools/gates/switch-menu-redfirst.py.
Exit 0 green / 1 finding / 2 no verdict.
"""

import json, os, re, shutil, subprocess, sys, tempfile, urllib.request

NO_VERDICT = 2
REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

HEADER   = "lg-shared/site-header.php"
POLLER   = "lg-patreon-stripe-poller/src/Wp/InternalRestController.php"
WHOAMI   = "profile-app/src/Whoami.php"
BOTTOMNV = "webroot/bottom-nav.js"
ROUTER   = "membership-pages/web/router.php"
PAGE     = "membership-pages/web/switch-billing.php"
NGINX    = ["platform/nginx/strangler-membership.conf",
            "platform/nginx/strangler-membership-buck.conf",
            "platform/nginx/strangler-membership-preview-a.conf"]
LIVE_SNIPPET = "/etc/nginx/snippets/strangler-membership.conf"

CAP  = "patreon_paying"
SLUG = "switch-billing"
DEST = "/switch-billing/"

passes = failures = 0
findings = []

def log(*a): print(" ".join(str(x) for x in a), flush=True)

def check(label, ok, detail=""):
    global passes, failures
    if ok: passes += 1
    else:
        failures += 1
        findings.append(f"{label}   {detail}".strip())
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + (f"   {detail}" if detail and not ok else ""))
    return ok

def report(label, detail=""):
    """A measured fact this gate refuses to SCORE, with the reason in the line."""
    log(f"  REPORT  {label}" + (f"   {detail}" if detail else ""))

def cannot_run(why):
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(NO_VERDICT)

def git(*a):
    r = subprocess.run(["git", "-C", REPO, *a], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"git {' '.join(a)}: {r.stderr.strip()[:200]}")
    return r.stdout

# ─────────────────────────────────────────────────────── comment stripping ──
# PHP's own tokenizer, not a regex: a regex that "removes comments" gets /* */
# inside a string literal wrong in both directions, and this gate's credibility
# rests entirely on an assertion being unsatisfiable by prose.
STRIP = r'''
$src = file_get_contents($argv[1]);
$out = '';
foreach (token_get_all($src) as $t) {
    if (is_array($t)) {
        if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) { $out .= "\n"; continue; }
        $out .= $t[1];
    } else { $out .= $t; }
}
echo $out;
'''

def php_code(rel):
    p = os.path.join(REPO, rel)
    if not os.path.isfile(p):
        cannot_run(f"{rel} not found under {REPO}")
    r = subprocess.run(["php", "-r", STRIP, p], capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"could not tokenize {rel}: {r.stderr.strip()[:200]}")
    return r.stdout

def js_code(rel):
    """// and /* */ stripped. Crude on purpose and safe for the one function
    read here: every use only ASKS whether a token is present, so an over-eager
    strip yields a false RED and never a false green."""
    src = open(os.path.join(REPO, rel), encoding="utf-8").read()
    src = re.sub(r"/\*.*?\*/", "\n", src, flags=re.S)
    src = re.sub(r"(?m)^\s*//.*$", "", src)
    return src


# ══════════════════════════════════════════════════════════════════════ §A ══
def leg_a():
    log("§A  SOURCE — the swap is resolved, and the capability survives the wire "
        "(comment-stripped)")
    hdr = php_code(HEADER)

    check("the header keys on the capability at all",
          f"$caps['{CAP}']" in hdr or f'caps[\'{CAP}\']' in hdr)

    # ONE derivation, not two. Two copies of "if paying then Switch" is two
    # places for the next change to miss one, and this repo has paid for that
    # shape twice already (the profile tray's unwired socials, the reader
    # sheet's un-embedded HTML — trap-relocated-markup-loses-its-wiring).
    check("the label is derived ONCE, from the capability",
          re.search(r"\$tester_join_label\s*=\s*\$patreon_paying\s*\?", hdr) is not None)
    check("the href is derived ONCE, from the capability",
          re.search(r"\$tester_join_href\s*=\s*\$patreon_paying\s*\?", hdr) is not None)
    # ⚠️ /lgjoin/ IS NAMED TWICE AND BOTH ARE CORRECT — measured, not assumed:
    # once as the ANONYMOUS destination ($join_href, #165's flag) and once in
    # this signed-in derivation. They are different controls for different
    # viewers and must not be folded together. The switch page, by contrast, is
    # reachable only from the signed-in door, so a second mention of it would be
    # a second place to forget.
    check("the switch destination is named exactly ONCE — one derivation, no copies",
          hdr.count(f"'{DEST}'") == 1,
          f"{hdr.count(chr(39)+DEST+chr(39))} occurrences")
    check("/lgjoin/ is named exactly twice: the anon destination and this one",
          hdr.count("'/lgjoin/'") == 2,
          f"{hdr.count(chr(39)+'/lgjoin/'+chr(39))} occurrences")

    # The one rendering must read the derived values — a literal is the drift
    # this derivation exists to prevent.
    check("the menu item takes its href from the derivation, not a literal",
          re.search(r'role="menuitem" href="<\?=\s*\$h\(\$tester_join_href\)', hdr) is not None)
    check("the menu item takes its LABEL from the derivation too",
          re.search(r'\$h\(\$tester_join_href\)\s*\?>"><\?=\s*\$h\(\$tester_join_label\)', hdr) is not None)

    # ⚠️ THE SIGNED-IN PILL IS RULED OUT (Ian, 2026-08-22: "Why is there a
    # superfluous join button for logged in users now"), so the door must NOT be
    # drawn a second time beside the chip. Exactly one lg-chrome__join anchor
    # survives, and it is the ANONYMOUS one — which #196 never touches.
    check("no signed-in Join pill is drawn — one door, in the account menu",
          "$join_pill_authed" not in hdr)
    check("...and exactly one join pill anchor remains, the anon one",
          len(re.findall(r'class="lg-chrome__join"', hdr)) == 1,
          f'{len(re.findall(chr(0x22) + "lg-chrome__join" + chr(0x22), hdr))} found')

    # THE HOOK THE PHONE READS. Measured 2026-08-22 on /hub/ AND the front page
    # as a real signed-in tester: at 390 and 640 the account chip is invisible
    # and #lg-account-menu is display:none, so below 641 the menu is in the DOM
    # the whole time and is not a door at all. bottom-nav.js draws it in the
    # account sheet by reading this hook; without it the ruling would leave a
    # signed-in tester no join or switch door on a phone anywhere.
    check("the menu item carries the hook the PWA account sheet reads at <=640",
          'class="lg-chrome__menu-join"' in hdr)

    # ⚠️ THE HEADER MUST NOT LOOK ANYTHING UP. It renders on seven apps under
    # seven unix users with no database of their own.
    for forbidden, why in (
            ("lg_patreon_members", "a table name"),
            ("lgpo_patreon_user_id", "a usermeta key"),
            ("lgms_double_pay_block", "an option name"),
            ("PatreonStanding", "the poller's class"),
            ("get_option", "a WordPress call")):
        check(f"the header contains no {why} ({forbidden}) — it has no database to ask",
              forbidden not in hdr)

    # It must also fail CLOSED. `($caps[...] ?? false) === true` is the shape:
    # absent, null, 'yes' and 1 all read false.
    check("the capability is read strictly, so anything but true is false",
          re.search(r"\$patreon_paying\s*=\s*\(\$caps\['" + CAP + r"'\]\s*\?\?\s*false\)\s*===\s*true", hdr)
          is not None)

    # ── the far end: computed once, centrally, from the ONE definition ────────
    pol = php_code(POLLER)
    check("the poller computes the capability",
          f"$caps['{CAP}']" in pol)
    check("...from PatreonStanding — the same definition #150's three doors ask",
          re.search(r"PatreonStanding::forUser\(\s*\$wpUserId\s*\)\['active'\]", pol) is not None)
    check("...never from payment_source, which is one slot both rails overwrite",
          "payment_source" not in pol)
    check("...and a throw is caught, so an unreadable database reads as NOT paying",
          re.search(r"\$caps\['" + CAP + r"'\]\s*=\s*false;\s*try\s*\{", pol) is not None)

    # ⚠️ THE NAMED PASS-THROUGH. A capability nobody remembered to name is
    # dropped, and a dropped capability is indistinguishable from a false one.
    who = php_code(WHOAMI)
    m = re.search(r"foreach\s*\(\[([^\]]*)\]\s*as\s*\$k\)", who)
    forwarded = re.findall(r"'([a-z_]+)'", m.group(1)) if m else []
    check("profile-app's named pass-through forwards the capability",
          CAP in forwarded, f"forwards: {forwarded}")

    # The structural version: EVERY capability the header keys on must survive.
    # Gate 34b asserts this too; it is repeated here because #196 is the change
    # that adds one, and a cross-check that lives only in a neighbour's gate is
    # a cross-check the next lane will not run.
    keyed = set(re.findall(r"caps\['([a-z_]+)'\]", php_code(HEADER)))
    explicit = set(re.findall(r"'([a-z_]+)'\s*=>", who))
    dropped = sorted(keyed - explicit - set(forwarded))
    check("EVERY capability the header keys on survives the pass-through",
          dropped == [], f"dropped: {dropped or 'none'}")
    log("")


# ══════════════════════════════════════════════════════════════════════ §B ══
RENDER = r'''
$file = $argv[1]; $mode = $argv[2];
require $file;
/* FIVE VIEWERS. 'tester' and 'admin' carry the soft-launch capability; 'member'
   is a signed-in viewer outside the cohort; 'nocaps' carries no capabilities at
   all, which is what a ctx that was never taught about this looks like. The
   +pat / -pat / (absent) suffix is the capability under test, and the ABSENT
   case is the one a deploy produces when the header lands before the poller. */
$caps = ['manage_options' => false];
if (str_contains($mode, 'tester')) { $caps['stripe_testgroup'] = true; }
if (str_contains($mode, 'admin'))  { $caps['manage_options'] = true; $caps['stripe_testgroup'] = true; }
if (str_contains($mode, '+pat'))   { $caps['patreon_paying'] = true; }
if (str_contains($mode, '-pat'))   { $caps['patreon_paying'] = false; }
if (str_starts_with($mode, 'nocaps')) { $caps = []; }
$ctx = str_starts_with($mode, 'anon')
  ? ['authenticated' => false, 'tier' => 'public']
  : ['authenticated' => true, 'tier' => 'pro', 'display_name' => 'probe', 'capabilities' => $caps];
lg_shared_render_site_header($ctx);
'''

STATES = {
    "off":       "<?php\nreturn array('state' => 'off');\n",
    "allowlist": "<?php\nreturn array('state' => 'allowlist');\n",
    "on":        "<?php\nreturn array('state' => 'on');\n",
}

def build_tree(dest, source, state):
    """An isolated tree holding only what the partial needs, so a render can
    never read the WORKTREE's config (or the serving checkout's) and report the
    wrong state as the right one."""
    os.makedirs(f"{dest}/lg-shared", exist_ok=True)
    os.makedirs(f"{dest}/platform/config", exist_ok=True)
    for f in ("site-header.php", "impact-tag.php"):
        if source == "WORKTREE":
            shutil.copyfile(f"{REPO}/lg-shared/{f}", f"{dest}/lg-shared/{f}")
        else:
            open(f"{dest}/lg-shared/{f}", "w", encoding="utf-8").write(
                git("show", f"{source}:lg-shared/{f}"))
    for extra in ("tester-unlock.php",):
        src = f"{REPO}/lg-shared/{extra}"
        if os.path.isfile(src):
            shutil.copyfile(src, f"{dest}/lg-shared/{extra}")
    open(f"{dest}/platform/config/header-join-stripe.php", "w").write(STATES[state])
    return f"{dest}/lg-shared/site-header.php"

def render(path, mode):
    e = dict(os.environ)
    # A stray override in the operator's shell would otherwise decide every leg.
    e.pop("LG_HEADER_JOIN_STRIPE", None)
    r = subprocess.run(["php", "-r", RENDER, path, mode], capture_output=True, text=True, env=e)
    if r.returncode != 0:
        cannot_run(f"rendering {path} ({mode}) failed: {r.stderr.strip()[:300]}")
    return r.stdout

def menu_of(html):
    m = re.search(r'<ul[^>]*id="lg-account-menu".*?</ul>', html, re.S)
    return m.group(0) if m else ""

def pill_of(html):
    m = re.search(r'<a class="lg-chrome__join"[^>]*>[^<]*</a>', html)
    return m.group(0) if m else ""


def leg_b(tmp):
    log("§B  RENDER — executed, five viewers x three capability states")

    trees = {s: build_tree(f"{tmp}/b-{s}", "WORKTREE", s) for s in STATES}
    R = {}
    for s in STATES:
        for mode in ("anon", "member+pat", "member-pat", "member",
                     "tester+pat", "tester-pat", "tester",
                     "admin+pat", "admin-pat", "nocaps"):
            R[(s, mode)] = render(trees[s], mode)

    # Liveness FIRST: every assertion below is an equality or an absence, and
    # both are trivially true of a render that died before the aside.
    check("liveness: a tester render is a real signed-in header with a menu",
          len(R[("allowlist", "tester+pat")]) > 5000
          and 'class="lg-chrome__account"' in R[("allowlist", "tester+pat")]
          and menu_of(R[("allowlist", "tester+pat")]) != "",
          f"{len(R[('allowlist','tester+pat')])} bytes")

    for s in STATES:
        # ── THE POSITIVE. Only in this one combination.
        m = menu_of(R[(s, "tester+pat")])
        check(f"{s}: a Patreon-paying tester's menu says Switch",
              f'href="{DEST}">Switch<' in m)
        check(f"{s}: ...and offers NO /lgjoin/ anywhere in the menu",
              "/lgjoin/" not in m)

        # ── THE NEGATIVES, which are the ones that bite.
        m2 = menu_of(R[(s, "tester-pat")])
        check(f"{s}: a tester with NO Patreon still says Join",
              'href="/lgjoin/">Join<' in m2)
        check(f"{s}: ...and is never sent to the switch page",
              DEST not in m2)
        m3 = menu_of(R[(s, "tester")])
        check(f"{s}: an ABSENT capability fails closed to Join — the deploy order case",
              'href="/lgjoin/">Join<' in m3 and DEST not in m3)

        for who in ("member+pat", "member-pat", "member", "nocaps"):
            mm = menu_of(R[(s, who)])
            check(f"{s}: {who} — outside the cohort, so NEITHER door appears",
                  DEST not in mm and "/lgjoin/" not in mm)

        # An admin is in the cohort by construction, so the swap must reach Ian
        # too — he is the person most likely to check it.
        ma = menu_of(R[(s, "admin+pat")])
        check(f"{s}: an admin who pays Patreon gets Switch as well",
              f'href="{DEST}">Switch<' in ma and "/lgjoin/" not in ma)

        # ── ONE DOOR. #196 ruled the signed-in pill out in EVERY state, so the
        # menu entry is the whole of it — and it must carry the hook, or the
        # phone loses its only copy.
        check(f"{s}: a signed-in tester is drawn NO Join pill in any state",
              pill_of(R[(s, "tester+pat")]) == "" and pill_of(R[(s, "tester-pat")]) == "",
              f"{pill_of(R[(s, 'tester+pat')])!r}")
        check(f"{s}: ...and the menu entry carries the hook the phone reads",
              'class="lg-chrome__menu-join"' in R[(s, "tester+pat")]
              and 'class="lg-chrome__menu-join"' in R[(s, "tester-pat")])
        check(f"{s}: neither destination opens a new tab — an installed PWA has "
              f"no chrome to come back through",
              "target=" not in m and "target=" not in m2)

        # ── ANON IS UNTOUCHED. It cannot even reach the branch: an anonymous
        # ctx carries no capabilities, which is the same structural argument
        # #170's caching law rests on.
        a = R[(s, "anon")]
        check(f"{s}: the anonymous header never mentions the switch page",
              DEST not in a)
        check(f"{s}: ...and its Join pill is exactly what the flag says it is",
              pill_of(a) != "", pill_of(a))
    log("")
    return R


# ══════════════════════════════════════════════════════════════════════ §C ══
def leg_c(tmp, R):
    log("§C  BYTE-IDENTITY vs origin/main — #196 ships no flag, so it must move "
        "nothing else")

    main_trees = {s: build_tree(f"{tmp}/c-{s}", "origin/main", s) for s in STATES}
    M = {}
    for s in STATES:
        for mode in ("anon", "member+pat", "member-pat", "member",
                     "tester-pat", "tester+pat", "tester", "admin-pat", "admin", "nocaps"):
            M[(s, mode)] = render(main_trees[s], mode)

    check("liveness: main's baseline render really produced the header",
          len(M[("allowlist", "tester-pat")]) > 5000
          and 'class="lg-chrome__join"' in M[("allowlist", "tester-pat")],
          f"{len(M[('allowlist','tester-pat')])} bytes")

    for s in STATES:
        # ⚠️ ONLY THE VIEWERS #196 MUST NOT REACH. A tester or an admin now
        # DELIBERATELY differs from main — Ian ruled the signed-in pill out on
        # 2026-08-22 — and demanding identity from them would be the hardcoded-
        # historical-baseline trap that reddened eleven of gate 79's legs on
        # #173 and six of gate 85's on this very issue. What they changed by is
        # asserted line-for-line below instead, which is the stronger claim.
        for mode in ("anon", "member+pat", "member-pat", "member", "nocaps"):
            same = R[(s, mode)] == M[(s, mode)]
            check(f"{s}/{mode}: byte-identical to origin/main",
                  same, f"{len(R[(s,mode)])} vs {len(M[(s,mode)])} bytes")

    # ⚠️ AND THE ONE THAT MUST DIFFER, DIFFERS BY EXACTLY THE INTENDED LINES.
    # A change that also moved something else would satisfy every assertion
    # above. The whitespace leak this lane shipped in its own first draft — an
    # indented <?php ?> island adding 12 bytes to every tester render — is
    # invisible to every href assertion and lands here.
    # ⚠️ WHAT MOVED, NOT HOW MANY LINES. A real diff (the pill is REMOVED, so a
    # positional compare reports the whole rest of the document as changed), and
    # then every changed line must be one of the four this issue is allowed to
    # touch. That is what catches a change which also moved something else while
    # making the swap — including the 12-byte whitespace leak this lane shipped
    # in its own first draft, which no href assertion can see.
    import difflib

    OLD_LI   = '<li role="none">'
    # The exact bytes each allowed line must arrive with. A line whose CONTENT
    # is permitted but whose INDENTATION moved is a stray — that is the leak.
    EXPECT   = {}
    NEW_LI   = '<li role="none" class="lg-chrome__menu-join">'
    OLD_JOIN = '<a role="menuitem" href="/lgjoin/">Join</a>'
    NEW_SWAP = f'<a role="menuitem" href="{DEST}">Switch</a>'
    PILL     = 'class="lg-chrome__join"'
    EXPECT.update({
        '<li role="none" class="lg-chrome__menu-join">': '            <li role="none" class="lg-chrome__menu-join">',
        f'<a role="menuitem" href="{DEST}">Switch</a>':  f'              <a role="menuitem" href="{DEST}">Switch</a>',
    })

    for s_ in STATES:
        for who, pays in (("tester+pat", True), ("tester-pat", False)):
            d = list(difflib.ndiff(M[(s_, "tester-pat")].splitlines(),
                                   R[(s_, who)].splitlines()))
            # ⚠️ NOT .strip(). The whole point of this leg is a change that is
            # ONLY whitespace: an indented <?php ?> island emits its own leading
            # spaces as inline HTML whether or not the branch is taken. The
            # first version of this gate stripped these lines and its red-first
            # M08 stayed GREEN — the assertion was decoration about the exact
            # defect it was written for. Compare raw; strip only for display.
            added   = [l[2:] for l in d if l.startswith("+ ")]
            removed = [l[2:] for l in d if l.startswith("- ")]

            # THE RULING: the signed-in pill goes, and only where main drew one.
            pill_gone = any(PILL in r for r in removed)
            check(f"{s_}/{who}: the ruled-out signed-in pill is removed "
                  f"{'(main drew one here)' if s_ == 'allowlist' else '(main drew none here)'}",
                  pill_gone == (s_ == "allowlist"),
                  f"removed: {removed}")

            # THE HOOK: the menu row gains the class the phone reads.
            check(f"{s_}/{who}: the menu row gains the PWA hook",
                  any(x.strip() == NEW_LI for x in added)
                  and any(x.strip() == OLD_LI for x in removed),
                  f"+{[x.strip() for x in added]} -{[x.strip() for x in removed]}")

            # THE SWAP: only for a payer, and it is the only href that moves.
            if pays:
                check(f"{s_}/{who}: the entry becomes Switch -> {DEST}",
                      any(x.strip() == NEW_SWAP for x in added)
                      and any(x.strip() == OLD_JOIN for x in removed),
                      f"+{[x.strip() for x in added]}")
            else:
                check(f"{s_}/{who}: a non-paying tester's Join is untouched",
                      DEST not in " ".join(added), f"+{[x.strip() for x in added]}")

            # NOTHING ELSE. The assertion the whitespace leak lands on.
            allowed_add = {NEW_LI, NEW_SWAP}
            allowed_rem = {OLD_LI, OLD_JOIN}
            # EXACT, including indentation — see the note above.
            stray_a = [x for x in added if x.strip() not in allowed_add or x != EXPECT.get(x.strip(), x)]
            stray_r = [x for x in removed if x.strip() not in allowed_rem and PILL not in x]
            check(f"{s_}/{who}: NOTHING else moved — no stray line, no stray byte",
                  stray_a == [] and stray_r == [],
                  f"stray +{stray_a} -{stray_r}")
    log("")


# ══════════════════════════════════════════════════════════════════════ §D ══
def leg_d():
    log("§D  THE PWA COPY — the phone's only door, its rule RUN not read")

    js = js_code(BOTTOMNV)
    check("the account sheet reads the header's MENU ENTRY rather than a flag",
          "querySelector('.lg-chrome__menu-join a')" in js)
    check("...and no longer reads the ruled-out signed-in pill",
          "hdrHref('.lg-chrome__join', null)" not in js)
    check("bottom-nav reads NO flag and NO cohort list of its own",
          "lgms_stripe_lifecycle_allowlist" not in js and "header-join-stripe" not in js)

    # ⚠️ THE TWO DECIDING LINES ARE LIFTED OUT OF THE FILE AND EXECUTED.
    #
    # The first version of this leg ran a hand-written COPY of the rule and
    # asserted the file merely CONTAINED the tokens "menuJoinLabel" and
    # "textContent" — and red-first proved that worthless: replacing the real
    # label line with `var menuJoinLabel = 'Join';` left the gate GREEN, because
    # "textContent" occurs elsewhere in a 1,000-line file. A gate that runs its
    # own transcription tests the transcription.
    #
    # Run rather than read for a second reason too: the live origin serves MAIN,
    # so §E's browser can never exercise this branch's copy at all
    # (trap-harness-and-serve-answer-from-main).
    m_label = re.search(r"^\s*var menuJoinLabel = .*?;$", js, re.M)
    m_tab   = re.search(r"^\s*if \(/\^https\?:.*?test\(menuJoinHref\)\).*?}$", js, re.M)
    # ⚠️ A MISSING LINE IS A FINDING, NOT A NO-VERDICT, and red-first is what
    # showed the difference matters. Deleting the tab guard — the exact defect
    # that ejects a member from the installed PWA — made the lift fail, and an
    # earlier version answered CANNOT RUN (exit 2): a real defect reported as a
    # missing environment, which run-all reads as "no verdict" rather than red
    # (trap-gate-exit-code-3-blocks-every-lane). They are checks now.
    check("bottom-nav still derives the sheet row's LABEL from the menu entry",
          m_label is not None, "the label line is gone from the file")
    check("bottom-nav still guards target=_blank on the href being off-site",
          m_tab is not None, "the tab guard is gone — an internal page would "
                             "eject a member from the installed PWA")
    if not m_label or not m_tab:
        log("")
        return

    prog = """
    const cases = [
      ['/switch-billing/', 'Switch'],
      ['/lgjoin/', 'Join'],
      ['https://www.patreon.com/c/theloothgroup/membership', 'Join'],
      ['/switch-billing/', '   '],
    ];
    const out = cases.map(([href, text]) => {
      const menuJoinEl   = { textContent: text };
      const menuJoinHref = href;
      const joinRow2     = {};
      %s
      %s
      return { label: menuJoinLabel, external: joinRow2.target === '_blank' };
    });
    console.log(JSON.stringify(out));
    """ % (m_label.group(0).strip(), m_tab.group(0).strip())

    r = subprocess.run(["node", "-e", prog], capture_output=True, text=True)
    if r.returncode != 0:
        cannot_run(f"node could not run bottom-nav's own lines: {r.stderr.strip()[:200]}")
    rows = json.loads(r.stdout.strip())
    check("the sheet row follows the menu to Switch, in a same-tab link",
          rows[0] == {"label": "Switch", "external": False}, str(rows[0]))
    check("...and to Join, still same-tab, for an internal join page",
          rows[1] == {"label": "Join", "external": False}, str(rows[1]))
    check("...and opens a new tab ONLY for an off-site destination",
          rows[2] == {"label": "Join", "external": True}, str(rows[2]))
    check("an empty label falls back to Join rather than an invisible row",
          rows[3]["label"] == "Join", str(rows[3]))
    log("")


# ══════════════════════════════════════════════════════════════════════ §E ══
def fetch(path):
    """GET over LOOPBACK. 127.0.0.1 is authorized by the box-local dev gate, so
    no cookie is needed and none is sent — a gated 403 is identical in both
    themes at every width and photographs as a clean pass having measured
    nothing (trap-locked-out-browser-goes-vacuously-green). The real hostname is
    kept in the Host header so SNI, the certificate and nginx's server_name all
    still match, and Cloudflare — which bot-challenges a plain public curl into
    a 403 that reads exactly like an outage — is never in the path.
    Returns (None, "") when the box cannot be reached at all."""
    import http.client, ssl
    c = ssl.create_default_context()
    c.check_hostname = False
    c.verify_mode = ssl.CERT_NONE
    try:
        conn = http.client.HTTPSConnection("127.0.0.1", 443, context=c, timeout=15)
        conn.request("GET", path, headers={"Host": "dev2.loothgroup.com"})
        resp = conn.getresponse()
        body = resp.read().decode("utf-8", "replace")
        status = resp.status
        conn.close()
        return status, body
    except Exception:                                           # noqa: BLE001
        return None, ""


def leg_e():
    log("§E  THE COUPLING — three files must name the same slug, and the box "
        "must have been told")

    hdr = php_code(HEADER)
    rtr = php_code(ROUTER)

    check("the router registers the slug the header points at",
          re.search(r"'" + SLUG + r"'\s*=>\s*\['" + SLUG + r"\.php'", rtr) is not None)
    check("the page file the registry names exists",
          os.path.isfile(os.path.join(REPO, PAGE)))
    # Prelaunch MIRRORS the menu's own audience. A menu that offers a tester a
    # door the router then shuts is the trap in its most annoying form.
    m = re.search(r"'" + SLUG + r"'\s*=>\s*\['" + SLUG + r"\.php',\s*'([a-z]+)',\s*'([a-z]+)'\]", rtr)
    check("...gated 'testgroup' pre-launch, exactly like the menu that links to it",
          bool(m) and m.group(1) == "testgroup", m.groups() if m else "no registry row")
    check("...and 'member' at go-live, since the page is addressed to a signed-in payer",
          bool(m) and m.group(2) == "member", m.groups() if m else "no registry row")

    check("the header's destination and the router's slug are the same string",
          f"'{DEST}'" in hdr and f"'{SLUG}'" in rtr and DEST.strip("/") == SLUG)

    # ⚠️ THE nginx LOCATION REGEX ENUMERATES EVERY SLUG AND HAS NO CATCH-ALL.
    for conf in NGINX:
        p = os.path.join(REPO, conf)
        if not os.path.isfile(p):
            check(f"{os.path.basename(conf)} exists", False, "missing")
            continue
        body = open(p, encoding="utf-8").read()
        loc = [l for l in body.splitlines() if l.strip().startswith("location ~ ^/(")]
        check(f"{os.path.basename(conf)}: the slug regex lists {SLUG}",
              bool(loc) and SLUG in loc[0], (loc[0][:120] if loc else "no slug location"))

    # THE BOX. The running snippet is a root-owned COPY, so the repo change does
    # NOT arrive by pull. This half REPORTS until somebody deploys it, and
    # asserts afterwards — it never goes quiet, because the repo half above is
    # unconditional and this line always prints the true state of the box.
    deployed = False
    if os.path.isfile(LIVE_SNIPPET):
        try:
            live = open(LIVE_SNIPPET, encoding="utf-8").read()
            deployed = SLUG in live
        except OSError as exc:
            report("the running nginx snippet could not be read", str(exc))
    else:
        report("no running nginx snippet on this box", LIVE_SNIPPET)

    if not deployed:
        report(f"⚠️ THE BOX DOES NOT KNOW {DEST} YET — Switch would land on a "
               f"WordPress 404",
               "sudo cp platform/nginx/strangler-membership.conf /etc/nginx/snippets/ "
               "&& sudo nginx -t && sudo systemctl reload nginx")
        report("...so the served-reachability assertion on the real slug is HELD, "
               "not skipped — it turns hard the moment that command has been run")

        # ⚠️ BUT "HELD" MUST NOT MEAN "UNMEASURED". The lane preview routes
        # /preview/196-switch-menu/switch-billing/ through the SAME router, the
        # SAME page file and the SAME membership FPM pool — only the nginx
        # location differs. So the half that is genuinely blocked on a root
        # deploy is the slug regex, and everything behind it can be proven now.
        # Asserting it here is what stops this leg being a promise: a gate that
        # stops watching the moment its subject is undeployed is not a gate
        # (gate 90 §G learned this by flipping into report mode and going quiet).
        code, body = fetch("/preview/196-switch-menu/switch-billing/")
        if code is None:
            report("the lane preview is not installed on this box, so the router "
                   "could not be exercised over HTTP at all",
                   "bash tools/preview/lane-preview.sh up 196-switch-menu")
        else:
            check("the page IS routable today — the branch's router serves it "
                  "through the real FPM pool on the lane preview",
                  code == 200 and "lg-membership-page" in body,
                  f"HTTP {code}, {len(body)} bytes")
            check("...and it is the ROUTER answering, not its no-such-surface 404",
                  "no such surface" not in body)
            # It must still REFUSE an anonymous visitor: the preview is not a
            # way around the testgroup gate, and a gate leg that proved the page
            # renders for anyone would be asserting a hole.
            check("...and it still refuses an anonymous visitor, exactly as the "
                  "registry says — the preview is not a bypass",
                  "isn&#039;t available yet" in body or "isn't available yet" in body
                  or "lg-gate" in body,
                  body[:160])
        log("")
        return

    check("the running nginx snippet lists the slug", True)
    # Answered by membership-pages, NOT by WordPress. The pre-launch stub IS a
    # 200 from the router, and that is the proof of routing — a signed-in probe
    # is not needed and would be a second thing to get wrong.
    code, body = fetch(DEST)
    if code is None:
        cannot_run(f"could not reach {DEST} over loopback")

    check(f"{DEST} is answered by membership-pages, not by WordPress",
          code == 200 and ("lg-membership-page" in body),
          f"HTTP {code}, {len(body)} bytes")
    check("...and by the ROUTER, not its no-such-surface 404",
          "no such surface" not in body)
    log("")


def main():
    log("=" * 78)
    log("GATE 93 — a Patreon payer is offered SWITCH, and Switch goes somewhere")
    log("=" * 78)
    for tool, name in ((["php", "-v"], "php"), (["node", "-v"], "node")):
        if shutil.which(tool[0]) is None:
            cannot_run(f"{name} is not on PATH")
    try:
        git("rev-parse", "--verify", "origin/main")
    except RuntimeError as exc:
        cannot_run(f"origin/main is not resolvable: {exc}")

    tmp = tempfile.mkdtemp(prefix=f"gate93-{os.getpid()}-")
    try:
        leg_a()
        R = leg_b(tmp)
        leg_c(tmp, R)
        leg_d()
        leg_e()
    finally:
        shutil.rmtree(tmp, ignore_errors=True)

    log("-" * 78)
    log(f"  {passes} passed, {failures} failed")
    if findings:
        log("\n  FINDINGS:")
        for f in findings:
            log(f"    FAIL  {f}")
        log("\n  GATE 93 RED")
        return 1
    log("  GATE 93 GREEN")
    return 0


if __name__ == "__main__":
    sys.exit(main())
