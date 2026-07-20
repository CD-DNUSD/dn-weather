<?php
/**
 * DNCOE Site Manager v0.28
 *
 * Interactive site / room / device manager.
 * Click a site → see floor plan with clickable rooms.
 * Click a room → basic info popup.
 * Enter a room → full-screen view with devices (TVs, Printers, Newline TVs,
 * Projectors, Chromebook Carts, Staff Devices, etc.) placed by x/y %.
 *
 * Reference base: legacy NVR Dashboard (camera/streaming code removed).
 *
 * ============================================================================
 *  ONE-TIME DATABASE MIGRATION  (run once in MySQL before first use)
 * ============================================================================
 *
 *  -- site already exists; ensure svg_path column is there:
 *  ALTER TABLE site
 *      ADD COLUMN IF NOT EXISTS svg_path VARCHAR(255) DEFAULT NULL;
 *
 *  CREATE TABLE IF NOT EXISTS room (
 *      room_id        INT AUTO_INCREMENT PRIMARY KEY,
 *      site_number    INT NOT NULL,
 *      room_name      VARCHAR(150) NOT NULL,
 *      room_number    VARCHAR(50)  DEFAULT NULL,
 *      room_type      VARCHAR(60)  DEFAULT 'general',
 *      department     VARCHAR(80)  DEFAULT NULL,
 *      capacity       INT          DEFAULT NULL,
 *      description    TEXT         DEFAULT NULL,
 *      map_level      VARCHAR(40)  NOT NULL DEFAULT 'level-1',
 *      polygon_points TEXT         DEFAULT NULL,  -- JSON: [{"x":12.3,"y":45.6},...]
 *      label_x        DECIMAL(7,3) DEFAULT NULL,
 *      label_y        DECIMAL(7,3) DEFAULT NULL,
 *      color          VARCHAR(20)  DEFAULT NULL,
 *      is_active      TINYINT(1)   NOT NULL DEFAULT 1,
 *      created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
 *      updated_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *      INDEX idx_site (site_number),
 *      INDEX idx_site_level (site_number, map_level)
 *  );
 *
 *  CREATE TABLE IF NOT EXISTS device_type (
 *      device_type_id INT AUTO_INCREMENT PRIMARY KEY,
 *      type_key       VARCHAR(40)  UNIQUE NOT NULL,
 *      type_name      VARCHAR(80)  NOT NULL,
 *      icon           VARCHAR(30)  DEFAULT NULL,
 *      color          VARCHAR(20)  DEFAULT NULL,
 *      category       VARCHAR(40)  DEFAULT 'IT',
 *      sort_order     INT          DEFAULT 100
 *  );
 *
 *  INSERT IGNORE INTO device_type (type_key, type_name, icon, color, category, sort_order) VALUES
 *      ('newline_tv',      'Newline TV',       'tv',        '#8b5cf6', 'AV', 10),
 *      ('projector',       'Projector',        'projector', '#f59e0b', 'AV', 20),
 *      ('tv',              'TV',               'tv',        '#ec4899', 'AV', 30),
 *      ('printer',         'Printer',          'printer',   '#3b82f6', 'IT', 40),
 *      ('chromebook_cart', 'Chromebook Cart',  'cart',      '#10b981', 'IT', 50),
 *      ('staff_device',    'Staff Device',     'laptop',    '#06b6d4', 'IT', 60),
 *      ('desktop',         'Desktop',          'desktop',   '#6366f1', 'IT', 70),
 *      ('phone',           'Phone',            'phone',     '#64748b', 'IT', 80),
 *      ('other',           'Other',            'box',       '#94a3b8', 'Misc', 999);
 *
 *  CREATE TABLE IF NOT EXISTS device (
 *      device_id       INT AUTO_INCREMENT PRIMARY KEY,
 *      room_id         INT NOT NULL,
 *      device_type_key VARCHAR(40)  NOT NULL,
 *      device_name     VARCHAR(150) NOT NULL,
 *      asset_tag       VARCHAR(80)  DEFAULT NULL,
 *      model           VARCHAR(120) DEFAULT NULL,
 *      serial_number   VARCHAR(120) DEFAULT NULL,
 *      ip_address      VARCHAR(45)  DEFAULT NULL,
 *      status          VARCHAR(30)  DEFAULT 'active',
 *      notes           TEXT         DEFAULT NULL,
 *      pos_x           DECIMAL(7,3) DEFAULT NULL,
 *      pos_y           DECIMAL(7,3) DEFAULT NULL,
 *      created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
 *      updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *      INDEX idx_room (room_id),
 *      INDEX idx_type (device_type_key),
 *      FOREIGN KEY (room_id) REFERENCES room(room_id) ON DELETE CASCADE
 *  );
 *
 * ============================================================================
 */

declare(strict_types=1);

const APP_VERSION = '0.47.12';   // bump on release; also busts asset caches (?v=)

// Absolute path of the application root (the folder holding index.php).
// All stored-file locations (uploads/, maps/) anchor here, so code modules in
// inc/ can move freely without changing where user data lives on disk.
define('APP_ROOT', dirname(__DIR__));

define('DEBUG_MODE', false);

// The hardcoded break-glass admin. This account cannot be deleted, disabled,
// demoted, or renamed, and is hidden from the Users list. Only its password
// can be changed. Enforced server-side in the user API regardless of client.
define('PROTECTED_ADMIN_USERNAME', 'admin');

// The glass-break SUPER ADMIN. Identity & power live HERE in code (not the DB),
// so a database compromise cannot fabricate, alter, or escalate to it. It holds
// every permission unconditionally, is invisible to all user-management surfaces
// EXCEPT itself, and can never be edited/deleted through the app. Authentication
// is still fully enforced — being this username grants no login bypass, only
// permission bypass once correctly authenticated. Defaults to the same protected
// admin; change here (and only here) to designate a different glass-break login.
define('GLASSBREAK_USERNAME', 'admin');

if (DEBUG_MODE) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);
}

// ================================================================
// HELPERS
// ================================================================
function esc(string $v): string {
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function outputDebugError(\Throwable $e): void {
    if (DEBUG_MODE) {
        echo '<pre>Error: ' . esc($e->getMessage()) . "\n" . esc($e->getTraceAsString()) . '</pre>';
    } else {
        echo 'An unexpected error occurred.';
    }
}

function jsonResponse($data, int $code = 200): void {
    http_response_code($code);
    // Which build actually answered — ends "is the new api.php deployed?"
    // forever: any response's headers name the version that produced it.
    if (defined('APP_VERSION')) { header('X-App-Version: ' . APP_VERSION); }
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * True if every named column exists on the table. This is THE way to guard
 * migration-dependent features — it replaces the hand-rolled
 * information_schema queries that each feature used to carry individually.
 * Column lists are cached per table for the request, so five separate checks
 * against `users` now cost one query instead of five.
 */
function db_has_columns(PDO $pdo, string $table, array $cols): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $st = $pdo->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $st->execute([$table]);
            $cache[$table] = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) { $cache[$table] = []; }
    }
    foreach ($cols as $c) { if (!in_array((string)$c, $cache[$table], true)) return false; }
    return true;
}

/** True if the table exists (cached per request). Sibling of db_has_columns(). */
function db_has_table(PDO $pdo, string $table): bool {
    static $cache = [];
    if (!isset($cache[$table])) {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $st->execute([$table]);
            $cache[$table] = ((int)$st->fetchColumn() === 1);
        } catch (\Throwable $e) { $cache[$table] = false; }
    }
    return $cache[$table];
}

function jsonInput(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// ================================================================
// SECURITY HEADERS
// ================================================================
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

// ================================================================
// AUTH — session + helpers (user is resolved after DB connects)
// ================================================================
// Session cookie hardening: HttpOnly keeps JS away from the cookie; SameSite=Lax
// blocks cross-site POSTs (CSRF) in modern browsers. 'secure' stays off because
// the app currently runs over plain HTTP — flip it to true if TLS is added.
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => false]);
// Sessions must outlive the app's own idle timeout — PHP's default GC
// (~24 minutes) was silently destroying sessions long before the configured
// timeout, which is why "idle logout" seemed to strike at random and the
// login page's baked CSRF token kept going stale. The app now enforces the
// real timeout itself (see inc/db.php); GC is just the safety net.
ini_set('session.gc_maxlifetime', '86400');
session_start();

// CSRF defense-in-depth. SameSite=Lax already blocks the common cross-site POST,
// but this adds a second, independent layer: every state-changing (POST) request
// to the API must echo the per-session token via the X-CSRF-Token header. A
// cross-site <form> can't set custom headers, and a cross-origin fetch that tries
// trips a CORS preflight we never satisfy — so forged requests are rejected while
// the app's own calls (which attach the header via a global fetch wrapper) pass.
// GET requests are exempt: they must never change state anyway.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
/** True if this request is allowed through CSRF-wise (non-POST always is). */
function csrf_ok(): bool {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') return true;
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $have = $_SESSION['csrf_token'] ?? '';
    return $have !== '' && is_string($sent) && hash_equals($have, $sent);
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_GET['api']) && !csrf_ok()) {
    // The page the user is sitting on baked in a token that no longer matches
    // (typically: the session expired and restarted while the login page sat
    // open). The server KNOWS the current token — handing it back lets the
    // page adopt it and retry silently instead of demanding a manual reload.
    // Returning it in same-origin-readable JSON is safe: cross-origin pages
    // can't read responses, which is the same property the meta tag relies on.
    if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
    jsonResponse([
        'success'     => false,
        'error'       => 'Security check failed (invalid or missing token). Reload the page and try again.',
        'error_code'  => 'csrf',
        'fresh_token' => $_SESSION['csrf_token'],
    ], 403);
}

