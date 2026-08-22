#!/usr/bin/env bash
# lanes-poke-install — the deploy steps a `git pull` cannot do, in the repo so
# they are traceable to a commit instead of being remembered (#156, #202).
#
#   bash tools/lanes-poke-install.sh            # the part ubuntu can do
#   sudo bash tools/lanes-poke-install.sh       # everything, including systemd
#   bash tools/lanes-poke-install.sh --verify   # measure, change nothing
#
# Idempotent. Safe to re-run after every deploy.
#
# ⚠️ WHY --verify EXISTS, AND WHY IT IS NOT A GATE. On 2026-08-22 this script's
# systemd half had never been run on dev2: no lanes-poke.path, no
# lanes-poke.service, spool 0 bytes, ~/.keeper-pokes absent. So a tap on "Poke
# keeper" validated its nonce, appended to a spool nothing drained, and printed
# "keeper told ✓" at Ian. The code was right the whole time; the box was not.
#
# That failure is invisible to any gate worth running: a gate that goes RED
# because a box lacks a systemd unit blocks every lane on that box for somebody
# else's install. So the liveness check lives HERE, where it is a deploy tool
# and not a merge blocker, and gate 77 asserts the behaviour with injected
# paths instead. Run --verify after a deploy; read what it prints.
set -euo pipefail

SPOOL=/home/ubuntu/.lanes-poke-request
STAMPS=/home/ubuntu/.lanes-poke
STORE=/home/ubuntu/.lg-decisions
REPO=/home/ubuntu/loothplatformv2-clean

# ── --verify: measure, never change ─────────────────────────────────────────
if [ "${1:-}" = "--verify" ]; then
    rc=0
    say() { printf '%-34s %s\n' "$1" "$2"; }
    chk() { if [ "$2" = "$3" ]; then say "$1" "OK  ($2)"; else say "$1" "MISSING  (got '$2', want '$3')"; rc=1; fi; }

    chk "spool exists"        "$([ -f $SPOOL ] && echo yes || echo no)"        yes
    chk "spool mode"          "$(stat -c %a $SPOOL 2>/dev/null || echo -)"     666
    chk "stamps dir"          "$([ -d $STAMPS ] && echo yes || echo no)"       yes
    chk "stamps mode"         "$(stat -c %a $STAMPS 2>/dev/null || echo -)"    777
    chk "question store"      "$([ -d $STORE ] && echo yes || echo no)"        yes
    chk "question store mode" "$(stat -c %a $STORE 2>/dev/null || echo -)"     755
    chk "store owner"         "$(stat -c %U $STORE 2>/dev/null || echo -)"     ubuntu
    chk "lanes-poke.path"     "$(systemctl is-active lanes-poke.path 2>/dev/null || echo inactive)" active
    chk "lanes-poke.path boot" "$(systemctl is-enabled lanes-poke.path 2>/dev/null || echo no)"     enabled
    for f in lanes-poke.php lanes-decisions.php lanes-decide.php; do
        chk "docroot $f" "$([ -e /var/www/dev/$f ] && echo yes || echo no)" yes
    done
    # ⚠ The one check that is about REACHABILITY rather than presence: the web
    # user must be able to READ the store. Presence is not reachability — the
    # store being there proves nothing about whether php can open it.
    chk "web user can read store" \
        "$(sudo -n -u looth-dev test -r $STORE 2>/dev/null && echo yes || echo no)" yes
    # ⚠ PAIRED WITH A LIVENESS CHECK, because "the web user cannot write it" is
    # trivially true of a box with no store at all — it read OK against a
    # missing directory for exactly one revision. An absence assertion with no
    # liveness assertion beside it is a green light for the broken state.
    if [ -d $STORE ]; then
        chk "web user CANNOT write it" \
            "$(sudo -n -u looth-dev test -w $STORE 2>/dev/null && echo yes || echo no)" no
    else
        say "web user CANNOT write it" "n/a — no store to test against"
        rc=1
    fi
    echo
    [ $rc -eq 0 ] && echo "decision box + poke: the whole chain is installed" \
                  || echo "SOMETHING ABOVE IS MISSING — a button that queues into a gap prints success at Ian"
    exit $rc
fi

# 1. the spool and the debounce stamps. The web user (looth-dev) can traverse
#    /home/ubuntu but cannot write it, so both must exist ALREADY and be
#    world-writable — the endpoint refuses loudly when they are not, because an
#    un-debounced poke button is a board flood.
[ -e "$SPOOL" ] || : > "$SPOOL"
chmod 0666 "$SPOOL"
mkdir -p "$STAMPS"
chmod 0777 "$STAMPS"
# #202: the pending-question store. 0755 and NOT world-writable — the web user
# must read the questions it renders and must never be able to author one.
# Measured rather than assumed: /home/ubuntu is drwxr-x--x so looth-dev can
# traverse to it, `sudo -u looth-dev cat` succeeds inside, and
# `sudo -u looth-dev touch` is refused.
mkdir -p "$STORE"
chmod 0755 "$STORE"
echo "spool:  $SPOOL  ($(stat -c %a "$SPOOL"))"
echo "stamps: $STAMPS  ($(stat -c %a "$STAMPS"))"
echo "store:  $STORE  ($(stat -c %a "$STORE"))"

if [ "$(id -u)" -ne 0 ]; then
    echo
    echo "NOT ROOT — these two steps still need doing, by root:"
    echo "  sudo $REPO/webroot/install-symlinks.sh --new-only   # /var/www/dev/lanes-poke.php"
    echo "  sudo systemctl enable --now lanes-poke.path         # after linking the unit files"
    echo
    echo "then measure it, rather than assuming it:"
    echo "  bash tools/lanes-poke-install.sh --verify"
    exit 0
fi

# 2. the unit files, linked out of the serving checkout like every other unit
for u in lanes-poke.path lanes-poke.service; do
    ln -sf "$REPO/platform/systemd/$u" "/etc/systemd/system/$u"
done
systemctl daemon-reload
systemctl enable --now lanes-poke.path
systemctl is-active lanes-poke.path

# 3. the docroot symlink for the endpoint
"$REPO/webroot/install-symlinks.sh" --new-only >/dev/null
ls -l /var/www/dev/lanes-poke.php /var/www/dev/lanes-decisions.php \
      /var/www/dev/lanes-decide.php
