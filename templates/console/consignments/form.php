<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $mode
 * @var array<string,mixed>|null $consignment
 * @var list<array<string,mixed>> $clients
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 * @var list<array<string,mixed>> $history  requests / inspections / certificates under this NXP (edit only)
 */
$old = $old ?? [];
$errors = $errors ?? [];
$val = static fn (string $k): string => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);
$err = static fn (string $k): string => isset($errors[$k])
    ? '<span class="field-error">' . htmlspecialchars($errors[$k], ENT_QUOTES) . '</span>' : '';
$sel = static fn (string $k, string $v): string => ($old[$k] ?? '') === $v ? 'selected' : '';
$isEdit = $mode === 'edit';
$action = $isEdit ? '/console/nxp/' . htmlspecialchars((string) $consignment['uuid'], ENT_QUOTES) : '/console/nxp';
$savedNxp = $isEdit ? ($consignment['form_nxp_number'] ?? null) : null;
$missingNxp = $isEdit && ($consignment['direction'] ?? '') === 'export' && ($savedNxp === null || $savedNxp === '');
?>
<div class="page-head">
  <h1><?= $isEdit ? ($savedNxp ? 'NXP ' . $this->e($savedNxp) : 'Edit NXP record') : 'New NXP' ?></h1>
  <a href="/console/nxp">Back to list</a>
</div>
<?php if ($isEdit): ?>
  <p class="hint">Record reference: <code class="record-ref"><?= $this->e($consignment['uuid']) ?></code>
    — identifies this record in CSV imports when it has no NXP number (imports).</p>
<?php endif; ?>

<?php if ($missingNxp): ?>
  <div class="flash flash-error">This export was saved before the NXP number became mandatory. Add its NXP number below to complete the record.</div>
<?php endif; ?>

<?php if (isset($errors['_'])): ?>
  <div class="flash flash-error"><?= $this->e($errors['_']) ?></div>
<?php endif; ?>

<form method="post" action="<?= $action ?>" class="record-form">
  <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

  <label>Client
    <select name="client_uuid" required>
      <option value="">— select —</option>
      <?php foreach ($clients as $cl): ?>
        <option value="<?= $this->e($cl['uuid']) ?>" <?= $sel('client_uuid', (string) $cl['uuid']) ?>>
          <?= $this->e($cl['name']) ?> (<?= $this->e($cl['type']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
    <?= $err('client_uuid') ?>
  </label>

  <div class="row">
    <label>Direction
      <select name="direction" required>
        <option value="export" <?= $sel('direction', 'export') ?>>Export</option>
        <option value="import" <?= $sel('direction', 'import') ?>>Import</option>
      </select>
      <?= $err('direction') ?>
    </label>
    <label>NXP number <small>required for exports · must be unique</small>
      <input name="form_nxp_number" value="<?= $val('form_nxp_number') ?>" maxlength="50" class="nxp-input" placeholder="e.g. AA1234567"<?= $missingNxp ? ' autofocus' : '' ?>>
      <?= $err('form_nxp_number') ?>
    </label>
    <label>Zone
      <input name="zone" value="<?= $val('zone') ?>" required maxlength="100">
      <?= $err('zone') ?>
    </label>
  </div>

  <label>Product category
    <input name="product_category" value="<?= $val('product_category') ?>" required maxlength="150">
    <?= $err('product_category') ?>
  </label>
  <label>Product description
    <textarea name="product_description" rows="3" required><?= $val('product_description') ?></textarea>
    <?= $err('product_description') ?>
  </label>

  <div class="row">
    <label>HS code <small>optional</small>
      <input name="hs_code" value="<?= $val('hs_code') ?>" maxlength="20">
      <?= $err('hs_code') ?>
    </label>
    <label>Quantity
      <input name="quantity" value="<?= $val('quantity') ?>" required inputmode="decimal">
      <?= $err('quantity') ?>
    </label>
    <label>Unit
      <input name="unit_of_measure" value="<?= $val('unit_of_measure') ?>" required maxlength="20">
      <?= $err('unit_of_measure') ?>
    </label>
  </div>

  <div class="row">
    <label>Declared value
      <input name="declared_value" value="<?= $val('declared_value') ?>" required inputmode="decimal">
      <?= $err('declared_value') ?>
    </label>
    <label>Currency
      <input name="currency" value="<?= $val('currency') ?>" required maxlength="3" placeholder="USD">
      <?= $err('currency') ?>
    </label>
  </div>

  <div class="row">
    <label>Origin country
      <input name="origin_country" value="<?= $val('origin_country') ?>" required maxlength="100">
      <?= $err('origin_country') ?>
    </label>
    <label>Destination country
      <input name="destination_country" value="<?= $val('destination_country') ?>" required maxlength="100">
      <?= $err('destination_country') ?>
    </label>
  </div>

  <button type="submit"><?= $isEdit ? 'Save changes' : 'Create NXP record' ?></button>
</form>

<?php if ($isEdit): ?>
<?php $history = $history ?? []; ?>
<section class="actions">
  <h2>Requests, inspections &amp; certificates</h2>
  <?php if (!$history): ?>
    <p class="hint">No inspection has been requested under this NXP yet.
      <a href="/console/inspection-requests/new?consignment=<?= $this->e($consignment['uuid']) ?>">Create a request</a>.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>CCI / NNCI no.</th><th>Issued</th><th>Requested</th><th>Request</th><th>Inspection</th></tr></thead>
      <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td>
            <?php if ($h['document_number'] !== null): ?>
              <a href="/console/inspections/<?= $this->e($h['inspection_uuid']) ?>/documents/<?= $this->e($h['document_uuid']) ?>"><strong class="cci-no"><?= $this->e($h['document_type']) ?> <?= $this->e($h['document_number']) ?></strong></a>
              <?php if ($h['document_status'] !== 'issued'): ?><span class="badge badge-cancelled"><?= $this->e($h['document_status']) ?></span><?php endif; ?>
            <?php else: ?>
              <span class="hint">not issued yet</span>
            <?php endif; ?>
          </td>
          <td><?= $this->d($h['issued_at']) ?></td>
          <td><?= $this->dt($h['requested_at']) ?></td>
          <td><a href="/console/inspection-requests/<?= $this->e($h['request_uuid']) ?>"><span class="badge badge-<?= $this->e($h['request_status']) ?>"><?= $this->e($h['request_status']) ?></span></a></td>
          <td><?php if ($h['inspection_uuid'] !== null): ?><a href="/console/inspections/<?= $this->e($h['inspection_uuid']) ?>"><span class="badge badge-<?= $this->e($h['inspection_status']) ?>"><?= $this->e($h['inspection_status']) ?></span></a><?php else: ?><span class="hint">not scheduled</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</section>

<?php $trade = $trade ?? []; $tv = static fn (string $k): string => htmlspecialchars((string) ($trade[$k] ?? ''), ENT_QUOTES); ?>
<section class="actions">
  <h2>Trade &amp; banking details <small>optional — feeds the CCI (Phase 6)</small></h2>
  <form method="post" action="/console/nxp/<?= $this->e($consignment['uuid']) ?>/trade-details" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

    <label>Importer name <input name="importer_name" value="<?= $tv('importer_name') ?>"></label>
    <label>Importer address <textarea name="importer_address" rows="2"><?= $tv('importer_address') ?></textarea></label>
    <label>NEPC number <input name="nepc_number" value="<?= $tv('nepc_number') ?>"></label>

    <div class="row">
      <label>Exporter's bank <input name="exporter_bank_name" value="<?= $tv('exporter_bank_name') ?>"></label>
      <label>Importer's bank <input name="importer_bank_name" value="<?= $tv('importer_bank_name') ?>"></label>
    </div>
    <label>Bank reference <input name="bank_reference" value="<?= $tv('bank_reference') ?>"></label>

    <div class="row">
      <label>Invoice number <input name="invoice_number" value="<?= $tv('invoice_number') ?>"></label>
      <label>Invoice date <input type="date" name="invoice_date" value="<?= $tv('invoice_date') ?>"></label>
    </div>
    <div class="row">
      <label>Basis of sale
        <select name="basis_of_sale">
          <option value="">—</option>
          <?php foreach (['FOB', 'CFR', 'CIF'] as $b): ?>
            <option value="<?= $b ?>" <?= ($trade['basis_of_sale'] ?? '') === $b ? 'selected' : '' ?>><?= $b ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Method of payment <input name="method_of_payment" value="<?= $tv('method_of_payment') ?>"></label>
    </div>
    <div class="row">
      <label>Freight charges <input name="freight_charges" value="<?= $tv('freight_charges') ?>" inputmode="decimal"></label>
      <label>Insurance charges <input name="insurance_charges" value="<?= $tv('insurance_charges') ?>" inputmode="decimal"></label>
    </div>

    <button type="submit">Save trade details</button>
  </form>
</section>
<?php endif; ?>

<?php if ($isEdit && isset($attachments)): ?>
  <?= $this->partial('console/_attachments', [
      'csrf_token'   => $csrf_token,
      'current_user' => $current_user ?? null,
      'attachments'  => $attachments,
      'uploadAction' => '/console/nxp/' . $consignment['uuid'] . '/attachments',
      'returnTo'     => '/console/nxp/' . $consignment['uuid'] . '/edit',
  ]) ?>
<?php endif; ?>
