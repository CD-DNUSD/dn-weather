<?php
// ============================================================
// Site Manager — login.php
// render_login_page(): the self-contained sign-in page.
// Split from the original single-file build in v0.28; load order
// is preserved exactly by the require sequence in index.php.
// ============================================================

function render_login_page(): void {
    global $pdo;
    $brandName = 'Site Manager';
    $loginLogoSrc = '';   // image URL to show, or '' for the fallback icon
    try {
        $brandName = (string)setting_get($pdo, 'site_brand_name', 'Site Manager');
        if ($brandName === '') $brandName = 'Site Manager';
        $rel = (string)setting_get($pdo, 'site_logo_path', '');
        if ($rel !== '' && is_file(APP_ROOT . '/' . ltrim($rel, '/'))) {
            $loginLogoSrc = '?api=image&action=serve&kind=logo&v=' . (@filemtime(APP_ROOT . '/' . ltrim($rel, '/')) ?: time());
        }
    } catch (\Throwable $e) {}
    // Legacy fallback: resources.logoPath served as a static file.
    if ($loginLogoSrc === '') {
        try {
            $resRow = $pdo->query("SELECT logoPath FROM resources LIMIT 1")->fetch();
            if ($resRow && !empty($resRow['logoPath']) && file_exists($resRow['logoPath'])) {
                $loginLogoSrc = htmlspecialchars($resRow['logoPath'], ENT_QUOTES) . '?v=' . filemtime($resRow['logoPath']);
            }
        } catch (\Throwable $e) {}
    }
    $hasLogo = ($loginLogoSrc !== '');
    $brandSafe = htmlspecialchars($brandName, ENT_QUOTES);
    ?><!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>">
<title>Sign in · Site Manager</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0a0e14;--card:#121822;--input:#0d131c;--border:#1f2733;--border-h:#2d3848;--text:#e6edf3;--muted:#8b98a9;--dim:#5c6675;--accent:#3b82f6;--red:#ef4444}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',system-ui,sans-serif;background:
      radial-gradient(900px 500px at 15% -5%, rgba(59,130,246,.16), transparent 60%),
      radial-gradient(800px 500px at 100% 110%, rgba(139,92,246,.14), transparent 55%),
      var(--bg);
    color:var(--text);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
  .login-card{position:relative;width:100%;max-width:400px;background:linear-gradient(180deg, rgba(255,255,255,.02), transparent), var(--card);border:1px solid var(--border);border-radius:20px;padding:38px 34px;box-shadow:0 30px 80px rgba(0,0,0,.55), 0 1px 0 rgba(255,255,255,.04) inset;animation:cardIn .4s cubic-bezier(.2,.7,.2,1)}
  @keyframes cardIn{from{opacity:0;transform:translateY(10px) scale(.99)}to{opacity:1;transform:none}}
  .brand{display:flex;flex-direction:column;align-items:center;text-align:center;gap:14px;margin-bottom:28px}
  .brand .logo{width:72px;height:72px;border-radius:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;background:linear-gradient(135deg,var(--accent),#8b5cf6);box-shadow:0 10px 30px rgba(59,130,246,.3)}
  .brand .logo img{width:100%;height:100%;object-fit:contain;border-radius:18px}
  .brand .logo svg{width:38px;height:38px;stroke:#fff;fill:none;stroke-width:2}
  .brand h1{font-size:22px;font-weight:700;letter-spacing:-.02em}
  .brand p{font-size:13px;color:var(--muted);margin-top:2px}
  label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:14px 0 6px}
  input{width:100%;background:var(--input);border:1px solid var(--border);border-radius:11px;color:var(--text);padding:12px 14px;font-size:14px;font-family:inherit;outline:none;transition:border-color .15s, box-shadow .15s}
  input:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(59,130,246,.14)}
  .btn{width:100%;margin-top:22px;background:linear-gradient(135deg,var(--accent),#6366f1);color:#fff;border:none;border-radius:11px;padding:13px;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:filter .15s, transform .05s;box-shadow:0 8px 24px rgba(59,130,246,.28)}
  .btn:hover{filter:brightness(1.08)}
  .btn:active{transform:translateY(1px)}
  .btn:disabled{opacity:.6;cursor:default}
  .err{display:none;margin-top:14px;background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);color:#fca5a5;font-size:13px;font-weight:600;padding:10px 12px;border-radius:9px}
  .err.show{display:block}
  .ok{display:none;margin-top:14px;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.3);color:#86efac;font-size:13px;font-weight:600;padding:10px 12px;border-radius:9px}
  .ok.show{display:block}
  .hint{margin-top:18px;text-align:center;font-size:11px;color:var(--dim)}
  .inv-step{display:none}
  .inv-step.active{display:block}
  .qr-box{display:flex;justify-content:center;margin:14px 0;background:#fff;padding:12px;border-radius:10px}
  .qr-box img,.qr-box canvas{display:block}
  .secret-line{text-align:center;font-family:monospace;font-size:13px;color:var(--muted);word-break:break-all;margin:6px 0 12px}
  .inv-actions{display:flex;gap:10px}
  .inv-actions .btn{flex:1}
  .btn.ghost{background:transparent;border:1px solid var(--border);color:var(--muted);box-shadow:none}
</style>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
</head>
<body>
  <form class="login-card" id="loginForm" autocomplete="on">
    <div class="brand">
      <div class="logo"><?php if ($hasLogo): ?><img src="<?= $loginLogoSrc ?>" alt="Logo"><?php else: ?><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-3"/><path d="M9 9v.01M9 12v.01M9 15v.01M9 18v.01"/></svg><?php endif; ?></div>
      <div><h1><?= $brandSafe ?></h1><p>Sign in to continue</p></div>
    </div>
    <label for="u">Username</label>
    <input id="u" name="username" type="text" autocomplete="username" autofocus required>
    <label for="p">Password</label>
    <input id="p" name="password" type="password" autocomplete="current-password" required>
    <div class="err" id="err"></div>
    <button class="btn" id="submitBtn" type="submit">Sign in</button>
    <div class="hint"><a href="#" id="forgotLink" style="color:var(--accent);text-decoration:none">Forgot password?</a></div>
  </form>

  <!-- Forgot password: request a reset link -->
  <form class="login-card" id="forgotForm" style="display:none" autocomplete="on">
    <div class="brand">
      <div class="logo"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z" fill="none"/><path d="M22 6l-10 7L2 6"/></svg></div>
      <div><h1>Reset password</h1><p>We'll email you a reset link</p></div>
    </div>
    <label for="fIdent">Username or email</label>
    <input id="fIdent" type="text" autocomplete="username" placeholder="your username or email" autofocus>
    <div class="err" id="fErr"></div>
    <div class="ok" id="fOk"></div>
    <button class="btn" id="fBtn" type="submit">Send reset link</button>
    <div class="hint"><a href="#" id="backToLogin" style="color:var(--muted);text-decoration:none">← Back to sign in</a></div>
  </form>

  <!-- Reset password: shown when the emailed ?reset= link is opened -->
  <form class="login-card" id="resetForm" style="display:none" autocomplete="off">
    <div class="brand">
      <div class="logo"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
      <div><h1>Choose a new password</h1><p>Enter and confirm your new password</p></div>
    </div>
    <input type="text" id="rUser" name="username" autocomplete="username" aria-hidden="true" tabindex="-1" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px">
    <label for="rPass">New password</label>
    <input id="rPass" type="password" autocomplete="new-password" placeholder="at least 8 characters" autofocus>
    <label for="rPass2">Confirm password</label>
    <input id="rPass2" type="password" autocomplete="new-password" placeholder="re-enter password">
    <div class="err" id="rErr"></div>
    <div class="ok" id="rOk"></div>
    <button class="btn" id="rBtn" type="submit">Set new password</button>
    <div class="hint"><a href="#" id="resetBackToLogin" style="color:var(--muted);text-decoration:none">← Back to sign in</a></div>
  </form>

  <!-- Invite activation: shown when an emailed ?invite= link is opened -->
  <form class="login-card" id="inviteForm" style="display:none" autocomplete="off" onsubmit="return false">
    <div class="brand">
      <div class="logo"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></div>
      <div><h1>Welcome</h1><p id="invWelcome">Set up your account</p></div>
    </div>

    <!-- Hidden username so password managers can associate the new credential. -->
    <input type="text" id="invUser" name="username" autocomplete="username" aria-hidden="true" tabindex="-1" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;left:-9999px">

    <!-- Step 1: choose a password -->
    <div class="inv-step active" id="invStep1">
      <label for="invPass">Create a password</label>
      <input id="invPass" type="password" autocomplete="new-password" placeholder="at least 8 characters">
      <label for="invPass2">Confirm password</label>
      <input id="invPass2" type="password" autocomplete="new-password" placeholder="re-enter password">
      <div class="err" id="invErr"></div>
      <button class="btn" id="invStep1Btn" type="button">Continue</button>
    </div>

    <!-- Step 2: offer MFA -->
    <div class="inv-step" id="invStep2">
      <p style="font-size:14px;color:var(--muted);line-height:1.5;margin-bottom:16px">Add an extra layer of security with two-factor authentication (an app like Google Authenticator or Authy)? You can also do this later from your profile.</p>
      <div class="inv-actions">
        <button class="btn ghost" id="invSkipMfa" type="button">Skip for now</button>
        <button class="btn" id="invSetupMfa" type="button">Set up 2FA</button>
      </div>
    </div>

    <!-- Step 3: MFA enrollment -->
    <div class="inv-step" id="invStep3">
      <p style="font-size:13px;color:var(--muted);line-height:1.5;margin-bottom:4px">Scan this with your authenticator app, then enter the 6-digit code.</p>
      <div class="qr-box" id="invQr"></div>
      <div class="secret-line">Can't scan? Enter this key:<br><span id="invSecret"></span></div>
      <label for="invCode">6-digit code</label>
      <input id="invCode" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000">
      <div class="err" id="invMfaErr"></div>
      <div class="inv-actions">
        <button class="btn ghost" id="invMfaBack" type="button">Back</button>
        <button class="btn" id="invMfaVerify" type="button">Verify &amp; finish</button>
      </div>
    </div>

    <!-- Done -->
    <div class="inv-step" id="invStep4">
      <div class="ok show" style="text-align:center">Your account is ready. Redirecting to sign in…</div>
    </div>
  </form>

  <!-- MFA step (hidden until needed) -->
  <form class="login-card" id="mfaForm" style="display:none" autocomplete="off">
    <div class="brand">
      <div class="logo"><svg viewBox="0 0 24 24" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
      <div><h1>Two-factor</h1><p>Enter the code from your app</p></div>
    </div>
    <label for="mfaCode">Authentication code</label>
    <input id="mfaCode" type="text" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" maxlength="14" autofocus>
    <div class="err" id="mfaErr"></div>
    <button class="btn" id="mfaBtn" type="submit">Verify</button>
    <div class="hint">Lost your device? Enter a backup code, or ask an admin to reset MFA.</div>
  </form>

  <script>
    // Attach the per-session CSRF token to same-origin requests automatically so
    // the login/reset/invite POSTs pass the server's CSRF check without each call
    // having to remember the header.
    (function(){
      const meta = document.querySelector('meta[name="csrf-token"]');
      let token = meta ? meta.getAttribute('content') : '';
      const orig = window.fetch;
      window.fetch = async function(input, init){
        init = init || {};
        let url = (typeof input === 'string') ? input : (input && input.url) || '';
        const sameOrigin = !/^[a-z]+:\/\//i.test(url) || url.indexOf(location.origin) === 0;
        if (!sameOrigin) return orig.call(this, input, init);
        const send = () => {
          const h = new Headers((init && init.headers) || (typeof input !== 'string' && input && input.headers) || {});
          if (token) h.set('X-CSRF-Token', token);
          return orig.call(window, input, Object.assign({}, init, { headers: h }));
        };
        let res = await send();
        // The token baked into this page can go stale if the session expired
        // and restarted while the page sat open. The server's rejection now
        // carries the current token — adopt it and retry once, so signing in
        // "just works" instead of failing with a message telling the human to
        // reload. (Request bodies here are plain JSON strings, so re-sending
        // is safe.)
        if (res.status === 403 && !init._csrfRetried){
          try {
            const peek = await res.clone().json();
            if (peek && peek.error_code === 'csrf' && peek.fresh_token){
              token = peek.fresh_token;
              init._csrfRetried = true;
              res = await send();
            }
          } catch(e){ /* not JSON — fall through with the original response */ }
        }
        return res;
      };
    })();
    const form = document.getElementById('loginForm');
    const err = document.getElementById('err');
    const btn = document.getElementById('submitBtn');
    const mfaForm = document.getElementById('mfaForm');
    const mfaErr = document.getElementById('mfaErr');
    const mfaBtn = document.getElementById('mfaBtn');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      err.classList.remove('show');
      btn.disabled = true; btn.textContent = 'Signing in…';
      try {
        const res = await fetch('?api=auth&action=login', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ username: document.getElementById('u').value, password: document.getElementById('p').value })
        });
        const data = await res.json();
        if (data.success && data.mfa_required) {
          // switch to the code step
          form.style.display = 'none';
          mfaForm.style.display = '';
          setTimeout(() => document.getElementById('mfaCode').focus(), 50);
          return;
        }
        if (data.success) { window.location = window.location.pathname; return; }
        err.textContent = data.error || 'Sign in failed'; err.classList.add('show');
      } catch (e) {
        err.textContent = 'Network error — please try again.'; err.classList.add('show');
      }
      btn.disabled = false; btn.textContent = 'Sign in';
    });

    mfaForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      mfaErr.classList.remove('show');
      mfaBtn.disabled = true; mfaBtn.textContent = 'Verifying…';
      try {
        const res = await fetch('?api=auth&action=mfa_login', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ code: document.getElementById('mfaCode').value })
        });
        const data = await res.json();
        if (data.success) { window.location = window.location.pathname; return; }
        mfaErr.textContent = data.error || 'Verification failed'; mfaErr.classList.add('show');
      } catch (e) {
        mfaErr.textContent = 'Network error — please try again.'; mfaErr.classList.add('show');
      }
      mfaBtn.disabled = false; mfaBtn.textContent = 'Verify';
    });

    // ---- Forgot / reset password ----
    const loginCard = form, forgotForm = document.getElementById('forgotForm'), resetForm = document.getElementById('resetForm'), inviteForm = document.getElementById('inviteForm');
    const show = (el) => { [loginCard, mfaForm, forgotForm, resetForm, inviteForm].forEach(f => f.style.display = 'none'); el.style.display = ''; };

    document.getElementById('forgotLink').addEventListener('click', (e) => { e.preventDefault(); show(forgotForm); setTimeout(() => document.getElementById('fIdent').focus(), 50); });
    document.getElementById('backToLogin').addEventListener('click', (e) => { e.preventDefault(); show(loginCard); });
    document.getElementById('resetBackToLogin').addEventListener('click', (e) => { e.preventDefault(); history.replaceState(null, '', window.location.pathname); show(loginCard); });

    const fBtn = document.getElementById('fBtn'), fErr = document.getElementById('fErr'), fOk = document.getElementById('fOk');
    forgotForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      fErr.classList.remove('show'); fOk.classList.remove('show');
      fBtn.disabled = true; fBtn.textContent = 'Sending…';
      try {
        const res = await fetch('?api=auth&action=forgot', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ identifier: document.getElementById('fIdent').value })
        });
        const data = await res.json();
        // Always a generic success (no account enumeration).
        fOk.textContent = data.message || 'If that account exists, a reset link is on its way.';
        fOk.classList.add('show');
      } catch (e2) { fErr.textContent = 'Network error — please try again.'; fErr.classList.add('show'); }
      fBtn.disabled = false; fBtn.textContent = 'Send reset link';
    });

    const rBtn = document.getElementById('rBtn'), rErr = document.getElementById('rErr'), rOk = document.getElementById('rOk');
    let resetToken = '';
    resetForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      rErr.classList.remove('show'); rOk.classList.remove('show');
      const p1 = document.getElementById('rPass').value, p2 = document.getElementById('rPass2').value;
      if (p1.length < 8) { rErr.textContent = 'Password must be at least 8 characters.'; rErr.classList.add('show'); return; }
      if (p1 !== p2)     { rErr.textContent = 'The two passwords do not match.'; rErr.classList.add('show'); return; }
      rBtn.disabled = true; rBtn.textContent = 'Saving…';
      try {
        const res = await fetch('?api=auth&action=reset', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ token: resetToken, new_password: p1 })
        });
        const data = await res.json();
        if (data.success) {
          rOk.textContent = 'Password updated. You can sign in now.'; rOk.classList.add('show');
          setTimeout(() => { history.replaceState(null, '', window.location.pathname); show(loginCard); }, 1600);
        } else { rErr.textContent = data.error || 'Could not reset password.'; rErr.classList.add('show'); }
      } catch (e2) { rErr.textContent = 'Network error — please try again.'; rErr.classList.add('show'); }
      rBtn.disabled = false; rBtn.textContent = 'Set new password';
    });

    // ---- Invite activation (multi-step: password -> optional MFA -> done) ----
    let inviteToken = '';
    const invSteps = ['invStep1','invStep2','invStep3','invStep4'].map(id => document.getElementById(id));
    const invShowStep = (n) => invSteps.forEach((s, i) => s.classList.toggle('active', i === (n - 1)));
    const invErr = document.getElementById('invErr'), invMfaErr = document.getElementById('invMfaErr');

    // Step 1: set password
    document.getElementById('invStep1Btn').addEventListener('click', async () => {
      invErr.classList.remove('show');
      const p1 = document.getElementById('invPass').value, p2 = document.getElementById('invPass2').value;
      if (p1.length < 8) { invErr.textContent = 'Password must be at least 8 characters.'; invErr.classList.add('show'); return; }
      if (p1 !== p2)     { invErr.textContent = 'The two passwords do not match.'; invErr.classList.add('show'); return; }
      const b = document.getElementById('invStep1Btn'); b.disabled = true; b.textContent = 'Saving…';
      try {
        const res = await fetch('?api=auth&action=invite_activate', {
          method:'POST', headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ token: inviteToken, new_password: p1 })
        });
        const data = await res.json();
        if (data.success) { invShowStep(2); }
        else { invErr.textContent = data.error || 'Could not set password.'; invErr.classList.add('show'); }
      } catch (e2) { invErr.textContent = 'Network error — please try again.'; invErr.classList.add('show'); }
      b.disabled = false; b.textContent = 'Continue';
    });

    // Step 2: skip MFA -> finish
    document.getElementById('invSkipMfa').addEventListener('click', async () => {
      try { await fetch('?api=auth&action=invite_finish', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: inviteToken }) }); } catch (e) {}
      invDone();
    });

    // Step 2: set up MFA -> fetch secret + render QR
    document.getElementById('invSetupMfa').addEventListener('click', async () => {
      const b = document.getElementById('invSetupMfa'); b.disabled = true; b.textContent = 'Loading…';
      try {
        const res = await fetch('?api=auth&action=invite_mfa_begin', {
          method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: inviteToken })
        });
        const data = await res.json();
        if (data.success) {
          document.getElementById('invSecret').textContent = data.secret;
          const box = document.getElementById('invQr'); box.innerHTML = '';
          try { const qr = qrcode(0, 'M'); qr.addData(data.otpauth); qr.make(); box.innerHTML = qr.createImgTag(5, 8); }
          catch (e) { box.textContent = 'QR unavailable — use the key below.'; }
          invShowStep(3);
          setTimeout(() => document.getElementById('invCode').focus(), 50);
        } else { alert(data.error || 'Could not start 2FA setup'); }
      } catch (e2) { alert('Network error'); }
      b.disabled = false; b.textContent = 'Set up 2FA';
    });

    // Step 3: verify code
    document.getElementById('invMfaBack').addEventListener('click', () => invShowStep(2));
    document.getElementById('invMfaVerify').addEventListener('click', async () => {
      invMfaErr.classList.remove('show');
      const code = document.getElementById('invCode').value.replace(/\D/g, '');
      if (code.length < 6) { invMfaErr.textContent = 'Enter the 6-digit code.'; invMfaErr.classList.add('show'); return; }
      const b = document.getElementById('invMfaVerify'); b.disabled = true; b.textContent = 'Verifying…';
      try {
        const res = await fetch('?api=auth&action=invite_mfa_verify', {
          method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ token: inviteToken, code })
        });
        const data = await res.json();
        if (data.success) { invDone(); }
        else { invMfaErr.textContent = data.error || 'That code is incorrect.'; invMfaErr.classList.add('show'); }
      } catch (e2) { invMfaErr.textContent = 'Network error — please try again.'; invMfaErr.classList.add('show'); }
      b.disabled = false; b.textContent = 'Verify & finish';
    });

    function invDone() {
      invShowStep(4);
      setTimeout(() => { history.replaceState(null, '', window.location.pathname); show(loginCard); }, 1800);
    }

    // If we arrived via an emailed reset OR invite link, open the right card.
    (async function () {
      const inv = window.location.search.match(/[?&]invite=([a-f0-9]+)/i);
      if (inv) {
        inviteToken = inv[1];
        // Validate the token and greet by name before showing the form.
        try {
          const res = await fetch('?api=auth&action=invite_info&token=' + encodeURIComponent(inviteToken));
          const data = await res.json();
          if (data.success) {
            document.getElementById('invWelcome').textContent = 'Hi ' + data.username + ', let\u2019s finish your account';
            const iu = document.getElementById('invUser'); if (iu) iu.value = data.username || '';
            show(inviteForm); invShowStep(1);
            setTimeout(() => document.getElementById('invPass').focus(), 50);
          } else {
            err.textContent = data.error || 'This invitation is invalid or has expired.'; err.classList.add('show');
            history.replaceState(null, '', window.location.pathname);
          }
        } catch (e) { err.textContent = 'Could not validate the invitation.'; err.classList.add('show'); }
        return;
      }
      const m = window.location.search.match(/[?&]reset=([a-f0-9]+)/i);
      if (m) { resetToken = m[1]; show(resetForm); setTimeout(() => document.getElementById('rPass').focus(), 50); }
    })();
  </script>
</body>
</html><?php
}
