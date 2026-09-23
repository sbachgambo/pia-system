<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{review:int,overdue:int,due_soon:int,alerts:int,total:int} $counts
 * @var array{review:list<array<string,mixed>>,overdue:list<array<string,mixed>>,due_soon:list<array<string,mixed>>,alerts:list<array<string,mixed>>} $items
 * @var bool $isAdmin
 */
$short = static fn (?string $v): string => $v ? (new DateTimeImmutable($v, new DateTimeZone('UTC')))->format('j M Y, H:i') : '—';
?>
<div class="page-head">
  <h1>Needs attention</h1>
</div>
<p class="hint">Live list of work waiting on someone. Items disappear on their own as soon as the underlying task is dealt with.</p>

<?php if ($counts['total'] === 0): ?>
  <div class="empty-state">All clear — nothing needs attention right now.</div>
<?php endif; ?>

<?php if ($items['review']): ?>
<section class="result-group">
  <h2>Inspections awaiting review <span class="badge badge-synced"><?= $this->e($counts['review']) ?></span></h2>
  <ul class="result-list">
    <?php foreach ($items['review'] as $r): ?>
      <li><a href="/console/inspections/<?= $this->e($r['uuid']) ?>"><strong><?= $this->e($r['client_name']) ?></strong> — <?= $this->e($r['product_category']) ?></a>
        <span class="hint">by <?= $this->e($r['inspector_name']) ?> · synced <?= $this->e($short($r['synced_at'])) ?> UTC · <?= $this->e($r['status']) ?></span></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($counts['review'] > count($items['review'])): ?><p class="hint"><a href="/console/inspections">See all <?= $this->e($counts['review']) ?> in the review queue</a></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($items['overdue']): ?>
<section class="result-group">
  <h2>Requests past their notice deadline <span class="badge badge-cancelled"><?= $this->e($counts['overdue']) ?></span></h2>
  <ul class="result-list">
    <?php foreach ($items['overdue'] as $r): ?>
      <li><a href="/console/inspection-requests/<?= $this->e($r['uuid']) ?>"><strong><?= $this->e($r['client_name']) ?></strong> — <?= $this->e($r['product_category']) ?></a>
        <span class="hint warn-text">deadline was <?= $this->e($short($r['notice_deadline'])) ?> UTC</span></li>
    <?php endforeach; ?>
  </ul>
  <?php if ($counts['overdue'] > count($items['overdue'])): ?><p class="hint"><a href="/console/inspection-requests?overdue=1">See all <?= $this->e($counts['overdue']) ?> overdue requests</a></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($items['due_soon']): ?>
<section class="result-group">
  <h2>Unscheduled requests due within 24 hours <span class="badge badge-pending"><?= $this->e($counts['due_soon']) ?></span></h2>
  <ul class="result-list">
    <?php foreach ($items['due_soon'] as $r): ?>
      <li><a href="/console/inspection-requests/<?= $this->e($r['uuid']) ?>"><strong><?= $this->e($r['client_name']) ?></strong> — <?= $this->e($r['product_category']) ?></a>
        <span class="hint">due <?= $this->e($short($r['notice_deadline'])) ?> UTC</span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($isAdmin && $items['alerts']): ?>
<section class="result-group">
  <h2>Compliance alerts <span class="badge badge-cancelled"><?= $this->e($counts['alerts']) ?></span></h2>
  <ul class="result-list">
    <?php foreach ($items['alerts'] as $r): ?>
      <li><a href="/console/compliance"><strong><?= $this->e($r['full_name']) ?></strong></a>
        <span class="hint warn-text"><?= $this->e($r['consecutive_miss_count']) ?> consecutive months with missed inspections (as of <?= $this->e(substr((string) $r['period_month'], 0, 7)) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
