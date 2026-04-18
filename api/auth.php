<?php
/**
 * BeSix Platform — Auth API
 * Endpoint: /api/auth.php?action=<action>
 */

// ── CONFIG ────────────────────────────────────────────────────────────
$cfg = __DIR__ . '/config.php';
if (file_exists($cfg)) require_once $cfg;

function env(string $key, string $default = ''): string {
    return getenv($key) ?: ($_ENV[$key] ?? $default);
}

// Jediný admin platformy — hardcoded, nedá se změnit z žádné jiné appky
define('PLATFORM_ADMIN_EMAIL', 'david.besse46@gmail.com');

// ── GLOBAL ERROR → JSON ───────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'Serverová chyba: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
});

// ── HEADERS ───────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$appUrl  = rtrim(env('APP_URL', 'https://besix.cz'), '/');
if ($origin === $appUrl || str_starts_with($origin, 'https://besix.cz')) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── SESSION ───────────────────────────────────────────────────────────
// Cookie sdílená přes všechny subdomény .besix.cz
session_set_cookie_params([
    'lifetime' => 86400 * 30,   // 30 dní
    'path'     => '/',
    'domain'   => '.besix.cz',
    'secure'   => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
ini_set('session.gc_maxlifetime', '2592000');
session_name('BESIX_SESS');
session_start();

// ── DATABASE ──────────────────────────────────────────────────────────
function db(): PDO {
    static $pdo;
    if ($pdo) return $pdo;
    $dsn = 'mysql:host=' . env('DB_HOST') . ';dbname=' . env('DB_NAME') . ';charset=utf8mb4';
    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS `users` (
            `id`                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `name`                VARCHAR(120)  NOT NULL,
            `email`               VARCHAR(180)  NOT NULL,
            `password_hash`       VARCHAR(255)  NOT NULL,
            `avatar_color`        VARCHAR(10)   NOT NULL DEFAULT '#4A5340',
            `google_id`           VARCHAR(100)  DEFAULT NULL,
            `is_verified`         TINYINT(1)    NOT NULL DEFAULT 0,
            `verification_token`  VARCHAR(80)   DEFAULT NULL,
            `reset_token`         VARCHAR(80)   DEFAULT NULL,
            `reset_token_expires` DATETIME      DEFAULT NULL,
            `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_email`     (`email`),
            UNIQUE KEY `uq_google_id` (`google_id`),
            KEY `idx_verification`    (`verification_token`),
            KEY `idx_reset`           (`reset_token`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `platform_invitations` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `email`      VARCHAR(180) NOT NULL DEFAULT '',
            `invited_by` INT UNSIGNED NOT NULL DEFAULT 0,
            `sent_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        "CREATE TABLE IF NOT EXISTS `app_access` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id`    INT UNSIGNED NOT NULL,
            `app`        VARCHAR(20)  NOT NULL,
            `role`       VARCHAR(20)  NOT NULL DEFAULT 'clen',
            `granted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_user_app` (`user_id`, `app`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $sql) {
        try { $pdo->exec($sql); } catch (PDOException $e) { /* table exists or hosting limitation */ }
    }

    // Repair platform_invitations table — add missing columns if table was created partially
    try {
        $existing = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `platform_invitations`")->fetchAll() as $row) {
            $existing[] = $row['Field'];
        }
        if (!in_array('email', $existing)) {
            $pdo->exec("ALTER TABLE `platform_invitations` ADD COLUMN `email` VARCHAR(180) NOT NULL DEFAULT '' AFTER `id`");
        }
        if (!in_array('invited_by', $existing)) {
            $pdo->exec("ALTER TABLE `platform_invitations` ADD COLUMN `invited_by` INT UNSIGNED NOT NULL DEFAULT 0");
        }
        if (!in_array('sent_at', $existing)) {
            $pdo->exec("ALTER TABLE `platform_invitations` ADD COLUMN `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
        // Add unique index on email if missing
        $indexes = [];
        foreach ($pdo->query("SHOW INDEX FROM `platform_invitations`")->fetchAll() as $row) {
            $indexes[] = $row['Key_name'];
        }
        if (!in_array('uq_inv_email', $indexes)) {
            // Remove empty-email duplicates first, then add unique index
            $pdo->exec("DELETE FROM `platform_invitations` WHERE `email` = ''");
            try { $pdo->exec("ALTER TABLE `platform_invitations` ADD UNIQUE KEY `uq_inv_email` (`email`)"); }
            catch (PDOException $e) { /* still duplicates, skip */ }
        }
        // Always clean up garbage rows from broken migration
        $pdo->exec("DELETE FROM `platform_invitations` WHERE `email` = ''"  );

        // Fix any columns without default values that we don't manage
        $cols = $pdo->query("SHOW COLUMNS FROM `platform_invitations`")->fetchAll();
        foreach ($cols as $col) {
            if ($col['Default'] === null && $col['Null'] === 'NO'
                && !in_array($col['Field'], ['id', 'email', 'invited_by', 'sent_at'])) {
                try {
                    $pdo->exec("ALTER TABLE `platform_invitations` ALTER COLUMN `{$col['Field']}` SET DEFAULT 0");
                } catch (PDOException $e) {
                    try {
                        $pdo->exec("ALTER TABLE `platform_invitations` ALTER COLUMN `{$col['Field']}` SET DEFAULT ''");
                    } catch (PDOException $e2) { /* skip */ }
                }
            }
        }
    } catch (PDOException $e) { /* invitations table doesn't exist yet */ }
}

// ── HELPERS ───────────────────────────────────────────────────────────
function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?: $_POST;
}

function requireAuth(): array {
    if (empty($_SESSION['user_id'])) json_out(['error' => 'Nepřihlášen'], 401);
    $st = db()->prepare('SELECT id, name, email, avatar_color, is_verified FROM users WHERE id = ?');
    $st->execute([$_SESSION['user_id']]);
    $user = $st->fetch();
    if (!$user) { session_destroy(); json_out(['error' => 'Uživatel nenalezen'], 401); }
    return $user;
}

function randomColor(): string {
    return ['#4A5340', '#5c6b4e', '#6a7a5a', '#3e4836', '#c9a84c'][array_rand([0,1,2,3,4])];
}

function sendEmail(string $to, string $toName, string $subject, string $html): void {
    $apiKey = env('BREVO_API_KEY');
    $from   = env('MAIL_FROM', 'noreply@besix.cz');
    $payload = json_encode([
        'sender'      => ['email' => $from, 'name' => 'BeSix Platform'],
        'to'          => [['email' => $to, 'name' => $toName]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ]);
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'api-key: ' . $apiKey],
        CURLOPT_TIMEOUT        => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

function emailTemplate(string $title, string $body): string {
    return "<!DOCTYPE html>
<html lang='cs'>
<head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
<title>$title</title></head>
<body style='margin:0;padding:0;background:#2a3026;font-family:Arial,sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#2a3026;padding:40px 16px;'>
<tr><td align='center'>
<table width='520' cellpadding='0' cellspacing='0' style='max-width:520px;width:100%;'>

  <!-- top border gold line -->
  <tr><td style='background:linear-gradient(90deg,transparent,#c9a84c,transparent);height:1px;font-size:0;line-height:0;'>&nbsp;</td></tr>

  <!-- main card -->
  <tr><td style='background:#353c2e;border:1px solid rgba(255,255,255,0.1);border-top:none;padding:0;'>

    <!-- header -->
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:36px 40px 28px;border-bottom:1px solid rgba(255,255,255,0.08);'>
        <table cellpadding='0' cellspacing='0'>
          <tr>
            <td style='padding-right:14px;vertical-align:middle;'>
              <img src='https://besix.cz/besix_logo_bila.png' width='42' height='42'
                   style='display:block;width:42px;height:42px;border:0;' alt='BeSix'>
            </td>
            <td style='vertical-align:middle;'>
              <div style='font-family:Georgia,serif;font-size:22px;font-weight:bold;
                          letter-spacing:4px;color:#c9a84c;line-height:1;'>BESIX</div>
              <div style='font-size:9px;letter-spacing:5px;color:rgba(255,255,255,0.35);
                          text-transform:uppercase;margin-top:4px;'>PLATFORM</div>
            </td>
          </tr>
        </table>
      </td></tr>

      <!-- title bar -->
      <tr><td style='padding:22px 40px 0;'>
        <div style='font-size:9px;letter-spacing:4px;text-transform:uppercase;
                    color:rgba(201,168,76,0.6);margin-bottom:8px;'>$title</div>
        <div style='width:32px;height:1px;background:#c9a84c;opacity:0.5;'></div>
      </td></tr>

      <!-- body content -->
      <tr><td style='padding:24px 40px 36px;'>$body</td></tr>

      <!-- footer -->
      <tr><td style='padding:20px 40px;border-top:1px solid rgba(255,255,255,0.07);
                     background:rgba(0,0,0,0.15);'>
        <table width='100%' cellpadding='0' cellspacing='0'>
          <tr>
            <td style='font-size:10px;letter-spacing:1px;color:rgba(255,255,255,0.2);'>
              © 2025 BeSix s.r.o.
            </td>
            <td align='right' style='font-size:10px;letter-spacing:1px;color:rgba(201,168,76,0.35);'>
              Digitalizace stavebnictví
            </td>
          </tr>
        </table>
      </td></tr>
    </table>

  </td></tr>
  <!-- bottom border gold line -->
  <tr><td style='background:linear-gradient(90deg,transparent,#c9a84c,transparent);height:1px;font-size:0;line-height:0;'>&nbsp;</td></tr>

</table>
</td></tr></table>
</body></html>";
}

// ── ROUTER ────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? '';

match ($action) {
    'register'        => handleRegister(),
    'verify'          => handleVerify(),
    'login'           => handleLogin(),
    'logout'          => handleLogout(),
    'me'              => handleMe(),
    'forgot'          => handleForgot(),
    'reset'           => handleReset(),
    'google_redirect' => handleGoogleRedirect(),
    'google_callback' => handleGoogleCallback(),
    'members'         => handleMembers(),
    'invitations'     => handleInvitations(),
    'invite'          => handleInvite(),
    'cancel_invite'   => handleCancelInvite(),
    'delete_member'   => handleDeleteMember(),
    'set_app_access'  => handleSetAppAccess(),
    'db_info'         => handleDbInfo(),
    default           => json_out(['error' => 'Neznámá akce'], 404),
};

// ════════════════════════════════════════════════════════════════════
// HANDLERS
// ════════════════════════════════════════════════════════════════════

function handleRegister(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $b     = body();
    $name  = trim($b['name'] ?? '');
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = $b['password'] ?? '';
    $pass2 = $b['password_confirm'] ?? '';

    if (!$name)                                          json_out(['error' => 'Jméno je povinné'], 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))      json_out(['error' => 'Neplatný e-mail'], 422);
    if (strlen($pass) < 8)                               json_out(['error' => 'Heslo musí mít alespoň 8 znaků'], 422);
    if ($pass !== $pass2)                                json_out(['error' => 'Hesla se neshodují'], 422);

    $chk = db()->prepare('SELECT id FROM users WHERE email = ?');
    $chk->execute([$email]);
    if ($chk->fetch()) json_out(['error' => 'E-mail je již zaregistrován'], 409);

    $token = bin2hex(random_bytes(32));
    $hash  = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    $color = randomColor();

    db()->prepare('INSERT INTO users (name, email, password_hash, avatar_color, is_verified, verification_token)
                   VALUES (?, ?, ?, ?, 0, ?)')
       ->execute([$name, $email, $hash, $color, $token]);

    $link = rtrim(env('APP_URL'), '/') . '/api/auth.php?action=verify&token=' . $token;
    $html = emailTemplate('Ověření e-mailu', "
        <p style='margin:0 0 20px;'>Ahoj <strong>$name</strong>,<br>
        pro dokončení registrace potvrď svůj e-mail kliknutím na tlačítko níže.</p>
        <a href='$link'
           style='display:inline-block;padding:13px 32px;background:#4A5340;color:#fff;
                  border-radius:8px;text-decoration:none;font-weight:600;
                  letter-spacing:.1em;font-family:Rajdhani,sans-serif;'>
          Ověřit e-mail
        </a>
        <p style='margin:20px 0 0;font-size:.78rem;color:rgba(255,255,255,.4);'>
          Odkaz platí 24 hodin. Pokud jsi o registraci nežádal/a, tento e-mail ignoruj.
        </p>");
    sendEmail($email, $name, 'BeSix — Ověřte svůj e-mail', $html);

    json_out(['success' => true, 'message' => 'Registrace proběhla. Zkontroluj e-mail pro ověření.']);
}

function handleVerify(): never {
    $token = $_GET['token'] ?? '';
    $appUrl = rtrim(env('APP_URL'), '/');

    if (!$token) { header("Location: $appUrl?verified=invalid"); exit; }

    $st = db()->prepare('SELECT id FROM users WHERE verification_token = ? AND is_verified = 0');
    $st->execute([$token]);
    $user = $st->fetch();

    if (!$user) { header("Location: $appUrl?verified=invalid"); exit; }

    db()->prepare('UPDATE users SET is_verified = 1, verification_token = NULL WHERE id = ?')
       ->execute([$user['id']]);

    header("Location: $appUrl?verified=1");
    exit;
}

function handleLogin(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $b     = body();
    $email = strtolower(trim($b['email'] ?? ''));
    $pass  = $b['password'] ?? '';

    if (!$email || !$pass) json_out(['error' => 'Zadej e-mail a heslo'], 422);

    $st = db()->prepare('SELECT * FROM users WHERE email = ?');
    $st->execute([$email]);
    $user = $st->fetch();

    if (!$user || $user['password_hash'] === '!google')  json_out(['error' => 'Neplatné přihlašovací údaje'], 401);
    if (!password_verify($pass, $user['password_hash']))  json_out(['error' => 'Neplatné přihlašovací údaje'], 401);
    if (!$user['is_verified'])                            json_out(['error' => 'Nejprve ověř svůj e-mail'], 403);

    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$user['id'];
    $_SESSION['user_name']    = $user['name'];
    $_SESSION['avatar_color'] = $user['avatar_color'];

    json_out(['success' => true, 'user' => [
        'id'           => (int)$user['id'],
        'name'         => $user['name'],
        'email'        => $user['email'],
        'avatar_color' => $user['avatar_color'],
    ]]);
}

function handleLogout(): never {
    session_destroy();
    json_out(['success' => true]);
}

function handleMe(): never {
    $user = requireAuth();
    json_out(['user' => array_merge($user, ['is_admin' => ($user['email'] === PLATFORM_ADMIN_EMAIL)])]);
}

function handleForgot(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $b     = body();
    $email = strtolower(trim($b['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Neplatný e-mail'], 422);

    $st = db()->prepare("SELECT id, name FROM users WHERE email = ? AND password_hash != '!google'");
    $st->execute([$email]);
    $user = $st->fetch();

    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        db()->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?')
           ->execute([$token, $expires, $user['id']]);

        $link = rtrim(env('APP_URL'), '/') . '?reset_token=' . $token;
        $html = emailTemplate('Reset hesla', "
            <p style='margin:0 0 20px;'>Ahoj <strong>{$user['name']}</strong>,<br>
            obdrželi jsme žádost o reset hesla k tvému účtu.</p>
            <a href='$link'
               style='display:inline-block;padding:13px 32px;background:#4A5340;color:#fff;
                      border-radius:8px;text-decoration:none;font-weight:600;
                      letter-spacing:.1em;font-family:Rajdhani,sans-serif;'>
              Nastavit nové heslo
            </a>
            <p style='margin:20px 0 0;font-size:.78rem;color:rgba(255,255,255,.4);'>
              Odkaz platí 1 hodinu. Pokud jsi reset hesla nežádal/a, tento e-mail ignoruj.
            </p>");
        sendEmail($email, $user['name'], 'BeSix — Reset hesla', $html);
    }

    // Vždy vrať success aby nedošlo k enumeraci e-mailů
    json_out(['success' => true, 'message' => 'Pokud e-mail existuje, obdržíš instrukce.']);
}

function handleReset(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $b    = body();
    $tok  = trim($b['token'] ?? '');
    $pass = $b['password'] ?? '';
    $pass2 = $b['password_confirm'] ?? '';

    if (!$tok)             json_out(['error' => 'Neplatný token'], 400);
    if (strlen($pass) < 8) json_out(['error' => 'Heslo musí mít alespoň 8 znaků'], 422);
    if ($pass !== $pass2)  json_out(['error' => 'Hesla se neshodují'], 422);

    $st = db()->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
    $st->execute([$tok]);
    $user = $st->fetch();
    if (!$user) json_out(['error' => 'Odkaz je neplatný nebo vypršel'], 400);

    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 12]);
    db()->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?')
       ->execute([$hash, $user['id']]);

    json_out(['success' => true, 'message' => 'Heslo bylo úspěšně změněno.']);
}

function handleGoogleRedirect(): never {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $appUrl   = rtrim(env('APP_URL'), '/');
    $callback = "$appUrl/api/auth.php?action=google_callback";
    $params   = http_build_query([
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'redirect_uri'  => $callback,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

function handleGoogleCallback(): never {
    $code  = $_GET['code']  ?? '';
    $state = $_GET['state'] ?? '';
    $appUrl = rtrim(env('APP_URL'), '/');

    if (!$code || $state !== ($_SESSION['oauth_state'] ?? '')) {
        header("Location: $appUrl?auth=error"); exit;
    }
    unset($_SESSION['oauth_state']);

    // Exchange code → token
    $callback = "$appUrl/api/auth.php?action=google_callback";
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'),
            'redirect_uri'  => $callback,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (empty($res['id_token'])) {
        header("Location: $appUrl?auth=error"); exit;
    }

    // Decode JWT payload (no signature check — trusted from Google endpoint)
    $parts   = explode('.', $res['id_token']);
    $payload = json_decode(base64_decode(str_pad(
        strtr($parts[1], '-_', '+/'),
        strlen($parts[1]) + (4 - strlen($parts[1]) % 4) % 4,
        '='
    )), true);

    $googleId = $payload['sub']   ?? '';
    $email    = strtolower($payload['email'] ?? '');
    $name     = $payload['name']  ?? $email;

    if (!$email || !$googleId) { header("Location: $appUrl?auth=error"); exit; }

    // Najdi nebo vytvoř uživatele
    $st = db()->prepare('SELECT * FROM users WHERE google_id = ? OR email = ?');
    $st->execute([$googleId, $email]);
    $user = $st->fetch();

    if ($user) {
        if (!$user['google_id']) {
            db()->prepare('UPDATE users SET google_id = ?, is_verified = 1 WHERE id = ?')
               ->execute([$googleId, $user['id']]);
        }
    } else {
        $color = randomColor();
        db()->prepare('INSERT INTO users (name, email, password_hash, avatar_color, google_id, is_verified)
                       VALUES (?, ?, ?, ?, ?, 1)')
           ->execute([$name, $email, '!google', $color, $googleId]);
        $user = [
            'id'           => (int)db()->lastInsertId(),
            'name'         => $name,
            'email'        => $email,
            'avatar_color' => $color,
        ];
    }

    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$user['id'];
    $_SESSION['user_name']    = $user['name'];
    $_SESSION['avatar_color'] = $user['avatar_color'];

    header("Location: $appUrl?auth=success");
    exit;
}

// ── MEMBERS ───────────────────────────────────────────────────────────
function handleMembers(): never {
    $me      = requireAuth();
    $isAdmin = ($me['email'] === PLATFORM_ADMIN_EMAIL);

    if ($isAdmin) {
        $rows = db()->query(
            'SELECT id, name, email, avatar_color, is_verified, created_at FROM users ORDER BY created_at ASC'
        )->fetchAll();
    } else {
        // Non-admins see only themselves + members they personally invited
        $st = db()->prepare(
            'SELECT id, name, email, avatar_color, is_verified, created_at FROM users WHERE id = ?
             UNION
             SELECT u.id, u.name, u.email, u.avatar_color, u.is_verified, u.created_at
             FROM users u
             INNER JOIN platform_invitations pi ON pi.email = u.email AND pi.invited_by = ?
             ORDER BY created_at ASC'
        );
        $st->execute([(int)$me['id'], (int)$me['id']]);
        $rows = $st->fetchAll();
    }

    // App access per user (app_access table is platform-managed)
    $accessByUser = [];
    try {
        foreach (db()->query('SELECT user_id, app, role FROM app_access ORDER BY app') as $a) {
            $accessByUser[(int)$a['user_id']][] = ['app' => $a['app'], 'role' => $a['role']];
        }
    } catch (PDOException $e) {}

    // Projects per user — detect schema + membership tables via INFORMATION_SCHEMA
    $projectsByUser = [];
    try {
        $projCols = array_column(db()->query('SHOW COLUMNS FROM projects')->fetchAll(), 'Field');
        $nameCol  = in_array('name', $projCols)          ? 'name'
                  : (in_array('title', $projCols)        ? 'title'
                  : (in_array('project_name', $projCols) ? 'project_name' : null));

        if ($nameCol) {
            $appCol  = in_array('app', $projCols)       ? 'app'
                     : (in_array('app_name', $projCols) ? 'app_name'
                     : (in_array('type', $projCols)     ? 'type' : null));
            $appExpr = $appCol ? $appCol : "''";

            // Helper: derive app label from table name or value
            $resolveApp = function(string $raw, string $srcTable): string {
                if ($raw !== '') return $raw;
                foreach (['board','plans','plan','time','organs','cad'] as $kw) {
                    if (stripos($srcTable, $kw) !== false) return ($kw === 'plan' ? 'plans' : $kw);
                }
                return '–';
            };

            // Owner projects — keyed by lowercase name to deduplicate
            $owned = db()->query(
                "SELECT created_by AS uid, id, $nameCol AS name, $appExpr AS app
                 FROM projects ORDER BY $nameCol"
            )->fetchAll();
            foreach ($owned as $p) {
                $key = strtolower(trim($p['name']));
                $uid = (int)$p['uid'];
                $projectsByUser[$uid][$key] = [
                    'app'   => $resolveApp(trim((string)$p['app']), 'projects'),
                    'name'  => $p['name'],
                    'owner' => true,
                ];
            }

            // Find all tables that have project_id + a user/member column
            $memberTables = db()->query(
                "SELECT c1.TABLE_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS c1
                 JOIN INFORMATION_SCHEMA.COLUMNS c2
                   ON c1.TABLE_NAME = c2.TABLE_NAME AND c1.TABLE_SCHEMA = c2.TABLE_SCHEMA
                 WHERE c1.TABLE_SCHEMA = DATABASE()
                   AND c1.COLUMN_NAME = 'project_id'
                   AND c2.COLUMN_NAME IN ('user_id','member_id','invited_user_id')
                   AND c1.TABLE_NAME NOT IN ('projects','platform_invitations','invitations')"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($memberTables as $tbl) {
                try {
                    $tCols   = array_column(db()->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(), 'Field');
                    $userCol = in_array('user_id', $tCols)    ? 'user_id'
                             : (in_array('member_id', $tCols) ? 'member_id' : 'invited_user_id');
                    $rows2 = db()->query(
                        "SELECT m.$userCol AS uid, p.id, p.$nameCol AS name, p.$appExpr AS app
                         FROM `$tbl` m
                         JOIN projects p ON p.id = m.project_id
                         ORDER BY p.$nameCol"
                    )->fetchAll();
                    foreach ($rows2 as $r) {
                        $uid = (int)$r['uid'];
                        $key = strtolower(trim($r['name']));
                        // Owned entry wins; otherwise record membership
                        if (!isset($projectsByUser[$uid][$key])) {
                            $projectsByUser[$uid][$key] = [
                                'app'   => $resolveApp(trim((string)$r['app']), $tbl),
                                'name'  => $r['name'],
                                'owner' => false,
                            ];
                        } elseif ($projectsByUser[$uid][$key]['app'] === '–') {
                            // Upgrade app label if we now know the source
                            $projectsByUser[$uid][$key]['app'] = $resolveApp(trim((string)$r['app']), $tbl);
                        }
                    }
                } catch (PDOException $e) {}
            }
        }
    } catch (PDOException $e) {}

    // Flatten (deduplicated by lowercase name)
    foreach ($projectsByUser as $uid => $map) {
        $projectsByUser[$uid] = array_values($map);
    }

    $members = [];
    foreach ($rows as $u) {
        $uid = (int)$u['id'];
        $members[] = [
            'id'           => $uid,
            'name'         => $u['name'],
            'email'        => $u['email'],
            'avatar_color' => $u['avatar_color'],
            'is_verified'  => (bool)$u['is_verified'],
            'is_admin'     => ($u['email'] === PLATFORM_ADMIN_EMAIL),
            'created_at'   => substr($u['created_at'], 0, 10),
            'apps'         => $accessByUser[$uid] ?? [],
            'projects'     => $projectsByUser[$uid] ?? [],
        ];
    }
    json_out(['members' => $members]);
}

function handleInvitations(): never {
    $me      = requireAuth();
    $isAdmin = ($me['email'] === PLATFORM_ADMIN_EMAIL);

    if ($isAdmin) {
        $st = db()->query(
            "SELECT i.email, i.sent_at,
                    u.id AS user_id, u.name AS user_name, u.created_at AS accepted_at
             FROM platform_invitations i
             LEFT JOIN users u ON u.email = i.email
             WHERE i.email != ''
             ORDER BY i.sent_at DESC"
        );
        $rows = $st->fetchAll();
    } else {
        $st = db()->prepare(
            "SELECT i.email, i.sent_at,
                    u.id AS user_id, u.name AS user_name, u.created_at AS accepted_at
             FROM platform_invitations i
             LEFT JOIN users u ON u.email = i.email
             WHERE i.email != '' AND i.invited_by = ?
             ORDER BY i.sent_at DESC"
        );
        $st->execute([(int)$me['id']]);
        $rows = $st->fetchAll();
    }

    $list = [];
    foreach ($rows as $r) {
        $list[] = [
            'email'       => $r['email'],
            'sent_at'     => substr($r['sent_at'], 0, 10),
            'accepted'    => !empty($r['user_id']),
            'user_name'   => $r['user_name'] ?? null,
            'accepted_at' => $r['accepted_at'] ? substr($r['accepted_at'], 0, 10) : null,
        ];
    }
    json_out(['invitations' => $list]);
}

function handleInvite(): never {
    requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $b     = body();
    $email = strtolower(trim($b['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Neplatný e-mail'], 422);

    // Already registered?
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) json_out(['error' => 'Tento e-mail je již registrován'], 409);

    // Already invited? Resend allowed — upsert
    $invBy = (int)$_SESSION['user_id'];
    db()->prepare(
        'INSERT INTO platform_invitations (email, invited_by) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE sent_at = NOW(), invited_by = VALUES(invited_by)'
    )->execute([$email, $invBy]);

    $link = rtrim(env('APP_URL'), '/') . '/?register=1';
    $html = emailTemplate('Pozvánka do platformy', "
        <p style='margin:0 0 8px;font-size:22px;font-weight:bold;letter-spacing:2px;
                  color:#ffffff;font-family:Georgia,serif;'>Byli jste pozváni</p>
        <p style='margin:0 0 24px;font-size:13px;letter-spacing:1px;color:rgba(201,168,76,0.8);
                  text-transform:uppercase;'>do BeSix Platform</p>

        <div style='font-size:10px;letter-spacing:3px;text-transform:uppercase;
                    color:rgba(201,168,76,0.6);margin-bottom:10px;'>Digitální nástroje</div>
        <table width='100%' cellpadding='0' cellspacing='0' style='margin:0 0 28px;
               background:rgba(201,168,76,0.06);border:1px solid rgba(201,168,76,0.2);
               border-left:3px solid #c9a84c;'>
          <tr>
            <!-- Board -->
            <td width='20%' align='center' valign='bottom'
                style='padding:14px 4px 10px;border-right:1px solid rgba(255,255,255,0.07);'>
              <svg width='44' height='40' viewBox='0 0 110 100' fill='none'
                   stroke='rgba(255,255,255,0.75)' stroke-linecap='round' stroke-linejoin='round'
                   style='display:block;margin:0 auto;'>
                <rect x='2' y='8' width='30' height='84' rx='4' stroke-width='1.5'/>
                <rect x='40' y='8' width='30' height='84' rx='4' stroke-width='1.5'/>
                <rect x='78' y='8' width='30' height='84' rx='4' stroke-width='1.5'/>
                <rect x='6' y='14' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='6' y='32' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='6' y='50' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='44' y='14' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='44' y='32' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='82' y='14' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='82' y='32' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='82' y='50' width='22' height='14' rx='2' stroke-width='1'/>
                <rect x='82' y='68' width='22' height='14' rx='2' stroke-width='1'/>
              </svg>
              <div style='font-size:8px;letter-spacing:2px;text-transform:uppercase;
                          color:rgba(255,255,255,0.45);margin-top:7px;'>Board</div>
            </td>
            <!-- Plans -->
            <td width='20%' align='center' valign='bottom'
                style='padding:14px 4px 10px;border-right:1px solid rgba(255,255,255,0.07);'>
              <svg width='40' height='40' viewBox='0 0 100 100' fill='none'
                   stroke='rgba(255,255,255,0.75)' stroke-linecap='round' stroke-linejoin='round'
                   style='display:block;margin:0 auto;'>
                <path d='M4 4 H96 V68 H68 V96 H4 Z' stroke-width='2'/>
                <line x1='4' y1='48' x2='52' y2='48' stroke-width='2'/>
                <line x1='52' y1='4' x2='52' y2='68' stroke-width='2'/>
                <line x1='68' y1='68' x2='68' y2='48' stroke-width='2'/>
                <line x1='52' y1='48' x2='68' y2='48' stroke-width='2'/>
                <path d='M52 30 A16 16 0 0 1 68 46' stroke-width='1'/>
                <line x1='8' y1='90' x2='36' y2='90' stroke-width='1'/>
                <line x1='8' y1='87' x2='8' y2='93' stroke-width='1'/>
                <line x1='36' y1='87' x2='36' y2='93' stroke-width='1'/>
                <line x1='72' y1='72' x2='92' y2='72' stroke-width='1'/>
                <line x1='72' y1='69' x2='72' y2='75' stroke-width='1'/>
                <line x1='92' y1='69' x2='92' y2='75' stroke-width='1'/>
              </svg>
              <div style='font-size:8px;letter-spacing:2px;text-transform:uppercase;
                          color:rgba(255,255,255,0.45);margin-top:7px;'>Plans</div>
            </td>
            <!-- Time -->
            <td width='20%' align='center' valign='bottom'
                style='padding:14px 4px 10px;border-right:1px solid rgba(255,255,255,0.07);'>
              <svg width='40' height='40' viewBox='0 0 100 100' fill='none'
                   stroke='rgba(255,255,255,0.75)' stroke-linecap='round'
                   style='display:block;margin:0 auto;'>
                <circle cx='50' cy='50' r='46' stroke-width='1.5'/>
                <circle cx='50' cy='50' r='38' stroke-width='0.5'/>
                <line x1='50' y1='7' x2='50' y2='17' stroke-width='2.5'/>
                <line x1='73' y1='13' x2='68' y2='22' stroke-width='2'/>
                <line x1='90' y1='29' x2='82' y2='34' stroke-width='2'/>
                <line x1='94' y1='50' x2='84' y2='50' stroke-width='2.5'/>
                <line x1='90' y1='71' x2='82' y2='66' stroke-width='2'/>
                <line x1='73' y1='87' x2='68' y2='78' stroke-width='2'/>
                <line x1='50' y1='93' x2='50' y2='83' stroke-width='2.5'/>
                <line x1='27' y1='87' x2='32' y2='78' stroke-width='2'/>
                <line x1='10' y1='71' x2='18' y2='66' stroke-width='2'/>
                <line x1='6' y1='50' x2='16' y2='50' stroke-width='2.5'/>
                <line x1='10' y1='29' x2='18' y2='34' stroke-width='2'/>
                <line x1='27' y1='13' x2='32' y2='22' stroke-width='2'/>
                <line x1='50' y1='50' x2='34' y2='26' stroke-width='2.5'/>
                <line x1='50' y1='50' x2='70' y2='40' stroke-width='1.5'/>
                <circle cx='50' cy='50' r='3.5' fill='rgba(255,255,255,0.8)' stroke='none'/>
              </svg>
              <div style='font-size:8px;letter-spacing:2px;text-transform:uppercase;
                          color:rgba(255,255,255,0.45);margin-top:7px;'>Time</div>
            </td>
            <!-- Organs -->
            <td width='20%' align='center' valign='bottom'
                style='padding:14px 4px 10px;border-right:1px solid rgba(255,255,255,0.07);'>
              <svg width='40' height='40' viewBox='0 0 100 100' fill='none'
                   stroke='rgba(255,255,255,0.75)' stroke-linecap='round' stroke-linejoin='round'
                   style='display:block;margin:0 auto;'>
                <rect x='36' y='4' width='28' height='16' rx='3' stroke-width='1.5'/>
                <line x1='50' y1='20' x2='50' y2='34' stroke-width='1.5'/>
                <line x1='18' y1='34' x2='82' y2='34' stroke-width='1.5'/>
                <line x1='18' y1='34' x2='18' y2='42' stroke-width='1.5'/>
                <line x1='82' y1='34' x2='82' y2='42' stroke-width='1.5'/>
                <rect x='4' y='42' width='28' height='14' rx='3' stroke-width='1.5'/>
                <rect x='68' y='42' width='28' height='14' rx='3' stroke-width='1.5'/>
                <line x1='18' y1='56' x2='18' y2='66' stroke-width='1.5'/>
                <line x1='8' y1='66' x2='28' y2='66' stroke-width='1.5'/>
                <line x1='8' y1='66' x2='8' y2='72' stroke-width='1.5'/>
                <line x1='28' y1='66' x2='28' y2='72' stroke-width='1.5'/>
                <rect x='2' y='72' width='14' height='12' rx='2' stroke-width='1'/>
                <rect x='22' y='72' width='14' height='12' rx='2' stroke-width='1'/>
                <line x1='82' y1='56' x2='82' y2='66' stroke-width='1.5'/>
                <line x1='68' y1='66' x2='96' y2='66' stroke-width='1.5'/>
                <line x1='68' y1='66' x2='68' y2='72' stroke-width='1.5'/>
                <line x1='96' y1='66' x2='96' y2='72' stroke-width='1.5'/>
                <rect x='62' y='72' width='14' height='12' rx='2' stroke-width='1'/>
                <rect x='83' y='72' width='14' height='12' rx='2' stroke-width='1'/>
              </svg>
              <div style='font-size:8px;letter-spacing:2px;text-transform:uppercase;
                          color:rgba(255,255,255,0.45);margin-top:7px;'>Organs</div>
            </td>
            <!-- CAD -->
            <td width='20%' align='center' valign='bottom'
                style='padding:14px 4px 10px;'>
              <svg width='40' height='40' viewBox='0 0 100 100' fill='none'
                   stroke='rgba(255,255,255,0.75)' stroke-linecap='round' stroke-linejoin='round'
                   style='display:block;margin:0 auto;'>
                <polygon points='50,6 90,28 90,72 50,94 10,72 10,28' stroke-width='1.5'/>
                <line x1='50' y1='6' x2='50' y2='50' stroke-width='1'/>
                <line x1='90' y1='28' x2='50' y2='50' stroke-width='1'/>
                <line x1='10' y1='28' x2='50' y2='50' stroke-width='1'/>
                <polygon points='50,6 90,28 50,50 10,28' stroke-width='1.5'/>
                <line x1='2' y1='28' x2='2' y2='72' stroke-width='0.8'/>
                <line x1='0' y1='28' x2='4' y2='28' stroke-width='0.8'/>
                <line x1='0' y1='72' x2='4' y2='72' stroke-width='0.8'/>
                <line x1='10' y1='98' x2='90' y2='98' stroke-width='0.8'/>
                <line x1='10' y1='96' x2='10' y2='100' stroke-width='0.8'/>
                <line x1='90' y1='96' x2='90' y2='100' stroke-width='0.8'/>
              </svg>
              <div style='font-size:8px;letter-spacing:2px;text-transform:uppercase;
                          color:rgba(255,255,255,0.45);margin-top:7px;'>CAD</div>
            </td>
          </tr>
        </table>

        <p style='margin:0 0 28px;font-size:13px;color:rgba(255,255,255,0.55);line-height:1.7;'>
          Pro přístup k platformě vytvořte svůj účet kliknutím na tlačítko níže.
          Odkaz je platný bez časového omezení.
        </p>

        <table cellpadding='0' cellspacing='0'>
          <tr>
            <td style='background:#4a5340;border:1px solid rgba(201,168,76,0.5);'>
              <a href='{$link}' style='display:inline-block;padding:14px 36px;
                  color:#c9a84c;font-size:11px;font-weight:bold;
                  letter-spacing:4px;text-transform:uppercase;
                  text-decoration:none;'>VYTVOŘIT ÚČET</a>
            </td>
          </tr>
        </table>

        <p style='margin:24px 0 0;font-size:11px;color:rgba(255,255,255,0.2);line-height:1.5;'>
          Pokud tuto pozvánku neočekáváte, ignorujte tento e-mail.
        </p>
    ");

    sendEmail($email, $email, 'Pozvánka do BeSix Platform', $html);
    json_out(['success' => true, 'message' => 'Pozvánka odeslána na ' . $email]);
}

function handleCancelInvite(): never {
    requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $email = strtolower(trim(body()['email'] ?? ''));
    if (!$email) json_out(['error' => 'Chybí email'], 422);
    // Only cancel if not yet accepted (email not in users table)
    $st = db()->prepare('SELECT id FROM users WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetch()) json_out(['error' => 'Pozvánka již byla přijata'], 409);
    db()->prepare('DELETE FROM platform_invitations WHERE email = ?')->execute([$email]);
    json_out(['success' => true]);
}

function handleDeleteMember(): never {
    $me = requireAuth();
    if ($me['email'] !== PLATFORM_ADMIN_EMAIL) json_out(['error' => 'Nedostatečná oprávnění'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);
    $uid = (int)(body()['user_id'] ?? 0);
    if (!$uid)                   json_out(['error' => 'Chybí user_id'], 422);
    if ($uid === (int)$me['id']) json_out(['error' => 'Nelze smazat vlastní účet'], 400);

    $pdo = db();
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    try {
        // Delete projects this user created (not projects they're just a member of)
        $pdo->prepare('DELETE FROM projects WHERE created_by = ?')->execute([$uid]);
        // Delete the user
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
    json_out(['success' => true]);
}

function handleSetAppAccess(): never {
    $me = requireAuth();
    if ($me['email'] !== PLATFORM_ADMIN_EMAIL) json_out(['error' => 'Nedostatečná oprávnění'], 403);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'POST required'], 405);

    $b       = body();
    $uid     = (int)($b['user_id'] ?? 0);
    $app     = strtolower(trim($b['app'] ?? ''));
    $role    = trim($b['role'] ?? 'clen');
    $granted = (bool)($b['granted'] ?? false);

    $allowed = ['board', 'plans', 'time', 'organs', 'cad'];
    if (!$uid || !in_array($app, $allowed, true)) json_out(['error' => 'Neplatné parametry'], 422);

    if ($granted) {
        db()->prepare(
            'INSERT INTO app_access (user_id, app, role) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role)'
        )->execute([$uid, $app, $role]);
    } else {
        db()->prepare('DELETE FROM app_access WHERE user_id = ? AND app = ?')
            ->execute([$uid, $app]);
    }
    json_out(['success' => true]);
}

function handleDbInfo(): never {
    $me = requireAuth();
    if ($me['email'] !== PLATFORM_ADMIN_EMAIL) json_out(['error' => 'Nedostatečná oprávnění'], 403);

    $tables = [];
    foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $tbl) {
        $cols = array_column(db()->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(), 'Field');
        $tables[$tbl] = $cols;
    }
    json_out(['tables' => $tables]);
}
