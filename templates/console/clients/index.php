<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 */
?>
<div class="page-head">
  <h1>Clients</h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="/console/clients/export<?= ($qs = http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== ''))) !== '' ? '?' . $qs : '' ?>"><?= \App\Http\View\Icons::svg('download') ?> Export CSV</a>
    <a class="btn" href="/console/clients/new"><?= \App\Http\View\Icons::svg('plus') ?> New client</a>
  </div>
</div>

<form class="filters" method="get" action="/console/clients">
  <select name="type">
    <option value="">All types</option>
    <option value="exporter" <?= ($filters['type'] ?? '') === 'exporter' ? 'selected' : '' ?>>Exporter</option>
    <option value="importer" <?= ($filters['type'] ?? '') === 'importer' ? 'selected' : '' ?>>Importer</option>
  </select>
  <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Search name / contact">
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Name</th><th>Type</th><th>RC number</th><th>Contact</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="5" class="empty">No clients yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $c): ?>
    <tr>
      <td><?= $this->e($c['name']) ?></td>
      <td><?= $this->e($c['type']) ?></td>
      <td><?= $this->e($c['rc_number'] ?? '—') ?></td>
      <td><?= $this->e($c['contact_name']) ?><br><small><?= $this->e($c['contact_email']) ?></small></td>
      <td><a href="/console/clients/<?= $this->e($c['uuid']) ?>/edit">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/clients',
    'query'      => $filters,
]) ?>
