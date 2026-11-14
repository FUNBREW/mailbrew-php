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

/** 一覧のページング・メールの削除・生データ・添付・リンクの種別・Webhook の include / filter（2026-11-14）。 */
class EmailApiTest extends TestCase
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

    private static function json(array $body, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function request(int $i): RequestInterface
    {
        return $this->history[$i]['request'];
    }

    private function query(int $i): array
    {
        parse_str($this->request($i)->getUri()->getQuery(), $q);

        return $q;
    }

    private function body(int $i): array
    {
        return json_decode((string) $this->request($i)->getBody(), true);
    }

    public function test_list_emails_follows_pages_of_100(): void
    {
        $emails = $this->client([
            self::json(['data' => [['id' => 3], ['id' => 2]], 'meta' => ['has_more' => true, 'next_before' => 2]]),
            self::json(['data' => [['id' => 1]], 'meta' => ['has_more' => false, 'next_before' => null]]),
        ])->listEmails('a@x');

        $this->assertSame([3, 2, 1], array_column($emails, 'id'));
        $this->assertCount(2, $this->history);
        $this->assertSame(['limit' => '100'], $this->query(0));
        $this->assertSame(['limit' => '100', 'before' => '2'], $this->query(1));
    }

    public function test_list_emails_with_limit_makes_one_request(): void
    {
        $emails = $this->client([
            self::json(['data' => [['id' => 9]], 'meta' => ['has_more' => true, 'next_before' => 9]]),
        ])->listEmails('a@x', limit: 1, before: 10);

        $this->assertSame([['id' => 9]], $emails);
        $this->assertCount(1, $this->history);
        $this->assertSame(['limit' => '1', 'before' => '10'], $this->query(0));
    }

    public function test_list_emails_page_returns_meta(): void
    {
        $page = $this->client([
            self::json(['data' => [['id' => 5]], 'meta' => ['has_more' => true, 'next_before' => 5]]),
        ])->listEmailsPage('a@x', 1);

        $this->assertSame(['emails' => [['id' => 5]], 'hasMore' => true, 'nextBefore' => 5], $page);
    }

    public function test_delete_email(): void
    {
        $this->client([new Response(204)])->deleteEmail('a@x', 7);

        $this->assertSame('DELETE', $this->request(0)->getMethod());
        $this->assertSame('/api/mailbrew/addresses/a@x/emails/7', $this->request(0)->getUri()->getPath());
    }

    public function test_delete_all_emails_returns_count(): void
    {
        $deleted = $this->client([self::json(['data' => ['deleted' => 4]])])->deleteAllEmails('a@x');

        $this->assertSame(4, $deleted);
        $this->assertSame('DELETE', $this->request(0)->getMethod());
        $this->assertSame('/api/mailbrew/addresses/a@x/emails', $this->request(0)->getUri()->getPath());
    }

    public function test_get_raw_email_returns_mime_text(): void
    {
        $raw = "From: a@b\r\nSubject: Hi\r\n\r\nbody";
        $result = $this->client([new Response(200, ['Content-Type' => 'message/rfc822'], $raw)])->getRawEmail('a@x', 7);

        $this->assertSame($raw, $result);
        $this->assertStringEndsWith('/emails/7/raw', $this->request(0)->getUri()->getPath());
        $this->assertSame('message/rfc822', $this->request(0)->getHeaderLine('Accept'));
    }

    public function test_download_attachment_returns_binary_string(): void
    {
        $bytes = "%PDF\x00\xff";
        $result = $this->client([new Response(200, ['Content-Type' => 'application/pdf'], $bytes)])->downloadAttachment('a@x', 7, 3);

        $this->assertSame($bytes, $result);
        $this->assertSame('/api/mailbrew/addresses/a@x/emails/7/attachments/3', $this->request(0)->getUri()->getPath());
    }

    public function test_raw_error_raises_with_api_message(): void
    {
        try {
            $this->client([self::json(['message' => 'Not found'], 404)])->getRawEmail('a@x', 7);
            $this->fail('MailBrewException was not thrown');
        } catch (MailBrewException $e) {
            $this->assertSame(404, $e->statusCode);
            $this->assertSame('Not found', $e->getMessage());
        }
    }

    public function test_links_send_pattern_text_and_kind(): void
    {
        $data = ['data' => [
            'links' => ['https://x/verify'],
            'items' => [['url' => 'https://x/verify', 'text' => 'Verify', 'kind' => 'verify']],
        ]];
        $client = $this->client([self::json($data), self::json($data)]);

        $links = $client->extractLinks('a@x', 7, pattern: 'x', text: 'verify', kind: 'verify');
        $items = $client->extractLinkItems('a@x', 7, kind: 'verify');

        $this->assertSame(['https://x/verify'], $links);
        $this->assertSame([['url' => 'https://x/verify', 'text' => 'Verify', 'kind' => 'verify']], $items);
        $this->assertSame(['pattern' => 'x', 'text' => 'verify', 'kind' => 'verify'], $this->query(0));
        $this->assertSame(['kind' => 'verify'], $this->query(1));
    }

    public function test_webhook_include_and_filter_pass_through(): void
    {
        $filter = ['address_prefix' => 'signup-', 'subject_contains' => 'Verify'];
        $client = $this->client([self::json(['data' => ['id' => 1]]), self::json(['data' => ['id' => 1]])]);

        $client->createWebhook('https://hook', ['email.received'], include: ['otp', 'links'], filter: $filter);
        $client->updateWebhook(1, ['include' => null, 'filter' => null]);

        $this->assertSame(['url' => 'https://hook', 'events' => ['email.received'], 'include' => ['otp', 'links'], 'filter' => $filter], $this->body(0));
        $this->assertSame('PUT', $this->request(1)->getMethod());
        $this->assertSame(['include' => null, 'filter' => null], $this->body(1));
    }
}
