#!/usr/bin/env python3
"""
auto-ban-gate.py — GATE 84 — the stuffing detector feeds a login-door blocklist,
and nothing else does.  (Number minted by keeper; lanes never self-mint.)

WHAT IT PROVES, in the order the feature actually works:

  §A the flag, read through the channel the runtime reads
  §B only the ruled trigger bans — several DIFFERENT logins in one window
  §C the address it bans is the one the connection proves, not the one a header claims
  §D the renderer treats the WordPress-written store as hostile
  §E the dash can undo a mistake, and cannot be made to do it without a nonce
  §F the config shape can never break nginx on a box that never armed this
  §G nginx really refuses the login door and really lets everything else through

NO NETWORK, NO ROOT, NO DATABASE, NO WORDPRESS, NO REAL BOX STATE. §A-§E drive
the real mu-plugins through tools/gates/auto-ban-harness.php against a stubbed
WordPress in a per-run temp dir. §G starts a THROWAWAY nginx on a per-run port
with the generated config and curls it — unprivileged, and pointed at a FastCGI
socket that does not exist, so 403 means "the door refused" and 502 means "the
door let it through to PHP". No FPM, no WordPress, nothing shared with the box.

⚠️ PER-RUN EVERYTHING. Temp dir, port, pid file. Two suites running at once must
not be able to make each other red — that has cost this repo five false REDs on
a healthy feature.

EVERY ABSENCE IS PAIRED WITH A LIVENESS CONTROL. "Nothing was banned" is equally
true of a working guard and a harness that cannot ban anything at all, so each
refusal has a sibling leg one condition away where the ban DOES happen.

⚠️ FIXTURE ADDRESSES ARE REAL-SHAPED, NOT RFC 5737. The renderer refuses
documentation ranges (203.0.113.0/24, 198.51.100.0/24, 2001:db8::/32) as
not-public, correctly — so a gate written with the usual example addresses would
watch every leg pass for the wrong reason.

Exit: 0 green, 1 a real defect, 2 CANNOT RUN.
⚠️ CANNOT RUN IS 2, NOT 3: run-all.sh reads anything else as RED, and a gate that
turns the whole suite red for every lane because an engine is missing is worse
than the defect it was looking for.
"""
import html
import json
import os
import re
import shutil
import socket
import subprocess
import sys
import tempfile
import time

REPO = os.environ.get("LG_AB_GATE_REPO") or os.path.abspath(
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", ".."))
MU       = os.path.join(REPO, "platform", "mu-plugins")
CFG_SRC  = os.path.join(REPO, "platform", "config", "auto-ban.php")
CF_LIST  = os.path.join(REPO, "tools", "infra", "cloudflare-ranges.txt")
RENDER   = os.path.join(REPO, "tools", "infra", "lg-auto-ban-render.py")
HARNESS  = os.path.join(REPO, "tools", "gates", "auto-ban-harness.php")
VHOST    = os.path.join(REPO, "platform", "nginx", "dev2.loothgroup.com.conf")
T_MAPS   = os.path.join(REPO, "platform", "nginx", "lg-auto-ban-maps.conf.template")
T_DOORS  = os.path.join(REPO, "platform", "nginx", "lg-auto-ban-doors.conf.template")
ERRPAGE  = os.path.join(REPO, "lg-shared", "errors", "login-blocked.html")
INSTALLER = os.path.join(REPO, "tools", "infra", "install-auto-ban.sh")

# Public-looking fixture addresses. See the docblock: RFC 5737 would be refused.
BAD_V4   = "45.83.64.10"
BAD_V4B  = "185.220.101.7"
BAD_V6   = "2a02:1234::9"
GOOD_V4  = "8.8.8.8"
CF_EDGE  = "104.23.190.196"      # a real Cloudflare address, from the tracked list
PEER_CF  = "162.158.159.108"     # ditto — used as the vouched connection's peer
PEER_RAW = "51.15.77.4"          # a direct-to-origin peer, not Cloudflare

FAILS = []
NOTES = []


def check(name, ok, detail=""):
    if ok:
        print(f"  ok   {name}")
    else:
        print(f"  FAIL {name}" + (f" — {detail}" if detail else ""))
        FAILS.append(name)
    return ok


def note(msg):
    NOTES.append(msg)
    print(f"  note {msg}")


# ── driving the real mu-plugins ──────────────────────────────────────────────

def php_run(tmp, tag, *, enabled=None, local_enabled=None, no_config=False,
            server=None, users=None, attempts=None, verbs=None, calls=None,
            caps=None, options=None, render_page=False, state_seed=None, get=None):
    """One scenario against the real plugin files. Returns the harness's JSON."""
    d = os.path.join(tmp, tag)
    cfg, state = os.path.join(d, "cfg"), os.path.join(d, "state")
    os.makedirs(cfg, exist_ok=True)
    os.makedirs(state, exist_ok=True)

    # THE REAL CHANNEL: an actual config file the plugin's own reader parses.
    if not no_config:
        body = "<?php return array( 'enabled' => %s );\n" % ("true" if enabled else "false")
        with open(os.path.join(cfg, "auto-ban.php"), "w") as fh:
            fh.write(body)
    if local_enabled is not None:
        with open(os.path.join(cfg, "auto-ban.local.php"), "w") as fh:
            fh.write("<?php return array( 'enabled' => %s );\n" % ("true" if local_enabled else "false"))
    if state_seed is not None:
        with open(os.path.join(state, "state.json"), "w") as fh:
            json.dump(state_seed, fh)

    scn = {
        "config_dir": cfg, "state_dir": state, "cf_ranges": CF_LIST, "mu_dir": MU,
        "server": server or {}, "users": users or [], "attempts": attempts or [],
        "verbs": verbs or [], "calls": calls or [], # NOT `caps or [...]`: an EMPTY capability list is the whole point of the
        # E4 leg, and `[] or [x]` quietly hands it back its admin rights.
        "caps": ["manage_options"] if caps is None else caps,
        "options": options or {}, "render_page": render_page, "get": get or {},
        "current_user": "ian",
    }
    sp = os.path.join(d, "scenario.json")
    with open(sp, "w") as fh:
        json.dump(scn, fh)
    p = subprocess.run(["php", HARNESS, sp], capture_output=True, text=True, timeout=90)
    if p.returncode != 0:
        raise RuntimeError(f"harness '{tag}' exited {p.returncode}: {p.stderr.strip()[:400]}")
    try:
        out = json.loads(p.stdout)
    except ValueError:
        raise RuntimeError(f"harness '{tag}' printed non-JSON: {p.stdout[:300]}")
    out["_state_dir"] = state
    out["_cfg_dir"] = cfg
    return out


def bans_of(res):
    st = res.get("state") or {}
    return [b["ip"] for b in (st.get("bans") or [])]


def stuffing_mails(res):
    return [m for m in res["mails"] if "credential stuffing" in m["subject"]]


# ── driving the real renderer ────────────────────────────────────────────────

def render(tmp, tag, state, *, op_allow=None, doors=True, maps=True,
           max_entries=None, twice=False, nginx_test="", nginx_reload="", dirname=None):
    d = os.path.join(tmp, "render-" + (dirname or tag))
    os.makedirs(d, exist_ok=True)
    sp, outp, stp = (os.path.join(d, n) for n in ("state.json", "list.conf", "status.json"))
    with open(sp, "w") as fh:
        json.dump(state, fh)
    if op_allow is not None:
        with open(os.path.join(d, "allowlist.local"), "w") as fh:
            fh.write(op_allow)
    for name, want in (("doors", doors), ("maps", maps)):
        p = os.path.join(d, name)
        if want:
            open(p, "w").write("x")
        elif os.path.exists(p):
            os.unlink(p)

    env = dict(os.environ,
               LG_AB_STATE=sp, LG_AB_OUT=outp, LG_AB_STATUS=stp,
               LG_AB_OP_ALLOW=os.path.join(d, "allowlist.local"),
               LG_AB_DOORS=os.path.join(d, "doors"), LG_AB_MAPS=os.path.join(d, "maps"),
               LG_AB_CF_RANGES=CF_LIST,
               LG_AB_NGINX_TEST=nginx_test, LG_AB_NGINX_RELOAD=nginx_reload)
    if max_entries:
        env["LG_AB_MAX_ENTRIES"] = str(max_entries)

    runs = []
    for _ in range(2 if twice else 1):
        p = subprocess.run([sys.executable, RENDER, "--quiet"], capture_output=True, text=True,
                           env=env, timeout=90)
        runs.append(p)
    body = open(outp).read() if os.path.exists(outp) else ""
    status = json.load(open(stp)) if os.path.exists(stp) else {}
    listed = re.findall(r'^"([^"]+)" 1;$', body, re.M)
    return {"listed": listed, "body": body, "status": status, "runs": runs, "dir": d}


# ── driving a throwaway nginx ────────────────────────────────────────────────

class Nginx:
    """A private nginx on a per-run port, serving the generated config."""

    def __init__(self, tmp, port):
        self.d = os.path.join(tmp, "nginx")
        os.makedirs(self.d, exist_ok=True)
        os.chmod(self.d, 0o755)
        self.port = port
        self.conf = os.path.join(self.d, "nginx.conf")
        self.list = os.path.join(self.d, "list.conf")
        self.running = False

    def build(self, vouch_loopback):
        # The list has to EXIST before `nginx -t`, or the include fails and the
        # config is judged broken for a reason that is nothing to do with it.
        if not os.path.exists(self.list):
            open(self.list, "w").close()
        # snippets/fastcgi-php.conf ends in `try_files $fastcgi_script_name =404`,
        # so without a file here every PERMITTED request answers 404 and stops
        # being distinguishable from a broken route. An empty file is enough: it
        # gets as far as the missing socket, which is the 502 we read as "allowed".
        for f in ("wp-login.php", "index.php"):
            open(os.path.join(self.d, f), "w").close()
        cf = [l.strip() for l in open(CF_LIST) if l.strip() and not l.startswith("#")]
        block = "\n".join(f"    {c} 1;" for c in cf)
        if vouch_loopback:
            # Stand in for "this connection arrived through Cloudflare". Without it
            # every probe would run down the unvouched path and the CF leg could
            # never be exercised from loopback.
            block += "\n    127.0.0.1/32 1;\n    ::1/128 1;"
        maps = open(T_MAPS).read().replace("@CF_RANGES@", block)
        maps = maps.replace("@LIST_INCLUDE@", self.list)
        open(os.path.join(self.d, "maps.conf"), "w").write(maps)

        # A socket that does not exist: blocked never needs an upstream (403),
        # permitted fails at the upstream (502). No FPM, no WordPress, no PHP.
        doors = open(T_DOORS).read()
        doors = doors.replace("@FPM_SOCK@", os.path.join(self.d, "no-such.sock"))
        doors = doors.replace("@DOCROOT@", self.d)
        open(os.path.join(self.d, "doors.conf"), "w").write(doors)

        for aux in ("mime.types", "fastcgi.conf", "fastcgi_params", "snippets"):
            link = os.path.join(self.d, aux)
            if not os.path.lexists(link):
                os.symlink(os.path.join("/etc/nginx", aux), link)

        open(self.conf, "w").write(f"""
worker_processes 1;
error_log {self.d}/error.log warn;
pid {self.d}/nginx.pid;
events {{ worker_connections 32; }}
http {{
    include /etc/nginx/mime.types;
    access_log off;
    client_body_temp_path {self.d}/cbt;
    proxy_temp_path {self.d}/pt;
    fastcgi_temp_path {self.d}/ft;
    uwsgi_temp_path {self.d}/ut;
    scgi_temp_path {self.d}/st;
    include {self.d}/maps.conf;
    server {{
        listen 127.0.0.1:{self.port};
        root {self.d};
        error_page 403 /lg-error/403.html;
        location ^~ /lg-error/ {{ alias {os.path.join(REPO, 'lg-shared', 'errors')}/; internal; }}
        include {self.d}/doors.conf;
        location / {{ return 200 "not-a-login-door"; }}
    }}
}}
""")

    def set_list(self, addresses):
        open(self.list, "w").write("".join(f'"{a}" 1;\n' for a in addresses))

    def test(self):
        return subprocess.run(["nginx", "-t", "-c", self.conf], capture_output=True, text=True, timeout=60)

    def start(self):
        p = subprocess.run(["nginx", "-c", self.conf], capture_output=True, text=True, timeout=60)
        if p.returncode != 0:
            raise RuntimeError("nginx would not start: " + (p.stderr or p.stdout)[:300])
        self.running = True
        for _ in range(50):
            with socket.socket() as s:
                if s.connect_ex(("127.0.0.1", self.port)) == 0:
                    return
            time.sleep(0.1)
        raise RuntimeError("nginx started but never listened")

    def reload(self):
        subprocess.run(["nginx", "-s", "reload", "-c", self.conf], capture_output=True, text=True, timeout=60)
        time.sleep(0.6)

    def stop(self):
        if self.running:
            subprocess.run(["nginx", "-s", "quit", "-c", self.conf], capture_output=True, text=True, timeout=60)
            self.running = False

    def get(self, path, *, ip=None, method="GET"):
        cmd = ["curl", "-s", "-m", "8", "-o", os.path.join(self.d, "body"),
               "-w", "%{http_code}", "-X", method, f"http://127.0.0.1:{self.port}{path}"]
        if ip:
            cmd += ["-H", f"CF-Connecting-IP: {ip}"]
        p = subprocess.run(cmd, capture_output=True, text=True, timeout=30)
        body = ""
        try:
            body = open(os.path.join(self.d, "body"), encoding="utf-8", errors="replace").read()
        except OSError:
            pass
        return p.stdout.strip(), body


class Installer:
    """Drive the REAL tools/infra/install-auto-ban.sh inside a temp root.

    This is the only piece of #162 a human runs on LIVE, by hand, with sudo — and
    until §H it was the only piece with no coverage: the gate proved the templates
    and the renderer, then trusted the script that installs them. Everything is
    redirected through LG_AB_ROOT, so no root, no /etc, no systemd and no nginx
    reload are involved; §H8 asserts the production defaults are untouched, so
    these hooks cannot quietly become the shipped behaviour.
    """

    SOCK = "/run/php/php8.3-fpm-GATE84-FIXTURE.sock"   # distinctive on purpose

    def __init__(self, tmp, tag, *, include_line=True, ban_page=True):
        self.root = os.path.join(tmp, "installroot-" + tag)
        self.tag = tag
        for d in ("etc/looth", "etc/nginx/sites-enabled", "etc/nginx/conf.d",
                  "etc/nginx/snippets", "etc/systemd/system", "srv/lg-shared/errors",
                  "var/lib"):
            os.makedirs(os.path.join(self.root, d), exist_ok=True)
        with open(os.path.join(self.root, "etc/looth/env"), "w") as fh:
            fh.write("LG_PUBLIC_HOST=gate84.invalid\nLG_WP_PATH=/var/www/gate84\n"
                     "LG_WP_USER=%s\n" % os.environ.get("USER", "ubuntu"))

        # A vhost shaped like the real one: the server_name the env names, the PHP
        # handler the socket is READ from, and (optionally) the glob include.
        vh = ["server {", "    server_name gate84.invalid;"]
        if include_line:
            vh.append("    include /etc/nginx/snippets/lg-auto-ban-*.conf;")
        vh += ["    location ~ \\.php$ {", "        include snippets/fastcgi-php.conf;",
               f"        fastcgi_pass unix:{self.SOCK};", "    }", "}"]
        with open(os.path.join(self.root, "etc/nginx/sites-enabled/gate84.conf"), "w") as fh:
            fh.write("\n".join(vh) + "\n")

        if ban_page:
            shutil.copy2(ERRPAGE, os.path.join(self.root, "srv/lg-shared/errors/login-blocked.html"))

    def path(self, rel):
        return os.path.join(self.root, rel)

    def run(self, *args, nginx_test="", nginx_reload=""):
        env = dict(os.environ,
                   LG_AB_ROOT=self.root,
                   LG_AB_ENVFILE=self.path("etc/looth/env"),
                   LG_AB_SITES_ENABLED=self.path("etc/nginx/sites-enabled"),
                   LG_AB_BANPAGE=self.path("srv/lg-shared/errors/login-blocked.html"),
                   LG_AB_NGINX_TEST=nginx_test, LG_AB_NGINX_RELOAD=nginx_reload,
                   LG_AB_SYSTEMCTL="true")
        p = subprocess.run(["bash", INSTALLER, *args], capture_output=True, text=True,
                           env=env, timeout=180, cwd=REPO)
        return p

    def present(self):
        return {n: os.path.exists(self.path(p)) for n, p in (
            ("maps",  "etc/nginx/conf.d/lg-auto-ban-maps.conf"),
            ("doors", "etc/nginx/snippets/lg-auto-ban-doors.conf"),
            ("list",  "etc/nginx/lg-auto-ban/list.conf"),
            ("store", "var/lib/lg-auto-ban"),
            ("unit",  "etc/systemd/system/lg-auto-ban.service"),
        )}

    def nginx_accepts(self, tmp):
        """Does nginx accept what the INSTALLER wrote — not what the gate substituted."""
        d = os.path.join(tmp, "instnginx-" + self.tag)
        os.makedirs(d, exist_ok=True)
        os.chmod(d, 0o755)
        for aux in ("mime.types", "fastcgi.conf", "fastcgi_params", "snippets"):
            link = os.path.join(d, aux)
            if not os.path.lexists(link):
                os.symlink(os.path.join("/etc/nginx", aux), link)
        conf = os.path.join(d, "nginx.conf")
        open(conf, "w").write(f"""
worker_processes 1;
error_log {d}/error.log warn;
pid {d}/nginx.pid;
events {{ worker_connections 16; }}
http {{
    include /etc/nginx/mime.types;
    access_log off;
    client_body_temp_path {d}/cbt; proxy_temp_path {d}/pt; fastcgi_temp_path {d}/ft;
    uwsgi_temp_path {d}/ut; scgi_temp_path {d}/st;
    include {self.path('etc/nginx/conf.d/lg-auto-ban-maps.conf')};
    server {{
        listen 127.0.0.1:{free_port()};
        root {d};
        location ^~ /lg-error/ {{ alias {os.path.join(REPO, 'lg-shared', 'errors')}/; internal; }}
        include {self.path('etc/nginx/snippets/lg-auto-ban-doors.conf')};
        location / {{ return 200 "ok"; }}
    }}
}}
""")
        return subprocess.run(["nginx", "-t", "-c", conf], capture_output=True, text=True, timeout=60)


def free_port():
    """Per-run, and actually free — two suites at once must not collide."""
    base = 20000 + (os.getpid() % 9000)
    for port in range(base, base + 200):
        with socket.socket() as s:
            if s.connect_ex(("127.0.0.1", port)) != 0:
                return port
    raise RuntimeError("no free port in range")


# ═════════════════════════════════════════════════════════════════════════════

def section(title):
    print(f"\n-- {title}")


def main():
    for tool, why in (("php", "the mu-plugins cannot be driven"),
                      ("curl", "the doors cannot be probed")):
        if not shutil.which(tool):
            print(f"CANNOT RUN: {tool} is not installed — {why}")
            return 2
    for p in (HARNESS, RENDER, CFG_SRC, CF_LIST, VHOST, T_MAPS, T_DOORS, ERRPAGE, INSTALLER):
        if not os.path.exists(p):
            print(f"CANNOT RUN: missing {p}")
            return 2

    tmp = tempfile.mkdtemp(prefix=f"lg-ab-gate-{os.getpid()}-")
    ng = None
    try:
        run_sections(tmp)
        ng = run_nginx_section(tmp)
        run_installer_section(tmp)
    except RuntimeError as e:
        print(f"CANNOT RUN: {e}")
        return 2
    finally:
        if ng is not None:
            ng.stop()
        shutil.rmtree(tmp, ignore_errors=True)

    print()
    if FAILS:
        print(f"GATE 84 RED — {len(FAILS)} assertion(s) failed:")
        for f in FAILS:
            print(f"   · {f}")
        return 1
    print("GATE 84 GREEN — the stuffing signal bans, nothing else does, the address "
          "cannot be forged, and the door refuses only the door.")
    return 0


def run_sections(tmp):
    users5 = [{"id": i, "login": f"m{i}", "email": f"m{i}@x.test"} for i in range(1, 8)]
    burst = [{"username": f"m{i}"} for i in range(1, 7)]
    cf_conn = {"REMOTE_ADDR": PEER_CF, "HTTP_CF_CONNECTING_IP": BAD_V4, "HTTP_USER_AGENT": "curl/8"}

    # ── §A the flag ─────────────────────────────────────────────────────────
    section("§A  the flag, through the channel the runtime actually reads")
    shipped = subprocess.run(
        ["php", "-r", f"$c = include {json.dumps(CFG_SRC)}; echo json_encode($c);"],
        capture_output=True, text=True, timeout=30)
    try:
        ship = json.loads(shipped.stdout)
    except ValueError:
        ship = {}
    check("A1 the SHIPPED default is OFF (no override anywhere in the assertion)",
          ship.get("enabled") is False, f"platform/config/auto-ban.php says {ship!r}")
    check("A1b the shipped default expires bans in a day and caps the list",
          ship.get("ban_seconds") == 86400 and int(ship.get("max_entries", 0)) > 0, repr(ship))

    r = php_run(tmp, "A2-noconfig", no_config=True, server=cf_conn, users=users5, attempts=burst)
    check("A2 an unreadable config FAILS CLOSED (off, not on)", r["enabled"] is False)
    check("A2b and therefore records nothing", not r["state_exists"])

    off = php_run(tmp, "A3-off", enabled=False, server=cf_conn, users=users5, attempts=burst)
    check("A3 flag OFF writes NO file at all — not an empty one", not off["state_exists"])
    on = php_run(tmp, "A4-on", enabled=True, server=cf_conn, users=users5, attempts=burst)
    check("A4 flag ON records the offender  [liveness for A3]", bans_of(on) == [BAD_V4], bans_of(on))

    # The alert prints the time it was sent, and the two runs are seconds apart,
    # so the wall clock is normalised out and everything else must match exactly.
    # Without this the leg measures the clock and says "the flag changed the mail".
    def scrub(x):
        return re.sub(r"\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\+\d{2}:\d{2})?",
                      "<WHEN>", json.dumps(x, sort_keys=True))
    check("A5 the alert email is identical OFF vs ON, to the byte, clock aside",
          scrub(off["mails"]) == scrub(on["mails"]),
          "the flag changed what Ian receives")
    check("A5b the ring-buffer log is identical OFF vs ON",
          scrub(off["log"]) == scrub(on["log"]))
    check("A5c and there really was an alert to compare  [liveness for A5]",
          len(stuffing_mails(off)) == 1 and len(stuffing_mails(on)) == 1,
          f"off={len(stuffing_mails(off))} on={len(stuffing_mails(on))}")

    loc = php_run(tmp, "A6-local", enabled=False, local_enabled=True,
                  server=cf_conn, users=users5, attempts=burst)
    check("A6 a box-local .local.php beats the tracked file (the dev2 flip channel)",
          loc["enabled"] is True and bans_of(loc) == [BAD_V4], bans_of(loc))

    # ── §B the trigger ──────────────────────────────────────────────────────
    section("§B  only the ruled trigger bans — several DIFFERENT logins in one window")
    check("B1 five distinct real accounts from one address -> alert, signal AND ban",
          len(stuffing_mails(on)) == 1 and len(on["signals"]) == 1 and bans_of(on) == [BAD_V4],
          f"mails={len(stuffing_mails(on))} signals={len(on['signals'])} bans={bans_of(on)}")
    check("B1b the signal fires at the same moment as the alert, exactly once",
          len(on["signals"]) == 1 and on["signals"][0].get("accounts") == 5, on["signals"])

    hammer = php_run(tmp, "B2-hammer", enabled=True, server=cf_conn,
                     users=users5, attempts=[{"username": "m1"}] * 50)
    check("B2 fifty attempts against ONE account is NOT a ban  [B1 is its control]",
          not stuffing_mails(hammer) and not hammer["signals"] and not hammer["state_exists"],
          f"signals={hammer['signals']} state={hammer['state_exists']}")

    under = php_run(tmp, "B3-under", enabled=True, server=cf_conn, users=users5,
                    attempts=[{"username": f"m{i}"} for i in range(1, 5)])
    check("B3 four distinct accounts — one under the threshold — is NOT a ban",
          not under["state_exists"], bans_of(under))

    spray = php_run(tmp, "B4-spray", enabled=True, server=cf_conn, users=users5,
                    attempts=[{"username": f"ghost{i}"} for i in range(1, 30)])
    check("B4 a spray at accounts that do not exist bans nobody and mails nobody",
          not spray["state_exists"] and not spray["mails"],
          f"mails={len(spray['mails'])}")

    # ── §C the address ──────────────────────────────────────────────────────
    section("§C  the address it bans is what the connection PROVES, not what a header claims")
    check("C1 through Cloudflare, the client in CF-Connecting-IP is what gets banned",
          bans_of(on) == [BAD_V4], bans_of(on))

    forged = php_run(tmp, "C2-forged", enabled=True, users=users5, attempts=burst,
                     server={"REMOTE_ADDR": PEER_RAW, "HTTP_CF_CONNECTING_IP": BAD_V4,
                             "HTTP_USER_AGENT": "curl/8"})
    check("C2 direct to the origin, a FORGED header cannot choose the victim",
          BAD_V4 not in bans_of(forged), bans_of(forged))
    check("C2b — the forger's own address is banned instead",
          bans_of(forged) == [PEER_RAW], bans_of(forged))
    st = (forged.get("state") or {}).get("bans") or []
    check("C2c and the claim is recorded, so the lie is visible afterwards",
          bool(st) and st[0].get("reported_ip") == BAD_V4, st)

    noheader = php_run(tmp, "C3-noheader", enabled=True, users=users5, attempts=burst,
                       server={"REMOTE_ADDR": PEER_CF, "HTTP_USER_AGENT": "curl/8"},
                       calls=[{"fn": "lg_ab_vouched_ip"}])
    check("C3 a Cloudflare connection with no client header bans NOBODY "
          "(never the edge — that is the whole-site outage)",
          not noheader["state_exists"], bans_of(noheader))
    # ⚠️ C3 ALONE PROVED THE WRONG THING. Its fixture peer is itself a Cloudflare
    # address, so the structural refusal catches it whatever lg_ab_vouched_ip()
    # returns — the red-first found the rule silently deleted and C3 still green.
    # This asks the function directly, which is the only way to see the rule.
    check("C3b …because lg_ab_vouched_ip() itself returns nothing, not the edge",
          noheader.get("calls", {}).get("lg_ab_vouched_ip:[]") == "",
          noheader.get("calls"))

    cfclient = php_run(tmp, "C4-cf", enabled=True, users=users5, attempts=burst,
                       server={"REMOTE_ADDR": PEER_CF, "HTTP_CF_CONNECTING_IP": CF_EDGE,
                               "HTTP_USER_AGENT": "curl/8"})
    check("C4 a Cloudflare address in the client header is refused at the WRITE",
          not cfclient["state_exists"], bans_of(cfclient))

    priv = php_run(tmp, "C5-private", enabled=True, users=users5, attempts=burst,
                   server={"REMOTE_ADDR": PEER_CF, "HTTP_CF_CONNECTING_IP": "10.0.0.9",
                           "HTTP_USER_AGENT": "curl/8"})
    check("C5 a private address is refused at the WRITE too",
          not priv["state_exists"], bans_of(priv))

    v6 = php_run(tmp, "C6-v6", enabled=True, users=users5, attempts=burst,
                 server={"REMOTE_ADDR": PEER_CF, "HTTP_CF_CONNECTING_IP": BAD_V6,
                         "HTTP_USER_AGENT": "curl/8"})
    check("C6 IPv6 clients are banned like anyone else", bans_of(v6) == [BAD_V6], bans_of(v6))

    # ── §D the renderer ─────────────────────────────────────────────────────
    section("§D  the renderer treats the WordPress-written store as hostile")
    now = int(time.time())
    fixture = {"version": 1, "bans": [
        {"ip": BAD_V4,  "banned_at": now - 3600,  "expires_at": now + 3600,  "accounts": 5},
        {"ip": BAD_V4B, "banned_at": now - 90000, "expires_at": now - 3600,  "accounts": 5},
    ], "allowlist": []}
    d1 = render(tmp, "expiry", fixture)
    check("D1 an expired ban is gone and a live one is present, same fixture",
          d1["listed"] == [BAD_V4], d1["listed"])

    allowed = json.loads(json.dumps(fixture))
    allowed["bans"][1]["expires_at"] = now + 3600
    allowed["allowlist"] = [{"ip": BAD_V4B}]
    d2 = render(tmp, "allow", allowed)
    check("D2 an allowlisted address is never rendered, even while it is a ban",
          d2["listed"] == [BAD_V4], d2["listed"])
    d3 = render(tmp, "opallow", allowed, op_allow="185.220.101.0/24  # the venue\n")
    check("D2b the ROOT allowlist WordPress cannot write takes CIDRs",
          BAD_V4B not in d3["listed"] and BAD_V4 in d3["listed"], d3["listed"])

    hostile = {"version": 1, "allowlist": [], "bans": [
        {"ip": CF_EDGE,       "banned_at": now, "expires_at": now + 3600},
        {"ip": "127.0.0.1",   "banned_at": now, "expires_at": now + 3600},
        {"ip": "10.1.2.3",    "banned_at": now, "expires_at": now + 3600},
        {"ip": "0.0.0.0",     "banned_at": now, "expires_at": now + 3600},
        {"ip": "not-an-ip",   "banned_at": now, "expires_at": now + 3600},
        {"ip": '1.2.3.4" 1;\n} server { listen 81; #', "banned_at": now, "expires_at": now + 3600},
        {"ip": BAD_V4,        "banned_at": now, "expires_at": now + 3600},
    ]}
    d4 = render(tmp, "hostile", hostile)
    check("D3 Cloudflare, loopback, private and 0.0.0.0 are all refused",
          d4["listed"] == [BAD_V4], d4["listed"])
    check("D3b every refusal is COUNTED, so a silent list is never mistaken for a quiet week",
          d4["status"].get("dropped", {}).get("cloudflare") == 1
          and d4["status"].get("dropped", {}).get("not-public") == 3,
          d4["status"].get("dropped"))
    check("D3c an address crafted to break out of the map reaches nginx as NOTHING",
          "listen 81" not in d4["body"] and "} server" not in d4["body"])

    flood = {"version": 1, "allowlist": [], "bans": [
        {"ip": f"45.{(i // 65536) % 200 + 20}.{(i // 256) % 256}.{i % 256}",
         "banned_at": now - 5000 + i, "expires_at": now + 3600} for i in range(5000)]}
    d5 = render(tmp, "cap", flood, max_entries=50)
    check("D4 the cap holds — 5,000 in, 50 out", len(d5["listed"]) == 50, len(d5["listed"]))
    check("D4b and it keeps the NEWEST, so a flood cannot push a fresh offender off",
          d5["status"].get("dropped", {}).get("over-cap") == 4950,
          d5["status"].get("dropped"))

    canon = {"version": 1, "allowlist": [], "bans": [
        {"ip": "2A02:1234:0000:0000:0000:0000:0000:0009", "banned_at": now, "expires_at": now + 3600}]}
    d6 = render(tmp, "canon", canon)
    check("D5 an address is RE-PRINTED from the parsed object, never copied through",
          d6["listed"] == [BAD_V6], d6["listed"])

    d7 = render(tmp, "unarmed", fixture, doors=False, maps=False)
    check("D6 with no nginx half installed the status says NOT armed, and why",
          d7["status"].get("armed") is False and "install-auto-ban" in d7["status"].get("why", ""),
          d7["status"])
    d8 = render(tmp, "armed", fixture, doors=True, maps=True)
    check("D6b with both halves present it says armed  [liveness for D6]",
          d8["status"].get("armed") is True, d8["status"])

    d9 = render(tmp, "idem", fixture, twice=True)
    check("D7 a second run changes nothing, so nginx is not reloaded for nothing",
          d9["status"].get("changed") is False, d9["status"])
    # The durable form of D7: the bytes nginx is handed must be a pure function of
    # the address set. A render time or a drop tally in that header would differ
    # every run and the 5-minute expiry timer would reload nginx forever on a box
    # with no bans. The red-first found exactly that, as a harmless edit reddening
    # D7 depending on which second the two runs landed in.
    check("D7b nothing clock-shaped is written into the file whose bytes decide "
          "whether nginx gets disturbed",
          re.search(r"\d{2}:\d{2}:\d{2}", d9["body"]) is None,
          [l for l in d9["body"].splitlines() if ":" in l][:3])

    # ⚠️ ROLLBACK NEEDS SOMETHING TO ROLL BACK TO. Asserting an empty list after a
    # refused render on a FRESH directory proves nothing — there was no previous
    # file, so "deleted" and "restored" look identical, and the red-first caught
    # this leg passing over a deleted rollback. So: land a good list first, then
    # try to replace it with one nginx refuses, and require the GOOD ONE to still
    # be in force afterwards.
    d10a = render(tmp, "rollback", fixture, nginx_test="true", nginx_reload="true")
    check("D8 a good render lands and nginx is told  [the setup for D8b]",
          d10a["listed"] == [BAD_V4] and d10a["status"].get("reload") == "reloaded",
          {"listed": d10a["listed"], "status": d10a["status"]})

    replacement = {"version": 1, "allowlist": [], "bans": [
        {"ip": BAD_V4B, "banned_at": now, "expires_at": now + 3600, "accounts": 5}]}
    d10b = render(tmp, "rollback-2", replacement, dirname="rollback", nginx_test="false")
    check("D8b a render nginx REFUSES is rolled back to the one already in force — "
          "a bad blocklist must never be able to stop the site serving",
          d10b["listed"] == [BAD_V4] and d10b["status"].get("reload") == "refused"
          and d10b["status"].get("armed") is False,
          {"listed": d10b["listed"], "status": d10b["status"]})
    d10c = render(tmp, "rollback-3", replacement, dirname="rollback",
                  nginx_test="true", nginx_reload="true")
    check("D8c …and the same replacement lands once nginx accepts it  [liveness for D8b]",
          d10c["listed"] == [BAD_V4B], d10c["listed"])

    # ── §E the dash ─────────────────────────────────────────────────────────
    section("§E  the dash can undo a mistake — and not without a nonce")
    seed = {"version": 1, "allowlist": [], "bans": [
        {"ip": BAD_V4, "banned_at": now, "expires_at": now + 86400, "accounts": 5, "span_seconds": 4},
        {"ip": BAD_V4B, "banned_at": now, "expires_at": now + 86400, "accounts": 6, "span_seconds": 40}]}

    e1 = php_run(tmp, "E1-remove", enabled=True, state_seed=seed,
                 verbs=[{"verb": "remove", "ip": BAD_V4}])
    check("E1 Remove lifts that one ban and leaves the other alone",
          bans_of(e1) == [BAD_V4B], bans_of(e1))

    e2 = php_run(tmp, "E2-badnonce", enabled=True, state_seed=seed,
                 verbs=[{"verb": "remove", "ip": BAD_V4, "nonce": "not-the-nonce"}])
    check("E2 a wrong nonce is refused  [E1 is its control]",
          e2["verbs"][0]["died"] == "nonce", e2["verbs"][0])
    check("E2b and the ban is still there afterwards",
          sorted(bans_of(e2)) == sorted([BAD_V4, BAD_V4B]), bans_of(e2))

    e3 = php_run(tmp, "E3-allow", enabled=True, state_seed=seed,
                 verbs=[{"verb": "allow", "ip": BAD_V4}])
    allow_ips = [a["ip"] for a in (e3["state"] or {}).get("allowlist", [])]
    check("E3 Never-ban both unbans and promotes, in one write",
          bans_of(e3) == [BAD_V4B] and allow_ips == [BAD_V4], f"{bans_of(e3)} / {allow_ips}")
    e3r = render(tmp, "afterallow", e3["state"])
    check("E3b and the webserver then stops refusing it",
          e3r["listed"] == [BAD_V4B], e3r["listed"])

    e4 = php_run(tmp, "E4-nocap", enabled=True, state_seed=seed, caps=[],
                 verbs=[{"verb": "remove", "ip": BAD_V4}])
    check("E4 a user without manage_options cannot remove a ban",
          e4["verbs"][0]["died"] == "Forbidden"
          and sorted(bans_of(e4)) == sorted([BAD_V4, BAD_V4B]), e4["verbs"][0])

    # The Remove handler's redirect, then the page it lands on: the sentence Ian
    # reads is the thing under test. The first cut put the whole sentence through
    # the query string and it arrived as "45.83.64.10%20can%20sign%20in%20again."
    redirect = e1["verbs"][0].get("redirect") or ""
    check("E1b Remove hands back a known outcome and the address, not free text",
          "lg_ab_done=removed" in redirect and "%20" not in redirect
          and "%25" not in redirect, redirect)
    e1p = php_run(tmp, "E1-page", enabled=True, state_seed=seed, render_page=True,
                  options={"lg_login_monitor_log": []},
                  get={"lg_ab_done": "removed", "lg_ab_ip": BAD_V4})
    check("E1c …and the page turns that into a sentence, with no escape debris in it",
          f"{BAD_V4} can sign in again." in html.unescape(e1p["page"] or ""),
          (e1p["page"] or "")[:300])

    e5 = php_run(tmp, "E5-page", enabled=True, state_seed=seed, render_page=True,
                 options={"lg_login_monitor_log": []})
    page = e5["page"] or ""
    check("E5 the page registers itself in wp-admin under manage_options",
          any(m["slug"] == "lg-auto-ban" and m["cap"] == "manage_options" for m in e5["menus"]),
          e5["menus"])
    check("E5b it says RECORDING BUT NOT BLOCKING when nginx was never armed "
          "(presence is not reachability)",
          "not yet blocking" in page, page[:200])
    plain = html.unescape(page)   # the page escapes its apostrophes; read the text
    check("E5c it describes a ban in plain English, not a bare timestamp",
          "members' passwords in 4 seconds" in plain and "Blocked today at" in plain,
          plain[plain.find("Blocked"):][:90])
    check("E5d every row's controls are POSTs carrying a nonce",
          page.count('name="_wpnonce"') >= 4 and "<form method=\"post\"" in page)

    e6 = php_run(tmp, "E6-off", enabled=False, state_seed=seed, render_page=True,
                 options={"lg_login_monitor_log": []})
    check("E6 with the flag off the page says so, rather than showing a reassuring table",
          "Recording is off" in (e6["page"] or ""), (e6["page"] or "")[:200])

    # ── §F the config shape ─────────────────────────────────────────────────
    section("§F  the config cannot break nginx on a box that never armed this")
    vh = open(VHOST).read()
    check("F1 the tracked vhost pulls the doors in by GLOB",
          "include /etc/nginx/snippets/lg-auto-ban-*.conf;" in vh)
    # Comments are prose and prose is allowed to name the variable; only CODE matters.
    vh_code = "\n".join(l for l in vh.splitlines() if not l.strip().startswith("#"))
    check("F2 and names no $lg_ab_ variable in any DIRECTIVE — on a box without the "
          "snippet that would be an undefined variable and nginx would refuse to start",
          "$lg_ab_" not in vh_code,
          [l for l in vh_code.splitlines() if "$lg_ab_" in l][:2])

    doors_t, maps_t = open(T_DOORS).read(), open(T_MAPS).read()
    m = re.search(r"location \^~ /wp-json/lg-member-sync/ \{(.*?)\n    \}", vh, re.S)
    if m:
        parent = m.group(1)
        want = [ln.strip() for ln in parent.splitlines()
                if ln.strip().startswith(("fastcgi_param SCRIPT_NAME", "fastcgi_read_timeout"))]
        check("F3 the auth door's FastCGI hand-off still mirrors the vhost block it overrides",
              all(w in doors_t for w in want) and "index.php" in doors_t, want)
    else:
        note("F3 skipped — could not locate the vhost's lg-member-sync block to compare against")

    # Each placeholder EXACTLY ONCE. The installer does a plain string replace, so a
    # second mention — in prose, in a comment, anywhere — is substituted too, and
    # the geo block or the include quietly ends up holding the wrong thing.
    for ph in ("@CF_RANGES@", "@LIST_INCLUDE@"):
        check(f"F4 the maps template spells {ph} exactly once",
              maps_t.count(ph) == 1, maps_t.count(ph))
    for ph in ("@FPM_SOCK@", "@DOCROOT@"):
        check(f"F4b the doors template's {ph} is only ever a placeholder, never prose",
              doors_t.count(ph) >= 1 and "@" not in doors_t.replace(ph, "").replace(
                  "info@loothgroup.com", "").replace("@FPM_SOCK@", "").replace("@DOCROOT@", ""),
              doors_t.count(ph))
    for act in ("logout", "lostpassword", "rp", "resetpass"):
        check(f"F5 '{act}' stays reachable to a blocked address (asserted in the map, not a comment)",
              re.search(r'^\s*"%s"\s+0;' % act, maps_t, re.M) is not None)
    check("F6 the polite page is a real page, and says what happens next",
          "Sign-in is paused" in open(ERRPAGE).read() and "Patreon" in open(ERRPAGE).read())


def run_nginx_section(tmp):
    section("§G  nginx really refuses the door — and only the door")
    if not shutil.which("nginx"):
        note("§G SKIPPED — no nginx on this box; §A-§F still ran")
        return None

    ng = Nginx(tmp, free_port())
    ng.build(vouch_loopback=True)
    t = ng.test()
    if t.returncode != 0:
        check("G0 the generated config is one nginx accepts", False,
              (t.stderr or t.stdout).strip()[:300])
        return None
    check("G0 the generated config is one nginx accepts", True)

    ng.set_list([BAD_V4, BAD_V6])
    ng.start()

    code, body = ng.get("/wp-login.php", ip=BAD_V4)
    check("G1 a blocked address gets 403 at the password form", code == "403", code)
    check("G1b …and the polite page, not a blank refusal",
          "Sign-in is paused for your network" in body, body[:120])
    check("G1c …which offers Patreon and a password reset",
          "connect-your-patreon" in body and "lostpassword" in body)

    for method in ("POST", "GET"):
        code, _ = ng.get("/wp-login.php", ip=BAD_V4, method=method)
        check(f"G1d the {method} to the login door is refused", code == "403", code)
    code, _ = ng.get("/wp-login.php?action=login", ip=BAD_V4)
    check("G1e ?action=login is refused too", code == "403", code)

    for action in ("logout", "lostpassword", "rp", "resetpass", "register"):
        code, _ = ng.get(f"/wp-login.php?action={action}", ip=BAD_V4)
        check(f"G2 ?action={action} still reaches WordPress for a blocked address "
              "(502 = the door let it through)", code == "502", code)

    code, body = ng.get("/hub/", ip=BAD_V4)
    check("G3 a blocked address keeps reading the site — this is a door, not a wall",
          code == "200" and "not-a-login-door" in body, code)

    code, _ = ng.get("/wp-login.php", ip=GOOD_V4)
    check("G4 an address not on the list signs in normally  [liveness for G1]",
          code == "502", code)

    code, _ = ng.get("/wp-login.php", ip=BAD_V6)
    check("G5 an IPv6 address on the list is refused", code == "403", code)

    code, body = ng.get("/wp-json/lg-member-sync/v1/auth", ip=BAD_V4, method="POST")
    check("G6 the membership password endpoint refuses a blocked address", code == "403", code)
    check("G6b …with JSON, because its caller does res.json() and would otherwise "
          "surface an unexplained script failure",
          body.strip().startswith("{") and "lg_login_blocked" in body, body[:120])
    code, _ = ng.get("/wp-json/lg-member-sync/v1/auth", ip=GOOD_V4, method="POST")
    check("G6c …and lets everyone else through  [liveness for G6]", code == "502", code)

    ng.set_list([])
    ng.reload()
    code, _ = ng.get("/wp-login.php", ip=BAD_V4)
    check("G7 an empty list blocks nobody — the state every box is in before a first ban",
          code == "502", code)

    # THE SPOOF DEFENCE, end to end: the same request, the same header, the only
    # difference being whether the connection itself is vouched.
    ng.stop()
    ng.build(vouch_loopback=False)
    ng.set_list([BAD_V4])
    ng.start()
    code, _ = ng.get("/wp-login.php", ip=BAD_V4)
    check("G8 off a NON-Cloudflare connection the client header is ignored, so a forged "
          "one cannot put a victim behind our own deny rule", code == "502", code)
    ng.set_list(["127.0.0.1"])
    ng.reload()
    code, _ = ng.get("/wp-login.php", ip=BAD_V4)
    check("G8b …and the forger's own address is what gets refused  [liveness for G8]",
          code == "403", code)
    return ng


def run_installer_section(tmp):
    section("§H  the flip kit — the one piece a human runs on LIVE, by hand")
    if not shutil.which("nginx"):
        note("§H partially SKIPPED — no nginx to judge what the installer wrote")

    # ── H1 the happy path ────────────────────────────────────────────────────
    ok = Installer(tmp, "arm")
    p = ok.run()
    check("H1 a well-formed box arms cleanly", p.returncode == 0,
          (p.stdout + p.stderr)[-400:])
    st = ok.present()
    check("H1b …writing BOTH nginx halves, the list, the store and the units",
          all(st.values()), st)

    maps = open(ok.path("etc/nginx/conf.d/lg-auto-ban-maps.conf")).read()
    doors = open(ok.path("etc/nginx/snippets/lg-auto-ban-doors.conf")).read()
    check("H2 no placeholder survives substitution in either file",
          not re.search(r"@[A-Z_]+@", maps + doors),
          re.findall(r"@[A-Z_]+@", maps + doors))
    check("H2b the FPM socket is READ off the vhost, not guessed",
          Installer.SOCK in doors, [l for l in doors.splitlines() if "fastcgi_pass" in l][:2])
    check("H2c the deny list the maps file includes is the one the installer wrote — "
          "one path computed in one place",
          ok.path("etc/nginx/lg-auto-ban") in maps,
          [l for l in maps.splitlines() if "include" in l][:3])
    cf_count = len([l for l in open(CF_LIST) if l.strip() and not l.startswith("#")])
    check("H2d every Cloudflare range reached the geo block",
          len(re.findall(r"^\s+\S+/\d+ 1;$", maps, re.M)) == cf_count,
          len(re.findall(r"^\s+\S+/\d+ 1;$", maps, re.M)))

    if shutil.which("nginx"):
        t = ok.nginx_accepts(tmp)
        check("H3 nginx accepts what the INSTALLER wrote, not what the gate substituted",
              t.returncode == 0, (t.stderr or t.stdout)[-300:])

    # ── H4/H5 the two refusals that exist because nginx -t cannot see them ───
    noinc = Installer(tmp, "noinclude", include_line=False)
    p = noinc.run()
    check("H4 it refuses a vhost with no include line — arming there would install "
          "a blocklist that blocks nobody",
          p.returncode != 0 and "STOP" in p.stdout, (p.stdout + p.stderr)[-300:])
    check("H4b …and leaves nothing behind  [H1b is its control]",
          not any(noinc.present()[k] for k in ("maps", "doors", "list")), noinc.present())

    nopage = Installer(tmp, "nopage", ban_page=False)
    p = nopage.run()
    check("H5 it refuses when the polite page is not in the serving checkout — a bare "
          "404 is the blank refusal the design was revised to avoid, and nginx -t "
          "passes either way",
          p.returncode != 0 and "404" in p.stdout, (p.stdout + p.stderr)[-300:])
    check("H5b …and leaves nothing behind",
          not any(nopage.present()[k] for k in ("maps", "doors", "list")), nopage.present())

    # ── H6 a config nginx rejects must leave the box exactly as it was ───────
    rej = Installer(tmp, "rejected")
    p = rej.run(nginx_test="false")
    check("H6 a rejected config aborts the arm", p.returncode != 0,
          (p.stdout + p.stderr)[-300:])
    check("H6b …and rolls BOTH halves back out, never leaving the pair half-installed",
          not any(rej.present()[k] for k in ("maps", "doors", "list")), rej.present())

    # ── H7 disarming ────────────────────────────────────────────────────────
    seeded = os.path.join(ok.path("var/lib/lg-auto-ban"), "state.json")
    with open(seeded, "w") as fh:
        json.dump({"version": 1, "bans": [], "allowlist": [{"ip": BAD_V4}]}, fh)
    p = ok.run("--uninstall")
    check("H7 --uninstall succeeds", p.returncode == 0, (p.stdout + p.stderr)[-300:])
    st = ok.present()
    check("H7b …removing both nginx halves, the list and the units",
          not any(st[k] for k in ("maps", "doors", "list", "unit")), st)
    check("H7c …and NOT the ban store — disarming must not throw away the allowlist "
          "somebody built by hand",
          os.path.exists(seeded)
          and json.load(open(seeded))["allowlist"][0]["ip"] == BAD_V4)

    # ── H8 the test hooks did not become the shipped behaviour ──────────────
    src = open(INSTALLER).read()
    for want in ("$ROOT/etc/nginx/conf.d/lg-auto-ban-maps.conf",
                 "$ROOT/etc/nginx/snippets/lg-auto-ban-doors.conf",
                 "$ROOT/var/lib/lg-auto-ban",
                 "$ROOT/etc/systemd/system"):
        check(f"H8 production path intact: {want.replace('$ROOT', '')}", want in src)
    check("H8b ROOT defaults to empty, so an unhooked run writes the real paths",
          re.search(r'^ROOT="\$\{LG_AB_ROOT:-\}"$', src, re.M) is not None)
    p = subprocess.run(["bash", INSTALLER, "--check"], capture_output=True, text=True,
                       timeout=60, cwd=REPO, env={k: v for k, v in os.environ.items()
                                                  if not k.startswith("LG_AB_")})
    check("H8c a REAL install still demands root  [the guard the hooks bypass]",
          p.returncode != 0 and "run as root" in (p.stdout + p.stderr),
          (p.stdout + p.stderr)[-200:])


if __name__ == "__main__":
    sys.exit(main())
