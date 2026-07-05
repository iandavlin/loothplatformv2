-- 2026-06-30-message-media.sql — DM image attachments (lane: message-images)
--
-- One optional image per message (chat-style: each image is its own bubble; the
-- text body is optional alongside it). `body` stays NOT NULL, but an empty string
-- is allowed when an image is present (image-only message) — the send path relaxes
-- its empty-body reject only when media is attached.
--
-- The bytes live in a DEDICATED message R2 bucket (NOT the profile bucket) and are
-- served ONLY through the access-controlled proxy /message-media/<thread-uuid>/<file>,
-- which asserts the viewer is a thread participant. media_url stores that proxy path.
ALTER TABLE messages
  ADD COLUMN IF NOT EXISTS media_url  text,
  ADD COLUMN IF NOT EXISTS media_mime text,
  ADD COLUMN IF NOT EXISTS media_w    integer,
  ADD COLUMN IF NOT EXISTS media_h    integer;
