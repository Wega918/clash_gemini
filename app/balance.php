<?php
/**
 * app/balance.php
 * Возвращает JSON с текущими ресурсами пользователя и емкостями хранилищ.
 */

require_once __DIR__ . '/../system/function.php';
require_once __DIR__ . '/../system/game_data.php';

global $mysqli;

try {
    $user = getUser($mysqli);
    $user_id = (int)($user['id'] ?? 0);
    if ($user_id <= 0) {
        throw new RuntimeException('Unauthorized', 401);
    }

    $th = (int)($user['townhall_lvl'] ?? 1);

    $cap_gold = getTotalStorageCapacity($user_id, 'gold', $mysqli, $th);
    $cap_elixir = getTotalStorageCapacity($user_id, 'elixir', $mysqli, $th);
    $cap_dark = getTotalStorageCapacity($user_id, 'dark_elixir', $mysqli, $th);

    $payload = [
        'gold' => (int)($user['gold'] ?? 0),
        'elixir' => (int)($user['elixir'] ?? 0),
        'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
        'gems' => (int)($user['gems'] ?? 0),
        'cap_gold' => (int)$cap_gold,
        'cap_elixir' => (int)$cap_elixir,
        'cap_dark_elixir' => (int)$cap_dark,
    ];

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
