<?php

declare(strict_types=1);

namespace MailBrew;

class MailBrewException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
        /** 429 で再試行を使い切ったとき、待つべき秒数 */
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
