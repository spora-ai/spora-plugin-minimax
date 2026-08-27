<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

/**
 * Pre-shaped log entry consumed by {@see MiniMaxLogWriter::record()}.
 *
 * Bundling the eight fields a tool call needs to persist into a DTO keeps the writer's
 * signature at one parameter (SonarQube S107 — "too many parameters") and gives the
 * caller a single named object to fill in.
 */
final readonly class MiniMaxLogContext
{
    /**
     * @param array<string, mixed> $request       Tool-call argument payload (post-redaction).
     * @param array<string, mixed> $response      Decoded API response (post-redaction), or empty on error.
     * @param ?int                 $runnerUserId  Who triggered the call — the audit log records the
     *                                            runner, not the owner (whose settings paid for the call).
     */
    public function __construct(
        public string   $provider,
        public string   $qualifiedToolName,
        public array    $request,
        public array    $response,
        public bool     $success,
        public ?string  $error = null,
        public ?int     $runnerUserId = null,
        public ?int     $agentId = null,
    ) {}
}
