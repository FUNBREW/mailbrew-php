<?php

declare(strict_types=1);

namespace MailBrew;

class OtpCapture
{
    public function __construct(
        private MailBrew $client,
        public readonly string $address,
        private int $timeout,
        private ?string $pattern,
        private array $where = [],
    ) {}

    /**
     * Wait for an email and extract OTP code.
     *
     * @return array{code: string, email: array}
     *
     * @throws MailBrewException
     */
    public function waitAndExtract(): array
    {
        if ($this->pattern !== null) {
            $email = $this->client->waitForEmail($this->address, $this->timeout, where: $this->where);
            $code = $this->client->extractOtp($this->address, $email['id'], $this->pattern);

            return ['code' => $code, 'email' => $email];
        }

        // パターンを渡さないときは wait 1 回で code まで取る（以前は wait と otp の 2 回）
        $email = $this->client->waitForEmail($this->address, $this->timeout, where: $this->where, extract: 'otp');
        $code = $email['extracted']['otp']['code'] ?? null;
        if ($code === null) {
            throw new MailBrewException('No OTP code found in the email', 404);
        }

        return ['code' => $code, 'email' => $email];
    }
}
