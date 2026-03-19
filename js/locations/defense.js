(function(){
  var MODAL_ID = 'defense-modal';
  var CONTENT_ID = 'defense-modal-content';
  var ENDPOINT = 'ajax.php?page=defense';
  var TITLE = 'ОБОРОНА';

  // Feature flag: disables legacy Walls handlers in main.js to prevent double-confirm modals.
  window.defenseWallsV2 = true;

  
  // Ensure Barracks shared UI styles are present (Defense reuses the same classes).
  
  // Ensure Defense has the same UI styles as Barracks even before visiting Barracks.
  // Barracks injects a big style patch at runtime; Defense reuses the same classes (coc-bslots, coc-bslot, etc),
  // so we inject the required CSS here as well.
  function ensureDefenseSharedStyles(){
    if (document.getElementById('defense-buildings-style')) return;
    try{
      var st = document.createElement('style');
      st.id = 'defense-buildings-style';
      st.type = 'text/css';
      st.appendChild(document.createTextNode("\n/* Buildings / \u041f\u043e\u0441\u0442\u0440\u043e\u0439\u043a\u0438 */\n\n.coc-building-head{\n  display:flex;\n  flex-direction:column;\n  align-items:center;\n  gap:4px;\n}\n.coc-building-title{\n  text-align:center;\n  width:100%;\n  font-weight:900;\n  justify-content:center;\n}\n.coc-building-sub{display:none;}\n\n.coc-bslots{\n  display:flex;\n  flex-wrap:wrap;\n  gap:10px;\n  justify-content:center;\n  max-width:720px;\n  margin:0 auto;\n}\n\n.coc-bslot{\n  position:relative;\n  flex:0 0 calc(33.333% - 10px);\n  max-width:calc(33.333% - 10px);\n  min-width:0;\n  background: rgb(209 209 209 / 75%);\n  border:2px solid rgba(0,0,0,.15);\n  border-radius:10px;\n  padding:38px 8px 46px; /* \u043c\u0435\u0441\u0442\u043e \u043f\u043e\u0434 \u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 */\n  text-align:center;\n}\n\n/* \u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043f\u043e\u0441\u0442\u0440\u043e\u0439\u043a\u0438 \u2014 \u043f\u043b\u0430\u0448\u043a\u0430, \"\u0441\u043b\u0438\u0432\u0430\u0435\u0442\u0441\u044f\" \u0441 \u043a\u0430\u0440\u0442\u043e\u0447\u043a\u043e\u0439 */\n.coc-bname{\n  position:absolute;\n  left:-2px;\n  right:-2px;\n  top:-2px;\n  padding:5px 0px 5px 0px;\n  font-weight:900;\n  font-size:11px;\n  /* letter-spacing:.4px; */\n  text-transform:uppercase;\n  color:#564a3c;\n  background:linear-gradient(180deg, rgba(255,255,255,.95), rgba(235,231,223,.92));\n  border:2px solid rgba(0,0,0,.15);\n  border-bottom:2px solid rgba(0,0,0,.18);\n  border-radius:0px 0px 6px 6px;\n  box-sizing:border-box;\n  text-shadow:0 1px 0 rgba(255,255,255,.7);\n}\n\n.coc-bslot img{\n  width:100px;\n  height:100px;\n  object-fit:contain;\n  display:block;\n  margin: -10px auto 8px;\n}\n\n.coc-bmore-wrap{ width:100%; display:flex; justify-content:center; margin:12px 0 4px; }\n.coc-bmore{\n  padding:10px 14px;\n  border-radius:10px;\n  border:2px solid rgba(0,0,0,.18);\n  background:linear-gradient(180deg, rgba(255,255,255,.95), rgba(235,231,223,.92));\n  font-weight:900;\n  color:#564a3c;\n}\n\n.coc-info.coc-info-bld{\n  position:absolute;\n  right:6px;\n  top:6px;\n  width:28px;\n  height:28px;\n  border-radius:8px;\n  border:2px solid rgba(0,0,0,.25);\n  background:#e9e5df;\n  font-weight:900;\n  cursor:pointer;\n}\n\n.coc-bbadge{\n  position:absolute;\n  left:6px;\n  bottom:46px;\n  pointer-events:none;\n  background:#2f2f2f;\n  color:#fff;\n  border-radius:6px;\n  padding:4px 7px;\n  font-size:11px;\n  font-weight:900;\n}\n\n.coc-bslot.is-empty{opacity:.85;}\n\n@media (max-width: 860px){\n  .coc-bslot{\n    flex-basis:calc(33.333% - 10px);\n    max-width:calc(33.333% - 10px);\n  }\n}\n\n@media (max-width: 520px){\n  .coc-bslot{\n    flex-basis:calc(33.333% - 10px);\n    max-width:calc(33.333% - 10px);\n  }\n  .coc-bslot img{\n    width:80px;\n    height:80px;\n  }\n}\n\n/* \u23f1 \u0422\u0430\u0439\u043c\u0435\u0440 \u043f\u043e\u0432\u0435\u0440\u0445 \u043a\u0430\u0440\u0442\u0438\u043d\u043a\u0438 (\u043a\u043e\u043c\u043f\u0430\u043a\u0442\u043d\u044b\u0439) */\n.coc-btimer-overlay{\n  position:absolute;\n  pointer-events:none;\n  left:50%;\n  top:58%;                 /* \u043d\u0438\u0436\u0435, \u0431\u043b\u0438\u0436\u0435 \u043a \u0446\u0435\u043d\u0442\u0440\u0443 \u0438\u043a\u043e\u043d\u043a\u0438 */\n  transform:translate(-50%,-50%);\n  background:rgba(0,0,0,.65);\n  color:#fff;\n  border-radius:8px;\n  padding:3px 8px;\n  font-weight:900;\n  font-size:11px;\n  line-height:14px;\n  pointer-events:none;\n  z-index:3;\n  text-shadow:0 1px 0 rgba(0,0,0,.35);\n}\n.coc-btimer-overlay.is-hidden{display:none;}\n.coc-btimer-overlay.is-pop{animation:cocBTimerPop .14s ease-out;}\n@keyframes cocBTimerPop{\n  0%{transform:translate(-50%,-50%) scale(.85); opacity:.6;}\n  50%{transform:translate(-50%,-50%) scale(1.12); opacity:1;}\n  100%{transform:translate(-50%,-50%) scale(1); opacity:1;}\n}\n\n/* Hold badge on troop tile */\n.coc-holdbadge{\n  position:absolute;\n  left:50%;\n  top:56%;\n  transform:translate(-50%,-50%);\n  background:rgba(0,0,0,.70);\n  color:#fff;\n  border-radius:999px;\n  padding:2px 7px;\n  font-weight:900;\n  font-size:11px;\n  line-height:14px;\n  z-index:4;\n  pointer-events:none;\n  text-shadow:0 1px 0 rgba(0,0,0,.35);\n}\n.coc-holdbadge.is-hidden{display:none;}\n.coc-holdbadge.is-pop{\n  animation:cocHoldPop .14s ease-out;\n}\n@keyframes cocHoldPop{\n  0%{transform:translate(-50%,-50%) scale(.85); opacity:.6;}\n  100%{transform:translate(-50%,-50%) scale(1); opacity:1;}\n}\n\n/* Building detail overlay (CoC-style) */\n.coc-bdetail-overlay{\n  position:fixed;\n  top:0;left:0;right:0;bottom:0;\n  background:rgba(0,0,0,.45);\n  z-index:999999;\n  display:flex;\n  align-items:center;\n  justify-content:center;\n  padding:10px;\n}\n\n.coc-bdetail-modal{\n  width:min(520px,96vw);\n  max-height:min(86vh,720px);\n  background:#efe9dd;\n  border:4px solid rgba(0,0,0,.25);\n  border-radius:16px;\n  box-shadow:0 20px 60px rgba(0,0,0,.35);\n  overflow:hidden;\n  display:flex;\n  flex-direction:column;\n}\n\n.coc-bdetail-head{\n  position:relative;\n  background:#6c625b;\n  color:#fff;\n  padding:10px 46px 10px 14px;\n  font-weight:900;\n  font-size:20px;\n  text-align:center;\n  text-shadow:0 2px 0 rgba(0,0,0,.35);\n}\n\n.coc-bdetail-close{\n  position:absolute;\n  right:10px;\n  top:8px;\n  width:34px;\n  height:34px;\n  border-radius:10px;\n  border:2px solid rgba(0,0,0,.25);\n  background:#d54;\n  cursor:pointer;\n  color:#fff;\n  font-weight:900;\n  line-height:30px;\n}\n\n.coc-bdetail-body{\n  padding:12px;\n  overflow:auto;\n}\n.coc-bdetail-body::-webkit-scrollbar{width:0;height:0;}\n\n.coc-bdetail-top{\n  display:flex;\n  gap:12px;\n  align-items:flex-start;\n}\n\n.coc-bdetail-img{\n  flex:0 0 190px;\n  max-width:190px;\n  background:rgba(255,255,255,.5);\n  border:2px solid rgba(0,0,0,.12);\n  border-radius:14px;\n  padding:6px;\n  display:flex;\n  align-items:center;\n  justify-content:center;\n}\n\n.coc-bdetail-img img{\n  width:100%;\n  height:170px;\n  object-fit:contain;\n  display:block;\n}\n\n.coc-bdetail-info{flex:1;min-width:0;}\n\n.coc-bdetail-sub{\n  font-weight:900;\n  margin-bottom:8px;\n}\n\n.coc-bdetail-grid{\n  display:grid;\n  grid-template-columns:1fr 1fr;\n  gap:6px;\n}\n\n.coc-bdetail-tile{\n  background:rgba(0,0,0,.03);\n  border:1px solid rgba(0,0,0,.10);\n  border-radius:10px;\n  padding:6px;\n}\n\n.coc-bdetail-k{\n  font-size:9px;\n  font-weight:900;\n  opacity:.8;\n}\n\n.coc-bdetail-v{\n  margin-top:2px;\n  font-size:11px;\n  font-weight:900;\n}\n\n/* Highlight missing requirements (upgrade) */\n.coc-need-bad{\n  border-color: rgba(210, 30, 30, .55) !important;\n  background: rgba(210, 30, 30, .08) !important;\n}\n.coc-need-bad .coc-bdetail-k,\n.coc-need-bad .coc-bdetail-v{\n  color: #b11;\n}\n\n.coc-bdetail-block{\n  margin-top:10px;\n  background:rgba(255,255,255,.55);\n  border:2px solid rgba(0,0,0,.12);\n  border-radius:14px;\n  padding:10px;\n}\n\n.coc-bdetail-bt{\n  font-weight:900;\n  margin-bottom:6px;\n}\n\n.coc-bdetail-actions{\n  display:flex;\n  gap:10px;\n  padding:12px;\n  border-top:2px solid rgba(0,0,0,.12);\n  background:rgba(0,0,0,.05);\n}\n\n.coc-bdetail-actions .coc-speedup-btn{flex:1;}\n\n@media (max-width:480px){\n  .coc-bdetail-top{\n    flex-direction:column;\n    align-items:center;\n  }\n  .coc-bdetail-img{\n    max-width:240px;\n    width:240px;\n  }\n  /* \u0442\u0435\u043b\u0435\u0444\u043e\u043d: \u0432\u0440\u0435\u043c\u044f + \u043e\u0442\u043a\u0440\u044b\u0432\u0430\u0435\u0442 \u2014 2 \u0432 \u0440\u044f\u0434, \u0437\u0434\u043e\u0440\u043e\u0432\u044c\u0435 \u2014 \u043d\u0430 \u0432\u0441\u044e \u0448\u0438\u0440\u0438\u043d\u0443 */\n  .coc-bdetail-grid{\n    grid-template-columns:1fr 1fr;\n  }\n  .coc-bdetail-grid .meta-hp{\n    grid-column:1 / -1;\n  }\n}\n\n/* Progress */\n.coc-progress{\n  height:12px;\n  border-radius:10px;\n  background:rgba(0,0,0,.12);\n  overflow:hidden;\n  border:1px solid rgba(0,0,0,.12);\n}\n.coc-progress > div{\n  height:100%;\n  width:0%;\n  background:linear-gradient(90deg,#6fda45,#2ea43a);\n  transition:width .35s ease;\n}\n.coc-progress-indeterminate > div{\n  width:35%;\n  animation:cocInd 1.2s infinite;\n}\n@keyframes cocInd{\n  0%{transform:translateX(-120%);}\n  100%{transform:translateX(320%);}\n}\n/* ===== CoC Original-ish skin overrides (spell & troop detail modal) ===== */\n.coc-bdetail-modal{\n  background:linear-gradient(#f8f2e6,#e8d6bd);\n  border:5px solid #4b2f1a;\n  box-shadow:0 22px 70px rgba(0,0,0,.45), inset 0 0 0 2px rgba(255,255,255,.35);\n}\n.coc-bdetail-head{\n  background:linear-gradient(#7c4c2a,#5a321b);\n  border-bottom:3px solid rgba(0,0,0,.18);\n  letter-spacing:.3px;\n}\n.coc-bdetail-head:before{\n  content:\"\";\n  position:absolute;\n  top:0;left:0;right:0;bottom:0;\n  background:linear-gradient(rgba(255,255,255,.22), rgba(255,255,255,0) 55%);\n  pointer-events:none;\n}\n.coc-bdetail-close{\n  background:linear-gradient(#ff6b5a,#c83022);\n  border:2px solid rgba(0,0,0,.25);\n  box-shadow:0 3px 0 rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.25);\n}\n.coc-bdetail-close:active{ transform:translateY(1px); box-shadow:0 2px 0 rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.20); }\n\n.coc-bdetail-img{\n  background:linear-gradient(rgba(255,255,255,.85),rgba(255,255,255,.55));\n  border:2px solid rgba(75,47,26,.20);\n  box-shadow:inset 0 1px 0 rgba(255,255,255,.6);\n}\n.coc-bdetail-tile{\n  background:linear-gradient(#fff7ea,#f0e1c9);\n  border:2px solid rgba(75,47,26,.18);\n  box-shadow:inset 0 1px 0 rgba(255,255,255,.6);\n}\n.coc-bdetail-k{\n  font-size:9px;\n  text-transform:uppercase;\n  letter-spacing:.25px;\n  opacity:.85;\n}\n.coc-bdetail-v{\n  font-size:15px;\n}\n.coc-bdetail-block{\n  background:linear-gradient(rgba(255,255,255,.78), rgba(255,255,255,.52));\n  border:2px solid rgba(75,47,26,.16);\n  box-shadow:inset 0 1px 0 rgba(255,255,255,.55);\n}\n\n.coc-bdetail-actions{\n  background:linear-gradient(rgba(0,0,0,.05), rgba(0,0,0,.08));\n}\n.coc-bdetail-modal .coc-speedup-btn{\n  border-radius:16px !important;\n  font-weight:900 !important;\n  font-size:16px !important;\n  text-shadow:0 2px 0 rgba(0,0,0,.20);\n  box-shadow:0 4px 0 rgba(0,0,0,.20), inset 0 1px 0 rgba(255,255,255,.28);\n}\n.coc-bdetail-modal .coc-speedup-btn:not(.coc-btn-gray){\n  background:linear-gradient(#7cf05a,#2ea43a) !important;\n  border:2px solid rgba(0,0,0,.18) !important;\n}\n.coc-bdetail-modal .coc-speedup-btn.coc-btn-gray{\n  background:linear-gradient(#e6e0d6,#bfb4a5) !important;\n  border:2px solid rgba(0,0,0,.15) !important;\n}\n.coc-bdetail-modal .coc-speedup-btn:disabled{\n  filter:grayscale(.25);\n  opacity:.70;\n}\n\n"));
    try{
      st.appendChild(document.createTextNode("\n/* Walls accordion */\n"+
        ".walls-accordion{border-radius:12px;overflow:hidden;border:1px solid rgba(0,0,0,.12);background:rgba(0,0,0,.03);}"+
        ".walls-accordion__summary{list-style:none;cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;font-weight:900;border-radius:12px;}"+
        ".walls-accordion__summary::-webkit-details-marker{display:none;}"+
        ".walls-accordion__hint{font-size:11px;opacity:.75;font-weight:900;}"+
        "@media (max-width:380px){.coc-um-modal--defcompact .coc-um-tile{flex:1 1 100%;}.coc-um-modal--defcompact .coc-um-actions{flex-wrap:wrap;}.coc-um-modal--defcompact .coc-um-upgmeta{justify-content:center;}}\n"));
    }catch(e){}
document.head.appendChild(st);
    }catch(e){}
  }


  // Extra Defense-only styling hooks:
  //  - .defense-type-slots .coc-bslot--dtype : cards on the first screen (types grid)
  //  - .coc-um-modal--defcompact : compact building modal (smaller art + tighter spacing)
  function ensureDefenseExtrasStyles(){
    if (document.getElementById('defense-extras-style')) return;
    var st = document.createElement('style');
    st.id = 'defense-extras-style';
    st.type = 'text/css';
    st.textContent = ''
      + '.defense-type-slots .coc-bslot--dtype{cursor:pointer;}'
      + '.defense-type-slots .coc-bslot--dtype{padding:32px 8px 14px; min-height:165px;}'
      + '.defense-type-slots .coc-bslot--dtype .coc-bbadge{bottom:10px; left:50%; transform:translateX(-50%);}'
      + '.defense-type-slots .coc-bslot--dtype img.coc-bimg{width:72px;height:72px;margin:0 auto 6px;}'

      /* Compact building modal layout fixes (desktop + mobile) */
      + '.coc-um-modal--defcompact .coc-um-body{padding:8px 10px; display:flex !important; flex-wrap:nowrap !important; gap:10px; align-items:flex-start;}'
      + '.coc-um-modal--defcompact .coc-um-left{flex:0 0 150px !important; position:relative; z-index:2;}'
      + '.coc-um-modal--defcompact .coc-um-right{flex:1 1 auto !important; min-width:0 !important; overflow:hidden !important; position:relative; z-index:3;}'
      + '.coc-um-modal--defcompact .coc-um-art{padding:6px; display:flex; justify-content:center; align-items:center; max-width:150px;}'
      + '.coc-um-modal--defcompact .coc-um-tiles{gap:6px;}'
      + '.coc-um-modal--defcompact .coc-um-tile{padding:6px 8px;}'
      + '.coc-um-modal--defcompact .coc-um-k{font-size:10px;}'
      + '.coc-um-modal--defcompact .coc-um-v{font-size:12px;}'
      + '.coc-um-modal--defcompact .coc-um-actions{padding:8px 10px; gap:8px; align-items:center;}'
      + '.coc-um-modal--defcompact .coc-um-upgmeta{display:flex; flex-wrap:wrap; gap:6px; justify-content:flex-end;}'
      + '.coc-um-modal--defcompact .coc-um-chip--meta{background:rgba(0,0,0,.05); border:1px solid rgba(0,0,0,.12); border-radius:999px; padding:5px 10px; font-weight:900;}'
      + '.coc-um-modal--defcompact .coc-um-need{margin-top:6px;}'

      /* Upgrade progress bar styles (used in coc-um-prog) */
      + '.coc-um-prog .js-upgrade-progress{display:flex;flex-direction:column;gap:6px;}'
      + '.coc-um-prog .upgrade-progress-row{display:flex;align-items:center;gap:10px;}'
      + '.coc-um-prog .coc-progress{flex:1;min-width:0;height:12px;background:rgba(0,0,0,.12);border-radius:999px;overflow:hidden;box-shadow:inset 0 1px 2px rgba(0,0,0,.18);}'
      + '.coc-um-prog .upgrade-progress-fill{height:100%;width:0%;background:linear-gradient(90deg,#6fda45,#2ea43a);border-radius:999px;transition:width .35s ease;}'
      + '.coc-um-prog .upgrade-progress-time{white-space:nowrap;font-weight:900;font-size:12px;opacity:.95;}'
      + '.coc-um-prog .upgrade-left{white-space:nowrap;}'

      /* Mobile: center art */
      + '@media (max-width:520px){'
      +   '.coc-um-modal--defcompact .coc-um-body{flex-direction:column;align-items:stretch;}'
      +   '.coc-um-modal--defcompact .coc-um-left{max-width:100%;width:100%;display:flex;flex-direction:column;align-items:center;}'
      +   '.coc-um-modal--defcompact .coc-um-right{width:100%;}'
      +   '.coc-um-modal--defcompact .coc-um-art{max-width:220px;}'
      +   '.coc-um-modal--defcompact .coc-um-desc{width:100%;text-align:left;background:rgba(0,0,0,.04);border:1px solid rgba(0,0,0,.10);border-radius:12px;padding:10px 12px;}'
      +   '.coc-um-modal--defcompact .coc-um-tiles{display:flex;flex-wrap:wrap;}'
      +   '.coc-um-modal--defcompact .coc-um-tile{flex:1 1 calc(50% - 6px);min-width:0;}'
      +   '.coc-um-modal--defcompact .coc-um-upgmeta{justify-content:center;}'
      +   '.coc-um-modal--defcompact .coc-um-chip--meta{white-space:normal;}'
            + '\n/* Walls accordion */\n'
      + '.walls-accordion{border-radius:12px;overflow:hidden;border:1px solid rgba(0,0,0,.12);background:rgba(0,0,0,.03);}'
      + '.walls-accordion__summary{list-style:none;cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;font-weight:900;border-radius:12px;}'
      + '.walls-accordion__summary::-webkit-details-marker{display:none;}'
      + '.walls-accordion__hint{font-size:11px;opacity:.75;font-weight:900;}'
      + '@media (max-width:380px){.coc-um-modal--defcompact .coc-um-tile{flex:1 1 100%;}.coc-um-modal--defcompact .coc-um-actions{flex-wrap:wrap;}.coc-um-modal--defcompact .coc-um-upgmeta{justify-content:center;}}'
+ '}';
    document.head.appendChild(st);
  }

  // Tabs scrolling (wheel on desktop + touch on mobile)
  function enableTabsScrolling(tabsEl){
    if (!tabsEl || tabsEl.__defTabsScroll) return;
    tabsEl.__defTabsScroll = true;

    // Ensure it can scroll horizontally
    try {
      tabsEl.style.overflowX = 'auto';
      tabsEl.style.overflowY = 'hidden';
      tabsEl.style.webkitOverflowScrolling = 'touch';
      tabsEl.style.scrollbarWidth = 'thin';
    } catch(e){}

    tabsEl.addEventListener('wheel', function(ev){
      if (Math.abs(ev.deltaY) < 1) return;
      // scroll horizontally with mouse wheel
      tabsEl.scrollLeft += ev.deltaY;
      ev.preventDefault();
    }, {passive:false});
  }


function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"]/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m]);
    });
  }

  function fmtNum(n){
    n = parseInt(n, 10);
    if (!isFinite(n)) n = 0;
    return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function resIcon(resType){
    resType = String(resType||'').toLowerCase();
    if (resType.indexOf('gold') !== -1) return '/images/icons/gold.png';
    if (resType.indexOf('elixir') !== -1 && resType.indexOf('dark') === -1) return '/images/icons/elixir.png';
    if (resType.indexOf('dark') !== -1) return '/images/icons/dark_elixir.png';
    if (resType.indexOf('gem') !== -1) return '/images/icons/gems.png';
    return '/images/icons/gems.png';
  }

  // Toast helper (same contract as other locations).
  // Never fall back to alert/confirm.
  function toast(type, title, msg){
    try{
      if (typeof window.gameToast === 'function') return window.gameToast(type, title, msg);
    }catch(_e){}
    try{
      var box = document.getElementById('coc-fallback-toast');
      if (!box){
        box = document.createElement('div');
        box.id = 'coc-fallback-toast';
        box.style.position = 'fixed';
        box.style.left = '12px';
        box.style.right = '12px';
        box.style.bottom = '12px';
        box.style.zIndex = '99999';
        box.style.display = 'flex';
        box.style.flexDirection = 'column';
        box.style.gap = '8px';
        document.body.appendChild(box);
      }
      var item = document.createElement('div');
      item.style.padding = '10px 12px';
      item.style.borderRadius = '12px';
      item.style.background = 'rgba(20,20,20,0.92)';
      item.style.color = '#fff';
      item.style.boxShadow = '0 10px 30px rgba(0,0,0,0.25)';
      item.style.fontSize = '14px';
      item.style.lineHeight = '1.25';
      item.style.border = (type==='error') ? '1px solid rgba(255,70,70,0.6)' : (type==='success'||type==='ok') ? '1px solid rgba(70,255,140,0.45)' : '1px solid rgba(255,255,255,0.15)';
      item.innerHTML = (title ? '<div style="font-weight:700;margin-bottom:4px;">'+esc(title)+'</div>' : '')
        + '<div>'+esc(msg||'')+'</div>';
      box.appendChild(item);
      setTimeout(function(){
        try{ item.style.opacity='0'; item.style.transition='opacity .25s'; }catch(_){ }
        setTimeout(function(){ try{ item.remove(); }catch(_2){} }, 320);
      }, 2600);
    }catch(_e2){
      try{ console.log(type, title, msg); }catch(_e3){}
    }
  }

  // Backward-compatible alias (older code checks showToast)
  if (!window.showToast) window.showToast = toast;

  function getCsrfToken(){
    try{
      if (window.APP_CONFIG && window.APP_CONFIG.csrfToken) return window.APP_CONFIG.csrfToken;
      if (window.CSRF_TOKEN) return window.CSRF_TOKEN;
      var meta = document.querySelector('meta[name="csrf_token"],meta[name="csrf-token"]');
      if (meta && meta.content) return meta.content;
    }catch(_e){}
    return '';
  }

  function ensureDefenseTabsStyles(){
    if (document.getElementById('defense-tabs-style')) return;
    var st = document.createElement('style');
    st.id = 'defense-tabs-style';
    st.textContent = ''
      + '.coc-tabs{display:flex;gap:8px;overflow-x:auto;overflow-y:hidden;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;scrollbar-width:thin;padding-bottom:2px;}'
      + '.coc-tabs::-webkit-scrollbar{height:7px;}'
      + '.coc-tabs::-webkit-scrollbar-thumb{background:rgba(0,0,0,.25);border-radius:8px;}'
      + '.coc-tab{flex:0 0 auto;}'
      + '.coc-tab.active,.coc-tab.is-active{filter:brightness(1.05);box-shadow:0 1px 0 rgba(255,255,255,.35) inset;outline:0;}';
    document.head.appendChild(st);
  }


  function enhanceTabs(root){
    root = root || document;
    var tabs = root.querySelectorAll ? root.querySelectorAll('.coc-tabs') : [];
    if (!tabs || !tabs.length) return;

    for (var i=0; i<tabs.length; i++){
      var bar = tabs[i];
      if (!bar.getAttribute('data-wheelbound')){
        bar.setAttribute('data-wheelbound','1');
        // Wheel → horizontal scroll (desktop)
        bar.addEventListener('wheel', function(ev){
          // If user is trying to scroll vertically over the tabs, translate to horizontal.
          if (Math.abs(ev.deltaY) > Math.abs(ev.deltaX)) {
            ev.preventDefault();
            this.scrollLeft += ev.deltaY;
          }
        }, {passive:false});
      }

      // Active tab highlight
      var btns = bar.querySelectorAll('.coc-tab[data-tab]');
      for (var j=0; j<btns.length; j++){
        var b = btns[j];
        var t = b.getAttribute('data-tab') || '';
        if (t && t === (currentState.tab || 'ground')) {
          b.classList.add('active');
          b.classList.add('is-active');
        } else {
          b.classList.remove('active');
          b.classList.remove('is-active');
        }
      }

      // Keep active in view
      var active = bar.querySelector('.coc-tab.active');
      if (active && active.scrollIntoView){
        try{ active.scrollIntoView({block:'nearest', inline:'center'}); }catch(_e){}
      }
    }
  }

  // CoC-style confirm overlay (compatible with barracks cocConfirm but supports HTML body)
  function ensureConfirmUI(){
    if (document.getElementById('coc-confirm-overlay')) return;
    var ov = document.createElement('div');
    ov.id = 'coc-confirm-overlay';
    ov.className = 'coc-confirm-overlay is-hidden';
    ov.style.position = 'fixed';
    ov.style.left = '0';
    ov.style.top = '0';
    ov.style.right = '0';
    ov.style.bottom = '0';
    ov.style.zIndex = '2147483000';
    ov.innerHTML = ''
      + '<div class="coc-confirm-panel" role="dialog" aria-modal="true">'
      +   '<button class="coc-confirm-x coc-um-x" type="button" id="coc-confirm-x" aria-label="Закрыть">×</button>'
      +   '<div class="coc-confirm-title" id="coc-confirm-title"></div>'
      +   '<div class="coc-confirm-body" id="coc-confirm-body"></div>'
      +   '<div class="coc-confirm-cost" id="coc-confirm-cost"></div>'
      +   '<div class="coc-confirm-actions" id="coc-confirm-actions">'
      +     '<button class="coc-btn coc-btn-cancel" type="button" id="coc-confirm-cancel">Отмена</button>'
      +     '<button class="coc-btn coc-btn-ok" type="button" id="coc-confirm-ok">Да</button>'
      +   '</div>'
      + '</div>';
    document.body.appendChild(ov);

    function close(res){
      try{ ov.classList.add('is-hidden'); }catch(e){}
      var cb = ov._resolve; ov._resolve = null;
      if (cb) cb(res);
    }
    ov._close = close;
    ov.addEventListener('click', function(e){
      if (e.target === ov) return close(false);
      // Delegate clicks for dynamic action buttons
      var tid = (e.target && e.target.id) ? e.target.id : '';
      if (!tid && e.target && e.target.closest) {
        var btn = e.target.closest('button');
        tid = btn && btn.id ? btn.id : '';
      }
      if (tid === 'coc-confirm-cancel' || tid === 'coc-confirm-x') return close(false);
      if (tid === 'coc-confirm-ok') return close(true);
    });
    document.getElementById('coc-confirm-x').addEventListener('click', function(){ close(false); });
    document.addEventListener('keydown', function(e){ if (!ov.classList.contains('is-hidden') && e.key === 'Escape') close(false); });
  }

  function defenseConfirm(opts){
    opts = opts || {};
    // if barracks cocConfirm exists and caller only needs plain text, allow using it.
    if (window.cocConfirm && !opts.html) return window.cocConfirm(opts);
    ensureConfirmUI();
    var ov = document.getElementById('coc-confirm-overlay');
    var t = document.getElementById('coc-confirm-title');
    var b = document.getElementById('coc-confirm-body');
    var c = document.getElementById('coc-confirm-cost');
    t.textContent = opts.title || 'Подтверждение';
    if (opts.html) b.innerHTML = opts.html;
    else b.textContent = opts.text || '';

    var cost = (typeof opts.cost !== 'undefined') ? (parseInt(opts.cost,10) || 0) : 0;
    if (cost > 0){
      var icon = opts.costIconHtml || ('<img class="coc-ic" src="'+esc(resIcon('gems'))+'" alt="">');
      c.innerHTML = '<span class="coc-confirm-cost-label">Стоимость:</span> <span class="coc-confirm-cost-val">'+esc(fmtNum(cost))+'</span> ' + icon;
      c.style.display = '';
    } else {
      c.textContent = '';
      c.style.display = 'none';
    }
    try{
      document.getElementById('coc-confirm-ok').textContent = opts.okText || 'Да';
      document.getElementById('coc-confirm-cancel').textContent = opts.cancelText || 'Отмена';
    }catch(_e){}

    // Custom action buttons (multi-choice confirm)
    var actionsHost = document.getElementById('coc-confirm-actions');

    function ensureDefaultButtons(){
      if (!actionsHost) return;
      if (document.getElementById('coc-confirm-ok') && document.getElementById('coc-confirm-cancel')) return;
      actionsHost.innerHTML = ''
        + '<button class="coc-btn coc-btn-cancel" type="button" id="coc-confirm-cancel">Отмена</button>'
        + '<button class="coc-btn coc-btn-ok" type="button" id="coc-confirm-ok">Да</button>';
    }

    if (actionsHost && Array.isArray(opts.actions) && opts.actions.length){
      actionsHost.innerHTML = '';
      opts.actions.forEach(function(a, i){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = (a.className || 'coc-btn coc-btn-ok');
        if (a.html){ btn.innerHTML = a.html; }
        else { btn.textContent = a.text || ('OK ' + (i+1)); }

        if (a.disabled){
          btn.disabled = true;
          btn.className += ' is-locked';
          btn.setAttribute('aria-disabled','true');
        } else {
          btn.addEventListener('click', function(){
            if (ov && ov._close) return ov._close(a.value);
            try{ ov.classList.add('is-hidden'); }catch(e){}
            var cb = ov._resolve; ov._resolve = null;
            if (cb) cb(a.value);
          });
        }

        actionsHost.appendChild(btn);
      });
    } else {
      ensureDefaultButtons();
      var okBtn = document.getElementById('coc-confirm-ok');
      var cancelBtn = document.getElementById('coc-confirm-cancel');
      if (okBtn) okBtn.textContent = opts.okText || 'Да';
      if (cancelBtn) cancelBtn.textContent = opts.cancelText || 'Отмена';
      if (cancelBtn) cancelBtn.style.display = '';
      if (okBtn) okBtn.style.display = '';
    }
    try{ document.body.appendChild(ov); }catch(_e2){}
    try{ ov.classList.remove('is-hidden'); }catch(_e3){}
    return new Promise(function(resolve){ ov._resolve = resolve; });
  }

  var historyStack = [];
  var currentState = {view: 'main', tab: 'ground', type: '', id: 0};
  var progressInterval = null;
  var pendingRefresh = false;
  var progressNodes = [];
  var progressObserverStarted = false;


  // Overlay modal (Barracks-style) for a single defense building
  var overlayId = 'coc-ddetail-overlay';

  function formatLeft(s){
    s = Math.max(0, parseInt(s, 10) || 0);
    var d = Math.floor(s / 86400); s -= d*86400;
    var h = Math.floor(s / 3600); s -= h*3600;
    var m = Math.floor(s / 60); var sec = s - m*60;
    var out = '';
    if (d>0) out += d+'д ';
    if (h>0 || d>0) out += h+'ч ';
    out += (m<10?'0':'')+m+':'+(sec<10?'0':'')+sec;
    return out;
  }

  function getModalEls(modalId){
    return {
      modal: document.getElementById(modalId),
      cont: document.getElementById(CONTENT_ID)
    };
  }

  function ensureModal(){
    var m = document.getElementById(MODAL_ID);
    if (!m) {
      // если в проекте есть createModal — используем
      if (typeof createModal === 'function') {
        createModal(MODAL_ID, CONTENT_ID, TITLE);
      }
    }
  }

    function loaderHtml(){
    return ''
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\''+MODAL_ID+'\')">'
      + '<img src="/images/icons/close.png" alt="Закрыть"></button>'
      + '<div class="modal-header-controls"><div class="modal-title-bar">'
      + '<h2 class="modal-title-text-inside-panel">' + TITLE + '</h2>'
      + '</div></div>'
      + '<div class="modal-body-custom">'
      + '<div class="modal-loader"><div class="loader-spinner"></div><div class="loader-text">Загрузка…</div></div>'
      + '</div>';
  }


  function applyBalancePayload(root){
    if (!root) return;

    // New payload format (used by defense.php): <div class="js-balance-payload" data-gold="..." ...></div>
    var node = root.querySelector('.js-balance-payload');
    if (node) {
      try {
        var data = {
          gold: parseInt(node.getAttribute('data-gold')||'0',10) || 0,
          elixir: parseInt(node.getAttribute('data-elixir')||'0',10) || 0,
          dark_elixir: parseInt(node.getAttribute('data-dark_elixir')||'0',10) || 0,
          gems: parseInt(node.getAttribute('data-gems')||'0',10) || 0,
          cap_gold: parseInt(node.getAttribute('data-cap_gold')||'0',10) || 0,
          cap_elixir: parseInt(node.getAttribute('data-cap_elixir')||'0',10) || 0,
          cap_dark_elixir: parseInt(node.getAttribute('data-cap_dark_elixir')||'0',10) || 0,
          cap_gems: parseInt(node.getAttribute('data-cap_gems')||'0',10) || 0
        };
        if (window.applyBalanceUpdate) window.applyBalanceUpdate(data);
      } catch(e) {}
      return;
    }

    // Backward compatibility: <script id="balance-payload">{...}</script>
    var payload = root.querySelector('#balance-payload');
    if (!payload) return;
    try {
      var data2 = JSON.parse(payload.textContent || '{}');
      if (window.applyBalanceUpdate) window.applyBalanceUpdate(data2);
    } catch(e2) {}
  }

  
// Fallback: update resource counters in header if core script doesn't provide it
if (!window.applyBalanceUpdate){
  window.applyBalanceUpdate = function(data){
    if (!data) return;
	    function showDelta(resKey, delta){
	      delta = parseInt(delta||0,10) || 0;
	      if (!delta) return;
	      var el = document.getElementById('balance-' + resKey + '-text');
	      if (!el || !el.animate) return;
	      try{
	        var fly = document.createElement('div');
	        fly.textContent = (delta > 0 ? '+' : '') + String(delta);
	        fly.style.position = 'absolute';
	        fly.style.right = '0';
	        fly.style.top = '-6px';
	        fly.style.fontSize = '12px';
	        fly.style.fontWeight = '900';
	        fly.style.pointerEvents = 'none';
	        fly.style.opacity = '1';
	        fly.style.color = (delta > 0) ? '#7CFF7C' : '#ff6b6b';
	        el.style.position = 'relative';
	        el.appendChild(fly);
	        fly.animate([
	          { transform:'translateY(0)', opacity: 1 },
	          { transform:'translateY(-14px)', opacity: 0 }
	        ], { duration: 900, easing: 'ease-out' }).onfinish = function(){ try{ fly.remove(); }catch(e){} };
	      }catch(_e){}
	    }
    function setAny(selectors, val){
      for (var i=0;i<selectors.length;i++){
        try{
          var els = document.querySelectorAll(selectors[i]);
          if (!els || !els.length) continue;
          for (var j=0;j<els.length;j++){
            if (!els[j]) continue;
            // don't overwrite inputs
            if (els[j].tagName === 'INPUT' || els[j].tagName === 'TEXTAREA') continue;
            els[j].textContent = fmtNum(parseInt(val,10)||0);
          }
        }catch(_e){}
      }
    }
    // Header (system/header.php) uses ids: balance-<res>-text and balance-<res>-bar
    if (data.gold != null) setAny(['#balance-gold-text','#gold','#gold-count','#gold_amount','[data-balance="gold"]','[data-resource="gold"] .value','.res-gold .value','.gold .value','.resource-gold .value'], data.gold);
    if (data.elixir != null) setAny(['#balance-elixir-text','#elixir','#elixir-count','#elixir_amount','[data-balance="elixir"]','[data-resource="elixir"] .value','.res-elixir .value','.elixir .value','.resource-elixir .value'], data.elixir);
    if (data.dark_elixir != null) setAny(['#balance-dark_elixir-text','#dark_elixir','#darkelixir','#dark-elixir','#dark_elixir-count','[data-balance="dark_elixir"]','[data-resource="dark_elixir"] .value','.res-darkelixir .value','.darkelixir .value'], data.dark_elixir);
    if (data.gems != null) setAny(['#balance-gems-text','#gems','#gems-count','#gems_amount','[data-balance="gems"]','[data-resource="gems"] .value','.res-gems .value','.gems .value'], data.gems);

    // Keep capacities in a shared place (used by header popover in main.js)
    try{
      window.BALANCE_CAPS = window.BALANCE_CAPS || {};
      if (data.cap_gold != null) window.BALANCE_CAPS.gold = parseInt(data.cap_gold,10) || 0;
      if (data.cap_elixir != null) window.BALANCE_CAPS.elixir = parseInt(data.cap_elixir,10) || 0;
      if (data.cap_dark_elixir != null) window.BALANCE_CAPS.dark_elixir = parseInt(data.cap_dark_elixir,10) || 0;
      if (data.cap_gems != null) window.BALANCE_CAPS.gems = parseInt(data.cap_gems,10) || 0;
    }catch(_eCaps){}

    function setBar(barId, amt, cap){
      try{
        var bar = document.getElementById(barId);
        if (!bar) return;
        cap = parseInt(cap,10) || 0;
        amt = parseInt(amt,10) || 0;
        if (cap <= 0) return;
        var pct = Math.max(0, Math.min(100, Math.round((amt / cap) * 100)));
        bar.style.width = pct + '%';
      }catch(_eBar){}
    }
    // Update header bars if we know caps
    try{
      var caps = window.BALANCE_CAPS || {};
      if (data.gold != null && caps.gold) setBar('balance-gold-bar', data.gold, caps.gold);
      if (data.elixir != null && caps.elixir) setBar('balance-elixir-bar', data.elixir, caps.elixir);
      if (data.dark_elixir != null && caps.dark_elixir) setBar('balance-dark_elixir-bar', data.dark_elixir, caps.dark_elixir);
      // gems bar usually doesn't exist
    }catch(_eBars){}

	    // Optional spend/gain effect (used by walls/build confirmations)
	    if (data && data.delta_res && data.delta_amt) {
	      showDelta(String(data.delta_res), parseInt(data.delta_amt,10)||0);
	    }
	    if (data && data.deltas && typeof data.deltas === 'object') {
	      try{
	        Object.keys(data.deltas).forEach(function(k){
	          showDelta(k, parseInt(data.deltas[k],10)||0);
	        });
	      }catch(_e3){}
	    }
    // keep in-memory state in sync if present
    try{
      if (window.currentState){
        if (data.gold != null) window.currentState.gold = parseInt(data.gold,10)||window.currentState.gold;
        if (data.elixir != null) window.currentState.elixir = parseInt(data.elixir,10)||window.currentState.elixir;
        if (data.gems != null) window.currentState.gems = parseInt(data.gems,10)||window.currentState.gems;
      }
    }catch(_e2){}
  };
}

	function applyBalanceWithDeltas(balance, deltas){
	  balance = balance || {};
	  deltas = deltas || {};
	  try{
	    if (window.applyBalanceUpdate) {
	      var payload = {};
	      Object.keys(balance).forEach(function(k){ payload[k] = balance[k]; });
	      payload.deltas = deltas;
	      window.applyBalanceUpdate(payload);
	      return;
	    }
	  }catch(_e){}
	}

// --- Walls UI helpers ---
  function setupWallsBulkUI(root){
    try{
      root = root || document;
      var panel = root.querySelector ? root.querySelector('[data-walls-bulk-panel]') : null;
      if (!panel) return;
      if (panel.getAttribute('data-walls-init') === '1') return;
      panel.setAttribute('data-walls-init','1');

      var lvlSel = panel.querySelector('#walls-bulk-level');
      var elixirMin = parseInt(panel.getAttribute('data-elixir-min')||'9',10) || 9;
      var btnElixir = panel.querySelector('[data-walls-bulk-v2][data-res="elixir"], [data-walls-bulk][data-res="elixir"]');
      var btnGold = panel.querySelector('[data-walls-bulk-v2][data-res="gold"], [data-walls-bulk][data-res="gold"]');

       // neutralize legacy inline handlers (avoid double modals/toasts)
      if (btnElixir){ try{ btnElixir.onclick = null; btnElixir.removeAttribute('onclick'); }catch(_e){} }
      if (btnGold){ try{ btnGold.onclick = null; btnGold.removeAttribute('onclick'); }catch(_e){} }

            var autoBtn = panel.querySelector('[data-walls-auto-v2], [data-walls-auto]');
      if (autoBtn){ try{ autoBtn.onclick = null; autoBtn.removeAttribute('onclick'); }catch(_e){} }

      function updateBtns(){
        var fromLevel = lvlSel ? (parseInt(lvlSel.value||'0',10) || 0) : 0;
        var next = fromLevel + 1;
        var allowElixir = (next >= elixirMin);
        if (btnElixir){
          btnElixir.disabled = !allowElixir;
          btnElixir.style.opacity = allowElixir ? '1' : '0.45';
          // avoid browser tooltip spam; we show toast on click
          btnElixir.title = '';
        }
        if (btnGold){
          btnGold.disabled = false;
          btnGold.style.opacity = '1';
        }
      }
      if (lvlSel){
        lvlSel.addEventListener('change', updateBtns);
        updateBtns();
      }
    }catch(_e){}
  }


  function ensureUpgradeProgressUI(el){
    if (!el) return;
    // Ensure bar + fill exist
    var bar = el.querySelector('.upgrade-progress-bar') || el.querySelector('.coc-progress');
    if (!bar) {
      // Build minimal structure expected by UI
      bar = document.createElement('div');
      bar.className = 'upgrade-progress-bar';
      el.insertBefore(bar, el.firstChild || null);
    }
    var fill = el.querySelector('.upgrade-progress-fill');
    if (!fill) {
      fill = document.createElement('div');
      fill.className = 'upgrade-progress-fill';
      bar.appendChild(fill);
    }

    // Ensure time node exists (ticking timer next to progress bar)
    var pctNode = el.querySelector('.upgrade-percent') || el.querySelector('.upgrade-progress-pct');
    if (!pctNode) {
      var meta = el.querySelector('.upgrade-progress-meta');
      if (!meta) {
        meta = document.createElement('div');
        meta.className = 'upgrade-progress-meta';
        el.appendChild(meta);
      }
      pctNode = document.createElement('span');
      pctNode.className = 'upgrade-percent';
      meta.appendChild(pctNode);
    }

    var timeNode = el.querySelector('.upgrade-left') || el.querySelector('.upgrade-progress-time');
    if (!timeNode) {
      var row2 = el.querySelector('.upgrade-progress-row') || bar.parentNode;
      timeNode = document.createElement('div');
      timeNode.className = 'upgrade-progress-time upgrade-left';
      row2.appendChild(timeNode);
    }
  }

  function uniqPush(arr, node){
    for (var i=0;i<arr.length;i++){ if (arr[i] === node) return; }
    arr.push(node);
  }

  function findBuildingIdForProgress(el){
    // Try to locate speedup button inside the same overlay/modal.
    var scope = el;
    // climb up to reasonable container
    for (var i=0; i<6 && scope && scope !== document.body; i++) scope = scope.parentNode;
    if (!scope) scope = document;

    var sp = scope.querySelector('[data-defspeedup]');
    if (sp) {
      var pbid = parseInt(sp.getAttribute('data-defspeedup')||'0',10) || 0;
      if (pbid) return pbid;
    }
    return 0;
  }

  function getOrInitTotal(pbid, left){
    if (!pbid) return left;
    var k = 'def_upg_total_' + pbid;
    var v = 0;
    try { v = parseInt(localStorage.getItem(k) || '0', 10) || 0; } catch(e) {}
    if (!v || v < left) {
      v = left;
      try { localStorage.setItem(k, String(v)); } catch(e2) {}
    }
    return v;
  }

  function startProgressUpdater(root){
    if (!root) root = document;

    // Start observer once: it captures progress blocks even if view was injected without calling loadView
    if (!progressObserverStarted) {
      progressObserverStarted = true;
      try {
        var mo = new MutationObserver(function(muts){
          for (var mi=0; mi<muts.length; mi++){
            var m = muts[mi];
            if (!m.addedNodes) continue;
            for (var ni=0; ni<m.addedNodes.length; ni++){
              var n = m.addedNodes[ni];
              if (!n || n.nodeType !== 1) continue;
              if (n.matches && n.matches('.js-upgrade-progress')) {
                uniqPush(progressNodes, n);
                ensureUpgradeProgressUI(n);
              }
              if (n.querySelectorAll) {
                var found = n.querySelectorAll('.js-upgrade-progress');
                for (var j=0; j<found.length; j++){
                  uniqPush(progressNodes, found[j]);
                  ensureUpgradeProgressUI(found[j]);
                }
              }
            }
          }
        });
        mo.observe(document.documentElement || document.body, {childList:true, subtree:true});
      } catch(_e) {}
    }

    // Collect nodes from current root
    var nodes = root.querySelectorAll ? root.querySelectorAll('.js-upgrade-progress') : [];
    for (var a=0; a<nodes.length; a++){
      uniqPush(progressNodes, nodes[a]);
      ensureUpgradeProgressUI(nodes[a]);
    }

    if (progressInterval) { clearInterval(progressInterval); progressInterval = null; }
    if (!progressNodes.length) return;

    function tick(){
      var now = Math.floor(Date.now()/1000);
      var anyDone = false;

      for (var i=0; i<progressNodes.length; i++) {
        var el = progressNodes[i];
        if (!el || !el.getAttribute) continue;

        // Prefer explicit start/end from backend
        var st = parseInt(el.getAttribute('data-start')||'0',10) || 0;
        var en = parseInt(el.getAttribute('data-end')||'0',10) || 0;

        // If no end in block, try to read from speedup button's data-deffinish
        if (!en) {
          var pbid2 = findBuildingIdForProgress(el);
          if (pbid2) {
            var scope2 = el.parentNode;
            while (scope2 && scope2 !== document.body && !scope2.querySelector('[data-defspeedup]')) scope2 = scope2.parentNode;
            var sp2 = scope2 ? scope2.querySelector('[data-defspeedup]') : null;
            if (sp2) en = parseInt(sp2.getAttribute('data-deffinish')||'0',10) || 0;
            if (en) el.setAttribute('data-end', String(en));
          }
        }

        if (!en) continue;

        var left = Math.max(0, en - now);

        // If start is unknown or invalid, compute progress from "initial remaining"
        var total = 0;
        if (st && en > st) total = en - st;
        else {
          var pbid = findBuildingIdForProgress(el);
          total = getOrInitTotal(pbid, left);
          // synthesize start so other code stays consistent
          st = en - total;
          el.setAttribute('data-start', String(st));
        }
        if (!total || total <= 0) total = Math.max(1, left);

        var done = total - left;
        var pct = Math.max(0, Math.min(100, (done/total)*100));

        var fill = el.querySelector('.upgrade-progress-fill');
        if (fill) fill.style.width = pct.toFixed(1) + '%';

        var leftNode = el.querySelector('.upgrade-left') || el.querySelector('.upgrade-progress-time');
        if (leftNode) leftNode.textContent = '⏳ ' + formatLeft(left);

        var pctNode = el.querySelector('.upgrade-percent') || el.querySelector('.upgrade-progress-pct');
        if (pctNode) pctNode.textContent = pct.toFixed(1) + '%';

        if (left <= 0) anyDone = true;
      }

      if (anyDone && !pendingRefresh) {
        pendingRefresh = true;
        setTimeout(function(){
          pendingRefresh = false;
          loadView(currentState.view, currentState.type, currentState.id, false, currentState.tab);
        }, 1200);
      }
    }

    tick();
    progressInterval = setInterval(tick, 1000);
  }


  function closeDefenseDetailOverlay(){
    var old = document.getElementById(overlayId);
    if (old && old.parentNode) old.parentNode.removeChild(old);
  }

  function openDefenseDetailOverlayHtml(html){
    ensureDefenseTabsStyles();
    closeDefenseDetailOverlay();
    var overlay = document.createElement('div');
    overlay.id = overlayId;
    overlay.className = 'coc-hmodal-overlay';
    overlay.innerHTML = html;
    document.body.appendChild(overlay);

    // close on background click
    overlay.addEventListener('click', function(e){
      if (e.target === overlay) closeDefenseDetailOverlay();
    });
    // close buttons
    overlay.addEventListener('click', function(e){
      var t = e.target;
      // any element with data-defmodalclose
      var c = t && (t.closest ? t.closest('[data-defmodalclose]') : null);
      if (c){
        e.preventDefault();
        e.stopPropagation();
        closeDefenseDetailOverlay();
      }
    });

    // apply balance updates + start progress updater inside overlay
    try{ applyBalancePayload(overlay); }catch(_e){}
    try{ startProgressUpdater(overlay); enhanceTabs(overlay); }catch(_e2){}

    // Sync speedup cost label with server quote (so button == confirm modal)
    try{
      var spBtn = overlay.querySelector('[data-defspeedup]');
      if (spBtn){
        var pbid = parseInt(spBtn.getAttribute('data-defspeedup')||'0',10) || 0;
        if (pbid){
          postJson({ action:'defense_speedup', player_building_id: pbid, quote: 1 }).then(function(q){
            var cost = parseInt(q.cost_gems,10) || 0;
            // update visible number
            var n = spBtn.querySelector('.coc-gem-cost');
            if (n) n.textContent = String(cost);
            // store for debug/consistency
            spBtn.setAttribute('data-defspeedupcost', String(cost));
          }).catch(function(){ /* ignore */ });
        }
      }
    }catch(_e3){}
  }

  function fetchOverlay(view, params){
    params = params || {};
    var url = ENDPOINT + '&view=' + encodeURIComponent(view);
    if (params.id) url += '&id=' + encodeURIComponent(params.id);
    if (params.type) url += '&type=' + encodeURIComponent(params.type);
    if (params.tab) url += '&tab=' + encodeURIComponent(params.tab);
    if (params.res) url += '&res=' + encodeURIComponent(params.res);
    return fetch(url, {credentials:'same-origin'}).then(function(r){
      return r.text().then(function(t){ return {ok:r.ok, status:r.status, html:t}; });
    });
  }

  function postJson(bodyObj){
    bodyObj = bodyObj || {};
    if (!bodyObj.csrf_token) bodyObj.csrf_token = getCsrfToken();
    var form = new URLSearchParams();
    Object.keys(bodyObj).forEach(function(k){
      if (bodyObj[k] === undefined || bodyObj[k] === null) return;
      form.append(k, String(bodyObj[k]));
    });
    return fetch(ENDPOINT, {
      method:'POST',
      credentials:'same-origin',
      headers:{
        'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With':'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      },
      body: form.toString()
    }).then(function(r){
      return r.json().then(function(d){
        if (!r.ok || !d || d.ok === false){
          var err = (d && d.error) ? d.error : ('HTTP ' + r.status);
          throw new Error(err);
        }
        // If server returned fresh balance — apply it to the global header immediately
        try{
          if (d && d.balance && window.applyBalanceUpdate) {
            window.applyBalanceUpdate(d.balance);
          } else if (d && window.applyBalanceUpdate && (d.gold!=null || d.elixir!=null || d.dark_elixir!=null || d.gems!=null)) {
            window.applyBalanceUpdate(d);
          }
        }catch(_eBal){}
        return d;
      });
    });
  }

  // --- Баланс в шапке (анимация/эффект списания, если есть applyBalanceUpdate) ---
  function refreshTopBalances(){
    return fetch('ajax.php?page=balance&r=' + Date.now(), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'X-CSRF-Token': getCsrfToken()
      }
    }).then(function(r){
      if (!r.ok) return null;
      return r.json().catch(function(){ return null; });
    }).then(function(data){
      if (data && window.applyBalanceUpdate) {
        try{ window.applyBalanceUpdate(data); }catch(_e){}
      }
      return data;
    }).catch(function(){ return null; });
  }

  function openDefenseBuildingModal(buildingRowId, tab){
    var id = parseInt(buildingRowId, 10) || 0;
    if (!id) return;
    fetchOverlay('detail', {id: id, tab: tab || currentState.tab}).then(function(res){
      if (!res.ok){
        // try to surface server-side error payload
        try{
          var tmp = document.createElement('div');
          tmp.innerHTML = res.html;
          if (window.gameHandleActionError) window.gameHandleActionError(tmp);
        }catch(_e){}
      }
      openDefenseDetailOverlayHtml(res.html);
        try{
          if (currentState.view === 'list' && (currentState.type === 'wall' || (tab || currentState.tab) === 'walls')){
            updateWallsListFragment({tab: tab || currentState.tab});
          }
        }catch(_e){}

    }).catch(function(){
      openDefenseDetailOverlayHtml('<div class="coc-um-modal coc-um-modal--unit" role="dialog" aria-modal="true">'+
        '<div class="coc-um-head">Оборона<button type="button" class="coc-um-x" data-defmodalclose="1">×</button></div>'+
        '<div class="coc-um-sub">Ошибка</div>'+
        '<div style="padding:14px;">Ошибка соединения</div>'+
      '</div>');
    });
  }

  function loadView(view, type, id, pushHistory, tab, extra){
    ensureDefenseTabsStyles();
    ensureModal();
    var els = getModalEls(MODAL_ID);
    if (!els.cont) return;

    if (pushHistory) historyStack.push({view: currentState.view, type: currentState.type, id: currentState.id, tab: currentState.tab});

    // запомним текущий HTML для восстановления при 4xx
    var prevHtml = els.cont.innerHTML;

    currentState.view = view || 'main';
    currentState.type = type || '';
    currentState.id = parseInt(id, 10) || 0;
    currentState.tab = tab || currentState.tab || 'ground';

    els.cont.innerHTML = loaderHtml();

    var url = ENDPOINT + '&view=' + encodeURIComponent(currentState.view);
    if (currentState.type) url += '&type=' + encodeURIComponent(currentState.type);
    if (currentState.id) url += '&id=' + encodeURIComponent(currentState.id);
    if (currentState.tab) url += '&tab=' + encodeURIComponent(currentState.tab);
    // optional extra query parameters (object or string like '&a=1')
    if (extra){
      if (typeof extra === 'string'){
        if (extra.charAt(0) !== '&') url += '&' + extra;
        else url += extra;
      } else {
        try{
          for (var k in extra){
            if (!Object.prototype.hasOwnProperty.call(extra,k)) continue;
            var v = extra[k];
            if (typeof v === 'undefined' || v === null) continue;
            url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(v));
          }
        }catch(_e){}
      }
    }

    fetch(url, {credentials: 'same-origin'})
      .then(function(r){ return r.text().then(function(t){ return {ok:r.ok, status:r.status, html:t}; }); })
      .then(function(res){
        if (!res.ok && res.status >= 400 && res.status < 500) {
          try {
            var tmp = document.createElement('div');
            tmp.innerHTML = res.html;
            if (window.gameHandleActionError) window.gameHandleActionError(tmp);
          } catch(e) {}
          if (prevHtml && prevHtml.length > 0) {
            els.cont.innerHTML = prevHtml;
            applyBalancePayload(els.cont);
            startProgressUpdater(els.cont);
            enhanceTabs(els.cont);
            setupWallsBulkUI(els.cont);
          } else {
            els.cont.innerHTML = res.html;
            applyBalancePayload(els.cont);
            startProgressUpdater(els.cont);
            enhanceTabs(els.cont);
            setupWallsBulkUI(els.cont);
          }
          return;
        }

        els.cont.innerHTML = res.html;
        applyBalancePayload(els.cont);
        startProgressUpdater(els.cont);
        enhanceTabs(els.cont);
        setupWallsBulkUI(els.cont);
      })
      .catch(function(){
        els.cont.innerHTML = ''
          + '<div class="modal-header-controls">'
          + '  <h2 class="modal-title-text-inside-panel">'+TITLE+'</h2>'
          + '  <button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'defenseModal\')">'
          + '    <img src="/images/icons/close.png" alt="Закрыть">'
          + '  </button>'
          + '</div>'
          + '<div class="modal-body-custom" style="padding:20px;">'
          + '  <div class="alert alert-danger">Ошибка соединения</div>'
          + '</div>';
      });
  }

  // Public API (используется в onclick из PHP)
    ensureDefenseSharedStyles();
    ensureDefenseExtrasStyles();

window.defenseOpen = function(){
    ensureDefenseSharedStyles();
    ensureDefenseExtrasStyles();
    var m = document.getElementById(MODAL_ID);
    if (m) m.classList.add('active');
    loadView('main', '', 0, false, 'ground');
  };

  window.defenseOpenTab = function(tab){
    var m = document.getElementById(MODAL_ID);
    if (m) m.classList.add('active');
    loadView('main', '', 0, true, tab || 'ground');
  };

  window.defenseLoadList = function(modalId, buildingType, tab){
    loadView('list', buildingType, 0, true, tab || currentState.tab);
  };

  window.defenseLoadDetail = function(modalId, buildingRowId, tab){
    // Legacy entry point: now opens Barracks-style overlay modal
    openDefenseBuildingModal(buildingRowId, tab || currentState.tab);
  };

  window.defenseStartBuilding = function(modalId, buildingType, tab){
    loadView('buy', buildingType, 0, false, tab || currentState.tab);
  };

  window.defenseStartUpgrade = function(modalId, buildingRowId, tab, res){
    // If detail overlay is open, upgrade inside the overlay and rerender it
    var id = parseInt(buildingRowId, 10) || 0;
    if (!id) return;
    var isOverlayOpen = !!document.getElementById(overlayId);
    if (isOverlayOpen){
      fetchOverlay('upgrade', {id: id, tab: tab || currentState.tab, res: res}).then(function(res){
        openDefenseDetailOverlayHtml(res.html);
        try{
          if (currentState.view === 'list' && currentState.type === 'wall'){
            updateWallsListFragment({tab: tab || currentState.tab});
          }
        }catch(_e){}

      }).catch(function(){
        // fallback: reload main view
        loadView('list', currentState.type, 0, false, tab || currentState.tab);
      });
      return;
    }
    loadView('upgrade', '', buildingRowId, false, tab || currentState.tab, (res ? {res: res} : null));
  };

  // Expose overlay opener for cards
  window.defenseOpenBuildingModal = openDefenseBuildingModal;

  // Walls list: update only list fragment (no full modal rerender)
  function updateWallsListFragment(opts){
    opts = opts || {};
    var tab = opts.tab || currentState.tab || 'walls';
    var wsort = opts.wsort || (function(){
      var btn = document.getElementById('walls-sort-toggle');
      return btn ? (btn.getAttribute('data-wsort') || 'asc') : 'asc';
    })(); 

    // Only when we are on walls list view
    if (currentState.view !== 'list' || currentState.type !== 'wall'){
      loadView('list', 'wall', 0, false, tab, {wsort:wsort});
      return;
    }

    var url = ENDPOINT + '&view=list&type=wall&partial=1&tab=' + encodeURIComponent(tab)
      + '&wsort=' + encodeURIComponent(String(wsort));

    var root = document.getElementById(CONTENT_ID);
    if (!root) return;
    var cardsHost = root.querySelector('#walls-cards');
    var prevScrollTop = 0;
    try{ prevScrollTop = cardsHost ? cardsHost.scrollTop : 0; }catch(_e){}
    var controlsHost = root.querySelector('#walls-controls');
    if (!cardsHost || !controlsHost){
      // fallback
      loadView('list', 'wall', 0, false, tab, {wsort:wsort});
      return;
    }

    // lightweight loading state
    try{ controlsHost.style.opacity = '0.75'; }catch(_e){}

    fetch(url, {credentials:'same-origin'})
      .then(function(r){ return r.text(); })
      .then(function(html){
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var frag = tmp.querySelector('[data-walls-fragment="1"]');
        if (!frag) throw new Error('bad fragment');
        var newControls = frag.querySelector('#walls-controls');
        var newCards = frag.querySelector('#walls-cards');
        if (!newControls || !newCards) throw new Error('bad fragment nodes');
        controlsHost.innerHTML = newControls.innerHTML;
        cardsHost.innerHTML = newCards.innerHTML;
        try{ cardsHost.scrollTop = prevScrollTop; }catch(_e){}
        // keep state consistent
        currentState.tab = tab;
        currentState.view = 'list';
        currentState.type = 'wall';
        // ensure tabs highlight
        enhanceTabs(root);
      })
      .catch(function(){
        loadView('list', 'wall', 0, false, tab, {wsort:wsort});
      })
      .finally(function(){
        try{ controlsHost.style.opacity = ''; }catch(_e2){}
      });
  }

  // Delegate clicks for new Barracks-like UI markup (data-def*)
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t) return;
// ===== Walls: bulk/auto upgrade =====
    var wb = t.closest ? t.closest('[data-walls-bulk-v2], [data-walls-bulk]') : null;
    if (wb){
      e.preventDefault();
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      var lvlSel = document.getElementById('walls-bulk-level');
      var qtySel = document.getElementById('walls-bulk-qty');
      if (!lvlSel || !qtySel) return;
      var fromLevel = parseInt(lvlSel.value||'0',10) || 0;
      var qty = parseInt(qtySel.value||'0',10) || 0;
      var res = wb.getAttribute('data-res') || 'gold';
      if (!fromLevel || !qty) return;

      wb.disabled = true;
      // Preview (server) -> Confirm -> Upgrade
      postJson({ action:'walls_bulk_preview', from_level: fromLevel, qty: qty, res: res }).then(function(p){
        if (!p || !p.ok) throw new Error((p && p.error) ? p.error : 'Ошибка');

        var allowed = Array.isArray(p.allowed_res) ? p.allowed_res : [];
        if (allowed.length && allowed.indexOf(res) === -1){
          var msg = (res === 'elixir') ? 'Эликсир доступен для стен только с 9 уровня.' : 'Этот ресурс недоступен для данного уровня стен.';
          toast('error','Стены', msg);
          return;
        }

        var qtyPossible = parseInt(p.qty_possible,10) || 0;
        var costEach = parseInt(p.cost_each,10) || 0;
        var total = qtyPossible * costEach;
        if (qtyPossible <= 0){
          toast('info','Стены','Недостаточно ресурсов или нет стен этого уровня для улучшения.');
          return;
        }

        var icon = '<img class="coc-ic" src="'+esc(resIcon(res))+'" alt="">';
        var html = ''
          + '<div style="font-weight:900;margin-bottom:8px;">Улучшение стен</div>'
          + '<div style="margin-bottom:8px;">Ур. <b>'+esc(String(p.from_level))+'</b> → <b>'+esc(String(p.to_level))+'</b></div>'
          + '<div style="display:flex;flex-direction:column;gap:6px;">'
          +   '<div style="display:flex;justify-content:space-between;gap:10px;"><span>Кол-во</span><b>'+esc(String(qtyPossible))+'</b></div>'
          +   '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;"><span>Цена за 1</span><span><b>'+esc(fmtNum(costEach))+'</b> '+icon+'</span></div>'
          +   '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;"><span>Итого</span><span><b>'+esc(fmtNum(total))+'</b> '+icon+'</span></div>'
          + '</div>';

        return defenseConfirm({
          title: 'ПОДТВЕРЖДЕНИЕ',
          html: html,
          okText: 'Подтвердить',
          cancelText: 'Отмена'
        }).then(function(ok){
          if (!ok) return;
          return postJson({ action:'walls_bulk_upgrade', from_level: fromLevel, qty: qtyPossible, res: res }).then(function(r){
            if (!r || !r.ok) throw new Error((r && r.error) ? r.error : 'Ошибка');
            var up = parseInt(r.upgraded,10) || 0;
            if (up > 0){
              toast('success','Стены','Улучшено: ' + up + ' | Потрачено: ' + fmtNum(parseInt(r.spent,10)||0));
            } else {
              toast('info','Стены', r.msg || 'Нечего улучшать');
            }
            // Update global header balances + spend effect
            try{
              var spent = parseInt(r.spent,10)||0;
              var d = {}; d[res] = -spent;
              applyBalanceWithDeltas(r.balance || {}, d);
            }catch(_e){}
            // keep caps/other counters in sync (if any)
            refreshTopBalances();
            loadView('list', 'wall', 0, false, currentState.tab || 'walls');
          });
        });
      }).catch(function(err){
        toast('error','Стены', (err && err.message) ? err.message : 'Ошибка улучшения');
      }).finally(function(){
        try{ wb.disabled = false; }catch(_e){}
      });
      return;
    }

    var wa = t.closest ? t.closest('[data-walls-auto-v2], [data-walls-auto]') : null;
    if (wa){
      e.preventDefault();
      e.stopPropagation();
      if (e.stopImmediatePropagation) e.stopImmediatePropagation();
      var prefSel = document.getElementById('walls-auto-pref');
      var pref = prefSel ? (prefSel.value || 'gold') : 'gold';
      var out = document.getElementById('walls-auto-result');

      wa.disabled = true;
      // Preview -> confirm -> upgrade
      postJson({ action:'walls_auto_preview', pref: pref }).then(function(p){
        if (!p || !p.ok) throw new Error((p && p.error) ? p.error : 'Ошибка');
        var upP = parseInt(p.upgraded,10) || 0;
        if (upP <= 0){
          toast('info','Стены','Недостаточно ресурсов или нечего улучшать');
          return;
        }

        var spentGP = (p.spent && p.spent.gold) ? parseInt(p.spent.gold,10)||0 : 0;
        var spentEP = (p.spent && p.spent.elixir) ? parseInt(p.spent.elixir,10)||0 : 0;
        var htmlC = ''
          + '<div style="font-weight:900;margin-bottom:8px;">Авто-улучшение стен</div>'
          + '<div style="margin-bottom:6px;">Будет улучшено стен: <b>'+esc(String(upP))+'</b></div>'
          + '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">'
          +   '<span style="display:flex;gap:6px;align-items:center;"><img src="/images/icons/gold.png" style="width:16px;height:16px;"> <b>'+esc(fmtNum(spentGP))+'</b></span>'
          +   '<span style="display:flex;gap:6px;align-items:center;"><img src="/images/icons/elixir.png" style="width:16px;height:16px;"> <b>'+esc(fmtNum(spentEP))+'</b></span>'
          + '</div>';

        return defenseConfirm({
          title: 'ПОДТВЕРЖДЕНИЕ',
          html: htmlC,
          okText: 'Подтвердить',
          cancelText: 'Отмена'
        }).then(function(ok){
          if (!ok) return;
          return postJson({ action:'walls_auto_upgrade', pref: pref }).then(function(r){
            if (!r || !r.ok) throw new Error((r && r.error) ? r.error : 'Ошибка');
            var up = parseInt(r.upgraded,10) || 0;
            if (up > 0) toast('success','Стены','Улучшено: ' + up);
            else toast('info','Стены','Недостаточно ресурсов или нечего улучшать');
            // Update global header balances + spend effect for both resources
            try{
              var sg = (r.spent && r.spent.gold) ? parseInt(r.spent.gold,10)||0 : 0;
              var se = (r.spent && r.spent.elixir) ? parseInt(r.spent.elixir,10)||0 : 0;
              var d2 = {};
              if (sg) d2.gold = -sg;
              if (se) d2.elixir = -se;
              applyBalanceWithDeltas(r.balance || {}, d2);
            }catch(_e){}
            refreshTopBalances();
        if (out){
          var html = '';
          if (up <= 0){
            html = 'Нечего улучшить (либо недостаточно ресурсов/Ратуши).';
          } else {
            var spentG = (r.spent && r.spent.gold) ? parseInt(r.spent.gold,10)||0 : 0;
            var spentE = (r.spent && r.spent.elixir) ? parseInt(r.spent.elixir,10)||0 : 0;
            html += '<div style="font-weight:900;margin-bottom:6px;">Готово!</div>';
            html += '<div>Улучшено стен: <b>'+esc(String(up))+'</b></div>';
            html += '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:2px;">'
                 +  '<span style="display:flex;gap:4px;align-items:center;"><img src="/images/icons/gold.png" style="width:16px;height:16px;" alt="Золото"> <b>'+esc(fmtNum(spentG))+'</b></span>'
                 +  '<span style="display:flex;gap:4px;align-items:center;"><img src="/images/icons/elixir.png" style="width:16px;height:16px;" alt="Эликсир"> <b>'+esc(fmtNum(spentE))+'</b></span>'
                 +'</div>';
            if (Array.isArray(r.steps) && r.steps.length){
              html += '<div style="margin-top:6px;opacity:.9;">Шаги:</div>';
              html += '<ul style="margin:6px 0 0 18px;">';
              r.steps.forEach(function(s){
                if (!s) return;
                html += '<li>Ур. '+esc(String(s.from))+' → '+esc(String(s.to))+': '+esc(String(s.qty))+' шт. ('+esc(s.res)+')</li>';
              });
              html += '</ul>';
            }
          }
          out.innerHTML = html;
          out.style.display = 'block';
        }
            loadView('list', 'wall', 0, false, currentState.tab || 'walls');
          });
        });
      }).catch(function(err){
        toast('error','Стены', (err && err.message) ? err.message : 'Ошибка');
      }).finally(function(){
        try{ wa.disabled = false; }catch(_e){}
      });
      return;
    }

    // Open list from main grid
    var listBtn = t.closest ? t.closest('[data-deflistbtn],[data-deflist]') : null;
    if (listBtn){
      var bid = listBtn.getAttribute('data-deflistbtn') || listBtn.getAttribute('data-deflist') || '';
      var tab = listBtn.getAttribute('data-tab') || currentState.tab;
      if (bid){
        e.preventDefault();
        e.stopPropagation();
        loadView('list', bid, 0, true, tab);
      }
      return;
    }

    // Build new slot
    var buildBtn = t.closest ? t.closest('[data-defbuildbtn],[data-defbuildtype]') : null;
    if (buildBtn){
      var btype = buildBtn.getAttribute('data-defbuildbtn') || buildBtn.getAttribute('data-defbuildtype') || '';
      var tab2 = buildBtn.getAttribute('data-tab') || currentState.tab;
      if (!btype) return;
      // disabled: show reason
      if (buildBtn.classList && buildBtn.classList.contains('is-disabled')){
        var msg = buildBtn.getAttribute('data-deflockmsg') || (buildBtn.closest('[data-deflockmsg]') ? buildBtn.closest('[data-deflockmsg]').getAttribute('data-deflockmsg') : '') || '';
        if (msg && window.showToast) window.showToast('info', 'Оборона', msg);
        e.preventDefault();
        e.stopPropagation();
        return;
      }
      if (buildBtn.disabled){
        e.preventDefault();
        e.stopPropagation();
        return;
      }
      e.preventDefault();
      e.stopPropagation();
      // CoC-style confirmation (like barracks speedup confirm, but with cost/time/description)
      var bname = buildBtn.getAttribute('data-defbname') || '';
      var bdesc = buildBtn.getAttribute('data-defbdesc') || '';
      var costHtml = buildBtn.getAttribute('data-defbcosthtml') || '';
      var tstr = buildBtn.getAttribute('data-defbtimestr') || '';
      var statsHtml = buildBtn.getAttribute('data-defbstatshtml') || '';
      var behHtml = buildBtn.getAttribute('data-defbbehaviorhtml') || '';

      // extra numeric cost for walls qty build
      var baseCost = parseInt(buildBtn.getAttribute('data-defbcostval')||'0',10) || 0;
      var resType = (buildBtn.getAttribute('data-defbres') || 'gold');
      var maxQty = parseInt(buildBtn.getAttribute('data-defbmaxcount')||'999999',10) || 999999;
      if (maxQty < 1) maxQty = 1;

      var qtyRow = '';
      var costRowHtml = costHtml;
      if (btype === 'wall' && baseCost > 0){
        qtyRow = ''
          + '<div class="coc-um-str" style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0;">'
          +   '<div style="opacity:.85;">Количество</div>'
          +   '<div style="font-weight:900;display:flex;align-items:center;gap:6px;">'
          +     '<input id="def-wall-qty" type="number" min="1" max="'+esc(String(maxQty))+'" value="1" '
          +       'style="width:88px;padding:6px 8px;border-radius:10px;border:2px solid rgba(0,0,0,.18);background:rgba(255,255,255,.7);font-weight:900;text-align:center;">'
          +     '<span style="opacity:.8;font-weight:900;">шт.</span>'
          +   '</div>'
          + '</div>';
        var icon = '<img class="coc-ic" src="'+esc(resIcon(resType))+'" alt="">';
        costRowHtml = '<span id="def-wall-total-cost">'+esc(fmtNum(baseCost))+'</span> ' + icon;
      }

      var html = ''
        + '<div style="font-size:13px; line-height:1.35;">'
        + (bdesc ? ('<div style="margin-bottom:10px; opacity:.95;">'+esc(bdesc)+'</div>') : '')
        + (statsHtml ? ('<div style="margin:10px 0 8px 0;">'+statsHtml+'</div>') : '')
        + (behHtml ? ('<div style="margin:8px 0 10px 0;">'+behHtml+'</div>') : '')
        + qtyRow
        + '<div class="coc-um-str" style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0;">'
        +   '<div style="opacity:.85;">Стоимость</div>'
        +   '<div style="font-weight:800;">'+costRowHtml+'</div>'
        + '</div>'
        + '<div class="coc-um-str" style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0;">'
        +   '<div style="opacity:.85;">Время</div>'
        +   '<div style="font-weight:800;">'+esc(tstr || '—')+'</div>'
        + '</div>'
        + '</div>';

      var pconf = defenseConfirm({
        title: (bname ? ('Построить: ' + bname) : 'Постройка'),
        html: html,
        okText: 'Построить',
        cancelText: 'Отмена'
      });

      // live-update total cost for walls
      if (btype === 'wall' && baseCost > 0){
        setTimeout(function(){
          var inp = document.getElementById('def-wall-qty');
          var out = document.getElementById('def-wall-total-cost');
          if (!inp) return;
          var upd = function(){
            var q = parseInt(inp.value||'1',10) || 1;
            if (q < 1) q = 1;
            if (q > maxQty) q = maxQty;
            inp.value = String(q);
            if (out) out.textContent = fmtNum(baseCost * q);
          };
          inp.addEventListener('input', upd);
          inp.addEventListener('change', upd);
          upd();
        }, 0);
      }

      pconf.then(function(ok){
        if (!ok) return;
        var q2 = 1;
        if (btype === 'wall' && baseCost > 0){
          var inp2 = document.getElementById('def-wall-qty');
          q2 = parseInt(inp2 && inp2.value ? inp2.value : '1', 10) || 1;
          if (q2 < 1) q2 = 1;
          if (q2 > maxQty) q2 = maxQty;
        }
        loadView('buy', btype, 0, false, tab2, (btype === 'wall' && q2 > 1) ? {qty:q2} : null);
      });
      return;
    }

    // Open a specific built building modal
    var infoBtn = t.closest ? t.closest('[data-definfo]') : null;
    if (infoBtn){
      var iid = infoBtn.getAttribute('data-definfo') || '';
      var itab = infoBtn.getAttribute('data-tab') || currentState.tab;
      if (iid){
        e.preventDefault();
        e.stopPropagation();
        openDefenseBuildingModal(iid, itab);
      }
      return;
    }

    var openBtn = t.closest ? t.closest('[data-defopenbtn],[data-defopen]') : null;
    if (openBtn){
      var id = openBtn.getAttribute('data-defopenbtn') || openBtn.getAttribute('data-defopen') || '';
      var tab3 = openBtn.getAttribute('data-tab') || currentState.tab;
      if (id){
        e.preventDefault();
        e.stopPropagation();
        openDefenseBuildingModal(id, tab3);
      }
      return;
    }

    // Upgrade inside overlay
    var upg = t.closest ? t.closest('[data-defupgrade]') : null;
    if (upg){
      if (upg.classList && upg.classList.contains('is-disabled')){
        var m2 = upg.getAttribute('data-deflockmsg') || '';
        if (m2 && window.showToast) window.showToast('info', 'Оборона', m2);
        e.preventDefault();
        e.stopPropagation();
        return;
      }
      var id2 = upg.getAttribute('data-defupgrade') || '';
      var tab4 = currentState.tab;
      e.preventDefault();
      e.stopPropagation();
      // Confirmation with cost/time/description
      var uname = upg.getAttribute('data-defuname') || '';
      var udesc = upg.getAttribute('data-defudesc') || '';
      var ustatsHtml = upg.getAttribute('data-defustatshtml') || '';
      var ubehHtml = upg.getAttribute('data-defubehaviorhtml') || '';
      var ucostHtml = upg.getAttribute('data-defucosthtml') || '';
      var utstr = upg.getAttribute('data-defutimestr') || '';
      var nxt = upg.getAttribute('data-defunextlvl') || '';
      var html2 = ''
        + '<div style="font-size:13px; line-height:1.35;">'
        + (udesc ? ('<div style="margin-bottom:8px; opacity:.95;">'+esc(udesc)+'</div>') : '')
        + (ustatsHtml ? ('<div style="margin:10px 0 8px 0;">'+ustatsHtml+'</div>') : '')
        + (ubehHtml ? ('<div style="margin:8px 0 10px 0;">'+ubehHtml+'</div>') : '')
        + (nxt ? ('<div style="margin-bottom:8px;"><b>Уровень:</b> '+esc(nxt)+'</div>') : '')
        + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0;">'
        +   '<div style="opacity:.85;">Стоимость</div>'
        +   '<div style="font-weight:800;">'+ucostHtml+'</div>'
        + '</div>'
        + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0;">'
        +   '<div style="opacity:.85;">Время</div>'
        +   '<div style="font-weight:800;">'+esc(utstr || '—')+'</div>'
        + '</div>'
        + '</div>';

      (function(){
      var isWall = (String(currentState.type||'') === 'wall') || (String(tab4||'') === 'walls');
      var nextLvl = parseInt(nxt,10) || 0;

      // Resource choice for walls must be derived from server/game config, not by parsing localized strings.
      var allowedStr = upg.getAttribute('data-defuallowedres') || '';
      var allowed = allowedStr.split(',').map(function(s){ return String(s||'').trim(); }).filter(Boolean);

      if (isWall){
        var goldAllowed = (allowed.indexOf('gold') !== -1);
        var elixirAllowed = (nextLvl >= 9) && (allowed.indexOf('elixir') !== -1);

        // Always show both buttons, but elixir is disabled until 9+ (and if server didn't allow it).
        var actions = [
          {
            html: '<img class="coc-ic" src="'+esc(resIcon('gold'))+'" alt=""> Улучшить',
            value: 'gold',
            className: 'coc-btn coc-btn-ok',
            disabled: !goldAllowed
          },
          {
            html: '<img class="coc-ic" src="'+esc(resIcon('elixir'))+'" alt=""> Улучшить' + (elixirAllowed ? '' : ' <span style="opacity:.75;font-weight:800;font-size:11px;">(с 9 ур.)</span>'),
            value: 'elixir',
            className: 'coc-btn coc-btn-ok',
            disabled: !elixirAllowed
          }
        ];

        defenseConfirm({
          title: (uname ? ('Улучшить: ' + uname) : 'Улучшение'),
          html: html2,
          actions: actions
        }).then(function(res){
          if (!res) return;
          window.defenseStartUpgrade(MODAL_ID, id2, tab4, res);
        });

      } else {
        defenseConfirm({
          title: (uname ? ('Улучшить: ' + uname) : 'Улучшение'),
          html: html2,
          okText: 'Улучшить',
          cancelText: 'Отмена'
        }).then(function(ok){
          if (!ok) return;
          window.defenseStartUpgrade(MODAL_ID, id2, tab4);
        });
      }
    })();
      return;
    }

    // Speedup inside overlay
    var sp = t.closest ? t.closest('[data-defspeedup]') : null;
    if (sp){
      var pbid = parseInt(sp.getAttribute('data-defspeedup')||'0',10) || 0;
      if (!pbid) return;
      var finishTs = parseInt(sp.getAttribute('data-deffinish')||'0',10) || 0;
      var left0 = finishTs ? Math.max(0, finishTs - Math.floor(Date.now()/1000)) : 0;
      e.preventDefault();
      e.stopPropagation();

      sp.disabled = true;

      postJson({ action:'defense_speedup', player_building_id: pbid, quote: 1 }).then(function(q){
        var cost = parseInt(q.cost_gems,10) || 0;
        var left = (typeof q.time_left !== 'undefined') ? (parseInt(q.time_left,10)||0) : left0;
        var html3 = ''
          + '<div style="font-size:13px; line-height:1.35;">'
          + '<div style="margin-bottom:8px; opacity:.95;">Ускорить строительство/улучшение за гемы?</div>'
          + '<div style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:6px 0;">'
          +   '<div style="opacity:.85;">Осталось</div>'
          +   '<div style="font-weight:800;">'+esc(formatLeft(left))+'</div>'
          + '</div>'
          + '</div>';

        return defenseConfirm({
          title: 'Ускорение',
          html: html3,
          cost: cost,
          costIconHtml: '<img class="coc-ic" src="'+esc(resIcon('gems'))+'" alt="">',
          okText: 'Ускорить',
          cancelText: 'Отмена'
        }).then(function(ok){
          if (!ok) return null;
          return postJson({ action:'defense_speedup', player_building_id: pbid });
        });
      }).then(function(r){
        if (!r) return;
        // refresh overlay detail
        return fetchOverlay('detail', {id: pbid, tab: currentState.tab}).then(function(res){
          openDefenseDetailOverlayHtml(res.html);
        try{
          if (currentState.view === 'list' && currentState.type === 'wall'){
            updateWallsListFragment({tab: tab || currentState.tab});
          }
        }catch(_e){}

          if (window.showToast){
            var spent = parseInt(r.cost_gems,10) || 0;
            window.showToast('success', 'Оборона', spent>0 ? ('Ускорено за ' + fmtNum(spent) + ' 💎') : 'Ускорено');
          }
        });
      }).catch(function(err){
        if (window.showToast) window.showToast('error', 'Оборона', (err && err.message) ? err.message : 'Ошибка ускорения');
      }).finally(function(){
        try{ sp.disabled = false; }catch(_e){}
      });
      return;
    }
  }, true);

  window.defenseGoBack = function(modalId, defaultView){
    if (!historyStack.length) {
      loadView(defaultView || 'main', '', 0, false, currentState.tab);
      return;
    }
    var prev = historyStack.pop();
    loadView(prev.view, prev.type, prev.id, false, prev.tab);
  };

  // Backward-compatible entry point (used by onclick in ajax.php)
  window.showDefenseModal = function(view){
    ensureModal();
    var m = document.getElementById(MODAL_ID);
    if (m) m.classList.add('active');
    var v = (view || 'main');
    if (v === 'main') {
      loadView('main', '', 0, false, currentState.tab || 'ground');
    } else {
      // allow passing 'main:air' etc.
      if (typeof v === 'string' && v.indexOf(':') !== -1) {
        var parts = v.split(':');
        if (parts[0] === 'main') {
          loadView('main', '', 0, false, parts[1] || 'ground');
          return;
        }
      }
      loadView(v, '', 0, false, currentState.tab || 'ground');
    }
  };


  // Walls: instant sort toggle without apply button
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t) return;
    var btn = t.closest ? t.closest('#walls-sort-toggle') : (t.id==='walls-sort-toggle'?t:null);
    if (!btn) return;
    e.preventDefault();
    e.stopPropagation();
    var cur = btn.getAttribute('data-wsort') || 'asc';
    var next = (cur === 'asc') ? 'desc' : 'asc';
    btn.setAttribute('data-wsort', next);
    var icon = document.getElementById('walls-sort-icon');
    if (icon) icon.textContent = (next === 'asc') ? '↑' : '↓';
    updateWallsListFragment({tab: btn.getAttribute('data-tab') || currentState.tab || 'walls', wsort: next});
  });

})();