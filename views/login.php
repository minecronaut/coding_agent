<?php /** @var string $error */ /** @var string $mode */ /** @var string $usernameValue */ /** @var string $displayValue */ ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(APP_NAME) ?> — an AI tutor for Python</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%235eead4'/><path d='M9 22l4-12 5 8 3-5 3 9' stroke='%23071a18' stroke-width='2.4' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<div class="auth-wrap">
  <section class="auth-art">
    <div>
      <div class="brand">
        <span class="brand-mark">C</span>
        <span><?= h(APP_NAME) ?></span>
      </div>

      <div style="margin-top:clamp(40px, 9vh, 110px)">
        <h1>One tutor. Your Python. Nothing generic.</h1>
        <p class="lede">
          Cairn works out where you actually are, then builds the course around you —
          the lessons, the exercises, the projects, the explanations. No two people
          take the same path.
        </p>

        <div class="auth-points">
          <div class="auth-point">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 12 5 5L20 6"/></svg>
            <span>Lessons written for you, not for a class of thirty.</span>
          </div>
          <div class="auth-point">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="m8 6-5 6 5 6M16 6l5 6-5 6"/></svg>
            <span>Real Python that runs in your browser — including Turtle graphics.</span>
          </div>
          <div class="auth-point">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a8 8 0 0 1-8 8H7l-4 3v-6.5A8 8 0 1 1 21 12Z"/></svg>
            <span>Stuck at 2am? Your tutor knows exactly what you've covered.</span>
          </div>
          <div class="auth-point">
            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 4 14h6l-1 8 9-12h-6l1-8Z"/></svg>
            <span>Bring your own model — NVIDIA NIM, OpenAI, Groq, Claude, local Ollama.</span>
          </div>
        </div>
      </div>
    </div>

    <div style="font-size:12px;color:var(--dim);position:relative;z-index:1">
      Your code runs on your machine. Only lessons and chat go to your chosen model provider.
    </div>
  </section>

  <section class="auth-form-side">
    <div class="auth-card">
      <h2 style="margin:0 0 6px;font-size:22px;letter-spacing:-.02em">
        <?= $mode === 'register' ? 'Create your account' : 'Welcome back' ?>
      </h2>
      <p style="margin:0;color:var(--muted);font-size:14px">
        <?= $mode === 'register' ? 'It takes about ten seconds.' : 'Pick up where you left off.' ?>
      </p>

      <?php if ($error !== ''): ?>
        <div style="margin-top:16px;padding:11px 14px;border-radius:10px;background:rgba(255,125,125,.08);border:1px solid rgba(255,125,125,.3);color:#ff9d9d;font-size:13.5px">
          <?= h($error) ?>
        </div>
      <?php endif; ?>

      <div class="auth-tabs">
        <button type="button" class="<?= $mode === 'login' ? 'active' : '' ?>" data-tab="login">Sign in</button>
        <button type="button" class="<?= $mode === 'register' ? 'active' : '' ?>" data-tab="register">Create account</button>
      </div>

      <form method="post" action="" data-form="login" class="<?= $mode === 'login' ? '' : 'hidden' ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <div class="field">
          <label for="login-user">Username</label>
          <input id="login-user" name="username" type="text" autocomplete="username" required value="<?= h($usernameValue) ?>">
        </div>
        <div class="field">
          <label for="login-pass">Password</label>
          <input id="login-pass" name="password" type="password" autocomplete="current-password" required>
        </div>
        <button class="btn btn-primary btn-block" type="submit" style="margin-top:8px">Sign in</button>
      </form>

      <form method="post" action="" data-form="register" class="<?= $mode === 'register' ? '' : 'hidden' ?>">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="register">
        <div class="field">
          <label for="reg-name">What should we call you?</label>
          <input id="reg-name" name="display" type="text" maxlength="40" placeholder="Optional" value="<?= h($displayValue) ?>">
        </div>
        <div class="field">
          <label for="reg-user">Username</label>
          <input id="reg-user" name="username" type="text" autocomplete="username" required pattern="[A-Za-z0-9_]{3,24}" title="3–24 letters, numbers or underscores">
        </div>
        <div class="field">
          <label for="reg-pass">Password</label>
          <input id="reg-pass" name="password" type="password" autocomplete="new-password" required minlength="8">
          <div class="hint">At least 8 characters.</div>
        </div>
        <div class="field">
          <label for="reg-pass2">Confirm password</label>
          <input id="reg-pass2" name="password2" type="password" autocomplete="new-password" required minlength="8">
        </div>
        <button class="btn btn-primary btn-block" type="submit" style="margin-top:8px">Create account</button>
      </form>

      <p style="margin-top:20px;font-size:12px;color:var(--dim);text-align:center">
        Accounts live on this server as plain JSON files.
      </p>
    </div>
  </section>
</div>

<script>
  document.querySelectorAll('[data-tab]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tab = btn.getAttribute('data-tab');
      document.querySelectorAll('[data-tab]').forEach(function (b) {
        b.classList.toggle('active', b === btn);
      });
      document.querySelectorAll('[data-form]').forEach(function (form) {
        form.classList.toggle('hidden', form.getAttribute('data-form') !== tab);
      });
      var heading = document.querySelector('.auth-card h2');
      var sub = document.querySelector('.auth-card p');
      if (tab === 'register') {
        heading.textContent = 'Create your account';
        sub.textContent = 'It takes about ten seconds.';
      } else {
        heading.textContent = 'Welcome back';
        sub.textContent = 'Pick up where you left off.';
      }
      var first = document.querySelector('[data-form="' + tab + '"] input:not([type=hidden])');
      if (first) first.focus();
    });
  });
</script>
</body>
</html>
