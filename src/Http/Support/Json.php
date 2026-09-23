<?php

declare(strict_types=1);

namespace App\Http\Support;

use Psr\Http\Message\ResponseInterface;

/**
 * One place that knows how a success payload is serialized, so every endpoint
 * returns the same shape and header set.
 */
final class Json
{
    /** @param mixed $data */
    public static function write(ResponseInterface $response, $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write((string) json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        ));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
