<?php

declare(strict_types=1);

/**
 * Central settings array, assembled from environment variables.
 *
 * This is the ONLY place env vars are read into the app. Everything else
 * depends on this array (via the DI container / Config), so the surface for
 * "where did this value come from" stays small.
 *
 * Required keys (no default) will throw at boot if unset — see Env::getRequired.
 */

use App\Support\Env;

$basePath = dirname(__DIR__);

Env::load($basePath);

$appEnv   = Env::get('APP_ENV', 'production');
$isProd   = $appEnv === 'production';
$debug    = Env::getBool('APP_DEBUG', false);

return [
    'app' => [
        'name'     => Env::get('APP_NAME', 'ADWOL PIA'),
        'env'      => $appEnv,
        'debug'    => $debug && !$isProd, // debug is force-disabled in production
        'url'      => Env::get('APP_URL', 'http://127.0.0.1:8080'),
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'key'      => Env::getRequired('APP_KEY'),
        'base_path' => $basePath,
    ],

    'security' => [
        // Consumed from Phase 2/6 onward; validated here so a bad deploy
        // fails at boot rather than at first login / first document.
        'jwt_secret'          => Env::getRequired('JWT_SECRET'),
        'jwt_issuer'          => Env::get('JWT_ISSUER', 'pia-api'),
        'hmac_document_key'   => Env::getRequired('HMAC_DOCUMENT_KEY'),
        'jwt_access_ttl'      => Env::getInt('JWT_ACCESS_TTL', 900),
        'jwt_refresh_ttl'     => Env::getInt('JWT_REFRESH_TTL', 604800),
        'offline_reauth_days' => Env::getInt('OFFLINE_REAUTH_MAX_DAYS', 7),
        'argon2' => [
            'memory_cost' => Env::getInt('ARGON2_MEMORY_KIB', 65536),
            'time_cost'   => Env::getInt('ARGON2_TIME_COST', 4),
            'threads'     => Env::getInt('ARGON2_THREADS', 1),
        ],
        'auth_rate_limit' => [
            'max'    => Env::getInt('AUTH_RATE_LIMIT_MAX', 10),
            'window' => Env::getInt('AUTH_RATE_LIMIT_WINDOW', 900),
        ],
        // Offline unlock PIN policy (D4 / Phase 8).
        'pin' => [
            'min_length' => Env::getInt('PIN_MIN_LENGTH', 4),
            'max_length' => Env::getInt('PIN_MAX_LENGTH', 8),
        ],
    ],

    'db' => [
        'driver'    => Env::get('DB_DRIVER', 'mysql'),
        'host'      => Env::get('DB_HOST', '127.0.0.1'),
        'port'      => Env::getInt('DB_PORT', 3306),
        'database'  => Env::getRequired('DB_DATABASE'),
        'username'  => Env::getRequired('DB_USERNAME'),
        'password'  => Env::get('DB_PASSWORD', ''),
        'charset'   => Env::get('DB_CHARSET', 'utf8mb4'),
        'collation' => Env::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
    ],

    'storage' => [
        'path'                 => $basePath . DIRECTORY_SEPARATOR . Env::get('STORAGE_PATH', 'storage'),
        'attachment_max_bytes' => Env::getInt('ATTACHMENT_MAX_BYTES', 10485760),
    ],

    'workflow' => [
        // A14 (pending client confirmation, Q9): notice_deadline = requested_at
        // + this many calendar hours. Switch to working-day math once the real
        // policy and a holiday calendar are known.
        'notice_window_hours' => Env::getInt('NOTICE_WINDOW_HOURS', 72),

        // A34 (Phase 7, pending client confirmation, Q9): an inspection is
        // "missed" once scheduled_at + this many hours has passed while it's
        // still scheduled/in_progress. 3 consecutive months with a miss
        // triggers the compliance alert (also configurable).
        'compliance_grace_hours'      => Env::getInt('COMPLIANCE_GRACE_HOURS', 24),
        'compliance_strike_threshold' => Env::getInt('COMPLIANCE_STRIKE_THRESHOLD', 3),
    ],

    // Document letterhead (Phase 6) — the env-level DEFAULT only.
    // App\Settings\CompanySettingsRepository is the actual source of truth:
    // an admin can edit this from /console/settings without a redeploy; these
    // values are just what a fresh install shows before anyone has saved a
    // change. Keys match templates/documents/*.php ($company['name'], …).
    'pia' => [
        'name'                  => Env::get('PIA_COMPANY_NAME', 'ADWOL Investments and Services'),
        'address'               => Env::get('PIA_COMPANY_ADDRESS', ''),
        'representative_title'  => Env::get('PIA_REPRESENTATIVE_TITLE', 'Authorised Representative'),
    ],

    // Outgoing email. Empty MAIL_HOST = email disabled (the app still works;
    // self-service password reset and the digest simply switch off).
    'mail' => [
        'host'         => Env::get('MAIL_HOST', ''),
        'port'         => Env::getInt('MAIL_PORT', 587),
        'encryption'   => strtolower(Env::get('MAIL_ENCRYPTION', 'tls')),
        'username'     => Env::get('MAIL_USERNAME', ''),
        'password'     => Env::get('MAIL_PASSWORD', ''),
        'from_address' => Env::get('MAIL_FROM_ADDRESS', ''),
        'from_name'    => Env::get('MAIL_FROM_NAME', 'ADWOL PIA'),
    ],

    'log' => [
        'channel' => Env::get('LOG_CHANNEL', 'app'),
        'level'   => Env::get('LOG_LEVEL', $debug ? 'debug' : 'info'),
        'path'    => $basePath . DIRECTORY_SEPARATOR . Env::get('STORAGE_PATH', 'storage')
                     . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'app.log',
    ],
];
