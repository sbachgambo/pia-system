<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $csrf_token
 * @var array{data:list<array<string,mixed>>,pagination:array} $list
 * @var array<string,mixed> $filters
 * @var array|null $current_user
 */
$roles = ['inspector' => 'Inspector', 'office_reviewer' => 'Office reviewer', 'admin' => 'Admin', 'super_admin' => 'Super admin', 'board' => 'Board of Directors'];
?>
<div class="page-head">
  <h1>Users</h1>
  <div class="head-actions">
    <a class="btn btn-outline" href="/console/users/export<?= ($qs = http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== ''))) !== '' ? '?' . $qs : '' ?>"><?= \App\Http\View\Icons::svg('download') ?> Export CSV</a>
    <a class="btn" href="/console/users/new"><?= \App\Http\View\Icons::svg('plus') ?> New user</a>
  </div>
</div>

<form class="filters" method="get" action="/console/users">
  <select name="role">
    <option value="">All roles</option>
    <?php foreach ($roles as $v => $lbl): ?>
      <option value="<?= $v ?>" <?= ($filters['role'] ?? '') === $v ? 'selected' : '' ?>><?= $lbl ?></option>
    <?php endforeach; ?>
  </select>
  <select name="status">
    <option value="">Any status</option>
    <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
    <option value="suspended" <?= ($filters['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>Suspended</option>
  </select>
  <input type="search" name="q" value="<?= $this->e($filters['q'] ?? '') ?>" placeholder="Search name / email">
  <button type="submit" class="btn-outline">Filter</button>
</form>

<div class="table-wrap">
<table class="grid">
  <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Zone</th><th>Status</th><th>Last login</th><th></th></tr></thead>
  <tbody>
  <?php if (!$list['data']): ?>
    <tr><td colspan="7" class="empty">No users yet.</td></tr>
  <?php endif; ?>
  <?php foreach ($list['data'] as $u): ?>
    <tr>
      <td><?= $this->e($u['full_name']) ?></td>
      <td><?= $this->e($u['email']) ?></td>
      <td><span class="badge badge-scheduled"><?= $this->e($roles[$u['role']] ?? $u['role']) ?></span> <?= !empty($u['2fa']) || !empty($u['two_factor']) ? '<span class="badge badge-completed" title="Two-factor on">2FA</span>' : '' ?></td>
      <td><?= $this->e($u['zone'] ?? '—') ?></td>
      <td><span class="badge badge-<?= $u['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= $this->e($u['status']) ?></span></td>
      <td class="hint"><?= $this->dt($u['last_login_at'] ?? null, true, 'never') ?></td>
      <td>
        <a href="/console/users/<?= $this->e($u['uuid']) ?>/edit">Edit</a>
        <?php if (($current_user['uuid'] ?? null) !== $u['uuid']): ?>
          <form method="post" action="/console/users/<?= $this->e($u['uuid']) ?>/toggle-status" class="inline-form"
                data-confirm="<?= $u['status'] === 'active' ? 'Suspend this user? They will not be able to sign in.' : 'Re-activate this user?' ?>">
            <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
            <button type="submit" class="btn-outline"><?= $u['status'] === 'active' ? 'Suspend' : 'Activate' ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?= $this->partial('console/_pagination', [
    'pagination' => $list['pagination'],
    'base'       => '/console/users',
    'query'      => $filters,
]) ?>
