<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use InvalidArgumentException;
use Spora\Plugins\Concerns\StoresBinaryAssets;
use Spora\Plugins\MiniMax\Support\MiniMaxHttpClient;
use Spora\Plugins\MiniMax\Support\MiniMaxSettings;
use Spora\Plugins\MiniMax\Support\MiniMaxTool;
use Spora\Plugins\MiniMax\Support\MiniMaxToolContext;
use Spora\Services\AssetStore;
use Spora\Services\LocalAssetStore;
use Spora\Services\MediaArchive\MediaIngestRequest;
use Spora\Tools\Attributes\Tool;
use Spora\Tools\Attributes\ToolOperation;
use Spora\Tools\Attributes\ToolParameter;
use Spora\Tools\Attributes\ToolSetting;
use Spora\Tools\MediaEmbed;
use Spora\Tools\ValueObjects\ToolResult;
use Throwable;

/**
 * Synthesizes speech from text via MiniMax's t2a_v2 (text-to-audio) API
 * and exposes the upstream voice library so the Agent can pick a
 * language-matched voice id per call. The tool is a multi-operation
 * discriminator:
 *
 *   - `synthesize` (default) — text → MP3; returns the upstream audio URL
 *     (24h expiry) if a CDN URL is available, otherwise embeds the audio
 *     bytes inline.
 *   - `voices`              — fetch the MiniMax voice library
 *     (`POST /v1/get_voice`, body `{"voice_type": "<bucket>"}`). Returns
 *     every voice in the chosen bucket; `language` / `gender` / `voice_id`
 *     are applied as client-side filters over `voice_name` + flattened
 *     `description[]` because MiniMax's upstream API does not expose
 *     server-side filters for those fields.
 */
#[Tool(
    name: 'speech',
    description: 'Synthesize speech from text via MiniMax t2a_v2, or list the MiniMax voice library via POST /v1/get_voice so the LLM can pick a voice_id before synthesizing. Two operations: synthesize (default), voices.',
    displayName: 'MiniMax Speech',
    category: 'generation',
    icon: 'play',
)]
#[ToolOperation(name: 'synthesize', description: 'Synthesize speech from text', enabledByDefault: true, requiresApprovalByDefault: false)]
#[ToolOperation(name: 'voices', description: 'List the MiniMax voice library (POST /v1/get_voice). Returns system / voice-cloning / voice-generation voices with `voice_name` + `description[]`. Use before `synthesize` to pick a language-matched voice_id.', enabledByDefault: true, requiresApprovalByDefault: false)]
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
    description: 'TTS model id (default: speech-2.8-hd).',
    default: 'speech-2.8-hd',
)]
#[ToolSetting(
    key: 'voice_id',
    label: 'Default voice',
    type: 'text',
    description: 'Default voice id from the MiniMax voice library (overridden by the `voice_id` parameter).',
    default: 'English_PassionateWarrior',
)]
#[ToolSetting(
    key: 'http_timeout_seconds',
    label: 'HTTP timeout (s)',
    type: 'number',
    description: 'Per-request timeout for the MiniMax API. Default 60 seconds.',
    default: '60',
)]
#[ToolParameter(
    name: 'text',
    type: 'string',
    description: 'The text to synthesize (max 10000 characters). Required only when `action == "synthesize"` — `voices` skips it.',
    required: ['synthesize'],
    maximum: 10000,
)]
#[ToolParameter(
    name: 'voice_id',
    type: 'string',
    description: 'For `synthesize`: override the default voice id for this call. For `voices`: return only voices whose `voice_id` matches this value (exact match).',
    required: false,
)]
#[ToolParameter(
    name: 'speed',
    type: 'number',
    description: 'Speech speed multiplier (0.5 - 2.0). Optional — defaults to 1.0 when omitted (or when `action == "voices"`). Use 0.85–0.95 for deliberate narration; 1.1–1.3 for energetic / promotional reads.',
    required: false,
    minimum: 0.5,
    maximum: 2.0,
    default: 1.0,
)]
#[ToolParameter(
    name: 'filename',
    type: 'string',
    description: 'Optional human-readable filename without an extension (e.g. "intro-greeting"). The correct file extension is appended automatically. When omitted, a speaking name is generated from the text. Ignored by `voices`.',
    required: false,
    maximum: 120,
)]
#[ToolParameter(
    name: 'voice_type',
    type: 'string',
    description: 'For `voices` only: which voice bucket to query upstream (MiniMax accepts only this single field on `POST /v1/get_voice`). `system` returns MiniMax\'s built-in voice library (default). `all` returns system + voice-cloning + voice-generation in one response. Ignored by `synthesize`.',
    required: false,
    enum: ['system', 'voice_cloning', 'voice_generation', 'all'],
    default: 'system',
)]
#[ToolParameter(
    name: 'language',
    type: 'string',
    description: 'For `voices` only: client-side substring filter applied to each voice\'s `voice_name` and flattened `description` (case-insensitive). MiniMax\'s upstream API does not filter by language — the caller must scan the returned list. Common values: "English", "Chinese", "Japanese", "Korean", "Spanish", "French", "German", "Italian", "Portuguese". Ignored by `synthesize`.',
    required: false,
)]
#[ToolParameter(
    name: 'gender',
    type: 'string',
    description: 'For `voices` only: client-side substring filter applied to each voice\'s flattened `description` (case-insensitive). MiniMax\'s upstream API does not tag gender explicitly — it lives inside the free-text description string. Common values: "male", "female". Ignored by `synthesize`.',
    required: false,
    enum: ['male', 'female'],
)]
#[ToolParameter(
    name: 'limit',
    type: 'number',
    description: 'For `voices` only: client-side cap on how many voice bullets the response contains (default 50, hard-capped at 500). Ignored by `synthesize`.',
    required: false,
    minimum: 1,
    maximum: 500,
    default: 50,
)]
final class MiniMaxSpeechTool extends MiniMaxTool
{
    use StoresBinaryAssets;

    protected const PROVIDER        = 'speech';
    protected const DEFAULT_MODEL   = 'speech-2.8-hd';
    protected const DEFAULT_VOICE   = 'English_PassionateWarrior';
    protected const QUALIFIED_NAME  = 'minimax:speech';
    protected const TIMEOUT_SECONDS        = 60;
    protected const TIMEOUT_SECONDS_VOICES = 30;
    protected const TOOL_LABEL             = 'Speech synthesis';
    protected const TOOL_LABEL_VOICES      = 'Voice library fetch';
    protected const DEFAULT_VOICE_LIMIT    = 50;
    protected const MAX_VOICE_LIMIT        = 500;
    protected const AUDIO_MIME             = 'audio/mpeg';

    /**
     * Optional direct injection of {@see LocalAssetStore}. When wired by
     * {@see \Spora\Plugins\MiniMax\MiniMaxPlugin::register()} via
     * `\DI\get(LocalAssetStore::class)`, the speech tool always stores the
     * decoded MP3 bytes on disk and emits a `/api/v1/assets/<token>.mp3`
     * URL — bypassing the global `asset_store.mode`. Without it the tool
     * falls back to the configured {@see AssetStore} (preserves test
     * behaviour and the historical AssetStore path).
     */
    private ?LocalAssetStore $localAssetStore = null;

    /**
     * Wired by PHP-DI from {@see \Spora\Plugins\MiniMax\MiniMaxPlugin::register()}.
     */
    public function setLocalAssetStore(LocalAssetStore $localAssetStore): void
    {
        $this->localAssetStore = $localAssetStore;
    }

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

    /**
     * Multi-operation dispatcher. Mirrors MiniMaxMusicTool::execute().
     *
     * Backward compat: pre-multi-op callers never passed `action` and
     * landed on the synthesizer. The default match arm (`synthesize`)
     * keeps that path alive for existing agent definitions, and any
     * unrecognised `action` value also lands on synthesize rather than
     * failing — the runtime schema validator (per the docs skill page)
     * enforces the enum at a higher layer.
     *
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments, int $agentId, ?int $userId = null, ?int $taskId = null): ToolResult
    {
        $operation = (string) ($arguments['action'] ?? 'synthesize');
        return match ($operation) {
            'voices' => $this->listVoices($arguments, $agentId, $userId),
            default  => $this->synthesize($arguments, $agentId, $userId),
        };
    }

    /**
     * Per-operation action description. The orchestrator renders this
     * in the chat bubble when the Agent calls the tool with explicit
     * approval — keep the descriptions short and action-shaped.
     *
     * @param array<string, mixed> $arguments
     */
    public function describeAction(array $arguments): string
    {
        $operation = (string) ($arguments['action'] ?? 'synthesize');
        $text = mb_substr(trim((string) ($arguments['text'] ?? '')), 0, 80);
        return match ($operation) {
            'voices' => 'Fetch the MiniMax voice library',
            default  => "Synthesize speech for: '{$text}'",
        };
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

        $configuredVoice = is_string($settings['voice_id'] ?? null)
            ? trim((string) $settings['voice_id'])
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
     *
     * When MiniMax returns a CDN URL the audio is short-lived anyway; we
     * pass it through and the MediaArchive ingest below swaps it for a
     * persistent `/api/v1/assets/<token>.mp3` reference (when the Media
     * Archive plugin is enabled). When MiniMax returns a hex blob the
     * speech tool routes through {@see LocalAssetStore} directly when
     * injected, sidestepping the global `asset_store.mode`. The default
     * `auto` threshold is 1 MiB — most MiniMax speech clips are
     * 50–500 KB, so on a default install the bytes would be inlined as a
     * `data:audio/mpeg;base64,…` URI. Long base64 strings bloat the chat
     * bubble, get truncated by downstream sanitizers to a `[data-omitted]`
     * placeholder, and the resulting `<audio src=…>` fails to play.
     *
     * Falls back to the configured {@see AssetStore} when no
     * {@see LocalAssetStore} is injected (test environments, custom
     * factory wiring). The fallback path is the historical behaviour and
     * never runs in production because the plugin's `register()` always
     * wires `setLocalAssetStore()`.
     */
    private function resolveSpeechPlayback(?string $audioUrl, ?string $hexAudio): ?array
    {
        if (is_string($audioUrl) && $audioUrl !== '') {
            return [$audioUrl, null];
        }
        if (is_string($hexAudio) && $hexAudio !== '' && strlen($hexAudio) % 2 === 0) {
            return $this->embedSpeechHex($hexAudio);
        }
        return null;
    }

    /**
     * Routes a decoded hex MP3 blob to {@see LocalAssetStore} when wired
     * (the production path), falling back to the configured
     * {@see AssetStore} otherwise. Extracted from
     * {@see resolveSpeechPlayback()} so the dispatch method stays inside
     * the SonarQube `php:S1142` (≤3 returns) threshold.
     *
     * @return array{0: string, 1: string}
     */
    private function embedSpeechHex(string $hex): array
    {
        if ($this->localAssetStore !== null) {
            $bytes = hex2bin($hex);
            if ($bytes === false || $bytes === '') {
                throw new InvalidArgumentException('Hex payload decoded to empty bytes.');
            }
            $ref = $this->localAssetStore->store($bytes, mime: self::AUDIO_MIME, filename: 'speech.mp3');
            return [$ref->url, $ref->mode];
        }
        return $this->embedHex($hex, self::AUDIO_MIME, 'speech.mp3');
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
            // Pass `url` and `hex` as named args so MiniMax returning one
            // payload shape doesn't accidentally populate both. Symmetric
            // with MiniMaxMusicTool::ingestIntoMediaArchive() — see #28
            // for the speech-specific regression that prompted the move:
            // the prior `if ($audioUrl !== '') { url: … } else { hex: … }`
            // guard let `$audioUrl === null` slip through to the URL
            // branch (`null !== ''` is true), leaving `hex` unset and
            // tripping MediaIngestRequest's "exactly one non-empty source"
            // invariant.
            $request = new MediaIngestRequest(
                ...$base,
                url: $audioUrl,
                hex: $audioUrl === null ? $hexAudio : null,
            );
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

    /**
     * Run the `synthesize` operation through the standard
     * validate → prepare → run pipeline. The single-op `synthesize`
     * path is the historical default; preserved verbatim so existing
     * calls (and the unit tests) keep working without an `action`
     * discriminator.
     *
     * @param array<string, mixed> $arguments
     */
    public function synthesize(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            self::TIMEOUT_SECONDS,
            self::TOOL_LABEL,
            fn(MiniMaxToolContext $c) => $this->doWork($c, $arguments),
            fn(array $a) => $this->validateArguments($a),
        );
    }

    /**
     * Run the `voices` operation: hit MiniMax's voice management API
     * and return the up-to-date list of voice ids (plus lightweight
     * metadata) so the LLM can pick a language-matched voice before
     * issuing the next `synthesize` call.
     *
     * @param array<string, mixed> $arguments
     */
    public function listVoices(array $arguments, int $agentId, ?int $userId): ToolResult
    {
        return $this->runWithValidation(
            $arguments,
            $agentId,
            $userId,
            self::TIMEOUT_SECONDS_VOICES,
            self::TOOL_LABEL_VOICES,
            fn(MiniMaxToolContext $c) => $this->doFetchVoices($c, $arguments),
            fn(array $a) => $this->validateVoicesArguments($a),
        );
    }

    /**
     * Per-operation validator for `voices`. Tightens the input only
     * for fields the LLM explicitly set; empty / missing values fall
     * through to the documented defaults (`voice_type: "system"`,
     * `limit: 50`, no language / gender filter).
     *
     * The runtime schema validator on `gender` / `voice_type` runs
     * earlier in normal operation; the checks below are
     * defence-in-depth.
     *
     * @param array<string, mixed> $arguments
     */
    protected function validateVoicesArguments(array $arguments): ?ToolResult
    {
        $errors = [];

        $voiceType = trim((string) ($arguments['voice_type'] ?? ''));
        if ($voiceType !== '' && !in_array($voiceType, ['system', 'voice_cloning', 'voice_generation', 'all'], true)) {
            $errors[] = 'voice_type must be one of: system, voice_cloning, voice_generation, all.';
        }

        $gender = trim((string) ($arguments['gender'] ?? ''));
        if ($gender !== '' && !in_array($gender, ['male', 'female'], true)) {
            $errors[] = 'gender must be "male" or "female".';
        }

        $limit = $arguments['limit'] ?? null;
        if ($limit !== null) {
            if (!is_numeric($limit)) {
                $errors[] = 'limit must be a number.';
            } else {
                $intLimit = (int) $limit;
                if ($intLimit < 1 || $intLimit > self::MAX_VOICE_LIMIT) {
                    $errors[] = sprintf(
                        'limit must be between 1 and %d (clamped at the hard cap).',
                        self::MAX_VOICE_LIMIT,
                    );
                }
            }
        }

        return $errors === [] ? null : new ToolResult(false, implode(' ', $errors));
    }

    /**
     * Voice library fetch worker.
     *
     * Upstream contract: `POST /v1/get_voice` (see
     * https://platform.minimax.io/docs/api-reference/voice-management-get)
     * takes exactly one field in the body — `voice_type` — and returns
     * up to three voice buckets: `system_voice`, `voice_cloning`, and
     * `voice_generation`. Each entry carries `voice_id`, `voice_name`
     * (display name; not the API call id), and a free-text
     * `description[]` array that MiniMax uses to convey language,
     * gender, character, etc.
     *
     * Because MiniMax does not expose server-side filters, the LLM's
     * `voice_id` / `language` / `gender` are applied client-side:
     *   - `voice_id`: exact match against `voice_id`.
     *   - `language`: case-insensitive substring match against
     *     `voice_name` + flattened `description`.
     *   - `gender`:   case-insensitive substring match against
     *     flattened `description`.
     * `limit` is a client-side cap on the rendered bullet count.
     *
     * The result is a Markdown bullet list. Each bullet quotes the
     * `voice_id` in backticks and, when present, appends the
     * `voice_name` + description so the LLM can pick a language-matched
     * voice from the chat transcript.
     *
     * @param array<string, mixed> $arguments
     */
    protected function doFetchVoices(MiniMaxToolContext $ctx, array $arguments): ToolResult
    {
        /** @var MiniMaxHttpClient $client */
        $client = $ctx->client;

        $voiceType = $this->resolveVoiceType($arguments);
        $timeout = $this->resolveTimeout('http_timeout_seconds', $ctx->settings, self::TIMEOUT_SECONDS_VOICES);
        $response  = $client->postJson('/v1/get_voice', ['voice_type' => $voiceType], timeoutSeconds: $timeout);

        $this->support->logSuccess($ctx, $response);

        $allVoices = $this->extractVoices($response, $voiceType);
        $filtered  = $this->applyClientFilters($allVoices, $arguments);
        $limit     = $this->resolveLimit($arguments);
        $capped = array_slice($filtered, 0, $limit);

        if ($capped === []) {
            $emptyPayload = [
                'count'        => 0,
                'voices'       => [],
                'voice_type'   => $voiceType,
                'total'        => count($allVoices),
                'after_filter' => count($filtered),
            ];
            return new ToolResult(true, $this->renderEmptyVoices($arguments, $allVoices), $emptyPayload);
        }

        $content = $this->renderVoicesList($capped, $arguments);

        return new ToolResult(true, $content, [
            'count'   => count($capped),
            'voices'  => array_map(static fn(array $v): array => [
                'voice_id'     => (string) ($v['voice_id'] ?? ''),
                'voice_name'   => $v['voice_name'] ?? null,
                'description'  => $v['description'] ?? null,
                '_source'      => (string) ($v['_source'] ?? ''),
            ], $capped),
            'voice_type'   => $voiceType,
            'total'        => count($allVoices),
            'after_filter' => count($filtered),
        ]);
    }

    /**
     * Pull the voice list out of the MiniMax response, narrowed to the
     * buckets the caller's `voice_type` requested. Each entry is
     * tagged with `_source` (which bucket it came from:
     * `system_voice`, `voice_cloning`, `voice_generation`) so the LLM
     * can disambiguate when `voice_type: "all"` returns duplicates
     * across buckets.
     *
     * Bucket resolution:
     *   - `voice_type == "all"`      → pull all three buckets
     *   - `voice_type == "system"`   → pull only `system_voice`
     *   - `voice_type == "voice_cloning"`    → pull only `voice_cloning`
     *   - `voice_type == "voice_generation"` → pull only `voice_generation`
     *
     * The previous behaviour (always pull all three) silently leaked
     * voices from non-requested buckets into the response, which made
     * `voices(voice_type: "voice_cloning")` return system voices too —
     * a caller who filtered by bucket to check their cloned voices
     * would see the full system library instead.
     *
     * @return list<array<string, mixed>>
     */
    private function extractVoices(array $response, string $voiceType = 'all'): array
    {
        $sources = match ($voiceType) {
            'system'           => ['system_voice'],
            'voice_cloning'    => ['voice_cloning'],
            'voice_generation' => ['voice_generation'],
            default            => ['system_voice', 'voice_cloning', 'voice_generation'], // 'all'
        };

        $out = [];
        foreach ($sources as $source) {
            $bucket = $response[$source] ?? null;
            if (is_array($bucket) && array_is_list($bucket)) {
                foreach ($bucket as $entry) {
                    if (is_array($entry)) {
                        $entry['_source'] = $source;
                        $out[] = $entry;
                    }
                }
                continue;
            }
            if (is_array($bucket)) {
                $entry = $bucket;
                $entry['_source'] = $source;
                $out[] = $entry;
            }
        }
        if ($out !== []) {
            return $out;
        }
        // Fallback: older MiniMax snapshots occasionally returned
        // `voice_list` or `voices` at the top level. Keep the parser
        // tolerant for a release — if MiniMax publishes an unannounced
        // shape we still render something usable. Only consulted when
        // the requested bucket(s) returned no voices AND the response
        // looks like an older shape (no `system_voice` key at all).
        $hasNewShape = array_any($sources, static fn(string $k): bool => array_key_exists($k, $response));
        if ($hasNewShape) {
            return [];
        }
        foreach (['voice_list', 'voices'] as $key) {
            $fallback = $response[$key] ?? null;
            if (is_array($fallback) && array_is_list($fallback)) {
                return $fallback;
            }
        }
        return [];
    }

    /**
     * Apply client-side filters over an already-flattened voice list.
     * Each filter is independent — passing only `language` skips the
     * `voice_id` exact-match, etc.
     *
     * @param  list<array<string, mixed>> $voices
     * @param  array<string, mixed>       $arguments
     * @return list<array<string, mixed>>
     */
    private function applyClientFilters(array $voices, array $arguments): array
    {
        $voiceIdFilter = mb_strtolower(trim((string) ($arguments['voice_id'] ?? '')));
        $languageNeedle = mb_strtolower(trim((string) ($arguments['language'] ?? '')));
        $genderNeedle   = mb_strtolower(trim((string) ($arguments['gender'] ?? '')));

        if ($voiceIdFilter === '' && $languageNeedle === '' && $genderNeedle === '') {
            return $voices;
        }

        $out = [];
        foreach ($voices as $v) {
            $voiceId  = (string) ($v['voice_id'] ?? '');
            $haystack = $this->flattenVoiceText($v);

            if ($voiceIdFilter !== '' && mb_strtolower($voiceId) !== $voiceIdFilter) {
                continue;
            }
            if ($languageNeedle !== '' && mb_strpos($haystack, $languageNeedle) === false) {
                continue;
            }
            if ($genderNeedle !== '' && mb_strpos($haystack, $genderNeedle) === false) {
                continue;
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Flatten a voice entry into a single lower-case string spanning
     * `voice_name` and every `description[]` element. Used as the
     * haystack for the `language` / `gender` substring filters and the
     * "voice matched" reason in {@see renderEmptyVoices()}.
     *
     * @param array<string, mixed> $v
     */
    private function flattenVoiceText(array $v): string
    {
        $bits = [];
        if (isset($v['voice_name']) && is_string($v['voice_name'])) {
            $bits[] = $v['voice_name'];
        }
        if (isset($v['description']) && is_array($v['description'])) {
            foreach ($v['description'] as $line) {
                if (is_string($line)) {
                    $bits[] = $line;
                }
            }
        }
        return mb_strtolower(implode(' ', $bits));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveVoiceType(array $arguments): string
    {
        $raw = trim((string) ($arguments['voice_type'] ?? ''));
        if ($raw === '') {
            return 'system';
        }
        $allowed = ['system', 'voice_cloning', 'voice_generation', 'all'];
        if (!in_array($raw, $allowed, true)) {
            // Defence-in-depth; the runtime schema validator catches this
            // earlier in normal operation.
            return 'system';
        }
        return $raw;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function resolveLimit(array $arguments): int
    {
        $raw = $arguments['limit'] ?? null;
        if ($raw === null || !is_numeric($raw)) {
            return self::DEFAULT_VOICE_LIMIT;
        }
        return max(1, min(self::MAX_VOICE_LIMIT, (int) $raw));
    }

    /**
     * Render the heading + Markdown bullet list the LLM will see in the
     * chat transcript. Each bullet quotes the `voice_id` in backticks
     * and appends `voice_name` + the first description line so the
     * language / gender cues land in the visible transcript.
     *
     * @param  list<array<string, mixed>> $voices
     * @param  array<string, mixed>       $arguments
     */
    private function renderVoicesList(array $voices, array $arguments): string
    {
        $count   = count($voices);
        $filters = $this->summariseFilters($arguments);
        $heading = $filters === ''
            ? "Available MiniMax voices ({$count}):"
            : "Available MiniMax voices ({$count} matching {$filters}):";

        $lines = [];
        foreach ($voices as $v) {
            $lines[] = '- ' . $this->formatVoiceLine($v);
        }

        return $heading . "\n\n"
            . implode("\n", $lines) . "\n\n"
            . "Pick one whose language matches `text`, then call `minimax_speech(text: \"<text>\", voice_id: \"<voice_id>\")` "
            . "(omit `action` — `synthesize` is the default).";
    }

    /**
     * Build the "no voices to render" message. Distinguishes three
     * cases that callers were conflating:
     *
     *   1. The upstream bucket is empty on this account (no cloned
     *      voices yet, voice_generation bucket has nothing, etc.).
     *      Different fix path: switch buckets or call without filters.
     *   2. The bucket has voices but the caller's filter excluded all
     *      of them. Different fix path: broaden or drop the filter.
     *   3. The bucket has voices, no filter was supplied, but the
     *      `limit` cap trimmed everything to zero. Won't happen with
     *      the current `limit >= 1` validator but defensive.
     *
     * The leading line of each message is distinct so the LLM (and a
     * human reading the chat transcript) can tell which case they
     * hit without parsing the body text.
     *
     * @param array<string, mixed> $arguments
     * @param list<array<string, mixed>> $allVoices
     */
    private function renderEmptyVoices(array $arguments, array $allVoices): string
    {
        $voiceType = $this->resolveVoiceType($arguments);
        $filters   = $this->summariseFilters($arguments);

        // Case 1: bucket empty on this account. The voice_type
        // matters here because voice_cloning and voice_generation are
        // user-populated buckets — empty is the default state until
        // the operator has cloned a voice or generated one. system
        // being empty is much rarer (and would indicate a much
        // stranger MiniMax account state).
        if (count($allVoices) === 0) {
            $bucketNote = $voiceType === 'voice_cloning'
                ? 'voice_cloning is a user-populated bucket — it stays empty until you have cloned a voice and used it in at least one synthesize call. Switch `voice_type` to `system` for MiniMax\'s built-in library.'
                : ($voiceType === 'voice_generation'
                    ? 'voice_generation is a user-populated bucket — it stays empty until you have generated a voice via MiniMax\'s text-to-voice API. Switch `voice_type` to `system` for MiniMax\'s built-in library.'
                    : ($voiceType === 'all'
                        ? 'No voices on this MiniMax account at all (system + voice_cloning + voice_generation all empty). Confirm the `api_key` setting points at an account with voice access.'
                        : 'MiniMax returned no `system_voice` entries for this account. Confirm the `api_key` setting points at a paid MiniMax plan that includes system voices.'));

            return "No voices available.\n\n"
                . "voice_type=\"{$voiceType}\" returned an empty bucket.\n\n"
                . $bucketNote;
        }

        // Case 2: bucket had voices, filter excluded all of them.
        $filterNote = $filters === ''
            ? 'No filter was supplied, so the bucket content is unexpected here — check the `voice_type` value.'
            : "Drop the filter (or broaden it) and call `minimax_speech(action: \"voices\")` again. Filters are case-insensitive substring matches against `voice_name` + `description[]` — try a shorter needle (e.g. \"ger\" instead of \"german\").";

        return "No voices matched your filter.\n\n"
            . "MiniMax returned " . count($allVoices) . " voice(s) for voice_type=\"{$voiceType}\"; none matched {$filters}.\n\n"
            . $filterNote;
    }

    /**
     * @param  array<string, mixed> $arguments
     */
    private function summariseFilters(array $arguments): string
    {
        $bits = [];
        $voiceType = trim((string) ($arguments['voice_type'] ?? ''));
        if ($voiceType !== '' && $voiceType !== 'system') {
            $bits[] = 'voice_type="' . $voiceType . '"';
        }
        $language = trim((string) ($arguments['language'] ?? ''));
        if ($language !== '') {
            $bits[] = 'language contains "' . $language . '"';
        }
        $gender = trim((string) ($arguments['gender'] ?? ''));
        if ($gender !== '') {
            $bits[] = 'description contains "' . $gender . '"';
        }
        $voiceId = trim((string) ($arguments['voice_id'] ?? ''));
        if ($voiceId !== '') {
            $bits[] = 'voice_id="' . $voiceId . '"';
        }
        return implode(', ', $bits);
    }

    /**
     * Format a single voice entry as a one-line Markdown bullet. The
     * `voice_id` is always backtick-quoted (so the LLM can copy it
     * verbatim). `voice_name` and the first description line follow in
     * plain text — they're the language / gender cues the LLM needs.
     *
     * @param array<string, mixed> $v
     */
    private function formatVoiceLine(array $v): string
    {
        $id   = (string) ($v['voice_id'] ?? '');
        $bits = [$id !== '' ? "`{$id}`" : '(missing voice_id)'];

        $description = $v['description'] ?? null;
        $firstLine   = is_array($description) ? (string) ($description[0] ?? '') : '';
        $voiceName   = isset($v['voice_name']) && is_string($v['voice_name']) ? $v['voice_name'] : '';

        $meta = [];
        if ($voiceName !== '') {
            $meta[] = $voiceName;
        }
        if ($firstLine !== '') {
            $meta[] = $firstLine;
        }
        if ($meta !== []) {
            $bits[] = '— ' . implode(' — ', $meta);
        }
        return implode(' ', $bits);
    }
}
