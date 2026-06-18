# post-header

Hero header for an article: featured image with gradient scrim, title +
optional tagline + byline overlaid, tags strip below the photo.

Most data is pulled dynamically from WP (title, author, featured image,
date, tags, read time). Author links come from user meta — the slot config
is filterable via `lg_layout_v2_post_header_author_links` so you can swap
the meta keys (and icons) without editing `render.php`.

Default user-meta keys (override via the filter or set these as ACF user
fields):

| Slot     | Meta key                | Title shown on hover |
|----------|-------------------------|----------------------|
| Bio      | `lg_user_bio_url`       | Bio                  |
| Articles | `lg_user_articles_url`  | Articles             |
| Website  | `lg_user_website`       | Website              |
| Instagram| `lg_user_instagram_url` | Instagram            |

Per-author publication name lives in user meta `lg_publication_name`;
falls back to the site name (`get_bloginfo('name')`) when empty.

Variants vary the accent color used for the publication line + link-hover.

Responsive breakpoints:

| Width        | Photo height                 | Type / padding feel |
|--------------|------------------------------|---------------------|
| Mobile       | `clamp(320px, 56vh, 720px)`  | Compact, tight gutters |
| ≥ 768px      | `clamp(420px, 58vh, 720px)`  | Tablet, mid padding |
| ≥ 1024px     | `clamp(480px, 62vh, 760px)`  | Laptop, bigger byline avatar |
| ≥ 1440px     | `clamp(560px, 64vh, 820px)`  | Large monitor, max-width 1320 |
| ≥ 1920px     | `clamp(620px, 60vh, 880px)`  | Ultra-wide, max-width 1480 |
