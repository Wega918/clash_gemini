<?php
/**
 * game_data.php
 * ПОЛНАЯ БАЗА ДАННЫХ CLASH OF CLANS
 */

// КРИТИЧЕСКОЕ ИСПРАВЛЕНИЕ: Оборачиваем константы в if (!defined) для предотвращения ошибок при множественной загрузке.
// Константы ресурсов
if (!defined('RES_GOLD')) define('RES_GOLD', 'gold');
if (!defined('RES_ELIXIR')) define('RES_ELIXIR', 'elixir');
if (!defined('RES_DARK')) define('RES_DARK', 'dark_elixir');
if (!defined('RES_GEMS')) define('RES_GEMS', 'gems');
if (!defined('RES_SHINY')) define('RES_SHINY', 'shiny_ore');
if (!defined('RES_GLOWY')) define('RES_GLOWY', 'glowy_ore');
if (!defined('RES_STARRY')) define('RES_STARRY', 'starry_ore');

// Константы типов
if (!defined('TYPE_TOWNHALL')) define('TYPE_TOWNHALL', 'townhall');
if (!defined('TYPE_RESOURCE')) define('TYPE_RESOURCE', 'resource');
if (!defined('TYPE_STORAGE')) define('TYPE_STORAGE', 'storage');
if (!defined('TYPE_ARMY')) define('TYPE_ARMY', 'army');
if (!defined('TYPE_DEFENSE')) define('TYPE_DEFENSE', 'defense');
if (!defined('TYPE_WALL')) define('TYPE_WALL', 'wall');
if (!defined('TYPE_TRAP')) define('TYPE_TRAP', 'trap');
if (!defined('TYPE_HERO_ALTAR')) define('TYPE_HERO_ALTAR', 'hero_altar');
if (!defined('TYPE_TROOP')) define('TYPE_TROOP', 'troop');
if (!defined('TYPE_DARK_TROOP')) define('TYPE_DARK_TROOP', 'dark_troop');
if (!defined('TYPE_SUPER_TROOP')) define('TYPE_SUPER_TROOP', 'super_troop');
if (!defined('TYPE_SPELL')) define('TYPE_SPELL', 'spell');
if (!defined('TYPE_DARK_SPELL')) define('TYPE_DARK_SPELL', 'dark_spell');
if (!defined('TYPE_HERO')) define('TYPE_HERO', 'hero');
if (!defined('TYPE_SIEGE')) define('TYPE_SIEGE', 'siege');
if (!defined('TYPE_PET')) define('TYPE_PET', 'pet');
if (!defined('TYPE_EQUIPMENT')) define('TYPE_EQUIPMENT', 'equipment');






// Данные вынесены в отдельные файлы для удобства поддержки.
$game_data = array_merge(
    require __DIR__ . '/game_data_buildings.php',
    require __DIR__ . '/game_data_entities.php'
);

// Заклинания поддерживаем в отдельном файле, чтобы карточки "готовить заклинания"
// соответствовали реальным параметрам игры. Этот массив намеренно ПЕРЕОПРЕДЕЛЯЕТ
// заклинания из game_data_entities.php.
$spellsFile = __DIR__ . '/game_data_spells.php';
if (file_exists($spellsFile)) {
    $spell_data = require $spellsFile;
    if (is_array($spell_data)) {
        $game_data = array_replace($game_data, $spell_data);
    }
}
