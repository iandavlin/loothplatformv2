# Apply runbook — the 26 auto-mergeable pairs

For Ian, on live. Covers **only the 26 auto pairs**. The 12 holds are ruled on
separately and every command here excludes them by construction.

Branch `dupe-merge`, SHA `3644369` or later.

---

## 0. Before you start — one hard precondition

**`lg-merged-login-redirect.php` must be live in the same window.**

The merge parks the retired account's address as `merged-<id>@retired.invalid`
so WP's unique-email index frees it. Until the redirect is deployed, a member
who types their old address gets "unknown email" — which reads as *my account is
gone*. Twelve of the 26 survivors are flagged `TWIN-MORE-RECENT`, meaning the
twin is the account that member has been using, so this is not a corner case.

It changes a login path, so it needs `tools/gates/run-all.sh` green and your
approval first.

It lives at `platform/mu-plugins/lg-merged-login-redirect.php`. **A pull alone
will not activate it.** mu-plugins are symlinked into the webroot one file at a
time, so the pull brings the file into the checkout and nothing loads it — the
symlink has to be created in the same window:

```bash
ln -s /home/ubuntu/loothplatformv2-clean/platform/mu-plugins/lg-merged-login-redirect.php \
      /var/www/dev/wp-content/mu-plugins/lg-merged-login-redirect.php
ls -l /var/www/dev/wp-content/mu-plugins/ | wc -l     # expect one more than before
```

**Proved on dev2** against a real merged pair (steve chapman, 227 → 182), with
the plugin loaded and a known password set on the survivor:

| check | result |
|---|---|
| WP alone finds the parked old address | no — correctly absent |
| old address + **survivor's** password | signs in as the survivor |
| old address + wrong password | `lg_merged_account`, names the survivor masked (`s***********d@gmail.com`) |
| parked `merged-<id>@retired.invalid` as a login | rejected — the retired account is never a login target |
| an unrelated address | untouched by the filter |

Afterwards the merge was rolled back, the survivor's password hash restored
(verified byte-identical to live) and the symlink removed.

Two more things to know before the first write:

- **Do not use `~/loothplatformv2-clean`.** It only ever pulls, and this is a
  branch. Use a separate checkout.
- The banner will say `env=dev2`. That is correct on the prod-at-cut box and
  does not mean it is pointed at dev — the data is live.

---

## 1. Get the code

```bash
git -C <checkout> fetch origin
git -C <checkout> checkout dupe-merge
git -C <checkout> rev-parse --short HEAD      # expect 3644369 or later
cd <checkout>
```

## 2. Rehearse — writes nothing

```bash
sudo tools/dupe-merge/apply-auto.sh --dry-run
```

Expected shape (dev2 rehearsal figures; live will differ slightly because the
snapshots differ, and that is fine):

```
26 pair(s): 26 auto, 0 held
would move: 14 forum/other posts, 760 other wp rows, 19 profile_app rows,
            8 mirror rows, 40 connections
would drop 89 duplicate connection(s). NOTHING WAS WRITTEN.
```

**Sanity check before proceeding:** it must say `26 auto, 0 held`. If any number
of held pairs appears, stop — the selection is wrong.

Note the auto batch moves only **14 posts**. The content-heavy merges (David
Trustman 81, Michael Minton 27) are all in the held set, which is why this batch
is the low-risk half.

To see the whole 38-pair picture instead: `sudo tools/dupe-merge/run-as-root.sh --dry-run`

## 3. Apply

```bash
sudo tools/dupe-merge/apply-auto.sh
```

It walks the 26 pairs one at a time and for each one:

1. re-derives the survivor rule **from the live database** and refuses if it has
   drifted or if the Patreon linkage is crossed,
2. writes and fsyncs the journal **before** the first write,
3. applies `looth_import`, then `profile_app`, then the forum mirror, each in
   its own transaction,
4. runs `--verify` immediately and **stops the batch** if it is not OK.

It stops at the first failure and leaves what it has already done in place,
because the manifest is what makes those merges reversible.

### Per-pair instead, if you prefer to go one at a time

```bash
sudo tools/dupe-merge/run-as-root.sh --apply  --pair="jake tuel"
sudo tools/dupe-merge/run-as-root.sh --verify --pair="jake tuel"
```

`--pair` matches on a substring of the name, and the tool refuses if the string
matches more than one pair.

## 4. Journals — where they are

```
tools/dupe-merge/journal/
  APPLIED-<timestamp>.tsv                    the batch manifest: pair -> journal
  <pair>-<twin>-into-<survivor>-<stamp>.json one per applied pair
```

Owned by `postgres`, mode 0750, and **git-ignored** — they contain member email
addresses and every prior value. They are the only thing that makes the merge
reversible: do not delete them until you are certain you will not roll back.

`user_pass` is never modified, so no password hash is written to a journal.

## 5. Verify

The batch verifies each pair as it goes. To re-check afterwards:

```bash
sudo tools/dupe-merge/run-as-root.sh --verify --pair="jake tuel"
```

`VERIFY OK` means: the twin owns no rows in any remapped table, the survivor's
email is the winning Patreon email, and the twin is archived in `profile_app`.

Whole-batch state check — after applying all 26 you should see 26 everywhere:

```bash
mysql looth_import -e "SELECT COUNT(*) FROM wp_users WHERE user_email LIKE 'merged-%@retired.invalid';"
mysql looth_import -e "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='lg_merged_into';"
sudo -u postgres psql -d profile_app -tAc "SELECT count(*) FROM users WHERE primary_email LIKE 'merged-%';"
```

## 6. Rollback

**Whole batch, in reverse order:**

```bash
sudo tools/dupe-merge/rollback-auto.sh tools/dupe-merge/journal/APPLIED-<timestamp>.tsv
```

**One pair** — use the journal path the tool printed:

```bash
sudo tools/dupe-merge/run-as-root.sh --rollback --journal=tools/dupe-merge/journal/<that-file>.json
```

> **Never pick a journal with `ls | tail -1`.** Journals are named per pair, so
> that sorts alphabetically rather than by time and rolls back the wrong merge.
> That mistake happened during rehearsal and cost a repair-from-live.

Rollback restores post authors, emails, capabilities, connections created,
deleted and status-upgraded, deleted notifications (including ones destroyed by
the `notifications → connections` cascade), the mirror's author fields, and the
twin's archive state. It is **tolerant but loud**: if a row is already absent it
says so and continues, which is what undoing a part-way batch requires.

Afterwards all three counts in §5 should be back to `0`.

## 7. If something goes wrong

| symptom | what it means | do this |
|---|---|---|
| `REFUSING … is HELD` | you named a held pair | leave it; holds are ruled separately |
| `REFUSING … failed preflight` | the survivor's email drifted, or the Patreon linkage is crossed | **stop.** Re-run the dry-run; the plan no longer matches the database |
| `REFUSING … Patreon linkage could not be checked` | `lg_membership` unreachable | do not waive on live — it is reachable there; investigate |
| `expected to rewrite 1 row … matched none` | a row vanished since the plan was built | the batch already stopped; roll back and re-run the dry-run |
| batch stops mid-way | that pair failed | earlier pairs are applied and listed in the manifest; roll the batch back with §6 |

The forward path is deliberately strict — it aborts rather than report a merge
that did not fully happen.

## 8. Rehearsal evidence

On dev2, against a copy of live:

- all **38** pairs individually: merge → verify → rollback → **byte-identical**
  restore (fingerprint of every affected row, not row counts),
- all **26** auto pairs as one batch through these exact scripts: applied,
  verified 26/26, rolled back 26/26, box returned to baseline and the dry-run
  totals reproduced identically.

## 9. After the merge lands

The poller still holds `lg_patreon_members` rows for the retired twins. Until
the `poller-patreon-id` lane ships, a later sync could act on a retired account.
Each row is recorded in the journal; this tool does not modify it.
