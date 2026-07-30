# Discussion cards: provider video on the card

Lane `discussion-card-video`, 2026-07-30. Ian: *"a discussion whose body carries a
YouTube/Instagram/Facebook link should PLAY that video inline on its hub card — the
exact inline-play facade video posts get today."*

YouTube is **done and pushed** (see §2). Instagram and Facebook are **deliberately
not done** — §4 says what they would actually cost, because the reason is not
"ran out of time", it is that they are a different kind of job.

---

## 1. The starting state — narrower than the ask assumed

Two things already worked, which is why the change is small:

| Surface | Before this lane |
|---|---|
| Discussion **post page** | A pasted YouTube link **already became a real player.** `.post__body` ships the bare anchor, forums.js §2d `bbProcessEmbeds()` rewrites it. Verified in a real browser on dev2: `youtu.be/4Gz45SJd2Ok` → live `youtube.com/embed/…` iframe. |
| Discussion **hub card** | **Already played too** — but the old way. `_feed.php` emitted `.feed-card__embed[data-embed-url]`, and forums.js §2e dropped a **full YouTube iframe** in on IntersectionObserver, under the excerpt, with the raw pasted URL still printed above it. |

`_feed.php`'s *facade* path (`data-yt-play`) genuinely was content-video-only —
that reading was right. It just was not the only path onto the card.

So the real defect was never "discussions can't play". It was **eager third-party
players, in the wrong slot, next to redundant link text.**

## 2. What shipped (YouTube)

Discussion cards emit the same facade a video post gets: thumb + play button in
the **cover slot** above the title; the player is built only on click.

**No JS or CSS change was needed.** The `.fc-cover--video[data-yt-play]` contract
was already card-type-agnostic in all three places that matter:

- forums.js §1 delegated play handler — keys off the class.
- forums.js §1 cover-lightbox handler — already excepts it, in words:
  *"Video facade + gated covers keep their own behavior."*
- forums.js §4e desktop discussion modal — `.fc-cover--video` already in its
  exempt list, so the facade click plays instead of opening the modal.

Server markup was the entire gap. Changes are confined to
`bb-mirror/web/forums/_feed.php`:

- `feed_yt_id()` — takes **one URL** (the answer from `feed_first_embed_url()`),
  not a body. So the facade only claims a card whose **first** provider URL is
  YouTube; a body leading with Vimeo/IG/X keeps today's lazy embed. One video per
  card, and non-YouTube providers stay untouched by construction.
- The facade drops that card's `.feed-card__embed`, so one video can't render twice.
- Thumb = the member's own attached photo when there is one (resizer + `srcset` +
  dims), else ytimg `mqdefault` — see `CRAFT-STANDARD.md` "Documented exceptions"
  for why that is one width with no `srcset`.
- The pasted URL is stripped from the excerpt (the rule hub-polish.js already
  applies client-side to promoted content cards), and `.fc-excerpt` is no longer
  emitted when empty — it carries a `border-top`, so an empty one drew a stray
  rule under the title.

### Measured, scrolling one forum page in the real browser

| | main | branch |
|---|---|---|
| YouTube players built before any click | **7** | **0** |
| Requests to `youtube.com/embed` | **7** | **0** |
| Static thumbnails instead | — | 9 × ~11 KB |

Page image weight 601 KB against the 1.5 MB budget; no IMG-OVERSIZE, no
IMG-NODIMS on anything the facade emits. All 8 gates green.

### Desktop only, on purpose

At ≤640 hub-polish routes a discussion tap to the discussion sheet and nothing
plays on a hub card (Ian 2026-06-17), and forums.css hides `.fc-play` there. The
facade therefore renders as a **plain thumbnail with no play button** on a phone
— which is exactly what a video post's card does. Not a missing button.

### Scoping, verified branch-vs-main over two feeds

Content cards **byte-identical**. Of 24 discussion cards, exactly **9** changed:
7 facades + 2 that had been drawing an empty `.fc-excerpt` divider. The 3
Instagram discussions are byte-identical.

---

## 3. How much of the corpus this covers

dev2 `forums.topic`, 1315 discussions:

| Provider | Discussions | Embeds in body today? | On the card today? |
|---|---|---|---|
| **YouTube** | **73** | yes | **facade (this lane)** |
| Instagram | 12 | yes (blockquote + `embed.js`) | eager embed on scroll |
| Facebook | 4 | **no** | **no** |
| Vimeo | 0 | yes | eager embed on scroll |
| X / Twitter | 0 | yes | eager embed on scroll |

YouTube is 82% of all provider discussions and 100% of the ones with real volume.

---

## 4. Why Instagram and Facebook are a separate job

Not "the same change with another regex". The facade needs **a thumbnail image
URL derivable from the link**. YouTube gives that away for free —
`i.ytimg.com/vi/<id>/mqdefault.jpg` needs no key, no call, no account. Neither of
the other two does.

### Instagram (12 discussions)

- A shortcode (`/p/<code>/`, `/reel/<code>/`) has **no public thumbnail URL**. The
  only supported route is the oEmbed endpoint, which since 2020 requires a
  **Facebook App access token** — app review, app-scoped, rate-limited.
- So an IG facade is an *integration*: register/att app, hold a token in
  `lg-secrets-helper`, add a server-side fetch **with caching** (a per-render call
  to Meta is not acceptable in the feed path), plus a fallback for private/deleted
  posts, which are common in a 12-row corpus this old.
- Worth noting the current card behaviour has **the same disease this lane just
  cured**: each IG card pulls `instagram.com/embed.js` on scroll. Even without a
  thumbnail, a static "Instagram post — tap to load" facade would kill that
  third-party script until intent. **That is the cheap 80% and it needs no token.**

### Facebook (4 discussions)

- Not embedded **anywhere** today — not on the card, not in the post body.
  `feed_first_embed_url()`'s regex doesn't list facebook, and forums.js
  `bbBuildEmbed()` has no facebook branch. An FB link is plain text everywhere.
- Embedding needs the FB SDK or the plugin iframe; a thumbnail needs Graph/oEmbed
  with the same token problem as Instagram.
- 4 discussions. Lowest value of anything here.

### Recommended order, if Ian wants these

1. **IG "load on tap" facade, no thumbnail, no token** — kills `embed.js` on
   scroll for 12 cards. Small, self-contained, same shape as this lane.
2. **Token + cached oEmbed** for real IG thumbnails — only worth it if the app
   token is being obtained for something else anyway.
3. **Facebook** — 4 rows. Do it last or not at all.

---

## 5. Open decision for Ian

The ytimg `mqdefault` thumb is 320×180. On desktop that is ~1× and looks right.
On a phone the cover goes full-bleed, so it is ~0.3× and **visibly soft**.

This is known, not missed, and `CRAFT-STANDARD.md` records why sharper is not a
free swap (hq720/maxresdefault **404 on 1 of the 13** YouTube discussions on
dev2, and a 404 in a `srcset` candidate is a broken image, not a fallback).

If Ian wants it sharper, there are two honest options, both costing something:

- **Client-side upgrade after load** — try `hq720` in a detached `Image()`, swap
  only if it resolves. Zero risk of a broken card. Costs one extra 58–206 KB
  fetch per in-view card, i.e. it gives back some of the weight the facade just won.
- **Proxy ytimg through `img.php`** — fetch once, cache locally, serve real
  `srcset` at our own widths. Best result and no third-party image request at
  all, but it is a genuine feature: remote fetch, SSRF care, cache dir, failure
  paths. Not a markup change.

Doing nothing is defensible. Picking one is Ian's call, and it changes one line
of markup plus (for either option) a small amount of new code.
