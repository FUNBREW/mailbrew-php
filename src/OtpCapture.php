<?php

declare(strict_types=1);

namespace BrewMail;

class OtpCapture
{
    public function __construct(
        private BrewMail $client,
        public readonly string $address,
        private int $timeout,
        private ?string $pattern,
    ) {}

    /**
     * Wait for an email and extract OTP code.
     *
     * @return array{code: string, email: array}
     *
     * @throws BrewMailException
     */
    public function waitAndExtract(): array
    {
        $email = $this->client->waitForEmail($this->address, $this->timeout);
        $code = $this->client->extractOtp($this->address, $email['id'], $this->pattern);

        return ['code' => $code, 'email' => $email];
    }
}
