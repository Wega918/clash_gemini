/**
 * modal_loader.js
 * Универсальная загрузка модальных окон деревни (оборот/казармы/лаба/клан/ратуша).
 *
 * Для Хранилищ/Производства продолжает использоваться storage_modal.js.
 */

(function() {
  const csrfToken = (typeof window.APP_CONFIG !== 'undefined' && window.APP_CONFIG.csrfToken)
    ? window.APP_CONFIG.csrfToken
    : (document.querySelector('meta[name="csrf_token"]') ? document.querySelector('meta[name="csrf_token"]').content : '');

  // отдельная история для каждого модального окна
  const historyMap = {};

  function getHistory(modalId) {
    if (!historyMap[modalId]) historyMap[modalId] = [];
    return historyMap[modalId];
  }

  function setLoading(modalContent) {
    modalContent.innerHTML = `<div style="text-align:center; padding: 40px;"><div class="loader-spinner"></div> Загрузка...</div>`;
  }

function initCountdown(root) {
    // Обновляет все элементы .js-countdown[data-finish]
    if (!root) root = document;
    const nodes = root.querySelectorAll('.js-countdown[data-finish]');
    if (!nodes || !nodes.length) return;

    function fmt(sec) {
      sec = Math.max(0, parseInt(sec, 10) || 0);
      const h = Math.floor(sec / 3600);
      const m = Math.floor((sec % 3600) / 60);
      const s = sec % 60;
      if (h > 0) return `${h}ч ${m}м ${s}с`;
      if (m > 0) return `${m}м ${s}с`;
      return `${s}с`;
    }

    // Один интервал на root (не плодим тысячи)
    if (root.__countdownInterval) return;
    root.__countdownInterval = setInterval(() => {
      const list = root.querySelectorAll('.js-countdown[data-finish]');
      const now = Math.floor(Date.now() / 1000);
      list.forEach(el => {
        const finish = parseInt(el.getAttribute('data-finish'), 10) || 0;
        const left = Math.max(0, finish - now);
        el.textContent = fmt(left);
      });
    }, 1000);
  }


  // --- CoC-like confirmation modal (Promise) ---
  // Usage:
  //   window.cocConfirm({ title:'Ускорить?', message:'...', cost: 12, resIcon:'/images/icons/gems.png', okText:'Да', cancelText:'Отмена' })
  //     .then(ok => { if (ok) ... });
  (function(){
    if (window.cocConfirm) return;

    function ensureStyles(){
      if (document.getElementById('coc-confirm-style')) return;
      const st = document.createElement('style');
      st.id = 'coc-confirm-style';
      st.textContent = `
        .coc-confirm-overlay{
          position:fixed; inset:0; background:rgba(0,0,0,.55);
          display:flex; align-items:center; justify-content:center;
          z-index:99999;
        }
        .coc-confirm-box{
          width:min(420px, 92vw);
          border-radius:16px;
          background:linear-gradient(#f3e6c7, #d2b583);
          box-shadow:0 14px 40px rgba(0,0,0,.45);
          border:2px solid rgba(255,255,255,.55);
          overflow:hidden;
          font-family: inherit;
        }
        .coc-confirm-head{
          padding:14px 18px;
          background:linear-gradient(#6c4b2a, #4a2f18);
          color:#fff;
          font-weight:900;
          letter-spacing:.5px;
          text-shadow:0 2px 0 rgba(0,0,0,.35);
          display:flex; align-items:center; justify-content:center;
          position:relative;
        }
        .coc-confirm-body{
          padding:16px 18px 14px;
          color:#2a1a10;
          font-weight:700;
          text-align:center;
        }
        .coc-confirm-cost{
          margin-top:10px;
          display:flex; gap:10px; align-items:center; justify-content:center;
          background:rgba(255,255,255,.55);
          border-radius:12px;
          padding:10px 12px;
          font-weight:900;
        }
        .coc-confirm-cost img{
          width:22px; height:22px; image-rendering:auto;
        }
        .coc-confirm-actions{
          display:flex; gap:10px; padding:14px 16px 16px;
          justify-content:center;
          background:rgba(0,0,0,.05);
        }
        .coc-confirm-btn{
          min-width:140px;
          border:none;
          border-radius:14px;
          padding:12px 16px;
          font-weight:1000;
          cursor:pointer;
          text-transform:uppercase;
          letter-spacing:.6px;
          box-shadow:0 6px 0 rgba(0,0,0,.22);
        }
        .coc-confirm-btn:active{ transform:translateY(2px); box-shadow:0 4px 0 rgba(0,0,0,.22); }
        .coc-confirm-btn-ok{
          background:linear-gradient(#8be65a, #2e9e2b);
          color:#0b2a0b;
        }
        .coc-confirm-btn-cancel{
          background:linear-gradient(#ffdb6b, #e0a12a);
          color:#2a1a10;
        }
      `;
      document.head.appendChild(st);
    }

    window.cocConfirm = function(opts){
      opts = opts || {};
      ensureStyles();

      const title = String(opts.title || 'Подтвердите');
      const message = String(opts.message || '');
      const cost = (typeof opts.cost !== 'undefined') ? parseInt(opts.cost,10) : null;
      const resIcon = String(opts.resIcon || '/images/icons/gems.png');
      const okText = String(opts.okText || 'Да');
      const cancelText = String(opts.cancelText || 'Отмена');

      return new Promise((resolve) => {
        const ov = document.createElement('div');
        ov.className = 'coc-confirm-overlay';
        ov.innerHTML = `
          <div class="coc-confirm-box" role="dialog" aria-modal="true">
            <div class="coc-confirm-head">${title}</div>
            <div class="coc-confirm-body">
              <div>${message}</div>
              ${cost !== null ? `<div class="coc-confirm-cost"><img src="${resIcon}" alt=""><span>${cost}</span></div>` : ``}
            </div>
            <div class="coc-confirm-actions">
              <button class="coc-confirm-btn coc-confirm-btn-ok" data-act="ok">${okText}</button>
              <button class="coc-confirm-btn coc-confirm-btn-cancel" data-act="cancel">${cancelText}</button>
            </div>
          </div>
        `;

        function close(ans){
          try{ ov.remove(); }catch(e){ if (ov.parentNode) ov.parentNode.removeChild(ov); }
          resolve(!!ans);
        }

        ov.addEventListener('click', (e)=>{
          if (e.target === ov) close(false);
        });
        ov.querySelector('[data-act="ok"]').addEventListener('click', ()=>close(true));
        ov.querySelector('[data-act="cancel"]').addEventListener('click', ()=>close(false));
        document.addEventListener('keydown', function escH(ev){
          if (ev.key === 'Escape'){ document.removeEventListener('keydown', escH); close(false); }
        });

        document.body.appendChild(ov);
      });
    };
  })();

  function buildUrl(section, view, type, id, extra) {
    const params = new URLSearchParams();
    params.set('page', 'buildings');
    params.set('section', section);
    params.set('view', view || 'main');
    if (type) params.set('type', type);
    if (id) params.set('id', String(id));
    if (extra) {
      Object.keys(extra).forEach(k => {
        if (extra[k] !== undefined && extra[k] !== null && extra[k] !== '') params.set(k, String(extra[k]));
      });
    }
    return `ajax.php?${params.toString()}`;
  }

  function loadSectionContent(section, view, type, id, isInitial, extra) {
    const modalId = `${section}-modal`;
    const modal = document.getElementById(modalId);
    const modalContent = document.getElementById(`${section}-modal-content`);
    if (!modal || !modalContent) {
      console.error('Modal element not found:', modalId);
      return;
    }

    const historyStack = getHistory(modalId);
    if (!isInitial) {
      const prev = historyStack.length ? historyStack[historyStack.length - 1] : null;
      const state = { section, view: view || 'main', type: type || '', id: id || 0, extra: extra || null };
      if (!prev || prev.view !== state.view || prev.type !== state.type || prev.id !== state.id) {
        historyStack.push(state);
      }
    }

    const url = buildUrl(section, view, type, id, extra);
    setLoading(modalContent);
    fetch(url, {
      method: 'GET',
      headers: {
        'X-CSRF-Token': (typeof window.APP_CONFIG !== 'undefined' && window.APP_CONFIG.csrfToken)
          ? window.APP_CONFIG.csrfToken
          : (document.querySelector('meta[name=\"csrf_token\"]') ? document.querySelector('meta[name=\"csrf_token\"]').content : ''),
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'text/html'
      },
      credentials: 'same-origin'
    })
      .then(async (r) => {
        // Синхронизируем CSRF из ответа (как в production/storage модалках)
        try {
          const t = r.headers.get('X-CSRF-Token');
          if (t) {
            if (window.APP_CONFIG) window.APP_CONFIG.csrfToken = t;
            let meta = document.querySelector('meta[name=\"csrf_token\"]');
            if (!meta) {
              meta = document.createElement('meta');
              meta.name = 'csrf_token';
              document.head.appendChild(meta);
            }
            meta.content = t;
          }
        } catch(e) {}

        const html = await r.text();
        modalContent.innerHTML = html;
        initCountdown(modalContent);

        // Показ тостов при игровых ошибках (нехватка ресурсов и т.п.)
        let action = null;
        if (window.gameHandleActionError) {
          action = window.gameHandleActionError(modalContent);
        }

        // Если сервер вернул 4xx с валидной игровой ошибкой —
        // не оставляем игрока на экране «ошибка», а возвращаемся в деталь/список.
        if (!r.ok && action && action.kind) {
          const kind = action.kind;
          if (kind === 'not_enough_resources' || kind === 'need_townhall' || kind === 'no_builder' || kind === 'max_count' || kind === 'busy') {
            setTimeout(() => {
              if (view === 'upgrade' && id) return loadSectionContent(section, 'detail', '', id, true);
              if (view === 'buy' && type) return loadSectionContent(section, 'list', type, 0, true);
              // fallback
              return loadSectionContent(section, 'main', '', 0, true);
            }, 60);
          }
        }

        // Если это реальная 5xx/403/401 без распознанной ошибки — оставляем как есть (HTML с ошибкой уже вставлен)
        return null;
      })
      .catch(err => {
        console.error(err);
        modalContent.innerHTML = `<div class=\"modal-content\"><button class=\"close-modal close-top-right modal-button-corner\" onclick=\"hideModal('${modalId}')\"><img src=\"/images/icons/close.png\" alt=\"Закрыть\"></button><div class=\"error\" style=\"margin:20px;\">❌ Ошибка соединения: ${String(err.message || err)}</div></div>`;
      });
  }

  // --- Public API ---
  window.showSectionModal = function(section, view = 'main', type = '', id = 0) {
    // storage/prod - в отдельном файле
    if (section === 'storage') return window.showStorageModal ? window.showStorageModal(view, type, id) : null;
    if (section === 'production') return window.showProductionModal ? window.showProductionModal(view, type, id) : null;

    setTimeout(() => {
      const modal = document.getElementById(`${section}-modal`);
      const historyStack = getHistory(`${section}-modal`);
      historyStack.length = 0;
      loadSectionContent(section, view, type, id, true);
      if (modal) modal.classList.add('active');
    }, 50);
  };

  window.loadSectionList = function(section, type) {
    loadSectionContent(section, 'list', type, 0, false);
  };

  window.loadSectionDetail = function(section, id) {
    loadSectionContent(section, 'detail', '', id, false);
  };

  window.startSectionUpgrade = function(section, id) {
    loadSectionContent(section, 'upgrade', '', id, false);
  };

  window.startSectionBuild = function(section, type) {
    loadSectionContent(section, 'buy', type, 0, false);
  };

  window.trainUnit = function(unitId, qty) {
    loadSectionContent('barracks', 'train', '', 0, false, { unit: unitId, qty: qty || 1 });
  };


  window.startResearchAction = function(researchId) {
    loadSectionContent('lab', 'research', '', 0, false, { research: researchId });
  };

  window.createClanAction = function() {
    const nameEl = document.getElementById('clan_name');
    const descEl = document.getElementById('clan_desc');
    const name = nameEl ? nameEl.value.trim() : '';
    const desc = descEl ? descEl.value.trim() : '';
    if (!name) { alert('Введите название клана'); return; }
    loadSectionContent('clan', 'clan_create', '', 0, false, { name, desc });
  };

  window.joinClanAction = function(clanId) {
    loadSectionContent('clan', 'clan_join', '', 0, false, { clan_id: clanId });
  };

  window.leaveClanAction = function() {
    if (!confirm('Выйти из клана?')) return;
    loadSectionContent('clan', 'clan_leave', '', 0, false);
  };

  window.goBackSection = function(section) {
    const modalId = `${section}-modal`;
    const historyStack = getHistory(modalId);
    historyStack.pop();
    const prev = historyStack.pop();
    if (prev) {
      loadSectionContent(section, prev.view, prev.type, prev.id, true, prev.extra);
    } else {
      loadSectionContent(section, 'main', '', 0, true);
    }
  };

})();
