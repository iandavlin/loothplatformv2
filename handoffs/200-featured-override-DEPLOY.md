# #200 — the merge window, in order

Three couplings a `git pull` does not do. Two are keeper's, one is Ian's.
Nothing here is optional and the first one has a deadline: **dev2 goes dark on
the featured band the moment keeper's clone pulls, until step 1 is done.**

---

## 1. KEEPER, IN THE SAME WINDOW AS THE PULL — dev2's `.local.php`

The tracked default is now `false` (that is the point of the lane). dev2 keeps
the feature ON through a per-box override that **did not exist before this
merge** — the readers for it are part of this branch. So the file has to be
placed as the pull lands, not afterwards:

```
cat > ~/loothplatformv2-clean/platform/config/featured-members.local.php <<'PHP'
<?php
// dev2 only. The tracked default is false since #200 (2026-08-22) so a live
// pull cannot re-arm the feature by surprise; live is protected by this file
// being ABSENT, not by a check in the code.
return array('enabled' => true);
PHP
php -l ~/loothplatformv2-clean/platform/config/featured-members.local.php
```

⚠️ **`php -l` IT. DO NOT SKIP.** A parse error inside an `@include` yields
`false`, the reader falls back to the tracked value, and the box serves the
opposite of what the file says — silently.

**It cannot dirty the serving checkout**: `.gitignore:70` covers
`platform/config/*.local.php`. Verified.

**Verify in one command** (expect `1`, and `0` before the file is placed):

```
cd ~/loothplatformv2-clean/archive-poc/web \
  && sudo -u archive-poc php index.php | grep -c row--featured-member
```

### The other `.local.php` — DO NOT place it without asking Ian

`platform/config/featured-consent.local.php` **already exists** in dev2's
serving checkout, saying `enabled => true`, placed 2026-08-20. **Nothing read
it until this merge.** So dev2 has been believed to be running #107's consent
rule ON and has been running it OFF for two days, and this merge makes that file
suddenly real. Its effect: the featured card may repeat an opted-in member's
members-only one-liner on the public front page.

That is a member-facing behaviour change nobody has looked at. **Either move it
aside before the pull, or get Ian's eyes on it first** — but do not let it
switch on as a side effect of a deploy about something else. That is the exact
shape of the incident this whole lane exists to clean up.

```
mv ~/loothplatformv2-clean/platform/config/featured-consent.local.php \
   ~/featured-consent.local.php.held-for-ian-200
```

---

## 2. KEEPER — the history migration on dev2

Already applied by this lane on dev2. Idempotent, safe to re-run:

```
sudo -u postgres psql looth -f tools/migrations/200-featured-history-pinned.sql
```

**The code does not require it.** Both the writer (`_config.php`) and the reader
(`_featured-history.php`) probe `information_schema` first and omit the column
when it is absent, so an unmigrated box keeps recording and reading history
exactly as before. That is deliberate: live is that box until Ian runs it, and a
failed INSERT there dies inside a `catch` and would silently stop recording
stints altogether.

---

## 3. IAN — two live commands, and neither is urgent until he wants the feature on

**a. The grant.** This is the one that actually broke the front page, and it is
still missing on live:

```
sudo -u postgres psql profile_app -f tools/cut/featured-member-grants.sql
```

Verify (expect `t`):

```
psql -h 127.0.0.1 -U looth_ro -d profile_app -c \
  "SELECT has_column_privilege('archive-poc','public.users','featured_opt_in_at','SELECT')"
```

**b. The history column**, whenever he wants live's stints to record how they
happened:

```
sudo -u postgres psql looth -f tools/migrations/200-featured-history-pinned.sql
```

### What live looks like after the merge WITHOUT either command

Fine, and better than today. The tracked flag is `false`, so live serves the
**fallback band** — a rendered card, not the hole it has now. Nothing regresses;
the feature simply stays off until Ian turns it on, which is what the flag law
asks for.

---

## The one thing still waiting on Ian

**A or B for the empty-state card**, at
`https://dev2.loothgroup.com/footer-mockups/200-featured-override/`.
Both are built and both are asserted by gate 94 §A3. `defaults.php` ships
`'kind' => 'member'` (option A, the standing card) because it is the status quo.
His answer is a one-line change to that key.
