<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

/**
 * Per-call context handed to a tool's work callable by {@see MiniMaxToolSupport::run()}.
 *
 * Bundles the resolved settings, an already-built {@see MiniMaxHttpClient} keyed to those
 * settings, and the bookkeeping fields the work callable needs to log and tag results
 * (provider tag, qualified tool name, user/agent ids, the original argument payload).
 *
 * Constructed only by {@see MiniMaxToolSupport::prepare()} — tool code should not new
 * one up directly.
 */
final readonly class MiniMaxToolContext
{
    /**
     * @param array<string, mixed> $settings  Effective tool settings (already merged across scopes).
     * @param array<string, mixed> $arguments Original tool-call arguments as the LLM supplied them.
     */
    public function __construct(
        public string          $provider,
        public string          $qualifiedName,
        public MiniMaxHttpClient $client,
        public array           $settings,
        public array           $arguments,
        public ?int            $userId,
        public int             $agentId,
    ) {}
}
