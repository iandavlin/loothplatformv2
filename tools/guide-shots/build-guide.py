#!/usr/bin/env python3
"""
build-guide.py — the actual member-facing PROFILE GUIDE.

This is the handbook, not the shot index. `build-index.py` publishes raw frames
for us to check; THIS publishes the walkthrough a member reads. Ian asked
whether it was clear we were building a user guide, and the honest answer was
no — there was a shot list and an index of screenshots and no guide.

Generated for one reason: frames arrive over several sittings (one browser on
this box, shared). A slot whose frame is not shot yet renders as a labelled
placeholder saying what it will show, so the guide reads end-to-end from day one
and NEVER shows a broken image. Re-run it after each capture batch.

  python3 tools/guide-shots/build-guide.py

Every quoted string below was read out of the served dev2 markup, not written
from memory — if the product's wording changes, this page is wrong and should be
regenerated against the new copy.
"""

import argparse, glob, html, os, re, struct, datetime

# step id -> (frame prefix, preferred viewport)
# The phone frame is preferred wherever the phone UI is the one that differs,
# because that is where members actually hit this.

STEPS = [
    dict(id="find", n="1", title="Finding your profile",
         body=[
             ("On a computer, your name sits at the top right of every page. "
              "Open that menu and choose <b>My Profile</b>.", "d1", "desktop-wide"),
             ("On a phone there is no menu up there — the bar along the bottom of the "
              "screen is how you move around, and the <b>You</b> tab on the right is "
              "your profile.", "d2", "phone"),
         ]),
    dict(id="editor", n="2", title="The page you land on is already the editor",
         intro="There is no separate “edit profile” screen to find. When you are looking "
               "at your own profile, you are looking at the editing tools — that is why "
               "the page says so at the top:",
         quote="This IS your editor — click any field (name, tagline, the photo, the "
               "privacy chips) to edit it in place. Drag the grip on a block to reorder; "
               "the Sections panel adds or removes blocks.",
         body=[("Click your name, your tagline or your photo and it becomes editable "
                "where it sits. Nothing needs saving separately.", "a1", "phone")]),
    dict(id="sections", n="3", title="Adding sections",
         intro="A profile is built from <b>sections</b> — About, Instruments, Skills, "
               "Music, Location and so on. You choose which ones you want.",
         body=[
             ("Under your name there is a <b>Your layout</b> row that tells you how many "
              "sections you are showing, with a <b>＋ Sections</b> button. There is a "
              "second, identical opener as a dashed <b>＋ Add a section</b> card at the "
              "very bottom of your profile — because that is usually where you are when "
              "you think “I want one more”.", "a6", "phone"),
             ("Either one opens the Sections panel. Drag a section in, or just tap it.",
              "a3", "phone"),
             ("On a wide screen the panel is not a pop-up at all — it sits permanently "
              "down the side, and your profile lays out in columns beside it.",
              "a4", "desktop-wide"),
         ],
         note="Some sections carry a <b>Filterable</b> badge — Instruments, Skills and "
              "Music. Those are the ones that make you findable: filling them in tags "
              "you with the site's own vocabulary so other members can turn up your "
              "profile when they search. Galleries work slightly differently — you get "
              "a set number and the panel counts down how many you have left."),
    dict(id="privacy", n="4", title="Deciding who can see what",
         intro="Privacy on Looth is set in two places, and it helps to know which is "
               "which — this is the part members most often get wrong.",
         body=[
             ("<b>The whole profile.</b> At the top, next to <i>Profile visibility</i>, "
              "one chip covers everything: <b>Public</b>, <b>Members-only</b> or "
              "<b>Private</b>. As the page puts it: “Public is the default for your "
              "whole profile — each section can override this to Members-only or "
              "Private with its own chip.”", "b1", "phone"),
             ("<b>One section at a time.</b> Every section carries its own small chip. "
              "Set your Instruments public and your Location members-only if that is "
              "what you want.", "a2", "phone"),
         ],
         note="These two interact in one direction only: <b>a section can be more "
              "private than your profile, never more public.</b> If your whole profile "
              "is members-only, a section you have marked Public still shows a warning "
              "caret (⚠) and its chip explains why — “Your header is Member-only — "
              "viewers see this as Member.” Nothing is leaking; the stricter setting "
              "simply wins.",
         note_frame=("b2", "desktop-wide")),
    dict(id="location", n="5", title="Location has two dials, not one",
         intro="Location is the one section that asks you twice, because “where I am” "
               "is usually something you will share with members but not with the open "
               "internet.",
         body=[("You set <b>Members see</b> and <b>Public sees</b> separately — for "
                "example members see your city while the public sees nothing at all. "
                "Each side can be set to Private, State, City or a street address.",
                "b3", "phone"),
               ("Precision is per-audience too, so you are never forced to choose "
                "between a useful map pin and your street address being public.",
                "b4", "phone")],
         note="Typing an address lists it exactly as you enter it and drops a map pin "
              "automatically; if the pin lands wrong you can drag it."),
    dict(id="posts", n="6", title="Your discussion posts are a separate setting",
         intro="This one catches people out. The <b>Discussion posts</b> toggle in the "
               "same panel is <i>not</i> part of your profile's visibility.",
         body=[("It controls whether your name and avatar appear on posts you make in "
                "the forums. A private profile does not silently make your posts "
                "anonymous — you set that here.", "b5", "phone")]),
    dict(id="checking", n="7", title="Checking what other people actually see",
         intro="You do not have to guess, and you should not have to log out to find "
               "out. The <b>View as</b> switch at the top of your profile shows you "
               "your own page through someone else's eyes.",
         body=[("<b>View as → Member</b> shows what a signed-in member sees.",
                "b6", "phone"),
               ("<b>View as → Public</b> shows what the open internet sees.",
                "b7", "phone")],
         note="Two things worth noticing while you are in there. The editing tools "
              "<b>disappear completely</b> — no grips, no section chips — which is how "
              "you know you are seeing the real thing rather than a preview with your "
              "own controls layered on top. But the main <b>Profile visibility</b> chip "
              "stays live, so you can flip your whole profile between Public and "
              "Members-only and watch the page change, without switching back first."),
    dict(id="outside", n="8", title="What someone who is not a member sees",
         intro="If your profile is members-only, a logged-out visitor does not get a "
               "broken page or a blank one.",
         body=[("They get a short screen explaining that the profile is members-only, "
                "with the option to sign in or join. Your name is still shown; nothing "
                "else is.", "b8", "phone"),
               ("Signed-in members see your profile normally, with none of the editing "
                "controls you see on your own.", "c1", "phone")]),
    dict(id="directory", n="9", title="Being found",
         intro="Filling in the Filterable sections is what puts you in front of other "
               "members.",
         body=[("The member directory lets people browse and filter by the same "
                "vocabulary those sections tag you with — so an empty Instruments "
                "section mostly means not turning up in other people's searches.",
                "c2", "phone")]),
]

DPR = 2


def png_size(path):
    with open(path, "rb") as f:
        head = f.read(33)
    if head[:8] != b"\x89PNG\x0d\x0a\x1a\x0a":
        return None
    return struct.unpack(">II", head[16:24])


def find_frame(d, sid, vp):
    """Prefer the requested viewport, fall back to any viewport of that shot."""
    for pat in (f"{sid}-*-{vp}.png", f"{sid}-*.png"):
        hits = sorted(glob.glob(os.path.join(d, pat)))
        if hits:
            return os.path.basename(hits[0])
    return None


def figure(d, sid, vp, caption="", rel=""):
    f = find_frame(d, sid, vp)
    if f:
        # The guide and the frames live in SEPARATE published directories, so a
        # bare filename resolves to nothing. Emit the path relative to the guide.
        f = rel + f
    if not f:
        return (f'<div class="shot pending"><span class="tag">{html.escape(sid.upper())}'
                f' · {html.escape(vp)}</span>'
                f'<span class="pend">Screenshot still to be taken</span></div>')
    cls = "shot"
    sz = png_size(os.path.join(d, f))
    warn = ""
    if sz:
        w, h = sz
        exp = {"phone": (390, 844)}.get(vp, (1440, 900))
        if h > exp[1] * DPR + 4:
            warn = ('<span class="warn">This frame was captured full-page — the fixed '
                    'bottom bar renders mid-page. Capture artifact, not a site defect. '
                    'Reshoot before this guide is shown to members.</span>')
    return (f'<div class="{cls}"><img src="{html.escape(f)}" alt="" loading="lazy">'
            f'{warn}</div>')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", default=os.path.expanduser(
        "~/projects/footer-mockups/profile-guide"))
    ap.add_argument("--shots", default=os.path.expanduser(
        "~/projects/footer-mockups/profile-guide-shots"))
    args = ap.parse_args()
    os.makedirs(args.dir, exist_ok=True)

    d = args.shots
    rel = os.path.relpath(args.shots, args.dir) + "/"
    total = pending = 0
    out = [f"""<!doctype html><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Your Looth profile — a walkthrough</title>
<style>
:root{{--bg:#f7f7f3;--card:#fff;--ink:#1d221e;--mute:#666d67;--line:#e2e5df;--accent:#5c7a4a;--warn:#a8321f;--warnbg:#fdecea;--quote:#f0f2ec}}
@media(prefers-color-scheme:dark){{:root{{--bg:#14161a;--card:#1c1f23;--ink:#e9ebe7;--mute:#98a09a;--line:#2a2e33;--accent:#9dbb86;--warn:#ff9b8a;--warnbg:#3a1f1a;--quote:#22262b}}}}
*{{box-sizing:border-box}}
body{{margin:0;background:var(--bg);color:var(--ink);font:16px/1.65 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}}
.wrap{{max-width:780px;margin:0 auto;padding:38px 20px 90px}}
h1{{font-size:30px;line-height:1.2;margin:0 0 8px;letter-spacing:-.01em}}
.lede{{color:var(--mute);font-size:17px;margin:0 0 10px}}
.toc{{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 18px;margin:24px 0 8px}}
.toc ol{{margin:0;padding-left:20px}} .toc a{{color:inherit}}
section{{margin-top:44px;padding-top:26px;border-top:1px solid var(--line)}}
h2{{font-size:21px;margin:0 0 10px;letter-spacing:-.01em}}
h2 .num{{color:var(--accent);font-weight:800;margin-right:9px}}
p{{margin:0 0 14px}}
.quote{{background:var(--quote);border-left:3px solid var(--accent);border-radius:0 8px 8px 0;padding:11px 15px;color:var(--ink);font-style:italic;margin:0 0 16px}}
.note{{background:var(--card);border:1px solid var(--line);border-radius:11px;padding:14px 17px;margin:18px 0}}
.note b{{color:var(--accent)}}
.shot{{margin:6px 0 20px;border:1px solid var(--line);border-radius:11px;overflow:hidden;background:var(--card)}}
.shot img{{display:block;width:100%;height:auto}}
.pending{{display:flex;flex-direction:column;gap:5px;align-items:center;justify-content:center;padding:26px 16px;border-style:dashed;color:var(--mute);text-align:center}}
.pending .tag{{font:700 11px/1 ui-monospace,SFMono-Regular,Menlo,monospace;letter-spacing:.08em;color:var(--accent)}}
.pending .pend{{font-size:13.5px}}
.warn{{display:block;background:var(--warnbg);color:var(--warn);font-size:12.5px;padding:8px 12px}}
.status{{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--accent);border-radius:10px;padding:13px 16px;font-size:14px;color:var(--mute);margin:26px 0 0}}
</style>
<div class="wrap">
<h1>Your Looth profile</h1>
<p class="lede">A walkthrough of what a profile is made of, who can see which parts of
it, and how to check that before anyone else does.</p>
<p class="lede">Written for a member who has just joined and has not opened their
profile yet. Nothing here needs doing in order — but the privacy section is the one
worth reading properly.</p>
<div class="toc"><ol>"""]

    for s in STEPS:
        out.append(f'<li><a href="#{s["id"]}">{html.escape(s["title"])}</a></li>')
    out.append("</ol></div>")

    for s in STEPS:
        out.append(f'<section id="{s["id"]}"><h2><span class="num">{s["n"]}</span>'
                   f'{html.escape(s["title"])}</h2>')
        if s.get("intro"):
            out.append(f'<p>{s["intro"]}</p>')
        if s.get("quote"):
            out.append(f'<div class="quote">{html.escape(s["quote"])}</div>')
        for item in s["body"]:
            text, sid, vp = item
            out.append(f"<p>{text}</p>")
            fig = figure(d, sid, vp, rel=rel)
            total += 1
            if "pending" in fig:
                pending += 1
            out.append(fig)
        if s.get("note"):
            out.append(f'<div class="note">{s["note"]}</div>')
            if s.get("note_frame"):
                sid, vp = s["note_frame"]
                fig = figure(d, sid, vp, rel=rel)
                total += 1
                if "pending" in fig:
                    pending += 1
                out.append(fig)
        out.append("</section>")

    today = datetime.date.today().isoformat()
    out.append(f"""<div class="status"><b>Draft — {today}.</b> The words are final enough
to read; {total - pending} of {total} screenshots are in place and {pending} are still
to be taken. Every quoted phrase was read from the live dev2 build rather than written
from memory. Nothing here has been checked by a member yet.</div>
</div>""")

    dest = os.path.join(args.dir, "index.html")
    with open(dest, "w") as f:
        f.write("\n".join(out))
    print(f"wrote {dest}  ({total-pending}/{total} frames present, {pending} pending)")


if __name__ == "__main__":
    main()
