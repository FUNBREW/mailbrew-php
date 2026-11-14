<?php

declare(strict_types=1);

namespace MailBrew\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use MailBrew\MailBrew;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/** CI の実行ごとのテスト結果（計画 10）: CI の環境変数から実行 ID・コミット・ブランチを拾い、テスト名と一緒にヘッダーで送る。 */
class CiRunTest extends TestCase
{
    private const VARS = [
        'MAILBREW_RUN_ID', 'MAILBREW_COMMIT', 'MAILBREW_BRANCH',
        'GITHUB_RUN_ID', 'GITHUB_RUN_ATTEMPT', 'GITHUB_SHA', 'GITHUB_HEAD_REF', 'GITHUB_REF_NAME',
        'CI_PIPELINE_ID', 'CI_COMMIT_SHA', 'CI_COMMIT_REF_NAME',
        'CIRCLE_WORKFLOW_ID', 'CIRCLE_SHA1', 'CIRCLE_BRANCH',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    /** @var array<int, array{request: RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        foreach (self::VARS as $name) {
            $this->saved[$name] = getenv($name);
        }
        $this->setEnv([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }

    private function setEnv(array $values): void
    {
        foreach (self::VARS as $name) {
            putenv($name);
        }
        foreach ($values as $name => $value) {
            putenv("{$name}={$value}");
        }
    }

    private function client(array $options = []): MailBrew
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler(array_map(fn () => new Response(200, [], '{"data":[]}'), range(1, 3))));
        $stack->push(Middleware::history($this->history));

        return new MailBrew('mb_key', 'http://api.test', ...[...$options, 'handler' => $stack]);
    }

    private function sent(MailBrew $client): RequestInterface
    {
        $client->listAddresses();

        return $this->history[array_key_last($this->history)]['request'];
    }

    public function test_github_actions_を拾う(): void
    {
        $this->assertSame(
            ['run_id' => '123-2', 'commit' => 'abc1234def', 'branch' => 'feat/x'],
            MailBrew::detectCiRun([
                'GITHUB_RUN_ID' => '123', 'GITHUB_RUN_ATTEMPT' => '2', 'GITHUB_SHA' => 'abc1234def',
                'GITHUB_HEAD_REF' => 'feat/x', 'GITHUB_REF_NAME' => '42/merge',
            ]),
        );
    }

    public function test_github_actions_の_run_attempt_が無ければ_1_head_ref_が空なら_ref_name(): void
    {
        $this->assertSame(
            ['run_id' => '9-1', 'commit' => 'abcdef1', 'branch' => 'main'],
            MailBrew::detectCiRun(['GITHUB_RUN_ID' => '9', 'GITHUB_SHA' => 'abcdef1', 'GITHUB_HEAD_REF' => '', 'GITHUB_REF_NAME' => 'main']),
        );
    }

    public function test_gitlab_ci_と_circleci_を拾う(): void
    {
        $this->assertSame(
            ['run_id' => '77', 'commit' => 'deadbeef', 'branch' => 'dev'],
            MailBrew::detectCiRun(['CI_PIPELINE_ID' => '77', 'CI_COMMIT_SHA' => 'deadbeef', 'CI_COMMIT_REF_NAME' => 'dev']),
        );
        $this->assertSame(
            ['run_id' => 'wf-1', 'commit' => 'cafebabe', 'branch' => 'main'],
            MailBrew::detectCiRun(['CIRCLE_WORKFLOW_ID' => 'wf-1', 'CIRCLE_SHA1' => 'cafebabe', 'CIRCLE_BRANCH' => 'main']),
        );
    }

    public function test_mailbrew_の変数が優先される(): void
    {
        $this->assertSame(
            ['run_id' => 'nightly-5', 'commit' => 'aaaaaaa', 'branch' => 'release'],
            MailBrew::detectCiRun([
                'GITHUB_RUN_ID' => '1', 'GITHUB_SHA' => 'aaaaaaa', 'GITHUB_REF_NAME' => 'main',
                'MAILBREW_RUN_ID' => 'nightly-5', 'MAILBREW_BRANCH' => 'release',
            ]),
        );
    }

    public function test_ci_の外では何も拾わない(): void
    {
        $this->assertSame([], MailBrew::detectCiRun([]));
    }

    public function test_作ったときに環境から拾いヘッダーで送る_ブランチは_url_エンコード(): void
    {
        $this->setEnv(['GITHUB_RUN_ID' => '123', 'GITHUB_SHA' => 'abc1234', 'GITHUB_REF_NAME' => '機能/登録']);
        $request = $this->sent($this->client());

        $this->assertSame('123-1', $request->getHeaderLine('X-MailBrew-Run-Id'));
        $this->assertSame('abc1234', $request->getHeaderLine('X-MailBrew-Commit'));
        $this->assertSame(rawurlencode('機能/登録'), $request->getHeaderLine('X-MailBrew-Branch'));
        $this->assertFalse($request->hasHeader('X-MailBrew-Test'));
        $this->assertSame('mb_key', $request->getHeaderLine('X-API-Key'));
    }

    public function test_ci_の外ではヘッダーを付けない(): void
    {
        $request = $this->sent($this->client());

        foreach (['X-MailBrew-Run-Id', 'X-MailBrew-Commit', 'X-MailBrew-Branch', 'X-MailBrew-Test'] as $name) {
            $this->assertFalse($request->hasHeader($name), $name);
        }
    }

    public function test_ci_false_なら何も送らない(): void
    {
        $this->setEnv(['GITHUB_RUN_ID' => '123', 'GITHUB_SHA' => 'abc1234', 'GITHUB_REF_NAME' => 'main']);
        $request = $this->sent($this->client(['ci' => false]));

        $this->assertFalse($request->hasHeader('X-MailBrew-Run-Id'));
        $this->assertFalse($request->hasHeader('X-MailBrew-Commit'));
        $this->assertFalse($request->hasHeader('X-MailBrew-Branch'));
    }

    public function test_ci_に配列を渡すと環境より渡した値を送る(): void
    {
        $this->setEnv(['GITHUB_RUN_ID' => '123', 'GITHUB_SHA' => 'abc1234']);
        $request = $this->sent($this->client(['ci' => ['run_id' => 'local-1', 'branch' => 'my branch', 'commit' => null]]));

        $this->assertSame('local-1', $request->getHeaderLine('X-MailBrew-Run-Id'));
        $this->assertFalse($request->hasHeader('X-MailBrew-Commit'));
        $this->assertSame('my%20branch', $request->getHeaderLine('X-MailBrew-Branch'));
    }

    public function test_with_test_はテスト名を送るコピーを返し元は変えない(): void
    {
        $this->setEnv(['GITHUB_RUN_ID' => '5', 'GITHUB_SHA' => 'abc1234', 'GITHUB_REF_NAME' => 'main']);
        $client = $this->client();
        $named = $client->withTest('会員登録 › 確認メールが届く');

        $this->assertNotSame($client, $named);
        $request = $this->sent($named);
        $this->assertSame(rawurlencode('会員登録 › 確認メールが届く'), $request->getHeaderLine('X-MailBrew-Test'));
        $this->assertSame('会員登録 › 確認メールが届く', rawurldecode($request->getHeaderLine('X-MailBrew-Test')));
        $this->assertSame('5-1', $request->getHeaderLine('X-MailBrew-Run-Id'));

        $this->assertNull($client->getTestName());
        $this->assertFalse($this->sent($client)->hasHeader('X-MailBrew-Test'));
    }

    public function test_test_name_を作るときに渡せる(): void
    {
        $request = $this->sent($this->client(['testName' => 'login works']));

        $this->assertSame('login%20works', $request->getHeaderLine('X-MailBrew-Test'));
    }
}
