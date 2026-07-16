<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Plugins\MiniMax\Support\Exceptions\MiniMaxApiException;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Generates a video from a text prompt via MiniMax's video_generation API.
 * This is an asynchronous endpoint: the first call returns a `task_id`, which
 * is then polled until it transitions to `success` or `failed`. The successful
 * response includes a download URL.
 */
#[Tool(
    name: 'video',
    description: 'Generate a short video clip (asynchronous; up to 10s). The download URL is valid for ~1 hour.',
    displayName: 'MiniMax Video',
    category: 'generation',
)]
#[ToolOperation(name: 'generate', description: 'Generate a short video clip from a text prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'model',
    label: 'Model',
    type: 'text',
    description: 'Video model id (default: MiniMax-Hailuo-2.3).',
    default: 'MiniMax-Hailuo-2.3',
)]
#[ToolSetting(
    key: 'poll_interval_seconds',
    label: 'Poll interval (s)',
    type: 'text',
    description: 'Seconds between status polls (default: 10).',
    default: '10',
)]
#[ToolSetting(
    key: 'poll_timeout_seconds',
    label: 'Poll timeout (s)',
    type: 'text',
    description: 'Maximum total wait for video generation (default: 600).',
    default: '600',
)]
#[ToolSetting(
    key: 'submit_timeout_seconds',
    label: 'Submit timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the submit API call (MiniMax queues the task server-side; default: 120).',
    default: '120',
)]
#[ToolSetting(
    key: 'retrieve_timeout_seconds',
    label: 'File retrieve timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the /v1/files/retrieve call (default: 30).',
    default: '30',
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
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional human-readable filename without an extension (e.g. "forest-push-in"). The correct file extension is appended automatically. When omitted, a speaking name is generated from the prompt.',
    required: false,
    maximum: 120,
)]
final class MiniMaxVideoTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'video';
    protected const DEFAULT_MODEL   = 'MiniMax-Hailuo-2.3';
    protected const QUALIFIED_NAME  = 'minimax:video';
    protected const TIMEOUT_SECONDS = 120;
    protected const TOOL_LABEL      = 'Video generation';

    public function __construct(
        \Spora\Services\ToolConfigService $configService,
        \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        \Spora\Plugins\MiniMax\Support\MiniMaxLogWriter $logWriter,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\Spora\Plugins\MiniMax\Support\MiniMaxToolSupport $support = null,
        ?\Spora\Services\MediaArchive\MediaArchiveService $mediaArchive = null,
    ) {
        parent::__construct($configService, $httpClient, $logWriter, $logger, $support);
        $this->attachVideoMediaArchive($mediaArchive);
    }

    private function attachVideoMediaArchive(?\Spora\Services\MediaArchive\MediaArchiveService $archive): void
    {
        if ($archive !== null) {
            $this->setMediaArchive($archive);
        }
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate video for prompt: '{$prompt}'";
    }

    /** @param array<string, mixed> $arguments */
    protected function validateArguments(array $arguments): ?ToolResult
    {
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));
        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        $duration    = in_array($durationRaw, ['6', '10'], true) ? (int) $durationRaw : 0;
        $errors = [];
        if ($prompt === '') {
            $errors[] = 'Prompt cannot be empty.';
        }
        if (mb_strlen($prompt) > 2000) {
            $errors[] = 'Prompt exceeds the 2000-character MiniMax limit.';
        }
        if (!in_array($duration, [6, 10], true)) {
            $errors[] = 'duration_seconds must be 6 or 10.';
        }
        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /** @param array<string, mixed> $arguments */
    protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $prompt      = trim((string) ($arguments['prompt'] ?? ''));
        $durationRaw = (string) ($arguments['duration_seconds'] ?? '6');
        $duration    = (int) $durationRaw;
        $resolution  = trim((string) ($arguments['resolution'] ?? ''));

        $pollInterval = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_interval_seconds', $ctx->settings, 10);
        $pollTimeout  = MiniMaxSettings::intSetting(self::PROVIDER, 'poll_timeout_seconds', $ctx->settings, 600);

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;

        $submitTimeout = $this->resolveTimeout('submit_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $taskId = $this->submitGeneration($client, $ctx->settings, $prompt, $duration, $resolution, $submitTimeout);
        $this->support->logger()?->info('MiniMaxVideoTool: video generation started', [
            'task_id'      => $taskId,
            'interval'     => $pollInterval,
            'poll_timeout' => $pollTimeout,
            'submit_timeout' => $submitTimeout,
        ]);

        $finalResponse = $this->pollUntilDone($client, $taskId, $pollInterval, $pollTimeout);
        $this->support->logSuccess($ctx, $finalResponse);

        $fileId = is_string($finalResponse['file_id'] ?? null) ? $finalResponse['file_id'] : null;
        $width  = is_int($finalResponse['video_width'] ?? null) ? $finalResponse['video_width'] : null;
        $height = is_int($finalResponse['video_height'] ?? null) ? $finalResponse['video_height'] : null;

        if ($fileId === null) {
            return new ToolResult(false, 'MiniMax video succeeded but returned no file_id.');
        }

        // The retrieve response carries a `download_url` valid for ~1 hour.
        $retrieveTimeout = $this->resolveTimeout('retrieve_timeout_seconds', $ctx->settings, 30);
        $downloadUrl = $this->retrieveDownloadUrl($client, $fileId, $retrieveTimeout);

        $sizeLine = ($width !== null && $height !== null) ? " ({$width}x{$height})" : '';
        if ($downloadUrl === null) {
            return new ToolResult(
                false,
                "MiniMax video succeeded (task_id={$taskId}, file_id={$fileId}) "
                . "but the file-retrieve API did not return a download_url. "
                . "Try again or fetch the file directly from your MiniMax dashboard.",
            );
        }

        // Ingest failures must never break the tool — fall back to the CDN URL.
        $archiveAsset = null;
        try {
            $archiveAsset = $this->mediaArchive()->ingest(new MediaIngestRequest(
                url: $downloadUrl,
                agentId: $ctx->agentId,
                pluginSlug: 'minimax',
                toolName: 'video',
                prompt: $prompt,
                width: $width,
                height: $height,
                durationSeconds: (float) $duration,
                filename: self::resolveFilename(
                    isset($arguments['filename']) ? (string) $arguments['filename'] : null,
                    $prompt,
                    'minimax-video',
                    'mp4',
                ),
            ));
        } catch (Throwable $e) {
            $this->support->logger()?->warning('MediaArchive ingest failed (video)', [
                'exception' => $e,
                'url'       => $downloadUrl,
            ]);
        }

        $archiveUrl = ($archiveAsset !== null && $archiveAsset->asset_url !== '' && !str_starts_with($archiveAsset->asset_url, 'data:'))
            ? $archiveAsset->asset_url
            : null;
        $embedUrl = $archiveUrl ?? $downloadUrl;
        $durationNote = $archiveUrl !== null
            ? ''
            : ' (URL valid ~1 hour)';

        $content = "Generated video{$sizeLine} for prompt: \"{$prompt}\"\n\n"
            . MediaEmbed::videoFromUrl($embedUrl, $width, $height) . "\n\n"
            . "task_id: {$taskId}  file_id: {$fileId}{$durationNote}"
            . "\n\nUse the same video embed above to show the video player in your reply.";

        return new ToolResult(true, $content, [
            'task_id'      => $taskId,
            'file_id'      => $fileId,
            'download_url' => $downloadUrl,
            'asset_url'    => $embedUrl,
            'width'        => $width,
            'height'       => $height,
            'duration'     => $duration,
            'resolution'   => $resolution !== '' ? $resolution : null,
        ]);
    }

    /**
     * Returns null if the upstream didn't return a download URL — the caller
     * surfaces a clear failure rather than pretending success.
     */
    private function retrieveDownloadUrl(MiniMaxHttpClient $client, string $fileId, int $timeoutSeconds): ?string
    {
        $response = $client->getJson(
            '/v1/files/retrieve',
            ['file_id' => $fileId],
            timeoutSeconds: $timeoutSeconds,
        );
        $file = is_array($response['file'] ?? null) ? $response['file'] : [];
        $url = $file['download_url'] ?? null;
        return is_string($url) && $url !== '' ? $url : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function submitGeneration(
        MiniMaxHttpClient $client,
        array $settings,
        string $prompt,
        int $duration,
        string $resolution,
        int $timeoutSeconds,
    ): string {
        $body = [
            'model'    => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'prompt'   => $prompt,
            'duration' => $duration,
        ];
        if ($resolution !== '') {
            $body['resolution'] = $resolution;
        }

        $startResponse = $client->postJson('/v1/video_generation', $body, timeoutSeconds: $timeoutSeconds);
        $taskId = $startResponse['task_id'] ?? null;
        if (!is_string($taskId) || $taskId === '') {
            // Synthetic MiniMaxApiException so the shared try/catch in
            // MiniMaxToolSupport::run() logs and converts to a ToolResult.
            throw new MiniMaxApiException('MiniMax returned no task_id.', 0, $startResponse);
        }
        return $taskId;
    }

    /**
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

            $this->support->logger()?->debug('MiniMaxVideoTool: still processing, sleeping', [
                'task_id'  => $taskId,
                'status'   => $status,
                'interval' => $intervalSeconds,
            ]);
            sleep(max(1, $intervalSeconds));
        }
    }
}
