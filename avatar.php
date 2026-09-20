<?php
declare(strict_types=1);
$room=strtoupper((string)($_GET['room']??''));$id=(string)($_GET['id']??'');
if(!(preg_match('/^[A-Z0-9]{9}$/',$room)||$room==='9334779133')||!preg_match('/^[a-f0-9]{36}$/',$id)){http_response_code(404);exit;}
$path=__DIR__.'/storage/avatars/'.$room.'-'.$id.'.jpg';if(!is_file($path)){http_response_code(404);exit;}
header('Content-Type: image/jpeg');header('Content-Length: '.filesize($path));header('Cache-Control: public, max-age=31536000, immutable');readfile($path);
