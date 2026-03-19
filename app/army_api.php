<?php
/**
 * app/army_api.php
 * JSON API for Barracks + Laboratory backend.
 *
 * Stage 1: backend foundation (state + core actions).
 * Frontend can call:
 *   GET  /app/army_api.php?action=barracks_state
 *   POST /app/army_api.php?action=barracks_train (unit_id, qty, csrf_token)
 *   POST /app/army_api.php?action=barracks_cancel (queue_id, csrf_token)
 *   POST /app/army_api.php?action=barracks_build (csrf_token)
 *   POST /app/army_api.php?action=barracks_upgrade (csrf_token)
 *   POST /app/army_api.php?action=barracks_speedup (mode=all|current, csrf_token)
 *   GET  /app/army_api.php?action=lab_state
 *   POST /app/army_api.php?action=lab_start (tech_id, csrf_token)
 */

require_once __DIR__ . '/../system/function.php';
require_once __DIR__ . '/../system/game_data.php';
require_once __DIR__ . '/../system/army_helpers.php';

$coc_texts = [];
try {
    $coc_texts = require __DIR__ . '/../system/coc_texts.php';
    if (!is_array($coc_texts)) $coc_texts = [];
} catch (Throwable $e) {
    $coc_texts = [];
}

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors','0');
ini_set('html_errors','0');
error_reporting(0);
ob_start();

global $mysqli, $game_data;

function army_api_json(array $payload, int $code = 200): void {
    http_response_code($code);
    if (ob_get_length()) { @ob_clean(); }
    if (ob_get_length()) { @ob_clean(); }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function army_api_error(string $msg, int $code = 400): void {
    army_api_json(['ok' => false, 'error' => $msg, 'message' => $msg], $code);
}


function army_api_resKey(string $resType): string {
    // game_data.php defines RES_* constants as strings (gold/elixir/dark_elixir/gems)
    switch ($resType) {
        case RES_GOLD:   return 'gold';
        case RES_ELIXIR: return 'elixir';
        case RES_DARK:   return 'dark_elixir';
        case RES_GEMS:   return 'gems';
        default:         return '';
    }
}

// --- Spells assets helper ---
function army_api_spell_img(string $spellId): string {
    $spellId = trim($spellId);
    if ($spellId === '') return '/images/icons/elixir.png';
    $parts = array_values(array_filter(explode('_', $spellId), static function($p){ return $p !== ''; }));
    $parts = array_map(static function($p){
        $p = (string)$p;
        return $p !== '' ? (strtoupper(substr($p, 0, 1)) . substr($p, 1)) : $p;
    }, $parts);
    $name = implode('_', $parts);
    return '/images/spells/' . $name . '_info.png';
}

// Normalize "unlocks" field from game_data_buildings.php.
// Some levels contain arrays (e.g., spell_factory level 4 unlocks multiple spells).
// Casting array to string in PHP produces "Array", which leaks into UI as "ОТКРЫВАЕТ Array".
function army_api_unlocks_to_string($u): string {
    if ($u === null) return '';
    if (is_array($u)) {
        $out = [];
        foreach ($u as $x) {
            $x = trim((string)$x);
            if ($x !== '') $out[] = $x;
        }
        return implode(',', $out);
    }
    return trim((string)$u);
}


// ---- Buildings (Army/Lab) helpers ----
function army_api_allowed_buildings(): array {
    return [
        'barracks',
        'dark_barracks',
        'army_camp',
        'spell_factory',
        'dark_spell_factory',
        'hero_hall',
        'siege_workshop',
        // Laboratory is used in Barracks -> Buildings and must open its card.
        'laboratory',
        'workshop',
    ];
}

function army_api_buildings_state(mysqli $mysqli, int $userId, array $game_data, int $townhallLvl): array {
    // Осадные машины/мастерская осадных машин выводятся отдельно (позже в Клановой крепости),
    // поэтому здесь их не показываем.
    $ids = ['barracks','dark_barracks','army_camp','spell_factory','dark_spell_factory','hero_hall','siege_workshop','laboratory'];
    $out = [];
    $now = time();
    foreach ($ids as $bid) {
        if (!isset($game_data[$bid]) || !is_array($game_data[$bid])) continue;
        $lvl = army_get_building_level($mysqli, $userId, $bid);
        $row = army_get_building_row($mysqli, $userId, $bid);
        $status = $row ? (string)($row['status'] ?? 'active') : 'none';
        $finish = $row ? (int)($row['finish_time'] ?? 0) : 0;
        // During upgrading we keep current `level` and expose `target_level` for UI.
        $target_level = $row ? (int)($row['target_level'] ?? 0) : 0;
        $maxLvl = isset($game_data[$bid]['levels']) && is_array($game_data[$bid]['levels']) ? count($game_data[$bid]['levels']) : 0;

        $canBuild = false;
        $canUp = false;
        $locked = '';

        if ($lvl <= 0) {
            $l1 = $game_data[$bid]['levels'][1] ?? null;
            $thReq = $l1 ? (int)($l1['th_req'] ?? 1) : 1;
            if ($townhallLvl < $thReq) {
                $locked = 'Требуется Ратуша ' . $thReq . '.';
            } else {
                // check max count for TH if helper exists
                try {
                    $builtCount = function_exists('getPlayerBuildingsByType') ? count(getPlayerBuildingsByType($mysqli, $bid)) : 0;
                    $maxCount = function_exists('getMaxCountForTH') ? (int)getMaxCountForTH($bid, $townhallLvl) : 1;
                    if ($builtCount >= $maxCount) {
                        $locked = 'Достигнут лимит построек.';
                    } else {
                        $canBuild = true;
                    }
                } catch (Throwable $e) {
                    $canBuild = true;
                }
            }
        } else {
            if ($status !== 'active' && $finish > $now) {
                $locked = 'Занято: стройка/улучшение.';
            } else {
                $next = $lvl + 1;
                if (isset($game_data[$bid]['levels'][$next])) {
                    $thReq = (int)($game_data[$bid]['levels'][$next]['th_req'] ?? 1);
                    if ($townhallLvl < $thReq) {
                        $locked = 'Требуется Ратуша ' . $thReq . '.';
                    } else {
                        $canUp = true;
                    }
                } else {
                    $locked = 'Макс. уровень.';
                }
            }
        }

        $desc = '';
        if (!empty($GLOBALS['coc_texts']['buildings'][$bid])) {
            $desc = (string)$GLOBALS['coc_texts']['buildings'][$bid];
        }

        $out[] = [
            'id' => $bid,
            'name' => (string)($game_data[$bid]['name'] ?? $bid),
            'description' => $desc,
            'level' => (int)$lvl,
            'target_level' => ($target_level > 0 ? (int)$target_level : null),
            'status' => $status,
            'finish_time' => $finish,
            'max_level' => $maxLvl,
            'can_build' => $canBuild,
            'can_upgrade' => $canUp,
            'locked_reason' => $locked,
        ];
    }
    return $out;
}

// Backward-compatible alias (older JS fixes call this name)
function army_api_get_buildings_state(mysqli $mysqli, int $userId, array $game_data, int $townhallLvl): array {
    return army_api_buildings_state($mysqli, $userId, $game_data, $townhallLvl);
}

function army_api_get_post(string $key, $default = null) {
    return isset($_POST[$key]) ? $_POST[$key] : $default;
}

function army_api_get_get(string $key, $default = null) {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function army_api_require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    $token = army_api_get_post('csrf_token', '');
    // Optional: allow header token for fetch clients
    if ($token === '' && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) $token = (string)$_SERVER['HTTP_X_CSRF_TOKEN'];
    if (!function_exists('validateCsrfToken') || !validateCsrfToken($token)) {
        army_api_error('CSRF-токен недействителен', 403);
    }
}

function army_api_list_unlocked_troops(array $game_data, int $barracksLvl): array {
    $out = [];
    if (!isset($game_data['barracks']['levels']) || $barracksLvl <= 0) return $out;
    $levels = $game_data['barracks']['levels'];
    for ($lvl = 1; $lvl <= $barracksLvl; $lvl++) {
        if (!isset($levels[$lvl]['unlocks'])) continue;
        $u = (string)$levels[$lvl]['unlocks'];
        if ($u !== '') $out[$u] = true;
    }
    return array_keys($out);
}

function army_api_is_researchable(array $def): bool {
    if (!is_array($def)) return false;
    if (empty($def['levels']) || !is_array($def['levels'])) return false;
    $t = isset($def['type']) ? (string)$def['type'] : '';
    // Исследования в Лаборатории: войска/темные войска/заклинания/темные заклинания.
    // Осадные машины исключены: они будут жить в механиках клана.
    return in_array($t, [TYPE_TROOP, TYPE_DARK_TROOP, TYPE_SPELL, TYPE_DARK_SPELL], true);
}

function army_api_researchables(array $game_data): array {
    $out = [];
    foreach ($game_data as $id => $def) {
        if (!is_string($id) || $id === '') continue;
        if (!is_array($def)) continue;
        if (!army_api_is_researchable($def)) continue;
        $maxLevel = 1;
        if (isset($def['levels']) && is_array($def['levels'])) {
            $keys = array_keys($def['levels']);
            $maxLevel = (int)max($keys);
            if ($maxLevel <= 0) $maxLevel = 1;
        }
        $out[] = [
            'id' => $id,
            'name' => isset($def['name']) ? (string)$def['name'] : $id,
            'type' => isset($def['type']) ? (string)$def['type'] : '',
            'max_level' => $maxLevel,
        ];
    }
    return $out;
}



function army_api_researchable_ids(array $game_data): array {
    $ids = [];
    foreach (army_api_researchables($game_data) as $r) {
        if (!empty($r['id'])) $ids[] = (string)$r['id'];
    }
    return $ids;
}


// --- Troop catalog helpers (Stage 1) ---
// ---- Stage 1 helpers: troop catalog for UI (no hardcoded lists on frontend) ----
function army_api_troop_folder(string $unitId): string {
    // Default: snake_case -> Title_Case with underscores
    if ($unitId === 'pekka') return 'P.E.K.K.A';
    $parts = preg_split('/_+/', $unitId);
    $parts = array_filter($parts, function($x){ return $x !== null && $x !== ''; });
    $out = [];
    foreach ($parts as $p) {
        $out[] = ucfirst(strtolower($p));
    }
    return implode('_', $out);
}

function army_api_troop_image_path(string $unitId): string {
    $folder = army_api_troop_folder($unitId);
    $avatar = 'Avatar_' . $folder . '.png';
    // We return web path (UI uses it directly). If file is missing, frontend will still try.
    return '/images/warriors/' . $folder . '/' . $avatar;
}

function army_api_required_building_level(array $game_data, string $buildingId, string $entityId): int {
    $req = 0;
    if (empty($game_data[$buildingId]['levels']) || !is_array($game_data[$buildingId]['levels'])) return 0;
    foreach ($game_data[$buildingId]['levels'] as $lvl => $def) {
        if (!is_array($def)) continue;
        if (!isset($def['unlocks'])) continue;
        $u = $def['unlocks'];
        if (is_array($u)) {
            foreach ($u as $x) {
                if ((string)$x === $entityId) { $req = (int)$lvl; break 2; }
            }
        } else {
            if ((string)$u === $entityId) { $req = (int)$lvl; break; }
        }
    }
    return max(0, (int)$req);
}

function army_api_max_level_by_th(array $levels, int $townhallLvl): int {
    // Returns highest level allowed for current Town Hall (based on per-level th_req in game_data).
    $max = 1;
    foreach ($levels as $lvl => $def) {
        $lvl = (int)$lvl;
        if ($lvl < 1 || !is_array($def)) continue;
        $req = (int)($def['th_req'] ?? 0);
        if ($req > 0 && $townhallLvl > 0 && $townhallLvl < $req) continue;
        if ($lvl > $max) $max = $lvl;
    }
    return $max;
}


function army_api_unlock_building_for_type(string $type): string {
    switch ($type) {
        case TYPE_TROOP: return 'barracks';
        case TYPE_DARK_TROOP: return 'dark_barracks';
        case TYPE_SPELL: return 'spell_factory';
        case TYPE_DARK_SPELL: return 'dark_spell_factory';
        case TYPE_SIEGE: return 'siege_workshop';
        case TYPE_HERO: return 'hero_hall';
        default: return '';
    }
}

function army_api_unlock_building_name(string $buildingId): string {
    switch ($buildingId) {
        case 'barracks': return 'Казармы';
        case 'dark_barracks': return 'Тёмные казармы';
        case 'spell_factory': return 'Фабрика заклинаний';
        case 'dark_spell_factory': return 'Тёмная фабрика заклинаний';
        case 'hero_hall': return 'Зал героев';
        case 'siege_workshop':
        case 'workshop':
            return 'Мастерская осадных машин';
        default: return 'Здание';
    }
}

function army_api_required_barracks_level(array $game_data, string $unitId): int {
    return army_api_required_building_level($game_data, 'barracks', $unitId);
}

function army_api_build_troops_catalog(mysqli $mysqli, int $userId, array $game_data, int $barracksLvl, int $darkBarracksLvl, int $siegeLvl, int $townhallLvl, array $troopLevels, string $barracksStatus, string $darkBarracksStatus, string $siegeStatus): array {
    $catalog = [];

    // Порядок как в CoC: цепочки разлока по зданиям + полный каталог (для locked карточек)
    $ordered = [];

    $appendUnlocks = function(string $buildingId) use (&$ordered, $game_data) {
        if (empty($game_data[$buildingId]['levels']) || !is_array($game_data[$buildingId]['levels'])) return;
        foreach ($game_data[$buildingId]['levels'] as $lvl => $bdef) {
            if (!is_array($bdef)) continue;
            if (!isset($bdef['unlocks'])) continue;
            $u = $bdef['unlocks'];
            $ids = [];
            if (is_array($u)) {
                foreach ($u as $x) { $x=(string)$x; if ($x!=='' && isset($game_data[$x]) && is_array($game_data[$x])) $ids[]=$x; }
            } else {
                $x = (string)$u;
                if ($x!=='' && isset($game_data[$x]) && is_array($game_data[$x])) $ids[]=$x;
            }
            foreach ($ids as $x) {
                if (!in_array($x, $ordered, true)) $ordered[] = $x;
            }
        }
    };

    // Chain order
    $appendUnlocks('barracks');
    $appendUnlocks('dark_barracks');
    $appendUnlocks('siege_workshop');

    // Add remaining units so UI can show full catalog (locked)
    foreach ($game_data as $id => $def) {
        if (!is_string($id) || $id === '') continue;
        if (!is_array($def)) continue;
        $type = (string)($def['type'] ?? '');
        if (!in_array($type, [TYPE_TROOP, TYPE_DARK_TROOP, TYPE_SIEGE], true)) continue;
        if (in_array($id, $ordered, true)) continue;
        $ordered[] = $id;
    }
    // Townhall level comes from users.townhall_lvl (authoritative).

    foreach ($ordered as $unitId) {
        $def = $game_data[$unitId] ?? null;
        if (!is_array($def)) continue;

        $type = (string)($def['type'] ?? '');
        $buildingId = army_api_unlock_building_for_type($type);
        if ($buildingId === '') continue;

        $srcLvl = 0;
        $srcStatus = 'none';
        if ($buildingId === 'barracks') { $srcLvl = $barracksLvl; $srcStatus = $barracksStatus; }
        elseif ($buildingId === 'dark_barracks') { $srcLvl = $darkBarracksLvl; $srcStatus = $darkBarracksStatus; }
        elseif ($buildingId === 'siege_workshop') { $srcLvl = $siegeLvl; $srcStatus = $siegeStatus; }

        $reqLvl = army_api_required_building_level($game_data, $buildingId, $unitId);
        $unlocked = ($srcLvl > 0) && ($reqLvl > 0) && ($srcLvl >= $reqLvl);
        if ($reqLvl === 0) $unlocked = false;

        $level = 1;
        if (isset($troopLevels[$unitId]['level'])) $level = (int)$troopLevels[$unitId]['level'];
        if ($level < 1) $level = 1;

        $maxLevel = 1;
        if (!empty($def['levels']) && is_array($def['levels'])) {
            $keys = array_keys($def['levels']);
            if ($keys) {
                $maxLevel = (int)max($keys);
                if ($maxLevel < 1) $maxLevel = 1;
            }
        }

        // Training model: legacy queue uses training_time & cost (defined per unit)
        $trainCost = army_get_training_cost($game_data, $unitId);
        $resKey = (string)($trainCost['res_key'] ?? 'elixir');
        $cost = (int)($trainCost['cost'] ?? 0);
        if ($cost < 0) $cost = 0;
        if ($resKey === '') $resKey = 'elixir';

        $space = (int)($def['housing_space'] ?? 0);
        if ($space < 1) $space = 1;

        $trainTime = (int)($def['training_time'] ?? 0);
        if ($trainTime < 0) $trainTime = 0;

        $lockedReason = '';
        if ($srcLvl <= 0) {
            $lockedReason = 'Постройте ' . army_api_unlock_building_name($buildingId);
        } elseif ($srcStatus !== 'active') {
            $lockedReason = $unlocked ? '' : (army_api_unlock_building_name($buildingId) . ' улучшаются');
        } elseif (!$unlocked) {
            if ($reqLvl > 0) {
                $lockedReason = 'Требуется ' . army_api_unlock_building_name($buildingId) . ' ур. ' . $reqLvl;
            } else {
                $lockedReason = 'Недоступно';
            }
        }

        // TH hint for current unit level (usually aligns with building TH req)
        $thReq = 0;
        if (!empty($def['levels'][$level]['th_req'])) $thReq = (int)$def['levels'][$level]['th_req'];
        if ($thReq > 0 && $townhallLvl > 0 && $townhallLvl < $thReq) {
            if ($lockedReason === '') $lockedReason = 'Требуется Ратуша ур. ' . $thReq;
        }

        $catalog[] = [
            'id' => $unitId,
            'name' => (string)($def['name'] ?? $unitId),
            'type' => $type,
            'level' => $level,
            'max_level' => $maxLevel,
            'housing_space' => $space,
            'training_time' => $trainTime,
            'img' => army_api_troop_image_path($unitId),
            'unlocked' => $unlocked,
            'locked' => !$unlocked,
            'locked_reason' => $lockedReason,
            'train' => [
                'res' => $resKey,
                'cost' => $cost,
                'time' => $trainTime,
            ],
            'train_time' => $trainTime,
        ];
    }

    return $catalog;
}

/**
 * Каталог заклинаний для UI (Stage 3). Источник — game_data.php.
 * Логику привязки к Spell Factory/Dark Spell Factory уточним на Stage 6.
 */
function army_api_build_spells_catalog(array $game_data, int $townhallLvl, int $spellFactoryLvl, int $darkSpellFactoryLvl, string $spellFactoryStatus, string $darkSpellFactoryStatus, int $spellCap, array $levelsById): array {
    $catalog = [];

    $ordered = [];
    $appendUnlocks = function(string $buildingId) use (&$ordered, $game_data) {
        if (empty($game_data[$buildingId]['levels']) || !is_array($game_data[$buildingId]['levels'])) return;
        foreach ($game_data[$buildingId]['levels'] as $lvl => $bdef) {
            if (!is_array($bdef)) continue;
            if (!isset($bdef['unlocks'])) continue;
            $u = $bdef['unlocks'];
            $ids = [];
            if (is_array($u)) {
                foreach ($u as $x) { $x=(string)$x; if ($x!=='' && isset($game_data[$x]) && is_array($game_data[$x])) $ids[]=$x; }
            } else {
                $x=(string)$u; if ($x!=='' && isset($game_data[$x]) && is_array($game_data[$x])) $ids[]=$x;
            }
            foreach ($ids as $x) { if (!in_array($x, $ordered, true)) $ordered[]=$x; }
        }
    };

    $appendUnlocks('spell_factory');
    $appendUnlocks('dark_spell_factory');

    // Add remaining spells
    foreach ($game_data as $id => $def) {
        if (!is_string($id) || $id==='') continue;
        if (!is_array($def)) continue;
        $type = (string)($def['type'] ?? '');
        if (!in_array($type, [TYPE_SPELL, TYPE_DARK_SPELL], true)) continue;
        if (in_array($id, $ordered, true)) continue;
        $ordered[] = $id;
    }

    foreach ($ordered as $spellId) {
        $def = $game_data[$spellId] ?? null;
        if (!is_array($def)) continue;
        $type = (string)($def['type'] ?? '');
        $buildingId = army_api_unlock_building_for_type($type);
        if ($buildingId === '') continue;

        $srcLvl = 0;
        $srcStatus = 'none';
        if ($buildingId === 'spell_factory') { $srcLvl = $spellFactoryLvl; $srcStatus = $spellFactoryStatus; }
        elseif ($buildingId === 'dark_spell_factory') { $srcLvl = $darkSpellFactoryLvl; $srcStatus = $darkSpellFactoryStatus; }

        $reqLvl = army_api_required_building_level($game_data, $buildingId, $spellId);
        $unlocked = ($srcLvl > 0) && ($reqLvl > 0) && ($srcLvl >= $reqLvl);
        if ($reqLvl === 0) $unlocked = false;

        $level = 1;
        if (isset($levelsById[$spellId]['level'])) $level = (int)$levelsById[$spellId]['level'];
        if ($level < 1) $level = 1;

        $maxLevel = 1;
        if (!empty($def['levels']) && is_array($def['levels'])) {
            $keys = array_keys($def['levels']);
            if ($keys) {
                $maxLevel = (int)max($keys);
                if ($maxLevel < 1) $maxLevel = 1;
            }
        }

        $space = (int)($def['housing_space'] ?? 0);
        if ($space < 1) $space = 1;

        $brewTime = army_get_spell_brew_time($game_data, $spellId);
        $brewCost = army_get_spell_brew_cost($game_data, $spellId);
        $resKey = (string)($brewCost['res_key'] ?? 'elixir');
        $cost = (int)($brewCost['cost'] ?? 0);
        if ($cost < 0) $cost = 0;
        if ($resKey === '') $resKey = 'elixir';

        $lockedReason = '';
        if ($srcLvl <= 0) {
            $lockedReason = 'Постройте ' . army_api_unlock_building_name($buildingId);
        } elseif ($srcStatus !== 'active') {
            $lockedReason = $unlocked ? '' : (army_api_unlock_building_name($buildingId) . ' улучшается');
        } elseif (!$unlocked) {
            if ($reqLvl > 0) $lockedReason = 'Требуется ' . army_api_unlock_building_name($buildingId) . ' ур. ' . $reqLvl;
            else $lockedReason = 'Недоступно';
        }

        // TH hint for current spell level (not used for unlocking; factory TH req already controls)
        $thReq = 0;
        if (!empty($def['levels'][$level]['th_req'])) $thReq = (int)$def['levels'][$level]['th_req'];
        if ($thReq > 0 && $townhallLvl > 0 && $townhallLvl < $thReq) {
            if ($lockedReason === '') $lockedReason = 'Требуется Ратуша ур. ' . $thReq;
        }

        $catalog[] = [
            'id' => $spellId,
            'name' => (string)($def['name'] ?? $spellId),
            'type' => $type,
            'img' => army_api_spell_img($spellId),
            'level' => $level,
            'max_level' => $maxLevel,
            'housing_space' => $space,
            'brew_time' => $brewTime,
            'unlocked' => $unlocked,
            'locked' => !$unlocked,
            'locked_reason' => $lockedReason,
            'train' => [
                'res' => $resKey,
                'cost' => $cost,
            ],
        ];
    }

    return $catalog;
}


try {
    $user = getUser($mysqli);
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) army_api_error('Не авторизован', 401);

    $action = (string)($_GET['action'] ?? 'barracks_state');

    // В этом проекте TH = уровень постройки `townhall`.
    $townhallLvl = function_exists('army_get_user_townhall_level') ? army_get_user_townhall_level($mysqli, $userId) : army_get_building_level($mysqli, $userId, 'townhall');
    if ($townhallLvl <= 0) $townhallLvl = 1;

    // POST: CSRF
    army_api_require_csrf();

    // Finalize finished building timers so barracks/unlocks update without relog
    if (function_exists('finalizeCompletedBuildTimers')) finalizeCompletedBuildTimers($mysqli, $userId);

    // Keep army/lab/spells in sync each request that needs it
    if ($action === 'barracks_state' || $action === 'barracks_train' || $action === 'barracks_cancel' || $action === 'barracks_build' || $action === 'barracks_upgrade' || $action === 'barracks_speedup') {
        army_training_sync($mysqli, $userId, $game_data);
    }
    if (
        $action === 'barracks_state' ||
        $action === 'barracks_spell_add' || $action === 'barracks_spell_train' ||
        $action === 'barracks_spell_cancel' || $action === 'barracks_spell_remove' ||
        $action === 'barracks_spell_speedup'
    ) {
        $capTmp = army_get_spell_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        if ($capTmp > 0 && function_exists('army_spell_training_sync')) {
            army_spell_training_sync($mysqli, $userId, $game_data, $capTmp);
        }
    }
    if ($action === 'lab_state' || $action === 'lab_start') {
        $researchableIds = army_api_researchable_ids($game_data);
        army_research_ensure_defaults($mysqli, $userId, $researchableIds);
        army_research_sync($mysqli, $userId);
    }


    if ($action === 'unit_info') {
        $unitId = trim((string)army_api_get_get('unit_id', ''));
        if ($unitId === '') army_api_error('unit_id required', 400);

        if (!isset($game_data[$unitId]) || !is_array($game_data[$unitId])) {
            army_api_error('Неизвестный юнит', 404);
        }

        // Ensure research levels exist (used for отображения уровня)
        $researchableIds = army_api_researchable_ids($game_data);
        army_research_ensure_defaults($mysqli, $userId, $researchableIds);
        army_research_sync($mysqli, $userId);
        $levelsById = army_research_get_levels($mysqli, $userId);

        $now = time();

        // Buildings levels for unlock logic (как в barracks_state)
        $barracksLvl = army_get_building_level($mysqli, $userId, 'barracks');
        $bRow = army_get_building_row($mysqli, $userId, 'barracks');
        $bStatus = $bRow ? (string)($bRow['status'] ?? 'active') : 'none';
        $bFinish = $bRow ? (int)($bRow['finish_time'] ?? 0) : 0;
        if ($bRow && $bStatus === 'constructing' && $bFinish > $now) $barracksLvl = 0;

        $darkBarracksLvl = army_get_building_level($mysqli, $userId, 'dark_barracks');
        $dbRow = army_get_building_row($mysqli, $userId, 'dark_barracks');
        $dbStatus = $dbRow ? (string)($dbRow['status'] ?? 'active') : 'none';
        $dbFinish = $dbRow ? (int)($dbRow['finish_time'] ?? 0) : 0;
        if ($dbRow && $dbStatus === 'constructing' && $dbFinish > $now) $darkBarracksLvl = 0;

        $siegeLvl = army_get_building_level($mysqli, $userId, 'siege_workshop');
        $swRow = army_get_building_row($mysqli, $userId, 'siege_workshop');
        $swStatus = $swRow ? (string)($swRow['status'] ?? 'active') : 'none';
        $swFinish = $swRow ? (int)($swRow['finish_time'] ?? 0) : 0;
        if ($swRow && $swStatus === 'constructing' && $swFinish > $now) $siegeLvl = 0;

        $spellFactoryLvl = army_get_building_level($mysqli, $userId, 'spell_factory');
        $sfRow = army_get_building_row($mysqli, $userId, 'spell_factory');
        $sfStatus = $sfRow ? (string)($sfRow['status'] ?? 'active') : 'none';
        $sfFinish = $sfRow ? (int)($sfRow['finish_time'] ?? 0) : 0;
        if ($sfRow && $sfStatus === 'constructing' && $sfFinish > $now) $spellFactoryLvl = 0;

        $darkSpellFactoryLvl = army_get_building_level($mysqli, $userId, 'dark_spell_factory');
        $dsfRow = army_get_building_row($mysqli, $userId, 'dark_spell_factory');
        $dsfStatus = $dsfRow ? (string)($dsfRow['status'] ?? 'active') : 'none';
        $dsfFinish = $dsfRow ? (int)($dsfRow['finish_time'] ?? 0) : 0;
        if ($dsfRow && $dsfStatus === 'constructing' && $dsfFinish > $now) $darkSpellFactoryLvl = 0;

        // Build catalogs and locate unit entry (locked_reason/unlocked/level)
        $troopsCatalog = army_api_build_troops_catalog($mysqli, $userId, $game_data, $barracksLvl, $darkBarracksLvl, $siegeLvl, $townhallLvl, $levelsById, $bStatus, $dbStatus, $swStatus);
        $spellCap = army_get_spell_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        $spellsCatalog = army_api_build_spells_catalog($game_data, $townhallLvl, $spellFactoryLvl, $darkSpellFactoryLvl, $sfStatus, $dsfStatus, $spellCap, $levelsById);

        $entry = null;
        foreach ($troopsCatalog as $t) {
            if (!empty($t['id']) && (string)$t['id'] === $unitId) { $entry = $t; break; }
        }
        if ($entry === null) {
            foreach ($spellsCatalog as $s) {
                if (!empty($s['id']) && (string)$s['id'] === $unitId) { $entry = $s; break; }
            }
        }
        if ($entry === null) {
            army_api_error('Unit is not supported here', 400);
        }

        $def = $game_data[$unitId];
        $type = (string)($def['type'] ?? '');

        // Heroes are handled by Hero Hall mechanics (not Laboratory).
        if ($type === TYPE_HERO) {
            if (function_exists('army_heroes_sync')) army_heroes_sync($mysqli, $userId);
            $heroesState = function_exists('army_get_heroes_state') ? army_get_heroes_state($mysqli, $userId, $game_data, $townhallLvl) : ['heroes'=>[],'hero_hall'=>[]];
            $h = $heroesState['heroes'][$unitId] ?? null;
            if (!is_array($h)) army_api_error('Неизвестный герой', 404);

            $levels = (array)($def['levels'] ?? []);
            $maxLvl = 1;
            if (!empty($levels)) { $ks = array_keys($levels); $maxLvl = (int)max($ks); if ($maxLvl < 1) $maxLvl = 1; }

            $img = '/images/heroes/' . $unitId . '.png';
    

$isSpell = in_array($type, [TYPE_SPELL, TYPE_DARK_SPELL], true);
$buildSpellStats = function(array $base, array $lvl): array {
    $meta = [
        'cost'=>1,'time'=>1,'res_type'=>1,'lab_req'=>1,'th_req'=>1,
        'brew_cost'=>1,'brew_res_type'=>1,'brew_time'=>1,
    ];
    $out = [];
    // carry some base keys if present
    foreach (['radius','duration','freeze_time','pulses','time_between_pulses','stun_time','spell_duration','cloned_lifespan','boost_time','trigger_radius'] as $k){
        if (array_key_exists($k, $base) && $base[$k] !== null && $base[$k] !== '') $out[$k] = $base[$k];
    }
    foreach ($lvl as $k => $v){
        if (!is_string($k) || $k==='') continue;
        if (isset($meta[$k])) continue;
        if ($v === null || $v === '') continue;
        $out[$k] = $v;
    }
    // normalize aliases for frontend
    if (isset($out['instant_damage']) && !isset($out['damage'])) $out['damage'] = $out['instant_damage'];
    if (isset($out['damage_per_second']) && (int)$out['damage_per_second'] === 0) unset($out['damage_per_second']);
    if (isset($out['dps']) && (int)$out['dps'] === 0) unset($out['dps']);
    if (isset($out['dpa']) && (int)$out['dpa'] === 0) unset($out['dpa']);
    return $out;
};
        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
                'ok' => true,
                'server_time' => $now,
                'unit' => [
                    'id' => $unitId,
                    'name' => (string)($h['name'] ?? ($def['name'] ?? $unitId)),
                    'type' => $type,
                    'img' => $img,
                    'level' => (int)($h['level'] ?? 0),
                    'max_level' => $maxLvl,
                    'unlocked' => ((int)($h['unlocked'] ?? 0) > 0),
                    'locked' => ((int)($h['unlocked'] ?? 0) <= 0),
                    'locked_reason' => (string)($h['locked_reason'] ?? ''),
                ],
                'train' => ['res' => '', 'cost' => 0],
                'requirements' => [
                    'building_id' => 'hero_hall',
                    'building_name' => army_api_unlock_building_name('hero_hall'),
                    'building_level' => (int)($h['unlock_hh'] ?? 0),
                    'townhall_level' => (int)($h['unlock_th'] ?? 0),
                ],
'current' => ($isSpell ? array_merge([
    'th_req' => isset($curDef['th_req']) ? (int)$curDef['th_req'] : null,
], $buildSpellStats($def, $curDef)) : [
    'dps' => (int)($pickVal($curDef, $def, ['dps','damage_per_second']) ?? 0),
    'dpa' => (int)($pickVal($curDef, $def, ['damage_per_attack']) ?? 0),
    'th_req' => isset($curDef['th_req']) ? (int)$curDef['th_req'] : null,
    'hp' => $pickVal($curDef, $def, ['hp','health']),
    'damage' => $pickVal($curDef, $def, ['damage']),
    'attack_speed' => $pickVal($curDef, $def, ['attack_speed','hit_speed']),
    'speed' => $pickVal($curDef, $def, ['speed','move_speed']),
    'range' => $pickVal($curDef, $def, ['range','attack_range']),
    'damage_type' => $pickVal($curDef, $def, ['damage_type','attack_type']),
    'targets' => $pickVal($curDef, $def, ['targets','target']),
    'fav_target' => $pickVal($curDef, $def, ['fav_target','favorite_target','preferred_target']),
    'healing_per_second' => $pickVal($curDef, $def, ['healing_per_second','healing_per_sec','heal_per_sec','heal_per_second','heal_ps']),
    'healing_per_pulse' => $pickVal($curDef, $def, ['healing_per_pulse','heal_per_pulse']),
    'hero_healing_per_second' => $pickVal($curDef, $def, ['hero_healing_per_second','hero_heal_per_sec','hero_heal_ps']),
    'hero_healing_per_pulse' => $pickVal($curDef, $def, ['hero_healing_per_pulse','hero_heal_per_pulse']),
]),
'next' => ($nextLvl > 0 ? ($isSpell ? array_merge([
    'level' => $nextLvl,
    'cost' => $nextCost,
    'time' => $nextTime,
    'res' => $nextRes,
    'th_req' => $nextThReq,
], $buildSpellStats($def, $nextDef)) : [
    'level' => $nextLvl,
    'cost' => $nextCost,
    'time' => $nextTime,
    'res' => $nextRes,
    'th_req' => $nextThReq,
    'dps' => (int)($pickVal($nextDef, $def, ['dps','damage_per_second']) ?? 0),
    'dpa' => (int)($pickVal($nextDef, $def, ['damage_per_attack']) ?? 0),
]) : null),

                'upgrade' => [
                    'can' => (bool)($h['can_upgrade'] ?? false),
                    'reason' => (string)($h['locked_reason'] ?? ''),
                    'checks' => [],
                ],
                'research' => (
                $activeResearch ? [
                    'tech_id' => (string)($activeResearch['tech_id'] ?? ''),
                    'finish_time' => (int)($activeResearch['finish_time'] ?? 0),
                    'time_left' => (int)($activeResearch['time_left'] ?? 0),
                    'level' => (int)($activeResearch['level'] ?? 0),
                ] : null
            ),
            'lab' => [
                    'level' => 0,
                    'busy' => false,
                    'can_upgrade' => (bool)($h['can_upgrade'] ?? false),
                    'upgrade_reason' => (string)($h['locked_reason'] ?? ''),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }


        // Unlock requirements (which building + required level)
        $reqBuildingId = army_api_unlock_building_for_type($type);
        $reqBuildingLvl = 0;
        if ($reqBuildingId !== '') {
            $reqBuildingLvl = army_api_required_building_level($game_data, $reqBuildingId, $unitId);
        }

        $curLvl = (int)($entry['level'] ?? 1);
        if ($curLvl < 1) $curLvl = 1;
        $maxLvl = (int)($entry['max_level'] ?? 1);
        if ($maxLvl < 1) $maxLvl = 1;

        $nextLvl = ($curLvl < $maxLvl) ? ($curLvl + 1) : 0;

        $curDef = (!empty($def['levels'][$curLvl]) && is_array($def['levels'][$curLvl])) ? $def['levels'][$curLvl] : [];
        $nextDef = ($nextLvl > 0 && !empty($def['levels'][$nextLvl]) && is_array($def['levels'][$nextLvl])) ? $def['levels'][$nextLvl] : [];

        $pickVal = function(array $level, array $base, array $keys){
            foreach ($keys as $k) {
                if (array_key_exists($k, $level) && $level[$k] !== null && $level[$k] !== '') return $level[$k];
                if (array_key_exists($k, $base) && $base[$k] !== null && $base[$k] !== '') return $base[$k];
            }
            return null;
        };

        $space = (int)($def['housing_space'] ?? ($entry['housing_space'] ?? 1));
        if ($space < 1) $space = 1;

        $trainingTime = (int)($def['training_time'] ?? 0);
        if ($trainingTime < 0) $trainingTime = 0;
        $brewTime = (int)($entry['brew_time'] ?? 0);
        if ($brewTime < 0) $brewTime = 0;

        // Lab availability for upgrade button
        $labLvl = army_get_building_level($mysqli, $userId, 'laboratory');
        $labRow = army_get_building_row($mysqli, $userId, 'laboratory');
        $labStatus = $labRow ? (string)($labRow['status'] ?? 'active') : ($labLvl > 0 ? 'active' : 'none');
        $labFinish = $labRow ? (int)($labRow['finish_time'] ?? 0) : 0;
        $labBusy = ($labLvl > 0 && $labStatus !== 'active' && $labFinish > $now);
        $activeResearch = army_research_get_active($mysqli, $userId);
        if ($activeResearch) {
            $activeResearch['finish_time'] = (int)($activeResearch['finish_time'] ?? 0);
            $activeResearch['time_left'] = max(0, (int)$activeResearch['finish_time'] - $now);
            $tidTmp = (string)($activeResearch['tech_id'] ?? '');
            if ($tidTmp !== '' && isset($game_data[$tidTmp]) && is_array($game_data[$tidTmp])) {
                $activeResearch['tech_name'] = (string)($game_data[$tidTmp]['name'] ?? $tidTmp);
            } else {
                $activeResearch['tech_name'] = $tidTmp;
            }

        }
        $isResearching = ($activeResearch && (string)($activeResearch['tech_id'] ?? '') !== '' ? true : false);

        $canUpgrade = false;
        $upgradeReason = '';
        $nextCost = 0;
        $nextTime = 0;
        $nextRes = '';
        $nextThReq = 0;

        if ($nextLvl > 0 && !empty($nextDef) && is_array($nextDef)) {
            $nextCost = (int)($nextDef['cost'] ?? 0);
            $nextTime = (int)($nextDef['time'] ?? 0);
            $nextRes = army_api_resKey((string)($nextDef['res_type'] ?? ''));
            $nextThReq = (int)($nextDef['th_req'] ?? 0);
        }

        if (!empty($entry['locked'])) {
            $upgradeReason = (string)($entry['locked_reason'] ?? '');
        } elseif ($labLvl <= 0) {
            $upgradeReason = 'Постройте Лабораторию.';
        } elseif ($labBusy) {
            $upgradeReason = 'Лаборатория занята (строится/улучшается).';
        } elseif ($nextLvl <= 0) {
            $upgradeReason = 'Максимальный уровень.';
        } elseif ($isResearching) {
            $upgradeReason = 'Лаборатория занята исследованием.';
        } elseif ($nextThReq > 0 && $townhallLvl < $nextThReq) {
            $upgradeReason = 'Требуется Ратуша ' . $nextThReq . '.';
        } else {
            $canUpgrade = true;
        }

        // What exactly is missing for upgrade (for red highlighting on UI)
        $upgradeChecks = [];
        // Lab existence
        $upgradeChecks[] = [
            'key' => 'lab',
            'label' => '🔬 Лаборатория',
            'ok' => ($labLvl > 0),
            'text' => ($labLvl > 0 ? ('ур. '.$labLvl) : 'не построена'),
        ];
        // Lab availability
        $upgradeChecks[] = [
            'key' => 'lab_free',
            'label' => '🧪 Свободна',
            'ok' => (!$labBusy && !$isResearching),
            'text' => ((!$labBusy && !$isResearching) ? 'да' : 'занята'),
        ];
        // Townhall
        if ($nextThReq > 0) {
            $upgradeChecks[] = [
                'key' => 'th',
                'label' => '🏰 Ратуша',
                'ok' => ($townhallLvl >= $nextThReq),
                'have' => $townhallLvl,
                'need' => $nextThReq,
            ];
        }
        // Resources
        if ($nextRes !== '' && $nextCost > 0) {
            $have = 0;
            if ($nextRes === 'gold') $have = (int)($user['gold'] ?? 0);
            if ($nextRes === 'elixir') $have = (int)($user['elixir'] ?? 0);
            if ($nextRes === 'dark_elixir') $have = (int)($user['dark_elixir'] ?? 0);
            if ($nextRes === 'gems') $have = (int)($user['gems'] ?? 0);
            $upgradeChecks[] = [
                'key' => 'res',
                'label' => '💰 Ресурсы',
                'ok' => ($have >= $nextCost),
                'have' => $have,
                'need' => $nextCost,
                'res' => $nextRes,
            ];
        }

        $img = '';
        if (!empty($entry['img'])) $img = (string)$entry['img'];
        if ($img === '' && in_array($type, [TYPE_SPELL, TYPE_DARK_SPELL], true)) {
            // spells have fixed folder
            $img = army_api_spell_img($unitId);
        }

        // --- Spells need different stat payload (they are not troops).
        $isSpell = in_array($type, [TYPE_SPELL, TYPE_DARK_SPELL], true);
        $buildSpellStats = function(array $base, array $lvl): array {
            // Keys that are meta and should NOT be treated as numeric stats.
            $meta = [
                'cost'=>1,'time'=>1,'res_type'=>1,'lab_req'=>1,'th_req'=>1,
                'brew_cost'=>1,'brew_res_type'=>1,'brew_time'=>1,
            ];
            $out = [];
            // carry some base keys if present
            foreach (['radius','duration','freeze_time','pulses','time_between_pulses','stun_time','boost_time','trigger_radius'] as $k){
                if (array_key_exists($k, $base) && $base[$k] !== null && $base[$k] !== '') $out[$k] = $base[$k];
            }
            foreach ($lvl as $k => $v){
                if (!is_string($k) || $k==='') continue;
                if (isset($meta[$k])) continue;
                if ($v === null || $v === '') continue;
                $out[$k] = $v;
            }
            // normalize aliases for frontend
            if (isset($out['instant_damage']) && !isset($out['damage'])) $out['damage'] = $out['instant_damage'];
            if (isset($out['speed_increase']) && !isset($out['speed_increase_pct'])) $out['speed_increase_pct'] = $out['speed_increase'];
            if (isset($out['damage_per_second']) && (int)$out['damage_per_second'] === 0) unset($out['damage_per_second']);
            if (isset($out['dps']) && (int)$out['dps'] === 0) unset($out['dps']);
            if (isset($out['dpa']) && (int)$out['dpa'] === 0) unset($out['dpa']);
            return $out;
        };

        // Spell meta that should be visible in the modal like in CoC.
        $spellMeta = [];
        if ($isSpell) {
            if (isset($def['brew_res_type'])) $spellMeta['resource_type'] = (string)$def['brew_res_type'];
            if (isset($def['unlock_th'])) $spellMeta['unlock_th'] = (int)$def['unlock_th'];
            if (isset($def['targets'])) $spellMeta['targets'] = (string)$def['targets'];
            if (isset($def['usage'])) $spellMeta['usage'] = (string)$def['usage'];
            if (isset($def['effect'])) $spellMeta['effect'] = (string)$def['effect'];
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'server_time' => $now,
            'unit' => [
                'id' => $unitId,
                'name' => (string)($entry['name'] ?? $unitId),
                'type' => $type,
                'img' => $img,
                'description' => (string)($def['desc'] ?? $def['description'] ?? ''),
                'level' => $curLvl,
                'max_level' => $maxLvl,
                'housing_space' => $space,
                'training_time' => $trainingTime,
                'brew_time' => $brewTime,
                'unlocked' => !empty($entry['unlocked']),
                'locked' => !empty($entry['locked']),
                'locked_reason' => (string)($entry['locked_reason'] ?? ''),
            ],
            // Training price (for UI tile)
            'train' => [
                'res' => isset($entry['train']['res']) ? (string)$entry['train']['res'] : '',
                'cost' => isset($entry['train']['cost']) ? (int)$entry['train']['cost'] : 0,
            ],
            // Requirements to unlock
            'requirements' => [
                'building_id' => $reqBuildingId,
                'building_name' => army_api_unlock_building_name($reqBuildingId),
                'building_level' => $reqBuildingLvl,
            ],
            'current' => (
                $isSpell ? array_merge(
                    [
                        'space' => $space,
                        'brew_time' => $brewTime,
                        'brew_cost' => (int)($def['brew_cost'] ?? ($entry['train']['cost'] ?? 0)),
                        'brew_res_type' => (string)($def['brew_res_type'] ?? ($entry['train']['res'] ?? '')),
                    ],
                    $spellMeta,
                    $buildSpellStats($def, $curDef)
                ) : [
                    'dps' => (int)($pickVal($curDef, $def, ['dps','damage_per_second']) ?? 0),
                    'dpa' => (int)($pickVal($curDef, $def, ['damage_per_attack']) ?? 0),
                    'th_req' => isset($curDef['th_req']) ? (int)$curDef['th_req'] : null,
                    'hp' => $pickVal($curDef, $def, ['hp','health']),
                    'damage' => $pickVal($curDef, $def, ['damage']),
                    'attack_speed' => $pickVal($curDef, $def, ['attack_speed','hit_speed']),
                    'speed' => $pickVal($curDef, $def, ['speed','move_speed']),
                    'range' => $pickVal($curDef, $def, ['range','attack_range']),
                    'damage_type' => $pickVal($curDef, $def, ['damage_type','attack_type']),
                    'targets' => $pickVal($curDef, $def, ['targets','target']),
                    'fav_target' => $pickVal($curDef, $def, ['fav_target','favorite_target','preferred_target']),
                    'healing_per_second' => $pickVal($curDef, $def, ['healing_per_second','healing_per_sec','heal_per_sec','heal_per_second','heal_ps']),
                    'healing_per_pulse' => $pickVal($curDef, $def, ['healing_per_pulse','heal_per_pulse']),
                    'hero_healing_per_second' => $pickVal($curDef, $def, ['hero_healing_per_second','hero_heal_per_sec','hero_heal_ps']),
                    'hero_healing_per_pulse' => $pickVal($curDef, $def, ['hero_healing_per_pulse','hero_heal_per_pulse']),
                ]
            ),
            'next' => ($nextLvl > 0 ? (
                $isSpell ? array_merge(
                    [
                        'level' => $nextLvl,
                        'cost' => $nextCost,
                        'time' => $nextTime,
                        'res' => $nextRes,
                        'th_req' => $nextThReq,
                    ],
                    $spellMeta,
                    $buildSpellStats($def, $nextDef)
                ) : [
                    'level' => $nextLvl,
                    'cost' => $nextCost,
                    'time' => $nextTime,
                    'res' => $nextRes,
                    'th_req' => $nextThReq,
                    'dps' => (int)($pickVal($nextDef, $def, ['dps','damage_per_second']) ?? 0),
                    'dpa' => (int)($pickVal($nextDef, $def, ['damage_per_attack']) ?? 0),
                    'hp' => $pickVal($nextDef, $def, ['hp','health']),
                    'damage' => $pickVal($nextDef, $def, ['damage']),
                    'attack_speed' => $pickVal($nextDef, $def, ['attack_speed','hit_speed']),
                    'speed' => $pickVal($nextDef, $def, ['speed','move_speed']),
                    'range' => $pickVal($nextDef, $def, ['range','attack_range']),
                    'damage_type' => $pickVal($nextDef, $def, ['damage_type','attack_type']),
                    'targets' => $pickVal($nextDef, $def, ['targets','target']),
                    'fav_target' => $pickVal($nextDef, $def, ['fav_target','favorite_target','preferred_target']),
                    'healing_per_second' => $pickVal($nextDef, $def, ['healing_per_second','healing_per_sec','heal_per_sec','heal_per_second','heal_ps']),
                    'healing_per_pulse' => $pickVal($nextDef, $def, ['healing_per_pulse','heal_per_pulse']),
                    'hero_healing_per_second' => $pickVal($nextDef, $def, ['hero_healing_per_second','hero_heal_per_sec','hero_heal_ps']),
                    'hero_healing_per_pulse' => $pickVal($nextDef, $def, ['hero_healing_per_pulse','hero_heal_per_pulse']),

                ]
            ) : null),
            'upgrade' => [
                'can' => $canUpgrade,
                'reason' => $upgradeReason,
                'checks' => $upgradeChecks,
            ],
            'research' => (
                $activeResearch ? [
                    'tech_id' => (string)($activeResearch['tech_id'] ?? ''),
                    'tech_name' => (string)($activeResearch['tech_name'] ?? ($game_data[(string)($activeResearch['tech_id'] ?? '')]['name'] ?? ($activeResearch['tech_id'] ?? ''))),
                    'finish_time' => (int)($activeResearch['finish_time'] ?? 0),
                    'time_left' => (int)($activeResearch['time_left'] ?? 0),
                    'level' => (int)($activeResearch['level'] ?? 0),
                ] : null
            ),
            'lab' => [
                'level' => $labLvl,
                'busy' => $isResearching,
                'can_upgrade' => $canUpgrade,
                'upgrade_reason' => $upgradeReason,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_state') {
        // ВАЖНО:
        // Без синхронизации очередь может зависать на "0с" (finish_time уже прошёл),
        // но статус останется training пока игрок не обновит страницу.
        // Синхронизируем перед чтением очереди/армии.
        if (function_exists('army_training_sync')) {
            try { army_training_sync($mysqli, $userId, $game_data); } catch (Throwable $e) { /* ignore */ }
        }
        if (function_exists('army_spell_training_sync')) {
            try {
                $townhallLvlTmp = $townhallLvl;
                $spellCapTmp = army_get_spell_capacity_total($mysqli, $userId, $game_data, $townhallLvlTmp);
                if ($spellCapTmp > 0) army_spell_training_sync($mysqli, $userId, $game_data, $spellCapTmp);
            } catch (Throwable $e) { /* ignore */ }
        }

        // Уровень казарм для показа/разлока. Если казарма прямо сейчас строится/улучшается,
        // стараемся вернуть "текущий" уровень (без преждевременного разлока войск).
        $barracksLvl = army_get_building_level($mysqli, $userId, 'barracks');
        $bRow = army_get_building_row($mysqli, $userId, 'barracks');
        $bStatus = $bRow ? (string)($bRow['status'] ?? 'active') : 'none';
        $bFinish = $bRow ? (int)($bRow['finish_time'] ?? 0) : 0;
        if ($bRow && $bStatus === 'constructing' && $bFinish > time()) {
            $barracksLvl = 0;
        }

        // Другие армейские здания (для корректных разлоков как в CoC)
        $darkBarracksLvl = army_get_building_level($mysqli, $userId, 'dark_barracks');
        $dbRow = army_get_building_row($mysqli, $userId, 'dark_barracks');
        $dbStatus = $dbRow ? (string)($dbRow['status'] ?? 'active') : 'none';
        $dbFinish = $dbRow ? (int)($dbRow['finish_time'] ?? 0) : 0;
        if ($dbRow && $dbStatus === 'constructing' && $dbFinish > time()) {
            $darkBarracksLvl = 0;
        }

        $siegeLvl = army_get_building_level($mysqli, $userId, 'siege_workshop');
        $swRow = army_get_building_row($mysqli, $userId, 'siege_workshop');
        $swStatus = $swRow ? (string)($swRow['status'] ?? 'active') : 'none';
        $swFinish = $swRow ? (int)($swRow['finish_time'] ?? 0) : 0;
        if ($swRow && $swStatus === 'constructing' && $swFinish > time()) {
            $siegeLvl = 0;
        }

        $spellFactoryLvl = army_get_building_level($mysqli, $userId, 'spell_factory');
        $sfRow = army_get_building_row($mysqli, $userId, 'spell_factory');
        $sfStatus = $sfRow ? (string)($sfRow['status'] ?? 'active') : 'none';
        $sfFinish = $sfRow ? (int)($sfRow['finish_time'] ?? 0) : 0;
        if ($sfRow && $sfStatus === 'constructing' && $sfFinish > time()) {
            $spellFactoryLvl = 0;
        }

        $darkSpellFactoryLvl = army_get_building_level($mysqli, $userId, 'dark_spell_factory');
        $dsfRow = army_get_building_row($mysqli, $userId, 'dark_spell_factory');
        $dsfStatus = $dsfRow ? (string)($dsfRow['status'] ?? 'active') : 'none';
        $dsfFinish = $dsfRow ? (int)($dsfRow['finish_time'] ?? 0) : 0;
        if ($dsfRow && $dsfStatus === 'constructing' && $dsfFinish > time()) {
            $darkSpellFactoryLvl = 0;
        }
        $campCap = army_get_camp_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        $campUsed = army_get_army_used($mysqli, $userId, $game_data);

        $army = army_get_player_army($mysqli, $userId);

        $queue = army_queue_get($mysqli, $userId);
        $now = time();
        foreach ($queue as &$q) {
            $q['id'] = (int)($q['id'] ?? 0);
            $q['qty'] = (int)($q['qty'] ?? 1);
            $q['start_time'] = (int)($q['start_time'] ?? 0);
            $q['finish_time'] = (int)($q['finish_time'] ?? 0);
            $q['unit_level'] = (int)($q['unit_level'] ?? 1);
            $q['time_left'] = max(0, $q['finish_time'] - $now);
        }
        unset($q);

        // $townhallLvl already resolved at the start of request.

        $unlocked = army_api_list_unlocked_troops($game_data, $barracksLvl);

        // Уровни войск (для фронта, чтобы можно было подставлять вместо "фейковых" чисел)
        $troopLevels = army_research_get_levels($mysqli, $userId);

        $troopsCatalog = army_api_build_troops_catalog($mysqli, $userId, $game_data, $barracksLvl, $darkBarracksLvl, $siegeLvl, $townhallLvl, $troopLevels, $bStatus, $dbStatus, $swStatus);

        // Stage 3.1: spells (catalog + composition + capacity)
        $spellCap = army_get_spell_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        $spellUsed = army_get_spells_used($mysqli, $userId, $game_data);
        $spellQueueUsed = ($spellCap > 0) ? army_spell_queue_get_space_used($mysqli, $userId, $game_data) : 0;
        $spellArmy = army_get_player_spells($mysqli, $userId);
        $spellQueue = ($spellCap > 0) ? army_spell_queue_get($mysqli, $userId) : [];
        if ($spellQueue) {
            foreach ($spellQueue as &$sq) {
                $sq['id'] = (int)($sq['id'] ?? 0);
                $sq['qty'] = (int)($sq['qty'] ?? 1);
                $sq['start_time'] = (int)($sq['start_time'] ?? 0);
                $sq['finish_time'] = (int)($sq['finish_time'] ?? 0);
                $sq['spell_level'] = (int)($sq['spell_level'] ?? 1);
                $sq['time_left'] = max(0, $sq['finish_time'] - $now);
            }
            unset($sq);
        }
        $spellsCatalog = army_api_build_spells_catalog($game_data, $townhallLvl, $spellFactoryLvl, $darkSpellFactoryLvl, $sfStatus, $dsfStatus, $spellCap, $troopLevels);

        // Stage 10.1: heroes (Hero Hall + heroes)
        army_heroes_sync($mysqli, $userId);
        $heroesState = army_get_heroes_state($mysqli, $userId, $game_data, $townhallLvl);

        $buildingsState = army_api_get_buildings_state($mysqli, $userId, $game_data, $townhallLvl);


        // Full troop catalog for UI (no hardcoded lists on frontend)
        // Ресурсы пользователя (для списания/показа)
        $uRes = [
            'gold' => (int)($user['gold'] ?? 0),
            'elixir' => (int)($user['elixir'] ?? 0),
            'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
            'gems' => (int)($user['gems'] ?? 0),
        ];

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'server_time' => $now,
            'barracks_level' => $barracksLvl,
            'barracks_status' => $bStatus,
            'barracks_finish_time' => $bFinish,
            'unlocked_troops' => $unlocked,
            'troops' => $troopsCatalog,
            'troop_levels' => $troopLevels,
            'user' => $uRes,
            'camp' => [
                'used' => $campUsed,
                'cap' => $campCap,
            ],
            'army' => $army,
            'queue' => $queue,

            // spells
            'spell' => [
                // В UX очереди (legacy): очередь резервирует место.
                'used' => ($spellUsed + $spellQueueUsed),
                'stored' => $spellUsed,
                'queued' => $spellQueueUsed,
                'cap' => $spellCap,
            ],
            'spells_army' => $spellArmy,
            'spells' => $spellsCatalog,
            'spell_queue' => $spellQueue,

            // heroes
            'hero_hall' => $heroesState['hero_hall'],
            'heroes' => $heroesState['heroes'],
            'texts' => $coc_texts,

            // buildings (tab)
            'buildings' => $buildingsState,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------- Stage 10.1: Heroes API --------------------

    if ($action === 'heroes_state') {
        // $townhallLvl already resolved at the start of request.
        army_heroes_sync($mysqli, $userId);
        $heroesState = army_get_heroes_state($mysqli, $userId, $game_data, $townhallLvl);
        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'server_time' => time(),
            'hero_hall' => $heroesState['hero_hall'],
            'heroes' => $heroesState['heroes'],
            'texts' => $coc_texts,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'hero_unlock') {
        $heroId = trim((string)army_api_get_post('hero_id', ''));
        if ($heroId === '') army_api_error('hero_id required', 400);
        if (!isset($game_data[$heroId]) || (string)($game_data[$heroId]['type'] ?? '') !== TYPE_HERO) {
            army_api_error('Unknown hero', 400);
        }

        // $townhallLvl already resolved at the start of request.

        // Hero Hall must be built and ready
        try {
            $hhRow = army_require_building_ready($mysqli, $userId, 'hero_hall', 'Зал героев');
        } catch (Throwable $e) {
            army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
        $hhLvl = (int)($hhRow['level'] ?? 0);

        $unlockTH = (int)($game_data[$heroId]['unlock_th'] ?? 0);
        $unlockHH = (int)($game_data[$heroId]['unlock_hh'] ?? 0);
        if ($unlockTH > 0 && $townhallLvl < $unlockTH) {
            army_api_error('Требуется Ратуша ' . $unlockTH . '.', 400);
        }
        if ($unlockHH > 0 && $hhLvl < $unlockHH) {
            army_api_error('Требуется Зал героев ' . $unlockHH . '.', 400);
        }

        // If already unlocked, just return ok.
        $existing = army_get_player_hero_row($mysqli, $userId, $heroId);
        if ($existing && (int)($existing['unlocked'] ?? 0) > 0) {
            if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'already' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Unlock at level 1 (no cost here; in CoC it's instant after unlock condition)
        if (!army_upsert_player_hero($mysqli, $userId, $heroId, 1, 1, 0, 0, $existing ? (string)($existing['equipment_json'] ?? '{}') : '{}')) {
            army_api_error('DB error (unlock hero)', 500);
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'hero_id' => $heroId, 'level' => 1], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'hero_upgrade') {
        $heroId = trim((string)army_api_get_post('hero_id', ''));
        if ($heroId === '') army_api_error('hero_id required', 400);
        if (!isset($game_data[$heroId]) || (string)($game_data[$heroId]['type'] ?? '') !== TYPE_HERO) {
            army_api_error('Unknown hero', 400);
        }

        // $townhallLvl already resolved at the start of request.

        // Hero Hall must be built and ready
        try {
            $hhRow = army_require_building_ready($mysqli, $userId, 'hero_hall', 'Зал героев');
        } catch (Throwable $e) {
            army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
        $hhLvl = (int)($hhRow['level'] ?? 0);

        army_heroes_sync($mysqli, $userId);

        $heroRow = army_get_player_hero_row($mysqli, $userId, $heroId);
        if (!$heroRow || (int)($heroRow['unlocked'] ?? 0) <= 0) {
            army_api_error('Герой не разблокирован.', 400);
        }

        $curLvl = (int)($heroRow['level'] ?? 0);
        $upUntil = (int)($heroRow['upgrading_until'] ?? 0);
        if ($upUntil > time()) {
            army_api_error('Герой уже улучшается.', 400);
        }

        $levels = (array)($game_data[$heroId]['levels'] ?? []);
        $nextLvl = $curLvl + 1;
        if (!isset($levels[$nextLvl])) {
            army_api_error('Достигнут максимальный уровень (нет данных уровня).', 400);
        }
        $ldata = $levels[$nextLvl];
        $hhReq = (int)($ldata['hh_req'] ?? 0);
        if ($hhReq > 0 && $hhLvl < $hhReq) {
            army_api_error('Требуется Зал героев ' . $hhReq . ' для улучшения.', 400);
        }

        $cost = (int)($ldata['cost'] ?? 0);
        $resType = (string)($ldata['res_type'] ?? '');
        $timeSec = (int)($ldata['time'] ?? 0);
        if ($timeSec < 0) $timeSec = 0;

        // Deduct resources + start upgrade
        $mysqli->begin_transaction();
        try {
            $stmtLock = $mysqli->prepare("SELECT gold, elixir, dark_elixir, gems FROM users WHERE id=? FOR UPDATE");
            if (!$stmtLock) throw new RuntimeException('DB: lock user failed', 500);
            $stmtLock->bind_param('i', $userId);
            $stmtLock->execute();
            $u = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            $gold = (int)($u['gold'] ?? 0);
            $elixir = (int)($u['elixir'] ?? 0);
            $dark = (int)($u['dark_elixir'] ?? 0);

            if ($cost > 0) {
                if ($resType === RES_GOLD) {
                    if ($gold < $cost) throw new GameActionException('Недостаточно золота.', 400);
                    $gold -= $cost;
                } elseif ($resType === RES_ELIXIR) {
                    if ($elixir < $cost) throw new GameActionException('Недостаточно эликсира.', 400);
                    $elixir -= $cost;
                } elseif ($resType === RES_DARK) {
                    if ($dark < $cost) throw new GameActionException('Недостаточно чёрного эликсира.', 400);
                    $dark -= $cost;
                }
            }

            $stmtUpdU = $mysqli->prepare("UPDATE users SET gold=?, elixir=?, dark_elixir=? WHERE id=?");
            if (!$stmtUpdU) throw new RuntimeException('DB: update user resources failed', 500);
            $stmtUpdU->bind_param('iiii', $gold, $elixir, $dark, $userId);
            $stmtUpdU->execute();
            $stmtUpdU->close();

            $finish = time() + $timeSec;
            $stmtUpdH = $mysqli->prepare("UPDATE player_heroes SET upgrading_until=?, upgrading_to_level=? WHERE user_id=? AND hero_id=?");
            if (!$stmtUpdH) throw new RuntimeException('DB: update hero failed', 500);
            $stmtUpdH->bind_param('iiis', $finish, $nextLvl, $userId, $heroId);
            $stmtUpdH->execute();
            $stmtUpdH->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), 400);
            }
            throw $e;
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'hero_id' => $heroId,
            'from_level' => $curLvl,
            'to_level' => $nextLvl,
            'finish_time' => time() + $timeSec,
            'time' => $timeSec,
            'res_type' => $resType,
            'cost' => $cost,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'hero_speedup') {
        $heroId = trim((string)army_api_get_post('hero_id', ''));
        if ($heroId === '') army_api_error('hero_id required', 400);
        if (!isset($game_data[$heroId]) || (string)($game_data[$heroId]['type'] ?? '') !== TYPE_HERO) {
            army_api_error('Unknown hero', 400);
        }
        $dryRun = (int)army_api_get_post('dry_run', 0);


        // Hero Hall must be built and ready
        try {
            army_require_building_ready($mysqli, $userId, 'hero_hall', 'Зал героев');
        } catch (Throwable $e) {
            army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }

        army_heroes_sync($mysqli, $userId);

        $heroRow = army_get_player_hero_row($mysqli, $userId, $heroId);
        if (!$heroRow || (int)($heroRow['unlocked'] ?? 0) <= 0) {
            army_api_error('Герой не разблокирован.', 400);
        }

        $now = time();
        $upUntil = (int)($heroRow['upgrading_until'] ?? 0);
        $upTo = (int)($heroRow['upgrading_to_level'] ?? 0);
        if ($upUntil <= $now || $upTo <= 0) {
            army_api_error('Герой сейчас не улучшается.', 400);
        }

        $left = max(0, $upUntil - $now);
        $gemCost = army_gem_cost_for_seconds($left);

        $mysqli->begin_transaction();
        try {
            $stmtLock = $mysqli->prepare("SELECT gems FROM users WHERE id=? FOR UPDATE");
            if (!$stmtLock) throw new RuntimeException('DB: lock user failed', 500);
            $stmtLock->bind_param('i', $userId);
            $stmtLock->execute();
            $uRow = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            $have = (int)($uRow['gems'] ?? 0);
            if ($gemCost > 0 && $have < $gemCost) {
                throwNotEnoughResources('gems', $gemCost, $have, 'ускорение');
            }
            $newVal = $have - $gemCost;

            $stmtU = $mysqli->prepare("UPDATE users SET gems=? WHERE id=?");
            if (!$stmtU) throw new RuntimeException('DB: update gems failed', 500);
            $stmtU->bind_param('ii', $newVal, $userId);
            $stmtU->execute();
            $stmtU->close();

            // Mark as finished now
            $stmtH = $mysqli->prepare("UPDATE player_heroes SET upgrading_until=? WHERE user_id=? AND hero_id=?");
            if (!$stmtH) throw new RuntimeException('DB: update hero failed', 500);
            $stmtH->bind_param('iis', $now, $userId, $heroId);
            $stmtH->execute();
            $stmtH->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), 400);
            }
            throw $e;
        }

        // Apply completion
        army_heroes_sync($mysqli, $userId);
        $heroRow2 = army_get_player_hero_row($mysqli, $userId, $heroId);

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'hero_id' => $heroId,
            'cost_gems' => $gemCost,
            'gems' => (int)($newVal ?? 0),
            'level' => (int)($heroRow2['level'] ?? 0),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'hero_equip') {
        $heroId = trim((string)army_api_get_post('hero_id', ''));
        $slot = trim((string)army_api_get_post('slot', ''));
        $item = trim((string)army_api_get_post('item', ''));
        if ($heroId === '' || $slot === '') army_api_error('hero_id and slot required', 400);
        if (!isset($game_data[$heroId]) || (string)($game_data[$heroId]['type'] ?? '') !== TYPE_HERO) {
            army_api_error('Unknown hero', 400);
        }

        $heroRow = army_get_player_hero_row($mysqli, $userId, $heroId);
        if (!$heroRow || (int)($heroRow['unlocked'] ?? 0) <= 0) {
            army_api_error('Герой не разблокирован.', 400);
        }

        $equip = (string)($heroRow['equipment_json'] ?? '{}');
        $data = json_decode($equip, true);
        if (!is_array($data)) $data = [];
        if ($item === '') {
            unset($data[$slot]);
        } else {
            $data[$slot] = $item;
        }
        $newJson = json_encode($data, JSON_UNESCAPED_UNICODE);
        if ($newJson === false) $newJson = '{}';

        $stmt = $mysqli->prepare("UPDATE player_heroes SET equipment_json=? WHERE user_id=? AND hero_id=?");
        if (!$stmt) army_api_error('DB: equipment update failed', 500);
        $stmt->bind_param('sis', $newJson, $userId, $heroId);
        $stmt->execute();
        $stmt->close();

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'hero_id' => $heroId, 'equipment_json' => $newJson], JSON_UNESCAPED_UNICODE);
        exit;
    }

    

    // -------------------- Buildings (Barracks/Lab): info + build + upgrade --------------------

    if ($action === 'building_info') {
        // Support both legacy param names:
        //  - building_id (new)
        //  - building     (older JS)
        $bid = trim((string)army_api_get_get('building_id', ''));
        if ($bid === '') {
            $bid = trim((string)army_api_get_get('id', ''));
        }
        if ($bid === '') {
            $bid = trim((string)army_api_get_get('building', ''));
        }
        if ($bid === '') army_api_error('Не указан building_id', 400);
        if (!in_array($bid, army_api_allowed_buildings(), true)) army_api_error('Неизвестная постройка', 400);
        if (!isset($game_data[$bid]) || !is_array($game_data[$bid]) || empty($game_data[$bid]['levels'])) {
            army_api_error('No building data', 400);
        }

        $now = time();
        // $townhallLvl already resolved at the start of request.

        // finalize any completed timers
        if (function_exists('finalizeCompletedBuildTimers')) {
            finalizeCompletedBuildTimers($mysqli, $userId);
        }

        $lvl = army_get_building_level($mysqli, $userId, $bid);
        $row = army_get_building_row($mysqli, $userId, $bid);
        $status = $row ? (string)($row['status'] ?? 'active') : 'none';
        $finish = $row ? (int)($row['finish_time'] ?? 0) : 0;
        $target_level = $row ? (int)($row['target_level'] ?? 0) : 0;
        $busy = ($status !== 'active' && $finish > $now);

        $levels = (array)($game_data[$bid]['levels'] ?? []);
        $maxLvl = count($levels);
        $nextLevel = ($lvl > 0) ? ($lvl + 1) : 1;

        $next = null;
        $canBuild = false;
        $canUp = false;
        $lockedReason = '';

        // builder availability
        $builderFree = 1;
        try {
            if (function_exists('getBuilderCounts')) {
                $bc = getBuilderCounts($mysqli, $userId);
                $builderFree = (int)($bc['free'] ?? 1);
            }
        } catch (Throwable $e) {
            $builderFree = 1;
        }

        if ($busy) {
            // While constructing/upgrading: show target level info but do NOT allow actions
            $lockedReason = ($status === 'constructing') ? 'Идет строительство.' : 'Идет улучшение.';

            // Determine which level is being built/upgraded to
            $busyTarget = 0;
            if ($status === 'constructing') {
                $busyTarget = 1;
            } elseif ($target_level > 0) {
                $busyTarget = $target_level;
            } else {
                $busyTarget = $nextLevel;
            }

            if (isset($levels[$busyTarget])) {
                $nl = $levels[$busyTarget];
                $next = [
                    'level' => $busyTarget,
                    'cost' => (int)($nl['cost'] ?? 0),
                    'res_type' => (string)($nl['res_type'] ?? ''),
                    'time' => (int)($nl['time'] ?? 0),
                    'th_req' => (int)($nl['th_req'] ?? 1),
                    'hp' => isset($nl['hp']) ? (int)$nl['hp'] : null,
                    'unlocks' => army_api_unlocks_to_string($nl['unlocks'] ?? ''),
                ];
                $nextLevel = $busyTarget;
            }
        } else {
            if ($lvl <= 0) {
                // build level 1
                if (!isset($levels[1])) {
                    $lockedReason = 'Нет данных уровня 1.';
                } else {
                    $thReq = (int)($levels[1]['th_req'] ?? 1);
                    if ($townhallLvl < $thReq) {
                        $lockedReason = 'Требуется Ратуша ' . $thReq . '.';
                    } else {
                        // max count
                        $builtCount = function_exists('getPlayerBuildingsByType') ? count(getPlayerBuildingsByType($mysqli, $bid)) : 0;
                        $maxCount = function_exists('getMaxCountForTH') ? (int)getMaxCountForTH($bid, $townhallLvl) : 1;
                        if ($builtCount >= $maxCount) {
                            $lockedReason = 'Достигнут лимит построек.';
                        } elseif ($builderFree <= 0) {
                            $lockedReason = 'Нет свободных строителей.';
                        } else {
                            $canBuild = true;
                        }
                    }
                    $l1 = $levels[1];
                    $next = [
                        'level' => 1,
                        'cost' => (int)($l1['cost'] ?? 0),
                        'res_type' => (string)($l1['res_type'] ?? ''),
                        'time' => (int)($l1['time'] ?? 0),
                        'th_req' => (int)($l1['th_req'] ?? 1),
                        'hp' => isset($l1['hp']) ? (int)$l1['hp'] : null,
                        'unlocks' => army_api_unlocks_to_string($l1['unlocks'] ?? ''),
                    ];
                }
            } else {
                if (!isset($levels[$nextLevel])) {
                    $lockedReason = 'Макс. уровень.';
                } else {
                    $nl = $levels[$nextLevel];
                    $thReq = (int)($nl['th_req'] ?? 1);
                    if ($townhallLvl < $thReq) {
                        $lockedReason = 'Требуется Ратуша ' . $thReq . '.';
                    } elseif ($builderFree <= 0) {
                        $lockedReason = 'Нет свободных строителей.';
                    } else {
                        $canUp = true;
                    }
                    $next = [
                        'level' => $nextLevel,
                        'cost' => (int)($nl['cost'] ?? 0),
                        'res_type' => (string)($nl['res_type'] ?? ''),
                        'time' => (int)($nl['time'] ?? 0),
                        'th_req' => (int)($nl['th_req'] ?? 1),
                        'hp' => isset($nl['hp']) ? (int)$nl['hp'] : null,
                        'unlocks' => army_api_unlocks_to_string($nl['unlocks'] ?? ''),
                    ];
                }
            }
        }

        // Capacity helpers (for UI stat blocks)
        $calcCapacity = function(string $buildingId, int $level) use ($game_data, $townhallLvl): ?int {
            if ($level <= 0) return null;
            if ($buildingId === 'army_camp') {
                $perCamp = (int)($game_data['army_camp']['levels'][$level]['capacity_army'] ?? 0);
                $mult = function_exists('army_virtual_camps_for_th') ? army_virtual_camps_for_th($townhallLvl) : 1;
                return max(0, $perCamp) * max(1, (int)$mult);
            }
            if ($buildingId === 'spell_factory') {
                $lvlDef = $game_data['spell_factory']['levels'][$level] ?? null;
                if (is_array($lvlDef) && isset($lvlDef['capacity_spells'])) return max(0, (int)$lvlDef['capacity_spells']);
                // fallback mapping
                if ($level == 1) return 2;
                if ($level == 2) return 4;
                if ($level == 3) return 6;
                if ($level == 4) return 7;
                if ($level == 5) return 8;
                if ($level == 6) return 9;
                if ($level == 7) return 10;
                if ($level >= 8) return 11;
            }
            return null;
        };

        $cur = null;
        if ($lvl > 0 && isset($levels[$lvl])) {
            $cl = $levels[$lvl];
            $cur = [
                'level' => $lvl,
                'hp' => isset($cl['hp']) ? (int)$cl['hp'] : null,
                'unlocks' => army_api_unlocks_to_string($cl['unlocks'] ?? ''),
                'th_req' => (int)($cl['th_req'] ?? 1),
            ];
            $capVal = $calcCapacity($bid, $lvl);
            if ($capVal !== null) {
                $cur['capacity'] = (int)$capVal;
                if ($bid === 'army_camp') {
                    $cur['capacity_per_camp'] = (int)($game_data['army_camp']['levels'][$lvl]['capacity_army'] ?? 0);
                    $cur['virtual_camps'] = function_exists('army_virtual_camps_for_th') ? (int)army_virtual_camps_for_th($townhallLvl) : 1;
                } elseif ($bid === 'spell_factory') {
                    $cur['capacity_spells'] = (int)$capVal;
                }
            }
        }

        if ($next && isset($next['level'])) {
            $capVal2 = $calcCapacity($bid, (int)$next['level']);
            if ($capVal2 !== null) {
                $next['capacity'] = (int)$capVal2;
                if ($bid === 'army_camp') {
                    $nl = (int)$next['level'];
                    $next['capacity_per_camp'] = (int)($game_data['army_camp']['levels'][$nl]['capacity_army'] ?? 0);
                    $next['virtual_camps'] = function_exists('army_virtual_camps_for_th') ? (int)army_virtual_camps_for_th($townhallLvl) : 1;
                } elseif ($bid === 'spell_factory') {
                    $next['capacity_spells'] = (int)$capVal2;
                }
            }
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'server_time' => $now,
            'building' => [
                'id' => $bid,
                'name' => (string)($game_data[$bid]['name'] ?? $bid),
                'description' => (string)($game_data[$bid]['description'] ?? ''),
                'level' => (int)$lvl,
                'target_level' => ($target_level > 0 ? (int)$target_level : null),
                'max_level' => (int)$maxLvl,
                'status' => $status,
                'finish_time' => $finish,
                'time_left' => $busy ? max(0, $finish - $now) : 0,
                'can_build' => $canBuild,
                'can_upgrade' => $canUp,
                'locked_reason' => $lockedReason,
                'next_level' => ($next && isset($next['level'])) ? (int)$next['level'] : null,
            ],
            'current' => $cur,
            'next' => $next,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'building_build') {
        $bid = trim((string)army_api_get_post('building_id', ''));
        if ($bid === '') army_api_error('Не указан building_id', 400);
        if (!in_array($bid, army_api_allowed_buildings(), true)) army_api_error('Неизвестная постройка', 400);

        try {
            buildNewBuilding($mysqli, $user, $bid);
        } catch (GameActionException $e) {
            army_api_error($e->getMessage(), 400);
        } catch (Throwable $e) {
            error_log('building_build error: ' . $e->getMessage());
            army_api_error('Ошибка строительства.', 500);
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'message' => 'Строительство запущено.',
            'resources' => [
                'gold' => (int)($user['gold'] ?? 0),
                'elixir' => (int)($user['elixir'] ?? 0),
                'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
                'gems' => (int)($user['gems'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'building_upgrade') {
        $bid = trim((string)army_api_get_post('building_id', ''));
        if ($bid === '') army_api_error('Не указан building_id', 400);
        if (!in_array($bid, army_api_allowed_buildings(), true)) army_api_error('Неизвестная постройка', 400);

        $row = army_get_building_row($mysqli, $userId, $bid);
        if (!$row) {
            army_api_error('Здание не построено.', 400);
        }

        try {
            startBuildingUpgrade($mysqli, $user, $row);
        } catch (GameActionException $e) {
            army_api_error($e->getMessage(), 400);
        } catch (Throwable $e) {
            error_log('building_upgrade error: ' . $e->getMessage());
            army_api_error('Ошибка улучшения.', 500);
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'message' => 'Улучшение запущено.',
            'resources' => [
                'gold' => (int)($user['gold'] ?? 0),
                'elixir' => (int)($user['elixir'] ?? 0),
                'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
                'gems' => (int)($user['gems'] ?? 0),
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Speed up building construction/upgrade with gems (like heroes speedup)
    if ($action === 'building_speedup') {
        $bid = trim((string)army_api_get_post('building_id', ''));
        if ($bid === '') army_api_error('building_id required', 400);
        if (!in_array($bid, army_api_allowed_buildings(), true)) army_api_error('Неизвестная постройка', 400);

        $quote = (int)army_api_get_post('quote', 0);

        // finalize any completed timers first
        if (function_exists('finalizeCompletedBuildTimers')) {
            finalizeCompletedBuildTimers($mysqli, $userId);
        }

        $row = army_get_building_row($mysqli, $userId, $bid);
        if (!$row) army_api_error('Здание не построено.', 400);

        $status = (string)($row['status'] ?? 'active');
        $finish = (int)($row['finish_time'] ?? 0);
        $now = time();

        if (!in_array($status, ['constructing','upgrading'], true) || $finish <= $now) {
            army_api_error('Здание сейчас не улучшается.', 400);
        }

        $left = max(0, $finish - $now);
        $gemCost = army_gem_cost_for_seconds($left);

        if ($quote > 0) {
            if (ob_get_length()) { @ob_clean(); }
            echo json_encode([
                'ok' => true,
                'quote' => true,
                'cost_gems' => $gemCost,
                'time_left' => $left,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $mysqli->begin_transaction();
        try {
            $stmtLock = $mysqli->prepare("SELECT gems FROM users WHERE id=? FOR UPDATE");
            if (!$stmtLock) throw new RuntimeException('DB: lock user failed', 500);
            $stmtLock->bind_param('i', $userId);
            $stmtLock->execute();
            $uRow = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            $have = (int)($uRow['gems'] ?? 0);
            if ($gemCost > 0 && $have < $gemCost) {
                throwNotEnoughResources('gems', $gemCost, $have, 'ускорение');
            }
            $newVal = $have - $gemCost;

            $stmtU = $mysqli->prepare("UPDATE users SET gems=? WHERE id=?");
            if (!$stmtU) throw new RuntimeException('DB: update gems failed', 500);
            $stmtU->bind_param('ii', $newVal, $userId);
            $stmtU->execute();
            $stmtU->close();

            // Mark building timer finished now; finalizeCompletedBuildTimers will apply target_level.
            $stmtB = $mysqli->prepare("UPDATE player_buildings SET finish_time=? WHERE user_id=? AND building_id=?");
            if (!$stmtB) throw new RuntimeException('DB: update building failed', 500);
            $stmtB->bind_param('iis', $now, $userId, $bid);
            $stmtB->execute();
            $stmtB->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), 400);
            }
            throw $e;
        }

        // Apply completion
        if (function_exists('finalizeCompletedBuildTimers')) {
            finalizeCompletedBuildTimers($mysqli, $userId);
        }

        // Return fresh row
        $row2 = army_get_building_row($mysqli, $userId, $bid);
        $lvl2 = $row2 ? (int)($row2['level'] ?? 0) : 0;

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'building_id' => $bid,
            'cost_gems' => $gemCost,
            'gems' => (int)($newVal ?? 0),
            'level' => $lvl2,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
// -------------------- Stage 2: Instant army composition --------------------
    // Modern CoC training: free & instant. We keep old action name barracks_train as alias for add.

    if ($action === 'barracks_add') {
        $unitId = trim((string)army_api_get_post('unit_id', ''));
        $qty = (int)army_api_get_post('qty', 1);
        if ($unitId === '') army_api_error('unit_id required', 400);
        if ($qty < 1) $qty = 1;
        if ($qty > 200) $qty = 200;

        if (!isset($game_data[$unitId]) || !is_array($game_data[$unitId])) {
            army_api_error('Unknown unit_id', 400);
        }

        $def = $game_data[$unitId];
        $type = (string)($def['type'] ?? '');
        if (!in_array($type, [TYPE_TROOP, TYPE_DARK_TROOP], true)) {
            army_api_error('Unit is not a troop', 400);
        }

        // Barracks must be built and ready
        try {
            $bRow = army_require_building_ready($mysqli, $userId, 'barracks', 'Казармы');
        } catch (Throwable $e) {
            army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }

        // Unlock by Barracks level (Stage 6 will refine by other buildings/types)
        $barracksLvl = (int)($bRow['level'] ?? 0);
        $unlocked = army_api_list_unlocked_troops($game_data, $barracksLvl);
        if (!in_array($unitId, $unlocked, true)) {
            army_api_error('Юнит не доступен в ваших казармах.', 400);
        }

        $spacePer = (int)($def['housing_space'] ?? 1);
        if ($spacePer < 1) $spacePer = 1;

        $campCap = army_get_camp_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        if ($campCap <= 0) {
            army_api_error('Постройте лагеря, чтобы формировать армию.', 400);
        }

        $needSpace = $spacePer * $qty;

        $mysqli->begin_transaction();
        try {
            // Lock current army rows to prevent race conditions
            $armyRows = [];
            $stmtA = $mysqli->prepare("SELECT unit_id, amount FROM player_army WHERE user_id=? FOR UPDATE");
            if (!$stmtA) throw new RuntimeException('DB: lock army failed', 500);
            $stmtA->bind_param('i', $userId);
            $stmtA->execute();
            $resA = $stmtA->get_result();
            while ($resA && ($r = $resA->fetch_assoc())) {
                $armyRows[] = $r;
            }
            $stmtA->close();

            $used = 0;
            foreach ($armyRows as $r) {
                $uid = (string)($r['unit_id'] ?? '');
                if ($uid === '' || !isset($game_data[$uid])) continue;
                $amt = (int)($r['amount'] ?? 0);
                if ($amt <= 0) continue;
                $sp = (int)($game_data[$uid]['housing_space'] ?? 1);
                if ($sp < 1) $sp = 1;
                $used += $sp * $amt;
            }

            if (($used + $needSpace) > $campCap) {
                throw new GameActionException('Недостаточно места в лагере.', 400, [
                    'type' => 'cap_exceeded',
                    'cap' => $campCap,
                    'used' => $used,
                    'need' => $needSpace,
                ]);
            }

            $ins = $mysqli->prepare("INSERT INTO player_army (user_id, unit_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)");
            if (!$ins) throw new RuntimeException('DB: add army failed', 500);
            $ins->bind_param('isi', $userId, $unitId, $qty);
            $ins->execute();
            $ins->close();

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), 400);
            }
            throw $e;
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
        'message' => 'Улучшение запущено.',
        'message' => 'Строительство запущено.',
            'training_model' => 'instant'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_remove') {
        $unitId = trim((string)army_api_get_post('unit_id', ''));
        $qty = (int)army_api_get_post('qty', 1);
        if ($unitId === '') army_api_error('unit_id required', 400);
        if ($qty < 1) $qty = 1;
        if ($qty > 200) $qty = 200;

        $mysqli->begin_transaction();
        try {
            $stmt = $mysqli->prepare("SELECT amount FROM player_army WHERE user_id=? AND unit_id=? FOR UPDATE");
            if (!$stmt) throw new RuntimeException('DB: lock army unit failed', 500);
            $stmt->bind_param('is', $userId, $unitId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res) ? $res->fetch_assoc() : null;
            $stmt->close();

            $have = (int)($row['amount'] ?? 0);
            if ($have <= 0) {
                throw new GameActionException('Этот юнит отсутствует в армии.', 400, ['type' => 'not_in_army']);
            }
            if ($qty > $have) $qty = $have;

            $newAmt = $have - $qty;
            if ($newAmt > 0) {
                $upd = $mysqli->prepare("UPDATE player_army SET amount=? WHERE user_id=? AND unit_id=?");
                if (!$upd) throw new RuntimeException('DB: update army failed', 500);
                $upd->bind_param('iis', $newAmt, $userId, $unitId);
                $upd->execute();
                $upd->close();
            } else {
                $del = $mysqli->prepare("DELETE FROM player_army WHERE user_id=? AND unit_id=?");
                if (!$del) throw new RuntimeException('DB: delete army failed', 500);
                $del->bind_param('is', $userId, $unitId);
                $del->execute();
                $del->close();
            }

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), 400);
            }
            throw $e;
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'training_model' => 'instant'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // -------------------- Stage 12: Legacy spell brew queue --------------------
    // Заклинания готовятся во времени (как в старом CoC), а не мгновенно.

    // -------------------- Stage 12: Legacy spell brew queue --------------------
    // Заклинания готовятся во времени (как в старом CoC), а не мгновенно.

    if ($action === 'barracks_spell_add' || $action === 'barracks_spell_train') {
        $spellId = trim((string)army_api_get_post('spell_id', ''));
        if ($spellId === '') $spellId = trim((string)army_api_get_post('unit_id', ''));
        $qty = (int)army_api_get_post('qty', 1);
        if ($spellId === '') army_api_error('spell_id required', 400);
        if ($qty < 1) $qty = 1;
        if ($qty > 200) $qty = 200;

        if (!isset($game_data[$spellId]) || !is_array($game_data[$spellId])) {
            army_api_error('Unknown spell_id', 400);
        }
        $def = $game_data[$spellId];
        $type = (string)($def['type'] ?? '');
        if (!in_array($type, [TYPE_SPELL, TYPE_DARK_SPELL], true)) {
            army_api_error('Unit is not a spell', 400);
        }

        // $townhallLvl already resolved at the start of request.

        // Требуемое здание и разлок как в CoC
        $buildingId = army_api_unlock_building_for_type($type); // spell_factory / dark_spell_factory
        if ($buildingId === '') army_api_error('Invalid spell type', 400);

        $buildingName = army_api_unlock_building_name($buildingId);
        try {
            army_require_building_ready($mysqli, $userId, $buildingId, $buildingName);
        } catch (Throwable $e) {
            army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
        $srcLvl = army_get_building_level($mysqli, $userId, $buildingId);
        $reqLvl = army_api_required_building_level($game_data, $buildingId, $spellId);
        if ($reqLvl <= 0) {
            army_api_error('Недоступно: заклинание не привязано к ' . $buildingName . '.', 400);
        }
        if ($srcLvl < $reqLvl) {
            army_api_error('Требуется ' . $buildingName . ' ур. ' . $reqLvl . '.', 400);
        }

        $spellCap = army_get_spell_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        if ($spellCap <= 0) {
            // На низких TH и без фабрики — 0. Если фабрика уже построена, cap может быть 0 в текущей формуле —
            // тогда просто не даем варить.
            army_api_error('Недоступно: вместимость заклинаний = 0.', 400);
        }

        $spacePer = (int)($def['housing_space'] ?? 1);
        if ($spacePer < 1) $spacePer = 1;
        $needSpace = $spacePer * $qty;

        // Уровень заклинания (из исследований)
        $levels = army_research_get_levels($mysqli, $userId);
        $lvl = 1;
        if (isset($levels[$spellId]['level'])) $lvl = (int)$levels[$spellId]['level'];
        if ($lvl < 1) $lvl = 1;

        $storedUsed = army_get_spells_used($mysqli, $userId, $game_data);
        $queueUsed = army_spell_queue_get_space_used($mysqli, $userId, $game_data);
        if (($storedUsed + $queueUsed + $needSpace) > $spellCap) {
            army_api_error('Недостаточно места для заклинаний.', 400);
        }

        $brewSeconds = army_get_spell_brew_time($game_data, $spellId);

        // Добавление в очередь
        $source = $buildingId; // для раздельных очередей (обычная/темная)
        army_spell_queue_add($mysqli, $userId, $spellId, $lvl, $qty, $brewSeconds, $source);

        // Sync immediately (if brewSeconds==0 or some were ready)
        if (function_exists('army_spell_training_sync')) army_spell_training_sync($mysqli, $userId, $game_data, $spellCap);

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'training_model' => 'queue',
            'kind' => 'spells'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_spell_cancel') {
        $queueId = (int)army_api_get_post('queue_id', 0);
        if ($queueId <= 0) army_api_error('queue_id required', 400);

        // Найдем source для правильного пересчета таймингов
        $source = '';
        $stmt = @$mysqli->prepare("SELECT source FROM player_spell_queue WHERE user_id=? AND id=?");
        if (!$stmt) {
            army_api_error('DB: отсутствует таблица player_spell_queue (примените патч Stage 12)', 500);
        }
        $stmt->bind_param('ii', $userId, $queueId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $source = (string)($row['source'] ?? '');
        if ($source === '') $source = 'spell_factory';

        army_spell_queue_cancel($mysqli, $userId, $queueId);
        army_spell_queue_recalculate_timings($mysqli, $userId, $game_data, $source);

        $spellCap = army_get_spell_capacity_total($mysqli, $userId, $game_data, 0);
        if ($spellCap > 0) {
            if (function_exists('army_spell_training_sync')) army_spell_training_sync($mysqli, $userId, $game_data, $spellCap);
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_spell_speedup') {
        // Ускорение варки заклинаний за гемы (как в CoC: цена считается по оставшемуся времени).
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') army_api_error('Invalid method', 405);

        $mode = trim((string)army_api_get_post('mode', 'all'));
        if ($mode !== 'all' && $mode !== 'current') $mode = 'all';
        $now = time();

        // Считаем секунды ускорения
        $seconds = 0;
        if ($mode === 'current') {
            $stmtC = @$mysqli->prepare("SELECT id, start_time, finish_time FROM player_spell_queue WHERE user_id=? AND status='training' AND start_time<=? AND finish_time>? ORDER BY start_time ASC, id ASC LIMIT 1");
            if (!$stmtC) {
                army_api_error('DB: отсутствует таблица player_spell_queue (примените патч Stage 12)', 500);
            }
            $stmtC->bind_param('iii', $userId, $now, $now);
            $stmtC->execute();
            $cur = $stmtC->get_result()->fetch_assoc();
            $stmtC->close();
            if ($cur) {
                $seconds = max(0, (int)($cur['finish_time'] ?? 0) - $now);
            }
        } else {
            $stmtQ = @$mysqli->prepare("SELECT start_time, finish_time, status FROM player_spell_queue WHERE user_id=? AND status='training'");
            if (!$stmtQ) {
                army_api_error('DB: отсутствует таблица player_spell_queue (примените патч Stage 12)', 500);
            }
            $stmtQ->bind_param('i', $userId);
            $stmtQ->execute();
            $resQ = $stmtQ->get_result();
            while ($resQ && ($it = $resQ->fetch_assoc())) {
                $st = (int)($it['start_time'] ?? 0);
                $ft = (int)($it['finish_time'] ?? 0);
                if ($ft <= $now) continue;
                if ($st <= $now) {
                    $seconds += ($ft - $now);
                } else {
                    $seconds += ($ft - $st);
                }
            }
            $stmtQ->close();
        }

        $gemCost = army_gem_cost_for_seconds((int)$seconds);
        if ($gemCost <= 0) {
            if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'cost' => 0], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Списываем гемы и отмечаем ready
        $mysqli->begin_transaction();
        try {
            $stmtLock = $mysqli->prepare("SELECT gems FROM users WHERE id=? FOR UPDATE");
            if (!$stmtLock) throw new RuntimeException('DB lock failed', 500);
            $stmtLock->bind_param('i', $userId);
            $stmtLock->execute();
            $uRow = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            $have = (int)($uRow['gems'] ?? 0);
            if ($have < $gemCost) {
                throwNotEnoughResources('gems', $gemCost, $have, 'ускорение');
            }

            $newVal = $have - $gemCost;
            $stmtU = $mysqli->prepare("UPDATE users SET gems=? WHERE id=?");
            if (!$stmtU) throw new RuntimeException('DB update failed', 500);
            $stmtU->bind_param('ii', $newVal, $userId);
            $stmtU->execute();
            $stmtU->close();

            if ($mode === 'all') {
                $stmt = @$mysqli->prepare("UPDATE player_spell_queue SET status='ready', start_time=?, finish_time=? WHERE user_id=? AND status='training'");
                if (!$stmt) throw new RuntimeException('DB: player_spell_queue missing', 500);
                $stmt->bind_param('iii', $now, $now, $userId);
                $stmt->execute();
                $stmt->close();
            } else {
                // Только текущий
                $stmtC2 = @$mysqli->prepare("SELECT id FROM player_spell_queue WHERE user_id=? AND status='training' AND start_time<=? AND finish_time>? ORDER BY start_time ASC, id ASC LIMIT 1");
                if (!$stmtC2) throw new RuntimeException('DB: player_spell_queue missing', 500);
                $stmtC2->bind_param('iii', $userId, $now, $now);
                $stmtC2->execute();
                $row = $stmtC2->get_result()->fetch_assoc();
                $stmtC2->close();
                $qid = (int)($row['id'] ?? 0);
                if ($qid > 0) {
                    $stmt = @$mysqli->prepare("UPDATE player_spell_queue SET status='ready', finish_time=? WHERE user_id=? AND id=?");
                    if (!$stmt) throw new RuntimeException('DB: player_spell_queue missing', 500);
                    $stmt->bind_param('iii', $now, $userId, $qid);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            $mysqli->commit();
            $user['gems'] = $newVal;
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }

        // Sync заклинаний + перерасчет таймингов
        // $townhallLvl already resolved at the start of request.
        $spellCap = army_get_spell_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
        if ($spellCap > 0 && function_exists('army_spell_training_sync')) army_spell_training_sync($mysqli, $userId, $game_data, $spellCap);
        if ($mode === 'current') {
            // Пересчёт нужен только если ускоряли не всё
            army_spell_queue_recalculate_timings($mysqli, $userId, $game_data, 'spell_factory');
            army_spell_queue_recalculate_timings($mysqli, $userId, $game_data, 'dark_spell_factory');
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'cost' => $gemCost, 'user' => [
            'gold' => (int)($user['gold'] ?? 0),
            'elixir' => (int)($user['elixir'] ?? 0),
            'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
            'gems' => (int)($user['gems'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_spell_remove') {
        $spellId = trim((string)army_api_get_post('spell_id', ''));
        if ($spellId === '') $spellId = trim((string)army_api_get_post('unit_id', ''));
        $qty = (int)army_api_get_post('qty', 1);
        if ($spellId === '') army_api_error('spell_id required', 400);
        if ($qty < 1) $qty = 1;
        if ($qty > 200) $qty = 200;

        $mysqli->begin_transaction();
        try {
            $stmt = @$mysqli->prepare("SELECT amount FROM player_spells WHERE user_id=? AND spell_id=? FOR UPDATE");
            if (!$stmt) {
                throw new RuntimeException('DB: отсутствует таблица player_spells (выполните миграцию Stage 3.1)', 500);
            }
            $stmt->bind_param('is', $userId, $spellId);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = ($res) ? $res->fetch_assoc() : null;
            $stmt->close();

            $have = (int)($row['amount'] ?? 0);
            if ($have <= 0) {
                throw new GameActionException('Это заклинание отсутствует в составе.', 400, ['type' => 'not_in_spells']);
            }
            if ($qty > $have) $qty = $have;

            $newAmt = $have - $qty;
            if ($newAmt > 0) {
                $upd = $mysqli->prepare("UPDATE player_spells SET amount=? WHERE user_id=? AND spell_id=?");
                if (!$upd) throw new RuntimeException('DB: update spell failed', 500);
                $upd->bind_param('iis', $newAmt, $userId, $spellId);
                $upd->execute();
                $upd->close();
            } else {
                $del = $mysqli->prepare("DELETE FROM player_spells WHERE user_id=? AND spell_id=?");
                if (!$del) throw new RuntimeException('DB: delete spell failed', 500);
                $del->bind_param('is', $userId, $spellId);
                $del->execute();
                $del->close();
            }

            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), 400);
            }
            throw $e;
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
            'training_model' => 'instant',
            'kind' => 'spells'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_train') {
        $unitId = trim((string)army_api_get_post('unit_id', ''));
        $qty = (int)army_api_get_post('qty', 1);
        $type = trim((string)army_api_get_post('type', TYPE_TROOP));
        if ($unitId === '') army_api_error('unit_id required', 400);
        if ($qty < 1) $qty = 1;
        if ($qty > 200) $qty = 200;

        if (!isset($game_data[$unitId]) || !is_array($game_data[$unitId])) {
            army_api_error('Неизвестный юнит', 400);
        }
        $defType = (string)($game_data[$unitId]['type'] ?? '');
        if ($defType !== $type) {
            // фронт может передать type, но мы доверяем game_data
            $type = $defType;
        }
        if (!in_array($type, [TYPE_TROOP, TYPE_DARK_TROOP, TYPE_SIEGE], true)) {
            army_api_error('Unsupported type', 400);
        }

        $buildingId = army_api_unlock_building_for_type($type);
        if ($buildingId === '') army_api_error('Unsupported unit type', 400);

        // Соответствующее здание должно быть построено и активно
        try {
            $bRow = army_require_building_ready($mysqli, $userId, $buildingId, army_api_unlock_building_name($buildingId));
        } catch (Throwable $e) {
            army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
        }
        $srcLvl = (int)($bRow['level'] ?? 0);

        $reqLvl = army_api_required_building_level($game_data, $buildingId, $unitId);
        if ($reqLvl <= 0) {
            army_api_error('Юнит пока недоступен.', 400);
        }
        if ($srcLvl < $reqLvl) {
            army_api_error('Требуется ' . army_api_unlock_building_name($buildingId) . ' ур. ' . $reqLvl . '.', 400);
        }

        // Проверка вместимости лагерей (ОЧЕРЕДЬ НЕ ЗАНИМАЕТ МЕСТО В ЛАГЕРЯХ)
// Место в лагерях занимает только готовая армия. Очередь тренировки хранится отдельно.
$campCap = army_get_camp_capacity_total($mysqli, $userId, $game_data, $townhallLvl);
$usedNow = army_get_army_used($mysqli, $userId, $game_data);
$space = (int)($game_data[$unitId]['housing_space'] ?? 0);
if ($space < 1) $space = 1;
$need = $space * $qty;
if (($usedNow + $need) > $campCap) {
    army_api_error('Недостаточно места в лагерях.', 400);
}


        // Стоимость + списание
        $cost = army_get_training_cost($game_data, $unitId);
        $resKey = (string)($cost['res_key'] ?? 'elixir');
        $price = (int)($cost['cost'] ?? 0);
        if ($price < 0) $price = 0;
        $totalCost = $price * $qty;
        // Время тренировки
        $trainSeconds = (int)($game_data[$unitId]['training_time'] ?? 0);
        if ($trainSeconds < 0) $trainSeconds = 0;
        // Уровень юнита берём из исследований
        $levels = army_research_get_levels($mysqli, $userId);
        $unitLevel = (int)($levels[$unitId]['level'] ?? 1);
        if ($unitLevel < 1) $unitLevel = 1;

        $mysqli->begin_transaction();
        try {
            if ($totalCost > 0) {
                debitUserResource($mysqli, (int)$userId, (string)$resKey, (int)$totalCost);
            }
            army_queue_add_units($mysqli, $userId, $unitId, $unitLevel, $qty, $trainSeconds, $buildingId);
            $mysqli->commit();
        } catch (Throwable $e) {
            $mysqli->rollback();
            if ($e instanceof GameActionException) {
                army_api_error($e->getMessage(), (int)($e->getCode() ?: 400));
            }
            army_api_error('DB error (queue add)', 500);
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_cancel') {
        $queueId = (int)army_api_get_post('queue_id', 0);
        if ($queueId <= 0) army_api_error('queue_id required', 400);

        // Попытаемся вернуть ресурсы за отмену (как в CoC)
        $row = null;
        $stmt = $mysqli->prepare("SELECT id, unit_id, qty, source FROM player_training_queue WHERE user_id=? AND id=?");
        if ($stmt) {
            $stmt->bind_param('ii', $userId, $queueId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }

        $refundRes = null;
        $refundAmt = 0;
        if ($row && !empty($row['unit_id'])) {
            $unitId = (string)$row['unit_id'];
            $qty = (int)($row['qty'] ?? 1);
            if ($qty < 1) $qty = 1;
            $c = army_get_training_cost($game_data, $unitId);
            $refundRes = (string)($c['res_key'] ?? 'elixir');
            $refundAmt = (int)($c['cost'] ?? 0) * $qty;
            if ($refundAmt < 0) $refundAmt = 0;
        }

        if ($refundAmt > 0 && $refundRes) {
            $mysqli->begin_transaction();
            try {
                // lock user
                $stmtLock = $mysqli->prepare("SELECT gold, elixir, dark_elixir, gems FROM users WHERE id=? FOR UPDATE");
                if (!$stmtLock) throw new RuntimeException('DB lock failed', 500);
                $stmtLock->bind_param('i', $userId);
                $stmtLock->execute();
                $uRow = $stmtLock->get_result()->fetch_assoc();
                $stmtLock->close();

                army_queue_cancel($mysqli, $userId, $queueId);
                $src = '';
                if ($row && isset($row['source'])) $src = (string)$row['source'];
                army_queue_recalculate_timings($mysqli, $userId, $game_data, $src);

                $newVal = creditUserResource($mysqli, (int)$userId, (string)$refundRes, (int)$refundAmt);

                $mysqli->commit();
                $user[$refundRes] = $newVal;
            } catch (Throwable $e) {
                $mysqli->rollback();
                throw $e;
            }
        } else {
            army_queue_cancel($mysqli, $userId, $queueId);
                $src = '';
                if ($row && isset($row['source'])) $src = (string)$row['source'];
                army_queue_recalculate_timings($mysqli, $userId, $game_data, $src);
        }

        army_training_sync($mysqli, $userId, $game_data);

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'user' => [
            'gold' => (int)($user['gold'] ?? 0),
            'elixir' => (int)($user['elixir'] ?? 0),
            'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
            'gems' => (int)($user['gems'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_build') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') army_api_error('Invalid method', 405);
        // Стройка через общий билд-механизм
        $user = buildNewBuilding($mysqli, $user, 'barracks');
        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'user' => [
            'gold' => (int)($user['gold'] ?? 0),
            'elixir' => (int)($user['elixir'] ?? 0),
            'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
            'gems' => (int)($user['gems'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_upgrade') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') army_api_error('Invalid method', 405);
        $b = army_get_building_row($mysqli, $userId, 'barracks');
        if (!$b) army_api_error('Казармы не построены.', 400);
        // Улучшаем одну казарму (первую)
        $user = startBuildingUpgrade($mysqli, $user, $b);
        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'user' => [
            'gold' => (int)($user['gold'] ?? 0),
            'elixir' => (int)($user['elixir'] ?? 0),
            'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
            'gems' => (int)($user['gems'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'barracks_speedup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') army_api_error('Invalid method', 405);
        $mode = trim((string)army_api_get_post('mode', 'all'));
        if ($mode !== 'all' && $mode !== 'current') $mode = 'all';
        $dryRun = (int)army_api_get_post('dry_run', 0);

        // Сколько секунд ускоряем?
        $now = time();
        $seconds = 0;
        if ($mode === 'current') {
            $cur = army_queue_get_current($mysqli, $userId);
            if ($cur) {
                $seconds = max(0, (int)($cur['finish_time'] ?? 0) - $now);
            }
        } else {
            $q = army_queue_get($mysqli, $userId);
            foreach ($q as $it) {
                if (($it['status'] ?? '') !== 'training') continue;
                $st = (int)($it['start_time'] ?? 0);
                $ft = (int)($it['finish_time'] ?? 0);
                if ($ft <= $now) continue;
                if ($st <= $now) {
                    $seconds += ($ft - $now);
                } else {
                    $seconds += ($ft - $st);
                }
            }
        }

        $gemCost = army_gem_cost_for_seconds((int)$seconds);
        if ($dryRun) {
            if (ob_get_length()) { @ob_clean(); }
            echo json_encode(['ok' => true, 'cost' => $gemCost], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($gemCost <= 0) {
            if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'cost' => 0], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Списываем гемы и делаем ready
        $mysqli->begin_transaction();
        try {
            $stmtLock = $mysqli->prepare("SELECT gems FROM users WHERE id=? FOR UPDATE");
            if (!$stmtLock) throw new RuntimeException('DB lock failed', 500);
            $stmtLock->bind_param('i', $userId);
            $stmtLock->execute();
            $uRow = $stmtLock->get_result()->fetch_assoc();
            $stmtLock->close();

            $have = (int)($uRow['gems'] ?? 0);
            if ($have < $gemCost) {
                throwNotEnoughResources('gems', $gemCost, $have, 'ускорение');
            }

            $newVal = $have - $gemCost;
            $stmtU = $mysqli->prepare("UPDATE users SET gems=? WHERE id=?");
            if (!$stmtU) throw new RuntimeException('DB update failed', 500);
            $stmtU->bind_param('ii', $newVal, $userId);
            $stmtU->execute();
            $stmtU->close();

            army_queue_mark_ready($mysqli, $userId, ($mode === 'all'));

            $mysqli->commit();
            $user['gems'] = $newVal;
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }

        // Выдаём войска в лагерь и пересчитываем очередь
        army_training_sync($mysqli, $userId, $game_data);
        if ($mode === 'current') {
            army_queue_recalculate_timings($mysqli, $userId, $game_data);
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'cost' => $gemCost, 'user' => [
            'gold' => (int)($user['gold'] ?? 0),
            'elixir' => (int)($user['elixir'] ?? 0),
            'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
            'gems' => (int)($user['gems'] ?? 0),
        ]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'lab_state') {
        $now = time();

        // Ensure timers finalized + research sync
        if (function_exists('finalizeCompletedBuildTimers')) {
            finalizeCompletedBuildTimers($mysqli, $userId);
        }
        army_research_sync($mysqli, $userId);

        $townhallLvl = army_get_building_level($mysqli, $userId, 'townhall');
        if ($townhallLvl <= 0) $townhallLvl = 1;
        if ($townhallLvl <= 0) $townhallLvl = 1;

        $labRow = army_get_building_row($mysqli, $userId, 'laboratory');
        $labLvl = army_get_building_level($mysqli, $userId, 'laboratory');
        $labStatus = $labRow ? (string)($labRow['status'] ?? 'active') : ($labLvl > 0 ? 'active' : 'none');
        $labFinish = $labRow ? (int)($labRow['finish_time'] ?? 0) : 0;
        $labBusy = ($labRow && $labStatus !== 'active' && $labFinish > $now);

        $barracksLvl = army_get_building_level($mysqli, $userId, 'barracks');
        $unlocked = army_api_list_unlocked_troops($game_data, $barracksLvl);

        $levels = army_research_get_levels($mysqli, $userId);
        $active = army_research_get_active($mysqli, $userId);
        if ($active) {
            $active['finish_time'] = (int)($active['finish_time'] ?? 0);
            $active['time_left'] = max(0, $active['finish_time'] - $now);
        }

        $outResearch = [];
        foreach (army_api_researchables($game_data) as $r) {
            $id = (string)($r['id'] ?? '');
            if ($id === '' || empty($game_data[$id]) || !is_array($game_data[$id])) continue;

            $def = $game_data[$id];
            $type = (string)($def['type'] ?? '');

            $cur = 1;
            if (isset($levels[$id]['level'])) $cur = (int)$levels[$id]['level'];
            if ($cur < 1) $cur = 1;

            $max = (int)($r['max_level'] ?? $cur);
            if ($max < 1) $max = 1;

            $maxAllowed = $max;
            if (!empty($def['levels']) && is_array($def['levels'])) {
                $maxAllowed = min($max, army_api_max_level_by_th($def['levels'], $townhallLvl));
                if ($maxAllowed < 1) $maxAllowed = 1;
            }

            $next = $cur + 1;
            $nextCost = null;
            $nextTime = null;
            $nextRes = null;
            $thReq = 0;

            $locked = false;
            $lockedReason = '';

            // Lab availability
            if ($labLvl <= 0) {
                $locked = true;
                $lockedReason = 'Сначала постройте Лабораторию.';
            } elseif ($labBusy) {
                $locked = true;
                $lockedReason = 'Лаборатория улучшается.';
            }

            // Only one research at a time
            if (!$locked && $active && (string)($active['tech_id'] ?? '') !== $id) {
                $locked = true;
                $lockedReason = 'Другое исследование уже идет.';
            }

            // Max level reached
            if (!$locked && $next > $max) {
                $next = 0;
            }

            if ($next > 0 && !empty($def['levels'][$next]) && is_array($def['levels'][$next])) {
                $lvlDef = $def['levels'][$next];
                $nextCost = (int)($lvlDef['cost'] ?? 0);
                $nextTime = (int)($lvlDef['time'] ?? 0);
                $nextRes = army_api_resKey((string)($lvlDef['res_type'] ?? ''));
                $thReq = (int)($lvlDef['th_req'] ?? 0);

                // Cap by TH
                if (!$locked && $next > $maxAllowed) {
                    $locked = true;
                    if ($thReq > 0) $lockedReason = 'Требуется Ратуша ' . $thReq . '.';
                    else $lockedReason = 'Недоступно по уровню Ратуши.';
                }

                // Must be unlocked in corresponding building (Barracks/Factories/Siege Workshop)
                if (!$locked) {
                    $sourceBuilding = army_api_unlock_building_for_type($type);
                    if ($sourceBuilding !== '') {
                        $srcRow = army_get_building_row($mysqli, $userId, $sourceBuilding);
                        $srcLvl = army_get_building_level($mysqli, $userId, $sourceBuilding);
                        $srcStatus = $srcRow ? (string)($srcRow['status'] ?? 'active') : ($srcLvl > 0 ? 'active' : 'none');
                        $srcFinish = $srcRow ? (int)($srcRow['finish_time'] ?? 0) : 0;
                        // if upgrading right now, treat as previous level for unlocks
                        if ($srcRow && $srcStatus !== 'active' && $srcFinish > $now) {
                            $srcLvl = max(0, $srcLvl - 1);
                        }
                        $reqLvl = army_api_required_building_level($game_data, $sourceBuilding, $id);
                        $bn = army_api_unlock_building_name($sourceBuilding);
                        if ($srcLvl <= 0) {
                            $locked = true;
                            $lockedReason = 'Сначала постройте ' . $bn . '.';
                        } elseif ($reqLvl > 0 && $srcLvl < $reqLvl) {
                            $locked = true;
                            $lockedReason = 'Сначала откройте в ' . $bn . ' (ур. ' . $reqLvl . ').';
                        }
                    }
                }

                // Resource check (visual lock, still validated on start)
                if (!$locked && $nextCost > 0 && $nextRes) {
                    $rk = $nextRes;
                    $have = (int)($user[$rk] ?? 0);
                    if ($have < $nextCost) {
                        $locked = true;
                        $lockedReason = 'Не хватает ресурса для улучшения.';
                    }
                }
            } else {
                $next = 0;
            }

            $outResearch[] = [
                'id' => $id,
                'name' => (string)($r['name'] ?? $id),
                'type' => (string)($r['type'] ?? ''),
                'level' => $cur,
                'max_level' => $max,
                'max_allowed_level' => $maxAllowed,
                'next_level' => ($next > 0 ? $next : null),
                'next_cost' => $nextCost,
                'next_time' => $nextTime,
                'next_res' => $nextRes,
                'th_req' => $thReq,
                'locked' => $locked,
                'locked_reason' => $lockedReason,
                'is_researching' => ($active && (string)($active['tech_id'] ?? '') === $id),
            ];
        }

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode([
            'ok' => true,
        'message' => 'Улучшение казармы запущено.',
        'message' => 'Строительство казармы запущено.',
            'server_time' => $now,
            'laboratory_level' => $labLvl,
            'laboratory_status' => $labStatus,
            'laboratory_finish_time' => $labFinish,
            'townhall_level' => $townhallLvl,
            'active' => $active,
            'levels' => $levels,
            'barracks_level' => $barracksLvl,
            'unlocked_troops' => $unlocked,
            'researchables' => $outResearch,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'lab_start') {
        $techId = trim((string)army_api_get_post('tech_id', ''));
        if ($techId === '') army_api_error('tech_id required', 400);

        $labLvl = army_get_building_level($mysqli, $userId, 'laboratory');
        if ($labLvl <= 0) {
            army_api_error('Лаборатория не построена', 400);
        }

        if (!isset($game_data[$techId]) || !is_array($game_data[$techId]) || !army_api_is_researchable($game_data[$techId])) {
            army_api_error('Unknown tech_id', 400);
        }

        // Для исследований как в CoC: технология должна быть открыта соответствующим армейским зданием
        $defCheck = $game_data[$techId];
        $typeCheck = (string)($defCheck['type'] ?? '');
        $sourceBuilding = army_api_unlock_building_for_type($typeCheck);
        if ($sourceBuilding !== '') {
            $srcLvl = army_get_building_level($mysqli, $userId, $sourceBuilding);
            $srcRow = army_get_building_row($mysqli, $userId, $sourceBuilding);
            $srcStatus = $srcRow ? (string)($srcRow['status'] ?? 'active') : 'none';
            $srcFinish = $srcRow ? (int)($srcRow['finish_time'] ?? 0) : 0;
            if ($srcRow && $srcStatus !== 'active' && $srcFinish > time()) {
                // не разлочиваем преждевременно во время улучшения
                $srcLvl = max(0, $srcLvl - 1);
            }

            $reqLvl = army_api_required_building_level($game_data, $sourceBuilding, $techId);
            if ($srcLvl <= 0 || $reqLvl <= 0 || $srcLvl < $reqLvl) {
                $bn = army_api_unlock_building_name($sourceBuilding);
                if ($srcLvl <= 0) {
                    army_api_error('Сначала постройте ' . $bn, 400);
                }
                if ($reqLvl > 0) {
                    army_api_error('Сначала откройте технологию в ' . $bn . ' (ур. ' . $reqLvl . ')', 400);
                }
                army_api_error('Сначала откройте технологию в ' . $bn, 400);
            }
        }

        // Current level from player_research (default 1)
        $levels = army_research_get_levels($mysqli, $userId);
        $cur = 1;
        if (isset($levels[$techId]['level'])) $cur = (int)$levels[$techId]['level'];
        if ($cur < 1) $cur = 1;

        $next = $cur + 1;

        $def = $game_data[$techId];
        if (empty($def['levels'][$next])) {
            army_api_error('Max level reached', 400);
        }

        $lvlDef = $def['levels'][$next];

        // TH cap (like CoC): cannot research above Town Hall limit for this level
        $townhallLvl = army_get_building_level($mysqli, $userId, 'townhall');
        if ($townhallLvl <= 0) $townhallLvl = 1;
        if ($townhallLvl <= 0) $townhallLvl = 1;
        $thReq = (int)($lvlDef['th_req'] ?? 0);
        if ($thReq > 0 && $townhallLvl < $thReq) {
            army_api_error('Требуется Ратуша ' . $thReq . '.', 400);
        }
        if (!empty($def['levels']) && is_array($def['levels'])) {
            $maxAllowed = army_api_max_level_by_th($def['levels'], $townhallLvl);
            if ($next > $maxAllowed) {
                army_api_error('Требуется повышение Ратуши для следующего уровня.', 400);
            }
        }

        $cost = (int)($lvlDef['cost'] ?? 0);
        $duration = (int)($lvlDef['time'] ?? 0);
        $resType = (string)($lvlDef['res_type'] ?? '');
        $resKey = army_api_resKey($resType);
        if ($resKey === '') $resKey = 'elixir'; // fallback safe

        $result = army_research_start($mysqli, $user, $techId, $resKey, $cost, $duration, $cur);

        if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'lab_speedup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') army_api_error('Invalid method', 405);

        $techId = trim((string)army_api_get_post('tech_id', ''));
        $dryRun = (int)army_api_get_post('dry_run', 0);

        $active = army_research_get_active($mysqli, $userId);
        if (!$active) {
            army_api_json(['ok'=>true,'cost_gems'=>0,'message'=>'No active research']);
        }

        if ($techId !== '' && (string)($active['tech_id'] ?? '') !== $techId) {
            army_api_error('Active research is different', 400);
        }

        $now = time();
        $finish = (int)($active['finish_time'] ?? 0);
        $left = max(0, $finish - $now);
        $cost = army_gem_cost_for_seconds((int)$left);

        if ($dryRun) {
            army_api_json(['ok'=>true,'cost_gems'=>$cost]);
        }

        if ($cost <= 0) {
            army_api_json(['ok'=>true,'cost_gems'=>0]);
        }

        $mysqli->begin_transaction();
        try {
            $lock = $mysqli->prepare("SELECT gems FROM users WHERE id=? FOR UPDATE");
            if (!$lock) throw new RuntimeException('DB lock failed', 500);
            $lock->bind_param('i', $userId);
            $lock->execute();
            $u = $lock->get_result()->fetch_assoc();
            $lock->close();

            $gems = (int)($u['gems'] ?? 0);
            if ($gems < $cost) {
                throw new RuntimeException('Недостаточно гемов', 400);
            }

            $newGems = $gems - $cost;
            $updU = $mysqli->prepare("UPDATE users SET gems=? WHERE id=?");
            if (!$updU) throw new RuntimeException('DB update user failed', 500);
            $updU->bind_param('ii', $newGems, $userId);
            $updU->execute();
            $updU->close();

            // Mark research complete
            $rid = (int)($active['id'] ?? 0);
            $updR = $mysqli->prepare("UPDATE player_research SET status='active', finish_time=0 WHERE id=? AND user_id=?");
            if (!$updR) throw new RuntimeException('DB update research failed', 500);
            $updR->bind_param('ii', $rid, $userId);
            $updR->execute();
            $updR->close();

            $mysqli->commit();
            army_api_json(['ok'=>true,'cost_gems'=>$cost,'gems_left'=>$newGems]);
        } catch (Throwable $e) {
            $mysqli->rollback();
            army_api_error($e->getMessage(), 400);
        }
    }



    army_api_error('Unknown action', 404);

} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 100 || $code > 599) $code = 500;
    http_response_code($code);
    if (ob_get_length()) { @ob_clean(); }
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}
