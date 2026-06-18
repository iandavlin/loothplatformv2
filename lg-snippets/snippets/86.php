<?php
/* code-snippets #86 — "Wordpress Login Branding Etc" — folded verbatim */

/**
 * Looth Group: WP Login Enhancements - THREE-CARD ACCORDION LAYOUT
 *
 * Drop into functions.php or a small mu-plugin.
 * Renders below the native WP login form on wp-login.php:
 *   - Card 1: I've logged in to loothgroup.com before → Manage Password
 *   - Card 2: Patreon member, never logged in → Connect Your Patreon
 *   - Card 3: Not a Patreon member yet → Join on Patreon
 *
 * Each card has animated <details> accordions for instructions.
 * Card 2 has an additional coral "⚠ exact Patreon email" warning accordion.
 *
 * Palette:
 *   Gold  #ECB351   Sand   #F1DE83
 *   Mint1 #D4E0B8   Mint2  #C2D5AA   Mint3 #A8BE8B
 *   Mint4 #97A97C   Mint5  #87986A   Coral #FE6A4F
 */

/* ==========================================================================
 * [01] CONFIG
 * ========================================================================== */
function lg_login_get_logo_url() {
	return 'https://dev.loothgroup.com/wp-content/uploads/2024/05/cropped-cropped-TLG-LOGO-300.png';
}

function lg_login_get_patreon_connect_shortcode() {
	// Your lg-patreon-onboard plugin shortcode.
	// If Ted has named it differently, swap the string here.
	return '[lg_patreon_onboard]';
}

function lg_login_get_patreon_join_url() {
	return 'https://www.patreon.com/cw/theloothgroup/membership';
}

function lg_login_get_support_email() {
	return 'ian.davlin@gmail.com';
}

/* ==========================================================================
 * [02] LOGIN LOGO LINK + HOVER TEXT
 * ========================================================================== */
add_filter('login_headerurl', function () {
	return home_url('/');
});

add_filter('login_headertext', function () {
	return get_bloginfo('name');
});

/* ==========================================================================
 * [03] STYLES (form + cards + accordions)
 * ========================================================================== */
add_action('login_enqueue_scripts', function () {

	$logo_url = lg_login_get_logo_url();

	$css = "
	/* ===== COLOR PALETTE ===== */
	:root {
		--lg-gold:   #ECB351;
		--lg-sand:   #F1DE83;
		--lg-mint1:  #D4E0B8;
		--lg-mint2:  #C2D5AA;
		--lg-mint3:  #A8BE8B;
		--lg-mint4:  #97A97C;
		--lg-mint5:  #87986A;
		--lg-coral:  #FE6A4F;
	}

	/* ===== BODY & PAGE LAYOUT ===== */
	body.login {
		background: linear-gradient(135deg, var(--lg-sand) 0%, var(--lg-mint1) 50%, var(--lg-mint2) 100%);
		font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
		min-height: 100vh;
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: flex-start;
		padding: 40px 20px 120px;
		box-sizing: border-box;
	}

	body.login #login {
		width: 100%;
		max-width: 480px;
		padding: 0;
		margin: 0 auto;
		position: relative;
	}

	.lg-login-stack {
		width: 100%;
		max-width: 480px;
		margin: 18px auto 0;
		display: flex;
		flex-direction: column;
		gap: 16px;
	}

	/* ===== LOGO ===== */
	#login h1 a, .login h1 a {
		background-image: url('{$logo_url}');
		background-size: contain;
		background-repeat: no-repeat;
		background-position: center;
		width: 100%;
		height: 100px;
		margin: 0 auto 22px;
		padding: 0;
		filter: drop-shadow(0 4px 10px rgba(0,0,0,0.15));
	}

	/* ===== LOGIN FORM ===== */
	.login form {
		background: #ffffff;
		border: none;
		border-radius: 16px;
		box-shadow: 0 10px 40px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.08);
		padding: 36px;
		margin-bottom: 0;
	}

	.login form .input,
	.login input[type='text'],
	.login input[type='password'] {
		background: #f8f9fa;
		border: 2px solid var(--lg-mint2);
		border-radius: 10px;
		padding: 14px 16px;
		font-size: 16px;
		transition: all 0.3s ease;
		box-sizing: border-box;
	}

	.login form .input:focus,
	.login input[type='text']:focus,
	.login input[type='password']:focus {
		background: #ffffff;
		border-color: var(--lg-mint4);
		box-shadow: 0 0 0 4px rgba(168, 190, 139, 0.15);
		outline: none;
	}

	.login label { font-size: 15px; font-weight: 600; color: #2c3e50; }

	.wp-core-ui .button-primary {
		background: linear-gradient(135deg, var(--lg-mint4) 0%, var(--lg-mint5) 100%);
		border: none;
		border-radius: 10px;
		color: #ffffff;
		font-size: 17px;
		font-weight: 700;
		padding: 14px 24px;
		height: auto;
		text-shadow: 0 1px 2px rgba(0,0,0,0.15);
		box-shadow: 0 4px 12px rgba(135, 152, 106, 0.35);
		transition: all 0.3s ease;
	}
	.wp-core-ui .button-primary:hover,
	.wp-core-ui .button-primary:focus {
		background: linear-gradient(135deg, var(--lg-mint5) 0%, var(--lg-mint4) 100%);
		box-shadow: 0 6px 20px rgba(135, 152, 106, 0.45);
		transform: translateY(-2px);
	}
	.wp-core-ui .button-primary:active { transform: translateY(0); }

	.login .forgetmenot { font-size: 15px; }
	.login input[type='checkbox'] {
		width: 18px; height: 18px;
		border: 2px solid var(--lg-mint3);
		border-radius: 4px;
	}

	#nav { display: none !important; }

	.login #backtoblog { text-align: center; margin-top: 16px; }
	.login #backtoblog a { color: var(--lg-mint5); font-weight: 600; text-decoration: none; }
	.login #backtoblog a:hover { color: var(--lg-mint4); }

	/* ===== PATH CARDS ===== */
	.lg-card {
		background: #ffffff;
		border: 2px solid rgba(135,152,106,0.35);
		border-radius: 16px;
		padding: 22px 24px;
		box-shadow: 0 6px 20px rgba(0,0,0,0.08);
	}
	.lg-card--gold  { border-color: rgba(236,179,81,0.45); }
	.lg-card--coral { border-color: rgba(254,106,79,0.45); }

	.lg-card-head {
		display: flex; align-items: center; gap: 12px; margin-bottom: 12px;
	}
	.lg-numcircle {
		width: 34px; height: 34px; border-radius: 50%;
		color: #fff;
		display: flex; align-items: center; justify-content: center;
		font-weight: 800; font-size: 1em;
		flex-shrink: 0;
	}
	.lg-numcircle--mint  { background: linear-gradient(135deg, var(--lg-mint4) 0%, var(--lg-mint5) 100%); }
	.lg-numcircle--gold  { background: linear-gradient(135deg, var(--lg-gold) 0%, var(--lg-sand) 100%); color: #2c3e50; }
	.lg-numcircle--coral { background: linear-gradient(135deg, var(--lg-gold) 0%, var(--lg-coral) 100%); }
	.lg-card-head h2 {
		font-size: 1.08em; font-weight: 800; color: #2c3e50; margin: 0; line-height: 1.3;
	}

	/* ===== BUTTONS INSIDE CARDS ===== */
	.lg-btn-wrap { text-align: center; margin-top: 4px; }
	.lg-btn {
		display: inline-block;
		width: 100%;
		padding: 13px 24px;
		border: none;
		border-radius: 10px;
		font-size: 16px;
		font-weight: 800;
		text-align: center;
		text-decoration: none;
		transition: all 0.25s ease;
		box-sizing: border-box;
		cursor: pointer;
	}
	.lg-btn--mint {
		background: linear-gradient(135deg, var(--lg-mint4) 0%, var(--lg-mint5) 100%);
		color: #fff;
		text-shadow: 0 1px 2px rgba(0,0,0,0.18);
		box-shadow: 0 4px 12px rgba(135,152,106,0.35);
	}
	.lg-btn--gold {
		background: linear-gradient(135deg, var(--lg-gold) 0%, var(--lg-sand) 100%);
		color: #2c3e50;
		text-shadow: 0 1px 2px rgba(255,255,255,0.25);
		box-shadow: 0 4px 12px rgba(236,179,81,0.35);
	}
	.lg-btn--coral {
		background: linear-gradient(135deg, var(--lg-gold) 0%, var(--lg-coral) 100%);
		color: #fff;
		text-shadow: 0 1px 2px rgba(0,0,0,0.20);
		box-shadow: 0 4px 12px rgba(254,106,79,0.35);
	}
	.lg-btn:hover { transform: translateY(-2px); }
	.lg-btn--mint:hover, .lg-btn--coral:hover { color: #fff; }

	/* ===== ACCORDION ===== */
	.lg-acc {
		margin: 0 0 14px;
		border: 1.5px solid rgba(135,152,106,0.40);
		border-radius: 10px;
		background: #fbfcf8;
		overflow: hidden;
		box-shadow: 0 1px 2px rgba(0,0,0,0.04);
		transition: all 0.25s ease;
	}
	.lg-acc:hover {
		border-color: rgba(135,152,106,0.70);
		box-shadow: 0 3px 8px rgba(0,0,0,0.08);
	}
	.lg-acc[open] {
		background: #ffffff;
		border-color: var(--lg-mint5);
		box-shadow: 0 3px 10px rgba(135,152,106,0.18);
	}

	.lg-acc summary {
		list-style: none;
		cursor: pointer;
		padding: 12px 14px 12px 16px;
		font-size: 0.92em;
		font-weight: 700;
		color: #2c3e50;
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 10px;
		user-select: none;
		transition: background 0.2s ease;
	}
	.lg-acc summary::-webkit-details-marker { display: none; }
	.lg-acc summary::marker { display: none; content: ''; }
	.lg-acc summary:hover { background: rgba(212,224,184,0.30); }
	.lg-acc summary:active { background: rgba(212,224,184,0.50); }

	.lg-acc-label { flex: 1; }

	.lg-acc-hint {
		font-size: 0.70em;
		font-weight: 800;
		letter-spacing: 0.08em;
		text-transform: uppercase;
		color: var(--lg-mint5);
		background: rgba(135,152,106,0.12);
		border: 1px solid rgba(135,152,106,0.30);
		border-radius: 12px;
		padding: 3px 9px;
		flex-shrink: 0;
		transition: all 0.25s ease;
		white-space: nowrap;
	}
	.lg-acc[open] .lg-acc-hint {
		color: #fff;
		background: var(--lg-mint5);
		border-color: var(--lg-mint5);
	}
	.lg-acc-hint::before { content: 'Click to expand'; }
	.lg-acc[open] .lg-acc-hint::before { content: 'Expanded'; }

	.lg-acc summary::after {
		content: '';
		width: 10px; height: 10px;
		border-right: 2.5px solid var(--lg-mint5);
		border-bottom: 2.5px solid var(--lg-mint5);
		transform: rotate(45deg);
		transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
		flex-shrink: 0;
		margin-right: 4px; margin-bottom: 4px;
	}
	.lg-acc[open] summary::after { transform: rotate(-135deg); margin-bottom: -2px; margin-top: 4px; }

	/* Height animation via grid-template-rows 0fr → 1fr */
	.lg-acc-wrap {
		display: grid;
		grid-template-rows: 0fr;
		transition: grid-template-rows 0.35s cubic-bezier(0.4, 0, 0.2, 1);
	}
	.lg-acc[open] .lg-acc-wrap { grid-template-rows: 1fr; }
	.lg-acc-inner { min-height: 0; overflow: hidden; }

	.lg-acc-body {
		padding: 4px 16px 14px;
		font-size: 0.90em;
		color: #444;
		line-height: 1.6;
		border-top: 1px dashed rgba(135,152,106,0.30);
		padding-top: 12px;
		opacity: 0;
		transform: translateY(-4px);
		transition: opacity 0.25s ease 0.1s, transform 0.25s ease 0.1s;
	}
	.lg-acc[open] .lg-acc-body { opacity: 1; transform: translateY(0); }
	.lg-acc-body p { margin: 0 0 10px; }
	.lg-acc-body p:last-child { margin-bottom: 0; }
	.lg-acc-body a { color: var(--lg-mint5); font-weight: 700; }
	.lg-acc-body strong { color: #2c3e50; }

	/* Color variants */
	.lg-acc--gold { border-color: rgba(236,179,81,0.50); background: #fefcf5; }
	.lg-acc--gold:hover { border-color: rgba(236,179,81,0.80); }
	.lg-acc--gold[open] { border-color: var(--lg-gold); box-shadow: 0 3px 10px rgba(236,179,81,0.20); }
	.lg-acc--gold .lg-acc-hint { color: #b8892a; background: rgba(236,179,81,0.15); border-color: rgba(236,179,81,0.40); }
	.lg-acc--gold[open] .lg-acc-hint { background: var(--lg-gold); border-color: var(--lg-gold); color: #fff; }
	.lg-acc--gold summary::after { border-color: #b8892a; }

	.lg-acc--coral { border-color: rgba(254,106,79,0.50); background: #fff8f6; }
	.lg-acc--coral:hover { border-color: rgba(254,106,79,0.80); }
	.lg-acc--coral[open] { border-color: var(--lg-coral); box-shadow: 0 3px 10px rgba(254,106,79,0.20); }
	.lg-acc--coral summary { color: #c04a33; }
	.lg-acc--coral .lg-acc-hint { color: #fff; background: var(--lg-coral); border-color: var(--lg-coral); }
	.lg-acc--coral .lg-acc-hint::before { content: 'Important'; }
	.lg-acc--coral[open] .lg-acc-hint::before { content: 'Read this'; }
	.lg-acc--coral summary::after { border-color: #c04a33; }

	/* ===== ANNOUNCEMENT ABOVE FORM ===== */
	.lg-login-announce {
		border-radius: 16px;
		padding: 14px 16px;
		margin: 0 0 18px;
		border: 2px solid rgba(135, 152, 106, 0.30);
		box-shadow: 0 6px 20px rgba(0,0,0,0.07);
		background: linear-gradient(
			135deg,
			rgba(241, 222, 131, 0.35) 0%,
			rgba(212, 224, 184, 0.50) 45%,
			rgba(194, 213, 170, 0.60) 100%
		);
		backdrop-filter: blur(6px);
	}
	.lg-login-announce__title {
		text-align: center; margin: 0 0 4px;
		font-size: 14px; font-weight: 900;
		letter-spacing: 0.6px; text-transform: uppercase;
		color: #2c3e50;
	}
	.lg-login-announce__body {
		text-align: center; margin: 0;
		font-size: 13.5px; line-height: 1.55;
		color: #2c3e50; font-weight: 600;
	}

	/* ===== RESPONSIVE ===== */
	@media (max-width: 580px) {
		body.login { padding: 30px 15px 100px; }
		body.login #login, .lg-login-stack { max-width: 100%; }
		.login form { padding: 30px 22px; }
		.lg-card-head h2 { font-size: 1em; }
		.lg-acc-hint { display: none; }
	}

	/* Respect reduced-motion */
	@media (prefers-reduced-motion: reduce) {
		.lg-acc, .lg-acc-wrap, .lg-acc-body, .lg-acc-hint,
		.lg-acc summary::after { transition: none !important; }
	}
	";

	wp_register_style('lg-login-enhancements', false);
	wp_enqueue_style('lg-login-enhancements');
	wp_add_inline_style('lg-login-enhancements', $css);
});

/* ==========================================================================
 * [04] ANNOUNCEMENT BANNER ABOVE LOGIN FORM
 * ========================================================================== */
add_filter('login_message', function ($message) {

	$action     = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
	$checkemail = isset($_REQUEST['checkemail']) ? sanitize_key((string) $_REQUEST['checkemail']) : '';

	$is_password_reset_flow = (
		in_array($action, ['lostpassword', 'retrievepassword', 'rp', 'resetpass'], true)
		|| $checkemail === 'confirm'
	);

	if ($is_password_reset_flow) {
		return $message;
	}

	$message .= '
		<div class="lg-login-announce" role="note" aria-label="Login announcement">
			<div class="lg-login-announce__title">Log in with Email and Password</div>
			<p class="lg-login-announce__body">New here or stuck? Pick the card that matches you below.</p>
		</div>
	';

	return $message;
});

/* ==========================================================================
 * [05] THREE-CARD STACK BELOW LOGIN FORM
 * ========================================================================== */
add_action('login_footer', function () {

	$manage_password_url = wp_lostpassword_url();
	$login_url           = wp_login_url();
	$support_email       = lg_login_get_support_email();
	$patreon_join_url    = lg_login_get_patreon_join_url();
	$patreon_shortcode   = lg_login_get_patreon_connect_shortcode();

	$action     = isset($_REQUEST['action']) ? sanitize_key((string) $_REQUEST['action']) : '';
	$checkemail = isset($_REQUEST['checkemail']) ? sanitize_key((string) $_REQUEST['checkemail']) : '';

	$is_password_reset_flow = (
		in_array($action, ['lostpassword', 'retrievepassword', 'rp', 'resetpass'], true)
		|| $checkemail === 'confirm'
	);
	?>

	<?php if ($is_password_reset_flow) : ?>

		<!-- Password Reset Flow: Important Email Notice + Back to Login -->
		<div class="lg-login-stack">

			<div class="lg-card lg-card--gold">
				<div class="lg-card-head">
					<div class="lg-numcircle lg-numcircle--gold">!</div>
					<h2>Important</h2>
				</div>
				<p style="margin:0;font-size:0.95em;color:#444;">
					Use the same email address you use for your paid Looth Group membership on Patreon.
					Not sure which one? Email
					<a href="mailto:<?php echo esc_attr($support_email); ?>" style="color:var(--lg-mint5);font-weight:700;"><?php echo esc_html($support_email); ?></a>.
				</p>
			</div>

			<div class="lg-btn-wrap">
				<a class="lg-btn lg-btn--mint" href="<?php echo esc_url($login_url); ?>">Back to Login</a>
			</div>
		</div>

	<?php else : ?>

		<!-- Normal Login Flow: Three-Card Stack -->
		<div class="lg-login-stack">

			<!-- =========================================================
			     CARD 1 :: RETURNING USERS
			     ========================================================= -->
			<div class="lg-card">
				<div class="lg-card-head">
					<div class="lg-numcircle lg-numcircle--mint">1</div>
					<h2>I've logged in to loothgroup.com before</h2>
				</div>

				<details class="lg-acc">
					<summary>
						<span class="lg-acc-label">Instructions — set or reset your password</span>
						<span class="lg-acc-hint"></span>
					</summary>
					<div class="lg-acc-wrap"><div class="lg-acc-inner">
						<div class="lg-acc-body">
							<p>We log in with <strong>email + password</strong> now. If you've never set a password on the site, or you've forgotten the one you had, use the button below to set or reset it.</p>
							<p><strong>Use the same email</strong> that's on your paid Looth Group Patreon membership. Not sure which one? Email <a href="mailto:<?php echo esc_attr($support_email); ?>"><?php echo esc_html($support_email); ?></a>.</p>
						</div>
					</div></div>
				</details>

				<div class="lg-btn-wrap">
					<a class="lg-btn lg-btn--mint" href="<?php echo esc_url($manage_password_url); ?>">Manage Password</a>
				</div>
			</div>

			<!-- =========================================================
			     CARD 2 :: PATREON MEMBER, NO SITE ACCOUNT YET
			     ========================================================= -->
			<div class="lg-card lg-card--gold">
				<div class="lg-card-head">
					<div class="lg-numcircle lg-numcircle--gold">2</div>
					<h2>I'm a Patreon member but I've never logged into the site</h2>
				</div>

				<details class="lg-acc lg-acc--gold">
					<summary>
						<span class="lg-acc-label">Instructions — first-time setup</span>
						<span class="lg-acc-hint"></span>
					</summary>
					<div class="lg-acc-wrap"><div class="lg-acc-inner">
						<div class="lg-acc-body">
							<p>First-time setup. Click the button below, log in with Patreon, and authorize Looth Group. We'll create your site account automatically. Once you're back, come to <strong>Card 1</strong> and set your password.</p>
							<p>This is a one-time thing. After you're connected, you'll log in with <strong>email + password</strong> like a normal site — no more bouncing through Patreon every time.</p>
						</div>
					</div></div>
				</details>

				<details class="lg-acc lg-acc--coral">
					<summary>
						<span class="lg-acc-label">⚠ Use the exact email on your Patreon membership</span>
						<span class="lg-acc-hint"></span>
					</summary>
					<div class="lg-acc-wrap"><div class="lg-acc-inner">
						<div class="lg-acc-body">
							<p>Before you click, make sure you're logged into Patreon with the account that actually has your Looth Group pledge on it. If you have more than one Patreon account, log out and log back in with the right one at <a href="https://www.patreon.com" target="_blank" rel="noopener noreferrer">patreon.com</a> <em>before</em> clicking Connect.</p>
							<p>Using the wrong email creates a ghost account with no access, and we have to clean it up manually.</p>
							<p>Not sure which email is on your Looth Group pledge? Open Patreon → <strong>Settings → Account</strong>, or email <a href="mailto:<?php echo esc_attr($support_email); ?>"><?php echo esc_html($support_email); ?></a> and I'll look it up.</p>
						</div>
					</div></div>
				</details>

				<div class="lg-btn-wrap">
					<?php echo do_shortcode($patreon_shortcode); ?>
				</div>
			</div>

			<!-- =========================================================
			     CARD 3 :: NOT A PATREON MEMBER YET
			     ========================================================= -->
			<div class="lg-card lg-card--coral">
				<div class="lg-card-head">
					<div class="lg-numcircle lg-numcircle--coral">3</div>
					<h2>I'm not a Looth Group Patreon member yet</h2>
				</div>

				<details class="lg-acc">
					<summary>
						<span class="lg-acc-label">Instructions — join on Patreon first</span>
						<span class="lg-acc-hint"></span>
					</summary>
					<div class="lg-acc-wrap"><div class="lg-acc-inner">
						<div class="lg-acc-body">
							<p>Looth Group membership runs through Patreon. Pick a tier over there, then come back here and follow <strong>Card 2</strong> to connect your Patreon account.</p>
							<p>Two tiers to choose from — <strong>Looth Lite</strong> and <strong>Looth Pro</strong>. Both get you into the community forum, livestreams, and the full back catalog.</p>
						</div>
					</div></div>
				</details>

				<div class="lg-btn-wrap">
					<a class="lg-btn lg-btn--coral" href="<?php echo esc_url($patreon_join_url); ?>" target="_blank" rel="noopener noreferrer">Join on Patreon</a>
				</div>
			</div>

		</div><!-- /.lg-login-stack -->

	<?php endif; ?>

	<?php
});
