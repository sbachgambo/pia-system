<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array{name:string,address:string,representative_title:string,updated_at:?string,updated_by_name:?string} $company
 * @var array{compliance_grace_hours:int|string,updated_at:?string,updated_by_name:?string} $operational
 * @var array<string,string> $errors
 * @var array<string,string> $opErrors
 */
$errors = $errors ?? [];
$opErrors = $opErrors ?? [];
$val = static fn (string $k): string => htmlspecialchars((string) ($company[$k] ?? ''), ENT_QUOTES);
$err = static fn (string $k): string => isset($errors[$k])
    ? '<span class="field-error">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</span>' : '';
$opErr = static fn (string $k): string => isset($opErrors[$k])
    ? '<span class="field-error">' . htmlspecialchars($opErrors[$k], ENT_QUOTES) . '</span>' : '';
?>
<div class="page-head">
  <h1>Settings</h1>
</div>

<section class="actions">
  <h2>Administration</h2>
  <div class="quick-links">
    <a class="btn-outline btn" href="/console/users">Manage users</a>
    <a class="btn-outline btn" href="/console/audit-log">Audit log</a>
  </div>
</section>

<section class="actions">
  <h2>Document letterhead <small>printed on every generated CCI / NNCI</small></h2>

  <?php if (!empty($company['updated_at'])): ?>
    <p class="hint">Last changed <?= $this->dt($company['updated_at'], true) ?><?= $company['updated_by_name'] ? ' by ' . $this->e($company['updated_by_name']) : '' ?>.</p>
  <?php else: ?>
    <p class="hint">Showing the installation default — nobody has changed this yet.</p>
  <?php endif; ?>

  <form method="post" action="/console/settings" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

    <label>Company name
      <input name="name" value="<?= $val('name') ?>" required maxlength="200">
      <?= $err('name') ?>
    </label>
    <label>Address
      <textarea name="address" rows="3"><?= $val('address') ?></textarea>
    </label>
    <label>Representative title <small>the signature line on documents</small>
      <input name="representative_title" value="<?= $val('representative_title') ?>" maxlength="100">
    </label>

    <button type="submit">Save</button>
  </form>
</section>

<section class="actions">
  <h2>Security <small>sign-in protection for admin accounts</small></h2>
  <?php $security = $security ?? ['require2fa' => false, 'meEnrolled' => false]; ?>
  <form method="post" action="/console/settings/security" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <label class="check">
      <input type="hidden" name="require_2fa_admins" value="0">
      <input type="checkbox" name="require_2fa_admins" value="1" <?= $security['require2fa'] ? 'checked' : '' ?>>
      Require two-factor authentication for admins and super admins
    </label>
    <p class="hint">When on, an admin who hasn't set up an authenticator app is held on the "My account" page until they do.
      <?php if (!$security['meEnrolled']): ?><strong>Set up your own two-factor first</strong> (<a href="/console/account">My account</a>) — this can only be switched on once you have it.<?php endif; ?></p>
    <button type="submit" class="btn-outline">Save</button>
  </form>
</section>

<section class="actions">
  <h2>Email <small>password-reset links and the daily digest</small></h2>
  <?php $mail = $mail ?? ['enabled' => false, 'describe' => '']; ?>
  <p class="hint">
    <span class="badge badge-<?= $mail['enabled'] ? 'completed' : 'pending' ?>"><?= $mail['enabled'] ? 'on' : 'off' ?></span>
    <?= $this->e($mail['describe']) ?>
  </p>
  <?php if ($mail['enabled']): ?>
    <form method="post" action="/console/settings/test-email" class="record-form">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <p class="hint">Sends a short test message to your own address (<?= $this->e($current_user['email'] ?? '') ?>).
        Credentials live in the server's <code>.env</code> and are deliberately not editable here.</p>
      <button type="submit" class="btn-outline">Send me a test email</button>
    </form>
  <?php endif; ?>
</section>

<section class="actions">
  <h2>Operational defaults</h2>

  <?php if (!empty($operational['updated_at'])): ?>
    <p class="hint">Last changed <?= $this->dt($operational['updated_at'], true) ?><?= $operational['updated_by_name'] ? ' by ' . $this->e($operational['updated_by_name']) : '' ?>.</p>
  <?php else: ?>
    <p class="hint">Showing the installation default — nobody has changed this yet.</p>
  <?php endif; ?>

  <form method="post" action="/console/settings/operational" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

    <label>Compliance grace period (hours) <small>how long after a scheduled inspection before it counts as "missed"</small>
      <input type="number" name="compliance_grace_hours" min="1" max="720" step="1"
             value="<?= $this->e((string) ($operational['compliance_grace_hours'] ?? '')) ?>" required>
      <?= $opErr('compliance_grace_hours') ?>
    </label>

    <button type="submit">Save</button>
  </form>
</section>
