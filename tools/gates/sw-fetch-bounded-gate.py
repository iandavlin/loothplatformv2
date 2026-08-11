#!/usr/bin/env python3
"""
sw-fetch-bounded-gate — the service worker must never leave a navigation UNSETTLED.

WHY THIS GATE EXISTS (CRAFT-STANDARD: a defect class found TWICE becomes a gate).

The class is **an unbounded wait presented to the user as "loading"**, and the PWA has
now produced it twice:

  1. 2026-06-25  a single dropped navigation dead-ended the user on offline.html with no
     retry. Fixed by adding one retry + the reconnect button in offline.html.
  2. 2026-08-09  Ian, twice in one day. Desktop Chrome span forever on a request that
     PROVABLY never reached nginx (no access-log entry) while every SW-bypassing path
     answered in milliseconds; the phone showed offline.html plus raw gate 403s.
     Backlog 3.10. The 2026-06-25 retry could not help, because it is `.catch`-guarded
     and a hung fetch never rejects.

The assertion that was missing both times is a LIVENESS one, and it is the kind a
presence-style check cannot express: not "the handler returns the right page" — it did —
but **"the handler always returns, within a bounded time, for every input including one
that never answers."**

HOW IT ASSERTS IT. tools/gates/lib/sw-handler-harness.js loads the REAL webroot/sw.js
into a stubbed ServiceWorkerGlobalScope and drives its real event handlers, so this
tests the shipped file rather than a paraphrase. The decisive input is a `fetch` stubbed
to `new Promise(() => {})` — a fetch that neither resolves nor rejects, which is exactly
what a hung request IS and exactly what a browser makes awkward to stage.

⚠️ THE HARNESS MUST INJECT HOST GLOBALS OR IT SILENTLY TESTS SOMETHING ELSE. A vm
context gets V8 intrinsics only: URL, URLSearchParams, Response, AbortController and
setTimeout are absent unless provided. Caught while red-firsting this gate — without
setTimeout the retry's promise executor THROWS, which rejects, which lands in the very
catch that serves offline.html, so a `reject` case passes in 1ms down a path no browser
takes; and without URL the flag reads as absent, so a flag-ON run measures the OFF path
and a working fix looks like it did nothing. This gate therefore asserts the harness's
own fidelity first (`--self-check`) before believing anything it reports.

WHAT IS ASSERTED, per flag state, because the flag is defaulted OFF and a master flag
otherwise neuters the tests that cover what it gates:

  OFF (today's behaviour, must not drift)   a hang is UNBOUNDED, a reject still reaches
                                            offline.html after the ~350ms retry, and a
                                            good fetch still serves the real page.
  ON  (the fix)                             a hang SETTLES inside the budget; the retry
                                            still wins slow-then-ok; a dev-gated path is
                                            NOT intercepted at all; a gate 403 becomes
                                            the claim prompt; and a partial shell still
                                            installs.

Run:   python3 tools/gates/sw-fetch-bounded-gate.py [--self-check-only]
Needs: node. No browser, no nginx, no database — so it is cheap enough to live in the
       numbered sequence and cannot flake on CDP or limit_req, the two failure modes
       that make gates 1/2/14/17 unreliable in-sequence.

⚠️ IT IS NOT FLAKE-PROOF IN GENERAL — it has real timing assertions, so the honest claim
is narrower than "cannot flake". The ON hang case must SETTLE inside BUDGET, and that
margin was re-measured when the box was downgraded to 2 cores on 2026-08-10:

    idle              8355 / 8365 / 8360 ms   (margin to the 12000 budget: ~3.64s)
    both cores pegged 8385 ms                 (margin ~3.62s — drift of 25ms)

25ms under full load, because the deadline is a wall-clock setTimeout and not CPU-bound,
so halving the core count does not move it. The margin stays comfortable and the budgets
were deliberately LEFT ALONE. Do not "optimise" the 12000 budget down to save the ~8s the
OFF hang case spends: that case has no deadline at all so any budget proves it, but a
smaller budget would stop catching a regression that put a deadline on the OFF path.

Exit:  0 green, 1 RED (real findings), 2 CANNOT RUN (no verdict).
       run-all.sh reads ONLY 0/1/2 — never invent a third code, an exit of 3 or 70 is
       counted as a finding and blocks every lane.
"""
import json
import os
import subprocess
import sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
ROOT = os.path.dirname(ROOT)
HARNESS = os.path.join(ROOT, "tools", "gates", "lib", "sw-handler-harness.js")
SW = os.path.join(ROOT, "webroot", "sw.js")
CFG = os.path.join(ROOT, "platform", "config", "pwa-sw.php")

# Must exceed the config's nav_timeout_ms, or "settled" is unmeasurable.
BUDGET = 12000
ON = "f=resilient&t=8000"

passes = failures = 0


def log(*a):
    print(" ".join(str(x) for x in a), flush=True)


def check(label, got, want):
    global passes, failures
    ok = got == want
    if ok:
        passes += 1
    else:
        failures += 1
    log(f"  {'PASS' if ok else 'FAIL'}  {label}" + ("" if ok else f"   got={got!r} want={want!r}"))
    return ok


def cannot_run(why):
    log(f"  CANNOT RUN — {why}")
    log("  (exit 2: no verdict. This is NOT a pass and NOT a finding.)")
    sys.exit(2)


def harness(case, flags=None):
    cmd = ["node", HARNESS, case, "--sw", SW, "--budget", str(BUDGET)]
    if flags:
        cmd += ["--flags", flags]
    p = subprocess.run(cmd, capture_output=True, text=True, timeout=BUDGET / 1000.0 + 30)
    if p.returncode != 0 and not p.stdout.strip():
        cannot_run(f"harness did not run ({case}): rc={p.returncode} {p.stderr.strip()[:300]}")
    try:
        return json.loads(p.stdout.strip().splitlines()[-1])
    except Exception:
        cannot_run(f"harness produced no JSON ({case}): {p.stdout[:200]!r} {p.stderr[:200]!r}")


if not os.path.isfile(HARNESS):
    cannot_run(f"missing {HARNESS}")
if not os.path.isfile(SW):
    cannot_run(f"missing {SW}")
try:
    subprocess.run(["node", "--version"], capture_output=True, check=True)
except Exception as e:
    cannot_run(f"node unavailable: {e}")

# ── 0. HARNESS FIDELITY ──────────────────────────────────────────────────────
# Believe nothing below until the stub is a faithful worker scope.
log("=== 0. HARNESS FIDELITY (a stub missing a global tests something else) ===")
fid = harness("ok", ON)
if fid.get("error"):
    cannot_run(f"sw.js threw on load in the harness: {fid['error']}")
# The retry pause is the tell: it can only be ~350ms if setTimeout really exists.
rej_off = harness("reject")
check("sw.js loads in the stubbed worker scope", fid.get("error") is None, True)
check("setTimeout is real (the ~350ms retry pause is observable)",
      rej_off.get("ms") is not None and rej_off["ms"] >= 300, True)
# The flag must actually be readable, or every ON assertion is vacuous.
on_probe = harness("dev-path", ON)
check("URL/searchParams are real (the flag is READ, not silently absent)",
      on_probe.get("handled") is False, True)

if "--self-check-only" in sys.argv:
    log("")
    log(f"  {passes} passed, {failures} failed  (self-check only)")
    sys.exit(1 if failures else 0)

# ── 1. FLAG OFF — today's behaviour, including the defect, must not drift ─────
log("=== 1. FLAG OFF: the shipped behaviour (defect included) is unchanged ===")
off_ok = harness("ok")
check("OFF: a good fetch serves the real page", off_ok.get("served_full"), "REAL PAGE")
check("OFF: a good fetch settles", off_ok.get("settled"), True)
check("OFF: a rejecting fetch still reaches offline.html", rej_off.get("served_full"), "OFFLINE PAGE")
off_slow = harness("slow-then-ok")
check("OFF: the one retry still wins a transient blip", off_slow.get("served_full"), "REAL PAGE")
check("OFF: ...and the retry was ACTUALLY exercised (2 nav fetches)", off_slow.get("nav_fetch_calls"), 2)
off_hang = harness("hang")
# This is the DEFECT, asserted as present so the OFF state is honest rather than assumed.
check("OFF: a HUNG fetch is UNBOUNDED (the defect, still there with the flag off)",
      off_hang.get("settled"), False)

# ── 2. FLAG ON — the fix, and the good behaviours it must not break ───────────
log("=== 2. FLAG ON: bounded, out of dev's way, and pointing at the claim door ===")
on_hang = harness("hang", ON)
# THE DECISIVE ASSERTION. Everything else here was green before the fix too.
check("ON: a HUNG fetch SETTLES rather than stranding the user", on_hang.get("settled"), True)
check("ON: it settles INSIDE the budget", (on_hang.get("ms") or BUDGET) < BUDGET, True)
check("ON: a hang lands on the offline page", on_hang.get("served_full"), "OFFLINE PAGE")

on_ok = harness("ok", ON)
check("ON: a good fetch still serves the real page", on_ok.get("served_full"), "REAL PAGE")
on_slow = harness("slow-then-ok", ON)
check("ON: the retry STILL wins a transient blip (not traded for the deadline)",
      on_slow.get("served_full"), "REAL PAGE")
# Guards the assertion above from going vacuous: install's shell fetches once spent the
# case's call budget, so the FIRST navigation attempt succeeded and no retry ever ran.
check("ON: ...and the retry was ACTUALLY exercised (2 nav fetches)",
      on_slow.get("nav_fetch_calls"), 2)
on_rej = harness("reject", ON)
check("ON: a rejecting fetch still reaches offline.html", on_rej.get("served_full"), "OFFLINE PAGE")

check("ON: a dev-gated path is NOT intercepted at all", on_probe.get("handled"), False)

on_403 = harness("gate-403", ON)
# served_full, NOT served: `served` is truncated for display and this assertion went
# red against a working claim prompt when it read the short field.
served403 = str(on_403.get("served_full") or "")
check("ON: a gate 403 becomes a page, not a raw nginx 403", on_403.get("status"), 200)
check("ON: that page offers the /claim door", 'href="/claim"' in served403, True)
check("ON: and explains the installed-app cookie jar", "own cookies" in served403, True)

on_inst = harness("install-partial", ON)
off_inst = harness("install-partial")
check("OFF: one 403 shell asset REJECTS the whole install (the defect)",
      str(off_inst.get("install", "")).startswith("REJECTED"), True)
check("ON: install SURVIVES a 403 shell asset", on_inst.get("install"), "RESOLVED")
check("ON: and still caches the asset it could reach",
      "/icons/icon-192.png" in (on_inst.get("shell_cached") or []), True)

# ── 3. The flag ships OFF unless a decision is recorded ──────────────────────
log("=== 3. DEFAULT: member-facing flags ship OFF unless a decision is recorded ===")
if not os.path.isfile(CFG):
    cannot_run(f"missing {CFG}")
cfg = open(CFG, encoding="utf-8").read()
armed = "'resilient_fetch' => true" in cfg.replace(" ", " ")
if not armed:
    check("shipped flag resilient_fetch is OFF", armed, False)
else:
    doc = os.path.join(ROOT, "docs", "PWA-SW-AUDIT.md")
    body = open(doc, encoding="utf-8").read() if os.path.isfile(doc) else ""
    import re
    recorded = re.search(r"^\s*#{1,6}\s*Decision to arm\b", body, re.M | re.I) is not None
    log("  flag is ARMED. That is allowed, but only with a recorded decision:")
    log("  a '## Decision to arm' section in docs/PWA-SW-AUDIT.md naming who and when.")
    log("  If this is red, RECORD THE DECISION — do not delete this check.")
    check("arming the flag is recorded in the audit doc", recorded, True)

log("")
log(f"  {passes} passed, {failures} failed")
if failures:
    log("  RED — an unbounded wait, or a flag state that drifted. See docs/PWA-SW-AUDIT.md.")
    sys.exit(1)
log("  GREEN — every navigation settles, in both flag directions.")
sys.exit(0)
