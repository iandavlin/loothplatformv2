#!/usr/bin/env python3
"""
group-mail-dead-gate — the thing keeping 853 people unmailed is an EMPTY LIST, and
nobody knows it.

WHY THIS GATE EXISTS.

BuddyBoss's group-subscription mailer has two legs (docs/ONE-MAILER-SCOPE.md §3):

  Leg A  new discussion in a group-linked forum  ->  ALL subscribers of that group
  Leg B  group activity update                   ->  ALL subscribers of that group

Leg A is dead. Not by a BuddyBoss setting, not by a flag anyone reviewed, and not for
any reason connected to email: it is dead because lg-discussion-group-gate.php returns
an EMPTY RECIPIENT LIST for every group, and it returns empty because
lg_discussion_group_allow() defaults to []. That default was written for Local Looths
(Ian, 2026-07-28: "design for it, do not build it") and has been silently load-bearing
ever since.

    ⚠️ SO ONE SLUG ADDED TO THAT ALLOW-LIST TURNS ON A MASS MAILER.

Measured on live 2026-08-08, after ruling 5's sweep: the kept groups still hold 3,735
armed type='group' subscriptions across 15 groups, topped by Tri State Looths (NYC) at
853. A lane shipping Local Looths would add its slug to that list for entirely correct
reasons and mail 853 people who never chose a subscription — and every existing gate
would stay green, because they assert what is PRESENT and this is a change in what is
ABSENT. That is Ian's six-miss blind spot exactly.

This gate does not forbid the change. It makes it IMPOSSIBLE TO MAKE BY ACCIDENT: the
suite goes red, and the correct response is to record the decision (ONE-MAILER-SCOPE.md
§4 lists the two coherent options and what each costs), not to edit this file.

    EVERY ABSENCE ASSERTION HERE IS PAIRED WITH A LIVENESS ASSERTION.

"The allow-list is empty" is worthless on its own — it is also true if the gate file was
deleted, if the hook was renamed, or if the suppression `return []` was removed. Each of
those makes group mail LOUDER while making the naive assertion greener. So before
asserting the list is empty, this gate proves the mechanism that consumes it still
exists: the file, the hook on bbp_forum_subscription_user_ids, and the empty-list return.

Exit codes follow run-all.sh: 0 green, 1 RED (real finding), 2 CANNOT RUN (no verdict).
"""

import os
import re
import sys

REPO = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
MU = os.path.join(REPO, "platform", "mu-plugins")

GROUP_GATE = os.path.join(MU, "lg-discussion-group-gate.php")
FOLLOW_DIGEST = os.path.join(MU, "lg-follow-digest.php")

# The filter BuddyBoss passes the group-leg recipient list through. Our gate claims it.
FORUM_SUB_FILTER = "bbp_forum_subscription_user_ids"
# The per-recipient email veto on the REPLY path. lg_fd_suppress_instant owns it, and
# a second claimant could silently undo suppression — that was a live hypothesis for
# the §5 double-send and refuting it cost real time. Encoded so nobody re-runs it.
REPLY_EMAIL_FILTER = "bb_send_forums_subscribed_reply_email_notifications"
REPLY_EMAIL_OWNER = "lg_fd_suppress_instant"
# The per-recipient email veto on the GROUP path. Unclaimed today. If a future lane
# claims it (the durable group-mail kill in ONE-MAILER-SCOPE.md §4), it is member-facing
# and must arrive behind a flag like every other member-facing change.
GROUP_EMAIL_FILTER = "bb_send_subscribed_group_email_notifications"

RED, GREEN, DEAD = [], [], []


def red(msg):
    RED.append(msg)


def ok(msg):
    GREEN.append(msg)


def dead(msg):
    DEAD.append(msg)


def read(path):
    try:
        with open(path, "r", encoding="utf-8") as fh:
            return fh.read()
    except OSError as exc:
        return None


def strip_comments(src):
    """Lex out comments so a filter name QUOTED IN PROSE cannot satisfy a check.

    lg-discussion-unsub.php mentions bb_send_forums_subscribed_reply in a comment and
    hooks nothing; a grep counts it as a claimant and reports a defect that does not
    exist. Same class as the stale-file:line trap — resolve by code, not by text.
    """
    src = re.sub(r"/\*.*?\*/", " ", src, flags=re.S)
    src = re.sub(r"^\s*//.*$", " ", src, flags=re.M)
    src = re.sub(r"^\s*#.*$", " ", src, flags=re.M)
    return src


def php_files():
    try:
        return sorted(
            os.path.join(MU, n) for n in os.listdir(MU) if n.endswith(".php")
        )
    except OSError:
        return []


# ── LIVENESS 1: the consumer of the allow-list exists at all ──────────────────
src = read(GROUP_GATE)
if src is None:
    dead("lg-discussion-group-gate.php is unreadable at %s" % GROUP_GATE)
else:
    code = strip_comments(src)

    if re.search(r"add_filter\(\s*['\"]%s['\"]" % FORUM_SUB_FILTER, code):
        ok("liveness: the group leg is still hooked (%s)" % FORUM_SUB_FILTER)
    else:
        red(
            "lg-discussion-group-gate.php no longer hooks %s. Leg A is UNGATED: a new "
            "discussion in a group forum now mails every subscriber of that group "
            "(853 for Tri State Looths). ONE-MAILER-SCOPE.md §3." % FORUM_SUB_FILTER
        )

    # LIVENESS 2: the suppression branch actually returns an empty list. Without this
    # the allow-list being empty means nothing — the callback could return $user_ids.
    #
    # ⚠️ SCOPED TO THE SUPPRESSION BRANCH, and the first draft was not. A file-wide
    # search for `return [];` passed while the suppression return had been deleted,
    # because lg_discussion_group_types() opens with `if ($groupId < 1) return [];`.
    # The mutation harness caught it staying green — verify the thing, not the thing
    # next to it. The anchor is the suppression log line, which is emitted immediately
    # before the only return that matters.
    log_at = code.find("[lg-discussion-group-gate]")
    branch = code[log_at:] if log_at != -1 else ""
    if log_at == -1:
        red(
            "the suppression branch's log line is gone from lg-discussion-group-gate.php, "
            "so this gate cannot locate the return that keeps leg A dead."
        )
    elif re.search(r"return\s*(\[\s*\]|array\(\s*\))\s*;", branch):
        ok("liveness: the suppression branch still returns an empty recipient list")
    else:
        red(
            "lg-discussion-group-gate.php's suppression branch no longer returns an "
            "empty recipient list. That empty return is what makes "
            "bbp_notify_forum_subscribers() bail before dispatching; without it the "
            "allow-list gates nothing and leg A mails every group subscriber."
        )

    # ── THE TRIPWIRE ──────────────────────────────────────────────────────────
    # Assert the DEFAULT the filter is seeded with, not the filter's absence: a lane
    # may legitimately extend this via add_filter('lg_discussion_group_allow', ...)
    # elsewhere, and that is equally a decision to start mailing. Both are caught —
    # the seed here, and any claimant below.
    m = re.search(
        r"apply_filters\(\s*['\"]lg_discussion_group_allow['\"]\s*,\s*(\[[^\]]*\]|array\([^)]*\))",
        code,
    )
    if not m:
        red(
            "could not find the lg_discussion_group_allow default in "
            "lg-discussion-group-gate.php. This gate cannot vouch for the group-mail "
            "kill switch it exists to watch — treat as a finding, not as noise."
        )
    else:
        seed = m.group(1)
        empty = re.fullmatch(r"\[\s*\]|array\(\s*\)", seed.strip())
        if empty:
            ok("tripwire: lg_discussion_group_allow default is EMPTY — leg A stays dead")
        else:
            red(
                "lg_discussion_group_allow now defaults to %s. THIS TURNS ON A MASS "
                "MAILER: every new discussion in a matching group emails every "
                "subscriber of that group (853 for Tri State Looths, 852 SoCal). "
                "That may well be correct — but it needs a RECORDED DECISION citing "
                "docs/ONE-MAILER-SCOPE.md §4, not a gate edit." % seed
            )

# ── ANY OTHER CLAIMANT ON THE ALLOW-LIST IS THE SAME DECISION ────────────────
claimants = []
for path in php_files():
    if os.path.abspath(path) == os.path.abspath(GROUP_GATE):
        continue
    body = read(path)
    if body is None:
        continue
    if re.search(
        r"add_filter\(\s*['\"]lg_discussion_group_allow['\"]", strip_comments(body)
    ):
        claimants.append(os.path.basename(path))
if claimants:
    red(
        "lg_discussion_group_allow is extended by %s. Same consequence as a non-empty "
        "default — it turns leg A on for whichever group types it admits. Record the "
        "decision (ONE-MAILER-SCOPE.md §4)." % ", ".join(claimants)
    )
else:
    ok("tripwire: nothing else extends lg_discussion_group_allow")

# ── SOLE CLAIMANT ON THE REPLY-SUPPRESSION SEAM ──────────────────────────────
# Encodes a refuted hypothesis so it never has to be refuted again: a second hook here
# at a later priority would silently undo lg_fd_suppress_instant and reinstate the
# double-send in ONE-MAILER-SCOPE.md §5.
hooks = []
for path in php_files():
    body = read(path)
    if body is None:
        continue
    for mm in re.finditer(
        r"add_filter\(\s*['\"]%s['\"]\s*,\s*['\"]?([A-Za-z0-9_\\]+)" % REPLY_EMAIL_FILTER,
        strip_comments(body),
    ):
        hooks.append((os.path.basename(path), mm.group(1)))

if not hooks:
    red(
        "nothing hooks %s. lg_fd_suppress_instant is the ONLY thing standing between a "
        "digest member and BuddyBoss's per-reply mail; without it every batched member "
        "gets instant mail AND a digest." % REPLY_EMAIL_FILTER
    )
elif len(hooks) == 1 and hooks[0][1] == REPLY_EMAIL_OWNER:
    ok("sole claimant on %s is %s" % (REPLY_EMAIL_FILTER, REPLY_EMAIL_OWNER))
else:
    red(
        "%s has %d claimant(s): %s. A second hook can turn a suppressed false back "
        "into true and reinstate the double-send (ONE-MAILER-SCOPE.md §5)."
        % (REPLY_EMAIL_FILTER, len(hooks), ", ".join("%s:%s" % h for h in hooks))
    )

# ── A FUTURE GROUP-MAIL KILL MUST ARRIVE FLAGGED ─────────────────────────────
# Prospective, and deliberately not a requirement that the code exist: this asserts
# the SHAPE of a change that has not been made yet, so it cannot ship unflagged.
group_claimants = []
for path in php_files():
    body = read(path)
    if body is None:
        continue
    code = strip_comments(body)
    if re.search(r"add_filter\(\s*['\"]%s['\"]" % GROUP_EMAIL_FILTER, code):
        flagged = re.search(r"LG_[A-Z0-9_]*GROUP[A-Z0-9_]*", code) or re.search(
            r"lg_[a-z0-9_]*group[a-z0-9_]*_enabled", code
        )
        group_claimants.append((os.path.basename(path), bool(flagged)))

if not group_claimants:
    ok("%s unclaimed — matches ONE-MAILER-SCOPE.md §4" % GROUP_EMAIL_FILTER)
else:
    unflagged = [n for n, f in group_claimants if not f]
    if unflagged:
        red(
            "%s is claimed by %s with no flag constant in the file. Member-facing "
            "changes ship behind a flag defaulted OFF (CLAUDE.md)."
            % (GROUP_EMAIL_FILTER, ", ".join(unflagged))
        )
    else:
        ok(
            "%s claimed by %s, and flagged"
            % (GROUP_EMAIL_FILTER, ", ".join(n for n, _ in group_claimants))
        )

# ── VERDICT ───────────────────────────────────────────────────────────────────
for line in GREEN:
    print("  PASS  %s" % line)
for line in DEAD:
    print("  DEAD  %s" % line)
for line in RED:
    print("  FAIL  %s" % line)

if DEAD and not RED:
    print("group-mail-dead: NO VERDICT (%d probe(s) could not run)" % len(DEAD))
    sys.exit(2)
if RED:
    print("group-mail-dead: RED (%d finding(s))" % len(RED))
    sys.exit(1)
print("group-mail-dead: GREEN (%d assertion(s))" % len(GREEN))
sys.exit(0)
