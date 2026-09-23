<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 */
?>
<div class="page-head">
  <h1>Compliance tracking</h1>
</div>
<p class="hint">3-strikes tracking: an inspection counts as "missed" once its scheduled time plus a grace period
  has passed and it's still not synced. 3 consecutive months with a miss triggers an alert.</p>

<form class="filters" method="get" action="/console/compliance">
  <label class="inline">
    <input type="checkbox" name="alert_only" value="1" <?= ($filters['alert_only'] ?? false) ? 'checked' : '' ?>>
    Alerts only
  </label>
  <button type="submit" class="btn-outline">Filter</button>
</form>

<form method="post" action="/console/compliance/evaluate" class="inline-form">
  <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
  <input type="text" name="month" placeholder="YYYY-MM (blank = current month)">
  <button type="submit">Evaluate now</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Inspector</th><th>Zone</th><th>Month</th><th>Missed</th><th>Streak</th><th>Alert</th><th>Last evaluated</th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="7" class="empty">No tracking data yet — run "Evaluate now".</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $row): ?>
    <tr class="<?= $row['alert_triggered'] ? 'row-warn' : '' ?>">
      <td><?= $this->e($row['inspector_name']) ?></td>
      <td><?= $this->e($row['inspector_zone'] ?? '—') ?></td>
      <td><?= $this->e($row['period_month']) ?></td>
      <td><?= $this->e($row['missed_windows_count']) ?></td>
      <td><?= $this->e($row['consecutive_miss_count']) ?></td>
      <td><?= $row['alert_triggered'] ? '<span class="badge badge-rejected">alert</span>' : '' ?></td>
      <td class="hint"><?= $this->dt($row['last_evaluated_at'], true) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/compliance',
    'query'      => ['alert_only' => !empty($filters['alert_only']) ? '1' : null],
]) ?>
