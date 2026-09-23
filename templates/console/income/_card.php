<?php
/**
 * The dashboard's income card. Rendered inline on the dashboard and, on its
 * own, by /console/income/card when the period is switched (console.js swaps
 * it in place). Every control is a plain link / GET form, so it also works
 * with scripts off.
 *
 * @var \App\Http\View\Renderer $this
 * @var array<string,mixed> $period   IncomeReport::period()
 * @var array<string,string> $periods
 * @var array<string,mixed> $report   IncomeReport::build()
 * @var list<array<string,mixed>> $monthly
 * @var string|null $error
 */
use App\Http\View\Charts;

$money = static fn ($v): string => number_format((float) $v, 2);
$reportUrl = '/console/income?period=' . rawurlencode($period['key'])
    . ($period['key'] === 'custom' ? '&from=' . $period['from_date'] . '&to=' . $period['to_date'] : '');
$missing = count($report['missing']);
?>
<section class="card income-card" id="incomeCard" data-income-card aria-live="polite">
  <div class="card-head">
    <h2>Income <span class="hint">· <?= $this->e($report['rate_label']) ?> service fee on issued CCIs</span></h2>
    <a href="<?= $this->e($reportUrl) ?>">Full report</a>
  </div>

  <div class="seg" role="group" aria-label="Income period">
    <?php foreach ($periods as $key => $name): if ($key === 'custom') { continue; } ?>
      <a class="seg-item<?= $period['key'] === $key ? ' active' : '' ?>" href="/console?income=<?= $this->e($key) ?>#incomeCard"
         data-income-period="<?= $this->e($key) ?>"<?= $period['key'] === $key ? ' aria-current="true"' : '' ?>><?= $this->e($name) ?></a>
    <?php endforeach; ?>
    <details class="seg-custom"<?= $period['key'] === 'custom' ? ' open' : '' ?>>
      <summary class="seg-item<?= $period['key'] === 'custom' ? ' active' : '' ?>">Custom</summary>
      <form method="get" action="/console#incomeCard" class="inline-form" data-income-form>
        <input type="hidden" name="income" value="custom">
        <input type="date" name="from" value="<?= $this->e($period['from_date']) ?>" aria-label="From" required>
        <input type="date" name="to" value="<?= $this->e($period['to_date']) ?>" aria-label="To" required>
        <button type="submit" class="btn-outline">Apply</button>
      </form>
    </details>
  </div>

  <?php if ($error): ?><div class="flash flash-error"><?= $this->e($error) ?></div><?php endif; ?>

  <div class="income-figure">
    <span class="income-total">₦<?= $this->e($money($report['income'])) ?></span>
    <span class="income-sub">
      <?= $this->e($period['label']) ?> · <?= $this->e($report['cci_count']) ?> CCI<?= $report['cci_count'] === 1 ? '' : 's' ?>
      · FOB ₦<?= $this->e($money($report['fob_ngn'])) ?>
    </span>
  </div>

  <?php if ($missing > 0): ?>
    <p class="income-warn warn-text">
      <?= $this->e($missing) ?> issued CCI<?= $missing === 1 ? ' has' : 's have' ?> no exchange rate yet and
      <?= $missing === 1 ? 'is' : 'are' ?> not counted. <a href="<?= $this->e($reportUrl) ?>#missing">Add the rate<?= $missing === 1 ? '' : 's' ?></a>
    </p>
  <?php endif; ?>

  <dl class="income-mini">
    <div><dt>Invoiced to CBN</dt><dd>₦<?= $this->e($money($report['invoiced'])) ?></dd></div>
    <div><dt>Paid</dt><dd>₦<?= $this->e($money($report['paid'])) ?></dd></div>
    <div><dt>Outstanding</dt><dd class="<?= $report['outstanding'] > 0 ? 'warn-text' : '' ?>">₦<?= $this->e($money($report['outstanding'])) ?></dd></div>
  </dl>

  <h3 class="income-chart-title">Last 12 months <span class="hint">— click a month for its report</span></h3>
  <?= Charts::incomeBars($monthly) ?>
</section>
