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
    /**
     * The LEGACY single-price option. Kept as a read-only fallback for the
     * MONTHLY slot so a box configured before cadences existed keeps working;
     * nothing writes it any more.
     */
    public const PRICE_OPT = StripeLifecycle::PRICE_OPT;

    /**
     * TWO CADENCES (Ian, 2026-08-15: "We need a monthly and a yearly price
     * etc." — his Patreon shape, 5/month or 60/year).
     *
     * Cadence is how often they pay. TIER is what they get. Keeping the two
     * words apart matters here because they used to be the same thing: under
     * the 8/08 one-tier ruling both prices sat under one product, so "which
     * price" only ever meant "how often".
     */
    public const CADENCES = [ 'month' => 'Monthly', 'year' => 'Yearly' ];

    /**
     * One option per TIER per CADENCE, so no two can quietly overwrite each
     * other — the same reasoning that split the single option into two when
     * cadences arrived, applied one dimension further out.
     *
     * MULTI-TIER (Ian, 2026-08-19: "I've decided I want to be able to have
     * multiple tiers"). The tier is part of the KEY rather than a value inside
     * one option, because the failure mode of a single option holding a map is
     * that a partial write loses a tier's price silently, and a lost price is
     * a tier nobody can buy.
     */
    public static function priceOpt( string $cadence, ?string $tier = null ): string
    {
        $tier = $tier ?? self::TIER;
        return 'lgms_stripe_price_' . $tier . '_' . $cadence;
    }

    /**
     * The LEGACY per-cadence option, which predates tiers. Read-only, and only
     * ever for the DEFAULT tier — see currentPriceId() for why a non-default
     * tier must never borrow it.
     */
    public static function legacyCadenceOpt( string $cadence ): string
    {
        return 'lgms_stripe_price_' . $cadence;
    }

    /**
     * The DEFAULT tier — what an unqualified "the price" means, and the tier
     * every member granted under the one-tier ruling holds. Same value as
     * StripeLifecycle::TIER so the dash and the grant cannot drift.
     */
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

    /**
     * The price NEW joins are pointed at for one cadence, or '' when unset
     * (the shipped state for both).
     *
     * The legacy single option answers for MONTHLY only, and only when the
     * monthly slot is empty — so an older box keeps selling what it was
     * selling, and a configured monthly price always wins over it.
     */
    public static function currentPriceId( string $cadence = 'month', ?string $tier = null ): string
    {
        $tier = $tier ?? self::TIER;

        $v = trim( (string) get_option( self::priceOpt( $cadence, $tier ), '' ) );
        if ( $v !== '' ) {
            return $v;
        }

        // THE FALLBACK CHAIN IS DELIBERATELY CLOSED TO NON-DEFAULT TIERS.
        //
        // Both legacy options were written in a world with exactly one tier, so
        // the price they name is that tier's price. Letting looth2 read them
        // would sell Looth LITE at the Pro price — worse, it would do it
        // SILENTLY, on a box that looked configured. An unset tier must read as
        // "not offered" and nothing else.
        if ( $tier !== self::TIER ) {
            return '';
        }

        $v = trim( (string) get_option( self::legacyCadenceOpt( $cadence ), '' ) );
        if ( $v !== '' ) {
            return $v;
        }
        if ( $cadence === 'month' ) {
            $v = trim( (string) get_option( self::PRICE_OPT, '' ) );   // the original single option
        }
        return $v;
    }

    /**
     * Every tier the CATALOGUE holds, in order. Not a constant, and not a dash
     * form either: tier CREATION stays the catalogue file plus the import
     * command (Ian's 8/19 scope ruling — "the dash gains PER-TIER pricing
     * only"). Registering a product is therefore the whole of adding a tier,
     * and this method is how the dash finds out.
     *
     * Regional products are excluded and the list is de-duplicated, so a tier
     * with three regional variants is still ONE tier — the regional prices hang
     * off their own products and are not what this control sets.
     *
     * @return string[] e.g. ['looth2','looth3']
     */
    public static function tiers(): array
    {
        try {
            $rows = Db::pdo()->query(
                "SELECT DISTINCT ref FROM products
                  WHERE kind = 'membership' AND active = 1 AND region_tag IS NULL AND ref IS NOT NULL AND ref <> ''
                  ORDER BY ref"
            )->fetchAll( \PDO::FETCH_COLUMN );
        } catch ( Throwable $e ) {
            return [];
        }
        return array_values( array_unique( array_map( 'strval', $rows ) ) );
    }

    /** Every cadence that currently has a price, in offer order. */
    public static function configuredCadences( ?string $tier = null ): array
    {
        $out = [];
        foreach ( array_keys( self::CADENCES ) as $c ) {
            if ( self::currentPriceId( $c, $tier ) !== '' ) { $out[] = $c; }
        }
        return $out;
    }

    /**
     * Every tier that currently has at least one price, in catalogue order.
     * A registered tier with no price is NOT offered — the same rule cadences
     * already follow, so a half-configured tier cannot reach a member.
     *
     * @return string[]
     */
    public static function configuredTiers(): array
    {
        $out = [];
        foreach ( self::tiers() as $t ) {
            if ( self::configuredCadences( $t ) !== [] ) { $out[] = $t; }
        }
        return $out;
    }

    /**
     * What the dash shows for the current price, or null when unset OR when the
     * option points at something our own table does not know — which is exactly
     * the broken state this class exists to prevent, and the dash says so out
     * loud rather than rendering a blank.
     *
     * @return array{stripe_price_id:string,unit_amount_cents:int,currency:string,interval:?string,product_name:string}|null
     */
    public static function currentPrice( string $cadence = 'month', ?string $tier = null ): ?array
    {
        $id = self::currentPriceId( $cadence, $tier );
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
    public static function currentPriceIsOrphaned( string $cadence = 'month', ?string $tier = null ): bool
    {
        return self::currentPriceId( $cadence, $tier ) !== '' && self::currentPrice( $cadence, $tier ) === null;
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
    public static function tierProduct( ?string $tier = null ): array
    {
        $tier = $tier ?? self::TIER;
        $st = Db::pdo()->prepare(
            "SELECT id, stripe_product_id, name
               FROM products
              WHERE ref = ? AND kind = 'membership' AND active = 1 AND region_tag IS NULL
              ORDER BY id"
        );
        $st->execute( [ $tier ] );
        $rows = $st->fetchAll( \PDO::FETCH_ASSOC );

        if ( count( $rows ) === 0 ) {
            throw new RuntimeException(
                'No active membership product for ' . $tier . '. The Stripe catalogue has not been '
                . 'imported on this box, so there is nothing to attach a price to.'
            );
        }
        if ( count( $rows ) > 1 ) {
            // Never guess which product a member's money should land against.
            throw new RuntimeException(
                'The catalogue has ' . count( $rows ) . ' active products for ' . $tier
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

    /**
     * The tier must be one the CATALOGUE actually holds. Never guessed, never
     * created here: a tier that is not a registered product has no product to
     * hang a price on, and inventing one would put a price in Stripe against a
     * membership this site cannot grant.
     *
     * @throws RuntimeException with a message written to be shown to Ian
     */
    public static function assertTier( ?string $tier ): string
    {
        $tier = trim( (string) ( $tier ?? self::TIER ) );
        if ( $tier === '' ) {
            $tier = self::TIER;
        }
        $known = self::tiers();
        if ( $known === [] ) {
            throw new RuntimeException(
                'The Stripe catalogue has not been imported on this box, so there are no tiers to price.'
            );
        }
        if ( ! in_array( $tier, $known, true ) ) {
            throw new RuntimeException( sprintf(
                'There is no membership tier called "%s" in the catalogue. Registered tiers: %s.',
                $tier, implode( ', ', $known )
            ) );
        }
        return $tier;
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
    public static function setPrice( int $cents, string $interval, ?string $tier = null, string $currency = 'usd' ): array
    {
        self::assertTestMode();
        $interval = self::assertInterval( $interval );
        $tier     = self::assertTier( $tier );
        $product  = self::tierProduct( $tier );
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
                'metadata'    => [ 'set_by' => 'lgms-dash', 'tier' => $tier ],
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
        update_option( self::priceOpt( $interval, $tier ), $priceId, false );

        /* 4. Retire every other price for this membership + rhythm. */
        //
        // WHY THIS IS NOT COSMETIC. The join page renders one button per ACTIVE
        // recurring price (lgjoin.php renderTiers), with no de-duplication by
        // cadence — so a tier priced twice offers two different monthly prices
        // for the same membership. Photographed on dev2 before this landed: the
        // Looth PRO card showed FOUR buttons — $11/month AND $9/month, $132/year
        // AND $99/year.
        //
        // IT SWEEPS THE WHOLE (product, interval), NOT JUST THE PRICE THE OPTION
        // POINTED AT, and that distinction is the entire fix. dev2 is the proof:
        // the options named prices 30 and 31, while 11 and 12 sat active and
        // unpointed from an earlier round. Retiring only the superseded pointer
        // would have deactivated 30 and left 11 — still two monthly buttons, on
        // a control whose whole job was to stop that.
        //
        // IT IS SAFE FOR THE MEMBERS STILL BILLING ON THOSE PRICES, and that is
        // the part worth checking rather than assuming, because the neighbouring
        // mistake is the double-charge shape this class exists to prevent:
        //   - lgjoin's "do you already have a subscription?" lookup joins
        //     `prices` with NO active filter, so a retired row does not make an
        //     existing subscriber vanish;
        //   - both tierForPrice implementations filter on the PRODUCT's active
        //     flag, never the price row's, so their tier still resolves.
        // Gate 76 §8 asserts both of those, not merely the count.
        //
        // One-time prices are left alone: they are a different product shape
        // (a fixed-duration purchase), not a competing rhythm.
        //
        // Our table only. A Stripe price cannot be deleted, and deactivating it
        // THERE would break the subscriptions billing against it.
        try {
            $st = Db::pdo()->prepare(
                'UPDATE prices SET active = 0
                  WHERE product_id = ? AND `interval` = ? AND type = ? AND stripe_price_id <> ?'
            );
            $st->execute( [ $product['id'], $interval, 'recurring', $priceId ] );
            $retired = $st->rowCount();
            if ( $retired > 0 ) {
                Log::line( sprintf( "[%s] retired %d superseded %s price(s) for %s\n",
                    gmdate( 'c' ), $retired, $interval, $tier ) );
            }
        } catch ( Throwable $e ) {
            // Never fatal: new joins are already pointed at the new price, which
            // is the part that had to be right. The worst case is an old price
            // staying on the join page, which is visible.
            Log::line( sprintf( "[%s] could not retire superseded %s price(s) for %s: %s\n",
                gmdate( 'c' ), $interval, $tier, $e->getMessage() ) );
        }

        Log::line( sprintf( "[%s] price set: %s (%d %s/%s) for %s under %s\n",
            gmdate( 'c' ), $priceId, $cents, $currency, $interval, $tier, $product['stripe_product_id'] ) );

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
