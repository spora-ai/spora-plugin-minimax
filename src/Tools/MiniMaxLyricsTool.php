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
 * Generates song lyrics via MiniMax's lyrics_generation API. Two modes:
 * - `write_full_song`: writes a full song from a topic / mood prompt.
 * - `edit`: rewrites an existing lyrics string according to a prompt.
 */
#[Tool(
    name: 'lyrics',
    description: 'Generate or edit song lyrics via MiniMax. Modes: write_full_song (from a topic) or edit (rewrite existing lyrics).',
    displayName: 'MiniMax Lyrics',
    category: 'generation',
)]
#[ToolOperation(name: 'generate', description: 'Generate or edit song lyrics', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'plugin.minimax.lyrics.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.lyrics.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'Override the MiniMax base URL (default: https://api.minimax.io).',
    default: 'https://api.minimax.io',
)]
#[ToolParameter(
    name: 'mode',
    type: 'string',
    description: 'Generation mode: `write_full_song` writes a new song; `edit` rewrites existing lyrics.',
    required: true,
    enum: ['write_full_song', 'edit'],
)]
#[ToolParameter(
    name: 'prompt',
    type: 'string',
    description: 'Topic or style description (max 2000 characters).',
    required: false,
    maximum: 2000,
)]
#[ToolParameter(
    name: 'lyrics',
    type: 'string',
    description: 'Existing lyrics to edit (required in `edit` mode, max 3500 characters).',
    required: false,
    maximum: 3500,
)]
final class MiniMaxLyricsTool extends AbstractTool
{
    private const PROVIDER = 'lyrics';

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
        $mode = (string) ($arguments['mode'] ?? 'write_full_song');
        return "Generate lyrics (mode: {$mode})";
    }

    public function generate(array $arguments, int $agentId, ?int $userId, ?int $taskId): ToolResult
    {
        $mode = trim((string) ($arguments['mode'] ?? ''));
        $prompt = trim((string) ($arguments['prompt'] ?? ''));
        $lyrics = trim((string) ($arguments['lyrics'] ?? ''));

        if (!in_array($mode, ['write_full_song', 'edit'], true)) {
            return new ToolResult(false, 'mode must be "write_full_song" or "edit".');
        }
        if (mb_strlen($prompt) > 2000) {
            return new ToolResult(false, 'Prompt exceeds the 2000-character MiniMax limit.');
        }
        if ($mode === 'edit' && $lyrics === '') {
            return new ToolResult(false, '`lyrics` is required when mode is "edit".');
        }
        if ($lyrics !== '' && mb_strlen($lyrics) > 3500) {
            return new ToolResult(false, 'Lyrics exceed the 3500-character MiniMax limit.');
        }
        if ($mode === 'write_full_song' && $prompt === '' && $lyrics === '') {
            return new ToolResult(false, 'Provide a `prompt` describing the song (or pre-existing `lyrics`).');
        }

        $settings = $this->configService->getEffectiveSettings(static::class, $agentId, $userId);
        $apiKey = MiniMaxSettings::apiKey(self::PROVIDER, $settings);
        if ($apiKey === '') {
            return new ToolResult(false, 'MiniMax API key is not configured for this agent. Edit the MiniMax Lyrics settings.');
        }

        $client = new MiniMaxHttpClient(
            $this->httpClient,
            $apiKey,
            MiniMaxSettings::baseUrl(self::PROVIDER, $settings),
            timeoutSeconds: 30,
            logger: $this->logger,
        );

        $body = ['mode' => $mode];
        if ($prompt !== '') {
            $body['prompt'] = $prompt;
        }
        if ($lyrics !== '') {
            $body['lyrics'] = $lyrics;
        }

        $qualifiedName = 'minimax:' . 'lyrics';

        try {
            $response = $client->postJson('/v1/lyrics_generation', $body);

            $generated = $response['lyrics'] ?? null;
            $songTitle = $response['song_title'] ?? null;
            $styleTags = $response['style_tags'] ?? null;

            if (!is_string($generated) || $generated === '') {
                $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, false, 'No lyrics in response', $userId, $agentId);
                return new ToolResult(false, 'MiniMax returned no lyrics.');
            }

            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, $response, true, null, $userId, $agentId);

            $header = "Lyrics (mode: {$mode})";
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
        } catch (MiniMaxApiException $e) {
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, $e->getMessage());
        } catch (Throwable $e) {
            $this->logger?->error('MiniMaxLyricsTool: unexpected exception', ['exception' => $e]);
            $this->logWriter->record(self::PROVIDER, $qualifiedName, $arguments, ['error' => $e->getMessage()], false, $e->getMessage(), $userId, $agentId);
            return new ToolResult(false, 'Lyrics generation failed: ' . $e->getMessage());
        }
    }
}
