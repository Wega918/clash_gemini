<?php
/**
 * Batch parser for Clash of Clans Wiki (Fandom) using MediaWiki API.
 * Runs in small batches to avoid timeouts on shared hosting.
 *
 * Usage in browser:
 *   /tools/wiki_parser.php?mode=list
 *   /tools/wiki_parser.php?mode=parse&start=0&count=5
 *
 * Output:
 *   /tools/troops_wiki.json  (accumulates data)
 *   /tools/debug_failed.json
 */

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$API = 'https://clashofclans.fandom.com/api.php';
$OUT_JSON = __DIR__ . '/troops_wiki.json';
$FAILED_JSON = __DIR__ . '/debug_failed.json';

$mode  = $_GET['mode']  ?? 'list';
$start = isset($_GET['start']) ? max(0, (int)$_GET['start']) : 0;
$count = isset($_GET['count']) ? max(1, min(20, (int)$_GET['count'])) : 5;

// --- helpers ---
function http_get_json(string $url, array $params): array {
    $qs = http_build_query($params);
    $full = $url . (str_contains($url, '?') ? '&' : '?') . $qs;

    $ch = curl_init($full);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'CoCStatsParserPHP/1.0',
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        throw new RuntimeException("HTTP error $code: $err");
    }

    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException("Bad JSON response");
    }
    return $data;
}

function load_json_file(string $path, array $default): array {
    if (!file_exists($path)) return $default;
    $s = file_get_contents($path);
    if ($s === false) return $default;
    $j = json_decode($s, true);
    return is_array($j) ? $j : $default;
}

function save_json_file(string $path, array $data): void {
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function norm(string $s): string {
    $s = trim(mb_strtolower($s));
    $s = preg_replace('/\s+/u', ' ', $s);
    $s = str_replace("\xc2\xa0", ' ', $s); // nbsp
    $s = trim($s, " :\t\n\r\0\x0B");
    return $s;
}

function parse_int_like(string $s): ?int {
    $s = trim($s);
    if ($s === '' || $s === '—' || $s === '-' ) return null;
    $digits = preg_replace('/[^\d]/', '', $s);
    if ($digits === '') return null;
    return (int)$digits;
}

function parse_time_to_seconds(string $s): ?int {
    $s = norm($s);
    if ($s === '' || $s === '—' || $s === '-' ) return null;

    $total = 0; $found = false;
    if (preg_match_all('/(\d+)\s*(d|day|days|h|hr|hrs|hour|hours|m|min|mins|minute|minutes|s|sec|secs|second|seconds)/u', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $n = (int)$x[1];
            $u = $x[2];
            $found = true;
            if (str_starts_with($u, 'd')) $total += $n * 86400;
            elseif (str_starts_with($u, 'h') || str_starts_with($u, 'hr') || str_starts_with($u, 'hou')) $total += $n * 3600;
            elseif (str_starts_with($u, 'm') || str_starts_with($u, 'min')) $total += $n * 60;
            else $total += $n;
        }
        return $total;
    }
    return null;
}

function extract_res_type(string $s): ?string {
    $t = norm($s);
    if (str_contains($t, 'dark elixir')) return 'dark_elixir';
    if (str_contains($t, 'elixir')) return 'elixir';
    if (str_contains($t, 'gold')) return 'gold';
    return null;
}

$HEADER_MAP = [
    'level' => 'level',
    'damage per second' => 'damage_per_second',
    'dps' => 'damage_per_second',
    'damage per attack' => 'damage_per_attack',
    'damage per hit' => 'damage_per_attack',
    'damage' => 'damage_per_attack',
    'hitpoints' => 'health',
    'hp' => 'health',
    'health' => 'health',
    'training cost' => 'cost',
    'cost' => 'cost',
    'training time' => 'time',
    'time' => 'time',
    'laboratory level required' => 'lab_req',
    'laboratory level' => 'lab_req',
    'laboratory' => 'lab_req',
    'town hall level required' => 'th_req',
    'town hall level' => 'th_req',
    'town hall' => 'th_req',
];

function map_headers(array $raw, array $HEADER_MAP): array {
    $mapped = [];
    foreach ($raw as $h) {
        $nh = norm($h);
        if (isset($HEADER_MAP[$nh])) { $mapped[] = $HEADER_MAP[$nh]; continue; }
        if (str_contains($nh, 'damage per second')) $mapped[] = 'damage_per_second';
        elseif (str_contains($nh, 'hitpoints') || $nh === 'hp') $mapped[] = 'health';
        elseif (str_contains($nh, 'training cost')) $mapped[] = 'cost';
        elseif (str_contains($nh, 'training time')) $mapped[] = 'time';
        elseif (str_contains($nh, 'laboratory')) $mapped[] = 'lab_req';
        elseif (str_contains($nh, 'town hall')) $mapped[] = 'th_req';
        elseif (str_contains($nh, 'level')) $mapped[] = 'level';
        else $mapped[] = null;
    }
    return $mapped;
}

function find_upgrade_table(DOMDocument $dom): ?DOMElement {
    $xpath = new DOMXPath($dom);
    $tables = $xpath->query('//table');
    if (!$tables) return null;

    $best = null; $bestScore = 0;

    foreach ($tables as $tbl) {
        $firstRow = $xpath->query('.//tr[1]', $tbl)->item(0);
        if (!$firstRow) continue;

        $cells = $xpath->query('.//th|.//td', $firstRow);
        if (!$cells || $cells->length === 0) continue;

        $headers = [];
        foreach ($cells as $c) $headers[] = norm($c->textContent);

        $hasLevel = false;
        foreach ($headers as $h) if ($h === 'level' || str_contains($h, 'level')) { $hasLevel = true; break; }
        if (!$hasLevel) continue;

        $score = 0;
        foreach ($headers as $h) {
            if (str_contains($h, 'hitpoints') || $h === 'hp') $score += 3;
            if (str_contains($h, 'damage per second') || $h === 'dps') $score += 3;
            if (str_contains($h, 'training cost') || $h === 'cost') $score += 2;
            if (str_contains($h, 'laboratory')) $score += 2;
            if (str_contains($h, 'training time') || $h === 'time') $score += 1;
        }

        $rows = $xpath->query('.//tr', $tbl);
        if ($rows && $rows->length < 3) $score -= 2;

        if ($score > $bestScore) { $bestScore = $score; $best = $tbl; }
    }

    return $best;
}

function parse_upgrade_table(DOMDocument $dom, DOMElement $tbl, array $HEADER_MAP): array {
    $xpath = new DOMXPath($dom);

    $rows = $xpath->query('.//tr', $tbl);
    if (!$rows || $rows->length < 2) return [[], ['res_type'=>null, 'headers'=>[]]];

    // headers
    $headerCells = $xpath->query('.//th|.//td', $rows->item(0));
    $rawHeaders = [];
    foreach ($headerCells as $c) $rawHeaders[] = trim($c->textContent);

    $mapped = map_headers($rawHeaders, $HEADER_MAP);

    $levels = [];
    $resType = null;

    for ($i=1; $i<$rows->length; $i++) {
        $cells = $xpath->query('.//th|.//td', $rows->item($i));
        if (!$cells || $cells->length === 0) continue;

        $vals = [];
        foreach ($cells as $c) $vals[] = trim(preg_replace('/\s+/u', ' ', $c->textContent));

        // pad
        while (count($vals) < count($mapped)) $vals[] = '';

        $obj = [];
        $level = null;

        for ($k=0; $k<count($mapped); $k++) {
            $key = $mapped[$k];
            if ($key === null) continue;
            $val = $vals[$k];

            if ($key === 'level') { $level = parse_int_like($val); continue; }

            if (in_array($key, ['damage_per_second','damage_per_attack','health','lab_req','th_req'], true)) {
                $obj[$key] = parse_int_like($val);
                continue;
            }

            if ($key === 'cost') {
                $obj['cost'] = parse_int_like($val);
                $rt = extract_res_type($val);
                if ($rt) $resType = $rt;
                continue;
            }

            if ($key === 'time') {
                $secs = parse_time_to_seconds($val);
                $obj['time'] = $secs ?? ($val !== '' ? $val : null);
                continue;
            }
        }

        if ($level === null) continue;
        $levels[(string)$level] = $obj;
    }

    return [$levels, ['res_type'=>$resType, 'headers'=>$rawHeaders]];
}

function fetch_page_html(string $API, string $title): string {
    $data = http_get_json($API, [
        'action' => 'parse',
        'format' => 'json',
        'page' => $title,
        'prop' => 'text',
        'formatversion' => 2,
    ]);
    $html = $data['parse']['text'] ?? '';
    if (!is_string($html) || trim($html) === '') {
        throw new RuntimeException("No HTML for: $title");
    }
    return $html;
}

function category_members(string $API, string $catTitle): array {
    $titles = [];
    $cmcontinue = null;

    while (true) {
        $params = [
            'action' => 'query',
            'format' => 'json',
            'list' => 'categorymembers',
            'cmtitle' => $catTitle,
            'cmlimit' => 500,
            'cmnamespace' => 0,
        ];
        if ($cmcontinue) $params['cmcontinue'] = $cmcontinue;

        $data = http_get_json($API, $params);
        $members = $data['query']['categorymembers'] ?? [];
        foreach ($members as $m) {
            if (!empty($m['title'])) $titles[] = trim((string)$m['title']);
        }

        $cmcontinue = $data['continue']['cmcontinue'] ?? null;
        if (!$cmcontinue) break;
    }

    $titles = array_values(array_unique($titles));
    sort($titles, SORT_NATURAL | SORT_FLAG_CASE);
    return $titles;
}

// --- main ---
$troopCats = ['Category:Troops', 'Category:Elixir Troops'];
$darkCats  = ['Category:Dark Elixir Troops', 'Category:Dark troops'];

if ($mode === 'list') {
    $troops = [];
    $dark = [];

    foreach ($troopCats as $c) {
        try { $troops = array_merge($troops, category_members($API, $c)); } catch (\Throwable $e) {}
    }
    foreach ($darkCats as $c) {
        try { $dark = array_merge($dark, category_members($API, $c)); } catch (\Throwable $e) {}
    }

    $troops = array_values(array_unique($troops));
    $dark   = array_values(array_unique($dark));

    echo "Troops pages: " . count($troops) . PHP_EOL;
    echo "Dark troops pages: " . count($dark) . PHP_EOL;

    echo PHP_EOL . "Next step: run parse batches, e.g.:" . PHP_EOL;
    echo "  ?mode=parse&start=0&count=5" . PHP_EOL;

    exit;
}

if ($mode === 'parse') {
    // build list
    $troops = [];
    $dark = [];

    foreach ($troopCats as $c) { try { $troops = array_merge($troops, category_members($API, $c)); } catch (\Throwable $e) {} }
    foreach ($darkCats as $c)  { try { $dark  = array_merge($dark, category_members($API, $c)); } catch (\Throwable $e) {} }

    $troops = array_values(array_unique($troops));
    $dark   = array_values(array_unique($dark));

    // merge, keeping "dark" flag if appears there
    $flag = [];
    foreach ($troops as $t) $flag[$t] = false;
    foreach ($dark as $t) $flag[$t] = true;

    $pages = array_keys($flag);
    sort($pages, SORT_NATURAL | SORT_FLAG_CASE);

    $total = count($pages);
    $end = min($total, $start + $count);

    $db = load_json_file($OUT_JSON, [
        'source' => 'clashofclans.fandom.com (MediaWiki API parse)',
        'generated_at_unix' => time(),
        'units' => [],
    ]);
    $failed = load_json_file($FAILED_JSON, []);

    echo "Total pages: $total" . PHP_EOL;
    echo "Batch: $start .. " . ($end-1) . PHP_EOL;

    for ($i=$start; $i<$end; $i++) {
        $title = $pages[$i];
        $isDark = $flag[$title] ?? false;

        echo "[" . ($i+1) . "/$total] $title ... ";

        try {
            $html = fetch_page_html($API, $title);

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
            libxml_clear_errors();

            $tbl = find_upgrade_table($dom);
            if (!$tbl) {
                $failed[] = ['title'=>$title, 'reason'=>'upgrade table not found'];
                echo "FAILED (no table)" . PHP_EOL;
                continue;
            }

            [$levels, $meta] = parse_upgrade_table($dom, $tbl, $HEADER_MAP);
            if (!$levels) {
                $failed[] = ['title'=>$title, 'reason'=>'table parsed but empty'];
                echo "FAILED (empty levels)" . PHP_EOL;
                continue;
            }

            $type = $isDark ? 'dark_troop' : 'troop';
            $resType = $meta['res_type'] ?? null;
            if (!$resType) $resType = $isDark ? 'dark_elixir' : 'elixir';

            $db['units'][$title] = [
                'type' => $type,
                'res_type' => $resType,
                'levels' => $levels,
                'meta' => ['table_headers' => $meta['headers'] ?? []],
            ];

            echo "OK (" . count($levels) . " levels)" . PHP_EOL;

        } catch (\Throwable $e) {
            $failed[] = ['title'=>$title, 'reason'=>'exception: ' . $e->getMessage()];
            echo "FAILED (exception)" . PHP_EOL;
        }

        // чуть-чуть пауза чтобы не долбить API
        usleep(200000);
    }

    $db['generated_at_unix'] = time();
    save_json_file($OUT_JSON, $db);
    save_json_file($FAILED_JSON, $failed);

    echo PHP_EOL . "Saved: " . $OUT_JSON . PHP_EOL;
    echo "Failed list: " . $FAILED_JSON . PHP_EOL;

    if ($end < $total) {
        echo PHP_EOL . "Next batch:" . PHP_EOL;
        echo "  ?mode=parse&start=$end&count=$count" . PHP_EOL;
    } else {
        echo PHP_EOL . "DONE! Parsed units: " . count($db['units']) . PHP_EOL;
    }

    exit;
}

echo "Unknown mode. Use ?mode=list or ?mode=parse" . PHP_EOL;
