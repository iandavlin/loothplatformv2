# The work board (backlog 29) — what to settle before building

**Written 2026-08-15 by the stripe-membership lane, at mock stage.** Nothing is
built. Ian has ruled the *shape* (the desk, plus drag-to-rank); these are the
things that decide the *build*, found while drawing it.

Mocks: `/footer-mockups/wip-board/` (round 1, shape) and
`/footer-mockups/wip-board/rank.html` (round 2, drag-to-rank).

> ⚠️ There is still no numbered entry for this in `docs/BACKLOG.md` — the file
> stops at 28. Scope here comes from keeper's brief and Ian's two rulings, not
> from a written backlog item.

---

## 1. The hard constraint: the board cannot commit the obvious way

**`~/loothplatformv2-clean` is the SERVING CHECKOUT and only ever pulls.** That
is the rule that outranks everything else on this box, and the one that left
nginx dead after a reboot on 2026-07-26 when it was broken.

Checked, not assumed:

- the checkout is `ubuntu:ubuntu`, `drwxrwxr-x` — no write bit for others;
- every PHP-FPM pool runs as a **non-ubuntu** user (`membership`, `looth-dev`,
  `events`, `profile-app`, `bb-mirror`, `archive-poc`, `tool-dev`, `www-data`).

So a page served from the serving tree **physically cannot** write to
`docs/BACKLOG.md` there — and must not, even if the permissions were loosened.

### The three ways round it

| | Shape | Trade |
|---|---|---|
| **A** | Page writes an **intent file** to a spool dir; keeper applies it on its next pass | Safest, no new privilege. But Ian's drag does not land until keeper runs. |
| **B** ⭐ | A small **committer service** as `ubuntu` with its **own clone**, called over loopback with a shared secret; it edits, commits, pushes; the serve picks it up on the next pull | **Recommended.** Keeps the serving checkout pull-only, and the drag lands in seconds. |
| **C** | Let the web user own a **second clone that can push** | Simplest, and the most privilege — a web-facing user that can push to main. |

**B is recommended because it is not a new trust model**, only a new caller: the
billing app already calls the poller over loopback with a shared secret
(`WpSync::trigger` → `/wp-json/lg-member-sync/v1/sync-customer`). Same pattern,
same failure handling, nothing novel to reason about.

---

## 2. The failure mode to design against

The drag will be **optimistic** — the card moves under Ian's finger before the
commit lands. If the commit then fails, **he has seen it move and the fleet never
learns**. That is [[trap-refused-save-reads-as-preserved]] in a new costume: the
screen says done, the store disagrees.

So, before it ships:

- the board must **show the commit landing**, and say so loudly when it does not;
- a failed re-rank must **snap back**, not sit there looking applied;
- gate it by making the committer fail on purpose and asserting the UI does not
  claim success — the assertion is on the STORE (`git log` / the file), never on
  the pixel.

Related: the same class already has a gate elsewhere in this repo, and the
lesson from gate 34b this week was that asserting a *decision* is not asserting
*reachability*. Here, asserting the drop handler ran is not asserting the file
changed.

---

## 3. Three questions only Ian can answer

Drawn onto `rank.html` so he can settle them by looking:

1. **Does dragging across a band change priority** (P1 → P0), or are bands fixed
   and a drag only re-orders within one? Assumed **across changes it**, since
   otherwise nothing can be promoted without editing the file by hand.
2. **"Something you can be updated on"** — drawn as a *since you last looked*
   strip. It could equally mean something that **reaches** him (email / phone
   notification when a lane needs him). One word settles it.
3. **Who else may drag?** Assumed **Ian only**; lanes and keeper keep editing the
   file as they do now, and his drag wins.

---

## 4. Why the file-backed model is the right one

`docs/BACKLOG.md` opens with this line, verbatim:

> `## PRIORITY INDEX (the order — edit THIS to re-rank; tell keeper "bump X")`

Keeper and every lane already treat that list as **the** ranking. So a drag is
not a widget update — it rewrites the list the fleet already obeys, and replaces
the "tell keeper bump X" ritual with the thing itself. Every re-rank keeps a git
history of who moved what and when.

Real shape as it stands today: four bands — **P0** (5 rankable lines), **P1**
(13), **P2** (20), **P3** (30).
