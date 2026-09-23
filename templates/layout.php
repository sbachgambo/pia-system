<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var string $content
 * @var string $csrf_token
 * @var array|null $current_user
 * @var list<array{type:string,message:string}> $flashes
 * @var string $path
 * @var string $app_name
 */
$app_name = $app_name ?? 'ADWOL PIA';
$current_user = $current_user ?? null;
$flashes = $flashes ?? [];
$path = $path ?? '';

$icon = static fn (string $name, string $class = ''): string => \App\Http\View\Icons::svg($name, $class);

$navItems = [
    ['href' => '/console', 'label' => 'Dashboard', 'icon' => 'house', 'exact' => true],
    ['href' => '/console/clients', 'label' => 'Clients', 'icon' => 'users'],
    ['href' => '/console/nxp', 'label' => 'NXP', 'icon' => 'package'],
    ['href' => '/console/inspection-requests', 'label' => 'Requests', 'icon' => 'clipboard'],
    ['href' => '/console/calendar', 'label' => 'Calendar', 'icon' => 'calendar'],
    ['href' => '/console/inspections', 'label' => 'Review', 'icon' => 'search'],
    ['href' => '/console/archive', 'label' => 'Archive', 'icon' => 'archive'],
];
$adminItems = [
    ['href' => '/console/income', 'label' => 'Income', 'icon' => 'trending'],
    ['href' => '/console/compliance', 'label' => 'Compliance', 'icon' => 'shield'],
    ['href' => '/console/invoices', 'label' => 'CBN invoices', 'icon' => 'receipt'],
    ['href' => '/console/statutory-returns', 'label' => 'Returns', 'icon' => 'file'],
    ['href' => '/console/import', 'label' => 'Import', 'icon' => 'upload'],
    ['href' => '/console/users', 'label' => 'Users', 'icon' => 'user-cog'],
    ['href' => '/console/audit-log', 'label' => 'Audit log', 'icon' => 'history'],
    ['href' => '/console/security', 'label' => 'Security', 'icon' => 'lock'],
    ['href' => '/console/settings', 'label' => 'Settings', 'icon' => 'sliders'],
];
$isAdmin = in_array($current_user['role'] ?? null, ['admin', 'super_admin'], true);
// Board of Directors: every page an admin can view, none that only change things.
$isBoard = ($current_user['role'] ?? null) === 'board';
if ($isBoard) {
    $adminItems = array_values(array_filter($adminItems, static fn (array $i): bool => in_array(
        $i['href'],
        ['/console/income', '/console/compliance', '/console/invoices', '/console/statutory-returns', '/console/audit-log'],
        true,
    )));
}
if (($current_user['role'] ?? null) === 'super_admin') {
    $adminItems[] = ['href' => '/console/backups', 'label' => 'Backups', 'icon' => 'download'];
}

$isActive = static function (array $item) use ($path): bool {
    return !empty($item['exact']) ? $path === $item['href'] : str_starts_with($path, $item['href']);
};
$navLink = static function (array $item) use ($isActive, $icon): string {
    $active = $isActive($item);
    return '<a href="' . htmlspecialchars($item['href'], ENT_QUOTES) . '" class="nav-item' . ($active ? ' active' : '') . '"'
        . ($active ? ' aria-current="page"' : '') . '>'
        . '<span class="nav-icon">' . $icon($item['icon']) . '</span>'
        . '<span class="nav-label">' . htmlspecialchars($item['label'], ENT_QUOTES) . '</span></a>';
};
$pageLabel = 'Dashboard';
foreach ([...$navItems, ...$adminItems] as $item) {
    if ($isActive($item)) {
        $pageLabel = $item['label'];
        break;
    }
}
$initials = static function (?array $user): string {
    $name = trim((string) ($user['full_name'] ?? $user['email'] ?? ''));
    if ($name === '') {
        return '?';
    }
    $parts = preg_split('/\s+/', $name) ?: [$name];
    $chars = array_map(static fn (string $p) => mb_strtoupper(mb_substr($p, 0, 1)), array_filter($parts));
    return mb_substr(implode('', $chars), 0, 2) ?: '?';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->e($app_name) ?> — Office Console</title>
<script src="/assets/theme-init.js"></script>
<link rel="icon" href="/assets/brand/favicon-32.png" sizes="32x32">
<link rel="icon" href="/assets/brand/favicon-16.png" sizes="16x16">
<link rel="apple-touch-icon" href="/assets/brand/apple-touch-icon.png">
<link rel="stylesheet" href="/assets/console.css">
</head>
<body<?= $isBoard ? ' class="read-only" data-read-only="1"' : '' ?>>
<?php if ($current_user): ?>
<a class="skip-link" href="#main-content">Skip to content</a>
<div class="shell">
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <a href="/console" aria-label="<?= $this->e($app_name) ?> — dashboard">
        <img src="/assets/brand/mark-96.png" alt="" width="38" height="38">
        <span class="brand-text"><span class="brand-name">ADWOL</span><span class="brand-sub"><?= $isBoard ? 'Board oversight' : 'PIA Office Console' ?></span></span>
      </a>
    </div>
    <nav class="sidebar-nav" aria-label="Main">
      <?php foreach ($navItems as $item): ?>
        <?= $navLink($item) ?>
      <?php endforeach; ?>
      <?php if ($isAdmin || $isBoard): ?>
        <div class="nav-group-label"><?= $isBoard ? 'Oversight' : 'Administration' ?></div>
        <?php foreach ($adminItems as $item): ?>
          <?= $navLink($item) ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </nav>
    <div class="sidebar-foot">
      <a class="who" href="/console/account" title="My account">
        <span class="avatar"><?= $this->e($initials($current_user)) ?></span>
        <span class="who-text">
          <span class="who-name"><?= $this->e($current_user['full_name'] ?? $current_user['email'] ?? '') ?></span>
          <span class="who-role"><?= $this->e(str_replace('_', ' ', $current_user['role'] ?? '')) ?></span>
        </span>
      </a>
      <form method="post" action="/console/logout" class="logout ro-ok">
        <input type="hidden" name="_csrf" value="<?= $this->e($csrf_token) ?>">
        <button type="submit" title="Sign out" aria-label="Sign out"><?= $icon('signout') ?></button>
      </form>
    </div>
  </aside>
  <div class="sidebar-backdrop" id="sidebarBackdrop"></div>

  <div class="main">
    <header class="topbar">
      <button type="button" class="sidebar-toggle" id="sidebarToggle" aria-controls="sidebar" aria-expanded="false" aria-label="Toggle menu">
        <?= $icon('menu') ?>
      </button>
      <p class="topbar-title"><span class="crumb">Console</span><?php if ($pageLabel !== 'Dashboard'): ?><span class="crumb-sep" aria-hidden="true">/</span><strong><?= $this->e($pageLabel) ?></strong><?php endif; ?></p>
      <form class="topbar-search" method="get" action="/console/search" role="search">
        <?= $icon('search') ?>
        <input type="search" name="q" placeholder="Search NXP no., CCI no., clients…" aria-label="Search" minlength="2" maxlength="100">
      </form>
      <a class="bell" href="/console/attention" aria-label="Needs attention<?= ($attention_count ?? 0) > 0 ? ': ' . (int) $attention_count . ' items' : '' ?>" title="Needs attention">
        <?= $icon('bell') ?>
        <?php if (($attention_count ?? 0) > 0): ?><span class="bell-badge"><?= $attention_count > 99 ? '99+' : (int) $attention_count ?></span><?php endif; ?>
      </a>
      <button type="button" class="theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
        <?= $icon('sun', 'icon-sun') ?>
        <?= $icon('moon', 'icon-moon') ?>
      </button>
    </header>
    <main class="content" id="main-content" tabindex="-1">
      <?php if ($isBoard): ?>
        <div class="ro-banner"><?= $icon('lock') ?> Board of Directors — read-only access. You can view and download everything; changes are made by the office.</div>
      <?php endif; ?>
      <?php foreach ($flashes as $f): ?>
        <div class="flash flash-<?= $this->e($f['type']) ?>"><?= $this->e($f['message']) ?></div>
      <?php endforeach; ?>

      <?= $content ?>
    </main>
  </div>
</div>
<?php else: ?>
  <main class="content-bare">
    <?php foreach ($flashes as $f): ?>
      <div class="flash flash-<?= $this->e($f['type']) ?>"><?= $this->e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
  </main>
<?php endif; ?>
<script src="/assets/console.js" defer></script>
</body>
</html>
