<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Monolog\Processor\UidProcessor;

/**
 * Builds the application's PSR-3 logger.
 *
 * One rotating stream to storage/logs/app.log. A per-process UID is attached
 * to every line (UidProcessor) so all log entries from one HTTP request can be
 * grepped together — the RequestIdMiddleware also surfaces this id in the
 * `X-Request-Id` response header.
 */
final class LoggerFactory
{
    /** @param array<string,mixed> $config The `log` slice of settings. */
    public static function create(array $config): Logger
    {
        $channel = $config['channel'] ?? 'app';
        $path    = $config['path'] ?? 'php://stderr';
        $level   = self::resolveLevel($config['level'] ?? 'info');

        $dir = \dirname((string) $path);
        if ($path !== 'php://stderr' && !is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $logger = new Logger($channel);
        $logger->pushProcessor(new UidProcessor(8));
        $logger->pushProcessor(new PsrLogMessageProcessor());
        $logger->pushHandler(new StreamHandler($path, $level));

        return $logger;
    }

    private static function resolveLevel(string $name): Level
    {
        return match (strtolower($name)) {
            'debug'             => Level::Debug,
            'info'              => Level::Info,
            'notice'            => Level::Notice,
            'warning', 'warn'   => Level::Warning,
            'error', 'err'      => Level::Error,
            'critical'          => Level::Critical,
            'alert'             => Level::Alert,
            'emergency'         => Level::Emergency,
            default             => Level::Info,
        };
    }
}
