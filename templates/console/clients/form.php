<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $mode           'create' | 'edit'
 * @var array<string,mixed>|null $client
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 */
$old = $old ?? [];
$errors = $errors ?? [];
$val = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);
$err = static fn (string $k): string => isset($errors[$k])
    ? '<span class="field-error">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</span>' : '';
$isEdit = $mode === 'edit';
$action = $isEdit ? '/console/clients/' . htmlspecialchars((string) $client['uuid'], ENT_QUOTES) : '/console/clients';
?>
<div class="page-head">
  <h1><?= $isEdit ? 'Edit client' : 'New client' ?></h1>
  <a href="/console/clients">Back to list</a>
</div>

<?php if (isset($errors['_'])): ?>
  <div class="flash flash-error"><?= $this->e($errors['_']) ?></div>
<?php endif; ?>

<form method="post" action="<?= $action ?>" class="record-form">
  <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

  <label>Name
    <input name="name" value="<?= $val('name') ?>" required maxlength="200">
    <?= $err('name') ?>
  </label>

  <label>Type
    <select name="type" required>
      <?php foreach (['exporter' => 'Exporter', 'importer' => 'Importer'] as $v => $lbl): ?>
        <option value="<?= $v ?>" <?= ($old['type'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <?= $err('type') ?>
  </label>

  <label>RC number (CAC) <small>optional</small>
    <input name="rc_number" value="<?= $val('rc_number') ?>" maxlength="50">
    <?= $err('rc_number') ?>
  </label>

  <label>Address
    <textarea name="address" rows="3" required><?= $val('address') ?></textarea>
    <?= $err('address') ?>
  </label>

  <label>Contact name
    <input name="contact_name" value="<?= $val('contact_name') ?>" required maxlength="150">
    <?= $err('contact_name') ?>
  </label>

  <label>Contact phone
    <input name="contact_phone" value="<?= $val('contact_phone') ?>" required maxlength="20">
    <?= $err('contact_phone') ?>
  </label>

  <label>Contact email
    <input type="email" name="contact_email" value="<?= $val('contact_email') ?>" required maxlength="190">
    <?= $err('contact_email') ?>
  </label>

  <button type="submit"><?= $isEdit ? 'Save changes' : 'Create client' ?></button>
</form>

<?php if ($isEdit && isset($attachments)): ?>
  <?= $this->partial('console/_attachments', [
      'csrf_token'   => $csrf_token,
      'current_user' => $current_user ?? null,
      'attachments'  => $attachments,
      'uploadAction' => '/console/clients/' . $client['uuid'] . '/attachments',
      'returnTo'     => '/console/clients/' . $client['uuid'] . '/edit',
  ]) ?>
<?php endif; ?>
