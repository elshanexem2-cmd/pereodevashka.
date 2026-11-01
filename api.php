<?php
declare(strict_types=1);
require __DIR__.'/lib.php';guard_headers();
// Small WebP copy of a stored PNG for showing on screen. The original size is kept in the file name; the copies are tiny and cached in the private folder.
function image_preview(array $c,string $h): ?array {
 $dir=$c['private_dir'].'/img-small/'.substr($h,0,2);
 foreach(glob($dir.'/'.$h.'-*x*.webp')?:[] as $f){if(preg_match('/-(\d+)x(\d+)\.webp$/D',$f,$m)){$b=file_get_contents($f);if($b!==false&&$b!=='')return ['w'=>(int)$m[1],'h'=>(int)$m[2],'webp'=>$b];}}
 if(!function_exists('imagewebp')||!function_exists('imagecreatefromstring'))return null;
 $bytes=image_bytes($c,$h);if($bytes===null)return null;
 $info=@getimagesizefromstring($bytes);if(!$info||$info[2]!==IMAGETYPE_PNG||$info[0]*$info[1]>16000000)return null;
 $w=(int)$info[0];$ht=(int)$info[1];$src=@imagecreatefromstring($bytes);unset($bytes);if(!$src)return null;
 $s=min(1,480/max($w,$ht));$tw=max(1,(int)round($w*$s));$th=max(1,(int)round($ht*$s));
 $dst=imagecreatetruecolor($tw,$th);imagealphablending($dst,false);imagesavealpha($dst,true);imagefill($dst,0,0,imagecolorallocatealpha($dst,0,0,0,127));
 imagecopyresampled($dst,$src,0,0,0,0,$tw,$th,$w,$ht);imagedestroy($src);
 ob_start();$ok=imagewebp($dst,null,82);$webp=ob_get_clean();imagedestroy($dst);
 if(!$ok||!is_string($webp)||$webp==='')return null;
 if(!is_dir($dir))@mkdir($dir,0700,true);$tmp=$dir.'/.'.$h.'.tmp';
 if(@file_put_contents($tmp,$webp)!==false)@rename($tmp,$dir.'/'.$h.'-'.$w.'x'.$ht.'.webp');
 return ['w'=>$w,'h'=>$ht,'webp'=>$webp];
}
// Catalog books taken out of the studio (the clothes now come from the library); their files stay on the server.
const HIDDEN_BOOKS=['my-doll','cat-cafe','space-boy'];
try {
 $c=config();session_begin($c);$action=$_GET['action']??'session';
 // Who is on the site now: every open page pings once a minute; the owner sees the count with /online in the bot.
 if($action==='ping'){
  $v=(string)($_GET['v']??'');$pg=($_GET['p']??'')==='studio'?'studio':'landing';
  if(preg_match('/^[a-z0-9]{8,32}$/D',$v)){$uid=(string)($_SESSION['uid']??'');session_write_close();
   locked($c['private_dir'].'/online.lock',function()use($c,$v,$pg,$uid){$p=$c['private_dir'].'/online.php';$all=record_read($p,[]);$now=time();
    foreach($all as $k=>$r)if(($r['t']??0)<$now-600)unset($all[$k]);
    if(count($all)<5000||isset($all[$v])){$all[$v]=['t'=>$now,'p'=>$pg,'u'=>$uid,'f'=>$all[$v]['f']??$now];record_write($p,$all);}
   });}
  reply(['ok'=>true]);
 }
 if($action==='session') { $u=null;$generation=null;$expired=false;if(!empty($_SESSION['uid'])){$r=record_read($c['private_dir'].'/users/'.$_SESSION['uid'].'.php');if($r){if(access_state($r)['active']&&!session_replaced($r)){$u=user_public($r);$generation=generation_status($c['private_dir'].'/accounts/'.$r['id'],$r);}else $expired=!access_state($r)['active'];}}reply(['user'=>$u,'expired'=>$expired,'bot'=>$c['tg_bot']??null,'csrf'=>$_SESSION['csrf'],'generation'=>$generation,'hfReady'=>(!empty($c['hf_token'])&&function_exists('curl_init'))||modal_ready($c),'modalReady'=>modal_ready($c)]); }
 if($_SERVER['REQUEST_METHOD']==='POST')csrf();
 elseif(!in_array($action,['img','projects','project','versions','hf.result','hf.image','hf.latest','hf.project','img.batch','child.ready','book.reference','book.layout','books','book.production','book.production.image','book.kit','library','library.piece'],true))fail(405,'Метод не поддерживается.');
 if($action==='login') { $b=input();throttle($c);$login=strtolower(trim((string)($b['login']??'')));$idx=record_read($c['private_dir'].'/users.php',[]);$u=isset($idx[$login])?record_read($c['private_dir'].'/users/'.$idx[$login].'.php'):null;
 $hash=$u['hash']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi';$pw=(string)($b['password']??'');$valid=strlen($pw)<=200&&password_verify($pw,$hash);
 if(!$u||!$valid)fail(401,'Неверный логин или пароль.');if(empty($u['admin'])&&!empty($u['plan']))fail(403,'Для этого аккаунта вход только по ключу из Telegram-бота.');throttle($c,true);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['sid']=login_register($c,$u);login_notify($c,$u);$_SESSION['csrf']=bin2hex(random_bytes(24));reply(['user'=>user_public($u),'csrf'=>$_SESSION['csrf']]); }
 if($action==='login.key') { $b=input();throttle($c);$id=key_consume($c,(string)($b['key']??''));$u=$id?record_read(user_file($c,$id)):null;
  if(!$u)fail(401,'Ключ неверный или устарел. Получите новый в боте.');
  if(!access_state($u)['active'])fail(403,'Срок доступа закончился. Продли доступ в Telegram-боте.',['expired'=>true]);
  throttle($c,true);session_regenerate_id(true);$_SESSION['uid']=$u['id'];$_SESSION['sid']=login_register($c,$u);$_SESSION['csrf']=bin2hex(random_bytes(24));
  login_notify($c,$u);
  reply(['user'=>user_public($u),'csrf'=>$_SESSION['csrf']]); }
 $u=auth($c);
 if($action==='logout'){$_SESSION=[];session_destroy();reply(['ok'=>true]);}
 if($action==='hf.configure'){
  if(empty($u['admin']))fail(403,'Настройка доступна только владельцу.');$b=input();$token=trim((string)($b['token']??''));if($token!==''&&!preg_match('/^hf_[A-Za-z0-9]{10,200}$/',$token))fail(400,'Проверь формат HF-токена.');
  locked(__DIR__.'/.install.lock',function()use($token){$cfg=config();$cfg['hf_token']=$token;record_write(__DIR__.'/.memorybox-config.php',$cfg);});reply(['ok'=>true,'hfReady'=>$token!==''&&function_exists('curl_init')]);
 }
 if($action==='modal.configure'){
  if(empty($u['admin']))fail(403,'Настройка доступна только владельцу.');$b=input();$url=trim((string)($b['url']??''));$key=trim((string)($b['key']??''));$secret=trim((string)($b['secret']??''));
  if($url!==''&&!preg_match('~^https://[a-z0-9-]+-start(\.[a-z0-9-]+)?\.modal\.run$~D',$url))fail(400,'Адрес должен быть вида https://…-start.us-east.modal.run');
  if($url!==''&&(!preg_match('/^wk-[A-Za-z0-9]{6,100}$/D',$key)||!preg_match('/^ws-[A-Za-z0-9]{6,100}$/D',$secret)))fail(400,'Нужны Token ID (wk-…) и Token Secret (ws-…) из раздела Proxy tokens в Modal.');
  locked(__DIR__.'/.install.lock',function()use($url,$key,$secret){$cfg=config();$cfg['modal_start']=$url;$cfg['modal_key']=$url===''?'':$key;$cfg['modal_secret']=$url===''?'':$secret;record_write(__DIR__.'/.memorybox-config.php',$cfg);});
  reply(['ok'=>true,'modalReady'=>$url!==''&&function_exists('curl_init')]);
 }
 if($action==='prompts.list'){$p=record_read($c['private_dir'].'/prompts.php',null);reply(['items'=>is_array($p)?$p:prompts_default()]);}
 if($action==='prompts.save'){
  if(empty($u['admin']))fail(403,'Только владелец.');$b=input();$in=$b['items']??null;if(!is_array($in)||count($in)>30)fail(400,'Нужен список подсказок, не больше 30.');
  $out=[];foreach($in as $it){$t=trim((string)($it['title']??''));$x=trim((string)($it['text']??''));if($t===''||$x==='')continue;if(strlen($t)>240||strlen($x)>16000)fail(400,'Название до 80 символов, текст до 5000.');$out[]=['title'=>$t,'text'=>$x];}
  locked(__DIR__.'/.install.lock',function()use($c,$out){record_write($c['private_dir'].'/prompts.php',$out);});reply(['items'=>$out]);
 }
 if(in_array($action,['tg.status','tg.configure','tg.linkcode','access.list','access.set'],true)){
  if(empty($u['admin']))fail(403,'Только владелец.');
  $dateRu=fn(?int $t)=>$t?date('d.m.Y',$t):'';
  if($action==='tg.status'){
   $hook=null;if(tg_ready($c)){try{$r=tg_call($c,'getWebhookInfo');$hook=['url'=>$r['result']['url']??'','pending'=>$r['result']['pending_update_count']??0,'error'=>$r['result']['last_error_message']??null];}catch(Throwable $e){$hook=['error'=>$e->getMessage()];}}
   reply(['ready'=>tg_ready($c),'bot'=>$c['tg_bot']??null,'provider'=>!empty($c['tg_provider']),'providerTest'=>str_contains((string)($c['tg_provider']??''),':TEST:'),'adminLinked'=>!empty($c['tg_admin']),'pay'=>(string)($c['tg_pay']??''),'webhook'=>$hook,'curl'=>function_exists('curl_init')]);
  }
  if($action==='tg.configure'){
   $b=input();$token=trim((string)($b['token']??''));$pay=trim((string)($b['pay']??''));if(strlen($pay)>1500)fail(400,'Реквизиты: не больше 1500 символов.');
   $new=[];
   if($token!==''){
    if(!preg_match('/^\d{6,12}:[A-Za-z0-9_-]{30,50}$/D',$token))fail(400,'Токен бота выглядит так: 123456789:AA… (его выдаёт @BotFather).');
    if(!function_exists('curl_init'))fail(503,'На хостинге выключен curl: бот работать не сможет.');
    $probe=$c;$probe['tg_token']=$token;$me=tg_call($probe,'getMe');if(empty($me['ok']))fail(400,'Telegram не принял токен: '.($me['description']??'ошибка'));
    $secret=bin2hex(random_bytes(24));$url='https://'.($_SERVER['HTTP_HOST']??'memorybox.by').rtrim(dirname($_SERVER['SCRIPT_NAME']),'/').'/tg.php';
    $hook=tg_call($probe,'setWebhook',['url'=>$url,'secret_token'=>$secret,'allowed_updates'=>['message','callback_query','pre_checkout_query'],'drop_pending_updates'=>true]);
    if(empty($hook['ok']))fail(502,'Не удалось подключить бота: '.($hook['description']??'ошибка'));
    $new=['tg_token'=>$token,'tg_secret'=>$secret,'tg_bot'=>(string)($me['result']['username']??'')];
   }
   // Payment token from @BotFather → Payments → ЮKassa. Empty keeps the current one, "off" removes it.
   $prov=trim((string)($b['provider']??''));
   if($prov==='off')$new['tg_provider']='';
   elseif($prov!==''){
    if(!preg_match('/^\d{6,12}:(TEST|LIVE):[A-Za-z0-9_-]{4,80}$/D',$prov))fail(400,'Платёжный токен выглядит так: 381764678:LIVE:12345 (выдаёт @BotFather → Payments).');
    $new['tg_provider']=$prov;
    // Payments need the pre_checkout_query update: re-register the webhook with it.
    if($token===''&&tg_ready($c)){$url='https://'.($_SERVER['HTTP_HOST']??'memorybox.by').rtrim(dirname($_SERVER['SCRIPT_NAME']),'/').'/tg.php';$hook=tg_call($c,'setWebhook',['url'=>$url,'secret_token'=>$c['tg_secret'],'allowed_updates'=>['message','callback_query','pre_checkout_query']]);if(empty($hook['ok']))fail(502,'Не удалось обновить бота: '.($hook['description']??'ошибка'));}
   }
   locked(__DIR__.'/.install.lock',function()use($new,$pay){$cfg=config();foreach($new as $k=>$v)$cfg[$k]=$v;$cfg['tg_pay']=$pay;record_write(__DIR__.'/.memorybox-config.php',$cfg);});
   reply(['ok'=>true,'bot'=>$new['tg_bot']??($c['tg_bot']??null)]);
  }
  if($action==='tg.linkcode'){
   if(!tg_ready($c))fail(409,'Сначала подключи бота.');
   $a=key_alphabet();$code='';for($i=0;$i<8;$i++)$code.=$a[random_int(0,strlen($a)-1)];
   locked($c['private_dir'].'/login-keys.lock',function()use($c,$code,$u){$p=$c['private_dir'].'/tg-link-codes.php';$all=record_read($p,[]);foreach($all as $h=>$r)if(($r['exp']??0)<time())unset($all[$h]);$all[hash_hmac('sha256',$code,$c['salt'])]=['user'=>$u['id'],'exp'=>time()+900];record_write($p,$all);});
   reply(['code'=>$code,'bot'=>$c['tg_bot']??null]);
  }
  if($action==='access.list'){
   $list=[];foreach(users_index($c) as $login=>$id){$r=record_read(user_file($c,(string)$id));if($r)$list[]=access_public($r);}
   usort($list,fn($a,$b)=>[$b['admin'],$a['login']]<=>[$a['admin'],$b['login']]);reply(['users'=>$list]);
  }
  if($action==='access.set'){
   $b=input();try{$r=access_set($c,(string)($b['user']??''),(string)($b['plan']??''));}catch(RuntimeException $e){fail(400,$e->getMessage());}
   if(tg_ready($c)&&!empty($r['telegram']['id'])){$a=access_state($r);$msg=$b['plan']==='lifetime'?'Доступ к студии теперь навсегда.':($b['plan']==='off'?'Доступ к студии отключён.':'Доступ к студии продлён до '.$dateRu($a['until']).'.');try{tg_send($c,$r['telegram']['id'],tg_html($msg));}catch(Throwable $e){}}
   reply(['user'=>access_public($r)]);
  }
 }
 if($action==='user.create'){if(empty($u['admin']))fail(403,'Только владелец может добавлять аккаунты.');$b=input();$new=make_user($c,(string)($b['login']??''),(string)($b['password']??''));reply(['user'=>user_public($new)]);}
 if($action==='password'){ $b=input();if(!password_verify((string)($b['old']??''),$u['hash']))fail(403,'Неверный текущий пароль.');$pw=(string)($b['password']??'');if(strlen($pw)<12||strlen($pw)>200)fail(400,'Новый пароль: 12–200 символов.');$u['hash']=password_hash($pw,PASSWORD_DEFAULT);record_write($c['private_dir'].'/users/'.$u['id'].'.php',$u);session_regenerate_id(true);reply(['ok'=>true]); }
 $root=$c['private_dir'].'/accounts/'.$u['id'];if(!is_dir($root))mkdir($root,0700,true);
 session_write_close(); // Never block a second tab while waiting for GPU or a project upload.
 if($action==='storage.status'||$action==='storage.configure'||$action==='storage.migrate'||$action==='storage.gc'){
  if(empty($u['admin']))fail(403,'Настройка доступна только владельцу.');
  $status=function(?array $fresh=null)use($c){if($fresh!==null)$c=$fresh;$local=0;$localBytes=0;$cloud=0;$cloudBytes=0;foreach(image_all($c) as $h=>$f){if(substr($f,-3)==='.s3'){$cloud++;$cloudBytes+=image_size($c,$h);}else{$local++;$localBytes+=(int)filesize($f);}}
   return ['ready'=>s3_ready($c),'endpoint'=>$c['s3_endpoint']??'','bucket'=>$c['s3_bucket']??'','region'=>$c['s3_region']??'','localImages'=>$local,'localBytes'=>$localBytes,'cloudImages'=>$cloud,'cloudBytes'=>$cloudBytes,'quotaMB'=>(int)($c['image_quota_mb']??2048)];};
  if($action==='storage.status')reply($status());
  if($action==='storage.migrate'){set_time_limit(60);$r=image_migrate_step($c,25);reply($r+['status'=>$status()]);}
  if($action==='storage.gc'){set_time_limit(120);$b=input();$r=image_gc($c,!empty($b['dry']));reply($r+['status'=>$status()]);}
  $b=input();$endpoint=trim((string)($b['endpoint']??''));$bucket=trim((string)($b['bucket']??''));$key=trim((string)($b['key']??''));$secret=trim((string)($b['secret']??''));$quota=(int)($b['quotaMB']??($c['image_quota_mb']??2048));$region=trim((string)($b['region']??''));
  if($region!==''&&!preg_match('/^[a-z0-9-]{2,40}$/D',$region))fail(400,'Регион: латиница, цифры, дефисы (например eu-central-003 или auto).');
  if($quota<50||$quota>1048576)fail(400,'Лимит на человека: от 50 МБ до 1 ТБ.');
  if($endpoint===''){
   if(glob($c['private_dir'].'/images/*/*.s3'))fail(409,'Часть картинок уже лежит в облаке. Сначала верни доступ к хранилищу или перенеси их обратно, иначе они пропадут из проектов.');
   locked(__DIR__.'/.install.lock',function()use($quota){$cfg=config();foreach(['s3_endpoint','s3_bucket','s3_key','s3_secret','s3_region'] as $k)unset($cfg[$k]);$cfg['image_quota_mb']=$quota;record_write(__DIR__.'/.memorybox-config.php',$cfg);});
   reply(['ok'=>true,'status'=>$status(config())]);
  }
  if(!preg_match('~^(https?://)?[a-z0-9][a-z0-9.-]*(:\d{2,5})?/?$~Di',$endpoint))fail(400,'Адрес вида s3.eu-central-003.backblazeb2.com (без пути).');
  if(!preg_match('/^[a-z0-9][a-z0-9.-]{4,62}$/D',$bucket))fail(400,'Название bucket: латиница, цифры, дефисы.');
  if($key===''&&$secret===''&&!empty($c['s3_key'])){$key=$c['s3_key'];$secret=$c['s3_secret'];}
  if(!preg_match('~^[A-Za-z0-9/+=._-]{8,100}$~D',$key)||!preg_match('~^[A-Za-z0-9/+=._-]{8,200}$~D',$secret))fail(400,'Проверь keyID и applicationKey.');
  $test=$c;$test['s3_endpoint']=$endpoint;$test['s3_bucket']=$bucket;$test['s3_key']=$key;$test['s3_secret']=$secret;if($region!=='')$test['s3_region']=$region;else unset($test['s3_region']);
  if(!function_exists('curl_init'))fail(503,'На хостинге нужно включить PHP cURL.');
  // Prove the settings work (write, read back, delete) before saving them.
  try{$probe=bin2hex(random_bytes(16));$bytes='memorybox-check-'.$probe;$hash=hash('sha256',$bytes);$r=s3_call($test,'PUT','healthcheck/'.$probe.'.txt',$bytes,30);
   if($r['status']<200||$r['status']>=300)fail(400,'Хранилище отказало ('.s3_error_text($r).'). Проверь ключ, bucket и адрес.');
   $r=s3_call($test,'GET','healthcheck/'.$probe.'.txt',null,30);if($r['status']!==200||$r['body']!==$bytes)fail(400,'Запись прошла, но прочитать обратно не удалось ('.s3_error_text($r).').');
   s3_call($test,'DELETE','healthcheck/'.$probe.'.txt',null,30);
  }catch(RuntimeException $e){fail(502,$e->getMessage().'. Проверь адрес хранилища.');}
  locked(__DIR__.'/.install.lock',function()use($endpoint,$bucket,$key,$secret,$quota,$region){$cfg=config();$cfg['s3_endpoint']=$endpoint;$cfg['s3_bucket']=$bucket;$cfg['s3_key']=$key;$cfg['s3_secret']=$secret;if($region!=='')$cfg['s3_region']=$region;else unset($cfg['s3_region']);$cfg['image_quota_mb']=$quota;record_write(__DIR__.'/.memorybox-config.php',$cfg);});
  reply(['ok'=>true,'status'=>$status(config())]);
 }
 if($action==='img'){
  $h=(string)($_GET['h']??'');if(!image_exists($c,$h))fail(404,'Картинка не найдена.');
  // small=1: the cached WebP preview (about 480 px) instead of the full picture, for covers and thumbnails.
  if(($_GET['small']??'')==='1'){$p=image_preview($c,$h);if($p){header('Content-Type: image/webp');header('Cache-Control: private, max-age=31536000, immutable');header('Content-Length: '.strlen($p['webp']));echo $p['webp'];exit;}}
  $bytes=image_bytes($c,$h);if($bytes===null)fail(502,'Картинка сейчас недоступна в хранилище. Попробуй чуть позже.');
  header('Content-Type: image/png');header('Cache-Control: private, max-age=31536000, immutable');header('Content-Length: '.strlen($bytes));echo $bytes;exit;
 }
 if($action==='img.batch'){
  // Several images in one request: the host lets only about one request per second through. Stops at ~6 MB or 20 s and lists the rest.
  $list=[];foreach(explode(',',(string)($_GET['h']??'')) as $x)if(preg_match('/^[a-f0-9]{64}$/D',$x)===1)$list[$x]=true;$list=array_keys($list);
  if(!$list||count($list)>40)fail(400,'Неверный список картинок.');
  if(($_GET['small']??'')==='1'){
   // Previews for the screen (a few KB each); the ones not made yet are produced here, stopping after ~20 s and listing the rest.
   $until=microtime(true)+20;$done=0;$rest=[];$missing=[];$failed=[];
   header('Content-Type: application/json; charset=utf-8');echo '{"previews":{';
   foreach($list as $h){
    if($done>0&&microtime(true)>$until){$rest[]=$h;continue;}
    if(!image_exists($c,$h)){$missing[]=$h;continue;}
    try{$p=image_preview($c,$h);}catch(Throwable $e){$p=null;}
    if(!$p){$failed[]=$h;continue;}
    echo ($done?',':'').'"'.$h.'":{"w":'.$p['w'].',"h":'.$p['h'].',"b":"'.base64_encode($p['webp']).'"}';$done++;unset($p);
   }
   echo '},"rest":'.json_encode($rest).',"missing":'.json_encode($missing).',"failed":'.json_encode($failed).'}';exit;
  }
  $until=microtime(true)+20;$used=0;$rest=[];$missing=[];$failed=[];$first=true;
  header('Content-Type: application/json; charset=utf-8');echo '{"images":{';
  foreach($list as $h){
   if(!$first&&($used>=6*1048576||microtime(true)>$until)){$rest[]=$h;continue;}
   if(!image_exists($c,$h)){$missing[]=$h;continue;}
   try{$bytes=image_bytes($c,$h);}catch(Throwable $e){$bytes=null;}
   if($bytes===null){$failed[]=$h;continue;}
   echo ($first?'':',').'"'.$h.'":"'.base64_encode($bytes).'"';$first=false;$used+=strlen($bytes);unset($bytes);
  }
  echo '},"rest":'.json_encode($rest).',"missing":'.json_encode($missing).',"failed":'.json_encode($failed).'}';exit;
 }
 if($action==='img.missing'){
  $b=input();$list=$b['hashes']??null;if(!is_array($list)||count($list)>1200)fail(400,'Неверный список картинок.');$missing=[];
  foreach($list as $h){if(!is_string($h)||!preg_match('/^[a-f0-9]{64}$/D',$h))fail(400,'Неверный список картинок.');if(!image_exists($c,$h))$missing[$h]=true;}
  reply(['missing'=>array_keys($missing)]);
 }
 if($action==='img.put'){
  // Sent as JSON with base64: the hosting firewall rejects raw image/* (and octet-stream) POST bodies.
  $b=input((int)(IMAGE_MAX_BYTES*1.4)+1024);$h=(string)($b['h']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$h))fail(400,'Неверная картинка.');
  $raw=base64_decode((string)($b['data']??''),true);if($raw===false||hash('sha256',$raw)!==$h)fail(400,'Картинка повредилась при загрузке. Повтори сохранение.');
  if(empty($u['admin'])&&!image_exists($c,$h)){
   $limit=image_quota_bytes($c);
   locked($root.'/owned.lock',function()use($c,$u,$h,$raw,$limit){$owned=owned_images($c,$u['id']);if(array_sum($owned)+strlen($raw)>$limit)fail(507,'Лимит картинок аккаунта ('.round($limit/1048576).' МБ) исчерпан. Удали ненужные проекты и повтори сохранение.');image_store($c,$raw);$owned[$h]=strlen($raw);record_write(owned_path($c,$u['id']),$owned);});
  }else image_store($c,$raw);
  reply(['ok'=>true]);
 }
 if($action==='book.reference'){
  $id=$_GET['id']??'';if(!in_array($id,['my-doll','cat-cafe','space-boy'],true))fail(400,'Неизвестная книга.');$book=record_read($c['private_dir'].'/books/'.$id.'.php');if(!$book)fail(404,'Книга не найдена.');reply(['reference'=>image_data_url($c,$book['snapshot']['reference']??null),'revision'=>$book['revision']]);
 }
 // The ready doll ("child") is stored as references to the picture store plus a tiny JPEG preview, so the
 // dialog can open at once; the big PNGs are only downloaded after the person chooses to use them.
 if($action==='child.ready'){
  $path=$root.'/ready-child.php';$record=record_read($path);
  // Records written by older versions hold both pictures inline (many MB); move them into the store once.
  if(is_array($record)&&(str_starts_with((string)($record['result']??''),'data:')||str_starts_with((string)($record['reference']??''),'data:'))){
   $conv=function($v)use($c){return (is_string($v)&&str_starts_with($v,'data:image/png;base64,'))?'img:'.image_store($c,(string)base64_decode(substr($v,22))):$v;};
   $record['result']=$conv($record['result']??null);$record['reference']=$conv($record['reference']??null);
   locked($root.'/save.lock',function()use($path,$record){record_write($path,$record);});
  }
  // Every doll the account has made, newest project first. Found by plain-text search in each project's
  // latest version (two picture names), so even large versions are never decoded. At most 6 are offered.
  $children=[];$seen=[];
  if(is_array($record)&&!empty($record['result'])){$record['record']=true;$children[]=$record;$seen[$record['result']]=0;}
  $candidates=[];foreach(glob($root.'/projects/*/meta.php')?:[] as $file){$m=record_read($file);if($m)$candidates[]=$m;}
  usort($candidates,fn($a,$b)=>strcmp($b['updatedAt'],$a['updatedAt']));
  foreach(array_slice($candidates,0,12) as $m){
   if(count($children)>=6)break;
   $file=$root.'/projects/'.$m['id'].'/versions/'.$m['revision'].'.php';if(!is_file($file))continue;$raw=file_get_contents($file);if($raw===false)continue;
   if(!preg_match('/"result":"img:([a-f0-9]{64})"/',$raw,$r)||!preg_match('/"reference":"img:([a-f0-9]{64})"/',$raw,$f)){unset($raw);continue;}
   $mm=preg_match('/"dollMM":"?([0-9]+(?:\.[0-9]+)?)/',$raw,$d)?(float)$d[1]:160.0;unset($raw);
   $key='img:'.$r[1];
   if(isset($seen[$key])){ // same doll as the saved record: borrow the project's preview if the record has none
    $i=$seen[$key];if(empty($children[$i]['thumb'])&&!empty($m['thumb']))$children[$i]['thumb']=$m['thumb'];continue;
   }
   if(!image_exists($c,$r[1])||!image_exists($c,$f[1]))continue;
   $seen[$key]=count($children);
   $children[]=['result'=>$key,'reference'=>'img:'.$f[1],'template'=>$f[1],'dollMM'=>$mm,'label'=>(string)($m['title']??'Кукла'),'savedAt'=>$m['updatedAt'],'thumb'=>$m['thumb']??null,'project'=>$m['id']];
  }
  // Nothing saved as a record yet: remember the newest doll so the next call is instant.
  if(!is_array($record)&&$children){$first=$children[0];unset($first['project'],$first['record']);locked($root.'/save.lock',function()use($path,$first){record_write($path,$first);});$children[0]['record']=true;}
  reply(['child'=>$children[0]??null,'children'=>$children]);
 }
 if($action==='child.save'){
  $b=input(MAX_PROJECT_BYTES);
  $ref=function($v)use($c){
   if($v===null)return null;
   if(is_string($v)&&preg_match('/^img:[a-f0-9]{64}$/D',$v)){if(!image_exists($c,substr($v,4)))fail(409,'Картинка куклы не найдена на сервере. Повтори сохранение.');return $v;}
   if(is_string($v)&&str_starts_with($v,'data:image/png;base64,')&&strlen($v)<=22000000){$raw=base64_decode(substr($v,22),true);if($raw===false)fail(400,'Повреждена картинка куклы.');return 'img:'.image_store($c,$raw);}
   fail(400,'Неверная картинка куклы.');
  };
  $result=$ref($b['result']??null);if($result===null)fail(400,'Нет готовой куклы.');
  $key=$b['template']??'';if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key))fail(400,'Нужен эталон куклы.');
  $mm=$b['dollMM']??0;if(!is_numeric($mm)||$mm<30||$mm>500)fail(400,'Неверный размер куклы.');
  $reference=$ref($b['reference']??null);$label=(string)($b['label']??'Готовая кукла');if(strlen($label)>300)fail(400,'Слишком длинное название.');
  $thumb=$b['thumb']??null;if($thumb!==null&&(!is_string($thumb)||strlen($thumb)>150000||!preg_match('~^data:image/jpeg;base64,[A-Za-z0-9+/=]{20,}$~D',$thumb)))$thumb=null;
  locked($root.'/save.lock',function()use($root,$result,$key,$mm,$label,$reference,$thumb){record_write($root.'/ready-child.php',['result'=>$result,'reference'=>$reference,'template'=>$key,'dollMM'=>$mm,'label'=>$label,'thumb'=>$thumb,'savedAt'=>gmdate('c')]);});
  reply(['saved'=>true]);
 }
 if($action==='child.thumb'){
  $b=input(200000);$t=$b['thumb']??'';if(!is_string($t)||strlen($t)>150000||!preg_match('~^data:image/jpeg;base64,[A-Za-z0-9+/=]{20,}$~D',$t))fail(400,'Неверная миниатюра.');
  locked($root.'/save.lock',function()use($root,$t){$p=$root.'/ready-child.php';$d=record_read($p);if(!is_array($d))return;$d['thumb']=$t;record_write($p,$d);});reply(['ok'=>true]);
 }
 if($action==='projects'){ $items=[];foreach(glob($root.'/projects/*/meta.php')?:[] as $p){$m=record_read($p);if($m)$items[]=$m;}usort($items,fn($a,$b)=>strcmp($b['updatedAt'],$a['updatedAt']));reply(['projects'=>$items,'usedBytes'=>dir_size($root),'quotaBytes'=>USER_QUOTA]); }
 if($action==='versions'||$action==='project'){
  $id=project_id($_GET['id']??null);$dir=$root.'/projects/'.$id;$meta=record_read($dir.'/meta.php');if(!$meta)fail(404,'Проект не найден.');
  if($action==='versions'){ $versions=[];foreach(glob($dir.'/versions/*.php')?:[] as $p){$v=record_read($p);if($v)$versions[]=['revision'=>$v['revision'],'savedAt'=>$v['savedAt']];}usort($versions,fn($a,$b)=>$b['revision']<=>$a['revision']);reply(['project'=>$meta,'versions'=>$versions]); }
  $rev=isset($_GET['revision'])?(int)$_GET['revision']:$meta['revision'];if($rev<1)fail(400,'Неверная версия.');$v=record_read($dir.'/versions/'.$rev.'.php');if(!$v)fail(404,'Версия не найдена.');reply(['project'=>$meta,'version'=>$v]);
 }
 if($action==='book.production'||$action==='book.production.image'){
  $id=$_GET['id']??'';if(!in_array($id,['my-doll','cat-cafe','space-boy'],true))fail(400,'Неизвестная книга.');
  $base=$c['private_dir'].'/books-production/'.$id;
  if($action==='book.production')reply(['production'=>record_read($base.'/current.php')]);
  $rev=$_GET['revision']??'';$key=$_GET['key']??'';if(!is_string($rev)||!preg_match('/^[0-9]{1,16}$/D',$rev)||!in_array($key,['cover','spread1','spread2','spread3','guide'],true))fail(400,'Неверный файл.');
  $preview=($_GET['preview']??'')==='1';$path=$base.'/'.$rev.'/'.($preview?'preview-':'').$key.'.jpg';if(!is_file($path))fail(404,'Файл разворота не найден.');
  header('Content-Type: image/jpeg');header('Cache-Control: private, max-age=31536000, immutable');header('Content-Length: '.filesize($path));if(($_GET['download']??'')==='1')header('Content-Disposition: attachment; filename="'.$key.'.jpg"');readfile($path);exit;
 }
 if($action==='books'){
  $catalog=[['id'=>'my-doll','title'=>'Моя куколка','gender'=>'girl'],['id'=>'cat-cafe','title'=>'Кото-кафе','gender'=>'girl'],['id'=>'space-boy','title'=>'Космонавт','gender'=>'boy']];
  foreach($catalog as &$item){$book=record_read($c['private_dir'].'/books/'.$item['id'].'.php');$item['ready']=!!$book;if($book){$item['revision']=$book['revision'];$key=image_key($book['snapshot']['reference']??null);if($key)$item['referenceKey']=$key;$item['pages']=count($book['snapshot']['bookLayout']['pages']);$item['details']=count($book['snapshot']['pieces']);$prod=$book['snapshot']['production']??record_read($c['private_dir'].'/books-production/'.$item['id'].'/current.php');if($prod){if(!empty($prod['previewOnly'])){$cover=image_key($prod['files'][0]['image']??null);if($cover)$item['coverImage']=$cover;}else $item['productionRevision']=$prod['revision'];}}}unset($item);
  // Books taken out of the catalog stay on the server (projects made from them still open) but are not offered any more.
  $catalog=array_values(array_filter($catalog,fn($i)=>!in_array($i['id'],HIDDEN_BOOKS,true)));reply(['books'=>$catalog]);
 }
 // Book step: a book chosen after the wardrobe can bring its own pieces to cut (Кото-кафе). They are the pieces of its old catalog
 // record (never a doll); the backgrounds ship with the site in books/<id>/.
 if($action==='book.kit'){
  $id=$_GET['id']??'';if(!in_array($id,['my-doll','cat-cafe'],true))fail(400,'Неизвестная книга.');
  if($id!=='cat-cafe')reply(['pieces'=>[],'dollMM'=>160]);
  $path=$c['private_dir'].'/books/'.$id.'.php';$book=record_read($path);if(!$book)reply(['pieces'=>[],'dollMM'=>160]);
  if(strpos(json_encode($book['snapshot'],JSON_UNESCAPED_SLASHES),'data:image/png')!==false)$book=locked($c['private_dir'].'/books.lock',function()use($c,$path){$book=record_read($path);$book['snapshot']=externalize_snapshot($c,$book['snapshot']);record_write($path,$book);return $book;});
  $s=$book['snapshot'];$pieces=[];
  foreach($s['pieces'] as $p){if(!is_array($p)||($p['role']??null)==='doll'||(($p['role']??null)!=='item'&&preg_match('/^kukla(?:_|$)/i',(string)($p['name']??''))))continue;$p['role']='item';unset($p['fit']);$pieces[]=$p;}
  reply(['pieces'=>$pieces,'dollMM'=>(float)($s['settings']['dollMM']??160)?:160,'layout'=>record_read($c['private_dir'].'/books/'.$id.'-layout.php',null)]);
 }
 // The owner places the book's own pieces once: which scene each one belongs to (it is printed under that scene, like the
 // Canva layout: nails under the hand, tables under the café) and the size of all of them against the 21 cm scene.
 if($action==='book.layout'){$id=(string)($_GET['id']??'');if($id!=='cat-cafe')reply(['layout'=>null]);reply(['layout'=>record_read($c['private_dir'].'/books/'.$id.'-layout.php',null)]);}
 if($action==='book.layout.save'){
  if(empty($u['admin']))fail(403,'Только владелец.');$b=input();$id=(string)($b['id']??'');if($id!=='cat-cafe')fail(400,'Неизвестная книга.');
  $scale=(float)($b['scale']??0);if($scale<0.3||$scale>1.5)fail(400,'Масштаб деталей: от 0,3 до 1,5.');
  $scenes=$b['scenes']??[];if(!is_array($scenes)||count($scenes)>500)fail(400,'Неверный список деталей.');$out=[];
  foreach($scenes as $name=>$scene){if(!is_string($name)||strlen($name)>120||!is_int($scene)||$scene<0||$scene>7)fail(400,'Неверная сцена у детали.');$out[$name]=$scene;}
  locked($c['private_dir'].'/books.lock',function()use($c,$id,$scale,$out){record_write($c['private_dir'].'/books/'.$id.'-layout.php',['scale'=>$scale,'scenes'=>$out,'savedAt'=>gmdate('c')]);});
  reply(['ok'=>true]);
 }
 // Clothes library: its own store (library-items.php), separate from projects. The owner adds sheets of cut-out items, sorts them
 // and removes them; the fitting room shows what is there. Never a doll (a doll carries a child's face). The owner can hide entries.
 if($action==='library'||$action==='library.piece'||$action==='library.hide'||$action==='library.kind'||$action==='library.scale'||$action==='library.add'||$action==='library.delete'||$action==='library.autokind'){
  $hiddenPath=$c['private_dir'].'/library-hidden.php';$kindsPath=$c['private_dir'].'/library-kinds.php';
  // The owner sorts the library: what kind of thing an item is decides where it sits on the doll in the fitting room.
  if($action==='library.kind'){
   if(empty($u['admin']))fail(403,'Библиотеку правит только владелец.');$b=input();
   // One item ({h, kind}) or many at once ({kinds: {h: kind}}): the host lets only about one request per second through.
   $set=is_array($b['kinds']??null)?$b['kinds']:[(string)($b['h']??'')=>(string)($b['kind']??'')];if(!$set||count($set)>500)fail(400,'Неверный список вещей.');
   foreach($set as $h=>$kind){if(!is_string($h)||!preg_match('/^[a-f0-9]{64}$/D',$h))fail(400,'Неверная вещь.');if(!is_string($kind)||($kind!==''&&!in_array($kind,['dress','top','robe','pants','shorts','skirt','outfit','shoes','hat','accessory','animal','thing'],true)))fail(400,'Неизвестный тип вещи.');}
   locked($c['private_dir'].'/library.lock',function()use($kindsPath,$set){$list=record_read($kindsPath,[]);if(!is_array($list))$list=[];foreach($set as $h=>$kind){if($kind==='')unset($list[$h]);else $list[$h]=$kind;}record_write($kindsPath,$list);});
   reply(['ok'=>true,'count'=>count($set)]);
  }
  // The owner fits an item to the doll once (a bit smaller, or bigger to hide a toe): every master gets it at that size.
  $scalesPath=$c['private_dir'].'/library-scales.php';
  if($action==='library.scale'){
   if(empty($u['admin']))fail(403,'Библиотеку правит только владелец.');$b=input();$h=(string)($b['h']??'');$sc=(float)($b['scale']??1);
   if(!preg_match('/^[a-f0-9]{64}$/D',$h))fail(400,'Неверная вещь.');if($sc<0.5||$sc>1.6)fail(400,'Размер от 50 до 160 %.');
   locked($c['private_dir'].'/library.lock',function()use($scalesPath,$h,$sc){$list=record_read($scalesPath,[]);if(!is_array($list))$list=[];if(abs($sc-1)<0.001)unset($list[$h]);else $list[$h]=round($sc,3);record_write($scalesPath,$list);});
   reply(['ok'=>true]);
  }
  $scales=record_read($scalesPath,[]);if(!is_array($scales))$scales=[];
  $kinds=record_read($kindsPath,[]);if(!is_array($kinds))$kinds=[];
  if($action==='library.hide'){
   if(empty($u['admin']))fail(403,'Библиотеку правит только владелец.');$b=input();$h=(string)($b['h']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$h))fail(400,'Неверная вещь.');
   locked($c['private_dir'].'/library.lock',function()use($hiddenPath,$h,$b){$list=record_read($hiddenPath,[]);if(!is_array($list))$list=[];if(!empty($b['hide']))$list[$h]=true;else unset($list[$h]);record_write($hiddenPath,$list);});
   reply(['ok'=>true]);
  }
  $hidden=record_read($hiddenPath,[]);if(!is_array($hidden))$hidden=[];
  $store=library_store($c);
  // The owner adds a batch of cut-out items (their pictures are already in the picture store) or removes one.
  if($action==='library.add'||$action==='library.delete'){
   if(empty($u['admin']))fail(403,'Библиотеку правит только владелец.');$b=input();
   if($action==='library.delete'){$h=(string)($b['h']??'');if(!preg_match('/^[a-f0-9]{64}$/D',$h))fail(400,'Неверная вещь.');
    locked($c['private_dir'].'/library.lock',function()use($c,$h){$st=library_store($c);unset($st['items'][$h]);record_write(library_store_path($c),$st);});reply(['ok'=>true]);}
   $list=$b['items']??null;if(!is_array($list)||!$list||count($list)>300)fail(400,'Нет вещей для добавления.');$source=trim((string)($b['source']??''));if($source===''||strlen($source)>240)$source='Библиотека';
   $doll=(float)($b['dollMM']??160);if($doll<60||$doll>400)fail(400,'Высота куклы от 60 до 400 мм.');$add=[];
   foreach($list as $p){
    if(!is_array($p)||!is_string($p['name']??null)||strlen($p['name'])>200||!is_numeric($p['wmm']??null)||!is_numeric($p['hmm']??null)||$p['wmm']<=0||$p['hmm']<=0||$p['wmm']>1000||$p['hmm']>1000)fail(400,'Повреждены размеры вещи.');
    foreach(['paper','film'] as $k)if(!is_array($p[$k]??null)||count($p[$k])>1000)fail(400,'Повреждены контуры вещи.');
    foreach(['frame','print'] as $k)if(!is_string($p[$k]??null)||!preg_match('/^img:([a-f0-9]{64})$/D',$p[$k],$mm)||!image_exists($c,$mm[1]))fail(400,'Картинка вещи не загрузилась. Повтори.');
    $h=substr($p['frame'],4);$add[$h]=['piece'=>['role'=>'item','name'=>$p['name'],'wmm'=>(float)$p['wmm'],'hmm'=>(float)$p['hmm'],'paper'=>$p['paper'],'film'=>$p['film'],'box'=>is_array($p['box']??null)?$p['box']:null,'printLayout'=>null,'print'=>$p['print'],'frame'=>$p['frame']],'dollMM'=>$doll,'source'=>$source,'added'=>gmdate('c')];
   }
   $n=locked($c['private_dir'].'/library.lock',function()use($c,$add){$st=library_store($c);foreach($add as $h=>$it)$st['items'][$h]=$it;if(strlen(json_encode($st))>40000000)fail(413,'Библиотека слишком большая.');record_write(library_store_path($c),$st);return count($st['items']);});
   reply(['ok'=>true,'added'=>count($add),'total'=>$n]);
  }
  // Sheets uploaded again: a new picture of the same garment gets the type its old picture had. Pictures are compared as 12×12
  // thumbnails (colour weighted by coverage) of the same shape; the thumbnails are kept, so a second run is quick.
  if($action==='library.autokind'){
   if(empty($u['admin']))fail(403,'Библиотеку правит только владелец.');set_time_limit(280);$debug=!empty(input()['debug']);
   $sigPath=$c['private_dir'].'/library-sigs.php';$sigs=record_read($sigPath,[]);if(!is_array($sigs))$sigs=[];
   $sig=function($h)use($c,&$sigs){if(array_key_exists($h,$sigs))return $sigs[$h];$im=@imagecreatefromstring(image_bytes($c,$h));if(!$im)return $sigs[$h]=null;
    $w=imagesx($im);$hh=imagesy($im);$t=imagecreatetruecolor(12,12);imagealphablending($t,false);imagesavealpha($t,true);imagefill($t,0,0,imagecolorallocatealpha($t,0,0,0,127));imagecopyresampled($t,$im,0,0,0,0,12,12,$w,$hh);
    $v=[];for($y=0;$y<12;$y++)for($x=0;$x<12;$x++){$px=imagecolorat($t,$x,$y);$a=(127-(($px>>24)&127))/127;array_push($v,(int)round((($px>>16)&255)*$a),(int)round((($px>>8)&255)*$a),(int)round(($px&255)*$a),(int)round(255*$a));}
    imagedestroy($t);imagedestroy($im);return $sigs[$h]=['r'=>$w/max(1,$hh),'v'=>$v];};
   $known=[];foreach($kinds as $h=>$k)if(is_string($h)&&preg_match('/^[a-f0-9]{64}$/D',$h))$known[$h]=$k;
   $set=[];$report=[];$t0=time();
   foreach($store['items'] as $h=>$it){if(isset($kinds[$h])||time()-$t0>200)continue;$a=$sig($h);if(!$a)continue;$best=null;$bd=1e9;
    foreach($known as $kh=>$k){if(!image_exists($c,$kh))continue;$b=$sig($kh);if(!$b||abs(log($a['r']/$b['r']))>.15)continue;$d=0;foreach($a['v'] as $i=>$x)$d+=abs($x-$b['v'][$i]);$d/=count($a['v']);if($d<$bd){$bd=$d;$best=$k;}}
    if($debug)$report[]=[$it['piece']['name']??'',$best,round($bd,1)];if($best!==null&&$bd<=12)$set[$h]=$best;}
   record_write($sigPath,$sigs);
   if($set&&!$debug)locked($c['private_dir'].'/library.lock',function()use($kindsPath,$set){$list=record_read($kindsPath,[]);if(!is_array($list))$list=[];foreach($set as $h=>$k)$list[$h]=$k;record_write($kindsPath,$list);});
   reply(['matched'=>count($set),'report'=>$report]);
  }
  $items=[];
  foreach($store['items'] as $h=>$it){
   if(!image_exists($c,$h))continue;$p=$it['piece'];
   if($action==='library.piece'){if(($_GET['h']??'')!==$h)continue;reply(['piece'=>$p,'dollMM'=>$it['dollMM'],'source'=>$it['source']]);}
   if(isset($hidden[$h])&&empty($u['admin']))continue;
   $items[]=['h'=>$h,'name'=>(string)($p['name']??''),'wmm'=>(float)($p['wmm']??0),'hmm'=>(float)($p['hmm']??0),'source'=>$it['source'],'book'=>false,'hidden'=>isset($hidden[$h]),'kind'=>$kinds[$h]??null,'dollMM'=>$it['dollMM'],'scale'=>(float)($scales[$h]??1),'added'=>$it['added']??null];
  }
  if($action==='library.piece')fail(404,'Вещь не найдена в библиотеке.');
  reply(['items'=>$items]);
 }
 if(in_array($action,['book.publish','book.delete','book.start'],true)){
  $b=input();$bookId=$b['book']??'';if(!in_array($bookId,['my-doll','cat-cafe','space-boy'],true))fail(400,'Неизвестная книга.');
  $path=$c['private_dir'].'/books/'.$bookId.'.php';
 if($action==='book.delete')fail(403,'Готовые книги защищены от удаления из каталога. Можно удалять только личные проекты.');
  if($action==='book.publish'){
   if(empty($u['admin']))fail(403,'Только владелец добавляет книги.');$source=project_id($b['source']??null);$layout=$b['layout']??null;
   $answer=locked($root.'/save.lock',function()use($root,$source,$b,$layout,$path,$c){
    $dir=$root.'/projects/'.$source;$m=record_read($dir.'/meta.php');if(!$m)fail(404,'Нет исходного проекта.');if(($b['expectedRevision']??null)!==$m['revision'])fail(409,'Проект изменился. Повтори после сохранения.');
    $v=record_read($dir.'/versions/'.$m['revision'].'.php');$s=externalize_snapshot($c,$v['snapshot']);if(!empty($s['personalization']['pending']))fail(400,'Сначала заверши создание куклы.');
    if(empty($s['template'])||empty($s['reference']))fail(400,'Нужны кукла-эталон и пурпурный шаблон куклы.');
    if(!is_array($layout)||!in_array([$layout['SW']??0,$layout['SH']??0],[[297,420],[320,450]],true)||!is_array($layout['pages']??null)||!count($layout['pages'])||count($layout['pages'])>100)fail(400,'Нет готовых печатных листов.');
    $map=[];$pieces=[];foreach($s['pieces'] as $i=>$p){$doll=($p['role']??null)==='doll'||(($p['role']??null)!=='item'&&preg_match('/^kukla(?:_|$)/i',$p['name']));if(!$doll){$map[$i]=count($pieces);$p['role']='item';$pieces[]=$p;}}
    if(!$pieces)fail(400,'В книге нет одежды.');$pages=[];$seen=[];$slots=[];
    foreach($layout['pages'] as $page){if(!is_array($page)||!count($page)||count($page)>500)fail(400,'Неверный лист.');$items=[];foreach($page as $it){$i=$it['index']??null;if(!is_int($i)||!isset($map[$i])||isset($seen[$i]))fail(400,'Деталь повторяется или не относится к одежде.');$p=$pieces[$map[$i]];$x=$it['x']??null;$y=$it['y']??null;$rot=$it['rot']??null;if(!is_numeric($x)||!is_numeric($y)||!is_bool($rot)||$x<0||$y<0||$x+($rot?$p['hmm']:$p['wmm'])>$layout['SW']+.1||$y+($rot?$p['wmm']:$p['hmm'])>$layout['SH']+.1)fail(400,'Деталь выходит за границы листа.');$seen[$i]=true;$items[]=['index'=>$map[$i],'x'=>$x,'y'=>$y,'rot'=>$rot];}$pages[]=$items;}
    if(count($seen)!==count($pieces))fail(400,'Не вся одежда включена в готовые листы.');
    foreach(($layout['dollSlots']??[]) as $slot){if(!is_array($slot)||!is_int($slot['page']??null)||!isset($pages[$slot['page']]))continue;$valid=true;foreach(['x','y','wmm','hmm'] as $key)if(!is_numeric($slot[$key]??null)||$slot[$key]<0||$slot[$key]>1000)$valid=false;if($valid&&is_bool($slot['rot']??null))$slots[]=$slot;}
    $s['pieces']=$pieces;$s['result']=null;$s['personalization']=null;$s['bookLayout']=['SW'=>$layout['SW'],'SH'=>$layout['SH'],'pages'=>$pages,'dollSlots'=>$slots];unset($s['bookInfo']);$s['savedAt']=gmdate('c');if(empty($s['production']))$s['production']=record_read($c['private_dir'].'/books-production/'.($b['book']??'').'/current.php');
    if(strlen(json_encode($s))>MAX_PROJECT_BYTES)fail(413,'Набор слишком большой.');
    return locked($c['private_dir'].'/books.lock',function()use($path,$s){$old=record_read($path);$book=['revision'=>max(($old['revision']??0)+1,(int)floor(microtime(true)*1000)),'snapshot'=>$s];record_write($path,$book);return ['revision'=>$book['revision'],'pages'=>count($s['bookLayout']['pages'])];});
   });reply($answer);
  }
  $book=record_read($path);if(!$book)fail(404,'Эта книга ещё не добавлена.');
  // Books published before the image store still hold inline images: move them once so copies are tiny.
  if(strpos(json_encode($book['snapshot'],JSON_UNESCAPED_SLASHES),'data:image/png')!==false)$book=locked($c['private_dir'].'/books.lock',function()use($c,$path){$book=record_read($path);$book['snapshot']=externalize_snapshot($c,$book['snapshot']);record_write($path,$book);return $book;});$id=project_id($b['requestId']??null);$title=trim((string)($b['title']??''));if($title===''||strlen($title)>300)fail(400,'Напиши название проекта.');$expected=$b['revision']??null;
  $answer=locked($root.'/save.lock',function()use($root,$book,$bookId,$id,$title,$expected){
   $fingerprint=hash('sha256',json_encode([$bookId,$expected,$title]));$dir=$root.'/projects/'.$id;$existing=record_read($dir.'/meta.php');if($existing){if(($existing['bookRequest']??null)!==$fingerprint)fail(409,'Номер проекта уже занят.');return ['project'=>$existing];}
   if($book['revision']!==$expected)fail(409,'Книга обновлена. Обнови каталог и выбери её заново.');
   $s=$book['snapshot'];$s['personalization']=['pending'=>true];$s['bookInfo']=['id'=>$bookId,'revision'=>$book['revision']];$now=gmdate('c');$s['savedAt']=$now;$raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(dir_size($root)+strlen($raw)+4096>USER_QUOTA)fail(507,'Недостаточно места в аккаунте. Удали ненужные проекты.');$stable=$s;unset($stable['savedAt']);$hash=hash('sha256',json_encode($stable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   record_write($dir.'/versions/1.php',['revision'=>1,'savedAt'=>$now,'snapshot'=>$s]);$meta=['id'=>$id,'title'=>$title,'revision'=>1,'createdAt'=>$now,'updatedAt'=>$now,'hash'=>$hash,'bookRequest'=>$fingerprint];record_write($dir.'/meta.php',$meta);return ['project'=>$meta];
  });reply($answer);
 }
 if($action==='project.clone'){
  $b=input();$source=project_id($b['source']??null);$id=project_id($b['requestId']??null);
  if($id===$source)fail(400,'Нужен отдельный номер копии.');
  $expected=$b['expectedRevision']??null;$title=trim((string)($b['title']??''));$target=$b['target']??null;
  if(!is_int($expected)||$expected<1||$title===''||strlen($title)>300||!is_string($target)||!preg_match('/^(auto|none|[0-9]{1,3})$/D',$target))fail(400,'Проверь название и выбранную куклу.');
  $fingerprint=hash('sha256',json_encode([$source,$expected,$title,$target]));
  $answer=locked($root.'/save.lock',function()use($root,$source,$expected,$title,$target,$id,$fingerprint,$c){
   $dir=$root.'/projects/'.$id;$existing=record_read($dir.'/meta.php');
   if($existing){if(($existing['cloneRequest']??'')!==$fingerprint)fail(409,'Номер копии уже использован.');return ['project'=>$existing,'reused'=>true];}
   $sourceDir=$root.'/projects/'.$source;$meta=record_read($sourceDir.'/meta.php');if(!$meta)fail(404,'Исходная книга не найдена.');
   if($meta['revision']!==$expected)fail(409,'Исходная книга изменилась на другом устройстве. Открой актуальную версию и повтори.');
   $v=record_read($sourceDir.'/versions/'.$expected.'.php');if(!$v)fail(404,'Версия исходной книги не найдена.');$s=externalize_snapshot($c,$v['snapshot']);
   if($target!=='auto'&&$target!=='none'&&!isset($s['pieces'][(int)$target]))fail(400,'Выбранная кукла не найдена.');
   $selected=0;foreach($s['pieces'] as $i=>&$piece){$doll=$target==='auto'?(($piece['role']??null)==='doll'||(($piece['role']??null)!=='item'&&preg_match('/^kukla(?:_|$)/i',$piece['name']))):($target!=='none'&&$i===(int)$target);$piece['role']=$doll?'doll':'item';if($doll)$selected++;}unset($piece);
   if($target==='auto'&&!$selected)fail(400,'В исходной книге не найдена кукла. Выбери её из списка.');
   $now=gmdate('Y-m-d\TH:i:s\Z');$s['result']=null;$s['personalization']=['pending'=>true];$s['savedAt']=$now;
   $raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);if(strlen($raw)>MAX_PROJECT_BYTES)fail(413,'Проект больше 50 МБ.');if(dir_size($root)+strlen($raw)+4096>USER_QUOTA)fail(507,'Не хватает места для копии. Скачай резервную копию и удали ненужные проекты.');
   $stable=$s;unset($stable['savedAt']);$hash=hash('sha256',json_encode($stable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   record_write($dir.'/versions/1.php',['revision'=>1,'savedAt'=>$now,'snapshot'=>$s]);
   $new=['id'=>$id,'title'=>$title,'revision'=>1,'createdAt'=>$now,'updatedAt'=>$now,'hash'=>$hash,'cloneRequest'=>$fingerprint];record_write($dir.'/meta.php',$new);return ['project'=>$new];
  });reply($answer);
 }
 if($action==='save'){
  $b=input(MAX_PROJECT_BYTES);$s=$b['snapshot']??null;if(!is_array($s))fail(400,'Нет проекта.');validate_snapshot($s);$s=externalize_snapshot($c,$s);snapshot_images_exist($c,$s);
  $title=trim((string)($b['title']??'Новый проект'));if($title===''||strlen($title)>300)fail(400,'Название: 1–100 символов.');$id=empty($b['id'])?bin2hex(random_bytes(16)):project_id($b['id']);$dir=$root.'/projects/'.$id;
  $answer=locked($root.'/save.lock',function()use($root,$b,$s,$title,$id,$dir){
   $meta=record_read($dir.'/meta.php');$current=$meta['revision']??0;$expected=$b['expectedRevision']??null;
   if(!is_int($expected)||$current!==$expected)fail(409,'Проект изменён на другом устройстве. Твоя локальная работа сохранена. Скачай её или сохрани отдельным проектом.',['currentRevision'=>$current]);
   $stable=$s;unset($stable['savedAt']);$hash=hash('sha256',json_encode($stable,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
   if($meta&&($meta['hash']??'')===$hash&&$meta['title']===$title)return ['project'=>$meta,'unchanged'=>true];
   $raw=json_encode($s,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(strlen($raw)>MAX_PROJECT_BYTES)fail(413,'Проект больше 50 МБ. Раздели его на несколько проектов.');prune_versions($dir,$current+1);if(dir_size($root)+strlen($raw)>USER_QUOTA)fail(507,'Место аккаунта закончилось. Скачай резервную копию и удали ненужный проект.');
   $rev=$current+1;$now=gmdate('Y-m-d\TH:i:s\Z');$version=['revision'=>$rev,'savedAt'=>$now,'snapshot'=>$s];record_write($dir.'/versions/'.$rev.'.php',$version);
   $meta=['id'=>$id,'title'=>$title,'revision'=>$rev,'createdAt'=>$meta['createdAt']??$now,'updatedAt'=>$now,'hash'=>$hash]+project_card($b);record_write($dir.'/meta.php',$meta);return ['project'=>$meta];
  });image_gc_maybe($c);reply($answer);
 }
 if($action==='delete'){$b=input();$id=project_id($b['id']??null);locked($root.'/save.lock',function()use($root,$id){$dir=$root.'/projects/'.$id;if(!is_dir($dir))fail(404,'Нет проекта.');$items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($items as $p){$p->isDir()?rmdir($p->getPathname()):unlink($p->getPathname());}rmdir($dir);});image_gc_maybe($c);reply(['ok'=>true]);}
 if($action==='hf.start'){
  foreach(array_merge(glob($root.'/job-images/*.png')?:[],glob($root.'/job-inputs/*.php')?:[]) as $cache){if(filemtime($cache)<time()-86400)@unlink($cache);}
  if(!function_exists('curl_init'))fail(503,'На хостинге нужно включить PHP cURL.');
  $useModal=modal_ready($c)&&(empty($c['hf_token'])||hf_quota_blocked($c));
  if(empty($c['hf_token'])&&!$useModal)fail(503,'Генерация не настроена: нет ни HF-токена, ни Modal.');
  $project=null;if(isset($_POST['projectId'])){$project=project_id($_POST['projectId']);if(!is_file($root.'/projects/'.$project.'/meta.php'))fail(404,'Проект для генерации не найден.');}
  foreach(['body','head'] as $k){$f=$_FILES[$k]??null;if(!$f||$f['error']!==UPLOAD_ERR_OK)fail(400,'Не загрузилась картинка '.$k.'. Проверь upload_max_filesize/post_max_size PHP.');if($f['size']>15728640)fail(413,'Каждая фотография должна быть меньше 15 МБ.');$info=@getimagesize($f['tmp_name']);if(!$info||!in_array($info['mime'],['image/png','image/jpeg','image/webp'],true)||$info[0]*$info[1]>40000000)fail(400,'Нужны JPG, PNG или WebP до 40 Мп.');}
  set_time_limit(100);
  // Keep the inputs for a day so a quota failure on Hugging Face can be retried on Modal.
  $attempt=(string)($_POST['attemptId']??'');$reserved=reserve_generation($root,$u,$attempt);$job=$reserved['job'];if($reserved['reused'])reply(['job'=>$job,'reused'=>true,'generation'=>$reserved['generation']]);
  // If sending to Hugging Face or Modal fails before the job is recorded, give the attempt back.
  register_shutdown_function(function()use($root,$u,$job){if(!is_file($root.'/jobs/'.$job.'.php'))refund_generation($root,$u,$job);});
  $inputs=['body'=>base64_encode(file_get_contents($_FILES['body']['tmp_name'])),'head'=>base64_encode(file_get_contents($_FILES['head']['tmp_name']))];
  if(modal_ready($c))record_write($root.'/job-inputs/'.$job.'.php',$inputs);
  modal_matte_warm($c);
  if($useModal){
   $call=modal_start($c,base64_decode($inputs['body']),base64_decode($inputs['head']));
   record_write($root.'/jobs/'.$job.'.php',['provider'=>'modal','call'=>$call,'created'=>time(),'project'=>$project]);if($project)record_write($root.'/project-jobs/'.$project.'.php',['job'=>$job,'created'=>time()]);reply(['job'=>$job,'generation'=>$reserved['generation']]);
  }
  // Gradio requires duplicate multipart fields named "files", rather than PHP's array notation.
  $boundary='memorybox'.bin2hex(random_bytes(16));$multipart='';foreach(['body','head'] as $k){$f=$_FILES[$k];$mime=mime_content_type($f['tmp_name']);$multipart.='--'.$boundary."\r\nContent-Disposition: form-data; name=\"files\"; filename=\"".$k.".png\"\r\nContent-Type: ".$mime."\r\n\r\n".file_get_contents($f['tmp_name'])."\r\n";}$multipart.='--'.$boundary."--\r\n";
  $paths=json_decode(remote_upload($c,$multipart,$boundary),true);if(!is_array($paths)||count($paths)!==2)fail(502,'Space не принял изображения.');
  $body=json_encode(['data'=>[['path'=>$paths[0],'meta'=>['_type'=>'gradio.FileData']],['path'=>$paths[1],'meta'=>['_type'=>'gradio.FileData']],-1]]);
  $call=json_decode(remote_call($c,'/gradio_api/call/swap','POST',$body),true);$event=$call['event_id']??'';if(!preg_match('/^[a-zA-Z0-9_-]{1,150}$/',$event))fail(502,'Space не вернул номер задачи.');
  record_write($root.'/jobs/'.$job.'.php',['event'=>$event,'created'=>time(),'project'=>$project]);if($project)record_write($root.'/project-jobs/'.$project.'.php',['job'=>$job,'created'=>time()]);reply(['job'=>$job,'generation'=>$reserved['generation']]);
 }
 if($action==='hf.reuse'){
  $b=input();$project=project_id($b['project']??null);$id=project_id($b['job']??null);if(!is_file($root.'/projects/'.$project.'/meta.php'))fail(404,'Проект не найден.');$job=record_read($root.'/jobs/'.$id.'.php');if(!$job||empty($job['output'])||time()-$job['created']>86400)fail(404,'Готовый результат недоступен.');
  if(!is_dir($root.'/project-jobs'))mkdir($root.'/project-jobs',0700,true);$answer=locked($root.'/project-jobs/'.$project.'.lock',function()use($root,$project,$id,$job){$old=record_read($root.'/project-jobs/'.$project.'.php');if($old){$existing=record_read($root.'/jobs/'.$old['job'].'.php');if(($existing['sourceJob']??null)===$id)return ['job'=>$old['job']];fail(409,'У этого проекта уже есть генерация.');}$copy=$job;$copy['project']=$project;$copy['sourceJob']=$id;$new=bin2hex(random_bytes(16));record_write($root.'/jobs/'.$new.'.php',$copy);record_write($root.'/project-jobs/'.$project.'.php',['job'=>$new,'created'=>time()]);return ['job'=>$new];});reply($answer);
 }
 if($action==='hf.project'){
  $project=project_id($_GET['project']??null);if(!is_file($root.'/projects/'.$project.'/meta.php'))fail(404,'Проект не найден.');
  $link=record_read($root.'/project-jobs/'.$project.'.php');if(!$link)fail(404,'Для проекта нет начатой генерации.');$id=project_id($link['job']??null);$job=record_read($root.'/jobs/'.$id.'.php');if(!$job||($job['project']??null)!==$project||time()-$job['created']>86400)fail(404,'Результат проекта недоступен.');reply(['job'=>$id,'ready'=>!empty($job['output']),'seed'=>$job['seed']??null]);
 }
 if($action==='hf.latest'){
  $latest=null;$id=null;
  foreach(glob($root.'/jobs/*.php')?:[] as $p){$job=record_read($p);if(!$job||empty($job['output'])||time()-$job['created']>86400)continue;if(!$latest||$job['created']>$latest['created']){$latest=$job;$id=basename($p,'.php');}}
  if(!$latest)fail(404,'Последний результат недоступен. Новые генерации уже будут сохраняться без склейки.');reply(['job'=>$id,'seed'=>$latest['seed']??null]);
 }
 if($action==='hf.result'||$action==='hf.image'){
  $id=project_id($_GET['job']??null);$path=$root.'/jobs/'.$id.'.php';$job=record_read($path);if(!$job||time()-$job['created']>86400)fail(404,'Задача не найдена или устарела.');
  if($action==='hf.result'){
   if(!isset($job['output'])&&($job['provider']??'hf')==='hf'){set_time_limit(260);$text=remote_call($c,'/gradio_api/call/swap/'.rawurlencode($job['event']),'GET',null,210);
    if(preg_match('/event: error\s*\r?\ndata: ([^\r\n]*)/',$text,$m)){
     if(!preg_match('/quota|ZeroGPU/i',$m[1])){refund_generation($root,$u,$id);fail(502,'Ошибка генерации в Space. Попытка не засчитана.');}
     // Free ZeroGPU minutes are over: remember it and send the same photos to Modal.
     hf_quota_block($c,$m[1]);$in=record_read($root.'/job-inputs/'.$id.'.php');
     if(!modal_ready($c)||!$in){refund_generation($root,$u,$id);fail(502,'Закончилась квота GPU. Попытка не засчитана — повтори позже.');}
     $job['provider']='modal';$job['call']=modal_start($c,base64_decode($in['body']),base64_decode($in['head']));record_write($path,$job);
    } else {
     if(!preg_match('/event: complete\s*\r?\ndata: ([^\r\n]*)/',$text,$m))fail(502,'Space не вернул готовую картинку.');$out=json_decode($m[1],true);$file=$out[0]['path']??null;if(!is_string($file)||$file===''||strlen($file)>2000)fail(502,'Неверный ответ Space.');$job['output']=$file;$job['seed']=$out[1]??null;record_write($path,$job);@unlink($root.'/job-inputs/'.$id.'.php');
    }
   }
   if(!isset($job['output'])&&($job['provider']??'')==='modal'){
    set_time_limit(260);$done=modal_wait($c,$job['call'],200,fn()=>refund_generation($root,$u,$id));if(!$done)fail(504,'Генерация ещё идёт. Повтори получение картинки через минуту.');
    $dir=$root.'/job-images';if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($dir.'/'.$id.'.png',$done['png'],LOCK_EX);
    $job['output']='modal';$job['seed']=$done['seed'];record_write($path,$job);@unlink($root.'/job-inputs/'.$id.'.php');
   }
   $img=hf_cached_image($c,$root,$id,$job);
   // Once per doll: the background goes by a real cut-out model (hair included); the studio keeps its own cleanup as the fallback.
   if(empty($job['matted'])&&empty($job['sourceJob'])&&substr($img,0,8)==="\x89PNG\r\n\x1a\n"){
    set_time_limit(200);$cut=modal_matte($c,$img);
    if($cut){$dir=$root.'/job-images';if(!is_dir($dir))mkdir($dir,0700,true);file_put_contents($dir.'/'.$id.'.png',$cut,LOCK_EX);$job['matted']=true;record_write($path,$job);}
   }
   reply(['job'=>$id,'seed'=>$job['seed']]);
  }
  if(empty($job['output']))fail(409,'Картинка ещё не готова.');$img=hf_cached_image($c,$root,$id,$job);if(substr($img,0,8)!=="\x89PNG\r\n\x1a\n")fail(502,'Space вернул не PNG.');header('Content-Type: image/png');echo $img;exit;
 }
 fail(404,'Неизвестное действие.');
} catch(Throwable $e){error_log('Memorybox API: '.get_class($e));fail(500,'Ошибка сервера. Работа в браузере не удалена. Проверь свободное место и права папки хранения.');}
function remote_upload(array $c,string $body,string $boundary): string {
 if(!function_exists('curl_init'))fail(503,'Нужно включить PHP cURL.');$ch=curl_init($c['hf_base'].'/gradio_api/upload');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$c['hf_token'],'Content-Type: multipart/form-data; boundary='.$boundary]]);$r=curl_exec($ch);$status=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($r===false||$status<200||$status>=300)fail(502,'Space не принял фото: HTTP '.$status.'.');return $r;
}

// What the projects list shows besides the title: a small doll preview and the number of clothing items.
function project_card(array $b): array {
 $card=[];$thumb=$b['thumb']??null;$items=$b['items']??null;
 if(is_string($thumb)&&strlen($thumb)<=60000&&preg_match('~^data:image/jpeg;base64,[A-Za-z0-9+/=]+$~D',$thumb))$card['thumb']=$thumb;
 if(is_int($items)&&$items>=0&&$items<=500)$card['items']=$items;
 return $card;
}
function hf_cached_image(array $c,string $root,string $id,array $job): string {
 $path=$root.'/job-images/'.$id.'.png';if(is_file($path))return file_get_contents($path);
 $source=$job['sourceJob']??null;for($depth=0;$depth<4&&is_string($source)&&preg_match('/^[a-f0-9]{32}$/D',$source);$depth++){if(is_file($root.'/job-images/'.$source.'.png'))return file_get_contents($root.'/job-images/'.$source.'.png');$parent=record_read($root.'/jobs/'.$source.'.php');$source=$parent['sourceJob']??null;}
 $img=remote_call($c,'/gradio_api/file='.rawurlencode($job['output']));if(substr($img,0,8)!=="\x89PNG\r\n\x1a\n")fail(502,'Space вернул не PNG.');
 if(dir_size($root)+strlen($img)<=USER_QUOTA){$dir=dirname($path);if(!is_dir($dir))mkdir($dir,0700,true);$tmp=tempnam($dir,'.png-');try{if(file_put_contents($tmp,$img,LOCK_EX)===strlen($img)){chmod($tmp,0600);rename($tmp,$path);}}finally{if(is_file($tmp))unlink($tmp);}}
 return $img;
}

function library_store_path(array $c): string { return $c['private_dir'].'/library-items.php'; }
// The library store; made once from the old sources (the owner's «Гардероб…»/«Библиотека…» projects), so nothing is lost: the
// types, sizes and hiding are kept by picture and stay valid.
function library_store(array $c): array {
 $st=record_read(library_store_path($c),null);if(is_array($st)&&is_array($st['items']??null))return $st;
 return locked($c['private_dir'].'/library.lock',function()use($c){
  $st=record_read(library_store_path($c),null);if(is_array($st)&&is_array($st['items']??null))return $st;
  $sources=[];
  foreach([['my-doll','Моя куколка'],['cat-cafe','Кото-кафе'],['space-boy','Космонавт']] as [$id,$title]){if(in_array($id,HIDDEN_BOOKS,true))continue;$book=record_read($c['private_dir'].'/books/'.$id.'.php');if($book)$sources['book:'.$id]=['title'=>$title,'snapshot'=>$book['snapshot']];}
  foreach(users_index($c) as $uid){if(!is_string($uid)||!preg_match('/^[a-f0-9]{32}$/D',$uid))continue;$r=record_read($c['private_dir'].'/users/'.$uid.'.php');if(empty($r['admin']))continue;
   // Only the owner's projects named «Гардероб…» (or the older «Библиотека…»): other projects hold test pieces and copies of old books.
   foreach(glob($c['private_dir'].'/accounts/'.$uid.'/projects/*/meta.php')?:[] as $mp){$m=record_read($mp);if(!$m||empty($m['id'])||!preg_match('/^\s*(гардероб|библиотека)/iu',(string)($m['title']??'')))continue;$v=record_read(dirname($mp).'/versions/'.$m['revision'].'.php');if($v&&is_array($v['snapshot']['pieces']??null))$sources['project:'.$m['id']]=['title'=>(string)$m['title'],'snapshot'=>$v['snapshot']];}}
  $st=['items'=>[]];
  foreach($sources as $src){$s=$src['snapshot'];$doll=(float)($s['settings']['dollMM']??160)?:160;
   foreach($s['pieces'] as $p){
    if(!is_array($p)||($p['role']??null)==='doll'||(($p['role']??null)!=='item'&&preg_match('/^kukla(?:_|$)/i',(string)($p['name']??''))))continue;
    $h=is_string($p['frame']??null)&&preg_match('/^img:([a-f0-9]{64})$/D',$p['frame'],$mm)?$mm[1]:null;
    if(!$h||isset($st['items'][$h])||!image_exists($c,$h))continue;
    $p['role']='item';unset($p['fit']);$st['items'][$h]=['piece'=>$p,'dollMM'=>$doll,'source'=>$src['title'],'added'=>gmdate('c')];
   }
  }
  record_write(library_store_path($c),$st);return $st;
 });
}
