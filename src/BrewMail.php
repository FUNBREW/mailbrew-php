<?php

declare(strict_types=1);

namespace BrewMail;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

class BrewMail
{
    private Client $http;

    private string $baseUrl;

    public function __construct(
        private string $apiKey,
        string $baseUrl = 'https://brewmail.dev',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new Client([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'timeout' => 65,
        ]);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    // ── Addresses ──────────────────────────────────────────

    /**
     * Create a temporary email address.
     *
     * @return array{id: int, address: string, webhook_url: ?string, expires_at: string, created_at: string}
     */
    public function createAddress(?int $ttl = null, ?string $webhookUrl = null, ?string $localPart = null): array
    {
        $body = [];
        if ($ttl !== null) {
            $body['ttl'] = $ttl;
        }
        if ($webhookUrl !== null) {
            $body['webhook_url'] = $webhookUrl;
        }
        if ($localPart !== null) {
            $body['local_part'] = $localPart;
        }

        return $this->post('/api/mailbrew/addresses', $body)['data'];
    }

    /** List all addresses. */
    public function listAddresses(): array
    {
        return $this->get('/api/mailbrew/addresses')['data'];
    }

    /** Get address details. */
    public function getAddress(string $address): array
    {
        return $this->get("/api/mailbrew/addresses/{$address}")['data'];
    }

    /** Delete an address. */
    public function deleteAddress(string $address): void
    {
        $this->request('DELETE', "/api/mailbrew/addresses/{$address}");
    }

    // ── Emails ─────────────────────────────────────────────

    /** List emails for an address. */
    public function listEmails(string $address): array
    {
        return $this->get("/api/mailbrew/addresses/{$address}/emails")['data'];
    }

    /** Get email details. */
    public function getEmail(string $address, int $emailId): array
    {
        return $this->get("/api/mailbrew/addresses/{$address}/emails/{$emailId}")['data'];
    }

    /** Extract OTP code from an email. */
    public function extractOtp(string $address, int $emailId, ?string $pattern = null): string
    {
        $query = $pattern ? '?pattern='.urlencode($pattern) : '';

        return $this->get("/api/mailbrew/addresses/{$address}/emails/{$emailId}/otp{$query}")['data']['code'];
    }

    /** Extract links from an email. */
    public function extractLinks(string $address, int $emailId, ?string $pattern = null): array
    {
        $query = $pattern ? '?pattern='.urlencode($pattern) : '';

        return $this->get("/api/mailbrew/addresses/{$address}/emails/{$emailId}/links{$query}")['data']['links'];
    }

    /**
     * Assert email properties (subject, from, to, body).
     *
     * @return array{passed: bool, email_id: int, results: array}
     */
    public function assertEmail(string $address, int $emailId, array $assertions): array
    {
        return $this->post("/api/mailbrew/addresses/{$address}/emails/{$emailId}/assert", $assertions)['data'];
    }

    /**
     * Analyze email deliverability (SPF, DKIM, DMARC, etc.)
     *
     * @return array{score: int, checks: array, recommendations: array}
     */
    public function deliverability(string $address, int $emailId): array
    {
        return $this->get("/api/mailbrew/addresses/{$address}/emails/{$emailId}/deliverability")['data'];
    }

    /**
     * Verify a sequence of emails arrives in order.
     *
     * @param  array<int, array{subject?: string, subject_contains?: string, from?: string, to?: string, body_contains?: string}>  $steps
     * @return array{passed: bool, steps: array}
     */
    public function expectSequence(string $address, array $steps, int $timeout = 30): array
    {
        return $this->post("/api/mailbrew/addresses/{$address}/sequence", [
            'steps' => $steps,
            'timeout' => $timeout,
        ])['data'];
    }

    /** Forward an email to another address. */
    public function forwardEmail(string $address, int $emailId, string $to): array
    {
        return $this->post("/api/mailbrew/addresses/{$address}/emails/{$emailId}/forward", ['to' => $to]);
    }

    // ── Wait for Email ─────────────────────────────────────

    /**
     * Wait for email(s) to arrive using server-side long-polling.
     *
     * @param  int  $timeout  Timeout in seconds (max 60)
     * @param  int  $count  Number of emails to wait for
     * @param  string|null  $since  Only return emails received after this ISO8601 timestamp
     * @return array List of emails (may be empty if timeout)
     */
    public function waitForEmails(string $address, int $timeout = 30, int $count = 1, ?string $since = null): array
    {
        $params = ['timeout' => min($timeout, 60), 'count' => $count];
        if ($since !== null) {
            $params['since'] = $since;
        }

        $query = http_build_query($params);

        return $this->get("/api/mailbrew/addresses/{$address}/emails/wait?{$query}")['data'];
    }

    /**
     * Wait for a single email to arrive.
     *
     * @throws BrewMailException If no email arrives within the timeout
     */
    public function waitForEmail(string $address, int $timeout = 30, ?string $since = null): array
    {
        $emails = $this->waitForEmails($address, $timeout, 1, $since);

        if (empty($emails)) {
            throw new BrewMailException('Timeout waiting for email');
        }

        return $emails[0];
    }

    // ── Webhooks ───────────────────────────────────────────

    /** List global webhooks. */
    public function listWebhooks(): array
    {
        return $this->get('/api/mailbrew/webhooks')['data'];
    }

    /** Create a global webhook. */
    public function createWebhook(string $url, ?array $events = null): array
    {
        $body = ['url' => $url];
        if ($events !== null) {
            $body['events'] = $events;
        }

        return $this->post('/api/mailbrew/webhooks', $body)['data'];
    }

    /** Update a webhook. */
    public function updateWebhook(int $id, array $data): array
    {
        return $this->request('PUT', "/api/mailbrew/webhooks/{$id}", $data)['data'];
    }

    /** Delete a webhook. */
    public function deleteWebhook(int $id): void
    {
        $this->request('DELETE', "/api/mailbrew/webhooks/{$id}");
    }

    // ── Convenience ────────────────────────────────────────

    /**
     * Create address + prepare OTP capture — all in one call.
     *
     * Usage:
     *   $capture = $client->captureOtp(ttl: 300);
     *   // ... trigger email send to $capture->address ...
     *   $result = $capture->waitAndExtract();
     *   echo $result['code']; // "847291"
     */
    public function captureOtp(int $ttl = 300, int $timeout = 30, ?string $pattern = null): OtpCapture
    {
        $addr = $this->createAddress(ttl: $ttl);

        return new OtpCapture($this, $addr['address'], $timeout, $pattern);
    }

    // ── HTTP helpers ───────────────────────────────────────

    private function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    private function post(string $path, array $data = []): array
    {
        return $this->request('POST', $path, $data);
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        try {
            $options = [];
            if ($data !== null && $method !== 'GET') {
                $options['json'] = $data;
            }

            $response = $this->http->request($method, $path, $options);

            if ($response->getStatusCode() === 204) {
                return [];
            }

            $body = json_decode($response->getBody()->getContents(), true);

            return $body ?? [];
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $statusCode = $response ? $response->getStatusCode() : 0;
            $body = $response ? json_decode($response->getBody()->getContents(), true) : null;
            $message = $body['message'] ?? 'API request failed';

            throw new BrewMailException($message, $statusCode, $e);
        } catch (GuzzleException $e) {
            throw new BrewMailException('Network error: '.$e->getMessage(), 0, $e);
        }
    }
}
