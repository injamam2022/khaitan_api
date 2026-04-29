<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\EasyEcom;

/**
 * Validates incoming EasyEcom webhook requests against EASYECOM_WEBHOOK_SECRET.
 *
 * Accepts the secret in:
 *   - Access-Token header  (EasyEcom default)
 *   - Authorization: Bearer <token>
 *
 * Applied to the legacy per-action webhook routes (webhooks/easyecom/*).
 * The unified handler (Webhooks::easyEcomHandler) performs its own validation inline.
 */
class EasyEcomWebhookAuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $secret = config(EasyEcom::class)->webhookSecret ?? '';

        if ($secret === '') {
            log_message('critical', 'EasyEcomWebhookAuthFilter: EASYECOM_WEBHOOK_SECRET is not configured — rejecting request');
            return $this->unauthorized();
        }

        $token = $request->getHeaderLine('Access-Token');
        if ($token !== '' && hash_equals($secret, trim($token))) {
            return;
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && preg_match('/^\s*Bearer\s+(.+)$/i', $auth, $m)) {
            if (hash_equals($secret, trim($m[1]))) {
                return;
            }
        }

        log_message('error', 'EasyEcomWebhookAuthFilter: invalid or missing webhook token from ' . $request->getIPAddress());
        return $this->unauthorized();
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return $response;
    }

    private function unauthorized(): ResponseInterface
    {
        $response = service('response');
        $response->setStatusCode(401);
        $response->setContentType('application/json');
        $response->setBody(json_encode([
            'success' => false,
            'message' => 'Unauthorized — invalid or missing webhook token.',
        ]));
        return $response;
    }
}
