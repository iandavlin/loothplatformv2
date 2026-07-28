# Serve-window receipts — 2026-07-27, third window of the night

Raw evidence for the two proofs `reply-images-count` owed. Kept because a claim
in a report is worth less than a re-runnable artifact.

Serve baseline before and after: HEAD `fa67f02`, tree
`74b397571af8ec01388c16b6f2657013e9a13d55`, branch `main`, porcelain empty.
Eight tracked files overlaid in `~/loothplatformv2-clean`, restored with
`git checkout --`.

---

## Proof 1 — the 422 under a real over-cap request — **CLOSED**

Real HTTP against `https://dev2.loothgroup.com/bb-mirror-api/v0/reply` on the
serve, as `claude_admin` (uid 1912) with a real WP session cookie and a real
`wp_rest` nonce from `auth.php`.

| # | Request | Expected | Got |
|---|---|---|---|
| A1 | POST `media_ids:[1..7]` | 422 | **422** `{"ok":false,"error":"too_many_media","max":6,...}` |
| A2 | POST `bbp_media:[1..7]` — *the payload shape composer v2 actually sends* | 422 | **422** same body |
| A3 | POST `media_ids:[1..6]` | 200 | **200**, reply 72217 created |
| A4 | PUT `keep_media_ids:[1..4] + media_ids:[5,6,7]` = 7 | 422 | **422** |
| A5 | PUT reply **58510** (4 *real* stored images), `media_ids:[91,92,93]`, **no keep set** — the add-only back-compat branch that counts existing media | 422 | **422** |
| A6 | PUT `keep:[1,2,3] + add:[4,5,6]` = 6 | 200 | **200**, `status:"edited"` |

**A5 is the one that matters most.** It proves the cap counts what is *already
stored* rather than only the additions, and that it fires **before** the write:
reply 58510's `post_modified` was still `2025-09-02 13:19:02` afterwards and its
`bp_media_ids` were untouched at `2361,2362,2363,2364`.

**Negative control after restore:** with the eight files reverted, the identical
A1 request returned **200** and created reply 72226. That is what rules out the
422 having come from anything other than this lane's code.

All three replies created during the window (72217, 72225, 72226) were deleted.
*Correction logged later:* deleting a reply does **not** clean its mirror
`forums.attachment` rows — 72225 left six orphan rows behind. See the atlas doc
§10.

---

## Proof 2 — the composer guard under a real finger — **CLOSED, with one caveat**

`drive-composer-guard.py`. Viewport 390×844 DPR 2, `mobile:true`, touch
emulation on. The composer was opened by a **real trusted touch**
(`Input.dispatchTouchEvent`) on `.lg-fb-reply`, and Post was tapped the same way.
Six real photos went up through the real `#lgc-file` input to the real
BuddyBoss media endpoint.

`proofB.json` — the counter after each photo:

| photo | chips | counter | `data-full` | photo button |
|---|---|---|---|---|
| 1 | 1 | `1 of 6` | — | enabled |
| 2 | 2 | `2 of 6` | — | enabled |
| 3 | 3 | `3 of 6` | — | enabled |
| 4 | 4 | `4 of 6` | — | enabled |
| 5 | 5 | `5 of 6` | — | enabled |
| 6 | 6 | `6 of 6` | **1** (amber) | **disabled** |
| 7 | **6** | `6 of 6` | 1 | disabled — *"A reply can have at most 6 photos — remove one to add another."* |

The 7th left the chip count at 6: it was refused, not queued.

Counter width **38px**, scrollWidth **38px** — it is fully readable, not ellipsed
to nothing. (Asserting that specifically is a scar from a previous lane where a
status line rendered into 30px of a 390px row and "present" was mistaken for
"visible".)

Then posted → reply 72225 with 6 images, **asserted on the store, not the DOM**:
6 rows in `forums.attachment` *and* `bp_media_ids = 3401..3406`. That assertion
discipline came from composer-p3's `data-lg-uuid` finding — attributes are not
assumed to survive the save path.

**The caveat, stated plainly:** file *selection* used CDP
`DOM.setFileInputFiles`. The OS file picker cannot be driven by any automation.
Everything downstream of the picker — the `change` event, the upload, the cap
check, the counter, the disable, the refusal, the post — is the genuine code
path, and the taps that opened the composer and pressed Post were real touch
events. If that caveat is not good enough, the only thing that closes it is a
human thumb, and the shots below are what that human would see.

Shots: `B2-three-of-six`, `B3-six-of-six`, `B4-seventh-refused` (the money
shot — six chips, amber `6 of 6`, dimmed photo button, full refusal text),
`B5-after-post` (the 6-photo reply rendering as a 3×2 gallery in the real
mobile replies sheet).

---

## Proof 3 — the gallery on a real device path — **path proven, phone was Ian's**

`drive-gallery-surfaces.py` / `proofC.json`. Existing member reply **58510**
(four photos stored since Sep 2025, one ever visible), on three real surfaces:

| surface | cells | picked candidate | broken | intrinsic dims | lazy | anon scrubbed |
|---|---|---|---|---|---|---|
| mobile 390 logged-in | 4 | w=480 | 0 | yes | yes | n/a |
| desktop 1280 logged-in | 4 | w=480 | 0 | yes | yes | n/a |
| mobile 390 **logged-out** | 4 | w=480 | 0 | yes | yes | **yes** |

A path is not a phone. Ian closed the real one himself on 2026-07-27, looking at
reply **71991** rendering 5 of 5 images that had been in the database unseen.

### The defect this leg found

Desktop first measured `picked: w=800` for a tile that renders **229px**. The
`sizes` attribute declared `360px` because it was sized against the discussion
modal's ~724px content column, ignoring `.reply-stub__gallery`'s own
`max-width:460px`. Fixed to 228px/151px (commit `e183136`); every surface then
picked w=480 or less.

The markup-level assertion — "each tile has a `srcset`" — passed the entire time.
Only reading `img.currentSrc` caught it. That is the argument for asserting the
candidate the browser **picks**, not the attribute it was offered.
