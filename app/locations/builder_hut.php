<?php
/**
 * app/locations/builder_hut.php
 * Локация: Хижина строителя (backend)
 *
 * Возможности:
 * - Просмотр количества строителей (всего/занято/свободно)
 * - Список текущих строек/улучшений
 * - Найм дополнительных строителей (через покупку "хижины строителя" за гемы)
 */

// Локальный форматтер времени, чтобы не зависеть от наличия format_time_display() в system/function.php
// (в текущей версии проекта этой функции может не быть, что приводило к HTTP 500 при занятых строителях).
if (!function_exists('builderhut_time_display')) {
    function builderhut_time_display(int $seconds): string {
        $seconds = max(0, (int)$seconds);

        // Если в проекте уже есть общий форматтер — используем его.
        if (function_exists('format_time_display')) {
            try {
                return (string)format_time_display($seconds);
            } catch (Throwable $e) {
                // fallback ниже
            }
        }

        $d = intdiv($seconds, 86400);
        $seconds -= $d * 86400;
        $h = intdiv($seconds, 3600);
        $seconds -= $h * 3600;
        $m = intdiv($seconds, 60);
        $s = $seconds - $m * 60;

        if ($d > 0) return $d . 'д ' . $h . 'ч';
        if ($h > 0) return $h . 'ч ' . $m . 'м';
        if ($m > 0) return $m . 'м ' . $s . 'с';
        return $s . 'с';
    }
}

// Нужен для расчёта стоимости ускорения (гемы) и прочих хелперов.
// Важно: подключаем ДО формирования HTML, иначе при активных стройках получим fatal
// (call to undefined function army_gem_cost_for_seconds) и HTTP 500.
require_once __DIR__ . '/../../system/army_helpers.php';

/**
 * AJAX action: ускорение стройки/улучшения (списание гемов и моментальное завершение)
 *
 * Фронт шлёт POST на ajax.php?page=builder_hut с action=building_speedup.
 * Этот обработчик ДОЛЖЕН отрабатывать до генерации HTML, иначе получится мешанина HTML+JSON.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'building_speedup') {
    header('Content-Type: application/json; charset=utf-8');

    // База и сессия поднимаются в ajax.php через system/function.php.
    global $mysqli;
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        if (isset($GLOBALS['mysqli']) && ($GLOBALS['mysqli'] instanceof mysqli)) {
            $mysqli = $GLOBALS['mysqli'];
        } elseif (function_exists('getDB')) {
            $mysqli = getDB();
        }
    }
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        echo json_encode(['ok' => false, 'error' => 'DB connection not available'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // CSRF
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if (!function_exists('check_csrf') || !check_csrf($csrf)) {
        echo json_encode(['ok' => false, 'error' => 'CSRF'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pbId = (int)($_POST['player_building_id'] ?? 0);
    $dryRun = (int)($_POST['dry_run'] ?? 0);
    if ($pbId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'player_building_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Not logged in'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $now = time();
    $stmt = $mysqli->prepare("SELECT id, building_id, level, target_level, status, finish_time FROM player_buildings WHERE id=? AND user_id=? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['ok' => false, 'error' => 'DB'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stmt->bind_param('ii', $pbId, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Building not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $status = (string)($row['status'] ?? '');
    $finish = (int)($row['finish_time'] ?? 0);
    if (!(($status === 'constructing' || $status === 'upgrading') && $finish > $now)) {
        echo json_encode(['ok' => true, 'cost_gems' => 0, 'message' => 'Nothing to speed up'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $left = max(0, $finish - $now);
    $cost = army_gem_cost_for_seconds((int)$left);

    if ($dryRun) {
        echo json_encode(['ok' => true, 'cost_gems' => $cost], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($cost <= 0) {
        echo json_encode(['ok' => true, 'cost_gems' => 0], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mysqli->begin_transaction();
    try {
        $lock = $mysqli->prepare('SELECT gems FROM users WHERE id=? FOR UPDATE');
        if (!$lock) throw new RuntimeException('DB lock failed');
        $lock->bind_param('i', $uid);
        $lock->execute();
        $u = $lock->get_result()->fetch_assoc();
        $lock->close();

        $gems = (int)($u['gems'] ?? 0);
        if ($gems < $cost) {
            throw new RuntimeException('Недостаточно гемов');
        }

        $newGems = $gems - $cost;
        $upU = $mysqli->prepare('UPDATE users SET gems=? WHERE id=?');
        if (!$upU) throw new RuntimeException('DB update user failed');
        $upU->bind_param('ii', $newGems, $uid);
        $upU->execute();
        $upU->close();

        // Завершаем стройку/улучшение прямо сейчас.
        // ВАЖНО: для апгрейда нужно применить target_level, иначе ускорение списывает гемы,
        // но уровень не меняется (останется "upgrading" -> "active" на старом уровне).
        if ($status === 'upgrading') {
            $upB = $mysqli->prepare("UPDATE player_buildings SET status='active', finish_time=0, level=COALESCE(target_level, level), target_level=NULL WHERE id=? AND user_id=?");
        } else {
            $upB = $mysqli->prepare("UPDATE player_buildings SET status='active', finish_time=0 WHERE id=? AND user_id=?");
        }
        if (!$upB) throw new RuntimeException('DB update building failed');
        $upB->bind_param('ii', $pbId, $uid);
        $upB->execute();
        $upB->close();

        $mysqli->commit();
        echo json_encode(['ok' => true, 'cost_gems' => $cost, 'gems_left' => $newGems], JSON_UNESCAPED_UNICODE);
        exit;
    } catch (Throwable $e) {
        $mysqli->rollback();
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

try {
    // Чтобы при исключениях не получался "кусок HTML + ошибка посередине" —
    // собираем вывод в буфер и при ошибке очищаем.
    $__bh_start_ob_level = ob_get_level();
    ob_start();

    global $mysqli, $game_data;

    // Надёжно получаем соединение с БД даже если локация подключена внутри функции (ajax.php)
    // 1) пробуем глобальную переменную
    // 2) пробуем $GLOBALS
    // 3) пробуем getDB()
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
        if (isset($GLOBALS['mysqli']) && ($GLOBALS['mysqli'] instanceof mysqli)) {
            $mysqli = $GLOBALS['mysqli'];
        } elseif (function_exists('getDB')) {
            $mysqli = getDB();
        }
    }
    // Соединение с БД получено.

	    // На всякий случай подхватываем game_data.php, если массив не загружен.
	    if (!isset($game_data) || !is_array($game_data)) {
	        $gd = __DIR__ . '/../../game_data.php';
	        if (file_exists($gd)) {
	            require_once $gd;
	        }
	    }
	    if (!isset($game_data) || !is_array($game_data)) {
	        $game_data = [];
	    }

    // $userData приходит из ajax.php (generatePageContent)
    if (!isset($userData) || empty($userData['id'])) {
        throw new RuntimeException('Нет данных пользователя', 403);
    }

    $uid = (int)$userData['id'];
    $view = $_GET['view'] ?? 'main';

    // В оригинальном CoC первый строитель бесплатный — делаем так же.
    if (function_exists('ensureDefaultBuilderHut')) {
        ensureDefaultBuilderHut($mysqli, $uid);
    }

// Всегда берём актуальные ресурсы/гемы из БД (а не из $userData),
// чтобы кнопки и расчёты не зависели от "устаревшего" состояния.
$stmtFresh = $mysqli->prepare("SELECT gold, elixir, dark_elixir, gems, builders_total FROM users WHERE id = ?");
if ($stmtFresh) {
    $stmtFresh->bind_param('i', $uid);
    if ($stmtFresh->execute()) {
        $resFresh = $stmtFresh->get_result();
        if ($resFresh) {
            $fresh = $resFresh->fetch_assoc();
            if ($fresh) {
                foreach (['gold','elixir','dark_elixir','gems','builders_total'] as $k) {
                    if (isset($fresh[$k])) $userData[$k] = $fresh[$k];
                }
            }
        }
    }
    $stmtFresh->close();
}

// Уровень Ратуши берём из player_buildings (в таблице users его нет).
$th = 1;
$stmtTH = $mysqli->prepare("SELECT level FROM player_buildings WHERE user_id = ? AND building_id = 'townhall' LIMIT 1");
if ($stmtTH) {
    $stmtTH->bind_param('i', $uid);
    if ($stmtTH->execute()) {
        $resTH = $stmtTH->get_result();
        if ($resTH) {
            $rowTH = $resTH->fetch_assoc();
            if ($rowTH && isset($rowTH['level'])) $th = max(1, (int)$rowTH['level']);
        }
    }
    $stmtTH->close();
}
$userData['townhall_lvl'] = $th;

    // Прайс-лист (2-5 строитель) — в гемах
    $builder_prices = [
        2 => 250,
        3 => 500,
        4 => 1000,
        5 => 2000,
    ];
    $max_builders = 5; // 6-й (B.O.B) можно добавить позже отдельной системой

    // Для фронта: дельта ресурса (чтобы анимация баланса срабатывала сразу)
    $delta_res = '';
    $delta_amt = 0;

    // --- Actions ---
if ($view === 'hire') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new RuntimeException('Неверный метод запроса', 405);
    }

    // Текущее число строителей берём из факта: сколько builder_hut реально стоит (getBuilderCounts)
    $countsNow = function_exists('getBuilderCounts') ? getBuilderCounts($mysqli, $uid) : ['total' => 1, 'busy' => 0, 'free' => 1];
    $totalNow = (int)($countsNow['total'] ?? 1);

    $next = $totalNow + 1;
    if ($next > $max_builders) {
        throw new RuntimeException('У вас уже максимальное количество строителей.', 400);
    }

    $cost = (int)($builder_prices[$next] ?? 0);
    if ($cost <= 0) {
        throw new RuntimeException('Цена найма не определена.', 500);
    }

    // Вставка новой хижины + списание гемов — строго в транзакции,
    // чтобы не ловить "купилось, но хижина не появилась" или наоборот.
    $mysqli->begin_transaction();

    try {
        // Лочим строку пользователя
        $stmtLock = $mysqli->prepare("SELECT gems, builders_total FROM users WHERE id = ? FOR UPDATE");
        if (!$stmtLock) throw new RuntimeException('DB: prepare lock failed', 500);
        $stmtLock->bind_param('i', $uid);
        $stmtLock->execute();
        $u = $stmtLock->get_result()->fetch_assoc();
        $stmtLock->close();

        $gemsNow = (int)($u['gems'] ?? 0);
        if ($gemsNow < $cost) {
            throw new RuntimeException('Не хватает гемов для найма строителя.', 400);
        }

        // Собираем занятые координаты (и лочим их)
        $used = [];
        $stmtXY = $mysqli->prepare("SELECT x, y FROM player_buildings WHERE user_id = ? FOR UPDATE");
        if (!$stmtXY) throw new RuntimeException('DB: prepare xy failed', 500);
        $stmtXY->bind_param('i', $uid);
        $stmtXY->execute();
        $resXY = $stmtXY->get_result();
        while ($r = $resXY->fetch_assoc()) {
            $x = (int)($r['x'] ?? 0);
            $y = (int)($r['y'] ?? 0);
            $used[$x . ':' . $y] = true;
        }
        $stmtXY->close();

        // Подбор свободного места
        $preferred = [
            [6, 6], [38, 6], [6, 38], [38, 38],
            [22, 6], [6, 22], [38, 22], [22, 38],
            [12, 12], [32, 12], [12, 32], [32, 32],
        ];
        $spot = null;
        foreach ($preferred as $p) {
            $k = $p[0] . ':' . $p[1];
            if (!isset($used[$k])) { $spot = ['x'=>(int)$p[0], 'y'=>(int)$p[1]]; break; }
        }
        if ($spot === null) {
            for ($yy=1; $yy<=44 && $spot===null; $yy++){
                for ($xx=1; $xx<=44; $xx++){
                    $k = $xx . ':' . $yy;
                    if (!isset($used[$k])) { $spot = ['x'=>$xx, 'y'=>$yy]; break; }
                }
            }
        }
        if ($spot === null) $spot = ['x'=>1, 'y'=>1];

        // Определяем реальные колонки
        $cols = [];
        $resCols = $mysqli->query("SHOW COLUMNS FROM player_buildings");
        if ($resCols) {
            while ($c = $resCols->fetch_assoc()) $cols[strtolower($c['Field'])] = true;
        }

        $insertCols = [];
        $placeholders = [];
        $types = '';
        $params = [];

        $add = function(string $col, string $type, $val) use (&$cols,&$insertCols,&$placeholders,&$types,&$params) {
            if (!isset($cols[strtolower($col)])) return;
            $insertCols[] = $col;
            $placeholders[] = '?';
            $types .= $type;
            $params[] = $val;
        };

        $add('user_id', 'i', $uid);
        $add('building_id', 's', 'builder_hut');
        $add('level', 'i', 1);
        $add('x', 'i', (int)$spot['x']);
        $add('y', 'i', (int)$spot['y']);
        $add('stored_resource', 'i', 0);
        $add('status', 's', 'active');
        $add('finish_time', 'i', 0);
        $add('last_collect', 'i', 0);

        if (!$insertCols) throw new RuntimeException('DB: player_buildings columns not found', 500);

        $sqlIns = "INSERT INTO player_buildings (`" . implode("`,`", $insertCols) . "`) VALUES (" . implode(",", $placeholders) . ")";
        $ins = $mysqli->prepare($sqlIns);
        if (!$ins) throw new RuntimeException('DB: prepare insert hut failed', 500);
        $bind = [];
        $bind[] = $types;
        for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
        @call_user_func_array([$ins,'bind_param'], $bind);
        if (!$ins->execute()) {
            $err = $ins->error ?: 'unknown';
            $ins->close();
            throw new RuntimeException('Не удалось поставить хижину строителя: ' . $err, 500);
        }
        $ins->close();

        // Списываем гемы и синхронизируем builders_total
        $stmtUpd = $mysqli->prepare("UPDATE users SET gems = gems - ?, builders_total = ? WHERE id = ? AND gems >= ?");
        if (!$stmtUpd) throw new RuntimeException('DB: prepare update user failed', 500);
        $stmtUpd->bind_param('iiii', $cost, $next, $uid, $cost);
        $stmtUpd->execute();
        $aff = $stmtUpd->affected_rows;
        $stmtUpd->close();
        if ($aff !== 1) {
            throw new RuntimeException('Не удалось списать гемы. Попробуйте ещё раз.', 500);
        }

        $mysqli->commit();

        // Дельта для анимации баланса (гемы списаны)
        $delta_res = 'gems';
        $delta_amt = -$cost;

        // Обновляем userData для payload
        $userData['gems'] = max(0, (int)$gemsNow - $cost);
        $userData['builders_total'] = $next;

    } catch (Throwable $te) {
        $mysqli->rollback();
        throw $te;
    }

    // После действия просто рисуем main
    $view = 'main';
}


    // --- Main view ---
    $counts = function_exists('getBuilderCounts') ? getBuilderCounts($mysqli, $uid) : ['total' => 1, 'busy' => 0, 'free' => 1];
    $total = (int)($counts['total'] ?? 1);
    $busy  = (int)($counts['busy'] ?? 0);
    $free  = (int)($counts['free'] ?? 1);

    $next_builder_num = $total + 1;
    $next_cost = ($next_builder_num <= $max_builders) ? (int)($builder_prices[$next_builder_num] ?? 0) : 0;

    // Очередь строек (занятые строители)
    $tasks = [];
    $now = time();
    $stmtT = $mysqli->prepare("SELECT id, building_id, level, status, finish_time FROM player_buildings WHERE user_id = ? AND status IN ('constructing','upgrading') AND finish_time > ? ORDER BY finish_time ASC");
    if ($stmtT) {
        $stmtT->bind_param('ii', $uid, $now);
        if ($stmtT->execute()) {
            $res = $stmtT->get_result();
            while ($row = $res->fetch_assoc()) {
                $tasks[] = $row;
            }
        }
        $stmtT->close();
    }

    // Пэйлоад для фронта: обновление баланса в верхней панели
    $th = (int)($userData['townhall_lvl'] ?? 1);
    $cap_gold = function_exists('getTotalStorageCapacity') ? getTotalStorageCapacity($uid, 'gold', $mysqli, $th) : 0;
    $cap_elixir = function_exists('getTotalStorageCapacity') ? getTotalStorageCapacity($uid, 'elixir', $mysqli, $th) : 0;
    $cap_dark = function_exists('getTotalStorageCapacity') ? getTotalStorageCapacity($uid, 'dark_elixir', $mysqli, $th) : 0;

    $modal_id = 'builder_hut-modal';
    ?>

    <div class="builderhut-main-view storage-detail-view">
        <div class="modal-header-controls">
            <h2 class="modal-title-text-inside-panel">ХИЖИНА СТРОИТЕЛЯ</h2>
            <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('<?= $modal_id ?>')">
                <img src="/images/icons/close.png" alt="Закрыть">
            </button>
        </div>

        <div class="modal-body-custom building-detail-content">

            <div class="js-balance-payload" style="display:none"
                data-gold="<?= (int)($userData['gold'] ?? 0) ?>"
                data-elixir="<?= (int)($userData['elixir'] ?? 0) ?>"
                data-dark_elixir="<?= (int)($userData['dark_elixir'] ?? 0) ?>"
                data-gems="<?= (int)($userData['gems'] ?? 0) ?>"
                data-cap_gold="<?= (int)$cap_gold ?>"
                data-cap_elixir="<?= (int)$cap_elixir ?>"
                data-cap_dark_elixir="<?= (int)$cap_dark ?>"
                data-delta_res="<?= htmlspecialchars($delta_res) ?>"
                data-delta_amt="<?= (int)$delta_amt ?>"
            ></div>

            <div class="info-box" style="margin-bottom: 12px;">
                <h3>👷 Строители</h3>
                <div style="display:flex; gap:10px; justify-content:space-between; align-items:stretch;">
                    <div style="flex:1; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 10px; text-align:center;">
                        <div style="font-size:12px; opacity:.85;">Всего</div>
                        <div class="text-primary" style="font-size:18px; font-weight:800; line-height:1.1;"><?= $total ?></div>
                    </div>
                    <div style="flex:1; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 10px; text-align:center;">
                        <div style="font-size:12px; opacity:.85;">Занято</div>
                        <div class="text-primary" style="font-size:18px; font-weight:800; line-height:1.1;"><?= $busy ?></div>
                    </div>
                    <div style="flex:1; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 10px; text-align:center;">
                        <div style="font-size:12px; opacity:.85;">Свободно</div>
                        <div class="text-primary" style="font-size:18px; font-weight:800; line-height:1.1;"><?= $free ?></div>
                    </div>
                </div>
            </div>

            <div class="info-box" style="margin-bottom: 12px;">
                <h3>📋 Текущие работы</h3>
                <?php if (empty($tasks)): ?>
                    <p style="margin:0; opacity:.85;">Сейчас ничего не строится — все строители свободны.</p>
                <?php else: ?>
                    <?php foreach ($tasks as $t):
                        $bid = $t['building_id'] ?? '';
                        $lvl = (int)($t['level'] ?? 1);
                        $name = $game_data[$bid]['name'] ?? $bid;
                        $left = max(0, (int)($t['finish_time'] ?? 0) - time());
                        $st = ($t['status'] === 'constructing') ? 'Строится' : 'Улучшается';
                    ?>
                        <div class="stat-row" style="align-items:flex-start;">
                            <span style="max-width: 65%;">
                                🔨 <b><?= htmlspecialchars($name) ?></b> (Ур. <?= $lvl ?>)
                                <div style="font-size: 12px; opacity: .85;"><?= $st ?></div>
                            </span>
                            <span class="text-primary js-countdown" data-finish="<?= (int)($t['finish_time'] ?? 0) ?>"><?= builderhut_time_display($left) ?></span>
                            <?php if ($left > 0): $spdCost = army_gem_cost_for_seconds((int)$left); ?>
                              <button class="coc-speedup-small" type="button"
                                data-buildspeedup-id="<?= (int)($t['id'] ?? 0) ?>"
                                data-buildspeedup-cost="<?= (int)$spdCost ?>">
                                <img src="/images/icons/gems.png" alt="" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;">
                                <b><?= (int)$spdCost ?></b>
                              </button>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="info-box">
                <h3>➕ Найм нового строителя</h3>
                <?php if ($total >= $max_builders): ?>
                    <p style="margin:0;">✅ У вас уже максимум строителей (<?= (int)$max_builders ?>).</p>
                    <p style="margin:6px 0 0; font-size: 12px; opacity:.85;">Дальше можно добавить 6-го строителя отдельной механикой (B.O.B) — сделаем позже.</p>
                <?php else: ?>
                    <p style="margin:0 0 10px;">
                        При найме появляется новая хижина, а значит — <b>+1 строитель</b>.
                    </p>
                    <div class="stat-row">
                        <span>Следующий строитель (#<?= (int)$next_builder_num ?>)</span>
                        <span class="text-primary"><?= number_format($next_cost, 0, '', ' ') ?> <img src="/images/icons/gems.png" width="14" height="14" style="vertical-align:-2px;"></span>
                    </div>
                    <button class="btn btn-block action-btn btn-upgrade" onclick="builderHutHireBuilder()" <?= (($userData['gems'] ?? 0) >= $next_cost) ? '' : 'disabled' ?>>
                        Нанять строителя
                    </button>
                    <?php if (($userData['gems'] ?? 0) < $next_cost): ?>
                        <div style="margin-top:8px; font-size: 12px; color: #b00020;">Не хватает гемов.</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <?php

    // Успешно — отдаём весь буфер.
    echo ob_get_clean();

} catch (Throwable $e) {
    // Если что-то уже успело записаться в буфер — очищаем, чтобы не ломать разметку.
    if (isset($__bh_start_ob_level)) {
        while (ob_get_level() > $__bh_start_ob_level) {
            @ob_end_clean();
        }
    }
    http_response_code((int)($e->getCode() ?: 500));
    $_code = (int)($e->getCode() ?: 500);
    $msg = ((($_code >= 400) && ($_code < 500)) ? $e->getMessage() : ((defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? ($e->getMessage().' on line '.$e->getLine()) : 'Внутренняя ошибка сервера.'));
    echo '<div class="modal-content">'
       . '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'builder_hut-modal\')"><img src="/images/icons/close.png" alt="Закрыть"></button>'
       . '<div class="error" style="margin: 20px;">❌ Ошибка: '.htmlspecialchars($msg).'</div>'
       . '</div>';
}


