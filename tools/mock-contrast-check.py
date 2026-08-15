#!/usr/bin/env python3
"""WCAG 2.x contrast ratio check for the featured-members mock's new dark
components. Not a formal gate (mocks aren't member-facing content) — craft QA
before the build, per CRAFT-STANDARD's "a defect class found twice becomes a
gate" philosophy: catch it now, cheaply, rather than after Ian's phone does."""
import sys, re

def srgb_to_lin(c):
    c /= 255.0
    return c/12.92 if c <= 0.04045 else ((c+0.055)/1.055) ** 2.4

def luminance(hexcol):
    hexcol = hexcol.lstrip('#')
    r, g, b = (int(hexcol[i:i+2], 16) for i in (0, 2, 4))
    R, G, B = srgb_to_lin(r), srgb_to_lin(g), srgb_to_lin(b)
    return 0.2126*R + 0.7152*G + 0.0722*B

def composite_over(fg_rgba, bg_hex):
    """An rgba() colour (r,g,b,alpha 0-1) painted over an opaque bg_hex. Needed
    because a `background: rgba(255,255,255,.05)` panel's TRUE colour depends on
    what sits behind it — guessing the wrong parent background is how the
    in-slab meter's failure was initially missed."""
    r,g,b,a = fg_rgba
    bg = bg_hex.lstrip('#'); br,bgg,bb = (int(bg[i:i+2],16) for i in (0,2,4))
    return '#%02x%02x%02x' % (round(r*a+br*(1-a)), round(g*a+bgg*(1-a)), round(b*a+bb*(1-a)))

def ratio(c1, c2):
    L1, L2 = luminance(c1), luminance(c2)
    L1, L2 = max(L1, L2), min(L1, L2)
    return (L1 + 0.05) / (L2 + 0.05)

# (label, foreground, background, AA threshold, is-large-text)
CHECKS = [
    # ── dark-slab components (Variant A, the completeness meter inside the slab) ──
    ("slab bg #22262a — text #cfd3cb (existing product default)",        "#cfd3cb", "#22262a", 4.5, False),
    ("slab note (opacity .8 approximated as blended) — skip, CSS opacity not testable by hex", None, None, None, None),
    ("meter (dark card #1e2124) — item text #c3c8bd",                     "#c3c8bd", "#1e2124", 4.5, False),
    ("meter (dark card, .tp-dark) — item--todo NOW var(--lg-mute)=#a6ac9f", "#a6ac9f", "#1e2124", 4.5, False),
    ("meter (in-slab, u.php-pinned bg composite #2d3135) — todo NOW #a6ac9f", "#a6ac9f", "#2d3135", 4.5, False),
    ("meter (dark card) — pct/charcoal-inverted #f2f4ee",                 "#f2f4ee", "#1e2124", 3.0, True),
    ("meter (dark card) — lbl/mute #a6ac9f",                              "#a6ac9f", "#1e2124", 4.5, False),
    ("meter (dark card) — next-note #c3c8bd",                             "#c3c8bd", "#1e2124", 4.5, False),
    ("meter track fill LOW (amber #ecb351) on track #2c312d — decorative", None, None, None, None),
    # ── consent card (Variant B) ──
    ("featcard (dark #1e2124) — body text #c3c8bd",                       "#c3c8bd", "#1e2124", 4.5, False),
    ("featcard (dark #1e2124) — heading #f2f4ee",                         "#f2f4ee", "#1e2124", 3.0, True),
    ("featcard (dark #1e2124) — label #e5e7e1",                           "#e5e7e1", "#1e2124", 4.5, False),
    ("featcard--in (dark, bg #24211a) — meta text #a6ac9f",               "#a6ac9f", "#24211a", 4.5, False),
    ("featcard--in (dark, bg #24211a) — heading #f2f4ee",                 "#f2f4ee", "#24211a", 3.0, True),
    # ── light-theme equivalents, for completeness ──
    ("meter (light card #fff) — item text (inherits --lg-ink #323532)",   "#323532", "#ffffff", 4.5, False),
    ("meter (light card #fff) — todo NOW var(--lg-mute)=#6b6f6b", "#6b6f6b", "#ffffff", 4.5, False),
    ("featcard (light #fff) — body text #4c4f47",                        "#4c4f47", "#ffffff", 4.5, False),
    ("featcard--in (light, bg #fffdf7) — meta #6b6f6b",                   "#6b6f6b", "#fffdf7", 4.5, False),
    # ── front-page card CTA (both themes reuse the same declared colours) ──
    ("lg-fm__cta light — text #fff on bg #6b7c52 (--lg-sage-d)",          "#ffffff", "#6b7c52", 4.5, False),
    ("lg-fm__cta dark — text #15171a (--fp-cta-ink) on bg #b0c693",       "#15171a", "#b0c693", 4.5, False),
    # ── the amber "next" tick badge, both themes ──
    ("meter__item--next tick dark — amber #ecb351 on badge #3a3220",      "#ecb351", "#3a3220", 3.0, True),
    ("meter__item--next tick light — amber #8a6326 on badge #fdf0d8",     "#8a6326", "#fdf0d8", 3.0, True),
]

fails = []
print(f"{'ratio':>6}  {'need':>5}  status  check")
for label, fg, bg, need, large in CHECKS:
    if fg is None:
        print(f"{'--':>6}  {'--':>5}  SKIP    {label}")
        continue
    r = ratio(fg, bg)
    ok = r >= need
    print(f"{r:6.2f}  {need:5.1f}  {'PASS' if ok else 'FAIL':6}  {label}")
    if not ok:
        fails.append((label, r, need))

print()
if fails:
    print(f"{len(fails)} FAIL(S):")
    for label, r, need in fails:
        print(f"  ✗ {label}: {r:.2f} < {need}")
    sys.exit(1)
print(f"All {len(CHECKS)-sum(1 for c in CHECKS if c[1] is None)} checked pairs meet WCAG AA.")
