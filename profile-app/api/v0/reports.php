<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Db;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') profile_app_json(405, ['error' => 'method_not_allowed']);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$pg = Db::pg();

// Rate-limit: 5 per IP per hour. Cheap exact count.
$rl = $pg->prepare("SELECT COUNT(*) FROM reports WHERE created_at > now() - interval '1 hour' AND reporter_ip = :ip");
$rl->execute([':ip' => $ip]);
if ((int)$rl->fetchColumn() >= 5) profile_app_json(429, ['error' => 'rate_limited']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$type = $in['target_type'] ?? '';
$id   = (int)($in['target_id'] ?? 0);
$reason = trim((string)($in['reason'] ?? ''));
$body   = trim((string)($in['body'] ?? ''));
if (!in_array($type, ['profile','practice','credential'], true)) profile_app_json(400, ['error' => 'bad_target_type']);
if ($id <= 0)         profile_app_json(400, ['error' => 'bad_target_id']);
if ($reason === '')   profile_app_json(400, ['error' => 'reason_required']);
if (strlen($body)  > 4000) profile_app_json(400, ['error' => 'body_too_long']);
if (strlen($reason) > 80)  profile_app_json(400, ['error' => 'reason_too_long']);

$viewer = Auth::currentUser();
$reporterId = $viewer ? (int)$viewer['id'] : null;

$pg->prepare("INSERT INTO reports
    (target_type, target_id, reason, body, reporter_user_id, reporter_ip)
    VALUES (:t, :i, :r, :b, :u, :ip)")
   ->execute([':t' => $type, ':i' => $id, ':r' => $reason, ':b' => $body ?: null,
              ':u' => $reporterId, ':ip' => $ip]);

// Email Ian. Cheap sendmail. Fails silently — table is still authoritative.
$adminEmail = 'ian.davlin@gmail.com';
$subj = "[looth-report] $type#$id: " . substr($reason, 0, 60);
$msg  = "Target:   $type #$id\nReason:   $reason\nBody:\n" . ($body ?: '(none)')
      . "\n\nReporter: " . ($viewer ? ($viewer['primary_email'] . ' (id ' . $viewer['id'] . ')') : 'anonymous')
      . "\nIP:       $ip\nTime:     " . gmdate('c');
@mail($adminEmail, $subj, $msg, "From: noreply@" . LG_PROFILE_APP_HOST);

profile_app_json(200, ['ok' => true]);
