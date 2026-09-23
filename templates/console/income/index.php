<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array<string,mixed> $period
 * @var array<string,string> $periods
 * @var array<string,mixed> $report
 * @var string|null $error
 */
use App\Http\View\Icons;

$money = static fn ($v): string => number_format((float) $v, 2);
$query = 'period=' . rawurlencode($period['key'])
    . ($period['key'] === 'custom' ? '&from=' . $period['from_date'] . '&to=' . $period['to_date'] : '');
$pct = static fn (float $part, float $whole): string => $whole > 0 ? number_format(100 * $part / $whole, 1) . '%' : '—';
$breakdown = static function (string $title, string $col, array $rows, float $total) use ($money, $pct): string {
    ob_start(); ?>
    <section class="card">
      <h2><?= htmlspecialchars($title, ENT_QUOTES) ?></h2>
      <?php if ($rows === []): ?>
        <div class="empty-state">No income in this period.</div>
      <?php else: ?>
      <div class="table-wrap">
      <table class="grid">
        <thead><tr><th><?= htmlspecialchars($col, ENT_QUOTES) ?></th><th class="num">CCIs</th><th class="num">FOB (₦)</th><th class="num">Income (₦)</th><th class="num">Share</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= isset($r['href']) ? '<a href="' . htmlspecialchars($r['href'], ENT_QUOTES) . '">' . htmlspecialchars($r['name'] ?? $r['label'], ENT_QUOTES) . '</a>' : htmlspecialchars($r['name'] ?? $r['label'], ENT_QUOTES) ?></td>
            <td class="num"><?= (int) $r['count'] ?></td>
            <td class="num"><?= $money($r['fob_ngn']) ?></td>
            <td class="num"><strong><?= $money($r['income']) ?></strong></td>
            <td class="num"><?= $pct((float) $r['income'], $total) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>
    </section>
    <?php return (string) ob_get_clean();
};
$months = array_map(static fn (array $m): array => $m + ['href' => '/console/income?period=custom&from=' . $m['from'] . '&to=' . $m['to']], $report['by_month']);
?>
<div class="page-head">
  <h1>Income — <?= $this->e($period['label']) ?></h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="/console/income/export?<?= $this->e($query) ?>"><?= Icons::svg('download') ?> Download CSV</a>
    <a class="btn btn-outline" href="/console/invoices"><?= Icons::svg('receipt') ?> CBN invoices</a>
  </div>
</div>
<p class="hint">The PIA service fee of <?= $this->e($report['rate_label']) ?> on the FOB value of every issued CCI, converted to naira at
  each CCI's own exchange rate — the same rate and certificates the monthly CBN invoice bills. NNCIs and voided certificates earn no fee.</p>

<?php if ($error): ?><div class="flash flash-error"><?= $this->e($error) ?></div><?php endif; ?>

<form method="get" action="/console/income" class="filters">
  <select name="period" aria-label="Period">
    <?php foreach ($periods as $key => $name): ?>
      <option value="<?= $this->e($key) ?>"<?= $period['key'] === $key ? ' selected' : '' ?>><?= $this->e($name) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="inline">From <input type="date" name="from" value="<?= $this->e($period['from_date']) ?>"></label>
  <label class="inline">To <input type="date" name="to" value="<?= $this->e($period['to_date']) ?>"></label>
  <button type="submit" class="btn-outline">Show</button>
  <span class="hint">Dates apply when "Custom dates" is chosen.</span>
</form>

<div class="stat-grid stat-grid-3">
  <div class="stat"><span class="stat-icon"><?= Icons::svg('trending') ?></span><span class="n">₦<?= $this->e($money($report['income'])) ?></span><span class="l">Income (<?= $this->e($report['rate_label']) ?>)</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('file') ?></span><span class="n"><?= $this->e($report['cci_count']) ?></span><span class="l">CCIs counted</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('package') ?></span><span class="n">₦<?= $this->e($money($report['fob_ngn'])) ?></span><span class="l">FOB value (₦)</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('receipt') ?></span><span class="n">₦<?= $this->e($money($report['invoiced'])) ?></span><span class="l">Invoiced to CBN</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('shield') ?></span><span class="n">₦<?= $this->e($money($report['paid'])) ?></span><span class="l">Paid by CBN</span></div>
  <div class="stat <?= $report['outstanding'] > 0 ? 'warn' : '' ?>"><span class="stat-icon"><?= Icons::svg('alert') ?></span><span class="n">₦<?= $this->e($money($report['outstanding'])) ?></span><span class="l">Outstanding</span></div>
</div>

<?php if ($report['missing']): ?>
<section class="card" id="missing">
  <h2 class="warn-text"><?= $this->e(count($report['missing'])) ?> CCI<?= count($report['missing']) === 1 ? '' : 's' ?> not counted — no exchange rate</h2>
  <p class="hint">Open the inspection and enter the exchange rate under Shipment / NESS details, or load rates in bulk with
    <a href="/console/import?type=shipment_details">Import → Shipment / NESS details</a>. The income updates immediately.</p>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>CCI no.</th><th>NXP no.</th><th>Client</th><th>Issued</th><th class="num">FOB</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($report['missing'] as $r): ?>
      <tr>
        <td><span class="cci-no"><?= $this->e($r['cci_number']) ?></span></td>
        <td><span class="nxp-no"><?= $this->e($r['nxp_number'] ?? '—') ?></span></td>
        <td><?= $this->e($r['client_name']) ?></td>
        <td><?= $this->d($r['issued_at']) ?></td>
        <td class="num"><?= $this->e($r['currency'] . ' ' . $money($r['fob'])) ?></td>
        <td><a href="/console/inspections/<?= $this->e($r['inspection_uuid']) ?>">Add rate</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<div class="chart-grid income-breakdowns">
  <?= $breakdown('By month', 'Month', $months, (float) $report['income']) ?>
  <?= $breakdown('By region', 'Region', $report['by_region'], (float) $report['income']) ?>
</div>
<?= $breakdown('By client', 'Client', $report['by_client'], (float) $report['income']) ?>

<?php if ($report['invoices']): ?>
<section class="card">
  <h2>CBN invoices for these months</h2>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Invoice no.</th><th>Month</th><th>Status</th><th class="num">Amount (₦)</th><th>Paid on</th></tr></thead>
    <tbody>
    <?php foreach ($report['invoices'] as $inv): ?>
      <tr>
        <td><?= $this->e($inv['invoice_number']) ?></td>
        <td><?= $this->e(date('F Y', strtotime((string) $inv['period_month']))) ?></td>
        <td><span class="badge badge-<?= $inv['status'] === 'paid' ? 'completed' : 'scheduled' ?>"><?= $this->e($inv['status']) ?></span></td>
        <td class="num"><?= $this->e($money($inv['fee_ngn'])) ?></td>
        <td><?= $this->d($inv['paid_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">An invoice bills each region line (its FOB in naira × <?= $this->e($report['rate_label']) ?>), so its total can differ from the
    per-CCI figures above by a few kobo of rounding.</p>
</section>
<?php endif; ?>

<section class="card">
  <h2>Every CCI in this period</h2>
  <?php if ($report['rows'] === []): ?>
    <div class="empty-state">No CCIs were issued in this period.</div>
  <?php else: ?>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>CCI no.</th><th>Issued</th><th>NXP no.</th><th>Client</th><th>Region</th><th class="num">FOB</th><th class="num">Rate</th><th class="num">FOB (₦)</th><th class="num">Income (₦)</th></tr></thead>
    <tbody>
    <?php foreach ($report['rows'] as $r): ?>
      <tr class="<?= $r['income'] === null ? 'row-warn' : '' ?>">
        <td><a class="cci-no" href="/console/inspections/<?= $this->e($r['inspection_uuid']) ?>"><?= $this->e($r['cci_number']) ?></a></td>
        <td><?= $this->d($r['issued_at']) ?></td>
        <td><a class="nxp-no" href="/console/nxp/<?= $this->e($r['nxp_uuid']) ?>/edit"><?= $this->e($r['nxp_number'] ?? '—') ?></a></td>
        <td><?= $this->e($r['client_name']) ?></td>
        <td><?= $this->e($r['region']) ?></td>
        <td class="num"><?= $this->e($r['currency'] . ' ' . $money($r['fob'])) ?></td>
        <td class="num"><?= $r['exchange_rate'] !== null ? $this->e(number_format((float) $r['exchange_rate'], 4)) : '<span class="warn-text">none</span>' ?></td>
        <td class="num"><?= $r['fob_ngn'] !== null ? $this->e($money($r['fob_ngn'])) : '—' ?></td>
        <td class="num"><?= $r['income'] !== null ? '<strong>' . $this->e($money($r['income'])) . '</strong>' : '<span class="warn-text">not counted</span>' ?></td>
      </tr>
    <?php endforeach; ?>
      <tr class="row-total">
        <td colspan="7"><strong>Total</strong> <span class="hint">(<?= $this->e($report['cci_count']) ?> counted)</span></td>
        <td class="num"><strong><?= $this->e($money($report['fob_ngn'])) ?></strong></td>
        <td class="num"><strong>₦<?= $this->e($money($report['income'])) ?></strong></td>
      </tr>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
