<?php
// verify_email.php — подтверждение email по токену
require_once __DIR__ . '/system/function.php';

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$ok = false;
$msg = '';

if ($token === '' || !preg_match('/^[a-f0-9]{32}$/i', $token)) {
    $msg = 'Некорректный токен.';
} else {
    global $mysqli;
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        $msg = 'DB not initialized.';
    } else {
        $hash = hash('sha256', $token);
        $stmt = @$mysqli->prepare("UPDATE users SET email_verified=1, email_verify_token=NULL WHERE email_verify_token=? LIMIT 1");
        if (!$stmt) {
            $msg = 'В БД нет поля email_verify_token. Выполни db_patch_users_settings.sql';
        } else {
            $stmt->bind_param('s', $hash);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $ok = true;
                $msg = 'Почта подтверждена ✅ Теперь изменить её нельзя.';
            } else {
                $msg = 'Токен не найден или уже использован.';
            }
        }
    }
}

?><!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Подтверждение почты</title>
  <link rel="stylesheet" href="/style.css">
  <style>
    body{display:flex;min-height:100vh;align-items:center;justify-content:center;background:#0b0f14;color:#fff;}
    .card{max-width:520px;padding:18px 16px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.35);border-radius:14px}
    a{color:#9bdcff}
    .ok{color:#7CFF9B}
    .bad{color:#FF8A8A}
  </style>
</head>
<body>
  <div class="card">
    <h2 style="margin:0 0 10px 0;">Подтверждение почты</h2>
    <div class="<?php echo $ok ? 'ok' : 'bad'; ?>"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <div style="margin-top:12px;">
      <a href="/"><?php echo $ok ? 'Вернуться в игру' : 'На главную'; ?></a>
    </div>
  </div>
</body>
</html>
