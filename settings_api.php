<?php
// settings_api.php — API для настроек (JSON)
// actions:
//   GET  ?action=status
//   GET  ?action=generate_nick
//   POST ?action=change_password
//   POST ?action=bind_email
//   POST ?action=change_nick
//   POST ?action=profile_save   (g=0..2)
//
// Требует авторизацию (session user_id).

require_once __DIR__ . '/system/function.php';

// Переопределяем тип ответа на JSON
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function send_csrf_header() {
    if (function_exists('generateCsrfToken')) {
        $tok = generateCsrfToken();
        if (!headers_sent()) header('X-CSRF-Token: ' . $tok);
    }
}
function json_ok($data = []) {
    send_csrf_header();
    echo json_encode(['ok' => true] + (is_array($data) ? $data : []), JSON_UNESCAPED_UNICODE);
    exit;
}
function json_err($msg, $code = 400) {
    send_csrf_header();
    http_response_code((int)$code);
    echo json_encode(['error' => (string)$msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function is_ajax_request() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function ensure_logged_in() {
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        json_err('Требуется авторизация', 401);
    }
}

function nick_cost_for_changes($changes) {
    $changes = (int)$changes;
    // как в CoC: 1-я смена бесплатно, затем растёт
    $table = [0, 500, 1000, 1500, 2000, 2500, 3000, 4000, 5000];
    if ($changes < 0) $changes = 0;
    if ($changes >= count($table)) return 5000 + ($changes - (count($table)-1)) * 1000;
    return $table[$changes];
}

function get_user_extra_safe(mysqli $mysqli, $userId) {
    // Пытаемся читать расширенные поля. Если их нет — вернём минимальный набор.
    $userId = (int)$userId;

    $sql = "SELECT id, login, gems,
                   COALESCE(nick_changes,0) AS nick_changes,
                   email, COALESCE(email_verified,0) AS email_verified,
                   COALESCE(gender,0) AS gender
            FROM users WHERE id=? LIMIT 1";
    $stmt = @$mysqli->prepare($sql);
    if (!$stmt) {
        // fallback (если нет колонок email/nick_changes)
        $stmt2 = $mysqli->prepare("SELECT id, login, gems FROM users WHERE id=? LIMIT 1");
        if (!$stmt2) return null;
        $stmt2->bind_param('i', $userId);
        if (!$stmt2->execute()) return null;
        $res2 = $stmt2->get_result();
        $row2 = $res2 ? $res2->fetch_assoc() : null;
        if (!$row2) return null;
        $row2['nick_changes'] = 0;
        $row2['email'] = null;
        $row2['email_verified'] = 0;
        $row2['gender'] = 0;
        return $row2;
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) return null;
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    return $row ?: null;
}

function sanitize_login_token($s) {
    $s = (string)$s;
    $s = preg_replace('/\s+/u', '_', trim($s));
    // только ru/en/цифры/_
    $s = preg_replace('/[^a-zA-Z0-9_\x{0400}-\x{04FF}]/u', '', $s);
    return $s;
}

function gen_funny_nick(mysqli $mysqli) {
    // Забавные, короткие, без спецсимволов кроме _ (чтобы подходили под правило логина)
    $a = [
        'Кот', 'Лис', 'Волк', 'Орёл', 'Панда', 'Дракон', 'Титан', 'Гном', 'Рыцарь', 'Маг',
        'Builder', 'Wizard', 'Archer', 'Knight', 'Dragon', 'Goblin', 'Viking', 'Ninja', 'Panda', 'Tiger'
    ];
    $b = [
        'Марса', 'Снега', 'Тумана', 'Шторма', 'Огня', 'Льда', 'Золота', 'Эликсира', 'Легенд', 'Рубинов',
        'ofMars', 'ofIce', 'ofGold', 'ofElixir', 'Storm', 'Frost', 'Fire', 'Shadow', 'Nova', 'Rocket'
    ];

    for ($i = 0; $i < 50; $i++) {
        $p1 = $a[random_int(0, count($a)-1)];
        $p2 = $b[random_int(0, count($b)-1)];
        $nick = sanitize_login_token($p1 . '_' . $p2);

        // длина 3..20
        $len = function_exists('mb_strlen') ? mb_strlen($nick, 'UTF-8') : strlen($nick);
        if ($len < 3) continue;
        if ($len > 20) {
            // грубо обрежем до 20
            $nick = function_exists('mb_substr') ? mb_substr($nick, 0, 20, 'UTF-8') : substr($nick, 0, 20);
        }

        // уникальность
        $stmt = $mysqli->prepare('SELECT id FROM users WHERE login=? LIMIT 1');
        $stmt->bind_param('s', $nick);
        if ($stmt->execute()) {
            $r = $stmt->get_result();
            if (!$r || !$r->fetch_assoc()) return $nick;
        }

        // если занято — добавим цифру и попробуем снова
        $suffix = (string)random_int(0, 999);
        $nick2 = $nick;
        $max = 20 - (function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix));
        if ($max < 3) $max = 3;
        $nick2 = function_exists('mb_substr') ? mb_substr($nick2, 0, $max, 'UTF-8') : substr($nick2, 0, $max);
        $nick2 = sanitize_login_token($nick2 . $suffix);

        $stmt2 = $mysqli->prepare('SELECT id FROM users WHERE login=? LIMIT 1');
        $stmt2->bind_param('s', $nick2);
        if ($stmt2->execute()) {
            $r2 = $stmt2->get_result();
            if (!$r2 || !$r2->fetch_assoc()) return $nick2;
        }
    }
    // fallback
    return 'Player_' . random_int(1000, 9999);
}

function require_csrf() {
    // Поддерживаем оба варианта:
    // 1) csrf_token в теле (как в проекте)
    // 2) X-CSRF-Token в header (как в main.js)
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    }
    if (function_exists('check_csrf')) {
        if (!check_csrf($token)) {
            json_err('Недействительный CSRF токен', 403);
        }
        return;
    }
    if (function_exists('verifyCsrfAjax')) {
        try {
            verifyCsrfAjax();
            return;
        } catch (Exception $e) {
            json_err('Недействительный CSRF токен', 403);
        }
    }
}

// ---------------- Entry ----------------

ensure_logged_in();

global $mysqli;
if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    json_err('DB not initialized', 500);
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) json_err('Требуется авторизация', 401);

// action
$action = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'status';
} else {
    $action = $_GET['action'] ?? ($_POST['action'] ?? '');
}

$action = trim((string)$action);

// Алиасы для совместимости с фронтом
switch ($action) {
    case 'password':
    case 'pass':
    case 'set_password':
        $action = 'change_password';
        break;
    case 'email':
    case 'set_email':
        $action = 'bind_email';
        break;
    case 'nick':
    case 'set_nick':
        $action = 'change_nick';
        break;
    case 'profile':
    case 'profile_set':
    case 'profile_save':
        $action = 'profile_save';
        break;
    case 'gen_nick':
    case 'generate_login':
        $action = 'generate_nick';
        break;
}

// -------- generate_nick --------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'generate_nick') {
    $nick = gen_funny_nick($mysqli);
    json_ok(['nick' => $nick]);
}


// -------- status --------
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || $action === 'status')) {
    $u = get_user_extra_safe($mysqli, $userId);
    if (!$u) json_err('Пользователь не найден', 404);

    $nickChanges = (int)($u['nick_changes'] ?? 0);
    $cost = nick_cost_for_changes($nickChanges);

    json_ok([
        'id' => (int)$u['id'],
        'login' => $u['login'],
        'email' => $u['email'],
        'email_verified' => (int)($u['email_verified'] ?? 0),
        'gender' => (int)($u['gender'] ?? 0),
        'nick_changes' => $nickChanges,
        'nick_cost' => $cost,
        'gems' => (int)($u['gems'] ?? 0),
        'is_admin' => ($userId === 1 ? 1 : 0),
    ]);
}

// Все POST действия — только с CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
}

// -------- change_password --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_password') {
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $new2 = (string)($_POST['new_password2'] ?? '');

    if (strlen($new) < 8) json_err('Минимум 8 символов: заглавные, цифры, спецсимволы');
    if (!preg_match('/\p{Lu}/u', $new) || !preg_match('/\d/u', $new) || !preg_match('/[^\p{L}\p{N}]/u', $new)) {
        json_err('Минимум 8 символов: заглавные, цифры, спецсимволы');
    }
    if ($new !== $new2) json_err('Пароли не совпадают');

    $stmt = $mysqli->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) json_err('Ошибка проверки пароля', 500);
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    if (!$row) json_err('Пользователь не найден', 404);

    if (!password_verify($old, $row['password'])) json_err('Текущий пароль неверный', 403);

    $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt2 = $mysqli->prepare("UPDATE users SET password=? WHERE id=? LIMIT 1");
    $stmt2->bind_param('si', $hash, $userId);
    if (!$stmt2->execute()) json_err('Не удалось обновить пароль', 500);

    // отдаём login, чтобы фронт мог попросить браузер сохранить пароль
    $u = get_user_extra_safe($mysqli, $userId);
    json_ok(['message' => 'Пароль обновлён', 'login' => ($u['login'] ?? null)]);
}

// -------- bind_email --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'bind_email') {
    $email = trim((string)($_POST['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_err('Введите корректный email');
    }

    // если уже подтверждена — менять нельзя
    $cur = get_user_extra_safe($mysqli, $userId);
    if ($cur && !empty($cur['email']) && (int)($cur['email_verified'] ?? 0) === 1) {
        json_err('Почта уже подтверждена и изменить её нельзя');
    }

    // проверка уникальности (если колонка есть)
    $stmtCheck = @$mysqli->prepare("SELECT id FROM users WHERE email=? AND id<>? LIMIT 1");
    if ($stmtCheck) {
        $stmtCheck->bind_param('si', $email, $userId);
        if ($stmtCheck->execute()) {
            $r = $stmtCheck->get_result();
            if ($r && $r->fetch_assoc()) json_err('Эта почта уже используется другим аккаунтом');
        }
    }

    $raw = function_exists('make_hex_token') ? make_hex_token(16) : bin2hex(random_bytes(16));
    $hash = hash('sha256', $raw);

    $stmt = @$mysqli->prepare("UPDATE users SET email=?, email_verified=0, email_verify_token=? WHERE id=? LIMIT 1");
    if (!$stmt) {
        json_err('В БД нет полей email/email_verified/email_verify_token. Выполни db_patch_users_settings.sql', 500);
    }
    $stmt->bind_param('ssi', $email, $hash, $userId);
    if (!$stmt->execute()) json_err('Не удалось сохранить email', 500);

    $base = function_exists('site_origin') ? site_origin() : (( !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    $url = $base . '/verify_email.php?token=' . urlencode($raw);

    // Отправляем письмо (а не показываем ссылку в ответе)
    $subject = 'Подтверждение почты в Clash Browser';
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.45;color:#111">'
          . '<h2 style="margin:0 0 10px 0;">Подтверждение почты</h2>'
          . '<p>Вы привязали этот email к аккаунту в <b>Clash Browser</b>.</p>'
          . '<p>Нажмите кнопку ниже, чтобы подтвердить почту:</p>'
          . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:10px 14px;border-radius:10px;background:#18a558;color:#fff;text-decoration:none;">Подтвердить почту</a></p>'
          . '<p style="color:#555;font-size:12px">Если вы не привязывали почту — просто игнорируйте это письмо.</p>'
          . '</div>';

    $sent = function_exists('send_game_mail') ? send_game_mail($email, $subject, $html) : false;
    if (!$sent) {
        // если отправка не удалась — не палим ссылку в ответе
        json_err('Не удалось отправить письмо. Проверь почтовую настройку хостинга (mail())', 500);
    }

    json_ok(['message' => 'Письмо с подтверждением отправлено на почту']);
}

// -------- set_gender --------
// Примечание: некоторые WAF/mod_security режут запросы с параметром/действием "gender".
// Поэтому основной action: profile_save, параметр: g.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'profile_save' || $action === 'set_gender' || $action === 'gender')) {
    $gender = (int)($_POST['g'] ?? ($_POST['gender'] ?? 0));
    if ($gender < 0 || $gender > 2) json_err('Некорректное значение пола');

    $stmt = @$mysqli->prepare("UPDATE users SET gender=? WHERE id=? LIMIT 1");
    if (!$stmt) json_err('В БД нет поля gender. Выполни patch_users_email_gender_reset.sql', 500);
    $stmt->bind_param('ii', $gender, $userId);
    if (!$stmt->execute()) json_err('Не удалось сохранить', 500);
    json_ok(['message' => 'Сохранено', 'gender' => $gender]);
}

// -------- change_nick --------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'change_nick') {
    $nick = trim((string)($_POST['nick'] ?? ''));

    // Разрешаем любые языки, но убираем управление/невидимые символы
    // CoC ограничивает по длине; возьмём 3..15
    $nick = preg_replace('/[\x00-\x1F\x7F]/u', '', $nick); // control chars
    $nick = preg_replace('/\s+/u', ' ', $nick);
    $len = function_exists('mb_strlen') ? mb_strlen($nick, 'UTF-8') : strlen($nick);
    if ($len < 3 || $len > 20) json_err('От 3 до 20 символов (ru, en, цифры, _)');

    // Разрешаем: буквы/цифры/пробел/._-
    if (!preg_match('/^[a-zA-Z0-9_\x{0400}-\x{04FF}]{3,20}$/u', $nick)) {
        json_err('От 3 до 20 символов (ru, en, цифры, _)');
    }

    // Получим текущие данные
    $u = get_user_extra_safe($mysqli, $userId);
    if (!$u) json_err('Пользователь не найден', 404);

    $changes = (int)($u['nick_changes'] ?? 0);
    $cost = nick_cost_for_changes($changes);
    $gems = (int)($u['gems'] ?? 0);

    if ($cost > 0 && $gems < $cost) json_err('Недостаточно 💎 для смены ника');

    // проверка уникальности
    $stmtU = $mysqli->prepare("SELECT id FROM users WHERE login=? AND id<>? LIMIT 1");
    $stmtU->bind_param('si', $nick, $userId);
    if ($stmtU->execute()) {
        $r = $stmtU->get_result();
        if ($r && $r->fetch_assoc()) json_err('Этот ник уже занят');
    }

    // Обновляем атомарно: ник + списание гемов + счётчик
    if ($cost > 0) {
        $stmt = $mysqli->prepare("UPDATE users SET login=?, gems=gems-?, nick_changes=COALESCE(nick_changes,0)+1 WHERE id=? AND gems>=? LIMIT 1");
        $stmt->bind_param('siii', $nick, $cost, $userId, $cost);
    } else {
        $stmt = $mysqli->prepare("UPDATE users SET login=?, nick_changes=COALESCE(nick_changes,0)+1 WHERE id=? LIMIT 1");
        $stmt->bind_param('si', $nick, $userId);
    }

    if (!$stmt->execute()) json_err('Не удалось сменить ник', 500);
    if ($stmt->affected_rows <= 0) json_err('Не удалось сменить ник (проверь баланс/поля)', 500);

    // Вернём новый статус
    $u2 = get_user_extra_safe($mysqli, $userId);
    $changes2 = (int)($u2['nick_changes'] ?? ($changes+1));
    $cost2 = nick_cost_for_changes($changes2);

    json_ok([
        'message' => 'Ник изменён',
        'login' => $nick,
        'gems' => (int)($u2['gems'] ?? max(0, $gems - $cost)),
        'nick_changes' => $changes2,
        'nick_cost' => $cost2,
        'delta' => ['gems' => -$cost]
    ]);
}

json_err('Unknown action', 404);
