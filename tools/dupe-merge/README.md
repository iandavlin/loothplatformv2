# Duplicate-account merge

Repairs members who ended up with two WP accounts. Ian runs the live commands;
everything here has been proved end-to-end on dev2 first.

## Why the duplicates exist

Login is **email + password**, and a member's WP email must equal their
**current Patreon email** — that address is both the login credential and the
Patreon-verification key. When the old provisioner could not match a patron's
Patreon email to an existing account, it minted a new account carrying that
email instead of updating the one already there. One member, two accounts, one
per address.

## Survivor rule

The survivor is the account that **can actually log in and Patreon-verify** —
the one whose `user_email` equals its live Patreon email. Not the one with more
content, and not the one used most recently.

| | condition | outcome |
|---|---|---|
| 1 | exactly one side's email matches its Patreon email | that side survives |
| 2 | both match — the member holds **two** Patreon accounts | the `active_patron` survives; if **both** are active it is **HELD** |
| 3 | neither matches | **HELD** |

Patreon email is read from `lg_membership.lg_patreon_members` (the live poller
record), falling back to the `lgpo_patreon_email` meta and then to the
`patreon_latest_patron_info` blob.

**In all 38 pairs the survivor's `user_email` already equals the winning Patreon
email**, so no live email is rewritten — the rule picks the matching account by
construction. The merge frees the twin's address rather than moving it.

A fourth outcome comes from the poller audit: where the poller row for either
account describes a **different Patreon identity** than the account itself
claims, the email match proves only that the member controls the address — not
that this account's own Patreon identity is current. Those are **held**, and
the tool refuses them at apply time regardless of `--force-hold`.

The decisions live in `pairs.json` as reviewable data, not in code.

## Preflight

`--apply` re-derives the survivor rule from the database immediately before
writing, because `pairs.json` was computed earlier and can drift. Its three
failures take three different overrides, so waiving one never silently waives
another:

| override | waives |
|---|---|
| `--force-hold` | the pair is flagged for a human; you are that human |
| `--force-preflight` | a crossed Patreon linkage, or the survivor's email has drifted from the plan |
| `--skip-poller-check` | `lg_membership` is unreachable (a box without the secret) |

Credentials for `lg_membership` come from `/etc/lg-poller-db`, falling back to
the `lgms_db_*` `wp_options` rows — the same order `membership-pages/config.php`
uses.

## What a merge does

Everything the twin owns moves to the survivor. A row that would collide with
one the survivor already has is deleted **after its full contents are recorded**,
so a rollback recreates it.

- **`looth_import`** — 26 measured tables incl. `wp_posts.post_author`,
  `wp_comments`, `wp_bp_activity`, `wp_bp_friends`, reactions, groups, xprofile.
- **`profile_app`** — connections, messages, message recipients/threads,
  reactions, email aliases, profile rows; the twin's `users` row is archived and
  its email parked.
- **`looth` (forum mirror)** — `forums.reply` / `forums.topic` author fields and
  the `discovery.*` tables.
- **`lg_membership`** — read only, copied into the journal.

The twin is **retired, never deleted**: archived in `profile_app`, email parked
as `merged-<id>@retired.invalid`, capabilities stripped, and `lg_merged_into` /
`lg_prior_email` markers left behind.

## Commands

**To apply on live, follow `APPLY-RUNBOOK.md`** — it covers the 26 auto pairs
end to end. These are the underlying commands.

```bash
# read-only, writes nothing
sudo tools/dupe-merge/run-as-root.sh --dry-run                      # all 38
sudo tools/dupe-merge/run-as-root.sh --dry-run --auto               # the 26 only
sudo tools/dupe-merge/run-as-root.sh --dry-run --auto --list        # their names
sudo tools/dupe-merge/run-as-root.sh --dry-run --pair="jake tuel" --verbose

# one pair at a time; refuses a HELD pair without --force-hold
sudo tools/dupe-merge/run-as-root.sh --apply --pair="jake tuel"
sudo tools/dupe-merge/run-as-root.sh --verify --pair="jake tuel"
sudo tools/dupe-merge/run-as-root.sh --rollback --journal=<file>

# the 26 auto pairs as one batch: apply + verify each, stop on first failure
sudo tools/dupe-merge/apply-auto.sh --dry-run
sudo tools/dupe-merge/apply-auto.sh
sudo tools/dupe-merge/rollback-auto.sh tools/dupe-merge/journal/APPLIED-<stamp>.tsv

# merge -> verify -> rollback -> byte-compare, on a copy
sudo tools/dupe-merge/prove.sh "jake tuel"
```

`run-as-root.sh` exists because Postgres here is peer-auth and no single role
owns both `profile_app` and `looth`, so the tool runs as `postgres` — which
cannot read `wp-config.php`. The wrapper reads the MySQL credentials as root and
passes them through the environment.

## Rollback

Four databases, no distributed transaction. The journal is written **and
fsynced before the first write**, and holds every prior value: post authors,
emails, capabilities, connection rows created/deleted/status-upgraded, deleted
notifications, mirror author fields, and the twin's archive state. Each store
commits in its own transaction; if a later one fails, the journal still
describes what was done and `--rollback` undoes it.

Forward is **strict** — a move that matches no row aborts rather than reporting
a merge that did not happen. Rollback is **tolerant but loud** — it says so and
continues, because it may be undoing a run that failed part-way.

`user_pass` is never modified, so no password hash is written to the journal.

## Login for the retired address

`lg-merged-login-redirect.php` (an mu-plugin, **not yet deployed**) makes the old
address sign the member into the survivor when they present the survivor's
password, and otherwise names the address to use instead of failing blankly. It
also points password resets at the survivor.

It is a change to a login path: it needs `tools/gates/run-all.sh`, Ian's
approval, and it must ship **in the same window as the merge** — until it is
live, the parked address simply fails.

## Traps this tool already handles

Each one was found by measurement or caught by the dev2 proof.

1. **`connections` UNIQUE is directional** — `(requester, addressee)`. The
   constraint alone does not stop a merge duplicating a relationship in the
   opposite direction. Both shapes are detected; 4 reversed duplicates exist.
2. **`notifications → connections` is ON DELETE CASCADE.** Deleting a duplicate
   connection silently destroys other members' notifications. Those rows are
   captured so rollback restores them. This is what made the first proof fail.
3. **The forum mirror will not re-sync an author change.** `reconcile.php` only
   revisits rows whose `post_modified_gmt` moved, so changing `post_author`
   alone leaves the public forum crediting the twin forever. The mirror is
   written directly, including the denormalised `author_name` / `author_slug`.
4. **The mirror's prior author names must be recorded too** — the apply rewrites
   them for rows the survivor already owned, and a rollback that ignored them
   left the survivor's own history renamed.
5. **`actor_key` is a GENERATED column** derived from `user_uuid`, and part of a
   unique index. It cannot be written, but collisions must be tested against its
   post-rewrite value. Generated/identity columns are read from the catalog, and
   identity columns are re-inserted with `OVERRIDING SYSTEM VALUE`.
6. **PDO/pgsql rejects PHP `false`** as a boolean bind. Rows are matched on their
   unique-key columns, never on every column.
7. **The twin's own notifications are deleted, not moved** — they sit behind
   three *partial* unique indexes and are per-account unread badges the survivor
   gains nothing from. Recorded in full.
8. **BuddyBoss is frozen legacy.** `wp_bp_friends` / `wp_bp_messages_*` stopped
   at the 2026-06-01 cutover to `profile_app`; they are still remapped so the
   archive stays coherent, but they are not the live social graph.

## Proof

`prove.sh` fingerprints every row of every affected table for both accounts
(hashed contents, not counts), merges, checks the twin is drained and the
survivor holds both halves, rolls back, and re-fingerprints — failing unless the
restore is byte-identical.

**All 38 pairs pass on dev2.** The 26 auto pairs also pass as one batch through
`apply-auto.sh` / `rollback-auto.sh`: applied and verified 26/26, rolled back
26/26, box back to baseline with the dry-run totals reproduced identically.

Use the journal path the tool prints. Do not pick one with `ls | tail -1` — the
journals are named per pair, so that selects alphabetically, not the most
recent, and rolls back the wrong merge. That mistake cost a manual test run
here and left a pair merged until it was repaired from live.

## Cross-check against POLLER-ONBOARDING-AUDIT §8

All 14 accounts the audit names are in this population. The 11 skeleton accounts
carrying another account's Patreon id (#84, 195, 399, 471, 505, 615, 676, 1154,
1407, 1516, 1520) are all on the **retired** side, so a crossed id never elects
a survivor. The 3 partner accounts whose id meta disagrees with their own
`patreon_<id>` username (#1574 derek taylor, #1333 kurt smith, #1690 vincent
jaeger) are all **survivors**, and all three are now held.

The sharpest of them is vincent jaeger: #1690 is `patreon_196135453` and its own
patron blob gives `vjaeger@jaegerguitarrepair.com`, but its poller row describes
patreon id `185178224` — the twin's — with `vjaeger2011@my.fit.edu`, which is
what its WP email matches. Under a strict per-account reading its email does not
match its own Patreon account at all.

## Known gap

The poller still holds `lg_patreon_members` rows for retired twins. Until the
`poller-patreon-id` lane lands, a later sync could act on a retired account. The
row is recorded in the journal; the merge does not modify it.
