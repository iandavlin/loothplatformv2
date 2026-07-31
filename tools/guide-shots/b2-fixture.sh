#!/usr/bin/env bash
# b2-fixture.sh — create / drop the THROWAWAY subject for shot B2.
#
# WHY THIS EXISTS. B2 photographs a section chip that has been CAPPED by a
# stricter header above it: `$capped = ceiling !== '' && effectiveVisibility(
# ceiling, vis) !== vis` (_render_blocks.php:690), which renders the ⚠▾ caret.
# That needs header=members with a section that is itself public.
#
# The visibility matrix's permanent fixture 1849 CANNOT produce it -- 1849 is
# header=public, so nothing above any section is strict enough to cap anything.
# Flipping 1849 was considered and REJECTED: §5 of the shot list says do not
# leave it in a non-default state, and the matrix reads it concurrently, so a
# mid-run flip could red another lane's gate. The alternative was minting a
# session as a real member (Buck) purely to take a screenshot. Keeper approved
# this third route on 2026-07-31.
#
# It is a THROWAWAY. `down` is written first-class, not as an afterthought,
# because the whole justification for this route is that it leaves no trace.
#
#   ./b2-fixture.sh up     # create, print the wp id to mint against
#   ./b2-fixture.sh down   # remove every row it made
#   ./b2-fixture.sh status # what exists right now
set -euo pipefail

SLUG="guide-capped-qa"
WP_ID=90001            # far above max(wp_user_id)=1954, so it cannot collide
                       # with a real bridged member now or after a reconcile.
PSQL=(sudo -n -u profile-app psql -d profile_app -v ON_ERROR_STOP=1 -qAt)

case "${1:-status}" in

up)
  # ON CONFLICT DO NOTHING throughout so a re-run is idempotent -- a half-made
  # fixture from an interrupted run must not block the next attempt.
  "${PSQL[@]}" <<SQL
BEGIN;
INSERT INTO users (uuid, primary_email, display_name, slug)
VALUES (gen_random_uuid(), 'guide-capped-qa@invalid.test', 'Guide Capped QA', '${SLUG}')
ON CONFLICT DO NOTHING;

INSERT INTO wp_user_bridge (user_id, wp_user_id)
SELECT id, ${WP_ID} FROM users WHERE slug='${SLUG}'
ON CONFLICT DO NOTHING;

-- header=members is the CEILING. about=public is the section that gets capped
-- by it, and is therefore the chip B2 photographs.
INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
SELECT id, 'header', 'members', '{}'::jsonb, 0 FROM users WHERE slug='${SLUG}'
ON CONFLICT (user_id, key) DO UPDATE SET visibility='members';

INSERT INTO profile_sections (user_id, key, visibility, data, sort_order)
SELECT id, 'about', 'public',
       '{"text":"Fixture for guide shot B2 — this section is public, but the header above it is members-only, so its chip is capped."}'::jsonb,
       1 FROM users WHERE slug='${SLUG}'
ON CONFLICT (user_id, key) DO UPDATE SET visibility='public';

-- THE BLOCK ORDER IS NOT DERIVED FROM profile_sections. Block::profileLayout()
-- reads users.profile_layout (jsonb) and only falls back to defaultLayout()
-- when it is NULL -- and that default did NOT include 'about', so the section
-- existed, was public, was capped by the header, and rendered NOTHING. Set the
-- layout explicitly or B2 photographs an empty page.
UPDATE users SET profile_layout = '["header","about"]'::jsonb WHERE slug='${SLUG}';
COMMIT;
SQL
  echo "fixture up: /u/${SLUG}  (mint with wp id ${WP_ID})"
  ;;

down)
  # profile_sections and wp_user_bridge are ON DELETE CASCADE from users, but
  # they are deleted explicitly anyway: a cleanup that silently relies on a FK
  # is one schema change away from leaving rows behind.
  "${PSQL[@]}" <<SQL
BEGIN;
DELETE FROM profile_sections WHERE user_id IN (SELECT id FROM users WHERE slug='${SLUG}');
DELETE FROM wp_user_bridge   WHERE user_id IN (SELECT id FROM users WHERE slug='${SLUG}');
DELETE FROM users            WHERE slug='${SLUG}';
COMMIT;
SQL
  echo "fixture down: removed /u/${SLUG}"
  ;;

status)
  echo "users rows:            $("${PSQL[@]}" -c "SELECT count(*) FROM users WHERE slug='${SLUG}';")"
  echo "bridge rows:           $("${PSQL[@]}" -c "SELECT count(*) FROM wp_user_bridge WHERE wp_user_id=${WP_ID};")"
  echo "section rows:          $("${PSQL[@]}" -c "SELECT count(*) FROM profile_sections WHERE user_id IN (SELECT id FROM users WHERE slug='${SLUG}');")"
  echo "-- fixture 1849 (MUST stay header=public) --"
  echo "1849 header vis:       $("${PSQL[@]}" -c "SELECT visibility FROM profile_sections WHERE user_id=1849 AND key='header';")"
  ;;

*) echo "usage: $0 up|down|status" >&2; exit 2 ;;
esac
