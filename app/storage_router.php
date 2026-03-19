<?php
/**
 * storage_router.php
 * Динамический роутер для модальных окон (Хранилища и Производство).
 */
 
// Подключаем функции рендеринга и игровые данные
include __DIR__ . '/storage_views.php'; 
require_once __DIR__ . '/../system/game_data.php';

// Проверка, что функции рендеринга доступны
if (!function_exists('renderStorageMainView')) {
    echo '<div class="modal-content"><button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'storage-modal\')"><img src="/images/icons/close.png" alt="Закрыть"></button><div class="error" style="margin: 20px;">❌ Ошибка: Файл app/storage_views.php не загружен.</div></div>';
    return;
}

// Запускаем соответствующую функцию рендеринга
try {
    // Объявляем глобальные переменные, необходимые для работы с базой данных
    global $mysqli; 
    global $game_data;
    
    // Получаем запрашиваемый вид (main, production_main, list, detail, collect, buy, upgrade)
    $view = $_GET['view'] ?? 'main';
    $type = cleanString($_GET['type'] ?? '', 50);
    $id = toInt($_GET['id'] ?? 0); // ID строки player_buildings

    // Для всех мутаций требуем CSRF даже при GET/XHR.
    $actionViews = ['collect','upgrade','buy','train','research','clan_create','clan_join','hire'];
    if (in_array($view, $actionViews, true)) {
        requireActionCsrfFromRequest();
    }

    // Получаем актуальные данные пользователя (с обновлением ресурсов)
    $user = getUser($mysqli);
    $userData = $user;


    switch ($view) {
        case 'main':
            echo renderStorageMainView($userData);
            break;
            
        case 'production_main': // Роут для Производства
            echo renderProductionMainView($userData);
            break;
            
        case 'list':
            if (empty($type)) {
                throw new RuntimeException('Не указан тип здания', 400);
            }
            // *** ИСПРАВЛЕНИЕ ЛОГИКИ: Получаем все построенные здания этого типа ***
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderStorageListView($userData, $type, $buildings);
            break;

        case 'detail':
            if ($id === 0) {
                throw new RuntimeException('Не указан ID здания', 400);
            }
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) {
                throw new RuntimeException('Здание не найдено', 404);
            }
            echo renderStorageDetailView($userData, $building);
            break;
            
        // --- ДЕЙСТВИЯ (Actions) ---
        
        case 'collect': // Сбор ресурсов
            if ($id === 0) {
                throw new RuntimeException('Не указан ID здания для сбора', 400);
            }
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) {
                throw new RuntimeException('Здание для сбора не найдено', 404);
            }
            
            // 2. Выполняем сбор и обновление баланса (функция collectAndStoreResources уже вызывает updateResources внутри себя)
            $user = collectAndStoreResources($building, $userData, $mysqli);
            $userData = $user;
            
            // 3. Перезагружаем детали (Detail View) с новыми данными
            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderStorageDetailView($userData, $building_updated);
            break;

        case 'upgrade': // Запуск улучшения
            if ($id === 0) {
                throw new RuntimeException('Не указан ID здания для улучшения', 400);
            }
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) {
                throw new RuntimeException('Здание для улучшения не найдено', 404);
            }
            
            // Запускаем процесс улучшения
            $user = startBuildingUpgrade($mysqli, $userData, $building);
            $userData = $user;
            
            // Перезагружаем детали
            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderStorageDetailView($userData, $building_updated);
            break;
            
        case 'buy': // Покупка и начало строительства
            if (empty($type)) {
                throw new RuntimeException('Не указан тип здания для покупки', 400);
            }
            
            // Запускаем процесс покупки
            $result = buildNewBuilding($mysqli, $userData, $type);
            $userData = $result['user'];
            
            // Перенаправляем на список, чтобы увидеть новое здание
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderStorageListView($userData, $type, $buildings);
            break;


        default:
            echo renderStorageMainView($userData);
            break;
    }
} catch (Throwable $e) {
    $errorMessage = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? $e->getMessage() . " on line " . $e->getLine() . " (" . $view . ")" : 'Внутренняя ошибка сервера.';
    http_response_code($e->getCode() ?: 500);
    
    // Определяем ID модального окна для кнопки закрытия
    $modal_id = strpos($view, 'production') !== false ? 'production-modal' : 'storage-modal';

    // Оборачиваем ошибку в модальный контент
    echo '<div class="modal-content"><button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'' . htmlspecialchars($modal_id) . '\')"><img src="/images/icons/close.png" alt="Закрыть"></button><div class="error" style="margin: 20px;">❌ Ошибка: ' . htmlspecialchars($errorMessage) . '</div></div>';
}
?>