<?php

declare(strict_types=1);

namespace App\Tests\Inspections;

use App\Inspections\AttachmentStorage;
use App\Support\ApiException;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class AttachmentStorageTest extends TestCase
{
    private string $dir;

    /** 1x1 PNG. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\nIDATx\x9cc\x00\x01\x00\x00\x05\x00\x01\x0d\x0a-\xb4\x00\x00\x00\x00IEND\xaeB`\x82";

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pia_att_' . uniqid();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function storage(int $max = 1_000_000): AttachmentStorage
    {
        return new AttachmentStorage($this->dir, $max);
    }

    public function testStoresAValidPngAndReadsItBack(): void
    {
        $uuid = Uuid::uuid4()->toString();
        $sha  = hash('sha256', self::PNG);

        $meta = $this->storage()->put($uuid, self::PNG, $sha);

        self::assertSame('image/png', $meta['mime_type']);
        self::assertSame(strlen(self::PNG), $meta['byte_size']);
        self::assertSame($sha, $meta['checksum_sha256']);
        self::assertStringEndsWith(strtolower($uuid) . '.png', $meta['relative_path']);

        $back = $this->storage()->read($meta['relative_path']);
        self::assertNotNull($back);
        self::assertSame(self::PNG, $back['bytes']);
    }

    public function testRetryWithSameUuidOverwritesRatherThanDuplicates(): void
    {
        $uuid = Uuid::uuid4()->toString();
        $sha  = hash('sha256', self::PNG);

        $this->storage()->put($uuid, self::PNG, $sha);
        $this->storage()->put($uuid, self::PNG, $sha);

        self::assertCount(1, glob($this->dir . '/*'));
    }

    public function testRejectsChecksumMismatch(): void
    {
        try {
            $this->storage()->put(Uuid::uuid4()->toString(), self::PNG, str_repeat('0', 64));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('checksum_mismatch', $e->getErrorCode());
        }
    }

    public function testRejectsOversizeFile(): void
    {
        $this->expectException(ApiException::class);
        $this->storage(10)->put(Uuid::uuid4()->toString(), self::PNG, hash('sha256', self::PNG));
    }

    public function testRejectsDisallowedType(): void
    {
        $txt = "just some text, not an allowed attachment type";
        try {
            $this->storage()->put(Uuid::uuid4()->toString(), $txt, hash('sha256', $txt));
            self::fail('expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('unsupported_type', $e->getErrorCode());
        }
    }

    public function testRejectsEmptyFile(): void
    {
        $this->expectException(ApiException::class);
        $this->storage()->put(Uuid::uuid4()->toString(), '', hash('sha256', ''));
    }

    public function testReadRejectsPathTraversal(): void
    {
        $this->storage()->put(Uuid::uuid4()->toString(), self::PNG, hash('sha256', self::PNG));

        self::assertNull($this->storage()->read('../../../etc/passwd'));
        self::assertNull($this->storage()->read('attachments/does-not-exist.png'));
    }
}
