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
    /**
     * CORS origins. In production, `CORS_ALLOWED_ORIGINS` is merged with canonical
     * storefront/admin/dashboard URLs so a dev-only `.env` value cannot block khaitan.com.
     */
    public function getAllowedOrigins(): array
    {
        $fromEnv = [];
        $raw = env('CORS_ALLOWED_ORIGINS', '');
        if ($raw !== '') {
            $fromEnv = array_map('trim', array_filter(explode(',', $raw)));
        }

        if (ENVIRONMENT === 'production') {
            $canonical = [
                'https://' . $this->mainDomain,
                'https://www.' . $this->mainDomain,
                'https://' . $this->adminSubdomain,
                'https://www.' . $this->adminSubdomain,
                'https://' . $this->dashboardDomain,
                'https://www.' . $this->dashboardDomain,
            ];
            return array_values(array_unique(array_merge($canonical, $fromEnv)));
        }

        if ($fromEnv !== []) {
            return $fromEnv;
        }

        return [
            'http://localhost:3000',
            'http://127.0.0.1:3000',
            'http://localhost:3001',
            'http://127.0.0.1:3001',
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
