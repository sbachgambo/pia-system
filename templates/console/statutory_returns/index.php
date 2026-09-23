<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 */
$agencies = ['CBN', 'NEPC', 'NBS', 'MOF', 'CUSTOMS'];
?>
<div class="page-head">
  <h1>Statutory returns</h1>
</div>
<p class="hint">CBN and NBS use the client's real report layout. NEPC/MOF/Customs use a generic placeholder register
  until a real layout is confirmed — see DEV_NOTES.md.</p>

<section class="actions">
  <h2>Generate a return</h2>
  <form method="post" action="/console/statutory-returns/generate" class="record-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <div class="row">
      <label>Agency
        <select name="agency" required>
          <?php foreach ($agencies as $a): ?><option value="<?= $a ?>"><?= $a ?></option><?php endforeach; ?>
        </select>
      </label>
      <label>Period start <input type="date" name="period_start" required></label>
      <label>Period end <input type="date" name="period_end" required></label>
    </div>
    <button type="submit">Generate</button>
  </form>
</section>

<form class="filters" method="get" action="/console/statutory-returns">
  <select name="agency">
    <option value="">All agencies</option>
    <?php foreach ($agencies as $a): ?>
      <option value="<?= $a ?>" <?= ($filters['agency'] ?? '') === $a ? 'selected' : '' ?>><?= $a ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <option value="">Any status</option>
    <?php foreach (['generated', 'submitted'] as $s): ?>
      <option value="<?= $s ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Agency</th><th>Period</th><th>Rows</th><th>Status</th><th>Generated</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="6" class="empty">No returns generated yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $r): ?>
    <tr>
      <td><?= $this->e($r['agency']) ?></td>
      <td><?= $this->e($r['period_start']) ?> &ndash; <?= $this->e($r['period_end']) ?></td>
      <td><?= $this->e($r['row_count']) ?></td>
      <td><span class="badge badge-<?= $this->e($r['status']) === 'submitted' ? 'completed' : 'scheduled' ?>"><?= $this->e($r['status']) ?></span></td>
      <td class="hint"><?= $this->dt($r['generated_at'], true) ?> by <?= $this->e($r['generated_by_name'] ?? 'system') ?></td>
      <td>
        <a href="/console/statutory-returns/<?= $this->e($r['uuid']) ?>/download">Download</a>
        <?php if ($r['status'] !== 'submitted'): ?>
          <form method="post" action="/console/statutory-returns/<?= $this->e($r['uuid']) ?>/submit" class="inline-form">
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
            <button type="submit">Mark submitted</button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/statutory-returns',
    'query'      => ['agency' => $filters['agency'] ?? null, 'status' => $filters['status'] ?? null],
]) ?>
