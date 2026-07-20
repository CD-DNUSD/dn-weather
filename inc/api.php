<?php
// ============================================================
// Site Manager — api.php
// The JSON API router: every ?api= endpoint handler.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================


// Convenience flags/values for the rest of the app.
// $is_admin now derives from the permission system (broad admin power), not the
// retired role column. True for glass-break or anyone who can manage users.
$is_admin   = is_glassbreak() || can($pdo, 'manage_users', 'manage');
$can_edit   = can($pdo, 'base', 'edit') || can($pdo, 'cameras', 'edit') || can($pdo, 'printers', 'edit') || can($pdo, 'devices', 'edit');
$user_role  = strtolower((string)$current_user['role']);
$my_sites   = accessible_site_numbers($pdo, $current_user); // null = all sites

// Opportunistic audit-log retention cleanup (admins only, ~1 in 20 page loads to
// keep it cheap — no cron needed).
if ($is_admin && random_int(1, 20) === 1) { prune_audit_log($pdo); }

// ================================================================
// USER MANAGEMENT API  (admin only)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'user') {
    if (!can($pdo, 'manage_users', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        try {
            $hasCam = users_has_camera_access($pdo); // (snippet may not have run yet)
            $hasLock = users_has_lockout($pdo);
            $hasEmail = users_has_email($pdo);
            $hasInv = users_has_invites($pdo);
            $hasAvatar = false;
            try { $pdo->query("SELECT profile_image FROM users LIMIT 1"); $hasAvatar = true; } catch (\Throwable $e) {}
            $cols = "user_id, public_id, username, display_name, role, is_active, last_login, created_at, mfa_enabled, never_expire, site_access"
                  . ($hasEmail ? ", email" : "")
                  . ($hasInv ? ", invite_status" : "")
                  . ($hasCam ? ", camera_access" : "")
                  . ($hasAvatar ? ", profile_image" : "")
                  . ($hasLock ? ", failed_attempts, locked_until, last_failed_at" : "");
            $rows = $pdo->query("SELECT $cols FROM users ORDER BY username ASC")->fetchAll();
            // Bulk-load group memberships (group ids + names) keyed by user_id.
            $memberships = [];
            try {
                $mq = $pdo->query("SELECT ug.user_id, g.group_id, g.name FROM perm_user_group ug JOIN perm_group g ON g.group_id = ug.group_id");
                foreach ($mq->fetchAll() as $m) {
                    $memberships[(int)$m['user_id']][] = ['group_id' => (int)$m['group_id'], 'name' => $m['name']];
                }
            } catch (\Throwable $e) { /* perm tables not present */ }
            $out = [];
            foreach ($rows as $r) {
                // The glass-break super admin is hidden from EVERYONE except itself.
                if (is_protected_username($r['username'] ?? '') && !is_glassbreak()) continue;
                // Backfill: public_id is generated only at creation/invite, so
                // accounts that predate it carry NULL forever. An empty id is what
                // made those rows unidentifiable to the client — their avatar URLs
                // went out as id='' (served the VIEWER'S photo back when serve had
                // its self-fallback), admin photo changes refused with "could not
                // identify", and removes targeted the caller. Heal them here: the
                // moment the list is opened, every user has a real public_id.
                // public_id must be present AND UNIQUE. "Every user has one" is
                // not enough: if the column was ever added with a shared default,
                // every legacy row carries the SAME id — and then every lookup
                // (avatar serve, admin photo targeting) resolves to the FIRST
                // matching row, typically user 1. That is precisely the symptom
                // of "everyone's photo tracks mine": each row's image request
                // dereferences to the same person. Empty AND duplicate ids are
                // both regenerated here, so the first open of this list makes
                // every user individually addressable.
                if (!isset($seenPub)) $seenPub = [];
                if (empty($r['public_id']) || isset($seenPub[$r['public_id']])) {
                    $r['public_id'] = generate_public_id();
                    try {
                        $pdo->prepare("UPDATE users SET public_id = ? WHERE user_id = ?")
                            ->execute([$r['public_id'], (int)$r['user_id']]);
                    } catch (\Throwable $e) { /* next open retries */ }
                }
                $seenPub[$r['public_id']] = true;
                // Same ownership rule as the serve endpoint: a profile_image that
                // lives in another user's folder is bug-era corruption. Clear it
                // (DB and payload) so the row honestly shows initials instead of
                // hiding a broken/foreign image; re-setting the photo fixes it
                // properly. The file itself is left alone — it may legitimately
                // belong to the folder's owner.
                if (!empty($r['profile_image'])) {
                    $pi  = (string)$r['profile_image'];
                    $uid = (int)$r['user_id'];
                    $own = (strpos($pi, 'uploads/avatars/' . $uid . '/') === 0)
                        || (strpos($pi, 'uploads/avatar-' . $uid . '-') === 0);
                    if (!$own) {
                        $r['profile_image'] = '';
                        try {
                            $pdo->prepare("UPDATE users SET profile_image = NULL WHERE user_id = ?")
                                ->execute([$uid]);
                        } catch (\Throwable $e) { /* next open retries */ }
                    }
                }
                $r['groups'] = $memberships[(int)$r['user_id']] ?? [];
                $r['is_active']    = (int)$r['is_active'];
                $r['mfa_enabled']  = (int)($r['mfa_enabled'] ?? 0);
                $r['never_expire'] = (int)($r['never_expire'] ?? 0);
                $r['email']        = $r['email'] ?? '';
                $r['invite_status'] = $r['invite_status'] ?? 'active';
                $sa = $r['site_access'] ?? null;
                $arr = is_array($sa) ? $sa : json_decode((string)$sa, true);
                $r['sites'] = is_array($arr) ? array_values(array_map('intval', $arr)) : [];
                unset($r['site_access']); // expose as `sites`
                // camera permissions as a structured object keyed by site number
                $ca = $r['camera_access'] ?? null;
                $caArr = is_array($ca) ? $ca : json_decode((string)$ca, true);
                $r['cameraAccess'] = is_array($caArr) ? $caArr : [];
                unset($r['camera_access']);
                // lock state for the UI
                $r['is_locked'] = $hasLock ? account_is_locked($r) : false;
                $r['lock_remaining'] = $hasLock ? account_lock_remaining($r) : 0;
                $r['failed_attempts'] = (int)($r['failed_attempts'] ?? 0);
                unset($r['user_id'], $r['locked_until']); // never expose internal id / raw timestamp
                $out[] = $r;
            }
            jsonResponse(['success' => true, 'users' => $out]);
        } catch (\Throwable $e) {
            error_log('User list failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not load users (run migration.sql?)'], 500);
        }
    }

    // Unlock a locked account (admins only — and admins CAN unlock other admins).
    // Body: { public_id }.
    if ($action === 'unlock' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!users_has_lockout($pdo)) jsonResponse(['success' => false, 'error' => 'Lockout not installed (run add_login_lockout.sql)'], 400);
        $in = jsonInput();
        $pubId = trim((string)($in['public_id'] ?? ''));
        if ($pubId === '') jsonResponse(['success' => false, 'error' => 'Missing user'], 400);
        try {
            $stmt = $pdo->prepare("SELECT user_id, username FROM users WHERE public_id = ?");
            $stmt->execute([$pubId]);
            $target = $stmt->fetch();
            if (!$target) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?")->execute([(int)$target['user_id']]);
            audit($pdo, 'user.unlock', ['target_type' => 'user', 'target_label' => $target['username']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('Unlock failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not unlock'], 500);
        }
    }

    // Invite a new user: create a pending account (no usable password) and email
    // them an activation link. Body: { username, email, display_name?, role, sites?, cameraAccess? }
    if ($action === 'invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!users_has_invites($pdo)) jsonResponse(['success' => false, 'error' => 'Invite system not installed (run add_user_invites.sql)'], 400);
        if (!users_has_email($pdo)) jsonResponse(['success' => false, 'error' => 'Email column missing (run add_password_reset.sql)'], 400);
        $in = jsonInput();
        $uname   = trim((string)($in['username'] ?? ''));
        $email   = trim((string)($in['email'] ?? ''));
        $display = trim((string)($in['display_name'] ?? '')) ?: $uname;
        // New permission model: a user's access comes from group memberships.
        $gids = array_values(array_unique(array_map('intval', (array)($in['group_ids'] ?? []))));
        if ($uname === '' || !preg_match('/^[A-Za-z0-9._-]{2,50}$/', $uname)) jsonResponse(['success' => false, 'error' => 'Enter a valid username (letters, numbers, . _ -)'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'error' => 'Enter a valid email address'], 400);
        if (is_protected_username($uname)) jsonResponse(['success' => false, 'error' => 'That username is reserved'], 400);
        try {
            $dup = $pdo->prepare("SELECT 1 FROM users WHERE username = ?");
            $dup->execute([$uname]);
            if ($dup->fetch()) jsonResponse(['success' => false, 'error' => 'That username already exists'], 409);
            $newPub = generate_public_id();
            // Unusable random password until they set their own via the invite link.
            $tempHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT);
            // role/site_access are legacy columns no longer used for access control;
            // insert benign defaults so old NOT NULL constraints stay satisfied.
            $pdo->prepare("INSERT INTO users (public_id, username, display_name, role, is_active, site_access, never_expire, password_hash, email, invite_status) VALUES (?,?,?,?,?,?,?,?,?, 'invited')")
                ->execute([$newPub, $uname, $display, 'viewer', 1, json_encode([]), 0, $tempHash, $email]);
            $uid = (int)$pdo->lastInsertId();
            // Assign group memberships (the actual permissions).
            if ($gids) {
                $insG = $pdo->prepare("INSERT INTO perm_user_group (user_id, group_id) VALUES (?, ?)");
                foreach ($gids as $g) { if ($g > 0) $insG->execute([$uid, $g]); }
            }
            audit($pdo, 'user.invite', ['target_type' => 'user', 'target_label' => $uname, 'details' => ['groups' => $gids, 'email' => $email]]);
            $res = send_invite($pdo, $uid, $uname, $email);
            if (!$res['success']) {
                // Account exists but the email failed — tell the admin so they can resend.
                jsonResponse(['success' => true, 'public_id' => $newPub, 'email_sent' => false, 'email_error' => $res['error']]);
            }
            jsonResponse(['success' => true, 'public_id' => $newPub, 'email_sent' => true]);
        } catch (\Throwable $e) {
            error_log('invite failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not create invite'], 500);
        }
    }

    // Resend a pending invite. Body: { public_id }
    if ($action === 'resend_invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!users_has_invites($pdo)) jsonResponse(['success' => false, 'error' => 'Invite system not installed'], 400);
        $in = jsonInput();
        $pubId = trim((string)($in['public_id'] ?? ''));
        try {
            $stmt = $pdo->prepare("SELECT user_id, username, email, invite_status FROM users WHERE public_id = ?");
            $stmt->execute([$pubId]);
            $t = $stmt->fetch();
            if (!$t) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            if (($t['invite_status'] ?? 'active') !== 'invited') jsonResponse(['success' => false, 'error' => 'That account is already active'], 400);
            if (empty($t['email'])) jsonResponse(['success' => false, 'error' => 'That user has no email on file'], 400);
            $res = send_invite($pdo, (int)$t['user_id'], $t['username'], $t['email']);
            audit($pdo, 'user.invite_resend', ['target_type' => 'user', 'target_label' => $t['username']]);
            if (!$res['success']) jsonResponse(['success' => false, 'error' => $res['error'] ?: 'Could not send invite email'], 500);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not resend invite'], 500);
        }
    }

    // Revoke a pending invite (deletes the pending account + its token). Body: { public_id }
    if ($action === 'revoke_invite' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!users_has_invites($pdo)) jsonResponse(['success' => false, 'error' => 'Invite system not installed'], 400);
        $in = jsonInput();
        $pubId = trim((string)($in['public_id'] ?? ''));
        try {
            $stmt = $pdo->prepare("SELECT user_id, username, invite_status FROM users WHERE public_id = ?");
            $stmt->execute([$pubId]);
            $t = $stmt->fetch();
            if (!$t) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            if (($t['invite_status'] ?? 'active') !== 'invited') jsonResponse(['success' => false, 'error' => 'Only pending invites can be revoked'], 400);
            $pdo->prepare("DELETE FROM password_reset_token WHERE user_id = ?")->execute([(int)$t['user_id']]);
            $pdo->prepare("DELETE FROM users WHERE user_id = ? AND invite_status = 'invited'")->execute([(int)$t['user_id']]);
            audit($pdo, 'user.invite_revoke', ['target_type' => 'user', 'target_label' => $t['username']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not revoke invite'], 500);
        }
    }

    // Create or update a user. Body: { public_id?, username, display_name, role,
    // is_active, password?, sites:[siteNumbers] }. New users get a fresh public_id.
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $publicId = trim((string)($in['public_id'] ?? ''));
        $uname    = trim((string)($in['username'] ?? ''));
        $display  = trim((string)($in['display_name'] ?? '')) ?: null;
        $role     = strtolower(trim((string)($in['role'] ?? 'viewer')));
        $active   = !empty($in['is_active']) ? 1 : 0;
        $pass     = (string)($in['password'] ?? '');
        $email    = trim((string)($in['email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'error' => 'That email address is not valid'], 400);
        $sites    = is_array($in['sites'] ?? null) ? array_values(array_unique(array_map('intval', $in['sites']))) : [];
        $neverExp = !empty($in['never_expire']) ? 1 : 0;
        // Camera permissions (structured, keyed by site). Sanitized below once we know
        // the final site list + role. Admins don't need it stored (they see all).
        $camIn = $in['cameraAccess'] ?? null;
        if ($uname === '') jsonResponse(['success' => false, 'error' => 'Username is required'], 400);
        if (!in_array($role, ['viewer','editor','admin'], true)) jsonResponse(['success' => false, 'error' => 'Invalid role'], 400);
        $isNew = ($publicId === '');
        // New users must set a password. Edits may leave it blank to keep the
        // existing one — but if a password IS provided on an edit, it must pass
        // the same policy rather than being silently accepted or ignored.
        if ($isNew || $pass !== '') {
            if (($pwErr = password_rejection_reason($pass)) !== null) {
                jsonResponse(['success' => false, 'error' => $pwErr], 400);
            }
        }
        // Nobody can create a second account using the protected username.
        if ($isNew && is_protected_username($uname)) {
            jsonResponse(['success' => false, 'error' => 'That username is reserved'], 403);
        }
        // Admins see all sites, so their site_access is stored empty.
        $siteJson = ($role === 'admin' || !$sites) ? null : json_encode($sites);
        // Camera permissions: admins ignore them; others are constrained to granted sites.
        $camClean = ($role === 'admin') ? [] : sanitize_camera_access($camIn, $sites);
        $camJson  = $camClean ? json_encode($camClean) : null;
        $hasCamCol = users_has_camera_access($pdo); // graceful if the snippet hasn't run

        try {
            if (!$isNew) {
                // resolve internal id + current state from public id
                $q = $pdo->prepare("SELECT user_id, username, display_name, role, is_active, never_expire FROM users WHERE public_id = ?");
                $q->execute([$publicId]);
                $existing = $q->fetch();
                if (!$existing) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
                $uid = (int)$existing['user_id'];
                // If this is the protected break-glass admin, lock its core fields:
                // force username/role/active to stay put; only display name + password
                // may change. Also block renaming any OTHER account to the reserved name.
                if (is_protected_username($existing['username'])) {
                    $uname  = $existing['username']; // can't rename away
                    $role   = 'admin';               // can't demote
                    $active = 1;                     // can't disable
                    $siteJson = null;
                    $camJson  = null;                // admin sees all; nothing to store
                } elseif (is_protected_username($uname)) {
                    jsonResponse(['success' => false, 'error' => 'That username is reserved'], 403);
                }
                // Build the column/value list, appending camera_access only if present.
                $camSet = $hasCamCol ? ", camera_access=?" : "";
                if ($pass !== '') {
                    $sql = "UPDATE users SET username=?, display_name=?, role=?, is_active=?, site_access=?, never_expire=?$camSet, password_hash=? WHERE user_id=?";
                    $args = [$uname, $display, $role, $active, $siteJson, $neverExp];
                    if ($hasCamCol) $args[] = $camJson;
                    $args[] = password_hash($pass, PASSWORD_BCRYPT);
                    $args[] = $uid;
                    $pdo->prepare($sql)->execute($args);
                } else {
                    $sql = "UPDATE users SET username=?, display_name=?, role=?, is_active=?, site_access=?, never_expire=?$camSet WHERE user_id=?";
                    $args = [$uname, $display, $role, $active, $siteJson, $neverExp];
                    if ($hasCamCol) $args[] = $camJson;
                    $args[] = $uid;
                    $pdo->prepare($sql)->execute($args);
                }
                // audit: record what actually changed
                $after = ['username' => $uname, 'display_name' => $display, 'role' => $role, 'is_active' => $active, 'never_expire' => $neverExp];
                $beforeCmp = ['username' => $existing['username'], 'display_name' => $existing['display_name'], 'role' => $existing['role'], 'is_active' => (int)$existing['is_active'], 'never_expire' => (int)$existing['never_expire']];
                $diff = [];
                foreach ($after as $k => $v) { if ((string)$v !== (string)($beforeCmp[$k] ?? '')) $diff[$k] = ['from' => $beforeCmp[$k] ?? null, 'to' => $v]; }
                if ($pass !== '') $diff['password'] = ['changed' => true];
                audit($pdo, 'user.update', ['target_type' => 'user', 'target_label' => $uname, 'details' => $diff]);
            } else {
                $newPub = generate_public_id();
                if ($hasCamCol) {
                    $pdo->prepare("INSERT INTO users (public_id, username, display_name, role, is_active, site_access, never_expire, camera_access, password_hash) VALUES (?,?,?,?,?,?,?,?,?)")
                        ->execute([$newPub, $uname, $display, $role, $active, $siteJson, $neverExp, $camJson, password_hash($pass, PASSWORD_BCRYPT)]);
                } else {
                    $pdo->prepare("INSERT INTO users (public_id, username, display_name, role, is_active, site_access, never_expire, password_hash) VALUES (?,?,?,?,?,?,?,?)")
                        ->execute([$newPub, $uname, $display, $role, $active, $siteJson, $neverExp, password_hash($pass, PASSWORD_BCRYPT)]);
                }
                $publicId = $newPub;
                audit($pdo, 'user.create', ['target_type' => 'user', 'target_label' => $uname, 'details' => ['role' => $role, 'is_active' => $active, 'never_expire' => $neverExp, 'sites' => $sites]]);
            }
            // Email lives in its own column (added by add_password_reset.sql). Write it
            // separately so the main insert/update stays simple and stays working even
            // if that migration hasn't run yet.
            if (users_has_email($pdo)) {
                try {
                    $pdo->prepare("UPDATE users SET email = ? WHERE public_id = ?")
                        ->execute([$email !== '' ? $email : null, $publicId]);
                } catch (\Throwable $e) { error_log('email save failed: ' . $e->getMessage()); }
            }
            jsonResponse(['success' => true, 'public_id' => $publicId]);
        } catch (\Throwable $e) {
            error_log('User save failed: ' . $e->getMessage());
            $msg = $e->getMessage();
            if (stripos($msg, 'Duplicate') !== false) jsonResponse(['success' => false, 'error' => 'That username is already taken'], 409);
            jsonResponse(['success' => false, 'error' => 'Could not save user'], 500);
        }
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $publicId = trim((string)($in['public_id'] ?? ''));
        if ($publicId === '') jsonResponse(['success' => false, 'error' => 'Missing user'], 400);
        if ($publicId === (string)$current_user['public_id']) jsonResponse(['success' => false, 'error' => "You can't delete your own account"], 400);
        if (is_protected_username(username_for_public_id($pdo, $publicId))) {
            jsonResponse(['success' => false, 'error' => 'This account is protected and cannot be deleted'], 403);
        }
        try {
            $delName = username_for_public_id($pdo, $publicId);
            $pdo->prepare("DELETE FROM users WHERE public_id = ?")->execute([$publicId]);
            audit($pdo, 'user.delete', ['target_type' => 'user', 'target_label' => $delName ?: $publicId]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('User delete failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not delete user'], 500);
        }
    }

    // Bulk operations. Body: { public_ids:[...], op:'activate'|'deactivate'|'delete' }.
    // Always skips the current admin's own account for safety.
    if ($action === 'bulk' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $ids = array_values(array_unique(array_filter(array_map(
            fn($x) => trim((string)$x), (array)($in['public_ids'] ?? [])
        ))));
        $op  = (string)($in['op'] ?? '');
        $self = (string)$current_user['public_id'];
        $ids = array_values(array_filter($ids, fn($id) => $id !== '' && $id !== $self)); // never act on self
        if (!$ids) jsonResponse(['success' => false, 'error' => 'No eligible users selected'], 400);
        if (!in_array($op, ['activate','deactivate','delete'], true)) jsonResponse(['success' => false, 'error' => 'Invalid operation'], 400);
        // Drop the protected break-glass admin from the set so it can't be
        // disabled or deleted in bulk, no matter what was submitted.
        try {
            $place0 = implode(',', array_fill(0, count($ids), '?'));
            $pq = $pdo->prepare("SELECT public_id FROM users WHERE public_id IN ($place0) AND LOWER(username) = LOWER(?)");
            $pq->execute(array_merge($ids, [PROTECTED_ADMIN_USERNAME]));
            $protected = $pq->fetchAll(PDO::FETCH_COLUMN);
            if ($protected) $ids = array_values(array_diff($ids, $protected));
        } catch (\Throwable $e) { /* if check fails, fall through; per-row safety still applies below */ }
        if (!$ids) jsonResponse(['success' => false, 'error' => 'No eligible users selected'], 400);
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            if ($op === 'delete') {
                $pdo->prepare("DELETE FROM users WHERE public_id IN ($place)")->execute($ids);
            } else {
                $val = $op === 'activate' ? 1 : 0;
                $pdo->prepare("UPDATE users SET is_active = ? WHERE public_id IN ($place)")
                    ->execute(array_merge([$val], $ids));
            }
            audit($pdo, 'user.bulk', ['target_type' => 'user', 'target_label' => count($ids) . ' user(s)', 'details' => ['op' => $op, 'count' => count($ids)]]);
            jsonResponse(['success' => true, 'affected' => count($ids)]);
        } catch (\Throwable $e) {
            error_log('User bulk failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Bulk action failed'], 500);
        }
    }

    // Admin: clear a user's MFA (when they've lost their device). Forces them to
    // re-enroll next time they choose to. Also wipes their backup codes.
    if ($action === 'reset_mfa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $publicId = trim((string)($in['public_id'] ?? ''));
        if ($publicId === '') jsonResponse(['success' => false, 'error' => 'Missing user'], 400);
        if (is_protected_username(username_for_public_id($pdo, $publicId))) {
            jsonResponse(['success' => false, 'error' => 'This account is protected; it manages its own security'], 403);
        }
        try {
            $q = $pdo->prepare("SELECT user_id FROM users WHERE public_id = ?");
            $q->execute([$publicId]);
            $uid = (int)$q->fetchColumn();
            if (!$uid) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            $pdo->prepare("UPDATE users SET mfa_enabled = 0, totp_secret = NULL WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("DELETE FROM user_backup_code WHERE user_id = ?")->execute([$uid]);
            audit($pdo, 'user.mfa_reset', ['target_type' => 'user', 'target_label' => username_for_public_id($pdo, $publicId) ?: $publicId]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('Admin reset_mfa failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not reset MFA'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown user action'], 400);
}

// ================================================================
//  PERMISSION ADMIN API — groups, grants, memberships, overrides
//  (requires manage_users at 'manage'; glass-break always allowed)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'perm') {
    if (!can($pdo, 'manage_users', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    $action = $_GET['action'] ?? '';

    // Valid layers/levels/scopes for server-side validation of any grant.
    $VALID_LAYERS = array_merge(PERM_DATA_LAYERS, PERM_ADMIN_CAPS);
    $VALID_LEVELS = ['view', 'edit', 'manage', 'admin'];
    $VALID_SCOPES = ['all', 'site', 'device'];
    $validateGrant = function ($g) use ($VALID_LAYERS, $VALID_LEVELS, $VALID_SCOPES) {
        $layer = (string)($g['layer'] ?? '');
        $level = (string)($g['level'] ?? '');
        $scope = (string)($g['scope_type'] ?? 'all');
        if (!in_array($layer, $VALID_LAYERS, true)) return null;
        if (!in_array($level, $VALID_LEVELS, true)) return null;
        if (!in_array($scope, $VALID_SCOPES, true)) return null;
        // Admin capabilities are always global and cap at 'manage'.
        if (in_array($layer, PERM_ADMIN_CAPS, true)) {
            $scope = 'all';
            if ($level === 'edit' || $level === 'admin') $level = 'manage';
        }
        // Per-camera (device) scope only applies to the cameras layer.
        if ($scope === 'device' && $layer !== 'cameras') return null;
        $scopeId = ($scope === 'all') ? null : (int)($g['scope_id'] ?? 0);
        if ($scope !== 'all' && !$scopeId) return null;
        return ['layer' => $layer, 'level' => $level, 'scope_type' => $scope, 'scope_id' => $scopeId];
    };

    // ---- Catalog: the layers/levels/scopes the UI offers ----
    if ($action === 'catalog') {
        jsonResponse(['success' => true,
            'data_layers'  => PERM_DATA_LAYERS,
            'admin_caps'   => PERM_ADMIN_CAPS,
            'levels'       => $VALID_LEVELS,
        ]);
    }

    // ---- List groups (with grant counts + member counts) ----
    if ($action === 'groups') {
        try {
            $groups = $pdo->query("SELECT group_id, name, description, is_system FROM perm_group ORDER BY is_system DESC, name ASC")->fetchAll();
            $gc = []; foreach ($pdo->query("SELECT group_id, COUNT(*) c FROM perm_group_grant GROUP BY group_id")->fetchAll() as $r) $gc[(int)$r['group_id']] = (int)$r['c'];
            $mc = []; foreach ($pdo->query("SELECT group_id, COUNT(*) c FROM perm_user_group GROUP BY group_id")->fetchAll() as $r) $mc[(int)$r['group_id']] = (int)$r['c'];
            foreach ($groups as &$g) {
                $g['group_id'] = (int)$g['group_id'];
                $g['is_system'] = (int)$g['is_system'];
                $g['grant_count'] = $gc[$g['group_id']] ?? 0;
                $g['member_count'] = $mc[$g['group_id']] ?? 0;
            }
            jsonResponse(['success' => true, 'groups' => $groups]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not load groups (run add_permissions.sql?)'], 500); }
    }

    // ---- One group's grants + members ----
    if ($action === 'group_detail') {
        $gid = (int)($_GET['group_id'] ?? 0);
        if (!$gid) jsonResponse(['success' => false, 'error' => 'Missing group'], 400);
        try {
            $grants = $pdo->prepare("SELECT grant_id, layer, level, scope_type, scope_id FROM perm_group_grant WHERE group_id = ? ORDER BY layer");
            $grants->execute([$gid]);
            $members = $pdo->prepare("SELECT u.public_id, u.username, u.display_name FROM perm_user_group ug JOIN users u ON u.user_id = ug.user_id WHERE ug.group_id = ? ORDER BY u.username");
            $members->execute([$gid]);
            jsonResponse(['success' => true, 'grants' => $grants->fetchAll(), 'members' => $members->fetchAll()]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not load group'], 500); }
    }

    // ---- Create / rename a group ----
    if ($action === 'group_save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $gid = (int)($in['group_id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        $desc = trim((string)($in['description'] ?? '')) ?: null;
        if ($name === '') jsonResponse(['success' => false, 'error' => 'Group name required'], 400);
        try {
            if ($gid) {
                $pdo->prepare("UPDATE perm_group SET name = ?, description = ? WHERE group_id = ?")->execute([substr($name,0,80), $desc, $gid]);
                audit($pdo, 'perm.group_update', ['target_type' => 'group', 'target_label' => $name]);
                jsonResponse(['success' => true, 'group_id' => $gid]);
            } else {
                $pdo->prepare("INSERT INTO perm_group (name, description, is_system) VALUES (?, ?, 0)")->execute([substr($name,0,80), $desc]);
                $newId = (int)$pdo->lastInsertId();
                audit($pdo, 'perm.group_create', ['target_type' => 'group', 'target_label' => $name]);
                jsonResponse(['success' => true, 'group_id' => $newId]);
            }
        } catch (\Throwable $e) {
            $msg = (stripos($e->getMessage(), 'Duplicate') !== false) ? 'A group with that name already exists' : 'Could not save group';
            jsonResponse(['success' => false, 'error' => $msg], 500);
        }
    }

    // ---- Delete a group (members lose its grants; cascade handles rows) ----
    if ($action === 'group_delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $gid = (int)($in['group_id'] ?? 0);
        if (!$gid) jsonResponse(['success' => false, 'error' => 'Missing group'], 400);
        try {
            $nm = $pdo->prepare("SELECT name FROM perm_group WHERE group_id = ?"); $nm->execute([$gid]); $name = $nm->fetchColumn();
            $pdo->prepare("DELETE FROM perm_group WHERE group_id = ?")->execute([$gid]);
            audit($pdo, 'perm.group_delete', ['target_type' => 'group', 'target_label' => (string)$name]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not delete group'], 500); }
    }

    // ---- Replace a group's grants wholesale. Body: { group_id, grants:[...] } ----
    if ($action === 'group_set_grants' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $gid = (int)($in['group_id'] ?? 0);
        if (!$gid) jsonResponse(['success' => false, 'error' => 'Missing group'], 400);
        $clean = [];
        foreach ((array)($in['grants'] ?? []) as $g) { $v = $validateGrant($g); if ($v) $clean[] = $v; }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM perm_group_grant WHERE group_id = ?")->execute([$gid]);
            $ins = $pdo->prepare("INSERT INTO perm_group_grant (group_id, layer, level, scope_type, scope_id) VALUES (?,?,?,?,?)");
            foreach ($clean as $g) $ins->execute([$gid, $g['layer'], $g['level'], $g['scope_type'], $g['scope_id']]);
            $pdo->commit();
            audit($pdo, 'perm.group_set_grants', ['target_type' => 'group', 'target_label' => 'group ' . $gid, 'details' => ['count' => count($clean)]]);
            jsonResponse(['success' => true, 'count' => count($clean)]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not save grants'], 500);
        }
    }

    // ---- Set a user's group memberships. Body: { public_id, group_ids:[...] } ----
    if ($action === 'user_set_groups' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $pubId = trim((string)($in['public_id'] ?? ''));
        if ($pubId === '') jsonResponse(['success' => false, 'error' => 'Missing user'], 400);
        $gids = array_values(array_unique(array_map('intval', (array)($in['group_ids'] ?? []))));
        try {
            $uq = $pdo->prepare("SELECT user_id, username FROM users WHERE public_id = ?"); $uq->execute([$pubId]); $u = $uq->fetch();
            if (!$u) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            // Never modify the glass-break account's memberships through the app.
            if (is_protected_username($u['username'])) jsonResponse(['success' => false, 'error' => 'This account is managed in code'], 403);
            $uid = (int)$u['user_id'];
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM perm_user_group WHERE user_id = ?")->execute([$uid]);
            if ($gids) {
                $ins = $pdo->prepare("INSERT INTO perm_user_group (user_id, group_id) VALUES (?, ?)");
                foreach ($gids as $g) { if ($g > 0) $ins->execute([$uid, $g]); }
            }
            $pdo->commit();
            audit($pdo, 'perm.user_set_groups', ['target_type' => 'user', 'target_label' => $u['username'], 'details' => ['groups' => $gids]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not update memberships'], 500);
        }
    }

    // ---- A user's personal override grants ----
    if ($action === 'user_grants') {
        $pubId = trim((string)($_GET['public_id'] ?? ''));
        if ($pubId === '') jsonResponse(['success' => false, 'error' => 'Missing user'], 400);
        try {
            $uq = $pdo->prepare("SELECT user_id FROM users WHERE public_id = ?"); $uq->execute([$pubId]); $uid = $uq->fetchColumn();
            if (!$uid) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            $gq = $pdo->prepare("SELECT grant_id, layer, level, scope_type, scope_id FROM perm_user_grant WHERE user_id = ? ORDER BY layer");
            $gq->execute([(int)$uid]);
            jsonResponse(['success' => true, 'grants' => $gq->fetchAll()]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not load grants'], 500); }
    }

    // ---- Replace a user's personal override grants. Body: { public_id, grants:[...] } ----
    if ($action === 'user_set_grants' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $pubId = trim((string)($in['public_id'] ?? ''));
        if ($pubId === '') jsonResponse(['success' => false, 'error' => 'Missing user'], 400);
        $clean = [];
        foreach ((array)($in['grants'] ?? []) as $g) { $v = $validateGrant($g); if ($v) $clean[] = $v; }
        try {
            $uq = $pdo->prepare("SELECT user_id, username FROM users WHERE public_id = ?"); $uq->execute([$pubId]); $u = $uq->fetch();
            if (!$u) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            if (is_protected_username($u['username'])) jsonResponse(['success' => false, 'error' => 'This account is managed in code'], 403);
            $uid = (int)$u['user_id'];
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM perm_user_grant WHERE user_id = ?")->execute([$uid]);
            $ins = $pdo->prepare("INSERT INTO perm_user_grant (user_id, layer, level, scope_type, scope_id) VALUES (?,?,?,?,?)");
            foreach ($clean as $g) $ins->execute([$uid, $g['layer'], $g['level'], $g['scope_type'], $g['scope_id']]);
            $pdo->commit();
            audit($pdo, 'perm.user_set_grants', ['target_type' => 'user', 'target_label' => $u['username'], 'details' => ['count' => count($clean)]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not save grants'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown perm action'], 400);
}

// ================================================================
// IMAGE UPLOAD  (logo → settings-manage; avatar → self or manage_users)
// ================================================================
// ----------------------------------------------------------------
// IMAGE SERVE. Every avatar/logo <img> in the app requests this, but the
// handler never existed — so no photo could ever render, whatever was on disk.
// Serving through PHP (rather than letting nginx hand out uploads/ directly)
// means images inherit the app's session gate: this file is reached only after
// inc/auth.php's signed-in check, so avatars are no longer world-readable to
// anyone who can reach the box. It also lets uploads/ move outside the web root
// later without touching a single <img> tag.
    // Set a site's colour (Settings -> Site colours). '' clears it back to the
    // app's auto-assigned palette colour. Gated on settings:manage like every
    // other district-wide appearance setting.
// ================================================================
// SITE SETTINGS (?api=site). NOTE the placement lesson: this block
// originally sat INSIDE the api=room section (anchored by comment, not by
// structure), so ?api=site&action=set_color matched NOTHING — the request
// fell through the whole API and the app page rendered back as a "200",
// which the client rightly refused to parse. Standalone handler now.
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'site' && ($_GET['action'] ?? '') === 'set_color' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'settings', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!db_has_columns($pdo, 'site', ['color'])) jsonResponse(['success' => false, 'error' => 'Site colour column missing (run add_site_color.sql)'], 400);
        $in  = jsonInput();
        $sn  = (int)($in['site_number'] ?? 0);
        $raw = trim((string)($in['color'] ?? ''));
        if (!$sn) jsonResponse(['success' => false, 'error' => 'Site required'], 400);
        if ($raw !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) jsonResponse(['success' => false, 'error' => 'Bad colour'], 400);
        try {
            $q = $pdo->prepare("SELECT site_name FROM site WHERE site_number = ?");
            $q->execute([$sn]);
            $row = $q->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Site not found'], 404);
            $pdo->prepare("UPDATE site SET color = ? WHERE site_number = ?")
                ->execute([$raw === '' ? null : strtolower($raw), $sn]);
            audit($pdo, 'site.set_color', ['target_type' => 'site', 'target_label' => (string)$row['site_name'], 'details' => ['color' => $raw]]);
            jsonResponse(['success' => true, 'color' => $raw]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not save colour'], 500);
        }
    }

if (isset($_GET['api']) && $_GET['api'] === 'image' && ($_GET['action'] ?? '') === 'serve') {
    $kind = $_GET['kind'] ?? '';
    $rel  = '';
    // Every refusal names its reason (header + body) so a broken image in the
    // Network tab is self-diagnosing — and carries the build fingerprint, so a
    // stale api.php on the server can never masquerade as the current one again.
    $deny = function (string $why) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        http_response_code(404);
        header('Content-Type: text/plain');
        if (defined('APP_VERSION')) { header('X-App-Version: ' . APP_VERSION); }
        header('X-Avatar-Reason: ' . $why);
        // Chrome replaces 404 bodies under 512 bytes with its own generic
        // "page can't be found" screen, hiding the diagnosis this response
        // exists to deliver. Pad past the threshold so pasting the URL into a
        // tab shows the reason directly, no DevTools needed.
        echo 'image 404: ' . $why . "\n"
           . 'build: ' . (defined('APP_VERSION') ? APP_VERSION : '?') . "\n"
           . str_repeat('-', 60) . "\n"
           . str_pad('(padding so the browser shows this text instead of its own generic 404 page)', 480, ' ');
        exit;
    };
    if ($kind === 'logo') {
        $rel = (string)setting_get($pdo, 'site_logo_path', '');
        if ($rel === '') { $deny('no_logo_configured'); }
    } elseif ($kind === 'avatar') {
        // An avatar request MUST name whose avatar it wants (by public id — the
        // internal id is never exposed to the client). This used to fall back to
        // "the caller's own photo" when the id was empty, so a row whose id
        // didn't resolve rendered the VIEWER'S face — every user in the list
        // appeared to be you. Third time this feature defaulted to the caller
        // instead of failing: an empty id is now simply not found.
        // Every refusal names its reason (header + body) so a broken thumbnail
        // in the Network tab is self-diagnosing instead of a mystery icon.
        $pub = trim((string)($_GET['id'] ?? ''));
        if ($pub === '') { $deny('no_id'); }
        try {
            $st = $pdo->prepare("SELECT user_id, profile_image FROM users WHERE public_id = ?");
            $st->execute([$pub]);
            $row = $st->fetch();
            $rel = $row ? (string)($row['profile_image'] ?: '') : '';
            // OWNERSHIP: user X's avatar may only be streamed from X's own
            // storage — uploads/avatars/<X>/ or the legacy flat pattern
            // uploads/avatar-<X>-*. Debugging surfaced rows whose profile_image
            // pointed into ANOTHER user's folder (bug-era leftovers), and the
            // old code faithfully streamed the wrong person's face for them.
            // A cross-owned path is data corruption: refuse it (404 → the row
            // shows initials, visibly needing a re-set) rather than render it.
            if (!$row) { $deny('user_not_found_for_id'); }
            if ($rel === '') { $deny('user_has_no_image_path'); }
            $uid = (int)$row['user_id'];
            // tolerate leading './' or '/' variants of the same stored path
            $relNorm = ltrim($rel, '/');
            if (strpos($relNorm, './') === 0) { $relNorm = substr($relNorm, 2); }
            $ok = (strpos($relNorm, 'uploads/avatars/' . $uid . '/') === 0)
               || (strpos($relNorm, 'uploads/avatar-' . $uid . '-') === 0);
            if (!$ok) { $deny('path_owned_by_other_user'); }
            $rel = $relNorm;
        } catch (\Throwable $e) { $deny('db_error'); }
    }
    if ($rel === '') { $deny('no_image_path'); }

    $path = APP_ROOT . '/' . ltrim($rel, '/');
    $real = realpath($path);
    $base = realpath(APP_ROOT . '/uploads');
    // Containment check: the stored path comes from the DB, and a path that
    // escapes uploads/ (../../etc/passwd) must never be streamed back.
    if ($real === false || !is_file($real)) {
        $deny('file_missing_on_disk');
    }
    if ($base === false || strncmp($real, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
        $deny('path_escapes_uploads');
    }
    $info = @getimagesize($real);
    $types = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_WEBP => 'image/webp', IMAGETYPE_GIF => 'image/gif'];
    if ($info === false || !isset($types[$info[2]])) {
        $deny('file_not_a_valid_image');
    }

    // ---- Byte-exactness is load-bearing here. This is the app's only
    // response that declares a precise Content-Length. If ANY stray output
    // precedes the file (a BOM or whitespace from an include, a PHP 8.5
    // deprecation notice sitting in the output buffer), the body becomes
    // junk+file while the header still promises filesize bytes — and on a
    // shared keep-alive connection that misalignment desyncs EVERY LATER
    // RESPONSE on it: the browser slices subsequent image replies at the
    // wrong offsets and repeats earlier bytes. Observed in the wild as every
    // avatar in Manage Users rendering as the first-loaded face (the
    // viewer's), with correct URLs, cache disabled, in every browser — while
    // a direct visit (fresh connection, single clean request) looked fine.
    while (ob_get_level() > 0) { @ob_end_clean(); }   // exact body, nothing else
    if (defined('APP_VERSION')) { header('X-App-Version: ' . APP_VERSION); }
    header('Content-Type: ' . $types[$info[2]]);
    header('Content-Length: ' . filesize($real));
    // private: a shared proxy must not cache one user's face for another's.
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline');
    // Belt over the buffer-purge braces: images decline connection reuse, so
    // even if some future stray byte sneaks in, it can corrupt at most its own
    // response — never the neighbouring ones. Trivial cost on a LAN app.
    header('Connection: close');
    readfile($real);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === 'image' && ($_GET['action'] ?? '') === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = $_POST['kind'] ?? '';
    if (!in_array($kind, ['logo', 'avatar'], true)) jsonResponse(['success' => false, 'error' => 'Bad kind'], 400);

    // Who's allowed?
    if ($kind === 'logo') {
        if (!can($pdo, 'settings', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    } else { // avatar
        // A missing user_id means "me". A user_id that's PRESENT but unusable is
        // an error — never a silent fall back to the caller. The old `?:` did
        // exactly that, so any client-side hiccup quietly redirected an admin's
        // upload onto the ADMIN'S OWN profile instead of the chosen user, with
        // no warning: the wrong person's photo changed.
        // Target by public_id: the users list deliberately strips the internal
        // user_id ("never expose internal id"), so that's the only handle the
        // client legitimately has. Leaking the internal id to make this work
        // would trade away that design for no reason.
        // No public_id sent = "me" (the self-serve flow). Sent but unresolvable
        // = a hard error; the old `?:` silently fell back to the CALLER, which
        // is why an admin's upload for someone else landed on their own profile.
        $targetId = (int)$current_user['user_id'];
        $pub = trim((string)($_POST['public_id'] ?? ''));
        if ($pub !== '') {
            $q = $pdo->prepare("SELECT user_id FROM users WHERE public_id = ?");
            $q->execute([$pub]);
            $found = $q->fetchColumn();
            if (!$found) jsonResponse(['success' => false, 'error' => 'User not found — refusing rather than defaulting to your own profile'], 404);
            $targetId = (int)$found;
        }
        $isSelf = ($targetId === (int)$current_user['user_id']);
        if (!$isSelf && !can($pdo, 'manage_users', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    }

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['success' => false, 'error' => 'No file uploaded'], 400);
    }
    $tmp = $_FILES['file']['tmp_name'];
    $size = (int)$_FILES['file']['size'];
    if ($size <= 0 || $size > 5 * 1024 * 1024) jsonResponse(['success' => false, 'error' => 'Image too large (max 5MB)'], 400);

    // Validate it's a real image by reading its signature, not trusting the name.
    $info = @getimagesize($tmp);
    $allowed = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if ($info === false || !isset($allowed[$info[2]])) {
        jsonResponse(['success' => false, 'error' => 'Please upload a PNG, JPG, WEBP, or GIF image'], 400);
    }
    $ext = $allowed[$info[2]];

    // ---- Dimension cap: a "decompression bomb" is small on disk and enormous
    // once decoded (a 40KB PNG can expand to hundreds of MB). getimagesize()
    // reports the DECODED size without decoding, so this is the cheap check
    // that must happen before anything touches the pixels.
    [$w, $h] = [(int)$info[0], (int)$info[1]];
    if ($w < 1 || $h < 1 || $w > 8000 || $h > 8000 || ($w * $h) > 40000000) {
        jsonResponse(['success' => false, 'error' => 'Image dimensions are too large (max 8000x8000)'], 400);
    }

    // ---- Re-encode rather than store what was uploaded. getimagesize() only
    // reads a header, so a polyglot (a genuine image with PHP/JS/HTML welded
    // into its metadata or trailing bytes) sails straight through it. Decoding
    // to a bitmap and re-encoding from scratch keeps ONLY the pixels: every
    // embedded payload, EXIF blob, and appended archive is discarded because
    // nothing but colour data survives the round trip.
    // Returns '' on success, else a reason code. It used to return a bare
    // false, so "GD isn't installed" and "couldn't write the file" produced the
    // same unhelpful message — different problems, different fixes.
    $rencode = function (string $tmp, int $type, string $destPath) {
        if (!function_exists('imagecreatefromstring')) return 'gd_missing';
        $raw = @file_get_contents($tmp);
        if ($raw === false) return 'read_failed';
        $img = @imagecreatefromstring($raw);
        unset($raw);
        if ($img === false) return 'decode_failed';
        // Flatten alpha correctly for formats that keep it.
        if (in_array($type, [IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }
        $ok = false; $why = 'write_failed';
        switch ($type) {
            case IMAGETYPE_PNG:  $ok = @imagepng($img, $destPath, 6); break;
            case IMAGETYPE_JPEG: $ok = @imagejpeg($img, $destPath, 88); break;
            case IMAGETYPE_WEBP:
                if (!function_exists('imagewebp')) { $why = 'webp_unsupported'; break; }
                $ok = @imagewebp($img, $destPath, 88); break;
            case IMAGETYPE_GIF:  $ok = @imagegif($img, $destPath); break;
        }
        imagedestroy($img);
        if (!$ok) return $why;
        if (!is_file($destPath) || filesize($destPath) < 1) return 'write_failed';
        return '';
    };
    // One place to turn a reason code into something actionable.
    $rencodeFail = function (string $why) {
        $msgs = [
            'gd_missing'       => 'The server\'s PHP image library (GD) is not installed, so images can\'t be sanitised. Install it on the server: sudo apt install php-gd  then restart PHP (sudo systemctl restart php8.3-fpm). Uploads are refused until then rather than storing files unchecked.',
            'read_failed'      => 'Could not read the uploaded file.',
            'decode_failed'    => 'That file could not be decoded as an image.',
            'webp_unsupported' => 'This server cannot write WEBP images — please upload a PNG or JPG instead.',
            'write_failed'     => 'Could not write the processed image — check that uploads/ is writable by the web server.',
        ];
        jsonResponse(['success' => false, 'error' => $msgs[$why] ?? 'Could not process that image', 'error_code' => $why], 500);
    };

    $dir = APP_ROOT . '/uploads';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) jsonResponse(['success' => false, 'error' => 'Uploads directory not writable'], 500);

    if ($kind === 'logo') {
        $fname = 'logo-' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $fname;
        $why = $rencode($tmp, $info[2], $dest);
        if ($why !== '') { @unlink($dest); $rencodeFail($why); }
        @chmod($dest, 0644);
        // Clean up any previous logo file.
        $prev = setting_get($pdo, 'site_logo_path', '');
        if ($prev) { $p = APP_ROOT . '/' . ltrim($prev, '/'); if (is_file($p)) @unlink($p); }
        setting_set($pdo, 'site_logo_path', 'uploads/' . $fname);
        audit($pdo, 'setting.logo', ['target_type' => 'setting', 'target_label' => 'site_logo_path']);
        jsonResponse(['success' => true, 'path' => 'uploads/' . $fname]);
    } else {
        // $targetId was resolved and vetted in the permission block above —
        // deriving it twice is how the two copies drift apart.
        // One folder per user, so uploads/ isn't a single heap of everyone's images.
        $userDir = $dir . '/avatars/' . $targetId;
        if (!is_dir($userDir) && !@mkdir($userDir, 0775, true)) jsonResponse(['success' => false, 'error' => 'Uploads directory not writable'], 500);
        // Random name, not avatar-<id>-<time>: the old pattern was guessable, so
        // anyone could enumerate staff photos in a folder the web server hands
        // out without asking for a login. Random names don't make the folder
        // private, but they stop it being trivially walkable.
        $fname = bin2hex(random_bytes(16)) . '.' . $ext;
        $rel   = 'uploads/avatars/' . $targetId . '/' . $fname;
        $dest  = $userDir . '/' . $fname;
        // Refuse rather than silently storing unsanitised bytes — a quiet
        // fallback here would defeat the whole point of re-encoding.
        $why = $rencode($tmp, $info[2], $dest);
        if ($why !== '') { @unlink($dest); $rencodeFail($why); }
        @chmod($dest, 0644);
        try {
            // Remove old avatar file if present.
            $st = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
            $st->execute([$targetId]);
            $prev = (string)($st->fetchColumn() ?: '');
            if ($prev) { $p = APP_ROOT . '/' . ltrim($prev, '/'); if (is_file($p)) @unlink($p); }
            $up = $pdo->prepare("UPDATE users SET profile_image = ? WHERE user_id = ?");
            $up->execute([$rel, $targetId]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Saved file but DB update failed (run add_images.sql)'], 500);
        }
        // Record WHO changed WHOSE picture — an admin editing someone else's
        // profile is exactly the event worth being able to look up later.
        audit($pdo, 'user.avatar', ['target_type' => 'user', 'target_id' => $targetId,
            'details' => ['by_admin' => ($targetId !== (int)$current_user['user_id'])]]);
        // Echo the user actually modified so the client updates that row rather
        // than assuming its request was honoured.
        jsonResponse(['success' => true, 'path' => $rel, 'public_id' => $pub !== '' ? $pub : (string)($current_user['public_id'] ?? '')]);
    }
}

if (isset($_GET['api']) && $_GET['api'] === 'image' && ($_GET['action'] ?? '') === 'remove' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $kind = $_POST['kind'] ?? '';
    if ($kind === 'logo') {
        if (!can($pdo, 'settings', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $prev = setting_get($pdo, 'site_logo_path', '');
        if ($prev) { $p = APP_ROOT . '/' . ltrim($prev, '/'); if (is_file($p)) @unlink($p); }
        setting_set($pdo, 'site_logo_path', '');
        jsonResponse(['success' => true]);
    } elseif ($kind === 'avatar') {
        // Same rule as upload: resolve by public_id, never silently self-target.
        $targetId = (int)$current_user['user_id'];
        $pub = trim((string)($_POST['public_id'] ?? ''));
        if ($pub !== '') {
            $q = $pdo->prepare("SELECT user_id FROM users WHERE public_id = ?");
            $q->execute([$pub]);
            $found = $q->fetchColumn();
            if (!$found) jsonResponse(['success' => false, 'error' => 'User not found'], 404);
            $targetId = (int)$found;
        }
        $isSelf = ($targetId === (int)$current_user['user_id']);
        if (!$isSelf && !can($pdo, 'manage_users', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        try {
            $st = $pdo->prepare("SELECT profile_image FROM users WHERE user_id = ?");
            $st->execute([$targetId]);
            $prev = (string)($st->fetchColumn() ?: '');
            if ($prev) { $p = APP_ROOT . '/' . ltrim($prev, '/'); if (is_file($p)) @unlink($p); }
            $pdo->prepare("UPDATE users SET profile_image = NULL WHERE user_id = ?")->execute([$targetId]);
        } catch (\Throwable $e) {}
        jsonResponse(['success' => true]);
    }
    jsonResponse(['success' => false, 'error' => 'Bad kind'], 400);
}

// ================================================================
// SETTINGS API  (admin only)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'setting') {
    if (!can($pdo, 'settings', 'view')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        $s = load_settings($pdo);
        // Never expose the stored SMTP password to the browser — send a mask if set.
        $s['smtp_pass'] = (isset($s['smtp_pass']) && $s['smtp_pass'] !== '') ? SMTP_PASS_MASK : '';
        jsonResponse(['success' => true, 'settings' => $s]);
    }

    // Save one or more settings. Body: { settings: { key: value, ... } }.
    // Only known keys are accepted, each validated to a sane range.
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $incoming = is_array($in['settings'] ?? null) ? $in['settings'] : [];
        // Permission to SAVE: normally settings 'manage'. Exception: someone with
        // audit 'manage' may change ONLY the audit retention setting even without
        // settings-manage (the retention control lives here but is an audit concern).
        $incomingKeys = array_keys($incoming);
        $onlyRetention = ($incomingKeys === ['audit_retention_days']);
        $maySave = can($pdo, 'settings', 'manage')
                   || ($onlyRetention && can($pdo, 'audit', 'manage'));
        if (!$maySave) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        // key => [min, max] integer bounds
        $allowed = [
            'session_timeout_minutes' => [5, 43200],   // 5 min .. 30 days
            'session_warn_minutes'    => [1, 120],
            'audit_retention_days'    => [0, 3650],     // 0 = keep forever
            'login_max_attempts'      => [0, 50],       // 0 = lockouts disabled
            'login_lockout_minutes'   => [1, 1440],     // 1 min .. 24 h
            'smtp_port'               => [1, 65535],
            'email_cap_hourly'        => [0, 100000],   // 0 = unlimited
            'email_cap_daily'         => [0, 100000],
            'invite_expiry_days'      => [1, 90],
        ];
        // free-text string settings (trimmed, length-capped)
        $stringKeys = ['smtp_host' => 255, 'smtp_user' => 255, 'smtp_from_email' => 255, 'smtp_from_name' => 120, 'site_brand_name' => 60];
        $before = load_settings($pdo);
        $changed = [];
        try {
            $up = $pdo->prepare("INSERT INTO settings (setting_key, setting_val) VALUES (?, ?)
                                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)");
            // Per-room-type default colors: a JSON object of type => #hex. Parsed
            // and rebuilt rather than stored verbatim, so only known types and
            // well-formed colors can ever enter the settings table.
            if (array_key_exists('room_type_colors', $incoming)) {
                $raw = is_array($incoming['room_type_colors'])
                     ? $incoming['room_type_colors']
                     : (json_decode((string)$incoming['room_type_colors'], true) ?: []);
                $types = ['general','classroom','office','lab','library','breakroom','storage','restroom','utility','hallway','conference','cafeteria','gym','auditorium'];
                $cleanColors = [];
                foreach ($types as $t) {
                    $v = trim((string)($raw[$t] ?? ''));
                    if (preg_match('/^#[0-9a-fA-F]{6}$/', $v)) $cleanColors[$t] = strtolower($v);
                }
                $up->execute(['room_type_colors', json_encode($cleanColors)]);
                $changed[] = 'room_type_colors';
            }
            // Boolean flags handled separately from the int bounds.
            foreach (['login_lockout_manual', 'smtp_enabled', 'layer_cameras_enabled', 'layer_printers_enabled', 'room_inherit_building'] as $boolKey) {
                if (array_key_exists($boolKey, $incoming)) {
                    $bv = !empty($incoming[$boolKey]) && $incoming[$boolKey] !== '0' ? '1' : '0';
                    $up->execute([$boolKey, $bv]);
                    if ((string)($before[$boolKey] ?? '0') !== $bv) $changed[$boolKey] = ['from' => $before[$boolKey] ?? '0', 'to' => $bv];
                }
            }
            // Enum: SMTP security mode.
            if (array_key_exists('smtp_security', $incoming)) {
                $sv = in_array($incoming['smtp_security'], ['none', 'tls', 'ssl'], true) ? $incoming['smtp_security'] : 'tls';
                $up->execute(['smtp_security', $sv]);
                if ((string)($before['smtp_security'] ?? '') !== $sv) $changed['smtp_security'] = ['from' => $before['smtp_security'] ?? null, 'to' => $sv];
            }
            // String settings.
            foreach ($stringKeys as $sk => $maxLen) {
                if (!array_key_exists($sk, $incoming)) continue;
                $val = trim((string)$incoming[$sk]);
                if (strlen($val) > $maxLen) $val = substr($val, 0, $maxLen);
                if ($sk === 'smtp_from_email' && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    jsonResponse(['success' => false, 'error' => 'From address is not a valid email'], 400);
                }
                $up->execute([$sk, $val]);
                if ((string)($before[$sk] ?? '') !== $val) $changed[$sk] = ['from' => $before[$sk] ?? null, 'to' => ($sk === 'smtp_user' ? '(changed)' : $val)];
            }
            // SMTP password: only overwrite when a real new value is sent (ignore the mask).
            if (array_key_exists('smtp_pass', $incoming)) {
                $pw = (string)$incoming['smtp_pass'];
                if ($pw !== '' && $pw !== SMTP_PASS_MASK) {
                    $up->execute(['smtp_pass', $pw]);
                    $changed['smtp_pass'] = ['from' => '(hidden)', 'to' => '(changed)'];
                }
            }
            foreach ($incoming as $k => $v) {
                if (!isset($allowed[$k])) continue;
                $iv = (int)$v;
                [$min, $max] = $allowed[$k];
                if ($iv < $min) $iv = $min;
                if ($iv > $max) $iv = $max;
                // warn time must be less than the timeout to make sense
                if ($k === 'session_warn_minutes') {
                    $to = (int)($incoming['session_timeout_minutes'] ?? $before['session_timeout_minutes'] ?? 480);
                    if ($iv >= $to) $iv = max(1, (int)floor($to / 2));
                }
                $up->execute([$k, (string)$iv]);
                if ((string)($before[$k] ?? '') !== (string)$iv) $changed[$k] = ['from' => $before[$k] ?? null, 'to' => (string)$iv];
            }
            $GLOBALS['__settings_cache'] = null; // bust cache
            if ($changed) audit($pdo, 'settings.update', ['target_type' => 'setting', 'target_label' => 'system settings', 'details' => $changed]);
            $out = load_settings($pdo);
            $out['smtp_pass'] = ($out['smtp_pass'] ?? '') !== '' ? SMTP_PASS_MASK : '';
            jsonResponse(['success' => true, 'settings' => $out]);
        } catch (\Throwable $e) {
            error_log('Settings save failed: ' . $e->getMessage());
            // Report the REAL failure. The old "(run migration.sql?)" guess sent
            // the admin chasing a migration when the actual problem (column too
            // small — error 1406) was named right here in the exception. On an
            // internal admin tool, the true DB message is worth more than a hunch.
            $msg = $e->getMessage();
            if (strpos($msg, '1406') !== false || stripos($msg, 'too long') !== false) {
                $msg = "A settings value is too long for the database column — run alter_settings_val.sql (widens setting_val to TEXT), then save again.";
            }
            jsonResponse(['success' => false, 'error' => 'Could not save settings: ' . $msg], 500);
        }
    }

    // Send a test email using the currently saved SMTP settings. Body: { to }.
    if ($action === 'test_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'settings', 'manage')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $to = trim((string)($in['to'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'error' => 'Enter a valid email address to test'], 400);
        $html = '<div style="font-family:sans-serif;font-size:15px;color:#111">'
              . '<h2 style="margin:0 0 10px">✅ Test email from Site Manager</h2>'
              . '<p>If you are reading this, your SMTP settings are working.</p>'
              . '<p style="color:#666;font-size:13px">Sent ' . htmlspecialchars(date('r')) . ' by ' . htmlspecialchars($current_user['display_name'] ?? $current_user['username'] ?? 'an admin') . '.</p>'
              . '</div>';
        $res = send_mail($pdo, $to, 'Site Manager — test email', $html, null, 'test');
        audit($pdo, 'email.test', ['target_type' => 'setting', 'target_label' => $to, 'details' => ['ok' => $res['success'], 'error' => $res['error'] ?? '']]);
        // Always 200 — a failed send is an expected result, not a server error.
        // The real reason rides in the JSON so the UI can show it.
        jsonResponse(['success' => (bool)$res['success'], 'error' => $res['success'] ? '' : ($res['error'] ?: 'Send failed')]);
    }

    jsonResponse(['error' => 'Unknown setting action'], 400);
}

// ================================================================
// AUDIT LOG API  (admin only)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'audit') {
    if (!can($pdo, 'audit', 'view')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        $q       = trim((string)($_GET['q'] ?? ''));
        $actFil  = trim((string)($_GET['action_filter'] ?? ''));
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 50;
        $offset  = ($page - 1) * $perPage;
        try {
            $where = []; $args = [];
            if ($q !== '') {
                $where[] = "(actor_name LIKE ? OR target_label LIKE ? OR ip_address LIKE ? OR action LIKE ?)";
                $like = '%' . $q . '%';
                array_push($args, $like, $like, $like, $like);
            }
            if ($actFil !== '') {
                if (strpos($actFil, '.') !== false) {
                    // a specific event type, e.g. "user.create" → exact match
                    $where[] = "action = ?";
                    $args[] = $actFil;
                } else {
                    // a bare group, e.g. "user" → matches user.create/update/delete and "user"
                    $where[] = "(action = ? OR action LIKE ?)";
                    $args[] = $actFil;
                    $args[] = $actFil . '.%';
                }
            }
            $wsql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_log $wsql");
            $cnt->execute($args);
            $total = (int)$cnt->fetchColumn();

            $sql = "SELECT audit_id, created_at, actor_name, action, target_type, target_label, details, ip_address, user_agent
                    FROM audit_log $wsql ORDER BY audit_id DESC LIMIT $perPage OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['audit_id'] = (int)$r['audit_id'];
                if (isset($r['details']) && $r['details'] !== null && !is_array($r['details'])) {
                    $dec = json_decode((string)$r['details'], true);
                    $r['details'] = $dec === null ? null : $dec;
                }
            }
            jsonResponse([
                'success' => true, 'events' => $rows,
                'page' => $page, 'per_page' => $perPage, 'total' => $total,
                'pages' => max(1, (int)ceil($total / $perPage)),
            ]);
        } catch (\Throwable $e) {
            error_log('Audit list failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not load audit log (run migration.sql?)'], 500);
        }
    }

    // Distinct action types present, for the filter dropdown (full event types).
    if ($action === 'kinds') {
        try {
            $rows = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);
            jsonResponse(['success' => true, 'kinds' => array_values($rows)]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => true, 'kinds' => []]);
        }
    }

    jsonResponse(['error' => 'Unknown audit action'], 400);
}

// ================================================================
// MAP API  (serve SVG floor plan for a site)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'map') {
    $action = $_GET['action'] ?? '';

    if ($action === 'svg') {
        $siteNum = filter_input(INPUT_GET, 'site', FILTER_VALIDATE_INT);
        if (!$siteNum) { http_response_code(400); exit('Missing site number'); }
        $mapKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['map'] ?? ''));
        try {
            // site_map is the single source of truth for floor plans (the
            // legacy site.svg_path column is retired from reads; run
            // migrate_svg_to_site_map.sql to backfill old sites). A specific
            // key serves that map; no key serves the site's default map
            // (is_default when available, else first by sort order).
            $path = null;
            try {
                if ($mapKey !== '') {
                    $ms = $pdo->prepare("SELECT svg_path FROM site_map WHERE site_number = ? AND map_key = ? AND svg_path IS NOT NULL AND svg_path != ''");
                    $ms->execute([$siteNum, $mapKey]);
                } else {
                    $ord = db_has_columns($pdo, 'site_map', ['is_default']) ? 'is_default DESC, sort_order ASC' : 'sort_order ASC';
                    $ms = $pdo->prepare("SELECT svg_path FROM site_map WHERE site_number = ? AND svg_path IS NOT NULL AND svg_path != '' ORDER BY $ord LIMIT 1");
                    $ms->execute([$siteNum]);
                }
                $mr = $ms->fetch();
                if ($mr && !empty($mr['svg_path']) && file_exists($mr['svg_path'])) $path = $mr['svg_path'];
            } catch (\Throwable $e) { /* site_map missing — treated as no map */ }
            if ($path === null) { http_response_code(404); exit('Map not found'); }
            $mtime = filemtime($path) ?: 0;
            $etag  = '"svg-' . $siteNum . '-' . $mapKey . '-' . $mtime . '-' . filesize($path) . '"';
            header('Content-Type: image/svg+xml');
            // Long cache; the ETag changes when the file does, so a re-upload busts it.
            header('Cache-Control: public, max-age=604800');
            header('ETag: ' . $etag);
            // 304 if the browser already has this exact version — zero bytes transferred.
            if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
            // These floor plans are multi-MB XML; gzip cuts them ~85-90%.
            $accept = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
            if (strpos($accept, 'gzip') !== false && function_exists('gzencode')) {
                $body = gzencode((string)file_get_contents($path), 6);
                header('Content-Encoding: gzip');
                header('Vary: Accept-Encoding');
                header('Content-Length: ' . strlen($body));
                echo $body;
            } else {
                header('Content-Length: ' . filesize($path));
                readfile($path);
            }
        } catch (\Throwable $e) { http_response_code(500); exit('Error'); }
        exit;
    }

    // Upload an SVG map for a site (admin only). Stores under a maps dir and sets
    // site.svg_path. multipart/form-data: file=<svg>, site=<n>.
    if ($action === 'upload_svg' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $siteNum = filter_input(INPUT_POST, 'site', FILTER_VALIDATE_INT);
        if (!$siteNum) jsonResponse(['success' => false, 'error' => 'Missing site'], 400);
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['success' => false, 'error' => 'No file uploaded'], 400);
        }
        $tmp = $_FILES['file']['tmp_name'];
        $size = (int)$_FILES['file']['size'];
        if ($size <= 0 || $size > 30 * 1024 * 1024) {
            jsonResponse(['success' => false, 'error' => 'File too large (max 30MB)'], 400);
        }
        // Validate it actually looks like SVG, not arbitrary upload.
        $head = file_get_contents($tmp, false, null, 0, 4096);
        if ($head === false || stripos($head, '<svg') === false) {
            jsonResponse(['success' => false, 'error' => 'Not a valid SVG file'], 400);
        }
        // SECURITY: SVG can carry scripts. This markup is injected into other
        // users' pages (x-html), so a malicious file would be stored XSS — an
        // editor could attack an admin's session. Strip the executable surface:
        // <script>, <foreignObject>, on*= handlers, and javascript:/data:text hrefs.
        $svgBody = (string)file_get_contents($tmp);
        $svgBody = preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $svgBody);
        $svgBody = preg_replace('#<script\b[^>]*/?\s*>#i', '', $svgBody);
        $svgBody = preg_replace('#<foreignObject\b[^>]*>.*?</foreignObject\s*>#is', '', $svgBody);
        $svgBody = preg_replace('#\son[a-z]+\s*=\s*"[^"]*"#i', '', $svgBody);
        $svgBody = preg_replace("#\son[a-z]+\s*=\s*'[^']*'#i", '', $svgBody);
        $svgBody = preg_replace('#\s(?:xlink:)?href\s*=\s*(["\'])\s*(?:javascript|data:text)[^"\']*\1#i', '', $svgBody);
        // PERF: shrink the file so it parses/renders faster everywhere. These are
        // lossless — they remove editor cruft, not drawing data:
        $svgBody = preg_replace('#<\?xml[^>]*\?>#i', '', $svgBody);                 // XML prolog
        $svgBody = preg_replace('#<!DOCTYPE[^>]*>#is', '', $svgBody);              // DOCTYPE
        $svgBody = preg_replace('#<!--.*?-->#s', '', $svgBody);                    // comments
        $svgBody = preg_replace('#<metadata\b[^>]*>.*?</metadata\s*>#is', '', $svgBody); // metadata blocks
        // Drop editor-namespace junk (Inkscape/Sodipodi/Adobe) attributes.
        $svgBody = preg_replace('#\s(?:inkscape|sodipodi|adobe|illustrator):[a-z0-9_\-]+\s*=\s*"[^"]*"#i', '', $svgBody);
        $svgBody = preg_replace("#\s(?:inkscape|sodipodi|adobe|illustrator):[a-z0-9_\-]+\s*=\s*'[^']*'#i", '', $svgBody);
        // Collapse runs of whitespace between tags.
        $svgBody = preg_replace('#>\s{2,}<#', '> <', $svgBody);
        $svgBody = trim($svgBody);
        // Stamp it so the browser can trust it's already clean and skip the
        // (expensive) runtime sanitize pass. The marker is an HTML comment right
        // before the opening <svg>.
        $svgBody = "<!--SM-SANITIZED-->\n" . $svgBody;
        // Which map within the site? (multi-map sites pass a map key.)
        $mapKey = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_POST['map'] ?? ''));
        // MAPS_DIR can be defined near config; default to a maps/ folder beside this script.
        $baseDir = defined('MAPS_DIR') ? MAPS_DIR : (APP_ROOT . '/maps');
        if (!is_dir($baseDir) && !@mkdir($baseDir, 0775, true)) {
            jsonResponse(['success' => false, 'error' => 'Maps directory not writable'], 500);
        }
        // Per-map filename so different suites/floors don't overwrite each other.
        $suffix = ($mapKey !== '' && $mapKey !== 'level-1') ? ('-' . $mapKey) : '';
        $dest = rtrim($baseDir, '/') . '/site-' . $siteNum . $suffix . '.svg';
        if (@file_put_contents($dest, $svgBody) === false) {
            jsonResponse(['success' => false, 'error' => 'Could not store file'], 500);
        }
        try {
            // If site_map exists and we have a key, write the map's SVG there.
            $wroteMap = false;
            // site_map is the single source of truth: update the row for this
            // key (default 'level-1' when none given), creating it if the site
            // doesn't have one yet. The legacy site.svg_path mirror is retired.
            $key = ($mapKey !== '') ? $mapKey : 'level-1';
            $um = $pdo->prepare("UPDATE site_map SET svg_path = ? WHERE site_number = ? AND map_key = ?");
            $um->execute([$dest, $siteNum, $key]);
            if ($um->rowCount() === 0) {
                $pdo->prepare("INSERT INTO site_map (site_number, map_key, name, svg_path, sort_order) VALUES (?,?,?,?,0)")
                    ->execute([$siteNum, $key, ($key === 'level-1' ? 'Level 1' : ucfirst(str_replace('-', ' ', $key))), $dest]);
                if ($key === 'level-1' && db_has_columns($pdo, 'site_map', ['is_default'])) {
                    try { $pdo->prepare("UPDATE site_map SET is_default = 1 WHERE site_number = ? AND map_key = 'level-1'")->execute([$siteNum]); } catch (\Throwable $e) {}
                }
            }
        } catch (\Throwable $e) {
            error_log('SVG path update failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Saved file but failed to update site'], 500);
        }
        jsonResponse(['success' => true, 'svg_path' => $dest]);
    }

    // Save the building rotation angle for a site (admin only). degrees, -180..180.
    if ($action === 'set_angle' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $siteNum = (int)($in['site_number'] ?? 0);
        $angle = isset($in['angle']) ? (float)$in['angle'] : null;
        if ($siteNum <= 0 || $angle === null) {
            jsonResponse(['success' => false, 'error' => 'site_number and angle required'], 400);
        }
        // normalize to (-180, 180]
        while ($angle >  180) $angle -= 360;
        while ($angle <= -180) $angle += 360;
        try {
            $stmt = $pdo->prepare("UPDATE site SET building_angle = ? WHERE site_number = ?");
            $stmt->execute([$angle, $siteNum]);
        } catch (\Throwable $e) {
            error_log('set_angle failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Save failed (did you run the migration?)'], 500);
        }
        jsonResponse(['success' => true, 'angle' => $angle]);
    }

    // Save a site's geographic placement for the OpenStreetMap view: center
    // lat/lng plus how many real-world meters the floor-plan overlay spans.
    // Any field may be null (e.g. clearing a placement). Needs add_site_geo.sql.
    if ($action === 'set_geo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $siteNum = (int)($in['site_number'] ?? 0);
        if ($siteNum <= 0) jsonResponse(['success' => false, 'error' => 'site_number required'], 400);
        $lat    = array_key_exists('lat', $in)    && $in['lat']    !== null && $in['lat']    !== '' ? (float)$in['lat']    : null;
        $lng    = array_key_exists('lng', $in)    && $in['lng']    !== null && $in['lng']    !== '' ? (float)$in['lng']    : null;
        $meters = array_key_exists('meters', $in) && $in['meters'] !== null && $in['meters'] !== '' ? (float)$in['meters'] : null;
        if ($lat !== null && ($lat < -90 || $lat > 90))    jsonResponse(['success' => false, 'error' => 'Latitude out of range'], 400);
        if ($lng !== null && ($lng < -180 || $lng > 180))  jsonResponse(['success' => false, 'error' => 'Longitude out of range'], 400);
        if ($meters !== null) $meters = max(5.0, min(5000.0, $meters));
        try {
            $pdo->prepare("UPDATE site SET site_lat = ?, site_lng = ?, site_map_meters = ? WHERE site_number = ?")
                ->execute([$lat, $lng, $meters, $siteNum]);
            audit($pdo, 'site.set_geo', ['target_type' => 'site', 'target_label' => 'site ' . $siteNum, 'details' => ['lat' => $lat, 'lng' => $lng, 'meters' => $meters]]);
        } catch (\Throwable $e) {
            error_log('set_geo failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Save failed (did you run add_site_geo.sql?)'], 500);
        }
        jsonResponse(['success' => true, 'lat' => $lat, 'lng' => $lng, 'meters' => $meters]);
    }

    // Save (or clear) a map's "start view": the focal point + zoom the map
    // opens at. x/y are 0-100 percentages on the floor plan; all three null =
    // clear. Needs add_map_focus.sql for the focus columns.
    if ($action === 'set_focus' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!db_has_columns($pdo, 'site_map', ['focus_x','focus_y'])) {
            jsonResponse(['success' => false, 'error' => 'Start-view columns missing (run add_map_focus.sql)'], 500);
        }
        $in = jsonInput();
        $siteNum = (int)($in['site_number'] ?? 0);
        $mapKey  = (string)($in['map_key'] ?? '');
        if ($siteNum <= 0 || $mapKey === '') jsonResponse(['success' => false, 'error' => 'site_number and map_key required'], 400);
        $x = array_key_exists('x', $in) && $in['x'] !== null && $in['x'] !== '' ? (float)$in['x'] : null;
        $y = array_key_exists('y', $in) && $in['y'] !== null && $in['y'] !== '' ? (float)$in['y'] : null;
        $z = array_key_exists('zoom', $in) && $in['zoom'] !== null && $in['zoom'] !== '' ? (float)$in['zoom'] : null;
        if ($x !== null && ($x < 0 || $x > 100)) jsonResponse(['success' => false, 'error' => 'x out of range'], 400);
        if ($y !== null && ($y < 0 || $y > 100)) jsonResponse(['success' => false, 'error' => 'y out of range'], 400);
        if ($z !== null) $z = max(0.1, min(20.0, $z));
        $hasZoomCol = db_has_columns($pdo, 'site_map', ['default_zoom']);
        try {
            if ($hasZoomCol) {
                $st = $pdo->prepare("UPDATE site_map SET focus_x = ?, focus_y = ?, default_zoom = ? WHERE site_number = ? AND map_key = ?");
                $st->execute([$x, $y, $z, $siteNum, $mapKey]);
            } else {
                $st = $pdo->prepare("UPDATE site_map SET focus_x = ?, focus_y = ? WHERE site_number = ? AND map_key = ?");
                $st->execute([$x, $y, $siteNum, $mapKey]);
            }
            if ($st->rowCount() === 0 && !db_has_table($pdo, 'site_map')) { /* unreachable guard */ }
            audit($pdo, 'map.set_focus', ['target_type' => 'site', 'target_label' => 'site ' . $siteNum . ' / ' . $mapKey,
                'details' => ['x' => $x, 'y' => $y, 'zoom' => $z]]);
        } catch (\Throwable $e) {
            error_log('set_focus failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Save failed — check the server log'], 500);
        }
        jsonResponse(['success' => true, 'x' => $x, 'y' => $y, 'zoom' => $z]);
    }

    jsonResponse(['error' => 'Unknown map action'], 400);
}
if (isset($_GET['api']) && $_GET['api'] === 'room') {
    $action = $_GET['action'] ?? '';

    // List rooms for a site
    if ($action === 'list') {
        $siteNum = filter_input(INPUT_GET, 'site', FILTER_VALIDATE_INT);
        if (!$siteNum) jsonResponse(['error' => 'Missing site'], 400);
        try {
            $stmt = $pdo->prepare("SELECT * FROM room WHERE site_number = ? AND is_active = 1 ORDER BY room_name ASC");
            $stmt->execute([$siteNum]);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['polygon_points'] = $r['polygon_points'] ? json_decode($r['polygon_points'], true) : [];
                if (array_key_exists('room_shape', $r)) {
                    $r['room_shape'] = $r['room_shape'] ? json_decode($r['room_shape'], true) : null;
                }
                // PDO can return TINYINT as the string "0", which is truthy in JS.
                $r['show_primary_contact'] = array_key_exists('show_primary_contact', $r)
                    ? (int)$r['show_primary_contact'] : 0;
            }
            jsonResponse(['success' => true, 'rooms' => $rows]);
        } catch (\Throwable $e) {
            error_log('Room list failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to load rooms'], 500);
        }
    }

    // Create or update a room  (admin only)
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $siteNum = (int)($in['site_number'] ?? 0);
        $roomId  = isset($in['room_id']) ? (int)$in['room_id'] : 0;
        $wasUpdate = ($roomId > 0);
        $name    = trim((string)($in['room_name'] ?? ''));
        if ($siteNum <= 0 || $name === '') jsonResponse(['success' => false, 'error' => 'Site and room name are required'], 400);
        if (!can_access_site($pdo, $siteNum)) jsonResponse(['success' => false, 'error' => 'You do not have access to this site'], 403);

        $polyJson = null;
        if (isset($in['polygon_points']) && is_array($in['polygon_points'])) {
            $clean = [];
            foreach ($in['polygon_points'] as $pt) {
                if (!isset($pt['x'], $pt['y'])) continue;
                $clean[] = [
                    'x' => max(0.0, min(100.0, (float)$pt['x'])),
                    'y' => max(0.0, min(100.0, (float)$pt['y'])),
                ];
            }
            if (count($clean) >= 3) $polyJson = json_encode($clean);
        }

        $level = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($in['map_level'] ?? 'level-1')) ?: 'level-1';
        $color = isset($in['color']) ? preg_replace('/[^#0-9A-Fa-f]/', '', (string)$in['color']) : null;
        if ($color === '') $color = null;
        $labelX = isset($in['label_x']) && $in['label_x'] !== null ? max(0.0, min(100.0, (float)$in['label_x'])) : null;
        $labelY = isset($in['label_y']) && $in['label_y'] !== null ? max(0.0, min(100.0, (float)$in['label_y'])) : null;
        $roomExt   = trim((string)($in['room_extension'] ?? '')) ?: null;
        $roomNotes = trim((string)($in['room_notes'] ?? '')) ?: null;

        // Base columns that have always existed.
        $params = [
            'site_number' => $siteNum,
            'room_name'   => $name,
            'room_number' => trim((string)($in['room_number'] ?? '')) ?: null,
            'room_type'   => trim((string)($in['room_type'] ?? 'general')) ?: 'general',
            'department'  => trim((string)($in['department'] ?? '')) ?: null,
            'capacity'    => isset($in['capacity']) && $in['capacity'] !== '' ? (int)$in['capacity'] : null,
            'description' => trim((string)($in['description'] ?? '')) ?: null,
            'map_level'   => $level,
            'polygon_points' => $polyJson,
            'label_x'     => $labelX,
            'label_y'     => $labelY,
            'color'       => $color,
        ];
        // Only include the newer columns if they actually exist in this DB, so the
        // save still works if the latest migration hasn't been run yet.
        $hasExt = $hasNotes = $hasBuilding = $hasPrimaryToggle = false;
        try {
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room'")
                        ->fetchAll(PDO::FETCH_COLUMN);
            $hasExt           = in_array('room_extension', $cols, true);
            $hasNotes         = in_array('room_notes', $cols, true);
            $hasBuilding      = in_array('building', $cols, true);
            $hasPrimaryToggle = in_array('show_primary_contact', $cols, true);
        } catch (\Throwable $e) { /* fall back to base columns */ }
        if ($hasExt)   $params['room_extension'] = $roomExt;
        if ($hasNotes) $params['room_notes']     = $roomNotes;
        if ($hasBuilding) {
            $bldg = trim((string)($in['building'] ?? ''));
            $params['building'] = $bldg !== '' ? substr($bldg, 0, 20) : null;
        }
        // Per-room opt-in for the featured-contact card (off by default — see
        // add_room_primary_toggle.sql). Any truthy value from the editor counts.
        if ($hasPrimaryToggle) $params['show_primary_contact'] = !empty($in['show_primary_contact']) ? 1 : 0;

        // Which optional metadata columns map to which input keys. For an UPDATE we
        // ONLY write these when the caller actually included them — so a partial
        // save (e.g. a pin-move that omits "building") can't wipe fields it never
        // intended to touch. Core positional fields below always update.
        $optionalMap = [
            'room_number'    => 'room_number',
            'room_type'      => 'room_type',
            'department'     => 'department',
            'capacity'       => 'capacity',
            'description'    => 'description',
            'color'          => 'color',
            'room_extension' => 'room_extension',
            'room_notes'     => 'room_notes',
            'building'       => 'building',
            'show_primary_contact' => 'show_primary_contact',
        ];

        try {
            if ($roomId > 0) {
                // UPDATE: always-updated core fields + only-if-present optional fields.
                $alwaysUpdate = ['site_number','room_name','map_level','polygon_points','label_x','label_y'];
                $setKeys = [];
                foreach ($params as $k => $v) {
                    if (in_array($k, $alwaysUpdate, true)) { $setKeys[] = $k; continue; }
                    // optional field: include only if the caller sent its input key
                    if (isset($optionalMap[$k])) {
                        if (array_key_exists($optionalMap[$k], $in)) $setKeys[] = $k;
                    } else {
                        $setKeys[] = $k; // anything else, keep prior behavior
                    }
                }
                $execParams = ['room_id' => $roomId];
                foreach ($setKeys as $k) $execParams[$k] = $params[$k];
                $set = implode(', ', array_map(fn($c) => "$c=:$c", $setKeys));
                $stmt = $pdo->prepare("UPDATE room SET $set WHERE room_id=:room_id");
                $stmt->execute($execParams);
            } else {
                // INSERT: write the full set (defaults are correct for a new room).
                $cols = array_keys($params);
                $colList = implode(', ', $cols);
                $valList = implode(', ', array_map(fn($c) => ":$c", $cols));
                $stmt = $pdo->prepare("INSERT INTO room ($colList) VALUES ($valList)");
                $stmt->execute($params);
                $roomId = (int)$pdo->lastInsertId();
            }
            $r = $pdo->prepare("SELECT * FROM room WHERE room_id = ?");
            $r->execute([$roomId]);
            $row = $r->fetch();
            $row['polygon_points'] = $row['polygon_points'] ? json_decode($row['polygon_points'], true) : [];
            if (array_key_exists('room_shape', $row)) {
                $row['room_shape'] = $row['room_shape'] ? json_decode($row['room_shape'], true) : null;
            }
            // PDO can return TINYINT as the string "0", which is truthy in JS — send
            // a clean 0/1 so the frontend's toggle binding behaves correctly.
            $row['show_primary_contact'] = array_key_exists('show_primary_contact', $row)
                ? (int)$row['show_primary_contact'] : 0;
            // attach occupants so the client has them immediately
            try {
                $oc = $pdo->prepare("SELECT * FROM room_occupant WHERE room_id = ? ORDER BY is_primary DESC, sort_order ASC, occupant_id ASC");
                $oc->execute([$roomId]);
                $row['occupants'] = $oc->fetchAll();
            } catch (\Throwable $e) { $row['occupants'] = []; }
            audit($pdo, $wasUpdate ? 'room.update' : 'room.create', [
                'target_type' => 'room',
                'target_label' => ($row['room_name'] ?? ('Room ' . $roomId)) . ' (site ' . $siteNum . ')',
                'details' => ['room_id' => $roomId, 'site' => $siteNum],
            ]);
            jsonResponse(['success' => true, 'room' => $row]);
        } catch (\Throwable $e) {
            error_log('Room save failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to save room — check the server log for details'], 500);
        }
    }

    // Save just the room-view interior boundary (the traced shape). Editor-gated.
    // Body: { room_id, room_shape:[{x,y}...] }  — 3+ points, or null/[] to clear.
    if ($action === 'save_shape' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $roomId = (int)($in['room_id'] ?? 0);
        if ($roomId <= 0) jsonResponse(['success' => false, 'error' => 'Missing room_id'], 400);
        // make sure the column exists (graceful if migration not yet run)
        try {
            $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'room'")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('room_shape', $cols, true)) {
                jsonResponse(['success' => false, 'error' => 'room_shape column missing — run migration.sql'], 500);
            }
        } catch (\Throwable $e) {}
        // verify access via the room's site
        try {
            $sq = $pdo->prepare("SELECT site_number, room_name FROM room WHERE room_id = ?");
            $sq->execute([$roomId]);
            $rinfo = $sq->fetch();
            if (!$rinfo) jsonResponse(['success' => false, 'error' => 'Room not found'], 404);
            if (!can_access_site($pdo, (int)$rinfo['site_number'])) jsonResponse(['success' => false, 'error' => 'No access to this site'], 403);

            $shapeJson = null;
            if (isset($in['room_shape']) && is_array($in['room_shape'])) {
                $clean = [];
                foreach ($in['room_shape'] as $pt) {
                    if (!isset($pt['x'], $pt['y'])) continue;
                    $clean[] = ['x' => max(0.0, min(100.0, (float)$pt['x'])), 'y' => max(0.0, min(100.0, (float)$pt['y']))];
                }
                if (count($clean) >= 3) $shapeJson = json_encode($clean);
            }
            $pdo->prepare("UPDATE room SET room_shape = ? WHERE room_id = ?")->execute([$shapeJson, $roomId]);
            audit($pdo, 'room.shape', ['target_type' => 'room', 'target_label' => ($rinfo['room_name'] ?? ('Room #' . $roomId)), 'details' => ['room_id' => $roomId, 'points' => $shapeJson ? count(json_decode($shapeJson, true)) : 0]]);
            jsonResponse(['success' => true, 'room_shape' => $shapeJson ? json_decode($shapeJson, true) : null]);
        } catch (\Throwable $e) {
            error_log('Save shape failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not save shape'], 500);
        }
    }

    // Delete (soft) a room  (admin only)
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $roomId = (int)($in['room_id'] ?? 0);
        if ($roomId <= 0) jsonResponse(['success' => false, 'error' => 'Missing room_id'], 400);
        try {
            $rn = $pdo->prepare("SELECT room_name, site_number FROM room WHERE room_id = ?");
            $rn->execute([$roomId]); $rinfo = $rn->fetch() ?: [];
            $stmt = $pdo->prepare("UPDATE room SET is_active = 0 WHERE room_id = ?");
            $stmt->execute([$roomId]);
            audit($pdo, 'room.delete', ['target_type' => 'room', 'target_label' => ($rinfo['room_name'] ?? ('Room #' . $roomId)), 'details' => ['room_id' => $roomId, 'site' => $rinfo['site_number'] ?? null]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('Room delete failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to delete'], 500);
        }
    }

    // Unplace a room's pin: clears its map position (label + polygon) so it
    // returns to the Place-items list. Data (name, people, devices) untouched.
    if ($action === 'unplace' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $rid = (int)($in['room_id'] ?? 0);
        if (!$rid) jsonResponse(['success' => false, 'error' => 'Room required'], 400);
        try {
            $chk = $pdo->prepare("SELECT room_name FROM room WHERE room_id = ?");
            $chk->execute([$rid]);
            $r = $chk->fetch();
            if (!$r) jsonResponse(['success' => false, 'error' => 'Room not found'], 404);
            $pdo->prepare("UPDATE room SET label_x = NULL, label_y = NULL, polygon_points = NULL WHERE room_id = ?")->execute([$rid]);
            audit($pdo, 'room.unplace', ['target_type' => 'room', 'target_label' => (string)$r['room_name']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('room unplace failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not unplace room'], 500);
        }
    }

    // Bulk import rooms (admin only) — accepts the room-extractor JSON output.
    // Body: { site_number, replace_level?: "level-1", rooms: [ {room_number, room_name,
    //         room_type?, map_level?, polygon_points:[{x,y}...], label_x?, label_y? } ] }
    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $siteNum = (int)($in['site_number'] ?? 0);
        $rooms = $in['rooms'] ?? null;
        if ($siteNum <= 0 || !is_array($rooms)) {
            jsonResponse(['success' => false, 'error' => 'site_number and rooms[] are required'], 400);
        }
        if (!can_access_site($pdo, $siteNum)) jsonResponse(['success' => false, 'error' => 'You do not have access to this site'], 403);
        // Optional: soft-delete existing rooms on the levels being imported, so a
        // re-import replaces rather than duplicates. Off by default.
        $replaceLevels = [];
        if (!empty($in['replace_levels']) && is_array($in['replace_levels'])) {
            foreach ($in['replace_levels'] as $lv) {
                $replaceLevels[] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$lv);
            }
        }
        $inserted = 0; $skipped = 0;
        // If the extractor detected a building angle, store it on the site so the
        // editor's wall-aligned tools match the imported boxes.
        $angle = isset($in['building_angle']) ? (float)$in['building_angle'] : null;
        try {
            $pdo->beginTransaction();
            if ($angle !== null) {
                while ($angle >  180) $angle -= 360;
                while ($angle <= -180) $angle += 360;
                try {
                    $au = $pdo->prepare("UPDATE site SET building_angle = ? WHERE site_number = ?");
                    $au->execute([$angle, $siteNum]);
                } catch (\Throwable $e) { /* column may not exist yet; non-fatal */ }
            }
            if ($replaceLevels) {
                $ph = implode(',', array_fill(0, count($replaceLevels), '?'));
                $del = $pdo->prepare("UPDATE room SET is_active = 0 WHERE site_number = ? AND map_level IN ($ph)");
                $del->execute(array_merge([$siteNum], $replaceLevels));
            }
            $ins = $pdo->prepare("INSERT INTO room (site_number, room_name, room_number, room_type,
                        department, capacity, description, map_level, polygon_points, label_x, label_y, color)
                    VALUES (:site_number, :room_name, :room_number, :room_type,
                        :department, :capacity, :description, :map_level, :polygon_points, :label_x, :label_y, :color)");
            foreach ($rooms as $rm) {
                if (!is_array($rm)) { $skipped++; continue; }
                $name = trim((string)($rm['room_name'] ?? ('Room ' . ($rm['room_number'] ?? ''))));
                $poly = null;
                if (isset($rm['polygon_points']) && is_array($rm['polygon_points'])) {
                    $clean = [];
                    foreach ($rm['polygon_points'] as $pt) {
                        if (!isset($pt['x'], $pt['y'])) continue;
                        $clean[] = ['x' => max(0.0, min(100.0, (float)$pt['x'])),
                                    'y' => max(0.0, min(100.0, (float)$pt['y']))];
                    }
                    if (count($clean) >= 3) $poly = json_encode($clean);
                }
                if ($name === '' || $poly === null) { $skipped++; continue; }
                $lvl = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($rm['map_level'] ?? 'level-1')) ?: 'level-1';
                $ins->execute([
                    'site_number' => $siteNum,
                    'room_name'   => $name,
                    'room_number' => trim((string)($rm['room_number'] ?? '')) ?: null,
                    'room_type'   => trim((string)($rm['room_type'] ?? 'general')) ?: 'general',
                    'department'  => trim((string)($rm['department'] ?? '')) ?: null,
                    'capacity'    => isset($rm['capacity']) && $rm['capacity'] !== '' ? (int)$rm['capacity'] : null,
                    'description' => trim((string)($rm['description'] ?? '')) ?: null,
                    'map_level'   => $lvl,
                    'polygon_points' => $poly,
                    'label_x'     => isset($rm['label_x']) ? max(0.0, min(100.0, (float)$rm['label_x'])) : null,
                    'label_y'     => isset($rm['label_y']) ? max(0.0, min(100.0, (float)$rm['label_y'])) : null,
                    'color'       => null,
                ]);
                $inserted++;
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Room import failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Import failed'], 500);
        }
        audit($pdo, 'room.import', ['target_type' => 'site', 'target_label' => 'site ' . $siteNum, 'details' => ['inserted' => $inserted, 'skipped' => $skipped]]);
        jsonResponse(['success' => true, 'inserted' => $inserted, 'skipped' => $skipped]);
    }

    // Mass-assign a building to many rooms at once. Body: { room_ids:[...], building:"A1"|null }
    if ($action === 'set_building' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        // Requires the building column.
        try {
            $hasB = db_has_columns($pdo, 'room', ['building']);
            if ((int)$hasB !== 1) jsonResponse(['success' => false, 'error' => 'Building grouping not installed (run add_room_buildings.sql)'], 400);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not check schema'], 500); }
        $in = jsonInput();
        $ids = array_values(array_filter(array_map('intval', (array)($in['room_ids'] ?? []))));
        $bldgRaw = $in['building'] ?? null;
        $bldg = ($bldgRaw === null || trim((string)$bldgRaw) === '') ? null : substr(trim((string)$bldgRaw), 0, 20);
        if (!$ids) jsonResponse(['success' => false, 'error' => 'No rooms selected'], 400);
        if (count($ids) > 2000) jsonResponse(['success' => false, 'error' => 'Too many rooms in one operation'], 400);
        try {
            $place = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("UPDATE room SET building = ? WHERE room_id IN ($place)");
            $stmt->execute(array_merge([$bldg], $ids));
            audit($pdo, 'room.set_building', ['target_type' => 'room', 'target_label' => count($ids) . ' rooms', 'details' => ['building' => $bldg, 'count' => count($ids)]]);
            jsonResponse(['success' => true, 'updated' => $stmt->rowCount()]);
        } catch (\Throwable $e) {
            error_log('set_building failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not update rooms'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown room action'], 400);
}

// ================================================================
//  BUILDING MANAGEMENT API — the managed per-site list of building codes
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'building') {
    $action = $_GET['action'] ?? '';
    // Graceful check that the shared pool table exists.
    $poolOk = function () use ($pdo) {
        try { return db_has_table($pdo, 'building_pool'); }
        catch (\Throwable $e) { return false; }
    };

    // List the shared pool of building codes (anyone signed in can read).
    // The `site` param is accepted but ignored — the pool is district-wide.
    if ($action === 'list') {
        if (!$poolOk()) jsonResponse(['success' => true, 'buildings' => []]); // not installed yet
        try {
            $rows = $pdo->query("SELECT id, code, label, sort_order FROM building_pool ORDER BY sort_order ASC, code ASC")->fetchAll();
            jsonResponse(['success' => true, 'buildings' => $rows]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not load buildings'], 500); }
    }

    // Add a code to the shared pool. Body: { code, label? }
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$poolOk()) jsonResponse(['success' => false, 'error' => 'Building grouping not installed (run add_room_buildings.sql)'], 400);
        $in = jsonInput();
        $code = substr(trim((string)($in['code'] ?? '')), 0, 20);
        $label = trim((string)($in['label'] ?? '')) ?: null;
        if ($code === '') jsonResponse(['success' => false, 'error' => 'Building code is required'], 400);
        if (!preg_match('/^[A-Za-z0-9 ._-]{1,20}$/', $code)) jsonResponse(['success' => false, 'error' => 'Building code can use letters, numbers, spaces, . _ -'], 400);
        try {
            $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM building_pool")->fetchColumn();
            $pdo->prepare("INSERT INTO building_pool (code, label, sort_order) VALUES (?,?,?)")->execute([$code, $label, $next]);
            audit($pdo, 'building.add', ['target_type' => 'setting', 'target_label' => 'building pool', 'details' => ['code' => $code]]);
            jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') jsonResponse(['success' => false, 'error' => 'That building code already exists in the pool'], 409);
            jsonResponse(['success' => false, 'error' => 'Could not add building'], 500);
        }
    }

    // Generate a grid of codes (columns x rows) into the pool, skipping any that
    // already exist. Body: { columns:["A".."H"]|count, rows:int }  Letter=column, number=row.
    if ($action === 'generate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$poolOk()) jsonResponse(['success' => false, 'error' => 'Building grouping not installed (run add_room_buildings.sql)'], 400);
        $in = jsonInput();
        // Accept either an explicit list of column letters, or a count A..N.
        $cols = [];
        if (isset($in['columns']) && is_array($in['columns'])) {
            foreach ($in['columns'] as $c) {
                $c = strtoupper(substr(trim((string)$c), 0, 2));
                if (preg_match('/^[A-Z]{1,2}$/', $c)) $cols[] = $c;
            }
        } else {
            $colCount = max(1, min(26, (int)($in['col_count'] ?? 8)));
            for ($i = 0; $i < $colCount; $i++) $cols[] = chr(65 + $i);
        }
        $rows = max(1, min(20, (int)($in['rows'] ?? 6)));
        $cols = array_values(array_unique($cols));
        if (!$cols) jsonResponse(['success' => false, 'error' => 'No valid columns'], 400);
        try {
            $base = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM building_pool")->fetchColumn();
            $ins = $pdo->prepare("INSERT IGNORE INTO building_pool (code, sort_order) VALUES (?,?)");
            $added = 0; $order = $base;
            foreach ($cols as $ci => $col) {
                for ($r = 1; $r <= $rows; $r++) {
                    $order = $base + (($ci + 1) * 100) + $r;
                    $ins->execute([$col . $r, $order]);
                    $added += $ins->rowCount();
                }
            }
            audit($pdo, 'building.generate', ['target_type' => 'setting', 'target_label' => 'building pool', 'details' => ['columns' => $cols, 'rows' => $rows, 'added' => $added]]);
            $total = (int)$pdo->query("SELECT COUNT(*) FROM building_pool")->fetchColumn();
            jsonResponse(['success' => true, 'added' => $added, 'total' => $total]);
        } catch (\Throwable $e) {
            error_log('building generate failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not generate codes'], 500);
        }
    }

    // Rename/relabel a pooled code. Body: { id, code?, label? } (syncs rooms district-wide)
    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$poolOk()) jsonResponse(['success' => false, 'error' => 'Building grouping not installed'], 400);
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing building'], 400);
        try {
            $cur = $pdo->prepare("SELECT code FROM building_pool WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Building not found'], 404);
            $newCode = array_key_exists('code', $in) ? substr(trim((string)$in['code']), 0, 20) : $row['code'];
            $newLabel = array_key_exists('label', $in) ? (trim((string)$in['label']) ?: null) : null;
            if ($newCode === '') jsonResponse(['success' => false, 'error' => 'Code cannot be empty'], 400);
            if (!preg_match('/^[A-Za-z0-9 ._-]{1,20}$/', $newCode)) jsonResponse(['success' => false, 'error' => 'Invalid building code'], 400);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE building_pool SET code = ?, label = ? WHERE id = ?")->execute([$newCode, $newLabel, $id]);
            // Renaming a pooled code updates every room using it, across all sites.
            if ($newCode !== $row['code']) {
                $pdo->prepare("UPDATE room SET building = ? WHERE building = ?")->execute([$newCode, $row['code']]);
            }
            $pdo->commit();
            audit($pdo, 'building.update', ['target_type' => 'setting', 'target_label' => 'building pool', 'details' => ['from' => $row['code'], 'to' => $newCode]]);
            jsonResponse(['success' => true]);
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') jsonResponse(['success' => false, 'error' => 'That code already exists in the pool'], 409);
            jsonResponse(['success' => false, 'error' => 'Could not update building'], 500);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not update building'], 500);
        }
    }

    // Remove a code from the pool. Body: { id, clear_rooms? }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$poolOk()) jsonResponse(['success' => false, 'error' => 'Building grouping not installed'], 400);
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        $clearRooms = !empty($in['clear_rooms']);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing building'], 400);
        try {
            $cur = $pdo->prepare("SELECT code FROM building_pool WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Building not found'], 404);
            $pdo->prepare("DELETE FROM building_pool WHERE id = ?")->execute([$id]);
            if ($clearRooms) {
                $pdo->prepare("UPDATE room SET building = NULL WHERE building = ?")->execute([$row['code']]);
            }
            audit($pdo, 'building.delete', ['target_type' => 'setting', 'target_label' => 'building pool', 'details' => ['code' => $row['code'], 'cleared_rooms' => $clearRooms]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not delete building'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown building action'], 400);
}

// ================================================================
//  SITE MAPS API — multiple maps (suites/floors) per site
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'sitemap') {
    $action = $_GET['action'] ?? '';
    $mapTableOk = function () use ($pdo) {
        try { return db_has_table($pdo, 'site_map'); }
        catch (\Throwable $e) { return false; }
    };

    // List a site's maps (any signed-in user).
    if ($action === 'list') {
        $siteNum = filter_input(INPUT_GET, 'site', FILTER_VALIDATE_INT);
        if (!$siteNum) jsonResponse(['success' => false, 'error' => 'Missing site'], 400);
        if (!$mapTableOk()) jsonResponse(['success' => true, 'maps' => []]);
        try {
            $hasDef = false;
            try { $pdo->query("SELECT is_default FROM site_map LIMIT 1"); $hasDef = true; } catch (\Throwable $e) {}
            $hasZoom = false;
            try { $pdo->query("SELECT default_zoom FROM site_map LIMIT 1"); $hasZoom = true; } catch (\Throwable $e) {}
            $hasDot = false;
            try { $pdo->query("SELECT dot_zoom FROM site_map LIMIT 1"); $hasDot = true; } catch (\Throwable $e) {}
            $dcol = ($hasDef ? ', is_default' : '') . ($hasZoom ? ', default_zoom' : '') . ($hasDot ? ', dot_zoom' : '');
            $stmt = $pdo->prepare("SELECT id, map_key, name, svg_path, sort_order$dcol FROM site_map WHERE site_number = ? ORDER BY sort_order ASC, name ASC");
            $stmt->execute([$siteNum]);
            $maps = array_map(function ($m) use ($hasDef, $hasZoom, $hasDot) {
                return ['id' => (int)$m['id'], 'key' => $m['map_key'], 'name' => $m['name'],
                        'has_svg' => !empty($m['svg_path']) && file_exists($m['svg_path']), 'sort_order' => (int)$m['sort_order'],
                        'is_default' => $hasDef ? ((int)($m['is_default'] ?? 0) === 1) : false,
                        'default_zoom' => ($hasZoom && $m['default_zoom'] !== null) ? (float)$m['default_zoom'] : null,
                        'dot_zoom' => ($hasDot && ($m['dot_zoom'] ?? null) !== null) ? (float)$m['dot_zoom'] : null];
            }, $stmt->fetchAll());
            // Include start-view fields when the columns exist, so the client's
            // local maps list keeps them across Map Manager saves.
            if (db_has_columns($pdo, 'site_map', ['focus_x','focus_y'])) {
                foreach ($maps as &$mm) {
                    $mm['focus_x'] = isset($mm['focus_x']) && $mm['focus_x'] !== null ? (float)$mm['focus_x'] : null;
                    $mm['focus_y'] = isset($mm['focus_y']) && $mm['focus_y'] !== null ? (float)$mm['focus_y'] : null;
                }
                unset($mm);
            }
            jsonResponse(['success' => true, 'maps' => $maps]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not load maps'], 500); }
    }

    // Add a new map to a site. Body: { site_number, name }
    if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$mapTableOk()) jsonResponse(['success' => false, 'error' => 'Multiple maps not installed (run add_site_maps.sql)'], 400);
        $in = jsonInput();
        $siteNum = (int)($in['site_number'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if (!$siteNum || $name === '') jsonResponse(['success' => false, 'error' => 'Site and map name are required'], 400);
        if (strlen($name) > 100) $name = substr($name, 0, 100);
        try {
            // Generate a unique, stable map_key for this site.
            $base = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name)) ?: 'map';
            $base = trim($base, '-');
            $key = $base; $i = 2;
            $chk = $pdo->prepare("SELECT COUNT(*) FROM site_map WHERE site_number = ? AND map_key = ?");
            while (true) {
                $chk->execute([$siteNum, $key]);
                if ((int)$chk->fetchColumn() === 0) break;
                $key = $base . '-' . $i; $i++;
            }
            $next = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0)+1 FROM site_map WHERE site_number = " . $siteNum)->fetchColumn();
            $pdo->prepare("INSERT INTO site_map (site_number, map_key, name, sort_order) VALUES (?,?,?,?)")
                ->execute([$siteNum, $key, $name, $next]);
            audit($pdo, 'sitemap.add', ['target_type' => 'site', 'target_label' => 'site ' . $siteNum, 'details' => ['name' => $name, 'key' => $key]]);
            jsonResponse(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'key' => $key]);
        } catch (\Throwable $e) {
            error_log('sitemap add failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not add map'], 500);
        }
    }

    // Rename a map. Body: { id, name }
    if ($action === 'rename' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$mapTableOk()) jsonResponse(['success' => false, 'error' => 'Multiple maps not installed'], 400);
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        $name = trim((string)($in['name'] ?? ''));
        if (!$id || $name === '') jsonResponse(['success' => false, 'error' => 'Map and name required'], 400);
        try {
            $pdo->prepare("UPDATE site_map SET name = ? WHERE id = ?")->execute([substr($name, 0, 100), $id]);
            audit($pdo, 'sitemap.rename', ['target_type' => 'site', 'target_label' => $name]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not rename map'], 500); }
    }

    // Reorder maps. Body: { site_number, order:[id,id,...] }
    if ($action === 'reorder' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$mapTableOk()) jsonResponse(['success' => false, 'error' => 'Multiple maps not installed'], 400);
        $in = jsonInput();
        $ids = array_values(array_filter(array_map('intval', (array)($in['order'] ?? []))));
        try {
            $up = $pdo->prepare("UPDATE site_map SET sort_order = ? WHERE id = ?");
            foreach ($ids as $i => $id) $up->execute([$i, $id]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not reorder'], 500); }
    }

    // Delete a map. Body: { id, move_rooms_to? }  Rooms on it are reassigned or hidden.
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$mapTableOk()) jsonResponse(['success' => false, 'error' => 'Multiple maps not installed'], 400);
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing map'], 400);
        try {
            $cur = $pdo->prepare("SELECT site_number, map_key, name FROM site_map WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Map not found'], 404);
            // Count rooms on this map; refuse to delete if it still has rooms (safety).
            $rc = $pdo->prepare("SELECT COUNT(*) FROM room WHERE site_number = ? AND map_level = ? AND is_active = 1");
            $rc->execute([(int)$row['site_number'], $row['map_key']]);
            if ((int)$rc->fetchColumn() > 0 && empty($in['force'])) {
                jsonResponse(['success' => false, 'error' => 'This map still has rooms. Move or remove them first.', 'has_rooms' => true], 409);
            }
            $pdo->prepare("DELETE FROM site_map WHERE id = ?")->execute([$id]);
            audit($pdo, 'sitemap.delete', ['target_type' => 'site', 'target_label' => $row['name']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not delete map'], 500); }
    }

    // Mark one map as the site's default (the level shown first when you open
    // the site). Clears the flag on the site's other maps so only one is default.
    if ($action === 'set_zoom' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$mapTableOk()) jsonResponse(['success' => false, 'error' => 'Multiple maps not installed'], 400);
        // Needs the default_zoom column (add_map_zoom.sql).
        try { $pdo->query("SELECT default_zoom FROM site_map LIMIT 1"); }
        catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Zoom column missing (run add_map_zoom.sql)'], 400); }
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing map'], 400);
        // zoom is a multiplier (e.g. 5.0 = 500%). null/0 clears the override (auto-fit).
        $zoom = $in['zoom'] ?? null;
        if ($zoom === null || $zoom === '' || (float)$zoom <= 0) {
            $zoomVal = null;
        } else {
            $zoomVal = (float)$zoom;
            // clamp to the app's allowed range (0.1–20)
            if ($zoomVal < 0.1) $zoomVal = 0.1;
            if ($zoomVal > 20)  $zoomVal = 20;
        }
        try {
            $cur = $pdo->prepare("SELECT name FROM site_map WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Map not found'], 404);
            $pdo->prepare("UPDATE site_map SET default_zoom = ? WHERE id = ?")->execute([$zoomVal, $id]);
            audit($pdo, 'sitemap.set_zoom', ['target_type' => 'site', 'target_label' => $row['name'], 'details' => ['zoom' => $zoomVal]]);
            jsonResponse(['success' => true, 'default_zoom' => $zoomVal]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not save zoom'], 500);
        }
    }

    // Per-map mini-pin threshold. Body: { id, dot_zoom } where dot_zoom is
    // null (app default), 0 (never dots on this map), or a multiplier.
    if ($action === 'set_dot_zoom' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        try { $pdo->query("SELECT dot_zoom FROM site_map LIMIT 1"); }
        catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Mini-pin column missing (run add_map_dot_zoom.sql)'], 400); }
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing map'], 400);
        $raw = $in['dot_zoom'] ?? null;
        if ($raw === null || $raw === '') {
            $val = null;                       // back to the app default
        } else {
            $val = (float)$raw;
            if ($val < 0) $val = 0;            // 0 = never dots
            if ($val > 20) $val = 20;
        }
        try {
            $cur = $pdo->prepare("SELECT name FROM site_map WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Map not found'], 404);
            $pdo->prepare("UPDATE site_map SET dot_zoom = ? WHERE id = ?")->execute([$val, $id]);
            audit($pdo, 'sitemap.set_dot_zoom', ['target_type' => 'site', 'target_label' => $row['name'], 'details' => ['dot_zoom' => $val]]);
            jsonResponse(['success' => true, 'dot_zoom' => $val]);
        } catch (\Throwable $e) {
            jsonResponse(['success' => false, 'error' => 'Could not save'], 500);
        }
    }

    if ($action === 'set_default' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$mapTableOk()) jsonResponse(['success' => false, 'error' => 'Multiple maps not installed'], 400);
        // Needs the is_default column (add_map_default.sql).
        try { $pdo->query("SELECT is_default FROM site_map LIMIT 1"); }
        catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Default-map column missing (run add_map_default.sql)'], 400); }
        $in = jsonInput();
        $id = (int)($in['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing map'], 400);
        try {
            $cur = $pdo->prepare("SELECT site_number, name FROM site_map WHERE id = ?");
            $cur->execute([$id]);
            $row = $cur->fetch();
            if (!$row) jsonResponse(['success' => false, 'error' => 'Map not found'], 404);
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE site_map SET is_default = 0 WHERE site_number = ?")->execute([(int)$row['site_number']]);
            $pdo->prepare("UPDATE site_map SET is_default = 1 WHERE id = ?")->execute([$id]);
            $pdo->commit();
            audit($pdo, 'sitemap.set_default', ['target_type' => 'site', 'target_label' => $row['name']]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            jsonResponse(['success' => false, 'error' => 'Could not set default'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown sitemap action'], 400);
}

// ================================================================
//  CAMERA API — map placement for the cameras layer
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'camera') {
    $action = $_GET['action'] ?? '';

    // Move a camera pin on the map. Body: { camera_number, map_x, map_y }
    // Editor permission required — same bar as repositioning rooms.
    if ($action === 'set_position' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'cameras', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $camNum = (int)($in['camera_number'] ?? 0);
        $mx = $in['map_x'] ?? null;
        $my = $in['map_y'] ?? null;
        $unplace = !empty($in['unplace']);   // clears the pin back to the Place-items list
        if (!$camNum || (!$unplace && (!is_numeric($mx) || !is_numeric($my)))) jsonResponse(['success' => false, 'error' => 'Camera and position required'], 400);
        try {
            $chk = $pdo->prepare("SELECT camera_name, site_number FROM camera WHERE camera_number = ?");
            $chk->execute([$camNum]);
            $cam = $chk->fetch();
            if (!$cam) jsonResponse(['success' => false, 'error' => 'Camera not found'], 404);
            if ($unplace) {
                $pdo->prepare("UPDATE camera SET map_x = NULL, map_y = NULL WHERE camera_number = ?")->execute([$camNum]);
                audit($pdo, 'camera.unplace', ['target_type' => 'camera', 'target_label' => (string)$cam['camera_name']]);
                jsonResponse(['success' => true]);
            }
            $mx = max(0, min(100, (float)$mx));
            $my = max(0, min(100, (float)$my));
            $lvl = isset($in['map_level']) ? substr(trim((string)$in['map_level']), 0, 40) : null;
            if ($lvl !== null && $lvl !== '') {
                $pdo->prepare("UPDATE camera SET map_x = ?, map_y = ?, map_level = ? WHERE camera_number = ?")
                    ->execute([round($mx, 4), round($my, 4), $lvl, $camNum]);
            } else {
                $pdo->prepare("UPDATE camera SET map_x = ?, map_y = ? WHERE camera_number = ?")
                    ->execute([round($mx, 4), round($my, 4), $camNum]);
            }
            audit($pdo, 'camera.move', ['target_type' => 'camera', 'target_label' => (string)$cam['camera_name'], 'details' => ['x' => round($mx,2), 'y' => round($my,2)]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('camera set_position failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not move camera'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown camera action'], 400);
}

// ================================================================
//  PRINTER API — the printers layer (asset pins per site map)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'printer') {
    $action = $_GET['action'] ?? '';
    $printerTableOk = function () use ($pdo) {
        try { return db_has_table($pdo, 'printer'); }
        catch (\Throwable $e) { return false; }
    };

    // List printers for a site (any signed-in user who can see the site).
    if ($action === 'list') {
        $siteNum = filter_input(INPUT_GET, 'site', FILTER_VALIDATE_INT);
        if (!$siteNum) jsonResponse(['success' => false, 'error' => 'Missing site'], 400);
        if (!$printerTableOk()) jsonResponse(['success' => true, 'printers' => []]);
        try {
            $stmt = $pdo->prepare("SELECT printer_id, site_number, printer_name, location, web_interface, model, serial_number, mac_address, toner_id, barcode, notes, map_x, map_y, map_level, map_icon_rotation FROM printer WHERE site_number = ? AND is_active = 1 ORDER BY printer_name");
            $stmt->execute([$siteNum]);
            jsonResponse(['success' => true, 'printers' => $stmt->fetchAll()]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not load printers'], 500); }
    }

    // Create or update a printer (editor+). Body carries the asset fields.
    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$printerTableOk()) jsonResponse(['success' => false, 'error' => 'Printers layer not installed (run add_printers.sql)'], 400);
        $in = jsonInput();
        $id = (int)($in['printer_id'] ?? 0);
        $siteNum = (int)($in['site_number'] ?? 0);
        $name = trim((string)($in['printer_name'] ?? ''));
        if ($name === '') jsonResponse(['success' => false, 'error' => 'Printer name is required'], 400);
        if (!$siteNum && !$id) jsonResponse(['success' => false, 'error' => 'Site is required'], 400);
        // Validate the web interface link if provided (http/https only).
        $web = trim((string)($in['web_interface'] ?? ''));
        if ($web !== '' && !preg_match('#^https?://#i', $web)) $web = 'http://' . $web;  // be forgiving
        if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) jsonResponse(['success' => false, 'error' => 'Web interface must be a valid URL'], 400);
        $fields = [
            'printer_name'  => substr($name, 0, 120),
            'location'      => substr(trim((string)($in['location'] ?? '')), 0, 160) ?: null,
            'web_interface' => $web !== '' ? substr($web, 0, 255) : null,
            'model'         => substr(trim((string)($in['model'] ?? '')), 0, 120) ?: null,
            'serial_number' => substr(trim((string)($in['serial_number'] ?? '')), 0, 120) ?: null,
            'mac_address'   => substr(trim((string)($in['mac_address'] ?? '')), 0, 64) ?: null,
            'toner_id'      => substr(trim((string)($in['toner_id'] ?? '')), 0, 120) ?: null,
            'barcode'       => substr(trim((string)($in['barcode'] ?? '')), 0, 120) ?: null,
            'notes'         => trim((string)($in['notes'] ?? '')) ?: null,
            'map_level'     => substr(trim((string)($in['map_level'] ?? 'level-1')), 0, 40) ?: 'level-1',
        ];
        // Optional placement.
        if (isset($in['map_x']) && is_numeric($in['map_x'])) $fields['map_x'] = max(0, min(100, (float)$in['map_x']));
        if (isset($in['map_y']) && is_numeric($in['map_y'])) $fields['map_y'] = max(0, min(100, (float)$in['map_y']));
        try {
            if ($id) {
                $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                $sql = "UPDATE printer SET $set WHERE printer_id = :id";
                $params = $fields; $params['id'] = $id;
                $pdo->prepare($sql)->execute($params);
                audit($pdo, 'printer.update', ['target_type' => 'printer', 'target_label' => $name]);
                jsonResponse(['success' => true, 'printer_id' => $id]);
            } else {
                $fields['site_number'] = $siteNum;
                $cols = implode(', ', array_keys($fields));
                $ph   = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                $pdo->prepare("INSERT INTO printer ($cols) VALUES ($ph)")->execute($fields);
                $newId = (int)$pdo->lastInsertId();
                audit($pdo, 'printer.create', ['target_type' => 'printer', 'target_label' => $name]);
                jsonResponse(['success' => true, 'printer_id' => $newId]);
            }
        } catch (\Throwable $e) {
            error_log('printer save failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not save printer'], 500);
        }
    }

    // Move a printer pin (editor+). Body: { printer_id, map_x, map_y }
    if ($action === 'set_position' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$printerTableOk()) jsonResponse(['success' => false, 'error' => 'Printers layer not installed'], 400);
        $in = jsonInput();
        $id = (int)($in['printer_id'] ?? 0);
        $mx = $in['map_x'] ?? null; $my = $in['map_y'] ?? null;
        $unplace = !empty($in['unplace']);   // clears the pin back to the Place-items list
        if (!$id || (!$unplace && (!is_numeric($mx) || !is_numeric($my)))) jsonResponse(['success' => false, 'error' => 'Printer and position required'], 400);
        if ($unplace) {
            try {
                $pdo->prepare("UPDATE printer SET map_x = NULL, map_y = NULL WHERE printer_id = ?")->execute([$id]);
                jsonResponse(['success' => true]);
            } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not unplace printer'], 500); }
        }
        $mx = max(0, min(100, (float)$mx)); $my = max(0, min(100, (float)$my));
        $lvl = isset($in['map_level']) ? substr(trim((string)$in['map_level']), 0, 40) : null;
        try {
            if ($lvl !== null && $lvl !== '') {
                $pdo->prepare("UPDATE printer SET map_x = ?, map_y = ?, map_level = ? WHERE printer_id = ?")->execute([round($mx,4), round($my,4), $lvl, $id]);
            } else {
                $pdo->prepare("UPDATE printer SET map_x = ?, map_y = ? WHERE printer_id = ?")->execute([round($mx,4), round($my,4), $id]);
            }
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not move printer'], 500); }
    }

    // Delete (soft) a printer (editor+). Body: { printer_id }
    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$printerTableOk()) jsonResponse(['success' => false, 'error' => 'Printers layer not installed'], 400);
        $in = jsonInput();
        $id = (int)($in['printer_id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing printer'], 400);
        try {
            $cur = $pdo->prepare("SELECT printer_name FROM printer WHERE printer_id = ?");
            $cur->execute([$id]);
            $nm = $cur->fetchColumn();
            $pdo->prepare("UPDATE printer SET is_active = 0 WHERE printer_id = ?")->execute([$id]);
            audit($pdo, 'printer.delete', ['target_type' => 'printer', 'target_label' => (string)$nm]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not delete printer'], 500); }
    }

    // Assign a printer to a room and set its spot on the room diagram (editor+).
    // Body: { printer_id, room_id, room_pos_x, room_pos_y }
    if ($action === 'assign_room' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$printerTableOk()) jsonResponse(['success' => false, 'error' => 'Printers layer not installed'], 400);
        // The room columns must exist (add_printer_room.sql).
        try {
            $hasRoom = db_has_columns($pdo, 'printer', ['room_id']);
            if (!$hasRoom) jsonResponse(['success' => false, 'error' => 'Room placement not installed (run add_printer_room.sql)'], 400);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Schema check failed'], 500); }
        $in = jsonInput();
        $id = (int)($in['printer_id'] ?? 0);
        $roomId = (int)($in['room_id'] ?? 0);
        $rx = $in['room_pos_x'] ?? null; $ry = $in['room_pos_y'] ?? null;
        if (!$id || !$roomId || !is_numeric($rx) || !is_numeric($ry)) jsonResponse(['success' => false, 'error' => 'Printer, room and position required'], 400);
        $rx = max(0, min(100, (float)$rx)); $ry = max(0, min(100, (float)$ry));
        try {
            $pdo->prepare("UPDATE printer SET room_id = ?, room_pos_x = ?, room_pos_y = ? WHERE printer_id = ?")
                ->execute([$roomId, round($rx,4), round($ry,4), $id]);
            audit($pdo, 'printer.assign_room', ['target_type' => 'printer', 'target_label' => 'printer ' . $id, 'details' => ['room_id' => $roomId]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not assign printer'], 500); }
    }

    // Move a printer within its room diagram (editor+). Body: { printer_id, room_pos_x, room_pos_y }
    if ($action === 'set_room_position' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $id = (int)($in['printer_id'] ?? 0);
        $rx = $in['room_pos_x'] ?? null; $ry = $in['room_pos_y'] ?? null;
        if (!$id || !is_numeric($rx) || !is_numeric($ry)) jsonResponse(['success' => false, 'error' => 'Printer and position required'], 400);
        $rx = max(0, min(100, (float)$rx)); $ry = max(0, min(100, (float)$ry));
        try {
            $pdo->prepare("UPDATE printer SET room_pos_x = ?, room_pos_y = ? WHERE printer_id = ?")->execute([round($rx,4), round($ry,4), $id]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not move printer'], 500); }
    }

    // Remove a printer's room assignment (editor+). Body: { printer_id }
    if ($action === 'unassign_room' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $id = (int)($in['printer_id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'error' => 'Missing printer'], 400);
        try {
            $pdo->prepare("UPDATE printer SET room_id = NULL, room_pos_x = NULL, room_pos_y = NULL WHERE printer_id = ?")->execute([$id]);
            audit($pdo, 'printer.unassign_room', ['target_type' => 'printer', 'target_label' => 'printer ' . $id]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) { jsonResponse(['success' => false, 'error' => 'Could not unassign printer'], 500); }
    }

    // Bulk import (editor+). Body: { rows:[{site_number, printer_name, location,
    // web_interface, model, serial_number, mac_address, toner_id, barcode, notes}, ...] }
    // Skips rows whose serial_number already exists (case-insensitive) or with no site.
    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'printers', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        if (!$printerTableOk()) jsonResponse(['success' => false, 'error' => 'Printers layer not installed (run add_printers.sql)'], 400);
        $in = jsonInput();
        $rows = is_array($in['rows'] ?? null) ? $in['rows'] : [];
        if (!$rows) jsonResponse(['success' => false, 'error' => 'No rows to import'], 400);
        if (count($rows) > 5000) jsonResponse(['success' => false, 'error' => 'Too many rows in one import'], 400);
        // Existing printers for dedup — by serial (preferred) and by name (fallback
        // for serial-less printers, so re-importing the same CSV doesn't duplicate).
        $existing = [];        // serial => true
        $existingNames = [];   // normalized name => true
        $nrm = static fn($v) => strtolower(preg_replace('/\s+/', ' ', trim((string)$v)));
        try {
            foreach ($pdo->query("SELECT serial_number, printer_name, site_number FROM printer")->fetchAll() as $row) {
                $s = strtolower(trim((string)($row['serial_number'] ?? '')));
                if ($s !== '') $existing[$s] = true;
                $nm = $nrm($row['printer_name'] ?? '');
                if ($nm !== '') $existingNames[(int)$row['site_number'] . '|' . $nm] = true;
            }
        } catch (\Throwable $e) {}
        $clip = static fn($v, $n) => ($v === null || $v === '') ? null : substr(trim((string)$v), 0, $n);
        $imported = 0; $skippedDup = 0; $skippedNoSite = 0; $seen = []; $seenNames = [];
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO printer (site_number, printer_name, location, web_interface, model, serial_number, mac_address, toner_id, barcode, notes, map_level) VALUES (?,?,?,?,?,?,?,?,?,?, 'level-1')");
            foreach ($rows as $r) {
                $site = (int)($r['site_number'] ?? 0);
                $name = trim((string)($r['printer_name'] ?? ''));
                if (!$site) { $skippedNoSite++; continue; }
                if ($name === '') continue;
                $sn = strtolower(trim((string)($r['serial_number'] ?? '')));
                $nmKey = (int)$site . '|' . $nrm($name);
                // Dup by serial, or (serial-less) by name within the same site.
                if ($sn !== '') {
                    if (isset($existing[$sn]) || isset($seen[$sn])) { $skippedDup++; continue; }
                    $seen[$sn] = true;
                } else {
                    if (isset($existingNames[$nmKey]) || isset($seenNames[$nmKey])) { $skippedDup++; continue; }
                }
                if ($nmKey !== '') $seenNames[$nmKey] = true;
                $web = trim((string)($r['web_interface'] ?? ''));
                if ($web !== '' && !preg_match('#^https?://#i', $web)) $web = 'http://' . $web;
                if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) $web = null;
                $stmt->execute([
                    $site,
                    substr($name, 0, 120),
                    $clip($r['location'] ?? '', 160),
                    $web ? substr($web, 0, 255) : null,
                    $clip($r['model'] ?? '', 120),
                    $clip($r['serial_number'] ?? '', 120),
                    $clip($r['mac_address'] ?? '', 64),
                    $clip($r['toner_id'] ?? '', 120),
                    $clip($r['barcode'] ?? '', 120),
                    ($r['notes'] ?? '') !== '' ? trim((string)$r['notes']) : null,
                ]);
                $imported++;
            }
            $pdo->commit();
            audit($pdo, 'printer.import', ['target_type' => 'setting', 'target_label' => 'printers', 'details' => ['imported' => $imported, 'skipped_dup' => $skippedDup, 'skipped_nosite' => $skippedNoSite]]);
            jsonResponse(['success' => true, 'imported' => $imported, 'skipped_dup' => $skippedDup, 'skipped_nosite' => $skippedNoSite]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('printer import failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Import failed'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown printer action'], 400);
}

// ================================================================
//  DATA EDITOR API — curated, guarded raw-table editing (db_admin only)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'dataedit') {
    if (!can($pdo, 'data_admin', 'view')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
    $action = $_GET['action'] ?? '';
    // Reads (tables/rows) need 'view'; writes (update/insert/delete) need 'manage'.
    if (in_array($action, ['update', 'insert', 'delete'], true) && !can($pdo, 'data_admin', 'manage')) {
        jsonResponse(['success' => false, 'error' => 'Forbidden — view only'], 403);
    }

    // Curated schema. Only these tables/columns are editable; everything else
    // (PKs except as targets, password_hash, totp_secret, audit_log, …) is
    // invisible and untouchable. types: text | int | float | bool | longtext
    $SCHEMA = [
        'printer' => [
            'label' => 'printer_name', 'pk' => 'printer_id',
            'cols' => [
                'printer_name'=>'text','location'=>'text','web_interface'=>'text','model'=>'text',
                'serial_number'=>'text','mac_address'=>'text','toner_id'=>'text','barcode'=>'text',
                'notes'=>'longtext','site_number'=>'int','map_level'=>'text','is_active'=>'bool',
            ],
        ],
        'camera' => [
            'label' => 'camera_name', 'pk' => 'camera_number',
            'cols' => [
                'camera_name'=>'text','camera_ip'=>'text','embed_url'=>'text','site_number'=>'int',
                'map_level'=>'text','is_active'=>'bool',
            ],
        ],
        'device' => [
            'label' => 'device_name', 'pk' => 'device_id',
            'cols' => [
                'device_name'=>'text','device_type_key'=>'text','asset_tag'=>'text','model'=>'text',
                'serial_number'=>'text','ip_address'=>'text','status'=>'text','notes'=>'longtext','room_id'=>'int',
            ],
        ],
        'room' => [
            'label' => 'room_name', 'pk' => 'room_id',
            'cols' => [
                'room_name'=>'text','room_number'=>'text','room_type'=>'text','department'=>'text',
                'capacity'=>'int','building'=>'text','room_extension'=>'text','room_notes'=>'longtext',
                'site_number'=>'int','map_level'=>'text','is_active'=>'bool',
            ],
        ],
        'site' => [
            'label' => 'site_name', 'pk' => 'site_number',
            'cols' => [
                'site_name'=>'text','site_abbreviation'=>'text','building_angle'=>'float','site_active'=>'bool',
            ],
        ],
    ];

    $coerce = function ($type, $val) {
        if ($val === null) return null;
        switch ($type) {
            case 'int':   return ($val === '' ? null : (int)$val);
            case 'float': return ($val === '' ? null : (float)$val);
            case 'bool':  return ((int)(bool)$val) ? 1 : 0;
            default:      return ($val === '' ? null : (string)$val);
        }
    };

    if ($action === 'tables') {
        $out = [];
        foreach ($SCHEMA as $t => $def) {
            $hasSite = isset($def['cols']['site_number']);   // site table itself filters via its PK separately
            $out[] = ['table' => $t, 'label' => $def['label'], 'pk' => $def['pk'],
                      'has_site' => $hasSite,
                      'cols' => array_map(fn($c, $ty) => ['name' => $c, 'type' => $ty], array_keys($def['cols']), array_values($def['cols']))];
        }
        jsonResponse(['success' => true, 'tables' => $out]);
    }

    if ($action === 'rows') {
        $t = (string)($_GET['table'] ?? '');
        if (!isset($SCHEMA[$t])) jsonResponse(['success' => false, 'error' => 'Unknown table'], 400);
        $def = $SCHEMA[$t];
        $selectCols = array_values(array_unique(array_merge([$def['pk']], array_keys($def['cols']))));
        $colList = implode(', ', array_map(fn($c) => "`$c`", $selectCols));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per = 50; $off = ($page - 1) * $per;
        $q = trim((string)($_GET['q'] ?? ''));
        $where = ''; $params = []; $conds = [];
        if ($q !== '') {
            $textCols = array_keys(array_filter($def['cols'], fn($ty) => $ty === 'text' || $ty === 'longtext'));
            if ($textCols) {
                $conds[] = '(' . implode(' OR ', array_map(fn($c) => "`$c` LIKE ?", $textCols)) . ')';
                foreach ($textCols as $c) $params[] = '%' . $q . '%';
            }
        }
        // Optional site filter — only for tables that have a site_number column.
        $siteFilter = $_GET['site'] ?? '';
        $hasSiteCol = isset($def['cols']['site_number']) || $def['pk'] === 'site_number';
        if ($hasSiteCol && $siteFilter !== '' && is_numeric($siteFilter)) {
            $siteCol = ($def['pk'] === 'site_number') ? 'site_number' : 'site_number';
            $conds[] = "`$siteCol` = ?";
            $params[] = (int)$siteFilter;
        }
        if ($conds) $where = ' WHERE ' . implode(' AND ', $conds);
        try {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM `$t`" . $where);
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();
            // Sorting: column must be in the allowlist (can't be parameterized, so
            // whitelist-validate against known columns to stay injection-safe).
            $sortable = array_merge([$def['pk']], array_keys($def['cols']));
            $sortCol = (string)($_GET['sort'] ?? $def['pk']);
            if (!in_array($sortCol, $sortable, true)) $sortCol = $def['pk'];
            $dir = strtolower((string)($_GET['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
            $st = $pdo->prepare("SELECT $colList FROM `$t`" . $where . " ORDER BY `$sortCol` $dir LIMIT $per OFFSET $off");
            $st->execute($params);
            jsonResponse(['success' => true, 'rows' => $st->fetchAll(), 'total' => $total, 'page' => $page, 'per' => $per]);
        } catch (\Throwable $e) {
            error_log("dataedit rows ($t) failed: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Could not load rows (table may not exist)'], 500);
        }
    }

    if ($action === 'update' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $t = (string)($in['table'] ?? '');
        if (!isset($SCHEMA[$t])) jsonResponse(['success' => false, 'error' => 'Unknown table'], 400);
        $def = $SCHEMA[$t];
        $pkVal = $in['pk_value'] ?? null;
        if ($pkVal === null || $pkVal === '') jsonResponse(['success' => false, 'error' => 'Missing row id'], 400);
        $vals = is_array($in['values'] ?? null) ? $in['values'] : [];
        $set = []; $params = [];
        foreach ($vals as $col => $val) {
            if (!isset($def['cols'][$col])) continue;
            $set[] = "`$col` = ?";
            $params[] = $coerce($def['cols'][$col], $val);
        }
        if (!$set) jsonResponse(['success' => false, 'error' => 'No editable fields provided'], 400);
        $params[] = $pkVal;
        try {
            $pdo->prepare("UPDATE `$t` SET " . implode(', ', $set) . " WHERE `{$def['pk']}` = ?")->execute($params);
            audit($pdo, 'dataedit.update', ['target_type' => 'table', 'target_label' => $t, 'details' => ['pk' => $pkVal, 'fields' => array_keys($vals)]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log("dataedit update ($t) failed: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Update failed — check the server log for details'], 500);
        }
    }

    if ($action === 'insert' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $t = (string)($in['table'] ?? '');
        if (!isset($SCHEMA[$t])) jsonResponse(['success' => false, 'error' => 'Unknown table'], 400);
        $def = $SCHEMA[$t];
        $vals = is_array($in['values'] ?? null) ? $in['values'] : [];
        $cols = []; $ph = []; $params = [];
        foreach ($vals as $col => $val) {
            if (!isset($def['cols'][$col])) continue;
            $cols[] = "`$col`"; $ph[] = '?';
            $params[] = $coerce($def['cols'][$col], $val);
        }
        if (!$cols) jsonResponse(['success' => false, 'error' => 'No fields to insert'], 400);
        try {
            $pdo->prepare("INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $ph) . ")")->execute($params);
            $newId = $pdo->lastInsertId();
            audit($pdo, 'dataedit.insert', ['target_type' => 'table', 'target_label' => $t, 'details' => ['new_id' => $newId]]);
            jsonResponse(['success' => true, 'new_id' => $newId]);
        } catch (\Throwable $e) {
            error_log("dataedit insert ($t) failed: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Insert failed — check the server log for details'], 500);
        }
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = jsonInput();
        $t = (string)($in['table'] ?? '');
        if (!isset($SCHEMA[$t])) jsonResponse(['success' => false, 'error' => 'Unknown table'], 400);
        $def = $SCHEMA[$t];
        $pkVal = $in['pk_value'] ?? null;
        if ($pkVal === null || $pkVal === '') jsonResponse(['success' => false, 'error' => 'Missing row id'], 400);
        try {
            $pdo->prepare("DELETE FROM `$t` WHERE `{$def['pk']}` = ?")->execute([$pkVal]);
            audit($pdo, 'dataedit.delete', ['target_type' => 'table', 'target_label' => $t, 'details' => ['pk' => $pkVal]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log("dataedit delete ($t) failed: " . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Delete failed — the row may be referenced elsewhere (see server log)'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown dataedit action'], 400);
}

// ================================================================
// OCCUPANT API  (people + extensions per room)
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'occupant') {
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        $roomId = filter_input(INPUT_GET, 'room', FILTER_VALIDATE_INT);
        if (!$roomId) jsonResponse(['error' => 'Missing room'], 400);
        try {
            $stmt = $pdo->prepare("SELECT * FROM room_occupant WHERE room_id = ? ORDER BY is_primary DESC, sort_order ASC, occupant_id ASC");
            $stmt->execute([$roomId]);
            jsonResponse(['success' => true, 'occupants' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            error_log('Occupant list failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to load occupants (run the migration?)'], 500);
        }
    }

    // Replace a room's entire occupant list in one call. Body: { room_id, occupants:
    // [ {name, role?, extension?, is_primary?} ] }. Simplest model for the editor —
    // the client sends the full list and we sync it. Only one primary is kept.
    if ($action === 'save_all' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $roomId = (int)($in['room_id'] ?? 0);
        $list = $in['occupants'] ?? null;
        if ($roomId <= 0 || !is_array($list)) jsonResponse(['success' => false, 'error' => 'room_id and occupants[] required'], 400);
        try {
            // Does the email column exist? (added by add_occupant_email.sql)
            $hasEmail = false;
            try { $pdo->query("SELECT email FROM room_occupant LIMIT 1"); $hasEmail = true; } catch (\Throwable $e) {}
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM room_occupant WHERE room_id = ?")->execute([$roomId]);
            if ($hasEmail) {
                $ins = $pdo->prepare("INSERT INTO room_occupant (room_id, name, role, extension, email, is_primary, sort_order)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)");
            } else {
                $ins = $pdo->prepare("INSERT INTO room_occupant (room_id, name, role, extension, is_primary, sort_order)
                                      VALUES (?, ?, ?, ?, ?, ?)");
            }
            $primaryUsed = false; $order = 0;
            foreach ($list as $oc) {
                if (!is_array($oc)) continue;
                $nm = trim((string)($oc['name'] ?? ''));
                if ($nm === '') continue;
                $isP = !empty($oc['is_primary']) && !$primaryUsed ? 1 : 0;
                if ($isP) $primaryUsed = true;
                $role = trim((string)($oc['role'] ?? '')) ?: null;
                $ext  = trim((string)($oc['extension'] ?? '')) ?: null;
                $em   = trim((string)($oc['email'] ?? '')) ?: null;
                if ($hasEmail) {
                    $ins->execute([$roomId, $nm, $role, $ext, $em, $isP, $order++]);
                } else {
                    $ins->execute([$roomId, $nm, $role, $ext, $isP, $order++]);
                }
            }
            $pdo->commit();
            $oc = $pdo->prepare("SELECT * FROM room_occupant WHERE room_id = ? ORDER BY is_primary DESC, sort_order ASC, occupant_id ASC");
            $oc->execute([$roomId]);
            $finalOcc = $oc->fetchAll();
            audit($pdo, 'people.update', ['target_type' => 'room', 'target_label' => 'room #' . $roomId, 'details' => ['room_id' => $roomId, 'people_count' => count($finalOcc)]]);
            jsonResponse(['success' => true, 'occupants' => $finalOcc]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Occupant save_all failed: ' . $e->getMessage());
            $msg = $e->getMessage();
            // Most likely cause: the room_occupant table doesn't exist yet.
            if (stripos($msg, "room_occupant") !== false || stripos($msg, "doesn't exist") !== false || stripos($msg, 'base table') !== false) {
                jsonResponse(['success' => false, 'error' => "The people feature needs a database update — run the latest migration.sql, then try again."], 500);
            }
            jsonResponse(['success' => false, 'error' => 'Failed to save people: ' . $msg], 500);
        }
    }

    // Bulk import people. Body: { rows: [ { name, role?, extension?, email?,
    // site_number, room_action: 'match'|'create'|'skip', room_id?, room_number? } ] }
    // - 'match'  → append the person to the existing room_id
    // - 'create' → create a room (room_number at site_number), then append
    // - 'skip'   → ignore the row
    // People are APPENDED (not replacing the room's existing occupants).
    if ($action === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'base', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $rows = is_array($in['rows'] ?? null) ? $in['rows'] : [];
        if (!$rows) jsonResponse(['success' => false, 'error' => 'No rows to import'], 400);
        if (count($rows) > 5000) jsonResponse(['success' => false, 'error' => 'Too many rows in one import'], 400);

        $hasEmail = false;
        try { $pdo->query("SELECT email FROM room_occupant LIMIT 1"); $hasEmail = true; } catch (\Throwable $e) {}
        // Does room have a room_number column? (it should)
        $roomCols = [];
        try { foreach ($pdo->query("SHOW COLUMNS FROM room")->fetchAll() as $c) $roomCols[] = $c['Field']; } catch (\Throwable $e) {}
        $hasRoomNumber = in_array('room_number', $roomCols, true);

        $added = 0; $roomsCreated = 0; $skipped = 0; $failed = 0;
        // Cache the next sort_order per room so appended people stack in order.
        $nextOrder = [];
        $orderFor = function (int $rid) use (&$nextOrder, $pdo): int {
            if (!isset($nextOrder[$rid])) {
                try {
                    $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM room_occupant WHERE room_id = ?");
                    $st->execute([$rid]);
                    $nextOrder[$rid] = (int)$st->fetchColumn();
                } catch (\Throwable $e) { $nextOrder[$rid] = 0; }
            }
            return $nextOrder[$rid]++;
        };
        try {
            $pdo->beginTransaction();
            // Prepared inserts.
            if ($hasEmail) {
                $insOcc = $pdo->prepare("INSERT INTO room_occupant (room_id, name, role, extension, email, is_primary, sort_order) VALUES (?,?,?,?,?,0,?)");
            } else {
                $insOcc = $pdo->prepare("INSERT INTO room_occupant (room_id, name, role, extension, is_primary, sort_order) VALUES (?,?,?,?,0,?)");
            }
            foreach ($rows as $r) {
                $name = trim((string)($r['name'] ?? ''));
                if ($name === '') { $skipped++; continue; }
                $act = (string)($r['room_action'] ?? 'skip');
                $site = (int)($r['site_number'] ?? 0);
                $roomId = (int)($r['room_id'] ?? 0);

                if ($act === 'skip') { $skipped++; continue; }

                if ($act === 'create') {
                    if (!$site) { $skipped++; continue; }
                    $roomNum = trim((string)($r['room_number'] ?? ''));
                    if ($roomNum === '') { $skipped++; continue; }
                    // A CSV value may be grouped, e.g. "C1-300A" = building "C1" + number
                    // "300A". Split only when it looks like <LETTER+DIGIT(S)>-<rest>.
                    $building = '';
                    if (preg_match('/^([A-Za-z]+[0-9]+)-(.+)$/', $roomNum, $mm)) {
                        $building = strtoupper($mm[1]);
                        $roomNum  = trim($mm[2]);
                    }
                    $roomName = 'Room ' . $roomNum;
                    // Reuse an existing room if one already matches — checking the
                    // grouped form (building+number), the raw number, OR the room
                    // name, since sites may not all be grouped yet.
                    $found = 0;
                    if ($hasRoomNumber) {
                        try {
                            $nk = static fn($v) => strtolower(preg_replace('/\s+/', '', trim((string)$v)));
                            $wantNum  = $nk($roomNum);
                            $wantName = $nk($roomName);
                            $wantGrp  = $building !== '' ? $nk($building . '-' . $roomNum) : '';
                            $cand = $pdo->prepare("SELECT room_id, room_number, room_name, building FROM room WHERE site_number = ?");
                            $cand->execute([$site]);
                            foreach ($cand->fetchAll() as $rr) {
                                $rNum  = $nk($rr['room_number'] ?? '');
                                $rName = $nk($rr['room_name'] ?? '');
                                $rBld  = trim((string)($rr['building'] ?? ''));
                                $rGrp  = ($rBld !== '' && ($rr['room_number'] ?? '') !== '') ? $nk($rBld . '-' . $rr['room_number']) : '';
                                if (($wantNum !== '' && ($wantNum === $rNum || $wantNum === $rName || $wantNum === $rGrp))
                                    || ($wantGrp !== '' && ($wantGrp === $rGrp || $wantGrp === $rNum))
                                    || ($wantName !== '' && $wantName === $rName)) {
                                    $found = (int)$rr['room_id'];
                                    break;
                                }
                            }
                        } catch (\Throwable $e) {}
                    }
                    if ($found) {
                        $roomId = $found;
                    } else {
                        // Create the room (with building if we parsed one, so it's grouped).
                        $hasBuildingCol = in_array('building', $roomCols, true);
                        try {
                            if ($hasRoomNumber && $hasBuildingCol && $building !== '') {
                                $cr = $pdo->prepare("INSERT INTO room (site_number, room_name, room_number, building, is_active) VALUES (?,?,?,?,1)");
                                $cr->execute([$site, $roomName, $roomNum, $building]);
                            } elseif ($hasRoomNumber) {
                                $cr = $pdo->prepare("INSERT INTO room (site_number, room_name, room_number, is_active) VALUES (?,?,?,1)");
                                $cr->execute([$site, $roomName, $roomNum]);
                            } else {
                                $cr = $pdo->prepare("INSERT INTO room (site_number, room_name, is_active) VALUES (?,?,1)");
                                $cr->execute([$site, $roomName]);
                            }
                            $roomId = (int)$pdo->lastInsertId();
                            $roomsCreated++;
                        } catch (\Throwable $e) { $failed++; continue; }
                    }
                }

                if ($act === 'match' || $act === 'create') {
                    if (!$roomId) { $skipped++; continue; }
                    $role = trim((string)($r['role'] ?? '')) ?: null;
                    $ext  = trim((string)($r['extension'] ?? '')) ?: null;
                    $em   = trim((string)($r['email'] ?? '')) ?: null;
                    try {
                        if ($hasEmail) {
                            $insOcc->execute([$roomId, substr($name,0,150), $role, $ext, $em ? substr($em,0,160) : null, $orderFor($roomId)]);
                        } else {
                            $insOcc->execute([$roomId, substr($name,0,150), $role, $ext, $orderFor($roomId)]);
                        }
                        $added++;
                    } catch (\Throwable $e) { $failed++; }
                } else {
                    $skipped++;
                }
            }
            $pdo->commit();
            audit($pdo, 'people.import', ['target_type' => 'setting', 'target_label' => 'people', 'details' => ['added' => $added, 'rooms_created' => $roomsCreated, 'skipped' => $skipped, 'failed' => $failed]]);
            jsonResponse(['success' => true, 'added' => $added, 'rooms_created' => $roomsCreated, 'skipped' => $skipped, 'failed' => $failed]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('People import failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Import failed — check the server log for details'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown occupant action'], 400);
}

// ================================================================
// DEVICE API
// ================================================================
if (isset($_GET['api']) && $_GET['api'] === 'device') {
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        $roomId = filter_input(INPUT_GET, 'room', FILTER_VALIDATE_INT);
        if (!$roomId) jsonResponse(['error' => 'Missing room'], 400);
        try {
            $stmt = $pdo->prepare("SELECT * FROM device WHERE room_id = ? ORDER BY device_name ASC");
            $stmt->execute([$roomId]);
            jsonResponse(['success' => true, 'devices' => $stmt->fetchAll()]);
        } catch (\Throwable $e) {
            error_log('Device list failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to load devices'], 500);
        }
    }

    if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'devices', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $roomId = (int)($in['room_id'] ?? 0);
        $devId  = isset($in['device_id']) ? (int)$in['device_id'] : 0;
        $devWasUpdate = ($devId > 0);
        $name   = trim((string)($in['device_name'] ?? ''));
        $type   = preg_replace('/[^a-z0-9_\-]/i', '', (string)($in['device_type_key'] ?? '')) ?: 'other';
        if ($roomId <= 0 || $name === '') jsonResponse(['success' => false, 'error' => 'Room and name required'], 400);

        $params = [
            'room_id'         => $roomId,
            'device_type_key' => $type,
            'device_name'     => $name,
            'asset_tag'       => trim((string)($in['asset_tag'] ?? '')) ?: null,
            'model'           => trim((string)($in['model'] ?? '')) ?: null,
            'serial_number'   => trim((string)($in['serial_number'] ?? '')) ?: null,
            'ip_address'      => trim((string)($in['ip_address'] ?? '')) ?: null,
            'status'          => trim((string)($in['status'] ?? 'active')) ?: 'active',
            'notes'           => trim((string)($in['notes'] ?? '')) ?: null,
            'pos_x'           => isset($in['pos_x']) && $in['pos_x'] !== null && $in['pos_x'] !== '' ? max(0.0, min(100.0, (float)$in['pos_x'])) : null,
            'pos_y'           => isset($in['pos_y']) && $in['pos_y'] !== null && $in['pos_y'] !== '' ? max(0.0, min(100.0, (float)$in['pos_y'])) : null,
        ];

        try {
            if ($devId > 0) {
                $sql = "UPDATE device SET
                            room_id=:room_id, device_type_key=:device_type_key, device_name=:device_name,
                            asset_tag=:asset_tag, model=:model, serial_number=:serial_number,
                            ip_address=:ip_address, status=:status, notes=:notes,
                            pos_x=:pos_x, pos_y=:pos_y
                        WHERE device_id=:device_id";
                $params['device_id'] = $devId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
            } else {
                $sql = "INSERT INTO device (room_id, device_type_key, device_name, asset_tag, model,
                            serial_number, ip_address, status, notes, pos_x, pos_y)
                        VALUES (:room_id, :device_type_key, :device_name, :asset_tag, :model,
                            :serial_number, :ip_address, :status, :notes, :pos_x, :pos_y)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $devId = (int)$pdo->lastInsertId();
            }
            $r = $pdo->prepare("SELECT * FROM device WHERE device_id = ?");
            $r->execute([$devId]);
            $devRow = $r->fetch();
            audit($pdo, $devWasUpdate ? 'device.update' : 'device.create', ['target_type' => 'device', 'target_label' => ($devRow['asset_tag'] ?? $devRow['model'] ?? ('Device #' . $devId)), 'details' => ['device_id' => $devId]]);
            jsonResponse(['success' => true, 'device' => $devRow]);
        } catch (\Throwable $e) {
            error_log('Device save failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to save device'], 500);
        }
    }

    if ($action === 'save_positions' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'devices', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $positions = $in['positions'] ?? [];
        if (!is_array($positions)) jsonResponse(['success' => false, 'error' => 'Invalid payload'], 400);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE device SET pos_x = ?, pos_y = ? WHERE device_id = ?");
            $updated = 0;
            foreach ($positions as $p) {
                $id = isset($p['device_id']) ? (int)$p['device_id'] : 0;
                if ($id <= 0) continue;
                $x = isset($p['pos_x']) && $p['pos_x'] !== null && $p['pos_x'] !== '' ? max(0.0, min(100.0, (float)$p['pos_x'])) : null;
                $y = isset($p['pos_y']) && $p['pos_y'] !== null && $p['pos_y'] !== '' ? max(0.0, min(100.0, (float)$p['pos_y'])) : null;
                $stmt->execute([$x, $y, $id]);
                $updated += $stmt->rowCount();
            }
            $pdo->commit();
            jsonResponse(['success' => true, 'updated' => $updated]);
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Device positions save failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to save positions'], 500);
        }
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!can($pdo, 'devices', 'edit')) jsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        $in = jsonInput();
        $id = (int)($in['device_id'] ?? 0);
        if ($id <= 0) jsonResponse(['success' => false, 'error' => 'Missing device_id'], 400);
        try {
            $dn = $pdo->prepare("SELECT asset_tag, model, device_name FROM device WHERE device_id = ?");
            $dn->execute([$id]); $dinfo = $dn->fetch() ?: [];
            $stmt = $pdo->prepare("DELETE FROM device WHERE device_id = ?");
            $stmt->execute([$id]);
            audit($pdo, 'device.delete', ['target_type' => 'device', 'target_label' => ($dinfo['asset_tag'] ?? $dinfo['device_name'] ?? $dinfo['model'] ?? ('Device #' . $id)), 'details' => ['device_id' => $id]]);
            jsonResponse(['success' => true]);
        } catch (\Throwable $e) {
            error_log('Device delete failed: ' . $e->getMessage());
            jsonResponse(['success' => false, 'error' => 'Failed to delete'], 500);
        }
    }

    jsonResponse(['error' => 'Unknown device action'], 400);
}

