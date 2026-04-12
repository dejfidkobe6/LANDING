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
    return $pdo;
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
    return "
    <div style='font-family:\"Exo 2\",sans-serif;max-width:480px;margin:0 auto;
                background:#353c2e;padding:40px 36px;border-radius:14px;color:#fff;'>
      <div style='text-align:center;margin-bottom:28px;'>
        <span style='font-family:Rajdhani,sans-serif;font-size:1.6rem;font-weight:700;
                     letter-spacing:.18em;color:#c9a84c;'>BESIX</span>
        <div style='font-size:.6rem;letter-spacing:.4em;color:rgba(255,255,255,.4);
                    text-transform:uppercase;margin-top:4px;'>Platform</div>
      </div>
      <h2 style='color:#c9a84c;font-family:Rajdhani,sans-serif;letter-spacing:.1em;
                 margin:0 0 18px;'>$title</h2>
      $body
      <hr style='border:none;border-top:1px solid rgba(255,255,255,.1);margin:28px 0;'>
      <p style='font-size:.72rem;color:rgba(255,255,255,.3);margin:0;'>
        © 2025 BeSix s.r.o. · Digitalizace stavebnictví
      </p>
    </div>";
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
    json_out(['user' => $user]);
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
    requireAuth();
    $rows = db()->query(
        'SELECT id, name, email, avatar_color, is_verified, created_at FROM users ORDER BY created_at ASC'
    )->fetchAll();

    $members = [];
    foreach ($rows as $u) {
        $members[] = [
            'id'           => (int)$u['id'],
            'name'         => $u['name'],
            'email'        => $u['email'],
            'avatar_color' => $u['avatar_color'],
            'is_verified'  => (bool)$u['is_verified'],
            'created_at'   => substr($u['created_at'], 0, 10),
        ];
    }
    json_out(['members' => $members]);
}

function handleInvitations(): never {
    requireAuth();
    $rows = db()->query(
        'SELECT i.email, i.sent_at,
                u.id AS user_id, u.name AS user_name, u.created_at AS accepted_at
         FROM invitations i
         LEFT JOIN users u ON u.email = i.email
         ORDER BY i.sent_at DESC'
    )->fetchAll();

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
        'INSERT INTO invitations (email, invited_by) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE sent_at = NOW(), invited_by = VALUES(invited_by)'
    )->execute([$email, $invBy]);

    $link = rtrim(env('APP_URL'), '/');
    $html = emailTemplate('Pozvánka do BeSix Platform', "
        <p style='margin:0 0 16px;color:rgba(255,255,255,.8);line-height:1.6;'>
            Byli jste pozván/a do platformy <strong style='color:#c9a84c;'>BeSix</strong> —
            digitálních nástrojů stavebního týmu.
        </p>
        <p style='margin:0 0 28px;color:rgba(255,255,255,.65);line-height:1.6;'>
            Pro registraci klikněte na tlačítko níže a vytvořte si účet.
        </p>
        <a href='{$link}' style='display:inline-block;padding:13px 32px;
            background:#4A5340;border:1px solid rgba(201,168,76,.5);border-radius:8px;
            color:#c9a84c;font-family:Rajdhani,sans-serif;font-size:1rem;
            font-weight:600;letter-spacing:.15em;text-transform:uppercase;
            text-decoration:none;'>Registrovat se</a>
    ");

    sendEmail($email, $email, 'Pozvánka do BeSix Platform', $html);
    json_out(['success' => true, 'message' => 'Pozvánka odeslána na ' . $email]);
}
