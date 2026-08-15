#!/usr/bin/env python3
"""
theme-independent-contrast-gate — GATE 45 — contrast defects that fail in BOTH
themes, so neither a light pass nor a dark pass can be blamed for them.

WHY THIS GATE EXISTS. dark-anon-sweep (backlog 21, gate 36) went looking for
dark-mode contrast and kept turning up findings that were not dark-mode defects
at all: a near-white foreground on a HARDCODED mid-tone fill, with no dark
variant anywhere, so it renders identically whichever theme you pick. Three
instances, found with the same instrument and the same eyes:

  bb_mirror_avatar()  bb-mirror/web/forums/_reply-render.php — an 8-colour
    palette selected by crc32($slug) and written as an INLINE style. White
    text fails AA on 3 of the 8: #c66845 3.84:1, #87986a 3.12:1, #a0714f
    4.23:1.
  loothalong.js:170   crew avatars — avatar('#6b7c52'), ('#c66845'),
    ('#b98a3e'), ('#7d8a5c') under a near-white icon (rgba(251,251,248,.95)).
    #b98a3e measures 2.85:1 against the 3:1 icon-control bar. Its selector
    prefix is '#' + EL_ID — an id, NOT a theme scope, which is the thing that
    proves it theme-independent rather than a dark defect.
  The PWA install button, from the other side — gate 36's wave fixed its DARK
    half (2.29:1); the LIGHT half is still 3.12:1.

By the craft law a class found twice becomes a gate BEFORE it is fixed the
second time (docs/CRAFT-STANDARD.md). This one reached three. Fixing any of it
behind a flag named "dark" would mislabel it permanently for the next reader,
which is why it is a separate gate and not more of gate 36.
KEEPER GATE NUMBER: 45, assigned by keeper 2026-08-15 (ledger next-free becomes
46 on their next commit). Verified free on origin/main before use — a lane does
not mint gate numbers, and two lanes have collided minting independently.
Do not renumber without asking.

SCOPE LINE, keeper's words, so this stays honest: this gate covers contrast
defects that appear in BOTH themes. The dark ratchet (gate 36) stays its own
gate. The two do not overlap by construction — gate 36 owns the six anon
sign-in/join surfaces, this one owns the six it does not.

WHAT "THEME-INDEPENDENT" MEANS HERE, MECHANICALLY. Every surface is measured
TWICE, once resolved light and once resolved dark, with the SAME probe
(tools/gates/lib/contrast-probe.js — the same one gate 36 and the sweep use, so
this gate cannot go green on an element the sweep photographed as failing). An
element counts only when it fails in BOTH passes, matched by selector. That is
the definition, and it is deliberately the weaker of the two available ones:
a defect that fails in both themes for DIFFERENT reasons is still a defect that
no theme pass will catch. Where the colours are identical across both passes
the report says so — that is the stronger signal, and it is what a hardcoded
palette looks like from the outside.

A RATCHET, NOT A ZERO-FINDINGS GATE — same shape and same reason as gate 36.
The class is real and larger than one wave, so a zero assertion merged today
would block every other lane's train for debt this lane is still working
through. It asserts no regression past BASELINE, per surface.

RED-FIRST. The comparison logic is red-fired on every run by _ratchet_selftest()
before a browser is opened — no CDP needed, proving a genuine regression reddens
and holding steady does not. Gate 36 earned the same guarantee the same way.

DELIBERATE COUPLING, AND WHY IT IS NOT A COPY. Session / gate_env / arm_anon /
measure / ratchet_verdict are imported from the gate-36 module rather than
re-implemented. Two independent copies of contrast plumbing is exactly how two
gates start disagreeing about the same page, and then nobody can tell which
number is lying. The right long-term home is tools/gates/lib/, and that
extraction is NOT done here on purpose: gate 36 was in a live merge suite when
this was written, and editing a gate mid-suite is how a green train turns red
for a reason nobody can attribute. Extract after the train lands.

Usage:  python3 tools/gates/theme-independent-contrast-gate.py
        python3 tools/gates/theme-independent-contrast-gate.py --capture
          Prints a BASELINE block from a live run instead of judging against
          one. Use when re-baselining; never hand-edit the numbers.
Needs:  chrome-dev on 127.0.0.1:9222, tools/gates/gate-env.sh resolving a token.
Exit 0 = GREEN (no surface exceeds baseline). 1 = RED. 2 = CANNOT RUN (never a
silent 0 on a broken harness — see trap-gate-exit-code-3-blocks-every-lane).
"""

import importlib.util
import json
import os
import pathlib
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))

# Import gate 36's module by path (it is not an importable package name).
# Importing it does NOT run its main() — that is guarded by __name__.
_spec = importlib.util.spec_from_file_location(
    "_anon_dark_gate", os.path.join(HERE, "anon-dark-contrast-gate.py"))
_g = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_g)

Session = _g.Session
gate_env = _g.gate_env
arm_anon = _g.arm_anon
ratchet_verdict = _g.ratchet_verdict
DESKTOP, PHONE, PROBE = _g.DESKTOP, _g.PHONE, _g.PROBE

# The six anon surfaces gate 36 does NOT cover, so the two gates never both
# own a page. All three known instances of the class live in here.
SURFACES = [
    ("hub",       "/hub/"),
    ("hub-door",  "/hub/acoustic/"),
    ("events",    "/events/"),
    ("directory", "/directory/members/"),
    ("shop",      "/shop/"),
    ("sponsors",  "/sponsors/"),
]

# Recorded 2026-08-15 by --capture against the live serve. Counts are
# BOTH-THEME failures only, which is why they sit far below the sweep's
# per-theme totals. See the module docstring for what qualifies.
#
# COUNTS ARE DEFECT TYPES, NOT INSTANCES, as of the position-free key — see
# defect_key(). Five failing avatars are one keyed defect, which is both the
# number of things someone has to fix and far stabler than an instance count
# that moves with however many cards lazy-loaded that second. Old instance-based
# floor was 55; this is 20, and it is a tighter, more meaningful 20.
#
# THE KEY FIX REDUCED THE VARIANCE BUT DID NOT REMOVE IT, and I am recording that
# rather than claiming a win I did not get. hub-door/mobile used to swing 19/0;
# it now swings 0/3. So the :nth-child mismatch was a real and large cause, but
# a second cause remains — elements genuinely present in one run and absent in
# the next (lazy-loaded content, the engagement-gated install banner). Two
# captures still disagreed on 6 of 12 surfaces, so this floor is the per-surface
# MAX of both. One run is not a distribution; that rule has not stopped being
# true just because the numbers got smaller.
#
BASELINE = {
    "hub/desktop": 2, "hub/mobile": 2,
    "hub-door/desktop": 2, "hub-door/mobile": 3,
    "events/desktop": 1, "events/mobile": 3,
    "directory/desktop": 1, "directory/mobile": 2,
    "shop/desktop": 1, "shop/mobile": 2,
    "sponsors/desktop": 0, "sponsors/mobile": 1,
}


def theme_ok(theme, got):
    """Did the page resolve the theme we asked for? Dark is the only state with
    a positive marker: light/default legitimately reports 'default' or nothing
    at all, so 'not dark' is the honest test for the light side rather than
    demanding a value the app never sets."""
    return (got == "dark") if theme == "dark" else (got != "dark")


def measure_theme(s, tok, host, probe_js, path, theme, metrics, patience=1.0):
    """One surface, one resolved theme. Returns the raw probe result.

    LIVENESS MATTERS ON BOTH SIDES HERE, not just the dark one. A page stuck in
    the wrong theme reports findings that are real for a theme nobody asked
    about, and the BOTH-THEMES intersection would then be measuring one theme
    twice and calling it theme-independent — green for a reason that is not
    true. The caller asserts the resolved theme against what it asked for.
    """
    url = host + path
    arm_anon(s, tok)
    s.call("Emulation.setDeviceMetricsOverride", **metrics)
    s.call("Emulation.setTouchEmulationEnabled", enabled=metrics["mobile"])
    s.call("Emulation.setEmulatedMedia", features=[
        {"name": "prefers-color-scheme", "value": "dark" if theme == "dark" else "light"}])

    # THE CLEAR RACES THE PAGE THAT IS STILL LOADING, which is what made two
    # mobile surfaces resolve DARK during the LIGHT pass (caught 2026-08-15 by
    # this gate's own liveness assertion, not by inspection). app-settings.js
    # persists a boot hint after ANY successful resolution, so on the previous
    # surface's dark pass it writes one; clearing too early lets that write land
    # AFTER the clear, and the next load's boot script then pre-paints dark from
    # a key we thought we had removed. Settle first so that write has definitely
    # happened, THEN clear it, then reload into the theme we actually want.
    s.goto(url, settle=1.4 * patience)
    s.js("try{localStorage.clear();sessionStorage.clear()}catch(e){}")
    s.js("try{localStorage.setItem('lg-set-theme','%s')}catch(e){}"
         % ("dark" if theme == "dark" else "default"))
    s.goto(url, settle=1.6 * patience)
    s.goto(url, settle=2.0 * patience)
    return s.js(probe_js)


POSITION = re.compile(r":nth-(?:child|of-type|last-child|last-of-type)\(\s*[^)]*\)")


def defect_key(f):
    """A POSITION-FREE identity for a finding.

    The first version of this gate matched light-pass findings to dark-pass ones
    by raw selector string. Those selectors carry :nth-child() positions, and the
    mobile feed lazy-loads a variable number of cards — so the SAME defect sat at
    a different index in the two passes, the selectors did not match, nothing
    intersected, and the surface reported a confident 0. hub-door/mobile read 19
    on two runs and 0 on a third for exactly that reason: a FALSE CLEAN, which is
    the vacuous-green shape this lane keeps finding.

    Key is (kind, selector-with-positions-stripped). Colour is deliberately NOT
    in the key: the whole point is to match an element across two themes where
    its colours are expected to differ, so keying on colour would silently
    narrow this gate to hardcoded palettes only and miss every defect that fails
    in both themes for different reasons.

    Stripping positions also COLLAPSES repeated instances — five failing avatars
    become one keyed defect rather than five. That is the intended meaning:
    counts here are distinct DEFECT TYPES per surface, not instances, which is
    both stabler against lazy-load variance and closer to the number of things
    someone has to fix.
    """
    return (f.get("kind", ""), POSITION.sub("", f.get("sel", "")))


def both_theme_failures(light, dark):
    """Defect TYPES that fail in BOTH passes, matched position-free.

    Returns one entry per distinct key, carrying each side's numbers plus
    `hardcoded` — True when the colours are identical in both themes, the shape
    a hardcoded palette has and the strongest evidence nothing repoints.
    """
    lf = {}
    for f in (light.get("findings") or []):
        lf.setdefault(defect_key(f), f)          # first instance represents the type
    out, seen = [], set()
    for f in (dark.get("findings") or []):
        k = defect_key(f)
        if k in seen:
            continue
        g = lf.get(k)
        if not g:
            continue
        seen.add(k)
        out.append({
            "sel": f["sel"],
            "sample": f.get("sample", ""),
            "kind": f.get("kind", ""),
            "need": f.get("need"),
            "light": {"ratio": g.get("ratio"), "fg": g.get("fg"), "bg": g.get("bg")},
            "dark": {"ratio": f.get("ratio"), "fg": f.get("fg"), "bg": f.get("bg")},
            "hardcoded": g.get("fg") == f.get("fg") and g.get("bg") == f.get("bg"),
        })
    return out


def _both_theme_selftest():
    """Red-fire the intersection itself. The whole gate rests on this function
    being right about what 'fails in both' means, and it is the one piece a
    browser run can never falsify — a green run proves nothing about an
    intersection that silently returns [].
    """
    mk = lambda sel, fg, bg, r: {"sel": sel, "fg": fg, "bg": bg, "ratio": r,
                                 "need": 4.5, "kind": "text", "sample": "x"}
    cases = []

    # fails in both, identical colours -> caught, and flagged hardcoded
    r = both_theme_failures({"findings": [mk("a", "#fff", "#b98a3e", 2.8)]},
                            {"findings": [mk("a", "#fff", "#b98a3e", 2.8)]})
    cases.append(("both themes, same colours", len(r) == 1 and r[0]["hardcoded"]))

    # fails in both, different colours -> still caught, not flagged hardcoded
    r = both_theme_failures({"findings": [mk("a", "#000", "#777", 3.0)]},
                            {"findings": [mk("a", "#fff", "#888", 2.5)]})
    cases.append(("both themes, different colours", len(r) == 1 and not r[0]["hardcoded"]))

    # dark-only -> NOT this gate's business (gate 36 owns it)
    r = both_theme_failures({"findings": []},
                            {"findings": [mk("a", "#fff", "#9cb37d", 2.29)]})
    cases.append(("dark-only is excluded", r == [])),

    # light-only -> also excluded
    r = both_theme_failures({"findings": [mk("a", "#fff", "#87986a", 3.12)]},
                            {"findings": []})
    cases.append(("light-only is excluded", r == []))

    # different elements failing in each theme must NOT intersect
    r = both_theme_failures({"findings": [mk("a", "#fff", "#87986a", 3.1)]},
                            {"findings": [mk("b", "#fff", "#9cb37d", 2.3)]})
    cases.append(("different elements do not intersect", r == []))

    # THE BUG THIS GATE SHIPPED WITH: the same defect at a different lazy-load
    # index. Raw-selector matching returned [] here — a confident false clean.
    r = both_theme_failures(
        {"findings": [mk("ul > li:nth-child(2) > span.pts", "#fff", "#87986a", 3.1)]},
        {"findings": [mk("ul > li:nth-child(7) > span.pts", "#fff", "#9cb37d", 2.3)]})
    cases.append(("same defect at a different index still matches", len(r) == 1))

    # repeated instances collapse to ONE defect type, not five
    r = both_theme_failures(
        {"findings": [mk(f"ul > li:nth-child({i}) > span.av", "#fff", "#87986a", 3.1) for i in (1, 2, 3)]},
        {"findings": [mk(f"ul > li:nth-child({i}) > span.av", "#fff", "#9cb37d", 2.3) for i in (4, 5, 6)]})
    cases.append(("repeated instances collapse to one type", len(r) == 1))

    # position-stripping must not merge genuinely DIFFERENT elements
    r = both_theme_failures(
        {"findings": [mk("li:nth-child(1) > span.pts", "#fff", "#87986a", 3.1),
                      mk("li:nth-child(1) > span.rank", "#fff", "#87986a", 3.1)]},
        {"findings": [mk("li:nth-child(9) > span.pts", "#fff", "#9cb37d", 2.3),
                      mk("li:nth-child(9) > span.rank", "#fff", "#9cb37d", 2.3)]})
    cases.append(("distinct element types stay distinct", len(r) == 2))

    bad = [n for n, ok in cases if not ok]
    for n, ok in cases:
        print(f"  {'ok  ' if ok else 'FAIL'} both-theme self-test: {n}")
    if bad:
        print(f"  both-theme self-test FAILED: {bad}")
        sys.exit(2)
    print("  both-theme self-test: all cases correct\n")


def run(capture=False):
    _g._ratchet_selftest()
    _both_theme_selftest()

    env = gate_env()
    host, tok = env["LG_GATE_HOST"], env["LG_GATE_TOKEN"]
    probe_js = pathlib.Path(PROBE).read_text()

    s = Session()
    red, cannot, captured = [], [], {}
    try:
        s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
        for device, metrics in (("desktop", DESKTOP), ("mobile", PHONE)):
            for key, path in SURFACES:
                label = f"{key}/{device}"
                pair = {}
                for theme in ("light", "dark"):
                    data = None
                    for attempt in range(2):
                        try:
                            data = measure_theme(s, tok, host, probe_js, path, theme, metrics)
                            break
                        except Exception as e:                      # noqa: BLE001
                            print(f"  WARN  {label}/{theme}: {str(e)[:90]}"
                                  + (" — reconnecting" if attempt == 0 else ""))
                            try:
                                s.finish()
                            except Exception:                       # noqa: BLE001
                                pass
                            s = Session()
                            s.call("Page.enable"); s.call("Runtime.enable"); s.call("Network.enable")
                    if data is None:
                        cannot.append(f"{label}/{theme}: connection failed twice")
                        break
                    # Liveness on BOTH sides — see measure_theme's docstring.
                    # One patient retry before calling it: the persistence race
                    # above is timing-sensitive and this box's contention is
                    # real, so a single miss is not yet evidence of a defect —
                    # but a miss that survives a slower run IS, and it must
                    # never be shrugged past, because an intersection built on
                    # a mis-resolved pass is one theme measured twice.
                    if not theme_ok(theme, data.get("theme")):
                        try:
                            data = measure_theme(s, tok, host, probe_js, path,
                                                 theme, metrics, patience=2.0)
                        except Exception as e:                      # noqa: BLE001
                            print(f"  WARN  {label}/{theme}: patient retry errored: {str(e)[:70]}")
                    if not theme_ok(theme, data.get("theme")):
                        red.append(f"RED  {label}/{theme}  THEME NEVER RESOLVED (asked {theme}, "
                                   f"got {data.get('theme')!r}, survived a patient retry) — an "
                                   f"intersection built on this would be one theme measured twice")
                        break
                    pair[theme] = data
                if len(pair) != 2:
                    # Judging mode must not skip quietly either: an unmeasured
                    # surface is an absent verdict, not a passing one.
                    if not capture and not any(label in x for x in red):
                        cannot.append(f"{label}: only {len(pair)}/2 themes measured")
                    continue

                hits = both_theme_failures(pair["light"], pair["dark"])
                captured[label] = len(hits)
                if capture:
                    print(f"  {label}  {len(hits)} both-theme finding(s)")
                    continue
                status, detail = ratchet_verdict(label, hits, BASELINE)
                if status == "RED":
                    red.append(f"RED  {label}  {detail}")
                    for h in hits:
                        tag = " HARDCODED" if h["hardcoded"] else ""
                        red.append(
                            f"     {label}  {h['kind']}{tag}  light {h['light']['ratio']}:1 "
                            f"({h['light']['fg']} on {h['light']['bg']}), dark {h['dark']['ratio']}:1 "
                            f"({h['dark']['fg']} on {h['dark']['bg']})  need {h['need']}:1  "
                            f"[{h['sel'][-60:]}]  \"{h['sample'][:40]}\"")
                elif status == "IMPROVED":
                    print(f"  IMPROVED  {label}  {detail}")
                nhard = sum(1 for h in hits if h["hardcoded"])
                print(f"  ok   {label}  {len(hits)} both-theme finding(s) "
                      f"({nhard} hardcoded) (baseline {BASELINE.get(label, 0)})")
    finally:
        try:
            s.finish()
        except Exception:                                            # noqa: BLE001
            pass

    if capture:
        # A SURFACE THAT WAS NEVER MEASURED MUST NOT BECOME A BASELINE OF 0.
        # The first cut of this function returned here before the red/cannot
        # blocks below ever printed, and filled gaps with .get(label, 0) — so a
        # surface that failed to resolve its theme, or dropped its connection,
        # was silently recorded as CLEAN. sponsors/mobile did exactly that on
        # the first capture run: eleven surfaces measured, twelve emitted, and
        # the twelfth was a zero nobody had earned. A baseline is a claim about
        # what was observed; emitting a number for an unobserved surface is the
        # vacuous-green failure this whole lane keeps finding elsewhere.
        missing = [f"{k}/{d}" for k, _ in SURFACES for d in ("desktop", "mobile")
                   if f"{k}/{d}" not in captured]
        if red or cannot or missing:
            print("\nCAPTURE INCOMPLETE — refusing to emit a baseline:\n")
            for line in red:
                print("  " + line)
            for c in cannot:
                print("  CANNOT RUN  " + c)
            for m in missing:
                print(f"  NEVER MEASURED  {m} — would have been silently recorded as 0")
            print("\nFix the cause and re-capture. Do not hand-fill the gaps.")
            return 2
        print("\nBASELINE = {")
        for key, _ in SURFACES:
            print("    " + ", ".join(f'"{key}/{d}": {captured[f"{key}/{d}"]}'
                                     for d in ("desktop", "mobile")) + ",")
        print("}")
        return 0

    if cannot and not red:
        print("\nCANNOT RUN:")
        for c in cannot:
            print("  " + c)
        return 2
    if red:
        print(f"\n{len(red)} line(s) — a surface regressed past its recorded BASELINE, "
              f"or a theme never resolved:\n")
        for r in red:
            print(r)
        return 1
    print("\nGREEN — no surface has MORE both-theme contrast findings than its baseline. "
          "This is a floor, not a finish line: findings that fail in only ONE theme are "
          "deliberately invisible here (gate 36 owns dark), so green here never means "
          "the page is clean.")
    return 0


if __name__ == "__main__":
    sys.exit(run(capture="--capture" in sys.argv))
