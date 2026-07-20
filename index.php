<?php
// ============================================================
// Site Manager v0.28 — entry point
// A single-artifact PHP app, split into purposeful modules. The
// require order below preserves the exact execution order of the
// original single-file build — do not reorder.
//   inc/bootstrap.php   core utils, session, CSRF, security
//   inc/helpers.php     permissions, mail, TOTP, password policy
//   inc/views/login.php the sign-in page renderer
//   inc/db.php          database connection + current user
//   inc/auth.php        auth actions + signed-in gate (exits here if not)
//   inc/api.php         every ?api= JSON endpoint
//   inc/view_data.php   payload prep for the app shell
//   inc/views/app.php   the application HTML shell
// Static assets live in assets/ and are cache-busted by APP_VERSION.
// ============================================================
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/helpers.php';
require __DIR__ . '/inc/views/login.php';
require __DIR__ . '/inc/db.php';
require __DIR__ . '/inc/auth.php';
require __DIR__ . '/inc/api.php';
require __DIR__ . '/inc/view_data.php';
require __DIR__ . '/inc/views/app.php';
