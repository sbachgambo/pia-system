<?php

declare(strict_types=1);

/**
 * Front controller — the ONLY PHP file under the web root.
 *
 * On cPanel-style hosting this directory is (or is symlinked from)
 * `public_html`; `src/`, `config/`, `db/`, `storage/`, and `vendor/` all live
 * one level up, outside anything the web server will serve. See §8 / DEV_NOTES.
 */

use App\Http\AppFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

AppFactory::create()->run();
