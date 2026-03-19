<?php
// nick_gen.php — генерация уникального логина для формы регистрации
// Возвращает JSON: {ok:true, login:"..."}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/system/function.php';

function json_out($arr, $code = 200){
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($mysqli) || !($mysqli instanceof mysqli)) {
    json_out(['ok'=>false,'error'=>'DB not initialized'], 500);
}

function sanitize_login_token($s) {
    $s = (string)$s;
    $s = preg_replace('/\s+/u', '_', trim($s));
    $s = preg_replace('/[^a-zA-Z0-9_\x{0400}-\x{04FF}]/u', '', $s);
    return $s;
}

function gen_funny_login(mysqli $mysqli) {
    $a = [
        'Кот','Лис','Волк','Орёл','Панда','Дракон','Титан','Гном','Рыцарь','Маг',
        'Builder','Wizard','Archer','Knight','Dragon','Goblin','Viking','Ninja','Panda','Tiger'
    ];
    $b = [
        'Марса','Снега','Тумана','Шторма','Огня','Льда','Золота','Эликсира','Легенд','Рубинов',
        'ofMars','ofIce','ofGold','ofElixir','Storm','Frost','Fire','Shadow','Nova','Rocket'
    ];

    for ($i=0;$i<50;$i++) {
        $p1 = $a[random_int(0, count($a)-1)];
        $p2 = $b[random_int(0, count($b)-1)];
        $nick = sanitize_login_token($p1 . '_' . $p2);

        $len = function_exists('mb_strlen') ? mb_strlen($nick, 'UTF-8') : strlen($nick);
        if ($len < 3) continue;
        if ($len > 20) $nick = function_exists('mb_substr') ? mb_substr($nick, 0, 20, 'UTF-8') : substr($nick, 0, 20);

        $stmt = $mysqli->prepare('SELECT id FROM users WHERE login=? LIMIT 1');
        $stmt->bind_param('s', $nick);
        if ($stmt->execute()) {
            $r = $stmt->get_result();
            if (!$r || !$r->fetch_assoc()) return $nick;
        }

        $suffix = (string)random_int(0, 999);
        $max = 20 - (function_exists('mb_strlen') ? mb_strlen($suffix, 'UTF-8') : strlen($suffix));
        if ($max < 3) $max = 3;
        $nick2 = function_exists('mb_substr') ? mb_substr($nick, 0, $max, 'UTF-8') : substr($nick, 0, $max);
        $nick2 = sanitize_login_token($nick2 . $suffix);

        $stmt2 = $mysqli->prepare('SELECT id FROM users WHERE login=? LIMIT 1');
        $stmt2->bind_param('s', $nick2);
        if ($stmt2->execute()) {
            $r2 = $stmt2->get_result();
            if (!$r2 || !$r2->fetch_assoc()) return $nick2;
        }
    }
    return 'Player_' . random_int(1000, 9999);
}

$login = gen_funny_login($mysqli);
json_out(['ok'=>true,'login'=>$login]);
