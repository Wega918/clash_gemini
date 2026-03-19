/**
 * storage_modal.js
 * Универсальная логика для динамической загрузки и навигации
 * в модальных окнах: Хранилища (storage-modal) и Производство (production-modal).
 */

(function () {
  // Безопасное чтение CSRF
  const meta = document.querySelector('meta[name="csrf_token"]');
  const csrfToken = (typeof window.APP_CONFIG !== 'undefined' && window.APP_CONFIG.csrfToken)
    ? window.APP_CONFIG.csrfToken
    : (meta ? meta.content : '');

  if (!csrfToken) {
    console.error('CSRF Token is missing. Modal functionality disabled.');
    return;
  }

  // Отдельная история на каждое модальное окно
  const histories = {
    'storage-modal': [],
    'production-modal': [],
  };

  function getHistory(modalId) {
    if (!histories[modalId]) histories[modalId] = [];
    return histories[modalId];
  }

  // --- Публичные входы ---
  window.showStorageModal = function (view, type = '', id = 0) {
    showModalAndLoad('storage-modal', view || 'main', type, id);
  };

  window.showProductionModal = function (view, type = '', id = 0) {
    showModalAndLoad('production-modal', view || 'production_main', type, id);
  };

  function showModalAndLoad(modalId, view, type = '', id = 0) {
    // Небольшая задержка — модалка может быть вставлена AJAX-ом
    setTimeout(() => {
      const modal = document.getElementById(modalId);
      if (!modal) {
        console.error(`Modal element (#${modalId}) not found. Убедитесь, что HTML-структура присутствует в AJAX-ответе.`);
        return;
      }

      // Сброс истории при первом открытии
      const stack = getHistory(modalId);
      stack.length = 0;

      loadContent(modalId, view, type, id, true);
      modal.classList.add('active');
    }, 50);
  }

  /**
   * Загружает контент через AJAX и управляет историей.
   */
  function loadContent(modalId, view, type = '', id = 0, isInitial = false) {
    const modal = document.getElementById(modalId);
    const modalContent = document.getElementById(`${modalId}-content`);
    if (!modal || !modalContent) return;

    // Роутер у нас общий: page=storage
    let url = `ajax.php?page=storage&view=${encodeURIComponent(view)}`;
    if (type) url += `&type=${encodeURIComponent(type)}`;
    if (id) url += `&id=${encodeURIComponent(id)}`;

    // История
    const stack = getHistory(modalId);
    if (!isInitial) {
      const currentState = stack.length > 0 ? stack[stack.length - 1] : { view: null, type: null, id: null };
      if (currentState.view !== view || currentState.type !== type || currentState.id !== id) {
        stack.push({ view, type, id });
      }
    }

    // Лоадер
    modalContent.innerHTML = `<div class="modal-loader">
  <div class="loader-spinner"></div>
  <div class="loader-text">Загрузка…</div>
</div>`;

    fetch(url, {
      method: 'GET',
      headers: {
        'X-CSRF-Token': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'text/html'
      },
      credentials: 'same-origin'
    })
      .then(async (response) => {
        if (!response.ok) {
          // Nice UX for auth-required
          if (response.status === 401) {
            try {
              if (typeof window.cocNotifyAuthRequired === 'function') {
                window.cocNotifyAuthRequired();
              }
            } catch (e) {}
            throw new Error('AUTH_REQUIRED');
          }
          const contentType = response.headers.get('content-type') || '';
          if (contentType.includes('application/json')) {
            const data = await response.json().catch(() => ({}));
            throw new Error(data.error || `Ошибка HTTP: ${response.status}`);
          }
          const text = await response.text();
          const err = new Error(`Ошибка HTTP ${response.status}: Произошла ошибка на сервере.`);
          err.fullText = text;
          throw err;
        }
        return response.text();
      })
      .then((html) => {
        modalContent.innerHTML = html;
        // После обновления — подтягиваем актуальные ресурсы в шапку
        refreshBalances().catch(() => {});
      })
      .catch((error) => {
        // Do not spam console for auth-required
        if (error && error.message === 'AUTH_REQUIRED') {
          modalContent.innerHTML = `
              <button class="close-modal" onclick="hideModal('${modalId}')">Закрыть</button>
              <div class="error" style="margin: 20px;">🔒 Требуется авторизация. Войдите в аккаунт.</div>
            `;
          return;
        }
        console.error('Ошибка загрузки модального окна:', error);
        if (error && error.fullText) {
          modalContent.innerHTML = `
            
              <button class="close-modal" onclick="hideModal('${modalId}')">Закрыть</button>
              <div class="error-container" style="margin: 20px; color: black; background: white; padding: 15px; border: 1px solid red; overflow: auto; max-height: 70vh;">
                <h3>❌ Ошибка сервера</h3>
                <p>Полный вывод сервера:</p>
                ${error.fullText}
              </div>
            `;
        } else {
          const msg = (error && error.message) ? error.message : String(error);
          modalContent.innerHTML = `
            
              <button class="close-modal" onclick="hideModal('${modalId}')">Закрыть</button>
              <div class="error" style="margin: 20px;">❌ Ошибка загрузки: ${msg}</div>
            `;
        }
      });
  }

  // --- Балансы в шапке ---
  async function refreshBalances() {
    const response = await fetch(`ajax.php?page=balance&r=${Date.now()}`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-CSRF-Token': csrfToken,
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      }
    });
    if (!response.ok) return;
    const data = await response.json().catch(() => null);
    if (!data) return;

    updateBalanceUI('gold', data.gold, data.cap_gold);
    updateBalanceUI('elixir', data.elixir, data.cap_elixir);
    updateBalanceUI('dark_elixir', data.dark_elixir, data.cap_dark_elixir);
    updateBalanceUI('gems', data.gems, data.cap_gems || 0);
  }

  function updateBalanceUI(key, value, cap) {
    const textEl = document.getElementById(`balance-${key}-text`);
    if (textEl) textEl.textContent = formatNumber(value);

    const barEl = document.getElementById(`balance-${key}-bar`);
    if (barEl && cap && cap > 0) {
      const pct = Math.max(0, Math.min(100, Math.floor((value / cap) * 100)));
      barEl.style.width = pct + '%';
    }
  }

  function formatNumber(n) {
    try {
      return (Number(n) || 0).toLocaleString('en-US');
    } catch (e) {
      return String(n);
    }
  }

  // --- Глобальные функции, используемые в HTML (storage_views.php) ---
  window.loadStorageList = function (modalId, buildingType) {
    loadContent(modalId, 'list', buildingType, 0, false);
  };

  window.loadStorageDetail = function (modalId, buildingRowId) {
    loadContent(modalId, 'detail', '', buildingRowId, false);
  };

  window.goBack = function (modalId, defaultView) {
    const stack = getHistory(modalId);
    // удаляем текущий
    stack.pop();
    // берем предыдущий
    const prev = stack.pop();
    if (prev) {
      loadContent(modalId, prev.view, prev.type || '', prev.id || 0, true);
    } else {
      loadContent(modalId, defaultView || 'main', '', 0, true);
    }
  };

  // --- Backward compatible aliases used by some templates ---
  window.storageLoadList = window.loadStorageList;
  window.storageLoadDetail = window.loadStorageDetail;
  window.storageGoBack = window.goBack;
  window.storageCollectResource = window.collectResource;
  window.storageStartUpgrade = window.startUpgrade;
  window.storageBuyBuilding = window.buyBuilding;

  window.collectResource = function (modalId, buildingRowId) {
    loadContent(modalId, 'collect', '', buildingRowId, false);
  };

  window.startUpgrade = function (modalId, buildingRowId) {
    loadContent(modalId, 'upgrade', '', buildingRowId, false);
  };

  window.buyBuilding = function (modalId, buildingType) {
    loadContent(modalId, 'buy', buildingType, 0, false);
  };

})();
