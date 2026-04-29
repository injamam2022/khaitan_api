<?php

// CORS is handled by Apache .htaccess (public/.htaccess) — do NOT set CORS headers here.
// Having both Apache and PHP set CORS causes DUPLICATE Access-Control-Allow-Origin headers,
// which browsers treat as a CORS violation and reject the response.
//
// In production, API requests go through the Next.js proxy (khaitanadmin.com/backend/* → admin.khaitan.com/backend/*),
// so CORS is not needed for session-based API calls (they are same-origin from the browser's perspective).
// Apache CORS is kept for direct cross-origin requests (e.g., image loading).

// Handle OPTIONS preflight (Apache .htaccess also handles this, but as a safety net)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Let Apache handle the CORS headers via .htaccess
    http_response_code(200);
    exit(0);
}

use CodeIgniter\Boot;
use Config\Paths;

/*
 *---------------------------------------------------------------
 * CHECK PHP VERSION
 *---------------------------------------------------------------
 */

$minPhpVersion = '8.1'; // If you update this, don't forget to update `spark`.
if (version_compare(PHP_VERSION, $minPhpVersion, '<')) {
    $message = sprintf(
        'Your PHP version must be %s or higher to run CodeIgniter. Current version: %s',
        $minPhpVersion,
        PHP_VERSION,
    );

    header('HTTP/1.1 503 Service Unavailable.', true, 503);
    echo $message;

    exit(1);
}

/*
 *---------------------------------------------------------------
 * SET THE CURRENT DIRECTORY
 *---------------------------------------------------------------
 */

// Path to the front controller (this file)
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);

// Ensure the current directory is pointing to the front controller's directory
if (getcwd() . DIRECTORY_SEPARATOR !== FCPATH) {
    chdir(FCPATH);
}

/*
 *---------------------------------------------------------------
 * BOOTSTRAP THE APPLICATION
 *---------------------------------------------------------------
 * This process sets up the path constants, loads and registers
 * our autoloader, along with Composer's, loads our constants
 * and fires up an environment-specific bootstrapping.
 */

// LOAD OUR PATHS CONFIG FILE
// This is the line that might need to be changed, depending on your folder structure.
require FCPATH . '../app/Config/Paths.php';
// ^^^ Change this line if you move your application folder

$paths = new Paths();

// LOAD THE FRAMEWORK BOOTSTRAP FILE
require $paths->systemDirectory . '/Boot.php';

exit(Boot::bootWeb($paths));
