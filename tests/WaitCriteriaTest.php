<?php

declare(strict_types=1);

namespace MailBrew\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MailBrew\MailBrew;
use MailBrew\MailBrewException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** wait の条件と extract（2026-11-14）。captureOtp は wait 1 回で OTP まで取る。 */
class WaitCriteriaTest extends TestCase
{
    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    private function client(array $responses): MailBrew
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new MailBrew('key', 'http://api.test', handler: $stack);
    }

    private static function ok(mixed $data): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $data]));
    }

    private function query(int $i): array
    {
        parse_str($this->history[$i]['request']->getUri()->getQuery(), $q);

        return $q;
    }

    public function test_wait_sends_where_and_extract_as_query(): void
    {
        $this->client([self::ok([])])->waitForEmails('a@x', where: [
            'subject_contains' => '確認',
            'has_attachment' => false,
            'header' => ['name' => 'X-Kind', 'value' => 'invoice'],
        ], extract: ['otp', 'links']);

        $q = $this->query(0);
        $this->assertSame('確認', $q['subject_contains']);
        $this->assertSame('false', $q['has_attachment']);
        $this->assertSame(['name' => 'X-Kind', 'value' => 'invoice'], $q['header']);
        $this->assertSame('otp,links', $q['extract']);
    }

    public function test_capture_otp_uses_one_wait(): void
    {
        $client = $this->client([
            self::ok(['address' => 'a@x']),
            self::ok([['id' => 7, 'extracted' => ['otp' => ['code' => '482914', 'candidates' => []]]]]),
        ]);

        $result = $client->captureOtp(where: ['subject_contains' => '確認'])->waitAndExtract();

        $this->assertSame('482914', $result['code']);
        $this->assertCount(2, $this->history);
        $this->assertStringEndsWith('/emails/wait', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame('otp', $this->query(1)['extract']);
        $this->assertSame('確認', $this->query(1)['subject_contains']);
    }

    public function test_capture_otp_throws_404_when_no_code(): void
    {
        $client = $this->client([self::ok(['address' => 'a@x']), self::ok([['id' => 7, 'extracted' => ['otp' => null]]])]);

        $this->expectException(MailBrewException::class);
        $this->expectExceptionCode(404);
        $client->captureOtp()->waitAndExtract();
    }

    public function test_capture_otp_with_pattern_keeps_otp_endpoint(): void
    {
        $client = $this->client([self::ok(['address' => 'a@x']), self::ok([['id' => 7]]), self::ok(['code' => 'abc-123'])]);

        $this->assertSame('abc-123', $client->captureOtp(pattern: '[a-z]{3}-\d{3}')->waitAndExtract()['code']);
        $this->assertStringEndsWith('/otp', $this->history[2]['request']->getUri()->getPath());
    }
}
