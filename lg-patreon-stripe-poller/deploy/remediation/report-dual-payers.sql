-- ============================================================================
-- #149 — WHO IS PAYING ON BOTH RAILS TODAY?
--
--   mysql --defaults-file=… < report-dual-payers.sql
--   (dev2)  mysql -u<billing-user> -p<pw> < report-dual-payers.sql
--   (live)  ssh live-ro 'mysql --defaults-file=/home/looth-ro/.my.cnf' < report-dual-payers.sql
--
-- READ ONLY. Every statement here is a SELECT — deliberately, so that a person
-- about to run it against LIVE can satisfy themselves of that by reading it,
-- rather than trusting a script. Nothing about these members is changed by this
-- lane; the repair, if Ian wants one, is a separate decision on #149.
--
-- WHAT "PAYING PATREON" MEANS HERE: patron_status = 'active_patron' AND a
-- positive currently_entitled_amount_cents. That is the money question, and it
-- is the same predicate LGMS\Membership\PatreonStanding falls back on. It does
-- NOT consult lgpo_tier_map, because that option is a PHP-SERIALIZED array that
-- SQL cannot read — and it does not need to: a mapped paid tier always carries
-- a positive amount, so the map can only ever widen this set, never narrow it.
--
-- TWO WAYS A MEMBER IS TIED TO A STRIPE CUSTOMER, and both are reported with
-- which one matched:
--   bridge — wp_user_bridge, the authoritative link the lifecycle writes
--   email  — the address matching either the WP account or the Patreon record
-- The email path exists because the bridge is only written for members the
-- lifecycle has actually processed; an older customer may have no bridge row
-- and still be the same person. It can also be WRONG (a shared family address),
-- so the column is printed rather than silently merged.
--
-- ⚠️ THE EXPLICIT `COLLATE` IS load-BEARING. The two databases were created with
-- different collations (lg_membership utf8mb4_unicode_ci, the WordPress database
-- utf8mb4_unicode_520_ci), so comparing an email across them raises ERROR 1267
-- rather than returning nothing. Removing it does not make the query loose — it
-- makes it refuse to run.
--
-- `payment_source` is printed for information ONLY. It is one slot the two
-- rails overwrite each other in, and no row here is included or excluded
-- because of it. Expect it to disagree with reality; that disagreement is
-- half of what #149 is about.
-- ============================================================================

SELECT '=== 1. SIZE OF EACH RAIL ===' AS report;

SELECT
    (SELECT COUNT(*) FROM lg_membership.lg_patreon_members)                                     AS patreon_rows,
    (SELECT COUNT(*) FROM lg_membership.lg_patreon_members
      WHERE patron_status = 'active_patron')                                                    AS active_patrons,
    (SELECT COUNT(*) FROM lg_membership.lg_patreon_members
      WHERE patron_status = 'active_patron' AND currently_entitled_amount_cents > 0)            AS paying_patrons,
    (SELECT COUNT(*) FROM lg_membership.customers WHERE deleted_at IS NULL)                     AS stripe_customers,
    (SELECT COUNT(*) FROM lg_membership.subscriptions
      WHERE status IN ('active','trialing','past_due'))                                         AS stripe_live_subs,
    (SELECT COUNT(*) FROM lg_membership.wp_user_bridge)                                         AS bridge_rows;

SELECT '=== 2. DUAL PAYERS — one row per member, both rails live ===' AS report;

SELECT
    p.wp_user_id,
    u.user_login,
    u.user_email                                AS wp_email,
    p.email                                     AS patreon_email,
    p.patron_status,
    p.tier_label                                AS patreon_tier,
    p.currently_entitled_amount_cents           AS patreon_cents,
    p.next_charge_date                          AS patreon_next_charge,
    c.id                                        AS customer_id,
    c.email                                     AS stripe_email,
    s.status                                    AS stripe_status,
    s.current_period_end                        AS stripe_period_end,
    prod.ref                                    AS stripe_tier,
    pr.unit_amount_cents                        AS stripe_cents,
    pr.`interval`                               AS stripe_interval,
    CASE WHEN b.wp_user_id IS NOT NULL THEN 'bridge' ELSE 'email' END AS matched_by,
    COALESCE(ps.meta_value, '(unset)')          AS payment_source_says
FROM       lg_membership.lg_patreon_members p
JOIN       looth_import.wp_users            u    ON u.ID = p.wp_user_id
LEFT JOIN  lg_membership.wp_user_bridge     b    ON b.wp_user_id = p.wp_user_id
JOIN       lg_membership.customers          c    ON c.deleted_at IS NULL
                                                AND ( c.id = b.customer_id
                                                   OR c.email = u.user_email COLLATE utf8mb4_unicode_ci
                                                   OR c.email = p.email )
JOIN       lg_membership.subscriptions      s    ON s.customer_id = c.id
                                                AND s.status IN ('active','trialing','past_due')
LEFT JOIN  lg_membership.prices             pr   ON pr.stripe_price_id = s.stripe_price_id
LEFT JOIN  lg_membership.products           prod ON prod.id = pr.product_id
LEFT JOIN  looth_import.wp_usermeta         ps   ON ps.user_id = p.wp_user_id
                                                AND ps.meta_key = 'payment_source'
WHERE p.patron_status = 'active_patron'
  AND p.currently_entitled_amount_cents > 0
GROUP BY p.wp_user_id, s.id
ORDER BY p.wp_user_id;

SELECT '=== 3. THE COUNT, on its own ===' AS report;

SELECT COUNT(DISTINCT p.wp_user_id) AS dual_payers
FROM       lg_membership.lg_patreon_members p
LEFT JOIN  lg_membership.wp_user_bridge     b ON b.wp_user_id = p.wp_user_id
JOIN       looth_import.wp_users            u ON u.ID = p.wp_user_id
JOIN       lg_membership.customers          c ON c.deleted_at IS NULL
                                             AND ( c.id = b.customer_id
                                                OR c.email = u.user_email COLLATE utf8mb4_unicode_ci
                                                OR c.email = p.email )
JOIN       lg_membership.subscriptions      s ON s.customer_id = c.id
                                             AND s.status IN ('active','trialing','past_due')
WHERE p.patron_status = 'active_patron'
  AND p.currently_entitled_amount_cents > 0;

SELECT '=== 4. THE FROZEN-OPINION SHAPE (#149 originals) ===' AS report;
-- Members carrying payment_source=stripe AND a paid role. The Patreon sweep
-- SKIPS these (class-lgpo-sync-engine.php:581), so their Patreon opinion is
-- frozen at whatever it last said. If they cancel Stripe while still paying
-- Patreon, the Stripe opinion retracts and the stale Patreon one cannot save
-- them — a still-paying patron drops to looth1. Reported, not touched.

SELECT
    ps.user_id                                  AS wp_user_id,
    u.user_login,
    u.user_email,
    GROUP_CONCAT(DISTINCT r.meta_value)         AS wp_capabilities,
    p.patron_status                             AS patreon_says,
    p.currently_entitled_amount_cents           AS patreon_cents,
    p.synced_at                                 AS patreon_row_last_CHANGED
FROM      looth_import.wp_usermeta ps
JOIN      looth_import.wp_users    u ON u.ID = ps.user_id
LEFT JOIN looth_import.wp_usermeta r ON r.user_id = ps.user_id AND r.meta_key = 'wp_capabilities'
LEFT JOIN lg_membership.lg_patreon_members p ON p.wp_user_id = ps.user_id
WHERE ps.meta_key = 'payment_source'
  AND ps.meta_value = 'stripe'
  AND ( r.meta_value LIKE '%looth2%' OR r.meta_value LIKE '%looth3%' )
GROUP BY ps.user_id
ORDER BY ps.user_id;
