<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var list<array<string,mixed>> $consignments
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 */
$old = $old ?? [];
$errors = $errors ?? [];
$val = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);
$err = static fn (string $k): string => isset($errors[$k])
    ? '<span class="field-error">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</span>' : '';
?>
<div class="page-head">
  <h1>New inspection request</h1>
  <a href="/console/inspection-requests">Back to list</a>
</div>

<?php if (isset($errors['_'])): ?>
  <div class="flash flash-error"><?= $this->e($errors['_']) ?></div>
<?php endif; ?>

<form method="post" action="/console/inspection-requests" class="record-form">
  <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

  <label>NXP record
    <select name="consignment_uuid" required>
      <option value="">— select —</option>
      <?php foreach ($consignments as $c): ?>
        <option value="<?= $this->e($c['uuid']) ?>" <?= ($old['consignment_uuid'] ?? '') === $c['uuid'] ? 'selected' : '' ?>>
          <?= $this->e($c['form_nxp_number'] ?? 'No NXP no.') ?> — <?= $this->e($c['client_name'] ?? '') ?>, <?= $this->e($c['product_category']) ?> (<?= $this->e($c['direction']) ?>, <?= $this->e($c['zone']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <?= $err('consignment_uuid') ?>
  </label>

  <label>Requested at <small>optional — defaults to now</small>
    <input type="datetime-local" name="requested_at" value="<?= $val('requested_at') ?>">
    <?= $err('requested_at') ?>
  </label>

  <p class="hint">The notice deadline is calculated automatically from the requested time.</p>

  <button type="submit">Create request</button>
</form>
