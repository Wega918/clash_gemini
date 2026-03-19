<?php
/**
 * app/locations/clan.php
 * Локация: Клановая крепость (backend)
 */

try {
    $view = $_GET['view'] ?? 'main';

    switch ($view) {
        case 'main':
        default:
            ?>
            <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('clan-modal')"><img src="/images/icons/close.png" alt="Закрыть"></button>
            <div class="modal-header-controls">
                <div class="modal-title-bar">
                    <h2 class="modal-title-text-inside-panel">КЛАНОВАЯ КРЕПОСТЬ</h2>
                </div>
            </div>
            <div class="modal-body-custom">
                <p>Здесь вы можете вступить в клан или создать свой.</p>
                <p>Текущий клан: Нет</p>
            </div>
            <?php
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    $msg = (defined('ENVIRONMENT') && ENVIRONMENT === 'development') ? ($e->getMessage().' on line '.$e->getLine()) : 'Внутренняя ошибка сервера.';
    echo '<div class="error" style="margin:20px;">❌ Ошибка: '.htmlspecialchars($msg).'</div>';
}
