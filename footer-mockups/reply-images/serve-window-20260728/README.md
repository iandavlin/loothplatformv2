# Serve-window receipts — 2026-07-28 — **against SHIPPED code**

The two proofs `reply-images-count` owed, closed against the merged build serving
on dev2. The 7/27 receipts next door were taken against an *overlay of a branch*;
these were taken against the thing members actually hit.

**Serve baseline, before and after — unchanged, because nothing was overlaid:**
HEAD `645b4cff935b4eb5306ee444d7b688ad82948b39`, tree
`e42d6a639820680ee5a89c5d0acfe68f24b33ac4`, branch `main`, porcelain **EMPTY**;
both hashes matched `~/keeper-baseline/` exactly. **No file on the serve was
touched, so there was no restore to perform.** The code under test *is* the serve.

Confirmed first by diffing all eight of this lane's files against the serving
checkout: five byte-identical, and `forums.css` / `forums.js` / `hub-polish.js`
differ only because composer-p3 shipped in the same pull — with every marker of
this lane intact (gallery block 12 hits, `max: 6` at both tray sites,
`reply_image_max` / `lgcPhotoN` counts matching, both 422 sites present).

---

## Proof 1 — the 422 under a real over-cap request — **CLOSED**

`proof1.sh`, `proof1.jsonl`. Real HTTP to
`https://dev2.loothgroup.com/bb-mirror-api/v0/reply` over loopback, as
`claude_admin` (uid 1912) with a real logged-in cookie and a real `wp_rest` nonce
fetched from the shipped `auth.php` — which, note, returned `"reply_image_max":6`,
so the server is already handing the client the same cap it enforces.

| # | Request | Expect | Got |
|---|---|---|---|
| A1 | POST `media_ids:[1..7]` | 422 | **422** `too_many_media`, `max:6` |
| A2 | POST `bbp_media:[1..7]` — *the shape composer v2 actually sends* | 422 | **422** same body |
| A3 | POST `media_ids:[1..6]` | 200 | **200**, created reply 72232 |
| A4 | PUT keep `[1..4]` + add `[8,9,10]` = 7 | 422 | **422** |
| A5 | PUT reply **58510** (4 *real* stored images), add 3, **no keep set** | 422 | **422** |
| A6 | PUT keep `[1,2,3]` + add `[11,12,13]` = 6 | 200 | **200** `status:"edited"` |

**6/6.**

**A5 is the load-bearing one.** It exercises the add-only back-compat branch,
which must count what is *already stored* rather than only the additions — and it
must refuse **before** writing. Asserted on the store afterwards, not the
response: 58510's `post_modified` was still `2025-09-02 13:19:02`, its
`bp_media_ids` still `2361,2362,2363,2364`, its mirror rows still 4, and its
`post_content` still the member's own text rather than the probe's. The refusal
was total.

### The negative control had to change, and it is stronger

The 7/27 control was "restore the files, re-POST 7, get 200". That is impossible
now without un-shipping, and un-shipping a live surface to prove a point is not a
trade worth making. The replacement is a **boundary differential on the shipped
endpoint**: A1 and A3 are the same session, same topic, same payload shape, and
differ by **one image** — 7 → 422, 6 → 200. A4/A6 are the same differential on
the edit door. Holding everything else constant isolates the cap better than
swapping the whole tree did.

---

## Proof 2 — the composer guard under a real finger — **CLOSED**

`proofB.json`, `B1`–`B5`. Viewport 390×844 DPR 2, `mobile:true`, touch emulation.
Composer opened by a **real trusted touch** on `.lg-fb-reply`; six real photos
through the real `#lgc-file` input to the real BuddyBoss media endpoint; Post
tapped the same way.

| photo | chips | counter | `data-full` | photo button | error |
|---|---|---|---|---|---|
| 1–5 | 1→5 | `1 of 6`→`5 of 6` | — | enabled | — |
| 6 | 6 | `6 of 6` | **1** (amber) | **disabled** | — |
| 7 | **6** | `6 of 6` | 1 | disabled | *A reply can have at most 6 photos — remove one to add another.* |

The 7th left the chip count at **6**: refused, not queued. Counter width **38px**
== scrollWidth **38px**, so it is readable rather than ellipsed
(cf. `status-string-ellipsed-to-zero` — presence is not legibility).

**Asserted on the store, not the DOM:** the posted reply 72240 carried **6 rows**
in `forums.attachment`.

*Caveat, unchanged and still honest:* file **selection** used CDP
`DOM.setFileInputFiles`. No automation can drive an OS picker. Everything
downstream of the picker is the genuine path.

---

## The over-fetch alarm that turned out not to be one

`probe-gallery-both-surfaces.py`, `probe-cover-reuse.py`.

Measuring the **picked** candidate (`img.currentSrc`) rather than the markup, a
2-up gallery on mobile showed one cell pulling **w800** while its identical
sibling pulled w480. That is the exact defect class this gallery exists to
prevent, so it was chased before anything was called closed.

**It is not a defect.** The w800 cell is the *same source file* as the feed-card
cover on the same page, which loads **eagerly at 390 css px** where w800 is
correct. The browser reused bytes it already had instead of issuing a second,
smaller request — fewer bytes crossed the wire, not more. Proven by comparing the
resolved `img.php?s=…` source of each: cover == cell 1 (`coverEqCell1: true`),
cover != cell 2, and **cell 2 — a different file with no cover twin — picked
w480, exactly right.** Cell 2 is the control.

> **Bank this: `currentSrc` can report a candidate larger than `sizes` would
> select, when the same image is already on the page at a larger size.** A naive
> `picked > needed ⇒ over-fetch` gate produces a FALSE RED on a correct page.
> Any future craft gate on this needs the same-source check, or it will cry wolf
> on every reply whose photo is also the card cover.

### And the `e183136` fix, verified on shipped code

Desktop 1280 dpr1, both galleries in the discussion modal:

| reply | count | declared `sizes` | rendered | picked | verdict |
|---|---|---|---|---|---|
| 72240 | 6 | `…30vw, 151px` | **151 css** | w240 | exact |
| 72229 | 2 | `…47vw, 228px` | **229 css** | w240 | exact (the 1 px is the documented floor) |

Declared 228 px against a tile that renders 229 px. Before `e183136` that tile
declared 360 px and pulled **w800**. The over-fetch is gone, measured in a real
browser against the merged build. No w800 anywhere on desktop.

Mobile 390 dpr2, reply 72240: all six cells `…30vw, 151px`, rendered 88 css /
176 dev, picked **w240**, `loading=lazy`, intrinsic `width`/`height` present,
0 broken.

---

## Cleanup — and the orphan defect, closed

Both test replies removed through the **real** DELETE endpoint (72232 from proof
1, 72240 from proof 2). Verified on **both** stores rather than one: WP post gone,
`forums.reply` row gone, and the six uploaded `wp_bp_media` rows gone with no
files left on disk.

**Deleting 72240 left 6 orphan `forums.attachment` rows behind** — exactly the
pre-existing defect this lane documented, and exactly the trap it fell into on
7/27 by reporting cleanup complete on the WP side alone. This time the mirror was
checked, so it was caught.

§10 asked whether a materializer re-sync clears orphans before anyone writes a
DELETE. **Answered: it does not.** `bb-mirror-reconcile.service` was run
deliberately (`20 row(s) touched (…replies=0…)`) and the orphan census was
byte-identical afterwards. So the documented DELETE was warranted, and was run:

```
dry run    : 33 rows across 4 parents; 873 live rows would survive (33+873 = 906)
executed   : DELETE 33
after      : orphan census NONE, total reply attachments 873
safety     : joined counts IDENTICAL before and after — 505 / 230 / 368 / max 5
```

The safety assertion is the point: cleaning orphans **must not** move any
user-visible number, and it did not.

**A side effect worth having.** The attachment-only multi count is now **230** —
equal to the joined count. The two query shapes finally agree on dev2, so the
phantom "max 11 images per reply" that misled three separate people, and made a
cap of 6 look lossy twice, can no longer be produced on this box.

Cleared: 72083 (10) and 72084 (11) from `reply-images-6`'s 2026-07-09 tests,
72225 (6) from this lane's 7/27 window, 72240 (6) from this one.

The underlying delete-path defect — the mirror not cleaning attachment rows when
a reply is removed — is **pre-existing, still unfixed, and still not this lane's**.
It will keep producing orphans until someone owns it.
