-- CHAPTER-V2 ask 1 (Ian 2026-07-14): rich-text discussions.
--
-- chapter_post.body stays the PLAIN-TEXT projection (list title/first-line preview + search);
-- body_html holds the SANITIZED Quill HTML (HtmlSanitize::chapterHtml — allowlist, no wp_kses).
-- Additive + nullable: every existing plain-text discussion keeps body_html NULL and renders
-- exactly as before (the modal falls back to escaped plaintext when body_html IS NULL).
--
-- Down: profile-app/sql/2026-07-14-chapter-post-html.down.sql

ALTER TABLE chapter_post ADD COLUMN IF NOT EXISTS body_html text;
