<?php
/**
 * NXP records (internally `consignments`). The NXP number is the key that
 * identifies a transaction, so it leads every row; exports saved before the
 * number became mandatory are flagged so someone completes them.
 *
 * @var \App\Http\View\Renderer $this
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 */
$exportQuery = http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== ''));
?>
<div class="page-head">
  <h1>NXP records</h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="/console/nxp/export<?= $exportQuery !== '' ? '?' . $exportQuery : '' ?>"><?= \App\Http\View\Icons::svg('download') ?> Export CSV</a>
    <a class="btn" href="/console/nxp/new"><?= \App\Http\View\Icons::svg('plus') ?> New NXP</a>
  </div>
</div>

<form class="filters" method="get" action="/console/nxp">
  <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="NXP no., client, product, HS code">
  <select name="direction">
    <option value="">Any direction</option>
    <option value="export" <?= ($filters['direction'] ?? '') === 'export' ? 'selected' : '' ?>>Export</option>
    <option value="import" <?= ($filters['direction'] ?? '') === 'import' ? 'selected' : '' ?>>Import</option>
  </select>
  <input name="zone" value="<?= $this->e($filters['zone'] ?? '') ?>" placeholder="Zone">
  <label class="inline"><input type="checkbox" name="missing_nxp" value="1" <?= !empty($filters['missing_nxp']) ? 'checked' : '' ?>> Missing NXP number</label>
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>NXP no.</th><th>Client / product</th><th>Dir.</th><th>Qty</th><th>Value</th><th>Route</th><th>Zone</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="8" class="empty"><?= !empty($filters['missing_nxp']) ? 'Every export has its NXP number.' : 'No NXP records yet.' ?></td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $c): ?>
    <?php $missing = $c['direction'] === 'export' && ($c['form_nxp_number'] ?? null) === null; ?>
    <tr class="<?= $missing ? 'row-warn' : '' ?>">
      <td>
        <?php if ($c['form_nxp_number'] !== null): ?>
          <strong class="nxp-no"><?= $this->e($c['form_nxp_number']) ?></strong>
        <?php elseif ($missing): ?>
          <span class="badge badge-pending">needs NXP no.</span>
        <?php else: ?>
          <span class="hint">n/a (import)</span>
        <?php endif; ?>
      </td>
      <td><?= $this->e($c['client_name'] ?? '') ?><br><small><?= $this->e($c['product_category']) ?><?= ($c['hs_code'] ?? '') !== '' ? ' · HS ' . $this->e($c['hs_code']) : '' ?></small></td>
      <td><?= $this->e($c['direction']) ?></td>
      <td><?= $this->e($c['quantity']) ?> <?= $this->e($c['unit_of_measure']) ?></td>
      <td><?= $this->e($c['currency']) ?> <?= $this->e($c['declared_value']) ?></td>
      <td><?= $this->e($c['origin_country']) ?> &rarr; <?= $this->e($c['destination_country']) ?></td>
      <td><?= $this->e($c['zone']) ?></td>
      <td><a href="/console/nxp/<?= $this->e($c['uuid']) ?>/edit"><?= $missing ? 'Complete' : 'Open' ?></a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/nxp',
    'query'      => $filters,
]) ?>
