<?php

declare(strict_types=1);

namespace LGMS\Membership;

use LGMS\Db;
use PDO;
use RuntimeException;
use Throwable;

/**
 * ProductCatalog — the ONE writer of `products.ref`. Issue #194.
 *
 * Ian, 2026-08-22, reading the go-live checklist: *"Do we have a spot in the
 * dash where we register the stripe products. Like looth-lite regional A ?"*
 * Measured answer at the time: no. `Membership\Health` REPORTED the problem —
 * it counts "Products with NO tier ref" and says plainly that a buyer who
 * reaches such a price pays and is granted nothing — and there was no way
 * anywhere in WordPress to SET it. Step G of the live runbook meant six
 * hand-run UPDATE statements against the live database, at launch, at night.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ WHY THIS CLASS LIVES IN THE POLLER AND NOT IN THE BILLING APP
 * ---------------------------------------------------------------------------
 * The obvious home is `lg-stripe-billing`, which owns this table and already
 * has `PdoProductRepository`. MEASURED 2026-08-22, and it is the wrong home:
 *
 *   - on dev2, `/srv/lg-stripe-billing` is a SYMLINK into the serving checkout,
 *     so it moves with a monorepo pull;
 *   - on LIVE it is a REAL DIRECTORY with its OWN `.git`, tracking a separate
 *     repository. #192's `src/Core/WebhookReceipts.php` is not there at all.
 *
 * So the billing app on live is deployed on a different schedule from the
 * monorepo and is behind it. WordPress requiring code out of that path would
 * refuse to write on live — the exact box, and the exact night, this tab exists
 * for. It therefore lives here, beside `Db::pdo()`, which is how `Health`,
 * `StripePrice` and `Repos\ProductRepo` already reach this database.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ STRIPE CANNOT UNDO WHAT THIS WRITES, AND THAT IS VERIFIED, NOT ASSUMED
 * ---------------------------------------------------------------------------
 * `PdoProductRepository::upsertProduct`'s `ON DUPLICATE KEY UPDATE` names only
 * `name` and `active`; `ProductSyncHandler::handleProductEvent` passes `ref` as
 * null and it is used on first INSERT only. So a `product.updated` event can
 * never overwrite a mapping made here. Gate 93 §D asserts it by running the
 * REAL sync handler over a mapped row, and by reading `upsertProduct`'s SQL
 * through PHP's tokenizer — because the comment saying so has no way of staying
 * true on its own.
 *
 * THE SCREEN SAYS SO OUT LOUD (the issue asks for it): nobody should go looking
 * in the Stripe dashboard for a setting that lives here.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ WHAT COUNTS AS A TIER, AND WHY NOT `StripePrice::assertTier()`
 * ---------------------------------------------------------------------------
 * That method validates against the tiers ALREADY PRESENT in the catalogue —
 * correct for pricing something registered, and circular here. On a box where
 * nothing is mapped yet it throws *"the catalogue has not been imported"*, so
 * reusing it would mean this tab could never make the FIRST mapping. That box
 * is live, and that moment is go-live.
 *
 * Validation is against WordPress roles instead, which `docs/TIER-TAXONOMY.md`
 * names the system of record for user tier, narrowed by SELLABLE below. Two
 * locks, deliberately: a tier must be something we are willing to sell AND
 * something this box can actually grant.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ THE AUDIT LINE IS PART OF THE WRITE, NOT A BEST EFFORT
 * ---------------------------------------------------------------------------
 * This is the exact opposite of `LGSB\Core\WebhookReceipts`, which swallows
 * every Throwable — and both are right. There, the priority is the response
 * Stripe gets, and bookkeeping must never turn a delivered webhook into a
 * three-day retry. Here, the change IS the point: a money-adjacent mapping that
 * nobody can account for afterwards is worse than no mapping. So the UPDATE and
 * the audit row share one transaction, and if the receipt cannot be written the
 * change is rolled back.
 *
 * `audit_log` and NOT `admin_action_log`: the latter has `customer_id NOT NULL`
 * with a FOREIGN KEY to `customers`, and a product mapping has no customer.
 * `audit_log` has no FK on `subject_id` and already names 'admin' in its own
 * `actor_type` comment. Same table #192 reuses, for the same deployment reason:
 * no migration, and nothing for Ian to run on live.
 */
final class ProductCatalog
{
    /**
     * The tiers a card payment may buy.
     *
     * looth1 and looth4 are deliberately absent even though both are real
     * roles. looth1 is the unpaid resting tier every account falls back to, and
     * looth4 is the permanent comp bypass the Arbiter short-circuits on — see
     * docs/TIER-TAXONOMY.md. Selling either would mean a card payment buying
     * something that is not a paid membership at all.
     */
    public const SELLABLE = [
        'looth2' => 'Looth Lite (looth2)',
        'looth3' => 'Looth Pro (looth3)',
    ];

    /**
     * The regions, keyed by what goes in the column. '' is the standard
     * product; the two regional tags are the discount ladders migration 005
     * defines.
     *
     * ⚠️ NOTHING ELSE WRITES THIS COLUMN. The Stripe webhook never sets it
     * (`handleProductEvent` does not pass it), so before this tab the only way
     * a product became "Regional A" was the hand-run SQL the import script
     * prints. That is why the region sits in the same control as the tier
     * rather than being left out: Ian's own example was "looth-lite regional
     * A", and a tab that sets half of that still sends him to a SQL prompt on
     * launch night.
     */
    public const REGIONS = [
        ''           => 'Standard',
        'regional_a' => 'Regional A',
        'regional_b' => 'Regional B',
    ];

    /** What this dash registers. Matches the import script's stamp. */
    public const KIND = 'membership';

    public const AUDIT_ACTION  = 'product_mapping_changed';
    public const AUDIT_SUBJECT = 'product';

    /**
     * Test seam. The gate points this at its own PDO so every query below is
     * exercised for real against a real (SQLite) database, rather than being
     * replaced by a stubbed return value.
     *
     * @var null|callable():PDO
     */
    public static $pdoFactory = null;

    public static function _resetForTests(): void
    {
        self::$pdoFactory = null;
    }

    private static function pdo(): PDO
    {
        $f = self::$pdoFactory;
        return $f !== null ? $f() : Db::pdo();
    }

    /* --------------------------------------------------------------------- */
    /* Validation                                                            */
    /* --------------------------------------------------------------------- */

    /**
     * A tier we are willing to sell AND that this box can grant, or '' for the
     * explicit "no tier" state.
     *
     * '' is a real answer, not a failure: un-mapping a product is how a
     * mistaken mapping is undone, and it is what an archived product should
     * say. It becomes NULL in the column, which is what Health reads.
     */
    public static function assertTier( string $tier ): string
    {
        $tier = trim( $tier );

        if ( $tier === '' ) {
            return '';
        }

        if ( ! isset( self::SELLABLE[ $tier ] ) ) {
            throw new RuntimeException( sprintf(
                'There is no membership tier called "%s" that can be sold. Choose one of: %s — or "none". '
                . '(looth1 is the unpaid resting tier and looth4 is the permanent comp bypass; neither is '
                . 'something a card payment may buy.)',
                $tier,
                implode( ', ', array_keys( self::SELLABLE ) )
            ) );
        }

        /* The second lock. docs/TIER-TAXONOMY.md: "User tier = WordPress roles.
           WP is the system-of-record." A tier this box cannot actually grant is
           not a tier, however good it looks in a dropdown. */
        if ( function_exists( 'get_role' ) && get_role( $tier ) === null ) {
            throw new RuntimeException( sprintf(
                'This site has no "%s" role, so nothing could be granted to a member who bought it. '
                . 'Nothing was changed.',
                $tier
            ) );
        }

        return $tier;
    }

    /** A known region tag, or null for the standard product. */
    public static function assertRegion( string $region ): ?string
    {
        $region = trim( $region );

        if ( ! array_key_exists( $region, self::REGIONS ) ) {
            throw new RuntimeException( sprintf(
                'There is no region called "%s". Choose one of: %s.',
                $region,
                implode( ', ', array_map(
                    static fn( $k ) => $k === '' ? 'standard' : $k,
                    array_keys( self::REGIONS )
                ) )
            ) );
        }

        return $region === '' ? null : $region;
    }

    /* --------------------------------------------------------------------- */
    /* Reading                                                               */
    /* --------------------------------------------------------------------- */

    /**
     * Every product the webhook has synced, each with its prices.
     *
     * Two queries and a group in PHP rather than one joined query, because a
     * product with eleven price rows — which dev2's Looth LITE has — would
     * otherwise be eleven rows to un-pick, and the row-per-product shape is
     * what the screen draws.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function products(): array
    {
        $pdo = self::pdo();

        $products = $pdo->query(
            'SELECT id, stripe_product_id, kind, ref, region_tag, name, active
               FROM products
              ORDER BY active DESC, name ASC, id ASC'
        )->fetchAll( PDO::FETCH_ASSOC );

        $prices = $pdo->query(
            'SELECT product_id, stripe_price_id, type, `interval`, unit_amount_cents, currency, active
               FROM prices
              ORDER BY active DESC, unit_amount_cents ASC'
        )->fetchAll( PDO::FETCH_ASSOC );

        $byProduct = [];
        foreach ( $prices as $pr ) {
            $byProduct[ (int) $pr['product_id'] ][] = [
                'stripe_price_id'   => (string) $pr['stripe_price_id'],
                'type'              => (string) $pr['type'],
                'interval'          => $pr['interval'] !== null ? (string) $pr['interval'] : null,
                'unit_amount_cents' => (int) $pr['unit_amount_cents'],
                'currency'          => (string) $pr['currency'],
                'active'            => (bool) $pr['active'],
            ];
        }

        $out = [];
        foreach ( $products as $p ) {
            $id  = (int) $p['id'];
            $ref = $p['ref'] !== null ? (string) $p['ref'] : '';
            $out[] = [
                'id'                => $id,
                'stripe_product_id' => (string) $p['stripe_product_id'],
                'kind'              => (string) $p['kind'],
                'ref'               => $ref,
                'region_tag'        => $p['region_tag'] !== null ? (string) $p['region_tag'] : '',
                'name'              => (string) $p['name'],
                'active'            => (bool) $p['active'],
                'prices'            => $byProduct[ $id ] ?? [],
                /* THE RED PREDICATE, and it must be the same one Health counts
                   with — active, kind=membership, ref empty. An ARCHIVED
                   product with no tier is not a problem: nobody can reach its
                   prices. Gate 93 §C runs both surfaces over the same data and
                   fails if the two numbers ever differ. */
                'unmapped'          => (bool) $p['active'] && (string) $p['kind'] === self::KIND && $ref === '',
            ];
        }

        return $out;
    }

    /**
     * The number Health reports as "Products with NO tier ref".
     *
     * Deliberately computed from the SAME rows the screen draws, rather than
     * with its own COUNT query, so the number under the table cannot disagree
     * with the rows above it.
     */
    public static function unmappedActiveCount( ?array $products = null ): int
    {
        $products = $products ?? self::products();
        $n = 0;
        foreach ( $products as $p ) {
            if ( ! empty( $p['unmapped'] ) ) { $n++; }
        }
        return $n;
    }

    /** @return array<string,mixed>|null */
    public static function find( string $stripeProductId ): ?array
    {
        $st = self::pdo()->prepare(
            'SELECT id, stripe_product_id, kind, ref, region_tag, name, active
               FROM products WHERE stripe_product_id = ? LIMIT 1'
        );
        $st->execute( [ $stripeProductId ] );
        $row = $st->fetch( PDO::FETCH_ASSOC );
        return $row ?: null;
    }

    /* --------------------------------------------------------------------- */
    /* The write                                                             */
    /* --------------------------------------------------------------------- */

    /**
     * Set a product's tier and region. The only runtime writer of these
     * columns anywhere in the monorepo.
     *
     * The column set — `ref`, `kind`, `region_tag`, keyed on
     * `stripe_product_id` — is exactly what `lg-stripe-billing/bin/
     * stripe-import-catalog.php` prints for a human to run, so the dash and the
     * import cannot mean different things by "registering a product". Gate 93
     * §F asserts the two stay in step.
     *
     * @return array{changed:bool,name:string,from:array,to:array}
     */
    public static function apply( string $stripeProductId, string $tier, string $region, int $actorWpUser ): array
    {
        $stripeProductId = trim( $stripeProductId );
        if ( $stripeProductId === '' ) {
            throw new RuntimeException( 'No product was named, so nothing was changed.' );
        }

        /* Validate BEFORE touching the database. A refusal must leave the row
           untouched, not half-written — gate 93 §B5 asserts the whole row is
           byte-identical after every refusal. */
        $tier   = self::assertTier( $tier );
        $region = self::assertRegion( $region );

        $pdo = self::pdo();
        $row = self::find( $stripeProductId );

        if ( $row === null ) {
            throw new RuntimeException( sprintf(
                'No product with the Stripe id "%s" has been synced to this box, so there is nothing to map. '
                . 'Products arrive here from Stripe webhooks.',
                $stripeProductId
            ) );
        }

        $fromRef    = $row['ref'] !== null ? (string) $row['ref'] : '';
        $fromRegion = $row['region_tag'] !== null ? (string) $row['region_tag'] : '';
        $fromKind   = (string) $row['kind'];
        $toRegion   = $region ?? '';

        $from = [ 'ref' => $fromRef, 'region_tag' => $fromRegion, 'kind' => $fromKind ];
        $to   = [ 'ref' => $tier,    'region_tag' => $toRegion,   'kind' => self::KIND ];

        /* A no-op writes NOTHING — not the row, and not an audit line. The
           audit is "one line per change" (the issue's words); a line per SAVE
           BUTTON PRESS would bury the changes that matter under the times
           somebody looked at the screen and pressed save. */
        if ( $from === $to ) {
            return [ 'changed' => false, 'name' => (string) $row['name'], 'from' => $from, 'to' => $to ];
        }

        $inTx = false;
        try {
            $pdo->beginTransaction();
            $inTx = true;

            $st = $pdo->prepare(
                'UPDATE products SET ref = ?, kind = ?, region_tag = ? WHERE stripe_product_id = ?'
            );
            $st->execute( [
                $tier === '' ? null : $tier,
                self::KIND,
                $region,
                $stripeProductId,
            ] );

            /* One transaction with the UPDATE on purpose — see the class
               docblock. If this cannot be written the mapping does not happen. */
            $au = $pdo->prepare(
                'INSERT INTO audit_log (actor_type, actor_ref, subject_type, subject_id, action, details)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $au->execute( [
                'admin',
                $actorWpUser > 0 ? (string) $actorWpUser : null,
                self::AUDIT_SUBJECT,
                (int) $row['id'],
                self::AUDIT_ACTION,
                json_encode( [
                    'stripe_product_id' => $stripeProductId,
                    'name'              => (string) $row['name'],
                    'from'              => $from,
                    'to'                => $to,
                ], JSON_UNESCAPED_SLASHES ),
            ] );

            $pdo->commit();
        } catch ( Throwable $e ) {
            if ( $inTx && $pdo->inTransaction() ) {
                $pdo->rollBack();
            }
            throw new RuntimeException(
                'The change was NOT saved: ' . $e->getMessage()
                . ' (nothing was written, including the audit line).'
            );
        }

        return [ 'changed' => true, 'name' => (string) $row['name'], 'from' => $from, 'to' => $to ];
    }

    /** Human words for a stored ref. */
    public static function tierLabel( string $ref ): string
    {
        if ( $ref === '' ) { return 'no tier'; }
        return self::SELLABLE[ $ref ] ?? $ref;
    }

    /** Human words for a stored region tag. */
    public static function regionLabel( string $tag ): string
    {
        return self::REGIONS[ $tag ] ?? $tag;
    }
}
