<?php
// Telegram bot for the studio: sells access (month / lifetime) and hands out one-time login keys.
// Telegram calls this address; the secret header set at webhook registration is the only thing that lets a request in.
declare(strict_types=1);
require __DIR__.'/lib.php';
header('Content-Type: application/json; charset=utf-8');
$c = config();
if (empty($c['tg_secret']) || !hash_equals((string)$c['tg_secret'], (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) { http_response_code(403); exit; }
$update = json_decode((string)file_get_contents('php://input'), true);
if (is_array($update) && tg_ready($c)) {
 try { tg_handle($c, $update); } catch (Throwable $e) { error_log('Memorybox bot: ' . $e->getMessage()); }
}
echo '{}';

function tg_site_url(): string { return 'https://' . ($_SERVER['HTTP_HOST'] ?? 'memorybox.by') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/pereodevashka/tg.php'), '/') . '/login.php'; }
function tg_money(int $n): string { return number_format($n, 0, '', ' ') . ' ₽'; }
function tg_date(?int $t): string { return $t ? date('d.m.Y', $t) : ''; }
function tg_requests_path(array $c): string { return $c['private_dir'] . '/tg-requests.php'; }
function tg_requests_edit(array $c, callable $fn) { return locked($c['private_dir'] . '/tg-requests.lock', function () use ($c, $fn) { $p = tg_requests_path($c); $all = record_read($p, []); $r = $fn($all); record_write($p, $all); return $r; }); }

function tg_price_lines(): string {
 return "👩‍🎨 <b>Я мастер</b> — доступ в студию на 30 дней: " . tg_money(PLANS['month']['price']) . ". Делайте кукол и книги для своих клиентов сами.\n"
  . "👩‍👧 <b>Я мама</b> — " . tg_money(ORDERS['mom']['price']) . ": пришлёте фото ребёнка, мы сделаем куклу и книжку и пришлём файлы для печати.";
}
function tg_buy_buttons(): array {
 return [[['text' => '👩‍🎨 Я мастер · ' . tg_money(PLANS['month']['price']) . ' / мес', 'callback_data' => 'buy:month']],
  [['text' => '👩‍👧 Я мама · книжка за ' . tg_money(ORDERS['mom']['price']), 'callback_data' => 'buy:mom']]];
}

// Protection from mass fake requests: sliding-window counters per key, a global ceiling and a block list the owner controls.
function tg_limit(array $c, string $key, int $max, int $window): bool {
 return locked($c['private_dir'] . '/tg-limits.lock', function () use ($c, $key, $max, $window) {
  $p = $c['private_dir'] . '/tg-limits.php'; $all = record_read($p, []); $now = time();
  foreach ($all as $k => $times) { $all[$k] = array_values(array_filter($times, fn($t) => $t > $now - 86400)); if (!$all[$k]) unset($all[$k]); }
  $mine = array_values(array_filter($all[$key] ?? [], fn($t) => $t > $now - $window));
  if (count($mine) >= $max) { $all[$key] = $mine; record_write($p, $all); return false; }
  $mine[] = $now; $all[$key] = $mine; if (count($all) > 5000) $all = array_slice($all, -4000, null, true); record_write($p, $all); return true;
 });
}
function tg_blocked(array $c, int $tg): bool { return isset(record_read($c['private_dir'] . '/tg-blocked.php', [])[(string)$tg]); }
function tg_block(array $c, int $tg): void { locked($c['private_dir'] . '/tg-limits.lock', function () use ($c, $tg) { $p = $c['private_dir'] . '/tg-blocked.php'; $b = record_read($p, []); $b[(string)$tg] = time(); record_write($p, $b); }); }
function tg_state_edit(array $c, callable $fn) { return locked($c['private_dir'] . '/tg-state.lock', function () use ($c, $fn) { $p = $c['private_dir'] . '/tg-state.php'; $all = record_read($p, []); $now = time(); foreach ($all as $k => $v) if (($v['exp'] ?? 0) < $now) unset($all[$k]); $r = $fn($all); record_write($p, $all); return $r; }); }
function tg_state_get(array $c, int $tg): ?array { $s = record_read($c['private_dir'] . '/tg-state.php', []); $r = $s[(string)$tg] ?? null; return $r && ($r['exp'] ?? 0) >= time() ? $r : null; }
function tg_state_set(array $c, int $tg, ?array $st): void { tg_state_edit($c, function (array &$all) use ($tg, $st) { if ($st === null) unset($all[(string)$tg]); else { $st['exp'] = time() + 1800; $all[(string)$tg] = $st; } }); }

// What we know about the buyer: the phone number Telegram itself confirmed.
function tg_ident(array $c, int $tg): array {
 $u = user_by_telegram($c, $tg);
 if ($u && !empty($u['phone'])) return ['phone' => $u['phone']];
 $r = record_read(ident_path($c), [])[(string)$tg] ?? [];
 return is_array($r) ? $r : [];
}
function tg_ask_phone(array $c, $chat, string $plan, int $tg): void {
 tg_state_set($c, $tg, ['step' => 'phone', 'plan' => $plan]);
 tg_send($c, $chat, "Чтобы доступ нельзя было передать или перепродать, он привязывается к вашему номеру телефона.\n\nНажмите кнопку ниже: Telegram передаст номер вашего аккаунта. Нажимая, вы соглашаетесь на обработку номера для выдачи и защиты доступа.", null, ['keyboard' => [[['text' => '📱 Поделиться номером', 'request_contact' => true]]], 'resize_keyboard' => true, 'one_time_keyboard' => true]);
}
// Owner replies to a forwarded client message → the reply goes back to that client.
function tg_relay_path(array $c): string { return $c['private_dir'] . '/tg-relay.php'; }
function tg_relay_remember(array $c, int $adminMsg, int $client): void { locked($c['private_dir'] . '/tg-state.lock', function () use ($c, $adminMsg, $client) { $all = record_read(tg_relay_path($c), []); $all[(string)$adminMsg] = $client; if (count($all) > 3000) $all = array_slice($all, -2500, null, true); record_write(tg_relay_path($c), $all); }); }
function tg_forward_to_owner(array $c, $chat, array $m, int $client): void {
 try { $r = tg_call($c, 'copyMessage', ['chat_id' => $c['tg_admin'], 'from_chat_id' => $chat, 'message_id' => $m['message_id']]); $id = (int)($r['result']['message_id'] ?? $r['message_id'] ?? 0); if ($id) tg_relay_remember($c, $id, $client); } catch (Throwable $e) {}
}
// A paid "mom" order stays open for 14 days: photos and messages from the parent go on to the owner.
function tg_mom_order(array $c, int $tg): ?array { foreach (array_reverse(record_read(tg_requests_path($c), []), true) as $r) if (($r['tg'] ?? 0) === $tg && ($r['plan'] ?? '') === 'mom' && ($r['status'] ?? '') === 'paid' && ($r['done'] ?? 0) > time() - 14 * 86400) return $r; return null; }
// Payment inside Telegram through ЮKassa: the receipt goes to «Мой налог» automatically (self-employed, no VAT).
function tg_invoice(array $c, $chat, string $plan, int $tg): void {
 $p = offer($plan); $title = $plan === 'mom' ? 'Книжка-переодевашка для вашего ребёнка' : 'Студия Memorybox — доступ на 30 дней';
 $desc = $plan === 'mom' ? 'Кукла по фото ребёнка и книжка с гардеробом, файлы для печати придут в чат.' : 'Студия бумажных кукол-переодевашек: кукла по фото, гардероб, книги, файлы для печати и резки.';
 $kop = $p['price'] * 100;
 $r = tg_call($c, 'sendInvoice', ['chat_id' => $chat, 'title' => $title, 'description' => $desc, 'payload' => $plan . ':' . $tg . ':' . bin2hex(random_bytes(4)), 'provider_token' => $c['tg_provider'], 'currency' => 'RUB', 'prices' => [['label' => $p['title'], 'amount' => $kop]],
  'need_email' => true, 'send_email_to_provider' => true,
  'provider_data' => json_encode(['receipt' => ['items' => [['description' => mb_substr($title, 0, 128), 'quantity' => '1.00', 'amount' => ['value' => number_format($p['price'], 2, '.', ''), 'currency' => 'RUB'], 'vat_code' => 1, 'payment_mode' => 'full_payment', 'payment_subject' => 'service']]]], JSON_UNESCAPED_UNICODE)]);
 if (empty($r['ok'])) { error_log('Memorybox invoice: ' . ($r['description'] ?? '')); tg_send($c, $chat, 'Оплата сейчас недоступна. Попробуйте позже или напишите владельцу.'); }
}
// Telegram confirmed the money: access for a master, an open order for a parent. Runs once per charge id.
function tg_paid(array $c, $chat, array $from, array $sp): void {
 $tg = (int)$from['id']; $plan = explode(':', (string)($sp['invoice_payload'] ?? ''))[0]; $p = offer($plan); $charge = (string)($sp['telegram_payment_charge_id'] ?? '');
 if (!$p || (int)($sp['total_amount'] ?? 0) !== $p['price'] * 100 || ($sp['currency'] ?? '') !== 'RUB') { if (!empty($c['tg_admin'])) tg_send($c, $c['tg_admin'], '⚠ Странный платёж от ' . $tg . ': ' . tg_html(json_encode($sp, JSON_UNESCAPED_UNICODE))); return; }
 $name = trim((string)($from['first_name'] ?? '')); $user = (string)($from['username'] ?? ''); $ident = tg_ident($c, $tg); $dup = false;
 tg_requests_edit($c, function (array &$all) use ($tg, $name, $user, $plan, $ident, $charge, $sp, &$dup) {
  foreach ($all as $r) if (($r['charge'] ?? '') === $charge) { $dup = true; return; }
  foreach ($all as $k => $r) if (($r['tg'] ?? 0) === $tg && ($r['status'] ?? '') === 'new') $all[$k]['status'] = 'declined';
  $all[bin2hex(random_bytes(5))] = ['tg' => $tg, 'name' => $name, 'username' => $user, 'plan' => $plan, 'status' => 'paid', 'at' => time(), 'done' => time(), 'ident' => $ident, 'charge' => $charge, 'yk' => (string)($sp['provider_payment_charge_id'] ?? '')];
  if (count($all) > 300) $all = array_slice($all, -300, null, true);
 });
 if ($dup) return;
 $who = tg_html(($name !== '' ? $name : 'без имени') . ($user !== '' ? ' (@' . $user . ')' : ''));
 if ($plan === 'mom') {
  tg_send($c, $chat, "🎉 Оплата прошла, спасибо!\nПришлите сюда фото ребёнка: лицо хорошо видно, лучше в полный рост и при дневном свете. Можно написать имя и пожелания по одежде. Готовые файлы для печати придут в этот чат.");
  if (!empty($c['tg_admin'])) tg_send($c, $c['tg_admin'], "💰 Оплачена книжка (мама) · " . tg_money($p['price']) . "\n" . $who . "\nЕё фото и сообщения будут приходить сюда 14 дней. Ответить или отправить файлы — ответьте (Reply) на её сообщение.");
  return;
 }
 $u = access_grant($c, $tg, $user, $name, $plan, $ident);
 ident_edit($c, function (array &$all) use ($tg) { unset($all[(string)$tg]); });
 $a = access_state($u);
 tg_send($c, $chat, '🎉 Оплата прошла. ' . ($a['plan'] === 'lifetime' ? 'Доступ навсегда.' : 'Доступ до ' . tg_date($a['until']) . '.'));
 tg_send_key($c, $chat, $u);
 if (!empty($c['tg_admin'])) tg_send($c, $c['tg_admin'], "💰 Оплачен доступ (мастер) · " . tg_money($p['price']) . "\n" . $who . (!empty($ident['phone']) ? "\nТелефон: " . tg_html((string)$ident['phone']) : '') . "\nДоступ выдан автоматически до " . tg_date($a['until']) . '.');
}
function tg_payment_message(array $c, $chat, string $plan, int $tg = 0): void {
 if (!empty($c['tg_provider']) && $tg) { tg_invoice($c, $chat, $plan, $tg); return; }
 $p = offer($plan);
 tg_send($c, $chat, '<b>' . tg_html($p['title']) . '</b> — ' . tg_money($p['price']) . "\n\nНа данный момент касса подключается. Если решили оформить заказ, напишите в Telegram @shxswn", null, ['remove_keyboard' => true]);
}
// The only step: the phone number that Telegram itself confirms.
function tg_flow(array $c, $chat, array $from, array $m, array $st): bool {
 $tg = (int)$from['id']; $text = trim((string)($m['text'] ?? ''));
 if ($st['step'] === 'phone') {
  $ct = $m['contact'] ?? null;
  if (!is_array($ct)) { tg_send($c, $chat, 'Нажмите кнопку «📱 Поделиться номером» под полем ввода.'); return true; }
  if ((int)($ct['user_id'] ?? 0) !== $tg) { tg_send($c, $chat, 'Нужен номер именно вашего аккаунта. Нажмите кнопку «📱 Поделиться номером», а не отправляйте чужой контакт.'); return true; }
  $phone = phone_normalize((string)($ct['phone_number'] ?? ''));
  if ($phone === '') { tg_send($c, $chat, 'Не удалось прочитать номер. Попробуйте ещё раз.'); return true; }
  if (ident_conflict($c, $tg, $phone, '') === 'phone') { tg_state_set($c, $tg, null); tg_send($c, $chat, 'Этот номер уже привязан к другому доступу. Если это ошибка, напишите владельцу.', null, ['remove_keyboard' => true]); return true; }
  ident_edit($c, function (array &$all) use ($tg, $phone) { $all[(string)$tg] = ['phone' => $phone, 'at' => time()] + ($all[(string)$tg] ?? []); $all[(string)$tg]['phone'] = $phone; });
  tg_state_set($c, $tg, null);
  tg_send($c, $chat, '✅ Номер принят.', null, ['remove_keyboard' => true]);
  tg_payment_message($c, $chat, (string)$st['plan'], $tg);
  return true;
 }
 return false;
}

function tg_online_report(array $c): string {
 $all = record_read($c['private_dir'] . '/online.php', []); $now = time(); $land = 0; $studio = []; $guests = 0; $five = 0;
 foreach ($all as $r) { $age = $now - (int)($r['t'] ?? 0); if ($age <= 300) $five++; if ($age > 120) continue;
  if (($r['p'] ?? '') === 'studio') { $u = !empty($r['u']) ? record_read(user_file($c, (string)$r['u'])) : null; if ($u) $studio[] = (string)($u['login'] ?? '?') . (!empty($u['admin']) ? ' (вы)' : ''); else $guests++; }
  else $land++; }
 $lines = ['👀 <b>Сейчас на сайте: ' . ($land + count($studio) + $guests) . '</b>', 'На главной: ' . $land, 'В студии: ' . count($studio) . ($studio ? ' — ' . tg_html(implode(', ', $studio)) : '')];
 if ($guests) $lines[] = 'Открыли студию без входа: ' . $guests;
 $lines[] = 'За последние 5 минут: ' . $five;
 return implode("\n", $lines);
}
function tg_home(array $c, $chat, array $from): void {
 $name = trim((string)($from['first_name'] ?? ''));
 $u = user_by_telegram($c, (int)$from['id']);
 if ($u) {
  $a = access_state($u);
  if ($a['active']) {
   $line = $a['plan'] === 'lifetime' ? 'Доступ: навсегда.' : ($a['plan'] === 'owner' ? 'Вы владелец студии.' : 'Доступ до ' . tg_date($a['until']) . '.');
   $rows = [[['text' => '🔑 Получить ключ входа', 'callback_data' => 'key']], [['text' => '📅 Мой доступ', 'callback_data' => 'status']]];
   if ($a['plan'] === 'month') $rows[] = [['text' => '➕ Продлить на месяц · ' . tg_money(PLANS['month']['price']), 'callback_data' => 'buy:month']];
   tg_send($c, $chat, 'Здравствуйте' . ($name !== '' ? ', ' . tg_html($name) : '') . "!\n" . tg_html($line) . "\nКлюч нужен, чтобы войти на сайт студии.", $rows);
   return;
  }
  tg_send($c, $chat, 'Срок доступа закончился ' . tg_date($a['until']) . ".\nПродлите доступ, чтобы снова входить в студию:\n" . tg_price_lines(), tg_buy_buttons());
  return;
 }
 if (tg_mom_order($c, (int)$from['id'])) { tg_send($c, $chat, "Ваш заказ книжки в работе. Фото ребёнка и пожелания присылайте прямо сюда — всё передаётся мастеру, готовые файлы придут в этот чат."); return; }
 tg_send($c, $chat, "<b>Студия Memorybox</b>\nБумажные куклы-переодевашки на липучках по фото ребёнка: кукла, книжка с гардеробом, листы для печати и резки.\n\n" . tg_price_lines() . "\n\nКто вы?", tg_buy_buttons());
}

function tg_send_key(array $c, $chat, array $u): void {
 if (empty($u['admin']) && !key_allowed($c, $u['id'])) { tg_send($c, $chat, 'Ключи можно получать не чаще 5 раз в час. Попробуйте позже.'); return; }
 $key = key_issue($c, $u['id']);
 tg_send($c, $chat, "Ваш ключ входа: <code>" . tg_html($key) . "</code>\nДействует 24 часа и работает один раз. Одновременно открыта может быть только одна сессия: новый вход закроет прежний.\n\nВведите его на странице входа:\n" . tg_html(tg_site_url()));
}

function tg_handle(array $c, array $update): void {
 // Telegram asks before charging: confirm only our own, current offers (must answer within 10 s).
 if (isset($update['pre_checkout_query'])) {
  $q = $update['pre_checkout_query']; $parts = explode(':', (string)($q['invoice_payload'] ?? '')); $p = offer($parts[0] ?? '');
  $ok = $p && ($q['currency'] ?? '') === 'RUB' && (int)($q['total_amount'] ?? 0) === $p['price'] * 100 && (int)($parts[1] ?? 0) === (int)($q['from']['id'] ?? -1) && !tg_blocked($c, (int)($q['from']['id'] ?? 0));
  try { tg_call($c, 'answerPreCheckoutQuery', $ok ? ['pre_checkout_query_id' => $q['id'], 'ok' => true] : ['pre_checkout_query_id' => $q['id'], 'ok' => false, 'error_message' => 'Цена изменилась. Нажмите /start и выберите тариф заново.']); } catch (Throwable $e) {}
  return;
 }
 if (isset($update['message']['successful_payment']) && ($update['message']['chat']['type'] ?? '') === 'private') { tg_paid($c, $update['message']['chat']['id'], $update['message']['from'], $update['message']['successful_payment']); return; }
 if (isset($update['callback_query'])) { tg_callback($c, $update['callback_query']); return; }
 $m = $update['message'] ?? null;
 if (!is_array($m) || empty($m['from']['id']) || ($m['chat']['type'] ?? '') !== 'private') return;
 $from = $m['from']; $chat = $m['chat']['id']; $text = trim((string)($m['text'] ?? ''));
 // The owner answers a client by replying to the client's forwarded message.
 if (!empty($c['tg_admin']) && (int)$from['id'] === (int)$c['tg_admin'] && !empty($m['reply_to_message']['message_id'])) {
  $to = record_read(tg_relay_path($c), [])[(string)$m['reply_to_message']['message_id']] ?? null;
  if ($to) { try { tg_call($c, 'copyMessage', ['chat_id' => $to, 'from_chat_id' => $chat, 'message_id' => $m['message_id']]); tg_send($c, $chat, '↪ Отправлено клиенту.'); } catch (Throwable $e) { tg_send($c, $chat, 'Не удалось отправить: ' . tg_html($e->getMessage())); } return; }
 }
 if (tg_blocked($c, (int)$from['id']) || !tg_limit($c, 'msg:' . (int)$from['id'], 40, 600)) return;
 if (preg_match('~^/link\s+([A-Za-z0-9-]+)~', $text, $mm)) { tg_link_owner($c, $chat, $from, $mm[1]); return; }
 // The "Open the bot" button in the studio arrives as /start link_CODE.
 if (preg_match('~^/start\s+link_([A-Za-z0-9-]+)$~', $text, $mm)) { tg_link_owner($c, $chat, $from, $mm[1]); return; }
 if ($text === '/start') tg_state_set($c, (int)$from['id'], null);
 $st = tg_state_get($c, (int)$from['id']);
 if ($st && ($text === '' || $text[0] !== '/') && tg_flow($c, $chat, $from, $m, $st)) return;
 if ($text === '/online' && !empty($c['tg_admin']) && (int)$from['id'] === (int)$c['tg_admin']) { tg_send($c, $chat, tg_online_report($c)); return; }
 if ($text === '/key') { $u = user_by_telegram($c, (int)$from['id']); if ($u && access_state($u)['active']) tg_send_key($c, $chat, $u); else tg_home($c, $chat, $from); return; }
 if ($text !== '' && $text[0] === '/') { tg_home($c, $chat, $from); return; }
 // A receipt (photo, file or text) goes on to the owner when there is an open payment request.
 if (!empty($c['tg_admin'])) {
  $open = null; foreach (record_read(tg_requests_path($c), []) as $id => $r) if (($r['tg'] ?? 0) === (int)$from['id'] && ($r['status'] ?? '') === 'new') $open = [$id, $r];
  if ($open) {
   tg_forward_to_owner($c, $chat, $m, (int)$from['id']);
   tg_send($c, $chat, ($open[1]['plan'] ?? '') === 'mom' ? 'Передал. Как только оплата подтвердится, напишу, что делать дальше.' : 'Передал владельцу. Как только оплата подтвердится, пришлю ключ входа.');
   return;
  }
  if ($mom = tg_mom_order($c, (int)$from['id'])) {
   tg_send($c, $c['tg_admin'], '👩‍👧 От ' . tg_html(($mom['name'] ?: 'клиента') . ($mom['username'] ? ' (@' . $mom['username'] . ')' : '')) . ' — ответьте на сообщение ниже, ответ уйдёт ей:');
   tg_forward_to_owner($c, $chat, $m, (int)$from['id']);
   tg_send($c, $chat, '✅ Передал мастеру.');
   return;
  }
 }
 tg_home($c, $chat, $from);
}

function tg_link_owner(array $c, $chat, array $from, string $code): void {
 $norm = key_normalize($code); $uid = null;
 locked($c['private_dir'] . '/login-keys.lock', function () use ($c, $norm, &$uid) {
  $p = $c['private_dir'] . '/tg-link-codes.php'; $all = record_read($p, []); $h = hash_hmac('sha256', $norm, $c['salt']); $r = $all[$h] ?? null;
  if ($r) { unset($all[$h]); record_write($p, $all); if (($r['exp'] ?? 0) >= time()) $uid = (string)$r['user']; }
 });
 $u = $uid ? record_read(user_file($c, $uid)) : null;
 if (!$u || empty($u['admin'])) { tg_send($c, $chat, 'Код привязки неверный или устарел. Получите новый в студии: Аккаунт → Администрирование.'); return; }
 locked(__DIR__ . '/.install.lock', function () use ($from) { $cfg = config(); $cfg['tg_admin'] = (int)$from['id']; record_write(__DIR__ . '/.memorybox-config.php', $cfg); });
 locked($c['private_dir'] . '/users.lock', function () use ($c, $u, $from) { $r = record_read(user_file($c, $u['id'])); $r['telegram'] = ['id' => (int)$from['id'], 'username' => (string)($from['username'] ?? ''), 'name' => (string)($from['first_name'] ?? '')]; record_write(user_file($c, $u['id']), $r); });
 tg_send($c, $chat, 'Готово: этот чат привязан к владельцу. Заявки на оплату будут приходить сюда, а команда /key даст ключ входа в студию.');
}

function tg_callback(array $c, array $cb): void {
 $from = $cb['from'] ?? []; $chat = $cb['message']['chat']['id'] ?? null; $msgId = $cb['message']['message_id'] ?? null; $data = (string)($cb['data'] ?? '');
 if (empty($from['id']) || !$chat) return;
 if ((int)($c['tg_admin'] ?? 0) !== (int)$from['id'] && (tg_blocked($c, (int)$from['id']) || !tg_limit($c, 'msg:' . (int)$from['id'], 40, 600))) return;
 $answer = function (string $t = '') use ($c, $cb) { try { tg_call($c, 'answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => $t]); } catch (Throwable $e) {} };
 $tgId = (int)$from['id']; $name = trim((string)($from['first_name'] ?? '')); $user = (string)($from['username'] ?? '');

 if ($data === 'key') {
  $u = user_by_telegram($c, $tgId); $answer();
  if ($u && access_state($u)['active']) tg_send_key($c, $chat, $u); else tg_home($c, $chat, $from);
  return;
 }
 if ($data === 'status') {
  $answer(); tg_home($c, $chat, $from); return;
 }
 if (preg_match('/^buy:(month|lifetime|mom)$/', $data, $m)) {
  $answer(); $id = tg_ident($c, $tgId);
  if ($m[1] === 'mom') { tg_send($c, $chat, "👩‍👧 <b>Книжка для вашего ребёнка</b>\nПосле оплаты пришлёте сюда фото ребёнка (лицо хорошо видно, в полный рост — ещё лучше). Мы сделаем куклу и книжку с гардеробом и пришлём файлы: распечатать, вырезать ножницами, наклеить липучки."); tg_payment_message($c, $chat, 'mom', $tgId); return; }
  if (!empty($id['phone'])) tg_payment_message($c, $chat, $m[1], $tgId); else tg_ask_phone($c, $chat, $m[1], $tgId);
  return;
 }
 if (preg_match('/^paid:(month|lifetime|mom)$/', $data, $m)) {
  $ident = tg_ident($c, $tgId);
  if (empty($ident['phone']) && $m[1] !== 'mom') { $answer(); tg_ask_phone($c, $chat, $m[1], $tgId); return; }
  $open = 0; foreach (record_read(tg_requests_path($c), []) as $r) if (($r['status'] ?? '') === 'new') $open++;
  if ($open >= 100) { $answer(); tg_send($c, $chat, 'Сейчас много заявок. Попробуйте чуть позже или напишите владельцу.'); return; }
  $id = null;
  tg_requests_edit($c, function (array &$all) use ($tgId, $name, $user, $m, $ident, &$id) {
   foreach ($all as $k => $r) if (($r['tg'] ?? 0) === $tgId && ($r['status'] ?? '') === 'new') { $id = $k; $all[$k]['plan'] = $m[1]; $all[$k]['ident'] = $ident; return; }
   $id = bin2hex(random_bytes(5)); $all[$id] = ['tg' => $tgId, 'name' => $name, 'username' => $user, 'plan' => $m[1], 'status' => 'new', 'at' => time(), 'ident' => $ident];
   if (count($all) > 300) $all = array_slice($all, -300, null, true);
  });
  $answer('Заявка отправлена');
  if (empty($c['tg_admin'])) { tg_send($c, $chat, 'Заявка принята. Владелец подтвердит оплату и пришлёт ключ.'); return; }
  $p = offer($m[1]);
  tg_send($c, $c['tg_admin'], ($m[1] === 'mom' ? "💳 Заказ книжки (мама)\n" : "💳 Заявка на доступ (мастер)\n") . tg_html(($name !== '' ? $name : 'без имени') . ($user !== '' ? ' (@' . $user . ')' : '')) . (!empty($ident['phone']) ? "\nТелефон: " . tg_html((string)$ident['phone']) . " ✅" : '') . "\nТариф: <b>" . tg_html($p['title']) . '</b> · ' . tg_money($p['price']), [[['text' => '✅ Оплачено, выдать доступ', 'callback_data' => 'ok:' . $id], ['text' => '✖ Отклонить', 'callback_data' => 'no:' . $id]], [['text' => '🚫 Заблокировать этого человека', 'callback_data' => 'ban:' . $tgId]]]);
  tg_send($c, $chat, 'Заявка отправлена. Как только оплата подтвердится, ' . ($m[1] === 'mom' ? 'напишу, что прислать дальше.' : 'пришлю ключ входа.') . ' Чек можно отправить сюда фото или текстом.');
  return;
 }
 if (preg_match('/^ban:(\d+)$/', $data, $m)) {
  if (empty($c['tg_admin']) || (int)$c['tg_admin'] !== $tgId) { $answer('Это действие только для владельца'); return; }
  tg_block($c, (int)$m[1]);
  tg_requests_edit($c, function (array &$all) use ($m) { foreach ($all as $k => $r) if (($r['tg'] ?? 0) === (int)$m[1] && ($r['status'] ?? '') === 'new') { $all[$k]['status'] = 'declined'; } });
  ident_edit($c, function (array &$all) use ($m) { unset($all[$m[1]]); });
  $answer('Заблокирован'); tg_send($c, $chat, 'Telegram-аккаунт ' . $m[1] . ' заблокирован: бот его больше не слушает.');
  return;
 }
 if (preg_match('/^(ok|no):([a-f0-9]{10})$/', $data, $m)) {
  if (empty($c['tg_admin']) || (int)$c['tg_admin'] !== $tgId) { $answer('Это действие только для владельца'); return; }
  $req = null; $skip = false;
  tg_requests_edit($c, function (array &$all) use ($m, &$req, &$skip) {
   $r = $all[$m[2]] ?? null; if (!$r) { $skip = true; return; } if (($r['status'] ?? '') !== 'new') { $skip = true; $req = $r; return; }
   $all[$m[2]]['status'] = $m[1] === 'ok' ? 'paid' : 'declined'; $all[$m[2]]['done'] = time(); $req = $all[$m[2]];
  });
  if ($skip || !$req) { $answer('Заявка уже обработана'); return; }
  $answer($m[1] === 'ok' ? 'Доступ выдан' : 'Отклонено');
  $label = $m[1] === 'ok' ? '✅ Доступ выдан' : '✖ Отклонено';
  if ($msgId) { try { tg_call($c, 'editMessageReplyMarkup', ['chat_id' => $chat, 'message_id' => $msgId, 'reply_markup' => ['inline_keyboard' => [[['text' => $label, 'callback_data' => 'done']]]]]); } catch (Throwable $e) {} }
  if ($m[1] === 'no') { tg_send($c, $req['tg'], 'Оплата не подтверждена. Если вы платили, напишите владельцу и отправьте чек.'); return; }
  if (($req['plan'] ?? '') === 'mom') {
   tg_send($c, $req['tg'], "🎉 Оплата подтверждена, спасибо!\nПришлите сюда фото ребёнка: лицо хорошо видно, лучше в полный рост и при дневном свете. Можно написать имя и пожелания по одежде. Готовые файлы для печати придут в этот чат.");
   tg_send($c, $chat, 'Заказ открыт на 14 дней: её фото и сообщения будут приходить сюда. Чтобы ответить или отправить файлы — ответьте (Reply) на её сообщение.');
   return;
  }
  $u = access_grant($c, (int)$req['tg'], (string)$req['username'], (string)$req['name'], (string)$req['plan'], (array)($req['ident'] ?? []));
  ident_edit($c, function (array &$all) use ($req) { unset($all[(string)$req['tg']]); });
  $a = access_state($u);
  tg_send($c, $req['tg'], '🎉 Оплата подтверждена. ' . ($a['plan'] === 'lifetime' ? 'Доступ навсегда.' : 'Доступ до ' . tg_date($a['until']) . '.'));
  tg_send_key($c, $req['tg'], $u);
  return;
 }
 $answer();
}
