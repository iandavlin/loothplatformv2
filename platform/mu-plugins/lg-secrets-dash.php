<?php
/**
 * Plugin Name: Looth Secrets Dashboard
 * @lg-dev-only EXCLUDED from live deploy (deploy.sh marker filter). Secret-bearing dev tool — never ship to live.
 * Description: Bucket-first R2 + Patreon secrets manager. Credentials deduped (each key shown
 *              once; one edit rewrites every location). Live bucket inventory via Cloudflare API
 *              when a Read token is present. All privilege is in the root-owned lg-secrets-helper.
 * Version: 0.2.0
 */
if (!defined('ABSPATH')) exit;

const LG_SECRETS_BIN = '/usr/local/sbin/lg-secrets-helper';
const LG_SECRETS_CAP = 'manage_options';

/** Invoke the privileged helper (optionally piping a value to stdin). */
function lg_secrets_helper(array $args, ?string $stdin = null): array {
    $user = wp_get_current_user();
    $args[] = '--actor=' . ($user && $user->user_login ? $user->user_login : 'wp');
    $cmd = 'sudo -n ' . escapeshellarg(LG_SECRETS_BIN);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);
    $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $desc, $pipes);
    if (!is_resource($p)) return ['ok' => false, 'error' => 'proc_open failed'];
    if ($stdin !== null) fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    proc_close($p);
    $json = json_decode($out, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'helper error: ' . trim($err ?: $out ?: 'no output')];
    if (isset($json['error'])) return ['ok' => false, 'error' => $json['error'], 'data' => $json];
    return ['ok' => true, 'data' => $json];
}

function lg_secrets_hsize($b): string {
    if ($b === null || $b === '') return '—';
    $u = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0; $b = (float) $b;
    while ($b >= 1000 && $i < 4) { $b /= 1000; $i++; }   // decimal, to match the Cloudflare dashboard
    return round($b, ($b < 10 && $i > 0) ? 2 : 1) . ' ' . $u[$i];
}
function lg_secrets_cred_name(string $cred): string { return $cred ? ucfirst(preg_replace('/^cred-/', '', $cred)) : '—'; }

add_action('admin_menu', function () {
    add_menu_page('Looth Secrets', 'Looth Secrets', LG_SECRETS_CAP, 'lg-secrets',
        'lg_secrets_render_page', 'dashicons-shield-alt', 81);
});

function lg_secrets_render_page(): void {
    if (!current_user_can(LG_SECRETS_CAP)) wp_die('Forbidden');
    $list = lg_secrets_helper(['list']);
    $buck = lg_secrets_helper(['buckets']);
    $nonce = wp_create_nonce('lg_secrets');

    echo '<div class="wrap"><h1><span class="dashicons dashicons-shield-alt"></span> Looth Secrets</h1>';
    echo '<p class="description">R2 buckets &amp; Patreon API. Each credential is shown once — one <b>Edit</b> rewrites every place it lives. <b>Reveal</b> and edits are logged.</p>';

    if (!$list['ok']) {
        echo '<div class="notice notice-error"><p><b>Helper unavailable:</b> ' . esc_html($list['error'])
           . '<br>Provision the bridge: <code>sudo ~/loothplatformv2/platform/bin/install-secrets-bridge.sh</code></p></div></div>';
        return;
    }
    $secrets = $list['data']['secrets'];
    $by = []; foreach ($secrets as $s) $by[$s['group']][] = $s;

    // ───────── R2 BUCKETS ─────────
    echo '<h2>R2 buckets</h2>';
    $bd = $buck['ok'] ? $buck['data'] : ['available' => false, 'buckets' => [], 'error' => $buck['error'] ?? ''];
    if (!empty($bd['available'])) {
        echo '<p class="description">Live from Cloudflare API (account <code>' . esc_html($bd['account'] ?? '') . '</code>).</p>';
    } else {
        $why = !empty($bd['error']) ? ' (' . esc_html($bd['error']) . ')' : '';
        echo '<div class="notice notice-info inline" style="margin:.5em 0"><p>Showing <b>known</b> buckets — add a <b>Cloudflare API token</b> below for live names, object counts &amp; sizes' . $why . '.</p></div>';
    }
    echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
       . '<th>Bucket</th><th style="width:70px">Env</th><th style="width:90px">Objects</th><th style="width:90px">Size</th>'
       . '<th style="width:130px">Credential</th><th style="width:110px">Reachable</th></tr></thead><tbody>';
    foreach ($bd['buckets'] as $b) {
        $reach = !empty($b['verified']) ? '<span style="color:#1a7f37">✓ from dev</span>'
               : '<span class="description">' . esc_html($b['env'] ?? '') . '-scoped</span>';
        echo '<tr><td><strong>' . esc_html($b['name']) . '</strong></td>'
           . '<td>' . esc_html($b['env'] ?? '') . '</td>'
           . '<td>' . (isset($b['count']) && $b['count'] !== null ? esc_html(number_format((int) $b['count'])) : '—') . '</td>'
           . '<td>' . esc_html(lg_secrets_hsize($b['size'] ?? null)) . '</td>'
           . '<td>' . esc_html(lg_secrets_cred_name($b['cred'] ?? '')) . '</td>'
           . '<td>' . $reach . '</td></tr>';
    }
    echo '</tbody></table>';

    // ───────── sections of editable secrets ─────────
    $sections = [
        'r2'         => 'R2 credentials',
        'cloudflare' => 'Cloudflare API',
        'patreon'    => 'Patreon API (poller)',
    ];
    foreach ($sections as $g => $title) {
        if (empty($by[$g])) continue;
        echo '<h2>' . esc_html($title) . '</h2>';
        if ($g === 'cloudflare') echo '<p class="description">Account-scoped, <i>Workers R2 Storage : Read</i>. Powers the live bucket inventory above.</p>';
        echo '<table class="widefat striped" style="max-width:1100px"><thead><tr>'
           . '<th style="width:230px">Secret</th><th>Current</th><th style="width:230px">Used by</th><th style="width:200px">Actions</th></tr></thead><tbody>';
        foreach ($by[$g] as $s) {
            $dead = stripos($s['note'], 'DEAD') !== false;
            $mask = $s['present']
                ? sprintf('<code>••••%s</code> <span class="description">· %s · %d chars</span>', esc_html($s['last4']), esc_html($s['sha8']), (int) $s['len'])
                : '<span class="description">— empty —</span>';
            if ($s['divergent']) $mask .= ' <span style="color:#b32d2e" title="locations disagree">⚠ diverged</span>';

            // "used by": locations + buckets
            $used = [];
            foreach ($s['locations'] as $L) $used[] = '<code style="font-size:11px">' . esc_html($L['where']) . '</code>';
            $usedHtml = implode(' ', $used);
            if (!empty($s['buckets'])) $usedHtml .= '<br><span class="description">→ ' . esc_html(implode(', ', $s['buckets'])) . '</span>';

            echo '<tr data-id="' . esc_attr($s['id']) . '">';
            echo '<td><strong>' . esc_html($s['label']) . '</strong>';
            if ($s['sensitivity'] === 'high') echo ' <span style="color:#b32d2e">●</span>';
            if ($dead) echo ' <span style="background:#b32d2e;color:#fff;padding:0 5px;border-radius:3px;font-size:11px">DEAD</span>';
            if ($s['note'] && !$dead) echo '<br><span class="description">' . esc_html($s['note']) . '</span>';
            echo '</td>';
            echo '<td class="lgs-current">' . $mask . '</td>';
            echo '<td>' . $usedHtml . '</td>';
            echo '<td class="lgs-actions">';
            echo '<button class="button button-small lgs-reveal">Reveal</button> ';
            if ($s['writable']) echo '<button class="button button-small lgs-edit">Edit</button>';
            else echo '<span class="description">read-only</span>';
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '<p class="description" style="margin-top:1em">Audit log: <code>/var/log/lg-secrets-audit.log</code></p>';
    ?>
    <script>
    (function(){
      const ajax='<?php echo esc_js(admin_url('admin-ajax.php')); ?>', nonce='<?php echo esc_js($nonce); ?>';
      const post=(action,body)=>{ body.action=action; body._wpnonce=nonce;
        return fetch(ajax,{method:'POST',credentials:'same-origin',
          headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)}).then(r=>r.json()); };
      document.querySelectorAll('.lgs-reveal').forEach(b=>b.onclick=function(){
        const tr=b.closest('tr'),id=tr.dataset.id,cell=tr.querySelector('.lgs-current');
        if(b.dataset.shown){ location.reload(); return; }
        b.disabled=true;b.textContent='…';
        post('lg_secrets_reveal',{id}).then(r=>{ b.disabled=false;
          if(!r.success){ alert(r.data||'error'); b.textContent='Reveal'; return; }
          b.dataset.shown='1'; b.textContent='Hide';
          cell.innerHTML='<input type="text" readonly style="width:100%;font-family:monospace">';
          cell.querySelector('input').value=r.data.value; });
      });
      document.querySelectorAll('.lgs-edit').forEach(b=>b.onclick=function(){
        const tr=b.closest('tr'),id=tr.dataset.id,cell=tr.querySelector('.lgs-current');
        cell.innerHTML='<input type="text" class="lgs-new" style="width:100%;font-family:monospace" placeholder="new value"> '+
          '<button class="button button-small button-primary lgs-save">Save all</button>';
        cell.querySelector('.lgs-save').onclick=function(){
          const v=cell.querySelector('.lgs-new').value;
          if(!v){ alert('empty'); return; }
          if(!confirm('Overwrite '+id+' in every location?')) return;
          this.disabled=true;this.textContent='…';
          post('lg_secrets_set',{id,value:v}).then(r=>{
            if(!r.success){ alert(r.data||'error'); this.disabled=false; this.textContent='Save all'; return; }
            location.reload(); });
        };
      });
    })();
    </script>
    <?php
    echo '</div>';
}

add_action('wp_ajax_lg_secrets_reveal', function () {
    if (!current_user_can(LG_SECRETS_CAP) || !check_ajax_referer('lg_secrets', '_wpnonce', false)) wp_send_json_error('forbidden', 403);
    $r = lg_secrets_helper(['reveal', sanitize_text_field($_POST['id'] ?? '')]);
    $r['ok'] ? wp_send_json_success(['value' => $r['data']['value']]) : wp_send_json_error($r['error']);
});
add_action('wp_ajax_lg_secrets_set', function () {
    if (!current_user_can(LG_SECRETS_CAP) || !check_ajax_referer('lg_secrets', '_wpnonce', false)) wp_send_json_error('forbidden', 403);
    $id  = sanitize_text_field($_POST['id'] ?? '');
    $val = (string) wp_unslash($_POST['value'] ?? '');     // raw — secrets may contain any chars
    if ($val === '') wp_send_json_error('empty value');
    $r = lg_secrets_helper(['set', $id], $val);
    $r['ok'] ? wp_send_json_success($r['data']) : wp_send_json_error($r['error']);
});
