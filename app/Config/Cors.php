<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Cross-Origin Resource Sharing (CORS) Configuration
 *
 * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
 */
class Cors extends BaseConfig
{
    /**
     * The default CORS configuration.
     *
     * @var array{
     *      allowedOrigins: list<string>,
     *      allowedOriginsPatterns: list<string>,
     *      supportsCredentials: bool,
     *      allowedHeaders: list<string>,
     *      exposedHeaders: list<string>,
     *      allowedMethods: list<string>,
     *      maxAge: int,
     *  }
     */
    /**
     * Environment-based allowed origins
     * Auto-configured from .env or defaults
     */
    private function getAllowedOrigins(): array
    {
        return config(\Config\Domains::class)->getAllowedOrigins();
    }

    public array $default = [
        'allowedOrigins' => [], // Will be set dynamically in constructor
        'allowedOriginsPatterns' => [],
        'supportsCredentials' => true, // REQUIRED for session cookies
        'allowedHeaders' => ['Content-Type', 'Authorization', 'Access-Token', 'X-Requested-With', 'Accept', 'Origin'],
        'exposedHeaders' => ['Content-Length', 'Content-Type'],
        'allowedMethods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'maxAge' => 86400, // 24 hours
    ];

    public function __construct()
    {
        parent::__construct();
        
        // Set allowed origins dynamically based on environment
        $this->default['allowedOrigins'] = $this->getAllowedOrigins();
    }
}
