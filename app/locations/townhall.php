<?php
/**
 * app/locations/townhall.php
 * Локация: Ратуша (backend)
 * Возвращает HTML для вставки в #townhall-modal-content.
 */

try {
    global $mysqli;

    // Данные пользователя (в контексте include из ajax.php они уже есть как $userData)
    if (!isset($userData) || !is_array($userData)) {
        $userData = getUser($mysqli);
    }

    $view = $_GET['view'] ?? 'main';

    switch ($view) {
        case 'main':
        default:
            $th = (int)($userData['townhall_lvl'] ?? 1);
            ?>
            <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('townhall-modal')"><img src="/images/icons/close.png" alt="Закрыть"></button>
            <div class="modal-header-controls">
                <div class="modal-title-bar">
                    <h2 class="modal-title-text-inside-panel">РАТУША</h2>
                </div>
            </div>
            <div class="modal-body-custom">
                <p>🏛 Ратуша: уровень <?= $th ?></p>
                <p>Это главное здание вашей деревни. Улучшение ратуши открывает новые возможности.</p>
            </div>
            <?php
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    $msg = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? ($e->getMessage().' on line '.$e->getLine()) : 'Внутренняя ошибка сервера.';
    echo '<div class="error" style="margin:20px;">❌ Ошибка: '.htmlspecialchars($msg).'</div>';
}
