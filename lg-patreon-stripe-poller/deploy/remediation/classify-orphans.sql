SELECT r.wp_user_id,
  IFNULL(r.tier,'\\N'),
  IFNULL(u.user_email,''),
  IFNULL((SELECT p.tier FROM lg_membership.lg_role_sources p WHERE p.wp_user_id=r.wp_user_id AND p.source='patreon'),'\\N'),
  (SELECT COUNT(*) FROM lg_membership.lg_role_sources p2 WHERE p2.wp_user_id=r.wp_user_id AND p2.source='patreon'),
  IFNULL((SELECT m.tier FROM lg_membership.lg_role_sources m WHERE m.wp_user_id=r.wp_user_id AND m.source='manual_admin'),'\\N'),
  (SELECT COUNT(*) FROM lg_membership.lg_role_sources m2 WHERE m2.wp_user_id=r.wp_user_id AND m2.source='manual_admin'),
  (SELECT GROUP_CONCAT(s.source) FROM lg_membership.lg_role_sources s WHERE s.wp_user_id=r.wp_user_id),
  IFNULL((SELECT um.meta_value FROM looth_import.wp_usermeta um WHERE um.user_id=r.wp_user_id AND um.meta_key='payment_source'),'\\N'),
  IFNULL((SELECT um2.meta_value FROM looth_import.wp_usermeta um2 WHERE um2.user_id=r.wp_user_id AND um2.meta_key='lgpo_patreon_tier_id'),'\\N'),
  IFNULL((SELECT um3.meta_value FROM looth_import.wp_usermeta um3 WHERE um3.user_id=r.wp_user_id AND um3.meta_key='wp_capabilities'),'\\N'),
  IFNULL((SELECT pm.patron_status FROM lg_membership.lg_patreon_members pm WHERE pm.wp_user_id=r.wp_user_id),'\\N'),
  IFNULL((SELECT pm2.currently_entitled_amount_cents FROM lg_membership.lg_patreon_members pm2 WHERE pm2.wp_user_id=r.wp_user_id),'\\N'),
  IFNULL((SELECT pm3.tier_label FROM lg_membership.lg_patreon_members pm3 WHERE pm3.wp_user_id=r.wp_user_id),'\\N'),
  r.updated_at
FROM lg_membership.lg_role_sources r
LEFT JOIN looth_import.wp_users u ON u.ID=r.wp_user_id
WHERE r.source='stripe' AND r.wp_user_id NOT IN (SELECT wp_user_id FROM lg_membership.wp_user_bridge)
ORDER BY r.wp_user_id;
