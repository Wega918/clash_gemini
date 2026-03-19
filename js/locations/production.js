(function(){
  var MODAL_ID = 'production-modal';
  var CONTENT_ID = 'production-modal-content';
  var ENDPOINT = 'ajax.php?page=production';
  var TITLE = 'ПРОИЗВОДСТВО';

  var historyStack = [];
  var currentState = {view: 'main', type: '', id: 0};
  var progressInterval = null;
  var pendingRefresh = false;

  function formatLeft(s){
    s = Math.max(0, parseInt(s, 10) || 0);
    var d = Math.floor(s / 86400); s -= d*86400;
    var h = Math.floor(s / 3600); s -= h*3600;
    var m = Math.floor(s / 60); var sec = s - m*60;
    if (d > 0) return d + 'д ' + h + 'ч';
    if (h > 0) return h + 'ч ' + m + 'м';
    if (m > 0) return m + 'м ' + sec + 'с';
    return sec + 'с';
  }

  function startProgressUpdater(root){
    if (progressInterval) { clearInterval(progressInterval); progressInterval = null; }
    if (!root) return;
    var nodes = root.querySelectorAll('.js-upgrade-progress');
    if (!nodes || !nodes.length) return;

    function tick(){
      var now = Math.floor(Date.now()/1000);
      var anyDone = false;
      for (var i=0; i<nodes.length; i++) {
        var el = nodes[i];
        var st = parseInt(el.getAttribute('data-start')||'0',10) || 0;
        var en = parseInt(el.getAttribute('data-end')||'0',10) || 0;
        var dur = Math.max(1, en - st);
        var p = Math.max(0, Math.min(1, (now - st) / dur));
        var left = Math.max(0, en - now);
        var fill = el.querySelector('.upgrade-progress-fill');
        if (fill) fill.style.width = Math.round(p*100) + '%';
        var leftEl = el.querySelector('.upgrade-left');
        if (leftEl) leftEl.textContent = (left <= 0 ? 'Готово' : ('⏳ ' + formatLeft(left)));
        var perEl = el.querySelector('.upgrade-percent');
        if (perEl) perEl.textContent = Math.round(p*100) + '%';
        if (left <= 0) anyDone = true;
      }
      if (anyDone && !pendingRefresh) {
        pendingRefresh = true;
        setTimeout(function(){
          pendingRefresh = false;
          var v = currentState.view;
          // не повторяем экшен-вьюхи (upgrade/collect/buy), чтобы не ловить 500 после завершения
          if (v === 'upgrade' || v === 'collect' || v === 'buy') {
            v = currentState.id ? 'detail' : 'list';
          }
          var t = (v === 'list') ? (currentState.type||'') : '';
          var id = (v === 'detail') ? (currentState.id||0) : 0;
          loadView(v, t, id, false);
        }, 800);
      }
    }

    tick();
    progressInterval = setInterval(tick, 1000);
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


function fmtNum(n){
  n = parseInt(n, 10) || 0;
  return (''+n).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
}


function toast(type, title, msg){
  try {
    if (!window.gameToast) return;
    if (type === 'warn') type = 'warning';
    window.gameToast(type, title, msg);
  } catch(e) {}
}

function clampPct(v){
  v = parseFloat(v);
  if (!isFinite(v)) v = 0;
  return Math.max(0, Math.min(100, v));
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

function showBalanceGain(resKey, amount){
  amount = parseInt(amount, 10) || 0;
  if (amount <= 0) return;

  var anchor = document.getElementById('balance-' + resKey + '-text');
  if (!anchor) return;

  // 1) Одна пульсация ресурса
  var pulseTarget = anchor;
  try {
    // если есть контейнер — пульсируем его (выглядит лучше)
    if (anchor.closest) {
      pulseTarget = anchor.closest('.balance-item') || anchor.closest('.resource-item') || anchor.parentElement || anchor;
    }
  } catch(e) {}
  if (pulseTarget && pulseTarget.animate) {
    pulseTarget.animate(
      [
        { transform: 'scale(1)' },
        { transform: 'scale(1.08)' },
        { transform: 'scale(1)' }
      ],
      { duration: 420, easing: 'ease-out' }
    );
  }

  // 2) Вылетает +N из ресурса (как раньше)
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

function showBalanceSpend(resKey, amount){
  // amount отрицательное (строкой), например "-500"
  var el = getBalanceEl(resKey);
  if (!el) return;
  var fly = document.createElement('div');
  fly.textContent = amount;
  fly.style.position = 'absolute';
  fly.style.right = '0';
  fly.style.top = '-6px';
  fly.style.fontSize = '12px';
  fly.style.fontWeight = '700';
  fly.style.color = '#ff6b6b';
  fly.style.pointerEvents = 'none';
  fly.style.transform = 'translateY(0)';
  fly.style.opacity = '1';
  el.style.position = 'relative';
  el.appendChild(fly);
  fly.animate([
    { transform:'translateY(0)', opacity: 1 },
    { transform:'translateY(-14px)', opacity: 0 }
  ], { duration: 900, easing: 'ease-out' }).onfinish = function(){ fly.remove(); };

  el.animate([
    { transform:'scale(1)' },
    { transform:'scale(0.96)' },
    { transform:'scale(1)' }
  ], { duration: 320, easing: 'ease-out' });
}

function showBalanceDelta(resKey, delta){
  if (!delta || delta === 0) return;
  if (delta > 0) showBalanceGain(resKey, '+' + delta);
  else showBalanceSpend(resKey, '' + delta);
}

function applyBalancePayload(root){
  if (!root) return;
  var p = root.querySelector('.js-balance-payload');
  if (!p) return;
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

  if (cr && ca > 0) showBalanceGain(cr, ca);

  // Уведомления для ручного "Собрать все" (внутри списка производства)
  if (currentState && currentState.view === 'collect_all') {
    if (typeof toast === 'function') {
      if (blocked === 'storage_full') {
        toast('warn', 'Сбор', 'Хранилища заполнены');
      } else if (cr && ca > 0) {
        var resName = 'ресурса';
        if (cr === 'gold') resName = 'золота';
        else if (cr === 'elixir') resName = 'эликсира';
        else if (cr === 'dark_elixir') resName = 'ЧЭ';
        toast('success', 'Сбор', 'Собрано +' + fmtNum(ca) + ' ' + resName);
      } else {
        toast('warn', 'Сбор', 'Нечего собирать');
      }
    }
  }
}

  function ensureEls(){
    var modal = document.getElementById(MODAL_ID);
    var cont = document.getElementById(CONTENT_ID);
    if (!modal || !cont) {
      console.error('Production modal container not found');
      return null;
    }
    return {modal: modal, cont: cont};
  }
  function showLoader(cont){
    cont.innerHTML = ''
      + '<div class="modal-header-controls">'
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'' + MODAL_ID + '\')">'
      + '<img src="/images/icons/close.png" alt="Закрыть"></button>'
      + '<div class="modal-title-bar"><h2 class="modal-title-text-inside-panel">' + TITLE + '</h2></div>'
      + '</div>'
      + '<div class="modal-body-custom">'
      + '<div class="modal-loader"><div class="loader-spinner"></div><div class="loader-text">Загрузка…</div></div>'
      + '</div>';
  }
  function showError(cont, msg){
    cont.innerHTML = ''
      + '<div class="modal-header-controls">'
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'' + MODAL_ID + '\')">'
      + '<img src="/images/icons/close.png" alt="Закрыть"></button>'
      + '<div class="modal-title-bar"><h2 class="modal-title-text-inside-panel">' + TITLE + '</h2></div>'
      + '</div>'
      + '<div class="modal-body-custom">'
      + '<div class="error" style="margin:20px;">❌ ' + msg + '</div>'
      + '</div>';
  }

  function request(params, onOk){
    var els = ensureEls();
    if (!els) return;
    var prevHtml = els.cont.innerHTML;
    showLoader(els.cont);
    var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;

    var url = ENDPOINT;
    if (params && typeof params === 'string') {
      url += (params.charAt(0) === '&' ? params : '&' + params);
    }
    url += (url.indexOf('?') === -1 ? '?' : '&') + 'r=' + Date.now();

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'text/html'
      }
    }).then(function(r){
      syncCsrfFromResponse(r);
      return r.text().then(function(html){
        return { ok: r.ok, status: r.status, html: html };
      });
    }).then(function(res){
      // На 4xx (игровые ошибки) не перерисовываем модалку: показываем уведомление и возвращаем прошлый контент
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
        } else {
          // если контента ещё не было (первое открытие) — покажем ответ как есть
          els.cont.innerHTML = res.html;
          applyBalancePayload(els.cont);
          startProgressUpdater(els.cont);
          if (window.gameHandleActionError) window.gameHandleActionError(els.cont);
        }
        if (typeof onOk === 'function') onOk(scrollY, res);
        return;
      }

      els.cont.innerHTML = res.html;
      applyBalancePayload(els.cont);
      startProgressUpdater(els.cont);
      if (window.gameHandleActionError) window.gameHandleActionError(els.cont);
      if (typeof onOk === 'function') onOk(scrollY, res);
    }).catch(function(err){
      console.error(err);
      showError(els.cont, 'Ошибка загрузки: ' + (err && err.message ? err.message : err));
      openModal(scrollY);
    });
  }

  function openModal(scrollY){
    var els = ensureEls();
    if (!els) return;
    els.modal.classList.add('active');
    if (typeof scrollY === 'number') {
      window.scrollTo(0, scrollY);
    }
  }

  function loadView(view, type, id, push){
    currentState = {view: view || 'main', type: type || '', id: id || 0};
    var q = 'view=' + encodeURIComponent(view || 'main');
    if (type) q += '&type=' + encodeURIComponent(type);
    if (id) q += '&id=' + encodeURIComponent(id);

    if (push) {
      historyStack.push({view: view || 'main', type: type || '', id: id || 0});
    }

    request('&' + q, function(scrollY){
      openModal(scrollY);
    });
  }

  window.showProductionModal = function(view){
    historyStack = [];
    loadView(view || 'main', '', 0, false);
  };

  window.productionLoadList = function(modalId, buildingType){
    loadView('list', buildingType, 0, true);
  };

  window.productionLoadDetail = function(modalId, buildingRowId){
    loadView('detail', '', buildingRowId, true);
  };

  window.productionGoBack = function(modalId, defaultView){
    if (historyStack.length > 0) historyStack.pop();
    var prev = historyStack.length ? historyStack[historyStack.length - 1] : null;
    if (!prev) {
      loadView(defaultView || 'main', '', 0, false);
      return;
    }
    loadView(prev.view, prev.type, prev.id, false);
  };

  window.productionCollectResource = function(modalId, buildingRowId){
    loadView('collect', '', buildingRowId, false);
  };

  // Собрать всё в текущем разделе (золотые шахты / сборщики / скважины)
  window.productionCollectAll = function(modalId, buildingType){
    if (!buildingType) return;
    loadView('collect_all', buildingType, 0, false);
  };

  // Быстрый сбор с главной вкладки "Производство" (иконки над заголовком)
  window.productionCollectAllMain = function(buildingType){
    if (!buildingType) return;
    currentState = {view: 'main', type: '', id: 0};
    request('&view=collect_all&type=' + encodeURIComponent(buildingType) + '&return=main', function(scrollY){
      openModal(scrollY);
    });
  };

  window.productionStartUpgrade = function(modalId, buildingRowId){
    loadView('upgrade', '', buildingRowId, false);
  };

  window.productionStartBuilding = function(modalId, buildingType){
    loadView('buy', buildingType, 0, false);
  };
})();
