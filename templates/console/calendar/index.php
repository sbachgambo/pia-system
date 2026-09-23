<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var DateTimeImmutable $first        first day of the displayed month
 * @var DateTimeImmutable $gridStart    Monday of the first displayed week
 * @var DateTimeImmutable $gridEnd      Sunday of the last displayed week
 * @var string $today                   Y-m-d
 * @var array<string,list<array<string,mixed>>> $byDay
 * @var list<array{uuid:string,name:string}> $inspectors
 * @var string $inspector
 * @var string|null $selected
 */
use App\Http\View\Icons;

$monthKey = $first->format('Y-m');
$url = static fn (array $q): string => '/console/calendar?' . http_build_query(array_filter($q, static fn ($v) => $v !== null && $v !== ''));
$base = ['inspector' => $inspector];
$prev = $url($base + ['month' => $first->modify('-1 month')->format('Y-m')]);
$next = $url($base + ['month' => $first->modify('+1 month')->format('Y-m')]);
$thisMonth = $url($base);
$time = static fn (string $at): string => substr($at, 11, 5);
$eventClass = static fn (array $e): string => 'cal-ev cal-' . preg_replace('/[^a-z_]/', '', (string) $e['status']);
$label = static fn (array $e): string => ($e['kind'] === 'deadline' ? 'Deadline · ' : '') . $e['client'];
$days = [];
for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')) {
    $days[] = $d;
}
$agendaDays = $selected !== null ? [$selected] : array_keys(array_filter(
    $byDay,
    static fn ($_, $k) => str_starts_with((string) $k, $monthKey),
    ARRAY_FILTER_USE_BOTH,
));
sort($agendaDays);
?>
<div class="page-head">
  <h1>Calendar</h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="<?= $this->e($prev) ?>" aria-label="Previous month">&larr;</a>
    <strong class="cal-month"><?= $this->e($first->format('F Y')) ?></strong>
    <a class="btn btn-outline" href="<?= $this->e($next) ?>" aria-label="Next month">&rarr;</a>
    <a class="btn btn-outline" href="<?= $this->e($thisMonth) ?>">Today</a>
  </div>
</div>

<form class="filters" method="get" action="/console/calendar">
  <input type="hidden" name="month" value="<?= $this->e($monthKey) ?>">
  <select name="inspector" aria-label="Inspector">
    <option value="">All inspectors</option>
    <?php foreach ($inspectors as $i): ?>
      <option value="<?= $this->e($i['uuid']) ?>" <?= $inspector === $i['uuid'] ? 'selected' : '' ?>><?= $this->e($i['name']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit" class="btn-outline">Filter</button>
  <span class="hint cal-legend">
    <i class="cal-key cal-scheduled"></i> Scheduled
    <i class="cal-key cal-synced"></i> Awaiting review
    <i class="cal-key cal-finalized"></i> Finalised
    <i class="cal-key cal-deadline"></i> Request deadline · times in UTC
  </span>
</form>

<div class="cal-grid" role="grid" aria-label="<?= $this->e($first->format('F Y')) ?>">
  <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $w): ?>
    <div class="cal-dow" role="columnheader"><?= $w ?></div>
  <?php endforeach; ?>
  <?php foreach ($days as $d): ?>
    <?php
      $key = $d->format('Y-m-d');
      $events = $byDay[$key] ?? [];
      $other = $d->format('Y-m') !== $monthKey;
    ?>
    <div class="cal-cell<?= $other ? ' cal-other' : '' ?><?= $key === $today ? ' cal-today' : '' ?><?= $key === $selected ? ' cal-selected' : '' ?>" role="gridcell">
      <a class="cal-num" href="<?= $this->e($url($base + ['month' => $monthKey, 'day' => $key])) ?>"><?= $this->e($d->format('j')) ?></a>
      <?php foreach (array_slice($events, 0, 3) as $e): ?>
        <a class="<?= $this->e($eventClass($e)) ?>" href="<?= $this->e($e['href']) ?>" title="<?= $this->e($label($e) . ' — ' . $e['product']) ?>">
          <span class="cal-t"><?= $this->e($time($e['at'])) ?></span> <?= $this->e($label($e)) ?>
        </a>
      <?php endforeach; ?>
      <?php if (count($events) > 3): ?>
        <a class="cal-more" href="<?= $this->e($url($base + ['month' => $monthKey, 'day' => $key])) ?>">+<?= $this->e(count($events) - 3) ?> more</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<section class="card cal-agenda">
  <div class="card-head">
    <h2><?= $selected !== null ? $this->e((new DateTimeImmutable($selected))->format('l j F Y')) : 'This month' ?></h2>
    <?php if ($selected !== null): ?><a href="<?= $this->e($url($base + ['month' => $monthKey])) ?>">Show whole month</a><?php endif; ?>
  </div>
  <?php if (!$agendaDays): ?>
    <p class="hint">Nothing scheduled<?= $selected !== null ? ' on this day' : ' this month' ?>.</p>
  <?php endif; ?>
  <?php foreach ($agendaDays as $key): ?>
    <h3 class="cal-agenda-day"><?= $this->e((new DateTimeImmutable((string) $key))->format('D j M')) ?></h3>
    <ul class="activity-list">
      <?php foreach ($byDay[$key] ?? [] as $e): ?>
        <li>
          <span class="activity-dot cal-dot-<?= $this->e(preg_replace('/[^a-z_]/', '', (string) $e['status'])) ?>"></span>
          <span><a href="<?= $this->e($e['href']) ?>"><strong><?= $this->e($e['client']) ?></strong></a> — <?= $this->e($e['product']) ?>
            <?php if ($e['kind'] === 'deadline'): ?><span class="badge badge-pending">notice deadline</span>
            <?php else: ?><span class="badge badge-<?= $this->e($e['status']) ?>"><?= $this->e(str_replace('_', ' ', $e['status'])) ?></span> <span class="hint"><?= $this->e($e['inspector']) ?></span><?php endif; ?></span>
          <span class="hint"><?= $this->e($time($e['at'])) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>
</section>
