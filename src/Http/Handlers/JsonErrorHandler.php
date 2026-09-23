<?php

declare(strict_types=1);

namespace App\Http\Handlers;

use App\Http\Middleware\RequestIdMiddleware;
use App\Support\ApiException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Throwable;

/**
 * Every error leaves this API as the same JSON envelope:
 *
 *   { "error": { "code": "<machine_slug>", "message": "<human text>",
 *                "request_id": "<uuid>" [, "details": {...}] } }
 *
 * Three tiers:
 *   - App\Support\ApiException  -> rendered as-is (status, code, details, extra
 *                                  headers). These are expected outcomes.
 *   - Slim\Exception\HttpException -> mapped to a status + slug.
 *   - anything else -> 500, logged with the request id, message hidden unless
 *                      APP_DEBUG (brief §7).
 */
final class JsonErrorHandler implements ErrorHandlerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly bool $debug,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $requestId = (string) $request->getAttribute(RequestIdMiddleware::ATTRIBUTE, '');

        $status  = 500;
        $code    = 'internal_error';
        $message = $this->debug ? $exception->getMessage() : 'An unexpected error occurred.';
        $details = [];
        $headers = [];

        if ($exception instanceof ApiException) {
            $status  = $exception->getStatusCode();
            $code    = $exception->getErrorCode();
            $message = $exception->getMessage();
            $details = $exception->getDetails();
            $headers = $exception->getHeaders();
        } elseif ($exception instanceof HttpException) {
            $status  = $exception->getCode();
            $code    = self::slugForStatus($status);
            $message = $exception->getMessage() ?: self::slugForStatus($status);
        }

        if ($status >= 500) {
            $this->logger->error($exception->getMessage(), [
                'request_id' => $requestId,
                'exception'  => $exception::class,
                'file'       => $exception->getFile() . ':' . $exception->getLine(),
                'method'     => $request->getMethod(),
                'path'       => $request->getUri()->getPath(),
            ]);
        }

        $error = ['code' => $code, 'message' => $message, 'request_id' => $requestId];

        if ($details !== []) {
            $error['details'] = $details;
        }

        if ($this->debug) {
            $error['debug'] = [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
                'at'        => $exception->getFile() . ':' . $exception->getLine(),
                'trace'     => explode("\n", $exception->getTraceAsString()),
            ];
        }

        $response = (new ResponseFactory())->createResponse($status)
            ->withHeader('Content-Type', 'application/json');

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        $response->getBody()->write((string) json_encode(
            ['error' => $error],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));

        return $response;
    }

    private static function slugForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthorized',
            403 => 'forbidden',
            404 => 'not_found',
            405 => 'method_not_allowed',
            409 => 'conflict',
            422 => 'unprocessable_entity',
            429 => 'too_many_requests',
            default => $status >= 500 ? 'internal_error' : 'error',
        };
    }
}
