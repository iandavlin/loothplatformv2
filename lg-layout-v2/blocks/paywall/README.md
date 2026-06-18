# paywall

Section gate. Drop this block into a post to declare "everything below this
line is members-only at tier X." Two viewer experiences:

- **Member (satisfies the tier):** sees a labeled divider — a thin amber
  rule with the tier name centered on it. Then the rest of the post
  renders normally.
- **Non-member:** the Renderer cuts the post at this block, emits a
  single `.lg-gate-cta--paywall` card in its place, and stops rendering.
  Everything past the paywall block is excluded from the response body
  entirely (crawler-safe — see SKILL §13's `<details>` discussion for a
  similar pattern but at the block level).

| Prop    | Type   | Default              | Notes                                                       |
|---------|--------|----------------------|-------------------------------------------------------------|
| `tier`  | string | `looth-pro`          | Required tier. Enum: `looth-lite` / `looth-pro` / `looth-group` / `admin`. |
| `label` | string | `Members only below` | Text shown on the member-side divider.                      |

## Companion: gate-CTA card

Whenever the renderer would have emitted a gated block to a non-member —
either a block with `gated_tier` set or content past a `paywall` block —
it substitutes a `.lg-gate-cta` card instead. The card has three
variants picked automatically by the engine:

| Variant     | When                                          | Look                                                            |
|-------------|-----------------------------------------------|-----------------------------------------------------------------|
| `--embed`   | per-block gate on an `embed` block            | 16:9 featured-image (greyed + blurred) + big play overlay + CTA |
| `--download` | per-block gate on download / file-ish blocks | Compact card, file icon, no image                               |
| `--paywall` | content trimmed past a `paywall` block       | 21:9 featured-image full bleed, "continue reading" framing      |

The CSS for all three lives in this block's `shell.css` (so the bundle
ships it on every page). The card itself is emitted by
`LG\LayoutV2\GateCta::render()`, not a user-insertable block — authors
don't pick the CTA; the engine picks it.

## CTA copy

Headline, body text, button label, and button URL come from a single WP
option: `lg_layout_v2_gate_cta`. Defaults work out of the box. To swap
(e.g., when transitioning off Patreon):

```bash
wp --path=/var/www/html option update lg_layout_v2_gate_cta \
  '{"button_url":"https://loothgroup.com/join","button_label":"Become a member","headline":"Members-only content","body":"…","enabled":true}' \
  --format=json
```

A proper dash settings panel is a planned follow-up.
