<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 */
$badge = static fn (string $s): string => '<span class="badge badge-' . htmlspecialchars($s, ENT_QUOTES) . '">'
    . htmlspecialchars($s, ENT_QUOTES) . '</span>';
?>
<div class="page-head">
  <h1>Inspection review</h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="/console/inspections/export<?= ($qs = http_build_query(array_filter(['status' => $filters['status'] ?? null], static fn ($v) => $v !== null && $v !== ''))) !== '' ? '?' . $qs : '' ?>"><?= \App\Http\View\Icons::svg('download') ?> Export CSV</a>
  </div>
</div>

<form class="filters" method="get" action="/console/inspections">
  <select name="status">
    <option value="">Awaiting / in review</option>
    <?php foreach (['synced', 'amended', 'rejected', 'finalized'] as $s): ?>
      <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>NXP no.</th><th>Client / product</th><th>CCI no.</th><th>Inspector</th><th>Synced</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="7" class="empty">Nothing to review.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $i): ?>
    <tr>
      <td><strong class="nxp-no"><?= $this->e($i['nxp_number'] ?? '—') ?></strong></td>
      <td><?= $this->e($i['client_name'] ?: '—') ?><br><small><?= $this->e($i['product_category']) ?></small></td>
      <td><?= !empty($i['cci_number']) ? '<strong class="cci-no">' . $this->e($i['cci_number']) . '</strong>' : '<span class="hint">—</span>' ?></td>
      <td><?= $this->e($i['inspector_name'] ?: '—') ?></td>
      <td><?= $this->dt($i['synced_at'] ?? null) ?></td>
      <td><?= $badge((string) $i['status']) ?></td>
      <td><a href="/console/inspections/<?= $this->e($i['uuid']) ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/inspections',
    'query'      => ['status' => $filters['status'] ?? null],
]) ?>
