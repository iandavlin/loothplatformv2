<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';

/**
 * Resume PDF upload + visibility + delete.
 *
 *   POST   /profile-api/v0/me/resume                multipart; field "resume"
 *                                                   PDF-only (finfo mime check), ≤ 10 MB.
 *   PUT    /profile-api/v0/me/resume                body { visibility: 'public'|'member'|'private' }
 *                                                   updates resume_visibility (uses Block::visFromInput
 *                                                   for UI 'member' → DB 'members' normalization).
 *   DELETE /profile-api/v0/me/resume                clears resume_url; bytes-on-disk left in place.
 *
 *   store: /srv/profile-app-media/resumes/<uuid>/<v>.pdf
 *   serve: /profile-media/resumes/<uuid>/<v>.pdf?v=<v>
 *
 * NOTE TO COORDINATOR (provision before this works):
 *   1. mkdir -p /srv/profile-app-media/resumes && chown profile-app:loothdevs;
 *      mode 2775. Queued in /srv/lg-sudo-queue/REQUESTS.md as buck-2026-06-02-2.
 *   2. nginx (/etc/nginx/snippets/strangler-profile-app.conf):
 *        rewrite "^/profile-api/v0/me/resume/?$" /profile-api/v0/me-resume.php last;
 *      Add `me-resume` to the auth-gated /me/*\.php allowlist regex.
 *      The /profile-media/ static alias already serves the new subdir.
 *   3. Schema: sql/2026-06-02-resume.sql adds the three columns.
 */

use Looth\ProfileApp\Auth;
use Looth\ProfileApp\Block;
use Looth\ProfileApp\Db;
use Looth\ProfileApp\Media;
use Looth\ProfileApp\R2;

const LG_RESUME_STORE    = '/srv/profile-app-media/resumes';
const LG_RESUME_URL_BASE = '/profile-media/resumes';
const LG_RESUME_MAX      = 10 * 1024 * 1024;   // 10 MB

$user   = Auth::requireUser();
$uid    = (int) $user['id'];
$uuid   = strtolower((string) $user['uuid']);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'PUT') {
    $in  = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($in)) profile_app_json(400, ['error' => 'invalid_json']);
    if (!array_key_exists('visibility', $in)) profile_app_json(400, ['error' => 'visibility_required']);
    $vis = Block::visFromInput((string)$in['visibility']);
    if ($vis === null) profile_app_json(400, ['error' => 'invalid_visibility']);
    Db::pg()->prepare('UPDATE users SET resume_visibility = :v WHERE id = :i')
        ->execute([':v' => $vis, ':i' => $uid]);
    profile_app_json(200, ['ok' => true, 'resume_visibility' => $vis]);
}

if ($method === 'DELETE') {
    Db::pg()->prepare('UPDATE users SET resume_url = NULL WHERE id = :i')->execute([':i' => $uid]);
    Media::unlinkUrl($user['resume_url'] ?? null);   // remove the bytes, not just the row
    profile_app_json(200, ['ok' => true, 'resume_url' => null]);
}

if ($method !== 'POST') profile_app_json(405, ['error' => 'method_not_allowed']);

profile_app_rate_gate('upload:' . $uuid, 30, 300);

$file = $_FILES['resume'] ?? null;
if (!is_array($file)) profile_app_json(400, ['error' => 'resume_file_required']);
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    profile_app_json(400, ['error' => 'upload_error', 'code' => $file['error'] ?? null]);
}
if ((int)($file['size'] ?? 0) > LG_RESUME_MAX) profile_app_json(400, ['error' => 'too_large', 'max' => '10MB']);

$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) profile_app_json(400, ['error' => 'bad_upload']);

// PDF-only (server-side finfo — don't trust the client mime).
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string) $finfo->file($tmp);
if ($mime !== 'application/pdf') profile_app_json(400, ['error' => 'unsupported_type', 'allowed' => ['application/pdf']]);

// Defence-in-depth: PDF files begin with "%PDF-" header bytes.
$head = @file_get_contents($tmp, false, null, 0, 5);
if ($head !== '%PDF-') profile_app_json(400, ['error' => 'not_a_pdf']);

// Atomic version bump — race-safe vs concurrent uploads (see me-avatar.php).
$rvs = Db::pg()->prepare('UPDATE users SET resume_version = COALESCE(resume_version,0) + 1 WHERE id = :i RETURNING resume_version');
$rvs->execute([':i' => $uid]);
$ver = (int) $rvs->fetchColumn();

$fn = $ver . '.pdf';
if (R2::enabled()) {
    $bytes = @file_get_contents($tmp);
    if ($bytes === false || !R2::put('resumes/' . $uuid . '/' . $fn, $bytes, 'application/pdf')) {
        profile_app_json(500, ['error' => 'write_failed']);
    }
} else {
    $dir = LG_RESUME_STORE . '/' . $uuid;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        profile_app_json(500, ['error' => 'store_unwritable', 'hint' => 'provision ' . LG_RESUME_STORE . ' (chown to the FPM user)']);
    }
    $dest = $dir . '/' . $fn;
    if (!@move_uploaded_file($tmp, $dest)) profile_app_json(500, ['error' => 'write_failed']);
    @chmod($dest, 0644);
}

$url = LG_RESUME_URL_BASE . '/' . $uuid . '/' . $ver . '.pdf?v=' . $ver;

Db::pg()->prepare('UPDATE users SET resume_url = :u WHERE id = :i')
    ->execute([':u' => $url, ':i' => $uid]);

// GC the previous resume file (replace would orphan the old <v>.pdf).
Media::unlinkUrl($user['resume_url'] ?? null);

profile_app_json(200, ['ok' => true, 'resume_url' => $url, 'resume_version' => $ver]);
