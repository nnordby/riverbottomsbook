<?php
/**
 * River Bottoms — feedback relay
 * Receives a small feedback POST from index.html and opens a GitHub Issue
 * on nnordby/riverbottomsbook. Mirrors the vmp-app "report -> Issue" pattern,
 * adapted for a static site (no DB, no admin UI — work the issues in GitHub).
 *
 * Token + repo live in feedback-config.php ONE LEVEL ABOVE the webroot so the
 * PAT is never web-servable. See feedback-config.example.php.
 */

header('Content-Type: application/json');

// --- only POST ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

// --- load server-side config (outside webroot) ---
$configPath = dirname(__DIR__) . '/feedback-config.php';
if (!is_readable($configPath)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'not_configured']);
    exit;
}
$cfg = require $configPath; // ['token' => '...', 'repo' => 'nnordby/riverbottomsbook']
if (empty($cfg['token']) || empty($cfg['repo'])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'not_configured']);
    exit;
}

// --- honeypot: bots fill hidden "website" field; humans leave it empty ---
if (!empty($_POST['website'])) {
    // Pretend success so bots don't learn anything.
    echo json_encode(['ok' => true]);
    exit;
}

// --- basic per-IP rate limit (5 / hour) ---
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlFile = sys_get_temp_dir() . '/rb_feedback_' . md5($ip) . '.json';
$now = time();
$hits = [];
if (is_readable($rlFile)) {
    $hits = json_decode(@file_get_contents($rlFile), true) ?: [];
}
$hits = array_values(array_filter($hits, fn($t) => $t > $now - 3600));
if (count($hits) >= 5) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

// --- collect + validate input ---
$message = trim($_POST['message'] ?? '');
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$page    = trim($_POST['page'] ?? '');

if ($message === '' || mb_strlen($message) < 3) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'empty_message']);
    exit;
}

// clamp lengths
$message = mb_substr($message, 0, 5000);
$name    = mb_substr($name, 0, 120);
$email   = mb_substr($email, 0, 200);
$page    = mb_substr($page, 0, 300);

// --- build the issue ---
$who = $name !== '' ? $name : 'Anonymous';
$firstLine = trim(strtok($message, "\n"));
$title = 'Feedback: ' . mb_substr($firstLine, 0, 70) . (mb_strlen($firstLine) > 70 ? '…' : '');

$body  = $message . "\n\n---\n";
$body .= "- **From:** " . $who . ($email !== '' ? " (" . $email . ")" : "") . "\n";
if ($page !== '') $body .= "- **Page:** " . $page . "\n";
$body .= "- **IP:** " . $ip . "\n";
$body .= "- **Submitted:** " . gmdate('Y-m-d H:i') . " UTC\n";
$body .= "\n_Filed automatically from the riverbottomsbook.com feedback widget._";

$payload = json_encode([
    'title'  => $title,
    'body'   => $body,
    'labels' => ['feedback'],
]);

// --- call GitHub ---
$ch = curl_init('https://api.github.com/repos/' . $cfg['repo'] . '/issues');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/vnd.github+json',
        'Authorization: Bearer ' . $cfg['token'],
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: riverbottomsbook-feedback',
        'Content-Type: application/json',
    ],
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($code === 201) {
    // record the hit only on success
    $hits[] = $now;
    @file_put_contents($rlFile, json_encode($hits), LOCK_EX);

    $issue = json_decode($resp, true);
    echo json_encode(['ok' => true, 'issue' => $issue['number'] ?? null]);
    exit;
}

// GitHub rejected it — log server-side, return a generic error
error_log('[rb-feedback] GitHub error ' . $code . ' ' . $err . ' ' . substr((string)$resp, 0, 300));
http_response_code(502);
echo json_encode(['ok' => false, 'error' => 'github_failed', 'status' => $code]);
