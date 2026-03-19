<?php
// ajax.php

// 1. Загрузка основных функций (содержит getUser)
require_once 'system/function.php';

// 2. Загрузка данных игры. 
require_once 'system/game_data.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


// Проверка необходимых функций и переменных
if (!function_exists('isLoggedIn')) {
    die(json_encode(['error' => 'Функция isLoggedIn не определена']));
}
if (!function_exists('getUser')) {
    die(json_encode(['error' => 'Функция getUser не определена']));
}
if (!isset($mysqli)) {
    die(json_encode(['error' => '$mysqli не определена']));
}

// Гарантируем CSRF токен + время (в system/function.php сессия уже поднята)
generateCsrfToken();

try {
    // Валидация страницы ДО проверки CSRF для условного пропуска
    // Laboratory полностью удалён из игры
    $allowedPages = ['home','buildings','army','storage','production','townhall','barracks','defense','clan','builder_hut','battle','balance']; 
    $page = $_GET['page'] ?? 'home';
    if (!in_array($page, $allowedPages)) {
        $page = 'home';
    }
    
    // Проверка метода запроса и CSRF токена
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !check_csrf($_POST['csrf_token'] ?? '')) {
        throw new RuntimeException('Недействительный CSRF токен', 403);
    }
    
    // Важно: контентные GET-запросы (страницы/модалки) не валим по CSRF,
    // иначе игрок видит пустые окна и "Недействительный CSRF токен (AJAX)".


    // Проверка авторизации
    if (!isLoggedIn()) {
        throw new RuntimeException('Требуется авторизация', 401);
    }

    // Получение данных пользователя (с обновлением ресурсов)
    $user = getUser($mysqli);
    if (empty($user['id'])) {
        throw new RuntimeException('Данные пользователя недействительны', 403);
    }

    // Подготовка данных
    $userData = [
        'id' => toInt($user['id'] ?? 0), 
        'login' => cleanString($user['login'] ?? 'Гость'),
        'gold' => toInt($user['gold'] ?? 0),
        'elixir' => toInt($user['elixir'] ?? 0),
        'dark_elixir' => toInt($user['dark_elixir'] ?? 0),
        'gems' => toInt($user['gems'] ?? 0),
        'townhall_lvl' => toInt($user['townhall_lvl'] ?? 1, 1, 20),
        'csrf_token' => $_SESSION['csrf_token']
    ];

    // Генерация контента
    $content = generatePageContent($page, $userData);

    // Отправка CSRF токена в заголовке
    header('X-CSRF-Token: ' . $_SESSION['csrf_token']);
    echo $content;

} catch (Throwable $e) {
    handleError($e, true); // Включаем AJAX-режим
}

/**
 * Генерирует HTML-содержимое страницы
 */
function generatePageContent(string $page, array $userData): string {
    global $mysqli;
    ob_start();
    
    $season = getActiveSeason($mysqli);

    
	
	?>
	<?php
	switch ($page) {
case 'home':
?>
  <div class="page-wrapper">
    <div class="village-map">
      <div class="building" style="top: 16%;left: 65%;transform: rotate(0deg);" onclick="showProductionModal('main')">
        <div class="building-label building-label-production">
          <div class="prod-quick-collect" onclick="event.stopPropagation();">
            <button type="button" class="prod-qc-btn" title="Собрать все" data-prod-collect="gold_mine">
              <img src="/images/icons/gold.png" alt="Золото" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="prod-qc-fallback" style="display:none">🪙</span>
            </button>
            <button type="button" class="prod-qc-btn" title="Собрать все" data-prod-collect="elixir_collector">
              <img src="/images/icons/elixir.png" alt="Эликсир" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="prod-qc-fallback" style="display:none">💜</span>
            </button>
            <button type="button" class="prod-qc-btn" title="Собрать все" data-prod-collect="dark_elixir_drill">
              <img src="/images/icons/fuel.png" alt="Тёмный эликсир" onerror="this.style.display='none'; this.nextElementSibling.style.display='inline';">
              <span class="prod-qc-fallback" style="display:none">🖤</span>
            </button>
          </div>
          Производство
        </div>
        <img src="<?= season_img('/images/building/production.png', $season) ?>" alt="Производство">
        <div class="building-shadow"></div>
      </div>

      <div class="building" style="top: 5%;right: 63%;transform: rotate(0deg);" onclick="showStorageModal('main')">
        <div class="building-label">Хранилища</div>
        <img src="<?= season_img('/images/building/storage.png', $season) ?>" alt="Хранилища">
        <div class="building-shadow"></div>
      </div>

      <div class="building" style="top: 41%;right: 54%;transform: rotate(0deg);" onclick="showTownhallModal('main')">
        <div class="building-label">Ратуша</div>
        <img src="<?= season_img('/images/building/Town_Hall/Town_Hall' . (int)$userData['townhall_lvl'] . '.png', $season) ?>" alt="Ратуша">
      </div>

      <div class="building mirror" style="top: 39.47%;left: 66%;transform: rotate(1deg);" onclick="showBarracksModal('main')">
        <div class="building-label">Казармы</div>
        <img src="<?= season_img('/images/building/barracks.png', $season) ?>" alt="Казармы">
      </div>

      <div class="building mirror" style="top: 19%;left: 44%;transform: translateX(-50%) rotate(0deg);" onclick="showDefenseModal('main')">
        <div class="building-label">Оборона</div>
        <img src="<?= season_img('/images/building/defense.png', $season) ?>" alt="Оборона">
        <div class="building-shadow"></div>
      </div>



      <div class="building" style="bottom: 17%;left: 15%;transform: translateX(-50%) rotate(-1deg);" onclick="showClanModal('main')">
        <div class="building-label">Клановая крепость</div>
        <img src="<?= season_img('/images/building/clan.png', $season) ?>" alt="Клановая крепость">
        <div class="building-shadow"></div>
      </div>

      <div class="building" style="bottom: 11%;left: 63%;transform: translateX(-50%) rotate(-1deg);" onclick="showBuilderHutModal('main')">
        <div class="building-label">Хижина строителя</div>
        <img src="<?= season_img('/images/building/Builders_Hut/Builders_Hut.png', $season) ?>" style="width: 65%;" alt="Хижина строителя">
        <div class="building-shadow"></div>
      </div>
    </div>

    <!-- Модальные окна (контейнеры; контент грузится из отдельных location-файлов) -->
    <div id="production-modal" class="modal-overlay">
      <div class="modal-content" id="production-modal-content"></div>
    </div>

    <div id="storage-modal" class="modal-overlay">
      <div class="modal-content" id="storage-modal-content"></div>
    </div>

    <div id="townhall-modal" class="modal-overlay">
      <div class="modal-content" id="townhall-modal-content"></div>
    </div>

    <div id="barracks-modal" class="modal-overlay">
      <div class="modal-content" id="barracks-modal-content"></div>
    </div>

    <div id="defense-modal" class="modal-overlay">
      <div class="modal-content" id="defense-modal-content"></div>
    </div>



    <div id="clan-modal" class="modal-overlay">
      <div class="modal-content" id="clan-modal-content"></div>
    </div>

    <div id="builder_hut-modal" class="modal-overlay">
      <div class="modal-content" id="builder_hut-modal-content"></div>
    </div>
  </div>
<?php
    break;

case 'storage':
    include __DIR__ . '/app/locations/storage.php';
    break;

case 'production':
    include __DIR__ . '/app/locations/production.php';
    break;

case 'townhall':
    include __DIR__ . '/app/locations/townhall.php';
    break;

case 'barracks':
    include __DIR__ . '/app/locations/barracks.php';
    break;

case 'defense':
    include __DIR__ . '/app/locations/defense.php';
    break;



case 'clan':
    include __DIR__ . '/app/locations/clan.php';
    break;

case 'builder_hut':
    include __DIR__ . '/app/locations/builder_hut.php';
    break;

case 'battle':
    include __DIR__ . '/app/locations/battle.php';
    break;

case 'balance':
    header('Content-Type: application/json; charset=utf-8');
    include __DIR__ . '/app/balance.php';
    return '';
}

return ob_get_clean();
}
?>