-- Export every member with a persisted tier=NULL patreon row, plus everything
-- RoleSourceWriter::readAllForUser / Arbiter::sync would consult for them.
-- Companion to classify-null-shadow.php (the offline replica that computes
-- whose effective tier would change under the null-shadow reader fix).
--
-- Run on a box (dev2 or, via live-ro, live):
--   mysql -N -B < null-shadow.sql > null-shadow-<box>-<date>.tsv
-- Both boxes use looth_import as the WP DB (looth_dev is the decoy on BOTH —
-- verified against wp-config.php DB_NAME on dev2 2026-08-09).
--
-- Also export the tier map (the replica needs it):
--   mysql -N -B -e "SELECT option_value FROM looth_import.wp_options
--                   WHERE option_name='lgpo_tier_map'" > tier-map-<box>.txt
SELECT r.wp_user_id,
  IFNULL(u.user_email,''),
  IFNULL((SELECT s.tier FROM lg_membership.lg_role_sources s WHERE s.wp_user_id=r.wp_user_id AND s.source='stripe'),'\\N'),
  (SELECT COUNT(*) FROM lg_membership.lg_role_sources s2 WHERE s2.wp_user_id=r.wp_user_id AND s2.source='stripe'),
  IFNULL((SELECT m.tier FROM lg_membership.lg_role_sources m WHERE m.wp_user_id=r.wp_user_id AND m.source='manual_admin'),'\\N'),
  (SELECT COUNT(*) FROM lg_membership.lg_role_sources m2 WHERE m2.wp_user_id=r.wp_user_id AND m2.source='manual_admin'),
  IFNULL((SELECT um.meta_value FROM looth_import.wp_usermeta um WHERE um.user_id=r.wp_user_id AND um.meta_key='payment_source'),'\\N'),
  IFNULL((SELECT um2.meta_value FROM looth_import.wp_usermeta um2 WHERE um2.user_id=r.wp_user_id AND um2.meta_key='lgpo_patreon_tier_id'),'\\N'),
  IFNULL((SELECT um3.meta_value FROM looth_import.wp_usermeta um3 WHERE um3.user_id=r.wp_user_id AND um3.meta_key='wp_capabilities'),'\\N'),
  IFNULL((SELECT pm.patron_status FROM lg_membership.lg_patreon_members pm WHERE pm.wp_user_id=r.wp_user_id),'\\N'),
  IFNULL((SELECT pm2.currently_entitled_amount_cents FROM lg_membership.lg_patreon_members pm2 WHERE pm2.wp_user_id=r.wp_user_id),'\\N'),
  IFNULL((SELECT pm3.tier_label FROM lg_membership.lg_patreon_members pm3 WHERE pm3.wp_user_id=r.wp_user_id),'\\N'),
  r.updated_at
FROM lg_membership.lg_role_sources r
LEFT JOIN looth_import.wp_users u ON u.ID=r.wp_user_id
WHERE r.source='patreon' AND r.tier IS NULL
ORDER BY r.wp_user_id;
