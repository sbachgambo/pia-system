<?php

declare(strict_types=1);

namespace App\Tests\Backup;

use App\Backup\BackupService;
use App\Backup\DatabaseDumper;
use App\Tests\Support\DatabaseTestCase;
use ZipArchive;

final class BackupTest extends DatabaseTestCase
{
    private string $dir;
    private string $filesDir;

    protected function dirtyTables(): array
    {
        return ['rate_limits', 'users'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pia-bk-' . bin2hex(random_bytes(4));
        $this->dir = $base . DIRECTORY_SEPARATOR . 'backups';
        $this->filesDir = $base . DIRECTORY_SEPARATOR . 'docs';
        mkdir($this->filesDir, 0775, true);
        file_put_contents($this->filesDir . DIRECTORY_SEPARATOR . 'a.txt', 'hello');
        file_put_contents($this->filesDir . DIRECTORY_SEPARATOR . '.gitkeep', '');
    }

    protected function tearDown(): void
    {
        foreach ([$this->dir, $this->filesDir] as $d) {
            foreach (glob($d . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f);
                }
            }
            @rmdir($d);
        }
        @rmdir(dirname($this->dir));
    }

    private function service(): BackupService
    {
        return new BackupService($this->pdo, new DatabaseDumper($this->pdo), $this->dir, ['docs' => $this->filesDir]);
    }

    private function dumpToString(): string
    {
        $sql = '';
        (new DatabaseDumper($this->pdo))->dump(static function (string $c) use (&$sql): void {
            $sql .= $c;
        });

        return $sql;
    }

    public function testDumpQuotesAwkwardValuesKeepsUtcAndSkipsEphemeralRows(): void
    {
        $this->makeUser(['full_name' => "O'Brien \"Q\" \\ back\nline ünï"]);
        $this->pdo->exec("INSERT INTO rate_limits (bucket_key, window_started_at, hits, created_at, updated_at)
                          VALUES ('k', UTC_TIMESTAMP(), 5, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $before = (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn();

        $sql = $this->dumpToString();

        self::assertStringContainsString("SET time_zone = '+00:00';", $sql);
        self::assertStringContainsString('CREATE TABLE `users`', $sql);
        self::assertStringContainsString('INSERT INTO `users`', $sql);
        self::assertStringContainsString("O\\'Brien", $sql);
        self::assertStringContainsString('ünï', $sql);
        self::assertStringContainsString('CREATE TABLE `rate_limits`', $sql);
        self::assertStringNotContainsString('INSERT INTO `rate_limits`', $sql, 'ephemeral rows are structure-only');
        self::assertSame($before, (string) $this->pdo->query('SELECT @@session.time_zone')->fetchColumn(), 'the connection\'s time zone is restored');
    }

    public function testCreateProducesAVerifiableArchiveWithDatabaseFilesAndManifest(): void
    {
        $this->makeUser();
        $svc = $this->service();

        $b = $svc->create();

        self::assertMatchesRegularExpression(BackupService::NAME_PATTERN, $b['name']);
        self::assertTrue($svc->verify($b['name']));
        self::assertSame(1, $b['files'], '.gitkeep is not backed up');

        $zip = new ZipArchive();
        self::assertTrue($zip->open((string) $svc->path($b['name'])));
        self::assertNotFalse($zip->locateName('database.sql'));
        self::assertNotFalse($zip->locateName('manifest.json'));
        self::assertSame('hello', $zip->getFromName('files/docs/a.txt'));
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        self::assertSame(1, $manifest['tables']['users']);
        $zip->close();
        self::assertNotContains('.tmp', array_map(static fn ($f) => substr(basename($f), 0, 4), glob($this->dir . '/*') ?: []));
    }

    public function testTamperingIsDetectedAndPathsAreLockedDown(): void
    {
        $svc = $this->service();
        $b = $svc->create();

        file_put_contents((string) $svc->path($b['name']), 'x', FILE_APPEND);
        self::assertFalse($svc->verify($b['name']));

        foreach (['../secret.zip', 'pia-backup-1.zip', 'database.sql', 'pia-backup-20260101-000000.exe', "pia-backup-20260101-000000.zip\0"] as $bad) {
            self::assertNull($svc->path($bad), $bad);
            self::assertFalse($svc->delete($bad), $bad);
        }
    }

    public function testListIsNewestFirstAndPruneKeepsTheRequestedCount(): void
    {
        $svc = $this->service();
        mkdir($this->dir, 0775, true);
        foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
            file_put_contents($this->dir . "/pia-backup-{$stamp}.zip", 'z');
        }

        self::assertSame(
            ['pia-backup-20260103-000000.zip', 'pia-backup-20260102-000000.zip', 'pia-backup-20260101-000000.zip'],
            array_column($svc->list(), 'name'),
        );
        self::assertSame(2, $svc->prune(1));
        self::assertSame(['pia-backup-20260103-000000.zip'], array_column($svc->list(), 'name'));
    }
}
