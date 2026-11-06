<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

function tile_reply(int $status, array $body): void {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') tile_reply(405, ['error'=>'POST required.']);
if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) tile_reply(415, ['error'=>'JSON required.']);
$origin = parse_url($_SERVER['HTTP_ORIGIN'] ?? '');
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$originHost = strtolower((string)($origin['host'] ?? '')) . (isset($origin['port']) ? ':' . $origin['port'] : '');
if (!$host || !$originHost || !hash_equals($host, $originHost) || !in_array($origin['scheme'] ?? '', ['http','https'], true) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
    tile_reply(403, ['error'=>'Same-origin request required.']);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 24000) tile_reply(413, ['error'=>'Request too large.']);
$raw = file_get_contents('php://input', false, null, 0, 24001);
if ($raw === false || strlen($raw) > 24000) tile_reply(413, ['error'=>'Request too large.']);
try {
    $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new InvalidArgumentException('Invalid request.');
    require_once __DIR__ . '/tile-engagement-core.php';
    // SITE_KEY is intentionally a non-secret installation identifier.
    $site = 'cnet-meet';
    $project = dirname(__DIR__);
    $directory = is_dir($project . '/storage/app') ? $project . '/storage/app/tile-engagement' : $project . '/.tile-engagement-' . $site;
    $visitor = $_COOKIE['mci_tile_visitor'] ?? '';
    if (!is_string($visitor) || !preg_match('/^[a-f0-9]{64}$/D', $visitor)) $visitor = bin2hex(random_bytes(32));
    setcookie('mci_tile_visitor', $visitor, ['expires'=>time()+31536000, 'path'=>'/', 'secure'=>($origin['scheme'] === 'https'), 'httponly'=>true, 'samesite'=>'Lax']);
    $store = new TileEngagementStore($directory, $site);
    $result = $store->request($input, $visitor, (string)($_SERVER['REMOTE_ADDR'] ?? 'local'));
    tile_reply(200, $result);
} catch (JsonException | InvalidArgumentException $error) {
    tile_reply(422, ['error'=>'Invalid counter request.']);
} catch (DomainException $error) {
    tile_reply(419, ['error'=>'Reload this page.']);
} catch (OverflowException $error) {
    header('Retry-After: 60');
    tile_reply(429, ['error'=>'Please wait before trying again.']);
} catch (Throwable $error) {
    error_log('Tile engagement: ' . get_class($error));
    tile_reply(503, ['error'=>'Counters temporarily unavailable.']);
}
