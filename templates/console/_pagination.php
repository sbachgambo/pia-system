<?php
/**
 * @var \App\Http\View\Renderer $this
 * @var array{page:int,per_page:int,total:int,total_pages:int} $pagination
 * @var string $base   base path, e.g. /console/clients
 * @var array<string,mixed> $query  current filters to preserve
 */
$query = $query ?? [];
$link = static function (int $page) use ($base, $query): string {
    $q = array_filter([...$query, 'page' => $page], static fn ($v) => $v !== null && $v !== '' && $v !== false);
    return htmlspecialchars($base . '?' . http_build_query($q), ENT_QUOTES);
};
if (($pagination['total_pages'] ?? 1) <= 1) {
    return;
}
?>
<nav class="pager">
  <?php if ($pagination['page'] > 1): ?>
    <a href="<?= $link($pagination['page'] - 1) ?>">&larr; Prev</a>
  <?php endif; ?>
  <span>Page <?= $this->e($pagination['page']) ?> of <?= $this->e($pagination['total_pages']) ?>
        (<?= $this->e($pagination['total']) ?> total)</span>
  <?php if ($pagination['page'] < $pagination['total_pages']): ?>
    <a href="<?= $link($pagination['page'] + 1) ?>">Next &rarr;</a>
  <?php endif; ?>
</nav>
