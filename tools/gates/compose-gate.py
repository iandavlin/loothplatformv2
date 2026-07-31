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


def fetch(env, path, cookie=None, method="GET", data=None):
    cmd = ["curl", "-s", "-o", "-", "-w", "\n@@%{http_code}", "-X", method]
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
        c_allow = cookie_for(args.allowed)
        c_deny = cookie_for(args.denied)

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

        # ---- 5. flag OFF is byte-identical to the recorded before-state --------
        if not os.path.exists(FIXTURE):
            print(f"note: no flag-OFF fixture yet — run --baseline BEFORE building. "
                  f"Assertion 5 skipped.")
        else:
            fx = json.load(open(FIXTURE)).get(args.type)
            if not fx:
                print(f"note: fixture has no entry for {args.type}; assertion 5 skipped.")
            elif os.environ.get("LG_COMPOSE_FLAG", "off") == "off":
                b, c = fetch(env, path)
                sha = hashlib.sha256(b.encode()).hexdigest()
                if c != fx["status"] or sha != fx["sha256"]:
                    findings.append(
                        f"[5] flag OFF is NOT a no-op: {path} was HTTP {fx['status']} / "
                        f"{fx['bytes']}B before the feature, now HTTP {c} / {len(b)}B.")

        # ---- verdict ----------------------------------------------------------
        print(f"compose-gate  type={args.type}  path={path}")
        print(f"  [1] allowed  {args.allowed:<16} form served: "
              f"{'YES' if got_allowed else 'no'}")
        print(f"  [2] denied   {args.denied:<16} form served: "
              f"{'YES (BAD)' if has_form(body_d, args.type) else 'no'}")
        print(f"  [3] anon     {'-':<16} form served: "
              f"{'YES (BAD)' if has_form(body_a, args.type) else 'no'}")
        print(f"  [4] denied POST wrote {after - before} row(s), HTTP {code_p}")
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
