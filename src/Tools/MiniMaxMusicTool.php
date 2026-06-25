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
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Song-making operations for MiniMax, consolidated into one tool:
 *   - `compose`      — generate music (instrumental or with lyrics) via `/v1/music_generation`
 *   - `write_lyrics` — generate a full song's lyrics via `/v1/lyrics_generation` (mode: write_full_song)
 *   - `edit_lyrics`  — rewrite existing lyrics via `/v1/lyrics_generation` (mode: edit)
 *
 * Returning the upstream audio URL (24h expiry) when `output_format=url`; hex
 * otherwise. Lyrics operations return the upstream text + optional song title
 * and style tags.
 */
#[Tool(
    name: 'music',
    description: 'Song-making: generate music (instrumental or with lyrics), or write/edit song lyrics. The "action" argument selects the operation.',
    displayName: 'MiniMax Music',
    category: 'generation',
)]
#[ToolOperation(name: 'compose', description: 'Generate music (instrumental or with lyrics)', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'write_lyrics', description: 'Write a full song of lyrics from a topic or style description', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'edit_lyrics', description: 'Rewrite existing lyrics according to a prompt', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'plugin.minimax.music.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.music.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'plugin.minimax.music.model',
    label: 'Model',
    type: 'text',
    description: 'Music model id (default: music-2.6). Applies to `compose`; the lyrics endpoint has no model parameter.',
    default: 'music-2.6',
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Style / mood description (max 2000 characters). For `compose`: optional when `lyrics` is provided. For `write_lyrics`: topic or style. For `edit_lyrics`: rewrite instruction.',
    required: false,
    maximum: 2000,
)]
#[ToolParameter(
    name: 'lyrics',
    type: 'string',
    description: 'Lyrics to sing or edit (1-3500 characters). Omit for instrumental music (compose). Required for `edit_lyrics`.',
    required: false,
    maximum: 3500,
)]
#[ToolParameter(
    name: 'output_format',
    type: 'string',
    description: '`url` returns a 24h CDN URL; `hex` returns inline audio bytes. Used by `compose` only.',
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
        $operation = $this->getOperationName($arguments);

        return match ($operation) {
            'compose'      => $this->compose($arguments, $agentId, $userId),
            'write_lyrics' => $this->writeLyrics($arguments, $agentId, $userId),
            'edit_lyrics'  => $this->editLyrics($arguments, $agentId, $userId),
            default        => new ToolResult(false, "Unknown music operation: {$operation}"),
        };
    }

    public function describeAction(array $arguments): string
    {
        $operation = $this->getOperationName($arguments);
        $prompt = mb_substr(trim((string) ($arguments['prompt'] ?? '')), 0, 80);

        return match ($operation) {
            'write_lyrics' => "Write song lyrics for: '{$prompt}'",
            'edit_lyrics'  => "Edit song lyrics: '{$prompt}'",
            default        => "Generate music for: '{$prompt}'",
        };
    }

    public function compose(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $validation = $this->validateComposeArguments($arguments);
        if ($validation !== null) {
            return $validation;
        }

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));
        $outputFormat = trim((string) ($arguments['output_format'] ?? 'url'));

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

        $qualifiedName = 'minimax:' . 'music';

        try {
            $response = $client->postJson('/v1/music_generation', $this->buildComposeBody($settings, $prompt, $lyrics, $outputFormat));

            return $this->parseComposeResponse($response, $arguments, $prompt, $userId, $agentId, $qualifiedName);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxMusicTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, 'Music generation failed: ' . $e->getMessage());
        }
    }

    private function validateComposeArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));
        $outputFormat = trim((string) ($arguments['output_format'] ?? 'url'));

        if ($prompt === '' && $lyrics === '') {
            $errors[] = 'Provide at least a `prompt` or `lyrics`.';
        }
        if (mb_strlen($prompt) > 2000) {
            $errors[] = 'Prompt exceeds the 2000-character MiniMax limit.';
        }
        if ($lyrics !== '' && mb_strlen($lyrics) > 3500) {
            $errors[] = 'Lyrics exceed the 3500-character MiniMax limit.';
        }
        if (!in_array($outputFormat, ['url', 'hex'], true)) {
            $errors[] = 'output_format must be "url" or "hex".';
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildComposeBody(array $settings, string $prompt, string $lyrics, string $outputFormat): array
    {
        $body = [
            'model'         => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'output_format' => $outputFormat,
            'lyrics'        => $lyrics,
        ];
        if ($prompt !== '') {
            $body['prompt'] = $prompt;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed> $response
     */
    private function parseComposeResponse(
        array $response,
        array $arguments,
        string $prompt,
        ?int $userId,
        int $agentId,
        string $qualifiedName,
    ): ToolResult {
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
    }

    public function writeLyrics(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->lyrics('write_full_song', $arguments, $agentId, $userId);
    }

    public function editLyrics(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->lyrics('edit', $arguments, $agentId, $userId);
    }

    private function lyrics(string $mode, array $arguments, int $agentId, ?int $userId): ToolResult
    {
        $validation = $this->validateLyricsArguments($mode, $arguments);
        if ($validation !== null) {
            return $validation;
        }

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = MiniMaxSettings::apiKey(self::PROVIDER, $settings);
        if ($apiKey === '') {
            return new ToolResult(false, 'MiniMax API key is not configured for this agent. Edit the MiniMax Music settings.');
        }

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl(self::PROVIDER, $settings),
            timeoutSeconds: 30,
            logger: $this->logger,
        );

        $qualifiedName = 'minimax:' . 'music';

        try {
            $response = $client->postJson('/v1/lyrics_generation', $this->buildLyricsBody($mode, $prompt, $lyrics));

            return $this->parseLyricsResponse($response, $arguments, $mode, $userId, $agentId, $qualifiedName);
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxMusicTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, 'Lyrics generation failed: ' . $e->getMessage());
        }
    }

    private function validateLyricsArguments(string $mode, array $arguments): ?ToolResult
    {
        $errors = [];

        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));

        if (mb_strlen($prompt) > 2000) {
            $errors[] = 'Prompt exceeds the 2000-character MiniMax limit.';
        }
        if ($mode === 'edit' && $lyrics === '') {
            $errors[] = '`lyrics` is required for the edit_lyrics operation.';
        }
        if ($lyrics !== '' && mb_strlen($lyrics) > 3500) {
            $errors[] = 'Lyrics exceed the 3500-character MiniMax limit.';
        }
        if ($mode === 'write_full_song' && $prompt === '' && $lyrics === '') {
            $errors[] = 'Provide a `prompt` describing the song (or pre-existing `lyrics`).';
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLyricsBody(string $mode, string $prompt, string $lyrics): array
    {
        $body = ['mode' => $mode];
        if ($prompt !== '') {
            $body['prompt'] = $prompt;
        }
        if ($lyrics !== '') {
            $body['lyrics'] = $lyrics;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed> $response
     */
    private function parseLyricsResponse(
        array $response,
        array $arguments,
        string $mode,
        ?int $userId,
        int $agentId,
        string $qualifiedName,
    ): ToolResult {
        $generated = $response['lyrics'] ?? null;
        $songTitle = $response['song_title'] ?? null;
        $styleTags = $response['style_tags'] ?? null;

        if (!is_string($generated) || $generated === '') {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, false, 'No lyrics in response', $userId, $agentId);
            return new ToolResult(false, 'MiniMax returned no lyrics.');
        }

        $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, true, null, $userId, $agentId);

        $header = $mode === 'edit' ? 'Edited lyrics' : 'Lyrics';
        if (is_string($songTitle) && $songTitle !== '') {
            $header .= " — \"{$songTitle}\"";
        }
        $content = $header . "\n\n" . $generated;
        if (is_string($styleTags) && $styleTags !== '') {
            $content .= "\n\nStyle tags: {$styleTags}";
        }

        return new ToolResult(true, $content, [
            'song_title' => is_string($songTitle) ? $songTitle : null,
            'style_tags' => is_string($styleTags) ? $styleTags : null,
        ]);
    }
}
