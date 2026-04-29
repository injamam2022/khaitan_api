<?php

namespace App\Libraries;

use App\Models\ProfileModel;

/**
 * JWT (JSON Web Token) Library for CodeIgniter 4
 * 
 * Implements JWT token generation, validation, and refresh functionality.
 * Uses HS256 algorithm for simplicity and performance.
 * 
 * Environment Variables Required:
 * - JWT_SECRET: Secret key for signing tokens (min 32 chars recommended)
 * - JWT_ACCESS_EXPIRY: Access token expiration in seconds (default: 3600 = 1 hour)
 * - JWT_REFRESH_EXPIRY: Refresh token expiration in seconds (default: 86400 = 24 hours)
 */
class Jwt
{
    private $secret;
    private $access_expiry;
    private $refresh_expiry;
    private $algorithm = 'HS256';

    public function __construct()
    {
        // Load secret from environment
        $this->secret = getenv('JWT_SECRET') ?: $_ENV['JWT_SECRET'] ?? '';
        
        // JWT_SECRET is required - throw exception if not set
        if (empty($this->secret)) {
            throw new \RuntimeException(
                'JWT_SECRET must be set in environment variables. ' .
                'Set it in your .env file: JWT_SECRET=your_secret_here ' .
                '(minimum 32 characters, 64+ recommended for production)'
            );
        }
        
        // Ensure secret is at least 32 characters
        if (strlen($this->secret) < 32) {
            throw new \RuntimeException(
                'JWT_SECRET must be at least 32 characters long for security. ' .
                'Current length: ' . strlen($this->secret) . ' characters. ' .
                'Recommended: 64+ characters for production.'
            );
        }
        
        // Load expiry times from environment
        $this->access_expiry = (int)(getenv('JWT_ACCESS_EXPIRY') ?: $_ENV['JWT_ACCESS_EXPIRY'] ?? 3600);
        $this->refresh_expiry = (int)(getenv('JWT_REFRESH_EXPIRY') ?: $_ENV['JWT_REFRESH_EXPIRY'] ?? 86400);
    }

    /**
     * Generate JWT token
     * 
     * @param array $payload Token payload (will add iat, exp automatically)
     * @param int|null $expiry Override default expiry (in seconds)
     * @return string Encoded JWT token
     */
    public function encode(array $payload, $expiry = null)
    {
        $now = time();
        $exp = $now + ($expiry ?? $this->access_expiry);
        
        // Standard claims
        $token_payload = array_merge([
            'iat' => $now,  // Issued at
            'exp' => $exp,  // Expiration
        ], $payload);
        
        // Encode header
        $header = json_encode([
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ]);
        
        // Encode payload
        $payload_encoded = json_encode($token_payload);
        
        // Base64Url encode
        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlPayload = $this->base64UrlEncode($payload_encoded);
        
        // Create signature
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secret, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);
        
        // Return JWT
        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    /**
     * Decode and validate JWT token
     * 
     * @param string $token JWT token string
     * @return array|false Decoded payload or false if invalid
     */
    public function decode($token)
    {
        if (empty($token)) {
            return false;
        }
        
        // Split token into parts
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        list($base64UrlHeader, $base64UrlPayload, $base64UrlSignature) = $parts;
        
        // Verify signature
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $this->secret, true);
        $base64UrlSignatureExpected = $this->base64UrlEncode($signature);
        
        if (!hash_equals($base64UrlSignatureExpected, $base64UrlSignature)) {
            log_message('debug', 'JWT signature verification failed');
            return false;
        }
        
        // Decode payload
        $payload = json_decode($this->base64UrlDecode($base64UrlPayload), true);
        
        if ($payload === null) {
            return false;
        }
        
        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            log_message('debug', 'JWT token expired');
            return false;
        }
        
        return $payload;
    }

    /**
     * Generate access and refresh token pair
     * 
     * @param array $user_data User data to include in tokens
     * @return array ['access_token' => string, 'refresh_token' => string, 'expires_in' => int]
     */
    public function generateTokenPair(array $user_data)
    {
        // Access token payload (short-lived)
        $access_payload = [
            'user_id' => $user_data['id'] ?? $user_data['userid'] ?? null,
            'username' => $user_data['username'] ?? null,
            'usertype' => $user_data['usertype'] ?? null,
            'type' => 'access'
        ];
        
        // Refresh token payload (long-lived, minimal data)
        $refresh_payload = [
            'user_id' => $user_data['id'] ?? $user_data['userid'] ?? null,
            'type' => 'refresh'
        ];
        
        $access_token = $this->encode($access_payload, $this->access_expiry);
        $refresh_token = $this->encode($refresh_payload, $this->refresh_expiry);
        
        return [
            'access_token' => $access_token,
            'refresh_token' => $refresh_token,
            'expires_in' => $this->access_expiry,
            'token_type' => 'Bearer'
        ];
    }

    /**
     * Refresh access token using refresh token
     * 
     * @param string $refresh_token Refresh token
     * @return array|false New token pair or false if invalid
     */
    public function refresh($refresh_token)
    {
        $payload = $this->decode($refresh_token);
        
        if ($payload === false) {
            return false;
        }
        
        // Verify it's a refresh token
        if (!isset($payload['type']) || $payload['type'] !== 'refresh') {
            return false;
        }
        
        // Get user data from database
        if (!isset($payload['user_id'])) {
            return false;
        }
        
        $profileModel = new ProfileModel();
        $user = $profileModel->getUserById($payload['user_id']);
        
        if (!$user) {
            return false;
        }
        
        // Get usertype - handle both possible column names
        $usertype = isset($user->usertype) ? $user->usertype : (isset($user->user_type) ? $user->user_type : 'ADMIN');
        
        // Prepare user data for token generation
        $user_data = [
            'id' => $user->id,
            'userid' => $user->id,
            'username' => $user->username,
            'usertype' => $usertype,
            'fullname' => isset($user->fullname) ? $user->fullname : ''
        ];
        
        // Generate new token pair
        return $this->generateTokenPair($user_data);
    }

    /**
     * Base64Url encode
     * 
     * @param string $data Data to encode
     * @return string Base64Url encoded string
     */
    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64Url decode
     * 
     * @param string $data Base64Url encoded string
     * @return string Decoded string
     */
    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
