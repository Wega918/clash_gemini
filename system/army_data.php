<?php
// system/army_data.php
// Единая точка входа для "армейных" данных (войска/армейные здания).
//
// ВАЖНО: на текущем этапе мы НЕ переносим данные из system/game_data.php,
// чтобы не сломать уже рабочие части игры. Вместо этого мы формируем
// отдельную структуру $army_data поверх существующего $game_data.

if (!defined('ARMY_DATA_LOADED')) define('ARMY_DATA_LOADED', true);

/**
 * Собирает army_data поверх $game_data.
 *
 * Структура:
 *  - units: unit_id => cfg (как в $game_data)
 *  - buildings: building_id => cfg (как в $game_data)
 *  - groups:
 *      - normal_troops: [unit_id, ...]
 *      - dark_troops:   [] (на 1 этапе не используем)
 *      - spells:        []
 *      - siege:         []
 *      - heroes:        []
 *      - pets:          []
 *  - name_to_id: русское_название => unit_id
 */
function army_build_data(array $game_data): array {
    // Важно: здесь мы НЕ храним «копию» данных отдельно от game_data.
    // Мы строим индексы/группы поверх уже загруженного $game_data, чтобы
    // UI и API всегда работали с *реальной* конфигурацией.

    // --- helper: ordered unlock chain (как в CoC) ---
    $appendUnlocks = function(string $buildingId, array $allowedTypes) use (&$game_data): array {
        $ordered = [];
        if (empty($game_data[$buildingId]['levels']) || !is_array($game_data[$buildingId]['levels'])) return $ordered;
        foreach ($game_data[$buildingId]['levels'] as $lvl => $bdef) {
            if (!is_array($bdef) || !isset($bdef['unlocks'])) continue;
            $u = $bdef['unlocks'];
            $ids = [];
            if (is_array($u)) {
                foreach ($u as $x) {
                    $x = trim((string)$x);
                    if ($x === '' || empty($game_data[$x]) || !is_array($game_data[$x])) continue;
                    $t = (string)($game_data[$x]['type'] ?? '');
                    if (!in_array($t, $allowedTypes, true)) continue;
                    $ids[] = $x;
                }
            } else {
                $x = trim((string)$u);
                if ($x !== '' && !empty($game_data[$x]) && is_array($game_data[$x])) {
                    $t = (string)($game_data[$x]['type'] ?? '');
                    if (in_array($t, $allowedTypes, true)) $ids[] = $x;
                }
            }
            foreach ($ids as $x) {
                if (!in_array($x, $ordered, true)) $ordered[] = $x;
            }
        }
        return $ordered;
    };

    $normalIds = $appendUnlocks('barracks', [defined('TYPE_TROOP') ? TYPE_TROOP : 'troop']);
    $darkIds = $appendUnlocks('dark_barracks', [defined('TYPE_DARK_TROOP') ? TYPE_DARK_TROOP : 'dark_troop']);
    $siegeIds = $appendUnlocks('siege_workshop', [defined('TYPE_SIEGE') ? TYPE_SIEGE : 'siege']);
    $spellIds = $appendUnlocks('spell_factory', [defined('TYPE_SPELL') ? TYPE_SPELL : 'spell']);
    $darkSpellIds = $appendUnlocks('dark_spell_factory', [defined('TYPE_DARK_SPELL') ? TYPE_DARK_SPELL : 'dark_spell']);

    // Добавляем «остальные» сущности по типам, чтобы карточки могли быть показаны даже если
    // unlock chain в данных неполный.
    $allByType = [
        'normal' => [],
        'dark' => [],
        'siege' => [],
        'spell' => [],
        'hero' => [],
        'pet' => [],
    ];

    foreach ($game_data as $id => $def) {
        if (!is_string($id) || $id === '' || !is_array($def)) continue;
        $type = (string)($def['type'] ?? '');
        if (defined('TYPE_TROOP') && $type === TYPE_TROOP) $allByType['normal'][] = $id;
        if (defined('TYPE_DARK_TROOP') && $type === TYPE_DARK_TROOP) $allByType['dark'][] = $id;
        if (defined('TYPE_SIEGE') && $type === TYPE_SIEGE) $allByType['siege'][] = $id;
        if (defined('TYPE_SPELL') && $type === TYPE_SPELL) $allByType['spell'][] = $id;
        if (defined('TYPE_DARK_SPELL') && $type === TYPE_DARK_SPELL) $allByType['spell'][] = $id;
        if (defined('TYPE_HERO') && $type === TYPE_HERO) $allByType['hero'][] = $id;
        if (defined('TYPE_PET') && $type === TYPE_PET) $allByType['pet'][] = $id;
    }

    $mergeUnique = function(array $a, array $b): array {
        foreach ($b as $x) {
            if (!in_array($x, $a, true)) $a[] = $x;
        }
        return $a;
    };

    $normalIds = $mergeUnique($normalIds, $allByType['normal']);
    $darkIds = $mergeUnique($darkIds, $allByType['dark']);
    $siegeIds = $mergeUnique($siegeIds, $allByType['siege']);
    $spellIds = $mergeUnique($mergeUnique($spellIds, $darkSpellIds), $allByType['spell']);

    $unitsOut = [];
    $nameToId = [];
    foreach (array_merge($normalIds, $darkIds, $siegeIds, $spellIds, $allByType['hero'], $allByType['pet']) as $id) {
        if (!isset($game_data[$id]) || !is_array($game_data[$id])) continue;
        $unitsOut[$id] = $game_data[$id];
        $ru = trim((string)($game_data[$id]['name'] ?? ''));
        if ($ru !== '') $nameToId[$ru] = $id;
    }

    $bOut = [];
    foreach (['army_camp','barracks','dark_barracks','laboratory','spell_factory','dark_spell_factory','siege_workshop','hero_hall'] as $bid) {
        if (!isset($game_data[$bid]) || !is_array($game_data[$bid])) continue;
        $bOut[$bid] = $game_data[$bid];
    }

    return [
        'units' => $unitsOut,
        'buildings' => $bOut,
        'groups' => [
            'normal_troops' => $normalIds,
            'dark_troops' => $darkIds,
            'siege' => $siegeIds,
            'spells' => $spellIds,
            'heroes' => $allByType['hero'],
            'pets' => $allByType['pet'],
        ],
        'name_to_id' => $nameToId,
    ];
}

