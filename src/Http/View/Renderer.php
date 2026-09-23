<?php

declare(strict_types=1);

namespace App\Http\View;

use RuntimeException;

/**
 * Minimal PHP-template renderer for the console. No third-party engine —
 * shared hosting, no build step. Templates are plain `.php` files in
 * `templates/`; each is rendered inside `templates/layout.php` (which echoes
 * `$content`).
 *
 * Inside a template, `$this` is the Renderer, so `$this->e(...)` escapes and
 * `$this->partial(...)` includes a fragment.
 */
final class Renderer
{
    /** @param array<string,mixed> $shared values merged into every render */
    public function __construct(
        private readonly string $templateDir,
        private array $shared = [],
    ) {
    }

    /** @param array<string,mixed> $data */
    public function withShared(array $data): self
    {
        return new self($this->templateDir, [...$this->shared, ...$data]);
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], string $layout = 'layout'): string
    {
        $content = $this->renderRaw($template, $data);

        return $this->renderRaw($layout, [...$data, 'content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public function renderRaw(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . str_replace(['..', "\0"], '', $template) . '.php';

        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$template}");
        }

        $vars = [...$this->shared, ...$data];

        return (function () use ($file, $vars): string {
            extract($vars, EXTR_SKIP);
            ob_start();
            require $file;
            return (string) ob_get_clean();
        })();
    }

    /** @param array<string,mixed> $data — for use inside templates */
    public function partial(string $template, array $data = []): string
    {
        return $this->renderRaw($template, $data);
    }

    /** HTML-escape. */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * A stored date-time as people read it — "16 Sep 2026, 01:21" — escaped.
     * Everything is stored in UTC; `$utc` appends the zone for places where
     * the time of day matters. Blank or unparseable values give `$empty`.
     */
    public function dt(mixed $value, bool $utc = false, string $empty = '—'): string
    {
        $d = self::parseDate($value);

        return $d === null ? $this->e($empty) : $this->e($d->format('j M Y, H:i') . ($utc ? ' UTC' : ''));
    }

    /** A stored date as people read it — "16 Sep 2026" — escaped. */
    public function d(mixed $value, string $empty = '—'): string
    {
        $d = self::parseDate($value);

        return $d === null ? $this->e($empty) : $this->e($d->format('j M Y'));
    }

    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
