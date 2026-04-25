<?php

declare(strict_types=1);

namespace MailBrew\Tests;

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
}
