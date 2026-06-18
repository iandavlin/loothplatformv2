<?php

declare(strict_types=1);

namespace LGMS\Wp;

use LGMS\UserLifecycle;
use Throwable;

/**
 * Exposes UserLifecycle::teardown() where admins actually manage users — the
 * WP Users screen. Adds per-row "Tombstone member" / "Nuke member" actions and
 * matching bulk actions, both routed through a confirm interstitial that shows
 * the dry-run preview counts before anything is destroyed. Nuke additionally
 * requires type-to-confirm.
 *
 * All entry points are manage_options + nonce gated.
 */
final class UserLifecycleAdmin
{
    public const PAGE_SLUG = 'lgms-user-lifecycle';
    private const NONCE_GO  = 'lgms_ulc_go';   // row-action / bulk -> confirm page
    private const NONCE_RUN = 'lgms_ulc_run';  // confirm page -> exec

    public static function boot(): void
    {
        add_filter( 'user_row_actions', [ self::class, 'rowActions' ], 10, 2 );
        add_filter( 'bulk_actions-users', [ self::class, 'bulkActions' ] );
        add_filter( 'handle_bulk_actions-users', [ self::class, 'handleBulk' ], 10, 3 );
        add_action( 'admin_menu', [ self::class, 'registerHiddenPage' ] );
        add_action( 'admin_post_lgms_ulc_exec', [ self::class, 'handleExec' ] );
        add_action( 'admin_notices', [ self::class, 'maybeShowResult' ] );
    }

    // ---- Users-screen surfaces ---------------------------------------------

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function rowActions( array $actions, \WP_User $user ): array
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return $actions;
        }
        $uid = (int) $user->ID;
        if ( $uid === get_current_user_id() ) {
            return $actions; // never offer self-teardown
        }

        $base = [ 'page' => self::PAGE_SLUG, 'user' => $uid ];
        $tomb = wp_nonce_url( add_query_arg( $base + [ 'op' => UserLifecycle::MODE_TOMBSTONE ], admin_url( 'admin.php' ) ), self::NONCE_GO );
        $nuke = wp_nonce_url( add_query_arg( $base + [ 'op' => UserLifecycle::MODE_NUKE ], admin_url( 'admin.php' ) ), self::NONCE_GO );

        $actions['lgms_tombstone'] = '<a href="' . esc_url( $tomb ) . '">Tombstone member</a>';
        $actions['lgms_nuke']      = '<a href="' . esc_url( $nuke ) . '" style="color:#b91c1c;">Nuke member</a>';
        return $actions;
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function bulkActions( array $actions ): array
    {
        if ( current_user_can( 'manage_options' ) ) {
            $actions['lgms_tombstone'] = 'Tombstone member (lifecycle)';
            $actions['lgms_nuke']      = 'Nuke member (lifecycle)';
        }
        return $actions;
    }

    /**
     * @param int[] $userIds
     */
    public static function handleBulk( string $redirectTo, string $action, array $userIds ): string
    {
        if ( ! in_array( $action, [ 'lgms_tombstone', 'lgms_nuke' ], true ) ) {
            return $redirectTo;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return $redirectTo;
        }
        $op  = $action === 'lgms_nuke' ? UserLifecycle::MODE_NUKE : UserLifecycle::MODE_TOMBSTONE;
        $ids = array_values( array_filter( array_map( 'intval', $userIds ) ) );
        $url = add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'op' => $op, 'users' => implode( ',', $ids ) ],
            admin_url( 'admin.php' )
        );
        return wp_nonce_url( $url, self::NONCE_GO );
    }

    // ---- Hidden confirm page -----------------------------------------------

    public static function registerHiddenPage(): void
    {
        add_submenu_page(
            '',                       // no parent -> not shown in any menu
            'User lifecycle',
            'User lifecycle',
            'manage_options',
            self::PAGE_SLUG,
            [ self::class, 'renderConfirm' ]
        );
    }

    public static function renderConfirm(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( (string) $_GET['_wpnonce'], self::NONCE_GO ) ) {
            wp_die( 'Expired or invalid link. Go back to Users and try again.', 400 );
        }

        $op  = sanitize_text_field( (string) ( $_GET['op'] ?? '' ) );
        if ( ! in_array( $op, [ UserLifecycle::MODE_NUKE, UserLifecycle::MODE_TOMBSTONE ], true ) ) {
            wp_die( 'Unknown operation.', 400 );
        }
        $ids = self::idsFromRequest();
        if ( $ids === [] ) {
            wp_die( 'No users selected.', 400 );
        }

        $isNuke = $op === UserLifecycle::MODE_NUKE;
        $single = count( $ids ) === 1 ? get_userdata( $ids[0] ) : null;

        // Build a dry-run preview (aggregated across selected users).
        $previews = [];
        $aggregate = [];
        $warnings  = [];
        foreach ( $ids as $uid ) {
            $u = get_userdata( $uid );
            if ( ! $u ) {
                continue;
            }
            try {
                $r = UserLifecycle::teardown( $uid, $op, true );
            } catch ( Throwable $e ) {
                $warnings[] = "#{$uid}: " . $e->getMessage();
                continue;
            }
            $previews[ $uid ] = [ 'user' => $u, 'result' => $r ];
            foreach ( $r['counts'] as $k => $v ) {
                if ( $v < 0 ) { continue; } // skipped markers (e.g. discovery)
                $aggregate[ $k ] = ( $aggregate[ $k ] ?? 0 ) + $v;
            }
            foreach ( $r['errors'] as $err ) {
                $warnings[] = "#{$uid}: " . $err;
            }
        }
        $warnings = array_values( array_unique( $warnings ) );

        $title = $isNuke ? 'Nuke member' : 'Tombstone member';
        ?>
        <div class="wrap">
            <h1 style="<?php echo $isNuke ? 'color:#b91c1c;' : ''; ?>"><?php echo esc_html( $title ); ?><?php echo count( $ids ) > 1 ? ' — ' . count( $ids ) . ' users' : ''; ?></h1>

            <p class="description" style="max-width:760px;">
                <?php if ( $isNuke ) : ?>
                    <strong>Destructive and irreversible.</strong> Cancels Stripe subscriptions, deletes the WP account, all BuddyPress / lg_membership / profile-app identity rows, media, and <strong>all content this member authored</strong> (posts, comments, forum topics &amp; replies, discovery rows).
                <?php else : ?>
                    Erases this member's identity everywhere (WP account, BuddyPress, lg_membership, profile-app, media) and cancels billing — but <strong>keeps their authored content</strong>, reassigning it to a stable <code>[deleted member]</code> account so threads still render.
                <?php endif; ?>
            </p>

            <h2>Members</h2>
            <table class="widefat striped" style="max-width:760px;">
                <thead><tr><th>User</th><th>Email</th><th>Roles</th></tr></thead>
                <tbody>
                <?php foreach ( $ids as $uid ) :
                    $u = get_userdata( $uid );
                    if ( ! $u ) : ?>
                        <tr><td colspan="3"><em>#<?php echo (int) $uid; ?> — not found</em></td></tr>
                    <?php else : ?>
                        <tr>
                            <td>#<?php echo (int) $uid; ?> · <code><?php echo esc_html( $u->user_login ); ?></code></td>
                            <td><?php echo esc_html( $u->user_email ); ?></td>
                            <td><?php echo esc_html( implode( ', ', (array) $u->roles ) ); ?></td>
                        </tr>
                    <?php endif;
                endforeach; ?>
                </tbody>
            </table>

            <h2>What will be removed (dry-run preview)</h2>
            <?php if ( $aggregate === [] ) : ?>
                <p><em>No matching rows found across the selected member(s).</em></p>
            <?php else : ?>
                <table class="widefat striped" style="max-width:520px;">
                    <tbody>
                    <?php foreach ( $aggregate as $store => $n ) : ?>
                        <tr><th style="text-align:left;"><?php echo esc_html( $store ); ?></th><td style="text-align:right;"><?php echo (int) $n; ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <?php if ( $warnings !== [] ) : ?>
                <div class="notice notice-warning" style="max-width:760px;margin-top:1em;">
                    <p><strong>Heads up — these stores reported an issue on dry-run and may not complete:</strong></p>
                    <ul style="list-style:disc;margin-left:1.5em;">
                        <?php foreach ( $warnings as $w ) : ?>
                            <li><?php echo esc_html( $w ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <h2 style="margin-top:1.5em;<?php echo $isNuke ? 'color:#b91c1c;' : ''; ?>">Confirm</h2>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
                  onsubmit="return <?php echo $isNuke ? 'lgmsConfirmNuke(this)' : "confirm('Tombstone the selected member(s)?')"; ?>;">
                <?php wp_nonce_field( self::NONCE_RUN ); ?>
                <input type="hidden" name="action" value="lgms_ulc_exec">
                <input type="hidden" name="op" value="<?php echo esc_attr( $op ); ?>">
                <input type="hidden" name="users" value="<?php echo esc_attr( implode( ',', $ids ) ); ?>">

                <?php if ( $isNuke ) :
                    $token = $single ? $single->user_login : 'NUKE';
                    ?>
                    <p>Type <code><?php echo esc_html( $token ); ?></code> to confirm:</p>
                    <p><input type="text" id="lgms-ulc-confirm" name="confirm" class="regular-text" autocomplete="off" required></p>
                    <input type="hidden" id="lgms-ulc-token" value="<?php echo esc_attr( $token ); ?>">
                    <p><button type="submit" class="button button-link-delete" style="color:#b91c1c;">Nuke <?php echo count( $ids ) > 1 ? count( $ids ) . ' members' : 'member'; ?></button></p>
                <?php else : ?>
                    <p><button type="submit" class="button button-primary">Tombstone <?php echo count( $ids ) > 1 ? count( $ids ) . ' members' : 'member'; ?></button></p>
                <?php endif; ?>

                <p><a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Cancel</a></p>
            </form>
        </div>
        <?php if ( $isNuke ) : ?>
        <script>
        function lgmsConfirmNuke(form){
            var want = document.getElementById('lgms-ulc-token').value;
            var got  = document.getElementById('lgms-ulc-confirm').value;
            if (got !== want){ alert('Type ' + want + ' exactly to confirm.'); return false; }
            return confirm('This permanently destroys the member and ALL their content. Continue?');
        }
        </script>
        <?php endif;
    }

    // ---- Execute ------------------------------------------------------------

    public static function handleExec(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Insufficient permissions.', 403 );
        }
        check_admin_referer( self::NONCE_RUN );

        $op  = sanitize_text_field( (string) ( $_POST['op'] ?? '' ) );
        if ( ! in_array( $op, [ UserLifecycle::MODE_NUKE, UserLifecycle::MODE_TOMBSTONE ], true ) ) {
            wp_die( 'Unknown operation.', 400 );
        }
        $ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) ( $_POST['users'] ?? '' ) ) ) ) );
        if ( $ids === [] ) {
            wp_die( 'No users selected.', 400 );
        }

        if ( $op === UserLifecycle::MODE_NUKE ) {
            $confirm = (string) ( $_POST['confirm'] ?? '' );
            $single  = count( $ids ) === 1 ? get_userdata( $ids[0] ) : null;
            $token   = $single ? $single->user_login : 'NUKE';
            if ( ! hash_equals( $token, $confirm ) ) {
                wp_die( 'Confirmation text did not match. Nuke aborted.', 400 );
            }
        }

        $ok = 0; $fail = 0; $errors = [];
        foreach ( $ids as $uid ) {
            try {
                $r = UserLifecycle::teardown( $uid, $op, false );
                if ( $r['errors'] === [] ) {
                    $ok++;
                } else {
                    $fail++;
                    foreach ( $r['errors'] as $e ) { $errors[] = "#{$uid}: {$e}"; }
                }
            } catch ( Throwable $e ) {
                $fail++;
                $errors[] = "#{$uid}: " . $e->getMessage();
            }
        }

        $summary = sprintf( '%s: %d ok, %d with issues.', $op, $ok, $fail );
        if ( $errors !== [] ) {
            set_transient( 'lgms_ulc_result_' . get_current_user_id(), array_slice( $errors, 0, 50 ), 120 );
        }
        wp_safe_redirect( add_query_arg( [ 'lgms_ulc' => rawurlencode( $summary ) ], admin_url( 'users.php' ) ) );
        exit;
    }

    public static function maybeShowResult(): void
    {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_GET['lgms_ulc'] ) ) {
            return;
        }
        $summary = sanitize_text_field( rawurldecode( (string) $_GET['lgms_ulc'] ) );
        $errors  = get_transient( 'lgms_ulc_result_' . get_current_user_id() );
        delete_transient( 'lgms_ulc_result_' . get_current_user_id() );
        $class = is_array( $errors ) && $errors !== [] ? 'notice-warning' : 'notice-success';
        ?>
        <div class="notice <?php echo esc_attr( $class ); ?> is-dismissible">
            <p><strong>User lifecycle —</strong> <?php echo esc_html( $summary ); ?></p>
            <?php if ( is_array( $errors ) && $errors !== [] ) : ?>
                <ul style="list-style:disc;margin-left:1.5em;">
                    <?php foreach ( $errors as $e ) : ?>
                        <li><?php echo esc_html( (string) $e ); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /** Read a single ?user= or a ?users=a,b,c list into an int[]. */
    private static function idsFromRequest(): array
    {
        if ( isset( $_GET['users'] ) ) {
            return array_values( array_filter( array_map( 'intval', explode( ',', (string) $_GET['users'] ) ) ) );
        }
        if ( isset( $_GET['user'] ) ) {
            $uid = (int) $_GET['user'];
            return $uid > 0 ? [ $uid ] : [];
        }
        return [];
    }
}
