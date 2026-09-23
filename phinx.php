<?php

declare(strict_types=1);

/**
 * Phinx configuration. Database credentials come from the same env file the
 * app uses (via App\Support\Env), so migrations and runtime never drift apart.
 *
 * Environments:
 *   development  -> .env        (DB_DATABASE, default adwol_pia_dev)
 *   testing      -> .env.testing (DB_DATABASE, default adwol_pia_test)
 *
 * Select with:  phinx migrate -e development   (this is also the default)
 */

require __DIR__ . '/vendor/autoload.php';

use App\Support\Env;

$basePath = __DIR__;

$build = static function (string $envFile) use ($basePath): array {
    Env::reset();
    Env::load($basePath, $envFile);

    return [
        'adapter'      => Env::get('DB_DRIVER', 'mysql'),
        'host'         => Env::get('DB_HOST', '127.0.0.1'),
        'name'         => Env::getRequired('DB_DATABASE'),
        'user'         => Env::getRequired('DB_USERNAME'),
        'pass'         => Env::get('DB_PASSWORD', ''),
        'port'         => Env::getInt('DB_PORT', 3306),
        'charset'      => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation'    => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ];
};

return [
    'paths' => [
        'migrations' => $basePath . '/db/migrations',
        'seeds'      => $basePath . '/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment'     => 'development',
        'development'             => $build('.env'),
        'testing'                 => $build('.env.testing'),
    ],
    'version_order' => 'creation',
];
