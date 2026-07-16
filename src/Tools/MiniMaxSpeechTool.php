<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Services\AssetStore;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Synthesizes speech from text via MiniMax's t2a_v2 (text-to-audio) API.
 * Returns the upstream audio URL (24h expiry) if a CDN URL is available;
 * otherwise embeds the audio bytes inline.
 */
#[Tool(
    name: 'speech',
    description: 'Synthesize speech from text.',
    displayName: 'MiniMax Speech',
    category: 'generation',
)]
#[ToolOperation(name: 'synthesize', description: 'Synthesize speech from text', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolSetting(
    key: 'plugin.minimax.speech.api_key',
    label: 'MiniMax API Key',
    type: 'password',
    description: 'API key for api.minimax.io (shared across all MiniMax tools).',
    required: true,
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.base_url',
    label: 'Base URL',
    type: 'text',
    description: 'MiniMax base URL. Default is the Global endpoint (https://api.minimax.io). For China-region, set to https://api.minimaxi.com.',
    default: 'https://api.minimax.io',
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.model',
    label: 'Model',
    type: 'text',
    description: 'TTS model id (default: speech-2.8-hd).',
    default: 'speech-2.8-hd',
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.voice_id',
    label: 'Default voice',
    type: 'text',
    description: 'Default voice id from the MiniMax voice library (overridden by the `voice_id` parameter).',
    default: 'English_PassionateWarrior',
)]
#[ToolSetting(
    key: 'plugin.minimax.speech.http_timeout_seconds',
    label: 'HTTP timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the MiniMax API. Default 60 seconds.',
    default: '60',
)]
#[ToolParameter(
    name: 'text',
    type: 'string',
    description: 'The text to synthesize (max 10000 characters).',
    required: true,
    maximum: 10000,
)]
#[ToolParameter(
    name: 'voice_id',
    type: 'string',
    description: 'Override the default voice id for this call.',
    required: false,
)]
#[ToolParameter(
    name: 'speed',
    type: 'number',
    description: 'Speech speed multiplier (0.5 - 2.0).',
    required: false,
    minimum: 0.5,
    maximum: 2.0,
    default: 1.0,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional human-readable filename without an extension (e.g. "intro-greeting"). The correct file extension is appended automatically. When omitted, a speaking name is generated from the text.',
    required: false,
    maximum: 120,
)]
final class MiniMaxSpeechTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'speech';
    protected const DEFAULT_MODEL   = 'speech-2.8-hd';
    protected const DEFAULT_VOICE   = 'English_PassionateWarrior';
    protected const QUALIFIED_NAME  = 'minimax:speech';
    protected const TIMEOUT_SECONDS = 60;
    protected const TOOL_LABEL      = 'Speech synthesis';
    protected const AUDIO_MIME      = 'audio/mpeg';

    public function __construct(
        \Spora\Services\ToolConfigService $configService,
        \Symfony\Contracts\HttpClient\HttpClientInterface $httpClient,
        \Spora\Plugins\MiniMax\Support\MiniMaxLogWriter $logWriter,
        AssetStore $assetStore,
        ?\Psr\Log\LoggerInterface $logger = null,
        ?\Spora\Plugins\MiniMax\Support\MiniMaxToolSupport $support = null,
        ?\Spora\Services\MediaArchive\MediaArchiveService $mediaArchive = null,
    ) {
        parent::__construct($configService, $httpClient, $logWriter, $logger, $support);
        $this->setAssetStore($assetStore);
        $this->attachSpeechMediaArchive($mediaArchive);
    }

    private function attachSpeechMediaArchive(?\Spora\Services\MediaArchive\MediaArchiveService $archive): void
    {
        if ($archive !== null) {
            $this->setMediaArchive($archive);
        }
    }

    public function describeAction(array $arguments): string
    {
        $text = mb_substr(trim((string) ($arguments['text'] ?? '')), 0, 80);
        return "Synthesize speech for: '{$text}'";
    }

    /** @param array<string, mixed> $arguments */
    protected function validateArguments(array $arguments): ?ToolResult
    {
        $text  = trim((string) ($arguments['text'] ?? ''));
        $speed = (float) ($arguments['speed'] ?? 1.0);
        $errors = [];
        if ($text === '') {
            $errors[] = 'Text cannot be empty.';
        }
        if (mb_strlen($text) > 10000) {
            $errors[] = 'Text exceeds the 10000-character MiniMax limit.';
        }
        if ($speed < 0.5 || $speed > 2.0) {
            $errors[] = 'Speed must be between 0.5 and 2.0.';
        }
        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * Resolution order: LLM-provided `voice_id` (per call) > operator-configured
     * setting (`voice_id`) > hard-coded default. The
     * LLM-visible #[ToolParameter] lets the model pick a voice per call; the
     * operator setting is the fallback when the model doesn't pass one.
     *
     * @param  array<string, mixed> $settings
     */
    private function resolveVoiceId(array $arguments, array $settings): string
    {
        $voiceOverride = trim((string) ($arguments['voice_id'] ?? ''));
        if ($voiceOverride !== '') {
            return $voiceOverride;
        }

        $configuredVoice = is_string($settings['plugin.minimax.speech.voice_id'] ?? null)
            ? trim((string) $settings['plugin.minimax.speech.voice_id'])
            : '';
        if ($configuredVoice !== '') {
            return $configuredVoice;
        }

        return self::DEFAULT_VOICE;
    }

    /**
     * @param  array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function buildRequestBody(array $settings, string $text, string $voiceId, float $speed): array
    {
        return [
            'model' => MiniMaxSettings::model(self::PROVIDER, $settings, self::DEFAULT_MODEL),
            'text'  => $text,
            'voice_setting' => [
                'voice_id' => $voiceId,
                'speed'    => $speed,
            ],
            'audio_setting' => [
                'sample_rate' => 32000,
                'bitrate'     => 128000,
                'format'      => 'mp3',
            ],
        ];
    }

    /** @param array<string, mixed> $arguments */
    protected function doWork(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        $text   = trim((string) ($arguments['text'] ?? ''));
        $speed  = (float) ($arguments['speed'] ?? 1.0);
        $voiceId = $this->resolveVoiceId($arguments, $ctx->settings);

        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;
        $timeout = $this->resolveTimeout('http_timeout_seconds', $ctx->settings, static::TIMEOUT_SECONDS);
        $response = $client->postJson(
            '/v1/t2a_v2',
            $this->buildRequestBody($ctx->settings, $text, $voiceId, $speed),
            timeoutSeconds: $timeout,
        );

        $hexAudio   = is_string($response['data']['audio'] ?? null) ? $response['data']['audio'] : null;
        $audioUrl   = is_string($response['data']['audio_url'] ?? null) ? $response['data']['audio_url'] : null;
        $lengthMs   = $response['extra_info']['audio_length'] ?? null;
        $sizeBytes  = $response['extra_info']['audio_size'] ?? null;
        $usageChars = $response['extra_info']['usage_characters'] ?? null;

        if (!is_string($hexAudio) && !is_string($audioUrl)) {
            $this->support->logFailure($ctx, $response, 'No audio in response');
            return new ToolResult(false, 'MiniMax returned no audio data.');
        }

        $this->support->logSuccess($ctx, $response);

        $statsLine = $this->formatStatsLine($lengthMs, $sizeBytes, $usageChars);
        $resolved  = $this->resolveSpeechPlayback($audioUrl, $hexAudio);
        if ($resolved === null) {
            return new ToolResult(false, 'MiniMax returned audio in an unsupported format.');
        }
        [$url, $assetMode] = $resolved;

        $archiveAsset = $this->ingestIntoMediaArchive($ctx, $text, $audioUrl, $hexAudio, $sizeBytes, $arguments);
        if ($archiveAsset !== null && $archiveAsset->asset_url !== '' && !str_starts_with($archiveAsset->asset_url, 'data:')) {
            $url = $archiveAsset->asset_url;
        }

        $content = "Synthesized speech{$statsLine}.\n\n"
            . MediaEmbed::audioFromUrl($url) . "\n\n"
            . "Voice: {$voiceId}."
            . "\n\nUse the same audio embed above to show the media player in your reply.";

        return new ToolResult(true, $content, [
            'audio_url'  => $audioUrl,
            'asset_url'  => $url,
            'asset_mode' => $assetMode,
            'voice_id'   => $voiceId,
            'audio_size' => is_int($sizeBytes) ? $sizeBytes : null,
        ]);
    }

    /**
     * @return array{0: string, 1: string|null}|null  [url, mode] or null
     *          when the payload is neither a usable URL nor valid hex.
     */
    private function resolveSpeechPlayback(?string $audioUrl, ?string $hexAudio): ?array
    {
        if (is_string($audioUrl) && $audioUrl !== '') {
            return [$audioUrl, null];
        }
        if (is_string($hexAudio) && $hexAudio !== '' && strlen($hexAudio) % 2 === 0) {
            return $this->embedHex($hexAudio, self::AUDIO_MIME, 'speech.mp3');
        }
        return null;
    }

    /**
     * Hand the MiniMax speech payload to the Media Archive. Returns the
     * persisted row, or null when ingest was skipped or failed.
     *
     * Ingest failures must never break the tool — log and return null so
     * the chat bubble still renders.
     *
     * @param array<string, mixed> $arguments
     */
    private function ingestIntoMediaArchive(
        MiniMaxToolContext $ctx,
        string $text,
        ?string $audioUrl,
        ?string $hexAudio,
        mixed $sizeBytes,
        array $arguments,
    ): ?\Spora\Models\MediaAsset {
        if ($audioUrl === null && ($hexAudio === null || $hexAudio === '')) {
            return null;
        }

        $base = [
            'agentId'    => $ctx->agentId,
            'pluginSlug' => 'minimax',
            'toolName'   => 'speech',
            'mime'       => self::AUDIO_MIME,
            'prompt'     => $text,
            'filename'   => self::resolveFilename(
                isset($arguments['filename']) ? (string) $arguments['filename'] : null,
                $text,
                'minimax-speech',
                'mp3',
            ),
        ];
        if (is_int($sizeBytes)) {
            $base['byteSize'] = $sizeBytes;
        }

        try {
            if ($audioUrl !== '') {
                $request = new MediaIngestRequest(...$base, url: $audioUrl);
            } else {
                $request = new MediaIngestRequest(...$base, hex: $hexAudio);
            }
            return $this->mediaArchive()->ingest($request);
        } catch (Throwable $e) {
            $this->support->logger()?->warning('MediaArchive ingest failed (speech)', [
                'exception' => $e,
            ]);
            return null;
        }
    }

    private function formatStatsLine(mixed $lengthMs, mixed $sizeBytes, mixed $usageChars): string
    {
        $stats = [];
        if (is_int($lengthMs) || (is_string($lengthMs) && ctype_digit($lengthMs))) {
            $stats[] = round(((int) $lengthMs) / 1000, 2) . 's';
        }
        if (is_int($sizeBytes) || (is_string($sizeBytes) && ctype_digit($sizeBytes))) {
            $stats[] = round(((int) $sizeBytes) / 1024, 1) . ' KB';
        }
        if (is_int($usageChars) || (is_string($usageChars) && ctype_digit($usageChars))) {
            $stats[] = $usageChars . ' chars';
        }

        return $stats === [] ? '' : ' (' . implode(', ', $stats) . ')';
    }
}
