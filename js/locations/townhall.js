(function(){
  var TITLE = 'РАТУША';

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

  function setLoading(cont){
    cont.innerHTML = ''
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'townhall-modal\')">'
      + '<img src="/images/icons/close.png" alt="Закрыть"></button>'
      + '<div class="modal-header-controls"><div class="modal-title-bar">'
      + '<h2 class="modal-title-text-inside-panel">' + TITLE + '</h2>'
      + '</div></div>'
      + '<div class="modal-body-custom">'
      + '<div class="modal-loader"><div class="loader-spinner"></div><div class="loader-text">Загрузка…</div></div>'
      + '</div>';
  }

  function setError(cont, msg){
    cont.innerHTML = ''
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'townhall-modal\')">'
      + '<img src="/images/icons/close.png" alt="Закрыть"></button>'
      + '<div class="modal-header-controls"><div class="modal-title-bar">'
      + '<h2 class="modal-title-text-inside-panel">' + TITLE + '</h2>'
      + '</div></div>'
      + '<div class="modal-body-custom">'
      + '<div class="error" style="margin:20px;">❌ ' + msg + '</div>'
      + '</div>';
  }

  function load(view){
    var modalId = 'townhall-modal';
    var contentId = 'townhall-modal-content';
    var modal = document.getElementById(modalId);
    var cont = document.getElementById(contentId);
    if (!modal || !cont) { console.error('Townhall modal container not found'); return; }

    setLoading(cont);

    var url = 'ajax.php?page=townhall&view=' + encodeURIComponent(view || 'main') + '&r=' + Date.now();
    var scrollY = window.pageYOffset || document.documentElement.scrollTop || 0;

    fetch(url, {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrf(),
        'Accept': 'text/html'
      }
    }).then(function(r){
      if (!r.ok) {
        if (r.status === 401) {
          try {
            if (typeof window.cocNotifyAuthRequired === 'function') window.cocNotifyAuthRequired();
          } catch(e) {}
          throw new Error('AUTH_REQUIRED');
        }
        return r.text().then(function(t){ throw new Error('HTTP ' + r.status + ': ' + t); });
      }
      syncCsrfFromResponse(r);
      return r.text();
    }).then(function(html){
      cont.innerHTML = html;
      modal.classList.add('active');
      window.scrollTo(0, scrollY);
    }).catch(function(err){
      if (err && err.message === 'AUTH_REQUIRED') {
        setError(cont, 'Требуется авторизация. Пожалуйста, войдите в аккаунт.');
        modal.classList.add('active');
        window.scrollTo(0, scrollY);
        return;
      }
      console.error(err);
      setError(cont, 'Ошибка загрузки: ' + (err && err.message ? err.message : err));
      modal.classList.add('active');
      window.scrollTo(0, scrollY);
    });
  }

  window.showTownhallModal = function(view){
    setTimeout(function(){ load(view); }, 20);
  };
})();
