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
 * Generates music (instrumental or with lyrics) via MiniMax's music_generation API.
 * Returns the upstream audio URL (24h expiry) when `output_format=url`; hex
 * otherwise.
 */
#[Tool(
    name: 'music',
    description: 'Generate music (instrumental or with lyrics) via MiniMax. Returns a CDN URL valid for 24 hours.',
    displayName: 'MiniMax Music',
    category: 'generation',
)]
#[ToolSetting(
    key: 'plugin.minimax.music.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimaxi.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.music.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'Override the MiniMax base URL (default: https://api.minimaxi.io).',
    default: 'https://api.minimaxi.io',
)]
#[ToolSetting(
    key: 'plugin.minimax.music.model',
    label: 'Model',
    type: 'text',
    description: 'Music model id (default: music-2.6).',
    default: 'music-2.6',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Style / mood description of the music (max 2000 characters). Optional when `lyrics` is provided.',
    required: false,
    maximum: 2000,
)]
#[ToolParameter(
    name: 'lyrics',
    type: 'string',
    description: 'Lyrics to sing (1-3500 characters). Omit for instrumental music.',
    required: false,
    maximum: 3500,
)]
#[ToolParameter(
    name: 'output_format',
    type: 'string',
    description: '`url` returns a 24h CDN URL; `hex` returns inline audio bytes.',
    required: false,
    enum: ['url', 'hex'],
    default: 'url',
)]
final class MiniMaxMusicTool extends AbstractTool
{
    private const PROVIDER = 'music';
    private const DEFAULT_MODEL = 'music-2.6';

    public function __construct(
        private readonly ToolConfigService   $configService,
        private readonly HttpClientInterface $httpClient,
        private readonly MiniMaxLogWriter    $logWriter,
        private readonly ?LoggerInterface    $logger = null,
    ) {}

    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        return $this->compose($arguments, $agentId, $userId, $taskId);
    }

    public function describeAction(array $arguments): string
    {
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);
        return "Generate music for: '{$prompt}'";
    }

    public function compose(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));
        $outputFormat = trim((string) ($arguments['output_format'] ?? 'url'));

        if ($prompt === '' && $lyrics === '') {
            return new ToolResult(false, 'Provide at least a `prompt` or `lyrics`.');
        }
        if (mb_strlen($prompt) > 2000) {
            return new ToolResult(false, 'Prompt exceeds the 2000-character MiniMax limit.');
        }
        if ($lyrics !== '' && mb_strlen($lyrics) > 3500) {
            return new ToolResult(false, 'Lyrics exceed the 3500-character MiniMax limit.');
        }
        if (!in_array($outputFormat, ['url', 'hex'], true)) {
            return new ToolResult(false, 'output_format must be "url" or "hex".');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = MiniMaxSettings::apiKey(self::PROVIDER, $settings);
        if ($apiKey === '') {
            return new ToolResult(false, 'MiniMax API key is not configured for this agent. Edit the MiniMax Music settings.');
        }

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl(self::PROVIDER, $settings),
            timeoutSeconds: 90,
            logger: $this->logger,
        );

        $body = [
            'model'         => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'output_format' => $outputFormat,
            'lyrics'        => $lyrics,
        ];
        if ($prompt !== '') {
            $body['prompt'] = $prompt;
        }

        $qualifiedName = 'minimax:' . 'music';

        try {
            $response = $client->postJson('/v1/music_generation', $body);

            $data = is_array($response['data'] ?? null) ? $response['data'] : [];
            $hexAudio = isset($data['audio']) && is_string($data['audio']) ? $data['audio'] : null;
            $audioUrl = isset($data['audio_url']) && is_string($data['audio_url']) ? $data['audio_url'] : null;

            if ($hexAudio === null && $audioUrl === null) {
                $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, false, 'No audio in response', $userId, $agentId);
                return new ToolResult(false, 'MiniMax returned no audio data.');
            }

            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, true, null, $userId, $agentId);

            $promptSummary = $prompt !== '' ? "prompt: \"{$prompt}\"" : 'instrumental';
            if ($audioUrl !== null) {
                $content = "Generated music ({$promptSummary}).\n\nCDN URL (valid 24h): {$audioUrl}";
                return new ToolResult(true, $content, ['audio_url' => $audioUrl]);
            }
            $byteCount = (int) (strlen($hexAudio) / 2);
            $content = "Generated music ({$promptSummary}).\n\nAudio payload: {$byteCount} bytes (hex-encoded, inline).";
            return new ToolResult(true, $content, ['audio_bytes' => $byteCount]);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxMusicTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, 'Music generation failed: ' . $e->getMessage());
        }
    }
}
