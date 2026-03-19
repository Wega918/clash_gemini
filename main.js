// Убедимся, что ENVIRONMENT определен до использования
if (typeof window.ENVIRONMENT === 'undefined') {
    window.ENVIRONMENT = 'production';
}

// ------------------ Toast + action error helper ------------------
(function(){
  if (window.gameToast && window.gameHandleActionError) return;

  function fmtNum(n){
    n = parseInt(n, 10);
    if (!isFinite(n)) return '';
    return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function ensureToastRoot(){
    var root = document.getElementById('game-toast-container');
    if (root) return root;
    root = document.createElement('div');
    root.id = 'game-toast-container';
    root.style.position = 'fixed';
    root.style.left = '50%';
    root.style.bottom = '20px';
    root.style.transform = 'translateX(-50%)';
    root.style.zIndex = '99999';
    root.style.display = 'flex';
    root.style.flexDirection = 'column';
    root.style.gap = '10px';
    root.style.pointerEvents = 'none';
    document.body.appendChild(root);
    return root;
  }

  window.gameToast = function(type, title, msg, ttl){
    try {
      var root = ensureToastRoot();
      var t = document.createElement('div');
      t.style.minWidth = '260px';
      t.style.maxWidth = '92vw';
      t.style.padding = '12px 14px';
      t.style.borderRadius = '12px';
      t.style.boxShadow = '0 10px 30px rgba(0,0,0,0.25)';
      t.style.backdropFilter = 'blur(6px)';
      t.style.pointerEvents = 'auto';
      t.style.border = '1px solid rgba(255,255,255,0.18)';
      t.style.background = 'rgba(20, 20, 20, 0.90)';
      t.style.color = '#fff';
      t.style.fontFamily = 'inherit';

      var icon = 'ℹ️';
      if (type === 'error') icon = '❌';
      else if (type === 'success') icon = '✅';
      else if (type === 'warning') icon = '⚠️';

      var h = document.createElement('div');
      h.style.fontWeight = '700';
      h.style.marginBottom = '4px';
      h.textContent = (icon + ' ' + (title || ''));
      t.appendChild(h);

      var b = document.createElement('div');
      b.style.opacity = '0.92';
      b.style.fontSize = '13px';
      if (msg && (typeof msg === 'string') && msg.indexOf('<') !== -1) b.innerHTML = msg;
      else b.textContent = msg || '';
      t.appendChild(b);

      root.appendChild(t);
      setTimeout(function(){ try{ t.style.opacity = '0'; t.style.transform = 'translateY(6px)'; }catch(_e){} }, Math.max(1200, (ttl||3800) - 500));
      setTimeout(function(){ try{ if(t && t.parentNode) t.parentNode.removeChild(t); }catch(_e){} }, ttl || 3800);
    } catch (e) {
      // ignore
    }
  };

  window.gameHandleActionError = function(root){
    try {
      if (!root || !root.querySelector) return null;
      var el = root.querySelector('.js-action-error');
      if (!el) return null;

      var kind = el.getAttribute('data-type') || '';
      var code = el.getAttribute('data-code') || '';
      var title = 'Ошибка';
      var msg = '';

      if (kind === 'not_enough_resources') {
        var res = (el.getAttribute('data-res') || '').toLowerCase();
        var missing = fmtNum(el.getAttribute('data-missing'));
        var need = fmtNum(el.getAttribute('data-need'));
        var have = fmtNum(el.getAttribute('data-have'));
        var map = { gold: 'золота', elixir: 'эликсира', dark_elixir: 'тёмного эликсира', gems: 'кристаллов' };
        var resRu = map[res] || res || 'ресурсов';
        title = 'Не хватает ресурсов';
        msg = 'Не хватает ' + resRu + ': ' + missing + ' (нужно ' + need + ', есть ' + have + ')';
        window.gameToast('warning', title, msg);
        return {kind: kind, code: code};
      }

      if (kind === 'need_townhall') {
        var thReq = el.getAttribute('data-th_req') || '';
        title = 'Нужна ратуша';
        msg = 'Требуется Ратуша ур. ' + thReq;
        window.gameToast('info', title, msg);
        return {kind: kind, code: code};
      }

      if (kind === 'max_count') {
        title = 'Лимит зданий';
        msg = 'Достигнут лимит зданий этого типа.';
        window.gameToast('info', title, msg);
        return {kind: kind, code: code};
      }

      if (kind === 'busy') {
        title = 'Занято';
        msg = 'Здание сейчас строится или улучшается.';
        window.gameToast('info', title, msg);
        return {kind: kind, code: code};
      }

      if (kind === 'no_builder') {
        title = 'Нет строителей';
        msg = 'Нет свободных строителей. Дождитесь окончания работ или наймите дополнительного.';
        window.gameToast('info', title, msg);
        return {kind: kind, code: code};
      }

      // fallback
      if (code && parseInt(code,10) >= 500) {
        window.gameToast('error', 'Ошибка сервера', 'Попробуйте позже');
      }
      return {kind: kind || 'error', code: code};
    } catch (e) {
      return null;
    }
  };
})();


// ------------------ App-like touch UX (disable callout/context menu, stop bubbling) ------------------
(function(){
  var SEL_NO_CTX = '.coc-qminus, .coc-qplus, .prod-qc-btn, .building, .building-label, .modal-button-corner';

  function closest(el, sel){
    try { return el && el.closest ? el.closest(sel) : null; } catch(e){ return null; }
  }

  // 1) Prevent right-click / long-press browser menus on key UI elements
  document.addEventListener('contextmenu', function(e){
    var t = e.target;
    if (closest(t, SEL_NO_CTX)) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  }, true);

    // 2) Do not let clicks on inner controls trigger parent "building" click.
  // Important: DO NOT stop touchstart/pointerdown bubbling here, otherwise existing "hold to remove" logic
  // in barracks/spells can break on mobile. We only stop the final click.
  document.addEventListener('click', function(e){
    var t = e.target;
    if (closest(t, '.prod-qc-btn, .coc-qminus, .coc-qplus')) {
      e.stopPropagation();
    }
  }, true);
})();

// Global small notification for "auth required" situations.
// Used by multiple location scripts to avoid scary console errors.
window.cocNotifyAuthRequired = window.cocNotifyAuthRequired || function(){
  try {
    var id = 'coc-auth-toast';
    var el = document.getElementById(id);
    if (!el) {
      el = document.createElement('div');
      el.id = id;
      el.style.position = 'fixed';
      el.style.left = '50%';
      el.style.bottom = '22px';
      el.style.transform = 'translateX(-50%)';
      el.style.zIndex = '999999';
      el.style.maxWidth = '92vw';
      el.style.padding = '10px 14px';
      el.style.borderRadius = '12px';
      el.style.background = 'rgba(0,0,0,0.85)';
      el.style.color = '#fff';
      el.style.fontWeight = '800';
      el.style.fontSize = '14px';
      el.style.boxShadow = '0 10px 24px rgba(0,0,0,0.35)';
      el.style.display = 'none';
      el.style.alignItems = 'center';
      el.style.gap = '10px';
      el.style.cursor = 'pointer';
      el.style.userSelect = 'none';
      el.innerHTML = '🔒 Требуется авторизация. Нажмите, чтобы войти.';
      el.addEventListener('click', function(){
        try { window.location.href = 'login.php'; } catch(e) {}
      });
      document.body.appendChild(el);
    }
    el.style.display = 'flex';
    clearTimeout(window.__cocAuthToastT);
    window.__cocAuthToastT = setTimeout(function(){
      try { el.style.display = 'none'; } catch(e) {}
    }, 3500);
  } catch(e) {}
};

// ------------------ Синхронизация полосок ресурсов на старте ------------------
(function(){
  if (window.syncBalanceIndicatorsOnLoad) return;

  function parseNum(s){
    return parseInt(String(s || '').replace(/[^0-9]/g, ''), 10) || 0;
  }
  function clampPct(v){
    v = parseFloat(v);
    if (!isFinite(v)) v = 0;
    return Math.max(0, Math.min(100, v));
  }

  function apply(){
    try {
      var caps = window.BALANCE_CAPS || null;
      if (!caps) return;

      var pairs = [
        { key: 'gold', cap: caps.gold },
        { key: 'elixir', cap: caps.elixir },
        { key: 'dark_elixir', cap: caps.dark_elixir }
      ];

      for (var i = 0; i < pairs.length; i++) {
        var k = pairs[i].key;
        var cap = parseInt(pairs[i].cap, 10) || 0;
        var textEl = document.getElementById('balance-' + k + '-text');
        var barEl = document.getElementById('balance-' + k + '-bar');
        if (!textEl || !barEl) continue;
        var amount = parseNum(textEl.textContent);

        var pct = 0;
        if (cap > 0) pct = (amount / cap) * 100;

        // Если ресурс > 0, но процент получился слишком маленьким, покажем хотя бы тонкую полоску.
        if (amount > 0 && cap > 0 && pct > 0 && pct < 1) pct = 1;

        barEl.style.width = clampPct(pct) + '%';
      }
    } catch(e) {
      // silent
    }
  }

  window.syncBalanceIndicatorsOnLoad = apply;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', apply);
  } else {
    apply();
  }
})();

// ------------------ Быстрый сбор с главной карты (Производство) ------------------
(function(){
  if (window.homeProductionCollectAll) return;

  function fmtNum(n){
    n = parseInt(n, 10) || 0;
    return (''+n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function clampPct(v){
    v = parseFloat(v);
    if (!isFinite(v)) v = 0;
    return Math.max(0, Math.min(100, v));
  }

  function getCsrf(){
    if (window.APP_CONFIG && window.APP_CONFIG.csrfToken) return window.APP_CONFIG.csrfToken;
    var meta = document.querySelector('meta[name="csrf_token"]');
    return meta ? meta.content : '';
  }

  function syncCsrfFromResponse(resp){
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
    } catch(e) {}
  }

  function setBalance(resKey, amount, cap){
    var textEl = document.getElementById('balance-' + resKey + '-text');
    if (textEl) textEl.textContent = fmtNum(amount);
    var barEl = document.getElementById('balance-' + resKey + '-bar');
    if (barEl) {
      var pct = 0;
      cap = parseInt(cap,10) || 0;
      if (cap > 0) pct = (amount / cap) * 100;
      barEl.style.width = clampPct(pct) + '%';
    }
  }

  function floatGainNearBalance(resKey, amount){
    amount = parseInt(amount, 10) || 0;
    if (amount <= 0) return;
    var anchor = document.getElementById('balance-' + resKey + '-text');
    if (!anchor) return;
    var rect = anchor.getBoundingClientRect();
    var fly = document.createElement('div');
    fly.textContent = '+' + fmtNum(amount);
    fly.style.position = 'fixed';
    fly.style.left = (rect.left + rect.width/2) + 'px';
    fly.style.top = (rect.top - 2) + 'px';
    fly.style.transform = 'translate(-50%, 0)';
    fly.style.fontWeight = '700';
    fly.style.fontSize = '14px';
    fly.style.pointerEvents = 'none';
    fly.style.zIndex = '99999';
    fly.style.textShadow = '0 2px 6px rgba(0,0,0,.65)';
    document.body.appendChild(fly);

    if (fly.animate) {
      var a = fly.animate(
        [
          { transform: 'translate(-50%, 0)', opacity: 1 },
          { transform: 'translate(-50%, -18px)', opacity: 0 }
        ],
        { duration: 700, easing: 'ease-out' }
      );
      a.onfinish = function(){
        if (fly && fly.parentNode) fly.parentNode.removeChild(fly);
      };
    } else {
      setTimeout(function(){
        if (fly && fly.parentNode) fly.parentNode.removeChild(fly);
      }, 800);
    }
  }

  function applyBalancePayloadFromHtml(html){
    try {
      var wrap = document.createElement('div');
      wrap.innerHTML = html;
      var p = wrap.querySelector('.js-balance-payload');
      if (!p) return null;

      var gold = parseInt(p.getAttribute('data-gold')||'0',10) || 0;
      var elixir = parseInt(p.getAttribute('data-elixir')||'0',10) || 0;
      var dark = parseInt(p.getAttribute('data-dark_elixir')||'0',10) || 0;
      var gems = parseInt(p.getAttribute('data-gems')||'0',10) || 0;

      var capGold = parseInt(p.getAttribute('data-cap_gold')||'0',10) || 0;
      var capElixir = parseInt(p.getAttribute('data-cap_elixir')||'0',10) || 0;
      var capDark = parseInt(p.getAttribute('data-cap_dark_elixir')||'0',10) || 0;
      var capGems = parseInt(p.getAttribute('data-cap_gems')||'0',10) || 0;

      setBalance('gold', gold, capGold);
      setBalance('elixir', elixir, capElixir);
      setBalance('dark_elixir', dark, capDark);
      setBalance('gems', gems, capGems);

      var cr = (p.getAttribute('data-collect_res')||'').trim();
      var ca = parseInt(p.getAttribute('data-collect_amt')||'0',10) || 0;
      var blocked = (p.getAttribute('data-collect_blocked')||'').trim();
      if (cr && ca > 0) floatGainNearBalance(cr, ca);

      return { collectRes: cr, collectAmt: ca, collectBlocked: blocked };
    } catch(e) {
      return null;
    }
  }

  function setBusy(on){
    try {
      var btns = document.querySelectorAll('.prod-qc-btn');
      for (var i=0;i<btns.length;i++) {
        if (on) btns[i].classList.add('is-busy');
        else btns[i].classList.remove('is-busy');
      }
    } catch(e) {}
  }

  window.homeProductionCollectAll = async function(buildingType){
    if (!buildingType) return;
    if (window.__home_prod_collect_busy) return;
    window.__home_prod_collect_busy = true;
    setBusy(true);

    // meta for notifications
    var meta = null;
    if (buildingType === 'gold_mine') meta = { res: 'gold', title: 'Золото', gen: 'золота', icon: '/images/icons/gold.png' };
    else if (buildingType === 'elixir_collector') meta = { res: 'elixir', title: 'Эликсир', gen: 'эликсира', icon: '/images/icons/elixir.png' };
    else if (buildingType === 'dark_elixir_drill') meta = { res: 'dark_elixir', title: 'Тёмный эликсир', gen: 'тёмного эликсира', icon: '/images/icons/fuel.png' };

    try {
      var url = 'ajax.php?page=production&view=collect_all&type=' + encodeURIComponent(buildingType) + '&return=main&r=' + Date.now();
      var resp = await fetch(url, {
        credentials: 'same-origin',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Accept': 'text/html',
          'X-CSRF-Token': getCsrf()
        }
      });
      syncCsrfFromResponse(resp);

      var txt = await resp.text();
      var payload = applyBalancePayloadFromHtml(txt);

      if (!resp.ok) {
        if (window.gameToast) window.gameToast('error', (meta && meta.title) ? meta.title : 'Производство', 'Ошибка сбора');
        return;
      }

      // Хранилища заполнены (есть что собирать, но места нет)
      if (payload && payload.collectBlocked === 'storage_full') {
        if (window.gameToast) window.gameToast('warning', (meta && meta.title) ? meta.title : 'Производство', 'Хранилища заполнены');
        return;
      }

      // Нечего собирать
      if (!payload || !payload.collectRes || !payload.collectAmt) {
        if (window.gameToast) window.gameToast('warning', (meta && meta.title) ? meta.title : 'Производство', 'Нечего собирать');
        return;
      }

      // Собрали ресурс (с иконкой)
      var amt = parseInt(payload.collectAmt, 10) || 0;
      if (amt > 0) {
        var icon = (meta && meta.icon) ? meta.icon : '';
        var gen = (meta && meta.gen) ? meta.gen : 'ресурса';
        var msg = '<span style="display:inline-flex;align-items:center;gap:6px;">'
          + (icon ? ('<img src="' + icon + '" style="width:16px;height:16px;object-fit:contain;vertical-align:middle;" alt="">') : '')
          + 'Собрано ' + gen + ': <b>+' + fmtNum(amt) + '</b></span>';
        if (window.gameToast) window.gameToast('success', (meta && meta.title) ? meta.title : 'Производство', msg);
      } else {
        if (window.gameToast) window.gameToast('warning', (meta && meta.title) ? meta.title : 'Производство', 'Нечего собирать');
      }
    } catch(e) {
      console.error(e);
      if (window.gameToast) window.gameToast('error', (meta && meta.title) ? meta.title : 'Производство', 'Ошибка соединения');
    } finally {
      window.__home_prod_collect_busy = false;
      setBusy(false);
    }
  };

  // Делегирование клика для кнопок ресурсов на главной (без inline JS)
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t) return;
    try {
      var btn = (t.closest ? t.closest('.prod-qc-btn') : null);
      if (!btn) return;
      var tp = btn.getAttribute('data-prod-collect');
      if (!tp) return;
      e.preventDefault();
      e.stopPropagation();
      if (window.homeProductionCollectAll) window.homeProductionCollectAll(tp);
    } catch(_e) {}
  }, true);
})();;

// ------------------ Оборона → Стены: показать/скрыть блок массового улучшения ------------------
(function(){
  if (window.__wallsBulkToggleBound) return;
  window.__wallsBulkToggleBound = true;

  function closest(el, sel){
    try { return el && el.closest ? el.closest(sel) : null; } catch(e){ return null; }
  }

  document.addEventListener('click', function(e){
    var t = e.target;
    var btn = closest(t, '[data-walls-bulk-toggle]');
    if (!btn) return;

    var action = (btn.getAttribute('data-walls-bulk-toggle') || '').trim();
    var panel = closest(btn, '.coc-panel');
    if (!panel) return;
    var body = panel.querySelector('[data-walls-bulk-body="1"]');
    if (!body) return;

    var bHide = panel.querySelector('[data-walls-bulk-toggle="hide"]');
    var bShow = panel.querySelector('[data-walls-bulk-toggle="show"]');

    if (action === 'hide') {
      body.style.display = 'none';
      if (bHide) bHide.style.display = 'none';
      if (bShow) bShow.style.display = '';
    } else if (action === 'show') {
      body.style.display = '';
      if (bHide) bHide.style.display = '';
      if (bShow) bShow.style.display = 'none';
    }

    e.preventDefault();
    e.stopPropagation();
  }, true);


// ------------------ Оборона → Стены: массовое/авто улучшение + подтверждения ------------------
(function(){
  if (window.__wallsUpgradeBound) return;
  window.__wallsUpgradeBound = true;

  function fmtNum(n){
    n = parseInt(n,10) || 0;
    return (''+n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }
  function clampPct(v){
    v = parseFloat(v);
    if (!isFinite(v)) v = 0;
    return Math.max(0, Math.min(100, v));
  }
  function getCsrf(){
    if (window.APP_CONFIG && window.APP_CONFIG.csrfToken) return window.APP_CONFIG.csrfToken;
    var meta = document.querySelector('meta[name="csrf_token"]');
    return meta ? meta.content : '';
  }
  function syncCsrfFromResponse(resp){
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
    } catch(e) {}
  }

  function setBalance(resKey, amount){
    amount = parseInt(amount,10) || 0;
    var textEl = document.getElementById('balance-' + resKey + '-text');
    if (textEl) textEl.textContent = fmtNum(amount);
    var barEl = document.getElementById('balance-' + resKey + '-bar');
    var caps = window.BALANCE_CAPS || {};
    var cap = parseInt(caps[resKey],10) || 0;
    if (barEl && cap > 0) {
      var pct = (amount / cap) * 100;
      if (amount > 0 && pct > 0 && pct < 1) pct = 1;
      barEl.style.width = clampPct(pct) + '%';
    }
  }

  function applyBalanceFromJson(balance){
    if (!balance) return;
    if (typeof balance.gold !== 'undefined') setBalance('gold', balance.gold);
    if (typeof balance.elixir !== 'undefined') setBalance('elixir', balance.elixir);
    if (typeof balance.dark_elixir !== 'undefined') setBalance('dark_elixir', balance.dark_elixir);
    if (typeof balance.gems !== 'undefined') setBalance('gems', balance.gems);
  }

  function resTitle(key){
    if (key === 'gold') return 'Золото';
    if (key === 'elixir') return 'Эликсир';
    if (key === 'dark_elixir') return 'Тёмный эликсир';
    return key;
  }
  function resIcon(key){
    if (key === 'gold') return '/images/icons/gold.png';
    if (key === 'elixir') return '/images/icons/elixir.png';
    if (key === 'dark_elixir') return '/images/icons/fuel.png';
    return '';
  }

  function parseWallLevelsFromDom(){
    var body = document.querySelector('[data-walls-bulk-body="1"][data-wall-levels]');
    if (!body) return null;
    var raw = body.getAttribute('data-wall-levels') || '';
    if (!raw) return null;
    try { return JSON.parse(raw); } catch(e) { return null; }
  }

  function normalizeResType(rt){
    var out = [];
    function normOne(x){
      x = (''+x).trim();
      if (!x) return null;
      var xl = x.toLowerCase();
      if (xl === 'gold' || xl === 'elixir' || xl === 'dark_elixir') return xl;
      if (xl === 'dark elixir') return 'dark_elixir';
      if (xl.indexOf('gold') !== -1) return 'gold';
      if (xl.indexOf('elixir') !== -1 && xl.indexOf('dark') === -1) return 'elixir';
      if (xl.indexOf('dark') !== -1 && xl.indexOf('elixir') !== -1) return 'dark_elixir';
      return null;
    }
    if (Array.isArray(rt)) {
      for (var i=0;i<rt.length;i++){
        var k = normOne(rt[i]);
        if (k && out.indexOf(k) === -1) out.push(k);
      }
    } else {
      var k2 = normOne(rt);
      if (k2) out.push(k2);
    }
    if (!out.length) out = ['gold'];
    return out;
  }

  function ensureElixirRule(allowedRes, toLevel){
    if (toLevel < 9) {
      allowedRes = allowedRes.filter(function(x){ return x !== 'elixir'; });
      if (!allowedRes.length) allowedRes = ['gold'];
    }
    return allowedRes;
  }

  function toast(kind, title, msg){
    if (window.gameToast) window.gameToast(kind, title, msg);
  }

  function updateBulkButtonsAvailability(){
    try {
      var levels = parseWallLevelsFromDom();
      if (!levels) return;
      var sel = document.getElementById('walls-bulk-level');
      if (!sel) return;
      var from = parseInt(sel.value,10) || 0;
      if (!from) return;
      var to = from + 1;
      var next = levels[String(to)] || levels[to];
      if (!next) return;
      var allowed = ensureElixirRule(normalizeResType(next.res_type || 'gold'), to);
      var btnElixir = document.querySelector('[data-walls-bulk="1"][data-res="elixir"]');
      var btnGold = document.querySelector('[data-walls-bulk="1"][data-res="gold"]');
      if (btnGold) btnGold.disabled = (allowed.indexOf('gold') === -1);
      if (btnElixir) {
        var ok = (allowed.indexOf('elixir') !== -1);
        btnElixir.disabled = !ok;
        btnElixir.style.opacity = ok ? '1' : '.45';
      }
    } catch(e) {}
  }

  function makeModal(title, html, onOk){
    var overlay = document.createElement('div');
    overlay.style.position = 'fixed';
    overlay.style.left = '0';
    overlay.style.top = '0';
    overlay.style.right = '0';
    overlay.style.bottom = '0';
    overlay.style.zIndex = '99999';
    overlay.style.background = 'rgba(0,0,0,.55)';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.padding = '12px';

    var box = document.createElement('div');
    box.className = 'coc-um-modal coc-um-modal--building';
    box.style.maxWidth = '520px';
    box.style.width = '100%';

    box.innerHTML = ''
      + '<div class="coc-um-top" style="padding:10px 12px;display:flex;align-items:center;gap:10px;">'
      + '  <div style="font-weight:900;flex:1;min-width:0;">' + title + '</div>'
      + '  <button type="button" class="coc-um-x" style="width:34px;height:34px;border-radius:10px;">×</button>'
      + '</div>'
      + '<div class="coc-um-body" style="padding:10px 12px 12px 12px;">'
      + '  <div class="coc-um-desc" style="font-size:12px;line-height:1.3;">' + html + '</div>'
      + '  <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap;">'
      + '    <button type="button" class="coc-um-btn" data-cancel="1" style="padding:8px 12px;">Отмена</button>'
      + '    <button type="button" class="coc-um-btn" data-ok="1" style="padding:8px 12px;font-weight:900;">Подтвердить</button>'
      + '  </div>'
      + '</div>';

    overlay.appendChild(box);
    document.body.appendChild(overlay);

    function close(){
      try { document.body.removeChild(overlay); } catch(e) {}
    }

    var x = box.querySelector('.coc-um-x');
    if (x) x.addEventListener('click', close);
    overlay.addEventListener('click', function(ev){ if (ev.target === overlay) close(); });
    var cancel = box.querySelector('[data-cancel="1"]');
    if (cancel) cancel.addEventListener('click', close);
    var ok = box.querySelector('[data-ok="1"]');
    if (ok) ok.addEventListener('click', async function(){
      if (ok.disabled) return;
      ok.disabled = true;
      try {
        if (onOk) await onOk();
        close();
      } catch(e) {
        ok.disabled = false;
        toast('error', 'Стены', e.message || 'Ошибка');
      }
    });

    return { close: close };
  }

  async function postJson(url, data){
    var body = new URLSearchParams();
    for (var k in data) body.append(k, data[k]);
    var resp = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
        'X-CSRF-Token': getCsrf()
      },
      body: body.toString()
    });
    syncCsrfFromResponse(resp);
    var js = await resp.json().catch(function(){ return null; });
    if (!resp.ok || !js || js.ok === false) {
      var msg = (js && js.error) ? js.error : 'Ошибка';
      throw new Error(msg);
    }
    return js;
  }

  function currentDefenseUrl(){
    return 'app/locations/defense.php';
  }

  // Bulk click
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t) return;

    // Walls V2 is handled inside js/locations/defense.js.
    // Keep legacy handler disabled to avoid double-confirm modals and "Не удалось прочитать конфиг стен".
    if (window.defenseWallsV2) return;
    var btn = t.closest ? t.closest('[data-walls-bulk="1"]') : null;
    if (!btn) return;

    var selLv = document.getElementById('walls-bulk-level');
    var selQty = document.getElementById('walls-bulk-qty');
    if (!selLv || !selQty) return;

    var from = parseInt(selLv.value,10) || 0;
    var qtyReq = selQty.value || '1';
    var res = (btn.getAttribute('data-res') || 'gold').trim();

    var levels = parseWallLevelsFromDom();
    if (!levels) { toast('error','Стены','Не удалось прочитать конфиг стен'); return; }
    var to = from + 1;
    var next = levels[String(to)] || levels[to];
    if (!next) { toast('warning','Стены','Этот уровень нельзя улучшить'); return; }

    var allowed = ensureElixirRule(normalizeResType(next.res_type || 'gold'), to);
    if (allowed.indexOf(res) === -1) { toast('warning','Стены','Этот ресурс недоступен для данного уровня'); return; }

    var costEach = parseInt(next.cost,10) || 0;
    if (costEach <= 0) { toast('error','Стены','Некорректная стоимость'); return; }

    var have = 0;
    try {
      var el = document.getElementById('balance-' + res + '-text');
      if (el) have = parseInt((el.textContent||'').replace(/[^0-9]/g,''),10) || 0;
    } catch(_e) {}
    var maxByRes = Math.floor(have / costEach);

    var qtyTxt = (qtyReq === '999999') ? 'Максимум' : qtyReq;
    var qn = (qtyReq === '999999') ? maxByRes : (parseInt(qtyReq,10)||1);
    var totalEst = qn * costEach;

    var icon = resIcon(res);
    var html = ''
      + '<div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">'
      + '  <div style="font-weight:900;">Улучшение стен</div>'
      + '  <div style="opacity:.9;">Ур. ' + from + ' → Ур. ' + to + '</div>'
      + '</div>'
      + '<div style="margin-top:8px;">Количество: <b>' + qtyTxt + '</b></div>'
      + '<div style="margin-top:6px;">Ресурс: <span style="display:inline-flex;gap:6px;align-items:center;">'
      + (icon ? ('<img src="' + icon + '" style="width:16px;height:16px;object-fit:contain;" alt="">') : '')
      + '<b>' + resTitle(res) + '</b></span></div>'
      + '<div style="margin-top:6px;">Стоимость за 1: <b>' + fmtNum(costEach) + '</b></div>'
      + '<div style="margin-top:6px;">Итого: <b>' + fmtNum(totalEst) + '</b></div>';

    makeModal('МАССОВОЕ УЛУЧШЕНИЕ', html, async function(){
      var js = await postJson(currentDefenseUrl(), { action: 'walls_bulk_upgrade', from_level: String(from), qty: String(qtyReq), res: res });
      applyBalanceFromJson(js.balance);
      var spent = parseInt(js.spent,10) || 0;
      var upg = parseInt(js.upgraded,10) || 0;
      var msg = '<span style="display:inline-flex;align-items:center;gap:6px;">'
        + (icon ? ('<img src="' + icon + '" style="width:16px;height:16px;object-fit:contain;" alt="">') : '')
        + 'Улучшено стен: <b>' + upg + '</b> (Ур. ' + from + '→' + (from+1) + '). Потрачено: <b>' + fmtNum(spent) + '</b></span>';
      toast('success','Стены', msg);
    });

    e.preventDefault();
    e.stopPropagation();
  }, true);

  // Auto click
  document.addEventListener('click', function(e){
    var t = e.target;
    if (!t) return;

    // Walls V2 is handled inside js/locations/defense.js.
    if (window.defenseWallsV2) return;
    var btn = t.closest ? t.closest('[data-walls-auto="1"]') : null;
    if (!btn) return;

    var prefSel = document.getElementById('walls-auto-pref');
    var pref = prefSel ? (prefSel.value || 'gold') : 'gold';

    (async function(){
      var preview = await postJson(currentDefenseUrl(), { action: 'walls_auto_preview', pref: pref });
      var up = parseInt(preview.upgraded,10) || 0;
      if (up <= 0) { toast('warning','Стены','Нечего улучшать или недостаточно ресурсов'); return; }

      var spent = preview.spent || {};
      var parts = [];
      if (spent.gold) parts.push('<span style="display:inline-flex;gap:6px;align-items:center;"><img src="/images/icons/gold.png" style="width:16px;height:16px;" alt="">' + fmtNum(spent.gold) + '</span>');
      if (spent.elixir) parts.push('<span style="display:inline-flex;gap:6px;align-items:center;"><img src="/images/icons/elixir.png" style="width:16px;height:16px;" alt="">' + fmtNum(spent.elixir) + '</span>');

      var steps = preview.steps || [];
      var listHtml = '';
      if (steps.length) {
        listHtml += '<div style="margin-top:10px;font-weight:900;">План улучшения:</div>';
        listHtml += '<div style="margin-top:6px;display:flex;flex-direction:column;gap:6px;">';
        for (var i=0;i<steps.length;i++) {
          var s = steps[i];
          var ic = resIcon(s.res);
          listHtml += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            + (ic ? ('<img src="' + ic + '" style="width:16px;height:16px;" alt="">') : '')
            + '<span>Ур. <b>' + s.from + '</b> → <b>' + s.to + '</b></span>'
            + '<span style="opacity:.9;">× <b>' + s.qty + '</b></span>'
            + '<span style="opacity:.9;">(за 1: ' + fmtNum(s.cost_each||0) + ')</span>'
            + '</div>';
        }
        listHtml += '</div>';
      }

      var html = ''
        + '<div>Будут улучшены стены максимально возможным образом, начиная с самых низких уровней.</div>'
        + '<div style="margin-top:6px;">Всего стен к улучшению: <b>' + up + '</b></div>'
        + '<div style="margin-top:6px;">Потрачено: <b>' + (parts.length ? parts.join(', ') : '0') + '</b></div>'
        + '<div style="margin-top:6px;">Предпочтение: <b>' + (pref === 'elixir' ? 'Эликсир' : 'Золото') + '</b></div>'
        + listHtml;

      makeModal('АВТО УЛУЧШЕНИЕ СТЕН', html, async function(){
        var js = await postJson(currentDefenseUrl(), { action: 'walls_auto_upgrade', pref: pref });
        applyBalanceFromJson(js.balance);
        var up2 = parseInt(js.upgraded,10) || 0;
        var sp = js.spent || {};
        var p2 = [];
        if (sp.gold) p2.push('<span style="display:inline-flex;gap:6px;align-items:center;"><img src="/images/icons/gold.png" style="width:16px;height:16px;" alt="">' + fmtNum(sp.gold) + '</span>');
        if (sp.elixir) p2.push('<span style="display:inline-flex;gap:6px;align-items:center;"><img src="/images/icons/elixir.png" style="width:16px;height:16px;" alt="">' + fmtNum(sp.elixir) + '</span>');
        toast('success','Стены','Улучшено стен: <b>' + up2 + '</b>. Потрачено: ' + (p2.length ? p2.join(', ') : '0'));
      });
    })().catch(function(err){
      toast('error','Стены', err && err.message ? err.message : 'Ошибка');
    });

    e.preventDefault();
    e.stopPropagation();
  }, true);

  document.addEventListener('change', function(e){
    var t = e.target;
    if (!t) return;
    if (t.id === 'walls-bulk-level') updateBulkButtonsAvailability();
  }, true);

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', updateBulkButtonsAvailability);
  else updateBulkButtonsAvailability();
})();
})();

document.addEventListener('DOMContentLoaded', () => {
    const app = document.getElementById('app');
    const loader = document.getElementById('loader');

    if (!app || !loader) {
        console.error('Не найден элемент #app или #loader');
        return;
    }

    let currentPage = 'home';
    let isNavigationInProgress = false;

    const csrfMetaTag = document.querySelector('meta[name="csrf_token"]');
    let csrfToken = csrfMetaTag ? csrfMetaTag.content : '';

    loadPage(currentPage);

    document.addEventListener('click', async (e) => {
        const pageBtn = e.target.closest('[data-page]');
        const logoutBtn = e.target.closest('.logout-btn');

        if (pageBtn && !isNavigationInProgress) {
            e.preventDefault();
            const targetPage = pageBtn.dataset.page;
            if (targetPage !== currentPage) {
                currentPage = targetPage;
                await loadPage(targetPage);
            }
        }

        if (logoutBtn) {
            e.preventDefault();
            await handleLogout();
        }
    });

    async function loadPage(page) {
        if (isNavigationInProgress) return;
        isNavigationInProgress = true;

        try {
            showLoader();

            const response = await fetch(`ajax.php?page=${encodeURIComponent(page)}&r=${Date.now()}`, {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html',
                    'X-CSRF-Token': csrfToken
                }
            });

            if (!response.ok) {
                throw await handleErrorResponse(response);
            }

            const newToken = response.headers.get('X-CSRF-Token');
            if (newToken) updateCsrfToken(newToken);

            const content = await response.text();
            app.innerHTML = content;

        } catch (error) {
            console.error('Ошибка загрузки:', error);
            showError(error);
            if (error.isAuthError) await redirectToLogin();
        } finally {
            hideLoader();
            isNavigationInProgress = false;
        }
    }

    async function handleLogout() {
        if (isNavigationInProgress) return;
        isNavigationInProgress = true;

        try {
            showLoader();

            const response = await fetch('logout.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': csrfToken
                },
                body: `csrf_token=${encodeURIComponent(csrfToken)}`
            });

            if (!response.ok) {
                throw new Error('Ошибка при выходе');
            }

            window.location.href = 'login.php';

        } catch (error) {
            console.error('Ошибка выхода:', error);
            showError(error);
        } finally {
            hideLoader();
            isNavigationInProgress = false;
        }
    }

    async function handleErrorResponse(response) {
        const error = new Error();

        switch (response.status) {
            case 401:
                error.message = 'Требуется авторизация';
                error.isAuthError = true;
                break;
            case 403:
                error.message = 'Доступ запрещен';
                error.isAuthError = true;
                break;
            case 500:
                error.message = 'Ошибка сервера';
                break;
            default:
                error.message = `Ошибка загрузки: ${response.status}`;
        }

        try {
            const data = await response.json();
            if (data.error) error.message = data.error;
            if (data.details) error.details = data.details;
        } catch (e) {
            // Не JSON-ответ
        }

        return error;
    }

    function updateCsrfToken(newToken) {
        csrfToken = newToken;
        if (window.APP_CONFIG) {
            window.APP_CONFIG.csrfToken = newToken;
        }
        let meta = document.querySelector('meta[name="csrf_token"]');
        if (!meta) {
            meta = document.createElement('meta');
            meta.name = 'csrf_token';
            document.head.appendChild(meta);
        }
        meta.content = newToken;
    }

    function showError(error) {
        const errorHtml = `
            <div class="error">
                <h3>❌ ${error.message}</h3>
                ${ENVIRONMENT === 'development' && error.details ? `<pre>${JSON.stringify(error.details, null, 2)}</pre>` : ''}
                <button class="btn retry-btn">Повторить</button>
            </div>
        `;

        app.innerHTML = errorHtml;

        const retryBtn = app.querySelector('.retry-btn');
        if (retryBtn) {
            retryBtn.addEventListener('click', () => {
                loadPage(currentPage);
            });
        }
    }

    async function redirectToLogin() {
        sessionStorage.setItem('returnUrl', window.location.href);
        window.location.href = 'login.php';
    }

    function showLoader() {
        loader.style.display = 'flex';
        app.style.opacity = '0.5';
        app.style.pointerEvents = 'none';
    }

    function hideLoader() {
        loader.style.display = 'none';
        app.style.opacity = '1';
        app.style.pointerEvents = 'auto';
    }
});


// ------------------ Resource capacity popover (CoC-like) ------------------
(function(){
  var pop = null;

  function fmtNum(n){
    n = parseInt(n, 10);
    if (!isFinite(n)) n = 0;
    return (''+n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
  }

  function ensurePopover(){
    if (pop) return pop;
    pop = document.createElement('div');
    pop.id = 'resourceInfoPopover';
    pop.className = 'resource-popover hidden';
    document.body.appendChild(pop);
    return pop;
  }

  function hidePopover(){
    if (!pop) return;
    pop.classList.add('hidden');
    pop.innerHTML = '';
  }

  function getResKeyFromBalanceEl(balanceEl){
    if (!balanceEl || !balanceEl.classList) return null;
    if (balanceEl.classList.contains('gold')) return 'gold';
    if (balanceEl.classList.contains('elixir')) return 'elixir';
    if (balanceEl.classList.contains('dark-elixir')) return 'dark_elixir';
    if (balanceEl.classList.contains('gems')) return 'gems';
    return null;
  }

  function getResTitle(key){
    if (key === 'gold') return 'Золото';
    if (key === 'elixir') return 'Эликсир';
    if (key === 'dark_elixir') return 'Чёрный эликсир';
    if (key === 'gems') return 'Кристаллы';
    return 'Ресурс';
  }

  function getResIconSrc(balanceEl){
    try {
      var img = balanceEl ? balanceEl.querySelector('img') : null;
      return img ? img.getAttribute('src') : '';
    } catch(e){ return ''; }
  }

  function parseAmountText(id){
    var el = document.getElementById(id);
    if (!el) return 0;
    var t = (el.textContent || '').replace(/\s+/g, '').replace(/,/g,'');
    var v = parseInt(t, 10);
    return isFinite(v) ? v : 0;
  }

  function getAmount(key){
    if (key === 'gold') return parseAmountText('balance-gold-text');
    if (key === 'elixir') return parseAmountText('balance-elixir-text');
    if (key === 'dark_elixir') return parseAmountText('balance-dark_elixir-text');
    if (key === 'gems') return parseAmountText('balance-gems-text');
    return 0;
  }

  function getCap(key){
    try {
      if (window.BALANCE_CAPS && typeof window.BALANCE_CAPS === 'object' && window.BALANCE_CAPS[key] != null) {
        var v = parseInt(window.BALANCE_CAPS[key], 10);
        return isFinite(v) ? v : 0;
      }
    } catch(e) {}
    return 0;
  }

  function showPopover(key, balanceEl, anchorEl){
    var p = ensurePopover();
    var title = getResTitle(key);
    var iconSrc = getResIconSrc(balanceEl);
    var cap = getCap(key);
    var amt = getAmount(key);

    var capText = (cap > 0) ? fmtNum(cap) : '—';
    var amtText = fmtNum(amt);

    p.innerHTML = ''
      + '<div class="res-pop-title">'
      + (iconSrc ? ('<img src="' + iconSrc + '" alt="">') : '')
      + '<div>' + title + '</div>'
      + '</div>'
      + '<div class="res-pop-row"><span>Вместимость</span><span>' + capText + '</span></div>'
      + '<div class="res-pop-row"><span>Сейчас</span><span>' + amtText + '</span></div>';

    p.classList.remove('hidden');

    try {
      var r = (anchorEl || balanceEl).getBoundingClientRect();
      var x = r.left + r.width/2;
      var y = r.bottom + 8;

      // clamp inside viewport
      var vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
      var vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);

      p.style.left = '0px';
      p.style.top = '0px';
      p.style.transform = 'translate(-50%, 0)';

      // after paint, measure and clamp
      requestAnimationFrame(function(){
        try {
          var pr = p.getBoundingClientRect();
          var left = x;
          var top = y;

          // keep within
          if (left - pr.width/2 < 8) left = pr.width/2 + 8;
          if (left + pr.width/2 > vw - 8) left = vw - pr.width/2 - 8;
          if (top + pr.height > vh - 8) top = Math.max(8, r.top - pr.height - 8);

          p.style.left = left + 'px';
          p.style.top = top + 'px';
          p.style.transform = 'translate(-50%, 0)';
        } catch(e){}
      });
    } catch(e){}
  }

  // Delegate clicks (SPA-safe)
  document.addEventListener('click', function(e){
    try {
      var img = e.target && e.target.closest ? e.target.closest('.balance img') : null;
      if (!img) { hidePopover(); return; }
      var balanceEl = img.closest('.balance');
      var key = getResKeyFromBalanceEl(balanceEl);
      if (!key) return;
      // показываем только для ресурсов с вместимостью (золото/эликсир/чэ)
      if (key === 'gems') { hidePopover(); return; }

      showPopover(key, balanceEl, img);
      e.preventDefault();
      e.stopPropagation();
    } catch(err){
      hidePopover();
    }
  }, true);

  window.addEventListener('scroll', hidePopover, true);
  window.addEventListener('resize', hidePopover);
})();
