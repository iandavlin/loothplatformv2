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
    mkdir -p "$d/tools/gates" "$d/tools/lanes" "$d/tools/decisions" "$d/webroot"
    cp "$ROOT/tools/lanes-status.sh"      "$d/tools/"
    cp "$ROOT/tools/lanes-page.py"        "$d/tools/"
    cp "$ROOT/tools/lanes-poke-worker.sh" "$d/tools/"
    cp "$ROOT/tools/lanes/stall-watchdog.sh" "$d/tools/lanes/"
    cp "$ROOT/webroot/lanes-poke.php"     "$d/webroot/"
    # #202 — the decision box's three files. A snapshot that does not carry
    # them makes every mutation below fail as CANNOT RUN, which reads exactly
    # like a gate with nothing to say.
    cp "$ROOT/webroot/lanes-decisions.php" "$d/webroot/"
    cp "$ROOT/webroot/lanes-decide.php"    "$d/webroot/"
    cp "$ROOT/tools/decisions/lg-decide.py" "$d/tools/decisions/"
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
#     [<file2> <needle2> <repl2>]
#
# The optional SECOND file exists for one specific shape: a property held
# jointly by two files that must agree. Breaking only one of them breaks their
# AGREEMENT (and is worth its own mutation); breaking both IDENTICALLY is the
# only way to break the property while leaving them agreeing — which is exactly
# how you prove an assertion about the property rather than about the drift.
run() {
    local expect="$1" name="$2" want="$3" file="$4" needle="$5" repl="$6"
    local file2="${7:-}" needle2="${8:-}" repl2="${9:-}"
    local d="$WORK/m$((pass+fail))"
    snapshot "$d"
    if [ -n "$needle" ]; then
        if ! mutate "$d" "$file" "$needle" "$repl"; then
            echo "  ✗ $name — the mutation could not be applied (stale needle)"
            fail=$((fail+1)); return
        fi
    fi
    if [ -n "$file2" ]; then
        if ! mutate "$d" "$file2" "$needle2" "$repl2"; then
            echo "  ✗ $name — the SECOND mutation could not be applied (stale needle)"
            fail=$((fail+1)); return
        fi
    fi
    local out rc
    out="$(python3 "$d/tools/gates/lanes-page-truth-gate.py" 2>&1)"; rc=$?
    if [ "$expect" = green ]; then
        if [ "$rc" -eq 0 ]; then echo "  ✓ $name — stayed GREEN, as it must"; pass=$((pass+1))
        else echo "  ✗ $name — went RED on a change that breaks nothing:"
             grep '^  FAIL' <<<"$out" | sed 's/^/        /'; fail=$((fail+1)); fi
        return
    fi
    if [ "$rc" -eq 0 ]; then
        echo "  ✗ $name — gate stayed GREEN with the behaviour BROKEN"; fail=$((fail+1)); return
    fi
    if [ "$rc" -ne 1 ]; then
        echo "  ✗ $name — gate exited $rc (expected 1); a CANNOT-RUN is not a finding"; fail=$((fail+1)); return
    fi
    # ⚠ HERESTRING, NOT A PIPE, AND THIS IS NOT STYLE.
    #
    # `printf ... | grep -q` under `set -o pipefail` reports the PIPELINE's
    # worst status, and `grep -q` exits the instant it matches — which closes
    # the pipe and kills `printf` with SIGPIPE (141). So a SUCCESSFUL match
    # returns 141 and the harness declares a perfectly good assertion "RED, but
    # NOT for the stated reason", printing the very line it just failed to find.
    #
    # It is SIZE-DEPENDENT, which is why it lay dormant: while the gate's output
    # fitted the 64KB pipe buffer, printf finished before grep exited and the
    # bug never fired. #202 pushed gate 77 past that buffer and it began
    # misreporting the FIRST mutations whose named check appears early in the
    # output — the earlier the match, the more unwritten output, the likelier
    # the SIGPIPE. Reproduced deliberately:
    #
    #     set -uo pipefail
    #     printf '%s\n' "$small" | grep -q target   → 0
    #     printf '%s\n' "$big"   | grep -q target   → 141
    #
    # A herestring feeds grep without a pipe, so there is no pipeline status to
    # poison and no writer to signal.
    if grep -q "FAIL  $want" <<<"$out"; then
        echo "  ✓ $name — RED, naming: $want"; pass=$((pass+1))
    else
        echo "  ✗ $name — RED, but NOT for the stated reason. It said:"
        grep '^  FAIL' <<<"$out" | sed 's/^/        /'
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
echo "#202 — the decision box:"
# ⚠ THE FIRST TWO ARE ABOUT DRIFT, NOT BINDING, AND THEY WERE MIS-AIMED AT
# FIRST. Changing the formula in ONE endpoint makes the two files disagree, so
# NOTHING authenticates: every "…is refused" check passes vacuously and it is
# the HAPPY PATH that reddens. That is a real and valuable thing to prove — it
# is the #191 lesson (gate the agreement you cannot de-duplicate) made
# executable — but it is not a test of what the nonce binds. Naming it that way
# was the harness reporting a pass for the wrong reason.
run red "the two endpoints' nonce formulas DRIFT (the option half)" \
    "#202 the happy path is accepted" webroot/lanes-decide.php \
    "'decide:' . \$id . ':' . \$key . ':'" \
    "'decide:' . \$id . ':'"
run red "the two endpoints' nonce formulas DRIFT (the question half)" \
    "#202 the happy path is accepted" webroot/lanes-decide.php \
    "'decide:' . \$id . ':' . \$key . ':'" \
    "'decide:' . \$key . ':'"
# …and these two break the BINDING while keeping the files in agreement, which
# is the only way to make a forged option or question actually go through.
run red "the nonce stops binding the OPTION (both files, still agreeing)" \
    "#202 a nonce minted for ANOTHER OPTION is refused on this one" webroot/lanes-decide.php \
    "'decide:' . \$id . ':' . \$key . ':'" \
    "'decide:' . \$id . ':'" \
    webroot/lanes-decisions.php \
    "'decide:' . \$id . ':' . \$k . ':' . \$day" \
    "'decide:' . \$id . ':' . \$day"
run red "the nonce stops binding the QUESTION (both files, still agreeing)" \
    "#202 a nonce minted for ANOTHER QUESTION is refused on this one" webroot/lanes-decide.php \
    "'decide:' . \$id . ':' . \$key . ':'" \
    "'decide:' . \$key . ':'" \
    webroot/lanes-decisions.php \
    "'decide:' . \$id . ':' . \$k . ':' . \$day" \
    "'decide:' . \$k . ':' . \$day"
# ⚠ FIRST-ANSWER-WINS HAS TWO GUARDS, AND ONLY ONE OF THEM IS THE REAL ONE.
# `answer()` short-circuits on an already-answered BODY before it reaches the
# claim. That fast path is an optimisation; the CLAIM is the guarantee. Removing
# O_EXCL alone is therefore NOT deterministically detectable — the fast path
# catches most racers on its own, so the gate went red in one run and green in
# the next. A mutation that only sometimes reddens is worse than none: it
# reports a healthy tree as broken on a bad night.
#
# So the pair below is deterministic and says something the single mutation
# never could:
#   · remove the fast path ALONE  → still exactly one winner  (GREEN)
#     which is a positive proof that the CLAIM is doing the work;
#   · remove the fast path AND the claim's O_EXCL → many winners  (RED).
run green "the fast path goes, and the CLAIM alone still holds the line" "" \
    tools/decisions/lg-decide.py \
    '    if q.get("answered"):
        a = q["answered"]
        raise Refused' \
    '    if False:
        a = q["answered"]
        raise Refused'
run red "first-answer-wins loses BOTH its fast path and its atomic claim" \
    "#202 eight simultaneous answerers produce EXACTLY ONE winner" tools/decisions/lg-decide.py \
    '    if q.get("answered"):
        a = q["answered"]
        raise Refused' \
    '    if False:
        a = q["answered"]
        raise Refused' \
    tools/decisions/lg-decide.py \
    'os.O_CREAT | os.O_EXCL | os.O_WRONLY' \
    'os.O_CREAT | os.O_WRONLY'
run red "the claim stops outranking a stale body, so a settled question is re-offered" \
    "#202 …and such a question is absent from the box as well" webroot/lanes-decisions.php \
    "if (file_exists(LG_DECIDE_STORE . '/' . \$id . '.claim')) continue;" \
    "if (false) continue;"
run red "the 2-4 bound goes, and a wall of options becomes a decision box" \
    "#202 five options are refused" tools/decisions/lg-decide.py \
    'if not (MIN_OPTIONS <= len(opts) <= MAX_OPTIONS):' \
    'if False:'
run red "an option keeper never posed stops being refused before the claim" \
    "#202 an option key keeper never posed is refused" tools/decisions/lg-decide.py \
    'if key not in keys:' \
    'if False:'
run red "a missing spool reports success instead of failing loud" \
    "#202 a missing spool fails LOUDLY rather than reporting success" webroot/lanes-decide.php \
    "if (!is_file(LG_DECIDE_SPOOL) || !is_writable(LG_DECIDE_SPOOL))  fail(500, 'decision spool not installed on this box');" \
    "if (false) fail(500, 'decision spool not installed on this box');"
run red "an unreadable store renders as silence — this page's oldest law" \
    "#202 an UNREADABLE store ⇒ LOUD, never silence" tools/lanes-page.py \
    "    if not dok:
        h.append('<div class=\"block gap\"><b>DECISIONS UNKNOWN</b><br>'" \
    "    if False:
        h.append('<div class=\"block gap\"><b>DECISIONS UNKNOWN</b><br>'"
run red "the token is handed to the browser" \
    "#202 the token never reaches the browser" webroot/lanes-decisions.php \
    "echo json_encode(['ok' => true, 'questions' => \$out," \
    "echo json_encode(['tok' => \$token, 'ok' => true, 'questions' => \$out,"
run red "a tap that lost the race is silently dropped" \
    "#202 a tap that LOST the race still reaches the board, saying so" tools/lanes-poke-worker.sh \
    'why=$(clean "$(cat "$err" 2>/dev/null)")' \
    'why=""; if true; then rm -f "$err"; continue; fi'
# ⚠ SINGLE-QUOTED, and the reason is worth the line: written with double
# quotes, bash expanded $ts itself under `set -u`, the script died with
# "ts: unbound variable" at this line, and every mutation AFTER it silently
# never ran. A harness that aborts mid-suite reports fewer proofs than it has,
# and the missing ones look like they were never written.
run red "an answer is filed as a poke, so keeper is told a seat looks idle" \
    "#202 an answer is NOT written to the poke wake file" tools/lanes-poke-worker.sh \
    'printf '"'"'%s %s %s\n'"'"' "$ts" "$qid" "$key" >> "$DECIDES"' \
    'printf '"'"'%s %s %s\n'"'"' "$ts" "$qid" "$key" >> "$POKES"' 
run red "the worker stops marking the store, so the other channel never clears" \
    "#202 the store is marked answered, by the page" tools/lanes-poke-worker.sh \
    'if rec=$(python3 "$DECIDE_CLI" answer "$qid" "$key" --via page 2>"$err"); then' \
    'if rec=$(python3 "$DECIDE_CLI" show "$qid" --json 2>"$err"); then'
run red "the endpoint stops checking that anything will DRAIN the spool" \
    "#202 an UNDEPLOYED delivery worker refuses the answer, loudly" webroot/lanes-decide.php \
    "if (strpos(\$worker, 'LG_DECIDE_WORKER_V1') === false) {" \
    "if (false) {"
run red "the marker is renamed, so a deployed worker reads as undeployed" \
    "#202 …and the SAME answer goes through once the worker knows the verb" tools/lanes-poke-worker.sh \
    'LG_DECIDE_WORKER_V1' \
    'LG_DECIDE_WORKER_RENAMED'
run red "the button is drawn even when nothing is pending" \
    "#202 nothing pending ⇒ SILENCE (quiet when healthy)" tools/lanes-page.py \
    '    elif dcount:' \
    '    elif True:'

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
run green "a comment added inside the question store" "" tools/decisions/lg-decide.py \
    'def new_id():' \
    '# changes nothing about how an id is shaped
def new_id():'
run green "the decision-box CSS reflowed" "" tools/lanes-page.py \
    '.qdone{color:#9db668;font-size:13px;font-weight:700;margin-top:8px;}' \
    '.qdone{ color:#9db668; font-size:13px; font-weight:700; margin-top:8px; }'

echo
echo "──────────────────────────────────────────────────────────────"
echo "red-first: $pass proved, $fail unproved"
[ "$fail" -eq 0 ] || exit 1
