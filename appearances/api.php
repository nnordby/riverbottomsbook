<?php
/**
 * River Bottoms — Upcoming Appearances API
 *
 *   GET                -> public JSON list of appearances (index.html renders it)
 *   POST action=list   -> same list, but only if the PIN is right (the "log in" check)
 *   POST action=save   -> add or update one appearance   (PIN required)
 *   POST action=delete -> remove one appearance          (PIN required)
 *
 * No database. Appearances live in one JSON file OUTSIDE the webroot, next to
 * a small config file holding the bcrypt hash of Don's PIN. See
 * appearances/config.example.php for where those go.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

function out(array $body, int $code = 200): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- locate config (never inside a webroot) ---------------------------------
// live:    <domain>/public_html/appearances/api.php          -> up 2 = <domain>
// preview: <domain>/public_html/preview/appearances/api.php  -> up 3 = <domain>
function find_config(): ?string {
    $env = getenv('RB_APPEARANCES_CONFIG');           // local testing only
    if ($env && is_readable($env)) return $env;
    foreach ([2, 3] as $up) {
        $base = dirname(__DIR__, $up);
        foreach (['/private/appearances-config.php', '/appearances-config.php'] as $rel) {
            if (@is_readable($base . $rel)) return $base . $rel;
        }
    }
    return null;
}

$configPath = find_config();
$cfg = $configPath ? (require $configPath) : [];
$dataFile = $cfg['data_file'] ?? ($configPath ? dirname($configPath) . '/appearances-data.json' : null);

function load_events(?string $file): array {
    if (!$file || !is_readable($file)) return [];
    $rows = json_decode((string) @file_get_contents($file), true);
    return is_array($rows) ? array_values($rows) : [];
}

// --- public read -------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    out(['ok' => true, 'events' => load_events($dataFile)]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    out(['ok' => false, 'error' => 'method_not_allowed'], 405);
}
if (empty($cfg['pin_hash']) || !$dataFile) {
    out(['ok' => false, 'error' => 'not_configured'], 500);
}

// --- PIN check, with a lockout so the PIN can't be guessed --------------------
// 8 wrong tries -> locked for 15 minutes (for everyone; fine for a one-person form).
const MAX_TRIES = 8;
const LOCK_SECS = 900;
$lockFile = dirname($dataFile) . '/appearances-lock.json';
$lock = json_decode((string) @file_get_contents($lockFile), true) ?: ['fails' => 0, 'until' => 0];
$now = time();

if (($lock['until'] ?? 0) > $now) {
    out(['ok' => false, 'error' => 'locked', 'minutes' => (int) ceil(($lock['until'] - $now) / 60)], 429);
}

$pin = preg_replace('/\D/', '', (string) ($_POST['pin'] ?? ''));
if ($pin === '' || !password_verify($pin, $cfg['pin_hash'])) {
    $lock['fails'] = (int) ($lock['fails'] ?? 0) + 1;
    if ($lock['fails'] >= MAX_TRIES) {
        $lock = ['fails' => 0, 'until' => $now + LOCK_SECS];
    }
    @file_put_contents($lockFile, json_encode($lock), LOCK_EX);
    usleep(400000);
    out(['ok' => false, 'error' => 'bad_pin'], 403);
}
if (!empty($lock['fails'])) {
    @file_put_contents($lockFile, json_encode(['fails' => 0, 'until' => 0]), LOCK_EX);
}

// --- authenticated actions -----------------------------------------------------
$action = (string) ($_POST['action'] ?? 'list');

if ($action === 'list') {
    out(['ok' => true, 'events' => load_events($dataFile)]);
}

function field(string $key, int $max): string {
    $v = trim((string) ($_POST[$key] ?? ''));
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $v) ?? '';
    return mb_substr($v, 0, $max);
}
function valid_date(string $d): bool {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

// Read-modify-write under an exclusive lock; keep the previous copy as .bak.
function mutate(string $file, callable $fn): array {
    $fh = fopen($file, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) out(['ok' => false, 'error' => 'storage'], 500);
    $rows = json_decode((string) stream_get_contents($fh), true);
    $rows = is_array($rows) ? array_values($rows) : [];
    $new = array_values($fn($rows));
    @copy($file, $file . '.bak');
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return $new;
}

if ($action === 'delete') {
    $id = field('id', 32);
    $events = mutate($dataFile, fn($rows) => array_filter($rows, fn($r) => ($r['id'] ?? '') !== $id));
    out(['ok' => true, 'events' => $events]);
}

if ($action === 'save') {
    $e = [
        'id'    => field('id', 32),
        'type'  => field('type', 16) === 'media' ? 'media' : 'in-person',
        'title' => field('title', 120),
        'start' => field('start', 10),
        'end'   => field('end', 10),
        'time'  => field('time', 60),
        'where' => field('where', 120),
        'who'   => field('who', 120),
        'link'  => field('link', 300),
        'blurb' => field('blurb', 600),
    ];
    if ($e['title'] === '')                 out(['ok' => false, 'error' => 'Please give the appearance a title.'], 422);
    if (!valid_date($e['start']))           out(['ok' => false, 'error' => 'Please pick a date.'], 422);
    if ($e['end'] !== '' && !valid_date($e['end'])) out(['ok' => false, 'error' => 'The end date is not a valid date.'], 422);
    if ($e['end'] !== '' && $e['end'] < $e['start']) out(['ok' => false, 'error' => 'The end date is before the start date.'], 422);
    if ($e['end'] === $e['start']) $e['end'] = '';
    if ($e['link'] !== '') {
        if (!preg_match('#^https?://#i', $e['link'])) $e['link'] = 'https://' . $e['link'];
        if (!filter_var($e['link'], FILTER_VALIDATE_URL)) out(['ok' => false, 'error' => 'That link does not look like a web address.'], 422);
    }
    if ($e['id'] !== '' && !preg_match('/^[a-f0-9]{8,32}$/', $e['id'])) $e['id'] = '';

    $events = mutate($dataFile, function ($rows) use (&$e) {
        if ($e['id'] !== '') {
            foreach ($rows as $i => $r) {
                if (($r['id'] ?? '') === $e['id']) { $rows[$i] = $e; return $rows; }
            }
        }
        if (count($rows) >= 200) out(['ok' => false, 'error' => 'The list is full. Delete some old appearances first.'], 422);
        $e['id'] = bin2hex(random_bytes(6));
        $rows[] = $e;
        return $rows;
    });
    out(['ok' => true, 'events' => $events, 'id' => $e['id']]);
}

out(['ok' => false, 'error' => 'unknown_action'], 400);
