<?php
declare(strict_types=1);require __DIR__.'/lib.php';guard_headers();header('Referrer-Policy: no-referrer');
$activation=(string)($_GET['activation']??'');
$error='';$done=is_file(__DIR__.'/.memorybox-config.php');
if(!$done&&$_SERVER['REQUEST_METHOD']==='POST'){
 try {
  $key=require __DIR__.'/install-key.php';if(!hash_equals($key,(string)($_POST['install_key']??'')))throw new RuntimeException('Неверный код установки из инструкции.');
  $login=strtolower(trim((string)($_POST['login']??'')));$pw=(string)($_POST['password']??'');if(!preg_match('/^[a-z0-9_.-]{3,40}$/',$login)||strlen($pw)<12||strlen($pw)>200)throw new RuntimeException('Логин: 3–40 латинских символов. Пароль: минимум 12 символов.');
  $dir=rtrim(trim((string)($_POST['private_dir']??'')),'/');
  if($dir===''){
   $doc=realpath($_SERVER['DOCUMENT_ROOT']??__DIR__);$dir='';
   if(!$doc)throw new RuntimeException('Хостинг не сообщил путь сайта. Пришли текст этой ошибки.');
   foreach([dirname($doc),dirname($doc,2),dirname($doc,3)] as $base){
    $candidate=$base.'/memorybox-private-'.substr(hash('sha256',__DIR__),0,12);
    if((is_dir($candidate)||@mkdir($candidate,0700,true))&&is_writable($candidate)){$real=realpath($candidate);if($real&&$real!==$doc&&strpos($real,$doc.DIRECTORY_SEPARATOR)!==0){$dir=$real;break;}}
   }
   if($dir==='')throw new RuntimeException('Не удалось автоматически создать закрытую папку. Пришли текст этой ошибки — подстроим установку под хостинг.');
  }if($dir===''||$dir[0]!=='/'||strpos($dir,"\0")!==false)throw new RuntimeException('Укажи полный путь к папке хранения.');
  if(!is_dir($dir)&&!@mkdir($dir,0700,true))throw new RuntimeException('Не удалось создать папку хранения. Проверь путь и права.');$dir=realpath($dir);$doc=realpath($_SERVER['DOCUMENT_ROOT']??__DIR__);
  if(!$dir||!is_writable($dir)||!$doc||$dir===$doc||strpos($dir,$doc.DIRECTORY_SEPARATOR)===0)throw new RuntimeException('Папка хранения должна быть доступна PHP и находиться ВНЕ публичной папки сайта.');
  $bootstrap=record_read(__DIR__.'/hf-bootstrap.php',[]);$token=trim((string)($_POST['hf_token']??($bootstrap['token']??'')));if($token!==''&&!preg_match('/^hf_[A-Za-z0-9]+$/',$token))throw new RuntimeException('Проверь формат HF-токена. Для проверки аккаунтов можно пока оставить поле пустым.');
  $c=['private_dir'=>$dir,'hf_base'=>'https://elshan94-kukla-head-swap.hf.space','hf_token'=>$token,'salt'=>bin2hex(random_bytes(32))];
  locked(__DIR__.'/.install.lock',function()use($c,$login,$pw){if(is_file(__DIR__.'/.memorybox-config.php'))throw new RuntimeException('Установка уже выполнена.');make_user($c,$login,$pw,true);record_write(__DIR__.'/.memorybox-config.php',$c);});$done=true;@unlink(__DIR__.'/hf-bootstrap.php');@unlink(__DIR__.'/install-key.php');
 }catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Установка не завершилась. Проверь права и версию PHP.';}
}
$suggest='';
?><!doctype html><html lang="ru"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Memorybox — активация редактора</title><style>body{font:16px system-ui;background:#f6f5f2;color:#242329;margin:0;padding:20px}main{max-width:580px;margin:auto;background:white;padding:24px;border-radius:18px}label{display:grid;gap:6px;margin:16px 0}input{font:inherit;padding:10px;border:1px solid #ccc;border-radius:8px;min-width:0}button,a{font:inherit}button{padding:12px;background:#a71a7d;color:white;border:0;border-radius:10px}p{line-height:1.6}.error{color:#a21e35}</style><main><h1>Первый вход в обновлённый редактор</h1>
<?php if($done): ?><p>Установка завершена. Открытой регистрации нет. Первый аккаунт — владелец.</p><p><a href="login.php">Войти в редактор</a></p><p>Ключ активации уже использован. Перейди в редактор и проверь сохранение проекта.</p>
<?php else: ?><p>Обновляем редактор кукол. Создай свой аккаунт — папку хранения программа настроит автоматически. Остальные разделы сайта не меняются.</p><p>PHP: <?=htmlspecialchars(PHP_VERSION)?> · cURL: <?=function_exists('curl_init')?'есть':'нет — генерацию включить после активации расширения'?></p><p class="error"><?=htmlspecialchars($error)?></p><form method="post" autocomplete="off"><?php if($activation!==''): ?><input type="hidden" name="install_key" value="<?=htmlspecialchars($activation,ENT_QUOTES)?>"><?php else: ?><label>Код активации из файла «ОТКРЫТЬ ПОСЛЕ ЗАГРУЗКИ»<input name="install_key" required type="password"></label><?php endif ?><label>Твой логин<input name="login" required minlength="3" maxlength="40" autocomplete="username"></label><label>Твой пароль — минимум 12 символов<input name="password" required minlength="12" maxlength="200" type="password" autocomplete="new-password"></label><p>Подключение к твоему Space Hugging Face будет перенесено из старой версии автоматически. Новый пароль аккаунта нужен только для редактора Memorybox.</p><details><summary>Дополнительные настройки — обычно не нужны</summary><label>Папка хранения (пусто = автоматически)<input name="private_dir" value=""></label></details><button>Установить</button></form><?php endif ?></main></html>
