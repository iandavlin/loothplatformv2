<?php

declare(strict_types=1);

namespace LGMS;

/**
 * Invites — email pre-authorisation and one-time join links.
 *
 * Ian found the hole on 2026-08-16: the Stripe Test Group takes only EXISTING wp
 * users, so the most important pre-cutover rehearsal — a fresh recruit going
 * from nothing to a paid membership — was untestable, because a fresh person
 * cannot even SEE the join page. He ruled the mechanism the same evening: a URL
 * token, single-use, working across devices.
 *
 * THIS IS THE WRITE HALF. The membership-pages app only ever READS an invite to
 * decide admission (web/_invites.php); minting and consuming live here, in
 * WordPress, where the admin dash and user registration already are.
 *
 * ┌─ WHAT IS STORED, AND WHAT DELIBERATELY IS NOT ───────────────────────────┐
 * │ Stored: sha256(token) => { email, created, expires, used_at, used_by }   │
 * │ NOT stored: the token itself. Reading this option must not hand anyone a │
 * │ working invite — the same instinct as the Stripe broker, where a store   │
 * │ that can be read is not a store that can be used. The raw token exists   │
 * │ exactly once, in the URL handed to the person invited.                   │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class Invites
{
    public const OPT  = 'lgms_stripe_invites';
    public const FLAG = 'lgms_stripe_invites_on';

    /** Default 14 days: long enough for a person to get to it, short enough that a forgotten link dies. */
    public const DEFAULT_DAYS = 14;

    /** @return array<string,array> hash => record */
    public static function all(): array
    {
        $v = get_option( self::OPT, [] );
        return is_array( $v ) ? $v : [];
    }

    /**
     * Mint an invite for an email. Returns the RAW token exactly once — it is
     * never stored and cannot be recovered, so a lost link is re-minted rather
     * than looked up.
     *
     * @return array{token:string,url:string,expires:int}|null
     */
    public static function mint( string $email, ?int $days = null ): ?array
    {
        $email = strtolower( trim( $email ) );
        if ( $email === '' || ! is_email( $email ) ) { return null; }

        // An email that already HAS a live invite does not get a second one:
        // two live links for one person is two ways to spend a single-use
        // invite, and the second would look broken to whoever clicked it last.
        foreach ( self::all() as $rec ) {
            if ( strtolower( (string) ( $rec['email'] ?? '' ) ) !== $email ) { continue; }
            if ( ! empty( $rec['used_at'] ) ) { continue; }
            if ( (int) ( $rec['expires'] ?? 0 ) > time() ) { return null; }
        }

        $token = bin2hex( random_bytes( 16 ) );
        $days  = $days !== null && $days > 0 ? $days : self::DEFAULT_DAYS;
        $exp   = time() + ( $days * 86400 );

        $all = self::all();
        $all[ hash( 'sha256', $token ) ] = [
            'email'   => $email,
            'created' => time(),
            'expires' => $exp,
            'used_at' => null,
            'used_by' => null,
        ];
        update_option( self::OPT, $all, false );

        return [
            'token'   => $token,
            'url'     => home_url( '/lgjoin/?lginv=' . $token ),
            'expires' => $exp,
        ];
    }

    /** The live (unspent, unexpired) invite for an email, or null. */
    public static function liveFor( string $email ): ?string
    {
        $email = strtolower( trim( $email ) );
        foreach ( self::all() as $hash => $rec ) {
            if ( strtolower( (string) ( $rec['email'] ?? '' ) ) !== $email ) { continue; }
            if ( ! empty( $rec['used_at'] ) ) { continue; }
            if ( (int) ( $rec['expires'] ?? 0 ) <= time() ) { continue; }
            return (string) $hash;
        }
        return null;
    }

    /**
     * A NEW ACCOUNT APPEARED. If its email holds a live invite, spend the invite
     * and put the account on the Test Group list.
     *
     * SPENT ON ACCOUNT CREATION, not on first page view — Ian's "single use"
     * means one account, and burning it when the link is merely OPENED would
     * kill it on a refresh or a back button, which is a support ticket rather
     * than a fence.
     *
     * THE MATCH IS ON EMAIL, so a token cannot enrol somebody it was not issued
     * to: whoever clicks it still has to register the address it was minted for.
     * That is what keeps a forwarded link from becoming a general door.
     */
    public static function consumeForUser( int $wpUserId ): bool
    {
        if ( $wpUserId <= 0 ) { return false; }
        $user = get_userdata( $wpUserId );
        if ( ! $user || ! is_string( $user->user_email ) ) { return false; }

        $hash = self::liveFor( $user->user_email );
        if ( $hash === null ) { return false; }

        $all = self::all();
        $all[ $hash ]['used_at'] = time();
        $all[ $hash ]['used_by'] = $wpUserId;
        update_option( self::OPT, $all, false );

        // The list is the thing that actually admits them afterwards — the
        // invite only got them through the door once.
        CohortAllowlist::add( $wpUserId );

        // HOW they got in, answerable later rather than inferred. A member who
        // appears on the list with no explanation is a member somebody has to
        // reconstruct months afterwards.
        update_user_meta( $wpUserId, '_lgms_invite_created', gmdate( 'c' ) );
        return true;
    }
}
