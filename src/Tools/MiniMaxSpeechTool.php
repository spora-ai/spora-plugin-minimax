<?php

declare(strict_types=1);

namespace Spora\Plugins\MiniMax\Tools;

use InvalidArgumentException;
use LogicException;
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
 *
 * The `voices` response-parsing / filtering / rendering helpers live in
 * {@see MiniMaxSpeechVoiceLibrary}, kept out of this class so the tool
 * stays under the SonarQube S1448 (≤20 methods) threshold.
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
    protected const AUDIO_MIME             = 'audio/mpeg';

    /**
     * Wired by PHP-DI from {@see MiniMaxPlugin::register()}.
     * Forces the hex payload to disk via `/api/v1/assets/<token>.mp3`
     * so the chat UI doesn't truncate a long base64 to `[data-omitted]`.
     */
    private ?LocalAssetStore $localAssetStore = null;

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
        if ($archiveAsset !== null
            && $archiveAsset->asset_url !== ''
            && !str_starts_with($archiveAsset->asset_url, 'data:')
        ) {
            $url = $archiveAsset->asset_url;
        }

        // Hard invariant: this tool must NEVER emit a `data:` URL.
        // `MiniMaxPlugin::register()` wires `LocalAssetStore` (and the
        // MediaArchive swap produces `/api/v1/assets/...` too), so the
        // only path that lands here with a `data:` URL is a
        // misconfigured deployment or a custom factory that
        // hand-rolled the tool. Fail loudly instead of papering over.
        if (str_starts_with($url, 'data:')) {
            throw new LogicException(
                'MiniMaxSpeechTool produced a data: URI; LocalAssetStore / MediaArchive wiring is missing from the DI container.',
            );
        }

        $renderInstruction = str_starts_with($url, '/api/v1/assets/')
            ? "Echo the `<audio>` element above verbatim — its `src` is `/api/v1/assets/<token>.mp3` served by the Media Archive, not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`."
            : "Echo the `<audio>` element above verbatim — its `src` is a short-lived MiniMax CDN URL (~24 h), not a relative filename (rewriting it breaks playback). Don't strip this sentence; it tells the chat UI to render the player inline. For the raw URL, read `ToolResult.data.asset_url`.";

        $content = "Synthesized speech{$statsLine}.\n\n"
            . MediaEmbed::audioFromUrl($url) . "\n\n"
            . "Voice: {$voiceId}."
            . "\n\n" . $renderInstruction;

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
            return $this->embedSpeechHex($hexAudio);
        }
        return null;
    }

    /**
     * Prefers {@see LocalAssetStore} so the chat UI never sees a
     * `data:` URI (the chat sanitizer truncates long base64).
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
                if ($intLimit < 1 || $intLimit > MiniMaxSpeechVoiceLibrary::MAX_VOICE_LIMIT) {
                    $errors[] = sprintf(
                        'limit must be between 1 and %d (clamped at the hard cap).',
                        MiniMaxSpeechVoiceLibrary::MAX_VOICE_LIMIT,
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
     *   - `voice_id`: exact match against `voice_id`. When non-empty
     *     it short-circuits the other filters (matches the SKILL.md
     *     promise "Other filters are ignored when this is set" —
     *     `voices(voice_id: "X")` is how the Agent checks whether
     *     `X` is available on this MiniMax account).
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

        $voiceType = MiniMaxSpeechVoiceLibrary::resolveVoiceType($arguments);
        $timeout = $this->resolveTimeout('http_timeout_seconds', $ctx->settings, self::TIMEOUT_SECONDS_VOICES);
        $response  = $client->postJson('/v1/get_voice', ['voice_type' => $voiceType], timeoutSeconds: $timeout);

        $this->support->logSuccess($ctx, $response);

        $allVoices = MiniMaxSpeechVoiceLibrary::extractVoices($response, $voiceType);
        $filtered  = MiniMaxSpeechVoiceLibrary::applyClientFilters($allVoices, $arguments);
        $limit     = MiniMaxSpeechVoiceLibrary::resolveLimit($arguments);
        $capped    = array_slice($filtered, 0, $limit);

        if ($capped === []) {
            $emptyPayload = [
                'count'        => 0,
                'voices'       => [],
                'voice_type'   => $voiceType,
                'total'        => count($allVoices),
                'after_filter' => count($filtered),
            ];
            return new ToolResult(
                true,
                MiniMaxSpeechVoiceLibrary::renderEmpty($arguments, $allVoices, $filtered),
                $emptyPayload,
            );
        }

        $content = MiniMaxSpeechVoiceLibrary::renderVoicesList($capped, $arguments);

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
}
