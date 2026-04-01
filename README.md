# brewmail/sdk

Official PHP SDK for the [BrewMail](https://brewmail.dev) API — programmable disposable email for E2E testing.

## Install

```bash
composer require brewmail/sdk
```

## Quick Start

```php
use BrewMail\BrewMail;

$client = new BrewMail('mb_your_api_key');

// Create a temporary email address
$addr = $client->createAddress(ttl: 3600);
echo $addr['address']; // "ax7k2m@acme.brewmail.dev"

// Wait for an email and extract OTP
$email = $client->waitForEmail($addr['address']);
$code = $client->extractOtp($addr['address'], $email['id']);
echo $code; // "847291"
```

## E2E Test Example (Pest / PHPUnit)

```php
use BrewMail\BrewMail;

test('signup with email verification', function () {
    $mail = new BrewMail(env('BREWMAIL_API_KEY'));
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

## API

### `new BrewMail(apiKey, baseUrl)`

| Parameter | Type | Default |
|-----------|------|---------|
| `$apiKey` | `string` | *(required)* |
| `$baseUrl` | `string` | `https://brewmail.dev` |

### Methods

| Method | Description |
|--------|-------------|
| `createAddress(ttl?, webhookUrl?, localPart?)` | Create a temporary email address |
| `listAddresses()` | List all addresses |
| `getAddress(address)` | Get address details |
| `deleteAddress(address)` | Delete an address |
| `listEmails(address)` | List emails for an address |
| `getEmail(address, emailId)` | Get email details |
| `extractOtp(address, emailId, pattern?)` | Extract OTP code from email |
| `forwardEmail(address, emailId, to)` | Forward email |
| `waitForEmails(address, timeout?, count?, since?)` | Wait for email(s) via server-side long-polling |
| `waitForEmail(address, timeout?, since?)` | Wait for a single email (throws on timeout) |
| `listWebhooks()` | List global webhooks |
| `createWebhook(url, events?)` | Create a webhook |
| `updateWebhook(id, data)` | Update a webhook |
| `deleteWebhook(id)` | Delete a webhook |
| `captureOtp(ttl?, timeout?, pattern?)` | Create address + prepare OTP capture |
