<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $token
 * @var string|null $error
 */
use App\Users\UserAdminService;
?>
<div class="auth-wrap">
  <img class="auth-logo" src="/assets/brand/lockup-large.png" alt="ADWOL Investment &amp; Services Ltd.">
  <div class="auth-card">
    <h1>Choose a new password</h1>
    <?php if ($error): ?>
      <div class="flash flash-error"><?= $this->e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/console/reset-password">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="token" value="<?= $this->e($token) ?>">
      <label>New password <small>at least <?= (int) UserAdminService::MIN_PASSWORD_LENGTH ?> characters</small>
        <input type="password" name="password" autocomplete="new-password" required minlength="<?= (int) UserAdminService::MIN_PASSWORD_LENGTH ?>" autofocus>
      </label>
      <label>Confirm new password
        <input type="password" name="password_confirm" autocomplete="new-password" required minlength="<?= (int) UserAdminService::MIN_PASSWORD_LENGTH ?>">
      </label>
      <button type="submit">Set password</button>
    </form>
  </div>
</div>
