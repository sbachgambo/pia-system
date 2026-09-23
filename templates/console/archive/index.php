<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array{q:?string,kind:?string,type:?string,from:?string,to:?string} $filters
 * @var array{certificate:int,invoice:int,return:int} $counts
 * @var bool $isAdmin
 * @var int $maxFiles
 */
use App\Http\View\Icons;

$query = array_filter($filters, static fn ($v) => $v !== null && $v !== '');
$qs = http_build_query($query);
$link = static function (array $r): ?string {
    return match ($r['kind']) {
        'certificate' => '/console/inspections/' . $r['parent_uuid'] . '/documents/' . $r['uuid'],
        'invoice'     => '/console/invoices/' . $r['uuid'] . '/download',
        'return'      => '/console/statutory-returns/' . $r['uuid'] . '/download',
        default       => null,
    };
};
$statusBadge = static fn (string $s): string => [
    'issued' => 'completed', 'paid' => 'completed', 'submitted' => 'completed', 'generated' => 'pending',
    'void' => 'cancelled', 'draft' => 'pending',
][$s] ?? 'pending';
?>
<div class="page-head">
  <h1>Archive</h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="/console/archive/download<?= $qs !== '' ? '?' . $qs : '' ?>"><?= Icons::svg('download') ?> Download as ZIP</a>
  </div>
</div>
<p class="hint">Every document the system generates is filed here automatically the moment it is created — certificates<?= $isAdmin ? ', CBN invoices and statutory returns' : '' ?> —
  including voided ones, so nothing is ever lost. The ZIP holds whatever the filters below match (up to <?= $this->e($maxFiles) ?> files), with an index.</p>

<div class="archive-kinds">
  <a class="btn <?= $filters['kind'] === null ? '' : 'btn-outline' ?>" href="/console/archive">All</a>
  <a class="btn <?= $filters['kind'] === 'certificate' ? '' : 'btn-outline' ?>" href="/console/archive?kind=certificate">Certificates (<?= $this->e($counts['certificate']) ?>)</a>
  <?php if ($isAdmin): ?>
    <a class="btn <?= $filters['kind'] === 'invoice' ? '' : 'btn-outline' ?>" href="/console/archive?kind=invoice">CBN invoices (<?= $this->e($counts['invoice']) ?>)</a>
    <a class="btn <?= $filters['kind'] === 'return' ? '' : 'btn-outline' ?>" href="/console/archive?kind=return">Statutory returns (<?= $this->e($counts['return']) ?>)</a>
  <?php endif; ?>
</div>

<form class="filters" method="get" action="/console/archive">
  <?php if ($filters['kind'] !== null): ?><input type="hidden" name="kind" value="<?= $this->e($filters['kind']) ?>"><?php endif; ?>
  <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="CCI no., NXP no., invoice no., client">
  <?php if ($filters['kind'] === null || $filters['kind'] === 'certificate'): ?>
    <select name="type">
      <option value="">CCI &amp; NNCI</option>
      <option value="CCI" <?= $filters['type'] === 'CCI' ? 'selected' : '' ?>>CCI only</option>
      <option value="NNCI" <?= $filters['type'] === 'NNCI' ? 'selected' : '' ?>>NNCI only</option>
    </select>
  <?php endif; ?>
  <label class="inline">From <input type="date" name="from" value="<?= $this->e($filters['from'] ?? '') ?>"></label>
  <label class="inline">To <input type="date" name="to" value="<?= $this->e($filters['to'] ?? '') ?>"></label>
  <button type="submit" class="btn-outline">Filter</button>
  <?php if ($query !== []): ?><a href="/console/archive">Clear</a><?php endif; ?>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Number</th><th>Type</th><th>NXP no.</th><th>Client / agency</th><th>Issued</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="7" class="empty">No archived documents match.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $r): ?>
    <tr class="<?= $r['status'] === 'void' ? 'row-muted' : '' ?>">
      <td><strong class="<?= $r['kind'] === 'certificate' ? 'cci-no' : '' ?>"><?= $this->e($r['number']) ?></strong></td>
      <td><?= $this->e($r['type']) ?></td>
      <td><span class="nxp-no"><?= $this->e($r['nxp_number'] ?? '—') ?></span></td>
      <td><?= $this->e($r['client_name']) ?></td>
      <td><?= $this->d($r['issued_at']) ?></td>
      <td><span class="badge badge-<?= $statusBadge((string) $r['status']) ?>"><?= $this->e($r['status']) ?></span></td>
      <td><?php if (($href = $link($r)) !== null): ?><a href="<?= $this->e($href) ?>"><?= Icons::svg('download', 'inline-icon') ?> <?= $this->e(strtoupper((string) $r['format'])) ?></a><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', ['pagination' => $list['pagination'], 'base' => '/console/archive', 'query' => $query]) ?>
