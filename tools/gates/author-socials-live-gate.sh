#!/bin/bash
# author-socials-live-gate.sh — the byline must RESOLVE a member's social links,
# never mirror them.
#
# THE DEFECT CLASS (found twice, so per docs/CRAFT-STANDARD.md it is a gate):
# a surface keeps its own copy of data another store owns, the copy stops being
# refreshed, and the surface renders confidently wrong for months. bb-mirror hit
# it via post_modified_gmt watermarks; the byline hit it here — ACF `author_*`
# usermeta was seeded from profile-app on 2026-05-29 and never synced again, so a
# Linktree a member deleted still rendered on his events on 2026-07-30.
# See docs/SOCIAL-LINKS-DRIFT-AUDIT.md.
#
# Exit: 0 green, 1 RED, 2 CANNOT RUN (per the runner's three-state convention).
set -uo pipefail
cd "$(dirname "$0")/../.." || { echo "cannot reach repo root"; exit 2; }

fail=0
ok()   { printf '  OK   %s\n' "$1"; }
bad()  { printf '  RED  %s\n' "$1"; fail=1; }

HEADER=lg-layout-v2/blocks/post-header/render.php
FOOTER=lg-layout-v2/blocks/post-footer/render.php
MUP=platform/mu-plugins/lg-author-socials.php
API=profile-app/api/v0/internal-byline-socials.php
PROF=profile-app/src/Profile.php

for f in "$HEADER" "$FOOTER" "$MUP" "$API" "$PROF"; do
  [ -r "$f" ] || { echo "missing $f — cannot judge"; exit 2; }
done

echo "--- both byline blocks must hand their resolved rail to the filter ---"
for f in "$HEADER" "$FOOTER"; do
  if grep -q "apply_filters('lg_layout_v2_author_links'" "$f"; then
    ok "$(basename "$(dirname "$f")") applies lg_layout_v2_author_links"
  else
    bad "$(basename "$(dirname "$f")") never applies lg_layout_v2_author_links — its rail is a dead mirror again"
  fi
done

# The filter is useless if it cannot tell a member-editable social slot from a
# computed one, which is what the 'key' field carries.
if grep -q "'key' =>" "$FOOTER"; then
  ok "post-footer links carry 'key' (filter can target social slots)"
else
  bad "post-footer links lost their 'key' — the filter cannot distinguish slots and will duplicate them"
fi

echo "--- the resolver must be wired to that filter ---"
if grep -q "add_filter('lg_layout_v2_author_links'" "$MUP"; then
  ok "lg-author-socials hooks the filter"
else
  bad "lg-author-socials does not hook lg_layout_v2_author_links — nothing resolves"
fi

echo "--- a failed/absent lookup must NOT blank a byline ---"
# The fallback is the whole reason a live read is safe to ship: any path that
# cannot answer authoritatively has to leave the legacy rail alone.
if grep -q 'resolved === null || !\$resolved\[.authoritative.\]' "$MUP" \
   || grep -qE 'resolved === null \|\| !\$resolved' "$MUP"; then
  ok "resolver falls back to the legacy rail unless the answer is authoritative"
else
  bad "resolver has no authoritative/null guard — a profile-app outage would empty every byline"
fi

echo "--- value->href must have exactly ONE implementation ---"
# Duplicating the base map into WP is precisely how the two stores drifted. The
# rule lives in Profile::socialUrl; the byline gets hrefs already composed.
if grep -q 'public static function socialUrl' "$PROF"; then
  ok "Profile::socialUrl is the single composer"
else
  bad "Profile::socialUrl is gone — composition has moved or forked"
fi
if grep -qE "https://(instagram|facebook|linktr)\." "$MUP"; then
  bad "$MUP hard-codes a platform base URL — that is a second implementation of the compose rule"
else
  ok "the WP side hard-codes no platform base URLs"
fi
if grep -q 'Profile::socialUrl' "$API"; then
  ok "the byline endpoint composes via Profile::socialUrl"
else
  bad "the byline endpoint composes hrefs some other way"
fi

echo "--- contact PII must never reach a public byline ---"
if grep -q "BYLINE_EXCLUDED_KINDS = \['email', 'phone'\]" "$API"; then
  ok "email/phone excluded from byline links"
else
  bad "byline endpoint no longer excludes email/phone (Ian 2026-06-11: scrape proof)"
fi

echo "--- the save-side host strip must stay host-AWARE ---"
# The blind version turned https://linktr.ee/facebook.com/x saved as facebook
# into the handle facebook.com/x, which re-composed to facebook.com/facebook.com/x.
# grep -F, not a regex: the literal contains '?' and '[', and a BRE '\?' silently
# matches nothing here — this check sat green against a real regression until it
# was tested by breaking the file on purpose.
if grep -qF "preg_replace('#^https?://[^/]+/#i'" profile-app/api/v0/me-socials.php; then
  bad "me-socials.php strips ANY pasted host again — this corrupts cross-host pastes"
else
  ok "me-socials.php does not blind-strip pasted hosts"
fi
if grep -q 'SOCIAL_HOSTS' "$PROF"; then
  ok "Profile::SOCIAL_HOSTS defines which host belongs to which kind"
else
  bad "Profile::SOCIAL_HOSTS is gone — nothing scopes the host strip"
fi

echo
if [ "$fail" -ne 0 ]; then
  echo "author-socials-live gate: RED"
  exit 1
fi
echo "author-socials-live gate: GREEN"
exit 0
