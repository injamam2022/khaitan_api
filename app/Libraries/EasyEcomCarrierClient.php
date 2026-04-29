<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Config\EasyEcom as EasyEcomConfig;

/**
 * EasyEcom Carrier Outbound API client (e.g. Delhivery via EasyEcom).
 * Uses Carrier Base URL and credentials in request body (no main API Bearer/x-api-key).
 *
 * Documented collection: https://api-docs.easyecom.io/#72423b60-baae-42ab-a0bb-7ee9699ff035
 *
 * Endpoints: /authenticate, /listCarriers, /createShipment, /cancelShipment, /tracking-auth, /updateTrackingStatus.
 * JSON POSTs are centralized in carrierPost(); updateTrackingStatus adds Bearer from tracking-auth when provided.
 */
class EasyEcomCarrierClient
{
    private const LOG_PREFIX = 'EasyEcom: ';

    private EasyEcomConfig $config;

    public function __construct(?EasyEcomConfig $config = null)
    {
        $this->config = $config ?? config(EasyEcomConfig::class);
    }

    public function isConfigured(): bool
    {
        return $this->config->isCarrierConfigured();
    }

    // -----------------------------------------------------------------------
    // 1. Carrier Authentication
    // -----------------------------------------------------------------------

    /**
     * POST {{CarrierBaseURL}}/authenticate
     * Payload: username, password, token, account_no, service_type, eeApiToken
     *
     * @return array Decoded response with _status; 200 = success
     */
    public function authenticate(): array
    {
        $url  = rtrim($this->config->carrierBaseUrl, '/') . '/authenticate';
        $body = array_merge(
            $this->config->getCarrierCredentials(),
            ['service_type' => '']
        );

        log_message('info', self::LOG_PREFIX . '[CARRIER_AUTH] requesting authentication');

        $response = $this->carrierPost($url, $body, '[CARRIER_AUTH]');
        $status   = $response['_status'] ?? 0;

        if ($status === 200) {
            log_message('info', self::LOG_PREFIX . '[CARRIER_AUTH] success response=200');
        } else {
            log_message('error', self::LOG_PREFIX . '[CARRIER_AUTH] failed response=' . $status);
        }

        return $response;
    }

    // -----------------------------------------------------------------------
    // 2. List carriers
    // -----------------------------------------------------------------------

    /**
     * POST {{CarrierBaseURL}}/listCarriers
     * Lists carriers available for the account (credential envelope per EasyEcom Carrier docs).
     */
    public function listCarriers(): array
    {
        $url  = rtrim($this->config->carrierBaseUrl, '/') . '/listCarriers';
        $body = [
            'credentials' => $this->config->getCarrierCredentials(),
        ];

        log_message('info', self::LOG_PREFIX . '[LIST_CARRIERS] requesting');

        return $this->carrierPost($url, $body, '[LIST_CARRIERS]');
    }

    // -----------------------------------------------------------------------
    // 3. Create Shipment
    // -----------------------------------------------------------------------

    /**
     * POST {{CarrierBaseURL}}/createShipment
     * Body: order_data + credentials
     *
     * @param array $orderData Must include reference_code, order_id, warehouse/location, package dimensions, weight, order_items (SKU)
     * @return array Decoded response; capture tracking_number, shipment_label, awb_number, carrier_name
     */
    public function createShipment(array $orderData): array
    {
        $url  = rtrim($this->config->carrierBaseUrl, '/') . '/createShipment';
        $body = [
            'order_data'  => $orderData,
            'credentials' => $this->config->getCarrierCredentials(),
        ];

        $ref = $orderData['reference_code'] ?? $orderData['order_no'] ?? '';
        log_message('info', self::LOG_PREFIX . '[CREATE_SHIPMENT] order=' . $ref . ' calling API');

        return $this->carrierPost($url, $body, '[CREATE_SHIPMENT]');
    }

    // -----------------------------------------------------------------------
    // 4. Cancel shipment
    // -----------------------------------------------------------------------

    /**
     * POST {{CarrierBaseURL}}/cancelShipment
     * Payload matches official API: awb_details { awb, courier } + credentials.
     * Trigger when order cancelled or shipment reassigned.
     *
     * @param string $awb AWB number to cancel
     * @return array Decoded response with _status
     */
    public function cancelShipment(string $awb): array
    {
        $url  = rtrim($this->config->carrierBaseUrl, '/') . '/cancelShipment';
        $body = [
            'awb_details' => [
                'awb'     => $awb,
                'courier' => $this->config->carrierName !== '' ? $this->config->carrierName : 'Delhivery',
            ],
            'credentials' => $this->config->getCarrierCredentials(),
        ];

        log_message('info', self::LOG_PREFIX . '[CANCEL_SHIPMENT] awb=' . $awb . ' requesting');

        return $this->carrierPost($url, $body, '[CANCEL_SHIPMENT]');
    }

    // -----------------------------------------------------------------------
    // 5. Tracking Authorization
    // -----------------------------------------------------------------------

    /**
     * POST {{CarrierBaseURL}}/tracking-auth
     * Payload: email, password, location_key. Returns JWT for tracking updates.
     *
     * @return array Decoded response; token in 'token' or 'access_token' or 'jwt'
     */
    public function trackingAuth(): array
    {
        $url  = rtrim($this->config->carrierBaseUrl, '/') . '/tracking-auth';
        $body = [
            'email'        => $this->config->apiUsername,
            'password'     => $this->config->apiPassword,
            'location_key' => $this->config->locationKey,
        ];

        log_message('info', self::LOG_PREFIX . '[TRACKING_AUTH] requesting JWT');

        return $this->carrierPost($url, $body, '[TRACKING_AUTH]');
    }

    // -----------------------------------------------------------------------
    // 6. Update Tracking Status
    // -----------------------------------------------------------------------

    /**
     * POST {{CarrierBaseURL}}/updateTrackingStatus
     * Payload: current_shipment_status_id, awb, estimated_delivery_date (and any carrier-specific fields).
     * Pass $bearerToken from trackingAuth() — required by the Carrier API for this route.
     *
     * @param array  $trackingData current_shipment_status_id, awb, estimated_delivery_date, etc.
     * @param string $bearerToken  JWT from trackingAuth()
     * @return array Decoded response with _status
     */
    public function updateTrackingStatus(array $trackingData, string $bearerToken): array
    {
        $url = rtrim($this->config->carrierBaseUrl, '/') . '/updateTrackingStatus';

        log_message('info', self::LOG_PREFIX . '[TRACKING_UPDATE] awb=' . ($trackingData['awb'] ?? $trackingData['awb_number'] ?? '-') . ' status=' . ($trackingData['current_shipment_status_id'] ?? $trackingData['status'] ?? '-'));

        return $this->carrierPost($url, $trackingData, '[TRACKING_UPDATE]', $bearerToken);
    }

    // -----------------------------------------------------------------------
    // Centralized HTTP
    // -----------------------------------------------------------------------

    /**
     * POST to carrier API with JSON body. Matches official createShipment curl:
     * Cache-Control, Connection, Content-Type, optional Content-Encoding: gzip.
     * When carrierGzipRequest is true, body is gzip-encoded before send.
     * Optional Bearer token (e.g. updateTrackingStatus after tracking-auth).
     */
    private function carrierPost(string $url, array $body, string $logTag, ?string $bearerToken = null): array
    {
        $headers = [
            'Cache-Control'  => 'private,must-revalidate',
            'Connection'     => 'keep-alive',
            'Content-Type'   => 'application/json',
            'Accept'         => 'application/json',
        ];
        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer ' . $bearerToken;
        }

        $options = ['headers' => $headers];

        if (! empty($this->config->carrierGzipRequest) && $bearerToken === null) {
            $json    = json_encode($body);
            $encoded = gzencode($json, -1, FORCE_GZIP);
            if ($encoded === false) {
                log_message('error', self::LOG_PREFIX . $logTag . ' gzip encode failed');
                $options['json'] = $body;
            } else {
                $headers['Content-Encoding'] = 'gzip';
                $options['headers']         = $headers;
                $options['body']           = $encoded;
            }
        } elseif (! empty($this->config->carrierGzipRequest) && $bearerToken !== null) {
            // Bearer requests (e.g. updateTrackingStatus): send JSON without gzip unless EasyEcom documents otherwise.
            $options['json'] = $body;
        } else {
            $options['json'] = $body;
        }

        $client   = $this->getHttpClient($url);
        $response = $client->post($url, $options);

        return $this->decodeResponse($response, $url, $logTag);
    }

    private function getHttpClient(string $baseUri): CURLRequest
    {
        return service('curlrequest', [
            'baseURI' => $baseUri,
            'timeout' => 60,
        ]);
    }

    private function decodeResponse(ResponseInterface $response, string $url, string $logTag = ''): array
    {
        $status = $response->getStatusCode();
        $raw    = $response->getBody();
        $data   = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message('error', self::LOG_PREFIX . $logTag . ' non-JSON response from ' . $url . ': ' . substr((string) $raw, 0, 500));
            return ['_raw' => $raw, '_status' => $status];
        }

        if ($status < 200 || $status >= 300) {
            log_message('error', self::LOG_PREFIX . $logTag . ' error status=' . $status . ' url=' . $url . ' response=' . json_encode($data));
        }

        $data['_status'] = $status;
        return $data;
    }
}
