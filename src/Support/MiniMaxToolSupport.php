<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Support;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Services\ToolConfigService;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Cross-cutting plumbing shared by every MiniMax tool: resolve settings, build an
 * authenticated HTTP client, and wrap the tool's work callable in the standard
 * try/catch + audit-log behaviour.
 *
 * The goal is to shrink each tool's `execute()` to: validate inputs → call
 * `run()`. Everything else (API-key check, client construction, exception
 * handling, error logging) lives here, which is what keeps the per-method
 * return counts and cognitive complexity inside SonarQube's defaults.
 *
 * The work callable receives a {@see MiniMaxToolContext} and must return a
 * {@see ToolResult}. It is responsible for handling "valid response but
 * missing the field I needed" cases; this class handles transport-level
 * exceptions only.
 */
final class MiniMaxToolSupport
{
    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly MiniMaxLogWriter    $logWriter,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

    /**
     * Resolve settings for the given tool class, verify the API key is present, and
     * build a {@see MiniMaxHttpClient} ready to call MiniMax. Returns a
     * {@see MiniMaxToolContext} on success or a failed {@see ToolResult} on
     * missing credentials.
     *
     * Tool-specific input validation (prompt length, enum values, …) is the
     * caller's responsibility — it must happen before this method so the error
     * message is anchored to the right field.
     *
     * @param  class-string         $toolClass     Concrete tool class (e.g. `MiniMaxImageTool::class`).
     * @param  array<string, mixed> $arguments     Tool-call arguments as the LLM supplied them.
     */
    public function prepare(
        string $toolClass,
        string $provider,
        string $qualifiedName,
        array  $arguments,
        int    $agentId,
        ?int   $userId,
        int    $timeoutSeconds,
    ): MiniMaxToolContext|ToolResult {
        $settings = $this->configService->getEffectiveSettings($toolClass, $agentId, $userId);
        $apiKey   = MiniMaxSettings::apiKey($provider, $settings);

        if ($apiKey === '') {
            return new ToolResult(false, "MiniMax API key is not configured for this agent. Edit the MiniMax {$this->displayName($provider)} settings.");
        }

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl($provider, $settings),
            timeoutSeconds: $timeoutSeconds,
            logger: $this->logger,
        );

        return new MiniMaxToolContext(
            provider: $provider,
            qualifiedName: $qualifiedName,
            client: $client,
            settings: $settings,
            arguments: $arguments,
            userId: $userId,
            agentId: $agentId,
        );
    }

    /**
     * Run the tool's work callable with the standard exception → log + ToolResult
     * behaviour. The callable receives the prepared context and returns a result.
     *
     * @param callable(MiniMaxToolContext): ToolResult $work
     */
    public function run(
        MiniMaxToolContext $ctx,
        string             $toolLabel,
        callable           $work,
    ): ToolResult {
        try {
            return $work($ctx);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->logFailure($ctx, ['error' => $e->getMessage()], $e->getMessage());
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error("MiniMax{$toolLabel}: unexpected exception", ['exception' => $e]);
            $message = "{$toolLabel} failed: " . $e->getMessage();
            $this->logWriter->logFailure($ctx, ['error' => $e->getMessage()], $message);
            return new ToolResult(false, $message);
        }
    }

    private function displayName(string $provider): string
    {
        return match ($provider) {
            'image'  => 'Image',
            'speech' => 'Speech',
            'music'  => 'Music',
            'video'  => 'Video',
            default  => ucfirst($provider),
        };
    }

    /** @param array<string, mixed> $response */
    public function logSuccess(MiniMaxToolContext $ctx, array $response): void
    {
        $this->logWriter->logSuccess($ctx, $response);
    }

    /** @param array<string, mixed> $response */
    public function logFailure(MiniMaxToolContext $ctx, array $response, string $error): void
    {
        $this->logWriter->logFailure($ctx, $response, $error);
    }

    /**
     * Optional PSR-3 logger the tool may use for debug-level entries (e.g.
     * polling-loop progress). Null when no logger is wired up — the `?->`
     * chain in callers makes the no-logger case free.
     */
    public function logger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
