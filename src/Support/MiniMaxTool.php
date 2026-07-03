<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use Psr\Log\LoggerInterface;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Abstract base for every MiniMax tool. Owns the cross-cutting wiring
 * (the {@see MiniMaxToolSupport} plumbing, the constructor) and the
 * `validate → prepare → run` orchestration that every tool follows.
 *
 * A subclass only has to provide:
 *   - the {@see PROVIDER}/{@see QUALIFIED_NAME}/{@see TIMEOUT_SECONDS}/
 *     {@see TOOL_LABEL} constants
 *   - a {@see describeAction()} (LLM-facing one-liner)
 *   - a {@see validateArguments()} (returns ?ToolResult)
 *   - a {@see doWork()} (the actual API call + response parsing)
 *
 * The base class handles:
 *   - building the {@see MiniMaxToolSupport} from the constructor args
 *     (so subclasses never store unused fields — SonarQube php:S1068)
 *   - orchestrating validate → prepare → run via {@see runWithValidation()}
 *
 * Multi-operation tools (e.g. {@see \Spora\Plugins\MiniMax\Tools\MiniMaxMusicTool})
 * override `execute()` to dispatch to per-operation methods, each of which
 * then calls `runWithValidation()` independently.
 */
abstract class MiniMaxTool extends AbstractTool
{
    protected const PROVIDER       = '';
    protected const QUALIFIED_NAME = '';
    protected const TIMEOUT_SECONDS = 30;
    protected const TOOL_LABEL      = '';

    protected MiniMaxToolSupport $support;

    public function __construct(
        ToolConfigService   $configService,
        HttpClientInterface $httpClient,
        MiniMaxLogWriter    $logWriter,
        ?LoggerInterface    $logger = null,
        ?MiniMaxToolSupport $support = null,
    ) {
        // Constructor params are consumed once to build the support and then
        // go out of scope. The support owns the long-lived references.
        $this->support = $support ?? new MiniMaxToolSupport($configService, $httpClient, $logWriter, $logger);
    }

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        // Resolve the timeout from per-tool settings before runWithValidation
        // so the value isn't baked into the constant. Settings are not yet
        // available here (they live on the context), so the safe default is
        // the class constant; per-tool overrides apply inside doWork via
        // MiniMaxSettings::timeoutSeconds().
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            static::TIMEOUT_SECONDS,
            static::TOOL_LABEL,
            fn(MiniMaxToolContext $ctx) => $this->doWork($ctx, $arguments),
            fn(array $a) => $this->validateArguments($a),
        );
    }

    /**
     * Resolve a per-stage HTTP timeout from the layered config (per-tool
     * setting → env → default). Subclasses call this instead of reading
     * `static::TIMEOUT_SECONDS` so the operator can override individual
     * stages via the settings UI.
     *
     * @param array<string, mixed> $settings
     */
    protected function resolveTimeout(string $field, array $settings, int $fallback): int
    {
        return MiniMaxSettings::timeoutSeconds(static::PROVIDER, $field, $settings) ?: $fallback;
    }

    /**
     * Standard validate → prepare → run orchestration. Multi-operation tools
     * call this from each per-operation method instead of `execute()`.
     *
     * @param  array<string, mixed> $arguments
     * @param  callable(MiniMaxToolContext, array<string, mixed>): ToolResult $work
     * @param  ?callable(array<string, mixed>): ?ToolResult                  $validate
     */
    protected function runWithValidation(
        array   $arguments,
        int     $agentId,
        ?int    $userId,
        int     $timeoutSeconds,
        string  $toolLabel,
        callable $work,
        ?callable $validate = null,
    ): ToolResult {
        if ($validate !== null) {
            $validation = $validate($arguments);
            if ($validation !== null) {
                return $validation;
            }
        }

        $ctx = $this->support->prepare(
            toolClass: static::class,
            provider: static::PROVIDER,
            qualifiedName: static::QUALIFIED_NAME,
            arguments: $arguments,
            agentId: $agentId,
            userId: $userId,
            timeoutSeconds: $timeoutSeconds,
        );
        if ($ctx instanceof ToolResult) {
            return $ctx;
        }

        return $this->support->run($ctx, $toolLabel, fn(MiniMaxToolContext $c) => $work($c, $arguments));
    }

    /** @param array<string, mixed> $arguments */
    abstract protected function validateArguments(array $arguments): ?ToolResult;

    /** @param array<string, mixed> $arguments */
    abstract protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult;
}
