<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Domain Configuration
 * Centralized configuration for all domain and URL settings
 * 
 * Update these values to change domains across the entire application
 */
class Domains extends BaseConfig
{
    /**
     * Main website domain (without protocol)
     */
    public string $mainDomain = 'khaitan.com';

    /**
     * Admin subdomain (without protocol)
     */
    public string $adminSubdomain = 'admin.khaitan.com';

    /**
     * Dashboard frontend domain (without protocol)
     */
    public string $dashboardDomain = 'khaitanadmin.com';

    /**
     * Backend base URL (full URL with protocol)
     * Automatically constructed from adminSubdomain
     */
    public function getBackendBaseUrl(): string
    {
        $envUrl = env('app.baseURL', '');
        if (!empty($envUrl)) {
            return rtrim($envUrl, '/') . '/';
        }
        
        if (ENVIRONMENT === 'production') {
            return 'https://' . $this->adminSubdomain . '/backend/';
        }
        
        return 'http://localhost/backend/';
    }

    /**
     * API base URL (full URL with protocol)
     * Automatically constructed from adminSubdomain
     */
    public function getApiBaseUrl(): string
    {
        $envUrl = env('app.baseURL', '');
        if (!empty($envUrl)) {
            return rtrim($envUrl, '/') . '/';
        }
        
        if (ENVIRONMENT === 'production') {
            return 'https://' . $this->adminSubdomain . '/api/';
        }
        
        return 'http://localhost/api/';
    }

    /**
     * Backend assets base URL (for product images, profile images, etc.)
     * Automatically constructed from adminSubdomain
     */
    public function getAssetsBaseUrl(): string
    {
        $envUrl = env('app.assetsBaseURL', '');
        if (!empty($envUrl)) {
            return rtrim($envUrl, '/') . '/';
        }
        
        if (ENVIRONMENT === 'production') {
            return 'https://' . $this->adminSubdomain . '/backend/assets/';
        }
        
        return 'http://localhost/backend/assets/';
    }

    /**
     * Get all allowed CORS origins
     * Returns array of full URLs with protocol
     */
    public function getAllowedOrigins(): array
    {
        $envOrigins = env('CORS_ALLOWED_ORIGINS', '');
        if ($envOrigins) {
            return array_map('trim', explode(',', $envOrigins));
        }
        
        if (ENVIRONMENT === 'production') {
            return [
                'https://' . $this->mainDomain,
                'https://www.' . $this->mainDomain,
                'https://' . $this->adminSubdomain,
                'https://www.' . $this->adminSubdomain,
                'https://' . $this->dashboardDomain,
                'https://www.' . $this->dashboardDomain,
            ];
        }
        
        // Development origins
        return [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:5173',
            'http://127.0.0.1:5173',
        ];
    }

    /**
     * Get main domain with protocol
     */
    public function getMainDomainUrl(): string
    {
        return 'https://' . $this->mainDomain;
    }

    /**
     * Get admin subdomain with protocol
     */
    public function getAdminSubdomainUrl(): string
    {
        return 'https://' . $this->adminSubdomain;
    }

    /**
     * Get dashboard domain with protocol
     */
    public function getDashboardDomainUrl(): string
    {
        return 'https://' . $this->dashboardDomain;
    }
}
