(function(){
  function boot(){
    const root = document.getElementById('raid-root');
    if (!root || root.__raidMounted) return;
    root.__raidMounted = true;
    const app = new window.RaidApp(root);
    app.mount().catch(err=>{
      console.error('Raid boot failed', err);
      const overlay = root.querySelector('#raid-scene-overlay');
      if (overlay) {
        overlay.classList.add('show');
        root.querySelector('#raid-overlay-title').textContent = 'Ошибка запуска рейда';
        root.querySelector('#raid-overlay-sub').textContent = err?.message || 'Попробуйте открыть бой ещё раз.';
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
  document.addEventListener('click', function(){ setTimeout(boot, 0); }, true);
})();
