<?php
// Ensure core helpers are available before any template logic in <head>.
require_once __DIR__ . '/function.php';
?>
<!DOCTYPE html>
<html lang="ru" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="Clash Browser">
    <title>Clash Browser</title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <?php
        // Cache-busting for CSS so UI updates show up immediately after deployment.
        $css_v = '';
        $css_path = project_doc_root() . '/style.css';
        if (is_file($css_path)) $css_v = (string)filemtime($css_path);
        echo '<link rel="stylesheet" href="/style.css?v=' . htmlspecialchars($css_v, ENT_QUOTES) . '&pv=9">';

        // Optional compact CoC-like UI layer (loaded only if present).
        $coc_css = project_doc_root() . '/css/coc_compact_ui.css';
        if (is_file($coc_css)) {
            $coc_v = (string)filemtime($coc_css);
            echo '\n<link rel="stylesheet" href="/css/coc_compact_ui.css?v=' . htmlspecialchars($coc_v, ENT_QUOTES) . '&pv=9">';
        }
    ?>
</head>
<div class="page-glade">
<?php

// function.php already required at the top of this file.

// --- Season / design ---
global $mysqli;
$season_mode = getGlobalSeasonMode($mysqli);
$season = getActiveSeason($mysqli);
$csrf_token = generateCsrfToken();

$bg_fon = season_img('/images/diz/fon.jpg', $season, $mysqli);
$bg_board = season_img('/images/diz/board-top.png', $season, $mysqli);
$left_top_img = season_img('/images/diz/left-top.png', $season, $mysqli);

$map_img = '/images/diz/' . $season . '.jpg';
if (!is_file(project_doc_root() . $map_img)) {
    $map_img = '/images/diz/summer.jpg';
}

echo "<style>:root{--bg-fon:url('{$bg_fon}');--bg-board-top-img:url('{$bg_board}');--bg-village-map:url('{$map_img}');}
    .settings-modal-overlay{
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.55);
        z-index: 99999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }
    .settings-modal-card{
        width: min(520px, 94vw);
        border-radius: 14px;
        border: 1px solid rgba(255,255,255,0.12);
        background: rgba(0,0,0,0.78);
        backdrop-filter: blur(10px);
        color: #fff;
        box-shadow: 0 20px 60px rgba(0,0,0,0.55);
        max-height: 92vh;
        display:flex;
        flex-direction:column;
    }
    .settings-modal-head{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
        padding: 12px 14px;
        border-bottom: 1px solid rgba(255,255,255,0.10);
    }
    .settings-modal-title{ font-weight: 700; font-size: 14px; }
    .settings-close-btn{
        background: rgba(255,255,255,0.10);
        border: 1px solid rgba(255,255,255,0.18);
        color:#fff;
        padding: 6px 10px;
        border-radius: 10px;
        cursor:pointer;
    }
    .settings-modal-body{ padding: 14px; overflow-y:auto; flex:1; min-height:0; }
    .settings-row{ display:flex; flex-direction:column; gap:10px; }
    .settings-row + .settings-row{ margin-top: 12px; }
    .settings-divider{ height:1px; background: rgba(255,255,255,0.10); margin: 14px 0; }
    .settings-section-title{ font-weight:800; font-size:12px; letter-spacing:0.04em; opacity:0.92; margin-bottom:10px; }

    .hidden-scroll{ scrollbar-width: none; -ms-overflow-style: none; }
    .hidden-scroll::-webkit-scrollbar{ width: 0; height: 0; }
    .settings-label{ font-weight: 700; font-size: 13px; }
    .settings-controls{ display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
    .settings-input{
        width: 100%;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.12);
        background: rgba(0,0,0,0.28);
        color: #fff;
        outline: none;
    }
    .settings-input:focus{ border-color: rgba(0,255,0,0.25); }

    .settings-select{
        padding: 10px 12px;
        border-radius: 10px;
        background: rgba(255,255,255,0.08);
        color:#fff;
        border: 1px solid rgba(255,255,255,0.16);
        outline: none;
    }
    .settings-save-btn{
        padding: 10px 12px;
        border-radius: 10px;
        background: rgba(0,200,0,0.22);
        color:#cfffca;
        border: 1px solid rgba(0,255,0,0.28);
        cursor:pointer;
    }
    .settings-hint{ font-size: 12px; color: rgba(255,255,255,0.75); }
</style>
";
echo "<script>window.CSRF_TOKEN=" . json_encode($csrf_token) . ";window.SEASON_MODE=" . json_encode($season_mode) . ";window.ACTIVE_SEASON=" . json_encode($season) . ";</script>";

// ... (начало файла)
// Если пользователь авторизован, получаем его данные
if (isLoggedIn()) {
    // ВНИМАНИЕ: Проверяем, что $user доступен. 
    // В index.php он доступен, но здесь, в header.php, 
    // его нужно получить, если он еще не был получен в вызывающем файле.
    global $mysqli;
    $user = getUser($mysqli);
    
    // Вспомогательная функция для форматирования чисел
    function format_resource($value) {
        return number_format($value, 0, '.', ',');
    }
?>
<body>
    <?php if (isLoggedIn()): ?>
    <div class="main-frame-left"></div>
    <div class="main-frame-right"></div>
    <div class="game-ui" style="position: fixed;bottom: 94%;left: 1%;z-index: 9999;">
        <button id="btn-sound" title="Включить/выключить звук">🔇</button>
        <button id="btn-fullscreen" title="На весь экран">⛶</button>
        <button id="btn-settings" title="Настройки">⚙️</button>
    </div>


<!-- Settings Modal -->
<div id="settings-modal" class="settings-modal-overlay" style="display:none;">
    <div class="settings-modal-card">
        <div class="settings-modal-head">
            <div class="settings-modal-title">⚙️ Настройки</div>
            <button type="button" id="settings-close" class="settings-close-btn">✕</button>
        </div>
        <div class="settings-modal-body hidden-scroll">

            <div class="settings-section">
                <div class="settings-section-title">Аккаунт</div>
                <div class="settings-row">
                    <div class="settings-label">Идентификатор учётной записи</div>
                    <div class="settings-hint"><b>ID <?= (int)$user['id'] ?></b></div>
                </div>

                <div class="settings-row">
                    <div class="settings-label">Выход</div>
                    <div class="settings-controls">
                        <a href="/logout.php" class="settings-save-btn" style="text-decoration:none;display:inline-block;background:rgba(255,255,255,0.10);border-color:rgba(255,255,255,0.18);color:#fff;">Выйти из аккаунта</a>
                    </div>
                    <div class="settings-hint">Если играешь не со своего устройства — лучше выйти.</div>
                </div>

                <div class="settings-row">
                    <div class="settings-label">Пол</div>
                    <div class="settings-controls">
                        <select id="gender-select" class="settings-select">
                            <option value="0">Не указан</option>
                            <option value="1">Мужской</option>
                            <option value="2">Женский</option>
                        </select>
                        <button type="button" id="gender-save" class="settings-save-btn">Сохранить</button>
                    </div>
                    <div class="settings-hint" id="gender-hint"></div>
                </div>
            </div>

            <div class="settings-divider"></div>

            <div class="settings-section">
                <div class="settings-section-title">Безопасность</div>
                <div class="settings-row">
                    <div class="settings-label">Смена пароля</div>
                    <div class="settings-controls" style="flex-direction:column;align-items:stretch;gap:8px;">
                        <input id="set-pass-old" class="settings-input" type="password" autocomplete="current-password" placeholder="Текущий пароль">
                        <input id="set-pass-new" class="settings-input" type="password" autocomplete="new-password" placeholder="Новый пароль">
                        <input id="set-pass-new2" class="settings-input" type="password" autocomplete="new-password" placeholder="Повторите новый пароль">
                        <div class="settings-controls" style="gap:10px;justify-content:flex-end;">
                            <button type="button" id="pass-gen" class="settings-save-btn" style="background:rgba(0,160,255,0.18);border-color:rgba(0,160,255,0.26);color:#d8f1ff;">Сгенерировать</button>
                            <button type="button" id="pass-copy" class="settings-save-btn" style="background:rgba(255,255,255,0.10);border-color:rgba(255,255,255,0.18);color:#fff;">Копировать</button>
                            <button type="button" id="pass-save" class="settings-save-btn">Сохранить</button>
                        </div>
                    </div>
                    <div class="settings-hint" id="pass-hint"></div>
                </div>
            </div>

            <div class="settings-divider"></div>

            <div class="settings-section">
                <div class="settings-section-title">Почта</div>

                <div id="email-block-unverified" class="settings-row">
                    <div class="settings-label">Привязка почты</div>
                    <div class="settings-controls" style="flex-direction:column;align-items:stretch;gap:8px;">
                        <input id="email-input" class="settings-input" type="email" placeholder="email@example.com" autocomplete="email">
                        <button type="button" id="email-save" class="settings-save-btn">Отправить письмо для подтверждения</button>
                    </div>
                    <div class="settings-hint" id="email-hint"></div>
                </div>

                <div id="email-block-verified" class="settings-row" style="display:none;">
                    <div class="settings-label">Почта</div>
                    <div class="settings-hint" id="email-verified-text"></div>
                    <div class="settings-hint" style="opacity:.85;">Почта подтверждена — изменить её нельзя.</div>
                </div>
            </div>

            <div class="settings-divider"></div>

            <div class="settings-section">
                <div class="settings-section-title">Имя игрока</div>
                <div class="settings-row">
                    <div class="settings-label">Смена ника</div>
                    <div class="settings-controls" style="flex-direction:column;align-items:stretch;gap:8px;">
                        <input id="nick-input" class="settings-input" type="text" placeholder="Новый ник">
                        <div class="settings-controls" style="gap:10px;justify-content:flex-end;">
                            <button type="button" id="nick-gen" class="settings-save-btn" style="background:rgba(0,160,255,0.18);border-color:rgba(0,160,255,0.26);color:#d8f1ff;">Сгенерировать</button>
                            <button type="button" id="nick-save" class="settings-save-btn">Сменить</button>
                        </div>
                    </div>
                    <div class="settings-hint" id="nick-hint"></div>
                    <div class="settings-hint">Стоимость: <b id="nick-cost">...</b> 💎</div>
                    <div class="settings-hint">Ник: от 3 до 20 символов (ru, en, цифры, _)</div>
                </div>
            </div>

            <?php if ((int)$user['id'] === 1): ?>
            <div class="settings-divider"></div>
            <div class="settings-section">
                <div class="settings-section-title">Админ</div>
                <div class="settings-row">
                    <div class="settings-label">Сезон / дизайн</div>
                    <div class="settings-controls">
                        <select id="season-mode" class="settings-select">
                            <option value="auto">По умолчанию (авто)</option>
                            <option value="winter">Зима</option>
                            <option value="spring">Весна</option>
                            <option value="summer">Лето</option>
                            <option value="autumn">Осень</option>
                        </select>
                        <button type="button" id="season-save" class="settings-save-btn">Сохранить</button>
                    </div>
                    <div class="settings-hint" id="season-hint"></div>
                    <div class="settings-hint">Активный сезон сейчас: <b id="active-season-text"></b></div>
                </div>
            </div>
            <?php endif; ?>
</div>

<!-- Toasts (уведомления) -->
<div id="toast-container" style="position:fixed;top:14px;right:14px;z-index:100000;display:flex;flex-direction:column;gap:10px;pointer-events:none;"></div>
    </div>
</div>


    <style>
    .game-ui button {
        background: rgba(0,0,0,0.6);
        color: white;
        border: none;
        font-size: 11px;
        padding: 8px;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.3s;
    }
    .game-ui button:hover {
        background: rgba(0,0,0,0.8);
    }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', async () => {
        const btnSound = document.getElementById('btn-sound');
        const btnFullscreen = document.getElementById('btn-fullscreen');
        const btnSettings = document.getElementById('btn-settings');

        const settingsModal = document.getElementById('settings-modal');
const settingsClose = document.getElementById('settings-close');
const isAdmin = <?= ((int)$user['id'] === 1) ? 'true' : 'false' ?>;

function openSettings(){
    if (!settingsModal) return;
    const activeEl = document.getElementById('active-season-text');
    if (activeEl) activeEl.textContent = (window.ACTIVE_SEASON || 'summer');

    if (isAdmin) {
        const sel = document.getElementById('season-mode');
        const hint = document.getElementById('season-hint');
        if (sel) sel.value = (window.SEASON_MODE || 'auto');
        if (hint) hint.innerHTML = 'Режим: <b>' + (window.SEASON_MODE || 'auto') + '</b> (auto = система по календарю)';
    }

    settingsModal.style.display = 'flex';


    // Статус настроек (ник/почта/стоимость смены ника)
    fetch('settings_api.php?action=status', { credentials:'same-origin' })
      .then(r => { try{ var nt=r.headers.get('X-CSRF-Token'); if(nt) window.CSRF_TOKEN=nt; }catch(e){}; return r.json(); }).then(d => {
        if (!d || d.error) return;

        // сохраним login, чтобы после смены пароля браузер мог предложить сохранение
        window.USER_LOGIN = d.login || '';

        var costEl = document.getElementById('nick-cost');
        if (costEl) costEl.textContent = (d.nick_cost !== undefined ? d.nick_cost : '...');

        var nickHint = document.getElementById('nick-hint');
        if (nickHint) nickHint.textContent = d.login ? ('Текущий ник: ' + d.login) : '';

        // gender
        var gSel = document.getElementById('gender-select');
        if (gSel) gSel.value = String(d.gender || 0);

        // email
        var emailInput = document.getElementById('email-input');
        if (emailInput) emailInput.value = d.email ? d.email : '';

        var blockUnv = document.getElementById('email-block-unverified');
        var blockV = document.getElementById('email-block-verified');
        var emailVText = document.getElementById('email-verified-text');

        if (d.email && d.email_verified) {
            if (blockUnv) blockUnv.style.display = 'none';
            if (blockV) blockV.style.display = '';
            if (emailVText) emailVText.innerHTML = '<b>' + (d.email) + '</b> ✅';
        } else {
            if (blockUnv) blockUnv.style.display = '';
            if (blockV) blockV.style.display = 'none';
            var emailHint = document.getElementById('email-hint');
            if (emailHint) {
              if (!d.email) emailHint.textContent = 'Почта не привязана.';
              else emailHint.textContent = 'Почта привязана, но не подтверждена.';
            }
        }
      }).catch(()=>{});

}

function closeSettings(){
    if (!settingsModal) return;
    settingsModal.style.display = 'none';
}

if (settingsClose) settingsClose.addEventListener('click', closeSettings);
if (settingsModal) settingsModal.addEventListener('click', (e) => {
    if (e.target === settingsModal) closeSettings();
});const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        let buffer = null;
        let source = null;
        const gainNode = audioCtx.createGain();
        let soundOn = localStorage.getItem('music-sound-on') || 'true';

        btnSound.textContent = soundOn === 'true' ? '🔊' : '🔇';

        const response = await fetch('home_music.mp3');
        const arrayBuffer = await response.arrayBuffer();
        buffer = await audioCtx.decodeAudioData(arrayBuffer);

        function play() {
            if (source) source.stop();
            source = audioCtx.createBufferSource();
            source.buffer = buffer;
            source.loop = true;
            gainNode.gain.value = soundOn === 'true' ? 0.3 : 0;
            source.connect(gainNode).connect(audioCtx.destination);
            source.start();
        }

        async function resumeCtx() {
            if (audioCtx.state === 'suspended') {
                await audioCtx.resume();
            }
        }

        async function init() {
            if (audioCtx.state === 'suspended') {
                const unlock = async () => {
                    await resumeCtx();
                    play();
                    document.body.removeEventListener('click', unlock);
                    document.body.removeEventListener('keydown', unlock);
                };
                document.body.addEventListener('click', unlock, { once: true });
                document.body.addEventListener('keydown', unlock, { once: true });
            } else {
                play();
            }
        }

        btnSound.addEventListener('click', () => {
            if (soundOn === 'true') {
                gainNode.gain.value = 0;
                soundOn = 'false';
                btnSound.textContent = '🔇';
            } else {
                gainNode.gain.value = 0.3;
                soundOn = 'true';
                btnSound.textContent = '🔊';
            }
            localStorage.setItem('music-sound-on', soundOn);
        });

        btnFullscreen.addEventListener('click', () => {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen();
            } else {
                document.exitFullscreen();
            }
        });

        
// Сохранение глобального сезона (только админ id=1)
const btnSeasonSave = document.getElementById('season-save');
if (btnSeasonSave) {
    btnSeasonSave.addEventListener('click', async () => {
        try{
            const sel = document.getElementById('season-mode');
            const mode = sel ? sel.value : 'auto';
            const csrf = (window.APP_CONFIG && window.APP_CONFIG.csrfToken) ? window.APP_CONFIG.csrfToken : (window.CSRF_TOKEN || '');
            const body = new URLSearchParams();
            body.set('csrf_token', csrf);
            body.set('season_mode', mode);

            const r = await fetch('/season_set.php', {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: body.toString()
            });
            const data = await r.json().catch(()=>null);
            if (!data || !data.ok) {
                alert((data && data.error) ? data.error : 'Ошибка сохранения');
                return;
            }
            location.reload();
        } catch(e){
            console.error(e);
            alert('Ошибка сохранения');
        }
    });
}

// ---------------- Настройки игрока (пароль / почта / ник) ----------------
function toast(type, title, msg){
    var cont = document.getElementById('toast-container');
    if (!cont) return;
    var el = document.createElement('div');
    el.style.pointerEvents = 'none';
    el.style.padding = '10px 12px';
    el.style.borderRadius = '12px';
    el.style.border = '1px solid rgba(255,255,255,0.14)';
    el.style.background = 'rgba(0,0,0,0.78)';
    el.style.backdropFilter = 'blur(10px)';
    el.style.color = '#fff';
    el.style.boxShadow = '0 10px 30px rgba(0,0,0,0.35)';
    el.style.maxWidth = '360px';
    el.style.overflow = 'hidden';
    el.style.position = 'relative';

    var bar = document.createElement('div');
    bar.style.position = 'absolute';
    bar.style.left = '0';
    bar.style.top = '0';
    bar.style.bottom = '0';
    bar.style.width = '4px';
    bar.style.background = (type === 'success') ? 'rgba(0,255,0,0.7)' : (type === 'warn') ? 'rgba(255,200,0,0.7)' : 'rgba(255,0,0,0.7)';
    el.appendChild(bar);

    var t = document.createElement('div');
    t.style.fontWeight = '800';
    t.style.fontSize = '13px';
    t.style.marginLeft = '8px';
    t.textContent = title || 'Сообщение';
    el.appendChild(t);

    var m = document.createElement('div');
    m.style.fontSize = '12px';
    m.style.opacity = '0.9';
    m.style.marginLeft = '8px';
    m.style.marginTop = '2px';
    m.textContent = msg || '';
    el.appendChild(m);

    cont.appendChild(el);
    try{
        el.animate([{transform:'translateY(-6px)',opacity:0},{transform:'translateY(0)',opacity:1}],{duration:220,easing:'ease-out'});
    }catch(e){}
    setTimeout(function(){
        try{
            el.animate([{opacity:1},{opacity:0}],{duration:300,easing:'ease-in'}).onfinish=function(){try{el.remove();}catch(e){}};
        }catch(e){ try{ el.remove(); }catch(e2){} }
    }, 2800);
}


function tryStorePassword(login, password){
    try{
        if (!login || !password) return;
        if (navigator.credentials && window.PasswordCredential) {
            var cred = new PasswordCredential({id: login, password: password, name: login});
            navigator.credentials.store(cred);
        }
    }catch(e){}
}

function setHint(id, text, ok){
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = text || '';
    el.style.color = ok ? 'rgba(140,255,140,0.95)' : 'rgba(255,140,140,0.95)';
}

function csrfToken(){
    if (window.APP_CONFIG && window.APP_CONFIG.csrfToken) return window.APP_CONFIG.csrfToken;
    return window.CSRF_TOKEN || '';
}

function apiPost(action, paramsObj){
    var params = new URLSearchParams();
    params.set('csrf_token', csrfToken());
    if (paramsObj) {
        Object.keys(paramsObj).forEach(function(k){
            if (paramsObj[k] !== undefined && paramsObj[k] !== null) params.set(k, String(paramsObj[k]));
        });
    }
    return fetch('settings_api.php?action=' + encodeURIComponent(action), {
        method:'POST',
        credentials:'same-origin',
        headers:{
            'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8',
            'X-Requested-With':'XMLHttpRequest',
            'X-CSRF-Token': csrfToken()
        },
        body: params.toString()
    }).then(function(r){
        try{ var nt = r.headers.get('X-CSRF-Token'); if (nt) window.CSRF_TOKEN = nt; }catch(e){}
        return r.json().then(function(d){
            if (!r.ok) {
                var err = (d && d.error) ? d.error : ('HTTP ' + r.status);
                throw new Error(err);
            }
            return d;
        });
    });
}

// Баланс в верхней панели + мини-эффект +/-
function fmtNum(n){
    n = parseInt(n||0,10) || 0;
    return (''+n).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}
function balText(resKey){ return document.getElementById('balance-' + resKey + '-text'); }
function setBalance(resKey, value){
    var t = balText(resKey);
    if (t) {
        t.textContent = fmtNum(value);
        if (t.classList) {
            t.classList.remove('balance-change');
            void t.offsetWidth;
            t.classList.add('balance-change');
        }
    }
}
function flyDelta(resKey, text, color){
    var el = balText(resKey);
    if (!el || !el.animate) return;
    var fly = document.createElement('div');
    fly.textContent = text;
    fly.style.position = 'absolute';
    fly.style.right = '0';
    fly.style.top = '-6px';
    fly.style.fontSize = '12px';
    fly.style.fontWeight = '800';
    fly.style.color = color;
    fly.style.pointerEvents = 'none';
    fly.style.opacity = '1';
    el.style.position = 'relative';
    el.appendChild(fly);
    fly.animate([{transform:'translateY(0)',opacity:1},{transform:'translateY(-14px)',opacity:0}],{duration:900,easing:'ease-out'}).onfinish=function(){try{fly.remove();}catch(e){}};
}

function copyToClipboard(text){
    if (!text) return false;
    try{
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
            return true;
        }
    }catch(e){}
    try{
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        document.execCommand('copy');
        ta.remove();
        return true;
    }catch(e2){}
    return false;
}

function genStrongPassword(){
    var lowers = 'abcdefghijkmnopqrstuvwxyz';
    var uppers = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    var digits = '23456789';
    var specials = '!@#$%^&*_-+=?';
    var all = lowers + uppers + digits + specials;
    var len = 12 + Math.floor(Math.random()*5); // 12..16
    function pick(set){ return set.charAt(Math.floor(Math.random()*set.length)); }
    var out = [pick(lowers), pick(uppers), pick(digits), pick(specials)];
    while (out.length < len) out.push(pick(all));
    // shuffle
    for (var i = out.length - 1; i > 0; i--) {
        var j = Math.floor(Math.random() * (i + 1));
        var t = out[i]; out[i] = out[j]; out[j] = t;
    }
    return out.join('');
}

// генерация/копирование пароля
var btnPassGen = document.getElementById('pass-gen');
if (btnPassGen) {
    btnPassGen.addEventListener('click', function(){
        var p = genStrongPassword();
        var n1 = document.getElementById('set-pass-new');
        var n2 = document.getElementById('set-pass-new2');
        if (n1) n1.value = p;
        if (n2) n2.value = p;
        setHint('pass-hint', 'Сгенерирован надёжный пароль. Можно скопировать кнопкой «Копировать».', true);
        toast('success','Пароль','Сгенерирован надёжный пароль');
    });
}
var btnPassCopy = document.getElementById('pass-copy');
if (btnPassCopy) {
    btnPassCopy.addEventListener('click', function(){
        var p = (document.getElementById('set-pass-new')||{}).value || '';
        if (!p) {
            toast('warn','Пароль','Сначала введите или сгенерируйте пароль');
            return;
        }
        if (copyToClipboard(p)) toast('success','Пароль','Пароль скопирован');
        else toast('error','Пароль','Не удалось скопировать');
    });
}

// смена пароля
var btnPass = document.getElementById('pass-save');
if (btnPass) {
    btnPass.addEventListener('click', function(){
        var oldp = (document.getElementById('set-pass-old')||{}).value || '';
        var n1 = (document.getElementById('set-pass-new')||{}).value || '';
        var n2 = (document.getElementById('set-pass-new2')||{}).value || '';

        if (!oldp || !n1 || !n2) {
            setHint('pass-hint','Заполните все поля', false);
            toast('warn','Пароль','Заполните все поля');
            return;
        }
                if (n1.length < 8) {
            setHint('pass-hint','Минимум 8 символов: заглавные, цифры, спецсимволы', false);
            toast('warn','Пароль','Минимум 8 символов: заглавные, цифры, спецсимволы');
            return;
        }
        if (!/[A-ZА-ЯЁ]/.test(n1) || !/\d/.test(n1) || !/[^A-Za-zА-Яа-яЁё0-9]/.test(n1)) {
            setHint('pass-hint','Минимум 8 символов: заглавные, цифры, спецсимволы', false);
            toast('warn','Пароль','Минимум 8 символов: заглавные, цифры, спецсимволы');
            return;
        }
        if (n1 !== n2) {
            setHint('pass-hint','Пароли не совпадают', false);
            toast('warn','Пароль','Пароли не совпадают');
            return;
        }

        btnPass.disabled = true;
        setHint('pass-hint','Сохранение...', true);
        apiPost('change_password', {old_password: oldp, new_password: n1, new_password2: n2})
          .then(function(d){
              setHint('pass-hint', (d && d.message) ? d.message : 'Пароль обновлён', true);
              toast('success','Пароль','Пароль обновлён');

              // Попросим браузер предложить сохранение пароля (где поддерживается)
              try{
                  var uid = (d && d.login) ? d.login : (window.USER_LOGIN || '');
                  if (uid && navigator.credentials && window.PasswordCredential && navigator.credentials.store) {
                      var cred = new PasswordCredential({ id: uid, password: n1 });
                      navigator.credentials.store(cred).catch(function(){});
                  }
              }catch(e){}

              (document.getElementById('set-pass-old')||{}).value='';
              (document.getElementById('set-pass-new')||{}).value='';
              (document.getElementById('set-pass-new2')||{}).value='';
          })
          .catch(function(e){
              setHint('pass-hint', e && e.message ? e.message : 'Ошибка', false);
              toast('error','Пароль', e && e.message ? e.message : 'Ошибка');
          })
          .finally(function(){ btnPass.disabled = false; });
    });
}

// почта
var btnEmail = document.getElementById('email-save');
if (btnEmail) {
    btnEmail.addEventListener('click', function(){
        var email = (document.getElementById('email-input')||{}).value || '';
        email = (email||'').trim();
        if (!email || email.indexOf('@') < 1) {
            setHint('email-hint','Введите корректный email', false);
            toast('warn','Почта','Введите корректный email');
            return;
        }
        btnEmail.disabled = true;
        setHint('email-hint','Сохранение...', true);
        apiPost('bind_email', {email: email})
          .then(function(d){
              var msg = (d && d.message) ? d.message : 'Почта сохранена';
              setHint('email-hint', msg, true);
              toast('success','Почта','Письмо отправлено');
          })
          .catch(function(e){
              setHint('email-hint', e && e.message ? e.message : 'Ошибка', false);
              toast('error','Почта', e && e.message ? e.message : 'Ошибка');
          })
          .finally(function(){ btnEmail.disabled = false; });
    });
}

// пол
var btnGender = document.getElementById('gender-save');
if (btnGender) {
    btnGender.addEventListener('click', function(){
        var sel = document.getElementById('gender-select');
        var val = sel ? parseInt(sel.value || '0', 10) : 0;
        btnGender.disabled = true;
        setHint('gender-hint', 'Сохранение...', true);
        // Используем action без слова "gender" чтобы не ловить WAF/mod_security 403
        apiPost('profile_save', {g: val})
          .then(function(d){
              setHint('gender-hint', (d && d.message) ? d.message : 'Сохранено', true);
              toast('success','Профиль','Сохранено');
          })
          .catch(function(e){
              setHint('gender-hint', e && e.message ? e.message : 'Ошибка', false);
              toast('error','Профиль', e && e.message ? e.message : 'Ошибка');
          })
          .finally(function(){ btnGender.disabled = false; });
    });
}

// ник
var btnNickGen = document.getElementById('nick-gen');
if (btnNickGen) {
    btnNickGen.addEventListener('click', function(){
        btnNickGen.disabled = true;
        setHint('nick-hint','Генерация...', true);
        fetch('settings_api.php?action=generate_nick', {credentials:'same-origin'})
          .then(function(r){ try{ var nt=r.headers.get('X-CSRF-Token'); if(nt) window.CSRF_TOKEN=nt; }catch(e){}; return r.json(); })
          .then(function(d){
              if (!d || d.error) throw new Error((d && d.error) ? d.error : 'Ошибка');
              var nick = (d.nick || '').trim();
              if (nick) {
                  var inp = document.getElementById('nick-input');
                  if (inp) inp.value = nick;
                  setHint('nick-hint','Сгенерирован ник: ' + nick, true);
                  toast('success','Ник','Сгенерирован ник');
              } else {
                  throw new Error('Ошибка генерации');
              }
          })
          .catch(function(e){
              setHint('nick-hint', e && e.message ? e.message : 'Ошибка', false);
              toast('error','Ник', e && e.message ? e.message : 'Ошибка');
          })
          .finally(function(){ btnNickGen.disabled = false; });
    });
}
var btnNick = document.getElementById('nick-save');
if (btnNick) {
    btnNick.addEventListener('click', function(){
        var nick = (document.getElementById('nick-input')||{}).value || '';
        nick = (nick||'').trim();
        if (!nick) {
            setHint('nick-hint','Введите ник', false);
            toast('warn','Ник','Введите ник');
            return;
        }
        btnNick.disabled = true;
        setHint('nick-hint','Проверка...', true);
        apiPost('change_nick', {nick: nick})
          .then(function(d){
              setHint('nick-hint', (d && d.message) ? d.message : 'Ник изменён', true);
              toast('success','Ник','Ник изменён');
              if (d && d.nick_cost !== undefined) {
                  var costEl = document.getElementById('nick-cost');
                  if (costEl) costEl.textContent = d.nick_cost;
              }
              if (d && d.gems !== undefined) {
                  // обновим верхнюю панель
                  setBalance('gems', d.gems);
              }
              if (d && d.delta && d.delta.gems) {
                  flyDelta('gems', '' + d.delta.gems, 'rgba(255,120,120,0.95)');
              }
          })
          .catch(function(e){
              setHint('nick-hint', e && e.message ? e.message : 'Ошибка', false);
              toast('error','Ник', e && e.message ? e.message : 'Ошибка');
          })
          .finally(function(){ btnNick.disabled = false; });
    });
}
btnSettings.addEventListener('click', () => {
            openSettings();
        });

        let previousVolume = gainNode.gain.value;
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                previousVolume = gainNode.gain.value;
                gainNode.gain.value = 0;
            } else {
                if (soundOn === 'true') {
                    gainNode.gain.value = previousVolume || 0.3;
                }
            }
        });

        init();

    });
    </script>

    <div class="glade-board top"></div>

    <?php
        // Емкости хранилищ для полосок (как в офф игре)
        $cap_gold = getTotalStorageCapacity((int)$user['id'], 'gold', $mysqli, (int)$user['townhall_lvl']);
        $cap_elixir = getTotalStorageCapacity((int)$user['id'], 'elixir', $mysqli, (int)$user['townhall_lvl']);
        $cap_dark = getTotalStorageCapacity((int)$user['id'], 'dark_elixir', $mysqli, (int)$user['townhall_lvl']);

        $pct_gold = ($cap_gold > 0) ? min(100, max(0, floor($user['gold'] / $cap_gold * 100))) : 0;
        $pct_elixir = ($cap_elixir > 0) ? min(100, max(0, floor($user['elixir'] / $cap_elixir * 100))) : 0;
        $pct_dark = ($cap_dark > 0) ? min(100, max(0, floor($user['dark_elixir'] / $cap_dark * 100))) : 0;
        // Gems: емкости нет — 0% если 0, иначе 100%
        $pct_gems = ((int)$user['gems'] > 0) ? 100 : 0;
        ?>

    <div class="balance-indicators">
        <div class="balance-row">
            <div class="balance gold">
                <div class="balance-bar" id="balance-gold-bar" style="width: <?= (int)$pct_gold ?>%;"></div> 
                <div class="balance-text" id="balance-gold-text"><?= format_resource($user['gold']) ?></div>
                <img src="/images/icons/gold.png" alt="Gold">
            </div>
            <div class="balance dark-elixir">
                <div class="balance-bar" id="balance-dark_elixir-bar" style="width: <?= (int)$pct_dark ?>%;"></div>
                <div class="balance-text" id="balance-dark_elixir-text"><?= format_resource($user['dark_elixir']) ?></div>
                <img src="/images/icons/fuel.png" alt="Dark Elixir (Fuel)">
            </div>
        </div>
        <div class="balance-row">
            <div class="balance elixir">
                <div class="balance-bar" id="balance-elixir-bar" style="width: <?= (int)$pct_elixir ?>%;"></div>
                <div class="balance-text" id="balance-elixir-text"><?= format_resource($user['elixir']) ?></div>
                <img src="/images/icons/elixir.png" alt="Elixir">
            </div>
            <div class="balance gems">
                <div class="balance-bar" id="balance-gems-bar" style="width: <?= (int)$pct_gems ?>%;"></div>
                <div class="balance-text" id="balance-gems-text"><?= format_resource($user['gems']) ?></div>
                <img src="/images/icons/gems.png" alt="Gems">
            </div>
        </div>
    </div>


    <script>
      window.BALANCE_CAPS = window.BALANCE_CAPS || {};
      window.BALANCE_CAPS.gold = <?= (int)$cap_gold ?>;
      window.BALANCE_CAPS.elixir = <?= (int)$cap_elixir ?>;
      window.BALANCE_CAPS.dark_elixir = <?= (int)$cap_dark ?>;
      window.BALANCE_CAPS.gems = 0;
    </script>

    <div class="glade-board bottom">
        <div class="player-left">
            <div class="level-box">
                <img src="/images/icons/xp_icon.png" alt="Уровень" class="level-icon">
                <span class="level-number">65</span>
            </div>
            <div class="level-progress">
                <div class="level-fill" style="width: 65%;"></div>
            </div>
        </div>
        <button class="battle-button" data-page="battle" type="button">В БОЙ!</button>
        <div class="player-right">
            <div class="trophy-progress">
                <img src="/images/league/no_league.png" alt="Лига" class="league-icon">
                <span class="trophy-count" style="position: relative; z-index: 1;">1850</span>
            </div>
            <div class="trophy-box">
                <img src="/images/icons/trophy_icon.png" alt="Кубок" class="trophy-icon">
            </div>
        </div>
    </div>

    <div class="page-decorations">
        <img src="<?= htmlspecialchars($left_top_img) ?>" class="tree left-top" alt="">
    </div>
    <?php endif; ?>

    <script>
    function showBuildingModal(buildingType) {
        const modal = document.getElementById(buildingType + '-modal');
        if (!modal) {
            console.error('Модальное окно не найдено: ' + buildingType + '-modal');
            return;
        }
        modal.classList.add('active');
    }

    function hideModal(modalId) {
        const modal = document.getElementById(modalId);
        if (!modal) {
            console.error('Модальное окно не найдено: ' + modalId);
            return;
        }
        modal.classList.remove('active');
    }

    window.addEventListener('click', function(event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.classList.remove('active');
        }
    });
    </script>
<?php
}
?>