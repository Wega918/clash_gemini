<?php
// app/locations/lab.php
// Экран лаборатории отрисовывается на клиенте (JS). Здесь оставляем только контейнер модалки.
?>

<div class="lab-main-view">
  <div class="modal-header-controls">
    <h2 class="modal-title-text-inside-panel">ЛАБОРАТОРИЯ</h2>

    <button class="close-modal close-top-right modal-button-corner" onclick="hideModal('lab-modal')">
      <img src="/images/icons/close.png" alt="Закрыть">
    </button>
  </div>

  <div class="modal-body-custom">
    <noscript>
      <div class="coc-panel" style="text-align:center">
        <div class="coc-subtitle">JavaScript отключён</div>
        <div class="coc-note">Для работы лаборатории включите JavaScript в браузере.</div>
      </div>
    </noscript>
    <div id="lab-ui-root"></div>
  </div>
</div>
