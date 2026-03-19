<?php
/**
 * building_router.php
 * Роутер для модальных окон деревни: Ратуша, Оборона, Казармы, Лаборатория, Клан.
 *
 * ВАЖНО: не ломает существующий роутер storage_router.php.
 */

require_once __DIR__ . '/../system/function.php';
require_once __DIR__ . '/../system/game_data.php';
require_once __DIR__ . '/storage_views.php'; // используем общие функции (getPlayerBuildingById, startBuildingUpgrade, buildNewBuilding и т.д.)
require_once __DIR__ . '/building_views.php';

try {
    global $mysqli;

    $section = cleanString($_GET['section'] ?? '', 30);
    $view    = cleanString($_GET['view'] ?? 'main', 30);
    $type    = cleanString($_GET['type'] ?? '', 60);
    $id      = toInt($_GET['id'] ?? 0);

    if ($section === '') {
        throw new RuntimeException('Не указан section', 400);
    }

    // Актуализируем ресурсы и получаем данные
    $userData = getUser($mysqli);

    // Маппинг секции -> стартовый экран
    switch ($view) {
        case 'main':
            echo renderSectionMainView($userData, $section);
            break;

        case 'list':
            if ($type === '') {
                throw new RuntimeException('Не указан type', 400);
            }
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderSectionListView($userData, $section, $type, $buildings);
            break;

        case 'detail':
            if ($id <= 0) {
                throw new RuntimeException('Не указан id', 400);
            }
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) {
                throw new RuntimeException('Здание не найдено', 404);
            }
            echo renderSectionDetailView($userData, $section, $building);
            break;

        // --- Actions ---
        case 'upgrade':
            if ($id <= 0) {
                throw new RuntimeException('Не указан id', 400);
            }
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) {
                throw new RuntimeException('Здание не найдено', 404);
            }
            $userData = startBuildingUpgrade($mysqli, $userData, $building);
            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderSectionDetailView($userData, $section, $building_updated);
            break;

        case 'buy':
            if ($type === '') {
                throw new RuntimeException('Не указан type', 400);
            }
            $result = buildNewBuilding($mysqli, $userData, $type);
            $userData = $result['user'];
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderSectionListView($userData, $section, $type, $buildings);
            break;

        case 'train':
            // barracks training (в очередь)
            if ($section !== 'barracks') {
                throw new RuntimeException('train доступен только в секции barracks', 400);
            }
            $unit = cleanString($_GET['unit'] ?? '', 60);
            $qty  = max(1, toInt($_GET['qty'] ?? 1));
            if ($unit === '') {
                throw new RuntimeException('Не указан unit', 400);
            }
            $userData = enqueueTraining($mysqli, $userData, $unit, $qty);
            echo renderSectionMainView($userData, $section);
            break;

        case 'research':
            // laboratory research start
            if ($section !== 'lab') {
                throw new RuntimeException('research доступен только в секции lab', 400);
            }
            $rid = cleanString($_GET['research'] ?? '', 80);
            if ($rid === '') {
                throw new RuntimeException('Не указан research', 400);
            }
            $userData = startResearch($mysqli, $userData, $rid);
            echo renderSectionMainView($userData, $section);
            break;

        case 'clan_create':
            if ($section !== 'clan') {
                throw new RuntimeException('clan_create доступен только в секции clan', 400);
            }
            $name = cleanString($_GET['name'] ?? '', 40);
            $desc = cleanString($_GET['desc'] ?? '', 200);
            if ($name === '') throw new RuntimeException('Не указано имя клана', 400);
            $userData = createClan($mysqli, $userData, $name, $desc);
            echo renderSectionMainView($userData, $section);
            break;

        case 'clan_join':
            if ($section !== 'clan') {
                throw new RuntimeException('clan_join доступен только в секции clan', 400);
            }
            $clan_id = toInt($_GET['clan_id'] ?? 0);
            if ($clan_id <= 0) throw new RuntimeException('Не указан clan_id', 400);
            $userData = joinClan($mysqli, $userData, $clan_id);
            echo renderSectionMainView($userData, $section);
            break;

        case 'clan_leave':
            if ($section !== 'clan') {
                throw new RuntimeException('clan_leave доступен только в секции clan', 400);
            }
            $userData = leaveClan($mysqli, $userData);
            echo renderSectionMainView($userData, $section);
            break;

        default:
            echo renderSectionMainView($userData, $section);
            break;
    }
} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        $code = ($e instanceof RuntimeException) ? 400 : 500;
    }
    $message = ($code >= 400 && $code < 500)
        ? $e->getMessage()
        : ((defined('ENVIRONMENT') && ENVIRONMENT === 'development')
            ? ($e->getMessage() . " on line " . $e->getLine())
            : 'Внутренняя ошибка сервера.');
    $data = function_exists('getExceptionData') ? getExceptionData($e) : [];
    http_response_code($code);
    $modal_id = htmlspecialchars(($section ?? 'modal') . '-modal');
    echo '<div class="modal-content">'
        . '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\''.$modal_id.'\')"><img src="/images/icons/close.png" alt="Закрыть"></button>';
    if (!empty($data) && is_array($data)) {
        $attrs = '';
        foreach ($data as $k => $v) {
            if ($v === null) continue;
            if (is_array($v) || is_object($v)) continue;
            $attrs .= ' data-' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
        }
        echo '<div class="js-action-error" style="display:none" data-code="'.(int)$code.'"'.$attrs.'></div>';
    }
    echo '<div class="error" style="margin:20px;">❌ Ошибка: '.htmlspecialchars($message).'</div></div>';
}
