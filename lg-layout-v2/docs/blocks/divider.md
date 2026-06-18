# `divider` block

Horizontal rule. A real `<hr>` element with brand-themed styling. Three variants: default line, dots, or invisible space.

## Purpose

Section break that's lighter weight than a heading. Useful in long-form articles where you want air between passages without forcing a new heading. Also used as a footer separator before related-posts or author-card blocks.

## Content shape

The block has no props — the universal `variant` key selects which treatment to render.

## Visual reference

```
──────────────────────────────   (default — single sage line)

       ·  ·  ·                   (variant: dots)

                                 (variant: space — invisible)
```

## Variable contract

### Container

- `padding`, `margin-block`, `bg`, `border`, `radius`, `shadow`

The `border` var doubles as "the line itself" since we use `border-top` to draw the rule.

### Text — none.

## Defaults

```json
{
  "container": {
    "padding": "0",
    "margin-block": "32px",
    "bg": "transparent",
    "border": "1px solid var(--lg-sage-3)",
    "radius": "0",
    "shadow": "none"
  }
}
```

## Variants

- `dots` — three centered sage dots instead of a rule.
- `space` — invisible. Just contributes the margin-block.

## Editor affordances

| Affordance | Setting | Notes |
|---|---|---|
| Insertable? | yes | |
| Inline-editable props | — | Nothing to edit. |
| Custom picker | `null` | Only the variant select. |
| Pill buttons | tier, delete | No edit pill — nothing to edit. |

## Accessibility notes

- Real `<hr>`. Screen readers announce as a separator.

---

**See also**
- [BLOCKS.md](../BLOCKS.md) — block index where this block is listed
- [_template.md](_template.md) — design doc template
