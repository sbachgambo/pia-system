<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array<string,mixed> $ins
 * @var array<string,mixed> $shipment
 * @var list<array<string,mixed>> $documents
 */
$reviewable = in_array($ins['status'], ['synced', 'amended'], true);
$sf = static fn (string $key) => htmlspecialchars((string) ($shipment[$key] ?? ''), ENT_QUOTES);
$findings = $ins['findings'] ?? [];
// Render existing finding rows plus a few blanks for additions.
$rows = array_merge($findings, array_fill(0, 3, ['checklist_item' => '', 'expected_value' => '', 'observed_value' => '', 'result' => 'pass', 'notes' => '']));
?>
<div class="page-head">
  <h1>Inspection review</h1>
  <a href="/console/inspections">Back to queue</a>
</div>

<dl class="detail">
  <dt>Status</dt><dd><span class="badge badge-<?= $this->e($ins['status']) ?>"><?= $this->e($ins['status']) ?></span></dd>
  <dt>NXP number</dt><dd><?php if (!empty($ins['nxp_number'])): ?><a href="/console/nxp/<?= $this->e($ins['consignment_uuid']) ?>/edit"><strong class="nxp-no"><?= $this->e($ins['nxp_number']) ?></strong></a><?php else: ?><a href="/console/nxp/<?= $this->e($ins['consignment_uuid']) ?>/edit">View NXP record</a><?php endif; ?></dd>
  <?php if (!empty($ins['cci_number'])): ?><dt>CCI number</dt><dd><strong class="cci-no"><?= $this->e($ins['cci_number']) ?></strong></dd><?php endif; ?>
  <dt>Client</dt><dd><?= $this->e($ins['client_name']) ?> — <?= $this->e($ins['product_category']) ?></dd>
  <dt>Inspector</dt><dd><?= $this->e($ins['inspector_name'] !== '' ? $ins['inspector_name'] : '—') ?></dd>
  <dt>Location</dt><dd><?= $this->e(ucfirst((string) $ins['location_type'])) ?> — <?= $this->e($ins['location_detail']) ?></dd>
  <dt>Started</dt><dd><?= $this->dt($ins['started_at'] ?? null, true) ?></dd>
  <dt>Synced</dt><dd><?= $this->dt($ins['synced_at'] ?? null, true) ?></dd>
  <?php if (!empty($ins['finalized_at'])): ?><dt>Finalised</dt><dd><?= $this->dt($ins['finalized_at'], true) ?></dd><?php endif; ?>
  <dt>Reference</dt><dd><code class="record-ref"><?= $this->e($ins['uuid']) ?></code></dd>
</dl>

<section class="actions">
  <h2>Attachments</h2>
  <?php if (empty($ins['attachments'])): ?>
    <p class="hint">None.</p>
  <?php else: ?>
    <ul class="att-list">
    <?php foreach ($ins['attachments'] as $a): ?>
      <li>
        <?= $this->e($a['file_type']) ?> — <?= $this->e($a['original_name'] ?? $a['client_uuid']) ?>
        <?php if (!empty($a['uploaded_at'])): ?>
          <span class="ok">uploaded</span>
        <?php else: ?>
          <span class="warn-text">pending upload</span>
        <?php endif; ?>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="actions">
  <h2>Shipment &amp; NESS details <small>feeds the CCI/NNCI — optional, save any time before generating</small></h2>
  <form method="post" action="/console/inspections/<?= $this->e($ins['uuid']) ?>/shipment-details" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <div class="row">
      <label>Shipment date <input type="date" name="shipment_date" value="<?= $sf('shipment_date') ?>"></label>
      <label>Shipping agent <input name="shipping_agent" value="<?= $sf('shipping_agent') ?>"></label>
    </div>
    <div class="row">
      <label>Carrier / vessel <input name="carrier_vessel" value="<?= $sf('carrier_vessel') ?>"></label>
      <label>Loading ref. no. <input name="loading_ref_no" value="<?= $sf('loading_ref_no') ?>"></label>
    </div>
    <label>Container numbers <input name="container_numbers" value="<?= $sf('container_numbers') ?>"></label>
    <div class="row">
      <label>Packing details <input name="packing_details" value="<?= $sf('packing_details') ?>"></label>
      <label>Quality remark <input name="quality_remark" value="<?= $sf('quality_remark') ?>"></label>
    </div>
    <div class="row">
      <label>Gross weight (kg) <input name="gross_weight_kg" value="<?= $sf('gross_weight_kg') ?>"></label>
      <label>Net weight (kg) <input name="net_weight_kg" value="<?= $sf('net_weight_kg') ?>"></label>
    </div>
    <div class="row">
      <label>Forex exchange date <input type="date" name="forex_exchange_date" value="<?= $sf('forex_exchange_date') ?>"></label>
      <label>Exchange rate <input name="exchange_rate" value="<?= $sf('exchange_rate') ?>"></label>
    </div>
    <div class="row">
      <label>NESS charges paid <input name="ness_charges_paid" value="<?= $sf('ness_charges_paid') ?>"></label>
      <label>NESS receipt no. <input name="ness_receipt_no" value="<?= $sf('ness_receipt_no') ?>"></label>
    </div>
    <div class="row">
      <label>NESS actual payable
        <?php if (!empty($nessSuggestion)): ?><small><?= $this->e($nessSuggestion['rate']) ?> of FOB = <?= $this->e($nessSuggestion['currency']) ?> <?= $this->e(number_format((float) $nessSuggestion['amount'], 2)) ?> — printed on the CCI if left blank</small><?php endif; ?>
        <input name="ness_actual_payable" value="<?= $sf('ness_actual_payable') ?>" inputmode="decimal"<?= !empty($nessSuggestion) ? ' placeholder="' . $this->e(number_format((float) $nessSuggestion['amount'], 2, '.', '')) . '"' : '' ?>>
      </label>
      <label>NESS balance paid <input name="ness_balance_paid" value="<?= $sf('ness_balance_paid') ?>"></label>
    </div>
    <label>NESS balance receipt no. <input name="ness_balance_receipt_no" value="<?= $sf('ness_balance_receipt_no') ?>"></label>
    <button type="submit">Save shipment details</button>
  </form>
</section>

<section class="actions">
  <h2>Documents</h2>
  <?php if (empty($documents)): ?>
    <p class="hint">None generated yet.</p>
  <?php else: ?>
    <ul class="att-list">
    <?php foreach ($documents as $d): ?>
      <li class="<?= $d['status'] === 'void' ? 'row-muted' : '' ?>">
        <span class="badge"><?= $this->e($d['type']) ?></span>
        <strong class="cci-no"><?= $this->e($d['document_number']) ?></strong>
        <?= $d['status'] === 'void' ? '<span class="badge badge-cancelled">replaced</span>' : '<span class="badge badge-completed">current</span>' ?>
        — issued <?= $this->dt($d['issued_at'], true) ?>
        by <?= $this->e($d['issued_by_name'] ?? 'system') ?>
        — <a href="/console/inspections/<?= $this->e($ins['uuid']) ?>/documents/<?= $this->e($d['uuid']) ?>" target="_blank">download</a>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
  <?php if ($ins['status'] === 'finalized'): ?>
    <?php
      $current = array_values(array_filter($documents ?? [], static fn (array $d): bool => $d['status'] === 'issued'))[0] ?? null;
      $confirm = $current !== null
          ? ' data-confirm="This replaces ' . $this->e($current['type'] . ' ' . $current['document_number']) . ', which will be marked void. Continue?"'
          : '';
    ?>
    <div class="inline-form">
      <form method="post" action="/console/inspections/<?= $this->e($ins['uuid']) ?>/documents" style="display:inline"<?= $confirm ?>>
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
        <input type="hidden" name="type" value="CCI">
        <button type="submit" class="btn-outline">Generate CCI</button>
      </form>
      <form method="post" action="/console/inspections/<?= $this->e($ins['uuid']) ?>/documents" style="display:inline"<?= $confirm ?>>
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
        <input type="hidden" name="type" value="NNCI">
        <button type="submit" class="btn-outline">Generate NNCI</button>
      </form>
    </div>
    <p class="hint">A CCI is generated automatically when you finalise. Use these if that failed, to correct a certificate after
      changing the shipment details, or to issue an NNCI instead (NESS fee unpaid). An inspection has one current certificate:
      a new one replaces the old, which is kept on file as void and is no longer invoiced or counted as income.</p>
  <?php else: ?>
    <p class="hint">Documents can only be generated once this inspection is finalised.</p>
  <?php endif; ?>
</section>

<?php if ($reviewable): ?>
<section class="actions">
  <h2>Amend</h2>
  <form method="post" action="/console/inspections/<?= $this->e($ins['uuid']) ?>/amend" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">

    <div class="row">
      <label>Location type
        <select name="location_type">
          <?php foreach (['', 'factory', 'warehouse', 'port', 'other'] as $v): ?>
            <option value="<?= $v ?>" <?= $ins['location_type'] === $v ? 'selected' : '' ?>><?= $v === '' ? '(unchanged)' : ucfirst($v) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Location detail
        <input name="location_detail" value="<?= $this->e($ins['location_detail']) ?>">
      </label>
    </div>

    <h3>Findings <small>rows with no item are ignored; submitting replaces the whole set</small></h3>
    <table class="grid findings-edit">
      <thead><tr><th>Checklist item</th><th>Expected</th><th>Observed</th><th>Result</th><th>Notes</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $n => $f): ?>
        <tr>
          <td><input name="findings[<?= $n ?>][checklist_item]" value="<?= $this->e($f['checklist_item'] ?? '') ?>"></td>
          <td><input name="findings[<?= $n ?>][expected_value]" value="<?= $this->e($f['expected_value'] ?? '') ?>"></td>
          <td><input name="findings[<?= $n ?>][observed_value]" value="<?= $this->e($f['observed_value'] ?? '') ?>"></td>
          <td>
            <select name="findings[<?= $n ?>][result]">
              <?php foreach (['pass', 'fail', 'flag'] as $r): ?>
                <option value="<?= $r ?>" <?= ($f['result'] ?? 'pass') === $r ? 'selected' : '' ?>><?= $r ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input name="findings[<?= $n ?>][notes]" value="<?= $this->e($f['notes'] ?? '') ?>"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <button type="submit">Save amendment</button>
  </form>
</section>

<section class="actions">
  <h2>Finalise / reject</h2>
  <form method="post" action="/console/inspections/<?= $this->e($ins['uuid']) ?>/finalize" class="inline-form"
        data-confirm="Finalise this inspection? It will be locked.">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <button type="submit">Finalise &amp; lock</button>
  </form>
  <form method="post" action="/console/inspections/<?= $this->e($ins['uuid']) ?>/reject" class="inline-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <input type="text" name="reason" placeholder="Reason for rejection" required>
    <button type="submit" class="btn-danger">Reject</button>
  </form>
</section>
<?php elseif ($ins['status'] === 'rejected'): ?>
  <p class="flash flash-error">Rejected — awaiting the inspector's re-sync.</p>
<?php elseif ($ins['status'] === 'finalized'): ?>
  <p class="flash flash-success">Finalised and locked.</p>
<?php endif; ?>

<section class="actions">
  <h2>Audit trail</h2>
  <?php if (empty($ins['audit_trail'])): ?>
    <p class="hint">No changes recorded yet.</p>
  <?php else: ?>
    <ul class="audit">
    <?php foreach ($ins['audit_trail'] as $ev): ?>
      <li>
        <strong><?= $this->e($ev['action']) ?></strong>
        by <?= $this->e($ev['actor_name'] ?? 'system') ?>
        <span class="hint"><?= $this->e($ev['at']) ?><?= $ev['ip_address'] ? ' · ' . $this->e($ev['ip_address']) : '' ?></span>
      </li>
    <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>
