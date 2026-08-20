<?php

declare(strict_types=1);

namespace LGMS;

final class Admin
{
    private const OPT_GROUP = 'lgms_settings';
    private const OPT_PAGE  = 'lg-member-sync';
    private const AFF_PAGE  = 'lg-affiliates';

    public static function boot(): void
    {
        add_action( 'admin_menu',  [ self::class, 'menu' ] );
        add_action( 'admin_init',  [ self::class, 'registerSettings' ] );
        add_action( 'admin_enqueue_scripts', [ self::class, 'enqueueScripts' ] );
        add_action( 'admin_post_lgms_rerun_pages',       [ self::class, 'handleRerunPages' ] );
        add_action( 'admin_post_lgms_save_welcome_mosaic', [ self::class, 'handleSaveMosaic' ] );
        add_action( 'admin_post_lgms_create_affiliate',          [ self::class, 'handleCreateAffiliate' ] );
        add_action( 'admin_post_lgms_update_affiliate_commission', [ self::class, 'handleUpdateAffiliateCommission' ] );
        add_action( 'wp_ajax_lgms_search_posts', [ self::class, 'ajaxSearchPosts' ] );
        add_action( 'wp_ajax_lgms_search_users', [ self::class, 'ajaxSearchUsers' ] );
        add_action( 'admin_post_lgms_create_affiliate_user', [ self::class, 'handleCreateAffiliateUser' ] );
        add_action( 'admin_post_lgms_cohort_lookup', [ self::class, 'handleCohortLookup' ] );
        add_action( 'admin_post_lgms_cohort_add',    [ self::class, 'handleCohortAdd' ] );
        add_action( 'admin_post_lgms_cohort_remove', [ self::class, 'handleCohortRemove' ] );
        add_action( 'admin_post_lgms_invite_mint',   [ self::class, 'handleInviteMint' ] );
        add_action( 'admin_post_lgms_price_set', [ self::class, 'handlePriceSet' ] );
    }

    public static function menu(): void
    {
        add_options_page(
            'LG Member Sync',
            'LG Member Sync',
            'manage_options',
            self::OPT_PAGE,
            [ self::class, 'render' ],
        );

        add_menu_page(
            'Affiliates',
            'Affiliates',
            'manage_options',
            self::AFF_PAGE,
            [ self::class, 'renderAffiliatePage' ],
            'dashicons-groups',
            30,
        );
    }

    public static function registerSettings(): void
    {
        $fields = [
            'lgms_db_host'                    => '127.0.0.1',
            'lgms_db_port'                    => '3306',
            'lgms_db_name'                    => 'lg_membership',
            'lgms_db_user'                    => 'lg_membership',
            'lgms_db_pass'                    => '',
            'lgms_stripe_secret_key'          => '',
            'lgms_shared_secret'              => '',
            'lgms_refund_email'               => '',
            'lgms_refund_window_days'         => '30',
            'lgms_plan_switch_cooldown_hours' => '24',
        ];
        foreach ( $fields as $key => $_default ) {
            register_setting( self::OPT_GROUP, $key, [
                'sanitize_callback' => 'sanitize_text_field',
            ]);
        }
    }

    public static function enqueueScripts( string $hook ): void
    {
        if ( $hook === 'settings_page_' . self::OPT_PAGE ) {
            $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'settings';
            if ( $tab === 'welcome_email' ) {
                wp_enqueue_media();
            }
        }
    }

    // -------------------------------------------------------------------------
    // admin-post handlers
    // -------------------------------------------------------------------------

    public static function handleRerunPages(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_rerun_pages' );

        $result = Wp\Pages::ensureAll();
        $msg = sprintf(
            'created=%d skipped=%d allowlisted=%d',
            count( $result['created'] ),
            count( $result['skipped'] ),
            count( $result['allowlisted'] )
        );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::OPT_PAGE, 'lgms_pages' => rawurlencode( $msg ) ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handleSaveMosaic(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_save_welcome_mosaic' );

        $raw = isset( $_POST['mosaic_ids'] ) && is_array( $_POST['mosaic_ids'] )
            ? array_map( 'absint', $_POST['mosaic_ids'] )
            : [];

        $ids = array_values( array_filter( $raw ) );
        update_option( 'lgms_welcome_mosaic_ids', wp_json_encode( $ids ) );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::OPT_PAGE, 'tab' => 'welcome_email', 'lgms_mosaic_saved' => '1' ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Stripe soft-launch cohort (docs/STRIPE-SOFT-LAUNCH-ALLOWLIST.md)
    // -------------------------------------------------------------------------

    /**
     * Deterministic, loud resolution — never a guess: all digits = user ID,
     * contains @ = email, anything else = login. One column each; no
     * fuzzy fallback, so "who did this store?" always has one answer.
     */
    private static function resolveCohortUser( string $q ): ?\WP_User
    {
        if ( $q === '' ) {
            return null;
        }
        if ( ctype_digit( $q ) ) {
            return get_user_by( 'id', (int) $q ) ?: null;
        }
        if ( str_contains( $q, '@' ) ) {
            return get_user_by( 'email', $q ) ?: null;
        }
        return get_user_by( 'login', $q ) ?: null;
    }

    private static function cohortRedirect( array $extra ): void
    {
        wp_safe_redirect( add_query_arg(
            array_merge( [ 'page' => self::OPT_PAGE, 'tab' => 'stripe_cohort' ], $extra ),
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /** Step 1 of add: resolve the input and bounce to the confirm panel. */
    public static function handleCohortLookup(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_cohort_lookup' );

        $q = trim( sanitize_text_field( (string) ( $_POST['cohort_query'] ?? '' ) ) );
        if ( $q === '' ) {
            self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode( 'Enter an email, login, or user ID.' ) ] );
        }

        $u = self::resolveCohortUser( $q );
        if ( $u === null ) {
            self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode(
                "No user on this box matches \"{$q}\" — nothing stored. Check the value; user IDs differ per box."
            ) ] );
        }
        self::cohortRedirect( [ 'lgms_cohort_confirm' => (int) $u->ID ] );
    }

    /** Step 2 of add: the id from the confirm panel, re-verified at write time. */
    public static function handleCohortAdd(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_cohort_add' );

        $uid = (int) ( $_POST['cohort_user_id'] ?? 0 );
        $u   = $uid > 0 ? get_user_by( 'id', $uid ) : false;
        if ( ! $u ) {
            // Never store an id this box cannot name, even one a stale
            // confirm panel asserted.
            self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode( "User #{$uid} does not exist on this box — nothing stored." ) ] );
        }

        if ( CohortAllowlist::add( $uid ) ) {
            self::cohortRedirect( [ 'lgms_cohort_ok' => rawurlencode( sprintf(
                'Added to the test group: #%d %s (%s).', $uid, $u->user_login, $u->user_email
            ) ) ] );
        }
        self::cohortRedirect( [ 'lgms_cohort_ok' => rawurlencode( sprintf(
            '#%d %s is already in the test group — nothing changed.', $uid, $u->user_login
        ) ) ] );
    }

    /**
     * MINT AN INVITE for an email that has no account yet.
     *
     * Ian, 2026-08-16: the test group took only EXISTING wp users, so the
     * rehearsal that matters most before cutover — a fresh recruit's whole join
     * — could not be run at all. This is where he generates the link.
     *
     * THE LINK IS SHOWN EXACTLY ONCE, because the raw token is never stored: the
     * option holds only a hash of it, so nobody can look a lost link up and
     * nobody reading the database can replay one. Losing it means minting
     * another, which is cheap and is the safe direction.
     */
    public static function handleInviteMint(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_invite_mint' );

        $email = strtolower( trim( (string) ( $_POST['invite_email'] ?? '' ) ) );
        if ( $email === '' || ! is_email( $email ) ) {
            self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode( 'That is not an email address — nothing minted.' ) ] );
        }

        // An address that ALREADY has an account does not need an invite, and
        // handing one over would be misleading: the list is what admits them,
        // and adding them to it is one click below.
        if ( ( $existing = get_user_by( 'email', $email ) ) instanceof \WP_User ) {
            self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode( sprintf(
                '%s already has an account (#%d %s) — add them to the test group directly instead of inviting them.',
                $email, $existing->ID, $existing->user_login
            ) ) ] );
        }

        $inv = Invites::mint( $email );
        if ( $inv === null ) {
            self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode( sprintf(
                '%s already has a live invite that has not been used — send them that link rather than a second one.', $email
            ) ) ] );
        }

        self::cohortRedirect( [ 'lgms_invite_link' => rawurlencode( $inv['url'] ),
                                'lgms_invite_for'  => rawurlencode( $email ),
                                'lgms_invite_exp'  => (string) $inv['expires'] ] );
    }

    public static function handleCohortRemove(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        $uid = (int) ( $_POST['cohort_user_id'] ?? 0 );
        check_admin_referer( 'lgms_cohort_remove_' . $uid );

        if ( CohortAllowlist::remove( $uid ) ) {
            $u = get_user_by( 'id', $uid );
            self::cohortRedirect( [ 'lgms_cohort_ok' => rawurlencode( sprintf(
                'Removed #%d%s from the test group. The Stripe system no longer touches them in EITHER direction — if they should lose access, retract by hand.',
                $uid, $u ? ' ' . $u->user_login : ''
            ) ) ] );
        }
        self::cohortRedirect( [ 'lgms_cohort_err' => rawurlencode( "#{$uid} was not in the cohort." ) ] );
    }

    // -------------------------------------------------------------------------
    // Stripe price (Ian 2026-08-15: "I'd like to be able to set the price. In
    // the dash.") — the WRITE goes through LGMS\StripePrice, which creates the
    // Stripe price, records it in our own prices table, and repoints new joins
    // as ONE action. See that class for why the middle step is load-bearing.
    // -------------------------------------------------------------------------

    private static function priceRedirect( array $extra ): void
    {
        wp_safe_redirect( add_query_arg(
            array_merge( [ 'page' => self::OPT_PAGE, 'tab' => 'stripe_price' ], $extra ),
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handlePriceSet(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Nope.' );
        }
        check_admin_referer( 'lgms_price_set' );

        try {
            $cents    = StripePrice::parseAmount( (string) ( $_POST['price_amount'] ?? '' ) );
            $interval = StripePrice::assertInterval( sanitize_key( (string) ( $_POST['price_interval'] ?? '' ) ) );
            // MULTI-TIER (#148). Validated against the catalogue, never trusted:
            // assertTier() refuses a tier that has no registered product rather
            // than falling back to the default, because a silent fallback here
            // would put a price on the wrong membership.
            $tier = StripePrice::assertTier( sanitize_key( (string) ( $_POST['price_tier'] ?? '' ) ) );

            // Typing the amount twice is cheaper than an accidental price. A
            // Stripe price cannot be deleted once made, only deactivated.
            $confirm = StripePrice::parseAmount( (string) ( $_POST['price_amount_confirm'] ?? '' ) );
            if ( $confirm !== $cents ) {
                throw new \RuntimeException( 'The two amounts do not match — nothing was changed.' );
            }

            $set = StripePrice::setPrice( $cents, $interval, $tier );
        } catch ( \Throwable $e ) {
            self::priceRedirect( [ 'lgms_price_err' => rawurlencode( $e->getMessage() ) ] );
            return;
        }

        self::priceRedirect( [ 'lgms_price_ok' => rawurlencode( sprintf(
            'New %s members will now pay %s %s. Everyone already subscribed keeps the price they joined on.',
            $set['product_name'],
            StripePrice::money( $set['unit_amount_cents'] ),
            $set['interval'] === 'year' ? 'a year' : 'a month'
        ) ) ] );
    }

    private static function renderStripePriceTab(): void
    {
        $ok  = isset( $_GET['lgms_price_ok'] )  ? rawurldecode( (string) $_GET['lgms_price_ok'] )  : '';
        $err = isset( $_GET['lgms_price_err'] ) ? rawurldecode( (string) $_GET['lgms_price_err'] ) : '';

        // MULTI-TIER (#148): the tiers come from the CATALOGUE, so registering a
        // product is the whole of adding a tier and this tab needs no edit to
        // show it. Each is resolved to its product independently — one tier
        // with an ambiguous catalogue must not blank the page for the others.
        $tiers      = StripePrice::tiers();
        $products   = [];
        $tierErrs   = [];
        foreach ( $tiers as $t ) {
            try {
                $products[ $t ] = StripePrice::tierProduct( $t );
            } catch ( \Throwable $e ) {
                $tierErrs[ $t ] = $e->getMessage();
            }
        }

        $productErr = '';
        if ( $tiers === [] ) {
            $productErr = 'The Stripe catalogue has not been imported on this box, so there is nothing to price.';
        }

        $modeErr = '';
        try {
            StripePrice::assertTestMode();
        } catch ( \Throwable $e ) {
            $modeErr = $e->getMessage();
        }

        if ( $ok !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $ok ); ?></p></div>
        <?php endif;
        if ( $err !== '' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
        <?php endif; ?>

        <h2>Membership prices</h2>
        <p class="description" style="max-width:720px;">
            This is what somebody pays to join, for each membership you offer.
            <strong>Changing a price only affects people who join afterwards</strong> — everybody already
            subscribed keeps paying the price they joined on, and nothing here can change that.
        </p>
        <p class="description" style="max-width:720px;">
            The memberships themselves are not created here — they come from the Stripe catalogue.
            To offer a new one, register its product and import the catalogue; it then appears below
            ready to be priced.
        </p>

        <?php if ( $modeErr !== '' ) : ?>
            <div class="notice notice-error inline" style="max-width:720px;"><p><?php echo esc_html( $modeErr ); ?></p></div>
        <?php endif; ?>

        <?php if ( $productErr !== '' ) : ?>
            <div class="notice notice-error inline" style="max-width:720px;"><p><?php echo esc_html( $productErr ); ?></p></div>
        <?php endif; ?>

        <h3>Right now</h3>
        <?php
          // TIER x CADENCE. Tier is WHAT they get, cadence is HOW OFTEN they pay
          // — two different questions that were the same one under the old
          // single-tier ruling, which is why the copy below spells them apart.
          $anyAtAll = false;
          foreach ( $tiers as $t ) {
              if ( StripePrice::configuredCadences( $t ) !== [] ) { $anyAtAll = true; }
          }
        ?>
        <?php if ( $tiers === [] ) : ?>
          <p><strong>No memberships are registered</strong>, so there is nothing to price yet.</p>
        <?php elseif ( ! $anyAtAll ) : ?>
          <p><strong>No price is set yet</strong>, so nobody can join. That is the intended state until
             you decide the numbers.</p>
        <?php endif; ?>

        <?php if ( $tiers !== [] ) : ?>
          <table class="widefat striped" style="max-width:860px;">
            <thead><tr>
              <th style="width:14em;">Membership</th>
              <th style="width:9em;">Billed</th>
              <th>New members pay</th>
              <th>Stripe reference</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $tiers as $t ) :
                    $label   = $products[ $t ]['name'] ?? $t;
                    $offered = StripePrice::configuredCadences( $t );
                    $first   = true;
                    foreach ( StripePrice::CADENCES as $cad => $cadLabel ) :
                        $cur  = StripePrice::currentPrice( $cad, $t );
                        $orph = StripePrice::currentPriceIsOrphaned( $cad, $t ); ?>
              <tr>
                <?php if ( $first ) : ?>
                  <td rowspan="<?php echo count( StripePrice::CADENCES ); ?>">
                    <strong><?php echo esc_html( $label ); ?></strong><br>
                    <code style="font-size:11px;"><?php echo esc_html( $t ); ?></code>
                    <?php if ( isset( $tierErrs[ $t ] ) ) : ?>
                      <br><span style="color:#8a3208;"><?php echo esc_html( $tierErrs[ $t ] ); ?></span>
                    <?php elseif ( $offered === [] ) : ?>
                      <br><span style="color:#666;">not on sale</span>
                    <?php endif; ?>
                  </td>
                <?php endif; $first = false; ?>
                <td><?php echo esc_html( $cadLabel ); ?></td>
                <?php if ( $orph ) : ?>
                  <td colspan="2" style="color:#8a3208;">
                    Pointed at a price this site has no record of
                    (<code><?php echo esc_html( StripePrice::currentPriceId( $cad, $t ) ); ?></code>) —
                    <strong>set it again before anybody joins.</strong>
                  </td>
                <?php elseif ( $cur === null ) : ?>
                  <td colspan="2" style="color:#666;">not set — this cadence is not offered</td>
                <?php else : ?>
                  <td><strong><?php echo esc_html( StripePrice::money( $cur['unit_amount_cents'], $cur['currency'] ) ); ?></strong>
                      <?php echo esc_html( $cad === 'year' ? 'a year' : 'a month' ); ?></td>
                  <td><code><?php echo esc_html( $cur['stripe_price_id'] ); ?></code></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
          </table>
          <p class="description" style="max-width:860px;">A member picks a membership and a billing
             rhythm at checkout. Leave a row unset and it is simply not offered; leave a whole
             membership unpriced and it is not on sale at all.</p>
        <?php endif; ?>

        <h3>Set a new price</h3>
        <?php if ( $modeErr !== '' || $productErr !== '' ) : ?>
            <p class="description">Not available until the problem above is resolved.</p>
        <?php else : ?>
            <p class="description" style="max-width:720px;">
                A price cannot be deleted from Stripe once it is made, only replaced — so the amount is
                typed twice on purpose. Setting a price also retires the one it replaces, so a
                membership never shows two different prices for the same billing rhythm.
            </p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'lgms_price_set' ); ?>
                <input type="hidden" name="action" value="lgms_price_set">
                <table class="form-table" style="max-width:720px;">
                    <tr>
                        <th scope="row"><label for="lgms_price_tier">Membership</label></th>
                        <td><select name="price_tier" id="lgms_price_tier">
                            <?php foreach ( $tiers as $t ) : ?>
                                <option value="<?php echo esc_attr( $t ); ?>"
                                    <?php disabled( isset( $tierErrs[ $t ] ) ); ?>>
                                    <?php echo esc_html( ( $products[ $t ]['name'] ?? $t ) . ' (' . $t . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                            </select>
                            <p class="description">Which membership this price is for.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lgms_price_amount">Price</label></th>
                        <td><input name="price_amount" id="lgms_price_amount" type="text" class="regular-text"
                                   inputmode="decimal" placeholder="12.00" required>
                            <p class="description">In dollars, like 12 or 12.50.</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lgms_price_amount_confirm">Price again</label></th>
                        <td><input name="price_amount_confirm" id="lgms_price_amount_confirm" type="text"
                                   class="regular-text" inputmode="decimal" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="lgms_price_interval">Billed</label></th>
                        <td><select name="price_interval" id="lgms_price_interval">
                            <?php foreach ( StripePrice::INTERVALS as $val => $label ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                            </select></td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">Set the price for new members</button></p>
            </form>
        <?php endif;
    }

    private static function renderStripeCohortTab(): void
    {
        $ok  = isset( $_GET['lgms_cohort_ok'] )  ? rawurldecode( (string) $_GET['lgms_cohort_ok'] )  : '';
        $err = isset( $_GET['lgms_cohort_err'] ) ? rawurldecode( (string) $_GET['lgms_cohort_err'] ) : '';
        $confirmId = (int) ( $_GET['lgms_cohort_confirm'] ?? 0 );

        /**
         * THE INVITE PANEL. Shown with the list because it answers the same
         * question from the other end: the list admits people who HAVE an
         * account, this admits one who does not have one yet.
         */
        $inviteLink = isset( $_GET['lgms_invite_link'] ) ? (string) $_GET['lgms_invite_link'] : '';
        $inviteFor  = isset( $_GET['lgms_invite_for'] )  ? (string) $_GET['lgms_invite_for']  : '';
        $inviteExp  = isset( $_GET['lgms_invite_exp'] )  ? (int) $_GET['lgms_invite_exp']     : 0;
        $invitesOn  = ( get_option( Invites::FLAG, '' ) === '1' );
        ?>
        <h2>Invite someone who has no account yet</h2>
        <p class="description">
            The test group only takes people who already have an account. This mints a
            one-time link for an email address, so a fresh recruit can walk the whole
            join. The link opens the join flow <strong>only</strong>, expires, and is spent
            by the account it creates — which is then added to the list automatically.
        </p>
        <?php if ( ! $invitesOn ) : ?>
            <p><strong>Invites are switched off on this box.</strong> A minted link will not
            admit anyone until <code>lgms_stripe_invites_on</code> is set. Minting one now is
            harmless — it simply will not work yet.</p>
        <?php endif; ?>
        <?php if ( $inviteLink !== '' ) : ?>
            <div class="notice notice-success"><p>
                <strong>Invite for <?php echo esc_html( $inviteFor ); ?></strong> —
                expires <?php echo esc_html( gmdate( 'Y-m-d', $inviteExp ) ); ?>.<br>
                <input type="text" readonly style="width:100%;font-family:monospace"
                       value="<?php echo esc_attr( $inviteLink ); ?>"
                       onclick="this.select()"><br>
                <em>Copy it now — this is the only time it is shown. The link is not stored
                anywhere, only a hash of it, so it cannot be looked up later. If it is lost,
                mint another.</em>
            </p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="lgms_invite_mint">
            <?php wp_nonce_field( 'lgms_invite_mint' ); ?>
            <input type="email" name="invite_email" required style="min-width:22em"
                   placeholder="someone@example.com">
            <button class="button button-primary">Mint an invite link</button>
        </form>
        <?php

        $ids         = CohortAllowlist::ids();
        $lifecycleOn = StripeLifecycle::flagOn();
        $gateOn      = (bool) get_option( StripeLifecycle::IDENTITY_GATE_OPT, false );

        if ( $ok !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $ok ); ?></p></div>
        <?php endif;
        if ( $err !== '' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
        <?php endif; ?>

        <h2>Stripe test group — the members allowed into the live test</h2>
        <p class="description" style="max-width:720px;">
            Only members on this list transition through the Stripe webhook lifecycle. Everyone else's
            events are acknowledged and journaled but change <strong>nothing</strong> — an
            <strong>empty list means closed for everyone</strong>, even with the lifecycle flag on.
            Removing a member freezes them (cancellations are skipped too); it does not take their
            access away.
        </p>

        <p>
            <span class="lgms-chip" style="background:<?php echo $lifecycleOn ? '#dcfce7;color:#15803d' : '#f0f0f1;color:#666'; ?>;">lifecycle <?php echo $lifecycleOn ? 'ON' : 'OFF'; ?></span>
            <span class="lgms-chip" style="background:<?php echo $gateOn ? '#dcfce7;color:#15803d' : '#f0f0f1;color:#666'; ?>;">identity gate <?php echo $gateOn ? 'ON' : 'OFF'; ?></span>
            <span class="lgms-chip" style="background:#e0f2fe;color:#0369a1;">in the test group: <?php echo count( $ids ); ?></span>
            <style>.lgms-chip { display:inline-block; padding:.15em .55em; border-radius:3px; font-size:.85em; font-weight:600; margin-right:.4em; }</style>
        </p>

        <?php if ( $confirmId > 0 ) :
            $cu = get_user_by( 'id', $confirmId );
            if ( ! $cu ) : ?>
                <div class="notice notice-error"><p>User #<?php echo $confirmId; ?> no longer exists on this box — nothing stored.</p></div>
            <?php elseif ( in_array( $confirmId, $ids, true ) ) : ?>
                <div class="notice notice-info"><p>#<?php echo $confirmId; ?> <strong><?php echo esc_html( $cu->user_login ); ?></strong> is already in the test group.</p></div>
            <?php else : ?>
                <div style="border:1px solid #b8d0f0;background:#f0f6ff;border-radius:4px;padding:1em 1.2em;max-width:560px;margin-bottom:1.5em;">
                    <p style="margin:0 0 .6em;font-weight:600;">Confirm before it lands — is this the right member?</p>
                    <table class="widefat" style="margin-bottom:.8em;max-width:520px;">
                        <tr><th style="width:8em;">User ID</th><td>#<?php echo (int) $cu->ID; ?></td></tr>
                        <tr><th>Login</th><td><?php echo esc_html( $cu->user_login ); ?></td></tr>
                        <tr><th>Email</th><td><?php echo esc_html( $cu->user_email ); ?></td></tr>
                        <tr><th>Display name</th><td><?php echo esc_html( $cu->display_name ); ?></td></tr>
                        <tr><th>Roles</th><td><?php echo esc_html( implode( ', ', (array) $cu->roles ) ?: '—' ); ?></td></tr>
                    </table>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em;">
                        <?php wp_nonce_field( 'lgms_cohort_add' ); ?>
                        <input type="hidden" name="action" value="lgms_cohort_add">
                        <input type="hidden" name="cohort_user_id" value="<?php echo (int) $cu->ID; ?>">
                        <button type="submit" class="button button-primary">Add #<?php echo (int) $cu->ID; ?> to the cohort</button>
                    </form>
                    <a class="button" href="<?php echo esc_url( add_query_arg( [ 'page' => self::OPT_PAGE, 'tab' => 'stripe_cohort' ], admin_url( 'options-general.php' ) ) ); ?>">Cancel</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <h3>Add a member</h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:2em;">
            <?php wp_nonce_field( 'lgms_cohort_lookup' ); ?>
            <input type="hidden" name="action" value="lgms_cohort_lookup">
            <input type="text" name="cohort_query" class="regular-text" placeholder="email, login, or user ID"
                   autocomplete="off" required>
            <button type="submit" class="button">Look up</button>
            <p class="description">All digits = user ID · contains @ = email · anything else = login. Nothing is stored until you confirm the resolved member.</p>
        </form>

        <h3>Current cohort (<?php echo count( $ids ); ?>)</h3>
        <?php if ( $ids === [] ) : ?>
            <p><em>Empty — the lifecycle is closed for everyone until a member is added.</em></p>
        <?php else : ?>
        <table class="widefat striped" style="max-width:760px;">
            <thead>
                <tr>
                    <th style="width:5em;">ID</th>
                    <th>Login</th>
                    <th>Email</th>
                    <th style="width:11em;">Date added (UTC)</th>
                    <th style="width:6em;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $ids as $id ) :
                $u = get_user_by( 'id', $id );
            ?>
                <tr>
                    <td>#<?php echo (int) $id; ?></td>
                    <?php if ( $u ) : ?>
                        <td><strong><?php echo esc_html( $u->user_login ); ?></strong></td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                    <?php else : ?>
                        <td colspan="2" style="color:#dc2626;">user no longer exists on this box — remove it</td>
                    <?php endif; ?>
                    <td><?php echo esc_html( CohortAllowlist::addedAt( (int) $id ) ?? '—' ); ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <?php wp_nonce_field( 'lgms_cohort_remove_' . (int) $id ); ?>
                            <input type="hidden" name="action" value="lgms_cohort_remove">
                            <input type="hidden" name="cohort_user_id" value="<?php echo (int) $id; ?>">
                            <button type="submit" class="button button-small">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <p class="description" style="margin-top:1.5em;">
            CLI equivalent (same option, same shape):
            <code>wp option update <?php echo esc_html( StripeLifecycle::ALLOWLIST_OPT ); ?> '[<?php echo esc_html( implode( ',', $ids ) ); ?>]' --format=json</code>
        </p>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX: post search for mosaic picker
    // -------------------------------------------------------------------------

    public static function ajaxSearchPosts(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }
        check_ajax_referer( 'lgms_mosaic_search' );

        $q = sanitize_text_field( (string) ( $_GET['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( [] );
        }

        $results = get_posts( [
            'post_type'      => [ 'post-type-videos', 'post-imgcap', 'post-regular', 'loothprint' ],
            'post_status'    => 'publish',
            's'              => $q,
            'posts_per_page' => 12,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        $out = [];
        foreach ( $results as $id ) {
            $thumb = get_the_post_thumbnail_url( $id, 'medium' );
            $out[] = [
                'id'    => $id,
                'title' => get_the_title( $id ),
                'thumb' => $thumb ?: '',
            ];
        }

        wp_send_json_success( $out );
    }

    public static function ajaxSearchUsers(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'forbidden', 403 );
        }
        check_ajax_referer( 'lgms_user_search' );

        $q = sanitize_text_field( (string) ( $_GET['q'] ?? '' ) );
        if ( strlen( $q ) < 2 ) {
            wp_send_json_success( [] );
        }

        $users = get_users( [
            'search'         => '*' . $q . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'number'         => 12,
            'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
        ] );

        $out = [];
        foreach ( $users as $u ) {
            $out[] = [
                'id'      => $u->ID,
                'name'    => $u->display_name ?: $u->user_login,
                'email'   => $u->user_email,
                'login'   => $u->user_login,
                'avatar'  => get_avatar_url( $u->ID, [ 'size' => 32 ] ),
                'roles'   => implode( ', ', (array) ( get_userdata( $u->ID )->roles ?? [] ) ),
            ];
        }

        wp_send_json_success( $out );
    }

    public static function handleCreateAffiliateUser(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_create_affiliate_user' );

        $affId    = (int)    ( $_POST['affiliate_id'] ?? 0 );
        $name     = sanitize_text_field( (string) ( $_POST['new_user_name']  ?? '' ) );
        $email    = sanitize_email( (string)         ( $_POST['new_user_email'] ?? '' ) );
        $role     = sanitize_text_field( (string) ( $_POST['new_user_role']  ?? 'subscriber' ) );

        $err = '';
        $notice = '';

        if ( $affId <= 0 || $email === '' || $name === '' ) {
            $err = 'Name, email, and affiliate are all required.';
        } elseif ( email_exists( $email ) ) {
            $err = "A user with email {$email} already exists. Use the search field to link them instead.";
        } else {
            $userId = wp_create_user(
                sanitize_user( strtolower( str_replace( ' ', '.', $name ) ) ),
                wp_generate_password( 24, true, true ),
                $email
            );
            if ( is_wp_error( $userId ) ) {
                $err = $userId->get_error_message();
            } else {
                $u = get_user_by( 'id', $userId );
                $u->set_role( $role );
                wp_update_user( [ 'ID' => $userId, 'display_name' => $name, 'first_name' => explode( ' ', $name )[0] ] );
                wp_send_new_user_notifications( $userId, 'user' );
                // Link to affiliate.
                Db::pdo()->prepare( 'UPDATE affiliates SET wp_user_id = ? WHERE id = ?' )
                    ->execute( [ $userId, $affId ] );
                $notice = "Created WP user for {$name} ({$email}) and linked to affiliate.";
            }
        }

        $args = [ 'page' => self::AFF_PAGE ];
        if ( $notice !== '' ) $args['lgms_aff_ok']  = rawurlencode( $notice );
        if ( $err    !== '' ) $args['lgms_aff_err'] = rawurlencode( $err );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    // -------------------------------------------------------------------------
    // Page render
    // -------------------------------------------------------------------------

    public static function render(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( (string) $_GET['tab'] ) : 'settings';
        $tabs = [
            'settings'      => 'Settings',
            'member_tools'  => 'Member Tools',
            'welcome_email' => 'Welcome Email',
            'stripe_cohort' => 'Stripe Test Group',
            'stripe_price'  => 'Stripe Price',
            'dual_payers'   => 'Dual Payers',
        ];
        ?>
        <div class="wrap">
            <h1>LG Member Sync</h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:1.5em;">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'page' => self::OPT_PAGE, 'tab' => $slug ], admin_url( 'options-general.php' ) ) ); ?>"
                       class="nav-tab<?php echo $tab === $slug ? ' nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            match ( $tab ) {
                'member_tools'  => MemberTools::renderContent(),
                'welcome_email' => self::renderWelcomeEmailTab(),
                'stripe_cohort' => self::renderStripeCohortTab(),
                'stripe_price'  => self::renderStripePriceTab(),
                'dual_payers'   => self::renderDualPayersTab(),
                default         => self::renderSettingsTab(),
            };
            ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Dual Payers tab (#149) — the direction we cannot block
    // -------------------------------------------------------------------------

    /**
     * Members paying on BOTH rails at once.
     *
     * #150 shuts the door we own: a member already paying on Patreon cannot buy
     * here. The reverse cannot be shut — nothing of ours runs at patreon.com,
     * so a member who pays here and then pledges over there arrives in this
     * list and nowhere else. That is why this screen exists rather than a
     * quiet reconciliation: changing somebody's billing without telling anyone
     * is worse than a list.
     *
     * READ ONLY, deliberately. There is no action on this screen, because what
     * to do about a dual payer (refund which side? cancel which side? ask them?)
     * is Ian's decision and not a button.
     */
    private static function renderDualPayersTab(): void
    {
        $rows  = \LGMS\Membership\DualPayers::find();
        $sweep = \LGMS\Membership\DualPayers::lastSweepAt();
        $flagOn = \LGMS\Membership\PatreonStanding::flagOn();
        ?>
        <h2>Dual payers</h2>
        <p>
            Members whose membership is being charged on <strong>both</strong> Patreon and Stripe right now.
            Ian, 2026-08-19: <em>"We should disallow double payment source for the same user."</em>
        </p>
        <p>
            Checkout on this site refuses a paying Patreon member while
            <code>lgms_double_pay_block</code> is on — it is currently
            <strong><?php echo $flagOn ? 'ON' : 'OFF'; ?></strong>.
            The other direction cannot be refused by us: nothing we run sits at Patreon&rsquo;s checkout,
            so a member who pays here and then pledges there shows up in this table and nowhere else.
        </p>
        <p class="description">
            Patreon figures come from the last completed sweep<?php
                echo $sweep !== null ? ' (' . esc_html( $sweep ) . ')' : ' (never recorded)'; ?>.
            The row&rsquo;s own <code>synced_at</code> is deliberately not used as an age here: it records the last
            time a patron&rsquo;s details CHANGED, not the last time they were checked, so the steadiest
            members carry the oldest-looking rows.
        </p>
        <?php if ( $rows === [] ) : ?>
            <p><strong>Nobody.</strong> No member is currently being charged on both rails.</p>
        <?php else : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Member</th>
                    <th>Patreon</th>
                    <th>Stripe</th>
                    <th>Matched by</th>
                    <th><code>payment_source</code> says</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $r ) :
                $p = $r['patreon'];
                $money = static function ( $cents ) {
                    return $cents === null ? '—' : '$' . number_format( ( (int) $cents ) / 100, 2 );
                };
                ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . (int) $r['wp_user_id'] ) ); ?>">
                            <?php echo esc_html( $r['login'] ); ?></a><br>
                        <span class="description">#<?php echo (int) $r['wp_user_id']; ?> · <?php echo esc_html( $r['wp_email'] ); ?></span>
                    </td>
                    <td>
                        <?php echo esc_html( (string) ( $p['tier_label'] ?? '—' ) ); ?>
                        · <?php echo esc_html( $money( $p['amount_cents'] ) ); ?><br>
                        <span class="description">
                            <?php echo esc_html( (string) ( $p['patron_status'] ?? '?' ) ); ?><?php
                            if ( ! empty( $p['next_charge_date'] ) ) {
                                echo ' · next ' . esc_html( (string) $p['next_charge_date'] );
                            } ?>
                        </span>
                    </td>
                    <td>
                        <?php echo esc_html( (string) ( $r['stripe_tier'] ?? '—' ) ); ?>
                        · <?php echo esc_html( $money( $r['stripe_cents'] ) ); ?><?php
                        echo $r['stripe_interval'] ? ' / ' . esc_html( $r['stripe_interval'] ) : ''; ?><br>
                        <span class="description">
                            <?php echo esc_html( $r['stripe_status'] ); ?> · customer #<?php echo (int) $r['customer_id']; ?>
                            · <?php echo esc_html( $r['stripe_email'] ); ?>
                        </span>
                    </td>
                    <td>
                        <?php echo esc_html( $r['matched_by'] ); ?>
                        <?php if ( $r['matched_by'] === 'email' ) : ?>
                            <br><span class="description">no bridge row — a shared address could match the wrong person</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html( $r['payment_source_says'] ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            Nothing on this screen changes anything. <code>payment_source</code> is shown because it is
            frequently WRONG for exactly these members — the two rails overwrite each other in that one
            slot — and no verdict here is taken from it.
        </p>
        <?php endif;
    }

    // -------------------------------------------------------------------------
    // Settings tab
    // -------------------------------------------------------------------------

    private static function renderSettingsTab(): void
    {
        $probe = '<em>not tested</em>';
        try {
            $pdo   = Db::pdo();
            $row   = $pdo->query( 'SELECT VERSION() AS v' )->fetch();
            $probe = sprintf( '✓ connected (MySQL %s)', esc_html( (string) ( $row['v'] ?? '?' ) ) );
        } catch ( \Throwable $e ) {
            $probe = '✗ ' . esc_html( $e->getMessage() );
        }

        $nextRun        = wp_next_scheduled( Plugin::CRON_HOOK );
        $nextRunDisplay = $nextRun ? gmdate( 'c', $nextRun ) . ' UTC' : '<em>not scheduled</em>';
        $pagesNotice    = isset( $_GET['lgms_pages'] ) ? rawurldecode( (string) $_GET['lgms_pages'] ) : '';
        ?>

        <h2>DB connection</h2>
        <p><strong>Probe:</strong> <?php echo $probe; ?></p>
        <p><strong>Cron next run:</strong> <?php echo $nextRunDisplay; ?></p>

        <h2>Membership pages</h2>
        <p class="description">
            Auto-creates the WP pages hosting <code>[lg_join]</code>, <code>[lg_gift]</code>, <code>[lg_redeem_gift]</code>, <code>[lg_manage_subscription]</code>, <code>[lg_refund_request]</code>, <code>[lg_regional_fail]</code>, and <code>[lg_subscription_success]</code>, and adds public-facing slugs to the BuddyBoss allowlist. Runs automatically on plugin activation; click below if you've edited the page registry and want to re-sync without deactivate/reactivate.
        </p>
        <?php if ( $pagesNotice !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Pages re-synced: <code><?php echo esc_html( $pagesNotice ); ?></code></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'lgms_rerun_pages' ); ?>
            <input type="hidden" name="action" value="lgms_rerun_pages">
            <p><button type="submit" class="button">Re-create / sync membership pages</button></p>
        </form>

        <form method="post" action="options.php">
            <?php settings_fields( self::OPT_GROUP ); ?>

            <h2>DB connection</h2>
            <table class="form-table">
                <tr><th><label>Host</label></th><td><input type="text" name="lgms_db_host" value="<?php echo esc_attr( get_option( 'lgms_db_host', '127.0.0.1' ) ); ?>" class="regular-text"></td></tr>
                <tr><th><label>Port</label></th><td><input type="text" name="lgms_db_port" value="<?php echo esc_attr( get_option( 'lgms_db_port', '3306' ) ); ?>" class="small-text"></td></tr>
                <tr><th><label>Database</label></th><td><input type="text" name="lgms_db_name" value="<?php echo esc_attr( get_option( 'lgms_db_name', 'lg_membership' ) ); ?>" class="regular-text"></td></tr>
                <tr><th><label>User</label></th><td><input type="text" name="lgms_db_user" value="<?php echo esc_attr( get_option( 'lgms_db_user', 'lg_membership' ) ); ?>" class="regular-text"></td></tr>
                <tr><th><label>Password</label></th><td><input type="password" name="lgms_db_pass" value="<?php echo esc_attr( get_option( 'lgms_db_pass', '' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
            </table>

            <h2>Stripe</h2>
            <table class="form-table">
                <tr><th><label>Secret key</label></th><td><input type="password" name="lgms_stripe_secret_key" value="<?php echo esc_attr( get_option( 'lgms_stripe_secret_key', '' ) ); ?>" class="regular-text" autocomplete="off" placeholder="sk_test_... or sk_live_..."></td></tr>
            </table>

            <h2>Refund requests</h2>
            <p class="description">Settings for the <code>[lg_refund_request]</code> form.</p>
            <table class="form-table">
                <tr><th><label>Refund email</label></th><td><input type="email" name="lgms_refund_email" value="<?php echo esc_attr( get_option( 'lgms_refund_email', '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"> <span class="description">Leave blank to use the WordPress admin email.</span></td></tr>
                <tr><th><label>Refund window (days)</label></th><td><input type="number" name="lgms_refund_window_days" value="<?php echo esc_attr( get_option( 'lgms_refund_window_days', '30' ) ); ?>" class="small-text" min="1" max="365"> <span class="description">Number of days after a charge that a customer is eligible for an automated refund.</span></td></tr>
                <tr><th><label>Plan-switch cooldown (hours)</label></th><td><input type="number" name="lgms_plan_switch_cooldown_hours" value="<?php echo esc_attr( get_option( 'lgms_plan_switch_cooldown_hours', '24' ) ); ?>" class="small-text" min="0" max="720"> <span class="description">Minimum hours between customer-initiated plan changes. Set to 0 to disable.</span></td></tr>
            </table>

            <h2>Slim ↔ plugin shared secret</h2>
            <p class="description">Used to authenticate Slim's calls to <code>/wp-json/lg-member-sync/v1/sync-customer</code>. Set the same value on Slim's <code>LGMS_SHARED_SECRET</code> in <code>.env</code>.</p>
            <table class="form-table">
                <tr><th><label>Shared secret</label></th><td><input type="password" name="lgms_shared_secret" value="<?php echo esc_attr( get_option( 'lgms_shared_secret', '' ) ); ?>" class="regular-text" autocomplete="off"></td></tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Welcome Email tab
    // -------------------------------------------------------------------------

    private static function renderWelcomeEmailTab(): void
    {
        if ( isset( $_GET['lgms_mosaic_saved'] ) ) : ?>
            <div class="notice notice-success is-dismissible"><p>Mosaic images saved.</p></div>
        <?php endif;

        $saved = json_decode( (string) get_option( 'lgms_welcome_mosaic_ids', '[]' ), true );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }
        $saved = array_pad( array_values( $saved ), 6, 0 );
        ?>

        <p class="description" style="margin-bottom:1.5em;">
            Choose up to 6 posts (videos, articles, loothprints) whose featured images appear in the welcome email mosaic.
            Search by title — the image preview updates as you pick.
        </p>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'lgms_save_welcome_mosaic' ); ?>
            <input type="hidden" name="action" value="lgms_save_welcome_mosaic">

            <div id="lgms-mosaic-slots" style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;max-width:760px;margin-bottom:2em;">
                <?php for ( $i = 0; $i < 6; $i++ ) :
                    $postId = (int) ( $saved[ $i ] ?? 0 );
                    $title  = $postId ? get_the_title( $postId ) : '';
                    $thumb  = $postId ? ( get_the_post_thumbnail_url( $postId, 'medium' ) ?: '' ) : '';
                    ?>
                    <div class="lgms-slot" style="border:1px solid #ddd;border-radius:4px;padding:12px;background:#fff;">
                        <p style="margin:0 0 6px;font-weight:600;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.05em;">Slot <?php echo $i + 1; ?></p>

                        <div class="lgms-thumb-wrap" style="height:90px;background:#f0f0f0;border-radius:3px;margin-bottom:8px;overflow:hidden;display:flex;align-items:center;justify-content:center;">
                            <?php if ( $thumb ) : ?>
                                <img src="<?php echo esc_url( $thumb ); ?>" style="width:100%;height:100%;object-fit:cover;" alt="">
                            <?php else : ?>
                                <span style="color:#aaa;font-size:12px;">No image</span>
                            <?php endif; ?>
                        </div>

                        <input type="hidden" name="mosaic_ids[]" class="lgms-post-id" value="<?php echo esc_attr( (string) $postId ); ?>">

                        <div style="position:relative;">
                            <input type="text"
                                   class="lgms-search-input widefat"
                                   placeholder="Search…"
                                   value="<?php echo esc_attr( $title ); ?>"
                                   autocomplete="off"
                                   style="margin-bottom:4px;">
                            <div class="lgms-results" style="display:none;position:absolute;z-index:999;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:3px;max-height:180px;overflow-y:auto;box-shadow:0 3px 8px rgba(0,0,0,.12);"></div>
                        </div>

                        <button type="button" class="lgms-clear button button-small" style="margin-top:4px;width:100%;">Clear</button>
                    </div>
                <?php endfor; ?>
            </div>

            <?php submit_button( 'Save mosaic' ); ?>
        </form>

        <script>
        (function () {
            var nonce = <?php echo wp_json_encode( wp_create_nonce( 'lgms_mosaic_search' ) ); ?>;
            var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;

            document.querySelectorAll('.lgms-slot').forEach(function (slot) {
                var input   = slot.querySelector('.lgms-search-input');
                var results = slot.querySelector('.lgms-results');
                var idField = slot.querySelector('.lgms-post-id');
                var thumb   = slot.querySelector('.lgms-thumb-wrap');
                var clear   = slot.querySelector('.lgms-clear');
                var timer   = null;

                function setPost(id, title, thumbUrl) {
                    idField.value = id;
                    input.value   = title;
                    results.style.display = 'none';
                    results.innerHTML = '';
                    thumb.innerHTML = thumbUrl
                        ? '<img src="' + thumbUrl + '" style="width:100%;height:100%;object-fit:cover;" alt="">'
                        : '<span style="color:#aaa;font-size:12px;">No image</span>';
                }

                input.addEventListener('input', function () {
                    clearTimeout(timer);
                    var q = input.value.trim();
                    if (q.length < 2) { results.style.display = 'none'; return; }
                    timer = setTimeout(function () {
                        var url = ajaxUrl + '?action=lgms_search_posts&_ajax_nonce=' + nonce + '&q=' + encodeURIComponent(q);
                        fetch(url)
                            .then(function (r) { return r.json(); })
                            .then(function (data) {
                                results.innerHTML = '';
                                if (!data.success || !data.data.length) {
                                    results.style.display = 'none';
                                    return;
                                }
                                data.data.forEach(function (post) {
                                    var li = document.createElement('div');
                                    li.style.cssText = 'display:flex;align-items:center;gap:8px;padding:6px 8px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
                                    li.innerHTML = (post.thumb
                                        ? '<img src="' + post.thumb + '" style="width:36px;height:36px;object-fit:cover;border-radius:2px;flex-shrink:0;" alt="">'
                                        : '<div style="width:36px;height:36px;background:#eee;border-radius:2px;flex-shrink:0;"></div>')
                                        + '<span style="font-size:13px;line-height:1.3;">' + post.title + '</span>';
                                    li.addEventListener('mousedown', function (e) {
                                        e.preventDefault();
                                        setPost(post.id, post.title, post.thumb);
                                    });
                                    results.appendChild(li);
                                });
                                results.style.display = 'block';
                            });
                    }, 300);
                });

                input.addEventListener('blur', function () {
                    setTimeout(function () { results.style.display = 'none'; }, 150);
                });

                clear.addEventListener('click', function () {
                    setPost(0, '', '');
                    thumb.innerHTML = '<span style="color:#aaa;font-size:12px;">No image</span>';
                });
            });
        }());
        </script>
        <?php
    }

    // -------------------------------------------------------------------------
    // Affiliates page (top-level menu)
    // -------------------------------------------------------------------------

    public static function renderAffiliatePage(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Affiliates</h1>
            <?php self::renderAffiliatesTab(); ?>
            <?php self::renderPayoutsPanel(); ?>
        </div>
        <?php
    }

    /**
     * Payouts dashboard for the wp-admin Affiliates page. Pending tab lists
     * open withdrawal requests with inline Mark Paid / Deny. History tab is
     * a read-only ledger of paid + denied rows across all affiliates with a
     * client-side affiliate filter.
     *
     * Same data shape and AJAX endpoint as the previous public-page version,
     * but uses native WP admin styling so it sits comfortably alongside the
     * existing Affiliates table.
     */
    private static function renderPayoutsPanel(): void
    {
        $pending      = [];
        $history      = [];
        $historyTotal = 0;
        $affs         = [];
        try {
            $pending = Db::pdo()->query(
                'SELECT p.id, p.affiliate_id, p.requested_cents, p.requested_at,
                        a.label AS aff_label, a.slug AS aff_slug
                 FROM lg_affiliate_payouts p
                 JOIN affiliates a ON a.id = p.affiliate_id
                 WHERE p.status = "requested"
                 ORDER BY p.id ASC'
            )->fetchAll( \PDO::FETCH_ASSOC ) ?: [];

            $history = Db::pdo()->query(
                'SELECT p.id, p.affiliate_id, p.requested_cents, p.paid_cents,
                        p.status, p.method, p.notes,
                        p.requested_at, p.resolved_at, p.resolved_by,
                        a.label AS aff_label, a.slug AS aff_slug
                 FROM lg_affiliate_payouts p
                 JOIN affiliates a ON a.id = p.affiliate_id
                 WHERE p.status IN ("paid","denied")
                 ORDER BY p.resolved_at DESC, p.id DESC
                 LIMIT 1000'
            )->fetchAll( \PDO::FETCH_ASSOC ) ?: [];

            $historyTotal = (int) Db::pdo()->query(
                'SELECT COUNT(*) FROM lg_affiliate_payouts WHERE status IN ("paid","denied")'
            )->fetchColumn();

            $affs = Db::pdo()->query(
                'SELECT slug, label FROM affiliates ORDER BY label ASC'
            )->fetchAll( \PDO::FETCH_ASSOC ) ?: [];
        } catch ( \Throwable $_ ) {}

        $defaultTab = $pending ? 'pending' : 'history';
        $sumPaid    = 0;
        $nPaid      = 0;
        $nDenied    = 0;
        foreach ( $history as $h ) {
            if ( ( $h['status'] ?? '' ) === 'paid' ) {
                $nPaid++;
                $sumPaid += (int) ( $h['paid_cents'] ?? 0 );
            } else {
                $nDenied++;
            }
        }
        $resolveUrl = esc_url_raw( rest_url( 'lg-member-sync/v1/admin/payout-resolve' ) );
        $nonce      = wp_create_nonce( 'wp_rest' );
        ?>
        <h2 style="margin-top:2.5em;">Payouts</h2>
        <p class="description">When an affiliate clicks Request withdrawal on /affiliate-earnings/, the request lands here. Mark Paid after you transfer; the amount is subtracted from their future estimated balance.</p>

        <div class="lgms-payouts" data-active-tab="<?php echo esc_attr( $defaultTab ); ?>">
            <ul class="lgms-payouts-tabs subsubsub" style="margin:0 0 .8em;">
                <li><a href="#" class="lgms-pay-tab <?php echo $defaultTab === 'pending' ? 'current' : ''; ?>" data-tab="pending">Pending <span class="count">(<?php echo count( $pending ); ?>)</span></a> |</li>
                <li><a href="#" class="lgms-pay-tab <?php echo $defaultTab === 'history' ? 'current' : ''; ?>" data-tab="history">History <span class="count">(<?php echo count( $history ); ?>)</span></a></li>
            </ul>

            <!-- Pending -->
            <div class="lgms-pay-panel" data-panel="pending">
                <?php if ( $pending === [] ) : ?>
                    <p style="color:#666;font-style:italic;">No pending requests right now.</p>
                <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:11em;">Requested</th>
                            <th>Affiliate</th>
                            <th style="width:8em;text-align:right;">Snapshot</th>
                            <th>Resolve</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $pending as $p ) :
                            $reqCents = (int) $p['requested_cents'];
                            $reqFmt   = number_format( $reqCents / 100, 2 );
                        ?>
                        <tr class="lgms-pay-pending" data-payout-id="<?php echo (int) $p['id']; ?>">
                            <td><?php echo esc_html( substr( (string) $p['requested_at'], 0, 16 ) ); ?></td>
                            <td>
                                <strong><?php echo esc_html( (string) $p['aff_label'] ); ?></strong>
                                <span style="color:#888;">/<?php echo esc_html( (string) $p['aff_slug'] ); ?></span>
                            </td>
                            <td style="text-align:right;font-weight:600;">$<?php echo $reqFmt; ?></td>
                            <td>
                                <div style="display:flex;gap:.3em;align-items:center;flex-wrap:wrap;">
                                    <span style="color:#666;">$</span>
                                    <input type="number" step="0.01" min="0" value="<?php echo $reqFmt; ?>" class="lgms-pay-amount small-text" style="width:6em;">
                                    <input type="text" placeholder="Method" maxlength="64" class="lgms-pay-method regular-text" style="width:9em;">
                                    <input type="text" placeholder="Notes" maxlength="2000" class="lgms-pay-notes regular-text" style="width:12em;">
                                    <button type="button" class="button button-primary lgms-pay-mark">Paid</button>
                                    <button type="button" class="button lgms-pay-deny">Deny</button>
                                </div>
                                <div class="lgms-pay-status" style="margin-top:.3em;font-size:.85em;display:none;"></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- History -->
            <div class="lgms-pay-panel" data-panel="history">
                <?php if ( $history === [] ) : ?>
                    <p style="color:#666;font-style:italic;">Nothing resolved yet.</p>
                <?php else : ?>
                <p style="margin:0 0 .8em;">
                    <span class="lgms-chip" style="background:#dcfce7;color:#15803d;"><?php echo $nPaid; ?> paid · $<?php echo number_format( $sumPaid / 100, 2 ); ?> total</span>
                    <?php if ( $nDenied > 0 ) : ?>
                    <span class="lgms-chip" style="background:#fee2e2;color:#dc2626;"><?php echo $nDenied; ?> denied</span>
                    <?php endif; ?>
                    <label style="margin-left:1em;">
                        Filter:
                        <select class="lgms-pay-filter">
                            <option value="">All affiliates</option>
                            <?php foreach ( $affs as $a ) : ?>
                                <option value="<?php echo esc_attr( (string) $a['slug'] ); ?>"><?php echo esc_html( (string) $a['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <?php $historyVisibleCap = 200; ?>
                <table class="widefat striped lgms-pay-history">
                    <thead>
                        <tr>
                            <th style="width:8em;">Resolved</th>
                            <th>Affiliate</th>
                            <th style="width:7em;text-align:right;">Amount</th>
                            <th style="width:6em;">Status</th>
                            <th>Method · Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $history as $idx => $h ) :
                            $status   = (string) ( $h['status'] ?? '' );
                            $reqCents = (int) ( $h['requested_cents'] ?? 0 );
                            $paidCents= (int) ( $h['paid_cents']      ?? 0 );
                            $amt      = $status === 'paid'
                                        ? number_format( $paidCents / 100, 2 )
                                        : number_format( $reqCents  / 100, 2 );
                            $color    = $status === 'paid' ? '#15803d' : '#dc2626';
                            $method   = (string) ( $h['method'] ?? '' );
                            $note     = (string) ( $h['notes']  ?? '' );
                            $extras   = trim( $method . ( $note !== '' ? ( $method !== '' ? ' · ' : '' ) . $note : '' ) );
                            $resolved = (string) ( $h['resolved_at'] ?? $h['requested_at'] ?? '' );
                            $extraCls = $idx >= $historyVisibleCap ? ' lgms-pay-hist-extra' : '';
                        ?>
                        <tr class="lgms-pay-hist<?php echo $extraCls; ?>" data-aff-slug="<?php echo esc_attr( (string) $h['aff_slug'] ); ?>">
                            <td><?php echo esc_html( substr( $resolved, 0, 10 ) ); ?></td>
                            <td>
                                <strong><?php echo esc_html( (string) $h['aff_label'] ); ?></strong>
                                <span style="color:#888;">/<?php echo esc_html( (string) $h['aff_slug'] ); ?></span>
                            </td>
                            <td style="text-align:right;font-weight:600;">
                                $<?php echo esc_html( $amt ); ?>
                                <?php if ( $status === 'paid' && $paidCents !== $reqCents ) : ?>
                                    <small style="color:#999;display:block;">req $<?php echo number_format( $reqCents / 100, 2 ); ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="color:<?php echo $color; ?>;font-weight:600;text-transform:uppercase;font-size:.85em;letter-spacing:.04em;">
                                <?php echo esc_html( $status ); ?>
                            </td>
                            <td><?php echo esc_html( $extras ?: '—' ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="lgms-pay-history-empty" style="display:none;color:#666;font-style:italic;margin-top:1em;">No payouts for that affiliate.</p>
                <?php
                $shownH    = count( $history );
                $hiddenH   = max( 0, $shownH - $historyVisibleCap );
                $beyondDbH = max( 0, $historyTotal - $shownH );
                if ( $hiddenH > 0 || $beyondDbH > 0 ) :
                ?>
                <p style="margin:.8em 0 0;font-size:.9em;color:#666;">
                    Showing <?php echo min( $shownH, $historyVisibleCap ); ?> of <?php echo $historyTotal; ?>.
                    <?php if ( $hiddenH > 0 ) : ?>
                        <a href="#" class="lgms-pay-history-show-all" style="color:#2271b1;font-weight:600;text-decoration:none;">Show all <?php echo $shownH; ?> &rarr;</a>
                    <?php endif; ?>
                    <?php if ( $beyondDbH > 0 ) : ?>
                        <span style="color:#888;">(<?php echo $beyondDbH; ?> older row<?php echo $beyondDbH === 1 ? '' : 's'; ?> not loaded — query the DB directly to retrieve.)</span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <style>
            .lgms-chip { display:inline-block; padding:.15em .55em; border-radius:3px; font-size:.8em; font-weight:600; margin-right:.4em; }
            .lgms-pay-panel { display: none; }
            .lgms-payouts[data-active-tab="pending"] .lgms-pay-panel[data-panel="pending"] { display: block; }
            .lgms-payouts[data-active-tab="history"] .lgms-pay-panel[data-panel="history"] { display: block; }
            .lgms-payouts-tabs a { text-decoration: none; }
            .lgms-payouts-tabs a.current { color: #1d2327; font-weight: 600; }
            .lgms-pay-hist-extra { display: none; }
        </style>
        <script>
        (function(){
            var root = document.querySelector('.lgms-payouts');
            if (!root) return;
            var RESOLVE_URL = <?php echo wp_json_encode( $resolveUrl ); ?>;
            var NONCE       = <?php echo wp_json_encode( $nonce ); ?>;

            // Tabs
            root.querySelectorAll('.lgms-pay-tab').forEach(function(a){
                a.addEventListener('click', function(e){
                    e.preventDefault();
                    var t = a.getAttribute('data-tab');
                    root.setAttribute('data-active-tab', t);
                    root.querySelectorAll('.lgms-pay-tab').forEach(function(x){
                        x.classList.toggle('current', x === a);
                    });
                });
            });

            // Show-all toggle on history (reveals rows hidden by the visible cap).
            root.querySelectorAll('.lgms-pay-history-show-all').forEach(function(link){
                link.addEventListener('click', function(e){
                    e.preventDefault();
                    root.querySelectorAll('.lgms-pay-hist-extra').forEach(function(tr){
                        tr.classList.remove('lgms-pay-hist-extra');
                    });
                    link.style.display = 'none';
                });
            });

            // History filter
            var filter = root.querySelector('.lgms-pay-filter');
            var empty  = root.querySelector('.lgms-pay-history-empty');
            if (filter) {
                filter.addEventListener('change', function(){
                    var want = filter.value, shown = 0;
                    root.querySelectorAll('.lgms-pay-hist').forEach(function(tr){
                        var match = !want || tr.getAttribute('data-aff-slug') === want;
                        tr.style.display = match ? '' : 'none';
                        if (match) shown++;
                    });
                    if (empty) empty.style.display = shown === 0 ? 'block' : 'none';
                });
            }

            // Resolve actions
            async function resolve(row, body) {
                var st = row.querySelector('.lgms-pay-status');
                st.style.display = 'block';
                st.style.color = '#555';
                st.textContent = 'Saving…';
                try {
                    var res = await fetch(RESOLVE_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
                        body: JSON.stringify(body),
                    });
                    var data = await res.json();
                    if (data.ok || res.ok) {
                        st.style.color = '#15803d';
                        st.textContent = body.status === 'paid' ? 'Marked paid. Reload to refresh.' : 'Marked denied. Reload to refresh.';
                        row.querySelectorAll('input, button').forEach(function(el){ el.disabled = true; });
                        row.style.opacity = '.5';
                    } else {
                        st.style.color = '#dc2626';
                        st.textContent = (data && data.error) || ('HTTP ' + res.status);
                    }
                } catch (e) {
                    st.style.color = '#dc2626';
                    st.textContent = 'Network error.';
                }
            }
            root.querySelectorAll('.lgms-pay-pending').forEach(function(row){
                var id = parseInt(row.getAttribute('data-payout-id'), 10);
                row.querySelector('.lgms-pay-mark').addEventListener('click', function(){
                    var amt   = parseFloat(row.querySelector('.lgms-pay-amount').value || '0');
                    var cents = Math.round(amt * 100);
                    if (!isFinite(cents) || cents < 0) {
                        var st = row.querySelector('.lgms-pay-status');
                        st.style.display='block'; st.style.color='#dc2626';
                        st.textContent='Enter a non-negative amount.';
                        return;
                    }
                    resolve(row, {
                        id: id, status: 'paid', paid_cents: cents,
                        method: row.querySelector('.lgms-pay-method').value || '',
                        notes:  row.querySelector('.lgms-pay-notes').value || '',
                    });
                });
                row.querySelector('.lgms-pay-deny').addEventListener('click', function(){
                    var notes = row.querySelector('.lgms-pay-notes').value || '';
                    if (!notes && !confirm('Deny without notes?')) return;
                    resolve(row, { id: id, status: 'denied', notes: notes });
                });
            });
        })();
        </script>
        <?php
    }

    public static function handleCreateAffiliate(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_create_affiliate' );

        $slug      = sanitize_text_field( (string) ( $_POST['slug']      ?? '' ) );
        $label     = sanitize_text_field( (string) ( $_POST['label']     ?? '' ) );
        $wpUserRef = sanitize_text_field( (string) ( $_POST['wp_user']   ?? '' ) );

        // Allow letters, digits, hyphens only.
        $slug = strtolower( (string) preg_replace( '/[^a-z0-9-]/i', '-', $slug ) );
        $slug = trim( $slug, '-' );

        // Resolve wp_user field: accepts numeric ID or email/login.
        $wpUserId = null;
        if ( $wpUserRef !== '' ) {
            if ( is_numeric( $wpUserRef ) ) {
                $wpUserId = (int) $wpUserRef;
            } else {
                $u = get_user_by( 'email', $wpUserRef ) ?: get_user_by( 'login', $wpUserRef );
                $wpUserId = $u ? $u->ID : null;
            }
        }

        $notice = '';
        $err    = '';

        if ( $slug === '' ) {
            $err = 'Slug is required.';
        } else {
            try {
                $pdo = Db::pdo();
                $pdo->prepare( 'INSERT INTO affiliates (slug, label, wp_user_id) VALUES (?, ?, ?)' )
                    ->execute( [ $slug, $label !== '' ? $label : $slug, $wpUserId ] );
                $notice = "Created affiliate: {$slug}";
            } catch ( \Throwable $e ) {
                if ( str_contains( $e->getMessage(), 'Duplicate' ) ) {
                    $err = "Slug '{$slug}' already exists.";
                } else {
                    $err = $e->getMessage();
                }
            }
        }

        $args = [ 'page' => self::AFF_PAGE ];
        if ( $notice !== '' ) $args['lgms_aff_ok']  = rawurlencode( $notice );
        if ( $err    !== '' ) $args['lgms_aff_err'] = rawurlencode( $err );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public static function handleUpdateAffiliateCommission(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( 'lgms_update_affiliate_commission' );

        $id         = (int) ( $_POST['affiliate_id'] ?? 0 );
        $pct        = (float) ( $_POST['commission_pct']         ?? 0 );
        $pctAnn     = (float) ( $_POST['commission_pct_annual']  ?? 0 );
        $bonus      = (float) ( $_POST['retention_bonus_pct']    ?? 0 );
        $wpUserRef  = sanitize_text_field( (string) ( $_POST['wp_user'] ?? '' ) );

        // Resolve wp_user field. Empty string means "no change" — don't null out an existing link.
        $updateWpUser = false;
        $wpUserId     = null;
        if ( $wpUserRef !== '' ) {
            $updateWpUser = true;
            if ( is_numeric( $wpUserRef ) ) {
                $wpUserId = (int) $wpUserRef;
            } else {
                $u = get_user_by( 'email', $wpUserRef ) ?: get_user_by( 'login', $wpUserRef );
                $wpUserId = $u ? $u->ID : null;
            }
        }

        $notice = '';
        $err    = '';

        if ( $id <= 0 ) {
            $err = 'Invalid affiliate.';
        } else {
            try {
                $pdo = Db::pdo();
                if ( $updateWpUser ) {
                    $pdo->prepare(
                        'UPDATE affiliates SET commission_pct = ?, commission_pct_annual = ?, retention_bonus_pct = ?, wp_user_id = ? WHERE id = ?'
                    )->execute( [ $pct, $pctAnn, $bonus, $wpUserId, $id ] );
                } else {
                    $pdo->prepare(
                        'UPDATE affiliates SET commission_pct = ?, commission_pct_annual = ?, retention_bonus_pct = ? WHERE id = ?'
                    )->execute( [ $pct, $pctAnn, $bonus, $id ] );
                }
                $notice = 'Saved.';
            } catch ( \Throwable $e ) {
                $err = $e->getMessage();
            }
        }

        $args = [ 'page' => self::AFF_PAGE, 'lgms_edit_aff' => $id ];
        if ( $notice !== '' ) $args['lgms_aff_ok']  = rawurlencode( $notice );
        if ( $err    !== '' ) $args['lgms_aff_err'] = rawurlencode( $err );
        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Renders a user search + "create new user" widget.
     * $linkedUser = currently linked WP_User object or null.
     * $affId      = affiliate ID (0 when creating a new affiliate).
     */
    private static function renderUserSearchField( string $fieldName, ?\WP_User $linkedUser, string $nonce, int $affId ): void
    {
        $uid   = $linkedUser ? (int) $linkedUser->ID : 0;
        $uName = $linkedUser ? esc_html( $linkedUser->display_name ?: $linkedUser->user_login ) : '';
        $uEmail= $linkedUser ? esc_html( $linkedUser->user_email ) : '';
        $uAv   = $linkedUser ? esc_url( get_avatar_url( $uid, [ 'size' => 32 ] ) ) : '';
        $roles = wp_roles()->get_names();
        $uid_field = esc_attr( $fieldName );
        ?>
        <div class="lgms-user-search" style="max-width:420px;">
            <input type="hidden" name="<?php echo $uid_field; ?>" id="lgms-us-val-<?php echo $uid_field; ?>"
                   value="<?php echo $uid > 0 ? $uid : ''; ?>">

            <?php if ( $uid > 0 ) : ?>
            <div id="lgms-us-linked-<?php echo $uid_field; ?>"
                 style="display:flex;align-items:center;gap:.6em;padding:.5em;background:#f0f6ff;border:1px solid #b8d0f0;border-radius:4px;margin-bottom:.5em;">
                <img src="<?php echo $uAv; ?>" width="32" height="32" style="border-radius:50%;">
                <span><strong><?php echo $uName; ?></strong><br><small style="color:#666;"><?php echo $uEmail; ?></small></span>
                <button type="button" onclick="lgmsUserSearchClear('<?php echo $uid_field; ?>')"
                        style="margin-left:auto;background:none;border:none;cursor:pointer;color:#dc2626;font-size:1.1em;" title="Unlink">✕</button>
            </div>
            <?php else : ?>
            <div id="lgms-us-linked-<?php echo $uid_field; ?>" style="display:none;"></div>
            <?php endif; ?>

            <div id="lgms-us-search-wrap-<?php echo $uid_field; ?>" <?php echo $uid > 0 ? 'style="display:none;"' : ''; ?>>
                <input type="text" id="lgms-us-q-<?php echo $uid_field; ?>"
                       placeholder="Search by name, email, or username…"
                       autocomplete="off"
                       style="width:100%;margin-bottom:.35em;"
                       oninput="lgmsUserSearch('<?php echo $uid_field; ?>', this.value)">
                <div id="lgms-us-results-<?php echo $uid_field; ?>"
                     style="border:1px solid #ddd;border-radius:4px;background:#fff;display:none;max-height:220px;overflow-y:auto;"></div>

                <?php if ( $affId === 0 ) : ?>
                <p class="description" style="margin-top:.8em;">Save the affiliate first, then use Edit rates to create a new user.</p>
                <?php endif; ?>
            </div>
        </div>

        <script>
        (function() {
            var timers = {};
            window.lgmsUserSearch = function(field, q) {
                clearTimeout(timers[field]);
                var res = document.getElementById('lgms-us-results-' + field);
                if (q.length < 2) { res.style.display = 'none'; return; }
                timers[field] = setTimeout(function() {
                    fetch(ajaxurl + '?action=lgms_search_users&q=' + encodeURIComponent(q) + '&_ajax_nonce=<?php echo esc_js( $nonce ); ?>')
                        .then(function(r) { return r.json(); })
                        .then(function(data) {
                            if (!data.success || !data.data.length) {
                                res.innerHTML = '<div style="padding:.5em .8em;color:#888;font-size:.9em;">No users found</div>';
                                res.style.display = 'block'; return;
                            }
                            res.innerHTML = data.data.map(function(u) {
                                return '<div style="display:flex;align-items:center;gap:.6em;padding:.45em .7em;cursor:pointer;border-bottom:1px solid #f0f0f0;" ' +
                                    'onmousedown="lgmsUserPick(\'' + field + '\',' + u.id + ',\'' +
                                    u.name.replace(/'/g,"\\'") + '\',\'' + u.email.replace(/'/g,"\\'") + '\',\'' +
                                    u.avatar + '\',\'' + u.roles.replace(/'/g,"\\'") + '\')">' +
                                    '<img src="' + u.avatar + '" width="28" height="28" style="border-radius:50%;flex-shrink:0;">' +
                                    '<span><strong>' + u.name + '</strong> <span style="color:#888;font-size:.85em;">(' + u.roles + ')</span><br>' +
                                    '<small style="color:#666;">' + u.email + '</small></span></div>';
                            }).join('');
                            res.style.display = 'block';
                        });
                }, 280);
            };
            window.lgmsUserPick = function(field, id, name, email, avatar, roles) {
                document.getElementById('lgms-us-val-' + field).value = id;
                document.getElementById('lgms-us-linked-' + field).innerHTML =
                    '<div style="display:flex;align-items:center;gap:.6em;padding:.5em;background:#f0f6ff;border:1px solid #b8d0f0;border-radius:4px;">' +
                    '<img src="' + avatar + '" width="32" height="32" style="border-radius:50%;">' +
                    '<span><strong>' + name + '</strong> <span style="color:#888;font-size:.85em;">(' + roles + ')</span><br>' +
                    '<small style="color:#666;">' + email + '</small></span>' +
                    '<button type="button" onclick="lgmsUserSearchClear(\'' + field + '\')" ' +
                    'style="margin-left:auto;background:none;border:none;cursor:pointer;color:#dc2626;font-size:1.1em;" title="Unlink">✕</button></div>';
                document.getElementById('lgms-us-linked-' + field).style.display = 'block';
                document.getElementById('lgms-us-search-wrap-' + field).style.display = 'none';
                document.getElementById('lgms-us-results-' + field).style.display = 'none';
            };
            window.lgmsUserSearchClear = function(field) {
                document.getElementById('lgms-us-val-' + field).value = '';
                document.getElementById('lgms-us-linked-' + field).style.display = 'none';
                document.getElementById('lgms-us-search-wrap-' + field).style.display = 'block';
                document.getElementById('lgms-us-q-' + field).value = '';
                document.getElementById('lgms-us-results-' + field).style.display = 'none';
            };
        })();
        </script>
        <?php
    }

    private static function renderCreateAffiliateUserForm( int $affId ): void
    {
        $roles = wp_roles()->get_names();
        ?>
        <details style="margin-top:1em;max-width:480px;">
            <summary style="cursor:pointer;color:#2271b1;font-size:.95em;">Create new WP user for this affiliate</summary>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  style="margin-top:.6em;padding:.8em;background:#fafafa;border:1px solid #eee;border-radius:4px;">
                <?php wp_nonce_field( 'lgms_create_affiliate_user' ); ?>
                <input type="hidden" name="action"       value="lgms_create_affiliate_user">
                <input type="hidden" name="affiliate_id" value="<?php echo $affId; ?>">
                <table style="border-collapse:collapse;width:100%;">
                    <tr>
                        <td style="padding:.3em .6em .3em 0;white-space:nowrap;"><label>Display name</label></td>
                        <td><input type="text" name="new_user_name" class="regular-text" required placeholder="Dan Smith"></td>
                    </tr>
                    <tr>
                        <td style="padding:.3em .6em .3em 0;white-space:nowrap;"><label>Email</label></td>
                        <td><input type="email" name="new_user_email" class="regular-text" required placeholder="dan@example.com"></td>
                    </tr>
                    <tr>
                        <td style="padding:.3em .6em .3em 0;white-space:nowrap;"><label>Role</label></td>
                        <td>
                            <select name="new_user_role">
                                <?php foreach ( $roles as $roleKey => $roleLabel ) : ?>
                                    <option value="<?php echo esc_attr( $roleKey ); ?>"
                                        <?php selected( $roleKey, 'subscriber' ); ?>>
                                        <?php echo esc_html( $roleLabel ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description" style="margin:.3em 0 0;">User will receive a password-setup email.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Create user & link', 'secondary', 'submit', false ); ?>
            </form>
        </details>
        <?php
    }

    private static function renderAffiliatesTab(): void
    {
        $notice  = isset( $_GET['lgms_aff_ok'] )  ? rawurldecode( (string) $_GET['lgms_aff_ok'] )  : '';
        $err     = isset( $_GET['lgms_aff_err'] ) ? rawurldecode( (string) $_GET['lgms_aff_err'] ) : '';
        $editId  = (int) ( $_GET['lgms_edit_aff'] ?? 0 );

        if ( $notice !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
        <?php endif;
        if ( $err !== '' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
        <?php endif;

        $rows = [];
        try {
            $rows = Db::pdo()->query(
                'SELECT a.id, a.slug, a.label, a.wp_user_id, a.created_at,
                        a.commission_pct, a.commission_pct_annual, a.retention_bonus_pct,
                        COUNT(DISTINCT cl.id)  AS clicks,
                        COUNT(DISTINCT cv.id)  AS conversions,
                        COUNT(DISTINCT CASE WHEN cv.retention_bonus_eligible_at IS NOT NULL THEN cv.id END) AS retention_eligible,
                        COALESCE(SUM(db.amount_cents), 0) AS total_debits_cents
                 FROM affiliates a
                 LEFT JOIN affiliate_clicks      cl ON cl.affiliate_id = a.id
                 LEFT JOIN affiliate_conversions cv ON cv.affiliate_id = a.id
                 LEFT JOIN affiliate_debits      db ON db.affiliate_id = a.id
                 GROUP BY a.id
                 ORDER BY a.created_at DESC'
            )->fetchAll( \PDO::FETCH_ASSOC );
        } catch ( \Throwable $_ ) {}

        $joinBase = home_url( '/lgjoin/' );
        ?>

        <h2 style="margin-top:0;">Affiliate links</h2>
        <p class="description">
            Conversions are tracked when a checkout session started on an affiliate link completes payment.
            Commission rates are informational — use the retention poller script to generate payout reports.
        </p>

        <?php if ( $rows !== [] ) : ?>
        <table class="widefat striped" style="margin-bottom:2em;">
            <thead>
                <tr>
                    <th>Slug / Label</th>
                    <th style="text-align:center;">Clicks</th>
                    <th style="text-align:center;">Conv.</th>
                    <th style="text-align:center;">Rate</th>
                    <th style="text-align:center;">Monthly&nbsp;%</th>
                    <th style="text-align:center;">Annual&nbsp;%</th>
                    <th style="text-align:center;">Retention&nbsp;bonus&nbsp;%</th>
                    <th style="text-align:center;">Retention<br>eligible</th>
                    <th style="text-align:center;">Refund<br>debits</th>
                    <th>Link</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) :
                $link             = add_query_arg( 'ref', esc_attr( (string) $row['slug'] ), $joinBase );
                $clicks           = (int) $row['clicks'];
                $conversions      = (int) $row['conversions'];
                $retEligible      = (int) $row['retention_eligible'];
                $rate             = $clicks > 0 ? round( $conversions / $clicks * 100 ) . '%' : '—';
                $debitsCents      = (int) $row['total_debits_cents'];
                $editUrl          = add_query_arg( [
                    'page'          => self::AFF_PAGE,
                    'lgms_edit_aff' => $row['id'],
                ], admin_url( 'admin.php' ) );
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( (string) $row['label'] ); ?></strong><br>
                        <code style="font-size:11px;"><?php echo esc_html( (string) $row['slug'] ); ?></code>
                    </td>
                    <td style="text-align:center;"><?php echo $clicks; ?></td>
                    <td style="text-align:center;font-weight:700;"><?php echo $conversions; ?></td>
                    <td style="text-align:center;color:<?php echo $clicks > 0 ? '#15803d' : '#aaa'; ?>;"><?php echo $rate; ?></td>
                    <td style="text-align:center;"><?php echo (float) $row['commission_pct'] > 0 ? esc_html( $row['commission_pct'] ) . '%' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo (float) $row['commission_pct_annual'] > 0 ? esc_html( $row['commission_pct_annual'] ) . '%' : '—'; ?></td>
                    <td style="text-align:center;"><?php echo (float) $row['retention_bonus_pct'] > 0 ? esc_html( $row['retention_bonus_pct'] ) . '%' : '—'; ?></td>
                    <td style="text-align:center;<?php echo $retEligible > 0 ? 'font-weight:700;color:#b45309;' : 'color:#aaa;'; ?>">
                        <?php echo $retEligible > 0 ? $retEligible : '—'; ?>
                    </td>
                    <td style="text-align:center;<?php echo $debitsCents > 0 ? 'font-weight:700;color:#dc2626;' : 'color:#aaa;'; ?>">
                        <?php echo $debitsCents > 0 ? '$' . number_format( $debitsCents / 100, 2 ) : '—'; ?>
                    </td>
                    <td style="min-width:240px;">
                        <input type="text" value="<?php echo esc_attr( $link ); ?>"
                               readonly onclick="this.select()"
                               style="width:100%;font-size:11px;font-family:monospace;padding:3px 5px;border:1px solid #ddd;border-radius:3px;">
                    </td>
                    <td><a href="<?php echo esc_url( $editUrl ); ?>">Edit rates</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p><em>No affiliates yet. Create your first one below.</em></p>
        <?php endif; ?>

        <?php
        // ── Edit commission rates ────────────────────────────────────────────
        $editRow = null;
        if ( $editId > 0 ) {
            foreach ( $rows as $r ) {
                if ( (int) $r['id'] === $editId ) { $editRow = $r; break; }
            }
        }
        if ( $editRow !== null ) : ?>
        <h3>Edit commission rates — <?php echo esc_html( (string) $editRow['label'] ); ?></h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:480px;">
            <?php wp_nonce_field( 'lgms_update_affiliate_commission' ); ?>
            <input type="hidden" name="action"       value="lgms_update_affiliate_commission">
            <input type="hidden" name="affiliate_id" value="<?php echo (int) $editRow['id']; ?>">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="lgms-aff-pct">Monthly commission %</label></th>
                    <td>
                        <input type="number" id="lgms-aff-pct" name="commission_pct" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( (string) $editRow['commission_pct'] ); ?>" class="small-text">
                        <p class="description">Paid on monthly subscription conversions.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgms-aff-pct-ann">Annual commission %</label></th>
                    <td>
                        <input type="number" id="lgms-aff-pct-ann" name="commission_pct_annual" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( (string) $editRow['commission_pct_annual'] ); ?>" class="small-text">
                        <p class="description">Paid on annual / one-time conversions. Set higher to incentivise yearly.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>WP User</label></th>
                    <td>
                        <?php
                        $linkedUser  = $editRow['wp_user_id'] ? get_user_by( 'id', (int) $editRow['wp_user_id'] ) : null;
                        $searchNonce = wp_create_nonce( 'lgms_user_search' );
                        ?>
                        <?php self::renderUserSearchField( 'wp_user', $linkedUser, $searchNonce, (int) $editRow['id'] ); ?>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgms-aff-bonus">1-year retention bonus %</label></th>
                    <td>
                        <input type="number" id="lgms-aff-bonus" name="retention_bonus_pct" step="0.01" min="0" max="100"
                               value="<?php echo esc_attr( (string) $editRow['retention_bonus_pct'] ); ?>" class="small-text">
                        <p class="description">% of the member's actual total Stripe invoices in their first year. Paid if still subscribed at the 1-year mark. Run <code>bin/poll-retention.php</code> to generate payout report.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save rates' ); ?>
        </form>
        <?php self::renderCreateAffiliateUserForm( (int) $editRow['id'] ); ?>
        <?php endif; ?>

        <h3>Create a new affiliate</h3>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:480px;">
            <?php wp_nonce_field( 'lgms_create_affiliate' ); ?>
            <input type="hidden" name="action" value="lgms_create_affiliate">
            <table class="form-table" style="margin:0;">
                <tr>
                    <th><label for="lgms-aff-slug">Slug</label></th>
                    <td>
                        <input type="text" id="lgms-aff-slug" name="slug" class="regular-text"
                               placeholder="dan" required>
                        <p class="description">Link will be <code>/lgjoin/?ref=<em>slug</em></code></p>
                        <script>
                        (function() {
                            var el = document.getElementById('lgms-aff-slug');
                            function clean(v) {
                                return v
                                    .replace(/^ref=/i, '')          // strip leading ref=
                                    .toLowerCase()
                                    .replace(/[^a-z0-9-]+/g, '-')  // anything invalid → hyphen
                                    .replace(/-{2,}/g, '-')         // collapse runs
                                    .replace(/^-+|-+$/g, '');       // trim edges
                            }
                            el.addEventListener('input', function() {
                                var pos = el.selectionStart;
                                var cleaned = clean(el.value);
                                if (cleaned !== el.value) {
                                    el.value = cleaned;
                                    el.setSelectionRange(pos, pos);
                                }
                            });
                            el.addEventListener('blur', function() {
                                el.value = clean(el.value);
                            });
                        }());
                        </script>
                    </td>
                </tr>
                <tr>
                    <th><label for="lgms-aff-label">Label</label></th>
                    <td>
                        <input type="text" id="lgms-aff-label" name="label" class="regular-text"
                               placeholder="Dan">
                        <p class="description">Human-readable name. Defaults to the slug if blank.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>WP User</label></th>
                    <td>
                        <?php self::renderUserSearchField( 'wp_user', null, wp_create_nonce( 'lgms_user_search' ), 0 ); ?>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Create affiliate' ); ?>
        </form>
        <?php
    }
}
