<?php

namespace App\Libraries;

use Config\Razorpay as RazorpayConfig;

/**
 * Minimal Razorpay REST client (no SDK dependency).
 */
class RazorpayService
{
    private string $keyId;
    private string $keySecret;

    public function __construct(?RazorpayConfig $config = null)
    {
        $config ??= config(RazorpayConfig::class);
        $this->keyId = trim($config->keyId);
        $this->keySecret = trim($config->keySecret);
    }

    public function isConfigured(): bool
    {
        return $this->keyId !== '' && $this->keySecret !== '';
    }

    public function getKeyId(): string
    {
        return $this->keyId;
    }

    /**
     * @return array{id: string, amount: int, currency: string, receipt: string, status: string}
     */
    public function createOrder(int $amountPaise, string $receipt, array $notes = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'Razorpay is not configured. Add RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET to khaitan_api/.env, then restart Apache.'
            );
        }

        if ($amountPaise < 100) {
            throw new \InvalidArgumentException('Order amount must be at least ₹1 (100 paise)');
        }

        $payload = [
            'amount'   => $amountPaise,
            'currency' => 'INR',
            'receipt'  => $receipt,
            'notes'    => $notes,
        ];

        $response = $this->request('POST', 'orders', $payload);

        if (empty($response['id'])) {
            throw new \RuntimeException('Razorpay order creation failed');
        }

        return $response;
    }

    public function verifySignature(string $razorpayOrderId, string $razorpayPaymentId, string $razorpaySignature): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $expected = hash_hmac('sha256', $razorpayOrderId . '|' . $razorpayPaymentId, $this->keySecret);

        return hash_equals($expected, $razorpaySignature);
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $url = 'https://api.razorpay.com/v1/' . ltrim($path, '/');
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->keyId . ':' . $this->keySecret,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 30,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException('Razorpay request failed: ' . $error);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid Razorpay response');
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $decoded['error']['description'] ?? $decoded['error']['reason'] ?? 'Razorpay API error';
            throw new \RuntimeException((string) $message);
        }

        return $decoded;
    }
}
