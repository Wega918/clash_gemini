<?php
require_once __DIR__ . '/system/function.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $mysqli;
$user = getUser($mysqli);
if (!$user || (int)$user['id'] !== 1) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Доступ запрещён'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Только POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF
if (!check_csrf($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'CSRF token invalid'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mode = strtolower(trim((string)($_POST['season_mode'] ?? 'auto')));
if (!in_array($mode, season_allowed_modes(), true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Некорректный режим сезона'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ok = setGlobalSeasonMode($mysqli, $mode);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Не удалось сохранить настройку'], JSON_UNESCAPED_UNICODE);
    exit;
}

$active = ($mode === 'auto') ? getDefaultSeason() : $mode;

echo json_encode([
    'ok' => true,
    'season_mode' => $mode,
    'active_season' => $active,
], JSON_UNESCAPED_UNICODE);
