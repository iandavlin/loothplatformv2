"""Rebuild the published snapshots from a FRESH render. Run after any change to
lg-profile-setup.php — a snapshot built from a stale capture is a picture of code
that no longer exists, and it is what Ian looks at."""
import re, os, sys
S=sys.argv[1]; OUT=sys.argv[2]
BANNER = """<div style="background:#1A1E12;color:#fff;font:600 13px/1.6 system-ui,sans-serif;
 padding:11px 16px;text-align:center">
 THE REAL PAGE, CAPTURED — this is the actual HTML the built step served for a real
 signed-in member, not a drawing. Every control is <b>inert here</b>: the scripts are
 stripped, so nothing on this snapshot can save, upload or change anything.
 <a href="../" style="color:#c9dd9e">&larr; back to the decision page</a>
</div>"""
PAD = """  /* The docroot injects /pwa.js here, which mounts the fixed bottom tabbar; it has
     previously landed on the exact control a mock was built to show. Reserve room. */
  body{padding-bottom:96px}
</style>"""

def build(src, dst, title):
    s=open(os.path.join(S,src),encoding="utf-8",errors="ignore").read()
    s=re.sub(r"<script\b.*?</script>","",s,flags=re.S|re.I)
    s=re.sub(r"<script\b[^>]*/?>","",s,flags=re.I)
    s=s.replace("alden","marty")
    s=s.replace("<body>","<body>"+BANNER,1)
    s=s.replace("<title>","<title>"+title+" — ",1)
    assert "</style>" in s, "no style block to pad"
    s=s.replace("</style>",PAD,1)
    # The privacy block and the location dial are hidden until the page's own JS
    # reveals them (on load, and when a location is typed). The snapshot has no JS
    # by design, so leaving them hidden would show Ian a page with the privacy
    # question missing — the exact thing he asked to see. Reveal them, and say so
    # on the page rather than silently faking a state.
    s = s.replace('<div class="privacy" id="ps-privacy" hidden>',
                  '<div class="privacy" id="ps-privacy">')
    s = s.replace('<div class="locvis" id="ps-locvis-row" hidden>',
                  '<div class="locvis" id="ps-locvis-row">')
    s = s.replace('<div class="privacy__h">While you are here &mdash; who sees this?</div>',
                  '<div class="privacy__h">While you are here &mdash; who sees this?</div>'
                  '<div class="hint"><em>Shown here so you can see them. On the live page '
                  'these appear once your current settings load, and the location one appears '
                  'as soon as you type a town. <strong>The value showing below is just the first '
                  'option in the list, not a default we impose</strong> &mdash; each dial arrives '
                  'pre-set to what you already have, and nothing is written unless you move '
                  'it.</em></div>')

    # inert: no submit, no links that 404 on this box
    s=s.replace('<button type="submit" class="btn btn--go" id="ps-save">',
                '<button type="button" class="btn btn--go" id="ps-save">')
    s=s.replace('href="/profile-setup/?skipped=1"','href="./skipped.html"')
    s=re.sub(r'href="/u/marty"','href="#" title="inert in this snapshot"',s)
    s=re.sub(r'href="https://dev2\.loothgroup\.com/"','href="#" title="inert in this snapshot"',s)
    open(os.path.join(OUT,dst),"w").write(s)
    assert not re.search(r"<script",s,re.I), "script survived"
    assert "profile-api" not in s, "live write path survived"
    print(f"  {dst}: {len(s)} bytes, scripts=0, profile-api=0")

build("rendered.html","step.html","The step")
build("skipped.html","skipped.html","If they skip")
