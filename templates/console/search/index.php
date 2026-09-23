<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $q
 * @var bool $tooShort
 * @var array{clients:list<array<string,mixed>>,consignments:list<array<string,mixed>>,inspections:list<array<string,mixed>>,documents:list<array<string,mixed>>,users:list<array<string,mixed>>} $results
 */
$total = array_sum(array_map('count', $results));
$date = static fn (?string $v): string => $v ? (new DateTimeImmutable($v, new DateTimeZone('UTC')))->format('j M Y') : '—';
?>
<div class="page-head">
  <h1>Search</h1>
</div>

<form class="filters" method="get" action="/console/search" role="search">
  <input type="search" name="q" value="<?= $this->e($q) ?>" placeholder="Client, product, HS code, document number…" autofocus>
  <button type="submit" class="btn-outline">Search</button>
</form>

<?php if ($q === ''): ?>
  <p class="hint">Search by NXP number, CCI number, client, product or HS code.</p>
<?php elseif ($tooShort): ?>
  <p class="hint">Type at least 2 characters.</p>
<?php elseif ($total === 0): ?>
  <div class="empty-state">No matches for “<?= $this->e($q) ?>”.</div>
<?php endif; ?>

<?php if ($results['clients']): ?>
<section class="result-group">
  <h2>Clients</h2>
  <ul class="result-list">
    <?php foreach ($results['clients'] as $r): ?>
      <li><a href="/console/clients/<?= $this->e($r['uuid']) ?>/edit"><strong><?= $this->e($r['name']) ?></strong></a>
        <span class="hint"><?= $this->e($r['type']) ?><?= $r['rc_number'] ? ' · RC ' . $this->e($r['rc_number']) : '' ?> · <?= $this->e($r['contact_name']) ?> (<?= $this->e($r['contact_email']) ?>)</span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($results['consignments']): ?>
<section class="result-group">
  <h2>NXP records</h2>
  <ul class="result-list">
    <?php foreach ($results['consignments'] as $r): ?>
      <li><a href="/console/nxp/<?= $this->e($r['uuid']) ?>/edit"><strong class="nxp-no"><?= $this->e($r['form_nxp_number'] ?? 'No NXP no.') ?></strong> — <?= $this->e($r['product_category']) ?></a>
        <span class="hint"><?= $this->e($r['client_name']) ?><?= $r['hs_code'] ? ' · HS ' . $this->e($r['hs_code']) : '' ?> · <?= $this->e($r['zone']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($results['inspections']): ?>
<section class="result-group">
  <h2>Inspections</h2>
  <ul class="result-list">
    <?php foreach ($results['inspections'] as $r): ?>
      <li><a href="/console/inspections/<?= $this->e($r['uuid']) ?>"><?= $r['form_nxp_number'] ? '<strong class="nxp-no">' . $this->e($r['form_nxp_number']) . '</strong> · ' : '' ?><strong><?= $this->e($r['client_name']) ?></strong> — <?= $this->e($r['product_category']) ?></a>
        <span class="badge badge-<?= $this->e($r['status']) ?>"><?= $this->e($r['status']) ?></span>
        <span class="hint">scheduled <?= $this->e($date($r['scheduled_at'])) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($results['documents']): ?>
<section class="result-group">
  <h2>Documents</h2>
  <ul class="result-list">
    <?php foreach ($results['documents'] as $r): ?>
      <li><a href="/console/inspections/<?= $this->e($r['inspection_uuid']) ?>"><strong><?= $this->e($r['type']) ?> <?= $this->e($r['document_number']) ?></strong></a>
        <span class="hint"><?= $this->e($r['client_name']) ?> · issued <?= $this->e($date($r['issued_at'])) ?> · <?= $this->e($r['status']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>

<?php if ($results['users']): ?>
<section class="result-group">
  <h2>Users</h2>
  <ul class="result-list">
    <?php foreach ($results['users'] as $r): ?>
      <li><a href="/console/users/<?= $this->e($r['uuid']) ?>/edit"><strong><?= $this->e($r['full_name']) ?></strong></a>
        <span class="hint"><?= $this->e($r['email']) ?> · <?= $this->e(str_replace('_', ' ', $r['role'])) ?> · <?= $this->e($r['status']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<?php endif; ?>
