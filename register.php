<?php
ob_start(); // Включаем буферизацию вывода
require_once 'system/function.php';
require_once 'system/header.php';
// Устанавливаем security-заголовки
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer-when-downgrade');

// Если пользователь уже авторизован - перенаправляем
if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$error = '';
$formData = [
    'login' => '',
    'password' => '',
    'confirm' => ''
];

$turnstile_secret = '0x4AAAAAACKTCQJePWhNVbX7g6qVfKCHxNw';
$turnstile_sitekey = '0x4AAAAAACKTCbltchG6Mirt';

// Обработка формы регистрации
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
       if (!check_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Недействительный CSRF токен');
        }
        
        // Проверка капчи
        if (empty($_POST['cf-turnstile-response'])) {
            throw new RuntimeException('Пожалуйста, пройдите проверку капчи.');
        }
        
        $token = $_POST['cf-turnstile-response'];
        $remote_ip = $_SERVER['REMOTE_ADDR'];
        
        $response = file_get_contents("https://challenges.cloudflare.com/turnstile/v0/siteverify", false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query([
                    'secret' => $turnstile_secret,
                    'response' => $token,
                    'remoteip' => $remote_ip,
                ]),
            ]
        ]));
        
        if (!$response) {
            throw new RuntimeException('Ошибка проверки капчи');
        }
        
        $result = json_decode($response, true);
        if (empty($result['success']) || $result['success'] !== true) {
            throw new RuntimeException('Неверная капча. Попробуйте снова.');
        }

        // Получение и нормализация данных
        $loginRaw = trim((string)($_POST['login'] ?? ''));
        $loginRaw = preg_replace('/[\x00-\x1F\x7F]/u', '', $loginRaw);
        if (strlen($loginRaw) < 3 || strlen($loginRaw) > 20) {
            throw new RuntimeException('Логин: от 3 до 20 символов (ru, en, цифры, _)');
        }
        if (!preg_match('/^[a-zA-Z0-9_\x{0400}-\x{04FF}]{3,20}$/u', $loginRaw)) {
            throw new RuntimeException('Логин: от 3 до 20 символов (ru, en, цифры, _)');
        }
        $formData['login'] = $loginRaw;
        $formData['password'] = $_POST['password'] ?? '';
        $formData['confirm'] = $_POST['confirm'] ?? '';

        // Валидация
        if (empty($formData['login']) || empty($formData['password']) || empty($formData['confirm'])) {
            throw new RuntimeException('Все поля обязательны для заполнения');
        }

        // Валидация логина: от 3 до 20 символов (ru, en, цифры, _)

        if (strlen($formData['password']) < 8) {
            throw new RuntimeException('Минимум 8 символов: заглавные, цифры, спецсимволы');
        }

        if (!preg_match('/\p{Lu}/u', $formData['password']) || 
            !preg_match('/[0-9]/', $formData['password']) || 
            !preg_match('/[^\p{L}\p{N}]/u', $formData['password'])) {
            throw new RuntimeException('Минимум 8 символов: заглавные, цифры, спецсимволы');
        }

        if ($formData['password'] !== $formData['confirm']) {
            throw new RuntimeException('Пароли не совпадают');
        }

        // Проверка существования пользователя
        $stmt = $mysqli->prepare("SELECT id FROM users WHERE login = ?");
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса');
        }
        
        $stmt->bind_param("s", $formData['login']);
        if (!$stmt->execute()) {
            throw new RuntimeException('Ошибка выполнения запроса');
        }
        
        $stmt->store_result();
        
        if ($stmt->num_rows > 0) {
            $stmt->close();
            throw new RuntimeException('Этот логин уже занят');
        }
        $stmt->close();

        // --- НАЧАЛО ТРАНЗАКЦИИ ---
        // Гарантируем, что либо все вставляется, либо ничего
        $mysqli->begin_transaction();

        try {
            // 1. Регистрация пользователя (только login, password и стартовые ресурсы)
            $hashed = password_hash($formData['password'], PASSWORD_BCRYPT, ['cost' => 12]);
            
            // Используем значения из вашего предоставленного кода: gold=500, elixir=200
            $stmt = $mysqli->prepare("INSERT INTO users (login, password, gold, elixir, gems, dark_elixir, last_update) VALUES (?, ?, 500, 200, 500, 0, UNIX_TIMESTAMP())");
            
            if (!$stmt) {
                throw new Exception('Ошибка подготовки запроса USER');
            }
            
            $stmt->bind_param("ss", $formData['login'], $hashed);
            
            if (!$stmt->execute()) {
                throw new Exception('Ошибка регистрации пользователя');
            }
            $new_user_id = $stmt->insert_id;
            $stmt->close();

            // 2. Выдаем стартовые здания в player_buildings
            $starter_buildings = [
                // Town Hall, Level 1
                ['townhall',         1, 22, 22],
                // Resources and Storages
                ['gold_mine',        1, 18, 26],
                ['elixir_collector', 1, 26, 26],
                ['gold_storage',     1, 18, 18],
                ['elixir_storage',   1, 26, 18],
                // Army
                ['barracks',         1, 22, 14],
                ['army_camp',        1, 22, 30],
                // Defense
                ['cannon',           1, 15, 22]
            ];

            $query_buildings = "INSERT INTO player_buildings (user_id, building_id, level, x, y) VALUES (?, ?, ?, ?, ?)";
            $stmt_build = $mysqli->prepare($query_buildings);
            
            if (!$stmt_build) {
                throw new Exception('Ошибка подготовки запроса BUILDINGS');
            }

            foreach ($starter_buildings as $b) {
                // $b[0] = building_id, $b[1] = level, $b[2] = x, $b[3] = y
                $stmt_build->bind_param("isiii", $new_user_id, $b[0], $b[1], $b[2], $b[3]);
                if (!$stmt_build->execute()) {
                    throw new Exception('Ошибка выдачи здания: ' . $b[0]);
                }
            }
            $stmt_build->close();

            // 3. Фиксируем транзакцию
            $mysqli->commit();
            
        } catch (Exception $e) {
            $mysqli->rollback(); // Откат изменений при любой ошибке в базе
            // Преобразуем DB-ошибку в пользовательское исключение
            throw new RuntimeException('Ошибка базы данных: ' . $e->getMessage()); 
        }
            
        // 4. Автоматический вход после успешной регистрации
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);

        $_SESSION['user_id'] = $new_user_id; // Используем ID, полученный в результате INSERT
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        
        // Перенаправление на главную
        header('Location: /');
        exit;
        
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
?>
<style>
html, body {
    height: 100%;
    overflow: hidden;
}
.page-glade {
    position: fixed;
    top: 0;
    left: 50%;
    transform: translateX(-50%);
    width: 100%;
    max-width: var(--map-width);
    height: 100vh;
    max-height: var(--map-height);
    display: flex;
    z-index: 999;
    align-content: center;
    justify-content: center;
    align-items: center;
    overflow-x: auto; /* горизонтальный скрол только при переполнении */
    overflow-y: auto; /* вертикальный скрол только при переполнении */
}
.page-glade {
    overflow: auto !important;              /* скролл только при переполнении */
    -webkit-overflow-scrolling: touch;      /* плавный скролл на iOS */
    overscroll-behavior: contain;           /* не прокидывать скролл наружу */
}

/* не даём flex-детям сжиматься, иначе переполнения не будет */
.page-glade > * {
    flex: 0 0 auto;
}

</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
  try {
    var pg = document.querySelector('.page-glade');
    if (pg) pg.classList.add('allow-scroll','hidden-scroll');
  } catch(e){}
});
</script>




<body>
    <div class="login-container">
       
        <form class="login-form" method="POST" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" novalidate>
            <?= csrfInput() ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger" id="error-message">
                    <?= htmlspecialchars($error) ?>
                </div>
                <script>
                    document.getElementById('error-message').classList.add('shake');
                </script>
            <?php endif; ?>
            
            <div class="form-group">
                <label for="login">Логин</label>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="text" 
                           id="login" 
                           name="login" 
                           class="form-control" 
                           value="<?= htmlspecialchars($formData['login']) ?>" 
                           required
                           minlength="3"
                           maxlength="20"
                           pattern="[A-Za-z0-9_\u0400-\u04FF]+"
                           title="ru/en буквы, цифры, _">
                    <button type="button" id="gen-login" class="btn" style="white-space:nowrap;padding:10px 12px;">🎲 Ник</button>
                </div>
                <small class="form-text">От 3 до 20 символов (ru, en, цифры, _)</small>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       class="form-control" 
                       required
                       minlength="8"
                       oninput="updatePasswordStrength()">
                <div class="password-strength">
                    <div class="password-strength-fill" id="password-strength"></div>
                </div>
                <small class="form-text">Минимум 8 символов: заглавные, цифры, спецсимволы</small>
                <div style="display:flex;gap:10px;margin-top:10px;flex-wrap:wrap;">
                    <button type="button" id="gen-pass" class="btn" style="padding:10px 12px;">Сгенерировать пароль</button>
                    <button type="button" id="copy-pass" class="btn" style="padding:10px 12px;">Копировать</button>
                </div>
            </div>
            
            <div class="form-group">
                <label for="confirm">Подтверждение пароля</label>
                <input type="password" 
                       id="confirm" 
                       name="confirm" 
                       class="form-control" 
                       required
                       minlength="8">
            </div>
<center>
<div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_sitekey) ?>"></div>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</center>
            <button type="submit" class="btn">
                Зарегистрироваться
            </button>
            
            <div class="register-link">
                Уже есть аккаунт? <a href="login.php">Войти</a>
            </div>
        </form>
    </div>
    
    <script>
        // Функция оценки сложности пароля
        function updatePasswordStrength() {
            const password = document.getElementById('password').value;
            const strengthBar = document.getElementById('password-strength');
            let strength = 0;
            
            // Проверка длины
            if (password.length > 7) strength += 20;
            if (password.length > 11) strength += 20;
            
            // Проверка наличия разных типов символов
            if (/[A-Z]/.test(password)) strength += 20;
            if (/[0-9]/.test(password)) strength += 20;
            if (/[^a-zA-Z0-9]/.test(password)) strength += 20;
            
            // Обновление индикатора
            strengthBar.style.width = strength + '%';
            
            // Изменение цвета в зависимости от сложности
            if (strength < 40) {
                strengthBar.style.backgroundColor = '#ff5722';
            } else if (strength < 80) {
                strengthBar.style.backgroundColor = '#ffc107';
            } else {
                strengthBar.style.backgroundColor = '#4caf50';
            }
        }
        
        // Улучшение UX
        document.addEventListener('DOMContentLoaded', function() {
            // Фокус на поле логина при загрузке
            document.getElementById('login').focus();
            
            // Валидация формы
            document.querySelector('form').addEventListener('submit', function(e) {
                let valid = true;
                
                // Простая валидация на клиенте
                document.querySelectorAll('.form-control').forEach(function(input) {
                    if (!input.value.trim()) {
                        input.style.borderColor = '#e53935';
                        valid = false;
                    }
                });
                
                // Проверка совпадения паролей
                const password = document.getElementById('password').value;
                const confirm = document.getElementById('confirm').value;
                
                if (password !== confirm) {
                    document.getElementById('confirm').style.borderColor = '#e53935';
                    valid = false;
                }
                
                if (!valid) {
                    e.preventDefault();
                }
            });
            
            // Сброс цвета при вводе
            document.querySelectorAll('.form-control').forEach(function(input) {
                input.addEventListener('input', function() {
                    this.style.borderColor = '';
                });
            });

            // --- Генерация ника (уникального) ---
            var btnGenLogin = document.getElementById('gen-login');
            if (btnGenLogin) {
                btnGenLogin.addEventListener('click', function(){
                    btnGenLogin.disabled = true;
                    fetch('/nick_gen.php', {credentials:'same-origin'})
                      .then(function(r){ return r.json(); })
                      .then(function(d){
                          if (!d || !d.ok || !d.login) throw new Error((d && d.error) ? d.error : 'Ошибка');
                          document.getElementById('login').value = d.login;
                      })
                      .catch(function(){})
                      .finally(function(){ btnGenLogin.disabled = false; });
                });
            }

            // --- Генерация/копирование пароля ---
            function genStrongPassword(){
                var lowers = 'abcdefghijkmnopqrstuvwxyz';
                var uppers = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
                var digits = '23456789';
                var specials = '!@#$%^&*_-+=?';
                var all = lowers + uppers + digits + specials;
                var len = 12 + Math.floor(Math.random()*5);
                function pick(set){ return set.charAt(Math.floor(Math.random()*set.length)); }
                var out = [pick(lowers), pick(uppers), pick(digits), pick(specials)];
                while (out.length < len) out.push(pick(all));
                for (var i = out.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var t = out[i]; out[i] = out[j]; out[j] = t;
                }
                return out.join('');
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

            var btnGenPass = document.getElementById('gen-pass');
            if (btnGenPass) {
                btnGenPass.addEventListener('click', function(){
                    var p = genStrongPassword();
                    document.getElementById('password').value = p;
                    document.getElementById('confirm').value = p;
                    updatePasswordStrength();
                });
            }
            var btnCopyPass = document.getElementById('copy-pass');
            if (btnCopyPass) {
                btnCopyPass.addEventListener('click', function(){
                    var p = document.getElementById('password').value || '';
                    copyToClipboard(p);
                });
            }
        });
    </script>
</body>
</html>