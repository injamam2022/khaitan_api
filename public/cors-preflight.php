<?php

/**
 * Apache/mod_php on Windows often answers OPTIONS without running CodeIgniter,
 * which strips CORS headers and blocks the Next.js apps on :3000/:3001.
 */
$origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
$isLocal = preg_match('#^https?://(localhost|127\.0\.0\.1)(:[0-9]+)?$#', $origin) === 1;
$isKhaitan = preg_match('#^https://(www\.)?(khaitan\.com|admin\.khaitan\.com|khaitanadmin\.com)$#', $origin) === 1;

header('Vary: Origin, Access-Control-Request-Method');

if ($origin !== '' && ($isLocal || $isKhaitan)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Access-Token, X-Requested-With, Accept, Origin, session_key');
    header('Access-Control-Max-Age: 86400');
    header('Access-Control-Expose-Headers: Content-Length, Content-Type');
}

http_response_code(204);
exit;
