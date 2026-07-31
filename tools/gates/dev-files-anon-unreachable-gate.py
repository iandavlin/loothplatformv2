#!/usr/bin/env python3
"""
dev-files-anon-unreachable-gate — lane tooling inside a deployed tree must be
UNREACHABLE by an anonymous request.

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found twice becomes a gate).

Found twice, both times as source/behaviour served to anybody who asked:

  1. 2026-07-30  /archive-api/v0/*.php returned PHP SOURCE on dev2 AND live. Any
     .php without its own nginx location fell through the parent alias and was
     served as a static file. (memory: trap-api-v0-php-source-disclosure)
  2. 2026-07-31  lg-weekly-digest/dev/ — 58 files of lane tooling sitting inside a
     PLUGIN directory, i.e. inside the docroot. Not source disclosure this time:
     the catch-all `location ~ \\.php$` handed them to FPM and they RAN.

What an unauthenticated GET actually did in (2), measured against the gate-free
(live-posture) config on dev2:

    dev/preview/signup-preview.php    booted WordPress via wp-load.php and
                                      rendered a real 20,944-byte page
    dev/verify-missed-exclusions.php  loaded /srv/profile-app/config.php, opened a
                                      PostgreSQL connection and QUERIED the
                                      `notifications` table. It failed on one
                                      Postgres GRANT — nothing about the code, the
                                      path, or nginx stopped it
    dev/verify-recap-flag-off.php     ran to completion, shell_exec()'d git, and
                                      printed the digest's flag internals
    dev/render-0727-reconstruction.php  eval()'d a file read from world-writable
                                      /tmp (no open_basedir, PrivateTmp=no)
    run-suite.sh, measure-*.sh        served verbatim as readable source

THE ASSERTION IS ANONYMOUS REACHABILITY, NOT ABSENCE.

"The file is not in my checkout" is not a pass — the whole defect was prospective,
about what the NEXT deploy creates. So this gate asks the only question that
matters: if I am an anonymous visitor, what do I get? Every assertion is an HTTP
status from a request carrying no cookies and no credentials.

WHY IT STARTS ITS OWN ORIGIN, AND WHY THAT IS THE LOAD-BEARING PART.

dev2's gate is ARMED: `if ($loothdev_is_authorized = 0) { return 403; }` fires at
server level for every unauthenticated request. Point this gate at dev2:443 as a
true anonymous client and EVERY url returns 403 — including the ones that are
wide open — and 403 is a PASS here. The gate would go green precisely because it
could not see anything. That is the same failure as craft gate 2 reporting GREEN
with 0KB against a dead origin (memory: trap-craft-gate-unrunnable-on-dev2).

So it builds a throwaway nginx on a spare port from the REPO vhost plus the REPO's
gate-free platform/nginx/loothdev-auth.conf (`default 1` — the posture live runs),
and asks it. Same docroot, same FPM socket, no gate. If it cannot build that
origin it exits 2 (CANNOT RUN), never 0.

THREE CONTROLS, because an all-404 result is vacuous on its own:

  LIVENESS   /  and /hub/ must be 200. Against a dead origin everything 404s and
             the deny assertions would all "pass".
  POSITIVE   a real plugin asset (elementor css) must be 200. This proves plugin
             trees are served at all — without it, "the tooling 404s" is equally
             true of a config that 404s the entire plugin directory.
  NEGATIVE   every discovered lane-tooling file must be 404/403.

DISCOVERY GOES DEEPER THAN THE NGINX RULE ON PURPOSE.

The nginx deny is scoped to directories DIRECTLY under a plugin root, because
matching at any depth would also hit woocommerce/assets/js/frontend/test, which
pages actually load. This gate scans at ANY depth. If someone adds
lg-foo/includes/dev/x.php the nginx pattern will not cover it and this gate goes
RED — which is the correct outcome: the rule needs widening, deliberately, with
the false-positive check redone.

Exit codes follow run-all.sh: 0 green, 1 RED, 2 CANNOT RUN.
"""

import os
import re
import shutil
import subprocess
import sys
import time
import urllib.error
import urllib.request

REPO = os.path.realpath(os.path.join(os.path.dirname(__file__), "..", ".."))
DOCROOT = "/var/www/dev"
HOST = "dev2.loothgroup.com"
WORKDIR = "/tmp/lg-dev-files-gate"

# Directory names that mean "not for the public". Kept in sync with the nginx
# location in platform/nginx/dev2.loothgroup.com.conf.
CLASS_DIRS = re.compile(
    r"^(?:dev|tests?|bin|fixtures?|spec|__tests__|previs|sandbox|scratch)$", re.I
)

# Extensions worth asking for. .php is the dangerous one; the rest proved
# disclosable as source/fixtures.
PROBE_EXT = (".php", ".sh", ".html", ".css", ".js", ".json")

# THE GATE MIRRORS THE CONTROL IT GUARDS, and the control is two rules:
#   directly under a plugin root -> ANY extension must be blocked
#   deeper                       -> only things that execute, are script source,
#                                   or are bulk fixture data
# .js/.css at depth are exempt because a directory called test/ that deep is
# usually a frontend ASSET dir — woocommerce/assets/js/frontend/test/*.js is
# shipped to browsers. Blocking those would break a page to tidy up a URL space.
# If this exemption ever hides a real defect, the answer is to narrow it here AND
# widen the nginx rule in the same commit — never one without the other.
DEEP_EXT = (".php", ".sh", ".bash", ".py", ".rb", ".pl", ".phtml", ".inc", ".json")


def say(msg):
    print(msg, flush=True)


def cannot_run(msg):
    say(f"CANNOT RUN: {msg}")
    sys.exit(2)


def free_port(start=8940):
    """Pick a port nobody holds. Never hardcode — a busy port silently measures
    another lane's listener (memory: the 8899 collision)."""
    import socket

    for p in range(start, start + 40):
        with socket.socket() as s:
            try:
                s.bind(("127.0.0.1", p))
                return p
            except OSError:
                continue
    cannot_run("no free loopback port in 8940-8979")


def build_origin(port):
    """Throwaway nginx: repo vhost + repo GATE-FREE auth conf, real docroot."""
    src = os.path.join(REPO, "platform", "nginx", "dev2.loothgroup.com.conf")
    authfree = os.path.join(REPO, "platform", "nginx", "loothdev-auth.conf")
    for f in (src, authfree):
        if not os.path.isfile(f):
            cannot_run(f"missing {f}")

    text = open(src).read()
    # Take the LAST top-level server block (the TLS one); brace-match it.
    starts = [m.start() for m in re.finditer(r"^server\s*\{", text, re.M)]
    if not starts:
        cannot_run("no server block in the repo vhost")
    i = starts[-1]
    depth, j = 0, i
    while True:
        if text[j] == "{":
            depth += 1
        elif text[j] == "}":
            depth -= 1
            if depth == 0:
                j += 1
                break
        j += 1
    b = text[i:j]
    b = re.sub(r"^\s*listen .*?;\s*$", "", b, flags=re.M)
    b = re.sub(r"^\s*ssl_\w+\s+.*?;\s*$", "", b, flags=re.M)
    b = re.sub(r"^\s*include /etc/letsencrypt/.*?;\s*$", "", b, flags=re.M)
    b = re.sub(r"^\s*add_header Strict-Transport-Security.*?;\s*$", "", b, flags=re.M)
    b = b.replace("server {", "server {\n    listen 127.0.0.1:%d;\n" % port, 1)
    b = re.sub(r"^\s*access_log /var/log/nginx/.*?;\s*$",
               f"    access_log {WORKDIR}/access.log;", b, flags=re.M)
    b = re.sub(r"^\s*error_log  /var/log/nginx/.*?;\s*$",
               f"    error_log {WORKDIR}/vhost-error.log;", b, flags=re.M)
    # nginx pins its prefix to the config's directory, so relative includes must
    # be absolute or shimmed.
    b = re.sub(r"^(\s*)include (fastcgi_params|fastcgi\.conf);",
               r"\1include /etc/nginx/\2;", b, flags=re.M)
    b = re.sub(r"^(\s*)include snippets/", r"\1include /etc/nginx/snippets/", b, flags=re.M)

    shutil.rmtree(WORKDIR, ignore_errors=True)
    os.makedirs(os.path.join(WORKDIR, "tmp"), exist_ok=True)
    os.chmod(WORKDIR, 0o755)
    open(os.path.join(WORKDIR, "vhost.conf"), "w").write(b)
    for name in ("fastcgi.conf", "fastcgi_params", "snippets", "mime.types"):
        link = os.path.join(WORKDIR, name)
        if not os.path.lexists(link):
            os.symlink(os.path.join("/etc/nginx", name), link)

    clean = "/home/ubuntu/loothplatformv2-clean/platform/nginx"
    open(os.path.join(WORKDIR, "nginx.conf"), "w").write(f"""
worker_processes 1;
error_log {WORKDIR}/error.log warn;
pid {WORKDIR}/nginx.pid;
user www-data;
events {{ worker_connections 64; }}
http {{
    include /etc/nginx/mime.types;
    default_type application/octet-stream;
    client_body_temp_path {WORKDIR}/tmp/body;
    fastcgi_temp_path     {WORKDIR}/tmp/fcgi;
    proxy_temp_path       {WORKDIR}/tmp/proxy;
    uwsgi_temp_path       {WORKDIR}/tmp/uwsgi;
    scgi_temp_path        {WORKDIR}/tmp/scgi;
    include {authfree};
    include {clean}/lg-write-freeze-map.conf;
    include {clean}/lg-microcache.conf;
    include {clean}/loothdev-ratelimit.conf;
    include {WORKDIR}/vhost.conf;
}}
""")

    conf = os.path.join(WORKDIR, "nginx.conf")
    t = subprocess.run(["sudo", "nginx", "-p", "/etc/nginx", "-c", conf, "-t"],
                       capture_output=True, text=True)
    if t.returncode != 0:
        cannot_run("harness nginx config failed -t:\n" + t.stderr)
    up = subprocess.run(["sudo", "nginx", "-p", "/etc/nginx", "-c", conf],
                        capture_output=True, text=True)
    if up.returncode != 0:
        cannot_run("harness nginx would not start:\n" + up.stderr)
    time.sleep(1.0)
    return conf


def stop_origin(conf):
    subprocess.run(["sudo", "nginx", "-p", "/etc/nginx", "-c", conf, "-s", "stop"],
                   capture_output=True, text=True)


def get(port, path):
    """An ANONYMOUS request: no cookies, no auth, no dev-gate token."""
    req = urllib.request.Request(f"http://127.0.0.1:{port}{path}",
                                 headers={"Host": HOST, "User-Agent": "lg-dev-files-gate"})
    try:
        with urllib.request.urlopen(req, timeout=25) as r:
            return r.status, r.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()
    except Exception as e:
        return None, str(e).encode()


PER_DIR_CAP = 12


def discover():
    """Lane-tooling files inside trees that are served off the docroot.

    Enumerates the REAL docroot (following the deploy symlinks) rather than the
    repo, because what deploys is the symlink set, not the checkout (memory:
    trap-live-reachability-is-a-symlink-question).

    Shells out through sudo on purpose: wp-content/plugins is drwxrws--- and a
    plain os.walk as the invoking user yields NOTHING while raising nothing. That
    silent-empty is precisely how this gate would have gone green having tested
    zero files."""
    names = "|".join(["dev", "tests?", "bin", "fixtures?", "spec", "__tests__",
                      "previs", "sandbox", "scratch"])
    found, capped = [], []
    for parent in ("plugins", "mu-plugins"):
        base = os.path.join(DOCROOT, "wp-content", parent)
        r = subprocess.run(
            ["sudo", "find", base, "-mindepth", "2", "-type", "d",
             "-regextype", "posix-extended", "-iregex", rf".*/({names})$",
             "-not", "-path", "*/node_modules/*"],
            capture_output=True, text=True)
        if r.returncode != 0 and not r.stdout.strip():
            cannot_run(f"could not enumerate {base}: {r.stderr.strip()[:200]}")
        for d in sorted(x for x in r.stdout.splitlines() if x.strip()):
            # Is the class dir DIRECTLY under the plugin root, or deeper?
            shallow = len(os.path.relpath(d, base).split(os.sep)) == 2
            exts = PROBE_EXT if shallow else DEEP_EXT
            rf = subprocess.run(
                ["sudo", "find", d, "-type", "f"], capture_output=True, text=True)
            files = [f for f in sorted(rf.stdout.splitlines())
                     if f.lower().endswith(exts)]
            if len(files) > PER_DIR_CAP:
                capped.append((d, len(files)))
                files = files[:PER_DIR_CAP]
            for f in files:
                found.append("/wp-content/" + parent + "/" +
                             os.path.relpath(f, base).replace(os.sep, "/"))
    # No silent caps: say what was dropped, or a partial sweep reads as a full one.
    for d, n in capped:
        say(f"  note: sampling {PER_DIR_CAP} of {n} probe-able files in "
            f"{d.replace(DOCROOT, '')}")
    return found


def main():
    if not os.path.isdir(DOCROOT):
        cannot_run(f"no docroot at {DOCROOT} — this gate must run on the platform box")

    port = free_port()
    say(f"anonymous origin: gate-free repo vhost on 127.0.0.1:{port} (live posture)")
    conf = build_origin(port)
    fail = 0
    try:
        # ---- CONTROL 1: LIVENESS. Everything 404s against a dead origin. ----
        for path in ("/", "/hub/"):
            code, _ = get(port, path)
            if code != 200:
                stop_origin(conf)
                cannot_run(f"liveness {path} returned {code}, not 200 — origin is not "
                           "serving, so no absence assertion below would mean anything")
        say("liveness            : / and /hub/ are 200")

        # ---- CONTROL 2: the gate must really be OFF, or 403 is a false pass ----
        code, body = get(port, "/gatetest")
        if code == 200 and b"auth=1" in body:
            say("dev gate            : OFF (auth=1) — 403s below are the deny, not the gate")
        else:
            stop_origin(conf)
            cannot_run(f"/gatetest says {code} {body[:60]!r}; the dev gate is not provably "
                       "off, so a 403 could be the gate rather than the deny rule")

        # ---- CONTROL 3: POSITIVE. Plugin trees must be served at all. ----
        pos = "/wp-content/plugins/elementor/assets/css/frontend.min.css"
        code, _ = get(port, pos)
        if code != 200:
            stop_origin(conf)
            cannot_run(f"positive control {pos} returned {code}, not 200 — plugin assets "
                       "are not being served, so 'the tooling is blocked' proves nothing")
        say("positive control    : a real plugin asset still serves (200)")

        # ---- THE ASSERTION ----
        targets = discover()
        if not targets:
            stop_origin(conf)
            cannot_run("discovery found NO lane-tooling files under the docroot. Either the "
                       "walk is broken or the deploy symlinks are missing; a gate that finds "
                       "nothing to test has not tested anything")
        say(f"discovered          : {len(targets)} lane-tooling file(s) inside deployed trees")

        bad = []
        for url in targets:
            code, body = get(port, url)
            if code not in (403, 404):
                bad.append((url, code, len(body)))
        for url, code, n in bad[:25]:
            say(f"  RED  {code} {n:>7}B  {url}")
        if bad:
            fail = 1
            say(f"\n  {len(bad)} lane-tooling file(s) ANSWER an anonymous request.")
            say("  Fix: extend the deny location in platform/nginx/dev2.loothgroup.com.conf")
            say("  (it must stay ABOVE the static-asset and catch-all \\.php$ regexes), or")
            say("  move the files out of the deployed tree.")
        else:
            say(f"anon reachability   : all {len(targets)} return 404/403")
    finally:
        stop_origin(conf)

    if fail:
        say("\nDEV-FILES GATE RED")
        return 1
    say("\nDEV-FILES GATE GREEN — no lane tooling answers an anonymous request")
    return 0


if __name__ == "__main__":
    sys.exit(main())
