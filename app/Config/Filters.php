<?php

namespace Config;

use CodeIgniter\Config\Filters as BaseFilters;
use CodeIgniter\Filters\Cors;
use CodeIgniter\Filters\CSRF;
use CodeIgniter\Filters\DebugToolbar;
use CodeIgniter\Filters\ForceHTTPS;
use CodeIgniter\Filters\Honeypot;
use CodeIgniter\Filters\InvalidChars;
use CodeIgniter\Filters\PageCache;
use CodeIgniter\Filters\PerformanceMetrics;
use CodeIgniter\Filters\SecureHeaders;
// CorsFilter removed - CORS handled by Apache .htaccess
use App\Filters\SessionCookieFilter;
use App\Filters\SessionAuthFilter;
use App\Filters\EasyEcomWebhookAuthFilter;

class Filters extends BaseFilters
{
    /**
     * Configures aliases for Filter classes to
     * make reading things nicer and simpler.
     *
     * @var array<string, class-string|list<class-string>>
     *
     * [filter_name => classname]
     * or [filter_name => [classname1, classname2, ...]]
     */
    public array $aliases = [
        'csrf'          => CSRF::class,
        'toolbar'       => DebugToolbar::class,
        'honeypot'      => Honeypot::class,
        'invalidchars'  => InvalidChars::class,
        'secureheaders' => SecureHeaders::class,
        'cors'          => Cors::class,  // Built-in CORS (not used, Apache handles it)
        'forcehttps'    => ForceHTTPS::class,
        'pagecache'     => PageCache::class,
        'performance'   => PerformanceMetrics::class,
        // 'backendcors' => Removed - CORS handled by Apache .htaccess
        'sessioncookie'      => SessionCookieFilter::class,
        'sessionauth'        => SessionAuthFilter::class,
        'easyecom_webhook'   => EasyEcomWebhookAuthFilter::class,
    ];

    /**
     * List of special required filters.
     *
     * The filters listed here are special. They are applied before and after
     * other kinds of filters, and always applied even if a route does not exist.
     *
     * Filters set by default provide framework functionality. If removed,
     * those functions will no longer work.
     *
     * @see https://codeigniter.com/user_guide/incoming/filters.html#provided-filters
     *
     * @var array{before: list<string>, after: list<string>}
     */
    public array $required = [
        'before' => [
            'forcehttps', // Force Global Secure Requests
            'pagecache',  // Web Page Caching
        ],
        'after' => [
            'pagecache',   // Web Page Caching
            'performance', // Performance Metrics
            // Toolbar moved to globals with exclusions to prevent JSON injection
        ],
    ];

    /**
     * List of filter aliases that are always
     * applied before and after every request.
     *
     * @var array{
     *     before: array<string, array{except: list<string>|string}>|list<string>,
     *     after: array<string, array{except: list<string>|string}>|list<string>
     * }
     */
    public array $globals = [
        'before' => [
            // CORS via PHP ensures headers when OPTIONS preflight reaches CI (Apache may omit them on shared hosting)
            'cors',
            'sessioncookie', // Must run for ALL routes (including login) for cross-domain cookie support
            'sessionauth' => ['except' => ['login', 'logout', 'health', 'test', 'webhooks/easyecom', 'webhooks/easyecom/*', 'orders/push-easyecom', 'orders/cancel-easyecom', 'cron/*', 'api/products/filter/v2', 'api/products/lists/v2', 'api/home/banners/v2']], // Skip for public, webhook, and internal push
            // 'honeypot',
            // 'csrf',
            // 'invalidchars',
        ],
        'after' => [
            // CORS handled by Apache - removed from here to avoid duplicate headers
            'toolbar' => ['except' => [
                'login', 
                'logout', 
                'health', 
                'testdb', 
                'setup',
                'dashboard',
                'dashboard/*',
                'products',
                'products/*',
                'orders',
                'orders/*',
                'users',
                'users/*',
                'promos',
                'promos/*',
                'productreviews',
                'productreviews/*',
                'homeproducts',
                'homeproducts/*',
                'homesliders',
                'homesliders/*',
                'pages',
                'pages/*',
                'profile',
                'profile/*',
                'product-descriptions',
                'product-descriptions/*',
                'product-description-images',
                'product-description-images/*',
                'product-images',
                'product-images/*',
                'api-docs',
                'api-docs/*',
                'delivery',
                'delivery/*',
                'easyecom',
                'easyecom/*',
                'webhooks',
                'webhooks/*',
                'api',
                'api/*'
            ]], // Debug Toolbar - exclude all API/JSON routes to prevent HTML injection
            // 'honeypot',
            // 'secureheaders',
        ],
    ];

    /**
     * List of filter aliases that works on a
     * particular HTTP method (GET, POST, etc.).
     *
     * Example:
     * 'POST' => ['foo', 'bar']
     *
     * If you use this, you should disable auto-routing because auto-routing
     * permits any HTTP method to access a controller. Accessing the controller
     * with a method you don't expect could bypass the filter.
     *
     * @var array<string, list<string>>
     */
    public array $methods = [];

    /**
     * List of filter aliases that should run on any
     * before or after URI patterns.
     *
     * Example:
     * 'isLoggedIn' => ['before' => ['account/*', 'profiles/*']]
     *
     * @var array<string, array<string, list<string>>>
     */
    public array $filters = [
        'easyecom_webhook' => ['before' => ['webhooks/easyecom/*']],
    ];
}
