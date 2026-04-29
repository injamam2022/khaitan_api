<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * EasyEcom API configuration (Self-Ship / Outbound Marketplace).
 * Set these in .env; do not commit real credentials.
 */
class EasyEcom extends BaseConfig
{
    /** API base URL */
    public string $baseUrl = 'https://api.easyecom.io';

    /** API email (for token auth; set EASYECOM_API_USERNAME in .env) */
    public string $apiUsername = '';

    /** API Password (for JWT generation) */
    public string $apiPassword = '';

    /** Location Key (warehouse/location identifier) */
    public string $locationKey = '';

    /** x-api-key (required in every request header) */
    public string $xApiKey = '';

    /** SKU mapping table name (default easyecom_sku_mapping; use easy_ecom_sku_mapping if your DB has that) */
    public string $skuMappingTable = 'easyecom_sku_mapping';

    /** Token cache table name (default easyecom_tokens). If your DB has easycom_tokens, set EASYECOM_TOKENS_TABLE=easycom_tokens in .env */
    public string $tokensTable = 'easyecom_tokens';

    /** Auth token path. EasyEcom uses POST /access/token with email, password, location_key and header x-api-key. */
    public string $loginPath = 'access/token';

    /** Create (retail) order API path. EasyEcom B2C uses webhook/v2/createOrder. */
    public string $createOrderPath = 'webhook/v2/createOrder';

    /** Marketplace ID for create order payload (default 10). Set EASYECOM_MARKETPLACE_ID in .env if required. */
    public int $marketplaceId = 10;

    /**
     * Webhook secret for validating incoming EasyEcom webhooks (optional; EasyEcom webhooks do not send Bearer/Access-Token).
     * Kept for backwards compatibility; webhook auth uses IP allowlist and/or X-Easyecom-Company-Id.
     */
    public string $webhookSecret = '';

    /**
     * Allowed IPs for EasyEcom webhook requests. Comma-separated in .env (EASYECOM_WEBHOOK_ALLOWED_IPS).
     * Default: EasyEcom callback IPs 13.234.247.69, 43.204.152.153.
     */
    public array $webhookAllowedIps = [];

    /**
     * Optional: expected X-Easyecom-Company-Id header value. If set, requests with this header are accepted (in addition to IP allowlist).
     */
    public string $webhookCompanyId = '';

    /**
     * Deprecated. Inventory sync always uses POST /updateVirtualInventoryAPI (Virtual Inventory).
     * Kept for .env compatibility; no longer used in code.
     */
    public bool $useVirtualInventory = false;

    // -----------------------------------------------------------------------
    // Carrier Outbound API (e.g. Delhivery via EasyEcom)
    // -----------------------------------------------------------------------

    /** Carrier API base URL (e.g. EasyEcom Carrier/Delhivery proxy). Set EASYECOM_CARRIER_BASE_URL in .env */
    public string $carrierBaseUrl = '';

    /** Carrier username (Delhivery/carrier account). Set EASYECOM_CARRIER_USERNAME in .env */
    public string $carrierUsername = '';

    /** Carrier password. Set EASYECOM_CARRIER_PASSWORD in .env */
    public string $carrierPassword = '';

    /** Carrier token. Set EASYECOM_CARRIER_TOKEN in .env */
    public string $carrierToken = '';

    /** Carrier account number. Set EASYECOM_CARRIER_ACCOUNT_NO in .env */
    public string $carrierAccountNo = '';

    /** Warehouse ID for shipment (EasyEcom warehouse). Set EASYECOM_CARRIER_WAREHOUSE_ID in .env */
    public string $carrierWarehouseId = '';

    /** Company name for shipment payload. Set EASYECOM_CARRIER_COMPANY_NAME in .env */
    public string $carrierCompanyName = '';

    /** Carrier name/code for cancelShipment awb_details.courier (e.g. Delhivery). Set EASYECOM_CARRIER_NAME in .env; falls back to carrierCompanyName */
    public string $carrierName = '';

    /** Pickup contact (phone). Set EASYECOM_CARRIER_PICKUP_CONTACT in .env */
    public string $carrierPickupContact = '';

    /** Pickup address for carrier. Set EASYECOM_CARRIER_PICKUP_* in .env */
    public string $carrierPickupAddress = '';
    public string $carrierPickupCity = '';
    public string $carrierPickupState = '';
    public string $carrierPickupStateCode = '';
    public string $carrierPickupPinCode = '';
    public string $carrierPickupCountry = 'India';

    /** Send carrier API request body gzip-encoded (matches official createShipment curl). Set EASYECOM_CARRIER_GZIP_REQUEST=true in .env */
    public bool $carrierGzipRequest = false;

    public function __construct()
    {
        parent::__construct();
        $baseUrl = env('EASYECOM_BASE_URL', '');
        if ($baseUrl !== '') {
            $this->baseUrl = rtrim($baseUrl, '/');
        }
        $this->apiUsername = env('EASYECOM_API_USERNAME', '');
        $this->apiPassword = env('EASYECOM_API_PASSWORD', '');
        $this->locationKey  = env('EASYECOM_LOCATION_KEY', '');
        $this->xApiKey      = env('EASYECOM_X_API_KEY', '');
        $this->skuMappingTable = env('EASYECOM_SKU_MAPPING_TABLE', 'easyecom_sku_mapping');
        $tokensTable = env('EASYECOM_TOKENS_TABLE', '');
        if ($tokensTable !== '') {
            $this->tokensTable = $tokensTable;
        }
        $loginPath = env('EASYECOM_LOGIN_PATH', '');
        if ($loginPath !== '') {
            $this->loginPath = $loginPath;
        }
        $createPath = env('EASYECOM_CREATE_ORDER_PATH', '');
        if ($createPath !== '') {
            $this->createOrderPath = $createPath;
        }
        $mktId = env('EASYECOM_MARKETPLACE_ID', '');
        if ($mktId !== '' && is_numeric($mktId)) {
            $this->marketplaceId = (int) $mktId;
        }
        $this->webhookSecret = (string) env('EASYECOM_WEBHOOK_SECRET', '');
        $allowedIps = env('EASYECOM_WEBHOOK_ALLOWED_IPS', '13.234.247.69,43.204.152.153');
        $this->webhookAllowedIps = array_map('trim', array_filter(explode(',', (string) $allowedIps)));
        $this->webhookCompanyId = (string) env('EASYECOM_WEBHOOK_COMPANY_ID', '');
        $this->useVirtualInventory = filter_var(env('EASYECOM_USE_VIRTUAL_INVENTORY', 'false'), FILTER_VALIDATE_BOOLEAN);

        // Carrier (Delhivery / Outbound) config
        $carrierUrl = env('EASYECOM_CARRIER_BASE_URL', '');
        if ($carrierUrl !== '') {
            $this->carrierBaseUrl = rtrim($carrierUrl, '/');
        }
        $this->carrierUsername   = (string) env('EASYECOM_CARRIER_USERNAME', '');
        $this->carrierPassword   = (string) env('EASYECOM_CARRIER_PASSWORD', '');
        $this->carrierToken      = (string) env('EASYECOM_CARRIER_TOKEN', '');
        $this->carrierAccountNo  = (string) env('EASYECOM_CARRIER_ACCOUNT_NO', '');
        $this->carrierWarehouseId = (string) env('EASYECOM_CARRIER_WAREHOUSE_ID', '');
        $this->carrierCompanyName = (string) env('EASYECOM_CARRIER_COMPANY_NAME', '');
        $carrierName = env('EASYECOM_CARRIER_NAME', '');
        $this->carrierName = $carrierName !== '' ? (string) $carrierName : $this->carrierCompanyName;
        $this->carrierPickupContact   = (string) env('EASYECOM_CARRIER_PICKUP_CONTACT', '');
        $this->carrierPickupAddress   = (string) env('EASYECOM_CARRIER_PICKUP_ADDRESS', '');
        $this->carrierPickupCity      = (string) env('EASYECOM_CARRIER_PICKUP_CITY', '');
        $this->carrierPickupState     = (string) env('EASYECOM_CARRIER_PICKUP_STATE', '');
        $this->carrierPickupStateCode = (string) env('EASYECOM_CARRIER_PICKUP_STATE_CODE', '');
        $this->carrierPickupPinCode   = (string) env('EASYECOM_CARRIER_PICKUP_PIN_CODE', '');
        $pickupCountry = env('EASYECOM_CARRIER_PICKUP_COUNTRY', '');
        if ($pickupCountry !== '') {
            $this->carrierPickupCountry = $pickupCountry;
        }
        $this->carrierGzipRequest = filter_var(env('EASYECOM_CARRIER_GZIP_REQUEST', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    /** Whether integration is configured (credentials set) */
    public function isConfigured(): bool
    {
        return $this->apiUsername !== ''
            && $this->apiPassword !== ''
            && $this->xApiKey !== '';
    }

    /** Whether carrier (shipment) API is configured */
    public function isCarrierConfigured(): bool
    {
        return $this->carrierBaseUrl !== ''
            && $this->carrierUsername !== ''
            && $this->carrierPassword !== '';
    }

    /**
     * Build credentials object for Carrier API (createShipment, listCarriers).
     * eeApiToken = "apiUsername,apiPassword,locationKey" per EasyEcom carrier docs.
     */
    public function getCarrierCredentials(): array
    {
        $eeApiToken = implode(',', [
            $this->apiUsername,
            $this->apiPassword,
            $this->locationKey,
        ]);
        return [
            'username'   => $this->carrierUsername,
            'password'   => $this->carrierPassword,
            'token'      => $this->carrierToken,
            'account_no' => $this->carrierAccountNo,
            'service_type' => '',
            'eeApiToken' => $eeApiToken,
        ];
    }
}
