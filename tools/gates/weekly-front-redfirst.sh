#!/usr/bin/env bash
# weekly-front-redfirst — break gate 54, one assertion at a time, and prove each
# break is caught. A gate that has only ever been green is decoration.
#
# ── SNAPSHOT AND RESTORE, NEVER `git checkout --` ────────────────────────────
# A previous lane's mutation harness restored with `git checkout -- <file>`,
# which wipes UNCOMMITTED work — including the very fix under test — and turned
# one harness bug into ten false "the assertion is decoration" verdicts. This
# copies the files aside and copies them back, so it is safe to run on a dirty
# tree, which is exactly when it is most useful.
#
# ── A NO-OP MUTATION MUST FAIL LOUD ─────────────────────────────────────────
# Every mutation below is checked to have actually CHANGED the file. A `sed`
# that silently matched nothing would leave the gate green and be recorded as
# "this assertion cannot be broken", which is the opposite of what happened.
set -u
cd "$(dirname "$0")/../.." || exit 2

GATE="tools/gates/weekly-front-gate.py"
PARTIAL="archive-poc/web/_render-weekly-issue.php"
FEED="lg-weekly-digest/includes/class-lg-wd-front-feed.php"
CONFIG="platform/config/weekly-front.php"
# The front page is the OTHER reader of the box-local override. It joins the
# mutation set because an override honoured by only one pool is a HALF-ON state.
FRONT="archive-poc/web/index.php"
FILES=("$PARTIAL" "$FEED" "$CONFIG" "$FRONT")

SNAP="$(mktemp -d)"
trap 'for f in "${FILES[@]}"; do cp "$SNAP/$(echo "$f" | tr / _)" "$f"; done; rm -rf "$SNAP"' EXIT
for f in "${FILES[@]}"; do cp "$f" "$SNAP/$(echo "$f" | tr / _)"; done

restore() { for f in "${FILES[@]}"; do cp "$SNAP/$(echo "$f" | tr / _)" "$f"; done; }

pass=0; fail=0

# baseline: the gate must be GREEN on the real tree, or nothing below means anything
if ! python3 "$GATE" >/dev/null 2>&1; then
  echo "CANNOT RUN  gate 54 is RED on the unmutated tree — fix that before proving red-first"
  exit 2
fi
echo "baseline: gate 54 GREEN on the real tree"
echo

# $1 = label, $2 = expected assertion tag, $3... = the mutation command
mutate() {
  local label="$1" tag="$2"; shift 2
  local before after
  before="$(cat "${FILES[@]}" | md5sum)"
  "$@"
  after="$(cat "${FILES[@]}" | md5sum)"
  if [ "$before" = "$after" ]; then
    echo "  NO-OP  $label — the mutation changed nothing, so this proves NOTHING"
    fail=$((fail+1)); restore; return
  fi
  local out rc
  out="$(python3 "$GATE" 2>&1)"; rc=$?
  restore
  if [ "$rc" -ne 1 ]; then
    echo "  MISS   $label — gate exited $rc, expected 1 (RED). Assertion $tag is decoration."
    fail=$((fail+1)); return
  fi
  if ! printf '%s' "$out" | grep -q "FAIL  \[$tag\]"; then
    echo "  WRONG  $label — gate went red, but not on $tag. It is catching something else."
    printf '%s\n' "$out" | grep "FAIL" | head -2 | sed 's/^/         /'
    fail=$((fail+1)); return
  fi
  echo "  caught $label  ->  $tag"
  pass=$((pass+1))
}

# ── C: the silent one. Pass the taxonomy slug through unmapped. ─────────────
mutate "tier slug passed through unmapped (looth-lite)" C \
  perl -0pi -e "s/\t\t\t\treturn 'lite';/\t\t\t\treturn 'looth-lite';/" "$FEED"

# ── C: drop the padlock element from the renderer ───────────────────────────
mutate "padlock element removed from the card" C \
  perl -0pi -e 's/<span class="rcard__gate"/<span class="rcard__nogate"/' "$PARTIAL"

# ── C: stop marking gated cards as gated at all ─────────────────────────────
mutate "an unknown tier fails OPEN instead of closed" C \
  perl -0pi -e "s/\t\t\tdefault:\n\t\t\t\treturn 'pro';/\t\t\tdefault:\n\t\t\t\treturn 'public';/" "$FEED"

mutate "gated cards no longer get their gated classes" C \
  perl -0pi -e "s/\\\$lg_wk_gated \? ' rcard--gated rcard--gated-' \. h\( \\\$lg_wk_tier \) : ''/''/" "$PARTIAL"

# ── D: print the excerpt for gated items ────────────────────────────────────
# Kept VALID, not merely wrong: the first version of this mutation emitted
# broken PHP, the partial failed to render, and the gate reported CANNOT RUN —
# which proves nothing about the assertion. Drop the gate test only.
mutate "renderer prints the excerpt for GATED items" D \
  perl -0pi -e "s/! \\\$lg_wk_gated && //" "$PARTIAL"

# ── E: remove the archived filter ───────────────────────────────────────────
mutate "archived posts no longer filtered" E \
  perl -0pi -e "s/return in_array\( \\\$status, \[ 'archived',/return in_array( \\\$status, [ 'never-matches',/" "$FEED"

# ── F: stop skipping the events section ─────────────────────────────────────
mutate "events (date-forward) section no longer skipped" F \
  perl -0pi -e "s/const SKIP_TEMPLATES = \[ 'date-forward', 'html-block' \];/const SKIP_TEMPLATES = [ 'html-block' ];/" "$FEED"

# ── G: date the issue by the render clock, the email builder's actual defect ─
mutate "masthead dated by the render clock instead of sent_at" G \
  perl -0pi -e "s/\\\$lg_wk_sent \? date\( 'l j F', \\\$lg_wk_sent \) : ''/date( 'l j F' )/" "$PARTIAL"

# ── B: make the OFF/empty state emit markup anyway ──────────────────────────
mutate "empty payload still emits the block shell" B \
  perl -0pi -e "s/if \( empty\( \\\$lg_wk\['sections'\] \) \) \{\n\treturn;\n\}/if ( false ) {\n\treturn;\n}\n\\\$lg_wk['sections'] = \\\$lg_wk['sections'] ?? [];/" "$PARTIAL"

# ── A: hardcode the flag instead of reading it ──────────────────────────────
mutate "feed stops reading the tracked config" A \
  perl -0pi -e "s#platform/config/weekly-front.php#platform/config/NOT-THE-FLAG.php#" "$FEED"

# ── A: read only one override channel ───────────────────────────────────────
mutate "feed reads getenv() but not \$_SERVER" A \
  perl -0pi -e "s/\\\$_SERVER\['LG_WEEKLY_FRONT'\] \?\? false/false/" "$FEED"

# ── A2: the box-local override, which is the whole dev2 flip ────────────────
# Each of these is a state the flip could silently land in. The first two are
# the HALF-ON case: one pool honours the override and the other does not, which
# renders as an enabled front page fetching a 404 -- a broken feature, not an
# off one.
mutate "front page stops reading the box-local override" A2 \
  perl -0pi -e "s#/weekly-front\.local\.php#/weekly-front.NOPE.php#" "$FRONT"

mutate "feed stops reading the box-local override" A2 \
  perl -0pi -e "s#/weekly-front\.local\.php#/weekly-front.NOPE.php#" "$FEED"

# Precedence: an override read AFTER the env channels loses to a file on disk,
# so a gate forcing a state would be silently overruled. Both the pattern and
# the replacement are SINGLE-QUOTED and use no literal quote character -- under
# `set -u` a $loc inside a double-quoted shell string is expanded by BASH before
# perl ever sees it, which aborts the run with "unbound variable".
mutate "front page reads the override AFTER the env channels" A2 \
  perl -0pi -e 's{(    \$loc = \@include[^\n]*\n[^\n]*\n[^\n]*\n[^\n]*\n)(    foreach \(\[getenv[^\n]*\n[^\n]*\n[^\n]*\n)}{$2$1}s' "$FRONT"

# !empty cannot express "force OFF": it reads an override saying enabled=false
# identically to no override at all, so dev2 could not be switched back off.
mutate "override uses !empty instead of array_key_exists" A2 \
  perl -0pi -e 's{array_key_exists\([^,]*, }{!empty(}' "$FRONT"

echo
echo "red-first: $pass caught, $fail not caught"
[ "$fail" -eq 0 ] || exit 1
echo "GATE 54 is REAL — every assertion above was broken on purpose and caught."
