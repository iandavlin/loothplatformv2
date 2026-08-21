<?php

declare(strict_types=1);

namespace LGMS;

/**
 * TesterUnlock — THE WRITE HALF of #180's anonymous tester link. Issue #190.
 *
 * Ian, 2026-08-21: *"Can we put the token link in there with the whitlist ?"*
 *
 * ┌─ THE OPERATIONAL HOLE THIS CLOSES ───────────────────────────────────────┐
 * │ #180's link existed ONLY because keeper minted it by hand and pasted it  │
 * │ into a chat message. The config stores sha256(token), so the working URL │
 * │ CANNOT be read back off the box. Lose the message and the link is gone   │
 * │ — for a feature about to be handed to real testers.                      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ── WHY THE STORE IS SPLIT IN TWO, WHICH IS THE WHOLE DESIGN ────────────────
 * Two stores, ONE writer (this class), split by who has to read them:
 *
 *   raw token      wp_option lgms_tester_unlock_token      wp-admin only
 *   sha256+enabled /srv/lg-shared-state/tester-unlock.json all seven apps
 *
 * Neither half could carry both jobs, and both constraints were measured
 * rather than assumed (2026-08-21):
 *
 *   • The dash CANNOT write platform/config/tester-unlock.local.php, where
 *     #180 put the hash. That directory in the serving checkout is
 *     ubuntu:ubuntu 0755 and WordPress runs as FPM pool looth-dev. A Rotate
 *     button aimed at it would simply fail.
 *
 *   • The hash CANNOT move to a wp_option. lg-shared/tester-unlock.php is
 *     required by lg-shared/site-header.php, which renders on SEVEN apps under
 *     seven different unix users (bb-mirror, archive-poc, events, membership,
 *     looth-dev, profile-app, tool-dev) and has no database at all. That is
 *     precisely why #180 used a file.
 *
 * ── THE RAW TOKEN IS STORED, AND THAT IS A DELIBERATE CHANGE FROM #180 ──────
 * #180's property was "the store holds a hash, never the token", so that
 * reading the config hands nobody a working link. Ian asked for the opposite
 * here — a link shown in full with a copy button — because its whole purpose is
 * to be sent to people. Saying so plainly rather than slipping it in:
 *
 *   • The raw token lives in wp_options and NOWHERE else. It is never written
 *     to the shared state file, never logged, and never put in a redirect URL
 *     (the neighbouring invite panel does pass its raw token through a query
 *     arg — it lands in the admin URL, browser history and any onward Referer.
 *     Not copied here; noted on #190 as observed, not fixed).
 *   • It is shown only to manage_options.
 *   • The trust level is already established: wp_options on these boxes holds
 *     lgms_db_pass and lgms_stripe_secret_key. An unlock token that grants
 *     nothing but PAGE VISIBILITY is a strictly lower risk class than either.
 *
 * ── TURNING IT OFF WRITES false, IT DOES NOT DELETE THE FILE ────────────────
 * An absent state file is SILENCE, and silence loses to a hand-placed
 * .local.php — dev2 carries an armed one right now. So Clear must write
 * enabled=false, or it would appear to work in the dash while the site stayed
 * armed. Same reason the state file is read AFTER the box file in the reader.
 *
 * ── WRITE ORDER AND ROLLBACK ────────────────────────────────────────────────
 * The two halves can disagree only if a write half-fails, and the two failure
 * modes are not equally bad:
 *
 *   state written, option not  → armed on a hash whose token nobody can see.
 *                                UNRECOVERABLE without a rotate. Rolled back.
 *   option written, state not  → the dash shows a link that does not work.
 *                                Visible, harmless, fixed by clicking again.
 *
 * So the state file is written first and ROLLED BACK if the option does not
 * read back. And the tab reports agreement between the two halves rather than
 * assuming it — the same instinct as #190's webhook-secret check.
 */
final class TesterUnlock
{
    /** The RAW token. wp-admin only; the seven apps never read this. */
    public const OPT_TOKEN = 'lgms_tester_unlock_token';

    /** When it was last minted (gmdate 'c'), for the tab's plain-words state. */
    public const OPT_ROTATED = 'lgms_tester_unlock_rotated';

    /** 24 bytes = 48 hex, matching the `openssl rand -hex 24` in #180's docblock. */
    private const TOKEN_BYTES = 24;

    // -------------------------------------------------------------------------
    // The shared reader — loaded, never reimplemented
    // -------------------------------------------------------------------------

    /**
     * Load lg-shared/tester-unlock.php so the dash answers "is this armed?"
     * with THE SAME CODE the seven apps run.
     *
     * A second reader here would be a second definition of "armed", and the two
     * would drift the first time one of them was changed — the mistake #190's
     * own issue is about (one webhook secret, two homes, nothing comparing
     * them). /srv/lg-shared is the deployed path; the repo-relative fallback is
     * for gate runs and any box without the symlink.
     */
    public static function loadReader(): bool
    {
        if ( function_exists( 'lg_tester_unlock_armed' ) ) { return true; }

        foreach ( [ '/srv/lg-shared', __DIR__ . '/../../lg-shared' ] as $dir ) {
            /* site-header.php is preferred because it REQUIRES tester-unlock.php
               from its own __DIR__ — so both function families arrive from the
               SAME tree and cannot be mixed. That matters more than it looks:
               tester-unlock.php declares top-level `const`s, and require_once
               keys on the resolved path, so pulling one copy from /srv and
               another from a worktree defines them twice and emits warnings on
               every page of seven apps. Loading both from one directory makes
               that impossible rather than unlikely.

               It is also how the tab learns the header's join state, which is
               the pairing a person needs: in header state 'off' the unlock
               changes nothing (#170's ruling), and a tab that could not say so
               would show an armed link that does nothing. */
            if ( is_readable( $dir . '/site-header.php' ) ) {
                require_once $dir . '/site-header.php';
            } elseif ( is_readable( $dir . '/tester-unlock.php' ) ) {
                require_once $dir . '/tester-unlock.php';
            }
            if ( function_exists( 'lg_tester_unlock_armed' ) ) { return true; }
        }
        return false;
    }

    /**
     * What the header's Join is doing on this box: 'off' | 'allowlist' | 'on',
     * or 'unknown' when the shared partial could not be loaded.
     *
     * Asked of lg_shared_header_join_stripe_state() rather than re-resolved
     * here, for the reason this whole issue exists: a second copy of "what
     * state is the header in" is how two answers drift apart.
     *
     * 'unknown' is returned rather than guessed, and the tab prints it as such.
     * A tab that quietly said 'off' when it simply could not tell would send
     * whoever read it to fix the wrong thing.
     */
    public static function headerJoinState(): string
    {
        self::loadReader();
        return function_exists( 'lg_shared_header_join_stripe_state' )
            ? lg_shared_header_join_stripe_state()
            : 'unknown';
    }

    /** Where the operator store lives, asked of the reader so there is one answer. */
    public static function statePath(): string
    {
        if ( self::loadReader() && function_exists( 'lg_tester_unlock_state_path' ) ) {
            return lg_tester_unlock_state_path();
        }
        return '/srv/lg-shared-state/tester-unlock.json';
    }

    // -------------------------------------------------------------------------
    // Reading
    // -------------------------------------------------------------------------

    /** The raw token this box can show, or '' if the dash has never minted one. */
    public static function token(): string
    {
        $t = get_option( self::OPT_TOKEN, '' );
        $t = is_string( $t ) ? trim( $t ) : '';
        // Anything that is not a token SHAPE is treated as no token, so a
        // hand-edited option cannot produce a link that was never going to work.
        return preg_match( '/^[a-f0-9]{32,64}$/', $t ) === 1 ? $t : '';
    }

    /** The shareable URL, or '' when there is no token to put in one. */
    public static function url(): string
    {
        $t = self::token();
        if ( $t === '' ) { return ''; }
        // home_url so the link names the box it was minted on — dev2 on dev2,
        // loothgroup.com on live — rather than a host baked in here.
        return home_url( '/lgjoin/?lgtester=' . rawurlencode( $t ) );
    }

    /** Is the SITE armed? Asked of the shared reader, not of our own option. */
    public static function siteArmed(): bool
    {
        return self::loadReader() ? lg_tester_unlock_armed() : false;
    }

    /**
     * The whole picture the Testers tab renders, in one call.
     *
     * 'mode' is the question a person actually has, answered in one word:
     *
     *   dash      armed, and the stored token matches the armed hash
     *             → the link below is the live one. The normal state.
     *   foreign   armed, but NOT by anything this dash minted (a hand-placed
     *             .local.php, or an env override). dev2 is in this state today.
     *             → we can say it is on; we CANNOT show the link, and saying so
     *               is the whole point. Rotate takes ownership.
     *   stale     we hold a token but the site is not armed — a Clear that only
     *             half-landed, or a box file that disarmed underneath us.
     *   off       not armed and no token. Nothing to show, nothing wrong.
     *
     * @return array{mode:string,armed:bool,token:string,url:string,rotated:string,
     *               state_exists:bool,state_enabled:bool,state_hash:string,
     *               state_path:string,writable:bool,agrees:bool}
     */
    public static function describe(): array
    {
        $armed   = self::siteArmed();
        $token   = self::token();
        $path    = self::statePath();
        $exists  = is_readable( $path );

        $stateEnabled = false;
        $stateHash    = '';
        if ( $exists && self::loadReader() && function_exists( 'lg_tester_unlock_state' ) ) {
            $s = lg_tester_unlock_state();
            if ( is_array( $s ) ) {
                $stateEnabled = ( ( $s['enabled'] ?? null ) === true );
                $h = is_string( $s['token_sha256'] ?? null ) ? strtolower( trim( $s['token_sha256'] ) ) : '';
                $stateHash = preg_match( '/^[a-f0-9]{64}$/', $h ) === 1 ? $h : '';
            }
        }

        // Does the link we would SHOW actually open the door that is open?
        // Compared with hash_equals for the usual reason, even though both
        // sides are ours: this is the one comparison that decides whether we
        // print a URL and call it live.
        $agrees = ( $token !== '' && $stateHash !== '' && hash_equals( $stateHash, hash( 'sha256', $token ) ) );

        if ( $armed && $agrees )        { $mode = 'dash'; }
        elseif ( $armed )               { $mode = 'foreign'; }
        elseif ( $token !== '' )        { $mode = 'stale'; }
        else                            { $mode = 'off'; }

        $rotated = get_option( self::OPT_ROTATED, '' );

        return [
            'mode'          => $mode,
            'armed'         => $armed,
            'token'         => $token,
            'url'           => self::url(),
            'rotated'       => is_string( $rotated ) ? $rotated : '',
            'state_exists'  => $exists,
            'state_enabled' => $stateEnabled,
            'state_hash'    => $stateHash,
            'state_path'    => $path,
            'writable'      => self::storeWritable(),
            'agrees'        => $agrees,
        ];
    }

    /**
     * Can this box's WordPress write the store at all?
     *
     * Asked so the tab can say "keeper has not made the directory yet" instead
     * of offering a Rotate button that fails. The directory is a one-time root
     * step per box:
     *
     *     install -d -o looth-dev -g looth-dev -m 755 /srv/lg-shared-state
     */
    public static function storeWritable(): bool
    {
        $path = self::statePath();
        if ( $path === '' ) { return false; }
        return is_writable( $path ) || ( ! file_exists( $path ) && is_writable( dirname( $path ) ) );
    }

    // -------------------------------------------------------------------------
    // Writing
    // -------------------------------------------------------------------------

    /**
     * ROTATE. Mint a new token, arm the site on its hash, and return the raw
     * token so the tab can show it.
     *
     * Every link already sent stops working the moment this succeeds — the tab
     * says so BEFORE the click, not after (Ian's ruling on #190).
     *
     * @return array{ok:bool,error:string,token:string,url:string}
     */
    public static function mint(): array
    {
        $fail = static fn( string $why ): array =>
            [ 'ok' => false, 'error' => $why, 'token' => '', 'url' => '' ];

        if ( ! self::storeWritable() ) {
            return $fail( 'The shared store is not writable by WordPress on this box: ' . self::statePath()
                . ' — it needs the one-time root step "install -d -o looth-dev -g looth-dev -m 755 '
                . dirname( self::statePath() ) . '". Nothing was changed.' );
        }

        try {
            $token = bin2hex( random_bytes( self::TOKEN_BYTES ) );
        } catch ( \Throwable $e ) {
            // random_bytes throws rather than returning weak bytes. A token that
            // is not unguessable is worse than no token, so this refuses.
            return $fail( 'No source of secure randomness on this box — nothing was changed.' );
        }

        // Remember what we are overwriting, so a half-failure can be undone.
        $before      = self::describe();
        $beforeState = $before['state_exists'] ? [ $before['state_enabled'], $before['state_hash'] ] : null;

        if ( ! self::writeState( true, hash( 'sha256', $token ) ) ) {
            return $fail( 'Could not write ' . self::statePath() . ' — nothing was changed, and the previous link (if any) still works.' );
        }

        update_option( self::OPT_TOKEN, $token, false );
        update_option( self::OPT_ROTATED, gmdate( 'c' ), false );

        // READ IT BACK. update_option returns false for a no-op write as well as
        // for a failure, so its return value cannot tell us what happened; the
        // stored value can. This is the half-failure that is unrecoverable —
        // armed on a hash whose token nobody can see — so it rolls back.
        if ( self::token() !== $token ) {
            if ( $beforeState === null ) {
                @unlink( self::statePath() );
            } else {
                self::writeState( $beforeState[0], $beforeState[1] );
            }
            return $fail( 'The new token did not store in wp_options, so the site was rolled back to its previous state. Nothing was armed on a link you cannot see.' );
        }

        return [ 'ok' => true, 'error' => '', 'token' => $token, 'url' => self::url() ];
    }

    /**
     * TURN IT OFF. Disarm the site and forget the token.
     *
     * Writes enabled=false rather than deleting the file, deliberately: an
     * absent file applies nothing, and on a box carrying an armed
     * tester-unlock.local.php — which dev2 does — "applies nothing" means STILL
     * ARMED. Deleting would make this button lie.
     *
     * @return array{ok:bool,error:string}
     */
    public static function clear(): array
    {
        if ( ! self::storeWritable() ) {
            return [ 'ok' => false, 'error' => 'The shared store is not writable by WordPress on this box: ' . self::statePath() . ' — nothing was changed.' ];
        }

        if ( ! self::writeState( false, '' ) ) {
            return [ 'ok' => false, 'error' => 'Could not write ' . self::statePath() . ' — the site is unchanged and may still be armed.' ];
        }

        // The token goes only AFTER the site is disarmed. The other order would
        // leave a live door open with no record here of what opens it.
        delete_option( self::OPT_TOKEN );
        update_option( self::OPT_ROTATED, '', false );

        return [ 'ok' => true, 'error' => '' ];
    }

    /**
     * Write the operator store, atomically.
     *
     * tmp + rename in the SAME directory, so a reader either sees the whole old
     * file or the whole new one and never a torn read. (The reader fails closed
     * on a torn read anyway; this means it never has to.)
     *
     * The raw token is deliberately absent from what is written here — this file
     * is world-readable by design, since seven unix users have to read it.
     */
    private static function writeState( bool $enabled, string $hash ): bool
    {
        $path = self::statePath();
        $dir  = dirname( $path );
        if ( ! is_dir( $dir ) ) { return false; }

        $json = wp_json_encode( [
            'enabled'      => $enabled,
            'token_sha256' => $hash,
            // Provenance, not policy. Nothing reads these; they answer "who
            // armed this box, and when" months later without a git archaeology
            // session. No user id, no token, nothing secret.
            'written_by'   => 'lg-member-sync/testers-tab',
            'written_at'   => gmdate( 'c' ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

        if ( ! is_string( $json ) ) { return false; }

        $tmp = @tempnam( $dir, '.tu-' );
        if ( ! is_string( $tmp ) || $tmp === '' ) { return false; }

        if ( @file_put_contents( $tmp, $json . "\n", LOCK_EX ) === false ) {
            @unlink( $tmp );
            return false;
        }

        // tempnam creates 0600. The seven app users have to READ this, and they
        // share no group with looth-dev — measured — so it must be world-readable.
        // It carries a hash and never the token, which is what makes that safe.
        @chmod( $tmp, 0644 );

        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return false;
        }

        // A reader in this same request would otherwise be served the old bytes,
        // and the shared reader caches its resolved config in a static — so
        // without both of these the dash asks "did that work?" and is answered
        // from before the write. Found by probing, not by reasoning: the first
        // run of this class reported "not armed" on a box it had just armed.
        if ( function_exists( 'clearstatcache' ) ) { clearstatcache( true, $path ); }
        if ( function_exists( 'lg_tester_unlock_forget' ) ) { lg_tester_unlock_forget(); }
        return true;
    }
}
