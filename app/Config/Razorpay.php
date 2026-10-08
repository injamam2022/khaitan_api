<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Razorpay credentials for B2C checkout.
 * Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET in khaitan_api/.env
 */
class Razorpay extends BaseConfig
{
    public string $keyId = '';
    public string $keySecret = '';

    public function __construct()
    {
        parent::__construct();

        $this->keyId = $this->readEnv([
            'RAZORPAY_KEY_ID',
            'razorpay.keyId',
            'razorpay.key_id',
        ]);

        $this->keySecret = $this->readEnv([
            'RAZORPAY_KEY_SECRET',
            'razorpay.keySecret',
            'razorpay.key_secret',
        ]);
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    /**
     * @param list<string> $keys
     */
    private function readEnv(array $keys): string
    {
        foreach ($keys as $key) {
            $val = env($key, '');
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            $fromGetenv = getenv($key);
            if (is_string($fromGetenv) && trim($fromGetenv) !== '') {
                return trim($fromGetenv);
            }
            if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
                return trim($_ENV[$key]);
            }
        }

        return '';
    }
}
