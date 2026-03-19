<?php
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
            return season_img('/images/building/storage.png', null, $GLOBALS['mysqli'] ?? null); 
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

/**
 * Вспомогательная функция для получения максимального кол-ва зданий (Дубликат из function.php для автономности view)
 */
if (!function_exists('getMaxCountForTH')) {
    function getMaxCountForTH(string $building_id, int $th_lvl): int {
        static $max_building_counts = [
            'gold_storage' => [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 5, 11 => 5, 12 => 5, 13 => 6, 14 => 6, 15 => 7, 16 => 7],
            'elixir_storage' => [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 5, 11 => 5, 12 => 5, 13 => 6, 14 => 6, 15 => 7, 16 => 7],
            'dark_storage' => [1 => 0, 7 => 1, 9 => 2, 11 => 3, 13 => 4, 15 => 5, 16 => 5],
            'gold_mine' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
            'elixir_collector' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
            'dark_elixir_drill' => [1 => 0, 7 => 1, 8 => 2, 9 => 3, 11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 4, 16 => 4],
        ];

        $max_counts = $max_building_counts[$building_id] ?? [];
        
        $count = 0;
        foreach ($max_counts as $th_req => $max) {
            if ($th_lvl >= $th_req) {
                $count = $max;
            }
        }
        return $count;
    }
}

/**
 * Возвращает минимальный уровень Ратуши, на котором откроется следующий слот для данного здания.
 * Если слотов дальше не будет — ...
 */
if (!function_exists('getNextTownhallForMoreSlots')) {
    function getNextTownhallForMoreSlots(string $building_id, int $current_th_lvl): int {
        static $max_building_counts = null;
        if ($max_building_counts === null) {
            // Должно совпадать с таблицей в getMaxCountForTH
            $max_building_counts = [
                'gold_storage' => [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 5, 11 => 5, 12 => 5, 13 => 6, 14 => 6, 15 => 7, 16 => 7],
                'elixir_storage' => [1 => 1, 2 => 2, 3 => 2, 4 => 3, 5 => 3, 6 => 4, 7 => 4, 8 => 4, 9 => 4, 10 => 5, 11 => 5, 12 => 5, 13 => 6, 14 => 6, 15 => 7, 16 => 7],
                'dark_storage' => [1 => 0, 7 => 1, 9 => 2, 11 => 3, 13 => 4, 15 => 5, 16 => 5],
                'gold_mine' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
                'elixir_collector' => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 7, 9 => 7, 10 => 7, 11 => 7, 12 => 7, 13 => 7, 14 => 8, 15 => 8, 16 => 8],
                'dark_elixir_drill' => [1 => 0, 7 => 1, 8 => 2, 9 => 3, 11 => 3, 12 => 3, 13 => 3, 14 => 3, 15 => 4, 16 => 4],
            ];
        }

        $map = $max_building_counts[$building_id] ?? [];
        if (empty($map)) return 0;

        $current_max = getMaxCountForTH($building_id, $current_th_lvl);
        foreach ($map as $th_req => $max) {
            if ($th_req > $current_th_lvl && $max > $current_max) {
                return (int)$th_req;
            }
        }
        return 0;
    }
}


// -------------------------------------------------------------------------------------
// 2. ГЛАВНОЕ МЕНЮ: ХРАНИЛИЩА (STORAGE MAIN)
// -------------------------------------------------------------------------------------
function renderStorageMainView(array $user): string {
    ob_start();
    
    // Убедимся, что ключи существуют
    $user['dark_elixir'] = $user['dark_elixir'] ?? 0;
    $user['gold'] = $user['gold'] ?? 0;
    $user['elixir'] = $user['elixir'] ?? 0;
    
    $modal_id = 'storage-modal';

    // Ссылки на картинки для меню (можно использовать статические высокие уровни для красоты)
    $main_view_icons = [
        'gold_storage' => '/images/building/Gold_Storage/Gold_Storage19.png',
        'elixir_storage' => '/images/building/Elixir_Storage/Elixir_Storage18.png',
        'dark_storage' => '/images/building/Dark_Elixir/Dark_Elixir_Storage12.png',
    ];
    ?>
    <div class="storage-main-view">
        <div class="modal-header-controls storage-main-header">
             <button class="back-modal modal-button-corner hidden" onclick="goBack('<?= $modal_id ?>', 'main')">
                <img src="/images/icons/left.png" alt="Назад">
             </button>
             
             <h2 class="modal-title-text-inside-panel">ХРАНИЛИЩА</h2>
             
             <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
             </button>
        </div>
        
        <div class="modal-body-custom resource-grid-wrapper">
            <div class="resource-selection main-storage-grid">
                
                <div class="resource-card card-gold" onclick="loadStorageList('<?= $modal_id ?>', 'gold_storage')">
                    <img src="<?= season_img($main_view_icons['gold_storage'], $season, $mysqli) ?>" alt="Золото">
                    <h3 class="resource-title-text">Хранилище золота</h3>
                    <div class="resource-balance-only"><?= format_resource_amount($user['gold']) ?></div>
                </div>
                
                <div class="resource-card card-elixir" onclick="loadStorageList('<?= $modal_id ?>', 'elixir_storage')">
                    <img src="<?= season_img($main_view_icons['elixir_storage'], $season, $mysqli) ?>" alt="Эликсир">
                    <h3 class="resource-title-text">Хранилище эликсира</h3>
                    <div class="resource-balance-only"><?= format_resource_amount($user['elixir']) ?></div>
                </div>
                
                <div class="resource-card card-dark-elixir" onclick="loadStorageList('<?= $modal_id ?>', 'dark_storage')">
                    <img src="<?= season_img($main_view_icons['dark_storage'], $season, $mysqli) ?>" alt="Черный эликсир">
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
// 3. ГЛАВНОЕ МЕНЮ: ПРОИЗВОДСТВО (PRODUCTION MAIN)
// -------------------------------------------------------------------------------------
function renderProductionMainView(array $user): string {
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
        <div class="modal-header-controls production-main-header">
             <button class="back-modal modal-button-corner hidden" onclick="goBack('<?= $modal_id ?>', 'production_main')">
                <img src="/images/icons/left.png" alt="Назад">
             </button>
             
             <h2 class="modal-title-text-inside-panel">ПРОИЗВОДСТВО</h2>
             
             <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
             </button>
        </div>
        
        <div class="modal-body-custom resource-grid-wrapper">
            <div class="resource-selection main-production-grid">
                
                <div class="resource-card card-mine" onclick="loadStorageList('<?= $modal_id ?>', 'gold_mine')">
                    <img src="<?= season_img($main_view_icons['gold_mine'], $season, $mysqli) ?>" alt="Золотые шахты">
                    <h3 class="resource-title-text">Золотая шахта</h3>
                </div>
                
                <div class="resource-card card-collector" onclick="loadStorageList('<?= $modal_id ?>', 'elixir_collector')">
                    <img src="<?= season_img($main_view_icons['elixir_collector'], $season, $mysqli) ?>" alt="Сборщики">
                    <h3 class="resource-title-text">Сборщик эликсира</h3>
                </div>
                
                <div class="resource-card card-drill" onclick="loadStorageList('<?= $modal_id ?>', 'dark_elixir_drill')">
                    <img src="<?= season_img($main_view_icons['dark_elixir_drill'], $season, $mysqli) ?>" alt="Скважины">
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
function renderStorageListView(array $user, string $type, array $built_buildings): string {
    global $game_data;
    global $storage_descriptions;
    
    $th_lvl = $user['townhall_lvl'];
    $max_count = getMaxCountForTH($type, $th_lvl);
    
    // Получаем имя здания из game_data, если нет - ставим дефолт
    $building_type_name = $game_data[$type]['name'] ?? 'Здание';
    $description = $storage_descriptions[$type] ?? 'Описание отсутствует.';
    
    // Определяем, в каком модальном окне мы находимся, чтобы кнопка "Назад" вела куда надо
    $is_production_list = in_array($type, ['gold_mine', 'elixir_collector', 'dark_elixir_drill']);
    $go_back_view = $is_production_list ? 'production_main' : 'main'; 
    $modal_id = $is_production_list ? 'production-modal' : 'storage-modal';
    
    ob_start();
    ?>
    <div class="storage-list-view">
        <div class="modal-header-controls">
             <button class="back-modal modal-button-corner" onclick="goBack('<?= $modal_id ?>', '<?= $go_back_view ?>')">
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
                    <div class="building-list-item stylized-card <?= $item_class ?>" onclick="loadStorageDetail('<?= $modal_id ?>', <?= $b['id'] ?>)">
                        <div class="item-icon-full">
                           <img src="<?= htmlspecialchars(season_img(getBuildingImageResourcePath($b['building_id'], $level), $season, $mysqli)) ?>" alt="<?= htmlspecialchars($building_type_name) ?>">
                        </div>
                        
                        <div class="item-info-extended">
                            <strong class="item-title-text"><?= htmlspecialchars($building_type_name) ?> Ур. <?= $level ?></strong>
                            
                            <?php if ($is_upgrading || $is_constructing): ?>
                                <p class="status-text text-primary">
                                    🔨 <?= $is_constructing ? 'Строится' : 'Улучшается' ?>: 
                                    <?= format_time_display(max(0, $b['finish_time'] - time())) ?>
                                </p>
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
                            <img src="<?= htmlspecialchars(season_img(getBuildingImageResourcePath($type, 1), $season, $mysqli)) ?>" alt="Новая постройка" style="opacity: 0.7;">
                        </div>
                        <div class="item-info-extended">
                            <strong class="item-title-text">Новое <?= htmlspecialchars($building_type_name) ?></strong>
                            <p>Уровень 1</p>
                            <p>Цена: <span style="color: <?= $price_color ?>; font-weight: bold;"><?= format_resource_amount($cost) ?></span> <img src="<?= $res_icon ?>" width="14" style="vertical-align: middle;"></p>
                        </div>
                        <div class="item-action-button">
                             <button class="btn btn-buy-action <?= $can_afford ? '' : 'btn-disabled' ?>" 
                                     onclick="startBuilding('<?= $modal_id ?>', '<?= htmlspecialchars($type) ?>')">
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
                        $next_th = getNextTownhallForMoreSlots($type, $th_lvl);
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
    $building_image_path = season_img(getBuildingImageResourcePath($building_id, $level), $season, $mysqli); 
    $th_lvl = $user['townhall_lvl'];

    // Определяем ресурс для расчета общей емкости
    $resource_type_key = '';
    $res_const_for_type = $is_resource_generator ? ($stats['res_type'] ?? RES_GOLD) : str_replace('_storage', '', $building_id);
    if (is_array($res_const_for_type)) $res_const_for_type = $res_const_for_type[0];
    $res_const_for_type = strtolower($res_const_for_type);
    if (strpos($res_const_for_type, 'res_') === 0) $res_const_for_type = substr($res_const_for_type, 4);

    if ($res_const_for_type === 'dark') $resource_type_key = 'dark_elixir';
    elseif ($res_const_for_type === 'gold') $resource_type_key = 'gold';
    elseif ($res_const_for_type === 'elixir') $resource_type_key = 'elixir';
    
    // Расчет общей емкости
    $total_storage_capacity = 0;
    if (!$is_resource_generator && !empty($resource_type_key) && function_exists('getTotalStorageCapacity')) {
         global $mysqli;
         $total_storage_capacity = getTotalStorageCapacity($user['id'], $resource_type_key, $mysqli, $th_lvl);
    }
    
    // Навигация
    $is_production_building = in_array($building_id, ['gold_mine', 'elixir_collector', 'dark_elixir_drill']);
    $modal_id = $is_production_building ? 'production-modal' : 'storage-modal';
    // Назад из деталей возвращаемся в корневую категорию,
    // а не в list (иначе нужно помнить type, и при потере параметра получаем HTTP 400).
    $go_back_view = $is_production_building ? 'production_main' : 'main';

    
    ob_start();
    ?>
    <div class="storage-detail-view">
        <div class="modal-header-controls">
            <button class="back-modal modal-button-corner" onclick="goBack('<?= $modal_id ?>', '<?= $go_back_view ?>')">
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
            
            <?php if ($is_upgrading): ?>
                <div class="alert alert-info info-box">
                    <h3>🔨 Процесс...</h3>
                    <div class="stat-row">
                        <span><?= ($building['status'] === 'constructing' ? 'Строится' : 'Улучшается') ?>:</span>
                        <span class="text-primary"><?= format_time_display(max(0, $building['finish_time'] - time())) ?></span>
                    </div>
                </div>
            <?php endif; ?>
            
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
                        <span>📦 Емкость</span>
                        <span class="text-primary"><?= format_resource_amount($stats['capacity'] ?? 0) ?></span>
                    </div>
                    <div style="border-top: 1px solid #c7b08d; margin: 5px 0;"></div>
                    <div class="stat-row">
                        <span>💧 Накоплено</span>
                        <span class="text-primary"><?= number_format($building['stored_resource'] ?? 0, 0, '', ' ') ?></span>
                    </div>
                    
                    <?php 
                        $can_collect = ((int)($building['stored_resource'] ?? 0) > 0) && !$is_upgrading;
                        $btn_text = $can_collect ? 'Собрать ресурсы' : 'Пусто';
                    ?>
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
                                <?= $can_collect ? 'onclick="collectResource(\''.$modal_id.'\','.$building_row_id.')"' : '' ?>>
                            <?= $btn_text ?>
                        </button>
                    <?php endif; ?>
                    
                <?php else: // Хранилище ?>
                    <div class="stat-row">
                        <span>📦 Емкость</span>
                        <span class="text-primary"><?= format_resource_amount($stats['capacity'] ?? 0) ?></span>
                    </div>
                    <div style="border-top: 1px solid #c7b08d; margin: 5px 0;"></div>
                    <div class="stat-row">
                        <span>💧 Всего места</span>
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
                         <span>📦 Емкость</span>
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
                        <?php
                            $end_ts = (int)($building['finish_time'] ?? 0);
                            $dur_sec = (int)($next_stats['time'] ?? 0);
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
                    <button class="btn btn-block action-btn <?= $can_upgrade ? 'btn-upgrade' : 'btn-disabled' ?>" 
                            onclick="startUpgrade('<?= $modal_id ?>', <?= (int)$building_row_id ?>)">
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