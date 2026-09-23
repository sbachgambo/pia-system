<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var list<array{name:string,bytes:int,created_at:string,sha256:?string}> $backups
 * @var bool $hasZip
 */
use App\Http\View\Icons;

$size = static fn (int $b): string => $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
?>
<div class="page-head">
  <h1>Backups</h1>
  <form method="post" action="/console/backups" data-confirm="Create a new backup now? This can take a little while on a large database.">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <button type="submit"><?= Icons::svg('plus') ?> Create backup now</button>
  </form>
</div>

<p class="hint">A backup is a single archive holding the whole database plus the generated documents and uploaded files.
  It contains <strong>every password hash and all client data</strong> — download it, store it somewhere safe <em>off this server</em>,
  and never email or share it. Only super admins can see this page.</p>

<?php if (!$hasZip): ?>
  <div class="flash flash-error">This server has no zip support: backups contain the database only. Copy <code>storage/</code> separately (see DEPLOYMENT.md).</div>
<?php endif; ?>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Backup</th><th>Created (UTC)</th><th>Size</th><th>Checksum (SHA-256)</th><th></th></tr></thead>
  <tbody>
  <?php if (!$backups): ?>
    <tr><td colspan="5" class="empty">No backups yet. Create one now, and schedule <code>php bin/backup.php</code> as a nightly cron job.</td></tr>
  <?php endif; ?>
  <?php foreach ($backups as $b): ?>
    <tr>
      <td><?= Icons::svg('file', 'inline-icon') ?> <code><?= $this->e($b['name']) ?></code></td>
      <td class="hint"><?= $this->dt($b['created_at'], true) ?></td>
      <td class="hint"><?= $this->e($size($b['bytes'])) ?></td>
      <td class="hint"><code><?= $this->e($b['sha256'] !== null ? substr($b['sha256'], 0, 16) . '…' : 'unknown') ?></code></td>
      <td>
        <a href="/console/backups/download?name=<?= $this->e(rawurlencode($b['name'])) ?>">Download</a>
        <form method="post" action="/console/backups/delete" class="inline-form" data-confirm="Delete this backup permanently?">
          <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
          <input type="hidden" name="name" value="<?= $this->e($b['name']) ?>">
          <button type="submit" class="btn-danger btn-sm">Delete</button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<section class="actions">
  <h2>Restoring</h2>
  <p class="hint">There is deliberately no restore button — overwriting a live database should be a hands-on step.
    Unzip the archive, run <code>mysql &lt; database.sql</code> against an empty (or the target) database, copy the
    <code>files/</code> folders back into <code>storage/</code>, then verify the checksum above matches. Full steps are in DEPLOYMENT.md.</p>
</section>
