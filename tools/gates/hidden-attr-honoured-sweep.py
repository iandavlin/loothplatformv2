#!/usr/bin/env python3
"""Sweep: does every component rendered with `hidden` actually stay hidden?

THE INVARIANT. `hidden` is only `display:none` from the UA stylesheet, so ANY author
`display:` on the same element silently defeats it. A component whose PHP renders it
with a `hidden` attribute, and whose CSS sets `display` on the bare class, is painted
open from first render — while its JS toggle keeps flipping an attribute with no
visual effect. That is backlog 3.6, found twice in one sitting:

  .post__menu               Edit/Delete dropdown open on every topic page, all widths
  .reply-form__replying-to  "↩ replying to …" banner shown when you are not replying

NOT WIRED INTO run-all.sh YET, on purpose. The scan finds candidates; only two are
confirmed by measurement. Reddening the suite on the rest would assert things I have
not proven — and one of them (.ntm-form) sets display, has no guard, and does NOT
misrender, because a guarded ancestor hides it. Verify a candidate in a browser, and
check its reveal path still works, BEFORE adding a guard: a guard on something whose
JS never clears `hidden` makes it permanently unreachable, which is worse than the bug.

Usage:  python3 tools/gates/hidden-attr-honoured-sweep.py
"""
import re, glob, os, sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CSS = os.path.join(REPO, "bb-mirror", "web", "forums.css")
CONFIRMED = {"post__menu", "reply-form__replying-to"}   # measured misrendering

def main():
    css = re.sub(r"/\*.*?\*/", "", open(CSS).read(), flags=re.S)   # a commented rule is not a rule
    markup = ""
    for f in glob.glob(os.path.join(REPO, "bb-mirror/web/forums/*.php")) + \
             glob.glob(os.path.join(REPO, "bb-mirror/web/*.php")):
        markup += open(f, errors="ignore").read()

    hidden = set()
    for m in re.finditer(r'class="([^"]+)"[^>]{0,200}?\shidden[\s>]', markup):
        for c in m.group(1).split():
            if re.match(r"^[a-z][a-z0-9_-]*$", c):
                hidden.add(c)

    unguarded, guarded = [], []
    for c in sorted(hidden):
        rules = re.findall(r"(?<![\w-])\." + re.escape(c) + r"(?![\w-])\s*(?:,[^{]*)?\{([^}]*)\}", css)
        if not any(re.search(r"(^|;)\s*display\s*:", r) for r in rules):
            continue                                   # no display rule → hidden works
        if re.search(r"\." + re.escape(c) + r"\[hidden\]\s*\{[^}]*display\s*:\s*none", css):
            guarded.append(c)
        else:
            unguarded.append(c)

    print(f"{len(hidden)} classes rendered with a hidden attribute; "
          f"{len(guarded)+len(unguarded)} of them set display")
    for c in guarded:
        print(f"  ok         .{c}")
    for c in unguarded:
        print(f"  UNGUARDED  .{c}  — candidate, MEASURE before adding a guard")

    missing = sorted(CONFIRMED - set(guarded))
    if missing:
        print(f"\nRED: confirmed instances lost their guard: {missing}")
        return 1
    print(f"\nboth confirmed instances still guarded; {len(unguarded)} candidate(s) to verify")
    return 0

if __name__ == "__main__":
    sys.exit(main())
