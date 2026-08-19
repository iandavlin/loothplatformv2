# EMAIL — weekly digest + signup funnel

## The map
Front-page email field (archive front feed) → admin-ajax `lg_weekly_signup`
(mu-plugins/lg-event-reminders.php) → FluentCRM list 7, double opt-in →
confirmation email → weekly digest campaigns (FluentCRM, wp_fc_* tables in
looth_import on live). Sending: SES (healthy as of 8/19: 0.04% bounce, 0.00%
complaints). Members are auto-subscribed at registration; the field is for
strangers. Secondary path: /weekly-email-sign-up/ (public since 8/19 — was
BuddyBoss-login-walled, #136).

## Paid-for traps
- **Any email link built as a root-query URL (`/?…`) dies on the front door**
  unless escaped in `platform/nginx/strangler-archive-poc.conf` `location = /`.
  Clicks (`?ns_url=`) broke at the 6/20 cut, fixed 7/6. Opens (`?fluentcrm=1&
  route=open`) and double-opt-in confirmations (`route=confirmation`) broke the
  same day, found 8/19 (#135): opens read 4% instead of ~55% for two months and
  every confirmation dead-ended. The `$arg_fluentcrm` escape covers all Fluent
  root-routes now. CHECK THE ESCAPE LIST whenever mail links change shape.
- Opens ≈ clicks exactly, click-to-open 100% ⇒ the pixel is dead, not the
  audience (clicks are separate plumbing). SES healthy + clicks normal ⇒ never
  a deliverability problem.
- Open stats for 6/22–8/17 are permanently understated; nothing to backfill.

## Issue history
#135 (pixel + confirmations, closed 8/19) · #136 (signup wall, closed 8/19).
