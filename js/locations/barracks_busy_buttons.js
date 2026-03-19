
/**
 * Busy-build buttons replacer (list view)
 * - When a building is under construction/upgrade, replace the action button area with a timer badge.
 * - Keeps level badge readable (doesn't overlap).
 *
 * Load AFTER barracks.js
 */
(function(){
  // CSS override: timer-as-button at the bottom
  (function ensureCss(){
    if (document.getElementById('barracks-busy-buttons-style')) return;
    var st = document.createElement('style');
    st.id = 'barracks-busy-buttons-style';
    st.textContent = `
      /* keep level badge readable */
      .coc-bslot .coc-bbadge{ z-index: 4 !important; }

      /* hide the original overlay when we show timer-as-button */
      .coc-bslot.is-busy .coc-btimer-overlay{
        display:none !important;
      }

      /* timer badge that sits where the button was */
.coc-bslot .coc-btimer-btn {
    /* position: absolute; */
    /* left: 50%; */
    bottom: 10px;
    /* transform: translateX(-50%); */
    background: rgba(0,0,0,.60);
    color: #fff;
    border-radius: 12px;
    padding: 7px 0px;
    font-weight: 900;
    font-size: 13px;
    line-height: 14px;
    pointer-events: none;
    z-index: 3;
    text-shadow: 0 1px 0 rgb(0 0 0 / 35%);
    min-width: 86px;
    text-align: center;
}
    `;
    document.head.appendChild(st);
  })();

  function isBusyCard(card){
    var ov = card.querySelector('.coc-btimer-overlay');
    if (!ov) return false;
    if (ov.classList.contains('is-hidden')) return false;
    var t = (ov.textContent || '').trim();
    return t.length > 0 && t !== '--:--' && t !== '—';
  }

  function getTimerText(card){
    var ov = card.querySelector('.coc-btimer-overlay');
    if (!ov) return '';
    return (ov.textContent || '').trim();
  }

  function actionButtons(card){
    var btns = Array.prototype.slice.call(card.querySelectorAll('button, .coc-btn, .btn'));
    return btns.filter(function(b){
      var cls = (b.className || '');
      if (cls.indexOf('coc-info') !== -1) return false;
      if (cls.indexOf('coc-info-bld') !== -1) return false;
      if (cls.indexOf('coc-bdetail') !== -1) return false;
      if (b.dataset && (b.dataset.action === 'building_build' || b.dataset.action === 'building_upgrade')) return true;
      if (cls.indexOf('build-action-btn') !== -1) return true;
      if (cls.indexOf('coc-bbuild') !== -1) return true;
      if (cls.indexOf('coc-bupgrade') !== -1) return true;
      // fallback: any button inside card is treated as action button
      return true;
    });
  }

  function ensureTimerBtn(card){
    var el = card.querySelector('.coc-btimer-btn');
    if (!el){
      el = document.createElement('div');
      el.className = 'coc-btimer-btn';
      card.appendChild(el);
    }
    el.textContent = getTimerText(card) || '';
    return el;
  }

  function removeTimerBtn(card){
    var el = card.querySelector('.coc-btimer-btn');
    if (el) el.remove();
  }

  function sync(){
    var cards = document.querySelectorAll('.coc-bslot');
    cards.forEach(function(card){
      var busy = isBusyCard(card);
      var btns = actionButtons(card);

      // store original display once
      btns.forEach(function(b){
        if (!b.dataset) return;
        if (b.dataset._origDisplay === undefined) b.dataset._origDisplay = b.style.display || '';
      });

      if (busy){
        card.classList.add('is-busy');

        // hide buttons
        btns.forEach(function(b){ b.style.display = 'none'; });

        // ensure timer badge where the button was
        ensureTimerBtn(card);

        // keep reasonable padding (buttons are hidden but badge is absolutely positioned)
        if (card.dataset._origPadBottom === undefined){
          card.dataset._origPadBottom = card.style.paddingBottom || '';
        }
        card.style.paddingBottom = '46px';
      }else{
        card.classList.remove('is-busy');

        // restore buttons
        btns.forEach(function(b){
          if (b.dataset && b.dataset._origDisplay !== undefined) b.style.display = b.dataset._origDisplay;
          else b.style.display = '';
        });

        removeTimerBtn(card);

        if (card.dataset._origPadBottom !== undefined) card.style.paddingBottom = card.dataset._origPadBottom;
        else card.style.paddingBottom = '';
      }
    });
  }

  // run periodically (safe + simple)
  setInterval(sync, 600);
  document.addEventListener('visibilitychange', function(){ if (!document.hidden) sync(); });
  window.addEventListener('focus', sync);
  window.addEventListener('load', sync);

  // Mutation observer to react to rerenders quickly
  try{
    var obs = new MutationObserver(function(){ sync(); });
    obs.observe(document.documentElement, { childList:true, subtree:true });
  }catch(e){}
})();
