<?php
/**
 * building_views.php
 * Рендеры для модальных окон деревни (кроме storage/production).
 */

require_once __DIR__ . '/../system/game_data.php';

/**
 * Вспомогательное: получить id модального окна.
 */
function getModalIdBySection(string $section): string {
    return $section . '-modal';
}

/**
 * Главные меню секций.
 */
function renderSectionMainView(array $user, string $section): string {
    $season = getActiveSeason($GLOBALS['mysqli'] ?? null);

    global $game_data;

    $section = strtolower($section);
    $modal_id = getModalIdBySection($section);

    // Для совместимости с существующими стилями используем ту же шапку.
    $title_map = [
        'townhall' => 'РАТУША',
        'defense'  => 'ОБОРОНА',
        'barracks' => 'КАЗАРМЫ',
        'lab'      => 'ЛАБОРАТОРИЯ',
        'clan'     => 'КЛАНОВАЯ КРЕПОСТЬ',
    ];
    $title = $title_map[$section] ?? strtoupper($section);

    ob_start();
    ?>
    <div class="modal-content">
        <div class="modal-header-controls">
            <h2 class="modal-title-text-inside-panel"><?= htmlspecialchars($title) ?></h2>
            <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= htmlspecialchars($modal_id) ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
            </button>
        </div>

        <div class="modal-body-custom">

            <?php if ($section === 'townhall'): ?>
                <?php
                    $th = getPlayerBuildingsByType($GLOBALS['mysqli'], 'townhall');
                    $th_building = !empty($th) ? $th[0] : null;
                    $lvl = $th_building ? (int)$th_building['level'] : ($user['townhall_lvl'] ?? 1);
                ?>
                <p>🏛 Ратуша: уровень <strong><?= (int)$lvl ?></strong></p>
                <p>Улучшение ратуши открывает новые здания и увеличивает лимиты.</p>
                <?php if ($th_building): ?>
                    <button class="btn btn-buy-action" onclick="loadSectionDetail('townhall', <?= (int)$th_building['id'] ?>)">Открыть</button>
                <?php else: ?>
                    <div class="alert alert-warning">Ратуша не найдена в деревне. (Проверьте player_buildings)</div>
                <?php endif; ?>

            <?php elseif ($section === 'defense'): ?>
                <p>Выберите тип обороны:</p>
                <div class="resource-selection" style="display:grid; grid-template-columns: repeat(2,1fr); gap:10px;">
                    <div class="resource-card" onclick="loadSectionList('defense','cannon')">
                        <img src="<?= season_img('/images/building/Cannon/Cannon1.png', $season) ?>" alt="Пушки" onerror="this.style.display='none'">
                        <h3 class="resource-title-text">Пушки</h3>
                    </div>
                    <div class="resource-card" onclick="loadSectionList('defense','archer_tower')">
                        <img src="<?= season_img('/images/building/Archer_Tower/Archer_Tower1.png', $season) ?>" alt="Башни" onerror="this.style.display='none'">
                        <h3 class="resource-title-text">Башни</h3>
                    </div>
                </div>

            <?php elseif ($section === 'barracks'): ?>
                <p>Казармы тренируют войска. Тренировка идёт по времени и стоит ресурсы.</p>
                <?php
                    $uid = (int)$user['id'];
                    $barracks_list = getPlayerBuildingsByType($GLOBALS['mysqli'], 'barracks');
                    $barracks = !empty($barracks_list) ? $barracks_list[0] : null;
                    $barracks_lvl = $barracks ? (int)$barracks['level'] : 0;

                    $cap = getArmyCapacity($GLOBALS['mysqli'], $uid);
                    $used = getArmyUsed($GLOBALS['mysqli'], $uid);
                    $free = max(0, $cap - $used);

                    $queue = getTrainingQueue($GLOBALS['mysqli'], $uid);
                ?>

                <?php if (!$barracks): ?>
                    <div class="alert alert-warning">Казарма не построена.</div>
                <?php else: ?>
                    <p>Уровень казармы: <strong><?= (int)$barracks_lvl ?></strong></p>
                    <p>Лагерь: <strong><?= (int)$used ?>/<?= (int)$cap ?></strong> (свободно <?= (int)$free ?>)</p>

                    <?php if (!empty($queue)): ?>
                        <div class="stylized-card" style="margin-bottom:10px;">
                            <div class="item-info-extended">
                                <strong class="item-title-text">Очередь тренировки</strong>
                                <?php foreach ($queue as $q):
                                    $unit_id = $q['unit_id'];
                                    $qty = (int)$q['qty'];
                                    $finish = (int)$q['finish_time'];
                                    $left = max(0, $finish - time());
                                    $name = $game_data[$unit_id]['name'] ?? $unit_id;
                                ?>
                                    <div style="margin-top:6px;">
                                        <?= htmlspecialchars($name) ?> ×<?= (int)$qty ?> — осталось <span class="js-countdown" data-finish="<?= (int)$finish ?>"><?= format_time($left) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php
                        $unlocked = getUnlockedTroopsByBarracksLevel($barracks_lvl);
                        if (empty($unlocked)) {
                            echo '<div class="alert alert-warning">Нет доступных войск.</div>';
                        }
                    ?>

                    <div class="building-list-view">
                        <?php foreach ($unlocked as $unit_id):
                            $u = $game_data[$unit_id] ?? null;
                            if (!$u) continue;
                            $unit_name = $u['name'] ?? $unit_id;

                            $lvl = getUnitLevel($GLOBALS['mysqli'], $uid, $unit_id);
                            $stats = $u['levels'][$lvl] ?? ($u['levels'][1] ?? null);
                            if (!$stats) continue;

                            $cost = (int)($stats['cost'] ?? 0);
                            $res_type = $stats['res_type'] ?? RES_ELIXIR;
                            $icon = getResourceIconPath($res_type);
                            $housing = (int)($stats['housing'] ?? 1);
                            $t = (int)($stats['time'] ?? 0);
                        ?>
                            <div class="building-list-item stylized-card">
                                <div class="item-info-extended">
                                    <strong class="item-title-text"><?= htmlspecialchars($unit_name) ?> (ур. <?= (int)$lvl ?>)</strong>
                                    <p>Цена: <b><?= format_resource_amount($cost) ?></b> <img src="<?= htmlspecialchars($icon) ?>" width="14" style="vertical-align: middle;"></p>
                                    <p>Вместимость: <?= (int)$housing ?> | Время: <?= format_time($t) ?></p>
                                </div>
                                <div class="item-action-button">
                                    <button class="btn btn-buy-action" onclick="trainUnit('<?= htmlspecialchars($unit_id) ?>', 1)">Тренировать</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php elseif ($section === 'lab'): ?>
                <?php
                    $uid = (int)$user['id'];
                    $lab_list = getPlayerBuildingsByType($GLOBALS['mysqli'], 'laboratory');
                    $lab = !empty($lab_list) ? $lab_list[0] : null;
                    $research = getResearchState($GLOBALS['mysqli'], $uid);
                    $hasActive = false;
                    foreach ($research as $r) { if (($r['status'] ?? '') === 'researching') { $hasActive = true; break; } }
                ?>

                <?php if (!$lab): ?>
                    <div class="alert alert-warning">Лаборатория не построена.</div>
                <?php else: ?>
                    <p>Уровень лаборатории: <strong><?= (int)$lab['level'] ?></strong></p>

                    <?php if ($hasActive): ?>
                        <div class="stylized-card" style="margin-bottom:10px;">
                            <div class="item-info-extended">
                                <strong class="item-title-text">Текущее исследование</strong>
                                <?php foreach ($research as $rid => $r):
                                    if (($r['status'] ?? '') !== 'researching') continue;
                                    $name = $game_data[$rid]['name'] ?? $rid;
                                    $finish = (int)$r['finish_time'];
                                    $left = max(0, $finish - time());
                                ?>
                                    <div style="margin-top:6px;">
                                        <?= htmlspecialchars($name) ?> → ур. <?= (int)($r['target_level'] ?? ((int)$r['level']+1)) ?> — осталось
                                        <span class="js-countdown" data-finish="<?= (int)$finish ?>"><?= format_time($left) ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p style="margin-top:10px;">Доступные улучшения (по открытым войскам казармы):</p>
                    <?php
                        $barracks_list = getPlayerBuildingsByType($GLOBALS['mysqli'], 'barracks');
                        $barracks_lvl = !empty($barracks_list) ? (int)$barracks_list[0]['level'] : 0;
                        $unlocked = $barracks_lvl > 0 ? getUnlockedTroopsByBarracksLevel($barracks_lvl) : [];
                    ?>

                    <?php if (empty($unlocked)): ?>
                        <div class="alert alert-warning">Нет открытых войск для исследования (нужна Казарма).</div>
                    <?php else: ?>
                        <div class="building-list-view">
                            <?php foreach ($unlocked as $unit_id):
                                $u = $game_data[$unit_id] ?? null;
                                if (!$u) continue;

                                $current = (int)($research[$unit_id]['level'] ?? 1);
                                $next = $current + 1;
                                $nextStats = $u['levels'][$next] ?? null;
                                $canNext = $nextStats !== null;

                                $name = $u['name'] ?? $unit_id;
                            ?>
                                <div class="building-list-item stylized-card">
                                    <div class="item-info-extended">
                                        <strong class="item-title-text"><?= htmlspecialchars($name) ?></strong>
                                        <p>Текущий уровень: <b><?= (int)$current ?></b></p>
                                        <?php if ($canNext):
                                            $cost = (int)($nextStats['cost'] ?? 0);
                                            $res_type = $nextStats['res_type'] ?? RES_ELIXIR;
                                            $icon = getResourceIconPath($res_type);
                                            $t = (int)($nextStats['time'] ?? 0);
                                        ?>
                                            <p>Следующий: ур. <?= (int)$next ?> — <?= format_resource_amount($cost) ?> <img src="<?= htmlspecialchars($icon) ?>" width="14" style="vertical-align: middle;">, время <?= format_time($t) ?></p>
                                        <?php else: ?>
                                            <p>Макс. уровень достигнут</p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-action-button">
                                        <?php if ($canNext): ?>
                                            <button class="btn btn-buy-action" onclick="startResearchAction('<?= htmlspecialchars($unit_id) ?>')" <?= $hasActive ? 'disabled' : '' ?>>Исследовать</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

            <?php elseif ($section === 'clan'): ?>
                <?php
                    $uid = (int)$user['id'];
                    $clan = getUserClan($GLOBALS['mysqli'], $uid);
                ?>

                <?php if ($clan): ?>
                    <p>Ваш клан: <b><?= htmlspecialchars($clan['name']) ?></b></p>
                    <p><?= htmlspecialchars($clan['description']) ?></p>

                    <?php
                        $members = getClanMembers($GLOBALS['mysqli'], (int)$clan['id']);
                    ?>
                    <div class="stylized-card" style="margin:10px 0;">
                        <div class="item-info-extended">
                            <strong class="item-title-text">Участники (<?= (int)count($members) ?>)</strong>
                            <?php foreach ($members as $m): ?>
                                <div style="margin-top:6px;">
                                    #<?= (int)$m['id'] ?> <?= htmlspecialchars($m['login']) ?> — <?= htmlspecialchars($m['role']) ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button class="btn btn-buy-action" onclick="leaveClanAction()">Выйти из клана</button>

                <?php else: ?>
                    <div class="stylized-card" style="margin-bottom:10px;">
                        <div class="item-info-extended">
                            <strong class="item-title-text">Создать клан</strong>
                            <div style="margin-top:8px;">
                                <input id="clan_name" type="text" placeholder="Название" style="width:100%; padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.15); background:rgba(0,0,0,0.25); color:#fff;">
                            </div>
                            <div style="margin-top:8px;">
                                <input id="clan_desc" type="text" placeholder="Описание" style="width:100%; padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.15); background:rgba(0,0,0,0.25); color:#fff;">
                            </div>
                            <div style="margin-top:10px;">
                                <button class="btn btn-buy-action" onclick="createClanAction()">Создать</button>
                            </div>
                        </div>
                    </div>

                    <?php
                        $q = cleanString($_GET['q'] ?? '', 40);
                        $clans = searchClans($GLOBALS['mysqli'], $q);
                    ?>
                    <p>Список кланов:</p>
                    <div class="building-list-view">
                        <?php foreach ($clans as $c): ?>
                            <div class="building-list-item stylized-card">
                                <div class="item-info-extended">
                                    <strong class="item-title-text"><?= htmlspecialchars($c['name']) ?></strong>
                                    <p><?= htmlspecialchars($c['description']) ?></p>
                                    <p>Участников: <?= (int)$c['members'] ?></p>
                                </div>
                                <div class="item-action-button">
                                    <button class="btn btn-buy-action" onclick="joinClanAction(<?= (int)$c['id'] ?>)">Вступить</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            <?php else: ?>
                <p>Раздел в разработке.</p>
            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Список зданий выбранного типа.
 */
function renderSectionListView(array $user, string $section, string $type, array $built_buildings): string {
    // reuse existing list view from storage_views for consistent UI
    // (она умеет показывать: построенные + слот "построить" + блокировки)
    return renderStorageListView($user, $type, $built_buildings);
}

/**
 * Детальная карточка здания.
 */
function renderSectionDetailView(array $user, string $section, array $building_row): string {
    // reuse existing detail view
    return renderStorageDetailView($user, $building_row);
}

/**
 * Получить список войск, доступных по уровню казармы.
 * В game_data у barracks.levels[x].unlocks лежит айди юнита, открываем всё до текущего уровня.
 */
function getUnlockedTroopsByBarracksLevel(int $barracks_lvl): array {
    global $game_data;
    $out = [];
    $levels = $game_data['barracks']['levels'] ?? [];
    foreach ($levels as $lvl => $info) {
        if ((int)$lvl > $barracks_lvl) break;
        if (!empty($info['unlocks'])) {
            $out[] = $info['unlocks'];
        }
    }
    return array_values(array_unique($out));
}

/**
 * Тренировка войск (упрощённо, мгновенно): списываем ресурс и увеличиваем player_army.
 */
function trainUnitInstant(mysqli $mysqli, array $user, string $unit_id, int $qty): array {
    global $game_data;

    if (!isset($game_data[$unit_id])) {
        throw new RuntimeException('Неизвестный юнит', 400);
    }
    $unit = $game_data[$unit_id];
    $cost_per = (int)($unit['levels'][1]['cost'] ?? 0);
    $res_type = $unit['levels'][1]['res_type'] ?? RES_ELIXIR;
    $total_cost = $cost_per * $qty;

    $res_key = ($res_type === RES_DARK) ? 'dark_elixir' : strtolower($res_type);
    if (strpos($res_key, 'res_') === 0) {
        $res_key = substr($res_key, 4);
    }

    if (($user[$res_key] ?? 0) < $total_cost) {
        throw new RuntimeException('Недостаточно ресурсов', 400);
    }

    $mysqli->begin_transaction();
    try {
        // списываем ресурс
        $stmt = $mysqli->prepare("UPDATE users SET `$res_key` = `$res_key` - ? WHERE id = ?");
        $stmt->bind_param('ii', $total_cost, $user['id']);
        if (!$stmt->execute()) {
            throw new RuntimeException('Не удалось списать ресурс');
        }
        $stmt->close();

        // добавляем армию
        $stmt = $mysqli->prepare("INSERT INTO player_army (user_id, unit_id, amount) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)");
        $stmt->bind_param('isi', $user['id'], $unit_id, $qty);
        if (!$stmt->execute()) {
            throw new RuntimeException('Не удалось обновить армию');
        }
        $stmt->close();

        $mysqli->commit();
    } catch (Throwable $e) {
        $mysqli->rollback();
        throw $e;
    }

    return getUser($mysqli);
}

/**
 * Информация о клане пользователя (если состоит).
 */
function getUserClanInfo(mysqli $mysqli, int $user_id): ?array {
    $sql = "SELECT c.name, cm.role FROM clan_members cm JOIN clans c ON c.id = cm.clan_id WHERE cm.user_id = ? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}
