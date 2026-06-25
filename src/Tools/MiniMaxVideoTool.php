<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxLogContext;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Plugins\MiniMax\Support\MiniMaxToolSupport;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generates a video from a text prompt via MiniMax's video_generation API.
 * This is an asynchronous endpoint: the first call returns a `task_id`, which
 * is then polled until it transitions to `success` or `failed`. The successful
 * response includes a download URL.
 */
#[Tool(
    name: 'video',
    description: 'Generate a short video clip from a text prompt via MiniMax. Asynchronous — may take up to several minutes; the tool polls until the result is ready or the timeout elapses.',
    displayName: 'MiniMax Video',
    category: 'generation',
)]
#[ToolOperation(name: 'generate', description: 'Generate a short video clip from a text prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'plugin.minimax.video.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.video.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'plugin.minimax.video.model',
    label: 'Model',
    type: 'text',
    description: 'Video model id (default: MiniMax-Hailuo-2.3).',
    default: 'MiniMax-Hailuo-2.3',
)]
#[ToolSetting(
    key: 'plugin.minimax.video.poll_interval_seconds',
    label: 'Poll interval (s)',
    type: 'text',
    description: 'Seconds between status polls (default: 10).',
    default: '10',
)]
#[ToolSetting(
    key: 'plugin.minimax.video.poll_timeout_seconds',
    label: 'Poll timeout (s)',
    type: 'text',
    description: 'Maximum total wait for video generation (default: 600).',
    default: '600',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Text prompt describing the video. Camera-movement tags like `[Pan left]`, `[Push in]` are supported (max 2000 characters).',
    required: true,
    maximum: 2000,
)]
#[ToolParameter(
    name: 'duration_seconds',
    type: 'string',
    description: 'Target video duration in seconds (6 or 10).',
    required: false,
    enum: ['6', '10'],
    default: '6',
)]
#[ToolParameter(
    name: 'resolution',
    type: 'string',
    description: 'Video resolution (e.g. "1080p"). MiniMax picks a default if omitted; the public docs do not enumerate valid values.',
    required: false,
)]
final class MiniMaxVideoTool extends AbstractTool
{
    private const PROVIDER = 'video';
    private const DEFAULT_MODEL = 'MiniMax-Hailuo-2.3';
    private const QUALIFIED_NAME = 'minimax:video';
    private const TIMEOUT_SECONDS = 30;
    private const TOOL_LABEL = 'Video generation';

    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly MiniMaxLogWriter    $logWriter,
        private readonly ?LoggerInterface    $logger = null,
        ?MiniMaxToolSupport                  $support = null,
    ) {
        $this->support = $support ?? new MiniMaxToolSupport($configService, $httpClient, $logWriter, $logger);
    }

    private MiniMaxToolSupport $support;

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $validation = $this->validateArguments($arguments);
        if ($validation !== null) {
            return $validation;
        }

        $ctx = $this->support->prepare(
            toolClass: static::class,
            provider: self::PROVIDER,
            qualifiedName: self::QUALIFIED_NAME,
            arguments: $arguments,
            agentId: $agentId,
            userId: $userId,
            timeoutSeconds: self::TIMEOUT_SECONDS,
        );
        if ($ctx instanceof ToolResult) {
            return $ctx;
        }

        return $this->support->run($ctx, self::TOOL_LABEL, fn(MiniMaxToolContext $c) => $this->doGenerate($c, $arguments));
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate video for prompt: '{$prompt}'";
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function validateArguments(array $arguments): ?ToolResult
    {
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));
        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        $duration    = in_array($durationRaw, ['6', '10'], true) ? (int) $durationRaw : 0;

        if ($prompt === '') {
            return new ToolResult(false, 'Prompt cannot be empty.');
        }
        if (mb_strlen($prompt) > 2000) {
            return new ToolResult(false, 'Prompt exceeds the 2000-character MiniMax limit.');
        }
        if (!in_array($duration, [6, 10], true)) {
            return new ToolResult(false, 'duration_seconds must be 6 or 10.');
        }
        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function doGenerate(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));
        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        $duration    = (int) $durationRaw;
        $resolution  = trim((string) ($arguments['resolution'] ?? ''));

        $pollInterval = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_interval_seconds', $ctx->settings, 10);
        $pollTimeout  = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $ctx->settings, 600);

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;

        $taskId = $this->submitGeneration($client, $ctx->settings, $prompt, $duration, $resolution);
        $this->logger?->info('MiniMaxVideoTool: video generation started', [
            'task_id'  => $taskId,
            'interval' => $pollInterval,
            'timeout'  => $pollTimeout,
        ]);

        $finalResponse = $this->pollUntilDone($client, $taskId, $pollInterval, $pollTimeout);

        // The success response carries a file_id, not a direct download URL.
        // Retrieving the underlying file requires MiniMax's file-management
        // endpoints (not documented on the public API page at the time of
        // v1). v1 returns the file_id so a downstream caller can fetch the
        // asset via a separate authenticated request when they need it.
        $this->logWriter->record(new MiniMaxLogContext(
            provider: self::PROVIDER,
            qualifiedToolName: self::QUALIFIED_NAME,
            request: $arguments,
            response: $finalResponse,
            success: true,
            userId: $ctx->userId,
            agentId: $ctx->agentId,
        ));

        $fileId = is_string($finalResponse['file_id'] ?? null) ? $finalResponse['file_id'] : null;
        $width  = is_int($finalResponse['video_width'] ?? null) ? $finalResponse['video_width'] : null;
        $height = is_int($finalResponse['video_height'] ?? null) ? $finalResponse['video_height'] : null;

        $sizeLine = ($width !== null && $height !== null) ? " ({$width}x{$height})" : '';
        $content = "Generated video{$sizeLine} for prompt: \"{$prompt}\"\n\n"
            . "task_id: {$taskId}\nfile_id: {$fileId}\n\n"
            . "Retrieve the file via MiniMax's file-management API using this file_id "
            . "(the public docs do not document the file-retrieval endpoint at the time of v1).";

        return new ToolResult(true, $content, [
            'task_id'    => $taskId,
            'file_id'    => $fileId,
            'width'      => $width,
            'height'     => $height,
            'duration'   => $duration,
            'resolution' => $resolution !== '' ? $resolution : null,
        ]);
    }

    /**
     * Submit the generation request and return the upstream `task_id`. Records
     * a failure log row and returns a ToolResult-flavoured exception if MiniMax
     * didn't return a usable id.
     *
     * @param array<string, mixed> $settings
     */
    private function submitGeneration(
        MiniMaxHttpClient $client,
        array $settings,
        string $prompt,
        int $duration,
        string $resolution,
    ): string {
        $body = [
            'model'    => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'prompt'   => $prompt,
            'duration' => $duration,
        ];
        if ($resolution !== '') {
            $body['resolution'] = $resolution;
        }

        $startResponse = $client->postJson('/v1/video_generation', $body);
        $taskId = $startResponse['task_id'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            // Mark as a business error (HTTP succeeded but payload is unusable).
            // The exception path in MiniMaxToolSupport::run() will log and convert
            // to a ToolResult — we use a synthetic MiniMaxApiException so the
            // shared try/catch handles it identically to a real API failure.
            throw new MiniMaxApiException('MiniMax returned no task_id.', 0, $startResponse);
        }
        return $taskId;
    }

    /**
     * Poll the task status endpoint until it transitions out of `processing`,
     * or until the timeout elapses. Returns the final status response.
     *
     * @return array<string, mixed>
     */
    private function pollUntilDone(MiniMaxHttpClient $client, string $taskId, int $intervalSeconds, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (true) {
            if (microtime(true) >= $deadline) {
                throw new MiniMaxApiException(
                    "MiniMax video generation did not finish within {$timeoutSeconds}s",
                    0,
                );
            }

            $response = $client->getJson('/v1/query/video_generation', ['task_id' => $taskId]);
            $status = $response['status'] ?? null;

            if ($status === 'Success') {
                return $response;
            }
            if ($status === 'Fail') {
                $baseResp = is_array($response['base_resp'] ?? null) ? $response['base_resp'] : [];
                $msg = is_string($baseResp['status_msg'] ?? null) ? $baseResp['status_msg'] : 'video generation failed';
                throw new MiniMaxApiException("MiniMax video generation failed: {$msg}", 0, $baseResp);
            }

            $this->logger?->debug('MiniMaxVideoTool: still processing, sleeping', [
                'task_id'  => $taskId,
                'status'   => $status,
                'interval' => $intervalSeconds,
            ]);
            sleep(max(1, $intervalSeconds));
        }
    }
}
