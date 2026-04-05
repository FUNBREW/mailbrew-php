<?php

/**
 * BrewMail PHP SDK — Integration test against a real Laravel server.
 *
 * Usage:
 *   cd sdks/php
 *   BREWMAIL_API_KEY=mb_sdk_integration_test_key_12345 \
 *   BREWMAIL_BASE_URL=http://127.0.0.1:8199 \
 *   php examples/integration-test.php
 */

require_once __DIR__.'/../vendor/autoload.php';

use BrewMail\BrewMail;
use BrewMail\BrewMailException;
use BrewMail\OtpCapture;

$apiKey = getenv('BREWMAIL_API_KEY') ?: 'mb_sdk_integration_test_key_12345';
$baseUrl = getenv('BREWMAIL_BASE_URL') ?: 'http://127.0.0.1:8199';

$client = new BrewMail($apiKey, $baseUrl);

$passed = 0;
$failed = 0;

function check(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) {
        echo "  ✓ {$label}\n";
        $passed++;
    } else {
        echo "  ✗ {$label}\n";
        $failed++;
    }
}

echo "\nBrewMail PHP SDK Integration Test\n";
echo "Base URL: {$baseUrl}\n\n";

// --- 1. Create Address ---
echo "1. createAddress()\n";
$addr = $client->createAddress(ttl: 600);
check(is_string($addr['address']), "address is string: {$addr['address']}");
check(str_contains($addr['address'], '.mailbrew.dev'), 'address contains domain');
check(isset($addr['expires_at']), 'expires_at is present');
check(is_int($addr['id']), "id is int: {$addr['id']}");

// --- 2. List Addresses ---
echo "\n2. listAddresses()\n";
$addresses = $client->listAddresses();
check(is_array($addresses), 'returns an array');
check(count($addresses) >= 1, 'has at least 1 address (got '.count($addresses).')');
$found = false;
foreach ($addresses as $a) {
    if ($a['address'] === $addr['address']) {
        $found = true;
    }
}
check($found, 'includes the created address');

// --- 3. Get Address ---
echo "\n3. getAddress()\n";
$detail = $client->getAddress($addr['address']);
check($detail['id'] === $addr['id'], 'id matches');
check($detail['address'] === $addr['address'], 'address matches');

// --- 4. List Emails (empty) ---
echo "\n4. listEmails() — empty\n";
$emails = $client->listEmails($addr['address']);
check(is_array($emails), 'returns an array');
check(count($emails) === 0, 'no emails yet');

// --- 5. waitForEmails (timeout, no emails) ---
echo "\n5. waitForEmails() — timeout with no emails\n";
$start = microtime(true);
$waited = $client->waitForEmails($addr['address'], timeout: 2);
$elapsed = microtime(true) - $start;
check(is_array($waited), 'returns an array');
check(count($waited) === 0, 'empty (timed out)');
check($elapsed >= 1.5, sprintf('waited ~2s (%.1fs)', $elapsed));

// --- 6. waitForEmail (timeout → throws) ---
echo "\n6. waitForEmail() — timeout throws BrewMailException\n";
try {
    $client->waitForEmail($addr['address'], timeout: 2);
    check(false, 'should have thrown');
} catch (BrewMailException $e) {
    check(true, 'throws BrewMailException');
    check(str_contains($e->getMessage(), 'Timeout'), "message: {$e->getMessage()}");
}

// --- 7. Create address with custom local_part ---
echo "\n7. createAddress() with custom local_part\n";
$customLocal = 'php-sdk-test-'.time();
$customAddr = $client->createAddress(ttl: 300, localPart: $customLocal);
check(str_starts_with($customAddr['address'], "{$customLocal}@"), "starts with custom part: {$customAddr['address']}");

// --- 8. Webhooks CRUD ---
echo "\n8. Webhook CRUD\n";
$wh = $client->createWebhook('https://example.com/php-hook', events: ['email.received']);
check(is_int($wh['id']), "webhook id: {$wh['id']}");
check($wh['url'] === 'https://example.com/php-hook', 'url matches');

$webhooks = $client->listWebhooks();
$whFound = false;
foreach ($webhooks as $w) {
    if ($w['id'] === $wh['id']) {
        $whFound = true;
    }
}
check($whFound, 'listed webhook');

$client->deleteWebhook($wh['id']);
sleep(1);
$afterDelete = $client->listWebhooks();
$whStillExists = false;
foreach ($afterDelete as $w) {
    if ($w['id'] === $wh['id']) {
        $whStillExists = true;
    }
}
check(! $whStillExists, 'webhook deleted');

// --- 9. Delete Address ---
echo "\n9. deleteAddress()\n";
sleep(1);
$client->deleteAddress($addr['address']);
try {
    $client->getAddress($addr['address']);
    check(false, 'should have thrown 404');
} catch (BrewMailException $e) {
    check(true, 'throws on deleted address');
    check($e->statusCode === 404, "status 404 (got {$e->statusCode})");
}

// --- 10. Error handling — invalid API key ---
echo "\n10. Error handling — invalid API key\n";
sleep(1);
$badClient = new BrewMail('mb_invalid_key', $baseUrl);
try {
    $badClient->listAddresses();
    check(false, 'should have thrown');
} catch (BrewMailException $e) {
    check(true, 'throws BrewMailException');
    check($e->statusCode === 401, "status 401 (got {$e->statusCode})");
}

// --- 11. captureOtp() — creates address and returns OtpCapture ---
echo "\n11. captureOtp() — creates address and returns OtpCapture\n";
$capture = $client->captureOtp(ttl: 120);
check($capture instanceof OtpCapture, 'returns OtpCapture instance');
check(is_string($capture->address), "has address: {$capture->address}");
check(str_contains($capture->address, '.mailbrew.dev'), 'address contains domain');

// Cleanup
$client->deleteAddress($customAddr['address']);
$client->deleteAddress($capture->address);

// --- Summary ---
echo "\n".str_repeat('─', 50)."\n";
echo "Results: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
