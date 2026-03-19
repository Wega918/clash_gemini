(function(){
  var TITLE = 'КЛАНОВАЯ КРЕПОСТЬ';

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
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'clan-modal\')">'
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
      + '<button class="close-modal close-top-right modal-button-corner" onclick="hideModal(\'clan-modal\')">'
      + '<img src="/images/icons/close.png" alt="Закрыть"></button>'
      + '<div class="modal-header-controls"><div class="modal-title-bar">'
      + '<h2 class="modal-title-text-inside-panel">' + TITLE + '</h2>'
      + '</div></div>'
      + '<div class="modal-body-custom">'
      + '<div class="error" style="margin:20px;">❌ ' + msg + '</div>'
      + '</div>';
  }

  function load(view){
    var modalId = 'clan-modal';
    var contentId = 'clan-modal-content';
    var modal = document.getElementById(modalId);
    var cont = document.getElementById(contentId);
    if (!modal || !cont) { console.error('Clan modal container not found'); return; }

    setLoading(cont);

    var url = 'ajax.php?page=clan&view=' + encodeURIComponent(view || 'main') + '&r=' + Date.now();
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
      if (!r.ok) return r.text().then(function(t){ throw new Error('HTTP ' + r.status + ': ' + t); });
      syncCsrfFromResponse(r);
      return r.text();
    }).then(function(html){
      cont.innerHTML = html;
      modal.classList.add('active');
      window.scrollTo(0, scrollY);
    }).catch(function(err){
      console.error(err);
      setError(cont, 'Ошибка загрузки: ' + (err && err.message ? err.message : err));
      modal.classList.add('active');
      window.scrollTo(0, scrollY);
    });
  }

  window.showClanModal = function(view){
    setTimeout(function(){ load(view); }, 20);
  };
})();
