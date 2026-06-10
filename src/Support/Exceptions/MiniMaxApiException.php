<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support\Exceptions;

use RuntimeException;

/**
 * Thrown by MiniMaxHttpClient for both transport-level errors (HTTP >= 400) and
 * business-level errors (non-zero base_resp.status_code). The status code is
 * preserved so callers can distinguish rate-limit (1002), auth (1004), balance
 * (1008), content moderation (1026), bad params (2013), and bad API key (2049)
 * when surfacing error messages to the LLM.
 */
final class MiniMaxApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $baseResp The decoded `base_resp` envelope from MiniMax, or empty if the error was transport-level.
     */
    public function __construct(
        string $message,
        public readonly int $statusCode,
        public readonly array $baseResp = [],
    ) {
        parent::__construct($message);
    }
}
