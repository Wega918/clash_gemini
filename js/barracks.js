/* === Barracks info button click FIX (v5) ===
   Makes .coc-info open the same unit card modal used elsewhere.
*/
(function(){
  function tryOpen(info){
    // Try a few known handlers across the project (keeps backward compatibility)
    const candidates = [
      function(){ if (typeof window.openUnitInfo === 'function') return window.openUnitInfo(info); },
      function(){ if (typeof window.unit_info === 'function') return window.unit_info(info); },
      function(){ if (typeof window.unit_info === 'function' && String(info).includes(':')) return window.unit_info(String(info).split(':')[1]); },
      function(){ if (window.COC && typeof window.COC.openUnitInfo === 'function') return window.COC.openUnitInfo(info); },
      function(){ if (window.COC && window.COC.ui && typeof window.COC.ui.openUnitInfo === 'function') return window.COC.ui.openUnitInfo(info); },
      function(){ if (typeof window.openModalUnit === 'function') return window.openModalUnit(info); }
    ];
    for (const fn of candidates){
      try{
        const r = fn();
        if (r !== undefined) return true;
      }catch(e){
        // continue trying
      }
    }
    console.warn('[barracks] Unit info handler not found for', info);
    return false;
  }

  // Capture phase so other handlers can't swallow the event
  document.addEventListener('click', function(e){
    const btn = e.target && e.target.closest ? e.target.closest('.coc-info') : null;
    if (!btn) return;

    e.preventDefault();
    e.stopPropagation();

    const info = btn.getAttribute('data-unitinfo');
    if (!info) return;

    tryOpen(info);
  }, true);
})();
