(function(){
  'use strict';

  var MODAL_ID = 'lab-modal';
  var CONTENT_ID = 'lab-modal-content';
  var API_URL = '/app/army_api.php';

  // ---------------- DOM helpers ----------------
  function q(id){ return document.getElementById(id); }

  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(m){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
    });
  }

  function imgWithFallback(primary, fallbacks){
    fallbacks = fallbacks || [];
    var fb = fallbacks.length ? ' data-fallback="'+esc(fallbacks.join('|'))+'"' : '';
    return '<img src="'+esc(primary)+'"'+fb+' alt="">';
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
    }catch(_e){}

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
      suppressClick = false;
    }

    function onMove(x, y){
      if (!isDown) return;
      var dx = x - startX;
      var dy = y - startY;

      // Decide axis after small threshold
      if (!axis){
        if (Math.abs(dx) > 6) axis = 'h';
        else if (Math.abs(dy) > 8) axis = 'v';
      }

      if (axis === 'h'){
        dragging = true;
        el.scrollLeft = startScroll - dx;
        // Suppress click only if user actually dragged
        if (Math.abs(dx) > 8) suppressClick = true;
      }
    }

    function onUp(){
      isDown = false;
      axis = null;
      dragging = false;
    }

    // Mouse
    el.addEventListener('mousedown', function(e){
      // only left button
      if (e.button !== 0) return;
      onDown(e.pageX, e.pageY, 'mouse');
    });

    document.addEventListener('mousemove', function(e){
      if (!isDown) return;
      onMove(e.pageX, e.pageY);
      if (dragging && e.cancelable) e.preventDefault();
    });

    document.addEventListener('mouseup', function(){
      if (!isDown) return;
      onUp();
    });

    // Touch
    el.addEventListener('touchstart', function(e){
      if (!e.touches || !e.touches.length) return;
      var t = e.touches[0];
      onDown(t.pageX, t.pageY, 'touch');
    }, {passive:true});

    el.addEventListener('touchmove', function(e){
      if (!isDown || !e.touches || !e.touches.length) return;
      var t = e.touches[0];
      onMove(t.pageX, t.pageY);
      // Prevent vertical scroll only when clearly horizontal dragging
      if (axis === 'h' && e.cancelable) e.preventDefault();
    }, {passive:false});

    el.addEventListener('touchend', function(){
      if (!isDown) return;
      onUp();
    }, {passive:true});

    // Wheel horizontal
    el.addEventListener('wheel', function(e){
      // if user scrolls vertically, map it to horizontal when over the strip
      var delta = e.deltaY || e.deltaX || 0;
      if (!delta) return;
      el.scrollLeft += delta;
      if (e.cancelable) e.preventDefault();
    }, {passive:false});

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

  function fmt(n){
    n = Number(n)||0;
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,' ');
  }

  function fmtLeft(sec){
    sec = Math.max(0, parseInt(sec, 10) || 0);
    var d = Math.floor(sec / 86400);
    var h = Math.floor((sec % 86400) / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    if (d > 0) return d + 'д ' + h + 'ч';
    if (h > 0) return h + 'ч ' + m + 'м';
    if (m > 0) return m + 'м ' + s + 'с';
    return s + 'с';
  }

  function toast(type, title, msg){
    try{
      if (typeof window.gameToast === 'function'){
        return window.gameToast(type, title, msg);
      }
    }catch(_){}
    // Fallback toast (no alert)
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
      item.innerHTML = (title ? '<div style="font-weight:700;margin-bottom:4px;">'+String(title).replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</div>' : '') +
                       '<div>'+String(msg||'').replace(/</g,'&lt;').replace(/>/g,'&gt;')+'</div>';
      box.appendChild(item);
      setTimeout(function(){
        try{ item.style.opacity = '0'; item.style.transition = 'opacity .25s'; }catch(_){}
        setTimeout(function(){ try{ item.remove(); }catch(_){} }, 320);
      }, 2600);
    }catch(_e){
      try { console.log(type, title, msg); } catch(_e2) {}
    }
  }

  // ---------------- state ----------------
  var state = {
    tab: 'troops',
    selected: null,
    loading: false,
    itemsByTab: { troops: [], spells: [], heroes: [] },
    buildingInfo: null,
    server: {
      server_time: 0,
      offset: 0,
      laboratory_level: 0,
      active: null,
      unlockedTroops: {}
    }
  };

  var timers = { tick: null, refresh: null };

  // ---------------- CSRF + API ----------------
  function getCsrfToken(){
    try {
      if (window.APP_CONFIG && window.APP_CONFIG.csrfToken) return window.APP_CONFIG.csrfToken;
    } catch(_e) {}
    var meta = document.querySelector('meta[name="csrf_token"]');
    return meta ? meta.content : '';
  }

  function setCsrfTokenFromResponse(resp){
    try {
      var t = resp.headers.get('X-CSRF-Token');
      if (!t) return;
      if (window.APP_CONFIG) window.APP_CONFIG.csrfToken = t;
      var meta = document.querySelector('meta[name="csrf_token"]');
      if (!meta) {
        meta = document.createElement('meta');
        meta.name = 'csrf_token';
        document.head.appendChild(meta);
      }
      meta.content = t;
    } catch(_e) {}
  }

  function encodeParams(obj){
    var parts = [];
    for (var k in obj) {
      if (!obj.hasOwnProperty(k)) continue;
      if (obj[k] === undefined || obj[k] === null) continue;
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(obj[k])));
    }
    return parts.join('&');
  }

  function apiGet(action, params){
    params = params || {};
    params.action = action;
    var url = API_URL + '?' + encodeParams(params);
    return fetch(url, {
      method: 'GET',
      headers: {
        'X-CSRF-Token': getCsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    }).then(function(r){
      setCsrfTokenFromResponse(r);
      return r.json().then(function(j){
        if (!r.ok || !j || j.ok !== true) {
          var msg = (j && j.error) ? j.error : ('HTTP ' + r.status);
          throw new Error(msg);
        }
        return j;
      });
    });
  }

  function apiPost(action, body){
    body = body || {};
    body.action = action;
    if (!body.csrf_token) body.csrf_token = getCsrfToken();
    var payload = encodeParams(body);
    return fetch(API_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-CSRF-Token': getCsrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      body: payload,
      credentials: 'same-origin'
    }).then(function(r){
      setCsrfTokenFromResponse(r);
      return r.json().then(function(j){
        if (!r.ok || !j || j.ok !== true) {
          var msg = (j && j.error) ? j.error : ('HTTP ' + r.status);
          throw new Error(msg);
        }
        return j;
      });
    });
  }

  function nowSec(){
    return Math.floor(Date.now() / 1000) + (parseInt(state.server.offset, 10) || 0);
  }

  function isModalActive(){
    var m = q(MODAL_ID);
    return !!(m && m.classList.contains('active'));
  }

  // ---------------- image mapping ----------------
  function capWord(w){
    if (!w) return '';
    w = String(w);
    return w.charAt(0).toUpperCase() + w.slice(1);
  }

  function idToFolder(id){
    id = String(id || '');
    if (id === 'pekka') return 'P.E.K.K.A';
    var parts = id.split('_');
    var out = [];
    for (var i=0; i<parts.length; i++) out.push(capWord(parts[i]));
    return out.join('_');
  }

  function troopImg(id){
    var folder = idToFolder(id);
    return '/images/warriors/' + folder + '/Avatar_' + folder + '.png';
  }

  function spellImg(id){
    var base = String(id || '').replace(/_spell$/, '');
    var parts = base.split('_');
    var out = [];
    for (var i=0; i<parts.length; i++) out.push(capWord(parts[i]));
    return '/images/spells/' + out.join('_') + '_Spell_info.png';
  }

  function pickImg(id, type){
    type = String(type || '');
    if (type === 'spell' || type === 'dark_spell') return spellImg(id);
    return troopImg(id);
  }

  // ---------------- backend mapping ----------------
  function getArr(){ return state.itemsByTab[state.tab] || []; }

  function itemById(id){
    var arr = getArr();
    for (var i=0;i<arr.length;i++) if (arr[i].id===id) return arr[i];
    return null;
  }

  function applyBackendState(j){
    var data = j || {};

    var st = parseInt(data.server_time, 10) || 0;
    if (st > 0) {
      state.server.server_time = st;
      state.server.offset = st - Math.floor(Date.now() / 1000);
    }

    state.server.laboratory_level = parseInt(data.laboratory_level, 10) || 0;
    state.server.active = data.active || null;

    // unlocked troops (for locking troop research until opened in Barracks)
    state.server.unlockedTroops = {};
    if (data.unlocked_troops && data.unlocked_troops.length) {
      for (var i=0; i<data.unlocked_troops.length; i++) {
        state.server.unlockedTroops[String(data.unlocked_troops[i])] = true;
      }
    }

    var troops = [];
    var spells = [];

    var res = data.researchables || [];
    for (var k=0; k<res.length; k++) {
      var r = res[k] || {};
      var id = String(r.id || '');
      if (!id) continue;

      var type = String(r.type || '');
      var name = String(r.name || id);

      var cur = parseInt(r.level, 10) || 1;
      if (cur < 1) cur = 1;

      var nextLevel = (r.next_level === null || r.next_level === undefined) ? null : (parseInt(r.next_level, 10) || (cur + 1));
      var nextCost = (r.next_cost === null || r.next_cost === undefined) ? null : (parseInt(r.next_cost, 10) || 0);
      var nextTime = (r.next_time === null || r.next_time === undefined) ? null : (parseInt(r.next_time, 10) || 0);
      var maxed = (nextLevel === null || nextCost === null);

      var active = state.server.active;
      var isActiveThis = active && String(active.tech_id || '') === id;
      var timeStr = maxed ? '—' : fmtLeft(nextTime || 0);
      if (isActiveThis) timeStr = fmtLeft(active.time_left || 0);

      var it = {
        id: id,
        name: name,
        type: type,
        level: cur,
        next: (nextLevel || cur),
        cost: (nextCost == null ? 0 : nextCost),
        time: timeStr,
        owned: true,
        locked: false,
        lockReason: '',
        maxed: maxed,
        img: pickImg(id, type),
        fb: ['/images/icons/elixir.png','/images/icons/gold.png','/images/icons/panel.png']
      };

      // server-side locks (Stage 4): lab built/ready, opened requirements, etc.
      if (r.locked) {
        it.locked = true;
        it.lockReason = String(r.locked_reason || '');
      }

      // lock troop research until opened in Barracks (fallback; server usually provides this)
      if (!it.locked && (type === 'troop' || type === 'dark_troop' || type === 'siege') && !state.server.unlockedTroops[id]) {
        it.locked = true;
        it.lockReason = 'Нужно открыть в казарме';
      }

      // max level
      if (!it.locked && it.maxed) {
        it.locked = true;
        it.lockReason = 'Макс. уровень';
      }

      if (type === 'spell' || type === 'dark_spell') spells.push(it);
      else troops.push(it);
    }

    // stable order
    troops.sort(function(a,b){ return String(a.name).localeCompare(String(b.name)); });
    spells.sort(function(a,b){ return String(a.name).localeCompare(String(b.name)); });

    state.itemsByTab.troops = troops;
    state.itemsByTab.spells = spells;
    state.itemsByTab.heroes = [];

    // default selected per tab
    var arr = getArr();
    if (!state.selected || !itemById(state.selected)) {
      state.selected = arr[0] ? arr[0].id : null;
    }
  }

  function isBusyOther(it){
    var a = state.server.active;
    if (!a) return false;
    var tid = String(a.tech_id || '');
    if (!tid) return false;
    return tid !== it.id;
  }

  function isActiveThis(it){
    var a = state.server.active;
    if (!a) return false;
    return String(a.tech_id || '') === it.id;
  }

  function canStart(it){
    if (!it) return false;
    if (state.loading) return false;
    if (state.server.laboratory_level <= 0) return false;
    if (it.locked || !it.owned || it.maxed) return false;
    if (state.server.active) return false;
    return true;
  }

  // ---------------- render ----------------
  function renderTabs(){
    var tabs = [
      {key:'buildings', label:'ЗДАНИЯ'},
      {key:'troops', label:'ВОЙСКА'},
      {key:'spells', label:'ЗАКЛИНАНИЯ'},
      {key:'heroes', label:'ГЕРОИ'}
    ];
    return '<div class="coc-tabs" id="coc-lab-tabs">' +
      tabs.map(function(t){
        var a = (t.key===state.tab) ? ' is-active' : '';
        return '<button type="button" class="coc-tab'+a+'" data-tab="'+esc(t.key)+'">'+esc(t.label)+'</button>';
      }).join('') +
    '</div>';
  }

  function renderSelectStrip(){
    var arr = getArr();

    var items = '';
    if (state.loading) {
      items = '<div class="coc-sitem is-selected" style="padding:10px 12px;">Загрузка…</div>';
    } else if (!arr.length) {
      items = '<div class="coc-sitem is-selected" style="padding:10px 12px;">Нет доступных исследований.</div>';
    } else {
      items = arr.map(function(it){
        var disabled = (!it.owned) || !!it.locked || isBusyOther(it) || isActiveThis(it) || state.server.laboratory_level<=0;
        var cls = 'coc-sitem' + (disabled ? ' is-disabled' : '') + (state.selected===it.id ? ' is-selected' : '');
        var showWarn = (!it.owned) || !!it.locked;
        return '<div class="'+cls+'" data-item="'+esc(it.id)+'">' +
          imgWithFallback(it.img, it.fb||[]) +
          '<div class="coc-badge coc-badge-count">'+esc(it.level)+'</div>' +
          (showWarn ? '<div class="coc-badge coc-badge-warn">🔒</div>' : '') +
        '</div>';
      }).join('');
    }

    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-panel-head">' +
        '<div class="coc-panel-title">Выберите для улучшения</div>' +
        '<div class="coc-panel-right">Свайп/скролл</div>' +
      '</div>' +
      '<div class="coc-strip" id="coc-lab-strip">'+items+'</div>' +
    '</div>';
  }

  function renderDetail(){
    var it = itemById(state.selected);

    if (state.loading && !it){
      return '<div class="coc-panel" style="margin-top:10px; font-weight:800; color:#2a2a2a;">Загрузка…</div>';
    }

    if (!it){
      return '<div class="coc-panel" style="margin-top:10px; font-weight:800; color:#2a2a2a;">Выберите элемент сверху.</div>';
    }

    var a = state.server.active;
    var activeThis = isActiveThis(it);
    var busy = isBusyOther(it);
    var labMissing = state.server.laboratory_level <= 0;

    var locked = labMissing || (!it.owned) || !!it.locked || busy || activeThis || state.loading;

    var lockText = '';
    if (labMissing) lockText = '<div class="coc-detail-lock">🔒 Постройте лабораторию</div>';
    else if (it.locked) lockText = '<div class="coc-detail-lock">🔒 ' + esc(it.lockReason || 'Недоступно') + '</div>';
    else if (busy) lockText = '<div class="coc-detail-lock">🔒 Лаборатория занята</div>';
    else if (activeThis) lockText = '<div class="coc-detail-lock">⏳ Исследуется (осталось ' + esc(fmtLeft(a.time_left || 0)) + ')</div>';

    var costText = (it.maxed || activeThis) ? '—' : fmt(it.cost);
    var timeText = activeThis ? fmtLeft(a.time_left || 0) : (it.maxed ? '—' : (it.time || '—'));

    var btnLabel = 'УЛУЧШИТЬ';
    if (state.loading) btnLabel = '...';
    else if (labMissing) btnLabel = 'НУЖНО ЗДАНИЕ';
    else if (activeThis) btnLabel = 'ИСЛЕДУЕТСЯ';
    else if (busy) btnLabel = 'ЗАНЯТО';
    else if (it.maxed) btnLabel = 'МАКС.';
    else if ((!it.owned) || it.locked) btnLabel = 'НЕДОСТУПНО';

var speedBtn = '';
if (activeThis && a && a.finish_time){
  var leftS = Math.max(0, parseInt(a.finish_time,10) - nowSec());
  var costG = gemCostForSeconds(leftS);
  speedBtn = '<button type="button" class="coc-speedup-btn" id="coc-lab-speedup">УСКОРИТЬ <span class="coc-btn-cost"><img src="'+esc(resIconAny('gems'))+'" alt="" class="coc-btn-ico"> '+esc(fmt(costG))+'</span></button>';
}


    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-detail-row">' +
        '<div class="coc-detail-icon">' +
          imgWithFallback(it.img, it.fb||[]) +
        '</div>' +
        '<div class="coc-detail-main">' +
          '<div class="coc-detail-title">'+esc(it.name)+'</div>' +
          '<div class="coc-detail-sub">Уровень '+esc(it.level)+' → '+esc(it.next)+'</div>' +
          '<div class="coc-detail-cost">Стоимость: '+esc(costText)+' <img src="/images/icons/gold.png" alt="" style="width:14px;height:14px;vertical-align:-2px;"></div>' +
          '<div class="coc-detail-time">Время: '+esc(timeText)+'</div>' +
          lockText +
        '</div>' +
      '</div>' +
      '<div class="coc-detail-actions">' +
        '<button type="button" class="coc-speedup-btn" id="coc-lab-up" '+(locked ? 'disabled="disabled"' : '')+'>'+ esc(btnLabel) +'</button>' +
        speedBtn +
        '<button type="button" class="coc-speedup-btn coc-btn-gray" id="coc-lab-clear">СБРОС</button>' +
      '</div>' +
    '</div>';
  }

  
// ---- CoC-like confirm modal + gem cost (shared styles in coc_compact_ui.css) ----
function gemCostForSeconds(seconds){
  var x = Math.max(0, parseInt(seconds,10)||0);
  if (x <= 0) return 0;
  if (x <= 60) return 1;
  if (x <= 3600){
    var y1 = ((20 - 1) / (3600 - 60)) * (x - 60) + 1;
    return Math.max(1, Math.round(y1));
  }
  if (x <= 86400){
    var y2 = ((260 - 20) / (86400 - 3600)) * (x - 3600) + 20;
    return Math.max(1, Math.round(y2));
  }
  var y3 = ((1000 - 260) / (604800 - 86400)) * (x - 86400) + 260;
  return Math.max(1, Math.round(y3));
}

function ensureConfirmRoot(){
  var id = 'coc-confirm-overlay';
  var ex = document.getElementById(id);
  if (ex) return ex;
  var ov = document.createElement('div');
  ov.id = id;
  ov.className = 'coc-confirm-overlay is-hidden';
  ov.innerHTML = ''+
    '<div class="coc-confirm" role="dialog" aria-modal="true">'+
      '<div class="coc-confirm-head">'+
        '<div class="coc-confirm-title" id="coc-confirm-title"></div>'+
        '<button class="coc-confirm-x" type="button" aria-label="Закрыть">×</button>'+
      '</div>'+
      '<div class="coc-confirm-body" id="coc-confirm-body"></div>'+
      '<div class="coc-confirm-actions">'+
        '<button type="button" class="coc-confirm-btn coc-confirm-no" id="coc-confirm-no">ОТМЕНА</button>'+
        '<button type="button" class="coc-confirm-btn coc-confirm-yes" id="coc-confirm-yes">ДА</button>'+
      '</div>'+
    '</div>';
  document.body.appendChild(ov);
  ov.addEventListener('click', function(e){ if (e.target === ov) { try{ ov.__cocResolve && ov.__cocResolve(false);}catch(_e){} } });
  var x = ov.querySelector('.coc-confirm-x');
  if (x) x.addEventListener('click', function(){ try{ ov.__cocResolve && ov.__cocResolve(false);}catch(_e){} });
  return ov;
}

function cocConfirm(opts){
  opts = opts || {};
  var ov = ensureConfirmRoot();
  var title = String(opts.title || 'Подтверждение');
  var text = String(opts.text || '');
  var cost = (typeof opts.cost !== 'undefined') ? (parseInt(opts.cost,10)||0) : null;
  var icon = String(opts.icon || '');
  var yesText = String(opts.yesText || 'ДА');
  var noText = String(opts.noText || 'ОТМЕНА');

  var tEl = ov.querySelector('#coc-confirm-title');
  var bEl = ov.querySelector('#coc-confirm-body');
  var yEl = ov.querySelector('#coc-confirm-yes');
  var nEl = ov.querySelector('#coc-confirm-no');

  if (tEl) tEl.textContent = title;
  var costHtml = '';
  if (cost !== null){
    costHtml = '<div class="coc-confirm-cost">'+
      '<span class="coc-confirm-costlbl">Стоимость:</span> '+
      '<span class="coc-confirm-costval">'+esc(fmt(cost))+'</span>' +
      (icon ? (' <img class="coc-confirm-ico" src="'+esc(icon)+'" alt="">') : '') +
    '</div>';
  }
  if (bEl){
    bEl.innerHTML = '<div class="coc-confirm-text">'+esc(text)+'</div>' + costHtml;
  }
  if (yEl) yEl.textContent = yesText;
  if (nEl) nEl.textContent = noText;

  try{ yEl && yEl.focus && yEl.focus(); }catch(_e){}

  ov.classList.remove('is-hidden');
  return new Promise(function(resolve){
    function done(val){
      try{ ov.classList.add('is-hidden'); }catch(_e){}
      ov.__cocResolve = null;
      try{
        yEl && yEl.removeEventListener('click', onYes);
        nEl && nEl.removeEventListener('click', onNo);
      }catch(_e2){}
      resolve(!!val);
    }
    function onYes(){ done(true); }
    function onNo(){ done(false); }
    ov.__cocResolve = done;
    if (yEl) yEl.addEventListener('click', onYes, {once:true});
    if (nEl) nEl.addEventListener('click', onNo, {once:true});
  });
}

function resIconAny(r){
    if (r === null || typeof r === 'undefined') return '/images/icons/elixir.png';
    var s = String(r);
    if (s === 'gold' || s === '1') return '/images/icons/gold.png';
    if (s === 'dark_elixir' || s === 'dark' || s === '3') return '/images/icons/dark_elixir.png';
    return '/images/icons/elixir.png';
  }

  function buildingImg(id){
    if (!id) return '/images/building/laboratory.png';
    return '/images/building/' + id + '.png';
  }

  function renderBuildingDetail(){
    var data = state.buildingInfo;
    var b = data && data.building ? data.building : null;
    if (!b) return '';

    var lvl = parseInt(b.level, 10) || 0;
    var maxLvl = parseInt(b.max_level, 10) || 0;
    var cur = b.current || null;
    var next = b.next || null;
    var status = String(b.status || 'none');
    var busy = (status !== 'active' && status !== 'none');
    var finish = parseInt(b.finish_time, 10) || 0;
    var left = busy ? Math.max(0, finish - nowSec()) : 0;

    var curHp = cur && typeof cur.hp !== 'undefined' ? cur.hp : null;
    var nextHp = next && typeof next.hp !== 'undefined' ? next.hp : null;

    var costText = next ? fmt(String(next.cost || '0')) : '—';
    var timeText = next ? (next.time ? fmtLeft(parseInt(next.time, 10) || 0) : '—') : '—';
    var lock = b.locked_reason ? '<div class="coc-detail-lock">🔒 '+esc(String(b.locked_reason))+'</div>' : '';

    var btnLabel = b.can_build ? 'ПОСТРОИТЬ' : (b.can_upgrade ? 'УЛУЧШИТЬ' : (busy ? 'ЗАНЯТО' : 'МАКС.'));
    var btnDisabled = (!b.can_build && !b.can_upgrade) ? 'disabled="disabled"' : '';

    return '<div class="coc-panel" style="margin-top:10px;">' +
      '<div class="coc-detail-row">' +
        '<div class="coc-detail-icon">' +
          imgWithFallback(buildingImg(b.id), ["/images/building/laboratory.png","/images/icons/trophy_icon.png"]) +
        '</div>' +
        '<div class="coc-detail-main">' +
          '<div class="coc-detail-title">'+esc(String(b.name || b.id))+'</div>' +
          '<div class="coc-detail-sub">Уровень '+esc(String(lvl))+' / '+esc(String(maxLvl))+(b.next_level ? (' → '+esc(String(b.next_level))) : '')+'</div>' +
          (b.description ? '<div class="coc-detail-time" style="margin-top:6px;">'+esc(String(b.description))+'</div>' : '') +
          (curHp !== null ? '<div class="coc-detail-time">Здоровье: '+esc(String(curHp))+'</div>' : '') +
          (nextHp !== null ? '<div class="coc-detail-time">След. здоровье: '+esc(String(nextHp))+'</div>' : '') +
          (busy ? '<div class="coc-detail-time">⏳ Осталось: '+esc(fmtLeft(left))+'</div>' : '') +
          (!busy && next ? '<div class="coc-detail-cost">Стоимость: '+esc(costText)+' '+imgWithFallback(resIconAny(next.res_type), ['/images/icons/elixir.png','/images/icons/gold.png','/images/icons/fuel.png','/images/icons/panel.png'])+'</div>' : '') +
          (!busy && next ? '<div class="coc-detail-time">Время: '+esc(timeText)+'</div>' : '') +
          lock +
        '</div>' +
      '</div>' +
      '<div class="coc-detail-actions">' +
        '<button type="button" class="coc-speedup-btn" id="coc-bld-act" '+btnDisabled+' data-bact="'+esc(b.id)+'">'+esc(btnLabel)+'</button>' +
        '<button type="button" class="coc-speedup-btn coc-btn-gray" id="coc-bld-back">НАЗАД</button>' +
      '</div>' +
    '</div>';
  }

  function renderBuildingsTab(){
    var lvl = parseInt(state.server.laboratory_level, 10) || 0;
    var badge = (lvl > 0) ? ('<div class="coc-bbadge">УР. '+esc(String(lvl))+'</div>') : '<div class="coc-bbadge">НЕ ПОСТРОЕНО</div>';
    var btn = (lvl > 0) ? '<button type="button" class="coc-bbtn" data-bup="laboratory">Улучшить</button>' : '<button type="button" class="coc-bbtn" data-bbuild="laboratory">Построить</button>';

    return '<div class="coc-panel coc-building-panel" style="margin-top:10px;">' +
      '<div class="coc-building-head">' +
        '<div class="coc-building-title">ЗДАНИЕ</div>' +
        '<div class="coc-building-sub">Лаборатория (как в CoC)</div>' +
      '</div>' +
      '<div class="coc-bslots">' +
        '<div class="coc-bslot'+(lvl<=0 ? ' is-empty' : '')+'" data-bopen="laboratory">' +
          '<button type="button" class="coc-info" data-binfo="laboratory">i</button>' +
          imgWithFallback(buildingImg('laboratory'), ["/images/building/laboratory.png","/images/icons/trophy_icon.png"]) +
          badge +
          '<div class="coc-bactions">'+btn+'</div>' +
        '</div>' +
      '</div>' +
    '</div>' +
    renderBuildingDetail();
  }

  function render(){
    var body = '';
    if (state.tab === 'buildings'){
      body = renderBuildingsTab();
    } else {
      body = renderSelectStrip() + renderDetail();
    }
    var html =
      '<div class="modal-header-controls">' +
        '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\''+MODAL_ID+'\')">' +
          '<img src="/images/icons/close.png" alt="Закрыть">' +
        '</button>' +
        '<div class="modal-title-bar"><h2 class="modal-title-text-inside-panel">ЛАБОРАТОРИЯ</h2></div>' +
      '</div>' +
      '<div class="modal-body-custom">' +
        renderTabs() +
        body +
      '</div>';

    var content = q(CONTENT_ID);
    if (!content) return;

    // preserve scroll positions inside modal
    var __prev = { bodyTop: 0, left: {} };
    var __bodyOld = content.querySelector('.modal-body-custom');
    if (__bodyOld) __prev.bodyTop = __bodyOld.scrollTop || 0;
    var __ids = ['coc-lab-tabs','coc-lab-strip'];
    for (var __i=0; __i<__ids.length; __i++){
      var __elOld = content.querySelector('#'+__ids[__i]);
      if (__elOld) __prev.left[__ids[__i]] = __elOld.scrollLeft || 0;
    }

    content.innerHTML = html;

    var __bodyNew = content.querySelector('.modal-body-custom');
    if (__bodyNew) __bodyNew.scrollTop = __prev.bodyTop || 0;
    for (var __k in __prev.left){
      if (!__prev.left.hasOwnProperty(__k)) continue;
      var __elNew = content.querySelector('#'+__k);
      if (__elNew) __elNew.scrollLeft = __prev.left[__k] || 0;
    }

    bindImgFallback(content);

    var tabsEl = q('coc-lab-tabs');
    if (tabsEl){
      enableDragScroll(tabsEl);
      tabsEl.addEventListener('click', function(e){
        var btn = closest(e.target, '[data-tab]');
        if (!btn) return;
        state.tab = btn.getAttribute('data-tab') || 'troops';
        if (state.tab === 'buildings'){
          state.buildingInfo = null;
          render();
          return;
        }
        state.buildingInfo = null;
        var arr = getArr();
        state.selected = arr[0] ? arr[0].id : null;
        render();
      });
    }

    // buildings: build/upgrade/info
    var buildPanel = content.querySelector('.coc-building-panel');
    if (buildPanel){
      if (buildPanel.dataset && buildPanel.dataset.boundClick === '1'){
        // already bound
      } else {
        if (buildPanel.dataset) buildPanel.dataset.boundClick = '1';
        buildPanel.addEventListener('click', function(e){
        var bid = 'laboratory';

        var binfo = closest(e.target, '[data-binfo]');
        var bopen = closest(e.target, '[data-bopen]');
        if (binfo || bopen){
          apiGet('building_info', { building_id: bid }).then(function(data){
            state.buildingInfo = data;
            render();
          }).catch(function(err){
            toast('error', 'Здания', String(err && err.message ? err.message : err));
          });
          e.preventDefault();
          return;
        }

        var b1 = closest(e.target, '[data-bbuild]');
        if (b1){
          apiPost('building_build', { building_id: bid }).then(function(){
            toast('success', 'Здания', 'Постройка начата.');
            state.buildingInfo = null;
            return reloadAndRender();
          }).catch(function(err){
            toast('error', 'Здания', String(err && err.message ? err.message : err));
            return reloadAndRender();
          });
          return;
        }

        var b2 = closest(e.target, '[data-bup]');
        if (b2){
          apiPost('building_upgrade', { building_id: bid }).then(function(){
            toast('success', 'Здания', 'Улучшение запущено.');
            state.buildingInfo = null;
            return reloadAndRender();
          }).catch(function(err){
            toast('error', 'Здания', String(err && err.message ? err.message : err));
            return reloadAndRender();
          });
          return;
        }
      });
      }
    }

    var backB = q('coc-bld-back');
    if (backB){
      if (!backB.dataset) backB.dataset = {};
      if (backB.dataset.boundClick !== '1'){
        backB.dataset.boundClick = '1';
        backB.addEventListener('click', function(){
        state.buildingInfo = null;
        render();
      });
      }
    }

    var actB = q('coc-bld-act');
    if (actB){
      if (!actB.dataset) actB.dataset = {};
      if (actB.dataset.boundClick !== '1'){
        actB.dataset.boundClick = '1';
        actB.addEventListener('click', function(){
        var bid2 = actB.getAttribute('data-bact') || 'laboratory';
        if (!bid2 || !state.buildingInfo || !state.buildingInfo.building) return;
        var b = state.buildingInfo.building;
        var action = b.can_build ? 'building_build' : (b.can_upgrade ? 'building_upgrade' : '');
        if (!action) return;
        actB.disabled = true;
        apiPost(action, { building_id: bid2 }).then(function(){
          toast('success', 'Здания', 'Готово.');
          state.buildingInfo = null;
          return reloadAndRender();
        }).catch(function(err){
          toast('error', 'Здания', String(err && err.message ? err.message : err));
          state.buildingInfo = null;
          return reloadAndRender();
        });
      });
      }
    }

    var strip = q('coc-lab-strip');
    if (strip){
      enableDragScroll(strip);
      strip.addEventListener('click', function(e){
        var it = closest(e.target, '[data-item]');
        if (!it) return;
        state.selected = it.getAttribute('data-item');
        render();
      });
    }

    var up = q('coc-lab-up');
    if (up){
      up.addEventListener('click', function(){
        var it = itemById(state.selected);
        if (!it) return;
        if (!canStart(it)) return;

        up.disabled = true;
        apiPost('lab_start', { tech_id: it.id })
          .then(function(){
            toast('success', 'Лаборатория', 'Исследование начато: ' + it.name);
            return reloadAndRender();
          })
          .catch(function(err){
            toast('error', 'Ошибка', String(err && err.message ? err.message : err));
            return reloadAndRender();
          });
      });
    }


var sp = q('coc-lab-speedup');
if (sp){
  sp.addEventListener('click', function(){
    var a = state.server.active;
    if (!a || !a.finish_time) return;
    var left = Math.max(0, parseInt(a.finish_time,10) - nowSec());
    var costG = gemCostForSeconds(left);
    sp.disabled = true;
    cocConfirm({
      title: 'Ускорить исследование?',
      text: 'Завершить исследование сейчас?',
      cost: costG,
      icon: resIconAny('gems'),
      yesText: 'ДА',
      noText: 'ОТМЕНА'
    }).then(function(ok){
      if (!ok){ sp.disabled=false; return; }
      return apiPost('lab_speedup', {}).then(function(resp){
        var spent = resp && typeof resp.cost !== 'undefined' ? (parseInt(resp.cost,10)||0) : costG;
        toast('success', 'Лаборатория', spent>0 ? ('Ускорено за '+fmt(spent)+' гемов.') : 'Ускорено.');
        return reloadAndRender();
      }).catch(function(err){
        toast('error', 'Ошибка', String(err && err.message ? err.message : err));
        return reloadAndRender();
      }).finally(function(){ sp.disabled=false; });
    });
  });
}

    var clr = q('coc-lab-clear');
    if (clr){
      clr.addEventListener('click', function(){
        var arr = getArr();
        state.selected = arr[0] ? arr[0].id : null;
        render();
      });
    }

    startTimers();
  }

  // ---------------- timers ----------------
  function stopTimers(){
    if (timers.tick) { clearInterval(timers.tick); timers.tick = null; }
    if (timers.refresh) { clearTimeout(timers.refresh); timers.refresh = null; }
  }

  function startTimers(){
    stopTimers();

    var a = state.server.active;
    if (!a || !a.finish_time) return;

    timers.tick = setInterval(function(){
      try {
        if (!isModalActive()) { stopTimers(); return; }
        var act = state.server.active;
        if (!act || !act.finish_time) return;

        var left = Math.max(0, parseInt(act.finish_time, 10) - nowSec());
        act.time_left = left;

        // Update only current detail if selected item is active
        var it = itemById(state.selected);
        if (it && isActiveThis(it)) {
          var content = q(CONTENT_ID);
          if (content) {
            var tEl = content.querySelector('.coc-detail-time');
            if (tEl) tEl.textContent = 'Время: ' + fmtLeft(left);
            var lEl = content.querySelector('.coc-detail-lock');
            if (lEl) lEl.textContent = '⏳ Исследуется (осталось ' + fmtLeft(left) + ')';
          }
        }

        if (left <= 0) {
          stopTimers();
          reloadAndRender();
        }
      } catch(_e) {}
    }, 1000);

    // reload a bit after finish
    var ms = (Math.max(0, parseInt(a.finish_time, 10) - nowSec()) + 2) * 1000;
    timers.refresh = setTimeout(function(){
      if (!isModalActive()) return;
      reloadAndRender();
    }, ms);
  }

  // ---------------- loading ----------------
  function reloadAndRender(){
    state.loading = true;
    render();
    return apiGet('lab_state')
      .then(function(j){
        applyBackendState(j);
        state.loading = false;
        render();
      })
      .catch(function(err){
        state.loading = false;
        render();
        toast('error', 'Ошибка', String(err && err.message ? err.message : err));
      });
  }

  function open(){
    var modal = q(MODAL_ID);
    var content = q(CONTENT_ID);
    if (!modal || !content) return;
    modal.classList.add('active');
    stopTimers();
    reloadAndRender();
  }

  window.showLabModal = open;

})();
