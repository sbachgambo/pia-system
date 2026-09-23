<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var string $type
 * @var array<string,array{0:string,1:string,2:string}> $types  ImportService::LABELS
 * @var array{required:list<string>,optional:list<string>,one_of:list<string>} $columns
 * @var array<string,mixed>|null $result
 * @var string|null $error
 * @var int $maxRows
 */
[$title, , $label] = $types[$type];
$updates = in_array($type, ['trade_details', 'shipment_details'], true);
?>
<div class="page-head">
  <h1>Import — <?= $this->e($title) ?></h1>
</div>

<nav class="seg" aria-label="What to import">
  <?php foreach ($types as $key => [$name]): ?>
    <a class="seg-item<?= $key === $type ? ' active' : '' ?>" href="/console/import?type=<?= $this->e($key) ?>"<?= $key === $type ? ' aria-current="page"' : '' ?>><?= $this->e($name) ?></a>
  <?php endforeach; ?>
</nav>
<p class="hint">Import in this order when starting from scratch: clients → NXP records → inspection requests → inspector assignments. Trade and shipment details
  update records that already exist, and can be loaded at any time.</p>

<?php if ($error): ?>
  <div class="flash flash-error"><?= $this->e($error) ?></div>
<?php endif; ?>

<section class="card">
  <h2>1. Prepare your file</h2>
  <p class="hint">A CSV saved as UTF-8, with a header row, up to <?= $this->e($maxRows) ?> rows. Column names are matched
    ignoring case, spaces and underscores, so <code>Contact Email</code> and <code>contact_email</code> both work.
    Extra columns are ignored.</p>
  <dl class="detail">
    <?php if ($columns['one_of']): ?>
      <dt>Which record</dt><dd><code><?= $this->e(implode(' or ', $columns['one_of'])) ?></code> — at least one, on every row</dd>
    <?php endif; ?>
    <?php if ($columns['required']): ?>
      <dt>Required columns</dt><dd><code><?= $this->e(implode(', ', $columns['required'])) ?></code></dd>
    <?php endif; ?>
    <?php if ($columns['optional']): ?>
      <dt>Optional columns</dt><dd><code><?= $this->e(implode(', ', $columns['optional'])) ?></code></dd>
    <?php endif; ?>
  </dl>
  <?php if ($updates): ?>
    <p class="hint"><strong>This updates existing records.</strong> Only filled-in cells are changed — a blank cell leaves the current value
      as it is, so a sheet with just two columns (for example <code>nxp_number, exchange_rate</code>) is fine. Dates may be written
      2026-09-21 or 21/09/2026; amounts may contain commas.</p>
  <?php endif; ?>
  <?php if ($type === 'inspection_requests'): ?>
    <p class="hint">One row per NXP record. <strong>record_ref</strong> is for imports, which have no NXP number — it is the
      "Record reference" shown on the NXP record's page and in the NXP CSV export. <strong>requested_at</strong> is optional
      (e.g. <code>2026-10-01 09:00</code>); left blank it is now. A record that already has an open request is refused.</p>
  <?php elseif ($type === 'assignments'): ?>
    <p class="hint">Assigns an inspector to each NXP record's <em>open</em> inspection request — the same as "Assign inspector" on
      the request page. <strong>inspector_email</strong> is the inspector's login email; <strong>scheduled_at</strong> is
      e.g. <code>2026-10-02 10:00</code> (UTC); <strong>location_type</strong> is factory, warehouse, port or other.
      A request that already has an inspector is refused.</p>
  <?php elseif ($type === 'shipment_details'): ?>
    <p class="hint">A row with <strong>cci_number</strong> updates the inspection that certificate was issued on; otherwise the NXP
      record's latest inspection is used. This is the quickest way to load <strong>exchange rates</strong>, which the CBN invoice
      and the income report need.</p>
  <?php elseif ($type === 'users'): ?>
    <p class="hint"><strong>role</strong> is one of inspector, office_reviewer, admin, super_admin, board (Board of Directors).
      Leave <strong>password</strong> blank and a random one is set: the person then uses "Forgot password" or you reset it —
      avoid keeping passwords in spreadsheets.</p>
  <?php endif; ?>
  <?php if ($type === 'nxp'): ?>
    <p class="hint"><strong>nxp_number</strong> is required for exports, must be unique, and must be left blank for imports.
      <strong>client</strong> is the client's name exactly as it appears under Clients — import or create those first.</p>
  <?php endif; ?>
  <p><a class="btn btn-outline" href="/console/import/template?type=<?= $this->e($type) ?>"><?= \App\Http\View\Icons::svg('download') ?> Download template</a></p>
</section>

<section class="card">
  <h2>2. Upload it</h2>
  <p class="hint">Uploading only checks the file — nothing is saved until you confirm what it shows.</p>
  <form method="post" action="/console/import/preview" enctype="multipart/form-data" class="record-form nobox">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <input type="hidden" name="type" value="<?= $this->e($type) ?>">
    <label>CSV file
      <input type="file" name="file" accept=".csv,text/csv" required>
    </label>
    <button type="submit">Check file</button>
  </form>
</section>

<?php if ($result !== null): ?>
<section class="card">
  <h2>3. Review<?= isset($result['filename']) ? ' — ' . $this->e((string) $result['filename']) : '' ?></h2>

  <?php if ($result['failed'] > 0): ?>
    <div class="flash flash-error">
      <?= $this->e($result['failed']) ?> of <?= $this->e($result['failed'] + $result['ok']) ?> rows have a problem.
      Nothing will be imported until every row is right — fix the file and upload it again.
    </div>
  <?php else: ?>
    <div class="flash flash-success">All <?= $this->e($result['ok']) ?> rows are good to import.</div>
  <?php endif; ?>

  <div class="table-wrap">
  <table class="grid">
    <thead><tr><th>Row</th><th>Record</th><th>Result</th></tr></thead>
    <tbody>
    <?php foreach ($result['rows'] as $r): ?>
      <tr class="<?= $r['error'] !== null ? 'row-warn' : '' ?>">
        <td><?= $this->e($r['line']) ?></td>
        <td><?= $this->e($r['label'] !== '' ? $r['label'] : '—') ?></td>
        <td><?= $r['error'] !== null
              ? '<span class="warn-text">' . $this->e($r['error']) . '</span>'
              : '<span class="badge badge-completed">ready</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($result['failed'] === 0): ?>
    <?php $verb = $updates ? 'Update' : 'Import'; ?>
    <form method="post" action="/console/import/commit" class="record-form nobox"
          data-confirm="<?= $this->e($verb) ?> <?= $this->e($result['ok']) ?> row(s)?">
      <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
      <button type="submit"><?= $this->e($verb) ?> <?= $this->e($result['ok']) ?> row<?= $result['ok'] === 1 ? '' : 's' ?></button>
    </form>
  <?php endif; ?>
</section>
<?php endif; ?>
