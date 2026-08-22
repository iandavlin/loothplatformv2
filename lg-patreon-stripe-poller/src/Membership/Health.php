<?php

declare(strict_types=1);

namespace LGMS\Membership;

use LGMS\CohortAllowlist;
use LGMS\Db;
use LGMS\StripePrice;
use Throwable;

/**
 * THE FIVE QUESTIONS NOBODY COULD ANSWER AT A GLANCE. Issue #192, split out of
 * #190, which landed the dash and explicitly did not reach this.
 *
 * On 2026-08-21 three failures cost roughly an hour each, and NOT ONE OF THEM
 * ANNOUNCED ITSELF:
 *
 *   1. the billing app pointed at `dev.loothgroup.com`, A HOST THAT DOES NOT
 *      EXIST, so #150's double-pay probe answered UNKNOWN on every call and the
 *      post-checkout sync ping was dead — the five-minute sweep quietly covered;
 *   2. `lgms_shared_secret` was ABSENT in WordPress while the app had one, so
 *      server-to-server auth failed closed. IT IS STILL ABSENT ON LIVE TODAY;
 *   3. BuddyBoss's `bb-enable-private-rest-apis` pre-empts every
 *      `lg-member-sync/v1` route with 401 before any permission callback runs.
 *
 * Each is a SILENCE. Nothing went red, nothing logged an error, and the system
 * kept serving pages. This class exists so that silence has a screen.
 *
 * ---------------------------------------------------------------------------
 * THREE DESIGN DECISIONS THAT LOOK LIKE DETAIL AND ARE NOT
 * ---------------------------------------------------------------------------
 *
 * ⚠️ (1) IT READS THE BILLING APP'S `.env` OFF DISK, NOT OVER HTTP.
 * Asking the app about itself is the obvious design and it is wrong here: the
 * one moment this panel matters is when a channel is broken, and an
 * HTTP-shaped answer goes UNKNOWN in exactly that moment. A file read has no
 * such coupling. It also sidesteps a chicken-and-egg — the natural way to
 * authenticate such a call is the shared secret, which is one of the things
 * under test.
 *
 * ⚠️ (2) A SECRET NEVER LEAVES THIS CLASS AS A VALUE, STRUCTURALLY.
 * `envFacts()` reduces every secret-shaped key to present / length / sha256 at
 * the moment it is read, and the sha is used ONLY inside `hash_equals`. The
 * arrays this class hands the panel carry no secret, no fingerprint and no
 * prefix — so "never print a secret value" (Ian's rule) is a property of the
 * data, not a discipline the renderer has to remember. Even the Stripe key
 * prefix is collapsed to `test` / `live` / `unknown` before it crosses the
 * boundary.
 *
 * ⚠️ (3) `unknown` IS A FIRST-CLASS ANSWER AND OUTRANKS A REASSURING BLANK.
 * Keeper, 2026-08-21: "If any question cannot be answered honestly from
 * WordPress alone, say so in the panel rather than approximating — a number
 * nobody can trust is worse than a sentence saying we cannot see it from
 * here." Every reader below returns `unknown` with a REASON rather than a
 * default, and the four states of the env file (missing / unreadable / key
 * absent / present) are never conflated into one.
 *
 * ---------------------------------------------------------------------------
 * ⚠️ LIVE AND DEV2 ARE NOT THE SAME SHAPE. MEASURED, NOT ASSUMED (8/21)
 * ---------------------------------------------------------------------------
 * On dev2, `/srv/lg-stripe-billing` is a SYMLINK into the serving checkout and
 * its `.env` is `-rw-rw-r--` (world-readable), so the `looth-dev` FPM pool
 * reads it outright. On LIVE it is a REAL DIRECTORY owned by `www-data` with
 * `.env` at mode 0640; live's `looth-dev` is in group `www-data`, so it should
 * read — but "should" is precisely the word this panel exists to remove. When
 * it cannot read the file it says UNREADABLE and names the path, which is a
 * different sentence from "the key is not set" and needs a different fix.
 *
 * @see \LGMS\HealthPanel  the renderer; this class prints nothing
 */
final class Health
{
    /**
     * The billing app's environment file.
     *
     * `/srv/lg-stripe-billing` is the deploy path on BOTH boxes (a symlink into
     * the serving checkout on dev2, a real directory on live), which is why the
     * constant is that and not a repo-relative path — a repo-relative path
     * would resolve through the symlink into the checkout and read a file that
     * is not the one the app actually boots with.
     */
    public const APP_ENV_PATH = '/srv/lg-stripe-billing/.env';

    /** The app's own liveness ping — unauthenticated, and it already exists. */
    public const APP_HEALTH_PATH = '/billing/health';

    /**
     * The receipt recorder, on disk. Its MTIME is when webhook recording
     * arrived on THIS box — see whenRecordingStarted().
     */
    public const RECORDER_PATH = '/srv/lg-stripe-billing/src/Core/WebhookReceipts.php';

    /**
     * Keys whose VALUE must never leave this class. Reduced to
     * present/length/sha256 the moment the file is parsed.
     */
    private const SECRET_KEYS = [
        'STRIPE_SECRET_KEY',
        'STRIPE_PUBLISHABLE_KEY',
        'STRIPE_WEBHOOK_SECRET',
        'LGMS_SHARED_SECRET',
        'DB_PASSWORD',
    ];

    /** Keys that are safe to carry as-is: URLs, modes, environment labels. */
    private const PLAIN_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'STRIPE_MODE',
        'LGMS_SYNC_URL',
        'APP_BASE_URL',
        'APP_HOME_URL',
    ];

    /** audit_log actions the billing app writes when a webhook lands. */
    public const ACT_RECEIVED = 'webhook_received';
    public const ACT_SIG_FAIL = 'webhook_signature_failed';

    /** A webhook older than this is reported with its age spelled out. */
    private const STALE_AFTER = 86400 * 7;

    /** Memoised so one page load parses the file once. */
    private static ?array $envCache = null;

    /** Test seam for the loopback probe's transport. See post(). */
    public static ?\Closure $transport = null;

    // =========================================================================
    // The public surface
    // =========================================================================

    /**
     * Every check, in the order the charter asks them.
     *
     * @return array{checks:list<array>,app_env:array,shared_secret:array}
     */
    public static function describe(): array
    {
        $env = self::envFacts();

        return [
            'app_env' => [
                'state'  => $env['state'],
                'path'   => $env['path'],
                'reason' => $env['reason'],
            ],
            /* #201: the shared secret is its own section, ABOVE the checks —
               but it is carried here, not fetched separately by the renderer,
               for two reasons. One call means one `checked_at` stamp for the
               whole screen instead of two that drift apart by a millisecond and
               invite the reader to wonder which is current. And it keeps the
               section inside `worst()`'s reach: a screen whose headline says
               everything is healthy while its top section says DIFFER is the
               "a blank cell reads like health" failure wearing a different
               hat. */
            'shared_secret' => self::sharedSecret(),
            'checks'  => [
                self::checkWebhooks(),
                self::checkSecrets(),
                self::checkMode(),
                self::checkCatalogue(),
                self::checkAudience(),
                self::checkChannel(),
            ],
        ];
    }

    /** Test seam: forget the parsed env so a new path takes effect. */
    public static function reset(): void
    {
        self::$envCache = null;
    }

    /**
     * The worst status present, for the tab's own headline.
     * `fail` outranks `unknown` outranks `warn` outranks `ok` — deliberately:
     * a thing we cannot see is more urgent than a thing we can see is untidy.
     */
    public static function worst( array $checks ): string
    {
        /* Anything carrying a `status` counts, which is how #201's shared-secret
           section is folded in beside the six checks — see describe(). */
        $rank = [ 'ok' => 0, 'warn' => 1, 'unknown' => 2, 'fail' => 3 ];
        $out  = 'ok';
        foreach ( $checks as $c ) {
            if ( ( $rank[ $c['status'] ] ?? 0 ) > ( $rank[ $out ] ?? 0 ) ) {
                $out = $c['status'];
            }
        }
        return $out;
    }

    // =========================================================================
    // 1. Are webhooks arriving?
    // =========================================================================

    /**
     * ⚠️ "NEVER" AND "LONG AGO" ARE DIFFERENT ANSWERS AND A BLANK CELL IS
     * NEITHER (keeper's requirement, 2026-08-21: "Silence is the failure mode,
     * and a blank cell reads like health.").
     *
     * ⚠️ AND "NEVER" IS NOT AUTOMATICALLY A DEFECT — that judgement needs a
     * second fact. On a box that has never sold anything, no webhook having
     * arrived is exactly right, and a panel that screams about it teaches its
     * reader to ignore it. So this pairs the receipt count with the number of
     * customers and subscriptions that exist:
     *
     *   never + nothing sold  ⇒ warn, and it says why it is expected
     *   never + money moved   ⇒ FAIL. A subscription exists that no webhook
     *                           accounted for. That is failure #1's shape.
     *   arrived, recently     ⇒ ok, with the age
     *   arrived, long ago     ⇒ warn, WITH THE AGE SPELLED OUT
     *   cannot read the table ⇒ unknown, with the reason
     *
     * ⚠️ "MONEY MOVED" MEANS SINCE RECORDING STARTED, NOT EVER, AND THE FIRST
     * REAL RUN IS WHAT TAUGHT US THAT. Pointed at dev2 this check said "a
     * payment completed with no webhook recorded" against 109 customers and
     * subscriptions — every one of them from before the recorder existed. A
     * panel that cries wolf on its own deployment day teaches its reader to
     * ignore it, which is the one thing this screen cannot afford.
     *
     * The reference point is a fact that can be read rather than assumed: the
     * MTIME of the recorder file on this box, which is when a `git pull` put it
     * there. Sales after that with no receipt are unambiguous. Sales before it
     * are history, and are counted separately and labelled as such. When the
     * recorder cannot be found at all the check says so and declines to score
     * the difference, rather than picking whichever answer looks tidier.
     *
     * Signature failures are surfaced beside the successes on purpose: a
     * rising failure count next to a silent success count IS the mismatched
     * webhook secret showing itself from the outside, which is the only place
     * that particular disagreement is ever visible.
     */
    private static function checkWebhooks(): array
    {
        $lines = [];

        try {
            $pdo = Db::pdo();

            $rows = $pdo->query(
                "SELECT action, COUNT(*) AS n, MAX(created_at) AS last
                   FROM audit_log
                  WHERE subject_type = 'webhook'
                    AND action IN ('" . self::ACT_RECEIVED . "','" . self::ACT_SIG_FAIL . "')
                  GROUP BY action"
            )->fetchAll( \PDO::FETCH_ASSOC );

            $by = [];
            foreach ( $rows as $r ) {
                $by[ (string) $r['action'] ] = [ 'n' => (int) $r['n'], 'last' => (string) $r['last'] ];
            }

            $okN    = $by[ self::ACT_RECEIVED ]['n']    ?? 0;
            $okLast = $by[ self::ACT_RECEIVED ]['last'] ?? '';
            $badN   = $by[ self::ACT_SIG_FAIL ]['n']    ?? 0;
            $badLast= $by[ self::ACT_SIG_FAIL ]['last'] ?? '';

            /* Has money moved on this box, and — the part that matters — has
               any of it moved SINCE recording started? See the docblock. */
            $soldEver = (int) $pdo->query(
                'SELECT (SELECT COUNT(*) FROM subscriptions) + (SELECT COUNT(*) FROM customers)'
            )->fetchColumn();

            $since = self::whenRecordingStarted();
            if ( $since === null ) {
                $soldSince = null;
            } else {
                $st = $pdo->prepare(
                    'SELECT (SELECT COUNT(*) FROM subscriptions WHERE created_at >= :a)
                          + (SELECT COUNT(*) FROM customers     WHERE created_at >= :b)'
                );
                $st->execute( [ ':a' => $since, ':b' => $since ] );
                $soldSince = (int) $st->fetchColumn();
            }

        } catch ( Throwable $e ) {
            return self::check(
                'webhooks',
                'Are webhooks arriving?',
                'unknown',
                'Cannot see the billing database from here.',
                [ self::line( 'Reason', self::short( $e->getMessage() ), 'unknown' ) ],
                'The receipts live in `audit_log` in the `lg_membership` database. WordPress '
                . 'reads it with the credentials on the Settings tab; if those are wrong or the '
                . 'database is unreachable, this question genuinely cannot be answered from here.'
            );
        }

        if ( $okN === 0 ) {
            if ( $soldSince === null ) {
                /* We cannot find the recorder, so we cannot say when recording
                   started — and therefore cannot tell a real miss from history.
                   Said plainly rather than guessed. */
                $status  = 'unknown';
                $summary = 'NEVER — and this box cannot tell when webhook recording started, '
                         . 'so it cannot say whether that is a problem.';
            } elseif ( $soldSince > 0 ) {
                $status  = 'fail';
                $summary = 'NEVER — and ' . $soldSince . ' sale(s) landed SINCE recording started, '
                         . 'so a payment completed with no webhook recorded.';
            } elseif ( $soldEver > 0 ) {
                $status  = 'warn';
                $summary = 'NEVER — but every one of the ' . $soldEver . ' sale(s) on this box predates '
                         . 'webhook recording, so that is expected.';
            } else {
                $status  = 'warn';
                $summary = 'NEVER — but nothing has ever been sold on this box, so that is expected.';
            }
            $lines[] = self::line( 'Last verified webhook', 'Never', $status === 'fail' ? 'fail' : 'warn' );
        } else {
            $age     = self::ageOf( $okLast );
            $stale   = $age === null || $age > self::STALE_AFTER;
            $status  = $stale ? 'warn' : 'ok';
            $summary = $stale
                ? 'The last webhook arrived ' . self::ago( $okLast ) . ' — nothing since.'
                : 'Yes — the last one arrived ' . self::ago( $okLast ) . '.';
            $lines[] = self::line( 'Last verified webhook', self::ago( $okLast ) . ' (' . $okLast . ' UTC)', $stale ? 'warn' : 'ok' );
        }

        $lines[] = self::line( 'Verified webhooks recorded', (string) $okN, $okN > 0 ? 'ok' : 'warn' );
        $lines[] = self::line(
            'Rejected for a bad signature',
            $badN === 0 ? 'none' : $badN . ' (last ' . self::ago( $badLast ) . ')',
            $badN === 0 ? 'ok' : 'fail'
        );
        $lines[] = self::line( 'Customers + subscriptions on this box', (string) $soldEver, 'neutral' );
        $lines[] = self::line(
            'Recording started',
            $since === null ? 'UNKNOWN — the recorder was not found on this box' : $since . ' UTC',
            $since === null ? 'unknown' : 'neutral'
        );
        if ( $soldSince !== null ) {
            $lines[] = self::line( 'Sales since recording started', (string) $soldSince, $soldSince > 0 && $okN === 0 ? 'fail' : 'neutral' );
        }

        /* A signature failure outranks everything else this check can say: it
           means Stripe IS reaching us and we are throwing the event away. */
        if ( $badN > 0 ) {
            $status  = 'fail';
            $summary = 'Stripe is reaching us and we are REJECTING it — ' . $badN
                     . ' event(s) failed signature verification. The webhook secret does not match.';
        }

        return self::check(
            'webhooks',
            'Are webhooks arriving?',
            $status,
            $summary,
            $lines,
            'A count of zero on a box that has taken payments SINCE recording started is a real '
            . 'finding; on a box whose sales all predate it, it is not — so the two are counted '
            . 'separately rather than added together. "Recording started" is the recorder file\'s '
            . 'own timestamp on this box, which is a fact that can be read rather than assumed. '
            . 'Signature failures are rate-limited to one record per five minutes, because that '
            . 'endpoint is unauthenticated and anyone can post rubbish at it.'
        );
    }

    // =========================================================================
    // 2. Do the two halves agree?
    // =========================================================================

    /**
     * ONE PAIR, COMPARED — the one definition of "do these two halves agree",
     * used by the Stripe-webhook check below AND by sharedSecret() above it,
     * which is what SharedSecretPanel renders. #201 extracted it rather than
     * writing a second comparison: two definitions of "agree" is two answers to
     * one question, and this dash exists because two halves disagreed.
     *
     * ⚠️ NOTHING DERIVED FROM EITHER VALUE IS IN THE RETURN. The comparison is
     * `hash_equals` over sha256 and it happens INSIDE this method; what comes
     * out is present/absent, a length, and a verdict word. That is what makes
     * "never print a secret" a property of the data rather than a rule every
     * renderer has to remember — see the class docblock, decision (2).
     *
     * @return array{
     *   label:string, opt:string, key:string, env_state:string,
     *   wp:array{present:bool,len:int}, app:array{present:bool,len:int,visible:bool},
     *   verdict:string, status:string, line:string, issue:string
     * }
     */
    public static function secretPair( string $label, string $opt, string $key ): array
    {
        $env     = self::envFacts();
        $wp      = (string) get_option( $opt, '' );
        $wpHas   = $wp !== '';
        $appFact = $env['secrets'][ $key ] ?? null;

        $out = [
            'label'     => $label,
            'opt'       => $opt,
            'key'       => $key,
            'env_state' => $env['state'],
            'wp'        => [ 'present' => $wpHas, 'len' => strlen( $wp ) ],
            'app'       => [ 'present' => false, 'len' => 0, 'visible' => false ],
            'verdict'   => 'cannot_compare',
            'status'    => 'unknown',
            'line'      => '',
            'issue'     => '',
        ];

        /* THE FOUR ENV STATES COLLAPSE TO ONE ANSWER HERE AND ONLY HERE: we
           cannot see the other half. WHICH of the four it is stays available to
           the caller in env_state, because "there is no file" and "this user
           may not read the file" need opposite fixes. */
        if ( $env['state'] !== 'ok' ) {
            $out['line'] = ( $wpHas ? 'WordPress: set (' . strlen( $wp ) . ' characters)' : 'WordPress: NOT SET' )
                . ' · billing app: cannot see it';
            return $out;
        }

        $appHas          = ( $appFact !== null && $appFact['present'] );
        $out['app']      = [
            'present' => $appHas,
            'len'     => (int) ( $appFact['len'] ?? 0 ),
            'visible' => true,
        ];

        if ( ! $wpHas && ! $appHas ) {
            $out['verdict'] = 'both_missing';
            $out['status']  = 'fail';
            $out['line']    = 'NOT SET on either side';
            $out['issue']   = $label . ' is missing everywhere';
            return $out;
        }
        if ( ! $wpHas ) {
            $out['verdict'] = 'wp_missing';
            $out['status']  = 'fail';
            $out['line']    = 'billing app: set (' . $out['app']['len'] . ' characters) · WordPress: NOT SET';
            $out['issue']   = $label . ' is set in the billing app but absent in WordPress';
            return $out;
        }
        if ( ! $appHas ) {
            $out['verdict'] = 'app_missing';
            $out['status']  = 'fail';
            $out['line']    = 'WordPress: set (' . strlen( $wp ) . ' characters) · billing app: NOT SET';
            $out['issue']   = $label . ' is set in WordPress but absent in the billing app';
            return $out;
        }

        $agree          = hash_equals( (string) $appFact['sha'], hash( 'sha256', $wp ) );
        $out['verdict'] = $agree ? 'match' : 'differ';
        $out['status']  = $agree ? 'ok' : 'fail';
        $out['line']    = $agree
            ? 'AGREE — both set, ' . strlen( $wp ) . ' characters'
            : 'DISAGREE — WordPress ' . strlen( $wp ) . ' characters, billing app ' . $out['app']['len'] . ' characters';
        if ( ! $agree ) {
            $out['issue'] = $label . ' differs between the two halves';
        }

        return $out;
    }

    /**
     * THE SHARED SECRET, ON ITS OWN, WITH A TIMESTAMP. Issue #201.
     *
     * Ian, 2026-08-22: *"Should just be a refresh button or something with a
     * status check."* This is the status check; SharedSecretPanel is the screen
     * and the button.
     *
     * ⚠️ IT IS REPORTED HERE AND NOWHERE ELSE ON THAT TAB. checkSecrets() used
     * to carry this pair as well, and leaving it there would have put one fact
     * on one screen twice in two different presentations — the same shape that
     * showed a member two stacked identical gate panels on #199. So this pair
     * came OUT of that check and the card below now names the webhook secret it
     * still holds.
     *
     * ⚠️ WHY IT DESERVES ITS OWN SECTION AT ALL: when this channel is down,
     * every other answer on the Health tab is meaningless. It is what
     * authenticates the billing app's calls into WordPress, and it is ABSENT ON
     * LIVE today — which is why #181's checkout guard, fail-open by design,
     * answers UNKNOWN and waves every purchase through.
     *
     * The per-half facts are spelled out in EVERY branch, healthy included.
     * `AGREE — both set, 64 characters` is a true sentence that answers a
     * different question from "is each half set, and how long is it", and a
     * panel that only itemises once something is broken cannot be used to check
     * that a rotation landed.
     *
     * @return array{
     *   checked_at:string, status:string, verdict:string, headline:string,
     *   summary:string, lines:list<array{label:string,value:string,status:string}>,
     *   env:array{state:string,path:string,reason:string}, pair:array
     * }
     */
    public static function sharedSecret(): array
    {
        $pair = self::secretPair(
            'Shared secret',
            'lgms_shared_secret',
            'LGMS_SHARED_SECRET'
        );
        $env = self::envFacts();

        $wpLine = $pair['wp']['present']
            ? 'set — ' . $pair['wp']['len'] . ' characters'
            : 'NOT SET';

        /* "Cannot read it" and "it is not set" are opposite findings and are
           never conflated: one is a permissions job for root, the other is a
           value nobody has entered. Naming the state is what sends the reader
           to the right file. */
        $appLine = match ( true ) {
            ! $pair['app']['visible'] => match ( $env['state'] ) {
                'missing'    => 'cannot see it — there is no settings file at that path',
                'unreadable' => 'cannot see it — the file is there and WordPress may not read it',
                'empty'      => 'cannot see it — the settings file parsed to nothing at all',
                default      => 'cannot see it — the settings file could not be used',
            },
            $pair['app']['present']   => 'set — ' . $pair['app']['len'] . ' characters',
            default                   => 'NOT SET',
        };

        [ $headline, $summary ] = match ( $pair['verdict'] ) {
            'match'         => [ 'MATCH', 'Both halves hold the same value. Server-to-server calls can authenticate.' ],
            'differ'        => [ 'DIFFER', 'The two halves hold DIFFERENT values, so every server-to-server call fails closed. A rotation that landed on one side only looks exactly like this.' ],
            'wp_missing'    => [ 'NOT SET in WordPress', 'The billing app holds one and WordPress does not. This is the state live is in, and it is why the checkout guard answers UNKNOWN instead of refusing anybody.' ],
            'app_missing'   => [ 'NOT SET in the billing app', 'WordPress holds one and the billing app does not, so the app cannot sign the calls it makes back.' ],
            'both_missing'  => [ 'NOT SET anywhere', 'Neither half is set. Nothing server-to-server can authenticate at all.' ],
            default         => [ 'CANNOT COMPARE', 'WordPress\'s half can be read; the billing app\'s cannot, so the two cannot be compared from here. That is reported rather than guessed — a number nobody can trust is worse than a sentence saying we cannot see it.' ],
        };

        return [
            /* THE STAMP IS THE POINT OF THE REFRESH. Without it a re-rendered
               section is indistinguishable from a stale one, and the button
               becomes decoration. UTC because both boxes disagree with it
               (America/New_York) and a health screen read across two boxes at
               3am needs one clock. */
            'checked_at' => gmdate( 'H:i:s' ) . ' UTC',
            'status'     => $pair['status'],
            'verdict'    => $pair['verdict'],
            'headline'   => $headline,
            'summary'    => $summary,
            'lines'      => [
                self::line( 'WordPress', $wpLine, $pair['wp']['present'] ? 'neutral' : 'fail' ),
                self::line( 'Billing app', $appLine, $pair['app']['visible'] ? ( $pair['app']['present'] ? 'neutral' : 'fail' ) : 'unknown' ),
                self::line( 'Do they match?', $headline, $pair['status'] ),
            ],
            'env'        => [
                'state'  => $env['state'],
                'path'   => $env['path'],
                'reason' => $env['reason'],
            ],
            'pair'       => $pair,
        ];
    }

    /**
     * ONE VALUE IN TWO HOMES WITH NOTHING COMPARING THEM is how a rotation
     * breaks verification silently — the charter's words, and failure #2's
     * exact shape.
     *
     * ⚠️ THE SHARED SECRET IS DELIBERATELY NOT HERE ANY MORE (#201). It has its
     * own section at the top of this tab, with a refresh button; reporting it
     * in both places would be the same fact twice on one screen. Gate 91 §B
     * asserts its ABSENCE from this card, so re-adding it is a red rather than
     * a silent duplicate.
     *
     * The comparison is `hash_equals` over sha256, in secretPair(). Nothing
     * derived from either value reaches the returned array: only present/absent,
     * length and a verdict.
     */
    private static function checkSecrets(): array
    {
        $pairs = [
            [ 'Stripe webhook secret', 'lgms_stripe_webhook_secret', 'STRIPE_WEBHOOK_SECRET' ],
        ];

        $lines  = [];
        $worst  = 'ok';
        $issues = [];

        foreach ( $pairs as [ $label, $opt, $key ] ) {
            $p       = self::secretPair( $label, $opt, $key );
            $lines[] = self::line( $label, $p['line'], $p['status'] );
            $worst   = self::worseOf( $worst, $p['status'] );
            if ( $p['issue'] !== '' ) {
                $issues[] = $p['issue'];
            }
        }

        $summary = match ( true ) {
            $issues !== []       => ucfirst( implode( '; ', $issues ) ) . '.',
            $worst === 'unknown' => 'Cannot compare — the billing app\'s settings file could not be read.',
            default              => 'Both halves hold the same value.',
        };

        return self::check(
            'secrets',
            'Does the webhook secret agree?',
            $worst,
            $summary,
            $lines,
            /* ⚠️ THE OLD NOTE HERE POINTED AT A CONTROL THAT DOES NOT EXIST.
               It said to set the WordPress side on the Settings tab;
               `lgms_stripe_webhook_secret` is not in registerSettings() and has
               no field there, measured on main 2026-08-22. Half a sentence
               pointing at a missing control is how an operator concludes the
               dash is broken. */
            'Values are never shown. The comparison is a sha256 comparison done in code; this '
            . 'screen only ever reports present, absent, length and agree-or-disagree. '
            . '**Both halves of this pair are set on the command line** — `wp option update '
            . 'lgms_stripe_webhook_secret` for the WordPress side, and the `STRIPE_WEBHOOK_SECRET` '
            . 'line of the billing app\'s settings file for the other. There is no field for '
            . 'either, on this tab or any other. The **shared secret** has its own section at '
            . 'the top of this tab.'
        );
    }

    // =========================================================================
    // 3. Test or live mode?
    // =========================================================================

    /**
     * TWO KEYS IN THE SAME MODE IS NOT A DEFECT — the poller and the billing
     * app may legitimately hold different keys on one Stripe account (one of
     * them restricted, for instance). What matters, and what this reports as a
     * finding, is the two halves being in DIFFERENT modes, or a live box still
     * carrying test keys on the night it takes real money.
     */
    private static function checkMode(): array
    {
        $env    = self::envFacts();
        $lines  = [];

        $wpMode = self::keyMode( (string) get_option( 'lgms_stripe_secret_key', '' ) );
        $lines[] = self::line( 'WordPress Stripe key', self::modeWords( $wpMode ), $wpMode === 'unknown' ? 'warn' : 'neutral' );

        if ( $env['state'] !== 'ok' ) {
            return self::check(
                'mode', 'Test or live mode?', 'unknown',
                'Cannot see which key the billing app holds.',
                array_merge( $lines, [ self::line( 'Billing app', 'cannot read its settings file', 'unknown' ) ] ),
                'The billing app is the half that actually charges the card, so its mode is the '
                . 'one that decides whether a purchase is real.'
            );
        }

        $appKeyMode = $env['secrets']['STRIPE_SECRET_KEY']['mode'] ?? 'unknown';
        $declared   = strtolower( trim( (string) ( $env['plain']['STRIPE_MODE'] ?? '' ) ) );

        $lines[] = self::line( 'Billing app Stripe key', self::modeWords( $appKeyMode ), $appKeyMode === 'unknown' ? 'warn' : 'neutral' );
        $lines[] = self::line( 'Billing app STRIPE_MODE setting', $declared === '' ? 'not set' : $declared, 'neutral' );

        /* APP_ENV is reported because it is what /billing/health prints and it
           reads alarmingly on live — but it is CHECKED, not guessed: nothing in
           the app branches on it (only APP_DEBUG does), so it is a label. Said
           plainly here so nobody spends an hour on it, and so nobody dismisses
           APP_DEBUG, which is not a label. */
        $appEnvLabel = (string) ( $env['plain']['APP_ENV'] ?? '' );
        $debug       = strtolower( trim( (string) ( $env['plain']['APP_DEBUG'] ?? '' ) ) );
        $debugOn     = in_array( $debug, [ 'true', '1', 'yes', 'on' ], true );

        $lines[] = self::line( 'Billing app APP_ENV label', $appEnvLabel === '' ? 'not set' : $appEnvLabel, 'neutral' );
        $lines[] = self::line( 'Billing app APP_DEBUG', $debugOn ? 'ON — errors are displayed' : 'off', $debugOn ? 'fail' : 'ok' );

        $status  = 'ok';
        $issues  = [];

        if ( $appKeyMode === 'unknown' ) {
            $status  = 'warn';
            $issues[] = 'the billing app\'s Stripe key is not recognisably test or live';
        }
        if ( $declared !== '' && $appKeyMode !== 'unknown' && $declared !== $appKeyMode ) {
            $status   = 'fail';
            $issues[] = 'the billing app says STRIPE_MODE=' . $declared . ' but holds a ' . $appKeyMode . ' key';
        }
        if ( $wpMode !== 'unknown' && $appKeyMode !== 'unknown' && $wpMode !== $appKeyMode ) {
            $status   = 'fail';
            $issues[] = 'WordPress holds a ' . $wpMode . ' key while the billing app holds a ' . $appKeyMode . ' key';
        }
        if ( $debugOn ) {
            $status   = 'fail';
            $issues[] = 'APP_DEBUG is on, which displays errors to visitors';
        }

        $summary = $issues !== []
            ? ucfirst( implode( '; ', $issues ) ) . '.'
            : 'Both halves are in ' . self::modeWords( $appKeyMode ) . '.';

        return self::check( 'mode', 'Test or live mode?', $status, $summary, $lines,
            'Two different keys in the SAME mode is normal and is not reported as a problem — '
            . 'one of them may be a restricted key. Different modes is a real defect, and so is '
            . 'a live box still holding test keys.' );
    }

    // =========================================================================
    // 4. Does the catalogue resolve to tiers?
    // =========================================================================

    /**
     * A Stripe price whose product carries no `ref` on our side GRANTS NOTHING,
     * and checkout refuses it with "not mapped to a membership tier" — the
     * third of the three accidents that were doing live's refusing (#180), and
     * the one that is REMOVED ON PURPOSE at go-live. So an empty catalogue is
     * reported as a fact about a box that is not selling yet, while a catalogue
     * with unmapped products is reported as a defect: those are prices a member
     * can reach and cannot buy.
     */
    private static function checkCatalogue(): array
    {
        try {
            $pdo = Db::pdo();
            $row = $pdo->query(
                "SELECT
                    SUM(active = 1 AND kind = 'membership')                                          AS memb,
                    SUM(active = 1 AND kind = 'membership' AND (ref IS NULL OR ref = ''))            AS unmapped,
                    SUM(active = 1 AND kind = 'membership' AND region_tag IS NOT NULL)               AS regional
                   FROM products"
            )->fetch( \PDO::FETCH_ASSOC );
            $prices = (int) $pdo->query( 'SELECT COUNT(*) FROM prices WHERE active = 1' )->fetchColumn();
        } catch ( Throwable $e ) {
            return self::check( 'catalogue', 'Does the catalogue resolve to tiers?', 'unknown',
                'Cannot see the billing database from here.',
                [ self::line( 'Reason', self::short( $e->getMessage() ), 'unknown' ) ],
                'Without the catalogue there is no way to tell whether a price a buyer clicks '
                . 'will grant them anything.' );
        }

        $memb     = (int) ( $row['memb'] ?? 0 );
        $unmapped = (int) ( $row['unmapped'] ?? 0 );
        $regional = (int) ( $row['regional'] ?? 0 );

        $tiers      = StripePrice::tiers();
        $configured = StripePrice::configuredTiers();

        $lines = [
            self::line( 'Active membership products', (string) $memb, $memb === 0 ? 'warn' : 'neutral' ),
            self::line( 'Active prices', (string) $prices, $prices === 0 ? 'warn' : 'neutral' ),
            self::line( 'Tiers the catalogue resolves to', $tiers === [] ? 'none' : implode( ', ', $tiers ), $tiers === [] ? 'warn' : 'ok' ),
            self::line( 'Tiers that actually have a price', $configured === [] ? 'none' : implode( ', ', $configured ), $configured === [] ? 'warn' : 'ok' ),
            self::line( 'Products with NO tier ref', (string) $unmapped, $unmapped > 0 ? 'fail' : 'ok' ),
            self::line( 'Regional products (excluded from tiers by design)', (string) $regional, 'neutral' ),
        ];

        $missingPrice = array_values( array_diff( $tiers, $configured ) );

        if ( $unmapped > 0 ) {
            $status  = 'fail';
            $summary = $unmapped . ' active membership product(s) carry no tier ref — a buyer who '
                     . 'reaches those prices is refused with "not mapped to a membership tier".';
        } elseif ( $memb === 0 ) {
            $status  = 'warn';
            $summary = 'The catalogue is EMPTY — nothing can be bought on this box yet.';
        } elseif ( $missingPrice !== [] ) {
            $status  = 'warn';
            $summary = 'Registered but not priced, so not offered: ' . implode( ', ', $missingPrice ) . '.';
        } else {
            $status  = 'ok';
            $summary = 'Every active membership product resolves to a tier and has a price.';
        }

        return self::check( 'catalogue', 'Does the catalogue resolve to tiers?', $status, $summary, $lines,
            'An empty catalogue is exactly what live looks like before go-live, and it is '
            . 'currently one of the things stopping a stranger buying. That prop is removed on '
            . 'purpose when the catalogue is registered — after which the fences have to be real.' );
    }

    // =========================================================================
    // 5. Audience and cohort
    // =========================================================================

    /**
     * WHO MAY BUY, plus the page-gate partners — because #165 and #170 both
     * recorded the same silent shape: a Join button wired perfectly that lands
     * on "This page isn't available yet". The two switches are free to
     * disagree and nothing else in the admin says so.
     */
    private static function checkAudience(): array
    {
        $state  = CheckoutAudience::state();
        $cohort = CohortAllowlist::ids();
        /* #193 — BOTH KINDS OF ENTRY COUNT. A list holding nothing but tester
           ADDRESSES admits people, so counting only the ids here would report
           "the cohort is EMPTY, nobody at all can buy" about a cohort that is
           working — the panel crying wolf on itself, which is the exact failure
           #192 spent a rewrite removing. */
        $addrs  = CohortAllowlist::emails();
        $n      = CohortAllowlist::count();   // ONE definition of "how many are admitted"

        $pagesLive     = (string) get_option( 'lgms_stripe_pages_live', '' );
        $testerPages   = (string) get_option( 'lgms_stripe_testgroup_pages', '' );
        $pagesLiveOn   = self::truthy( $pagesLive );
        $testerPagesOn = self::truthy( $testerPages );

        $lines = [
            self::line( 'Checkout audience', $state . ( get_option( CheckoutAudience::OPT, null ) === null ? ' (option not set — this is the default)' : '' ),
                $state === 'on' ? 'warn' : 'ok' ),
            self::line( 'Tester cohort', $n === 0 ? 'EMPTY' : self::cohortWords( count( $cohort ), count( $addrs ) ), $n === 0 ? 'warn' : 'ok' ),
            self::line( 'Join page open to everyone (lgms_stripe_pages_live)', $pagesLiveOn ? 'yes' : 'no', 'neutral' ),
            self::line( 'Join page open to the test group (lgms_stripe_testgroup_pages)', $testerPagesOn ? 'yes' : 'no', 'neutral' ),
        ];

        $status  = 'ok';
        $issues  = [];

        /* THE PAIRINGS. Each is a state where one switch is on and the door it
           needs is shut — the "wired perfectly and lands nowhere" shape. */
        if ( $state === CheckoutAudience::ALLOWLIST && $n === 0 ) {
            $status   = 'warn';
            $issues[] = 'the audience is `allowlist` and the cohort is EMPTY, so nobody at all can buy';
        }
        if ( $state === CheckoutAudience::ALLOWLIST && $n > 0 && ! $testerPagesOn && ! $pagesLiveOn ) {
            $status   = 'warn';
            $issues[] = 'the cohort may buy, but the join page is not open to them — they will reach '
                      . '"This page isn\'t available yet"';
        }
        if ( $state === CheckoutAudience::ON && ! $pagesLiveOn ) {
            $status   = 'fail';
            $issues[] = 'the audience is `on` (everybody may buy) but the join page is still pre-launch';
        }
        if ( $state === CheckoutAudience::OFF ) {
            $status   = self::worseOf( $status, 'warn' );
            $issues[] = 'the audience is `off`, so nobody is asked about and nobody is fenced';
        }

        $summary = $issues !== []
            ? ucfirst( implode( '; ', $issues ) ) . '.'
            : 'Audience `' . $state . '` with ' . self::cohortWords( count( $cohort ), count( $addrs ) )
              . ' in the cohort, and the join page agrees.';

        return self::check( 'audience', 'Who may buy?', $status, $summary, $lines,
            'The audience decides who may CHECK OUT; the two page options decide who may SEE the '
            . 'join page. They are separate switches and are free to disagree, which is why they '
            . 'are shown together. Edit the cohort on the **Testers** tab. A cohort entry is either '
            . 'a MEMBER (an account here) or an ADDRESS (no account yet — the account is created by '
            . 'their join, #193).' );
    }

    /**
     * "6 member(s)" / "4 member(s) + 2 address(es)" — the two are different
     * situations and an operator reading this panel is usually trying to tell
     * them apart: an address still waiting on its tester looks exactly like a
     * member who has already joined if you only ever print one number.
     */
    private static function cohortWords( int $members, int $addresses ): string
    {
        $s = $members . ' member(s)';
        if ( $addresses > 0 ) {
            $s .= ' + ' . $addresses . ' address(es)';
        }
        return $s;
    }

    // =========================================================================
    // 6. The channel
    // =========================================================================

    /**
     * FAILURE #1 AND FAILURE #3, WHICH THE SECRET COMPARISON DOES NOT COVER.
     * Agreeing secrets over a dead channel is still a dead channel: the app
     * pointed at `dev.loothgroup.com` for an unknown length of time with a
     * perfectly good secret.
     *
     * ⚠️ THE PROBE MUST GO TO 127.0.0.1 WITH A HOST HEADER, NEVER TO THE PUBLIC
     * NAME. A plain request to the public host on live goes out to Cloudflare
     * and comes back a bot-challenge 403 that reads exactly like an outage —
     * a trap this repo has paid for more than once. Certificate verification is
     * off for the same reason and only for this loopback call.
     *
     * When the probe cannot run, the in-process facts below it still answer the
     * BuddyBoss question honestly, and the panel says which of the two it is
     * looking at rather than blending them.
     */
    private static function checkChannel(): array
    {
        $env   = self::envFacts();
        $lines = [];
        $status = 'ok';
        $issues = [];

        // --- the app's idea of where WordPress lives -------------------------
        $ourHost = (string) wp_parse_url( home_url(), PHP_URL_HOST );

        if ( $env['state'] !== 'ok' ) {
            $lines[] = self::line( 'Billing app → WordPress URL', 'cannot read its settings file', 'unknown' );
            $status  = 'unknown';
            $issues[] = 'the billing app\'s sync URL cannot be read from here';
        } else {
            $sync     = trim( (string) ( $env['plain']['LGMS_SYNC_URL'] ?? '' ) );
            $syncHost = $sync === '' ? '' : (string) wp_parse_url( $sync, PHP_URL_HOST );

            if ( $sync === '' ) {
                $lines[]  = self::line( 'Billing app → WordPress URL', 'NOT SET', 'fail' );
                $status   = 'fail';
                $issues[] = 'the billing app has no sync URL, so it cannot call WordPress at all';
            } elseif ( $syncHost !== '' && strcasecmp( $syncHost, $ourHost ) !== 0 ) {
                /* THE EXACT BUG. Corroborated with a DNS lookup, because
                   `dev.loothgroup.com` did not merely differ — it did not
                   exist, and saying so is the difference between "check the
                   value" and "that host is gone". */
                $resolves = self::hostResolves( $syncHost );
                $lines[]  = self::line(
                    'Billing app → WordPress URL',
                    $syncHost . ' — but this site is ' . $ourHost
                    . ( $resolves ? '' : ' (and that host does not resolve at all)' ),
                    'fail'
                );
                $status   = 'fail';
                $issues[] = 'the billing app is calling ' . $syncHost . ', which is not this site';
            } else {
                $lines[] = self::line( 'Billing app → WordPress URL', $syncHost . ' — matches this site', 'ok' );
            }
        }

        // --- BuddyBoss, from in-process facts (always available) ------------
        $bbPrivate = self::truthy( (string) get_option( 'bb-enable-private-rest-apis', '' ) );
        $exempted  = has_filter( 'bb_exclude_endpoints_from_restriction' ) !== false;

        $lines[] = self::line(
            'BuddyBoss private REST API',
            $bbPrivate ? 'ON — it 401s our routes unless they are exempted' : 'off',
            $bbPrivate ? 'warn' : 'ok'
        );
        $lines[] = self::line(
            'Our exemption filter registered',
            $exempted ? 'yes — checkout-audience is exempted' : 'NO',
            $exempted ? 'ok' : 'fail'
        );
        if ( $bbPrivate && ! $exempted ) {
            $status   = 'fail';
            $issues[] = 'BuddyBoss is restricting the REST API and nothing exempts our routes';
        }
        if ( $bbPrivate ) {
            $lines[] = self::line(
                'Routes still behind that 401',
                'sync-customer, patreon-standing, gift-mail — reported, not opened (#181)',
                'warn'
            );
        }

        // --- the live probe -------------------------------------------------
        $probe = self::probeExemptedRoute();
        $lines[] = self::line( 'Loopback probe of checkout-audience', $probe['words'], $probe['status'] );
        if ( $probe['status'] === 'fail' ) {
            $status   = 'fail';
            $issues[] = $probe['issue'];
        } elseif ( $probe['status'] === 'unknown' && $status === 'ok' ) {
            $status   = 'unknown';
            $issues[] = 'the loopback probe could not run, so the channel is reported from '
                      . 'in-process facts only';
        }

        $summary = $issues !== []
            ? ucfirst( implode( '; ', $issues ) ) . '.'
            : 'The billing app is pointed at this site and the server-to-server route answers.';

        return self::check( 'channel', 'Can the two halves reach each other?', $status, $summary, $lines,
            'The probe goes to 127.0.0.1 with a Host header, never to the public name — on live a '
            . 'plain public request is bot-challenged into a 403 that reads exactly like an outage.' );
    }

    /**
     * One POST to the ONE route #181 exempted, over loopback, with the real
     * shared secret. Deliberately side-effect free: `decide()` reads the state
     * and returns it, and with no email in the body nothing is logged and
     * nobody is refused.
     *
     * @return array{status:string,words:string,issue:string}
     */
    private static function probeExemptedRoute(): array
    {
        $secret = (string) get_option( 'lgms_shared_secret', '' );
        if ( $secret === '' ) {
            return [
                'status' => 'unknown',
                'words'  => 'not attempted — WordPress has no shared secret to authenticate with',
                'issue'  => 'the probe needs the shared secret, which is not set',
            ];
        }

        $url    = rest_url( 'lg-member-sync/v1/checkout-audience' );
        $parts  = (array) wp_parse_url( $url );
        $scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
        $host   = (string) ( $parts['host'] ?? '' );
        $port   = (int) ( $parts['port'] ?? ( $scheme === 'https' ? 443 : 80 ) );

        $res = self::post( $url, [
            'resolve' => $host !== '' ? [ "{$host}:{$port}:127.0.0.1" ] : [],
            'timeout' => 3,
            'headers' => [ 'Content-Type: application/json', 'X-LGMS-Token: ' . $secret ],
            'body'    => '{}',
        ] );

        if ( $res['error'] !== '' ) {
            return [
                'status' => 'unknown',
                'words'  => 'could not reach the site over loopback (' . self::short( $res['error'] ) . ')',
                'issue'  => 'the loopback probe failed',
            ];
        }

        $code = (int) $res['code'];
        $body = (string) $res['body'];

        if ( $code === 200 && str_contains( $body, '"state"' ) ) {
            return [ 'status' => 'ok', 'words' => 'HTTP 200 — our own answer came back', 'issue' => '' ];
        }
        if ( str_contains( $body, 'bb_rest_authorization_required' ) ) {
            return [
                'status' => 'fail',
                'words'  => 'HTTP ' . $code . ' — BuddyBoss answered, not us (bb_rest_authorization_required)',
                'issue'  => 'BuddyBoss is intercepting the exempted route, so the billing app cannot reach WordPress',
            ];
        }
        if ( $code === 403 ) {
            return [
                'status' => 'fail',
                'words'  => 'HTTP 403 — the shared secret was rejected',
                'issue'  => 'the route refused our shared secret',
            ];
        }
        return [
            'status' => 'fail',
            'words'  => 'HTTP ' . $code . ' — unexpected',
            'issue'  => 'the server-to-server route answered HTTP ' . $code,
        ];
    }

    /**
     * ⚠️ RAW CURL WITH CURLOPT_RESOLVE, NOT wp_remote_post — AND THAT IS THIS
     * PLUGIN'S DOCUMENTED CONVENTION, not a preference.
     * lg-patreon-stripe-poller/CLAUDE.md: *"Server-to-server HTTP: raw curl with
     * CURLOPT_RESOLVE => host:port:127.0.0.1 (CF challenges PHP-curl).
     * wp_remote_post does NOT work for these."* RestController::proxyToSlim and
     * Tick both already do exactly this.
     *
     * RESOLVE rather than a 127.0.0.1 URL with a Host header, which is the
     * tempting shortcut: keeping the real hostname in the URL means SNI, the
     * certificate and nginx's server_name all still match, and only the TCP
     * connection is pinned to the box. Cloudflare is never in the path, so the
     * bot-challenge 403 that reads exactly like an outage cannot happen.
     *
     * @param array{resolve:list<string>,timeout:int,headers:list<string>,body:string} $opts
     * @return array{error:string,code:int,body:string}
     */
    private static function post( string $url, array $opts ): array
    {
        /* TEST SEAM. Gate 91 swaps this to observe exactly what the probe asks
           for — the URL, the loopback pin and the timeout are all assertions,
           and a probe nobody can watch is a probe nobody can prove. */
        if ( self::$transport !== null ) {
            return ( self::$transport )( $url, $opts );
        }

        $ch = curl_init( $url );
        if ( $ch === false ) {
            return [ 'error' => 'curl_init failed', 'code' => 0, 'body' => '' ];
        }
        curl_setopt_array( $ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $opts['timeout'],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER     => $opts['headers'],
            CURLOPT_POSTFIELDS     => $opts['body'],
            CURLOPT_RESOLVE        => $opts['resolve'],
        ] );
        $body = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
        $err  = (string) curl_error( $ch );
        curl_close( $ch );

        if ( $body === false || $err !== '' ) {
            return [ 'error' => $err !== '' ? $err : 'the request failed', 'code' => $code, 'body' => '' ];
        }
        return [ 'error' => '', 'code' => $code, 'body' => (string) $body ];
    }

    // =========================================================================
    // Reading the billing app's settings file
    // =========================================================================

    /**
     * FOUR STATES, NEVER CONFLATED — because each needs a different fix and a
     * single "not configured" would send whoever reads it to the wrong one:
     *
     *   missing     the file is not there. Wrong box, or the app is not deployed.
     *   unreadable  it is there and WordPress may not read it. A permissions
     *               job for root, and the live-vs-dev2 difference above makes
     *               this a real possibility rather than a defensive branch.
     *   empty       readable and parsed to nothing. A truncated deploy.
     *   ok          parsed.
     *
     * ⚠️ A DIRECTORY AT THAT PATH IS `unreadable`, NOT `empty`, AND THE
     * DISTINCTION WAS FOUND BY GATE 91 RATHER THAN BY REVIEW. PHP's
     * file_get_contents() on a directory returns the EMPTY STRING, not false —
     * so the obvious implementation parsed nothing and reported "a truncated
     * deploy", sending whoever read it to look for a broken write when the real
     * answer is that the path points somewhere else entirely.
     *
     * @return array{state:string,path:string,reason:string,secrets:array,plain:array}
     */
    public static function envFacts(): array
    {
        if ( self::$envCache !== null ) {
            return self::$envCache;
        }

        $path = self::appEnvPath();
        $out  = [ 'state' => 'ok', 'path' => $path, 'reason' => '', 'secrets' => [], 'plain' => [] ];

        if ( ! file_exists( $path ) ) {
            $out['state']  = 'missing';
            $out['reason'] = 'no file at that path';
            return self::$envCache = $out;
        }
        if ( ! is_file( $path ) ) {
            $out['state']  = 'unreadable';
            $out['reason'] = 'that path exists but is not a file';
            return self::$envCache = $out;
        }
        if ( ! is_readable( $path ) ) {
            $out['state']  = 'unreadable';
            $out['reason'] = 'the file exists but this WordPress cannot read it';
            return self::$envCache = $out;
        }

        $raw = @file_get_contents( $path );
        if ( $raw === false ) {
            $out['state']  = 'unreadable';
            $out['reason'] = 'the read failed';
            return self::$envCache = $out;
        }

        $parsed = self::parseEnv( $raw );
        if ( $parsed === [] ) {
            $out['state']  = 'empty';
            $out['reason'] = 'the file parsed to no settings at all';
            return self::$envCache = $out;
        }

        /* THE BOUNDARY. Past this point no secret VALUE exists in the returned
           structure — only present/length/sha256, and the sha is consumed by
           hash_equals and never rendered. This is what makes "never print a
           secret" a property of the data rather than a rule the renderer has to
           keep remembering. */
        foreach ( self::SECRET_KEYS as $k ) {
            $v = (string) ( $parsed[ $k ] ?? '' );
            $out['secrets'][ $k ] = [
                'present' => $v !== '',
                'len'     => strlen( $v ),
                'sha'     => $v === '' ? '' : hash( 'sha256', $v ),
                'mode'    => self::keyMode( $v ),
            ];
        }
        foreach ( self::PLAIN_KEYS as $k ) {
            $out['plain'][ $k ] = (string) ( $parsed[ $k ] ?? '' );
        }

        return self::$envCache = $out;
    }

    /**
     * WHEN WEBHOOK RECORDING ARRIVED ON THIS BOX, as 'Y-m-d H:i:s' UTC.
     *
     * The recorder file's own mtime, which a `git pull` sets. It is not a
     * perfect clock — a redeploy moves it forward and the window shrinks
     * accordingly — but it errs in the SAFE direction: it can only ever make
     * this check quieter, never make it invent a failure. Guessing the other
     * way round would be a panel that cries wolf, which is the failure mode
     * that gets a health screen ignored.
     *
     * Returns null when the recorder cannot be found, and the caller reports
     * `unknown` rather than choosing whichever answer looks tidier.
     */
    public static function whenRecordingStarted(): ?string
    {
        $path = (string) ( $_SERVER['LG_HEALTH_RECORDER'] ?? getenv( 'LG_HEALTH_RECORDER' ) ?: self::RECORDER_PATH );
        if ( $path === '' || ! is_file( $path ) ) {
            return null;
        }
        $t = @filemtime( $path );
        return $t === false ? null : gmdate( 'Y-m-d H:i:s', $t );
    }

    /** Where the app's settings file is. Overridable so a gate can point elsewhere. */
    public static function appEnvPath(): string
    {
        /* BOTH $_SERVER AND getenv(): a value delivered by fastcgi_param lands
           in $_SERVER only, and reading one of the two is how a flag ends up
           serving the wrong state on the very URL built to exercise it. */
        $override = (string) ( $_SERVER['LG_HEALTH_APP_ENV'] ?? getenv( 'LG_HEALTH_APP_ENV' ) ?: '' );
        if ( $override !== '' ) {
            return $override;
        }
        if ( function_exists( 'apply_filters' ) ) {
            return (string) apply_filters( 'lgms_health_app_env_path', self::APP_ENV_PATH );
        }
        return self::APP_ENV_PATH;
    }

    /**
     * A dotenv-shaped file, parsed conservatively: `#` comments, `KEY=value`,
     * optional surrounding quotes, CR tolerated. Anything it does not
     * understand it ignores rather than guessing.
     *
     * @return array<string,string>
     */
    private static function parseEnv( string $raw ): array
    {
        $out = [];
        /* Split on "\n" explicitly. PCRE's \R without /u matches byte 0x85 —
           the third byte of a multi-byte character — and halves the line. */
        foreach ( explode( "\n", $raw ) as $line ) {
            $line = trim( $line, " \t\r" );
            if ( $line === '' || $line[0] === '#' ) {
                continue;
            }
            $eq = strpos( $line, '=' );
            if ( $eq === false ) {
                continue;
            }
            $k = trim( substr( $line, 0, $eq ) );
            $v = trim( substr( $line, $eq + 1 ) );
            if ( $k === '' || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $k ) ) {
                continue;
            }
            if ( strlen( $v ) >= 2 && ( ( $v[0] === '"' && substr( $v, -1 ) === '"' ) || ( $v[0] === "'" && substr( $v, -1 ) === "'" ) ) ) {
                $v = substr( $v, 1, -1 );
            }
            $out[ $k ] = $v;
        }
        return $out;
    }

    // =========================================================================
    // Small shared helpers
    // =========================================================================

    /**
     * test / live / unknown, from a Stripe key's documented prefix.
     * The prefix itself never leaves this function — only the verdict — so a
     * key never reaches the panel even one character at a time.
     */
    private static function keyMode( string $key ): string
    {
        if ( $key === '' ) { return 'unknown'; }
        if ( preg_match( '/^(sk|pk|rk)_test_/', $key ) ) { return 'test'; }
        if ( preg_match( '/^(sk|pk|rk)_live_/', $key ) ) { return 'live'; }
        return 'unknown';
    }

    private static function modeWords( string $mode ): string
    {
        return match ( $mode ) {
            'test'  => 'TEST mode',
            'live'  => 'LIVE mode — real money',
            default => 'not set, or not a recognisable Stripe key',
        };
    }

    /** WordPress-ish truthiness for an option that may be '1', 1, true or 'yes'. */
    private static function truthy( string $v ): bool
    {
        return in_array( strtolower( trim( $v ) ), [ '1', 'true', 'yes', 'on' ], true );
    }

    /** Seconds since a 'Y-m-d H:i:s' UTC stamp, or null if it will not parse. */
    private static function ageOf( string $utc ): ?int
    {
        if ( trim( $utc ) === '' ) { return null; }
        $t = strtotime( $utc . ' UTC' );
        return $t === false ? null : ( time() - $t );
    }

    /**
     * "3 days ago", and NEVER a blank. Keeper, 2026-08-21: a blank cell reads
     * like health, so the absence of a timestamp is spelled out in words.
     */
    private static function ago( string $utc ): string
    {
        $age = self::ageOf( $utc );
        if ( $age === null )  { return 'never'; }
        if ( $age < 90 )      { return 'just now'; }
        if ( $age < 5400 )    { return (int) round( $age / 60 ) . ' minutes ago'; }
        if ( $age < 172800 )  { return (int) round( $age / 3600 ) . ' hours ago'; }
        return (int) round( $age / 86400 ) . ' days ago';
    }

    private static function hostResolves( string $host ): bool
    {
        if ( $host === '' ) { return false; }
        return checkdnsrr( $host . '.', 'A' ) || checkdnsrr( $host . '.', 'AAAA' ) || checkdnsrr( $host . '.', 'CNAME' );
    }

    private static function short( string $m, int $max = 160 ): string
    {
        $m = trim( preg_replace( '/\s+/', ' ', $m ) ?? $m );
        return strlen( $m ) > $max ? substr( $m, 0, $max - 1 ) . '…' : $m;
    }

    private static function worseOf( string $a, string $b ): string
    {
        $rank = [ 'ok' => 0, 'neutral' => 0, 'warn' => 1, 'unknown' => 2, 'fail' => 3 ];
        return ( $rank[ $b ] ?? 0 ) > ( $rank[ $a ] ?? 0 ) ? $b : $a;
    }

    /** @return array{label:string,value:string,status:string} */
    private static function line( string $label, string $value, string $status ): array
    {
        return [ 'label' => $label, 'value' => $value, 'status' => $status ];
    }

    private static function check( string $key, string $title, string $status, string $summary, array $lines, string $note ): array
    {
        return [
            'key'     => $key,
            'title'   => $title,
            'status'  => $status,
            'summary' => $summary,
            'lines'   => $lines,
            'note'    => $note,
        ];
    }
}
