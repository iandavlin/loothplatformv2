#!/usr/bin/env bash
# red-first for GATE 77 — proof the gate is neither always-green nor always-red.
#
# Each mutation breaks ONE behaviour and must redden the assertion that NAMES
# that behaviour. A mutation that reddens something else is as much a finding as
# one that reddens nothing: it means the assertion is measuring the wrong thing.
#
# ⚠ EVERY MUTATION IS APPLIED TO A SNAPSHOT COPY OF THE FILES, NEVER TO THE TREE
# UNDER TEST, and never with `git checkout --` to undo. Checkout-from-HEAD wipes
# uncommitted work and once turned one harness bug into ten false "the assertion
# is decoration" verdicts (feedback-mutation-harness-must-snapshot-not-checkout).
#
# Two NO-OP mutations are included and must stay GREEN. Without them a gate that
# reddens on any edit at all would look perfectly discriminating here.
#
#   bash tools/gates/lanes-page-truth-redfirst.sh
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/gate77-redfirst-XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
pass=0; fail=0

snapshot() {   # snapshot <dir>
    local d="$1"
    mkdir -p "$d/tools/gates" "$d/tools/lanes" "$d/webroot"
    cp "$ROOT/tools/lanes-status.sh"      "$d/tools/"
    cp "$ROOT/tools/lanes-page.py"        "$d/tools/"
    cp "$ROOT/tools/lanes-poke-worker.sh" "$d/tools/"
    cp "$ROOT/tools/lanes/stall-watchdog.sh" "$d/tools/lanes/"
    cp "$ROOT/webroot/lanes-poke.php"     "$d/webroot/"
    cp "$ROOT/tools/gates/lanes-page-truth-gate.py" "$d/tools/gates/"
}

mutate() {     # mutate <dir> <file> <needle> <replacement>
    python3 - "$1/$2" "$3" "$4" <<'PY'
import sys, pathlib
p, old, new = pathlib.Path(sys.argv[1]), sys.argv[2], sys.argv[3]
s = p.read_text()
if old not in s:
    print(f"MUTATION-NEEDLE-MISSING in {p.name}: {old[:70]!r}"); sys.exit(3)
p.write_text(s.replace(old, new, 1))
PY
}

# run <expect green|red> <name> <must-name-this-check> <file> <needle> <repl>
run() {
    local expect="$1" name="$2" want="$3" file="$4" needle="$5" repl="$6"
    local d="$WORK/m$((pass+fail))"
    snapshot "$d"
    if [ -n "$needle" ]; then
        if ! mutate "$d" "$file" "$needle" "$repl"; then
            echo "  ✗ $name — the mutation could not be applied (stale needle)"
            fail=$((fail+1)); return
        fi
    fi
    local out rc
    out="$(python3 "$d/tools/gates/lanes-page-truth-gate.py" 2>&1)"; rc=$?
    if [ "$expect" = green ]; then
        if [ "$rc" -eq 0 ]; then echo "  ✓ $name — stayed GREEN, as it must"; pass=$((pass+1))
        else echo "  ✗ $name — went RED on a change that breaks nothing:"
             printf '%s\n' "$out" | grep '^  FAIL' | sed 's/^/        /'; fail=$((fail+1)); fi
        return
    fi
    if [ "$rc" -eq 0 ]; then
        echo "  ✗ $name — gate stayed GREEN with the behaviour BROKEN"; fail=$((fail+1)); return
    fi
    if [ "$rc" -ne 1 ]; then
        echo "  ✗ $name — gate exited $rc (expected 1); a CANNOT-RUN is not a finding"; fail=$((fail+1)); return
    fi
    if printf '%s\n' "$out" | grep -q "FAIL  $want"; then
        echo "  ✓ $name — RED, naming: $want"; pass=$((pass+1))
    else
        echo "  ✗ $name — RED, but NOT for the stated reason. It said:"
        printf '%s\n' "$out" | grep '^  FAIL' | sed 's/^/        /'
        echo "        expected: $want"
        fail=$((fail+1))
    fi
}

echo "══ GATE 77 red-first — mutating SNAPSHOTS, never the tree under test ══"
echo "control:"
run green "baseline, unmutated" "" "" "" ""

echo
echo "the three lies of #151:"
run red "unique==0 means finished again (the root cause)" \
    "#151 a branch cut minutes ago is NOT" tools/lanes-status.sh \
    'if   [[ "$unique" == "0" && "$ag" == "none" && "$age_min" -ge 60 ]]; then' \
    'if   [[ "$unique" == "0" ]]; then'
run red "AT RISK stops requiring real work" \
    "#151 an unpushed branch with NOTHING in it is not flagged AT RISK" tools/lanes-status.sh \
    'elif [[ "$push" == "NO REMOTE" && "$unique" != "0" ]]; then' \
    'elif [[ "$push" == "NO REMOTE" ]]; then'
run red "the seat map is built from the filtered list" \
    "#151 a FINISHED seat's issue is never APPROVED" tools/lanes-page.py \
    'seat_nums = {l["branch"].split("-")[0]: l["branch"] for l in rows' \
    'seat_nums = {l["branch"].split("-")[0]: l["branch"] for l in seats'
run red "riders stop counting as work in progress" \
    "#151 a RIDER's issue is never APPROVED" tools/lanes-page.py \
    'for r in l.get("riders", []):
            seat_nums.setdefault(str(r), l["branch"])' \
    'for r in []:
            seat_nums.setdefault(str(r), l["branch"])'
run red "a parked branch stops counting as started" \
    "#151 a PARKED branch's issue is never APPROVED" tools/lanes-page.py \
    'for p_ in parked:
        n = p_["branch"].split("-")[0]' \
    'for p_ in []:
        n = p_["branch"].split("-")[0]'

echo
echo "the detector (#160, and the drift that has bitten twice in a day):"
run red "re-anchor the detector on the closing paren" \
    "a lane thinking at a raised effort reads as WORKING" tools/lanes-status.sh \
    'AGENT_RE="esc to interrupt|s · ↓ [0-9.,]+k? tokens"' \
    'AGENT_RE="esc to interrupt|s · ↓ [0-9.,]+k? tokens\\)"'
run red "the working chip stops carrying the verb" \
    "#160 the working chip mirrors the spinner verb" tools/lanes-page.py \
    '            if sb:
                label = f"{label} &middot;' \
    '            if not sb:
                label = f"{label} &middot;'
run red "the Building strip comes back" \
    "#160 there is no separate Building strip" tools/lanes-page.py \
    "    if cards:
        acc(h, \"seats\", \"Seats\", plural(len(cards), \"seat\"))" \
    "    if cards:
        h.append('<b>Building</b>')
        acc(h, \"seats\", \"Seats\", plural(len(cards), \"seat\"))"

echo
echo "the four chips (#159):"
run red "a PARKED: tip stops meaning retired" \
    "#159 a PARKED: tip ⇒ retired" tools/lanes-status.sh \
    'elif [[ "$subj" == "PARKED: "* ]]; then st4="retired"' \
    'elif [[ "$subj" == "PARKED: "* ]]; then st4="needs-keeper"'
run red "a lane naming Ian stops reaching him" \
    "#159 a QUESTION naming Ian ⇒ needs-you" tools/lanes-status.sh \
    'if [[ "$lreason" =~ (^|[^A-Za-z])[Ii]an([^A-Za-z]|$) ]]; then st4="needs-you"' \
    'if [[ "$lreason" =~ NEVERMATCHESANYTHING ]]; then st4="needs-you"'
run red "the verbatim reason is replaced by the bare word" \
    "#159 the lane's own question is carried verbatim" tools/lanes-status.sh \
    '                reason="$lreason"' \
    '                reason=""'
run red "a fifth chip wording appears" \
    "#159 no chip outside the ruled four exists" tools/lanes-page.py \
    '"needs-keeper": ("needs keeper", "#7fa8d9"),' \
    '"needs-keeper": ("idle — agent parked", "#7fa8d9"),'

echo
echo "the checklist (#155) and the workers view (#164):"
run red "the list renders even when nothing waits on him" \
    "nothing waiting on him ⇒ the list is ABSENT" tools/lanes-page.py \
    '    if todo:
        acc(h, "your-list"' \
    '    if True:
        acc(h, "your-list"'
run red "a GitHub failure goes quiet" \
    "GitHub unreadable ⇒ says so LOUDLY" tools/lanes-page.py \
    '    if not gh_ok:
        h.append' \
    '    if False:
        h.append'
run red "a question stops being mirrored as a bullet" \
    "#155 a lane's question is mirrored as a bullet" tools/lanes-page.py \
    '        if (l.get("state") != "needs-you" or not l.get("reason")
                or l.get("state_from_label")):' \
    '        if True:'
run red "the workers view starts printing desks" \
    "#164 a desk with no session is not listed as an agent" tools/lanes-page.py \
    '    live_agents = [l for l in seats if l.get("agent", "none") != "none"]' \
    '    live_agents = list(seats)'
run red "an idle agent goes back to saying nothing useful" \
    "#164 an idle-but-alive agent says what it waits FOR" tools/lanes-page.py \
    '    if ls == "DONE":
        return "waiting for the keeper to merge"' \
    '    if ls == "DONE":
        return "parked"'
run red "the casual form is forced onto a possessive" \
    "#164 a possessive keeps its capital" tools/lanes-page.py \
    '            and not any(q in t for q in APOSTROPHES)' \
    '            and True'

echo
echo "the poke button (#156):"
run red "the debounce is removed" \
    "#156 the immediate second tap is debounced" webroot/lanes-poke.php \
    'if (is_file($stamp) && (time() - (int)filemtime($stamp)) < DEBOUNCE) {' \
    'if (false) {'
run red "the seat name stops being validated" \
    "#156 a path-traversal seat name is refused" webroot/lanes-poke.php \
    "preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]{0,63}\$/', \$seat) || strpos(\$seat, '..') !== false" \
    "preg_match('/^.*\$/', \$seat) && false"
run red "the nonce stops being checked" \
    "#156 a forged nonce is refused" webroot/lanes-poke.php \
    "if (!\$valid) fail(403, 'stale page — reload and retry');" \
    "\$valid = true;"
run red "the worker stops re-validating what it delivers" \
    "#156 a malformed seat in the spool is dropped" tools/lanes-poke-worker.sh \
    '    [[ "$seat" =~ ^[0-9A-Za-z][0-9A-Za-z._-]{0,63}$ ]] || continue' \
    '    true'
run red "the watchdog stops advancing its watermark" \
    "#156 the SAME poke does not re-alarm" tools/lanes/stall-watchdog.sh \
    '      cp "$POKES" "$PMARK" 2>/dev/null' \
    '      true'

echo
echo "#172 — the record parser, the card, the accordions:"
run red "safe_url stops filtering, so a poisoned href renders" \
    "#172 a javascript: record never reaches an href" tools/lanes-page.py \
    "    m = re.match(r'^https://([A-Za-z0-9.\-]+)(/.*)?\$', u)
    if m and m.group(1).lower() in SAFE_HOSTS:
        return u
    return None" \
    "    return u"
run red "a record is read as its FIRST TOKEN again (the build's own bug)" \
    "#172 a value with prose after it is NOT a record" tools/lanes-page.py \
    "val = safe_url(val.rstrip('.,;'))" \
    "val = safe_url((val.split() or [''])[0].rstrip('.,;'))"
run red "an unattributable record is guessed onto an issue" \
    "#172 a record nobody can attribute is DROPPED" tools/lanes-page.py \
    '        if not num:
            continue                       # unattributable — silently useless' \
    '        if not num:
            num = "880"'
run red "the issue BODY is read before its comments" \
    "#172 a COMMENT outranks the issue BODY" tools/lanes-page.py \
    'texts.append(i.get("body") or "")' \
    'texts.insert(0, i.get("body") or "")'
run red "the LAST writer wins instead of the first" \
    "#172 a COMMENT outranks a commit body" tools/lanes-page.py \
    'records.setdefault(num, {}).setdefault(key, val)' \
    'records.setdefault(num, {})[key] = val'
run red "a park reason loses its one usable record shape" \
    "#172 a record in a PARK REASON opens a door" tools/lanes-page.py \
    'out.extend(_record_lines(p.get("reason") or "", n, tail=True))' \
    'out.extend(_record_lines(p.get("reason") or "", n))'
run red "attribution by a leading number is dropped" \
    "#172 …and by a leading number in the subject" tools/lanes-page.py \
    "    m = re.match(r'\s*(\d+)\s*[:.]', subject or \"\")
    return m.group(1) if m else None" \
    "    return None"
run red "a missing door falls back to the GitHub link" \
    "#172 the GitHub link is NEVER substituted as the door" tools/lanes-page.py \
    '"door": door_html(rec, records_ok),' \
    '"door": door_html(rec or {"TEST-URL": i["html_url"]}, records_ok),'
run red "a failed read renders as an answer" \
    "#172 a FAILED read says the link is UNKNOWN" tools/lanes-page.py \
    'records_ok = records_ok and gh_ok' \
    'records_ok = True'
run red "the card leads with the title again, as it did on 8/20" \
    "#172 …and every one of them starts with a verb" tools/lanes-page.py \
    'return f"Take a look — {what}"' \
    'return what'
run red "the title stops being plainised inside the action" \
    "#172 titles are still plainised inside the action" tools/lanes-page.py \
    '    what = plainize(issue["title"])' \
    '    what = issue["title"]'
run red "an ACTION record is ignored" \
    "#172 an ACTION record outranks the derived verb" tools/lanes-page.py \
    '    if rec.get("ACTION"):' \
    '    if rec.get("ACTION") and False:'
run red "the clipboard payload loses its replies" \
    "#172 Copy for keeper carries" tools/lanes-page.py \
    'return f"Re #{n} {action} — " + (f"[{tail}]" if tail else "")' \
    'return f"Re #{n} {action} — "'
run red "the suggested replies stop printing on the card" \
    "#172 the suggested replies are printed on the card" tools/lanes-page.py \
    '            if t.get("says"):' \
    '            if t.get("says") and False:'
run red "on GitHub is promoted back to a control" \
    "#172 'on GitHub' is fine print on a bullet" tools/lanes-page.py \
    'rel="noopener" class="ghfine">#{issue["number"]} on GitHub ' \
    'rel="noopener" class="ghlink">#{issue["number"]} on GitHub '
run red "keeper tooling becomes a bullet again" \
    "#172 merged + infra + not built is NOT a bullet" tools/lanes-page.py \
    '            if "infra" in lab:' \
    '            if "infra" in lab and False:'
run red "the quiet line vanishes instead of being quiet" \
    "#172 …it drops to the quiet line instead of vanishing" tools/lanes-page.py \
    '        if quiet:' \
    '        if quiet and False:'
run red "a label-derived needs-you is a hand-raise again (the #138 shape)" \
    "#172 a label-derived 'needs you' seat is not an 'it asked' bullet" tools/lanes-page.py \
    'l["state_from_label"] = True' \
    'l["state_from_label"] = False'
run red "a loud block is collapsed into an accordion" \
    "#172 NO loud block is inside an accordion" tools/lanes-page.py \
    "                         f'style=\"color:#e8e6df\">#{i[\"number\"]} '
                         f'{html.escape(plainize(i[\"title\"], 70))}</a></div>')" \
    "                         f'style=\"color:#e8e6df\">#{i[\"number\"]} '
                         f'{html.escape(plainize(i[\"title\"], 70))}</a></div>')
                h.insert(-1, '<details class=\"acc\" data-acc=\"z\"><summary>'
                             '<h2>Z</h2><span class=\"acccount\">1 z</span>'
                             '</summary><div class=\"accbody\">')
                h.append('</div></details>')"
run red "Your list defaults collapsed like everything else" \
    "#172 Your list opens by default and nothing else does" tools/lanes-page.py \
    'plural(len(todo), "item"), open_=True' \
    'plural(len(todo), "item"), open_=False'
run red "the section counts become a constant" \
    "#172 the counts are the real ones, not a constant" tools/lanes-page.py \
    'return f"{n} {word if n == 1' \
    'return f"{n + 1} {word if n == 1'
run red "the remembered open state restores one way only" \
    "#172 …and it OVERRIDES the server default in both directions" tools/lanes-page.py \
    "if(v==='1')d.open=true;else if(v==='0')d.open=false;" \
    "if(v==='1')d.open=true;"

echo
echo "no-ops — these MUST stay green, or the gate is merely edit-sensitive:"
run green "a comment added to the renderer" "" tools/lanes-page.py \
    'def git_words(l):' \
    '# a comment that changes nothing at all
def git_words(l):'
run green "whitespace reflowed in the status script" "" tools/lanes-status.sh \
    'SEAT_CEILING=6     # worktrees existing' \
    'SEAT_CEILING=6         # worktrees existing'
run green "a comment added inside the #172 record parser" "" tools/lanes-page.py \
    'def _absorb(records, triples):' \
    '# changes nothing about which record wins
def _absorb(records, triples):'
run green "the accordion CSS reflowed" "" tools/lanes-page.py \
    '.accbody{padding:2px 0 12px;}' \
    '.accbody{ padding:2px 0 12px; }'

echo
echo "──────────────────────────────────────────────────────────────"
echo "red-first: $pass proved, $fail unproved"
[ "$fail" -eq 0 ] || exit 1
