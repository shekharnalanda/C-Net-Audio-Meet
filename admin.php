<?php
declare(strict_types=1);
session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off']);
session_start();
header('Cache-Control: no-store');
$store=__DIR__.'/storage/admin.json'; $roomsDir=__DIR__.'/storage/rooms';
if(!is_dir(dirname($store))) mkdir(dirname($store),0750,true);
function h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
$config=file_exists($store)?json_decode((string)file_get_contents($store),true):null;
$error='';
$notice='';
if(empty($_SESSION['cnet_csrf']))$_SESSION['cnet_csrf']=bin2hex(random_bytes(24));
if(isset($_GET['logout'])){session_destroy();header('Location: admin.php');exit;}
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(($_POST['action']??'')==='set_host_pin'&&!empty($_SESSION['cnet_admin'])){
    $pin=(string)($_POST['host_pin']??'');$confirmPin=(string)($_POST['confirm_pin']??'');
    if(!hash_equals((string)$_SESSION['cnet_csrf'],(string)($_POST['csrf']??'')))$error='Session verification failed. Page refresh करके फिर प्रयास कीजिए।';
    elseif(!preg_match('/^\d{4,6}$/',$pin)||$pin!==$confirmPin)$error='समान 4–6 अंकों का PIN लिखिए।';
    else{$config['host_pin']=password_hash($pin,PASSWORD_DEFAULT);$config['host_pin_updated']=time();file_put_contents($store,json_encode($config),LOCK_EX);chmod($store,0640);$notice='Host Authentication PIN सुरक्षित हो गया है।';}
  }elseif(($_POST['action']??'')==='set_capacity'&&!empty($_SESSION['cnet_admin'])){
    $capacity=(int)($_POST['room_capacity']??0);$allowed=[10,20,50,100,300,500,1000];
    if(!hash_equals((string)$_SESSION['cnet_csrf'],(string)($_POST['csrf']??'')))$error='Session verification failed. Page refresh करके फिर प्रयास कीजिए।';
    elseif(!in_array($capacity,$allowed,true))$error='सही room capacity चुनिए।';
    else{$config['room_capacity']=$capacity;file_put_contents($store,json_encode($config),LOCK_EX);chmod($store,0640);$notice='प्रति room live capacity '.$capacity.' कर दी गई है।';}
  }else{
    $email=filter_var(trim((string)($_POST['email']??'')),FILTER_VALIDATE_EMAIL);
    $pass=(string)($_POST['password']??'');
    if(!$config){
      $confirm=(string)($_POST['confirm']??'');
      if(!$email||strlen($pass)<8||$pass!==$confirm)$error='सही email और कम-से-कम 8 अक्षर का समान password लिखिए।';
      else{$config=['email'=>$email,'password'=>password_hash($pass,PASSWORD_DEFAULT),'created'=>time()];file_put_contents($store,json_encode($config),LOCK_EX);chmod($store,0640);session_regenerate_id(true);$_SESSION['cnet_admin']=true;}
    }elseif($email&&hash_equals((string)$config['email'],$email)&&password_verify($pass,(string)$config['password'])){session_regenerate_id(true);$_SESSION['cnet_admin']=true;}
    else $error='Email या password सही नहीं है।';
  }
}
$logged=!empty($_SESSION['cnet_admin']);
$rooms=[];
if($logged&&is_dir($roomsDir))foreach(glob($roomsDir.'/*.json')?:[] as $file){$d=json_decode((string)file_get_contents($file),true);if(is_array($d))$rooms[]=$d;}
usort($rooms,fn($a,$b)=>($b['created']??0)<=>($a['created']??0));
$active=array_filter($rooms,fn($r)=>!($r['ended']??false)&&count($r['participants']??[])>0);
$roomCapacity=(int)($config['room_capacity']??1000);
?><!doctype html><html lang="hi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="theme-color" content="#123a73"><title>C-Net Meet Admin</title><link rel="icon" href="assets/cnet-meet-logo-web.png"><link rel="stylesheet" href="assets/app.css?v=20260910-4"><link rel="stylesheet" href="assets/addon.css?v=9"></head><body class="admin-v2">
<?php if(!$logged):?><main class="login-v2"><section class="login-brand"><img src="assets/cnet-meet-logo-web.png" alt="C-Net Meet"><p>MCI EDUCATIONAL GROUP</p><h1>C-Net Meet</h1><span>Secure Meeting Administration</span></section><section class="login-form"><a class="back-home" href="./">← Meeting Home</a><h2><?= $config?'Admin Login':'Admin Setup' ?></h2><p><?= $config?'Host PIN और meetings नियंत्रित करने के लिए login कीजिए।':'पहली बार अपना सुरक्षित Admin account बनाइए।' ?></p><?php if($error):?><p class="error alert"><?=h($error)?></p><?php endif?><form method="post"><label>Email address<input type="email" name="email" required autocomplete="username" placeholder="admin@example.com"></label><label>Password<input type="password" name="password" required minlength="8" autocomplete="current-password" placeholder="••••••••"></label><?php if(!$config):?><label>Confirm password<input type="password" name="confirm" required minlength="8" placeholder="••••••••"></label><?php endif?><button class="primary wide"><?= $config?'Login to Admin':'Create Admin Account' ?></button></form></section></main>
<?php else:?><main class="admin-shell admin-v2-shell"><header class="admin-v2-head"><a class="admin-brand" href="./"><img src="assets/cnet-meet-logo-web.png" alt="C-Net Meet"><span><small>MCI EDUCATIONAL GROUP</small><b>C-Net Meet Admin</b></span></a><nav><a class="home-link" href="./">Meeting Home</a><a class="logout-link" href="?logout=1">Logout</a></nav></header><section class="admin-welcome"><div><span class="eyebrow">CONTROL CENTRE V2</span><h1>Meeting Administration</h1><p>Host access, live capacity और सभी meeting rooms एक सुरक्षित स्थान से नियंत्रित कीजिए।</p></div><div class="system-live"><i></i><span>System operational<small>Secure audio service</small></span></div></section><?php if($error):?><p class="error alert"><?=h($error)?></p><?php endif?><?php if($notice):?><p class="success-note"><?=h($notice)?></p><?php endif?>
<section class="admin-grid v2-stats"><div class="stat"><span class="stat-icon blue-stat">●</span><div><strong><?=count($active)?></strong><small>Active meetings</small></div></div><div class="stat"><span class="stat-icon violet-stat">▦</span><div><strong><?=count($rooms)?></strong><small>Total rooms</small></div></div><div class="stat"><span class="stat-icon green-stat">♟</span><div><strong><?=$roomCapacity?></strong><small>Live participants/room</small><em>Admin configurable</em></div></div></section>
<section class="pin-v2"><div class="pin-info"><div class="pin-heading"><span class="key-icon">⌘</span><div><span class="eyebrow">HOST ACCESS CONTROL</span><h2>Authentication PIN</h2></div></div><p>New Meeting या Schedule Meeting खोलने के लिए यह 4–6 अंकों का PIN जरूरी होगा। इसे केवल अधिकृत host को दीजिए।</p><div class="pin-status <?=!empty($config['host_pin'])?'active':'inactive'?>"><i></i><?=!empty($config['host_pin'])?'PIN सुरक्षा सक्रिय है':'PIN अभी बनाया नहीं गया है'?></div><ol><li>Generate Secure PIN दबाइए</li><li>PIN सुरक्षित जगह note कर लीजिए</li><li>Save PIN करके host को दीजिए</li></ol></div><form method="post" class="pin-form-v2"><input type="hidden" name="action" value="set_host_pin"><input type="hidden" name="csrf" value="<?=h((string)$_SESSION['cnet_csrf'])?>"><label>New Host PIN<div class="pin-input"><input id="hostPin" type="text" name="host_pin" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" required autocomplete="off" placeholder="4–6 digit PIN"><button type="button" id="generatePin">Generate Secure PIN</button></div></label><label>Confirm Host PIN<input id="confirmPin" type="text" name="confirm_pin" inputmode="numeric" pattern="\d{4,6}" minlength="4" maxlength="6" required autocomplete="off" placeholder="PIN दोबारा लिखिए"></label><p class="pin-note">PIN बदलने पर पुराने authorized host sessions बंद हो जाएँगे।</p><button class="save-pin"><?=!empty($config['host_pin'])?'Update Host PIN':'Save & Activate PIN'?></button></form></section>
<section class="capacity-v2"><div><span class="eyebrow">LIVE CAPACITY TEST</span><h2>Participants per Room</h2><p>एक room की वास्तविक join limit चुनिए। 1000 तक विकल्प उपलब्ध है; load test क्रमशः बढ़ाकर कीजिए।</p></div><form method="post"><input type="hidden" name="action" value="set_capacity"><input type="hidden" name="csrf" value="<?=h((string)$_SESSION['cnet_csrf'])?>"><label>Room capacity<select name="room_capacity"><?php foreach([10,20,50,100,300,500,1000] as $cap):?><option value="<?=$cap?>" <?=$roomCapacity===$cap?'selected':''?>><?=$cap?> participants</option><?php endforeach?></select></label><button class="save-pin">Save Live Capacity</button></form></section>
<section class="rooms-v2"><header><div><span class="eyebrow">ROOM MONITOR</span><h2>Meeting Rooms</h2></div><span><?=count($rooms)?> total</span></header><div class="table-wrap"><table class="room-table"><thead><tr><th>Meeting ID</th><th>Created</th><th>Members</th><th>Status</th></tr></thead><tbody><?php if(!$rooms):?><tr><td colspan="4" class="empty-room">अभी कोई meeting room नहीं बना है।</td></tr><?php endif;foreach($rooms as $r):?><tr><td><b><?=h((string)($r['id']??''))?></b></td><td><?=date('d M Y, h:i A',(int)($r['created']??0))?></td><td><?=count($r['participants']??[])?></td><td><span class="room-status <?=($r['ended']??false)?'ended':'open'?>"><?=($r['ended']??false)?'Ended':((($r['locked']??false)?'Locked':'Open'))?></span></td></tr><?php endforeach?></tbody></table></div></section></main><script>document.getElementById('generatePin')?.addEventListener('click',()=>{const pin=String(crypto.getRandomValues(new Uint32Array(1))[0]%900000+100000);document.getElementById('hostPin').value=pin;document.getElementById('confirmPin').value=pin;document.getElementById('hostPin').focus()});</script><?php endif?></body></html>
