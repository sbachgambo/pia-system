<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{blocked_windows:int,distinct_ips:int,total_hits:int} $summary
 * @var list<array{path:string,ip:string,hits:int,window:string,blocked:bool}> $buckets
 * @var int $sessions
 * @var array{active:int,suspended:int,never_signed_in:int,admins:int} $hygiene
 * @var list<array{uuid:string,full_name:string,role:string,last_login_at:string}> $signins
 * @var array{max:int,minutes:int} $limit
 */
use App\Http\View\Icons;
?>
<div class="page-head">
  <h1>Security</h1>
</div>
<p class="hint">Sign-in throttling and account hygiene at a glance. Login attempts are limited to
  <?= $this->e($limit['max']) ?> per <?= $this->e($limit['minutes']) ?> minutes per address; the figures below cover the last 24 hours.</p>

<div class="stat-grid">
  <div class="stat <?= $summary['blocked_windows'] > 0 ? 'warn' : '' ?>"><span class="stat-icon"><?= Icons::svg('lock') ?></span><span class="n"><?= $this->e($summary['blocked_windows']) ?></span><span class="l">Throttled windows (429s)</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('search') ?></span><span class="n"><?= $this->e($summary['distinct_ips']) ?></span><span class="l">Distinct addresses</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('history') ?></span><span class="n"><?= $this->e($summary['total_hits']) ?></span><span class="l">Sign-in attempts</span></div>
  <div class="stat"><span class="stat-icon"><?= Icons::svg('clipboard') ?></span><span class="n"><?= $this->e($sessions) ?></span><span class="l">Live inspector sessions</span></div>
  <div class="stat <?= $hygiene['suspended'] > 0 ? 'warn' : '' ?>"><span class="stat-icon"><?= Icons::svg('user-cog') ?></span><span class="n"><?= $this->e($hygiene['suspended']) ?></span><span class="l">Suspended accounts</span></div>
  <div class="stat <?= $hygiene['never_signed_in'] > 0 ? 'warn' : '' ?>"><span class="stat-icon"><?= Icons::svg('users') ?></span><span class="n"><?= $this->e($hygiene['never_signed_in']) ?></span><span class="l">Never signed in</span></div>
</div>

<section class="actions">
  <h2>Busiest sign-in sources <small>last 24 hours</small></h2>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Address</th><th>Endpoint</th><th>Attempts</th><th>Window starting (UTC)</th><th>Outcome</th></tr></thead>
    <tbody>
    <?php if (!$buckets): ?>
      <tr><td colspan="5" class="empty">No sign-in attempts recorded in the last 24 hours.</td></tr>
    <?php endif; ?>
    <?php foreach ($buckets as $b): ?>
      <tr class="<?= $b['blocked'] ? 'row-warn' : '' ?>">
        <td><code><?= $this->e($b['ip']) ?></code></td>
        <td><code><?= $this->e($b['path']) ?></code></td>
        <td><?= $this->e($b['hits']) ?></td>
        <td class="hint"><?= $this->e($b['window']) ?></td>
        <td><span class="badge badge-<?= $b['blocked'] ? 'cancelled' : 'completed' ?>"><?= $b['blocked'] ? 'throttled' : 'ok' ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="actions">
  <h2>Most recent sign-ins</h2>
  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>User</th><th>Role</th><th>Signed in (UTC)</th></tr></thead>
    <tbody>
    <?php if (!$signins): ?>
      <tr><td colspan="3" class="empty">Nobody has signed in yet.</td></tr>
    <?php endif; ?>
    <?php foreach ($signins as $s): ?>
      <tr>
        <td><a href="/console/users/<?= $this->e($s['uuid']) ?>/edit"><?= $this->e($s['full_name']) ?></a></td>
        <td><?= $this->e(str_replace('_', ' ', $s['role'])) ?></td>
        <td class="hint"><?= $this->dt($s['last_login_at'], true, 'never') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
