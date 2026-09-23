<?php
/**
 * Supporting-documents panel for a client / NXP record edit page.
 *
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var list<array<string,mixed>> $attachments
 * @var string $uploadAction   POST target for new uploads
 * @var string $returnTo       the edit page to come back to after a delete
 * @var array|null $current_user
 */
use App\Http\View\Icons;

$isAdmin = in_array($current_user['role'] ?? null, ['admin', 'super_admin'], true);
$size = static function (int $b): string {
    return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
};
?>
<section class="actions">
  <h2>Documents <small>RC certificates, invoices, permits — PDF, JPEG, PNG or WebP</small></h2>

  <?php if (!$attachments): ?>
    <p class="hint">No documents attached yet.</p>
  <?php else: ?>
    <div class="table-wrap">
    <table class="grid">
      <thead><tr><th>File</th><th>Size</th><th>Uploaded</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($attachments as $a): ?>
        <tr>
          <td><a href="/console/attachments/<?= $this->e($a['uuid']) ?>"><?= Icons::svg('file', 'inline-icon') ?> <?= $this->e($a['original_name']) ?></a></td>
          <td class="hint"><?= $this->e($size((int) $a['byte_size'])) ?></td>
          <td class="hint"><?= $this->dt($a['created_at'], true) ?> · <?= $this->e($a['uploaded_by_name']) ?></td>
          <td>
            <?php if ($isAdmin || (int) ($current_user['id'] ?? 0) === (int) $a['uploaded_by']): ?>
              <form method="post" action="/console/attachments/<?= $this->e($a['uuid']) ?>/delete" class="inline-form"
                    data-confirm="Delete this document permanently?">
                <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
                <input type="hidden" name="return" value="<?= $this->e($returnTo) ?>">
                <button type="submit" class="btn-outline btn-sm">Delete</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= $this->e($uploadAction) ?>" enctype="multipart/form-data" class="record-form upload-form">
    <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
    <label>Attach a document
      <input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">
    </label>
    <button type="submit" class="btn-outline"><?= Icons::svg('plus') ?> Upload</button>
  </form>
</section>
