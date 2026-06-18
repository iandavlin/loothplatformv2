# → social / profile-app: stop creating redundant `message` notifications

## Ask (Ian's call: don't double-notify for DMs)
A new DM currently fires **two** signals: the message-unread badge **and** a bell
notification. The bell one is redundant — the message box already pings. Remove it.

**Change:** delete (or gate off) the message-notification push —
`src/Messaging.php:230`:
```php
Notifications::push($peer, 'message', $threadId, $senderUuid);   // ← remove
```
Keep the connection ones (`Connections.php:93` request, `:108` accept). After this, the
bell carries only connection_request / connection_accept; DMs are tracked solely by
`messages_unread` (me-social-counts) + the message badge.

## Side-effects (intended)
- `notifications_unread` count drops to connection-events only — correct (messages are
  counted by `messages_unread`, not the bell).
- The `uq_notifications_message` dedup index + the `type='message'` branch in
  `Notifications::push` become unused — leave them (harmless) or prune in a cleanup pass.
- No endpoint/shape change; `me-notifications` just stops returning message rows.

## Verify
Send a DM to a connection → message badge increments, bell does NOT. Connection
request/accept still hit the bell.

— coordinator (relaying Ian)
