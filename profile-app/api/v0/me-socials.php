<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once LG_PROFILE_APP_APP_ROOT . '/src/Block.php';   // not in config.php's require list (yet)

/**
 * socials / links block — website (kind='web') + platform links. Data is the
 * profile_socials rows (kind + url only); the block-level visibility lives on the
 * profile_sections key='socials' row (pmp, ceiling-capped at render).
 *
 *   GET → the assembled socials block (Block::loadSocials).
 *   PUT → { items?: [{kind,value,sort_order}], visibility?: 'public'|'member'|'private' }
 *         items replaces the link set (existing behaviour); visibility sets the
 *         block pmp. At least one of the two is required.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Profile;

$user   = Auth::requireUser();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $block = Block::loadSocials((int)$user['id']);
    if ($block === null) profile_app_json(404, ['error' => 'not_found']);
    profile_app_json(200, $block);
}

if ($method !== 'PUT') profile_app_json(405, ['error' => 'method_not_allowed']);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);

$hasItems = isset($in['items']) && is_array($in['items']);
$hasVis   = array_key_exists('visibility', $in);
if (!$hasItems && !$hasVis) profile_app_json(400, ['error' => 'items_or_visibility_required']);

if ($hasItems && count($in['items']) > Profile::SOCIALS_MAX) {
    profile_app_json(400, ['error' => 'too_many', 'max' => Profile::SOCIALS_MAX]);
}

// Validate the block visibility early so we never half-write.
if ($hasVis && Block::visFromInput($in['visibility']) === null) {
    profile_app_json(400, ['error' => 'invalid_visibility', 'allowed' => ['public', 'member', 'private']]);
}

$clean = [];
if ($hasItems) {
    foreach ($in['items'] as $i => $item) {
        if (!is_array($item)) profile_app_json(400, ['error' => "item_$i_not_object"]);
        $kind  = $item['kind']  ?? null;
        $value = $item['value'] ?? null;
        $sort  = $item['sort_order'] ?? $i;
        if (!in_array($kind, Profile::SOCIAL_KINDS, true)) profile_app_json(400, ['error' => "invalid_kind_at_$i"]);
        if (!is_string($value) || trim($value) === '') profile_app_json(400, ['error' => "empty_value_at_$i"]);
        $value = trim($value);

        switch ($kind) {
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) profile_app_json(400, ['error' => "bad_email_at_$i"]);
                break;
            case 'phone':
                if (!preg_match('/^[\d\s\-\+\(\)\.x]{4,}$/', $value)) profile_app_json(400, ['error' => "bad_phone_at_$i"]);
                break;
            case 'web':
                if (!preg_match('#^https?://#i', $value)) $value = 'https://' . ltrim($value, '/');
                if (!filter_var($value, FILTER_VALIDATE_URL)) profile_app_json(400, ['error' => "bad_url_at_$i"]);
                break;
            default:
                // handle/username — strip leading @, strip URL prefix if a full URL was pasted
                $value = preg_replace('#^https?://[^/]+/#i', '', $value);
                $value = ltrim($value, '@/');
                if ($value === '' || strlen($value) > 200) profile_app_json(400, ['error' => "bad_handle_at_$i"]);
                break;
        }

        $clean[] = ['kind' => $kind, 'value' => $value, 'sort_order' => (int)$sort];
    }
}

$pg = Db::pg();
if ($hasItems) {
    $pg->beginTransaction();
    try {
        $pg->prepare('DELETE FROM profile_socials WHERE user_id = :u')->execute([':u' => (int)$user['id']]);
        $ins = $pg->prepare('INSERT INTO profile_socials (user_id, kind, value, sort_order) VALUES (:u, :k, :v, :s)');
        foreach ($clean as $item) {
            $ins->execute([':u' => (int)$user['id'], ':k' => $item['kind'], ':v' => $item['value'], ':s' => $item['sort_order']]);
        }
        $pg->commit();
    } catch (Throwable $e) {
        $pg->rollBack();
        profile_app_json(500, ['error' => 'db_error', 'detail' => $e->getMessage()]);
    }
}

if ($hasVis) {
    Block::saveBlockVisibility((int)$user['id'], Block::SOCIALS_KEY, $in['visibility'], 30);
}

profile_app_json(200, ['ok' => true, 'socials' => Block::loadSocials((int)$user['id'])]);
