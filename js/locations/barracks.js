// v13 marker: full file, do not truncate

;try{console.log('BARRACKS_UI_PATCH v20 loaded');}catch(e){}
(function(){
  'use strict';
  window.__SPELL_UI_BUILD__ = '2026-02-23T20:10Z'; console.info('SPELL_UI_BUILD', window.__SPELL_UI_BUILD__);
  // Heroes debug: enabled by default to help diagnose unlock/upgrade issues.
  // You can disable in console: window.__HERO_DEBUG__ = false
  if (typeof window.__HERO_DEBUG__ === 'undefined') window.__HERO_DEBUG__ = true;

  var MODAL_ID = 'barracks-modal';
  var CONTENT_ID = 'barracks-modal-content';

  function q(id){ return document.getElementById(id); }

  function esc(s){
    return String(s).replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
    });
  }


  // Number formatter (fallback if project global formatNumber is absent)
  function formatNumber(n){
    try{ if (typeof window.formatNumber === 'function' && window.formatNumber !== formatNumber) return window.formatNumber(n); }catch(_e){}
    var x = parseInt(n, 10);
    if (isNaN(x)) x = 0;
    // spaces as thousand separators (CoC-like)
    return String(x).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  // Update top resource bars (header.php balance-indicators) from API state.
  function syncBalanceIndicators(userRes){
    try{
      if (!userRes || typeof userRes !== 'object') return;
      var map = [
        { key:'gold' },
        { key:'elixir' },
        { key:'dark_elixir' },
        { key:'gems' }
      ];
      var caps = (window.BALANCE_CAPS && typeof window.BALANCE_CAPS === 'object') ? window.BALANCE_CAPS : {};
      for (var i=0;i<map.length;i++){
        var k = map[i].key;
        var v = parseInt(userRes[k],10) || 0;
        var textEl = document.getElementById('balance-' + k + '-text');
        if (textEl) textEl.textContent = formatNumber(v);
        var barEl = document.getElementById('balance-' + k + '-bar');
        if (!barEl) continue;
        var cap = parseInt(caps[k],10) || 0;
        var pct = 0;
        if (k === 'gems'){
          pct = v > 0 ? 100 : 0;
        } else if (cap > 0){
          pct = (v / cap) * 100;
          if (v > 0 && pct > 0 && pct < 1) pct = 1;
        }
        if (!isFinite(pct)) pct = 0;
        pct = Math.max(0, Math.min(100, pct));
        barEl.style.width = pct + '%';
      }
    }catch(_e){ /* silent */ }
  }

  // Stat formatter: show at most 1 decimal (no trailing .0)
  function formatStat(v){
    var n = (typeof v === 'string') ? parseFloat(v) : (typeof v === 'number' ? v : NaN);
    if (isNaN(n)) return '—';
    var r = Math.round(n * 10) / 10;
    if (Math.abs(r - Math.round(r)) < 1e-9) return String(Math.round(r)).replace(/\B(?=(\d{3})+(?!\d))/g,' ');
    return String(r.toFixed(1)).replace('.', '.').replace(/\B(?=(\d{3})+(?!\d))/g,' ');
  }

  function iconImg(name, alt){
    var p = '/images/icons/' + name;
    return '<img class="coc-ic" src="'+p+'" alt="'+(alt||'')+'">';
  }

  function resIconImg(resType){
    resType = String(resType||'').toLowerCase();
    if (resType === 'dark' || resType === 'dark_elixir') return iconImg('dark_elixir.png','DE');
    if (resType === 'elixir') return iconImg('elixir.png','E');
    if (resType === 'gold') return iconImg('gold.png','G');
    if (resType === 'gems' || resType === 'gem') return iconImg('gems.png','Gems');
    return '';
  }

  // CoC-like global max levels (independent of Hero Hall cap).
  // We show these as "общий максимум", and additionally show "(доступно до X)" from Hero Hall cap.
  var HERO_GLOBAL_MAX = {
    barbarian_king: 105,
    archer_queen: 105,
    minion_prince: 95,
    grand_warden: 80,
    royal_champion: 55
  };



// Normalize asset paths coming from backend (avoid /images//images/... and missing leading slash)
function normalizeImgPath(p){
  p = String(p || '').trim();
  if (!p) return p;

  // Some DB rows may contain paths with spaces instead of slashes (e.g. "images warriors barbarian avatar.png").
  // Heuristic: if it looks like an images path and has no slashes, convert spaces to slashes.
  if (p.indexOf('/') === -1 && /^images\s+/i.test(p)){
    p = p.replace(/\s+/g, '/');
  }

  // avoid accidental whitespace inside filenames (can break URLs)
  p = p.replace(/\s+/g, '');

  // drop protocol-relative duplicates
  while (p.indexOf('/images//') !== -1) p = p.replace('/images//', '/images/');
  // if path contains duplicated /images/ prefix (e.g. /images//images/spells/...)
  p = p.replace(/^\/images\/(\/images\/)+/i, '/images/');
  // if backend returns "images/..." without leading slash
  if (p.indexOf('images/') === 0) p = '/' + p;
  // if backend returns something like "/public/images/..." keep last "/images/..."
  var idx = p.toLowerCase().lastIndexOf('/images/');
  if (idx > 0) p = p.slice(idx);
  // encode spaces (shouldn't exist after trim+cleanup, but keep safe)
  try{ p = encodeURI(p); }catch(_){ }
  return p;
}


// Force-load images inside Barracks modal on tab switches.
// Fixes cases where images were previously loaded, but after re-render they remain blank
// (lazy-loading inside hidden/scroll containers can stall in some browsers/WebViews).
function kickBarracksImages(scope){
  try{
    if (!scope) return;

    // 1) Ensure lazy images actually start loading
    var imgs = scope.querySelectorAll('img');
    for (var i=0; i<imgs.length; i++){
      var img = imgs[i];
      try{
        if (!img) continue;

        // Don't interfere with "late-bind" building thumbnails; handled by scheduleBuildingsImgLoad().
        if (img.classList && img.classList.contains('coc-bimg-late') && img.getAttribute('data-src')) continue;

        // Kick lazy-loading (especially in overflow containers / after display:none).
        if ((img.getAttribute('loading') || '').toLowerCase() === 'lazy'){
          try{ img.loading = 'eager'; }catch(_e0){}
        }

        // If image is in a broken state (complete but 0 naturalWidth), force retry with cache-buster.
        if (img.complete && img.naturalWidth === 0){
          var src = img.currentSrc || img.src || '';
          if (!src) continue;
          if (src.indexOf('data:') === 0) continue;

          // Avoid infinite busting loops
          if (String(img.getAttribute('data-retry')||'') === '1') continue;
          img.setAttribute('data-retry','1');

          var t = Date.now();
          var busted = src.replace(/([?&])r=\d+/,'$1r='+t);
          if (busted === src){
            busted = src + (src.indexOf('?') >= 0 ? '&' : '?') + 'r=' + t;
          }
          img.src = busted;
        }
      }catch(_e1){}
    }

    // 2) Background-image tiles: if bg not applied, re-run fallback loader.
    try{
      var bgEls = scope.querySelectorAll('.coc-simg[data-bg]');
      for (var j=0; j<bgEls.length; j++){
        var el = bgEls[j];
        if (!el) continue;
        var bg = '';
        try{ bg = (el.style && el.style.backgroundImage) ? String(el.style.backgroundImage) : ''; }catch(_e2){ bg = ''; }
        if (!bg || bg === 'none'){
          // allow applyBgFallback() to process again
          try{ el.__bgDone = false; }catch(_e3){}
        }
      }
      applyBgFallback(scope);
    }catch(_e4){}
  }catch(_e){}
}


function ensureToastOnTop(){
  try{
    var el = document.querySelector('.game-toast-container');
    if (!el) el = document.getElementById('game-toast-container');
    if (!el) return;

    // Move to the end of <body> to avoid being covered by modal overlays / stacking contexts.
    if (document.body && el.parentNode !== document.body){
      document.body.appendChild(el);
    } else if (document.body && el.nextSibling){
      document.body.appendChild(el);
    }

    // Do NOT touch positioning (top/bottom/left/right). Only guarantee it renders above overlays.
    el.style.zIndex = '2147483647';

    ensureToastStyles();
  }catch(_){}
}

function ensureToastStyles(){
  try{
    if (ensureToastStyles._done) return;
    ensureToastStyles._done = true;

    var css = ''
      + '.game-toast-container, #game-toast-container{ z-index:2147483647 !important; pointer-events:none !important; }'
      + '.game-toast-container > *, #game-toast-container > *, '
      + '.game-toast-container .toast, #game-toast-container .toast, '
      + '.game-toast-container .game-toast, #game-toast-container .game-toast, '
      + '.game-toast-container .toast-item, #game-toast-container .toast-item{'
      + ' display:flex !important; flex-direction:row !important; align-items:center !important; gap:6px; }'
      + '.game-toast-container > * > :first-child, #game-toast-container > * > :first-child, '
      + '.game-toast-container .toast > :first-child, #game-toast-container .toast > :first-child, '
      + '.game-toast-container .game-toast > :first-child, #game-toast-container .game-toast > :first-child, '
      + '.game-toast-container .toast-item > :first-child, #game-toast-container .toast-item > :first-child{'
      + ' flex:0 0 auto; white-space:nowrap; }'
      + '.game-toast-container > * > :last-child, #game-toast-container > * > :last-child, '
      + '.game-toast-container .toast > :last-child, #game-toast-container .toast > :last-child, '
      + '.game-toast-container .game-toast > :last-child, #game-toast-container .game-toast > :last-child, '
      + '.game-toast-container .toast-item > :last-child, #game-toast-container .toast-item > :last-child{'
      + ' flex:1 1 auto; min-width:0; word-break:break-word; white-space:normal; }'
      + '.game-toast-container span, .game-toast-container div, #game-toast-container span, #game-toast-container div{ box-sizing:border-box; } .game-toast-container > *, #game-toast-container > *, .game-toast-container .toast, #game-toast-container .toast, .game-toast-container .game-toast, #game-toast-container .game-toast, .game-toast-container .toast-item, #game-toast-container .toast-item{ pointer-events:auto !important; }';

        css += '.coc-queue-panel{ margin-top:10px; }';
        // Make spells catalog block a bit more compact
        // Spells catalog: no scroll, compact height
        /* Spells UI should match troops grid (same columns, padding, gaps) */
        css += '.coc-spell-blue-wrap{ padding:10px; width:100%; box-sizing:border-box; }';
        /* Spells grid: identical to troop grid */
        css += '#coc-spell-grid{ overflow:visible; max-height:none; padding:10px; display:grid; grid-template-columns:repeat(4, 78px); gap:10px; justify-content:center; justify-items:center;  width:100%; box-sizing:border-box; }';
        css += '#coc-spell-grid .coc-tile{ margin:0; width:78px; aspect-ratio:1/1; height:auto; min-width:0; }';
        css += '#coc-spell-grid .coc-tile img{ width:100%; height:100%; object-fit:cover; margin:0; }';

        css += '.coc-panel .coc-panel-title.is-lower{ text-transform:none; font-weight:600; }';
var style = document.createElement('style');
    style.type = 'text/css';
    style.appendChild(document.createTextNode(css));
    document.head.appendChild(style);
  }catch(_){}
}


function ensureBuildingsStyles(){
  if (document.getElementById('barracks-buildings-style')) return;

  var st = document.createElement('style');
  st.id = 'barracks-buildings-style';
  st.textContent = `
/* Buildings / Постройки */

.coc-building-head{
  display:flex;
  flex-direction:column;
  align-items:center;
  gap:4px;
}
.coc-building-title{
  text-align:center;
  width:100%;
  font-weight:900;
  justify-content:center;
}
.coc-building-sub{display:none;}

.coc-bslots{
  display:flex;
  flex-wrap:wrap;
  gap:10px;
  justify-content:center;
  max-width:720px;
  margin:0 auto;
}

.coc-bslot{
  position:relative;
  flex:0 0 calc(33.333% - 10px);
  max-width:calc(33.333% - 10px);
  min-width:0;
  background: rgb(209 209 209 / 75%);
  border:2px solid rgba(0,0,0,.15);
  border-radius:10px;
  padding:38px 8px 46px; /* место под название */
  text-align:center;
}

/* Название постройки — плашка, "сливается" с карточкой */
.coc-bname{
  position:absolute;
  left:-2px;
  right:-2px;
  top:-2px;
  padding:5px 0px 5px 0px;
  font-weight:900;
  font-size:11px;
  /* letter-spacing:.4px; */
  text-transform:uppercase;
  color:#564a3c;
  background:linear-gradient(180deg, rgba(255,255,255,.95), rgba(235,231,223,.92));
  border:2px solid rgba(0,0,0,.15);
  border-bottom:2px solid rgba(0,0,0,.18);
  border-radius:0px 0px 6px 6px;
  box-sizing:border-box;
  text-shadow:0 1px 0 rgba(255,255,255,.7);
}

.coc-bslot img{
  width:100px;
  height:100px;
  object-fit:contain;
  display:block;
  margin: -10px auto 8px;
}

.coc-bmore-wrap{ width:100%; display:flex; justify-content:center; margin:12px 0 4px; }
.coc-bmore{
  padding:10px 14px;
  border-radius:10px;
  border:2px solid rgba(0,0,0,.18);
  background:linear-gradient(180deg, rgba(255,255,255,.95), rgba(235,231,223,.92));
  font-weight:900;
  color:#564a3c;
}

.coc-info.coc-info-bld{
  position:absolute;
  right:6px;
  top:6px;
  width:28px;
  height:28px;
  border-radius:8px;
  border:2px solid rgba(0,0,0,.25);
  background:#e9e5df;
  font-weight:900;
  cursor:pointer;
}

.coc-bbadge{
  position:absolute;
  left:6px;
  bottom:46px;
  pointer-events:none;
  background:#2f2f2f;
  color:#fff;
  border-radius:6px;
  padding:4px 7px;
  font-size:11px;
  font-weight:900;
}

.coc-bslot.is-empty{opacity:.85;}

@media (max-width: 860px){
  .coc-bslot{
    flex-basis:calc(33.333% - 10px);
    max-width:calc(33.333% - 10px);
  }
}

@media (max-width: 520px){
  .coc-bslot{
    flex-basis:calc(33.333% - 10px);
    max-width:calc(33.333% - 10px);
  }
  .coc-bslot img{
    width:80px;
    height:80px;
  }
}

/* ⏱ Таймер поверх картинки (компактный) */
.coc-btimer-overlay{
  position:absolute;
  pointer-events:none;
  left:50%;
  top:58%;                 /* ниже, ближе к центру иконки */
  transform:translate(-50%,-50%);
  background:rgba(0,0,0,.65);
  color:#fff;
  border-radius:8px;
  padding:3px 8px;
  font-weight:900;
  font-size:11px;
  line-height:14px;
  pointer-events:none;
  z-index:3;
  text-shadow:0 1px 0 rgba(0,0,0,.35);
}
.coc-btimer-overlay.is-hidden{display:none;}
.coc-btimer-overlay.is-pop{animation:cocBTimerPop .14s ease-out;}
@keyframes cocBTimerPop{
  0%{transform:translate(-50%,-50%) scale(.85); opacity:.6;}
  50%{transform:translate(-50%,-50%) scale(1.12); opacity:1;}
  100%{transform:translate(-50%,-50%) scale(1); opacity:1;}
}

/* Hold badge on troop tile */
.coc-holdbadge{
  position:absolute;
  left:50%;
  top:56%;
  transform:translate(-50%,-50%);
  background:rgba(0,0,0,.70);
  color:#fff;
  border-radius:999px;
  padding:2px 7px;
  font-weight:900;
  font-size:11px;
  line-height:14px;
  z-index:4;
  pointer-events:none;
  text-shadow:0 1px 0 rgba(0,0,0,.35);
}
.coc-holdbadge.is-hidden{display:none;}
.coc-holdbadge.is-pop{
  animation:cocHoldPop .14s ease-out;
}
@keyframes cocHoldPop{
  0%{transform:translate(-50%,-50%) scale(.85); opacity:.6;}
  100%{transform:translate(-50%,-50%) scale(1); opacity:1;}
}

/* Building detail overlay (CoC-style) */
.coc-bdetail-overlay{
  position:fixed;
  top:0;left:0;right:0;bottom:0;
  background:rgba(0,0,0,.45);
  z-index:999999;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:10px;
}

.coc-bdetail-modal{
  width:min(520px,96vw);
  max-height:min(86vh,720px);
  background:#efe9dd;
  border:4px solid rgba(0,0,0,.25);
  border-radius:16px;
  box-shadow:0 20px 60px rgba(0,0,0,.35);
  overflow:hidden;
  display:flex;
  flex-direction:column;
}

.coc-bdetail-head{
  position:relative;
  background:#6c625b;
  color:#fff;
  padding:10px 46px 10px 14px;
  font-weight:900;
  font-size:20px;
  text-align:center;
  text-shadow:0 2px 0 rgba(0,0,0,.35);
}

.coc-bdetail-close{
  position:absolute;
  right:10px;
  top:8px;
  width:34px;
  height:34px;
  border-radius:10px;
  border:2px solid rgba(0,0,0,.25);
  background:#d54;
  cursor:pointer;
  color:#fff;
  font-weight:900;
  line-height:30px;
}

.coc-bdetail-body{
  padding:12px;
  overflow:auto;
}
.coc-bdetail-body::-webkit-scrollbar{width:0;height:0;}

.coc-bdetail-top{
  display:flex;
  gap:12px;
  align-items:flex-start;
}

.coc-bdetail-img{
  flex:0 0 190px;
  max-width:190px;
  background:rgba(255,255,255,.5);
  border:2px solid rgba(0,0,0,.12);
  border-radius:14px;
  padding:6px;
  display:flex;
  align-items:center;
  justify-content:center;
}

.coc-bdetail-img img{
  width:100%;
  height:170px;
  object-fit:contain;
  display:block;
}

.coc-bdetail-info{flex:1;min-width:0;}

.coc-bdetail-sub{
  font-weight:900;
  margin-bottom:8px;
}

.coc-bdetail-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:6px;
}

.coc-bdetail-tile{
  background:rgba(0,0,0,.03);
  border:1px solid rgba(0,0,0,.10);
  border-radius:10px;
  padding:6px;
}

.coc-bdetail-k{
  font-size:9px;
  font-weight:900;
  opacity:.8;
}

.coc-bdetail-v{
  margin-top:2px;
  font-size:11px;
  font-weight:900;
}

/* Highlight missing requirements (upgrade) */
.coc-need-bad{
  border-color: rgba(210, 30, 30, .55) !important;
  background: rgba(210, 30, 30, .08) !important;
}
.coc-need-bad .coc-bdetail-k,
.coc-need-bad .coc-bdetail-v{
  color: #b11;
}

.coc-bdetail-block{
  margin-top:10px;
  background:rgba(255,255,255,.55);
  border:2px solid rgba(0,0,0,.12);
  border-radius:14px;
  padding:10px;
}

.coc-bdetail-bt{
  font-weight:900;
  margin-bottom:6px;
}

.coc-bdetail-actions{
  display:flex;
  gap:10px;
  padding:12px;
  border-top:2px solid rgba(0,0,0,.12);
  background:rgba(0,0,0,.05);
}

.coc-bdetail-actions .coc-speedup-btn{flex:1;}

@media (max-width:480px){
  .coc-bdetail-top{
    flex-direction:column;
    align-items:center;
  }
  .coc-bdetail-img{
    max-width:240px;
    width:240px;
  }
  /* телефон: время + открывает — 2 в ряд, здоровье — на всю ширину */
  .coc-bdetail-grid{
    grid-template-columns:1fr 1fr;
  }
  .coc-bdetail-grid .meta-hp{
    grid-column:1 / -1;
  }
}

/* Progress */
.coc-progress{
  height:12px;
  border-radius:10px;
  background:rgba(0,0,0,.12);
  overflow:hidden;
  border:1px solid rgba(0,0,0,.12);
}
.coc-progress > div{
  height:100%;
  width:0%;
  background:linear-gradient(90deg,#6fda45,#2ea43a);
  transition:width .35s ease;
}
.coc-progress-indeterminate > div{
  width:35%;
  animation:cocInd 1.2s infinite;
}
@keyframes cocInd{
  0%{transform:translateX(-120%);}
  100%{transform:translateX(320%);}
}
/* ===== CoC Original-ish skin overrides (spell & troop detail modal) ===== */
.coc-bdetail-modal{
  background:linear-gradient(#f8f2e6,#e8d6bd);
  border:5px solid #4b2f1a;
  box-shadow:0 22px 70px rgba(0,0,0,.45), inset 0 0 0 2px rgba(255,255,255,.35);
}
.coc-bdetail-head{
  background:linear-gradient(#7c4c2a,#5a321b);
  border-bottom:3px solid rgba(0,0,0,.18);
  letter-spacing:.3px;
}
.coc-bdetail-head:before{
  content:"";
  position:absolute;
  top:0;left:0;right:0;bottom:0;
  background:linear-gradient(rgba(255,255,255,.22), rgba(255,255,255,0) 55%);
  pointer-events:none;
}
.coc-bdetail-close{
  background:linear-gradient(#ff6b5a,#c83022);
  border:2px solid rgba(0,0,0,.25);
  box-shadow:0 3px 0 rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.25);
}
.coc-bdetail-close:active{ transform:translateY(1px); box-shadow:0 2px 0 rgba(0,0,0,.25), inset 0 1px 0 rgba(255,255,255,.20); }

.coc-bdetail-img{
  background:linear-gradient(rgba(255,255,255,.85),rgba(255,255,255,.55));
  border:2px solid rgba(75,47,26,.20);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.6);
}
.coc-bdetail-tile{
  background:linear-gradient(#fff7ea,#f0e1c9);
  border:2px solid rgba(75,47,26,.18);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.6);
}
.coc-bdetail-k{
  font-size:9px;
  text-transform:uppercase;
  letter-spacing:.25px;
  opacity:.85;
}
.coc-bdetail-v{
  font-size:15px;
}
.coc-bdetail-block{
  background:linear-gradient(rgba(255,255,255,.78), rgba(255,255,255,.52));
  border:2px solid rgba(75,47,26,.16);
  box-shadow:inset 0 1px 0 rgba(255,255,255,.55);
}

.coc-bdetail-actions{
  background:linear-gradient(rgba(0,0,0,.05), rgba(0,0,0,.08));
}
.coc-bdetail-modal .coc-speedup-btn{
  border-radius:16px !important;
  font-weight:900 !important;
  font-size:16px !important;
  text-shadow:0 2px 0 rgba(0,0,0,.20);
  box-shadow:0 4px 0 rgba(0,0,0,.20), inset 0 1px 0 rgba(255,255,255,.28);
}
.coc-bdetail-modal .coc-speedup-btn:not(.coc-btn-gray){
  background:linear-gradient(#7cf05a,#2ea43a) !important;
  border:2px solid rgba(0,0,0,.18) !important;
}
.coc-bdetail-modal .coc-speedup-btn.coc-btn-gray{
  background:linear-gradient(#e6e0d6,#bfb4a5) !important;
  border:2px solid rgba(0,0,0,.15) !important;
}
.coc-bdetail-modal .coc-speedup-btn:disabled{
  filter:grayscale(.25);
  opacity:.70;
}

`;

  document.head.appendChild(st);
}



// --- API helpers (backend) --- (backend) ---
var API_URL = '/app/army_api.php';

function getCsrfToken(){
  try{
    // common globals
    if (window.APP_CONFIG){
      if (window.APP_CONFIG.csrfToken) return String(window.APP_CONFIG.csrfToken);
      if (window.APP_CONFIG.csrf_token) return String(window.APP_CONFIG.csrf_token);
    }
    if (window.csrfToken) return String(window.csrfToken);
    if (window.CSRF_TOKEN) return String(window.CSRF_TOKEN);
    if (window.csrftoken) return String(window.csrftoken);
    if (window.csrf_token) return String(window.csrf_token);
    if (window.csrf) return String(window.csrf);

    // common meta tags
    var meta = document.querySelector('meta[name="csrf_token"],meta[name="csrf-token"],meta[name="csrf"],meta[name="_csrf"]');
    if (meta && meta.content) return String(meta.content);

    // hidden input
    var inp = document.querySelector('input[name="csrf_token"],input[name="csrf"],input[name="_csrf"]');
    if (inp && inp.value) return String(inp.value);

    // cookie (if used)
    var m = document.cookie.match(/(?:^|;\s*)(csrf_token|csrf|_csrf)=([^;]+)/);
    if (m) return decodeURIComponent(m[2] || '');
  }catch(_){}
  return '';
}

function setCsrfToken(t){
  try{
    if (!t) return;
    if (window.APP_CONFIG){ window.APP_CONFIG.csrfToken = t; window.APP_CONFIG.csrf_token = t; }
    window.csrf_token = t;
    window.csrftoken = t;
    var meta = document.querySelector('meta[name="csrf_token"]');
    if (!meta){
      meta = document.createElement('meta');
      meta.name = 'csrf_token';
      document.head.appendChild(meta);
    }
    meta.content = t;
  }catch(_){}
}


  // --- CoC-style confirm modal (no browser confirm/alert) ---
  function ensureCoCConfirmUI(){
    if (document.getElementById('coc-confirm-overlay')) return;
    var ov = document.createElement('div');
    ov.id = 'coc-confirm-overlay';
    ov.className = 'coc-confirm-overlay is-hidden';
    // гарантируем что подтверждение (ускорение) поверх ВСЕХ модалок
    ov.style.position = 'fixed';
    ov.style.left = '0';
    ov.style.top = '0';
    ov.style.right = '0';
    ov.style.bottom = '0';
    ov.style.zIndex = '2147483000';
    ov.style.transform = 'translateZ(0)';
    ov.style.webkitTransform = 'translateZ(0)';
    ov.innerHTML = ''+
      '<div class="coc-confirm-panel" role="dialog" aria-modal="true">'+
        '<div class="coc-confirm-title" id="coc-confirm-title"></div>'+
        '<div class="coc-confirm-body" id="coc-confirm-body"></div>'+
        '<div class="coc-confirm-cost" id="coc-confirm-cost"></div>'+
        '<div class="coc-confirm-actions">'+
          '<button class="coc-btn coc-btn-cancel" type="button" id="coc-confirm-cancel">Отмена</button>'+
          '<button class="coc-btn coc-btn-ok" type="button" id="coc-confirm-ok">Да</button>'+
        '</div>'+
      '</div>';
    document.body.appendChild(ov);

    function close(res){
      try{ ov.classList.add('is-hidden'); }catch(e){}
      var cb = ov._cocResolve; ov._cocResolve = null;
      if (cb) cb(res);
    }

    ov.addEventListener('click', function(e){
      if (e.target === ov) close(false);
    });
    document.getElementById('coc-confirm-cancel').addEventListener('click', function(){ close(false); });
    document.getElementById('coc-confirm-ok').addEventListener('click', function(){ close(true); });
    document.addEventListener('keydown', function(e){
      if (ov.classList.contains('is-hidden')) return;
      if (e.key === 'Escape') close(false);
    });
  }

  // options: {title, text, cost, costIconHtml, okText, cancelText}
  window.cocConfirm = function(options){
    ensureCoCConfirmUI();
    options = options || {};
    var ov = document.getElementById('coc-confirm-overlay');
    var t = document.getElementById('coc-confirm-title');
    var b = document.getElementById('coc-confirm-body');
    var c = document.getElementById('coc-confirm-cost');
    t.textContent = options.title || 'Подтверждение';
    b.textContent = options.text || '';
    var cost = (typeof options.cost !== 'undefined') ? (parseInt(options.cost,10)||0) : 0;
    var icon = options.costIconHtml || resIconImg('gems');
    if (cost > 0){
      c.innerHTML = '<span class="coc-confirm-cost-label">Стоимость:</span> <span class="coc-confirm-cost-val">'+ esc(formatNumber(cost)) +'</span> ' + icon;
      c.style.display = '';
    } else {
      c.textContent = '';
      c.style.display = 'none';
    }
    try{
      document.getElementById('coc-confirm-ok').textContent = options.okText || 'Да';
      document.getElementById('coc-confirm-cancel').textContent = options.cancelText || 'Отмена';
    }catch(e){}

    // ensure confirm overlay is always on top (some mobile/desktop modals bump z-index after we open)
    try{
      // move to the end of <body> to be the last stacking candidate
      document.body.appendChild(ov);
      var maxZ = 0;
      var nodes = document.querySelectorAll('[style*="z-index"], .modal, .popup, .coc-modal, .coc-modal-overlay, .overlay, .popup_overlay, .modal_overlay');
      for (var i=0;i<nodes.length;i++){
        if (nodes[i] === ov) continue;
        var z = 0;
        try{
          var cs = window.getComputedStyle ? getComputedStyle(nodes[i]) : null;
          var zv = cs ? cs.zIndex : (nodes[i].style ? nodes[i].style.zIndex : '');
          if (zv && zv !== 'auto'){
            z = parseInt(zv,10) || 0;
          }
        }catch(e){}
        if (z > maxZ) maxZ = z;
      }
      // keep in int range; our default is already huge
      var targetZ = Math.max(2147483000, maxZ + 50);
      ov.style.zIndex = String(targetZ);
    }catch(e){}

    try{ ov.classList.remove('is-hidden'); }catch(e){}
    return new Promise(function(resolve){
      ov._cocResolve = resolve;
    });
  };

function showBarracksToast(a,b,c){
  try{
    if (!window.gameToast) return;

    var type='info', title='', msg='';
    // Supported call patterns in legacy code:
    // 1) showBarracksToast(type, title, msg)
    // 2) showBarracksToast(msg, type)
    // 3) showBarracksToast(msg)
    // 4) showBarracksToast(type, msg)  (no title)
    var isType = function(x){
      if (!x) return false;
      x = String(x).toLowerCase();
      return (x==='err'||x==='error'||x==='warn'||x==='warning'||x==='ok'||x==='success'||x==='info');
    };

    if (isType(a)){
      type = (String(a).toLowerCase()==='err') ? 'error' : String(a).toLowerCase();
      if (typeof b==='string' && typeof c==='string'){ title=b; msg=c; }
      else if (typeof b==='string'){ msg=b; }
    } else {
      // first arg is message
      msg = (a!=null? String(a):'');
      if (isType(b)) type = (String(b).toLowerCase()==='err') ? 'error' : String(b).toLowerCase();
    }

    if (type==='warn') type='warning';

    // build full text for 1-arg/2-arg toast apis
    var text = (title ? (title + (msg?': ':'') ) : '') + (msg||'');
    if (/Этот юнит отсутствует в армии\./.test(text) || /Этот юнит отсутствует в армии/.test(text)) return;
    if (/Недостаточно места для заклинаний\.?/i.test(text)) return;

    // Rate-limit toasts (mobile long-press can generate many errors and freeze the UI)
    try{
      var __rl = window.__COC_BARRACKS_TOAST_RL || (window.__COC_BARRACKS_TOAST_RL = { lastText:'', lastAt:0, winAt:0, winCount:0 });
      var __now = Date.now();
      if (__now - (__rl.winAt||0) > 1000){ __rl.winAt = __now; __rl.winCount = 0; }
      __rl.winCount++;
      // hard cap: no more than 3 toasts per second total
      if (__rl.winCount > 3) return;
      // duplicate cap: same text within 900ms
      if (text === __rl.lastText && (__now - (__rl.lastAt||0)) < 900) return;
      __rl.lastText = text;
      __rl.lastAt = __now;
    }catch(_e){}
    try{
      if (typeof window.gameToast === 'function'){
        if (window.gameToast.length >= 3){
          window.gameToast(type, title || '', msg || '');
        } else if (window.gameToast.length === 2){
          window.gameToast(text, type);
        } else {
          window.gameToast(text);
        }
        setTimeout(ensureToastOnTop,0);
        return;
      }
    }catch(_){}
    window.gameToast(text);
    setTimeout(ensureToastOnTop,0);
  }catch(_){}
}

function updateCsrfFromResponse(res){
  try{
    var t = res && (res.headers.get('X-CSRF-Token') || res.headers.get('X-Csrf-Token'));
    if (t) setCsrfToken(t);
  }catch(_){}
}

function safeJson(res){
  return res.text().then(function(t){
    try{ return JSON.parse(t); } catch(e){ return { ok:false, error:'Некорректный ответ сервера', raw:t }; }
  });
}

function apiGet(action){
  var url = API_URL + '?action=' + encodeURIComponent(action || '');
  return fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': getCsrfToken()
    }
  }).then(function(res){
    updateCsrfFromResponse(res);
    return safeJson(res).then(function(data){
      if (!res.ok || !data || data.ok === false || data.ok === 0 || data.ok === '0' || data.success === false || data.success === 0) {
        var err = (data && data.error) ? data.error : ('HTTP ' + res.status);
        throw new Error(err);
      }
      return data;
    });
  });
}

function apiGetParams(action, params){
  var url = API_URL + '?action=' + encodeURIComponent(action || '');
  params = params || {};
  for (var k in params){
    if (!params.hasOwnProperty(k)) continue;
    url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(String(params[k]));
  }
  return fetch(url, {
    method: 'GET',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': getCsrfToken()
    }
  }).then(function(res){
    updateCsrfFromResponse(res);
    return safeJson(res).then(function(data){
      if (!res.ok || !data || data.ok === false || data.ok === 0 || data.ok === '0' || data.success === false || data.success === 0) {
        var err = (data && data.error) ? data.error : ('HTTP ' + res.status);
        throw new Error(err);
      }
      return data;
    });
  });
}

function formEncode(obj){
  var parts = [];
  for (var k in obj){
    if (!obj.hasOwnProperty(k)) continue;
    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(obj[k])));
  }
  return parts.join('&');
}

function apiPost(action, params){
  // Serialized POST queue to avoid CSRF invalidation on rapid holds
  params = params || {};
  if (!apiPost._q) apiPost._q = Promise.resolve();

  function ensureCsrfReady(){
    var csrf = getCsrfToken();
    if (csrf) return Promise.resolve(csrf);
    // ping state endpoint to get a fresh token (GET usually not protected)
    return apiGetParams('barracks_state', {}).then(function(){ return getCsrfToken(); }).catch(function(){ return getCsrfToken(); });
  }

  function doOnce(){
    var url = API_URL + '?action=' + encodeURIComponent(action || '');
    return ensureCsrfReady().then(function(csrf){
      if (csrf){
        if (!params.csrf_token) params.csrf_token = csrf;
        if (!params.csrf) params.csrf = csrf;
        if (!params._csrf) params._csrf = csrf;
      }
      var body = new URLSearchParams();
      for (var k in params){
        if (!params.hasOwnProperty(k)) continue;
        if (params[k] === undefined || params[k] === null) continue;
        body.append(k, String(params[k]));
      }
      return fetch(url, {
        method:'POST',
        credentials:'same-origin',
        headers:{
          'Accept':'application/json',
          'X-Requested-With':'XMLHttpRequest',
          'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
          'X-CSRF-Token': getCsrfToken()
        },
        body: body.toString()
      }).then(function(res){
        updateCsrfFromResponse(res);
        if (res.status === 403){
          // retry once after refreshing token
          return apiGetParams('barracks_state', {}).then(function(){
            // update token param
            var csrf2 = getCsrfToken();
            if (csrf2){ params.csrf_token = csrf2; }
            return fetch(url, {
              method:'POST',
              credentials:'same-origin',
              headers:{
                'Accept':'application/json',
                'X-Requested-With':'XMLHttpRequest',
                'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
                'X-CSRF-Token': getCsrfToken()
              },
              body: body.toString()
            }).then(function(res2){
              updateCsrfFromResponse(res2);
              return safeJson(res2).then(function(data){
                if (!res2.ok || !data || data.ok === false || data.ok === 0 || data.ok === '0' || data.success === false || data.success === 0) {
                  var err = (data && data.error) ? data.error : ('HTTP ' + res2.status);
                  throw new Error(err);
                }
                return data;
              });
            });
          });
        }
        return safeJson(res).then(function(data){
          if (!res.ok || !data || data.ok === false || data.ok === 0 || data.ok === '0' || data.success === false || data.success === 0) {
            var err = (data && data.error) ? data.error : ('HTTP ' + res.status);
            throw new Error(err);
          }
          return data;
        });
      });
    });
  }

  // chain
  apiPost._q = apiPost._q.then(doOnce, doOnce);
  return apiPost._q;
}

function isModalOpen(){
  var m = q(MODAL_ID);
  return !!(m && m.classList.contains('active'));
}
  function matches(el, sel){
    if (!el) return false;
    var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
    return fn ? fn.call(el, sel) : false;
  }

  function closest(el, sel){
    while (el && el !== document){
      if (matches(el, sel)) return el;
      el = el.parentElement;
    }
    return null;
  }

  // Compact timer formatting for the whole Barracks UI.
  // Examples:
  //  - 7200 => 2ч
  //  - 7320 => 2ч 2м
  //  - 65   => 1м 5с
  //  - 90061=> 1д 1ч
  function formatTimeSmart(sec){
    sec = Math.max(0, sec|0);
    var d = Math.floor(sec/86400);
    var h = Math.floor((sec%86400)/3600);
    var m = Math.floor((sec%3600)/60);
    var s = sec%60;

    var parts = [];
    if (d>0) parts.push(d + 'д');
    if (h>0) parts.push(h + 'ч');
    if (m>0) parts.push(m + 'м');
    if (s>0 || parts.length===0) parts.push(s + 'с');

    return parts.join(' ');
  }

  function formatTimeShort(sec){
    return formatTimeSmart(sec);
  }

  // Back-compat: a lot of Barracks code uses formatTime() for timers.
  function formatTime(sec){
    return formatTimeSmart(sec);
  }

  // Compact hero time formatting for modal UI.
  // Examples:
  //  - 120m 00s => 2ч
  //  - 2h 05m 00s => 2ч 5м
  //  - 0m 30s => 30с
  function formatHeroTime(sec){
    return formatTimeSmart(sec);
  }
  function enableDragScroll(el){
    // Horizontal scroll helper that DOES NOT break clicks on desktop,
    // and DOES NOT block vertical modal scroll on touch.
    if (!el || el._dragScrollBound) return;
    el._dragScrollBound = true;

    // Encourage native scrolling behaviour
    try{
      el.style.webkitOverflowScrolling = 'touch';
      el.style.overscrollBehaviorX = 'contain';
      // Allow vertical panning; horizontal will be handled when user clearly drags horizontally.
      el.style.touchAction = 'pan-y';
    }catch(_){}

    var isDown = false;
    var startX = 0, startY = 0;
    var startScroll = 0;
    var axis = null; // 'h' | 'v'
    var dragging = false;
    var suppressClick = false;
    var pointerType = 'mouse';

    function onDown(x, y, pType){
      isDown = true;
      startX = x; startY = y;
      startScroll = el.scrollLeft;
      axis = null;
      dragging = false;
      pointerType = pType || 'mouse';
    }

    function onMove(x, y, e){
      if (!isDown) return;

      var dx = x - startX;
      var dy = y - startY;

      if (!axis){
        if (Math.abs(dx) + Math.abs(dy) < 6) return;
        axis = (Math.abs(dx) > Math.abs(dy) * 1.2) ? 'h' : 'v';
        if (axis === 'v'){
          // Let the browser/modal handle vertical scroll
          isDown = false;
          axis = null;
          dragging = false;
          return;
        }
      }

      if (axis === 'h'){
        var thr = (pointerType === 'mouse') ? 8 : 6;
        if (!dragging && Math.abs(dx) > thr) dragging = true;

        if (dragging){
          suppressClick = true;
          if (e && e.cancelable) e.preventDefault();
          el.scrollLeft = startScroll - dx;
          if (pointerType === 'mouse'){
            try { document.body && (document.body.style.userSelect = 'none'); } catch(_){}
          }
        }
      }
    }

    function onUp(){
      if (pointerType === 'mouse'){
        try { document.body && (document.body.style.userSelect = ''); } catch(_){}
      }
      isDown = false;
      axis = null;
      dragging = false;
      // auto-reset suppression if no click fires
      setTimeout(function(){ suppressClick = false; }, 250);
    }

    // Touch/Pen: only handle when user clearly drags horizontally.
    el.addEventListener('pointerdown', function(e){
      if (!e || e.pointerType === 'mouse') return; // mouse handled separately
      onDown(e.clientX || 0, e.clientY || 0, e.pointerType || 'touch');
    }, {passive:true});

    el.addEventListener('pointermove', function(e){
      if (!e || e.pointerType === 'mouse') return;
      onMove(e.clientX || 0, e.clientY || 0, e);
    }, {passive:false});

    el.addEventListener('pointerup', onUp, {passive:true});
    el.addEventListener('pointercancel', onUp, {passive:true});

    // Mouse: drag-to-scroll (works even when starting over icons),
    // but click is preserved unless the user actually drags.
    el.addEventListener('mousedown', function(e){
      if (!e || e.button !== 0) return;
      onDown(e.clientX || 0, e.clientY || 0, 'mouse');

      function mm(ev){ onMove(ev.clientX || 0, ev.clientY || 0, ev); }
      function mu(){
        document.removeEventListener('mousemove', mm, true);
        document.removeEventListener('mouseup', mu, true);
        onUp();
      }
      document.addEventListener('mousemove', mm, true);
      document.addEventListener('mouseup', mu, true);
    }, false);

    // Wheel: allow easy horizontal scroll without needing to hit a gap
    el.addEventListener('wheel', function(e){
      if (!e) return;
      if (!el || el.scrollWidth <= el.clientWidth + 2) return;

      var dx = (typeof e.deltaX === 'number') ? e.deltaX : 0;
      var dy = (typeof e.deltaY === 'number') ? e.deltaY : 0;

      // If user didn't provide horizontal wheel, map vertical wheel to horizontal.
      var delta = (Math.abs(dx) > Math.abs(dy)) ? dx : dy;

      if (delta === 0) return;

      el.scrollLeft += delta;
      if (e.cancelable) e.preventDefault();
    }, {passive:false, capture:true});

    // Suppress "click" only if it was actually a drag-scroll
    el.addEventListener('click', function(e){
      if (suppressClick){
        suppressClick = false;
        if (e){
          e.preventDefault();
          e.stopPropagation();
        }
      }
    }, true);
  }


  // ----- Fake state -----
var troopDefs = [];



  // ----- Fake spells & heroes (for visual only) -----
  function imgWithFallback(primary, fallbacks){
    fallbacks = fallbacks || [];
    var fb = fallbacks.length ? ' data-fallback="'+esc(fallbacks.join('|'))+'"' : '';
    // draggable="false" + class: prevents image drag/selection and helps suppress mobile callout on long-press.
    return '<img class="coc-unitimg" draggable="false" loading="lazy" decoding="async" src="'+esc(primary)+'"'+fb+' alt="">';
  }

  function bindImgFallback(root){
    if (!root) return;
    var imgs = root.querySelectorAll('img[data-fallback]');
    for (var i=0;i<imgs.length;i++){
      (function(img){
        if (img._fbBound) return;
        img._fbBound = true;
        img.addEventListener('error', function(){
          var list = (img.getAttribute('data-fallback') || '').split('|').filter(Boolean);
          var idx = parseInt(img.getAttribute('data-fb-idx') || '0', 10);
          if (idx >= list.length) return;
          img.setAttribute('data-fb-idx', String(idx + 1));
          img.src = list[idx];
        });
      })(imgs[i]);
    }
  }

  // Background-image fallback for .coc-simg tiles (used to avoid <img> elements and browser image menus).
  function applyBgFallback(root){
    try{
      if (!root) return;
      var els = root.querySelectorAll('.coc-simg[data-bg]');
      for (var i=0;i<els.length;i++){
        (function(el){
          if (el.__bgDone) return;
          el.__bgDone = true;
          var primary = String(el.getAttribute('data-bg')||'');
          var fb = String(el.getAttribute('data-bgfb')||'');
          var list = [primary].concat(fb ? fb.split('|') : []).map(function(x){return String(x||'').trim();}).filter(Boolean);
          if (!list.length) return;

          var idx = 0;
          var probe = new Image();
          var tryNext = function(){
            if (idx >= list.length) return;
            var url = list[idx++];
            probe.onload = function(){
              try{ el.style.backgroundImage = 'url("' + url.replace(/"/g,'\\"') + '")'; }catch(_e){}
            };
            probe.onerror = function(){ tryNext(); };
            probe.src = url;
          };
          tryNext();
        })(els[i]);
      }
    }catch(_e){}
  }

  var spellDefs = [];

  var heroDefs = [
    { id:'king', name:'Король', level:55, owned:true,
      img:'/images/heroes/Avatar_Hero_Barbarian_King.png', fb:['/images/heroes/Avatar_Hero_Barbarian_King.png','/images/heroes/Avatar_Hero_Barbarian_King.png','/images/icons/trophy_icon.png'] },
    { id:'queen', name:'Королева', level:55, owned:true,
      img:'/images/heroes/Avatar_Hero_Archer_Queen.png', fb:['/images/heroes/Avatar_Hero_Archer_Queen.png','/images/heroes/Avatar_Hero_Archer_Queen.png','/images/icons/trophy_icon.png'] },
    { id:'warden', name:'Страж', level:30, owned:false, locked:true,
      img:'/images/heroes/Avatar_Hero_Grand_Warden.png', fb:['/images/heroes/Avatar_Hero_Grand_Warden.png','/images/heroes/Avatar_Hero_Grand_Warden.png','/images/icons/trophy_icon.png'] },
    { id:'champ', name:'Чемпион', level:20, owned:false, locked:true,
      img:'/images/heroes/Avatar_Hero_Royal_Champion.png', fb:['/images/heroes/Avatar_Hero_Royal_Champion.png','/images/heroes/Avatar_Hero_Royal_Champion.png','/images/icons/trophy_icon.png'] }
  ];

// --- HERO WIKI STATS (for detailed modal) ---
// --- Hero base parameters (CoC-like). Full per-level stats come from backend (game_data_entities).
// We only keep constants needed to compute derived values like "damage per attack".
var HERO_BASE = {
  barbarian_king: {
    preferred_target: 'Отсутствует',
    attack_type: 'Ближний (Наземные)',
    move_speed: 16,
    attack_speed_sec: 1.2
  },
  archer_queen: {
    preferred_target: 'Отсутствует',
    attack_type: 'Дальний (Наземные и Воздушные)',
    move_speed: 24,
    attack_speed_sec: 0.75
  },
  grand_warden: {
    preferred_target: 'Любая',
    attack_type: 'Дальний (Любая цель)',
    move_speed: 16,
    attack_speed_sec: 1.8
  },
  royal_champion: {
    preferred_target: 'Защита',
    attack_type: 'Дальний (Наземные и Воздушные)',
    move_speed: 24,
    attack_speed_sec: 1.2
  },
  minion_prince: {
    preferred_target: 'Любая',
    attack_type: 'Дальний (Наземные и Воздушные)',
    move_speed: 24,
    attack_speed_sec: 0.85
  }
};

function heroBaseFromId(hero_id){
  hero_id = String(hero_id||'').toLowerCase();
  return HERO_BASE[hero_id] || null;
}

function heroMaxLevel(h){
  if (!h) return 0;
  var ml = parseInt(h.max_level || h.maxLevel || h.max_level_total || 0, 10) || 0;
  return ml;
}

function heroCapLevel(h){
  if (!h) return 0;
  return parseInt(h.cap || 0, 10) || 0;
}

function calcDamagePerAttack(dps, base){
  var x = parseFloat(dps);
  if (!isFinite(x)) return null;
  var sp = base && isFinite(base.attack_speed_sec) ? base.attack_speed_sec : null;
  if (!sp) return null;
  // keep 1 decimal like CoC wiki (e.g., 122.4)
  var v = Math.round((x * sp) * 10) / 10;
  return v;
}

function fmtMaybe(v){
  if (v === null || typeof v === 'undefined' || v === '') return '—';
  return String(v);
}


  function spellById(id){
    for (var i=0;i<spellDefs.length;i++) if (spellDefs[i].id===id) return spellDefs[i];
    return null;
  }

  function heroById(id){
    for (var i=0;i<heroDefs.length;i++) if (heroDefs[i].id===id) return heroDefs[i];
    return null;
  }

  function renderGenericStripItem(def, qty, opts){
    opts = opts || {};
    if (!def) return '';
    var disabled = opts.disabled ? ' is-disabled' : '';
    var minus = (opts.minus !== undefined && opts.minus !== null) ? '<button type="button" class="coc-qminus" title="Убрать" data-qminus="'+esc(String(opts.minus))+'">−</button>' : '';
    var time = '';
    if (opts.time || opts.timeEnd){
      var endAt = opts.timeEnd ? (parseInt(opts.timeEnd,10)||0) : 0;
      var left = (endAt>0) ? Math.max(0, endAt - nowServer()) : (opts.time|0);
      var te = endAt ? (' data-qtimer-end="'+esc(String(endAt))+'"') : '';
      time = '<div class="coc-qtime"'+te+'>'+esc(formatTime(left))+'</div>';
    }
    // Badges:
    // - default for troops: "Nx"
    // - default for spells: "xN"
    // - heroes (and any other caller) may pass opts.badgeText to override (e.g. "1ур")
    var badgeText = (opts && opts.badgeText !== undefined && opts.badgeText !== null) ? String(opts.badgeText) : null;
    var isSpell = !!(def && (def.kind === 'spell' || def.type === 'spell'));
    var count = '';
    if (badgeText !== null){
      badgeText = badgeText.trim();
      if (badgeText){
        count = '<div class="coc-badge coc-badge-count">'+esc(badgeText)+'</div>';
      }
    } else if (typeof qty === 'number'){
      count = '<div class="coc-badge coc-badge-count">'+(isSpell ? ('x'+esc(String(qty))) : (esc(String(qty))+'x'))+'</div>';
    }
    var lock = opts.locked ? '<div class="coc-lock"></div>' : '';
    return '<div class="coc-sitem coc-nomenu'+disabled+'" data-gitem="'+esc(def.id)+'">' +
      '<img class="coc-simgimg" draggable="false" loading="lazy" decoding="async" src="'+esc(normalizeImgPath(def.img))+'" data-fallback="'+esc((def.fb||[]).map(normalizeImgPath).join('|'))+'" alt="" aria-hidden="true">' +
      count +
      lock +
      minus +
      time +
    '</div>';
  }

  function renderHeroStrip(){
    var list = getHeroesList();
    var items = list.map(function(h){
      h = h || {};
      var id = String(h.id||'');
      if (!id) return '';
      var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
      var upgrading = !!h.upgrading;
      var left = parseInt(h.time_left,10)||0;
      var def = {
        id: id,
        img: heroImgFromId(id),
        fb: [heroImgFromId(id), '/images/heroes/Avatar_Hero_Barbarian_King.png']
      };
      var inner = renderGenericStripItem(def, unlocked ? (parseInt(h.level,10)||0) : null, {disabled:false, locked:!unlocked, time:(upgrading?left:0), badgeText: (unlocked ? (String(parseInt(h.level,10)||0)+'ур') : '')});
      // wrap to make it clickable
      return '<div class="coc-hero-click" data-heroopen="'+esc(id)+'">'+inner+'</div>';
    }).join('');
    if (!items) items = '<div class="coc-subhint" style="padding:10px;">Герои недоступны</div>';
    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-toprow"><div class="coc-subhint">Герои</div></div>' +
      '<div class="coc-strip" id="coc-hero-strip">'+items+'</div>' +
    '</div>';
  }

  function renderSpellArmyStrip(){
    // show only spells that are actually stored in army (qty > 0)
    var items = (spellDefs || []).filter(function(s){
      var qty = state.spellsArmy[s.id] || 0;
      return (s && s.owned && qty > 0);
    }).map(function(s){
      var qty = state.spellsArmy[s.id] || 0;
      return renderGenericStripItem(s, qty, {disabled:false, minus: ('s:' + s.id)});
    }).join('');

    if (!items){
      items = '<div class="coc-empty-note">Заклинаний нет.</div>';
    }

    var cap = '<div class="coc-cap">' +
      '<img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/svg/1f465.svg" alt="">' +
      esc(state.spellUsed) + '/' + esc(state.spellCap) +
    '</div>';

    // Top block title must be uppercase "ЗАКЛИНАНИЯ" and capacity only here.
    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-toprow"><div class="coc-panel-title">ЗАКЛИНАНИЯ</div>' + cap + '</div>' +
      '<div class="coc-strip" id="coc-spell-army-strip">'+items+'</div>' +
    '</div>';
  }

  function renderSpellQueueStrip(){
    // Legacy CoC: spells are brewed over time (queue)
    // Group identical spells so we don't duplicate tiles. Show xN.
    var rows = (state.spellQueue || []).slice();
    var grouped = [];
    var byId = {};
    for (var i=0;i<rows.length;i++){
      var it = rows[i] || {};
      var sid = String(it.id||'');
      if (!sid) continue;
      var g = byId[sid];
      if (!g){
        g = { id:sid, qty:0, firstFinish:0, firstQid:0, firstIdx:i };
        byId[sid] = g;
        grouped.push(g);
      }
      var q = parseInt(it.qty,10) || 1;
      g.qty += q;
      var ft = parseInt(it.finish_time,10) || 0;
      if (ft && (!g.firstFinish || ft < g.firstFinish)) g.firstFinish = ft;
      if (!g.firstQid && parseInt(it.qid,10)) g.firstQid = parseInt(it.qid,10)||0;
    }

    var items = grouped.map(function(g){
      var tend = parseInt(g.firstFinish, 10) || 0;
      var tleft = (tend > 0) ? Math.max(0, tend - nowServer()) : 0;
      var token = 'sqt:' + g.id; // cancel by spell type so hold-remove works on grouped tiles
      var def = spellDefById(g.id) || { id: g.id, kind:'spell', type:'spell', img: spellImgFromId(g.id), fb: [spellImgFromId(g.id)] };
      def.kind = 'spell';
      def.type = 'spell';
      return renderGenericStripItem(def, g.qty || 1, { minus: token, timeEnd: tend, time: tleft });
    }).join('');

    if (!items) return '';

    // Brewing queue panel (lowercase label, no capacity duplicate, no speedup)
    return '<div class="coc-panel coc-train-panel" style="margin-top:10px;">' +
      '<div class="coc-toprow">' +
        '<div class="coc-subhint" style="font-weight:400;font-size:13px;">готовятся</div>' +
      '</div>' +
      '<div class="coc-strip" id="coc-spell-queue-strip">'+items+'</div>' +
    '</div>';
  }

  function spellDefById(id){
    id = String(id||'');
    for (var i=0;i<spellDefs.length;i++){
      if (String(spellDefs[i].id) === id) return spellDefs[i];
    }
    return null;
  }

  function renderSpellGrid(){
    // Spells grid styled like troop grid (same tile size, same overlays & spacing)
    var tiles = spellDefs.map(function(s){
      var space = parseInt(s.space,10)||parseInt(s.housing_space,10)||parseInt(s.spell_space,10)||1;
      if (space < 1) space = 1;

      var isLocked = (!s.owned) || !!s.locked;

      var cls = 'coc-tile is-spell' + (isLocked ? ' is-disabled is-locked' : '');

      var lockOverlay = isLocked ? '<div class="coc-lock"></div>' : '';

      var lvl = (typeof s.level !== 'undefined') ? String(s.level) : '1';
      var lvlBadge = '<div class="coc-lvl">'+esc(lvl)+'</div>';

      // spells use 📦 for space/slot
      var spaceBadge = '<div class="coc-spacebar" data-kind="spell"><span class="coc-spacebar-num">'+esc(String(space))+'</span><span class="coc-spacebar-emoji" aria-hidden="true">📦</span></div>';

      var costHtml = '';
      if (!isLocked){
        costHtml = '<div class="coc-cost"><img src="'+esc(resIcon(s.res))+'" alt="">'+esc(String(s.cost))+'</div>';
      }

      var style = '';
      if (isLocked){
        style = ' style="filter: grayscale(1); opacity:0.45;"';
      }

      return '<div class="'+cls+'" data-spell="'+esc(s.id)+'"'+style+'>' +
        lvlBadge +
        spaceBadge +
        '<button type="button" class="coc-info" data-sinfo="'+esc(s.id)+'">i</button>' +
        lockOverlay +
        imgWithFallback(normalizeImgPath(s.img), (s.fb||[]).map(normalizeImgPath)) +
        costHtml +
      '</div>';
    }).join('');

    // keep same container class as troop grid for identical spacing
    return '<div class="coc-troop-grid" id="coc-spell-grid">'+tiles+'</div>';
  }

  function renderArmyTab(){
    // show only troops that are actually in army (qty > 0)
    var armyItems = (troopDefs || []).filter(function(t){
      var qty = state.army[t.id] || 0;
      return (t && t.owned && qty > 0);
    }).map(function(t){
      var qty = state.army[t.id] || 0;
      return renderStripItem(t.id, qty, {disabled:false, minus: ('u:' + t.id)});
    }).join('');

    if (!armyItems){
      armyItems = '<div class="coc-empty-note">Армия пуста.</div>';
    }

    return '<div class="coc-panel" style="margin-top:10px;">' +
        '<div class="coc-toprow"><div class="coc-subhint">Армия</div>'+ renderCapRow() +'</div>' +
        '<div class="coc-strip" id="coc-army-strip">'+armyItems+'</div>' +
      '</div>' +
      renderSpellArmyStrip() +
      renderHeroStrip();
  }

  function renderSpellsTab(){
    // Reworked: like troop training UI (stored spells + brew panel with CoC-like tiles)
    return renderSpellArmyStrip() +
      renderSpellGridInline();
  }

  // ---------------- heroes UI (Stage 11) ----------------

  function heroImgFromId(id){
    id = String(id||'').toLowerCase();
    // Use existing project assets (no /img/heroes/* paths to avoid 404)
    var map = {
      'barbarian_king':'/images/heroes/Avatar_Hero_Barbarian_King.png',
      'archer_queen':'/images/heroes/Avatar_Hero_Archer_Queen.png',
      'grand_warden':'/images/heroes/Avatar_Hero_Grand_Warden.png',
      'royal_champion':'/images/heroes/Avatar_Hero_Royal_Champion.png',
      'minion_prince':'/images/heroes/Avatar_Hero_Minion_Prince.png'
    };
    return map[id] || '';
  }

  function heroInfoImgFromId(id){
    id = String(id||'').toLowerCase();
    var map = {
      'barbarian_king':'/images/heroes/BarbarianKing_info.png',
      'archer_queen':'/images/heroes/Archer_Queen_info.png',
      'grand_warden':'/images/heroes/Grand_Warden_Info.png',
      'royal_champion':'/images/heroes/Royal_Champion_info.png',
      'minion_prince':'/images/heroes/Minion_Prince_info.png'
    };
    return map[id] || '';
  }
  // Try multiple filename variants for newly added "info" hero images
  function heroInfoImgCandidates(id){
    id = String(id||'').toLowerCase();
    // IMPORTANT: do NOT probe non-existent filenames.
    // Even with onerror fallbacks, the browser can flash a broken-image (404) first.
    // Use only реально существующие ассеты из /images/heroes/.
    var c = [];
    var mapped = heroInfoImgFromId(id);
    if (mapped) c.push(mapped);
    var av = heroImgFromId(id);
    if (av) c.push(av);
    return c;
  }



  function getHeroesList(){
    var list = (state && Array.isArray(state.heroes)) ? state.heroes.slice() : [];
    list.sort(function(a,b){
      var ath = parseInt((a&&a.unlock_th)?a.unlock_th:0,10)||0;
      var bth = parseInt((b&&b.unlock_th)?b.unlock_th:0,10)||0;
      if (ath !== bth) return ath - bth;
      var an = String((a&&a.name)?a.name:(a&&a.id)||'');
      var bn = String((b&&b.name)?b.name:(b&&b.id)||'');
      return an.localeCompare(bn);
    });
    return list;
  }

  function renderHeroesGrid(){
    var list = getHeroesList();
    if (!list.length){
      return '<div class="coc-panel" style="margin-top:10px;"><div class="coc-empty">Герои недоступны</div></div>';
    }

    var hhLvl = state && state.heroHall ? (parseInt(state.heroHall.level,10)||0) : 0;

    var html = '' +
      '<div class="coc-herohall">' +
        '<div class="coc-herohall-bar">' +
          '<div class="coc-herohall-title">ЗАЛ ГЕРОЕВ • Уровень '+esc(String(hhLvl))+'</div>' +
        '</div>' +
        '<div class="coc-herohall-cards" id="coc-heroes-grid">';

    for (var i=0;i<list.length;i++){
      var h = list[i] || {};
      var id = String(h.id||'');
      if (!id) continue;

      var name = String(h.name||id);
      var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
      var lvl = parseInt(h.level,10)||0;
      var upgrading = !!h.upgrading;
      var until = parseInt(h.upgrading_until,10)||0;
      var left = upgrading ? Math.max(0, until ? (until - nowServer()) : (parseInt(h.time_left,10)||0)) : 0;
      // Some backends don't send upgrading_until; build a synthetic end timestamp so the timer can tick.
      var endAt = upgrading ? (until || (left>0 ? (nowServer() + left) : 0)) : 0;

      var maxLvl = heroMaxLevel(h) || 0;
      var capLvl = heroCapLevel(h) || 0;
      var shownMax = (maxLvl > 0) ? maxLvl : capLvl;

      var lockText = (!unlocked && !h.can_unlock) ? String(h.locked_reason||'') : '';
      var actionLabel = unlocked ? 'УЛУЧШИТЬ' : 'РАЗБЛОК.';
      // If hero is upgrading: show a ticking timer INSTEAD of the action button (like buildings).
      var statusHtml = '';
      var btnHtml = '';
      if (upgrading){
        statusHtml = 'Улучшается';
        btnHtml = '<div class="coc-heroh-btn is-busy" data-herotimer-end="'+esc(String(endAt||0))+'">'+esc(formatHeroTime(left))+'</div>';
      } else {
        var statusLine = (unlocked ? 'Готов' : (lockText ? 'Закрыто' : 'Доступно'));
        statusHtml = esc(statusLine);
        btnHtml = '<div class="coc-heroh-btn">'+esc(actionLabel)+'</div>';
      }

      html += '' +
        '<div class="coc-heroh-card'+(unlocked?'':' is-locked')+'" data-heroopen="'+esc(id)+'">' +
          '<div class="coc-heroh-name">'+esc(name)+'</div>' +
          '<div class="coc-heroh-portrait">' +
            imgWithFallback(heroImgFromId(id), ['/images/heroes/Avatar_Hero_Barbarian_King.png']) +
            '' +
            (lockText ? ('<div class="coc-heroh-lock">'+esc(lockText)+'</div>') : '') +
          '</div>' +
          '<div class="coc-heroh-bottom">' +
            '<div class="coc-heroh-level">Ур. '+esc(String(lvl||0))+(shownMax?(' / '+esc(String(shownMax))):'')+'</div>' +
            '<div class="coc-heroh-status">'+statusHtml+'</div>' +
            btnHtml +
          '</div>' +
        '</div>';
    }

    html += '</div></div>';
    return html;
  }


  function renderHeroDetail(){
    var h = state.heroInfo;
    if (!h || !h.id) return '';
    var id = String(h.id||'');
    var name = String(h.name||id);
    var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
    var lvl = parseInt(h.level,10)||0;
    var cap = parseInt(h.cap,10)||0;
    var upgrading = !!h.upgrading;
    var until = parseInt(h.upgrading_until,10)||0;
    var left = upgrading ? Math.max(0, until ? (until - nowServer()) : (parseInt(h.time_left,10)||0)) : 0;
    var endAt = upgrading ? (until || (left>0 ? (nowServer() + left) : 0)) : 0;

    var cur = h.current || null;
    var next = h.next || null;
    var lock = (!unlocked && !h.can_unlock) ? String(h.locked_reason||'') : '';

    var btnLabel = unlocked ? 'УЛУЧШИТЬ' : 'РАЗБЛОКИРОВАТЬ';
    // Do not use native disabled attr (it blocks click -> can't show reason toast). Use CSS class instead.
    var isDisabled = (upgrading || (unlocked ? !h.can_upgrade : !h.can_unlock));
    var btnClsExtra = (isDisabled ? ' is-disabled' : '') + (upgrading ? ' is-busy' : '');

    var costText = '';
    var timeText = '';
    if (next){
      var cost = parseInt(next.cost,10)||0;
      costText = formatNumber(cost);
      timeText = formatTime(parseInt(next.time,10)||0);
    }

    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-detail-row">' +
        '<div class="coc-detail-icon">' + imgWithFallback(heroImgFromId(id), ['/images/heroes/Avatar_Hero_Barbarian_King.png']) + '</div>' +
        '<div class="coc-detail-main">' +
          '<div class="coc-detail-title">'+esc(name)+'</div>' +
          '<div class="coc-detail-sub">'+(unlocked ? ('Уровень '+esc(String(lvl))+(cap?(' / '+esc(String(cap))):'')) : 'Не разблокирован')+'</div>' +
          (cur ? ('<div class="coc-detail-time">Урон: '+esc(String(cur.dps||0))+' • Здоровье: '+esc(String(cur.hp||0))+'</div>') : '') +
          (cur ? ('<div class="coc-detail-time">Восстановление: '+esc(formatTime(parseInt(cur.recovery,10)||0))+'</div>') : '') +
          (upgrading ? ('<div class="coc-detail-time">⏳ Осталось: <span data-herotimer-end="'+esc(String(endAt||0))+'">'+esc(formatHeroTime(left))+'</span></div>') : '') +
          (next && !upgrading ? ('<div class="coc-detail-cost">Стоимость: '+esc(costText)+' <img src="'+esc(resIconAny(next.res_type||''))+'" alt="" style="width:14px;height:14px;vertical-align:-2px;"></div>') : '') +
          (next && !upgrading ? ('<div class="coc-detail-time">Время: '+esc(formatHeroTime(parseInt(next.time,10)||0))+'</div>') : '') +
          (lock ? ('<div class="coc-detail-lock"> '+esc(lock)+'</div>') : '') +
        '</div>' +
      '</div>' +
      '<div class="coc-detail-actions">' +
        '<button type="button" class="coc-speedup-btn'+btnClsExtra+'" id="coc-hero-act" data-heroact="'+esc(id)+'"'+((isDisabled && lock)?(' data-herolockmsg="'+esc(lock)+'"'):'')+'>'+esc(btnLabel)+'</button>' +
        (upgrading ? ('<button type="button" class="coc-speedup-btn" id="coc-hero-speedup" data-herospeedup="'+esc(id)+'">УСКОРИТЬ за '+esc(formatNumber(parseInt(h.speedup_cost,10)||0))+' '+resIconImg('gems')+'</button>') : '') +
        '' +
      '</div>' +
    '</div>';
  }

  // ---- Heroes modal (CoC-like) ----
  var HERO_LORE = {
    'barbarian_king': 'Король варваров — мощный танк ближнего боя. Умение вызывает ярость и подкрепление.',
    'archer_queen': 'Королева лучниц — дальнобойный убийца. Умение даёт невидимость и усиливает урон.',
    'grand_warden': 'Великий хранитель — герой поддержки. Умение даёт временную неуязвимость союзникам.',
    'royal_champion': 'Королевская чемпионка — охотник по обороне. Умение даёт рывок щитом по целям.',
    'minion_prince': 'Принц миньонов — летающий герой урона. Быстро расправляется с целями.',
  };

  function heroLoreFromId(id){
    id = String(id||'').toLowerCase();
    return HERO_LORE[id] || '';
  }


  
  function renderHeroUnlockReq(h){
    try{
      if (!h) return '';
      var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
      if (unlocked) return '';
      var parts = [];
      var reason = String(h.locked_reason || '').trim();
      var th = parseInt(h.unlock_th || h.th_req || h.req_th || 0, 10) || 0;
      var hh = parseInt(h.unlock_hh || h.hh_req || h.req_hh || h.required_hh || 0, 10) || 0;
      if (th > 0) parts.push('🏰 Ратуша: ' + th + '+');
      if (hh > 0) parts.push('🏛️ Зал героев: ' + hh + '+');
      var need = parts.length ? parts.join(' • ') : '';
      var html = '<div class="coc-um-req">';
      html += '<div class="coc-um-reqt">🔒 Условия разблокировки</div>';
      if (need) html += '<div class="coc-um-reqv">'+esc(need)+'</div>';
      if (reason) html += '<div class="coc-um-reqv is-reason">'+esc(reason)+'</div>';
      if (!need && !reason) html += '<div class="coc-um-reqv">Требования уточняются на стороне сервера.</div>';
      html += '</div>';
      return html;
    }catch(e){ return ''; }
  }

  // Short one-line requirements text (used near the Unlock button)
  function heroUnlockReqInlineText(h){
    try{
      if (!h) return '';
      var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
      if (unlocked) return '';
      var parts = [];
      var reason = String(h.locked_reason || '').trim();
      var th = parseInt(h.unlock_th || h.th_req || h.req_th || 0, 10) || 0;
      var hh = parseInt(h.unlock_hh || h.hh_req || h.req_hh || h.required_hh || 0, 10) || 0;
      if (th > 0) parts.push('Ратуша ' + th + '+');
      if (hh > 0) parts.push('Зал героев ' + hh + '+');
      var need = parts.length ? parts.join(' • ') : '';
      if (need && reason) return need + ' • ' + reason;
      return need || reason || '';
    }catch(e){ return ''; }
  }

// -------------------- Hero modal (unit-style) mounted via portal --------------------
  function renderHeroModalUnit(){
    if (!state || !state.heroModalOpen || !state.heroInfo || !state.heroInfo.id) return '';
    var h = state.heroInfo;
    var id = String(h.id||'');
    var name = String(h.name||id);
    var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
    var lvl = parseInt(h.level,10)||0;
    var cap = parseInt(h.cap,10)||0;
    var maxLevel = (HERO_GLOBAL_MAX[id]||0) || (parseInt(h.max_level,10)||0);
    var upgrading = !!h.upgrading;
    var left = parseInt(h.time_left,10)||0;
    var until = parseInt(h.upgrading_until,10)||0;
    var endAt = upgrading ? (until || (left>0 ? (nowServer() + left) : 0)) : 0;
    var cur = h.current || null;
    var next = h.next || null;

    // For locked heroes, show level 1 stats as "current" (no dashes from level 0)
    if (!unlocked){
      var nlv = next ? (parseInt(next.level,10)||0) : 0;
      var clv = cur ? (parseInt(cur.level,10)||0) : 0;
      if (nlv === 1){
        cur = next;
        next = null;
      } else if (clv === 0 && next){
        cur = next;
        next = null;
      }
      if (lvl <= 0) lvl = 1;
    }

    var lore = String(h.description||h.desc||'') || heroLoreFromId(id);

    var levelText = (unlocked ? 'Уровень ' : 'Уровень (после разблокировки) ') + lvl + (maxLevel ? (' / ' + maxLevel) : (cap ? (' / ' + cap) : ''));

    var lvlPct = (maxLevel>0) ? Math.max(0, Math.min(100, Math.round((lvl/maxLevel)*100))) : 0;
    var lvlProgHtml = (maxLevel>0) ? ('<div class="coc-lvl-prog"><div class="coc-lvl-progbar" style="width:'+lvlPct+'%"></div><div class="coc-lvl-progtext">'+esc(String(lvl))+' / '+esc(String(maxLevel))+'</div></div>') : '';

    if (maxLevel && cap && cap < maxLevel) levelText += ' • Лимит Зала героев: ' + cap;

    var btnLabel = unlocked ? 'УЛУЧШИТЬ' : 'РАЗБЛОКИРОВАТЬ';
    var canDo = !upgrading && (unlocked ? !!h.can_upgrade : !!h.can_unlock);
    // Do NOT use native disabled attribute (it blocks click -> can't show reason toast). We'll handle via class + data.
    var isDisabled = !canDo;
    var btnClsExtra = (isDisabled ? ' is-disabled' : '') + (upgrading ? ' is-busy' : '');

    var nextCost = next ? formatNumber(parseInt(next.cost,10)||0) : '';
    var nextTime = next ? formatHeroTime(parseInt(next.time,10)||0) : '';

    var totalTime = parseInt(h.time_total||h.time_full||h.upgrade_time||h.time_max||0,10)||0;
    if (!totalTime && next && next.time) totalTime = parseInt(next.time,10)||0;
    var progPct = 0;
    if (upgrading && totalTime > 0) {
      progPct = Math.max(0, Math.min(100, Math.round((1 - (left/totalTime)) * 100)));
    }
    // During upgrade show a proper status line: progress bar + ticking timer.
    var progHtml = '';
    if (upgrading){
      if (totalTime > 0){
        progHtml = (
          '<div class="coc-um-upgline">'
            + '<div class="coc-um-upglabel">Улучшение</div>'
            + '<div class="coc-um-prog coc-um-prog--inline"><div class="coc-um-progbar" style="width:'+progPct+'%"></div></div>'
            + '<div class="coc-um-upgtime" data-herotimer-end="'+esc(String(endAt||0))+'">'+esc(formatHeroTime(left))+'</div>'
          + '</div>'
        );
      } else {
        // fallback (unknown total time) – still show a ticking timer
        progHtml = (
          '<div class="coc-um-upgline">'
            + '<div class="coc-um-upglabel">Улучшение</div>'
            + '<div class="coc-um-prog coc-um-prog--inline is-indeterminate"><div class="coc-um-progbar" style="width:35%"></div></div>'
            + '<div class="coc-um-upgtime" data-herotimer-end="'+esc(String(endAt||0))+'">'+esc(formatHeroTime(left))+'</div>'
          + '</div>'
        );
      }
    }


    // Build stat tiles (like unit/spell window). No "cells/radius".
    var tiles = [];
    var hp = cur ? (cur.hp ?? cur.health) : (h.hp ?? h.health);
    var dps = cur ? (cur.dps ?? cur.damage_per_second) : (h.dps ?? h.damage_per_second);
    var rec = cur ? (cur.recovery ?? cur.regen) : (h.recovery ?? h.regen);
    var base = heroBaseFromId(id);
    // allow server-provided overrides (if any)
    if (h.base_params && typeof h.base_params === 'object') {
      for (var k in h.base_params) { if (Object.prototype.hasOwnProperty.call(h.base_params,k)) base[k] = h.base_params[k]; }
    }
    var atkSpeed = (base.attack_speed !== undefined && base.attack_speed !== null) ? parseFloat(base.attack_speed) : 1.2;
    var dmgPerAtk = (dps !== undefined && dps !== null) ? (parseFloat(dps)||0) * atkSpeed : null;

    if (hp !== undefined && hp !== null) tiles.push({ico:'❤️', label:'Здоровье', val: formatNumber(parseInt(hp,10)||0)});
    if (dps !== undefined && dps !== null) tiles.push({ico:'💥', label:'Урон в секунду', val: formatStat(dps)});
    if (dmgPerAtk !== null) tiles.push({ico:'⚔️', label:'Урон за атаку', val: formatStat(dmgPerAtk)});

    // unit-like meta tiles
    if (base.dmg_type) tiles.push({ico:'💢', label:'Вид урона', val: String(base.dmg_type)});
    if (base.targets) tiles.push({ico:'🎯', label:'Цели', val: String(base.targets)});
    if (base.attack_type) tiles.push({ico:'🥊', label:'Тип атаки', val: String(base.attack_type)});
    if (atkSpeed) tiles.push({ico:'⏱️', label:'Скорость атаки', val: (atkSpeed + 'с')});

    var tilesHtml = tiles.map(function(t){
      return '<div class="coc-um-tile"><div class="coc-um-ti">'+t.ico+' '+esc(t.label)+':</div><div class="coc-um-tv">'+esc(t.val)+'</div></div>';
    }).join('');

// Stats table (Текущий / Следующий), like unit/spell info.
function pickStat(obj, keys){
  if (!obj) return null;
  for (var i=0;i<keys.length;i++){
    var k=keys[i];
    if (obj[k]!==undefined && obj[k]!==null && obj[k]!=='' ) return obj[k];
  }
  return null;
}
function fmtNum(v){
  return formatStat(v);
}
var stRows = [];
var curLvl = (cur && (cur.level!==undefined)) ? parseInt(cur.level,10) : lvl;
var nextLvl = (next && (next.level!==undefined)) ? parseInt(next.level,10) : (curLvl ? (curLvl+1) : (lvl+1));
stRows.push({ico:'⭐', label:'Уровень', a: String(curLvl||0), b: String(nextLvl||'')});

var curDps = pickStat(cur, ['dps','damage_per_second','damageps']);
var nextDps = pickStat(next, ['dps','damage_per_second','damageps']);
if (curDps!==null || nextDps!==null) stRows.push({ico:'💥', label:'Урон в секунду', a: fmtNum(curDps), b: fmtNum(nextDps)});

var curAtk = pickStat(cur, ['attack','damage','hit_damage','damage_per_hit']);
var nextAtk = pickStat(next, ['attack','damage','hit_damage','damage_per_hit']);
// Если сервер не прислал урон за атаку — считаем из DPS и скорости атаки (как в CoC)
if (curAtk===null && curDps!==null){
  var b0 = heroBaseFromId(id) || {};
  var as0 = (b0.attack_speed!==undefined && b0.attack_speed!==null) ? parseFloat(b0.attack_speed) : 1.2;
  curAtk = (parseFloat(curDps)||0) * as0;
}
if (nextAtk===null && nextDps!==null){
  var b1 = heroBaseFromId(id) || {};
  var as1 = (b1.attack_speed!==undefined && b1.attack_speed!==null) ? parseFloat(b1.attack_speed) : 1.2;
  nextAtk = (parseFloat(nextDps)||0) * as1;
}
if (curAtk!==null || nextAtk!==null) stRows.push({ico:'⚔️', label:'Урон за атаку', a: fmtNum(curAtk), b: fmtNum(nextAtk)});

var curHp = pickStat(cur, ['hp','health','hitpoints']);
var nextHp = pickStat(next, ['hp','health','hitpoints']);
if (curHp!==null || nextHp!==null) stRows.push({ico:'❤️', label:'Здоровье', a: fmtNum(curHp), b: fmtNum(nextHp)});

var curRec = pickStat(cur, ['recovery','regen','regeneration']);
var nextRec = pickStat(next, ['recovery','regen','regeneration']);
if (curRec!==null || nextRec!==null) stRows.push({ico:'💚', label:'Регенерация', a: (curRec!==null?formatTime(parseInt(curRec,10)||0):'—'), b: (nextRec!==null?formatTime(parseInt(nextRec,10)||0):'—')});

var curRegen = pickStat(cur, ['ability_heal','regen','healing','ability_regen']);
var nextRegen = pickStat(next, ['ability_heal','regen','healing','ability_regen']);
if (curRegen!==null || nextRegen!==null) stRows.push({ico:'✨', label:'Восстановление (умение)', a: fmtNum(curRegen), b: fmtNum(nextRegen)});

// Requirements/cost/time (if known)
var reqHh = pickStat(next, ['req_hh','required_hh','hero_hall','hall','req_hall']) || pickStat(h, ['req_hh','required_hh','hero_hall','hall','req_hall']);
if (reqHh!==null && reqHh!=='' ) stRows.push({ico:'🏛️', label:'Требуется зал героев', a: '', b: String(reqHh)});
var reqTh = pickStat(next, ['th_req','req_th','required_th','town_hall','th']);
if (reqTh!==null && reqTh!=='' ) stRows.push({ico:'🏰', label:'Требуется уровень ратуши', a:'', b: String(reqTh)});

var nextCostRaw = pickStat(next, ['cost','price']);
if (!upgrading && nextCostRaw!==null && nextCostRaw!=='') stRows.push({ico:'💰', label:'Стоимость улучшения', a:'', b: formatNumber(parseInt(nextCostRaw,10)||0)});

var nextTimeRaw = pickStat(next, ['time','upgrade_time']);
if (!upgrading && nextTimeRaw!==null && nextTimeRaw!=='') stRows.push({ico:'⏳', label:'Время улучшения', a:'', b: formatHeroTime(parseInt(nextTimeRaw,10)||0)});

var twoCols = !!(next && unlocked);
var colA = (!unlocked ? 'После разблокировки (ур.1)' : 'Текущий');
var colB = (twoCols ? 'Следующий' : '');
var statsHtml = '<div class="coc-um-stats">' +
  '<div class="coc-um-sth"><div></div><div class="coc-um-stc">'+esc(colA)+'</div>' + (twoCols ? '<div class="coc-um-stn">'+esc(colB)+'</div>' : '<div class="coc-um-stn is-hidden"></div>') + '</div>' +
  stRows.map(function(r){
    var bcls = (r.b && r.a && r.b!==r.a) ? ' coc-um-next' : '';
    return '<div class="coc-um-str">' +
      '<div class="coc-um-stl"><span class="coc-um-ico">'+esc(r.ico)+'</span>'+esc(r.label)+'</div>' +
      '<div class="coc-um-stv">'+esc(r.a||'—')+'</div>' +
      (twoCols ? ('<div class="coc-um-stv'+bcls+'">'+esc(r.b||'—')+'</div>') : '<div class="coc-um-stv is-hidden"></div>') +
    '</div>';
  }).join('') +
'</div>';

    // When upgrading we don't show the old time chip (it doesn't look like a timer).
    var timeChip = upgrading ? '' : (nextTime ? ('<div class="coc-um-chip">⏳ '+esc(nextTime)+'</div>') : '');
    var costChip = (!upgrading && nextCost) ? ('<div class="coc-um-chip">'+resIconImg(next && next.res_type ? next.res_type : 'dark')+' '+esc(nextCost)+'</div>') : '';

    var needUnlockTxt = (!unlocked && !canDo) ? heroUnlockReqInlineText(h) : '';
    var lockToastMsg = '';
    if (!canDo){
      lockToastMsg = String(h.locked_reason||'').trim();
      if (!lockToastMsg && needUnlockTxt) lockToastMsg = 'Требуется: ' + needUnlockTxt;
    }
    var mainBtnHtml = '<button type="button" class="coc-um-btn coc-um-main'+btnClsExtra+'" id="coc-hero-act" data-heroact="'+esc(id)+'">'+esc(btnLabel)+'</button>';
    if (isDisabled && lockToastMsg){
      // Keep reason on the button so click handler can show it.
      mainBtnHtml = mainBtnHtml.replace('data-heroact="'+esc(id)+'"', 'data-heroact="'+esc(id)+'" data-herolockmsg="'+esc(lockToastMsg)+'"');
    }

    var spCost = parseInt(h.speedup_cost,10)||0;
    var spBtnLabel = upgrading ? ('УСКОРИТЬ за ' + formatNumber(spCost) + ' ') : '';

    return (
      '<div class="coc-hmodal-overlay" id="coc-hportal">'
      + '<div class="coc-um-modal coc-um-modal--unit" role="dialog" aria-modal="true">'
        + '<div class="coc-um-head">'+esc(name)+'<button type="button" class="coc-um-x" data-heromodalclose="1">×</button></div>'
        + '<div class="coc-um-sub">'+esc(levelText)+'</div>'
        + lvlProgHtml
        + progHtml
        + '<div class="coc-um-body">'
          + '<div class="coc-um-left">'
            + '<div class="coc-um-art">'+ (function(){ var cc = heroInfoImgCandidates(id); return imgWithFallback(cc[0]||heroImgFromId(id), cc.slice(1).concat(['/images/heroes/Avatar_Hero_Barbarian_King.png'])); })() +'</div>'
            + (lore ? ('<div class="coc-um-lore">📜 '+esc(lore)+'</div>') : '')
          + '</div>'
          + '<div class="coc-um-right">'
            + '<div class="coc-um-tiles">'+tilesHtml+'</div>' + statsHtml
          + '</div>'
        + '</div>'
        + '<div class="coc-um-actions">'
                    + mainBtnHtml
          + (upgrading ? ('<button type="button" class="coc-um-btn coc-um-speed" id="coc-hero-speedup" data-herospeedup="'+esc(id)+'">'+esc(spBtnLabel)+resIconImg('gems')+'</button>') : '')
          + '<div class="coc-um-chips">'+costChip+timeChip+'</div>'
          + (needUnlockTxt ? ('<div class="coc-um-need">🔒 '+esc(needUnlockTxt)+'</div>') : '')
        + '</div>'
      + '</div>'
      + '</div>'
    );
  }

  function syncHeroPortal(){
    try{
      var existing = document.getElementById('coc-hportal');
      installHeroPortalHandlers();
      if (!state || !state.heroModalOpen || !state.heroInfo || !state.heroInfo.id){
        if (existing && existing.parentNode) existing.parentNode.removeChild(existing);
        return;
      }
      var html = renderHeroModalUnit();
      if (!existing){
        var wrap = document.createElement('div');
        wrap.innerHTML = html;
        document.body.appendChild(wrap.firstChild);
        // Enable image fallback switching inside portal modal (info images, avatars, etc.)
        try{ bindImgFallback(document.getElementById('coc-hportal')); }catch(_e){}
      } else {
        // preserve scroll inside right panel (if any)
        var st = 0;
        var sc = existing.querySelector('.coc-um-right');
        if (sc) st = sc.scrollTop||0;
        existing.outerHTML = html;
        var ne = document.getElementById('coc-hportal');
        var sc2 = ne && ne.querySelector('.coc-um-right');
        if (sc2) sc2.scrollTop = st;
        try{ bindImgFallback(ne); }catch(_e){}
      }
    }catch(e){
      try{ heroDbg('syncHeroPortal error', e); }catch(_e){}
    }
  }


  

// Ensure hero modal in BODY has working controls (close / back / action buttons).
// Previously click handlers were delegated on the barracks content container, so the portal modal didn't receive them.
var __heroPortalHandlersInstalled = false;
function installHeroPortalHandlers(){
  if (__heroPortalHandlersInstalled) return;
  __heroPortalHandlersInstalled = true;

  document.addEventListener('click', function(e){
    try{
      var portal = document.getElementById('coc-hportal');
      if (!portal) return;
      if (!portal.contains(e.target)) return;

      var t = e.target;

      // Close on overlay click or any explicit close/back button
      if (t === portal || closest(t,'[data-heromodalclose]')){
        state.heroModalOpen = false;
        state.heroInfo = null;
        // remove portal immediately
        try{ syncHeroPortal(); }catch(_e){}
        try{ render(); }catch(_e2){}
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      // If main button is disabled, show lock reason on click (desktop has space, mobile shows under button)
      var mw = closest(t, '.coc-um-mainwrap');
      if (mw && mw.getAttribute){
        var msg = mw.getAttribute('data-herolockmsg') || '';
        if (msg){
          showBarracksToast('info','Герои', String(msg));
          e.preventDefault();
          e.stopPropagation();
          return;
        }
      }

      // Hero action (unlock/upgrade)
      var act = closest(t, '[data-heroact],#coc-hero-act');
      if (act){
        var hid = act.getAttribute('data-heroact') || (state.heroInfo && state.heroInfo.id) || '';
        if (hid){
          doHeroAction(String(hid));
        }
        e.preventDefault();
        e.stopPropagation();
        return;
      }

      // Speed up
      var sp = closest(t, '[data-herospeedup],#coc-hero-speedup');
      if (sp){
        var hid2 = sp.getAttribute('data-herospeedup') || (state.heroInfo && state.heroInfo.id) || '';
        if (hid2){
          doHeroSpeedup(String(hid2));
        }
        e.preventDefault();
        e.stopPropagation();
        return;
      }

    }catch(err){
      try{ heroDbg('hero portal click handler error', err); }catch(_e3){}
    }
  }, true);
}
function renderHeroModal(){
    if (!state || !state.heroModalOpen || !state.heroInfo || !state.heroInfo.id) return '';
    var h = state.heroInfo;
    var id = String(h.id||'');
    var name = String(h.name||id);
    var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
    var lvl = parseInt(h.level,10)||0;
    var cap = parseInt(h.cap,10)||0;
    var upgrading = !!h.upgrading;
    var left = parseInt(h.time_left,10)||0;
    var until = parseInt(h.upgrading_until,10)||0;
    var endAt = upgrading ? (until || (left>0 ? (nowServer() + left) : 0)) : 0;
    var cur = h.current || null;
    var next = h.next || null;

    var lore = String(h.description||h.desc||'') || heroLoreFromId(id);

    var btnLabel = unlocked ? 'УЛУЧШИТЬ' : 'РАЗБЛОКИРОВАТЬ';
    // Do not use native disabled attr (it blocks click -> can't show reason toast). Use CSS class instead.
    var isDisabled = (upgrading || (unlocked ? !h.can_upgrade : !h.can_unlock));
    var btnClsExtra = (isDisabled ? ' is-disabled' : '') + (upgrading ? ' is-busy' : '');
    var nextCost = next ? formatNumber(parseInt(next.cost,10)||0) : '';
    var nextTime = next ? formatHeroTime(parseInt(next.time,10)||0) : '';

    function heroStatIcon(label){
      label = String(label||'').toLowerCase();
      if (label.indexOf('уров') !== -1) return '⭐';
      if (label.indexOf('dps') !== -1 || label.indexOf('урон (') !== -1) return '⚔️';
      if (label.indexOf('урон за') !== -1) return '🗡️';
      if (label.indexOf('здоров') !== -1) return '❤️';
      if (label.indexOf('восстанов') !== -1) return '✨';
      return '📌';
    }

    var statRow = function(label, curV, nextV){
      var c = (curV===undefined||curV===null) ? '—' : String(curV);
      var n = (nextV===undefined||nextV===null) ? '' : String(nextV);
      var right = next ? ('<div class="coc-hstat-next">'+esc(n)+'</div>') : '';
      var arrow = next ? '<div class="coc-hstat-arrow">›</div>' : '';
      var ico = heroStatIcon(label);
      return '<div class="coc-hstat-row">' +
        '<div class="coc-hstat-label"><span class="coc-hico">'+esc(ico)+'</span><span class="coc-hstat-text">'+esc(label)+'</span></div>' +
        '<div class="coc-hstat-cur">'+esc(c)+'</div>' +
        arrow + right +
      '</div>';
    };

    
    // --- Stats section (CoC-like) ---
var base = heroBaseFromId(id) || {};
var stats = '';

// Base params (hide "cells" / range / detection - not used in this game UI)
var baseCards = [];
function baseCard(ico, label, value){
  if (value === null || typeof value === 'undefined' || value === '') return;
  baseCards.push(
    '<div class="coc-hbase-item">' +
      '<div class="coc-hbase-label"><span class="coc-hico">'+esc(ico)+'</span>'+esc(label)+'</div>' +
      '<div class="coc-hbase-value">'+esc(String(value))+'</div>' +
    '</div>'
  );
}
baseCard('🎯', 'Избранная цель', fmtMaybe(base.preferred_target));
baseCard('🥊', 'Тип атаки', fmtMaybe(base.attack_type));
baseCard('🏃', 'Скорость', fmtMaybe(base.move_speed));
baseCard('⏱️', 'Скорость атаки', base.attack_speed_sec ? (String(base.attack_speed_sec)+'с') : '—');

var baseHtml = baseCards.length ? (
  '<div class="coc-hsection-title">БАЗОВЫЕ ПАРАМЕТРЫ</div>' +
  '<div class="coc-hbase">'+baseCards.join('')+'</div>'
) : '';

function addLine(label, curV, nextV){
  stats += statRow(label, curV, nextV);
}

// Level-based (current/next) from backend
var curLevel = unlocked ? (lvl||1) : 0;
var nextLevel = next ? (parseInt(next.level,10)||((lvl||0)+1)) : null;

// Compute derived values
var curDps = cur ? (parseFloat(cur.dps)||0) : 0;
var nextDps = next && next.dps ? (parseFloat(next.dps)||0) : (next ? null : null);
var curHit = cur ? calcDamagePerAttack(cur.dps, base) : null;
var nextHit = null;
if (next){
  // If backend doesn't provide next dps, approximate by current+? (won't be used usually).
  var nd = (nextDps!==null) ? nextDps : null;
  if (nd!==null) nextHit = calcDamagePerAttack(nd, base);
}

var lvRows = '';
stats = '';
addLine('⭐ Уровень', curLevel || '—', nextLevel);
addLine('⚔️ Урон (DPS)', cur ? fmtMaybe(cur.dps) : '—', (next && next.dps!==undefined ? fmtMaybe(next.dps) : (next ? '—' : null)));
addLine('🗡️ Урон за атаку', curHit, nextHit);
addLine('❤️ Здоровье', cur ? fmtMaybe(cur.hp) : '—', (next && next.hp!==undefined ? fmtMaybe(next.hp) : (next ? '—' : null)));
addLine('✨ Восстановление (умение)', cur ? fmtMaybe(cur.recovery) : '—', (next && next.recovery!==undefined ? fmtMaybe(next.recovery) : (next ? '—' : null)));

var statsHtml = '' +
  baseHtml +
  '<div class="coc-hsection-title">ТЕКУЩИЙ / СЛЕДУЮЩИЙ УРОВЕНЬ</div>' +
  '<div class="coc-hlevel">'+stats+'</div>';

stats = statsHtml;

    var upgradeInfo = '';
    if (next && !upgrading){
      upgradeInfo =
        '<div class="coc-hupgrade">' +
          '<div class="coc-hupgrade-item"><span>💰 Стоимость:</span> <b>'+esc(nextCost)+'</b> <img class="coc-res-ico" src="'+esc(resIconAny(next.res_type||''))+'" alt=""></div>' +
          '<div class="coc-hupgrade-item"><span>⏳ Время:</span> <b>'+esc(nextTime)+'</b></div>' +
        '</div>';
    } else if (upgrading){
      upgradeInfo =
        '<div class="coc-hupgrade">' +
          '<div class="coc-hupgrade-item"><span>🔧 Улучшение:</span> <b>в процессе</b></div>' +
          '<div class="coc-hupgrade-item"><span>⏳ Осталось:</span> <b data-herotimer-end="'+esc(String(endAt||0))+'">'+esc(formatHeroTime(left))+'</b></div>' +
        '</div>';
    }

    var maxLvl = heroMaxLevel(h) || 0;
var capLvl = heroCapLevel(h) || 0;
// Prefer total max level if provided by backend; otherwise fall back to cap.
var shownMax = (maxLvl > 0) ? maxLvl : capLvl;
var levelText = unlocked
  ? ('Уровень '+esc(String(lvl)) + (shownMax ? (' / '+esc(String(shownMax))) : '') + ((capLvl && shownMax && capLvl < shownMax) ? (' <span class="coc-hcap">(доступно до '+esc(String(capLvl))+')</span>') : ''))
  : ('Не разблокирован' + (shownMax ? (' <span class="coc-hcap">(макс. '+esc(String(shownMax))+')</span>') : ''));

    return '' +
      '<div class="coc-hmodal-overlay" id="coc-hmodal">' +
        '<div class="coc-hmodal" role="dialog" aria-modal="true" aria-label="Герой">' +
          '<button type="button" class="coc-hmodal-close" title="Закрыть" data-heromodalclose="1">×</button>' +

          '<div class="coc-hheader">' +
            '<div class="coc-hheader-name">👑 '+esc(name)+'</div>' +
            '<div class="coc-hheader-level">'+levelText+'</div>' +
          '</div>' +

          '<div class="coc-hmodal-top">' +
            '<div class="coc-hportrait">' + imgWithFallback(heroImgFromId(id), ['/images/heroes/Avatar_Hero_Barbarian_King.png']) + '</div>' +
            '<div class="coc-hmeta">' +
              (lore ? ('<div class="coc-hlore">📜 '+esc(lore)+'</div>') : '') +
              upgradeInfo +
            '</div>' +
          '</div>' +

          (stats ? ('<div class="coc-hstats">' +
            '<div class="coc-hstats-head">' +
              '<div class="coc-hstats-title">ХАРАКТЕРИСТИКИ</div>' +
              (next ? '<div class="coc-hstats-sub">Текущий › Следующий</div>' : '<div class="coc-hstats-sub">Текущий уровень</div>') +
            '</div>' +
            '<div class="coc-hstats-body">'+stats+'</div>' +
          '</div>') : '') +

          '<div class="coc-hactions">' +
            '<button type="button" class="coc-speedup-btn'+btnClsExtra+'" id="coc-hero-act" data-heroact="'+esc(id)+'"'+((isDisabled && lock)?(' data-herolockmsg="'+esc(lock)+'"'):'')+'>'+esc(btnLabel)+'</button>' +
            (upgrading ? ('<button type="button" class="coc-speedup-btn" id="coc-hero-speedup" data-herospeedup="'+esc(id)+'">УСКОРИТЬ за '+esc(formatNumber(parseInt(h.speedup_cost,10)||0))+' '+resIconImg('gems')+'</button>') : '') +
            '' +
          '</div>' +
        '</div>' +
      '</div>';
  }

  function renderHeroHallPanel(){
    var hh = state.heroHall || null;
    var hhLvl = hh ? (parseInt(hh.level,10)||0) : 0;
    var hhStatus = hh ? String(hh.status||'none') : 'none';
    var hhFinish = hh ? (parseInt(hh.finish_time,10)||0) : 0;
    var busy = (hhStatus && hhStatus !== 'active' && hhStatus !== 'none');
    var left = busy ? Math.max(0, hhFinish - nowServer()) : 0;

    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-building-head">' +
        '<div class="coc-building-title">ЗАЛ ГЕРОЕВ</div>' +
        '<div class="coc-building-sub">Нужен для героев и их улучшения</div>' +
      '</div>' +
      '<div class="coc-detail-time">Уровень: '+esc(String(hhLvl))+'</div>' +
      (busy ? ('<div class="coc-detail-time">⏳ Осталось: '+esc(formatTime(left))+'</div>') : '') +
      '<div class="coc-detail-actions">' +
        '<button type="button" class="coc-speedup-btn" data-bopen="hero_hall" data-binfo="hero_hall">ОТКРЫТЬ</button>' +
      '</div>' +
    '</div>';
  }

  function renderHeroesTab(){
    // Heroes tab shows only heroes. Hero Hall building is available in the Buildings tab.
    // Hero modal is mounted in a portal (document.body) to avoid clipping/transform issues.
    return renderHeroesGrid();
  }

  // -------------------- Heroes debug helpers --------------------
  // Enable in console: window.__HERO_DEBUG__ = true
  function heroDbg(){
    try{
      if (!window.__HERO_DEBUG__) return;
      var args = Array.prototype.slice.call(arguments);
      args.unshift('[HERO]');
      console.log.apply(console, args);
    }catch(_e){}
  }
var heroActionInFlight = {};

// Dedup guard for laboratory start actions (units/spells).
// Some browsers/devices can trigger duplicate click handlers; also multiple UI layers may exist.
var labStartInFlight = {};
var labStartLastClick = {};
var state = {
    _cancelLock: false, // lock queue/pending updates during mass cancel to avoid UI bounce

    tab: 'army',
    trainingModel: 'queue',
    capNow: 0,
    capMax: 0,
    // what is currently in army (player troops)
    army: {},
    // ready but waiting for space
    pending: {},
    // training queue
    queue: [],

    // локальные таймеры очереди (когда бэкенд не отдаёт finish_time по каждому юниту)
    _localQueueEndAt: {},
    _localQueueSig: {},
    // spells (instant composition)
    spellsArmy: {},
    spellUsed: 0,
    spellCap: 0,
    // legacy spell queue kept empty (UI panel)
    spellQueue: [],

    // армейные здания (для вкладки "Здания")
    buildings: [],
    buildingInfo: null,

    // heroes (Stage 11)
    heroes: [],
    heroHall: null,
    heroInfo: null,
    heroModalOpen: false,
    texts: null
  };

  // --- Backend sync state ---
  var _sync = {
    serverTime: 0,
    clientSyncAt: 0,
    firstFinish: 0,
    refreshTimer: null,
    flushTimer: null,
    spellFlushTimer: null,
    trainBuffer: {},
    spellBuffer: {},
    busy: false
  };

  function nowServer(){
    if (!_sync.serverTime) return Math.floor(Date.now() / 1000);
    var elapsed = Math.floor((Date.now() - _sync.clientSyncAt) / 1000);
    return _sync.serverTime + Math.max(0, elapsed);
  }

  // --- UI timer ticker (per-second countdown + pulse + optional sound) ---
  var _uiTimerTicker = null;
  var _lastTimerLeft = Object.create(null);
  var _pendingRefreshAt = 0;
  var _uiAudio = { enabled:false, ctx:null, lastTick:0 };

  function enableTickSound(){
    if (_uiAudio.enabled) return;
    try{
      var Ctx = window.AudioContext || window.webkitAudioContext;
      if (!Ctx) return;
      _uiAudio.ctx = new Ctx();
      _uiAudio.enabled = true;
    }catch(e){}
  }

  function playTick(){
    if (!_uiAudio.enabled || !_uiAudio.ctx) return;
    try{
      // keep it subtle
      var ctx = _uiAudio.ctx;
      if (ctx.state === 'suspended') ctx.resume();
      var o = ctx.createOscillator();
      var g = ctx.createGain();
      o.type = 'square';
      o.frequency.value = 880;
      g.gain.value = 0.02;
      o.connect(g); g.connect(ctx.destination);
      o.start();
      o.stop(ctx.currentTime + 0.03);
    }catch(e){}
  }

  function startUiTimerTicker(){
    if (_uiTimerTicker) return;
    _uiTimerTicker = setInterval(function(){
      var now = nowServer();
      // Building upgrade/build timers (cards).
      // IMPORTANT: must be cheap on mobile. We only update textContent.
      var els = document.querySelectorAll('[data-btimer-end]');
      var anyActive = false;
      for (var i=0;i<els.length;i++){
        var el = els[i];
        var endAt = parseInt(el.getAttribute('data-btimer-end'),10)||0;
        if (!endAt) continue;
        var left = Math.max(0, endAt - now);
        el.textContent = formatTime(left);
          if (left>0) anyActive = true;
      }

      // spell queue timers (first brewed item)
      var qels = document.querySelectorAll('[data-qtimer-end]');
      for (var j=0;j<qels.length;j++){
        var qel = qels[j];
        var qend = parseInt(qel.getAttribute('data-qtimer-end'),10)||0;
        if (!qend) continue;
        var qleft = Math.max(0, qend - now);
        qel.textContent = formatTime(qleft);
        if (qleft>0) anyActive = true;
      }

      // hero upgrade timers
      var hels = document.querySelectorAll('[data-herotimer-end]');
      for (var k=0;k<hels.length;k++){
        var hel = hels[k];
        var hend = parseInt(hel.getAttribute('data-herotimer-end'),10)||0;
        if (!hend){ continue; }
        var hleft = Math.max(0, hend - now);
        hel.textContent = formatHeroTime(hleft);
        if (hleft <= 0){
          // don't show stale 0:00 labels on cards
          if (hel.classList) hel.classList.add('is-hidden');
        } else {
          if (hel.classList) hel.classList.remove('is-hidden');
          anyActive = true;
        }
      }
      
      function scheduleRefreshOnEnd(key, left){
        // detect transition >0 -> 0
        var prev = _lastTimerLeft[key];
        _lastTimerLeft[key] = left;
        if (typeof prev === 'number' && prev > 0 && left === 0){
          var nowMs = Date.now();
          if (nowMs - _pendingRefreshAt > 1500){
            _pendingRefreshAt = nowMs;
            try{ loadServerState(true); }catch(e){}
          }
        }
      }

      // detect ends for building/spell/hero timers
      try{
        for (var i2=0;i2<els.length;i2++){
          var e2 = els[i2];
          var end2 = parseInt(e2.getAttribute('data-btimer-end'),10)||0;
          if (!end2) continue;
          var l2 = Math.max(0, end2 - now);
          scheduleRefreshOnEnd('b:'+end2+':'+i2, l2);
        }
        for (var j2=0;j2<qels.length;j2++){
          var qe2 = qels[j2];
          var qend2 = parseInt(qe2.getAttribute('data-qtimer-end'),10)||0;
          if (!qend2) continue;
          var ql2 = Math.max(0, qend2 - now);
          scheduleRefreshOnEnd('q:'+qend2+':'+j2, ql2);
        }
        for (var k2=0;k2<hels.length;k2++){
          var he2 = hels[k2];
          var hend2 = parseInt(he2.getAttribute('data-herotimer-end'),10)||0;
          if (!hend2) continue;
          var hl2 = Math.max(0, hend2 - now);
          scheduleRefreshOnEnd('h:'+hend2+':'+k2, hl2);
        }
      }catch(_e_end){}
// sound once per second if any timers visible and page is visible
      if (anyActive && document.visibilityState === 'visible'){
        var t = Math.floor(Date.now()/1000);
        if (t !== _uiAudio.lastTick){
          _uiAudio.lastTick = t;
          /* tick sound disabled */
        }
      }
    }, 1000);
  }



function _ruPlural(n, one, few, many){
  n = Math.abs(n) % 100;
  var n1 = n % 10;
  if (n > 10 && n < 20) return many;
  if (n1 > 1 && n1 < 5) return few;
  if (n1 == 1) return one;
  return many;
}
  // Used in research/progress UI. Keep it compact like the rest of timers.
  function formatDurationSmart(totalSeconds){
  var s = parseInt(totalSeconds, 10);
  if (isNaN(s) || s < 0) s = 0;

  var day = 86400, hour = 3600, min = 60;

  // Delegate to the common compact formatter.
  return formatTimeSmart(s);
}



  // Legacy queue timers
  function getFirstTimeLeft(){
    var ft = parseInt(_sync.firstFinish, 10) || 0;
    if (!ft) return 0;
    return Math.max(0, ft - nowServer());
  }

  function getSpellFirstTimeLeft(){
    var ft = parseInt(_sync.spellFirstFinish, 10) || 0;
    if (!ft) return 0;
    return Math.max(0, ft - nowServer());
  }

  function scheduleAutoRefresh(){
    if (_sync.refreshTimer){ clearTimeout(_sync.refreshTimer); _sync.refreshTimer = null; }
    if (!isModalOpen()) return;

    // Timers that must tick while the modal is open
    var hasTroopQueue = (state.trainingModel !== 'instant') && (_sync.firstFinish || (state.queue && state.queue.length) || (state.trainFinish && Object.keys(state.trainFinish).length) || (state.optTrain && Object.keys(state.optTrain).length));
    var hasSpellQueue = !!(_sync.spellFirstFinish || (state.spellQueue && state.spellQueue.length));

    var now = nowServer();
    var hasBuildingTimers = false;
    var i, b;
    if (state.buildings && state.buildings.length){
      for (i=0;i<state.buildings.length;i++){
        b = state.buildings[i] || {};
        var st = String(b.status||'');
        var ft = parseInt(b.finish_time,10) || 0;
        if ((st === 'constructing' || st === 'upgrading') && ft > now){ hasBuildingTimers = true; break; }
      }
    }
    if (!hasBuildingTimers && state.buildingInfo && state.buildingInfo.building){
      b = state.buildingInfo.building;
      var st2 = String(b.status||'');
      var ft2 = parseInt(b.finish_time,10) || 0;
      if ((st2 === 'constructing' || st2 === 'upgrading') && ft2 > now) hasBuildingTimers = true;
    }

    // Optional: hero upgrades
    var hasHeroTimers = false;
    if (state.heroes && state.heroes.length){
      for (i=0;i<state.heroes.length;i++){
        var h = state.heroes[i] || {};
        var until = parseInt(h.upgrading_until,10) || 0;
        if (until > now){ hasHeroTimers = true; break; }
      }
    }

    if (!hasTroopQueue && !hasSpellQueue && !hasBuildingTimers && !hasHeroTimers) return;

    _sync.refreshTimer = setTimeout(function(){
      if (!isModalOpen()) return;

      // When something finishes, reload from server.
      var needReload = false;
      if ((_sync.firstFinish && getFirstTimeLeft() <= 0) || (_sync.spellFirstFinish && getSpellFirstTimeLeft() <= 0)) needReload = true;

      var now2 = nowServer();
      // Periodic poll while troop queue is active (keeps camp updated without page refresh)
      if (!needReload && hasTroopQueue){
        var lp = parseInt(_sync.lastTroopPoll,10)||0;
        if (!lp) lp = now2;
        if ((now2 - lp) >= 3){
          needReload = true;
        }
      }

      if (!needReload && state.buildings && state.buildings.length){
        for (i=0;i<state.buildings.length;i++){
          b = state.buildings[i] || {};
          var st = String(b.status||'');
          var ft = parseInt(b.finish_time,10) || 0;
          if ((st === 'constructing' || st === 'upgrading') && ft && ft <= now2){ needReload = true; break; }
        }
      }
      if (!needReload && state.buildingInfo && state.buildingInfo.building){
        b = state.buildingInfo.building;
        var st = String(b.status||'');
        var ft = parseInt(b.finish_time,10) || 0;
        if ((st === 'constructing' || st === 'upgrading') && ft && ft <= now2) needReload = true;
      }
      if (!needReload && state.heroes && state.heroes.length){
        for (i=0;i<state.heroes.length;i++){
          var h = state.heroes[i] || {};
          var until = parseInt(h.upgrading_until,10) || 0;
          if (until && until <= now2){ needReload = true; break; }
        }
      }

      if (needReload){
        loadServerState(true).then(function(){
          try{ _sync.lastTroopPoll = nowServer(); }catch(e){}
          // keep building detail fresh
          if (state.buildingInfo && state.buildingInfo.building && state.buildingInfo.building.id){
            var bid = state.buildingInfo.building.id;
            apiGetParams('building_info', { building_id: bid }).then(function(d){ state.buildingInfo = d; render(); scheduleAutoRefresh(); }).catch(function(){ render(); scheduleAutoRefresh(); });
            return;
          }
          render();
          refreshOpenBuildingDetailModal();
          scheduleAutoRefresh();
        }).catch(function(){
          render();
          scheduleAutoRefresh();
        });
        return;
      }

      // Otherwise just tick timers (без полного render() — иначе прыгает скролл каждую секунду).
      // DOM-таймеры обновляет startUiTimerTicker() (data-btimer-end / data-qtimer-end / data-herotimer-end).
      scheduleAutoRefresh();
    }, 1000);
  }

  // Compatibility alias: some UI flows call startTick().
  // Keep a single ticking mechanism via scheduleAutoRefresh().
  function startTick(){
    scheduleAutoRefresh();
  }


  function groupQueue(rows, status){
    rows = rows || [];
    var out = [];
    var cur = null;

    for (var i=0; i<rows.length; i++){
      var r = rows[i] || {};
      if ((r.status || '') !== status) continue;
      var uid = r.unit_id || r.id;
      if (!uid) continue;

      if (cur && cur.id === uid){
        cur.qty += (parseInt(r.qty, 10) || 1);
        cur._qids.push(parseInt(r.id, 10) || 0);
        var _ft = parseInt(r.finish_time, 10) || 0;
        if (_ft) cur._finish = _ft;
      } else {
        cur = {
          id: uid,
          qty: (parseInt(r.qty, 10) || 1),
          _qids: [parseInt(r.id, 10) || 0],
          _finish: parseInt(r.finish_time, 10) || 0
        };
        out.push(cur);
      }
    }

    for (var j=0; j<out.length; j++){
      var qids = out[j]._qids || [];
      out[j].qid = qids.length ? qids[qids.length - 1] : 0;
      out[j].finish_time = out[j]._finish || 0;
    }

    return out;
  }

  function groupPending(rows){
    rows = rows || [];
    var pending = {};
    for (var i=0; i<rows.length; i++){
      var r = rows[i] || {};
      if ((r.status || '') !== 'ready') continue;
      var uid = r.unit_id || r.id;
      if (!uid) continue;
      pending[uid] = (pending[uid] || 0) + (parseInt(r.qty, 10) || 1);
    }
    return pending;
  }

  function applyBackendState(data){
    if (data && Array.isArray(data.troops)) {
      troopDefs = data.troops.map(function(x){
        var cost = 0, res = 'elixir';
        if (x && x.train){
          if (typeof x.train.cost !== 'undefined') cost = parseInt(x.train.cost,10) || 0;
          if (typeof x.train.res !== 'undefined') res = String(x.train.res||'elixir');
        }
        return {
          id: String(x.id||''),
          name: String(x.name||x.id||''),
          level: (typeof x.level !== 'undefined') ? (parseInt(x.level,10)||1) : 1,
          cost: cost,
          res: res,
          img: normalizeImgPath(String(x.img||'')),
          owned: !!x.unlocked,
          locked: !!x.locked,
          locked_reason: String(x.locked_reason||''),
          trainTime: (typeof x.training_time !== 'undefined' ? (parseInt(x.training_time,10)||0) : (typeof x.train_time !== 'undefined' ? (parseInt(x.train_time,10)||0) : (x.train && typeof x.train.time !== 'undefined' ? (parseInt(x.train.time,10)||0) : 0))),
          space: (typeof x.housing_space !== 'undefined') ? (parseInt(x.housing_space,10)||1) : 1
        };
      }).filter(function(t){ return t.id; });
    }

    if (!data) return;

    _sync.serverTime = parseInt(data.server_time, 10) || Math.floor(Date.now() / 1000);
    _sync.clientSyncAt = Date.now();

    var blvl = parseInt(data.barracks_level, 10) || 0;
    state.barracks = [blvl, 0, 0, 0];

    var used = 0, cap = 0;
    if (data.camp){
      used = parseInt(data.camp.used, 10) || 0;
      cap = parseInt(data.camp.cap, 10) || 0;
    }
    state.capNow = used;
    state.capMax = cap;
    state.army = data.army || {};

    // ресурсы пользователя (для шапки страницы)
    if (data && data.user){
      state.user = data.user;
      syncBalanceIndicators(state.user);
    }

    // Stage 3.2: spells catalog + composition + capacity
    if (data && Array.isArray(data.spells)) {
      spellDefs = data.spells.map(function(x){
        var id = String((x && x.id) ? x.id : '');
        if (!id) return null;
        var type = String((x && x.type) ? x.type : 'spell');
        var res = (type === 'dark_spell' || type === 'darkSpell' || type === 'dark') ? 'dark_elixir' : 'elixir';
        // Backend may return legacy paths like /images/spells/lightning_spell.png (404).
        // Prefer canonical "*_Spell_info.png".
        var rawImg = String((x && x.img) ? x.img : '');
        var img = normalizeImgPath(rawImg || spellImgFromId(id));
        if (!/\/images\/spells\/.+_info\.png$/i.test(img)){
          img = spellImgFromId(id);
        }
        return {
          id: id,
          name: String((x && x.name) ? x.name : id),
          kind: 'spell',
          type: 'spell',
          level: (typeof (x && x.level) !== 'undefined') ? (parseInt(x.level,10)||1) : 1,
          cost: 0,
          res: res,
          img: img,
          fb: [img, resIcon(res)],
          owned: !!(x && x.unlocked),
          locked: !!(x && x.locked),
          locked_reason: String((x && x.locked_reason) ? x.locked_reason : ''),
          space: (typeof (x && x.housing_space) !== 'undefined') ? (parseInt(x.housing_space,10)||1) : 1
        };
      }).filter(function(v){ return v && v.id; });
    } else {
      spellDefs = spellDefs || [];
    }

    state.spellsArmy = (data && data.spells_army) ? data.spells_army : {};
    state.spellUsed = 0;
    state.spellCap = 0;
    if (data && data.spell){
      state.spellUsed = parseInt(data.spell.used, 10) || 0;
      state.spellCap = parseInt(data.spell.cap, 10) || 0;
    }
    // Stage 12: spell brew queue
    var srows = (data && Array.isArray(data.spell_queue)) ? data.spell_queue : [];
    state.spellQueue = [];
    _sync.spellFirstFinish = 0;
    if (srows && srows.length){
      for (var si=0; si<srows.length; si++){
        var r2 = srows[si] || {};
        if ((r2.status || '') !== 'training') continue;
        var sid2 = String(r2.spell_id || r2.id || '');
        if (!sid2) continue;
        state.spellQueue.push({
          id: sid2,
          qty: parseInt(r2.qty, 10) || 1,
          qid: parseInt(r2.id, 10) || 0,
          finish_time: parseInt(r2.finish_time, 10) || 0,
          time: parseInt(r2.time_left, 10) || 0
        });
        if (!_sync.spellFirstFinish){
          var ft2 = parseInt(r2.finish_time, 10) || 0;
          if (ft2 > 0) _sync.spellFirstFinish = ft2;
        }
      }
    }

    // buildings list for the Buildings tab
    state.buildings = (data && Array.isArray(data.buildings)) ? data.buildings : [];

    // heroes
    state.texts = (data && data.texts) ? data.texts : (state.texts||null);
    state.heroHall = (data && data.hero_hall) ? data.hero_hall : null;
    state.heroes = [];
    if (data && data.heroes){
      if (Array.isArray(data.heroes)) {
        state.heroes = data.heroes;
      } else if (typeof data.heroes === 'object'){
        try {
          for (var _hid in data.heroes){
            if (!data.heroes.hasOwnProperty(_hid)) continue;
            var _h = data.heroes[_hid] || {};
            _h.id = String(_h.id || _hid);
            state.heroes.push(_h);
          }
        } catch(_e){}
      }
    }
    // keep hero detail synced
    if (state.heroInfo && state.heroInfo.id && state.heroes && state.heroes.length){
      for (var _i=0; _i<state.heroes.length; _i++){
        if (String(state.heroes[_i].id) === String(state.heroInfo.id)){
          state.heroInfo = state.heroes[_i];
          break;
        }
      }
    }

    state.trainingModel = (typeof data.training_model !== 'undefined' && data.training_model) ? String(data.training_model) : 'queue';
    if (state.trainingModel === 'instant' && data && Array.isArray(data.queue) && data.queue.length){ state.trainingModel = 'queue'; }

    if (state.trainingModel === 'instant'){
      state.queue = [];
      state.pending = {};
      _sync.firstFinish = 0;
      scheduleAutoRefresh();
      return;
    }
    // Legacy (classic CoC): training queue + pending.
    var rows = (data && Array.isArray(data.queue)) ? data.queue : [];

    if (!state._cancelLock){
      state.queue = groupQueue(rows, 'training');
      state.pending = groupPending(rows);

      // First finish time (for countdown)
      _sync.firstFinish = 0;
      if (rows && rows.length){
        for (var i=0;i<rows.length;i++){
          var r = rows[i] || {};
          if ((r.status||'') !== 'training') continue;
          var ft = parseInt(r.finish_time, 10) || 0;
          if (ft && (!_sync.firstFinish || ft < _sync.firstFinish)) _sync.firstFinish = ft;
        }
      }
    }

    // during mass cancel we ignore queue/pending updates to prevent UI bouncing
    scheduleAutoRefresh();

  }

  
function syncBuildingFromInfo(info){
  try{
    if (!info || !info.building) return;
    var b = info.building;
    var arr = (state && state.buildings) ? state.buildings : null;
    if (arr && Array.isArray(arr)){
      for (var i=0;i<arr.length;i++){
        if (arr[i] && (arr[i].id===b.id || arr[i].building_id===b.id || arr[i].name===b.id)){
          arr[i].level = b.level;
          arr[i].is_built = b.is_built;
          if (b.finish_time!=null) arr[i].finish_time = b.finish_time;
          if (b.start_time!=null) arr[i].start_time = b.start_time;
          if (b.status) arr[i].status = b.status;
          if (b.target_level!=null) arr[i].target_level = b.target_level;
          if (b.next && b.next.time!=null) arr[i].next_time = b.next.time;
          break;
        }
      }
    }
    var arr2 = (state && state.barracksData && state.barracksData.buildings) ? state.barracksData.buildings : null;
    if (arr2 && Array.isArray(arr2)){
      for (var j=0;j<arr2.length;j++){
        if (arr2[j] && (arr2[j].id===b.id || arr2[j].building_id===b.id || arr2[j].name===b.id)){
          Object.assign(arr2[j], b);
          break;
        }
      }
    }
  }catch(_){ }
}
function loadServerState(silent){
    // allow background refresh when any countdown is visible on the page
    if (!isModalOpen()){
      var hasTimers = !!document.querySelector('[data-btimer-end],[data-qtimer-end],[data-herotimer-end]');
      if (!hasTimers) return;
    }
    return apiGet('barracks_state').then(function(data){
      applyBackendState(data);
      render();
      return data;
    }).catch(function(err){
      if (!silent) showBarracksToast('error', 'Казармы', (err && err.message) ? err.message : 'Ошибка загрузки');
    });
  }

  function flushTrainBuffer(){
  if (_sync.flushTimer){ clearTimeout(_sync.flushTimer); _sync.flushTimer = null; }

  // если прямо сейчас идет синк — повторим чуть позже, чтобы клики не терялись
  if (_sync.busy){
    _sync.flushTimer = setTimeout(flushTrainBuffer, 250);
    return;
  }

  var jobs = [];
  for (var id in _sync.trainBuffer){
    if (!_sync.trainBuffer.hasOwnProperty(id)) continue;
    var q = parseInt(_sync.trainBuffer[id], 10) || 0;
    if (q > 0) jobs.push({ id: id, qty: q });
  }
  _sync.trainBuffer = {};
  if (!jobs.length) return;

  _sync.busy = true;

  // параллельные запросы (быстрее и не блокирует удержание)
  Promise.all(jobs.map(function(j){
    return apiPost('barracks_train', { unit_id: j.id, qty: j.qty });
  })).then(function(){
    // IMPORTANT:
    // optTrain is only a визуальный "оптимистичный" слой.
    // После успешной отправки на сервер его нужно убрать, иначе при следующем barracks_state
    // фронт сложит backend queue + optTrain и игрок увидит +2 за один клик.
    try{
      if (state && state.optTrain){
        for (var i=0;i<jobs.length;i++){
          var jid = jobs[i].id;
          var jqty = parseInt(jobs[i].qty,10)||0;
          if (!jid || jqty<=0) continue;
          if (state.optTrain[jid]){
            state.optTrain[jid] = (parseInt(state.optTrain[jid],10)||0) - jqty;
            if ((parseInt(state.optTrain[jid],10)||0) <= 0) delete state.optTrain[jid];
          }
        }
      }
    }catch(_e){}

    _sync.busy = false;
    loadServerState(true);
  }).catch(function(err){
    _sync.busy = false;
    showBarracksToast('error', 'Тренировка', (err && err.message) ? err.message : 'Ошибка');
    loadServerState(true);
  });
}

function bufferTrainUnit(id, qty){
    if (!id) return;
    qty = parseInt(qty, 10) || 1;
    if (qty <= 0) return;
    _sync.trainBuffer[id] = (_sync.trainBuffer[id] || 0) + qty;
    if (_sync.flushTimer) clearTimeout(_sync.flushTimer);
    _sync.flushTimer = setTimeout(flushTrainBuffer, 200);
  }


  function flushSpellBuffer(){
    if (_sync.spellFlushTimer){ clearTimeout(_sync.spellFlushTimer); _sync.spellFlushTimer = null; }
    if (_sync.busy) return;

    var jobs = [];
    for (var id in _sync.spellBuffer){
      if (!_sync.spellBuffer.hasOwnProperty(id)) continue;
      var q = parseInt(_sync.spellBuffer[id], 10) || 0;
      if (q > 0) jobs.push({ id: id, qty: q });
    }
    _sync.spellBuffer = {};
    if (!jobs.length) return;

    _sync.busy = true;
    var p = Promise.resolve();
    jobs.forEach(function(j){
      p = p.then(function(){
        return apiPost('barracks_spell_add', { spell_id: j.id, qty: j.qty });
      });
    });

    p.then(function(){
      _sync.busy = false;
      loadServerState(true);
    }).catch(function(err){
      _sync.busy = false;
      showBarracksToast('error', 'Заклинания', (err && err.message) ? err.message : 'Ошибка');
      loadServerState(true);
    });
  }

  function bufferSpellAdd(id, qty){
    if (!id) return;
    qty = parseInt(qty, 10) || 1;
    if (qty <= 0) return;
    _sync.spellBuffer[id] = (_sync.spellBuffer[id] || 0) + qty;
    if (_sync.spellFlushTimer) clearTimeout(_sync.spellFlushTimer);
    _sync.spellFlushTimer = setTimeout(flushSpellBuffer, 200);
  }

  function cancelQueueItem(token, onBlock){
    onBlock = (typeof onBlock === 'function') ? onBlock : function(){};
    if (!token) return;
    // token: "u:<unitId>" (instant remove) or legacy queue tokens
    var s = String(token);

    // Mixed token: remove 1 unit from merged queue (optimistic first)
    if (s.indexOf('mix:') === 0){
      var mid = String(s.slice(4)||'');
      if (!mid) return;
      // If there are still buffered (unsent) train requests for this unit, cancel them first
      if (_sync && _sync.trainBuffer && _sync.trainBuffer[mid] && (parseInt(_sync.trainBuffer[mid],10)||0) > 0){
        _sync.trainBuffer[mid] = (parseInt(_sync.trainBuffer[mid],10)||0) - 1;
        if (_sync.trainBuffer[mid] <= 0) delete _sync.trainBuffer[mid];
        if (state.optTrain && state.optTrain[mid] && (parseInt(state.optTrain[mid],10)||0) > 0){
          state.optTrain[mid] = (parseInt(state.optTrain[mid],10)||0) - 1;
          if (state.optTrain[mid] <= 0) delete state.optTrain[mid];
        }
        render();
        return;
      }

      // optimistic: prefer removing from optimistic buffer
      if (state.optTrain && state.optTrain[mid] && (parseInt(state.optTrain[mid],10)||0) > 0){
        state.optTrain[mid] = (parseInt(state.optTrain[mid],10)||0) - 1;
        if (state.optTrain[mid] <= 0) delete state.optTrain[mid];
        render();
        return;
      }
      // otherwise remove from server queue if possible (pick last qid from last matching group)
      var qid = 0;
      if (state.queue && state.queue.length){
        for (var i=state.queue.length-1;i>=0;i--){
          var it = state.queue[i] || {};
          if (String(it.id||'') !== mid) continue;
          if (it._qids && it._qids.length){
            qid = parseInt(it._qids[it._qids.length-1],10)||0;
          }
          // optimistic local decrement
          it.qty = (parseInt(it.qty,10)||0) - 1;
          if (it._qids && it._qids.length) it._qids.pop();
          if (it.qty <= 0){
            state.queue.splice(i,1);
          }
          break;
        }
      }
      render();
      if (qid){
        apiPost('barracks_cancel', { queue_id: qid }).then(function(){
          if (!state._cancelLock) loadServerState(true);
        }).catch(function(){ if (!state._cancelLock) loadServerState(true); });
      } else {
        // fallback reload
        if (!state._cancelLock) loadServerState(true);
      }
      return;
    }
    
// Stage 12b: cancel spell brew queue by spell type (works with grouped tiles + hold remove)
if (s.indexOf('sqt:') === 0){
  var stid = String(s.slice(4) || '');
  if (!stid) return;

  // pick next real queue_id for this spell from current state
  var qid2 = 0;
  if (state.spellQueue && state.spellQueue.length){
    for (var si=0; si<state.spellQueue.length; si++){
      var sit = state.spellQueue[si] || {};
      if (String(sit.id||'') !== stid) continue;
      qid2 = parseInt(sit.qid,10) || 0;
      // optimistic decrement/remove (queue entries are usually qty=1)
      var sq = parseInt(sit.qty,10) || 1;
      if (sq > 1){
        sit.qty = sq - 1;
      } else {
        state.spellQueue.splice(si,1);
      }
      break;
    }
  }
  render();
  if (!qid2){
    // nothing to cancel -> stop hold
    try{ onBlock(true); }catch(_e){}
    return;
  }
  apiPost('barracks_spell_cancel', { queue_id: qid2 }).then(function(){
    if (!state._cancelLock) loadServerState(true);
  }).catch(function(err){
    // ignore "not found" / race conditions silently
    var msg2 = (err && err.message) ? String(err.message) : '';
    if (!/нет|not\s*found|missing|отсутств/i.test(msg2)){
      showBarracksToast('error', 'Заклинания', msg2 || 'Ошибка');
    }
    if (!state._cancelLock) loadServerState(true);
  });
  return;
}

// Stage 12: cancel spell brew queue item
    if (s.indexOf('sq:') === 0){
      var sqid = parseInt(s.slice(3), 10) || 0;
      if (!sqid){ try{ onBlock(true); }catch(_e){}; return; }
      apiPost('barracks_spell_cancel', { queue_id: sqid }).then(function(){
        if (!state._cancelLock) loadServerState(true);
      }).catch(function(err){
        var msgS = (err && err.message) ? String(err.message) : '';
        if (/400|bad\s*request|not\s*found|missing|отсутств|нет/i.test(msgS)){
          try{ onBlock(true); }catch(_e){}
          return;
        }
        showBarracksToast('error', 'Заклинания', msgS || 'Ошибка');
        if (!state._cancelLock) loadServerState(true);
      });
      return;
    }

    // remove brewed spell from composition
    if (s.indexOf('s:') === 0){
      var sid = s.slice(2);
      if (!sid) return;

      // Prevent toast spam: if there is nothing to remove, do nothing silently.
      var curQty = 0;
      try{ curQty = (state && state.spellsArmy && state.spellsArmy[sid]) ? parseInt(state.spellsArmy[sid],10) : 0; }catch(_){}
      if (!curQty || curQty <= 0) return;

      apiPost('barracks_spell_remove', { spell_id: sid, qty: 1 }).then(function(){
        if (!state._cancelLock) loadServerState(true);
      }).catch(function(err){
        // If backend says "not found"/"нет" - ignore silently (race conditions)
        var msg = (err && err.message) ? String(err.message) : '';
        if (/400|bad\s*request/i.test(msg)){
          try{ onBlock(true); }catch(_e){}
          return;
        }
        if (!/нет|not\s*found|missing|отсутств/i.test(msg)){
          showBarracksToast('error', 'Заклинания', msg || 'Ошибка');
        }
        if (!state._cancelLock) loadServerState(true);
      });
      return;
    }

    // Stage 2 (instant): remove 1 unit from current army
    if (s.indexOf('u:') === 0){
      var uid = s.slice(2);
      if (!uid) return;

      // Do not spam backend when holding "-" after army is already empty
      var curU = 0;
      try{ curU = (state && state.army && typeof state.army[uid] !== 'undefined') ? (parseInt(state.army[uid],10)||0) : 0; }catch(_e){}
      if (!curU || curU <= 0){
        try{ if (typeof onBlock === 'function') onBlock(true); }catch(_e2){}
        return;
      }

      // optimistic local decrement to prevent repeated POSTs during hold
      try{ state.army[uid] = Math.max(0, (parseInt(state.army[uid],10)||0) - 1); }catch(_e3){}
      render();

      apiPost('barracks_remove', { unit_id: uid, qty: 1 }).then(function(){
        if (!state._cancelLock) loadServerState(true);
      }).catch(function(err){
        var msgU = (err && err.message) ? String(err.message) : '';
        // Ignore typical "empty" / 400 race when holding
        if (/400|bad\s*request|not\s*found|missing|отсутств|нет/i.test(msgU)){
          try{ if (typeof onBlock === 'function') onBlock(true); }catch(_e4){}
          return;
        }
        showBarracksToast('error', 'Армия', msgU || 'Ошибка');
        if (!state._cancelLock) loadServerState(true);
      });
      return;
    }

// Legacy queue cancel (if someone still has old queue entries)
    if (s.indexOf('q:') === 0){
      var qid = parseInt(s.slice(2), 10) || 0;
      if (!qid) return;
      apiPost('barracks_cancel', { queue_id: qid }).then(function(){
        if (!state._cancelLock) loadServerState(true);
      }).catch(function(err){
        var msgQ = (err && err.message) ? String(err.message) : '';
        if (/400|bad\s*request|not\s*found|missing|отсутств|нет/i.test(msgQ)){
          try{ onBlock(true); }catch(_e){}
          return;
        }
        showBarracksToast('error', 'Очередь', msgQ || 'Ошибка');
        if (!state._cancelLock) loadServerState(true);
      });
      return;
    }

    if (s.indexOf('i:') === 0){
      var idx = parseInt(s.slice(2), 10);
      if (isNaN(idx) || idx < 0 || idx >= state.queue.length) return;
      state.queue.splice(idx, 1);
      render();
      return;
    }

    // fallback old numeric
    var idx2 = parseInt(s, 10);
    if (!isNaN(idx2) && idx2 >= 0 && idx2 < state.queue.length){
      state.queue.splice(idx2, 1);
      render();
    }
  }


  function resIcon(res){
    res = String(res||'elixir');
    if (res === 'dark_elixir') return '/images/icons/dark_elixir.png';
    if (res === 'gold') return '/images/icons/gold.png';
    return '/images/icons/elixir.png';
  }


  function spellImgFromId(id){
  id = String(id||"" );
  if (!id) return "/images/icons/elixir.png";
  var parts = id.split("_").filter(function(p){ return p; });
  for (var i=0;i<parts.length;i++){
    var w = parts[i];
    parts[i] = w ? (w.charAt(0).toUpperCase() + w.slice(1)) : w;
  }
  var name = parts.join("_");
  return "/images/spells/" + name + "_info.png";
}

function spellFallbackDesc(id, cur, onlyStats){
  id = String(id||"").toLowerCase();
  cur = cur || {};
  onlyStats = !!onlyStats;

  function n(v){
    if (v===undefined || v===null || v==="") return null;
    var x = Number(v);
    return isFinite(x) ? x : null;
  }
  function pick(keys){
    for (var i=0;i<keys.length;i++){
      var k = keys[i];
      if (cur && cur[k]!==undefined) return cur[k];
    }
    return null;
  }
  function fmtNum(x){
    var v = n(x);
    if (v===null) return "";
    if (Math.abs(v - Math.round(v)) < 1e-9) return String(Math.round(v));
    return String(v).replace(".", ",");
  }
  function fmtSec(x){
    var v = n(x);
    if (v===null) return "";
    return fmtNum(v) + " сек.";
  }
  function fmtPct(x){
    var v = n(x);
    if (v===null) return "";
    // allow both 0.30 and 30 formats
    if (v>0 && v<=1) v = v*100;
    return fmtNum(v) + "%";
  }

  // common stats (try many keys)
  var dmg    = n(pick(["damage","dmg","hit_damage","strike_damage","damage_total","total_damage","damage_amount"]));
  var dps    = n(pick(["dps","damage_per_sec","damage_per_second","damage_ps","damage_sec","damage_per_s"]));
  var healps = n(pick(["heal_per_sec","healing_per_sec","hps","healps","healing_per_second","heal_sec","heal_per_s"]));
  var heal   = n(pick(["heal","healing","total_heal","heal_amount","healing_total"]));
  var dur    = n(pick(["duration","dur","time","seconds","duration_sec","dur_sec","time_sec","time_seconds"]));
  var rad    = n(pick(["radius","range","area","area_radius","radius_tiles","radius_tile"]));

  // boosts / modifiers
  var dmgBoostPct   = n(pick(["damage_boost_percent","damage_percent","damage_increase_percent","dmg_boost_percent","dmg_percent"]));
  var atkSpdBoostPct= n(pick(["attack_speed_boost_percent","attack_speed_percent","atk_speed_percent","atk_spd_percent"]));
  var moveSpdPct    = n(pick(["speed_boost","speed_percent","move_speed_percent","move_speed_boost_percent","move_speed_boost"]));
  var slowPct       = n(pick(["slow_percent","speed_reduction_percent","move_speed_reduction_percent","speed_reduction","slow"]));
  var atkSlowPct    = n(pick(["attack_speed_reduction_percent","attack_slow_percent","atk_speed_reduction_percent"]));

  // special capacities / counts
  var cap = n(pick(["capacity","housing_space","max_housing_space","max_space","max_capacity","max_targets","targets"]));
  var units = n(pick(["units","count","spawn_count","spawn","spawned_units","spawn_amount"]));

  // helper: build stats list in RU
  function statsList(){
    var parts = [];
    if (dmg!==null) parts.push("урон: "+fmtNum(dmg));
    if (dps!==null) parts.push("урон/сек: "+fmtNum(dps));
    if (healps!==null) parts.push("лечение/сек: "+fmtNum(healps));
    if (heal!==null && healps===null) parts.push("лечение: "+fmtNum(heal));
    if (dur!==null) parts.push("длительность: "+fmtSec(dur));
    if (rad!==null) parts.push("радиус: "+fmtNum(rad));
    if (dmgBoostPct!==null) parts.push("усиление урона: "+fmtPct(dmgBoostPct));
    if (atkSpdBoostPct!==null) parts.push("скорость атаки: "+fmtPct(atkSpdBoostPct));
    if (moveSpdPct!==null) parts.push("скорость передвижения: "+fmtPct(moveSpdPct));
    if (slowPct!==null) parts.push("замедление: "+fmtPct(slowPct));
    if (atkSlowPct!==null) parts.push("замедление атаки: "+fmtPct(atkSlowPct));
    if (cap!==null) parts.push("вместимость: "+fmtNum(cap));
    if (units!==null) parts.push("кол-во: "+fmtNum(units));
    return parts;
  }

  var parts0 = statsList();
  var statsLine = parts0.length ? ("Параметры: " + parts0.join(", ") + ".") : "";

  if (onlyStats) return statsLine;

  // per spell narrative + numbers
  if (id==="lightning_spell"){
    return "Молния — наносит разовый урон по выбранной области." + (statsLine?(" " + statsLine):"");
  }
  if (id==="heal_spell" || id==="healing_spell"){
    return "Лечение — восстанавливает здоровье войск в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="rage_spell"){
    return "Ярость — увеличивает урон и скорость атаки войск в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="haste_spell"){
    return "Ускорение — увеличивает скорость передвижения войск в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="freeze_spell"){
    return "Заморозка — временно останавливает войска и здания в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="jump_spell"){
    return "Прыжок — позволяет войскам перепрыгивать через стены в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="poison_spell"){
    return "Яд — наносит постепенный урон и замедляет войска противника в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="earthquake_spell"){
    return "Землетрясение — наносит урон зданиям и стенам в области действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="clone_spell"){
    return "Клон — создаёт копии войск, попавших в область действия (в пределах вместимости)." + (statsLine?(" " + statsLine):"");
  }
  if (id==="invisibility_spell"){
    return "Невидимость — делает войска невидимыми для обороны на время действия." + (statsLine?(" " + statsLine):"");
  }
  if (id==="recall_spell"){
    return "Отзыв — возвращает выбранные войска домой, освобождая место." + (statsLine?(" " + statsLine):"");
  }
  if (id==="skeleton_spell"){
    return "Скелеты — призывает скелетов, которые отвлекают оборону." + (statsLine?(" " + statsLine):"");
  }
  if (id==="bat_spell"){
    return "Летучие мыши — призывает стаю летучих мышей для атаки." + (statsLine?(" " + statsLine):"");
  }
  if (id==="overgrowth_spell"){
    return "Разрастание — сдерживает и замедляет войска и оборону в области действия." + (statsLine?(" " + statsLine):"");
  }

  // generic fallback with numbers
  return statsLine;
}

  function defById(id){
    for (var i=0;i<troopDefs.length;i++) if (troopDefs[i].id===id) return troopDefs[i];
    return null;
  }

  // --- Capacity helpers (army + pending + queue + optimistic) ---
  function calcUsedCapacity(){
    var used = 0;
    function addMap(mp){
      if (!mp) return;
      for (var k in mp){
        if (!mp.hasOwnProperty(k)) continue;
        var q = parseInt(mp[k],10)||0;
        if (q<=0) continue;
        var d = defById(k) || {};
        var sp = parseInt(d.space,10)||1;
        used += q * sp;
      }
    }
    addMap(state.army);
    addMap(state.pending);
    // server queue (grouped)
    if (state.queue && state.queue.length){
      for (var i=0;i<state.queue.length;i++){
        var it = state.queue[i] || {};
        var id = String(it.id||'');
        var q = parseInt(it.qty,10)||0;
        if (!id || q<=0) continue;
        var d = defById(id) || {};
        var sp = parseInt(d.space,10)||1;
        used += q * sp;
      }
    }
    addMap(state.optTrain);
    return used;
  }

  function calcFreeCapacity(){
    var max = parseInt(state.capMax,10)||0;
    return Math.max(0, max - calcUsedCapacity());
  }



  function openUnitInfo(kind, unitId){
    kind = String(kind||'');
    unitId = String(unitId||'');
    if (!unitId) return;
    apiGetParams('unit_info', { unit_id: unitId }).then(function(info){
      // backend returns kind too; keep for renderer
      state.unitInfo = info;
      state.unitInfo.kind = info.kind || kind;
      openUnitDetailModal();
    }).catch(function(err){
      showBarracksToast('error', 'Информация', (err && err.message) ? err.message : 'Ошибка');
    });
  }

  function getQueuedSpaceUsed(){
    // For classic queue model, capacity check should include:
    // - units currently training (state.queue)
    // - units that are ready but waiting for free space (state.pending)
    if (state.trainingModel === 'instant') return 0;

    var used = 0;
    var i, it;

    // training queue
    for (i = 0; i < state.queue.length; i++){
      it = state.queue[i] || {};
      var d = defById(it.id) || {};
      var sp = parseInt(d.space, 10) || 1;
      var q = parseInt(it.qty, 10) || 0;
      if (q > 0) used += sp * q;
    }

    // ready/pending
    for (var id in state.pending){
      if (!state.pending.hasOwnProperty(id)) continue;
      var q2 = parseInt(state.pending[id], 10) || 0;
      if (q2 <= 0) continue;
      var d2 = defById(id) || {};
      var sp2 = parseInt(d2.space, 10) || 1;
      used += sp2 * q2;
    }

    return used;
  }

  function renderTabs(){
    var tabs = [
      {key:'buildings', label:'ПОСТРОЙКИ'},
      {key:'army', label:'АРМИЯ'},
      {key:'heroes', label:'ГЕРОИ'},
      {key:'train', label:'ТРЕНИРОВАТЬ ВОЙСКА'},
      {key:'spells', label:'ГОТОВИТЬ ЗАКЛИНАНИЯ'}
    ];
    return '<div class="coc-tabs" id="coc-barracks-tabs">' +
      tabs.map(function(t){
        var a = (t.key===state.tab) ? ' is-active' : '';
        return '<button type="button" class="coc-tab'+a+'" data-tab="'+esc(t.key)+'">'+esc(t.label)+'</button>';
      }).join('') +
    '</div>';
  }

  function buildingImg(id, lvl){
    if (!id) return '/images/building/barracks.png';

    // Special-case: Siege Workshop / Мастерская (осадные машины)
    // In assets it lives under /images/building/Workshop/Workshop{level}.png
    var sid = String(id).toLowerCase();
    if (sid === 'siege_workshop' || sid === 'workshop' || sid === 'siegeworkshop'){
      var l = parseInt(lvl, 10) || 1;
      if (l < 1) l = 1;
      if (l > 8) l = 8;
      return '/images/building/Workshop/Workshop' + l + '.png';
    }

    // Special-case: Army Camp / Военный лагерь
    // Assets live under /images/building/Army_Camp/Army_Camp{level}.png
    if (sid === 'army_camp' || sid === 'armycamp' || sid === 'camp'){
      var cl = parseInt(lvl, 10) || 1;
      if (cl < 1) cl = 1;
      if (cl > 13) cl = 13;
      return '/images/building/Army_Camp/Army_Camp' + cl + '.png';
    }

    // Default: dedicated icon if it exists; fallback handled by imgWithFallback.
    return '/images/building/' + id + '.png';
  }

  function buildingRichDesc(b, cur, next){
    var id = (b && (b.id || b.building_id || b.type)) ? String(b.id || b.building_id || b.type) : '';
    var key = id.toLowerCase();

    var base = String((b && b.description) || (cur && cur.description) || '');
    // Если описание слишком короткое/общее — заменяем на полезное (как в офф CoC).
    function isWeak(s){
      s = String(s||'').trim();
      if (!s) return true;
      if (s.length < 18) return true;
      // типовые заглушки
      var low = s.toLowerCase();
      return (low === 'улучшает войска.' || low === 'улучшает войска' || low === 'улучшает армию.' || low === 'улучшает армию');
    }

    var rich = {
      "laboratory": "Позволяет изучать и улучшать войска и заклинания. Улучшение лаборатории открывает новые уровни улучшений и ускоряет прогресс вашей армии.",
      "barracks": "Тренирует обычные войска. Улучшайте казарму, чтобы открыть новые войска и сократить время подготовки армии.",
      "dark_barracks": "Тренирует тёмные войска за тёмный эликсир. Улучшайте, чтобы открыть новых тёмных юнитов и ускорить их подготовку.",
      "army_camp": "Увеличивает вместимость армии. Улучшение лагерей — самый прямой способ сделать армию сильнее, т.к. вы сможете брать больше войск в бой.",
      "spell_factory": "Создаёт заклинания. Улучшайте, чтобы открыть новые заклинания и увеличить/ускорить их производство.",
      "dark_spell_factory": "Создаёт тёмные заклинания за тёмный эликсир. Улучшайте, чтобы открыть новые тёмные заклинания и ускорить их производство.",
      "siege_workshop": "Позволяет создавать осадные машины. Улучшайте мастерскую, чтобы открыть новые осадные машины и улучшить их характеристики/доступность.",
      "workshop": "Позволяет создавать осадные машины. Улучшайте мастерскую, чтобы открыть новые осадные машины и улучшить их характеристики/доступность.",
      "clan_castle": "Хранилище подкрепления клана и сокровищ. Улучшение увеличивает вместимость подкреплений и бонусы защиты/хранилища.",
      "builder_hut": "Домик строителя. Наличие строителей позволяет строить и улучшать здания. Чем больше строителей, тем быстрее развивается деревня."
    };

    // fallback: если id вида siege_workshop_1 и т.п.
    if (!rich[key]){
      if (key.indexOf('laboratory') !== -1) rich[key] = rich.laboratory;
      if (key.indexOf('dark_barracks') !== -1) rich[key] = rich.dark_barracks;
      if (key.indexOf('barracks') !== -1 && !rich[key]) rich[key] = rich.barracks;
      if (key.indexOf('army_camp') !== -1) rich[key] = rich.army_camp;
      if (key.indexOf('spell_factory') !== -1) rich[key] = rich.spell_factory;
      if (key.indexOf('dark_spell') !== -1) rich[key] = rich.dark_spell_factory;
      if (key.indexOf('siege') !== -1 || key.indexOf('workshop') !== -1) rich[key] = rich.siege_workshop;
      if (key.indexOf('clan_castle') !== -1) rich[key] = rich.clan_castle;
      if (key.indexOf('builder') !== -1 && key.indexOf('hut') !== -1) rich[key] = rich.builder_hut;
    }

    // Универсальный fallback: если нет подробного описания в данных и нет готового текста —
    // делаем понятное описание по типу здания.
    function genericByType(){
      var t = String((b && b.type) || (cur && cur.type) || '').toLowerCase();
      if (t === 'army') return 'Военное здание. Используется для подготовки армии и развития войск. Улучшение открывает новые возможности и повышает эффективность.';
      if (t === 'defense') return 'Оборонительное здание. Защищает деревню от атак. Улучшение повышает прочность и боевые характеристики.';
      if (t === 'resource' || t === 'production') return 'Ресурсное здание. Помогает добывать или хранить ресурсы. Улучшение увеличивает эффективность и лимиты.';
      if (t === 'storage') return 'Хранилище ресурсов. Улучшение увеличивает вместимость и защиту запасов.';
      if (t === 'trap') return 'Ловушка. Срабатывает при атаке и наносит урон врагам. Улучшение усиливает эффект.';
      return 'Здание вашей деревни. Улучшение повышает характеристики и открывает новые возможности.';
    }
    var chosen = base;
    if (isWeak(base) && rich[key]) chosen = rich[key];
    if (isWeak(base) && !rich[key]) chosen = genericByType();

    // Добавим “зачем улучшать” динамически, если есть next.
    var nlvl = next && (parseInt(next.level,10)||parseInt(next.lv,10));
    var clvl = cur && (parseInt(cur.level,10)||parseInt(cur.lv,10));
    if (nlvl && clvl && nlvl > clvl){
      if (key === 'army_camp' || key.indexOf('army_camp') !== -1){
        var ccap = parseInt(cur.capacity,10) || parseInt(cur.cap,10) || 0;
        var ncap = parseInt(next.capacity,10) || parseInt(next.cap,10) || 0;
        if (ccap && ncap && ncap > ccap){
          chosen += " Вместимость: " + ccap + " → " + ncap + ".";
        }
      }
    }

    return chosen || base || "—";
  }


  
  // Mobile performance: chunked render for Buildings tab to avoid UI freeze on low-end devices.
  function isTouchDevice(){
    try{
      // More robust mobile / touch detection:
      // 1) Real touch points
      if ((navigator.maxTouchPoints && navigator.maxTouchPoints > 0) || (navigator.msMaxTouchPoints && navigator.msMaxTouchPoints > 0)) return true;

      // 2) Coarse pointer / hover none (common on mobile)
      if (window.matchMedia){
        try{
          if (window.matchMedia('(pointer: coarse)').matches) return true;
          if (window.matchMedia('(hover: none)').matches && window.matchMedia('(pointer: coarse)').matches) return true;
        }catch(_mm){}
      }

      // 3) User-Agent / UAData mobile hint
      var ua = (navigator.userAgent || '').toLowerCase();
      if (ua.indexOf('android') !== -1 || ua.indexOf('iphone') !== -1 || ua.indexOf('ipad') !== -1 || ua.indexOf('ipod') !== -1) return true;
      try{
        if (navigator.userAgentData && navigator.userAgentData.mobile) return true;
      }catch(_ud){}

      // 4) Fallback: small screen (prevents false negatives)
      var w = Math.min(window.innerWidth || 9999, window.screen && window.screen.width || 9999);
      if (w && w <= 900) return true;

      return ('ontouchstart' in window);
    }catch(_e){ return false; }
  }


  var __bldChunkToken = 0;

  // For some mobile WebViews, even chunked rendering of a large Buildings list
  // can hard-freeze the UI. We therefore cap the initial render amount and
  // allow progressively loading more.
  var __bldChunkCtx = null;
  var __bldTargetCount = 0;
  var __bldInitialCap = 24;
  var __bldMoreStep = 24;

  function bldImgTag(src){
    // IMPORTANT (mobile): some WebViews hard-freeze when decoding a lot of PNGs immediately.
    // We therefore "late-bind" the real image URL via data-src, and use a tiny placeholder
    // for the initial DOM insert. Actual src is applied in small batches after mount.
    var real = normalizeImgPath(src);
    var ph = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    return '<img class="coc-bimg coc-bimg-late" src="'+ph+'" data-src="'+esc(real)+'" alt="" loading="lazy" decoding="async" draggable="false" oncontextmenu="return false;" onerror="this.onerror=null;this.src=\'/images/building/barracks.png\';">';
  }

  // Late image loader for Buildings thumbnails (mobile-safe)
  var __bldImgToken = 0;
  function scheduleBuildingsImgLoad(){
    var host = document.getElementById('coc-bslots');
    if (!host) return false;
    __bldImgToken++;
    var token = __bldImgToken;

    function step(){
      if (token !== __bldImgToken) return;
      if (state.tab !== 'buildings') return;
      var imgs = host.querySelectorAll('img.coc-bimg-late[data-src]');
      if (!imgs || !imgs.length) return;

      // Load only a couple per frame to avoid decode spikes.
      var batch = isTouchDevice() ? 2 : 6;
      for (var i=0; i<imgs.length && i<batch; i++){
        var img = imgs[i];
        var ds = img.getAttribute('data-src');
        if (!ds) continue;
        img.removeAttribute('data-src');
        img.classList.remove('coc-bimg-late');
        img.src = ds;
      }

      // Continue if there are still pending images.
      if (host.querySelector('img.coc-bimg-late[data-src]')){
        requestAnimationFrame(step);
      }
    }

    // Yield at least one frame so the tab switch paints immediately.
    requestAnimationFrame(step);
    return true;
  }

  function buildBuildingSlotHtml(b){
    b = b || {};
    var lvl = parseInt(b.level, 10) || 0;
    var isEmpty = (lvl <= 0);

    // ⏱ Ticking timer on card while upgrading/constructing.
    // We only update text nodes (no re-render) to stay smooth on mobile.
    var nowS = nowServer();
    var fin = 0;
    try{
      var bi = getBuildingBusyInfo(b);
      if (bi && bi.finish) fin = parseInt(bi.finish, 10) || 0;
    }catch(_e){}
    if (!fin) fin = parseInt(b.finish_time, 10) || 0;

    var left = (fin && fin > nowS) ? (fin - nowS) : 0;

    var cls = 'coc-bslot' + (isEmpty ? ' is-empty' : '');
    var badge = isEmpty ? '<div class="coc-bbadge">НЕ ПОСТРОЕНО</div>' : '<div class="coc-bbadge">'+esc(lvl)+'</div>';
    var bname = String(b.building_name || b.name || b.title || ruUnlockName(b.id) || b.id || '');
    var btn = '';
    if (isEmpty){
      btn = '<button type="button" class="coc-bbtn" data-bbuild="'+esc(b.id)+'">Построить</button>';
    } else if (left > 0){
      // Replace the upgrade button with a ticking timer.
      // Updated by startUiTimerTicker() via [data-btimer-end].
      btn = '<button type="button" class="coc-bbtn coc-bbtn-busy" disabled="disabled" data-btimer-end="'+esc(String(fin||0))+'">'+esc(formatTime(left))+'</button>';
    } else {
      btn = '<button type="button" class="coc-bbtn" data-bup="'+esc(b.id)+'">Улучшить</button>';
    }

    return '<div class="'+cls+'" data-bopen="'+esc(b.id)+'">' +
        '<div class="coc-bname">'+esc(bname)+'</div>' +
        '<button type="button" class="coc-info coc-info-bld" data-binfo="'+esc(b.id)+'">i</button>' +
        bldImgTag(buildingImg(b.id, (b.level||b.lvl||b.current_level||b.lv||0))) +
        badge +
        '<div class="coc-bactions">'+btn+'</div>' +
      '</div>';
  }

  function scheduleBuildingsChunkRender(){
    var list = (state.buildings && state.buildings.length) ? state.buildings : [];
    var host = document.getElementById('coc-bslots');
    if (!host) return false;
    if (!list.length) return false;

    // Cancel previous job
    __bldChunkToken++;
    var token = __bldChunkToken;

    host.innerHTML = '';

    // Decide how many we render in this pass.
    // On touch devices we cap initial items and expose a "Показать ещё" control.
    if (!__bldTargetCount || __bldTargetCount < 1) __bldTargetCount = __bldInitialCap;
    if (!isTouchDevice()) __bldTargetCount = list.length;
    if (__bldTargetCount > list.length) __bldTargetCount = list.length;

    __bldChunkCtx = {
      token: token,
      host: host,
      list: list,
      idx: 0,
      chunk: isTouchDevice() ? 4 : 10,
      startedAt: Date.now()
    };

    function step(){
      if (!__bldChunkCtx) return;
      if (__bldChunkCtx.token !== __bldChunkToken) return;
      if (state.tab !== 'buildings') return;

      // Safety guard: stop if it takes too long (prevents hard lock on some webviews).
      if (isTouchDevice() && (Date.now() - __bldChunkCtx.startedAt) > 1800){
        if (!host.querySelector('[data-bmore]')){
          host.insertAdjacentHTML('beforeend', '<div class="coc-bmore-wrap"><button type="button" class="coc-bmore" data-bmore="1">Показать ещё</button></div>');
        }
        return;
      }

      var idx = __bldChunkCtx.idx;
      var end = Math.min(idx + __bldChunkCtx.chunk, __bldTargetCount);
      var html = '';
      for (var i=idx; i<end; i++){
        html += buildBuildingSlotHtml(__bldChunkCtx.list[i]);
      }
      if (html) host.insertAdjacentHTML('beforeend', html);

      __bldChunkCtx.idx = end;
      if (__bldChunkCtx.idx < __bldTargetCount){
        requestAnimationFrame(step);
        return;
      }

      if (__bldTargetCount < __bldChunkCtx.list.length){
        if (!host.querySelector('[data-bmore]')){
          host.insertAdjacentHTML('beforeend', '<div class="coc-bmore-wrap"><button type="button" class="coc-bmore" data-bmore="1">Показать ещё</button></div>');
        }
      } else {
        // Start ticking timers once chunk render completes.
        try{ startBuildingsTabTimers(); }catch(_e){}
      }
    }

    requestAnimationFrame(step);
    return true;
  }

function renderBarracksSlots(){
    var list = (state.buildings && state.buildings.length) ? state.buildings : [];
    if (!list.length){
      return '<div class="coc-panel coc-building-panel">' +
        '<div class="coc-building-head">' +
          '<div class="coc-building-title">ПОСТРОЙКИ</div>' +
          '<div class="coc-building-sub">Загрузка…</div>' +
        '</div>' +
      '</div>';
    }


    // On touch devices, rendering all buildings in one big HTML string can freeze the UI.
    // Render an empty container and fill it in chunks after mount.
    if (isTouchDevice() || list.length > 40){
      return '<div class="coc-panel coc-building-panel">' +
        '<div class="coc-building-head">' +
          '<div class="coc-building-title">АРМЕЙСКИЕ ЗДАНИЯ</div>' +
        '</div>' +
        '<div class="coc-bslots" id="coc-bslots" data-chunked="1"></div>' +
      '</div>';
    }

    var slots = list.map(function(b){
      var lvl = parseInt(b.level, 10) || 0;

      var status = String(b.status || 'none');
      var isEmpty = (lvl <= 0);

      // ⏱ Timer for card action while upgrading/constructing (ticking via startUiTimerTicker()).
      var nowS = nowServer();
      var fin = 0;
      try{
        var bi = getBuildingBusyInfo(b);
        if (bi && bi.finish) fin = parseInt(bi.finish,10) || 0;
      }catch(_e){}
      if (!fin) fin = parseInt(b.finish_time,10) || 0;
      var left = (fin && fin > nowS) ? (fin - nowS) : 0;

var cls = 'coc-bslot' + (isEmpty ? ' is-empty' : '');
      var badge = isEmpty ? '<div class="coc-bbadge">НЕ ПОСТРОЕНО</div>' : '<div class="coc-bbadge">'+esc(lvl)+'</div>';
      var bname = String(b.building_name || b.name || b.title || ruUnlockName(b.id) || b.id || '');
      var btn = '';
      if (isEmpty){
        btn = '<button type="button" class="coc-bbtn" data-bbuild="'+esc(b.id)+'">Построить</button>';
      } else if (left > 0){
        btn = '<button type="button" class="coc-bbtn coc-bbtn-busy" disabled="disabled" data-btimer-end="'+esc(String(fin||0))+'">'+esc(formatTime(left))+'</button>';
      } else {
        btn = '<button type="button" class="coc-bbtn" data-bup="'+esc(b.id)+'">Улучшить</button>';
      }

      return '<div class="'+cls+'" data-bopen="'+esc(b.id)+'">' +
        '<div class="coc-bname">'+esc(bname)+'</div>' +
        '<button type="button" class="coc-info coc-info-bld" data-binfo="'+esc(b.id)+'">i</button>' +
        imgWithFallback(buildingImg(b.id, (b.level||b.lvl||b.current_level||b.lv||0)), ["/images/building/barracks.png","/images/icons/trophy_icon.png"]) +
        badge +
        '<div class="coc-bactions">'+btn+'</div>' +
      '</div>';
    }).join('');

    return '<div class="coc-panel coc-building-panel">' +
      '<div class="coc-building-head">' +
        '<div class="coc-building-title">АРМЕЙСКИЕ ЗДАНИЯ</div>' +
              '</div>' +
      '<div class="coc-bslots">'+slots+'</div>' +
    '</div>';
  }

  function renderCapRow(){
    return '<div class="coc-cap">' +
      '<img src="https://cdn.jsdelivr.net/gh/twitter/twemoji@14.0.2/assets/svg/1f465.svg" alt="">' +
      esc(state.capNow) + '/' + esc(state.capMax) +
    '</div>';
  }

  function renderStripItem(id, qty, opts){
    opts = opts || {};
    var def = defById(id);
    if (!def) return '';
    var disabled = opts.disabled ? ' is-disabled' : '';
    var check = opts.check ? '<div class="coc-badge coc-badge-check">✓</div>' : '';
    var warn = opts.warn ? '<div class="coc-badge coc-badge-warn">!</div>' : '';
    var count = (typeof qty === 'number')
      ? '<div class="coc-badge coc-badge-count">'+esc(qty)+'x</div>'
      : '';
    var minus = opts.minus ? '<button type="button" class="coc-qminus" data-qminus="'+esc(opts.minus)+'" title="Убрать">−</button>' : '';
    var time = '';
    // Queue timers (troops/spells) must tick without rerender: use data-qtimer-end.
    // opts.endAt is an absolute server timestamp (seconds).
    if (opts.endAt){
      var endAt = parseInt(opts.endAt, 10) || 0;
      if (endAt > 0){
        var left = Math.max(0, endAt - nowServer());
        time = '<div class="coc-qtime" data-qtimer-end="'+esc(String(endAt))+'">'+esc(formatTime(left))+'</div>';
      }
    } else if (opts.time){
      time = '<div class="coc-qtime">'+esc(formatTime(opts.time))+'</div>';
    }

    return '<div class="coc-sitem coc-nomenu'+disabled+'" data-sitem="'+esc(id)+'">' +
      '<img class="coc-simgimg" draggable="false" loading="lazy" decoding="async" src="'+esc(normalizeImgPath(def.img))+'" data-fallback="'+esc((def.fb||[]).map(normalizeImgPath).join('|'))+'" alt="" aria-hidden="true">' +
      count +
      check +
      warn +
      minus +
      time +
    '</div>';
  }

  function renderArmyStrip(){
    // show only troops that are actually trained / in army (qty > 0)
    var items = troopDefs.filter(function(t){
      var qty = state.army[t.id] || 0;
      return (t.owned && qty > 0);
    }).map(function(t){
      var qty = state.army[t.id] || 0;
      return renderStripItem(t.id, qty, {disabled:false, minus: ('u:' + t.id)});
    }).join('');

    if (!items){
      items = '<div class="coc-empty-note">Армия пуста. Тренируйте войска ниже.</div>';
    }

    return '<div class="coc-panel coc-army-panel">' +
      '<div class="coc-panel-head">' +
        '<div class="coc-panel-title">АРМИЯ</div>' +
        renderCapRow() +
      '</div>' +
      '<div class="coc-strip" id="coc-army-strip">'+items+'</div>' +
    '</div>';
  }

  function renderPendingStrip(){
    return '';
if (state.trainingModel === 'instant' && !(state.queue && state.queue.length) && !(state.optTrain && Object.keys(state.optTrain).length)) return '';
    var keys = Object.keys(state.pending);
    var items = keys.map(function(id){
      var qty = state.pending[id] || 0;
      return renderStripItem(id, qty, {check:true});
    }).join('');

    if (!items){
      items = '<div class="coc-empty-note">Сейчас нет воинов, ожидающих места.</div>';
    }

    return '<div class="coc-panel coc-pending-panel">' +
      '<div class="coc-hint">Эти воины присоединятся к вашей армии, когда в ней освободится место!</div>' +
      '<div class="coc-strip" id="coc-pending-strip">'+items+'</div>' +
    '</div>';
  }

  function renderQueueStrip(){
    if (state.trainingModel === 'instant') return '';

    var now = nowServer();

    // Build merged list by unit id (server queue + optimistic), keep server qid if present for minus
    var byId = {};
    if (state.queue && state.queue.length){
      state.queue.forEach(function(it){
        it = it || {};
        var id = String(it.id||'');
        if (!id) return;
        if (!byId[id]) byId[id] = { id:id, qty:0, qid:0 };
        byId[id].qty += (parseInt(it.qty,10)||0);
        if (it.qid) byId[id].qid = it.qid;
      });
    }
    if (state.optTrain){
      for (var oid in state.optTrain){
        if (!state.optTrain.hasOwnProperty(oid)) continue;
        var oq = parseInt(state.optTrain[oid],10)||0;
        if (oq<=0) continue;
        if (!byId[oid]) byId[oid] = { id:oid, qty:0, qid:0 };
        byId[oid].qty += oq;
      }
    }

    var itemsArr = [];
    for (var id in byId){
      if (!byId.hasOwnProperty(id)) continue;
      var q = byId[id].qty|0;
      if (q<=0) continue;

      var def = defById(id);
      var per = def && def.trainTime ? (parseInt(def.trainTime,10)||0) : 0;

      // Prefer backend remaining: last finish time for this unit id
      var ft = (state.trainFinish && state.trainFinish[id]) ? (parseInt(state.trainFinish[id],10)||0) : 0;
      var left = ft ? Math.max(0, ft - now) : 0;

      // If backend doesn't provide per-unit finish times, fallback to per*qty.
      if (!left && per>0) left = per * q;

      // Add optimistic extension (already included in q, but ft may not account it yet)
      var optq = (state.optTrain && state.optTrain[id]) ? (parseInt(state.optTrain[id],10)||0) : 0;
      if (optq>0 && per>0 && ft) left += per * optq;

      var token = 'mix:' + id;
      var endAt = 0;
      if (ft){
        // серверный конец тренировки (может быть по конкретному юниту)
        endAt = ft;
        if (optq>0 && per>0) endAt = ft + (per*optq);
        // если сервер начал отдавать finish — сбрасываем локальный
        if (state._localQueueEndAt[id]) delete state._localQueueEndAt[id];
        if (state._localQueueSig[id]) delete state._localQueueSig[id];
      } else if (left){
        // сервер не дал finish — фиксируем локальный endAt один раз, чтобы таймер тикал,
        // а не пересчитывался в render() как "now + per*q".
        var sig = String(q) + ':' + String(per);
        if (!state._localQueueEndAt[id] || state._localQueueSig[id] !== sig){
          state._localQueueEndAt[id] = now + left;
          state._localQueueSig[id] = sig;
        }
        endAt = state._localQueueEndAt[id];
      }
      itemsArr.push(renderStripItem(id, q, { minus: token, endAt: (endAt?endAt:null) }));
    }

    var items = itemsArr.join('');
    if (!items) return '';

    return '<div class="coc-panel coc-train-panel coc-queue-panel">' +
      '<div class="coc-toprow">' +
        '<div class="coc-subhint">Очередь тренировки</div>' +
      '</div>' +
      '<div class="coc-strip" id="coc-queue-strip">'+items+'</div>' +
    '</div>';
  }

function renderTroopGrid(){
    // 3 состояния как в CoC:
    // 1) active: можно тренировать
    // 2) nospace: открыто, но не хватает места в лагере
    // 3) locked: не открыто (показываем требования)

    var free = calcFreeCapacity();

    var tiles = troopDefs.map(function(t){
      var space = parseInt(t.space,10)||parseInt(t.housing_space,10)||1;
      if (space < 1) space = 1;

      var isLocked = (!t.owned) || !!t.locked;
      var isNoSpace = (!isLocked) && (space > free);

      var cls = 'coc-tile is-troop' + (isLocked ? ' is-disabled is-locked' : '') + (isNoSpace ? ' is-nospace' : '');

      var lockOverlay = '';
      if (isLocked) lockOverlay = '<div class="coc-lock"></div>';

      // CoC-like overlays: level + housing space + info button
      var lvl = (typeof t.level !== 'undefined') ? String(t.level) : '1';
      var lvlBadge = '<div class="coc-lvl">'+esc(lvl)+'</div>';
      var spaceBadge = '<div class="coc-spacebar" data-kind="troop"><span class="coc-spacebar-num">'+esc(String(space))+'</span><span class="coc-spacebar-emoji" aria-hidden="true">👥</span></div>';

      // show cost only if unlocked
      var costHtml = '';
      if (!isLocked){
        costHtml = '<div class="coc-cost"><img src="'+esc(resIcon(t.res))+'" alt="">'+esc(String(t.cost))+'</div>';
      }

      // inline style for nospace to mimic grey-with-border
      var style = '';
      if (isNoSpace){
        style = ' style="opacity:0.65; box-shadow: inset 0 0 0 3px rgba(255,255,255,0.65);"';
      }
      if (isLocked){
        style = ' style="filter: grayscale(1); opacity:0.45;"';
      }

      return '<div class="'+cls+'" data-troop="'+esc(t.id)+'"'+style+'>' +
        lvlBadge +
        spaceBadge +
        '<button type="button" class="coc-info" data-unitinfo="troop:'+esc(t.id)+'">i</button>' +
        lockOverlay +
        '<div class=\"coc-holdbadge is-hidden\"></div>' +
        imgWithFallback((t.img || unitImageCandidates(t.id)[0]), [t.img].concat(unitImageCandidates(t.id)).filter(Boolean)) +
        costHtml +
      '</div>';
    }).join('');

    return '<div class="coc-troop-grid" id="coc-troop-grid">'+tiles+'</div>';
  }

  function renderSpellGridInline(){
    if (!Array.isArray(spellDefs) || !spellDefs.length) return '';

    var free = Math.max(0, (parseInt(state.spellCap,10)||0) - (parseInt(state.spellUsed,10)||0) - (parseInt(state.spellQueued,10)||0));

    var tiles = spellDefs.map(function(s){
      var space = parseInt(s.space,10)||parseInt(s.housing_space,10)||1;
      if (space < 1) space = 1;

      var isLocked = (!s.owned) || !!s.locked;
      var isNoSpace = (!isLocked) && (space > free);

      var cls = 'coc-tile' + (isLocked ? ' is-disabled is-locked' : '') + (isNoSpace ? ' is-nospace' : '');

      var lockOverlay = '';
      if (isLocked) lockOverlay = '<div class="coc-lock"></div>';

      var lvl = (typeof s.level !== 'undefined') ? String(s.level) : '1';
      var lvlBadge = '<div class="coc-lvl">'+esc(lvl)+'</div>';
      var spaceBadge = '<div class="coc-spacebar" data-kind="troop"><span class="coc-spacebar-num">'+esc(String(space))+'</span><span class="coc-spacebar-emoji" aria-hidden="true">👥</span></div>';

      var costHtml = '';
      if (!isLocked){
        costHtml = '<div class="coc-cost"><img src="'+esc(resIcon(s.res))+'" alt="">'+esc(String(s.cost))+'</div>';
      }

      var style = '';
      if (isNoSpace){
        style = ' style="opacity:0.65; box-shadow: inset 0 0 0 3px rgba(255,255,255,0.65);"';
      }
      if (isLocked){
        style = ' style="filter: grayscale(1); opacity:0.45;"';
      }

      return '<div class="'+cls+'" data-spell="'+esc(s.id)+'"'+style+'>' +
        lvlBadge +
        spaceBadge +
        '<button type="button" class="coc-info" data-unitinfo="spell:'+esc(s.id)+'">i</button>' +
        lockOverlay +
        imgWithFallback(normalizeImgPath(s.img), (s.fb||[]).map(normalizeImgPath)) +
        costHtml +
      '</div>';
    }).join('');

    // Queue panel (if any) + catalog panel. Titles outside the top block must be lowercase.
    var q = renderSpellQueueStrip();
    var catalog =
      '<div class="coc-spell-blue-wrap" style="margin-top:10px;">' +
        '<div class="coc-troop-grid" id="coc-spell-grid">'+tiles+'</div>' +
      '</div>';
    return q + catalog;
  }
function renderSimple(text){
    return '<div class="coc-panel" style="margin-top:10px; font-weight:800; color:#2a2a2a;">'+esc(text)+'</div>';
  }

  function renderTrain(){
    return renderArmyStrip() +
      renderPendingStrip() +
      renderQueueStrip() +
      renderTroopGrid();
  }

  function resIconAny(r){
    // backend may return strings (gold/elixir/dark_elixir) or numbers; keep it resilient
    if (r === null || typeof r === 'undefined') return resIcon('elixir');
    var s = String(r);
    if (s === 'gold' || s === '1') return resIcon('gold');
    if (s === 'dark_elixir' || s === 'dark' || s === '3') return resIcon('dark_elixir');
    return resIcon('elixir');
  }

  var STATIC_ARMY_BUILDINGS = null; // deprecated: frontend must rely on server API (army_api.php)

function ruUnitName(key){
  if (!key) return '';
  key = String(key).trim().toLowerCase();
  var map = {
    "balloon":" Шар",
    "barbarian":" Варвар",
    "archer":" Лучница",
    "giant":" Гигант",
    "goblin":" Гоблин",
    "wall_breaker":" Стенобой",
    "wizard":"‍♂ Волшебник",
    "healer":"✨ Целительница",
    "dragon":" Дракон",
    "pekka":" П.Е.К.К.А.",
    "baby_dragon":" Малыш-дракон",
    "miner":"⛏ Шахтёр",
    "hog_rider":" Всадник на кабане",
    "valkyrie":" Валькирия",
    "golem":" Голем",
    "witch":"‍♀ Ведьма",
    "lava_hound":" Лавовый пёс",
    "bowler":" Боулингист",
    "ice_golem":"❄ Ледяной голем",
    "headhunter":" Охотница за головами",
    "super_barbarian":" Суперварвар",
    "super_archer":" Суперлучница"
  };
  return map[key] || (' ' + key);
}
function translateUnlocks(unlocks){
  if (!unlocks) return '';
  var s = String(unlocks);
  var parts = s.split(/[,;|]+/).map(function(p){return p.trim();}).filter(Boolean);
  if (!parts.length) parts = s.split(/\s+/).filter(Boolean);
  return parts.map(function(p){ return ruUnlockName(p); }).join(', ');
}

var cocBuildingTimerInterval = null;
var cocUnitTimerInterval = null;
var cocLabTimerInterval = null;

function closeBuildingDetailModal(){
  if (cocBuildingTimerInterval){ clearInterval(cocBuildingTimerInterval); cocBuildingTimerInterval = null; }
  var el = document.getElementById('coc-bdetail-overlay');
  if (el) el.remove();

  // Mobile: after closing building detail, re-render buildings list fully if we are on the buildings tab.
  // This fixes a case where chunked render may have been interrupted and only partial slots stay visible.
  try{
    if (state && state.tab === 'buildings'){
      var host = document.getElementById('coc-bslots');
      if (host && host.getAttribute('data-chunked') === '1'){
        scheduleBuildingsChunkRender();
        scheduleBuildingsImgLoad();
      }
    }
  }catch(_e){}
}


function troopFallbackDesc(unit, cur){
  unit = unit || {};
  cur = cur || {};
  // Prefer explicit fields if they exist
  var txt =
    (unit.description || unit.desc || unit.text || unit.about) ||
    (cur.description || cur.desc || cur.text || cur.about) ||
    (unit.effect || cur.effect) ||
    (unit.main_use || unit.main || cur.main_use || cur.main) ||
    (unit.role || cur.role) ||
    '';
  txt = String(txt||'').trim();
  if (txt) return txt;

  // Lightweight constructed fallback (kept short to avoid duplicating stat tiles)
  var target = cur.target || cur.targets || unit.target || unit.targets || '';
  var atype = cur.attack_type || unit.attack_type || '';
  var parts = [];
  if (atype) parts.push('Атака: ' + String(atype).replace(/_/g,' '));
  if (target) parts.push('Цель: ' + String(target).replace(/_/g,' '));
  var out = parts.join('. ');
  if (out) return out;

  // Absolute fallback so the header never looks пустым.
  return 'Боевая единица. Используется для атаки и обороны.';
}


function closeUnitDetailModal(){
  if (cocUnitTimerInterval){ clearInterval(cocUnitTimerInterval); cocUnitTimerInterval = null; }
  var el = document.getElementById('coc-udetail-overlay');
  if (el) el.remove();
}

// Build resilient image fallbacks for units.
// On some deployments there may be legacy/lowercase folders or missing backend img field.
function unitImageCandidates(unitId){
  unitId = String(unitId||'').trim();
  if (!unitId) return [];

  function troopFolderFromId(id){
    id = String(id||'').trim();
    if (!id) return '';
    if (id.toLowerCase() === 'pekka') return 'P.E.K.K.A';
    return id.split(/_+/).filter(Boolean).map(function(p){
      p = String(p||'');
      return p ? (p.charAt(0).toUpperCase() + p.slice(1).toLowerCase()) : '';
    }).filter(Boolean).join('_');
  }

  var folder = troopFolderFromId(unitId);
  var out = [];
  if (folder){
    // Prefer large *info* artwork in modal when available
    out.push('/images/warriors/' + folder + '/' + folder + '_info.png');
    out.push('/images/warriors/' + folder + '/Avatar_' + folder + '.png');
  }
  // legacy lowercase folder/file
  out.push('/images/warriors/' + unitId + '/Avatar_' + unitId + '.png');
  out.push('/images/warriors/' + unitId + '/' + unitId + '_info.png');
  // very old/flat fallback
  out.push('/images/warriors/' + unitId + '.png');
  out.push('/images/icons/trophy_icon.png');
  return out;
}

function renderUnitDetailModalHtml(){
  var u = state.unitInfo;
  if (!u || !u.unit || !u.unit.id) return '';

  var unit = u.unit;
  var kind = String(u.kind||'');
  var name = String(unit.name||unit.id);
  var img = normalizeImgPath(unit.img || '');
  var cur = u.current || u.cur || u.stats || u.current_stats || {};
  var nxt = u.next || u.next_level || u.next_stats || u.nextStats || {};

  // Some backends return next meta (level/cost/time) separately from next combat stats.
  // Merge current with unit fields (many projects store stats directly on unit object).
  if (unit && typeof unit === 'object'){
    cur = Object.assign({}, unit, cur);
  }

  function looksLikeMetaOnly(obj){
    if (!obj || typeof obj !== 'object') return true;
    var keys = Object.keys(obj);
    if (!keys.length) return true;
    var informative = 0;
    for (var i=0;i<keys.length;i++){
      var k = keys[i];
      if (k==='level' || k==='cost' || k==='time' || k==='img' || k==='name' || k==='id') continue;
      if (k==='training_cost' || k==='training_time' || k==='brew_cost' || k==='brew_time') continue;
      if (obj[k]===null || obj[k]===undefined || obj[k]==='') continue;
      informative++;
      if (informative>=2) return false;
    }
    return true;
  }

  
  function findLevelStats(container, lvl){
    if (!container || !lvl) return null;
    try{
      // array of {level: n, ...}
      if (Array.isArray(container)){
        for (var i=0;i<container.length;i++){
          var it = container[i];
          if (!it) continue;
          var il = parseInt(it.level,10);
          if (il === lvl) return it;
        }
        // some arrays are 1-indexed by level position
        if (container[lvl]) return container[lvl];
      } else if (typeof container === 'object'){
        if (container[lvl]) return container[lvl];
        if (container[String(lvl)]) return container[String(lvl)];
        if (container['lvl_'+lvl]) return container['lvl_'+lvl];
        if (container['level_'+lvl]) return container['level_'+lvl];
      }
    }catch(_){}
    return null;
  }

  
  function countUsefulKeys(obj){
    if (!obj || typeof obj !== 'object') return 0;
    var keys = Object.keys(obj);
    var c = 0;
    for (var i=0;i<keys.length;i++){
      var k = keys[i];
      if (k==='level' || k==='cost' || k==='time' || k==='img' || k==='name' || k==='id') continue;
      if (k==='training_cost' || k==='training_time' || k==='brew_cost' || k==='brew_time') continue;
      var v = obj[k];
      if (v===null || typeof v === 'undefined' || v==='') continue;
      c++;
    }
    return c;
  }

  // Deeply search in unit_info payload for a per-level stats table and return stats for lvl (best-effort).
  function deepFindLevelStats(root, lvl){
    if (!root || !lvl) return null;
    var queue = [root];
    var seen = [];
    function seenHas(o){
      for (var i=0;i<seen.length;i++) if (seen[i]===o) return true;
      return false;
    }
    function tryContainer(cont){
      var found = findLevelStats(cont, lvl);
      if (found && typeof found === 'object') return found;
      return null;
    }
    while (queue.length){
      var curObj = queue.shift();
      if (!curObj || typeof curObj !== 'object') continue;
      if (seenHas(curObj)) continue;
      seen.push(curObj);
      if (seen.length > 120) break;

      // direct: array-like with level entries
      if (Array.isArray(curObj)){
        var f0 = tryContainer(curObj);
        if (f0) return f0;
        for (var ai=0; ai<curObj.length && ai<40; ai++){
          var it = curObj[ai];
          if (it && typeof it === 'object' && !seenHas(it)) queue.push(it);
        }
        continue;
      }

      // key-based candidates
      try{
        var keys = Object.keys(curObj);
        for (var ki=0; ki<keys.length; ki++){
          var k = keys[ki];
          var v = curObj[k];
          if (!v || typeof v !== 'object') continue;

          // If key name suggests a per-level table - try it immediately
          if (/(^|_)(levels|level_stats|stats_by_level|by_level|leveldata|level_data|leveltable|level_table)(_|$)/i.test(k)){
            var f = tryContainer(v);
            if (f) return f;
          }

          // enqueue for BFS (bounded)
          if (!seenHas(v)) queue.push(v);
        }
      }catch(_){}
    }
    return null;
  }
if (looksLikeMetaOnly(nxt)){
    // try alternative containers commonly used in this project family
    var alt = u.next_stats || u.nextStats || u.next_combat || u.next_params || u.next_current ||
              (unit ? (unit.next_stats || unit.nextStats || unit.next_combat || unit.next_params || unit.next_level || unit.next) : null) ||
              null;
    if (alt && typeof alt === 'object') nxt = Object.assign({}, nxt, alt);

    // If still empty/meta-only - try to compute next level stats from level tables embedded in unit_info
    if (looksLikeMetaOnly(nxt)){
      var curLvl = parseInt(cur.level,10) || parseInt(unit.level,10) || parseInt(u.level,10) || 0;
      var nextLvl = curLvl ? (curLvl + 1) : 0;
      var table = (u && (u.levels || u.level_stats || u.stats_by_level || u.by_level)) ||
                  (unit && (unit.levels || unit.level_stats || unit.stats_by_level || unit.by_level)) ||
                  null;
      var fromTbl = findLevelStats(table, nextLvl);
      if (fromTbl && typeof fromTbl === 'object'){
        nxt = Object.assign({}, nxt, fromTbl);
      }
    }
  }


  // Description (keep whatever backend returns; for missing texts use resilient fallbacks)
  var desc = String(
    unit.description || unit.desc || unit.text || unit.about ||
    u.description || u.desc || u.text || u.about ||
    cur.description || cur.desc || cur.text || cur.about ||
    ''
  ).trim();
  if (kind === 'spell'){
    if (!desc || desc==='—'){
      var fb = spellFallbackDesc(unit.id, cur, false) || '';
      var pIdx = fb.indexOf('Параметры:');
      desc = (pIdx>=0 ? fb.slice(0,pIdx).trim() : fb.trim());
    }
  } else {
    if (!desc || desc==='—') desc = troopFallbackDesc(unit, cur) || '';
  }

  var lvl = parseInt(unit.level,10)||parseInt(u.level,10)||1;
  var maxLvl = parseInt(unit.max_level,10)||parseInt(u.max_level,10)||1;
  var nextLvl = (u.next && u.next.level) ? parseInt(u.next.level,10) : ((lvl < maxLvl) ? (lvl + 1) : 0);

  // Ensure next-level combat stats exist for troops/spells (some endpoints provide only meta in `next`).
  if (nextLvl && countUsefulKeys(nxt) < 2){
    var fromDeep = deepFindLevelStats(u, nextLvl) || deepFindLevelStats(unit, nextLvl);
    if (fromDeep && typeof fromDeep === 'object'){
      // keep meta fields (cost/time/level) from existing `nxt`, but fill missing combat keys from table
      nxt = Object.assign({}, fromDeep, nxt);
    }
  }

  var lockedReason = (unit.locked_reason || u.locked_reason) ? String(unit.locked_reason || u.locked_reason) : '';
  if (lockedReason && /Требуется\s+.*казармы/i.test(lockedReason)) lockedReason = '';
  var canUpgrade = !!(u.lab ? u.lab.can_upgrade : u.can_upgrade);
  var upgrading = !!(u.lab ? u.lab.busy : u.is_researching);


// Next level improvements preview (generic diff current → next)
function buildNextDiffHtml(cur2, nxt2){
  if (!nxt2 || !cur2) return '';
  var fields = [
    ['dps','Урон/сек'],
    ['damage','Урон'],
    ['total_damage','Урон'],
    ['hp','Здоровье'],
    ['total_healing','Лечение (всего)'],
    ['healing_per_pulse','Лечение/импульс'],
    ['hero_total_healing','Лечение героев (всего)'],
    ['freeze_time','Заморозка'],
    ['slow_pct','Замедление'],
    ['damage_increase_pct','Бонус урона'],
    ['speed_increase_pct','Бонус скорости'],
    ['building_damage_pct','Урон по зданиям'],
    ['wall_damage','Урон по стенам'],
    ['clone_capacity','Лимит клонирования'],
    ['invis_duration','Невидимость'],
    ['recall_capacity','Лимит возврата'],
    ['overgrowth_duration','Разрастание'],
    ['duration','Длительность эффекта'],
    ['brew_time','Время варки'],
    ['training_time','Время тренировки'],
    ['radius','Радиус'],
    ['range','Дальность'],
    ['speed','Скорость'],
    ['attack_speed','Скорость атаки'],
    ['heal','Лечение'],
    ['heal_per_sec','Лечение/сек'],
    ['healing_per_second','Лечение/сек'],
    ['boost','Усиление'],
    ['speed_boost','Ускорение'],
    ['damage_boost','Усиление урона'],
    ['hp_boost','Усиление здоровья'],
    ['th_req','Требуется Ратуша'],
    ['lab_req','Требуется лаборатория'],
    ['lab_level','Уровень лаборатории'],
  ];
  function pickVal(obj, key){
    if (!obj) return null;
    // try common aliases
    var aliases = [key];
    if (key === 'damage') aliases = ['damage','dmg'];
    if (key === 'total_healing') aliases = ['total_healing','healing_total','heal_total'];
    if (key === 'healing_per_pulse') aliases = ['healing_per_pulse','heal_per_pulse'];
    if (key === 'hero_total_healing') aliases = ['hero_total_healing','hero_heal_total'];
    if (key === 'duration') aliases = ['duration','spell_duration','effect_duration'];
    if (key === 'radius') aliases = ['radius','area','area_radius'];
    if (key === 'range') aliases = ['range','atk_range'];
    if (key === 'speed') aliases = ['speed','move_speed'];
    if (key === 'heal_per_sec') aliases = ['heal_per_sec','heal_ps','hps','healing_per_second','healing_per_sec'];
    if (key === 'freeze_time') aliases = ['freeze_time','freeze','freeze_duration'];
    if (key === 'slow_pct') aliases = ['slow_pct','slow','slow_percent'];
    if (key === 'damage_increase_pct') aliases = ['damage_increase_pct','damage_boost','boost_damage','damage_increase'];
    if (key === 'speed_increase_pct') aliases = ['speed_increase_pct','speed_boost','boost_speed','speed_increase'];
    if (key === 'building_damage_pct') aliases = ['building_damage_pct','building_damage_percent'];
    if (key === 'clone_capacity') aliases = ['clone_capacity','max_clone_capacity','clone_cap'];
    if (key === 'invis_duration') aliases = ['invis_duration','invisibility_duration'];
    if (key === 'recall_capacity') aliases = ['recall_capacity','recall_cap'];
    if (key === 'overgrowth_duration') aliases = ['overgrowth_duration','overgrowth_time'];
    for (var i=0;i<aliases.length;i++){
      var k = aliases[i];
      if (Object.prototype.hasOwnProperty.call(obj, k)) return obj[k];
    }
    return null;
  }
  function isNum(v){
    return (typeof v === 'number') || (typeof v === 'string' && v !== '' && !isNaN(v));
  }
  function fmt(v){
    if (v === null || typeof v === 'undefined' || v === '') return '—';
    if (typeof v === 'string') return v;
    return String(v);
  }

  var rows = [];
  for (var i=0;i<fields.length;i++){
    var k = fields[i][0], label = fields[i][1];
    var a = pickVal(cur2, k);
    var b = pickVal(nxt2, k);
    if (b === null || typeof b === 'undefined') continue;
    if (a === null || typeof a === 'undefined') continue;
    // compare
    var sa = fmt(a), sb = fmt(b);
    if (sa === sb) continue;

    var delta = '';
    if (isNum(a) && isNum(b)){
      var da = parseFloat(a), db = parseFloat(b);
      var d = db - da;
      if (isFinite(d) && d !== 0){
        var sign = d > 0 ? '+' : '';
        // keep up to 2 decimals when needed
        var ds = (Math.abs(d) < 1 && String(d).indexOf('.') !== -1) ? (sign + d.toFixed(2)) : (sign + (Math.round(d*100)/100));
        delta = ' <span style="font-weight:900; color:#1f6f3a;">('+ds+')</span>';
      }
    }
    rows.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k">'+esc(withEmoji(label, k))+'</div><div class="coc-bdetail-v">'+esc(sa)+' → <b>'+esc(sb)+'</b>'+delta+'</div></div>');
  }

  // If nothing matched known fields, try any numeric keys (limited)
  if (!rows.length){
    var picked = 0;
    for (var kk in nxt2){
      if (!Object.prototype.hasOwnProperty.call(nxt2, kk)) continue;
      if (picked >= 8) break;
      var av = cur2[kk];
      var bv = nxt2[kk];
      if (av === null || typeof av === 'undefined' || bv === null || typeof bv === 'undefined') continue;
      if (!isNum(av) || !isNum(bv)) continue;
      if (String(av) === String(bv)) continue;
      rows.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k">'+esc(withEmoji(labelForKeyRu(kk), kk))+'</div><div class="coc-bdetail-v">'+esc(String(av))+' → <b>'+esc(String(bv))+'</b></div></div>');
      picked++;
    }
  }

  if (!rows.length) return '';
  return '<div class="coc-bdetail-block" style="margin-top:10px;">' +
    '<div class="coc-bdetail-sub" style="margin:0 0 6px 0;">Следующий уровень: что улучшится</div>' +
    '<div class="coc-bdetail-grid" style="grid-template-columns:1fr 1fr;">' + rows.join('') + '</div>' +
  '</div>';
}

  // --- Stat table (Текущий / Следующий), in the same style as Heroes modal ---
  function pickStat(obj, keys){
    if (!obj) return null;
    for (var i=0;i<keys.length;i++){
      var k = keys[i];
      if (obj[k]!==undefined && obj[k]!==null && obj[k]!=='' ) return obj[k];
    }
    return null;
  }
  var stRows = [];
  stRows.push({ico:'⭐', label:'Уровень', a:String(lvl||0), b:(nextLvl?String(nextLvl):'')});

  // Core stats (troops/spells share many aliases)
  var curDps = pickStat(cur, ['dps','damage_per_second','damageps','damage_per_sec']);
  var nxtDps = pickStat(nxt, ['dps','damage_per_second','damageps','damage_per_sec']);
  if (curDps!==null || nxtDps!==null) stRows.push({ico:'💥', label:'Урон/сек', a:formatStat(curDps), b:formatStat(nxtDps)});

  var curHp = pickStat(cur, ['hp','health','hitpoints']);
  var nxtHp = pickStat(nxt, ['hp','health','hitpoints']);
  if (curHp!==null || nxtHp!==null) stRows.push({ico:'❤️', label:'Здоровье', a:formatStat(curHp), b:formatStat(nxtHp)});

  var curDmg = pickStat(cur, ['damage','dmg','instant_damage','hit_damage','damage_per_hit','total_damage']);
  var nxtDmg = pickStat(nxt, ['damage','dmg','instant_damage','hit_damage','damage_per_hit','total_damage']);
  if (curDmg!==null || nxtDmg!==null) stRows.push({ico:'⚔️', label:'Урон', a:formatStat(curDmg), b:formatStat(nxtDmg)});

  var curHeal = pickStat(cur, ['heal','heal_amount','total_healing','healing_total','heal_total','healing_per_second','heal_per_sec']);
  var nxtHeal = pickStat(nxt, ['heal','heal_amount','total_healing','healing_total','heal_total','healing_per_second','heal_per_sec']);
  if (curHeal!==null || nxtHeal!==null) stRows.push({ico:'❤️', label:'Лечение', a:formatStat(curHeal), b:formatStat(nxtHeal)});

  var curDur = pickStat(cur, ['duration','effect_duration','spell_duration']);
  var nxtDur = pickStat(nxt, ['duration','effect_duration','spell_duration']);
  if (curDur!==null || nxtDur!==null){
    stRows.push({ico:'⏱️', label:'Длительность', a:formatTimeSmart(parseInt(curDur,10)||0), b:formatTimeSmart(parseInt(nxtDur,10)||0)});
  }

  var curRad = pickStat(cur, ['radius','area','area_radius']);
  var nxtRad = pickStat(nxt, ['radius','area','area_radius']);
  if (curRad!==null || nxtRad!==null) stRows.push({ico:'📏', label:'Радиус', a:formatStat(curRad), b:formatStat(nxtRad)});

  var curRange = pickStat(cur, ['range','atk_range']);
  var nxtRange = pickStat(nxt, ['range','atk_range']);
  if (curRange!==null || nxtRange!==null) stRows.push({ico:'📏', label:'Дальность', a:formatStat(curRange), b:formatStat(nxtRange)});

  var curSpd = pickStat(cur, ['speed','move_speed']);
  var nxtSpd = pickStat(nxt, ['speed','move_speed']);
  if (curSpd!==null || nxtSpd!==null) stRows.push({ico:'💨', label:'Скорость', a:formatStat(curSpd), b:formatStat(nxtSpd)});

  // Upgrade meta
  if (!upgrading && nxt && nxt.cost!==undefined && nxt.cost!==null && String(nxt.cost)!==''){
    stRows.push({ico:'💰', label:'Стоимость улучшения', a:'', b:formatNumber(parseInt(nxt.cost,10)||0)});
  }
  if (!upgrading && nxt && nxt.time!==undefined && nxt.time!==null && String(nxt.time)!==''){
    stRows.push({ico:'⏳', label:'Время улучшения', a:'', b:formatTimeSmart(parseInt(nxt.time,10)||0)});
  }

  var twoCols = !!(nextLvl && lvl && nextLvl>lvl && nxt && Object.keys(nxt).length);
  var colA = 'Текущий';
  var colB = twoCols ? 'Следующий' : '';
  var statsHtml = '<div class="coc-um-stats">'
    + '<div class="coc-um-sth"><div></div><div class="coc-um-stc">'+esc(colA)+'</div>' + (twoCols ? '<div class="coc-um-stn">'+esc(colB)+'</div>' : '<div class="coc-um-stn is-hidden"></div>') + '</div>'
    + stRows.map(function(r){
        var bcls = (twoCols && r.b && r.a && r.b!==r.a) ? ' coc-um-next' : '';
        return '<div class="coc-um-str">'
          + '<div class="coc-um-stl"><span class="coc-um-ico">'+esc(r.ico)+'</span>'+esc(r.label)+'</div>'
          + '<div class="coc-um-stv">'+esc(r.a||'—')+'</div>'
          + (twoCols ? ('<div class="coc-um-stv'+bcls+'">'+esc(r.b||'—')+'</div>') : '<div class="coc-um-stv is-hidden"></div>')
        + '</div>';
      }).join('')
    + '</div>';

  // Build tiles list as array for layout control (we will render them in coc-um-tiles)
  var tileArr = [];
  var _tileSeen = Object.create(null);
  function addTile(k,v,key){
    if (v === null || typeof v === 'undefined' || v === '' || v === 'null') return;
    if (_tileSeen[k]) return;
    _tileSeen[k]=1;
    tileArr.push({k:k,v:v,key:key||k});
  }


  function emojiForKey(key){
    key = String(key||'').toLowerCase();

    // Emoji (Chrome renders them consistently across OS via emoji fonts)
    if (key.indexOf('spell_housing') !== -1 || key.indexOf('spell_space') !== -1) return '📦';
    if (key.indexOf('housing') !== -1 || key.indexOf('capacity') !== -1 || key.indexOf('space') !== -1) return '👥';

    if (key.indexOf('resource') !== -1 || key.indexOf('res') !== -1) return '🧪';
    if (key.indexOf('cost') !== -1 || key.indexOf('price') !== -1) return '💰';

    if (key.indexOf('unlock') !== -1 || key.indexOf('th') !== -1 || key.indexOf('townhall') !== -1) return '🏰';

    if (key.indexOf('effect') !== -1) return '✨';
    if (key.indexOf('usage') !== -1 || key.indexOf('apply') !== -1 || key.indexOf('use') !== -1) return '🧠';
    if (key.indexOf('target') !== -1 || key.indexOf('aim') !== -1) return '🎯';

    if (key.indexOf('duration') !== -1 || key.indexOf('time') !== -1) return '⏱️';
    if (key.indexOf('radius') !== -1 || key.indexOf('range') !== -1) return '📏';

    if (key.indexOf('freeze') !== -1) return '❄️';
    if (key.indexOf('invis') !== -1) return '🫥';
    if (key.indexOf('heal') !== -1) return '❤️';

    if (key.indexOf('damage') !== -1 || key.indexOf('dps') !== -1 || key.indexOf('dmg') !== -1) return '⚔️';
    if (key.indexOf('speed') !== -1) return '💨';
    if (key.indexOf('attack_rate') !== -1 || key.indexOf('attack_speed') !== -1) return '⚡';

    if (key.indexOf('hp') !== -1 || key.indexOf('health') !== -1) return '❤️';
    if (key.indexOf('hit') !== -1 && key.indexOf('point') !== -1) return '❤️';

    if (key.indexOf('jump') !== -1) return '🦘';
    if (key.indexOf('poison') !== -1 || key.indexOf('slow') !== -1 || key.indexOf('decrease') !== -1) return '☠️';

    return '✨';
  }

function withEmoji(label, key){
    label = String(label||'');
    // If already starts with emoji, keep it (Chrome supports Unicode properties)
    try{ if (/^\p{Extended_Pictographic}/u.test(label)) return label; }catch(_){
      // fallback: basic BMP + surrogate pair prefix
      if (/^[\u2600-\u27BF]/.test(label) || /^[\uD800-\uDBFF]/.test(label)) return label;
    }
    return emojiForKey(key) + ' ' + label;
  }

  // Same as withEmoji(), but returns HTML with a dedicated emoji span so we can size it via CSS.
  function withEmojiHtml(label, key){
    label = String(label||'');
    // If label already starts with emoji, keep it as-is (escape the whole thing)
    try{ if (/^\p{Extended_Pictographic}/u.test(label)) return esc(label); }catch(_){
      if (/^[\u2600-\u27BF]/.test(label) || /^[\uD800-\uDBFF]/.test(label)) return esc(label);
    }
    var emoji = emojiForKey(key);
    return (emoji?('<span class="coc-emoji" aria-hidden="true">'+emoji+'</span>'):'') + esc(label);
  }

  function trEnum(key, val){
    if (val === null || typeof val === 'undefined') return val;
    var s = String(val);

    // normalize key aliases
    var k = String(key || '');
    if (k === 'attack_type' || k === 'attackType') k = 'damage_type';
    if (k === 'favorite_target' || k === 'favoriteTarget') k = 'fav_target';
    if (k === 'target_type' || k === 'targetType') k = 'targets';

    var map = {
      damage_type: {
        melee_ground: 'Ближний бой (земля)',
        melee_air: 'Ближний бой (воздух)',
        melee_ground_air: 'Ближний бой (земля и воздух)',
        ranged_ground: 'Дальний бой (земля)',
        ranged_air: 'Дальний бой (воздух)',
        ranged_ground_air: 'Дальний бой (земля и воздух)',
        melee: 'Ближний бой',
        ranged: 'Дальний бой',
        splash: 'По области',
        splash_ground: 'По области (земля)',
        splash_air: 'По области (воздух)',
        splash_ground_air: 'По области (земля и воздух)',
        splash_air_ground: 'По области (земля и воздух)',
        heal: 'Лечение',
        support: 'Поддержка'
      },
      targets: {
        ground: 'Наземные',
        air: 'Воздушные',
        any: 'Любые',
        buildings: 'Здания',
        ground_air: 'Земля и воздух',
        air_ground: 'Земля и воздух',
      },
      fav_target: {
        defenses: 'Оборона',
        defense: 'Оборона',
        resources: 'Ресурсы',
        resource: 'Ресурсы',
        walls: 'Стены',
        wall: 'Стены',
        townhall: 'Ратуша',
        heroes: 'Герои',
        hero: 'Герои',
        none: 'Нет',
      }
    };

    var tbl = map[k];
    if (tbl && Object.prototype.hasOwnProperty.call(tbl, s)) return tbl[s];

    // Best-effort fallback for enum-like values: translate tokens
    if (s.indexOf('_') !== -1){
      var t = s;
      t = t.replace(/ground_air|air_ground/g, 'земля и воздух');
      t = t.replace(/\bground\b/g, 'земля');
      t = t.replace(/\bair\b/g, 'воздух');
      t = t.replace(/\bmelee\b/g, 'ближний бой');
      t = t.replace(/\branged\b/g, 'дальний бой');
      t = t.replace(/\bsplash\b/g, 'по области');
      t = t.replace(/\bdefenses\b/g, 'оборона');
      t = t.replace(/\bresources\b/g, 'ресурсы');
      t = t.replace(/\bwalls\b/g, 'стены');
      t = t.replace(/\bnone\b/g, 'нет');
      t = t.replace(/_/g, ' ');
      // capitalize first letter
      t = t.charAt(0).toUpperCase() + t.slice(1);
      return t;
    }

    return s;
  }

  addTile(withEmoji('Требуется места', (kind === 'spell' ? 'spell_housing_space' : 'housing_space')), unit.housing_space);

  // Training cost with real resource icon
  if (u.train && (u.train.cost || u.train.res)){
    var trCost = (u.train.cost !== null && typeof u.train.cost !== 'undefined') ? String(u.train.cost) : '—';
    var trIcon = resIconAny(u.train.res);
    addTile(withEmoji('Стоимость тренировки','train_cost'), '<img src="'+trIcon+'" style="height:16px;vertical-align:middle;margin-right:4px;">'+trCost);
  }
  if (kind === 'spell'){
    var bt = unit.brew_time || unit.training_time;
    var btN = parseInt(bt,10);
    if (!isNaN(btN) && btN>0){ bt = formatTime(btN); }
    addTile(withEmoji('Время варки','brew_time'), bt);
  } else {
    addTile(withEmoji('Время тренировки','training_time'), unit.training_time);
  }

  // Healer / support stats (if backend provides)
  var healPs = cur.heal_per_sec || cur.heal_ps || cur.healPS || cur.hps || cur.healing_per_second || cur.healing_per_sec || cur.heal_per_second || cur.healing_per_pulse || unit.heal_per_sec || unit.heal_ps || unit.healPS || unit.hps || unit.healing_per_second || unit.healing_per_sec || unit.heal_per_second || unit.healing_per_pulse;
  var heroHealPs = cur.hero_healing_per_second || cur.hero_heal_per_sec || cur.hero_heal_per_second || unit.hero_healing_per_second || unit.hero_heal_per_sec || unit.hero_heal_per_second;
  var healAmt = cur.heal || cur.heal_amount || cur.healAmount || unit.heal || unit.heal_amount || unit.healAmount;
  var healTargets = cur.heal_targets || cur.heal_target_count || cur.healTargets || unit.heal_targets || unit.heal_target_count || unit.healTargets;
  var healRange = cur.heal_range || cur.range_heal || cur.healRange || unit.heal_range || unit.range_heal || unit.healRange;

  var isHealerLike = (unit.id === 'healer' || heroHealPs || healPs || healAmt || healTargets || healRange);

  // Для хилеров/саппортов НЕ показываем "Урон/сек" (в игре это лечение).
  if (!isHealerLike){
    var dpsVal = (cur && (cur.dps !== null && typeof cur.dps !== 'undefined')) ? parseFloat(cur.dps) : 0;
    if (dpsVal > 0) addTile(' Урон/сек', cur.dps);
  }

  if (isHealerLike){
    if (healPs) addTile('Лечение/сек', healPs);
    if (heroHealPs) addTile(' Лечение героев/сек', heroHealPs);
    if (healAmt) addTile('Лечение', healAmt);
    if (healTargets) addTile(' Целей лечения', healTargets);
    if (healRange) addTile(' Дальность лечения', healRange);
  }  

  // Доп. параметры заклинаний (если backend прислал числовые статы)
if (kind === 'spell' && cur){
  // берём только "боевые" параметры, не показываем тех. поля (стоимость/время/требования)
  var SP = {
    resource_type: 'Тип ресурса',
    unlock_th: 'Открывается на TH',
    effect: 'Эффект',
    usage: 'Основное применение',
    targets: 'Цели',
    damage: 'Урон',
    instant_damage: 'Урон',
    dmg: 'Урон',
    total_damage: 'Урон',
    damage_total: 'Урон',
    damage_per_attack: 'Урон за атаку',
    damage_per_second: 'Урон/сек',
    damage_per_sec: 'Урон/сек',
    dps: 'Урон/сек',
    heal: 'Лечение',
    heal_amount: 'Лечение',
    total_healing: 'Лечение (всего)',
    healing_total: 'Лечение (всего)',
    heal_total: 'Лечение (всего)',
    healing_per_pulse: 'Лечение/импульс',
    heal_per_pulse: 'Лечение/импульс',
    hero_total_healing: 'Лечение героев (всего)',
    hero_healing_per_pulse: 'Лечение героев/импульс',
    heal_per_second: 'Лечение/сек',
    heal_per_sec: 'Лечение/сек',
    healing_per_second: 'Лечение/сек',
    hero_heal_per_second: 'Лечение героев/сек',
    duration: 'Длительность',
    effect_duration: 'Длительность',
    invis_duration: 'Невидимость',
    freeze_time: 'Заморозка',
    stun_time: 'Оглушение',
    radius: 'Радиус',
    area_radius: 'Радиус',
    range: 'Дальность',
    speed_boost: 'Бонус скорости',
    speed_increase_pct: 'Бонус скорости',
    speed_increase: 'Бонус скорости',
    move_speed_boost: 'Бонус скорости',
    attack_speed_boost: 'Бонус скорости атаки',
    damage_boost: 'Бонус урона',
    damage_increase_pct: 'Бонус урона',
    damage_increase: 'Бонус урона',
    boost_damage: 'Бонус урона',
    boost_speed: 'Бонус скорости',
    slow_pct: 'Замедление',
    building_damage_pct: 'Урон по зданиям',
    wall_damage: 'Урон по стенам',
    clone_capacity: 'Лимит клонирования',
    max_clone_capacity: 'Лимит клонирования',
    recall_capacity: 'Лимит возврата',
    overgrowth_duration: 'Разрастание',
    max_units: 'Макс. юнитов',
    units: 'Юнитов',
    summoned_units: 'Призывает',
    skeletons: 'Скелетов',
    bats: 'Летучих мышей',
    jumps: 'Прыжков',
    spell_strength: 'Сила эффекта',
    boost_time: 'Время действия',
    spell_capacity: 'Требуется места',
    housing_space: 'Требуется места',
    train_cost: 'Стоимость тренировки',
    max_damage_per_second: 'Макс. урон/сек',
    attack_rate_decrease_pct: 'Снижение скорости атаки',
    speed_decrease_pct: 'Снижение скорости',
    troop_damage_pct: 'Урон по войскам',
    troop_damage_percent: 'Урон по войскам',
    cloned_capacity: 'Лимит клонирования',
    cloned_housing_space: 'Лимит клонирования',
    cloned_capacity_total: 'Лимит клонирования',
    recalled_capacity: 'Лимит возврата',
    recalled_housing_space: 'Лимит возврата',
    skeletons_generated: 'Призвано скелетов',
    bats_generated: 'Призвано летучих мышей',
    incoming_damage_reduction: 'Снижение входящего урона',
    hero_heal_pct: 'Восстановление героя',
    trigger_radius: 'Радиус активации',

  };

// Человеческие подписи для "нестандартных" ключей статистик (и алиасы из бэкенда/дат).
function spellExtraLabel(key){
  if (!key) return '';
  var k = String(key).toLowerCase().trim();
  // normalize: spaces -> underscores, collapse multiple
  k = k.replace(/\s+/g,'_').replace(/_+/g,'_');
  // common aliases
  if (k === 'boost time') k = 'boost_time';
  if (k === 'boost-time') k = 'boost_time';
  if (k === 'cloned capacity') k = 'cloned_capacity';
  if (k === 'recalled capacity') k = 'recalled_capacity';
  if (k === 'skeletons generated') k = 'skeletons_generated';
  if (k === 'bats generated') k = 'bats_generated';
  if (k === 'troop damage %' || k === 'troop_damage%') k = 'troop_damage_pct';

  var map = {
    // time / duration
    boost_time: 'Время действия',
    freeze_time: 'Заморозка',
    invis_duration: 'Невидимость',

    // debuffs
    speed_decrease_pct: 'Снижение скорости',
    attack_rate_decrease_pct: 'Снижение скорости атаки',

    // capacities / summons
    cloned_capacity: 'Лимит клонирования',
    recalled_capacity: 'Лимит возврата',
    skeletons_generated: 'Призвано скелетов',
    bats_generated: 'Призвано летучих мышей',

    // earthquake
    building_damage_pct: 'Урон по зданиям',
    troop_damage_pct: 'Урон по войскам',

    // new spells
    incoming_damage_reduction: 'Снижение входящего урона',
    hero_heal_pct: 'Восстановление героя',
    trigger_radius: 'Радиус активации',
  };

  if (Object.prototype.hasOwnProperty.call(map, k)) return map[k];

  // fallback: заменяем подчеркивания пробелами и делаем первую букву заглавной
  var kk = k.replace(/_/g,' ');
  return kk.charAt(0).toUpperCase() + kk.slice(1);
}


  function _fmtSpellVal(k, v){
    if (v === null || typeof v === 'undefined') return null;
    // enums / meta
    if (k === 'resource_type' || k === 'brew_res_type'){
      var rs = String(v);
      if (rs === 'elixir') return 'Эликсир';
      if (rs === 'dark_elixir') return 'Тёмный эликсир';
      if (rs === 'gold') return 'Золото';
      return rs;
    }
    if (k === 'targets') return trEnum('targets', v);
    var n = Number(v);
    var isNum = isFinite(n) && String(v).trim() !== '';
    if (!isNum) return String(v);
    // time/duration-like fields (секунды). Для дробных значений показываем "3,5 сек."
if (/duration/i.test(k) || /_time$/i.test(k) || k === 'freeze_time' || k === 'boost_time'){
  var ssec = Math.max(0, n);
  if (ssec === 0) return 'мгновенно';
  // Дробные секунды (например, невидимость 3.5)
  var isFrac = Math.abs(ssec - Math.round(ssec)) > 1e-9;
  if (ssec < 60){
    var shown = isFrac ? (Math.round(ssec*10)/10) : Math.round(ssec);
    return String(shown).replace('.', ',') + ' сек.';
  }
  // Для больших значений округляем до целых секунд
  return formatTime(Math.round(ssec));
}
    // radius / range: meters (условно)
    if (/radius|range/i.test(k)){
      var rr = Math.round(n*10)/10;
      return String(rr).replace('.', ',');
    }
        // не показываем нулевой урон по войскам у Землетрясения (на низких уровнях)
    if ((k === 'troop_damage_pct' || k === 'troop_damage_percent') && Math.abs(n) < 1e-9) return null;
// % fields: allow 0.3 or 30
    if (/(boost|bonus|_pct|pct|percent|increase)/i.test(k)){
      var p = n;
      if (p>0 && p<=1) p = p*100;
      p = Math.round(p*10)/10;
      return String(p).replace('.', ',') + '%';
    }
    // ints
    if (Math.abs(n - Math.round(n)) < 1e-9) return String(Math.round(n));
    return String(Math.round(n*10)/10).replace('.', ',');
  }

  // show mapped keys first (in stable order)
  Object.keys(SP).forEach(function(k){
    if (cur[k]===undefined) return;
    var val = _fmtSpellVal(k, cur[k]);
    if (val===null || val==='') return;
    addTile(withEmoji(SP[k], k), val);
  });

  // also show any additional numeric fields that look like stats (safe allow-list by pattern)
  Object.keys(cur).forEach(function(k){
    if (!k) return;
    if (SP[k]) return;
    if (/^(cost|time|th_req|lab_req|res|res_type|level|id|name|type)$/i.test(k)) return;
    if (!/(damage|dmg|heal|duration|radius|range|boost|speed|capacity|units|skeleton|bat|freeze|slow|poison|earth|quake|haste|jump|clone|invis|recall|overgrowth)/i.test(k)) return;
    var val = _fmtSpellVal(k, cur[k]);
    if (val===null || val==='') return;
    // label (RU) + дедуп по подписи
var lbl = SP[k] || spellExtraLabel(k);
addTile(withEmoji(lbl, k), val);
});
}

  // Upgrade block (separate section like buildings)
  var upgradeHtml = '';
  if (nxt && (nxt.cost || nxt.time || nxt.res || nxt.th_req)){
    var upgCost = (nxt.cost !== null && typeof nxt.cost !== 'undefined') ? String(nxt.cost) : '—';
    var upgIcon = resIconAny(nxt.res);
    var upgTime = (nxt.time !== null && typeof nxt.time !== 'undefined') ? formatDurationSmart(parseInt(nxt.time,10)||0) : '—';

    // checks (what is missing) comes from backend: u.upgrade.checks
    var checks = (u.upgrade && Array.isArray(u.upgrade.checks)) ? u.upgrade.checks : [];
    function chkRow(label, value, ok){
      var cls = ok ? '' : ' coc-need-bad';
      return '<div class="coc-bdetail-tile'+cls+'"><div class="coc-bdetail-k">'+esc(withEmoji(label, label))+'</div><div class="coc-bdetail-v">'+esc(String(value))+'</div></div>';
    }

    var upgGrid = '';
    upgGrid += '<div class="coc-bdetail-grid">';
    upgGrid += '<div class="coc-bdetail-tile"><div class="coc-bdetail-k">⬆ Стоимость</div><div class="coc-bdetail-v">' +
      '<img src="'+upgIcon+'" style="height:16px;vertical-align:middle;margin-right:4px;">'+esc(upgCost) +
    '</div></div>';
    upgGrid += '<div class="coc-bdetail-tile"><div class="coc-bdetail-k">⬆ Время</div><div class="coc-bdetail-v">'+esc(upgTime)+'</div></div>';
    if (nxt.th_req) upgGrid += chkRow(' Требуется Ратуша', nxt.th_req, !(checks.find(function(c){return c && c.key==='th';}) && checks.find(function(c){return c && c.key==='th';}).ok===false));
    upgGrid += '</div>';

    var needHtml = '';
    if (checks && checks.length){
      needHtml += '<div class="coc-bdetail-grid" style="margin-top:8px;">';
      checks.forEach(function(c){
        if (!c) return;
        var label = c.label || c.key || 'Требование';
        var valText = '';
        if (typeof c.have !== 'undefined' && typeof c.need !== 'undefined'){
          valText = String(c.have) + ' / ' + String(c.need);
        } else {
          valText = String(c.text || '');
        }
        // chkRow expects plain text, but for resource row we pass prebuilt html.
        if (c.key === 'res' && c.res){
          var cls = c.ok ? '' : ' coc-need-bad';
          var ic = resIconAny(c.res);
          needHtml += '<div class="coc-bdetail-tile'+cls+'"><div class="coc-bdetail-k">'+esc(label)+'</div><div class="coc-bdetail-v"><img src="'+ic+'" style="height:16px;vertical-align:middle;margin-right:4px;">'+esc(valText)+'</div></div>';
        } else {
          needHtml += chkRow(label, valText, !!c.ok);
        }
      });
      needHtml += '</div>';
    }

    var reason = (u.upgrade && u.upgrade.reason) ? String(u.upgrade.reason) : (u.lab && u.lab.upgrade_reason ? String(u.lab.upgrade_reason) : '');
    var showReason = (!canUpgrade && reason);
// Если заклинание заблокировано (требуется фабрика/ратуша), не дублируем причину в блоке улучшения
if (showReason && lockedReason){
  if (String(reason) === String(lockedReason)) showReason = false;
  else if (/^Требуется\s+/i.test(reason) && /^Требуется\s+/i.test(lockedReason)) showReason = false;
}
    if (showReason && /Требуется\s+Ратуша/i.test(reason)){
      for (var ii=0; ii<checks.length; ii++){
        if (checks[ii] && checks[ii].key==='th'){ showReason = false; break; }
      }
    }
    var reasonHtml = showReason ? ('<div class="coc-bdetail-block coc-need-bad" style="margin-top:8px;">'+esc(reason)+'</div>') : '';

    upgradeHtml = '<div class="coc-bdetail-block" style="margin-top:10px;">' +
      '<div class="coc-bdetail-sub" style="margin:0 0 6px 0;">Улучшение</div>' +
      upgGrid +
      needHtml +
      reasonHtml +
    '</div>';
  }

  // optional fields if backend provides (render only if exist)
  if (cur.hp) addTile('❤ Здоровье', cur.hp); 

  if (cur.attack_speed){
    var sp = String(cur.attack_speed);
    if (!/\bсек\b/i.test(sp)) sp = sp + ' в сек';
    addTile('⚡ Скорость атаки', sp);
  }
var dmgType = cur.damage_type || cur.damageType || cur.attack_type || cur.attackType;
  if (dmgType) addTile(' Тип атаки', trEnum('damage_type', dmgType));
  var tgt = cur.targets || cur.target_type || cur.targetType;
  if (tgt) addTile(' Цели', trEnum('targets', tgt));
  var fav = cur.fav_target || cur.favTarget || cur.favorite_target || cur.favoriteTarget;
  if (fav) addTile(' Избранная цель', trEnum('fav_target', fav));

  var lockTxt = lockedReason ? String(lockedReason) : '';

  var actions = '';
  // Active upgrade/research timer for this unit/spell (laboratory or barracks implementations differ)
  function pickActiveTimer(info){
    if (!info) return null;
    var cand = [];
    if (info.research) cand.push(info.research);
    if (info.upgrade) cand.push(info.upgrade);
    if (info.lab && info.lab.research) cand.push(info.lab.research);
    if (info.lab && info.lab.upgrade) cand.push(info.lab.upgrade);
    // choose the one with positive time_left or future finish timestamp
    for (var i=0;i<cand.length;i++){
      var t = cand[i];
      if (!t) continue;
      var tl = parseInt(t.time_left,10)||0;
      var fin = parseInt(t.finish_time,10)||parseInt(t.finish_ts,10)||parseInt(t.finish_at,10)||parseInt(t.ends_at,10)||parseInt(t.end_time,10)||0;
      if (tl>0 || (fin && fin>nowServer())) return t;
    }
    return null;
  }

  function timerTargetId(t){
    if (!t) return '';
    return String(t.tech_id || t.unit_id || t.spell_id || t.id || t.target_id || '');
  }

  function timerFinishTs(t){
    if (!t) return 0;
    var fin = parseInt(t.finish_time,10)||parseInt(t.finish_ts,10)||parseInt(t.finish_at,10)||parseInt(t.ends_at,10)||parseInt(t.end_time,10)||0;
    if (!fin){
      var tl = parseInt(t.time_left,10)||0;
      if (tl>0) fin = nowServer() + tl;
    }
    return fin;
  }

  var r = pickActiveTimer(u);
  var researchingThis = false;
  var rLeft = 0;
  var rFinish = 0;
  if (r && r.tech_id){
    researchingThis = (timerTargetId(r) === String(unit.id)) && ((parseInt(r.time_left,10)||0) > 0);
    rLeft = parseInt(r.time_left,10) || 0;
    rFinish = timerFinishTs(r);
  }

  // --- Action button / progress ---
  var actionsHtml = '';
  var chipsHtml = '';
  var needTxt = '';

  // Active upgrade/research timer for this unit/spell (laboratory or barracks implementations differ)
  function pickActiveTimer(info){
    if (!info) return null;
    var cand = [];
    if (info.research) cand.push(info.research);
    if (info.upgrade) cand.push(info.upgrade);
    if (info.lab && info.lab.research) cand.push(info.lab.research);
    if (info.lab && info.lab.upgrade) cand.push(info.lab.upgrade);
    // choose the one with positive time_left or future finish timestamp
    for (var i=0;i<cand.length;i++){
      var t = cand[i];
      if (!t) continue;
      var tl = parseInt(t.time_left,10)||0;
      var fin = parseInt(t.finish_time,10)||parseInt(t.finish_ts,10)||parseInt(t.finish_at,10)||parseInt(t.ends_at,10)||parseInt(t.end_time,10)||0;
      if (tl>0 || (fin && fin>nowServer())) return t;
    }
    return null;
  }

  function timerTargetId(t){
    if (!t) return '';
    return String(t.tech_id || t.unit_id || t.spell_id || t.id || t.target_id || '');
  }

  function timerFinishTs(t){
    if (!t) return 0;
    var fin = parseInt(t.finish_time,10)||parseInt(t.finish_ts,10)||parseInt(t.finish_at,10)||parseInt(t.ends_at,10)||parseInt(t.end_time,10)||0;
    if (!fin){
      var tl = parseInt(t.time_left,10)||0;
      if (tl>0) fin = nowServer() + tl;
    }
    return fin;
  }

  var r = pickActiveTimer(u);
  var researchingThis = false;
  var rLeft = 0;
  if (r && r.tech_id){
    researchingThis = (timerTargetId(r) === String(unit.id)) && ((parseInt(r.time_left,10)||0) > 0);
    rLeft = parseInt(r.time_left,10) || 0;
  }

  // Main button state (do NOT use native disabled to keep click-to-toast patterns consistent)
  var hasNext = !!nextLvl;
  var btnLabel = hasNext ? 'УЛУЧШИТЬ' : 'МАКС. УРОВЕНЬ';
  var btnDisabled = (!hasNext) || upgrading || !canUpgrade || (r && r.tech_id && (parseInt(r.time_left,10)||0) > 0);
  var btnClsExtra = (btnDisabled ? ' is-disabled' : '') + ((upgrading||researchingThis)?' is-busy':'');

  // Chips: cost/time (when available)
  if (!upgrading && hasNext && nxt && nxt.cost!==undefined && nxt.cost!==null && String(nxt.cost)!==''){
    chipsHtml += '<div class="coc-um-chip">'+resIconImg(nxt && nxt.res_type ? nxt.res_type : (u.res_type||'elixir'))+' '+esc(formatNumber(parseInt(nxt.cost,10)||0))+'</div>';
  }
  if (!upgrading && hasNext && nxt && nxt.time!==undefined && nxt.time!==null && String(nxt.time)!==''){
    chipsHtml += '<div class="coc-um-chip">⏳ '+esc(formatTimeSmart(parseInt(nxt.time,10)||0))+'</div>';
  }

  // Locked reason & requirements (keep visible in the same place as in Heroes modal)
  if (lockTxt) needTxt = lockTxt;
  if (btnDisabled && !needTxt){
    var reason = (u.upgrade && u.upgrade.reason) ? String(u.upgrade.reason) : (u.lab && u.lab.upgrade_reason ? String(u.lab.upgrade_reason) : '');
    if (reason) needTxt = reason;
  }

  var mainBtnHtml = '<button type="button" class="coc-um-btn coc-um-main'+btnClsExtra+'" id="coc-unit-upg" data-unitupg="'+esc(unit.id)+'">'+esc(btnLabel)+'</button>';
  if (btnDisabled && needTxt){
    mainBtnHtml = mainBtnHtml.replace('data-unitupg="'+esc(unit.id)+'"', 'data-unitupg="'+esc(unit.id)+'" data-unitlockmsg="'+esc(needTxt)+'"');
  }

  // Progress bar inside modal (when research is running)
  var progHtml = '';
  if (r && (parseInt(r.time_left,10)||0) > 0){
    var total = (researchingThis && nxt && nxt.time) ? (parseInt(nxt.time,10)||0) : 0;
    var pct = (researchingThis && total>0) ? Math.max(0, Math.min(100, Math.round(100 * (1 - (rLeft/total))))) : 0;
    progHtml = '<div class="coc-um-prog" id="coc-udetail-upgbar"><div class="coc-um-progbar" style="width:'+pct+'%"></div></div>';
    chipsHtml += '<div class="coc-um-chip">⏳ <span id="coc-udetail-upgleft">'+esc(formatDurationSmart(rLeft))+'</span></div>';
  }

  // Tiles (keep the same data, but render in Heroes modal style)
  function renderUmTiles(arr){
    var html = '';
    for (var i=0;i<arr.length;i++){
      var t = arr[i];
      if (!t) continue;
      var vv = t.v;
      if (vv === null || typeof vv === 'undefined') vv = '—';
      if (typeof vv !== 'string') vv = String(vv);
      if (vv === '' || vv === 'null' || vv === 'undefined') vv = '—';
      var vvHtml = (vv.indexOf('<') !== -1) ? vv : esc(vv);
      var ico = emojiForKey(t.key || t.k);
      html += '<div class="coc-um-tile">'
        + '<div class="coc-um-tl">'+esc(String(t.k))+'</div>'
        + '<div class="coc-um-tv">'+vvHtml+'</div>'
      + '</div>';
    }
    return html;
  }

  // Image: spells prefer *_info.png; troops have resilient fallbacks
  var artHtml = (function(){
    if (kind === 'spell'){
      var sCand = normalizeImgPath(img || unit.img || '');
      if (!sCand || !/\/images\/spells\/.+_info\.png$/i.test(sCand)) sCand = spellImgFromId(unit.id);
      return imgWithFallback(sCand, [spellImgFromId(unit.id), '/images/icons/elixir.png']);
    }
    var _cands = unitImageCandidates(unit.id);
    return imgWithFallback(_cands[0] || img || unit.img || '', _cands.concat([img, unit.img].filter(Boolean)));
  })();

  var sub = (kind==='spell' ? 'Заклинание' : 'Войска');
  var levelText = 'Уровень ' + lvl + (maxLvl?(' / ' + maxLvl):'');

  return (
    '<div class="coc-um-modal coc-um-modal--unit" role="dialog" aria-modal="true">'
      + '<div class="coc-um-head">'+esc(name)+'<button type="button" class="coc-um-x" data-unitmodalclose="1">×</button></div>'
      + '<div class="coc-um-sub">'+esc(sub)+' • '+esc(levelText)+'</div>'
      + progHtml
      + '<div class="coc-um-body">'
        + '<div class="coc-um-left">'
          + '<div class="coc-um-art">'+artHtml+'</div>'
          + (desc ? ('<div class="coc-um-lore">'+esc(desc)+'</div>') : '')
        + '</div>'
        + '<div class="coc-um-right">'
          + '<div class="coc-um-tiles">'+renderUmTiles(tileArr)+'</div>'
          + statsHtml
        + '</div>'
      + '</div>'
      + '<div class="coc-um-actions">'
        + mainBtnHtml
        + '<div class="coc-um-chips">'+chipsHtml+'</div>'
        + (needTxt ? ('<div class="coc-um-need">🔒 '+esc(needTxt)+'</div>') : '')
      + '</div>'
    + '</div>'
  );
}

function openUnitDetailModal(){
  // remove building detail if open
  closeBuildingDetailModal();
  closeUnitDetailModal();

  var overlay = document.createElement('div');
  overlay.id = 'coc-udetail-overlay';
  overlay.className = 'coc-hmodal-overlay';

  overlay.innerHTML = renderUnitDetailModalHtml();
  document.body.appendChild(overlay);
  setTimeout(ensureToastOnTop,0);

  var onResize = function(){};

  // bind close
  function closeMe(){
    window.removeEventListener('resize', onResize);
    state.unitInfo = null;
    closeUnitDetailModal();
  }
  overlay.addEventListener('click', function(e){
    // close on background click
    if (e.target === overlay) closeMe();
  });
  var closeBtn = overlay.querySelector('[data-unitmodalclose]');
  if (closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); closeMe(); });

  // If main button is disabled, show the reason
  overlay.addEventListener('click', function(e){
    var t = e.target;
    var btn = closest(t, '#coc-unit-upg,[data-unitupg]');
    if (btn){
      var msg = btn.getAttribute('data-unitlockmsg') || '';
      if (msg && (btn.classList && btn.classList.contains('is-disabled'))){
        showBarracksToast('info', 'Казармы', String(msg));
        e.preventDefault();
        e.stopPropagation();
      }
    }
  });

  var upgBtn = overlay.querySelector('#coc-unit-upg');
  if (upgBtn){
    upgBtn.addEventListener('click', function(e){
      e.preventDefault();
      try{ e.stopImmediatePropagation && e.stopImmediatePropagation(); }catch(_e){}
      e.stopPropagation();
      // Disabled button: show reason
      if (upgBtn.classList && upgBtn.classList.contains('is-disabled')){
        var m = upgBtn.getAttribute('data-unitlockmsg') || '';
        if (m) showBarracksToast('info','Казармы', String(m));
        return;
      }
      var uid = upgBtn.getAttribute('data-unitupg') || '';
      if (!uid || !state.unitInfo || !state.unitInfo.unit) return;

      // NOTE: в API unit_info права лежат в state.unitInfo.upgrade / state.unitInfo.lab
      var can = !!(state.unitInfo.upgrade && state.unitInfo.upgrade.can);
      var busyAny = !!(state.unitInfo.research && state.unitInfo.research.tech_id && (parseInt(state.unitInfo.research.time_left,10)||0) > 0);
      if (!can || busyAny) return;

      // Prevent duplicate requests/toasts
      var k = 'lab_start:'+String(uid);
      var nowTs = Date.now();
      if (labStartInFlight[k]) return;
      if (labStartLastClick[k] && (nowTs - labStartLastClick[k]) < 700) return;
      labStartLastClick[k] = nowTs;
      labStartInFlight[k] = nowTs;

      upgBtn.disabled = true;
      apiPost('lab_start', { tech_id: uid }).then(function(){
        showBarracksToast('ok', 'Лаборатория', 'Улучшение начато.');
        return Promise.all([
          loadServerState(true),
          apiGetParams('unit_info', { unit_id: uid })
        ]);
      }).then(function(arr){
        try{ delete labStartInFlight[k]; }catch(_e0){}
        var info = arr[1];
        state.unitInfo = info;
        state.unitInfo.kind = info.kind || state.unitInfo.kind;
        openUnitDetailModal();
      }).catch(function(err){
        try{ delete labStartInFlight[k]; }catch(_e1){}
        showBarracksToast('error', 'Лаборатория', (err && err.message) ? err.message : 'Ошибка');
        loadServerState(true);
        closeMe();
      });
    });
  }

  // live research timer (без обновления страницы)
  if (cocUnitTimerInterval){ clearInterval(cocUnitTimerInterval); cocUnitTimerInterval = null; }

  function tickResearch(){
    if (!state.unitInfo || !state.unitInfo.unit) return;

    // Prefer the same picker as renderer (research/upgrade may live in different fields)
    var info = state.unitInfo;
    var r = (function(){
      var cand = [];
      if (info.research) cand.push(info.research);
      if (info.upgrade) cand.push(info.upgrade);
      if (info.lab && info.lab.research) cand.push(info.lab.research);
      if (info.lab && info.lab.upgrade) cand.push(info.lab.upgrade);
      for (var i=0;i<cand.length;i++){
        var t = cand[i];
        if (!t) continue;
        var tl = parseInt(t.time_left,10)||0;
        var fin = parseInt(t.finish_time,10)||parseInt(t.finish_ts,10)||parseInt(t.finish_at,10)||parseInt(t.ends_at,10)||parseInt(t.end_time,10)||0;
        if (tl>0 || (fin && fin>nowServer())) return t;
      }
      return null;
    })();

    if (!r) return;

    // finish timestamp
    var finish = parseInt(r.finish_time,10)||parseInt(r.finish_ts,10)||parseInt(r.finish_at,10)||parseInt(r.ends_at,10)||parseInt(r.end_time,10)||0;
    if (!finish){
      var tl0 = parseInt(r.time_left,10)||0;
      if (tl0>0) finish = nowServer() + tl0;
    }
    if (!finish) return;

    var left = Math.max(0, finish - nowServer());
    r.time_left = left;

    var timeEl = overlay.querySelector('#coc-udetail-upgleft');
    if (timeEl) timeEl.textContent = formatDurationSmart(left);

    var bar = overlay.querySelector('#coc-udetail-upgbar');
    if (bar){
      var targetId = String(r.tech_id || r.unit_id || r.spell_id || r.id || r.target_id || '');
      var isThis = (targetId && String(info.unit && info.unit.id) === targetId);

      // try to determine total time
      var total = 0;
      if (isThis){
        try{
          if (info.next && info.next.time) total = parseInt(info.next.time,10) || 0;
        }catch(_){}
      }
      if (!total){
        total = parseInt(r.total_time,10)||parseInt(r.duration,10)||parseInt(r.time,10)||0;
      }

      var fill = bar.querySelector('.coc-um-progbar') || bar.firstElementChild;
      if (isThis && total > 0){
        var pct = Math.max(0, Math.min(100, Math.round(100 * (1 - (left/total)))));
        bar.classList.remove('is-indeterminate');
        if (fill) fill.style.width = pct + '%';
      } else {
        bar.classList.add('is-indeterminate');
        if (fill) fill.style.width = '25%';
      }
    }

    if (left <= 0){
      clearInterval(cocUnitTimerInterval); cocUnitTimerInterval = null;
      var uid = info.unit.id;
      loadServerState(true).then(function(){
        return apiGetParams('unit_info', { unit_id: uid });
      }).then(function(info2){
        state.unitInfo = info2;
        state.unitInfo.kind = info2.kind || state.unitInfo.kind;
        openUnitDetailModal();
      }).catch(function(){
        loadServerState(true);
        closeMe();
      });
    }
  }

  // запуск таймера если есть активное улучшение/исследование (для текущего юнита или для показа 'лаба занята')
  var _tCand = [];
  if (state.unitInfo){
    if (state.unitInfo.research) _tCand.push(state.unitInfo.research);
    if (state.unitInfo.upgrade) _tCand.push(state.unitInfo.upgrade);
    if (state.unitInfo.lab && state.unitInfo.lab.research) _tCand.push(state.unitInfo.lab.research);
    if (state.unitInfo.lab && state.unitInfo.lab.upgrade) _tCand.push(state.unitInfo.lab.upgrade);
  }
  var _hasTimer = false;
  for (var _i=0; _i<_tCand.length; _i++){
    var _t = _tCand[_i];
    if (!_t) continue;
    var _tl = parseInt(_t.time_left,10)||0;
    if (_tl>0){ _hasTimer = true; break; }
    var _fin = parseInt(_t.finish_time,10)||parseInt(_t.finish_ts,10)||parseInt(_t.finish_at,10)||parseInt(_t.ends_at,10)||parseInt(_t.end_time,10)||0;
    if (_fin && _fin>nowServer()){ _hasTimer = true; break; }
  }
  if (_hasTimer){
    tickResearch();
    cocUnitTimerInterval = setInterval(tickResearch, 1000);
  }
}
function getBuildingBusyInfo(b){
  if (!b) return null;

  var finish = parseInt(b.finish_time, 10) || 0;
  if (!finish) return null;

  var left = Math.max(0, finish - nowServer());
  if (left <= 0) return null;

  // Some backends keep status='active' even while building/upgrading.
  var status = String(b.status || 'none');
  var inferred = status;

  if (status === 'active' || status === 'none'){
    // Infer by permissions/level when status is not informative.
    if ((parseInt(b.level,10)||0) <= 0 || b.can_build === false){
      inferred = 'building';
    } else if (b.can_upgrade === false){
      inferred = 'upgrading';
    } else {
      inferred = 'upgrading';
    }
  }

  return { status: inferred, finish: finish, left: left };
}

	function doBuildingSpeedup(bid){
	  if (!bid) return;
	  apiPost('building_speedup', { building_id: bid, quote: 1 }).then(function(q){
	    var cost = 0;
	    if (q && typeof q.cost_gems !== 'undefined') cost = parseInt(q.cost_gems,10) || 0;
	    else if (q && typeof q.cost !== 'undefined') cost = parseInt(q.cost,10) || 0;
	    return window.cocConfirm({
	      title: 'Постройки',
	      text: 'Ускорить строительство/улучшение за гемы?',
	      cost: cost,
	      costIconHtml: resIconImg('gems')
	    }).then(function(ok){
	      if (!ok) return null;
	      return apiPost('building_speedup', { building_id: bid }).then(function(r){
	        var spent = (r && typeof r.cost_gems !== 'undefined') ? (parseInt(r.cost_gems,10)||0) : cost;
	        if (spent > 0) showBarracksToast('ok', 'Постройки', 'Ускорено за ' + formatNumber(spent) + ' ');
	        else showBarracksToast('ok', 'Постройки', 'Ускорено.');
	        return loadServerState(true).then(function(){
	          return apiGetParams('building_info', { building_id: bid }).then(function(d){
	            state.buildingInfo = d;
	            refreshOpenBuildingDetailModal();
	          });
	        });
	      });
	    });
	  }).catch(function(err){
	    showBarracksToast('error', 'Постройки', (err && err.message) ? err.message : 'Ошибка ускорения');
	    loadServerState(true);
	  });
	}


function refreshOpenBuildingDetailModal(){
  try{
    var overlay = document.getElementById('coc-bdetail-overlay');
    if (!overlay) return;
    var modal = overlay.querySelector('.coc-bdetail-modal');
    if (!modal) return;

    modal.innerHTML = renderBuildingDetailModal();

    var closeBtn = modal.querySelector('.coc-bdetail-close');
    if (closeBtn) closeBtn.addEventListener('click', function(){ closeBuildingDetailModal(); });

    var backBtn = modal.querySelector('#coc-bdetail-back');
    if (backBtn) backBtn.addEventListener('click', function(){ closeBuildingDetailModal(); });

    var actBtn = modal.querySelector('#coc-bld-act');
    if (actBtn){
      actBtn.dataset.boundClick = '1';
      actBtn.addEventListener('click', function(){
        var bid = actBtn.getAttribute('data-bact');
        if (typeof onBuildingAction === 'function') return onBuildingAction(bid);
        if (typeof doBuildingAction === 'function') return doBuildingAction(bid);
        if (typeof window.buildOrUpgradeBuilding === 'function') return window.buildOrUpgradeBuilding(bid);
      });
    }

	    var spBtn = modal.querySelector('#coc-bld-speedup');
	    if (spBtn){
	      spBtn.addEventListener('click', function(e){
	        var bid = spBtn.getAttribute('data-bspeedup') || '';
	        if (!bid) return;
	        spBtn.disabled = true;
	        doBuildingSpeedup(bid);
	        e.preventDefault();
	      });
	    }

    // keep toast above overlay
    setTimeout(ensureToastOnTop, 0);
  }catch(_){}
}

function openBuildingDetailModal(){
  closeBuildingDetailModal();
  var overlay = document.createElement('div');
  overlay.id = 'coc-bdetail-overlay';
  overlay.className = 'coc-bdetail-overlay';

  overlay.addEventListener('click', function(e){
    if (e.target === overlay) { closeBuildingDetailModal(); return; }
  });

  var modal = document.createElement('div');
  modal.className = 'coc-bdetail-modal';
  modal.innerHTML = renderBuildingDetailModal();
  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  var closeBtn = modal.querySelector('.coc-bdetail-close');
  if (closeBtn) closeBtn.addEventListener('click', function(){ closeBuildingDetailModal(); });

  var backBtn = modal.querySelector('#coc-bdetail-back');
  if (backBtn) backBtn.addEventListener('click', function(){ closeBuildingDetailModal(); });

  var actBtn = modal.querySelector('#coc-bld-act');
  if (actBtn) actBtn.addEventListener('click', function(){
    var bid = actBtn.getAttribute('data-bact');
    if (typeof onBuildingAction === 'function') return onBuildingAction(bid);
    if (typeof doBuildingAction === 'function') return doBuildingAction(bid);
    if (typeof window.buildOrUpgradeBuilding === 'function') return window.buildOrUpgradeBuilding(bid);
  });

	  var spBtn = modal.querySelector('#coc-bld-speedup');
	  if (spBtn) spBtn.addEventListener('click', function(e){
	    var bid = spBtn.getAttribute('data-bspeedup') || '';
	    if (!bid) return;
	    spBtn.disabled = true;
	    doBuildingSpeedup(bid);
	    e.preventDefault();
	  });

  // live timer/progress
  if (cocBuildingTimerInterval){ clearInterval(cocBuildingTimerInterval); cocBuildingTimerInterval = null; }

  function tick(){
    var data = state.buildingInfo;
    var b = data && data.building ? data.building : null;
    if (!b) return;

    var status = String(b.status || 'none');
    var busy = (status !== 'active' && status !== 'none');
    var finish = parseInt(b.finish_time, 10) || 0;

    // Building timer (construct/upgrade)
    var left = (busy && finish) ? Math.max(0, finish - nowServer()) : 0;
    var timeEl = modal.querySelector('#coc-bdetail-timeleft');
    if (timeEl) timeEl.textContent = busy ? formatDurationSmart(left) : timeEl.textContent;

    var total = 0;
    try{
      // Prefer server-provided upgrade/build duration (it matches finish_time)
      if (data && data.next && data.next.time){
        total = parseInt(data.next.time,10) || 0;
      }
    }catch(_){}

    var bar = modal.querySelector('#coc-bdetail-progress');
    if (bar){
      if (total > 0){
        var pct = Math.max(0, Math.min(100, Math.round(100 * (1 - (left/total)))));
        bar.classList.remove('coc-progress-indeterminate');
        bar.firstElementChild.style.width = pct + '%';
      } else {
        bar.classList.add('coc-progress-indeterminate');
      }
    }


    // Laboratory research timer
    if (String(b.id||'') === 'laboratory' && state.labState && state.labState.active){
      var a = state.labState.active;
      var afin = parseInt(a.finish_time,10) || 0;
      var aleft = (afin > 0) ? Math.max(0, afin - nowServer()) : (parseInt(a.time_left,10)||0);
      a.time_left = aleft;
      var lt = modal.querySelector('#coc-lab-timeleft');
      if (lt) lt.textContent = formatDurationSmart(aleft);
      if (aleft <= 0){
        // refresh lab state once finished
        apiGetParams('lab_state', {}).then(function(ls){
          state.labState = ls;
          // also refresh building info to update levels
          if (state.buildingInfo && state.buildingInfo.building && state.buildingInfo.building.id){
            return apiGetParams('building_info', { building_id: state.buildingInfo.building.id }).then(function(d){ state.buildingInfo = d; });
          }
        }).catch(function(){});
      }
    }
    if (busy && finish && left <= 0){
      // timer would have ended; refresh once (no interval ticking)
      if (typeof fetchBarracksData === 'function') fetchBarracksData();
    }
  }

  tick();
  // building timers disabled: no live ticking
  cocBuildingTimerInterval = null;

  // keep list visible behind modal
  render();
}

function ruUnlockName(id){
  // Troops / dark troops / siege / heroes (best-effort)
  var m = {
    // basic troops
    "barbarian":"Варвар",
    "archer":"Лучница",
    "giant":"Гигант",
    "goblin":"Гоблин",
    "wall_breaker":"Ломатель стен",
    "balloon":"Шар",
    "wizard":"Волшебник",
    "healer":"Целительница",
    "dragon":"Дракон",
    "pekka":"П.Е.К.К.А.",
    "baby_dragon":"Дракончик",
    "miner":"Шахтёр",
    "electro_dragon":"Электродракон",
    "yeti":"Йети",
    "dragon_rider":"Дрессировщик драконов",

    // dark troops
    "minion":"Миньон",
    "hog_rider":"Всадник на кабане",
    "valkyrie":"Валькирия",
    "golem":"Голем",
    "witch":"Ведьма",
    "lava_hound":"Лавовый пёс",
    "bowler":"Боулер",
    "ice_golem":"Ледяной голем",
    "headhunter":"Охотница за головами",

    // super troops (common)
    "super_barbarian":"Суперварвар",
    "super_archer":"Суперлучница",
    "super_giant":"Супергигант",
    "super_goblin":"Супергоблин",
    "super_wall_breaker":"Суперломатель стен",
    "super_wizard":"Суперволшебник",
    "super_dragon":"Супердракон",
    "super_minion":"Суперминьон",
    "super_valkyrie":"Супервалькирия",
    "super_witch":"Суперведьма",
    "sneaky_goblin":"Скрытный гоблин",
    "rocket_balloon":"Ракетный шар",
    "inferno_dragon":"Инфернодракон",
    "ice_hound":"Ледяной пёс",

    // siege machines
    "wall_wrecker":"Стенолом",
    "battle_blimp":"Боевой дирижабль",
    "stone_slammer":"Камнедробитель",
    "siege_barracks":"Осадные казармы",
    "log_launcher":"Пускатель брёвен",
    "flame_flinger":"Огнемёт",
    "battle_drill":"Боевая дрель",
    "troop_launcher":"Пускатель войск",

    // heroes
    "barbarian_king":"Король варваров",
    "archer_queen":"Королева лучниц",
    "grand_warden":"Великий хранитель",
    "royal_champion":"Королевская чемпионка",

    // spells
    "lightning_spell":"Заклинание молнии",
    "healing_spell":"Заклинание исцеления",
    "rage_spell":"Заклинание ярости",
    "jump_spell":"Заклинание прыжка",
    "freeze_spell":"Заклинание заморозки",
    "clone_spell":"Заклинание клонирования",
    "invisibility_spell":"Заклинание невидимости",
    "recall_spell":"Заклинание возврата",

    // dark spells
    "poison_spell":"Заклинание яда",
    "earthquake_spell":"Заклинание землетрясения",
    "haste_spell":"Заклинание ускорения",
    "skeleton_spell":"Заклинание скелетов",
    "bat_spell":"Заклинание летучих мышей",
    "overgrowth_spell":"Заклинание разрастания"
  };

  if (!id) return "—";
  // backend may send "troop:wizard", "Wizard", etc.
  var key = String(id)
    .toLowerCase()
    .replace(/^troop[:_\-]/,'')
    .replace(/^unit[:_\-]/,'')
    .replace(/^hero[:_\-]/,'')
    .replace(/\s+/g,'_')
    .replace(/[^a-z0-9_]/g,'_')
    .replace(/_+/g,'_')
    .replace(/^_+|_+$/g,'');

  return m[key] || (key ? (key.charAt(0).toUpperCase()+key.slice(1)) : "—");
}


function renderBuildingDetailModal(){
  var data = state.buildingInfo;
  var b = data && data.building ? data.building : null;
  if (!b) return '<div class="coc-bdetail-head">Постройка</div><div class="coc-bdetail-body">Нет данных</div>';

  var lvl = parseInt(b.level, 10) || 0;
  var maxLvl = parseInt(b.max_level, 10) || 0;
  var cur = b.current || data.current || {};
  var next = b.next || data.next || null;

  function pickFirst(obj, keys){
    for (var i=0;i<keys.length;i++){
      var k = keys[i];
      if (obj && typeof obj[k] !== 'undefined' && obj[k] !== null && obj[k] !== '') return obj[k];
    }
    return null;
  }
  function fmt(v){
    if (v === null || typeof v === 'undefined' || v === '') return null;
    if (typeof v === 'boolean') return v ? 'Да' : 'Нет';
    return String(v);
  }

  var name = String(b.name || b.id || 'Постройка');
  var desc = buildingRichDesc(b, cur, next);
  // IMPORTANT: do not default HP to 0 when backend doesn't provide it.
  // Otherwise we render misleading "0" instead of real values from current/next.
  var hp = pickFirst(cur, ['hp','health','hp_current','current_hp']);
  if (hp === null) hp = pickFirst(b, ['hp','health']);
  var hpMax = pickFirst(cur, ['max_hp','health_max','hp_max','maxHealth']);
  if (hpMax === null) hpMax = pickFirst(b, ['max_hp','health_max','hp_max']);

  var limitMax = pickFirst(b, ['limit','max_count','max','cap','max_buildings']);
  var limitHave = pickFirst(b, ['count','built','built_count','current_count','have']);
  var reqTh = pickFirst(next || b, ['th_req','townhall_req','required_th','th']);
  var reqBuilder = pickFirst(next || b, ['builder_req','builders_req','required_builder','builder']);

  function getCost(obj){
    if (!obj) return {amount:null, icon:''};
    var amt = pickFirst(obj, ['cost','price','amount']);
    var rt = pickFirst(obj, ['res_type','resource','type']);
    if (amt === null){
      var ce = pickFirst(obj, ['cost_elixir','elixir']);
      var cg = pickFirst(obj, ['cost_gold','gold']);
      var cd = pickFirst(obj, ['cost_dark','dark_elixir']);
      if (ce !== null && String(ce) !== '0') { amt = ce; rt = 'elixir'; }
      else if (cg !== null && String(cg) !== '0') { amt = cg; rt = 'gold'; }
      else if (cd !== null && String(cd) !== '0') { amt = cd; rt = 'dark'; }
    }
    var icon = rt ? resIconAny(String(rt)) : '';
    return {amount: fmt(amt), icon: icon};
  }
  function getTime(obj){
    if (!obj) return null;
    var t = pickFirst(obj, ['time','upgrade_time','build_time','duration']);
    var n = parseInt(t,10);
    if (!isNaN(n)) return formatDurationSmart(n);
    return fmt(t);
  }

  var status = String(b.status || 'none');
  var busy = (status !== 'active' && status !== 'none');

  // Even if the building is currently constructing/upgrading, we still want to show
  // the target level cost/time (as in CoC). Backend often doesn't repeat cost/time in `building` while busy,
  // but keeps it in `next` / `data.next`.
  var cost = next ? getCost(next) : getCost(b);
  var timeText = next ? getTime(next) : getTime(b);
  var costText = cost.amount ? cost.amount : '—';
  var timeShow = timeText ? timeText : '—';

  var lockReason = b.locked_reason ? String(b.locked_reason) : '';
  var canBuild = !!b.can_build;
  var canUp = !!b.can_upgrade;
  var btnLabel = canBuild ? 'ПОСТРОИТЬ' : (canUp ? 'УЛУЧШИТЬ' : (busy ? 'ЗАНЯТО' : 'НЕДОСТУПНО'));
  var btnDisabled = (!canBuild && !canUp) ? 'disabled="disabled"' : '';
  if (busy) btnDisabled = 'disabled="disabled"';

  var tiles = [];
  tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k">⭐ Уровень</div><div class="coc-bdetail-v">'+esc(String(lvl))+' / '+esc(String(maxLvl))+'</div></div>');
  tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k"> Стоимость</div><div class="coc-bdetail-v">'+esc(costText)+' '+(cost.icon?('<img src="'+esc(cost.icon)+'" alt="" style="width:16px;height:16px;vertical-align:-3px;">'):'')+'</div></div>');
  tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k">⏱ Время</div><div class="coc-bdetail-v">'+esc(timeShow)+'</div></div>');
	  // Capacity tile (Army Camp / Spell Factory)
	  var capTile = pickFirst(cur, ['capacity','cap','storage','slots']);
	  if (capTile === null) capTile = pickFirst(b, ['capacity','cap','storage','slots']);
	  var capNextTile = next ? pickFirst(next, ['capacity','cap','storage','slots']) : null;
	  if (capTile !== null && capTile !== undefined && String(capTile) !== '' && String(capTile) !== '0'){
	    var bid0 = String(b.id||'');
	    var capLabel = (bid0 === 'army_camp') ? 'Вместимость армии' : ((bid0 === 'spell_factory') ? 'Вместимость заклинаний' : 'Вместимость');
	
	    function capFmt(total, obj){
	      if (total === null || typeof total === 'undefined') return '';
	      var t = String(total);
	      if (bid0 === 'army_camp'){
	        var per = pickFirst(obj, ['capacity_per_camp','per_camp','perCamp']);
	        var mult = pickFirst(obj, ['virtual_camps','camps','mult']);
	        per = parseInt(per,10); mult = parseInt(mult,10);
	        if (!isNaN(per) && !isNaN(mult) && per>0 && mult>0){
	          t += ' ('+ mult + '×' + per + ')';
	        }
	      }
	      return t;
	    }

	    var v = capFmt(capTile, cur);
	    if (capNextTile !== null && capNextTile !== undefined && String(capNextTile) !== '' && String(capNextTile) !== '0'){
	      v += ' → ' + capFmt(capNextTile, next);
	    }
	    tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k"> '+esc(capLabel.toUpperCase())+'</div><div class="coc-bdetail-v">'+esc(v)+'</div></div>');
	  }
  var unlock = (next && next.unlocks) ? next.unlocks : (cur && cur.unlocks ? cur.unlocks : null);
  if (unlock) tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k"> Открывает</div><div class="coc-bdetail-v">'+esc(translateUnlocks(unlock))+'</div></div>');
  if (hp !== null || hpMax !== null) tiles.push('<div class="coc-bdetail-tile meta-hp"><div class="coc-bdetail-k">❤ Здоровье</div><div class="coc-bdetail-v">'+esc(fmt(hp) || '—')+(hpMax!==null?(' / '+esc(fmt(hpMax) || '—')):'')+'</div></div>');
  if (limitMax !== null || limitHave !== null) tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k"> Лимит</div><div class="coc-bdetail-v">'+esc(fmt(limitHave) || '—')+' / '+esc(fmt(limitMax) || '—')+'</div></div>');
  if (reqTh !== null && reqTh !== undefined && reqTh !== '' && reqTh !== 0 && reqTh !== '0') tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k"> Требуется Ратуша</div><div class="coc-bdetail-v">'+esc(fmt(reqTh))+'</div></div>');
  if (reqBuilder !== null) tiles.push('<div class="coc-bdetail-tile"><div class="coc-bdetail-k"> Требуются строители</div><div class="coc-bdetail-v">'+esc(fmt(reqBuilder) || '—')+'</div></div>');

  var blocks = '';
  if (busy){
    var finish = parseInt(b.finish_time,10) || 0;
    var left = finish ? Math.max(0, finish - nowServer()) : 0;
    var phaseTxt = (String(b.status||'')==='building') ? ' Идёт стройка' : '⬆ Идёт улучшение';
    blocks += '<div class="coc-bdetail-block"><div class="coc-bdetail-bt">'+phaseTxt+'</div>'+
      '<div class="coc-progress" id="coc-bdetail-progress"><div></div></div>'+
      '<div style="margin-top:6px;font-weight:900;">Осталось: <span id="coc-bdetail-timeleft">'+esc(formatDurationSmart(left))+'</span></div>'+
    '</div>';
  }

	  // Speedup button (gems) for buildings
	  var speedupBtn = '';
	  if (busy){
	    speedupBtn = '<button type="button" class="coc-speedup-btn" id="coc-bld-speedup" data-bspeedup="'+esc(b.id)+'">УСКОРИТЬ</button>';
	  }


  // Laboratory: show what is being researched right now
  if (String(b.id||'') === 'laboratory' && state.labState && state.labState.active && (parseInt(state.labState.active.time_left,10)||0) > 0){
    var a = state.labState.active;
    var anId = a.tech_id || a.tech_name || a.name || a.title || '';
    var an = ruUnlockName(anId);
    var afin = parseInt(a.finish_time,10) || 0;
    var aleft = Math.max(0, (parseInt(a.time_left,10)||0));
    blocks += '<div class="coc-bdetail-block" id="coc-lab-active-block"><div class="coc-bdetail-bt"> Идёт исследование</div>'+
      '<div style="margin-bottom:6px;">'+esc(String(an))+'</div>'+
      '<div class="coc-progress coc-progress-indeterminate" id="coc-lab-progress"><div></div></div>'+
      '<div style="margin-top:6px;font-weight:900;">Осталось: <span id="coc-lab-timeleft">'+esc(formatDurationSmart(aleft))+'</span></div>'+
    '</div>';
  }

  if (desc) blocks += '<div class="coc-bdetail-block"><div class="coc-bdetail-bt"> Описание</div><div>'+esc(desc)+'</div></div>';
  if (lockReason && !busy) blocks += '<div class="coc-bdetail-block"><div class="coc-bdetail-bt"> Ограничение</div><div>'+esc(lockReason)+'</div></div>';

  return ''+
    '<div class="coc-bdetail-head">'+esc(name)+'<button class="coc-bdetail-close" title="Закрыть">×</button></div>' +
    '<div class="coc-bdetail-body">' +
      '<div class="coc-bdetail-top">' +
        '<div class="coc-bdetail-img">'+imgWithFallback(buildingImg(b.id, (b.level||b.lvl||b.current_level||b.lv||0)), ["/images/building/barracks.png","/images/icons/trophy_icon.png"])+'</div>' +
        '<div class="coc-bdetail-info">' +
          '<div class="coc-bdetail-sub"> Постройка армии</div>' +
          '<div class="coc-bdetail-grid">'+tiles.join('')+'</div>' +
        '</div>' +
      '</div>' +
      blocks +
    '</div>' +
    '<div class="coc-bdetail-actions">' +
	      speedupBtn +
	      '<button type="button" class="coc-speedup-btn" id="coc-bld-act" '+btnDisabled+' data-bact="'+esc(b.id)+'">'+esc(btnLabel)+'</button>' +
      '<button type="button" class="coc-speedup-btn coc-btn-gray" id="coc-bdetail-back">НАЗАД</button>' +
    '</div>';
}

function renderBuildingDetail(){
  var data = state.buildingInfo;
  var b = data && data.building ? data.building : null;
  if (!b) return '';

  var lvl = parseInt(b.level, 10) || 0;
  var maxLvl = parseInt(b.max_level, 10) || 0;
  var cur = b.current || data.current || {};
  var next = b.next || data.next || null;

  var status = String(b.status || 'none');
  var busy = (status !== 'active' && status !== 'none');
  var finish = parseInt(b.finish_time, 10) || 0;
  var left = busy ? Math.max(0, finish - nowServer()) : 0;

  function pickFirst(obj, keys){
    for (var i=0;i<keys.length;i++){
      var k = keys[i];
      if (obj && typeof obj[k] !== 'undefined' && obj[k] !== null && obj[k] !== '') return obj[k];
    }
    return null;
  }
  function isScalar(v){
    return (v === null) || (typeof v === 'string') || (typeof v === 'number') || (typeof v === 'boolean');
  }
  function fmt(v){
    if (v === null || typeof v === 'undefined') return '—';
    if (typeof v === 'boolean') return v ? 'Да' : 'Нет';
    return String(v);
  }

  var name = String(b.name || b.id || 'Постройка');
  var desc = String(b.description || cur.description || '');

  var hp = pickFirst(cur, ['hp','health','hp_current','current_hp']);
  if (hp === null) hp = pickFirst(b, ['hp','health']);
  var hpMax = pickFirst(cur, ['max_hp','health_max','hp_max','maxHealth']);
  if (hpMax === null) hpMax = pickFirst(b, ['max_hp','health_max','hp_max']);

  var limitMax = pickFirst(b, ['limit','max_count','max','cap','max_buildings']);
  var limitHave = pickFirst(b, ['count','built','built_count','current_count','have']);
  var reqTh = pickFirst(next || b, ['th_req','townhall_req','required_th','th']);
  var reqBuilder = pickFirst(next || b, ['builder_req','builders_req','required_builder','builder']);

  function getCost(obj){
    if (!obj) return {amount:'—', icon:''};
    var amt = pickFirst(obj, ['cost','price','amount']);
    var rt = pickFirst(obj, ['res_type','resource','type']);
    if (amt === null){
      var ce = pickFirst(obj, ['cost_elixir','elixir']);
      var cg = pickFirst(obj, ['cost_gold','gold']);
      var cd = pickFirst(obj, ['cost_dark','dark_elixir']);
      if (ce !== null && String(ce) !== '0') { amt = ce; rt = 'elixir'; }
      else if (cg !== null && String(cg) !== '0') { amt = cg; rt = 'gold'; }
      else if (cd !== null && String(cd) !== '0') { amt = cd; rt = 'dark'; }
    }
    var icon = rt ? resIconAny(String(rt)) : '';
    return {amount: fmt(amt), icon: icon};
  }
  function getTime(obj){
    if (!obj) return '—';
    var t = pickFirst(obj, ['time','upgrade_time','build_time','duration']);
    var n = parseInt(t,10);
    if (!isNaN(n)) return formatTime(n);
    return fmt(t);
  }

  // Like in CoC: while a building is upgrading we still display the target level cost/time.
  // Backend may omit these fields on `building` during busy states, but keeps them in `next`.
  var cost = next ? getCost(next) : getCost(b);
  var timeText = next ? getTime(next) : getTime(b);

  var lockReason = b.locked_reason ? String(b.locked_reason) : '';
  var canBuild = !!b.can_build;
  var canUp = !!b.can_upgrade;
  var btnLabel = canBuild ? 'ПОСТРОИТЬ' : (canUp ? 'УЛУЧШИТЬ' : (busy ? 'ЗАНЯТО' : 'МАКС.'));
  var btnDisabled = (!canBuild && !canUp) ? 'disabled="disabled"' : '';
  if (busy) btnDisabled = 'disabled="disabled"';

  var aboutBlock =
    '<div class="coc-block">' +
      '<div class="coc-block-title"> Описание</div>' +
      '<div class="coc-detail-desc">'+(desc ? esc(desc) : '—')+'</div>' +
    '</div>';

  var kv = [];
  if (hp !== null || hpMax !== null) kv.push('<div class="coc-kv-item"><div class="coc-kv-k">❤ Здоровье</div><div class="coc-kv-v">'+esc(fmt(hp))+(hpMax!==null?(' / '+esc(fmt(hpMax))):'')+'</div></div>');
  if (limitMax !== null || limitHave !== null) kv.push('<div class="coc-kv-item"><div class="coc-kv-k"> Лимит</div><div class="coc-kv-v">'+esc(fmt(limitHave))+' / '+esc(fmt(limitMax))+'</div></div>');
  if (reqTh !== null && reqTh !== undefined && reqTh !== '' && reqTh !== 0 && reqTh !== '0') kv.push('<div class="coc-kv-item"><div class="coc-kv-k"> Требуется Ратуша</div><div class="coc-kv-v">'+esc(fmt(reqTh))+'</div></div>');
  if (reqBuilder !== null) kv.push('<div class="coc-kv-item"><div class="coc-kv-k"> Требуются строители</div><div class="coc-kv-v">'+esc(fmt(reqBuilder))+'</div></div>');
  kv.push('<div class="coc-kv-item"><div class="coc-kv-k"> Стоимость</div><div class="coc-kv-v">'+esc(cost.amount)+' '+(cost.icon?('<img src="'+esc(cost.icon)+'" alt="" style="width:16px;height:16px;vertical-align:-3px;">'):'')+'</div></div>');
  kv.push('<div class="coc-kv-item"><div class="coc-kv-k">⏱ Время</div><div class="coc-kv-v">'+esc(timeText)+'</div></div>');

  var infoBlock =
    '<div class="coc-block">' +
      '<div class="coc-block-title"> Информация</div>' +
      '<div class="coc-kv">'+kv.join('')+'</div>' +
    '</div>';

  var lockBlock = '';
  if (busy){
    lockBlock = '<div class="coc-block"><div class="coc-block-title">⏳ Статус</div><div class="coc-detail-desc">Идёт улучшение/стройка. Осталось: <b>'+esc(formatTime(left))+'</b></div></div>';
  } else if (lockReason && !busy){
    lockBlock = '<div class="coc-block"><div class="coc-block-title"> Недоступно</div><div class="coc-detail-desc">'+esc(lockReason)+'</div></div>';
  }

  var stats = [];
  function addStat(label, curV, nextV, emoji){
    if (!isScalar(curV) && !isScalar(nextV)) return;
    if ((curV === null || typeof curV === 'undefined' || curV === '') && (nextV === null || typeof nextV === 'undefined' || nextV === '')) return;
    stats.push(
      '<div class="coc-stat">' +
        '<div class="coc-stat-k">'+(emoji?('<span class="coc-emoji" aria-hidden="true">'+emoji+'</span>'):'')+esc(label)+'</div>' +
        '<div class="coc-stat-v">'+esc(fmt(curV))+'</div>' +
        '<div class="coc-stat-n">'+esc(fmt(nextV))+'</div>' +
      '</div>'
    );
  }
  var capCur = pickFirst(cur,['capacity','cap','storage','slots']);
  var capNext = next ? pickFirst(next,['capacity','cap','storage','slots']) : null;
  addStat('Вместимость', capCur, capNext, '');

  var statsBlock = stats.length
    ? ('<div class="coc-block"><div class="coc-block-title"> Характеристики</div>' +
        '<div class="coc-stats">' +
          '<div class="coc-stat coc-stat-head"><div class="coc-stat-k">Параметр</div><div class="coc-stat-v">Сейчас</div><div class="coc-stat-n">Далее</div></div>' +
          stats.join('') +
        '</div></div>')
    : '';

  return ''+
    '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-detail-row">' +
        '<div class="coc-detail-icon">' +
          imgWithFallback(buildingImg(b.id, (b.level||b.lvl||b.current_level||b.lv||0)), ["/images/building/barracks.png","/images/icons/trophy_icon.png"]) +
        '</div>' +
        '<div class="coc-detail-main">' +
          '<div class="coc-detail-title"> '+esc(name)+'</div>' +
          '<div class="coc-detail-sub">⭐ Уровень '+esc(String(lvl))+' / '+esc(String(maxLvl))+'</div>' +
          aboutBlock +
          infoBlock +
          lockBlock +
          statsBlock +
        '</div>' +
      '</div>' +
      '<div class="coc-detail-actions">' +
        '<button type="button" class="coc-speedup-btn" id="coc-bld-act" '+btnDisabled+' data-bact="'+esc(b.id)+'">'+esc(btnLabel)+'</button>' +
        '<button type="button" class="coc-speedup-btn coc-btn-gray" id="coc-bld-back">НАЗАД</button>' +
      '</div>' +
    '</div>';
}

  function renderUnitDetail(){
    // Inline unit detail is deprecated. We use the separate overlay modal (openUnitDetailModal).
    return '';

    var unit = u.unit;
    var name = String(unit.name||unit.id);
    var img = normalizeImgPath(unit.img || '');
    var kind = String(u.kind||'');

    var lvl = parseInt(u.level,10)||1;
    var maxLvl = parseInt(u.max_level,10)||1;
    var nextLvl = u.next_level ? parseInt(u.next_level,10) : 0;

    var lockedReason = u.locked_reason ? String(u.locked_reason) : '';
    var canUpgrade = !!u.can_upgrade;
    var upgrading = !!u.is_researching;

    function statRow(label, curVal, nextVal){
      var c = (curVal === null || typeof curVal === 'undefined') ? '—' : String(curVal);
      var n = (nextVal === null || typeof nextVal === 'undefined') ? '—' : String(nextVal);
      return '<div class="coc-stat"><div class="coc-stat-k">'+esc(label)+'</div><div class="coc-stat-v">'+esc(c)+'</div><div class="coc-stat-n">'+esc(n)+'</div></div>';
    }

    var rows = '';
    rows += statRow('Урон/сек', cur.dps, nxt.dps);
    rows += statRow('Место', u.housing_space, u.housing_space);
    rows += statRow(kind==='spell' ? 'Время варки' : 'Время тренировки', u.train_time, u.train_time);

    // cost/time for next level from lab
    var cost = (u.next_cost !== null && typeof u.next_cost !== 'undefined') ? String(u.next_cost) : '—';
    var res = u.next_res ? String(u.next_res) : '';
    var time = (u.next_time !== null && typeof u.next_time !== 'undefined') ? formatTime(parseInt(u.next_time,10)||0) : '—';

    var lockHtml = lockedReason ? '<div class="coc-detail-lock" style="margin-top:8px;"> '+esc(lockedReason)+'</div>' : '';

    var btnText = 'УЛУЧШИТЬ';
    if (upgrading) btnText = 'УЛУЧШАЕТСЯ';
    if (!nextLvl) btnText = 'МАКС.';

    var btnDisabled = (!canUpgrade || upgrading || !nextLvl) ? 'disabled="disabled"' : '';

    var html = '';
    html += '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-detail-row">' +
        '<div class="coc-detail-icon" style="width:160px;">' +
          (img ? imgWithFallback(img, [img]) : '') +
        '</div>' +
        '<div class="coc-detail-main">' +
          '<div class="coc-detail-title">'+esc(name)+' '+esc(String(lvl))+' ур.</div>' +
          '<div class="coc-detail-sub">Уровень '+esc(String(lvl))+' / '+esc(String(maxLvl))+(nextLvl?(' → '+esc(String(nextLvl))):'')+'</div>' +
          '<div class="coc-stats">' +
            '<div class="coc-stat-head"><div></div><div>Сейчас</div><div>Далее</div></div>' +
            rows +
          '</div>' +
          (nextLvl ? ('<div class="coc-detail-cost" style="margin-top:8px;">Стоимость: '+esc(cost)+' '+(res?('<img src="'+esc(resIcon(res))+'" alt="" style="width:14px;height:14px;vertical-align:-2px;">'):'')+'</div>') : '') +
          (nextLvl ? ('<div class="coc-detail-time">Время улучшения: '+esc(time)+'</div>') : '') +
          lockHtml +
        '</div>' +
      '</div>' +
      '<div class="coc-detail-actions">' +
        '<button type="button" class="coc-speedup-btn" id="coc-unit-upg" '+btnDisabled+' data-unitupg="'+esc(unit.id)+'">'+esc(btnText)+'</button>' +
        '<button type="button" class="coc-speedup-btn coc-btn-gray" id="coc-unit-close">ЗАКРЫТЬ</button>' +
      '</div>' +
    '</div>';

    return html;
  }

function renderBuildingsTab(){
    return renderBarracksSlots();
  }

  var cocBuildingsTabInterval = null;
var cocBuildingsTabRaf = 0;
function startBuildingsTabTimers(root){
  // Legacy hook.
  // Building timers are updated by the shared UI ticker (startUiTimerTicker)
  // using [data-btimer-end] nodes placed in the card actions.
  try{ startUiTimerTicker(); }catch(_e){}
  return;

  function updateAll(nowS){
    if (!timers || !timers.length) return;

    for (var i=0;i<timers.length;i++){
      var el = timers[i];
      if (!el || !el.getAttribute) continue;

      if (io && el.__cocBldInView === 0) continue;

      var fin = parseInt(el.getAttribute('data-end'),10) || 0;
      if (!fin || fin <= nowS){
        if (!el.classList.contains('is-hidden')){
          el.classList.add('is-hidden');
          el.textContent = '';
        }
        continue;
      }

      var left = fin - nowS;
      var txt = fmtLeft(left);

      if (el.textContent !== txt){
        el.textContent = txt;
        // cheap "pop" without RAF/reflow forcing
        try{
          el.classList.remove('is-pop');
          setTimeout((function(node){
            return function(){
              try{
                node.classList.add('is-pop');
                setTimeout(function(){ try{ node.classList.remove('is-pop'); }catch(_e){} }, 180);
              }catch(_e2){}
            };
          })(el), 0);
        }catch(_e){}
      }
      if (el.classList.contains('is-hidden')) el.classList.remove('is-hidden');
    }
  }

  // Expose a safe tick function (some codepaths may call it).
  // Never throws; if timers are not running yet, it's a no-op.
  window.__cocBuildingsTimer = {
    forceTick: function(){
      try{ lastNow = -1; }catch(_e){}
      try{ runOnce(); }catch(_e2){}
    }
  };
  window.cocBuildingsTimerTick = function cocBuildingsTimerTick(){
    try{ if (window.__cocBuildingsTimer && window.__cocBuildingsTimer.forceTick) window.__cocBuildingsTimer.forceTick(); }catch(_e){}
  };

  ensureObserver();
  scan();

  var lastNow = -1;
  var lastScanAt = 0;
  var done = false;

  function runOnce(){
    if (done) return;
    if (state && state.tab !== 'buildings'){ done = true; return; }
    var nowS = nowServer();
    if (nowS !== lastNow){
      lastNow = nowS;
      updateAll(nowS);
    }
    // periodic rescan for chunked rendering / late DOM inserts
    var t = Date.now();
    if (!lastScanAt || (t - lastScanAt) > 1300){
      lastScanAt = t;
      scan();
    }
  }

  // run immediately (fast open)
  runOnce();

  // low-frequency interval; real updates happen once per second via lastNow
  cocBuildingsTabInterval = setInterval(runOnce, 250);

  if (!document.__cocBldVisBound){
    document.__cocBldVisBound = true;
    document.addEventListener('visibilitychange', function(){
      try{
        if (!document.hidden && state && state.tab === 'buildings'){
          // restart (ensures interval exists after backgrounding)
          startBuildingsTabTimers(root);
        }
      }catch(_e){}
    });
  }
}
function render()
{
    ensureBuildingsStyles();
    var body = '';
    if (state.tab === 'buildings'){
      try{ console.log('[barracks] open buildings tab; touch=', isTouchDevice(), 'count=', (state.buildings&&state.buildings.length)||0); }catch(_e){}
      body = renderBuildingsTab();
    } else if (state.tab === 'train'){
      body = renderTrain();
    } else if (state.tab === 'army'){
      body = renderArmyTab();
    } else if (state.tab === 'spells'){
      body = renderSpellsTab();
    } else if (state.tab === 'heroes'){
      body = renderHeroesTab();
    }

    var html =
      '<div class="modal-header-controls">' +
        '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\''+MODAL_ID+'\')">' +
          '<img src="/images/icons/close.png" alt="Закрыть">' +
        '</button>' +
        '<div class="modal-title-bar"><h2 class="modal-title-text-inside-panel">КАЗАРМЫ</h2></div>' +
      '</div>' +
      '<div class="modal-body-custom">' +
        renderTabs() +
        body +
      '</div>';

    // tiny styles for unit cards/detail (scoped)

    var content = q(CONTENT_ID);
    if (!content) return;
    // preserve scroll positions inside modal
    // scheduleAutoRefresh() can call render() every second.
    // If a hero modal is open, re-rendering resets its internal scroll and feels like "auto-scroll up".
    var __prev = { bodyTop: 0, left: {}, heroModalTop: 0 };
    var __bodyOld = content.querySelector('.modal-body-custom');
    if (__bodyOld) __prev.bodyTop = __bodyOld.scrollTop || 0;

    var __heroModalOld = content.querySelector('#coc-hmodal .coc-hmodal');
    if (__heroModalOld) __prev.heroModalTop = __heroModalOld.scrollTop || 0;
    var __ids = ['coc-barracks-tabs','coc-army-strip','coc-pending-strip','coc-queue-strip','coc-spell-army-strip','coc-hero-strip','coc-spell-queue-strip','coc-spell-grid'];
    for (var __i=0; __i<__ids.length; __i++){
      var __elOld = content.querySelector('#'+__ids[__i]);
      if (__elOld) __prev.left[__ids[__i]] = __elOld.scrollLeft || 0;
    }
    // Preserve page scroll as well: full re-render can slightly change layout
    // and the browser may "anchor" scroll upward every second.
    var __winY = 0;
    try{ __winY = window.pageYOffset || document.documentElement.scrollTop || 0; }catch(_e){}
    content.innerHTML = html;
    // ensure image/background fallbacks and suppress <img> errors
    try{ bindImgFallback(content); }catch(_e){}
    try{ applyBgFallback(content); }catch(_e){}
    try{
      requestAnimationFrame(function(){
        try{ window.scrollTo(0, __winY); }catch(_e2){}
      });
    }catch(_e3){ }
    startUiTimerTicker();
    // mount hero modal portal (outside modal content) to avoid clipping/transform issues
    syncHeroPortal();
    // enable tick sound after first user gesture inside modal (Chrome policy)
    if (!content._tickAudioBound){
      content._tickAudioBound = true;
      var onceFn = function(){ enableTickSound(); };
      content.addEventListener('pointerdown', onceFn, { once:true });
      content.addEventListener('mousedown', onceFn, { once:true });
      content.addEventListener('touchstart', onceFn, { once:true });
    }

    startUiTimerTicker();
    // enable tick sound after first user gesture inside modal
    if (!content._tickAudioBound){
      content._tickAudioBound = true;
      content.addEventListener('pointerdown', function(){ enableTickSound(); }, { once:true });
      content.addEventListener('mousedown', function(){ enableTickSound(); }, { once:true });
      content.addEventListener('touchstart', function(){ enableTickSound(); }, { once:true });
    }
    var __bodyNew = content.querySelector('.modal-body-custom');
    if (__bodyNew) __bodyNew.scrollTop = __prev.bodyTop || 0;

    var __heroModalNew = content.querySelector('#coc-hmodal .coc-hmodal');
    if (__heroModalNew) __heroModalNew.scrollTop = __prev.heroModalTop || 0;
    for (var __k in __prev.left){
      if (!__prev.left.hasOwnProperty(__k)) continue;
      var __elNew = content.querySelector('#'+__k);
      if (__elNew) __elNew.scrollLeft = __prev.left[__k] || 0;
    }
    bindImgFallback(content);

    // timers on list cards
    
if (state.tab === 'buildings'){
      var __didChunk = false;
      try{ __didChunk = scheduleBuildingsChunkRender(); }catch(_e){ __didChunk = false; }
      // Late-load building thumbnails in small batches (prevents mobile hard-freeze).
      try{ scheduleBuildingsImgLoad(); }catch(_e2){}
      // Timers on list cards (single RAF; safe on mobile).
      if (!__didChunk) startBuildingsTabTimers(content);
    } else {
      if (cocBuildingsTabInterval){ clearInterval(cocBuildingsTabInterval); cocBuildingsTabInterval = null; }
      if (cocBuildingsTabRaf){ cancelAnimationFrame(cocBuildingsTabRaf); cocBuildingsTabRaf = 0; }
    }

    // Kick image loading for the active tab (prevents blank thumbnails after tab switches)
    try{ requestAnimationFrame(function(){ kickBarracksImages(content); }); }catch(_eKick){}

    // bind tabs
    var tabsEl = q('coc-barracks-tabs');
    if (tabsEl){
      enableDragScroll(tabsEl);
      tabsEl.addEventListener('click', function(e){
        var btn = closest(e.target, '[data-tab]');
        if (!btn) return;
        state.tab = btn.getAttribute('data-tab') || 'army';
        if (state.tab !== 'buildings') state.buildingInfo = null;
        if (state.tab !== 'heroes') state.heroInfo = null;
        render();
      });
    }

    
    // unit info + unit upgrade (troops/spells)
    if (!content._unitInfoBound){
      content._unitInfoBound = true;
      content.addEventListener('click', function(e){
        var ui = closest(e.target, '[data-unitinfo]');
        if (ui){
          var key = ui.getAttribute('data-unitinfo') || '';
          if (key.indexOf(':') > 0){
            var parts = key.split(':');
            var kind = parts[0];
            var id = parts.slice(1).join(':');
            openUnitInfo(kind, id);
          }
          e.preventDefault();
          return;
        }

        var uclose = closest(e.target, '#coc-unit-close');
        if (uclose){
          state.unitInfo = null;
          render();
          e.preventDefault();
          return;
        }

        var uupg = closest(e.target, '[data-unitupg]');
        if (uupg){
          var uid = uupg.getAttribute('data-unitupg') || '';
          if (!uid || !state.unitInfo || !state.unitInfo.unit || String(state.unitInfo.unit.id) !== String(uid)) return;
          if (!state.unitInfo.can_upgrade) return;
          // Prevent duplicate requests/toasts
          var k = 'lab_start:'+String(uid);
          var nowTs = Date.now();
          if (labStartInFlight[k]){ e.preventDefault(); return; }
          if (labStartLastClick[k] && (nowTs - labStartLastClick[k]) < 700){ e.preventDefault(); return; }
          labStartLastClick[k] = nowTs;
          labStartInFlight[k] = nowTs;
          uupg.disabled = true;
          apiPost('lab_start', { tech_id: uid }).then(function(resp){
            showBarracksToast('ok', 'Лаборатория', 'Улучшение начато.');
            // reload and refresh detail
            loadServerState(true).then(function(){
              return apiGetParams('unit_info', { unit_id: uid });
            }).then(function(info){
              try{ delete labStartInFlight[k]; }catch(_e0){}
              state.unitInfo = info;
              render();
            }).catch(function(){
              try{ delete labStartInFlight[k]; }catch(_e1){}
              state.unitInfo = null;
              render();
            });
          }).catch(function(err){
            try{ delete labStartInFlight[k]; }catch(_e2){}
            showBarracksToast('error', 'Лаборатория', (err && err.message) ? err.message : 'Ошибка');
            loadServerState(true).then(function(){
              // keep window open
              apiGetParams('unit_info', { unit_id: uid }).then(function(info){
                state.unitInfo = info;
                render();
              }).catch(function(){ render(); });
            });
          });
          e.preventDefault();
          return;
        }
      });
    }

// strips drag
    var s1 = q('coc-army-strip');
    var s2 = q('coc-pending-strip');
    var s3 = q('coc-queue-strip');
    if (s1) enableDragScroll(s1);
    if (s2) enableDragScroll(s2);
    if (s3) enableDragScroll(s3);
    var hs = q('coc-hero-strip');
    if (hs) enableDragScroll(hs);
    var hg = q('coc-heroes-grid');
    if (hg) enableDragScroll(hg);
    var ss1 = q('coc-spell-army-strip');
    if (ss1) enableDragScroll(ss1);
    var ss2 = q('coc-spell-queue-strip');
    if (ss2) enableDragScroll(ss2);

    // minus buttons (армия + очередь) — поддержка удержания 0.1с
    if (!content._minusHoldBound){
      content._minusHoldBound = true;
      (function(){
        var hold = { active:false, token:'', timer:0, fired:false, startAt:0, pointerId:null, queueLock:false, blocked:false };

        function stop(){
          var wasActive = hold.active;
          var hadQueueLock = hold.queueLock;
          hold.active = false;
          hold.token = '';
          hold.queueLock = false;
          hold.blocked = false;
          if (hold.timer){ clearInterval(hold.timer); hold.timer = 0; }
          // unlock queue updates and do one final sync after mass-cancel
          if (wasActive && hadQueueLock){
            // Keep lock until ALL queued POST requests are done (prevents queue "bounce back")
            var pq = (typeof apiPost !== 'undefined' && apiPost._q) ? apiPost._q : Promise.resolve();
            pq.then(function(){
              state._cancelLock = false;
              loadServerState(true);
            }).catch(function(){
              state._cancelLock = false;
              loadServerState(true);
            });
          }
        }

        function step(){
          if (!hold.active) return;
          if (hold.blocked) return;
          cancelQueueItem(hold.token, function(shouldBlock){
            if (shouldBlock){
              hold.blocked = true;
              stop();
            }
          });
          hold.fired = true;
        }

        function startHold(e, btn){
          // не конфликтовать с drag-scroll / кликами
          try{ e.preventDefault(); }catch(_e){}
          try{ e.stopPropagation(); }catch(_e2){}

          hold.active = true;
          hold.fired = false;
          hold.startAt = Date.now();
          hold.token = (btn && btn.getAttribute) ? (btn.getAttribute('data-qminus') || '') : '';
          hold.pointerId = (e && typeof e.pointerId !== 'undefined') ? e.pointerId : null;

          // lock server queue/pending updates during mass-cancel in training/spell queues
          hold.queueLock = false;
          if (hold.token){
            var t = String(hold.token);
            if (t.indexOf('mix:')===0 || t.indexOf('q:')===0 || t.indexOf('i:')===0 || t.indexOf('sq:')===0 || t.indexOf('sqt:')===0){
              hold.queueLock = true;
              state._cancelLock = true;
            }
          }

          // первый шаг сразу, затем каждые 0.1с пока держим
          if (hold.token){
            step();
          }
          if (hold.timer){ clearInterval(hold.timer); hold.timer = 0; }
          hold.timer = setInterval(step, 100);
        }

        content.addEventListener('pointerdown', function(e){
          var b = closest(e.target, '[data-qminus]');
          if (!b) return;
          startHold(e, b);
        }, { passive:false });

        function onUp(e){
          if (!hold.active) return;
          if (hold.pointerId !== null && e.pointerId !== hold.pointerId) return;
          stop();
        }

        content.addEventListener('pointerup', onUp, { passive:true });
        content.addEventListener('pointercancel', function(e){ stop(); }, { passive:true });
        content.addEventListener('pointerleave', function(e){ stop(); }, { passive:true });

        // Fallback: mousedown/mouseup (на случай, если pointer events не работают корректно)
        content.addEventListener('mousedown', function(e){
          // только левая кнопка
          if (typeof e.button !== 'undefined' && e.button !== 0) return;
          var b = closest(e.target, '[data-qminus]');
          if (!b) return;
          startHold(e, b);
        }, { passive:false });
        content.addEventListener('mouseup', function(e){ stop(); }, { passive:true });
        content.addEventListener('mouseleave', function(e){ stop(); }, { passive:true });

        // Touch fallback
        content.addEventListener('touchstart', function(e){
          var b = closest(e.target, '[data-qminus]');
          if (!b) return;
          startHold(e, b);
        }, { passive:false });
        content.addEventListener('touchend', function(e){ stop(); }, { passive:true });
        content.addEventListener('touchcancel', function(e){ stop(); }, { passive:true });
      })();
    }

    
    // spell speedup info (Stage 3.2)
    if (!content._spellMinusBound){
      content._spellMinusBound = true;
      content.addEventListener('click', function(e){
        var sb = closest(e.target, '[data-speedup]');
        if (sb && (sb.getAttribute('data-speedup') === 'spells')){
          apiPost('barracks_spell_speedup', { mode: 'all', quote: 1 }).then(function(qdata){
            var qcost = 0;
            if (qdata && typeof qdata.cost !== 'undefined') qcost = parseInt(qdata.cost,10)||0;
            var isQuote = !!(qdata && (qdata.quote||qdata.preview||qdata.only_cost||qdata.is_quote));
            // If backend does not support quote, it may have already applied speedup.
            if (!isQuote){
              // fallback: treat as applied response
              var cost = qcost;
              if (cost>0) showBarracksToast('ok', 'Казармы', 'Ускорено за ' + cost + ' ');
              loadServerState(true);
              return;
            }
            window.cocConfirm({ title: 'Казармы', text: 'Ускорить приготовление заклинаний (очередь)?', cost: qcost, costIconHtml: resIconImg('gems') }).then(function(ok){
              if (!ok) return;
              apiPost('barracks_spell_speedup', { mode: 'all' }).then(function(data){

            var cost = 0;
            if (data && typeof data.cost !== 'undefined') cost = parseInt(data.cost, 10) || 0;
            if (cost > 0){
              showBarracksToast('ok', 'Заклинания', 'Очередь ускорена за ' + cost + ' гемов.');
            } else {
              showBarracksToast('ok', 'Заклинания', 'Очередь ускорена.');
            }
            loadServerState(true);
                        });
            });
}).catch(function(err){
            showBarracksToast('error', 'Заклинания', (err && err.message) ? err.message : 'Ошибка ускорения');
            loadServerState(true);
          });
          e.preventDefault();
          return;
        }
      });
    }

    // heroes: open/details/unlock/upgrade
    if (!content._heroesBound){
      content._heroesBound = true;
      content.addEventListener('click', function(e){
        var hopen = closest(e.target, '[data-heroopen]');
        if (hopen){
          var hid = hopen.getAttribute('data-heroopen') || '';
          if (hid){
            var list = getHeroesList();
            var found = null;
            for (var i=0;i<list.length;i++){ if (String((list[i]||{}).id) === String(hid)) { found = list[i]; break; } }
            heroDbg('open hero popup', {hero_id: hid, hero: found});
            state.tab = 'heroes';
            state.buildingInfo = null;
            state.heroInfo = found;
            state.heroModalOpen = true;
            render();
          }
          e.preventDefault();
          return;
        }

        // Close hero modal only when clicking outside the window (overlay background)
        if (e && e.target && (e.target.id === 'coc-hmodal')){
          state.heroModalOpen = false;
          state.heroInfo = null;
          render();
          e.preventDefault();
          return;
        }

        var hclose = closest(e.target, '[data-heromodalclose]');
        if (hclose){
          // IMPORTANT: do not rely on inline onclick="event.stopPropagation()" (can be blocked by CSP).
          // If the click happened INSIDE the popup content, ignore the overlay close.
          var insidePopup = !!closest(e.target, '.coc-hmodal,.coc-um-modal');
          var explicitClose = false;
          try{ explicitClose = !!(e.target && e.target.getAttribute && e.target.getAttribute('data-heromodalclose')); }catch(_e){}

          if (insidePopup && !explicitClose){
            heroDbg('click inside popup -> ignore overlay close', e.target);
          } else {
            heroDbg('close hero popup', {insidePopup: insidePopup, explicitClose: explicitClose});
            state.heroModalOpen = false;
            state.heroInfo = null;
            render();
            e.preventDefault();
            return;
          }
        }

        var hgoto = closest(e.target, '[data-goto-hero-hall]');
        if (hgoto){
          // Jump to Buildings tab and open Hero Hall info
          state.heroModalOpen = false;
          state.heroInfo = null;
          state.tab = 'buildings';
          state.buildingInfo = null;
          render();
          // trigger open building info via existing handler
          setTimeout(function(){
            try{
              var btn = document.querySelector('#'+CONTENT_ID+' [data-bopen="hero_hall"]') || document.querySelector('[data-binfo="hero_hall"]');
              if (btn) btn.click();
            }catch(_e){}
          }, 30);
          e.preventDefault();
          return;
        }

        var hspeed = closest(e.target, '[data-herospeedup]');
        if (hspeed){
          var hidS = hspeed.getAttribute('data-herospeedup') || '';
          if (!hidS || !state.heroInfo || String(state.heroInfo.id) !== String(hidS)) return;
          hspeed.disabled = true;
          apiPost('hero_speedup', { hero_id: hidS }).then(function(resp){
            var spent = resp && typeof resp.cost_gems !== 'undefined' ? resp.cost_gems : (parseInt(state.heroInfo.speedup_cost,10)||0);
            showBarracksToast('ok', 'Герои', 'Ускорено за ' + formatNumber(parseInt(spent,10)||0) + ' ');
            loadServerState(true).then(function(){
              // keep hero detail
              var list = getHeroesList();
              for (var i=0;i<list.length;i++){ if (String((list[i]||{}).id) === String(hidS)) { state.heroInfo = list[i]; break; } }
              render();
            });
          }).catch(function(err){
            showBarracksToast('error', 'Герои', (err && err.message) ? err.message : 'Ошибка');
            try{ hact.disabled = false; }catch(_e){}
            try{ hact.classList.remove('is-busy'); }catch(_e2){}
            try{ delete heroActionInFlight[key]; }catch(_e3){}
            try{ delete heroActionInFlight[key]; }catch(_e3){}
            loadServerState(true).then(function(){ render(); });
          });
          e.preventDefault();
          return;
        }

        var hact = closest(e.target, '[data-heroact]');
        if (hact){
          var hid2 = hact.getAttribute('data-heroact') || '';
          if (!hid2 || !state.heroInfo || String(state.heroInfo.id) !== String(hid2)) return;
          heroDbg('hero action clicked', {hero_id: hid2, heroInfo: state.heroInfo});
          var unlocked = !!(state.heroInfo.unlocked && parseInt(state.heroInfo.unlocked,10)>0);
          var upgrading = !!state.heroInfo.upgrading;
          var canDo = unlocked ? !!state.heroInfo.can_upgrade : !!state.heroInfo.can_unlock;
          if (upgrading){
            heroDbg('blocked: upgrading');
            showBarracksToast('info', 'Герои', 'Этот герой сейчас улучшается.');
            e.preventDefault();
            return;
          }
          if (!canDo){
            var reason = '';
            if (!unlocked){
              reason = String(state.heroInfo.locked_reason||'');
              if (!reason){
                try{ var needTxt = heroUnlockReqInlineText(state.heroInfo); if (needTxt) reason = 'Требуется ' + needTxt; }catch(_e){}
              }
            }
            else {
              var cap = parseInt(state.heroInfo.cap,10)||0;
              var lvl = parseInt(state.heroInfo.level,10)||0;
              reason = (cap && lvl >= cap) ? ('Достигнут максимум ('+lvl+'). Улучшите Зал героев, чтобы поднять лимит.') : 'Улучшение недоступно.';
            }
            heroDbg('blocked: canDo=false', {unlocked: unlocked, reason: reason, heroInfo: state.heroInfo});
            showBarracksToast('info', 'Герои', reason || 'Недоступно');
            e.preventDefault();
            return;
          }
          var action = unlocked ? 'hero_upgrade' : 'hero_unlock';
          if (!unlocked){
            // show immediate feedback on click (even if request later fails)
            showBarracksToast('info', 'Герои', 'Разблокировка…');
          }
                    // Prevent duplicate requests on fast double handlers/clicks
          var key = action+':'+String(hid2);
          if (heroActionInFlight[key]){ heroDbg('skip duplicate apiPost', {key:key}); e.preventDefault(); return; }
          heroActionInFlight[key]=Date.now();
heroDbg('apiPost start', {action: action, hero_id: hid2});
          try{ hact.disabled = true; }catch(_e){}
          hact.classList.add('is-busy');
          apiPost(action, { hero_id: hid2 }).then(function(resp){
            heroDbg('apiPost ok', {action: action, hero_id: hid2, resp: resp});
            showBarracksToast('ok', 'Герои', unlocked ? 'Улучшение запущено.' : 'Герой разблокирован.');
            try{ hact.disabled = false; }catch(_e){}
            try{ hact.classList.remove('is-busy'); }catch(_e2){}
            // refresh state and keep detail open
            loadServerState(true).then(function(){
              // update selected hero object
              var list = getHeroesList();
              for (var i=0;i<list.length;i++){ if (String((list[i]||{}).id) === String(hid2)) { state.heroInfo = list[i]; break; } }
              render();
            });
          }).catch(function(err){
            heroDbg('apiPost error', {action: action, hero_id: hid2, err: err});
            showBarracksToast('error', 'Герои', (err && err.message) ? err.message : 'Ошибка');
            try{ hact.disabled = false; }catch(_e){}
            try{ hact.classList.remove('is-busy'); }catch(_e2){}
            loadServerState(true).then(function(){ render(); });
          });
          e.preventDefault();
          return;
        }
      });
    }

    // buildings: build/upgrade/info
    
function optimisticMarkBuildingBusy(bid, action, resp){
  bid = String(bid||'');
  if (!bid) return;
  var now = (typeof nowServer === 'function') ? nowServer() : Math.floor(Date.now()/1000);

  function apply(obj){
    if (!obj) return;
    var oid = String(obj.id || obj.building_id || obj.name || '');
    if (!oid || oid !== bid) return;

    var st = (action === 'building_build') ? 'building' : 'upgrading';
    obj.status = st;
    obj.start_time = now;

    // try to set finish_time from response
    var fin = 0;
    if (resp){
      if (resp.finish_time != null) fin = parseInt(resp.finish_time, 10) || 0;
      if (!fin && resp.building && resp.building.finish_time != null) fin = parseInt(resp.building.finish_time, 10) || 0;
    }
    if (fin) obj.finish_time = fin;

    // fallback: compute finish_time from duration
    if (!obj.finish_time){
      var dur = 0;
      if (resp && resp.time_left != null) dur = parseInt(resp.time_left, 10) || 0;
      if (!dur){
        var t = (obj.next_time != null) ? obj.next_time : (obj.next && obj.next.time != null ? obj.next.time : 0);
        dur = parseInt(t, 10) || 0;
      }
      if (dur > 0) obj.finish_time = now + dur;
    }

    if (action === 'building_upgrade'){
      var lvl = parseInt(obj.level, 10) || 0;
      obj.target_level = lvl + 1;
    }
    if (action === 'building_build'){
      obj.is_built = 1;
      if (obj.level == null || parseInt(obj.level,10) <= 0) obj.level = 1;
    }
  }

  try{
    if (state && Array.isArray(state.buildings)){
      for (var i=0;i<state.buildings.length;i++) apply(state.buildings[i]);
    }
    if (state && state.barracksData && Array.isArray(state.barracksData.buildings)){
      for (var j=0;j<state.barracksData.buildings.length;j++) apply(state.barracksData.buildings[j]);
    }
    if (state && state.buildingInfo && state.buildingInfo.building) apply(state.buildingInfo.building);
  }catch(e){}
}

function refreshBuildingInfoWithRetry(bid, tries, delay){
  bid = String(bid||'');
  tries = (tries == null) ? 3 : tries;
  delay = (delay == null) ? 250 : delay;

  return new Promise(function(resolve){
    function attempt(n, d){
      setTimeout(function(){
        apiGetParams('building_info', { building_id: bid }).then(function(info){
          state.buildingInfo = info;
          syncBuildingFromInfo(info);
          render();
          refreshOpenBuildingDetailModal();
          scheduleAutoRefresh();

          var b = info && info.building ? info.building : null;
          var st = b ? String(b.status || 'none') : 'none';
          var fin = b ? (parseInt(b.finish_time,10)||0) : 0;
          var busy = (st !== 'active' && st !== 'none') && !!fin;

          if (busy || n <= 0){
            resolve(info);
          } else {
            attempt(n-1, Math.min(1200, d*2));
          }
        }).catch(function(){
          if (n <= 0){ resolve(null); }
          else attempt(n-1, Math.min(1200, d*2));
        });
      }, d);
    }
    attempt(tries, delay);
  });
}

var buildPanel = content.querySelector('.coc-building-panel');
    if (buildPanel){
      if (!buildPanel.dataset.boundClick){ buildPanel.dataset.boundClick='1';
        buildPanel.addEventListener('click', function(e){
        var bmore = closest(e.target, '[data-bmore]');
        if (bmore){
          // Increase the target count and continue chunk rendering.
          __bldTargetCount = (__bldTargetCount || __bldInitialCap) + __bldMoreStep;
          if (state.buildings && state.buildings.length && __bldTargetCount > state.buildings.length) __bldTargetCount = state.buildings.length;

          // Remove existing load-more control to avoid duplicates.
          try{
            var wrap = closest(bmore, '.coc-bmore-wrap');
            if (wrap && wrap.parentNode) wrap.parentNode.removeChild(wrap);
          }catch(_e0){}

          // Continue rendering from the current index.
          try{
            if (__bldChunkCtx && __bldChunkCtx.host && __bldChunkCtx.token === __bldChunkToken){
              requestAnimationFrame(function(){
                try{ scheduleBuildingsChunkRender(); }catch(_e1){}
              });
            } else {
              scheduleBuildingsChunkRender();
            }
          }catch(_e2){}
          e.preventDefault();
          return;
        }

        var bid = '';
        var binfo = closest(e.target, '[data-binfo]');
        var bopen = closest(e.target, '[data-bopen]');
        if (binfo || bopen){
          bid = (binfo ? binfo.getAttribute('data-binfo') : bopen.getAttribute('data-bopen')) || '';
          if (bid){
            var _p = apiGetParams('building_info', { building_id: bid }).catch(function(err){
              // fallback: some backends expect different param names
              return apiGetParams('building_info', { id: bid }).catch(function(){
                return apiGetParams('building_info', { building: bid });
              });
            });
            _p.then(function(data){
              state.buildingInfo = data;
              // For Laboratory show current research too
              var bid2 = (data && data.building && data.building.id) ? String(data.building.id) : String(bid||'');
              if (bid2 === 'laboratory'){
                return apiGetParams('lab_state', {}).then(function(ls){ state.labState = ls; return data; }).catch(function(){ return data; });
              }
              return data;
            }).then(function(){
              openBuildingDetailModal();
              // keep list visible behind modal
              render();
            }).catch(function(err){
              showBarracksToast('error', (err && err.message) ? err.message : 'Ошибка');
            });
          }
          e.preventDefault();
          return;
        }

        var b1 = closest(e.target, '[data-bbuild]');
        if (b1){
          bid = b1.getAttribute('data-bbuild') || '';
          apiPost('building_build', { building_id: bid }).then(function(resp){
            showBarracksToast('ok', 'Постройка начата.');
            optimisticMarkBuildingBusy(bid, 'building_build', resp);
            render();
            startBuildingsTabTimers();
            refreshBuildingInfoWithRetry(bid, 4, 180).catch(function(){});
            apiGetParams('building_info', { building_id: bid }).then(function(info){
              state.buildingInfo = info;
              syncBuildingFromInfo(info);
              render();
              scheduleAutoRefresh();
            }).catch(function(){});
            loadServerState(true).catch(function(){});
          }).catch(function(err){
            showBarracksToast('error', (err && err.message) ? err.message : 'Ошибка');
          });
          return;
        }
        var b2 = closest(e.target, '[data-bup]');
        if (b2){
          bid = b2.getAttribute('data-bup') || '';
          apiPost('building_upgrade', { building_id: bid }).then(function(resp){
            showBarracksToast('ok', 'Улучшение запущено.');
            optimisticMarkBuildingBusy(bid, 'building_upgrade', resp);
            render();
            startBuildingsTabTimers();
            refreshBuildingInfoWithRetry(bid, 4, 180).catch(function(){});
            apiGetParams('building_info', { building_id: bid }).then(function(info){
              state.buildingInfo = info;
              syncBuildingFromInfo(info);
              render();
              scheduleAutoRefresh();
            }).catch(function(){});
            loadServerState(true).catch(function(){});
          }).catch(function(err){
            showBarracksToast('error', (err && err.message) ? err.message : 'Ошибка');
          });
          return;
        }
      });
      }
    }

    var backB = q('coc-bld-back');
    if (backB){
      backB.addEventListener('click', function(){
        state.buildingInfo = null;
        render();
      });
    }

    var actB = q('coc-bld-act');
    if (actB){
      if (!actB.dataset.boundClick){
        actB.dataset.boundClick='1';
        actB.addEventListener('click', function(){
        var bid2 = actB.getAttribute('data-bact') || '';
        if (!bid2 || !state.buildingInfo || !state.buildingInfo.building) return;
        var b = state.buildingInfo.building;
        var action = b.can_build ? 'building_build' : (b.can_upgrade ? 'building_upgrade' : '');
        if (!action) return;
        actB.disabled = true;
        apiPost(action, { building_id: bid2 }).then(function(resp){
          showBarracksToast('ok', (action === 'building_build') ? 'Постройка начата.' : 'Улучшение запущено.');
          optimisticMarkBuildingBusy(bid2, action, resp);
          render();
          refreshOpenBuildingDetailModal();
          startBuildingsTabTimers();
          refreshBuildingInfoWithRetry(bid2, 4, 180).catch(function(){});
          apiGetParams('building_info', { building_id: bid2 }).then(function(info){
            state.buildingInfo = info;
            syncBuildingFromInfo(info);
            render();
            scheduleAutoRefresh();
          }).catch(function(){});
          loadServerState(true).catch(function(){});
        }).catch(function(err){
          showBarracksToast('error', (err && err.message) ? err.message : 'Ошибка');
          loadServerState(true).then(function(){
            apiGetParams('building_info', { building_id: bid2 }).then(function(info){
              state.buildingInfo = info;
              render();
              scheduleAutoRefresh();
            }).catch(function(){ state.buildingInfo = null; render(); });
          });
        });
      });
      }
    }

    // speedup
    var speed = q('coc-speedup');
    if (speed){
      speed.addEventListener('click', function(){
        apiPost('barracks_speedup', { mode: 'all', quote: 1 }).then(function(qdata){
            var qcost = 0;
            if (qdata && typeof qdata.cost !== 'undefined') qcost = parseInt(qdata.cost,10)||0;
            var isQuote = !!(qdata && (qdata.quote||qdata.preview||qdata.only_cost||qdata.is_quote));
            // If backend does not support quote, it may have already applied speedup.
            if (!isQuote){
              // fallback: treat as applied response
              var cost = qcost;
              if (cost>0) showBarracksToast('ok', 'Казармы', 'Ускорено за ' + cost + ' ');
              loadServerState(true);
              return;
            }
            window.cocConfirm({ title: 'Казармы', text: 'Ускорить обучение войск (очередь)?', cost: qcost, costIconHtml: resIconImg('gems') }).then(function(ok){
              if (!ok) return;
              apiPost('barracks_speedup', { mode: 'all' }).then(function(data){

          var cost = 0;
          if (data && typeof data.cost !== 'undefined') cost = parseInt(data.cost, 10) || 0;
          if (cost > 0){
            showBarracksToast('ok', 'Казармы', 'Очередь ускорена за ' + cost + ' гемов.');
          } else {
            showBarracksToast('ok', 'Казармы', 'Очередь ускорена.');
          }
          loadServerState(true);
                      });
            });
}).catch(function(err){
          showBarracksToast('error', 'Казармы', (err && err.message) ? err.message : 'Ошибка ускорения');
        });
      });
    }

    // troop grid (tap/hold add)
    var grid = q('coc-troop-grid');
    if (grid && !grid.__cocBound){
      grid.__cocBound = true;

      // Prevent browser context menu / image save sheet on long-press (mobile) and right-click.
      grid.addEventListener('contextmenu', function(e){ e.preventDefault(); }, {passive:false});
      grid.addEventListener('dragstart', function(e){ e.preventDefault(); }, {passive:false});

      // Global release safety (prevents stuck-hold on PC/mobile when pointerup happens outside grid)
      window.addEventListener('pointerup', function(){ try{ stopHold(); }catch(e){} }, true);
      window.addEventListener('pointercancel', function(){ try{ stopHold(); }catch(e){} }, true);
      window.addEventListener('mouseup', function(){ try{ stopHold(); }catch(e){} }, true);
      window.addEventListener('touchend', function(){ try{ stopHold(); }catch(e){} }, true);
      window.addEventListener('blur', function(){ try{ stopHold(); }catch(e){} }, true);

      var hold = { timer:null, id:null, did:false, tile:null, badge:null, added:0 };

      function ensureHoldBadge(tile){
        if (!tile) return null;
        var b = tile.querySelector('.coc-holdbadge');
        if (!b){
          b = document.createElement('div');
          b.className = 'coc-holdbadge is-hidden';
          tile.appendChild(b);
        }
        return b;
      }

      function setHoldBadge(tile, val){
        var b = ensureHoldBadge(tile);
        if (!b) return;
        if (!val || val<=0){
          b.classList.add('is-hidden');
          b.textContent = '';
          return;
        }
        b.textContent = '+' + String(val);
        b.classList.remove('is-hidden');
        // micro pop animation
        b.classList.remove('is-pop');
        // force reflow
        void b.offsetWidth;
        b.classList.add('is-pop');
      }


      function addTroop(id2){
        if (!id2) return;
        var d = defById(id2) || {};
        var space = parseInt(d.space, 10) || 1;

        // Real-time camp limit: prevent selecting more than can fit.
        var free = calcFreeCapacity();
        if (state.capMax && free < space){
          showBarracksToast('error', 'Лагерь', 'Недостаточно места');
          if (hold && hold.timer){ stopHold(); }
          return;
        }

        // Optimistic UI: reflect immediately in queue preview
        if (!state.optTrain) state.optTrain = {};
        state.optTrain[id2] = (parseInt(state.optTrain[id2],10)||0) + 1;

        // Classic CoC: unit goes into training queue (buffered POST)
        bufferTrainUnit(id2, 1);

        // Update UI instantly (badge/queue)
        render();
      }

      function stopHold(){
        if (hold.timer){ clearInterval(hold.timer); hold.timer = null; }
        if (hold._repeatT){ clearTimeout(hold._repeatT); hold._repeatT = null; }
        if (hold._mouseT){ clearTimeout(hold._mouseT); hold._mouseT = null; }
        hold.id = null;
        hold.tile = null;
        hold.added = 0;
        // hide badge & reset suppression flag
        if (hold.badge){
          hold.badge.classList.add('is-hidden');
          hold.badge.textContent = '';
        }
        hold.badge = null;
        hold.did = false;
        // flush training requests immediately on release
        flushTrainBuffer();
      }

      // One press = +1 immediately. If still held, repeat every 0.1s (after a short hold threshold).
      function startHold(id2, tile){
        stopHold();
        hold.id = id2;
        hold.tile = tile || null;
        hold.badge = tile ? ensureHoldBadge(tile) : null;
        hold.added = 0;
        hold.did = true;

        // Add one immediately on press
        addTroop(id2);
        hold.added += 1;
        if (hold.tile) setHoldBadge(hold.tile, hold.added);

        // Start repeating only if user is really holding (prevents accidental 2x on обычном клике)
        hold._repeatT = setTimeout(function(){
          hold._repeatT = null;
          if (!hold.id) return;
          hold.timer = setInterval(function(){
            if (!hold.id) return;
            var before = hold.added;
            addTroop(id2);
            // if addTroop stopped hold due to full cap, do not increment
            if (!hold.timer) return;
            hold.added = before + 1;
            if (hold.tile) setHoldBadge(hold.tile, hold.added);
          }, 100);
        }, 300);
      }

      function isTouchLike(e){
        return !!(e && (e.pointerType === 'touch' || e.pointerType === 'pen' || (e.touches && e.touches.length)));
      }

      // click: info only (adding is handled by pointerdown so we don't double-send)
      grid.addEventListener('click', function(e){
        if (hold.did){ hold.did = false; e.preventDefault(); e.stopPropagation(); return; }
        if (hold._mouseT){ clearTimeout(hold._mouseT); hold._mouseT = null; }

        var ui = closest(e.target, '[data-unitinfo]');
        if (ui){
          var key = ui.getAttribute('data-unitinfo') || '';
          var parts = key.split(':');
          openUnitInfo(parts[0], parts.slice(1).join(':'));
          e.preventDefault();
          return;
        }
        // troop add is handled by pointerdown/hold
        var tile2 = closest(e.target, '[data-troop]');
        if (tile2) e.preventDefault();
      });

      // hold: continuous add (touch/pen/mouse) — одинаково на телефоне и ПК
      grid.addEventListener('pointerdown', function(e){
        var tile2 = closest(e.target, '[data-troop]');
        if (!tile2) return;
        if (closest(e.target, '.coc-info')) return;
        if (tile2.classList.contains('is-disabled')) return;
        var id2 = tile2.getAttribute('data-troop');
        if (!id2) return;

        // Prevent selection/callout and start training immediately.
        e.preventDefault();
        try{ tile2.setPointerCapture && tile2.setPointerCapture(e.pointerId); }catch(_e){}
        startHold(id2, tile2);
      });

      grid.addEventListener('pointerup', function(){ stopHold(); });
      grid.addEventListener('pointercancel', function(){ stopHold(); });
      grid.addEventListener('mouseleave', function(){ stopHold(); });
    }

    // spell grid (tap/hold add) — переписано с нуля: tap=+1, hold=repeat, без двойных обработчиков
var sgrid = q('coc-spell-grid');
if (sgrid && !sgrid.__cocBound){
  sgrid.__cocBound = true;

  // Prevent browser context menu / callout on long-press & right-click, but do NOT block our hold logic.
  sgrid.addEventListener('contextmenu', function(e){ e.preventDefault(); }, {passive:false});
  sgrid.addEventListener('dragstart', function(e){ e.preventDefault(); }, {passive:false});

  var shold = { id:null, tile:null, timer:null, repeatT:null, did:false, blocked:false, pointerId:null };

  function stopHoldS(){
    if (shold.timer){ clearInterval(shold.timer); shold.timer = null; }
    if (shold.repeatT){ clearTimeout(shold.repeatT); shold.repeatT = null; }
    shold.id = null;
    shold.tile = null;
    shold.pointerId = null;
    shold.blocked = false;
    // flush buffered requests on release
    flushSpellBuffer();
  }

  function addSpellOnce(id2){
    if (!id2) return false;
    if (shold.blocked) return false;

    var def = spellById(id2);
    if (def && (def.locked || !def.owned)) return false;

    var space = def ? (parseInt(def.space,10)||1) : 1;
    if ((state.spellUsed + space) > state.spellCap){
      // stop repeating and block until release
      shold.blocked = true;
      // уведомление отключено: недостаточно места для заклинаний
      stopHoldS();
      return false;
    }

    // optimistic: add into brew queue (not instantly into composition)
    state.spellQueue = state.spellQueue || [];
    state.spellQueue.push({ id: id2, qty: 1, qid: 0, finish_time: 0, time: 0 });
    state.spellUsed += space;
    render();

    bufferSpellAdd(id2, 1);
    return true;
  }

  // One press = +1 immediately. If still held, repeat after 300ms.
  function startHoldS(id2, tile, e){
    stopHoldS();
    shold.id = id2;
    shold.tile = tile || null;
    shold.did = true;
    shold.pointerId = (e && typeof e.pointerId !== 'undefined') ? e.pointerId : null;

    // Add one immediately on press (like troops)
    addSpellOnce(id2);

    // Repeat only after hold threshold (prevents "tap -> max")
    shold.repeatT = setTimeout(function(){
      shold.repeatT = null;
      if (!shold.id) return;
      shold.timer = setInterval(function(){
        if (!shold.id) return;
        addSpellOnce(id2);
      }, 120);
    }, 300);
  }

  // CAPTURE: kill any legacy onclick handlers on spell tiles to prevent "fill to max" on PC.
  sgrid.addEventListener('click', function(e){
    var ui = closest(e.target, '[data-unitinfo]');
    if (ui){
      var key = ui.getAttribute('data-unitinfo') || '';
      var parts = key.split(':');
      openUnitInfo(parts[0], parts.slice(1).join(':'));
      e.preventDefault();
      e.stopPropagation();
      try{ e.stopImmediatePropagation(); }catch(_e){}
      return;
    }

    var tile2 = closest(e.target, '[data-spell]');
    if (tile2){
      // Adding handled by pointerdown; swallow click to avoid double add / legacy handlers.
      e.preventDefault();
      e.stopPropagation();
      try{ e.stopImmediatePropagation(); }catch(_e2){}
      return;
    }
  }, true);

  // Pointer press: tap or hold (works одинаково на телефоне и ПК)
  sgrid.addEventListener('pointerdown', function(e){
    var tile2 = closest(e.target, '[data-spell]');
    if (!tile2) return;
    if (closest(e.target, '.coc-info')) return;
    if (tile2.classList.contains('is-disabled')) return;

    var id2 = tile2.getAttribute('data-spell');
    if (!id2) return;

    e.preventDefault();
    e.stopPropagation();
    try{ e.stopImmediatePropagation(); }catch(_e3){}
    try{ tile2.setPointerCapture && tile2.setPointerCapture(e.pointerId); }catch(_e4){}

    startHoldS(id2, tile2, e);
  }, {passive:false});

  function onUp(e){
    if (!shold.id) return;
    if (shold.pointerId !== null && typeof e.pointerId !== 'undefined' && e.pointerId !== shold.pointerId) return;
    stopHoldS();
  }

  sgrid.addEventListener('pointerup', onUp, {passive:true});
  sgrid.addEventListener('pointercancel', onUp, {passive:true});
  sgrid.addEventListener('mouseleave', function(){ stopHoldS(); }, {passive:true});

  // Safety: release outside of grid
  document.addEventListener('pointerup', onUp, true);
  document.addEventListener('pointercancel', onUp, true);
}

  }


// -------------------- HERO DEBUG CAPTURE + STYLE SAFETY NET --------------------
// Some browsers / CSP settings can block inline onclick handlers and cause clicks to bubble to overlays,
// closing the hero popup without triggering our delegation. To make hero actions reliable, we
// intercept clicks on hero action buttons in CAPTURE phase and run the action handler ourselves.
function ensureHeroStyles(){
  try{
    if (document.getElementById('coc-heroes-style')) return;
    var css = ''
      + '.coc-hero-overlay{position:fixed;top:0;left:0;right:0;bottom:0;z-index:200000;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.55);}'
      + '.coc-hero-overlay.is-open{display:flex;}'
      + '.coc-hmodal{width:520px;max-width:92vw;max-height:86vh;overflow:auto;border-radius:14px;background:#f4efe4;border:3px solid #b59b6d;box-shadow:0 18px 40px rgba(0,0,0,.45);}'
      + '.coc-hmodal .coc-hmodal-head{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:2px solid rgba(0,0,0,.12);background:linear-gradient(#efe3c9,#e2cfaa);}'
      + '.coc-hmodal .coc-hmodal-title{font-weight:800;letter-spacing:.5px;color:#3c2a18;text-transform:uppercase;}'
      + '.coc-hmodal .coc-hmodal-close{width:34px;height:34px;border-radius:10px;border:2px solid rgba(0,0,0,.18);background:#f7f1e5;cursor:pointer;font-weight:900;}'
      + '.coc-hmodal .coc-hmodal-body{padding:12px 12px 14px;}'
      + '.coc-hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px;}'
      + '.coc-hero-btn{padding:10px 14px;border-radius:12px;border:2px solid rgba(0,0,0,.18);background:#f7f1e5;font-weight:800;cursor:pointer;}'
      + '.coc-hero-btn-primary{background:linear-gradient(#60d25f,#2f9f32);border-color:#1f7d24;color:#0b2b0b;text-shadow:0 1px 0 rgba(255,255,255,.35);}'
      + '.coc-hero-btn.is-disabled{opacity:.55;cursor:not-allowed;filter:grayscale(.2);}'
      + '.coc-hero-btn.is-busy{opacity:.75;cursor:progress;}'
      + '[data-herotimer-end].is-hidden{display:none!important;}';
    var st = document.createElement('style');
    st.id = 'coc-heroes-style';
    st.type = 'text/css';
    st.appendChild(document.createTextNode(css));
    document.head.appendChild(st);
  }catch(_e){}
}

function ensureHeroInfoSelected(hid){
  if (state.heroInfo && String(state.heroInfo.id) === String(hid)) return state.heroInfo;
  var list = getHeroesList();
  for (var i=0;i<list.length;i++){
    if (String((list[i]||{}).id) === String(hid)) { state.heroInfo = list[i]; return state.heroInfo; }
  }
  return null;
}

function doHeroAction(hid){
  try{ ensureToastOnTop(); }catch(_e){}
  ensureHeroStyles();
  var h = ensureHeroInfoSelected(hid);
  heroDbg('doHeroAction()', {hero_id: hid, heroInfo: h});
  if (!h){ showBarracksToast('error','Герои','Не удалось найти героя.'); return; }

  var unlocked = !!(h.unlocked && parseInt(h.unlocked,10)>0);
  var upgrading = !!h.upgrading;
  var canDo = unlocked ? !!h.can_upgrade : !!h.can_unlock;

  if (upgrading){
    heroDbg('blocked: upgrading');
    showBarracksToast('info','Герои','Этот герой сейчас улучшается.');
    return;
  }
  if (!canDo){
    var reason = String(h.locked_reason||'').trim();
    // If backend didn't provide a reason, build a helpful one from requirements (especially for unlock).
    if (!reason && !unlocked){
      try{
        var needTxt = heroUnlockReqInlineText(h);
        if (needTxt) reason = 'Требуется ' + needTxt.replace(/^Требуется\s*:?\s*/i,'');
      }catch(_e){}
    }
    if (!reason) reason = (unlocked ? 'Улучшение недоступно.' : 'Разблокировка недоступна.');
    heroDbg('blocked: canDo=false', {reason: reason, heroInfo: h});
    showBarracksToast('info','Герои', reason);
    return;
  }

  var action = unlocked ? 'hero_upgrade' : 'hero_unlock';
  // Prevent duplicate requests (double handlers)
  var key = action+':'+String(hid);
  if (heroActionInFlight[key]){ heroDbg('skip duplicate apiPost', {key:key}); return; }
  heroActionInFlight[key]=Date.now();

  heroDbg('apiPost start', {action: action, hero_id: hid});
  apiPost(action, { hero_id: hid }).then(function(resp){
    heroDbg('apiPost ok', {action: action, resp: resp});
    showBarracksToast('ok','Герои', unlocked ? 'Улучшение запущено.' : 'Герой разблокирован.');
    try{ delete heroActionInFlight[key]; }catch(_e){}
    return loadServerState(true);
  }).then(function(){
    ensureHeroInfoSelected(hid);
    try{
      var hh=null;
      for (var i=0;i<state.heroes.length;i++){ if (String(state.heroes[i].hero_id)===String(hid)){ hh=state.heroes[i]; break; } }
      heroDbg('after loadServerState hero state', hh||{missing:true, hero_id:hid});
    }catch(_e){}
    // keep popup open
    state.tab = 'heroes';
    state.heroModalOpen = true;
    render();
  }).catch(function(err){
    heroDbg('apiPost error', {action: action, error: String(err||'')});
    showBarracksToast('error','Герои', (err && err.message) ? err.message : 'Ошибка');
  });
}

function doHeroSpeedup(hid){
  ensureHeroStyles();
  var h = ensureHeroInfoSelected(hid);
  heroDbg('doHeroSpeedup()', {hero_id: hid, heroInfo: h});
  if (!h){ showBarracksToast('error','Герои','Не удалось найти героя.'); return; }
  if (!h.upgrading){
    showBarracksToast('info','Герои','Сейчас нечего ускорять.');
    return;
  }

  // Confirmation with cost
  var cost = parseInt(h.speedup_cost,10)||0;
  var heroName = String(h.name||h.id||'героя');
  // CoC-like confirm (no browser confirm/alert)
  try{ ensureCoCConfirmUI(); }catch(_e){}
  window.cocConfirm({
    title: 'Ускорить улучшение?',
    text: 'Ускорить улучшение "'+heroName+'"?',
    cost: cost,
    costIconHtml: resIconImg('gems'),
    okText: 'УСКОРИТЬ',
    cancelText: 'ОТМЕНА'
  }).then(function(ok){
    if (!ok) return;

    heroDbg('apiPost start', {action: 'hero_speedup', hero_id: hid});
    apiPost('hero_speedup', { hero_id: hid }).then(function(resp){
      heroDbg('apiPost ok', {action: 'hero_speedup', resp: resp});
      showBarracksToast('ok','Герои','Ускорение применено.');
      return loadServerState(true);
    }).then(function(){
      ensureHeroInfoSelected(hid);
      state.tab = 'heroes';
      state.heroModalOpen = true;
      render();
    }).catch(function(err){
      heroDbg('apiPost error', {action: 'hero_speedup', error: String(err||'')});
      showBarracksToast('error','Герои', (err && err.message) ? err.message : 'Ошибка');
    });
  });
}


// Expose hero actions for inline onclick safety (portal modals)
window.__barracksHeroAct = function(heroId){ try{ doHeroAction(heroId); }catch(e){ try{ console.error(e);}catch(_){ } } };
window.__barracksHeroSpeedup = function(heroId){ try{ doHeroSpeedup(heroId); }catch(e){ try{ console.error(e);}catch(_){ } } };

// NOTE: hero clicks are handled inside installHeroPortalHandlers() (capture phase).
// We intentionally do NOT bind a second global capture handler here to avoid double requests.

  function open(){
    var modal = q(MODAL_ID);
    var content = q(CONTENT_ID);
    if (!modal || !content) return;
    modal.classList.add('active');
    render();
    startTick();
    loadServerState(false);
  }

  window.showBarracksModal = open;

})();
console.log('[barracks] locations/barracks.js loaded v13, lines='+ (new Error().stack.split('\n').length));
