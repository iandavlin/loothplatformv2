#!/usr/bin/env python3
"""
check-guide-copy.py — assert the guide still quotes the product ACCURATELY.

WHY. The guide quotes the profile UI verbatim ("This IS your editor…", "Members
see", "Your header is Member-only — viewers see this as Member"). Quoting is what
makes a guide checkable instead of a paraphrase that drifts. But a quote is only
an asset while it is TRUE: the day someone rewords a hint in _render_blocks.php,
the handbook starts lying and nothing anywhere fails. That is a silent-drift
defect class with no owner, so it gets a check.

WHAT IT DOES. Fetches the real owner/anon pages off the dev2 serve and asserts
each quoted phrase is still present in the markup. Exit 1 on any miss.

  python3 tools/guide-shots/check-guide-copy.py

It deliberately checks the SERVE, not the worktree: the guide describes what
members actually get. And it pins the Host to dev2.loothgroup.com -- the retired
`dev` name falls through to buck's stale tree and would happily "confirm" copy
that is a week old.
"""

import html as _html
import subprocess, sys, urllib.parse

DOMAIN, ADDR = "dev2.loothgroup.com", "172.31.78.94"
OWNER_WP = 1910
OWNER_SLUG = "visibility-matrix-qa"
CAPPED_SLUG = "guide-capped-qa"        # throwaway B2 fixture; skipped if absent
CAPPED_WP = 90001

# (phrase, which page it must appear on)
QUOTES = [
    ("This IS your editor", "owner"),
    ("click any field (name, tagline, the photo, the privacy chips) to edit it in place", "owner"),
    ("Drag the grip on a block to reorder", "owner"),
    ("Public is the default for your whole profile", "owner"),
    ("each section can override this to Members-only or Private with its own chip", "owner"),
    ("Your name &amp; avatar show on your discussion posts to everyone.", "owner"),
    ("Drag a section into your profile", "owner"),
    ("tag you with site taxonomy so members can find you in search", "owner"),
    ("Filterable", "owner"),
    ("Your layout", "owner"),
    ("Add a section", "owner"),
    ("Add gallery", "owner"),
    ("Members see", "owner"),
    ("Public sees", "owner"),
    ("Street address", "owner"),
    ("drop the map pin automatically", "owner"),
    ("Discussion posts", "owner"),
    ("Member-only", "owner"),
    # the members gate, seen by a logged-out visitor
    ("This profile is members-only", "anon"),
    ("Profiles on Looth are a members community by default", "anon"),
    ("Join Looth", "anon"),
    # the capped chip -- only when the throwaway fixture is up
    ("Your header is Member-only", "capped"),
]


def sh(cmd):
    return subprocess.run(cmd, capture_output=True, text=True).stdout.strip()


def gate_token():
    return sh(["grep", "-oP", r'map \$cookie_loothdev_auth.*?"\K[^"]+',
               "/etc/nginx/conf.d/loothdev-auth.conf"]).splitlines()[0]


def mint(wp):
    out = sh(["sudo", "-n", "-u", "profile-app", "php",
              "/srv/profile-app/bin/mint-dev-token.php", str(wp)])
    return out.splitlines()[-1] if out else None


def fetch(path, cookies):
    cookie = "; ".join(f"{k}={v}" for k, v in cookies.items() if v)
    return sh(["curl", "-sS", "--resolve", f"{DOMAIN}:443:{ADDR}", "-k",
               "-H", f"Cookie: {cookie}", f"https://{DOMAIN}{path}"])


def main():
    tok = gate_token()
    pages = {}
    pages["owner"] = fetch(f"/u/{OWNER_SLUG}",
                           {"loothdev_auth": tok, "looth_id": mint(OWNER_WP)})
    pages["anon"] = fetch("/u/pilot_pro", {"loothdev_auth": tok})
    capped_tok = mint(CAPPED_WP)
    pages["capped"] = (fetch(f"/u/{CAPPED_SLUG}",
                             {"loothdev_auth": tok, "looth_id": capped_tok})
                       if capped_tok else "")

    for name, body in pages.items():
        if name != "capped" and len(body) < 5000:
            print(f"FAIL: {name} page came back {len(body)}b — not a real render")
            return 1

    bad, skipped = [], []
    for phrase, page in QUOTES:
        body = pages.get(page, "")
        if page == "capped" and not body:
            skipped.append(phrase)
            continue
        # compare against both raw and entity-decoded markup: the page carries
        # &amp; and &#8212; where the guide carries the characters themselves.
        if phrase not in body and phrase not in _html.unescape(body):
            bad.append((phrase, page))

    for phrase, page in bad:
        print(f"MISSING on {page}: {phrase!r}")
    for phrase in skipped:
        print(f"SKIPPED (B2 fixture is down): {phrase!r}")

    ok = len(QUOTES) - len(bad) - len(skipped)
    print(f"\n{ok}/{len(QUOTES)} quoted phrases still present"
          + (f", {len(skipped)} skipped" if skipped else ""))
    if bad:
        print("The guide quotes copy the product no longer uses. Regenerate it "
              "against the current build before showing it to anyone.")
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
