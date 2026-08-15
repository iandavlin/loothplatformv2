#!/usr/bin/env python3
"""GATE 53 — .lgpo-subtext keeps a dark-theme ink, and its OFF path stays a no-op.

Number 53 ASSIGNED BY KEEPER 2026-08-15 (52 is frontend-compose's; ledger next 54).

THE DEFECT. `.lgpo-subtext` in lg-patreon-stripe-poller/lg-patreon-onboard.php was
hardcoded `color: #666`. That was chosen against a white page — 5.57:1 on the login
card's light #fbfcf8, comfortably AA — and nothing ever gave it a dark counterpart.
Under dark the card underneath becomes --lg-dark-card #1b1e21 while the ink stays
#666: 2.92:1, under the 4.5:1 AA text bar. Measured on the real served page with
gate 36's own contrast probe, os-dark AND app-dark, mobile AND desktop — one
finding, all four states, theme=dark confirmed on each.

WHY THIS IS ITS OWN GATE AND NOT JUST GATE 36's RATCHET. Gate 36 reads the SERVE,
which is main, and it counts findings per surface. It can tell you the number moved;
it cannot tell you the OFF path is still byte-identical, and it cannot run without a
browser. This gate is a static render: no CDP, no network, cannot flake, and it
fails for a NAMED reason.

READS THE FLAG, DOES NOT HARDCODE THE STATE. Both states are asserted independently
(OFF adds nothing, ON adds exactly the rule), so flipping the shipped default in
either direction needs NO edit here. The default is REPORTED, never asserted — that
is the whole point: a gate that asserted "the rule is present" would have gone red
the moment the fix shipped defaulted OFF, and blocked every lane's train.

Exit 0 green / 1 red / 2 cannot run.
"""

import io
import os
import re
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", ".."))
TARGET = os.path.join(REPO, "lg-patreon-stripe-poller", "lg-patreon-onboard.php")

CARD_BG = "#1b1e21"      # --lg-dark-card, the surface this ink actually lands on
AA_TEXT = 4.5


def cannot(msg):
    print("CANNOT RUN  " + msg)
    sys.exit(2)


def contrast(fg, bg):
    def lin(c):
        c /= 255.0
        return c / 12.92 if c <= 0.04045 else ((c + 0.055) / 1.055) ** 2.4

    def lum(h):
        h = h.lstrip("#")
        if len(h) == 3:
            h = "".join(ch * 2 for ch in h)
        r, g, b = (int(h[i:i + 2], 16) for i in (0, 2, 4))
        return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)

    a, b = lum(fg), lum(bg)
    hi, lo = max(a, b), min(a, b)
    return (hi + 0.05) / (lo + 0.05)


def render(src, flag_literal):
    """Render the shortcode's template region with the flag forced to a state.

    The region is lifted VERBATIM out of the file under test — never retyped —
    so this gate cannot drift away from the thing it is gating.
    """
    try:
        start = src.index("    // Dark-mode ink for .lgpo-subtext")
        end = src.index("    return ob_get_clean();", start) + len("    return ob_get_clean();")
    except ValueError:
        return None
    region = src[start:end]
    prog = (
        "<?php\n"
        "define('LG_DARK_ONBOARD_SUBTEXT_FIX', %s);\n"
        "function esc_url($u){return $u;}\n"
        "$auth_url='https://example.test/';\n"
        "function render(){ global $auth_url;\n" % flag_literal
    ) + region + "\n}\necho render();\n"
    with tempfile.NamedTemporaryFile("w", suffix=".php", delete=False, encoding="utf-8") as fh:
        fh.write(prog)
        path = fh.name
    try:
        r = subprocess.run(["php", path], capture_output=True, text=True, timeout=30)
        if r.returncode != 0:
            return None
        return r.stdout
    finally:
        os.unlink(path)


def main():
    if not os.path.exists(TARGET):
        cannot("target file missing: " + TARGET)
    if subprocess.run(["which", "php"], capture_output=True).returncode != 0:
        cannot("php CLI not available")

    src = io.open(TARGET, encoding="utf-8").read()
    fails = []

    # ---- the flag must still exist and still be readable from the environment
    if "LG_DARK_ONBOARD_SUBTEXT_FIX" not in src:
        fails.append("LG_DARK_ONBOARD_SUBTEXT_FIX is gone from the file — the fix "
                     "was inlined or reverted; this gate can no longer prove either state")
    else:
        if "getenv( 'LG_DARK_ONBOARD_SUBTEXT_FIX' )" not in src:
            fails.append("the flag no longer reads getenv() — an nginx fastcgi_param "
                         "preview would serve the wrong state")
        if "$_SERVER['LG_DARK_ONBOARD_SUBTEXT_FIX']" not in src:
            fails.append("the flag no longer reads $_SERVER — fastcgi_param lands there "
                         "ONLY, so a preview URL would serve the wrong state")

    off = render(src, "false")
    on = render(src, "true")
    if off is None or on is None:
        cannot("could not render the shortcode template region (moved or renamed?)")

    # ---- OFF adds nothing at all -------------------------------------------
    if "lguser-theme" in off or "a8ada6" in off:
        fails.append("FLAG OFF still emits a dark rule — OFF must be a byte-identical no-op")

    # ---- ON adds exactly the rule, and only that ---------------------------
    extra = on.count("\n") - off.count("\n")
    if extra != 1:
        fails.append(f"FLAG ON adds {extra} lines, expected exactly 1 "
                     f"(the .lgpo-subtext dark rule and nothing else)")

    m = re.search(r"html\[data-lguser-theme='dark'\]\s*\.lgpo-subtext\s*\{\s*color:\s*(#[0-9a-fA-F]{3,6})\s*;?\s*\}", on)
    if not m:
        fails.append("FLAG ON does not emit an html[data-lguser-theme='dark'] .lgpo-subtext "
                     "colour rule — the dark ink fix is not being served")
    else:
        ink = m.group(1)
        ratio = contrast(ink, CARD_BG)
        if ratio < AA_TEXT:
            fails.append(f"FLAG ON emits {ink} on the dark card {CARD_BG} = {ratio:.2f}:1, "
                         f"under the {AA_TEXT}:1 AA text bar — the fix does not actually fix it")
        else:
            print(f"  ok   ON emits {ink} on {CARD_BG} = {ratio:.2f}:1 (need {AA_TEXT}:1)")

    # ---- the LIGHT value must not have been touched ------------------------
    if not re.search(r"\.lgpo-subtext\s*\{[^}]*color:\s*#666", src):
        fails.append("the LIGHT .lgpo-subtext colour is no longer #666 — light measured "
                     "5.57:1 and was deliberately left alone; changing it is an unmeasured "
                     "change to a passing surface")

    # ---- report (never assert) which way the shipped default points --------
    # The plugin cannot be require()'d standalone (it needs WordPress), so the
    # default is read out of the source rather than executed. REPORTED ONLY —
    # asserting it is what would make this gate block a legitimate flip.
    default_on = "! $lgpo_subtext_off" in src
    print("  info shipped default: %s (reported, never asserted — flipping it "
          "needs no edit here)" % ("ON" if default_on else "OFF"))

    if fails:
        print("\nGATE 53 RED — .lgpo-subtext dark ink")
        for f in fails:
            print("  FAIL  " + f)
        return 1

    print("GATE 53 GREEN — OFF is a no-op, ON emits an AA-clearing dark ink, light untouched")
    return 0


if __name__ == "__main__":
    sys.exit(main())
