<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\View\Renderer;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pia_tpl_' . uniqid();
        mkdir($this->dir);
        file_put_contents($this->dir . '/layout.php', '<html><?= $content ?></html>');
        file_put_contents($this->dir . '/page.php', 'Hi <?= $this->e($name) ?> from <?= $this->e($app_name) ?>');
        file_put_contents($this->dir . '/frag.php', '[<?= $this->e($x) ?>]');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testRendersTemplateInsideLayoutAndEscapes(): void
    {
        $r = new Renderer($this->dir, ['app_name' => 'ADWOL']);

        $html = $r->render('page', ['name' => '<b>x&y</b>']);

        self::assertSame('<html>Hi &lt;b&gt;x&amp;y&lt;/b&gt; from ADWOL</html>', $html);
    }

    public function testPartialInclude(): void
    {
        $r = new Renderer($this->dir);
        // renderRaw so we don't need the layout
        $out = $r->renderRaw('frag', ['x' => 'ok']);
        self::assertSame('[ok]', $out);
    }

    public function testMissingTemplateThrows(): void
    {
        $this->expectExceptionMessage('Template not found');
        (new Renderer($this->dir))->render('nope');
    }

    public function testPathTraversalIsStripped(): void
    {
        $this->expectExceptionMessage('Template not found');
        (new Renderer($this->dir))->renderRaw('../../etc/passwd');
    }
}
