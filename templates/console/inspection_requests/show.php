<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array<string,mixed> $ir
 * @var list<array<string,mixed>> $inspections  inspections raised under this request
 * @var list<array{uuid:string,full_name:string,email:string,zone:?string}> $inspectors  active inspectors
 */
$transitions = [
    'pending'   => ['scheduled' => 'Mark scheduled', 'cancelled' => 'Cancel'],
    'scheduled' => ['completed' => 'Mark completed', 'cancelled' => 'Cancel'],
    'completed' => [],
    'cancelled' => [],
][$ir['status']] ?? [];
$open = in_array($ir['status'], ['pending', 'scheduled'], true);
$overdue = $open && $ir['notice_deadline'] < gmdate('c');
// A request can be (re)assigned while open, unless an inspection under it is still live.
$live = array_filter($inspections, static fn (array $i): bool => $i['status'] !== 'rejected');
$canAssign = $open && $live === [];
?>
<div class="page-head">
  <h1>Inspection request</h1>
  <a href="/console/inspection-requests">Back to list</a>
</div>

<dl class="detail">
  <dt>NXP number</dt><dd><?php if (!empty($ir['nxp_number'])): ?><a href="/console/nxp/<?= $this->e($ir['consignment_uuid']) ?>/edit"><strong class="nxp-no"><?= $this->e($ir['nxp_number']) ?></strong></a><?php else: ?><a href="/console/nxp/<?= $this->e($ir['consignment_uuid']) ?>/edit">View NXP record</a><?php endif; ?></dd>
  <dt>Client</dt><dd><?= $this->e($ir['client_name']) ?> — <?= $this->e($ir['product_category']) ?></dd>
  <dt>Status</dt><dd><span class="badge badge-<?= $this->e($ir['status']) ?>"><?= $this->e($ir['status']) ?></span></dd>
  <dt>Requested at</dt><dd><?= $this->dt($ir['requested_at'], true) ?></dd>
  <dt>Notice deadline</dt><dd><?= $this->dt($ir['notice_deadline'], true) ?><?= $overdue ? ' <strong class="warn-text">overdue</strong>' : '' ?></dd>
  <dt>Requested by</dt><dd><?= $this->e($ir['requested_by_name'] !== '' ? $ir['requested_by_name'] : '—') ?></dd>
  <dt>Reference</dt><dd><code class="record-ref"><?= $this->e($ir['uuid']) ?></code></dd>
</dl>

<section class="card">
  <h2>Inspection</h2>
  <?php if ($inspections === []): ?>
    <p class="hint">No inspector assigned yet.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>Scheduled (UTC)</th><th>Inspector</th><th>Location</th><th>Status</th><th>CCI no.</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($inspections as $i): ?>
        <tr>
          <td><?= $this->dt($i['scheduled_at']) ?></td>
          <td><?= $this->e($i['inspector_name']) ?></td>
          <td><?= $this->e(ucfirst((string) $i['location_type'])) ?> — <?= $this->e($i['location_detail']) ?></td>
          <td><span class="badge badge-<?= $this->e($i['status']) ?>"><?= $this->e(str_replace('_', ' ', (string) $i['status'])) ?></span></td>
          <td><?= !empty($i['cci_number']) ? '<span class="cci-no">' . $this->e($i['cci_number']) . '</span>' : '—' ?></td>
          <td><a href="/console/inspections/<?= $this->e($i['uuid']) ?>">Open</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <?php if ($canAssign): ?>
    <h3><?= $inspections === [] ? 'Assign an inspector' : 'Assign again (the last inspection was rejected)' ?></h3>
    <?php if ($inspectors === []): ?>
      <p class="warn-text">There are no active inspectors. Add one under Users first.</p>
    <?php else: ?>
    <form method="post" action="/console/inspection-requests/<?= $this->e($ir['uuid']) ?>/schedule" class="record-form nobox">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <div class="row">
        <label>Inspector
          <select name="inspector_uuid" required>
            <option value="">— select —</option>
            <?php foreach ($inspectors as $u): ?>
              <option value="<?= $this->e($u['uuid']) ?>"><?= $this->e($u['full_name']) ?><?= $u['zone'] ? ' (' . $this->e($u['zone']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Date and time (UTC)
          <input type="datetime-local" name="scheduled_at" required>
        </label>
      </div>
      <div class="row">
        <label>Location type
          <select name="location_type" required>
            <option value="factory">Factory</option>
            <option value="warehouse">Warehouse</option>
            <option value="port">Port</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label>Location
          <input name="location_detail" maxlength="255" required placeholder="e.g. Apapa port, bay 4">
        </label>
      </div>
      <button type="submit">Assign inspector</button>
      <p class="hint">The inspection appears on the inspector's app at their next sync; the request moves to "scheduled".</p>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($transitions): ?>
<section class="actions">
  <h2>Change status</h2>
  <?php foreach ($transitions as $to => $label): ?>
    <form method="post" action="/console/inspection-requests/<?= $this->e($ir['uuid']) ?>/transition" class="inline-form">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <input type="hidden" name="status" value="<?= $this->e($to) ?>">
      <button type="submit" class="<?= $to === 'cancelled' ? 'btn-danger' : 'btn-outline' ?>"><?= $this->e($label) ?></button>
    </form>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($open): ?>
<section class="actions">
  <h2>Reschedule</h2>
  <form method="post" action="/console/inspection-requests/<?= $this->e($ir['uuid']) ?>/reschedule" class="inline-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <input type="datetime-local" name="requested_at" required>
    <button type="submit" class="btn-outline">Reschedule</button>
  </form>
</section>
<?php endif; ?>
