<?php
/**
 * Plugin Name: LG Dev2 Power (admin-only EC2 toggle)
 * Description: Admin-only start/stop/status for the dev2 EC2 box. Resolves dev2 by
 *   its stable Elastic IP (rebuild-proof — survives instance replacement, and the
 *   EIP stays associated while the box is stopped) and runs ec2 start/stop via the
 *   host's AWS CLI, authorized by the live box's IAM instance role (tag-scoped to
 *   dev2*). Serve from the always-on box (live). REST:
 *   /wp-json/looth/v1/dev2-power  (manage_options only, wp_rest nonce).
 *   Repo-served: symlink into wp-content/mu-plugins/ from the serve clone
 *   (deploy = git pull).
 */

if (!defined('ABSPATH')) exit;

const LG_DEV2_EIP    = '34.193.244.53';
const LG_DEV2_REGION = 'us-east-1';   // all Looth boxes live in us-east-1
const LG_DEV2_TIMEOUT = 20;           // seconds; never let the admin page hang on aws

/** Locate a usable aws CLI on the host. */
function lg_dev2_aws_bin(): ?string {
	foreach (['/usr/local/bin/aws', '/snap/bin/aws', '/usr/bin/aws'] as $c) {
		if (@is_executable($c)) return $c;
	}
	return null;
}

/** Run an aws CLI subcommand; returns [stdout, errOrNull]. */
function lg_dev2_aws(string $args): array {
	if (!function_exists('exec')) return ['', 'exec() disabled on host'];
	$bin = lg_dev2_aws_bin();
	if (!$bin) return ['', 'aws CLI not found on host'];
	// Credentials are supplied by the environment via the AWS CLI's standard
	// discovery chain — a shared-credentials file in the FPM pool user's ~/.aws
	// on this box, or an IAM instance role (IMDS) on the always-on live box. The
	// plugin stays cred-source agnostic and portable; no path logic lives here.
	$cmd = sprintf(
		'timeout %d %s --region %s %s 2>&1',
		LG_DEV2_TIMEOUT,
		escapeshellarg($bin),
		escapeshellarg(LG_DEV2_REGION),
		$args
	);
	$out = []; $rc = 1;
	exec($cmd, $out, $rc);
	$o = implode("\n", $out);
	if ($rc === 124) return [$o, 'aws timed out after ' . LG_DEV2_TIMEOUT . 's'];
	return [$o, $rc === 0 ? null : ('aws rc=' . $rc . ': ' . $o)];
}

/** Resolve dev2 (current instance + state) by its stable EIP. */
function lg_dev2_describe(): array {
	[$o, $e] = lg_dev2_aws('ec2 describe-instances --filters Name=ip-address,Values=' . LG_DEV2_EIP . ' --output json');
	if ($e) return ['error' => $e, 'state' => 'unknown'];
	$d = json_decode($o, true);
	$inst = $d['Reservations'][0]['Instances'][0] ?? null;
	if (!$inst) return ['error' => 'no instance found for EIP ' . LG_DEV2_EIP, 'state' => 'unknown'];
	$name = '';
	foreach (($inst['Tags'] ?? []) as $t) { if (($t['Key'] ?? '') === 'Name') $name = (string) ($t['Value'] ?? ''); }
	return [
		'id'    => $inst['InstanceId'] ?? '',
		'state' => $inst['State']['Name'] ?? 'unknown',
		'ip'    => $inst['PublicIpAddress'] ?? '',
		'name'  => $name,
	];
}

/** Start ('on') or stop ('off') dev2 (resolved by EIP). */
function lg_dev2_action(string $act): array {
	$d = lg_dev2_describe();
	if (isset($d['error'])) return $d;
	if (empty($d['id'])) return ['error' => 'could not resolve a dev2 instance id'];
	// Defense-in-depth: the EIP is unique, but never start/stop a box that does
	// not look like dev2 (guards against an EIP being reassigned by mistake).
	if ($d['name'] !== '' && stripos($d['name'], 'dev2') === false) {
		return ['error' => 'resolved instance "' . $d['name'] . '" is not dev2; refusing ' . $act];
	}
	$sub = $act === 'on' ? 'start-instances' : 'stop-instances';
	[$o, $e] = lg_dev2_aws('ec2 ' . $sub . ' --instance-ids ' . escapeshellarg($d['id']) . ' --output json');
	if ($e) return ['error' => $e];
	return ['ok' => true, 'id' => $d['id'], 'action' => $act];
}

add_action('rest_api_init', function () {
	$admin = function () { return current_user_can('manage_options'); };
	register_rest_route('looth/v1', '/dev2-power', [
		[
			'methods'             => 'GET',
			'permission_callback' => $admin,
			'callback'            => function () { return rest_ensure_response(lg_dev2_describe()); },
		],
		[
			'methods'             => 'POST',
			'permission_callback' => $admin,
			'callback'            => function ($req) {
				$a = (string) $req->get_param('action');
				if (!in_array($a, ['on', 'off'], true)) {
					return new WP_Error('lg_dev2_bad_action', 'action must be on|off', ['status' => 400]);
				}
				$r = lg_dev2_action($a);
				if (isset($r['error'])) return new WP_Error('lg_dev2_failed', $r['error'], ['status' => 500]);
				return rest_ensure_response($r);
			},
		],
	]);
});

add_action('admin_menu', function () {
	add_management_page('Dev2 Power', 'Dev2 Power', 'manage_options', 'lg-dev2-power', 'lg_dev2_power_page');
});

function lg_dev2_power_page(): void {
	if (!current_user_can('manage_options')) wp_die('Forbidden');
	$root  = esc_url_raw(rest_url('looth/v1/dev2-power'));
	$nonce = wp_create_nonce('wp_rest');
	?>
	<div class="wrap">
		<h1>Dev2 Power</h1>
		<p>Status: <strong id="d2state">&hellip;</strong> <span id="d2meta" style="color:#666"></span></p>
		<p>
			<button class="button button-primary" id="d2on">Start dev2</button>
			<button class="button" id="d2off">Stop dev2</button>
			<button class="button" id="d2refresh">Refresh</button>
		</p>
		<pre id="d2log" style="background:#1d2327;color:#7ad07a;padding:10px;max-height:220px;overflow:auto;border-radius:4px"></pre>
	</div>
	<script>
	(function () {
		var ROOT = <?php echo wp_json_encode($root); ?>, N = <?php echo wp_json_encode($nonce); ?>;
		var stateEl = document.getElementById('d2state'),
		    metaEl  = document.getElementById('d2meta'),
		    logEl   = document.getElementById('d2log');
		function log(m) { logEl.textContent += m + "\n"; logEl.scrollTop = logEl.scrollHeight; }
		function setButtons(disabled) {
			['d2on', 'd2off', 'd2refresh'].forEach(function (id) { document.getElementById(id).disabled = disabled; });
		}
		function refresh() {
			fetch(ROOT, { headers: { 'X-WP-Nonce': N }, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (d) {
					stateEl.textContent = d.state || d.error || '?';
					metaEl.textContent  = d.id ? (' — ' + (d.name || '') + ' · ' + d.id + ' · ' + (d.ip || 'no-ip')) : '';
					if (d.error) log('status: ' + d.error);
				})
				.catch(function (e) { stateEl.textContent = 'error'; log('status error: ' + e); });
		}
		function act(a) {
			log(a + '…'); setButtons(true);
			fetch(ROOT, {
				method: 'POST',
				headers: { 'X-WP-Nonce': N, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify({ action: a })
			})
				.then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
				.then(function (res) {
					log(JSON.stringify(res.d));
					setButtons(false);
					setTimeout(refresh, 2500);
				})
				.catch(function (e) { log('error: ' + e); setButtons(false); });
		}
		document.getElementById('d2on').onclick = function () { act('on'); };
		document.getElementById('d2off').onclick = function () { if (confirm('Stop dev2?')) act('off'); };
		document.getElementById('d2refresh').onclick = refresh;
		refresh();
	})();
	</script>
	<?php
}
