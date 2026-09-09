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
    private ?LoggerInterface $logger;

    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        ?LoggerInterface                     $logger = null,
    ) {
        $this->logger = $logger;
    }

    /**
     * Wired by PHP-DI from {@see MiniMaxPlugin::register()}; the optional
     * ctor param is short-circuited to null by reflection autowiring, so
     * the setter is the only path that reaches the production logger.
     */
    public function setLogger(?LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

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
        array  $arguments,
        int    $agentId,
        ?int   $ownerUserId,
        ?int   $runnerUserId,
        int    $timeoutSeconds,
    ): MiniMaxToolContext|ToolResult {
        // The MiniMax API key belongs to the owner — that's whose
        // settings pay for the call. The runner is who triggered it
        // and rides along for the row attribution / Media Archive
        // permission checks downstream.
        $settings = $this->configService->getEffectiveSettings($toolClass, $agentId, $ownerUserId);
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
            client: $client,
            settings: $settings,
            arguments: $arguments,
            ownerUserId: $ownerUserId,
            runnerUserId: $runnerUserId,
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
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error("MiniMax{$toolLabel}: unexpected exception", ['exception' => $e]);
            $message = "{$toolLabel} failed: " . $e->getMessage();
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
