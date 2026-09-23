<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Base class for "expected" errors that should become a clean JSON response
 * rather than a 500. Carries the HTTP status, a stable machine `code` for the
 * client to branch on, optional structured `details`, and optional extra
 * response headers (e.g. Retry-After).
 *
 * JsonErrorHandler renders these directly; anything NOT extending this is
 * treated as a server fault.
 */
class ApiException extends RuntimeException
{
    /**
     * @param array<string,mixed> $details
     * @param array<string,string> $headers
     */
    public function __construct(
        private readonly int $statusCode,
        private readonly string $errorCode,
        string $message,
        private readonly array $details = [],
        private readonly array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** @return array<string,mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** @return array<string,string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
