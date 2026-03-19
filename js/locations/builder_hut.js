(function(){
  var MODAL_ID = 'builder_hut-modal';
  var CONTENT_ID = 'builder_hut-modal-content';
  var ENDPOINT = 'ajax.php?page=builder_hut';
  var TITLE = 'ХИЖИНА СТРОИТЕЛЯ';

  // Один таймер на модалку (иначе при переходах/перезагрузках плодятся интервалы)
  var countdownInterval = null;

  // --- Balance helpers (обновление верхней панели + эффект +/-) ---
  function fmtNum(n){
    n = parseInt(n||0,10) || 0;
    return (''+n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function getBalanceTextEl(resKey){
    var id = 'balance-' + resKey + '-text';
    return document.getElementById(id);
  }

  function getBalanceBarEl(resKey){
    var id = 'balance-' + resKey + '-bar';
    return document.getElementById(id);
  }

  function setBalance(resKey, value, cap){
    var t = getBalanceTextEl(resKey);
    if (t) t.textContent = fmtNum(value);
    var bar = getBalanceBarEl(resKey);
    if (bar) {
      var pct = 0;
      cap = parseInt(cap||0,10) || 0;
      if (cap > 0) pct = Math.max(0, Math.min(100, Math.floor((value / cap) * 100)));
      bar.style.width = pct + '%';
    }
    if (t && t.classList) {
      t.classList.remove('balance-change');
      // reflow
      void t.offsetWidth;
      t.classList.add('balance-change');
    }
  }

  function showBalanceSpend(resKey, delta){
    var el = getBalanceTextEl(resKey);
    if (!el || !el.animate) return;
    var fly = document.createElement('div');
    fly.textContent = '' + delta;
    fly.style.position = 'absolute';
    fly.style.right = '0';
    fly.style.top = '-6px';
    fly.style.fontSize = '12px';
    fly.style.fontWeight = '700';
    fly.style.color = '#ff6b6b';
    fly.style.pointerEvents = 'none';
    fly.style.opacity = '1';
    el.style.position = 'relative';
    el.appendChild(fly);
    fly.animate([
      { transform:'translateY(0)', opacity: 1 },
      { transform:'translateY(-14px)', opacity: 0 }
    ], { duration: 900, easing: 'ease-out' }).onfinish = function(){ try{ fly.remove(); }catch(e){} };
  }

  function showBalanceGain(resKey, delta){
    var el = getBalanceTextEl(resKey);
    if (!el || !el.animate) return;
    var fly = document.createElement('div');
    fly.textContent = '+' + delta;
    fly.style.position = 'absolute';
    fly.style.right = '0';
    fly.style.top = '-6px';
    fly.style.fontSize = '12px';
    fly.style.fontWeight = '700';
    fly.style.color = '#7CFF7C';
    fly.style.pointerEvents = 'none';
    fly.style.opacity = '1';
    el.style.position = 'relative';
    el.appendChild(fly);
    fly.animate([
      { transform:'translateY(0)', opacity: 1 },
      { transform:'translateY(-14px)', opacity: 0 }
    ], { duration: 900, easing: 'ease-out' }).onfinish = function(){ try{ fly.remove(); }catch(e){} };
  }

  function showBalanceDelta(resKey, delta){
    delta = parseInt(delta||0,10) || 0;
    if (!delta) return;
    if (delta > 0) showBalanceGain(resKey, delta);
    else showBalanceSpend(resKey, delta);
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

    setBalance('gold', gold, capGold);
    setBalance('elixir', elixir, capElixir);
    setBalance('dark_elixir', dark, capDark);
    setBalance('gems', gems, 0);

    var dr = (p.getAttribute('data-delta_res')||'').trim();
    var da = parseInt(p.getAttribute('data-delta_amt')||'0',10) || 0;
    if (dr && da) showBalanceDelta(dr, da);
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

  function initCountdown(root){
    if (countdownInterval) {
      clearInterval(countdownInterval);
      countdownInterval = null;
    }
    if (!root) return;
    var nodes = root.querySelectorAll('.js-countdown[data-finish]');
    if (!nodes || !nodes.length) return;

    function fmt(sec){
      sec = Math.max(0, parseInt(sec, 10) || 0);
      var d = Math.floor(sec / 86400); sec -= d * 86400;
      var h = Math.floor(sec / 3600); sec -= h * 3600;
      var m = Math.floor(sec / 60); var s = sec - m * 60;
      if (d > 0) return d + 'д ' + h + 'ч';
      if (h > 0) return h + 'ч ' + m + 'м';
      if (m > 0) return m + 'м ' + s + 'с';
      return s + 'с';
    }

    function tick(){
      var now = Math.floor(Date.now() / 1000);
      var list = root.querySelectorAll('.js-countdown[data-finish]');
      for (var i = 0; i < list.length; i++) {
        var el = list[i];
        var finish = parseInt(el.getAttribute('data-finish') || '0', 10) || 0;
        var left = Math.max(0, finish - now);
        el.textContent = fmt(left);
      }
    }

    tick();
    countdownInterval = setInterval(tick, 1000);
  }

  
  function bindBuildSpeedups(root){
    if (!root) root = document;
    if (root.__buildSpeedupBound) return;
    root.__buildSpeedupBound = true;

    root.addEventListener('click', function(ev){
      var btn = ev.target && (ev.target.closest ? ev.target.closest('[data-buildspeedup-id]') : null);
      if (!btn) return;
      ev.preventDefault();
      ev.stopPropagation();

      var id = parseInt(btn.getAttribute('data-buildspeedup-id'),10) || 0;
      var cost = parseInt(btn.getAttribute('data-buildspeedup-cost'),10) || 0;
      if (!id) return;

      var ask = window.cocConfirm
        ? window.cocConfirm({ title:'УСКОРЕНИЕ', message:'Ускорить улучшение/стройку?', cost: cost, resIcon:'/images/icons/gems.png', okText:'Да', cancelText:'Отмена' })
        : Promise.resolve(confirm('Ускорить? Стоимость: ' + cost + ' gems'));

      btn.disabled = true;
      ask.then(function(ok){
        if (!ok){ btn.disabled = false; return; }
        // do request
        var token = getCsrf();
        var body = 'action=building_speedup&player_building_id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(token);
        return fetch(ENDPOINT, {
          method:'POST',
          credentials:'include',
          headers:{ 'Content-Type':'application/x-www-form-urlencoded' },
          body: body
        }).then(function(r){
          syncCsrfFromResponse(r);
          return r.json().catch(function(){ return null; }).then(function(j){
            if (!r.ok || !j || j.ok === false){
              var msg = (j && (j.message || j.error)) ? (j.message || j.error) : ('HTTP ' + r.status);
              throw new Error(msg);
            }
            return j;
          });
        }).then(function(j){
          // Refresh modal content to update statuses and timers without page reload
          requestGet('main');
        }).catch(function(err){
          alert('Ошибка: ' + (err && err.message ? err.message : err));
        }).finally(function(){ btn.disabled = false; });
      }).catch(function(){ btn.disabled = false; });
    }, true);
  }

function ensureEls(){
    var modal = document.getElementById(MODAL_ID);
    var cont = document.getElementById(CONTENT_ID);
    if (!modal || !cont) {
      console.error('Builder Hut modal container not found');
      return null;
    }
    return { modal: modal, cont: cont };
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

  function openModal(scrollY){
    var els = ensureEls();
    if (!els) return;
    els.modal.classList.add('active');
    if (typeof scrollY === 'number') window.scrollTo(0, scrollY);
  }

  function requestGet(view){
    var els = ensureEls();
    if (!els) return;
    showLoader(els.cont);

    var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
    var url = ENDPOINT + '&view=' + encodeURIComponent(view || 'main') + '&r=' + Date.now();

    fetch(url, {
      method: 'GET',
      credentials: 'include',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'text/html'
      }
    }).then(function(r){
      if (!r.ok) return r.text().then(function(t){ throw new Error('HTTP ' + r.status + ': ' + t); });
      syncCsrfFromResponse(r);
      return r.text();
    }).then(function(html){
      els.cont.innerHTML = html;
      // Мгновенно обновляем баланс по пэйлоаду из ответа
      applyBalancePayload(els.cont);
      initCountdown(els.cont);
      bindBuildSpeedups(els.cont);
      openModal(scrollY);
    }).catch(function(err){
      console.error(err);
      showError(els.cont, 'Ошибка загрузки: ' + (err && err.message ? err.message : err));
      openModal(scrollY);
    });
  }

  function requestPost(view, body){
    var els = ensureEls();
    if (!els) return;
    showLoader(els.cont);

    var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
    var url = ENDPOINT + '&view=' + encodeURIComponent(view || 'main') + '&r=' + Date.now();

    fetch(url, {
      method: 'POST',
      credentials: 'include',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'text/html'
      },
      body: body || ''
    }).then(function(r){
      if (!r.ok) return r.text().then(function(t){ throw new Error('HTTP ' + r.status + ': ' + t); });
      syncCsrfFromResponse(r);
      return r.text();
    }).then(function(html){
      els.cont.innerHTML = html;
      // Мгновенно обновляем баланс по пэйлоаду из ответа
      applyBalancePayload(els.cont);
      initCountdown(els.cont);
      bindBuildSpeedups(els.cont);
      openModal(scrollY);
    }).catch(function(err){
      console.error(err);
      showError(els.cont, 'Ошибка: ' + (err && err.message ? err.message : err));
      openModal(scrollY);
    });
  }

  // Public API
  window.showBuilderHutModal = function(view){
    requestGet(view || 'main');
  };

  window.builderHutHireBuilder = function(){
    var token = getCsrf();
    requestPost('hire', 'csrf_token=' + encodeURIComponent(token));
  };
})();
