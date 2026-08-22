<?php
/**
 * stripe-prune-live.php — archive every ACTIVE recurring price that is not in
 * Ian's ruled twelve, and archive non-membership stray products' prices.
 * READ-THEN-ASK: --dry-run (default) prints; --apply archives. Never deletes.
 * Run ON LIVE:  php tools/keeper/stripe-prune-live.php [--apply]
 */
$apply = in_array('--apply', $argv, true);
$env = @file_get_contents('/srv/lg-stripe-billing/.env') ?: '';
if (!preg_match('/^STRIPE_SECRET_KEY=(\S+)/m', $env, $m)) { fwrite(STDERR, "no key readable — run with sudo\n"); exit(1); }
$key = trim($m[1], "\"'");
if (strpos($key, 'sk_live') !== 0) { fwrite(STDERR, "key is not LIVE mode (" . substr($key,0,7) . "…) — refusing\n"); exit(1); }

// Ian's ruled twelve: [product-name => [interval => cents]]
$want = [
 'Looth LITE'              => ['month'=>500,  'year'=>5500],
 'Looth LITE — Regional A' => ['month'=>400,  'year'=>3000],
 'Looth LITE — Regional B' => ['month'=>300,  'year'=>2000],
 'Looth PRO'               => ['month'=>1100, 'year'=>12000],
 'Looth PRO — Regional A'  => ['month'=>800,  'year'=>6500],
 'Looth PRO — Regional B'  => ['month'=>600,  'year'=>4000],
];
function api($key, $method, $path, $body = []) {
  $ch = curl_init("https://api.stripe.com/v1/$path");
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true, CURLOPT_USERPWD=>"$key:", CURLOPT_CUSTOMREQUEST=>$method]);
  if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
  $r = json_decode(curl_exec($ch), true); curl_close($ch); return $r;
}
$prices = []; $after = '';
do {
  $page = api($key, 'GET', 'prices?limit=100&active=true&expand[]=data.product' . $after);
  foreach (($page['data'] ?? []) as $p) $prices[] = $p;
  $after = !empty($page['has_more']) ? '&starting_after=' . end($prices)['id'] : '';
} while ($after);
printf("%d active prices in live mode\n", count($prices));
$keep = 0; $kill = [];
foreach ($prices as $p) {
  $pname = $p['product']['name'] ?? '?'; $int = $p['recurring']['interval'] ?? 'one-time';
  $amt = $p['unit_amount'] ?? -1; $cur = strtoupper($p['currency'] ?? '');
  $wanted = isset($want[$pname][$int]) && $want[$pname][$int] === $amt && $cur === 'USD';
  printf("%-8s %-28s %-8s %8.2f %s  %s\n", $wanted ? 'KEEP' : 'ARCHIVE', $pname, $int, $amt/100, $cur, $p['id']);
  if ($wanted) { $keep++; continue; }
  $kill[] = $p['id'];
}
printf("\nkeep=%d archive=%d mode=%s\n", $keep, count($kill), $apply ? 'APPLY' : 'DRY-RUN');
if ($apply) foreach ($kill as $id) { $r = api($key, 'POST', "prices/$id", ['active'=>'false']); echo (isset($r['id']) ? "archived $id\n" : "FAILED $id: " . json_encode($r['error']['message'] ?? $r) . "\n"); }
elseif ($kill) echo "run again with --apply to archive those\n";
