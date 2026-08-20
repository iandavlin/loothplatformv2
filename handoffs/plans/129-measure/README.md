# #129 Phase-1 measurement extracts

Re-run: `python3 match.py .` (reads the two TSVs in this directory).

- `postable-forums.tsv` — id, slug, title, parent_title, new-topics-365d.
  From `bb-mirror/web/_chrome.php:300`'s own SQL, run verbatim against Postgres
  `looth` schema `forums` on dev2. 37 rows.
- `shared-category-terms.tsv` — term_id, slug, name, parent_name, uses.
  From MySQL `looth_import` `wp_term_taxonomy` where taxonomy='shared_category'.
  36 rows.
- `match.py` — the hierarchy-aware matcher. Buckets terms into clean /
  hand-written / no-forum and weights each by actual term use.

Both extracts are dev2 data as of 2026-08-19. Forum IDs are shared with live
(same bbPress post IDs); the topic counts are dev2's.
