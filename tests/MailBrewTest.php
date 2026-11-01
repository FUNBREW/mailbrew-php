<?php

declare(strict_types=1);

namespace MailBrew\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use MailBrew\MailBrew;
use MailBrew\MailBrewException;
use MailBrew\OtpCapture;
use PHPUnit\Framework\TestCase;

class MailBrewTest extends TestCase
{
    public function test_constructor_sets_api_key_and_base_url(): void
    {
        $client = new MailBrew('mb_test_key', 'http://localhost:8080');

        $this->assertSame('mb_test_key', $client->getApiKey());
        $this->assertSame('http://localhost:8080', $client->getBaseUrl());
    }

    public function test_constructor_strips_trailing_slash(): void
    {
        $client = new MailBrew('key', 'http://example.com/');

        $this->assertSame('http://example.com', $client->getBaseUrl());
    }

    public function test_constructor_uses_default_base_url(): void
    {
        $client = new MailBrew('key');

        $this->assertSame('https://mailbrew.dev', $client->getBaseUrl());
    }

    public function test_exception_has_status_code(): void
    {
        $err = new MailBrewException('test error', 404);

        $this->assertSame('test error', $err->getMessage());
        $this->assertSame(404, $err->statusCode);
        $this->assertSame(404, $err->getCode());
        $this->assertInstanceOf(\RuntimeException::class, $err);
    }

    public function test_exception_default_status_code(): void
    {
        $err = new MailBrewException('timeout');

        $this->assertSame(0, $err->statusCode);
    }

    public function test_otp_capture_stores_address(): void
    {
        $client = new MailBrew('key');
        $capture = new OtpCapture($client, 'test@example.com', 30, '\d{6}');

        $this->assertSame('test@example.com', $capture->address);
    }

    private function clientWith(MockHandler $mock, int $maxRetries = 2): MailBrew
    {
        return new MailBrew('key', 'http://api.test', maxRetries: $maxRetries, handler: HandlerStack::create($mock));
    }

    private function rateLimited(string $retryAfter = '0'): Response
    {
        return new Response(429, ['Retry-After' => $retryAfter], json_encode(['message' => 'Rate limit exceeded.']));
    }

    // 429 のとき Retry-After だけ待って再試行する（Node / Python SDK と同じ）
    public function test_retries_on_429_and_returns_the_success(): void
    {
        $mock = new MockHandler([
            $this->rateLimited(),
            new Response(201, [], json_encode(['data' => ['address' => 'abc@x.mailbrew.dev']])),
        ]);

        $addr = $this->clientWith($mock)->createAddress();

        $this->assertSame('abc@x.mailbrew.dev', $addr['address']);
        $this->assertSame(0, $mock->count());
    }

    public function test_gives_up_after_max_retries_with_retry_after(): void
    {
        $mock = new MockHandler([$this->rateLimited(), $this->rateLimited(), $this->rateLimited(), $this->rateLimited()]);

        try {
            $this->clientWith($mock, maxRetries: 2)->createAddress();
            $this->fail('MailBrewException was not thrown');
        } catch (MailBrewException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertSame(0, $e->retryAfter);
        }
        $this->assertSame(1, $mock->count()); // 最初の 1 回 + 再試行 2 回で 3 つ使う
    }

    public function test_does_not_retry_other_errors(): void
    {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['message' => 'Address not found.'])),
            new Response(201, [], json_encode(['data' => ['address' => 'never']])),
        ]);

        try {
            $this->clientWith($mock)->createAddress();
            $this->fail('MailBrewException was not thrown');
        } catch (MailBrewException $e) {
            $this->assertSame(404, $e->statusCode);
        }
        $this->assertSame(1, $mock->count());
    }
}
