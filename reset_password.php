<?php
require_once __DIR__ . '/system/function.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    header('Location: /');
    exit;
}

$tokenRaw = trim((string)($_GET['token'] ?? ($_POST['token'] ?? '')));
$tokenRaw = preg_replace('/\s+/u','', $tokenRaw);
$tokenHash = ($tokenRaw !== '') ? hash('sha256', $tokenRaw) : '';

$error = '';
$ok = false;
$userLogin = '';

function load_reset_user($mysqli, $tokenHash) {
    if (!$tokenHash) return null;
    $stmt = @$mysqli->prepare("SELECT id, login, COALESCE(password_reset_expires,0) AS exp FROM users WHERE password_reset_token=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('s', $tokenHash);
    if (!$stmt->execute()) return null;
    $u = $stmt->get_result()->fetch_assoc();
    return $u ?: null;
}

$u = load_reset_user($mysqli, $tokenHash);
if ($u) {
    $userLogin = (string)($u['login'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!check_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Недействительный CSRF токен');
        }
        if (!$tokenRaw || !preg_match('/^[a-f0-9]{32}$/i', $tokenRaw)) {
            throw new RuntimeException('Неверная ссылка');
        }

        $u = load_reset_user($mysqli, $tokenHash);
        if (!$u) throw new RuntimeException('Ссылка недействительна или уже использована');
        if ((int)($u['exp'] ?? 0) < time()) throw new RuntimeException('Ссылка устарела. Запросите восстановление снова.');

        $p1 = (string)($_POST['p1'] ?? '');
        $p2 = (string)($_POST['p2'] ?? '');
        if (strlen($p1) < 8) throw new RuntimeException('Минимум 8 символов: заглавные, цифры, спецсимволы');
        if (!preg_match('/\p{Lu}/u', $p1) || !preg_match('/\d/u', $p1) || !preg_match('/[^\p{L}\p{N}]/u', $p1)) {
            throw new RuntimeException('Минимум 8 символов: заглавные, цифры, спецсимволы');
        }
        if ($p1 !== $p2) throw new RuntimeException('Пароли не совпадают');

        $hash = password_hash($p1, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmtUp = $mysqli->prepare("UPDATE users SET password=?, password_reset_token=NULL, password_reset_expires=NULL WHERE id=? LIMIT 1");
        $stmtUp->bind_param('si', $hash, $u['id']);
        if (!$stmtUp->execute()) throw new RuntimeException('Не удалось обновить пароль');

        $ok = true;
        $userLogin = (string)($u['login'] ?? '');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля</title>
    <link rel="stylesheet" href="style.css">
    <style>
        html, body { height:100%; overflow-y:auto; scrollbar-width:none; -ms-overflow-style:none; }
        html::-webkit-scrollbar, body::-webkit-scrollbar { width:0; height:0; }
        .rp-card{ width:min(460px, 94vw); margin: 18px auto; padding: 18px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.72); backdrop-filter: blur(10px); color:#fff; box-shadow: 0 20px 60px rgba(0,0,0,0.55); }
        .rp-title{ font-weight:900; font-size: 18px; margin-bottom: 10px; }
        .rp-hint{ font-size: 12px; opacity: 0.85; margin-bottom: 12px; }
        .rp-input{ width:100%; padding: 11px 12px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.16); background: rgba(0,0,0,0.35); color:#fff; outline:none; }
        .rp-btn{ width:100%; margin-top: 10px; padding: 11px 12px; border-radius: 12px; border: 0; background: linear-gradient(180deg,#37c953,#1b8a31); color:#fff; font-weight:900; cursor:pointer; }
        .rp-msg{ margin-top: 10px; font-size: 13px; }
        .rp-msg.ok{ color: rgba(140,255,140,0.95); }
        .rp-msg.err{ color: rgba(255,140,140,0.95); }
        .rp-links{ margin-top: 12px; font-size: 13px; }
        .rp-links a{ color: rgba(140,200,255,0.95); text-decoration: underline; }
    </style>
</head>
<body>
    <div class="rp-card">
        <div class="rp-title">Сброс пароля</div>

        <?php if ($ok): ?>
            <div class="rp-msg ok">Пароль успешно обновлён. Теперь вы можете войти.</div>
            <div class="rp-links">
                <a href="login.php">Перейти ко входу</a>
            </div>
        <?php else: ?>
            <div class="rp-hint">Аккаунт: <b><?= htmlspecialchars($userLogin ?: '—', ENT_QUOTES, 'UTF-8') ?></b></div>
            <form method="post" action="reset_password.php" autocomplete="on">
                <?= csrfInput() ?>
                <input type="hidden" name="token" value="<?= htmlspecialchars($tokenRaw, ENT_QUOTES, 'UTF-8') ?>">
                <input class="rp-input" type="password" name="p1" placeholder="Новый пароль" autocomplete="new-password" required>
                <div style="height:8px"></div>
                <input class="rp-input" type="password" name="p2" placeholder="Повторите пароль" autocomplete="new-password" required>
                <button class="rp-btn" type="submit">Сохранить пароль</button>
            </form>

            <?php if ($error): ?>
                <div class="rp-msg err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <div class="rp-links">
                <a href="forgot_password.php">Запросить письмо заново</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
