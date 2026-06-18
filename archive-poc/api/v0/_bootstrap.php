<?php
// Shared bootstrap for archive-poc API endpoints.
// Cookie-gate is enforced by nginx; if a request reaches PHP we trust it.

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Archive-Poc: v0');

// Backend (SQLite legacy / Postgres discovery) is env-driven via
// LG_ARCHIVE_POC_DSN, resolved in lg_archive_poc_pdo(). Default = SQLite.
require_once __DIR__ . '/../../config.php';

try {
    $db = lg_archive_poc_pdo();
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Read-only guard only applies to the SQLite file; PG access is grant-scoped.
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $db->exec('PRAGMA query_only = ON');
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db open failed', 'detail' => $e->getMessage()]);
    exit;
}

function send_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function param_str(string $k, string $default = ''): string {
    $v = $_GET[$k] ?? $default;
    return is_string($v) ? trim($v) : $default;
}
function param_int(string $k, int $default = 0): int {
    $v = $_GET[$k] ?? null;
    return is_numeric($v) ? (int)$v : $default;
}
function param_csv(string $k): array {
    $v = param_str($k);
    if ($v === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $v)), fn($x) => $x !== ''));
}

/** Sanitize an FTS5 MATCH input — quote each token to avoid syntax errors. */
function fts_quote(string $q): string {
    $tokens = preg_split('/\s+/u', trim($q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $out = [];
    foreach ($tokens as $t) {
        $t = str_replace('"', '', $t);
        if ($t === '') continue;
        $out[] = '"' . $t . '"';
    }
    return implode(' ', $out);
}


/**
 * Driver-aware full-text search builder. Returns the join/where/rank pieces +
 * bound param(s), or null if the query reduces to no searchable tokens.
 *   SQLite : content_fts MATCH + bm25() ranking (lower = better → rank ASC)
 *   Postgres: tsv @@ websearch_to_tsquery + ts_rank (higher = better → rank DESC)
 * On PG, relevance ranking re-binds the needle in ORDER BY (rank_param).
 */
function archive_fts(PDO $db, string $rawQ): ?array {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        $needle = trim($rawQ);
        if ($needle === '') return null;
        return [
            'pg'          => true,
            'join'        => '',
            'where'       => "ci.tsv @@ websearch_to_tsquery('english', ?)",
            'param'       => $needle,
            'rank_select' => '',
            'rank_order'  => "ts_rank(ci.tsv, websearch_to_tsquery('english', ?)) DESC",
            'rank_param'  => $needle,
        ];
    }
    $needle = fts_quote($rawQ);
    if ($needle === '') return null;
    return [
        'pg'          => false,
        'join'        => 'JOIN content_fts f ON f.rowid = ci.id',
        'where'       => 'content_fts MATCH ?',
        'param'       => $needle,
        'rank_select' => ', bm25(content_fts) AS rank',
        'rank_order'  => 'rank ASC',
        'rank_param'  => null,
    ];
}
