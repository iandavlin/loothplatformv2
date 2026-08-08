# Email terminology — pinned by Ian, 2026-08-08

Three member-facing email things exist. They have exactly these names. The word
"digest" is RESERVED for the first one — using it for anything else has already
caused one real miscommunication (8/1) and one week of muddled diagnosis.

| Term | What it is | Engine |
|---|---|---|
| **Weekly Digest** | Ian's editorial email to the subscriber list (~1,860/send). The ONLY thing called "digest". | FluentCRM campaign, composed + sent by Ian |
| **Weekly Recap** | Not a separate email — the personalized "what you missed" SECTION inside the Weekly Digest (`##lg_recap.section##` smartcode). | Rendered per-recipient at send time |
| **Follow roundup** | The batched follow-notification email: "N new replies in a discussion you follow". Cadences: **instant** (per-reply, currently BB's native mail passed through), **daily roundup**, **weekly roundup**. | `platform/mu-plugins/lg-follow-digest.php`, our systemd timer |

Usage rules:

- "digest" unqualified ⇒ the Weekly Digest. Never the roundup.
- The roundup at cadence: "daily roundup" / "weekly roundup" / "instant follow
  notification". Member-facing copy already avoids "digest" — keep it that way.
- CODE KEEPS ITS NAMES. `lg-follow-digest.php`, `lg_fd_*`, the `follow-digest`
  branch and gate names stay — renaming working code is churn for zero member
  value. The glossary governs docs, charters, commit messages, and conversation.
- The bell (on-site notifications, `profile_app.notifications`) is a fourth thing
  and is never called email anything.

History that made this necessary: keeper called the roundup "the follow-digest"
everywhere, Ian reasonably read "digest" as the Weekly Digest, and on 8/1 the two
projects were briefly treated as one. Distinct names are cheaper than one more
hour of that.
