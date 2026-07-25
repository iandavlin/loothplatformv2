# composer-v2 previs — phase 2 (pictures only, per Ian's mock-first gate)

*mentions lane, 2026-07-24. Spec: docs/atlas/COMPOSER-V2-PLAN.md §1.2–1.3 with the
2026-07-24 ruling folded (ONE rich-text engine on both surfaces; images NEVER inline —
the attachment strip is the only image surface, desktop included).*

## The shots (`shots/`)

| # | File | Skin | State |
|---|---|---|---|
| 1–2 | `m-base-{light,dark}.png` | mobile 390×844 | keyboard DOWN, empty, toolbar row |
| 3–4 | `m-kb-{light,dark}.png` | mobile | keyboard UP (zero-gap dock), rich text in body (bold/strike live) |
| 5–6 | `m-mentions-{light,dark}.png` | mobile | keyboard UP + mention list open (approved FB rows fill the midsection) |
| 7–8 | `m-photos-{light,dark}.png` | mobile | attachment strip (3 photos + add), photo count in tool row |
| 9–10 | `d-base-{light,dark}.png` | desktop 1280×900 | modal skin, empty |
| 11–12 | `d-mentions-{light,dark}.png` | desktop | mention popover open |
| 13–14 | `d-photos-{light,dark}.png` | desktop | attachment strip in the modal |

Regenerate: `node shoot.js` (RAM-flag first — one browser engine per box).
Source: `composer-previs.html?skin=&state=&theme=` — one file, one composer, two skins.

## Layout decisions shown (from the approved plan + receipts)
- **Mobile**: full-height sheet docked flush to the keyboard top — zero gap below, so
  Safari's autofill pills have no stage (receipt list). Header: **✕ top-left**,
  identity + "Replying to" pill, **Post top-right** (FB Done-style). No peek — the
  "double modal" cut. Mention list renders inline, filling the midsection.
- **Tool row** sits above the keyboard: photo button (opens the attachment strip —
  the ONLY image surface) + the rich-text toolbar + status.
- **Desktop**: the SAME composer with a modal skin (640px, centered). Mention list is
  a popover at the caret. Attachment strip identical to mobile — inline image
  insertion is REMOVED per the ruling (phase-3 Ian gate; read path untouched).

## Engine proposal (decide here, per the ruling)

**Recommendation: Quill 2 (2.0.3), one instance both surfaces.**
- Already vendored and proven in this codebase: the About rich-editor ships Quill
  2.0.3 lazy-loaded (profile-enhance lane), and frm/ntm already run Quill on desktop —
  the migration is a config change, not an engine swap.
- The sanitizer contract exists: server-side DOMDocument allowlist (b/i/s/a/ul/ol/li/p)
  built + regression-tested in the About work; reuse verbatim.
- The ruling *removes* Quill's weakest mobile area (inline image embeds) — the photo
  button routes to the attachment strip, so Quill runs text-only. Known iOS caveats
  that remain (IME/autocorrect quirks in contenteditable) are covered by the WebKit
  harness + Ian's phone gate.
- Alternative considered — TipTap/ProseMirror: better mobile IME reputation, but
  needs a JS bundler this codebase deliberately doesn't have (all vendored single
  files) and adds a large dependency surface. Not worth it for a 6-button toolbar.

**Toolbar set (shown in every shot): B · I · S · link · bullet list · numbered list.**
Six 38px buttons, one row. No headings/quotes/code in v1 of the composer (forum
replies don't need them; fewer states to keep correct on iOS). `S` maps to `<s>` —
already in the sanitizer allowlist.

**Quill-compat for desktop migration:** frm/ntm Quill content is already stored as
sanitized HTML; composer-v2 reads/writes the same shape. Existing posts with inline
`<img>` keep rendering (read path untouched); on EDIT of a legacy inline-image post,
images lift out of the body into the attachment strip (same behavior the mobile
editor has today via keep_media).

## Revision 2 (Ian notes, 7/25 21:12)
- Mobile picker RAISED: 44px top inset, search at top, list fills the middle, sheet
  bottom lands exactly on the keyboard top (was cramped at 18%-top over the keyboard).
- `m-icon-at-light.png`: the ONE @+ icon-variant decision frame (composer tool row with
  an @+ button in place of the person icon). Person icon is the keeper-recommended
  default and appears in every other frame.
- Search behavior note (no change, confirming): the picker's search IS mention-suggest —
  rank fix (machine slugs demoted), multi-word AND, scrunched matching and LIMIT 10 are
  all inherited for free, both skins.
