<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cookie;

/**
 * Session Cookie Filter for Backend
 * 
 * Sets proper session cookie attributes for cross-origin requests.
 * 
 * Root Cause Fix:
 * - CodeIgniter 4's Session library uses Cookie config for session cookies
 * - SameSite=None REQUIRES Secure=true (even for localhost HTTP)
 * - Modern browsers reject SameSite=None cookies without Secure flag
 * - Browsers allow Secure=true on localhost HTTP as an exception
 * - This enables cross-origin session cookies (127.0.0.1:3000 -> localhost)
 * 
 * Production-safe: Uses Secure=true only when SameSite=None is needed
 */
class SessionCookieFilter implements FilterInterface
{
    /**
     * Before filter - sets session cookie parameters
     * 
     * SIMPLE LOGIC:
     * - Development (localhost): SameSite=None + Secure=true (allows cross-origin on different ports)
     * - Production (HTTPS): SameSite=Lax + Secure=true (more secure, same-site only)
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        try {
            if (version_compare(PHP_VERSION, '7.3.0', '<')) {
                return;
            }
            // Local development runs on HTTP; secure cookies must be disabled there.
            // Production stays cross-site compatible with SameSite=None + Secure=true.
            $isProduction = ENVIRONMENT === 'production';
            $isDevelopment = ENVIRONMENT === 'development' || env('CI_ENVIRONMENT') === 'development';
            $isSecureRequest = $request->isSecure();
            $cookieSettings = [
                'secure' => $isProduction || $isSecureRequest,
                'samesite' => $isProduction ? 'None' : 'Lax',
                'httponly' => true,
                'path' => '/',
                'domain' => '', // Empty = cookie for current host (admin.khaitan.com). Do NOT set to khaitanadmin.com.
            ];
            $cookieConfig = config('Cookie');
            if ($cookieConfig instanceof Cookie) {
                $cookieConfig->secure = $cookieSettings['secure'];
                $cookieConfig->samesite = $cookieSettings['samesite'];
                $cookieConfig->httponly = $cookieSettings['httponly'];
                $cookieConfig->path = $cookieSettings['path'];
                $cookieConfig->domain = $cookieSettings['domain'];
            }
            $sessionConfig = config('Session');
            $lifetime = $sessionConfig->expiration ?? 7200;
            session_set_cookie_params([
                'lifetime' => $lifetime,
                'path' => $cookieSettings['path'],
                'domain' => $cookieSettings['domain'],
                'secure' => $cookieSettings['secure'],
                'httponly' => $cookieSettings['httponly'],
                'samesite' => $cookieSettings['samesite']
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'SessionCookieFilter - ' . $e->getMessage());
        }
    }

    /**
     * After filter - no action needed
     * 
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param array|null $arguments
     * @return ResponseInterface
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // No action needed after request
        return $response;
    }

    /**
     * Check if request is HTTPS
     */
    private function isSecureRequest(RequestInterface $request): bool
    {
        return $request->isSecure() || 
               $request->getServer('HTTPS') === 'on' ||
               $request->getServer('SERVER_PORT') == 443;
    }
}
