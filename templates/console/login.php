<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $email
 * @var string|null $error
 */
$error = $error ?? null;
?>
<div class="auth-wrap">
  <img class="auth-logo" src="/assets/brand/lockup-large.png" alt="ADWOL Investment &amp; Services Ltd.">
  <div class="auth-card">
    <h1>Sign in</h1>
    <?php if ($error): ?>
      <div class="flash flash-error"><?= $this->e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/console/login">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <label>Email
        <input type="email" name="email" value="<?= $this->e($email) ?>" autocomplete="username" required autofocus>
      </label>
      <label>Password
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit">Sign in</button>
    </form>
    <?php if (!empty($mail_enabled)): ?>
      <p class="auth-alt"><a href="/console/forgot-password">Forgot your password?</a></p>
    <?php endif; ?>
  </div>
</div>
