#!/usr/bin/env python3
"""
compose-gate — front-end COMPOSE is reachable by the right people and by nobody else.

WRITTEN BEFORE THE FEATURE, DELIBERATELY. It is RED today, and it is supposed to be:
assertion 1 fails because no compose route exists yet. That is the point. A gate
written after the build tends to encode what the build happens to do; this one
encodes what the build has to do, and it goes green only when that is true.

WHAT IT ASSERTS, AND WHY EACH ONE IS HERE

  1. AN ALLOWED USER GETS THE FORM.            (RED today — nothing serves it)
  2. A NON-ALLOWED MEMBER DOES NOT.
  3. A SIGNED-OUT VISITOR DOES NOT.
  4. A NON-ALLOWED MEMBER'S POST IS REFUSED — and NO POST ROW IS CREATED.
  5. WITH THE FLAG OFF, THE ROUTE IS BYTE-IDENTICAL TO TODAY.

Assertions 2, 3 and 4 are ABSENCES, and an absence is the thing a green suite is
worst at seeing: with no feature built, "the member did not get a form" is true for
the most boring possible reason. That is a vacuous green, and this box has a
recorded history of them. The cure is the COUPLING — 1 is asserted in the same run,
so the gate as a whole cannot go green until the form genuinely exists AND is
genuinely refused to the right people. Neither half can pass alone.

Assertion 4 reads the DATABASE, not the HTTP response. A refused POST that still
wrote a row would answer 403 and look perfect. The store is the only witness.
(Same reasoning as the recorded "a refused save reads as preserved everything"
trap: the observable and the claim have to be different things.)

Assertion 5 is the flag law from CLAUDE.md made concrete. "Flag OFF is a
byte-identical no-op" is asserted against a fingerprint of TODAY'S response, taken
before the feature exists and committed as a fixture — so OFF is measured against
the real before-state rather than against the feature author's belief about it.

THE ROUTE IS A CONTRACT THIS GATE DECLARES, NOT AN IMPLEMENTATION IT DISCOVERED.
Nothing serves LG_COMPOSE_PATH yet. Whatever the build chooses, it satisfies this
shape or it changes this file in the same commit and says why.

⚠️ THE ROUTE SHAPE IS LOAD-BEARING — DO NOT PUT THE POST TYPE IN THE LAST PATH
SEGMENT. WordPress canonical-redirects any URL whose final segment matches a CPT
slug straight to that CPT's archive, before any handler of ours can run. Measured on
dev2, all as a logged-in admin:

    /compose/loothprint/         301 -> /loothprint/
    /post-new/loothprint/        301 -> /loothprint/
    /share/loothprint/           301 -> /loothprint/
    /new/loothprint/             301 -> /loothprint/
    /compose/?type=loothprint    404          (no handler yet — but OURS to define)
    /compose-loothprint/         404
    /hub/compose/?type=loothprint 404

A 301 is invisible in a "does the page work" check that follows redirects — you get
a 200 and a page full of loothprints, and the natural conclusion is that the form
failed to render. Hence the default below: ONE route, type as a query parameter,
which cannot collide with any CPT slug present or future.

USERS ARE PARAMETERS, NOT MECHANISM. --allowed / --denied name two real accounts and
the gate asserts BEHAVIOUR. It deliberately does not know how the allow-list is
implemented, so it survives Ian choosing a whitelist, a role, or something else.

Run:
  python3 tools/gates/compose-gate.py --type loothprint \
      --allowed <admin_login> --denied <member_login>
  python3 tools/gates/compose-gate.py --baseline      # (re)record the flag-OFF fixture

Needs sudo for wp-cli (minting a session cookie and reading the store) and the dev
gate token via tools/gates/gate-env.sh.

Exit: 0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
      Most failure modes here are environmental — no token, no such user, box
      unreachable. Reporting those as RED is indistinguishable from a regression,
      which is how a gate once sat "red" for weeks while it was in fact dead.
"""
import argparse, hashlib, json, os, subprocess, sys

REPO = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "..")
FIXTURE = os.path.join(REPO, "tools", "gates", "fixtures", "compose-flag-off.json")

# The route contract. Overridable so the build can move it in one place.
COMPOSE_PATH = os.environ.get("LG_COMPOSE_PATH", "/compose/?type={type}")

# A form is "present" only if it is the real thing: a form element AND the fields
# this post type cannot be posted without. A page that merely says the word
# "loothprint" is not a compose form.
# NB the event names are the REAL ones, verified against the field group. An
# earlier draft of this file guessed "event_start_date"; the actual name is
# "events_start_date_and_time_", trailing underscore and all. A guessed marker
# makes assertion 1 unfalsifiable — it would stay red after the form was built
# and correct, and the obvious conclusion would be that the build was broken.
REQUIRED_MARKERS = {
    "loothprint": ["<form", "loothprint_3d_file", "loothprint_more_images"],
    "event":      ["<form", "events_start_date_and_time_", "time_of_event"],
}


class CannotRun(Exception):
    pass


def sh(cmd, **kw):
    return subprocess.run(cmd, capture_output=True, text=True, **kw)


def wp_eval(php):
    r = sh(["sudo", "-n", "wp", "--allow-root", "--path=/var/www/dev", "eval", php])
    if r.returncode != 0:
        raise CannotRun(f"wp eval failed: {r.stderr.strip()[:200]}")
    return "\n".join(l for l in r.stdout.splitlines()
                     if not l.startswith(("PHP Warning:", "PHP Deprecated:", "Warning:")))


def gate_env():
    r = sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")])
    if r.returncode != 0:
        raise CannotRun("gate-env.sh could not resolve a host/token")
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines())


def cookie_for(login):
    out = wp_eval(
        f"$u = get_user_by('login', '{login}');"
        "if (!$u) { echo 'NOUSER'; exit; }"
        "$e = time() + 600;"
        "echo LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie($u->ID, $e, 'logged_in') . ';'"
        " . SECURE_AUTH_COOKIE . '=' . wp_generate_auth_cookie($u->ID, $e, 'secure_auth');"
    ).strip()
    if out == "NOUSER" or not out:
        raise CannotRun(f"no such user: {login}")
    return out


def fetch(env, path, cookie=None, method="GET", data=None, headers=False):
    cmd = ["curl", "-s", "-o", "-", "-w", "\n@@%{http_code}", "-X", method]
    if headers:
        cmd.append("-i")
    jar = f"loothdev_auth={env['LG_GATE_TOKEN']}"
    if cookie:
        jar += "; " + cookie
    cmd += ["-H", f"Cookie: {jar}"]
    if data:
        cmd += ["--data", data]
    if env.get("LG_GATE_RESOLVE"):
        cmd += env["LG_GATE_RESOLVE"].split()
    cmd.append(env["LG_GATE_HOST"] + path)
    r = sh(cmd)
    body, _, code = r.stdout.rpartition("\n@@")
    return body, (int(code) if code.isdigit() else 0)


def has_form(body, ptype):
    markers = REQUIRED_MARKERS.get(ptype, ["<form"])
    return all(m in body for m in markers)


def flag_state():
    """
    READ the flag; never assume it. Two recorded traps make the env var the wrong
    source: sudo strips the environment, so a flag-ON run can silently exercise
    the OFF path and report on the wrong one; and this box has had a gate sit
    "red" for weeks while it was in fact dead. LG_COMPOSE_FLAG still overrides,
    but only to force a state deliberately — the default is the truth.
    """
    forced = os.environ.get("LG_COMPOSE_FLAG")
    if forced in ("on", "off"):
        return forced, "forced by LG_COMPOSE_FLAG"
    out = wp_eval("echo defined('LG_FRONTEND_COMPOSE') ? "
                  "(LG_FRONTEND_COMPOSE ? 'on' : 'off') : 'absent';").strip()
    if out not in ("on", "off", "absent"):
        raise CannotRun(f"could not read LG_FRONTEND_COMPOSE (got {out!r})")
    return out, "read from the box"


def count_posts(ptype):
    return int(wp_eval(
        f"global $wpdb; echo (int)$wpdb->get_var($wpdb->prepare("
        f"\"SELECT COUNT(*) FROM {{$wpdb->posts}} WHERE post_type=%s\", '{ptype}'));"
    ).strip() or "0")


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--type", default="loothprint")
    ap.add_argument("--allowed", help="login of a user who SHOULD get the form")
    ap.add_argument("--denied", help="login of a member who should NOT")
    ap.add_argument("--owner", help="login of a member who OWNS --post (edit mode)")
    ap.add_argument("--stranger", help="login of a member who does NOT own --post")
    ap.add_argument("--post", type=int, default=0, help="an existing post id to edit")
    ap.add_argument("--baseline", action="store_true",
                    help="record the flag-OFF fingerprint fixture and exit")
    args = ap.parse_args()

    try:
        env = gate_env()
        path = COMPOSE_PATH.format(type=args.type)

        # ---- assertion 5's fixture ------------------------------------------
        if args.baseline:
            body, code = fetch(env, path)
            os.makedirs(os.path.dirname(FIXTURE), exist_ok=True)
            prior = {}
            if os.path.exists(FIXTURE):
                prior = json.load(open(FIXTURE))
            prior[args.type] = {
                "path": path, "status": code,
                "sha256": hashlib.sha256(body.encode()).hexdigest(),
                "bytes": len(body),
            }
            json.dump(prior, open(FIXTURE, "w"), indent=2, sort_keys=True)
            print(f"baseline recorded for {args.type}: HTTP {code}, {len(body)}B -> {FIXTURE}")
            print("This is the BEFORE state. Flag OFF must reproduce it exactly.")
            return 0

        if not args.allowed or not args.denied:
            raise CannotRun("--allowed and --denied are both required (or use --baseline)")

        findings = []
        state, how = flag_state()
        c_allow = cookie_for(args.allowed)
        c_deny = cookie_for(args.denied)

        # ── FLAG OFF / ABSENT: the ONLY correct behaviour is the before-state ──
        # Asserted for an ALLOWED user, not for anon. Anon is a 404 whether the
        # flag is on or off, so an anon-only probe cannot tell the two apart and
        # goes green either way — which is how assertion 5 first passed against a
        # flag that was ON. The allowed user is the one whose response actually
        # changes, so they are the witness.
        if state in ("off", "absent"):
            b, c = fetch(env, path, c_allow)
            print(f"compose-gate  type={args.type}  path={path}")
            print(f"  flag: {state.upper()} ({how}) — asserting the NO-OP, not the feature")
            if has_form(b, args.type):
                findings.append(
                    f"[5] the flag is {state.upper()} but an allowed user WAS served "
                    f"the compose form at {path}. OFF must be inert.")
            if not os.path.exists(FIXTURE):
                findings.append("[5] no flag-OFF fixture — run --baseline on a tree "
                                "WITHOUT the feature; OFF cannot be checked against a "
                                "belief, only against a recorded before-state.")
            else:
                fx = json.load(open(FIXTURE)).get(args.type)
                if not fx:
                    findings.append(f"[5] fixture has no entry for {args.type}.")
                else:
                    sha = hashlib.sha256(b.encode()).hexdigest()
                    print(f"  [5] allowed user gets HTTP {c} / {len(b)}B "
                          f"(before: {fx['status']} / {fx['bytes']}B)")
                    if c != fx["status"] or sha != fx["sha256"]:
                        findings.append(
                            f"[5] flag {state.upper()} is NOT byte-identical: {path} was "
                            f"HTTP {fx['status']} / {fx['bytes']}B before the feature, "
                            f"now HTTP {c} / {len(b)}B.")
            print()
            if findings:
                print(f"{len(findings)} FINDING(S):")
                for f in findings:
                    print(f"  \u2717 {f}")
                return 1
            print("GREEN — the flag is off and the route is byte-identical to before it existed.")
            return 0

        # ---- 1. an allowed user GETS the form (RED until the build exists) ----
        body, code = fetch(env, path, c_allow)
        got_allowed = code == 200 and has_form(body, args.type)
        if not got_allowed:
            findings.append(
                f"[1] allowed user {args.allowed!r} did NOT get a compose form at {path} "
                f"(HTTP {code}, {len(body)}B). Expected 200 with "
                f"{REQUIRED_MARKERS.get(args.type)}.")

        # ---- 2/3. the absences ------------------------------------------------
        body_d, code_d = fetch(env, path, c_deny)
        if has_form(body_d, args.type):
            findings.append(
                f"[2] NON-ALLOWED member {args.denied!r} WAS SERVED the compose form "
                f"at {path} (HTTP {code_d}). This is the escalation this gate exists for.")

        body_a, code_a = fetch(env, path)
        if has_form(body_a, args.type):
            findings.append(f"[3] a SIGNED-OUT visitor was served the compose form at {path}.")

        # ---- 4. a refused POST must not write ---------------------------------
        before = count_posts(args.type)
        _, code_p = fetch(env, path, c_deny, method="POST",
                          data=f"post_title=compose-gate+probe&post_type={args.type}")
        after = count_posts(args.type)
        if after != before:
            findings.append(
                f"[4] a POST by non-allowed member {args.denied!r} CREATED "
                f"{after - before} {args.type} row(s) (HTTP {code_p}). "
                f"The store is the witness, not the status code.")
        if 200 <= code_p < 300:
            findings.append(
                f"[4] a POST by non-allowed member {args.denied!r} returned {code_p}; "
                f"a refusal must not be 2xx even when nothing was written.")

        # ---- 6/7/8. EDIT: the owner gets it, a stranger does not, and a
        # stranger's POST writes nothing. Added with the edit path, red-first:
        # it was written and run BEFORE the ownership check was trusted.
        #
        # Edit is the half where "refused" and "silently wrote anyway" look
        # identical from outside, so 8 reads post_modified rather than the status
        # code — a refusal that still saved would answer 403 and look perfect.
        edit_note = ""
        if args.post and args.owner and args.stranger:
            epath = f"/compose/?id={args.post}"
            c_owner = cookie_for(args.owner)
            c_strange = cookie_for(args.stranger)

            b_o, code_o = fetch(env, epath, c_owner)
            got_owner = code_o == 200 and has_form(b_o, args.type)
            if not got_owner:
                findings.append(
                    f"[6] the OWNER {args.owner!r} did not get an edit form at {epath} "
                    f"(HTTP {code_o}, {len(b_o)}B).")

            b_s, code_s = fetch(env, epath, c_strange)
            if has_form(b_s, args.type):
                findings.append(
                    f"[7] a STRANGER {args.stranger!r} WAS SERVED the edit form for "
                    f"post {args.post} (HTTP {code_s}). This is the IDOR the "
                    f"ownership check exists for.")

            # NB deliberately NOT named before/after — those hold assertion 4's
            # row COUNTS and shadowing them made this crash on a subtraction.
            # It surfaced loudly, which is the good version of that mistake.
            mod_before = wp_eval(f"echo get_post_field('post_modified', {args.post});").strip()
            _, code_ep = fetch(env, epath, c_strange, method="POST",
                               data=f"acf[_post_title]=compose-gate+stranger+edit")
            mod_after = wp_eval(f"echo get_post_field('post_modified', {args.post});").strip()
            if mod_before != mod_after:
                findings.append(
                    f"[8] a stranger's POST CHANGED post {args.post} "
                    f"(post_modified {mod_before!r} -> {mod_after!r}). The store is "
                    f"the witness, not the status code.")
            if 200 <= code_ep < 300:
                findings.append(
                    f"[8] a stranger's edit POST returned {code_ep}; a refusal must "
                    f"not be 2xx even when nothing was written.")
            edit_note = (f"  [6] owner    {args.owner:<16} edit form: "
                         f"{'YES' if got_owner else 'no'}\n"
                         f"  [7] stranger {args.stranger:<16} edit form: "
                         f"{'YES (BAD)' if has_form(b_s, args.type) else 'no'}\n"
                         f"  [8] stranger POST: HTTP {code_ep}, post_modified "
                         f"{'UNCHANGED' if mod_before == mod_after else 'CHANGED (BAD)'}\n")

        # ---- 9. EMBED: the toggle's contract with this route ------------------
        # The hub composer's type toggle loads this in a same-origin iframe, so
        # two things have to hold and neither is visible from the normal render:
        # the furniture-free variant must actually apply (not just exist in the
        # CSS — an earlier check of mine counted the stylesheet text and "passed"
        # on the non-embed page), and the response must be FRAMEABLE. An
        # X-Frame-Options of DENY would break the toggle with an empty box and no
        # error anywhere.
        b_e, code_e = fetch(env, path + "&embed=1", c_allow, headers=True)
        if not has_form(b_e, args.type):
            findings.append(f"[9] embed=1 did not serve the form (HTTP {code_e}).")
        if 'class="lgfc-body lgfc-body--embed"' not in b_e:
            findings.append("[9] embed=1 served the form but WITHOUT the embed body "
                            "class — the page furniture is still on it.")
        xfo = ""
        for line in b_e.splitlines():
            if line.lower().startswith("x-frame-options:"):
                xfo = line.split(":", 1)[1].strip().upper()
        if xfo == "DENY":
            findings.append("[9] X-Frame-Options: DENY — the composer cannot frame "
                            "this, and the failure is a silent empty box.")
        # and the non-embed render must NOT carry the class
        b_n, _ = fetch(env, path, c_allow)
        if 'lgfc-body--embed' in b_n.split("<main")[0].split("<body")[-1]:
            findings.append("[9] the NON-embed render carries the embed body class.")
        embed_note = (f"  [9] embed    form: {'YES' if has_form(b_e, args.type) else 'no'}, "
                      f"body class applied: "
                      f"{'YES' if 'lgfc-body lgfc-body--embed' in b_e else 'no'}, "
                      f"X-Frame-Options: {xfo or '(none)'}\n")

        # ---- verdict ----------------------------------------------------------
        print(f"compose-gate  type={args.type}  path={path}")
        print(f"  flag: ON ({how}) — asserting the FEATURE")
        print(f"  [1] allowed  {args.allowed:<16} form served: "
              f"{'YES' if got_allowed else 'no'}")
        print(f"  [2] denied   {args.denied:<16} form served: "
              f"{'YES (BAD)' if has_form(body_d, args.type) else 'no'}")
        print(f"  [3] anon     {'-':<16} form served: "
              f"{'YES (BAD)' if has_form(body_a, args.type) else 'no'}")
        print(f"  [4] denied POST wrote {after - before} row(s), HTTP {code_p}")
        if edit_note:
            print(edit_note, end="")
        print(embed_note, end="")
        print()
        if findings:
            print(f"{len(findings)} FINDING(S):")
            for f in findings:
                print(f"  ✗ {f}")
            if not got_allowed and len(findings) == 1:
                print("\n  ^ This is the EXPECTED red-first state: the feature does not")
                print("    exist yet, so [1] fails and the absences pass for the boring")
                print("    reason. They are coupled on purpose — the gate cannot go")
                print("    green until the form exists AND is correctly refused.")
            return 1
        print("GREEN — the form reaches the allowed user, and nobody else.")
        return 0

    except CannotRun as e:
        print(f"CANNOT RUN — {e}", file=sys.stderr)
        print("No verdict. This is not a red.", file=sys.stderr)
        return 2


if __name__ == "__main__":
    sys.exit(main())
