<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $mode           'create' | 'edit'
 * @var array<string,mixed>|null $user
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 */
$old = $old ?? [];
$errors = $errors ?? [];
$val = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);
$err = static fn (string $k): string => isset($errors[$k])
    ? '<span class="field-error">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</span>' : '';
$isEdit = $mode === 'edit';
$action = $isEdit ? '/console/users/' . htmlspecialchars((string) $user['uuid'], ENT_QUOTES) : '/console/users';
$roles = ['inspector' => 'Inspector', 'office_reviewer' => 'Office reviewer', 'admin' => 'Admin', 'super_admin' => 'Super admin', 'board' => 'Board of Directors'];
?>
<div class="page-head">
  <h1><?= $isEdit ? 'Edit user' : 'New user' ?></h1>
  <a href="/console/users">Back to list</a>
</div>

<?php if (isset($errors['_'])): ?>
  <div class="flash flash-error"><?= $this->e($errors['_']) ?></div>
<?php endif; ?>

<form method="post" action="<?= $action ?>" class="record-form">
  <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

  <label>Full name
    <input name="full_name" value="<?= $val('full_name') ?>" required maxlength="150">
    <?= $err('full_name') ?>
  </label>

  <label>Email
    <input type="email" name="email" value="<?= $val('email') ?>" required maxlength="190">
    <?= $err('email') ?>
  </label>

  <label>Phone <small>optional</small>
    <input name="phone" value="<?= $val('phone') ?>" maxlength="20">
    <?= $err('phone') ?>
  </label>

  <div class="row">
    <label>Role
      <select name="role" required>
        <?php foreach ($roles as $v => $lbl): ?>
          <option value="<?= $v ?>" <?= ($old['role'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <?= $err('role') ?>
    </label>
    <label>Zone <small>optional, e.g. "South East"</small>
      <input name="zone" value="<?= $val('zone') ?>" maxlength="100">
      <?= $err('zone') ?>
    </label>
  </div>

  <?php if ($isEdit && ($old['role'] ?? '') !== 'inspector'): ?>
    <label class="check">
      <input type="hidden" name="receive_digest" value="0">
      <input type="checkbox" name="receive_digest" value="1" <?= !isset($old['receive_digest']) || $old['receive_digest'] ? 'checked' : '' ?>>
      Send this person the daily "needs attention" email <small>(only when email is configured and something is waiting)</small>
    </label>
  <?php endif; ?>

  <?php if (!$isEdit): ?>
    <label>Initial password <small>at least 10 characters — share it with the user out of band</small>
      <input type="text" name="password" required minlength="10" autocomplete="new-password">
      <?= $err('password') ?>
    </label>
  <?php endif; ?>

  <button type="submit"><?= $isEdit ? 'Save changes' : 'Create user' ?></button>
</form>

<?php if ($isEdit): ?>
  <section class="actions">
    <h2>Two-factor <span class="badge badge-<?= !empty($user['two_factor']) ? 'completed' : 'pending' ?>"><?= !empty($user['two_factor']) ? 'on' : 'off' ?></span></h2>
    <?php if (!empty($user['two_factor'])): ?>
      <p class="hint">Lost their phone? Resetting switches two-factor off, signs them out everywhere, and lets them enrol a new device at next sign-in.</p>
      <form method="post" action="/console/users/<?= $this->e($user['uuid']) ?>/two-factor/reset" data-confirm="Reset this person's two-factor and sign them out everywhere?">
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
        <button type="submit" class="btn-outline">Reset two-factor</button>
      </form>
    <?php else: ?>
      <p class="hint">Not set up. People turn it on themselves from <em>My account</em>.</p>
    <?php endif; ?>
  </section>

  <section class="actions">
    <h2>Sessions <small>signs the user out of the console and the inspector app on every device</small></h2>
    <form method="post" action="/console/users/<?= $this->e($user['uuid']) ?>/sign-out" data-confirm="Sign this user out everywhere? They will need to log in again.">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <button type="submit" class="btn-outline">Sign out everywhere</button>
    </form>
  </section>

  <section class="actions">
    <h2>Reset password <small>immediately replaces the current password</small></h2>
    <form method="post" action="/console/users/<?= $this->e($user['uuid']) ?>/reset-password" class="record-form"
          data-confirm="Reset this user's password? Their current password will stop working immediately.">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <label>New password <small>at least 10 characters</small>
        <input type="text" name="password" required minlength="10" autocomplete="new-password">
      </label>
      <button type="submit" class="btn-outline">Reset password</button>
    </form>
  </section>
<?php endif; ?>
