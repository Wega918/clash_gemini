<?php
require_once __DIR__ . '/../system/function.php';
require_once __DIR__ . '/../system/game_data.php';
require_once __DIR__ . '/../system/army_helpers.php';

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(0);

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $mysqli, $game_data;
$attackerId = (int)($_SESSION['user_id'] ?? 0);
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'bootstrap');

raidEnsureTables($mysqli);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (function_exists('verifyCsrfAjax')) {
            verifyCsrfAjax();
        } elseif (function_exists('verifyCsrfPost')) {
            verifyCsrfPost();
        }
    }

    switch ($action) {
        case 'bootstrap':
            raidJson([
                'ok' => true,
                'player' => raidGetPlayerSummary($mysqli, $attackerId),
                'army' => raidGetArmyRoster($mysqli, $attackerId, $game_data),
                'next_cost' => raidNextSearchCost($mysqli, $attackerId),
            ]);
            break;

        case 'search_opponent':
            $reroll = !empty($_POST['reroll']);
            if ($reroll) {
                $cost = raidNextSearchCost($mysqli, $attackerId);
                raidChargeGold($mysqli, $attackerId, $cost);
            }
            $target = raidFindTarget($mysqli, $attackerId, $game_data);
            raidJson([
                'ok' => true,
                'target' => $target,
                'player' => raidGetPlayerSummary($mysqli, $attackerId),
                'army' => raidGetArmyRoster($mysqli, $attackerId, $game_data),
                'next_cost' => raidNextSearchCost($mysqli, $attackerId),
            ]);
            break;

        case 'start_raid':
            $defenderId = (int)($_POST['defender_id'] ?? 0);
            if ($defenderId <= 0 || $defenderId === $attackerId) throw new RuntimeException('bad_defender');
            $target = raidBuildTargetSnapshot($mysqli, $defenderId, $game_data);
            $army = raidGetArmyRoster($mysqli, $attackerId, $game_data);
            if (empty($army['troops']) && empty($army['heroes'])) throw new RuntimeException('Армия пуста. Подготовьте войска.');
            $raidId = raidCreateBattle($mysqli, $attackerId, $defenderId, [
                'target' => $target,
                'army' => $army,
                'player' => raidGetPlayerSummary($mysqli, $attackerId),
                'started_at' => time(),
            ]);
            raidJson([
                'ok' => true,
                'raid' => [
                    'id' => $raidId,
                    'target' => $target,
                    'army' => $army,
                    'player' => raidGetPlayerSummary($mysqli, $attackerId),
                ],
            ]);
            break;

        case 'resolve_raid':
            $raidId = (int)($_POST['raid_id'] ?? 0);
            $payloadRaw = (string)($_POST['result_json'] ?? '');
            $result = json_decode($payloadRaw, true);
            if (!$raidId || !is_array($result)) throw new RuntimeException('bad_result');
            $resolved = raidResolveBattle($mysqli, $attackerId, $raidId, $result);
            raidJson(['ok' => true, 'result' => $resolved]);
            break;

        default:
            raidJson(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (Throwable $e) {
    raidJson(['ok' => false, 'error' => $e->getMessage()], 400);
}

function raidJson(array $payload, int $status = 200): void {
    http_response_code($status);
    $payload['csrf_token'] = $_SESSION['csrf_token'] ?? generateCsrfToken();
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function raidEnsureTables(mysqli $mysqli): void {
    static $done = false;
    if ($done) return;
    $done = true;
    @$mysqli->query("CREATE TABLE IF NOT EXISTS raid_battles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        attacker_id INT UNSIGNED NOT NULL,
        defender_id INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'running',
        snapshot_json LONGTEXT NOT NULL,
        result_json LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_attacker (attacker_id),
        KEY idx_defender (defender_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function raidResearchJoinColumn(mysqli $mysqli): string {
    static $col = null;
    if ($col !== null) return $col;
    $col = 'tech_id';
    $res = @$mysqli->query("SHOW COLUMNS FROM player_research");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $field = (string)($row['Field'] ?? '');
            if ($field === 'entity_id') { $col = 'entity_id'; break; }
            if ($field === 'tech_id') { $col = 'tech_id'; }
        }
        $res->close();
    }
    return $col;
}

function raidNormalizeLevel(array $cfg, int $level): int {
    $levels = array_keys((array)($cfg['levels'] ?? []));
    if (!$levels) return max(1, $level);
    sort($levels, SORT_NUMERIC);
    if (isset($cfg['levels'][$level])) return $level;
    $best = (int)end($levels);
    foreach ($levels as $lvl) {
        if ($lvl <= $level) $best = (int)$lvl;
    }
    return $best;
}

function raidGetPlayerSummary(mysqli $mysqli, int $userId): array {
    $stmt = $mysqli->prepare("SELECT id, login, gold, elixir, dark_elixir, trophies FROM users WHERE id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('player_prepare_failed');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();
    $row['townhall_level'] = raidGetTownhallLevel($mysqli, $userId);
    $row['avatar'] = '/images/icons/trophy.png';
    return [
        'id' => (int)($row['id'] ?? $userId),
        'login' => (string)($row['login'] ?? ('Player #' . $userId)),
        'gold' => (int)($row['gold'] ?? 0),
        'elixir' => (int)($row['elixir'] ?? 0),
        'dark_elixir' => (int)($row['dark_elixir'] ?? 0),
        'trophies' => (int)($row['trophies'] ?? 0),
        'townhall_level' => (int)($row['townhall_level'] ?? 1),
    ];
}

function raidGetTownhallLevel(mysqli $mysqli, int $userId): int {
    $stmt = $mysqli->prepare("SELECT MAX(level) lvl FROM player_buildings WHERE user_id=? AND building_id='townhall'");
    if (!$stmt) return 1;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();
    return max(1, (int)($row['lvl'] ?? 1));
}

function raidNextSearchCost(mysqli $mysqli, int $userId): int {
    $summary = raidGetPlayerSummary($mysqli, $userId);
    $trophies = max(0, (int)$summary['trophies']);
    if ($trophies >= 2600) return 2000;
    if ($trophies >= 1600) return 1500;
    if ($trophies >= 800) return 1200;
    return 1000;
}

function raidChargeGold(mysqli $mysqli, int $userId, int $cost): void {
    $stmt = $mysqli->prepare("UPDATE users SET gold = gold - ? WHERE id = ? AND gold >= ?");
    if (!$stmt) throw new RuntimeException('gold_prepare_failed');
    $stmt->bind_param('iii', $cost, $userId, $cost);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$ok) throw new RuntimeException('Недостаточно золота для смены цели');
}

function raidFindTarget(mysqli $mysqli, int $attackerId, array $gameData): array {
    $attacker = raidGetPlayerSummary($mysqli, $attackerId);
    $minTrophies = max(0, (int)$attacker['trophies'] - 500);
    $maxTrophies = (int)$attacker['trophies'] + 500;

    $stmt = $mysqli->prepare("SELECT u.id
        FROM users u
        WHERE u.id <> ?
          AND u.trophies BETWEEN ? AND ?
          AND EXISTS (SELECT 1 FROM player_buildings pb WHERE pb.user_id = u.id AND pb.building_id='townhall')
        ORDER BY RAND()
        LIMIT 1");
    if (!$stmt) throw new RuntimeException('target_prepare_failed');
    $stmt->bind_param('iii', $attackerId, $minTrophies, $maxTrophies);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();
    $defenderId = (int)($row['id'] ?? 0);
    if ($defenderId <= 0) {
        $stmt = $mysqli->prepare("SELECT u.id FROM users u WHERE u.id <> ? ORDER BY RAND() LIMIT 1");
        if (!$stmt) throw new RuntimeException('target_prepare_failed_fallback');
        $stmt->bind_param('i', $attackerId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? ($res->fetch_assoc() ?: []) : [];
        $stmt->close();
        $defenderId = (int)($row['id'] ?? 0);
    }
    if ($defenderId <= 0) throw new RuntimeException('Цель не найдена');
    return raidBuildTargetSnapshot($mysqli, $defenderId, $gameData);
}

function raidBuildTargetSnapshot(mysqli $mysqli, int $defenderId, array $gameData): array {
    $player = raidGetPlayerSummary($mysqli, $defenderId);
    $base = raidBuildBaseModel($mysqli, $defenderId, $gameData);
    return [
        'user_id' => $player['id'],
        'login' => $player['login'],
        'townhall_level' => $player['townhall_level'],
        'trophies' => $player['trophies'],
        'resources' => $base['resource_caps'],
        'base' => $base,
    ];
}

function raidGetArmyRoster(mysqli $mysqli, int $userId, array $gameData): array {
    $researchCol = raidResearchJoinColumn($mysqli);
    $troops = [];
    $heroes = [];
    $spells = [];

    $stmt = $mysqli->prepare("SELECT pa.unit_id, pa.amount, COALESCE(pr.level,1) AS level
        FROM player_army pa
        LEFT JOIN player_research pr ON pr.user_id = pa.user_id AND BINARY pr." . $researchCol . " = BINARY pa.unit_id
        WHERE pa.user_id = ? AND pa.amount > 0
        ORDER BY pa.amount DESC, pa.unit_id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $id = (string)$row['unit_id'];
            $cfg = $gameData[$id] ?? null;
            if (!$cfg) continue;
            $type = (string)($cfg['type'] ?? '');
            if (!in_array($type, [TYPE_TROOP, TYPE_DARK_TROOP, TYPE_SUPER_TROOP, TYPE_SIEGE], true)) continue;
            $troops[] = raidNormalizeCombatEntity($id, $cfg, (int)$row['level'], (int)$row['amount'], 'troop');
        }
        $stmt->close();
    }

    $stmt = $mysqli->prepare("SELECT ph.hero_id, ph.level
        FROM player_heroes ph
        WHERE ph.user_id = ? AND ph.unlocked = 1 AND ph.level > 0 AND ph.upgrading_until <= UNIX_TIMESTAMP()");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $id = (string)$row['hero_id'];
            $cfg = $gameData[$id] ?? null;
            if (!$cfg || (string)($cfg['type'] ?? '') !== TYPE_HERO) continue;
            $heroes[] = raidNormalizeCombatEntity($id, $cfg, (int)$row['level'], 1, 'hero');
        }
        $stmt->close();
    }

    $stmt = $mysqli->prepare("SELECT ps.spell_id, ps.amount, COALESCE(pr.level,1) AS level
        FROM player_spells ps
        LEFT JOIN player_research pr ON pr.user_id = ps.user_id AND BINARY pr." . $researchCol . " = BINARY ps.spell_id
        WHERE ps.user_id = ? AND ps.amount > 0
        ORDER BY ps.amount DESC, ps.spell_id ASC");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $id = (string)$row['spell_id'];
            $cfg = $gameData[$id] ?? null;
            if (!$cfg) continue;
            $type = (string)($cfg['type'] ?? '');
            if (!in_array($type, [TYPE_SPELL, TYPE_DARK_SPELL], true)) continue;
            $spells[] = raidNormalizeCombatEntity($id, $cfg, (int)$row['level'], (int)$row['amount'], 'spell');
        }
        $stmt->close();
    }

    return ['troops' => $troops, 'heroes' => $heroes, 'spells' => $spells];
}

function raidNormalizeCombatEntity(string $id, array $cfg, int $level, int $count, string $kind): array {
    $level = raidNormalizeLevel($cfg, $level);
    $lvl = (array)($cfg['levels'][$level] ?? []);
    $type = (string)($cfg['type'] ?? '');
    $attackTypeText = mb_strtolower((string)($cfg['attack_type'] ?? ''), 'UTF-8');
    $preferredTargetText = mb_strtolower((string)($cfg['preferred_target'] ?? ''), 'UTF-8');
    $name = (string)($cfg['name'] ?? $id);
    $damage = (float)($lvl['damage_per_second'] ?? $lvl['dps'] ?? $lvl['damage_per_attack'] ?? 0);
    $attackSpeed = (float)($cfg['attack_speed'] ?? 1.0);
    if ($attackSpeed <= 0) $attackSpeed = 1.0;
    $movement = str_contains($attackTypeText, 'воздух') && !str_contains($attackTypeText, 'земл') ? 'air' : 'ground';
    if (in_array($id, ['balloon','dragon','baby_dragon','electro_dragon','dragon_rider','healer','lava_hound','minion'], true)) $movement = 'air';
    if (in_array($id, ['grand_warden','minion_prince'], true)) $movement = 'hybrid';
    $canHitGround = !str_contains($attackTypeText, 'только воздух');
    $canHitAir = str_contains($attackTypeText, 'воздух') || in_array($movement, ['air','hybrid'], true) || in_array($id, ['archer','wizard','dragon','baby_dragon','electro_dragon','minion','healer','grand_warden','archer_queen','royal_champion'], true);
    $range = (float)($cfg['range'] ?? $lvl['range'] ?? ($kind === 'hero' ? (in_array($id, ['archer_queen','royal_champion'], true) ? 5.5 : 1.2) : 1.1));
    if ($range <= 0.4 && !str_contains($attackTypeText, 'даль')) $range = 1.05;
    $priority = 'any';
    if (str_contains($preferredTargetText, 'защит')) $priority = 'defense';
    if (str_contains($preferredTargetText, 'ресурс')) $priority = 'resource';
    if (str_contains($preferredTargetText, 'ратуш')) $priority = 'townhall';
    if (str_contains($preferredTargetText, 'стен')) $priority = 'wall';
    if ($id === 'wall_breaker' || $id === 'super_wall_breaker' || $id === 'wall_wrecker') $priority = 'wall';
    if ($id === 'giant' || $id === 'golem' || $id === 'ice_golem' || $id === 'hog_rider' || $id === 'lava_hound') $priority = 'defense';

    $abilityId = '';
    if ($kind === 'hero') {
        $abilityId = match($id) {
            'barbarian_king' => 'iron_fist',
            'archer_queen' => 'royal_cloak',
            'grand_warden' => 'eternal_tome',
            'royal_champion' => 'seeking_shield',
            'minion_prince' => 'dark_command',
            default => 'hero_burst',
        };
    }
    $spellEffect = '';
    if ($kind === 'spell') {
        $spellEffect = match($id) {
            'rage_spell' => 'rage',
            'healing_spell' => 'heal',
            'freeze_spell' => 'freeze',
            'lightning_spell' => 'lightning',
            'poison_spell' => 'poison',
            'earthquake_spell' => 'earthquake',
            'jump_spell' => 'jump',
            'haste_spell' => 'haste',
            'clone_spell' => 'clone',
            'invisibility_spell' => 'invisibility',
            default => 'generic',
        };
    }

    return [
        'id' => $id,
        'name' => $name,
        'kind' => $kind,
        'type' => $type,
        'level' => $level,
        'count' => $count,
        'icon' => raidEntityIcon($id, $kind),
        'housing' => (int)($cfg['housing_space'] ?? 1),
        'movement' => $movement,
        'speed' => (float)($cfg['move_speed'] ?? ($movement === 'air' ? 26 : 18)),
        'range' => $range,
        'attackSpeed' => $attackSpeed,
        'dps' => $damage,
        'damagePerHit' => (float)($lvl['damage_per_attack'] ?? ($damage * $attackSpeed)),
        'hp' => (float)($lvl['health'] ?? $lvl['hp'] ?? 1),
        'canHitGround' => $canHitGround,
        'canHitAir' => $canHitAir,
        'targetPriority' => $priority,
        'wallBreaker' => in_array($id, ['wall_breaker','super_wall_breaker','wall_wrecker'], true),
        'wallDamageMultiplier' => in_array($id, ['wall_breaker','super_wall_breaker'], true) ? 18 : (in_array($id, ['pekka','root_rider','wall_wrecker'], true) ? 2.2 : 1.0),
        'splash' => raidInferSplash($id, $cfg, $kind),
        'summon' => $id === 'witch' ? ['unit' => 'skeleton', 'count' => 2, 'interval' => 8] : null,
        'heroAbility' => $abilityId,
        'spellEffect' => $spellEffect,
        'spellRadius' => (float)($cfg['radius'] ?? 0),
        'spellDuration' => (float)($lvl['duration'] ?? $cfg['spell_duration'] ?? $lvl['freeze_time'] ?? 0),
        'healPerSecond' => (float)($lvl['heal_per_second'] ?? $lvl['hero_healing_per_second'] ?? 0),
        'freezeTime' => (float)($lvl['freeze_time'] ?? 0),
        'boostMultiplier' => $spellEffect === 'rage' ? 1.45 : ($spellEffect === 'haste' ? 1.35 : 1.0),
    ];
}

function raidInferSplash(string $id, array $cfg, string $kind): float {
    $text = mb_strtolower((string)($cfg['attack_type'] ?? ''), 'UTF-8');
    if ($kind === 'spell') return (float)($cfg['radius'] ?? 0);
    if (str_contains($text, 'площад')) return 1.5;
    if (in_array($id, ['dragon','electro_dragon','wizard','valkyrie','baby_dragon'], true)) return 1.4;
    return 0.0;
}

function raidBuildBaseModel(mysqli $mysqli, int $userId, array $gameData): array {
    $stmt = $mysqli->prepare("SELECT id, building_id, level, x, y, status, stored_resource FROM player_buildings WHERE user_id=? AND status IN ('active','constructing','upgrading') ORDER BY id ASC");
    if (!$stmt) throw new RuntimeException('base_prepare_failed');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();

    $buildings = [];
    $walls = [];
    $resourceCaps = ['gold' => 0, 'elixir' => 0, 'dark_elixir' => 0];
    $wallBuckets = [];
    while ($row = $res->fetch_assoc()) {
        $buildingId = (string)$row['building_id'];
        $cfg = $gameData[$buildingId] ?? null;
        if (!$cfg) continue;
        $level = raidNormalizeLevel($cfg, (int)$row['level']);
        $lvl = (array)($cfg['levels'][$level] ?? []);
        $lane = max(0, min(2, (int)floor((((int)$row['y']) / 45) * 3)));
        $segment = max(0, min(4, (int)floor((((int)$row['x']) / 45) * 5)));
        $category = raidBuildingCategory($buildingId, $cfg);
        $loot = raidBuildingLoot($buildingId, (float)($row['stored_resource'] ?? 0), $category);
        foreach ($loot as $rk => $rv) $resourceCaps[$rk] += $rv;

        if ($category === 'wall') {
            $key = $lane . ':' . $segment;
            if (!isset($wallBuckets[$key])) {
                $wallBuckets[$key] = [
                    'id' => 'wall_' . $lane . '_' . $segment,
                    'lane' => $lane,
                    'segment' => $segment,
                    'kind' => 'wall',
                    'name' => 'Стена',
                    'icon' => raidBuildingIcon('wall', $level, 'wall'),
                    'hp' => 0,
                    'maxHp' => 0,
                    'count' => 0,
                    'crackThresholds' => [0.75, 0.5, 0.25],
                ];
            }
            $hp = (float)($lvl['hp'] ?? 1000);
            $wallBuckets[$key]['hp'] += $hp;
            $wallBuckets[$key]['maxHp'] += $hp;
            $wallBuckets[$key]['count'] += 1;
            continue;
        }

        $dps = (float)($lvl['dps'] ?? $lvl['dps_multi'] ?? $lvl['damage'] ?? 0);
        $range = raidDefenseRange($buildingId, $cfg, $lvl);
        $hidden = in_array($buildingId, ['hidden_tesla','bomb','air_bomb','giant_bomb','seeking_air_mine','skeleton_trap','tornado_trap'], true);
        $targets = (string)($cfg['targets'] ?? ($buildingId === 'air_defense' ? 'air' : 'ground'));
        $buildings[] = [
            'id' => 'b' . (int)$row['id'],
            'instanceId' => (int)$row['id'],
            'buildingId' => $buildingId,
            'name' => (string)($cfg['name'] ?? $buildingId),
            'lane' => $lane,
            'segment' => $segment,
            'kind' => $category,
            'icon' => raidBuildingIcon($buildingId, $level, $category),
            'level' => $level,
            'hp' => (float)($lvl['hp'] ?? 100),
            'maxHp' => (float)($lvl['hp'] ?? 100),
            'dps' => $dps,
            'range' => $range,
            'attackType' => (string)($cfg['attack_type'] ?? 'single'),
            'targets' => $targets,
            'hidden' => $hidden,
            'hiddenTrigger' => raidHiddenTrigger($buildingId),
            'loot' => $loot,
            'priorityWeight' => raidDefensePriorityWeight($buildingId, $category),
            'effects' => array_values((array)($cfg['effects'] ?? [])),
            'splashRadius' => raidDefenseSplashRadius($buildingId),
        ];
    }
    $stmt->close();
    $walls = array_values($wallBuckets);

    usort($buildings, static function(array $a, array $b): int {
        if ($a['segment'] !== $b['segment']) return $a['segment'] <=> $b['segment'];
        if ($a['lane'] !== $b['lane']) return $a['lane'] <=> $b['lane'];
        return ($b['priorityWeight'] ?? 0) <=> ($a['priorityWeight'] ?? 0);
    });

    return [
        'buildings' => $buildings,
        'walls' => $walls,
        'resource_caps' => $resourceCaps,
        'segments' => 5,
        'lanes' => 3,
    ];
}

function raidBuildingCategory(string $buildingId, array $cfg): string {
    $type = (string)($cfg['type'] ?? 'resource');
    if ($buildingId === 'townhall') return 'townhall';
    if ($type === TYPE_WALL) return 'wall';
    if ($type === TYPE_TRAP) return 'trap';
    if ($type === TYPE_DEFENSE) return 'defense';
    if (str_contains($buildingId, 'storage') || str_contains($buildingId, 'mine') || str_contains($buildingId, 'collector') || str_contains($buildingId, 'drill')) return 'resource';
    return 'building';
}

function raidBuildingLoot(string $buildingId, float $stored, string $category): array {
    $out = ['gold' => 0, 'elixir' => 0, 'dark_elixir' => 0];
    if ($category === 'resource') {
        if (str_contains($buildingId, 'gold')) $out['gold'] = (int)round($stored);
        elseif (str_contains($buildingId, 'dark')) $out['dark_elixir'] = (int)round($stored);
        else $out['elixir'] = (int)round($stored);
        return $out;
    }
    if ($buildingId === 'townhall') return ['gold' => 12000, 'elixir' => 12000, 'dark_elixir' => 240];
    if ($category === 'defense') return ['gold' => 900, 'elixir' => 900, 'dark_elixir' => 0];
    return $out;
}

function raidDefenseRange(string $buildingId, array $cfg, array $lvl): float {
    if (isset($lvl['range'])) return (float)$lvl['range'];
    return match($buildingId) {
        'mortar' => 5.2,
        'wizard_tower' => 3.6,
        'archer_tower' => 5.5,
        'air_defense' => 4.8,
        'hidden_tesla' => 4.6,
        'x_bow' => 6.4,
        'inferno_tower' => 5.1,
        'eagle_artillery' => 7.6,
        'scattershot' => 6.2,
        'monolith' => 5.0,
        default => 3.8,
    };
}

function raidDefenseSplashRadius(string $buildingId): float {
    return match($buildingId) {
        'mortar' => 1.5,
        'wizard_tower' => 1.3,
        'bomb_tower' => 1.3,
        'scattershot' => 1.6,
        default => 0.0,
    };
}

function raidDefensePriorityWeight(string $buildingId, string $category): int {
    if ($buildingId === 'townhall') return 100;
    if ($category === 'defense') {
        return match($buildingId) {
            'inferno_tower' => 95,
            'eagle_artillery' => 94,
            'monolith' => 93,
            'x_bow' => 90,
            'air_defense' => 88,
            'wizard_tower' => 85,
            'hidden_tesla' => 83,
            default => 78,
        };
    }
    if ($category === 'resource') return 35;
    return 20;
}

function raidHiddenTrigger(string $buildingId): array {
    return match($buildingId) {
        'hidden_tesla' => ['distance' => 1.65, 'target' => 'any'],
        'bomb' => ['distance' => 0.9, 'target' => 'ground'],
        'air_bomb', 'seeking_air_mine' => ['distance' => 1.1, 'target' => 'air'],
        'giant_bomb' => ['distance' => 1.0, 'target' => 'ground'],
        'skeleton_trap' => ['distance' => 1.1, 'target' => 'any'],
        default => ['distance' => 0.95, 'target' => 'any'],
    };
}

function raidCreateBattle(mysqli $mysqli, int $attackerId, int $defenderId, array $snapshot): int {
    $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    $stmt = $mysqli->prepare("INSERT INTO raid_battles (attacker_id, defender_id, status, snapshot_json) VALUES (?, ?, 'running', ?)");
    if (!$stmt) throw new RuntimeException('raid_create_failed');
    $stmt->bind_param('iis', $attackerId, $defenderId, $json);
    $stmt->execute();
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

function raidResolveBattle(mysqli $mysqli, int $attackerId, int $raidId, array $result): array {
    $stmt = $mysqli->prepare("SELECT * FROM raid_battles WHERE id=? AND attacker_id=? LIMIT 1");
    if (!$stmt) throw new RuntimeException('raid_load_failed');
    $stmt->bind_param('ii', $raidId, $attackerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? ($res->fetch_assoc() ?: []) : [];
    $stmt->close();
    if (!$row) throw new RuntimeException('raid_not_found');
    if ((string)($row['status'] ?? '') !== 'running') throw new RuntimeException('raid_already_settled');

    $snapshot = json_decode((string)$row['snapshot_json'], true) ?: [];
    $target = (array)($snapshot['target'] ?? []);
    $player = raidGetPlayerSummary($mysqli, $attackerId);
    $available = (array)(($target['resources'] ?? []));
    $destruction = max(0, min(100, (int)($result['destructionPercent'] ?? 0)));
    $stars = max(0, min(3, (int)($result['stars'] ?? 0)));

    $maxFactor = min(0.82, 0.16 + ($destruction / 100) * 0.66);
    $requestedLoot = (array)($result['loot'] ?? []);
    $loot = [
        'gold' => min((int)($requestedLoot['gold'] ?? 0), (int)floor(((int)($available['gold'] ?? 0)) * $maxFactor)),
        'elixir' => min((int)($requestedLoot['elixir'] ?? 0), (int)floor(((int)($available['elixir'] ?? 0)) * $maxFactor)),
        'dark_elixir' => min((int)($requestedLoot['dark_elixir'] ?? 0), (int)floor(((int)($available['dark_elixir'] ?? 0)) * $maxFactor)),
    ];
    $bonus = raidLeagueBonus($stars, $destruction);
    foreach ($bonus as $k => $v) $loot[$k] += $v;

    $trophyDelta = raidTrophyDelta($stars, $destruction, (int)($player['trophies'] ?? 0), (int)($target['trophies'] ?? 0));

    $mysqli->begin_transaction();
    try {
        $stmt = $mysqli->prepare("UPDATE users
            SET gold = CAST(gold AS SIGNED) + ?, 
                elixir = CAST(elixir AS SIGNED) + ?, 
                dark_elixir = CAST(dark_elixir AS SIGNED) + ?, 
                trophies = GREATEST(0, trophies + ?)
            WHERE id=?");
        if (!$stmt) throw new RuntimeException('attacker_reward_failed');
        $stmt->bind_param('iiiii', $loot['gold'], $loot['elixir'], $loot['dark_elixir'], $trophyDelta, $attackerId);
        $stmt->execute();
        $stmt->close();

        $defenderId = (int)($row['defender_id'] ?? 0);
        $negTrophy = -max(0, $trophyDelta);
        $stmt = $mysqli->prepare("UPDATE users
            SET gold = GREATEST(0, gold - ?),
                elixir = GREATEST(0, elixir - ?),
                dark_elixir = GREATEST(0, dark_elixir - ?),
                trophies = GREATEST(0, trophies + ?)
            WHERE id=?");
        if (!$stmt) throw new RuntimeException('defender_reward_failed');
        $stmt->bind_param('iiiii', $loot['gold'], $loot['elixir'], $loot['dark_elixir'], $negTrophy, $defenderId);
        $stmt->execute();
        $stmt->close();

        $resultJson = json_encode([
            'stars' => $stars,
            'destructionPercent' => $destruction,
            'loot' => $loot,
            'requested' => $requestedLoot,
            'summary' => $result,
            'trophyDelta' => $trophyDelta,
        ], JSON_UNESCAPED_UNICODE);
        $stmt = $mysqli->prepare("UPDATE raid_battles SET status='resolved', result_json=? WHERE id=? AND attacker_id=?");
        if (!$stmt) throw new RuntimeException('raid_finish_failed');
        $stmt->bind_param('sii', $resultJson, $raidId, $attackerId);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }

    return [
        'raidId' => $raidId,
        'stars' => $stars,
        'destructionPercent' => $destruction,
        'loot' => $loot,
        'bonus' => $bonus,
        'trophyDelta' => $trophyDelta,
        'target' => [
            'user_id' => (int)($target['user_id'] ?? 0),
            'login' => (string)($target['login'] ?? 'Противник'),
        ],
    ];
}

function raidLeagueBonus(int $stars, int $destruction): array {
    if ($stars >= 3 || $destruction >= 100) return ['gold' => 12000, 'elixir' => 12000, 'dark_elixir' => 240];
    if ($stars >= 2 || $destruction >= 50) return ['gold' => 6500, 'elixir' => 6500, 'dark_elixir' => 120];
    if ($stars >= 1 || $destruction >= 25) return ['gold' => 2500, 'elixir' => 2500, 'dark_elixir' => 40];
    return ['gold' => 0, 'elixir' => 0, 'dark_elixir' => 0];
}

function raidTrophyDelta(int $stars, int $destruction, int $attTrophies, int $defTrophies): int {
    $base = match ($stars) {
        3 => 34,
        2 => 22,
        1 => 10,
        default => ($destruction >= 40 ? 5 : -8),
    };
    $spread = $defTrophies - $attTrophies;
    if ($spread > 400) $base += 4;
    elseif ($spread > 150) $base += 2;
    elseif ($spread < -400) $base -= 4;
    elseif ($spread < -150) $base -= 2;
    return max(-16, min(40, $base));
}

function raidBuildingIcon(string $buildingId, int $level, string $kind = 'building'): string {
    $level = max(1, min(21, $level));
    $map = [
        'townhall' => ['/images/building/Town_Hall/Town_Hall%s.png'],
        'gold_storage' => ['/images/building/Gold_Storage/Gold_Storage%s.png'],
        'elixir_storage' => ['/images/building/Elixir_Storage/Elixir_Storage%s.png'],
        'dark_storage' => ['/images/building/Dark_Elixir/Dark_Elixir_Storage%s.png'],
        'cannon' => ['/images/building/Cannon/Cannon%s.png', '/images/building/Cannon/Cannon%sB.png'],
        'archer_tower' => ['/images/building/Archer_Tower/Archer_Tower%s.png'],
        'wizard_tower' => ['/images/building/Wizard_Tower/Wizard_Tower%s.png'],
        'mortar' => ['/images/building/Mortar/Mortar%s.png'],
        'air_defense' => ['/images/building/Air_Defense/Air_Defense%s.png'],
        'air_sweeper' => ['/images/building/Air_Sweeper/Air_Sweeper%s.png'],
        'hidden_tesla' => ['/images/building/Hidden_Tesla/Hidden_Tesla%s.png'],
        'bomb_tower' => ['/images/building/Bomb_Tower/Bomb_Tower%s.png'],
        'x_bow' => ['/images/building/X-Bow_Ground/X-Bow_Ground%s.png'],
        'inferno_tower' => ['/images/building/Inferno_Tower_Single/Inferno_Tower_Single%s.png'],
        'eagle_artillery' => ['/images/building/Eagle_Artillery/Eagle_Artillery%s.png'],
        'scattershot' => ['/images/building/Scattershot/Scattershot%s.png'],
        'spell_tower' => ['/images/building/Spell_Tower_Rage/Spell_Tower_Rage%s.png'],
        'monolith' => ['/images/building/Monolith/Monolith%s.png'],
        'builder_hut' => ['/images/building/Builders_Hut/Builders_Hut%s.png', '/images/building/Builders_Hut/Builders_Hut.png'],
        'wall' => ['/images/building/Wall/Wall%s.png'],
        'bomb' => ['/images/building/Bomb/Bomb%s.png'],
        'air_bomb' => ['/images/building/Air_Bomb/Air_Bomb%s.png'],
    ];
    foreach (($map[$buildingId] ?? []) as $pattern) return sprintf($pattern, $level);
    if (in_array($buildingId, ['gold_mine','elixir_collector','dark_elixir_drill'], true)) return '/images/building/production.png';
    if ($kind === 'defense') return '/images/building/Cannon/Cannon1.png';
    if ($kind === 'resource') return '/images/building/storage.png';
    if ($kind === 'wall') return '/images/building/Wall/Wall1.png';
    return '/images/building/storage.png';
}

function raidEntityIcon(string $entityId, string $kind): string {
    $map = [
        'barbarian' => '/images/warriors/Barbarian/Avatar_Barbarian.png',
        'archer' => '/images/warriors/Archer/Avatar_Archer.png',
        'giant' => '/images/warriors/Giant/Avatar_Giant.png',
        'goblin' => '/images/warriors/Goblin/Avatar_Goblin.png',
        'wall_breaker' => '/images/warriors/Wall_Breaker/Avatar_Wall_Breaker.png',
        'balloon' => '/images/warriors/Balloon/Avatar_Balloon.png',
        'wizard' => '/images/warriors/Wizard/Avatar_Wizard.png',
        'healer' => '/images/warriors/Healer/Avatar_Healer.png',
        'dragon' => '/images/warriors/Dragon/Avatar_Dragon.png',
        'pekka' => '/images/warriors/P.E.K.K.A/Avatar_P.E.K.K.A.png',
        'baby_dragon' => '/images/warriors/Baby_Dragon/Avatar_Baby_Dragon.png',
        'miner' => '/images/warriors/Miner/Avatar_Miner.png',
        'electro_dragon' => '/images/warriors/Electro_Dragon/Avatar_Electro_Dragon.png',
        'yeti' => '/images/warriors/Yeti/Avatar_Yeti.png',
        'dragon_rider' => '/images/warriors/Dragon_Rider/Avatar_Dragon_Rider.png',
        'electro_titan' => '/images/warriors/Electro_Titan/Avatar_Electro_Titan.png',
        'root_rider' => '/images/warriors/Root_Rider/Avatar_Root_Rider.png',
        'thrower' => '/images/warriors/Thrower/Avatar_Thrower.png',
        'meteor_golem' => '/images/warriors/Meteor_Golem/Avatar_Meteor_Golem.png',
        'minion' => '/images/warriors/Minion/Avatar_Minion.png',
        'hog_rider' => '/images/warriors/Hog_Rider/Avatar_Hog_Rider.png',
        'valkyrie' => '/images/warriors/Valkyrie/Avatar_Valkyrie.png',
        'golem' => '/images/warriors/Golem/Avatar_Golem.png',
        'witch' => '/images/warriors/Witch/Avatar_Witch.png',
        'lava_hound' => '/images/warriors/Lava_Hound/Avatar_Lava_Hound.png',
        'barbarian_king' => '/images/heroes/Avatar_Hero_Barbarian_King.png',
        'archer_queen' => '/images/heroes/Avatar_Hero_Archer_Queen.png',
        'grand_warden' => '/images/heroes/Avatar_Hero_Grand_Warden.png',
        'minion_prince' => '/images/heroes/Avatar_Hero_Minion_Prince.png',
        'royal_champion' => '/images/heroes/Avatar_Hero_Royal_Champion.png',
        'lightning_spell' => '/images/spells/Lightning_Spell_info.png',
        'healing_spell' => '/images/spells/Healing_Spell_info.png',
        'rage_spell' => '/images/spells/Rage_Spell_info.png',
        'freeze_spell' => '/images/spells/Freeze_Spell_info.png',
        'poison_spell' => '/images/spells/Poison_Spell_info.png',
        'jump_spell' => '/images/spells/Jump_Spell_info.png',
        'earthquake_spell' => '/images/spells/Earthquake_Spell_info.png',
        'haste_spell' => '/images/spells/Haste_Spell_info.png',
        'clone_spell' => '/images/spells/Clone_Spell_info.png',
        'skeleton_spell' => '/images/spells/Skeleton_Spell_info.png',
        'bat_spell' => '/images/spells/Bat_Spell_info.png',
        'overgrowth_spell' => '/images/spells/Overgrowth_Spell_info.png',
        'ice_block_spell' => '/images/spells/Ice_Block_Spell_info.png',
        'invisibility_spell' => '/images/spells/Invisibility_Spell_info.png',
        'recall_spell' => '/images/spells/Recall_Spell_info.png',
    ];
    return $map[$entityId] ?? ($kind === 'spell' ? '/images/icons/elixir.png' : '/images/icons/sword.png');
}
