<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$core = $argv[1] ?? (__DIR__ . '/../public/tile-engagement-core.php');
require_once $core;
function verify(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
$path = sys_get_temp_dir() . '/mci-tile-test-' . bin2hex(random_bytes(8));
try {
    $store = new TileEngagementStore($path, 'self-test');
    $key = 'course-dca';
    $initial = $store->request(['action'=>'sync','keys'=>[$key]], 'visitor-a', 'test-net', '2026-11-06', 1000);
    verify($initial['cards'][$key]['views'] >= 16000, 'DCA baseline');
    verify($initial['cards'][$key]['likes'] >= 12000, 'DCA like baseline');
    $token = $initial['token'];
    $like = ['action'=>'like','keys'=>[$key],'liked'=>true,'token'=>$token];
    $liked = $store->request($like, 'visitor-a', 'test-net', '2026-11-06', 1001);
    $duplicate = $store->request($like, 'visitor-a', 'test-net', '2026-11-06', 1002);
    verify($liked['cards'][$key]['likes'] === $initial['cards'][$key]['likes']+1, 'Like persists');
    verify($duplicate['cards'][$key]['likes'] === $liked['cards'][$key]['likes'], 'Duplicate like suppressed');
    $second = $store->request(['action'=>'sync','keys'=>[$key]], 'visitor-b', 'test-net', '2026-11-06', 1003);
    verify(!$second['cards'][$key]['liked'] && $second['cards'][$key]['likes'] === $liked['cards'][$key]['likes'], 'Visitor identity and shared totals');
    $view = ['action'=>'view','keys'=>[$key],'token'=>$token];
    $viewed = $store->request($view, 'visitor-a', 'test-net', '2026-11-06', 1004);
    $again = $store->request($view, 'visitor-a', 'test-net', '2026-11-06', 1005);
    verify($viewed['cards'][$key]['views'] === $initial['cards'][$key]['views']+1 && $again['cards'][$key]['views'] === $viewed['cards'][$key]['views'], 'Daily view deduplication');
    $reloaded = (new TileEngagementStore($path,'self-test'))->request(['action'=>'sync','keys'=>[$key]], 'visitor-a', 'test-net', '2026-11-06', 1006);
    verify($reloaded['cards'][$key] === $viewed['cards'][$key], 'Reload and new process persistence');
    $unliked = $store->request(['action'=>'like','keys'=>[$key],'liked'=>false,'token'=>$token], 'visitor-a', 'test-net', '2026-11-06', 1007);
    verify($unliked['cards'][$key]['likes'] === $initial['cards'][$key]['likes'] && !$unliked['cards'][$key]['liked'], 'Unlike');
    $previous = $store->promotion($key,'2026-11-06');
    for ($i=1; $i<=400; $i++) {
        $day = (new DateTimeImmutable('2026-11-06'))->modify('+' . $i . ' days')->format('Y-m-d');
        $current = $store->promotion($key,$day);
        foreach (['likes','views'] as $metric) verify(in_array($current[$metric]-$previous[$metric],[25,40,50,80,100],true), 'Configured daily growth: ' . $metric . ' ' . $day);
        $previous = $current;
    }
    $regular = $store->promotion('tile-0123456789abcdef','2026-11-06');
    verify($regular['likes'] >= 5000 && $regular['views'] >= 7000, 'Standard promotional baseline');
    $blocked = false;
    try { $store->request(['action'=>'like','keys'=>[$key],'liked'=>true,'token'=>'invalid'], 'visitor-a','test-net','2026-11-06',1008); } catch (DomainException $e) { $blocked=true; }
    verify($blocked, 'CSRF token enforcement');
    verify(!TileEngagementStore::validKey('../private') && !TileEngagementStore::validKey(''), 'Key validation');
    $raw = file_get_contents($path.'/counters.json');
    verify(!str_contains($raw,'visitor-a') && !str_contains($raw,'test-net'), 'No raw visitor or network identifiers');
    echo "PASS promotional baseline and 400 daily growth steps; persisted like/unlike; daily view deduplication; shared totals; CSRF; private anonymous storage.\n";
} finally {
    if (is_dir($path)) { foreach (glob($path.'/*') ?: [] as $file) unlink($file); rmdir($path); }
}
