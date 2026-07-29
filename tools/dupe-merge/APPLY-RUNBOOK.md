# Apply runbook — the 29 pairs cleared to merge

For Ian, on live. Covers **only the 29 pairs cleared for merge**. The other 9
are excluded by construction — no flag in this runbook can reach them.

Ian ruled the 12 holds on 2026-07-29. That split them three ways:

| | | |
|---|---|---|
| **29 merge** | the original 26 auto + **jim bonnell**, **david trustman/donnercruz**, **john sarlo** | this runbook |
| **5 hold** | ira cox (contact member first); michael minton, derek taylor, kurt smith, vincent jaeger (lane investigating) | not here |
| **4 excluded** | andrew mcneill, don goulart, patrick morrissey, charles fox — *no action, ever* | refused outright |

`EXCLUDED` has **no override**. `--force-hold` and `--force-preflight` will not
apply one; only editing `pairs.json` can, which takes a human decision. Each
pair carries Ian's ruling as text and the dry-run prints it.

Branch `dupe-merge`, SHA `4d00522` or later.

---

## 0. Before you start — one hard precondition

**`lg-merged-login-redirect.php` must be live in the same window.**

The merge parks the retired account's address as `merged-<id>@retired.invalid`
so WP's unique-email index frees it. Until the redirect is deployed, a member
who types their old address gets "unknown email" — which reads as *my account is
gone*. Several of the 29 survivors are flagged `TWIN-MORE-RECENT`, meaning the
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

## 0b. One pair in the 29 needs a billing decision first — **jim bonnell**

Investigating the roster (which Ian asked for) turned this up after the rulings
were made, so it is new information on a pair already cleared to merge.

**Both sides are a live active patron, on two different plans:**

| | account | patreon id | pledge | next charge |
|---|---|---|---|---|
| survivor | 1467 `jimbonnell@gmail.com` | 31057830 | **$11.00/mo** Looth-Pro | 2026-08-02 |
| retire | 1470 `jbonnell@tampabay.rr.com` | 260787 | **$132.00/yr** Ding King Plus | 2026-11-02 |

**Merging does not cancel a pledge.** Retiring 1470 leaves that annual charge
running against an account that no longer resolves — he keeps paying $132/yr for
an entitlement that has nowhere to land. That is the same situation you ruled
*contact-first* for ira cox; this one was labelled only `BOTH-ACTIVE-PATRON`
without the payment detail behind it.

**Recommendation: pull it from tonight and treat it like ira cox.** One edit:

```bash
python3 - <<'PY'
import json; P='tools/dupe-merge/pairs.json'; ps=json.load(open(P))
for p in ps:
    if p['name']=='jim bonnell': p['action']='HOLD'; p['hold']=['CONTACT-MEMBER-FIRST-DOUBLE-PLEDGE']
json.dump(ps,open(P,'w'),indent=1)
PY
sudo tools/dupe-merge/apply-auto.sh --dry-run    # now says 28 auto
```

If you would rather merge anyway, nothing is needed — it is already in the set,
and the dry-run prints the warning against the pair so it cannot pass unseen.

The other **28** pairs are clear: the retiring account has no live pledge in any
of them. Verified against the roster, whose newest sync is hours old.

---

## 1. Get the code

```bash
git -C <checkout> fetch origin
git -C <checkout> checkout dupe-merge
git -C <checkout> rev-parse --short HEAD      # expect 4d00522 or later
cd <checkout>
```

## 2. Rehearse — writes nothing

```bash
sudo tools/dupe-merge/apply-auto.sh --dry-run
```

Expected shape (dev2 rehearsal figures; live will differ slightly because the
snapshots differ, and that is fine):

```
29 pair(s): 29 auto, 0 held
would move: 96 forum/other posts, 1069 other wp rows, 19 profile_app rows,
            36 mirror rows, 40 connections
would drop 92 duplicate connection(s). NOTHING WAS WRITTEN.
```

**Sanity check before proceeding:** it must say `29 auto, 0 held`. If a held
count appears, stop — the selection is wrong.

The jump from 14 posts to 96 is David Trustman's 81-post reattach, which Ian
cleared. Michael Minton's 27 are *not* in here — that pair is still held.

To see the whole 38-pair picture instead: `sudo tools/dupe-merge/run-as-root.sh --dry-run`

## 3. Apply

```bash
sudo tools/dupe-merge/apply-auto.sh
```

It walks the 29 pairs one at a time and for each one:

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

Whole-batch state check — after applying all 29 you should see 29 everywhere:

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
| `REFUSING … is EXCLUDED` | Ian ruled that pair off the list entirely | leave it; no flag overrides this |
| `REFUSING … is HELD` | you named a held pair | leave it; those are still open |
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
- the **29** cleared pairs as one batch through these exact scripts: applied and
  verified 29/29, rolled back 29/29, box returned to baseline,
- during that batch the four excluded twins (338, 799, 814, 1129) were confirmed
  **untouched**.

## 9. After the merge lands

The poller still holds `lg_patreon_members` rows for the retired twins. Until
the `poller-patreon-id` lane ships, a later sync could act on a retired account.
Each row is recorded in the journal; this tool does not modify it.

## 10. The 9 pairs NOT in this batch

Held — still open:

- **derek taylor** (1574 / 676) — POLLER-ID-CROSSED: untangle which Patreon identity is the live pledge (roster + payment history), then recommend with proof.
- **ira cox** (895 / 566) — double pledge + survivor is an Apple relay address; contact the member first. Verify the twin active_patron is current roster truth before telling him he pays twice.
- **kurt smith** (1333 / 1154) — POLLER-ID-CROSSED: untangle which Patreon identity is the live pledge (roster + payment history), then recommend with proof.
- **michael minton** (828 / 1313) — run the INTERACTION CHECK (connections / messages / cross-replies between 828 and 1313). Interaction -> two people, pair dies. Nothing -> back to Ian with evidence.
- **vincent jaeger** (1690 / 1516) — POLLER-ID-CROSSED: untangle which Patreon identity is the live pledge (roster + payment history), then recommend with proof.

Excluded — no action, ever:

- **andrew mcneill** (1340 / 338) — name-only detection, zero shared connections, personal vs business email. Not proven same person.
- **charles fox** (603 / 799) — twin carries doug@dougproper.com; not provably Charles.
- **don goulart** (890 / 814) — Ian: "ignore."
- **patrick morrissey** (1130 / 1129) — Ian chose leave-alone over the recommended merge.
