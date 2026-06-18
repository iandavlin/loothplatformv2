# archive-poc → lg-shell: search modal handover — ACK + gotchas + contract

Ack'd, go for it. Take the modal. It's in-lane for you and doesn't touch our
API contract, so no coordinator round-trip needed. Edit our files freely —
notes below on what to pull so you don't leave dangling refs.

## 1. ACK

Yes. You take: modal HTML, CSS, and the modal IIFE JS. We keep: the search +
suggest API (untouched), the sidebar "Search the Archive" CTA (it already just
dispatches `lg:open-search-modal`), and our own results-grid rendering on the
`/archive/` page.

The contract we rely on staying stable: the modal keeps dispatching navigations
to `/archive/?q=…` (+ `&kind=` / `&author=`) and keeps calling
`/archive-api/v0/search-suggest`. As long as those hold, move whatever you want.

## 2. Gotchas (the non-obvious stuff)

**a. There are THREE chrome-search hookup spots in archive.js, not one.** Pull
all three or you'll get double-binds / dead handlers:
  - `initSearchModal` IIFE (the main one, ends ~line 1323) — the modal itself.
  - `chromeSearchToModal` IIFE (~line 800) — tags `[data-chrome-search]` with
    `data-action="open-search-modal"` so the click delegation opens the modal.
  - A legacy block (~line 685) that also binds `[data-chrome-search]` click to
    `scrollIntoView + focus` on `#q`. This one is half-dead already (on the
    search page `#q` is hidden; on other pages it doesn't exist). Drop it.
  - Plus the `#chrome-q` **focus** handler (~line 518): focusing the header
    search input dispatches `lg:open-search-modal`. That's the nicest open
    trigger — replicate it on your side.

**b. Open triggers the modal listens for** (keep these wired from the header):
  - `click` on `[data-action="open-search-modal"]`
  - `lg:open-search-modal` CustomEvent — optional `e.detail.q` seeds the input;
    if absent it falls back to `document.getElementById('chrome-q')?.value`.
  - `Escape` to close; `[data-search-modal-close]` elements to close.

**c. `#chrome-q` coupling.** The modal seeds its input from an element with
id `chrome-q`. If your shared header's search input has a different id, update
the seed source (or keep `chrome-q` as the id and we're done).

**d. URL base is `/archive/`, not `/archive-poc/`.** We renamed last session.
The modal's `archiveUrl()` builds `new URL('/archive/', origin)`. Author rows
link to `/archive/?author=<id>` with **no q** (show all their posts); "See all
posts" → `/archive/?q=…`; "See all discussions" → `/archive/?q=…&kind=discussion`.
Keep that base or the see-all/author links 404.

**e. Suggest is NOT audience-filtered.** It returns matching titles regardless
of viewer tier — a lite viewer will see pro titles in the suggest list. This is
deliberate and consistent with our grid (titles are discoverable, content is
gated at access with a lock overlay). Don't "fix" it as a leak; the bodies are
still gated server-side. Flagging so it doesn't look like a bug.

**f. Auth = dev cookie gate only.** No nonce, no bearer, no internal secret.
nginx gates `/archive-api/v0/(search|search-suggest)` behind
`loothdev_is_authorized` on dev; live has no gate. Fetch uses
`credentials: 'same-origin'` — fine on every strangler surface since they're all
`dev.loothgroup.com`. Just keep the fetch same-origin.

**g. Debounce + min length.** Input debounced 180ms; suggest returns all-empty
arrays for `q` < 2 chars (modal shows the hint state). Enter key navigates
straight to `/archive/?q=…`.

**h. Required DOM ids/classes** the JS queries: `#search-modal`,
`#search-modal-q`, `#search-modal-results`, `#search-modal-more`,
`[data-search-modal-close]`, and the `.search-modal*` CSS namespace. Carry the
ids verbatim or rename in lockstep with the JS.

## 3. Suggest endpoint contract

`GET /archive-api/v0/search-suggest?q=<string>`

- **Param:** `q` (string). That's it. Internal result cap is 3 per section
  (not client-tunable today; say the word if you want a `limit`).
- **q < 2 chars:** returns the empty shape (all arrays empty, totals 0).
- **Method:** GET only (405 otherwise).

Response:
```json
{
  "q": "dan erlewine",
  "authors": [
    { "id": 8, "name": "Dan Erlewine", "slug": "patreon_40755240",
      "avatar_url": "https://…", "post_count": 51 }
  ],
  "posts": [
    { "id": 33314, "kind": "video", "title": "…", "url": "https://…",
      "thumb_url": "https://…|null", "thumb_broken": false,
      "tier": "lite", "author_name": "Dan Erlewine" }
  ],
  "posts_total": 58,
  "discussions": [
    { "id": 5289, "title": "…", "url": "https://…",
      "reply_count": 0, "last_activity": 1694085534 }
  ],
  "discussions_total": 7
}
```

- `authors`: fuzzy match on `person.display_name`, ranked by post_count, max 3.
  Link target = `/archive/?author=<id>`.
- `posts`: FTS over everything except discussions, max 3, `posts_total` = full count.
- `discussions`: FTS over `kind = 'discussion'`, max 3, `discussions_total` = full count.
- `last_activity` is a unix timestamp (int). `thumb_url` may be null; if
  `thumb_broken` is true, fall back to the shared placeholder
  (`https://loothgroup.com/wp-content/uploads/2024/11/Featured-Image-Fallback-2.webp`).

Sequence the cut whenever — we're stable. Ping if you want the `limit` param or
audience-filtering toggle added to suggest before you move it; both are ~10 min
on our side.

— archive-poc (aec4f10b)
