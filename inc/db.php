<?php
// ============================================================
// Site Manager — db.php
// Database connection (external credentials) + session/user identity.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================


// ================================================================
// DATABASE CONNECTION (systemd-creds / TPM2)
// ================================================================
try {
    $servername = getenv('DB_SERVER');
    $username   = getenv('DB_USERNAME');
    $dbname     = getenv('DB_NAME');
    $credsDir   = getenv('CREDENTIALS_DIRECTORY');

    if (!$credsDir || !file_exists($credsDir . '/db_password')) {
        throw new \Exception('Credential file not found.');
    }
    $password = trim(file_get_contents($credsDir . '/db_password'));
    if ($password === '') {
        throw new \Exception('DB password credential is empty.');
    }

    $pdo = new PDO(
        "mysql:host=$servername;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    unset($password);
} catch (\Throwable $e) {
    http_response_code(500);
    error_log('Site Manager DB error: ' . $e->getMessage());
    outputDebugError($e);
    exit;
}

// ================================================================
// RESOLVE CURRENT USER + AUTH API + LOGIN ENFORCEMENT
// ================================================================
$current_user = null;
if (!empty($_SESSION['user_id'])) {
    try {
        $hasDbAdmin = db_has_columns($pdo, 'users', ['db_admin']);
        $hasAvatarCol = db_has_columns($pdo, 'users', ['profile_image']);
        $extra = ($hasDbAdmin ? ", db_admin" : "") . ($hasAvatarCol ? ", profile_image" : "");
        $stmt = $pdo->prepare("SELECT user_id, public_id, username, display_name, role, is_active, site_access, never_expire{$extra} FROM users WHERE user_id = ? AND is_active = 1");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $current_user = $stmt->fetch() ?: null;
        if ($current_user && !isset($current_user['db_admin'])) $current_user['db_admin'] = 0;
        if ($current_user && !isset($current_user['profile_image'])) $current_user['profile_image'] = '';
    } catch (\Throwable $e) {
        // users table may not exist yet (migration not run). Leave unauthenticated.
        error_log('User resolve failed (run migration.sql?): ' . $e->getMessage());
    }
}

// ---- Idle session timeout enforcement ----
// Kiosk/service accounts (never_expire) are exempt. Everyone else is logged out
// after `session_timeout_minutes` of inactivity. Activity refreshes the clock,
// EXCEPT the lightweight session-status poll, which must not keep a tab alive forever.
if ($current_user) {
    $isStatusPoll = (($_GET['api'] ?? '') === 'auth' && ($_GET['action'] ?? '') === 'session_status');
    $neverExpire  = !empty($current_user['never_expire']);
    if (!$neverExpire) {
        $timeoutSec = setting_int($pdo, 'session_timeout_minutes', 480) * 60;
        $now  = time();
        $last = (int)($_SESSION['last_activity'] ?? $now);
        if ($timeoutSec > 0 && ($now - $last) > $timeoutSec) {
            // expired — tear the session down and treat as logged out
            audit($pdo, 'session.timeout', ['target_type' => 'user', 'target_label' => $current_user['username']]);
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cp = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $cp['path'], $cp['domain'], $cp['secure'], $cp['httponly']);
            }
            session_destroy();
            $current_user = null;
            if (isset($_GET['api'])) jsonResponse(['success' => false, 'error' => 'Session expired', 'auth_required' => true, 'expired' => true], 401);
        } else {
            // Refresh activity on real interactions, not on the status poll itself.
            // HANDSHAKE RULE: once the session is inside the WARNING window,
            // ordinary requests stop counting as activity — only the explicit
            // "Stay signed in" keepalive (a human clicking the button) extends.
            // Otherwise a client that suppresses its own logout could keep an
            // unattended session alive forever just by pinging any endpoint at
            // the last second. Outside the window, normal use refreshes as
            // always, so someone actively working never even sees the modal.
            // The timeout itself is enforced HERE on the server's clock either
            // way — the client's logout-at-zero is a courtesy, not the lock.
            $warnSec = setting_int($pdo, 'session_warn_minutes', 10) * 60;
            $inWarnWindow = ($timeoutSec - ($now - $last)) <= $warnSec;
            $isKeepalive = (($_GET['api'] ?? '') === 'auth' && ($_GET['action'] ?? '') === 'session_keepalive');
            if (!$isStatusPoll && (!$inWarnWindow || $isKeepalive)) {
                $_SESSION['last_activity'] = $now;
            }
        }
    }
}

