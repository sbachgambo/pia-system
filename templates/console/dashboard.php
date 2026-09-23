<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array<string,int> $stats
 * @var list<array{label:string,scheduled:int,finalized:int}> $monthly
 * @var array<string,int> $statuses
 * @var int|null $alerts   null for non-admins
 * @var list<array<string,mixed>> $activity   admin / board only
 * @var array<string,mixed>|null $income     admin / board only: the income card's data
 * @var bool $readOnly                        board members can't create records
 */
use App\Http\View\Charts;

$icon = static fn (string $name, string $class = ''): string => \App\Http\View\Icons::svg($name, $class);
?>
<h1>Dashboard</h1>

<div class="stat-grid">
  <div class="stat"><span class="stat-icon"><?= $icon('users') ?></span><span class="n"><?= $this->e($stats['clients']) ?></span><span class="l">Clients</span></div>
  <div class="stat"><span class="stat-icon"><?= $icon('package') ?></span><span class="n"><?= $this->e($stats['consignments']) ?></span><span class="l">NXP records</span></div>
  <div class="stat"><span class="stat-icon"><?= $icon('clipboard') ?></span><span class="n"><?= $this->e($stats['pending']) ?></span><span class="l">Requests pending</span></div>
  <div class="stat"><span class="stat-icon"><?= $icon('calendar') ?></span><span class="n"><?= $this->e($stats['scheduled']) ?></span><span class="l">Requests scheduled</span></div>
  <div class="stat <?= $stats['overdue'] > 0 ? 'warn' : '' ?>"><span class="stat-icon"><?= $icon('alert') ?></span><span class="n"><?= $this->e($stats['overdue']) ?></span><span class="l">Overdue</span></div>
</div>

<?php if ($income !== null): ?>
  <?= $this->partial('console/income/_card', $income) ?>
<?php endif; ?>

<div class="chart-grid">
  <section class="card">
    <h2>Inspections per month</h2>
    <?= array_sum(array_map(static fn ($m) => $m["scheduled"] + $m["finalized"], $monthly)) === 0
        ? '<div class="empty-state">No inspection activity in the last 6 months yet.</div>'
        : Charts::monthlyBars($monthly, "scheduled", "finalised") ?>
    <div class="chart-legend"><span><i class="dot-a"></i> Scheduled</span><span><i class="dot-b"></i> Finalised</span></div>
  </section>
  <section class="card">
    <h2>Requests by status</h2>
    <?= Charts::hbars($statuses) ?>
    <?php if ($alerts !== null): ?>
      <p class="chart-note <?= $alerts > 0 ? "warn-text" : "" ?>">
        <?= $alerts > 0
            ? $this->e($alerts) . " inspector(s) currently on a compliance alert — <a href=\"/console/compliance\">review</a>"
            : "No active compliance alerts." ?>
      </p>
    <?php endif; ?>
  </section>
</div>

<?php if (!$readOnly): ?>
<div class="quick-links">
  <a class="btn btn-outline" href="/console/clients/new"><?= $icon('plus') ?> New client</a>
  <a class="btn btn-outline" href="/console/nxp/new"><?= $icon('plus') ?> New NXP</a>
  <a class="btn btn-outline" href="/console/inspection-requests/new"><?= $icon('plus') ?> New inspection request</a>
</div>
<?php endif; ?>

<?php if ($activity): ?>
<section class="card activity">
  <div class="card-head"><h2>Recent activity</h2><a href="/console/audit-log">View audit log</a></div>
  <ul class="activity-list">
    <?php foreach ($activity as $a): ?>
      <li>
        <span class="activity-dot"></span>
        <span><strong><?= $this->e($a["actor_name"] ?? "System") ?></strong> <code><?= $this->e($a["action"]) ?></code></span>
        <span class="hint"><?= $this->dt($a["at"], true) ?></span>
      </li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>