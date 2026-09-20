<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$storage=__DIR__.'/storage/rooms';
$checks=[
    'storage_directory'=>is_dir($storage),
    'storage_writable'=>is_dir($storage)&&is_writable($storage),
    'livekit_configured'=>is_file(__DIR__.'/config.php'),
];
$ok=!in_array(false,$checks,true);
http_response_code($ok?200:503);
echo json_encode([
    'ok'=>$ok,
    'service'=>'cnet-meet',
    'checks'=>$checks,
    'time'=>gmdate('c'),
],JSON_UNESCAPED_SLASHES);
