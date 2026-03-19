<?php
require_once 'system/function.php';

// Логирование выхода
if (isLoggedIn()) {
    logToFile("Пользователь {$_SESSION['user_id']} вышел из системы");
}

// Полное уничтожение сессии
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

header('Location: login.php');
exit;