<?php

declare(strict_types=1);

namespace MailBrew;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;

class MailBrew
{
    private Client $http;

    private string $baseUrl;

    /**
     * @param  int  $maxRetries  How many times to retry on 429, waiting for Retry-After (default: 2)
     * @param  callable|null  $handler  Guzzle handler (for tests)
     */
    public function __construct(
        private string $apiKey,
        string $baseUrl = 'https://mailbrew.dev',
        private int $maxRetries = 2,
        ?callable $handler = null,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new Client([
            'handler' => $handler ?? HandlerStack::create(),
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
     * Assert email properties.
     *
     * Keys: subject, subject_contains, subject_matches, from, to, body_contains, body_not_contains,
     * body_matches, html_contains, header (['name' => ..., 'value' => ...]), has_attachment (bool), attachment_filename.
     *
     * @param  array<string, mixed>  $assertions
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
     * @param  array<int, array<string, mixed>>  $steps  Conditions for each step (same keys as assertEmail)
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
     * @param  int  $count  Number of matching emails to wait for
     * @param  string|null  $since  Only return emails received after this ISO8601 timestamp
     * @param  array<string, mixed>  $where  Only wait for emails that match (same keys as assertEmail, except "to"; header is ['name' => ..., 'value' => ...])
     * @param  list<string>|string|null  $extract  "otp" and/or "links": adds "extracted" to each email
     * @return array List of emails (may be empty if timeout)
     */
    public function waitForEmails(string $address, int $timeout = 30, int $count = 1, ?string $since = null, array $where = [], array|string|null $extract = null): array
    {
        $params = ['timeout' => min($timeout, 60), 'count' => $count];
        if ($since !== null) {
            $params['since'] = $since;
        }
        $params += self::whereParams($where);
        if ($extract !== null && $extract !== [] && $extract !== '') {
            $params['extract'] = is_array($extract) ? implode(',', $extract) : $extract;
        }

        $query = http_build_query($params);

        return $this->get("/api/mailbrew/addresses/{$address}/emails/wait?{$query}")['data'];
    }

    /**
     * Wait for a single email to arrive.
     *
     * @param  array<string, mixed>  $where  Only wait for an email that matches (same keys as assertEmail, except "to")
     * @param  list<string>|string|null  $extract  "otp" and/or "links": adds "extracted" to the email
     *
     * @throws MailBrewException If no email arrives within the timeout
     */
    public function waitForEmail(string $address, int $timeout = 30, ?string $since = null, array $where = [], array|string|null $extract = null): array
    {
        $emails = $this->waitForEmails($address, $timeout, 1, $since, $where, $extract);

        if (empty($emails)) {
            throw new MailBrewException('Timeout waiting for email');
        }

        return $emails[0];
    }

    /**
     * wait の条件をクエリに直す。header は header[name] / header[value]、真偽は "true" / "false"。
     *
     * @param  array<string, mixed>  $where
     * @return array<string, mixed>
     */
    private static function whereParams(array $where): array
    {
        $params = [];
        foreach ($where as $key => $value) {
            if ($value === null) {
                continue;
            }
            $params[$key] = is_bool($value) ? ($value ? 'true' : 'false') : $value;
        }

        return $params;
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
    public function captureOtp(int $ttl = 300, int $timeout = 30, ?string $pattern = null, array $where = []): OtpCapture
    {
        $addr = $this->createAddress(ttl: $ttl);

        return new OtpCapture($this, $addr['address'], $timeout, $pattern, $where);
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
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->send($method, $path, $data);
            } catch (MailBrewException $e) {
                if ($e->statusCode !== 429 || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                sleep($e->retryAfter ?? 1);
            }
        }
    }

    private function send(string $method, string $path, ?array $data): array
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
            $retryAfter = $statusCode === 429 ? self::parseRetryAfter($response?->getHeaderLine('Retry-After') ?? '') : null;

            throw new MailBrewException($message, $statusCode, $e, $retryAfter);
        } catch (GuzzleException $e) {
            throw new MailBrewException('Network error: '.$e->getMessage(), 0, $e);
        }
    }

    /** Retry-After は秒。無い・読めないときは 1 秒。 */
    private static function parseRetryAfter(string $value): int
    {
        return ctype_digit(trim($value)) ? (int) trim($value) : 1;
    }
}
