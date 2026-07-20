<?php
// ============================================================
// Site Manager — app.php
// The application shell: security headers + the full HTML document.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================

$nonce = bin2hex(random_bytes(16));

header("Content-Security-Policy: default-src 'self'; "
    ."script-src 'self' 'unsafe-eval' 'nonce-{$nonce}' https://cdn.jsdelivr.net; "
    ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
    ."font-src https://fonts.gstatic.com; "
    ."img-src 'self' data: blob: https://*.tile.openstreetmap.org https://tile.openstreetmap.org https://server.arcgisonline.com https://services.arcgisonline.com; "
    ."worker-src blob: https://cdn.jsdelivr.net; "
    ."connect-src 'self' https://cdn.jsdelivr.net https://nominatim.openstreetmap.org"
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
    <title>Site Manager &mdash; <?= esc((string)($current_user['display_name'] ?: $current_user['username'])) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js" nonce="<?= $nonce ?>"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" nonce="<?= $nonce ?>"></script>
    <!-- Leaflet: renders the OpenStreetMap "Map" view. Loaded from the same CDN
         the app already uses; the map tiles + Leaflet stylesheet are allowlisted
         in the CSP above. -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/leaflet@1.9.4/dist/leaflet.js" nonce="<?= $nonce ?>"></script>
    <script nonce="<?= $nonce ?>">
        (function(){
            try {
                var t = localStorage.getItem('sm_theme') || 'dark';
                document.documentElement.setAttribute('data-theme', t);
            } catch(e){}
        })();
        // Attach the per-session CSRF token to same-origin requests automatically.
        // Runs before Alpine (which is deferred), so every API call the app makes —
        // including FormData uploads — carries the header the server now requires on
        // POSTs, without each of the ~120 call sites needing to add it by hand.
        (function(){
            var meta = document.querySelector('meta[name="csrf-token"]');
            var token = meta ? meta.getAttribute('content') : '';
            var orig = window.fetch;
            window.fetch = function(input, init){
                init = init || {};
                var url = (typeof input === 'string') ? input : (input && input.url) || '';
                var sameOrigin = !/^[a-z]+:\/\//i.test(url) || url.indexOf(location.origin) === 0;
                if (sameOrigin && token){
                    var h = new Headers((init && init.headers) || (typeof input !== 'string' && input && input.headers) || {});
                    if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', token);
                    init.headers = h;
                }
                return orig.call(this, input, init);
            };
        })();
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css?v=<?= APP_VERSION ?>">
</head>
<body>
<div class="app-shell" x-data="siteManagerApp()" @keydown.escape.window="onEscape()">

    <!-- SIDEBAR -->
    <div class="sidebar-wrap" @mouseenter="sidebarHover=true" @mouseleave="sidebarHover=false">
        <aside class="sidebar">
            <div class="sidebar-logo">
                <div class="logo-icon">
                    <?php if ($logoSrc !== ''): ?>
                        <img src="<?= $logoSrc ?>" alt="Logo">
                    <?php else: ?>
                        <svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    <?php endif; ?>
                </div>
                <div class="logo-text">
                    <div class="logo-title"><?= esc(setting_get($pdo, 'site_brand_name', 'Site Manager') ?: 'Site Manager') ?></div>
                    <div class="logo-sub">DNCOE &bull; v<?= APP_VERSION ?></div>
                </div>
            </div>
            <nav class="sidebar-nav">
                <button class="nav-item" :class="{active:view==='home'}" @click="goHome()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    <span class="nav-label">Home</span>
                </button>
                <button class="nav-item" :class="{active:view==='geomap'}" @click="goGeoMap()" title="Sites on a real map, with floor plans overlaid">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                    <span class="nav-label">Map</span>
                </button>
                <!-- Sites: the pin icon + label still opens the Sites overview page
                     (master search + tile grid). The arrow on the right restores the
                     old sidebar dropdown — it expands an inline list of every site
                     for quick jumping, without leaving wherever you are. The caret
                     and the list only appear while the sidebar is expanded (hovered,
                     or always on touch), so the collapsed rail stays icon-only. -->
                <div class="sites-nav">
                    <button class="nav-item sites-nav-main" :class="{active:view==='dashboard'}" @click="goDashboard()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        <span class="nav-label">Sites</span>
                    </button>
                    <button class="sites-caret" x-show="sites.length" @click="sitesOpen=!sitesOpen" :title="sitesOpen ? 'Hide site list' : 'Show every site'" :aria-expanded="sitesOpen ? 'true' : 'false'">
                        <svg class="sites-caret-ic" :style="sitesOpen ? 'transform:rotate(90deg)' : ''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
                    </button>
                </div>
                <div class="sites-sublist" x-show="sitesOpen && (sidebarHover || !sidebarHoverCapable)" x-cloak>
                    <template x-for="site in sites" :key="site.id">
                        <button class="site-link" :class="{active:(view==='site'||view==='room') && currentSiteId===site.id}" @click="goSite(site.id, 'map')">
                            <span class="dot" :style="'background:'+site.color"></span>
                            <span class="site-link-name" x-text="site.name"></span>
                        </button>
                    </template>
                    <template x-if="sites.length===0">
                        <div class="sites-empty">No active sites.</div>
                    </template>
                </div>
            </nav>
            <!-- Admin tools: pinned to the bottom-left, visually separated from the
                 main nav above by a divider — moved out of the user dropdown per
                 request. Order (top-to-bottom): Audit log, Data editor,
                 Manage users, Settings — i.e. Settings sits at the very bottom. -->
            <nav class="sidebar-admin-nav">
                <button class="nav-item" x-show="can('audit','view')" :class="{active:view==='audit'}" @click="openAudit()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                    <span class="nav-label">Audit log</span>
                </button>
                <button class="nav-item" x-show="can('data_admin','view')" :class="{active:view==='data_editor'}" @click="openDataEditor()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                    <span class="nav-label">Data editor</span>
                </button>
                <button class="nav-item" x-show="can('manage_users','view')" :class="{active:view==='users'}" @click="openUsers()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="nav-label">Manage users</span>
                </button>
                <button class="nav-item" x-show="can('settings','view')" :class="{active:view==='settings'}" @click="openSettings()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span class="nav-label">Settings</span>
                </button>
            </nav>
        </aside>
    </div>

    <!-- MAIN -->
    <div class="main">

        <!-- TOPBAR -->
        <header class="topbar">
            <button class="mobile-nav-btn" @click="mobileNavOpen=true" aria-label="Open menu">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="crumbs">
                <button @click="goDashboard()">All Sites</button>
                <template x-if="view==='site' || view==='room'">
                    <span class="sep">/</span>
                </template>
                <template x-if="view==='site'">
                    <span class="now" x-text="currentSite?.name || ''"></span>
                </template>
                <template x-if="view==='room'">
                    <button @click="goSite(currentSiteId)" x-text="currentSite?.name || ''"></button>
                </template>
                <template x-if="view==='room'">
                    <span class="sep">/</span>
                </template>
                <template x-if="view==='room'">
                    <span class="now" x-text="currentRoom?.room_name || ''"></span>
                </template>
            </div>
            <div class="topbar-spacer"></div>
            <span class="topbar-stat" x-show="view==='site' && currentSite" x-text="(roomsForCurrentSite.length) + ' rooms'"></span>
            <span class="topbar-stat" x-show="view==='room' && currentRoom" x-text="(devicesForCurrentRoom.length) + ' devices'"></span>
            <button class="theme-toggle" @click="toggleTheme()" :title="theme==='dark' ? 'Switch to light' : 'Switch to dark'">
                <svg x-show="theme==='dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg x-show="theme==='light'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <!-- User menu -->
            <div class="user-menu" x-data="{open:false}" @click.outside="open=false">
                <button class="user-menu-btn" @click="open=!open">
                    <span class="user-avatar" x-show="!myAvatarUrl" x-text="(currentUser.name||'?').slice(0,1).toUpperCase()"></span>
                    <img class="user-avatar user-avatar-img" x-show="myAvatarUrl" :src="myAvatarUrl" alt="">
                    <span class="user-meta">
                        <span class="user-name" x-text="currentUser.name"></span>
                        <span class="user-role role-group" :title="(myGroups||[]).join(', ')"
                              x-text="(myGroups && myGroups.length) ? (myGroups.length===1 ? myGroups[0] : myGroups[0] + ' +' + (myGroups.length-1)) : 'no groups'"></span>
                    </span>
                    <span class="caret">▾</span>
                </button>
                <div class="user-dropdown" x-show="open" x-cloak x-transition>
                    <div class="ud-head">
                        <div class="ud-name" x-text="currentUser.name"></div>
                        <div class="ud-sub" x-text="'@'+currentUser.username"></div>
                    </div>
                    <button class="ud-item" @click="open=false; openProfile()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Profile settings
                    </button>
                    <div class="ud-divider"></div>
                    <button class="ud-item danger" @click="logout()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Sign out
                    </button>
                </div>
            </div>
        </header>

        <!-- MOBILE NAV (slide-over; the sidebar is hidden on phones) -->
        <div class="mobile-nav-backdrop" x-show="mobileNavOpen" x-transition.opacity @click="mobileNavOpen=false" x-cloak></div>
        <nav class="mobile-nav" x-show="mobileNavOpen" x-transition x-cloak>
            <div class="mobile-nav-head">
                <span>Site Manager</span>
                <button @click="mobileNavOpen=false" aria-label="Close menu">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <button class="nav-item" :class="{active:view==='home'}" @click="mobileNavOpen=false; goHome()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                <span class="nav-label">Home</span>
            </button>
            <button class="nav-item" :class="{active:view==='geomap'}" @click="mobileNavOpen=false; goGeoMap()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                <span class="nav-label">Map</span>
            </button>
            <button class="nav-item" :class="{active:view==='dashboard'}" @click="mobileNavOpen=false; goDashboard()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                <span class="nav-label">Sites</span>
            </button>
            <div class="mobile-nav-divider" x-show="can('audit','view') || can('manage_users','view') || can('settings','view')"></div>
            <button class="nav-item" x-show="can('audit','view')" :class="{active:view==='audit'}" @click="mobileNavOpen=false; openAudit()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
                <span class="nav-label">Audit log</span>
            </button>
            <button class="nav-item" x-show="can('manage_users','view')" :class="{active:view==='users'}" @click="mobileNavOpen=false; openUsers()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span class="nav-label">Manage users</span>
            </button>
            <button class="nav-item" x-show="can('settings','view')" :class="{active:view==='settings'}" @click="mobileNavOpen=false; openSettings()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span class="nav-label">Settings</span>
            </button>
            <!-- Account block: pinned to the drawer's bottom (standard mobile
                 drawer pattern). Replaces the topbar dropdown on phones. -->
            <div class="mobile-nav-account">
                <button class="mna-user" @click="mobileNavOpen=false; openProfile()" title="Profile settings">
                    <span class="user-avatar" x-show="!myAvatarUrl" x-text="(currentUser.name||'?').slice(0,1).toUpperCase()"></span>
                    <img class="user-avatar user-avatar-img" x-show="myAvatarUrl" :src="myAvatarUrl" alt="">
                    <span class="mna-meta">
                        <span class="mna-name" x-text="currentUser.name"></span>
                        <span class="mna-sub" x-text="(myGroups && myGroups.length) ? (myGroups.length===1 ? myGroups[0] : myGroups[0] + ' +' + (myGroups.length-1)) : 'no groups'"></span>
                    </span>
                    <svg class="mna-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                </button>
                <button class="nav-item mna-signout" @click="logout()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    <span class="nav-label">Sign out</span>
                </button>
            </div>
        </nav>

        <!-- ADD DEVICES: choose one-by-hand or a CSV batch -->
        <div class="modal-backdrop" x-show="deviceAdd.open" x-transition.opacity @click.self="deviceAdd.open=false" x-cloak>
            <div class="modal-card" style="max-width:470px">
                <div class="modal-head">
                    <h3>Add devices</h3>
                    <button class="modal-x" @click="deviceAdd.open=false">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="modal-help">Adding to <strong x-text="currentRoom ? roomNumberLabel(currentRoom) : 'this room'"></strong>.</p>
                    <button class="add-choice" @click="deviceAddSingle()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <span class="ac-txt"><span class="ac-t">Add one device</span><span class="ac-s">Fill in the details yourself.</span></span>
                    </button>
                    <button class="add-choice" @click="openDeviceImport()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                        <span class="ac-txt"><span class="ac-t">Import from a CSV</span><span class="ac-s">Add a batch at once. Grab the template if you need it.</span></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- DEVICE CSV IMPORT: template, file pick, preview, then import -->
        <div class="modal-backdrop" x-show="deviceImport.open" x-transition.opacity @click.self="if(!deviceImport.busy) deviceImport.open=false" x-cloak>
            <div class="modal-card" style="max-width:720px">
                <div class="modal-head">
                    <h3>Import devices from CSV</h3>
                    <button class="modal-x" @click="deviceImport.open=false" :disabled="deviceImport.busy">&times;</button>
                </div>
                <div class="modal-body">
                    <p class="modal-help">Devices are added to <strong x-text="currentRoom ? roomNumberLabel(currentRoom) : 'this room'"></strong>. Only <strong>device_name</strong> is required; unknown device types fall back to “Other”.</p>
                    <div class="di-actions">
                        <button class="btn" @click="downloadDeviceTemplate()">Download template</button>
                        <label class="btn">
                            Choose CSV…
                            <input type="file" accept=".csv,text/csv" @change="onDeviceCsv($event)" style="display:none">
                        </label>
                        <span class="di-file" x-show="deviceImport.file" x-text="deviceImport.file"></span>
                    </div>
                    <div class="upload-status err" x-show="deviceImport.error" x-text="deviceImport.error"></div>
                    <template x-if="deviceImport.rows.length">
                        <div>
                            <div class="di-summary">
                                <span x-text="deviceImportReady + ' ready'"></span>
                                <span x-show="deviceImport.rows.length - deviceImportReady" class="di-bad"
                                      x-text="(deviceImport.rows.length - deviceImportReady) + ' need attention'"></span>
                            </div>
                            <div class="di-rows">
                                <template x-for="(r, i) in deviceImport.rows" :key="'di'+i">
                                    <div class="di-row" :class="{bad: r.error}">
                                        <span class="di-name" x-text="r.device_name || '(no name)'"></span>
                                        <span class="di-type" x-text="r.device_type_key"></span>
                                        <span class="di-meta" x-text="[r.asset_tag, r.model, r.ip_address].filter(Boolean).join(' · ')"></span>
                                        <span class="di-note" x-show="r.error" x-text="r.error"></span>
                                        <span class="di-note" x-show="!r.error && r.typeUnknown" x-text="'Unknown type “' + r.typeRaw + '” → Other'"></span>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="modal-foot">
                    <button class="btn" @click="deviceImport.open=false" :disabled="deviceImport.busy">Cancel</button>
                    <button class="btn save" @click="runDeviceImport()" :disabled="deviceImport.busy || !deviceImportReady"
                            x-text="deviceImport.busy ? ('Importing… ' + deviceImport.done + '/' + deviceImportReady) : ('Import ' + deviceImportReady + ' device' + (deviceImportReady===1?'':'s'))"></button>
                </div>
            </div>
        </div>

        <!-- BARCODE SCANNER (camera preview + native decode; closes on hit) -->
        <div class="scan-overlay" x-show="scanner.open" x-transition.opacity @click.self="closeScanner()" x-cloak>
            <div class="scan-card">
                <div class="scan-head">
                    <span>Scan a barcode</span>
                    <button @click="closeScanner()" aria-label="Close scanner">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
                <div class="scan-video-wrap">
                    <video x-ref="scanVideo" playsinline muted></video>
                    <div class="scan-frame"></div>
                </div>
                <div class="scan-hint" x-text="scanner.hint"></div>
            </div>
        </div>

        <!-- CONTENT -->
        <main class="content">

            <!-- ============ MAP (OpenStreetMap + floor-plan overlays) ============ -->
            <div x-show="view==='geomap'" x-cloak>
                <div class="geo-head">
                    <div>
                        <h1 class="page-title">Map</h1>
                        <p class="page-subtitle">Your sites on the real map, with floor plans overlaid at their true location.</p>
                    </div>
                    <div class="geo-head-actions">
                        <div class="geo-base-toggle">
                            <button :class="{active: geo.base==='satellite'}" @click="geoSetBase('satellite')">Satellite</button>
                            <button :class="{active: geo.base==='street'}" @click="geoSetBase('street')">Street</button>
                        </div>
                        <button class="btn edit-only" :class="{primary: !geo.placing}" x-show="!geo.placing && can('base','edit')" @click="geoStartPlacing()">Place a site</button>
                        <button class="btn edit-only" x-show="geo.placing && can('base','edit')" @click="geoCancelPlacing()">Done placing</button>
                    </div>
                </div>

                <!-- Warning if Leaflet failed to load (offline / CDN blocked). -->
                <div class="empty-card" x-show="geo.libFailed" style="margin-bottom:12px">
                    The map library couldn't load. Check the network connection to the map CDN, then reload.
                </div>

                <div class="geo-wrap">
                    <div id="geoMap"></div>

                    <!-- Live location: toggles GPS tracking; blue dot follows you. -->
                    <button class="geo-locate" :class="{on: geo.gps.watching}" @click="gpsToggle()" :title="geo.gps.watching ? 'Stop showing my location' : 'Show my location'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/><circle cx="12" cy="12" r="8"/></svg>
                    </button>

                    <!-- Search: jump to one of your sites by name, or look up an
                         address (useful when placing a new site). -->
                    <div class="geo-search">
                        <div class="geo-search-box">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" x-model="geo.searchQuery" @keydown.enter="geoSearchEnter()" @keydown.escape="geo.searchQuery=''" placeholder="Search sites or an address…">
                            <button class="geo-search-clear" x-show="geo.searchQuery.length" @click="geo.searchQuery=''" title="Clear">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <div class="geo-search-results" x-show="geo.searchQuery.length" x-cloak>
                            <template x-for="s in geoSiteMatches" :key="'gsr-'+s.id">
                                <button class="geo-search-row" @click="geoFlyToSite(s)">
                                    <span class="dot" :style="'background:'+s.color"></span>
                                    <span class="geo-search-name" x-text="s.name"></span>
                                    <span class="geo-search-tag" x-show="s.lat===null || s.lng===null">not placed</span>
                                </button>
                            </template>
                            <div class="geo-search-empty" x-show="geoSiteMatches.length===0">No matching site</div>
                            <button class="geo-search-row geo-search-addr" @click="geoSearchAddress()" :disabled="geo.searching">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <span x-text="geo.searching ? 'Searching…' : ('Look up address: “' + geo.searchQuery + '”')"></span>
                            </button>
                        </div>
                    </div>

                    <!-- Placement panel: pick a site, click the map to drop it, size it, save. -->
                    <div class="geo-panel" x-show="geo.placing" x-cloak>
                        <div class="geo-panel-title">Place a site</div>
                        <label class="geo-field">
                            <span>Site</span>
                            <select class="level-select" x-model.number="geo.editSiteId" @change="geoSelectEditSite()">
                                <option value="0" disabled>Choose a site…</option>
                                <template x-for="s in sites" :key="'geo-'+s.id">
                                    <option :value="s.id" x-text="s.name + (s.lat!==null && s.lng!==null ? '  ✓' : '')"></option>
                                </template>
                            </select>
                        </label>
                        <p class="geo-hint" x-show="geo.editSiteId" x-text="geo.hasPoint ? 'Drag the marker to fine-tune, or click elsewhere to move it.' : 'Click on the map where this site is.'"></p>
                        <label class="geo-field" x-show="geo.editSiteId && geo.hasPoint">
                            <span>Overlay size: <b x-text="Math.round(geo.meters) + ' m'"></b> wide</span>
                            <input type="range" min="20" max="1200" step="5" x-model.number="geo.meters" @input="geoUpdatePreview()">
                        </label>
                        <label class="geo-field geo-check" x-show="geo.editSiteId && geo.hasPoint">
                            <input type="checkbox" x-model="geo.showOverlayWhilePlacing" @change="geoUpdatePreview()">
                            <span>Show floor-plan overlay</span>
                        </label>
                        <div class="geo-panel-actions" x-show="geo.editSiteId">
                            <button class="btn primary" :disabled="!geo.hasPoint || geo.saving" @click="geoSavePlacement()" x-text="geo.saving ? 'Saving…' : 'Save location'"></button>
                            <button class="btn danger-ghost" x-show="geo.hasPoint" :disabled="geo.saving" @click="geoClearPlacement()">Remove</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ CAMERA WALL (live feeds) ============ -->
            <div x-show="view==='cameras'" x-cloak>
                <div class="wall-head">
                    <div>
                        <h1 class="page-title">Camera Wall</h1>
                        <p class="dash-subtitle">Live view of cameras you have feed access to</p>
                    </div>
                    <div class="wall-controls">
                        <select class="level-select" :value="wall.site" @change="onWallSiteChange($event.target.value)" title="Pick a site to open its camera view">
                            <option value="all">All sites</option>
                            <template x-for="s in wallSites" :key="s.id">
                                <option :value="s.id" x-text="s.name"></option>
                            </template>
                        </select>
                        <div class="wall-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="ws-icon"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" x-model="wall.q" placeholder="Search cameras…" title="Filter by camera name, number, IP, or site">
                            <button class="ws-clear" x-show="wall.q" @click="wall.q=''" title="Clear">×</button>
                        </div>
                        <div class="wall-cols wall-size-slider">
                            <span class="wc-label">Tile size</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wc-ic" title="Larger"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                            <input type="range" min="1" max="8" step="1" :value="wall.cols" @input="setWallCols($event.target.value)" style="width:120px" title="Drag to resize the camera tiles">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wc-ic" title="Smaller"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>
                            <span class="wc-readout" x-text="wall.cols + (wall.cols===1?' col':' cols')"></span>
                        </div>
                        <div class="wall-cols" title="Maximum cameras streaming at once — lower this if the wall lags">
                            <span class="wc-label">Max streams</span>
                            <select class="level-select" :value="wall.maxStreams" @change="setWallMax($event.target.value)">
                                <option value="8">8</option>
                                <option value="16">16</option>
                                <option value="32">32</option>
                                <option value="48">48</option>
                                <option value="64">64</option>
                                <option value="96">96</option>
                                <option value="128">128</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="wall-grid" :style="'grid-template-columns:repeat('+wall.cols+',1fr)'" x-show="feedCameras.length">
                    <template x-for="g in wallGroups" :key="'wg-'+g.site_id">
                        <div style="display:contents">
                            <div class="wall-site-head" x-show="wall.site==='all'" :style="'--site-color:'+g.site_color">
                                    <span class="wsh-dot"></span>
                                    <span class="wsh-name" x-text="g.site_name"></span>
                                    <span class="wsh-count" x-text="g.cams.length + ' camera' + (g.cams.length===1?'':'s')"></span>
                                </div>
                                <template x-for="cam in g.cams" :key="'wall-'+cam.camera_number">
                                    <div class="wall-tile" @click="openCameraFeed(cam)">
                                        <div class="wall-video" x-init="_observeWallTile($el, cam.camera_number)">
                                            <template x-if="cameraIsOnline(cam) && cam.stream_sub && streamActive(cam.camera_number)">
                                                <iframe :src="cam.stream_sub" loading="lazy" style="width:100%;height:100%;border:none;display:block;pointer-events:none"></iframe>
                                            </template>
                                            <template x-if="!(cameraIsOnline(cam) && cam.stream_sub && streamActive(cam.camera_number))">
                                                <div class="wall-placeholder" :class="{offline:!cameraIsOnline(cam)}">
                                                    <template x-if="!cameraIsOnline(cam)"><span class="wall-offline">OFFLINE</span></template>
                                                    <template x-if="cameraIsOnline(cam) && !cam.stream_sub"><span class="wall-nourl">No stream URL</span></template>
                                                    <template x-if="cameraIsOnline(cam) && cam.stream_sub">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;color:var(--text-dim)"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                                    </template>
                                                </div>
                                            </template>
                                            <span class="wall-dot" :class="cameraIsOnline(cam)?'online':'offline'"></span>
                                            <div class="wall-hover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></div>
                                        </div>
                                        <div class="wall-cap">
                                            <span class="wall-cap-name" x-text="cam.camera_name"></span>
                                            <span class="wall-cap-ip mono" x-show="cam.camera_ip" x-text="cam.camera_ip"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                    </template>
                </div>

                <div class="empty-card" x-show="!feedCameras.length">
                    <span x-show="wall.q">No cameras match "<span x-text="wall.q"></span>".</span>
                    <span x-show="!wall.q && wall.site==='all'">You don't have live-feed access to any cameras yet.</span>
                    <span x-show="!wall.q && wall.site!=='all'">No feed-accessible cameras at this site.</span>
                </div>
            </div>

            <!-- ============ DASHBOARD (all sites) ============ -->
            <div x-show="view==='dashboard' || view==='home'" x-cloak>
                <template x-if="view==='home'">
                    <div>
                        <h1 class="page-title">Home</h1>
                        <p class="page-subtitle">Search everything — rooms, people, devices, cameras — or jump into a module.</p>
                    </div>
                </template>
                <template x-if="view==='dashboard'">
                    <div>
                        <h1 class="page-title">All Sites</h1>
                        <p class="page-subtitle">Search across every site, or click a site to open its floor plan.</p>
                    </div>
                </template>

                <!-- Global universal search -->
                <div class="global-search">
                    <svg class="gs-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" x-model="globalSearch.q" @input="runGlobalSearch()"
                           @keydown.escape="clearGlobalSearch()"
                           placeholder="Search all sites — try “Westview Room 200”, a name, extension, asset tag, barcode…">
                    <button class="gs-clear" x-show="globalSearch.q" @click="clearGlobalSearch()" title="Clear">×</button>
                    <button class="gs-scan" @click="openScanner('global')" title="Scan a barcode with the camera"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="8" x2="7" y2="16"/><line x1="10.5" y1="8" x2="10.5" y2="16"/><line x1="14" y1="8" x2="14" y2="16"/><line x1="17" y1="8" x2="17" y2="16"/></svg></button>
                </div>

                <!-- Grouped results (replace the grid while searching) -->
                <div x-show="globalSearch.q" x-cloak>
                    <div class="gs-summary" x-show="globalSearch.total" x-text="globalSearch.total + ' match' + (globalSearch.total===1?'':'es') + ' across ' + globalSearch.groups.length + ' site' + (globalSearch.groups.length===1?'':'s')"></div>
                    <div class="gs-empty" x-show="!globalSearch.groups.length">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <span x-text="'No matches for “' + globalSearch.q + '”'"></span>
                        <span class="gs-empty-hint">Try a room number, a person's name, an extension, or an asset tag — or start with a site name, e.g. “<span x-text="(sites[0] && sites[0].name) || 'Site'"></span> Room 200”.</span>
                    </div>
                    <template x-for="g in globalSearch.groups" :key="g.site_id">
                        <div class="gs-group">
                            <div class="gs-group-head" :style="'--site-color:'+g.site_color">
                                <span class="gs-group-dot"></span>
                                <span class="gs-group-name" x-text="g.site_name"></span>
                                <span class="gs-group-count" x-text="(g.rooms.length + g.cameras.length) + ' result' + ((g.rooms.length + g.cameras.length)===1?'':'s')"></span>
                            </div>
                            <template x-for="r in g.rooms" :key="'room-'+r.room.room_id">
                                <div class="gs-result">
                                    <div class="ms-rdot" :style="'background:'+(roomColor(r.room)||'var(--accent)')"></div>
                                    <div class="ms-rmain">
                                        <div class="ms-rtitle">
                                            <span x-text="r.room.room_name || ('Room ' + (r.room.room_number||''))"></span>
                                            <span class="ms-rnum" x-show="roomNumberLabel(r.room)" x-text="roomNumberLabel(r.room)"></span>
                                        </div>
                                        <div class="ms-rsub">
                                            <span x-text="formatRoomType(r.room.room_type)"></span>
                                            <template x-if="_matchReason(r.room, _norm(globalSearch.q))">
                                                <span class="ms-reason">
                                                    <span class="ms-reason-ic" x-html="reasonIcon(_matchReason(r.room, _norm(globalSearch.q)).icon)"></span>
                                                    <span x-text="_matchReason(r.room, _norm(globalSearch.q)).text"></span>
                                                </span>
                                            </template>
                                        </div>
                                    </div>
                                    <div class="gs-result-actions">
                                        <button class="btn tiny" @click="globalGoIntoRoom(r.room)">Open room</button>
                                        <button class="btn tiny primary" @click="globalGoToMap(r.room)">Show on map</button>
                                    </div>
                                </div>
                            </template>
                            <!-- Camera matches for this site -->
                            <template x-for="c in g.cameras" :key="'cam-'+c.item.camera_number">
                                <div class="gs-result">
                                    <div class="ms-cam-ic" :class="{online:cameraIsOnline(c.item)}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                    </div>
                                    <div class="ms-rmain">
                                        <div class="ms-rtitle">
                                            <span x-text="c.item.camera_name"></span>
                                            <span class="ms-rnum">#<span x-text="c.item.camera_number"></span></span>
                                        </div>
                                        <div class="ms-rsub">
                                            <span class="ms-cam-tag">Camera</span>
                                            <span x-show="c.item.camera_ip" class="mono" x-text="c.item.camera_ip"></span>
                                            <span class="ms-cam-state" :class="{on:cameraIsOnline(c.item)}" x-text="cameraStatusText(c.item)"></span>
                                        </div>
                                    </div>
                                    <div class="gs-result-actions">
                                        <button class="btn tiny primary" @click="globalGoToCamera(c.item)">Show on map</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- HOME: module launcher + quick stats (hidden while searching) -->
                <div x-show="view==='home' && !globalSearch.q">
                    <div class="home-stats">
                        <div class="home-stat">
                            <div class="hs-num" x-text="sites.length"></div>
                            <div class="hs-label">Sites</div>
                        </div>
                        <div class="home-stat">
                            <div class="hs-num" x-text="Object.values(siteCounts).reduce((a,c)=>a+(c.rooms||0),0)"></div>
                            <div class="hs-label">Rooms</div>
                        </div>
                        <div class="home-stat">
                            <div class="hs-num"><span x-text="homeDash.placed"></span><span class="hs-pct" x-text="' · ' + homeDash.placedPct + '%'"></span></div>
                            <div class="hs-label">Rooms Pinned</div>
                        </div>
                        <div class="home-stat">
                            <div class="hs-num" x-text="Object.values(siteCounts).reduce((a,c)=>a+(c.devices||0),0)"></div>
                            <div class="hs-label">Devices</div>
                        </div>
                        <div class="home-stat" x-show="cameras.length">
                            <div class="hs-num" x-text="cameras.length"></div>
                            <div class="hs-label">Cameras</div>
                        </div>
                        <div class="home-stat warn" x-show="cameras.length && cameras.filter(c=>!cameraIsOnline(c)).length">
                            <div class="hs-num" x-text="cameras.filter(c=>!cameraIsOnline(c)).length"></div>
                            <div class="hs-label">Cams offline</div>
                        </div>
                    </div>

                    <!-- Dashboard (v0.47.1): camera status only, by request — the
                         rooms/devices columns, type mix, and mapping progress cards
                         are gone (homeDash still computes the Rooms Pinned stat). -->
                    <div class="hd-grid">
                        <div class="hd-card" x-show="homeDash.cams.total">
                            <div class="hd-title">Camera status <span class="hd-title-sub">click a site to open its cameras</span></div>
                            <div class="hd-cam-hero">
                                <svg viewBox="0 0 90 90" class="hd-donut" :class="{'has-off': homeDash.cams.offline > 0}" aria-hidden="true">
                                    <circle cx="45" cy="45" r="34" class="hd-donut-track"/>
                                    <circle cx="45" cy="45" r="34" class="hd-donut-arc" :stroke-dasharray="homeDash.cams.dash" transform="rotate(-90 45 45)"/>
                                    <text x="45" y="43" class="hd-donut-num" text-anchor="middle" x-text="homeDash.cams.pct + '%'"></text>
                                    <text x="45" y="58" class="hd-donut-sub" text-anchor="middle">online</text>
                                </svg>
                                <div class="hd-cam-summary">
                                    <div class="hd-cam-big"><span x-text="homeDash.cams.online"></span><small x-text="'of ' + homeDash.cams.total + ' cameras online'"></small></div>
                                    <span class="hd-cam-state ok" x-show="homeDash.cams.offline === 0">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px"><polyline points="20 6 9 17 4 12"/></svg>
                                        All cameras online
                                    </span>
                                    <span class="hd-cam-state bad" x-show="homeDash.cams.offline > 0"
                                          x-text="homeDash.cams.offline + (homeDash.cams.offline === 1 ? ' camera offline' : ' cameras offline')"></span>
                                </div>
                            </div>
                            <div class="hd-cam-grid">
                                <template x-for="s in homeDash.cams.perSite" :key="'hdc-'+s.id">
                                    <div class="hd-cam-tile" :class="{bad: s.offline > 0}" @click="goSite(s.id, 'cameras')" role="button" :title="'Open '+s.name+' cameras'">
                                        <div class="hd-ct-top">
                                            <span class="hd-ct-name" x-text="s.name"></span>
                                            <span class="hd-ct-count" x-text="s.offline > 0 ? (s.offline + ' offline') : (s.online + '/' + s.total)"></span>
                                        </div>
                                        <div class="hd-ct-bar">
                                            <i class="hd-ct-on" :style="'width:' + Math.round((s.online / s.total) * 100) + '%'"></i>
                                            <i class="hd-ct-off" :style="'width:' + Math.round((s.offline / s.total) * 100) + '%'"></i>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>


                    <div class="home-modules">
                        <div class="home-module" @click="goDashboard()">
                            <div class="hm-ic teal">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            </div>
                            <div class="hm-text">
                                <div class="hm-title">Sites &amp; Maps</div>
                                <div class="hm-sub">Floor plans, rooms, devices, and people across every site.</div>
                            </div>
                            <svg class="hm-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                        </div>
                        <!-- Camera Wall / Camera Home entry points removed (v0.39.2) at
                             Casey's request — the global all-sites camera views weren't
                             right for this app. Per-site cameras (the Cameras tab inside a
                             site) are kept. The 'cameras' view code and its #cameras hash
                             route still exist untouched, so restoring is just re-adding
                             these nav entries. -->
                    </div>
                </div>

                <template x-if="sites.length===0">
                    <div class="empty-card" x-show="!globalSearch.q && view==='dashboard'">No sites configured yet.</div>
                </template>

                <div class="sites-grid" x-show="view==='dashboard' && sites.length && !globalSearch.q">
                    <template x-for="site in sites" :key="site.id">
                        <div class="site-card" :style="'--site-color:'+site.color" @click="goSite(site.id, 'map')">
                            <div class="site-card-top">
                                <div class="site-card-ic" :style="'background:'+site.color+'1f;color:'+site.color">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                </div>
                                <svg class="site-card-arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                            </div>
                            <div class="site-card-name" x-text="site.name"></div>
                            <div class="site-card-stats">
                                <div class="scs">
                                    <div class="scs-num" x-text="siteCounts[site.id]?.rooms || 0"></div>
                                    <div class="scs-label">Rooms</div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ============ SITE MAP (one site) ============ -->
            <div x-show="view==='site'" x-cloak x-init="$watch('view', v => { if(v==='site') { loadSvgForCurrentSite(); applyMapDefaultZoom(); _syncLevelSelectDom(); } }); $watch('currentSiteId', () => { loadSvgForCurrentSite(); applyMapDefaultZoom(); _syncLevelSelectDom(); }); $watch('selectedLevel', () => { loadSvgForCurrentSite(); applyMapDefaultZoom(); _syncLevelSelectDom(); })">

                <!-- In-site module switcher: same site, different lens -->
                <div class="site-tabs" x-show="siteFeedCameras.length">
                    <button class="site-tab" :class="{active:siteTab==='map'}" @click="setSiteTab('map'); $nextTick(()=>_applyZoomForCurrentMap())">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Map
                    </button>
                    <button class="site-tab" :class="{active:siteTab==='cameras'}" @click="setSiteTab('cameras')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                        Cameras
                        <span class="cam-count-badge" x-text="siteFeedCameras.length"></span>
                    </button>
                </div>

                <!-- SITE: CAMERAS TAB (this site's wall) -->
                <div x-show="siteTab==='cameras'" x-cloak>
                    <div class="site-cam-controls" x-show="siteFeedCameras.length">
                        <div class="wall-cols wall-size-slider">
                            <span class="wc-label">Tile size</span>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wc-ic" title="Larger"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>
                            <input type="range" min="1" max="8" step="1" :value="wall.cols" @input="setWallCols($event.target.value)" style="width:120px" title="Drag to resize the camera tiles">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="wc-ic" title="Smaller"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>
                            <span class="wc-readout" x-text="wall.cols + (wall.cols===1?' col':' cols')"></span>
                        </div>
                        <div class="wall-cols" title="Maximum cameras streaming at once — lower this if it lags">
                            <span class="wc-label">Max streams</span>
                            <select class="level-select" :value="wall.maxStreams" @change="setWallMax($event.target.value)">
                                <option value="8">8</option>
                                <option value="16">16</option>
                                <option value="32">32</option>
                                <option value="48">48</option>
                                <option value="64">64</option>
                                <option value="96">96</option>
                                <option value="128">128</option>
                            </select>
                        </div>
                    </div>
                    <div class="wall-grid" :style="'grid-template-columns:repeat('+wall.cols+',1fr)'" x-show="siteFeedCameras.length">
                        <template x-for="cam in siteFeedCameras" :key="'sw-'+cam.camera_number">
                            <div class="wall-tile" @click="openCameraFeed(cam)">
                                <div class="wall-video" x-init="_observeWallTile($el, cam.camera_number)">
                                    <template x-if="cameraIsOnline(cam) && cam.stream_sub && streamActive(cam.camera_number)">
                                        <iframe :src="cam.stream_sub" loading="lazy" style="width:100%;height:100%;border:none;display:block;pointer-events:none"></iframe>
                                    </template>
                                    <template x-if="!(cameraIsOnline(cam) && cam.stream_sub && streamActive(cam.camera_number))">
                                        <div class="wall-placeholder" :class="{offline:!cameraIsOnline(cam)}">
                                            <template x-if="!cameraIsOnline(cam)"><span class="wall-offline">OFFLINE</span></template>
                                            <template x-if="cameraIsOnline(cam) && !cam.stream_sub"><span class="wall-nourl">No stream URL</span></template>
                                            <template x-if="cameraIsOnline(cam) && cam.stream_sub">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;color:var(--text-dim)"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                            </template>
                                        </div>
                                    </template>
                                    <span class="wall-dot" :class="cameraIsOnline(cam)?'online':'offline'"></span>
                                    <div class="wall-hover"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg></div>
                                </div>
                                <div class="wall-cap">
                                    <span class="wall-cap-name" x-text="cam.camera_name"></span>
                                    <span class="wall-cap-ip mono" x-show="cam.camera_ip" x-text="cam.camera_ip"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="siteTab==='map'">
                <div class="map-toolbar">
                    <div class="map-tool-group">
                        <template x-if="currentSite && currentSite.maps && currentSite.maps.length>1">
                            <div class="map-switch" title="Switch between this site's maps">
                                <svg class="map-switch-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                                <select class="level-select" x-ref="levelSelect" :value="selectedLevel" @change="selectedLevel=$event.target.value; loadSvgForCurrentSite(); applyMapDefaultZoom()">
                                    <!-- map_key is only unique WITHIN a site (e.g. many sites each have
                                         their own "level-1"), so the :key must include the site id — otherwise
                                         switching to a different site whose map happens to share a key string
                                         with the previous site's map lets Alpine reuse the old <option> DOM
                                         node instead of replacing it, leaving a stale name/selection behind. -->
                                    <template x-for="m in currentSite.maps" :key="'m-'+currentSiteId+'-'+m.key">
                                        <option :value="m.key" x-text="m.name"></option>
                                    </template>
                                </select>
                            </div>
                        </template>
                        <button class="btn" @click="zoom(-0.2)" title="Zoom out">−</button>
                        <span class="zoom-label phone-hide" x-text="Math.round(mapZoom*100)+'%'"></span>
                        <button class="btn" @click="zoom(0.2)" title="Zoom in">+</button>
                        <button class="btn phone-hide" @click="zoomReset()">Reset</button>
                    </div>

                    <!-- Site picker: hop to another site's map without leaving the map view -->
                    <div class="map-tool-group phone-hide" x-show="currentSite && sites.length > 1">
                        <select class="level-select site-picker" :value="currentSiteId" @change="goSite(Number($event.target.value))" title="Switch site">
                            <template x-for="s in sites" :key="'sp-'+s.id">
                                <option :value="s.id" x-text="s.name" :selected="s.id===currentSiteId"></option>
                            </template>
                        </select>
                    </div>

                    <!-- Universal room search -->
                    <div class="map-tool-group map-search-group" x-show="currentSite" @click.outside="mapSearch.open=false; mapSearch.choice=null">
                        <div class="map-search">
                            <svg class="ms-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" x-model="mapSearch.q" @input="runMapSearch()" @focus="runMapSearch()"
                                   @keydown.escape="clearMapSearch()" @keydown.enter="pickFirstResult()"
                                   placeholder="Search room, person, extension, asset tag, barcode…">
                            <button class="ms-clear" x-show="mapSearch.q" @click="clearMapSearch()" title="Clear">×</button>
                            <button class="ms-scan" @click="openScanner('map')" title="Scan a barcode with the camera"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/><line x1="7" y1="8" x2="7" y2="16"/><line x1="10.5" y1="8" x2="10.5" y2="16"/><line x1="14" y1="8" x2="14" y2="16"/><line x1="17" y1="8" x2="17" y2="16"/></svg></button>

                            <!-- results dropdown -->
                            <div class="ms-dropdown" x-show="mapSearch.open && mapSearch.q" x-transition.opacity x-cloak>
                                <template x-if="mapSearch.results.length">
                                    <div class="ms-results">
                                        <template x-for="r in mapSearch.results" :key="r.type+'-'+(r.type==='camera' ? r.item.camera_number : r.room.room_id)">
                                            <div>
                                                <!-- ROOM result -->
                                                <template x-if="r.type==='room'">
                                                    <div>
                                                        <div class="ms-result" @click="pickSearchResult(r.room)">
                                                            <div class="ms-rdot" :style="'background:'+(roomColor(r.room)||'var(--accent)')"></div>
                                                            <div class="ms-rmain">
                                                                <div class="ms-rtitle">
                                                                    <span x-text="r.room.room_name || ('Room ' + (r.room.room_number||''))"></span>
                                                                    <span class="ms-rnum" x-show="roomNumberLabel(r.room)" x-text="roomNumberLabel(r.room)"></span>
                                                                </div>
                                                                <div class="ms-rsub">
                                                                    <span x-text="formatRoomType(r.room.room_type)"></span>
                                                                    <template x-if="mapNameForRoom(r.room)">
                                                                        <span class="ms-map-tag" x-text="mapNameForRoom(r.room)"></span>
                                                                    </template>
                                                                    <template x-if="_matchReason(r.room, _norm(mapSearch.q))">
                                                                        <span class="ms-reason">
                                                                            <span class="ms-reason-ic" x-html="reasonIcon(_matchReason(r.room, _norm(mapSearch.q)).icon)"></span>
                                                                            <span x-text="_matchReason(r.room, _norm(mapSearch.q)).text"></span>
                                                                        </span>
                                                                    </template>
                                                                </div>
                                                            </div>
                                                            <svg class="ms-rarrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                                                        </div>
                                                    </div>
                                                </template>
                                                <!-- CAMERA result -->
                                                <template x-if="r.type==='camera'">
                                                    <div class="ms-result ms-result-cam" @click="focusCameraOnMap(r.item)">
                                                        <div class="ms-cam-ic" :class="{online:cameraIsOnline(r.item)}">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                                        </div>
                                                        <div class="ms-rmain">
                                                            <div class="ms-rtitle">
                                                                <span x-text="r.item.camera_name"></span>
                                                                <span class="ms-rnum">#<span x-text="r.item.camera_number"></span></span>
                                                            </div>
                                                            <div class="ms-rsub">
                                                                <span class="ms-cam-tag">Camera</span>
                                                                <span x-show="r.item.camera_ip" class="mono" x-text="r.item.camera_ip"></span>
                                                                <span class="ms-cam-state" :class="{on:cameraIsOnline(r.item)}" x-text="cameraStatusText(r.item)"></span>
                                                            </div>
                                                        </div>
                                                        <svg class="ms-rarrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <div class="ms-empty" x-show="!mapSearch.results.length" x-text="'No matches for “' + mapSearch.q + '”'"></div>
                            </div>
                        </div>
                    </div>
                    <div class="map-toolbar-spacer"></div>
                    <!-- View menu: quick show/hide toggles (everyone) -->
                    <div class="map-tool-group" x-show="currentSite" x-data="{open:false}" @click.outside="open=false">
                        <button class="btn menu-btn" @click="open=!open" :class="{active:open}">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mb-ic"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            View
                            <svg class="mb-caret" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </button>
                        <div class="tool-menu" x-show="open" x-transition x-cloak>
                            <button class="tool-menu-item" x-show="cameraCountForSite > 0" @click="showCameras=!showCameras; if(!showCameras) selectedCamera=null">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                <span x-text="showCameras ? 'Hide cameras' : 'Show cameras'"></span>
                                <span class="cam-count-badge" x-text="cameraCountForSite"></span>
                                <svg class="tmi-check" x-show="showCameras" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                            <button class="tool-menu-item" @click="showPins=!showPins">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <span x-text="showPins ? 'Hide room pins' : 'Show room pins'"></span>
                                <svg class="tmi-check" x-show="showPins" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                            <button class="tool-menu-item" x-show="printersEnabled && printerCountForSite > 0" @click="showPrinters=!showPrinters">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                <span x-text="showPrinters ? 'Hide printers' : 'Show printers'"></span>
                                <span class="cam-count-badge" x-text="printerCountForSite"></span>
                                <svg class="tmi-check" x-show="showPrinters" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Edit button: flips on edit mode; editing tools appear in their own bar below -->
                    <div class="map-tool-group edit-only" x-show="(can('base','edit') || can('printers','edit') || can('cameras','edit') || can('devices','edit')) && currentSite">
                        <button class="btn" :class="{'edit-on':roomEditMode}" @click="toggleRoomEdit()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mb-ic"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            <span x-text="roomEditMode ? 'Done editing' : 'Edit'"></span>
                        </button>
                    </div>

                    <!-- Tools menu: occasional actions -->
                    <div class="map-tool-group" x-show="(can('base','edit') || (can('printers','edit') && printersEnabled)) && currentSite" x-data="{open:false}" @click.outside="open=false">
                        <button class="btn menu-btn icon-only" @click="open=!open" :class="{active:open}" title="More tools">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
                        </button>
                        <div class="tool-menu tool-menu-right" x-show="open" x-transition x-cloak>
                            <template x-if="can('base','edit')">
                            <div>
                            <div class="tool-menu-label">Rooms</div>
                            <button class="tool-menu-item" @click="open=false; toggleRoomSelectMode()" :class="{'tmi-active':roomSelect.on}">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                                <span x-text="roomSelect.on ? 'Done grouping rooms' : 'Group rooms into buildings'"></span>
                            </button>
                            <div class="tool-menu-label">Start view</div>
                            <button class="tool-menu-item edit-only" @click="saveMapStartView(); open=false">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                                <span>Set start view here</span>
                            </button>
                            <button class="tool-menu-item edit-only" x-show="currentMapObj?.focus_x != null" @click="clearMapStartView(); open=false">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                <span>Clear start view</span>
                            </button>
                                                        <div class="tool-menu-label">Maps</div>
                            <button class="tool-menu-item" @click="open=false; openMapManager()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                                <span>Manage maps (suites / floors)</span>
                            </button>
                            <button class="tool-menu-item" @click="open=false; openSvgUpload()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                <span x-text="(currentSite && currentSite.maps && currentSite.maps.length>1) ? 'Upload SVG for current map' : 'Upload floor plan'"></span>
                            </button>
                            <div class="tool-menu-label">Import data</div>
                            <button class="tool-menu-item" @click="open=false; openRoomImport()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                <span>Import rooms (JSON)</span>
                            </button>
                            <button class="tool-menu-item" @click="open=false; openPeopleImport()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                                <span>Import people (CSV)</span>
                            </button>
                            </div>
                            </template>
                            <button class="tool-menu-item" x-show="printersEnabled && can('printers','edit')" @click="open=false; openPrinterImport()">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                <span>Import printers (PrinterLogic CSV)</span>
                            </button>
                        </div>
                    </div>

                    <!-- Editing tools: only while in edit mode, on their own row -->
                    <div class="map-tool-group edit-tools edit-only" x-show="roomEditMode && currentSite" x-transition x-cloak>
                        <button class="btn primary" x-show="!placingRoom && !measuringAngle && can('base','edit')" @click="startDrawRoom()">+ New Room</button>
                        <button class="btn" x-show="!placingRoom && !measuringAngle && printersEnabled && can('printers','edit')" @click="newPrinter()" title="Add a printer to this site">+ Printer</button>
                        <button class="btn" x-show="!placingRoom && !measuringAngle && (can('base','edit') || can('printers','edit') || can('cameras','edit'))" :class="{active:placePanel.open}" @click="togglePlacePanel()" title="Drag unplaced items onto the map">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:15px;height:15px;vertical-align:-2px;margin-right:5px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Place items<span class="place-btn-count" x-show="unplacedTotalForSite" x-text="unplacedTotalForSite"></span></button>
                        <span class="placing-hint" x-show="placingRoom">Click the map to drop the pin…</span>
                        <button class="btn" x-show="placingRoom" @click="cancelDrawRoom()">Cancel</button>
                        <button class="btn" x-show="!placingRoom && !measuringAngle && can('base','edit')"
                                @click="startAngleMeasure()"
                                :title="'Click two points along a wall to set the building angle. Current: ' + (buildingAngle||0).toFixed(1) + '°'">
                            📐 Set Angle <span class="angle-readout" x-text="(buildingAngle||0).toFixed(1)+'°'"></span>
                        </button>
                        <button class="btn warn" x-show="measuringAngle" @click="cancelAngleMeasure()">Cancel angle</button>
                        <template x-if="!placingRoom && !measuringAngle">
                            <span class="grid-tools">
                                <label class="snap-toggle" title="Pins snap to line up with each other and the grid"><input type="checkbox" x-model="gridSnap"> Snap</label>
                                <label class="snap-toggle" title="Show alignment grid"><input type="checkbox" x-model="showGrid"> Grid</label>
                                <label class="snap-toggle" x-show="can('base','edit')" title="When you drop a new room pin, read the room number from the label printed on the map"><input type="checkbox" x-model="smartRooms"> Smart Rooms</label>
                                <template x-if="showGrid || gridSnap">
                                    <span class="grid-size">
                                        <button class="btn tiny" @click="setGrid(gridStep/2)" title="Finer">−</button>
                                        <input type="range" min="0.1" max="10" step="0.1" x-model.number="gridStep" style="width:70px">
                                        <button class="btn tiny" @click="setGrid(gridStep*2)" title="Coarser">+</button>
                                        <span class="grid-readout" x-text="gridStep+'%'"></span>
                                    </span>
                                </template>
                            </span>
                        </template>
                    </div>
                </div>

                <!-- Selection action bar: appears while picking rooms to group -->
                <div class="select-bar" x-show="roomSelect.on" x-transition x-cloak>
                    <div class="sel-count"><strong x-text="roomSelect.ids.length"></strong> selected</div>
                    <button class="btn tiny" @click="selectAllVisibleRooms()">Select all visible</button>
                    <button class="btn tiny" @click="clearRoomSelection()" :disabled="!roomSelect.ids.length">Clear</button>
                    <span class="sel-label">Set building:</span>
                    <select class="bld-select" style="width:auto;min-width:140px" x-model="assignPick" :disabled="!roomSelect.ids.length">
                        <option value="">— choose —</option>
                        <template x-for="b in siteBuildings" :key="'asg-'+b.id">
                            <option :value="b.code" x-text="b.label ? (b.code + ' · ' + b.label) : b.code"></option>
                        </template>
                        <option value="__clear__">(remove building)</option>
                    </select>
                    <button class="btn primary tiny" :disabled="!roomSelect.ids.length || !assignPick"
                            @click="assignBuildingToSelection(assignPick==='__clear__' ? null : assignPick); assignPick=''">Apply</button>
                    <span class="sel-hint" x-show="!siteBuildings.length">No buildings yet — add some via “Manage buildings”.</span>
                    <div class="sel-spacer"></div>
                    <button class="btn tiny sel-close" @click="toggleRoomSelectMode()" title="Exit grouping">Close</button>
                </div>

                <!-- List multi-select: pick rooms by name (complements clicking pins) -->

                <!-- Unified placement panel: drag any layer's unplaced pins (this site) onto the map -->


                <div class="map-stage">
                    <!-- Selected-rooms list while grouping: docked as a column like
                         the Place-items panel (it used to float absolute at the
                         top-left, on top of the map and everything else). -->
                    <div class="sel-list-panel" x-show="roomSelect.on" x-transition x-cloak>
                        <div class="slp-head">
                            <span>Rooms on this level</span>
                            <input type="text" x-model="roomListFilter" placeholder="Filter…" class="slp-filter">
                        </div>
                        <div class="slp-body">
                            <template x-for="room in roomsVisible.filter(r => !roomListFilter || (r.room_name||'').toLowerCase().includes(roomListFilter.toLowerCase()) || (r.room_number||'').toString().includes(roomListFilter))" :key="'slp-'+room.room_id">
                                <label class="slp-row" :class="{on:isRoomSelected(room.room_id)}">
                                    <input type="checkbox" :checked="isRoomSelected(room.room_id)" @change="toggleRoomSelected(room.room_id)">
                                    <span class="slp-name" x-text="room.room_name || 'Room'"></span>
                                    <span class="slp-num" x-show="roomNumberLabel(room)" x-text="roomNumberLabel(room)"></span>
                                </label>
                            </template>
                            <div class="muted-note" x-show="!roomsVisible.length" style="padding:8px">No rooms on this level.</div>
                        </div>
                    </div>

                    <!-- Place-items: docked as a column beside the map (it used to
                         float ON TOP of the map — covering the exact surface the
                         items get dragged onto). Same height treatment as the
                         room editor: pinned to the map's height, body scrolls. -->
                    <div class="place-panel" x-show="placePanel.open && roomEditMode && canEdit" x-transition x-cloak>
                        <div class="pp-head">
                            <div class="pp-title">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                <span>Place items</span>
                            </div>
                            <button class="pp-close" @click="placePanel.open=false" title="Close"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
                        </div>
                        <input type="text" x-model="placePanel.filter" placeholder="Filter this site's items…" class="pp-filter">
                        <div class="pp-body">
                            <template x-for="layer in placeLayers" :key="'pl-'+layer.key">
                                <div class="pp-section" x-show="layer.enabled">
                                    <button class="pp-sec-head" @click="_placeCollapsed[layer.key] = !layer.collapsed">
                                        <span class="pp-sec-ic" x-html="layer.icon"></span>
                                        <span class="pp-sec-name" x-text="layer.label"></span>
                                        <span class="pp-sec-count" x-show="layer.items.length" x-text="layer.items.length + ' unplaced'"></span>
                                        <span class="pp-sec-count future" x-show="layer.future">future layer</span>
                                        <svg class="pp-chev" :class="{open:!layer.collapsed}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><polyline points="6 9 12 15 18 9"/></svg>
                                    </button>
                                    <div x-show="!layer.collapsed">
                                        <template x-for="item in layer.items" :key="layer.key+'-'+item.id">
                                            <div class="pp-row" :class="{dragging: listDrag.active && listDrag.placeId===item.id && listDrag.placeLayer===layer.key}"
                                                 @pointerdown="startPlaceDrag(layer.key, item, $event)" title="Drag onto the map to place">
                                                <span class="pp-row-ic" :style="'background:'+layer.color" x-html="layer.icon"></span>
                                                <span class="pp-row-text">
                                                    <span class="pp-row-name" x-text="item.name"></span>
                                                    <span class="pp-row-meta" x-text="item.meta"></span>
                                                </span>
                                                <svg class="pp-grip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="18" r="1"/></svg>
                                            </div>
                                        </template>
                                        <div class="pp-empty" x-show="!layer.items.length && !layer.future">All placed — nothing waiting.</div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="pp-foot"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Drag any item onto the map to place it</div>
                    </div>
                <div class="map-viewport" id="mapViewport" :class="{editing:roomEditMode, panning:isPanning}" x-ref="viewport"
                     @wheel="onWheelZoom($event)"
                     @pointerdown="startPan($event)">
                    <div class="map-spinner" x-show="mapSvgLoading" x-cloak>
                        <div class="spinner"></div>
                        <span>Loading map…</span>
                    </div>
                    <template x-if="currentSite && currentSite.has_map">
                        <div class="map-sizer" x-ref="sizer" :style="'width:'+(mapBaseW*mapZoom)+'px;height:'+(mapBaseH*mapZoom)+'px'">
                        <div class="map-canvas"
                             x-ref="canvas"
                             :style="'width:'+mapBaseW+'px;height:'+mapBaseH+'px;transform:scale('+mapZoom+');--pin-scale:'+(1/(mapZoom||1))"
                             @click="onCanvasClick($event)"
                             @mousemove="onCanvasMove($event)">

                            <!-- SVG floor plan background -->
                            <div class="map-bg" x-html="mapSvgMarkup"></div>

                            <!-- Optional grid + snap guide overlay (behind pins) -->
                            <div class="grid-host" x-html="gridSvg"></div>
                            <div class="guide-host" x-ref="guideHost"></div>

                            <!-- Lasso selection rectangle (building grouping tool) -->
                            <!-- While placing a room, this transparent layer sits above every
                                 polygon, pin, and label and owns the pointer completely. It exists
                                 because existing rooms' shapes sit ON TOP of the printed numbers:
                                 a drag starting there was swallowed, and the release-click opened
                                 that underlying room's popup — the wrong name, and no form fill. -->
                            <div class="place-catch" x-show="placingRoom" x-cloak @pointerdown.stop.prevent="startRoomPickBox($event)"></div>
                            <!-- Highlight box while placing a room: drag over the printed room number to read it. -->
                            <div class="sel-box pick-box" x-show="pickBox" x-cloak
                                 :style="pickBox ? ('left:'+Math.min(pickBox.x0,pickBox.x1)+'%;top:'+Math.min(pickBox.y0,pickBox.y1)+'%;width:'+Math.abs(pickBox.x1-pickBox.x0)+'%;height:'+Math.abs(pickBox.y1-pickBox.y0)+'%') : ''"></div>
                            <div class="sel-box" x-show="roomSelect.box" x-cloak
                                 :style="roomSelect.box ? ('left:'+Math.min(roomSelect.box.x0,roomSelect.box.x1)+'%;top:'+Math.min(roomSelect.box.y0,roomSelect.box.y1)+'%;width:'+Math.abs(roomSelect.box.x1-roomSelect.box.x0)+'%;height:'+Math.abs(roomSelect.box.y1-roomSelect.box.y0)+'%') : ''"></div>

                            <!-- Room pins: one clickable marker per room at its point.
                                 Counter-scaled inline so they stay a constant size at any
                                 zoom, and anchored by their tip (translate -50%,-100%). -->
                            <template x-for="room in roomPinsOnMap" :key="'pin-'+room.room_id">
                                <div class="room-pin"
                                     :class="{selected: editingRoomId===room.room_id, editing: roomEditMode, dot: mapZoom < dotThreshold, blink: blinkRoomId===room.room_id, 'sel-pick': roomSelect.on && isRoomSelected(room.room_id), 'sel-mode': roomSelect.on}"
                                     :style="'left:'+labelPosition(room).x+'%;top:'+labelPosition(room).y+'%'"
                                     :data-room-id="room.room_id"
                                     @pointerdown="onPinPointerDown(room, $event)">
                                    <div class="pin-body" :style="roomColor(room) ? ('background:'+roomColor(room)) : ''"
                                         x-text="(roomNumberLabel(room) || room.room_name) || '•'"></div>
                                    <div class="pin-stem"></div>
                                    <button class="pin-unplace" x-show="roomEditMode && can('base','edit')" x-cloak
                                            :class="{armed: mapUnplaceArm==='room:'+room.room_id}"
                                            :title="mapUnplaceArm==='room:'+room.room_id ? 'Tap again to unplace' : 'Remove from map (back to Place items)'"
                                            @click="requestMapUnplace('room', room.room_id, $event)"
                                            @pointerdown.stop>&times;</button>
                                    <div class="pin-name pin-name-hover">
                                        <!-- Full pill already displays the number, so its hover adds the
                                             type; a dot displays nothing, so its hover gives the number.
                                             CSS picks which span shows — no zoom-reactive binding per pin. -->
                                        <span class="pn-type" x-text="formatRoomType(room.room_type)"></span>
                                        <span class="pn-num" x-text="(roomNumberLabel(room) || room.room_name) || ''"></span>
                                    </div>
                                    <div class="pin-check" x-show="roomSelect.on && isRoomSelected(room.room_id)">✓</div>
                                </div>
                            </template>

                            <!-- Camera pins (Phase 2): same pin style as devices, different
                                 colour. Object-permission-filtered server-side. Click = info. -->
                            <template x-for="cam in camerasOnMap" :key="'cam-'+cam.camera_number">
                                <div class="room-pin camera-pin"
                                     :class="{dot: mapZoom < dotThreshold, selected: selectedCamera && selectedCamera.camera_number===cam.camera_number, offline: !cameraIsOnline(cam), editing: roomEditMode && canEdit}"
                                     :style="'left:'+cam.map_x+'%;top:'+cam.map_y+'%'"
                                     :data-cam="cam.camera_number"
                                     @pointerdown="onCameraPinDown(cam, $event)"
                                     @pointerenter="onCameraPinEnter(cam, $event)"
                                     @pointerleave="onCameraPinLeave()">
                                    <div class="pin-body">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                    </div>
                                    <div class="pin-stem"></div>
                                    <button class="pin-unplace" x-show="roomEditMode && can('cameras','edit')" x-cloak
                                            :class="{armed: mapUnplaceArm==='cam:'+cam.camera_number}"
                                            :title="mapUnplaceArm==='cam:'+cam.camera_number ? 'Tap again to unplace' : 'Remove from map (back to Place items)'"
                                            @click="requestMapUnplace('cam', cam.camera_number, $event)"
                                            @pointerdown.stop>&times;</button>
                                    <div class="pin-name" x-show="mapZoom >= 1.6" x-text="cam.camera_name"></div>
                                </div>
                            </template>
                            <template x-for="pr in printersOnMap" :key="'pr-'+pr.printer_id">
                                <div class="room-pin printer-pin"
                                     :class="{dot: mapZoom < dotThreshold, selected: selectedPrinter && selectedPrinter.printer_id===pr.printer_id, editing: roomEditMode && canEdit}"
                                     :style="'left:'+pr.map_x+'%;top:'+pr.map_y+'%'"
                                     :data-printer="pr.printer_id"
                                     @pointerdown="onPrinterPinDown(pr, $event)">
                                    <div class="pin-body">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                                    </div>
                                    <div class="pin-stem"></div>
                                    <button class="pin-unplace" x-show="roomEditMode && can('printers','edit')" x-cloak
                                            :class="{armed: mapUnplaceArm==='printer:'+pr.printer_id}"
                                            :title="mapUnplaceArm==='printer:'+pr.printer_id ? 'Tap again to unplace' : 'Remove from map (back to Place items)'"
                                            @click="requestMapUnplace('printer', pr.printer_id, $event)"
                                            @pointerdown.stop>&times;</button>
                                    <div class="pin-name" x-show="mapZoom >= 1.6" x-text="pr.printer_name"></div>
                                </div>
                            </template>
                        </div>
                        </div>
                    </template>

                    <!-- Camera info popup relocated below, outside the scrolling viewport -->

                    <template x-if="!currentSite || !currentSite.has_map">
                        <div class="map-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg>
                            <div class="map-empty-title" x-text="!currentSite ? 'Pick a site' : 'No floor plan attached to this site'"></div>
                            <div class="map-empty-sub" x-show="currentSite">
                                Set a SVG path on the site row, e.g.<br>
                                <code>INSERT INTO site_map (site_number, map_key, name, svg_path, sort_order) VALUES (<span x-text="currentSite?.id"></span>, 'level-1', 'Level 1', '/var/www/html/maps/<span x-text="(currentSite?.abbr||'site').toLowerCase()"></span>/map.svg', 0);</code>
                            </div>
                        </div>
                    </template>

                    <div class="draft-hint" x-show="drawingRoom">
                        Click to add a corner. <kbd>Click first dot</kbd> or hit <kbd>Finish Polygon</kbd> to close (min 3 corners). <kbd>Esc</kbd> cancels.
                    </div>
                </div>
                <!-- Shape tools sidebar — visible only while editing a room -->
                <aside class="shape-sidebar" x-show="false" x-cloak>
                    <div class="ss-title">Shape</div>
                    <div class="ss-group">
                        <div class="ss-label">Rotate</div>
                        <div class="ss-row">
                            <button class="btn tiny" @click="rotateRoom(-5)" title="Rotate left 5°">⟲ 5</button>
                            <button class="btn tiny" @click="rotateRoom(-1)">⟲ 1</button>
                            <button class="btn tiny" @click="rotateRoom(1)">1 ⟳</button>
                            <button class="btn tiny" @click="rotateRoom(5)" title="Rotate right 5°">5 ⟳</button>
                        </div>
                        <div class="ss-row">
                            <input type="number" class="angle-field" step="0.5" min="-180" max="180"
                                   :value="roomAngle"
                                   @change="setRoomAngle($event.target.value)"
                                   title="Exact angle (degrees)">
                            <button class="btn tiny" @click="setRoomAngle(buildingAngle)" title="Match the building angle">⟂ Bldg</button>
                        </div>
                    </div>
                    <div class="ss-group">
                        <div class="ss-label">Scale</div>
                        <div class="ss-row">
                            <button class="btn tiny" @click="scaleRoom(0.95)" title="Shrink">– smaller</button>
                            <button class="btn tiny" @click="scaleRoom(1.05)" title="Grow">+ larger</button>
                        </div>
                    </div>
                    <div class="ss-group">
                        <div class="ss-label">Nudge</div>
                        <div class="ss-row nudge-grid">
                            <span></span>
                            <button class="btn tiny" @click="nudgeRoom(0,-0.5)">↑</button>
                            <span></span>
                            <button class="btn tiny" @click="nudgeRoom(-0.5,0)">←</button>
                            <button class="btn tiny" @click="nudgeRoom(0.5,0)">→</button>
                            <span></span>
                            <button class="btn tiny" @click="nudgeRoom(0,0.5)">↓</button>
                            <span></span>
                        </div>
                    </div>
                    <div class="ss-group">
                        <div class="ss-label">Straighten</div>
                        <div class="ss-row">
                            <button class="btn tiny" @click="straightenRoom()" title="Square the box up, aligned to the building">▭ Straighten</button>
                        </div>
                    </div>
                    <div class="ss-group">
                        <div class="ss-label">Grid</div>
                        <label class="ss-check"><input type="checkbox" x-model="gridSnap"> Snap to grid</label>
                        <label class="ss-check"><input type="checkbox" x-model="showGrid"> Show grid</label>
                        <div class="ss-row" x-show="showGrid || gridSnap">
                            <button class="btn tiny" @click="setGrid(gridStep/2)" title="Finer grid">−</button>
                            <input type="range" min="0.1" max="10" step="0.1" x-model.number="gridStep" style="flex:1;min-width:60px">
                            <button class="btn tiny" @click="setGrid(gridStep*2)" title="Coarser grid">+</button>
                        </div>
                        <div class="ss-readout" x-show="showGrid || gridSnap" x-text="gridStep+'%'"></div>
                    </div>
                </aside>

                <!-- Room editor — right-side panel; map stays fully visible & pin draggable -->
                <div class="room-editor" x-show="roomEditMode && editingRoomId && !drawingRoom" x-cloak>
                    <div class="re-head">
                        <div class="re-title" x-text="editingRoomId>0 ? 'Edit room' : 'New room'"></div>
                        <button class="re-x" @click="cancelRoomEdit()" title="Close">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>
                    <div class="re-body">
                        <!-- Section: Details -->
                        <div class="re-sec-label">Details</div>
                        <div class="field">
                            <label>Name *</label>
                            <input type="text" x-model="editForm.room_name" placeholder="e.g. Library">
                        </div>
                        <div class="re-grid2">
                            <div class="field">
                                <label>Building</label>
                                <select x-model="editForm.building" class="bld-select" @change="onEditBuildingChange()">
                                    <option value="">— none —</option>
                                    <template x-for="b in siteBuildings" :key="b.id">
                                        <option :value="b.code" x-text="b.label ? (b.code + ' · ' + b.label) : b.code"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="field">
                                <label>Room number</label>
                                <input type="text" x-model="editForm.room_number" placeholder="e.g. 204">
                            </div>
                        </div>
                        <div class="bld-preview" x-show="editForm.building || editForm.room_number">
                            Displays as <strong x-text="roomNumberLabel(editForm) || '—'"></strong>
                        </div>
                        <div class="re-grid2">
                            <div class="field">
                                <label>Type</label>
                                <div class="ddown" :class="{open}" x-data="ddown('editForm.room_type', [
                                        {v:'general', n:'General'},
                                        {v:'classroom', n:'Classroom'},
                                        {v:'office', n:'Office'},
                                        {v:'lab', n:'Lab'},
                                        {v:'library', n:'Library'},
                                        {v:'breakroom', n:'Break Room'},
                                        {v:'storage', n:'Storage'},
                                        {v:'restroom', n:'Restroom'},
                                        {v:'utility', n:'Utility'},
                                        {v:'hallway', n:'Hallway'},
                                        {v:'conference', n:'Conference Room'},
                                        {v:'cafeteria', n:'Cafeteria'},
                                        {v:'gym', n:'Gym'},
                                        {v:'auditorium', n:'Auditorium'},
                                    ])" @click.outside="open=false">
                                    <button type="button" class="ddown-trigger" @click="open=!open">
                                        <span x-text="label"></span>
                                        <span class="caret">▾</span>
                                    </button>
                                    <div class="ddown-panel">
                                        <template x-for="o in items" :key="o.v">
                                            <div class="ddown-opt" :class="{active:isActive(o)}"
                                                 @click="pick(o)" x-text="o.n"></div>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="re-grid2">
                            <div class="field">
                                <label>Department</label>
                                <input type="text" x-model="editForm.department" placeholder="e.g. IT, Admin">
                            </div>
                            <div class="field">
                                <label>Capacity</label>
                                <input type="number" min="0" x-model.number="editForm.capacity" placeholder="0">
                            </div>
                        </div>
                        <div class="re-grid2">
                            <div class="field">
                                <label>Level</label>
                                <input type="text" x-model="editForm.map_level" placeholder="level-1">
                            </div>
                            <div class="field">
                                <label>Color</label>
                                <div class="re-color">
                                    <input type="color" :value="editForm.color || '#3b82f6'" @input="editForm.color = $event.target.value">
                                    <input type="text" x-model="editForm.color" placeholder="#3b82f6">
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <label>Description</label>
                            <textarea x-model="editForm.description" placeholder="Notes about this room..."></textarea>
                        </div>

                        <!-- Section: Contact / alert -->
                        <div class="re-sec-label">Contact &amp; alerts</div>
                        <div class="field">
                            <label>Room phone ext.</label>
                            <input type="text" x-model="editForm.room_extension" placeholder="e.g. 10700">
                        </div>
                        <div class="field">
                            <label>Notes / alert <span style="text-transform:none;font-weight:500;color:var(--text-dim)">(shown prominently in the popup)</span></label>
                            <input type="text" x-model="editForm.room_notes" placeholder="e.g. Projector broken, awaiting part">
                        </div>

                        <!-- Section: People -->
                        <div class="re-sec-label">People &amp; extensions</div>
                        <div class="field re-toggle-field">
                            <label class="re-toggle-row">
                                <span>
                                    <span class="re-toggle-title">Feature a contact at the top</span>
                                    <span class="re-toggle-desc">Off by default. When on, the ★ primary person below is shown prominently at the top of this room's info — not every room needs one.</span>
                                </span>
                                <span class="switch">
                                    <input type="checkbox" x-model="editForm.show_primary_contact">
                                    <span class="switch-slider"></span>
                                </span>
                            </label>
                        </div>
                        <div class="field people-editor">
                            <div class="pe-rows">
                                <template x-for="(p, idx) in (editForm.occupants || [])" :key="idx">
                                    <div class="pe-row">
                                        <button class="pe-primary" :class="{on:p.is_primary}" @click="setPrimaryOccupant(idx)" :title="p.is_primary?'Primary contact':'Set as primary'">★</button>
                                        <input type="text" class="pe-name" x-model="p.name" placeholder="Name (e.g. Jenny James)">
                                        <input type="text" class="pe-role" x-model="p.role" placeholder="Role">
                                        <input type="text" class="pe-ext" x-model="p.extension" placeholder="ext.">
                                        <button class="pe-del" @click="removeOccupant(idx)" title="Remove">×</button>
                                    </div>
                                </template>
                                <div class="pe-empty" x-show="!(editForm.occupants || []).length">No people yet. Add staff who work in this room.</div>
                            </div>
                            <button class="btn tiny" @click="addOccupant()" style="align-self:flex-start;margin-top:6px">+ Add person</button>
                        </div>

                        <!-- Section: Pin -->
                        <div class="re-sec-label">Pin</div>
                        <button class="btn" style="width:100%" @click="redrawPolygon()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;margin-right:6px;vertical-align:-2px"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                            Move pin to a new spot
                        </button>
                        <p class="re-hint">Tip: in edit mode you can also just drag the pin on the map to move it.</p>
                    </div>
                    <div class="re-foot">
                        <button class="btn danger" @click="deleteRoom()" x-show="editingRoomId>0" title="Delete room">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        </button>
                        <div class="spacer"></div>
                        <button class="btn" @click="cancelRoomEdit()">Cancel</button>
                        <button class="btn save" @click="saveRoom()">Save</button>
                    </div>
                </div>

                <!-- Persistent camera info card (object-only click) — anchored to map-stage so it's never clipped -->
                <div class="cam-info-pop" x-show="selectedCamera" x-cloak x-transition @click.outside="closeCameraInfo()">
                    <template x-if="selectedCamera">
                        <div>
                            <div class="cip-head">
                                <div class="cip-ic" :class="{online:cameraIsOnline(selectedCamera)}">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                </div>
                                <div class="cip-title">
                                    <div class="cip-name" x-text="selectedCamera.camera_name"></div>
                                    <div class="cip-sub">Camera #<span x-text="selectedCamera.camera_number"></span></div>
                                </div>
                                <button class="cip-x" @click="closeCameraInfo()" title="Close">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <div class="cip-rows">
                                <div class="cip-row">
                                    <span class="cip-k">Status</span>
                                    <span class="cip-status" :class="{on:cameraIsOnline(selectedCamera)}">
                                        <span class="cip-dot"></span><span x-text="cameraStatusText(selectedCamera)"></span>
                                    </span>
                                </div>
                                <div class="cip-row" x-show="selectedCamera.camera_ip">
                                    <span class="cip-k">IP address</span>
                                    <span class="cip-v mono" x-text="selectedCamera.camera_ip"></span>
                                </div>
                                <div class="cip-row" x-show="selectedCamera.last_update">
                                    <span class="cip-k">Last seen</span>
                                    <span class="cip-v" x-text="formatLastSeen(selectedCamera.last_update)"></span>
                                </div>
                            </div>
                            <div class="cip-foot" x-show="selectedCamera.can_feed">
                                <button class="btn primary" style="width:100%" @click="openCameraFeed(selectedCamera)">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;margin-right:6px;vertical-align:-2px"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                    Watch live feed
                                </button>
                            </div>
                            <div class="cip-foot" x-show="!selectedCamera.can_feed">
                                <span class="cip-feed-hint" style="color:var(--text-dim)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg> No live-feed access</span>
                            </div>
                        </div>
                    </template>
                </div>
                </div><!-- /map-stage -->

                <!-- Hover preview (fixed-position; live thumbnail for feed cameras, info otherwise) -->
                <div class="cam-hover-card" x-show="camHover.show && camHover.cam" x-cloak
                     :style="'left:'+camHover.x+'px;top:'+camHover.y+'px'"
                     @pointerenter="cancelCamHoverHide()" @pointerleave="onCameraPinLeave()">
                    <template x-if="camHover.cam">
                        <div>
                            <div class="chc-video" x-show="camHover.cam.can_feed && cameraIsOnline(camHover.cam) && camHover.cam.stream_sub">
                                <iframe :src="camHover.cam.stream_sub" style="width:100%;height:100%;border:none;display:block;pointer-events:none"></iframe>
                            </div>
                            <div class="chc-novideo" x-show="!(camHover.cam.can_feed && cameraIsOnline(camHover.cam) && camHover.cam.stream_sub)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>
                                <span x-show="!cameraIsOnline(camHover.cam)">Offline</span>
                                <span x-show="cameraIsOnline(camHover.cam) && !camHover.cam.can_feed">No feed access</span>
                            </div>
                            <div class="chc-cap">
                                <span class="chc-dot" :class="cameraIsOnline(camHover.cam)?'online':'offline'"></span>
                                <span class="chc-name" x-text="camHover.cam.camera_name"></span>
                                <span class="chc-act" x-show="camHover.cam.can_feed">click to expand</span>
                            </div>
                        </div>
                    </template>
                </div>
                </div><!-- /siteTab map -->

                <!-- Upload SVG modal -->
                <div class="modal-backdrop" x-show="showSvgUpload" x-cloak @click.self="showSvgUpload=false">
                    <div class="modal-card">
                        <div class="modal-head">
                            <h3>Upload floor-plan map</h3>
                            <button class="modal-x" @click="showSvgUpload=false">×</button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-help">Upload the building's <strong>.svg</strong> for <strong x-text="currentSite?.name"></strong>. It becomes this site's map background. Replaces any existing map.</p>
                            <input type="file" x-ref="svgFileInput" accept=".svg,image/svg+xml" @change="onSvgFilePicked($event)">
                            <div class="upload-picked" x-show="svgFile" x-text="svgFile ? ('Selected: ' + svgFile.name) : ''"></div>
                            <div class="upload-status" x-show="svgUploadMsg" x-text="svgUploadMsg" :class="{err:svgUploadErr}"></div>
                        </div>
                        <div class="modal-foot">
                            <button class="btn" @click="showSvgUpload=false">Cancel</button>
                            <button class="btn save" @click="doSvgUpload()" :disabled="!svgFile || svgUploading" x-text="svgUploading?'Uploading…':'Upload'"></button>
                        </div>
                    </div>
                </div>

                <!-- Import rooms modal -->
                <div class="modal-backdrop" x-show="showRoomImport" x-cloak @click.self="showRoomImport=false">
                    <div class="modal-card wide">
                        <div class="modal-head">
                            <h3>Import rooms</h3>
                            <button class="modal-x" @click="showRoomImport=false">×</button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-help">Paste the <code>.rooms.json</code> from the extractor, or pick the file. Rooms load as pins you can drag to fine-tune.</p>
                            <input type="file" accept=".json,application/json" @change="onImportFilePicked($event)">
                            <textarea class="import-text" x-model="importJsonText" @input.debounce.400ms="_detectImportAngle()" placeholder='{ "rooms": [ { "room_number": "204", "polygon_points": [...] } ] }'></textarea>
                            <label class="check"><input type="checkbox" x-model="importReplace"> Replace existing rooms on the same level(s) (avoids duplicates on re-import)</label>
                            <label class="check" x-show="importHasAngle"><input type="checkbox" x-model="importApplyAngle"> Apply detected building angle (<span x-text="importAngleText"></span>) — leave off to keep your current angle</label>
                            <div class="upload-status" x-show="importMsg" x-text="importMsg" :class="{err:importErr}"></div>
                        </div>
                        <div class="modal-foot">
                            <button class="btn" @click="showRoomImport=false">Cancel</button>
                            <button class="btn save" @click="doRoomImport()" :disabled="importing || !importJsonText.trim()" x-text="importing?'Importing…':'Import rooms'"></button>
                        </div>
                    </div>
                </div>

            </div>

            <!-- ============ ROOM (full view) ============ -->
            <div x-show="view==='room' && currentRoom" x-cloak>

                <div class="map-toolbar">
                    <div class="map-tool-group">
                        <button class="btn" @click="goSite(currentSiteId, 'map')">← Back to map</button>
                        <span style="font-size:13px;font-weight:600;margin-left:6px" x-text="currentRoom?.room_name"></span>
                        <span class="pill" x-show="currentRoom?.room_number"><span x-text="'#' + currentRoom?.room_number"></span></span>
                        <span class="pill" x-show="currentRoom?.room_type"><span x-text="currentRoom?.room_type"></span></span>
                    </div>
                    <div class="map-tool-group edit-only" x-show="can('devices','edit')">
                        <button class="btn warn" :class="{active:deviceEditMode}" @click="toggleDeviceEdit()" x-text="deviceEditMode?'Done Editing':'Edit Devices'"></button>
                        <button class="btn primary" x-show="deviceEditMode" @click="openDeviceAdd()">+ Add Device</button>
                        
                    </div>
                </div>

                <div class="room-layout" :class="{editing:deviceEditMode, moving:deviceEditMode}">

                    <!-- Stage with device pins -->
                    <div class="room-stage" :class="{placing:placingDeviceId, tracing:shapeEdit.active, panning:shapeEdit.active && shapeEdit.backdrop && !shapeEdit.locked, droptarget:listDrag.active}" x-ref="stage"
                         @click="onStageClick($event)"
                         @pointerdown="shapeEdit.active ? startBgPan($event) : null">

                        <!-- Faint floor-plan backdrop (trace guide) -->
                        <div class="trace-bg-wrap" x-show="shapeEdit.active && shapeEdit.backdrop && mapSvgMarkup" x-cloak>
                            <div class="trace-bg" :style="shapeBgStyle" x-html="mapSvgMarkup"></div>
                        </div>

                        <!-- Trace grid overlay -->
                        <div class="trace-grid" x-show="shapeEdit.active && shapeEdit.grid" :style="shapeGridStyle" x-cloak></div>

                        <!-- Room interior boundary (always shown, behind devices) -->
                        <svg class="room-shape-svg" viewBox="0 0 100 100" preserveAspectRatio="none">
                            <polygon class="room-shape-poly"
                                     :class="{tracing:shapeEdit.active}"
                                     :points="shapeEdit.active ? shapePointsAttr(shapeEdit.points) : shapePointsAttr(currentRoomShape)"
                                     :style="'stroke:'+((currentRoom&&currentRoom.color)||'var(--accent)')"></polygon>
                        </svg>

                        <!-- Trace corner handles (edit mode only) -->
                        <template x-if="shapeEdit.active">
                            <div>
                                <!-- edge midpoint "+" handles: grab to add a corner between two points -->
                                <template x-for="(m, mi) in shapeMidpoints" :key="'mid'+mi">
                                    <div class="shape-mid"
                                         :style="'left:'+m.x+'%;top:'+m.y+'%'"
                                         @pointerdown="startMidDrag(m.after, $event)"
                                         @click.stop=""
                                         title="Add a corner here (splits this edge)">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                    </div>
                                </template>
                                <!-- corner handles -->
                                <template x-for="(p, i) in shapeEdit.points" :key="i">
                                    <div class="shape-vtx" :class="{dragging:shapeEdit.dragIdx===i}"
                                         :style="'left:'+p.x+'%;top:'+p.y+'%'"
                                         @pointerdown="startVtxDrag(i, $event)"
                                         @click.stop=""
                                         @dblclick.stop="removeShapePoint(i)"
                                         :title="'Corner '+(i+1)+' — drag to move, double-click to remove'">
                                        <span class="shape-vtx-num" x-text="i+1"></span>
                                    </div>
                                </template>
                            </div>
                        </template>

                        <template x-for="dev in placedDevices" :key="dev.device_id">
                            <div class="device-pin"
                                 :class="{editing:deviceEditMode, selected:selectedDeviceId===dev.device_id, offline:dev.status!=='active'}"
                                 :style="'left:'+dev.pos_x+'%;top:'+dev.pos_y+'%'"
                                 @click.stop="onDevicePinClick(dev)"
                                 @pointerdown="deviceEditMode ? startDevicePointerDrag(dev, $event) : null">
                                <div class="device-icon" :style="'--device-color:'+typeColor(dev.device_type_key)" x-html="typeIconSvg(dev.device_type_key)"></div>
                                <div class="device-label" x-text="dev.device_name"></div>
                                <button class="pin-unplace"
                                        x-show="deviceEditMode && selectedDeviceId===dev.device_id"
                                        :class="{armed: unplaceConfirmId===dev.device_id}"
                                        @pointerdown.stop.prevent=""
                                        @click.stop.prevent="requestUnplace(dev, $event)"
                                        :title="unplaceConfirmId===dev.device_id ? 'Click again to remove from map' : 'Unplace this device'">
                                    <span x-show="unplaceConfirmId!==dev.device_id">×</span>
                                    <span x-show="unplaceConfirmId===dev.device_id" style="font-size:9px;font-weight:700">OK?</span>
                                </button>
                            </div>
                        </template>

                        <template x-for="pr in printersInCurrentRoom" :key="'rpr-'+pr.printer_id">
                            <div class="device-pin printer-room-pin"
                                 :class="{editing:deviceEditMode, selected:selectedPrinter && selectedPrinter.printer_id===pr.printer_id}"
                                 :style="'left:'+pr.room_pos_x+'%;top:'+pr.room_pos_y+'%'"
                                 @click.stop="onPrinterPinClick(pr)"
                                 @pointerdown="deviceEditMode ? startPrinterRoomDrag(pr, $event) : null">
                                <div class="device-icon" style="--device-color:#0d9488"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></div>
                                <div class="device-label" x-text="pr.printer_name"></div>
                                <button class="pin-unplace"
                                        x-show="deviceEditMode"
                                        @pointerdown.stop.prevent=""
                                        @click.stop.prevent="unassignPrinterRoom(pr)"
                                        title="Remove this printer from the room">×</button>
                            </div>
                        </template>

                        <template x-if="placedDevices.length===0 && !placingDeviceId && !shapeEdit.active">
                            <div class="map-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                                <div class="map-empty-title">No devices placed yet</div>
                                <div class="map-empty-sub edit-only" x-show="can('devices','edit')"><strong>Edit Devices</strong> to add one, then <strong>Move Devices</strong> and drag it onto the map.</div>
                            </div>
                        </template>

                        <div class="draft-hint" x-show="placingDeviceId">
                            Click on the canvas to place the device. <kbd>Esc</kbd> to cancel.
                        </div>
                        <div class="draft-hint" x-show="deviceEditMode && !placingDeviceId && !shapeEdit.active">
                            Drag devices to move them · drag from the list to place. Click <strong>Done Moving</strong> when finished.
                        </div>

                        <!-- Set-shape entry button -->
                        <button class="shape-edit-btn edit-only" x-show="can('base','edit') && !shapeEdit.active && !placingDeviceId && !deviceEditMode"
                                @click.stop="startShapeEdit()"
                                :title="(currentRoom && currentRoom.room_shape) ? 'Edit the room shape' : 'Trace the room shape'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 2 17 12 22 22 17 22 7 12 2"/></svg>
                            <span x-text="(currentRoom && currentRoom.room_shape) ? 'Edit shape' : 'Trace room shape'"></span>
                        </button>
                    </div>

                    <!-- Side panel -->
                    <div class="room-panel">
                        <!-- Trace mode takes over this column; reverts on save/cancel -->
                        <template x-if="shapeEdit.active">
                            <div class="trace-col">
                                <div class="room-panel-head">
                                    <div class="room-panel-title"><span>Trace room shape</span></div>
                                    <div class="room-panel-sub" x-text="currentRoom?.room_name"></div>
                                </div>
                                <div class="trace-col-body">
                                    <div class="shape-hint">Start from the rectangle, then shape it: <strong>drag a corner</strong> to move it · <strong>drag a dashed +</strong> on an edge to add a corner · <strong>double-click a corner</strong> to remove it. Frame the room with zoom &amp; drag.</div>

                                    <div class="shape-panel-sec" x-show="currentSite && currentSite.has_map">
                                        <label class="bg-check">
                                            <input type="checkbox" :checked="shapeEdit.backdrop" @change="toggleShapeBackdrop()">
                                            Floor plan backdrop
                                        </label>
                                        <label class="bg-check" x-show="shapeEdit.backdrop">
                                            <input type="checkbox" :checked="shapeEdit.locked" @change="toggleShapeLock()">
                                            <span style="display:inline-flex;align-items:center;gap:5px">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                Lock map in place
                                            </span>
                                        </label>
                                        <div class="bg-zoom" :class="{disabled:!shapeEdit.backdrop || shapeEdit.locked}">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                                            <input type="range" min="1" max="14" step="0.5" :value="shapeEdit.bgZoom" @input="setShapeZoom($event.target.value)" :disabled="!shapeEdit.backdrop || shapeEdit.locked">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.5" y2="16.5"/><line x1="8" y1="11" x2="14" y2="11"/><line x1="11" y1="8" x2="11" y2="14"/></svg>
                                        </div>
                                        <div class="bg-zoom" :class="{disabled:!shapeEdit.backdrop}">
                                            <span style="font-size:10px;color:var(--text-muted);font-weight:700;white-space:nowrap">PLAN</span>
                                            <input type="range" min="0.1" max="1" step="0.05" :value="shapeEdit.bgOpacity" @input="setBgOpacity($event.target.value)" :disabled="!shapeEdit.backdrop" title="How strongly the floor plan shows">
                                            <span style="font-size:11px;color:var(--text-muted);min-width:34px;text-align:right" x-text="Math.round(shapeEdit.bgOpacity*100) + '%'"></span>
                                        </div>
                                    </div>

                                    <div class="shape-panel-sec">
                                        <div class="shape-panel-label">Guides</div>
                                        <label class="bg-check">
                                            <input type="checkbox" :checked="shapeEdit.grid" @change="toggleShapeGrid()">
                                            Show grid
                                        </label>
                                        <label class="bg-check">
                                            <input type="checkbox" :checked="shapeEdit.snap" @change="toggleShapeSnap()">
                                            Snap to grid &amp; straighten walls
                                        </label>
                                        <div class="bg-zoom" :class="{disabled:!shapeEdit.grid && !shapeEdit.snap}">
                                            <span style="font-size:10px;color:var(--text-muted);font-weight:700">GRID</span>
                                            <input type="range" min="6" max="50" step="1" :value="shapeEdit.gridSize" @input="setGridSize($event.target.value)">
                                            <span style="font-size:11px;color:var(--text-muted);min-width:42px;text-align:right" x-text="shapeEdit.gridSize + '×' + shapeEdit.gridSize"></span>
                                        </div>
                                    </div>

                                    <div class="shape-panel-sec">
                                        <div class="shape-panel-label" x-text="'Points · ' + shapeEdit.points.length"></div>
                                        <div class="shape-edit-actions">
                                            <button class="btn tiny" @click="undoShapePoint()" :disabled="!shapeEdit.points.length">Undo</button>
                                            <button class="btn tiny" @click="resetShapeToRect()">Rectangle</button>
                                            <button class="btn tiny" @click="clearShapePoints()" :disabled="!shapeEdit.points.length">Clear</button>
                                        </div>
                                        <div class="shape-edit-actions">
                                            <button class="btn tiny" @click="centerShape()" :disabled="shapeEdit.points.length<3" title="Move the shape to the middle (keep its size)">Center</button>
                                            <button class="btn tiny" @click="fitShape()" :disabled="shapeEdit.points.length<3" title="Center and scale the shape to fill the canvas">Fit</button>
                                        </div>
                                        <button class="btn tiny" x-show="currentRoom && currentRoom.room_shape" @click="clearSavedShape()" style="color:var(--red)">Remove saved shape</button>
                                    </div>
                                </div>
                                <div class="trace-col-foot">
                                    <button class="btn ghost" @click="cancelShapeEdit()">Cancel</button>
                                    <button class="btn primary" @click="saveShape()">Save shape</button>
                                </div>
                            </div>
                        </template>

                        <div class="room-panel-head" x-show="!shapeEdit.active">
                            <div class="room-panel-title">
                                <span x-text="currentRoom?.room_name"></span>
                            </div>
                            <div class="room-panel-sub">
                                <span x-show="currentRoom?.department" x-text="currentRoom?.department + ' · '"></span>
                                <span x-show="currentRoom?.capacity" x-text="'cap ' + currentRoom?.capacity + ' · '"></span>
                                <span x-text="devicesForCurrentRoom.length + ' devices'"></span>
                            </div>
                        </div>
                        <div class="room-panel-tabs" x-show="!shapeEdit.active">
                            <button class="room-panel-tab" :class="{active:panelTab==='devices'}" @click="panelTab='devices'">Devices</button>
                            <button class="room-panel-tab" :class="{active:panelTab==='people'}" @click="openPeopleTab()">People</button>
                            <button class="room-panel-tab" :class="{active:panelTab==='info'}" @click="panelTab='info'">Info</button>
                        </div>
                        <div class="room-panel-body" x-show="!shapeEdit.active">

                            <!-- Devices list -->
                            <div x-show="panelTab==='devices'">
                                <template x-if="devicesForCurrentRoom.length===0">
                                    <div class="room-panel-empty">No devices yet.<span class="edit-only"><br>Click <strong>+ Add Device</strong> above.</span></div>
                                </template>
                                <template x-for="dev in devicesForCurrentRoom" :key="dev.device_id">
                                    <div class="device-row" :class="{selected:selectedDeviceId===dev.device_id, dragging:listDrag.active && listDrag.dev && listDrag.dev.device_id===dev.device_id}"
                                         @pointerdown="startDeviceListDrag(dev, $event)"
                                         :title="deviceEditMode ? 'Drag onto the map to place / move' : (deviceEditMode ? 'Click to edit details' : 'Click to select')">
                                        <div class="device-icon" :style="'--device-color:'+typeColor(dev.device_type_key)" x-html="typeIconSvg(dev.device_type_key)"></div>
                                        <div class="device-row-text">
                                            <div class="device-row-name" x-text="dev.device_name"></div>
                                            <div class="device-row-meta">
                                                <span x-text="typeName(dev.device_type_key)"></span>
                                                <span x-show="dev.model" x-text="' · ' + dev.model"></span>
                                                <span x-show="dev.asset_tag" x-text="' · ' + dev.asset_tag"></span>
                                            </div>
                                        </div>
                                        <!-- Placed/unplaced status; in Move mode the "placed" chip becomes a guarded Unplace control -->
                                        <template x-if="!(deviceEditMode && isDevicePlaced(dev))">
                                            <span class="device-row-flag" :class="isDevicePlaced(dev)?'placed':'unplaced'" x-text="isDevicePlaced(dev)?'placed':'unplaced'"></span>
                                        </template>
                                        <template x-if="deviceEditMode && isDevicePlaced(dev)">
                                            <button class="unplace-btn"
                                                    :class="{armed: unplaceConfirmId===dev.device_id}"
                                                    @pointerdown.stop.prevent=""
                                                    @click.stop.prevent="requestUnplace(dev, $event)"
                                                    :title="unplaceConfirmId===dev.device_id ? 'Click again to confirm' : 'Remove this device from the map'">
                                                <template x-if="unplaceConfirmId!==dev.device_id">
                                                    <span class="upb-inner">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><line x1="9" y1="10" x2="15" y2="10"/></svg>
                                                        Unplace
                                                    </span>
                                                </template>
                                                <template x-if="unplaceConfirmId===dev.device_id">
                                                    <span class="upb-inner">Confirm?</span>
                                                </template>
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                <!-- Printers: this site's printers — drag any onto the room diagram -->
                                <template x-if="printersEnabled">
                                    <div class="room-printers-sec edit-only">
                                        <div class="rps-head">Printers <span class="muted-note" style="font-weight:400">— drag into the room</span></div>
                                        <template x-if="!availablePrintersForRoom.length">
                                            <div class="room-panel-empty" style="padding:10px 4px">No printers for this site yet.<br>Import or add printers, then drag them in.</div>
                                        </template>
                                        <template x-for="pr in availablePrintersForRoom" :key="'avpr-'+pr.printer_id">
                                            <div class="device-row" :class="{dragging: listDrag.active && listDrag.printer && listDrag.printer.printer_id===pr.printer_id, placed: printerInThisRoom(pr)}"
                                                 @pointerdown="startPrinterRoomListDrag(pr, $event)"
                                                 :title="deviceEditMode ? 'Drag onto the room to place' : 'Turn on Move Devices, then drag in'">
                                                <div class="device-icon" style="--device-color:#0d9488"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg></div>
                                                <div class="device-row-text">
                                                    <div class="device-row-name" x-text="pr.printer_name"></div>
                                                    <div class="device-row-meta"><span x-text="pr.model || 'Printer'"></span><span x-show="pr.location" x-text="' · ' + pr.location"></span></div>
                                                </div>
                                                <template x-if="!(deviceEditMode && printerInThisRoom(pr))">
                                                    <span class="device-row-flag" :class="printerInThisRoom(pr) ? 'placed' : 'unplaced'" x-text="printerInThisRoom(pr) ? 'in room' : 'drag in'"></span>
                                                </template>
                                                <template x-if="deviceEditMode && printerInThisRoom(pr)">
                                                    <button class="unplace-btn" @pointerdown.stop.prevent="" @click.stop.prevent="unassignPrinterRoom(pr)" title="Remove this printer from the room">
                                                        <span class="upb-inner">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><line x1="9" y1="10" x2="15" y2="10"/></svg>
                                                            Unplace
                                                        </span>
                                                    </button>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <!-- Info -->
                            <!-- People management -->
                            <div x-show="panelTab==='people'">
                                <!-- Editable version (base-layer editors only) -->
                                <div class="people-manage edit-only" x-show="can('base','edit')">
                                    <div class="field" style="margin-bottom:10px">
                                        <label>Room phone ext.</label>
                                        <input type="text" x-model="peopleEditor.room_extension" placeholder="e.g. 10700">
                                    </div>
                                    <div class="field" style="margin-bottom:10px">
                                        <label>Notes / alert</label>
                                        <input type="text" x-model="peopleEditor.room_notes" placeholder="e.g. Projector broken">
                                    </div>
                                    <div class="modal-field-label">People &amp; extensions</div>
                                    <div class="pe-rows" style="margin-top:6px">
                                        <template x-for="(p, idx) in peopleEditor.occupants" :key="idx">
                                            <div class="pe-row">
                                                <button class="pe-primary" :class="{on:p.is_primary}" @click="pmSetPrimary(idx)" :title="p.is_primary?'Primary':'Set primary'">★</button>
                                                <input type="text" class="pe-name" x-model="p.name" placeholder="Name">
                                                <input type="text" class="pe-role" x-model="p.role" placeholder="Role">
                                                <input type="text" class="pe-ext" x-model="p.extension" placeholder="ext.">
                                                <input type="text" class="pe-email" x-model="p.email" placeholder="email">
                                                <button class="pe-del" @click="pmRemove(idx)" title="Remove">×</button>
                                            </div>
                                        </template>
                                        <div class="pe-empty" x-show="!peopleEditor.occupants.length">No people yet.</div>
                                    </div>
                                    <div style="display:flex;gap:8px;margin-top:10px">
                                        <button class="btn tiny" @click="pmAdd()">+ Add person</button>
                                        <div style="flex:1"></div>
                                        <button class="btn save" @click="savePeople()">Save people</button>
                                    </div>
                                </div>

                                <!-- Read-only version (no base edit) -->
                                <div class="people-readonly" x-show="!can('base','edit')">
                                    <div x-show="currentRoom?.room_extension" style="margin-bottom:12px">
                                        <div class="modal-field-label">Room phone</div>
                                        <div class="modal-field-value" x-text="'ext. ' + (currentRoom?.room_extension || '')"></div>
                                    </div>
                                    <div x-show="currentRoom?.room_notes" class="ro-alert" style="margin-bottom:12px">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                        <span x-text="currentRoom?.room_notes"></span>
                                    </div>
                                    <div class="modal-field-label">People &amp; extensions</div>
                                    <div class="ro-people" style="margin-top:6px">
                                        <template x-for="p in (currentRoom?.occupants || [])" :key="p.occupant_id">
                                            <div class="ro-person">
                                                <span class="ro-person-star" x-show="p.is_primary" title="Primary contact">★</span>
                                                <div class="ro-person-text">
                                                    <span class="ro-person-name" x-text="p.name"></span>
                                                    <span class="ro-person-role" x-show="p.role" x-text="p.role"></span>
                                                </div>
                                                <span class="ro-person-ext" x-show="p.extension" x-text="'ext. ' + p.extension"></span>
                                            </div>
                                        </template>
                                        <div class="pe-empty" x-show="!(currentRoom?.occupants || []).length">No people listed for this room.</div>
                                    </div>
                                </div>
                            </div>

                            <div x-show="panelTab==='info'">
                                <div style="display:flex;flex-direction:column;gap:10px;font-size:13px">
                                    <div>
                                        <div class="modal-field-label">Type</div>
                                        <div class="modal-field-value" x-text="currentRoom?.room_type || '—'"></div>
                                    </div>
                                    <div>
                                        <div class="modal-field-label">Room number</div>
                                        <div class="modal-field-value" x-text="currentRoom?.room_number || '—'"></div>
                                    </div>
                                    <div>
                                        <div class="modal-field-label">Department</div>
                                        <div class="modal-field-value" x-text="currentRoom?.department || '—'"></div>
                                    </div>
                                    <div x-show="currentRoom?.room_extension">
                                        <div class="modal-field-label">Room phone</div>
                                        <div class="modal-field-value" x-text="'ext. ' + (currentRoom?.room_extension || '')"></div>
                                    </div>
                                    <div>
                                        <div class="modal-field-label">Level</div>
                                        <div class="modal-field-value" x-text="formatLevel(currentRoom?.map_level || '')"></div>
                                    </div>
                                    <div x-show="currentRoom?.description">
                                        <div class="modal-field-label">Description</div>
                                        <div class="modal-desc" x-text="currentRoom?.description"></div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <!-- Device editor (admin) -->
                <div class="device-editor" x-show="deviceEditor.open" x-cloak>
                    <div class="edit-panel-head">
                        <div>
                            <div class="edit-panel-title" x-text="deviceEditor.device_id ? 'Edit Device' : 'New Device'"></div>
                            <div class="edit-panel-help">After saving, click on the canvas to place the device. Click again on the device to reposition it.</div>
                        </div>
                    </div>
                    <div class="edit-panel-body">
                        <div class="field">
                            <label>Name *</label>
                            <input type="text" x-model="deviceEditor.device_name" placeholder="e.g. Front office printer">
                        </div>
                        <div class="field">
                            <label>Type *</label>
                            <div class="ddown" :class="{open}" x-data="ddown('deviceEditor.device_type_key', 'deviceTypes', {valueKey:'key', labelKey:'name', placeholder:'— pick a type —'})" @click.outside="open=false">
                                <button type="button" class="ddown-trigger" @click="open=!open">
                                    <span x-text="label"></span>
                                    <span class="caret">▾</span>
                                </button>
                                <div class="ddown-panel">
                                    <template x-for="t in items" :key="t.key">
                                        <div class="ddown-opt" :class="{active:isActive(t)}"
                                             @click="pick(t)" x-text="t.name"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <label>Status</label>
                            <div class="ddown" :class="{open}" x-data="ddown('deviceEditor.status', [
                                    {v:'active', n:'Active'},
                                    {v:'offline', n:'Offline'},
                                    {v:'maintenance', n:'Maintenance'},
                                    {v:'retired', n:'Retired'},
                                ])" @click.outside="open=false">
                                <button type="button" class="ddown-trigger" @click="open=!open">
                                    <span x-text="label"></span>
                                    <span class="caret">▾</span>
                                </button>
                                <div class="ddown-panel">
                                    <template x-for="o in items" :key="o.v">
                                        <div class="ddown-opt" :class="{active:isActive(o)}"
                                             @click="pick(o)" x-text="o.n"></div>
                                    </template>
                                </div>
                            </div>
                        </div>
                        <div class="field">
                            <label>Asset tag</label>
                            <input type="text" x-model="deviceEditor.asset_tag" placeholder="e.g. AS-12345">
                        </div>
                        <div class="field">
                            <label>Model</label>
                            <input type="text" x-model="deviceEditor.model" placeholder="e.g. HP M404n">
                        </div>
                        <div class="field">
                            <label>Serial number</label>
                            <input type="text" x-model="deviceEditor.serial_number">
                        </div>
                        <div class="field">
                            <label>IP address</label>
                            <input type="text" x-model="deviceEditor.ip_address" placeholder="10.0.0.10">
                        </div>
                        <div class="field" style="grid-column:1/-1">
                            <label>Notes</label>
                            <textarea x-model="deviceEditor.notes"></textarea>
                        </div>
                    </div>
                    <div class="edit-panel-foot">
                        <button class="btn danger" x-show="deviceEditor.device_id" @click="deleteDevice()">Delete</button>
                        <div class="spacer"></div>
                        <button class="btn" @click="closeDeviceEditor()">Cancel</button>
                        <button class="btn save" @click="saveDevice()">Save Device</button>
                    </div>
                </div>

            </div>


        <!-- (relocated into the main content column, alongside Home/Sites/Cameras, so it lays out full-width instead of squeezed into leftover flex space) -->
    <!-- AUDIT LOG (admin) -->
    <div x-show="view==='audit'" x-cloak>
        <h1 class="page-title">Audit log</h1>
        <p class="page-subtitle">Sign-ins, user changes, settings, and data edits — newest first.</p>
        <div class="modal-body">
                <div class="user-toolbar">
                    <div class="user-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="text" x-model.debounce.400ms="auditModal.q" @input="auditModal.page=1; loadAudit()" placeholder="Search actor, target, IP, action…">
                        <button class="us-clear" x-show="auditModal.q" @click="auditModal.q=''; auditModal.page=1; loadAudit()" title="Clear">×</button>
                    </div>
                    <div class="ddown" :class="{open}" x-data="{open:false}" @click.outside="open=false">
                        <button type="button" class="ddown-trigger compact" @click="open=!open">
                            <span x-text="auditModal.actionFilter ? auditLabel(auditModal.actionFilter) : 'All events'"></span><span class="caret">▾</span>
                        </button>
                        <div class="ddown-panel" style="max-height:300px;overflow-y:auto">
                            <div class="ddown-opt" :class="{active:!auditModal.actionFilter}" @click="auditModal.actionFilter=''; auditModal.page=1; loadAudit(); open=false">All events</div>
                            <template x-for="k in auditModal.kinds" :key="k">
                                <div class="ddown-opt" :class="{active:auditModal.actionFilter===k}" @click="auditModal.actionFilter=k; auditModal.page=1; loadAudit(); open=false" x-text="auditLabel(k)"></div>
                            </template>
                        </div>
                    </div>
                    <button class="btn tiny ghost" @click="loadAudit()" title="Refresh">↻</button>
                </div>

                <div class="audit-list">
                    <template x-for="e in auditModal.events" :key="e.audit_id">
                        <div class="audit-row">
                            <div class="audit-icon" :class="'evt-'+(e.action.split('.')[0])" x-html="auditIcon(e.action)"></div>
                            <div class="audit-main">
                                <div class="audit-line">
                                    <span class="audit-action" x-text="auditLabel(e.action)"></span>
                                    <span class="audit-target" x-show="e.target_label" x-text="e.target_label"></span>
                                </div>
                                <div class="audit-meta">
                                    <span class="audit-actor" x-text="e.actor_name || 'system'"></span>
                                    <span x-show="e.ip_address" x-text="'· ' + e.ip_address"></span>
                                    <span x-text="'· ' + auditTime(e.created_at)"></span>
                                </div>
                                <div class="audit-details" x-show="e.details && hasDetailKeys(e.details)" x-text="formatAuditDetails(e.details)"></div>
                            </div>
                        </div>
                    </template>
                    <div class="utable-empty" x-show="!auditModal.events.length && !auditModal.loading">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                        <span x-text="auditModal.q || auditModal.actionFilter ? 'No matching events.' : 'No audit events yet.'"></span>
                    </div>
                    <div class="utable-empty" x-show="auditModal.loading"><span>Loading…</span></div>
                </div>

                <div class="audit-foot" x-show="auditModal.pages > 1">
                    <button class="btn tiny" :disabled="auditModal.page<=1" @click="auditModal.page--; loadAudit()">← Prev</button>
                    <span class="audit-page" x-text="'Page ' + auditModal.page + ' of ' + auditModal.pages + ' · ' + auditModal.total + ' events'"></span>
                    <button class="btn tiny" :disabled="auditModal.page>=auditModal.pages" @click="auditModal.page++; loadAudit()">Next →</button>
                </div>
                <div class="audit-foot" x-show="auditModal.pages <= 1 && auditModal.events.length">
                    <span class="audit-page" x-text="auditModal.total + ' event' + (auditModal.total===1?'':'s')"></span>
                </div>
            </div>
    </div>

        <!-- (relocated into the main content column, alongside Home/Sites/Cameras, so it lays out full-width instead of squeezed into leftover flex space) -->
    <!-- ============ DATA EDITOR MODAL (db_admin only) ============ -->
    <div x-show="view==='data_editor'" x-cloak>
        <h1 class="page-title">Data editor</h1>
        <p class="page-subtitle">Direct table editing — changes are immediate and audited. Handle with care.</p>
        <div class="de-body">
                <div class="de-tablebar">
                    <template x-for="tb in dataEditor.tables" :key="'tab-'+tb.table">
                        <button class="de-tab" :class="{active: dataEditor.current===tb.table}" @click="deSelectTable(tb.table)" x-text="tb.table"></button>
                    </template>
                </div>
                <div class="de-toolbar" x-show="dataEditor.current">
                    <input type="text" x-model="dataEditor.q" @input.debounce.300ms="deLoadRows(1)" placeholder="Search…" class="de-search">
                    <select class="de-site-filter" x-show="deCurrentDef && deCurrentDef.has_site" x-model="dataEditor.site" @change="deLoadRows(1)">
                        <option value="">All sites</option>
                        <template x-for="s in sites" :key="'desf-'+s.id"><option :value="s.id" x-text="s.name"></option></template>
                    </select>
                    <span class="de-count" x-show="dataEditor.total" x-text="dataEditor.total + ' rows'"></span>
                    <div style="flex:1"></div>
                    <button class="btn save" x-show="can('data_admin','manage')" @click="deNewRow()">+ New row</button>
                    <span class="muted-note" x-show="!can('data_admin','manage')">View only — read access</span>
                </div>
                <div class="de-table-wrap" x-show="dataEditor.current">
                    <table class="de-table" x-show="dataEditor.rows.length">
                        <thead><tr>
                            <th class="de-th-sort" @click="deSort(dePk)">
                                <span x-text="dePk"></span><span class="de-sort-ar" x-show="dataEditor.sort===dePk" x-text="dataEditor.dir==='asc'?'▲':'▼'"></span>
                            </th>
                            <template x-for="c in deCols" :key="'h-'+c.name">
                                <th class="de-th-sort" @click="deSort(c.name)">
                                    <span x-text="c.name"></span><span class="de-sort-ar" x-show="dataEditor.sort===c.name" x-text="dataEditor.dir==='asc'?'▲':'▼'"></span>
                                </th>
                            </template>
                            <th></th>
                        </tr></thead>
                        <tbody>
                            <template x-for="row in dataEditor.rows" :key="'r-'+row[dePk]">
                                <tr>
                                    <td class="de-pk" x-text="row[dePk]"></td>
                                    <template x-for="c in deCols" :key="'c-'+row[dePk]+'-'+c.name">
                                        <td x-text="deCellText(row[c.name], c.type, c.name)" :title="row[c.name]"></td>
                                    </template>
                                    <td class="de-actions">
                                        <button class="de-mini" x-show="can('data_admin','manage')" @click="deEditRow(row)" title="Edit">✎</button>
                                        <button class="de-mini danger" x-show="can('data_admin','manage')" @click="deDeleteRow(row)" title="Delete">🗑</button>
                                        <span class="muted-note" x-show="!can('data_admin','manage')">—</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="de-empty" x-show="!dataEditor.rows.length && !dataEditor.busy">No rows.</div>
                    <div class="de-empty" x-show="dataEditor.busy">Loading…</div>
                </div>
                <div class="de-pager" x-show="dataEditor.total > dataEditor.per">
                    <button class="btn" :disabled="dataEditor.page<=1" @click="deLoadRows(dataEditor.page-1)">← Prev</button>
                    <span x-text="'Page ' + dataEditor.page + ' of ' + Math.ceil(dataEditor.total/dataEditor.per)"></span>
                    <button class="btn" :disabled="dataEditor.page >= Math.ceil(dataEditor.total/dataEditor.per)" @click="deLoadRows(dataEditor.page+1)">Next →</button>
                </div>
            </div>
    </div>

        <!-- (relocated into the main content column, alongside Home/Sites/Cameras, so it lays out full-width instead of squeezed into leftover flex space) -->
    <!-- USER MANAGEMENT MODAL (admin) -->
    <div x-show="view==='users'" x-cloak>
        <div style="display:flex;align-items:flex-start;gap:8px">
            <button class="modal-back" x-show="userForm.open" @click="closeUserSubview()" title="Back to list">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            </button>
            <div>
                <h1 class="page-title" x-text="userForm.open ? (userForm.public_id ? 'Edit user' : (userForm.mode==='invite' ? 'Invite user' : 'New user')) : 'Users &amp; access'"></h1>
                <p class="page-subtitle" x-text="userForm.open ? (userForm.public_id ? 'Update their groups, password, and access.' : (userForm.mode==='invite' ? 'They set their own password via an emailed link.' : 'Set their password and group memberships.')) : 'Manage who can sign in, their groups, and access.'"></p>
            </div>
        </div>
            <div class="um-tabs" x-show="!userForm.open">
                <button class="um-tab" :class="{active: usersModal.tab==='users'}" @click="usersModal.tab='users'">Users</button>
                <button class="um-tab" :class="{active: usersModal.tab==='groups'}" @click="usersModal.tab='groups'; loadGroups()">Groups</button>
            </div>
            <div class="modal-body" x-show="usersModal.tab==='groups' && !userForm.open">
                <!-- ============ GROUPS TAB ============ -->
                <div class="ug-layout">
                    <div class="ug-list">
                        <button class="btn save" style="width:100%;margin-bottom:10px" @click="newGroup()">+ New group</button>
                        <template x-for="g in permGroups" :key="'grp-'+g.group_id">
                            <button class="ug-row" :class="{active: groupEdit.group_id===g.group_id}" @click="openGroup(g)">
                                <div class="ug-row-main">
                                    <span class="ug-row-name" x-text="g.name"></span>
                                    <span class="ug-row-sys" x-show="g.is_system" title="Starter group">system</span>
                                </div>
                                <div class="ug-row-meta"><span x-text="g.member_count + ' member' + (g.member_count===1?'':'s')"></span> · <span x-text="g.grant_count + ' grant' + (g.grant_count===1?'':'s')"></span></div>
                            </button>
                        </template>
                        <div class="muted-note" x-show="!permGroups.length" style="padding:10px">No groups yet.</div>
                    </div>
                    <div class="ug-detail" x-show="groupEdit.open">
                        <div class="ug-detail-head">
                            <input type="text" class="ug-name-input" x-model="groupEdit.name" placeholder="Group name">
                            <button class="btn danger" x-show="groupEdit.group_id && !groupEdit.is_system" @click="deleteGroup()">Delete</button>
                        </div>
                        <input type="text" class="ug-desc-input" x-model="groupEdit.description" placeholder="Description (optional)">
                        <div class="ug-grants-head">Grants <span class="muted-note">— what this group can do</span></div>
                        <div class="ug-grants">
                            <template x-for="(gr, idx) in groupEdit.grants" :key="'gg-'+idx">
                                <div class="grant-row-wrap">
                                <div class="grant-row">
                                    <select x-model="gr.layer" class="grant-sel" @change="gr.scope_type = isAdminCap(gr.layer) ? 'all' : gr.scope_type; gr.level = levelsFor(gr.layer).includes(gr.level) ? gr.level : 'view'">
                                        <optgroup label="Data layers">
                                            <option value="base" x-text="layerLabel('base')"></option>
                                            <option value="cameras" x-text="layerLabel('cameras')"></option>
                                            <option value="printers" x-text="layerLabel('printers')"></option>
                                            <option value="devices" x-text="layerLabel('devices')"></option>
                                        </optgroup>
                                        <optgroup label="Admin capabilities">
                                            <option value="audit" x-text="layerLabel('audit')"></option>
                                            <option value="settings" x-text="layerLabel('settings')"></option>
                                            <option value="manage_users" x-text="layerLabel('manage_users')"></option>
                                            <option value="data_admin" x-text="layerLabel('data_admin')"></option>
                                            <option value="notifications" x-text="layerLabel('notifications')"></option>
                                        </optgroup>
                                    </select>
                                    <select x-model="gr.level" class="grant-sel">
                                        <option value="view" x-text="levelLabel('view')"></option>
                                        <option value="edit" x-show="!isAdminCap(gr.layer)" x-text="levelLabel('edit')"></option>
                                        <option value="manage" x-text="levelLabel('manage')"></option>
                                        <option value="admin" x-show="!isAdminCap(gr.layer)" x-text="levelLabel('admin')"></option>
                                    </select>
                                    <select x-model="gr.scope_type" class="grant-sel" :disabled="isAdminCap(gr.layer)" x-show="!isAdminCap(gr.layer)" @change="gr.scope_id = null">
                                        <option value="all">at all sites</option>
                                        <option value="site">at one site…</option>
                                        <option value="device" x-show="gr.layer==='cameras'">at one camera…</option>
                                    </select>
                                    <select x-model.number="gr.scope_id" class="grant-sel" x-show="gr.scope_type==='site' && !isAdminCap(gr.layer)">
                                        <option :value="null">— pick a site —</option>
                                        <template x-for="s in sites" :key="'gs-'+s.id"><option :value="s.id" x-text="s.name"></option></template>
                                    </select>
                                    <select x-model.number="gr._pickSite" class="grant-sel" x-show="gr.scope_type==='device' && gr.layer==='cameras'">
                                        <option :value="null">— site —</option>
                                        <template x-for="s in sites" :key="'gds-'+s.id"><option :value="s.id" x-text="s.name"></option></template>
                                    </select>
                                    <button class="grant-del" @click="groupEdit.grants.splice(idx,1)" title="Remove">✕</button>
                                </div>
                                <div class="cam-checklist" x-show="gr.scope_type==='device' && gr.layer==='cameras' && gr._pickSite">
                                    <div class="cam-checklist-head">
                                        <span x-text="'Cameras at ' + siteName(gr._pickSite, '')"></span>
                                        <span class="cam-check-actions">
                                            <button class="linkbtn" @click="gr.scope_ids = camerasForSite(gr._pickSite).map(c=>Number(c.camera_number))">All</button>
                                            <button class="linkbtn" @click="gr.scope_ids = []">None</button>
                                        </span>
                                    </div>
                                    <div class="cam-check-grid">
                                        <template x-for="c in camerasForSite(gr._pickSite)" :key="'gck-'+c.camera_number">
                                            <label class="cam-check" :class="{on: (gr.scope_ids||[]).includes(Number(c.camera_number))}">
                                                <input type="checkbox" :checked="(gr.scope_ids||[]).includes(Number(c.camera_number))" @change="toggleCamScope(gr, Number(c.camera_number))">
                                                <span x-text="c.camera_name"></span>
                                            </label>
                                        </template>
                                        <div class="muted-note" x-show="!camerasForSite(gr._pickSite).length">No cameras at this site.</div>
                                    </div>
                                </div>
                                <div class="grant-summary" x-text="grantSummary(gr)"></div>
                                </div>
                            </template>
                            <button class="btn" style="margin-top:6px" @click="addGrant(groupEdit.grants)">+ Add grant</button>
                        </div>
                        <div class="ug-members" x-show="groupEdit.group_id">
                            <div class="ug-grants-head">Members <span class="muted-note" x-text="'(' + groupEdit.members.length + ')'"></span></div>
                            <div class="ug-member-chips">
                                <template x-for="m in groupEdit.members" :key="'mem-'+m.public_id"><span class="ug-chip" x-text="m.display_name || m.username"></span></template>
                                <span class="muted-note" x-show="!groupEdit.members.length">No members. Add users from the Users tab.</span>
                            </div>
                        </div>
                        <div class="modal-foot">
                            <div style="flex:1"></div>
                            <button class="btn" @click="groupEdit.open=false">Close</button>
                            <button class="btn save" @click="saveGroup()">Save group</button>
                        </div>
                    </div>
                    <div class="ug-detail ug-empty" x-show="!groupEdit.open">
                        <p class="muted-note">Select a group to edit its grants, or create a new one.</p>
                    </div>
                </div>
            </div>
            <div class="modal-body" x-show="usersModal.tab==='users' || userForm.open">
                <!-- ============ LIST VIEW ============ -->
                <div x-show="!userForm.open">
                    <!-- Summary stat chips (also act as quick role/status filters) -->
                    <div class="ustat-row">
                        <button class="ustat" :class="{active: usersModal.roleFilter==='all' && usersModal.statusFilter==='all'}"
                                @click="usersModal.roleFilter='all'; usersModal.statusFilter='all'">
                            <span class="ustat-n" x-text="userStats.total"></span><span class="ustat-l">Total</span>
                        </button>
                        <button class="ustat" :class="{active: usersModal.roleFilter==='admin'}" @click="usersModal.roleFilter = usersModal.roleFilter==='admin'?'all':'admin'">
                            <span class="ustat-n" x-text="userStats.admin"></span><span class="ustat-l">Admins</span>
                        </button>
                        <button class="ustat" :class="{active: usersModal.roleFilter==='editor'}" @click="usersModal.roleFilter = usersModal.roleFilter==='editor'?'all':'editor'">
                            <span class="ustat-n" x-text="userStats.editor"></span><span class="ustat-l">Editors</span>
                        </button>
                        <button class="ustat" :class="{active: usersModal.roleFilter==='viewer'}" @click="usersModal.roleFilter = usersModal.roleFilter==='viewer'?'all':'viewer'">
                            <span class="ustat-n" x-text="userStats.viewer"></span><span class="ustat-l">Viewers</span>
                        </button>
                        <button class="ustat" :class="{active: usersModal.statusFilter==='disabled'}" @click="usersModal.statusFilter = usersModal.statusFilter==='disabled'?'all':'disabled'">
                            <span class="ustat-n" x-text="userStats.disabled"></span><span class="ustat-l">Disabled</span>
                        </button>
                    </div>

                    <!-- Toolbar: search · status · sort · new -->
                    <div class="user-toolbar">
                        <div class="user-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <input type="text" x-model="usersModal.search" placeholder="Search name, username, role…">
                            <button class="us-clear" x-show="usersModal.search" @click="usersModal.search=''" title="Clear">×</button>
                        </div>
                        <div class="ddown" :class="{open}" x-data="ddown('usersModal.statusFilter', [
                                {v:'all', n:'All status'},{v:'active', n:'Active only'},{v:'disabled', n:'Disabled only'}
                            ])" @click.outside="open=false">
                            <button type="button" class="ddown-trigger compact" @click="open=!open">
                                <span x-text="label"></span><span class="caret">▾</span>
                            </button>
                            <div class="ddown-panel">
                                <template x-for="o in items" :key="o.v">
                                    <div class="ddown-opt" :class="{active:isActive(o)}" @click="pick(o)" x-text="o.n"></div>
                                </template>
                            </div>
                        </div>
                        <div class="ddown" :class="{open}" x-data="ddown('usersModal.sortBy', [
                                {v:'name', n:'Name A–Z'},{v:'role', n:'Role'},{v:'login', n:'Last login'},{v:'created', n:'Recently added'}
                            ])" @click.outside="open=false">
                            <button type="button" class="ddown-trigger compact" @click="open=!open">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:13px;height:13px;margin-right:5px"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                                <span x-text="label"></span><span class="caret">▾</span>
                            </button>
                            <div class="ddown-panel">
                                <template x-for="o in items" :key="o.v">
                                    <div class="ddown-opt" :class="{active:isActive(o)}" @click="pick(o)" x-text="o.n"></div>
                                </template>
                            </div>
                        </div>
                        <button class="btn" @click="openInvite()">Invite</button>
                        <button class="btn save" @click="newUser()">+ New</button>
                    </div>

                    <!-- Bulk action bar (only when something is selected) -->
                    <div class="bulk-bar" x-show="usersModal.selected.length" x-transition x-cloak>
                        <span class="bulk-count" x-text="usersModal.selected.length + ' selected'"></span>
                        <div style="flex:1"></div>
                        <div class="ddown" :class="{open}" x-data="{open:false}" @click.outside="open=false">
                            <button type="button" class="btn tiny" @click="open=!open">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:13px;height:13px;margin-right:4px;vertical-align:-2px"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                                Add to group <span class="caret">▾</span>
                            </button>
                            <div class="ddown-panel" style="max-height:240px;overflow-y:auto">
                                <template x-for="g in permGroups" :key="'bag-'+g.group_id">
                                    <div class="ddown-opt" @click="open=false; bulkAddToGroup(g.group_id)" x-text="g.name"></div>
                                </template>
                                <div class="ddown-opt" style="opacity:.6;pointer-events:none" x-show="!permGroups.length">No groups yet</div>
                            </div>
                        </div>
                        <button class="btn tiny" @click="bulkAction('activate')">Activate</button>
                        <button class="btn tiny" @click="bulkAction('deactivate')">Disable</button>
                        <button class="btn tiny danger" @click="bulkAction('delete')">Delete</button>
                        <button class="btn tiny ghost" @click="clearSelection()">Clear</button>
                    </div>

                    <!-- Table header -->
                    <div class="utable-head">
                        <label class="ucheck"><input type="checkbox" :checked="allVisibleSelected" @change="toggleSelectAll()"></label>
                        <span class="uth-user">User</span>
                        <span class="uth-access">Access</span>
                        <span class="uth-login">Last login</span>
                        <span class="uth-act"></span>
                    </div>

                    <!-- Rows -->
                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" id="adminAvatarInput" style="display:none" @change="adminUploadAvatar($event)">
                    <div class="user-table">
                        <template x-for="u in filteredUsers" :key="u.public_id">
                            <div class="user-row" :class="{selected: isSelected(u.public_id), dim: !u.is_active}">
                                <label class="ucheck">
                                    <input type="checkbox" :checked="isSelected(u.public_id)" :disabled="u.public_id===currentUserId" @change="toggleSelect(u.public_id)">
                                </label>
                                <div class="ur-user">
                                    <div class="ur-avatar ur-avatar-neutral" x-show="!u.profile_image" x-text="(u.display_name||u.username).slice(0,1).toUpperCase()"></div>
                                    <!-- template x-if (not x-show): a hidden img still FETCHES its src, so
                                         every photo-less user fired a doomed request per page load. -->
                                    <template x-if="u.profile_image">
                                        <img class="ur-avatar ur-avatar-img" :src="'?api=image&action=serve&kind=avatar&id='+encodeURIComponent(u.public_id||'')+'&v='+encodeURIComponent(u.profile_image||'')" :title="u.username+' \u2022 '+(u.public_id||'').slice(0,8)+' \u2022 '+(u.profile_image||'')" alt="">
                                    </template>
                                    <div class="ur-main">
                                        <div class="ur-name">
                                            <span class="ur-name-text" x-text="u.display_name || u.username"></span>
                                            <span class="ur-you" x-show="u.public_id===currentUserId">you</span>
                                            <span class="ur-inactive" x-show="!u.is_active">disabled</span>
                                            <span class="ur-locked" x-show="u.is_locked" title="Account locked from too many failed logins">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                locked
                                            </span>
                                            <span class="ur-pending" x-show="u.invite_status==='invited'" title="Invitation sent — not yet activated">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z" fill="none"/><path d="M22 6l-10 7L2 6"/></svg>
                                                invited
                                            </span>
                                        </div>
                                        <div class="ur-sub">
                                            <span x-text="'@'+u.username"></span>
                                            <template x-for="gn in (u.groups||[])" :key="'urg-'+u.public_id+'-'+gn.group_id">
                                                <span class="ur-group-badge" x-text="gn.name"></span>
                                            </template>
                                            <span class="ur-group-badge ur-group-none" x-show="!(u.groups||[]).length">no groups</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="ur-meta">
                                <span class="ur-access" :title="(u.groups||[]).map(g=>g.name).join(', ')">
                                    <span class="ur-access-n" x-text="(u.groups||[]).length"></span>
                                    <span class="ur-access-l" x-text="(u.groups||[]).length===1?'group':'groups'"></span>
                                    <span class="mfa-badge" x-show="u.mfa_enabled" title="Two-factor enabled">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    </span>
                                </span>
                                <span class="ur-login" x-text="relativeTime(u.last_login)"></span>
                                </div>
                                <div class="ur-actions">
                                    <button class="ur-icon" x-show="u.invite_status==='invited'" @click="resendInvite(u)" title="Resend invite">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z" fill="none"/><path d="M22 6l-10 7L2 6"/></svg>
                                    </button>
                                    <button class="ur-icon" x-show="u.invite_status==='invited'" @click="revokeInvite(u)" title="Revoke invite">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                    </button>
                                    <button class="ur-icon unlock" x-show="u.is_locked" @click="unlockUser(u)" title="Unlock account">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/></svg>
                                    </button>
                                    <button class="ur-icon" x-show="u.mfa_enabled" @click="adminResetMfa(u)" title="Reset two-factor">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9 9 0 0 0-6.4 2.6L3 8"/><path d="M3 3v5h5"/></svg>
                                    </button>
                                    <button class="ur-icon" @click="quickToggleActive(u)" :disabled="u.public_id===currentUserId" :title="u.is_active ? 'Disable' : 'Activate'">
                                        <svg x-show="u.is_active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                                        <svg x-show="!u.is_active" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                    </button>
                                    <button class="ur-icon" @click="editUser(u)" title="Edit">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <div class="utable-empty" x-show="!filteredUsers.length">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                            <span x-text="usersModal.users.length ? 'No users match your filters.' : 'No users yet.'"></span>
                            <button class="btn tiny ghost" x-show="usersModal.users.length" @click="usersModal.search=''; usersModal.roleFilter='all'; usersModal.statusFilter='all'">Clear filters</button>
                        </div>
                    </div>
                    <div class="utable-foot" x-show="filteredUsers.length">
                        <span x-text="'Showing ' + filteredUsers.length + ' of ' + usersModal.users.length"></span>
                    </div>
                </div>

                <!-- ============ ADD / EDIT FORM ============ -->
                <div x-show="userForm.open">
                    <!-- Creation mode (new users only): set a password now, or send an email invite. -->
                    <div class="create-mode" x-show="!userForm.public_id">
                        <button type="button" class="cm-opt" :class="{on: userForm.mode==='password'}" @click="userForm.mode='password'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <span><b>Set a password</b><small>Create the account now</small></span>
                        </button>
                        <button type="button" class="cm-opt" :class="{on: userForm.mode==='invite'}" @click="userForm.mode='invite'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                            <span><b>Email an invite</b><small>They set their own password</small></span>
                        </button>
                    </div>
                    <!-- Profile photo lives with the rest of the user's details
                         (moved out of the row quick-actions by request). Existing
                         users only — a photo needs a saved account to attach to. -->
                    <div class="field" x-show="userForm.public_id && can('manage_users','manage')">
                        <label>Profile photo</label>
                        <div class="uf-avatar-row">
                            <template x-if="editingUserRow && editingUserRow.profile_image">
                                <img class="uf-avatar-img" :src="'?api=image&action=serve&kind=avatar&id='+encodeURIComponent(userForm.public_id)+'&v='+encodeURIComponent(editingUserRow.profile_image)" alt="">
                            </template>
                            <template x-if="!editingUserRow || !editingUserRow.profile_image">
                                <div class="uf-avatar-none">no photo</div>
                            </template>
                            <button type="button" class="btn tiny" @click="adminPickAvatar(editingUserRow)" :disabled="avatarBusy || !editingUserRow">Change photo</button>
                            <button type="button" class="btn tiny danger" x-show="editingUserRow && editingUserRow.profile_image" @click="adminRemoveAvatar(editingUserRow)" :disabled="avatarBusy">Remove</button>
                        </div>
                    </div>
                    <div class="modal-grid">
                        <div class="field">
                            <label>Username *</label>
                            <input type="text" x-model="userForm.username" placeholder="e.g. jsmith" autocomplete="off">
                        </div>
                        <div class="field">
                            <label>Display name</label>
                            <input type="text" x-model="userForm.display_name" placeholder="e.g. Jenny Smith">
                        </div>
                        <div class="field">
                            <label>Email <span style="font-weight:400;color:var(--text-dim)">— for password resets &amp; alerts</span></label>
                            <input type="email" x-model="userForm.email" placeholder="e.g. jsmith@example.org" autocomplete="off">
                        </div>
                        <div class="field" style="grid-column:1/-1">
                            <label>Groups</label>
                            <div class="ufg-groups">
                                <template x-for="g in permGroups" :key="'ufg-'+g.group_id">
                                    <label class="ufg-chip" :class="{on: userForm.group_ids.includes(g.group_id)}">
                                        <input type="checkbox" :checked="userForm.group_ids.includes(g.group_id)" @change="toggleUserGroup(g.group_id)">
                                        <span x-text="g.name"></span>
                                    </label>
                                </template>
                                <div class="muted-note" x-show="!permGroups.length">No groups yet — create them in the Groups tab.</div>
                            </div>
                            <div class="muted-hint" style="margin-top:6px">A user's access is the highest of all their groups' grants, plus any overrides below.</div>
                        </div>
                        <form class="field" style="display:contents" onsubmit="return false" autocomplete="off">
                            <input type="text" :value="userForm.username || ''" autocomplete="username" aria-hidden="true" tabindex="-1" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px">
                            <div class="field" x-show="!(userForm.mode==='invite' && !userForm.public_id)">
                                <label x-text="userForm.public_id ? 'New password (leave blank to keep)' : 'Password *'"></label>
                                <input type="password" x-model="userForm.password" placeholder="••••••" autocomplete="new-password">
                            </div>
                        </form>
                        <div class="field" style="grid-column:1/-1" x-show="userForm.mode==='invite' && !userForm.public_id">
                            <div class="invite-note">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                <span>An activation link will be emailed to <b x-text="userForm.email || '(enter an email above)'"></b>. They'll set their own password and can't sign in until they activate.</span>
                            </div>
                        </div>
                        <div class="field" style="grid-column:1/-1" x-show="!(userForm.mode==='invite' && !userForm.public_id)">
                            <label class="check-row"><input type="checkbox" x-model="userForm.is_active"> Account is active (can sign in)</label>
                        </div>
                        <div class="field" style="grid-column:1/-1" x-show="!(userForm.mode==='invite' && !userForm.public_id)">
                            <label class="check-row"><input type="checkbox" x-model="userForm.never_expire"> Never log out <span style="text-transform:none;font-weight:500;color:var(--text-dim)">— for kiosk / service accounts (exempt from the idle timeout)</span></label>
                        </div>
                    </div>

                    <!-- site access (legacy — superseded by grants; hidden) -->
                    <div x-show="false" style="margin-top:6px">
                        <div class="glance-section">
                            <span class="gs-label">Site access</span>
                            <span class="gs-line"></span>
                            <span class="sa-count" x-text="userForm.sites.length + ' selected'"></span>
                        </div>
                        <div class="sa-tools">
                            <div class="user-search slim">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                                <input type="text" x-model="usersModal.siteSearch" placeholder="Filter sites…">
                            </div>
                            <button class="btn tiny ghost" @click="selectAllSites()">Select all</button>
                            <button class="btn tiny ghost" @click="clearAllSites()">Clear</button>
                        </div>
                        <div class="site-access-grid">
                            <template x-for="s in formSitesFiltered" :key="s.id">
                                <label class="site-access-item" :class="{on: userForm.sites.includes(s.id)}">
                                    <input type="checkbox" :value="s.id" :checked="userForm.sites.includes(s.id)" @change="toggleUserSite(s.id)">
                                    <span x-text="s.name"></span>
                                </label>
                            </template>
                            <div class="pe-empty" x-show="!formSitesFiltered.length" x-text="sites.length ? 'No sites match.' : 'No sites available to assign.'"></div>
                        </div>
                    </div>
                    <div x-show="false" class="muted-hint" style="margin-top:10px">Admins automatically have access to every site.</div>

                    <!-- Per-user override grants (on top of group grants) -->
                    <div x-show="userForm.public_id" style="margin-top:18px">
                        <div class="glance-section">
                            <span class="gs-label">Override grants</span>
                            <span class="gs-line"></span>
                            <span class="muted-note">extra access on top of groups</span>
                        </div>
                        <div class="ug-grants">
                            <template x-for="(gr, idx) in userForm.overrides" :key="'ov-'+idx">
                                <div class="grant-row-wrap">
                                <div class="grant-row">
                                    <select x-model="gr.layer" class="grant-sel" @change="gr.scope_type = isAdminCap(gr.layer) ? 'all' : gr.scope_type; gr.level = levelsFor(gr.layer).includes(gr.level) ? gr.level : 'view'">
                                        <optgroup label="Data layers">
                                            <option value="base" x-text="layerLabel('base')"></option>
                                            <option value="cameras" x-text="layerLabel('cameras')"></option>
                                            <option value="printers" x-text="layerLabel('printers')"></option>
                                            <option value="devices" x-text="layerLabel('devices')"></option>
                                        </optgroup>
                                        <optgroup label="Admin capabilities">
                                            <option value="audit" x-text="layerLabel('audit')"></option>
                                            <option value="settings" x-text="layerLabel('settings')"></option>
                                            <option value="manage_users" x-text="layerLabel('manage_users')"></option>
                                            <option value="data_admin" x-text="layerLabel('data_admin')"></option>
                                            <option value="notifications" x-text="layerLabel('notifications')"></option>
                                        </optgroup>
                                    </select>
                                    <select x-model="gr.level" class="grant-sel">
                                        <option value="view" x-text="levelLabel('view')"></option>
                                        <option value="edit" x-show="!isAdminCap(gr.layer)" x-text="levelLabel('edit')"></option>
                                        <option value="manage" x-text="levelLabel('manage')"></option>
                                        <option value="admin" x-show="!isAdminCap(gr.layer)" x-text="levelLabel('admin')"></option>
                                    </select>
                                    <select x-model="gr.scope_type" class="grant-sel" :disabled="isAdminCap(gr.layer)" x-show="!isAdminCap(gr.layer)" @change="gr.scope_id = null">
                                        <option value="all">at all sites</option>
                                        <option value="site">at one site…</option>
                                        <option value="device" x-show="gr.layer==='cameras'">at one camera…</option>
                                    </select>
                                    <select x-model.number="gr.scope_id" class="grant-sel" x-show="gr.scope_type==='site' && !isAdminCap(gr.layer)">
                                        <option :value="null">— pick a site —</option>
                                        <template x-for="s in sites" :key="'os-'+s.id"><option :value="s.id" x-text="s.name"></option></template>
                                    </select>
                                    <select x-model.number="gr._pickSite" class="grant-sel" x-show="gr.scope_type==='device' && gr.layer==='cameras'">
                                        <option :value="null">— site —</option>
                                        <template x-for="s in sites" :key="'ods-'+s.id"><option :value="s.id" x-text="s.name"></option></template>
                                    </select>
                                    <button class="grant-del" @click="userForm.overrides.splice(idx,1)" title="Remove">✕</button>
                                </div>
                                <div class="cam-checklist" x-show="gr.scope_type==='device' && gr.layer==='cameras' && gr._pickSite">
                                    <div class="cam-checklist-head">
                                        <span x-text="'Cameras at ' + siteName(gr._pickSite, '')"></span>
                                        <span class="cam-check-actions">
                                            <button class="linkbtn" @click="gr.scope_ids = camerasForSite(gr._pickSite).map(c=>Number(c.camera_number))">All</button>
                                            <button class="linkbtn" @click="gr.scope_ids = []">None</button>
                                        </span>
                                    </div>
                                    <div class="cam-check-grid">
                                        <template x-for="c in camerasForSite(gr._pickSite)" :key="'ock-'+c.camera_number">
                                            <label class="cam-check" :class="{on: (gr.scope_ids||[]).includes(Number(c.camera_number))}">
                                                <input type="checkbox" :checked="(gr.scope_ids||[]).includes(Number(c.camera_number))" @change="toggleCamScope(gr, Number(c.camera_number))">
                                                <span x-text="c.camera_name"></span>
                                            </label>
                                        </template>
                                        <div class="muted-note" x-show="!camerasForSite(gr._pickSite).length">No cameras at this site.</div>
                                    </div>
                                </div>
                                <div class="grant-summary" x-text="grantSummary(gr)"></div>
                                </div>
                            </template>
                            <button class="btn" style="margin-top:6px" @click="addGrant(userForm.overrides)">+ Add override</button>
                        </div>
                    </div>

                    <!-- Camera permissions (legacy — superseded by grants; hidden) -->
                    <div x-show="false" style="margin-top:18px">
                        <div class="glance-section">
                            <span class="gs-label">Camera access</span>
                            <span class="gs-line"></span>
                        </div>
                        <p class="muted-hint" style="margin:0 0 10px">For each site, choose which cameras they can <strong>see on the map / search</strong> (objects) and whose <strong>live feed</strong> they can view. Feeds are off unless you grant them.</p>
                        <div class="cam-perm-list">
                            <template x-for="sid in userForm.sites" :key="'camperm'+sid">
                                <div class="cam-perm-site" x-data="{expanded:false}">
                                    <div class="cam-perm-head">
                                        <div class="cps-name" x-text="siteName(sid, 'Site '+sid)"></div>
                                        <div class="cps-summary" x-text="camRuleSummary(sid)"></div>
                                        <button type="button" class="cps-toggle" @click="expanded=!expanded" x-text="expanded ? 'Hide' : 'Configure'"></button>
                                    </div>
                                    <div class="cam-perm-body" x-show="expanded" x-transition.opacity>
                                        <template x-if="!camerasForSite(sid).length">
                                            <div class="pe-empty">No cameras at this site yet.</div>
                                        </template>
                                        <template x-if="camerasForSite(sid).length">
                                            <div>
                                                <!-- OBJECT access -->
                                                <div class="cam-perm-row">
                                                    <div class="cpr-label">See cameras (map &amp; search)</div>
                                                    <div class="seg">
                                                        <button type="button" :class="{on:camObjMode(sid)==='none'}" @click="setCamObjMode(sid,'none')">None</button>
                                                        <button type="button" :class="{on:camObjMode(sid)==='some'}" @click="setCamObjMode(sid,'some')">Specific</button>
                                                        <button type="button" :class="{on:camObjMode(sid)==='all'}" @click="setCamObjMode(sid,'all')">All</button>
                                                    </div>
                                                </div>
                                                <div class="cam-pick" x-show="camObjMode(sid)==='some'">
                                                    <template x-for="c in camerasForSite(sid)" :key="'obj'+c.camera_number">
                                                        <label class="cam-chip" :class="{on:camInList(sid,c.camera_number,'obj')}">
                                                            <input type="checkbox" :checked="camInList(sid,c.camera_number,'obj')" @change="toggleCam(sid,c.camera_number,'obj')">
                                                            <span x-text="c.camera_name"></span>
                                                        </label>
                                                    </template>
                                                </div>

                                                <!-- FEED access -->
                                                <div class="cam-perm-row" style="margin-top:12px">
                                                    <div class="cpr-label">Watch live feed
                                                        <span class="cpr-lock" title="Feeds are sensitive — off by default">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                                        </span>
                                                    </div>
                                                    <div class="seg">
                                                        <button type="button" :class="{on:camFeedMode(sid)==='none'}" @click="setCamFeedMode(sid,'none')">None</button>
                                                        <button type="button" :class="{on:camFeedMode(sid)==='some'}" @click="setCamFeedMode(sid,'some')" :disabled="camObjMode(sid)==='none'">Specific</button>
                                                        <button type="button" :class="{on:camFeedMode(sid)==='all'}" @click="setCamFeedMode(sid,'all')" :disabled="camObjMode(sid)==='none'">All</button>
                                                    </div>
                                                </div>
                                                <div class="cam-pick" x-show="camFeedMode(sid)==='some'">
                                                    <template x-for="c in camerasForSite(sid)" :key="'feed'+c.camera_number">
                                                        <label class="cam-chip feed" :class="{on:camInList(sid,c.camera_number,'feed'), disabled: camObjMode(sid)==='some' && !camInList(sid,c.camera_number,'obj') && !camInList(sid,c.camera_number,'feed')}">
                                                            <input type="checkbox" :checked="camInList(sid,c.camera_number,'feed')" @change="toggleCam(sid,c.camera_number,'feed')">
                                                            <span x-text="c.camera_name"></span>
                                                        </label>
                                                    </template>
                                                </div>
                                                <p class="muted-hint" x-show="camFeedMode(sid)==='all'" style="margin:8px 0 0">Watching all feeds is limited to the cameras they can see above.</p>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div style="display:flex;gap:8px;margin-top:16px;align-items:center">
                        <button class="btn danger" x-show="userForm.public_id && userForm.public_id !== currentUserId" @click="deleteUser()">Delete</button>
                        <div style="flex:1"></div>
                        <button class="btn" @click="closeUserSubview()">Cancel</button>
                        <button class="btn save" x-show="!(userForm.mode==='invite' && !userForm.public_id)" @click="saveUser()">Save user</button>
                        <button class="btn save" x-show="userForm.mode==='invite' && !userForm.public_id" :disabled="inviteModal.sending" @click="sendInviteFromForm()" x-text="inviteModal.sending ? 'Sending…' : 'Send invite'"></button>
                    </div>
                </div>

            </div>
    </div>

        <!-- (relocated into the main content column, alongside Home/Sites/Cameras, so it lays out full-width instead of squeezed into leftover flex space) -->
    <div x-show="view==='settings'" x-cloak>
        <h1 class="page-title">System settings</h1>
        <p class="page-subtitle">Session, security, and log retention. Applies to everyone.</p>
        <div class="modal-body" id="settingsBody" :class="{'settings-readonly': !can('settings','manage')}">
                <!-- Settings search: filters rows across every section below. -->
                <div class="set-search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" placeholder="Search settings…" x-model="settingsQuery" @input="filterSettings()">
                    <button class="set-search-x" x-show="settingsQuery" @click="settingsQuery=''; filterSettings()" title="Clear">&times;</button>
                </div>
                <div class="ro-banner" x-show="!can('settings','manage')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    <span x-text="can('audit','manage') ? 'View only — you can change the audit log retention below, but not other settings.' : 'View only — you do not have permission to change these settings.'"></span>
                </div>
                <div class="glance-section"><span class="gs-label">Branding</span><span class="gs-line"></span></div>
                <div class="set-row" :class="{'set-row-enabled': false}">
                    <div class="set-info">
                        <div class="set-label">Site logo</div>
                        <div class="set-desc">Shown on the login page and the top bar. PNG, JPG, WEBP, or GIF (max 5MB).</div>
                    </div>
                    <div class="brand-logo-upload">
                        <div class="brand-logo-preview">
                            <template x-if="brandLogoUrl"><img :src="brandLogoUrl" alt="Logo"></template>
                            <template x-if="!brandLogoUrl"><span class="blp-empty">No logo</span></template>
                        </div>
                        <div class="brand-logo-btns" x-show="can('settings','manage')">
                            <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" id="logoFileInput" style="display:none" @change="uploadLogo($event)" :disabled="!can('settings','manage')">
                            <button class="btn tiny" @click="document.getElementById('logoFileInput').click()" :disabled="brandLogoBusy" x-text="brandLogoBusy?'Uploading…':'Upload'"></button>
                            <button class="btn tiny ghost" x-show="brandLogoUrl" @click="removeLogo()" :disabled="brandLogoBusy">Remove</button>
                        </div>
                    </div>
                </div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Brand name</div>
                        <div class="set-desc">Text shown beside the logo.</div>
                    </div>
                    <div class="set-input">
                        <input type="text" maxlength="60" x-model="settingsModal.vals.site_brand_name" :disabled="!can('settings','manage')" placeholder="Site Manager">
                    </div>
                </div>

                <div class="glance-section"><span class="gs-label">Sessions</span><span class="gs-line"></span></div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Idle logout</div>
                        <div class="set-desc">Sign users out after this many minutes of inactivity.</div>
                    </div>
                    <div class="set-input">
                        <input type="number" min="5" max="43200" x-model.number="settingsModal.vals.session_timeout_minutes" :disabled="!can('settings','manage')">
                        <span class="set-unit" x-text="minutesLabel(settingsModal.vals.session_timeout_minutes)"></span>
                    </div>
                </div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Warn before logout</div>
                        <div class="set-desc">Show a "you'll be signed out soon" prompt this many minutes early.</div>
                    </div>
                    <div class="set-input">
                        <input type="number" min="1" max="120" x-model.number="settingsModal.vals.session_warn_minutes" :disabled="!can('settings','manage')">
                        <span class="set-unit">min</span>
                    </div>
                </div>
                <div class="muted-hint">Kiosk / service accounts with “Never log out” are exempt from the idle timeout.</div>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Login security</span><span class="gs-line"></span></div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Max failed attempts</div>
                        <div class="set-desc">Lock an account after this many wrong passwords in a row. Set 0 to disable lockouts.</div>
                    </div>
                    <div class="set-input">
                        <input type="number" min="0" max="50" x-model.number="settingsModal.vals.login_max_attempts" :disabled="!can('settings','manage')">
                        <span class="set-unit" x-text="(settingsModal.vals.login_max_attempts>0)?'tries':'off'"></span>
                    </div>
                </div>
                <div class="set-row" x-show="settingsModal.vals.login_max_attempts>0">
                    <div class="set-info">
                        <div class="set-label">Lock duration</div>
                        <div class="set-desc">How long a locked account stays locked before it can try again.</div>
                    </div>
                    <div class="set-input">
                        <select class="level-select" x-model="lockoutChoice" @change="applyLockoutChoice()" :disabled="settingsModal.vals.login_lockout_manual==='1'">
                            <option value="5">5 minutes</option>
                            <option value="10">10 minutes</option>
                            <option value="20">20 minutes</option>
                            <option value="60">1 hour</option>
                            <option value="240">4 hours</option>
                            <option value="1440">24 hours</option>
                        </select>
                    </div>
                </div>
                <div class="set-row" x-show="settingsModal.vals.login_max_attempts>0">
                    <div class="set-info">
                        <div class="set-label">Require admin to unlock</div>
                        <div class="set-desc">If on, locked accounts stay locked until an admin unlocks them (ignores the duration above).</div>
                    </div>
                    <div class="set-input">
                        <label class="switch">
                            <input type="checkbox" :disabled="!can('settings','manage')" :checked="settingsModal.vals.login_lockout_manual==='1'" @change="settingsModal.vals.login_lockout_manual = $event.target.checked ? '1' : '0'">
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Layers</span><span class="gs-line"></span></div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Cameras layer</div>
                        <div class="set-desc">Shows the "All Sites" camera view in the sidebar and camera feeds across the app. More device layers (printers, HVAC…) will appear here as they're added.</div>
                    </div>
                    <div class="set-input">
                        <label class="switch">
                            <input type="checkbox" :disabled="!can('settings','manage')" :checked="settingsModal.vals.layer_cameras_enabled==='1'" @change="settingsModal.vals.layer_cameras_enabled = $event.target.checked ? '1' : '0'">
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                </div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Printers layer</div>
                        <div class="set-desc">Shows printers as a toggleable layer on each site's map (View menu). Editors can add, edit, and place them.</div>
                    </div>
                    <div class="set-input">
                        <label class="switch">
                            <input type="checkbox" :disabled="!can('settings','manage')" :checked="settingsModal.vals.layer_printers_enabled==='1'" @change="settingsModal.vals.layer_printers_enabled = $event.target.checked ? '1' : '0'">
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Guess a new room's building</div>
                        <div class="set-desc">When you drop a new room pin, pre-fill its building from the nearest room beside it on the same level. Only ever fills a blank field, and always tells you what it guessed — turn this off to enter every building by hand.</div>
                    </div>
                    <div class="set-input">
                        <label class="switch">
                            <input type="checkbox" :disabled="!can('settings','manage')" :checked="settingsModal.vals.room_inherit_building==='1'" @change="settingsModal.vals.room_inherit_building = $event.target.checked ? '1' : '0'">
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Room colors</span><span class="gs-line"></span></div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Default pin color per room type</div>
                        <div class="set-desc">Rooms with no color of their own use their type's color below. A color set on an individual room always wins. Defaults follow common facility-map conventions (cyan restrooms, orange food, gray utility — red is left free for emergency use).</div>
                        <div class="rtc-grid">
                            <template x-for="t in roomTypeList" :key="'rtc-'+t.v">
                                <label class="rtc-item">
                                    <input type="color" :value="(settingsModal.vals._rtc && settingsModal.vals._rtc[t.v]) || '#3b82f6'"
                                           :disabled="!can('settings','manage')"
                                           @input="settingsModal.vals._rtc[t.v] = $event.target.value; settingsModal.vals.room_type_colors = JSON.stringify(settingsModal.vals._rtc)">
                                    <span x-text="t.n"></span>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Site colors</span><span class="gs-line"></span></div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Color for each site</div>
                        <div class="set-desc">The dot beside each site in the sidebar, its card accent, and its markers. Leave a site untouched to keep the color the app picks automatically; clear one to go back to that.</div>
                        <div class="rtc-grid">
                            <template x-for="s in sites" :key="'sc-'+s.id">
                                <label class="rtc-item">
                                    <input type="color" :value="s.color || '#3b82f6'" :disabled="!can('settings','manage')"
                                           @change="setSiteColor(s, $event.target.value)">
                                    <span x-text="s.name"></span>
                                    <button class="sc-reset" x-show="can('settings','manage')" @click.prevent="setSiteColor(s, '')" title="Back to the automatic color">&#8635;</button>
                                </label>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Email (SMTP)</span><span class="gs-line"></span></div>
                <div class="set-row">
                    <div class="set-info">
                        <div class="set-label">Enable email</div>
                        <div class="set-desc">Lets the system send password resets and alerts. Configure your mail server below.</div>
                    </div>
                    <div class="set-input">
                        <label class="switch">
                            <input type="checkbox" :disabled="!can('settings','manage')" :checked="settingsModal.vals.smtp_enabled==='1'" @change="settingsModal.vals.smtp_enabled = $event.target.checked ? '1' : '0'">
                            <span class="switch-slider"></span>
                        </label>
                    </div>
                </div>
                <template x-if="settingsModal.vals.smtp_enabled==='1'">
                    <div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">SMTP host</div><div class="set-desc">e.g. smtp.gmail.com, smtp.office365.com, or your district relay</div></div>
                            <div class="set-input"><input type="text" style="width:220px" x-model="settingsModal.vals.smtp_host" :disabled="!can('settings','manage')" placeholder="smtp.example.org"></div>
                        </div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">Port &amp; security</div><div class="set-desc">587 = STARTTLS (typical), 465 = SSL/TLS, 25 = none</div></div>
                            <div class="set-input" style="gap:8px">
                                <input type="number" min="1" max="65535" style="width:80px" x-model.number="settingsModal.vals.smtp_port" :disabled="!can('settings','manage')">
                                <select class="level-select" x-model="settingsModal.vals.smtp_security" :disabled="!can('settings','manage')">
                                    <option value="tls">STARTTLS</option>
                                    <option value="ssl">SSL/TLS</option>
                                    <option value="none">None</option>
                                </select>
                            </div>
                        </div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">Username</div><div class="set-desc">Leave blank if your relay needs no authentication</div></div>
                            <div class="set-input"><input type="text" style="width:220px" x-model="settingsModal.vals.smtp_user" :disabled="!can('settings','manage')" autocomplete="off" placeholder="user@example.org"></div>
                        </div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">Password</div><div class="set-desc">Stored securely; leave the dots to keep the current password</div></div>
                            <div class="set-input"><input type="password" style="width:220px" x-model="settingsModal.vals.smtp_pass" :disabled="!can('settings','manage')" autocomplete="new-password"></div>
                        </div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">From address</div><div class="set-desc">What recipients see as the sender</div></div>
                            <div class="set-input"><input type="text" style="width:220px" x-model="settingsModal.vals.smtp_from_email" :disabled="!can('settings','manage')" placeholder="noreply@example.org"></div>
                        </div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">From name</div></div>
                            <div class="set-input"><input type="text" style="width:220px" x-model="settingsModal.vals.smtp_from_name" :disabled="!can('settings','manage')" placeholder="Site Manager"></div>
                        </div>
                        <div class="set-row">
                            <div class="set-info">
                                <div class="set-label">Send limits (safety)</div>
                                <div class="set-desc">Hard ceilings so nothing can mass-email by accident. 0 = no limit. Blocked sends are logged.</div>
                            </div>
                            <div class="set-input" style="gap:8px;flex-wrap:wrap">
                                <input type="number" min="0" max="100000" style="width:80px" x-model.number="settingsModal.vals.email_cap_hourly" :disabled="!can('settings','manage')">
                                <span class="set-unit">/ hour</span>
                                <input type="number" min="0" max="100000" style="width:80px" x-model.number="settingsModal.vals.email_cap_daily" :disabled="!can('settings','manage')">
                                <span class="set-unit">/ day</span>
                            </div>
                        </div>
                        <div class="set-row">
                            <div class="set-info"><div class="set-label">Send a test</div><div class="set-desc">Saves your settings, then sends a test message to this address.</div></div>
                            <div class="set-input" style="gap:8px">
                                <input type="text" style="width:170px" x-model="testEmailTo" :disabled="!can('settings','manage')" placeholder="you@example.org">
                                <button class="btn tiny" :disabled="emailTesting" @click="sendTestEmail()" x-text="emailTesting ? 'Sending…' : 'Send test'"></button>
                            </div>
                        </div>
                    </div>
                </template>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Audit log</span><span class="gs-line"></span></div>
                <div class="set-row" :class="{'set-row-enabled': can('audit','manage')}">
                    <div class="set-info">
                        <div class="set-label">Keep logs for</div>
                        <div class="set-desc">Older entries are deleted automatically. Set 0 to keep forever.</div>
                    </div>
                    <div class="set-input">
                        <input type="number" min="0" max="3650" x-model.number="settingsModal.vals.audit_retention_days" :disabled="!can('settings','manage') && !can('audit','manage')">
                        <span class="set-unit" x-text="daysLabel(settingsModal.vals.audit_retention_days)"></span>
                    </div>
                </div>
                <div class="set-row set-row-enabled" x-show="!can('settings','manage') && can('audit','manage')" style="border:none;padding-top:0">
                    <button class="btn primary" @click="saveRetentionOnly()">Save retention</button>
                </div>

                <div class="glance-section" style="margin-top:18px"><span class="gs-label">Buildings</span><span class="gs-line"></span></div>
                <div class="muted-hint" style="margin-bottom:10px">One shared pool of building codes that every site uses. Rooms display as <strong>code-number</strong> (e.g. A1-100). Each site's “A1” is its own building — the codes are just shared vocabulary.</div>

                <div class="set-bld-list" x-show="siteBuildings.length">
                    <template x-for="b in siteBuildings" :key="'set-bld-'+b.id">
                        <div class="set-bld-row">
                            <span class="bld-code" x-text="b.code"></span>
                            <span class="bld-label" x-text="b.label || ''"></span>
                            <span style="flex:1"></span>
                            <span class="bld-usage" x-text="roomCountForBuilding(b.code) + ' rooms'"></span>
                            <button class="ur-icon" x-show="can('settings','manage')" @click="deleteBuilding(b)" title="Remove from pool">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="muted-note" x-show="!siteBuildings.length" style="padding:4px 0 10px">No building codes yet. Add one below, or generate a grid.</div>

                <div class="bld-add" x-show="can('settings','manage')">
                    <input type="text" x-model="buildingMgr.newCode" :disabled="!can('base','edit')" placeholder="Code (e.g. A1)" maxlength="20" style="width:110px" @keydown.enter="addBuilding()">
                    <input type="text" x-model="buildingMgr.newLabel" :disabled="!can('base','edit')" placeholder="Label (optional)" @keydown.enter="addBuilding()">
                    <button class="btn save" :disabled="buildingMgr.busy || !buildingMgr.newCode.trim()" @click="addBuilding()">Add</button>
                </div>

                <!-- Grid generator kept, but tucked behind a toggle + confirm so it can't be hit by accident. It only ADDS missing codes; it never deletes. -->
                <div x-show="can('settings','manage')" style="margin-top:10px">
                    <button class="set-bld-gentoggle" @click="settingsModal.showGen = !settingsModal.showGen" x-text="(settingsModal.showGen ? '▾' : '▸') + ' Generate a grid of codes'"></button>
                    <div x-show="settingsModal.showGen" x-transition class="set-bld-gen">
                        <div class="muted-hint" style="margin-bottom:8px">Adds any missing codes in a grid (letter = column, number = row). <strong>Safe:</strong> this only adds new codes — it never removes existing buildings.</div>
                        <div class="bld-gen-row">
                            <label>Columns A–<select x-model="buildingMgr.genCols" :disabled="!can('base','edit')" class="bld-mini">
                                <template x-for="n in 12" :key="'sgc'+n"><option :value="n" x-text="String.fromCharCode(64+n)"></option></template>
                            </select></label>
                            <label>Rows 1–<select x-model="buildingMgr.genRows" :disabled="!can('base','edit')" class="bld-mini">
                                <template x-for="n in 12" :key="'sgr'+n"><option :value="n" x-text="n"></option></template>
                            </select></label>
                            <button class="btn" :disabled="buildingMgr.busy" @click="generateGrid()">Generate</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn primary" x-show="can('settings','manage')" @click="saveSettings()">Save settings</button>
            </div>
    </div>
        </main>
    </div>

    <!-- ROOM INFO MODAL — quick "who and what is here" glance card -->
    <div class="modal-backdrop" x-show="roomModal" x-transition.opacity @click.self="closeRoomModal()" x-cloak>
        <div class="modal-card glance" x-show="roomModal" x-transition :style="'--rt-color:'+roomTypeColor(roomModal?.room_type)">
            <div class="modal-head">
                <div class="glance-icon" x-html="roomTypeIcon(roomModal?.room_type)"></div>
                <div class="modal-head-text">
                    <div class="modal-title">
                        <span x-text="roomModal?.room_name"></span>
                        <span class="attention-dot" x-show="roomNeedsAttention(roomModal)" title="A device needs attention"></span>
                    </div>
                    <div class="modal-sub">
                        <span class="sub-num" x-show="roomModal?.room_number" x-text="'#' + roomModal?.room_number"></span>
                        <span class="sub-chip" x-text="formatRoomType(roomModal?.room_type)"></span>
                        <span class="sub-chip" x-show="roomModal?.department" x-text="roomModal?.department"></span>
                    </div>
                </div>
                <button class="modal-close" @click="closeRoomModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body">

                <!-- Alert / notes (most urgent — first) -->
                <div class="room-alert" x-show="roomModal?.room_notes">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <span x-text="roomModal?.room_notes"></span>
                </div>

                <!-- Headline: the room's own phone takes the top spot when it has one;
                     otherwise the primary person. People always go in the list below. -->
                <!-- Room-phone hero -->
                <div class="primary-person" x-show="roomModal?.room_extension">
                    <div class="pp-avatar room">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </div>
                    <div class="pp-text">
                        <div class="pp-name">Room phone</div>
                        <div class="pp-role">Main line</div>
                    </div>
                    <button class="pp-ext" @click="copyExt(roomModal?.room_extension)"
                            :title="'Copy ext. ' + roomModal?.room_extension">
                        <span x-text="'ext. ' + roomModal?.room_extension"></span>
                    </button>
                </div>
                <!-- Primary-person hero (only when there is no room phone) -->
                <div class="primary-person" x-show="!roomModal?.room_extension && roomModal?.show_primary_contact && primaryOccupant(roomModal)">
                    <div class="pp-avatar" x-text="primaryInitials(roomModal)"></div>
                    <div class="pp-text">
                        <div class="pp-name" x-text="primaryOccupant(roomModal)?.name"></div>
                        <div class="pp-role" x-text="primaryOccupant(roomModal)?.role || 'Primary contact'"></div>
                    </div>
                    <button class="pp-ext" x-show="primaryOccupant(roomModal)?.extension"
                            @click="copyExt(primaryOccupant(roomModal)?.extension)"
                            :title="'Copy ext. ' + primaryOccupant(roomModal)?.extension">
                        <span x-text="'ext. ' + primaryOccupant(roomModal)?.extension"></span>
                    </button>
                </div>

                <!-- Stat tiles -->
                <div class="glance-stats">
                    <div class="gstat">
                        <div class="gstat-label">Level</div>
                        <div class="gstat-value" x-text="glanceLevel(roomModal?.map_level)"></div>
                    </div>
                    <div class="gstat">
                        <div class="gstat-label">Devices</div>
                        <div class="gstat-value" :class="{muted: !devicesForRoom(roomModal?.room_id).length}" x-text="devicesForRoom(roomModal?.room_id).length || '—'"></div>
                    </div>
                    <div class="gstat">
                        <div class="gstat-label">People</div>
                        <div class="gstat-value" :class="{muted: !(roomModal?.occupants || []).length}" x-text="(roomModal?.occupants || []).length || '—'"></div>
                    </div>
                    <div class="gstat">
                        <div class="gstat-label">Updated</div>
                        <div class="gstat-value" style="font-size:13px" x-text="glanceDate(roomModal?.updated_at)"></div>
                    </div>
                </div>

                <!-- Equipment summary -->
                <div x-show="deviceSummary(roomModal?.room_id)">
                    <div class="glance-section"><span class="gs-label">Equipment</span><span class="gs-line"></span></div>
                    <div class="device-summary" x-text="deviceSummary(roomModal?.room_id)"></div>
                </div>

                <!-- People & extensions. The room phone is shown as the hero above, so
                     this section is just the people. It hides entirely for a room that
                     has only a phone and no named people. -->
                <div x-show="(roomModal?.occupants || []).length || !roomModal?.room_extension">
                    <div class="glance-section"><span class="gs-label">People &amp; extensions</span><span class="gs-line"></span></div>
                    <!-- Named occupants -->
                    <div class="people-list" x-show="(roomModal?.occupants || []).length">
                        <template x-for="oc in (roomModal?.occupants || [])" :key="oc.occupant_id">
                            <div class="person-row">
                                <span class="person-dot" :class="{primary: oc.is_primary}"></span>
                                <span class="person-name" x-text="oc.name"></span>
                                <span class="person-role" x-show="oc.role" x-text="oc.role"></span>
                                <span class="person-spacer"></span>
                                <button class="ext-chip" x-show="oc.extension" @click="copyExt(oc.extension)" :title="'Copy ext. ' + oc.extension">
                                    <span x-text="'ext. ' + oc.extension"></span>
                                </button>
                            </div>
                        </template>
                    </div>
                    <!-- No named people and no room phone: just name the room type -->
                    <div class="people-list" x-show="!(roomModal?.occupants || []).length">
                        <div class="person-row">
                            <span class="person-dot"></span>
                            <span class="person-name" x-text="formatRoomType(roomModal?.room_type)"></span>
                            <span class="person-spacer"></span>
                        </div>
                    </div>
                </div>

                <!-- Description (if any) -->
                <div x-show="roomModal?.description">
                    <div class="glance-section"><span class="gs-label">Description</span><span class="gs-line"></span></div>
                    <div class="modal-desc" x-text="roomModal?.description"></div>
                </div>
            </div>
            <div class="glance-foot">
                <button class="btn" @click="closeRoomModal()">Close</button>
                <button class="btn primary" @click="enterRoomFromModal()">Enter Room →</button>
            </div>
        </div>
    </div>


    <!-- Data editor row edit/create sub-modal -->
    <div class="modal-backdrop" x-show="deForm.open" x-transition.opacity @click.self="deForm.open=false" x-cloak style="z-index:60">
        <div class="modal-card" style="max-width:520px" x-show="deForm.open" x-transition>
            <div class="modal-head">
                <div>
                    <div class="modal-title" x-text="(deForm.isNew ? 'New ' : 'Edit ') + dataEditor.current"></div>
                    <div class="modal-sub" x-show="!deForm.isNew" x-text="dePk + ' ' + deForm.pkValue"></div>
                </div>
                <button class="modal-close" @click="deForm.open=false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg></button>
            </div>
            <div style="padding:16px 20px;max-height:60vh;overflow-y:auto">
                <template x-for="c in deCols" :key="'f-'+c.name">
                    <div class="field" style="margin-bottom:12px">
                        <label x-text="c.name + (c.type!=='text' && c.type!=='longtext' ? ' ('+c.type+')' : '')"></label>
                        <template x-if="c.type==='bool'">
                            <label class="switch"><input type="checkbox" x-model="deForm.values[c.name]"><span class="switch-slider"></span></label>
                        </template>
                        <template x-if="c.type==='longtext'">
                            <textarea x-model="deForm.values[c.name]" rows="3"></textarea>
                        </template>
                        <template x-if="c.type==='int' || c.type==='float'">
                            <input type="number" :step="c.type==='float' ? 'any' : '1'" x-model="deForm.values[c.name]">
                        </template>
                        <template x-if="c.type==='text'">
                            <input type="text" x-model="deForm.values[c.name]">
                        </template>
                    </div>
                </template>
            </div>
            <div class="modal-foot">
                <div style="flex:1"></div>
                <button class="btn" @click="deForm.open=false">Cancel</button>
                <button class="btn save" @click="deSaveRow()" x-text="deForm.isNew ? 'Create' : 'Save changes'"></button>
            </div>
        </div>
    </div>


    <!-- PROFILE SETTINGS MODAL (profile · password · security) -->
    <div class="modal-backdrop" x-show="profileModal.open" x-transition.opacity @click.self="profileModal.open=false" x-cloak>
        <div class="modal-card users-modal" x-show="profileModal.open" x-transition>
            <div class="modal-head">
                <div class="modal-head-text">
                    <div class="modal-title">Profile settings</div>
                    <div class="modal-sub">Manage your account, password, and security.</div>
                </div>
                <button class="modal-close" @click="profileModal.open=false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body" style="padding:0">
                <div class="profile-layout">
                    <!-- left nav -->
                    <nav class="profile-nav">
                        <button class="pnav-item" :class="{active: profileModal.tab==='profile'}" @click="profileModal.tab='profile'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Profile
                        </button>
                        <button class="pnav-item" :class="{active: profileModal.tab==='password'}" @click="profileModal.tab='password'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Password
                        </button>
                        <button class="pnav-item" :class="{active: profileModal.tab==='security'}" @click="profileModal.tab='security'">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            Security
                            <span class="pnav-badge" :class="mfaModal.step==='on' ? 'on' : 'off'" x-text="mfaModal.step==='on' ? 'On' : 'Off'"></span>
                        </button>
                    </nav>

                    <!-- panels -->
                    <div class="profile-panel">

                        <!-- ===== PROFILE ===== -->
                        <div x-show="profileModal.tab==='profile'">
                            <div class="profile-hero">
                                <div class="ph-avatar-wrap">
                                    <div class="ph-avatar" x-show="!myAvatarUrl" x-text="(currentUser.name||'?').slice(0,1).toUpperCase()"></div>
                                    <img class="ph-avatar-img" x-show="myAvatarUrl" :src="myAvatarUrl" alt="Avatar">
                                    <button class="ph-avatar-edit" @click="document.getElementById('avatarFileInput').click()" :disabled="avatarBusy" title="Change photo">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                                    </button>
                                    <input type="file" accept="image/png,image/jpeg,image/webp,image/gif" id="avatarFileInput" style="display:none" @change="uploadAvatar($event)">
                                </div>
                                <div>
                                    <div class="ph-name" x-text="currentUser.name"></div>
                                    <div class="ph-sub">
                                        <span x-text="'@'+currentUser.username"></span>
                                        <template x-for="gn in myGroups" :key="'mg-'+gn"><span class="user-role role-group" x-text="gn" style="margin-left:6px"></span></template>
                                        <span class="user-role role-group" x-show="!myGroups.length" x-text="'no groups'" style="margin-left:6px"></span>
                                    </div>
                                    <button class="linkbtn" x-show="myAvatarUrl" @click="removeAvatar()" :disabled="avatarBusy" style="margin-top:4px">Remove photo</button>
                                </div>
                            </div>
                            <div class="field" style="margin-top:6px">
                                <label>Display name</label>
                                <input type="text" x-model="profileModal.display_name" placeholder="Your name">
                            </div>
                            <div class="field">
                                <label>Username</label>
                                <input type="text" :value="currentUser.username" disabled>
                                <div class="muted-hint">Usernames can only be changed by an administrator.</div>
                            </div>
                            <div class="field">
                                <label>Role</label>
                                <input type="text" :value="userRole.charAt(0).toUpperCase()+userRole.slice(1)" disabled>
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px">
                                <div style="flex:1"></div>
                                <button class="btn save" @click="saveProfile()">Save profile</button>
                            </div>
                        </div>

                        <!-- ===== PASSWORD ===== -->
                        <div x-show="profileModal.tab==='password'">
                            <div class="glance-section"><span class="gs-label">Change password</span><span class="gs-line"></span></div>
                            <form onsubmit="return false" autocomplete="off">
                            <input type="text" :value="currentUser.username || ''" autocomplete="username" aria-hidden="true" tabindex="-1" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px">
                            <div class="field" style="margin-top:8px">
                                <label>New password</label>
                                <input type="password" x-model="profileModal.p1" placeholder="At least 8 characters" autocomplete="new-password">
                            </div>
                            <div class="field">
                                <label>Confirm password</label>
                                <input type="password" x-model="profileModal.p2" placeholder="Re-enter password" autocomplete="new-password" @keydown.enter="saveProfilePassword()">
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px">
                                <div style="flex:1"></div>
                                <button class="btn save" @click="saveProfilePassword()">Update password</button>
                            </div>
                            </form>
                        </div>

                        <!-- ===== SECURITY (2FA) ===== -->
                        <div x-show="profileModal.tab==='security'">
                            <!-- backup codes after enable/regen -->
                            <div x-show="mfaModal.step==='codes'">
                                <div class="room-alert" style="color:#fbbf24;margin-bottom:14px">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                                    <span>Save these backup codes now. Each works once if you lose your phone. They won't be shown again.</span>
                                </div>
                                <div class="backup-codes">
                                    <template x-for="c in mfaModal.codes" :key="c"><div class="bcode" x-text="c"></div></template>
                                </div>
                                <div style="display:flex;gap:8px;margin-top:14px">
                                    <button class="btn" @click="copyBackupCodes()">Copy codes</button>
                                    <div style="flex:1"></div>
                                    <button class="btn save" @click="mfaModal.step='on'; refreshMfaStatus()">Done</button>
                                </div>
                            </div>

                            <!-- OFF → enroll -->
                            <div x-show="mfaModal.step==='off'">
                                <div class="glance-section"><span class="gs-label">Two-factor authentication</span><span class="gs-line"></span></div>
                                <p class="muted-hint" style="margin:8px 0 14px">Add a second step at sign-in. You'll need an authenticator app like Google Authenticator, Microsoft Authenticator, Authy, or 1Password.</p>
                                <button class="btn save" @click="startMfaSetup()">Set up two-factor</button>
                            </div>

                            <!-- enrolling -->
                            <div x-show="mfaModal.step==='setup'">
                                <div class="glance-section"><span class="gs-label">Scan to enroll</span><span class="gs-line"></span></div>
                                <div class="qr-wrap"><div id="mfaQr" x-ref="mfaQr"></div></div>
                                <div class="secret-row">
                                    <span class="muted-hint" style="margin:0">Can't scan? Enter this key:</span>
                                    <code class="secret-code" x-text="mfaModal.secret"></code>
                                </div>
                                <label style="display:block;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 6px">Enter the 6-digit code to confirm</label>
                                <input type="text" inputmode="numeric" maxlength="6" x-model="mfaModal.code" placeholder="123456"
                                       style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:11px 13px;font-size:18px;letter-spacing:4px;text-align:center;font-family:monospace;outline:none"
                                       @keydown.enter="confirmMfaEnable()">
                                <div style="display:flex;gap:8px;margin-top:14px">
                                    <button class="btn" @click="mfaModal.step='off'; mfaModal.code=''">Cancel</button>
                                    <div style="flex:1"></div>
                                    <button class="btn save" @click="confirmMfaEnable()">Verify &amp; enable</button>
                                </div>
                            </div>

                            <!-- ON → manage -->
                            <div x-show="mfaModal.step==='on'">
                                <div class="mfa-on-banner">
                                    <div class="mfa-on-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></div>
                                    <div>
                                        <div style="font-weight:700;font-size:14px;color:var(--text-primary)">Two-factor is on</div>
                                        <div class="muted-hint" style="margin:2px 0 0" x-text="mfaModal.backupRemaining + ' backup code' + (mfaModal.backupRemaining===1?'':'s') + ' remaining'"></div>
                                    </div>
                                </div>
                                <div class="mfa-action-row">
                                    <div>
                                        <div style="font-size:13px;font-weight:600;color:var(--text-primary)">Backup codes</div>
                                        <div class="muted-hint" style="margin:0">Regenerate if you've run low or misplaced them.</div>
                                    </div>
                                    <button class="btn tiny" @click="mfaModal.confirmMode='regen'; mfaModal.code=''">Regenerate</button>
                                </div>
                                <div class="mfa-action-row">
                                    <div>
                                        <div style="font-size:13px;font-weight:600;color:var(--text-primary)">Turn off two-factor</div>
                                        <div class="muted-hint" style="margin:0">Your account will rely on just your password.</div>
                                    </div>
                                    <button class="btn tiny danger" @click="mfaModal.confirmMode='disable'; mfaModal.code=''">Disable</button>
                                </div>
                                <div x-show="mfaModal.confirmMode" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border)">
                                    <label style="display:block;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px"
                                           x-text="mfaModal.confirmMode==='disable' ? 'Enter a code to turn off MFA' : 'Enter a code to regenerate'"></label>
                                    <input type="text" inputmode="numeric" maxlength="6" x-model="mfaModal.code" placeholder="123456"
                                           style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:9px;color:var(--text-primary);padding:10px 13px;font-size:16px;letter-spacing:3px;text-align:center;font-family:monospace;outline:none"
                                           @keydown.enter="mfaModal.confirmMode==='disable' ? disableMfa() : regenCodes()">
                                    <div style="display:flex;gap:8px;margin-top:10px">
                                        <button class="btn" @click="mfaModal.confirmMode=''; mfaModal.code=''">Cancel</button>
                                        <div style="flex:1"></div>
                                        <button class="btn save" x-show="mfaModal.confirmMode==='regen'" @click="regenCodes()">Regenerate codes</button>
                                        <button class="btn danger" x-show="mfaModal.confirmMode==='disable'" @click="disableMfa()">Turn off MFA</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- CHANGE PASSWORD MODAL -->
    <div class="modal-backdrop" x-show="pwModal.open" x-transition.opacity @click.self="pwModal.forced ? null : (pwModal.open=false)" x-cloak>
        <div class="modal-card" style="max-width:400px" x-show="pwModal.open" x-transition>
            <div class="modal-head">
                <div class="modal-head-text">
                    <div class="modal-title" x-text="pwModal.forced ? 'Set a new password' : 'Change password'"></div>
                    <div class="modal-sub" x-show="pwModal.forced">You're using the default password. Please set your own to continue.</div>
                </div>
                <button class="modal-close" x-show="!pwModal.forced" @click="pwModal.open=false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-body">
                <form onsubmit="return false" autocomplete="off">
                <input type="text" :value="currentUser.username || ''" autocomplete="username" aria-hidden="true" tabindex="-1" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px">
                <div class="field">
                    <label>New password</label>
                    <input type="password" x-model="pwModal.p1" placeholder="At least 8 characters" autocomplete="new-password">
                </div>
                <div class="field">
                    <label>Confirm password</label>
                    <input type="password" x-model="pwModal.p2" placeholder="Re-enter password" autocomplete="new-password" @keydown.enter="submitPassword()">
                </div>
                </form>
            </div>
            <div class="modal-foot">
                <button class="btn" x-show="!pwModal.forced" @click="pwModal.open=false">Cancel</button>
                <button class="btn primary" @click="submitPassword()">Update password</button>
            </div>
        </div>
    </div>

    <!-- SYSTEM SETTINGS MODAL (admin) -->
    <!-- ============ PRINTER IMPORT MODAL (PrinterLogic CSV) ============ -->
    <div class="modal-backdrop" x-show="printerImport.open" x-transition.opacity @click.self="printerImport.open=false" x-cloak>
        <div class="modal-card" style="max-width:880px" x-show="printerImport.open" x-transition>
            <div class="modal-head">
                <div>
                    <div class="modal-title">Import printers</div>
                    <div class="modal-sub">From a PrinterLogic CSV export</div>
                </div>
                <button class="modal-close" @click="printerImport.open=false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:18px 20px">
                <!-- Step 1: pick file -->
                <template x-if="!printerImport.rows.length">
                    <div>
                        <div class="invite-intro">Export your printers from PrinterLogic as CSV, then choose the file here. I'll match each printer to a site by the code in parentheses in its folder (e.g. <strong>(OM)</strong> → site abbreviation OM), build the web link from the IP, and skip any whose serial already exists. You'll review everything before it imports.</div>
                        <input type="file" accept=".csv,text/csv" @change="onPrinterCsv($event)" class="csv-file">
                        <div class="err" x-show="printerImport.error" x-text="printerImport.error" style="margin-top:10px"></div>
                    </div>
                </template>

                <!-- Step 2: preview -->
                <template x-if="printerImport.rows.length">
                    <div>
                        <div class="import-summary">
                            <span><strong x-text="printerImport.rows.filter(r=>!r.dup && r.site_number).length"></strong> ready</span>
                            <span class="is-warn" x-show="printerImport.rows.filter(r=>!r.site_number && !r.dup).length"><strong x-text="printerImport.rows.filter(r=>!r.site_number && !r.dup).length"></strong> need a site</span>
                            <span class="is-dim" x-show="printerImport.rows.filter(r=>r.dup).length"><strong x-text="printerImport.rows.filter(r=>r.dup).length"></strong> duplicate (will skip)</span>
                        </div>

                        <!-- Filter toggle: control which rows show in the preview -->
                        <div class="imp-filterbar">
                            <button class="imp-fbtn" :class="{active:printerImport.filter==='all'}" @click="printerImport.filter='all'">All</button>
                            <button class="imp-fbtn" :class="{active:printerImport.filter==='hidedup'}" @click="printerImport.filter='hidedup'">Hide duplicates</button>
                            <button class="imp-fbtn" :class="{active:printerImport.filter==='needsite'}" @click="printerImport.filter='needsite'">Needs a site</button>
                            <button class="imp-fbtn" :class="{active:printerImport.filter==='ready'}" @click="printerImport.filter='ready'">Ready</button>
                        </div>

                        <!-- Bulk bar: appears when rows are selected -->
                        <div class="imp-bulkbar" x-show="printerImport.selected.length" x-transition x-cloak>
                            <span class="bulk-count" x-text="printerImport.selected.length + ' selected'"></span>
                            <span class="imp-bulk-label">Set site:</span>
                            <select x-model.number="printerImport.bulkSite" class="imp-bulk-site">
                                <option :value="0">— pick a site —</option>
                                <template x-for="s in sites" :key="'ibs-'+s.id">
                                    <option :value="s.id" x-text="s.name + (s.abbr?(' ('+s.abbr+')'):'')"></option>
                                </template>
                            </select>
                            <button class="btn tiny save" @click="importBulkSetSite()">Apply to selected</button>
                            <div style="flex:1"></div>
                            <button class="btn tiny ghost" @click="importClearSelection()">Clear</button>
                        </div>

                        <div class="import-table-wrap">
                            <table class="import-table">
                                <thead><tr>
                                    <th style="width:34px"><input type="checkbox" :checked="importAllVisibleSelected" @change="importToggleSelectAll()"></th>
                                    <th>Printer</th><th>Folder → Site</th><th>IP / Link</th><th>Serial</th><th></th>
                                </tr></thead>
                                <tbody>
                                    <template x-for="x in visibleImportRows" :key="'imp-'+x.i">
                                        <tr :class="{dup:x.r.dup, 'imp-sel':importIsSelected(x.i)}">
                                            <td><input type="checkbox" :checked="importIsSelected(x.i)" :disabled="x.r.dup" @change="importSelectToggle(x.i)"></td>
                                            <td x-text="x.r.printer_name"></td>
                                            <td>
                                                <div class="imp-folder" x-text="x.r.folder" :title="x.r.folder"></div>
                                                <select x-model.number="x.r.site_number" class="imp-site" :class="{unset:!x.r.site_number}">
                                                    <option :value="0">— pick a site —</option>
                                                    <template x-for="s in sites" :key="'is-'+s.id">
                                                        <option :value="s.id" x-text="s.name + (s.abbr?(' ('+s.abbr+')'):'')"></option>
                                                    </template>
                                                </select>
                                            </td>
                                            <td x-text="x.r.web_interface || '—'"></td>
                                            <td><span x-text="x.r.serial_number || '—'"></span><span class="dup-tag" x-show="x.r.dup">dup</span></td>
                                            <td><button class="ur-icon" @click="printerImport.rows.splice(x.i,1); importClearSelection()" title="Remove from import">✕</button></td>
                                        </tr>
                                    </template>
                                    <template x-if="!visibleImportRows.length">
                                        <tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text-dim)">No rows match this filter.</td></tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div class="modal-foot" style="margin-top:14px">
                            <button class="btn" @click="printerImport.rows=[]; printerImport.error=''; importClearSelection()">← Choose a different file</button>
                            <div style="flex:1"></div>
                            <button class="btn save" :disabled="printerImport.busy || !printerImport.rows.some(r=>r.site_number && !r.dup)"
                                    @click="runPrinterImport()"
                                    x-text="printerImport.busy ? 'Importing…' : ('Import ' + printerImport.rows.filter(r=>r.site_number && !r.dup).length + ' printers')"></button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ============ PEOPLE IMPORT MODAL (staff CSV) ============ -->
    <div class="modal-backdrop" x-show="peopleImport.open" x-transition.opacity @click.self="peopleImport.open=false" x-cloak>
        <div class="modal-card data-editor-modal" x-show="peopleImport.open" x-transition>
            <div class="modal-head" style="justify-content:space-between">
                <div>
                    <div class="modal-title">Import people</div>
                    <div class="modal-sub">From a staff CSV (Last Name, First Name, Position, Extension, Room Number, Email)</div>
                </div>
                <button class="modal-close" @click="peopleImport.open=false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="de-body" style="padding:18px 20px">
                <!-- Step 1: pick file -->
                <template x-if="!peopleImport.rows.length">
                    <div>
                        <div class="invite-intro">Pick a staff CSV. I'll detect the columns automatically — names, position, extension, room number, and email in any order. For each person you'll choose a <strong>site</strong>, and if their room doesn't exist yet you can <strong>create it</strong>, pick an existing room, or skip. People are added to rooms (existing occupants are kept).</div>
                        <input type="file" accept=".csv,text/csv" @change="onPeopleCsv($event)" class="csv-file">
                        <div class="err" x-show="peopleImport.error" x-text="peopleImport.error" style="margin-top:10px"></div>
                    </div>
                </template>

                <!-- Step 2: review -->
                <template x-if="peopleImport.rows.length">
                    <div style="display:flex;flex-direction:column;min-height:0;flex:1">
                        <div class="import-summary">
                            <span><strong x-text="peopleReadyCount"></strong> ready</span>
                            <span class="is-warn" x-show="peopleNeedRoomCount"><strong x-text="peopleNeedRoomCount"></strong> need a room</span>
                            <span class="is-dim" x-show="peopleSkipCount"><strong x-text="peopleSkipCount"></strong> will skip</span>
                        </div>

                        <!-- Filter toggle + site filter/sort -->
                        <div class="imp-filterbar">
                            <button class="imp-fbtn" :class="{active:peopleImport.filter==='all'}" @click="peopleImport.filter='all'">All</button>
                            <button class="imp-fbtn" :class="{active:peopleImport.filter==='needroom'}" @click="peopleImport.filter='needroom'">Needs a room</button>
                            <button class="imp-fbtn" :class="{active:peopleImport.filter==='ready'}" @click="peopleImport.filter='ready'">Ready</button>
                            <button class="imp-fbtn" :class="{active:peopleImport.filter==='nosite'}" @click="peopleImport.filter='nosite'">No site matched</button>
                            <div style="flex:1"></div>
                            <select class="imp-bulk-site" x-model.number="peopleImport.siteFilter">
                                <option :value="0">All sites</option>
                                <template x-for="s in peopleImportSites" :key="'pisf-'+s.id"><option :value="s.id" x-text="s.name"></option></template>
                            </select>
                            <button class="imp-fbtn" :class="{active:peopleImport.sortBySite}" @click="peopleImport.sortBySite = !peopleImport.sortBySite" title="Group rows by site">Sort by site</button>
                        </div>

                        <!-- Bulk bar -->
                        <div class="imp-bulkbar" x-show="peopleImport.selected.length" x-transition x-cloak>
                            <span class="bulk-count" x-text="peopleImport.selected.length + ' selected'"></span>
                            <span class="imp-bulk-label">Set site:</span>
                            <select x-model.number="peopleImport.bulkSite" class="imp-bulk-site">
                                <option :value="0">— pick a site —</option>
                                <template x-for="s in sites" :key="'pbs-'+s.id"><option :value="s.id" x-text="s.name + (s.abbr?(' ('+s.abbr+')'):'')"></option></template>
                            </select>
                            <button class="btn tiny save" @click="peopleBulkSetSite()">Apply site</button>
                            <button class="btn tiny" @click="peopleBulkSetAction('create')">All → create room</button>
                            <button class="btn tiny ghost" @click="peopleBulkSetAction('skip')">All → skip</button>
                            <div style="flex:1"></div>
                            <button class="btn tiny ghost" @click="peopleImport.selected=[]">Clear</button>
                        </div>

                        <div class="import-table-wrap" style="flex:1;min-height:0;max-height:none">
                            <table class="import-table">
                                <thead><tr>
                                    <th style="width:30px"><input type="checkbox" :checked="peopleAllVisibleSelected" @change="peopleToggleSelectAll()"></th>
                                    <th>Person</th><th>Position / Ext / Email</th><th>Site</th><th>Room</th><th></th>
                                </tr></thead>
                                <tbody>
                                    <template x-for="x in visiblePeopleRows" :key="'pip-'+x.i">
                                        <tr :class="{'imp-sel':peopleIsSelected(x.i)}">
                                            <td><input type="checkbox" :checked="peopleIsSelected(x.i)" @change="peopleSelectToggle(x.i)"></td>
                                            <td>
                                                <div style="font-weight:600" x-text="x.r.name"></div>
                                                <div class="imp-folder" x-text="'room in CSV: ' + (x.r.room_number || '—')"></div>
                                            </td>
                                            <td>
                                                <div x-text="x.r.role || '—'"></div>
                                                <div class="imp-folder">
                                                    <span x-show="x.r.extension" x-text="'x'+x.r.extension"></span>
                                                    <span x-show="x.r.email" x-text="' · '+x.r.email"></span>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="imp-folder" x-show="x.r.site_raw" :title="x.r.site_raw">
                                                    <span x-show="x.r.site_number" style="color:#22c55e">✓</span>
                                                    <span x-show="!x.r.site_number" style="color:#f59e0b">⚠</span>
                                                    <span x-text="x.r.site_raw"></span>
                                                </div>
                                                <select x-model.number="x.r.site_number" class="imp-site" :class="{unset:!x.r.site_number}" @change="x.r.room_id=0; peopleAutoMatch(x.r)">
                                                    <option :value="0">— site —</option>
                                                    <template x-for="s in sites" :key="'pis-'+x.i+'-'+s.id"><option :value="s.id" x-text="s.name + (s.abbr?(' ('+s.abbr+')'):'')"></option></template>
                                                </select>
                                            </td>
                                            <td>
                                                <select x-model="x.r.room_action" class="imp-site">
                                                    <option value="skip">Skip person</option>
                                                    <option value="match" :disabled="!x.r.site_number">Pick existing room…</option>
                                                    <option value="create" :disabled="!x.r.site_number || !x.r.room_number">Create room “<span x-text="x.r.room_number"></span>”</option>
                                                </select>
                                                <select x-show="x.r.room_action==='match'" x-model.number="x.r.room_id" class="imp-site" :class="{unset:!x.r.room_id}" style="margin-top:4px">
                                                    <option :value="0">— pick room —</option>
                                                    <template x-for="rm in roomsForSite(x.r.site_number)" :key="'pir-'+x.i+'-'+rm.id"><option :value="rm.id" x-text="rm.label"></option></template>
                                                </select>
                                            </td>
                                            <td><button class="ur-icon" @click="peopleImport.rows.splice(x.i,1); peopleImport.selected=[]" title="Remove">✕</button></td>
                                        </tr>
                                    </template>
                                    <template x-if="!visiblePeopleRows.length"><tr><td colspan="6" style="text-align:center;padding:20px;color:var(--text-dim)">No rows match this filter.</td></tr></template>
                                </tbody>
                            </table>
                        </div>
                        <div class="modal-foot" style="margin-top:14px">
                            <button class="btn" @click="peopleImport.rows=[]; peopleImport.error=''; peopleImport.selected=[]">← Choose a different file</button>
                            <div style="flex:1"></div>
                            <button class="btn save" :disabled="peopleImport.busy || !peopleReadyCount"
                                    @click="runPeopleImport()"
                                    x-text="peopleImport.busy ? 'Importing…' : ('Import ' + peopleReadyCount + ' people')"></button>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <!-- ============ PRINTER INFO CARD ============ -->
    <div class="modal-backdrop" x-show="selectedPrinter" x-transition.opacity @click.self="closePrinterInfo()" x-cloak>
        <div class="modal-card" style="max-width:440px" x-show="selectedPrinter" x-transition>
            <template x-if="selectedPrinter">
                <div>
                    <div class="modal-head">
                        <div>
                            <div class="modal-title" x-text="selectedPrinter.printer_name"></div>
                            <div class="modal-sub" x-text="selectedPrinter.location || 'No location set'"></div>
                        </div>
                        <button class="modal-close" @click="closePrinterInfo()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="pr-card-body">
                        <div class="pr-info-grid">
                            <template x-if="selectedPrinter.location"><div class="pr-info"><span class="pr-k">Location</span><span class="pr-v" x-text="selectedPrinter.location"></span></div></template>
                            <template x-if="selectedPrinter.model"><div class="pr-info"><span class="pr-k">Model</span><span class="pr-v" x-text="selectedPrinter.model"></span></div></template>
                            <template x-if="selectedPrinter.serial_number"><div class="pr-info"><span class="pr-k">Serial</span><span class="pr-v" x-text="selectedPrinter.serial_number"></span></div></template>
                            <template x-if="selectedPrinter.mac_address"><div class="pr-info"><span class="pr-k">MAC</span><span class="pr-v" x-text="selectedPrinter.mac_address"></span></div></template>
                            <template x-if="selectedPrinter.toner_id"><div class="pr-info"><span class="pr-k">Toner ID</span><span class="pr-v" x-text="selectedPrinter.toner_id"></span></div></template>
                            <template x-if="selectedPrinter.barcode"><div class="pr-info"><span class="pr-k">Barcode</span><span class="pr-v" x-text="selectedPrinter.barcode"></span></div></template>
                            <template x-if="selectedPrinter.web_interface"><div class="pr-info"><span class="pr-k">Web</span><a class="pr-v pr-link" :href="selectedPrinter.web_interface" target="_blank" rel="noopener noreferrer" x-text="selectedPrinter.web_interface"></a></div></template>
                        </div>
                        <template x-if="selectedPrinter.notes">
                            <div class="pr-notes" x-text="selectedPrinter.notes"></div>
                        </template>
                    </div>
                    <div class="modal-foot pr-card-foot">
                        <template x-if="selectedPrinter.web_interface">
                            <a class="btn primary" :href="selectedPrinter.web_interface" target="_blank" rel="noopener noreferrer">Open web interface ↗</a>
                        </template>
                        <div style="flex:1"></div>
                        <button class="btn edit-only" x-show="can('printers','edit')" @click="editPrinter(selectedPrinter)">Edit</button>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- ============ PRINTER EDITOR MODAL ============ -->
    <div class="modal-backdrop" x-show="printerForm.open" x-transition.opacity @click.self="printerForm.open=false" x-cloak>
        <div class="modal-card" style="max-width:520px" x-show="printerForm.open" x-transition>
            <div class="modal-head">
                <div>
                    <div class="modal-title" x-text="printerForm.printer_id ? 'Edit printer' : 'Add printer'"></div>
                    <div class="modal-sub">Drop it on the map after saving, or drag it in edit mode.</div>
                </div>
                <button class="modal-close" @click="printerForm.open=false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:18px 20px">
                <div class="modal-grid">
                    <div class="field"><label>Printer name *</label><input type="text" x-model="printerForm.printer_name" placeholder="e.g. Front Office MFP"></div>
                    <div class="field"><label>Location</label><input type="text" x-model="printerForm.location" placeholder="e.g. Room 100, by the copier"></div>
                </div>
                <div class="field"><label>Web interface (link)</label><input type="text" x-model="printerForm.web_interface" placeholder="e.g. http://10.0.5.21"></div>
                <div class="modal-grid">
                    <div class="field"><label>Model</label><input type="text" x-model="printerForm.model"></div>
                    <div class="field"><label>Serial number</label><input type="text" x-model="printerForm.serial_number"></div>
                    <div class="field"><label>MAC address</label><input type="text" x-model="printerForm.mac_address" placeholder="00:1B:44:11:3A:B7"></div>
                    <div class="field"><label>Toner ID</label><input type="text" x-model="printerForm.toner_id" placeholder="e.g. TN-660"></div>
                    <div class="field"><label>Barcode</label><input type="text" x-model="printerForm.barcode"></div>
                </div>
                <div class="field"><label>Notes</label><textarea x-model="printerForm.notes" rows="3" placeholder="Anything worth recording…"></textarea></div>
                <div class="modal-foot">
                    <button class="btn danger" x-show="printerForm.printer_id" @click="deletePrinter(printerForm)">Delete</button>
                    <div style="flex:1"></div>
                    <button class="btn" @click="printerForm.open=false">Cancel</button>
                    <button class="btn save" @click="savePrinter()">Save printer</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MAPS MANAGER MODAL (suites / floors) ============ -->
    <div class="modal-backdrop" x-show="mapMgr.open" x-transition.opacity @click.self="mapMgr.open=false" x-cloak>
        <div class="modal-card" style="max-width:520px" x-show="mapMgr.open" x-transition>
            <div class="modal-head">
                <div>
                    <div class="modal-title">Maps</div>
                    <div class="modal-sub" x-text="currentSite ? currentSite.name : ''"></div>
                </div>
                <button class="modal-close" @click="mapMgr.open=false">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div style="padding:18px 20px">
                <div class="invite-intro">A site can have several maps — for a leased building, one per suite (Suite A, Suite B, Suite D); for a multi-story site, one per floor (Floor 1, Floor 2). Each map holds its own rooms and floor-plan image. Search still covers the whole site and jumps to the right map.</div>

                <div class="bld-list">
                    <template x-for="m in mapMgr.maps" :key="'mm-'+m.id">
                        <div class="mm-card">
                            <div class="mm-card-top">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="mm-card-ic"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/></svg>
                                <input type="text" :value="m.name" @change="renameMap(m, $event.target.value)" class="map-name-input">
                                <span class="bld-usage" x-text="(m.has_svg ? 'has map' : 'no image') + ' · ' + roomCountForMap(m.key) + ' rooms'"></span>
                                <button class="ur-icon" @click="deleteMap(m)" title="Delete map">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                                </button>
                            </div>
                            <div class="mm-card-controls">
                                <button class="mm-default" :class="{on:m.is_default}" @click="setDefaultMap(m)" :title="m.is_default ? 'This is the default level shown first' : 'Make this the default level'">
                                    <svg viewBox="0 0 24 24" :fill="m.is_default ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                    <span x-text="m.is_default ? 'Default' : 'Set default'"></span>
                                </button>
                                <div class="mm-zoom" title="Zoom this map opens at. Blank = auto-fit.">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                                    <span class="mm-zoom-label">Opens at</span>
                                    <input type="number" min="10" max="2000" step="10" class="mm-zoom-input"
                                           :value="m.default_zoom ? Math.round(m.default_zoom*100) : ''"
                                           placeholder="auto"
                                           @change="setMapZoom(m, $event.target.value)">
                                    <span class="mm-zoom-pct">%</span>
                                </div>
                                <button class="mm-zoom-use" @click="setMapZoom(m, Math.round(mapZoom*100))" :disabled="m.key!==selectedLevel" :title="m.key===selectedLevel ? 'Save the current on-screen zoom for this map' : 'Switch to this map first'">Use current</button>
                                <div class="mm-zoom" title="Zoom below which pins collapse to mini dots on this map. Blank = default (160%). 0 = never — usually right for small maps, where dots hurt more than help.">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><circle cx="12" cy="12" r="9" stroke-dasharray="2 3"/></svg>
                                    <span class="mm-zoom-label">Mini pins</span>
                                    <input type="number" min="0" max="2000" step="10" class="mm-zoom-input"
                                           :value="(m.dot_zoom===null || m.dot_zoom===undefined) ? '' : Math.round(m.dot_zoom*100)"
                                           placeholder="160"
                                           @change="setMapDotZoom(m, $event.target.value)">
                                    <span class="mm-zoom-pct">%</span>
                                </div>
                            </div>
                        </div>
                    </template>
                    <div class="muted-note" x-show="!mapMgr.maps.length" style="padding:8px 0">No maps yet. Add one below (e.g. “Suite A” or “Floor 1”).</div>
                </div>

                <div class="bld-add">
                    <input type="text" x-model="mapMgr.newName" placeholder="New map name (e.g. Suite A)" @keydown.enter="addMap()">
                    <button class="btn save" :disabled="mapMgr.busy || !mapMgr.newName.trim()" @click="addMap()">Add map</button>
                </div>
                <div class="muted-note" style="margin-top:10px">Tip: after adding a map, select it from the switcher, then use <strong>⋯ → Upload SVG for current map</strong> to give it a floor plan.</div>
            </div>
        </div>
    </div>

    <!-- (Building pool management moved into System Settings → Buildings.) -->



    <!-- SESSION TIMEOUT WARNING -->
    <div class="modal-backdrop" x-show="sessionWarn.show" x-transition.opacity x-cloak style="z-index:300">
        <div class="modal-card" style="max-width:380px" x-show="sessionWarn.show" x-transition>
            <div class="modal-body" style="text-align:center;padding-top:24px">
                <div class="sw-ring">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                </div>
                <div class="modal-title" style="margin-top:12px">Still there?</div>
                <p class="muted-hint" style="margin:8px 0 0;font-size:13px">You'll be signed out in <strong x-text="sessionWarn.countdownLabel" style="color:var(--text-primary)"></strong> due to inactivity.</p>
            </div>
            <div class="modal-foot" style="justify-content:center">
                <button class="btn" @click="doLogoutNow()">Sign out</button>
                <button class="btn primary" @click="staySignedIn()">Stay signed in</button>
            </div>
        </div>
    </div>

    <!-- Drag ghost: shown while dragging a device from the list onto the map -->
    <div class="device-drag-ghost" x-show="listDrag.active" x-cloak
         :class="{over:listDrag.over}"
         :style="'left:'+listDrag.x+'px;top:'+listDrag.y+'px'">
        <div class="device-icon" x-show="listDrag.dev"
             :style="'--device-color:'+(listDrag.dev ? typeColor(listDrag.dev.device_type_key) : 'var(--accent)')"
             x-html="listDrag.dev ? typeIconSvg(listDrag.dev.device_type_key) : ''"></div>
        <span class="ddg-label" x-text="listDrag.over ? 'Drop to place' : 'Drag onto the map'"></span>
    </div>

    <!-- Camera feed modal (expanded live view, NVR-style: video + info underneath) -->
    <div class="modal-backdrop" x-show="feedModal.open" x-cloak x-transition.opacity @click.self="closeCameraFeed()" style="z-index:320"
         @keydown.arrow-left.window="feedModal.open && cycleFeedCam(-1)"
         @keydown.arrow-right.window="feedModal.open && cycleFeedCam(1)"
         @keydown.escape.window="feedModal.open && closeCameraFeed()">
        <div class="feed-modal" id="feedModalCard" x-show="feedModal.open" x-transition>
            <template x-if="feedModal.cam">
                <div class="feed-modal-inner">
                    <div class="feed-modal-body">
                        <template x-if="cameraIsOnline(feedModal.cam) && feedModal.cam.stream_main">
                            <iframe :src="feedModal.cam.stream_main" style="width:100%;height:100%;border:none;display:block"></iframe>
                        </template>
                        <template x-if="!(cameraIsOnline(feedModal.cam) && feedModal.cam.stream_main)">
                            <div class="feed-modal-empty">
                                <span x-show="!cameraIsOnline(feedModal.cam)">This camera is offline.</span>
                                <span x-show="cameraIsOnline(feedModal.cam) && !feedModal.cam.stream_main">No stream URL configured for this camera.</span>
                            </div>
                        </template>
                        <!-- overlaid controls -->
                        <div class="fm-toolbar">
                            <button class="fm-btn" @click="toggleFeedFullscreen()" title="Fullscreen">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/></svg>
                            </button>
                            <button class="fm-btn" @click="closeCameraFeed()" title="Close">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                        <!-- prev / next: cycle through this site's cameras in display order -->
                        <button class="fm-nav prev" x-show="feedModalList.length > 1" @click.stop="cycleFeedCam(-1)" title="Previous camera">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <button class="fm-nav next" x-show="feedModalList.length > 1" @click.stop="cycleFeedCam(1)" title="Next camera">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/></svg>
                        </button>
                        <div class="fm-counter" x-show="feedModalList.length > 1" x-text="(feedModalIndex+1) + ' / ' + feedModalList.length"></div>
                    </div>
                    <div class="feed-modal-info">
                        <div class="fmi-head">
                            <span class="fm-dot" :class="cameraIsOnline(feedModal.cam)?'online':'offline'"></span>
                            <span class="fmi-name" x-text="feedModal.cam.camera_name"></span>
                            <span class="fmi-state" :class="{on:cameraIsOnline(feedModal.cam)}" x-text="cameraStatusText(feedModal.cam)"></span>
                        </div>
                        <div class="fmi-grid">
                            <div><div class="modal-field-label">Camera #</div><div class="modal-field-value" x-text="'#'+feedModal.cam.camera_number"></div></div>
                            <div><div class="modal-field-label">IP address</div><div class="modal-field-value mono" x-text="feedModal.cam.camera_ip||'—'"></div></div>
                            <div><div class="modal-field-label">Site</div><div class="modal-field-value" x-text="siteName(feedModal.cam.site_number, 'Site '+feedModal.cam.site_number)"></div></div>
                            <div><div class="modal-field-label">Last seen</div><div class="modal-field-value" x-text="formatLastSeen(feedModal.cam.last_update)"></div></div>
                        </div>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- TOAST -->
    <div class="toast" :class="toast.kind" x-show="toast.show" x-transition x-cloak x-text="toast.msg"></div>

</div>

    <!-- Server-rendered boot state for assets/app.js (read as BOOT). -->
    <script type="application/json" id="sm-boot">{
        "brandLogo": <?= json_encode(setting_get($pdo, 'site_logo_path', '')) ?>,
        "brandName": <?= json_encode(setting_get($pdo, 'site_brand_name', 'Site Manager') ?: 'Site Manager') ?>,
        "cameraWallEnabled": <?= json_encode(setting_get($pdo, 'layer_cameras_enabled', '1') === '1') ?>,
        "cameras": <?= $cameras_json ?>,
        "camerasAdmin": <?= $cameras_admin_json ?>,
        "canDataAdmin": <?= json_encode(can($pdo, 'data_admin', 'manage')) ?>,
        "canEditAny": <?= json_encode(can($pdo, 'base', 'edit') || can($pdo, 'cameras', 'edit') || can($pdo, 'printers', 'edit') || can($pdo, 'devices', 'edit')) ?>,
        "deviceTypes": <?= $device_types_json ?>,
        "devices": <?= $devices_json ?>,
        "isAdmin": <?= json_encode($is_admin) ?>,
        "isGlassbreak": <?= json_encode(is_glassbreak()) ?>,
        "mustChangePassword": <?= json_encode(!empty($_SESSION['must_change_password'])) ?>,
        "myGroups": <?= json_encode((function() use ($pdo, $current_user) {
            if (is_glassbreak()) return ['Super Admin'];
            try {
                $st = $pdo->prepare("SELECT g.name FROM perm_user_group ug JOIN perm_group g ON g.group_id = ug.group_id WHERE ug.user_id = ? ORDER BY g.name");
                $st->execute([(int)$current_user['user_id']]);
                return array_map(fn($r) => $r['name'], $st->fetchAll());
            } catch (\Throwable $e) { return []; }
        })()) ?>,
        "neverExpire": <?= json_encode(!empty($current_user['never_expire'])) ?>,
        "perms": <?= json_encode([
            'base'     => perm_effective_level($pdo, 'base'),
            'cameras'  => perm_effective_level($pdo, 'cameras'),
            'printers' => perm_effective_level($pdo, 'printers'),
            'devices'  => perm_effective_level($pdo, 'devices'),
            'audit'    => perm_effective_level($pdo, 'audit'),
            'settings' => perm_effective_level($pdo, 'settings'),
            'manage_users' => perm_effective_level($pdo, 'manage_users'),
            'data_admin'   => perm_effective_level($pdo, 'data_admin'),
            'notifications'=> perm_effective_level($pdo, 'notifications'),
        ]) ?>,
        "printers": <?= $printers_json ?? '[]' ?>,
        "printersEnabled": <?= json_encode(setting_get($pdo, 'layer_printers_enabled', '1') === '1') ?>,
        "inheritBuilding": <?= json_encode(setting_get($pdo, 'room_inherit_building', '1') === '1') ?>,
        "roomTypeColors": <?= json_encode((function() use ($pdo) {
            $m = json_decode((string)setting_get($pdo, 'room_type_colors', '{}'), true);
            return is_array($m) ? $m : [];
        })()) ?>,
        "profileImage": <?= json_encode((string)($current_user['profile_image'] ?? '')) ?>,
        "publicId": <?= json_encode((string)$current_user['public_id']) ?>,
        "rooms": <?= $rooms_json ?>,
        "sessionTimeoutSecs": <?= json_encode(setting_int($pdo, 'session_timeout_minutes', 480) * 60) ?>,
        "sessionWarnSecs": <?= json_encode(setting_int($pdo, 'session_warn_minutes', 10) * 60) ?>,
        "siteCounts": <?= $site_counts_json ?>,
        "sites": <?= $sites_json ?>,
        "user": <?= json_encode(['username' => $current_user['username'], 'name' => $current_user['display_name'] ?: $current_user['username']]) ?>,
        "userRole": <?= json_encode($user_role) ?>
    }</script>
    <script src="assets/app.js?v=<?= APP_VERSION ?>" nonce="<?= $nonce ?>"></script>
</body>
</html>
