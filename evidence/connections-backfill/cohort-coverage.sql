-- Per-member coverage of the LATE-bridged cohort vs the AT-CUTOVER cohort.
-- Read-only. Cohort boundary: wp_user_bridge.synced_at < 2026-06-03 = at cutover.
\pset footer off
WITH coh AS (
  SELECT b.user_id, u.uuid,
         CASE WHEN b.synced_at < '2026-06-03' THEN 'cutover' ELSE 'late' END AS cohort
  FROM wp_user_bridge b JOIN users u ON u.id = b.user_id
),
tot AS (SELECT cohort, count(*) n FROM coh GROUP BY cohort),
hit AS (
  SELECT 'profiles'            t, cohort, count(DISTINCT c.user_id) n FROM coh c JOIN profiles           x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_socials',     cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_socials     x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_instruments', cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_instruments x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_genres',      cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_genres      x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_skills',      cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_skills      x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_services',    cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_services    x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_scenes',      cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_scenes      x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_highlights',  cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_highlights  x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'profile_sections',    cohort, count(DISTINCT c.user_id) FROM coh c JOIN profile_sections    x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'slug_history',        cohort, count(DISTINCT c.user_id) FROM coh c JOIN slug_history        x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'email_aliases',       cohort, count(DISTINCT c.user_id) FROM coh c JOIN email_aliases       x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'practice_members',    cohort, count(DISTINCT c.user_id) FROM coh c JOIN practice_members    x ON x.user_id=c.user_id GROUP BY cohort
  UNION ALL SELECT 'chapter_member',      cohort, count(DISTINCT c.user_id) FROM coh c JOIN chapter_member      x ON x.user_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'chapter_post',        cohort, count(DISTINCT c.user_id) FROM coh c JOIN chapter_post        x ON x.author_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'notifications',       cohort, count(DISTINCT c.user_id) FROM coh c JOIN notifications       x ON x.user_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'message_recipients',  cohort, count(DISTINCT c.user_id) FROM coh c JOIN message_recipients  x ON x.user_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'messages(sent)',      cohort, count(DISTINCT c.user_id) FROM coh c JOIN messages            x ON x.sender_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'message_reactions',   cohort, count(DISTINCT c.user_id) FROM coh c JOIN message_reactions   x ON x.user_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'user_mutes',          cohort, count(DISTINCT c.user_id) FROM coh c JOIN user_mutes          x ON x.muter_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'connections',         cohort, count(DISTINCT c.user_id) FROM coh c JOIN connections         x ON x.requester_uuid=c.uuid OR x.addressee_uuid=c.uuid GROUP BY cohort
  UNION ALL SELECT 'users.location set',  cohort, count(DISTINCT c.user_id) FROM coh c JOIN users u2 ON u2.id=c.user_id AND u2.lat IS NOT NULL GROUP BY cohort
  UNION ALL SELECT 'users.avatar set',    cohort, count(DISTINCT c.user_id) FROM coh c JOIN users u2 ON u2.id=c.user_id AND u2.avatar_url IS NOT NULL GROUP BY cohort
  UNION ALL SELECT 'users.at_a_glance',   cohort, count(DISTINCT c.user_id) FROM coh c JOIN users u2 ON u2.id=c.user_id AND u2.at_a_glance IS NOT NULL GROUP BY cohort
  UNION ALL SELECT 'users.banner set',    cohort, count(DISTINCT c.user_id) FROM coh c JOIN users u2 ON u2.id=c.user_id AND u2.banner_url IS NOT NULL GROUP BY cohort
  UNION ALL SELECT 'users.resume set',    cohort, count(DISTINCT c.user_id) FROM coh c JOIN users u2 ON u2.id=c.user_id AND u2.resume_url IS NOT NULL GROUP BY cohort
  UNION ALL SELECT 'users.profile_layout',cohort, count(DISTINCT c.user_id) FROM coh c JOIN users u2 ON u2.id=c.user_id AND u2.profile_layout IS NOT NULL GROUP BY cohort
)
SELECT rpad(h.t,22)
    || rpad(to_char(100.0*COALESCE(cut.n,0)/ct.n,'990.9')||'%',9)
    || rpad(to_char(100.0*COALESCE(lat.n,0)/lt.n,'990.9')||'%',9)
    || rpad(COALESCE(cut.n,0)||'/'||ct.n, 12)
    || rpad(COALESCE(lat.n,0)||'/'||lt.n, 10)
    || CASE WHEN COALESCE(lat.n,0)::numeric/lt.n < 0.5 * (COALESCE(cut.n,0)::numeric/ct.n)
            THEN '  <-- LATE COHORT UNDER HALF' ELSE '' END AS "table                 cutover%  late%    cutover     late      flag"
FROM (SELECT DISTINCT t FROM hit) h
LEFT JOIN hit cut ON cut.t=h.t AND cut.cohort='cutover'
LEFT JOIN hit lat ON lat.t=h.t AND lat.cohort='late'
CROSS JOIN (SELECT n FROM tot WHERE cohort='cutover') ct
CROSS JOIN (SELECT n FROM tot WHERE cohort='late') lt
ORDER BY (COALESCE(lat.n,0)::numeric/lt.n) / NULLIF(COALESCE(cut.n,0)::numeric/ct.n,0) NULLS FIRST, h.t;
