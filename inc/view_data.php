<?php
// ============================================================
// Site Manager — view_data.php
// View-model prep: sites/rooms/devices/cameras payloads for the shell.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================

// ================================================================
// SITE PALETTE (site table has no color column)
// ================================================================
$site_palette = [
    '#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6',
    '#ec4899','#06b6d4','#84cc16','#f97316','#6366f1',
    '#14b8a6','#e11d48','#0ea5e9','#a855f7','#eab308',
    '#22d3ee','#fb923c','#c084fc',
];

// ================================================================
// SITE LOGO  (prefer the new settings-based logo uploaded via System
// Settings → Branding; fall back to the legacy resources.logoPath.)
// ================================================================
$logoPath = '';
$logoVersion = time();
$logoSrc = '';   // URL the header <img> should use
try {
    $rel = (string)setting_get($pdo, 'site_logo_path', '');
    if ($rel !== '' && is_file(APP_ROOT . '/' . ltrim($rel, '/'))) {
        $logoPath = APP_ROOT . '/' . ltrim($rel, '/');
        $logoVersion = @filemtime($logoPath) ?: time();
        $logoSrc = '?api=image&action=serve&kind=logo&v=' . $logoVersion;
    }
} catch (\Throwable $e) {}
if ($logoPath === '') {
    try {
        $resRow = $pdo->query("SELECT logoPath FROM resources LIMIT 1")->fetch();
        if ($resRow && !empty($resRow['logoPath'])) {
            $logoPath = $resRow['logoPath'];
            if (file_exists($logoPath)) {
                $logoVersion = filemtime($logoPath);
                $logoSrc = esc($logoPath) . '?v=' . $logoVersion;
            }
        }
    } catch (\Throwable $e) {
        error_log('Logo fetch failed: ' . $e->getMessage());
    }
}

// ================================================================
// FETCH SITES
// ================================================================
$sites    = [];
$siteMap  = [];
$colorIdx = 0;

// Geographic placement (added by add_site_geo.sql). Detect once; if present,
// pull lat/lng/overlay-size for every site up front and merge into $sites below.
// Guarded so the app keeps working before the migration is run.
$geoBySite = [];
try {
    $hasGeoCols = db_has_columns($pdo, 'site', ['site_lat','site_lng','site_map_meters']);
    if ($hasGeoCols) {
        foreach ($pdo->query("SELECT site_number, site_lat, site_lng, site_map_meters FROM site")->fetchAll() as $g) {
            $geoBySite[(int)$g['site_number']] = [
                'lat'     => $g['site_lat'] !== null ? (float)$g['site_lat'] : null,
                'lng'     => $g['site_lng'] !== null ? (float)$g['site_lng'] : null,
                'meters'  => $g['site_map_meters'] !== null ? (float)$g['site_map_meters'] : null,
            ];
        }
    }
} catch (\Throwable $e) { /* pre-migration — no geo data */ }

try {
    // svg_path deliberately absent: the legacy column is retired (and dropped
    // by drop_legacy_svg_path.sql) — floor plans come from site_map only.
    // color is optional (add_site_color.sql): NULL falls back to the auto palette.
    $hasSiteColor = db_has_columns($pdo, 'site', ['color']);
    $siteRows = $pdo->query("
        SELECT site_number, site_name, site_abbreviation, building_angle"
        . ($hasSiteColor ? ", color" : "") . "
        FROM site WHERE site_active = 1
        ORDER BY site_name ASC
    ")->fetchAll();
} catch (\Throwable $e) {
    try {
        // older schema: no building_angle yet
        $siteRows = $pdo->query("
            SELECT site_number, site_name, site_abbreviation
            FROM site WHERE site_active = 1
            ORDER BY site_name ASC
        ")->fetchAll();
    } catch (\Throwable $e2) {
        http_response_code(500);
        error_log($e2->getMessage());
        outputDebugError($e2);
        exit;
    }
}

foreach ($siteRows as $row) {
    $sn = (int)$row['site_number'];
    // Per-user access: non-admins only see their assigned sites ($my_sites is
    // null for admins, meaning "all").
    if ($my_sites !== null && !in_array($sn, $my_sites, true)) continue;
    // Maps come exclusively from site_map (the single source of truth).
    $siteMaps = [];
    try {
        // is_default may not exist yet (added by add_map_default.sql) — detect once.
        static $hasMapDefault = null;
        if ($hasMapDefault === null) {
            try { $pdo->query("SELECT is_default FROM site_map LIMIT 1"); $hasMapDefault = true; }
            catch (\Throwable $e) { $hasMapDefault = false; }
        }
        static $hasMapZoom = null;
        if ($hasMapZoom === null) {
            try { $pdo->query("SELECT default_zoom FROM site_map LIMIT 1"); $hasMapZoom = true; }
            catch (\Throwable $e) { $hasMapZoom = false; }
        }
        // Start-view columns (add_map_focus.sql). Two bugs lived here: the flag
        // was set inside the run-once block above (undefined for every site
        // after the first), and — decisively — focus_x/focus_y were never added
        // to the column list below, so the values sat correct in the database
        // while the browser never received them. That single missing fetch is
        // why the start-view feature "saved but never restored" across versions.
        static $hasMapFocus = null;
        if ($hasMapFocus === null) $hasMapFocus = db_has_columns($pdo, 'site_map', ['focus_x','focus_y']);
        // Per-map dot-mode threshold (add_map_dot_zoom.sql). NOTE: 0 is a real
        // value ("never dots"), so only NULL may fall back to the default.
        static $hasMapDot = null;
        if ($hasMapDot === null) $hasMapDot = db_has_columns($pdo, 'site_map', ['dot_zoom']);
        $dcol = ($hasMapDefault ? ', is_default' : '') . ($hasMapZoom ? ', default_zoom' : '')
              . ($hasMapFocus ? ', focus_x, focus_y' : '') . ($hasMapDot ? ', dot_zoom' : '');
        $mq = $pdo->prepare("SELECT map_key, name, svg_path, sort_order$dcol FROM site_map WHERE site_number = ? ORDER BY sort_order ASC, name ASC");
        $mq->execute([$sn]);
        foreach ($mq->fetchAll() as $m) {
            $siteMaps[] = [
                'key'        => $m['map_key'],
                'name'       => $m['name'],
                'has_svg'    => !empty($m['svg_path']) && file_exists($m['svg_path']),
                'is_default' => $hasMapDefault ? ((int)($m['is_default'] ?? 0) === 1) : false,
                'default_zoom' => ($hasMapZoom && ($m['default_zoom'] ?? null) !== null) ? (float)$m['default_zoom'] : null,
                'focus_x'    => ($hasMapFocus && ($m['focus_x'] ?? null) !== null) ? (float)$m['focus_x'] : null,
                'focus_y'    => ($hasMapFocus && ($m['focus_y'] ?? null) !== null) ? (float)$m['focus_y'] : null,
                'dot_zoom'   => ($hasMapDot && ($m['dot_zoom'] ?? null) !== null) ? (float)$m['dot_zoom'] : null,
            ];
        }
    } catch (\Throwable $e) { /* table not present yet — use the single-map fallback below */ }
    // (Legacy implicit-"Main" fallback from site.svg_path removed — site_map is
    // the single source of truth; migrate_svg_to_site_map.sql backfills old sites.)
    $sites[] = [
        'id'       => $sn,
        'name'     => $row['site_name'],
        'abbr'     => $row['site_abbreviation'] ?? '',
        // Site colours were always auto-assigned from a fixed palette by index —
        // there was nothing to edit. A stored colour now wins; NULL keeps the
        // palette pick, so untouched sites look exactly as they do today.
        'color'    => (!empty($row['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $row['color']))
                        ? strtolower($row['color'])
                        : $site_palette[$colorIdx % count($site_palette)],
        'has_map'  => count($siteMaps) > 0,
        'maps'     => $siteMaps,
        'building_angle' => isset($row['building_angle']) ? (float)$row['building_angle'] : 0,
        'lat'        => $geoBySite[$sn]['lat']    ?? null,
        'lng'        => $geoBySite[$sn]['lng']    ?? null,
        'geo_meters' => $geoBySite[$sn]['meters'] ?? null,
    ];
    $siteMap[$sn] = $colorIdx;
    $colorIdx++;
}

// ================================================================
// FETCH ROOMS (all active rooms — small enough to send in one payload)
// ================================================================
$rooms = [];
try {
    $roomRows = $pdo->query("SELECT * FROM room WHERE is_active = 1 ORDER BY site_number, room_name")->fetchAll();
    // Pull all occupants once, group by room_id (avoids N queries).
    $occByRoom = [];
    try {
        $ocRows = $pdo->query("SELECT * FROM room_occupant ORDER BY is_primary DESC, sort_order ASC, occupant_id ASC")->fetchAll();
        foreach ($ocRows as $o) {
            $rid = (int)$o['room_id'];
            $o['occupant_id'] = (int)$o['occupant_id'];
            $o['room_id']     = $rid;
            $o['is_primary']  = (int)$o['is_primary'];
            $occByRoom[$rid][] = $o;
        }
    } catch (\Throwable $e) {
        // room_occupant table may not exist until migration is run — non-fatal
        error_log('Occupant fetch skipped: ' . $e->getMessage());
    }
    foreach ($roomRows as $r) {
        $sn = (int)$r['site_number'];
        if ($my_sites !== null && !in_array($sn, $my_sites, true)) continue; // per-user access
        $r['polygon_points'] = $r['polygon_points'] ? json_decode($r['polygon_points'], true) : [];
        $r['room_shape']     = (array_key_exists('room_shape', $r) && $r['room_shape']) ? json_decode($r['room_shape'], true) : null;
        $r['room_id']        = (int)$r['room_id'];
        $r['site_number']    = $sn;
        $r['capacity']       = $r['capacity'] !== null ? (int)$r['capacity'] : null;
        $r['label_x']        = $r['label_x'] !== null ? (float)$r['label_x'] : null;
        $r['label_y']        = $r['label_y'] !== null ? (float)$r['label_y'] : null;
        $r['room_extension'] = $r['room_extension'] ?? null;
        $r['room_notes']     = $r['room_notes'] ?? null;
        // PDO can return TINYINT as the string "0", which is truthy in JS — send a
        // clean 0/1 (column may not exist yet pre-migration, hence the key check).
        $r['show_primary_contact'] = array_key_exists('show_primary_contact', $r)
            ? (int)$r['show_primary_contact'] : 0;
        $r['occupants']      = $occByRoom[$r['room_id']] ?? [];
        $rooms[] = $r;
    }
} catch (\Throwable $e) {
    // table doesn't exist yet — show empty until schema is migrated
    error_log('Room fetch failed (run the SQL migration in the file header): ' . $e->getMessage());
}

// ================================================================
// FETCH DEVICE TYPES
// ================================================================
$device_types = [];
try {
    $dtRows = $pdo->query("SELECT * FROM device_type ORDER BY sort_order ASC, type_name ASC")->fetchAll();
    foreach ($dtRows as $dt) {
        $device_types[] = [
            'key'      => $dt['type_key'],
            'name'     => $dt['type_name'],
            'icon'     => $dt['icon']  ?? 'box',
            'color'    => $dt['color'] ?? '#94a3b8',
            'category' => $dt['category'] ?? 'Misc',
        ];
    }
} catch (\Throwable $e) {
    // Fallback default set if table missing
    $device_types = [
        ['key' => 'newline_tv',      'name' => 'Newline TV',      'icon' => 'tv',        'color' => '#8b5cf6', 'category' => 'AV'],
        ['key' => 'projector',       'name' => 'Projector',       'icon' => 'projector', 'color' => '#f59e0b', 'category' => 'AV'],
        ['key' => 'tv',              'name' => 'TV',              'icon' => 'tv',        'color' => '#ec4899', 'category' => 'AV'],
        ['key' => 'printer',         'name' => 'Printer',         'icon' => 'printer',   'color' => '#3b82f6', 'category' => 'IT'],
        ['key' => 'chromebook_cart', 'name' => 'Chromebook Cart', 'icon' => 'cart',      'color' => '#10b981', 'category' => 'IT'],
        ['key' => 'staff_device',    'name' => 'Staff Device',    'icon' => 'laptop',    'color' => '#06b6d4', 'category' => 'IT'],
        ['key' => 'desktop',         'name' => 'Desktop',         'icon' => 'desktop',   'color' => '#6366f1', 'category' => 'IT'],
        ['key' => 'other',           'name' => 'Other',           'icon' => 'box',       'color' => '#94a3b8', 'category' => 'Misc'],
    ];
    error_log('device_type table missing — using defaults: ' . $e->getMessage());
}

// ================================================================
// FETCH DEVICES (all — keep payload small; switch to per-room fetch when scale demands it)
// ================================================================
$devices = [];
try {
    $devRows = $pdo->query("SELECT * FROM device ORDER BY room_id, device_name")->fetchAll();
    // Map room_id -> site_number for the rooms this user can see, so devices in
    // off-limits sites are never sent. Also require the 'devices' layer grant.
    $roomSite = [];
    foreach ($rooms as $rr) { $roomSite[(int)$rr['room_id']] = (int)$rr['site_number']; }
    foreach ($devRows as $d) {
        $rid = (int)$d['room_id'];
        if (!isset($roomSite[$rid])) continue;                       // room not visible to this user
        if (!can($pdo, 'devices', 'view', $roomSite[$rid])) continue; // needs devices layer on that site
        $d['device_id'] = (int)$d['device_id'];
        $d['room_id']   = $rid;
        $d['pos_x']     = $d['pos_x'] !== null ? (float)$d['pos_x'] : null;
        $d['pos_y']     = $d['pos_y'] !== null ? (float)$d['pos_y'] : null;
        $devices[] = $d;
    }
} catch (\Throwable $e) {
    // table missing — empty
    error_log('Device fetch failed: ' . $e->getMessage());
}

// ================================================================
// STATS (rooms / devices per site)
// ================================================================
$site_counts = [];
foreach ($sites as $s) {
    $sid = $s['id'];
    $siteRooms   = array_filter($rooms,   fn($r) => $r['site_number'] === $sid);
    $siteRoomIds = array_map(fn($r) => $r['room_id'], $siteRooms);
    $siteDevices = array_filter($devices, fn($d) => in_array($d['room_id'], $siteRoomIds, true));
    $site_counts[$sid] = [
        'rooms'   => count($siteRooms),
        'devices' => count($siteDevices),
    ];
}

$sites_json        = json_encode(array_values($sites),        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$rooms_json        = json_encode(array_values($rooms),        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$devices_json      = json_encode(array_values($devices),      JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$device_types_json = json_encode(array_values($device_types), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$site_counts_json  = json_encode($site_counts);

// Lightweight camera list for the admin camera-permission picker (admins only).
// Just identity + site + active flag — no stream URLs are exposed here.
$cameras_admin_json = '[]';
if ($is_admin) {
    try {
        $camRows = $pdo->query("SELECT camera_number, camera_name, site_number, is_active FROM camera ORDER BY site_number, camera_name")->fetchAll();
        $camList = [];
        foreach ($camRows as $c) {
            $camList[] = [
                'camera_number' => (string)$c['camera_number'],
                'camera_name'   => $c['camera_name'] ?? ('Camera ' . $c['camera_number']),
                'site_number'   => (int)$c['site_number'],
                'is_active'     => (int)($c['is_active'] ?? 0),
            ];
        }
        $cameras_admin_json = json_encode($camList, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    } catch (\Throwable $e) {
        // camera table may not exist in this DB yet — leave the picker empty.
        $cameras_admin_json = '[]';
    }
}

// Camera MAP-LAYER data (Phase 2). SECURITY: every row is filtered through
// can_view_camera_object() so cameras a user may not see are NEVER sent to the
// browser. Positions are 0-100% (same convention as room pins). No stream URLs
// here — feeds come later (Phase 4) and are separately gated.
$cameras_json = '[]';
try {
    $camMapRows = $pdo->query("SELECT camera_number, camera_name, camera_ip, site_number, map_x, map_y, map_level, map_icon_rotation, COALESCE(status, 1) AS cam_status, is_active, last_update, camera_url_sub, camera_url_main FROM camera ORDER BY site_number, camera_name")->fetchAll();
    $camMap = [];
    foreach ($camMapRows as $c) {
        // Deny-by-default object visibility (admins bypass inside the helper).
        if (!can_view_camera_object($c)) continue;
        // Only place cameras that actually have map coordinates.
        $mx = ($c['map_x'] !== null && $c['map_x'] !== '') ? (float)$c['map_x'] : null;
        $my = ($c['map_y'] !== null && $c['map_y'] !== '') ? (float)$c['map_y'] : null;
        $canFeed = can_view_camera_feed($c);
        // Online = active AND cam_status 0 (0 = healthy, matches NVR app convention).
        $online = ((int)($c['is_active'] ?? 0) === 1) && ((int)($c['cam_status'] ?? 1) === 0);
        // SECURITY: stream URLs are emitted ONLY when this user may view the feed.
        // Object-only users never receive a stream URL at all.
        $streamSub  = '';
        $streamMain = '';
        if ($canFeed) {
            // Append controls=false with the right separator (URL may already have a query).
            $sep = static fn(string $u) => (strpos($u, '?') !== false ? '&' : '?');
            $streamSub  = $c['camera_url_sub']  ? ($c['camera_url_sub']  . $sep($c['camera_url_sub'])  . 'controls=false') : '';
            $streamMain = $c['camera_url_main'] ? ($c['camera_url_main'] . $sep($c['camera_url_main']) . 'controls=false') : '';
        }
        $camMap[] = [
            'camera_number' => (string)$c['camera_number'],
            'camera_name'   => $c['camera_name'] ?? ('Camera ' . $c['camera_number']),
            'camera_ip'     => $c['camera_ip'] ?? '',
            'site_number'   => (int)$c['site_number'],
            'map_x'         => $mx,
            'map_y'         => $my,
            'map_level'     => $c['map_level'] ?: 'level-1',
            'rotation'      => (int)($c['map_icon_rotation'] ?? 0),
            'online'        => $online,
            'is_active'     => (int)($c['is_active'] ?? 0),
            'last_update'   => $c['last_update'] ?? null,
            'can_feed'      => $canFeed,
            'stream_sub'    => $streamSub,
            'stream_main'   => $streamMain,
        ];
    }
    $cameras_json = json_encode(array_values($camMap), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (\Throwable $e) {
    $cameras_json = '[]'; // camera table not reachable — no camera layer
}

// ---- Printers layer (assets pinned per site map) ----
try {
    $prRows = $pdo->query("SELECT printer_id, site_number, printer_name, location, web_interface, model, serial_number, mac_address, toner_id, barcode, notes, map_x, map_y, map_level, map_icon_rotation, is_active, " . (db_has_columns($pdo, 'printer', ['room_id']) ? "room_id, room_pos_x, room_pos_y" : "NULL AS room_id, NULL AS room_pos_x, NULL AS room_pos_y") . " FROM printer WHERE is_active = 1 ORDER BY site_number, printer_name")->fetchAll();
    $printers = [];
    foreach ($prRows as $p) {
        $psn = (int)$p['site_number'];
        // Per-user access: must be able to see this site AND have the printers layer here.
        if ($my_sites !== null && !in_array($psn, $my_sites, true)) continue;
        if (!can($pdo, 'printers', 'view', $psn)) continue;
        $mx = ($p['map_x'] !== null && $p['map_x'] !== '') ? (float)$p['map_x'] : null;
        $my = ($p['map_y'] !== null && $p['map_y'] !== '') ? (float)$p['map_y'] : null;
        $printers[] = [
            'printer_id'    => (int)$p['printer_id'],
            'site_number'   => (int)$p['site_number'],
            'printer_name'  => $p['printer_name'] ?? ('Printer ' . $p['printer_id']),
            'location'      => $p['location'] ?? '',
            'web_interface' => $p['web_interface'] ?? '',
            'model'         => $p['model'] ?? '',
            'serial_number' => $p['serial_number'] ?? '',
            'mac_address'   => $p['mac_address'] ?? '',
            'toner_id'      => $p['toner_id'] ?? '',
            'barcode'       => $p['barcode'] ?? '',
            'notes'         => $p['notes'] ?? '',
            'map_x'         => $mx,
            'map_y'         => $my,
            'map_level'     => $p['map_level'] ?: 'level-1',
            'rotation'      => (int)($p['map_icon_rotation'] ?? 0),
            'room_id'       => isset($p['room_id']) && $p['room_id'] !== null ? (int)$p['room_id'] : null,
            'room_pos_x'    => isset($p['room_pos_x']) && $p['room_pos_x'] !== null && $p['room_pos_x'] !== '' ? (float)$p['room_pos_x'] : null,
            'room_pos_y'    => isset($p['room_pos_y']) && $p['room_pos_y'] !== null && $p['room_pos_y'] !== '' ? (float)$p['room_pos_y'] : null,
        ];
    }
    $printers_json = json_encode(array_values($printers), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
} catch (\Throwable $e) {
    $printers_json = '[]'; // printer table not present yet — no printer layer
}

// ================================================================
// CSP
// ================================================================
