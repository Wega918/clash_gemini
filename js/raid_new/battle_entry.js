/**
 * BATTLE ENTRY POINT - Точка входа новой боевой системы
 * Инициализирует RaidApp при загрузке страницы рейда
 */

(function() {
  'use strict';

  let initAttempts = 0;
  const maxAttempts = 20;

  function initRaidSystem() {
    const root = document.getElementById('raid-root');
    
    // If not found, try again (for AJAX-loaded content)
    if (!root) {
      initAttempts++;
      if (initAttempts < maxAttempts) {
        setTimeout(initRaidSystem, 200);
      } else {
        console.error('raid-root element not found after ' + maxAttempts + ' attempts');
      }
      return;
    }
    
    // Check if already mounted
    if (root.__raidMounted) {
      console.log('Raid system already mounted');
      return;
    }
    
    // Mark as mounted
    root.__raidMounted = true;
    
    // Check dependencies
    if (!window.RaidApp) {
      console.error('❌ RaidApp class not loaded');
      return;
    }
    if (!window.RaidApi) {
      console.error('❌ RaidApi not loaded');
      return;
    }
    
    console.log('🎮 Initializing Raid System...');
    console.log('Dependencies:', {
      RaidApp: !!window.RaidApp,
      RaidApi: !!window.RaidApi,
      RaidSearchManager: !!window.RaidSearchManager,
      ScoutScene: !!window.ScoutScene,
      BattleScene: !!window.BattleScene
    });
    
    // Add raid-active class to body for blur effect
    document.body.classList.add('raid-active');
    
    // CRITICAL: Move raid-root to body to make it fullscreen overlay
    // It gets loaded inside #app container, but we need it at body level
    if (root.parentElement && root.parentElement.id !== 'body') {
      console.log('📦 Moving raid-root to body for fullscreen overlay');
      document.body.appendChild(root);
    }
    
    // Initialize RaidApp
    try {
      const app = new window.RaidApp(root);
      
      // Store app instance for cleanup
      root.__raidApp = app;
      
      // Setup exit handler
      const exitBtn = root.querySelector('#raid-exit-btn');
      if (exitBtn) {
        exitBtn.addEventListener('click', () => {
          document.body.classList.remove('raid-active');
        });
      }
      
      // Wait a bit then mount
      setTimeout(() => {
        app.mount().catch(error => {
          console.error('❌ Raid mount failed:', error);
          
          const overlay = root.querySelector('#raid-scene-overlay');
          if (overlay) {
            overlay.classList.add('show');
            const titleEl = root.querySelector('#raid-overlay-title');
            const subEl = root.querySelector('#raid-overlay-sub');
            if (titleEl) titleEl.textContent = 'Ошибка запуска';
            if (subEl) subEl.textContent = error?.message || 'Попробуйте перезагрузить страницу';
          }
        });
      }, 100);
      
      console.log('✅ Raid system initialized successfully!');
      
    } catch (error) {
      console.error('❌ Failed to initialize raid system:', error);
      console.error('Stack:', error.stack);
    }
  }

  // Watch for raid-root element
  function watchForRaidRoot() {
    const observer = new MutationObserver((mutations) => {
      const root = document.getElementById('raid-root');
      if (root && !root.__raidMounted) {
        console.log('🔍 raid-root detected via MutationObserver');
        observer.disconnect();
        initRaidSystem();
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });

    // Also try immediate init
    setTimeout(initRaidSystem, 50);
  }

  // Start watching
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', watchForRaidRoot);
  } else {
    watchForRaidRoot();
  }

})();
