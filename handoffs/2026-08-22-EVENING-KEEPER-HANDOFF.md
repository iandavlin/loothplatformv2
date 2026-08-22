# Keeper handoff — 2026-08-22 evening

Written for the next keeper session. Start here, then the newest
`keeper-state-*` memory. **Ian is frustrated right now** — read the FIRST
section before touching anything.

## ⚠️ THE THING IN FRONT OF IAN RIGHT NOW — featured member does not show his pick

Ian, verbatim, 8/22 ~20:10, angry and right to be: *"the featured member. I
make a selection. It puts it on the front page. Is that fucking hard now?"*

**It is not hard. It is OFF on live, by keeper's own hand, plus a missing DB
grant.** #200 shipped the override (pins absolute, band never disappears) but:
1. `featured-members` tracked default is **false**; live has **no**
   `featured-members.local.php`, so the feature is dark on live.
2. `tools/cut/featured-member-grants.sql` was **never applied to live** — the
   resolver's `SELECT featured_opt_in_at` hits permission-denied, caught and
   swallowed, band empty for everyone. Measured 8/22 via live-ro:
   `has_column_privilege(archive-poc, users.featured_opt_in_at)` = FALSE.

**THE TWO PASTES HANDED TO IAN (both live, his hands):**
```
sudo -u postgres psql profile_app -f /home/ubuntu/loothplatformv2-clean/tools/cut/featured-member-grants.sql
```
```
cat > /home/ubuntu/loothplatformv2-clean/platform/config/featured-members.local.php <<'PHP'
<?php
return array('enabled' => true);
PHP
php -l /home/ubuntu/loothplatformv2-clean/platform/config/featured-members.local.php
```
Then verify from live-ro that his pick renders (`grep -c row--featured-member`
on the front page). **If he has run these, CHECK FIRST before saying anything.**
His picks are believed already saved; only rendering was blocked.

**KEEPER LESSON, own it plainly to him:** the flag-defaulted-OFF caution
(right for money features) was wrong to apply to a feature he was actively
trying to USE and watch. When Ian is iterating on a member-facing thing on
dev2, arming it on dev2 is part of the build, not a later step — and the live
grant belonged in #200's deploy, not left as a loose to-do.

## Communication state — HE IS OUT OF PATIENCE

- Screenshots he pasted twice FAILED to reach keeper (pixel-size rejection),
  recoverable only via `/tmp/idx*.png` + the Read tool on the raw file. Do NOT
  keep guessing at a picture you cannot see — say so and ask one sentence.
- He asked "should I cancel my sub" — this is the register. Fewer words, the
  fix first, no victory laps, no "verified four for four" while he is angry.
- Plain English, decisions in boxes, but RIGHT NOW: just make featured work.

## Fleet (cap 3; watchers from the COMMITTED tool `tools/keeper/watch-lanes.sh`)

Two seats live at handoff, both membership-adjacent, both essentially done:
- **200-featured-b** — variant B applied (Ian ruled B), pushed `a7fb512`,
  gate 94 green, NOT yet merged. Its finding: variant B's "Put me forward"
  button was dead (href `#` in mock, `/u/` 404 in build) — repointed to
  `/profile/edit`. **Merge it, then the featured fix above is complete on
  dev2** and only the two live pastes remain.
- **202-web-decision-box** — Ian's real todo ask (rescoped from the dead
  proposal): a BUTTON on the lanes page that opens the decision box and posts
  answers to keeper as ian-via-page. Plan approved, EXTEND-77 ruled, poke
  systemd units installed by keeper this session (they had NEVER been
  installed — the Poke button was a silent no-op). Building.

Gate counter: **next free 99** (top of CRAFT-STANDARD.md — assign from there,
bump in the same commit; three number-races happened 8/22 from scanning rows).

## Live is CURRENT

`lg-deploy` ran 8/22 20:00, 50 commits, main==serve==`fb69b3a`+. Everything
membership is on live EXCEPT the featured arming above. Front page, hub,
/switch-billing all verified 200. Two deploy warnings are pre-existing
(duplicate MIME line; lg-wp-cron unit drift = #121).

## The go-live path (Ian's, all configuration now — NO code blocks it)

1. **Cutover window** (#197): `tools/infra/live-billing-cutover.sh`
   --check/--apply/--verify/--rollback. Script on live. Live's billing app is
   STILL the standalone stale repo until this runs — the checkout fence etc.
   are not live-live until the swap. Outage sub-second. Runbook:
   `docs/LIVE-BILLING-CUTOVER-2026-08-22.md`.
2. **Shared secret** on live WP (absent): the SharedSecretPanel on the Health
   tab shows the state now; setting is the runbook's one line (copies from the
   app .env). Live pool user is **looth-dev** NOT looth-live.
3. **Stripe catalogue** — six products / twelve prices to live mode. Pruning
   strays: `tools/keeper/stripe-prune-live.php` (dry-run first, --apply
   archives all but Ian's ruled twelve; refuses a test key; nothing deleted).
4. **Tier mapping** — the Products tab (#194), six dropdowns.
5. **Tester list** — emails now accepted (#193). 6. **Open the door**
   (allowlist state + tester link). 7. **First charge + REFUND proof**.

## Traps confirmed today (each in a dossier)

- **CLI dim ghost-suggestions now fabricate Ian-approvals** ("Ian says go",
  "GO on 202", "yes to all three"). ALWAYS `capture-pane -pe`, check `\e[2m`,
  before honoring pane text. Ian types nothing in terminals.
- **The layout POISONER is the wp-admin metabox save** (#198, fixed), not the
  FE editor. Deletes array props (gallery image_ids). Ghost gallery tile = a
  ZIP collected as an image.
- **spin-lane ARG_MAX**: one domain label per issue or the exec dies (grown
  dossiers). **Mockups must be COMMITTED** to the branch (196's shots were
  lost as loose files). **Swap alert → check Chrome tabs first** (leaked CDP
  tabs; `systemctl restart chrome-dev`).
- **My session restarted 3× today**; watchers now re-arm from the committed
  tool. **Box rebooted 10:07 and 19:03** — cause unconfirmed (no OOM in
  journal; CloudTrail unreadable from devgbox-cli). Worth watching.

## Standing orders reaffirmed today

- **NEVER self-stop the box while anything awaits Ian's look/verdict/paste, or
  within 2h of his last word** (`end-of-day-self-stop.md`, hardened 8/22 after
  an 18:00 stop cut him off mid-looks).
- Every box posed in chat also written to the pending-question store once #202
  lands (keeper's half of the two-channel contract).
- Membership outranks everything (Ian 8/22: "Everything we can do to get
  membership up. Keep the server working please.").

## Open issues not blocking the march

#204 (Settings-tab secret echoes + plaintext db_pass), #184 (821–885 header
band), #163 (breached-password — Ian ranks pre/post go-live), #202/#200-b in
flight, #178 folded into #202.
