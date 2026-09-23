<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array<string,mixed> $preview  CbnInvoiceService::preview()
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $settings
 * @var string $feeLabel
 * @var string $today
 */
use App\Http\View\Icons;

$money = static fn ($v): string => number_format((float) $v, 2);
$f = $preview['figures'];
$ready = $preview['blockers'] === [];
?>
<div class="page-head">
  <h1>CBN invoices</h1>
</div>
<p class="hint">The monthly service-fee invoice to the Central Bank of Nigeria: every CCI issued in the month, grouped by region,
  at <?= $this->e($feeLabel) ?> of FOB. Issued invoices are frozen and signed, and filed in the Archive automatically.</p>

<section class="card">
  <div class="card-head">
    <h2>Invoice for <?= $this->e($preview['label']) ?></h2>
    <form method="get" action="/console/invoices" class="inline-form">
      <input type="month" name="month" value="<?= $this->e($preview['month']) ?>" max="<?= $this->e(substr($today, 0, 7)) ?>" aria-label="Month">
      <button type="submit" class="btn-outline">Show month</button>
    </form>
  </div>

  <?php foreach ($preview['blockers'] as $b): ?>
    <div class="flash flash-error"><?= $this->e($b) ?></div>
  <?php endforeach; ?>

  <?php if ($f['lines']): ?>
    <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>Region</th><th class="num">CCIs</th><th>Currency</th><th class="num">FOB value</th><th class="num">Exchange rate</th><th class="num">FOB value (₦)</th><th class="num">Fee (<?= $this->e($feeLabel) ?>) (₦)</th></tr></thead>
      <tbody>
      <?php foreach ($f['lines'] as $l): ?>
        <tr>
          <td><?= $this->e($l['region']) ?></td>
          <td class="num"><?= $this->e($l['cci_count']) ?></td>
          <td><?= $this->e($l['currency']) ?></td>
          <td class="num"><?= $this->e($money($l['fob'])) ?></td>
          <td class="num"><?= $this->e(number_format((float) $l['exchange_rate'], 4)) ?></td>
          <td class="num"><?= $this->e($money($l['fob_ngn'])) ?></td>
          <td class="num"><?= $this->e($money($l['fee_ngn'])) ?></td>
        </tr>
      <?php endforeach; ?>
        <tr class="row-total">
          <td><strong>Total</strong></td>
          <td class="num"><strong><?= $this->e($f['cci_count']) ?></strong></td>
          <td></td><td></td><td></td>
          <td class="num"><strong><?= $this->e($money($f['fob_ngn'])) ?></strong></td>
          <td class="num"><strong>₦<?= $this->e($money($f['fee_ngn'])) ?></strong></td>
        </tr>
      </tbody>
    </table>
    </div>
    <p class="hint"><?= $this->e(\App\Invoicing\InvoiceCalculator::nairaInWords($f['fee_ngn'])) ?></p>
  <?php endif; ?>

  <?php if ($preview['ccis']): ?>
    <details class="cci-detail">
      <summary><?= count($preview['ccis']) ?> CCI(s) issued in <?= $this->e($preview['label']) ?></summary>
      <div class="table-wrap">
      <table class="grid">
        <thead><tr><th>CCI no.</th><th>NXP no.</th><th>Client</th><th>Region</th><th class="num">FOB</th><th class="num">Rate</th></tr></thead>
        <tbody>
        <?php foreach ($preview['ccis'] as $c): ?>
          <tr class="<?= $c['exchange_rate'] === null ? 'row-warn' : '' ?>">
            <td><a href="/console/inspections/<?= $this->e($c['inspection_uuid']) ?>"><strong class="cci-no"><?= $this->e($c['document_number']) ?></strong></a></td>
            <td><span class="nxp-no"><?= $this->e($c['form_nxp_number'] ?? '—') ?></span></td>
            <td><?= $this->e($c['client_name']) ?></td>
            <td><?= $this->e($c['zone']) ?></td>
            <td class="num"><?= $this->e($c['currency']) ?> <?= $this->e($money($c['declared_value'])) ?></td>
            <td class="num"><?= $c['exchange_rate'] !== null ? $this->e(number_format((float) $c['exchange_rate'], 4)) : '<span class="warn-text">missing</span>' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </details>
  <?php endif; ?>

  <?php if ($ready): ?>
    <form method="post" action="/console/invoices" data-confirm="Issue the <?= $this->e($preview['label']) ?> invoice to the CBN for ₦<?= $this->e($money($f['fee_ngn'])) ?>? It cannot be edited afterwards, only voided.">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="month" value="<?= $this->e($preview['month']) ?>">
      <button type="submit">Issue invoice for <?= $this->e($preview['label']) ?></button>
    </form>
  <?php endif; ?>
</section>

<section class="card">
  <h2>Issued invoices</h2>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Invoice no.</th><th>Month</th><th class="num">CCIs</th><th class="num">Amount (₦)</th><th>Status</th><th>Issued</th><th></th></tr></thead>
    <tbody>
    <?php if (!$list['data']): ?>
      <tr><td colspan="7" class="empty">No invoices issued yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($list['data'] as $inv): ?>
      <?php $badge = ['issued' => 'pending', 'paid' => 'completed', 'void' => 'cancelled'][$inv['status']] ?? 'pending'; ?>
      <tr>
        <td><strong><?= $this->e($inv['invoice_number']) ?></strong></td>
        <td><?= $this->e(date('M Y', strtotime((string) $inv['period_month']))) ?></td>
        <td class="num"><?= $this->e($inv['cci_count']) ?></td>
        <td class="num"><?= $this->e($money($inv['fee_ngn'])) ?></td>
        <td>
          <span class="badge badge-<?= $badge ?>"><?= $this->e($inv['status'] === 'issued' ? 'awaiting payment' : $inv['status']) ?></span>
          <?php if ($inv['status'] === 'paid'): ?><br><small><?= $this->d($inv['paid_at']) ?><?= $inv['payment_reference'] ? ' · ' . $this->e($inv['payment_reference']) : '' ?></small><?php endif; ?>
          <?php if ($inv['status'] === 'void'): ?><br><small><?= $this->e($inv['void_reason']) ?></small><?php endif; ?>
        </td>
        <td><?= $this->d($inv['issued_at']) ?><br><small><?= $this->e($inv['issued_by_name'] ?? '') ?></small></td>
        <td>
          <a href="/console/invoices/<?= $this->e($inv['uuid']) ?>/download"><?= Icons::svg('download', 'inline-icon') ?> PDF</a>
          <?php if ($inv['status'] === 'issued'): ?>
            <details class="row-actions">
              <summary>Record payment / void</summary>
              <form method="post" action="/console/invoices/<?= $this->e($inv['uuid']) ?>/paid" class="record-form nobox">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
                <label>Date received <input type="date" name="paid_at" required max="<?= $this->e($today) ?>" min="<?= $this->e(substr((string) $inv['issued_at'], 0, 10)) ?>"></label>
                <label>Payment reference <small>optional</small> <input name="payment_reference" maxlength="100"></label>
                <button type="submit">Mark paid</button>
              </form>
              <form method="post" action="/console/invoices/<?= $this->e($inv['uuid']) ?>/void" class="record-form nobox" data-confirm="Void invoice <?= $this->e($inv['invoice_number']) ?>?">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
                <label>Reason for voiding <input name="reason" required maxlength="255"></label>
                <button type="submit" class="btn-danger">Void invoice</button>
              </form>
            </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= $this->partial('console/_pagination', ['pagination' => $list['pagination'], 'base' => '/console/invoices', 'query' => ['month' => $preview['month']]]) ?>
</section>

<section class="card" id="invoice-details">
  <h2>Invoice details</h2>
  <p class="hint">The fixed parts of every invoice. The bank account must be filled in before an invoice can be issued.</p>
  <form method="post" action="/console/invoices/settings" class="record-form nobox">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <div class="row">
      <label>Account name <input name="bank_account_name" value="<?= $this->e($settings['bank_account_name'] ?? '') ?>" maxlength="150" required></label>
      <label>Account number <input name="bank_account_number" value="<?= $this->e($settings['bank_account_number'] ?? '') ?>" maxlength="30" required inputmode="numeric"></label>
      <label>Bank <input name="bank_name" value="<?= $this->e($settings['bank_name'] ?? '') ?>" maxlength="150" required></label>
    </div>
    <label>Invoice number prefix <small>numbers look like <?= $this->e($settings['number_prefix']) ?>/<?= $this->e(substr($today, 0, 4)) ?>/001</small>
      <input name="number_prefix" value="<?= $this->e($settings['number_prefix']) ?>" maxlength="20" pattern="[A-Za-z0-9\-]+" required>
    </label>
    <label>Addressed to <textarea name="addressee" rows="3" maxlength="1000"><?= $this->e($settings['addressee']) ?></textarea></label>
    <label>Service description <small>the "TO:" line</small> <textarea name="service_description" rows="3" maxlength="2000"><?= $this->e($settings['service_description']) ?></textarea></label>
    <button type="submit" class="btn-outline">Save invoice details</button>
  </form>
</section>
