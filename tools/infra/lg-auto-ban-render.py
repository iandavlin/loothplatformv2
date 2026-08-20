#!/usr/bin/env python3
"""
lg-auto-ban-render.py — #162 — turn the ban file WordPress writes into the deny
list nginx reads, and reload nginx when it actually changed.

RUNS AS ROOT, TRIGGERED BY systemd:
  · lg-auto-ban.path   — the instant /var/lib/lg-auto-ban/state.json is written
  · lg-auto-ban.timer  — every 5 minutes, so a ban EXPIRES on its own even on a
                         box where nobody has failed a login since

═══ THIS SCRIPT IS THE PRIVILEGE BOUNDARY ══════════════════════════════════════

Its input is written by the web user and its output is nginx configuration
executed by root. So it assumes the input is hostile — not because WordPress is
expected to be compromised, but because "an unprivileged process can put text
into a root-parsed config file" is only ever safe if the parser in between is the
one deciding what may appear.

Everything is rebuilt from scratch on every run. Nothing from the input is
copied through; each entry is parsed into a Python ipaddress object and then
RE-PRINTED from that object, so the only bytes that can ever reach nginx are a
canonical address this script produced itself. Anything that will not parse, or
that parses into something we refuse, is dropped and counted.

REFUSED, ALWAYS, whatever the input says:
  · Cloudflare's own ranges — banning the edge takes the site off the internet
    for every visitor behind it, which is the exact opposite of the feature
  · private, loopback, link-local, multicast, reserved, unspecified — a deny list
    is for the public internet; a private address in it means something upstream
    is confused, and acting on that confusion is how you lock yourself out
  · anything on the allowlist, either WordPress's (removable from the dash) or the
    root-owned one WordPress cannot touch
  · anything past the entry cap, oldest first

THE CAP IS THIS SCRIPT'S OWN NUMBER, deliberately not read from the WP-side
config: a bound the untrusted writer can raise is not a bound.

FAILING SAFE MEANS FAILING OPEN HERE. If the render is rejected by `nginx -t`,
the previous list is restored and the reload is skipped — an unparseable
blocklist must never be allowed to stop nginx from serving the site.

Exit: 0 fine, 1 something went wrong (and was reported), 2 could not run at all.
"""
import argparse
import ipaddress
import json
import os
import subprocess
import sys
import time

DEF_STATE   = "/var/lib/lg-auto-ban/state.json"
DEF_STATUS  = "/var/lib/lg-auto-ban/render-status.json"
DEF_ALLOW   = "/var/lib/lg-auto-ban/allowlist.local"
DEF_OUT     = "/etc/nginx/lg-auto-ban/list.conf"
DEF_DOORS   = "/etc/nginx/snippets/lg-auto-ban-doors.conf"
DEF_MAPS    = "/etc/nginx/conf.d/lg-auto-ban-maps.conf"
DEF_CF      = os.path.join(os.path.dirname(os.path.abspath(__file__)), "cloudflare-ranges.txt")
DEF_MAX     = 500


def env(name, default):
    """For PATHS: an empty value is a mistake, so fall back to the default."""
    v = os.environ.get(name)
    return v if v not in (None, "") else default


def env_cmd(name, default):
    """For COMMANDS: an empty value is a DECISION — do not run it at all.

    These two rules have to differ, and the difference is not academic: with the
    path rule applied to commands there is no way to switch the nginx test and
    reload off, so an offline render still shells out to the real `nginx -t`,
    fails as a non-root caller, and rolls its own work back. Gate 84 found
    exactly that on its first run, as six assertions about expiry, the allowlist
    and the cap all going red for a reason none of them was about."""
    if name in os.environ:
        return os.environ[name].strip()
    return default


def read_cf_ranges(path):
    """The shared trust boundary. An unreadable list is fatal, not empty: with no
    ranges every Cloudflare address becomes bannable and the first ban takes the
    site down. Refusing to render is the mild outcome."""
    nets = []
    try:
        with open(path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                try:
                    nets.append(ipaddress.ip_network(line, strict=False))
                except ValueError:
                    print(f"  ! ignoring unparseable CF range: {line[:60]}", file=sys.stderr)
    except OSError as e:
        raise RuntimeError(f"cannot read Cloudflare range list {path}: {e}")
    if not nets:
        raise RuntimeError(f"Cloudflare range list {path} yielded no ranges — refusing to render")
    return nets


def read_json(path, what):
    try:
        with open(path, "r", encoding="utf-8") as fh:
            data = json.load(fh)
    except FileNotFoundError:
        return {}
    except (OSError, ValueError) as e:
        print(f"  ! {what} at {path} is unreadable ({e}) — treating as empty", file=sys.stderr)
        return {}
    return data if isinstance(data, dict) else {}


def read_operator_allowlist(path):
    """Root-owned, one address or CIDR per line. WordPress cannot write here, so
    this is where an address goes when it must be immune to the dashboard too."""
    out = []
    try:
        with open(path, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.split("#", 1)[0].strip()
                if not line:
                    continue
                try:
                    out.append(ipaddress.ip_network(line, strict=False))
                except ValueError:
                    print(f"  ! ignoring unparseable operator allowlist entry: {line[:60]}", file=sys.stderr)
    except FileNotFoundError:
        pass
    except OSError as e:
        print(f"  ! cannot read operator allowlist {path}: {e}", file=sys.stderr)
    return out


def refuse_reason(addr, cf_nets):
    """Why this address may not be banned — '' when it may be."""
    if addr.is_loopback or addr.is_private or addr.is_link_local or addr.is_multicast \
            or addr.is_reserved or addr.is_unspecified:
        return "not-public"
    for net in cf_nets:
        if addr.version == net.version and addr in net:
            return "cloudflare"
    return ""


def build(state, cf_nets, op_allow, now, max_entries):
    """Returns (ordered list of canonical address strings, per-reason drop counts)."""
    drops = {"not-public": 0, "cloudflare": 0, "unparseable": 0,
             "expired": 0, "allowlisted": 0, "over-cap": 0, "duplicate": 0}

    allow = set()
    for a in state.get("allowlist") or []:
        if isinstance(a, dict) and a.get("ip"):
            try:
                allow.add(ipaddress.ip_address(str(a["ip"]).strip()))
            except ValueError:
                pass

    kept = []
    seen = set()
    for b in state.get("bans") or []:
        if not isinstance(b, dict) or not b.get("ip"):
            drops["unparseable"] += 1
            continue
        try:
            addr = ipaddress.ip_address(str(b["ip"]).strip())
        except ValueError:
            drops["unparseable"] += 1
            continue
        try:
            expires = int(b.get("expires_at") or 0)
        except (TypeError, ValueError):
            expires = 0
        if expires <= now:
            drops["expired"] += 1
            continue
        reason = refuse_reason(addr, cf_nets)
        if reason:
            drops[reason] += 1
            continue
        if addr in allow or any(addr.version == n.version and addr in n for n in op_allow):
            drops["allowlisted"] += 1
            continue
        if addr in seen:
            drops["duplicate"] += 1
            continue
        try:
            banned_at = int(b.get("banned_at") or 0)
        except (TypeError, ValueError):
            banned_at = 0
        seen.add(addr)
        kept.append((banned_at, addr))

    # Cap: newest survive, so a flood of stale rows cannot push a fresh offender off.
    kept.sort(key=lambda t: t[0])
    if len(kept) > max_entries:
        drops["over-cap"] = len(kept) - max_entries
        kept = kept[-max_entries:]

    kept.sort(key=lambda t: (-t[0], str(t[1])))
    # RE-PRINTED from the parsed object, never copied from the input.
    return [str(addr) for _, addr in kept], drops


def render_conf(addresses, now, drops):
    lines = [
        "# GENERATED — do not edit. tools/infra/lg-auto-ban-render.py rewrites this",
        "# file whenever /var/lib/lg-auto-ban/state.json changes and every 5 minutes",
        "# so bans expire on their own. Included INSIDE the $lg_ab_listed map in",
        "# conf.d/lg-auto-ban-maps.conf, so these are map entries, not directives.",
        "#",
        f"# rendered: {time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime(now))}",
        f"# entries:  {len(addresses)}",
        f"# dropped:  " + ", ".join(f"{k}={v}" for k, v in sorted(drops.items()) if v),
        "",
    ]
    for a in addresses:
        lines.append(f'"{a}" 1;')
    return "\n".join(lines) + "\n"


def write_if_changed(path, body):
    """Returns True when the file's content actually changed."""
    try:
        with open(path, "r", encoding="utf-8") as fh:
            if fh.read() == body:
                return False
    except OSError:
        pass
    parent = os.path.dirname(path) or "."
    os.makedirs(parent, exist_ok=True)
    tmp = os.path.join(parent, f".{os.path.basename(path)}.{os.getpid()}.tmp")
    with open(tmp, "w", encoding="utf-8") as fh:
        fh.write(body)
    os.chmod(tmp, 0o644)
    os.replace(tmp, path)
    return True


def run(cmd):
    if not cmd:
        return (0, "skipped")
    try:
        p = subprocess.run(cmd, shell=True, capture_output=True, text=True, timeout=60)
        return (p.returncode, (p.stderr or p.stdout or "").strip()[:400])
    except Exception as e:      # noqa: BLE001 — any failure here is just "it failed"
        return (1, str(e)[:400])


def write_status(path, payload):
    """The dash reads this. It is the ONLY way wp-admin can tell 'recording but
    not blocking' from 'blocking'; without it the page shows a table of bans and
    lets Ian assume they are being enforced."""
    try:
        parent = os.path.dirname(path) or "."
        os.makedirs(parent, exist_ok=True)
        tmp = os.path.join(parent, f".{os.path.basename(path)}.{os.getpid()}.tmp")
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(payload, fh, indent=2, sort_keys=True)
            fh.write("\n")
        os.chmod(tmp, 0o644)
        os.replace(tmp, path)
    except OSError as e:
        print(f"  ! cannot write status {path}: {e}", file=sys.stderr)


def main():
    ap = argparse.ArgumentParser(description="Render the auto-ban deny list for nginx.")
    ap.add_argument("--dry-run", action="store_true", help="print what would be written; touch nothing")
    ap.add_argument("--quiet", action="store_true")
    args = ap.parse_args()

    state_p  = env("LG_AB_STATE", DEF_STATE)
    status_p = env("LG_AB_STATUS", DEF_STATUS)
    allow_p  = env("LG_AB_OP_ALLOW", DEF_ALLOW)
    out_p    = env("LG_AB_OUT", DEF_OUT)
    doors_p  = env("LG_AB_DOORS", DEF_DOORS)
    maps_p   = env("LG_AB_MAPS", DEF_MAPS)
    cf_p     = env("LG_AB_CF_RANGES", DEF_CF)
    test_cmd = env_cmd("LG_AB_NGINX_TEST", "nginx -t")
    load_cmd = env_cmd("LG_AB_NGINX_RELOAD", "systemctl reload nginx")
    try:
        max_entries = max(1, int(env("LG_AB_MAX_ENTRIES", str(DEF_MAX))))
    except ValueError:
        max_entries = DEF_MAX

    now = int(time.time())
    try:
        cf_nets = read_cf_ranges(cf_p)
    except RuntimeError as e:
        print(f"CANNOT RUN: {e}", file=sys.stderr)
        write_status(status_p, {"armed": False, "why": "The blocklist could not be rebuilt: " + str(e),
                                "rendered_at": now, "entries": 0})
        return 2

    state = read_json(state_p, "ban store")
    op_allow = read_operator_allowlist(allow_p)
    addresses, drops = build(state, cf_nets, op_allow, now, max_entries)
    body = render_conf(addresses, now, drops)

    if args.dry_run:
        sys.stdout.write(body)
        return 0

    prev = None
    try:
        with open(out_p, "r", encoding="utf-8") as fh:
            prev = fh.read()
    except OSError:
        pass

    changed = write_if_changed(out_p, body)

    # Both halves of the nginx config must be present for anything to be enforced.
    armed = os.path.exists(doors_p) and os.path.exists(maps_p)
    why = ""
    if not armed:
        missing = [p for p in (doors_p, maps_p) if not os.path.exists(p)]
        why = ("The webserver has not been given the list yet — run "
               "sudo tools/infra/install-auto-ban.sh. Missing: " + ", ".join(missing))

    rc = 0
    reload_note = "not needed"
    if changed and armed:
        trc, tout = run(test_cmd)
        if trc != 0:
            # Put the old list back rather than leave nginx holding one it rejects.
            if prev is not None:
                write_if_changed(out_p, prev)
            else:
                try:
                    os.unlink(out_p)
                except OSError:
                    pass
            armed = False
            why = "The webserver refused the rebuilt list, so the previous one is still in force: " + tout
            reload_note = "refused"
            rc = 1
            print(f"nginx -t REFUSED the render — rolled back. {tout}", file=sys.stderr)
        else:
            lrc, lout = run(load_cmd)
            if lrc != 0:
                armed = False
                why = "The list was rebuilt but the webserver could not be told to re-read it: " + lout
                reload_note = "failed"
                rc = 1
                print(f"nginx reload FAILED: {lout}", file=sys.stderr)
            else:
                reload_note = "reloaded"

    write_status(status_p, {
        "armed": armed,
        "why": why,
        "rendered_at": now,
        "entries": len(addresses),
        "changed": changed,
        "reload": reload_note,
        "dropped": {k: v for k, v in drops.items() if v},
        "list_path": out_p,
    })

    if not args.quiet:
        print(f"lg-auto-ban: {len(addresses)} address(es) live, changed={changed}, "
              f"armed={armed}, reload={reload_note}"
              + (f", dropped=" + ", ".join(f"{k}={v}" for k, v in sorted(drops.items()) if v) if any(drops.values()) else ""))
    return rc


if __name__ == "__main__":
    sys.exit(main())
