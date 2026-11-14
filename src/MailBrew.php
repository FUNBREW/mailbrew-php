<?php

declare(strict_types=1);

namespace MailBrew;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;

class MailBrew
{
    /** Page size the SDK asks for when it follows every page (the API's maximum). */
    public const PAGE_SIZE = 100;

    private Client $http;

    private string $baseUrl;

    /** @var array{run_id?: string, commit?: string, branch?: string} */
    private array $ci;

    private ?string $testName;

    /**
     * @param  int  $maxRetries  How many times to retry on 429, waiting for Retry-After (default: 2)
     * @param  callable|null  $handler  Guzzle handler (for tests)
     * @param  array{run_id?: ?string, commit?: ?string, branch?: ?string}|false|null  $ci
     *                                                                                      The CI run sent with every request (X-MailBrew-Run-Id / -Commit / -Branch).
     *                                                                                      null (default): detected from the environment (MAILBREW_RUN_ID /
     *                                                                                      MAILBREW_COMMIT / MAILBREW_BRANCH, then GitHub Actions, GitLab CI,
     *                                                                                      CircleCI). false: send nothing. An array sets the values.
     * @param  string|null  $testName  Test name sent as X-MailBrew-Test (see withTest)
     */
    public function __construct(
        private string $apiKey,
        string $baseUrl = 'https://mailbrew.dev',
        private int $maxRetries = 2,
        ?callable $handler = null,
        array|false|null $ci = null,
        ?string $testName = null,
    ) {
        if ($ci === false) {
            $this->ci = [];
        } elseif (is_array($ci)) {
            $this->ci = array_map('strval', array_filter(
                array_intersect_key($ci, ['run_id' => 1, 'commit' => 1, 'branch' => 1]),
                fn ($v) => $v !== null && $v !== '',
            ));
        } else {
            $this->ci = self::detectCiRun();
        }
        $this->testName = $testName !== null && $testName !== '' ? $testName : null;
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

    /**
     * The CI run this client sends (empty when disabled or not in CI).
     *
     * @return array{run_id?: string, commit?: string, branch?: string}
     */
    public function getCi(): array
    {
        return $this->ci;
    }

    public function getTestName(): ?string
    {
        return $this->testName;
    }

    /**
     * A copy of this client that sends the test's name (X-MailBrew-Test) with every request,
     * so results on the Test Results screen show which test made them. The original is unchanged.
     */
    public function withTest(string $name): static
    {
        $copy = clone $this;
        $copy->testName = $name !== '' ? $name : null;

        return $copy;
    }

    /**
     * Detect the CI run from environment variables (getenv() by default).
     * MAILBREW_RUN_ID / MAILBREW_COMMIT / MAILBREW_BRANCH win, then GitHub Actions, GitLab CI, CircleCI.
     *
     * @param  array<string, string>|null  $env
     * @return array{run_id?: string, commit?: string, branch?: string}
     */
    public static function detectCiRun(?array $env = null): array
    {
        $env ??= getenv();
        $pick = function (string ...$names) use ($env): ?string {
            foreach ($names as $name) {
                $value = trim((string) ($env[$name] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $runId = $commit = $branch = null;
        if ($pick('GITHUB_RUN_ID') !== null) {
            $runId = $pick('GITHUB_RUN_ID').'-'.($pick('GITHUB_RUN_ATTEMPT') ?? '1');
            $commit = $pick('GITHUB_SHA');
            $branch = $pick('GITHUB_HEAD_REF', 'GITHUB_REF_NAME');
        } elseif ($pick('CI_PIPELINE_ID') !== null) {
            $runId = $pick('CI_PIPELINE_ID');
            $commit = $pick('CI_COMMIT_SHA');
            $branch = $pick('CI_COMMIT_REF_NAME');
        } elseif ($pick('CIRCLE_WORKFLOW_ID') !== null) {
            $runId = $pick('CIRCLE_WORKFLOW_ID');
            $commit = $pick('CIRCLE_SHA1');
            $branch = $pick('CIRCLE_BRANCH');
        }

        return array_filter([
            'run_id' => $pick('MAILBREW_RUN_ID') ?? $runId,
            'commit' => $pick('MAILBREW_COMMIT') ?? $commit,
            'branch' => $pick('MAILBREW_BRANCH') ?? $branch,
        ], fn ($v) => $v !== null);
    }

    /**
     * Headers that tie requests to the CI run and test (only the values that are known).
     *
     * @return array<string, string>
     */
    private function ciHeaders(): array
    {
        $headers = [];
        if (isset($this->ci['run_id'])) {
            $headers['X-MailBrew-Run-Id'] = $this->ci['run_id'];
        }
        if (isset($this->ci['commit'])) {
            $headers['X-MailBrew-Commit'] = $this->ci['commit'];
        }
        if (isset($this->ci['branch'])) {
            $headers['X-MailBrew-Branch'] = rawurlencode($this->ci['branch']);
        }
        if ($this->testName !== null) {
            $headers['X-MailBrew-Test'] = rawurlencode($this->testName);
        }

        return $headers;
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

    /**
     * List emails for an address (newest first).
     *
     * Without $limit, every email is returned: the SDK fetches pages of 100 and follows them.
     * With $limit (1–100) and optional $before, only that one page is returned (see listEmailsPage).
     */
    public function listEmails(string $address, ?int $limit = null, ?int $before = null): array
    {
        if ($limit !== null) {
            return $this->listEmailsPage($address, $limit, $before)['emails'];
        }

        $emails = [];
        $cursor = $before;
        while (true) {
            $page = $this->listEmailsPage($address, self::PAGE_SIZE, $cursor);
            array_push($emails, ...$page['emails']);
            if (! $page['hasMore'] || $page['nextBefore'] === null) {
                return $emails;
            }
            $cursor = $page['nextBefore'];
        }
    }

    /**
     * Get one page of emails (newest first).
     *
     * @param  int  $limit  1–100
     * @param  int|null  $before  Only emails older than this email id (pass nextBefore of the previous page)
     * @return array{emails: array, hasMore: bool, nextBefore: ?int}
     */
    public function listEmailsPage(string $address, int $limit = self::PAGE_SIZE, ?int $before = null): array
    {
        $params = ['limit' => $limit];
        if ($before !== null) {
            $params['before'] = $before;
        }
        $res = $this->get("/api/mailbrew/addresses/{$address}/emails?".http_build_query($params));

        return [
            'emails' => $res['data'],
            'hasMore' => (bool) ($res['meta']['has_more'] ?? false),
            'nextBefore' => isset($res['meta']['next_before']) ? (int) $res['meta']['next_before'] : null,
        ];
    }

    /** Delete one email (needs the emails:delete scope). */
    public function deleteEmail(string $address, int $emailId): void
    {
        $this->request('DELETE', "/api/mailbrew/addresses/{$address}/emails/{$emailId}");
    }

    /** Delete every email of an address; the address stays. Returns the count (emails:delete scope). */
    public function deleteAllEmails(string $address): int
    {
        return (int) $this->request('DELETE', "/api/mailbrew/addresses/{$address}/emails")['data']['deleted'];
    }

    /** Get the raw MIME source of an email (message/rfc822). */
    public function getRawEmail(string $address, int $emailId): string
    {
        return $this->requestBody('GET', "/api/mailbrew/addresses/{$address}/emails/{$emailId}/raw", null, 'message/rfc822') ?? '';
    }

    /** Download an attachment's content (binary string). Attachment ids are in $email['attachments']. */
    public function downloadAttachment(string $address, int $emailId, int $attachmentId): string
    {
        return $this->requestBody('GET', "/api/mailbrew/addresses/{$address}/emails/{$emailId}/attachments/{$attachmentId}", null, '*/*') ?? '';
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

    /**
     * Extract link URLs from an email.
     *
     * @param  string|null  $pattern  Regex the URL must match
     * @param  string|null  $text  Only links whose anchor text contains this (case-insensitive)
     * @param  string|null  $kind  verify / password_reset / unsubscribe / other
     * @return list<string>
     */
    public function extractLinks(string $address, int $emailId, ?string $pattern = null, ?string $text = null, ?string $kind = null): array
    {
        return $this->links($address, $emailId, $pattern, $text, $kind)['links'];
    }

    /**
     * Extract links with their anchor text and kind. Same filters as extractLinks.
     *
     * @return list<array{url: string, text: ?string, kind: string}>
     */
    public function extractLinkItems(string $address, int $emailId, ?string $pattern = null, ?string $text = null, ?string $kind = null): array
    {
        return $this->links($address, $emailId, $pattern, $text, $kind)['items'] ?? [];
    }

    private function links(string $address, int $emailId, ?string $pattern, ?string $text, ?string $kind): array
    {
        $params = array_filter(['pattern' => $pattern, 'text' => $text, 'kind' => $kind], fn ($v) => $v !== null && $v !== '');
        $query = $params ? '?'.http_build_query($params) : '';

        return $this->get("/api/mailbrew/addresses/{$address}/emails/{$emailId}/links{$query}")['data'];
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

    /**
     * Create a global webhook.
     *
     * @param  list<string>|null  $include  Extra payload fields: to_addresses, html_body, attachments, otp, links
     * @param  array<string, mixed>|null  $filter  Only send matching emails: address_prefix and/or assertEmail conditions
     */
    public function createWebhook(string $url, ?array $events = null, ?array $include = null, ?array $filter = null, ?bool $redactHistory = null): array
    {
        $body = ['url' => $url];
        if ($events !== null) {
            $body['events'] = $events;
        }
        if ($include !== null) {
            $body['include'] = $include;
        }
        if ($filter !== null) {
            $body['filter'] = $filter;
        }
        if ($redactHistory !== null) {
            $body['redact_history'] = $redactHistory;
        }

        return $this->post('/api/mailbrew/webhooks', $body)['data'];
    }

    /** Update a webhook (url, events, is_active, include, filter; null clears include / filter). */
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
        $body = $this->requestBody($method, $path, $data);

        return $body === null ? [] : (json_decode($body, true) ?? []);
    }

    /** Send (retrying on 429) and return the body as is. null on 204. */
    private function requestBody(string $method, string $path, ?array $data = null, string $accept = 'application/json'): ?string
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->send($method, $path, $data, $accept);
            } catch (MailBrewException $e) {
                if ($e->statusCode !== 429 || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                sleep($e->retryAfter ?? 1);
            }
        }
    }

    private function send(string $method, string $path, ?array $data, string $accept): ?string
    {
        try {
            $options = ['headers' => ['Accept' => $accept] + $this->ciHeaders()];
            if ($data !== null && $method !== 'GET') {
                $options['json'] = $data;
            }

            $response = $this->http->request($method, $path, $options);

            if ($response->getStatusCode() === 204) {
                return null;
            }

            return $response->getBody()->getContents();
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
