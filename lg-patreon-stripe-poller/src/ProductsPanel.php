<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Membership\ProductCatalog;
use Throwable;

/**
 * ProductsPanel — the Products tab, and its write handler. Issue #194.
 *
 * Ian, 2026-08-22: *"Do we have a spot in the dash where we register the stripe
 * products. Like looth-lite regional A ?"* This is that spot. Before it, the
 * only way to map a Stripe product to a membership tier was six hand-run
 * UPDATE statements against the live database on launch night.
 *
 * ⚠️ IT IS ITS OWN CLASS FOR THE REASON HealthPanel AND TesterUnlockPanel ARE
 * (#190, #192): so a gate can drive it without loading `Admin.php`. That file
 * reaches StripeLifecycle, StripePrice, Invites, CompExpiry and MemberTools,
 * and its neighbouring test file has died at exit 255 with NO FAIL LINE three
 * separate times because the door gained a dependency nobody added to a require
 * list. A screen that can only be exercised by loading all of that is a screen
 * that will eventually stop being exercised.
 *
 * It owns its own `admin_post` handler for the same reason, and for one more:
 * lane 193 is in `Admin.php`'s Testers region while this is being built, so
 * this lane's footprint there is three lines — one registration, one tab entry,
 * one match arm.
 *
 * ⚠️ EVERY DECISION LIVES IN Membership\ProductCatalog. This class chooses
 * words and colours and does no validation of its own, so there is exactly one
 * definition of what a tier is and one writer of the columns.
 */
final class ProductsPanel
{
    public const NONCE   = 'lgms_product_set';
    public const ACTION  = 'lgms_product_set';

    public static function boot(): void
    {
        add_action( 'admin_post_' . self::ACTION, [ self::class, 'handleSet' ] );
    }

    /* --------------------------------------------------------------------- */
    /* The write                                                             */
    /* --------------------------------------------------------------------- */

    private static function back( array $extra ): void
    {
        wp_safe_redirect( add_query_arg(
            array_merge( [ 'page' => 'lg-member-sync', 'tab' => 'products' ], $extra ),
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /**
     * Money-adjacent, so both locks and never one: the capability AND the
     * nonce. Neither is decoration — this row decides what a payment buys.
     */
    public static function handleSet(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Nope.' );
        }
        check_admin_referer( self::NONCE );

        try {
            $result = ProductCatalog::apply(
                sanitize_text_field( (string) ( $_POST['product'] ?? '' ) ),
                sanitize_key( (string) ( $_POST['tier'] ?? '' ) ),
                sanitize_key( (string) ( $_POST['region'] ?? '' ) ),
                (int) get_current_user_id()
            );
        } catch ( Throwable $e ) {
            self::back( [ 'lgms_prod_err' => rawurlencode( $e->getMessage() ) ] );
            return;
        }

        if ( ! $result['changed'] ) {
            self::back( [ 'lgms_prod_ok' => rawurlencode( sprintf(
                '%s was already set to %s, %s — nothing changed.',
                $result['name'],
                ProductCatalog::tierLabel( $result['to']['ref'] ),
                ProductCatalog::regionLabel( $result['to']['region_tag'] )
            ) ) ] );
            return;
        }

        self::back( [ 'lgms_prod_ok' => rawurlencode( sprintf(
            '%s is now %s (%s). It was %s (%s).',
            $result['name'],
            ProductCatalog::tierLabel( $result['to']['ref'] ),
            ProductCatalog::regionLabel( $result['to']['region_tag'] ),
            ProductCatalog::tierLabel( $result['from']['ref'] ),
            ProductCatalog::regionLabel( $result['from']['region_tag'] )
        ) ) ] );
    }

    /* --------------------------------------------------------------------- */
    /* The screen                                                            */
    /* --------------------------------------------------------------------- */

    public static function render(): void
    {
        $ok  = isset( $_GET['lgms_prod_ok'] )  ? rawurldecode( (string) $_GET['lgms_prod_ok'] )  : '';
        $err = isset( $_GET['lgms_prod_err'] ) ? rawurldecode( (string) $_GET['lgms_prod_err'] ) : '';

        $products = null;
        $dbError  = '';
        try {
            $products = ProductCatalog::products();
        } catch ( Throwable $e ) {
            /* A dead database says so. It must never render as an empty
               catalogue, because "nothing is registered" and "I cannot see what
               is registered" need opposite responses — the same discipline
               #192's Health panel follows, where `unknown` may not collapse
               into `ok`. */
            $dbError = $e->getMessage();
        }
        ?>
        <style>
            .lgms-p-chip { display:inline-block; padding:.1em .55em; border-radius:3px; font-size:11.5px; font-weight:600; white-space:nowrap; }
            .lgms-p-ok   { background:#dcfce7; color:#15803d; }
            .lgms-p-off  { background:#f0f0f1; color:#646970; }
            .lgms-p-red  { background:#fee2e2; color:#b91c1c; }
            .lgms-p-reg  { background:#e0e7ff; color:#3730a3; }
            .lgms-p-tbl  { width:100%; border-collapse:collapse; background:#fff; border:1px solid #c3c4c7; font-size:13px; max-width:1180px; }
            .lgms-p-tbl th { text-align:left; background:#f6f7f7; border-bottom:1px solid #c3c4c7; padding:9px 10px; }
            .lgms-p-tbl td { border-bottom:1px solid #f0f0f1; padding:10px; vertical-align:top; }
            .lgms-p-tbl tr.is-unmapped td { background:#fef1f1; }
            .lgms-p-tbl tr.is-archived td { background:#fcfcfc; color:#8c8f94; }
            .lgms-p-name { font-weight:600; }
            .lgms-p-sid  { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:11.5px; color:#787c82; }
            .lgms-p-prices { margin:0; padding:0; list-style:none; }
            .lgms-p-prices li { white-space:nowrap; }
            .lgms-p-prices li.off { color:#a7aaad; text-decoration:line-through; }
            .lgms-p-more { color:#787c82; font-size:12px; }
            .lgms-p-authority { background:#fff; border:1px solid #c3c4c7; border-left:4px solid #d97706; padding:12px 16px; margin:0 0 18px; max-width:900px; }
        </style>

        <h2 style="margin-top:0;">Products</h2>

        <?php if ( $ok !== '' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $ok ); ?></p></div>
        <?php endif; ?>
        <?php if ( $err !== '' ) : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
        <?php endif; ?>

        <div class="lgms-p-authority">
            <p style="margin:0 0 .4em;">
                <strong>This screen is the authority for a product&rsquo;s tier and region.</strong>
                Stripe never sets them and never overwrites them &mdash; a <code>product.updated</code> event syncs
                only the product&rsquo;s <em>name</em> and whether it is active.
            </p>
            <p style="margin:0;color:#50575e;">
                So if a tier looks wrong, it is changed here. There is no such setting in the Stripe dashboard to go
                looking for. Products themselves arrive here from Stripe; what they are <em>worth</em> is set here.
            </p>
        </div>

        <?php if ( $dbError !== '' ) : ?>
            <div class="notice notice-error"><p>
                <strong>Cannot see the billing database from here, so this screen cannot show you anything.</strong><br>
                <?php echo esc_html( $dbError ); ?><br>
                <span style="color:#50575e;">This is not the same as an empty catalogue &mdash; check the database
                settings on the <strong>Settings</strong> tab.</span>
            </p></div>
            <?php return; ?>
        <?php endif; ?>

        <?php
        $unmapped = ProductCatalog::unmappedActiveCount( $products );
        $active   = 0;
        foreach ( $products as $p ) { if ( $p['active'] ) { $active++; } }
        ?>

        <p style="margin:0 0 14px;">
            <?php if ( $unmapped > 0 ) : ?>
                <span class="lgms-p-chip lgms-p-red"><?php echo (int) $unmapped; ?></span>
                active membership product<?php echo $unmapped === 1 ? '' : 's'; ?> with <strong>no tier</strong> &mdash;
                a buyer who reaches those prices is refused with <em>&ldquo;not mapped to a membership tier&rdquo;</em>.
                They are red below.
            <?php else : ?>
                <span class="lgms-p-chip lgms-p-ok">0</span>
                active membership products with no tier.
            <?php endif; ?>
            <span style="color:#787c82;">
                This is the same number the <strong>Health</strong> tab reports; the two cannot disagree.
            </span>
        </p>

        <?php if ( $products === [] ) : ?>
            <div class="notice notice-warning"><p>
                <strong>The catalogue is empty &mdash; no products have been synced to this box.</strong><br>
                <span style="color:#50575e;">Products appear here when Stripe sends a <code>product.created</code>
                webhook, which happens when the catalogue is registered against this box&rsquo;s Stripe account.
                Until then there is nothing to map, and nothing can be bought.</span>
            </p></div>
            <?php return; ?>
        <?php endif; ?>

        <table class="lgms-p-tbl">
            <thead>
                <tr>
                    <th style="width:26%">Product</th>
                    <th style="width:9%">State</th>
                    <th style="width:12%">Region</th>
                    <th style="width:25%">Prices</th>
                    <th style="width:28%">Tier &amp; region</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $products as $p ) :
                $rowClass = $p['unmapped'] ? 'is-unmapped' : ( $p['active'] ? '' : 'is-archived' );
                ?>
                <tr class="<?php echo esc_attr( $rowClass ); ?>">
                    <td>
                        <span class="lgms-p-name"><?php echo esc_html( $p['name'] ); ?></span><br>
                        <span class="lgms-p-sid"><?php echo esc_html( $p['stripe_product_id'] ); ?></span>
                        <?php if ( $p['kind'] !== ProductCatalog::KIND ) : ?>
                            <br><span class="lgms-p-chip lgms-p-off">kind: <?php echo esc_html( $p['kind'] ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $p['active'] ) : ?>
                            <span class="lgms-p-chip lgms-p-ok">active</span>
                        <?php else : ?>
                            <span class="lgms-p-chip lgms-p-off">archived</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $p['region_tag'] === '' ) : ?>
                            <span class="lgms-p-chip lgms-p-off">standard</span>
                        <?php else : ?>
                            <span class="lgms-p-chip lgms-p-reg"><?php echo esc_html( $p['region_tag'] ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php self::renderPrices( $p['prices'] ); ?></td>
                    <td>
                        <?php if ( $p['unmapped'] ) : ?>
                            <p style="margin:0 0 .5em;">
                                <span class="lgms-p-chip lgms-p-red">NO TIER &mdash; nobody can buy this</span>
                            </p>
                        <?php endif; ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                              style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                            <?php wp_nonce_field( self::NONCE ); ?>
                            <input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
                            <input type="hidden" name="product" value="<?php echo esc_attr( $p['stripe_product_id'] ); ?>">

                            <label class="screen-reader-text" for="tier-<?php echo (int) $p['id']; ?>">Tier</label>
                            <select name="tier" id="tier-<?php echo (int) $p['id']; ?>">
                                <option value=""<?php selected( $p['ref'], '' ); ?>>&mdash; no tier &mdash;</option>
                                <?php foreach ( ProductCatalog::SELLABLE as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"<?php selected( $p['ref'], $slug ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="screen-reader-text" for="region-<?php echo (int) $p['id']; ?>">Region</label>
                            <select name="region" id="region-<?php echo (int) $p['id']; ?>">
                                <?php foreach ( ProductCatalog::REGIONS as $tag => $label ) : ?>
                                    <option value="<?php echo esc_attr( $tag ); ?>"<?php selected( $p['region_tag'], $tag ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <button type="submit" class="button<?php echo $p['unmapped'] ? ' button-primary' : ''; ?>">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="description" style="max-width:900px;margin-top:14px;">
            <strong><?php echo (int) $active; ?></strong> of <strong><?php echo count( $products ); ?></strong>
            products are active. Archived products cannot be bought, so an archived product with no tier is not a
            problem and is not shown in red. Every change here writes one line to the billing database&rsquo;s
            audit log recording who changed what, and from what to what.
        </p>
        <?php
    }

    /**
     * Active prices first and plainly; archived ones behind a count.
     *
     * ⚠️ THE COUNT IS NOT TIDINESS. Measured on dev2 2026-08-22: Looth LITE
     * carries TEN price rows, SEVEN of them archived, and drawing all of them
     * would bury the three a member can actually reach. This is the same class
     * of defect as the four-button join card (#148 state notes), where a page
     * listed every active price and showed two monthly prices at once.
     *
     * @param array<int,array<string,mixed>> $prices
     */
    private static function renderPrices( array $prices ): void
    {
        $live = array_values( array_filter( $prices, static fn( $p ) => $p['active'] ) );
        $dead = count( $prices ) - count( $live );

        if ( $prices === [] ) {
            echo '<span class="lgms-p-more">no prices</span>';
            return;
        }

        echo '<ul class="lgms-p-prices">';
        foreach ( $live as $p ) {
            printf(
                '<li>%s %s</li>',
                esc_html( StripePrice::money( (int) $p['unit_amount_cents'], (string) $p['currency'] ) ),
                esc_html( self::cadence( $p ) )
            );
        }
        if ( $live === [] ) {
            echo '<li class="lgms-p-more">no active prices</li>';
        }
        if ( $dead > 0 ) {
            printf(
                '<li class="lgms-p-more">%d archived price%s</li>',
                $dead,
                $dead === 1 ? '' : 's'
            );
        }
        echo '</ul>';
    }

    /** @param array<string,mixed> $p */
    private static function cadence( array $p ): string
    {
        if ( (string) $p['type'] !== 'recurring' ) {
            return 'one-time';
        }
        $i = $p['interval'] !== null ? (string) $p['interval'] : '';
        return $i === '' ? 'recurring' : '/ ' . $i;
    }
}
