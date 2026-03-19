<?php
require_once __DIR__ . '/system/function.php';
$code = isset($_GET['code']) ? preg_replace('/[^a-z0-9_\-]/i','',$_GET['code']) : 'unknown';
http_response_code(400);
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Ошибка</title>
</head>
<body style="background:#0b0f14;color:#fff;font-family:Arial,sans-serif;">
  <div style="max-width:520px;margin:60px auto;padding:16px;">
    <div style="background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:16px;">
      <h2 style="margin:0 0 10px 0;">Ошибка загрузки данных</h2>
      <div style="opacity:.8;font-size:13px;margin-bottom:10px;">Код: <b><?php echo htmlspecialchars($code, ENT_QUOTES, 'UTF-8'); ?></b></div>
      <div style="opacity:.9;">Обычно это означает проблему с данными пользователя в базе или несовместимость окружения (например, отсутствует mysqlnd).</div>
      <div style="margin-top:14px;">
        <a href="/login.php" style="display:inline-block;background:#ff4d4d;color:#fff;text-decoration:none;padding:10px 14px;border-radius:10px;">Вернуться на вход</a>
      </div>
    </div>
  </div>
</body>
</html>
