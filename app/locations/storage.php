<?php
/**
 * app/locations/storage.php
 * Локация: Хранилища (backend + views)
 * Endpoint: ajax.php?page=storage
 */

/**
 * Локальные хелперы для этой локации (уникальные имена, чтобы не конфликтовать).
 * ВАЖНО: объявлены ДО try/switch, чтобы были доступны сразу.
 */


/**
 * STORAGE DEBUG (оставляем включённым на время фикса).
 * Пишет в /storage_debug.log и дублирует в console.error.
 */
if (!function_exists('storage_debug_log')) {
    function storage_debug_log(string $msg): void {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
        @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/storage_debug.log', $line, FILE_APPEND);
        echo "<script>console.error(" . json_encode($msg) . ");</script>";
    }
}

/**
 * Локальные хелперы для локации (ВАЖНО: ДО try/switch).
 */
if (!function_exists('storage_loc_getMaxCountForTH')) {
    function storage_loc_getMaxCountForTH(string $building_id, int $th_lvl): int {
        if (function_exists('getMaxCountForTH')) {
            return (int)getMaxCountForTH($building_id, $th_lvl);
        }
        // Fallback: хотя бы 1 слот, если здание уже доступно.
        return ($th_lvl >= 1) ? 1 : 0;
    }
}

if (!function_exists('storage_loc_getNextTownhallForMoreSlots')) {
    function storage_loc_getNextTownhallForMoreSlots(string $building_id, int $current_th_lvl): int {
        if (function_exists('getNextTownhallForMoreSlots')) {
            return (int)getNextTownhallForMoreSlots($building_id, $current_th_lvl);
        }
        return 0;
    }
}

function storage_loc_renderBalancePayload(mysqli $mysqli, array $user, string $deltaRes = '', int $deltaAmt = 0): string {
    $uid = (int)($user['id'] ?? 0);
    $th = (int)($user['townhall_lvl'] ?? 1);

    $cap_gold = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid, 'gold', $mysqli, $th) : 0;
    $cap_elixir = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid, 'elixir', $mysqli, $th) : 0;
    $cap_dark = function_exists('getTotalStorageCapacity') ? (int)getTotalStorageCapacity($uid, 'dark_elixir', $mysqli, $th) : 0;

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
        . '></div>';
}

// NOTE: storage_loc_getMaxCountForTH / storage_loc_getNextTownhallForMoreSlots
// объявляются ниже в секции views (под защитой function_exists), чтобы:
//  - не дублировать таблицы,
//  - не конфликтовать с system/function.php / app/storage_views.php,
//  - и не ловить redeclare/ArgumentCountError.

try {
    global $mysqli, $game_data;

    $view = $_GET['view'] ?? 'main';
    $type = cleanString($_GET['type'] ?? '', 50);
    $id = toInt($_GET['id'] ?? 0);

    // Актуализируем пользователя (ресурсы/времена)
    $user = getUser($mysqli);
    $userData = $user;

    // Разрешённые типы зданий для локации "Хранилища"
    $allowedTypes = ['gold_storage','elixir_storage','dark_storage'];

    switch ($view) {
        case 'main':
            echo renderStorageMainView($userData);
            break;

        case 'list':
            if (empty($type) || !in_array($type, $allowedTypes, true)) {
                throw new RuntimeException('Не указан или недопустимый тип здания', 400);
            }
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderStorageListView($userData, $type, $buildings);
            break;

        case 'detail':
            if ($id === 0) throw new RuntimeException('Не указан ID здания', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание не найдено', 404);
            echo renderStorageDetailView($userData, $building);
            break;

        case 'collect':
            if ($id === 0) throw new RuntimeException('Не указан ID здания для сбора', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание для сбора не найдено', 404);
            $userData = collectAndStoreResources($building, $userData, $mysqli);
            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderStorageDetailView($userData, $building_updated ?: $building);
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
            echo renderStorageMainView($userData);
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

$view = (string)($_GET['view'] ?? 'main');
$type = (string)($_GET['type'] ?? '');
$rid  = (string)($_GET['r'] ?? '');
$id   = (string)($_GET['id'] ?? '');

storage_debug_log(
    "STORAGE ERROR: " . $e->getMessage()
    . " | file=" . $e->getFile()
    . " | line=" . $e->getLine()
    . " | code=" . $code
    . " | view=" . $view
    . " | type=" . $type
    . " | id=" . $id
    . " | r=" . $rid
);

    http_response_code($code);
    ?>
    <div class="modal-content">
      <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('storage-modal')"><img src="/images/icons/close.png" alt="Закрыть"></button>
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
            return '/images/building/storage.png'; 
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
// 2. ГЛАВНОЕ МЕНЮ: ХРАНИЛИЩА (STORAGE MAIN)
// -------------------------------------------------------------------------------------
function renderStorageMainView(array $user): string {
    // ВНИМАНИЕ: этот файл подключается внутри функции generatePageContent() (ajax.php),
    // поэтому обычная переменная $mysqli тут НЕ видна внутри функций без global.
    // Из-за этого появлялся Warning: Undefined variable $mysqli и HTTP 500.
    global $mysqli;
    ob_start();
    
    // Убедимся, что ключи существуют
    $user['dark_elixir'] = $user['dark_elixir'] ?? 0;
    $user['gold'] = $user['gold'] ?? 0;
    $user['elixir'] = $user['elixir'] ?? 0;
    
    $modal_id = 'storage-modal';
    $is_production_list = false;

    // Ссылки на картинки для меню (можно использовать статические высокие уровни для красоты)
    $main_view_icons = [
        'gold_storage' => '/images/building/Gold_Storage/Gold_Storage19.png',
        'elixir_storage' => '/images/building/Elixir_Storage/Elixir_Storage18.png',
        'dark_storage' => '/images/building/Dark_Elixir/Dark_Elixir_Storage12.png',
    ];
    ?>
    <div class="storage-main-view">
        <?= storage_loc_renderBalancePayload($mysqli, $user); ?>
        <div class="modal-header-controls storage-main-header">
             <h2 class="modal-title-text-inside-panel">ХРАНИЛИЩА</h2>
             
             <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
             </button>
        </div>
        
        <div class="modal-body-custom resource-grid-wrapper">
            <div class="resource-selection main-storage-grid">
                
                <div class="resource-card card-gold" onclick="storageLoadList('<?= $modal_id ?>', 'gold_storage')">
                    <img src="<?= $main_view_icons['gold_storage'] ?>" alt="Золото">
                    <h3 class="resource-title-text">Хранилище золота</h3>
                    <div class="resource-balance-only"><?= format_resource_amount($user['gold']) ?></div>
                </div>
                
                <div class="resource-card card-elixir" onclick="storageLoadList('<?= $modal_id ?>', 'elixir_storage')">
                    <img src="<?= $main_view_icons['elixir_storage'] ?>" alt="Эликсир">
                    <h3 class="resource-title-text">Хранилище эликсира</h3>
                    <div class="resource-balance-only"><?= format_resource_amount($user['elixir']) ?></div>
                </div>
                
                <div class="resource-card card-dark-elixir" onclick="storageLoadList('<?= $modal_id ?>', 'dark_storage')">
                    <img src="<?= $main_view_icons['dark_storage'] ?>" alt="Черный эликсир">
                    <h3 class="resource-title-text">Хранилище ЧЭ</h3>
                    <div class="resource-balance-only"><?= format_resource_amount($user['dark_elixir']) ?></div>
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
function renderStorageListView(array $user, string $type, array $built_buildings): string {
    global $game_data;
    global $storage_descriptions;
    global $mysqli;
    
    $th_lvl = $user['townhall_lvl'];

    // Красивое имя здания (иногда в game_data встречаются служебные/склеенные названия)
    $pretty_names = [
        'gold_storage' => 'Хранилище золота',
        'elixir_storage' => 'Хранилище эликсира',
        'dark_storage' => 'Хранилище тёмного эликсира',
    ];
    // Важно: $building_id тут НЕ определён (это была ошибка).
    // Для списка используем текущий тип.
    $display_name = $pretty_names[$type] ?? ($game_data[$type]['name'] ?? 'Здание');

    $max_count = storage_loc_getMaxCountForTH($type, (int)$th_lvl);
    
    // Получаем имя здания из game_data, если нет - ставим дефолт
    $building_type_name = $game_data[$type]['name'] ?? 'Здание';
    $description = $storage_descriptions[$type] ?? 'В этом разделе вы управляете зданиями, которые отвечают за запас ресурсов. Улучшайте хранилища, чтобы увеличить вместимость и защитить добытое золото и эликсир.';
    
    // Определяем, в каком модальном окне мы находимся, чтобы кнопка "Назад" вела куда надо
    $go_back_view = 'main';
    $modal_id = 'storage-modal';
    $is_production_list = false;

    ob_start();
    ?>
    <div class="storage-list-view">
        <?= storage_loc_renderBalancePayload($mysqli, $user); ?>
        <div class="modal-header-controls">
             <button class="back-modal modal-button-corner" onclick="storageGoBack('<?= $modal_id ?>', '<?= $go_back_view ?>')">
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
                    <div class="building-list-item stylized-card <?= $item_class ?>" onclick="storageLoadDetail('<?= $modal_id ?>', <?= $b['id'] ?>)">
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
                                     onclick="storageStartBuilding('<?= $modal_id ?>', '<?= htmlspecialchars($type) ?>')">
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
                        $next_th = storage_loc_getNextTownhallForMoreSlots($type, (int)$th_lvl);
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

    // Красивое имя здания (иногда в game_data встречаются служебные/склеенные названия)
    $pretty_names = [
        'gold_storage' => 'Хранилище золота',
        'elixir_storage' => 'Хранилище эликсира',
        'dark_storage' => 'Хранилище тёмного эликсира',
    ];
    $display_name = $pretty_names[$building_id] ?? ($info['name'] ?? 'Здание');


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

// Разбивка: вместимость хранилищ + (возможно) базовый запас Ратуши.
// В текущей экономике проекта Ратуша даёт стартовую вместимость (например, 1000 на ТХ1),
// поэтому при 1 хранилище на 1500 итог получается 2500.
$th_base_cap = 0;
$storage_only_capacity = 0;

if (!empty($resource_type_key) && function_exists('getTotalStorageCapacity')) {
    global $mysqli;
    $total_storage_capacity = (int)getTotalStorageCapacity($user['id'], $resource_type_key, $mysqli, $th_lvl);
    $available_space = $total_storage_capacity - (int)($user[$resource_type_key] ?? 0);

    $cap_key = '';
    if ($resource_type_key === 'dark_elixir') $cap_key = 'cap_dark_elixir';
    elseif ($resource_type_key === 'gold') $cap_key = 'cap_gold';
    elseif ($resource_type_key === 'elixir') $cap_key = 'cap_elixir';

    if ($cap_key && isset($game_data['townhall']['levels'][$th_lvl][$cap_key])) {
        $th_base_cap = (int)$game_data['townhall']['levels'][$th_lvl][$cap_key];
    }
    $storage_only_capacity = max(0, $total_storage_capacity - $th_base_cap);
}

// Навигация
    $modal_id = 'storage-modal';
    $go_back_view = 'main';
ob_start();
    ?>
    <div class="storage-detail-view">
        <?= storage_loc_renderBalancePayload($mysqli, $user, $user['_delta_res'] ?? ($user['_collect_res'] ?? ''), (int)($user['_delta_amt'] ?? ($user['_collect_amt'] ?? 0))); ?>
        <div class="modal-header-controls">
            <button class="back-modal modal-button-corner" onclick="storageGoBack('<?= $modal_id ?>', '<?= $go_back_view ?>')">
                <img src="/images/icons/left.png" alt="Назад">
            </button>
            
            <h2 class="modal-title-text-inside-panel"><?= htmlspecialchars($display_name) ?> Ур. <?= $level ?></h2>
            
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
                                <?= $can_collect ? 'onclick="storageCollectResource(\''.$modal_id.'\','.$building_row_id.')"' : '' ?>>
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
                        <span>📦 Вместимость хранилищ (все)</span>
                        <span class="text-primary"><?= format_resource_amount($storage_only_capacity) ?></span>
                    </div>
                    <div class="stat-row">
                        <span>🏛️ Базовый запас Ратуши</span>
                        <span class="text-primary"><?= format_resource_amount($th_base_cap) ?></span>
                    </div>
                    <div class="stat-row">
                        <span>🧮 Общая вместимость</span>
                        <span class="text-primary"><?= format_resource_amount($total_storage_capacity) ?></span>
                    </div>
                    <div class="stat-row">
                        <span>💧 Баланс</span>
                        <span class="text-primary"><?= number_format($user[$resource_type_key] ?? 0, 0, '', ' ') ?></span>
                    </div>

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
                    <?php endif; ?>
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
                        <!-- Прогресс улучшения показываем в блоке "Характеристики" (как в CoC) -->
                        <button class="btn btn-block action-btn btn-disabled" disabled>
                            Улучшается
                        </button>
                    <?php else: ?>
                    <button class="btn btn-block action-btn <?= $can_upgrade ? 'btn-upgrade' : 'btn-disabled' ?>" 
                            onclick="storageStartUpgrade('<?= $modal_id ?>', <?= (int)$building_row_id ?>)">
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
