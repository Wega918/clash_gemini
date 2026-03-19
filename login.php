<?php
ob_start(); // Включаем буферизацию вывода
// Продолжаем основной код авторизации только после успешной проверки капчи
require_once 'system/function.php';
// Если пользователь уже авторизован - перенаправляем
if (isLoggedIn()) {
    header('Location: /');
    exit;
}


$turnstile_secret = '0x4AAAAAABgrOxFOs-yAgOChyDd1VllSHcg';
$turnstile_sitekey = '0x4AAAAAABgrOz68iJtS7HNQ';

$error = '';
$login = '';
$attempts_exceeded = false;

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!check_csrf($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Недействительный CSRF токен');
        }
        
/*         // Проверка капчи
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
        } */

        $login = cleanString($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Проверка попыток входа
        if (isBruteforceAttempt($login)) {
            $attempts_exceeded = true;
            throw new RuntimeException('Слишком много попыток. Попробуйте позже.');
        }

        // Поиск пользователя в БД
        $stmt = $mysqli->prepare("SELECT id, password, login_attempts, last_attempt FROM users WHERE login = ?");
        if (!$stmt) {
            throw new RuntimeException('Ошибка подготовки запроса');
        }
        
        $stmt->bind_param("s", $login);
        if (!$stmt->execute()) {
            throw new RuntimeException('Ошибка выполнения запроса');
        }
        
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Проверка пароля
        if (!$user || !password_verify($password, $user['password'])) {
            logFailedLoginAttempt($login);
            throw new RuntimeException('Неверный логин или пароль');
        }

        // Сброс попыток
        resetLoginAttempts($user['id']);

        // Перезапуск сессии
        session_unset();
        session_destroy();
        session_start();
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

        // Защита от open redirect
        $returnUrl = $_SESSION['return_url'] ?? '/';
        if (parse_url($returnUrl, PHP_URL_HOST)) {
            $returnUrl = '/';
        }
        
        header('Location: ' . $returnUrl);
        exit;
        
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
require_once 'system/header.php';


?>
<style>
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
    flex-direction: row;
    flex-wrap: wrap;
    justify-content: center;
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

<div class="login-header">
            <h1><img src="<?= htmlspecialchars(season_img('/images/diz/logo.png')) ?>" width="90%"></h1>
        </div>

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
                <input type="text" 
                       id="login" 
                       name="login" 
                       class="form-control" 
                       value="<?= htmlspecialchars($login) ?>" 
                       required
                       <?= $attempts_exceeded ? 'disabled' : '' ?>>
            </div>
            
            <div class="form-group">
                <label for="password">Пароль</label>
                <input type="password" 
                       id="password" 
                       name="password" 
                       class="form-control" 
                       required
                       <?= $attempts_exceeded ? 'disabled' : '' ?>>
            </div>
<?
/* <center>
<div class="cf-turnstile" data-sitekey="<?= htmlspecialchars($turnstile_sitekey) ?>"></div>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</center> */
?>
            <button type="submit" class="btn" <?= $attempts_exceeded ? 'disabled' : '' ?>>
                Войти
            </button>
            
            <div class="register-link">
                Нет аккаунта? <a href="register.php">Зарегистрируйтесь</a>
            </div>

            <div class="register-link" style="margin-top:10px;opacity:.95;">
                Забыли пароль? <a href="forgot_password.php">Восстановить</a>
            </div>
        </form>
    </div>
    
    <!-- Подключение modernizr для обнаружения возможностей браузера -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/modernizr/2.8.3/modernizr.min.js"></script>
    
    <script>
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
        });
    </script>


<?
//require_once 'system/footer.php';
?>