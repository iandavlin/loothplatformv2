#!/usr/bin/env python3
"""
build-index.py — publish the guide frames as ONE page, grouped by guide section.

Ian decides from pictures, so this is the deliverable, not the PNG folder. It is
generated rather than hand-written for one reason: the page AUDITS ITSELF.

Every frame's pixel size is read straight out of the PNG header (no PIL on this
box) and compared with the viewport it claims. A capture taller than its own
viewport was shot with captureBeyondViewport, which renders position:fixed chrome
at its viewport offset -- the mobile tab bar ends up mid-page across the footer.
That is exactly how 5 of the 8 frames from the 7/28 pass shipped looking like a
broken site. A hand-written index would show them with a confident caption; this
one marks them RESHOOT in red and says why.

  python3 tools/guide-shots/build-index.py [--dir ~/projects/footer-mockups/profile-guide-shots]
"""

import argparse, os, re, struct, html, datetime

DPR = 2
VIEWPORT_H = {"phone": 844, "desktop-narrow": 900, "desktop-wide": 900, "desktop": 900}
VIEWPORT_W = {"phone": 390, "desktop-narrow": 1280, "desktop-wide": 1440, "desktop": 1440}

SECTIONS = [
    ("A", "The spine — what an owner sees and edits",
     "The frames the guide is built around. A3 is the one that answers Ian's "
     "complaint directly: it shows where sections actually come from."),
    ("B", "Privacy — the part Ian named",
     "Whole-profile visibility, per-section chips, the two-audience location "
     "model, and what a logged-out visitor really hits."),
    ("C", "What other people see",
     "The same profile from outside, plus the directory."),
    ("D", "Entry points",
     "How a member reaches their own profile at all — different on desktop and phone."),
]

CAPTIONS = {
    "a1": "Top of your profile as the owner: the dark controls panel is privacy only.",
    "a2": "Each section has a grip, move controls and its own privacy chip.",
    "a3": "The Sections drawer — Core and Extras, Filterable badges, gallery counter.",
    "a4": "At 1380px and wider the drawer becomes a permanent sidebar. No toggle.",
    "a5": "Avatar and banner affordances.",
    "a6": "The 'Your layout' row (option A) — the opener now sits under the identity card.",
    "a7": "The dashed 'Add a section' card at the end of the list, where the thought happens.",
    "b3": "Location carries TWO dials: what members see and what the public sees.",
    "b5": "Discussion posts is its own toggle — NOT the same as profile visibility.",
    "b6": "View as Member: the editor is gone entirely.",
    "b7": "View as Public: editor gone, privacy panel stays, and the master visibility "
          "chip is still live while per-section chips are not. Three claims, one frame.",
    "b8": "What a logged-out visitor actually hits on a members-only profile.",
    "c1": "Another member's profile: no edit affordances, no privacy chips.",
    "c2": "The directory. Mobile and desktop are separate surfaces.",
    "d1": "Desktop: the account menu carries My Profile.",
    "d2": "Phone: the bottom tab bar's You is the entry point (the header bubble is hidden).",
}


def png_size(path):
    with open(path, "rb") as f:
        head = f.read(33)
    if head[:8] != b"\x89PNG\r\n\x1a\n":
        return None
    return struct.unpack(">II", head[16:24])


def viewport_of(name):
    for v in ("desktop-narrow", "desktop-wide", "phone", "desktop"):
        if f"-{v}" in name:
            return v
    return None


def audit(name, w, h):
    """Return (ok, note). The whole point of the page."""
    vp = viewport_of(name)
    if not vp:
        return True, ""
    exp_h, exp_w = VIEWPORT_H[vp] * DPR, VIEWPORT_W[vp] * DPR
    if h > exp_h + 4:
        return False, (f"full-page capture ({h}px vs {exp_h}px for {vp}) — fixed chrome "
                       f"renders mid-page. Reshoot viewport-only.")
    if abs(w - exp_w) > 4:
        return False, (f"width {w}px ⇒ {w//DPR} CSS px, not {exp_w//DPR} — a scrollbar ate "
                       f"the viewport. Reshoot with --hide-scrollbars.")
    return True, f"{w//DPR}×{h//DPR} viewport-only"


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", default=os.path.expanduser(
        "~/projects/footer-mockups/profile-guide-shots"))
    args = ap.parse_args()

    files = sorted(f for f in os.listdir(args.dir) if f.endswith(".png"))
    by_sec = {s[0]: [] for s in SECTIONS}
    for f in files:
        m = re.match(r"([a-d])(\d)", f, re.I)
        if not m:
            continue
        sec = m.group(1).upper()
        sid = (m.group(1) + m.group(2)).lower()
        size = png_size(os.path.join(args.dir, f))
        ok, note = audit(f, *size) if size else (False, "unreadable PNG")
        by_sec.setdefault(sec, []).append((sid, f, ok, note))

    total = sum(len(v) for v in by_sec.values())
    bad = sum(1 for v in by_sec.values() for x in v if not x[2])
    today = datetime.date.today().isoformat()

    out = [f"""<!doctype html><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Profile guide — frames for the member handbook</title>
<style>
:root{{--bg:#f4f4f0;--card:#fff;--ink:#1f2420;--mute:#6d746b;--line:#e3e5df;--bad:#a8321f;--badbg:#fdecea}}
@media(prefers-color-scheme:dark){{:root{{--bg:#15171a;--card:#1d2024;--ink:#e8eae6;--mute:#9aa199;--line:#2b2f34;--bad:#ff9b8a;--badbg:#3a1f1a}}}}
*{{box-sizing:border-box}}
body{{margin:0;background:var(--bg);color:var(--ink);font:15px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:26px 20px 70px}}
.wrap{{max-width:1200px;margin:0 auto}}
h1{{font-size:23px;margin:0 0 4px}}
.sub{{color:var(--mute);max-width:80ch;margin:0 0 18px}}
h2{{font-size:17px;margin:34px 0 2px;padding-top:16px;border-top:1px solid var(--line)}}
h2 .k{{color:var(--mute);font-weight:400}}
.secnote{{color:var(--mute);max-width:80ch;margin:4px 0 14px;font-size:14px}}
.g{{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:20px}}
figure{{margin:0;background:var(--card);border:1px solid var(--line);border-radius:12px;padding:10px;overflow:hidden}}
figure img{{width:100%;height:auto;display:block;border-radius:7px;border:1px solid var(--line);background:#fff}}
figcaption{{font-size:12.5px;font-weight:700;margin-top:9px}}
figcaption .d{{display:block;font-weight:400;color:var(--mute);margin-top:3px}}
.ok{{display:block;color:var(--mute);font-weight:400;font-size:11.5px;margin-top:5px}}
.bad{{display:block;background:var(--badbg);color:var(--bad);border-radius:6px;padding:5px 7px;font-weight:600;font-size:11.5px;margin-top:6px}}
.banner{{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--bad);border-radius:9px;padding:13px 15px;max-width:80ch;margin:0 0 20px}}
.empty{{color:var(--mute);font-style:italic}}
</style>
<div class="wrap">
<h1>Profile guide — frames for the member handbook</h1>
<p class="sub">Captured on the dev2 serve, {today}. Grouped the way the guide reads.
Every frame is checked against the viewport it claims, so a bad capture is labelled
here instead of reaching a reader.</p>"""]

    if bad:
        out.append(f"""<div class="banner"><b>{bad} of {total} frames need reshooting.</b>
They were captured full-page, which renders the fixed mobile tab bar at its viewport
offset — it lands mid-page across the footer and looks like a broken site. It is a
capture artifact, not a site defect. Each one is marked below.</div>""")

    for key, title, note in SECTIONS:
        items = sorted(by_sec.get(key, []))
        out.append(f'<h2><span class="k">{key}.</span> {html.escape(title)}</h2>')
        out.append(f'<p class="secnote">{html.escape(note)}</p>')
        if not items:
            out.append('<p class="empty">Not captured yet.</p>')
            continue
        out.append('<div class="g">')
        for sid, f, ok, n in items:
            cap = CAPTIONS.get(sid, "")
            vp = viewport_of(f) or ""
            out.append(
                f'<figure><img src="{html.escape(f)}" alt="{html.escape(sid)}" loading="lazy">'
                f'<figcaption>{sid.upper()} · {html.escape(vp)}'
                f'<span class="d">{html.escape(cap)}</span>'
                + (f'<span class="ok">{html.escape(n)}</span>' if ok
                   else f'<span class="bad">RESHOOT — {html.escape(n)}</span>')
                + '</figcaption></figure>')
        out.append('</div>')

    out.append("</div>")
    dest = os.path.join(args.dir, "index.html")
    with open(dest, "w") as fh:
        fh.write("\n".join(out))
    print(f"wrote {dest}  ({total} frames, {bad} flagged)")


if __name__ == "__main__":
    main()
