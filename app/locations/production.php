<?php
/**
 * app/locations/production.php
 * Локация: Производство (backend + views)
 * Endpoint: ajax.php?page=production
 */

/**
 * Локальные хелперы для этой локации (уникальные имена, чтобы не конфликтовать).
 * ВАЖНО: объявлены ДО try/switch, чтобы были доступны сразу.
 */
function production_loc_getMaxCountForTH(string $building_id, int $th_lvl): int {
    // Prefer shared implementation if available (single source of truth)
    if (function_exists('getMaxCountForTH')) {
        return (int)getMaxCountForTH($building_id, $th_lvl);
    }

    // Safe fallback (should rarely be used)
    static $fallback = [
        'gold_mine' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
        'elixir_collector' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
        'dark_elixir_drill' => [1 => 0, 7 => 1, 8 => 2, 9 => 3, 11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 4, 16 => 4],
    ];

    $max_counts = $fallback[$building_id] ?? [];
    $count = 0;
    foreach ($max_counts as $th_req => $max) {
        if ($th_lvl >= (int)$th_req) $count = (int)$max;
    }
    return (int)$count;
}


function production_loc_renderBalancePayload(mysqli $mysqli, array $user, string $deltaRes = '', int $deltaAmt = 0): string {
    $uid = (int)($user['id'] ?? 0);
    $th = (int)($user['townhall_lvl'] ?? 1);

    $cap_gold = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid, 'gold', $mysqli, $th) : 0;
    $cap_elixir = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid, 'elixir', $mysqli, $th) : 0;
    $cap_dark = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid, 'dark_elixir', $mysqli, $th) : 0;

    // Для гемов "емкость" не важна — чтобы бар не был 0% всегда, ставим безопасный максимум.
    $gems = (int)($user['gems'] ?? 0);
    $cap_gems = max(1000, $gems, 1);

    $gold = (int)($user['gold'] ?? 0);
    $elixir = (int)($user['elixir'] ?? 0);
    $dark_elixir = (int)($user['dark_elixir'] ?? 0);

    $deltaRes = (string)$deltaRes;
    $deltaAmt = (int)$deltaAmt;
    if ($deltaAmt === 0) { $deltaRes = ''; }

    // Backward-compatible alias for старого фронта (плюс при сборе)
    $collectRes = ($deltaAmt > 0) ? $deltaRes : '';
    $collectAmt = ($deltaAmt > 0) ? $deltaAmt : 0;

    $collectBlocked = isset($user['_collect_blocked']) ? (string)$user['_collect_blocked'] : '';

    return '<div class="js-balance-payload" style="display:none"'
        . ' data-gold="' . $gold . '"'
        . ' data-elixir="' . $elixir . '"'
        . ' data-dark_elixir="' . $dark_elixir . '"'
        . ' data-gems="' . $gems . '"'
        . ' data-cap_gold="' . $cap_gold . '"'
        . ' data-cap_elixir="' . $cap_elixir . '"'
        . ' data-cap_dark_elixir="' . $cap_dark . '"'
        . ' data-cap_gems="' . $cap_gems . '"'
        . ' data-delta_res="' . htmlspecialchars($deltaRes, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-delta_amt="' . $deltaAmt . '"'
        . ' data-collect_res="' . htmlspecialchars($collectRes, ENT_QUOTES, 'UTF-8') . '"'
        . ' data-collect_amt="' . $collectAmt . '"'
        . ' data-collect_blocked="' . htmlspecialchars($collectBlocked, ENT_QUOTES, 'UTF-8') . '"'
        . '></div>';
}

function production_loc_getNextTownhallForMoreSlots(string $building_id, int $current_th_lvl): int {
    // Prefer shared implementation if available (single source of truth)
    if (function_exists('getNextTownhallForMoreSlots')) {
        return (int)getNextTownhallForMoreSlots($building_id, $current_th_lvl);
    }

    // Safe fallback based on production_loc_getMaxCountForTH
    static $fallback = [
        'gold_mine' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
        'elixir_collector' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
        'dark_elixir_drill' => [1 => 0, 7 => 1, 8 => 2, 9 => 3, 11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 4, 16 => 4],
    ];

    $map = $fallback[$building_id] ?? [];
    if (!$map) return 0;

    $current_max = production_loc_getMaxCountForTH($building_id, $current_th_lvl);
    foreach ($map as $th_req => $max) {
        $th_req = (int)$th_req;
        $max = (int)$max;
        if ($th_req > $current_th_lvl && $max > $current_max) return $th_req;
    }
    return 0;
}


try {
    global $mysqli, $game_data;

    $view = $_GET['view'] ?? 'main';
    $type = cleanString($_GET['type'] ?? '', 50);
    $id = toInt($_GET['id'] ?? 0);

    $userData = getUser($mysqli);
    if (function_exists('finalizeCompletedBuildTimers')) {
        finalizeCompletedBuildTimers($mysqli, (int)$userData['id']);
    }

    $allowedTypes = ['gold_mine','elixir_collector','dark_elixir_drill'];

    switch ($view) {
        case 'main':
            echo renderProductionMainView($userData);
            break;

        case 'list':
            if (empty($type) || !in_array($type, $allowedTypes, true)) {
                throw new RuntimeException('Не указан или недопустимый тип здания', 400);
            }
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderStorageListView($userData, $type, $buildings); // используем общий list-view
            break;

        case 'detail':
            if ($id === 0) throw new RuntimeException('Не указан ID здания', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание не найдено', 404);
            echo renderStorageDetailView($userData, $building); // используем общий detail-view
            break;

        case 'collect':
            if ($id === 0) throw new RuntimeException('Не указан ID здания для сбора', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание для сбора не найдено', 404);
            $userData = collectAndStoreResources($building, $userData, $mysqli);
            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderStorageDetailView($userData, $building_updated ?: $building);
            break;

                case 'collect_all':
            if (empty($type) || !in_array($type, $allowedTypes, true)) {
                throw new RuntimeException('Не указан или недопустимый тип здания для сбора', 400);
            }
            $buildings = getPlayerBuildingsByType($mysqli, $type);

            // Определяем ресурс по типу (для корректного сообщения "хранилища заполнены")
            $res_by_type = [
                'gold_mine' => 'gold',
                'elixir_collector' => 'elixir',
                'dark_elixir_drill' => 'dark_elixir',
            ];
            $res_for_type = $res_by_type[$type] ?? '';

            $has_stored = false;
            foreach ($buildings as $b0) {
                if (($b0['status'] ?? '') !== 'active') continue;
                if ((int)($b0['stored_resource'] ?? 0) > 0) { $has_stored = true; break; }
            }

            $storage_full = false;
            if ($res_for_type) {
                $uid2 = (int)($userData['id'] ?? 0);
                $th2  = (int)($userData['townhall_lvl'] ?? 1);
                $cap2 = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid2, $res_for_type, $mysqli, $th2) : 0;
                $cur2 = (int)($userData[$res_for_type] ?? 0);
                if ($cap2 > 0 && $cur2 >= $cap2) $storage_full = true;
            }

            $total_amt = 0;
            $res_key = '';
            foreach ($buildings as $b) {
                if (($b['status'] ?? '') !== 'active') continue;
                if ((int)($b['stored_resource'] ?? 0) <= 0) continue;

                $userData = collectAndStoreResources($b, $userData, $mysqli);
                if (isset($userData['_collect_amt']) && (int)$userData['_collect_amt'] > 0) {
                    $total_amt += (int)$userData['_collect_amt'];
                    $res_key = (string)($userData['_collect_res'] ?? $res_key);
                }
                unset($userData['_collect_res'], $userData['_collect_amt']);
            }

            // Если есть ресурс в производственных зданиях, но места в хранилищах нет — показываем корректное сообщение
            if ($total_amt <= 0 && $has_stored && $storage_full) {
                $userData['_collect_blocked'] = 'storage_full';
            } else {
                unset($userData['_collect_blocked']);
            }

            // Обновляем список после сбора
            $buildings = getPlayerBuildingsByType($mysqli, $type);

            // По умолчанию остаёмся в списке, но с главной можно вернуть main
            $return_to = cleanString($_GET['return'] ?? 'list', 20);
            if ($return_to === 'main') {
                echo renderProductionMainView($userData, $res_key, $total_amt);
            } else {
                echo renderStorageListView($userData, $type, $buildings, $res_key, $total_amt);
            }
            break;
    

        case 'upgrade':
            if ($id === 0) throw new RuntimeException('Не указан ID здания для улучшения', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание для улучшения не найдено', 404);
            $userData = startBuildingUpgrade($mysqli, $userData, $building);
            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderStorageDetailView($userData, $building_updated ?: $building);
            break;

        case 'buy':
            if (empty($type) || !in_array($type, $allowedTypes, true)) {
                throw new RuntimeException('Не указан или недопустимый тип здания для покупки', 400);
            }
            $result = buildNewBuilding($mysqli, $userData, $type);
            $userData = $result['user'] ?? $userData;
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderStorageListView($userData, $type, $buildings);
            break;

        default:
            echo renderProductionMainView($userData);
            break;
    }

} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
        // RuntimeException здесь обычно означает "ожидаемую" игровую ошибку
        $code = ($e instanceof RuntimeException) ? 400 : 500;
    }

    $publicMessage = ($code >= 400 && $code < 500)
        ? $e->getMessage()
        : ((defined('ENVIRONMENT') && ENVIRONMENT === 'development')
            ? ($e->getMessage() . " on line " . $e->getLine() . " (" . ($_GET['view'] ?? 'main') . ")")
            : 'Внутренняя ошибка сервера.');

    $data = function_exists('getExceptionData') ? getExceptionData($e) : [];
    http_response_code($code);
    ?>
    <div class="modal-content">
      <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('production-modal')"><img src="/images/icons/close.png" alt="Закрыть"></button>
      <?php if (!empty($data) && is_array($data)):
            $attrs = '';
            foreach ($data as $k => $v) {
                if ($v === null) continue;
                if (is_array($v) || is_object($v)) continue;
                $attrs .= ' data-' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
            }
      ?>
        <div class="js-action-error" style="display:none" data-code="<?= (int)$code ?>"<?= $attrs ?>></div>
      <?php endif; ?>
      <div class="error" style="margin: 20px;">❌ Ошибка: <?= htmlspecialchars($publicMessage) ?></div>
    </div>
    <?php
}

/**
 * app/storage_views.php
 * Полный файл представлений для модальных окон (Хранилища, Производство, Списки, Детали).
 * Включает в себя всю логику отображения и унифицированный дизайн заголовков.
 */

// -------------------------------------------------------------------------------------
// 1. КОНСТАНТЫ И ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// -------------------------------------------------------------------------------------

// Определяем константы ресурсов, если они еще не определены
if (!defined('RES_GOLD')) define('RES_GOLD', 'gold');
if (!defined('RES_ELIXIR')) define('RES_ELIXIR', 'elixir');
if (!defined('RES_DARK')) define('RES_DARK', 'dark_elixir');
if (!defined('RES_GEMS')) define('RES_GEMS', 'gems');

/**
 * Форматирование количества ресурсов (например, 1.5M, 250K)
 */
function format_resource_amount($value): string {
    if ($value === null) return '0';
    $value = (int)$value;
    if ($value >= 1000000) {
        return number_format($value / 1000000, 1, '.', ',') . 'M';
    }
    if ($value >= 1000) {
        return number_format($value / 1000, 1, '.', ',') . 'K';
    }
    return number_format($value, 0, '.', ',');
}

/**
 * Форматирование времени (чч мм сс)
 */
function format_time_display(int $time): string {
    $days = floor($time / 86400);
    $hours = floor(($time % 86400) / 3600);
    $minutes = floor(($time % 3600) / 60);
    $seconds = $time % 60;
    
    $output = '';
    if ($days > 0) $output .= $days . 'д ';
    if ($hours > 0) $output .= $hours . 'ч ';
    if ($minutes > 0) $output .= $minutes . 'м ';
    if ($seconds > 0 || ($days == 0 && $hours == 0 && $minutes == 0)) $output .= $seconds . 'с';
    
    return trim($output);
}

/**
 * Получение пути к изображению здания в зависимости от типа и уровня
 */
function getBuildingImageResourcePath(string $building_id, int $level): string {
    $base_path = '/images/building/';
    // Для некоторых зданий картинка может меняться не каждый уровень, 
    // но здесь предполагаем наличие всех уровней или базовую логику.
    $level_suffix = $level;

    switch ($building_id) {
        case 'gold_storage':
        case 'gold_mine': 
            // Пример: если уровень > 11, используем картинку 11, если нет файлов
            // Здесь ставим прямую ссылку
            return $base_path . 'Gold_Storage/Gold_Storage' . $level_suffix . '.png';
            
        case 'elixir_storage':
        case 'elixir_collector': 
            return $base_path . 'Elixir_Storage/Elixir_Storage' . $level_suffix . '.png';
            
        case 'dark_storage':
        case 'dark_elixir_drill': 
            return $base_path . 'Dark_Elixir/Dark_Elixir_Storage' . $level_suffix . '.png';
            
        case 'barracks':
            return $base_path . 'Barracks/Barracks' . $level_suffix . '.png';
            
        case 'army_camp':
            return $base_path . 'Army_Camp/Army_Camp' . $level_suffix . '.png';
            
        default:
            // Дефолтная иконка, если тип не найден
            return '/images/building/production.png'; 
    }
}

/**
 * Получение иконки ресурса
 */
function getResourceIconPath(string $resource_type): string {
    $resource_type = strtolower($resource_type);
    // Удаляем префикс RES_ или res_ если есть
    if (strpos($resource_type, 'res_') === 0) {
        $resource_type = substr($resource_type, 4);
    }
    
    switch ($resource_type) {
        case 'gold':
            return '/images/icons/gold.png';
        case 'elixir':
            return '/images/icons/elixir.png';
        case 'dark_elixir':
        case 'dark':
            return '/images/icons/fuel.png';
        case 'gems':
            return '/images/icons/gems.png';
        default:
            return '';
    }
}

/**
 * Описания зданий (тексты)
 */
$storage_descriptions = [
    'gold_storage' => 'Ваше золото хранится здесь. Не подпускайте к нему гоблинов! Улучшайте хранилище, чтобы повысить его вместимость и прочность.',
    'elixir_storage' => 'В этих хранилищах содержится эликсир, добытый из лей-линий. Повысьте их уровень, чтобы увеличить максимальный запас эликсира.',
    'dark_storage' => 'Мощь чёрного эликсира невозможно удержать в обычном сосуде. Его сила втрое больше, поэтому мы изобрели особое кубическое хранилище!',
    'gold_mine' => 'Добывает золото из недр земли бесконечно. Однако, шахта имеет предел вместимости. Не забывайте собирать ресурсы!',
    'elixir_collector' => 'Качает эликсир из лей-линий под вашей деревней. Улучшайте сборщики, чтобы ускорить добычу.',
    'dark_elixir_drill' => 'Наши алхимики нашли способ добывать чистый Черный Эликсир. Это редкий и ценный ресурс.',
];


// -------------------------------------------------------------------------------------
// 3. ГЛАВНОЕ МЕНЮ: ПРОИЗВОДСТВО (PRODUCTION MAIN)
// -------------------------------------------------------------------------------------
function renderProductionMainView(array $user, string $deltaRes = '', int $deltaAmt = 0): string {
    // ВНИМАНИЕ: этот файл подключается внутри функции generatePageContent() (ajax.php),
    // поэтому обычная переменная $mysqli тут НЕ видна внутри функций без global.
    // Из-за этого появлялся Warning: Undefined variable $mysqli и HTTP 500.
    global $mysqli;
    ob_start();
    
    $modal_id = 'production-modal';

    // Иконки для меню производства
    $main_view_icons = [
        'gold_mine' => '/images/building/Gold_Storage/Gold_Storage16.png', // Используем похожий стиль или иконку шахты если есть
        'elixir_collector' => '/images/building/Elixir_Storage/Elixir_Storage16.png',
        'dark_elixir_drill' => '/images/building/Dark_Elixir/Dark_Elixir_Storage9.png',
    ];
    ?>
    <div class="production-main-view">
        <?= production_loc_renderBalancePayload($mysqli, $user, $deltaRes, $deltaAmt); ?>
        <div class="modal-header-controls production-main-header">
             <button class="back-modal modal-button-corner hidden" onclick="productionGoBack('<?= $modal_id ?>', 'production_main')">
                <img src="/images/icons/left.png" alt="Назад">
             </button>
             
             <div class="production-title-wrap">
<h2 class="modal-title-text-inside-panel">ПРОИЗВОДСТВО</h2>
             </div>
             
             <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
             </button>
        </div>
        
        <div class="modal-body-custom resource-grid-wrapper">
            <div class="resource-selection main-production-grid">
                
                <div class="resource-card card-mine" onclick="productionLoadList('<?= $modal_id ?>', 'gold_mine')">
                    <img src="<?= $main_view_icons['gold_mine'] ?>" alt="Золотые шахты">
                    <h3 class="resource-title-text">Золотая шахта</h3>
                </div>
                
                <div class="resource-card card-collector" onclick="productionLoadList('<?= $modal_id ?>', 'elixir_collector')">
                    <img src="<?= $main_view_icons['elixir_collector'] ?>" alt="Сборщики">
                    <h3 class="resource-title-text">Сборщик эликсира</h3>
                </div>
                
                <div class="resource-card card-drill" onclick="productionLoadList('<?= $modal_id ?>', 'dark_elixir_drill')">
                    <img src="<?= $main_view_icons['dark_elixir_drill'] ?>" alt="Скважины">
                    <h3 class="resource-title-text">Скважина ЧЭ</h3>
                </div>
                
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// -------------------------------------------------------------------------------------
// 4. СПИСОК ЗДАНИЙ (LIST VIEW)
// -------------------------------------------------------------------------------------
function renderStorageListView(array $user, string $type, array $built_buildings, string $deltaRes = '', int $deltaAmt = 0): string {
    global $game_data;
    global $storage_descriptions;
    global $mysqli;
    
    $th_lvl = $user['townhall_lvl'];
    $max_count = production_loc_getMaxCountForTH($type, (int)$th_lvl);
    
    // Получаем имя здания из game_data, если нет - ставим дефолт
    $building_type_name = $game_data[$type]['name'] ?? 'Здание';
    $description = $storage_descriptions[$type] ?? 'Здесь добываются ваши ресурсы: золото, эликсир и тёмный эликсир. Улучшайте производственные здания, чтобы повысить скорость добычи и увеличить объём накопления, который можно собрать одним нажатием.';
    
    // Определяем, в каком модальном окне мы находимся, чтобы кнопка "Назад" вела куда надо
    $go_back_view = 'main';
    $modal_id = 'production-modal';
    $is_production_list = true;

    // Есть ли что собирать (хотя бы в одном здании есть накопления)
    $has_collectable = false;
    foreach ($built_buildings as $cb) {
        if (($cb['status'] ?? '') !== 'active') continue;
        if ((int)($cb['stored_resource'] ?? 0) > 0) { $has_collectable = true; break; }
    }
    
    ob_start();
    ?>
    <div class="storage-list-view">
        <?= production_loc_renderBalancePayload($mysqli, $user, $deltaRes, $deltaAmt); ?>
        <div class="modal-header-controls">
             <button class="back-modal modal-button-corner" onclick="productionGoBack('<?= $modal_id ?>', '<?= $go_back_view ?>')">
                <img src="/images/icons/left.png" alt="Назад">
             </button>
             
             <h2 class="modal-title-text-inside-panel"><?= htmlspecialchars($building_type_name) ?> (<?= count($built_buildings) ?>/<?= $max_count ?>)</h2>
             
             <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
             </button>
        </div>

        <div class="modal-body building-list-view">
            <?php 
            // Получаем требования для постройки 1-го уровня
            $initial_level_stats = $game_data[$type]['levels'][1] ?? [];
            $initial_th_req = $initial_level_stats['th_req'] ?? 1; 

            // Логика отображения: 
            // Показываем контент, если есть здания ИЛИ если их нет, но уровень ТХ позволяет строить.
            // Если зданий 0 и ТХ мал -> ошибка.
            
            if (empty($built_buildings) && $th_lvl < $initial_th_req): ?>
                <div class="alert alert-warning" style="text-align: center; margin-top: 20px;">
                    <strong>Здание недоступно!</strong><br>
                    Требуется Ратуша Уровня <?= $initial_th_req ?>.
                </div>
            <?php else: ?>
                
                <p class="modal-hint-list detail-description-text" style="margin-bottom: 15px; color: #6d4421; font-style: italic; font-size: 13px;">
                    <?= htmlspecialchars($description) ?>
                </p>

                <?php if ($is_production_list): ?>
                    <div class="collect-all-row" style="margin: 6px 0 14px; text-align:center;">
                        <button class="btn btn-collect-all <?= $has_collectable ? '' : 'btn-disabled' ?>"
                                onclick="event.stopPropagation(); productionCollectAll('<?= $modal_id ?>', '<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>');">
                            Собрать все
                        </button>
                    </div>
                <?php endif; ?>

                <?php foreach ($built_buildings as $b): 
                     $level = $b['level'];
                     $info = $game_data[$b['building_id']]['levels'][$level] ?? [];
                     
                     // Статус
                     $is_upgrading = ($b['status'] === 'upgrading');
                     $is_constructing = ($b['status'] === 'constructing');
                     $item_class = ($is_upgrading || $is_constructing) ? 'item-upgrading' : '';
                     
                     // Характеристики для превью
                     $hp = number_format($info['hp'] ?? 0, 0, '', ' ');
                     $capacity = $info['capacity'] ?? 0;
                     $display_capacity = format_resource_amount($capacity); 
                     
                     // Для производственных зданий считаем процент заполнения
                     $fill_percent = 0;
                     $current_stored = 0;
                     if ($capacity > 0 && isset($b['stored_resource'])) {
                         $fill_percent = round($b['stored_resource'] / $capacity * 100);
                         $current_stored = number_format($b['stored_resource'], 0, '', ' ');
                     }
                ?>
                    <div class="building-list-item stylized-card <?= $item_class ?>" onclick="productionLoadDetail('<?= $modal_id ?>', <?= $b['id'] ?>)">
                        <div class="item-icon-full">
                           <img src="<?= htmlspecialchars(getBuildingImageResourcePath($b['building_id'], $level)) ?>" alt="<?= htmlspecialchars($building_type_name) ?>">
                        </div>
                        
                        <div class="item-info-extended">
                            <strong class="item-title-text"><?= htmlspecialchars($building_type_name) ?> Ур. <?= $level ?></strong>
                            
                            <?php if ($is_upgrading || $is_constructing): ?>
                                <?php
                                    $end_ts = (int)($b['finish_time'] ?? 0);
                                    $dur_sec = (int)($info['time'] ?? 0);
                                    $start_ts = ($end_ts > 0 && $dur_sec > 0) ? ($end_ts - $dur_sec) : 0;
                                    $left_sec = max(0, $end_ts - time());
                                ?>
                                <p class="status-text text-primary" style="margin:0;">
                                    🔨 <?= $is_constructing ? 'Строится' : 'Улучшается' ?>
                                </p>
                                <?php if ($end_ts > 0): ?>
                                <div class="js-upgrade-progress upgrade-progress" data-start="<?= (int)$start_ts ?>" data-end="<?= (int)$end_ts ?>">
                                    <div class="upgrade-progress-bar"><div class="upgrade-progress-fill"></div></div>
                                    <div class="upgrade-progress-meta">
                                        <span class="upgrade-left">⏳ <?= format_time_display($left_sec) ?></span>
                                        <span class="upgrade-percent">0%</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php elseif ($is_production_list): ?>
                                <p class="status-text">Наполнено: <?= $current_stored ?> / <?= $display_capacity ?></p>
                                <div class="progress-bar-small">
                                    <div class="progress-fill" style="width: <?= $fill_percent ?>%; background-color: #f5a623;"></div>
                                </div>
                            <?php else: ?>
                                <p class="status-text">Вместимость: <?= $display_capacity ?></p>
                                <p class="hp-text">Здоровье: ❤️ <?= $hp ?></p>
                            <?php endif; ?>
                        </div>
                        
                        <!-- стрелка перехода скрыта по ТЗ -->
                    </div>
                <?php endforeach; ?>

                <?php 
                // Показываем этот блок только если не достигнут лимит
                if (count($built_buildings) < $max_count): 
                    $cost = $initial_level_stats['cost'] ?? 0;
                    
                    // Определяем ресурс для постройки
                    $res_type_const = is_array($initial_level_stats['res_type'] ?? null) 
                                        ? $initial_level_stats['res_type'][0] 
                                        : ($initial_level_stats['res_type'] ?? RES_GOLD);
                                        
                    $res_icon = getResourceIconPath($res_type_const);
                    
                    // Проверка цены
                    $user_res_key = ($res_type_const === RES_DARK) ? 'dark_elixir' : str_replace('res_', '', strtolower($res_type_const));
                    $user_res_key = strtolower($user_res_key);
                    $can_afford = ($user[$user_res_key] ?? 0) >= $cost;
                    $price_color = $can_afford ? 'green' : 'red';
                ?>
                    <div class="building-list-item stylized-card building-placeholder placeholder-available">
                        <div class="item-icon-full">
                            <img src="<?= htmlspecialchars(getBuildingImageResourcePath($type, 1)) ?>" alt="Новая постройка" style="opacity: 0.7;">
                        </div>
                        <div class="item-info-extended">
                            <strong class="item-title-text">Новое <?= htmlspecialchars($building_type_name) ?></strong>
                            <p>Уровень 1</p>
                            <p>Цена: <span style="color: <?= $price_color ?>; font-weight: bold;"><?= format_resource_amount($cost) ?></span> <img src="<?= $res_icon ?>" width="14" style="vertical-align: middle;"></p>
                        </div>
                        <div class="item-action-button">
                             <button class="btn btn-buy-action <?= $can_afford ? '' : 'btn-disabled' ?>" 
                                     onclick="productionStartBuilding('<?= $modal_id ?>', '<?= htmlspecialchars($type) ?>')">
                                Построить
                             </button>
                        </div>
                    </div>
                    
                <?php elseif (count($built_buildings) >= $max_count): 
                    // Если лимит достигнут, проверяем, открываются ли слоты на след. уровне ТХ
                    // Это сложная логика, упростим: если не макс ТХ, пишем подсказку
                    if ($th_lvl < 16) {
                ?>
                     <?php
                        $next_th = production_loc_getNextTownhallForMoreSlots($type, (int)$th_lvl);
                        $building_name = $game_data[$type]['name'] ?? $building_type_name;
                     ?>
                     <div class="building-list-item stylized-card item-disabled max-reached-slot" style="opacity: 0.7; background: #eee;">
                        <div class="item-icon-full">
                            <span style="font-size: 30px;">🔒</span>
                        </div>
                        <div class="item-info-extended">
                            <strong class="item-title-text" style="color: gray;">Слот недоступен</strong>
                            <?php if ($next_th > 0): ?>
                                <p style="font-size: 11px;"><?= htmlspecialchars($building_name) ?> будет доступно с <b>Ратуша ур. <?= (int)$next_th ?></b>.</p>
                            <?php else: ?>
                                <p style="font-size: 11px;">Новых слотов для этого здания на текущих уровнях Ратуши больше нет.</p>
                            <?php endif; ?>
                        </div>
                        <!-- стрелка перехода скрыта по ТЗ -->
                     </div>
                <?php } endif; ?>

            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// -------------------------------------------------------------------------------------
// 5. ДЕТАЛИ ЗДАНИЯ (DETAIL VIEW)
// -------------------------------------------------------------------------------------
function renderStorageDetailView(array $user, array $building): string {
    global $mysqli, $game_data;
    
    // Валидация входных данных
    $building_row_id = $building['id'] ?? 0;
    $building_id = $building['building_id'] ?? null;
    
    if (!$building_id || !$building_row_id) {
         return '<div class="error">Ошибка данных здания.</div>';
    }
    
    $level = (int)($building['level'] ?? 1);
    
    // Получаем данные из конфига
    $info = $game_data[$building_id];
    $stats = $info['levels'][$level] ?? null;
    
    // Данные для следующего уровня (для апгрейда)
    $next_level = $level + 1;
    $next_stats = $info['levels'][$next_level] ?? null;
    
    $is_resource_generator = isset($stats['rate']);
    $is_upgrading = ($building['status'] === 'upgrading' || $building['status'] === 'constructing');
    $building_image_path = getBuildingImageResourcePath($building_id, $level); 
    $th_lvl = $user['townhall_lvl'];

    // Определяем ресурс для расчета общей емкости.
    // Важно: в game_data.php у производящих зданий res_type может означать ресурс стоимости апгрейда,
    // поэтому для добывающих зданий определяем тип по building_id.
    $resource_type_key = '';
    if ($is_resource_generator) {
        if ($building_id === 'gold_mine') $resource_type_key = 'gold';
        elseif ($building_id === 'elixir_collector') $resource_type_key = 'elixir';
        elseif ($building_id === 'dark_elixir_drill') $resource_type_key = 'dark_elixir';
    } else {
        $plain = str_replace('_storage', '', $building_id);
        if ($plain === 'dark') $resource_type_key = 'dark_elixir';
        elseif ($plain === 'gold') $resource_type_key = 'gold';
        elseif ($plain === 'elixir') $resource_type_key = 'elixir';
    }

    // Расчет общей емкости (и свободного места) для нужного ресурса
    $total_storage_capacity = 0;
    $available_space = null;
    if (!empty($resource_type_key) && function_exists('getTotalStorageCapacity')) {
        global $mysqli;
        $total_storage_capacity = (int)getTotalStorageCapacity($user['id'], $resource_type_key, $mysqli, $th_lvl);
        $available_space = $total_storage_capacity - (int)($user[$resource_type_key] ?? 0);
    }
    
    // Навигация
    $modal_id = 'production-modal';
    $go_back_view = 'main';

    
    ob_start();
    ?>
    <div class="storage-detail-view">
        <?= production_loc_renderBalancePayload($mysqli, $user, $user['_delta_res'] ?? ($user['_collect_res'] ?? ''), (int)($user['_delta_amt'] ?? ($user['_collect_amt'] ?? 0))); ?>
        <div class="modal-header-controls">
            <button class="back-modal modal-button-corner" onclick="productionGoBack('<?= $modal_id ?>', '<?= $go_back_view ?>')">
                <img src="/images/icons/left.png" alt="Назад">
            </button>
            
            <h2 class="modal-title-text-inside-panel"><?= htmlspecialchars($info['name'] ?? 'Здание') ?> Ур. <?= $level ?></h2>
            
            <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
            </button>
        </div>
        
        <div class="building-card-large no-frame-bg">
            <div class="building-card-image-only">
                <img src="<?= htmlspecialchars($building_image_path) ?>" alt="Здание">
            </div>
        </div>

        <div class="modal-body building-detail-content">
            
            <?php /* Процесс стройки/улучшения показываем в зоне кнопок (вместо отдельного alert-блока) */ ?>
            
            <div class="info-box current-stats-box">
                <h3>Характеристики (Ур. <?= $level ?>)</h3>
                
                <div class="stat-row">
                    <span>❤️ Здоровье</span>
                    <span class="text-primary"><?= number_format($stats['hp'] ?? 0, 0, '', ' ') ?></span>
                </div>
                
                <?php if ($is_resource_generator): ?>
                    <div class="stat-row">
                        <span>⚡️ Добыча</span>
                        <span class="text-primary"><?= format_resource_amount($stats['rate'] ?? 0) ?>/час</span>
                    </div>
                    <div class="stat-row">
                        <span>📦 Вместимость (здание)</span>
                        <span class="text-primary"><?= format_resource_amount($stats['capacity'] ?? 0) ?></span>
                    </div>
                    <div style="border-top: 1px solid #c7b08d; margin: 5px 0;"></div>
                    <div class="stat-row">
                        <span>💧 Накоплено</span>
                        <span class="text-primary"><?= number_format($building['stored_resource'] ?? 0, 0, '', ' ') ?></span>
                    </div>
                    
                    <?php
                        $stored_now = (int)($building['stored_resource'] ?? 0);
                        $space_ok = ($available_space === null) ? true : ($available_space > 0);
                        $can_collect = $stored_now > 0 && !$is_upgrading && $space_ok;

                        if ($is_upgrading) $btn_text = 'Улучшение активно';
                        elseif ($stored_now <= 0) $btn_text = 'Пусто';
                        elseif (!$space_ok) $btn_text = 'Хранилище заполнено';
                        else $btn_text = 'Собрать ресурсы';
                    ?>

                    <?php if ($stored_now > 0 && !$space_ok): ?>
                        <div class="alert alert-warning info-box" style="margin-top:10px;">
                            ⚠️ Хранилища заполнены. Освободите место, чтобы собрать оставшееся.
                        </div>
                    <?php endif; ?>

                    <?php if ($is_upgrading): ?>
                        <?php
                            $end_ts = (int)($building['finish_time'] ?? 0);
                            $dur_sec = (int)($stats['time'] ?? 0);
                            $start_ts = ($end_ts > 0 && $dur_sec > 0) ? ($end_ts - $dur_sec) : 0;
                            $left_sec = max(0, $end_ts - time());
                        ?>
                        <div class="js-upgrade-progress upgrade-progress" data-start="<?= (int)$start_ts ?>" data-end="<?= (int)$end_ts ?>" style="margin-top:10px;">
                            <div class="upgrade-progress-bar"><div class="upgrade-progress-fill"></div></div>
                            <div class="upgrade-progress-meta">
                                <span class="upgrade-left">⏳ <?= format_time_display($left_sec) ?></span>
                                <span class="upgrade-percent">0%</span>
                            </div>
                        </div>
                    <?php else: ?>
                        <button class="btn btn-block action-btn <?= $can_collect ? 'btn-collect' : 'btn-disabled' ?>"
                                <?= $can_collect ? 'onclick="productionCollectResource(\''.$modal_id.'\','.$building_row_id.')"' : '' ?>>
                            <?= $btn_text ?>
                        </button>
                    <?php endif; ?>
                    
                <?php else: // Хранилище ?>
                    <div class="stat-row">
                        <span>📦 Вместимость (это хранилище)</span>
                        <span class="text-primary"><?= format_resource_amount($stats['capacity'] ?? 0) ?></span>
                    </div>
                    <div style="border-top: 1px solid #c7b08d; margin: 5px 0;"></div>
                    <div class="stat-row">
                        <span>📦 Общая вместимость (все хранилища)</span>
                        <span class="text-primary"><?= format_resource_amount($total_storage_capacity) ?></span>
                    </div>
                    <div class="stat-row">
                        <span>💧 Баланс</span>
                        <span class="text-primary"><?= number_format($user[$resource_type_key] ?? 0, 0, '', ' ') ?></span>
                    </div>
                <?php endif; ?>
            </div>


            <?php if ($next_stats): 
                $up_res_const = is_array($next_stats['res_type']) ? $next_stats['res_type'][0] : ($next_stats['res_type'] ?? RES_GOLD);
                $up_res_key = ($up_res_const === RES_DARK) ? 'dark_elixir' : str_replace('res_', '', strtolower($up_res_const));
                $up_res_key = strtolower($up_res_key);
                
                $cost = $next_stats['cost'] ?? 0;
                $res_icon = getResourceIconPath($up_res_const);
                
                $user_res_amount = $user[$up_res_key] ?? 0;
                $th_req = $next_stats['th_req'] ?? 1;
                
                $can_afford = $user_res_amount >= $cost;
                $th_ok = $th_lvl >= $th_req;
                
                $can_upgrade = $can_afford && $th_ok && !$is_upgrading;

            ?>
                <div class="info-box upgrade-info-box" style="margin-top: 15px;">
                    <h3>Улучшить до Ур. <?= $next_level ?></h3>
                    
                    <div class="stat-row">
                        <span>Ратуша Ур. <?= $th_req ?></span>
                        <span style="color: <?= $th_ok ? 'green' : 'red' ?>;"><?= $th_ok ? '✅ OK' : '❌ Требуется' ?></span>
                    </div>
                    <div class="stat-row">
                        <span>Цена</span>
                        <span style="color: <?= $can_afford ? 'green' : 'red' ?>;">
                            <?= format_resource_amount($cost) ?> <img src="<?= $res_icon ?>" width="16" style="vertical-align: middle;">
                        </span>
                    </div>
                    <div class="stat-row">
                        <span>Время</span>
                        <span class="text-primary"><?= format_time_display($next_stats['time'] ?? 0) ?></span>
                    </div>

                    <div style="border-top: 1px solid #c7b08d; margin: 10px 0;"></div>
                    
                    <?php 
                        $hp_diff = ($next_stats['hp'] ?? 0) - ($stats['hp'] ?? 0);
                        $cap_diff = ($next_stats['capacity'] ?? 0) - ($stats['capacity'] ?? 0);
                    ?>
                    <div class="stat-row">
                         <span>❤️ Здоровье</span>
                         <span class="text-success">+<?= number_format($hp_diff, 0, '', ' ') ?></span>
                    </div>
                    <div class="stat-row">
                         <span>📦 Вместимость</span>
                         <span class="text-success">+<?= format_resource_amount($cap_diff) ?></span>
                    </div>
                    <?php if ($is_resource_generator): 
                         $rate_diff = ($next_stats['rate'] ?? 0) - ($stats['rate'] ?? 0);
                    ?>
                        <div class="stat-row">
                             <span>⚡️ Добыча</span>
                             <span class="text-success">+<?= format_resource_amount($rate_diff) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php 
                        $btn_text = 'Улучшить';
                        if (!$th_ok) $btn_text = 'Нужна Ратуша Ур. ' . $th_req;
                        elseif (!$can_afford) $btn_text = 'Не хватает ресурсов';
                        elseif ($is_upgrading) $btn_text = 'Занято';
                    ?>
                    <?php if ((($building['status'] ?? '') === 'upgrading')): ?>
                        <!-- Прогресс улучшения показываем во вкладке "Характеристики" -->
                        <button class="btn btn-block action-btn btn-disabled" disabled>
                            Улучшается
                        </button>
                    <?php else: ?>
                    <button class="btn btn-block action-btn <?= $can_upgrade ? 'btn-upgrade' : 'btn-disabled' ?>" 
                            onclick="productionStartUpgrade('<?= $modal_id ?>', <?= (int)$building_row_id ?>)">
                        <?= $btn_text ?>
                    </button>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-info info-box" style="margin-top: 15px;">
                    <h3>✅ Максимум</h3>
                    <p>Здание достигло максимального уровня.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}
