<?php
/**
 * app/section_router.php
 * AJAX роутер для разделов деревни (кроме Storage/Production).
 */

require_once __DIR__ . '/../system/function.php';
require_once __DIR__ . '/section_views.php';

if (!isLoggedIn()) {
    echo '<div class="modal-content"><div class="modal-body">Требуется вход.</div></div>';
    exit;
}

$user = getUser($mysqli);
if (!$user) {
    echo '<div class="modal-content"><div class="modal-body">Ошибка пользователя.</div></div>';
    exit;
}

$section = $_GET['section'] ?? '';
$view    = $_GET['view'] ?? 'main';
$bid     = $_GET['building_id'] ?? '';
$rowId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$user_id = (int)$user['id'];
$th_lvl  = (int)($user['townhall_lvl'] ?? 1);

$sectionMap = [
    'townhall' => ['title' => 'Ратуша', 'items' => ['townhall']],
    'barracks' => ['title' => 'Армия',  'items' => ['barracks', 'army_camp']],
    'defense'  => ['title' => 'Оборона', 'items' => ['cannon','archer_tower','mortar','air_defense','wizard_tower']],
    'clan'     => ['title' => 'Клановая крепость', 'items' => ['clan_castle']],
];

if (!isset($sectionMap[$section])) {
    echo '<div class="modal-content"><div class="modal-body">Неизвестный раздел.</div></div>';
    exit;
}

$modalId = $section . '-modal';

function resConstToColumn(string $res): string {
    // RES_* константы определены в game_data.php
    if ($res === RES_GOLD) return 'gold';
    if ($res === RES_ELIXIR) return 'elixir';
    if ($res === RES_DARK) return 'dark_elixir';
    if ($res === RES_GEMS) return 'gems';
    return '';
}

function spendResource(mysqli $mysqli, int $user_id, string $col, int $amount): bool {
    if ($amount <= 0) return true;
    if (!in_array($col, ['gold','elixir','dark_elixir','gems'], true)) return false;

    // atomic check+update
    $sql = "UPDATE users SET `$col` = `$col` - ? WHERE id=? AND `$col` >= ?";
    $st = $mysqli->prepare($sql);
    if (!$st) return false;
    $st->bind_param("iii", $amount, $user_id, $amount);
    $st->execute();
    $ok = ($st->affected_rows > 0);
    $st->close();
    return $ok;
}

// POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrfAjax() && !verifyCsrfPost()) {
        json_out(['ok' => false, 'error' => 'CSRF'], 403);
    }

    $action = $_POST['action'] ?? '';
    if ($action === 'buy') {
        $building_id = (string)($_POST['building_id'] ?? '');
        $info = getObjectInfo($building_id);
        if (!$info) json_out(['ok'=>false,'error'=>'building'], 400);

        $max = getMaxCountForTH($building_id, $th_lvl);
        $builtCount = count(getPlayerBuildingsByType($mysqli, $user_id, $building_id));
        if ($max > 0 && $builtCount >= $max) json_out(['ok'=>false,'error'=>'limit'], 400);

        $lvl1 = $info['levels'][1] ?? null;
        if (!$lvl1) json_out(['ok'=>false,'error'=>'lvl1'], 400);

        $th_req = (int)($lvl1['th_req'] ?? 1);
        if ($th_lvl < $th_req) json_out(['ok'=>false,'error'=>'th_req'], 400);

        $cost = (int)($lvl1['cost'] ?? 0);
        $res  = (string)($lvl1['res_type'] ?? '');
        $col  = resConstToColumn($res);
        if ($col === '') json_out(['ok'=>false,'error'=>'res'], 400);

        if (!spendResource($mysqli, $user_id, $col, $cost)) json_out(['ok'=>false,'error'=>'no_money'], 400);

        // build instantly if time=0 else constructing
        $time = (int)($lvl1['time'] ?? 0);
        $status = ($time > 0) ? 'constructing' : 'active';
        $finish = ($time > 0) ? (time() + $time) : null;

        $st = $mysqli->prepare("INSERT INTO player_buildings (user_id, building_id, level, x, y, status, finish_time, stored_resource) VALUES (?,?,?,?,?,?,?,0)");
        if (!$st) json_out(['ok'=>false,'error'=>'db'], 500);
        $level = 1; $x=0; $y=0;
        $st->bind_param("isiiisi", $user_id, $building_id, $level, $x, $y, $status, $finish);
        $st->execute();
        $st->close();

        json_out(['ok'=>true]);
    }

    if ($action === 'upgrade') {
        $id = (int)($_POST['id'] ?? 0);
        $row = getPlayerBuildingByRowId($mysqli, $user_id, $id);
        if (!$row) json_out(['ok'=>false,'error'=>'not_found'], 404);
        if ($row['status'] !== 'active') json_out(['ok'=>false,'error'=>'busy'], 400);

        $building_id = $row['building_id'];
        $info = getObjectInfo($building_id);
        if (!$info) json_out(['ok'=>false,'error'=>'building'], 400);

        $next = (int)$row['level'] + 1;
        $lvlInfo = $info['levels'][$next] ?? null;
        if (!$lvlInfo) json_out(['ok'=>false,'error'=>'max_level'], 400);

        $th_req = (int)($lvlInfo['th_req'] ?? 1);
        if ($th_lvl < $th_req) json_out(['ok'=>false,'error'=>'th_req'], 400);

        $cost = (int)($lvlInfo['cost'] ?? 0);
        $res  = (string)($lvlInfo['res_type'] ?? '');
        $col  = resConstToColumn($res);
        if ($col === '') json_out(['ok'=>false,'error'=>'res'], 400);

        if (!spendResource($mysqli, $user_id, $col, $cost)) json_out(['ok'=>false,'error'=>'no_money'], 400);

        $time = (int)($lvlInfo['time'] ?? 0);
        $finish = time() + max(1, $time);

        $st = $mysqli->prepare("UPDATE player_buildings SET status='upgrading', finish_time=? WHERE id=? AND user_id=? AND status='active'");
        if (!$st) json_out(['ok'=>false,'error'=>'db'], 500);
        $st->bind_param("iii", $finish, $id, $user_id);
        $st->execute();
        $ok = ($st->affected_rows > 0);
        $st->close();

        if (!$ok) json_out(['ok'=>false,'error'=>'race'], 409);
        json_out(['ok'=>true]);
    }

    json_out(['ok'=>false,'error'=>'unknown_action'], 400);
}

// Views
$cfg = $sectionMap[$section];

if ($view === 'main') {
    renderSectionMain([
        'section' => $section,
        'modalId' => $modalId,
        'title'   => $cfg['title'],
        'items'   => $cfg['items'],
    ]);
    exit;
}

if ($view === 'list') {
    $building_id = (string)$bid;
    $info = getObjectInfo($building_id);
    if (!$info) { echo '<div class="modal-content"><div class="modal-body">Здание не найдено.</div></div>'; exit; }

    $lvl1 = $info['levels'][1] ?? [];
    $th_req = (int)($lvl1['th_req'] ?? 1);

    $built = getPlayerBuildingsByType($mysqli, $user_id, $building_id);
    $max = getMaxCountForTH($building_id, $th_lvl);
    if ($max <= 0) $max = 1;

    renderSectionList([
        'section' => $section,
        'modalId' => $modalId,
        'building_id' => $building_id,
        'title'   => (string)($info['name'] ?? $building_id),
        'townhall_lvl' => $th_lvl,
        'built'   => $built,
        'max'     => $max,
        'canBuild'=> ($th_lvl >= $th_req),
    ]);
    exit;
}

if ($view === 'detail') {
    $row = getPlayerBuildingByRowId($mysqli, $user_id, $rowId);
    if (!$row) { echo '<div class="modal-content"><div class="modal-body">Не найдено.</div></div>'; exit; }

    $info = getObjectInfo($row['building_id']);
    if (!$info) { echo '<div class="modal-content"><div class="modal-body">Здание не найдено.</div></div>'; exit; }

    $lvl = (int)$row['level'];
    $lvlInfo = $info['levels'][$lvl] ?? [];

    $next = $lvl + 1;
    $nextInfo = $info['levels'][$next] ?? null;
    $canUpgrade = false; $cost=0; $resName=''; $time=0;
    if ($row['status'] === 'active' && $nextInfo) {
        $th_req = (int)($nextInfo['th_req'] ?? 1);
        $cost = (int)($nextInfo['cost'] ?? 0);
        $res  = (string)($nextInfo['res_type'] ?? '');
        $col  = resConstToColumn($res);
        $time = (int)($nextInfo['time'] ?? 0);
        $resName = $col;
        if ($col && $th_lvl >= $th_req) {
            // не проверяем деньги здесь (просто кнопка), проверим в POST
            $canUpgrade = true;
        }
    }

    renderSectionDetail([
        'section' => $section,
        'modalId' => $modalId,
        'row'     => $row,
        'info'    => $info,
        'lvlInfo' => $lvlInfo,
        'canUpgrade' => $canUpgrade,
        'upgradeCost' => $cost,
        'upgradeRes'  => $resName,
        'upgradeTime' => $time,
    ]);
    exit;
}

echo '<div class="modal-content"><div class="modal-body">Неизвестный view.</div></div>';
