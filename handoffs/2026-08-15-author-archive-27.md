# Backlog 27 — author archive door in the Hub header

**Seat** featured-members · **2026-08-15** · **NOT BUILT — mock + findings only**
**Mock** https://dev2.loothgroup.com/footer-mockups/author-archive-link/

---

## 1. I misread the ask once. Read Ian's words, not the gloss.

Ian, verbatim (`docs/BACKLOG.md` @`b40a30f`):

> "We seem to not have a button for the user archive in the hub. Which would be
> the author search... **basically in the header where the links go**"

My charter's one-line gloss said "from a profile, a header link opens the hub
filtered to that author", so I built a mock of a link on the **member profile
header**. Wrong header. "The header where the links go" is the **Hub header** —
the `lg-chrome__menu` / `lg-chrome__submenu` list. That line landed on main
while I was working off an older branch point, which is why I did not see it
until after the reboot. Same lesson as backlog 20 the same night: **check the
source, not the paraphrase.** Mock is redrawn.

## 2. Nothing is missing except the door

- `/hub/?author=<NAME>` **already works.** Measured as a real anon on dev2:
  no filter **18** cards · `?author=Dan Erlewine` **18** · `?author=Ian Davlin`
  **2** · `?author=ZZZNoSuchPerson` **0**.
- The Hub **already draws an author header with a post count** when filtered
  (`_feed.php:893-910`).
- The Hub toolbar **already has a "Search by author…" box**
  (`form.hub-tsearch--author`, GET → `/hub/`).

So this is a **findability fix**, and small.

## 3. The real header, link for link

```
lg-chrome__menu:  The Hub ▼ | Events | The Map | Sponsors | Loothtool | Sign in
  └ submenu (aria-label "Browse the Hub by content type"):
      Everything · Discussions · Videos · Articles · Loothprints · Sponsor Posts
      · Useful Links · Shorts · Benefits · Loothcuts · Documents
```

A link for every kind of **content**, none for **people**. `The Map` →
`/directory/members/` is the only people-shaped door.

## 4. What the mock asks Ian

- **Q1 — where:** **P1** inside the Hub dropdown *(recommended — it is already
  "every way to slice the Hub", and author is another slice)*, or **P2** top
  level next to The Map. *If P1: the submenu's aria-label says "by content type"
  and would stop being true.*
- **Q2 — what's behind it:** **D1** an Authors index (the 432 who have posted,
  with counts, click → `/hub/?author=NAME`) *(recommended)*, or **D2** point it
  at the existing `/directory/members/` for zero build. D3 (focus the existing
  author box) is cheapest but needs you to already know the name — which is the
  thing you couldn't do.

**D1 is cheap**: `_suggest.php`'s author query already groups names across both
sources, sums counts and joins avatars. The index is that query minus the text
filter.

## 5. ⚠ A REAL DEFECT FOUND WHILE CHECKING D1 — unresolved

**The Hub author search is effectively dead for signed-out visitors.**

- `/hub/?suggest=author&q=erlewine` → **0 results on dev2 AND live**, for a man
  with 54 posts. Not the `<2 char` guard, not a dead endpoint: `?suggest=tag`
  and `?suggest=hub` both answer normally on the same request.
- **Cause:** `_suggest.php` hides an author from anon unless
  `forums.person.discussion_visibility = 'public'`. That column is `'member'`
  for **506 of 517** rows, because `member` is the default. An anon can be
  suggested **4 of 432** authors.
- **Why it's a defect, not a policy:** the Hub **feed** shows that same anon
  **14 author bylines by name** on the unfiltered front page. `_suggest.php`'s
  own comment claims "same mask as the Hub feed". Measured, it is not.
- **My read** (a ruling, not my call): the *search* over-masks rather than the
  feed leaking — a byline on published work is already public, and
  `discussion_visibility` is a *discussions* setting being used to hide the
  authors of articles and videos too.
- **NOW MEASURED** (was read-from-code; verified 2026-08-15 with minted
  `looth_id` bearers): the signed-in path is **fine**. Same query, three
  viewers — `q=erlewine`: anon **0**, member **1** (Dan Erlewine, n=54, avatar),
  admin **1**. Across broad queries (`an`/`gu`/`ar`/`er`) anon gets **0–3** and a
  member gets **8 every time**, 8 being the endpoint's own LIMIT. So the search
  works for signed-in viewers and is dead only for anon — meaning **Ian has
  never seen it broken**, and his complaint really is the missing door.

**This decides D1.** An Authors index on that query shows a signed-out visitor
4 people out of 432 — not an archive. Either D1 ships members-only, or the mask
is settled first. Asked keeper whether this becomes its own backlog item.

## 6. Numbers (all measured)

| | |
|---|---|
| authors with posts | **432** (179 have 1, 161 have 2–5, 78 have 6–20, 14 have 20+) |
| members who ever posted | **410 of 1,839** — 78% of profiles have nothing to archive |
| live author names shared by >1 member | **11 of 436** — *Ian's own is one* |
| `forums.person` rows `discussion_visibility='public'` | **11 of 517** |

The name-matching caveat is the Hub's own: it matches authors **by name, not
id**, and calls the id fix "a later cross-lane increment".

## 7. State
- No code written for 27. Mock only, published behind the dev gate.
- The two old screenshots were **deleted** rather than left stale beside a
  rewritten page.
- Blocked on Ian for Q1/Q2, and on keeper for whether §5 is its own item.
