#!/usr/bin/env bash
# ─── RED-FIRST: #166, the meta leak ──────────────────────────────────────────
#
# Proves the defect EXISTS before the fix removes it, against the real served
# page as a real logged-out visitor. Run this on a tree that does NOT carry the
# fix (main, or LG_GATE_HOST pointed at the serve) and it must print LEAKING.
# Run it on the fixed branch and it must print SEALED.
#
# WHY A SCRIPT AND NOT A PARAGRAPH. "I fetched it and saw the string" is not
# reproducible, and the two mistakes that make this measurement lie are both
# easy and both silent:
#
#   1. `sed 's|<head>.*</head>||'` STRIPS NOTHING. sed is line-based and the
#      head spans ~90 lines, so the "body" you then grep still contains all
#      three meta tags and the leak reads as though it were in the rendered
#      page. This bit the first run of this very measurement.
#   2. `grep -c` counts LINES, not occurrences. All three tags can sit on
#      three lines or one; -c cannot tell 1 from 3. Use `grep -o | wc -l`.
#
# Both are avoided below: head and body are split on the literal `</head>`
# STRING, and counting is an exact substring count, never a line count. The
# needle is HTML-escaped first, because a member's one-liner is arbitrary text
# and an `&` arrives on the page as `&amp;` — a raw needle silently scores 0
# against a page that is leaking, which is the worst possible failure here.
#
# THE LIVENESS MARKER IS BODY MARKUP, AND IT IS `lg-gate` ON PURPOSE. The
# obvious choice, `lg-idrow`, is WRONG for this probe: it appears 12 times in
# this page's head as CSS rules (`.lg-idrow{display:flex...}`) and zero times
# in the body, so "the page rendered" would be proven by a stylesheet. For a
# members-only member seen anonymously the body IS the join gate, so `lg-gate`
# is the one string here that can only exist because a render happened.
#
# ANON MEANS ANON: no cookie jar, no -b, no auth. The dev gate allows loopback,
# so a Host-header fetch at 127.0.0.1 is both past the gate and unauthenticated
# — which is exactly the crawler's view. Never smoke this through the public
# name: Cloudflare bot-challenges it into a 403 that reads as "no leak".
set -uo pipefail

HOST="${LG_GATE_HOST:-dev2.loothgroup.com}"
# Same knob as the gate. EMPTY means the real /u/ and /p/ routes — which are
# symlinked out of the serving checkout and therefore serve MAIN, which is
# exactly what you want for the red-first run. Point it at a lane preview
# (LG_MGL_PREFIX=/preview/166-meta-leak) to watch the SAME script go SEALED
# against the branch. Without this the header's "run it on the fixed branch"
# instruction was unfollowable: every fetch went to main no matter which tree
# the script was sitting in.
PREFIX="${LG_MGL_PREFIX:-}"; PREFIX="${PREFIX%/}"
PSQL_DB="${LG_PROFILE_DB:-profile_app}"

say() { printf '%s\n' "$*"; }

# The same query the gate uses: a PUBLIC profile whose header is NOT public and
# who has actually written a one-liner. If none exists there is nothing to
# prove and that is NO VERDICT, never a pass.
pick() {
  sudo -u postgres psql "$PSQL_DB" -A -t -F'|' -c "$1" 2>/dev/null
}

PAGE="$(mktemp -t lg166-XXXXXX.html)"
trap 'rm -f "$PAGE"' EXIT

fetch() {   # fetch <path> -> writes $PAGE, sets HTTP_STATUS
  HTTP_STATUS=$(curl -sk --max-time 25 -H "Host: ${HOST}" \
    -o "$PAGE" -w '%{http_code}' "https://127.0.0.1$1")
}

# Count occurrences of a FIXED needle in the head and the body, separately.
# Split on the literal `</head>` STRING, not on lines: a line-based split is
# a no-op on a multi-line head (mistake 1 above) and silently wrong on a
# minified one. This is the same split gate 39 §G7 uses, deliberately.
# Escaping happens here too — looth_h() is htmlspecialchars with ENT_QUOTES,
# so the needle is compared in the form the page actually emits.
count_in() {   # count_in <file> <head|body> <needle>
  python3 - "$1" "$2" "$3" <<'PY'
import html, sys
raw = open(sys.argv[1], 'rb').read().decode('utf-8', 'replace')
part, needle = sys.argv[2], sys.argv[3]
head, sep, body = raw.partition('</head>')
hay = head if part == 'head' else (body if sep else '')
print(hay.count(html.escape(needle, quote=True)))
PY
}

VERDICT=0
probe() {   # probe <label> <url> <needle> <liveness-marker>
  local label="$1" url="$2" needle="$3" live="$4"
  local f="$PAGE"
  fetch "$url"
  if [ "$HTTP_STATUS" != "200" ]; then
    say "[$label] NO VERDICT — $url returned HTTP $HTTP_STATUS. A styled 403 or a"
    say "         redirect reads identically to 'the text is absent'."
    VERDICT=2; return
  fi
  if ! grep -qF -- "$live" "$f"; then
    say "[$label] NO VERDICT — 200 but no '$live' markup; the page did not render,"
    say "         so its silence proves nothing."
    VERDICT=2; return
  fi
  local inhead inbody
  inhead=$(count_in "$f" head "$needle")
  inbody=$(count_in "$f" body "$needle")
  say "[$label] $url"
  say "         head=$inhead  body=$inbody   needle=\"$needle\""
  if [ "$inhead" -gt 0 ] && [ "$inbody" -eq 0 ]; then
    say "         >>> LEAKING: withheld from the rendered page, published to crawlers."
    [ "$VERDICT" -eq 0 ] && VERDICT=1
  elif [ "$inhead" -eq 0 ] && [ "$inbody" -eq 0 ]; then
    say "         >>> SEALED: absent from both surfaces."
  else
    say "         >>> body-visible ($inbody) — this member is not withheld at all;"
    say "             not the #166 defect. Pick another."
    VERDICT=2
  fi
}

say "=== #166 red-first: private one-liners in the crawler-visible head ==="
say "host=$HOST  prefix=${PREFIX:-(none — the REAL routes, i.e. main)}"
say "(anon, cookieless, loopback past the dev gate)"
say ""

U=$(pick "SELECT u.slug || '|' || btrim(u.at_a_glance)
            FROM users u
           WHERE u.profile_visibility='public'
             AND btrim(coalesce(u.at_a_glance,'')) <> ''
             AND coalesce((SELECT ps.visibility FROM profile_sections ps
                            WHERE ps.user_id=u.id AND ps.key='header'),'members') <> 'public'
             AND EXISTS (SELECT 1 FROM wp_user_bridge b WHERE b.user_id=u.id)
           ORDER BY u.slug LIMIT 1" | head -1)
if [ -n "$U" ]; then
  probe "PROFILE" "${PREFIX}/u/${U%%|*}/" "${U#*|}" "lg-gate"
else
  say "[PROFILE] NO VERDICT — no public member with a members-only header and a"
  say "          written one-liner exists on this box."
  VERDICT=2
fi
say ""

P=$(pick "SELECT p.slug || '|' || btrim(coalesce(nullif(btrim(p.tagline),''), p.about))
            FROM practices p
           WHERE p.archived_at IS NULL
             AND (btrim(coalesce(p.tagline,'')) <> '' OR btrim(coalesce(p.about,'')) <> '')
             AND coalesce((SELECT ps.visibility FROM profile_sections ps
                            WHERE ps.key = 'practice-header:' || p.id),'members') <> 'public'
           ORDER BY p.slug LIMIT 1" | head -1)
if [ -n "$P" ]; then
  probe "PRACTICE" "${PREFIX}/p/${P%%|*}/" "${P#*|}" "lg-gate"
else
  say "[PRACTICE] NO VERDICT — no practice with a members-only header and text."
  VERDICT=2
fi

say ""
case "$VERDICT" in
  1) say "RED-FIRST CONFIRMED: the defect is present on this tree." ;;
  0) say "SEALED on this tree — the fix is in. (Run against main to see the RED.)" ;;
  *) say "NO VERDICT — see above. Do not read this as either state." ;;
esac
exit 0
