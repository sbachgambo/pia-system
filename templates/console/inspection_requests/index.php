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
  <h1>Inspection requests</h1>
  <a class="btn" href="/console/inspection-requests/new"><?= \App\Http\View\Icons::svg('plus') ?> New request</a>
</div>

<form class="filters" method="get" action="/console/inspection-requests">
  <select name="status">
    <option value="">Any status</option>
    <?php foreach (['pending', 'scheduled', 'completed', 'cancelled'] as $s): ?>
      <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <label class="inline"><input type="checkbox" name="overdue" value="1" <?= !empty($filters['overdue']) ? 'checked' : '' ?>> Overdue only</label>
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>NXP no.</th><th>Client / product</th><th>Requested</th><th>Notice deadline</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="6" class="empty">No inspection requests.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $ir): ?>
    <?php $overdue = in_array($ir['status'], ['pending', 'scheduled'], true) && $ir['notice_deadline'] < gmdate('c'); ?>
    <tr class="<?= $overdue ? 'row-warn' : '' ?>">
      <td><strong class="nxp-no"><?= $this->e($ir['nxp_number'] ?? '—') ?></strong></td>
      <td><?= $this->e($ir['client_name'] ?: '—') ?><br><small><?= $this->e($ir['product_category']) ?></small></td>
      <td><?= $this->dt($ir['requested_at']) ?></td>
      <td><?= $this->dt($ir['notice_deadline']) ?><?= $overdue ? ' <span class="warn-text">overdue</span>' : '' ?></td>
      <td><?= $badge((string) $ir['status']) ?></td>
      <td><a href="/console/inspection-requests/<?= $this->e($ir['uuid']) ?>">Open</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/inspection-requests',
    'query'      => ['status' => $filters['status'] ?? null, 'overdue' => !empty($filters['overdue']) ? '1' : null],
]) ?>
