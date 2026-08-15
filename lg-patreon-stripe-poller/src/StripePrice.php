<?php

declare(strict_types=1);

namespace LGMS;

use LGMS\Repos\ProductRepo;
use RuntimeException;
use Throwable;

/**
 * The single tier's PRICE — set from the dash (Ian, 2026-08-15: "I'd like to be
 * able to set the price. In the dash.").
 *
 * WHY THIS IS ONE CLASS AND NOT A FORM HANDLER: setting a price is three writes
 * that must not come apart —
 *
 *   1. create the Price in Stripe, under the ONE membership product;
 *   2. write the matching row into our own `prices` table;
 *   3. point NEW joins at it (lgms_stripe_price_id).
 *
 * Step 2 is the one that is easy to forget, and forgetting it is a
 * DOUBLE-CHARGE SHAPE rather than a cosmetic bug. The member pages do not use
 * the lifecycle's tier ruling — they look the price up in our `prices` table —
 * and `membership-pages/web/lgjoin.php` resolves "do you already have a
 * subscription?" with an INNER JOIN through it. A price Stripe knows about and
 * we do not makes an existing subscriber's subscription VANISH from that query,
 * so an already-paying member is offered the join flow again. Measured on dev2:
 * the same lookup returns 4 rows with the price present and 0 without. Nothing
 * back-fills it either — the lifecycle webhook handles checkout and
 * subscription events only, never price.created.
 *
 * So: all three, or none. A price that exists in Stripe but not here is worse
 * than no price at all, and this class is the only supported way to set one.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO — existing subscribers are GRANDFATHERED.
 * Changing the price changes what the NEXT person to join pays and nothing
 * else; every current subscription keeps billing at the price it was created
 * with, because that is Stripe's own behaviour and we do not override it.
 * Migrating existing subscribers is a separate decision of Ian's and is not
 * built here (RestController's self-switch-plan is the member-initiated path
 * and is untouched).
 *
 * SANDBOX ONLY. The lane charter's first rule, and enforced rather than
 * documented: assertTestMode() refuses to run against a live secret key. Ian
 * takes the account out of sandbox himself at cutover; until then a live key
 * reaching this code is a stop-and-report event, not a prompt.
 */
final class StripePrice
{
    /** Where NEW joins are pointed. Read by Wp\CheckoutRestController. */
    public const PRICE_OPT = StripeLifecycle::PRICE_OPT;

    /** The single tier. Same ruling as StripeLifecycle::TIER — not a lookup. */
    public const TIER = StripeLifecycle::TIER;

    /** Stripe's own floor for a recurring charge, and ours: no free tiers here. */
    private const MIN_CENTS = 50;
    /** A guard against a typo turning 20.00 into 2000.00, not a business rule. */
    private const MAX_CENTS = 100000;

    public const INTERVALS = [ 'month' => 'Monthly', 'year' => 'Yearly' ];

    /**
     * Test seam, mirroring StripeLifecycle::$confirmFactory — the gate injects
     * a price-creating client so nothing reaches Stripe.
     *
     * @var null|callable():object
     */
    public static $clientFactory = null;

    public static function _resetForTests(): void
    {
        self::$clientFactory = null;
    }

    /* ------------------------------------------------------------------ */
    /* Reading the current state                                          */
    /* ------------------------------------------------------------------ */

    /** The price NEW joins are pointed at, or '' when none is set (the shipped state). */
    public static function currentPriceId(): string
    {
        return trim( (string) get_option( self::PRICE_OPT, '' ) );
    }

    /**
     * What the dash shows for the current price, or null when unset OR when the
     * option points at something our own table does not know — which is exactly
     * the broken state this class exists to prevent, and the dash says so out
     * loud rather than rendering a blank.
     *
     * @return array{stripe_price_id:string,unit_amount_cents:int,currency:string,interval:?string,product_name:string}|null
     */
    public static function currentPrice(): ?array
    {
        $id = self::currentPriceId();
        if ( $id === '' ) {
            return null;
        }
        try {
            $st = Db::pdo()->prepare(
                'SELECT pr.stripe_price_id, pr.unit_amount_cents, pr.currency, pr.`interval`, p.name AS product_name
                   FROM prices pr
                   JOIN products p ON p.id = pr.product_id
                  WHERE pr.stripe_price_id = ?
                  LIMIT 1'
            );
            $st->execute( [ $id ] );
            $row = $st->fetch( \PDO::FETCH_ASSOC );
        } catch ( Throwable $e ) {
            return null;
        }
        if ( ! $row ) {
            return null;
        }
        return [
            'stripe_price_id'   => (string) $row['stripe_price_id'],
            'unit_amount_cents' => (int) $row['unit_amount_cents'],
            'currency'          => (string) $row['currency'],
            'interval'          => $row['interval'] !== null ? (string) $row['interval'] : null,
            'product_name'      => (string) $row['product_name'],
        ];
    }

    /** True when the option points at a price our own table has never heard of. */
    public static function currentPriceIsOrphaned(): bool
    {
        return self::currentPriceId() !== '' && self::currentPrice() === null;
    }

    /**
     * The ONE membership product every price is created under: the single
     * tier's standard (non-regional) product. Resolved from the catalogue, not
     * hardcoded, so the dash cannot drift from what the webhook syncs.
     *
     * Regional products are deliberately excluded — regional pricing is a set of
     * prices under their OWN products, and this control sets the standard price.
     *
     * @return array{id:int,stripe_product_id:string,name:string}
     * @throws RuntimeException when the catalogue cannot name exactly one
     */
    public static function tierProduct(): array
    {
        $st = Db::pdo()->prepare(
            "SELECT id, stripe_product_id, name
               FROM products
              WHERE ref = ? AND kind = 'membership' AND active = 1 AND region_tag IS NULL
              ORDER BY id"
        );
        $st->execute( [ self::TIER ] );
        $rows = $st->fetchAll( \PDO::FETCH_ASSOC );

        if ( count( $rows ) === 0 ) {
            throw new RuntimeException(
                'No active membership product for ' . self::TIER . '. The Stripe catalogue has not been '
                . 'imported on this box, so there is nothing to attach a price to.'
            );
        }
        if ( count( $rows ) > 1 ) {
            // Never guess which product a member's money should land against.
            throw new RuntimeException(
                'The catalogue has ' . count( $rows ) . ' active products for ' . self::TIER
                . '. Exactly one is required before a price can be set.'
            );
        }
        return [
            'id'                => (int) $rows[0]['id'],
            'stripe_product_id' => (string) $rows[0]['stripe_product_id'],
            'name'              => (string) $rows[0]['name'],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Guards                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * SANDBOX ONLY (lane charter rule 1). A live key here is a stop-and-report
     * event: Ian takes the account out of sandbox himself, at cutover, and no
     * dash control should be able to create a real chargeable price before he
     * has decided the number.
     *
     * @throws RuntimeException on a live key or no key at all
     */
    public static function assertTestMode(): void
    {
        $key = (string) get_option( 'lgms_stripe_secret_key', '' );
        if ( $key === '' ) {
            throw new RuntimeException( 'No Stripe secret key is configured, so no price can be created.' );
        }
        if ( str_starts_with( $key, 'sk_live_' ) || str_starts_with( $key, 'rk_live_' ) ) {
            throw new RuntimeException(
                'This box is configured with a LIVE Stripe key. Setting a price is refused while the '
                . 'soft launch is sandbox-only — creating a live price is a cutover step and Ian does it himself.'
            );
        }
    }

    /**
     * Validate the amount as typed. Returns the amount in cents.
     *
     * @throws RuntimeException with a message written to be shown to Ian
     */
    public static function parseAmount( string $raw ): int
    {
        $raw = trim( $raw );
        if ( $raw === '' ) {
            throw new RuntimeException( 'Enter a price.' );
        }
        // Accept "12", "12.00", "12,00" — reject anything else rather than
        // letting (int) silently read "12abc" as 12.
        $norm = str_replace( ',', '.', $raw );
        if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $norm ) ) {
            throw new RuntimeException( 'Enter a price as a plain number, like 12 or 12.50.' );
        }
        $cents = (int) round( ( (float) $norm ) * 100 );
        if ( $cents < self::MIN_CENTS ) {
            throw new RuntimeException( sprintf(
                'The lowest price Stripe will take is %s.', self::money( self::MIN_CENTS, 'usd' )
            ) );
        }
        if ( $cents > self::MAX_CENTS ) {
            throw new RuntimeException( sprintf(
                'That is over %s — if it is really meant, it has to be set in Stripe directly.',
                self::money( self::MAX_CENTS, 'usd' )
            ) );
        }
        return $cents;
    }

    public static function assertInterval( string $interval ): string
    {
        if ( ! array_key_exists( $interval, self::INTERVALS ) ) {
            throw new RuntimeException( 'Choose monthly or yearly.' );
        }
        return $interval;
    }

    /* ------------------------------------------------------------------ */
    /* The write                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Create the price in Stripe, record it here, and point new joins at it.
     *
     * ORDER MATTERS AND IS DELIBERATE. Stripe first, because a Price cannot be
     * deleted once created and we would rather have an unused price in Stripe
     * than a row here naming one that does not exist. Then our row. The option
     * moves LAST, so at no point does lgms_stripe_price_id name a price the
     * `prices` table has not got — the join page reads that table, and a moment
     * of inconsistency there is the double-charge shape described above.
     *
     * If our row cannot be written, the option is NOT moved and the failure is
     * reported: new joins keep pointing at whatever was working before.
     *
     * @return array{stripe_price_id:string,unit_amount_cents:int,interval:string,product_name:string}
     * @throws RuntimeException with a message written to be shown to Ian
     */
    public static function setPrice( int $cents, string $interval, string $currency = 'usd' ): array
    {
        self::assertTestMode();
        $interval = self::assertInterval( $interval );
        $product  = self::tierProduct();
        $currency = strtolower( $currency );

        /* 1. Stripe. */
        try {
            $client = self::$clientFactory !== null
                ? ( self::$clientFactory )()
                : new \LGMS\Stripe\Client();
            $price = $client->createPrice( [
                'product'     => $product['stripe_product_id'],
                'currency'    => $currency,
                'unit_amount' => $cents,
                'recurring'   => [ 'interval' => $interval ],
                'metadata'    => [ 'set_by' => 'lgms-dash', 'tier' => self::TIER ],
            ] );
        } catch ( Throwable $e ) {
            Log::line( sprintf( "[%s] price create FAILED (%d %s/%s): %s\n",
                gmdate( 'c' ), $cents, $currency, $interval, $e->getMessage() ) );
            throw new RuntimeException( 'Stripe would not create the price: ' . $e->getMessage() );
        }

        $priceId = (string) ( $price->id ?? '' );
        if ( $priceId === '' ) {
            throw new RuntimeException( 'Stripe accepted the price but did not return an id — nothing was changed here.' );
        }

        /* 2. Our own table — the step whose absence is a double-charge shape. */
        try {
            // Explicit exists-then-write rather than an upsert: this must behave
            // identically on MySQL (production) and SQLite (the gate's rig), and
            // ON DUPLICATE KEY UPDATE is MySQL-only. Stripe mints a fresh id per
            // call, so the UPDATE branch is for a retry after a partial failure,
            // not the normal path.
            $pdo  = Db::pdo();
            $seen = $pdo->prepare( 'SELECT id FROM prices WHERE stripe_price_id = ? LIMIT 1' );
            $seen->execute( [ $priceId ] );

            if ( $seen->fetchColumn() !== false ) {
                $st = $pdo->prepare(
                    'UPDATE prices
                        SET product_id = ?, type = ?, `interval` = ?, unit_amount_cents = ?, currency = ?, active = 1
                      WHERE stripe_price_id = ?'
                );
                $st->execute( [ $product['id'], 'recurring', $interval, $cents, $currency, $priceId ] );
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO prices (product_id, stripe_price_id, type, `interval`, unit_amount_cents, currency, active)
                     VALUES (?, ?, ?, ?, ?, ?, 1)'
                );
                $st->execute( [ $product['id'], $priceId, 'recurring', $interval, $cents, $currency ] );
            }
        } catch ( Throwable $e ) {
            Log::line( sprintf( "[%s] price %s created in Stripe but NOT recorded locally: %s\n",
                gmdate( 'c' ), $priceId, $e->getMessage() ) );
            throw new RuntimeException(
                'The price was created in Stripe but could not be recorded here, so new joins have NOT been '
                . 'pointed at it — nothing has changed for members. The price to record is ' . $priceId . '.'
            );
        }

        /* 3. Only now do new joins move. */
        update_option( self::PRICE_OPT, $priceId, false );

        Log::line( sprintf( "[%s] price set: %s (%d %s/%s) under %s\n",
            gmdate( 'c' ), $priceId, $cents, $currency, $interval, $product['stripe_product_id'] ) );

        return [
            'stripe_price_id'   => $priceId,
            'unit_amount_cents' => $cents,
            'interval'          => $interval,
            'product_name'      => $product['name'],
        ];
    }

    /* ------------------------------------------------------------------ */

    /** "12.00" as "$12.00" — display only. */
    public static function money( int $cents, string $currency = 'usd' ): string
    {
        $sym = [ 'usd' => '$', 'gbp' => '£', 'eur' => '€' ][ strtolower( $currency ) ] ?? '';
        return $sym . number_format( $cents / 100, 2 );
    }
}
