#!/usr/bin/env bash
# live-billing-cutover.sh — #197 — retire live's standalone /srv/lg-stripe-billing
# and serve the monorepo copy through the same path.
#
#   sudo tools/infra/live-billing-cutover.sh --check     # measure, change nothing
#   sudo tools/infra/live-billing-cutover.sh --apply     # the window
#   sudo tools/infra/live-billing-cutover.sh --verify    # the battery, after
#   sudo tools/infra/live-billing-cutover.sh --rollback  # one mv back
#
# WHY A SCRIPT AND NOT A PASTE BLOCK: live-push-literal-ids. Every path here is
# literal and fetched from the repo, so the thing Ian runs is the thing that was
# reviewed — not something retyped into a terminal at the moment it matters most.
#
# WHAT THE SWAP ACTUALLY IS. /srv/lg-stripe-billing is today a real directory
# holding a SECOND git repo (iandavlin/lg-stripe-billing @ d7a71f3, last touched
# May 10). It becomes a symlink to the monorepo's lg-stripe-billing/, which is
# the same arrangement dev2 has run since 2026-08-16. nginx is untouched: the
# vhost already aliases /srv/lg-stripe-billing/public/ and a symlink there is
# transparent to it.
#
# THE THREE THINGS A PULL CANNOT DELIVER, and the reason this script exists:
# vendor/, .env and logs/ are all gitignored, so the serving checkout has none
# of them and no `git pull` will ever create them. Swapping without all three is
# an instant 500.
#
#   vendor/ is COPIED, never `composer install`ed. Measured 2026-08-22: the old
#   tree and the monorepo tree are byte-identical (890 files, same manifest
#   hash) and composer.lock is byte-identical, so a copy is provably the same
#   bytes AND needs no packagist egress inside a change window.
#
#   .env is copied with `cp -a`, which carries live's 0640 www-data:www-data
#   posture unchanged. THIS IS DELIBERATELY NOT dev2's POSTURE — dev2's .env is
#   0664 ubuntu and world-readable, and mirroring dev2 "exactly" would widen
#   live Stripe secrets to every account on the box. Preflight refuses to run if
#   it finds anything other than 0640 www-data.
#
#   logs/ is created owned by the FPM pool user. Monolog's StreamHandler creates
#   its own directory, but as www-data inside an ubuntu-owned 0775 tree it
#   cannot, and the app 500s on its first log write — with the HTTP routes
#   answering 200 right up until something logs.
#
# ⚠️ THE FPM RELOAD IS A STEP, NOT A TIDY-UP. PHP's opcache and realpath cache
# serve the old compiled file through a re-pointed symlink (proven on dev2
# 2026-06-28, SERVE-CONSOLIDATE-MEMBERSHIP-RUNBOOK.md D3). Skip it and every
# check below still passes while live runs the old code.
#
# ⚠️ THE SWAP CHANGES CHECKOUT BEHAVIOUR, ON PURPOSE. The new tree runs #181's
# CheckoutAudienceGuard as the first gate on POST /v1/checkout; the old tree has
# no such gate. With lgms_shared_secret ABSENT on live (measured 2026-08-22) the
# guard's probe is refused by WordPress, an unknown answer refuses, and checkout
# answers 503 to everyone. That is fail-safe-closed and it is not member-visible
# — lgms_stripe_pages_live is 0, so the purchase pages are administrator-only.
# --verify treats that 503 as EXPECTED-TODAY and asserts its exact sentence, so
# it can never be confused with the 403 that arrives once the secret is set.
set -uo pipefail

MODE="check"
case "${1:-}" in
    --check)    MODE="check" ;;
    --apply)    MODE="apply" ;;
    --verify)   MODE="verify" ;;
    --rollback) MODE="rollback" ;;
    "")         ;;
    *) echo "usage: $0 [--check|--apply|--verify|--rollback]" >&2; exit 2 ;;
esac

# ─────────────────────────────────────────────────────────────────────────────
# REDIRECTABLE ROOT — so this script can be TESTED rather than trusted.
# With LG_BC_ROOT unset every path is byte-for-byte the production path. With it
# set, the whole thing runs inside a temp dir: no root, no /srv, no systemctl.
ROOT="${LG_BC_ROOT:-}"

LIVE_HOSTNAME="ip-172-31-67-175"
OLD="$ROOT/srv/lg-stripe-billing"
NEW="$ROOT/home/ubuntu/loothplatformv2-clean/lg-stripe-billing"
CHECKOUT="$ROOT/home/ubuntu/loothplatformv2-clean"
VHOST="$CHECKOUT/platform/nginx/dev2.loothgroup.com.conf"
STATE="$ROOT/var/lib/lg-billing-cutover.state"
POOL_USER="www-data"
PUBLIC_HOST="loothgroup.com"

# The two sentences the battery asserts. Copied from the code they come from:
#   CheckoutAudienceGuard::UNKNOWN_MESSAGE
#   CheckoutAudience::refusalMessage()  (WordPress side, reaches Slim as `message`)
SENTENCE_503="We could not verify access to checkout just now."
SENTENCE_403="Memberships are not open for sale yet."

# ⚠️ BOTH SENTENCES STOP AT THE FIRST FULL STOP, DELIBERATELY. The source strings
# are PHP concatenations spanning two lines, and the 403 one contains an em-dash
# that comes back from the API JSON-escaped as \u2014 — measured on dev2
# 2026-08-22. Matching the whole sentence with grep -F would fail on a HEALTHY
# box. Matching the first clause cannot.
#
# THE PROBE PRICE ID IS DELIBERATELY NOT REAL. It is well-formed, so it passes the
# "price_id is required" check at CheckoutController:94 and reaches the audience
# guard at :124 — but it maps to nothing, so it can never mint a Stripe checkout
# session, on either tree, in any audience state. A probe carrying a REAL price id
# would mint one on any box where the guard is off or absent, which is exactly the
# #181 hole this cutover is delivering the fix for. A verify battery must not walk
# through the hole it is verifying.
PROBE_PRICE="price_LANE197VERIFYNOTREAL"
PROBE_EMAIL="lane197-verify@example.invalid"

RC=0
say()  { printf '%s\n' "$*"; }
ok()   { printf '  \033[32mPASS\033[0m  %s\n' "$*"; }
bad()  { printf '  \033[31mFAIL\033[0m  %s\n' "$*"; RC=1; }
note() { printf '  ----  %s\n' "$*"; }

# ─────────────────────────────────────────────────────────────────────────────
# GUARDS
guard_box() {
    [ -n "$ROOT" ] && { note "LG_BC_ROOT set — hostname guard skipped (test mode)"; return 0; }
    if [ "$(hostname)" != "$LIVE_HOSTNAME" ]; then
        say "WRONG BOX — this is $(hostname), expected live ($LIVE_HOSTNAME). Nothing done."
        exit 1
    fi
    ok "box is live ($LIVE_HOSTNAME)"
}

guard_root() {
    [ -n "$ROOT" ] && return 0
    if [ "$(id -u)" -ne 0 ]; then
        say "Run with sudo. Nothing done."
        exit 1
    fi
}

# `sudo -u www-data` in production; a plain read in test mode.
as_pool() {
    if [ -n "$ROOT" ]; then "$@"; else sudo -u "$POOL_USER" "$@"; fi
}

# ─────────────────────────────────────────────────────────────────────────────
# PREFLIGHT — every check must pass. Fails closed: --apply refuses to start.
preflight() {
    say "PREFLIGHT"
    guard_box

    # 1. The old path is a real directory, not already a symlink. This is what
    #    makes a second --apply refuse instead of moving the checkout aside.
    if [ -L "$OLD" ]; then
        bad "$OLD is ALREADY a symlink — the swap has already run. Nothing to do."
        return 1
    elif [ -d "$OLD" ]; then
        ok "$OLD is a real directory (pre-swap state)"
    else
        bad "$OLD is missing entirely — stop and look before doing anything"
        return 1
    fi

    # 2. The replacement is really there.
    for f in public/index.php config/routes.php config/container.php composer.lock; do
        if [ -f "$NEW/$f" ]; then ok "monorepo app has $f"
        else bad "monorepo app is MISSING $f"; fi
    done

    # 3. The serving checkout is clean and on main. A dirty tree here means
    #    somebody is mid-something; a swap is not the moment to find out.
    if [ -n "$ROOT" ]; then
        note "git checks skipped (test mode)"
    else
        local branch dirty
        branch="$(git -C "$CHECKOUT" rev-parse --abbrev-ref HEAD 2>/dev/null)"
        dirty="$(git -C "$CHECKOUT" status --porcelain 2>/dev/null)"
        [ "$branch" = "main" ] && ok "serving checkout on main" \
                               || bad "serving checkout on '$branch', expected main"
        [ -z "$dirty" ] && ok "serving checkout is clean" \
                        || bad "serving checkout is DIRTY: $(printf '%s' "$dirty" | head -3 | tr '\n' ' ')"
    fi

    # 4. The lock files match, which is what makes copying vendor/ correct
    #    rather than merely convenient.
    if cmp -s "$OLD/composer.lock" "$NEW/composer.lock"; then
        ok "composer.lock byte-identical — the existing vendor/ is valid for the new tree"
    else
        bad "composer.lock DIFFERS — vendor/ cannot be copied; stop and re-measure"
    fi

    # 5. .env exists with live's posture. Owner and mode are checked, never the
    #    contents — this is a secrets file and nothing here prints it.
    if [ -f "$OLD/.env" ]; then
        local mode owner
        mode="$(stat -c '%a' "$OLD/.env")"
        owner="$(stat -c '%U:%G' "$OLD/.env")"
        if [ "$mode" = "640" ] && [ "$owner" = "www-data:www-data" ]; then
            ok ".env is 0640 www-data:www-data (live posture — carried as-is)"
        else
            bad ".env is $mode $owner, expected 640 www-data:www-data. REFUSING: cp -a would carry a posture nobody chose."
        fi
    else
        bad "$OLD/.env is missing"
    fi

    # 6. LGMS_SYNC_URL must end in /sync-customer, because #181's and #150's
    #    probe URLs are DERIVED from it (EnvSettingsStore::getCheckoutAudienceUrl).
    #    If it does not, the derived URL is '' and checkout answers 503 forever.
    #    Read as the pool user; -q so the value never reaches the terminal.
    if as_pool grep -qE '^LGMS_SYNC_URL=.+/sync-customer[[:space:]]*$' "$OLD/.env" 2>/dev/null; then
        ok "LGMS_SYNC_URL ends in /sync-customer (audience + standing URLs will derive)"
    else
        bad "LGMS_SYNC_URL does NOT end in /sync-customer — the derived probe URLs would be empty and checkout would 503 permanently"
    fi

    # 7. nginx still points where we think. The trailing slash is the #40 fix;
    #    without it every route under /billing/ 404s.
    if grep -qF 'alias /srv/lg-stripe-billing/public/;' "$VHOST" 2>/dev/null; then
        ok "vhost aliases /srv/lg-stripe-billing/public/ (trailing slash present) — no nginx change needed"
    else
        bad "vhost alias is not the expected line — re-read $VHOST before swapping"
    fi

    return $RC
}

# ─────────────────────────────────────────────────────────────────────────────
apply() {
    guard_root
    preflight || { say ""; say "PREFLIGHT FAILED — nothing changed."; exit 1; }

    local ts bak
    ts="$(date +%Y%m%d-%H%M%S)"
    bak="$ROOT/srv/lg-stripe-billing.bak-$ts"

    say ""
    say "APPLY  (backup: $bak)"

    # Everything expensive happens BEFORE the move, so the only time /billing is
    # unavailable is the two commands at the end.
    cp -a "$OLD/vendor" "$NEW/vendor" && ok "vendor/ copied ($(find "$NEW/vendor" -type f | wc -l) files)" \
        || { bad "vendor copy failed — nothing moved yet, safe to retry"; exit 1; }

    install -d -o "$POOL_USER" -g "$POOL_USER" -m 0755 "$NEW/logs" \
        && ok "logs/ created 0755 $POOL_USER:$POOL_USER" \
        || { bad "could not create logs/ — nothing moved yet"; exit 1; }

    cp -a "$OLD/.env" "$NEW/.env" && ok ".env copied with its 0640 www-data posture" \
        || { bad ".env copy failed — nothing moved yet"; exit 1; }

    # ── the window opens ──
    mv "$OLD" "$bak"       || { bad "mv failed"; exit 1; }
    ln -sfn "$NEW" "$OLD"  || { bad "symlink failed — RESTORE NOW: mv $bak $OLD"; exit 1; }
    # ── the window closes ──
    ok "swapped: $OLD -> $(readlink "$OLD")"
    ok "old tree MOVED (not deleted) to $bak"

    install -d "$(dirname "$STATE")" 2>/dev/null
    printf '%s\n' "$bak" > "$STATE"

    if [ -n "$ROOT" ]; then
        note "FPM reload skipped (test mode)"
    else
        systemctl reload php8.3-fpm && ok "php8.3-fpm reloaded (opcache/realpath — without this you are still running the old bytes)" \
            || bad "FPM reload FAILED — live may still be serving the old tree"
    fi

    say ""
    say "Now run:  sudo $0 --verify"
    return $RC
}

# ─────────────────────────────────────────────────────────────────────────────
probe() { # probe <method> <path> [data]  -> "<code> <body>"
    local m="$1" p="$2" d="${3:-}"
    if [ -n "$d" ]; then
        curl -sk -X "$m" -H "Host: $PUBLIC_HOST" -H 'Content-Type: application/json' \
             -d "$d" -w '\n%{http_code}' --max-time 15 "https://127.0.0.1$p" 2>/dev/null
    else
        curl -sk -X "$m" -H "Host: $PUBLIC_HOST" -w '\n%{http_code}' --max-time 15 "https://127.0.0.1$p" 2>/dev/null
    fi
}
code_of() { printf '%s' "$1" | tail -1; }
body_of() { printf '%s' "$1" | sed '$d'; }

verify() {
    say "VERIFY BATTERY"
    [ -n "$ROOT" ] && { note "test mode — HTTP battery not run"; return 0; }
    guard_box

    # V1 — the swap itself.
    local target; target="$(readlink -f "$OLD" 2>/dev/null)"
    [ "$target" = "$(readlink -f "$NEW")" ] && ok "V1 $OLD resolves to the monorepo app" \
                                            || bad "V1 $OLD resolves to '$target', expected $NEW"

    # V2-V4 — it serves.
    local r c b
    for p in /billing/health /billing/v1/products /billing/v1/config; do
        r="$(probe GET "$p")"; c="$(code_of "$r")"
        [ "$c" = "200" ] && ok "V2-4 $p -> 200" || bad "V2-4 $p -> $c (expected 200)"
    done
    r="$(probe GET /billing/v1/products)"
    [ -n "$(body_of "$r")" ] && ok "V3 products body is non-empty" || bad "V3 products body is EMPTY"

    # V5 — the guard, and the proof that the NEW BYTES are running.
    #
    # ⚠️ THE PRICE ID MATTERS. CheckoutController checks "price_id is required" at
    # :94 and only reaches the audience guard at :124, so a probe with NO price id
    # returns 400 on BOTH trees and proves nothing whatsoever. (An earlier draft of
    # this battery made exactly that mistake and would have false-RED'd a healthy
    # swap.) The probe therefore carries a well-formed but unmapped price id.
    #
    # THE DISCRIMINATOR IS THE `audience` KEY, not the status code. Only the new
    # tree emits it, and it emits it in BOTH refusal states — so this one check
    # proves the swap took AND that opcache/realpath picked it up, without
    # depending on how the audience happens to be configured. Measured both sides
    # 2026-08-22:
    #   old tree (live)  -> 400 "Price ... is not mapped to a membership tier."  NO audience key
    #   new tree (dev2)  -> 403 {"...","audience":"allowlist"}
    #
    # Skipped unless V1 passed: on the pre-swap tree there is no guard at all, and
    # a battery should not be poking the checkout door of an app it just proved is
    # the wrong one.
    if [ "$target" != "$(readlink -f "$NEW")" ]; then
        note "V5 SKIPPED — $OLD is not the monorepo app yet (see V1). Nothing to assert about the guard."
    else
    r="$(probe POST /billing/v1/checkout "{\"price_id\":\"$PROBE_PRICE\",\"email\":\"$PROBE_EMAIL\"}")"
    c="$(code_of "$r")"; b="$(body_of "$r")"
    if ! printf '%s' "$b" | grep -qF '"audience"'; then
        bad "V5 checkout -> $c with NO audience key. The OLD tree is still answering — the swap or the FPM reload did not take. ($b)"
    else
        case "$c" in
            503)
                if printf '%s' "$b" | grep -qF "$SENTENCE_503"; then
                    ok "V5 checkout -> 503 with the UNKNOWN sentence (EXPECTED TODAY: lgms_shared_secret is unset)"
                    note "V5 follow-up: set lgms_shared_secret WP-side to match .env, then this becomes 403."
                else
                    bad "V5 checkout -> 503 but NOT the UNKNOWN sentence: $b"
                fi ;;
            403)
                if printf '%s' "$b" | grep -qF "$SENTENCE_403"; then
                    ok "V5 checkout -> 403 with the #181 tester sentence (the guard is fully wired)"
                else
                    bad "V5 checkout -> 403 but NOT the #181 sentence: $b"
                fi ;;
            *)
                bad "V5 checkout -> $c, expected 503 (today) or 403 (once armed): $b" ;;
        esac
    fi
    fi

    # V6 — the webhook door Stripe's signature test knocks on.
    r="$(probe POST /billing/v1/webhook '{}')"; c="$(code_of "$r")"; b="$(body_of "$r")"
    if [ "$c" = "400" ] && printf '%s' "$b" | grep -qiF 'signature'; then
        ok "V6 webhook (unsigned) -> 400 invalid-signature — reachable, and refusing for the right reason"
    else
        bad "V6 webhook (unsigned) -> $c ($b). 401 = an auth wall in front of it; 404 = routing."
    fi

    # V8 — LIVENESS. Without this, V2-V6 all pass on a box that cannot log at
    # all, and the first thing that tries to log 500s. An absence assertion is
    # vacuous without proof the machinery is live.
    if [ -f "$NEW/logs/app.log" ]; then
        if as_pool test -w "$NEW/logs/app.log"; then
            ok "V8 logs/app.log exists and is writable by $POOL_USER"
        else
            bad "V8 logs/app.log is NOT writable by $POOL_USER — the app will 500 on its first log write"
        fi
    elif as_pool test -w "$NEW/logs"; then
        ok "V8 logs/ is writable by $POOL_USER (no app.log written yet)"
    else
        bad "V8 logs/ is not writable by $POOL_USER — the app will 500 on its first log write"
    fi

    say ""
    [ $RC -eq 0 ] && say "BATTERY GREEN" || say "BATTERY RED — see FAIL lines above."
    return $RC
}

# ─────────────────────────────────────────────────────────────────────────────
rollback() {
    guard_root
    guard_box
    local bak
    bak="$(cat "$STATE" 2>/dev/null)"
    [ -n "$bak" ] || { say "No recorded backup in $STATE. Pass the .bak- dir by hand after looking at /srv."; exit 1; }
    [ -d "$bak" ] || { say "Recorded backup '$bak' is not a directory. Stop and look."; exit 1; }

    # The whole safety of this script: it can only ever remove a SYMLINK.
    if [ -L "$OLD" ]; then
        rm -f "$OLD" && ok "removed the symlink"
    elif [ -e "$OLD" ]; then
        say "$OLD is NOT a symlink — refusing to touch it. Nothing done."
        exit 1
    fi

    mv "$bak" "$OLD" && ok "restored $OLD from $bak" || { bad "restore FAILED"; exit 1; }
    if [ -n "$ROOT" ]; then note "FPM reload skipped (test mode)"
    else systemctl reload php8.3-fpm && ok "php8.3-fpm reloaded" || bad "FPM reload failed"; fi
    rm -f "$STATE"
    say ""
    say "Rolled back. Re-run --verify: V5 should be 400 'price_id is required' again (the old tree's answer)."
    return $RC
}

case "$MODE" in
    check)    preflight; [ $RC -eq 0 ] && say "" && say "PREFLIGHT GREEN — safe to --apply." || { say ""; say "PREFLIGHT RED — do not --apply."; } ;;
    apply)    apply ;;
    verify)   verify ;;
    rollback) rollback ;;
esac
exit $RC
