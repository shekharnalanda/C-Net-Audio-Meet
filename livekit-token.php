<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(array $data, int $status = 200): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function b64url(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function jwt(array $claims, string $key, string $secret): string {
    $header=b64url(json_encode(['alg'=>'HS256','typ'=>'JWT'])); $claims['iss']=$key; $payload=b64url(json_encode($claims,JSON_UNESCAPED_SLASHES));
    return "$header.$payload.".b64url(hash_hmac('sha256',"$header.$payload",$secret,true));
}
function clean(string $value,int $max): string { return trim(mb_substr(strip_tags($value),0,$max)); }
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') respond(['ok'=>false,'error'=>'POST required'],405);
$configFile=__DIR__.'/config.php'; if(!is_file($configFile)) respond(['ok'=>false,'configured'=>false,'error'=>'LiveKit server configuration pending'],503);
$config=require $configFile; $body=json_decode(file_get_contents('php://input')?:'{}',true); if(!is_array($body)) respond(['ok'=>false,'error'=>'Invalid request'],400);
$room=strtoupper(preg_replace('/[^A-Z0-9_-]/i','',(string)($body['room']??''))); $name=clean((string)($body['name']??''),60);
$pid=(string)($body['pid']??''); $sessionToken=(string)($body['token']??'');
if(strlen($room)<6||strlen($room)>48||mb_strlen($name)<2) respond(['ok'=>false,'error'=>'Meeting ID और नाम सही लिखिए'],422);
$roomFile=__DIR__.'/storage/rooms/'.$room.'.json'; if(!is_file($roomFile)) respond(['ok'=>false,'error'=>'Meeting नहीं मिली'],404);
$state=json_decode((string)file_get_contents($roomFile),true); $participant=$state['participants'][$pid]??null;
if(!is_array($participant)||!isset($participant['token'])||!hash_equals((string)$participant['token'],$sessionToken)) respond(['ok'=>false,'error'=>'Meeting session expired'],403);
$role=($participant['host']??false)?'host':'audience';
$allowedModes=['audio','video','webinar']; $requestedMode=(string)($body['mode']??'');
$modeDir=__DIR__.'/storage/room-modes'; $modeFile=$modeDir.'/'.$room.'.json'; $mode='audio';
if($role==='host'&&in_array($requestedMode,$allowedModes,true)){
    if(!is_dir($modeDir)) @mkdir($modeDir,0700,true);
    if(is_dir($modeDir)) @file_put_contents($modeFile,json_encode(['mode'=>$requestedMode,'updatedAt'=>time()]),LOCK_EX);
    $mode=$requestedMode;
}elseif(is_file($modeFile)){
    $saved=json_decode((string)file_get_contents($modeFile),true);
    if(in_array(($saved['mode']??''),$allowedModes,true)) $mode=$saved['mode'];
}elseif(in_array(($state['mode']??''),$allowedModes,true)){
    $mode=$state['mode'];
}
$canPublish=$role!=='audience'||$mode!=='webinar'; $now=time(); $identity=$pid;
$claims=['sub'=>$identity,'name'=>$name,'metadata'=>json_encode(['displayName'=>$name,'role'=>$role,'mode'=>$mode],JSON_UNESCAPED_UNICODE),'nbf'=>$now-5,'exp'=>$now+(int)($config['token_ttl_seconds']??21600),'video'=>['roomJoin'=>true,'room'=>$room,'canSubscribe'=>true,'canPublish'=>$canPublish,'canPublishData'=>true]];
respond(['ok'=>true,'url'=>(string)$config['livekit_url'],'token'=>jwt($claims,(string)$config['livekit_api_key'],(string)$config['livekit_api_secret']),'identity'=>$identity,'role'=>$role,'mode'=>$mode,'maxParticipants'=>(int)($config['max_participants']??1000)]);
