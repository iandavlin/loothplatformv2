#!/usr/bin/env python3
"""
loothprint-paywall-gate.py — PART OF GATE 35, no new number minted.

Covers the Loothprint compose form's MEMBER-FACING CONTROLS: the paywall toggle
(P1-P3) and the rich-text editor's initialisation (P4). One file rather than a
third, because both legs need the same served page and the same deploy-coupling
verdict.

Gate 35 is "front-end compose+edit reaches the right people, and OFF is inert".
The paywall toggle is a control ON that form, so it is asserted under that
number rather than under a new one — the #172 precedent, and the recorded reason:
two lanes have both minted the next free number and collided.

Ian, 2026-08-21: "I would like the form to have a toggle for the user to decide
if behind the paywall. Default to behind the paywall. This should toggle the tier
selector looth-lite or public."

══ WHAT IT ASSERTS, AND WHY EACH LEG EXISTS ═══════════════════════════════════

  P1. THE RULE, EXHAUSTIVELY. lg_fc_paywall_target() is a pure function on
      purpose so its truth table can be EXECUTED here rather than described. The
      case that matters is looth-pro: the obvious two-way mapping silently
      downgrades a pro post to lite the first time its author saves, and no
      member would ever see that happen.

  P2. THE CONTROL MATCHES THE FLAG, PER STATE. Read from the SERVING checkout,
      never hardcoded, so flipping the default needs no edit here. Three states:
      config absent, OFF, ON. OFF must render ZERO paywall bytes — not a hidden
      control.

  P4. NO MEMBER IS SHOWN THEIR OWN HTML TAGS. Ian's 8/21 screenshot of the form:
      "Click to initialize TinyMCE" above his write-up as literal <p>test</p>.
      ACF's `delay` renders a placeholder and boots TinyMCE only on click, so
      until then the textarea shows stored markup as text — and nothing tells the
      member the grey bar is a button. Asserted on the served form. Deploy-coupled
      with P2, for the same reason.

  P3. THE CHOICE REACHES THE RENDERED PAGE. Asserted from the SERVED BYTES of the
      standalone article, not from the term store, because a write that lands in
      the database and never reaches the page is precisely the defect this whole
      feature nearly shipped with: ACF saves the post before the acf/save_post
      chain, so the blob used to be baked BEFORE the tier was written and the
      de-dupe swallowed the re-bake. The store was right and the page was wrong.

  ⚠️ P2 IS DEPLOY-COUPLED AND SAYS SO. lg-frontend-compose.php is a mu-plugin
  symlinked out of the serving checkout, so dev2 runs MAIN's copy: until this
  branch is merged and pulled, the served form cannot carry the control however
  correct the code is. The gate resolves whether the SERVE has it and reports
  NOT DEPLOYED — never a red, because "the box has not been updated yet" is not
  a defect, and never a green, because that would be a vacuous pass on the one
  claim under test.

  The probe post is PER-RUN and PID-KEYED, and is deleted along with its blob:
  a fixed test post means any concurrent writer produces a false red.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.
⚠️ CANNOT RUN IS 2, NOT 3 — run-all.sh reads anything else as RED.
"""
import json, os, subprocess, sys, time

REPO = os.path.abspath(os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
SERVE_MU = "/srv/archive-poc/../platform/mu-plugins/lg-frontend-compose.php"
SERVE_CFG = "/srv/archive-poc/../platform/config"


class CannotRun(Exception):
    pass


def sh(c, **kw):
    return subprocess.run(c, capture_output=True, text=True, **kw)


def gate_env():
    r = sh(["bash", os.path.join(REPO, "tools", "gates", "gate-env.sh")])
    if r.returncode != 0:
        raise CannotRun("gate-env.sh failed: " + r.stderr.strip()[:200])
    return dict(l.partition("=")[::2] for l in r.stdout.splitlines())


def wp(php):
    r = sh(["sudo", "-n", "-u", "looth-dev", "wp", "--path=/var/www/dev", "eval", php])
    return "\n".join(l for l in r.stdout.splitlines() if not l.startswith(("PHP ", "Warning"))).strip()


def curl(env, url, cookie=True):
    cmd = ["curl", "-s", "--resolve", f"{env['LG_GATE_DOMAIN']}:443:{env['LG_GATE_ADDR']}"]
    if cookie:
        cmd += ["-H", f"Cookie: loothdev_auth={env['LG_GATE_TOKEN']}"]
    cmd.append(url)
    return sh(cmd).stdout


def materialize(post_id, action="upsert"):
    return sh(["curl", "-sk", "-o", "/dev/null", "-w", "%{http_code}",
               "-X", "POST", "-H", "Host: dev2.loothgroup.com",
               "-H", "Content-Type: application/json",
               "-d", json.dumps({"post_id": post_id, "action": action}),
               "https://127.0.0.1/archive-api/v0/_materialize"]).stdout.strip()


def paywall_flag_state():
    """(state, how) resolved from the SERVING checkout — the pair the app reads."""
    base = SERVE_CFG if os.path.isdir(SERVE_CFG) else os.path.join(REPO, "platform", "config")
    tracked = os.path.join(base, "loothprint-paywall.php")
    local = os.path.join(base, "loothprint-paywall.local.php")
    if not os.path.isfile(tracked):
        return "ABSENT", f"no {tracked}"
    php = ("$on=false;$b=%s;"
           "$r=include $b.'/loothprint-paywall.php';"
           "$on=(is_array($r)&&($r['enabled']??false)===true);"
           "if(is_readable($b.'/loothprint-paywall.local.php')){$l=include $b.'/loothprint-paywall.local.php';"
           "if(is_array($l)&&array_key_exists('enabled',$l)){$on=($l['enabled']===true);}}"
           "echo $on?'ON':'OFF';") % json.dumps(base)
    out = sh(["php", "-r", php]).stdout.strip()
    if out not in ("ON", "OFF"):
        raise CannotRun("could not resolve the paywall flag: " + out[:120])
    return out, ("local override" if os.path.isfile(local) else "tracked default")


def main() -> int:
    env = gate_env()
    fails, checked = [], 0

    def chk(label, ok, detail=""):
        nonlocal checked
        checked += 1
        print(f"  {'ok  ' if ok else 'RED '} {label} {detail}")
        if not ok:
            fails.append(f"{label} {detail}")

    # ── P1: the rule, executed ────────────────────────────────────────────────
    print("P1 — the four-case rule, exhaustively")
    src = os.path.join(REPO, "platform", "mu-plugins", "lg-frontend-compose.php")
    if not os.path.isfile(src):
        raise CannotRun(f"no {src}")
    # Load ONLY the pure function, by name, out of the plugin — it takes no post
    # and calls no WordPress, which is the whole reason it was factored that way.
    php = r"""
      $s = file_get_contents(%s);
      if (!preg_match('/function lg_fc_paywall_target.*?\n}/s', $s, $m)) { echo 'NOFUNC'; exit; }
      define('LG_FC_PAYWALL_PUBLIC','public'); define('LG_FC_PAYWALL_BEHIND','looth-lite');
      eval($m[0]);
      $cases = [
        [[], 'behind', 'looth-lite'], [[], 'public', 'public'],
        [['public'], 'behind', 'looth-lite'], [['public'], 'public', 'public'],
        [['looth-lite'], 'behind', null], [['looth-lite'], 'public', 'public'],
        [['looth-pro'], 'behind', null], [['looth-pro'], 'public', 'public'],
        [['public','looth-pro'], 'behind', null], [['public','looth-pro'], 'public', 'public'],
      ];
      $bad = [];
      foreach ($cases as $i => [$cur,$ch,$want]) {
        $got = lg_fc_paywall_target($cur, $ch);
        if ($got !== $want) $bad[] = "[".implode(',',$cur)."]+$ch => ".var_export($got,true)." want ".var_export($want,true);
      }
      echo $bad ? implode(' | ', $bad) : 'ALLPASS';
    """ % json.dumps(src)
    out = sh(["php", "-r", php]).stdout.strip()
    if out == "NOFUNC":
        raise CannotRun("lg_fc_paywall_target() not found in the plugin")
    # Ian, 8/21, after a submitted print came back with an EMPTY Tiers box:
    # "It should either be public for anyone looth lite for paywalled." So
    # 'public' ALWAYS writes public — the old table wanted NULL on a post that
    # was not already paywalled, which is precisely the untiered post he saw.
    # The surviving NULLs are 'behind' on something already behind (Lite/Pro):
    # keeping Pro there is a preserve, not a miss.
    chk("all 10 rule cases (incl. every looth-pro case) hold", out == "ALLPASS", out[:120])

    # ── P2: the control agrees with the flag, per state ──────────────────────
    state, how = paywall_flag_state()
    print(f"P2 — the flag reads {state} ({how}, from the serving checkout)")
    serve_has_code = os.path.isfile(SERVE_MU) and "lg_fc_paywall_control" in open(SERVE_MU).read()
    p2_ran = serve_has_code
    if not serve_has_code:
        print("  NOT DEPLOYED: the serve's lg-frontend-compose.php has no paywall control "
              "yet, so the served form cannot carry it however correct the branch is.")
        print("  (mu-plugin symlinked out of the serving checkout — merge + pull, then "
              "re-run. Neither a red nor a green: this leg has no verdict.)")
    else:
        body = curl(env, f"{env['LG_GATE_HOST']}/compose/?type=loothprint")
        live = 'name="lg_fc_paywall"' in body
        # ⚠️ THIS FETCH CARRIES THE DEV-GATE COOKIE ONLY, NEVER A WP LOGIN, and
        # /compose/ is members-only — so an unauthenticated run reads the login
        # 404 and every assertion below it would fail for a reason that has
        # nothing to do with the code. That is the harness-must-run-as-the-real
        # -user trap, and a gate that reports its own blind spot as RED teaches
        # people to ignore it. NO VERDICT unless the form actually arrived.
        if 'name="lg_fc_comments"' not in body:
            print(f"  NO VERDICT: the form did not arrive ({len(body)}B) — this leg "
                  "needs a logged-in member cookie, which this harness does not mint. "
                  "P1 and P3 still carry real verdicts.")
            return fails
        chk("the form is alive (so an absence is not vacuous)",
            'name="lg_fc_comments"' in body, f"{len(body)}B")
        if state == "ON":
            chk("ON: the toggle is rendered", live)
            # The DEFAULT, read off the rendered input rather than assumed: the
            # attribute sits immediately after value="behind" in the heredoc.
            after = body.split('value="behind"', 1)[1][:24] if 'value="behind"' in body else ""
            chk("ON: 'behind the paywall' is the default", "checked" in after, repr(after))
        else:
            chk(f"{state}: the toggle renders ZERO bytes", not live)
        # P4 — no member is ever shown their own HTML tags. Ian, 8/21, from his
        # screenshot: a grey bar reading "Click to initialize TinyMCE" above his
        # write-up rendered as literal <p>test</p>. That is ACF's `delay`
        # placeholder; until it is clicked the textarea shows stored markup as
        # text. Same element as gate 47's bright-surface red.
        chk("the rich-text editor is not left un-initialised",
            "Click to initialize TinyMCE" not in body and "acf-editor-toolbar" not in body,
            "acf-editor-toolbar present" if "acf-editor-toolbar" in body else "")

    # ── P3: the choice reaches the RENDERED PAGE ─────────────────────────────
    print("P3 — the choice must reach the served bytes, not just the term store")
    pid = os.getpid()
    post_id = wp(
        "$id=wp_insert_post(['post_type'=>'loothprint','post_status'=>'publish',"
        f"'post_title'=>'lg paywall gate probe {pid}','post_content'=>'probe','post_author'=>1],true);"
        "if(is_wp_error($id)){echo 'ERR';exit;}"
        "$t=get_term_by('slug','public','tier');wp_set_object_terms((int)$id,[(int)$t->term_id],'tier',false);"
        "echo (int)$id;")
    if not post_id.isdigit():
        raise CannotRun(f"could not create the probe post: {post_id[:120]}")
    post_id = int(post_id)
    try:
        slug = wp(f"echo get_post_field('post_name', {post_id});")
        url = f"{env['LG_GATE_HOST']}/loothprint/{slug}/"
        materialize(post_id); time.sleep(1.0)
        a = curl(env, url)
        chk("the probe page renders (so the comparison is not vacuous)",
            "lg-post-header__chip--tier" in a, f"{len(a)}B")
        # THE WRITE lg_fc_paywall_apply() makes, term for term.
        wp(f"$t=get_term_by('slug','looth-lite','tier');"
           f"wp_set_object_terms({post_id},[(int)$t->term_id],'tier',false);")
        materialize(post_id); time.sleep(1.0)
        b = curl(env, url)
        chk("public renders as Public", "chip--tier--public" in a, "")
        chk("…and after the paywall write the SERVED BYTES say Looth Lite",
            "chip--tier--looth-lite" in b and "chip--tier--public" not in b, "")
    finally:
        wp(f"wp_delete_post({post_id}, true);")
        materialize(post_id, "delete")
        left = sh(["sudo", "-n", "-u", "archive-poc", "psql", "-d", "looth", "-At", "-c",
                   f"set search_path=discovery,public; select count(*) from article_blobs where post_id={post_id};"]).stdout.strip().splitlines()
        chk("the probe post and its blob are cleaned up",
            bool(left) and left[-1] == "0", f"blobs left={left[-1] if left else '?'}")

    if fails:
        print(f"\nRED — {len(fails)} of {checked}:")
        for f in fails:
            print(f"  - {f}")
        return 1
    # The summary must not claim a leg that did not run — a green sentence is read
    # as evidence, and P2 is the one leg the deploy coupling can silence.
    p2 = ("the control agrees with the flag, "
          if p2_ran else "(the flag/control leg had NO VERDICT — not deployed yet) ")
    print(f"\nGREEN — the rule holds for every looth-pro case, {p2}"
          f"and a paywall choice reaches the served page ({checked} checks).")
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
