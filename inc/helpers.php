<?php
// ============================================================
// Site Manager — helpers.php
// Shared helpers: RBAC/permissions, mail, TOTP, password policy, audit.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================


/** Roles, most→least privileged. */
function role_rank(string $r): int {
    return ['admin' => 3, 'editor' => 2, 'viewer' => 1][strtolower($r)] ?? 0;
}
/** True if the logged-in user's role meets or exceeds $need. */
function user_can(string $need): bool {
    global $current_user;
    return $current_user && role_rank($current_user['role']) >= role_rank($need);
}
/** Data Editor access: now resolves to the data_admin capability (manage). */
function is_db_admin(): bool {
    global $pdo;
    return can($pdo, 'data_admin', 'manage');
}

// ============================================================================
//  PERMISSION SYSTEM (groups + grants, highest-wins, glass-break on top)
// ============================================================================
// Layers that use the full View<Edit<Manage<Admin ladder (data layers) vs the
// admin capabilities that use View<Manage only. Level ranks are shared.
const PERM_LEVEL_RANK = ['none' => 0, 'view' => 1, 'edit' => 2, 'manage' => 3, 'admin' => 4];
const PERM_DATA_LAYERS  = ['base', 'cameras', 'printers', 'devices'];
const PERM_ADMIN_CAPS   = ['audit', 'settings', 'manage_users', 'data_admin', 'notifications'];

function perm_level_rank(string $level): int {
    return PERM_LEVEL_RANK[strtolower($level)] ?? 0;
}

/** Is the (authenticated) current user the code-defined glass-break super admin? */
function is_glassbreak(): bool {
    global $current_user;
    return $current_user
        && isset($current_user['username'])
        && hash_equals(strtolower(GLASSBREAK_USERNAME), strtolower((string)$current_user['username']));
}

/**
 * Load and cache the current user's effective grants for this request.
 * Returns a list of ['layer','level','scope_type','scope_id'] from BOTH their
 * groups and their personal override grants. Glass-break short-circuits with
 * full power and never touches the DB for resolution.
 */
function perm_grants(PDO $pdo): array {
    global $current_user;
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (!$current_user) return $cache;

    if (is_glassbreak()) {
        // Synthesize an all-powerful grant set in code — never read from DB.
        foreach (PERM_DATA_LAYERS as $l) $cache[] = ['layer' => $l, 'level' => 'admin', 'scope_type' => 'all', 'scope_id' => null];
        foreach (PERM_ADMIN_CAPS as $l)  $cache[] = ['layer' => $l, 'level' => 'manage', 'scope_type' => 'all', 'scope_id' => null];
        return $cache;
    }

    $uid = (int)$current_user['user_id'];
    try {
        // Group grants (via membership) + personal override grants, unioned.
        $sql = "SELECT gg.layer, gg.level, gg.scope_type, gg.scope_id
                  FROM perm_user_group ug
                  JOIN perm_group_grant gg ON gg.group_id = ug.group_id
                 WHERE ug.user_id = ?
                UNION ALL
                SELECT pg.layer, pg.level, pg.scope_type, pg.scope_id
                  FROM perm_user_grant pg
                 WHERE pg.user_id = ?";
        $st = $pdo->prepare($sql);
        $st->execute([$uid, $uid]);
        $cache = $st->fetchAll();
    } catch (\Throwable $e) {
        // Permission tables not present yet (migration not run). No grants.
        error_log('perm_grants load failed (run add_permissions.sql?): ' . $e->getMessage());
        $cache = [];
    }
    return $cache;
}

/**
 * Does the current user have at least $need level on $layer, optionally scoped
 * to a specific $siteNumber (and/or $deviceId)? Highest grant wins. A broader
 * scope ('all') satisfies a narrower request; a per-site grant satisfies that
 * site only. Admin capabilities ignore scope.
 */
function can(PDO $pdo, string $layer, string $need = 'view', ?int $siteNumber = null, ?int $deviceId = null): bool {
    $needRank = perm_level_rank($need);
    if ($needRank === 0) return true;
    foreach (perm_grants($pdo) as $g) {
        if ($g['layer'] !== $layer) continue;
        if (perm_level_rank((string)$g['level']) < $needRank) continue;
        $st = $g['scope_type'] ?? 'all';
        if ($st === 'all') return true;                       // broadest — covers everything
        if ($st === 'site' && $siteNumber !== null && (int)$g['scope_id'] === $siteNumber) return true;
        if ($st === 'device' && $deviceId !== null && (int)$g['scope_id'] === $deviceId) return true;
    }
    return false;
}

/** Highest level the user holds for a layer at a given scope (for the UI/debug). */
function perm_effective_level(PDO $pdo, string $layer, ?int $siteNumber = null): string {
    $best = 'none';
    foreach (perm_grants($pdo) as $g) {
        if ($g['layer'] !== $layer) continue;
        $st = $g['scope_type'] ?? 'all';
        $covers = ($st === 'all') || ($st === 'site' && $siteNumber !== null && (int)$g['scope_id'] === $siteNumber);
        if (!$covers) continue;
        if (perm_level_rank((string)$g['level']) > perm_level_rank($best)) $best = (string)$g['level'];
    }
    return $best;
}
/** Sites this user may access. Admins => null (meaning "all"). */
function accessible_site_numbers(PDO $pdo, array $user): ?array {
    global $current_user;
    // Glass-break and anyone with an 'all'-scoped grant sees every site.
    if (is_glassbreak()) return null;
    $sites = [];
    $deviceCamIds = [];
    foreach (perm_grants($pdo) as $g) {
        $st = $g['scope_type'] ?? 'all';
        if ($st === 'all') return null;                 // a global grant = all sites
        if ($st === 'site' && $g['scope_id'] !== null) $sites[] = (int)$g['scope_id'];
        // A per-camera grant should make that camera's SITE visible (so the user
        // can navigate to the single camera they're allowed to see).
        if ($st === 'device' && $g['layer'] === 'cameras' && $g['scope_id'] !== null) $deviceCamIds[] = (int)$g['scope_id'];
    }
    if ($deviceCamIds) {
        try {
            $ph = implode(',', array_fill(0, count($deviceCamIds), '?'));
            $q = $pdo->prepare("SELECT DISTINCT site_number FROM camera WHERE camera_number IN ($ph)");
            $q->execute($deviceCamIds);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $sn) $sites[] = (int)$sn;
        } catch (\Throwable $e) { /* camera table absent — ignore */ }
    }
    return array_values(array_unique($sites));          // [] = no sites
}
/** True if the current user may access a specific site. */
function can_access_site(PDO $pdo, $siteNum): bool {
    global $current_user;
    if (!$current_user) return false;
    $allowed = accessible_site_numbers($pdo, $current_user);
    if ($allowed === null) return true;                 // all sites
    return in_array((int)$siteNum, $allowed, true);
}

// ====================================================================
// CAMERA PERMISSIONS (Phase 1 foundation)
// camera_access is JSON on the users row, keyed by site number:
//   { "7": {"obj":"all","feed":"none"}, "12": {"obj":["5","30"],"feed":["5"]} }
//   obj/feed values: "all" | "none" | [camera_number strings]
//   feed is always treated as a subset of obj. Admins bypass everything.
// ====================================================================
/** Does users.camera_access exist yet? (cached per request; snippet may not have run) */
function users_has_camera_access(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $cc = db_has_columns($pdo, 'users', ['camera_access']);
        $has = ($cc !== false);
    } catch (\Throwable $e) { $has = false; }
    return $has;
}
/** Does users.email exist yet? (cached; add_password_reset.sql may not have run) */
function users_has_email(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $cc = db_has_columns($pdo, 'users', ['email']);
        $has = ($cc !== false);
    } catch (\Throwable $e) { $has = false; }
    return $has;
}
/** Does the invite system schema exist? (users.invite_status + token.purpose) */
function users_has_invites(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = db_has_columns($pdo, 'users', ['invite_status'])
            && db_has_columns($pdo, 'password_reset_token', ['purpose']);
    } catch (\Throwable $e) { $has = false; }
    return $has;
}
/** Parse a user's camera_access JSON into an array keyed by site number (string). */
function camera_access_map(array $user): array {
    $raw = $user['camera_access'] ?? null;
    if ($raw === null || $raw === '') return [];
    $arr = is_array($raw) ? $raw : json_decode((string)$raw, true);
    return is_array($arr) ? $arr : [];
}
/** Internal: does $rule ("all"|"none"|[ids]) permit camera number $camNum? */
function _camera_rule_allows($rule, $camNum): bool {
    if ($rule === 'all') return true;
    if ($rule === 'none' || $rule === null) return false;
    if (is_array($rule)) return in_array((string)$camNum, array_map('strval', $rule), true);
    return false;
}
/** Can the current user see this camera as a map/search OBJECT? */
function can_view_camera_object(array $cam): bool {
    global $current_user, $pdo;
    if (!$current_user) return false;
    $site = (int)($cam['site_number'] ?? 0);
    $camId = (int)($cam['camera_number'] ?? 0);
    // Any 'cameras' grant (view+) covering this site OR this specific camera.
    return can($pdo, 'cameras', 'view', $site ?: null, $camId ?: null);
}
/** Can the current user see this camera's live FEED? (now same as object access) */
function can_view_camera_feed(array $cam): bool {
    global $current_user, $pdo;
    if (!$current_user) return false;
    $site = (int)($cam['site_number'] ?? 0);
    $camId = (int)($cam['camera_number'] ?? 0);
    return can($pdo, 'cameras', 'view', $site ?: null, $camId ?: null);
}
/**
 * Clean + normalize a camera_access structure coming from the admin UI.
 * Enforces: valid shape, feed ⊆ obj, drops empty/none sites, only keeps sites
 * the target user can actually access. Returns a compact array (or [] if none).
 */
function sanitize_camera_access($input, array $allowedSites): array {
    if (!is_array($input)) return [];
    $out = [];
    foreach ($input as $siteKey => $rule) {
        $site = (int)$siteKey;
        if ($site <= 0) continue;
        if (!in_array($site, $allowedSites, true)) continue; // only granted sites
        if (!is_array($rule)) continue;
        $obj  = $rule['obj']  ?? 'none';
        $feed = $rule['feed'] ?? 'none';
        // normalize obj
        if ($obj !== 'all' && $obj !== 'none') {
            $obj = is_array($obj) ? array_values(array_unique(array_map('strval', $obj))) : 'none';
            if (is_array($obj) && !count($obj)) $obj = 'none';
        }
        // normalize feed, then constrain to be a subset of obj
        if ($feed !== 'all' && $feed !== 'none') {
            $feed = is_array($feed) ? array_values(array_unique(array_map('strval', $feed))) : 'none';
            if (is_array($feed) && !count($feed)) $feed = 'none';
        }
        // enforce feed ⊆ obj
        if ($obj === 'none') {
            $feed = 'none';
        } elseif ($obj === 'all') {
            // feed may be all, none, or a specific list — all are subsets of "all"
        } else {
            // obj is a specific list
            if ($feed === 'all') {
                $feed = $obj; // "all feeds" can't exceed the visible objects
            } elseif (is_array($feed)) {
                $feed = array_values(array_intersect($feed, $obj));
                if (!count($feed)) $feed = 'none';
            }
        }
        if ($obj === 'none' && $feed === 'none') continue; // nothing to store
        $out[(string)$site] = ['obj' => $obj, 'feed' => $feed];
    }
    return $out;
}
/** Is this username the hardcoded, protected break-glass admin? (case-insensitive) */
function is_protected_username(?string $username): bool {
    return strtolower(trim((string)$username)) === strtolower(PROTECTED_ADMIN_USERNAME);
}
/** Resolve a public_id to its username (or null), to check protection by id. */
function username_for_public_id(PDO $pdo, string $publicId): ?string {
    $q = $pdo->prepare("SELECT username FROM users WHERE public_id = ?");
    $q->execute([$publicId]);
    $u = $q->fetchColumn();
    return $u === false ? null : (string)$u;
}

// ---- TOTP (RFC 6238) helpers — verified against RFC test vectors ----
/** Random, URL-safe, unguessable id (128-bit). The only user id exposed to clients. */
function generate_public_id(): string {
    return rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
}

/**
 * Central password policy. Returns an error string if the password is
 * unacceptable, or null if it's fine. Used by EVERY path that sets or changes a
 * password so the rules stay consistent. Rules: at least 8 characters, and not
 * one of the most common/guessable passwords (8 chars alone still permits
 * "password", "12345678", etc.). NOTE: this is separate from MFA codes, which
 * are always 6 digits and validated elsewhere.
 */
function password_rejection_reason(string $pw): ?string {
    if (strlen($pw) < 8) return 'Password must be at least 8 characters';
    static $common = [
        'password','password1','password12','password123','passw0rd','p@ssword','p@ssw0rd',
        '12345678','123456789','1234567890','123123123','111111111','000000000','12341234',
        'qwerty123','qwertyuiop','1q2w3e4r','1qaz2wsx','asdfghjkl','zxcvbnm123',
        'iloveyou','abc12345','admin123','root12345','letmein12','welcome1','welcome123',
        'changeme','changeme1','change123','football1','baseball1','superman1','trustno1',
        'monkey123','dragon123','sunshine1','princess1','starwars1','whatever1','computer1',
    ];
    if (in_array(strtolower($pw), $common, true)) {
        return 'That password is too common — please choose something less guessable';
    }
    return null;
}

function base32_encode_bin(string $data): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    if ($data === '') return '';
    $bits = '';
    foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out .= $alphabet[bindec($chunk)];
    }
    return $out;
}
function base32_decode_str(string $b32): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
    if ($b32 === '') return '';
    $bits = '';
    foreach (str_split($b32) as $c) {
        $pos = strpos($alphabet, $c);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $bytes = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
    }
    return $bytes;
}
/** Generate a new random base32 TOTP secret (160-bit, 32 chars). */
function totp_generate_secret(): string {
    return base32_encode_bin(random_bytes(20));
}
/** Compute the 6-digit code for a given time-step counter. */
function totp_code_at(string $secretBin, int $counter): string {
    $bin  = pack('N*', 0) . pack('N*', $counter); // 8-byte big-endian counter
    $hash = hash_hmac('sha1', $bin, $secretBin, true);
    $off  = ord(substr($hash, -1)) & 0x0F;
    $val  = (unpack('N', substr($hash, $off, 4))[1]) & 0x7FFFFFFF;
    return str_pad((string)($val % 1000000), 6, '0', STR_PAD_LEFT);
}
/** Verify a user-entered code against the secret, allowing ±1 step of clock drift. */
function totp_verify(string $base32Secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $secretBin = base32_decode_str($base32Secret);
    if ($secretBin === '') return false;
    $counter = intdiv(time(), 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code_at($secretBin, $counter + $i), $code)) return true;
    }
    return false;
}
/** otpauth:// URI for QR enrollment (issuer + account label). */
function totp_uri(string $base32Secret, string $account, string $issuer): string {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
        . '?secret=' . $base32Secret
        . '&issuer=' . rawurlencode($issuer)
        . '&algorithm=SHA1&digits=6&period=30';
}

// ---- System settings (cached key/value) ----
$GLOBALS['__settings_cache'] = null;
/** Built-in defaults; DB rows override these. New settings can be added here freely. */
function settings_defaults(): array {
    return [
        'session_timeout_minutes' => '480', // 8 hours, idle
        'session_warn_minutes'    => '10',
        'audit_retention_days'    => '90',
        'login_max_attempts'      => '5',   // 0 = lockouts disabled
        'login_lockout_minutes'   => '15',
        'login_lockout_manual'    => '0',   // 1 = stay locked until an admin unlocks
        // 1 = a new room pin borrows its building from the nearest room beside it
        'room_inherit_building'   => '1',
        // Default pin color per room type (JSON map type=>hex). Follows common
        // facility-map conventions: blue occupied spaces, cyan restrooms, orange
        // food, gray infrastructure. A room's own color always overrides.
        'room_type_colors'        => '{"general":"#3b82f6","classroom":"#3b82f6","office":"#475569","lab":"#8b5cf6","library":"#6366f1","breakroom":"#f97316","storage":"#a16207","restroom":"#06b6d4","utility":"#6b7280","hallway":"#9ca3af","conference":"#0ea5e9","cafeteria":"#f97316","gym":"#22c55e","auditorium":"#ec4899"}',
        'smtp_enabled'            => '0',
        'smtp_host'               => '',
        'smtp_port'               => '587',
        'smtp_user'               => '',
        'smtp_pass'               => '',
        'smtp_security'           => 'tls', // none | tls (STARTTLS) | ssl (implicit)
        'smtp_from_email'         => '',
        'smtp_from_name'          => 'Site Manager',
        'email_cap_hourly'        => '50',   // max emails/hour (0 = unlimited)
        'email_cap_daily'         => '200',  // max emails/day  (0 = unlimited)
        'invite_expiry_days'      => '7',    // how long an invite link stays valid
        'layer_cameras_enabled'   => '1',    // cameras layer: all-sites wall + overview (first device layer)
        'layer_printers_enabled'  => '1',    // printers layer: map pins per site (asset layer)
        'site_logo_path'          => '',     // uploaded site logo (relative path under uploads/)
        'site_brand_name'         => 'Site Manager', // brand text shown by the logo
    ];
}
function load_settings(PDO $pdo): array {
    if ($GLOBALS['__settings_cache'] !== null) return $GLOBALS['__settings_cache'];
    $vals = settings_defaults();
    try {
        foreach ($pdo->query("SELECT setting_key, setting_val FROM settings")->fetchAll() as $r) {
            $vals[$r['setting_key']] = $r['setting_val'];
        }
    } catch (\Throwable $e) { /* table may not exist yet — use defaults */ }
    return $GLOBALS['__settings_cache'] = $vals;
}
function setting_get(PDO $pdo, string $key, $default = null) {
    $s = load_settings($pdo);
    return $s[$key] ?? $default;
}
function setting_int(PDO $pdo, string $key, int $default): int {
    $v = setting_get($pdo, $key, null);
    return ($v === null || $v === '') ? $default : (int)$v;
}
// Write a single setting (upsert). Used by image upload to record the logo path.
function setting_set(PDO $pdo, string $key, string $val): void {
    $st = $pdo->prepare("INSERT INTO settings (setting_key, setting_val) VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
    $st->execute([$key, $val]);
}

// ====================================================================
// LOGIN LOCKOUT (brute-force protection)
//   Settings (in `settings`, edited from System Settings > Security):
//     login_max_attempts        int   default 5   (0 = lockouts disabled)
//     login_lockout_minutes     int   default 15  (duration; ignored if manual-only)
//     login_lockout_manual      '0'|'1'           1 = stay locked until an admin unlocks
//   The protected break-glass admin gets a higher threshold but is still lockable.
// ====================================================================
/** Does users have the lockout columns yet? (cached; snippet may not have run) */
function users_has_lockout(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $has = db_has_columns($pdo, 'users', ['failed_attempts','locked_until','last_failed_at']);
    } catch (\Throwable $e) { $has = false; }
    return $has;
}
/** Is this user row currently locked? (auto-expired locks read as unlocked.) */
function account_is_locked(?array $row): bool {
    if (!$row || empty($row['locked_until'])) return false;
    return strtotime((string)$row['locked_until']) > time();
}
/** Seconds remaining on a lock (0 if not locked). */
function account_lock_remaining(?array $row): int {
    if (!account_is_locked($row)) return 0;
    return max(0, strtotime((string)$row['locked_until']) - time());
}
/** Per-account attempt ceiling — the break-glass admin gets more headroom. */
function lockout_threshold_for(PDO $pdo, array $row): int {
    $base = setting_int($pdo, 'login_max_attempts', 5);
    if ($base <= 0) return 0; // lockouts disabled
    if (is_protected_username($row['username'] ?? '')) return $base + 5;
    return $base;
}
/**
 * Record a failed attempt and lock the account if it crosses the threshold.
 * Returns ['locked'=>bool, 'remaining_attempts'=>int].
 */
function lockout_register_failure(PDO $pdo, array $row): array {
    if (!users_has_lockout($pdo)) return ['locked' => false, 'remaining_attempts' => 99];
    $threshold = lockout_threshold_for($pdo, $row);
    $uid = (int)$row['user_id'];
    $attempts = (int)($row['failed_attempts'] ?? 0) + 1;
    if ($threshold > 0 && $attempts >= $threshold) {
        $manual = setting_get($pdo, 'login_lockout_manual', '0') === '1';
        if ($manual) {
            // far-future sentinel = "until an admin unlocks"
            $until = '2999-12-31 00:00:00';
        } else {
            $mins  = max(1, setting_int($pdo, 'login_lockout_minutes', 15));
            $until = date('Y-m-d H:i:s', time() + $mins * 60);
        }
        try {
            $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ?, last_failed_at = NOW() WHERE user_id = ?")
                ->execute([$attempts, $until, $uid]);
        } catch (\Throwable $e) {}
        audit($pdo, 'login.locked', ['target_type' => 'user', 'target_label' => $row['username'] ?? '', 'details' => ['attempts' => $attempts, 'manual' => $manual]]);
        return ['locked' => true, 'remaining_attempts' => 0];
    }
    try {
        $pdo->prepare("UPDATE users SET failed_attempts = ?, last_failed_at = NOW() WHERE user_id = ?")
            ->execute([$attempts, $uid]);
    } catch (\Throwable $e) {}
    return ['locked' => false, 'remaining_attempts' => max(0, $threshold - $attempts)];
}
/** Clear failures + lock after a successful login. */
function lockout_clear(PDO $pdo, int $uid): void {
    if (!users_has_lockout($pdo)) return;
    try {
        $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")->execute([$uid]);
    } catch (\Throwable $e) {}
}

// ====================================================================
// EMAIL  — minimal SMTP-over-socket sender (no external dependency).
//   Settings (System Settings > Email): smtp_enabled, smtp_host, smtp_port,
//   smtp_user, smtp_pass, smtp_security (none|tls|ssl), smtp_from_email,
//   smtp_from_name. The password is stored in `settings` and never echoed
//   back to the browser (masked in the list response).
// ====================================================================
const SMTP_PASS_MASK = '••••••••';

/** Encode a header value for non-ASCII; quote display names that need it. */
function mime_header_encode(string $s): string {
    if (preg_match('/[^\x20-\x7E]/', $s)) return '=?UTF-8?B?' . base64_encode($s) . '?=';
    return $s;
}
function mime_display_name(string $name): string {
    if ($name === '') return '';
    if (preg_match('/[^\x20-\x7E]/', $name)) return '=?UTF-8?B?' . base64_encode($name) . '?=';
    return '"' . addcslashes($name, "\"\\") . '"';
}

/**
 * Send an email using the configured SMTP settings.
 * Returns ['success'=>bool, 'error'=>string].  HTML body required; a plain-text
 * part is derived automatically if not supplied.
 */
const EMAIL_MAX_RECIPIENTS = 25;  // hard cap on recipients per single message

/** Is the email_log table present? (cached; add_email_log.sql may not have run) */
function email_log_available(PDO $pdo): bool {
    static $has = null;
    if ($has !== null) return $has;
    try {
        $n = db_has_table($pdo, 'email_log') ? 1 : 0;
        $has = ((int)$n === 1);
    } catch (\Throwable $e) { $has = false; }
    return $has;
}
/** Count emails actually SENT within a MySQL interval like '1 HOUR' / '1 DAY'. */
function email_sent_count(PDO $pdo, string $interval): int {
    if (!email_log_available($pdo)) return 0;
    try {
        // $interval is a fixed literal we control below — never user input.
        return (int)$pdo->query("SELECT COALESCE(SUM(recipient_count),0) FROM email_log WHERE status='sent' AND created_at >= (NOW() - INTERVAL $interval)")->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}
/** Record one send attempt (sent | failed | blocked). */
function log_email(PDO $pdo, string $recipient, int $count, string $subject, string $purpose, string $status, string $error = ''): void {
    if (!email_log_available($pdo)) return;
    try {
        $pdo->prepare("INSERT INTO email_log (recipient, recipient_count, subject, purpose, status, error) VALUES (?,?,?,?,?,?)")
            ->execute([substr($recipient, 0, 255), $count, substr($subject, 0, 255), substr($purpose, 0, 64), $status, substr($error, 0, 255)]);
    } catch (\Throwable $e) {}
}

/**
 * Send an email through the configured SMTP server, with guardrails:
 *   - kill switch (smtp_enabled)         - recipient cap per message
 *   - global hourly + daily send caps    - every attempt logged
 * Returns ['success'=>bool, 'error'=>string].
 */
function send_mail(PDO $pdo, $to, string $subject, string $htmlBody, ?string $textBody = null, string $purpose = 'general'): array {
    $s = load_settings($pdo);
    // KILL SWITCH — if email is off, nothing sends, period.
    if (($s['smtp_enabled'] ?? '0') !== '1') return ['success' => false, 'error' => 'Email is not enabled in System Settings'];

    $recipients = array_values(array_filter(array_map('trim', is_array($to) ? $to : [$to])));
    if (!$recipients) return ['success' => false, 'error' => 'No recipient'];
    foreach ($recipients as $r) {
        if (!filter_var($r, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => "Invalid recipient: $r"];
    }
    $n = count($recipients);
    $firstTo = $recipients[0];

    // RECIPIENT GUARD — no single message can fan out to a huge list (bug or misuse).
    if ($n > EMAIL_MAX_RECIPIENTS) {
        log_email($pdo, $firstTo, $n, $subject, $purpose, 'blocked', 'too many recipients');
        return ['success' => false, 'error' => 'Refused: a single message can have at most ' . EMAIL_MAX_RECIPIENTS . ' recipients'];
    }

    // GLOBAL CAPS — hard ceiling so nothing can mass-mail (0 = unlimited).
    $capH = (int)($s['email_cap_hourly'] ?? 50);
    $capD = (int)($s['email_cap_daily'] ?? 200);
    if ($capH > 0 && (email_sent_count($pdo, '1 HOUR') + $n) > $capH) {
        log_email($pdo, $firstTo, $n, $subject, $purpose, 'blocked', 'hourly cap reached');
        return ['success' => false, 'error' => 'Hourly email limit reached. Try again later or raise the limit in System Settings.'];
    }
    if ($capD > 0 && (email_sent_count($pdo, '1 DAY') + $n) > $capD) {
        log_email($pdo, $firstTo, $n, $subject, $purpose, 'blocked', 'daily cap reached');
        return ['success' => false, 'error' => 'Daily email limit reached. Try again later or raise the limit in System Settings.'];
    }

    $host = trim((string)($s['smtp_host'] ?? ''));
    $port = (int)($s['smtp_port'] ?? 587);
    $user = trim((string)($s['smtp_user'] ?? ''));
    $pass = (string)($s['smtp_pass'] ?? '');
    $sec  = (string)($s['smtp_security'] ?? 'tls');
    $fromEmail = trim((string)($s['smtp_from_email'] ?? ''));
    $fromName  = trim((string)($s['smtp_from_name'] ?? 'Site Manager'));
    if ($host === '' || $fromEmail === '') {
        log_email($pdo, $firstTo, $n, $subject, $purpose, 'failed', 'SMTP host/From not configured');
        return ['success' => false, 'error' => 'SMTP host and From address are required'];
    }
    if ($textBody === null) {
        $textBody = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));
    }
    try {
        $res = smtp_dispatch($host, $port, $sec, $user, $pass, $fromEmail, $fromName, $recipients, $subject, $htmlBody, $textBody);
    } catch (\Throwable $e) {
        $res = ['success' => false, 'error' => $e->getMessage()];
    }
    log_email($pdo, $firstTo, $n, $subject, $purpose, !empty($res['success']) ? 'sent' : 'failed', !empty($res['success']) ? '' : ($res['error'] ?? ''));
    return $res;
}

function smtp_dispatch(string $host, int $port, string $sec, string $user, string $pass, string $fromEmail, string $fromName, array $to, string $subject, string $html, string $text): array {
    $timeout = 12;
    $remote  = ($sec === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]);
    $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['success' => false, 'error' => "Connection failed: $errstr ($errno)"];
    stream_set_timeout($fp, $timeout);

    $read = function () use ($fp) {
        $data = '';
        while (($line = fgets($fp, 515)) !== false) {
            $data .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break; // last line of a (possibly multiline) reply
        }
        return $data;
    };
    $code = fn($resp) => (int)substr((string)$resp, 0, 3);
    $cmd  = function ($c) use ($fp, $read) { fwrite($fp, $c . "\r\n"); return $read(); };

    if ($code($read()) !== 220) { fclose($fp); return ['success' => false, 'error' => 'Server did not greet (220)']; }
    $ehlo = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if ($code($cmd("EHLO $ehlo")) !== 250) {
        if ($code($cmd("HELO $ehlo")) !== 250) { fclose($fp); return ['success' => false, 'error' => 'EHLO/HELO refused']; }
    }
    if ($sec === 'tls') {
        if ($code($cmd('STARTTLS')) !== 220) { fclose($fp); return ['success' => false, 'error' => 'STARTTLS refused']; }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return ['success' => false, 'error' => 'TLS negotiation failed']; }
        $cmd("EHLO $ehlo"); // must re-EHLO after the channel is encrypted
    }
    if ($user !== '') {
        if ($code($cmd('AUTH LOGIN')) !== 334) { fclose($fp); return ['success' => false, 'error' => 'Server rejected AUTH LOGIN']; }
        if ($code($cmd(base64_encode($user))) !== 334) { fclose($fp); return ['success' => false, 'error' => 'Username not accepted']; }
        if ($code($cmd(base64_encode($pass))) !== 235) { fclose($fp); return ['success' => false, 'error' => 'Authentication failed (check user/password)']; }
    }
    if ($code($cmd("MAIL FROM:<$fromEmail>")) !== 250) { fclose($fp); return ['success' => false, 'error' => 'Sender (MAIL FROM) rejected']; }
    foreach ($to as $rcpt) {
        if (!in_array($code($cmd("RCPT TO:<$rcpt>")), [250, 251], true)) { fclose($fp); return ['success' => false, 'error' => "Recipient rejected: $rcpt"]; }
    }
    if ($code($cmd('DATA')) !== 354) { fclose($fp); return ['success' => false, 'error' => 'DATA command rejected']; }

    $boundary = 'bnd_' . bin2hex(random_bytes(8));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . trim(mime_display_name($fromName) . " <$fromEmail>"),
        'To: ' . implode(', ', array_map(fn($t) => "<$t>", $to)),
        'Subject: ' . mime_header_encode($subject),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $host . '>',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];
    $body  = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($text) . "\r\n";
    $body .= "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($html) . "\r\n";
    $body .= "--$boundary--\r\n";
    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $message = preg_replace('/^\./m', '..', $message); // dot-stuffing

    fwrite($fp, $message . "\r\n.\r\n");
    if ($code($read()) !== 250) { fclose($fp); return ['success' => false, 'error' => 'Message body not accepted by server']; }
    $cmd('QUIT');
    fclose($fp);
    return ['success' => true, 'error' => ''];
}

/**
 * Create (or refresh) an invite token for a user and email them the activation
 * link. Returns ['success'=>bool, 'error'=>string]. Used by invite + resend.
 */
function send_invite(PDO $pdo, int $userId, string $username, string $email): array {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'User has no valid email address'];
    $days = max(1, setting_int($pdo, 'invite_expiry_days', 7));
    try {
        // One active invite per user — clear old invite tokens first.
        $pdo->prepare("DELETE FROM password_reset_token WHERE user_id = ? AND purpose = 'invite'")->execute([$userId]);
        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $exp  = date('Y-m-d H:i:s', time() + $days * 86400);
        $pdo->prepare("INSERT INTO password_reset_token (user_id, token_hash, expires_at, purpose, requested_ip) VALUES (?,?,?,'invite',?)")
            ->execute([$userId, $hash, $exp, client_ip()]);
    } catch (\Throwable $e) {
        return ['success' => false, 'error' => 'Could not create invite token'];
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $link   = $scheme . '://' . $host . $path . '?invite=' . $raw;
    $html = '<div style="font-family:sans-serif;font-size:15px;color:#111">'
          . '<h2 style="margin:0 0 10px">You\'ve been invited to Site Manager</h2>'
          . '<p>An administrator created an account for you (username <strong>' . htmlspecialchars($username) . '</strong>).</p>'
          . '<p>Click below to set your password and finish setting up your account:</p>'
          . '<p style="margin:18px 0"><a href="' . htmlspecialchars($link) . '" style="background:#3b82f6;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:600">Activate my account</a></p>'
          . '<p style="color:#666;font-size:13px">This invitation expires in ' . $days . ' day' . ($days === 1 ? '' : 's') . '. If you weren\'t expecting it, you can ignore this email.</p>'
          . '</div>';
    return send_mail($pdo, $email, 'Your Site Manager account invitation', $html, null, 'invite');
}

/** Best-effort client IP, respecting a proxy header if present. */
function client_ip(): string {
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = trim(explode(',', $_SERVER[$k])[0]); // first hop if a list
            if ($ip !== '') return substr($ip, 0, 45);
        }
    }
    return '';
}

/**
 * Append an audit-log entry. Best-effort: never throws into the request flow.
 * $action is a dotted event type, e.g. 'login', 'user.update', 'room.delete'.
 * $details is arbitrary structured context (e.g. ['before'=>..., 'after'=>...]).
 */
function audit(PDO $pdo, string $action, array $opts = []): void {
    global $current_user;
    try {
        $actorId   = $opts['actor_id']   ?? ($current_user['user_id'] ?? null);
        $actorName = $opts['actor_name'] ?? ($current_user['display_name'] ?? ($current_user['username'] ?? null));
        $details   = $opts['details']    ?? null;
        $stmt = $pdo->prepare("INSERT INTO audit_log
            (actor_id, actor_name, action, target_type, target_label, details, ip_address, user_agent)
            VALUES (?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $actorId !== null ? (int)$actorId : null,
            $actorName !== null ? substr((string)$actorName, 0, 120) : null,
            substr($action, 0, 64),
            isset($opts['target_type']) ? substr((string)$opts['target_type'], 0, 40) : null,
            isset($opts['target_label']) ? substr((string)$opts['target_label'], 0, 160) : null,
            $details !== null ? json_encode($details) : null,
            client_ip() ?: null,
            isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ]);
    } catch (\Throwable $e) {
        error_log('Audit write failed (' . $action . '): ' . $e->getMessage());
    }
}

/** Delete audit rows older than the configured retention. Cheap, runs opportunistically. */
function prune_audit_log(PDO $pdo): void {
    try {
        $days = setting_int($pdo, 'audit_retention_days', 90);
        if ($days <= 0) return; // 0 = keep forever
        $pdo->prepare("DELETE FROM audit_log WHERE created_at < (NOW() - INTERVAL ? DAY)")->execute([$days]);
    } catch (\Throwable $e) { /* non-fatal */ }
}

/** Renders the standalone login screen (shown when not authenticated). */
