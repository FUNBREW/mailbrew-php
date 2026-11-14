# mailbrew/sdk

Official PHP SDK for the [MailBrew](https://mailbrew.dev) API — programmable disposable email for E2E testing.

## Install

```bash
composer require mailbrew/sdk
```

> **Note:** This package is not yet published to Packagist, so it cannot be installed from there yet. Until it is published, call the [REST API](https://mailbrew.dev/en/docs) directly: create an address (`POST /api/mailbrew/addresses`), wait for the email (`GET /api/mailbrew/addresses/{address}/emails/wait`) and read the code (`GET /api/mailbrew/addresses/{address}/emails/{id}/otp`) with `Http::` or `curl` and your API key in the `X-API-Key` header.

## Quick Start

```php
use MailBrew\MailBrew;

$client = new MailBrew('mb_your_api_key');

// Create a temporary email address
$addr = $client->createAddress(ttl: 3600);
echo $addr['address']; // "ax7k2m@acme.mailbrew.dev"

// Wait for an email and extract OTP
$email = $client->waitForEmail($addr['address']);
$code = $client->extractOtp($addr['address'], $email['id']);
echo $code; // "847291"
```

## E2E Test Example (Pest / PHPUnit)

```php
use MailBrew\MailBrew;

test('signup with email verification', function () {
    $mail = new MailBrew(env('MAILBREW_API_KEY'));
    $capture = $mail->captureOtp(ttl: 120);

    // 1. Sign up with the disposable address
    $this->post('/signup', [
        'email' => $capture->address,
        'password' => 'test1234',
    ])->assertRedirect('/verify');

    // 2. Wait for verification email and extract OTP
    $result = $capture->waitAndExtract();

    // 3. Submit OTP
    $this->post('/verify', ['code' => $result['code']])
        ->assertRedirect('/dashboard');
});
```

## CI run results

Every request carries the CI run it came from, so the **Test Results** screen in the dashboard groups assertion results by run and flags **flaky** tests (the same commit both passed and failed).

| Header | Value |
|--------|-------|
| `X-MailBrew-Run-Id` | The CI run (`<GITHUB_RUN_ID>-<GITHUB_RUN_ATTEMPT>` on GitHub Actions) |
| `X-MailBrew-Commit` | Commit SHA |
| `X-MailBrew-Branch` | Branch name (URL-encoded) |
| `X-MailBrew-Test` | Test name (URL-encoded) |

A header is sent only when its value is known. The run is read from the environment when the client is created:

| | Run | Commit | Branch |
|---|---|---|---|
| Override (wins) | `MAILBREW_RUN_ID` | `MAILBREW_COMMIT` | `MAILBREW_BRANCH` |
| GitHub Actions | `GITHUB_RUN_ID`-`GITHUB_RUN_ATTEMPT` | `GITHUB_SHA` | `GITHUB_HEAD_REF`, else `GITHUB_REF_NAME` |
| GitLab CI | `CI_PIPELINE_ID` | `CI_COMMIT_SHA` | `CI_COMMIT_REF_NAME` |
| CircleCI | `CIRCLE_WORKFLOW_ID` | `CIRCLE_SHA1` | `CIRCLE_BRANCH` |

```php
// Attach the test's name (an immutable copy; the original is unchanged)
$mb = (new MailBrew(getenv('MAILBREW_API_KEY')))->withTest('Signup › sends a verification email');

// Set the run yourself, or turn it off
new MailBrew($key, ci: ['run_id' => 'nightly-42', 'commit' => 'abc1234', 'branch' => 'main']);
new MailBrew($key, ci: false); // sends no run headers
```

In PHPUnit / Pest, `$client->withTest($this->name())` names each test.

## API

### `new MailBrew(apiKey, baseUrl)`

| Parameter | Type | Default |
|-----------|------|---------|
| `$apiKey` | `string` | *(required)* |
| `$baseUrl` | `string` | `https://mailbrew.dev` |
| `$maxRetries` | `int` | `2` |
| `ci:` | `array\|false\|null` | `null` = detected from the environment (see [CI run results](#ci-run-results)); `false` sends nothing |
| `testName:` | `?string` | sent as `X-MailBrew-Test` (see `withTest($name)`) |

### Methods

| Method | Description |
|--------|-------------|
| `createAddress(ttl?, webhookUrl?, localPart?)` | Create a temporary email address |
| `listAddresses()` | List all addresses |
| `getAddress(address)` | Get address details |
| `deleteAddress(address)` | Delete an address |
| `listEmails(address, limit?, before?)` | List emails (newest first). Without `limit`, every email (fetched 100 per request); with `limit` / `before`, just that page |
| `listEmailsPage(address, limit?, before?)` | One page: `['emails' => ..., 'hasMore' => bool, 'nextBefore' => ?int]` |
| `getEmail(address, emailId)` | Get email details (`attachments: [{id, filename, content_type, size}]`) |
| `deleteEmail(address, emailId)` | Delete one email (`emails:delete` scope) |
| `deleteAllEmails(address)` | Delete every email of the address; returns the count (`emails:delete` scope) |
| `getRawEmail(address, emailId)` | Raw MIME source as a string |
| `downloadAttachment(address, emailId, attachmentId)` | Attachment content (binary string) |
| `extractOtp(address, emailId, pattern?)` | Extract OTP code from email |
| `extractLinks(address, emailId, pattern?, text?, kind?)` | Extract link URLs. `text`: anchor text contains; `kind`: `verify` / `password_reset` / `unsubscribe` / `other` |
| `extractLinkItems(address, emailId, pattern?, text?, kind?)` | Same filters; returns `[['url' => ..., 'text' => ..., 'kind' => ...]]` |
| `assertEmail(address, emailId, assertions)` | Assert email properties (subject, from, to, body) |
| `forwardEmail(address, emailId, to)` | Forward email |
| `waitForEmails(address, timeout?, count?, since?)` | Wait for email(s) via server-side long-polling |
| `waitForEmail(address, timeout?, since?)` | Wait for a single email (throws on timeout) |
| `listWebhooks()` | List global webhooks |
| `createWebhook(url, events?, include?, filter?)` | Create a webhook. `include`: extra payload fields (`to_addresses`, `html_body`, `attachments`, `otp`, `links`); `filter`: `address_prefix` and/or the conditions below |
| `updateWebhook(id, data)` | Update a webhook (`url`, `events`, `is_active`, `include`, `filter`) |
| `deleteWebhook(id)` | Delete a webhook |
| `captureOtp(ttl?, timeout?, pattern?)` | Create address + prepare OTP capture |

### Conditions

`assertEmail`, `expectSequence` steps and the `where` argument of `waitForEmail(s)` / `captureOtp` take the same conditions
(`where` cannot use `to`): `subject`, `subject_contains`, `subject_matches`, `from`, `to`, `body_contains`, `body_not_contains`,
`body_matches`, `html_contains`, `header` (`['name' => ..., 'value' => ...]`), `has_attachment`, `attachment_filename`.

```php
$email = $client->waitForEmail($address, where: ['subject_contains' => 'Verify'], extract: 'otp');
$code = $email['extracted']['otp']['code'];

$resetUrl = $client->extractLinks($address, $email['id'], kind: 'password_reset')[0];
$raw = $client->getRawEmail($address, $email['id']);
$client->deleteAllEmails($address); // empty a permanent mailbox between tests
```

