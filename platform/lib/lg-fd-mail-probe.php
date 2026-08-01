<?php
/**
 * lg-fd-mail-probe.php — ask the mail chain a BEHAVIOURAL question:
 *
 *     "if I send right now, will anything swallow it?"
 *
 * ── WHY THIS EXISTS ──────────────────────────────────────────────────────────
 * The live one-shot used to refuse whenever `has_filter('pre_wp_mail')` was true.
 * That is a PRESENCE test standing in for a BEHAVIOUR question, and on live it is
 * always true and always will be: lg-poller-mail-killswitch.php registers on every
 * box where `lgms_poller_mail_enabled` is unset (which is live's state), so the
 * one-shot could never send there — a guard that has become a wall.
 *
 * And the killswitch is SELECTIVE. Read it: it returns `$short` UNCHANGED unless the
 * call stack runs through /lg-patreon-stripe-poller/, and it exempts anything carrying
 * an X-LG-Poller-Intent header. A follow-digest send matches neither, so it passes
 * through untouched. Refusing on its mere presence confuses "a filter exists" with
 * "my mail dies", and those are different facts.
 *
 * ⚠️ THE OPPOSITE MISTAKE IS THE DANGEROUS ONE, so this does not simply relax the
 * check — it replaces it with a stronger one. lg-dev-mail-containment.php ALSO hooks
 * pre_wp_mail, at priority 1, and returns `true`: the mail is delivered to mailpit and
 * wp_mail() reports SUCCESS. Any guard that stopped refusing on presence and put
 * nothing behind it would hand back a confident "sent" for a message that reached
 * nobody. That is the single failure mode this lane exists to prevent.
 *
 * ── HOW IT ANSWERS THE QUESTION ──────────────────────────────────────────────
 * WordPress runs the filter as:
 *
 *     $pre = apply_filters( 'pre_wp_mail', null, $atts );
 *     if ( null !== $pre ) { return $pre; }          // ← swallowed
 *
 * apply_filters() runs EVERY callback regardless of what earlier ones returned, and
 * each receives the running value. So a callback registered LAST — at PHP_INT_MAX —
 * sees exactly what WordPress is about to see. If the value reaching it is still null,
 * nothing short-circuited and the message goes on to real delivery. If it is anything
 * else, something upstream already swallowed it.
 *
 * That is a direct observation of the actual chain on the actual box, not an inference
 * from which plugins happen to be loaded. It answers correctly for the killswitch
 * (passes through), for containment (swallows), and for any future filter nobody has
 * written yet — which is the point, since the guard has to outlive its authors.
 *
 * ⚠️ IT COSTS ONE REAL EMAIL, DELIBERATELY. A dry run of the chain would have to invoke
 * the filters itself, and containment's callback DELIVERS as a side effect — so a
 * "dry" probe is not dry, and it still would not prove that anything leaves the box.
 * A real wp_mail() with a unique token is both the honest test and, on live, the first
 * genuine end-to-end delivery receipt: if the probe lands in the inbox, the channel is
 * proven before the digest is built.
 *
 * ── ⚠️ THE PROBE IS BEHIND THE ALLOWLIST, AND THAT IS NOT OPTIONAL ───────────
 * This file contains a wp_mail() call. That makes it exactly the "future code path"
 * the sender's gate warns about — a second way to put a message on the wire that does
 * not pass the wall. So it enforces lg_fd_allowed() itself, before it composes
 * anything, and refuses for any address that is not on the list. A probe that could
 * mail a stranger would be a hole cut in the wall by the tool built to inspect it.
 * tools/gates/follow-digest-gate.py asserts this, on this file, by name.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) { return; }

if ( ! function_exists( 'lg_fd_mail_probe_filters' ) ) {
	/**
	 * Every pre_wp_mail callback currently registered, with the file it came from.
	 *
	 * Diagnostics only — nothing branches on it. It exists so a refusal can NAME the
	 * plugin responsible instead of saying "something is intercepting mail", which is
	 * what sent the last run looking in the wrong place.
	 *
	 * @return array<int,array{priority:int,file:string,fn:string}>
	 */
	function lg_fd_mail_probe_filters(): array {
		$out = array();
		$reg = $GLOBALS['wp_filter']['pre_wp_mail'] ?? null;
		if ( ! $reg || ! isset( $reg->callbacks ) ) { return $out; }
		foreach ( (array) $reg->callbacks as $prio => $cbs ) {
			foreach ( (array) $cbs as $cb ) {
				$fn   = $cb['function'] ?? null;
				$file = '?';
				$name = '(closure)';
				try {
					if ( $fn instanceof Closure ) {
						$r    = new ReflectionFunction( $fn );
						$file = (string) $r->getFileName();
					} elseif ( is_string( $fn ) ) {
						$name = $fn;
						$r    = new ReflectionFunction( $fn );
						$file = (string) $r->getFileName();
					} elseif ( is_array( $fn ) && 2 === count( $fn ) ) {
						$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
						$r    = new ReflectionMethod( $fn[0], (string) $fn[1] );
						$file = (string) $r->getFileName();
					}
				} catch ( Throwable $e ) {           // phpcs:ignore
					$file = '?';
				}
				$out[] = array( 'priority' => (int) $prio, 'file' => $file, 'fn' => $name );
			}
		}
		usort( $out, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
		return $out;
	}
}

if ( ! function_exists( 'lg_fd_mail_probe' ) ) {
	/**
	 * Send one tokenised probe and report whether the chain swallowed it.
	 *
	 * FAILS CLOSED IN EVERY DIRECTION. Any outcome that is not an unambiguous
	 * "nothing intercepted this" reports swallowed=true, including the cases where the
	 * probe could not run at all. A caller must be able to treat `false === $r['clear']`
	 * as "do not send" without reading the reason.
	 *
	 * @param int    $uid   the member this probe is on behalf of — checked against the allowlist.
	 * @param string $email where the probe goes. MUST be allowlisted for $uid.
	 * @return array{clear:bool,ran:bool,swallowed:bool,token:string,reason:string,
	 *               wp_mail_returned:mixed,filters:array}
	 */
	function lg_fd_mail_probe( int $uid, string $email ): array {
		$r = array(
			'clear'            => false,       // ← the only field a caller needs to obey
			'ran'              => false,
			'swallowed'        => true,
			'token'            => '',
			'reason'           => '',
			'wp_mail_returned' => null,
			'filters'          => lg_fd_mail_probe_filters(),
		);

		/* ── THE WALL, BEFORE ANYTHING IS COMPOSED ────────────────────────────
		 * This function owns a wp_mail() call, so it is a way to mail somebody. It
		 * must therefore pass the same allowlist the digest passes, or it becomes the
		 * route around it. Missing allowlist machinery is itself a refusal: it means
		 * this is running against a build without the wall. */
		if ( ! function_exists( 'lg_fd_allowed' ) ) {
			$r['reason'] = 'lg_fd_allowed() is not loaded — this build has no allowlist, so the '
				. 'probe refuses rather than becoming an unguarded way to send mail';
			return $r;
		}
		if ( $uid <= 0 || ! is_email( $email ) ) {
			$r['reason'] = 'refusing to probe a malformed recipient';
			return $r;
		}
		if ( ! lg_fd_allowed( $uid, $email ) ) {
			$r['reason'] = sprintf( 'refusing: uid %d <%s> is NOT on the allowlist. The probe is '
				. 'behind the same wall as the digest, deliberately.', $uid, $email );
			return $r;
		}

		try {
			$token = 'fdprobe-' . bin2hex( random_bytes( 8 ) );
		} catch ( Throwable $e ) {               // phpcs:ignore
			$r['reason'] = 'could not generate a probe token: ' . $e->getMessage();
			return $r;
		}
		$r['token'] = $token;

		/* The recorder. Registered LAST so it observes the value WordPress itself is
		 * about to test. It returns $short untouched — a probe that altered the chain
		 * would be measuring itself. */
		$seen = new stdClass();
		$seen->ran      = false;
		$seen->incoming = null;
		$rec = static function ( $short, $atts ) use ( $token, $seen ) {
			$h    = is_array( $atts ) ? ( $atts['headers'] ?? '' ) : '';
			$flat = is_array( $h ) ? implode( "\n", $h ) : (string) $h;
			$subj = is_array( $atts ) ? (string) ( $atts['subject'] ?? '' ) : '';
			if ( false !== stripos( $flat, $token ) || false !== stripos( $subj, $token ) ) {
				$seen->ran      = true;
				$seen->incoming = $short;
			}
			return $short;                        // never alter the chain
		};
		add_filter( 'pre_wp_mail', $rec, PHP_INT_MAX, 2 );

		$subject = sprintf( '[follow-digest probe %s] channel check — no action needed', $token );
		$body    = "This is an automated one-line probe sent by follow-digest-live-oneshot.php\n"
			. "before it would send a real digest. It exists to prove the mail chain is not\n"
			. "silently swallowing messages.\n\n"
			. "If you are reading this in your inbox, delivery works.\n\n"
			. "Token: $token\n";

		$ret = null;
		try {
			$ret = wp_mail( $email, $subject, $body, array( 'X-LG-FD-Probe: ' . $token ) );
		} catch ( Throwable $e ) {               // phpcs:ignore
			remove_filter( 'pre_wp_mail', $rec, PHP_INT_MAX );
			$r['reason'] = 'wp_mail() threw during the probe: ' . $e->getMessage();
			return $r;
		}
		remove_filter( 'pre_wp_mail', $rec, PHP_INT_MAX );

		$r['wp_mail_returned'] = $ret;
		$r['ran']              = (bool) $seen->ran;

		/* ⚠️ THE RECORDER NOT RUNNING IS A REFUSAL, NOT A PASS. If it never fired, the
		 * probe never reached the filter chain and we know NOTHING about what would
		 * happen to the real send — which is exactly the state in which a guard must
		 * say no. */
		if ( ! $seen->ran ) {
			$r['reason'] = 'the probe never reached pre_wp_mail, so nothing was observed. '
				. 'Refusing on the grounds that an unanswered question is not a yes.';
			return $r;
		}

		if ( null !== $seen->incoming ) {
			$r['swallowed'] = true;
			$r['reason']    = sprintf(
				'SWALLOWED: a pre_wp_mail filter short-circuited the probe (it handed WordPress %s '
				. 'instead of null), so wp_mail() reports success for a message that reached no '
				. 'inbox. This is what containment does.',
				var_export( $seen->incoming, true ) );
			return $r;
		}

		$r['swallowed'] = false;
		$r['clear']     = true;
		$r['reason']    = 'nothing short-circuited the probe — it passed the whole filter chain '
			. 'and went on to real delivery';
		return $r;
	}
}
