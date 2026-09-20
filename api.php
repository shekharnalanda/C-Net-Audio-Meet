<?php
declare(strict_types=1);
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
const TTL = 86400;
$dir = __DIR__.'/storage/rooms';
if (!is_dir($dir)) mkdir($dir, 0750, true);
function out(array $v, int $s=200): never { http_response_code($s); echo json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array { $v=json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($v)?$v:[]; }
function clean(string $v, int $n=60): string { return trim(mb_substr(strip_tags($v),0,$n)); }
function rid(string $v): string { $v=strtoupper(preg_replace('/[^A-Z0-9]/i','',$v)); return (strlen($v)===9||$v==='9334779133')?$v:''; }
function token(): string { return bin2hex(random_bytes(18)); }
function clientHash(string $v): string { return preg_match('/^[a-f0-9-]{24,80}$/i',$v)?hash('sha256',strtolower($v)):''; }
function pathFor(string $room): string { global $dir; return $dir.'/'.$room.'.json'; }
function adminConfig(): ?array { $p=__DIR__.'/storage/admin.json';$v=file_exists($p)?json_decode((string)file_get_contents($p),true):null;return is_array($v)?$v:null; }
function roomCapacity(): int { $c=adminConfig();$v=(int)($c['room_capacity']??1000);return in_array($v,[10,20,50,100,300,500,1000],true)?$v:1000; }
function hostAuthorized(): bool { $c=adminConfig();return !empty($_SESSION['cnet_host_authorized'])&&isset($c['host_pin_updated'])&&hash_equals((string)$c['host_pin_updated'],(string)($_SESSION['cnet_host_pin_version']??'')); }
function transact(string $room, callable $fn): array {
  $p=pathFor($room); $f=fopen($p,'c+'); if(!$f) out(['ok'=>false,'error'=>'Room storage unavailable'],500);
  flock($f,LOCK_EX); $raw=stream_get_contents($f); $d=$raw?json_decode($raw,true):[]; $d=is_array($d)?$d:[];
  $result=$fn($d); ftruncate($f,0); rewind($f); fwrite($f,json_encode($d)); fflush($f); flock($f,LOCK_UN); fclose($f); return $result;
}
function auth(array $d,string $pid,string $tk): bool { return isset($d['participants'][$pid]) && hash_equals($d['participants'][$pid]['token'],$tk); }
$b=body(); $action=$_GET['action']??'';
if($action==='authorize_host'){
  $config=adminConfig();
  $pin=(string)($b['pin']??'');
  $lockedUntil=(int)($_SESSION['cnet_pin_locked_until']??0);
  if($lockedUntil>time()) out(['ok'=>false,'error'=>'कई गलत प्रयास हुए। कुछ मिनट बाद फिर प्रयास कीजिए।'],429);
  if(!is_array($config)||empty($config['host_pin'])) out(['ok'=>false,'error'=>'Host PIN अभी Admin Panel में बनाया नहीं गया है।'],409);
  if(!preg_match('/^\d{4,6}$/',$pin)||!password_verify($pin,(string)$config['host_pin'])){
    $attempts=(int)($_SESSION['cnet_pin_attempts']??0)+1;
    $_SESSION['cnet_pin_attempts']=$attempts;
    if($attempts>=5){$_SESSION['cnet_pin_locked_until']=time()+300;$_SESSION['cnet_pin_attempts']=0;}
    out(['ok'=>false,'error'=>'Authentication PIN सही नहीं है।'],401);
  }
  unset($_SESSION['cnet_pin_attempts'],$_SESSION['cnet_pin_locked_until']);
  $_SESSION['cnet_host_authorized']=true;
  $_SESSION['cnet_host_pin_version']=(string)$config['host_pin_updated'];
  out(['ok'=>true]);
}
if($action==='host_status') out(['ok'=>true,'authorized'=>hostAuthorized()]);
if($action==='start_permanent'){
  if(!hostAuthorized()) out(['ok'=>false,'error'=>'पहले Host Authentication PIN डालें'],401);
  $name=clean((string)($b['name']??'')); if(mb_strlen($name)<2) out(['ok'=>false,'error'=>'अपना नाम लिखिए'],422);
  $room='9334779133'; $now=time(); $existingFile=pathFor($room);
  if(is_file($existingFile)){
    $existing=json_decode((string)file_get_contents($existingFile),true); $active=false;
    foreach(($existing['participants']??[]) as $participant) if($now-(int)($participant['seen']??0)<=35){$active=true;break;}
    if($active&&!($existing['ended']??false)) out(['ok'=>false,'error'=>'यह permanent meeting पहले से चल रही है। Invitation link से जुड़िए।'],409);
  }
  $pid=token();$tk=token();$host=token();$ch=clientHash((string)($b['clientKey']??''));
  $d=['id'=>$room,'created'=>$now,'permanent'=>true,'started'=>true,'waitingRoom'=>false,'waiting'=>[],'blockedClients'=>[],'locked'=>false,'ended'=>false,'host'=>$pid,'hostKey'=>hash('sha256',$host),'participants'=>[$pid=>['id'=>$pid,'name'=>$name,'token'=>$tk,'clientHash'=>$ch,'host'=>true,'muted'=>false,'hand'=>false,'joined'=>$now,'seen'=>$now]],'signals'=>[],'chat'=>[],'events'=>[]];
  file_put_contents(pathFor($room),json_encode($d),LOCK_EX); out(['ok'=>true,'room'=>$room,'pid'=>$pid,'token'=>$tk,'hostKey'=>$host]);
}
if($action==='create'){
  if(!hostAuthorized()) out(['ok'=>false,'error'=>'पहले Host Authentication PIN डालिए'],401);
  $name=clean((string)($b['name']??'')); if(mb_strlen($name)<2) out(['ok'=>false,'error'=>'अपना नाम लिखिए'],422);
  do{$room='';for($i=0;$i<9;$i++)$room.='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0,31)];}while(file_exists(pathFor($room)));
  $pid=token();$tk=token();$host=token();$now=time();
  $ch=clientHash((string)($b['clientKey']??''));
  $d=['id'=>$room,'created'=>$now,'started'=>true,'waitingRoom'=>false,'waiting'=>[],'blockedClients'=>[],'locked'=>false,'ended'=>false,'host'=>$pid,'hostKey'=>hash('sha256',$host),'participants'=>[$pid=>['id'=>$pid,'name'=>$name,'token'=>$tk,'clientHash'=>$ch,'host'=>true,'muted'=>false,'hand'=>false,'joined'=>$now,'seen'=>$now]],'signals'=>[],'chat'=>[],'events'=>[]];
  file_put_contents(pathFor($room),json_encode($d),LOCK_EX); out(['ok'=>true,'room'=>$room,'pid'=>$pid,'token'=>$tk,'hostKey'=>$host]);
}
if($action==='reserve'){
  if(!hostAuthorized()) out(['ok'=>false,'error'=>'पहले Host Authentication PIN डालिए'],401);
  $title=clean((string)($b['title']??'Scheduled meeting'),80);
  $date=clean((string)($b['date']??''),10); $time=clean((string)($b['time']??''),5);
  do{$room='';for($i=0;$i<9;$i++)$room.='ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[random_int(0,31)];}while(file_exists(pathFor($room)));
  $host=token(); $now=time();
  $d=['id'=>$room,'created'=>$now,'scheduled'=>true,'title'=>$title,'date'=>$date,'time'=>$time,'started'=>false,'waitingRoom'=>(bool)($b['waitingRoom']??false),'waiting'=>[],'blockedClients'=>[],'locked'=>false,'ended'=>false,'host'=>'','hostKey'=>hash('sha256',$host),'participants'=>[],'signals'=>[],'chat'=>[],'events'=>[]];
  file_put_contents(pathFor($room),json_encode($d),LOCK_EX);
  out(['ok'=>true,'room'=>$room,'hostKey'=>$host,'invite'=>'?room='.$room]);
}
// Heartbeat and control requests do not need the PHP session. Releasing this
// lock prevents polling from blocking host controls in the same browser.
if(session_status()===PHP_SESSION_ACTIVE) session_write_close();
$room=rid((string)($b['room']??$_GET['room']??'')); if(!$room||!file_exists(pathFor($room))) out(['ok'=>false,'error'=>'Meeting नहीं मिली'],404);
$res=transact($room,function(array &$d)use($action,$b,$room){
  $now=time(); foreach(($d['participants']??[]) as $id=>$p) if($now-($p['seen']??0)>900) unset($d['participants'][$id]);
  foreach(($d['waiting']??[]) as $id=>$w) if($now-($w['seen']??$w['joined']??0)>180) unset($d['waiting'][$id]);
  $hostId=(string)($d['host']??'');
  if(($d['started']??false)&&$hostId!==''&&!isset($d['participants'][$hostId])){$d['ended']=true;$d['started']=false;$d['locked']=true;$d['endedAt']=$now;foreach(array_keys($d['waiting']??[]) as $id)$d['waiting'][$id]['status']='rejected';}
  if($action==='join'){
    $name=clean((string)($b['name']??'')); if(mb_strlen($name)<2)return['ok'=>false,'error'=>'अपना नाम लिखिए'];
    $isHost=!empty($b['hostKey'])&&hash_equals((string)($d['hostKey']??''),hash('sha256',(string)$b['hostKey']));
    if(($d['ended']??false)&&!$isHost)return['ok'=>false,'error'=>'Meeting समाप्त है। Host के दोबारा शुरू करने की प्रतीक्षा कीजिए।'];
    if(($d['locked']??false)&&!$isHost)return['ok'=>false,'error'=>'Meeting locked है'];
    $restarted=false;if($isHost&&($d['ended']??false)){$d['participants']=[];$d['waiting']=[];$d['signals']=[];$d['chat']=[];$d['ended']=false;$d['started']=false;$d['locked']=false;unset($d['endedAt']);$restarted=true;}
    $ch=clientHash((string)($b['clientKey']??''));
    if(!$isHost&&$ch!==''&&in_array($ch,$d['blockedClients']??[],true))return['ok'=>false,'error'=>'इस meeting में आपका प्रवेश प्रतिबंधित है।'];
    if(!$isHost&&(!(bool)($d['started']??false)||(bool)($d['waitingRoom']??false))){
      $id=token();$tk=token();$d['waiting'][$id]=['id'=>$id,'name'=>$name,'token'=>$tk,'clientHash'=>$ch,'status'=>'waiting','joined'=>$now,'seen'=>$now];
      return['ok'=>true,'waiting'=>true,'waitId'=>$id,'waitToken'=>$tk,'started'=>(bool)($d['started']??false),'waitingRoom'=>(bool)($d['waitingRoom']??false)];
    }
    $capacity=roomCapacity();if(count($d['participants']??[])>=$capacity)return['ok'=>false,'error'=>'Meeting में '.$capacity.' सदस्य पूरे हैं'];
    $id=token();$tk=token();$d['participants'][$id]=['id'=>$id,'name'=>$name,'token'=>$tk,'clientHash'=>$ch,'host'=>$isHost,'muted'=>false,'hand'=>false,'joined'=>$now,'seen'=>$now];
    if($isHost){$d['host']=$id;$d['started']=true;}
    return['ok'=>true,'pid'=>$id,'token'=>$tk,'host'=>$isHost,'restarted'=>$restarted];
  }
  if($action==='wait_state'){
    $wid=(string)($b['waitId']??'');$wt=(string)($b['waitToken']??'');
    if(!isset($d['waiting'][$wid])||!hash_equals((string)$d['waiting'][$wid]['token'],$wt))return['ok'=>false,'error'=>'Waiting request समाप्त हो गई।'];
    $w=&$d['waiting'][$wid];$w['seen']=$now;
    if(($w['status']??'')==='rejected'){unset($d['waiting'][$wid]);return['ok'=>false,'rejected'=>true,'error'=>'Host ने प्रवेश की अनुमति नहीं दी।'];}
    $admit=($w['status']??'')==='admitted'||((bool)($d['started']??false)&&!(bool)($d['waitingRoom']??false));
    if($admit){
      $capacity=roomCapacity();if(count($d['participants']??[])>=$capacity)return['ok'=>false,'error'=>'Meeting में '.$capacity.' सदस्य पूरे हैं'];
      $id=token();$tk=token();$d['participants'][$id]=['id'=>$id,'name'=>$w['name'],'token'=>$tk,'clientHash'=>$w['clientHash']??'','host'=>false,'muted'=>false,'hand'=>false,'joined'=>$now,'seen'=>$now];unset($d['waiting'][$wid]);
      return['ok'=>true,'admitted'=>true,'pid'=>$id,'token'=>$tk,'host'=>false];
    }
    return['ok'=>true,'waiting'=>true,'started'=>(bool)($d['started']??false),'waitingRoom'=>(bool)($d['waitingRoom']??false)];
  }
  $pid=(string)($b['pid']??'');$tk=(string)($b['token']??''); if(!auth($d,$pid,$tk))return['ok'=>false,'error'=>'Session expired'];
  $d['participants'][$pid]['seen']=$now;
  if($action==='state'){
    $since=(int)($b['since']??0); $signals=array_values(array_filter($d['signals']??[],fn($x)=>$x['to']===$pid&&$x['seq']>$since));
    $people=array_map(fn($p)=>['id'=>$p['id'],'name'=>$p['name'],'host'=>$p['host'],'muted'=>$p['muted'],'hand'=>$p['hand'],'avatar'=>(int)($p['avatar']??0)],array_values($d['participants']));
    $waiting=[];if($d['participants'][$pid]['host']??false)$waiting=array_map(fn($w)=>['id'=>$w['id'],'name'=>$w['name']],array_values(array_filter($d['waiting']??[],fn($w)=>($w['status']??'waiting')==='waiting')));
    return['ok'=>true,'participants'=>$people,'signals'=>$signals,'chat'=>array_slice($d['chat']??[],-40),'waiting'=>$waiting,'waitingRoom'=>(bool)($d['waitingRoom']??false),'started'=>(bool)($d['started']??false),'capacity'=>roomCapacity(),'locked'=>$d['locked'],'ended'=>$d['ended']];
  }
  if($action==='signal'){$to=(string)($b['to']??'');if(isset($d['participants'][$to])){$d['signals'][]=['seq'=>(int)(microtime(true)*1000)+random_int(0,999),'from'=>$pid,'to'=>$to,'data'=>$b['data']??null];$d['signals']=array_slice($d['signals'],-300);}return['ok'=>true];}
  if($action==='chat'){$msg=clean((string)($b['message']??''),300);if($msg!=='')$d['chat'][]=['id'=>token(),'from'=>$pid,'name'=>$d['participants'][$pid]['name'],'message'=>$msg,'time'=>$now];return['ok'=>true];}
  if($action==='avatar'){
    $avatarDir=__DIR__.'/storage/avatars';if(!is_dir($avatarDir))mkdir($avatarDir,0750,true);$avatarPath=$avatarDir.'/'.$room.'-'.$pid.'.jpg';
    if(!empty($b['remove'])){if(is_file($avatarPath))unlink($avatarPath);$d['participants'][$pid]['avatar']=0;return['ok'=>true,'avatar'=>0];}
    $image=(string)($b['image']??'');if(!preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#',$image,$m))return['ok'=>false,'error'=>'सही profile photo भेजिए'];
    $raw=base64_decode($m[1],true);if($raw===false||strlen($raw)>70000||@getimagesizefromstring($raw)===false)return['ok'=>false,'error'=>'Profile photo बहुत बड़ी या अमान्य है'];
    if(file_put_contents($avatarPath,$raw,LOCK_EX)===false)return['ok'=>false,'error'=>'Profile photo सुरक्षित नहीं हुई'];chmod($avatarPath,0640);$d['participants'][$pid]['avatar']=(int)(microtime(true)*1000);return['ok'=>true,'avatar'=>$d['participants'][$pid]['avatar']];
  }
  if($action==='self'){$field=(string)($b['field']??'');if(in_array($field,['muted','hand'],true))$d['participants'][$pid][$field]=(bool)($b['value']??false);if($field==='name')$d['participants'][$pid]['name']=clean((string)($b['value']??''));return['ok'=>true];}
  if($action==='control'){
    if(!($d['participants'][$pid]['host']??false))return['ok'=>false,'error'=>'Host permission required'];$cmd=$b['command']??'';$target=$b['target']??'';
    if($cmd==='lock')$d['locked']=(bool)($b['value']??false);
    if($cmd==='waiting_room')$d['waitingRoom']=(bool)($b['value']??false);
    if(($cmd==='mute'||$cmd==='unmute')&&isset($d['participants'][$target]))$d['participants'][$target]['muted']=$cmd==='mute';
    if($cmd==='mute_all'||$cmd==='unmute_all')foreach(array_keys($d['participants']) as $id)if($id!==$pid)$d['participants'][$id]['muted']=$cmd==='mute_all';
    if(($cmd==='admit'||$cmd==='reject')&&isset($d['waiting'][$target]))$d['waiting'][$target]['status']=$cmd==='admit'?'admitted':'rejected';
    if($cmd==='remove'&&isset($d['participants'][$target])&&$target!==$pid){$ch=(string)($d['participants'][$target]['clientHash']??'');if($ch!==''&&!in_array($ch,$d['blockedClients']??[],true))$d['blockedClients'][]=$ch;unset($d['participants'][$target]);}
    if($cmd==='end'){$d['ended']=true;$d['started']=false;$d['locked']=true;$d['endedAt']=$now;foreach(array_keys($d['waiting']??[]) as $id)$d['waiting'][$id]['status']='rejected';} return['ok'=>true];
  }
  if($action==='leave'){
    $wasHost=(bool)($d['participants'][$pid]['host']??false);
    unset($d['participants'][$pid]);
    if($wasHost){$d['ended']=true;$d['started']=false;$d['locked']=true;$d['endedAt']=$now;foreach(array_keys($d['waiting']??[]) as $id)$d['waiting'][$id]['status']='rejected';}
    return['ok'=>true,'ended'=>$wasHost];
  } return['ok'=>false,'error'=>'Invalid action'];
}); out($res,($res['ok']??false)?200:422);
