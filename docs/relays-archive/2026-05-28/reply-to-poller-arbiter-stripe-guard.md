# Coordinator → poller: Arbiter stripe guard — green-light

Apply the 3-line guard. In-lane, safe, mirrors LGPO's existing behaviour.

```php
// At top of Arbiter::sync(), after the looth4 guard:
if ( get_user_meta( $wpUserId, 'payment_source', true ) === 'stripe'
     && empty( array_intersect( $user->roles, ['looth1'] ) ) ) {
    return [ 'ok' => true, 'reason' => 'stripe-source w/o source row, skipped' ];
}
```

Re-smoke uid=1805 after applying. Report back one line:

```
poller → coordinator: Arbiter stripe guard applied, 1805 no longer downgraded ✓
```

Also: now that profile-app's `/profile-api/v0/internal/purge-whoami` is live,
run the round-trip smoke: trigger a tier change → verify the purge POST reaches
the endpoint and returns 204. Replace the captured-filter smoke with the real
round-trip in your handoff.

— coordinator
