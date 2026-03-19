<?php
/**
 * RAID BATTLE UI - PREMIUM 2026
 * Полностью переделанный интерфейс
 */
?>

<div id="raid-root" class="raid-no-select">
  <!-- Fullscreen overlay -->
  <div class="raid-overlay-shell">
    <div class="raid-viewport-frame">
      
      <!-- Battle scene with green field -->
      <div class="raid-scene-wrap">
        <canvas id="raid-scene-canvas" width="784" height="440"></canvas>
        <div class="raid-scene-vignette"></div>
        <div class="raid-gloss"></div>
        <div class="raid-edge"></div>
        
        <!-- Scene overlay for messages -->
        <div class="raid-scene-overlay" id="raid-scene-overlay">
          <div class="raid-overlay-title" id="raid-overlay-title">Поиск...</div>
          <div class="raid-overlay-sub" id="raid-overlay-sub">Подготовка к бою</div>
        </div>
      </div>
      
      <!-- HUD Layer -->
      <div class="raid-hud">
        <!-- Exit button -->
        <button class="raid-exit-btn" id="raid-exit-btn">← Выход</button>
        
        <!-- Top stats -->
        <div class="raid-top-stats">
          <div class="raid-stat-pill">
            <span>ВРЕМЯ</span>
            <strong id="raid-timer">--:--</strong>
          </div>
          <div class="raid-stat-pill">
            <span>РАЗРУШЕНИЕ</span>
            <strong id="raid-destruction">0%</strong>
          </div>
          <div class="raid-stat-pill">
            <span>ЗВЁЗДЫ</span>
            <strong id="raid-stars">0★</strong>
          </div>
        </div>
        
        <!-- Player badge (right) -->
        <div class="raid-player-info">
          <div class="raid-player-badge" id="raid-player-badge">⚜ Игрок</div>
        </div>
      </div>
      
      <!-- Target info (search/scout) -->
      <div class="raid-target-chip raid-hidden" id="raid-target-chip">
        <div class="raid-target-avatar">
          <img src="/images/icons/avatar.png" id="raid-target-avatar" alt="">
        </div>
        <div style="flex: 1; min-width: 0;">
          <div class="raid-target-name" id="raid-target-name">Противник</div>
          <div class="raid-target-sub" id="raid-target-sub">Поиск...</div>
        </div>
        <div class="raid-target-loot" id="raid-target-loot"></div>
      </div>
      
      <!-- Bottom army panel -->
      <div class="raid-bottom-dock">
        <!-- Army cards -->
        <div class="raid-army-strip" id="raid-army-strip">
          <!-- Cards added by JS -->
        </div>
        
        <!-- Action buttons -->
        <div class="raid-cta-stack">
          <button class="raid-btn raid-btn-ability raid-hidden" id="raid-ability-btn">⚡ Способность</button>
          <button class="raid-btn raid-btn-primary" id="raid-attack-btn" disabled>⚔️ Атаковать</button>
          <button class="raid-btn raid-btn-danger raid-hidden" id="raid-end-btn">Завершить</button>
        </div>
      </div>
      
    </div>
  </div>
</div>

<style>
/* Critical inline - ensure fullscreen */
#raid-root {
  position: fixed !important;
  inset: 0 !important;
  z-index: 99998 !important;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
}
</style>
