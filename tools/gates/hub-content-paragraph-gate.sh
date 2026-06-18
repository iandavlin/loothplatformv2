#!/bin/bash
# hub-content-paragraph-gate.sh — the paragraph-collapse checkpoint
# (docs/CRAFT-STANDARD.md: a defect class found TWICE becomes a gate).
#
# THE DEFECT CLASS (regressed twice — feed teaser fixed in e3d1d07, then the
# full-body single-topic + topic-body paths collapsed again):
#   bbPress/BuddyBoss store post_content RAW — paragraph breaks are bare "\n\n",
#   no <p>/<br> — and apply wpautop on DISPLAY. The Hub (bb-mirror) renders
#   forums.{topic,reply}.content_html WP-free, so without paragraph handling every
#   newline collapses into one unbroken wall of text in the discussion modals.
#
# THE INVARIANT (realigned 2026-06-15, Ian's render-time decision): the PARAGRAPH
# HOME is RENDER-time, not the data layer. The sync-time wpautop in the materializer
# was tried and REVERTED (9510cbf/def57f8 -> 5fd44bd/b656d2b); the PG content_html
# column stays MIXED on purpose. So this gate no longer demands block tags in the
# STORED html — it asserts the USER-VISIBLE truth: every raw-newline body that WOULD
# collapse must come out of the render path carrying a block/break tag.
#   - topic/reply bodies: render via bb_mirror_paragraphs() (in _reply-render.php,
#     applied at the _topic-body.php OP emit shared by BOTH modal endpoints). Each
#     raw-newline body is pushed THROUGH the helper and re-checked.
#   - forum descriptions: render via a template that already wraps them in a single
#     <p> (index.php / _topic-list.php, htmlspecialchars'd) — so they cannot render
#     tag-less, but a GENUINE multi-paragraph description loses its internal breaks
#     inside that one <p>. We flag only descriptions with REAL text (whitespace incl
#     U+00A0 stripped — kills phantom nbsp/blank-line rows like 3818) that carry a
#     paragraph break, i.e. ones that would actually collapse.
#
# Runs as ubuntu on dev; the render check runs as the `bb-mirror` PG-role user so
# bb_mirror_db() connects via peer auth.
#
# Exit 0 = GREEN. Non-zero = a body still collapses at render — fix the render path
# (bb_mirror_paragraphs / the description template), do NOT re-introduce the
# reverted sync-time wpautop.
set -uo pipefail

OFFENDERS=$(sudo -u bb-mirror php -r '
require "/srv/bb-mirror/config.php";
require_once "/srv/bb-mirror/web/forums/_reply-render.php";
$db = bb_mirror_db();
$bad = [];

// A render-collapse offender = after bb_mirror_paragraphs, a RAW text run (outside
// any block element) STILL holds a blank-line break -> it collapses in the modal.
// Tokenise the rendered output by block element (same shape the helper preserves)
// and inspect only the text BETWEEN blocks. This catches BOTH fully-raw rows AND
// MIXED rows (raw body + appended block, e.g. 71640) — the latter were invisible
// to the old "content_html has no block tag" filter.
$blockEl = "~(<(?:p|h[1-6]|blockquote|pre|figure|ul|ol|table|div)\b[^>]*>.*?"
         . "</(?:p|h[1-6]|blockquote|pre|figure|ul|ol|table|div)>|<(?:hr|br)\s*/?>)~is";
$collapses = function (string $rendered) use ($blockEl): bool {
    $rendered = str_replace(["\r\n", "\r"], "\n", $rendered); // normalise so \n\n is detectable
    $parts = preg_split($blockEl, $rendered, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return false;
    foreach ($parts as $seg) {
        if ($seg === "" || preg_match("~^\s*(?:<[a-z]|<(?:hr|br)\s*/?>)~i", $seg)) continue;
        if (preg_match("/\S\s*\n[ \t]*\n\s*\S/", $seg)) return true; // blank-line break in bare text
    }
    return false;
};

// topic/reply: candidate = source has a paragraph break (NO block-tag filter, so
// mixed rows are included). Push through the render helper, then check for survivors.
foreach (["topic" => "status IN (\x27publish\x27,\x27closed\x27)",
          "reply" => "status = \x27publish\x27"] as $kind => $where) {
    $sql = "SELECT id, content_html FROM forums.$kind
             WHERE $where AND content_text ~ E\x27\n[[:space:]]*\n\x27";
    foreach ($db->query($sql) as $r) {
        if ($collapses(bb_mirror_paragraphs((string)$r["content_html"])))
            $bad[] = "$kind|" . $r["id"];
    }
}

// forum descriptions: template wraps in one <p>; flag only REAL multi-paragraph
// text (strip all whitespace incl U+00A0) that would lose its internal breaks.
$dsql = "SELECT id, description FROM forums.forum
          WHERE description ~ E\x27\n[[:space:]]*\n\x27
            AND description !~* \x27<(p|br|ul|ol|li|blockquote|h[1-6]|pre|div|table)[ />]\x27";
foreach ($db->query($dsql) as $r) {
    $stripped = preg_replace("/[\s\x{00A0}]+/u", "", (string)$r["description"]);
    if ($stripped !== "" && $stripped !== null) $bad[] = "forum-desc|" . $r["id"];
}

echo implode("\n", $bad);
' 2>&1)
RC=$?
if [ $RC -ne 0 ]; then
  echo "PARAGRAPH-GATE: ERROR running render check:"
  echo "$OFFENDERS"
  exit 2
fi

OFFENDERS=$(printf '%s\n' "$OFFENDERS" | grep '|' || true)
if [ -n "$OFFENDERS" ]; then
  N=$(printf '%s\n' "$OFFENDERS" | grep -c '|')
  echo "PARAGRAPH-COLLAPSE: $N body/desc(s) STILL render with no block tag (wall of text)"
  echo "  (kind|id — these collapse at render):"
  printf '%s\n' "$OFFENDERS" | head -25 | sed 's/^/    /'
  echo "  Fix: bb_mirror_paragraphs() in _reply-render.php (render path) — NOT sync wpautop."
  exit 1
fi

echo "paragraph-collapse gate: GREEN (every raw body de-collapses at render)"
exit 0
