<?php
// ============================================================
// Site Manager — auth.php
// Auth actions (login/MFA/logout/reset/invite) + the signed-in gate.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================

// ---- AUTH API (login / logout / me / change own password) ----
if (isset($_GET['api']) && $_GET['api'] === 'auth') {
    $action = $_GET['action'] ?? '';

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $u = trim((string)($in['username'] ?? ''));
        $p = (string)($in['password'] ?? '');
        if ($u === '' || $p === '') jsonResponse(['success' => false, 'error' => 'Enter a username and password'], 400);
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
            $stmt->execute([$u]);
            $row = $stmt->fetch();
            // Check lock BEFORE verifying the password — a locked account stays out
            // even with the right password. Generic message; same shape for missing
            // users so attackers can't tell accounts apart (no enumeration).
            if ($row && account_is_locked($row)) {
                $secs = account_lock_remaining($row);
                $manual = setting_get($pdo, 'login_lockout_manual', '0') === '1' || $secs > 86400 * 30;
                audit($pdo, 'login.blocked_locked', ['actor_id' => null, 'actor_name' => $u, 'target_type' => 'user', 'target_label' => $u]);
                usleep(400000);
                $msg = $manual
                    ? 'This account is locked. Contact an administrator to unlock it.'
                    : 'Too many attempts. This account is locked for ' . max(1, (int)ceil($secs / 60)) . ' more minute(s).';
                jsonResponse(['success' => false, 'error' => $msg, 'locked' => true], 423);
            }
            if (!$row || !password_verify($p, $row['password_hash'])) {
                audit($pdo, 'login.failed', ['actor_id' => null, 'actor_name' => $u, 'target_type' => 'user', 'target_label' => $u]);
                usleep(400000); // 0.4s on every failure — makes brute-forcing impractical
                // Register the failure against a real account (never reveal which exist).
                $res = $row ? lockout_register_failure($pdo, $row) : ['locked' => false, 'remaining_attempts' => 99];
                if ($res['locked']) {
                    jsonResponse(['success' => false, 'error' => 'Too many attempts — this account is now locked.', 'locked' => true], 423);
                }
                jsonResponse(['success' => false, 'error' => 'Incorrect username or password'], 401);
            }
            // Success: clear any accumulated failures + lock.
            lockout_clear($pdo, (int)$row['user_id']);
            // A pending (invited, not-yet-activated) account can't sign in until it's
            // activated via the invite link — even if a password somehow matched.
            if (users_has_invites($pdo) && ($row['invite_status'] ?? 'active') === 'invited') {
                jsonResponse(['success' => false, 'error' => 'This account hasn\'t been activated yet. Check your email for the invitation link.'], 403);
            }
            // Rotate the seeded placeholder public_id to a real random token the first
            // time the seed admin logs in, so it never persists as a known value.
            if (($row['public_id'] ?? '') === 'seed-admin-rotate-on-login') {
                try {
                    $newPub = generate_public_id();
                    $pdo->prepare("UPDATE users SET public_id = ? WHERE user_id = ?")->execute([$newPub, (int)$row['user_id']]);
                } catch (\Throwable $e) {}
            }
            $mustChange = password_verify('changeme', $row['password_hash']);
            // If this user has MFA enabled, don't establish the full session yet —
            // hold a pending state and require a 6-digit code (or backup code).
            if (!empty($row['mfa_enabled']) && !empty($row['totp_secret'])) {
                session_regenerate_id(true);
                $_SESSION['pending_mfa_user'] = (int)$row['user_id'];
                $_SESSION['pending_must_change'] = $mustChange ? 1 : 0;
                jsonResponse(['success' => true, 'mfa_required' => true]);
            }
            // No MFA — establish session immediately.
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$row['user_id'];
            $_SESSION['last_activity'] = time();
            try { $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")->execute([(int)$row['user_id']]); } catch (\Throwable $e) {}
            $_SESSION['must_change_password'] = $mustChange ? 1 : 0;
            audit($pdo, 'login', ['actor_id' => (int)$row['user_id'], 'actor_name' => $row['display_name'] ?: $row['username'], 'target_type' => 'user', 'target_label' => $row['username']]);
            jsonResponse(['success' => true, 'must_change_password' => $mustChange]);
        } catch (\Throwable $e) {
            error_log('Login failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Login unavailable — has the database migration been run?'], 500);
        }
    }

    // Second step of login for MFA users: verify a 6-digit code OR a backup code.
    if ($action === 'mfa_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $pendingId = (int)($_SESSION['pending_mfa_user'] ?? 0);
        if (!$pendingId) jsonResponse(['success' => false, 'error' => 'No login in progress. Start over.'], 401);
        $in   = jsonInput();
        $code = trim((string)($in['code'] ?? ''));
        if ($code === '') jsonResponse(['success' => false, 'error' => 'Enter your code'], 400);
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND is_active = 1");
            $stmt->execute([$pendingId]);
            $row = $stmt->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Account unavailable'], 401);

            $ok = totp_verify((string)$row['totp_secret'], $code);
            $usedBackup = false;
            // If the TOTP code didn't match, try unused backup codes.
            if (!$ok) {
                $codes = $pdo->prepare("SELECT code_id, code_hash FROM user_backup_code WHERE user_id = ? AND used_at IS NULL");
                $codes->execute([$pendingId]);
                $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
                foreach ($codes->fetchAll() as $bc) {
                    if (password_verify($normalized, $bc['code_hash'])) {
                        $pdo->prepare("UPDATE user_backup_code SET used_at = NOW() WHERE code_id = ?")->execute([$bc['code_id']]);
                        $ok = true; $usedBackup = true;
                        break;
                    }
                }
            }
            if (!$ok) jsonResponse(['success' => false, 'error' => 'Invalid or expired code'], 401);

            // success — promote pending → real session
            $mustChange = (int)($_SESSION['pending_must_change'] ?? 0);
            unset($_SESSION['pending_mfa_user'], $_SESSION['pending_must_change']);
            session_regenerate_id(true);
            $_SESSION['user_id'] = $pendingId;
            $_SESSION['last_activity'] = time();
            $_SESSION['must_change_password'] = $mustChange;
            try { $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?")->execute([$pendingId]); } catch (\Throwable $e) {}
            audit($pdo, 'login', ['actor_id' => $pendingId, 'actor_name' => $row['display_name'] ?: $row['username'], 'target_type' => 'user', 'target_label' => $row['username'], 'details' => ['mfa' => true, 'used_backup' => $usedBackup]]);
            jsonResponse(['success' => true, 'must_change_password' => (bool)$mustChange, 'used_backup' => $usedBackup]);
        } catch (\Throwable $e) {
            error_log('MFA login failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Verification unavailable'], 500);
        }
    }

    if ($action === 'logout') {
        // The idle-timeout flow ends the session through here too (with
        // reason=timeout), instead of just reloading and racing the server's
        // clock: the client's countdown drifts a second or two, so a reload at
        // "zero" could arrive marginally BEFORE the strict expiry check tripped
        // — and a page load is activity, so the near-expired session got
        // refreshed by the very request meant to end it. Explicit beats racy.
        $reason = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $in = jsonInput(); $reason = (string)($in['reason'] ?? ''); }
        if ($current_user) {
            audit($pdo, $reason === 'timeout' ? 'session.timeout' : 'logout',
                  ['target_type' => 'user', 'target_label' => $current_user['username']]);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        jsonResponse(['success' => true]);
    }

    if ($action === 'me') {
        jsonResponse(['success' => true, 'user' => $current_user ? [
            'username' => $current_user['username'],
            'display_name' => $current_user['display_name'],
            'role' => $current_user['role'],
        ] : null]);
    }

    // Lightweight poll: how many seconds until idle logout, and the warn threshold.
    // This action does NOT refresh activity (handled above), so a backgrounded tab
    // still times out. Returns never_expire:true for kiosk/service accounts.
    if ($action === 'session_status') {
        if (!$current_user) jsonResponse(['success' => true, 'authenticated' => false]);
        if (!empty($current_user['never_expire'])) {
            jsonResponse(['success' => true, 'authenticated' => true, 'never_expire' => true]);
        }
        $timeoutSec = setting_int($pdo, 'session_timeout_minutes', 480) * 60;
        $warnSec    = setting_int($pdo, 'session_warn_minutes', 10) * 60;
        $last       = (int)($_SESSION['last_activity'] ?? time());
        $remaining  = max(0, $timeoutSec - (time() - $last));
        jsonResponse([
            'success' => true, 'authenticated' => true, 'never_expire' => false,
            'remaining' => $remaining, 'warn_at' => $warnSec, 'timeout' => $timeoutSec,
        ]);
    }

    // "Stay signed in" — explicitly refresh the activity clock.
    if ($action === 'session_keepalive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $_SESSION['last_activity'] = time();
        jsonResponse(['success' => true]);
    }

    // update OWN profile (display name only; username/role are admin-managed)
    if ($action === 'update_profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $in = jsonInput();
        $display = trim((string)($in['display_name'] ?? '')) ?: null;
        try {
            $pdo->prepare("UPDATE users SET display_name = ? WHERE user_id = ?")->execute([$display, (int)$current_user['user_id']]);
            jsonResponse(['success' => true, 'display_name' => $display]);
        } catch (\Throwable $e) {
            error_log('Update profile failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not save profile'], 500);
        }
    }

    // change OWN password
    if ($action === 'change_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $in = jsonInput();
        $new = (string)($in['new_password'] ?? '');
        if (($pwErr = password_rejection_reason($new)) !== null) jsonResponse(['success' => false, 'error' => $pwErr], 400);
        try {
            $hash = password_hash($new, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")->execute([$hash, (int)$current_user['user_id']]);
            $_SESSION['must_change_password'] = 0;
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('Change password failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not update password'], 500);
        }
    }

    // ---- Self-service password reset (works while logged OUT) ----
    // Request a reset link. Body: { username_or_email }. ALWAYS returns success so
    // the form can't be used to discover which usernames/emails exist.
    if ($action === 'forgot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $ident = trim((string)($in['identifier'] ?? ''));
        $generic = ['success' => true, 'message' => 'If that account exists and has an email on file, a reset link is on its way.'];
        if ($ident === '' || !users_has_email($pdo)) { usleep(300000); jsonResponse($generic); }
        try {
            // Match by username OR email; only active accounts with an email.
            $stmt = $pdo->prepare("SELECT user_id, username, email FROM users WHERE is_active = 1 AND email IS NOT NULL AND email != '' AND (username = ? OR email = ?) LIMIT 1");
            $stmt->execute([$ident, $ident]);
            $u = $stmt->fetch();
            if ($u) {
                // RATE LIMIT: if a reset email already went to this address in the last
                // 15 minutes, don't send another (still return the generic success, so
                // the behavior looks identical and can't be probed).
                if (email_log_available($pdo)) {
                    $rl = $pdo->prepare("SELECT COUNT(*) FROM email_log WHERE recipient = ? AND purpose = 'password_reset' AND status = 'sent' AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
                    $rl->execute([$u['email']]);
                    if ((int)$rl->fetchColumn() > 0) { usleep(300000); jsonResponse($generic); }
                }
                // One active token per user: clear old ones first.
                $pdo->prepare("DELETE FROM password_reset_token WHERE user_id = ?")->execute([(int)$u['user_id']]);
                $raw  = bin2hex(random_bytes(32));               // emailed to the user
                $hash = hash('sha256', $raw);                    // only the hash is stored
                $exp  = date('Y-m-d H:i:s', time() + 3600);      // 60-minute window
                $pdo->prepare("INSERT INTO password_reset_token (user_id, token_hash, expires_at, requested_ip) VALUES (?,?,?,?)")
                    ->execute([(int)$u['user_id'], $hash, $exp, client_ip()]);
                // Build an absolute link back to this app with the raw token.
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
                $link   = $scheme . '://' . $host . $path . '?reset=' . $raw;
                $html = '<div style="font-family:sans-serif;font-size:15px;color:#111">'
                      . '<h2 style="margin:0 0 10px">Reset your password</h2>'
                      . '<p>Hi ' . htmlspecialchars($u['username']) . ', we received a request to reset your Site Manager password.</p>'
                      . '<p style="margin:18px 0"><a href="' . htmlspecialchars($link) . '" style="background:#3b82f6;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:600">Choose a new password</a></p>'
                      . '<p style="color:#666;font-size:13px">This link expires in 1 hour and can be used once. If you did not request this, you can ignore this email.</p>'
                      . '</div>';
                send_mail($pdo, $u['email'], 'Reset your Site Manager password', $html, null, 'password_reset');
                audit($pdo, 'password.reset_requested', ['target_type' => 'user', 'target_label' => $u['username']]);
            }
        } catch (\Throwable $e) { error_log('forgot failed: ' . $e->getMessage()); }
        usleep(300000);
        jsonResponse($generic);
    }

    // Redeem a reset token and set a new password. Body: { token, new_password }.
    if ($action === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $token = trim((string)($in['token'] ?? ''));
        $new   = (string)($in['new_password'] ?? '');
        if ($token === '') jsonResponse(['success' => false, 'error' => 'Missing reset token'], 400);
        if (($pwErr = password_rejection_reason($new)) !== null) jsonResponse(['success' => false, 'error' => $pwErr], 400);
        if (!users_has_email($pdo)) jsonResponse(['success' => false, 'error' => 'Password reset is not available'], 400);
        try {
            $hash = hash('sha256', $token);
            $stmt = $pdo->prepare("SELECT id, user_id, expires_at, used_at FROM password_reset_token WHERE token_hash = ? LIMIT 1");
            $stmt->execute([$hash]);
            $row = $stmt->fetch();
            if (!$row || $row['used_at'] !== null || strtotime((string)$row['expires_at']) < time()) {
                jsonResponse(['success' => false, 'error' => 'This reset link is invalid or has expired. Request a new one.'], 400);
            }
            $uid = (int)$row['user_id'];
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE user_id = ?")
                ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
            // Burn the token, and clear any lockout so they can log in immediately.
            $pdo->prepare("UPDATE password_reset_token SET used_at = NOW() WHERE id = ?")->execute([(int)$row['id']]);
            if (users_has_lockout($pdo)) {
                $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")->execute([$uid]);
            }
            $uq = $pdo->prepare("SELECT username FROM users WHERE user_id = ?");
            $uq->execute([$uid]);
            audit($pdo, 'password.reset_done', ['target_type' => 'user', 'target_label' => (string)$uq->fetchColumn()]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('reset failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not reset password'], 500);
        }
    }

    // ---- Invite activation (works logged OUT) ----
    // Helper: resolve a valid, unused, unexpired invite token to its user row.
    $resolve_invite = function (string $token) use ($pdo) {
        if ($token === '' || !users_has_invites($pdo)) return null;
        $stmt = $pdo->prepare("SELECT t.id AS tid, t.expires_at, t.used_at, u.user_id, u.username, u.email, u.invite_status
                               FROM password_reset_token t JOIN users u ON u.user_id = t.user_id
                               WHERE t.token_hash = ? AND t.purpose = 'invite' LIMIT 1");
        $stmt->execute([hash('sha256', $token)]);
        $r = $stmt->fetch();
        if (!$r || $r['used_at'] !== null || strtotime((string)$r['expires_at']) < time()) return null;
        return $r;
    };

    // Validate an invite token and return the username to greet (no auth).
    if ($action === 'invite_info') {
        $token = trim((string)($_GET['token'] ?? ''));
        $r = $resolve_invite($token);
        if (!$r) jsonResponse(['success' => false, 'error' => 'This invitation is invalid or has expired.'], 400);
        jsonResponse(['success' => true, 'username' => $r['username']]);
    }

    // Step 1 of activation: set the password. Returns a short-lived activation
    // ticket (the same token) so the optional MFA step can follow.
    if ($action === 'invite_activate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $token = trim((string)($in['token'] ?? ''));
        $new   = (string)($in['new_password'] ?? '');
        if (($pwErr = password_rejection_reason($new)) !== null) jsonResponse(['success' => false, 'error' => $pwErr], 400);
        $r = $resolve_invite($token);
        if (!$r) jsonResponse(['success' => false, 'error' => 'This invitation is invalid or has expired.'], 400);
        try {
            $uid = (int)$r['user_id'];
            $pdo->prepare("UPDATE users SET password_hash = ?, invite_status = 'active' WHERE user_id = ?")
                ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
            if (users_has_lockout($pdo)) {
                $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")->execute([$uid]);
            }
            audit($pdo, 'invite.activated', ['target_type' => 'user', 'target_label' => $r['username']]);
            // Token stays valid (not burned yet) so the MFA step can use it; it's
            // burned by invite_finish. Tell the client whether MFA is offered.
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('invite_activate failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not set password'], 500);
        }
    }

    // Optional MFA during activation: generate a secret + otpauth URI for the QR.
    if ($action === 'invite_mfa_begin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $token = trim((string)($in['token'] ?? ''));
        $r = $resolve_invite($token);
        if (!$r) jsonResponse(['success' => false, 'error' => 'This invitation is invalid or has expired.'], 400);
        try {
            $secret = totp_generate_secret();
            $pdo->prepare("UPDATE users SET totp_secret = ?, mfa_enabled = 0 WHERE user_id = ?")
                ->execute([$secret, (int)$r['user_id']]);
            jsonResponse(['success' => true, 'secret' => $secret, 'otpauth' => totp_uri($secret, $r['username'], 'Site Manager')]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not start MFA setup'], 500);
        }
    }

    // Verify the first TOTP code to turn MFA on, then burn the invite token.
    if ($action === 'invite_mfa_verify' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $token = trim((string)($in['token'] ?? ''));
        $code  = preg_replace('/\D/', '', (string)($in['code'] ?? ''));
        $r = $resolve_invite($token);
        if (!$r) jsonResponse(['success' => false, 'error' => 'This invitation is invalid or has expired.'], 400);
        try {
            $sq = $pdo->prepare("SELECT totp_secret FROM users WHERE user_id = ?");
            $sq->execute([(int)$r['user_id']]);
            $secret = (string)$sq->fetchColumn();
            if ($secret === '' || !totp_verify($secret, $code)) jsonResponse(['success' => false, 'error' => 'That code is incorrect — check the app and try again'], 400);
            $pdo->prepare("UPDATE users SET mfa_enabled = 1 WHERE user_id = ?")->execute([(int)$r['user_id']]);
            $pdo->prepare("UPDATE password_reset_token SET used_at = NOW() WHERE id = ?")->execute([(int)$r['tid']]);
            audit($pdo, 'invite.mfa_enabled', ['target_type' => 'user', 'target_label' => $r['username']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not enable MFA'], 500);
        }
    }

    // Finish activation without MFA: just burn the invite token.
    if ($action === 'invite_finish' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $token = trim((string)($in['token'] ?? ''));
        $r = $resolve_invite($token);
        if ($r) {
            try {
                // If they skipped MFA, make sure no half-set secret lingers.
                $pdo->prepare("UPDATE users SET totp_secret = NULL, mfa_enabled = 0 WHERE user_id = ? AND mfa_enabled = 0")->execute([(int)$r['user_id']]);
                $pdo->prepare("UPDATE password_reset_token SET used_at = NOW() WHERE id = ?")->execute([(int)$r['tid']]);
            } catch (\Throwable $e) {}
        }
        jsonResponse(['success' => true]);
    }

    // Status: is MFA on, and how many backup codes remain.
    if ($action === 'mfa_status') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $enabled = false; $remaining = 0;
        try {
            // Read the LIVE value — $current_user is a snapshot from page load and
            // would be stale right after the user just enabled/disabled MFA.
            $u = $pdo->prepare("SELECT mfa_enabled FROM users WHERE user_id = ?");
            $u->execute([(int)$current_user['user_id']]);
            $enabled = (bool)$u->fetchColumn();
            $c = $pdo->prepare("SELECT COUNT(*) FROM user_backup_code WHERE user_id = ? AND used_at IS NULL");
            $c->execute([(int)$current_user['user_id']]);
            $remaining = (int)$c->fetchColumn();
        } catch (\Throwable $e) {}
        jsonResponse(['success' => true, 'enabled' => $enabled, 'backup_remaining' => $remaining]);
    }

    // Begin enrollment: generate a fresh secret, stash it provisionally (not enabled
    // until a code is verified), and return the otpauth URI + secret for the QR.
    if ($action === 'mfa_setup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        try {
            $secret = totp_generate_secret();
            // store provisionally with mfa_enabled = 0; confirmed on mfa_enable
            $pdo->prepare("UPDATE users SET totp_secret = ?, mfa_enabled = 0 WHERE user_id = ?")
                ->execute([$secret, (int)$current_user['user_id']]);
            $uri = totp_uri($secret, $current_user['username'], 'Site Manager');
            jsonResponse(['success' => true, 'secret' => $secret, 'uri' => $uri]);
        } catch (\Throwable $e) {
            error_log('MFA setup failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not start setup (run migration.sql?)'], 500);
        }
    }

    // Confirm enrollment: verify the first code, flip mfa_enabled on, and issue
    // a fresh set of one-time backup codes (returned ONCE, in plaintext).
    if ($action === 'mfa_enable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $in = jsonInput();
        $code = trim((string)($in['code'] ?? ''));
        try {
            $stmt = $pdo->prepare("SELECT totp_secret FROM users WHERE user_id = ?");
            $stmt->execute([(int)$current_user['user_id']]);
            $secret = (string)$stmt->fetchColumn();
            if ($secret === '') jsonResponse(['success' => false, 'error' => 'Start setup first'], 400);
            if (!totp_verify($secret, $code)) jsonResponse(['success' => false, 'error' => 'That code is incorrect — check the app and try again'], 400);

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET mfa_enabled = 1 WHERE user_id = ?")->execute([(int)$current_user['user_id']]);
            // (re)issue backup codes
            $pdo->prepare("DELETE FROM user_backup_code WHERE user_id = ?")->execute([(int)$current_user['user_id']]);
            $plain = [];
            $ins = $pdo->prepare("INSERT INTO user_backup_code (user_id, code_hash) VALUES (?, ?)");
            for ($i = 0; $i < 10; $i++) {
                $raw = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars
                $fmt = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
                $plain[] = $fmt;
                $ins->execute([(int)$current_user['user_id'], password_hash($raw, PASSWORD_BCRYPT)]);
            }
            $pdo->commit();
            jsonResponse(['success' => true, 'backup_codes' => $plain]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('MFA enable failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not enable MFA'], 500);
        }
    }

    // Turn MFA off (requires a current code to confirm it's really them).
    if ($action === 'mfa_disable' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $in = jsonInput();
        $code = trim((string)($in['code'] ?? ''));
        try {
            // Read live state — $current_user is stale right after enabling.
            $u = $pdo->prepare("SELECT mfa_enabled, totp_secret FROM users WHERE user_id = ?");
            $u->execute([(int)$current_user['user_id']]);
            $row = $u->fetch() ?: [];
            if (!empty($row['mfa_enabled'])) {
                if (!totp_verify((string)$row['totp_secret'], $code)) {
                    jsonResponse(['success' => false, 'error' => 'Enter a valid code to turn off MFA'], 400);
                }
            }
            $pdo->prepare("UPDATE users SET mfa_enabled = 0, totp_secret = NULL WHERE user_id = ?")->execute([(int)$current_user['user_id']]);
            $pdo->prepare("DELETE FROM user_backup_code WHERE user_id = ?")->execute([(int)$current_user['user_id']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('MFA disable failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not disable MFA'], 500);
        }
    }

    // Regenerate backup codes (invalidates old ones). Requires a current code.
    if ($action === 'mfa_regen_codes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$current_user) jsonResponse(['success' => false, 'error' => 'Not signed in'], 401);
        $in = jsonInput();
        $code = trim((string)($in['code'] ?? ''));
        // Read live state rather than the page-load snapshot.
        $live = $pdo->prepare("SELECT mfa_enabled, totp_secret FROM users WHERE user_id = ?");
        $live->execute([(int)$current_user['user_id']]);
        $lrow = $live->fetch() ?: [];
        if (empty($lrow['mfa_enabled'])) jsonResponse(['success' => false, 'error' => 'MFA is not enabled'], 400);
        if (!totp_verify((string)$lrow['totp_secret'], $code)) jsonResponse(['success' => false, 'error' => 'Enter a valid code first'], 400);
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM user_backup_code WHERE user_id = ?")->execute([(int)$current_user['user_id']]);
            $plain = [];
            $ins = $pdo->prepare("INSERT INTO user_backup_code (user_id, code_hash) VALUES (?, ?)");
            for ($i = 0; $i < 10; $i++) {
                $raw = strtoupper(bin2hex(random_bytes(5)));
                $plain[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
                $ins->execute([(int)$current_user['user_id'], password_hash($raw, PASSWORD_BCRYPT)]);
            }
            $pdo->commit();
            jsonResponse(['success' => true, 'backup_codes' => $plain]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('MFA regen failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not regenerate codes'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown auth action'], 400);
}

// ---- Everything past here requires a logged-in user ----
// API calls without a session get 401 JSON; page loads fall through to the
// ================================================================
// LOGO SERVE (pre-auth, logo ONLY — so the login page shows the logo).
// This block used to serve avatars too, resolving ?id= with
// filter_input(...FILTER_VALIDATE_INT) ?: $current_user — and since public
// ids are STRINGS, the int check failed for every request and the ?: fell
// back to the VIEWER. Fourth caller-default fallback in this feature, and
// the one that shadowed the real serve handler in api.php (this file loads
// first), so: every avatar rendered as whoever was looking (or a bare 404
// if the viewer had no photo). Avatars now fall through to api.php's
// handler — session-gated, public-id addressed, ownership-checked, labeled.
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'image' && ($_GET['action'] ?? '') === 'serve'
    && (($_GET['kind'] ?? '') === 'logo')) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    $rel = (string)setting_get($pdo, 'site_logo_path', '');
    $path = $rel !== '' ? APP_ROOT . '/' . ltrim($rel, '/') : null;
    $real = $path ? realpath($path) : false;
    $base = realpath(APP_ROOT . '/uploads');
    if ($real === false || $base === false || strpos($real, $base) !== 0 || !is_file($real)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        if (defined('APP_VERSION')) { header('X-App-Version: ' . APP_VERSION); }
        header('X-Avatar-Reason: logo_missing_or_invalid');
        echo str_pad("image 404: logo_missing_or_invalid\n(re-upload the logo in Settings > Branding)", 520, ' ');
        exit;
    }
    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    if (defined('APP_VERSION')) { header('X-App-Version: ' . APP_VERSION); }
    header('Cache-Control: private, max-age=300');
    readfile($real);
    exit;
}

// login screen rendered at the very bottom of this file.
$is_api_request = isset($_GET['api']);
if (!$current_user) {
    if ($is_api_request) {
        jsonResponse(['success' => false, 'error' => 'Not signed in', 'auth_required' => true], 401);
    }
    // Render the login page and stop. (Defined near the end as render_login_page().)
    render_login_page();
    exit;
}

