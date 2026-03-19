<?php

// Ensure gem cost helper is available even before visiting Barracks
$__army_helpers = __DIR__ . '/../../system/army_helpers.php';
if (file_exists($__army_helpers)) { require_once $__army_helpers; }
/**
 * app/locations/defense.php
 * Локация: Оборона (Defense + Walls + Traps)
 * Endpoint: ajax.php?page=defense
 *
 * Принцип как в storage/production:
 *  - views: main, list, detail, buy, upgrade
 *  - Таблицы/модалки: выбрать тип → построить/список → обзор → улучшить
 *
 * Вкладки-фильтры (5): Земля, Воздух, Земля+Воздух, Стены, Ловушки
 */
// Normalize game data: in this project system/game_data.php returns a nested array.
// Defense location expects a flat map of building configs.
if (isset($game_data) && is_array($game_data) && isset($game_data['buildings']) && is_array($game_data['buildings'])) {
    $game_data = $game_data['buildings'];
}


// -----------------------------------------------------------------------------
// Helpers (unique names)
// -----------------------------------------------------------------------------

function defense_loc_format_amount($value): string {
    if ($value === null) return '0';
    $value = (int)$value;
    if ($value >= 1000000) return number_format($value / 1000000, 1, '.', ',') . 'M';
    if ($value >= 1000) return number_format($value / 1000, 1, '.', ',') . 'K';
    return number_format($value, 0, '.', ',');
}

function defense_loc_format_time(int $time): string {
    $days = (int)floor($time / 86400);
    $hours = (int)floor(($time % 86400) / 3600);
    $minutes = (int)floor(($time % 3600) / 60);
    $seconds = (int)($time % 60);
    $out = '';
    if ($days > 0) $out .= $days . 'д ';
    if ($hours > 0) $out .= $hours . 'ч ';
    if ($minutes > 0) $out .= $minutes . 'м ';
    if ($seconds > 0 || $out === '') $out .= $seconds . 'с';
    return trim($out);
}

function defense_loc_res_icon(string $res_const): string {
    $res_const = (string)$res_const;
    if ($res_const === 'gold' || $res_const === 'RES_GOLD') return '/images/icons/gold.png';
    if ($res_const === 'elixir' || $res_const === 'RES_ELIXIR') return '/images/icons/elixir.png';
    if ($res_const === 'dark_elixir' || $res_const === 'RES_DARK') return '/images/icons/elixir.png';
    if ($res_const === 'gems' || $res_const === 'RES_GEMS') return '/images/icons/gems.png';
    return '/images/icons/gold.png';
}

function defense_loc_res_pretty($res_type): string {
    $r = is_string($res_type) ? $res_type : '';
    if ($r === 'RES_GOLD' || $r === 'gold') return 'Золото';
    if ($r === 'RES_ELIXIR' || $r === 'elixir') return 'Эликсир';
    if ($r === 'RES_DARK' || $r === 'dark_elixir') return 'Тёмный эликсир';
    if ($r === 'RES_GEMS' || $r === 'gems') return 'Гемы';
    return $r !== '' ? $r : '—';
}

function defense_loc_can_afford(array $user, $res_type, int $cost): bool {
    if ($cost <= 0) return true;

    // стены могут иметь массив вариантов (gold/elixir) — достаточно одного доступного
    $types = is_array($res_type) ? $res_type : [$res_type];

    foreach ($types as $rt) {
        $rt = (string)$rt;
        $bal = 0;
        if ($rt === 'RES_GOLD' || $rt === 'gold') $bal = (int)($user['gold'] ?? 0);
        else if ($rt === 'RES_ELIXIR' || $rt === 'elixir') $bal = (int)($user['elixir'] ?? 0);
        else if ($rt === 'RES_DARK' || $rt === 'dark_elixir') $bal = (int)($user['dark_elixir'] ?? 0);
        else if ($rt === 'RES_GEMS' || $rt === 'gems') $bal = (int)($user['gems'] ?? 0);

        if ($bal >= $cost) return true;
    }

    return false;
}

/**
 * Extract canonical (res_type,cost,time) from a level row.
 * Project configs are not uniform: some use {res_type,cost,time}, others use {gold/elixir/dark,...} keys.
 */
function defense_loc_extract_cost_res_time(array $lvl): array {
    // Canonical keys used in some configs
    $res_type = $lvl['res_type'] ?? ($lvl['resource'] ?? null);
    $cost = 0;

    // cost may be scalar or array
    if (isset($lvl['cost'])) {
        if (is_array($lvl['cost'])) {
            // e.g. ['gold'=>1000] or ['RES_GOLD'=>1000]
            foreach ($lvl['cost'] as $k => $v) {
                if ((int)$v <= 0) continue;
                $res_type = $k;
                $cost = (int)$v;
                break;
            }
        } else {
            $cost = (int)$lvl['cost'];
        }
    }

    // fallback: many projects use explicit cost fields
    if (!$res_type || $cost <= 0) {
        $candidates = [
            'gold' => ['gold','gold_cost','cost_gold','price_gold','build_gold','build_cost_gold','upgrade_gold','upgrade_cost_gold','build_cost'],
            'elixir' => ['elixir','elixir_cost','cost_elixir','price_elixir','build_elixir','build_cost_elixir','upgrade_elixir','upgrade_cost_elixir'],
            'dark_elixir' => ['dark','dark_elixir','dark_cost','cost_dark','price_dark','build_dark','build_cost_dark','upgrade_dark','upgrade_cost_dark'],
            'gems' => ['gems','gem','gem_cost','gems_cost']
        ];
        foreach ($candidates as $rtype => $keys) {
            foreach ($keys as $k) {
                if (isset($lvl[$k]) && (int)$lvl[$k] > 0) {
                    $res_type = $rtype;
                    $cost = (int)$lvl[$k];
                    break 2;
                }
            }
        }
    }

    // also allow nested like {price: {gold:123}}
    if ((!$res_type || $cost <= 0) && isset($lvl['price']) && is_array($lvl['price'])) {
        foreach ($lvl['price'] as $k => $v) {
            if ((int)$v <= 0) continue;
            $res_type = $k;
            $cost = (int)$v;
            break;
        }
    }

    // time seconds: many keys exist
    $time = 0;
    foreach (['time','time_sec','build_time','build_time_sec','upgrade_time','upgrade_time_sec','duration','duration_sec','seconds','secs'] as $tk) {
        if (isset($lvl[$tk]) && (int)$lvl[$tk] > 0) { $time = (int)$lvl[$tk]; break; }
    }

    return [$res_type, $cost, $time];
}

function defense_loc_gem_cost_for_seconds(int $seconds): int {
    $seconds = max(0, (int)$seconds);
    if ($seconds <= 0) return 0;
    if (function_exists('army_gem_cost_for_seconds')) {
        return (int)army_gem_cost_for_seconds($seconds);
    }
    // Conservative fallback: 1 gem per minute, min 1
    return max(1, (int)ceil($seconds / 60));
}

function defense_loc_cost_html(array $stats): string {
    $cost = (int)($stats['cost'] ?? 0);
    $res = $stats['res_type'] ?? 'RES_GOLD';
    if ($cost <= 0) return '—';

    // может быть массив вариантов (например, стены: золото/эликсир)
    $items = is_array($res) ? $res : [$res];
    $parts = [];

    foreach ($items as $rc) {
        $rc = (string)$rc;
        $icon = defense_loc_res_icon($rc);
        $parts[] = defense_loc_format_amount($cost)
            . ' <img src="' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" width="14" height="14" style="vertical-align:-2px;">';
    }
    return implode(' <span class="defense-cost-or">или</span> ', $parts);
}




function defense_loc_targets_pretty($targets): string {
    $t = (string)$targets;
    if ($t === 'ground') return 'Земля';
    if ($t === 'air') return 'Воздух';
    if ($t === 'air_ground' || $t === 'both') return 'Земля+Воздух';
    return $t;
}

function defense_loc_attack_type_pretty($attack_type): string {
    // Только чистое отображение значения. Логику fallback держим в defense_loc_attack_type_label().
    $a = strtolower(trim((string)$attack_type));
    $map = [
        'single' => 'Одиночная',
        'splash' => 'По области',
        'aoe'    => 'По области',
        'splash_global' => 'По области (глобальная)',
        'multi'  => 'Множественная',
        'multi_target' => 'Множественная',
        'burst'  => 'Очередь',
        'beam'   => 'Луч',
        'push'   => 'Отталкивание',
        'none'   => 'Не атакует',
    ];
    return $map[$a] ?? ($a !== '' ? $a : '—');
}


function defense_loc_targets_label(string $building_id, array $cfg): string {
    return defense_loc_target_kind_for_cfg($building_id, $cfg);
}

function defense_loc_attack_type_label(array $cfg): string {
    $raw = (string)($cfg['attack_type'] ?? '');
    if ($raw !== '') return defense_loc_attack_type_pretty($raw);

    // Fallback: если в cfg не задано явно, берём из effects (то же, что используется в "Поведение в бою")
    $effects = $cfg['effects'] ?? [];
    if (is_array($effects) && $effects) {
        if (in_array('spell', $effects, true)) return 'Заклинание';
        if (in_array('push', $effects, true)) return 'Отталкивание';
        if (in_array('beam_ramp', $effects, true)) return 'Луч';
        if (in_array('aoe', $effects, true)) return 'По области';
        if (in_array('multi_target', $effects, true)) return 'Множественная';
        if (in_array('rapid_fire', $effects, true)) return 'Скорострельная';
        if (in_array('single_target', $effects, true)) return 'Одиночная';
    }

    return 'Одиночная';
}



function defense_loc_target_kind_for_cfg(string $building_id, array $cfg): string {
    $bid = strtolower(trim($building_id));
    $btype = strtolower((string)($cfg['type'] ?? ''));

    // 1) Если точные цели заданы в базе — используем их
    if (!empty($cfg['targets'])) return defense_loc_targets_pretty((string)$cfg['targets']);
    if (!empty($cfg['target']))  return defense_loc_targets_pretty((string)$cfg['target']);

    // 1.5) Ловушки: в данных часто нет targets, но в игре они строго определены
    if ($btype === (defined('TYPE_TRAP') ? TYPE_TRAP : 'trap')) {
        // Воздушные ловушки
        if (in_array($bid, ['air_bomb', 'seeking_air_mine'], true)) return 'Воздух';
        // Остальные — по земле
        return 'Земля';
    }

    // 2) Fallback по effects (чтобы не было ситуации "вверху есть, а тут прочерк")
    $effects = $cfg['effects'] ?? [];
    if (is_array($effects) && $effects) {
        if (in_array('air_only', $effects, true)) return 'Воздух';
        if (in_array('anti_air', $effects, true) && !in_array('ground_only', $effects, true)) return 'Воздух';
        if (in_array('ground_only', $effects, true)) return 'Земля';
        if (in_array('air_ground', $effects, true)) return 'Земля+воздух';
    }

    // 3) Последний шанс — по типу здания (если в данных нет targets вообще)
    // Большинство защит в игре бьёт по земле, если явно не ПВО.
    return 'Земля';
}


function defense_loc_attack_speed(string $building_id, array $cfg): float {
    // Не используем статические/примерные значения: скорость атаки должна приходить
    // из базы. Если её нет — возвращаем 0 (UI покажет "—").
    foreach (['attack_speed', 'hit_speed', 'attack_rate', 'rate_of_fire'] as $k) {
        if (isset($cfg[$k]) && is_numeric($cfg[$k])) return (float)$cfg[$k];
    }
    return 0.0;
}

function defense_loc_damage_per_attack($a, $b = null, $c = null): int {
    /*
      Поддержка двух порядков аргументов (из-за старых вызовов в файле):
      1) (array $levelStats, string $building_id, array $cfg)
      2) (string $building_id, array $cfg, array $levelStats)
    */
    $levelStats = [];
    $building_id = '';
    $cfg = [];

    if (is_array($a) && is_string($b) && is_array($c)) {
        $levelStats = $a;
        $building_id = $b;
        $cfg = $c;
    } elseif (is_string($a) && is_array($b) && is_array($c)) {
        $building_id = $a;
        $cfg = $b;
        $levelStats = $c;
    } elseif (is_array($a)) {
        $levelStats = $a;
        $building_id = is_string($b) ? $b : '';
        $cfg = is_array($c) ? $c : [];
    } else {
        $building_id = is_string($a) ? $a : '';
        $cfg = is_array($b) ? $b : [];
        $levelStats = is_array($c) ? $c : [];
    }

    // Если в game_data есть "damage" (обычно ловушки) — берём его
    if (isset($levelStats['damage'])) return (int)$levelStats['damage'];

    // Если есть явный урон за удар — берём его
    foreach (['damage_per_attack', 'dpa'] as $k) {
        if (isset($levelStats[$k]) && is_numeric($levelStats[$k])) return (int)$levelStats[$k];
    }

    // Не оцениваем через "DPS * скорость" (иначе это будет угадайка).
    return 0;
}

function defense_loc_calc_xp(int $cost, int $time): int {
    // XP в CoC зависит от времени; здесь делаем адекватную формулу без нулей.
    // Чем дольше и дороже — тем больше XP.
    $t = max(0, $time);
    $c = max(0, $cost);
    $xp = (int)round(sqrt($t / 60.0) + log(max(2.0, $c), 10) * 3.0);
    return max(1, $xp);
}

function defense_loc_building_image_url(string $building_id, int $level): string {
    $building_id = strtolower(trim($building_id));
    $level = max(1, (int)$level);

    // Карты соответствия ID -> папка/шаблон файлов
    // Формат: 'id' => ['dir' => 'Folder', 'patterns' => ['Name{L}.png', ...]]
    static $map = [
        // Базовая оборона
        'cannon' => ['dir' => 'Cannon', 'patterns' => ['Cannon{L}.png', 'Cannon{L}B.png']],
        'archer_tower' => ['dir' => 'Archer_Tower', 'patterns' => ['Archer_Tower{L}.png']],
        'mortar' => ['dir' => 'Mortar', 'patterns' => ['Mortar{L}.png', 'Mortar{L}B.png']],
        'air_defense' => ['dir' => 'Air_Defense', 'patterns' => ['Air_Defense{L}.png']],
        'wizard_tower' => ['dir' => 'Wizard_Tower', 'patterns' => ['Wizard_Tower{L}.png']],
        'air_sweeper' => ['dir' => 'Air_Sweeper', 'patterns' => ['Air_Sweeper{L}.png']],
        'hidden_tesla' => ['dir' => 'Hidden_Tesla', 'patterns' => ['Hidden_Tesla{L}.png']],
        'bomb_tower' => ['dir' => 'Bomb_Tower', 'patterns' => ['Bomb_Tower{L}.png']],
        'x_bow' => ['dir' => 'X-Bow_Ground', 'patterns' => ['X-Bow{L}_Ground.png']],
        'inferno_tower' => ['dir' => 'Inferno_Tower_Single', 'patterns' => ['Inferno_Tower{L}_Single.png']],
        'eagle_artillery' => ['dir' => 'Eagle_Artillery', 'patterns' => ['Eagle_Artillery{L}.png']],
        'scattershot' => ['dir' => 'Scattershot', 'patterns' => ['Scattershot{L}.png']],
        'spell_tower' => ['dir' => 'Spell_Tower_Rage', 'patterns' => ['Spell_Tower{L}_Rage.png']],
        'monolith' => ['dir' => 'Monolith', 'patterns' => ['Monolith{L}.png']],
        'ricochet_cannon' => ['dir' => 'Ricochet_Cannon', 'patterns' => ['Ricochet_Cannon{L}.png']],
        'multi_archer_tower' => ['dir' => 'Multi-Archer_Tower', 'patterns' => ['Multi-Archer_Tower{L}.png']],
        'builder_hut' => ['dir' => 'Builders_Hut', 'patterns' => ['Builders_Hut{L}.png', 'Builders_Hut.png']],
        'multi_gear_tower' => ['dir' => 'Multi-Gear_Tower_LongRange', 'patterns' => ['Multi-Gear_Tower{L}_LongRange.png']],
        'firespitter' => ['dir' => 'Firespitter', 'patterns' => ['Firespitter{L}.png']],
        'revenge_tower' => ['dir' => 'Revenge_Tower_Stage3', 'patterns' => ['Revenge_Tower{L}_Stage3.png']],
        'super_wizard_tower' => ['dir' => 'Super_Wizard_Tower', 'patterns' => ['Super_Wizard_Tower{L}.png']],

        // Ловушки/стены
        'bomb' => ['dir' => 'Bomb', 'patterns' => ['Bomb{L}.png']],
        'spring_trap' => ['dir' => 'Spring_Trap', 'patterns' => ['Spring_Trap{L}.png']],
        'giant_bomb' => ['dir' => 'Giant_Bomb', 'patterns' => ['Giant_Bomb{L}.png']],
        'giga_bomb' => ['dir' => 'Giga_Bomb', 'patterns' => ['Giga_Bomb{L}.png']],
        'air_bomb' => ['dir' => 'Air_Bomb', 'patterns' => ['Air_Bomb{L}.png']],
        'seeking_air_mine' => ['dir' => 'Seeking_Air_Mine', 'patterns' => ['Seeking_Air_Mine{L}.png']],
        'skeleton_trap' => ['dir' => 'SkeletonTrap', 'patterns' => ['SkeletonTrap{L}.png']],
        'tornado_trap' => ['dir' => 'Tornado_Trap', 'patterns' => ['Tornado_Trap{L}.png']],
        'wall' => ['dir' => 'Wall', 'patterns' => ['Wall{L}.png']],
    ];

    $docroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

    if (isset($map[$building_id])) {
        $dir = $map[$building_id]['dir'];
        $patterns = $map[$building_id]['patterns'];

        // 1) Пытаемся по точному уровню
        foreach ($patterns as $pat) {
            $file = str_replace('{L}', (string)$level, $pat);
            $url = "/images/building/{$dir}/{$file}";
            if ($docroot && file_exists($docroot . $url)) return $url;
        }

        // 2) Если точного уровня нет — ищем ближайший меньший (чтобы не показывать placeholder)
        if ($docroot && is_dir($docroot . "/images/building/{$dir}")) {
            for ($l = $level - 1; $l >= 1; $l--) {
                foreach ($patterns as $pat) {
                    $file = str_replace('{L}', (string)$l, $pat);
                    $url = "/images/building/{$dir}/{$file}";
                    if (file_exists($docroot . $url)) return $url;
                }
            }

            // 3) И последний шанс — любой первый png в папке
            $glob = glob($docroot . "/images/building/{$dir}/*.png");
            if ($glob && isset($glob[0])) {
                $rel = str_replace($docroot, '', (string)$glob[0]);
                if ($rel) return $rel;
            }
        }
    }

    return '/images/building/defense.png';
}

// -----------------------------------------------------------------------------
// JSON actions (used by defense.js)
// -----------------------------------------------------------------------------

// Speedup building construction/upgrade with gems (instance-based: player_buildings.id)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'defense_speedup') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    try {
        global $mysqli;

        // CSRF
        $csrf = (string)($_POST['csrf_token'] ?? ($_POST['csrf'] ?? ''));
        if (!function_exists('check_csrf') || !check_csrf($csrf)) {
            http_response_code(403);
            echo json_encode(['ok'=>false,'error'=>'CSRF'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // Helpers for gem cost
        require_once __DIR__ . '/../../system/army_helpers.php';

        // Logged in
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(401);
            echo json_encode(['ok'=>false,'error'=>'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pbId = (int)($_POST['player_building_id'] ?? 0);
        $quote = (int)($_POST['quote'] ?? 0);
        if ($pbId <= 0) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'player_building_id required'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if (function_exists('finalizeCompletedBuildTimers')) {
            finalizeCompletedBuildTimers($mysqli, $uid);
        }

        $stmt = $mysqli->prepare("SELECT id, building_id, level, status, finish_time, target_level FROM player_buildings WHERE id=? AND user_id=? LIMIT 1");
        if (!$stmt) throw new RuntimeException('DB error', 500);
        $stmt->bind_param('ii', $pbId, $uid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            http_response_code(404);
            echo json_encode(['ok'=>false,'error'=>'Здание не найдено'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $status = (string)($row['status'] ?? 'active');
        $finish = (int)($row['finish_time'] ?? 0);
        $now = time();
        if (!in_array($status, ['constructing','upgrading'], true) || $finish <= $now) {
            http_response_code(400);
            echo json_encode(['ok'=>false,'error'=>'Здание сейчас не строится/не улучшается'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $left = max(0, $finish - $now);
        $cost = army_gem_cost_for_seconds((int)$left);

        if ($quote > 0) {
            echo json_encode(['ok'=>true,'quote'=>true,'cost_gems'=>$cost,'time_left'=>$left], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $mysqli->begin_transaction();
        try {
            // lock user gems
            $lock = $mysqli->prepare("SELECT gems FROM users WHERE id=? FOR UPDATE");
            if (!$lock) throw new RuntimeException('DB lock user failed', 500);
            $lock->bind_param('i', $uid);
            $lock->execute();
            $u = $lock->get_result()->fetch_assoc();
            $lock->close();

            $gems = (int)($u['gems'] ?? 0);
            if ($gems < $cost) throw new RuntimeException('Недостаточно гемов', 400);

            $newGems = $gems - $cost;
            $upU = $mysqli->prepare("UPDATE users SET gems=? WHERE id=?");
            if (!$upU) throw new RuntimeException('DB update user failed', 500);
            $upU->bind_param('ii', $newGems, $uid);
            $upU->execute();
            $upU->close();

            // finish now
            $finishNow = time();
            $upB = $mysqli->prepare("UPDATE player_buildings SET finish_time=? WHERE id=? AND user_id=?");
            if (!$upB) throw new RuntimeException('DB update building failed', 500);
            $upB->bind_param('iii', $finishNow, $pbId, $uid);
            $upB->execute();
            $upB->close();

            // apply finalize (sets status active, level=target_level, etc.)
            if (function_exists('finalizeCompletedBuildTimers')) {
                finalizeCompletedBuildTimers($mysqli, $uid);
            }

            $mysqli->commit();
            echo json_encode(['ok'=>true,'cost_gems'=>$cost,'gems_left'=>$newGems], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $mysqli->rollback();
            $code = (int)$e->getCode();
            if ($code < 400 || $code > 599) $code = 400;
            http_response_code($code);
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) $code = 500;
        http_response_code($code);
        $msg = ($code >= 400 && $code < 500) ? $e->getMessage() : 'Внутренняя ошибка сервера.';
        echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Массовое улучшение стен (без строителей, instant как в CoC)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'walls_bulk_upgrade') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        global $mysqli, $game_data;
        $user = getUser($mysqli);
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) throw new RuntimeException('Не авторизовано', 401);

        $from_level = (int)($_POST['from_level'] ?? 0);
        $qty = (int)($_POST['qty'] ?? 0);
        $res = (string)($_POST['res'] ?? 'gold');
        if ($from_level <= 0) throw new RuntimeException('Некорректный уровень', 400);
        if ($qty <= 0) throw new RuntimeException('Некорректное количество', 400);
        if (!in_array($res, ['gold','elixir','dark_elixir'], true)) $res = 'gold';

        $wallCfg = $game_data['wall'] ?? null;
        if (!$wallCfg) throw new RuntimeException('Конфиг стен не найден', 500);
        $levels = (array)($wallCfg['levels'] ?? []);
        $next_level = $from_level + 1;
        if (!isset($levels[$from_level]) || !isset($levels[$next_level])) {
            throw new RuntimeException('Нельзя улучшить этот уровень', 400);
        }

        $next_stats = (array)$levels[$next_level];
        $cost = (int)($next_stats['cost'] ?? 0);
        $time = (int)($next_stats['time'] ?? 0);
        $th_req = (int)($next_stats['th_req'] ?? 1);
        $th_lvl = (int)($user['townhall_lvl'] ?? 1);
        if ($th_lvl < $th_req) {
            throw new RuntimeException('Требуется Ратуша уровня ' . $th_req . '.', 400);
        }
        if ($cost <= 0) throw new RuntimeException('Некорректная стоимость улучшения', 500);
        if ($time > 0) {
            // На случай, если в будущем сделают стены не instant.
            throw new RuntimeException('Массовое улучшение доступно только для мгновенных улучшений', 400);
        }

        // res_type может быть строкой или массивом вариантов (как в CoC: золото/эликсир)
        $res_type = $next_stats['res_type'] ?? 'gold';
        $allowedRes = [];
        if (is_array($res_type)) {
            foreach ($res_type as $x) {
                $k = resourceConstToUserKey($x);
                if ($k) $allowedRes[] = $k;
            }
        } else {
            $k = resourceConstToUserKey($res_type);
            if ($k) $allowedRes[] = $k;
        }
        if (!$allowedRes) $allowedRes = ['gold'];

        // В офф CoC улучшение стен за эликсир доступно начиная с 9 уровня (т.е. при апгрейде ДО 9 и выше).
        // Если в конфиге по ошибке включён эликсир на ранних уровнях — принудительно скрываем.
        if ($next_level < 9) {
            $allowedRes = array_values(array_filter($allowedRes, function($x){ return $x !== 'elixir'; }));
            if (!$allowedRes) $allowedRes = ['gold'];
        }
        if (!in_array($res, $allowedRes, true)) {
            // Важно: не делаем "тихий" фоллбек на золото.
            // Иначе UI пишет "нельзя", а апгрейд всё равно проходит за золото.
            throw new RuntimeException('Этот ресурс недоступен для данного уровня стен.', 400);
        }

        // Сколько стен этого уровня вообще есть
        $eligible = 0;
        $stmtC = $mysqli->prepare("SELECT COUNT(*) c FROM player_buildings WHERE user_id=? AND building_id='wall' AND level=? AND status='active'");
        $stmtC->bind_param('ii', $uid, $from_level);
        $stmtC->execute();
        $rowC = $stmtC->get_result()->fetch_assoc();
        $stmtC->close();
        $eligible = (int)($rowC['c'] ?? 0);
        if ($eligible <= 0) {
            echo json_encode(['ok'=>true,'upgraded'=>0,'msg'=>'Нет стен этого уровня для улучшения.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $have = (int)($user[$res] ?? 0);
        $maxByRes = (int)floor($have / $cost);
        $qty = max(1, min($qty, $eligible, $maxByRes));
        if ($qty <= 0) {
            throwNotEnoughResources($res, $cost, $have, 'улучшение стен');
        }

        $totalCost = $cost * $qty;

        $mysqli->begin_transaction();
        try {
            // Списываем ресурсы
            $newBalance = $have - $totalCost;
            $sqlU = "UPDATE users SET {$res}=? WHERE id=?";
            $stmtU = $mysqli->prepare($sqlU);
            $stmtU->bind_param('ii', $newBalance, $uid);
            $stmtU->execute();
            $stmtU->close();

            // Поднимаем уровень у первых qty стен этого уровня
            $sqlB = "UPDATE player_buildings SET level=?, status='active', target_level=NULL, finish_time=0 WHERE user_id=? AND building_id='wall' AND level=? AND status='active' ORDER BY id ASC LIMIT ?";
            $stmtB = $mysqli->prepare($sqlB);
            $stmtB->bind_param('iiii', $next_level, $uid, $from_level, $qty);
            $stmtB->execute();
            $affected = (int)$stmtB->affected_rows;
            $stmtB->close();

            $mysqli->commit();

            // обновляем user локально
            $user[$res] = $newBalance;

            echo json_encode([
                'ok' => true,
                'upgraded' => $affected,
                'from_level' => $from_level,
                'to_level' => $next_level,
                'spent' => $totalCost,
                'res' => $res,
                'balance' => [
                    'gold' => (int)($user['gold'] ?? 0),
                    'elixir' => (int)($user['elixir'] ?? 0),
                    'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
                    'gems' => (int)($user['gems'] ?? 0),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) $code = 400;
        http_response_code($code);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Предпросмотр массового улучшения стен (только расчёт, без списаний)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'walls_bulk_preview') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        global $mysqli, $game_data;
        $user = getUser($mysqli);
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) throw new RuntimeException('Не авторизовано', 401);

        $from_level = (int)($_POST['from_level'] ?? 0);
        $qty_req = (int)($_POST['qty'] ?? 0);
        $res = (string)($_POST['res'] ?? 'gold');
        if ($from_level <= 0) throw new RuntimeException('Некорректный уровень', 400);
        if ($qty_req <= 0) throw new RuntimeException('Некорректное количество', 400);
        if (!in_array($res, ['gold','elixir','dark_elixir'], true)) $res = 'gold';

        $wallCfg = $game_data['wall'] ?? null;
        if (!$wallCfg) throw new RuntimeException('Конфиг стен не найден', 500);
        $levels = (array)($wallCfg['levels'] ?? []);
        $next_level = $from_level + 1;
        if (!isset($levels[$from_level]) || !isset($levels[$next_level])) {
            throw new RuntimeException('Нельзя улучшить этот уровень', 400);
        }

        $next_stats = (array)$levels[$next_level];
        $cost = (int)($next_stats['cost'] ?? 0);
        $time = (int)($next_stats['time'] ?? 0);
        $th_req = (int)($next_stats['th_req'] ?? 1);
        $th_lvl = (int)($user['townhall_lvl'] ?? 1);
        if ($th_lvl < $th_req) {
            throw new RuntimeException('Требуется Ратуша уровня ' . $th_req . '.', 400);
        }
        if ($cost <= 0) throw new RuntimeException('Некорректная стоимость улучшения', 500);
        if ($time > 0) {
            throw new RuntimeException('Массовое улучшение доступно только для мгновенных улучшений', 400);
        }

        $res_type = $next_stats['res_type'] ?? 'gold';
        $allowedRes = [];
        if (is_array($res_type)) {
            foreach ($res_type as $x) {
                $k = resourceConstToUserKey($x);
                if ($k) $allowedRes[] = $k;
            }
        } else {
            $k = resourceConstToUserKey($res_type);
            if ($k) $allowedRes[] = $k;
        }
        if (!$allowedRes) $allowedRes = ['gold'];

        if ($next_level < 9) {
            $allowedRes = array_values(array_filter($allowedRes, function($x){ return $x !== 'elixir'; }));
            if (!$allowedRes) $allowedRes = ['gold'];
        }

        $stmtC = $mysqli->prepare("SELECT COUNT(*) c FROM player_buildings WHERE user_id=? AND building_id='wall' AND level=? AND status='active'");
        $stmtC->bind_param('ii', $uid, $from_level);
        $stmtC->execute();
        $rowC = $stmtC->get_result()->fetch_assoc();
        $stmtC->close();
        $eligible = (int)($rowC['c'] ?? 0);

        $have = (int)($user[$res] ?? 0);
        $maxByRes = ($cost > 0) ? (int)floor($have / $cost) : 0;
        $qty_possible = max(0, min($qty_req, $eligible, $maxByRes));

        echo json_encode([
            'ok' => true,
            'from_level' => $from_level,
            'to_level' => $next_level,
            'cost_each' => $cost,
            'eligible' => $eligible,
            'qty_requested' => $qty_req,
            'qty_possible' => $qty_possible,
            'res_requested' => $res,
            'allowed_res' => $allowedRes,
            'have' => $have,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) $code = 400;
        http_response_code($code);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Предпросмотр авто-улучшения стен (без списания/изменений)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'walls_auto_preview') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        global $mysqli, $game_data;
        $user = getUser($mysqli);
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) throw new RuntimeException('Не авторизовано', 401);

        $pref = (string)($_POST['pref'] ?? 'gold');
        if (!in_array($pref, ['gold','elixir'], true)) $pref = 'gold';

        $wallCfg = $game_data['wall'] ?? null;
        if (!$wallCfg) throw new RuntimeException('Конфиг стен не найден', 500);
        $levels = (array)($wallCfg['levels'] ?? []);
        if (!$levels) throw new RuntimeException('У стен нет уровней в конфиге', 500);

        $maxLevel = 0;
        foreach ($levels as $k => $v) $maxLevel = max($maxLevel, (int)$k);
        if ($maxLevel < 2) {
            echo json_encode(['ok'=>true,'upgraded'=>0,'spent'=>['gold'=>0,'elixir'=>0],'steps'=>[]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $th_lvl = (int)($user['townhall_lvl'] ?? 1);
        $spent = ['gold'=>0,'elixir'=>0];
        $upgraded_total = 0;
        $perLevel = [];

        // локальные балансы для симуляции
        $bal = ['gold' => (int)($user['gold'] ?? 0), 'elixir' => (int)($user['elixir'] ?? 0)];

        for ($from = 1; $from < $maxLevel; $from++) {
            $to = $from + 1;
            if (!isset($levels[$to])) continue;
            $next_stats = (array)$levels[$to];

            $cost = (int)($next_stats['cost'] ?? 0);
            $time = (int)($next_stats['time'] ?? 0);
            $th_req = (int)($next_stats['th_req'] ?? 1);
            if ($cost <= 0) continue;
            if ($time > 0) continue;
            if ($th_lvl < $th_req) continue;

            // сколько стен этого уровня есть
            $eligible = 0;
            $stmtC = $mysqli->prepare("SELECT COUNT(*) c FROM player_buildings WHERE user_id=? AND building_id='wall' AND level=? AND status='active'");
            $stmtC->bind_param('ii', $uid, $from);
            $stmtC->execute();
            $rowC = $stmtC->get_result()->fetch_assoc();
            $stmtC->close();
            $eligible = (int)($rowC['c'] ?? 0);
            if ($eligible <= 0) continue;

            $res_type = $next_stats['res_type'] ?? 'gold';
            $allowedRes = [];
            if (is_array($res_type)) {
                foreach ($res_type as $x) {
                    $k = resourceConstToUserKey($x);
                    if ($k) $allowedRes[] = $k;
                }
            } else {
                $k = resourceConstToUserKey($res_type);
                if ($k) $allowedRes[] = $k;
            }
            if (!$allowedRes) $allowedRes = ['gold'];

            if ($to < 9) {
                $allowedRes = array_values(array_filter($allowedRes, function($x){ return $x !== 'elixir'; }));
                if (!$allowedRes) $allowedRes = ['gold'];
            }
            $resKey = in_array($pref, $allowedRes, true) ? $pref : $allowedRes[0];

            $have = (int)($bal[$resKey] ?? 0);
            $maxByRes = (int)floor($have / $cost);
            $qty = min($eligible, $maxByRes);
            if ($qty <= 0) continue;

            $totalCost = $cost * $qty;
            $bal[$resKey] = $have - $totalCost;

            $spent[$resKey] += $totalCost;
            $upgraded_total += $qty;
            $perLevel[] = ['from'=>$from,'to'=>$to,'qty'=>$qty,'res'=>$resKey,'cost_each'=>$cost];
        }

        echo json_encode([
            'ok' => true,
            'upgraded' => $upgraded_total,
            'spent' => $spent,
            'steps' => $perLevel,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) $code = 400;
        http_response_code($code);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// Автоматическое улучшение стен: повышает самые низкие уровни, пока хватает ресурсов/требований Ратуши.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && (string)$_POST['action'] === 'walls_auto_upgrade') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        global $mysqli, $game_data;
        $user = getUser($mysqli);
        $uid = (int)($user['id'] ?? 0);
        if ($uid <= 0) throw new RuntimeException('Не авторизовано', 401);

        $pref = (string)($_POST['pref'] ?? 'gold');
        if (!in_array($pref, ['gold','elixir'], true)) $pref = 'gold';

        $wallCfg = $game_data['wall'] ?? null;
        if (!$wallCfg) throw new RuntimeException('Конфиг стен не найден', 500);
        $levels = (array)($wallCfg['levels'] ?? []);
        if (!$levels) throw new RuntimeException('У стен нет уровней в конфиге', 500);

        $maxLevel = 0;
        foreach ($levels as $k => $v) $maxLevel = max($maxLevel, (int)$k);
        if ($maxLevel < 2) {
            echo json_encode(['ok'=>true,'upgraded'=>0,'msg'=>'Нечего улучшать.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $th_lvl = (int)($user['townhall_lvl'] ?? 1);
        $spent = ['gold'=>0,'elixir'=>0];
        $upgraded_total = 0;
        $perLevel = [];

        // Работаем транзакционно, чтобы баланс и стены совпадали.
        $mysqli->begin_transaction();
        try {
            for ($from = 1; $from < $maxLevel; $from++) {
                $to = $from + 1;
                if (!isset($levels[$to])) continue;
                $next_stats = (array)$levels[$to];

                $cost = (int)($next_stats['cost'] ?? 0);
                $time = (int)($next_stats['time'] ?? 0);
                $th_req = (int)($next_stats['th_req'] ?? 1);
                if ($cost <= 0) continue;
                if ($time > 0) continue; // автопрокачка только instant
                if ($th_lvl < $th_req) continue;

                // сколько стен этого уровня есть
                $eligible = 0;
                $stmtC = $mysqli->prepare("SELECT COUNT(*) c FROM player_buildings WHERE user_id=? AND building_id='wall' AND level=? AND status='active'");
                $stmtC->bind_param('ii', $uid, $from);
                $stmtC->execute();
                $rowC = $stmtC->get_result()->fetch_assoc();
                $stmtC->close();
                $eligible = (int)($rowC['c'] ?? 0);
                if ($eligible <= 0) continue;

                // выбираем ресурс
                $res_type = $next_stats['res_type'] ?? 'gold';
                $allowedRes = [];
                if (is_array($res_type)) {
                    foreach ($res_type as $x) {
                        $k = resourceConstToUserKey($x);
                        if ($k) $allowedRes[] = $k;
                    }
                } else {
                    $k = resourceConstToUserKey($res_type);
                    if ($k) $allowedRes[] = $k;
                }
                if (!$allowedRes) $allowedRes = ['gold'];

                // Эликсир для стен — только начиная с 9 уровня.
                if ($to < 9) {
                    $allowedRes = array_values(array_filter($allowedRes, function($x){ return $x !== 'elixir'; }));
                    if (!$allowedRes) $allowedRes = ['gold'];
                }
                $resKey = in_array($pref, $allowedRes, true) ? $pref : $allowedRes[0];

                $have = (int)($user[$resKey] ?? 0);
                $maxByRes = (int)floor($have / $cost);
                $qty = min($eligible, $maxByRes);
                if ($qty <= 0) continue;

                $totalCost = $cost * $qty;
                $newBalance = $have - $totalCost;

                // списываем
                $sqlU = "UPDATE users SET {$resKey}=? WHERE id=?";
                $stmtU = $mysqli->prepare($sqlU);
                $stmtU->bind_param('ii', $newBalance, $uid);
                $stmtU->execute();
                $stmtU->close();
                $user[$resKey] = $newBalance;

                // апгрейдим пачку
                $sqlB = "UPDATE player_buildings SET level=?, status='active', target_level=NULL, finish_time=0 WHERE user_id=? AND building_id='wall' AND level=? AND status='active' ORDER BY id ASC LIMIT ?";
                $stmtB = $mysqli->prepare($sqlB);
                $stmtB->bind_param('iiii', $to, $uid, $from, $qty);
                $stmtB->execute();
                $affected = (int)$stmtB->affected_rows;
                $stmtB->close();

                if ($affected > 0) {
                    $spent[$resKey] += $cost * $affected;
                    $upgraded_total += $affected;
                    $perLevel[] = [
                        'from' => $from,
                        'to' => $to,
                        'qty' => $affected,
                        'res' => $resKey,
                        'cost_each' => $cost,
                    ];
                }
            }

            $mysqli->commit();

            echo json_encode([
                'ok' => true,
                'upgraded' => $upgraded_total,
                'spent' => $spent,
                'steps' => $perLevel,
                'balance' => [
                    'gold' => (int)($user['gold'] ?? 0),
                    'elixir' => (int)($user['elixir'] ?? 0),
                    'dark_elixir' => (int)($user['dark_elixir'] ?? 0),
                    'gems' => (int)($user['gems'] ?? 0),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (Throwable $e) {
            $mysqli->rollback();
            throw $e;
        }
    } catch (Throwable $e) {
        $code = (int)$e->getCode();
        if ($code < 400 || $code > 599) $code = 400;
        http_response_code($code);
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}


function defense_loc_renderBalancePayload(mysqli $mysqli, array $user): string {
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

    return '<div class="js-balance-payload" style="display:none"'
        . ' data-gold="' . $gold . '"'
        . ' data-elixir="' . $elixir . '"'
        . ' data-dark_elixir="' . $dark_elixir . '"'
        . ' data-gems="' . $gems . '"'
        . ' data-cap_gold="' . $cap_gold . '"'
        . ' data-cap_elixir="' . $cap_elixir . '"'
        . ' data-cap_dark_elixir="' . $cap_dark . '"'
        . ' data-cap_gems="' . $cap_gems . '"'
        . ' data-delta_res="" data-delta_amt="0" data-collect_res="" data-collect_amt="0"'
        . '></div>';
}

function defense_loc_get_tab_defs(): array {
    return [
        'ground' => ['label' => 'Земля', 'hint' => 'Только наземные цели'],
        'air'    => ['label' => 'Воздух', 'hint' => 'Только воздушные цели'],
        'both'   => ['label' => 'Земля+Воздух', 'hint' => 'Земля и воздух'],
        'walls'  => ['label' => 'Стены', 'hint' => 'Укрепления без атаки'],
        'traps'  => ['label' => 'Ловушки', 'hint' => 'Сюрпризы для врага'],
    ];
}


function defense_loc_render_tabs(string $activeTab): string {
    $tabs = defense_loc_get_tab_defs();
    if (!isset($tabs[$activeTab])) $activeTab = 'ground';

    ob_start();
    ?>
    <!-- UI tabs are aligned to Barracks (coc-tabs/coc-tab) to reuse compact UI styles -->
    <div class="coc-tabs" role="tablist" aria-label="Оборона">
      <?php foreach ($tabs as $k => $t):
        $is = ($k === $activeTab);
        $cls = $is ? 'coc-tab active' : 'coc-tab';
      ?>
        <button
          type="button"
          class="<?= $cls ?>"
          role="tab"
          aria-selected="<?= $is ? 'true' : 'false' ?>"
          data-tab="<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>"
          onclick="defenseOpenTab('<?= htmlspecialchars($k, ENT_QUOTES, 'UTF-8') ?>')"
          title="<?= htmlspecialchars((string)($t['hint'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
        >
          <?= htmlspecialchars((string)($t['label'] ?? $k), ENT_QUOTES, 'UTF-8') ?>
        </button>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function defense_loc_collect_types(array $game_data, string $typeConst, array $preferredOrder = []): array {
    $types = [];
    foreach ($game_data as $bid => $cfg) {
        if (($cfg['type'] ?? '') === $typeConst) $types[] = (string)$bid;
    }
    $out = [];
    foreach ($preferredOrder as $x) {
        if (in_array($x, $types, true)) $out[] = $x;
    }
    foreach ($types as $x) {
        if (!in_array($x, $out, true)) $out[] = $x;
    }
    return $out;
}

function defense_loc_get_types_for_tab(array $game_data, string $tab): array {
    $defOrder = [
        'cannon','archer_tower','mortar','air_defense','wizard_tower','air_sweeper','hidden_tesla','bomb_tower',
        'x_bow','inferno_tower','eagle_artillery','scattershot','spell_tower','monolith','ricochet_cannon','multi_archer_tower',
        'builder_hut','multi_gear_tower','firespitter'
    ];

    $trapOrder = ['bomb','spring_trap','giant_bomb','giga_bomb','air_bomb','seeking_air_mine','skeleton_trap','tornado_trap'];

    $typeDefense = defined('TYPE_DEFENSE') ? TYPE_DEFENSE : 'defense';
    $typeTrap = defined('TYPE_TRAP') ? TYPE_TRAP : 'trap';
    $typeWall = defined('TYPE_WALL') ? TYPE_WALL : 'wall';

    if ($tab === 'walls') {
        return defense_loc_collect_types($game_data, $typeWall, ['wall']);
    }
    if ($tab === 'traps') {
        return defense_loc_collect_types($game_data, $typeTrap, $trapOrder);
    }

    // Defense tabs by targets
    $allDef = defense_loc_collect_types($game_data, $typeDefense, $defOrder);
    $need = $tab;
    if (!in_array($need, ['ground','air','both'], true)) $need = 'ground';

    $out = [];
    foreach ($allDef as $bid) {
        $cfg = $game_data[$bid] ?? null;
        if (!$cfg) continue;
        $t = (string)($cfg['targets'] ?? '');
if ($t === '') {
    // fallback-эвристика, чтобы тип не "терялся" если targets забыли прописать
    if (preg_match('/air|mine/i', $bid)) $t = 'air';
    else $t = 'ground';
}

if ($need === 'ground' && $t === 'ground') $out[] = $bid;
if ($need === 'air' && $t === 'air') $out[] = $bid;
if ($need === 'both' && ($t === 'air_ground' || $t === 'both')) $out[] = $bid;
    }

    return $out;
}

function defense_loc_cleanString($v, $max = 64): string {
    $v = (string)$v;
    $v = trim($v);
    if (function_exists('mb_substr')) return mb_substr($v, 0, (int)$max, 'UTF-8');
    return substr($v, 0, (int)$max);
}

function defense_loc_toInt($v): int {
    return (int)preg_replace('/[^0-9]/', '', (string)$v);
}


function defense_loc_get_level_stats(array $cfg, int $level): array {
    $levels = (array)($cfg['levels'] ?? []);
    if (!$levels) return [];
    if (isset($levels[$level])) return (array)$levels[$level];
    $s = (string)$level;
    if (isset($levels[$s])) return (array)$levels[$s];
    // Иногда уровни могут быть с ключами вида 'lvl1' и т.п. — пробуем найти по совпадению числа
    foreach ($levels as $k => $v) {
        if ((string)$k === $s) return (array)$v;
    }
    // fallback: первый доступный уровень
    foreach ($levels as $v) return (array)$v;
    return [];
}



function defense_loc_effect_meta(): array {
    // code => [icon, label]
    return [
        'aoe' => ['🌀', 'Урон по области'],
        'anti_air' => ['🛩️', 'ПВО'],
        'air_only' => ['🛩️', 'Только воздух'],
        'ground_only' => ['🦶', 'Только земля'],
        'air_ground' => ['🌍', 'Земля+воздух'],
        'single_target' => ['🎯', 'Одна цель'],
        // Один эмодзи, иначе twemoji превращает "🎯🎯" в 2 картинки и выглядит как дубль
        'multi_target' => ['🎯', 'Несколько целей'],
        'rapid_fire' => ['⚡', 'Скорострельность'],
        'long_range' => ['📡', 'Дальность'],
        'mode_switch' => ['🔁', 'Режимы'],
        'beam_ramp' => ['🔥', 'Нарастающий урон'],
        'high_damage' => ['💥', 'Высокий урон'],
        'push' => ['💨', 'Отталкивание'],
        'control' => ['🧊', 'Контроль'],
        'spell' => ['🪄', 'Заклинание'],
        'hidden' => ['🕵️', 'Скрытая'],
        'death_blast' => ['💥', 'Взрыв при разрушении'],
        'global' => ['🛰️', 'Глобальная атака'],
        'activation_threshold' => ['✅', 'Активируется по условию'],
        'percent_damage' => ['📈', 'Процентный урон'],
        'ricochet' => ['↩️', 'Рикошет'],
        'chain' => ['🔗', 'Цепной эффект'],
        'cone_split' => ['📐', 'Рассыпной удар'],
        'burn' => ['🔥', 'Огонь'],
        'ramp_with_destruction' => ['😡', 'Усиление по ходу боя'],
        'chain_lightning' => ['⚡', 'Цепная молния'],
        'burst' => ['⚡', 'Всплеск'],
        'support_defense' => ['🛠️', 'Поддержка'],
    ];
}

function defense_loc_render_effect_badges(array $effects): string {
    $meta = defense_loc_effect_meta();
    if (!$effects) return '';
    $out = '<div class="def-eff-badges" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;">';
    foreach ($effects as $code) {
        $code = (string)$code;
        if ($code === '') continue;
        $icon = $meta[$code][0] ?? '•';
        $label = $meta[$code][1] ?? $code;

        // Убираем дубли эмодзи в подписи (например: 🎯🎯 Несколько целей)
        $label = trim((string)$label);
        $iconStr = (string)$icon;
        if ($iconStr !== '' && $iconStr !== '•') {
            while (strpos($label, $iconStr) === 0) {
                $label = trim(substr($label, strlen($iconStr)));
            }
        } else {
            // общий случай: если подпись начинается с эмодзи/пиктограмм — убираем
            $label = preg_replace('/^[\p{So}\p{Sk}\p{Cs}]+\s*/u', '', $label);
        }
        $label = trim((string)$label);
        $out .= '<span class="def-eff-badge" data-eff="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '" style="display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:10px;background:rgba(0,0,0,.06);font-size:12px;line-height:1;">'
              . '<span style="font-size:14px;line-height:1;">' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '</span>'
              . '<span>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>'
              . '</span>';
    }
    $out .= '</div>';
    return $out;
}

function defense_loc_render_battle_block(string $building_id, array $cfg): string {
    $battle = (string)($cfg['battle_desc'] ?? '');
    $effects = $cfg['effects'] ?? [];
    if ($battle === '' && !$effects) return '';
    ob_start();
    ?>
    <div class="coc-panel coc-um-battle" style="margin-top:10px;">
      <div class="coc-panel-head" style="padding-bottom:8px;">
        <div class="coc-panel-title" style="font-size:12px;letter-spacing:.35px;">ПОВЕДЕНИЕ В БОЮ</div>
      </div>
      <div class="coc-panel-body" style="padding: 0 14px 14px 14px;">
        <?php if ($battle !== ''): ?>
          <div style="font-size:13px;line-height:1.35;opacity:.95;"><?= htmlspecialchars($battle, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?= defense_loc_render_effect_badges(is_array($effects) ? $effects : []) ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}


function defense_loc_render_stat_table(string $building_id, array $cfg, int $level, array $levelStats): string {
    $type = (string)($cfg['type'] ?? '');
    $isWall = ($type === (defined('TYPE_WALL') ? TYPE_WALL : 'wall'));
    $isTrap = ($type === (defined('TYPE_TRAP') ? TYPE_TRAP : 'trap'));

    $attack_speed = defense_loc_attack_speed($building_id, $cfg);

    $hp = isset($levelStats['hp']) ? (int)$levelStats['hp'] : null;
    $time = isset($levelStats['time']) ? (int)$levelStats['time'] : null;
    $th_req = isset($levelStats['th_req']) ? (int)$levelStats['th_req'] : 1;

    $dps = isset($levelStats['dps']) ? (int)$levelStats['dps'] : null;

    // Спец-поля (если есть)
    $push = isset($levelStats['push_strength']) ? (float)$levelStats['push_strength'] : null;
    $spell = isset($levelStats['spell']) ? (string)$levelStats['spell'] : '';
    $dps_multi = isset($levelStats['dps_multi']) ? (int)$levelStats['dps_multi'] : null;
    $dps_single_max = isset($levelStats['dps_single_max']) ? (int)$levelStats['dps_single_max'] : null;
    $bonus_pct = isset($levelStats['bonus_pct']) ? (int)$levelStats['bonus_pct'] : null;
    $death_dmg = isset($levelStats['death_dmg']) ? (int)$levelStats['death_dmg'] : (isset($levelStats['death_damage']) ? (int)$levelStats['death_damage'] : null);
    $damage = isset($levelStats['damage']) ? (int)$levelStats['damage'] : null;

    $xp = defense_loc_calc_xp((int)($levelStats['cost'] ?? 0), (int)($time ?? 0));

    // Тип атаки / цель
    // Тип атаки: используем label с fallback, чтобы в модалках не было прочерков
    $attack_type = $isWall ? 'Не атакует' : defense_loc_attack_type_label($cfg);
    if ($isTrap) $attack_type = 'Ловушка';

    $target_type = defense_loc_target_kind_for_cfg($building_id, $cfg);
    if ($isWall) $target_type = '—';

    $speedTxt = ($attack_speed > 0 && !$isWall)
        ? (rtrim(rtrim(number_format($attack_speed, 2, '.', ''), '0'), '.') . 'с')
        : '';

    $timeTxt = ($time === null || (int)$time <= 0) ? '' : defense_loc_format_time((int)$time);
    $hpTxt  = ($hp === null || (int)$hp <= 0) ? '' : number_format((int)$hp, 0, '', ' ');

    // Ряды: показываем ТОЛЬКО применимые характеристики (без прочерков/нулей)
    $rows = [];
    $rows[] = ['🏷️','Уровень', (string)(int)$level];

    // Урон
    if ($isTrap) {
        if ($damage !== null && (int)$damage > 0) {
            $rows[] = ['💣','Урон при срабатывании', number_format((int)$damage, 0, '', ' ')];
        }
        if (isset($levelStats['capacity']) && (int)$levelStats['capacity'] > 0) {
            // Spring Trap: max spring weight (в "местах")
            $rows[] = ['🪶','Макс. вес', (string)(int)$levelStats['capacity']];
        }
        if (isset($levelStats['duration']) && (int)$levelStats['duration'] > 0) {
            $rows[] = ['🌀','Длительность', (string)(int)$levelStats['duration'] . 'с'];
        }
    } else {
        if ($dps !== null && (int)$dps > 0) {
            $rows[] = ['⚔️','Урон/сек', number_format((int)$dps, 0, '', ' ')];
        }
        if ($dps_multi !== null && (int)$dps_multi > 0) {
            $rows[] = ['🔥','Урон/сек (мульти)', number_format((int)$dps_multi, 0, '', ' ')];
        }
        if ($dps_single_max !== null && (int)$dps_single_max > 0) {
            $rows[] = ['🎯','Урон/сек (макс.)', number_format((int)$dps_single_max, 0, '', ' ')];
        }
        if ($bonus_pct !== null && (int)$bonus_pct !== 0) {
            $rows[] = ['📈','Бонусный урон', (string)(int)$bonus_pct . '%'];
        }
        if ($death_dmg !== null && (int)$death_dmg > 0) {
            $rows[] = ['💥','Урон при разрушении', number_format((int)$death_dmg, 0, '', ' ')];
        }
        if ($push !== null && (float)$push > 0) {
            $rows[] = ['💨','Сила отталкивания', rtrim(rtrim(number_format((float)$push, 2, '.', ''), '0'), '.')];
        }
        if ($spell !== '') {
            $rows[] = ['🪄','Заклинание', htmlspecialchars($spell, ENT_QUOTES, 'UTF-8')];
        }
    }

    // Прочность только если есть (у ловушек скрываем полностью)
    if (!$isTrap && $hpTxt !== '') {
        $rows[] = ['❤️','Прочность', (string)$hpTxt];
    }

    // Экономика/таймеры
    // Стоимость: рядом уже есть иконка ресурса, эмодзи не нужен
    $rows[] = ['','Стоимость', defense_loc_cost_html($levelStats)];
    if ($timeTxt !== '') {
        $rows[] = ['⏳','Время', htmlspecialchars((string)$timeTxt, ENT_QUOTES, 'UTF-8')];
    }
    $rows[] = ['⭐','Опыт', (string)(int)$xp];
    $rows[] = ['🏰','Ратуша', 'Ур. ' . (int)$th_req];

    // Бой (цели/тип атаки)
    if (!$isWall) {
        if ($speedTxt !== '' && !$isTrap) {
            $rows[] = ['⏱️','Скорость атаки', (string)$speedTxt];
        }
        $rows[] = ['🌀','Тип', htmlspecialchars((string)$attack_type, ENT_QUOTES, 'UTF-8')];
        $rows[] = ['🎯','Цели', htmlspecialchars((string)$target_type, ENT_QUOTES, 'UTF-8')];
    }

    ob_start();
    ?>
    <div class="defense-stat-table">
      <?php foreach ($rows as $r): ?>
        <div class="def-row" style="display:flex;justify-content:space-between;gap:10px;align-items:center;padding:3px 0;">
          <span style="display:inline-flex;gap:6px;align-items:center;opacity:.9;">
            <span style="width:18px;text-align:center;"><?= htmlspecialchars((string)$r[0], ENT_QUOTES, 'UTF-8') ?></span>
            <span><?= htmlspecialchars((string)$r[1], ENT_QUOTES, 'UTF-8') ?></span>
          </span>
          <span style="font-weight:800;"><?= $r[2] ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Собрать применимые “боевые” характеристики для сравнительной таблицы (текущий/следующий).
 * Возвращает массив элементов вида: [['key'=>..., 'icon'=>..., 'label'=>..., 'value'=>...], ...]
 */
function defense_loc_collect_combat_stats(string $building_id, array $cfg, array $levelStats): array {
    $type = strtolower((string)($cfg['type'] ?? ''));
    $isTrap = ($type === (defined('TYPE_TRAP') ? TYPE_TRAP : 'trap'));
    $isWall = ($type === (defined('TYPE_WALL') ? TYPE_WALL : 'wall'));

    $out = [];

    $hp = isset($levelStats['hp']) ? (int)$levelStats['hp'] : 0;
    $dps = isset($levelStats['dps']) ? (int)$levelStats['dps'] : 0;
    $dps_multi = isset($levelStats['dps_multi']) ? (int)$levelStats['dps_multi'] : 0;
    $dps_single_max = isset($levelStats['dps_single_max']) ? (int)$levelStats['dps_single_max'] : 0;
    $damage = isset($levelStats['damage']) ? (int)$levelStats['damage'] : 0;
    $push = isset($levelStats['push_strength']) ? (float)$levelStats['push_strength'] : 0.0;

    if ($isTrap) {
        if ($damage > 0) $out[] = ['key'=>'damage', 'icon'=>'💣', 'label'=>'Урон при срабатывании', 'value'=>number_format($damage, 0, '', ' ')];
        if (isset($levelStats['capacity']) && (int)$levelStats['capacity'] > 0) $out[] = ['key'=>'capacity', 'icon'=>'🪶', 'label'=>'Макс. вес', 'value'=>(string)(int)$levelStats['capacity']];
        if (isset($levelStats['duration']) && (int)$levelStats['duration'] > 0) $out[] = ['key'=>'duration', 'icon'=>'🌀', 'label'=>'Длительность', 'value'=>(string)(int)$levelStats['duration'] . 'с'];
        return $out;
    }

    // Стены не атакуют, но имеют прочность
    if (!$isWall) {
        if ($dps > 0) $out[] = ['key'=>'dps', 'icon'=>'⚔️', 'label'=>'Урон/сек', 'value'=>number_format($dps, 0, '', ' ')];
        // Режимные защиты (Адская башня и т.п.): отдельные поля в базе
        if ($dps_multi > 0) $out[] = ['key'=>'dps_multi', 'icon'=>'🔥', 'label'=>'Урон/сек (мульти)', 'value'=>number_format($dps_multi, 0, '', ' ')];
        if ($dps_single_max > 0) $out[] = ['key'=>'dps_single_max', 'icon'=>'🎯', 'label'=>'Урон/сек (макс.)', 'value'=>number_format($dps_single_max, 0, '', ' ')];
        if ($push > 0) $out[] = ['key'=>'push', 'icon'=>'💨', 'label'=>'Сила отталкивания', 'value'=>rtrim(rtrim(number_format($push, 2, '.', ''), '0'), '.')];
    }

    if ($hp > 0) $out[] = ['key'=>'hp', 'icon'=>'❤️', 'label'=>'Прочность', 'value'=>number_format($hp, 0, '', ' ')];

    // Дополнительные поля (если заданы)
    if (isset($levelStats['death_dmg']) && (int)$levelStats['death_dmg'] > 0) {
        $out[] = ['key'=>'death_dmg', 'icon'=>'💥', 'label'=>'Урон при разрушении', 'value'=>number_format((int)$levelStats['death_dmg'], 0, '', ' ')];
    } elseif (isset($levelStats['death_damage']) && (int)$levelStats['death_damage'] > 0) {
        $out[] = ['key'=>'death_damage', 'icon'=>'💥', 'label'=>'Урон при разрушении', 'value'=>number_format((int)$levelStats['death_damage'], 0, '', ' ')];
    }

    return $out;
}

function defense_loc_render_combat_compare_table(string $building_id, array $cfg, array $curStats, ?array $nextStats): string {
    $cur = defense_loc_collect_combat_stats($building_id, $cfg, $curStats);
    $nxt = $nextStats ? defense_loc_collect_combat_stats($building_id, $cfg, $nextStats) : [];

    // Индексация по label (чтобы не было “прочерков”)
    $idxCur = [];
    foreach ($cur as $r) $idxCur[$r['label']] = $r;
    $idxNxt = [];
    foreach ($nxt as $r) $idxNxt[$r['label']] = $r;

    $labels = array_values(array_unique(array_merge(array_keys($idxCur), array_keys($idxNxt))));
    if (!$labels) return '';

    ob_start();
    ?>
    <div class="coc-um-stats coc-um-stats--combat" style="margin-top: 10px;">
      <div class="coc-um-sth"><div></div><div class="coc-um-stc">Текущий</div><div class="coc-um-stn">Следующий</div></div>
      <?php foreach ($labels as $lbl):
        $c = $idxCur[$lbl] ?? null;
        $n = $idxNxt[$lbl] ?? null;
        $icon = $c['icon'] ?? ($n['icon'] ?? '•');
      ?>
        <div class="coc-um-str">
          <div class="coc-um-stl"><span class="coc-um-ico"><?= htmlspecialchars((string)$icon, ENT_QUOTES, 'UTF-8') ?></span><?= htmlspecialchars((string)$lbl, ENT_QUOTES, 'UTF-8') ?></div>
          <div class="coc-um-stv"><?= $c ? htmlspecialchars((string)$c['value'], ENT_QUOTES, 'UTF-8') : '' ?></div>
          <div class="coc-um-stv coc-um-next"><?= $n ? htmlspecialchars((string)$n['value'], ENT_QUOTES, 'UTF-8') : '' ?></div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

// -----------------------------------------------------------------------------
// Views
// -----------------------------------------------------------------------------

function renderDefenseMainView(array $user, string $tab): string {
    global $mysqli, $game_data;

    $tabs = defense_loc_get_tab_defs();
    $tab = $tab ?: 'ground';
    if (!isset($tabs[$tab])) $tab = 'ground';

    $modal_id = 'defense-modal';
    $th_lvl = (int)($user['townhall_lvl'] ?? 1);
    $types = defense_loc_get_types_for_tab($game_data, $tab);

    // counts by type in one query
    $built_counts = [];
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid > 0) {
        if (function_exists('finalizeCompletedBuildTimers')) {
            finalizeCompletedBuildTimers($mysqli, $uid);
        }
        $sql = "SELECT building_id, COUNT(*) cnt FROM player_buildings WHERE user_id=? GROUP BY building_id";
        if ($stmt = $mysqli->prepare($sql)) {
            $stmt->bind_param("i", $uid);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $bid = (string)($row['building_id'] ?? '');
                $built_counts[$bid] = (int)($row['cnt'] ?? 0);
            }
            $stmt->close();
        }
    }

    $tab_label = (string)($tabs[$tab]['label'] ?? 'Оборона');

    ob_start();
    ?>
    <div class="defense-main-view">
      <?= defense_loc_renderBalancePayload($mysqli, $user); ?>

      <div class="modal-header-controls">
        <h2 class="modal-title-text-inside-panel">ОБОРОНА</h2>
        <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
          <img src="/images/icons/close.png" alt="Закрыть">
        </button>
      </div>

      <div class="modal-body-custom">
        <?= defense_loc_render_tabs($tab) ?>

        <?php
          // Для вкладки "Стены" считаем (текущее/макс) заранее, чтобы вывести в заголовке панели.
          $walls_count_text_for_title = null;
          if ($tab === 'walls') {
              $wallTotalTitle = 0;
              if ($uid > 0) {
                  if ($stmtWT = $mysqli->prepare("SELECT COUNT(*) c FROM player_buildings WHERE user_id=? AND building_id='wall'")) {
                      $stmtWT->bind_param('i', $uid);
                      $stmtWT->execute();
                      $rrt = $stmtWT->get_result();
                      $rowt = $rrt ? $rrt->fetch_assoc() : null;
                      $wallTotalTitle = (int)($rowt['c'] ?? 0);
                      $stmtWT->close();
                  }
              }
              $maxWallsTitle = function_exists('getMaxCountForTH') ? (int)getMaxCountForTH('wall', $th_lvl) : 0;
              $walls_count_text_for_title = ($maxWallsTitle > 0) ? ($wallTotalTitle . '/' . $maxWallsTitle) : ($wallTotalTitle . '/?');
          }
        ?>

        <div class="coc-panel coc-building-panel" style="margin-top: 10px;">
          <div class="coc-building-head">
            <div class="coc-building-title">
              <?php if ($tab === 'walls' && $walls_count_text_for_title !== null): ?>
                СТЕНЫ <span style="opacity:.8;">(<?= htmlspecialchars($walls_count_text_for_title, ENT_QUOTES, 'UTF-8') ?>)</span>
              <?php else: ?>
                <?= htmlspecialchars(mb_strtoupper($tab_label, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
              <?php endif; ?>
            </div>
          </div>

          <?php if (empty($types)): ?>
            <div class="coc-empty-note" style="padding: 14px;">В этой вкладке пока нет сооружений.</div>
          <?php else: ?>
            
              <div class="coc-bslots defense-type-slots">
                <?php foreach ($types as $building_id):
                  $cfg = $game_data[$building_id] ?? null;
                  if (!$cfg) continue;

                  $name = (string)($cfg['name'] ?? $building_id);
                  $built_count = (int)($built_counts[$building_id] ?? 0);
                  $max_count = function_exists('getMaxCountForTH') ? (int)getMaxCountForTH((string)$building_id, $th_lvl) : 0;
                  $max_text = ($max_count > 0) ? ($built_count . '/' . $max_count) : ($built_count . '/?');
                  $img = defense_loc_building_image_url((string)$building_id, 1);
                ?>
                  <div class="coc-bslot coc-bslot--dtype" data-deflist="<?= htmlspecialchars($building_id, ENT_QUOTES, 'UTF-8') ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="coc-bname"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></div>
                    <img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="coc-bbadge"><?= htmlspecialchars($max_text, ENT_QUOTES, 'UTF-8') ?></div>
                  </div>
                <?php endforeach; ?>
              </div>

          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}


function renderDefenseListView(array $user, string $type, array $built_buildings, string $tab): string {
    global $mysqli, $game_data;

    $modal_id = 'defense-modal';
    $th_lvl = (int)($user['townhall_lvl'] ?? 1);

    $cfg = $game_data[$type] ?? null;
    if (!$cfg) throw new RuntimeException('Неизвестный тип сооружения', 400);

    $building_type_name = (string)($cfg['name'] ?? $type);
    $description = (string)($cfg['description'] ?? 'Укрепляйте деревню и готовьтесь к атакам.');

    $lvl1 = defense_loc_get_level_stats($cfg, 1);
    $build_stats_html = defense_loc_render_stat_table($type, $cfg, 1, (array)$lvl1);
    $build_behavior_html = defense_loc_render_battle_block($type, $cfg);

    $levels = $cfg['levels'] ?? [];
    $th_req_1 = (int)($lvl1['th_req'] ?? 1);

    // counts
    $built_count = count($built_buildings);
    $max_count = function_exists('getMaxCountForTH') ? (int)getMaxCountForTH((string)$type, $th_lvl) : 0;
    $count_text = ($max_count > 0) ? ($built_count . '/' . $max_count) : ($built_count . '/?');

    // build availability
    $can_build_more = ($max_count <= 0) ? true : ($built_count < $max_count);
    $th_ok = ($th_lvl >= $th_req_1);
    // cost/time for build lvl1 (robust extraction from config)
    [$res_type, $cost, $time] = defense_loc_extract_cost_res_time($lvl1);
    $xp = defense_loc_calc_xp($cost, $time);

    $build_cost_html = defense_loc_cost_html(['cost'=>$cost,'res_type'=>$res_type]);
    $build_time_str = defense_loc_format_time($time);

    $can_afford = defense_loc_can_afford($user, $res_type, $cost);

    // image
    $img = defense_loc_building_image_url((string)$type, 1);

    ob_start();
    ?>
    <div class="defense-list-view">
      <?= defense_loc_renderBalancePayload($mysqli, $user); ?>

      <div class="modal-header-controls">
        <button class="back-modal modal-button-corner" onclick="defenseGoBack('<?= $modal_id ?>','main')">
          <img src="/images/icons/left.png" alt="Назад">
        </button>
        <h2 class="modal-title-text-inside-panel"><?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($count_text, ENT_QUOTES, 'UTF-8') ?>)</h2>
        <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
          <img src="/images/icons/close.png" alt="Закрыть">
        </button>
      </div>

      <div class="modal-body-custom">
        <div class="coc-panel" style="margin-top: 10px;">
          <div class="coc-panel-head">
            <div class="coc-panel-title"><?= htmlspecialchars(mb_strtoupper($building_type_name, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="coc-hint" style="margin:0;"><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></div>
          </div>
        </div>

        <?php if ($type === 'wall'): ?>
          <?php
            // Массовое / авто улучшение стен — показываем ТОЛЬКО на странице списка стен (view=list&type=wall)
            $wallByLevel = [];
            foreach ($built_buildings as $bw) {
                $lv = (int)($bw['level'] ?? 1);
                if ($lv < 1) $lv = 1;
                if (!isset($wallByLevel[$lv])) $wallByLevel[$lv] = 0;
                $wallByLevel[$lv]++;
            }
            ksort($wallByLevel);

            $maxWallLevel = 0;
            $wallLevelsCfg = (array)($cfg['levels'] ?? []);
            foreach ($wallLevelsCfg as $k => $_v) { $maxWallLevel = max($maxWallLevel, (int)$k); }

            $goldHave = (int)($user['gold'] ?? 0);
            $elixirHave = (int)($user['elixir'] ?? 0);
          ?>

          <div class="coc-panel coc-um-battle" style="margin:10px 14px 0 14px;">
            <div class="coc-panel-head" style="align-items:flex-start;">
              <div class="coc-panel-title">МАССОВОЕ УЛУЧШЕНИЕ</div>
              <div style="margin-left:auto;display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end;">
                <div class="coc-hint" style="margin:0;">Мгновенно, без строителей. Эликсир доступен с 9 уровня.</div>
                <button type="button" class="coc-um-btn" data-walls-bulk-toggle="hide" style="padding:6px 10px;">Скрыть</button>
                <button type="button" class="coc-um-btn" data-walls-bulk-toggle="show" style="padding:6px 10px;display:none;">Показать</button>
              </div>
            </div>

            <div class="coc-panel-body" style="padding:10px 12px 12px 12px;" data-walls-bulk-panel="1" data-elixir-min="9" data-walls-bulk-body="1">
              <div class="walls-bulk" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;">
                <div style="flex:1;min-width:170px;">
                  <div style="font-weight:900;font-size:11px;opacity:.75;margin-bottom:4px;">Уровень</div>
                  <select class="coc-input" id="walls-bulk-level" style="width:100%;padding:7px 10px;border-radius:10px;border:2px solid rgba(0,0,0,.18);background:rgba(255,255,255,.7);font-weight:900;" <?= empty($wallByLevel) ? 'disabled="disabled"' : '' ?>>
                    <?php if (empty($wallByLevel)): ?>
                      <option value="0">Нет построенных стен</option>
                    <?php else: ?>
                      <?php foreach ($wallByLevel as $lv => $cnt): if ((int)$lv >= (int)$maxWallLevel) continue; ?>
                        <option value="<?= (int)$lv ?>">Ур. <?= (int)$lv ?> (<?= (int)$cnt ?> шт.)</option>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </select>
                </div>

                <div style="width:150px;">
                  <div style="font-weight:900;font-size:11px;opacity:.75;margin-bottom:4px;">Кол-во</div>
                  <select class="coc-input" id="walls-bulk-qty" style="width:100%;padding:7px 10px;border-radius:10px;border:2px solid rgba(0,0,0,.18);background:rgba(255,255,255,.7);font-weight:900;" <?= empty($wallByLevel) ? 'disabled="disabled"' : '' ?>>
                    <option value="1">1</option>
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                    <option value="999999">Максимум</option>
                  </select>
                </div>

                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                  <button type="button" class="coc-um-btn" data-walls-bulk-v2="1" data-res="gold" style="padding:8px 12px;display:flex;align-items:center;gap:6px;" <?= empty($wallByLevel) ? 'disabled="disabled"' : '' ?>>
                    <img src="/images/icons/gold.png" alt="Золото" style="width:18px;height:18px;">
                    <span>Улучшить</span>
                  </button>
                  <button type="button" class="coc-um-btn" data-walls-bulk-v2="1" data-res="elixir" style="padding:8px 12px;display:flex;align-items:center;gap:6px;" <?= empty($wallByLevel) ? 'disabled="disabled"' : '' ?>>
                    <img src="/images/icons/elixir.png" alt="Эликсир" style="width:18px;height:18px;">
                    <span>Улучшить</span>
                  </button>
                </div>
              </div>

              <div class="walls-auto" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <div style="font-weight:900;opacity:.9;">Авто</div>
                <select class="coc-input" id="walls-auto-pref" style="padding:8px 10px;border-radius:10px;border:2px solid rgba(0,0,0,.18);background:rgba(255,255,255,.7);font-weight:900;" <?= empty($wallByLevel) ? 'disabled="disabled"' : '' ?>>
                  <option value="gold">Предпочитать золото</option>
                  <option value="elixir">Предпочитать эликсир</option>
                </select>
                <button type="button" class="coc-um-btn" data-walls-auto-v2="1" style="padding:8px 12px;" <?= empty($wallByLevel) ? 'disabled="disabled"' : '' ?>>Улучшить всё возможное</button>


              </div>

              <div class="walls-auto-result" id="walls-auto-result" style="display:none;margin-top:8px;padding:8px;border-radius:12px;background:rgba(0,0,0,.04);border:1px solid rgba(0,0,0,.10);font-size:12px;"></div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!$th_ok && empty($built_buildings)): ?>
          <div class="coc-panel" style="margin-top: 10px; text-align:center;">
            <div class="coc-empty-note" style="padding: 14px;">🔒 Требуется Ратуша уровня <?= (int)$th_req_1 ?>.</div>
          </div>
        <?php endif; ?>

        <div class="coc-panel coc-building-panel<?= ($type==='wall'?' is-walls':'') ?>" style="margin-top: 10px;">
          <div class="coc-building-head">
            <div class="coc-building-title">ПОСТРОЙКИ</div>
            <div class="coc-building-sub">Построено: <?= htmlspecialchars($count_text, ENT_QUOTES, 'UTF-8') ?></div>
          </div>

          
          <style>
            /* Restore original grid for all defense lists except walls */
            .coc-building-panel:not(.is-walls) .coc-bslots{
              display:grid;
              grid-template-columns:repeat(3, minmax(0, 1fr));
              gap:12px;
            }
            @media (max-width: 460px){
              .coc-building-panel:not(.is-walls) .coc-bslots{
                grid-template-columns:repeat(2, minmax(0, 1fr));
              }
            }
          </style>
<?php
            $isWallsList = ($type === 'wall');
            if ($isWallsList):
          ?>
          <?php
// Walls list: sort + pagination (fragment for instant updates)
            // ----------------------------
            $isWallsList = ($type === 'wall');
            $walls_sort = 'asc';
            $walls_sort = 'asc';
            $walls_page_items = $built_buildings;
            $walls_total = count($built_buildings);

            if ($isWallsList) {
                $walls_sort = (string)($_GET['wsort'] ?? 'asc');
                if ($walls_sort !== 'asc' && $walls_sort !== 'desc') $walls_sort = 'asc';

                $walls_sorted = $built_buildings;
                usort($walls_sorted, function($a, $b) use ($walls_sort){
                    $la = (int)($a['level'] ?? 1);
                    $lb = (int)($b['level'] ?? 1);
                    if ($la === $lb) return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
                    return ($walls_sort === 'asc') ? ($la <=> $lb) : ($lb <=> $la);
                });

                $walls_page_items = $walls_sorted;
            }

            // Partial fragment request: return only sort/pager + wall cards (no header/back/buttons)
            if ($isWallsList && (string)($_GET['partial'] ?? '') === '1') {
                ob_start();
                ?>
                <div data-walls-fragment="1">
                  <div id="walls-controls">
                    <style>
                      .walls-scroll{scrollbar-width:none;-ms-overflow-style:none;}
                      .walls-scroll::-webkit-scrollbar{width:0;height:0;}
                      #walls-controls .coc-bslot-add.is-empty{background:#e9e9e9;}
                      #walls-controls .coc-bslot-add.is-empty .coc-bbadge{background:rgba(0,0,0,.55);color:#fff;}
                    </style>
                    <div class="walls-controls-compact" style="margin:8px 14px 10px 14px;display:flex;align-items:center;justify-content:flex-end;gap:8px;">
  <button type="button" id="walls-sort-toggle" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" data-wsort="<?= htmlspecialchars($walls_sort, ENT_QUOTES, 'UTF-8') ?>"
    class="coc-bbtn"
    style="height:30px;min-width:30px;padding:0 10px;border-radius:10px;font-weight:900;display:flex;align-items:center;justify-content:center;gap:6px;">
    <span style="font-size:14px;line-height:1;">Ур.</span>
    <span id="walls-sort-icon" style="font-size:16px;line-height:1;"><?= ($walls_sort==='asc'?'↑':'↓') ?></span>
  </button>
</div>
                  </div>
                  <div id="walls-cards" class="coc-bslots walls-scroll" style="max-height:360px;overflow:auto;padding:0 12px 12px;margin-top:6px;">
                    <?php
      $remain = ($max_count > 0) ? max(0, ($max_count - $built_count)) : 999999;
      $haveRes = (int)($user[$res_type] ?? 0);
      $affCnt = ($cost > 0) ? (int)floor($haveRes / $cost) : $remain;
      $maxQtyBuild = max(1, min($remain > 0 ? $remain : 1, $affCnt > 0 ? $affCnt : 1));
      $buildDisabled = (!$th_ok) || (!$can_build_more);
      $lockMsg = '';
      if (!$th_ok) $lockMsg = 'Требуется Ратуша уровня ' . (int)$th_req_1 . '.';
      else if (!$can_build_more) $lockMsg = 'Достигнут лимит стен: ' . htmlspecialchars($count_text, ENT_QUOTES, 'UTF-8');
      $btnCls = 'coc-bslot coc-bslot-add is-empty';
      if ($buildDisabled) $btnCls .= ' is-locked';
    ?>
    <div class="<?= $btnCls ?>" data-deflockmsg="<?= htmlspecialchars($lockMsg, ENT_QUOTES, 'UTF-8') ?>"
         data-defbuildbtn="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
         data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>"
         data-defbname="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>"
         data-defbdesc="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"
         data-defbcosthtml="<?= htmlspecialchars($build_cost_html, ENT_QUOTES, 'UTF-8') ?>"
         data-defbcostval="<?= (int)$cost ?>"
         data-defbres="<?= htmlspecialchars((string)$res_type, ENT_QUOTES, 'UTF-8') ?>"
         data-defbtimestr="<?= htmlspecialchars($build_time_str, ENT_QUOTES, 'UTF-8') ?>"
         data-defbmaxcount="<?= (int)$maxQtyBuild ?>">
      <div class="coc-bname"><?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?></div>
      <img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>">
      <div class="coc-bbadge">+</div>
      <div class="coc-bactions">
        <button type="button" class="coc-bbtn <?= $buildDisabled ? 'is-locked':'' ?>" <?= $buildDisabled ? 'disabled="disabled"':'' ?>
          data-defbuildtype="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
          data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>"
          data-deflockmsg="<?= htmlspecialchars($lockMsg, ENT_QUOTES, 'UTF-8') ?>"
          data-defbname="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>"
          data-defbdesc="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"
          data-defbcosthtml="<?= htmlspecialchars($build_cost_html, ENT_QUOTES, 'UTF-8') ?>"
          data-defbcostval="<?= (int)$cost ?>"
          data-defbres="<?= htmlspecialchars((string)$res_type, ENT_QUOTES, 'UTF-8') ?>"
          data-defbtimestr="<?= htmlspecialchars($build_time_str, ENT_QUOTES, 'UTF-8') ?>"
          data-defbmaxcount="<?= (int)$maxQtyBuild ?>"
        >Построить</button>
      </div>
    </div>
    
<?php foreach ($walls_page_items as $b):
                      $rowId = (int)($b['id'] ?? 0);
                      $level = (int)($b['level'] ?? 1);
                      $status = (string)($b['status'] ?? 'ready');
                      $finish = (int)($b['finish_time'] ?? 0);
                      $nowS = time();
                      $left = ($finish > $nowS) ? ($finish - $nowS) : 0;
                      $img2 = defense_loc_building_image_url((string)$type, max(1,$level));
                      $slotCls = 'coc-bslot' . (($status === 'upgrading' || $status === 'constructing') ? ' is-busy' : '');
                    ?>
                      <div class="<?= $slotCls ?>" data-defopen="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="coc-bname"><?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?></div>
                        <button type="button" class="coc-info coc-info-bld" data-definfo="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">!</button>
                        <img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img2, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>">
                        <div class="coc-bbadge"><?= (int)$level ?></div>
                        <div class="coc-bactions">
                          <?php if ($left > 0): ?>
                            <button type="button" class="coc-bbtn coc-bbtn-busy" disabled="disabled" data-btimer-end="<?= (int)$finish ?>"><?= htmlspecialchars(defense_loc_format_time($left), ENT_QUOTES, 'UTF-8') ?></button>
                          <?php else: ?>
                            <button type="button" class="coc-bbtn" data-defopenbtn="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">Открыть</button>
                          <?php endif; ?>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
                <?php
                return ob_get_clean();
            }
          ?>

          <?php if ($isWallsList): ?>
            <style>
                      .walls-scroll{scrollbar-width:none;-ms-overflow-style:none;}
                      .walls-scroll::-webkit-scrollbar{width:0;height:0;}
                      #walls-controls .coc-bslot-add.is-empty{background:#e9e9e9;}
                      #walls-controls .coc-bslot-add.is-empty .coc-bbadge{background:rgba(0,0,0,.55);color:#fff;}
                    </style>
            <div id="walls-controls">
              <div class="walls-controls-compact" style="margin:8px 14px 10px 14px;display:flex;align-items:center;justify-content:flex-end;gap:8px;">
  <button type="button" id="walls-sort-toggle" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" data-wsort="<?= htmlspecialchars($walls_sort, ENT_QUOTES, 'UTF-8') ?>"
    class="coc-bbtn"
    style="height:30px;min-width:30px;padding:0 10px;border-radius:10px;font-weight:900;display:flex;align-items:center;justify-content:center;gap:6px;">
    <span style="font-size:14px;line-height:1;">Ур.</span>
    <span id="walls-sort-icon" style="font-size:16px;line-height:1;"><?= ($walls_sort==='asc'?'↑':'↓') ?></span>
  </button>
</div>
            </div>

            <div id="walls-cards" class="coc-bslots walls-scroll" style="max-height:360px;overflow:auto;padding:0 12px 12px;margin-top:6px;">
          <?php
      $remain = ($max_count > 0) ? max(0, ($max_count - $built_count)) : 999999;
      $haveRes = (int)($user[$res_type] ?? 0);
      $affCnt = ($cost > 0) ? (int)floor($haveRes / $cost) : $remain;
      $maxQtyBuild = max(1, min($remain > 0 ? $remain : 1, $affCnt > 0 ? $affCnt : 1));
      $buildDisabled = (!$th_ok) || (!$can_build_more);
      $lockMsg = '';
      if (!$th_ok) $lockMsg = 'Требуется Ратуша уровня ' . (int)$th_req_1 . '.';
      else if (!$can_build_more) $lockMsg = 'Достигнут лимит стен: ' . htmlspecialchars($count_text, ENT_QUOTES, 'UTF-8');
      $btnCls = 'coc-bslot coc-bslot-add is-empty';
      if ($buildDisabled) $btnCls .= ' is-locked';
    ?>
    <div class="<?= $btnCls ?>" data-deflockmsg="<?= htmlspecialchars($lockMsg, ENT_QUOTES, 'UTF-8') ?>"
         data-defbuildbtn="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
         data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>"
         data-defbname="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>"
         data-defbdesc="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"
         data-defbcosthtml="<?= htmlspecialchars($build_cost_html, ENT_QUOTES, 'UTF-8') ?>"
         data-defbcostval="<?= (int)$cost ?>"
         data-defbres="<?= htmlspecialchars((string)$res_type, ENT_QUOTES, 'UTF-8') ?>"
         data-defbtimestr="<?= htmlspecialchars($build_time_str, ENT_QUOTES, 'UTF-8') ?>"
         data-defbmaxcount="<?= (int)$maxQtyBuild ?>">
      <div class="coc-bname"><?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?></div>
      <img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>">
      <div class="coc-bbadge">+</div>
      <div class="coc-bactions">
        <button type="button" class="coc-bbtn <?= $buildDisabled ? 'is-locked':'' ?>" <?= $buildDisabled ? 'disabled="disabled"':'' ?>
          data-defbuildtype="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
          data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>"
          data-deflockmsg="<?= htmlspecialchars($lockMsg, ENT_QUOTES, 'UTF-8') ?>"
          data-defbname="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>"
          data-defbdesc="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"
          data-defbcosthtml="<?= htmlspecialchars($build_cost_html, ENT_QUOTES, 'UTF-8') ?>"
          data-defbcostval="<?= (int)$cost ?>"
          data-defbres="<?= htmlspecialchars((string)$res_type, ENT_QUOTES, 'UTF-8') ?>"
          data-defbtimestr="<?= htmlspecialchars($build_time_str, ENT_QUOTES, 'UTF-8') ?>"
          data-defbmaxcount="<?= (int)$maxQtyBuild ?>"
        >Построить</button>
      </div>
    </div>
    
<?php endif; ?>

              <?php foreach ($walls_page_items as $b):

                $rowId = (int)($b['id'] ?? 0);
                $level = (int)($b['level'] ?? 1);
                $status = (string)($b['status'] ?? 'ready');
                $is_upgrading = ($status === 'upgrading');
                $is_constructing = ($status === 'constructing');
                $is_busy = ($is_upgrading || $is_constructing);
                $finish = (int)($b['finish_time'] ?? 0);

                $img2 = defense_loc_building_image_url((string)$type, max(1,$level));
                $slotCls = 'coc-bslot' . ($is_busy ? ' is-busy' : '');
                $slotName = $building_type_name;

                // UI like barracks: info button + action button
                $nowS = time();
                $left = ($finish > $nowS) ? ($finish - $nowS) : 0;
              ?>
                <div class="<?= $slotCls ?>" data-defopen="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                  <div class="coc-bname"><?= htmlspecialchars($slotName, ENT_QUOTES, 'UTF-8') ?></div>
                  <button type="button" class="coc-info coc-info-bld" data-definfo="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">!</button>
<img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img2, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>">
                  <div class="coc-bbadge"><?= (int)$level ?></div>
                  <div class="coc-bactions">
                    <?php if ($left > 0): ?>
                      <button type="button" class="coc-bbtn coc-bbtn-busy" disabled="disabled" data-btimer-end="<?= (int)$finish ?>"><?= htmlspecialchars(defense_loc_format_time($left), ENT_QUOTES, 'UTF-8') ?></button>
                    <?php else: ?>
                      <button type="button" class="coc-bbtn" data-defopenbtn="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">Открыть</button>
                    <?php endif; ?>
                  </div>
</div>
              <?php endforeach; ?>

              <?php if ($isWallsList): ?>
                </div>
              <?php endif; ?>
          </div>
          <?php else: ?>
          <div class="coc-bslots">
            <?php
              // Slot: build new
              $build_btn_text = 'Построить';
              $build_disabled = false;
              $build_lock_msg = '';
              if (!$can_build_more) { $build_btn_text = 'Лимит'; $build_disabled = true; $build_lock_msg = 'Достигнут лимит построек.'; }
              else if (!$th_ok) { $build_btn_text = 'Нужна Ратуша'; $build_disabled = true; $build_lock_msg = 'Требуется Ратуша уровня ' . (int)$th_req_1 . '.'; }
              else if (!$can_afford) { $build_btn_text = 'Недостаточно'; $build_disabled = true; $build_lock_msg = 'Недостаточно ресурсов для постройки.'; }
              $build_cls = 'coc-bslot is-empty' . ($build_disabled ? ' is-locked' : '');
            ?>
            <div class="<?= $build_cls ?>"
                 data-defbuildtype="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                 data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>"
                 data-deflockmsg="<?= htmlspecialchars($build_lock_msg, ENT_QUOTES, 'UTF-8') ?>"
                 data-defbname="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>"
                 data-defbdesc="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"
                 data-defbstatshtml="<?= htmlspecialchars($build_stats_html, ENT_QUOTES, 'UTF-8') ?>"
                 data-defbbehaviorhtml="<?= htmlspecialchars($build_behavior_html, ENT_QUOTES, 'UTF-8') ?>"
                 data-defbcosthtml="<?= htmlspecialchars($build_cost_html, ENT_QUOTES, 'UTF-8') ?>"
                 data-defbtimestr="<?= htmlspecialchars($build_time_str, ENT_QUOTES, 'UTF-8') ?>">
              <div class="coc-bname"><?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?></div>
<img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>">
              <div class="coc-bbadge">+</div>
              <div class="coc-bactions">
                <button type="button"
                        class="coc-bbtn"
                        <?= $build_disabled ? 'disabled="disabled"' : '' ?>
                        data-defbuildbtn="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
                        data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>"
                        data-deflockmsg="<?= htmlspecialchars($build_lock_msg, ENT_QUOTES, 'UTF-8') ?>"
                        data-defbname="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>"
                        data-defbdesc="<?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?>"
                        data-defbstatshtml="<?= htmlspecialchars($build_stats_html, ENT_QUOTES, 'UTF-8') ?>"
                        data-defbbehaviorhtml="<?= htmlspecialchars($build_behavior_html, ENT_QUOTES, 'UTF-8') ?>"
                        data-defbcosthtml="<?= htmlspecialchars($build_cost_html, ENT_QUOTES, 'UTF-8') ?>"
                        data-defbtimestr="<?= htmlspecialchars($build_time_str, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($build_btn_text, ENT_QUOTES, 'UTF-8') ?></button>
              </div>
</div>

            <?php if (empty($built_buildings)): ?>
              <div class="coc-empty-note" style="padding: 14px;">У вас пока нет этого сооружения. Постройте первое, чтобы увидеть детали и улучшения.</div>
            <?php else: ?>
              <?php foreach ($built_buildings as $b):
                $rowId = (int)($b['id'] ?? 0);
                $level = (int)($b['level'] ?? 1);
                $status = (string)($b['status'] ?? 'ready');
                $is_upgrading = ($status === 'upgrading');
                $is_constructing = ($status === 'constructing');
                $is_busy = ($is_upgrading || $is_constructing);
                $finish = (int)($b['finish_time'] ?? 0);

                $img2 = defense_loc_building_image_url((string)$type, max(1,$level));
                $slotCls = 'coc-bslot' . ($is_busy ? ' is-busy' : '');
                $slotName = $building_type_name;

                // UI like barracks: info button + action button
                $nowS = time();
                $left = ($finish > $nowS) ? ($finish - $nowS) : 0;
              ?>
                <div class="<?= $slotCls ?>" data-defopen="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">
                  <div class="coc-bname"><?= htmlspecialchars($slotName, ENT_QUOTES, 'UTF-8') ?></div>
                  <button type="button" class="coc-info coc-info-bld" data-definfo="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">!</button>
<img class="coc-bimg" loading="lazy" decoding="async" src="<?= htmlspecialchars($img2, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($building_type_name, ENT_QUOTES, 'UTF-8') ?>">
                  <div class="coc-bbadge"><?= (int)$level ?></div>
                  <div class="coc-bactions">
                    <?php if ($left > 0): ?>
                      <button type="button" class="coc-bbtn coc-bbtn-busy" disabled="disabled" data-btimer-end="<?= (int)$finish ?>"><?= htmlspecialchars(defense_loc_format_time($left), ENT_QUOTES, 'UTF-8') ?></button>
                    <?php else: ?>
                      <button type="button" class="coc-bbtn" data-defopenbtn="<?= (int)$rowId ?>" data-tab="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>">Открыть</button>
                    <?php endif; ?>
                  </div>
</div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}


function renderDefenseDetailView(array $user, array $building, string $tab): string {
    global $mysqli, $game_data;

    $modal_id = 'defense-modal';

    $building_row_id = (int)($building['id'] ?? 0);
    $building_id = (string)($building['building_id'] ?? '');
    if (!$building_row_id || !$building_id) return '<div class="error">Ошибка данных здания.</div>';

    $cfg = $game_data[$building_id] ?? null;
    if (!$cfg) throw new RuntimeException('Неизвестный тип сооружения', 400);

    $name = (string)($cfg['name'] ?? $building_id);
    $level = (int)($building['level'] ?? 1);
    $levels = $cfg['levels'] ?? [];
    $stats = $levels[$level] ?? [];
    $next_stats = $levels[$level + 1] ?? null;

    $th_lvl = (int)($user['townhall_lvl'] ?? 1);
    $status = (string)($building['status'] ?? 'ready');
    $is_upgrading = ($status === 'upgrading');
    $is_constructing = ($status === 'constructing');
    $is_busy = ($is_upgrading || $is_constructing);

    $type = strtolower((string)($cfg['type'] ?? ''));
    $isTrap = ($type === (defined('TYPE_TRAP') ? TYPE_TRAP : 'trap'));
    $isWall = ($type === (defined('TYPE_WALL') ? TYPE_WALL : 'wall'));

    $targets = $isWall ? '—' : defense_loc_targets_label($building_id, $cfg);
    $attack_type = $isWall ? 'Не атакует' : ($isTrap ? 'Ловушка' : defense_loc_attack_type_label($cfg));

    // upgrade info
    $can_upgrade = !$is_busy && $next_stats !== null;
    $need_th = (int)(($next_stats['th_req'] ?? 1));
    $th_ok = $th_lvl >= $need_th;
    [$res_type, $cost, $time] = $next_stats ? defense_loc_extract_cost_res_time($next_stats) : [null,0,0];
    $xp = defense_loc_calc_xp($cost, $time);

    $upg_cost_html = $next_stats ? defense_loc_cost_html(['cost'=>$cost,'res_type'=>$res_type]) : '—';
    $upg_time_str = $next_stats ? defense_loc_format_time($time) : '—';

    $can_afford = $res_type ? defense_loc_can_afford($user, $res_type, $cost) : true;

    $btn_text = 'Улучшить';
    if ($is_constructing) $btn_text = 'Строится';
    elseif ($is_upgrading) $btn_text = 'Улучшается';
    elseif (!$can_upgrade) $btn_text = 'Макс. уровень';
    elseif (!$th_ok) $btn_text = 'Нужна Ратуша';
    elseif (!$can_afford) $btn_text = 'Недостаточно';

    $img = defense_loc_building_image_url($building_id, max(1,$level));

    // Render as an overlay modal like Barracks unit modal (coc-um-modal)
    $max_level = is_array($levels) ? count($levels) : 0;
    $level_text = $max_level > 0 ? ($level . ' / ' . $max_level) : (string)$level;

    $lock_msg = '';
    if ($is_busy) $lock_msg = 'Сейчас идёт ' . ($is_constructing ? 'стройка' : 'улучшение') . '.';
    elseif (!$can_upgrade) $lock_msg = 'Достигнут максимальный уровень.';
    elseif (!$th_ok) $lock_msg = 'Требуется Ратуша уровня ' . (int)$need_th . '.';
    elseif (!$can_afford) $lock_msg = 'Недостаточно ресурсов для улучшения.';

    $combat_compare_html = defense_loc_render_combat_compare_table($building_id, $cfg, (array)$stats, $next_stats ? (array)$next_stats : null);

    // Для модалки подтверждения улучшения — отдельные характеристики следующего уровня
    $upg_stats_html = $next_stats ? defense_loc_render_stat_table($building_id, $cfg, (int)($level + 1), (array)$next_stats) : '';
    $upg_behavior_html = defense_loc_render_battle_block($building_id, $cfg);

    ob_start();
    ?>
    <?= defense_loc_renderBalancePayload($mysqli, $user); ?>

    <!-- building modal (НЕ unit), unit tweaks ломали моб. выравнивание -->
    <div class="coc-um-modal coc-um-modal--building coc-um-modal--defcompact" role="dialog" aria-modal="true">
      <div class="coc-um-head">
        <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>
        <button type="button" class="coc-um-x" data-defmodalclose="1">×</button>
      </div>
      <div class="coc-um-sub">Оборона • Уровень <?= htmlspecialchars($level_text, ENT_QUOTES, 'UTF-8') ?></div>
      
    <style>
      /* def-ui */
      .upgrade-progress{display:flex;flex-direction:column;gap:6px;}
      .upgrade-progress-bar{height:10px;border-radius:8px;background:rgba(0,0,0,.10);overflow:hidden;}
      .upgrade-progress-fill{height:100%;width:0%;background:rgba(76,175,80,.85);}
      .upgrade-progress-meta{display:flex;justify-content:space-between;align-items:center;font-size:12px;opacity:.9;}
      .upgrade-percent{color:rgba(0,0,0,.75)!important;}
      .def-battle-block{border:1px solid rgba(0,0,0,.10);border-radius:12px;background:rgba(255,255,255,.28);padding:10px 12px;}
      .defense-stat-table .def-row{border-bottom:1px solid rgba(0,0,0,.08);min-height:28px;padding:4px 0;}
      .defense-stat-table .def-row:last-child{border-bottom:none;}
      .def-eff-badge{border:1px solid rgba(0,0,0,.12);background:rgba(255,255,255,.35);height:24px;border-radius:12px;padding:0 10px;font-weight:700;}
      .def-eff-badges{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px;}
      .def-eff-badge span{white-space:nowrap;}

      /* meta chips rounding (was pill=999) */
      .coc-um-chip.coc-um-chip--meta{border-radius:6px!important;}

      /* Phone layout like Barracks unit modal (but for buildings).
         Важно: правила в coc_compact_ui.css есть только для .coc-um-modal--unit,
         поэтому даём такие же для .coc-um-modal--building. */
      @media (max-width: 760px){
        .coc-um-modal--building .coc-um-body{
          display:flex !important;
          flex-direction:column !important;
          overflow:auto !important;
          padding:10px !important;
          gap:10px !important;
          width:100% !important;
          box-sizing:border-box !important;
        }
        .coc-um-modal--building .coc-um-left{
          width:100% !important;
          padding:10px !important;
          display:flex !important;
          flex-direction:column !important;
          align-items:center !important;
          justify-content:flex-start !important;
          text-align:center !important;
          box-sizing:border-box !important;
        }
        /* Center building art like Barracks units (no width:100% stretch) */
        .coc-um-modal--building .coc-um-art{
          display:flex !important;
          justify-content:center !important;
          align-items:center !important;
          margin:0 auto !important;
          max-width:none !important;
          width:auto !important;
        }
        /* Disable compact-ui max-width limit for art on mobile */
        .coc-um-modal--building.coc-um-modal--defcompact .coc-um-art{
          max-width:none !important;
        }
        .coc-um-modal--building .coc-um-desc{
          width:100% !important;
          margin:10px auto 0 !important;
          text-align:center !important;
          box-sizing:border-box !important;
        }
        .coc-um-modal--building .coc-um-right{
          width:100% !important;
          padding:10px !important;
          overflow:visible !important;
          box-sizing:border-box !important;
        }
        .coc-um-modal--building .coc-um-tiles{flex-wrap:wrap;gap:6px;}
        .coc-um-modal--building .coc-um-tile{flex:1 1 calc(50% - 6px);min-width:140px;}
        .coc-um-modal--building .coc-um-v{white-space:normal;}
      }

      /* Building art sizing should be in CSS (not inline style) */
      .coc-um-modal--building .coc-um-art img{
        max-width:120px;
        max-height:120px;
        width:auto;
        height:auto;
        display:block;
      }

      .coc-um-modal--building .coc-um-desc{
        font-size:13px;
        line-height:1.35;
        opacity:.92;
      }
      .def-eff-badge[data-eff="aoe"]{background:rgba(0,150,255,.10);border-color:rgba(0,150,255,.25);}
      .def-eff-badge[data-eff="anti_air"], .def-eff-badge[data-eff="air_only"]{background:rgba(0,200,255,.10);border-color:rgba(0,200,255,.25);}
      .def-eff-badge[data-eff="ground_only"]{background:rgba(140,90,40,.10);border-color:rgba(140,90,40,.22);}
      .def-eff-badge[data-eff="spell"]{background:rgba(170,80,255,.10);border-color:rgba(170,80,255,.24);}
      .def-eff-badge[data-eff="control"], .def-eff-badge[data-eff="push"]{background:rgba(80,120,255,.10);border-color:rgba(80,120,255,.24);}
      .def-eff-badge[data-eff="high_damage"], .def-eff-badge[data-eff="percent_damage"], .def-eff-badge[data-eff="beam_ramp"]{background:rgba(255,80,80,.10);border-color:rgba(255,80,80,.24);}
      .def-eff-badge[data-eff="hidden"]{background:rgba(80,80,80,.10);border-color:rgba(80,80,80,.22);}
      .def-eff-badge[data-eff="death_blast"]{background:rgba(255,140,0,.12);border-color:rgba(255,140,0,.26);}
      .def-eff-badge[data-eff="blind_zone"]{background:rgba(255,200,0,.14);border-color:rgba(255,200,0,.28);}
    </style>

      <?php if ($is_busy):
        // Для прогрессбара нужен старт. В таблице у нас есть только finish_time,
        // поэтому длительность берём из конфигов:
        //  - upgrading: длительность апгрейда = время "следующего" уровня
        //  - constructing: длительность постройки = время текущего уровня (обычно lvl1)

        $busy_status = (string)($building['busy_status'] ?? $building['status'] ?? $building['state'] ?? '');
        if ($busy_status === '' && (int)($building['finish_time'] ?? 0) > time()) {
          $busy_status = ((int)($building['level'] ?? 1) < (int)($cfg['max_level'] ?? 1)) ? 'upgrading' : 'constructing';
        }
        $end_ts = (int)($building['finish_time'] ?? 0);
        $dur_busy = 0;

        if ($busy_status === 'upgrading') {
          $next_level = min($max_level, $level + 1);
          $next_stats = defense_loc_get_level_stats($cfg, (int)$next_level);
          $dur_busy = (int)($next_stats['time'] ?? 0);
        } else {
          // constructing
          $lvl1 = defense_loc_get_level_stats($cfg, 1);
          $dur_busy = (int)($lvl1['time'] ?? 0);
        }

        // Fallback: если вдруг time не задан, пытаемся взять из helper, как в других местах
        if ($dur_busy <= 0) {
          $cur_stats = defense_loc_get_level_stats($cfg, (int)$level);
          $cur_ex = defense_loc_extract_cost_res_time($cur_stats);
          $dur_busy = (int)($cur_ex[2] ?? 0);
        }

        $start_ts = ($end_ts > 0 && $dur_busy > 0) ? ($end_ts - $dur_busy) : 0;
        $left_sec = max(0, $end_ts - time());
      ?>
        <div style="padding: 10px 14px 0;">
          <div class="js-upgrade-progress upgrade-progress" data-start="<?= (int)$start_ts ?>" data-end="<?= (int)$end_ts ?>">
            <div class="upgrade-progress-bar"><div class="upgrade-progress-fill"></div></div>
            <div class="upgrade-progress-meta">
              <span class="upgrade-left">⏳ <?= function_exists('format_time_display') ? format_time_display($left_sec) : defense_loc_format_time($left_sec) ?></span>
              <span class="upgrade-percent"></span>
            </div>
          </div>
        </div>
<?php endif; ?>

      <div class="coc-um-body">
        <div class="coc-um-left">
          <div class="coc-um-art">
            <img loading="lazy" decoding="async" src="<?= htmlspecialchars($img, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
          </div>

          <?php if (!empty($cfg['description'])): ?>
            <div class="coc-um-desc" style="margin-top:10px; text-align:center;">
              <?= htmlspecialchars((string)($cfg['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
          <?php endif; ?>
        </div>
        <div class="coc-um-right">
          <div class="coc-um-tiles"><?php if (!$isWall): ?>

            <div class="coc-um-tile"><div class="coc-um-k">🌀 Тип</div><div class="coc-um-v"><?= htmlspecialchars($attack_type, ENT_QUOTES, 'UTF-8') ?></div></div>
            <div class="coc-um-tile"><div class="coc-um-k">🎯 Цели</div><div class="coc-um-v"><?= htmlspecialchars($targets, ENT_QUOTES, 'UTF-8') ?></div></div>
          <?php endif; ?>
</div>

          <?= $combat_compare_html ?>

          <!-- Поведение в бою — сразу под характеристиками, в одной ширине -->
          <?= defense_loc_render_battle_block($building_id, $cfg) ?>
        </div>
      </div>

      <div class="coc-um-actions">
        <?php
          // Кнопку "Строится"/"Улучшается" убираем полностью.
          // Главная кнопка показывается только когда здание НЕ занято.
          $btnCls = 'coc-um-btn coc-um-main';
          $btnDisabled = false;
          if (!$can_upgrade || !$th_ok || !$can_afford) { $btnDisabled = true; $btnCls .= ' is-locked'; }

          // Allowed resources for upgrade (for Walls we support gold/elixir choice, elixir from 9+ level only).
          $defAllowedRes = [];
          $defRt = $next_stats ? ($next_stats['res_type'] ?? ($next_stats['resource'] ?? null)) : null;
          if ($defRt === null) $defRt = $res_type;
          if (is_array($defRt)) {
              foreach ($defRt as $x) {
                  $k = function_exists('resourceConstToUserKey') ? resourceConstToUserKey($x) : null;
                  if (!$k) {
                      $sx = (string)$x;
                      if ($sx === 'RES_GOLD' || $sx === 'gold') $k = 'gold';
                      elseif ($sx === 'RES_ELIXIR' || $sx === 'elixir') $k = 'elixir';
                      elseif ($sx === 'RES_DARK' || $sx === 'dark_elixir') $k = 'dark_elixir';
                      elseif ($sx === 'RES_GEMS' || $sx === 'gems') $k = 'gems';
                  }
                  if ($k) $defAllowedRes[] = $k;
              }
          } else {
              $k = function_exists('resourceConstToUserKey') ? resourceConstToUserKey($defRt) : null;
              if (!$k) {
                  $sx = (string)$defRt;
                  if ($sx === 'RES_GOLD' || $sx === 'gold') $k = 'gold';
                  elseif ($sx === 'RES_ELIXIR' || $sx === 'elixir') $k = 'elixir';
                  elseif ($sx === 'RES_DARK' || $sx === 'dark_elixir') $k = 'dark_elixir';
                  elseif ($sx === 'RES_GEMS' || $sx === 'gems') $k = 'gems';
              }
              if ($k) $defAllowedRes[] = $k;
          }
          $defAllowedRes = array_values(array_unique(array_filter($defAllowedRes)));
          if (!$defAllowedRes) $defAllowedRes = ['gold'];
          $defNextLevel = (int)($level + 1);
          if ($isWall && $defNextLevel < 9) {
              $defAllowedRes = array_values(array_filter($defAllowedRes, function($x){ return $x !== 'elixir'; }));
              if (!$defAllowedRes) $defAllowedRes = ['gold'];
          }
          $defAllowedResStr = implode(',', $defAllowedRes);
        ?>
        <?php if (!$is_busy): ?>
          <button
            type="button"
            class="<?= $btnCls ?>"
            id="coc-def-act"
            data-defupgrade="<?= (int)$building_row_id ?>"
            data-defuname="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
            data-defudesc="<?= htmlspecialchars((string)($cfg['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            data-defustatshtml="<?= htmlspecialchars($upg_stats_html, ENT_QUOTES, 'UTF-8') ?>"
            data-defubehaviorhtml="<?= htmlspecialchars($upg_behavior_html, ENT_QUOTES, 'UTF-8') ?>"
            data-defucosthtml="<?= htmlspecialchars($upg_cost_html, ENT_QUOTES, 'UTF-8') ?>"
            data-defutime="<?= (int)$time ?>"
            data-defutimestr="<?= htmlspecialchars($upg_time_str, ENT_QUOTES, 'UTF-8') ?>"
            data-defunextlvl="<?= (int)($level + 1) ?>"
            data-defuallowedres="<?= htmlspecialchars($defAllowedResStr, ENT_QUOTES, 'UTF-8') ?>"
            data-deflockmsg="<?= htmlspecialchars($lock_msg, ENT_QUOTES, 'UTF-8') ?>"
            <?= $btnDisabled ? 'disabled="disabled"' : '' ?>
          >
            <?= htmlspecialchars($btn_text, ENT_QUOTES, 'UTF-8') ?>
          </button>
        <?php endif; ?>

        <?php if ($is_busy && (int)($building['finish_time'] ?? 0) > time()): ?>
<?php $left_for_speed = max(0, (int)($building['finish_time'] ?? 0) - time()); $speed_gems = defense_loc_gem_cost_for_seconds($left_for_speed); ?>
          <button
            type="button"
            class="coc-um-btn coc-um-speed"
            id="coc-def-speedup"
            data-defspeedup="<?= (int)$building_row_id ?>"
            data-deffinish="<?= (int)($building['finish_time'] ?? 0) ?>"
            data-defleft="<?= (int)$left_for_speed ?>"
                      data-defspeedupcost="<?= (int)$speed_gems ?>"
>УСКОРИТЬ ЗА <img class="coc-ic" src="/images/icons/gems.png" alt=""> <span class="coc-gem-cost"><?= (int)$speed_gems ?></span></button>
        <?php endif; ?>
        <div class="coc-um-chips coc-um-upgmeta">
          <?php if ($next_stats): ?>
            <div class="coc-um-chip coc-um-chip--meta"><?= $upg_cost_html ?></div>
            <div class="coc-um-chip coc-um-chip--meta">⏳ <?= htmlspecialchars($upg_time_str, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="coc-um-chip coc-um-chip--meta">🏰 <?= (int)$need_th ?></div>
          <?php else: ?>
            <div class="coc-um-chip coc-um-chip--meta">Макс. уровень</div>
          <?php endif; ?>
        </div>
        <?php if ($lock_msg && $btnDisabled && !$is_busy): ?>
          <div class="coc-um-need">🔒 <?= htmlspecialchars($lock_msg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
}


// -----------------------------------------------------------------------------
// Controller
// -----------------------------------------------------------------------------

try {
    global $mysqli, $game_data;

    $view = $_GET['view'] ?? 'main';
    $tab = defense_loc_cleanString($_GET['tab'] ?? 'ground', 20);
    $type = defense_loc_cleanString($_GET['type'] ?? '', 60);
    $id = defense_loc_toInt($_GET['id'] ?? 0);

    $tabs = defense_loc_get_tab_defs();
    if (!isset($tabs[$tab])) $tab = 'ground';

    // актуализируем пользователя
    $user = getUser($mysqli);
    $userData = $user;

    // Разрешённые типы в локации
    $allowed = array_merge(
        defense_loc_collect_types($game_data, defined('TYPE_DEFENSE') ? TYPE_DEFENSE : 'defense'),
        defense_loc_collect_types($game_data, defined('TYPE_TRAP') ? TYPE_TRAP : 'trap'),
        defense_loc_collect_types($game_data, defined('TYPE_WALL') ? TYPE_WALL : 'wall')
    );

    switch ($view) {
        case 'main':
            echo renderDefenseMainView($userData, $tab);
            break;

        case 'list':
            if ($type === '' || !in_array($type, $allowed, true)) {
                throw new RuntimeException('Не указан или недопустимый тип здания', 400);
            }
            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderDefenseListView($userData, $type, $buildings, $tab);
            break;

        case 'detail':
            if ($id === 0) throw new RuntimeException('Не указан ID здания', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание не найдено', 404);
            echo renderDefenseDetailView($userData, $building, $tab);
            break;

        case 'buy':
            if ($type === '' || !in_array($type, $allowed, true)) {
                throw new RuntimeException('Не указан или недопустимый тип здания для покупки', 400);
            }

            // Walls: allow building multiple at once (qty) from confirm modal.
            $qty = (int)($_GET['qty'] ?? 1);
            if ($qty < 1) $qty = 1;
            if ($qty > 9999) $qty = 9999;

            if ($type === 'wall' && $qty > 1) {
                // Build as many as possible up to qty (respecting TH cap and resources).
                $built = 0;
                $mysqli->begin_transaction();
                try {
                    for ($i = 0; $i < $qty; $i++) {
                        $result = buildNewBuilding($mysqli, $userData, $type);
                        $userData = $result['user'] ?? $userData;
                        $built++;
                    }
                    $mysqli->commit();
                } catch (Throwable $e) {
                    // If at least one wall was built, commit partial progress; otherwise rollback and rethrow.
                    try {
                        if ($built > 0) $mysqli->commit();
                        else $mysqli->rollback();
                    } catch (Throwable $_e2) {}

                    if ($built <= 0) throw $e;
                }
            } else {
                $result = buildNewBuilding($mysqli, $userData, $type);
                $userData = $result['user'] ?? $userData;
            }

            $buildings = getPlayerBuildingsByType($mysqli, $type);
            echo renderDefenseListView($userData, $type, $buildings, $tab);
            break;

        case 'upgrade':
            if ($id === 0) throw new RuntimeException('Не указан ID здания для улучшения', 400);
            $building = getPlayerBuildingById($mysqli, $id);
            if (!$building) throw new RuntimeException('Здание для улучшения не найдено', 404);

            // Optional resource choice (walls can be upgraded by elixir starting from level 9+).
            $resChoice = null;
            if (isset($_GET['res'])) $resChoice = strtolower(trim((string)$_GET['res']));
            elseif (isset($_POST['res'])) $resChoice = strtolower(trim((string)$_POST['res']));
            if ($resChoice !== 'gold' && $resChoice !== 'elixir') $resChoice = null;

            // Enforce wall rule & consistent resource deduction on server side too.
            $bTypeCandidates = [];
            foreach (['type','building_type','building_id','code','slug','name'] as $__k) {
                if (isset($building[$__k])) $bTypeCandidates[] = strtolower(trim((string)$building[$__k]));
            }
            $isWall = false;
            foreach ($bTypeCandidates as $__v) {
                if ($__v === 'wall' || $__v === 'walls' || preg_match('/^wall(_|$)/', $__v)) { $isWall = true; break; }
            }
            $lvlCur = (int)($building['level'] ?? $building['lvl'] ?? 0);
            $nextLvl = $lvlCur + 1;

            // ---- Walls: do the upgrade & resource deduction here (instant upgrades like in CoC) ----
            // In this project the generic startBuildingUpgrade() path is inconsistent for walls:
            //  - elixir choice may be ignored
            //  - resource deduction may not happen (or happens intermittently)
            // So for walls we rely on the wall config + explicit SQL transaction.
            if ($isWall) {
                $wallCfg = $game_data['wall'] ?? null;
                if (!$wallCfg) throw new RuntimeException('Конфиг стен не найден', 500);
                $levels = (array)($wallCfg['levels'] ?? []);

                if (!isset($levels[$lvlCur]) || !isset($levels[$nextLvl])) {
                    throw new RuntimeException('Нельзя улучшить этот уровень стен', 400);
                }

                $next_stats = (array)$levels[$nextLvl];
                $cost = (int)($next_stats['cost'] ?? 0);
                // Some configs may store cost per-resource. Support both.
                if ($cost <= 0) {
                    foreach (['gold','elixir','dark_elixir'] as $rk) {
                        if (isset($next_stats[$rk]) && (int)$next_stats[$rk] > 0) { $cost = (int)$next_stats[$rk]; break; }
                        if (isset($next_stats[$rk.'_cost']) && (int)$next_stats[$rk.'_cost'] > 0) { $cost = (int)$next_stats[$rk.'_cost']; break; }
                    }
                }
                $time = (int)($next_stats['time'] ?? 0);
                $th_req = (int)($next_stats['th_req'] ?? 1);
                $th_lvl = (int)($userData['townhall_lvl'] ?? 1);
                if ($th_lvl < $th_req) throw new RuntimeException('Требуется Ратуша уровня ' . $th_req . '.', 400);
                if ($cost <= 0) throw new RuntimeException('Некорректная стоимость улучшения стен', 500);

                // Allowed resources from config (res_type can be string or array). Force CoC rule: elixir from 9+.
                $res_type = $next_stats['res_type'] ?? 'gold';
                $allowedRes = [];
                if (is_array($res_type)) {
                    foreach ($res_type as $x) {
                        $k = function_exists('resourceConstToUserKey') ? resourceConstToUserKey($x) : null;
                        if (!$k) {
                            $sx = (string)$x;
                            if ($sx === 'RES_GOLD' || $sx === 'gold') $k = 'gold';
                            elseif ($sx === 'RES_ELIXIR' || $sx === 'elixir') $k = 'elixir';
                            elseif ($sx === 'RES_DARK' || $sx === 'dark_elixir') $k = 'dark_elixir';
                        }
                        if ($k) $allowedRes[] = $k;
                    }
                } else {
                    $k = function_exists('resourceConstToUserKey') ? resourceConstToUserKey($res_type) : null;
                    if (!$k) {
                        $sx = (string)$res_type;
                        if ($sx === 'RES_GOLD' || $sx === 'gold') $k = 'gold';
                        elseif ($sx === 'RES_ELIXIR' || $sx === 'elixir') $k = 'elixir';
                        elseif ($sx === 'RES_DARK' || $sx === 'dark_elixir') $k = 'dark_elixir';
                    }
                    if ($k) $allowedRes[] = $k;
                }
                $allowedRes = array_values(array_unique(array_filter($allowedRes)));
                if (!$allowedRes) $allowedRes = ['gold'];
                if ($nextLvl < 9) {
                    $allowedRes = array_values(array_filter($allowedRes, function($x){ return $x !== 'elixir'; }));
                    if (!$allowedRes) $allowedRes = ['gold'];
                }

                // Final resource choice (never silently fallback).
                if (!$resChoice) $resChoice = $allowedRes[0];
                if (!in_array($resChoice, $allowedRes, true)) {
                    throw new RuntimeException('Этот ресурс недоступен для данного уровня стен.', 400);
                }

                $uid = (int)($userData['id'] ?? 0);
                if ($uid <= 0) throw new RuntimeException('Не авторизовано', 401);
                $have = (int)($userData[$resChoice] ?? 0);
                if ($have < $cost) {
                    if (function_exists('throwNotEnoughResources')) {
                        throwNotEnoughResources($resChoice, $cost, $have, 'улучшение стен');
                    }
                    throw new RuntimeException('Недостаточно ресурсов для улучшения стен', 400);
                }

                $mysqli->begin_transaction();
                try {
                    // Deduct resources (atomic, uses DB as source of truth)
                    $col = $resChoice; // 'gold' or 'elixir' (validated above)
                    $stmtU = $mysqli->prepare("UPDATE users SET {$col} = {$col} - ? WHERE id=? AND {$col} >= ?");
                    $stmtU->bind_param('iii', $cost, $uid, $cost);
                    $stmtU->execute();
                    $affectedU = (int)$stmtU->affected_rows;
                    $stmtU->close();
                    if ($affectedU <= 0) {
                        // balance changed or not enough
                        throw new RuntimeException('Недостаточно ресурсов для улучшения стен', 400);
                    }

                    // Read back new balance
                    $stmtS = $mysqli->prepare("SELECT {$col} FROM users WHERE id=? LIMIT 1");
                    $stmtS->bind_param('i', $uid);
                    $stmtS->execute();
                    $stmtS->bind_result($newBalance);
                    $stmtS->fetch();
                    $stmtS->close();

                    if ($time <= 0) {
                        // Instant upgrade this wall segment
                        $stmtB = $mysqli->prepare("UPDATE player_buildings SET level=?, status='active', target_level=NULL, finish_time=0 WHERE id=? AND user_id=? AND level=?");
                        $stmtB->bind_param('iiii', $nextLvl, $id, $uid, $lvlCur);
                        $stmtB->execute();
                        $affected = (int)$stmtB->affected_rows;
                        $stmtB->close();
                        if ($affected <= 0) {
                            throw new RuntimeException('Не удалось улучшить стену (возможно, уровень уже изменился)', 409);
                        }
                    }

                    $mysqli->commit();
                    $userData[$resChoice] = $newBalance;
                } catch (Throwable $e) {
                    $mysqli->rollback();
                    throw $e;
                }

                if ($time > 0) {
                    // Timed wall upgrade (not expected, but supported): start timer after we deducted.
                    $_POST['res'] = $resChoice;
                    $_REQUEST['res'] = $resChoice;
                    $useResParam = false;
                    try {
                        $rf = new ReflectionFunction('startBuildingUpgrade');
                        if ($rf->getNumberOfParameters() >= 4) $useResParam = true;
                    } catch (Throwable $_e) {}
                    $userData = $useResParam
                        ? startBuildingUpgrade($mysqli, $userData, $building, $resChoice)
                        : startBuildingUpgrade($mysqli, $userData, $building);
                }

                $building_updated = getPlayerBuildingById($mysqli, $id);
                echo renderDefenseDetailView($userData, $building_updated ?: $building, $tab);
                break;
            }

            // Try to pass resource choice into game logic without breaking older signatures.
            if ($resChoice) {
                $_POST['res'] = $resChoice;
                $_REQUEST['res'] = $resChoice;
            }
            $useResParam = false;
            try {
                $rf = new ReflectionFunction('startBuildingUpgrade');
                if ($rf->getNumberOfParameters() >= 4) $useResParam = true;
            } catch (Throwable $_e) {}

            $userData = $useResParam
                ? startBuildingUpgrade($mysqli, $userData, $building, $resChoice)
                : startBuildingUpgrade($mysqli, $userData, $building);

            $building_updated = getPlayerBuildingById($mysqli, $id);
            echo renderDefenseDetailView($userData, $building_updated ?: $building, $tab);
            break;

        default:
            echo renderDefenseMainView($userData, $tab);
            break;
    }

} catch (Throwable $e) {
    $code = (int)$e->getCode();
    if ($code < 400 || $code > 599) {
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
      <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('defense-modal')"><img src="/images/icons/close.png" alt="Закрыть"></button>
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
      <div class="error" style="margin: 20px;">❌ Ошибка: <?= htmlspecialchars($publicMessage, ENT_QUOTES, 'UTF-8') ?></div>
    </div>
    <?php
}