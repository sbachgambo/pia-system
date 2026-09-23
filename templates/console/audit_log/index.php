<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 * @var list<string> $actions
 */
$entityTypes = ['client', 'consignment', 'inspection_request', 'inspection', 'document', 'cbn_invoice', 'invoice_settings', 'import', 'user', 'company_settings', 'export'];
// Stored entity types keep their internal names; a few read better under their business name.
$entityLabel = static fn (string $t): string => ['consignment' => 'NXP record', 'cbn_invoice' => 'CBN invoice'][$t] ?? ucfirst(str_replace('_', ' ', $t));
?>
<div class="page-head">
  <h1>Audit log</h1>
</div>
<p class="hint">Every recorded change, system-wide — append-only, nothing here can be edited or deleted.</p>

<form class="filters" method="get" action="/console/audit-log">
  <select name="action">
    <option value="">All actions</option>
    <?php foreach ($actions as $a): ?>
      <option value="<?= $this->e($a) ?>" <?= ($filters['action'] ?? '') === $a ? 'selected' : '' ?>><?= $this->e($a) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="entity_type">
    <option value="">All entity types</option>
    <?php foreach ($entityTypes as $t): ?>
      <option value="<?= $t ?>" <?= ($filters['entity_type'] ?? '') === $t ? 'selected' : '' ?>><?= $this->e($entityLabel($t)) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>IP</th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="5" class="empty">No audit events recorded yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $r): ?>
    <tr>
      <td class="hint"><?= $this->e($r['at']) ?></td>
      <td><?= $this->e($r['actor_name'] ?? 'system') ?></td>
      <td><code><?= $this->e($r['action']) ?></code></td>
      <td><?= $this->e($entityLabel((string) $r['entity_type'])) ?> <small>#<?= $this->e($r['entity_id']) ?></small></td>
      <td class="hint"><?= $this->e($r['ip_address'] ?? '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/audit-log',
    'query'      => $filters,
]) ?>
