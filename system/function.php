<?php
// Включаем отображение ошибок (только для разработки)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$start = microtime(true);

session_start(); // нужно до доступа к $_SESSION

header('Content-Type: text/html; charset=utf-8');
header('X-XSS-Protection: 1; mode=block'); 
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN'); 
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer-when-downgrade');


// Логирование для отладки (только в dev-среде)

// Данные игры (характеристики зданий, уровни, емкости и т.д.)
require_once __DIR__ . '/game_data.php';

if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
    error_log("===== AJAX Request =====");
    error_log("Time: ".date('Y-m-d H:i:s'));
    error_log("GET: ".print_r($_GET, true));
    error_log("SESSION: ".print_r($_SESSION, true));
}

// Настройки безопасности
define('ENVIRONMENT', 'production'); // 'development' или 'production'
define('DB_HOST', 'localhost');
define('DB_USER', 'gr5478gr_clash');
define('DB_PASS', 'jeJeQLj8QkkF1');
define('DB_NAME', 'gr5478gr_clash');
define('MAX_LOGIN_ATTEMPTS', 5);
define('RESOURCE_UPDATE_INTERVAL', 5); // секунд


// Версия игры для принудительного сброса кеша (используется в query-параметре ?v=...)
if (!defined('GAME_VER')) define('GAME_VER', '1.0.13');
// ------------------ GAME ACTION EXCEPTIONS ------------------
/**
 * Исключение для "ожидаемых" игровых ошибок (нехватка ресурсов, лимит зданий, нет строителей и т.п.).
 * Данные из $data можно использовать на фронте для красивых уведомлений.
 */
class GameActionException extends RuntimeException {
    /** @var array */
    public $data = [];

    public function __construct(string $message, int $code = 400, array $data = [], Throwable $previous = null) {
        $this->data = $data;
        parent::__construct($message, $code, $previous);
    }

    public function getData(): array {
        return is_array($this->data) ? $this->data : [];
    }
}

/** Возвращает данные из GameActionException (если есть) */
function getExceptionData(Throwable $e): array {
    return ($e instanceof GameActionException) ? $e->getData() : [];
}

/**
 * Бросает "ожидаемую" ошибку недостатка ресурсов с данными для фронта.
 */
function throwNotEnoughResources(string $resKey, int $need, int $have, string $action = ''): void {
    $missing = max(0, $need - $have);
    $msg = 'Не хватает ресурсов.';
    if ($action !== '') $msg = 'Не хватает ресурсов для действия: ' . $action . '.';
    throw new GameActionException($msg, 400, [
        'type' => 'not_enough_resources',
        'res' => $resKey,
        'need' => $need,
        'have' => $have,
        'missing' => $missing,
        'action' => $action,
    ]);
}

// Инициализация ошибок

ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// Подключение к БД с обработкой ошибок
try {
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        throw new RuntimeException('DB connection failed: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset("utf8mb4");
} catch (Exception $e) {
    error_log('Database error: ' . $e->getMessage());
    http_response_code(500);
    die('System temporarily unavailable');
}


// ------------------ СЕЗОНЫ / ДИЗАЙН ------------------

/**
 * Разрешённые режимы сезона (глобально на весь сервер).
 */
function season_allowed_modes(): array {
    return ['auto','winter','spring','summer','autumn'];
}

/**
 * Корень public_html (для проверки существования файлов по URL-пути).
 */
function project_doc_root(): string {
    static $root = null;
    if ($root !== null) return $root;

    $root = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($root === '' || !is_dir($root)) {
        // function.php лежит в /public_html/system/
        $root = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
    }
    return $root;
}

/**
 * Автосоздание таблицы настроек (мягкая миграция при первом заходе).
 */
function ensureGameSettingsTable(mysqli $mysqli): void {
    static $done = false;
    if ($done) return;
    $done = true;

    // Если прав на CREATE нет — просто молча оставим auto.
    $sql = "CREATE TABLE IF NOT EXISTS `game_settings` (
        `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
        `season_mode` ENUM('auto','winter','spring','summer','autumn') NOT NULL DEFAULT 'auto',
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    @$mysqli->query($sql);
    @$mysqli->query("INSERT INTO `game_settings` (`id`, `season_mode`) VALUES (1, 'auto')
        ON DUPLICATE KEY UPDATE `season_mode` = `season_mode`;");
}

/**
 * Глобальный режим сезона: auto|winter|spring|summer|autumn
 */
function getGlobalSeasonMode(?mysqli $mysqli = null): string {
    if (isset($GLOBALS['__season_mode_cache']) && is_string($GLOBALS['__season_mode_cache'])) {
        return $GLOBALS['__season_mode_cache'];
    }

    $mode = 'auto';
    if ($mysqli instanceof mysqli) {
        try {
            ensureGameSettingsTable($mysqli);
            $res = @$mysqli->query("SELECT season_mode FROM game_settings WHERE id=1 LIMIT 1");
            if ($res && ($row = $res->fetch_assoc())) {
                $m = strtolower(trim((string)($row['season_mode'] ?? '')));
                if (in_array($m, season_allowed_modes(), true)) {
                    $mode = $m;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    if (!in_array($mode, season_allowed_modes(), true)) $mode = 'auto';
    $GLOBALS['__season_mode_cache'] = $mode;
    return $mode;
}

/**
 * Авто-определение сезона (Украина/Северное полушарие):
 * - winter: Dec-Feb
 * - spring: Mar-May
 * - summer: Jun-Aug
 * - autumn: Sep-Nov
 */
function getDefaultSeason(?int $ts = null): string {
    $ts = $ts ?? time();
    $m = (int)date('n', $ts);
    if ($m === 12 || $m === 1 || $m === 2) return 'winter';
    if ($m === 3 || $m === 4 || $m === 5) return 'spring';
    if ($m === 6 || $m === 7 || $m === 8) return 'summer';
    return 'autumn';
}

/**
 * Итоговый сезон: учитывает глобальный режим (auto/forced).
 */
function getActiveSeason(?mysqli $mysqli = null): string {
    $mode = ($mysqli instanceof mysqli) ? getGlobalSeasonMode($mysqli) : 'auto';
    return ($mode === 'auto') ? getDefaultSeason() : $mode;
}

/**
 * Установить глобальный режим сезона (только для админа, через отдельный endpoint).
 */
function setGlobalSeasonMode(mysqli $mysqli, string $mode): bool {
    $mode = strtolower(trim($mode));
    if (!in_array($mode, season_allowed_modes(), true)) return false;

    ensureGameSettingsTable($mysqli);

    $stmt = $mysqli->prepare("INSERT INTO game_settings (id, season_mode) VALUES (1, ?)
        ON DUPLICATE KEY UPDATE season_mode = VALUES(season_mode)");
    if (!$stmt) return false;

    $stmt->bind_param("s", $mode);
    $ok = (bool)$stmt->execute();
    $stmt->close();

    // сбрасываем кэш
    unset($GLOBALS['__season_mode_cache']);
    return $ok;
}

/**
 * Подбор сезонного ассета по URL-пути.
 * Правила:
 * - если сезонного варианта нет — возвращает исходный путь (лето по умолчанию)
 * - поддерживает спец-имена в /images/diz (фон, левый верх, логотип, доска)
 * - общий вариант: добавляет _{season} перед расширением
 */
function season_img(string $urlPath, ?string $season = null, ?mysqli $mysqli = null): string {
    $season = $season ?: getActiveSeason($mysqli);
    $season = strtolower($season);

    if ($urlPath === '' || $urlPath[0] !== '/') return $urlPath;
    if (!in_array($season, season_allowed_modes(), true)) $season = 'summer';

    // Лето — базовый вариант
    if ($season === 'summer') return $urlPath;

    // Спец-маппинг для /images/diz
    $special = [
        '/images/diz/fon.jpg' => [
            'winter' => '/images/diz/fon_winter.jpg',
            'spring' => '/images/diz/fonspring.jpg', // в проекте файл так называется
            'autumn' => '/images/diz/fon_autumn.jpg',
        ],
        '/images/diz/left-top.png' => [
            'winter' => '/images/diz/left-top_winter.png',
            'spring' => '/images/diz/left-top_spring.png',
            'autumn' => '/images/diz/left-top_autumn.png',
        ],
        '/images/diz/board-top.png' => [
            'winter' => '/images/diz/board-top_winter.png',
        ],
        '/images/diz/logo.png' => [
            'winter' => '/images/diz/logo_winter.png',
        ],
    ];

    if (isset($special[$urlPath][$season])) {
        $cand = $special[$urlPath][$season];
        $fs = project_doc_root() . $cand;
        if (is_file($fs)) return $cand;
        // если вдруг файла нет — падаем дальше на общую логику
    }

    // Общая логика: вставляем _{season} перед расширением
    $pi = pathinfo($urlPath);
    $dir = $pi['dirname'] ?? '';
    $name = $pi['filename'] ?? '';
    $ext = $pi['extension'] ?? '';
    if ($dir === '' || $name === '' || $ext === '') return $urlPath;

    $cand = $dir . '/' . $name . '_' . $season . '.' . $ext;
    $fs = project_doc_root() . $cand;
    if (is_file($fs)) return $cand;

    return $urlPath; // по умолчанию (лето)
}


// ------------------ ФУНКЦИИ БЕЗОПАСНОСТИ ------------------

/**
 * Очистка и обрезка строки
 * @param string $str Входная строка
 * @param int $max_length Максимальная длина (по умолчанию 255)
 * @return string Очищенная строка
 */
function cleanString($str, $max_length = 255) {
    if (!is_string($str)) {
        return '';
    }
    
    $str = trim($str);
    $str = htmlspecialchars($str, ENT_QUOTES | ENT_HTML5, 'UTF-8', true);
    return mb_substr($str, 0, $max_length, 'UTF-8');
}

/**
 * Безопасное преобразование в целое число с проверкой диапазона
 * @param mixed $val Входное значение
 * @param int $min Минимальное значение
 * @param int $max Максимальное значение
 * @return int Проверенное целое число
 */
function toInt($val, $min = 0, $max = PHP_INT_MAX) {
    $options = [
        'options' => [
            'min_range' => $min,
            'max_range' => $max
        ],
        'flags' => FILTER_NULL_ON_FAILURE
    ];
    
    $result = filter_var($val, FILTER_VALIDATE_INT, $options);
    return $result !== null ? $result : 0;
}

/**
 * Проверка аутентификации пользователя
 * @return bool True если пользователь аутентифицирован
 */
function isLoggedIn() {
    return !empty($_SESSION['user_id']) && 
           !empty($_SESSION['user_ip']) && 
           !empty($_SESSION['user_agent']) &&
           $_SESSION['user_ip'] === $_SERVER['REMOTE_ADDR'] &&
           $_SESSION['user_agent'] === ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

/**
 * Генерация CSRF токена
 * @return string Токен
 * @throws RuntimeException Если невозможно сгенерировать токен
 */
function generateCsrfToken() {
    // Совместимость: если токен есть, но время не задано — ставим сейчас.
    if (!empty($_SESSION['csrf_token']) && empty($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token_time'] = time();
        return $_SESSION['csrf_token'];
    }

    if (empty($_SESSION['csrf_token'])) {
        try {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
        } catch (Exception $e) {
            throw new RuntimeException('Ошибка генерации CSRF токена');
        }
    }
    return $_SESSION['csrf_token'];
}

/**
 * Валидация CSRF токена
 * @param string $token Токен для проверки
 * @param int $timeout Время жизни токена в секундах (по умолчанию 3600)
 * @return bool Результат проверки
 */
function validateCsrfToken($token, $timeout = 3600) {
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }

    // Если время не было сохранено (старые сессии) — не ломаем игру.
    if (empty($_SESSION['csrf_token_time'])) {
        $_SESSION['csrf_token_time'] = time();
    }
    
    // Проверка времени жизни токена
    if (time() - $_SESSION['csrf_token_time'] > $timeout) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Генерация HTML-поля с CSRF токеном
 * @return string HTML-код input элемента
 */
function csrfInput() {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
}

/**
 * Проверка CSRF токена в POST запросе
 * @throws RuntimeException Если токен недействителен
 */
function verifyCsrfPost() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($token)) {
            throw new RuntimeException('Недействительный CSRF токен');
        }
    }
}

/**
 * Проверка CSRF токена для AJAX запросов (Улучшенная логика)
 * @throws RuntimeException Если токен недействителен или отсутствует
 */
function verifyCsrfAjax() {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        
        $token = '';
        
        // 1. Standard PHP key
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        
        // 2. Common Apache/Nginx keys (may be capitalized)
        if (empty($token)) {
             $token = $_SERVER['X_CSRF_TOKEN'] ?? ''; 
        }

        // 3. Check via getallheaders() (most reliable non-standard method)
        if (empty($token) && function_exists('getallheaders')) {
            $headers = getallheaders();
            // Check for case variations
            $token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? $headers['X-csrf-Token'] ?? '';
        }
        
        // 4. Fallback for environments that use apache_request_headers (rare, but possible)
        if (empty($token) && function_exists('apache_request_headers')) {
             $headers = apache_request_headers();
             $token = $headers['X-CSRF-Token'] ?? $headers['X-Csrf-Token'] ?? '';
        }


        if (empty($token)) {
            // Log this severe error
            error_log("CRITICAL CSRF ERROR: Token missing in AJAX request headers. SERVER keys checked.");
            throw new RuntimeException('CSRF токен отсутствует в запросе (AJAX)');
        }
        
        if (!validateCsrfToken($token)) {
            error_log("CSRF AJAX validation failed. Client token (short): " . substr($token, 0, 8) . 
                      ", Session token (short): " . substr(($_SESSION['csrf_token'] ?? 'NONE'), 0, 8));
            
            throw new RuntimeException('Недействительный CSRF токен (AJAX)');
        }
    }
}


// ------------------ ФУНКЦИИ ПОЛЬЗОВАТЕЛЯ ------------------
function getUser($mysqli) {
    // Проверка авторизации
    if (!isLoggedIn()) {
        error_log('Unauthorized access attempt. IP: '.$_SERVER['REMOTE_ADDR']);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            throw new RuntimeException('Требуется авторизация', 401);
        }

        header('Location: login.php');
        exit;
    }

    static $cached_user = null;
    $user_id = (int)$_SESSION['user_id'];

    // Проверка кэша (только если есть данные и ID совпадает)
    if ($cached_user !== null && 
        isset($cached_user['id']) && 
        $cached_user['id'] === $user_id &&
        (time() - ($cached_user['last_cache_update'] ?? 0)) < RESOURCE_UPDATE_INTERVAL
    ) {
        return $cached_user;
    }
    try {
        // --- ОБНОВЛЕННЫЙ ЗАПРОС ---
        $sql = "
            SELECT 
                u.id, u.login, u.gold, u.elixir, u.dark_elixir, u.gems, u.last_update,
                (SELECT level FROM player_buildings WHERE user_id = u.id AND building_id = 'townhall' LIMIT 1) as townhall_lvl
            FROM users u
            WHERE u.id = ?
        ";
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            throw new RuntimeException('Prepare failed: '.$mysqli->error);
        }

        if (!$stmt->bind_param("i", $user_id)) {
            throw new RuntimeException('Bind failed: '.$stmt->error);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Execute failed: '.$stmt->error);
        }

        $result = $stmt->get_result();
        if ($result === false) {
            throw new RuntimeException('Get result failed: '.$stmt->error);
        }

        $user = $result->fetch_assoc();
        $stmt->close();

        // Проверка наличия пользователя
        if (empty($user)) {
            error_log("User not found in DB. ID: $user_id, SESSION: ".json_encode($_SESSION));
            logout(); 
        }

        // Проверка обязательных полей
        $required = ['id', 'login', 'gold', 'elixir', 'dark_elixir', 'gems', 'last_update'];
        foreach ($required as $field) {
            if (!array_key_exists($field, $user)) {
                $user[$field] = 0; 
            }
        }
        
        // Инициализация townhall_lvl, если она не найдена
        if (!isset($user['townhall_lvl']) || $user['townhall_lvl'] === null) {
            $user['townhall_lvl'] = 1;
        }

        // Обновление ресурсов в зданиях
        $user = updateResources($user, $mysqli);
        $user['last_cache_update'] = time();
        $cached_user = $user;

        return $user;

    } catch (Exception $e) {
        error_log("Error in getUser(): ".$e->getMessage()."\nStack trace: ".$e->getTraceAsString());
        
        if ($cached_user !== null && isset($cached_user['id']) && $cached_user['id'] === $user_id) {
            return $cached_user;
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            throw $e;
        }
        
        header('Location: error.php?code=user_data_error');
        exit;
    }
}

function updateResources($user, $mysqli) {
    global $game_data;

    $now = time();
    
    // Определяем типы ресурсных зданий
    $resource_building_types = ['gold_mine', 'elixir_collector', 'dark_elixir_drill'];

    // 1. Получаем все ресурсные здания игрока, которые активны
    $in_clause = implode(',', array_fill(0, count($resource_building_types), '?'));
    $sql = "SELECT id, building_id, level, stored_resource, last_collect
            FROM player_buildings 
            WHERE user_id = ? AND building_id IN ($in_clause) AND status = 'active'";
    
    $stmt = $mysqli->prepare($sql);
    
    if (!$stmt) {
        error_log("Failed to prepare resource query: " . $mysqli->error);
        return $user; 
    }
    
    // Подготовка параметров для динамического запроса (user_id + building_ids)
    $bind_params = array_merge([$user['id']], $resource_building_types);
    $types = 'i' . str_repeat('s', count($resource_building_types)); 

    // Создаем массив ссылок для bind_param
    $refs = [$types];
    foreach ($bind_params as $key => $value) {
        $refs[] = &$bind_params[$key];
    }
    
    // bind_param требует ссылки, поэтому используем call_user_func_array
    call_user_func_array([$stmt, 'bind_param'], $refs);

    if (!$stmt->execute()) {
        error_log("Failed to execute resource query: " . $stmt->error);
        $stmt->close();
        return $user;
    }

    $result = $stmt->get_result();
    $buildings_to_update = [];
    $update_user_last_update = $user['last_update'];

    while ($row = $result->fetch_assoc()) {
        $building_id = $row['building_id'];
        $level = (int)$row['level'];
        $status = $row['status'] ?? 'active';
        $finish_time = (int)($row['finish_time'] ?? 0);

        // Если здание ещё строится и таймер не закончился — емкость не учитываем
        if ($status === 'constructing' && $finish_time > 0 && $finish_time > $now) {
            continue;
        }
        $stored_resource = (int)$row['stored_resource'];
        // last_collect — это последняя точка расчёта накопления для производственных зданий.
        // finish_time используется для стройки/улучшения и не должен трогаться.
        $last_update_time = (int)($row['last_collect'] ?? 0);
        if ($last_update_time <= 0) {
            // Для старых записей (0) берем last_update пользователя, чтобы накопление работало сразу.
            $last_update_time = (int)($user['last_update'] ?? 0);
            if ($last_update_time <= 0) {
                $last_update_time = $now;
            }
        }

        // Получаем характеристики (rate, capacity) из $game_data.
        $info = $game_data[$building_id] ?? null;

        if (!isset($info['levels'][$level])) {
            continue; 
        }
        $stats = $info['levels'][$level];
        $rate_per_hour = $stats['rate'] ?? 0; 
        $capacity = $stats['capacity'] ?? 0;
        
        if ($rate_per_hour <= 0 || $capacity <= 0) {
            continue;
        }

        $rate_per_second = $rate_per_hour / 3600;

        $time_elapsed = $now - $last_update_time;
        
        $newly_produced = floor($time_elapsed * $rate_per_second);
        $new_stored_resource = min($stored_resource + $newly_produced, $capacity);
        
        if ($new_stored_resource > $stored_resource) {
            $buildings_to_update[] = [
                'id' => $row['id'],
                'new_stored_resource' => $new_stored_resource,
                'new_last_collect' => $now 
            ];
        }
    }
    
    $stmt->close();

    // 2. Обновление БД для всех зданий
    if (!empty($buildings_to_update)) {
        $mysqli->begin_transaction();
        $success = true;

        $stmt = $mysqli->prepare("UPDATE player_buildings SET stored_resource = ?, last_collect = ? WHERE id = ?");
        
        if ($stmt) {
            foreach ($buildings_to_update as $update_data) {
                $stmt->bind_param("iii", $update_data['new_stored_resource'], $update_data['new_last_collect'], $update_data['id']);
                if (!$stmt->execute()) {
                    $success = false;
                    error_log("Failed to update resource building ID " . $update_data['id'] . ": " . $stmt->error);
                    break;
                }
            }
            $stmt->close();
        } else {
             $success = false;
        }

        if ($success) {
            $mysqli->commit();
        } else {
            $mysqli->rollback();
        }
    }

    // 3. Обновляем общее время последнего обновления пользователя.
    $user['last_update'] = $now;
    $stmt = $mysqli->prepare("UPDATE users SET last_update = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("ii", $user['last_update'], $user['id']);
        $stmt->execute();
        $stmt->close();
    }

    return $user;
}

function getTotalStorageCapacity(int $user_id, string $resource_type, mysqli $mysqli, int $townhall_level): int {
    global $game_data;

    // Идентификатор здания-хранилища в player_buildings
    $building_id = $resource_type . '_storage';
    // Для темного эликсира в проекте используется dark_storage
    if ($resource_type === 'dark_elixir') {
        $building_id = 'dark_storage';
    } elseif ($resource_type === 'gold' || $resource_type === 'elixir') {
        $building_id = $resource_type . '_storage';
    } else {
        return 0;
    }

    $total_capacity = 0;

    // Берём активные + улучшающиеся (во время апгрейда емкость доступна),
    // а строящиеся учитываем только если таймер уже закончился (иначе емкости ещё нет).
    $stmt = $mysqli->prepare("SELECT level, status, finish_time FROM player_buildings WHERE user_id = ? AND building_id = ? AND status IN ('active','upgrading','constructing')");
    if (!$stmt) return 0;
    $stmt->bind_param("is", $user_id, $building_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $now = time();

    while ($row = $result->fetch_assoc()) {
        $status = (string)$row['status'];
        $finish_time = (int)($row['finish_time'] ?? 0);

        if ($status === 'constructing' && $finish_time > 0 && $finish_time > $now) {
            // ещё строится — емкость недоступна
            continue;
        }

        $level = (int)$row['level'];
        $info = $game_data[$building_id] ?? null;
        $stats = $info['levels'][$level] ?? [];
        $capacity = (int)($stats['capacity'] ?? 0);

        $total_capacity += $capacity;
    }
    $stmt->close();

    // Базовая емкость из Ратуши (зависит от уровня ратуши)
    $townhall_info = $game_data['townhall']['levels'][$townhall_level] ?? [];
    $townhall_capacity_key = 'cap_' . $resource_type;
    $total_capacity += (int)($townhall_info[$townhall_capacity_key] ?? 0);

    return $total_capacity;
}



function logout() {
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
}

// ------------------ АУТЕНТИФИКАЦИЯ ------------------

function registerUser($mysqli, $login, $password) {
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('Password too short');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $stmt = $mysqli->prepare("INSERT INTO users (login, password) VALUES (?, ?)");
    $stmt->bind_param("ss", $login, $hash);
    return $stmt->execute();
}

function verifyLogin($mysqli, $login, $password) {
    static $attempts = [];
    $ip = $_SERVER['REMOTE_ADDR'];
    $key = md5($login.$ip);

    if (($attempts[$key] ?? 0) >= MAX_LOGIN_ATTEMPTS) {
        sleep(($attempts[$key] - MAX_LOGIN_ATTEMPTS + 1) * 2);
        throw new RuntimeException('Too many attempts');
    }

    $stmt = $mysqli->prepare("SELECT id, password FROM users WHERE login = ?");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user || !password_verify($password, $user['password'])) {
        $attempts[$key] = ($attempts[$key] ?? 0) + 1;
        throw new RuntimeException('Invalid credentials');
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_ip'] = $ip;
    session_regenerate_id(true);
    
    return true;
}

// ------------------ УТИЛИТЫ ------------------

function logError($message, $context = []) {
    $log = date('[Y-m-d H:i:s]') . ' ' . strip_tags($message);
    if ($context) {
        $log .= ' ' . json_encode($context);
    }
    file_put_contents(__DIR__.'/../logs/security.log', $log.PHP_EOL, FILE_APPEND);
}

function isPasswordStrong($password) {
    return preg_match('/^(?=.*\d)(?=.*[A-Z])(?=.*[a-z])(?=.*[^\w\d\s:])([^\s]){8,}$/', $password);
}

function generatePassword($length = 12) {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*()_-=+';
    $password = '';
    $max = strlen($chars) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, $max)];
    }
    
    return $password;
}








/**
 * Конвертирует RES_* / строковые константы из game_data.php в ключ ресурса в таблице users.
 * Поддерживает как старый формат ('RES_DARK'), так и текущий (RES_DARK = 'dark').
 */

/**
 * Разрешённые ресурсные колонки users.
 */
function allowedUserResourceKeys(): array {
    return ['gold','elixir','dark_elixir','gems'];
}

/**
 * Безопасная нормализация имени ресурсной колонки.
 */
function normalizeUserResourceKey(string $resourceKey): string {
    $resourceKey = trim(strtolower($resourceKey));
    if (!in_array($resourceKey, allowedUserResourceKeys(), true)) {
        throw new RuntimeException('Недопустимый тип ресурса', 400);
    }
    return $resourceKey;
}

/**
 * Требует валидный CSRF-токен для игровых действий, даже если действие ушло GET/XHR.
 */
function requireActionCsrfFromRequest(): void {
    $token = '';
    if (!empty($_POST['csrf_token'])) {
        $token = (string)$_POST['csrf_token'];
    } elseif (!empty($_GET['csrf_token'])) {
        $token = (string)$_GET['csrf_token'];
    } elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    if (!check_csrf($token)) {
        throw new RuntimeException('Недействительный CSRF токен', 403);
    }
}

/**
 * Атомарно списывает ресурс с основного баланса.
 */
function debitUserResource(mysqli $mysqli, int $userId, string $resourceKey, int $amount): int {
    $resourceKey = normalizeUserResourceKey($resourceKey);
    $amount = max(0, (int)$amount);
    if ($amount === 0) {
        $stmt = $mysqli->prepare("SELECT `{$resourceKey}` FROM users WHERE id=? LIMIT 1");
        if (!$stmt) throw new RuntimeException('DB prepare failed', 500);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row[$resourceKey] ?? 0);
    }

    $stmt = $mysqli->prepare("UPDATE users SET `{$resourceKey}` = `{$resourceKey}` - ? WHERE id = ? AND `{$resourceKey}` >= ? LIMIT 1");
    if (!$stmt) throw new RuntimeException('DB prepare failed', 500);
    $stmt->bind_param('iii', $amount, $userId, $amount);
    $stmt->execute();
    $affected = (int)$stmt->affected_rows;
    $stmt->close();
    if ($affected < 1) {
        $stmt2 = $mysqli->prepare("SELECT `{$resourceKey}` FROM users WHERE id=? LIMIT 1");
        if (!$stmt2) throw new RuntimeException('DB prepare failed', 500);
        $stmt2->bind_param('i', $userId);
        $stmt2->execute();
        $row = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $have = (int)($row[$resourceKey] ?? 0);
        throwNotEnoughResources($resourceKey, $amount, $have, 'списание ресурса');
    }

    $stmt3 = $mysqli->prepare("SELECT `{$resourceKey}` FROM users WHERE id=? LIMIT 1");
    if (!$stmt3) throw new RuntimeException('DB prepare failed', 500);
    $stmt3->bind_param('i', $userId);
    $stmt3->execute();
    $row = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();
    return (int)($row[$resourceKey] ?? 0);
}

/**
 * Атомарно зачисляет ресурс на основной баланс.
 */
function creditUserResource(mysqli $mysqli, int $userId, string $resourceKey, int $amount): int {
    $resourceKey = normalizeUserResourceKey($resourceKey);
    $amount = max(0, (int)$amount);
    if ($amount > 0) {
        $stmt = $mysqli->prepare("UPDATE users SET `{$resourceKey}` = `{$resourceKey}` + ? WHERE id = ? LIMIT 1");
        if (!$stmt) throw new RuntimeException('DB prepare failed', 500);
        $stmt->bind_param('ii', $amount, $userId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt2 = $mysqli->prepare("SELECT `{$resourceKey}` FROM users WHERE id=? LIMIT 1");
    if (!$stmt2) throw new RuntimeException('DB prepare failed', 500);
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    $stmt2->close();
    return (int)($row[$resourceKey] ?? 0);
}
function resourceConstToUserKey($res_const): string {
    if (is_array($res_const)) {
        $res_const = $res_const[0] ?? '';
    }
    $res = strtolower((string)$res_const);
    $res = str_replace('res_', '', $res);

    // Dark elixir
    if ($res === 'dark' || $res === 'dark_elixir' || $res === 'darkelixir') {
        return 'dark_elixir';
    }
    // Поддержка если где-то осталось 'RES_DARK'
    if ($res === strtolower('RES_DARK')) {
        return 'dark_elixir';
    }

    if ($res === 'gold' || $res === 'elixir') {
        return $res;
    }
    if ($res === 'gems' || $res === 'gem') {
        return 'gems';
    }
    return $res;
}

/**
 * Обрабатывает сбор ресурсов из производственного здания.
 * @param array $building Данные здания (player_buildings row)
 * @param array $user Текущие данные пользователя
 * @param mysqli $mysqli Соединение с БД
 * @return array Обновленные данные пользователя
 */
function collectAndStoreResources(array $building, array $user, mysqli $mysqli): array {
    global $game_data;

    $building_id = $building['building_id'];
    $stored_amount = (int)$building['stored_resource'];
    
    // 1. Определяем тип ресурса, который мы собираем

// 1) Определяем, какой ресурс начислять.
// В нашем game_data.php поле res_type у производящих зданий используется как "ресурс строительства",
// поэтому для начисления берём тип по ID здания (как в оригинальном CoC).
$resource_type_key = '';
if ($building_id === 'gold_mine') {
    $resource_type_key = 'gold';
} elseif ($building_id === 'elixir_collector') {
    $resource_type_key = 'elixir';
} elseif ($building_id === 'dark_elixir_drill') {
    $resource_type_key = 'dark_elixir';
} else {
    $level = (int)$building['level'];
    $info = $game_data[$building_id] ?? null;

    if (!$info || !isset($info['levels'][$level]['res_type'])) {
        throw new RuntimeException("Неизвестное здание или уровень: {$building_id} (Ур. {$level})");
    }

    $resource_type_key = resourceConstToUserKey($info['levels'][$level]['res_type']);
    if ($resource_type_key === 'dark') $resource_type_key = 'dark_elixir';

    if ($resource_type_key !== 'gold' && $resource_type_key !== 'elixir' && $resource_type_key !== 'dark_elixir') {
        throw new RuntimeException("Не удалось определить тип ресурса для сбора: {$building_id}");
    }
}
    
    // 2. Определяем общую емкость хранилищ и текущий баланс
    $max_capacity = getTotalStorageCapacity($user['id'], $resource_type_key, $mysqli, (int)$user['townhall_lvl']);
    $current_balance = $user[$resource_type_key] ?? 0;
    
    // 3. Рассчитываем, сколько можно добавить
    $available_space = $max_capacity - $current_balance;
    $transfer_amount = min($stored_amount, $available_space);
    $remaining_in_building = $stored_amount - $transfer_amount;

    // Если хранилища заполнены или в здании нет накоплений — это НЕ ошибка сервера.
    // Просто ничего не зачисляем и оставляем накопления в здании.
    if ($transfer_amount <= 0) {
        return $user;
    }

    // 4. Обновление транзакционно
    $mysqli->begin_transaction();
    try {
        // a) Обновляем баланс пользователя атомарно
        $new_balance = creditUserResource($mysqli, (int)$user['id'], (string)$resource_type_key, (int)$transfer_amount);
        
        // b) Обновляем состояние производственного здания
        // last_collect — время последнего расчёта накопления (НЕ finish_time)
        $sql_building = "UPDATE player_buildings SET stored_resource = ?, last_collect = ? WHERE id = ?";
        $stmt_building = $mysqli->prepare($sql_building);
        if (!$stmt_building) {
            throw new Exception("Ошибка подготовки запроса BUILDING: " . $mysqli->error);
        }
        
        $now = time();
        $stmt_building->bind_param("iii", $remaining_in_building, $now, $building['id']);
        $stmt_building->execute();
        $stmt_building->close();

        $mysqli->commit();
        
        // Обновляем локальные данные пользователя
        $user[$resource_type_key] = $new_balance;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Ошибка при сборе ресурсов: " . $e->getMessage());
        throw new RuntimeException("Ошибка базы данных при сборе ресурсов.");
    }

// Для фронта: что именно собрали (эффект + обновление баланса без перезагрузки)
$user['_collect_res'] = $resource_type_key;
$user['_collect_amt'] = $transfer_amount;

return $user;
}

// ------------------ СТРОИТЕЛИ (обязательны для строительства/улучшений) ------------------

/**
 * В оригинальной игре первый строитель бесплатный: если у игрока нет ни одной "Хижины строителя" — создаём одну.
 */
function ensureDefaultBuilderHut(mysqli $mysqli, int $user_id): void {
    // В проекте есть 2 "источника правды" по строителям:
    // 1) users.builders_total (сколько строителей куплено/доступно)
    // 2) количество builder_hut в player_buildings (сколько хижин реально стоит на карте)
    //
    // Ранее мог быть сценарий: builders_total увеличился, а хижина не была добавлена => везде оставался 1 строитель.
    // Здесь синхронизируем: если хижин меньше, чем builders_total — достраиваем недостающие хижины автоматически.

    // 1) Узнаём целевое количество строителей из users
    $target = 1;
    $stmtU = $mysqli->prepare("SELECT builders_total FROM users WHERE id = ?");
    if ($stmtU) {
        $stmtU->bind_param('i', $user_id);
        $stmtU->execute();
        $rowU = $stmtU->get_result()->fetch_assoc();
        $stmtU->close();
        if ($rowU) {
            $target = max(1, (int)($rowU['builders_total'] ?? 1));
        }
    }

    // 2) Считываем занятые координаты и текущее количество хижин
    $used = [];
    $countHuts = 0;
    $stmtXY = $mysqli->prepare("SELECT building_id, x, y FROM player_buildings WHERE user_id = ?");
    if (!$stmtXY) {
        return;
    }
    $stmtXY->bind_param('i', $user_id);
    $stmtXY->execute();
    $resXY = $stmtXY->get_result();
    while ($r = $resXY->fetch_assoc()) {
        $x = (int)($r['x'] ?? 0);
        $y = (int)($r['y'] ?? 0);
        $used[$x . ':' . $y] = true;
        if (($r['building_id'] ?? '') === 'builder_hut') $countHuts++;
    }
    $stmtXY->close();

    if ($countHuts >= $target) {
        // Если в users меньше, чем реально стоит хижин — не ломаем прогресс.
        // Просто синхронизируем вверх.
        if ($countHuts > $target) {
            $stmtFix = $mysqli->prepare("UPDATE users SET builders_total = ? WHERE id = ? AND builders_total < ?");
            if ($stmtFix) {
                $stmtFix->bind_param('iii', $countHuts, $user_id, $countHuts);
                $stmtFix->execute();
                $stmtFix->close();
            }
        }
        return;
    }

    $missing = $target - $countHuts;

    // 3) Подбираем свободные клетки для новых хижин
    $preferred = [
        [6, 6], [38, 6], [6, 38], [38, 38],
        [22, 6], [6, 22], [38, 22], [22, 38],
        [12, 12], [32, 12], [12, 32], [32, 32],
    ];

    $findSpot = function() use (&$used, &$preferred): array {
        foreach ($preferred as $pos) {
            $k = $pos[0] . ':' . $pos[1];
            if (!isset($used[$k])) {
                $used[$k] = true;
                return ['x' => (int)$pos[0], 'y' => (int)$pos[1]];
            }
        }
        // fallback: простое сканирование поля
        for ($y = 1; $y <= 44; $y++) {
            for ($x = 1; $x <= 44; $x++) {
                $k = $x . ':' . $y;
                if (!isset($used[$k])) {
                    $used[$k] = true;
                    return ['x' => $x, 'y' => $y];
                }
            }
        }
        return ['x' => 1, 'y' => 1];
    };

    // 4) Определяем реальные колонки (на случай отличий структуры)
    $cols = [];
    $resCols = $mysqli->query("SHOW COLUMNS FROM player_buildings");
    if ($resCols) {
        while ($r = $resCols->fetch_assoc()) {
            $cols[strtolower($r['Field'])] = true;
        }
    }

    $addRow = function(int $x, int $y) use ($mysqli, $user_id, &$cols): bool {
        $insertCols = [];
        $placeholders = [];
        $types = '';
        $params = [];

        $add = function(string $col, string $type, $val) use (&$cols, &$insertCols, &$placeholders, &$types, &$params) {
            if (!isset($cols[strtolower($col)])) return;
            $insertCols[] = $col;
            $placeholders[] = '?';
            $types .= $type;
            $params[] = $val;
        };

        $add('user_id', 'i', $user_id);
        $add('building_id', 's', 'builder_hut');
        $add('level', 'i', 1);
        $add('x', 'i', $x);
        $add('y', 'i', $y);
        $add('stored_resource', 'i', 0);
        $add('status', 's', 'active');
        $add('finish_time', 'i', 0);
        $add('last_collect', 'i', 0);

        if (!$insertCols) return false;

        $sql = "INSERT INTO player_buildings (`" . implode("`,`", $insertCols) . "`) VALUES (" . implode(",", $placeholders) . ")";
        $ins = $mysqli->prepare($sql);
        if (!$ins) return false;

        $bind = [];
        $bind[] = $types;
        for ($i = 0; $i < count($params); $i++) $bind[] = &$params[$i];
        @call_user_func_array([$ins, 'bind_param'], $bind);
        $ok = $ins->execute();
        $ins->close();
        return (bool)$ok;
    };

    for ($i = 0; $i < $missing; $i++) {
        $spot = $findSpot();
        $addRow($spot['x'], $spot['y']);
    }

    // 5) Финальная синхронизация users.builders_total
    $newTotal = $countHuts + $missing;
    $stmtFix2 = $mysqli->prepare("UPDATE users SET builders_total = ? WHERE id = ?");
    if ($stmtFix2) {
        $stmtFix2->bind_param('ii', $newTotal, $user_id);
        $stmtFix2->execute();
        $stmtFix2->close();
    }
}

/**
 * Закрывает завершённые таймеры построек/улучшений, чтобы не было "улучшается 0с" бесконечно.
 */
function finalizeCompletedBuildTimers(mysqli $mysqli, int $user_id): void {
    $now = time();

    // Finish upgrades: apply target_level only after the timer ends
    $stmtU = $mysqli->prepare("UPDATE player_buildings SET status='active', finish_time=0, level=COALESCE(target_level, level), target_level=NULL WHERE user_id=? AND status='upgrading' AND finish_time > 0 AND finish_time <= ?");
    if ($stmtU) {
        $stmtU->bind_param('ii', $user_id, $now);
        $stmtU->execute();
        $stmtU->close();
    }

    // Finish constructions
    $stmtC = $mysqli->prepare("UPDATE player_buildings SET status='active', finish_time=0 WHERE user_id=? AND status='constructing' AND finish_time > 0 AND finish_time <= ?");
    if ($stmtC) {
        $stmtC->bind_param('ii', $user_id, $now);
        $stmtC->execute();
        $stmtC->close();
    }
}


/**
 * Возвращает количество строителей: всего / занято / свободно.
 */
function getBuilderCounts(mysqli $mysqli, int $user_id): array {
    ensureDefaultBuilderHut($mysqli, $user_id);
    finalizeCompletedBuildTimers($mysqli, $user_id);

    // Всего строителей = количество хижин строителя
    $total = 1;
    $stmtT = $mysqli->prepare("SELECT COUNT(*) AS c FROM player_buildings WHERE user_id = ? AND building_id = 'builder_hut'");
    if ($stmtT) {
        $stmtT->bind_param('i', $user_id);
        $stmtT->execute();
        $row = $stmtT->get_result()->fetch_assoc();
        $stmtT->close();
        $total = max(1, (int)($row['c'] ?? 1));
    }

    // Занято = количество активных процессов стройки/улучшения (до finish_time)
    $busy = 0;
    $now = time();
    $stmtB = $mysqli->prepare("SELECT COUNT(*) AS c FROM player_buildings WHERE user_id = ? AND status IN ('constructing','upgrading') AND finish_time > ?");
    if ($stmtB) {
        $stmtB->bind_param('ii', $user_id, $now);
        $stmtB->execute();
        $row = $stmtB->get_result()->fetch_assoc();
        $stmtB->close();
        $busy = (int)($row['c'] ?? 0);
    }

    $free = max(0, $total - $busy);
    return ['total' => $total, 'busy' => $busy, 'free' => $free];
}

/**
 * Требует свободного строителя для начала стройки/апгрейда.
 */
function requireFreeBuilder(mysqli $mysqli, int $user_id): void {
    $counts = getBuilderCounts($mysqli, $user_id);
    if ((int)($counts['free'] ?? 0) <= 0) {
        throw new GameActionException('Нет свободных строителей. Дождитесь окончания текущих работ или наймите дополнительного строителя.', 400, [
            'type' => 'no_builder',
        ]);
    }
}

/**
 * ЗАПУСК ПРОЦЕССА УЛУЧШЕНИЯ СУЩЕСТВУЮЩЕГО ЗДАНИЯ.
 */
function startBuildingUpgrade(mysqli $mysqli, array $user, array $building): array {
    global $game_data;
    $user_id = $user['id'];
    $th_lvl = $user['townhall_lvl'];
    $building_row_id = $building['id'];
    $building_id = $building['building_id'];
    $current_level = (int)$building['level'];
    $next_level = $current_level + 1;

    // 1. Проверки
    if (($building['status'] ?? '') !== 'active') {
        throw new GameActionException("Здание уже строится или улучшается.", 409, [
            'type' => 'busy',
            'building_id' => $building_id,
        ]);
    }
    if (!isset($game_data[$building_id]['levels'][$next_level])) {
        throw new GameActionException("Достигнут максимальный уровень для этого здания.", 400, [
            'type' => 'max_level',
            'building_id' => $building_id,
        ]);
    }
    
    $next_stats = $game_data[$building_id]['levels'][$next_level];
    $cost = $next_stats['cost'] ?? 0;
    $time = $next_stats['time'] ?? 0;
    $th_req = $next_stats['th_req'] ?? 1;

    if ($th_lvl < $th_req) {
        throw new GameActionException("Требуется Ратуша Ур. {$th_req} для улучшения до Ур. {$next_level}.", 400, [
            'type' => 'need_townhall',
            'th_req' => (int)$th_req,
            'th_have' => (int)$th_lvl,
            'building_id' => $building_id,
            'next_level' => (int)$next_level,
        ]);
    }

    // По умолчанию берём первый вариант ресурса, если их несколько.
    // (Для стен в UI есть массовое улучшение с выбором ресурса.)
    $resource_const = is_array($next_stats['res_type']) ? $next_stats['res_type'][0] : ($next_stats['res_type'] ?? 'RES_GOLD');
    $resource_type_key = resourceConstToUserKey($resource_const);
    $have = (int)($user[$resource_type_key] ?? 0);
    if ($have < (int)$cost) {
        throwNotEnoughResources((string)$resource_type_key, (int)$cost, $have, 'улучшение здания');
    }

    // 1.5. Нужен свободный строитель ТОЛЬКО если апгрейд занимает время.
    // Стены (и любые instant-апгрейды с time=0) в CoC улучшаются без строителя.
    if ((int)$time > 0) {
        requireFreeBuilder($mysqli, (int)$user_id);
    }

    // 2. Транзакция
    $mysqli->begin_transaction();
    try {
        // a) Списание ресурсов с защитой от гонок/ухода в минус
        $new_balance = debitUserResource($mysqli, (int)$user_id, (string)$resource_type_key, (int)$cost);
        
        // b) Обновление статуса здания
        if ((int)$time > 0) {
            $finish_time = time() + $time;
            $sql_building = "UPDATE player_buildings SET status = 'upgrading', target_level = ?, finish_time = ? WHERE id = ? AND user_id = ?";
            $stmt_building = $mysqli->prepare($sql_building);
            $stmt_building->bind_param("iiii", $next_level, $finish_time, $building_row_id, $user_id);
            $stmt_building->execute();
            $stmt_building->close();
        } else {
            // instant upgrade (например, стены)
            $sql_building = "UPDATE player_buildings SET level = ?, status = 'active', target_level = NULL, finish_time = 0 WHERE id = ? AND user_id = ?";
            $stmt_building = $mysqli->prepare($sql_building);
            $stmt_building->bind_param("iii", $next_level, $building_row_id, $user_id);
            $stmt_building->execute();
            $stmt_building->close();
        }

        $mysqli->commit();
        
        // Обновляем локальные данные пользователя
        $user[$resource_type_key] = $new_balance;
        
    } catch (Exception $e) {
        $mysqli->rollback();
        error_log("Ошибка при улучшении здания: " . $e->getMessage());
        throw new RuntimeException("Ошибка базы данных при улучшении здания.", 500);
    }

    return $user;
}




/**
 * Находит первую свободную клетку (x,y) для нового здания.
 * ВАЖНО: в БД есть уникальный ключ по (user_id, x, y), поэтому нельзя вставлять (0,0) всем подряд.
 * По умолчанию сканирует поле 0..99 по X/Y и берет первую свободную позицию.
 */
function findFirstFreeBuildingXY(mysqli $mysqli, int $user_id, int $limit = 100): array {
    $used = [];
    $stmt = $mysqli->prepare("SELECT `x`,`y` FROM `player_buildings` WHERE `user_id` = ?");
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $x = (int)($row['x'] ?? 0);
                $y = (int)($row['y'] ?? 0);
                $used[$x . ':' . $y] = true;
            }
        }
        $stmt->close();
    }

    $limit = max(1, (int)$limit);
    for ($y = 0; $y < $limit; $y++) {
        for ($x = 0; $x < $limit; $x++) {
            $key = $x . ':' . $y;
            if (!isset($used[$key])) {
                return [$x, $y];
            }
        }
    }

    throw new GameActionException("Нет свободного места для размещения нового здания.", 409, [
        'type' => 'no_free_space',
        'limit' => $limit,
    ]);
}

/**
 * ПОКУПКА И СТРОИТЕЛЬСТВО НОВОГО ЗДАНИЯ.
 */
function buildNewBuilding(mysqli $mysqli, array $user, string $building_id): array {
    global $game_data;

    $user_id = (int)($user['id'] ?? 0);
    $th_lvl  = (int)($user['townhall_lvl'] ?? 1);

    if ($user_id <= 0) {
        throw new RuntimeException("Пользователь не найден.", 403);
    }

    // 1) Проверки
    $built_count = count(getPlayerBuildingsByType($mysqli, $building_id));
    $max_count   = getMaxCountForTH($building_id, $th_lvl);

    if ($built_count >= $max_count) {
        throw new GameActionException("Достигнут максимальный лимит зданий для вашей Ратуши (Ур. {$th_lvl}).", 400, [
            'type' => 'max_count',
            'building_id' => $building_id,
            'th_lvl' => (int)$th_lvl,
            'max_count' => (int)$max_count,
        ]);
    }

    $initial_level = 1;
    $stats = $game_data[$building_id]['levels'][$initial_level] ?? null;
    if (!$stats) {
        throw new RuntimeException("Не удалось найти данные здания: {$building_id}.", 500);
    }

    $cost   = (int)($stats['cost'] ?? 0);
    $time   = (int)($stats['time'] ?? 0);
    $th_req = (int)($stats['th_req'] ?? 1);

    if ($th_lvl < $th_req) {
        throw new GameActionException("Требуется Ратуша Ур. {$th_req} для строительства.", 400, [
            'type' => 'need_townhall',
            'th_req' => (int)$th_req,
            'th_have' => (int)$th_lvl,
            'building_id' => $building_id,
        ]);
    }

    $resource_const    = is_array($stats['res_type'] ?? null) ? ($stats['res_type'][0] ?? RES_GOLD) : ($stats['res_type'] ?? RES_GOLD);
    $resource_type_key = resourceConstToUserKey((string)$resource_const);
    $have              = (int)($user[$resource_type_key] ?? 0);

    if ($have < $cost) {
        throwNotEnoughResources((string)$resource_type_key, $cost, $have, 'строительство здания');
    }

    // Нужен свободный строитель
    requireFreeBuilder($mysqli, $user_id);

    // 2) Транзакция
    $mysqli->begin_transaction();
    try {
        // a) Списание ресурсов с защитой от гонок/ухода в минус
        $new_balance = debitUserResource($mysqli, (int)$user_id, (string)$resource_type_key, (int)$cost);

        // b) Создаем запись здания
        $status = ($time > 0) ? 'constructing' : 'active';
        $finish_time = ($time > 0) ? (time() + $time) : 0;

        $xy = findFirstFreeBuildingXY($mysqli, $user_id, 100);
        $x = (int)$xy[0]; $y = (int)$xy[1];
        $sql_building = "INSERT INTO `player_buildings` (`user_id`,`building_id`,`level`,`x`,`y`,`status`,`finish_time`) VALUES (?,?,?,?,?,?,?)";
        $stmt_building = $mysqli->prepare($sql_building);
        if (!$stmt_building) {
            throw new RuntimeException("DB prepare failed (player_buildings): " . $mysqli->error, 500);
        }

        $bindOk = $stmt_building->bind_param("isiiisi", $user_id, $building_id, $initial_level, $x, $y, $status, $finish_time);
        if (!$bindOk) {
            $err = $stmt_building->error ?: $mysqli->error;
            $stmt_building->close();
            throw new RuntimeException("DB bind failed (player_buildings): " . $err, 500);
        }

        $ok = $stmt_building->execute();
        if (!$ok) {
            $err = $stmt_building->error ?: $mysqli->error;

            // Фолбек на случай несовпадения ENUM статусов в БД (старый дамп без 'constructing')
            if ($status === 'constructing' && stripos($err, 'Incorrect') !== false) {
                $status2 = 'upgrading';
                $stmt_building->bind_param("isiiisi", $user_id, $building_id, $initial_level, $x, $y, $status2, $finish_time);
                $ok2 = $stmt_building->execute();
                if (!$ok2) {
                    $err2 = $stmt_building->error ?: $mysqli->error;
                    $stmt_building->close();
                    throw new RuntimeException("DB execute failed (player_buildings): " . $err2, 500);
                }
            } else {
                $stmt_building->close();
                throw new RuntimeException("DB execute failed (player_buildings): " . $err, 500);
            }
        }

        $new_building_id = (int)$stmt_building->insert_id;
        $stmt_building->close();

        $mysqli->commit();

        // Обновляем локальные данные пользователя
        $user[$resource_type_key] = $new_balance;

        return ['user' => $user, 'new_building_id' => $new_building_id];

    } catch (Throwable $e) {
        $mysqli->rollback();
        error_log('[buildNewBuilding] ' . $e->getMessage());
        throw $e;
    }
}


// ------------------ Получение данных зданий ------------------
function getPlayerBuildingsByType(mysqli $mysqli, string $building_id): array {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($user_id === 0) {
        return [];
    }

    // Завершаем просроченные таймеры, чтобы статус не зависал в "upgrading 0с"
    finalizeCompletedBuildTimers($mysqli, $user_id);

    $stmt = $mysqli->prepare("SELECT id, building_id, level, x, y, stored_resource, status, finish_time FROM player_buildings WHERE user_id = ? AND building_id = ? ORDER BY level DESC");
    if (!$stmt) {
        error_log('Prepare failed: ' . $mysqli->error);
        return [];
    }

    $stmt->bind_param("is", $user_id, $building_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $buildings = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $buildings;
}

/**
 * Получает одно здание по ID
 */
function getPlayerBuildingById(mysqli $mysqli, int $building_id): ?array {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if ($user_id === 0) {
        return null;
    }

    // Завершаем просроченные таймеры, чтобы статус не зависал
    finalizeCompletedBuildTimers($mysqli, $user_id);

    $stmt = $mysqli->prepare("SELECT id, building_id, level, x, y, stored_resource, status, finish_time FROM player_buildings WHERE user_id = ? AND id = ?");
    if (!$stmt) {
        error_log('Prepare failed: ' . $mysqli->error);
        return null;
    }

    $stmt->bind_param("ii", $user_id, $building_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $building = $result->fetch_assoc();
    $stmt->close();

    return $building;
}






// ------------------ ОБРАБОТКА ОШИБОК ------------------
/**
 * Логирование в файл
 * @param string $message Сообщение для логирования
 */
function logToFile(string $message) {
    $logFile = __DIR__ . '/../logs/system.log';
    $date = date('[Y-m-d H:i:s]');
    file_put_contents($logFile, "$date $message\n", FILE_APPEND);
}


/**
 * Универсальный обработчик ошибок
 * @param Throwable $e Исключение или ошибка
 * @param bool $isAjax Флаг AJAX-запроса
 */
function handleError(Throwable $e, bool $isAjax = false): void {
    $code = $e->getCode() ?: 500;
    http_response_code($code);

    $errorData = [
        'message' => $e->getMessage(),
        'code' => $code,
    ];

    if (defined('ENVIRONMENT') && ENVIRONMENT === 'development') {
        $errorData['file'] = $e->getFile();
        $errorData['line'] = $e->getLine();
        $errorData['trace'] = $e->getTrace();
    }

    if (function_exists('logToFile')) {
        logToFile("ERROR: " . json_encode($errorData));
    }

    if ($isAjax || (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')) {
        header('Content-Type: application/json');
        die(json_encode(['error' => $errorData['message']]));
    }

    if ($code === 401) {
        header('Location: login.php');
        exit;
    }

    $message = (defined('ENVIRONMENT') && ENVIRONMENT === 'development')
        ? '<pre>' . print_r($errorData, true) . '</pre>'
        : 'Произошла ошибка. Пожалуйста, попробуйте позже.';

    die('<div class="error">' . $message . '</div>');
}


// ------------------ БЕЗОПАСНОСТЬ ------------------

/**
 * Проверка CSRF токена
 * @param string $token Токен для проверки
 * @return bool Результат проверки
 */
function check_csrf(string $token): bool {
    if (empty($token)) return false;
    generateCsrfToken();
    return validateCsrfToken($token);
}

/**
 * Защита от брутфорса
 * @param string $login Логин пользователя
 * @return bool Превышено ли количество попыток
 */
function isBruteforceAttempt(string $login): bool {
    global $mysqli;
    
    $stmt = $mysqli->prepare("SELECT login_attempts, last_attempt FROM users WHERE login = ?");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    return $result && 
           $result['login_attempts'] >= 5 && 
           time() - strtotime($result['last_attempt']) < 60;
}

/**
 * Логирование неудачной попытки входа
 * @param string $login Логин пользователя
 */
function logFailedLoginAttempt(string $login) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("UPDATE users SET 
        login_attempts = IF(last_attempt < DATE_SUB(NOW(), INTERVAL 1 HOUR), 1, login_attempts + 1),
        last_attempt = NOW()
        WHERE login = ?");
    $stmt->bind_param("s", $login);
    $stmt->execute();
    $stmt->close();
}

/**
 * Сброс счетчика попыток входа
 * @param int $user_id ID пользователя
 */
function resetLoginAttempts(int $user_id) {
    global $mysqli;
    
    $stmt = $mysqli->prepare("UPDATE users SET login_attempts = 0 WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();
}

// ------------------ УТИЛИТЫ ------------------

/**
 * Получение базового URL сайта
 * @return string Базовый URL
 */
function getBaseUrl(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    return $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
}

/**
 * Подтверждение действия (JS)
 * @param string $message Текст подтверждения
 * @return string JavaScript код
 */
function confirm(string $message): string {
    return 'onclick="return confirm(\'' . addslashes($message) . '\')"';
}

// --- СИМУЛЯЦИЯ: Максимальное количество зданий на уровне Ратуши ---
function getMaxCountForTH(string $building_id, int $th_lvl): int {
    // Максимальное количество построек/ловушек/стен по уровню Ратуши (максимально близко к CoC).
    // Важно: для "слияний" (Ricochet/Multi-Archer) мы даём отдельный лимит, без принудительного вычитания из базовых пушек/вышек.
    static $max_building_counts = [
        // --- Ресурсы / экономика ---
        'gold_storage'      => [1 => 1, 3 => 2, 6 => 3, 9 => 4, 16 => 4],
        'elixir_storage'    => [1 => 1, 3 => 2, 6 => 3, 9 => 4, 16 => 4],
        'dark_storage'      => [1 => 0, 7 => 1, 16 => 1],
        'gold_mine'         => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 6, 9 => 7, 10 => 7, 11 => 7, 14 => 8, 16 => 8],
        'elixir_collector'  => [1 => 1, 2 => 2, 3 => 3, 4 => 4, 5 => 5, 6 => 6, 7 => 6, 8 => 6, 9 => 7, 10 => 7, 11 => 7, 14 => 8, 16 => 8],
        'dark_elixir_drill' => [1 => 0, 7 => 1, 8 => 2, 9 => 3, 15 => 3, 16 => 3],

        // --- Армия ---
        // В современном CoC Barracks/Army Camps имеют фиксированное количество (без 4 казарм как раньше).
        'barracks'   => [1 => 1, 16 => 1],
        'army_camp'  => [1 => 1, 16 => 1],
        // Зал героев
        'hero_hall'  => [1 => 1, 16 => 1],
        'laboratory' => [3 => 1, 16 => 1],
        'dark_barracks' => [7 => 1, 16 => 1],
        'spell_factory' => [5 => 1, 16 => 1],
        'dark_spell_factory' => [8 => 1, 16 => 1],
        'siege_workshop' => [12 => 1, 16 => 1],

        // --- Оборона (Домашняя деревня) ---
        'cannon'         => [1 => 1, 2 => 2, 5 => 3, 7 => 4, 8 => 5, 9 => 6, 10 => 7, 16 => 7],
        'archer_tower'   => [2 => 1, 4 => 2, 5 => 3, 7 => 4, 8 => 5, 9 => 6, 10 => 7, 11 => 8, 16 => 8],
        'mortar'         => [3 => 1, 6 => 2, 8 => 3, 9 => 4, 16 => 4],
        'air_defense'    => [4 => 1, 6 => 2, 7 => 3, 9 => 4, 16 => 4],
        'wizard_tower'   => [5 => 1, 7 => 2, 8 => 3, 10 => 4, 11 => 5, 16 => 5],
        'air_sweeper'    => [8 => 1, 10 => 2, 16 => 2],
        'hidden_tesla'   => [7 => 1, 8 => 2, 9 => 3, 10 => 4, 16 => 4],
        'bomb_tower'     => [8 => 1, 10 => 2, 16 => 2],
        'x_bow'          => [9 => 2, 10 => 3, 11 => 4, 16 => 4],
        'inferno_tower'  => [10 => 2, 11 => 3, 16 => 3],
        'eagle_artillery'=> [11 => 1, 16 => 1],
        'scattershot'    => [13 => 2, 16 => 2],
        'spell_tower'    => [15 => 2, 16 => 2],
        'monolith'       => [15 => 1, 16 => 1],

        // Слияния на TH16 (по патч-нотам Supercell: 2 Ricochet + 2 Multi-Archer)
        'ricochet_cannon'    => [16 => 2],
        'multi_archer_tower' => [16 => 2],

        // --- Стены ---
        'wall' => [1 => 0, 2 => 25, 3 => 50, 4 => 75, 5 => 100, 6 => 125, 7 => 175, 8 => 225, 9 => 250, 10 => 275, 11 => 300, 14 => 325, 16 => 325],

        // --- Ловушки ---
        'bomb'             => [1 => 0, 3 => 2, 5 => 4, 7 => 5, 14 => 6, 16 => 6],
        'spring_trap'      => [1 => 0, 3 => 2, 5 => 4, 6 => 6, 7 => 8, 16 => 8],
        'air_bomb'         => [1 => 0, 4 => 1, 6 => 2, 7 => 3, 9 => 4, 14 => 5, 16 => 5],
        'giant_bomb'       => [1 => 0, 6 => 1, 7 => 2, 8 => 3, 10 => 4, 14 => 5, 16 => 5],
        'seeking_air_mine' => [1 => 0, 7 => 1, 8 => 2, 10 => 3, 12 => 4, 14 => 5, 16 => 5],
        'skeleton_trap'    => [1 => 0, 8 => 1, 14 => 2, 16 => 2],
        'tornado_trap'     => [1 => 0, 11 => 1, 16 => 1],
    ];

    // Если здание не описано в таблице лимитов, считаем, что оно уникальное (лимит 1),
    // чтобы не блокировать строительство из-за нулевого значения по умолчанию.
    $max_counts = $max_building_counts[$building_id] ?? [1 => 1];

    $count = 0;
    foreach ($max_counts as $th_req => $max) {
        if ($th_lvl >= (int)$th_req) {
            $count = (int)$max;
        }
    }
    return $count;
}

// ------------------ АККАУНТ: НИК/ПОЧТА/ПИСЬМА ------------------

/**
 * Нормализация имени игрока (логин/ник) под стиль CoC.
 * Разрешаем любые языки, но только буквы/цифры/пробел/._-.
 *
 * @return array [bool ok, string value_or_error]
 */
function normalize_player_name($name, $min = 3, $max = 15) {
    $name = is_string($name) ? $name : '';
    $name = trim($name);
    // убираем управляющие/невидимые
    $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
    $name = preg_replace('/\s+/u', ' ', $name);
    $len = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($len < $min || $len > $max) {
        return [false, 'Имя должно быть от ' . (int)$min . ' до ' . (int)$max . ' символов'];
    }
    // только буквы/цифры/пробел/._-
    if (!preg_match('/^[\p{L}\p{N} ._\-]+$/u', $name)) {
        return [false, 'Допустимы буквы, цифры, пробел, точки и _-'];
    }
    // без лидирующих/двойных пробелов уже нормализовано
    return [true, $name];
}

/** Возвращает origin сайта вида https://host */
function site_origin() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

/**
 * Простейшая отправка письма (HTML). На хостинге обычно работает через mail().
 */
function send_game_mail($to, $subject, $html, $text = '') {
    $to = trim((string)$to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) return false;

    $fromHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $from = 'no-reply@' . preg_replace('/^www\./', '', $fromHost);

    $subject = (string)$subject;
    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-type: text/html; charset=UTF-8';
    $headers[] = 'From: Clash Browser <' . $from . '>';
    $headers[] = 'Reply-To: ' . $from;

    // Поддержка русских тем
    $subjectEnc = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $body = (string)$html;
    if ($body === '') {
        $body = nl2br(htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8'));
    }

    $ok = @mail($to, $subjectEnc, $body, implode("\r\n", $headers));
    if (!$ok) {
        error_log('[MAIL] Failed to send to ' . $to . ' subject=' . $subject);
    }
    return $ok;
}

/**
 * Генерация сырого токена (hex), удобно для ссылок.
 */
function make_hex_token($bytes = 16) {
    try {
        return bin2hex(random_bytes((int)$bytes));
    } catch (Exception $e) {
        return bin2hex(openssl_random_pseudo_bytes((int)$bytes));
    }
}


/**
 * Алиас для генерации HEX-токена (совместимость)
 * @param int $bytes
 * @return string
 */
function create_token_hex($bytes = 16) {
    return function_exists('make_hex_token') ? make_hex_token((int)$bytes) : bin2hex(random_bytes((int)$bytes));
}

?>