<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 *  1. Load the autoloader.
 *  2. Ensure a `.env.testing` exists (copy from the example on first run).
 *  3. Load it and force APP_ENV=testing.
 *  4. Create the test database if it does not exist.
 *  5. Run Phinx migrations against it (idempotent).
 *
 * The test database is SEPARATE from development and is safe to drop.
 */

use App\Support\Env;
use App\Support\Database;
use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

require dirname(__DIR__) . '/vendor/autoload.php';

$basePath = dirname(__DIR__);

// --- 2. env file -----------------------------------------------------------
$envTesting = $basePath . '/.env.testing';
if (!is_file($envTesting)) {
    copy($basePath . '/.env.testing.example', $envTesting);
    fwrite(STDERR, "[bootstrap] created .env.testing from example\n");
}

// --- 3. load env ---------------------------------------------------------
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
Env::reset();
Env::load($basePath, '.env.testing');

date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC'));

// --- 4. create test database if missing ---------------------------------
$dbName = Env::getRequired('DB_DATABASE');
$serverDsn = sprintf(
    'mysql:host=%s;port=%d;charset=%s',
    Env::get('DB_HOST', '127.0.0.1'),
    Env::getInt('DB_PORT', 3306),
    Env::get('DB_CHARSET', 'utf8mb4'),
);

try {
    $pdo = new PDO($serverDsn, Env::getRequired('DB_USERNAME'), Env::get('DB_PASSWORD', ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
        str_replace('`', '', $dbName),
        Env::get('DB_CHARSET', 'utf8mb4'),
        Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ));
} catch (PDOException $e) {
    fwrite(STDERR, "[bootstrap] cannot reach MySQL to prepare the test database: {$e->getMessage()}\n");
    fwrite(STDERR, "[bootstrap] is the MySQL server running on {$serverDsn}?\n");
    exit(1);
}

// --- 5. migrate --------------------------------------------------------
$phinx = new PhinxApplication();
$phinx->setAutoExit(false);
$output = new BufferedOutput();
$exitCode = $phinx->run(new ArrayInput([
    'command' => 'migrate',
    '-c'      => $basePath . '/phinx.php',
    '-e'      => 'testing',
]), $output);

if ($exitCode !== 0) {
    fwrite(STDERR, "[bootstrap] phinx migrate failed:\n" . $output->fetch() . "\n");
    exit(1);
}

// Sanity: the app's own connector must reach the migrated DB.
$check = Database::ping(Database::connect([
    'driver'   => Env::get('DB_DRIVER', 'mysql'),
    'host'     => Env::get('DB_HOST', '127.0.0.1'),
    'port'     => Env::getInt('DB_PORT', 3306),
    'database' => $dbName,
    'username' => Env::getRequired('DB_USERNAME'),
    'password' => Env::get('DB_PASSWORD', ''),
    'charset'  => Env::get('DB_CHARSET', 'utf8mb4'),
]));

if (!$check['ok']) {
    fwrite(STDERR, "[bootstrap] test DB unreachable after migrate: " . ($check['error'] ?? '') . "\n");
    exit(1);
}
