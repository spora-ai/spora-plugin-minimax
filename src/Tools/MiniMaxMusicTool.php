<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use LogicException;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\ValueObjects\ToolResult;

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
final class MiniMaxMusicTool extends MiniMaxTool
{
    protected const PROVIDER              = 'music';
    protected const DEFAULT_MODEL         = 'music-2.6';
    protected const QUALIFIED_NAME        = 'minimax:music';
    protected const TIMEOUT_SECONDS       = 30; // overridden per-op
    protected const TOOL_LABEL            = ''; // unused — dispatch via execute()
    protected const TIMEOUT_SECONDS_COMPOSE = 90;
    protected const TIMEOUT_SECONDS_LYRICS  = 30;

    /**
     * Multi-operation tool: dispatch on the `action` argument. Each per-op
     * method calls {@see MiniMaxTool::runWithValidation()} for the standard
     * validate→prepare→run orchestration.
     */
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

    /** @param array<string, mixed> $arguments */
    public function compose(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            self::TIMEOUT_SECONDS_COMPOSE,
            'Music generation',
            fn(MiniMaxToolContext $c) => $this->doCompose($c, $arguments),
            fn(array $a) => $this->validateComposeArguments($a),
        );
    }

    /** @param array<string, mixed> $arguments */
    public function writeLyrics(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->lyrics('write_full_song', $arguments, $agentId, $userId);
    }

    /** @param array<string, mixed> $arguments */
    public function editLyrics(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->lyrics('edit', $arguments, $agentId, $userId);
    }

    /** @param array<string, mixed> $arguments */
    private function lyrics(string $mode, array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            self::TIMEOUT_SECONDS_LYRICS,
            'Lyrics generation',
            fn(MiniMaxToolContext $c) => $this->doLyrics($c, $arguments, $mode),
            fn(array $a) => $this->validateLyricsArguments($mode, $a),
        );
    }

    /**
     * The base class declares `validateArguments` / `doWork` as abstract for
     * the single-operation tools. MusicTool overrides `execute()` to dispatch
     * across multiple operations, so these base-class hooks are unused —
     * throwing here surfaces a programming error if they ever get called.
     */
    protected function validateArguments(array $arguments): ?ToolResult
    {
        throw new LogicException('MiniMaxMusicTool dispatches per-operation; the base validateArguments() is never reached.');
    }

    protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        throw new LogicException('MiniMaxMusicTool dispatches per-operation; the base doWork() is never reached.');
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function validateComposeArguments(array $arguments): ?ToolResult
    {
        $prompt       = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics       = trim((string) ($arguments['lyrics'] ?? ''));
        $outputFormat = trim((string) ($arguments['output_format'] ?? 'url'));
        $errors = [];
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
     * @param array<string, mixed> $arguments
     */
    private function validateLyricsArguments(string $mode, array $arguments): ?ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));
        $errors = [];
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
     * @param  array<string, mixed> $arguments
     */
    private function doCompose(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $prompt       = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics       = trim((string) ($arguments['lyrics'] ?? ''));
        $outputFormat = trim((string) ($arguments['output_format'] ?? 'url'));

        /** @var \Spora\Plugins\MiniMax\Support\MiniMaxHttpClient $client */
        $client = $ctx->client;
        $response = $client->postJson(
            '/v1/music_generation',
            $this->buildComposeBody($ctx->settings, $prompt, $lyrics, $outputFormat),
        );

        $data     = is_array($response['data'] ?? null) ? $response['data'] : [];
        $hexAudio = isset($data['audio']) && is_string($data['audio']) ? $data['audio'] : null;
        $audioUrl = isset($data['audio_url']) && is_string($data['audio_url']) ? $data['audio_url'] : null;

        if ($hexAudio === null && $audioUrl === null) {
            $this->support->logFailure($ctx, $response, 'No audio in response');
            return new ToolResult(false, 'MiniMax returned no audio data.');
        }

        $this->support->logSuccess($ctx, $response);

        $promptSummary = $prompt !== '' ? "prompt: \"{$prompt}\"" : 'instrumental';
        if ($audioUrl !== null) {
            $content = "Generated music ({$promptSummary}).\n\nCDN URL (valid 24h): {$audioUrl}";
            return new ToolResult(true, $content, ['audio_url' => $audioUrl]);
        }
        $byteCount = (int) (strlen($hexAudio) / 2);
        $content = "Generated music ({$promptSummary}).\n\nAudio payload: {$byteCount} bytes (hex-encoded, inline).";
        return new ToolResult(true, $content, ['audio_bytes' => $byteCount]);
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
     * @param  array<string, mixed> $arguments
     */
    private function doLyrics(MiniMaxToolContext $ctx, array $arguments, string $mode): ToolResult
    {
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));

        /** @var \Spora\Plugins\MiniMax\Support\MiniMaxHttpClient $client */
        $client = $ctx->client;
        $response = $client->postJson('/v1/lyrics_generation', $this->buildLyricsBody($mode, $prompt, $lyrics));

        $generated = $response['lyrics'] ?? null;
        $songTitle = $response['song_title'] ?? null;
        $styleTags = $response['style_tags'] ?? null;

        if (!is_string($generated) || $generated === '') {
            $this->support->logFailure($ctx, $response, 'No lyrics in response');
            return new ToolResult(false, 'MiniMax returned no lyrics.');
        }

        $this->support->logSuccess($ctx, $response);

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
