#!/usr/bin/env python3
"""gate 88 — compose uploads: the limits hold, and the cleanup cannot eat a file.

#186. Ian, 2026-08-21: "Can we make limits, post only and in and out?" and
"Basically if it doesn't launch with the post, does it get deleted on publish?"

⚠️ WHY THIS GATE ASSERTS BEHAVIOUR AND NEVER A SETTING — the whole reason it is
shaped this way. The obvious gate reads `max_size` back off the ACF field and goes
green. That assertion is TRUE of the broken build: ACF validates attachments from
`wp_handle_upload_prefilter` alone (includes/media.php:38), this box runs
tuxedo-big-file-uploads whose chunker calls media_handle_upload() with
`overrides['action'] = 'wp_handle_sideload'`, and WordPress dispatches the
prefilter dynamically as "{action}_prefilter". So ACF's validator never runs here.
Measured proof, no reading required: the print-file field declares
`mime_types = zip` and holds 127 zips AND 48 .stl files. A field setting on this
form is decoration. So every limit below is proved by pushing a real oversize file
through the real filter and reading the refusal.

⚠️ AND WHY THE COLLECTOR NEEDS §E. Run unrestricted over the real corpus,
"delete what the post does not use" wanted to delete 65 attachments across 36
HEALTHY PUBLISHED loothprints. §E is the assertion that this can never happen: it
counts stamped attachments outside the run's own fixtures and requires zero, so
"legacy files are unreachable" is checked on every run rather than remembered.

READS THE FLAG, NEVER HARDCODES A STATE (feedback-gate-reads-the-flag-not-a-
hardcoded-state). It runs the probe TWICE against the same build — once with the
flag off and once with it on — and asserts the OFF build refuses nothing, deletes
nothing and requires nothing. That comes free from the harness: lg_fc_enabled()
resolves its config relative to the mu-plugin FILE, and the `enabled => true`
override lives in a gitignored .local.php that exists only in the serving
checkout, so a mirror pointed at this worktree reads the TRACKED default (false).
LG_FC_PREVIEW=1 arms it — through `env`, because sudo strips the environment.

NOTHING ON THE SERVE IS MODIFIED. The branch's mu-plugin is loaded by mirroring
/var/www/dev/wp-content/mu-plugins into a per-run directory with this one file
swapped, and pointing WPMU_PLUGIN_DIR at the mirror.

Exit: 0 green · 1 an open defect · 2 CANNOT RUN (never 3 — run-all.sh reads
anything that is not 0 or 2 as RED, so an environment failure must say 2).
"""
import os
import re
import shutil
import subprocess
import sys
import tempfile

WP_PATH = "/var/www/dev"
MU_DIR = "/var/www/dev/wp-content/mu-plugins"
PLUGIN_REL = "platform/mu-plugins/lg-frontend-compose.php"
PROBE_REL = "tools/gates/compose-limits-probe.php"

RUN_ROOT = None


def repo_root():
    return os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def die(reason):
    print("GATE-ERROR  %s" % reason)
    print("############ GATE 88 CANNOT RUN ############")
    sys.exit(2)


def sh(cmd, timeout=180, env=None):
    return subprocess.run(cmd, capture_output=True, text=True, timeout=timeout, env=env)


def refuse_off_dev2():
    """Live pulls all of main, so this file WILL land there. It must do nothing."""
    r = sh(["sudo", "-n", "-u", "looth-dev", "wp", "--path=" + WP_PATH,
            "option", "get", "siteurl"])
    url = (r.stdout or "").strip()
    if "dev2.loothgroup.com" not in url:
        die("siteurl is %r — this gate mints rows and only ever runs on dev2" % url)


def build_harness(plugin):
    """A WordPress that loads THIS BRANCH's mu-plugin, without touching the serve.

    Mirrors the real mu-plugin dir into a per-run directory with the one file
    swapped, then defines WPMU_PLUGIN_DIR ahead of wp_initial_constants() via
    `wp --require=`. Adapted from tools/gates/follow-digest-gate.py, which paid
    for every warning in it.
    """
    global RUN_ROOT
    RUN_ROOT = tempfile.mkdtemp(prefix="lg186-gate-%d-" % os.getpid())
    os.chmod(RUN_ROOT, 0o755)
    harness = os.path.join(RUN_ROOT, "mu")
    boot = os.path.join(RUN_ROOT, "boot.php")

    listing = sh(["sudo", "find", MU_DIR, "-maxdepth", "1", "-mindepth", "1"])
    if listing.returncode != 0:
        return None, "could not enumerate %s: %s" % (MU_DIR, (listing.stderr or "").strip()[:200])
    entries = [x for x in (listing.stdout or "").splitlines() if x.strip()]
    if not entries:
        return None, ("%s is empty — a mirror of it would be a WordPress with no mu-plugins, "
                      "and every assertion below would fail for the wrong reason" % MU_DIR)

    os.makedirs(harness, 0o755)
    swapped, failures = False, []
    for src in entries:
        base = os.path.basename(src)
        real = sh(["sudo", "readlink", "-f", src]).stdout.strip()
        if not real:
            continue
        target = base == os.path.basename(plugin)
        if target:
            real = plugin
        try:
            os.symlink(real, os.path.join(harness, base))
        except OSError as e:
            failures.append("%s (%s)" % (base, e))
            continue
        if target:
            swapped = True
    if failures:
        return None, ("could not link %d mu-plugin(s): %s — a partial mirror is a DIFFERENT "
                      "WordPress from the one under test" % (len(failures), "; ".join(failures[:4])))
    if not swapped:
        return None, ("%s is not among the mu-plugins in %s, so the mirror would load it "
                      "ALONGSIDE the serve's copy rather than instead of it"
                      % (os.path.basename(plugin), MU_DIR))

    with open(boot, "w") as fh:
        fh.write('<?php define("WPMU_PLUGIN_DIR", %r);\n' % harness)
    os.chmod(boot, 0o644)

    # THE HARNESS IS ASSERTED, NOT ASSUMED. Every step above can succeed and still
    # leave WordPress loading a different file than the one named — which is exactly
    # how a gate reports confidently about main.
    linked = os.path.join(harness, os.path.basename(plugin))
    if os.path.realpath(linked) != os.path.realpath(plugin):
        return None, "the mirror resolves %s to %s, not the branch file" % (linked, os.path.realpath(linked))
    return boot, None


def run_probe(boot, probe, tag, plugin, flag_on):
    """One boot of real WordPress. `env` is used explicitly: sudo strips it, and a
    silently-stripped LG_FC_PREVIEW makes the flag-ON run exercise the OFF path."""
    cmd = ["sudo", "-n", "-u", "looth-dev", "env",
           "LG186_TAG=" + tag, "LG186_PLUGIN=" + plugin]
    if flag_on:
        cmd.append("LG_FC_PREVIEW=1")
    cmd += ["wp", "--path=" + WP_PATH, "--require=" + boot, "eval-file", probe]
    r = sh(cmd, timeout=420)
    rows = []
    for line in (r.stdout or "").splitlines():
        if line.startswith("R|"):
            parts = line.split("|", 3)
            if len(parts) == 4:
                rows.append((parts[1], parts[2] == "PASS", parts[3]))
    return rows, r


def main():
    repo = repo_root()
    plugin = os.path.join(repo, PLUGIN_REL)
    probe = os.path.join(repo, PROBE_REL)
    for p in (plugin, probe):
        if not os.path.isfile(p):
            die("missing %s" % p)

    refuse_off_dev2()
    boot, why = build_harness(plugin)
    if boot is None:
        die(why)

    # The probe must be readable by looth-dev; it lives in a ubuntu-owned worktree.
    staged = os.path.join(RUN_ROOT, "probe.php")
    shutil.copyfile(probe, staged)
    os.chmod(staged, 0o644)

    passed = failed = 0
    findings = []
    for flag_on in (False, True):
        label = "flag ON " if flag_on else "flag OFF"
        tag = "%d%s" % (os.getpid(), "on" if flag_on else "off")
        rows, raw = run_probe(boot, staged, tag, plugin, flag_on)
        if not rows:
            die("the %s run produced no assertions. stdout=%r stderr=%r"
                % (label, (raw.stdout or "")[-400:], (raw.stderr or "")[-400:]))

        # LIVENESS BEFORE ANYTHING ELSE — a WordPress that did not load the branch
        # satisfies "nothing was refused" perfectly (trap-locked-out-browser-goes-
        # vacuously-green, and #185's 0-byte page).
        live = dict((i, ok) for i, ok, _ in rows)
        if not live.get("0.loaded") or not live.get("0.branch"):
            detail = [d for i, _, d in rows if i.startswith("0.")]
            die("the %s run did not load the branch's plugin: %s" % (label, "; ".join(detail)))
        # ⚠️ DID THE PROBE FINISH? A run that dies part way through emits fewer
        # PASSes and NO FAILs, which scores as a clean green — this gate reported
        # GREEN on a flag-ON run that had been cut from 56 assertions to 26 by
        # wp_send_json()'s bare `die`. Silence is not success; the sentinel is the
        # difference between "nothing failed" and "nothing was measured".
        if not live.get("Z.end"):
            die("the %s run did not reach its end sentinel — it stopped after %d "
                "assertions, so a green verdict here would mean nothing. "
                "stderr=%r" % (label, len(rows), (raw.stderr or "")[-300:]))

        state = [d for i, _, d in rows if i == "0.flagstate"]
        want = "ON" if flag_on else "OFF"
        if state and want not in state[0]:
            die("the %s run reported %s — the harness did not reach the state it claims"
                % (label, state[0]))

        print("── %s (%s) ──" % (label, state[0] if state else "?"))
        for ident, ok, detail in rows:
            if ok:
                passed += 1
            else:
                failed += 1
                findings.append("%s  %s  %s" % (label, ident, detail))
                print("   FAIL  %-28s %s" % (ident, detail))
        print("   %d passed, %d failed" % (
            sum(1 for _, ok, _ in rows if ok), sum(1 for _, ok, _ in rows if not ok)))
        print()

    print("gate 88 — %d passed, %d failed" % (passed, failed))
    if failed:
        print("############ GATE 88 RED ############")
        for f in findings:
            print("  " + f)
        return 1
    print("############ GATE 88 GREEN ############")
    return 0


if __name__ == "__main__":
    try:
        rc = main()
    finally:
        if RUN_ROOT and os.path.isdir(RUN_ROOT):
            shutil.rmtree(RUN_ROOT, ignore_errors=True)
    sys.exit(rc)
