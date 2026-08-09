#!/usr/bin/env bash
#
# REMEDIATION #5 — Phase 1 clean start: snapshot + truncate the Stripe mirror
# tables in the lg_membership database.
# PREPARED FOR LIVE — Ian runs this by hand. NOT wired into any cron.
#
# Ian's ruling, 2026-08-09: "Truncate — clean start."
# Runbook: TRUNCATE-LG-MEMBERSHIP-RUNBOOK.md (read it first — it carries an
# OPEN QUESTION about 3 lg_role_sources rows that this script deliberately
# does NOT touch).
#
# MODES
#   ./truncate-lg-membership.sh                 review — writes nothing, prints the plan
#   ./truncate-lg-membership.sh apply           snapshot every table, then truncate
#   ./truncate-lg-membership.sh verify          post-apply state check
#   ./truncate-lg-membership.sh rollback <stamp>         restore from snapshots
#   ./truncate-lg-membership.sh drop-snapshots <stamp> confirm
#
# OVERRIDES
#   --accept-drift   live counts no longer match the 2026-08-09 pinned
#                    expectation. Read the printed diff first. Drift here means
#                    something wrote to a table every known writer of which is
#                    frozen — understand WHAT before truncating it.
#
# SAFETY PROPERTIES
#   - lg_patreon_members and lg_role_sources are NEVER touched. A forbidden-list
#     self-check aborts the script if a future edit ever adds them to the set.
#   - Every table is snapshotted (zz_truncsnap_<stamp>_<name>) and count-verified
#     BEFORE anything is truncated. Apply aborts before the first TRUNCATE on any
#     mismatch.
#   - Rollback restores from those snapshots and is verified by CHECKSUM TABLE
#     equality per table (proven byte-identical on the dev2 clone, 2026-08-09).
#   - FOREIGN_KEY_CHECKS is disabled per-SESSION only, inside a single mysql
#     connection, for an all-tables-at-once truncate — never globally.
#   - Every count is guarded numeric before use; an empty variable aborts.
#   - No Stripe API call. No WP user created, deleted, or emailed.
#
# The lg_event_cursor guard: its ONLY row on live is source='stripe' (verified
# 2026-08-09). If a non-stripe cursor row ever appears, this script refuses to
# truncate that table rather than eat someone else's cursor.

set -euo pipefail

DB="${LGMS_DB:-lg_membership}"   # LGMS_DB override exists for dev2 rehearsal only
MYSQL_OPTS="${MYSQL_OPTS:-}"

# Truncation order: children before parents (FK CASCADE/RESTRICT topology).
TRUNCATE_SET=(
    wp_user_bridge
    entitlements
    subscriptions
    order_items
    orders
    gift_recipients_pending
    gift_codes
    admin_action_log
    customers
    prices
    products
    pending_sessions
    audit_log
    lg_processed_events
    lg_event_cursor
    trial_fingerprints
)

# Restore order: parents before children.
RESTORE_SET=(
    customers
    products
    prices
    orders
    order_items
    wp_user_bridge
    subscriptions
    entitlements
    gift_codes
    gift_recipients_pending
    pending_sessions
    admin_action_log
    audit_log
    lg_processed_events
    lg_event_cursor
    trial_fingerprints
)

# Tables this script must NEVER touch. lg_patreon_members and lg_role_sources
# are LIVE PATREON DATA in the same schema — the named trap. The rest are
# first-party (affiliate program, QA feedback, pricing config, policy).
FORBIDDEN=(
    lg_patreon_members
    lg_role_sources
    lg_test_feedback
    lg_affiliate_payouts
    affiliates
    affiliate_clicks
    affiliate_conversions
    affiliate_debits
    banned_emails
    price_regions
)

# Pinned expectation — measured on live 2026-08-09 via live-ro. A TRIPWIRE:
# every writer of every table below is frozen or routeless on live, so these
# counts should not move at all. If they moved, find out what wrote.
declare -A PINNED=(
    [customers]=4
    [wp_user_bridge]=3
    [subscriptions]=3
    [entitlements]=7
    [orders]=0
    [order_items]=0
    [products]=11
    [prices]=21
    [gift_codes]=4
    [gift_recipients_pending]=0
    [pending_sessions]=6
    [admin_action_log]=4
    [audit_log]=0
    [lg_processed_events]=883
    [lg_event_cursor]=1
    [trial_fingerprints]=1
)

die()  { echo "!! $*" >&2; exit 1; }
note() { echo "-- $*"; }

q() { mysql $MYSQL_OPTS -N -B "$DB" -e "$1"; }

count() {
    local t="$1" n
    n="$(q "SELECT COUNT(*) FROM \`$t\`")"
    [[ "$n" =~ ^[0-9]+$ ]] || die "non-numeric COUNT(*) for $t: '$n'"
    echo "$n"
}

table_exists() {
    local t="$1" n
    n="$(q "SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='$t'")"
    [[ "$n" == "1" ]]
}

checksum() {
    local t="$1" c
    c="$(q "CHECKSUM TABLE \`$t\`" | awk '{print $2}')"
    [[ "$c" =~ ^[0-9]+$ ]] || die "non-numeric checksum for $t: '$c'"
    echo "$c"
}

# ---------------------------------------------------------------- preflight --
preflight() {
    # Self-check: the forbidden list outranks the truncate list, forever.
    local t f
    for t in "${TRUNCATE_SET[@]}"; do
        for f in "${FORBIDDEN[@]}"; do
            [[ "$t" == "$f" ]] && die "SELF-CHECK FAILED: forbidden table '$t' is in TRUNCATE_SET. Refusing everything."
        done
    done
    [[ "${#TRUNCATE_SET[@]}" -eq 16 && "${#RESTORE_SET[@]}" -eq 16 ]] \
        || die "self-check: expected exactly 16 tables in each set"

    for t in "${TRUNCATE_SET[@]}"; do
        table_exists "$t" || die "table $DB.$t does not exist — wrong schema?"
    done

    # Live-Patreon sentinels must exist and be populated — proves this is the
    # real lg_membership, and gives before-values the apply re-checks after.
    table_exists lg_patreon_members || die "sentinel lg_patreon_members missing — wrong schema"
    table_exists lg_role_sources    || die "sentinel lg_role_sources missing — wrong schema"
    PM_BEFORE="$(count lg_patreon_members)"
    RS_BEFORE="$(count lg_role_sources)"
    [[ "$PM_BEFORE" -ge 1500 ]] || die "lg_patreon_members has $PM_BEFORE rows (< 1500) — this is not the live lg_membership"

    # lg_event_cursor: refuse if it holds anything but the stripe cursor.
    local nonstripe
    nonstripe="$(q "SELECT COUNT(*) FROM lg_event_cursor WHERE source <> 'stripe'")"
    [[ "$nonstripe" =~ ^[0-9]+$ ]] || die "non-numeric cursor probe: '$nonstripe'"
    [[ "$nonstripe" == "0" ]] || die "lg_event_cursor holds $nonstripe non-stripe row(s) — refusing to truncate someone else's cursor"
}

check_pinned() {
    local drift=0 t n
    for t in "${TRUNCATE_SET[@]}"; do
        n="$(count "$t")"
        if [[ "$n" != "${PINNED[$t]}" ]]; then
            echo "!! DRIFT: $t = $n rows, pinned ${PINNED[$t]}"
            drift=1
        fi
    done
    if [[ "$drift" == "1" && "$ACCEPT_DRIFT" != "1" ]]; then
        die "live no longer matches the 2026-08-09 pinned expectation. Every writer of these tables is frozen — find out what wrote before truncating it. Re-run with --accept-drift only once you understand the diff."
    fi
    [[ "$drift" == "1" ]] && note "drift ACCEPTED by explicit flag"
    return 0
}

print_plan() {
    echo "=== TRUNCATE PLAN — $DB ==="
    printf '%-26s %8s %8s\n' "table" "rows" "pinned"
    local t
    for t in "${TRUNCATE_SET[@]}"; do
        printf '%-26s %8s %8s\n' "$t" "$(count "$t")" "${PINNED[$t]}"
    done
    echo
    echo "NEVER touched (sentinels before): lg_patreon_members=$PM_BEFORE lg_role_sources=$RS_BEFORE"
    echo "Also excluded: lg_test_feedback lg_affiliate_payouts affiliates affiliate_clicks"
    echo "               affiliate_conversions affiliate_debits banned_emails price_regions"
}

# -------------------------------------------------------------------- apply --
apply() {
    check_pinned
    print_plan

    local stamp stamp_t t n s
    stamp="$(date +%Y%m%d-%H%M%S)"
    stamp_t="${stamp//-/_}"

    local backstop="$HOME/backup-lgms-truncate-$stamp.sql"
    note "backstop dump -> $backstop"
    mysqldump $MYSQL_OPTS "$DB" "${TRUNCATE_SET[@]}" > "$backstop"
    [[ -s "$backstop" ]] || die "backstop dump is empty — aborting before any write"

    note "snapshotting ${#TRUNCATE_SET[@]} tables as zz_truncsnap_${stamp_t}_<name>"
    for t in "${TRUNCATE_SET[@]}"; do
        table_exists "zz_truncsnap_${stamp_t}_${t}" && die "snapshot zz_truncsnap_${stamp_t}_${t} already exists"
        q "CREATE TABLE \`zz_truncsnap_${stamp_t}_${t}\` AS SELECT * FROM \`$t\`"
    done
    for t in "${TRUNCATE_SET[@]}"; do
        n="$(count "$t")"; s="$(count "zz_truncsnap_${stamp_t}_${t}")"
        [[ "$n" == "$s" ]] || die "snapshot mismatch on $t: table=$n snapshot=$s — NOTHING truncated, aborting"
    done
    note "snapshots verified: 16/16 counts equal"

    # One session, FK checks off for that session only, truncate all 16.
    local sql="SET SESSION FOREIGN_KEY_CHECKS = 0;"
    for t in "${TRUNCATE_SET[@]}"; do sql+=" TRUNCATE TABLE \`$t\`;"; done
    sql+=" SET SESSION FOREIGN_KEY_CHECKS = 1;"
    q "$sql"

    local bad=0
    for t in "${TRUNCATE_SET[@]}"; do
        n="$(count "$t")"
        [[ "$n" == "0" ]] || { echo "!! $t still has $n rows"; bad=1; }
    done
    [[ "$bad" == "0" ]] || die "truncate incomplete — investigate before doing anything else"

    local pm_after rs_after
    pm_after="$(count lg_patreon_members)"
    rs_after="$(count lg_role_sources)"
    [[ "$pm_after" -ge "$PM_BEFORE" ]] || die "SENTINEL MOVED: lg_patreon_members $PM_BEFORE -> $pm_after. Investigate NOW."
    [[ "$rs_after" -ge "$RS_BEFORE" ]] || die "SENTINEL MOVED: lg_role_sources $RS_BEFORE -> $rs_after. Investigate NOW."

    echo
    echo "=== DONE. All 16 mirror tables truncated. ==="
    echo "Sentinels untouched: lg_patreon_members=$pm_after lg_role_sources=$rs_after"
    echo "ROLLBACK HANDLE (write it down): $stamp"
    echo "  ./truncate-lg-membership.sh rollback $stamp"
    echo "Backstop dump: $backstop"
}

# ----------------------------------------------------------------- rollback --
rollback() {
    local stamp="$1" stamp_t t n c1 c2
    [[ "$stamp" =~ ^[0-9]{8}-[0-9]{6}$ ]] || die "rollback needs a stamp like 20260809-141530"
    stamp_t="${stamp//-/_}"

    for t in "${RESTORE_SET[@]}"; do
        table_exists "zz_truncsnap_${stamp_t}_${t}" || die "snapshot zz_truncsnap_${stamp_t}_${t} missing — cannot roll back from this stamp"
    done
    for t in "${RESTORE_SET[@]}"; do
        n="$(count "$t")"
        [[ "$n" == "0" ]] || die "$t is not empty ($n rows) — rollback only restores into the truncated state. Reconcile by hand."
    done

    local sql="SET SESSION FOREIGN_KEY_CHECKS = 0;"
    for t in "${RESTORE_SET[@]}"; do
        sql+=" INSERT INTO \`$t\` SELECT * FROM \`zz_truncsnap_${stamp_t}_${t}\`;"
    done
    sql+=" SET SESSION FOREIGN_KEY_CHECKS = 1;"
    q "$sql"

    local bad=0
    for t in "${RESTORE_SET[@]}"; do
        c1="$(checksum "$t")"; c2="$(checksum "zz_truncsnap_${stamp_t}_${t}")"
        if [[ "$c1" != "$c2" ]]; then echo "!! checksum mismatch on $t: $c1 vs snapshot $c2"; bad=1; fi
    done
    [[ "$bad" == "0" ]] || die "restore NOT byte-identical — do not drop snapshots; investigate"
    echo "=== ROLLBACK COMPLETE — 16/16 tables checksum-identical to snapshots ==="
    echo "(AUTO_INCREMENT self-adjusts to MAX(id)+1 on restore — proven on the dev2 clone.)"
}

# ------------------------------------------------------------------- verify --
verify() {
    local clean=1 t n
    for t in "${TRUNCATE_SET[@]}"; do
        n="$(count "$t")"
        [[ "$n" == "0" ]] || { echo "$t: $n rows"; clean=0; }
    done
    echo "sentinels: lg_patreon_members=$(count lg_patreon_members) lg_role_sources=$(count lg_role_sources)"
    q "SELECT TABLE_NAME FROM information_schema.TABLES
       WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME LIKE 'zz\\_truncsnap\\_%' ORDER BY TABLE_NAME" \
        | sed 's/^/snapshot: /'
    [[ "$clean" == "1" ]] && echo "Clean — every mirror table is empty." \
                          || echo "NOT clean — mirror tables hold rows (either pre-apply, or Phase 1 has started writing)."
}

# ----------------------------------------------------------- drop-snapshots --
drop_snapshots() {
    local stamp="$1" confirm="${2:-}" stamp_t t
    [[ "$stamp" =~ ^[0-9]{8}-[0-9]{6}$ ]] || die "drop-snapshots needs a stamp like 20260809-141530"
    [[ "$confirm" == "confirm" ]] || die "refusing without the literal word: drop-snapshots $stamp confirm"
    stamp_t="${stamp//-/_}"
    for t in "${TRUNCATE_SET[@]}"; do
        table_exists "zz_truncsnap_${stamp_t}_${t}" && q "DROP TABLE \`zz_truncsnap_${stamp_t}_${t}\`"
    done
    echo "snapshots for $stamp dropped. The ~/backup-lgms-truncate-$stamp.sql dump remains."
}

# --------------------------------------------------------------------- main --
MODE="${1:-review}"
ACCEPT_DRIFT=0
for a in "$@"; do [[ "$a" == "--accept-drift" ]] && ACCEPT_DRIFT=1; done

preflight

case "$MODE" in
    review)         check_pinned || true; print_plan; echo; echo "review only — nothing written." ;;
    apply)          apply ;;
    verify)         verify ;;
    rollback)       rollback "${2:?rollback needs the stamp printed by apply}" ;;
    drop-snapshots) drop_snapshots "${2:?needs the stamp}" "${3:-}" ;;
    *)              die "unknown mode '$MODE' (review|apply|verify|rollback <stamp>|drop-snapshots <stamp> confirm)" ;;
esac
