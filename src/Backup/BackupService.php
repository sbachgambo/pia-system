<?php

declare(strict_types=1);

namespace App\Backup;

use PDO;
use RuntimeException;
use ZipArchive;

/**
 * Creates, lists, verifies and prunes backups under `storage/backups/`.
 *
 * A backup is one zip holding `database.sql` (see DatabaseDumper), the
 * generated/uploaded files (`files/<dir>/…`) and a `manifest.json`. Hosts
 * without ext-zip get a gzipped SQL dump only (documents/photos then need the
 * file-copy step in DEPLOYMENT.md). A `.sha256` sidecar records the archive
 * digest so a corrupted or tampered file is detectable before a restore.
 *
 * Restore is deliberately NOT a console button — overwriting a live database
 * is a deliberate, hands-on operation (see DEPLOYMENT.md).
 */
final class BackupService
{
    public const NAME_PATTERN = '/^pia-backup-\d{8}-\d{6}\.(zip|sql\.gz)$/';

    /** @param array<string,string> $fileDirs archive-name => absolute directory */
    public function __construct(
        private readonly PDO $pdo,
        private readonly DatabaseDumper $dumper,
        private readonly string $backupDir,
        private readonly array $fileDirs,
    ) {
    }

    /** @return array{name:string,bytes:int,sha256:string,tables:int,rows:int,files:int,includes_files:bool} */
    public function create(): array
    {
        $this->ensureDir();
        $stamp = gmdate('Ymd-His');
        $tmpSql = $this->backupDir . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(6)) . '.sql';

        try {
            $fh = fopen($tmpSql, 'wb');
            if ($fh === false) {
                throw new RuntimeException("Cannot write to {$this->backupDir}");
            }
            $stats = $this->dumper->dump(static function (string $chunk) use ($fh): void {
                fwrite($fh, $chunk);
            });
            fclose($fh);

            $withFiles = class_exists(ZipArchive::class);
            $name = "pia-backup-{$stamp}." . ($withFiles ? 'zip' : 'sql.gz');
            $path = $this->backupDir . DIRECTORY_SEPARATOR . $name;
            $fileCount = 0;

            if ($withFiles) {
                $fileCount = $this->writeZip($path, $tmpSql, $stats);
            } else {
                $this->writeGzip($path, $tmpSql);
            }

            $sha = hash_file('sha256', $path);
            file_put_contents($path . '.sha256', $sha . '  ' . $name . "\n");

            return [
                'name'           => $name,
                'bytes'          => (int) filesize($path),
                'sha256'         => $sha,
                'tables'         => $stats['tables'],
                'rows'           => $stats['rows'],
                'files'          => $fileCount,
                'includes_files' => $withFiles,
            ];
        } finally {
            if (is_file($tmpSql)) {
                @unlink($tmpSql);
            }
        }
    }

    /** @return list<array{name:string,bytes:int,created_at:string,sha256:?string}> newest first */
    public function list(): array
    {
        $out = [];
        foreach (glob($this->backupDir . DIRECTORY_SEPARATOR . 'pia-backup-*') ?: [] as $file) {
            $name = basename($file);
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                continue;
            }
            $side = $file . '.sha256';
            $out[] = [
                'name'       => $name,
                'bytes'      => (int) filesize($file),
                'created_at' => gmdate('Y-m-d H:i:s', (int) filemtime($file)),
                'sha256'     => is_file($side) ? substr((string) file_get_contents($side), 0, 64) : null,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));

        return $out;
    }

    /** Absolute path for a backup name, or null if the name isn't a real backup file. */
    public function path(string $name): ?string
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }
        $path = $this->backupDir . DIRECTORY_SEPARATOR . $name;

        return is_file($path) ? $path : null;
    }

    /** True if the archive still matches the digest recorded when it was created. */
    public function verify(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null || !is_file($path . '.sha256')) {
            return false;
        }

        return hash_equals(substr((string) file_get_contents($path . '.sha256'), 0, 64), (string) hash_file('sha256', $path));
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);
        if ($path === null) {
            return false;
        }
        @unlink($path . '.sha256');

        return @unlink($path);
    }

    /** Keep the newest $keep backups, delete the rest. @return int number deleted */
    public function prune(int $keep): int
    {
        $deleted = 0;
        foreach (array_slice($this->list(), max(1, $keep)) as $b) {
            $deleted += $this->delete($b['name']) ? 1 : 0;
        }

        return $deleted;
    }

    /** @param array{tables:int,rows:int,table_rows:array<string,int>} $stats */
    private function writeZip(string $path, string $sqlFile, array $stats): int
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$path}");
        }

        $zip->addFile($sqlFile, 'database.sql');
        $files = 0;
        foreach ($this->fileDirs as $label => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (!$f->isFile() || $f->getFilename() === '.gitkeep') {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen(rtrim($dir, '/\\')) + 1));
                $zip->addFile($f->getPathname(), "files/{$label}/{$rel}");
                $files++;
            }
        }

        $zip->addFromString('manifest.json', (string) json_encode([
            'created_at_utc' => gmdate('c'),
            'php'            => PHP_VERSION,
            'mysql'          => (string) $this->pdo->query('SELECT VERSION()')->fetchColumn(),
            'tables'         => $stats['table_rows'],
            'file_count'     => $files,
            'restore'        => 'See DEPLOYMENT.md, "Restoring a backup".',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (!$zip->close()) {
            throw new RuntimeException('Could not finalise the backup archive.');
        }

        return $files;
    }

    private function writeGzip(string $path, string $sqlFile): void
    {
        $in = fopen($sqlFile, 'rb');
        $out = gzopen($path, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException("Cannot create {$path}");
        }
        while (!feof($in)) {
            gzwrite($out, (string) fread($in, 1048576));
        }
        fclose($in);
        gzclose($out);
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException("Backup directory {$this->backupDir} is not writable.");
        }
    }
}
