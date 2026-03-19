<?php
require_once __DIR__ . '/system/function.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: /');
    exit;
}

$sent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!check_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Недействительный CSRF токен');
        }

        $q = trim((string)($_POST['login_or_email'] ?? ''));
        if ($q === '') throw new RuntimeException('Введите логин или email');

        // Найдём пользователя (без раскрытия существования)
        $isEmail = (strpos($q, '@') !== false);
        if ($isEmail) {
            $email = strtolower($q);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Введите корректный email');
            }
            $stmt = $mysqli->prepare("SELECT id, login, email, COALESCE(email_verified,0) AS email_verified FROM users WHERE email=? LIMIT 1");
            $stmt->bind_param('s', $email);
        } else {
            // логин: от 3 до 20 символов (ru, en, цифры, _)
            $login = trim($q);
            if (!preg_match('/^[a-zA-Z0-9_\x{0400}-\x{04FF}]{3,20}$/u', $login)) {
                throw new RuntimeException('Логин: от 3 до 20 символов (ru, en, цифры, _)');
            }
            $stmt = $mysqli->prepare("SELECT id, login, email, COALESCE(email_verified,0) AS email_verified FROM users WHERE login=? LIMIT 1");
            $stmt->bind_param('s', $login);
        }

        if ($stmt && $stmt->execute()) {
            $u = $stmt->get_result()->fetch_assoc();
            if ($u && !empty($u['email']) && (int)$u['email_verified'] === 1) {
                $tokenRaw = create_token_hex(16); // 32 hex
                $tokenHash = hash('sha256', $tokenRaw);
                $expires = time() + 3600; // 1 час

                // Попробуем записать токен (если колонок нет — письмо не отправим, но форму не палим)
                $stmtUp = @$mysqli->prepare("UPDATE users SET password_reset_token=?, password_reset_expires=? WHERE id=? LIMIT 1");
                if ($stmtUp) {
                    $stmtUp->bind_param('sii', $tokenHash, $expires, $u['id']);
                    if ($stmtUp->execute()) {
                        $origin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
                        $link = $origin . '/reset_password.php?token=' . urlencode($tokenRaw);

                        $subj = 'Восстановление пароля — Clash';
                        $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111">'
                              . '<h2 style="margin:0 0 10px">Восстановление пароля</h2>'
                              . '<p>Кто-то запросил восстановление пароля для аккаунта <b>' . htmlspecialchars($u['login'], ENT_QUOTES, 'UTF-8') . '</b>.</p>'
                              . '<p>Если это были вы — нажмите кнопку ниже. Ссылка действует 1 час.</p>'
                              . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:#2e7d32;color:#fff;text-decoration:none;padding:10px 14px;border-radius:10px;font-weight:bold">Сбросить пароль</a></p>'
                              . '<p style="color:#555;font-size:12px">Если вы не запрашивали восстановление — просто проигнорируйте это письмо.</p>'
                              . '</div>';

                        @send_game_mail($u['email'], $subj, $html);
                    }
                }
            }
        }

        // Всегда одинаковый ответ
        $sent = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля</title>
    <link rel="stylesheet" href="style.css">
    <style>
        html, body { height:100%; overflow-y:auto; scrollbar-width:none; -ms-overflow-style:none; }
        html::-webkit-scrollbar, body::-webkit-scrollbar { width:0; height:0; }
        .fp-card{ width:min(460px, 94vw); margin: 18px auto; padding: 18px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.72); backdrop-filter: blur(10px); color:#fff; box-shadow: 0 20px 60px rgba(0,0,0,0.55); }
        .fp-title{ font-weight:900; font-size: 18px; margin-bottom: 10px; }
        .fp-hint{ font-size: 12px; opacity: 0.85; margin-bottom: 12px; }
        .fp-input{ width:100%; padding: 11px 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.16); background: rgba(0,0,0,0.35); color:#fff; outline:none; }
        .fp-btn{ width:100%; margin-top: 10px; padding: 11px 12px; border-radius: 12px; border: 0; background: linear-gradient(180deg,#37c953,#1b8a31); color:#fff; font-weight:900; cursor:pointer; }
        .fp-msg{ margin-top: 10px; font-size: 13px; }
        .fp-msg.ok{ color: rgba(140,255,140,0.95); }
        .fp-msg.err{ color: rgba(255,140,140,0.95); }
        .fp-links{ margin-top: 12px; font-size: 13px; }
        .fp-links a{ color: rgba(140,200,255,0.95); text-decoration: underline; }
    </style>
</head>
<body>
    <div class="fp-card">
        <div class="fp-title">Восстановление пароля</div>
        <div class="fp-hint">Введите логин или подтверждённую почту. Если аккаунт найден — мы отправим письмо со ссылкой для сброса пароля.</div>

        <form method="post" action="forgot_password.php" autocomplete="on">
            <?= csrfInput() ?>
            <input class="fp-input" type="text" name="login_or_email" placeholder="Логин или email" required>
            <button class="fp-btn" type="submit">Отправить письмо</button>
        </form>

        <?php if ($error): ?>
            <div class="fp-msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php elseif ($sent): ?>
            <div class="fp-msg ok">Если аккаунт найден и почта подтверждена — письмо отправлено. Проверьте входящие и папку «Спам».</div>
        <?php endif; ?>

        <div class="fp-links">
            <a href="login.php">Вернуться ко входу</a>
        </div>
    </div>
</body>
</html>
