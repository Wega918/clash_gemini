<?php

/**
 * Проверяет, есть ли колонка в таблице (кешируется на время запроса).
 * Нужна для обратной совместимости между патчами БД (например, source в очереди).
 */
function army_db_has_column(mysqli $mysqli, string $table, string $column): bool {
    static $cache = [];
    $db = '';
    $key = $table . '.' . $column;
    if (isset($cache[$key])) return (bool)$cache[$key];

    $res = $mysqli->query("SELECT DATABASE() AS db");
    if ($res) {
        $row = $res->fetch_assoc();
        $db = (string)($row['db'] ?? '');
        $res->free();
    }
    if ($db === '') {
        $cache[$key] = false;
        return false;
    }

    $stmt = $mysqli->prepare("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    if (!$stmt) {
        $cache[$key] = false;
        return false;
    }
    $stmt->bind_param('sss', $db, $table, $column);
    $stmt->execute();
    $r = $stmt->get_result();
    $ok = ($r && $r->fetch_row());
    $stmt->close();
    $cache[$key] = (bool)$ok;
    return (bool)$ok;
}

// system/army_helpers.php
// Вспомогательные функции для армии (очередь тренировки + исследования).

if (!defined('ARMY_HELPERS_LOADED')) define('ARMY_HELPERS_LOADED', true);

/** Возвращает текущий уровень здания у игрока (текущий, без учета target_level). */
function army_get_building_level(mysqli $mysqli, int $userId, string $buildingId): int {
    $lvl = 0;
    $stmt = $mysqli->prepare("SELECT MAX(level) AS lvl FROM player_buildings WHERE user_id=? AND building_id=?");
    if (!$stmt) return 0;
    $stmt->bind_param('is', $userId, $buildingId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $row = $res->fetch_assoc();
        $lvl = (int)($row['lvl'] ?? 0);
    }
    $stmt->close();
    return max(0, $lvl);
}

/** Возвращает список построек игрока заданного типа. */
function army_get_buildings(mysqli $mysqli, int $userId, string $buildingId): array {
    $out = [];
    // В твоей БД поле называется target_level (см. дамп player_buildings).
    $stmt = $mysqli->prepare("SELECT id, building_id, level, status, target_level, finish_time FROM player_buildings WHERE user_id=? AND building_id=? ORDER BY id ASC");
    if (!$stmt) return $out;
    $stmt->bind_param('is', $userId, $buildingId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) $out[] = $row;
    $stmt->close();
    return $out;
}

/**
 * Возвращает "виртуальное" количество лагерей по уровню Ратуши (CoC-like).
 * Мы используем ОДНО здание Army Camp, но считаем вместимость так, будто лагерей несколько:
 *   TH1-2: 1 лагерь
 *   TH3-4: 2 лагеря
 *   TH5-6: 3 лагеря
 *   TH7+:  4 лагеря
 */
function army_virtual_camps_for_th(?int $townhallLvl): int {
    $th = max(1, (int)($townhallLvl ?? 0));
    if ($th <= 2) return 1;
    if ($th <= 4) return 2;
    if ($th <= 6) return 3;
    return 4;
}

function army_get_user_townhall_level(mysqli $mysqli, int $userId): int {
    $lvl = army_get_building_level($mysqli, $userId, 'townhall');
    return ($lvl > 0) ? (int)$lvl : 1;
}

/** Считает общий лимит армии по Army Camp (одно здание, виртуальные лагеря). */
function army_get_camp_capacity_total(mysqli $mysqli, int $userId, array $game_data, ?int $townhallLvl = null): int {
    if ($townhallLvl === null) $townhallLvl = army_get_user_townhall_level($mysqli, $userId);
    $lvl = army_get_building_level($mysqli, $userId, 'army_camp');
    if ($lvl <= 0) return 0;

    $perCamp = (int)($game_data['army_camp']['levels'][$lvl]['capacity_army'] ?? 0);
    $mult = army_virtual_camps_for_th($townhallLvl);
    return max(0, $perCamp) * max(1, $mult);
}

/** Возвращает текущий состав армии игрока unit_id=>amount */
function army_get_player_army(mysqli $mysqli, int $userId): array {
    $out = [];
    $stmt = $mysqli->prepare("SELECT unit_id, amount FROM player_army WHERE user_id=?");
    if (!$stmt) return $out;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $uid = (string)($row['unit_id'] ?? '');
        if ($uid === '') continue;
        $out[$uid] = (int)($row['amount'] ?? 0);
    }
    $stmt->close();
    return $out;
}

// -------------------- Spells (instant composition) --------------------

/** Возвращает текущий состав заклинаний игрока spell_id=>amount */
function army_get_player_spells(mysqli $mysqli, int $userId): array {
    $out = [];
    // Таблица может появиться миграцией (Stage 3). Если ее нет — просто вернем пусто.
    $stmt = @$mysqli->prepare("SELECT spell_id, amount FROM player_spells WHERE user_id=?");
    if (!$stmt) return $out;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $sid = (string)($row['spell_id'] ?? '');
        if ($sid === '') continue;
        $out[$sid] = (int)($row['amount'] ?? 0);
    }
    $stmt->close();
    return $out;
}

/** Считает занятое место в заклинаниях (по player_spells). */
function army_get_spells_used(mysqli $mysqli, int $userId, array $game_data): int {
    $spells = army_get_player_spells($mysqli, $userId);
    $used = 0;
    foreach ($spells as $spellId => $amt) {
        if ($amt <= 0) continue;
        $space = (int)($game_data[$spellId]['housing_space'] ?? 0);
        if ($space <= 0) $space = 1;
        $used += $space * $amt;
    }
    return max(0, $used);
}

/** Лимит заклинаний (CoC-подобно). Если в проекте позже появится Spell Factory — можно будет переключить на уровни здания. */
function army_get_spell_capacity_total(mysqli $mysqli, int $userId, array $game_data, ?int $townhallLvl = null): int {
    if ($townhallLvl === null) $townhallLvl = army_get_user_townhall_level($mysqli, $userId);
    // В CoC вместимость заклинаний задаётся уровнем Spell Factory.
    // Dark Spell Factory НЕ добавляет отдельную вместимость — это только разблок тёмных заклинаний.

    $sfLevel = army_get_building_level($mysqli, $userId, 'spell_factory');
    if ($sfLevel > 0) {
        $lvlDef = $game_data['spell_factory']['levels'][$sfLevel] ?? null;
        if (is_array($lvlDef) && isset($lvlDef['capacity_spells'])) {
            return max(0, (int)$lvlDef['capacity_spells']);
        }

        // Fallback mapping (на случай, если capacity_spells ещё не добавили в данные)
        if ($sfLevel == 1) return 2;
        if ($sfLevel == 2) return 4;
        if ($sfLevel == 3) return 6;
        if ($sfLevel == 4) return 7;
        if ($sfLevel == 5) return 8;
        if ($sfLevel == 6) return 9;
        if ($sfLevel == 7) return 10;
        if ($sfLevel >= 8) return 11;
    }

    // Если фабрика не построена — 0 (как в CoC до TH5)
    return 0;
}


/** Время варки заклинания (сек). Берем из game_data[spell]['training_time'] (как у войск). */
function army_get_spell_brew_time(array $game_data, string $spellId, int $level = 0): int {
    if (!isset($game_data[$spellId]) || !is_array($game_data[$spellId])) return 0;
    $def = $game_data[$spellId];

    // 1) per-level brew time
    if ($level > 0 && !empty($def['levels'][$level]) && is_array($def['levels'][$level])) {
        if (isset($def['levels'][$level]['brew_time'])) {
            $t = (int)$def['levels'][$level]['brew_time'];
            return max(0, $t);
        }
    }

    // 2) global brew time (legacy)
    if (isset($def['brew_time'])) return max(0, (int)$def['brew_time']);

    // 3) fallback to training_time if present
    $t = (int)($def['training_time'] ?? 0);
    return max(0, $t);
}

/** Стоимость варки заклинания (ресурс/стоимость). Берём из levels[1][cost/res_type], если есть, иначе 0. */
function army_get_spell_brew_cost(array $game_data, string $spellId, int $level = 0): array {
    // Важно:
    // - "cost" в levels[] у заклинаний — это стоимость ИССЛЕДОВАНИЯ в Лаборатории,
    //   а не цена варки (brew). Поэтому НЕ используем levels[1]['cost'] как фолбэк.
    // - Если brew-цены в базе не заданы явно — возвращаем 0.
    $out = ['res_key' => 'elixir', 'cost' => 0];
    if (!isset($game_data[$spellId]) || !is_array($game_data[$spellId])) return $out;
    $def = $game_data[$spellId];

    // 1) Пер-уровень (предпочтительно)
    $cost = null;
    $resType = null;
    if ($level > 0 && !empty($def['levels'][$level]) && is_array($def['levels'][$level])) {
        if (isset($def['levels'][$level]['brew_cost'])) $cost = (int)$def['levels'][$level]['brew_cost'];
        if (isset($def['levels'][$level]['brew_res_type'])) $resType = (string)$def['levels'][$level]['brew_res_type'];
    }

    // 2) Глобальные поля
    if ($cost === null && isset($def['brew_cost'])) $cost = (int)$def['brew_cost'];
    if ($resType === null && isset($def['brew_res_type'])) $resType = (string)$def['brew_res_type'];
    if ($cost === null && isset($def['training_cost'])) $cost = (int)$def['training_cost'];
    if ($resType === null && isset($def['training_res_type'])) $resType = (string)$def['training_res_type'];

    // 2) Последний фолбэк: по типу (только ресурс), стоимость = 0
    if ($resType === null) {
        $t = (string)($def['type'] ?? '');
        $resType = ($t === TYPE_DARK_SPELL) ? RES_DARK : RES_ELIXIR;
    }
    if ($cost === null) $cost = 0;
    if ($cost < 0) $cost = 0;

    // RES_* -> ключ users.*
    $resKey = 'elixir';
    if (defined('RES_GOLD') && $resType === RES_GOLD) $resKey = 'gold';
    if (defined('RES_ELIXIR') && $resType === RES_ELIXIR) $resKey = 'elixir';
    if (defined('RES_DARK') && $resType === RES_DARK) $resKey = 'dark_elixir';
    if (defined('RES_GEMS') && $resType === RES_GEMS) $resKey = 'gems';

    $out['res_key'] = $resKey;
    $out['cost'] = (int)$cost;
    return $out;
}

// -------------------- Заклинания: очередь варки + синхронизация --------------------

/** Получить очередь варки заклинаний (training|ready). */
function army_spell_queue_get(mysqli $mysqli, int $userId): array {
    $out = [];
    if ($userId <= 0) return $out;

    $stmt = @$mysqli->prepare("SELECT id, spell_id, spell_level, qty, start_time, finish_time, status, source FROM player_spell_queue WHERE user_id=? AND status IN ('training','ready') ORDER BY start_time ASC, id ASC");
    if (!$stmt) return $out;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $out[] = [
                'id' => (int)$row['id'],
                'spell_id' => (string)$row['spell_id'],
                'spell_level' => (int)$row['spell_level'],
                'qty' => (int)$row['qty'],
                'start_time' => (int)$row['start_time'],
                'finish_time' => (int)$row['finish_time'],
                'status' => (string)$row['status'],
                'source' => (string)$row['source'],
            ];
        }
    }
    $stmt->close();
    return $out;
}

/** Занятое место в очереди варки заклинаний (training|ready). */
function army_spell_queue_get_space_used(mysqli $mysqli, int $userId, array $game_data): int {
    $queue = army_spell_queue_get($mysqli, $userId);
    if (empty($queue)) return 0;
    $used = 0;
    foreach ($queue as $r) {
        $spellId = (string)($r['spell_id'] ?? '');
        $qty = max(0, (int)($r['qty'] ?? 0));
        if ($qty <= 0 || $spellId === '') continue;
        $space = (int)($game_data[$spellId]['housing_space'] ?? 1);
        if ($space <= 0) $space = 1;
        $used += $space * $qty;
    }
    return max(0, $used);
}

/** Добавить в очередь варки заклинаний (поштучно, как у войск). */
function army_spell_queue_add(mysqli $mysqli, int $userId, string $spellId, int $spellLevel, int $qty, int $brewSeconds, string $source = 'spell_factory'): void {
    if ($userId <= 0) return;
    $spellId = trim($spellId);
    if ($spellId === '') return;

    $qty = max(1, min(200, (int)$qty));
    $brewSeconds = max(0, (int)$brewSeconds);
    $spellLevel = max(1, (int)$spellLevel);
    $now = time();

    // последний finish_time (внутри своей очереди source)
    $lastFinish = $now;
    $stmt = @$mysqli->prepare("SELECT MAX(finish_time) AS mf FROM player_spell_queue WHERE user_id=? AND source=?");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $source);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            $row = $res->fetch_assoc();
            $mf = (int)($row['mf'] ?? 0);
            if ($mf > $lastFinish) $lastFinish = $mf;
        }
        $stmt->close();
    }

    $ins = @$mysqli->prepare("INSERT INTO player_spell_queue (user_id, spell_id, spell_level, qty, start_time, finish_time, status, source) VALUES (?,?,?,?,?,?,?,?)");
    if (!$ins) throw new RuntimeException('DB: insert spell queue failed', 500);

    for ($i = 0; $i < $qty; $i++) {
        $st = (int)$lastFinish;
        $en = (int)($st + $brewSeconds);
        $q = 1;
        $status = 'training';
        $ins->bind_param('issiiiss', $userId, $spellId, $spellLevel, $q, $st, $en, $status, $source);
        $ins->execute();
        $lastFinish = $en;
    }
    $ins->close();
}

/** Отменить одну запись очереди заклинаний */
function army_spell_queue_cancel(mysqli $mysqli, int $userId, int $queueId): void {
    if ($userId <= 0 || $queueId <= 0) return;
    $stmt = @$mysqli->prepare("DELETE FROM player_spell_queue WHERE user_id=? AND id=?");
    if (!$stmt) return;
    $stmt->bind_param('ii', $userId, $queueId);
    $stmt->execute();
    $stmt->close();
}

/** Пересчёт таймингов очереди варки (только status=training) */
function army_spell_queue_recalculate_timings(mysqli $mysqli, int $userId, array $game_data, string $source = ''): void {
    if ($userId <= 0) return;
    $now = time();

    $queue = army_spell_queue_get($mysqli, $userId);
    if (empty($queue)) return;

    // Берем только training и только нужный source (если задан)
    $training = [];
    foreach ($queue as $row) {
        if (($row['status'] ?? '') !== 'training') continue;
        if ($source !== '' && (string)($row['source'] ?? '') !== $source) continue;
        $training[] = $row;
    }
    if (empty($training)) return;

    // Найдем текущий тренируемый элемент (если есть)
    $currentIndex = -1;
    for ($i = 0; $i < count($training); $i++) {
        $st = (int)($training[$i]['start_time'] ?? 0);
        $ft = (int)($training[$i]['finish_time'] ?? 0);
        if ($st <= $now && $ft > $now) { $currentIndex = $i; break; }
    }

    $lastFinish = $now;
    $startFrom = 0;
    if ($currentIndex >= 0) {
        $lastFinish = (int)($training[$currentIndex]['finish_time'] ?? $now);
        $startFrom = $currentIndex + 1;
    } else {
        $lastFinish = $now;
        $startFrom = 0;
    }

    $upd = @$mysqli->prepare("UPDATE player_spell_queue SET start_time=?, finish_time=? WHERE id=? AND user_id=?");
    if (!$upd) return;

    $mysqli->begin_transaction();
    try {
        for ($i = $startFrom; $i < count($training); $i++) {
            $row = $training[$i];
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;

            $spellId = (string)($row['spell_id'] ?? '');
            $qty = max(1, (int)($row['qty'] ?? 1));

            // В очереди заклинаний время считается по brew_time (а не training_time).
            // Из-за неверного поля dur становился 0 и оставшаяся очередь мгновенно "готовилась"
            // после отмены (особенно заметно при постановке нескольких заклинаний и их резкой отмене).
            $spellLevel = (int)($row['spell_level'] ?? 1);
            if ($spellLevel < 1) $spellLevel = 1;
            $per = function_exists('army_get_spell_brew_time')
                ? (int)army_get_spell_brew_time($game_data, $spellId, $spellLevel)
                : (int)($game_data[$spellId]['brew_time'] ?? 0);
            $dur = max(0, $per) * $qty;

            $newStart = (int)$lastFinish;
            $newFinish = (int)($newStart + $dur);

            $oldStart = (int)($row['start_time'] ?? 0);
            $oldFinish = (int)($row['finish_time'] ?? 0);

            if ($newStart !== $oldStart || $newFinish !== $oldFinish) {
                $upd->bind_param('iiii', $newStart, $newFinish, $id, $userId);
                $upd->execute();
            }

            $lastFinish = $newFinish;
        }
        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
    }

    $upd->close();
}

/**
 * Синхронизация варки заклинаний:
 * 1) training, у которых finish_time <= now -> ready
 * 2) переносим ready в хранилище заклинаний (player_spells) пока есть место.
 */
function army_spell_training_sync(mysqli $mysqli, int $userId, array $game_data, int $spellCapTotal): void {
    if ($userId <= 0) return;
    $spellCapTotal = max(0, (int)$spellCapTotal);
    if ($spellCapTotal <= 0) return;

    $now = time();

    // 1) training -> ready
    $stmt = @$mysqli->prepare("UPDATE player_spell_queue SET status='ready' WHERE user_id=? AND status='training' AND finish_time<=?");
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $now);
        $stmt->execute();
        $stmt->close();
    }

    // 2) перенос ready -> player_spells
    $used = army_get_spells_used($mysqli, $userId, $game_data);
    $free = $spellCapTotal - $used;
    if ($free <= 0) return;

    $q = [];
    $stmt2 = @$mysqli->prepare("SELECT id, spell_id, qty FROM player_spell_queue WHERE user_id=? AND status='ready' ORDER BY start_time ASC, id ASC");
    if (!$stmt2) return;
    $stmt2->bind_param('i', $userId);
    $stmt2->execute();
    $res = $stmt2->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $q[] = ['id' => (int)$row['id'], 'spell_id' => (string)$row['spell_id'], 'qty' => (int)$row['qty']];
        }
    }
    $stmt2->close();
    if (empty($q)) return;

    $ins = @$mysqli->prepare("INSERT INTO player_spells (user_id, spell_id, amount) VALUES (?,?,?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)");
    $del = @$mysqli->prepare("DELETE FROM player_spell_queue WHERE user_id=? AND id=?");
    $updQty = @$mysqli->prepare("UPDATE player_spell_queue SET qty=? WHERE user_id=? AND id=?");
    if (!$ins || !$del || !$updQty) return;

    $mysqli->begin_transaction();
    try {
        foreach ($q as $row) {
            if ($free <= 0) break;
            $id = (int)$row['id'];
            $spellId = (string)$row['spell_id'];
            $qty = max(0, (int)$row['qty']);
            if ($id <= 0 || $spellId === '' || $qty <= 0) continue;

            $space = (int)($game_data[$spellId]['housing_space'] ?? 1);
            if ($space <= 0) $space = 1;
            $can = (int)floor($free / $space);
            if ($can <= 0) break;

            $take = min($qty, $can);
            if ($take <= 0) break;

            $ins->bind_param('isi', $userId, $spellId, $take);
            $ins->execute();

            if ($take >= $qty) {
                $del->bind_param('ii', $userId, $id);
                $del->execute();
            } else {
                $newQty = (int)($qty - $take);
                $updQty->bind_param('iii', $newQty, $userId, $id);
                $updQty->execute();
            }

            $free -= $take * $space;
        }
        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
    }

    $ins->close();
    $del->close();
    $updQty->close();
}

/** Считает занятое место в армии (по player_army). */
function army_get_army_used(mysqli $mysqli, int $userId, array $game_data): int {
    $army = army_get_player_army($mysqli, $userId);
    $used = 0;
    foreach ($army as $unitId => $amt) {
        $space = (int)($game_data[$unitId]['housing_space'] ?? 0);
        $used += max(0, $space) * max(0, (int)$amt);
    }
    return max(0, $used);
}

/**
 * Считает место, занятое в очереди тренировки (status=training|ready).
 * Нужно для запрета постановки в очередь сверх вместимости лагерей.
 */
function army_queue_get_space_used(mysqli $mysqli, int $userId, array $game_data): int {
    $used = 0;
    $stmt = $mysqli->prepare("SELECT unit_id, SUM(qty) AS s FROM player_training_queue WHERE user_id=? AND status IN ('training','ready') GROUP BY unit_id");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $uid = (string)($row['unit_id'] ?? '');
        if ($uid === '' || !isset($game_data[$uid])) continue;
        $amt = (int)($row['s'] ?? 0);
        if ($amt <= 0) continue;
        $space = (int)($game_data[$uid]['housing_space'] ?? 0);
        $used += max(0, $space) * $amt;
    }
    $stmt->close();
    return max(0, (int)$used);
}

/** Возвращает очередь тренировки */
function army_queue_get(mysqli $mysqli, int $userId): array {
    $out = [];
    if (army_db_has_column($mysqli, 'player_training_queue', 'source')) {
        $stmt = $mysqli->prepare("SELECT id, unit_id, unit_level, qty, start_time, finish_time, status, source FROM player_training_queue WHERE user_id=? ORDER BY start_time ASC, id ASC");
    } else {
        $stmt = $mysqli->prepare("SELECT id, unit_id, unit_level, qty, start_time, finish_time, status FROM player_training_queue WHERE user_id=? ORDER BY start_time ASC, id ASC");
    }
    if (!$stmt) return $out;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        if (!isset($row['source'])) $row['source'] = 'barracks';
        $out[] = $row;
    }
    $stmt->close();
    return $out;
}

/** Добавляет в очередь указанное количество юнитов (по 1 на строку) */
function army_queue_add_units(mysqli $mysqli, int $userId, string $unitId, int $unitLevel, int $qty, int $trainSeconds, string $source = 'barracks'): void {
    $qty = max(1, min(200, (int)$qty));
    $trainSeconds = max(0, (int)$trainSeconds);
    $unitLevel = max(1, (int)$unitLevel);
    $now = time();

    $hasSource = army_db_has_column($mysqli, 'player_training_queue', 'source');
    if (!$hasSource) $source = 'barracks';

    // последний finish_time (внутри своей очереди source)
    $lastFinish = $now;
    if ($hasSource) {
        $stmt = $mysqli->prepare("SELECT MAX(finish_time) AS mf FROM player_training_queue WHERE user_id=? AND source=?");
        if ($stmt) {
            $stmt->bind_param('is', $userId, $source);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $row = $res->fetch_assoc();
                $mf = (int)($row['mf'] ?? 0);
                if ($mf > $lastFinish) $lastFinish = $mf;
            }
            $stmt->close();
        }
    } else {
        $stmt = $mysqli->prepare("SELECT MAX(finish_time) AS mf FROM player_training_queue WHERE user_id=?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                $row = $res->fetch_assoc();
                $mf = (int)($row['mf'] ?? 0);
                if ($mf > $lastFinish) $lastFinish = $mf;
            }
            $stmt->close();
        }
    }

    if ($hasSource) {
        $ins = $mysqli->prepare("INSERT INTO player_training_queue (user_id, unit_id, unit_level, qty, start_time, finish_time, status, source) VALUES (?,?,?,?,?,?,?,?)");
    } else {
        $ins = $mysqli->prepare("INSERT INTO player_training_queue (user_id, unit_id, unit_level, qty, start_time, finish_time, status) VALUES (?,?,?,?,?,?,?)");
    }
    if (!$ins) throw new RuntimeException('DB: insert queue failed', 500);

    for ($i=0; $i<$qty; $i++) {
        $st = $lastFinish;
        $en = $st + $trainSeconds;
        $q = 1;
        $status = 'training';
        if ($hasSource) {
            $ins->bind_param('issiiiss', $userId, $unitId, $unitLevel, $q, $st, $en, $status, $source);
        } else {
            $ins->bind_param('issiiis', $userId, $unitId, $unitLevel, $q, $st, $en, $status);
        }
        $ins->execute();
        $lastFinish = $en;
    }
    $ins->close();
}

/** Отменяет одну запись очереди */
function army_queue_cancel(mysqli $mysqli, int $userId, int $queueId): void {
    $stmt = $mysqli->prepare("DELETE FROM player_training_queue WHERE user_id=? AND id=?");
    if (!$stmt) return;
    $stmt->bind_param('ii', $userId, $queueId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Синхронизация очереди тренировки:
 * 1) все training с finish_time <= now -> ready
 * 2) переносим ready в лагеря, пока есть место
 */
function army_training_sync(mysqli $mysqli, int $userId, array $game_data): void {
    $now = time();

    // 1) training -> ready
    $stmt = $mysqli->prepare("UPDATE player_training_queue SET status='ready' WHERE user_id=? AND status='training' AND finish_time<=?");
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $now);
        $stmt->execute();
        $stmt->close();
    }

    // 2) перенос ready в лагеря по месту
    $cap = army_get_camp_capacity_total($mysqli, $userId, $game_data);
    $used = army_get_army_used($mysqli, $userId, $game_data);
    $free = max(0, $cap - $used);
    if ($free <= 0) return;

    $q = [];
    $stmt2 = $mysqli->prepare("SELECT id, unit_id FROM player_training_queue WHERE user_id=? AND status='ready' ORDER BY finish_time ASC, id ASC");
    if ($stmt2) {
        $stmt2->bind_param('i', $userId);
        $stmt2->execute();
        $res = $stmt2->get_result();
        while ($res && ($row = $res->fetch_assoc())) $q[] = $row;
        $stmt2->close();
    }
    if (!$q) return;

    $updArmy = $mysqli->prepare("INSERT INTO player_army (user_id, unit_id, amount) VALUES (?,?,1) ON DUPLICATE KEY UPDATE amount=amount+1");
    $delQ = $mysqli->prepare("DELETE FROM player_training_queue WHERE user_id=? AND id=?");
    if (!$updArmy || !$delQ) return;

    foreach ($q as $row) {
        $qid = (int)($row['id'] ?? 0);
        $unitId = (string)($row['unit_id'] ?? '');
        if ($qid <= 0 || $unitId === '') continue;
        $space = (int)($game_data[$unitId]['housing_space'] ?? 0);
        if ($space <= 0) $space = 1;
        if ($free < $space) break;

        $updArmy->bind_param('is', $userId, $unitId);
        $updArmy->execute();

        $delQ->bind_param('ii', $userId, $qid);
        $delQ->execute();

        $free -= $space;
        if ($free <= 0) break;
    }

    $updArmy->close();
    $delQ->close();
}

// -------------------- Исследования (лаборатория) --------------------

/** Возвращает исследования (tech_id => row) */
function army_research_get(mysqli $mysqli, int $userId): array {
    $out = [];
    $stmt = $mysqli->prepare("SELECT tech_id, level, status, finish_time FROM player_research WHERE user_id=?");
    if (!$stmt) return $out;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $tid = (string)($row['tech_id'] ?? '');
        if ($tid === '') continue;
        $out[$tid] = $row;
    }
    $stmt->close();
    return $out;
}

/** Возвращает текущую запись исследования, если идет */
function army_research_get_active(mysqli $mysqli, int $userId): ?array {
    $stmt = $mysqli->prepare("SELECT id, tech_id, level, status, finish_time FROM player_research WHERE user_id=? AND status='researching' LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res) ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** Завершает исследования по времени */
function army_research_sync(mysqli $mysqli, int $userId): void {
    $now = time();
    $stmt = $mysqli->prepare("SELECT id, level FROM player_research WHERE user_id=? AND status='researching' AND finish_time<=? LIMIT 1");
    if (!$stmt) return;
    $stmt->bind_param('ii', $userId, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = ($res) ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return;

    $id = (int)($row['id'] ?? 0);
    $lvl = (int)($row['level'] ?? 1);
    $newLvl = max(1, $lvl + 1);

    $upd = $mysqli->prepare("UPDATE player_research SET level=?, status='active', finish_time=0 WHERE id=? AND user_id=?");
    if (!$upd) return;
    $upd->bind_param('iii', $newLvl, $id, $userId);
    $upd->execute();
    $upd->close();
}

/** Запускает исследование tech_id на duration секунд. Уровень увеличится после завершения. */
function army_research_start(mysqli $mysqli, array $userData, string $techId, string $resKey, int $cost, int $duration, int $currentLevel = 1): array {
    $userId = (int)($userData['id'] ?? 0);
    if ($userId <= 0) throw new RuntimeException('Не указан пользователь', 400);
    if ($techId === '') throw new RuntimeException('Не указан tech_id', 400);
    if ($duration < 0) $duration = 0;
    $cost = max(0, (int)$cost);
    $currentLevel = max(1, (int)$currentLevel);

    // Только одно исследование одновременно
    $active = army_research_get_active($mysqli, $userId);
    if ($active) {
        throw new GameActionException('Исследование уже идет.', 400, ['type' => 'busy']);
    }

    // Проверка ресурсов
    $have = (int)($userData[$resKey] ?? 0);
    if ($cost > 0 && $have < $cost) {
        if (function_exists('throwNotEnoughResources')) {
            throwNotEnoughResources($resKey, $cost, $have, 'исследование');
        }
        throw new RuntimeException('Не хватает ресурсов', 400);
    }

    $now = time();
    $finish = $now + $duration;

    // upsert строки исследования
    $mysqli->begin_transaction();
    try {
        if ($cost > 0) {
            $updRes = $mysqli->prepare("UPDATE users SET {$resKey}={$resKey}-? WHERE id=? AND {$resKey}>=?");
            if (!$updRes) throw new RuntimeException('DB: update resources failed', 500);
            $updRes->bind_param('iii', $cost, $userId, $cost);
            $updRes->execute();
            if ($updRes->affected_rows <= 0) {
                $updRes->close();
                if (function_exists('throwNotEnoughResources')) {
                    throwNotEnoughResources($resKey, $cost, $have, 'исследование');
                }
                throw new RuntimeException('Не хватает ресурсов', 400);
            }
            $updRes->close();
        }

        // если записи нет — создадим с level=1
        $ins = $mysqli->prepare("INSERT INTO player_research (user_id, tech_id, level, status, finish_time) VALUES (?,?,?,'researching',?) ON DUPLICATE KEY UPDATE level=VALUES(level), status='researching', finish_time=?");
        if (!$ins) throw new RuntimeException('DB: research upsert failed', 500);
        $ins->bind_param('isiii', $userId, $techId, $currentLevel, $finish, $finish);
        $ins->execute();
        $ins->close();

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }

    // Вернем обновленные ресурсы
    if (function_exists('getUserData')) {
        $userData = getUserData($mysqli, $userId);
    }
    return $userData;
}



/**
 * Завершает текущее исследование мгновенно за гемы.
 * Возвращает: ['cost_gems'=>int,'user'=>array]
 */
function army_research_finish_now(mysqli $mysqli, int $userId): array {
    $active = army_research_get_active($mysqli, $userId);
    if (!$active) {
        throw new GameActionException('Нет активного исследования.', 400, ['type' => 'nothing']);
    }

    $now = time();
    $finish = (int)($active['finish_time'] ?? 0);
    $timeLeft = max(0, $finish - $now);
    $cost = function_exists('army_gem_cost_for_seconds') ? army_gem_cost_for_seconds($timeLeft) : 0;

    $mysqli->begin_transaction();
    try {
        if ($cost > 0) {
            $upd = $mysqli->prepare("UPDATE users SET gems=gems-? WHERE id=? AND gems>=?");
            if (!$upd) throw new RuntimeException('DB: update gems failed', 500);
            $upd->bind_param('iii', $cost, $userId, $cost);
            $upd->execute();
            if ($upd->affected_rows <= 0) {
                $upd->close();
                throw new GameActionException('Не хватает гемов.', 400, ['type' => 'not_enough', 'res' => 'gems']);
            }
            $upd->close();
        }

        // Mark finish now, then sync will bump level and set status active
        $stmt = $mysqli->prepare("UPDATE player_research SET finish_time=? WHERE user_id=? AND status='researching'");
        if (!$stmt) throw new RuntimeException('DB: update research failed', 500);
        $stmt->bind_param('ii', $now, $userId);
        $stmt->execute();
        $stmt->close();

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }

    // finalize immediately
    army_research_sync($mysqli, $userId);

    $user = function_exists('getUserData') ? getUserData($mysqli, $userId) : null;
    return [
        'cost_gems' => (int)$cost,
        'user' => $user ?: ['id' => $userId],
    ];
}
// -------------------- Совместимость (Этап 1): недостающие хелперы --------------------

/**
 * Возвращает уровни исследований в удобном виде: tech_id => ['level'=>int,'status'=>string,'finish_time'=>int]
 * Нужно для barracks.php (отображение ур. войск).
 */
function army_research_get_levels(mysqli $mysqli, int $userId): array {
    $rows = army_research_get($mysqli, $userId);
    $out = [];
    foreach ($rows as $techId => $row) {
        $out[$techId] = [
            'level' => max(1, (int)($row['level'] ?? 1)),
            'status' => (string)($row['status'] ?? 'active'),
            'finish_time' => (int)($row['finish_time'] ?? 0),
        ];
    }
    return $out;
}

/**
 * Создаёт записи исследований (ур.1) для списка tech_id, если их нет.
 * На первом этапе это нужно, чтобы войска отображались как ур.1 и были готовы к улучшениям.
 *
 * Важно: если таблицы player_research нет (этапы БД ещё не применены) — тихо выходим,
 * чтобы не ломать уже рабочие части игры.
 */
function army_research_ensure_defaults(mysqli $mysqli, int $userId, array $techIds): void {
    if ($userId <= 0 || empty($techIds)) return;

    // Быстрый sanity: проверим наличие таблицы через пробную подготовку.
    $ins = $mysqli->prepare("INSERT INTO player_research (user_id, tech_id, level, status, finish_time) VALUES (?,?,1,'active',0) ON DUPLICATE KEY UPDATE tech_id=tech_id");
    if (!$ins) {
        // Таблицы/колонок может ещё не быть — не падаем.
        return;
    }

    foreach ($techIds as $tid) {
        $tid = (string)$tid;
        if ($tid === '') continue;
        $ins->bind_param('is', $userId, $tid);
        $ins->execute();
    }
    $ins->close();
}


// -------------------- Этап 4: стройка/апгрейд казарм и уплотнение очереди --------------------

/**
 * Возвращает первую (основную) постройку заданного типа у игрока.
 * Важно: level в проекте обычно уже является "целевым" уровнем во время апгрейда,
 * а актуальность статуса/finish_time подтягивается через finalizeCompletedBuildTimers().
 */
function army_get_primary_building_row(mysqli $mysqli, int $userId, string $buildingId): ?array {
    if ($userId <= 0 || $buildingId === '') return null;

    // Чтобы не зависать на "upgrading 0с"
    if (function_exists('finalizeCompletedBuildTimers')) {
        finalizeCompletedBuildTimers($mysqli, $userId);
    }

    $stmt = $mysqli->prepare("SELECT id, building_id, level, status, target_level, finish_time FROM player_buildings WHERE user_id=? AND building_id=? ORDER BY id ASC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $buildingId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/**
 * Совместимость: раньше в API использовалось имя army_get_building_row.
 * Оставляем как алиас к основной функции.
 */
function army_get_building_row(mysqli $mysqli, int $userId, string $buildingId): ?array {
    return army_get_primary_building_row($mysqli, $userId, $buildingId);
}

/**
 * Требует, чтобы здание было построено и было доступно для работы (status=active).
 * Если здание строится/улучшается — кидаем понятную ошибку.
 */
function army_require_building_ready(mysqli $mysqli, int $userId, string $buildingId, string $prettyName): array {
    $b = army_get_primary_building_row($mysqli, $userId, $buildingId);
    if (!$b) {
        throw new GameActionException($prettyName . ' не построены.', 400, ['type' => 'not_built']);
    }

    $st = (string)($b['status'] ?? 'active');
    $finish = (int)($b['finish_time'] ?? 0);
    $now = time();

    // В Clash of Clans производственные здания (Казармы/Фабрики/Мастерская) продолжают работать во время улучшения.
    // Поэтому запрещаем только если здание ЕЩЁ СТРОИТСЯ (не построено). При улучшении — разрешаем.
    if ($st !== 'active' && $finish > $now) {
        if ($st === 'upgrading') {
            // allow training/brewing while upgrading
        } else {
            throw new GameActionException($prettyName . ' сейчас заняты: идет стройка/улучшение.', 400, [
                'type' => 'busy_building',
                'status' => $st,
                'finish_time' => $finish,
                'time_left' => max(0, $finish - $now),
            ]);
        }
    }

    // Если статус не active, но время уже вышло — добьем finalize и перечитаем
    if ($st !== 'active' && $finish > 0 && $finish <= $now && function_exists('finalizeCompletedBuildTimers')) {
        finalizeCompletedBuildTimers($mysqli, $userId);
        $b2 = army_get_primary_building_row($mysqli, $userId, $buildingId);
        if ($b2) $b = $b2;
    }

    return $b;
}

/**
 * Пересчитывает start_time/finish_time для будущих строк очереди (status=training),
 * чтобы после отмены или массовых добавлений очередь "уплотнялась".
 *
 * Важно: текущая строка, которая уже тренируется (start<=now<finish), НЕ перезапускается.
 */
function army_queue_recalculate_timings(mysqli $mysqli, int $userId, array $game_data, string $source = ''): void {
    if ($userId <= 0) return;

    $now = time();
    $hasSource = army_db_has_column($mysqli, 'player_training_queue', 'source');
    if (!$hasSource) $source = '';

    $queue = army_queue_get($mysqli, $userId);
    if (empty($queue)) return;

    // Если есть колонка source и source не задан — пересчитаем каждую очередь отдельно
    if ($hasSource && $source === '') {
        $sources = [];
        foreach ($queue as $r) {
            if (($r['status'] ?? '') !== 'training') continue;
            $s = (string)($r['source'] ?? 'barracks');
            if ($s === '') $s = 'barracks';
            $sources[$s] = true;
        }
        foreach ($sources as $s => $_) {
            army_queue_recalculate_timings($mysqli, $userId, $game_data, $s);
        }
        return;
    }

    // Берем только training (ready не трогаем) и только нужный source
    $training = [];
    foreach ($queue as $row) {
        if (($row['status'] ?? '') !== 'training') continue;
        if ($hasSource && $source !== '' && (string)($row['source'] ?? '') !== $source) continue;
        $training[] = $row;
    }
    if (empty($training)) return;

    // Найдем текущий тренируемый элемент (если есть)
    $currentIndex = -1;
    for ($i = 0; $i < count($training); $i++) {
        $st = (int)($training[$i]['start_time'] ?? 0);
        $ft = (int)($training[$i]['finish_time'] ?? 0);
        if ($st <= $now && $ft > $now) { $currentIndex = $i; break; }
    }

    $lastFinish = $now;
    $startFrom = 0;

    if ($currentIndex >= 0) {
        // Текущий элемент не трогаем — от него и пляшем
        $lastFinish = (int)($training[$currentIndex]['finish_time'] ?? $now);
        $startFrom = $currentIndex + 1;
    } else {
        // Если очередь еще не стартовала, либо есть "дыра" — начнем от now
        $lastFinish = $now;
        $startFrom = 0;
    }

    // Апдейты только если реально меняются значения — чтобы не шуметь в БД
    $upd = $mysqli->prepare("UPDATE player_training_queue SET start_time=?, finish_time=? WHERE id=? AND user_id=?");
    if (!$upd) return;

    $mysqli->begin_transaction();
    try {
        for ($i = $startFrom; $i < count($training); $i++) {
            $row = $training[$i];
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0) continue;

            $unitId = (string)($row['unit_id'] ?? '');
            $qty = max(1, (int)($row['qty'] ?? 1));
            $per = (int)($game_data[$unitId]['training_time'] ?? 0);
            $dur = max(0, $per) * $qty;

            $newStart = (int)$lastFinish;
            $newFinish = (int)($newStart + $dur);

            $oldStart = (int)($row['start_time'] ?? 0);
            $oldFinish = (int)($row['finish_time'] ?? 0);

            if ($newStart !== $oldStart || $newFinish !== $oldFinish) {
                $upd->bind_param('iiii', $newStart, $newFinish, $id, $userId);
                $upd->execute();
            }

            $lastFinish = $newFinish;
        }

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        // сервисная оптимизация
    }

    $upd->close();
}

// -------------------- Этап 5: стоимость тренировки + ускорение --------------------

/**
 * Определяет ресурс и стоимость тренировки 1 юнита.
 *
 * Поддерживает (если будут добавлены позже):
 *  - $def['training_cost'] и опционально $def['training_res_type'] (RES_ELIXIR/RES_DARK/...) 
 *  - $def['train_cost'] / $def['train_res_type']
 *
 * Фолбэк (для текущей базы данных проекта): берем cost/res_type из levels[1].
 */
function army_get_training_cost(array $game_data, string $unitId, int $level = 0): array {
    $def = $game_data[$unitId] ?? null;
    if (!is_array($def)) return ['res_key' => 'elixir', 'cost' => 0];

    // 1) Явные поля
    $cost = null;
    $resType = null;
    if (isset($def['training_cost'])) $cost = (int)$def['training_cost'];
    if (isset($def['training_res_type'])) $resType = $def['training_res_type'];
    if ($cost === null && isset($def['train_cost'])) $cost = (int)$def['train_cost'];
    if ($resType === null && isset($def['train_res_type'])) $resType = $def['train_res_type'];

    // 2) Пер-уровень (если данные заданы)
    $curLvl = (int)$level;
    if ($curLvl <= 0 && isset($def['current_level'])) $curLvl = (int)$def['current_level'];
    if ($curLvl > 0 && !empty($def['levels'][$curLvl]) && is_array($def['levels'][$curLvl])) {
        if ($cost === null && isset($def['levels'][$curLvl]['train_cost'])) $cost = (int)$def['levels'][$curLvl]['train_cost'];
        if ($resType === null && isset($def['levels'][$curLvl]['train_res_type'])) $resType = $def['levels'][$curLvl]['train_res_type'];
    }

    // 3) Фолбэк: если явных данных нет — используем безопасную эвристику, чтобы UI не показывал 0.
    // Это НЕ баланс, а лишь отображение для карточек.
    if ($cost === null) {
        $space = (int)($def['housing_space'] ?? 1);
        if ($space < 1) $space = 1;
        $t = (int)($def['training_time'] ?? 0);
        $dps = 0;
        if (!empty($def['levels']) && is_array($def['levels'])) {
            // попробуем взять dps 1-го уровня как базу
            $l1 = $def['levels'][1] ?? [];
            if (is_array($l1)) {
                $dps = (int)($l1['dps'] ?? ($l1['damage_per_second'] ?? 0));
            }
        }
        // простая формула: место + время + dps
        $cost = max(1, ($space * 25) + (int)round($t / 2) + ($dps * 2));
    }

    // 4) Последний фолбэк: по типу (только ресурс)
    if ($resType === null) {
        $t = (string)($def['type'] ?? '');
        $resType = ($t === TYPE_DARK_TROOP) ? RES_DARK : RES_ELIXIR;
    }
    if ($cost === null) {
        // безопасный ноль (чтобы не ломать экономику, если данных нет)
        $cost = 0;
    }

    // RES_* -> ключ users.*
    $resKey = 'elixir';
    if (defined('RES_GOLD') && $resType === RES_GOLD) $resKey = 'gold';
    if (defined('RES_ELIXIR') && $resType === RES_ELIXIR) $resKey = 'elixir';
    if (defined('RES_DARK') && $resType === RES_DARK) $resKey = 'dark_elixir';
    if (defined('RES_GEMS') && $resType === RES_GEMS) $resKey = 'gems';

    return ['res_key' => $resKey, 'cost' => max(0, (int)$cost)];
}

/**
 * Цена ускорения в гемах по секундам (как в CoC: линейная интерполяция по контрольным точкам).
 *
 * Контрольные точки (time->gems):
 *  60s => 1
 *  3600s => 20
 *  86400s => 260
 *  604800s => 1000
 *
 * Реализация: ROUND(piecewise linear interpolation).
 */
function army_gem_cost_for_seconds(int $seconds): int {
    $x = max(0, (int)$seconds);
    if ($x <= 0) return 0;
    if ($x <= 60) return 1;

    // Segment 1: (60,1) -> (3600,20)
    if ($x <= 3600) {
        $y = ((20 - 1) / (3600 - 60)) * ($x - 60) + 1;
        return max(1, (int)round($y));
    }

    // Segment 2: (3600,20) -> (86400,260)
    if ($x <= 86400) {
        $y = ((260 - 20) / (86400 - 3600)) * ($x - 3600) + 20;
        return max(1, (int)round($y));
    }

    // Segment 3: (86400,260) -> (604800,1000)
    $y = ((1000 - 260) / (604800 - 86400)) * ($x - 86400) + 260;
    return max(1, (int)round($y));
}

/** Возвращает текущую тренируемую строку очереди (id, unit_id, qty, start_time, finish_time) либо null. */
function army_queue_get_current(mysqli $mysqli, int $userId): ?array {
    $now = time();

    // Если есть source — берем самый ранний current из любой очереди
    if (army_db_has_column($mysqli, 'player_training_queue', 'source')) {
        $stmt = $mysqli->prepare("SELECT id, unit_id, unit_level, qty, start_time, finish_time, status, source FROM player_training_queue WHERE user_id=? AND status='training' AND start_time<=? AND finish_time>? ORDER BY start_time ASC, id ASC LIMIT 1");
        if (!$stmt) return null;
        $stmt->bind_param('iii', $userId, $now, $now);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row ?: null;
    }

    $stmt = $mysqli->prepare("SELECT id, unit_id, unit_level, qty, start_time, finish_time, status FROM player_training_queue WHERE user_id=? AND status='training' AND start_time<=? AND finish_time>? ORDER BY start_time ASC, id ASC LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('iii', $userId, $now, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** Ставит finish_time=now для всех training (и/или одного текущего) и переводит их в ready. */
function army_queue_mark_ready(mysqli $mysqli, int $userId, bool $all = false): int {
    $now = time();
    if ($all) {
        $stmt = $mysqli->prepare("UPDATE player_training_queue SET status='ready', start_time=?, finish_time=? WHERE user_id=? AND status='training'");
        if (!$stmt) return 0;
        $stmt->bind_param('iii', $now, $now, $userId);
        $stmt->execute();
        $aff = $stmt->affected_rows;
        $stmt->close();
        return (int)$aff;
    }

    $cur = army_queue_get_current($mysqli, $userId);
    if (!$cur) return 0;
    $qid = (int)($cur['id'] ?? 0);
    if ($qid <= 0) return 0;

    $stmt = $mysqli->prepare("UPDATE player_training_queue SET status='ready', finish_time=? WHERE user_id=? AND id=?");
    if (!$stmt) return 0;
    $stmt->bind_param('iii', $now, $userId, $qid);
    $stmt->execute();
    $aff = $stmt->affected_rows;
    $stmt->close();
    return (int)$aff;
}


// ============================================================
// HEROES (Stage 10.1)
// ============================================================

/** Возвращает список ID героев из game_data. */
function army_list_hero_ids(array $game_data): array {
    $ids = [];
    foreach ($game_data as $id => $def) {
        if (!is_array($def)) continue;
        if (($def['type'] ?? '') === TYPE_HERO) {
            $ids[] = (string)$id;
        }
    }
    sort($ids);
    return $ids;
}

/** Берет строку героя (если есть). */
function army_get_player_hero_row(mysqli $mysqli, int $userId, string $heroId): ?array {
    $stmt = $mysqli->prepare("SELECT hero_id, level, unlocked, upgrading_until, upgrading_to_level, equipment_json FROM player_heroes WHERE user_id=? AND hero_id=? LIMIT 1");
    if (!$stmt) return null;
    $stmt->bind_param('is', $userId, $heroId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** Создает/обновляет запись героя (upsert). */
function army_upsert_player_hero(mysqli $mysqli, int $userId, string $heroId, int $level, int $unlocked, int $upUntil = 0, int $upTo = 0, string $equipJson = ''): bool {
    if ($equipJson === '') $equipJson = '{}';
    $stmt = $mysqli->prepare("INSERT INTO player_heroes (user_id, hero_id, level, unlocked, upgrading_until, upgrading_to_level, equipment_json) VALUES (?,?,?,?,?,?,?)\n        ON DUPLICATE KEY UPDATE level=VALUES(level), unlocked=VALUES(unlocked), upgrading_until=VALUES(upgrading_until), upgrading_to_level=VALUES(upgrading_to_level), equipment_json=VALUES(equipment_json)");
    if (!$stmt) return false;
    $stmt->bind_param('isiiiis', $userId, $heroId, $level, $unlocked, $upUntil, $upTo, $equipJson);
    $ok = $stmt->execute();
    $stmt->close();
    return (bool)$ok;
}

/** Синхронизирует завершенные улучшения героев. */
function army_heroes_sync(mysqli $mysqli, int $userId): void {
    $now = time();
    // Закрываем только тех, у кого upgrade закончен
    $stmt = $mysqli->prepare("SELECT hero_id, upgrading_until, upgrading_to_level FROM player_heroes WHERE user_id=? AND upgrading_until>0 AND upgrading_until<=?");
    if (!$stmt) return;
    $stmt->bind_param('ii', $userId, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $rows[] = $r;
    }
    $stmt->close();
    if (!$rows) return;

    foreach ($rows as $r) {
        $hid = (string)($r['hero_id'] ?? '');
        $to = (int)($r['upgrading_to_level'] ?? 0);
        if ($hid === '' || $to <= 0) continue;
        $upd = $mysqli->prepare("UPDATE player_heroes SET level=?, upgrading_until=0, upgrading_to_level=0 WHERE user_id=? AND hero_id=?");
        if (!$upd) continue;
        $upd->bind_param('iis', $to, $userId, $hid);
        $upd->execute();
        $upd->close();
    }
}

/**
 * Возвращает состояние героев (для barracks_state / heroes_state).
 * Добавляет: locked_reason, can_unlock, can_upgrade, cap, next.
 */
function army_get_heroes_state(mysqli $mysqli, int $userId, array $game_data, int $townHallLevel): array {
    $heroHallLvl = army_get_building_level($mysqli, $userId, 'hero_hall');
    $heroHallRow = army_get_building_row($mysqli, $userId, 'hero_hall');
    $heroHallStatus = $heroHallRow ? (string)($heroHallRow['status'] ?? 'active') : 'none';
    $heroHallFinish = $heroHallRow ? (int)($heroHallRow['finish_time'] ?? 0) : 0;

    $out = [
        'hero_hall' => [
            'level' => $heroHallLvl,
            'status' => $heroHallStatus,
            'finish_time' => $heroHallFinish,
        ],
        'heroes' => []
    ];

    $heroIds = army_list_hero_ids($game_data);
    $now = time();

    foreach ($heroIds as $heroId) {
        $def = $game_data[$heroId] ?? null;
        if (!is_array($def)) continue;

        $unlockTH = (int)($def['unlock_th'] ?? 0);
        $unlockHH = (int)($def['unlock_hh'] ?? 0);
        $levels = (array)($def['levels'] ?? []);

        $player = army_get_player_hero_row($mysqli, $userId, $heroId);
        $unlocked = $player ? (int)($player['unlocked'] ?? 0) : 0;
        $lvl = $player ? (int)($player['level'] ?? 0) : 0;
        $upUntil = $player ? (int)($player['upgrading_until'] ?? 0) : 0;
        $upTo = $player ? (int)($player['upgrading_to_level'] ?? 0) : 0;
        $equipJson = $player ? (string)($player['equipment_json'] ?? '{}') : '{}';

        $isUpgrading = ($upUntil > $now);
        $timeLeft = $isUpgrading ? ($upUntil - $now) : 0;

        

        $speedupCost = $isUpgrading ? army_gem_cost_for_seconds($timeLeft) : 0;
// Cap по уровню Герой-холла: максимальный уровень, у которого hh_req <= heroHallLvl
        $cap = 0;
        foreach ($levels as $L => $ldata) {
            $hhReq = (int)($ldata['hh_req'] ?? 0);
            if ($hhReq <= $heroHallLvl && $L > $cap) {
                $cap = (int)$L;
            }
        }
        if ($cap <= 0) {
            // Если нет таблицы уровней (или heroHall еще 0)
            $cap = 0;
        }

// Total max level from game data (independent of Hero Hall cap)
$maxLevel = 0;
foreach ($levels as $L => $_ldata) {
    if ((int)$L > $maxLevel) $maxLevel = (int)$L;
}


        // Locked reasons
        $lockedReason = '';
        if ($heroHallLvl <= 0) {
            $lockedReason = 'Постройте Зал героев.';
        } elseif ($heroHallStatus !== 'active' && $heroHallFinish > $now) {
            $lockedReason = 'Зал героев строится/улучшается.';
        } elseif ($unlockTH > 0 && $townHallLevel < $unlockTH) {
            $lockedReason = 'Требуется Ратуша ' . $unlockTH . '.';
        } elseif ($unlockHH > 0 && $heroHallLvl < $unlockHH) {
            $lockedReason = 'Требуется Зал героев ' . $unlockHH . '.';
        }

        $canUnlock = ($unlocked <= 0) && ($lockedReason === '');

        // Next upgrade data
        $next = null;
        $canUpgrade = false;
        if ($unlocked > 0 && !$isUpgrading && $cap > 0) {
            $nextLevel = $lvl + 1;
            if (isset($levels[$nextLevel])) {
                $ldata = $levels[$nextLevel];
                $hhReq = (int)($ldata['hh_req'] ?? 0);
                if ($nextLevel <= $cap && ($hhReq <= $heroHallLvl)) {
                    $canUpgrade = true;
                }
                $next = [
                    'level' => $nextLevel,
                    'cost' => (int)($ldata['cost'] ?? 0),
                    'res_type' => (string)($ldata['res_type'] ?? ''),
                    'time' => (int)($ldata['time'] ?? 0),
                    'hh_req' => $hhReq,
                    'dps' => (int)($ldata['dps'] ?? 0),
                    'hp' => (int)($ldata['hp'] ?? 0),
                    'recovery' => (int)($ldata['recovery'] ?? 0),
                ];
            }
        }

        // Current stats:
        // - if unlocked: current level stats
        // - if NOT unlocked: show level 1 stats so the UI can display full characteristics before unlock
        $curStats = null;
        if ($unlocked > 0 && $lvl > 0 && isset($levels[$lvl])) {
            $c = $levels[$lvl];
            $curStats = [
                'dps' => (int)($c['dps'] ?? 0),
                'hp' => (int)($c['hp'] ?? 0),
                'recovery' => (int)($c['recovery'] ?? 0),
            ];
        } elseif ($unlocked <= 0 && isset($levels[1])) {
            $c = $levels[1];
            $curStats = [
                'dps' => (int)($c['dps'] ?? 0),
                'hp' => (int)($c['hp'] ?? 0),
                'recovery' => (int)($c['recovery'] ?? 0),
            ];
        }

        $out['heroes'][$heroId] = [
            'id' => $heroId,
            'name' => (string)($def['name'] ?? $heroId),
            'unlock_th' => $unlockTH,
            'unlock_hh' => $unlockHH,
            'unlocked' => $unlocked,
            'level' => $lvl,
            'cap' => $cap,
            'max_level' => $maxLevel,
            'upgrading' => $isUpgrading,
            'upgrading_to' => $upTo,
            'upgrade_finish' => $upUntil,
            'time_left' => $timeLeft,
            'speedup_cost' => $speedupCost,
            'locked_reason' => $lockedReason,
            'can_unlock' => $canUnlock,
            'can_upgrade' => $canUpgrade,
            'current' => $curStats,
            'next' => $next,
            'equipment_json' => $equipJson,
        ];
    }

    return $out;
}
