<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
const MAX_PARTICIPANTS = 20;
const TTL = 86400;
$dir = __DIR__.'/storage/rooms';
if (!is_dir($dir)) mkdir($dir, 0750, true);
function out(array $v, int $s=200): never { http_response_code($s); echo json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $v=json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($v)?$v:[]; }
function clean(string $v, int $n=60): string { return trim(mb_substr(strip_tags($v),0,$n)); }
function rid(string $v): string { $v=strtoupper(preg_replace('/[^A-Z0-9]/i','',$v)); return strlen($v)===9?$v:''; }
function token(): string { return bin2hex(random_bytes(18)); }
function pathFor(string $room): string { global $dir; return $dir.'/'.$room.'.json'; }
function transact(string $room, callable $fn): array {
  $p=pathFor($room); $f=fopen($p,'c+'); if(!$f) out(['ok'=>false,'error'=>'Room storage unavailable'],500);
  flock($f,LOCK_EX); $raw=stream_get_contents($f); $d=$raw?json_decode($raw,true):[]; $d=is_array($d)?$d:[];
  $result=$fn($d); ftruncate($f,0); rewind($f); fwrite($f,json_encode($d)); fflush($f); flock($f,LOCK_UN); fclose($f); return $result;
}
function auth(array $d,string $pid,string $tk): bool { return isset($d['participants'][$pid]) && hash_equals($d['participants'][$pid]['token'],$tk); }
$b=body(); $action=$_GET['action']??'';
if($action==='create'){
  $name=clean((string)($b['name']??'')); if(mb_strlen($name)<2) out(['ok'=>false,'error'=>'अपना नाम लिखिए'],422);
  $mode=in_array(($b['mode']??''),['audio','video','webinar'],true)?$b['mode']:'audio';
  do{$room='';for($i=0;$i<9;$i++)$room.='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0,31)];}while(file_exists(pathFor($room)));
  $pid=token();$tk=token();$host=token();$now=time();
  $d=['id'=>$room,'mode'=>$mode,'created'=>$now,'locked'=>false,'ended'=>false,'host'=>$pid,'hostKey'=>hash('sha256',$host),'participants'=>[$pid=>['id'=>$pid,'name'=>$name,'token'=>$tk,'host'=>true,'muted'=>false,'hand'=>false,'joined'=>$now,'seen'=>$now]],'signals'=>[],'chat'=>[],'events'=>[]];
  file_put_contents(pathFor($room),json_encode($d),LOCK_EX); out(['ok'=>true,'room'=>$room,'mode'=>$mode,'pid'=>$pid,'token'=>$tk,'hostKey'=>$host]);
}
$room=rid((string)($b['room']??$_GET['room']??'')); if(!$room||!file_exists(pathFor($room))) out(['ok'=>false,'error'=>'Meeting नहीं मिली'],404);
$res=transact($room,function(array &$d)use($action,$b){
  $now=time(); foreach(($d['participants']??[]) as $id=>$p) if($now-($p['seen']??0)>35) unset($d['participants'][$id]);
  if($action==='join'){
    if($d['ended']??false)return['ok'=>false,'error'=>'Meeting समाप्त हो चुकी है']; if($d['locked']??false)return['ok'=>false,'error'=>'Meeting locked है'];
    if(count($d['participants']??[])>=MAX_PARTICIPANTS)return['ok'=>false,'error'=>'Meeting में 8 सदस्य पूरे हैं'];
    $name=clean((string)($b['name']??'')); if(mb_strlen($name)<2)return['ok'=>false,'error'=>'अपना नाम लिखिए'];
    $id=token();$tk=token();$d['participants'][$id]=['id'=>$id,'name'=>$name,'token'=>$tk,'host'=>false,'muted'=>false,'hand'=>false,'joined'=>$now,'seen'=>$now];
    return['ok'=>true,'mode'=>$d['mode']??'audio','pid'=>$id,'token'=>$tk];
  }
  $pid=(string)($b['pid']??'');$tk=(string)($b['token']??''); if(!auth($d,$pid,$tk))return['ok'=>false,'error'=>'Session expired'];
  $d['participants'][$pid]['seen']=$now;
  if($action==='state'){
    $since=(int)($b['since']??0); $signals=array_values(array_filter($d['signals']??[],fn($x)=>$x['to']===$pid&&$x['seq']>$since));
    $people=array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'host'=>$p['host'],'muted'=>$p['muted'],'hand'=>$p['hand']],array_values($d['participants']));
    return['ok'=>true,'mode'=>$d['mode']??'audio','participants'=>$people,'signals'=>$signals,'chat'=>array_slice($d['chat']??[],-40),'locked'=>$d['locked'],'ended'=>$d['ended']];
  }
  if($action==='signal'){$to=(string)($b['to']??'');if(isset($d['participants'][$to])){$d['signals'][]=['seq'=>(int)(microtime(true)*1000)+random_int(0,999),'from'=>$pid,'to'=>$to,'data'=>$b['data']??null];$d['signals']=array_slice($d['signals'],-300);}return['ok'=>true];}
  if($action==='chat'){$msg=clean((string)($b['message']??''),300);if($msg!=='')$d['chat'][]=['id'=>token(),'from'=>$pid,'name'=>$d['participants'][$pid]['name'],'message'=>$msg,'time'=>$now];return['ok'=>true];}
  if($action==='self'){$field=(string)($b['field']??'');if(in_array($field,['muted','hand'],true))$d['participants'][$pid][$field]=(bool)($b['value']??false);if($field==='name')$d['participants'][$pid]['name']=clean((string)($b['value']??''));return['ok'=>true];}
  if($action==='control'){
    if(!($d['participants'][$pid]['host']??false))return['ok'=>false,'error'=>'Host permission required'];$cmd=$b['command']??'';$target=$b['target']??'';
    if($cmd==='lock')$d['locked']=(bool)($b['value']??false); if($cmd==='mute'&&isset($d['participants'][$target]))$d['participants'][$target]['muted']=true;
    if($cmd==='remove'&&isset($d['participants'][$target])&&$target!==$pid)unset($d['participants'][$target]); if($cmd==='end')$d['ended']=true; return['ok'=>true];
  }
  if($action==='leave'){unset($d['participants'][$pid]);return['ok'=>true];} return['ok'=>false,'error'=>'Invalid action'];
}); out($res,($res['ok']??false)?200:422);
