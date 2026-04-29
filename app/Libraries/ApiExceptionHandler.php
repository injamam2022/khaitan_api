<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\Debug\ExceptionHandler;
use CodeIgniter\Debug\ExceptionHandlerInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Exceptions as ExceptionsConfig;
use Throwable;

/**
 * Exception handler that ensures API requests (e.g. login) always receive
 * a JSON body on 5xx errors instead of an empty response when display_errors is off.
 */
class ApiExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        protected ExceptionsConfig $config
    ) {
    }

    public function handle(
        Throwable $exception,
        RequestInterface $request,
        ResponseInterface $response,
        int $statusCode,
        int $exitCode,
    ): void {
        $isApiRequest = $request instanceof IncomingRequest
            && ! str_contains((string) $request->getHeaderLine('accept'), 'text/html');
        $isServerError = $statusCode >= 500;
        $displayErrors = in_array(
            strtolower((string) ini_get('display_errors')),
            ['1', 'true', 'on', 'yes'],
            true,
        );

        if ($isApiRequest && $isServerError && ! $displayErrors) {
            $response->setStatusCode($statusCode);
            $response->setContentType('application/json');
            $response->setBody((string) json_encode([
                'success' => false,
                'message' => 'Internal server error',
                'data'    => null,
            ], JSON_UNESCAPED_UNICODE));
            $response->send();
            if (ENVIRONMENT !== 'testing') {
                exit($exitCode);
            }
            return;
        }

        (new ExceptionHandler($this->config))->handle($exception, $request, $response, $statusCode, $exitCode);
    }
}
