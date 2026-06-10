<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Psr\Log\LoggerInterface;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxLogWriter;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Services\ToolConfigService;
use Spora\Tools\AbstractTool;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

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
#[ToolSetting(
    key: 'plugin.minimax.video.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimaxi.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.video.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'Override the MiniMax base URL (default: https://api.minimaxi.io).',
    default: 'https://api.minimaxi.io',
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
    type: 'integer',
    description: 'Target video duration in seconds.',
    required: false,
    enum: [6, 10],
    default: 6,
)]
#[ToolParameter(
    name: 'aspect_ratio',
    type: 'string',
    description: 'Aspect ratio of the generated video.',
    required: false,
    enum: ['16:9', '9:16', '1:1'],
    default: '16:9',
)]
final class MiniMaxVideoTool extends AbstractTool
{
    private const PROVIDER = 'video';
    private const DEFAULT_MODEL = 'MiniMax-Hailuo-2.3';

    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly MiniMaxLogWriter    $logWriter,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->generate($arguments, $agentId, $userId, $taskId);
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate video for prompt: '{$prompt}'";
    }

    public function generate(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $duration = (int) ($arguments['duration_seconds'] ?? 6);
        $aspectRatio = trim((string) ($arguments['aspect_ratio'] ?? '16:9'));

        if ($prompt === '') {
            return new ToolResult(false, 'Prompt cannot be empty.');
        }
        if (mb_strlen($prompt) > 2000) {
            return new ToolResult(false, 'Prompt exceeds the 2000-character MiniMax limit.');
        }
        if (!in_array($duration, [6, 10], true)) {
            return new ToolResult(false, 'duration_seconds must be 6 or 10.');
        }
        if (!in_array($aspectRatio, ['16:9', '9:16', '1:1'], true)) {
            return new ToolResult(false, 'aspect_ratio must be 16:9, 9:16, or 1:1.');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = MiniMaxSettings::apiKey(self::PROVIDER, $settings);
        if ($apiKey === '') {
            return new ToolResult(false, 'MiniMax API key is not configured for this agent. Edit the MiniMax Video settings.');
        }

        $pollInterval = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_interval_seconds', $settings, 10);
        $pollTimeout = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $settings, 600);

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl(self::PROVIDER, $settings),
            timeoutSeconds: 30,
            logger: $this->logger,
        );

        $qualifiedName = 'minimax:' . 'video';

        try {
            $startResponse = $client->postJson('/v1/video_generation', [
                'model'         => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
                'prompt'        => $prompt,
                'duration'      => $duration,
                'aspect_ratio'  => $aspectRatio,
            ]);

            $taskId = $startResponse['task_id'] ?? null;
            if (!is_string($taskId) || $taskId === '') {
                $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $startResponse, false, 'No task_id in response', $userId, $agentId);
                return new ToolResult(false, 'MiniMax returned no task_id.');
            }

            $this->logger?->info('MiniMaxVideoTool: video generation started', [
                'task_id'   => $taskId,
                'interval'  => $pollInterval,
                'timeout'   => $pollTimeout,
            ]);

            $finalResponse = $this->pollUntilDone($client, $taskId, $pollInterval, $pollTimeout);

            $downloadUrl = $this->fetchDownloadUrl($client, $taskId, $finalResponse);

            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $finalResponse, true, null, $userId, $agentId);

            $content = "Generated video for prompt: \"{$prompt}\"\n\nDownload URL (valid 24h): {$downloadUrl}";
            return new ToolResult(true, $content, [
                'task_id'      => $taskId,
                'video_url'    => $downloadUrl,
                'duration'     => $duration,
                'aspect_ratio' => $aspectRatio,
            ]);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxVideoTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, 'Video generation failed: ' . $e->getMessage());
        }
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

            if ($status === 'success') {
                return $response;
            }
            if ($status === 'failed') {
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

    /**
     * @param array<string, mixed> $finalResponse
     */
    private function fetchDownloadUrl(MiniMaxHttpClient $client, string $taskId, array $finalResponse): string
    {
        // Some MiniMax video responses include a download URL directly; others
        // require a follow-up call. Prefer the inline value when present.
        $inline = $finalResponse['file']['download_url']
            ?? $finalResponse['download_url']
            ?? $finalResponse['video_url']
            ?? null;
        if (is_string($inline) && $inline !== '') {
            return $inline;
        }

        $response = $client->getJson('/v1/video_generation/download', ['task_id' => $taskId]);
        $url = $response['file']['download_url'] ?? $response['download_url'] ?? null;
        if (!is_string($url) || $url === '') {
            throw new MiniMaxApiException('MiniMax video succeeded but no download URL was returned', 0);
        }
        return $url;
    }
}
