<?php
/**
 * welcome-activation — THE TRACKED CONFIG for the first-activation welcome.
 *
 * ── WHAT IT SWITCHES ─────────────────────────────────────────────────────────
 * The one-shot welcome (modal + email) currently fires only when Arbiter::sync
 * observes a TRANSITION into a paid tier. That is a question about ROLE ORDERING,
 * not about the member, and the two production account-creation paths sit on
 * opposite sides of it:
 *
 *   lg-patreon-onboard.php:1615  creates the account WITH the paid role, so
 *                                $oldTier already equals the winner and the
 *                                welcome NEVER fires for that rail
 *   UserLifecycle.php:231        creates with NO role, so the arbiter applies the
 *                                tier itself, sees null → looth3, and it FIRES
 *
 * So today two members who paid the same money get a different product depending
 * on the rail they joined through. That is the dual-rail violation this fixes.
 *
 *   OFF  the arbiter behaves EXACTLY as it does today: the welcome fires on
 *        isUpgradeToPaid and nothing else, and NOTHING new is written — no
 *        marker, no meta, no mail. A true byte-identical no-op, which is why the
 *        marker write below also sits inside the enabled check.
 *   ON   the welcome additionally fires on FIRST ACTIVATION — the member holds a
 *        paid tier and has never been marked activated — regardless of rail.
 *
 * ── THE CUTOVER FENCE, AND WHY IT IS NOT THE BACKFILL ────────────────────────
 * Measured on live 2026-08-15: 1,225 members hold a paid tier and 1,109 of them
 * carry NO welcome marker of any kind. A fix keyed on "paid and never welcomed"
 * would therefore mail eleven hundred people on the first cron sweep, and a mass
 * mail is unrecallable.
 *
 * The obvious guard is a backfill. A backfill is a thing somebody has to REMEMBER
 * to run, so it is not a guard — it is a plan. The fence is the guard: first
 * activation may only welcome an account registered AT OR AFTER the cutover
 * below. Every one of those 1,225 registered before any cutover we would set, so
 * arming the flag cannot mail a single existing member EVEN IF THE BACKFILL IS
 * NEVER RUN. The backfill then becomes a deliberate, reviewable step that makes
 * the state explicit rather than the only thing preventing a disaster.
 *
 * ⚠️ THE FENCE APPLIES TO FIRST ACTIVATION ONLY, NEVER TO A GENUINE UPGRADE.
 * An existing member moving looth2 → looth3 gets the welcome today, and fencing
 * that path would REMOVE a working behaviour in the name of fixing a broken one.
 * The upgrade condition below is left exactly as it is.
 *
 * ⚠️ THE EMAIL LEG IS SUPPRESSED ON LIVE TODAY — VERIFIED 2026-08-16, NOT FIXED
 * HERE. Turning this flag on makes the welcome FIRE for both rails; it does not
 * make the welcome EMAIL arrive. Two independent filters suppress any wp_mail
 * whose call stack runs through /lg-patreon-stripe-poller/, and both are armed on
 * live right now:
 *
 *   platform/mu-plugins/lg-poller-mail-killswitch.php  (symlinked into live's
 *     mu-plugins on 2026-06-30; its docblock says dev-only and DO NOT DEPLOY TO
 *     PROD, and it is on prod)
 *   LGMS\Plugin::gateOutboundMail  (inside the poller itself, so always present)
 *
 * Both self-disable only when the option lgms_poller_mail_enabled is truthy, and
 * that option DOES NOT EXIST in live's wp_options (verified against the real live
 * DB — siteurl loothgroup.com, 3,545 option rows, zero rows matching '%poller%').
 * Both allow-list mail carrying an X-LG-Poller-Intent header; WelcomeMailer sends
 * only Content-Type and From, so it gets no pass. The MODAL leg is unaffected —
 * it is user meta plus wp_footer and involves no mail at all.
 *
 * WHY THE FENCE STILL MATTERS EXACTLY AS MUCH: the suppressors are a runtime
 * option that exists to be switched on. The day it is, the fence is the only
 * thing standing between a flag flip and 1,109 emails. Nothing here relaxes on
 * the strength of a suppressor somebody else controls.
 *
 * ── THE MARKER CARRIES ITS OWN PROVENANCE ───────────────────────────────────
 * _lg_membership_activated_at is written with a prefix that says where it came
 * from, because one write serves two very different members:
 *
 *   <ISO8601>              a first activation OBSERVED LIVE — a true date
 *   pre-cutover:<ISO8601>  an existing member swept past the fence; the date is
 *                          when we NOTICED, not when they activated
 *   backfill:<ISO8601>     stamped by tools/welcome-activation-backfill.php
 *
 * Without the prefix the field would read "activated today" for all 1,225
 * existing paid members the first time a sweep touched them, and which members
 * it lied about would depend on whether the backfill had been run first. All
 * three values are non-empty, so the first-activation test is unchanged.
 *
 * ── WHY A TRACKED FILE AND NOT AN ENV VAR ────────────────────────────────────
 * The arbiter runs inside cron sweeps, and lg-wp-cron.service carries no
 * Environment= at all, so an FPM pool variable would arm a flag that then no-ops
 * forever in the one context that matters most. Read through __DIR__, which
 * resolves through the mu-plugin symlink into the serving checkout — verified,
 * not assumed.
 */

return array(

	/**
	 * OFF until the fix has been verified on the dev2 serve and Ian has said go.
	 * This one sends MAIL, and mail cannot be recalled, so it merges off.
	 */
	'enabled' => false,

	/**
	 * FIRST ACTIVATION is only welcomed for accounts registered at or after this
	 * date (UTC, Y-m-d). Everyone who already pays registered before it, which is
	 * what makes arming the flag safe on its own.
	 *
	 * An empty or unparseable value FAILS CLOSED — no first-activation welcome at
	 * all — because the failure mode of a broken fence is a mass mail.
	 */
	'cutover' => '2026-08-16',
);
