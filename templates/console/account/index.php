<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array $current_user
 * @var bool $twoFactorOn
 * @var int $recoveryLeft
 * @var bool $twoFactorRequired
 * @var bool $mustEnrol
 * @var string|null $passwordError
 */
use App\Users\UserAdminService;
?>
<div class="page-head">
  <h1>My account</h1>
</div>

<dl class="detail">
  <dt>Name</dt><dd><?= $this->e($current_user['full_name']) ?></dd>
  <dt>Email</dt><dd><?= $this->e($current_user['email']) ?></dd>
  <dt>Role</dt><dd><?= $this->e(ucfirst(str_replace('_', ' ', (string) $current_user['role']))) ?></dd>
</dl>

<?php if ($mustEnrol): ?>
  <div class="flash flash-error">Your administrator requires two-factor authentication for your role. Set it up below to continue using the console.</div>
<?php endif; ?>

<section class="actions">
  <h2>Two-factor authentication
    <span class="badge badge-<?= $twoFactorOn ? 'completed' : 'pending' ?>"><?= $twoFactorOn ? 'on' : 'off' ?></span>
  </h2>

  <?php if (!$twoFactorOn): ?>
    <p class="hint">Adds a second step at sign-in: a 6-digit code from an authenticator app on your phone (Google Authenticator,
      Microsoft Authenticator, Authy, 1Password…). Even if your password leaks, nobody can sign in without your phone.</p>
    <form method="post" action="/console/account/two-factor/start">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <button type="submit">Set up two-factor</button>
    </form>
  <?php else: ?>
    <p class="hint">Sign-in asks for a code from your authenticator app. You have <strong><?= $this->e($recoveryLeft) ?></strong> unused recovery
      code<?= $recoveryLeft === 1 ? '' : 's' ?><?= $recoveryLeft <= 2 ? ' — generate a fresh set soon' : '' ?>.</p>

    <form method="post" action="/console/account/two-factor/recovery-codes" class="record-form"
          data-confirm="Generate new recovery codes? The old ones stop working.">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <label>Current password <small>needed to make new recovery codes</small>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button type="submit" class="btn-outline">Generate new recovery codes</button>
    </form>

    <?php if ($twoFactorRequired): ?>
      <p class="hint">Two-factor is required for your role, so it can't be turned off.</p>
    <?php else: ?>
      <form method="post" action="/console/account/two-factor/disable" class="record-form"
            data-confirm="Turn two-factor off? Your account will be protected by your password alone.">
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
        <label>Current password <small>needed to turn two-factor off</small>
          <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="btn-danger">Turn off two-factor</button>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<section class="actions">
  <h2>Change password</h2>
  <?php if ($passwordError): ?><div class="flash flash-error"><?= $this->e($passwordError) ?></div><?php endif; ?>
  <form method="post" action="/console/account/password" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <label>Current password
      <input type="password" name="current_password" autocomplete="current-password" required>
    </label>
    <label>New password <small>at least <?= (int) UserAdminService::MIN_PASSWORD_LENGTH ?> characters</small>
      <input type="password" name="new_password" autocomplete="new-password" required minlength="<?= (int) UserAdminService::MIN_PASSWORD_LENGTH ?>">
    </label>
    <label>Confirm new password
      <input type="password" name="new_password_confirm" autocomplete="new-password" required minlength="<?= (int) UserAdminService::MIN_PASSWORD_LENGTH ?>">
    </label>
    <button type="submit" class="btn-outline">Change password</button>
    <p class="hint">Changing it signs you out of every other device.</p>
  </form>
</section>
