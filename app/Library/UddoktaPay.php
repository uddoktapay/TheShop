<?php

namespace App\Library;

use Exception;

/**
 * UddoktaPay Payment Gateway Integration
 */
class UddoktaPay
{
    private const API_HEADER_KEY = 'RT-UDDOKTAPAY-API-KEY';
    private const DEFAULT_TIMEOUT = 30;
    private const CHECKOUT_V2 = 'checkout-v2';
    private const VERIFY_ENDPOINT = 'verify-payment';

    private string $apiKey;
    private string $apiBaseURL;
    private int $timeout;
    private bool $verifySsl;

    /**
     * Initialize UddoktaPay client
     *
     * @param string $apiKey Your UddoktaPay API key
     * @param string $apiBaseURL Base URL for the API
     * @param int $timeout Request timeout in seconds
     * @param bool $verifySsl Whether to verify SSL certificates
     */
    private function __construct(
        string $apiKey,
        string $apiBaseURL,
        int $timeout = self::DEFAULT_TIMEOUT,
        bool $verifySsl = true
    ) {
        if (empty(trim($apiKey))) {
            throw new Exception('API Key cannot be empty');
        }
        
        $this->apiKey = trim($apiKey);
        $this->apiBaseURL = $this->normalizeBaseURL($apiBaseURL);
        $this->timeout = max(1, $timeout);
        $this->verifySsl = $verifySsl;
    }

    /**
     * Create a new UddoktaPay instance
     *
     * @param string $apiKey Your UddoktaPay API key
     * @param string $apiBaseURL Base URL for the API
     * @param int $timeout Request timeout in seconds
     * @param bool $verifySsl Whether to verify SSL certificates
     * @return self
     */
    public static function make(
        string $apiKey,
        string $apiBaseURL,
        int $timeout = self::DEFAULT_TIMEOUT,
        bool $verifySsl = true
    ): self {
        return new self($apiKey, $apiBaseURL, $timeout, $verifySsl);
    }

    /**
     * Normalize the base URL
     */
    private function normalizeBaseURL(string $apiBaseURL): string
    {
        if (empty($apiBaseURL)) {
            throw new Exception('API Base URL cannot be empty');
        }

        $baseURL = rtrim($apiBaseURL, '/');
        $apiSegmentPosition = strpos($baseURL, '/api');
        
        if ($apiSegmentPosition !== false) {
            $baseURL = substr($baseURL, 0, $apiSegmentPosition + 4);
        }

        return $baseURL;
    }

    /**
     * Build full URL from endpoint
     */
    private function buildURL(string $endpoint): string
    {
        return $this->apiBaseURL . '/' . ltrim($endpoint, '/');
    }

    /**
     * Initialize a payment
     *
     * @param array $requestData Payment data (full_name, email, amount, metadata, etc.)
     * @param string $apiType API endpoint type (default: checkout-v2)
     * @return string Payment URL
     */
    public function initPayment(array $requestData, string $apiType = self::CHECKOUT_V2): string
    {
        $this->validatePaymentData($requestData);
        
        $apiUrl = $this->buildURL($apiType);
        $response = $this->sendRequest('POST', $apiUrl, $requestData);
        
        if (!isset($response['payment_url'])) {
            $message = $response['message'] ?? 'Payment initialization failed';
            throw new Exception($message);
        }

        return $response['payment_url'];
    }

    /**
     * Verify a payment by invoice ID
     *
     * @param string $invoiceId Invoice ID to verify
     * @return array Verification response
     */
    public function verifyPayment(string $invoiceId): array
    {
        if (empty(trim($invoiceId))) {
            throw new Exception('Invoice ID cannot be empty');
        }

        $verifyUrl = $this->buildURL(self::VERIFY_ENDPOINT);
        $requestData = ['invoice_id' => $invoiceId];
        
        return $this->sendRequest('POST', $verifyUrl, $requestData);
    }

    /**
     * Execute payment (IPN webhook handler)
     *
     * @return array Verification response
     */
    public function executePayment(): array
    {
        $headerApi = $_SERVER['HTTP_' . str_replace('-', '_', self::API_HEADER_KEY)] ?? null;
        
        if ($headerApi === null) {
            throw new Exception('Missing API key in request header');
        }

        if ($headerApi !== $this->apiKey) {
            throw new Exception('Invalid API key - Unauthorized');
        }

        $rawInput = trim(file_get_contents('php://input'));
        
        if (empty($rawInput)) {
            throw new Exception('Empty IPN response body');
        }
        
        $data = json_decode($rawInput, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON in IPN response: ' . json_last_error_msg());
        }

        if (!isset($data['invoice_id'])) {
            throw new Exception('Invoice ID missing in IPN data');
        }

        return $this->verifyPayment($data['invoice_id']);
    }

    /**
     * Send HTTP request to API
     */
    private function sendRequest(string $method, string $url, array $data): array
    {
        $headers = [
            self::API_HEADER_KEY . ': ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ];

        $jsonData = json_encode($data);
        if ($jsonData === false) {
            throw new Exception('Failed to encode request data: ' . json_last_error_msg());
        }

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $jsonData,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($error) {
            throw new Exception("cURL Error: $error");
        }

        if ($response === false) {
            throw new Exception('Empty response from API');
        }

        $decodedResponse = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . json_last_error_msg());
        }

        if ($httpCode >= 400) {
            $message = $decodedResponse['message'] ?? "HTTP Error: $httpCode";
            throw new Exception($message);
        }

        return $decodedResponse;
    }

    /**
     * Validate payment data before sending
     */
    private function validatePaymentData(array $data): void
    {
        // Required fields validation
        $requiredFields = ['full_name', 'email', 'amount', 'metadata'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new Exception("Required field missing: $field");
            }
            
            if (is_string($data[$field]) && trim($data[$field]) === '') {
                throw new Exception("Required field cannot be empty: $field");
            }
        }

        // Email validation
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address format');
        }

        // Amount validation
        if (!is_numeric($data['amount'])) {
            throw new Exception('Amount must be a number');
        }

        if ($data['amount'] <= 0) {
            throw new Exception('Amount must be greater than zero');
        }

        // Metadata validation
        if (!is_array($data['metadata'])) {
            throw new Exception('Metadata must be an array');
        }

        // Optional fields validation
        if (isset($data['redirect_url']) && !filter_var($data['redirect_url'], FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid redirect URL format');
        }

        if (isset($data['cancel_url']) && !filter_var($data['cancel_url'], FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid cancel URL format');
        }

        if (isset($data['webhook_url']) && !filter_var($data['webhook_url'], FILTER_VALIDATE_URL)) {
            throw new Exception('Invalid webhook URL format');
        }
    }
}
