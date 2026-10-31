<?php
// PHP 8.0+, no database server required. All records are executable-denied PHP envelopes.
declare(strict_types=1);
const ENVELOPE = "<?php http_response_code(404); exit; ?>\n";
const MAX_PROJECT_BYTES = 52428800;
const USER_QUOTA = 536870912;
const KEEP_VERSIONS = 10;
const GENERATION_LIMIT = 10;
// Daily generation cap per non-admin account; the Lipkotop demo wrapper raises it through $GLOBALS['MB_GEN_LIMIT'].
function generation_limit(): int { return max(1,(int)($GLOBALS['MB_GEN_LIMIT']??GENERATION_LIMIT)); }
function fail(int $code, string $message, array $extra = []): void { http_response_code($code); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['error'=>$message]+$extra, JSON_UNESCAPED_UNICODE); exit; }
function reply(array $data): void { header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function guard_headers(): void { header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: SAMEORIGIN'); header('Referrer-Policy: same-origin'); }
function record_read(string $path, $fallback = null) { if (!is_file($path)) return $fallback; $raw=file_get_contents($path); if ($raw===false || strncmp($raw,ENVELOPE,strlen(ENVELOPE))!==0) throw new RuntimeException('Повреждено хранилище'); return json_decode(substr($raw,strlen(ENVELOPE)),true,512,JSON_THROW_ON_ERROR); }
function record_write(string $path, $data): void {
 $dir=dirname($path); if (!is_dir($dir) && !mkdir($dir,0700,true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать папку');
 $tmp=tempnam($dir,'.tmp-'); if ($tmp===false) throw new RuntimeException('Нет места для сохранения');
 // Even temporary records have a PHP suffix; otherwise a web server could expose their content.
 $safe=$tmp.'.php'; rename($tmp,$safe);
 try { $raw=ENVELOPE.json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); if(file_put_contents($safe,$raw,LOCK_EX)!==strlen($raw))throw new RuntimeException('Не удалось записать файл'); chmod($safe,0600); if(!rename($safe,$path))throw new RuntimeException('Не удалось завершить сохранение'); } finally { if(is_file($safe))unlink($safe); }
}
function locked(string $path, callable $fn) { $h=fopen($path,'c+'); if(!$h || !flock($h,LOCK_EX))throw new RuntimeException('Хранилище занято'); try{return $fn();}finally{flock($h,LOCK_UN);fclose($h);} }
function config(): array { $c=record_read(__DIR__.'/.memorybox-config.php'); if(!is_array($c))fail(503,'Сначала открой setup.php и выполни установку.'); return $c; }
function session_begin(array $c): void {
 if(session_status()===PHP_SESSION_ACTIVE)return;
 ini_set('session.use_strict_mode','1'); ini_set('session.gc_maxlifetime','604800');
 $sessions=$c['private_dir'].'/sessions'; if(!is_dir($sessions))mkdir($sessions,0700,true);session_save_path($sessions);
 session_name('memorybox_'.substr(hash('sha256',__DIR__),0,12));
 $secure=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off';
 session_set_cookie_params(['lifetime'=>604800,'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']),'/').'/','secure'=>$secure,'httponly'=>true,'samesite'=>'Lax']);session_start();
 if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(24));
}
function auth(array $c): array { session_begin($c);$id=$_SESSION['uid']??'';if(!preg_match('/^[a-f0-9]{32}$/',$id))fail(401,'Войди в аккаунт.');$u=record_read($c['private_dir'].'/users/'.$id.'.php');if(!$u)fail(401,'Войди заново.');if(!access_state($u)['active'])fail(403,'Срок доступа закончился. Продли доступ в Telegram-боте.',['expired'=>true]);if(session_replaced($u))fail(401,'Вход выполнен на другом устройстве. Получите новый ключ в боте.',['replaced'=>true]);return $u; }
function csrf(): void { if(!hash_equals($_SESSION['csrf']??'',$_SERVER['HTTP_X_CSRF_TOKEN']??''))fail(403,'Страница устарела. Обнови её и повтори действие.'); }
function input(int $limit=1048576): array { $length=(int)($_SERVER['CONTENT_LENGTH']??0);if($length>$limit)fail(413,'Файл слишком большой для этого действия.');$stream=fopen('php://input','rb');$raw=stream_get_contents($stream,$limit+1);fclose($stream);if(strlen($raw)>$limit)fail(413,'Слишком большой запрос.');try{$v=json_decode($raw,true,512,JSON_THROW_ON_ERROR);}catch(Throwable $e){fail(400,'Не удалось прочитать запрос.');}if(!is_array($v))fail(400,'Неверный запрос.');return $v; }
function user_public(array $u): array { $a=access_state($u);return ['id'=>$u['id'],'login'=>$u['login'],'admin'=>!empty($u['admin']),'plan'=>$a['plan'],'until'=>$a['until']]; }
function generation_day(): string { return (new DateTimeImmutable('now',new DateTimeZone('Europe/Minsk')))->format('Y-m-d'); }
function generation_status(string $root,array $u): array {
 if(!empty($u['admin']))return ['limit'=>null,'used'=>0,'remaining'=>null];
 $s=record_read($root.'/generation-attempts.php',['day'=>generation_day(),'used'=>0]);
 $used=($s['day']??'')===generation_day()?max(0,min(generation_limit(),(int)($s['used']??0))):0;
 return ['limit'=>generation_limit(),'used'=>$used,'remaining'=>generation_limit()-$used];
}
function reserve_generation(string $root,array $u,string $request): array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$request))fail(400,'Обнови страницу и повтори запуск генерации.');
 if(!empty($u['admin']))return ['job'=>bin2hex(random_bytes(16)),'reused'=>false,'generation'=>generation_status($root,$u)];
 return locked($root.'/generation-attempts.lock',function()use($root,$u,$request){
  $day=generation_day();$s=record_read($root.'/generation-attempts.php',['day'=>$day,'used'=>0,'requests'=>[]]);
  $sameDay=($s['day']??'')===$day;$used=$sameDay?max(0,(int)($s['used']??0)):0;
  $requests=$sameDay&&is_array($s['requests']??null)?$s['requests']:[];
  if(isset($requests[$request]))return ['job'=>$requests[$request]['job'],'reused'=>true,'generation'=>['limit'=>generation_limit(),'used'=>$used,'remaining'=>max(0,generation_limit()-$used)]];
  if($used>=generation_limit())fail(429,'Лимит на сегодня исчерпан: доступно '.generation_limit().' '.(generation_limit()===3?'генерации':'генераций').' в сутки.',['generation'=>['limit'=>generation_limit(),'used'=>generation_limit(),'remaining'=>0]]);
  $job=bin2hex(random_bytes(16));$requests[$request]=['job'=>$job,'created'=>time()];
  record_write($root.'/generation-attempts.php',['day'=>$day,'used'=>$used+1,'requests'=>$requests]);
  return ['job'=>$job,'reused'=>false,'generation'=>['limit'=>generation_limit(),'used'=>$used+1,'remaining'=>generation_limit()-$used-1]];
 });
}
// Gives back today's attempt when its generation failed (never twice for the same job, never for another day).
function refund_generation(string $root,array $u,string $job): void {
 if(!empty($u['admin'])||!is_file($root.'/generation-attempts.php'))return;
 locked($root.'/generation-attempts.lock',function()use($root,$job){
  $s=record_read($root.'/generation-attempts.php',[]);if(($s['day']??'')!==generation_day()||!is_array($s['requests']??null))return;
  foreach($s['requests'] as $id=>$r){if(($r['job']??'')!==$job||!empty($r['refunded']))continue;$s['requests'][$id]['refunded']=true;$s['used']=max(0,(int)($s['used']??0)-1);record_write($root.'/generation-attempts.php',$s);return;}
 });
}
function dir_size(string $dir): int { if(!is_dir($dir))return 0;$n=0;$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS));foreach($it as $f)if($f->isFile())$n+=$f->getSize();return $n; }
// Keeps the newest KEEP_VERSIONS versions, counting the one about to be written as $nextRevision.
function prune_versions(string $dir, int $nextRevision): void { foreach(glob($dir.'/versions/*.php')?:[] as $f){$n=(int)basename($f,'.php');if($n>0&&$n<=$nextRevision-KEEP_VERSIONS)@unlink($f);} }
// Image store: every PNG is kept once, named by its SHA-256, outside the web root. Snapshots reference
// images as "img:<sha256>" so versions, project copies and books share the same files.
const IMAGE_MAX_BYTES = 15728640;
function image_path(array $c,string $hash): string { return $c['private_dir'].'/images/'.substr($hash,0,2).'/'.$hash.'.png'; }
function image_marker_path(array $c,string $hash): string { return $c['private_dir'].'/images/'.substr($hash,0,2).'/'.$hash.'.s3'; }
// An image is either a local PNG or, once moved to the cloud, a tiny ".s3" marker whose content is the PNG size in bytes.
function image_exists(array $c,string $hash): bool { return preg_match('/^[a-f0-9]{64}$/D',$hash)===1&&(is_file(image_path($c,$hash))||is_file(image_marker_path($c,$hash))); }
function image_store(array $c,string $bytes): string {
 if(strlen($bytes)>IMAGE_MAX_BYTES||strncmp($bytes,"\x89PNG\r\n\x1a\n",8)!==0)fail(400,'Нужна PNG-картинка до 15 МБ.');
 $hash=hash('sha256',$bytes);$path=image_path($c,$hash);if(image_exists($c,$hash))return $hash;
 $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Не удалось создать папку картинок');
 if(s3_ready($c)){
  // Cloud first; if the cloud is unreachable the picture is kept on the server so work is never lost.
  try{s3_put($c,$hash,$bytes);record_marker($c,$hash,strlen($bytes));return $hash;}catch(Throwable $e){error_log('Memorybox cloud put failed: '.$e->getMessage());}
 }
 $tmp=tempnam($dir,'.tmp-');if($tmp===false)throw new RuntimeException('Нет места для картинки');
 try{if(file_put_contents($tmp,$bytes,LOCK_EX)!==strlen($bytes))throw new RuntimeException('Не удалось записать картинку');chmod($tmp,0600);if(!rename($tmp,$path))throw new RuntimeException('Не удалось сохранить картинку');}finally{if(is_file($tmp))@unlink($tmp);}
 return $hash;
}
function record_marker(array $c,string $hash,int $size): void { $m=image_marker_path($c,$hash);$dir=dirname($m);if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Не удалось создать папку картинок');if(file_put_contents($m,(string)$size,LOCK_EX)===false)throw new RuntimeException('Не удалось записать отметку картинки');chmod($m,0600); }
// Moves inline data-URL images of a snapshot into the store (older clients and stored books still send them).
function externalize_snapshot(array $c,array $s): array {
 $one=function($v)use($c){if(is_string($v)&&str_starts_with($v,'data:image/png;base64,')){$raw=base64_decode(substr($v,22),true);if($raw===false)fail(400,'Повреждена картинка проекта.');return 'img:'.image_store($c,$raw);}return $v;};
 foreach(['result','reference','template'] as $k)if(array_key_exists($k,$s))$s[$k]=$one($s[$k]);
 if(is_array($s['pieces']??null))foreach($s['pieces'] as $i=>$p){if(!is_array($p))continue;foreach(['print','frame'] as $k)if(array_key_exists($k,$p))$s['pieces'][$i][$k]=$one($p[$k]);}
 return $s;
}
// Every image reference of a snapshot must point at a stored file.
function snapshot_images_exist(array $c,array $s): void {
 $refs=[];foreach(['result','reference','template'] as $k)$refs[]=$s[$k]??null;foreach($s['pieces'] as $p){$refs[]=$p['print']??null;$refs[]=$p['frame']??null;}
 foreach($refs as $v)if(is_string($v)&&str_starts_with($v,'img:')&&!image_exists($c,substr($v,4)))fail(409,'Картинка проекта не найдена на сервере. Повтори сохранение.');
}
// For endpoints that still hand single images to the browser as data URLs.
function image_data_url(array $c,$v) { if(is_string($v)&&preg_match('/^img:([a-f0-9]{64})$/D',$v,$m)){$b=image_bytes($c,$m[1]);return $b!==null?'data:image/png;base64,'.base64_encode($b):null;}return $v; }
// The template key of a reference is the SHA-256 of its PNG, which is exactly the store name.
function image_key($v): ?string { if(is_string($v)&&preg_match('/^img:([a-f0-9]{64})$/D',$v,$m))return $m[1];if(is_string($v)&&str_starts_with($v,'data:image/png;base64,'))return hash('sha256',(string)base64_decode(substr($v,22)));return null; }
function project_id($id): string { if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/',$id))fail(400,'Неверный номер проекта.');return $id; }
function validate_snapshot(array $s): void {
 if(($s['format']??'')!=='memorybox-kukla-project'||($s['version']??0)!==1||!isset($s['pieces'],$s['settings'])||!is_array($s['pieces'])||count($s['pieces'])>500||!is_array($s['settings']))fail(400,'Неверный проект.');
 $check=function($v){if($v!==null&&(!is_string($v)||!preg_match('~^(data:image/png;base64,[A-Za-z0-9+/=]+|img:[a-f0-9]{64})$~D',$v)))fail(400,'Неверная картинка проекта.');};
 foreach(['result','reference','template'] as $k)$check($s[$k]??null);
 foreach($s['pieces'] as $p){if(!is_array($p)||!is_string($p['name']??null)||strlen($p['name'])>600||!is_numeric($p['wmm']??null)||!is_numeric($p['hmm']??null)||$p['wmm']<=0||$p['hmm']<=0||!is_array($p['paper']??null)||!is_array($p['film']??null)||!is_array($p['box']??null))fail(400,'Повреждены детали.');$check($p['print']??null);$check($p['frame']??null);}
}
function throttle(array $c, bool $reset=false): void {
 $key=hash_hmac('sha256',$_SERVER['REMOTE_ADDR']??'local',$c['salt']);$p=$c['private_dir'].'/rate-'.$key.'.php';
 locked($c['private_dir'].'/rate.lock',function()use($p,$reset){$r=record_read($p,['start'=>time(),'count'=>0]);if(time()-$r['start']>900)$r=['start'=>time(),'count'=>0];if($reset){@unlink($p);return;}if($r['count']>=20)fail(429,'Слишком много попыток входа. Подожди 15 минут.');$r['count']++;record_write($p,$r);});
}
function make_user(array $c,string $login,string $password,bool $admin=false): array {
 $login=strtolower(trim($login));if(!preg_match('/^[a-z0-9_.-]{3,40}$/',$login))fail(400,'Логин: 3–40 латинских букв, цифр, точек, дефисов.');if(strlen($password)<12||strlen($password)>200)fail(400,'Пароль: от 12 до 200 символов.');
 return locked($c['private_dir'].'/users.lock',function()use($c,$login,$password,$admin){$idx=record_read($c['private_dir'].'/users.php',[]);if(isset($idx[$login]))fail(409,'Этот логин уже существует.');$u=['id'=>bin2hex(random_bytes(16)),'login'=>$login,'hash'=>password_hash($password,PASSWORD_DEFAULT),'admin'=>$admin];record_write($c['private_dir'].'/users/'.$u['id'].'.php',$u);$idx[$login]=$u['id'];record_write($c['private_dir'].'/users.php',$idx);return $u;});
}
// Modal is the paid fallback when the free Hugging Face ZeroGPU quota runs out.
function modal_ready(array $c): bool { return !empty($c['modal_start'])&&!empty($c['modal_key'])&&!empty($c['modal_secret'])&&function_exists('curl_init'); }
function modal_request(array $c,string $url,?string $json,int $timeout=60): array {
 $ch=curl_init($url);$headers=['Modal-Key: '.$c['modal_key'],'Modal-Secret: '.$c['modal_secret']];if($json!==null){$headers[]='Content-Type: application/json';curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json]);}
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headers]);
 $r=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($r===false)fail(502,'Не удалось связаться с Modal.');if($status===401||$status===403)fail(502,'Modal отклонил ключ доступа. Проверь токен в «Администрировании».');if($status===402||$status===429)fail(503,'Лимит генераций на этот месяц исчерпан. Новые генерации станут доступны после обновления лимита.');
 if($status<200||$status>=300)fail(502,'Modal вернул HTTP '.$status.'.');$j=json_decode((string)$r,true);if(!is_array($j))fail(502,'Неверный ответ Modal.');return $j;
}
function modal_start(array $c,string $body,string $head): string {
 $j=modal_request($c,$c['modal_start'],json_encode(['body'=>base64_encode($body),'head'=>base64_encode($head),'seed'=>-1]));$id=$j['call_id']??'';
 if(!is_string($id)||!preg_match('/^fc-[A-Za-z0-9]{6,80}$/',$id))fail(502,'Modal не вернул номер задачи.');return $id;
}
// Background removal of a finished doll (BiRefNet on Modal, the "matte" endpoint next to "start"). Never fatal: on any
// problem it returns null and the studio's own cleanup in the browser does the job as before.
function modal_matte_call(array $c,array $payload,int $timeout): ?array {
 if(!modal_ready($c))return null;$url=preg_replace('/-start(\.[a-z0-9-]+)?\.modal\.run$/','-matte$1.modal.run',$c['modal_start']);if($url===$c['modal_start'])return null;
 $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>['Modal-Key: '.$c['modal_key'],'Modal-Secret: '.$c['modal_secret'],'Content-Type: application/json']]);
 $r=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($r===false||$status<200||$status>=300)return null;$j=json_decode((string)$r,true);return is_array($j)?$j:null;
}
// Wakes the background GPU while the doll is generated, so cutting the result costs no waiting.
function modal_matte_warm(array $c): void { modal_matte_call($c,['warm'=>true],4); }
// The hosting's line to Modal is slow: a JPEG goes there (~1 MB instead of ~3.5 MB), an RGBA WebP comes back and is turned
// into the PNG the studio expects here.
function modal_matte(array $c,string $png): ?string {
 if(!function_exists('imagecreatefromstring')||!function_exists('imagewebp'))return null;
 $src=@imagecreatefromstring($png);if(!$src)return null;ob_start();imagejpeg($src,null,95);$jpeg=ob_get_clean();imagedestroy($src);
 $j=modal_matte_call($c,['img'=>base64_encode($jpeg)],150);$webp=is_string($j['webp']??null)?base64_decode($j['webp'],true):false;
 if($webp===false||substr($webp,0,4)!=='RIFF')return null;$im=@imagecreatefromstring($webp);if(!$im)return null;
 imagealphablending($im,false);imagesavealpha($im,true);ob_start();imagepng($im,null,3);$out=ob_get_clean();imagedestroy($im);
 return substr($out,0,8)==="\x89PNG\r\n\x1a\n"?$out:null;
}
// Waits up to $seconds for a Modal call; returns ['png'=>bytes,'seed'=>int] or null if still running.
function modal_wait(array $c,string $call,int $seconds,?callable $onError=null): ?array {
 $url=preg_replace('/-start(\.[a-z0-9-]+)?\.modal\.run$/','-result$1.modal.run',$c['modal_start']).'?call_id='.rawurlencode($call);$until=time()+$seconds;
 do{$j=modal_request($c,$url,null,30);$s=$j['status']??'';
  if($s==='done'){$png=base64_decode((string)($j['png']??''),true);if($png===false||substr($png,0,8)!=="\x89PNG\r\n\x1a\n")fail(502,'Modal вернул не PNG.');return ['png'=>$png,'seed'=>$j['seed']??null];}
  if($s==='error'){if($onError)$onError();fail(502,'Ошибка генерации в Modal.');}if($s!=='pending')fail(502,'Неверный ответ Modal.');sleep(3);
 }while(time()<$until);return null;
}
function hf_quota_path(array $c): string { return $c['private_dir'].'/hf-quota.php'; }
function hf_quota_blocked(array $c): bool { $q=record_read(hf_quota_path($c),null);return is_array($q)&&($q['until']??0)>time(); }
// Remember until when ZeroGPU refuses work; Gradio says "Try again in H:MM:SS" when it knows.
function hf_quota_block(array $c,string $message): void { $wait=3600;if(preg_match('/(\d+):(\d\d):(\d\d)/',$message,$m))$wait=(int)$m[1]*3600+(int)$m[2]*60+(int)$m[3]+60;record_write(hf_quota_path($c),['until'=>time()+min($wait,86400)]); }
function remote_call(array $c,string $path,string $method='GET',$body=null,int $timeout=45): string {
 if(!function_exists('curl_init'))fail(503,'На хостинге нужно включить PHP cURL.');
 $ch=curl_init($c['hf_base'].$path);$headers=['Authorization: Bearer '.$c['hf_token']];if(is_string($body))$headers[]='Content-Type: application/json';
 $buffer='';$tooLarge=false;
 curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headers,CURLOPT_WRITEFUNCTION=>function($ch,$chunk)use(&$buffer,&$tooLarge){if(strlen($buffer)+strlen($chunk)>MAX_PROJECT_BYTES){$tooLarge=true;return 0;}$buffer.=$chunk;return strlen($chunk);}]);
 if($method==='POST'){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,$body);}
 $ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$errno=curl_errno($ch);curl_close($ch);
 if($tooLarge)fail(502,'Ответ Space слишком большой.');if($ok===false)fail(502,$errno===28?'Space не успел ответить. Картинку можно попробовать получить повторно.':'Не удалось связаться со Space.');
 if($status<200||$status>=300)fail(502,'Space вернул HTTP '.$status.'. Проверь токен, доступ к Space и квоту.');return $buffer;
}

// ===================================================================================================
// Object storage for pictures: any S3-compatible service (Backblaze B2, Cloudflare R2, Yandex, ...).
// Pictures are content-addressed (SHA-256), so a picture is uploaded once, never changes, and several
// projects and users share it. Local state per picture: images/xx/<hash>.png (still on this server)
// or images/xx/<hash>.s3 (moved to the cloud; the file holds the size in bytes).
// ===================================================================================================
const IMAGE_GRACE_SECONDS = 86400;
const IMAGE_USER_QUOTA_DEFAULT = 2147483648;
function s3_ready(array $c): bool { return !empty($c['s3_endpoint'])&&!empty($c['s3_bucket'])&&!empty($c['s3_key'])&&!empty($c['s3_secret'])&&function_exists('curl_init'); }
function s3_target(array $c): array {
 $e=trim((string)$c['s3_endpoint']);$scheme='https';
 if(preg_match('~^(https?)://~i',$e,$m)){$scheme=strtolower($m[1]);$e=substr($e,strlen($m[0]));}
 $e=rtrim($e,'/');$region=trim((string)($c['s3_region']??''));
 if($region===''&&preg_match('/^s3\.([a-z0-9-]+)\./',$e,$m))$region=$m[1];
 if($region==='')$region='us-east-1';
 return [$scheme,$e,$region];
}
function s3_object_key(string $hash): string { return 'images/'.substr($hash,0,2).'/'.$hash.'.png'; }
// AWS Signature V4. $headers: lowercase name => value, must contain host, x-amz-content-sha256, x-amz-date.
function s3_authorization(string $key,string $secret,string $method,string $path,string $query,array $headers,string $payloadHash,string $amzDate,string $region): string {
 ksort($headers);$canon='';foreach($headers as $k=>$v)$canon.=$k.':'.trim(preg_replace('/\s+/',' ',(string)$v))."\n";$signed=implode(';',array_keys($headers));$date=substr($amzDate,0,8);
 $request=$method."\n".$path."\n".$query."\n".$canon."\n".$signed."\n".$payloadHash;$scope="$date/$region/s3/aws4_request";
 $string="AWS4-HMAC-SHA256\n$amzDate\n$scope\n".hash('sha256',$request);
 $k=hash_hmac('sha256',$date,'AWS4'.$secret,true);$k=hash_hmac('sha256',$region,$k,true);$k=hash_hmac('sha256','s3',$k,true);$k=hash_hmac('sha256','aws4_request',$k,true);
 return 'AWS4-HMAC-SHA256 Credential='.$key.'/'.$scope.', SignedHeaders='.$signed.', Signature='.hash_hmac('sha256',$string,$k);
}
function s3_call(array $c,string $method,string $objectKey,?string $body=null,int $timeout=120): array {
 [$scheme,$host,$region]=s3_target($c);
 $path='/'.$c['s3_bucket'].'/'.implode('/',array_map('rawurlencode',explode('/',$objectKey)));
 $hash=hash('sha256',$body??'');$date=gmdate('Ymd\THis\Z');
 $auth=s3_authorization((string)$c['s3_key'],(string)$c['s3_secret'],$method,$path,'',['host'=>$host,'x-amz-content-sha256'=>$hash,'x-amz-date'=>$date],$hash,$date,$region);
 $head=[];$ch=curl_init($scheme.'://'.$host.$path);
 $opts=[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>$timeout,
  CURLOPT_HTTPHEADER=>['Authorization: '.$auth,'x-amz-content-sha256: '.$hash,'x-amz-date: '.$date,'Expect:'],
  CURLOPT_HEADERFUNCTION=>function($ch,$line)use(&$head){$p=explode(':',$line,2);if(count($p)===2)$head[strtolower(trim($p[0]))]=trim($p[1]);return strlen($line);}];
 if($method==='HEAD')$opts[CURLOPT_NOBODY]=true;
 if($body!==null)$opts[CURLOPT_POSTFIELDS]=$body;
 curl_setopt_array($ch,$opts);$r=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
 if($r===false)throw new RuntimeException('Нет связи с хранилищем: '.$err);
 return ['status'=>$status,'body'=>(string)$r,'headers'=>$head];
}
function s3_error_text(array $r): string { $t=preg_match('~<Message>(.*?)</Message>~s',$r['body'],$m)?$m[1]:substr(trim($r['body']),0,200);return 'HTTP '.$r['status'].($t!==''?': '.$t:''); }
function s3_put(array $c,string $hash,string $bytes): void { $r=s3_call($c,'PUT',s3_object_key($hash),$bytes);if($r['status']<200||$r['status']>=300)throw new RuntimeException('Хранилище не приняло картинку ('.s3_error_text($r).')'); }
function s3_get(array $c,string $hash): ?string { $r=s3_call($c,'GET',s3_object_key($hash),null,60);if($r['status']===404)return null;if($r['status']!==200)throw new RuntimeException('Хранилище не отдало картинку ('.s3_error_text($r).')');return $r['body']; }
function s3_head_size(array $c,string $hash): ?int { $r=s3_call($c,'HEAD',s3_object_key($hash),null,30);if($r['status']===404)return null;if($r['status']!==200)throw new RuntimeException('Хранилище не ответило ('.s3_error_text($r).')');return (int)($r['headers']['content-length']??-1); }
function s3_delete(array $c,string $hash): void { $r=s3_call($c,'DELETE',s3_object_key($hash),null,30);if(!in_array($r['status'],[200,204,404],true))throw new RuntimeException('Хранилище не удалило картинку ('.s3_error_text($r).')'); }
// Bytes of a picture wherever it lives; null when it exists nowhere.
function image_bytes(array $c,string $hash): ?string {
 if(preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)return null;$p=image_path($c,$hash);if(is_file($p)){$b=file_get_contents($p);return $b===false?null:$b;}
 if(is_file(image_marker_path($c,$hash))&&s3_ready($c))return s3_get($c,$hash);return null;
}
function image_size(array $c,string $hash): int { $p=image_path($c,$hash);if(is_file($p))return (int)filesize($p);$m=image_marker_path($c,$hash);return is_file($m)?(int)file_get_contents($m):0; }
function image_all(array $c): array { $out=[];foreach(glob($c['private_dir'].'/images/*/*.{png,s3}',GLOB_BRACE)?:[] as $f){$h=basename($f,'.'.pathinfo($f,PATHINFO_EXTENSION));if(preg_match('/^[a-f0-9]{64}$/D',$h))$out[$h]=$f;}return $out; }
// Moves local PNGs to the cloud one by one: upload, verify the stored size, only then drop the local copy.
function image_migrate_step(array $c,int $seconds): array {
 if(!s3_ready($c))fail(409,'Облачное хранилище не настроено.');
 $until=microtime(true)+$seconds;$moved=0;$freed=0;$failed=[];
 foreach(glob($c['private_dir'].'/images/*/*.png')?:[] as $f){
  if(microtime(true)>$until)break;$hash=basename($f,'.png');if(preg_match('/^[a-f0-9]{64}$/D',$hash)!==1)continue;
  try{$bytes=file_get_contents($f);if($bytes===false)throw new RuntimeException('read');$size=strlen($bytes);s3_put($c,$hash,$bytes);
   if(s3_head_size($c,$hash)!==$size)throw new RuntimeException('Размер в хранилище не совпал');
   record_marker($c,$hash,$size);unlink($f);$moved++;$freed+=$size;}catch(Throwable $e){$failed[]=substr($hash,0,8).': '.$e->getMessage();if(count($failed)>=3)break;}
 }
 return ['moved'=>$moved,'freedBytes'=>$freed,'left'=>count(glob($c['private_dir'].'/images/*/*.png')?:[]),'errors'=>$failed];
}
function image_quota_bytes(array $c): int { return max(1,(int)($c['image_quota_mb']??2048))*1048576; }
function owned_path(array $c,string $userId): string { return $c['private_dir'].'/accounts/'.$userId.'/images-owned.php'; }
function owned_images(array $c,string $userId): array { $o=record_read(owned_path($c,$userId),[]);return is_array($o)?$o:[]; }
// Garbage collection: a picture nobody references any more (deleted project, pruned old version) is removed
// from the cloud and the disk. It also rebuilds how many picture bytes each account uses.
// Safety: pictures younger than IMAGE_GRACE_SECONDS are never touched (an upload may precede its save),
// and nothing is deleted if any project file could not be read.
function image_gc(array $c,bool $dry=false): array {
 $refs=[];$perUser=[];$scanned=0;
 $sources=array_merge(glob($c['private_dir'].'/accounts/*/projects/*/versions/*.php')?:[],glob($c['private_dir'].'/books/*.php')?:[],glob($c['private_dir'].'/books-production/*/current.php')?:[],glob($c['private_dir'].'/library-items*.php')?:[]);
 foreach($sources as $f){
  $raw=file_get_contents($f);if($raw===false)return ['deleted'=>0,'freedBytes'=>0,'kept'=>0,'skipped'=>'Не удалось прочитать '.basename($f).'. Уборку отложили, ничего не удалено.'];
  $scanned++;$user=preg_match('~/accounts/([a-f0-9]{32})/~',$f,$m)?$m[1]:null;
  if(preg_match_all('/img:([a-f0-9]{64})/',$raw,$mm))foreach($mm[1] as $h){$refs[$h]=true;if($user)$perUser[$user][$h]=true;}
  unset($raw);
 }
 $all=image_all($c);
 // A missing accounts folder means a wrong path or a broken mount, not "no projects": never delete on a guess.
 if(!is_dir($c['private_dir'].'/accounts')&&$all)return ['deleted'=>0,'freedBytes'=>0,'kept'=>count($all),'skipped'=>'Не найдена папка аккаунтов. Уборку отложили, ничего не удалено.'];
 $deleted=0;$freed=0;$kept=0;$errors=[];$now=time();
 foreach($all as $hash=>$f){
  if(isset($refs[$hash])||$now-(int)filemtime($f)<IMAGE_GRACE_SECONDS){$kept++;continue;}
  $size=image_size($c,$hash);
  if(!$dry){
   try{if(substr($f,-3)==='.s3'){if(s3_ready($c))s3_delete($c,$hash);else{$kept++;continue;}}unlink($f);
    $other=image_path($c,$hash);if(is_file($other))unlink($other);$other=image_marker_path($c,$hash);if(is_file($other))unlink($other);}
   catch(Throwable $e){$errors[]=substr($hash,0,8).': '.$e->getMessage();$kept++;continue;}
  }
  $deleted++;$freed+=$size;
 }
 if(!$dry)foreach(glob($c['private_dir'].'/accounts/*',GLOB_ONLYDIR)?:[] as $dir){
  $uid=basename($dir);if(!preg_match('/^[a-f0-9]{32}$/D',$uid))continue;$owned=[];foreach(array_keys($perUser[$uid]??[]) as $h)if(image_exists($c,$h))$owned[$h]=image_size($c,$h);
  record_write(owned_path($c,$uid),$owned);
 }
 return ['deleted'=>$deleted,'freedBytes'=>$freed,'kept'=>$kept,'errors'=>$errors,'dry'=>$dry];
}
// Runs a collection at most every 12 hours, without ever waiting for another one that is already running.
function image_gc_maybe(array $c): void {
 $stamp=$c['private_dir'].'/images-gc-last.php';if(is_file($stamp)&&time()-(int)filemtime($stamp)<43200)return;
 $h=fopen($c['private_dir'].'/images-gc.lock','c');if(!$h)return;
 try{if(!flock($h,LOCK_EX|LOCK_NB))return;touch($stamp);image_gc($c);}catch(Throwable $e){error_log('Memorybox gc: '.$e->getMessage());}finally{flock($h,LOCK_UN);fclose($h);}
}

// ---------- Access by key from the Telegram bot ----------
// Masters never get a password: the bot hands out a short one-time key, the login page exchanges it for a session.
// An account has plan 'month' (until a date) or 'lifetime'; accounts without a plan are the owner and older accounts and stay open.
const KEY_TTL = 86400;
const PLANS = ['month'=>['title'=>'Месяц','price'=>5000,'days'=>30],'lifetime'=>['title'=>'Навсегда','price'=>39000,'days'=>0]];
// Done-for-you order for a parent: no studio access, the owner makes the book from the photo sent to the bot.
const ORDERS = ['mom'=>['title'=>'Книжка для вашего ребёнка','price'=>1490]];
function offer(string $k): ?array { return PLANS[$k] ?? ORDERS[$k] ?? null; }
function access_state(array $u): array {
 if(!empty($u['admin']))return ['active'=>true,'plan'=>'owner','until'=>null];
 $plan=$u['plan']??null;
 if($plan==='lifetime')return ['active'=>true,'plan'=>'lifetime','until'=>null];
 if($plan==='month'){$until=(int)($u['until']??0);return ['active'=>$until>time(),'plan'=>'month','until'=>$until];}
 return ['active'=>true,'plan'=>null,'until'=>null];
}
function user_file(array $c,string $id): string { return $c['private_dir'].'/users/'.$id.'.php'; }
function key_alphabet(): string { return 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; }
function key_normalize(string $key): string { return preg_replace('/[^A-Z0-9]/','',strtoupper($key))??''; }
function key_issue(array $c,string $userId): string {
 $a=key_alphabet();$key='';for($i=0;$i<8;$i++)$key.=$a[random_int(0,strlen($a)-1)];
 locked($c['private_dir'].'/login-keys.lock',function()use($c,$key,$userId){
  $p=$c['private_dir'].'/login-keys.php';$all=record_read($p,[]);$now=time();
  foreach($all as $h=>$r)if(($r['exp']??0)<$now||($r['user']??'')===$userId)unset($all[$h]);
  $all[hash_hmac('sha256',$key,$c['salt'])]=['user'=>$userId,'exp'=>$now+KEY_TTL];record_write($p,$all);
 });
 return substr($key,0,4).'-'.substr($key,4);
}
// One use: the key is removed as soon as it is accepted.
function key_consume(array $c,string $key): ?string {
 $key=key_normalize($key);if(strlen($key)!==8)return null;
 return locked($c['private_dir'].'/login-keys.lock',function()use($c,$key){
  $p=$c['private_dir'].'/login-keys.php';$all=record_read($p,[]);$h=hash_hmac('sha256',$key,$c['salt']);$r=$all[$h]??null;
  if(!$r)return null;unset($all[$h]);record_write($p,$all);
  return ($r['exp']??0)>=time()?(string)$r['user']:null;
 });
}
function tg_ready(array $c): bool { return !empty($c['tg_token'])&&function_exists('curl_init'); }
function tg_call(array $c,string $method,array $params=[],int $timeout=20): array {
 $base=rtrim((string)($c['tg_api']??'https://api.telegram.org'),'/');
 $ch=curl_init($base.'/bot'.$c['tg_token'].'/'.$method);
 curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($params,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_CONNECTTIMEOUT=>10]);
 $raw=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
 if($raw===false)throw new RuntimeException('Telegram недоступен: '.$err);
 $j=json_decode((string)$raw,true);return is_array($j)?$j:['ok'=>false,'description'=>'Непонятный ответ Telegram'];
}
function tg_html(string $s): string { return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function tg_send(array $c,$chat,string $html,?array $buttons=null,?array $markup=null): array {
 $p=['chat_id'=>$chat,'text'=>$html,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
 if($buttons)$p['reply_markup']=['inline_keyboard'=>$buttons];elseif($markup)$p['reply_markup']=$markup;
 return tg_call($c,'sendMessage',$p);
}
function users_index(array $c): array { $i=record_read($c['private_dir'].'/users.php',[]);return is_array($i)?$i:[]; }
function user_by_telegram(array $c,int $tgId): ?array {
 foreach(users_index($c) as $login=>$id){$u=record_read(user_file($c,(string)$id));if($u&&(int)($u['telegram']['id']??0)===$tgId)return $u;}
 return null;
}
// Creates the account on first payment, extends it on the next ones. A lifetime plan is never turned back into a month.
function access_grant(array $c,int $tgId,string $username,string $name,string $plan,array $ident=[]): array {
 if(!isset(PLANS[$plan]))throw new RuntimeException('Неизвестный тариф');
 $u=user_by_telegram($c,$tgId);
 if(!$u){
  $base=strtolower(preg_replace('/[^a-z0-9_.-]/i','',$username)??'');if(strlen($base)<3)$base='tg'.$tgId;$login=substr($base,0,40);$n=2;
  $idx=users_index($c);while(isset($idx[$login])){$login=substr($base,0,36).'-'.$n++;}
  $u=make_user($c,$login,bin2hex(random_bytes(24)));
 }
 return locked($c['private_dir'].'/users.lock',function()use($c,$u,$tgId,$username,$name,$plan,$ident){
  $u=record_read(user_file($c,$u['id']));$now=time();
  // Phone comes from Telegram itself, the e-mail was confirmed by a code; both stay with the account.
  if(!empty($ident['phone']))$u['phone']=$ident['phone'];if(!empty($ident['email'])){$u['email']=$ident['email'];$u['emailVerified']=!empty($ident['verified']);}
  if(($u['plan']??null)!=='lifetime'){
   if($plan==='lifetime'){$u['plan']='lifetime';$u['until']=null;}
   else{$from=max($now,(int)($u['until']??0));$u['plan']='month';$u['until']=$from+PLANS['month']['days']*86400;}
  }
  $u['telegram']=['id'=>$tgId,'username'=>$username,'name'=>$name];$u['paidAt']=$now;
  record_write(user_file($c,$u['id']),$u);return $u;
 });
}
function access_set(array $c,string $userId,string $plan): array {
 $u=record_read(user_file($c,$userId));if(!$u)throw new RuntimeException('Нет такой участницы');if(!empty($u['admin']))throw new RuntimeException('Владельцу доступ не меняется');
 return locked($c['private_dir'].'/users.lock',function()use($c,$u,$plan){
  $u=record_read(user_file($c,$u['id']));$now=time();
  if($plan==='lifetime'){$u['plan']='lifetime';$u['until']=null;}
  elseif($plan==='month'){if(($u['plan']??null)==='lifetime')throw new RuntimeException('У неё доступ навсегда. Чтобы отключить, нажми «Отключить».');$from=($u['plan']??null)==='month'?max($now,(int)($u['until']??0)):$now;$u['plan']='month';$u['until']=$from+PLANS['month']['days']*86400;}
  elseif($plan==='off'){$u['plan']='month';$u['until']=$now-1;}
  else throw new RuntimeException('Неизвестное действие');
  record_write(user_file($c,$u['id']),$u);return $u;
 });
}
function access_public(array $u): array {
 $a=access_state($u);
 $last=($u['logins']??[])[0]??null;
 return ['id'=>$u['id'],'login'=>$u['login'],'admin'=>!empty($u['admin']),'plan'=>$a['plan'],'active'=>$a['active'],'until'=>$a['until'],'telegram'=>$u['telegram']['username']??($u['telegram']['id']??null),'phone'=>$u['phone']??null,'email'=>$u['email']??null,'emailVerified'=>!empty($u['emailVerified']),'lastLogin'=>$last?['t'=>$last['t'],'ip'=>$last['ip']]:null];
}

// ---------- Against reselling a key ----------
// One account = one Telegram account + one phone + one e-mail, one open session at a time, and the owner sees every login.
// A new login by key ends the earlier session, so a key passed around cannot be used by two people together.
function session_replaced(array $u): bool { return empty($u['admin'])&&!empty($u['sid'])&&!hash_equals((string)$u['sid'],(string)($_SESSION['sid']??'')); }
function login_register(array $c,array $u): string {
 $sid=bin2hex(random_bytes(16));
 locked($c['private_dir'].'/users.lock',function()use($c,$u,$sid){
  $r=record_read(user_file($c,$u['id']));if(!$r)return;
  if(empty($r['admin']))$r['sid']=$sid;
  $log=$r['logins']??[];array_unshift($log,['t'=>time(),'ip'=>(string)($_SERVER['REMOTE_ADDR']??''),'ua'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,120)]);$r['logins']=array_slice($log,0,12);
  record_write(user_file($c,$u['id']),$r);
 });
 return $sid;
}
function phone_normalize(string $p): string { $d=preg_replace('/\D/','',$p)??'';return strlen($d)>=9&&strlen($d)<=15?'+'.$d:''; }
function email_normalize(string $e): string { $e=strtolower(trim($e));return strlen($e)<=120&&filter_var($e,FILTER_VALIDATE_EMAIL)?$e:''; }
function ident_path(array $c): string { return $c['private_dir'].'/tg-ident.php'; }
function ident_edit(array $c,callable $fn) { return locked($c['private_dir'].'/tg-ident.lock',function()use($c,$fn){$p=ident_path($c);$all=record_read($p,[]);$r=$fn($all);record_write($p,$all);return $r;}); }
// A phone or e-mail that already belongs to another Telegram account is refused.
function ident_conflict(array $c,int $tgId,string $phone,string $email): ?string {
 foreach(users_index($c) as $login=>$id){$u=record_read(user_file($c,(string)$id));if(!$u||(int)($u['telegram']['id']??0)===$tgId)continue;
  if($phone!==''&&($u['phone']??'')===$phone)return 'phone';if($email!==''&&($u['email']??'')===$email)return 'email';}
 foreach(record_read(ident_path($c),[]) as $t=>$r){if((int)$t===$tgId)continue;if($phone!==''&&($r['phone']??'')===$phone)return 'phone';if($email!==''&&($r['email']??'')===$email&&!empty($r['verified']))return 'email';}
 return null;
}
function mb_mail(array $c,string $to,string $subject,string $body): bool {
 if(!empty($c['mail_log'])){file_put_contents($c['mail_log'],$to.' | '.$subject.' | '.$body."\n",FILE_APPEND);return true;}
 $host=preg_replace('/^www\./','',(string)($_SERVER['HTTP_HOST']??'memorybox.by'))??'memorybox.by';
 $headers="From: Memorybox <noreply@".$host.">\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0";
 return @mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,$headers);
}
// No more than 5 keys an hour per account.
function key_allowed(array $c,string $userId): bool {
 return locked($c['private_dir'].'/users.lock',function()use($c,$userId){
  $r=record_read(user_file($c,$userId));if(!$r)return false;$now=time();$log=array_values(array_filter($r['keyLog']??[],fn($t)=>$t>$now-3600));
  if(count($log)>=5)return false;$log[]=$now;$r['keyLog']=$log;record_write(user_file($c,$userId),$r);return true;
 });
}

// ---------- Prompts the masters copy into an AI tool (edited by the owner in the studio) ----------
function prompts_default(): array {
 return [['title'=>'Одежда на шаблоне куклы','text'=>"Это РЕДАКТИРОВАНИЕ приложенной картинки, а не новая генерация. Размер холста 1360×2919 не менять.\nФигуру (голову, руки, ноги, позу и положение на холсте) не двигать, не перерисовывать и не масштабировать. Пурпурный цвет #FF00FF и белый фон оставить как есть.\nНарисуй поверх фигуры только одежду: [опиши одежду, например: красный пуховик, светло-голубые джинсы, коричневые ботинки].\nБелые майку и шорты на фигуре закрась новой одеждой.\nМежду вещами оставь сплошной пурпурный промежуток 40–50 px, вещи не должны касаться друг друга. Руки и кисти остаются открытыми и пурпурными.\nФотореалистичная предметная съёмка, чёткие края. Без теней на фоне, подписей и коллажей. Верни одно изображение."]];
}
function device_label(string $ua): string {
 return str_contains($ua,'iPhone')?'iPhone':(str_contains($ua,'iPad')?'iPad':(str_contains($ua,'Android')?'Android':(str_contains($ua,'Windows')?'Windows':(str_contains($ua,'Macintosh')?'Mac':'устройство'))));
}
// Tells the account's own Telegram about every login (owner included) so a login from a strange device is noticed at once.
function login_notify(array $c,array $u): void {
 if(!tg_ready($c)||empty($u['telegram']['id']))return;
 $ip=(string)($_SERVER['REMOTE_ADDR']??'');$dev=device_label((string)($_SERVER['HTTP_USER_AGENT']??''));
 try{tg_send($c,$u['telegram']['id'],'Вход в студию'.(!empty($u['admin'])?' (владелец)':'').': '.date('d.m.Y H:i').', '.tg_html($dev).', IP '.tg_html($ip).'. Если это не вы, смените пароль и напишите владельцу.');}catch(Throwable $e){}
}
