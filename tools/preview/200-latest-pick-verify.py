#!/usr/bin/env python3
"""#200-latest-pick — verify on the RENDERED ADMIN FLOW that a fresh pool pick
visibly takes the front page.

Keeper asked for this specifically, and it is a different question from the
gate: the gate proves the resolver draws a selection, this proves the JOURNEY —
the row Ian is looking at in wp-admin, the words on it, the button on it, and
then the front page that results from clicking it.

⚠️ IT RENDERS THE BRANCH, TWICE OVER, AND NEITHER RENDER TOUCHES THE SERVE.
  · the DASH runs under real WordPress with the branch's class required before
    WP boots (lg-layout-v2 has a LAZY PSR-4 autoloader, so a plain include would
    let the serving checkout's copy — main — answer instead). ReflectionClass
    reports which file actually loaded and this asserts that path.
  · the FRONT PAGE runs this worktree's index.php under php-cli with
    LG_ARCHIVE_POC_CONFIG_JSON pre-defined at a temp file.
  · the pool payload is STUBBED through pre_http_request, because the dash's
    loopback URL is routed by nginx into the serving checkout — a live call
    would measure main's endpoint.

⚠️ AND IT DOES NOT CLICK THE REAL BUTTON, WHICH IS SAID PLAINLY RATHER THAN
IMPLIED. handle_feature() redirects and exits, and driving it for real would
write the live config.json that dev2's own front page serves. So the payload is
taken from the handler by reading it, and the MERGE is the one _config.php
performs (`$clean + $existing`, left wins). Everything downstream of that merge
is really rendered.
"""
import json, os, re, subprocess, sys, tempfile

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
IDX  = os.path.join(REPO, "archive-poc", "web", "index.php")
DASH = os.path.join(REPO, "lg-layout-v2", "src", "FeaturedMemberDash.php")
TMP  = tempfile.mkdtemp(prefix="lp200v-"); os.chmod(TMP, 0o755)
RED, OK = [], []

# A member whose card is THIN: opted in, public, has a photo, but nothing public
# to say — the exact shape the old guard threw away.
THIN = {"uuid": "44444444-4444-4444-8444-444444444444", "slug": "thin-one",
        "display_name": "Thin One", "avatar_url": "/a.jpg", "location": "",
        "eligible": True, "opted_in": True, "status": "consented",
        "profile_url": "/u/thin-one", "has_photo": True, "public_role": "",
        "glance_members_only": False,
        "card_renderable": True, "card_blockers": ["what_you_do"],
        "completeness": {"card_ready": False, "next": None, "percent": 40}}
FULL = dict(THIN, uuid="55555555-5555-4555-8555-555555555555", slug="full-one",
            display_name="Full One", public_role="Bench Work", card_blockers=[])

payload = {"pool": [THIN, FULL], "candidates": [],
           "candidate_counts": {"all": 0, "consented": 0, "never": 0, "private": 0},
           "status": "all"}

# ── 1. THE DASH, RENDERED ───────────────────────────────────────────────────
boot = os.path.join(TMP, "boot.php")
open(boot, "w").write("<?php require_once %r;\n" % DASH)
rp = os.path.join(TMP, "dash.php")
open(rp, "w").write("""<?php
use LG\\LayoutV2\\FeaturedMemberDash;
$r = new ReflectionClass(FeaturedMemberDash::class);
fwrite(STDERR, "LOADED:" . $r->getFileName() . "\\n");
add_filter('pre_http_request', function ($pre, $args, $url) {
    if (strpos($url, 'featured-pool') !== false) {
        return ['headers'=>[], 'body'=>getenv('POOL_JSON'),
                'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[], 'filename'=>null];
    }
    if (strpos($url, '_featured-history') !== false) {
        return ['headers'=>[], 'body'=>'{"history":[]}',
                'response'=>['code'=>200,'message'=>'OK'], 'cookies'=>[], 'filename'=>null];
    }
    return $pre;
}, 10, 3);
$a = get_users(['role'=>'administrator','number'=>1]);
if (!$a) { fwrite(STDERR, "NOADMIN\\n"); exit(1); }
wp_set_current_user($a[0]->ID);
$_GET['page'] = 'lg-featured-member';
ob_start(); FeaturedMemberDash::render_page(); echo ob_get_clean();
""")
for f in (boot, rp): os.chmod(f, 0o644)
p = subprocess.run(["sudo", "-n", "-u", "looth-dev", "env",
                    "POOL_JSON=" + json.dumps(payload),
                    "wp", "eval-file", rp, "--require=" + boot,
                    "--path=/var/www/dev", "--skip-themes"],
                   capture_output=True, text=True, timeout=240)
html = p.stdout
if "LOADED:" + DASH not in p.stderr:
    print("CANNOT RUN: the dash that loaded was not this branch's —",
          [l for l in p.stderr.splitlines() if l.startswith("LOADED:")] or p.stderr[-200:])
    sys.exit(2)
if "Featured Member" not in html:
    print("CANNOT RUN: the dash rendered nothing usable:", (p.stderr or html)[-250:]); sys.exit(2)
print("dash rendered from the BRANCH:", DASH)

row = re.search(r"Thin One.{0,1600}", html, re.S)
seg = row.group(0) if row else ""
if "Won’t show yet" in html or "Won't show yet" in html:
    RED.append("the dash still tells Ian a pick “Won’t show yet” — the front page draws every "
               "selection now, so that column is the disappearance restated in words")
else:
    OK.append("the dash never says “Won’t show yet”")
if "Thin card" in seg:
    OK.append("a thin member's Card column reads “Thin card”, describing what he will get")
else:
    RED.append("a thin member's Card column does not describe the card at all: "
               + re.sub(r"\s+", " ", seg[:200]))
# ⚠️ THE ACTION NAME IS READ FROM THE CLASS, NOT GUESSED. The first version of
# this check looked for "lg_fm_feature" and went RED on a perfectly good dash —
# the constant is FEATURE_ACTION = 'lg_featured_member_feature'. A hardcoded
# guess at another file's identifier is the same false-red family as gate 39's
# §C3 comment scan, and it cost this run a diagnosis.
_dash_src = open(DASH, encoding="utf-8").read()
_act = re.search(r"const FEATURE_ACTION\s*=\s*'([^']+)'", _dash_src)
if not _act:
    RED.append("could not read FEATURE_ACTION from the dash class — cannot check for its button")
elif _act.group(1) in seg:
    OK.append(f"a thin member still has a live Feature button ({_act.group(1)}) — nothing "
              f"refuses a pick")
else:
    RED.append(f"a thin member has no {_act.group(1)} button in the rendered dash")

# ── 2. THE CLICK'S PAYLOAD, TAKEN FROM THE HANDLER ─────────────────────────
src = open(DASH, encoding="utf-8").read()
blk = re.search(r"public static function handle_feature\(\).*?post_config\(\[(.*?)\]\)", src, re.S)
if not blk:
    print("CANNOT RUN: could not read handle_feature()'s payload"); sys.exit(2)
body = blk.group(1)
if re.search(r"'pinned'\s*=>\s*false", body):
    OK.append("handle_feature() writes pinned => false explicitly, so a stale pin cannot survive")
else:
    RED.append("handle_feature() does not write pinned => false — an omitted key persists through "
               "PHP's `+`, so a previous pin would outrank this click")

# ── 3. THE FRONT PAGE THAT RESULTS ─────────────────────────────────────────
def render_front(fm):
    cfg = json.load(open("/home/ubuntu/projects/archive-poc/config.json"))
    cfg["featured_member"] = fm
    c = os.path.join(TMP, "config.json"); r = os.path.join(TMP, "front.php")
    json.dump(cfg, open(c, "w"))
    open(r, "w").write("<?php define('LG_ARCHIVE_POC_CONFIG_JSON', %r);\nrequire %r;\n" % (c, IDX))
    os.chmod(c, 0o644); os.chmod(r, 0o644)
    q = subprocess.run(["sudo", "-n", "-u", "archive-poc", "env",
                        "LG_FEATURED_MEMBERS=1", "php", r],
                       capture_output=True, text=True, timeout=180)
    return q.stdout

def psql(sql):
    q = subprocess.run(["sudo", "-n", "-u", "postgres", "psql", "-d", "profile_app",
                        "-At", "-F", "|", "-c", sql], capture_output=True, text=True, timeout=60)
    return q.stdout.strip()

# A REAL member the old guard refused: opted in, public, empty resolved role.
row = psql("""SELECT uuid, display_name FROM users
               WHERE profile_visibility='public' AND featured_opt_in
                 AND btrim(coalesce(at_a_glance,''))=''
                 AND (coalesce(business_name,'')='' OR display_name LIKE '%'||business_name)
               ORDER BY id LIMIT 1""")
if not row:
    print("NOTE: no real thin opted-in member on this box; front-page leg not run")
else:
    uuid, name = row.split("|")[0], row.split("|")[1]
    standing = {"enabled": True, "member_uuid": "ffffffff-0000-4000-8000-000000000000",
                "name": "Someone Else", "pinned": True, "consent_ack": True}
    # exactly what _config.php does: $clean + $existing, left wins
    merged = {"enabled": True, "member_uuid": uuid, "name": name, "role": "",
              "consent_ack": False, "pinned": False, "chosen_by": "verify"}
    for k, v in standing.items(): merged.setdefault(k, v)
    out = render_front(merged)
    drawn = (re.search(r'lg-fm__name">([^<]*)', out) or [None, ""])[1]
    live = "class=\"rows\"" in out and len(out) > 20000
    if not live:
        RED.append("the front page did not render (%d bytes) — the result below would be vacuous"
                   % len(out))
    elif drawn.strip() == name.strip():
        OK.append(f"a fresh pool pick of a THIN member ({name!r}) takes the front page, "
                  f"displacing a standing pin")
    else:
        RED.append(f"a fresh pool pick of {name!r} did NOT take the front page — it drew "
                   f"{drawn!r}, which is exactly what Ian reported")

print()
for m in OK:  print("  ok   " + m)
for m in RED: print("  RED  " + m)
print()
if RED:
    print("200-latest-pick-verify: RED — %d finding(s)" % len(RED)); sys.exit(1)
print("200-latest-pick-verify: GREEN — %d assertions across the rendered admin flow" % len(OK))
