# post-footer

Article-closing footer: author card + "Keep reading" related-posts grid.
Pairs visually with `post-header` (same accent palette via variants).

**Data pulls**

- Avatar: ACF `author_image` → gravatar
- Bio: ACF `author_about` → WP `description`
- "More from {author}" CTA: ACF `author_looth_group_profile` → author archive
- Social icons: same slot config as `post-header` (filter via `lg_layout_v2_post_header_author_links`)
- Related posts: v1's `LG\Layout\RelatedPosts::pick_mix()` when present;
  fallback = same primary category, same CPT, latest first.
  Filter via `lg_layout_v2_post_footer_related_ids`.

**Responsive**

| Width   | Author card | Related grid |
|---------|-------------|--------------|
| <760    | stacked (avatar + body, links full-width) | 1 col |
| ≥760    | side-by-side | 3 cols |
| ≥1024   | roomier padding | bigger card images (180px) |
| ≥1440   | larger title | bigger card images (200px) |
