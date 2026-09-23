<?php

declare(strict_types=1);

namespace App\Office;

use App\Support\ApiException;

/**
 * A resource addressed by URL (…/{uuid}) does not exist. Renders as 404
 * `not_found`. (A bad *reference inside a request body* is a 422
 * `validation_failed` instead — that is the caller's input being wrong, not a
 * missing page.)
 */
final class NotFoundException extends ApiException
{
    public function __construct(string $what = 'Resource')
    {
        parent::__construct(404, 'not_found', "{$what} not found.");
    }
}
